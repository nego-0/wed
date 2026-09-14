// O que se mostra enquanto não há nada para mostrar
// (docs/auditoria-ui-ux.md §25, EST-001 e CTA-001).
//
// O que aqui se defende:
//
//   1. UMA ação principal na barra do painel. Estavam ali oito acções em fila
//      e «+ Novo convite» — a que se faz dezenas de vezes — era a oitava,
//      depois de quatro que se fazem uma vez por casamento. As de uma vez só
//      passaram para trás do «⋯», e o menu tem de abrir DENTRO do ecrã, no
//      telemóvel como no computador.
//   2. O esqueleto aparece enquanto a resposta não chega, e tem a ALTURA das
//      linhas verdadeiras. Um esqueleto de outra altura é um salto de página
//      disfarçado de cortesia: mede-se o antes e o depois, e o que não pode
//      acontecer é ENCOLHER — encolher puxa a página para cima por baixo do
//      dedo de quem já estava a ler.
//   3. A zero linhas não há esqueleto nenhum: prometer uma lista e entregar
//      «ainda não há convites» é a pior das duas coisas.
//   4. As barras são desenho, e um leitor de ecrã a soletrar seis caixas
//      vazias é pior do que o silêncio: levam `aria-hidden`, e quem ouve
//      recebe a frase.
//   5. O vazio nunca é só «não há nada»: diz o que falta, porquê, e dá o gesto
//      que resolve. E um vazio por causa de um FILTRO tem de o confessar,
//      senão lê-se como «está tudo vazio» e a pessoa vai criar o que já lá
//      está.
//
// Para se ver o esqueleto atrasa-se a resposta de propósito: ele vive uns
// milissegundos, e sem isto a prova não chegava a tempo de o apanhar.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
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

  // ============ CTA-001: uma primária ============
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  const barra = await p.evaluate(() => ({
    primarias: [...document.querySelectorAll('.barra-acoes .btn-ouro')].map(e => e.textContent.trim()),
    naBarra:   document.querySelectorAll('.barra-acoes button, .barra-acoes a').length,
  }));
  ok(barra.primarias.length === 1 && /Novo convite/.test(barra.primarias[0]),
     'a barra do painel tem UMA ação principal, e é a que se faz todos os dias: '
     + barra.primarias.join(', '));
  ok(barra.naBarra <= 3,
     'e ao todo não mais de três botões à vista (' + barra.naBarra + ')');

  // O menu abre, e abre dentro do ecrã. Nos dois tamanhos: num telemóvel, um
  // menu de 190px ancorado à direita de um botão que ficou à esquerda saía
  // pela borda e as acções lá de dentro não se alcançavam.
  for (const [nome, larg, alt] of [['no computador', 1280, 900], ['a 390px', 390, 844]]) {
    await p.setViewportSize({ width: larg, height: alt });
    await p.waitForTimeout(400);
    await p.click('.btn-mais-acoes');
    await p.waitForTimeout(250);
    const pop = await p.evaluate(() => {
      const el = document.getElementById('pop-mais');
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const itens = [...el.querySelectorAll('button, a')];
      return {
        dentro: r.left >= 0 && r.right <= innerWidth + 1 && r.top >= 0 && r.bottom <= innerHeight + 1,
        itens: itens.length,
        alcancaveis: itens.filter(i => {
          const q = i.getBoundingClientRect();
          const e = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
          return e && (e === i || i.contains(e));
        }).length,
      };
    });
    ok(!!pop && pop.itens === 4, 'o «⋯» abre com as quatro acções de uma vez só ' + nome);
    ok(!!pop && pop.dentro, 'e abre dentro do ecrã ' + nome);
    ok(!!pop && pop.alcancaveis === 4, 'com as quatro a poderem ser tocadas ' + nome);
    await p.keyboard.press('Escape');
    await p.waitForTimeout(200);
  }
  await p.setViewportSize({ width: 1280, height: 900 });

  // ============ EST-001: o esqueleto ============
  // Quantas linhas existem mesmo, para se saber o que esperar do esqueleto.
  const quantos = await p.evaluate(async () => {
    const d = await window.api('convite_list', { silencioso: true });
    return (d && d.convites || []).length;
  });
  console.log('  (o casamento aberto tem ' + quantos + ' convite(s))');

  const atraso = /action=convite_list/;
  await ctx.route(atraso, async r => { await new Promise(x => setTimeout(x, 1100)); r.continue(); });
  await p.goto(BASE + '/index.php');
  await p.waitForTimeout(650);
  const esq = await p.evaluate(() => {
    const c = document.querySelector('#lista .esq-lista');
    const bs = [...document.querySelectorAll('#lista .esqueleto')];
    if (!c) return null;
    return { barras: bs.length,
             alt: Math.round(bs[0].getBoundingClientRect().height),
             total: Math.round(c.getBoundingClientRect().height),
             escondidas: bs.every(x => x.getAttribute('aria-hidden') === 'true'),
             frase: (c.querySelector('.so-leitor') || {}).textContent || '',
             pulsa: getComputedStyle(bs[0]).animationName };
  });
  if (quantos === 0) {
    ok(esq === null,
       'sem convites nenhuns não se promete lista nenhuma: não há esqueleto');
  } else {
    ok(!!esq, 'à espera da lista, o lugar dela fica marcado');
    ok(!!esq && esq.barras === Math.min(8, quantos),
       'com o número certo de barras (' + (esq ? esq.barras : 0) + ' para '
       + quantos + ' convite(s))');
    ok(!!esq && esq.pulsa === 'esq-pulso', 'e a pulsar, para se ver que está a caminho');
    ok(!!esq && esq.escondidas,
       'as barras são desenho: um leitor de ecrã não as soletra');
    ok(!!esq && /A carregar/.test(esq.frase),
       'e quem ouve recebe a frase em vez do silêncio');
  }
  await p.waitForTimeout(2600);
  const real = await p.evaluate(() => ({
    alt: Math.round(document.getElementById('lista').getBoundingClientRect().height),
    linhas: document.querySelectorAll('#lista .esq-lista').length,
  }));
  ok(real.linhas === 0, 'e quando a lista chega o esqueleto sai da frente');
  if (esq) {
    // O que não pode acontecer é ENCOLHER. Crescer é inofensivo — a lista
    // continua por baixo e nada do que já se estava a ler se mexe.
    ok(real.alt >= esq.total - 8,
       'a lista verdadeira não é mais curta do que o esqueleto prometeu: '
       + esq.total + 'px → ' + real.alt + 'px');
  }
  await ctx.unroute(atraso);

  // ============ EST-001: o vazio que confessa o filtro ============
  await p.fill('#busca', 'zzzznaoexistezzz');
  await p.waitForTimeout(1500);
  const vf = await p.evaluate(() => {
    const v = document.querySelector('#lista .vazio');
    if (!v) return null;
    return { titulo: (v.querySelector('b') || {}).textContent || '',
             texto:  (v.querySelector('p') || {}).textContent || '',
             botao:  (v.querySelector('.btn') || {}).textContent || '',
             ico: !!v.querySelector('svg') };
  });
  ok(!!vf, 'sem resultados, a lista diz o que aconteceu');
  ok(!!vf && /filtro/i.test(vf.titulo + vf.texto),
     'e confessa que a culpa é do filtro — senão lê-se como «não há convites nenhuns»');
  ok(!!vf && /Limpar/i.test(vf.botao),
     'com o gesto que o resolve ali mesmo: ' + (vf ? vf.botao.trim() : ''));
  ok(!!vf && vf.ico, 'e um sinal desenhado, que não é um emoji');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
