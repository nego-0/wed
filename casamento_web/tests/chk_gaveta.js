// A gaveta lateral, no telemóvel — e a vista principal sem nada por cima.
//
// Esteve aqui, durante um tempo, uma barra fixa em baixo com quatro destinos e
// uma folha para o resto. Resolvia o problema de os encontrar e criava outro:
// era a TERCEIRA coisa a flutuar por cima da página, depois do cabeçalho fixo
// e da pastilha do tema — e tapava-lhe 39 dos 44px, com z-index 90 contra 75.
// Cinco pixéis de botão é o mesmo que botão nenhum.
//
// O que aqui se defende:
//
//   1. Com a gaveta fechada, a vista principal NÃO TEM NADA POR CIMA a não ser
//      o cabeçalho. É o ponto todo: um toque em qualquer sítio da página chega
//      à página.
//   2. A escolha do tema mora na gaveta, com espaço, nome e as cores à vista —
//      e não a pairar num canto que outra coisa qualquer acaba por tapar.
//   3. Os doze destinos aparecem pelo NOME INTEIRO. Numa barra de 390px cada
//      coluna tinha 78px e «Convite digital» cortava-se a meio; aqui há largura.
//   4. A gaveta sai por onde se espera: pelo fundo escurecido, pelo Escape,
//      pelo × e ao escolher. O foco entra nela, fica lá preso enquanto estiver
//      aberta — um diálogo modal que deixa tabular para a página de baixo não
//      é modal nenhum — e volta ao puxador quando ela se fecha.
//   5. O cabeçalho cabe. Media 253px num ecrã de 844 (quase um terço, antes de
//      se ver o que quer que fosse) e variava com o tema sem ninguém ter
//      escolhido isso.
//   6. No ecrã largo nada disto existe: a tira do cabeçalho cabe e chega.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const entrar = async (pg) => {
    await pg.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await pg.fill('input[name=utilizador]', 'admin');
    await pg.fill('input[name=senha]', 'noivos2026');
    await pg.click('button[type=submit]');
    await pg.waitForLoadState('networkidle');
    await pg.evaluate(async () => {
      await fetch('api.php?action=casamento_abrir&id=1',
        { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
    }).catch(() => {});
  };

  // ============ no telemóvel ============
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  m.on('pageerror', e => errs.push('movel: ' + e.message));
  await entrar(m);
  await m.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(1600);

  // ---- 1. nada por cima da página ----
  // Prova-se por TOQUE, e não por leitura do CSS: o que interessa é onde o
  // dedo chega. Varre-se uma grelha de pontos e pergunta-se, em cada um, quem
  // está por cima. Foi a tocar que se encontrou o fundo escurecido da folha
  // antiga, que estava invisível e engolia o ecrã inteiro.
  const tapado = await m.evaluate(() => {
    const maus = [];
    for (let y = 0.25; y <= 0.95; y += 0.1) {
      for (let x = 0.2; x <= 0.8; x += 0.3) {
        const e = document.elementFromPoint(Math.round(innerWidth * x), Math.round(innerHeight * y));
        if (!e) { maus.push('nada em ' + x + ',' + y); continue; }
        const flutua = e.closest('.gaveta, .gaveta-fundo, .tema-fab, .nav-baixo, .folha-mais, .folha-fundo');
        if (flutua) maus.push((flutua.className || flutua.tagName) + ' em ' + x.toFixed(1) + ',' + y.toFixed(1));
      }
    }
    return maus;
  });
  ok(tapado.length === 0,
     'com a gaveta fechada, nada flutua por cima da página'
     + (tapado.length ? ': ' + tapado.slice(0, 3).join(' | ') : ''));
  ok(await m.evaluate(() => !document.querySelector('.nav-baixo')),
     'e a barra de baixo saiu de cena — era ela que tapava o botão do tema');

  // ---- 2. o cabeçalho cabe ----
  const cab = await m.evaluate(() => {
    const t = document.querySelector('.topo');
    const bt = document.querySelector('.gaveta-bt');
    const r = bt ? bt.getBoundingClientRect() : null;
    return { alt: Math.round(t.getBoundingClientRect().height),
             topoAlt: getComputedStyle(document.documentElement).getPropertyValue('--topo-alt').trim(),
             fundo: Math.round(t.getBoundingClientRect().bottom),
             botao: r ? { w: Math.round(r.width), h: Math.round(r.height),
                          dentro: r.right <= innerWidth + 1 } : null,
             transbordo: document.documentElement.scrollWidth - innerWidth };
  });
  ok(cab.alt <= 150, 'o cabeçalho cabe num punhado de pixéis: ' + cab.alt + 'px (eram 253)');
  ok(cab.topoAlt === cab.fundo + 'px',
     'e o corpo guarda-lhe o lugar CERTO — medido depois de as fontes chegarem, '
     + 'e não antes: ' + cab.topoAlt + ' para ' + cab.fundo + 'px');
  ok(!!cab.botao && cab.botao.w >= 44 && cab.botao.h >= 44 && cab.botao.dentro,
     'o puxador tem 44px e está dentro do ecrã');
  ok(cab.transbordo === 0, 'e a página não ganha rolagem horizontal');

  // A contagem é o número que se vem cá ver: quando a linha aperta, é o nome
  // do casal que encolhe, nunca ela.
  const linha = await m.evaluate(() => {
    const cg = document.querySelector('.topo-casal .contagem');
    const nome = document.querySelector('.topo-casal .tc-nome');
    if (!cg || !nome) return null;
    const r = cg.getBoundingClientRect();
    return { contagem: cg.textContent.replace(/\s+/g, ' ').trim(),
             inteira: r.width > 0 && r.right <= innerWidth + 1,
             alt: Math.round(document.querySelector('.topo-casal').getBoundingClientRect().height),
             nomeCortado: nome.scrollWidth > nome.clientWidth + 1 };
  });
  ok(!!linha && linha.inteira && /dias|HOJE|dia/.test(linha.contagem),
     'a contagem fica sempre inteira: «' + (linha ? linha.contagem : '') + '»');
  ok(!!linha && linha.alt <= 24,
     'e a linha do casal não dobra — dobrar custava 18px de cabeçalho em todas '
     + 'as páginas: ' + (linha ? linha.alt : '?') + 'px');

  // A frase de apoio corta-se a uma linha, mas o que se corta fica à mão.
  const apoio = await m.evaluate(() => {
    const s = document.querySelector('.topo .sub:not(.topo-casal):not(.licenca-restante)');
    if (!s) return null;
    return { umaLinha: Math.round(s.getBoundingClientRect().height) <= 24,
             titulo: (s.getAttribute('title') || '').length > 0,
             igual: (s.getAttribute('title') || '') === s.textContent.trim() };
  });
  ok(!!apoio && apoio.umaLinha, 'a frase de apoio fica-se por uma linha');
  ok(!!apoio && apoio.titulo && apoio.igual,
     'e o texto inteiro fica no título, para quem o quiser ler');

  // ---- 3. a gaveta ----
  await m.click('#gaveta-bt');
  await m.waitForTimeout(450);
  const aberta = await m.evaluate(() => {
    const g = document.getElementById('gaveta');
    const links = [...g.querySelectorAll('.gv-lista a')];
    const r = g.getBoundingClientRect();
    return { visivel: !g.hidden && g.classList.contains('aberta'),
             dentro: r.right <= innerWidth + 1 && r.left >= 0,
             destinos: links.length,
             cortados: links.filter(a => a.scrollWidth > a.clientWidth + 1)
                            .map(a => a.textContent.trim()),
             pequenos: links.filter(a => a.getBoundingClientRect().height < 44).length,
             temas: g.querySelectorAll('[data-gv-tema]').length,
             aceso: [...g.querySelectorAll('[data-gv-tema].on')].map(e => e.dataset.gvTema),
             expandido: document.getElementById('gaveta-bt').getAttribute('aria-expanded'),
             focoDentro: !!document.activeElement.closest('#gaveta'),
             corpoPreso: getComputedStyle(document.body).overflow === 'hidden' };
  });
  ok(aberta.visivel && aberta.dentro, 'o puxador abre a gaveta, e ela entra toda no ecrã');
  ok(aberta.expandido === 'true', 'e diz que está aberta (aria-expanded)');
  ok(aberta.destinos >= 10,
     'com os destinos todos lá dentro, e não quatro: ' + aberta.destinos);
  ok(aberta.cortados.length === 0,
     'pelo nome inteiro — numa barra de 78px «Convite digital» cortava-se a meio'
     + (aberta.cortados.length ? ': ' + aberta.cortados.join(', ') : ''));
  ok(aberta.pequenos === 0, 'e todos com 44px, que é o mínimo com que um dedo acerta');
  ok(aberta.focoDentro, 'o foco entra na gaveta');
  ok(aberta.corpoPreso,
     'e a página de baixo deixa de rolar — rolar o que está atrás de um painel '
     + 'modal é mexer no que não se está a ver');

  // ---- 4. o tema mora aqui ----
  ok(aberta.temas === 4, 'a escolha do tema está na gaveta: ' + aberta.temas + ' temas');
  ok(aberta.aceso.length === 1, 'com um — e só um — aceso: ' + aberta.aceso.join(', '));
  const antes = await m.evaluate(() => document.documentElement.getAttribute('data-tema'));
  const outro = ['niras', 'classico', 'azul', 'escuro'].find(t => t !== antes);
  await m.click('[data-gv-tema="' + outro + '"]');
  await m.waitForTimeout(400);
  const trocou = await m.evaluate(() => ({
    tema: document.documentElement.getAttribute('data-tema'),
    aceso: [...document.querySelectorAll('[data-gv-tema].on')].map(e => e.dataset.gvTema),
    aindaAberta: !document.getElementById('gaveta').hidden,
  }));
  ok(trocou.tema === outro, 'escolher um tema aplica-o de imediato: ' + antes + ' → ' + trocou.tema);
  ok(trocou.aceso.length === 1 && trocou.aceso[0] === outro, 'e o visto muda de sítio');
  ok(trocou.aindaAberta,
     'a gaveta fica aberta — quem está a comparar temas quer ver o efeito sem '
     + 'reabrir o menu de cada vez');
  await m.evaluate(t => { try { localStorage.setItem('tema', t); } catch (e) {}
                          document.documentElement.setAttribute('data-tema', t); }, antes);

  // ---- 5. sai por onde se espera ----
  await m.keyboard.press('Escape');
  await m.waitForTimeout(450);
  const fechada = await m.evaluate(() => ({
    escondida: document.getElementById('gaveta').hidden,
    expandido: document.getElementById('gaveta-bt').getAttribute('aria-expanded'),
    focoNoPuxador: document.activeElement.id === 'gaveta-bt',
    corpoSolto: getComputedStyle(document.body).overflow !== 'hidden',
  }));
  ok(fechada.escondida && fechada.expandido === 'false', 'o Escape fecha-a');
  ok(fechada.focoNoPuxador,
     'e o foco volta ao puxador — sem isso tabulava-se por uma página que já não se vê');
  ok(fechada.corpoSolto, 'e a página volta a rolar');

  await m.click('#gaveta-bt'); await m.waitForTimeout(400);
  await m.click('#gaveta-fundo', { position: { x: 20, y: 300 } });
  await m.waitForTimeout(450);
  ok(await m.evaluate(() => document.getElementById('gaveta').hidden),
     'o fundo escurecido também — um painel que só fecha no botão obriga a apontar');

  // O foco fica PRESO lá dentro: o Tab dá a volta e não sai para a página.
  await m.click('#gaveta-bt'); await m.waitForTimeout(400);
  const preso = await m.evaluate(async () => {
    const g = document.getElementById('gaveta');
    const f = [...g.querySelectorAll('a[href], button:not([disabled])')]
      .filter(e => e.offsetParent !== null);
    f[f.length - 1].focus();
    return { ultimo: document.activeElement === f[f.length - 1], quantos: f.length };
  });
  ok(preso.ultimo, 'o último elemento da gaveta recebe foco (' + preso.quantos + ' paragens)');
  await m.keyboard.press('Tab');
  await m.waitForTimeout(200);
  ok(await m.evaluate(() => !!document.activeElement.closest('#gaveta')),
     'e o Tab a partir dele dá a volta para dentro, em vez de sair para a página');
  await m.keyboard.press('Escape'); await m.waitForTimeout(400);

  // ---- 6. a página em que se está lê-se na gaveta ----
  await m.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(1500);
  await m.click('#gaveta-bt'); await m.waitForTimeout(400);
  ok(await m.evaluate(() => {
       const a = document.querySelector('#gaveta .gv-lista a.ativo');
       return !!a && /Mesas/.test(a.textContent); }),
     'em mesas.php, é «Mesas» que está aceso na gaveta');

  // ============ no ecrã largo ============
  const d = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  d.on('pageerror', e => errs.push('desktop: ' + e.message));
  await entrar(d);
  await d.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await d.waitForTimeout(1400);
  const largo = await d.evaluate(() => ({
    puxador: getComputedStyle(document.querySelector('.gaveta-bt')).display,
    tiraDeCima: document.querySelectorAll('.topo .nav a').length,
    noHeader: document.querySelectorAll('header nav a').length,
    fab: document.querySelector('.tema-fab')
       ? getComputedStyle(document.querySelector('.tema-fab')).display : 'não existe',
  }));
  ok(largo.puxador === 'none', 'no ecrã largo o puxador não aparece');
  ok(largo.tiraDeCima >= 10,
     'e a tira do cabeçalho continua inteira (' + largo.tiraDeCima + ' destinos)');
  ok(largo.noHeader === largo.tiraDeCima,
     'sem uma segunda cópia dentro do <header>: as provas lêem daí o menu de cada página');
  ok(largo.fab !== 'none',
     'e a pastilha do tema volta ao canto, onde não estorva ninguém');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
