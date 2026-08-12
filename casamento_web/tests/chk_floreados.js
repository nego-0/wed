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
             texto: { e: t.left - n.left, d: t.right - n.left }, alt: n.height, larg: n.width };
  });
  const c0 = await cx();
  console.log('   ', JSON.stringify(c0));
  // O par abraça os nomes em simetria ROTACIONAL — é o desenho de referência,
  // e a mesma ideia das volutas e das trepadeiras em cantos opostos. O que
  // estava errado não era isto: era o invólucro a virar bloco contentor, que
  // punha o da direita a pairar 100px ACIMA do bloco.
  ok(Math.abs(c0.esq.t - (c0.alt - c0.dir.b)) < 1.5 &&
     Math.abs(c0.esq.e - (c0.larg - c0.dir.d)) < 1.5,
     'o par é rotacionalmente simétrico à volta do centro dos nomes');
  ok(c0.dir.b > 0 && c0.dir.t < c0.alt,
     `o da direita fica dentro do bloco dos nomes, e não a pairar acima (topo ${Math.round(c0.dir.t)})`);
  ok(c0.esq.t > -8 && c0.esq.t < 8,
     `e o da esquerda encosta ao topo, como o design o pôs (${Math.round(c0.esq.t)})`);

  // A causa: uma camada por mover não pode criar bloco contentor nenhum.
  const trans = await p.evaluate(() => {
    const el = document.querySelector('#escala [data-camada="floreados"]');
    return { tr: getComputedStyle(el).translate, rt: getComputedStyle(el).rotate };
  });
  ok(trans.tr === 'none' && trans.rt === 'none',
     `uma camada por mover não leva transformação nenhuma (${trans.tr} / ${trans.rt})`);

  // ...mas mover uma também não pode DEFORMAR nada. Pôr-lhe translate ou rotate
  // faz dela o bloco contentor dos ornamentos que lá vivem, e se o invólucro
  // não tiver a medida daquilo contra que eles se posicionam, saltam ao
  // primeiro toque — antes sequer de se ter arrastado. Já aconteceu três vezes.
  const aoPegar = await p.evaluate(() => {
    const casos = [
      ['floreados', '.ct-floreado-e',  '.ct-nomes'],
      ['floreados', '.ct-floreado-d',  '.ct-nomes'],
      ['volutas',   '.ct-voluta-se',   '.cartao'],
      ['ramos',     '.ct-ramo-sd',     '.cartao'],
    ];
    const r = [];
    for (const [camada, orn, contra] of casos) {
      const inv = document.querySelector(`#escala [data-camada="${camada}"]`);
      const onde = () => {
        const a = document.querySelector('#escala ' + orn).getBoundingClientRect();
        const b = document.querySelector('#escala ' + contra).getBoundingClientRect();
        return [+(a.left - b.left).toFixed(1), +(a.top - b.top).toFixed(1)];
      };
      const parado = onde();
      inv.style.translate = '0px 0px';  const comTranslate = onde();
      inv.style.rotate    = '0deg';     const comRotate    = onde();
      inv.style.translate = ''; inv.style.rotate = '';
      r.push({ camada, orn, parado, comTranslate, comRotate });
    }
    return r;
  });
  for (const c of aoPegar) {
    const igual = a => a[0] === c.parado[0] && a[1] === c.parado[1];
    ok(igual(c.comTranslate) && igual(c.comRotate),
       `pegar em "${c.camada}" não mexe com ${c.orn} `
       + `(parado [${c.parado}], com translate [${c.comTranslate}], com volta [${c.comRotate}])`);
  }

  // O ar entre os nomes e o floreado. O desenho de origem deixa-o dos dois
  // lados, e ele não vem do floreado (que está em left:-26px / top:-4px, como
  // no ficheiro de referência) — vem da altura do elo. O coração não declara
  // entrelinha no original, e `normal` dá 24 px em corpo 20; com entrelinha 1
  // dava 20, os nomes fechavam-se quatro píxeis e o de baixo ia tocar na volta.
  const elo = await p.evaluate(() => {
    const h = document.querySelector('#escala .ct-coracao');
    const esc = h.closest('.escala');
    const s = esc ? esc.getBoundingClientRect().width / esc.offsetWidth : 1;
    return +(h.getBoundingClientRect().height / s).toFixed(1);
  });
  ok(Math.abs(elo - 24) < 1, `o coração ocupa as 24 px do original (${elo})`);

  // E o que daí resulta: o intervalo entre os dois nomes. Mede-se de caixa a
  // caixa, que é exato — o ar até ao traço do floreado só se julga a olho, e
  // para isso está o tests/amostras.js. São as 24 px do elo mais as duas
  // margens de 2 px: 28. Com entrelinha 1 dava 24, e era esse o encosto.
  const entre = await p.evaluate(() => {
    const cx = document.querySelector('#escala .cartao');
    const s = cx.getBoundingClientRect().width / cx.offsetWidth;
    const n = [...document.querySelectorAll('#escala .ct-nome')].map(x => x.getBoundingClientRect());
    return +((n[1].top - n[0].bottom) / s).toFixed(1);
  });
  ok(Math.abs(entre - 28) < 1, `os dois nomes ficam a 28 px um do outro, como no original (${entre})`);

  // O traço do clássico é o do desenho de origem, e não uma aproximação.
  const traco = await p.evaluate(() => [...document.querySelectorAll('#escala .ct-floreado-e path')]
    .map(x => x.getAttribute('d')));
  ok(traco[0] === 'M148 98 C 90 100 36 84 20 36 C 12 14 34 2 46 20' &&
     traco[1] === 'M46 20 C 41 11 30 11 27 21',
     'o clássico é o traço do ficheiro de referência, ponto por ponto');

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
       `"${t}" fica na mesma caixa do clássico`);
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
    // A folha desenha o cartão em `transform:scale(--esc)`, por isso o que se
    // mede aqui vem encolhido. Desfaz-se a escala antes de comparar com o
    // desenho de origem, senão a verificação estaria a medir o zoom.
    const caixa = el.closest('.escala');
    const esc = caixa ? caixa.getBoundingClientRect().width / caixa.offsetWidth : 1;
    return { polig: !!el.querySelector('path[fill]:not([fill=none])'),
             esc:   Math.round(esc * 1000) / 1000,
             topo:  Math.round((r.top - n.top) / esc) };
  });
  ok(naFolha.polig, 'a folha de impressão traz o filete (que é o único com losango cheio)');
  ok(naFolha.esc > 0 && naFolha.esc < 1, `a folha encolhe o cartão (escala ${naFolha.esc})`);
  ok(Math.abs(naFolha.topo + 4) < 2, `e no sítio de origem (top ${naFolha.topo}, esperado -4)`);

  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  ok(/Filete/.test(await p.locator('.man-wrap').innerText()),
     'e o manual de impressão nomeia o feitio escolhido');

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  await p.evaluate(() => { selecionar('floreados'); zoom = .8; aplicarZoom(); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: OUT + '/floreados.png' });
  await api('defs_save', { defs: { 'cartao.floreado': 'classico' } });

  // Arrastar move a camada ESCOLHIDA, e não a que estiver por dentro dela. Os
  // nomes quase não têm orla que o floreado não cubra: sem esta regra,
  // escolher "Nomes" e pegar-lhes pela beira arrastava o floreado.
  for (const [escolha, esperada] of [['floreados', 'floreados'], ['nomes', 'nomes']]) {
    await api('defs_save', { defs: { 'cartao.posicoes': '' } });
    await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1300);
    await p.evaluate(c => selecionar(c), escolha);
    await p.waitForTimeout(250);
    const r = await (await p.$('#escala .ct-floreado-e')).boundingBox();
    const x = r.x + 8, y = r.y + r.height / 2;      // a orla do floreado
    await p.mouse.move(x, y); await p.mouse.down();
    await p.mouse.move(x + 50, y, { steps: 8 }); await p.mouse.up();
    await p.waitForTimeout(300);
    const movidas = await p.evaluate(() => Object.keys(est.pos));
    ok(movidas.length === 1 && movidas[0] === esperada,
       `com "${escolha}" escolhida, arrastar move "${esperada}" (moveu ${JSON.stringify(movidas)})`);
  }
  await api('defs_save', { defs: { 'cartao.posicoes': '' } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
