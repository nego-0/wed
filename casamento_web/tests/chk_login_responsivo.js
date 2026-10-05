// O login tem duas composições: editorial em desktop e fotografia integrada
// no topo em mobile. Esta prova mede a geometria em vez de depender de uma
// captura visual, para apanhar regressões em ecrãs altos, baixos e estreitos.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM;
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8921';

const tamanhos = [
  { nome: 'desktop', width: 1440, height: 900 },
  { nome: 'tablet',  width: 820,  height: 1180 },
  { nome: 'mobile',  width: 390,  height: 844 },
  { nome: 'estreito', width: 320, height: 568 },
];

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true });
  let falhas = 0;
  const ok = (v, texto) => {
    console.log((v ? 'PASS' : 'FAIL') + ': ' + texto);
    if (!v) falhas++;
  };

  for (const tamanho of tamanhos) {
    const page = await browser.newPage({ viewport: tamanho });
    const erros = [];
    page.on('pageerror', e => erros.push(e.message));
    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    const estado = await page.evaluate(() => {
      const q = s => document.querySelector(s);
      const rect = s => { const r = q(s).getBoundingClientRect(); return { top:r.top, bottom:r.bottom, left:r.left, right:r.right, width:r.width, height:r.height }; };
      return {
        titulo: q('h1')?.textContent.trim(),
        emailTipo: q('#utilizador')?.type,
        emailModo: q('#utilizador')?.inputMode,
        pagina: document.documentElement.scrollWidth,
        viewport: innerWidth,
        visual: rect('.entrada-visual'),
        card: rect('.login .card'),
        botao: rect('#login-submit'),
        posVisual: getComputedStyle(q('.entrada-visual')).position,
        overflowX: getComputedStyle(document.body).overflowX,
      };
    });
    ok(estado.titulo === 'Entrar na Kulemba', `${tamanho.nome}: identifica claramente a entrada`);
    ok(estado.emailTipo === 'email' && estado.emailModo === 'email', `${tamanho.nome}: usa teclado de email`);
    ok(estado.pagina <= estado.viewport + 1, `${tamanho.nome}: sem transbordo horizontal`);
    ok(!erros.length, `${tamanho.nome}: sem erros JavaScript`);
    if (tamanho.width <= 820) {
      ok(estado.posVisual === 'absolute' && estado.visual.top === 0,
        `${tamanho.nome}: fotografia integrada no topo`);
      ok(estado.card.top < estado.visual.bottom && estado.card.top >= 100,
        `${tamanho.nome}: cartão sobrepõe a fotografia sem esconder o início`);
      ok(estado.botao.top < tamanho.height,
        `${tamanho.nome}: acção principal aparece antes do fim do primeiro ecrã`);
    } else {
      ok(estado.posVisual === 'relative' && estado.visual.width > estado.card.width,
        'desktop: campanha e formulário conservam hierarquia equilibrada');
    }

    await page.locator('#senha').fill('segredo');
    await page.locator('#olho').click();
    ok(await page.locator('#senha').getAttribute('type') === 'text' &&
       (await page.locator('#olho').getAttribute('aria-label')).startsWith('Ocultar'),
       `${tamanho.nome}: mostrar palavra-passe actualiza estado e acessibilidade`);
    await page.close();
  }

  // O servidor devolve o mesmo email quando a autenticação falha, para não se
  // obrigar a pessoa a escrevê-lo outra vez no telemóvel.
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.locator('#utilizador').fill('teste.login@kulemba.invalid');
  await page.locator('#senha').fill('palavra-passe-errada');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.locator('#login-submit').click(),
  ]);
  ok(await page.locator('#utilizador').inputValue() === 'teste.login@kulemba.invalid',
    'erro de autenticação conserva o email preenchido');
  ok(await page.locator('#login-erro').getAttribute('role') === 'alert',
    'erro do servidor é anunciado por tecnologia de apoio');

  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
