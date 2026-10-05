// Arquitectura híbrida: cada suporte mantém a sua mesa e partilha capacidades,
// acessibilidade, adaptação móvel e recuperação de rascunhos.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8921';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const page = await ctx.newPage();
  const erros = [];
  page.on('pageerror', e => erros.push(e.message));
  page.on('console', m => { if (m.type() === 'error') erros.push(m.text()); });
  let falhas = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ': ' + m); if (!c) falhas++; };

  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.fill('[name=utilizador]', 'admin');
  await page.fill('[name=senha]', 'noivos2026');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  const abriu = await page.evaluate(async () => {
    const r = await fetch('api.php?action=casamento_abrir&id=1', {
      method:'POST', headers:{'X-CSRF-Token':window.CSRF}
    }); return r.json();
  });
  ok(abriu && abriu.success, 'abre o casamento de prova');

  for (const nome of ['editor-cartao.php', 'convite-editor.php']) {
    await page.setViewportSize({ width:1366, height:768 });
    await page.goto(BASE + '/' + nome, { waitUntil:'networkidle' });
    await page.waitForTimeout(nome.startsWith('convite') ? 1800 : 500);
    const abriuEditor = new URL(page.url()).pathname.endsWith('/' + nome);
    ok(abriuEditor, `${nome}: o editor abriu (${page.url()})`);
    if (!abriuEditor) continue;

    const base = await page.evaluate(() => ({
      manifesto: window.EDITOR_HIBRIDO && window.EDITOR_HIBRIDO.manifesto,
      opcoes: document.querySelector('.ed-opcoes').getBoundingClientRect().height,
      grupos: document.querySelectorAll('.ed-opcoes-principais,.ed-opcoes-contexto').length,
      mais: !!document.querySelector('.ed-mais'),
      cabecalhoTab: document.querySelector('.ed-painel>h3').tabIndex,
      cabecalhoRole: document.querySelector('.ed-painel>h3').getAttribute('role'),
      ariaLive: !!document.querySelector('.ed-estado [aria-live="polite"]')
    }));
    ok(base.manifesto && base.manifesto.schema === 1 && base.manifesto.ambito,
      `${nome}: recebe o manifesto de capacidades do modelo`);
    ok(base.opcoes < 70, `${nome}: a barra principal conserva uma linha (${Math.round(base.opcoes)} px)`);
    ok(base.grupos === 2 && base.mais, `${nome}: comandos principais, contexto e Mais acções estão separados`);
    ok(base.cabecalhoTab === 0 && base.cabecalhoRole === 'button', `${nome}: painéis respondem ao teclado`);
    ok(base.ariaLive, `${nome}: mensagens do editor são anunciadas por leitores de ecrã`);
    if (nome === 'editor-cartao.php') {
      await page.locator('.ed-mais > summary').click();
      await page.locator('#bt-guias-impressao').click();
      ok(await page.locator('#arte.guias-impressao').count() === 1,
        'editor-cartao.php: a prova pode mostrar corte e área segura de impressão');
    }

    const h = page.locator('.ed-painel>h3').first();
    await h.focus(); const antes = await h.getAttribute('aria-expanded'); await h.press('Enter');
    ok(await h.getAttribute('aria-expanded') !== antes, `${nome}: Enter abre ou fecha o painel focado`);

    await page.setViewportSize({ width:900, height:700 }); await page.waitForTimeout(400);
    ok(await page.locator('.esp-aviso.on').count() === 0, `${nome}: tablet usa a composição adaptada sem bloqueio`);
    ok(await page.locator('.ed-movel').isVisible(), `${nome}: tablet recebe a navegação do editor`);
    await page.locator('.ed-movel-bt[data-abrir="camadas"]').click();
    ok(await page.locator('body.ed-inspector-on').count() === 1, `${nome}: inspector abre como gaveta no tablet`);

    await page.setViewportSize({ width:390, height:844 }); await page.waitForTimeout(450);
    const movel = await page.evaluate(() => {
      const mesa = document.querySelector('.ed-mesa').getBoundingClientRect();
      const nav = document.querySelector('.ed-movel').getBoundingClientRect();
      const palco = document.querySelector('#arte,#palco').getBoundingClientRect();
      return { sw:document.documentElement.scrollWidth, vw:innerWidth, mesa:mesa.width,
        palco:palco.width, palcoCss:document.querySelector('#arte,#palco').style.cssText,
        transform:getComputedStyle(document.querySelector('#arte,#palco')).transform,
        nav:nav.height, botoes:[...document.querySelectorAll('.ed-movel-bt')].map(x=>x.getBoundingClientRect().height) };
    });
    ok(movel.sw <= movel.vw, `${nome}: não há transbordo horizontal a 390 px`);
    ok(movel.mesa >= 360 && movel.palco >= 300,
      `${nome}: a prévia ocupa o centro útil no telemóvel (mesa ${Math.round(movel.mesa)} / peça ${Math.round(movel.palco)} px; ${movel.palcoCss}; ${movel.transform})`);
    ok(movel.nav >= 56 && movel.botoes.every(x => x >= 44), `${nome}: navegação móvel tem alvos tácteis suficientes`);
    await page.locator('.ed-movel-bt[data-abrir="previa"]').click();
    ok(await page.locator('body.ed-inspector-on').count() === 0, `${nome}: Prévia devolve toda a área à peça`);

    // Mudar um campo sem gravar deve criar uma cópia recuperável local. Não se
    // envia nada ao servidor nesta prova.
    await page.evaluate(() => marcarSujo(true));
    await page.waitForTimeout(900);
    const r = await page.evaluate(() => Object.keys(localStorage).find(k => k.startsWith('kulemba.editor.rascunho.')) || '');
    ok(!!r, `${nome}: alterações criam rascunho recuperável neste dispositivo`);
    page.once('dialog', d => d.accept());
    await page.reload({ waitUntil:'networkidle' });
    await page.waitForTimeout(nome.startsWith('convite') ? 1600 : 450);
    ok(await page.locator('.ed-recuperar').count() === 1,
      `${nome}: ao reabrir, oferece recuperar ou descartar o rascunho`);
    await page.locator('.ed-recuperar .ed-descartar').click();
  }

  ok(erros.length === 0, 'sem erros JavaScript: ' + erros.slice(0, 3).join(' | '));
  await browser.close();
  console.log(falhas ? `\n${falhas} verificação(ões) falharam.` : '\nTudo certo.');
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
