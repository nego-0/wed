// O botão «Guardar» dos editores dos noivos, agora ciente do estado.
//
// • Sem versão sua em vigor → «Guardar Como» (nasce uma versão com nome).
// • Com versão sua em vigor  → «Actualizar» (actualiza essa versão, sem duplicar).
// • Só o admin, no editor de um MODELO da casa, é que vê «Guardar» (grava direto).
// Vale para o convite digital e para o cartão impresso.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let NOME = 'Nossa versão';
  p.on('dialog', d => d.accept(d.type() === 'prompt' ? NOME : undefined));
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
  const rot = () => p.textContent('#bt-guardar').then(s => (s || '').trim());
  const propriasDig = () => api('versao_lista&ambito=digital').then(d => (d.versoes || []).filter(v => !v.padrao));

  const w = await api('casamento_criar', { nome: 'ZZ Guardar ' + Date.now(), noiva: 'Ana', noivo: 'Beto' });
  await api('casamento_abrir&id=' + w.id, {});

  // ---------- convite digital ----------
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  ok(await rot() === 'Guardar Como', 'sem versão sua, o botão diz «Guardar Como» — ' + JSON.stringify(await rot()));

  await p.evaluate(() => { EST.val['textos.kicker'] = 'KICK ' + Date.now(); marcarSujo(true); });
  await p.evaluate(() => guardar()); await p.waitForTimeout(1200);
  ok(await rot() === 'Actualizar', 'depois de guardar, passa a «Actualizar»');
  let vs = await propriasDig();
  ok(vs.length === 1 && vs[0].nome === NOME && vs[0].em_vigor, 'nasceu uma versão do casal, em vigor');

  await p.evaluate(() => { EST.val['textos.kicker'] = 'KICK2 ' + Date.now(); marcarSujo(true); });
  await p.evaluate(() => guardar()); await p.waitForTimeout(1200);
  ok(await rot() === 'Actualizar', 'continua «Actualizar»');
  vs = await propriasDig();
  ok(vs.length === 1, 'e não se duplicou a versão — actualizou a que estava em vigor');
  ok(vs[0].em_vigor, 'a versão do casal continua em vigor após actualizar');

  // ---------- cartão impresso ----------
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  ok(await rot() === 'Guardar Como', 'no cartão, sem versão sua, também diz «Guardar Como»');
  await p.evaluate(() => { est.textos['cartao.reservado'] = 'R ' + Date.now(); marcarSujo(true); });
  await p.evaluate(() => guardar()); await p.waitForTimeout(1400);
  ok(await rot() === 'Actualizar', 'e passa a «Actualizar» depois de guardar');
  const vsImp = (await api('versao_lista&ambito=impresso')).versoes.filter(v => !v.padrao);
  ok(vsImp.length === 1 && vsImp[0].em_vigor, 'nasceu a versão do cartão, em vigor');

  // ---------- editor de um MODELO da casa: «Guardar» ----------
  const mod = await api('modelo_criar', { nome: 'ZZ Mod ' + Date.now(), descricao: 'x', ambito: 'digital', visivel: true });
  await p.goto(BASE + '/convite-editor.php?modelo=' + mod.id, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  ok(await rot() === 'Guardar', 'no editor de um modelo da casa (admin), o botão diz «Guardar»');

  // limpeza
  await api('modelo_apagar&id=' + mod.id, {});
  await api('casamento_estado&id=' + w.id + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + w.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
