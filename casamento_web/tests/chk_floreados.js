// Os floreados: onde ficam, e com que feitio.
//
// Ficavam mal por uma razão que não estava à vista: a regra do posicionamento
// livre punha `translate: calc(0 * 7.2px)` em TODAS as camadas — e um
// translate a zero continua a ser um translate, o que faz do elemento o bloco
// contentor dos descendentes em posição absoluta. Os floreados posicionam-se
// contra o bloco dos nomes; passaram a posicionar-se contra um invólucro de
// altura zero, e o da esquerda foi atravessar o primeiro nome.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
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
  await api('defs_save', { defs: { 'cartao.floreado': 'classico', 'cartao.posicoes': '',
                                   'cartao.floreados_escala': '100' } });

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  await p.evaluate(() => { zoom = 1; aplicarZoom(); });
  await p.waitForTimeout(300);

  // ============ 1. ladeiam os nomes; não passam por cima deles ============
  const cx = () => p.evaluate(() => {
    const n = document.querySelector('#escala .ct-nomes').getBoundingClientRect();
    const g = s => { const r = document.querySelector('#escala ' + s).getBoundingClientRect();
                     return { e: r.left - n.left, d: r.right - n.left,
                              t: r.top - n.top, b: r.bottom - n.top }; };
    // Os glifos do primeiro nome, e não a caixa (que ocupa a coluna inteira).
    const el = document.querySelectorAll('#escala .ct-nome')[0];
    const fx = document.createRange(); fx.selectNodeContents(el);
    const t = fx.getBoundingClientRect();
    return { esq: g('.ct-floreado-e'), dir: g('.ct-floreado-d'),
             texto: { e: t.left - n.left, d: t.right - n.left }, alt: n.height };
  });
  const c0 = await cx();
  console.log('   ', JSON.stringify(c0));
  ok(c0.esq.d <= c0.texto.e + 1,
     `o floreado da esquerda acaba antes de o nome começar (${Math.round(c0.esq.d)} ≤ ${Math.round(c0.texto.e)})`);
  ok(c0.dir.e >= c0.texto.d - 1,
     `e o da direita começa depois de ele acabar (${Math.round(c0.dir.e)} ≥ ${Math.round(c0.texto.d)})`);
  ok(c0.esq.t > 0 && c0.esq.b < c0.alt + 1,
     'ficam dentro da altura do bloco dos nomes, e não a pairar acima dele');
  ok(Math.abs(c0.esq.t - c0.dir.t) < 1 && Math.abs(c0.esq.b - c0.dir.b) < 1,
     'os dois à mesma altura — são um par, e viam-se desencontrados');

  // A causa: uma camada por mover não pode criar bloco contentor nenhum.
  const trans = await p.evaluate(() => {
    const el = document.querySelector('#escala [data-camada="floreados"]');
    return { tr: getComputedStyle(el).translate, rt: getComputedStyle(el).rotate };
  });
  ok(trans.tr === 'none' && trans.rt === 'none',
     `uma camada por mover não leva transformação nenhuma (${trans.tr} / ${trans.rt})`);

  // ============ 2. cinco feitios, à escolha ============
  await p.evaluate(() => selecionar('floreados'));
  await p.waitForTimeout(300);
  const feitios = await p.evaluate(() => [...document.querySelectorAll('#props select option')]
    .map(o => ({ v: o.value, t: o.textContent.trim() })));
  console.log('   feitios:', feitios.map(x => x.t).join(', '));
  ok(feitios.length === 5, 'há cinco feitios de floreado à escolha');
  ok(feitios.some(x => x.v === 'classico'), 'com o clássico entre eles');

  // Cada um desenha alguma coisa, e todos na mesma âncora — trocar de feitio
  // é uma escolha de desenho, não um desalinhamento.
  const medidas = {};
  for (const { v, t } of feitios) {
    await p.evaluate(x => mudarFloreado(x), v);
    await p.waitForTimeout(220);
    medidas[v] = await p.evaluate(() => {
      const el = document.querySelector('#escala .ct-floreado-e');
      return { caminhos: el.querySelectorAll('path').length,
               d: [...el.querySelectorAll('path')].map(x => x.getAttribute('d') || '').join('|').length };
    });
    const c = await cx();
    ok(medidas[v].caminhos > 0, `"${t}" desenha mesmo alguma coisa (${medidas[v].caminhos} traços)`);
    ok(Math.abs(c.esq.t - c0.esq.t) < 1 && Math.abs(c.esq.e - c0.esq.e) < 1,
       `"${t}" fica exatamente no mesmo sítio do clássico`);
  }
  const assinaturas = new Set(Object.values(medidas).map(m => m.caminhos + ':' + m.d));
  ok(assinaturas.size === 5, `os cinco são desenhos diferentes (${assinaturas.size} distintos)`);

  // ============ 3. grava, relê, e chega ao que se imprime ============
  await p.evaluate(() => mudarFloreado('filete'));
  ok(await p.evaluate(() => guardar()), 'o feitio grava-se');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  ok(await p.evaluate(() => est.floreado) === 'filete', 'e volta ao reabrir o editor');

  await p.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
  const naFolha = await p.evaluate(() => {
    const el = document.querySelector('.cartao .ct-floreado-e');
    const n = el.closest('.ct-nomes').getBoundingClientRect(), r = el.getBoundingClientRect();
    return { polig: !!el.querySelector('path[fill]:not([fill=none])'),
             foraDoTexto: r.right - n.left };
  });
  ok(naFolha.polig, 'a folha de impressão traz o filete (que é o único com losango cheio)');
  ok(naFolha.foraDoTexto < 45, 'e continua ao lado dos nomes, não por cima');

  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  ok(/Filete/.test(await p.locator('.man-wrap').innerText()),
     'e o manual de impressão nomeia o feitio escolhido');

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  await p.evaluate(() => { selecionar('floreados'); zoom = .8; aplicarZoom(); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: OUT + '/floreados.png' });
  await api('defs_save', { defs: { 'cartao.floreado': 'classico' } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
