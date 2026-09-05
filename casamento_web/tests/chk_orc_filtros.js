// Os cartões do orçamento são o filtro.
//
// Os números do topo diziam «tem 1 140 000 Kz por pagar» e deixavam a pessoa
// a procurar QUAIS, à mão, numa lista de trinta linhas — e o calendário de
// pagamentos ficava sempre inteiro por baixo, a responder a outra pergunta.
//
// Cada cartão é uma fatia das despesas, e carregar nele mostra só essa fatia,
// nas duas listas. Cruzam-se com o filtro de categoria que já lá estava. A
// margem até ao teto saiu dos cartões: é a diferença entre dois números, não
// uma fatia de nada, e seria o único que não filtrava — passou para junto da
// barra, onde a folga já se lia.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1000, height: 1300 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/orcamento.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  // ---------- o cenário: duas gavetas, três despesas, três parcelas ----------
  // Duas das parcelas já venceram (uma de cada gaveta), para o «em atraso» ter
  // o que mostrar e para o cruzamento com a categoria ter sentido.
  const feito = await p.evaluate(async () => {
    const iso = d => new Date(Date.now() + d * 86400000).toISOString().slice(0, 10);
    // Um teto, para a margem ter o que medir.
    await window.api('orc_ajuste', { method: 'POST', body: JSON.stringify({ total: 3000000 }) });
    const cat = a => window.api('orc_categoria_guardar',
      { method: 'POST', body: JSON.stringify(a) });
    const buffet = await cat({ nome: 'ZZ Buffet', cor: '#4C8C1E' });
    const flores = await cat({ nome: 'ZZ Flores', cor: '#B24C7A' });
    const desp = async (descricao, valor, estado, categoria_id) =>
      (await window.api('orc_despesa_guardar',
        { method: 'POST', body: JSON.stringify({ descricao, valor, estado, categoria_id }) })).id;
    const d1 = await desp('ZZ Menu para 120', 900000, 'previsto', buffet.id);
    const d2 = await desp('ZZ Bolo',          180000, 'pago',     buffet.id);
    const d3 = await desp('ZZ Arranjos',      240000, 'previsto', flores.id);
    const parc = (despesa_id, valor, data_prevista) => window.api('orc_pagamento_guardar',
      { method: 'POST', body: JSON.stringify({ despesa_id, valor, data_prevista }) });
    await parc(d1, 300000, iso(-10));    // vencida
    await parc(d1, 600000, iso(30));     // por vencer
    await parc(d3, 240000, iso(-3));     // vencida
    return { buffet: buffet.id, flores: flores.id, d1, d2, d3 };
  });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  const cartoes = () => p.evaluate(() => [...document.querySelectorAll('#o-kpis .kpi')].map(k => ({
    rotulo: k.querySelector('.l').textContent.trim(),
    valor: k.querySelector('.n').textContent.trim(),
    on: k.classList.contains('on'), botao: k.tagName === 'BUTTON' })));
  const listas = () => p.evaluate(() => ({
    despesas: [...document.querySelectorAll('#lista-despesas tbody tr .d-nome')].map(e => e.textContent.trim()),
    parcelas: [...document.querySelectorAll('#lista-pagamentos .pag .desc')].map(e => e.textContent.trim()),
    tiras: [...document.querySelectorAll('.o-filtro')].length,
    estados: [...document.querySelectorAll('.o-filtro-est')].map(e => e.textContent.trim())
  }));
  const carregar = async (rotulo) => {
    await p.evaluate((r) => {
      const k = [...document.querySelectorAll('#o-kpis .kpi')]
        .find(x => x.querySelector('.l').textContent.trim() === r);
      k.click();
    }, rotulo);
    await p.waitForTimeout(500);
  };

  // ============ 1. os cartões são botões, e dizem os quatro números ============
  const cs = await cartoes();
  ok(cs.length === 4 && cs.every(c => c.botao),
     'os quatro cartões são botões: ' + cs.map(c => c.rotulo).join(', '));
  ok(cs.map(c => c.rotulo).join('|') === 'Orçamento|Por pagar|Já pago|Em atraso',
     'e o quarto é «Em atraso» — a margem saiu daqui, que não é fatia de nada');
  ok(cs[0].on, 'à partida está escolhido o «Orçamento», que é o mesmo que ver tudo');
  ok(/540\s*000/.test(cs[3].valor.replace(/ /g, ' ')),
     'o «Em atraso» soma as parcelas vencidas: ' + cs[3].valor);

  // A margem passou para a legenda da barra.
  const legenda = await p.evaluate(() =>
    document.getElementById('o-legenda').textContent.replace(/\s+/g, ' '));
  ok(/Margem até ao teto|Acima do teto/.test(legenda) && !/Folga/.test(legenda),
     'e a margem lê-se junto à barra, uma vez só: ' + legenda.trim().slice(0, 90));

  // ============ 2. «Por pagar» encolhe as duas listas ============
  const tudo = await listas();
  ok(tudo.despesas.length === 3 && tudo.parcelas.length === 3 && tudo.tiras === 0,
     'sem filtro, as listas estão inteiras e não há tira nenhuma a explicar-se');

  await carregar('Por pagar');
  const porPagar = await listas();
  ok(porPagar.despesas.length === 2 && !porPagar.despesas.some(d => /Bolo/.test(d)),
     'escolher «Por pagar» tira o que já foi pago da lista: '
       + porPagar.despesas.join(', '));
  ok(porPagar.parcelas.length === 3,
     'e no calendário ficam as parcelas por liquidar — que aqui são todas ('
       + porPagar.parcelas.length + ')');
  ok(porPagar.tiras === 2 && porPagar.estados.every(e => e === 'Por pagar'),
     'as duas listas dizem, cada uma na sua tira, o que estão a mostrar');

  await carregar('Já pago');
  const pago = await listas();
  ok(pago.despesas.length === 1 && /Bolo/.test(pago.despesas[0]),
     '«Já pago» mostra só o que saiu: ' + pago.despesas.join(', '));
  ok(pago.parcelas.length === 0,
     'e nenhuma parcela, porque nenhuma foi dada por paga');

  // ============ 3. «Em atraso» é sobre datas ============
  await carregar('Em atraso');
  const atraso = await listas();
  ok(atraso.parcelas.length === 2,
     'as duas parcelas vencidas, e só essas (' + atraso.parcelas.length + ')');
  ok(atraso.despesas.length === 2 && !atraso.despesas.some(d => /Bolo/.test(d)),
     'e as despesas a que elas pertencem: ' + atraso.despesas.join(', '));
  await p.screenshot({ path: OUT + '/orc-em-atraso.png' });

  // ============ 4. os dois filtros cruzam-se ============
  await p.evaluate((id) => orcFiltrar(String(id)), feito.flores);
  await p.waitForTimeout(500);
  const cruzado = await listas();
  ok(cruzado.despesas.length === 1 && /Arranjos/.test(cruzado.despesas[0]),
     'juntar a gaveta «Flores» ao «Em atraso» deixa uma despesa: '
       + cruzado.despesas.join(', '));
  ok(cruzado.parcelas.length === 1,
     'e uma parcela — o calendário também obedece à gaveta');
  const tira = await p.evaluate(() =>
    document.querySelector('.o-filtro').textContent.replace(/\s+/g, ' ').trim());
  ok(/ZZ Flores/.test(tira) && /Em atraso/.test(tira),
     'e a tira diz os dois filtros: ' + tira);

  // ============ 5. desfazer ============
  await carregar('Em atraso');           // o mesmo cartão outra vez
  const semEstado = await listas();
  ok(semEstado.despesas.length === 1 && semEstado.estados.length === 0,
     'carregar no mesmo cartão desfaz o estado e deixa só a gaveta');
  ok((await cartoes())[0].on, 'e o «Orçamento» volta a estar escolhido');

  await p.evaluate(() => orcLimparFiltros());
  await p.waitForTimeout(500);
  const limpo = await listas();
  ok(limpo.despesas.length === 3 && limpo.parcelas.length === 3 && limpo.tiras === 0,
     'e «limpar» devolve as listas inteiras');

  // ============ 6. arrumar ============
  await p.evaluate(async (f) => {
    for (const id of [f.d1, f.d2, f.d3])
      await window.api('orc_despesa_apagar&id=' + id, { method: 'POST' });
    for (const id of [f.buffet, f.flores])
      await window.api('orc_categoria_apagar&id=' + id, { method: 'POST' });
  }, feito);
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  ok(!(await listas()).despesas.some(d => /^ZZ /.test(d)),
     'a prova leva consigo as despesas que lançou');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
