// Carregar numa mesa da LISTA é o mesmo que tocar-lhe no canvas.
//
// Não era. Eram três coisas a faltar, e a terceira estragava a segunda:
//
//   1. A MESA NÃO ABRIA. Ficava marcada, e a aba continuava na lista. O painel
//      da mesa é onde vivem as setas e o «pôr aqui» — escolher pela lista
//      deixava essas ferramentas fechadas, sem nada a dizer que existiam.
//
//   2. NÃO FICAVA AO CENTRO. Media-se o desvio até 93px, e uma das mesas ficava
//      mesmo fora da área visível. A conta era feita com a posição GUARDADA da
//      mesa; o que se quer ao meio da vista é o DESENHO dela, que tem tamanho
//      e está onde o browser o pôs.
//
//   3. O CANVAS ESTAVA FORA DO ECRÃ. No telemóvel começa abaixo da dobra
//      (y=741 num ecrã de 844). Centrar uma mesa dentro de uma caixa que quase
//      não se vê é acertar num sítio que ninguém está a olhar.
//
// E o «pôr aqui» tinha um defeito que só se vê no telemóvel, e que vem
// justamente do ponto 3: com o modo ligado, QUALQUER toque na planta valia como
// destino — incluindo o toque com que se começa a rolar a página para chegar
// lá. Ligava-se o modo, arrastava-se para ver a planta, e a mesa saltava para
// onde o dedo tinha pousado. Agora a planta vem à vista sozinha, e um arrasto
// já não é confundido com uma escolha de sítio.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzl' + Math.floor(Math.random() * 1e5);

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  // Mesas espalhadas pelo salão — incluindo longe do meio, que é onde a
  // centragem custa.
  const ids = await p.evaluate(async ({ m }) => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const sitios = [[30, 30], [70, 35], [40, 70], [65, 65]];
    const out = [];
    for (let i = 0; i < sitios.length; i++) {
      const d = await post('mesa_save', { nome: m + ' ' + i, capacidade: 8,
                                          forma: 'redonda', cor: 'neutra' });
      const nova = (d.mesas || []).filter(x => x.nome === m + ' ' + i).pop();
      if (nova) {
        await post('mesa_pos', { id: +nova.id, x: sitios[i][0], y: sitios[i][1] });
        out.push(+nova.id);
      }
    }
    return out;
  }, { m: 'ZZL ' + marca });
  ok(ids.length === 4, 'quatro mesas de prova, espalhadas pelo salão: ' + ids.length);

  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2400);

  // ---- 1. a lista abre a mesa, e o canvas vem ao ecrã ----
  await p.evaluate(() => window.scrollTo(0, 0));
  await p.waitForTimeout(300);
  const chegada = await p.evaluate(() => ({
    y: Math.round(document.getElementById('planta-viewport').getBoundingClientRect().top),
    ecra: innerHeight }));
  // Isto não é uma exigência: é o RETRATO do problema. Se um dia a planta
  // passar a nascer à vista, esta prova deixa de ter razão de ser — e é bom
  // que se saiba, em vez de continuar a medir um mundo que já mudou.
  ok(chegada.y > chegada.ecra * 0.5,
     'ao chegar, a planta está abaixo da dobra — é daqui que vem tudo o resto: y='
     + chegada.y + ' num ecrã de ' + chegada.ecra);

  const r1 = await p.evaluate(async id => {
    irAMesa(id);
    await new Promise(r => setTimeout(r, 1500));
    const vp = document.getElementById('planta-viewport');
    const no = document.querySelector('.mesa-node[data-id="' + id + '"]');
    const rv = vp.getBoundingClientRect(), rn = no.getBoundingClientRect();
    return { aba: activeTab, sel: SEL,
             canvasY: Math.round(rv.top), ecra: innerHeight,
             dx: Math.round((rn.left + rn.width / 2) - (rv.left + rv.width / 2)),
             dy: Math.round((rn.top + rn.height / 2) - (rv.top + rv.height / 2)),
             naVista: rn.left >= rv.left - 1 && rn.right <= rv.right + 1
                   && rn.top >= rv.top - 1 && rn.bottom <= rv.bottom + 1 };
  }, ids[0]);

  ok(r1.sel === ids[0], 'carregar na lista escolhe a mesa');
  ok(r1.aba === 'mesa',
     'e ABRE-A, como se lhe tivessem tocado no canvas — é lá dentro que estão '
     + 'as setas e o «pôr aqui»: aba=' + r1.aba);
  ok(r1.canvasY < r1.ecra * 0.6,
     'o canvas sobe ao ecrã em vez de ficar abaixo da dobra: y=' + r1.canvasY
     + ' num ecrã de ' + r1.ecra);
  ok(r1.naVista, 'e a mesa fica dentro da área visível do canvas');
  ok(Math.abs(r1.dx) <= 2 && Math.abs(r1.dy) <= 2,
     'ao centro, e não «lá para o meio»: desvio de ' + r1.dx + ',' + r1.dy + 'px (era 93,57)');

  // ---- 2. e vale para todas, não só para a primeira ----
  let piorX = 0, piorY = 0, forasDaVista = 0;
  for (const id of ids) {
    const r = await p.evaluate(async i => {
      window.scrollTo(0, 0);
      irAMesa(i);
      await new Promise(r => setTimeout(r, 1400));
      const vp = document.getElementById('planta-viewport');
      const no = document.querySelector('.mesa-node[data-id="' + i + '"]');
      const rv = vp.getBoundingClientRect(), rn = no.getBoundingClientRect();
      return { dx: Math.round((rn.left + rn.width / 2) - (rv.left + rv.width / 2)),
               dy: Math.round((rn.top + rn.height / 2) - (rv.top + rv.height / 2)),
               naVista: rn.left >= rv.left - 1 && rn.right <= rv.right + 1
                     && rn.top >= rv.top - 1 && rn.bottom <= rv.bottom + 1 };
    }, id);
    piorX = Math.max(piorX, Math.abs(r.dx));
    piorY = Math.max(piorY, Math.abs(r.dy));
    if (!r.naVista) forasDaVista++;
  }
  ok(forasDaVista === 0,
     'nenhuma das quatro fica fora da vista: ' + forasDaVista);
  ok(piorX <= 2 && piorY <= 2,
     'e todas ao centro, com o pior desvio em ' + piorX + ',' + piorY + 'px');

  // ---- 3. o «pôr aqui» traz a planta à vista ----
  // O ciclo acima deixou escolhida a ÚLTIMA mesa, e o «pôr aqui» serve a que
  // está escolhida. Volta-se à primeira, que é a que daqui para baixo se mede.
  await p.evaluate(async i => { irAMesa(i); await new Promise(r => setTimeout(r, 1200)); }, ids[0]);
  await p.evaluate(() => window.scrollTo(0, 0));
  await p.waitForTimeout(400);
  const longe = await p.evaluate(() =>
    Math.round(document.getElementById('planta-viewport').getBoundingClientRect().top));
  const depoisDoModo = await p.evaluate(async () => {
    porAqui();
    await new Promise(r => setTimeout(r, 900));
    return { y: Math.round(document.getElementById('planta-viewport').getBoundingClientRect().top),
             modo: document.body.classList.contains('a-por-mesa') };
  });
  ok(depoisDoModo.modo, 'o modo «pôr aqui» liga-se');
  ok(depoisDoModo.y < longe,
     'e traz a planta à vista sozinho — senão era preciso rolar até lá, e o dedo '
     + 'que rola toca na planta: ' + longe + ' → ' + depoisDoModo.y);

  // ---- 4. um ARRASTO não é uma escolha de sítio ----
  const pos0 = await p.evaluate(i => { const m = MESAS.find(x => x.id === i);
                                       return { x: +m.pos_x, y: +m.pos_y }; }, ids[0]);
  const ponto = await p.evaluate(() => {
    const vp = document.querySelector('.planta-viewport').getBoundingClientRect();
    return { x: Math.round(vp.left + vp.width * 0.5), y: Math.round(vp.top + vp.height * 0.5) };
  });
  // arrasta-se bem para lá do limiar: é uma rolagem, não um toque
  await p.mouse.move(ponto.x, ponto.y);
  await p.mouse.down();
  await p.mouse.move(ponto.x + 80, ponto.y + 60, { steps: 8 });
  await p.mouse.up();
  await p.waitForTimeout(900);
  const depoisArrasto = await p.evaluate(i => ({
    pos: (() => { const m = MESAS.find(x => x.id === i); return { x: +m.pos_x, y: +m.pos_y }; })(),
    modo: document.body.classList.contains('a-por-mesa') }), ids[0]);
  ok(Math.abs(depoisArrasto.pos.x - pos0.x) < 0.01 && Math.abs(depoisArrasto.pos.y - pos0.y) < 0.01,
     'arrastar NÃO põe a mesa — era assim que ela saltava para onde a rolagem '
     + 'começava: ' + JSON.stringify(depoisArrasto.pos));
  ok(depoisArrasto.modo, 'e o modo continua à espera do toque que interessa');

  // ---- 5. um toque põe-na, e ao pixel ----
  const alvo = await p.evaluate(() => {
    const vp = document.querySelector('.planta-viewport').getBoundingClientRect();
    return { x: Math.round(vp.left + vp.width * 0.62), y: Math.round(vp.top + vp.height * 0.42) };
  });
  await p.touchscreen.tap(alvo.x, alvo.y);
  await p.waitForTimeout(1000);
  const posto = await p.evaluate(({ i, a }) => {
    const no = document.querySelector('.mesa-node[data-id="' + i + '"]');
    const r = no.getBoundingClientRect();
    return { dx: Math.round(r.left + r.width / 2 - a.x), dy: Math.round(r.top + r.height / 2 - a.y),
             modo: document.body.classList.contains('a-por-mesa') };
  }, { i: ids[0], a: alvo });
  ok(Math.abs(posto.dx) <= 2 && Math.abs(posto.dy) <= 2,
     'um toque põe a mesa onde o dedo tocou: ' + posto.dx + ',' + posto.dy + 'px');
  ok(!posto.modo, 'e o modo sai depois de servir');

  // ---- arrumar ----
  await p.evaluate(async is => {
    for (const i of is) {
      await fetch('api.php?action=mesa_delete&id=' + i,
        { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => {});
    }
  }, ids);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
