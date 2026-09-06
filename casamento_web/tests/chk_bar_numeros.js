// Os números da noite, e de quem eles são.
//
// A pergunta que a copa faz de verdade não é «quantas saíram» — é «chega até
// ao fim?». Por isso a previsão de rutura vem primeiro; o que saiu é história,
// e a história decide-se depois.
//
// A previsão só se faz para o que já teve saída. Uma bebida parada não «acaba
// nunca»: não se sabe, e diz-se null em vez de um número bonito que mandava
// alguém à cidade em vão. Esta prova fixa isso — e fixa sobretudo a regra de
// privacidade: o convidado vê só o SEU consumo, nem sequer o do resto da
// família, e não há parâmetro nenhum por onde espreitar o de outro. Aqui
// tenta-se, e tem de falhar.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const casa = await b.newContext({ viewport: { width: 1280, height: 1000 } });
  const p = await casa.newPage();
  p.on('pageerror', e => errs.push('copa: ' + e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push('copa: ' + m.text()); });
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);

  const arrumar = () => p.evaluate(async () => {
    const r = await window.api('bar_regras');
    for (const x of r.regras) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    const e2 = await window.api('bar_estado');
    for (const i of e2.itens.filter(i => /^ZZ /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
  });
  await arrumar();

  const base = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const g = e.categorias[0].id;
    const mk = async (nome, stock) => (await window.api('bar_item_guardar', { method: 'POST',
      body: JSON.stringify({ nome, categoria_id: g, stock, visivel: 1, max_por_pedido: 9 }) })).id;
    const ids = { sai: await mk('ZZ Sai Muito', 12), parada: await mk('ZZ Parada', 40) };
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const n = await window.api('bar_procurar_pessoal&q=Convidad');
    return { ids, quem: n.nomes };
  });
  const A = base.quem[0], B = base.quem[1];
  const token = await p.evaluate(() => window.BAR_MESAS[0].token);

  const abrir = async (quem) => {
    const c = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    c.on('pageerror', e => errs.push('bebidas: ' + e.message));
    await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
    await c.waitForTimeout(500);
    await c.evaluate(async ([t, id]) => {
      await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, convidado_id: id }) });
    }, [token, quem.id]);
    return c;
  };
  const cA = await abrir(A), cB = await abrir(B);
  const pedir = (c, id, q) => c.evaluate(async ([t, id, q]) =>
    await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, itens: [{ item_id: id, quantidade: q }], mesa_id: 1 }) })).json(),
    [token, id, q]);

  // O histórico de uma pessoa sobrevive entre corridas — é dela, e é natural
  // que sobreviva. Mede-se a diferença, e não o total.
  const contar = (c) => c.evaluate(async (t) => {
    const d = await (await fetch('api.php?action=bar_meu_consumo&m=' + t)).json();
    return (d.levou || []).reduce((s, x) => s + x.n, 0);
  }, token);
  const antesA = await contar(cA);

  // A saiu com quatro, B com duas. A «ZZ Parada» não sai a ninguém.
  await pedir(cA, base.ids.sai, 4);
  await pedir(cB, base.ids.sai, 2);
  // Aprovar e entregar as de A, para haver «servidas» e não só «por sair».
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of e.fila.filter(x => x.estado === 'em_analise')) {
      await window.api('bar_decidir', { method: 'POST',
        body: JSON.stringify({ id: x.id, decisao: 'aprovar' }) });
    }
    const e2 = await window.api('bar_estado');
    for (const x of e2.fila.filter(x => x.estado === 'aprovado')) {
      await window.api('bar_entregue', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
  });

  // ============ 1. a previsão de rutura ============
  const num = await p.evaluate(async () => await window.api('bar_numeros'));
  ok(num.success === true, 'os números lêem-se de uma vez só');
  const sai = num.rutura.find(x => x.nome === 'ZZ Sai Muito');
  const parada = num.rutura.find(x => x.nome === 'ZZ Parada');
  ok(sai && sai.acaba_em_min !== null,
     'o que tem saída ganha previsão: ' + (sai && sai.acaba_em_min) + ' min a '
     + (sai && sai.por_hora) + '/hora');
  ok(parada && parada.acaba_em_min === null,
     'e o que está parado NÃO ganha um número inventado — diz-se que não se sabe');
  ok(num.rutura[0].nome === 'ZZ Sai Muito',
     'a que acaba primeiro vem à frente: é o que a copa precisa de ver');

  // ============ 2. o que a festa bebeu ============
  const c1 = num.consumo.find(x => x.nome === 'ZZ Sai Muito');
  ok(c1 && c1.servidas === 6, 'o consumo conta as entregues (6): ' + (c1 && c1.servidas));
  ok(num.estado.bebidas_entregues >= 6, 'e bate com a conta da noite');

  // ============ 3. as recusas dizem o que correu mal ============
  await pedir(cA, base.ids.parada, 1);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const x = e.fila.find(x => x.estado === 'em_analise');
    if (x) await window.api('bar_decidir', { method: 'POST',
      body: JSON.stringify({ id: x.id, decisao: 'recusar', motivo_texto: 'ZZ sem copos' }) });
  });
  const num2 = await p.evaluate(async () => await window.api('bar_numeros'));
  ok(num2.recusas.some(r => r.motivo === 'ZZ sem copos'),
     'as recusas agrupam-se por motivo: ' + JSON.stringify(num2.recusas[0]));

  // ============ 4. o ecrã da copa ============
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  await p.click('#fa-num');
  await p.waitForTimeout(1400);
  const ecra = await p.locator('#b-fila').innerText();
  ok(/Chega até ao fim\?/.test(ecra), 'a copa abre nos números pela pergunta que faz de verdade');
  ok(/ZZ Sai Muito/.test(ecra), 'com a bebida que está a sair');
  ok(/O que a festa bebeu/.test(ecra), 'e a história vem depois da decisão');
  ok(/ZZ sem copos/.test(ecra), 'as recusas aparecem, sem rodeios');

  // O separador não se apaga por baixo de quem o está a ler.
  await p.waitForTimeout(9000);
  ok(/Chega até ao fim\?/.test(await p.locator('#b-fila').innerText()),
     'e a sondagem de 8 segundos refresca-os em vez de os apagar');

  // ============ 5. o convidado vê SÓ o seu ============
  await cA.reload({ waitUntil: 'networkidle' });
  await cA.waitForTimeout(1300);
  const meuA = await cA.locator('.b-conta-minha').innerText();
  ok(/ZZ Sai Muito/.test(meuA), 'o convidado vê o que pediu: ' + meuA);

  const dA = await cA.evaluate(async (t) =>
    await (await fetch('api.php?action=bar_meu_consumo&m=' + t)).json(), token);
  const totalA = dA.levou.reduce((s, x) => s + x.n, 0);
  // Nesta corrida pediu cinco: quatro servidas e uma recusada. A recusada não
  // conta — um pedido que a copa não fez não gasta a quota de ninguém, e
  // contá-lo seria castigar duas vezes.
  ok(totalA - antesA === 4,
     'a conta dele sobe com o que a copa aceitou, e não com as recusas: +'
     + (totalA - antesA));

  // A tentativa: pedir o consumo de OUTRO. Não há por onde.
  const espreitar = await cA.evaluate(async ([t, id]) => {
    const r = await fetch('api.php?action=bar_meu_consumo&convidado=' + id + '&m=' + t);
    return await r.json();
  }, [token, B.id]);
  const totalEspreitado = (espreitar.levou || []).reduce((s, x) => s + x.n, 0);
  ok(totalEspreitado === totalA,
     'passar o id de outro no endereço não muda nada: devolve o SEU (' + totalEspreitado + ')');
  // A comparação só tem valor se os dois números forem mesmo diferentes —
  // senão «não é o de B» passaria por acaso. A e B pedem quantidades
  // diferentes de propósito, e confere-se isso antes de confiar no resto.
  const totalB = await contar(cB);
  ok(totalA !== totalB, 'A e B têm contas diferentes, para a comparação valer ('
     + totalA + ' vs ' + totalB + ')');
  ok(totalEspreitado !== totalB,
     'e o que se devolve nunca é o de B — o ecrã público não tem porta para o alheio');

  // ============ 6. a tira do painel ============
  // Uma tira e não um cartão de estatística: os cartões do painel são todos
  // filtros da lista de convidados, e um que não filtrasse nada era uma
  // promessa falsa. E só aparece com o bar a trabalhar.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1300);
  ok(await p.locator('#tira-bar').isVisible(), 'com o bar aberto, o painel diz que ele existe');
  const tira = await p.locator('#tira-bar').innerText();
  ok(/O bar está aberto/.test(tira) && /servidas/.test(tira),
     'de relance: o estado e o que já saiu — «' + tira.replace(/\n/g, ' ') + '»');

  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await arrumar();
  await p.evaluate(async () => { await window.api('bar_fechar', { method: 'POST', body: '{}' }); });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1300);
  // O display de classe ganha ao atributo [hidden]: sem a regra que o desfaz,
  // a tira ficava colada ao painel meses antes da festa, a dizer zero.
  ok(await p.locator('#tira-bar').isHidden(),
     'e com o bar fechado e nada por fazer desaparece — não fica a dizer zero');

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await arrumar();
  await p.evaluate(async () => { await window.api('bar_fechar', { method: 'POST', body: '{}' }); });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
