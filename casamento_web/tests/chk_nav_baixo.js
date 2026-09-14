// A navegação de baixo, no telemóvel (docs/auditoria-ui-ux.md §8 e NAV-001).
//
// O que aqui se defende:
//   1. A 390px há uma barra fixa em baixo, e nela cabem os destinos do trabalho
//      de todos os dias — visíveis, sem rolar na horizontal para os descobrir.
//   2. Nenhum rótulo se corta. Uma coluna de 78px não leva «Convite digital»,
//      e um rótulo cortado a meio não é um rótulo.
//   3. Todos os alvos têm 44px, que é o mínimo com que um dedo acerta.
//   4. O resto dos destinos vive numa folha que sobe, e a folha sai por onde se
//      espera: pelo fundo escurecido, pelo Escape, e ao escolher.
//   5. O foco volta ao botão quando a folha se fecha. Sem isso, quem navega por
//      teclado fica atrás dela a tabular por uma página que já não vê.
//   6. A página em que se está lê-se na barra — e quando ela vive na folha, é o
//      «Mais» que se acende.
//   7. No ecrã largo nada disto existe: a tira do cabeçalho cabe e chega. Duas
//      navegações ao mesmo tempo são duas respostas à mesma pergunta.
//   8. E o fundo escurecido, quando está escondido, está mesmo escondido — um
//      `[hidden]` perde para uma classe, e isso deixava-o a tapar a página
//      inteira e a engolir todos os toques.
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

  const barra = await m.evaluate(() => {
    const nb = document.querySelector('.nav-baixo');
    const itens = [...document.querySelectorAll('.nb-item')];
    const dentro = (e) => { const r = e.getBoundingClientRect();
      return r.left >= -1 && r.right <= innerWidth + 1
          && r.top >= 0 && r.bottom <= innerHeight + 1; };
    return {
      existe:   !!nb && getComputedStyle(nb).display !== 'none',
      destinos: itens.length,
      visiveis: itens.filter(dentro).length,
      pequenos: itens.filter(e => { const r = e.getBoundingClientRect();
                                    return r.width < 44 || r.height < 44; }).length,
      cortados: itens.map(e => e.querySelector('.nb-rot'))
                     .filter(s => s && s.scrollWidth > s.clientWidth + 1)
                     .map(s => s.textContent.trim()),
      tiraDeCima: getComputedStyle(document.querySelector('.topo .nav')).display,
      transbordo: document.documentElement.scrollWidth - innerWidth,
    };
  });
  ok(barra.existe, 'a 390px há uma barra de navegação em baixo');
  ok(barra.destinos >= 4 && barra.destinos <= 5,
     'com quatro destinos e o «Mais» — nem mais, que não cabiam: ' + barra.destinos);
  ok(barra.visiveis === barra.destinos,
     'e todos à vista, sem rolar na horizontal (' + barra.visiveis + '/' + barra.destinos + ')');
  ok(barra.pequenos === 0,
     'todos com 44px, que é o mínimo com que um dedo acerta');
  ok(barra.cortados.length === 0,
     'e nenhum rótulo cortado a meio' + (barra.cortados.length ? ': ' + barra.cortados.join(', ') : ''));
  ok(barra.tiraDeCima === 'none',
     'a tira do cabeçalho recolhe: duas navegações seriam duas respostas à mesma pergunta');
  ok(barra.transbordo === 0, 'e a página não passa a ganhar rolagem horizontal');

  // ---- o fundo escondido está MESMO escondido ----
  // `[hidden]` é um selector de atributo e perde para uma classe. Com um
  // `.folha-fundo{display:block}` solto, ele ficava por cima da página inteira,
  // invisível e a engolir todos os toques.
  const tapa = await m.evaluate(() => {
    const fundo = document.getElementById('folha-fundo');
    if (!fundo) return 'não existe';
    if (!fundo.hidden) return 'devia estar escondido';
    return getComputedStyle(fundo).display;
  });
  ok(tapa === 'none',
     'com a folha fechada, o fundo escurecido não tapa nada: display ' + tapa);
  const aoCentro = await m.evaluate(() => {
    const e = document.elementFromPoint(innerWidth / 2, innerHeight / 2);
    return e ? (e.closest('#folha-fundo') ? 'o fundo da folha' : 'conteúdo') : 'nada';
  });
  ok(aoCentro === 'conteúdo', 'e um toque no meio do ecrã chega ao conteúdo');

  // ---- a folha ----
  await m.click('#nb-mais');
  await m.waitForTimeout(420);
  const aberta = await m.evaluate(() => {
    const fl = document.getElementById('folha-mais');
    return { visivel: !fl.hidden && fl.classList.contains('aberta'),
             expandido: document.getElementById('nb-mais').getAttribute('aria-expanded'),
             destinos: fl.querySelectorAll('.fm-lista a').length,
             focoDentro: !!document.activeElement.closest('#folha-mais') };
  });
  ok(aberta.visivel, 'o «Mais» faz subir a folha');
  ok(aberta.expandido === 'true', 'e diz que está aberta (aria-expanded)');
  ok(aberta.destinos >= 5,
     'com o resto dos destinos lá dentro (' + aberta.destinos + ')');
  ok(aberta.focoDentro, 'e o foco entra na folha');

  await m.keyboard.press('Escape');
  await m.waitForTimeout(420);
  const fechada = await m.evaluate(() => ({
    escondida: document.getElementById('folha-mais').hidden,
    expandido: document.getElementById('nb-mais').getAttribute('aria-expanded'),
    focoNoBotao: document.activeElement.id === 'nb-mais',
  }));
  ok(fechada.escondida && fechada.expandido === 'false', 'o Escape fecha-a');
  ok(fechada.focoNoBotao,
     'e o foco volta ao botão — sem isso tabulava-se por uma página que já não se vê');

  await m.click('#nb-mais'); await m.waitForTimeout(420);
  await m.click('#folha-fundo', { position: { x: 120, y: 60 } });
  await m.waitForTimeout(420);
  ok(await m.evaluate(() => document.getElementById('folha-mais').hidden),
     'e o fundo escurecido também — um painel que só fecha no botão obriga a apontar');

  // ---- a página em que se está lê-se na barra ----
  await m.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(1500);
  ok(await m.evaluate(() => {
       const a = document.querySelector('.nb-item.ativo');
       return !!a && /Mesas/.test(a.textContent); }),
     'em mesas.php, é «Mesas» que está aceso');

  await m.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(1500);
  ok(await m.evaluate(() => {
       const a = document.querySelector('.nb-item.ativo');
       return !!a && a.id === 'nb-mais'; }),
     'e numa página que vive na folha, acende-se o «Mais»');

  // ============ no ecrã largo, nada disto existe ============
  const d = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  d.on('pageerror', e => errs.push('desktop: ' + e.message));
  await entrar(d);
  await d.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await d.waitForTimeout(1400);
  const largo = await d.evaluate(() => ({
    barraBaixo: getComputedStyle(document.querySelector('.nav-baixo')).display,
    tiraDeCima: document.querySelectorAll('.topo .nav a').length,
    noHeader:   document.querySelectorAll('header nav a').length,
  }));
  ok(largo.barraBaixo === 'none', 'no ecrã largo a barra de baixo não aparece');
  ok(largo.tiraDeCima >= 10,
     'e a tira do cabeçalho continua inteira (' + largo.tiraDeCima + ' destinos)');
  ok(largo.noHeader === largo.tiraDeCima,
     'sem uma segunda cópia dentro do <header>: as provas lêem daí o menu de cada página');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
