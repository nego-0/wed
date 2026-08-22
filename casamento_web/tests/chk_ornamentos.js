// As volutas dos cantos e o elo entre os nomes: escolhem-se no editor,
// gravam-se, e saem impressos.
//
// A regra que se verifica aqui e que não é óbvia: cada voluta é desenhada uma
// vez e espelhada pela diagonal do canto, com matrix(0 1 1 0 0 0). Um desenho
// que não seja simétrico em relação a essa diagonal aparece DUAS vezes e
// cruzado consigo próprio — foi o que aconteceu ao "leque" e ao remate do
// "arco". Como não dá erro nenhum, só se apanha medindo.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  p.on('dialog', d => d.accept(d.type() === 'prompt' ? 'Prova' : undefined)); // «Guardar Como» pede nome
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const defs = d => p.evaluate(async d => {
    const r = await fetch('api.php?action=defs_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ defs: d }) });
    return r.json();
  }, d);

  // O estado de partida, para a prova poder correr as vezes que forem.
  const ORIGEM = { 'cartao.voluta': 'caracol', 'cartao.elo': 'coracao' };
  await defs(ORIGEM);

  // ---- as volutas espelham-se sem se atravessarem ----
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);

  await p.evaluate(() => selecionar('volutas')); await p.waitForTimeout(400);
  const selVol = '#props select[onchange*="mudarVoluta"]';
  ok(await p.locator(selVol).count() === 1, 'a camada das volutas oferece um feitio à escolha');
  const feitiosVol = await p.locator(selVol + ' option').evaluateAll(o => o.map(x => x.value));
  ok(feitiosVol.length === 5, `há cinco volutas à escolha (${feitiosVol.join(', ')})`);
  ok(feitiosVol.includes('caracol'), 'com o caracol de origem entre elas');

  // Cada voluta é desenhada uma vez e espelhada. O que se pode medir daqui é
  // a estrutura — que o espelho existe e leva o mesmo desenho — e a caixa em
  // que o par cabe. Não se exige simetria em relação à diagonal: o caracol de
  // origem e a folha são traços abertos, feitos DE PROPÓSITO para formarem um
  // par espelhado. O que estragava o leque era outra coisa (curvas de tamanhos
  // diferentes a cruzarem-se), e isso vê-se no contacto, não numa medida.
  const traços = {};
  for (const v of feitiosVol) {
    await p.selectOption(selVol, v); await p.waitForTimeout(400);
    const m = await p.evaluate(() => {
      const svg = document.querySelector('#escala .ct-voluta-se svg');
      if (!svg) return null;
      const g = svg.querySelector('g[transform]');
      if (!g) return { semEspelho: true };
      const r = g.getBBox();
      // Peças fora do espelho: o desenho, mais o losango do canto quando o
      // feitio o tem. Só interessa comparar as do desenho.
      const conta = n => n.querySelectorAll('path,circle').length;
      return { proprios: [...svg.children].filter(n => n !== g && n.tagName !== 'g').length,
               noEspelho: conta(g),
               caixa: [r.x, r.y, r.width, r.height].map(n => +n.toFixed(1)),
               d: [...svg.querySelectorAll('path')].map(x => x.getAttribute('d')).join('|') };
    });
    if (!m || m.semEspelho) { ok(false, `"${v}": não se encontrou o espelho`); continue; }
    traços[v] = m.d;
    const [x, y, w, h] = m.caixa;
    console.log(`  ${v.padEnd(10)} caixa=[${x},${y} ${w}×${h}]  peças=${m.noEspelho}`);
    ok(m.noEspelho > 0, `"${v}": o espelho leva mesmo o desenho (${m.noEspelho} peças)`);
    // A voluta é desenhada numa caixa de 150×150 ancorada no canto. Sair dela
    // punha o ornamento por cima do texto ou fora do cartão.
    ok(x >= -2 && y >= -2 && x + w <= 152 && y + h <= 152,
       `"${v}": o desenho fica dentro da caixa do canto ([${x},${y} ${w}×${h}])`);
  }
  ok(new Set(Object.values(traços)).size === feitiosVol.length,
     `as cinco volutas são desenhos diferentes (${new Set(Object.values(traços)).size} distintos)`);

  // ---- o elo entre os nomes ----
  await p.evaluate(() => selecionar('nomes')); await p.waitForTimeout(400);
  const selElo = '#props select[onchange*="mudarElo"]';
  ok(await p.locator(selElo).count() === 1, 'a camada dos nomes oferece o elo à escolha');
  const elos = await p.locator(selElo + ' option').evaluateAll(o => o.map(x => x.value));
  ok(elos.length === 6, `há seis elos à escolha (${elos.join(', ')})`);
  ok(elos.includes('comercial'), 'com o "&" entre eles');

  for (const e of elos) {
    await p.selectOption(selElo, e); await p.waitForTimeout(400);
    const r = await p.evaluate(() => {
      const n = document.querySelector('#escala .ct-nomes');
      const el = n.querySelector('.ct-coracao');
      if (!el) return { existe: false };
      const nb = n.getBoundingClientRect(), rb = el.getBoundingClientRect();
      return { existe: true, texto: el.textContent.trim(),
               // De quanto o centro do elo se afasta do centro dos nomes.
               fora: Math.round(Math.abs((rb.left + rb.right) / 2 - (nb.left + nb.right) / 2)),
               larg: Math.round(rb.width) };
    });
    if (e === 'nada') { ok(!r.existe, '"nada" não deixa sequer o espaço do elo'); continue; }
    ok(r.existe, `"${e}" desenha alguma coisa entre os nomes`);
    // O filete não é texto: sem margem lateral `auto` encostava à esquerda, e
    // era isso que se via — nada, porque ficava debaixo do floreado.
    ok(r.fora <= 2, `"${e}" fica centrado entre os nomes (fora por ${r.fora}px)`);
  }

  // ---- grava-se e sai impresso ----
  await p.selectOption(selElo, 'comercial'); await p.waitForTimeout(300);
  await p.evaluate(() => selecionar('volutas')); await p.waitForTimeout(400);
  await p.selectOption(selVol, 'leque'); await p.waitForTimeout(300);
  ok(await p.evaluate(() => guardar()), 'as duas escolhas gravam-se');

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1300);
  ok(await p.evaluate(() => est.voluta) === 'leque', 'e a voluta volta ao reabrir o editor');
  ok(await p.evaluate(() => est.elo)    === 'comercial', 'e o elo também');

  for (const [url, nome] of [['/cartoes.php', 'a folha de cartões'],
                             ['/manual.php?peca=cartao', 'o manual']]) {
    await p.goto(BASE + url, { waitUntil: 'networkidle' }); await p.waitForTimeout(700);
    const st = await p.evaluate(() => {
      const c = document.querySelector('.cartao'); if (!c) return null;
      const v = c.querySelector('.ct-voluta svg');
      // Só os do desenho: `svg > path` deixa de fora as cópias que vivem
      // dentro do <g> do espelho, senão contavam-se seis.
      return { arcos: v ? v.querySelectorAll(':scope > path[d*="A "]').length : -1,
               elo:   (c.querySelector('.ct-elo-amp') || {}).textContent };
    });
    console.log(`  ${nome}:`, JSON.stringify(st));
    ok(st && st.arcos === 3, nome + ' traz o leque com os seus três arcos');
    ok(st && st.elo === '&', nome + ' traz o "&" entre os nomes');
  }
  ok(await p.evaluate(async () => {
    const r = await fetch('manual.php?peca=cartao'); const t = await r.text();
    return /Entre os nomes/.test(t) && /Volutas/.test(t);
  }), 'o manual de impressão nomeia as duas escolhas');

  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await defs(ORIGEM);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.join(' | '));
  await b.close();
  if (f) { console.log(`\n${f} verificação(ões) falharam`); process.exit(1); }
  console.log('\nTudo certo.');
})().catch(e => { console.error(e); process.exit(1); });
