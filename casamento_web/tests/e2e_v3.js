// Planta das mesas: mesa dos noivos, zoom, papéis nas alas, painel dos noivos.
// A barra de zoom é 50/100/150 (factores 0.5/1/1.5) e a mesa dos noivos já se
// pode eliminar, com um botão que a repõe. Ver tests/LEIA-ME.md.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
const { confirmar } = require('./_janela');

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE: ' + m.text()); });
  page.on('pageerror', e => errs.push('PAGEERROR: ' + e.message));
  const log = (...a) => console.log('•', ...a);
  let fails = 0; const ok = (c, m) => { log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) fails++; };

  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.fill('input[name=utilizador]', 'admin');
  await page.fill('input[name=senha]', 'noivos2026');
  await page.click('button[type=submit], input[type=submit]');
  await page.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde esta prova trabalha.
  await page.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

  await page.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  // 1) a mesa dos noivos existe sempre; o botão de a repor fica escondido
  ok(await page.locator('.mesa-node.forma-noivos').count() === 1, 'a mesa dos noivos existe por omissão');
  ok(!(await page.locator('#btn-noivos').isVisible()), 'com a mesa no sítio, o botão de a repor está escondido');

  // 2) o seletor de formato do canvas foi retirado
  ok(await page.locator('#sel-formato').count() === 0, 'o seletor de formato do canvas já não existe');

  // 3) barra de zoom: 50/100/150, factores 0.5/1/1.5, 100% por omissão
  const zLer = () => page.locator('#planta').evaluate(el => getComputedStyle(el).getPropertyValue('--z').trim());
  const rotulos  = await page.locator('#zoombar button').allTextContents();
  const factores = await page.locator('#zoombar button').evaluateAll(bs => bs.map(b => b.dataset.zoom));
  log('zoom:', JSON.stringify(rotulos), '→', JSON.stringify(factores));
  ok(JSON.stringify(rotulos)  === JSON.stringify(['50%', '100%', '150%']), 'a barra de zoom tem três níveis');
  ok(JSON.stringify(factores) === JSON.stringify(['0.5', '1', '1.5']), 'os factores acompanham os rótulos');
  ok((await page.locator('#zoombar button.on').textContent()) === '100%', 'abre nos 100%');
  ok(parseFloat(await zLer()) === 1, 'aos 100% o mundo tem o tamanho do canvas');

  for (const [f, rot] of [['0.5', '50%'], ['1.5', '150%']]) {
    await page.click(`#zoombar button[data-zoom="${f}"]`);
    await page.waitForTimeout(250);
    ok(parseFloat(await zLer()) === parseFloat(f), `escolher ${rot} põe --z a ${f}`);
    // O deslocamento da vista já não depende do zoom: depende da trava, e ela
    // está aberta. Antes escondia-se abaixo dos 100%, e num canvas estreito
    // isso deixava metade do salão fora do alcance (ver chk_planta.js).
    const ov = await page.locator('#planta-viewport').evaluate(el => getComputedStyle(el).overflow);
    ok(ov !== 'hidden', `aos ${rot} a vista continua a poder deslocar-se (${ov})`);
  }
  await page.click('#zoombar button[data-zoom="1"]'); await page.waitForTimeout(250);

  // 4) à mesa dos noivos só se sentam padrinhos e madrinhas.
  // Prova-se ANTES de emprestar papéis: numa base pequena podia não sobrar
  // ninguém sem papel para a prova.
  const recusa = await page.evaluate(async () => {
    const gl = await api('convidado_list');
    const noivos = (await api('mesa_list')).mesas.find(m => m.especial === 'noivos');
    const semPapel = (gl.convidados || []).find(g => !g.papel);
    if (!semPapel) return { success: null, message: 'sem cobaia' };
    const r = await api('convidado_mesa', { method: 'POST', body: JSON.stringify({ id: semPapel.id, mesa_id: noivos.id }) });
    return { success: r.success, message: r.message };
  });
  log('recusa:', recusa.message);
  ok(recusa.success === false, 'o servidor recusa sentar um convidado normal à mesa dos noivos');

  // 5) papéis pela API: as alas apanham-nos sozinhas
  const cobaias = await page.evaluate(async () => {
    const gl = await api('convidado_list');
    const livres = (gl.convidados || []).filter(g => !g.papel).slice(0, 2);
    if (livres.length < 2) return null;
    await api('convidado_papel', { method: 'POST', body: JSON.stringify({ id: livres[0].id, papel: 'padrinho' }) });
    await api('convidado_papel', { method: 'POST', body: JSON.stringify({ id: livres[1].id, papel: 'madrinha' }) });
    return livres.map(g => g.id);
  });
  ok(cobaias !== null, 'há convidados sem papel para a prova');
  await page.reload({ waitUntil: 'networkidle' }); await page.waitForTimeout(900);
  const esq = await page.locator('.noivos-ala.esq .ala-p').allTextContents();
  const dir = await page.locator('.noivos-ala.dir .ala-p').allTextContents();
  log('alas — padrinhos:', JSON.stringify(esq), '| madrinhas:', JSON.stringify(dir));
  ok(esq.length > 0, 'os padrinhos aparecem na ala esquerda');
  ok(dir.length > 0, 'as madrinhas aparecem na ala direita');

  // 6) painel da mesa dos noivos
  await page.evaluate(() => { const N = MESAS.find(m => m.especial === 'noivos'); selecionar(N.id); });
  await page.waitForTimeout(500);
  const html = await page.locator('.tab-body').innerHTML();
  ok(!html.includes('centro'), 'o painel dos noivos não fala em centro de mesa');
  ok(html.includes('Nomear padrinho'), 'o painel dos noivos deixa nomear padrinhos e madrinhas');
  ok(html.includes('Padrinhos · ala esquerda') && html.includes('Madrinhas · ala direita'),
     'o painel dos noivos separa as duas alas');

  // 7) eliminar e repor a mesa dos noivos
  // A pergunta deixou de ser um confirm() do browser: é a janela da casa, e
  // responde-se-lhe carregando no botão, como o utilizador faz.
  await page.evaluate(() => { const N = MESAS.find(m => m.especial === 'noivos'); eliminar(N.id); });
  await confirmar(page);
  await page.waitForTimeout(1200);
  ok(await page.locator('.mesa-node.forma-noivos').count() === 0, 'a mesa dos noivos pode ser eliminada');
  ok(await page.locator('#btn-noivos').isVisible(), 'sem ela, aparece o botão de a repor');
  await page.click('#btn-noivos');
  await page.waitForTimeout(1200);
  ok(await page.locator('.mesa-node.forma-noivos').count() === 1, 'o botão repõe a mesa dos noivos');
  ok(!(await page.locator('#btn-noivos').isVisible()), 'reposta a mesa, o botão volta a esconder-se');

  await page.screenshot({ path: OUT + '/mesas_v3.png' });

  // ---- limpeza: devolver os papéis emprestados ----
  if (cobaias) {
    await page.evaluate(async ids => {
      for (const id of ids) await api('convidado_papel', { method: 'POST', body: JSON.stringify({ id, papel: '' }) });
    }, cobaias);
  }

  if (errs.length) console.log('\nERROS DE JS:\n' + errs.join('\n'));
  ok(errs.length === 0, 'nenhum erro de JavaScript');

  console.log(fails ? `\n${fails} FALHA(S)` : '\nTUDO VERDE');
  await browser.close();
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
