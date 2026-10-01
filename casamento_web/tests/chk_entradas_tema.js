// Login, Registo e Demonstração são portas públicas da mesma plataforma.
// Esta prova impede que o tema escuro volte a deixar uma folha clara atrás
// dos cartões e garante que a Demonstração conserva o selector flutuante.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM;
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8922';
const TEMAS = ['jardim', 'classico', 'azul', 'escuro'];

function rgb(valor) {
  const m = String(valor || '').match(/[\d.]+/g);
  return m ? m.slice(0, 3).map(Number) : null;
}
function luminancia(c) {
  const f = v => (v /= 255) <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
  return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]);
}
function contraste(a, b) {
  const x = luminancia(a), y = luminancia(b);
  return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true });
  let falhas = 0;
  const ok = (valor, texto) => {
    console.log((valor ? 'PASS' : 'FAIL') + ': ' + texto);
    if (!valor) falhas++;
  };
  const context = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await context.newPage();

  for (const pagina of ['login.php', 'registo.php']) {
    await page.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    for (const tema of TEMAS) {
      await page.evaluate(t => localStorage.setItem('tema', t), tema);
      await page.reload({ waitUntil: 'networkidle' });
      const estado = await page.evaluate(() => {
        const raiz = getComputedStyle(document.documentElement);
        const corpo = getComputedStyle(document.body);
        const card = document.querySelector('.card');
        const titulo = document.querySelector('.evento,.tit');
        const input = document.querySelector('input');
        return {
          tema: document.documentElement.dataset.tema,
          fundo: corpo.backgroundImage,
          card: getComputedStyle(card).backgroundColor,
          cardToken: raiz.getPropertyValue('--card').trim(),
          titulo: getComputedStyle(titulo).color,
          inputFundo: getComputedStyle(input).backgroundColor,
          inputTexto: getComputedStyle(input).color,
          largura: document.documentElement.scrollWidth,
          viewport: innerWidth,
          fab: !!document.querySelector('.tema-fab')
        };
      });
      ok(estado.tema === tema, `${pagina}: aplica o tema ${tema}`);
      ok(estado.fundo !== 'none', `${pagina}: usa a folha temática em ${tema}`);
      ok(estado.fab, `${pagina}: conserva o selector de tema em ${tema}`);
      ok(contraste(rgb(estado.titulo), rgb(estado.card)) >= 4.5,
        `${pagina}: título legível sobre o cartão em ${tema}`);
      ok(contraste(rgb(estado.inputTexto), rgb(estado.inputFundo)) >= 4.5,
        `${pagina}: campos legíveis em ${tema}`);
      ok(estado.largura <= estado.viewport + 1, `${pagina}: sem transbordo em ${tema} (${estado.largura}/${estado.viewport})`);
    }
  }

  await page.goto(BASE + '/atendimento.php', { waitUntil: 'networkidle' });
  await page.evaluate(() => localStorage.setItem('tema', 'escuro'));
  await page.reload({ waitUntil: 'networkidle' });
  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
  const fab = page.locator('.tema-fab-btn');
  ok(await fab.isVisible(), 'Demonstração: o botão flutuante permanece visível após a rolagem');
  ok((await fab.evaluate(el => getComputedStyle(el.closest('.tema-fab')).position)) === 'fixed',
    'Demonstração: o selector mantém posição flutuante');
  await fab.click();
  ok(await page.locator('#temaFabPop.aberto').isVisible(),
    'Demonstração: o selector abre as opções de tema');
  await page.locator('[data-tema-op="azul"]').click();
  ok((await page.locator('html').getAttribute('data-tema')) === 'azul',
    'Demonstração: a escolha muda o tema sem recarregar');

  await page.setViewportSize({ width: 390, height: 844 });
  for (const pagina of ['login.php', 'registo.php', 'atendimento.php']) {
    await page.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    const larguras = await page.evaluate(() => ({ pagina: document.documentElement.scrollWidth, viewport: innerWidth }));
    if (larguras.pagina > larguras.viewport + 1) {
      console.log('OVERFLOW ' + pagina, await page.evaluate(() => [...document.querySelectorAll('*')]
        .map(el => ({ tag: el.tagName, cls: el.className, id: el.id, left: el.getBoundingClientRect().left,
          right: el.getBoundingClientRect().right, scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }))
        .filter(x => x.right > innerWidth + 1 || x.left < -1 || x.scrollWidth > x.clientWidth + 1)
        .slice(0, 12)));
    }
    ok(larguras.pagina <= larguras.viewport + 1,
      `${pagina}: não transborda no telemóvel (${larguras.pagina}/${larguras.viewport})`);
    ok(await page.locator('.tema-fab-btn').isVisible(),
      `${pagina}: botão de tema visível no telemóvel`);
  }

  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
