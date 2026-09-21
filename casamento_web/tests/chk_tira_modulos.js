// Onde vai cada módulo da licença (docs/auditoria-ui-ux.md §25, UX-010).
//
// O painel dizia muito sobre os convidados e nada sobre o resto: quem tinha a
// planta de mesas, o orçamento e o bar na licença não tinha, em sítio nenhum,
// uma resposta à pergunta com que se abre o portátil — «o que é que falta
// fazer?». Ia-se a cada página ver.
//
// Isto vivia numa TIRA própria (.tm-cartao), por baixo da barra de ações. A
// tira acabou: metade dos seus rótulos já estava nos cartões de cima com outro
// número — «Confirmações 16 de 19» debaixo de «Confirmados 0» —, e duas tiras
// a dizer o mesmo de maneiras diferentes lêem-se como um erro. Os números
// passaram para os cartões do painel; o que esta prova defende é o que a tira
// defendia, que continua a valer no sítio novo:
//
//   1. Só aparece o que a LICENÇA abre. Um cartão de uma coisa que não se pode
//      usar é uma montra disfarçada de progresso.
//   2. Cada um leva ao sítio onde o trabalho se faz. Um número que só informa
//      obriga a ir procurar o caminho a seguir.
//   3. Entre os módulos, vem primeiro o que mais FALTA — é isso que se vem
//      aqui perguntar. (Os cartões de filtro têm ordem própria, a do casal:
//      esta regra vale dentro do grupo dos módulos, que é onde sempre quis
//      dizer alguma coisa.)
//   4. Nada de rótulos cortados nem cartões de alturas diferentes.
//   5. Onde a conta ainda não faz sentido — o bar antes de haver carta, a
//      porta antes do dia — diz-se o que falta em vez de se inventar uma
//      percentagem. Uma barra a zero por cento diria que há trabalho por fazer
//      onde ainda não há trabalho nenhum.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

