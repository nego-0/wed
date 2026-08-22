// A licença de uso e as contas do casamento.
//
// Prova, de fora: o admin cria um casamento já com as contas dos noivos e do
// porteiro e um período de licença; suspender/arquivar o casamento fecha a porta
// a essas contas, reativar volta a abri-la; as contas administrativas contam-se
// à parte das do casamento; e uma licença vencida suspende o casamento sozinha.
const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const SOCK = process.env.DB_SOCK || '/run/mysqld/mysqld.sock';
const DB   = process.env.DB_NAME || 'wedding_guests';
const sql  = q => execSync(`mysql -uroot --socket=${SOCK} ${DB} -N -e ${JSON.stringify(q)}`).toString().trim();

const entrar = async (ctx, u, s) => {
  const g = await ctx.newPage();
  await g.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await g.fill('input[name=utilizador]', u); await g.fill('input[name=senha]', s);
  await g.click('button[type=submit]'); await g.waitForLoadState('networkidle');
  g._api = (a, c) => g.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  g._entrou = () => g.evaluate(() => !!window.CSRF && !/name=.?utilizador/.test(document.body.innerHTML));
  return g;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + Date.now().toString().slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. criar um casamento com contas e licença ----------
  const noivosEmail = 'noivos' + marca + '@ex.pt';
  const portEmail   = 'porta' + marca + '@ex.pt';
  const cr = await api('casamento_criar', {
    nome: 'ZZ Licença ' + marca, noiva: 'Lia', noivo: 'Leo',
    licenca_meses: 6, licenca_ativa: true,
    noivos_email: noivosEmail, noivos_senha: 'segredo12345',
    porteiro_email: portEmail, porteiro_senha: 'segredo12345',
  });
  ok(cr && cr.success, 'o admin cria o casamento');
  ok(cr.licenca && cr.licenca.iniciada && cr.licenca.meses === 6 && !cr.licenca.expirada,
     'a licença nasce ativa, de 6 meses, com data de fim');
  ok(cr.contas && cr.contas.noivos && cr.contas.noivos.email === noivosEmail,
     'a conta dos noivos foi criada e ligada');
  ok(cr.contas && cr.contas.porteiro && cr.contas.porteiro.email === portEmail,
     'a conta do porteiro foi criada e ligada');
  const cid = cr.id;

  // Os noivos entram já.
  let noivos = await entrar(await b.newContext(), noivosEmail, 'segredo12345');
  ok(await noivos._entrou(), 'os noivos entram com a conta criada');
  // E o cabeçalho diz o que resta da licença.
  await noivos.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const cab = (await noivos.textContent('.licenca-restante').catch(() => '')) || '';
  console.log('   cabeçalho licença:', JSON.stringify(cab.replace(/\s+/g, ' ').trim()));
  ok(/licença/i.test(cab), 'o cabeçalho dos noivos mostra o que resta da licença');
  await noivos.context().close();

  // ---------- 2. contas administrativas contam-se à parte ----------
  const plat = (await api('utilizador_lista&tipo=plataforma')).contas || [];
  ok(plat.length > 0 && plat.every(c => c.papel_plataforma),
     'a lista administrativa só traz contas admin/suporte');
  ok(!plat.some(c => c.email === noivosEmail || c.email === portEmail),
     'e não mistura lá as contas de noivos/porteiro');
  const doCas = (await api('utilizador_lista&tipo=casamento')).contas || [];
  ok(doCas.some(c => c.email === noivosEmail) && doCas.some(c => c.email === portEmail),
     'as contas do casamento aparecem na lista de casamento');

  // ---------- 3. suspender o casamento fecha a porta às contas ----------
  const susp = await api('casamento_estado&id=' + cid + '&estado=suspenso', {});
  ok(susp && susp.success && susp.contas_paradas >= 2,
     'suspender o casamento para as contas dele (' + (susp && susp.contas_paradas) + ')');
  let tent = await entrar(await b.newContext(), noivosEmail, 'segredo12345');
  ok(!(await tent._entrou()), 'com o casamento suspenso, os noivos já não entram');
  await tent.context().close();

  // ---------- 4. reativar devolve as contas ----------
  const rea = await api('casamento_estado&id=' + cid + '&estado=ativo', {});
  ok(rea && rea.success && rea.contas_ativadas >= 2,
     'reativar o casamento devolve as contas (' + (rea && rea.contas_ativadas) + ')');
  tent = await entrar(await b.newContext(), noivosEmail, 'segredo12345');
  ok(await tent._entrou(), 'reativado, os noivos voltam a entrar');
  await tent.context().close();

  // ---------- 5. licença vencida suspende o casamento sozinha ----------
  // Recua-se a data de fim para ontem, e um login qualquer dispara a varredura.
  sql(`UPDATE cw_casamentos SET licenca_ate = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id=${cid}`);
  await (await entrar(await b.newContext(), 'admin', 'noivos2026')).context().close();  // dispara o sweep
  const est = sql(`SELECT estado FROM cw_casamentos WHERE id=${cid}`);
  ok(est === 'suspenso', 'a licença vencida deixou o casamento suspenso (' + est + ')');
  const tent2 = await entrar(await b.newContext(), noivosEmail, 'segredo12345');
  ok(!(await tent2._entrou()), 'e os noivos, com a licença vencida, não entram');
  await tent2.context().close();

  // ---------- 6. registo público com licença desejada e porteiro ----------
  const regEmail = 'reg' + marca + '@ex.pt';
  const regPort  = 'regporta' + marca + '@ex.pt';
  const pub = await admin.evaluate(async (d) => {
    const r = await fetch('api.php?action=registo_publico', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) });
    return r.json();
  }, { noiva: 'Rosa', noivo: 'Rui', email: regEmail, senha: 'segredo12345',
       licenca_meses: 12, porteiro_email: regPort, porteiro_senha: 'segredo12345' });
  ok(pub && pub.success, 'o registo público entra na fila');
  const regCid = pub.casamento;
  const linha = sql(`SELECT CONCAT(estado,'|',licenca_meses,'|',IFNULL(licenca_ate,'NULL')) FROM cw_casamentos WHERE id=${regCid}`);
  console.log('   registo:', linha);
  ok(/^pendente\|12\|NULL$/.test(linha),
     'guarda o período desejado (12) mas o relógio só arranca na aprovação');
  const portReg = sql(`SELECT estado FROM cw_utilizadores WHERE email='${regPort}'`);
  ok(portReg === 'pendente', 'a conta do porteiro fica pendente com o casamento');

  // Aprovar arranca a licença e ativa as contas.
  const apr = await api('casamento_estado&id=' + regCid + '&estado=ativo', {});
  ok(apr && apr.success, 'o admin aprova o registo');
  const linha2 = sql(`SELECT CONCAT(estado,'|',IF(licenca_ate IS NULL,'NULL','set')) FROM cw_casamentos WHERE id=${regCid}`);
  ok(linha2 === 'ativo|set', 'aprovado, o casamento fica ativo e a licença começa a contar');
  ok(sql(`SELECT estado FROM cw_utilizadores WHERE email='${regEmail}'`) === 'ativo',
     'e a conta dos noivos passa a ativa');

  // ---------- limpeza ----------
  for (const x of [cid, regCid]) {
    await api('casamento_estado&id=' + x + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + x, {});
  }
  sql(`DELETE FROM cw_utilizadores WHERE email IN ('${noivosEmail}','${portEmail}','${regEmail}','${regPort}')`);

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
