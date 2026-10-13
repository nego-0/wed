// Os três indicadores sobre a fotografia da Demonstração devem caber no
// cartão, inclusive nos telemóveis estreitos, sem uma faixa de scroll.
const { chromium } = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const raiz = path.join(__dirname, '..');
const css = ['assets/estilo.css', 'assets/central.css']
  .map(f => fs.readFileSync(path.join(raiz, f), 'utf8')).join('\n');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, headless: true });
  let falhas = 0;
  const ok = (v, m) => { console.log((v ? 'PASS' : 'FAIL') + ': ' + m); if (!v) falhas++; };
  for (const width of [320, 360, 390, 700]) {
    const page = await browser.newPage({ viewport: { width, height: 700 } });
    await page.setContent(`<style>${css}</style><body class="central-publica">
      <section class="mk-cinema"><div class="mk-cinema-copia"><h2>Da primeira lista à última entrega.</h2></div>
      <div class="mk-cinema-cartoes"><span><i>↪</i><b>Entrada</b><small>87 presentes</small></span>
      <span><i>♢</i><b>Serviço</b><small>3 entregas</small></span>
      <span><i>◉</i><b>Orçamento</b><small>sob controlo</small></span></div></section></body>`);
    const estado = await page.evaluate(() => {
      const grupo = document.querySelector('.mk-cinema-cartoes');
      const g = grupo.getBoundingClientRect();
      const cards = [...grupo.children].map(el => {
        const r = el.getBoundingClientRect();
        return { left: r.left, right: r.right, width: r.width, scroll: el.scrollWidth, client: el.clientWidth };
      });
      return { overflow: getComputedStyle(grupo).overflowX,
        scroll: grupo.scrollWidth, client: grupo.clientWidth,
        cards, pagina: document.documentElement.scrollWidth, viewport: innerWidth,
        dentro: cards.every(r => r.left >= g.left - .5 && r.right <= g.right + .5) };
    });
    ok(estado.overflow !== 'auto' && estado.scroll <= estado.client + 1,
      `${width}px: o grupo não cria scroll horizontal`);
    ok(estado.dentro && estado.cards.every(x => x.width > 0),
      `${width}px: os três cartões ficam inteiros dentro da fotografia`);
    ok(estado.pagina <= estado.viewport + 1,
      `${width}px: a secção não aumenta a largura da página`);
    if (process.env.SCREENSHOT_PATH && width === 390)
      await page.screenshot({ path: process.env.SCREENSHOT_PATH, fullPage: true });
    await page.close();
  }
  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
