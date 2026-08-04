const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE: ' + m.text()); });
  page.on('pageerror', e => errs.push('PAGEERROR: ' + e.message));
  const log = (...a) => console.log('•', ...a);

  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.fill('input[name=utilizador]', 'admin');
  await page.fill('input[name=senha]', 'noivos2026');
  await page.click('button[type=submit], input[type=submit]');
  await page.waitForLoadState('networkidle');

  await page.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);

  // 1) noivos table exists by default (no create button)
  const noivosCount = await page.locator('.mesa-node.forma-noivos').count();
  const createBtn = await page.locator('#btn-noivos').count();
  log('noivos exists by default:', noivosCount === 1, '| create button removed:', createBtn === 0);

  // 2) canvas format selector removed
  const fmtSel = await page.locator('#sel-formato').count();
  log('canvas format selector removed:', fmtSel === 0);

  // 3) zoom bar: 50/100/150, default 100 (factor 0.8)
  const zoomLabels = await page.locator('#zoombar button').allTextContents();
  const zoomVals = await page.locator('#zoombar button').evaluateAll(bs => bs.map(b => b.dataset.zoom));
  const onLabel = await page.locator('#zoombar button.on').textContent();
  const zNow = await page.locator('#planta').evaluate(el => getComputedStyle(el).getPropertyValue('--z').trim());
  log('zoom labels:', JSON.stringify(zoomLabels), 'factors:', JSON.stringify(zoomVals));
  log('default on:', onLabel, '| default --z (100% reduced 20%):', zNow);

  // switch to 50 and 150, verify factor
  await page.click('#zoombar button[data-zoom="0.4"]'); await page.waitForTimeout(200);
  const z50 = await page.locator('#planta').evaluate(el => getComputedStyle(el).getPropertyValue('--z').trim());
  await page.click('#zoombar button[data-zoom="1.2"]'); await page.waitForTimeout(200);
  const z150 = await page.locator('#planta').evaluate(el => getComputedStyle(el).getPropertyValue('--z').trim());
  await page.click('#zoombar button[data-zoom="0.8"]'); await page.waitForTimeout(200);
  log('50% ->', z50, '| 150% ->', z150);

  // 4) set roles via API, verify wings auto-detect
  await page.evaluate(async () => {
    const gl = await api('convidado_list');
    const ana = gl.convidados.find(g => g.nome === 'Ana Teste');
    const carla = gl.convidados.find(g => g.nome === 'Carla Padrinho');
    await api('convidado_papel', { method:'POST', body: JSON.stringify({ id: ana.id, papel:'padrinho' }) });
    await api('convidado_papel', { method:'POST', body: JSON.stringify({ id: carla.id, papel:'madrinha' }) });
  });
  await page.reload({ waitUntil: 'networkidle' }); await page.waitForTimeout(700);
  const esq = await page.locator('.noivos-ala.esq .ala-p').allTextContents();
  const dir = await page.locator('.noivos-ala.dir .ala-p').allTextContents();
  log('wings — padrinhos:', JSON.stringify(esq), 'madrinhas:', JSON.stringify(dir));

  // 5) only padrinhos/madrinhas can be placed at noivos: try to drag a person there -> rejected server-side
  const rejected = await page.evaluate(async () => {
    const gl = await api('convidado_list');
    const noivos = (await api('mesa_list')).mesas.find(m => m.especial === 'noivos');
    const sofia = gl.convidados.find(g => g.nome === 'Sofia Convidada');
    const r = await api('convidado_mesa', { method:'POST', body: JSON.stringify({ id: sofia.id, mesa_id: noivos.id }) });
    return { success: r.success, message: r.message };
  });
  log('assign regular person to noivos rejected:', rejected.success === false, '|', rejected.message);

  // 6) noivos detail has no "center"/no delete button
  await page.evaluate(() => { const N = MESAS.find(m => m.especial === 'noivos'); selecionar(N.id); });
  await page.waitForTimeout(400);
  const hasCentro = (await page.locator('.tab-body').innerHTML()).includes('centro');
  const hasDelete = await page.locator('.tab-body button:has-text("Eliminar mesa")').count();
  const hasNomear = (await page.locator('.tab-body').innerHTML()).includes('Nomear padrinho');
  log('noivos panel — no center:', !hasCentro, '| no delete btn:', hasDelete === 0, '| has role naming:', hasNomear);

  await page.screenshot({ path: OUT + '/mesas_v3.png' });
  console.log('\nERRORS:', errs.length ? errs.join('\n') : 'none');
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
