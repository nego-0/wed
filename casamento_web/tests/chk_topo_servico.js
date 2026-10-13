// As páginas que fazem o seu próprio cabeçalho não apanham o cabeçalho fixo.
//
// A copa, as entregas e a porta não usam o cabeçalho partilhado: têm barra
// própria, com o menu curto de quem está a trabalhar de pé. Mas apanhavam, do
// estilo da casa, a regra do telemóvel que põe `.topo{position:fixed}` — e não
// tinham quem lhes medisse a altura a reservar, porque quem mede é o script do
// cabeçalho partilhado. O corpo reservava ZERO.
//
// O resultado, a 390px: o título, a tira de aviso e o primeiro cartão todos no
// mesmo sítio, com um cabeçalho transparente por cima — invisível, a tapar o
// que estava por baixo. Era o que se via como «menus transparentes».
//
// Fixar e reservar são a MESMA decisão: ou vêm as duas, ou não vem nenhuma. A
// classe `topo-fixo` é posta pelo próprio script que mede, e é ela que liga as
// duas coisas — não se podem voltar a separar sem se apagar a linha que as une.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const PROPRIO = ['copa.php', 'entregas.php', 'porteiro.php'];
const PARTILHADO = ['index.php', 'mesas.php', 'orcamento.php'];

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
    const l = await (await fetch('api.php?action=casamento_lista&estado=ativo',
      { headers: { 'X-CSRF-Token': window.CSRF } })).json();
    const c = (l.casamentos || [])[0];
    if (!c) throw new Error('A prova dos postos precisa de um casamento ativo.');
    await fetch('api.php?action=casamento_abrir&id=' + c.id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  const ler = () => p.evaluate(() => {
    const t = document.querySelector('.topo');
    if (!t) return null;
    const cs = getComputedStyle(t), r = t.getBoundingClientRect();
    // O teste que interessa: o que está no sítio do cabeçalho é o cabeçalho, e
    // logo abaixo dele começa o conteúdo. Se alguma coisa do corpo aparecer
    // POR BAIXO dele, é porque ele está pousado por cima.
    const noMeio = document.elementFromPoint(Math.round(innerWidth / 2),
                                             Math.round(r.top + r.height / 2));
    const abaixo = document.elementFromPoint(Math.round(innerWidth / 2),
                                             Math.round(Math.min(r.bottom + 8, innerHeight - 2)));
    return {
      pos: cs.position,
      fixo: document.body.classList.contains('topo-fixo'),
      pad: getComputedStyle(document.body).paddingTop,
      alt: Math.round(r.height),
      seuSitio: !!(noMeio && noMeio.closest('.topo')),
      abaixoFora: !!(abaixo && !abaixo.closest('.topo')),
      transbordo: document.documentElement.scrollWidth - innerWidth,
      servico: document.body.classList.contains('b-servico'),
      folhaServico: !!document.querySelector('link[href*="assets/bar.css"]'),
      pagina: location.pathname.split('/').pop(),
      // Um cabeçalho FIXO tem de ter fundo: transparente, deixa o conteúdo
      // passar-lhe por trás e lê-se tudo em cima de tudo.
      pintado: cs.backgroundImage !== 'none'
            || !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.backgroundColor),
    };
  });

  for (const pag of PROPRIO) {
    await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1500);
    const m = await ler();
    ok(!!m, pag + ': tem cabeçalho');
    ok(!!m && m.pagina === pag, pag + ': abre sem desvio (' + (m ? m.pagina : '?') + ')');
    ok(!!m && !m.fixo && m.pos !== 'fixed',
       pag + ': faz o seu próprio topo, e por isso ele NÃO é fixo (' + (m ? m.pos : '?') + ')');
    ok(!!m && m.seuSitio && m.abaixoFora,
       pag + ': nada se sobrepõe — o cabeçalho está no lugar dele e o conteúdo a seguir');
    ok(!!m && m.transbordo === 0, pag + ': sem rolagem horizontal');
    ok(!!m && m.servico && m.folhaServico,
       pag + ': usa a estrutura visual comum dos postos de serviço');
  }

  for (const pag of PARTILHADO) {
    await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1600);
    const m = await ler();
    ok(!!m && m.fixo && m.pos === 'fixed',
       pag + ': usa o cabeçalho partilhado, e esse é fixo');
    ok(!!m && m.pad === m.alt + 'px',
       pag + ': e o corpo guarda-lhe exactamente o lugar — ' + (m ? m.pad + ' para ' + m.alt + 'px' : '?'));
    ok(!!m && m.pintado,
       pag + ': um cabeçalho fixo tem de ter fundo, senão o conteúdo passa-lhe por trás');
    ok(!!m && m.seuSitio && m.abaixoFora, pag + ': e nada se sobrepõe');
  }

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
