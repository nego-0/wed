// O ficheiro de exemplo do bar, e o retrato que o carrega.
//
// docs/exemplos/bar-exemplo.json é um casamento inteiro pronto a importar, feito
// para se poder experimentar o bar sem montar nada à mão: 10 convites, 7 mesas,
// 16 bebidas em 4 gavetas, 5 motivos de recusa e 10 regras — uma de cada forma
// que o motor de limites conhece, incluindo a janela horária e as regras de uma
// pessoa e de um convite.
//
// Esta prova defende as duas metades:
//
//   1. **O retrato leva o bar.** Até aqui levava convidados, mesas, definições e
//      orçamento; o bar ficava de fora, e um ficheiro de exemplo só podia dar
//      nomes e QR — o bar chegava vazio, que é o contrário do que se queria.
//   2. **E o ficheiro carrega um bar que funciona.** Não basta as linhas
//      entrarem na base: a prova vai até ao fim — abre o menu no telemóvel pelo
//      QR de uma mesa e faz um pedido. Um ficheiro que importa sem erro e
//      depois não deixa pedir uma bebida não serve para testar nada.
//
// O que ela mais defende é o **stock**. À primeira, a bebida entrava com o
// stock escrito na coluna E lançado no livro-razão a seguir — e vinha a dobrar.
// É exactamente a avaria que a regra de ouro do módulo existe para impedir: a
// coluna e o livro-razão têm de contar a mesma história. Por isso o número
// aqui é fixo (1306) e não «maior do que mil»: um teste frouxo teria deixado
// passar o dobro.
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const FICH = path.join(__dirname, '..', 'docs', 'exemplos', 'bar-exemplo.json');
let f = 0;
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

