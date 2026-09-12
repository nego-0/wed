// O editor pede mesa, e o manual acompanha o cartão em vigor.
//
// Duas coisas que só se veem de fora: um ecrã pequeno tem de AVISAR (e deixar
// continuar, que a decisão é de quem trabalha), e o manual de impressão tem de
// dizer o que o cartão é AGORA — feitio da moldura, tamanho dos ornamentos e a
// composição livre — e não o que ele era quando alguém escreveu a página.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 950 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

  // ============ 1. o aviso de ecrã apertado ============
  for (const pag of ['editor-cartao.php', 'convite-editor.php']) {
    await p.setViewportSize({ width: 1440, height: 950 });
    await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    // "Continuar mesmo assim" vale para a sessão de trabalho inteira, e é isso
    // que se quer: quem já disse que sabe não quer ouvi-lo em cada página. Aqui
    // limpa-se para cada editor ser provado de fresco.
    await p.evaluate(() => sessionStorage.removeItem('editor.espaco.avancar'));
    await p.waitForTimeout(pag === 'convite-editor.php' ? 2600 : 1000);
    const min = await p.evaluate(() => window.EDITOR_MIN);
    ok(min && min.l >= 1000 && min.a >= 600, `${pag}: a medida mínima está declarada (${min && min.l}×${min && min.a})`);
    ok(await p.locator('.esp-aviso.on').count() === 0, 'com mesa que chegue, nem sinal do aviso');

    // Encolher: o aviso aparece sozinho, sem recarregar.
    await p.setViewportSize({ width: 900, height: 640 });
    await p.waitForTimeout(500);
    ok(await p.locator('.esp-aviso.on').count() === 1, 'encolher a janela faz o aviso aparecer');
    const txt = await p.locator('.esp-cartao').innerText();
    ok(/900 × 640/.test(txt), 'que diz a medida que se tem: ' + (txt.match(/Tem [^.]*/) || [''])[0]);
    ok(new RegExp(min.l + ' × ' + min.a).test(txt), 'e a que faz falta');
    ok(/largura e altura/.test(txt), 'e o que é que falta ao certo');
    ok(await p.locator('body.esp-travado').count() === 1, 'enquanto está à frente, o corpo não se mexe');

    // Continuar mesmo assim: é um aviso, não uma porta fechada.
    await p.click('.esp-ok'); await p.waitForTimeout(250);
    ok(await p.locator('.esp-aviso.on').count() === 0, 'quem quiser continua mesmo assim');
    ok(await p.locator('body.esp-travado').count() === 0, 'e o editor volta a responder');
    ok(await p.locator('.ed-estado .esp-chip.on').count() === 1,
       'ficando a marca na barra de estado, para não se esquecer');

    // Voltar a crescer: nem aviso nem marca.
    await p.setViewportSize({ width: 1440, height: 950 });
    await p.waitForTimeout(400);
    ok(await p.locator('.esp-chip.on').count() === 0, 'e a marca sai quando a janela volta a dar mesa');
  }

  // A escolha de continuar dura a sessão de trabalho, e não só a página.
  await p.setViewportSize({ width: 900, height: 640 }); await p.waitForTimeout(450);
  ok(await p.locator('.esp-aviso.on').count() === 0 && await p.locator('.esp-chip.on').count() === 1,
     'depois de continuar, encolher outra vez traz só a marca — não o aviso todo');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  ok(await p.locator('.esp-aviso.on').count() === 0,
     'quem já disse que sabe não o ouve outra vez na página seguinte');
  ok(await p.locator('.esp-chip.on').count() === 1, 'mas a marca continua lá');
  await p.click('.esp-chip'); await p.waitForTimeout(250);
  ok(await p.locator('.esp-aviso.on').count() === 1, 'e a marca traz o aviso de volta a quem o quiser reler');
  await p.click('.esp-ok'); await p.waitForTimeout(200);

  // Só falta a altura: o aviso tem de o dizer, e não falar de largura.
  await p.evaluate(() => sessionStorage.removeItem('editor.espaco.avancar'));
  await p.setViewportSize({ width: 1440, height: 520 });
  await p.waitForTimeout(500);
  const sohAltura = await p.locator('.esp-cartao .esp-med').innerText();
  ok(/altura/.test(sohAltura) && !/largura/.test(sohAltura),
     'faltando só a altura, é da altura que fala: ' + sohAltura);
  await p.screenshot({ path: OUT + '/editor-espaco.png' });
  await p.setViewportSize({ width: 1440, height: 950 });

  // ============ 2. o manual segue o cartão em vigor ============
  await api('defs_save', { defs: { 'cartao.posicoes': '', 'cartao.moldura_estilo': 'simples',
                                   'cartao.moldura_margem': '28', 'cartao.volutas_escala': '100' } });
  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  let man = await p.locator('.man-wrap').innerText();
  ok(/Linha simples/.test(man), 'o manual nomeia o feitio da moldura em vigor');
  ok(/28 px/.test(man), 'com a distância à aresta que está definida');
  ok(!/Composição — camadas fora do sítio/.test(man),
     'e sem tabela de composição, porque não há nada movido');

  // Mudar a peça — e voltar ao manual sem lhe tocar.
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await api('defs_save', { defs: {
    'cartao.moldura_estilo': 'cantos', 'cartao.moldura_margem': '40', 'cartao.volutas_escala': '120',
    'cartao.posicoes': '{"fecho":"-4.5 -9 -6.5","data":"0 3"}' } });
  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  man = await p.locator('.man-wrap').innerText();
  ok(/Só os cantos/.test(man), 'trocar o feitio da moldura chega ao manual sozinho');
  ok(/40 px/.test(man), 'e a nova distância à aresta também');
  ok(/5,6 mm/.test(man), 'com a conversão para milímetros refeita');
  ok(/214 × 214 px/.test(man), 'as volutas a 120% dão a caixa maior no manual');
  ok(/Composição — camadas fora do sítio/.test(man), 'e nasce a tabela da composição livre');
  ok(/-4,5 %/.test(man) && /-9 %/.test(man), 'com o deslocamento de cada camada');
  ok(/-6,5°/.test(man), 'e a volta de quem foi virado');
  // -4,5% de 720 px são 4,5 mm (o cartão tem 100 mm de largura); -9% de 1080 px
  // são 13,5 mm. É esta a tradução que a gráfica mede na prova.
  ok(/-4,5 mm/.test(man) && /-13,5 mm/.test(man),
     'traduzido para milímetros de papel, que é o que a gráfica mede');

  // A prova visual do manual é o cartão a sério, com a composição aplicada.
  const naProva = await p.evaluate(() => {
    const el = document.querySelector('.palco-cartao [data-camada="fecho"]');
    return el ? { px: el.style.getPropertyValue('--px'), pa: el.style.getPropertyValue('--pa') } : null;
  });
  ok(naProva && parseFloat(naProva.px) === -4.5 && parseFloat(naProva.pa) === -6.5,
     'e a prova impressa no manual sai com a composição, não com o desenho de origem');
  await p.screenshot({ path: OUT + '/manual-composicao.png', fullPage: false });

  // Aplicar outra versão muda a peça: o manual tem de a acompanhar na mesma.
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const vs = await p.evaluate(async () => {
    const r = await fetch('api.php?action=versao_lista&ambito=impresso', { headers: { 'X-CSRF-Token': window.CSRF } });
    return r.json();
  });
  const original = (vs.versoes || []).find(v => v.padrao);
  ok(!!original, 'existe a versão de origem (a peça como a casa a traz)');
  await p.evaluate(async id => {
    await fetch('api.php?action=versao_aplicar&ambito=impresso&id=' + id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, original.id);
  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  man = await p.locator('.man-wrap').innerText();
  ok(/Linha simples/.test(man), 'pôr outra versão em vigor repõe o feitio no manual');
  ok(!/Composição — camadas fora do sítio/.test(man),
     'e a tabela da composição desaparece com ela');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
