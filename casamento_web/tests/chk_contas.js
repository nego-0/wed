// Registo público, aprovação, equipa e códigos de suporte.
//
// A etapa 5 é sobre quem entra e com que chave. O que aqui se prova não é o
// desenho das páginas: são as recusas.
//
//   1. quem se inscreve NÃO entra enquanto o admin não aprovar;
//   2. aprovar o casamento abre também a conta de quem se inscreveu;
//   3. o suporte não chega a casamento nenhum sem um código do casal;
//   4. um código de "ver" deixa ver e NÃO deixa mexer;
//   5. revogar um código fecha a porta, mesmo a quem já lá estava;
//   6. o casal gere a sua própria equipa, e não fica sem quem o gere;
//   7. uma conta suspensa deixa de entrar.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const comApi = (p) => {
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: 'POST', headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  return comApi(p);
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);
  const SENHA = 'segredo12345';

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. registo público ----------
  const emailCasal = 'casal.' + marca + '@exemplo.pt';
  const reg = await (await (await b.newContext()).newPage()).goto(BASE + '/registo.php');
  ok(reg.status() === 200, 'a página de inscrição está de pé e é pública');

  const anon = await (await b.newContext()).newPage();
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await anon.fill('#noiva', 'Nadia' + marca); await anon.fill('#noivo', 'Nuno' + marca);
  await anon.fill('#email', emailCasal); await anon.fill('#senha', SENHA);
  // Os dados do evento perguntam-se aqui, no primeiro registo — é o que evita
  // um casamento a viver meses com a morada do casal de origem.
  await anon.fill('#data', '2027-03-20'); await anon.fill('#hora', '18:30');
  await anon.fill('#local', 'Salão ' + marca); await anon.fill('#cidade', 'Huambo');
  await anon.fill('#convidados', '80'); await anon.fill('#whatsapp', '244911000111');
  await anon.fill('#religiosa_hora', '15:00'); await anon.fill('#religiosa_local', 'Igreja ' + marca);
  await anon.click('#btn'); await anon.waitForTimeout(900);
  ok(await anon.locator('#obrigado').isVisible(), 'a inscrição confirma no ecrã que ficou à espera');

  // A conta existe — mas não entra.
  const tenta = await entrar(await b.newContext(), emailCasal, SENHA);
  ok(tenta.url().includes('login.php'), 'quem se inscreveu NÃO entra antes de ser aprovado');

  // ---------- 2. o admin aprova, e a conta abre-se com o casamento ----------
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const txtFila = await admin.locator('body').innerText();
  ok(txtFila.includes('Nadia' + marca), 'a inscrição aparece na fila de aprovação do admin');

  const contas = await api('utilizador_lista&q=' + marca);
  const conta = (contas.contas || []).find(c => c.email === emailCasal);
  ok(conta && conta.estado === 'pendente', 'e a conta está mesmo "pendente" na lista de contas');

  const casamentos = await api('casamento_estado&id=0&estado=ativo');   // id inválido, só para ver que recusa
  ok(casamentos && casamentos.success === false, 'aprovar um casamento que não existe é recusado');

  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const idPend = await admin.evaluate((m) => {
    const cx = [...document.querySelectorAll('.cas')].find(e => e.innerText.includes(m));
    const b = cx && cx.querySelector('button');
    return b ? b.getAttribute('onclick').match(/\d+/)[0] : null;
  }, 'Nadia' + marca);
  const apr = await api('casamento_estado&id=' + idPend + '&estado=ativo');
  console.log('   aprovação:', JSON.stringify(apr));
  ok(apr && apr.success && apr.contas_ativadas >= 1,
     'aprovar o casamento ativa também a conta de quem se inscreveu');

  const casal = await entrar(await b.newContext(), emailCasal, SENHA);
  ok(!casal.url().includes('login.php'), 'e o casal passa a entrar');

  // E entra num casamento que já é o SEU: com o que escreveu na inscrição.
  await casal.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  const meus = await casal.evaluate(() => {
    const v = {}; document.querySelectorAll('[data-chave]').forEach(e => { v[e.dataset.chave] = e.value; });
    v['_noiva'] = document.getElementById('f-noiva').value;
    v['_data']  = document.getElementById('f-data').value;
    return v;
  });
  console.log('   o que o casal encontra:', JSON.stringify(meus).slice(0, 200));
  ok(meus._noiva === 'Nadia' + marca && meus._data === '2027-03-20', 'a ficha é a que ele escreveu');
  ok(meus['evento.local'] === 'Salão ' + marca && meus['evento.cidade'] === 'Huambo',
     'o local e a cidade também');
  ok(meus['evento.convidados'] === '80', 'e quantos convidados espera');
  ok(meus['evento.religiosa_hora'] === '15:00', 'a cerimónia religiosa que indicou');
  ok(meus['evento.civil_hora'] !== '' && meus['evento.civil_local'] === '',
     'e o que não indicou fica no original, sem inventar nada');

  // ---------- 3. o suporte não entra sem código ----------
  const emailSup = 'suporte.' + marca + '@exemplo.pt';
  await api('utilizador_criar', { email: emailSup, nome: 'Suporte ' + marca,
                                  senha: SENHA, papel_plataforma: 'suporte' });
  const sup = await entrar(await b.newContext(), emailSup, SENHA);
  console.log('   o suporte aterra em:', sup.url().replace(BASE, ''));
  ok(sup.url().includes('plataforma.php'), 'o suporte entra, mas na página dos casamentos');

  const listaSup = await sup._api('convite_list');
  console.log('   o suporte a pedir convites sem código:', JSON.stringify(listaSup).slice(0, 90));
  ok(listaSup && listaSup.success === false, 'e sem código não chega a convite nenhum');

  const semCodigo = await sup._api('casamento_abrir&id=' + idPend);
  ok(semCodigo && semCodigo.success === false, 'nem abre o casamento escrevendo-lhe o número');

  // ---------- 4. um código de "ver" deixa ver e não deixa mexer ----------
  const cod = await casal._api('suporte_codigo_criar', { pode_corrigir: false, dias: 7 });
  ok(cod && cod.success && cod.codigo && cod.codigo.length === 8, 'o casal gera um código de leitura');

  const entrou = await sup._api('suporte_entrar', { codigo: cod.codigo });
  console.log('   suporte com código:', JSON.stringify(entrou));
  ok(entrou && entrou.success, 'com o código, o suporte entra no casamento do casal');

  const veConvites = await sup._api('convite_list');
  ok(veConvites && veConvites.success, 'e passa a ver a casa toda');

  const tentaMexer = await sup._api('convite_save',
    { nome_exibicao: 'ZZ Intruso ' + marca, tipo: 'digital', lado: 'ambos', membros: ['X'] });
  console.log('   suporte de leitura a tentar gravar:', JSON.stringify(tentaMexer).slice(0, 120));
  ok(tentaMexer && tentaMexer.success === false, 'mas com um código de LEITURA não grava nada');

  const tentaDefs = await sup._api('defs_save', { defs: { 'casal.noiva': 'Alterado' + marca } });
  ok(tentaDefs && tentaDefs.success === false, 'nem mexe no desenho do convite');

  await sup.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok((await sup.locator('body').innerText()).includes('Visita de suporte'),
     'e o cabeçalho não deixa esquecer que está em casa alheia');

  // ---------- 5. revogar fecha a porta a quem já lá estava ----------
  const codigos = await casal._api('suporte_codigo_lista');
  const oNosso = (codigos.codigos || []).find(c => c.codigo === cod.codigo);
  const rev = await casal._api('suporte_codigo_revogar&id=' + oNosso.id);
  ok(rev && rev.success, 'o casal revoga o código');

  const depoisRevogado = await sup._api('suporte_entrar', { codigo: cod.codigo });
  ok(depoisRevogado && depoisRevogado.success === false, 'e o código revogado deixa de abrir a porta');

  // O que mais interessa: quem JÁ estava lá dentro é posto na rua no pedido
  // seguinte. Revogar tem de querer dizer agora, e não da próxima vez que a
  // pessoa fechar a sessão.
  const aindaLaDentro = await sup._api('convite_list');
  console.log('   suporte depois de revogado:', JSON.stringify(aindaLaDentro).slice(0, 90));
  ok(aindaLaDentro && aindaLaDentro.success === false,
     'e quem já lá estava deixa de ver a casa no pedido seguinte');

  // ---------- 5b. e um código de correção deixa mesmo corrigir ----------
  // Se um código de "ver e corrigir" não deixasse corrigir, a escolha que o
  // casal faz ao gerá-lo não queria dizer nada.
  const cod2 = await casal._api('suporte_codigo_criar', { pode_corrigir: true, dias: 7 });
  await sup._api('suporte_entrar', { codigo: cod2.codigo });
  const corrigiu = await sup._api('convite_save',
    { nome_exibicao: 'ZZ Corrigido ' + marca, tipo: 'digital', lado: 'ambos', membros: ['Y'] });
  ok(corrigiu && corrigiu.success, 'com um código de CORREÇÃO, o suporte já grava');
  if (corrigiu && corrigiu.convite) await sup._api('convite_delete&id=' + corrigiu.convite.id);
  await sup._api('suporte_sair');
  const saiu = await sup._api('convite_list');
  ok(saiu && saiu.success === false, 'e ao terminar a visita fecha-se logo a porta');

  // ---------- 6. o casal gere a sua equipa ----------
  const emailPorteiro = 'porta.' + marca + '@exemplo.pt';
  const conv = await casal._api('acesso_convidar', { email: emailPorteiro, papel: 'porteiro' });
  console.log('   convite ao porteiro:', JSON.stringify({ ...conv, senha: conv.senha ? '(senha temporária)' : '' }));
  ok(conv && conv.success && conv.senha, 'o casal convida um porteiro, e recebe a senha temporária para lha dar');

  const porteiro = await entrar(await b.newContext(), emailPorteiro, conv.senha);
  ok(porteiro.url().includes('porteiro.php'), 'o porteiro entra, e vai direito à porta');
  const porteiroTenta = await porteiro._api('convite_list');
  ok(porteiroTenta && porteiroTenta.success === false, 'e não chega ao painel dos noivos');

  // O porteiro chega à página da Equipa (é lá que muda a sua senha), mas o que
  // lá vê é só a sua conta — nem a equipa, nem os códigos, nem os dados do evento.
  await porteiro.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  const txtEq = await porteiro.locator('body').innerText();
  ok(txtEq.includes('A minha conta') && !txtEq.includes('Códigos de suporte'),
     'o porteiro muda a sua senha na Gestão, mas não vê a equipa nem os códigos');

  const equipa = await casal._api('acesso_lista');
  ok((equipa.acessos || []).some(a => a.email === emailPorteiro), 'a equipa mostra os dois');

  const seDespromove = await casal._api('acesso_papel&utilizador=' + equipa.eu + '&papel=porteiro');
  ok(seDespromove && seDespromove.success === false,
     'e o casal não se pode despromover a si próprio, deixando o casamento sem dono');

  const tirou = await casal._api('acesso_tirar&utilizador=' + conv.utilizador);
  ok(tirou && tirou.success, 'tirar o acesso ao porteiro, isso pode');

  // ---------- 7. suspender uma conta ----------
  const susp = await api('utilizador_estado&id=' + conv.utilizador + '&estado=suspenso');
  ok(susp && susp.success, 'o admin suspende uma conta');
  const suspTenta = await entrar(await b.newContext(), emailPorteiro, conv.senha);
  ok(suspTenta.url().includes('login.php'), 'e a conta suspensa deixa de entrar');

  // A senha do próprio muda-se na página da equipa.
  const mudou = await casal._api('senha_mudar', { atual: SENHA, nova: SENHA + 'novo' });
  ok(mudou && mudou.success, 'cada um muda a sua senha');
  const senhaErrada = await casal._api('senha_mudar', { atual: 'não é esta', nova: 'outracoisa123' });
  ok(senhaErrada && senhaErrada.success === false, 'mas só sabendo a atual');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1');
  await api('casamento_estado&id=' + idPend + '&estado=arquivado');
  await api('casamento_apagar&id=' + idPend);
  for (const email of [emailCasal, emailSup, emailPorteiro]) {
    const l = await api('utilizador_lista&q=' + encodeURIComponent(email));
    const c = (l.contas || []).find(x => x.email === email);
    if (c) await api('utilizador_apagar&id=' + c.id);
  }
  const sobra = await api('utilizador_lista&q=' + marca);
  ok((sobra.contas || []).length === 0, 'a prova não deixa contas de mentira na base');

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
