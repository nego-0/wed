// A Demonstração e a Ajuda partilham a mesma central visual. Esta prova
// mantém legíveis as tintas que, no tema escuro, antes coincidiam com as
// superfícies: símbolos, avatares, contadores, pesquisa e texto da campanha.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM;
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8922';
const USER = process.env.TEST_USER || 'admin';
const PASSWORD = process.env.TEST_PASSWORD || 'noivos2026';

const ESPECIFICACOES = [
  ['.aj-hero .aj-kicker', '--forest', 4.5, 'chamada do cabeçalho'],
  ['.aj-pesquisa input', 'self', 4.5, 'texto da pesquisa'],
  ['.aj-pesquisa>[data-ico]', '--sand', 3, 'ícone da pesquisa'],
  ['.aj-categorias-titulo>span', 'self', 3, 'símbolo das categorias'],
  ['.aj-submenu button.ativo>span', '.aj-submenu button.ativo', 3, 'ícone da categoria activa'],
  ['.aj-card[data-modulo="bar"] .aj-avatar:not(.kulemba)', 'self', 3, 'ícone do módulo'],
  ['.aj-card[data-modulo="bar"] .aj-avatar.kulemba', 'self', 4.5, 'assinatura Kulemba'],
  ['.aj-card[data-modulo="bar"] .aj-meta>b', '--card', 4.5, 'contador de passos'],
  ['.aj-card[data-modulo="bar"] .aj-passo[open] summary b', '--card', 4.5, 'título do passo'],
  ['.aj-card[data-modulo="bar"] .aj-passo[open] .aj-passo-detalhe p', '--card', 4.5, 'narração do passo'],
];

async function escolherEscuro(p) {
  await p.evaluate(() => localStorage.setItem('tema', 'escuro'));
  await p.reload({ waitUntil: 'networkidle' });
}

async function abrirBar(p, prefixo = '') {
  await p.locator(prefixo + '[data-aj-modulo="bar"]').click();
  await p.waitForTimeout(1200);
  const detalhes = p.locator(prefixo + '.aj-card[data-modulo="bar"]>details').first();
  if (!(await detalhes.getAttribute('open'))) await detalhes.locator(':scope > summary').click();
  const passo = detalhes.locator('.aj-passo').first();
  if (!(await passo.getAttribute('open'))) await passo.locator(':scope > summary').click();
}

async function medir(p, especificacoes = ESPECIFICACOES) {
  return p.evaluate(especificacoes => {
    const raiz = getComputedStyle(document.documentElement);
    const rgb = valor => {
      const s = String(valor || '').trim();
      if (s[0] === '#') {
        const h = s.slice(1);
        const x = h.length === 3 ? h.split('').map(c => c + c).join('') : h.slice(0, 6);
        return [0, 2, 4].map(i => parseInt(x.slice(i, i + 2), 16));
      }
      const m = s.match(/[\d.]+/g);
      return m ? m.slice(0, 3).map(Number) : null;
    };
    const lum = c => {
      const f = v => (v /= 255) <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
      return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]);
    };
    const contraste = (a, b) => {
      const x = lum(a), y = lum(b);
      return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
    };
    return especificacoes.map(([selector, fundo, minimo, nome]) => {
      const el = document.querySelector(selector);
      if (!el) return { nome, selector, erro: 'elemento ausente' };
      const estilo = getComputedStyle(el);
      const tinta = rgb(estilo.color);
      let superficie;
      if (fundo === 'self') superficie = rgb(estilo.backgroundColor);
      else if (fundo.startsWith('--')) superficie = rgb(raiz.getPropertyValue(fundo));
      else {
        const bg = document.querySelector(fundo);
        superficie = bg ? rgb(getComputedStyle(bg).backgroundColor) : null;
      }
      if (!tinta || !superficie) return { nome, selector, erro: 'cor não resolvida' };
      const valor = contraste(tinta, superficie);
      return { nome, selector, valor, minimo, tinta, superficie, ok: valor >= minimo };
    });
  }, especificacoes);
}

(async () => {
  const b = await chromium.launch({ executablePath: EXE, headless: true });
  let falhas = 0;
  const ok = (v, m) => { console.log((v ? 'PASS' : 'FAIL') + ': ' + m); if (!v) falhas++; };
  const ctx = await b.newContext({ viewport: { width: 1440, height: 950 } });
  const pub = await ctx.newPage();

  await pub.goto(BASE + '/atendimento.php', { waitUntil: 'networkidle' });
  await escolherEscuro(pub);
  ok(await pub.evaluate(() => getComputedStyle(document.body).backgroundImage !== 'none'),
     'a Demonstração usa o fundo escuro do tema, sem a antiga folha clara');
  await abrirBar(pub, '#demonstracao ');
  let medidas = await medir(pub);
  for (const m of medidas) ok(m.ok, `Demonstração: ${m.nome} ${m.valor ? m.valor.toFixed(2) + ':1' : m.erro}`);

  const admin = await ctx.newPage();
  await admin.goto(BASE + '/login.php');
  await admin.fill('input[name=utilizador]', USER);
  await admin.fill('input[name=senha]', PASSWORD);
  await Promise.all([admin.waitForLoadState('networkidle'), admin.click('button[type=submit]')]);
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await admin.goto(BASE + '/ajuda.php', { waitUntil: 'networkidle' });
  await escolherEscuro(admin);
  await abrirBar(admin);
  medidas = await medir(admin);
  for (const m of medidas) ok(m.ok, `Ajuda: ${m.nome} ${m.valor ? m.valor.toFixed(2) + ':1' : m.erro}`);

  for (const [pagina, p] of [['Demonstração', pub], ['Ajuda', admin]]) {
    await p.setViewportSize({ width: 390, height: 844 });
    await p.reload({ waitUntil: 'networkidle' });
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
       `${pagina}: o tema escuro não transborda no telemóvel`);
    ok((await p.locator('html').getAttribute('data-tema')) === 'escuro',
       `${pagina}: o tema escuro permanece activo no telemóvel`);
  }

  await b.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
