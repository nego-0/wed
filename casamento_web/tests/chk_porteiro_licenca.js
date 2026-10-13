// Sem porta para guardar, não se cria porteiro.
//
// O «Controlo à porta» passou a ser um módulo à parte, mas a conta do porteiro
// continuava a criar-se sempre que alguém escrevesse um email no formulário —
// independentemente do plano. O casal acabava com uma conta a mais na equipa,
// com uma senha a circular, para entrar numa página que a licença não abre. E
// pior: parecia que tinha comprado o controlo à porta.
//
// A regra é uma só, e vale nas três portas por onde uma conta de porteiro
// nasce — o registo público, a criação pela administração, e a edição da ficha.
// Como sempre, dois trincos: o formulário não pede o que não pode dar, e a API
// recusa (ou ignora) quem a chame à mão.
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
  const marca = 'pt' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  const cat = (await api('lic_catalogo')).catalogo;
  const esc = (m) => cat.modulos.find(x => x.chave === m).escaloes[0].id;
  const escConv = esc('convidados'), escPorta = esc('porta');
  const registar = (dados) => admin.evaluate(async (d) => {
    const r = await fetch('api.php?action=registo_publico', { method: 'POST',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) });
    return r.json();
  }, dados);
  const contaDe = (email) => sql(`SELECT COUNT(*) FROM cw_utilizadores WHERE email='${email}'`);
  const limpar  = [];

  // ---------- 1. registo público SEM a porta no plano ----------
  const semEmail = 'sem' + marca + '@ex.pt', semPorta = 'semporta' + marca + '@ex.pt';
  let d = await registar({ noiva: 'Sara', noivo: 'Simão', email: semEmail, senha: 'segredo12345',
    porteiro_email: semPorta, porteiro_senha: 'segredo12345',
    licenca: { pacote: 0, escaloes: [escConv], meses: 12, aceito: true } });
  ok(d && d.success, 'o registo passa — a inscrição não fica refém disto');
  limpar.push(d.casamento);
  ok(d.porteiro_ignorado === true,
     'mas responde que a conta de porteiro não se criou, para ninguém ficar à espera dela');
  ok(contaDe(semPorta) === '0', 'e não há conta de porteiro nenhuma na base');
  ok(contaDe(semEmail) === '1', 'a conta do casal, essa, criou-se — o registo não se perdeu');

  // ---------- 2. o mesmo registo COM a porta no plano ----------
  // Noutro browser: um registo por sessão é o que o travão do servidor permite,
  // e é também o que a vida faz — dois casais não se inscrevem no mesmo separador.
  const outro = await (await b.newContext()).newPage();
  await outro.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  const registar2 = (dados) => outro.evaluate(async (dd) => {
    const r = await fetch('api.php?action=registo_publico', { method: 'POST',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dd) });
    return r.json();
  }, dados);
  const comEmail = 'com' + marca + '@ex.pt', comPorta = 'comporta' + marca + '@ex.pt';
  d = await registar2({ noiva: 'Rita', noivo: 'Rui', email: comEmail, senha: 'segredo12345',
    porteiro_email: comPorta, porteiro_senha: 'segredo12345',
    licenca: { pacote: 0, escaloes: [escConv, escPorta], meses: 12, aceito: true } });
  ok(d && d.success, 'com o módulo da porta no plano, o registo passa igual: ' + JSON.stringify(d).slice(0,120));
  limpar.push(d.casamento);
  ok(!d.porteiro_ignorado, 'e desta vez nada se ignora');
  ok(contaDe(comPorta) === '1', 'a conta do porteiro existe');
  ok(sql(`SELECT estado FROM cw_utilizadores WHERE email='${comPorta}'`) === 'pendente',
     'pendente, como o casamento — abre quando a licença abrir');

  // ---------- 3. a administração, ao criar, é avisada em vez de ignorada ----------
  // Aqui não se cala: quem responde pela casa escreveu o email de propósito, e
  // deixá-lo cair sem uma palavra era pior do que recusar.
  const admPorta = 'admporta' + marca + '@ex.pt';
  d = await api('casamento_criar', { nome: 'Sem porta ' + marca, data: '2027-09-04',
    escaloes: [escConv], porteiro_email: admPorta, porteiro_senha: 'segredo12345' });
  ok(d && d.success === false, 'criar um casamento com porteiro e sem o módulo é recusado');
  ok(/porta/i.test(d.message || '') && /módulo|modulo/i.test(d.message || ''),
     'e a recusa diz qual é o módulo que falta: ' + (d.message || '').slice(0, 80));
  ok(contaDe(admPorta) === '0', 'a conta não se criou');
  ok(sql(`SELECT COUNT(*) FROM cw_casamentos WHERE nome='Sem porta ${marca}'`) === '0',
     'e o casamento também não — a recusa vem antes de se criar seja o que for');

  // Com o módulo, o mesmo pedido passa.
  d = await api('casamento_criar', { nome: 'Com porta ' + marca, data: '2027-09-05',
    escaloes: [escConv, escPorta], porteiro_email: admPorta, porteiro_senha: 'segredo12345' });
  ok(d && d.success, 'com o módulo, o mesmo pedido passa');
  const cidOk = d.id; limpar.push(cidOk);
  ok(contaDe(admPorta) === '1', 'e a conta do porteiro criou-se');

  // Sem escalões nenhuns, a licença nasce completa: a porta vem lá dentro.
  const tudoPorta = 'tudoporta' + marca + '@ex.pt';
  d = await api('casamento_criar', { nome: 'Tudo ' + marca, data: '2027-09-06',
    porteiro_email: tudoPorta, porteiro_senha: 'segredo12345' });
  ok(d && d.success && contaDe(tudoPorta) === '1',
     'e um casamento criado sem escalões nasce com tudo — incluindo a porta');
  limpar.push(d.id);

  // ---------- 4. editar a ficha segue a mesma regra ----------
  const fichaPorta = 'fichaporta' + marca + '@ex.pt';
  d = await api('casamento_criar', { nome: 'Ficha ' + marca, data: '2027-09-07', escaloes: [escConv] });
  const cidFicha = d.id; limpar.push(cidFicha);
  d = await api('casamento_editar', { id: cidFicha, noiva: 'Fê', noivo: 'Fá',
    porteiro_email: fichaPorta, porteiro_senha: 'segredo12345' });
  ok(d && d.success === false, 'juntar um porteiro pela ficha, sem o módulo, é recusado');
  ok(contaDe(fichaPorta) === '0', 'e não fica conta nenhuma pelo caminho');
  await api('lic_conceder', { casamento: cidFicha, escaloes: [escConv, escPorta], meses: 12 });
  d = await api('casamento_editar', { id: cidFicha, noiva: 'Fê', noivo: 'Fá',
    porteiro_email: fichaPorta, porteiro_senha: 'segredo12345' });
  ok(d && d.success && contaDe(fichaPorta) === '1',
     'concedido o módulo, a mesma ficha já cria a conta: ' + JSON.stringify(d).slice(0,140));

  // ---------- 5. o formulário não pede o que não pode dar ----------
  const pub = await (await b.newContext()).newPage();
  pub.on('pageerror', e => errs.push('registo: ' + e.message));
  await pub.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await pub.waitForTimeout(1200);
  const escondido = () => pub.evaluate(() => {
    const el = document.getElementById('bloco-porteiro');
    return el ? el.hidden : null;
  });
  // Monta-se o plano à medida, como o casal monta: abre-se a secção e escolhe-se
  // o escalão da porta na sua lista.
  await pub.click('#pl-abrir-medida');
  await pub.waitForTimeout(500);
  await pub.click('#reg-planos input[name="pl-porta"][value="0"]');
  await pub.waitForTimeout(400);
  ok(await escondido() === true,
     'num plano sem o «Controlo à porta», o formulário nem mostra a conta do porteiro');
  await pub.click('#reg-planos input[name="pl-porta"][value="' + escPorta + '"]');
  await pub.waitForTimeout(500);
  ok(await escondido() === false,
     'ao juntar o «Controlo à porta» ao plano, o bloco do porteiro aparece');

  // Escreve-se lá qualquer coisa, para se ver que ela não fica a viajar.
  await pub.evaluate(() => { document.getElementById('bloco-porteiro').open = true; });
  await pub.fill('#porteiro_email', 'porta.' + marca + '@ex.pt');
  await pub.click('#reg-planos input[name="pl-porta"][value="0"]');
  await pub.waitForTimeout(500);
  ok(await escondido() === true, 'e ao tirá-lo do plano volta a desaparecer');
  ok(await pub.evaluate(() => document.getElementById('porteiro_email').value) === '',
     'levando consigo o que lá estivesse escrito, para não viajar escondido no pedido');

  // ---------- limpeza ----------
  for (const x of limpar) {
    if (!x) continue;
    await api('lic_revogar', { casamento: x, motivo: 'Fim da prova ' + marca });
    await api('casamento_estado&id=' + x + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + x, {});
  }
  sql(`DELETE FROM cw_utilizadores WHERE email LIKE '%${marca}@ex.pt'`);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
