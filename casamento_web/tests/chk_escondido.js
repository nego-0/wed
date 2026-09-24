// O que está `hidden` está mesmo escondido.
//
// O HTML tem uma maneira de esconder uma coisa — o atributo `hidden` — e ela
// funciona por uma regra do browser: [hidden]{ display:none }. É uma regra
// fraca. Qualquer `display` escrito numa classe nossa tem a mesma força e vem
// depois, pelo que ganha. O elemento fica `hidden` no HTML, o JavaScript
// julga-o escondido, e ele está no ecrã.
//
// O que se vê então não é o elemento: é a CASCA dele. O conteúdo está vazio
// (o JavaScript ainda não o encheu, ou esvaziou-o de propósito), mas o fundo,
// a moldura e o espaço ficam. Uma barra oca. Foi assim que a tira de chegada
// continuou a aparecer no painel depois de a termos «escondido» fora da hora
// da festa: o texto desaparecia, a faixa verde não.
//
// Isto já tinha mordido antes, na barra do cesto do bar, e foi remendado só
// ali. Voltou em quatro sítios. É por isso que esta prova existe: não pergunta
// se ALGUÉM se lembrou do remendo, pergunta ao browser o que é que está
// realmente desenhado — que é a única pergunta que não se pode responder mal.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

// As páginas da casa que trazem elementos a nascer escondidos. Não é preciso
// serem todas: basta que uma folha partilhada seja carregada uma vez para as
// suas regras entrarem em prova.
const PAGINAS = ['index.php', 'copa.php', 'entregas.php', 'bar.php', 'mesas.php',
                 'modelos.php', 'digital.php', 'registo.php', 'impressos.php',
                 'orcamento.php', 'porteiro.php', 'gestao.php', 'convite-editor.php'];

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

  const traidores = [];
  let vistos = 0;

  for (const pag of PAGINAS) {
    const resp = await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' })
                        .catch(() => null);
    if (!resp || resp.status() >= 400) continue;
    // O JavaScript de cada página esconde e mostra à medida que os dados
    // chegam. Mede-se depois de ele ter tido a sua vez.
    await p.waitForTimeout(2200);

    const r = await p.evaluate(() => {
      const out = [];
      let conta = 0;
      for (const el of document.querySelectorAll('[hidden]')) {
        conta++;
        const cs = getComputedStyle(el);
        if (cs.display === 'none') continue;
        // Um pai escondido chega para esconder o filho, e isso é legítimo.
        if (!el.getClientRects().length) continue;
        const r = el.getBoundingClientRect();
        out.push({
          onde: (el.id ? '#' + el.id : el.tagName.toLowerCase())
                + (el.className && typeof el.className === 'string'
                   ? '.' + el.className.trim().split(/\s+/).join('.') : ''),
          display: cs.display,
          caixa: Math.round(r.width) + '×' + Math.round(r.height),
        });
      }
      return { conta, out };
    });

    vistos += r.conta;
    for (const t of r.out) traidores.push(pag + '  ' + t.onde
      + '  display:' + t.display + '  ' + t.caixa);
  }

  ok(vistos > 10,
     'há elementos a nascer escondidos para medir: ' + vistos);
  ok(traidores.length === 0,
     'nenhum elemento `hidden` continua desenhado — esconder o conteúdo não é '
     + 'esconder a caixa'
     + (traidores.length ? ':\n   ' + traidores.join('\n   ') : ''));

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