(async () => {
  const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const casa = await b.newContext({ viewport: { width: 1280, height: 950 } });
  const p = await casa.newPage();
  p.on('pageerror', e => console.log('  JS!', e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);

  // Importa-se como casamento NOVO, e não por cima do de trabalho. É o que um
  // ficheiro de exemplo quer ser — uma festa a mais para experimentar — e é
  // também o que impede esta prova de estragar as outras: «substituir» punha
  // o casamento nº1 com outros convidados, e as provas seguintes iam procurar
  // nomes que já lá não estavam.
  const ficheiro = JSON.parse(fs.readFileSync(FICH, 'utf8'));
  ficheiro.casamentos[0].ficha.nome = 'ZZI ' + ficheiro.casamentos[0].ficha.nome;
  const r = await p.evaluate(async (fich) => {
    return await window.api('dados_importar', { method: 'POST',
      body: JSON.stringify({ ficheiro: fich, modo: 'novo' }) });
  }, ficheiro);
  console.log('   importação:', JSON.stringify(r && r.resumo || r).slice(0, 300));
  ok(!!(r && r.success), 'o ficheiro importa-se sem reclamar');
  const novoId = (((r || {}).resumo || [])[0] || {}).id || 0;
  ok(novoId > 1, 'e nasce um casamento à parte (nº' + novoId + ')');

  // A licença do bar, que o ficheiro NÃO traz nem deve trazer: é um facto
  // comercial daquele casamento e não um dado do casal — um ficheiro que se
  // auto-licenciasse era uma porta aberta. Aqui dá-se à mão, que é o que o
  // admin faria a seguir a importar.
  const lic = await p.evaluate(async (id) => {
    const cat = await window.api('lic_catalogo');
    const mods = (cat.catalogo.modulos || []).filter(
      m => ['convidados', 'mesas', 'bar'].includes(m.chave));
    const esc = mods.map(m => m.escaloes[m.escaloes.length - 1].id);
    const d = await window.api('lic_conceder', { method: 'POST',
      body: JSON.stringify({ casamento: id, escaloes: esc, meses: 12 }) });
    return !!(d && d.success);
  }, novoId);
  ok(lic, 'e o admin dá-lhe a licença do bar — que o ficheiro não traz, de propósito');

  await p.evaluate(async (id) => {
    await fetch('api.php?action=casamento_abrir&id=' + id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, novoId);
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const est = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const rg = await window.api('bar_regras');
    return { aberto: !!(e.estado && e.estado.aberto), cats: (e.categorias || []).length,
             itens: (e.itens || []).length,
             ocultos: (e.itens || []).filter(i => i.estado === 'oculto').length,
             stock: (e.itens || []).reduce((s, i) => s + i.stock, 0),
             reservado: (e.itens || []).reduce((s, i) => s + (i.reservado || 0), 0),
             motivos: (e.motivos || []).length,
             regras: (rg.regras || []).map(x => x.quem + ' → ' + x.frase) };
  });
  ok(est.cats === 4, 'chegam as quatro gavetas (' + est.cats + ')');
  ok(est.itens === 16, 'e as dezasseis bebidas (' + est.itens + ')');
  ok(est.ocultos === 1, 'com a que estava escondida ainda escondida (' + est.ocultos + ')');
  ok(est.reservado === 0, 'nada reservado: um bar importado está por abrir (' + est.reservado + ')');
  ok(est.stock === 1306, 'o stock veio todo, e UMA vez só (' + est.stock + ')');
  ok(est.motivos === 5, 'os cinco motivos de recusa (' + est.motivos + ')');
  ok(est.aberto === true, 'e o bar chega aberto, como o ficheiro diz');
  console.log('   regras:\n     ' + est.regras.join('\n     '));
  ok(est.regras.length === 10, 'as dez regras (' + est.regras.length + ')');
  ok(est.regras.some(x => /Rodrigo Macedo/.test(x)), 'a regra de uma PESSOA achou a pessoa pelo nome');
  ok(est.regras.some(x => /convite inteiro/.test(x)), 'a de um CONVITE achou o convite');
  ok(est.regras.some(x => /caudal/.test(x)), 'e o caudal da casa lá está');
  ok(est.regras.some(x => /até às 21h/.test(x)), 'com a janela horária escrita: '
     + (est.regras.filter(x => /21h/.test(x))[0] || '—'));

  // O livro-razão explica o stock que entrou.
  const mov = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const i = (e.itens || []).filter(x => x.nome === 'Cerveja')[0];
    const d = await window.api('bar_stock_hist&item=' + i.id).catch(() => null);
    return { item: i.nome, stock: i.stock, hist: d && d.movimentos ? d.movimentos.length : -1 };
  });
  console.log('   ' + JSON.stringify(mov));

  // ---- e agora o essencial: dá para pedir? ----
  const mesas = await p.evaluate(async () => {
    const d = await window.api('mesa_list');
    return (d.mesas || []).map(m => ({ nome: m.nome, token: m.bar_token }));
  });
  ok(mesas.length === 7 && mesas.every(m => m.token), 'as sete mesas chegaram com o seu código de QR');
  const tok = mesas.filter(m => m.nome === 'Oliveira')[0].token;

  const tel = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  tel.on('pageerror', e => console.log('  JS tel!', e.message));
  await tel.goto(BASE + '/bebidas.php?m=' + tok, { waitUntil: 'networkidle' });
  await tel.waitForTimeout(900);
  await tel.fill('#b-q', 'Estêv');
  await tel.waitForTimeout(900);
  const achados = await tel.locator('.b-nome').count();
  ok(achados >= 3, 'a procura do nome encontra a família Estêvão (' + achados + ')');
  await tel.locator('.b-nome').filter({ hasText: 'Clara' }).first().click();
  await tel.waitForTimeout(1500);
  const menu = await tel.locator('#b-corpo').innerText();
  ok(/Cerveja/.test(menu) && /Água/.test(menu), 'e o menu abre com as bebidas do ficheiro');
  ok(!/Licor Beirão/.test(menu), 'sem a que a copa deixou escondida');
  console.log('   menu (início): ' + menu.replace(/\n+/g, ' · ').slice(0, 200));

  await tel.locator('.b-bebida:has-text("Água") button:has-text("+")').first().click();
  await tel.waitForTimeout(400);
  await tel.click('#b-pedir');
  await tel.waitForTimeout(1600);
  const rec = await tel.locator('.lic-conf-txt').innerText().catch(() => '');
  ok(/copa está a ver/.test(rec), 'e um pedido chega mesmo à copa: ' + rec.replace(/\n/g, ' ').slice(0, 80));

  // ============ arrumar ============
  // A festa de exemplo sai da casa: arquivar primeiro, que é o passo que o
  // sistema pede antes de apagar, e depois apagar. Deixá-la ficar enchia a
  // lista de casamentos de quem corre as provas — e há uma prova que mede
  // exactamente a altura dessa lista.
  // Pela ordem que o sistema exige, e cada passo por uma razão: a licença
  // primeiro (não se arquiva um casamento com licença em vigor), o arquivo
  // depois (não se apaga o que ainda está nas listas de trabalho), e só então
  // apagar. É a mesma escada que protege um casamento a sério de desaparecer
  // por um clique — e por isso a prova desce-a inteira, em vez de mexer na
  // base de dados por baixo.
  await p.evaluate(async (id) => {
    const post = (u, corpo) => fetch('api.php?action=' + u, {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: corpo === undefined ? '{}' : JSON.stringify(corpo) });
    await post('casamento_fechar');
    await post('lic_revogar', { casamento: id, motivo: 'festa de exemplo, fim da prova' });
    await post('casamento_estado&id=' + id + '&estado=arquivado');
    await post('casamento_apagar&id=' + id);
    await post('casamento_abrir&id=1');
  }, novoId);
  const sobrou = await p.evaluate(async (id) => {
    const d = await window.api('casamento_lista', { silencioso: true });
    return ((d && d.casamentos) || []).filter(c => c.id === id).length;
  }, novoId);
  ok(sobrou === 0, 'e a festa de exemplo sai da casa quando a prova acaba');

  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close();
  process.exit(f ? 1 : 0);
})();
