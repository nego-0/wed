// Contas: um email por conta, e «Tirar conta» elimina mesmo a conta.
//
//   1. um email já em uso não se reatribui — nem a criar casamento, nem a
//      convidar um porteiro;
//   2. «Tirar conta» apaga a conta (não só o acesso), e o email fica livre.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  const mk = 'zz' + String(Date.now()).slice(-6);
  const emailN = 'noivos.' + mk + '@ex.pt';
  const emailP = 'porta.' + mk + '@ex.pt';

  const w1 = await api('casamento_criar', { nome: 'ZZ Un1 ' + mk, noiva: 'A', noivo: 'B', noivos_email: emailN }, 1);
  ok(w1.success && w1.contas && w1.contas.noivos, 'cria casamento com conta de noivos nova');
  const w2 = await api('casamento_criar', { nome: 'ZZ Un2 ' + mk, noiva: 'C', noivo: 'D', noivos_email: emailN }, 1);
  ok(!w2.success && /já existe/i.test(w2.message || ''), 'recusa reutilizar um email já em uso ao criar');

  await api('casamento_abrir&id=' + w1.id, {});
  const inv = await api('acesso_convidar', { email: emailP, papel: 'porteiro' }, 1);
  ok(inv.success && inv.senha, 'convida um porteiro novo, com senha temporária');
  const inv2 = await api('acesso_convidar', { email: emailN, papel: 'porteiro' }, 1);
  ok(!inv2.success && /uma só conta|já existe/i.test(inv2.message || ''),
     'recusa convidar um porteiro com um email já em uso');

  const del = await api('conta_apagar_do_casamento&utilizador=' + inv.utilizador + '&casamento=' + w1.id, {});
  ok(del.success && del.apagada, '«Tirar conta» elimina mesmo a conta do porteiro');
  const restou = ((await api('utilizador_lista&q=' + encodeURIComponent(emailP))).contas || []).some(u => u.email === emailP);
  ok(!restou, 'e a conta já não consta na base');
  const inv3 = await api('acesso_convidar', { email: emailP, papel: 'porteiro' }, 1);
  ok(inv3.success, 'o email eliminado fica livre para uma conta nova');

  // limpeza
  await api('casamento_estado&id=' + w1.id + '&estado=arquivado', {}); await api('casamento_apagar&id=' + w1.id, {});
  if (w2.id) { await api('casamento_estado&id=' + w2.id + '&estado=arquivado', {}); await api('casamento_apagar&id=' + w2.id, {}); }
  for (const em of [emailN, emailP]) {
    const u = ((await api('utilizador_lista&q=' + encodeURIComponent(em))).contas || []).find(x => x.email === em);
    if (u) await api('utilizador_apagar&id=' + u.id, {});
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
