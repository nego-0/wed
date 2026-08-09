// Plataforma: vários casamentos, várias contas, e cada um no seu lugar.
//
// A prova mais importante desta etapa não é a página bonita: é a fechadura.
// Um casal não pode entrar no casamento de outro escrevendo outro número no
// endereço, e não pode criar casamentos nem contas.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: 'POST', headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  // ---------- o admin da plataforma prepara o terreno ----------
  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  const casA = await api('casamento_criar', { nome: 'ZZ Casamento A ' + marca, noiva: 'Ana', noivo: 'Alberto' });
  const casB = await api('casamento_criar', { nome: 'ZZ Casamento B ' + marca, noiva: 'Bia', noivo: 'Bruno' });
  ok(casA.success && casB.success, 'o admin da plataforma cria casamentos');

  const emailA = 'noivos.a.' + marca + '@exemplo.pt';
  const contaA = await api('utilizador_criar', { email: emailA, nome: 'Noivos A', senha: 'segredo12345',
                                                 casamento_id: casA.id, papel: 'noivos' });
  ok(contaA.success, 'e cria a conta dos noivos, já ligada ao casamento deles');

  // Um convite em cada casamento, para haver o que ver (ou não ver).
  await api('casamento_abrir&id=' + casA.id);
  await api('convite_save', { nome_exibicao: 'ZZ Só do A', tipo: 'digital', lado: 'ambos', membros: ['Alguém A'] });
  await api('casamento_abrir&id=' + casB.id);
  await api('convite_save', { nome_exibicao: 'ZZ Só do B', tipo: 'digital', lado: 'ambos', membros: ['Alguém B'] });

  // A plataforma vê os dois na página.
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const txtAdmin = await admin.locator('body').innerText();
  ok(txtAdmin.includes('ZZ Casamento A ' + marca) && txtAdmin.includes('ZZ Casamento B ' + marca),
     'o pessoal da plataforma vê todos os casamentos na página');

  // ---------- os noivos de A: só o que é deles ----------
  const noivosA = await entrar(await b.newContext(), emailA, 'segredo12345');
  ok(noivosA.url().includes('index.php') || noivosA.url().includes('plataforma.php'),
     'os noivos entram com o seu email');

  const listaA = await noivosA._api('convite_list');
  const nomesA = (listaA.convites || []).map(c => c.nome_exibicao);
  console.log('   convites vistos pelos noivos A:', JSON.stringify(nomesA));
  ok(nomesA.includes('ZZ Só do A'), 'os noivos de A veem o convite de A');
  ok(!nomesA.includes('ZZ Só do B'), 'e não veem o de B');

  // A fechadura: tentar abrir o casamento do outro casal.
  const invasao = await noivosA._api('casamento_abrir&id=' + casB.id);
  console.log('   tentativa de abrir o casamento alheio:', JSON.stringify(invasao));
  ok(invasao && invasao.success === false, 'os noivos de A NÃO conseguem abrir o casamento de B');

  // E, mesmo depois da tentativa, continuam a ver só o que é deles.
  const depois = await noivosA._api('convite_list');
  ok(!(depois.convites || []).some(c => c.nome_exibicao === 'ZZ Só do B'),
     'e depois da tentativa continuam a ver apenas o seu casamento');

  // Não são da casa: não criam casamentos nem contas.
  const tentaCriar = await noivosA._api('casamento_criar', { nome: 'ZZ Intruso' });
  ok(tentaCriar && tentaCriar.success === false, 'os noivos não criam casamentos');
  const tentaConta = await noivosA._api('utilizador_criar',
    { email: 'x' + marca + '@exemplo.pt', nome: 'X', senha: 'segredo12345' });
  ok(tentaConta && tentaConta.success === false, 'nem contas');

  // A página da plataforma, para eles, mostra só o seu casamento.
  await noivosA.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const txtA = await noivosA.locator('body').innerText();
  ok(!txtA.includes('ZZ Casamento B ' + marca), 'a página de casamentos não lhes mostra o casamento alheio');
  ok(!txtA.includes('Novo casamento'), 'nem o painel de criar casamentos');

  // ---------- aprovar um registo pendente ----------
  const pend = await api('casamento_criar', { nome: 'ZZ Pendente ' + marca });
  await api('casamento_estado&id=' + pend.id + '&estado=pendente');
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  ok((await admin.locator('body').innerText()).includes('à espera de aprovação'),
     'um registo pendente aparece na fila de aprovação');
  const apr = await api('casamento_estado&id=' + pend.id + '&estado=ativo');
  console.log('   aprovação:', JSON.stringify(apr), '| id pendente:', pend.id);
  ok(apr && apr.success, 'o admin aprova-o');

  // ---------- o cabeçalho diz sempre onde se está ----------
  await api('casamento_abrir&id=' + casA.id);
  await admin.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const cab = await admin.locator('header').innerText();
  console.log('   cabeçalho:', cab.replace(/\s+/g, ' '));
  console.log('   esperava:', 'ZZ Casamento A ' + marca, '| casA.id =', casA.id);
  ok(/A trabalhar em/.test(cab) && cab.includes('ZZ Casamento A ' + marca),
     'o cabeçalho nomeia o casamento aberto, para não se editar o casal errado');

  // ---------- limpeza ----------
  // Primeiro os casamentos (que levam com eles os lugares), e só depois a
  // conta — que a esta altura já não pertence a casamento nenhum. Sem isto,
  // cada corrida deixava mais uma conta de mentira na base.
  await api('casamento_abrir&id=1');
  for (const id of [casA.id, casB.id, pend.id]) await api('casamento_apagar&id=' + id);
  const limpaConta = await api('utilizador_apagar&id=' + contaA.id);
  ok(limpaConta && limpaConta.success, 'a conta de prova, já sem casamento, apaga-se');

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
