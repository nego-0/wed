// O formulário público de inscrição — as verificações de preenchimento.
//
// Prova, de fora: sem dados obrigatórios não avança e assinala cada campo; um
// email torto, uma palavra-passe curta e uma confirmação que não bate certo são
// apanhados; o plano tem de ser escolhido e as políticas aceites; a conta do
// porteiro exige as duas coisas ou nenhuma; e, bem preenchido, a inscrição
// entra na fila — com a conta já aberta, para o casal poder entrar de imediato.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const maus = () => p.$$eval('.campo.mau input, .campo.mau select', els => els.map(e => e.id));

  await p.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });

  // ---------- 1. submeter vazio: os obrigatórios acendem ----------
  await p.evaluate(() => enviar()); await p.waitForTimeout(200);
  let m = await maus();
  ok(['noiva','noivo','email','senha','confirmar'].every(k => m.includes(k)),
     'vazio: acende os cinco campos obrigatórios (' + m.join(',') + ')');
  ok(await p.locator('#erro').isVisible(), 'e mostra um aviso geral');
  ok(!(await p.locator('#obrigado').isVisible()), 'e NÃO avança para o ecrã de sucesso');

  // ---------- 2. email/senha/confirmação inválidos ----------
  await p.fill('#noiva', 'Isabel'); await p.fill('#noivo', 'Abednego');
  await p.fill('#email', 'isabel(a)ex'); await p.fill('#senha', '123'); await p.fill('#confirmar', '999');
  await p.evaluate(() => enviar()); await p.waitForTimeout(200);
  m = await maus();
  ok(m.includes('email'), 'um email sem @ é recusado');
  ok(m.includes('senha'), 'uma palavra-passe com menos de 8 é recusada');
  ok(m.includes('confirmar'), 'e uma confirmação diferente é apanhada');

  // A força da palavra-passe reage ao que se escreve.
  await p.fill('#senha', 'Segredo123!'); await p.dispatchEvent('#senha', 'input'); await p.waitForTimeout(120);
  ok(/f[34]/.test(await p.getAttribute('#pw-forca', 'class')), 'a força sobe com uma boa palavra-passe');

  // ---------- 3. o plano: a montra, e as políticas por aceitar ----------
  await p.fill('#confirmar', 'Segredo123!');
  const email = 'reg.form.' + Date.now() + '@exemplo.ao';
  await p.fill('#email', email);

  // A montra vem do preçário do servidor.
  await p.waitForSelector('#reg-planos .pl-pac', { timeout: 8000 });
  const pacs = await p.$$eval('#reg-planos .pl-pac', e => e.length);
  ok(pacs > 0, `a montra mostra os pacotes do preçário (${pacs})`);
  ok(await p.$$eval('#reg-planos .pl-pac.on', e => e.length) === 1,
     'e um vem já escolhido — o que está em destaque');
  ok(/\d/.test(await p.textContent('.pl-conta-val')), 'a conta do plano mostra um total');

  // Sem aceitar as políticas não passa — é o consentimento informado.
  await p.evaluate(() => enviar()); await p.waitForTimeout(250);
  ok(await p.$eval('#reg-aceite-cx', e => e.classList.contains('mau')),
     'sem aceitar as políticas, a inscrição não avança');
  ok(!(await p.locator('#obrigado').isVisible()), 'e continua no formulário');

  // A janela das políticas abre e traz o texto da lei.
  await p.click('#reg-ver-pol'); await p.waitForTimeout(300);
  ok(await p.locator('#pl-modal.on').isVisible(), 'a janela das políticas abre');
  const txtPol = await p.textContent('#pl-modal .pl-texto');
  ok(/22\/11/.test(txtPol), 'e o texto invoca a lei da protecção de dados');
  // «Li e aceito» fecha a janela e marca a caixa.
  await p.click('#pl-aceitar'); await p.waitForTimeout(250);
  ok(await p.isChecked('#reg-aceite'), 'aceitar na janela marca a caixa do formulário');

  // ---------- 4. o porteiro: email sem palavra-passe não passa ----------
  await p.evaluate(() => document.querySelectorAll('details.bloco').forEach(d => d.open = true));
  await p.evaluate(() => { PORT_TOCADO = true; });
  await p.fill('#porteiro_senha', ''); await p.fill('#porteiro_email', 'porta@ex.pt');
  await p.evaluate(() => enviar()); await p.waitForTimeout(200);
  ok((await maus()).includes('porteiro_senha'), 'porteiro com email mas sem palavra-passe é apanhado');
  // Sem porteiro nenhum (par vazio) é válido — é opcional.
  await p.fill('#porteiro_email', ''); await p.fill('#porteiro_senha', '');

  // ---------- 5. bem preenchido: entra na fila ----------
  await p.evaluate(() => enviar()); await p.waitForTimeout(1500);
  ok(await p.locator('#obrigado').isVisible(), 'bem preenchido, a inscrição avança para o ecrã de sucesso');
  ok((await p.textContent('#feito-email')).includes(email), 'que confirma o email escolhido');
  ok(!(await p.locator('#planos-sec').isVisible()), 'e a montra sai de cena');

  // ---------- limpeza: como admin, arquiva e apaga o que se criou ----------
  const a = await b.newContext().then(c => c.newPage());
  await a.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await a.fill('input[name=utilizador]', 'admin'); await a.fill('input[name=senha]', 'noivos2026');
  await a.click('button[type=submit]'); await a.waitForLoadState('networkidle');
  const api = (ac, c) => a.evaluate(async ({ ac, c }) => {
    const r = await fetch('api.php?action=' + ac, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { ac, c });
  const lista = (await api('casamento_lista&estado=pendente&q=Isabel')).casamentos || [];
  const nosso = lista.find(x => (x.nome || '').includes('Isabel'));
  if (nosso) { await api('casamento_estado&id=' + nosso.id + '&estado=arquivado', {}); await api('casamento_apagar&id=' + nosso.id, {}); }
  const conta = ((await api('utilizador_lista&q=' + encodeURIComponent(email))).contas || []).find(u => u.email === email);
  if (conta) await api('utilizador_apagar&id=' + conta.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
