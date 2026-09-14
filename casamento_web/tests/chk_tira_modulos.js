// Onde vai cada módulo da licença (docs/auditoria-ui-ux.md §25, UX-010).
//
// O painel dizia muito sobre os convidados e nada sobre o resto: quem tinha a
// planta de mesas, o orçamento e o bar na licença não tinha, em sítio nenhum,
// uma resposta à pergunta com que se abre o portátil — «o que é que falta
// fazer?». Ia-se a cada página ver.
//
// O que aqui se defende:
//
//   1. Só aparecem os módulos que a LICENÇA abre. Uma tira com barras de
//      coisas que não se podem usar é uma montra disfarçada de progresso.
//   2. Cada cartão leva ao sítio onde o trabalho se faz. Uma tira que só
//      informa obriga a ir procurar o caminho a seguir.
//   3. Ordena-se pelo que FALTA, que é o que se vem aqui perguntar — e não
//      pelo que já está feito.
//   4. Nada de rótulos cortados nem cartões de alturas diferentes. A primeira
//      versão media 166px no ecrã largo e 296px no telemóvel, o suficiente
//      para empurrar a lista de convites para fora do primeiro ecrã — que é
//      justamente o que esta tira devia ajudar a não fazer. A culpa era das
//      frases dos estados vazios a quebrar em três linhas: uma linha da grelha
//      cresce toda com o cartão mais alto.
//   5. Onde a conta ainda não faz sentido — o bar antes de haver carta, a
//      porta antes do dia — diz-se o que falta em vez de se inventar uma
//      percentagem. Uma barra a zero por cento diria que há trabalho por fazer
//      onde ainda não há trabalho nenhum.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const entrar = async (ctx) => {
    const p = await ctx.newPage();
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
    return p;
  };

  // ============ no ecrã largo ============
  const p = await entrar(await b.newContext({ viewport: { width: 1280, height: 900 } }));
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2200);

  const tira = await p.evaluate(() => {
    const cs = [...document.querySelectorAll('.tm-cartao')];
    const cortado = e => !!e && e.scrollWidth > e.clientWidth + 1;
    return {
      cartoes: cs.length,
      rotulos: cs.map(c => c.querySelector('.tm-rot').textContent.trim()),
      destinos: cs.map(c => c.getAttribute('href')),
      alturas: [...new Set(cs.map(c => Math.round(c.getBoundingClientRect().height)))],
      cortados: cs.filter(c => cortado(c.querySelector('.tm-rot'))
                            || cortado(c.querySelector('.tm-num')))
                  .map(c => c.querySelector('.tm-rot').textContent.trim()),
      // Sem nada contado não há barra nenhuma: ver o comentário em cima.
      semConta: cs.filter(c => c.classList.contains('tm-vazio')).length,
      barrasEmVazios: cs.filter(c => c.classList.contains('tm-vazio')
                                  && c.querySelector('.tm-barra')).length,
      comTitulo: cs.filter(c => (c.getAttribute('title') || '').length > 8).length,
      altura: Math.round(document.getElementById('tira-modulos').getBoundingClientRect().height),
      transbordo: document.documentElement.scrollWidth - innerWidth,
    };
  });
  ok(tira.cartoes > 0, 'o painel diz onde vai cada módulo: ' + tira.cartoes + ' cartões');
  ok(tira.cortados.length === 0,
     'sem rótulos nem contas cortados a meio' + (tira.cortados.length ? ': ' + tira.cortados.join(', ') : ''));
  ok(tira.alturas.length === 1,
     'e todos da mesma altura — uma frase que quebra estica a linha inteira da grelha: '
     + tira.alturas.join('px, ') + 'px');
  ok(tira.altura < 140,
     'a tira cabe num punhado de pixéis, e não num ecrã: ' + tira.altura + 'px');
  ok(tira.destinos.every(h => h && /\.php$/.test(h)),
     'cada cartão leva ao sítio onde o trabalho se faz');
  ok(tira.comTitulo === tira.cartoes,
     'e cada um leva a frase inteira no título, que no cartão não cabia');
  ok(tira.barrasEmVazios === 0,
     'sem nada contado não se desenha barra nenhuma — zero por cento diria que '
     + 'há trabalho por fazer onde ainda não há trabalho');
  ok(tira.transbordo === 0, 'e a página não ganha rolagem horizontal');

  // ---- o que a licença não abre não aparece ----
  const conferido = await p.evaluate(async () => {
    const r = await fetch('api.php?action=painel_progresso');
    const d = await r.json();
    const daApi = (d.modulos || []).map(m => m.chave);
    const noEcra = [...document.querySelectorAll('.tm-cartao')].length;
    return { daApi: daApi, noEcra: noEcra };
  });
  ok(conferido.daApi.length === conferido.noEcra,
     'a tira mostra exactamente os módulos que a licença abre ('
     + conferido.daApi.join(', ') + ')');
  ok(conferido.daApi.includes('convidados'),
     'a lista de convidados está sempre lá: é a porta de entrada de tudo o resto');

  // ---- ordena-se pelo que falta ----
  const ordem = await p.evaluate(async () => {
    const r = await fetch('api.php?action=painel_progresso');
    const d = await r.json();
    const falta = {};
    (d.modulos || []).forEach(m => { falta[m.chave] = Math.max(0, (+m.total || 0) - (+m.feito || 0)); });
    const rots = [...document.querySelectorAll('.tm-cartao')]
      .map(c => c.getAttribute('href').replace('.php', ''));
    return { faltas: (d.modulos || []).map(m => falta[m.chave]), n: rots.length };
  });
  const noEcraFaltas = await p.evaluate(() =>
    [...document.querySelectorAll('.tm-cartao')].map(c => {
      const t = (c.getAttribute('title') || '');
      const m = t.match(/(\d[\d\s]*) de (\d[\d\s]*)/);
      if (!m) return -1;   // um cartão sem conta não entra na ordenação
      return parseInt(m[2].replace(/\s/g, ''), 10) - parseInt(m[1].replace(/\s/g, ''), 10);
    }).filter(x => x >= 0));
  const ordenada = noEcraFaltas.every((v, i, a) => i === 0 || a[i - 1] >= v);
  ok(ordenada,
     'e o que mais falta vem primeiro — é isso que se vem aqui perguntar: '
     + noEcraFaltas.join(' ≥ '));

  // ============ no telemóvel ============
  const m = await entrar(await b.newContext({ viewport: { width: 390, height: 844 },
                                              isMobile: true, hasTouch: true }));
  await m.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(2200);
  const est = await m.evaluate(() => {
    const cs = [...document.querySelectorAll('.tm-cartao')];
    const cortado = e => !!e && e.scrollWidth > e.clientWidth + 1;
    return { cartoes: cs.length,
             altura: Math.round(document.getElementById('tira-modulos').getBoundingClientRect().height),
             alturas: [...new Set(cs.map(c => Math.round(c.getBoundingClientRect().height)))],
             cortados: cs.filter(c => cortado(c.querySelector('.tm-rot'))
                                   || cortado(c.querySelector('.tm-num'))).length,
             mais: !!document.querySelector('.tm-mais'),
             transbordo: document.documentElement.scrollWidth - innerWidth };
  });
  ok(est.cartoes <= 4,
     'a 390px mostram-se os quatro que mais pedem trabalho: ' + est.cartoes);
  ok(est.altura < 180,
     'e a tira não come o primeiro ecrã: ' + est.altura + 'px');
  ok(est.cortados === 0, 'nada cortado a 390px');
  ok(est.alturas.length === 1, 'e os cartões todos da mesma altura');
  ok(est.transbordo === 0, 'sem rolagem horizontal');

  if (est.mais) {
    await m.click('.tm-mais');
    await m.waitForTimeout(350);
    const todos = await m.evaluate(() => document.querySelectorAll('.tm-cartao').length);
    ok(todos > est.cartoes,
       'e os outros estão a um toque de distância, não escondidos: '
       + est.cartoes + ' → ' + todos);
  }

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
