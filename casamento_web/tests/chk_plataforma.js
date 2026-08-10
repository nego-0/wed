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
  await admin.waitForTimeout(1000);          // a lista é servida por casamento_lista
  const txtAdmin = await admin.locator('#lista-casamentos').innerText();
  ok(txtAdmin.includes('ZZ Casamento A ' + marca) && txtAdmin.includes('ZZ Casamento B ' + marca),
     'o pessoal da plataforma vê todos os casamentos na página');

  // ---------- o admin aterra na administração, não na festa de ninguém ----------
  const recem = await entrar(await b.newContext(), 'admin', 'noivos2026');
  console.log('   o admin aterra em:', recem.url().replace(BASE, ''));
  ok(recem.url().includes('plataforma.php'),
     'ao entrar, o admin vai para a administração e não para o painel de um casal');

  const cabRecem = await recem.locator('header').innerText();
  ok(!/A trabalhar em/.test(cabRecem),
     'e não fica com o casamento de ninguém aberto por si');
  ok(!/Isabel|Abednego/.test(cabRecem) && /Administração/.test(cabRecem),
     'o cabeçalho é da casa, e não de um casal ao acaso');
  ok((await recem.locator('.topo .monograma').innerText()).trim() === '✦',
     'e o monograma é a marca da plataforma, não as iniciais de um casal');

  const nums = await recem.locator('.numeros').innerText();
  console.log('   números:', nums.replace(/\s+/g, ' ').slice(0, 140));
  for (const rot of ['casamentos ativos', 'convites', 'pessoas convidadas',
                     'entradas registadas', 'contas ativas', 'códigos de suporte']) {
    ok(nums.toLowerCase().includes(rot), 'a administração mostra: ' + rot);
  }
  // Os números são do SISTEMA: os casamentos criados acima têm de lá estar.
  const nAtivos = parseInt((nums.match(/(\d+)\s*\n?\s*CASAMENTOS ATIVOS/i) || [])[1] || '0', 10);
  ok(nAtivos >= 3, 'e contam todos os casamentos, não só um (' + nAtivos + ')');

  // Sem casamento aberto, continua a poder fazer o que é da casa.
  const criaSemCasa = await recem._api('casamento_criar', { nome: 'ZZ Sem casa ' + marca });
  ok(criaSemCasa && criaSemCasa.success,
     'e, sem casamento aberto, ainda cria casamentos — que é como abre o primeiro');
  await api('casamento_estado&id=' + criaSemCasa.id + '&estado=arquivado');
  await api('casamento_apagar&id=' + criaSemCasa.id);

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

  // ---------- a entrada não é de casamento nenhum ----------
  const porta = await (await b.newContext()).newPage();
  await porta.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  const txtLogin = await porta.locator('body').innerText();
  const tituloLogin = await porta.title();
  console.log('   entrada:', txtLogin.replace(/\s+/g, ' ').slice(0, 80), '| título:', tituloLogin);
  ok(!/Isabel|Abednego/.test(txtLogin + tituloLogin),
     'a página de entrada não mostra o nome de casal nenhum');
  ok(txtLogin.includes('Gestão de Convidados'), 'mostra a casa, que é de quem lá chega');
  await porta.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  ok(!/Isabel|Abednego/.test(await porta.locator('body').innerText()),
     'e a inscrição também não');

  // ---------- o admin não é nenhum dos casais ----------
  // Entra em qualquer casamento porque responde pela casa. Isso não faz dele
  // um dos noivos, e o sistema tem de dizer a diferença.
  await api('casamento_abrir&id=' + casB.id);
  await admin.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const tira = await admin.locator('.tira-suporte').innerText().catch(() => '');
  console.log('   tira no casamento alheio:', tira.replace(/\s+/g, ' ').slice(0, 90));
  ok(/administração da plataforma/.test(tira),
     'o admin, em casa alheia, é avisado de que não está na sua');

  const equipaB = await api('acesso_lista');
  const emailsB = (equipaB.acessos || []).map(a => a.email);
  console.log('   equipa do casamento alheio:', JSON.stringify(emailsB));
  ok(!emailsB.includes('admin@local'),
     'e não aparece na equipa desse casamento, porque não é dela');

  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  ok((await admin.locator('body').innerText()).includes('administração da plataforma'),
     'a lista de casamentos diz com que título ele lá entra');

  // ---------- arquivar, reabrir, apagar ----------
  const alvo = await api('casamento_criar', { nome: 'ZZ A arquivar ' + marca, noiva: 'Zita', noivo: 'Zé' });
  await api('casamento_abrir&id=' + alvo.id);
  await api('convite_save', { nome_exibicao: 'ZZ Convidado do arquivo', tipo: 'digital',
                              lado: 'ambos', membros: ['Alguém'] });

  // Apagar sem arquivar é recusado — é a trava que substitui o "o nº1 não se apaga".
  const cedo = await api('casamento_apagar&id=' + alvo.id);
  console.log('   apagar sem arquivar:', JSON.stringify(cedo));
  ok(cedo && cedo.success === false, 'não se apaga um casamento que ainda está de pé');

  // Uma conta que só existe por causa deste casamento para com ele.
  const emailZ = 'zita.' + marca + '@exemplo.pt';
  const contaZ = await api('utilizador_criar', { email: emailZ, nome: 'Zita', senha: 'segredo12345',
                                                 casamento_id: alvo.id, papel: 'noivos' });
  // E uma que também tem outro casamento NÃO pode parar por causa deste.
  const emailD = 'dupla.' + marca + '@exemplo.pt';
  const contaD = await api('utilizador_criar', { email: emailD, nome: 'Dupla', senha: 'segredo12345',
                                                 casamento_id: alvo.id, papel: 'porteiro' });
  await api('acesso_dar&utilizador=' + contaD.id + '&casamento=' + casA.id + '&papel=porteiro');

  // A lista principal é a das casas em funcionamento. Um suspenso sai dela e vai
  // para a sua secção: misturados, a lista deixava de responder à pergunta que
  // se lhe faz de manhã — em quantos casamentos estamos a trabalhar.
  await api('casamento_estado&id=' + alvo.id + '&estado=suspenso');
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(900);
  const nomeAlvo = 'ZZ A arquivar ' + marca;
  const listaAtivos = await admin.locator('#lista-casamentos').innerText();
  ok(!listaAtivos.includes(nomeAlvo), 'um casamento suspenso sai da vista dos ativos');
  ok(/ATIVO/i.test(listaAtivos) && !/SUSPENSO/i.test(listaAtivos),
     'e a vista de abertura só tem casamentos ativos');

  await admin.click('#filtros-cas .chip[data-estado=suspenso]');
  await admin.waitForTimeout(700);
  ok((await admin.locator('#lista-casamentos').innerText()).includes(nomeAlvo),
     'e encontra-se no filtro dos suspensos');
  await api('casamento_estado&id=' + alvo.id + '&estado=ativo');

  const arq = await api('casamento_estado&id=' + alvo.id + '&estado=arquivado');
  console.log('   arquivar:', JSON.stringify(arq));
  ok(arq && arq.success, 'arquiva-se');
  ok(arq.contas_paradas === 1, 'e para as contas que só existiam por causa dele');

  const estadoDe = async (email) => {
    const l = await api('utilizador_lista&q=' + encodeURIComponent(email));
    return ((l.contas || [])[0] || {}).estado;
  };
  ok(await estadoDe(emailZ) === 'inativo', 'a conta do casal fica «inativo»');
  ok(await estadoDe(emailD) === 'ativo',
     'mas quem tem outro casamento de pé continua a entrar — não se fecha por tabela');
  const zitaTenta = await entrar(await b.newContext(), emailZ, 'segredo12345');
  ok(zitaTenta.url().includes('login.php'), 'e uma conta parada não entra');
  ok((await api('casamento_abrir&id=' + alvo.id)).success === false,
     'e um casamento arquivado já não se abre');

  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(900);
  await admin.click('#filtros-cas .chip[data-estado=arquivado]');
  await admin.waitForTimeout(700);
  ok((await admin.locator('#lista-casamentos').innerText()).includes(nomeAlvo),
     'encontra-se no filtro dos arquivados — arquivar não é perder de vista');

  // Os dados de um arquivado ainda se podem levar: é para isso que ele lá está.
  const dadosArq = await admin.evaluate(async (id) =>
    (await fetch('api.php?action=dados_exportar&ambito=casamento&id=' + id)).json(), alvo.id);
  ok(dadosArq && dadosArq.casamentos && dadosArq.casamentos[0].ficha.noiva === 'Zita',
     'e os seus dados ainda se levam, pelo número, sem o abrir');

  // Reabrir devolve-o inteiro — e devolve também as contas que pararam com ele.
  const reab = await api('casamento_estado&id=' + alvo.id + '&estado=ativo');
  ok(reab.contas_ativadas === 1, 'reabrir devolve as contas que tinham parado');
  ok(await estadoDe(emailZ) === 'ativo', 'a conta do casal volta a «ativo»');
  const reaberto = await api('casamento_abrir&id=' + alvo.id);
  ok(reaberto && reaberto.success, 'reabrir devolve-o');
  ok((await api('convite_list')).convites.length === 1, 'com o que lá estava dentro');

  // E apagar, depois de arquivar, leva tudo — e diz o que levou.
  await api('casamento_abrir&id=1');
  await api('casamento_estado&id=' + alvo.id + '&estado=arquivado');
  const morto = await api('casamento_apagar&id=' + alvo.id);
  console.log('   apagado:', JSON.stringify(morto));
  ok(morto && morto.success && morto.levou && morto.levou.convites === 1,
     'apagar um arquivado leva tudo, e diz quantos convites e pessoas levou');
  ok((await api('casamento_abrir&id=' + alvo.id)).success === false, 'e o casamento deixa de existir');

  // ---------- sair do casamento sem terminar a sessão ----------
  await api('casamento_abrir&id=' + casA.id);
  ok((await api('convite_list')).success, 'com o casamento aberto, vê-se a casa por dentro');
  const saiu = await api('casamento_fechar');
  console.log('   fechar:', JSON.stringify(saiu));
  ok(saiu && saiu.success && saiu.nome.includes('ZZ Casamento A'), 'fecha-se o casamento em que se estava');
  ok((await api('convite_list')).success === false, 'e deixa de se ver a casa por dentro');
  ok((await api('casamento_lista')).success, 'mas a sessão continua de pé — não se foi embora');

  // ---------- a lista por ordem de uso ----------
  await api('casamento_abrir&id=' + casB.id);
  await api('casamento_fechar');
  const ordem = await api('casamento_lista');
  console.log('   ordem:', JSON.stringify(ordem.casamentos.map(c => c.nome)));
  ok(ordem.casamentos[0].nome.includes('ZZ Casamento B'),
     'o último em que se trabalhou fica em cima');
  ok(!!ordem.casamentos[0].ultimo_acesso, 'e a lista traz quando foi');

  const procura = await api('casamento_lista&q=' + encodeURIComponent('Casamento B ' + marca));
  ok(procura.casamentos.length === 1, 'a lista procura-se pelo nome');

  // ---------- contas: criar, editar, dar e tirar lugares ----------
  const emailP = 'porta2.' + marca + '@exemplo.pt';
  const novaP = await api('utilizador_criar', { email: emailP, nome: 'Porteiro Novo',
                                                senha: 'segredo12345', casamento_id: casA.id,
                                                papel: 'porteiro' });
  ok(novaP && novaP.success, 'o admin cria uma conta de porteiro ligada a um casamento');
  const lug = await api('utilizador_casamentos&id=' + novaP.id);
  ok((lug.acessos || []).length === 1 && lug.acessos[0].papel === 'porteiro',
     'e vê-se o lugar que ela tem');

  await api('acesso_dar&utilizador=' + novaP.id + '&casamento=' + casB.id + '&papel=noivos');
  ok((await api('utilizador_casamentos&id=' + novaP.id)).acessos.length === 2,
     'dá-se-lhe lugar noutro casamento, com outro papel');
  await api('acesso_tirar_de&utilizador=' + novaP.id + '&casamento=' + casB.id);
  ok((await api('utilizador_casamentos&id=' + novaP.id)).acessos.length === 1, 'e tira-se');

  const edit = await api('utilizador_editar', { id: novaP.id, nome: 'Porteiro Editado',
                                                email: 'editado.' + marca + '@exemplo.pt' });
  ok(edit && edit.success && edit.email.startsWith('editado.'), 'edita-se o nome e o email da conta');

  // ---------- o suporte não se prende a casamentos ----------
  const presa = await api('utilizador_criar', { email: 'sup2.' + marca + '@exemplo.pt',
                                                nome: 'Sup', senha: 'segredo12345',
                                                papel_plataforma: 'suporte', casamento_id: casA.id });
  console.log('   suporte com casamento:', JSON.stringify(presa));
  ok(presa && presa.success === false,
     'uma conta de suporte NÃO se cria presa a um casamento — entra por código');

  const supOk = await api('utilizador_criar', { email: 'sup3.' + marca + '@exemplo.pt',
                                                nome: 'Sup', senha: 'segredo12345',
                                                papel_plataforma: 'suporte' });
  ok(supOk && supOk.success, 'cria-se sem casamento nenhum');
  const prender = await api('acesso_dar&utilizador=' + supOk.id + '&casamento=' + casA.id + '&papel=noivos');
  ok(prender && prender.success === false, 'e não se lhe pode dar um lugar depois');

  // Passar uma conta com lugares a suporte larga-os: passa a entrar por código.
  await api('utilizador_editar', { id: novaP.id, papel_plataforma: 'suporte' });
  ok((await api('utilizador_casamentos&id=' + novaP.id)).acessos.length === 0,
     'e uma conta que passa a suporte larga os lugares que tinha');

  // ---------- os noivos só criam porteiros ----------
  await api('casamento_abrir&id=' + casA.id);
  const noivosA2 = await entrar(await b.newContext(), emailA, 'segredo12345');
  const tentaNoivos = await noivosA2._api('acesso_convidar',
    { email: 'outro.' + marca + '@exemplo.pt', papel: 'noivos' });
  console.log('   noivos a convidar noivos:', JSON.stringify(tentaNoivos).slice(0, 90));
  ok(tentaNoivos && tentaNoivos.success && tentaNoivos.papel === 'porteiro',
     'um convite dos noivos sai sempre como porteiro, peçam o que pedirem');
  await noivosA2._api('acesso_tirar&utilizador=' + tentaNoivos.utilizador);

  // ---------- nem no casamento nº1, que ele herdou ----------
  // O 'admin' do config.local.php ficou, na migração, com um lugar de noivos no
  // casamento nº1 — nesse mundo de um casamento só, ele era o casal. Com vários,
  // esse lugar mente na mesma. A v9 tira-o.
  await api('casamento_abrir&id=1');
  const equipa1 = await api('acesso_lista');
  const emails1 = (equipa1.acessos || []).map(a => a.email + ':' + a.papel);
  console.log('   equipa do casamento nº1:', JSON.stringify(emails1));
  ok(!emails1.some(e => e.startsWith('admin@local')),
     'o admin herdado também saiu da equipa do casamento nº1');

  // E o buraco que isso deixa não fica escondido: um casamento sem conta de
  // noivos é um casamento a que o casal não chega.
  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(900);
  const txtEq = await admin.locator('#lista-acessos').innerText();
  console.log('   aviso na equipa:', txtEq.replace(/\s+/g, ' ').slice(0, 100));
  ok(/não tem nenhuma conta de noivos/.test(txtEq),
     'e a Gestão avisa que o casamento ficou sem conta dos noivos');
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  ok((await admin.locator('body').innerText()).includes('sem conta dos noivos'),
     'a lista de casamentos marca-o também');

  // ---------- limpeza ----------
  // Primeiro os casamentos (que levam com eles os lugares), e só depois a
  // conta — que a esta altura já não pertence a casamento nenhum. Sem isto,
  // cada corrida deixava mais uma conta de mentira na base.
  await api('casamento_abrir&id=1');
  for (const id of [casA.id, casB.id, pend.id]) {
    // Apagar exige arquivar antes — o mesmo caminho que a página faz.
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }
  // Apagados os casamentos, os seus lugares foram com eles: estas contas ficam
  // órfãs, e é isso que as deixa apagáveis.
  for (const e of [emailZ, emailD, 'editado.' + marca + '@exemplo.pt',
                   'sup3.' + marca + '@exemplo.pt', 'outro.' + marca + '@exemplo.pt']) {
    const l = await api('utilizador_lista&q=' + encodeURIComponent(e));
    for (const c of l.contas || []) await api('utilizador_apagar&id=' + c.id);
  }
  const limpaConta = await api('utilizador_apagar&id=' + contaA.id);
  ok(limpaConta && limpaConta.success, 'a conta de prova, já sem casamento, apaga-se');

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
