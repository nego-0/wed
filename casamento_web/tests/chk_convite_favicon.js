// O convite digital nasce do HTML-base; a prova confirma que todos os ícones
// oficiais estão no seu <head> e que o servidor consegue entregá-los.
const { chromium } = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8922';
const raiz = path.join(__dirname, '..');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, headless: true });
  const page = await browser.newPage();
  let falhas = 0;
  const ok = (v, m) => { console.log((v ? 'PASS' : 'FAIL') + ': ' + m); if (!v) falhas++; };
  const modelo = fs.readFileSync(path.join(raiz, 'assets', 'convite-base.html'), 'utf8')
    .replace('<head>', `<head><base href="${BASE}/">`);
  await page.setContent(modelo, { waitUntil: 'domcontentloaded' });

  const icones = await page.locator('head link[rel~="icon"], head link[rel="apple-touch-icon"]')
    .evaluateAll(xs => xs.map(x => ({ rel: x.rel, href: x.href, sizes: x.sizes.value })));
  ok(icones.some(x => x.rel === 'icon' && x.sizes === '32x32'), 'o convite declara o favicon de 32 píxeis');
  ok(icones.some(x => x.rel === 'icon' && x.sizes === '512x512'), 'o convite declara o ícone de alta resolução');
  ok(icones.some(x => x.rel === 'apple-touch-icon' && x.sizes === '180x180'), 'o convite declara o ícone para dispositivos Apple');
  for (const icone of icones) {
    const resposta = await page.request.get(icone.href);
    ok(resposta.ok(), `${icone.rel} ${icone.sizes || 'alternativo'} está acessível`);
  }
  ok(await page.locator('meta[name="theme-color"][content="#16283a"]').count() === 1,
    'o convite define a cor da barra do navegador');

  const servidor = fs.readFileSync(path.join(raiz, 'convite-digital.php'), 'utf8');
  ok(servidor.includes("'ico'=>'image/x-icon'") && servidor.includes('Ícones do navegador'),
    'a descarga offline embute também os ícones do navegador');
  ok(servidor.includes("asset('assets/icone-sistema-32.png')"),
    'a resposta de convite inválido mantém o favicon oficial');

  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
