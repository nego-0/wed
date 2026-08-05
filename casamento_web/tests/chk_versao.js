// A página de versão diz a verdade sobre o que está instalado.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1200, height: 900 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  await p.goto(BASE + '/versao.php', { waitUntil: 'networkidle' });
  const assin = (await p.locator('.assin').textContent()).trim();
  console.log('  assinatura:', assin);
  ok(/^[0-9a-f]{8}$/.test(assin), 'a assinatura é um hash curto do conteúdo instalado');
  const naos = await p.locator('td.nao').count();
  console.log('  correções em falta:', naos);
  ok(naos === 0, 'nesta instalação não falta nenhuma correção');
  ok(await p.locator('.aviso.bom').count() === 1, 'a página diz claramente que está tudo cá');

  // A mesma assinatura aparece no editor, para se confirmar num relance
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const noEditor = (await p.locator('.versao-app').textContent()).trim();
  ok(noEditor === assin, 'o editor mostra a mesma assinatura que a página de versão');

  // O diagnóstico só entra com ?diag=1
  ok(await p.locator('#ed-diag').count() === 0, 'sem ?diag=1 não há painel de diagnóstico');
  await p.goto(BASE + '/editor-cartao.php?diag=1', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  ok(await p.locator('#ed-diag').count() === 1, 'com ?diag=1 o painel de diagnóstico aparece');
  ok(await p.locator('#ed-diag-txt').count() === 1, 'e traz o sítio onde o relatório sai');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
