// Cores com nome, textos que pintam ao vivo, e os nomes dos noivos na tela.
// Ver tests/LEIA-ME.md para como correr.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });


  // ---- cartão: o texto de abertura pinta ao vivo ----
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  ok(await p.evaluate(() => {
    const antes = document.querySelector('#escala .ct-abertura').textContent;
    pintarTexto('cartao.abertura', 'SONDA VIVA');
    const dep = document.querySelector('#escala .ct-abertura').textContent;
    pintarTexto('cartao.abertura', antes);
    return dep.includes('SONDA VIVA');
  }), 'o texto de abertura do cartão pinta ao vivo');

  // ---- convite digital: cores com nome, nenhuma morta ----
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  await p.evaluate(() => document.querySelectorAll('.ed-painel').forEach(x => {
    if (/Cores/.test(x.querySelector('h3').textContent)) x.classList.remove('fechado'); }));
  await p.waitForTimeout(600);

  const rot = await p.evaluate(() => [...document.querySelectorAll('#cores .cor-linha span')].map(s => s.textContent.trim()));
  console.log('  rótulos:', rot.join(' · '));
  ok(rot.length === 8, 'ficam 8 cores — as que não pintavam nada saíram');
  ok(!rot.some(r => r.startsWith('--')), 'nenhum rótulo mostra o nome da variável CSS');
  const onde = await p.evaluate(() => [...document.querySelectorAll('#cores .cor-onde')].map(s => s.textContent.trim()));
  ok(onde.length === 8 && onde.every(x => x.length > 10), 'cada cor diz o que pinta');

  // Nenhuma das cores oferecidas pode ser das que o modelo nunca usa
  ok(await p.evaluate(() => !TEMA_VARS.some(v => ['ink','sand','blush'].includes(v))),
     'as três cores sem efeito deixaram de ser oferecidas');

  // ---- os nomes dos noivos pintam em todos os sítios ----
  const nos = await p.evaluate(() => {
    const d = document.getElementById('tela').contentDocument;
    return [...d.querySelectorAll('[data-def="casal.noiva"]')].length;
  });
  console.log('  nós casal.noiva na tela:', nos);
  ok(nos >= 2, 'os dois sítios onde o nome da noiva aparece ficam marcados');

  await p.evaluate(() => { EST.val['casal.noiva'] = 'SONDANOIVA';
    enviarTela({ tipo:'texto', def:'casal.noiva', html:'SONDANOIVA' }); });
  await p.waitForTimeout(700);      // o postMessage chega à tela de forma assíncrona
  ok(await p.evaluate(() => {
    const d = document.getElementById('tela').contentDocument;
    const ns = [...d.querySelectorAll('[data-def="casal.noiva"]')];
    return ns.length >= 2 && ns.every(n => n.textContent === 'SONDANOIVA');
  }), 'escrever o nome da noiva muda-o em todos os sítios da tela');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
