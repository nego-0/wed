// Separadores: com endereço próprio e navegáveis pelo teclado
// (docs/auditoria-ui-ux.md §25, DENS-001 e A11Y-003).
//
// O `bar.php` é a página mais densa da casa e vive em cinco separadores. Sem
// endereço, nenhum deles se podia guardar nos favoritos, mandar a alguém, nem
// alcançar com o botão de voltar: quem estava nas Regras e carregava em
// «voltar» saía da página inteira em vez de recuar um separador. E ao
// recarregar caía-se sempre no menu, por muito que o trabalho estivesse noutro
// sítio.
//
// E um `role="tablist"` é uma promessa: quem o ouve anunciado espera que as
// setas andem entre separadores e que o Tab atravesse o grupo de uma vez.
// Estava metade feita — o papel lá estava, o comportamento não —, e uma
// promessa por cumprir é pior do que não a fazer: quem confia nela carrega nas
// setas e não acontece nada.
//
// Prova-se o que a pessoa faz — carregar, voltar, escrever o endereço à mão,
// premir uma seta — e não o que o código diz que faz.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
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

  const ler = (sel) => p.evaluate((s) => ({
    aceso:    [...document.querySelectorAll(s + '.on')].map(e => e.id)[0] || '',
    vistos:   [...document.querySelectorAll('[role=tabpanel]')].filter(e => !e.hidden).map(e => e.id),
    tabbable: [...document.querySelectorAll(s)].filter(e => e.tabIndex === 0).map(e => e.id),
    foco:     document.activeElement.id,
    url:      location.search,
  }), sel);

  // ============ bar.php: cinco separadores, cinco endereços ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1900);

  const papeis = await p.evaluate(() => {
    const abas = [...document.querySelectorAll('[role=tab]')];
    const pns  = [...document.querySelectorAll('[role=tabpanel]')];
    return {
      abas: abas.length, painéis: pns.length,
      temLista: !!document.querySelector('[role=tablist]'),
      // Cada separador tem de dizer que painel comanda, e cada painel de quem
      // é comandado: sem isso, um leitor de ecrã lê dois grupos sem ligação.
      semControla: abas.filter(a => !a.getAttribute('aria-controls')).length,
      semEtiqueta: pns.filter(x => !x.getAttribute('aria-labelledby')).length,
      // E um painel tem de poder receber o foco, senão o conteúdo dele fica
      // atrás de uma porta que o teclado não abre.
      semFoco: pns.filter(x => x.tabIndex !== 0).length,
    };
  });
  ok(papeis.temLista && papeis.abas === 5 && papeis.painéis === 5,
     'bar.php: cinco separadores e cinco painéis, com os papéis certos');
  ok(papeis.semControla === 0, 'cada separador diz que painel comanda (aria-controls)');
  ok(papeis.semEtiqueta === 0, 'e cada painel diz de quem é (aria-labelledby)');
  ok(papeis.semFoco === 0, 'e o painel recebe o foco, para o teclado lhe chegar');

  const chegada = await ler('.b-aba');
  ok(chegada.aceso === 'ab-menu' && chegada.url === '',
     'à chegada abre o menu, e o endereço fica limpo');
  ok(chegada.tabbable.length === 1,
     'só o separador aceso é alcançável com o Tab — cinco paragens antes do '
     + 'conteúdo é o que este padrão existe para evitar');

  // ---- carregar num separador dá-lhe endereço ----
  await p.click('#ab-regras');
  await p.waitForTimeout(700);
  const regras = await ler('.b-aba');
  ok(regras.url === '?aba=regras',
     'carregar nas Regras põe-nas no endereço: ' + (regras.url || '(vazio)'));
  ok(regras.vistos.length === 1 && regras.vistos[0] === 'pn-regras',
     'e é o painel delas que está à vista');

  // ---- o botão de voltar recua um separador, e não a página inteira ----
  await p.goBack();
  await p.waitForTimeout(700);
  const voltou = await ler('.b-aba');
  ok(voltou.aceso === 'ab-menu' && /bar\.php/.test(p.url()),
     'voltar recua um separador — e não para fora da página');

  // ---- o endereço escrito à mão abre o separador, e sem erros ----
  for (const [aba, painel] of [['gente', 'pn-gente'], ['mesas', 'pn-mesas'], ['gav', 'pn-gav']]) {
    await p.goto(BASE + '/bar.php?aba=' + aba, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1700);
    const e = await ler('.b-aba');
    ok(e.aceso === 'ab-' + aba && e.vistos[0] === painel,
       '?aba=' + aba + ' abre lá direito — dá para guardar e para mandar a alguém');
  }
  // Um nome que não existe não deixa a página em branco.
  await p.goto(BASE + '/bar.php?aba=inventada', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  ok((await ler('.b-aba')).aceso === 'ab-menu',
     'e um separador que não existe cai no menu, em vez de não abrir nada');

  // ---- as setas ----
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  await p.focus('#ab-menu');
  await p.keyboard.press('ArrowRight');
  await p.waitForTimeout(400);
  let k = await ler('.b-aba');
  ok(k.aceso === 'ab-gav', 'a seta para a direita anda um separador');
  ok(k.foco === 'ab-gav',
     'e o foco vai com ela — acender um sítio e estar noutro é o pior dos dois');
  ok(k.tabbable.length === 1 && k.tabbable[0] === 'ab-gav',
     'e o Tab passa a sair dali, e não do separador de antes');

  await p.keyboard.press('ArrowLeft'); await p.waitForTimeout(350);
  ok((await ler('.b-aba')).aceso === 'ab-menu', 'a seta para a esquerda volta atrás');
  await p.keyboard.press('End'); await p.waitForTimeout(350);
  ok((await ler('.b-aba')).aceso === 'ab-gente', 'o End vai ao último');
  await p.keyboard.press('Home'); await p.waitForTimeout(350);
  ok((await ler('.b-aba')).aceso === 'ab-menu', 'e o Home ao primeiro');

  // ============ digital.php: o mesmo padrão, dois separadores ============
  await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const dg = await ler('.p-aba');
  ok(dg.tabbable.length === 1,
     'digital.php: também aqui só o separador aceso é alcançável com o Tab');
  await p.focus('#ab-estado');
  await p.keyboard.press('ArrowRight');
  await p.waitForTimeout(400);
  const dg2 = await ler('.p-aba');
  ok(dg2.aceso === 'ab-fotos' && dg2.foco === 'ab-fotos',
     'e as setas andam — meio padrão aplicado é pior do que nenhum, porque '
     + 'quem confia nele carrega na seta e não acontece nada');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
