// Repor os modelos que a casa traz de origem, apagados por lapso.
//
// O admin pode apagar um modelo da casa sem querer — ou até esvaziar a lista.
// A lista de modelos passa a dizer o que falta do catálogo de origem, e há como
// repô-lo (todos ou um a um) sem tocar nos modelos que o admin criou. E o
// ficheiro de origem de fábrica, esse, nunca se apaga de todo.
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
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  const lista = () => api('modelo_lista');
  const catalogoDe = d => d.catalogo || [];

  // ---------- 0. de origem, nada falta ----------
  let d = await lista();
  ok(Array.isArray(d.catalogo), 'a lista traz o catálogo dos modelos de origem, para o admin');
  ok(catalogoDe(d).length >= 6, 'o catálogo tem os modelos de origem da casa (' + catalogoDe(d).length + ')');
  ok(catalogoDe(d).every(c => c.em_falta === false), 'à partida, não falta nenhum');
  ok(catalogoDe(d).some(c => c.origem && c.nome === 'Isabel & Abednego'),
     'e o ficheiro de origem consta do catálogo, marcado como origem');

  // ---------- 1. apagar um modelo de origem apagável (Borgonha) ----------
  const borgonha = d.modelos.find(m => m.nome === 'Borgonha' && m.ambito === 'digital');
  ok(!!borgonha, 'a casa tem «Borgonha» (convite digital)');
  const ap = await api('modelo_apagar&id=' + borgonha.id, {});
  ok(ap && ap.success, 'o admin apaga «Borgonha» (não é peça de origem, é apagável)');

  d = await lista();
  const cBorg = catalogoDe(d).find(c => c.nome === 'Borgonha' && c.ambito === 'digital');
  ok(cBorg && cBorg.em_falta === true, 'a lista passa a assinalar que «Borgonha» falta');
  ok(!d.modelos.some(m => m.nome === 'Borgonha' && m.ambito === 'digital'),
     'e já não está entre os modelos');

  // ---------- 2. repor só esse ----------
  const r1 = await api('modelos_restaurar', { alvos: [{ ambito: 'digital', nome: 'Borgonha' }] });
  ok(r1 && r1.success && (r1.criados || []).some(s => /Borgonha/.test(s)),
     'repor «Borgonha» volta a semeá-lo');
  d = await lista();
  const volta = d.modelos.find(m => m.nome === 'Borgonha' && m.ambito === 'digital');
  ok(!!volta && +volta.visivel === 1 && volta.alcance === 'todos',
     'e ele reaparece, publicado e para todos os casais');
  ok(catalogoDe(d).find(c => c.nome === 'Borgonha' && c.ambito === 'digital').em_falta === false,
     'a lista deixa de o dar por falta');

  // ---------- 3. o ficheiro de origem de fábrica não se apaga ----------
  const isabel = d.modelos.find(m => m.nome === 'Isabel & Abednego' && m.ambito === 'digital');
  const apFab = await api('modelo_apagar&id=' + isabel.id, {});
  ok(apFab && apFab.success === false, 'apagar o ficheiro de origem de fábrica continua a ser recusado');

  // ---------- 4. apagar vários e repor todos de uma vez ----------
  const apagaveis = d.modelos.filter(m => !m.protegido &&
    ['Meia-noite', 'Terracota', 'Sálvia', 'Rosa velho'].includes(m.nome));
  for (const m of apagaveis) await api('modelo_apagar&id=' + m.id, {});
  d = await lista();
  const faltamAntes = catalogoDe(d).filter(c => c.em_falta).length;
  ok(faltamAntes >= apagaveis.length, 'vários modelos de origem passam a faltar (' + faltamAntes + ')');

  const rAll = await api('modelos_restaurar', {});   // sem alvos: repõe tudo o que falta
  ok(rAll && rAll.success && (rAll.criados || []).length === faltamAntes,
     'repor todos semeia exatamente os que faltavam (' + (rAll.criados || []).length + ')');
  ok(catalogoDe(rAll).every(c => c.em_falta === false),
     'e a resposta confirma que já não falta nenhum');

  // ---------- 5. repor é inócuo quando não falta nada ----------
  const r0 = await api('modelos_restaurar', {});
  ok(r0 && r0.success && (r0.criados || []).length === 0,
     'repor de novo não duplica nada — não faltava nenhum');
  d = await lista();
  ok(catalogoDe(d).every(c => c.em_falta === false), 'a lista fica com o catálogo completo');

  // ---------- 6. só o admin restaura ----------
  const casal = await api('casamento_criar', { nome: 'ZZ Rest ' + Date.now().toString().slice(-5),
                                               noiva: 'Rita', noivo: 'Rui' });
  const email = 'rest' + Date.now().toString().slice(-6) + '@ex.pt';
  await api('utilizador_criar', { email, nome: 'Casal Rest', senha: 'segredo12345',
                                  casamento_id: casal.id, papel: 'noivos' });
  const ctx2 = await b.newContext(); const q = await ctx2.newPage();
  await q.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await q.fill('input[name=utilizador]', email); await q.fill('input[name=senha]', 'segredo12345');
  await q.click('button[type=submit]'); await q.waitForLoadState('networkidle');
  const rNeg = await q.evaluate(async () => {
    const r = await fetch('api.php?action=modelos_restaurar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' }, body: '{}' });
    return r.json();
  });
  ok(rNeg && rNeg.success === false, 'um casal não pode restaurar modelos — é do admin');
  const dCasal = await q.evaluate(async () =>
    (await fetch('api.php?action=modelo_lista', { headers: { 'X-CSRF-Token': window.CSRF } })).json());
  ok(dCasal.catalogo === undefined, 'e o catálogo de origem nem lhe é mostrado');

  // limpeza
  await api('casamento_estado&id=' + casal.id + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + casal.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
