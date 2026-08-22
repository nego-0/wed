// «Repor Secção (Nome)» no editor do convite digital.
//
// Duas coisas: o botão diz sempre que secção repõe, e repor a secção devolve-a
// INTEIRA ao desenho de origem — textos E fotografia. A foto que o casal tenha
// posto à mão para a secção é largada (o retoque some do casamento e volta a
// valer a de origem).
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  p.on('dialog', d => d.accept());        // confirma os avisos de repor
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  // Um casamento-oficina próprio, para não sujar o de ninguém.
  const casal = await p.evaluate(async () => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: 'ZZ Repor ' + Date.now(), noiva: 'Rita', noivo: 'Rui' }) });
    return r.json();
  });
  const cid = casal.id;
  await p.evaluate(async (id) => { await fetch('api.php?action=casamento_abrir&id=' + id,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);

  // A secção «Capa» ganha uma foto manual e um texto próprio.
  await p.evaluate(async () => { await fetch('api.php?action=defs_save', { method: 'POST',
    headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
    body: JSON.stringify({ defs: { 'media.hero': 'assets/convite/custom/manual-teste.jpg',
                                   'textos.kicker': 'KICKER MANUAL' } }) }); });

  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  // ---------- 1. o botão diz a secção ----------
  const lblEnv = (await p.textContent('#bt-repor') || '').trim();
  ok(lblEnv === 'Repor Secção (Envelope)', 'no envelope, o botão diz «Repor Secção (Envelope)» — ' + JSON.stringify(lblEnv));
  await p.evaluate(() => irCamada('hero')); await p.waitForTimeout(300);
  const lblHero = (await p.textContent('#bt-repor') || '').trim();
  ok(lblHero === 'Repor Secção (Capa)', 'na Capa, diz «Repor Secção (Capa)» — ' + JSON.stringify(lblHero));

  // ---------- 2. repor devolve texto e foto à origem ----------
  const padraoHero = await p.evaluate(() => PADRAO['media.hero']);
  const antes = await p.evaluate(() => EST.val['media.hero']);
  ok(antes === 'assets/convite/custom/manual-teste.jpg', 'antes, a Capa tem a foto manual');
  await p.evaluate(async () => { await reporSeccao(); });
  await p.waitForTimeout(500);
  ok(await p.evaluate(() => EST.val['media.hero']) === padraoHero, 'depois, a foto da Capa volta à de origem');
  ok(await p.evaluate(() => EST.val['textos.kicker']) === (await p.evaluate(() => PADRAO['textos.kicker'])),
     'e o texto (kicker) volta ao de origem');

  // ---------- 3. o servidor larga mesmo o retoque manual ----------
  const serv = await p.evaluate(async (id) => {
    const r = await fetch('api.php?action=dados_exportar&ambito=casamento&id=' + id);
    return ((await r.json()).casamentos[0].definicoes || {})['media.hero'];
  }, cid);
  // Repor grava o valor de origem: guardarDefinicoes apaga a linha igual ao
  // padrão, por isso deixa de haver retoque — o efetivo passa a ser a de origem.
  ok(serv === undefined || serv === padraoHero,
     'no servidor, o retoque manual da foto foi largado (fica a de origem)');

  // limpeza
  await p.evaluate(async (id) => {
    await fetch('api.php?action=casamento_estado&id=' + id + '&estado=arquivado', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
    await fetch('api.php?action=casamento_apagar&id=' + id, { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, cid);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