// Os cartões que nascem de um módulo, e a página de cada um.
const DOS_MODULOS = { mesas:'mesas.php', porta:'porteiro.php',
                      orcamento:'orcamento.php', bar:'bebidas.php',
                      digital:'digital.php', impresso:'impressos.php' };

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

  // Lê os cartões do painel, com o destino de cada um (esteja o destino no
  // próprio cartão ou na seta do canto, que é como ficam os que também filtram).
  const lerCartoes = pg => pg.evaluate(() => {
    const cortado = e => !!e && e.scrollWidth > e.clientWidth + 1;
    return [...document.querySelectorAll('#stats .stat-f')].map(c => {
      const cx = c.parentElement;
      const seta = cx && cx.classList.contains('stat-cx') ? cx.querySelector('.stat-ir') : null;
      return {
        rot: ((c.querySelector('.sl') || {}).textContent || '').trim(),
        num: ((c.querySelector('.sn') || {}).textContent || '').trim(),
        sub: ((c.querySelector('.ss') || {}).textContent || '').trim(),
        onde: c.getAttribute('href') || (seta && seta.getAttribute('href')) || null,
        titulo: c.getAttribute('title') || '',
        altura: Math.round(c.getBoundingClientRect().height),
        cortado: cortado(c.querySelector('.sl')) || cortado(c.querySelector('.ss')),
        escondido: !!c.closest('.stats-extra:not(.aberto)'),
      };
    });
  });

  // ============ no ecrã largo ============
  const p = await entrar(await b.newContext({ viewport: { width: 1280, height: 900 } }));
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2600);

  const mods = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=painel_progresso')).json();
    return (d.modulos || []).map(m => ({ chave: m.chave, rotulo: m.rotulo,
      feito: +m.feito || 0, total: +m.total || 0,
      falta: Math.max(0, (+m.total || 0) - (+m.feito || 0)) }));
  });
  const cs = await lerCartoes(p);
  ok(cs.length > 0, 'o painel tem cartões: ' + cs.length);
  ok(mods.some(m => m.chave === 'convidados'),
     'a lista de convidados está sempre lá: é a porta de entrada de tudo o resto');

  // ---- 1. só o que a licença abre ----
  const destinos = cs.map(c => c.onde).filter(Boolean);
  const abertos = mods.map(m => m.chave);
  const aMais = Object.entries(DOS_MODULOS)
    .filter(([k, pag]) => destinos.includes(pag) && !abertos.includes(k))
    .map(([k]) => k);
  ok(aMais.length === 0,
     'nenhum caminho para um módulo que a licença não abre: ' + (aMais.join(', ') || 'nenhum'));
  const emFalta = Object.entries(DOS_MODULOS)
    .filter(([k, pag]) => abertos.includes(k) && !destinos.includes(pag))
    .map(([k]) => k);
  ok(emFalta.length === 0,
     'e todos os que ela abre têm caminho: ' + (emFalta.join(', ') || 'nenhum em falta'));

  // ---- 2. cada caminho é uma página ----
  ok(destinos.length > 0 && destinos.every(h => /\.php$/.test(h)),
     'cada caminho leva ao sítio onde o trabalho se faz: ' + destinos.length + ' cartões com página');

  // ---- 3. entre os módulos, o que mais falta vem primeiro ----
  const soModulos = ['Sentados', 'Entradas', 'Despesas', 'Bar'];
  const ordemNoEcra = cs.map(c => c.rot).filter(r => soModulos.includes(r));
  const faltaDe = { 'Sentados':'mesas', 'Entradas':'porta', 'Despesas':'orcamento', 'Bar':'bar' };
  const faltas = ordemNoEcra.map(r => {
    const m = mods.find(x => x.chave === faltaDe[r]);
    return m ? m.falta : -1;
  });
  const ordenada = faltas.every((v, i, a) => i === 0 || a[i - 1] >= v);
  ok(ordenada,
     'entre os módulos, o que mais falta vem primeiro: ' + (faltas.join(' ≥ ') || '(nenhum)'));

  // ---- 4. nada cortado, e todos da mesma altura ----
  const cortados = cs.filter(c => c.cortado).map(c => c.rot);
  ok(cortados.length === 0,
     'sem rótulos nem contas cortados a meio' + (cortados.length ? ': ' + cortados.join(', ') : ''));
  const alturas = [...new Set(cs.filter(c => !c.escondido).map(c => c.altura))];
  ok(alturas.length === 1,
     'e os cartões à vista todos da mesma altura — uma frase que quebra estica a '
     + 'linha inteira da grelha: ' + alturas.join('px, ') + 'px');

  // ---- 5. sem conta, diz-se o que falta em vez de se inventar uma barra ----
  const vazios = mods.filter(m => m.total <= 0).map(m => m.rotulo);
  const semNumero = cs.filter(c => soModulos.includes(c.rot) && c.num === '—');
  ok(semNumero.every(c => c.sub.length > 3),
     'onde ainda não há conta, o cartão diz o que falta em vez de mostrar um zero: '
     + (semNumero.map(c => c.rot + ' «' + c.sub + '»').join(', ') || 'todos com conta'));
  ok(semNumero.every(c => c.titulo.length > 8),
     'e a frase inteira vive no título, que no cartão não cabia'
     + (vazios.length ? ' (' + vazios.join(', ') + ')' : ''));

  const transbordo = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  ok(transbordo === 0, 'e a página não ganha rolagem horizontal');

  // ============ no telemóvel ============
  const m = await entrar(await b.newContext({ viewport: { width: 390, height: 844 },
                                              isMobile: true, hasTouch: true }));
  await m.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(2600);
  const cm = await lerCartoes(m);
  const aVista = cm.filter(c => !c.escondido);
  ok(aVista.length === 4,
     'a 390px ficam quatro cartões à vista, e o resto a um toque: ' + aVista.length);
  ok(aVista.every(c => !c.cortado), 'nada cortado a 390px');
  ok([...new Set(aVista.map(c => c.altura))].length === 1,
     'e os quatro da mesma altura');

  const est = await m.evaluate(() => {
    const busca = document.getElementById('busca');
    return { busca: Math.round(busca.getBoundingClientRect().top + scrollY),
             ecra: innerHeight,
             transbordo: document.documentElement.scrollWidth - innerWidth };
  });
  ok(est.busca < est.ecra,
     'e a caixa de procura continua no primeiro ecrã — era a razão de a tira '
     + 'antiga viver lá em baixo: y=' + est.busca + ' num ecrã de ' + est.ecra);
  ok(est.transbordo === 0, 'sem rolagem horizontal');

  await m.click('#stats-mais');
  await m.waitForTimeout(500);
  const depois = (await lerCartoes(m)).filter(c => !c.escondido).length;
  ok(depois > aVista.length,
     'e os outros estão a um toque de distância, não escondidos: '
     + aVista.length + ' → ' + depois);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
