// A planta lê-se, e responde ao toque.
//
// Quatro coisas que a planta fazia mal, todas do mesmo lado — o de quem está a
// olhar para ela com a lista de convidados na mão:
//
//   1. A COR escolhida para a mesa não aparecia na mesa. Uma mesa sem ninguém
//      sentado ficava sem preenchimento nenhum, e a cor que se tinha escolhido
//      à mão via-se só no traço. Escolher terracota e ver marfim é o mesmo que
//      a escolha não ter sido guardada.
//   2. Os nomes dos convidados eram escritos em proporção do desenho: numa mesa
//      pequena, ou a 50% de zoom, ficavam por ler.
//   3. O nome da mesa era texto solto por cima do que estivesse atrás.
//   4. Para largar a mesa escolhida era preciso acertar no fundo do canvas — e
//      num salão cheio quase não há fundo por onde acertar. O gesto que abre
//      passa a ser o mesmo que fecha.
//
// E a legenda dizia «Vazia» sem dizer quantas.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'pl' + String(Date.now()).slice(-6);

  const p = await entrar(await b.newContext({ viewport: { width: 1400, height: 1000 } }),
                         'admin', 'noivos2026');
  p.on('pageerror', e => errs.push(e.message));
  const api = p._api;
  await api('casamento_abrir&id=1');

  // Uma mesa VAZIA, de uma cor escolhida à mão: é o caso em que a cor
  // desaparecia. E uma com gente, para haver pastilhas de nomes que medir.
  const nomeVazia = 'ZZ Vazia ' + marca, nomeCheia = 'ZZ Com gente ' + marca;
  let d = await api('mesa_save', { id:0, nome:nomeVazia, capacidade:10, forma:'redonda', cor:'terracota' });
  const vazia = (d.mesas || []).find(m => m.nome === nomeVazia);
  d = await api('mesa_save', { id:0, nome:nomeCheia, capacidade:8, forma:'oval', cor:'azul' });
  const cheia = (d.mesas || []).find(m => m.nome === nomeCheia);
  ok(vazia && cheia, 'criou as duas mesas de prova');
  const cv = ((await api('convite_list')).convites || [])[0];
  if (cv) await api('convite_mesa', { id: cv.id, mesa_id: cheia.id });

  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);

  // ---------- 1. a cor escolhida aparece na mesa ----------
  const pinta = (id) => p.evaluate((mid) => {
    const n = document.querySelector('.mesa-node[data-id="' + mid + '"]');
    if (!n) return null;
    const t = n.querySelector('.mi-t');
    return { fill: getComputedStyle(t).fill, stroke: getComputedStyle(t).stroke,
             escolhida: getComputedStyle(n).getPropertyValue('--mt-fundo').trim(),
             classes: n.querySelector('svg').getAttribute('class') };
  }, id);

  const cor = await pinta(vazia.id);
  ok(cor, 'a mesa está na planta');
  ok(/vazia/.test(cor.classes), 'e está mesmo sem ninguém — é o caso que falhava');
  ok(cor.fill !== 'none',
     `o tampo de uma mesa vazia tem preenchimento (era «none»): ${cor.fill}`);
  // #f5e2d9 = a terracota da paleta. Compara-se em rgb, que é como o CSS a devolve.
  ok(/245,\s*226,\s*217/.test(cor.fill),
     `e é a cor ESCOLHIDA, e não o marfim de origem (${cor.fill})`);
  ok(/181,\s*103,\s*63/.test(cor.stroke), 'o traço acompanha, na cor mais funda');

  // ---------- 2. os nomes lêem-se, e continuam a ler-se com o zoom em baixo ----------
  // O nome da mesa vive na camada de rótulos, por cima de todas as mesas; o de
  // quem se senta vive no painel do lado, que é de onde se arrasta.
  const letras = () => p.evaluate((mid) => {
    const nm = document.querySelector('#rotulos .mn-nome[data-id="' + mid + '"]');
    const mp = document.querySelector('.lista-sentados .nm-pega');
    return { mesa: nm ? parseFloat(getComputedStyle(nm).fontSize) : null,
             pessoa: mp ? parseFloat(getComputedStyle(mp).fontSize) : null,
             fundo: nm ? getComputedStyle(nm).backgroundColor : '' };
  }, mid = cheia.id);

  await p.evaluate((id) => selecionar(id), cheia.id);
  await p.waitForTimeout(500);
  let l = await letras();
  ok(l.mesa >= 12, `o nome da mesa tem tamanho de leitura (${l.mesa}px)`);
  ok(l.pessoa !== null && l.pessoa >= 12.5,
     `e o nome de quem se senta também (${l.pessoa}px)`);
  ok(!/rgba\(0,\s*0,\s*0,\s*0\)/.test(l.fundo),
     'o nome da mesa assenta num véu do fundo, e não solto sobre o que estiver atrás');

  await p.evaluate(() => setZoom(0.5));
  await p.waitForTimeout(400);
  l = await letras();
  ok(l.mesa >= 12, `a 50% de zoom continua a ler-se (${l.mesa}px) — o tamanho tem chão`);
  ok(l.pessoa >= 12.5, `e o dos convidados também (${l.pessoa}px)`);
  await p.evaluate(() => setZoom(1));
  await p.waitForTimeout(300);

  // ---------- 3. tocar outra vez na mesma mesa fecha-a ----------
  ok(await p.evaluate(() => SEL) !== null, 'a mesa está escolhida');
  await p.locator('.mesa-node[data-id="' + cheia.id + '"]').click({ position: { x: 8, y: 8 } });
  await p.waitForTimeout(600);
  ok(await p.evaluate(() => SEL) === null,
     'tocar outra vez na mesma mesa larga-a — sem ter de acertar no fundo do canvas');
  await p.locator('.mesa-node[data-id="' + cheia.id + '"]').click({ position: { x: 8, y: 8 } });
  await p.waitForTimeout(600);
  ok(await p.evaluate(() => SEL) === +cheia.id, 'e tocar de novo volta a abri-la');

  // ---------- 4. a legenda diz o que é, e quantas ----------
  const lg = await p.evaluate(() => [...document.querySelectorAll('#legenda .lg')].map(e => ({
    txt: e.textContent.replace(/\s+/g, ' ').trim(),
    n: +(e.querySelector('.n') || {}).textContent,
    ajuda: e.getAttribute('title') || '' })));
  ok(lg.length === 4, `a legenda tem os quatro estados (${lg.length})`);
  ok(lg.every(x => x.ajuda.includes(':')),
     'cada um explica por palavras o que quer dizer, e não só o nome');
  const somaLg = lg.reduce((s, x) => s + x.n, 0);
  const nMesas = await p.evaluate(() => MESAS.length);
  ok(somaLg === nMesas,
     `e conta as mesas de cada estado, sem deixar nenhuma de fora (${somaLg} de ${nMesas})`);
  const vaziaLg = lg.find(x => /Vazia/.test(x.txt));
  ok(vaziaLg && vaziaLg.n >= 1, 'a mesa vazia que se criou está contada nas vazias');

  // ---------- 5. a vista desloca-se nos dois eixos, e pode ser travada ----------
  // O canvas mostrava só o que coubesse: num monitor pequeno, ou com o canvas
  // arrastado para mais estreito, o salão era esmagado na largura que houvesse
  // e as mesas passavam umas por cima das outras. O mundo passa a ter um
  // mínimo e é a VISTA que se desloca — nos dois eixos, porque um salão é
  // largo e fundo.
  const vista = () => p.evaluate(() => {
    const v = document.getElementById('planta-viewport');
    return { overflow: getComputedStyle(v).overflow,
             podeH: v.scrollWidth  > v.clientWidth  + 1,
             podeV: v.scrollHeight > v.clientHeight + 1,
             x: v.scrollLeft, y: v.scrollTop };
  });
  // Encolhe-se o canvas de propósito: é o caso em que isto interessa, e é o que
  // acontece a quem tem pouco ecrã. Antes, encolher esmagava o salão.
  const canvasAntes = await api('mesa_list');
  await api('planta_size', { largura: 420, altura: 300 });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  let v = await vista();
  ok(v.overflow === 'auto', `por omissão a vista desloca-se (${v.overflow})`);
  ok(v.podeH, 'e há para onde ir na horizontal');
  ok(v.podeV, 'e na vertical também');

  await p.evaluate(() => { const el = document.getElementById('planta-viewport');
                           el.scrollLeft = 90; el.scrollTop = 60; });
  await p.waitForTimeout(250);
  v = await vista();
  ok(v.x >= 60 && v.y >= 40,
     `e desloca-se mesmo, com folga nos dois sentidos (${v.x}, ${v.y})`);

  await p.click('#bloq-scroll');
  await p.waitForTimeout(800);
  v = await vista();
  ok(v.overflow === 'hidden', 'travada a vista, a planta deixa de se deslocar');
  ok(await p.evaluate(() => document.body.classList.contains('bloq-scroll')),
     'e a página inteira sabe disso — a trava não vive só num estilo em linha');

  // A trava é do casal, e por isso fica guardada: recarregar não a perde.
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  ok(await p.evaluate(() => document.getElementById('bloq-scroll').checked),
     'a trava sobrevive a recarregar a página — ficou guardada');
  v = await vista();
  ok(v.overflow === 'hidden', 'e continua a valer');

  await p.click('#bloq-scroll');
  await p.waitForTimeout(800);
  v = await vista();
  ok(v.overflow === 'auto', 'destravar devolve o deslocamento');

  // ---------- 6. o salão estica-se para lá do primeiro ecrã ----------
  // Um casamento grande precisa de muitas mesas, e obrigá-las a caber no
  // quadrado que se vê era empilhá-las umas nas outras.
  const mundo = () => p.evaluate(() => {
    const w = document.getElementById('planta');
    return { ex: +getComputedStyle(w).getPropertyValue('--ex') || 1,
             largura: w.getBoundingClientRect().width };
  });
  const antesMundo = await mundo();
  await api('mesa_pos', { id: vazia.id, x: 210, y: 40 });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  const depoisMundo = await mundo();
  ok(depoisMundo.ex > antesMundo.ex,
     `uma mesa aos 210% estica o mundo (${antesMundo.ex} → ${depoisMundo.ex})`);
  ok(depoisMundo.largura > antesMundo.largura * 1.5,
     'e o mundo fica mesmo maior, para haver por onde lá chegar');
  const laLonge = await p.evaluate((id) => {
    const n = document.querySelector('.mesa-node[data-id="' + id + '"]');
    const w = document.getElementById('planta').getBoundingClientRect();
    const r = n.getBoundingClientRect();
    return Math.round((r.left + r.width / 2 - w.left) / w.width * 100);
  }, vazia.id);
  ok(Math.abs(laLonge - 210 / depoisMundo.ex) < 3,
     `e a mesa fica onde foi posta, no mundo esticado (${laLonge}% do mundo)`);

  // Com a vista TRAVADA, ninguém manda uma mesa para onde já não se consegue
  // chegar: o arrasto pára na borda do que está à vista.
  await api('mesa_pos', { id: vazia.id, x: 40, y: 40 });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  await p.click('#bloq-scroll'); await p.waitForTimeout(800);
  const cx0 = await p.evaluate((id) => {
    const r = document.querySelector('.mesa-node[data-id="' + id + '"]').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  }, vazia.id);
  await p.mouse.move(cx0.x, cx0.y);
  await p.mouse.down();
  await p.mouse.move(cx0.x + 2000, cx0.y + 40, { steps: 12 });
  await p.mouse.up();
  await p.waitForTimeout(900);
  const presa = await p.evaluate((id) => {
    const m = MESAS.find(x => x.id === +id); return m ? +m.pos_x : null;
  }, vazia.id);
  ok(presa !== null && presa <= 94.5,
     `com a vista travada, a mesa pára na borda do que se vê (${presa}%)`);
  await p.click('#bloq-scroll'); await p.waitForTimeout(800);
  await api('mesa_pos', { id: vazia.id, x: 40, y: 40 });

  // ---------- 7. arrasta-se do painel, não de cima da planta ----------
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  await p.evaluate((id) => selecionar(id), cheia.id);
  await p.waitForTimeout(600);
  ok(await p.evaluate(() => document.querySelectorAll('.mesa-membros').length) === 0,
     'já não há pastilhas de nomes por cima da planta a tapar as mesas vizinhas');
  const pega = await p.evaluate(() => {
    const el = document.querySelector('.lista-sentados .nm-pega');
    return el ? { tipo: el.dataset.tipo, temId: !!el.dataset.id, arrastavel: el.classList.contains('chip-drag'),
                  texto: el.textContent.trim() } : null;
  });
  ok(pega, 'o painel da mesa lista quem lá está sentado');
  ok(pega && pega.arrastavel && pega.tipo === 'pessoa' && pega.temId,
     'e cada nome é a pega do arrasto, com o que é preciso para o largar numa mesa');

  // ---------- limpeza ----------
  // Devolve-se o canvas ao tamanho que tinha, para as provas seguintes o
  // encontrarem como estava.
  // Vazio devolve o canvas ao automático, que é como ele estava.
  const cA = (canvasAntes.canvas || {});
  await api('planta_size', { largura: +cA.largura || '', altura: +cA.altura || '' });
  for (const m of [vazia, cheia]) await api('mesa_delete&id=' + m.id);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
