// Gerir mesas no telemóvel, sem arrastar.
//
// Arrastar num telemóvel é o pior dos gestos para isto: o dedo tapa justamente
// a mesa que se está a mexer, a precisão é a de uma almofada, e o MESMO gesto
// serve para deslocar a vista — o canvas e a mesa disputam-no.
//
// Sentar gente já tinha caminho sem arrasto (as listas de escolha ao lado de
// cada nome). O que faltava era pôr a mesa onde ela vai ficar. Ficam dois
// caminhos, e servem o rato tão bem como o dedo:
//
//   • as SETAS empurram a mesa um passo de cada vez — é a única forma de
//     acertar ao pixel, e num ecrã pequeno é a única que acerta de todo;
//   • «PÔR AQUI» espera pelo toque seguinte na planta e põe lá a mesa: dois
//     toques em vez de um arrasto, e o segundo é onde se está a olhar.
//
// E uma terceira coisa, que é o que se via primeiro ao abrir a página: a
// PLANTA não cabia no primeiro ecrã. Os números de cima (240px) e o formulário
// de criar mesa (234px) empurravam-na para y=982 num ecrã de 844. Numa página
// chamada «Planta de Mesas», a planta era a única coisa que não se via.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

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

  // Uma mesa só desta prova. A do salão de exemplo é a dos NOIVOS, que tem
  // painel próprio — e foi lá que esta prova tropeçou à primeira.
  const nova = await p.evaluate(async () => {
    const r = await fetch('api.php?action=mesa_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: 'ZZ Mexer', capacidade: 8, forma: 'redonda', cor: 'neutra' }) });
    return r.json();
  });
  ok(nova && nova.success, 'criou a mesa de prova');
  const id = nova.id;

  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2200);

  // ---- 1. a planta vê-se ao chegar ----
  const layout = await p.evaluate(() => {
    const vp = document.querySelector('.planta-viewport');
    const d = document.getElementById('barra-add-dobra');
    return { plantaY: Math.round(vp.getBoundingClientRect().top + scrollY),
             ecra: innerHeight,
             formAberto: d ? d.open : null,
             sumario: d ? getComputedStyle(d.querySelector('.barra-add-sum')).display : null,
             transbordo: document.documentElement.scrollWidth - innerWidth };
  });
  ok(layout.plantaY < layout.ecra,
     'numa página chamada «Planta de Mesas», a planta vê-se ao chegar: y='
     + layout.plantaY + ' num ecrã de ' + layout.ecra + ' (era 982)');
  ok(layout.formAberto === false && layout.sumario === 'flex',
     'e o formulário de criar mesa dobra-se — cria-se meia dúzia de vezes, '
     + 'olha-se para o salão sempre');
  ok(layout.transbordo === 0, 'sem rolagem horizontal');

  // ---- 2. as setas ----
  await p.evaluate(i => selecionar(i), id);
  await p.waitForTimeout(800);
  const ctrl = await p.evaluate(() => {
    const g = document.querySelector('.grp.mover');
    if (!g) return null;
    const bts = [...g.querySelectorAll('button')];
    return { botoes: bts.length,
             pequenos: bts.filter(x => { const r = x.getBoundingClientRect();
                                         return r.width < 40 || r.height < 44; }).length,
             temPorAqui: !!document.getElementById('bt-por-aqui') };
  });
  ok(!!ctrl && ctrl.botoes === 5,
     'a mesa escolhida traz quatro setas e o «pôr aqui»: ' + (ctrl ? ctrl.botoes : 0));
  ok(!!ctrl && ctrl.pequenos === 0,
     'com alvos que um polegar acerta (44px), e não os 28px de quem tem rato');

  const antes = await p.evaluate(i => { const m = MESAS.find(x => x.id === i);
                                        return { x: +m.pos_x, y: +m.pos_y }; }, id);
  await p.evaluate(() => empurrarMesa(1, 0));
  await p.waitForTimeout(700);
  const dirEsq = await p.evaluate(i => { const m = MESAS.find(x => x.id === i);
                                         return { x: +m.pos_x, y: +m.pos_y }; }, id);
  ok(dirEsq.x > antes.x && dirEsq.y === antes.y,
     'a seta da direita empurra a mesa para a direita, e só para a direita: '
     + antes.x.toFixed(1) + ' → ' + dirEsq.x.toFixed(1));
  await p.evaluate(() => empurrarMesa(0, 1));
  await p.waitForTimeout(700);
  const baixo = await p.evaluate(i => { const m = MESAS.find(x => x.id === i);
                                        return { x: +m.pos_x, y: +m.pos_y }; }, id);
  ok(baixo.y > dirEsq.y, 'e a de baixo para baixo: ' + dirEsq.y.toFixed(1) + ' → ' + baixo.y.toFixed(1));

  // O que se empurra fica guardado: um passo que não sobrevive a um recarregar
  // não é um passo, é um engano.
  const guardado = await p.evaluate(async i => {
    const r = await fetch('api.php?action=mesa_list');
    const d = await r.json();
    const m = (d.mesas || []).find(x => +x.id === i);
    return m ? { x: +(+m.pos_x).toFixed(2), y: +(+m.pos_y).toFixed(2) } : null;
  }, id);
  ok(!!guardado && Math.abs(guardado.x - baixo.x) < 0.02 && Math.abs(guardado.y - baixo.y) < 0.02,
     'e o servidor guarda onde ela ficou: ' + JSON.stringify(guardado));

  // ---- 3. pôr aqui ----
  await p.evaluate(() => document.querySelector('.planta-viewport')
                                 .scrollIntoView({ block: 'center' }));
  await p.waitForTimeout(500);
  await p.evaluate(() => porAqui());
  await p.waitForTimeout(300);
  const modo = await p.evaluate(() => ({
    corpo: document.body.classList.contains('a-por-mesa'),
    rotulo: (document.getElementById('bt-por-aqui') || {}).textContent,
    aceso: (document.getElementById('bt-por-aqui') || { classList: { contains: () => false } })
             .classList.contains('on'),
  }));
  ok(modo.corpo && modo.aceso,
     'o «pôr aqui» acende enquanto espera — um modo que não se vê é um modo que '
     + 'se esquece, e o toque seguinte faria outra coisa');
  ok(/toque/i.test(modo.rotulo || ''), 'e diz o que está à espera: «' + modo.rotulo + '»');

  const alvo = await p.evaluate(() => {
    const vp = document.querySelector('.planta-viewport').getBoundingClientRect();
    return { x: Math.round(vp.left + vp.width * 0.62), y: Math.round(vp.top + vp.height * 0.38) };
  });
  await p.mouse.click(alvo.x, alvo.y);
  await p.waitForTimeout(1000);
  const posto = await p.evaluate(i => ({
    m: (() => { const m = MESAS.find(x => x.id === i); return { x: +m.pos_x, y: +m.pos_y }; })(),
    modo: document.body.classList.contains('a-por-mesa'),
  }), id);
  ok(Math.abs(posto.m.x - baixo.x) > 1 || Math.abs(posto.m.y - baixo.y) > 1,
     'o toque na planta põe lá a mesa: ' + baixo.x.toFixed(1) + ',' + baixo.y.toFixed(1)
     + ' → ' + posto.m.x.toFixed(1) + ',' + posto.m.y.toFixed(1));
  ok(!posto.modo, 'e o modo sai sozinho depois de servir — não fica à espera de mais toques');

  // ---- o disco de cor é um círculo, mesmo no toque ----
  // Os alvos de dedo sobem a 44px de altura; o disco de cor é a excepção, senão
  // o min-height esticava-o para uma elipse no telemóvel.
  const disco = await p.evaluate(() => {
    const d = document.getElementById('barra-add-dobra'); if (d) d.open = true;
    const b = document.querySelector('.cores button');
    if (!b) return null;
    const r = b.getBoundingClientRect();
    return { w: +r.width.toFixed(1), h: +r.height.toFixed(1) };
  });
  ok(disco && Math.abs(disco.w - disco.h) < 1,
     'o disco de cor da mesa é um círculo perfeito no telemóvel: ' + JSON.stringify(disco));

  // ---- o selector de mesa de cada convidado abre no telemóvel ----
  // Focar a pesquisa rolava o painel, e esse rolar fechava a lista no mesmo
  // toque — parecia que o selector não respondia.
  const comboMob = await p.evaluate(async () => {
    const ids = (await (await fetch('api.php?action=mesa_list')).json()).mesas.map(m => m.id);
    for (const mid of ids) {
      irAMesa(mid);
      await new Promise(r => setTimeout(r, 250));
      const combo = document.querySelector('#tab-body .combo[data-kind="mesa-pessoa"]');
      if (combo) {
        combo.querySelector('.combo-btn').click();
        await new Promise(r => setTimeout(r, 400));
        const pop = combo.querySelector('.combo-pop');
        return { achou: true, aberta: !pop.hidden, nOpts: pop.querySelectorAll('.combo-opt').length };
      }
    }
    return { achou: false };
  });
  if (comboMob.achou) {
    ok(comboMob.aberta && comboMob.nOpts > 0,
       'o selector de mesa do convidado abre e fica aberto no telemóvel: ' + JSON.stringify(comboMob));
  } else {
    console.log('  (este casamento não tem mesa com pessoas para provar o selector — salta)');
  }

  // ---- arrumar ----
  await p.evaluate(async i => {
    await fetch('api.php?action=mesa_delete&id=' + i,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, id);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
