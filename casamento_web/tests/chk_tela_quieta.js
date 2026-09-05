// A tela do editor fica quieta enquanto se trabalha.
//
// Cada retoque recompõe a tela de raiz — é o servidor que monta as secções, e
// não há como remendá-las no browser. Só que um documento novo começa no
// princípio, e o editor mandava-o depois descer outra vez até à secção: a
// maquete subia e descia a cada tecla. Aqui prova-se que deixou de o fazer, e
// que continua a ir à secção quando é isso que se lhe pede.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3500);

  // Onde está a secção dentro da janela da tela. É esta a medida que interessa:
  // o número de pixéis rolados muda com a altura da página, o sítio da secção
  // na vista é o que a pessoa vê.
  const naJanela = (sec) => p.evaluate(k => {
    const w = document.getElementById('tela').contentWindow;
    const s = w.document.querySelector('[data-sec="' + k + '"]');
    return s ? Math.round(s.getBoundingClientRect().top) : null;
  }, sec);
  const rolagem = () => p.evaluate(() =>
    Math.round(document.getElementById('tela').contentWindow.scrollY));

  // ============ 1. escolher uma camada leva lá ============
  await p.evaluate(() => irCamada('grande-dia'));
  await p.waitForTimeout(2000);
  ok(Math.abs(await naJanela('grande-dia')) <= 4,
     'escolher a camada «O grande dia» leva a tela até lá (' + await naJanela('grande-dia') + 'px)');
  const rolado = await rolagem();
  ok(rolado > 400, 'e isso é mesmo descer o convite: ' + rolado + 'px');

  // ============ 2. um retoque não mexe a tela ============
  // Recompor a tela é o caso difícil: o documento é outro, e a página até pode
  // ter outra altura. A secção tem de ficar onde estava, ao pixel.
  await p.evaluate(() => { const w = document.getElementById('tela').contentWindow;
    w.document.documentElement.style.scrollBehavior = 'auto'; w.scrollBy(0, 180); });
  await p.waitForTimeout(400);
  const antes = await naJanela('grande-dia');
  await p.evaluate(() => { EST.val['gd.eyebrow'] = 'Guarde este dia'; marcarSujo(true); recarregarTela(); });
  await p.waitForTimeout(3000);
  const depois = await naJanela('grande-dia');
  ok(antes !== null && depois !== null && Math.abs(depois - antes) <= 4,
     'depois de mudar uma propriedade, a secção está onde estava ('
       + antes + ' → ' + depois + ')');
  ok(/Guarde este dia/i.test(await p.evaluate(() =>
       document.getElementById('tela').contentWindow.document.body.innerText)),
     'e o retoque chegou mesmo à tela — não é que ela não se tenha recomposto');

  // A janela de edição diz à tela onde ela estava; sem isto, cada recomposição
  // devolvia o convite ao princípio.
  const ancora = await p.evaluate(() =>
    ({ sec: document.getElementById('tela-sec').value,
       y:   +document.getElementById('tela-y').value }));
  ok(ancora.sec !== '' && ancora.y > 0,
     'o pedido leva a âncora consigo: secção «' + ancora.sec + '», a ' + ancora.y + 'px');

  // ============ 3. e não volta a fazer a entrada em cena ============
  // As secções entram com um fade e um deslize de 34px. Na tela isso repetia-se
  // a cada tecla; quem recebe o convite continua a vê-las entrar.
  const naTela = await p.evaluate(() => {
    const w = document.getElementById('tela').contentWindow;
    const el = w.document.querySelector('.rv');
    if (!el) return null;
    const c = w.getComputedStyle(el);
    return { opacidade: c.opacity, transformada: c.transform };
  });
  ok(naTela && naTela.opacidade === '1' && (naTela.transformada === 'none' || naTela.transformada === ''),
     'na tela do editor as secções estão postas, não a entrar: '
       + JSON.stringify(naTela));
  const paraConvidados = await p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  ok(/\.rv\{opacity:0/.test(paraConvidados) && !/opacity:1 !important/.test(paraConvidados),
     'e o convite dos convidados continua a trazer a entrada das secções');

  // ============ 4. clicar na tela não a faz saltar ============
  const antesClique = await rolagem();
  await p.evaluate(() => { const w = document.getElementById('tela').contentWindow;
    const el = w.document.querySelector('#grande-dia [data-def]'); if (el) el.click(); });
  await p.waitForTimeout(1200);
  ok(Math.abs(await rolagem() - antesClique) <= 4,
     'clicar num texto dentro da tela escolhe-o sem a mover ('
       + antesClique + ' → ' + await rolagem() + ')');

  await p.screenshot({ path: OUT + '/tela-quieta.png' });
  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
