// Não se fecha a casa a quem tem licença em vigor.
//
// Suspender ou arquivar um casamento tira ao casal o que ele comprou — e sem
// lhe dizer nada: a página da licença continuava a mostrar-lha activa enquanto
// ele batia à porta e não entrava. As duas coisas ficavam em desacordo, e a
// culpa parecia ser dele.
//
// A ordem passa a ser uma só: decide-se a LICENÇA primeiro (revogar deixa um
// motivo escrito, que o casal lê), e só depois se fecha a porta. Uma licença
// que já expirou não trava nada — não está a dar nada a ninguém.
//
// Dois trincos, como sempre: a acção não aparece no menu, e a API recusa-a a
// quem a chamar à mão.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { execSync } = require('child_process');
const SOCK = process.env.DB_SOCK || '/run/mysqld/mysqld.sock';
const DB   = process.env.DB_NAME || 'wedding_guests';
const sql  = q => execSync(`mysql -uroot --socket=${SOCK} ${DB} -N -e ${JSON.stringify(q)}`).toString().trim();

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'tv' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  const cat = (await api('lic_catalogo')).catalogo;
  const conv = cat.modulos.find(m => m.chave === 'convidados').escaloes[0];

  let d = await api('casamento_criar', { nome: 'Travão ' + marca, data: '2027-08-14' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  d = await api('lic_conceder', { casamento: cid, escaloes: [conv.id], meses: 12 });
  ok(d && d.success, 'e deu-lhe licença por 12 meses');
  ok(sql(`SELECT licenca_estado FROM cw_casamentos WHERE id=${cid}`) === 'ativa',
     'a licença está em vigor');

  // ---------- 1. a API recusa ----------
  for (const est of ['suspenso', 'arquivado']) {
    const r = await api('casamento_estado&id=' + cid + '&estado=' + est, {});
    ok(r && r.success === false, `com licença em vigor, a API recusa passar a «${est}»`);
    ok(/licen/i.test(r.message || ''), 'e diz que é da licença que se trata');
    ok(/revogue|revogar|expire/i.test(r.message || ''),
       'dizendo o que fazer primeiro: revogar, ou esperar que expire');
  }
  ok(sql(`SELECT estado FROM cw_casamentos WHERE id=${cid}`) === 'ativo',
     'e o casamento continua aberto — a recusa não foi só uma mensagem');

  // ---------- 2. as acções não aparecem no menu ----------
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(900);
  await admin.evaluate(() => filtrarCasamentos('todos', 1));
  await admin.waitForTimeout(700);
  const menu = await admin.evaluate((id) => {
    const pop = document.getElementById('mm-' + id);
    if (!pop) return null;
    return { accoes: [...pop.querySelectorAll('button')].map(x => x.textContent.trim()),
             nota: (pop.querySelector('.mm-nota') || {}).textContent || '' };
  }, cid);
  ok(menu, 'a linha do casamento tem o seu menu de ações');
  ok(!menu.accoes.some(x => /^Suspender$|^Arquivar$/.test(x)),
     `suspender e arquivar não estão no menu (${menu.accoes.join(', ')})`);
  ok(/licen/i.test(menu.nota) && /revogue/i.test(menu.nota),
     'e no lugar delas está a explicação, com o caminho');
  ok(menu.accoes.some(x => /Revogar licença/.test(x)),
     'a revogação — que é o que se faz primeiro — continua à mão');

  // ---------- 3. revogada, a casa já se fecha ----------
  d = await api('lic_revogar', { casamento: cid, motivo: 'Prova do travão ' + marca });
  ok(d && d.success, 'revoga-se a licença, com motivo');
  d = await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  ok(d && d.success, 'e agora o casamento já se arquiva');
  ok(sql(`SELECT estado FROM cw_casamentos WHERE id=${cid}`) === 'arquivado',
     'e ficou mesmo arquivado');

  // ---------- 4. uma licença EXPIRADA não trava ----------
  d = await api('casamento_criar', { nome: 'Expirado ' + marca, data: '2027-08-15' });
  const cid2 = d.id;
  await api('lic_conceder', { casamento: cid2, escaloes: [conv.id], meses: 12 });
  // Põe-se-lhe o prazo no passado: é o que acontece a quem não renova.
  sql(`UPDATE cw_casamentos SET licenca_ate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=${cid2}`);
  d = await api('casamento_estado&id=' + cid2 + '&estado=suspenso', {});
  ok(d && d.success,
     'uma licença já expirada não trava nada — não está a dar nada a ninguém');

  // ---------- limpeza ----------
  for (const x of [cid, cid2]) {
    await api('lic_conceder', { casamento: x, escaloes: [], meses: 0 });
    await api('casamento_estado&id=' + x + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + x, {});
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
