// As áreas de administração têm de caber num telemóvel sem transformar a
// página numa faixa horizontal. A escolha de mesa é a exceção deliberada ao
// fluxo: abre como diálogo centrado e sobrevive ao teclado virtual.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  const erros = []; p.on('pageerror', e => erros.push(e.message));
  let falhas = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ': ' + m); if (!c) falhas++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');

  // A administração da plataforma existe sem casamento aberto.
  const paginasCasa = ['plataforma.php', 'modelos.php'];
  // As restantes áreas trabalham sobre um casamento.
  await p.evaluate(async () => {
    const l = await (await fetch('api.php?action=casamento_lista&estado=ativo',
      { headers: { 'X-CSRF-Token': window.CSRF } })).json();
    const c = (l.casamentos || [])[0];
    if (!c) throw new Error('A prova móvel precisa de um casamento ativo.');
    await fetch('api.php?action=casamento_abrir&id=' + c.id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  const paginasCasamento = [
    'index.php', 'mesas.php', 'graficas.php', 'digital.php',
    'orcamento.php', 'gestao.php', 'licenca.php'
  ];

  for (const pagina of paginasCasa.concat(paginasCasamento)) {
    await p.goto(BASE + '/' + pagina, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(500);
    const m = await p.evaluate(() => {
      const d = document.documentElement;
      const desc = document.querySelector('.pagina-descricao p');
      return {
        url: location.pathname.split('/').pop(),
        largura: d.scrollWidth, janela: d.clientWidth,
        descricao: !!desc && !desc.closest('.topo') && !!desc.textContent.trim(),
        nome: !!document.querySelector('.topo .tc-nome, .topo .topo-casal'),
        licenca: !!document.querySelector('.topo .licenca-restante')
      };
    });
    ok(m.url === pagina, `${pagina} abre sem desvio (${m.url})`);
    ok(m.largura <= m.janela + 1,
       `${pagina} não cria rolagem horizontal (${m.largura}/${m.janela}px)`);
    ok(m.descricao, `${pagina} apresenta a descrição no corpo`);
    ok(!m.nome && !m.licenca, `${pagina} não repete noivos nem licença no cabeçalho`);
  }

  // A frase da contagem, com a ordem e o texto pedidos.
  await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
  const contagem = (await p.locator('#topo-contagem').innerText()).replace(/\s+/g, ' ').trim();
  ok(/^\d+ Dias? Até ao “Sim, Aceito”(?: \d\d:\d\d:\d\d)?$/.test(contagem),
     'o cabeçalho diz «N Dias Até ao “Sim, Aceito”»: ' + contagem);

  // O seletor das mesas em modo modal. A prova usa o próprio construtor do
  // painel para não depender de haver uma mesa ocupada nesta base concreta.
  await p.goto(BASE + '/mesas.php', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#tabset-tabs .rt');
  await p.evaluate(() => {
    const prova = document.createElement('div'); prova.id = 'combo-prova';
    prova.style.cssText = 'position:fixed;left:8px;top:260px;width:210px;z-index:100';
    prova.innerHTML = comboHTML('mesa-pessoa', -1, 'Escolher mesa', 'combo-inline');
    document.getElementById('tab-body').appendChild(prova);
    abrirCombo(prova.querySelector('.combo'));
  });
  await p.waitForTimeout(100);
  const modal = async () => p.evaluate(() => {
    const x = document.querySelector('#combo-prova .combo-pop');
    const r = x.getBoundingClientRect(), v = window.visualViewport;
    const cx = (v ? v.offsetLeft + v.width / 2 : innerWidth / 2);
    const cy = (v ? v.offsetTop + v.height / 2 : innerHeight / 2);
    return { aberto: !x.hidden && document.body.classList.contains('combo-aberto'),
      dx: Math.round(r.left + r.width / 2 - cx), dy: Math.round(r.top + r.height / 2 - cy),
      dentro: r.left >= 0 && r.right <= innerWidth + 1 && r.top >= 0 && r.bottom <= innerHeight + 1,
      rect: [r.left,r.top,r.width,r.height].map(Math.round), display: getComputedStyle(x).display,
      estilo: x.getAttribute('style') || '' };
  });
  let mm = await modal();
  ok(mm.aberto && mm.dentro && Math.abs(mm.dx) <= 2 && Math.abs(mm.dy) <= 2,
     `a escolha abre centrada (${mm.dx}/${mm.dy}px) e dentro do ecrã · ${JSON.stringify(mm)}`);

  // Simula a redução de altura causada pelo teclado virtual. Era este resize
  // que fechava o seletor por si.
  await p.setViewportSize({ width: 390, height: 600 });
  await p.waitForTimeout(150);
  mm = await modal();
  ok(mm.aberto && mm.dentro && Math.abs(mm.dy) <= 2,
     'a escolha continua aberta e centrada depois do redimensionamento do teclado · ' + JSON.stringify(mm));

  await p.screenshot({ path: OUT + '/admin-mobile-mesas.png' });
  ok(erros.length === 0, 'nenhum erro de JavaScript: ' + erros.slice(0, 3).join(' | '));
  await b.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
