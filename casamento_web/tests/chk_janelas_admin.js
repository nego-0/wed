// As perguntas do painel — contas, casamentos e dados — deixaram de ser janelas
// nativas do browser.
//
// Isto não é uma questão de gosto. Um confirm() do browser não se estiliza, não
// valida nada e não sabe pedir que se escreva um nome antes de apagar uma casa
// inteira. Um prompt() a seguir a um confirm() é pior ainda: parte a decisão em
// dois ecrãs, e quem lê o aviso já não o tem à frente quando escreve.
//
// O que se prova aqui são consequências, e não aparências:
//   1. nenhuma janela nativa aparece em todo o percurso;
//   2. cancelar a janela não faz nada — o estado fica como estava;
//   3. apagar um casamento exige o nome escrito, e um nome errado não apaga;
//   4. suspender uma conta pergunta, e confirmar suspende mesmo;
//   5. repor a senha pergunta, e confirmar devolve a senha nova;
//   6. apagar tudo o sistema exige «APAGAR TUDO» — e um texto errado recusa.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

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
  const marca = 'jn' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  // Qualquer janela nativa que apareça é contada e mandada embora. No fim têm
  // de ser zero: se uma aparecer, o teste ainda corre — mas fica a marca.
  let nativos = 0;
  admin.on('dialog', d => { nativos++; d.dismiss(); });

  // Abre-se a janela, e devolve-se o que lá está escrito.
  const abrir = async (fn, ...args) => {
    await admin.evaluate(([nome, a]) => { window[nome].apply(null, a); }, [fn, args]);
    await admin.waitForSelector('#lic-janela.on', { timeout: 4000 });
    return admin.textContent('#lic-janela .pl-modal-corpo');
  };
  const confirmar = async () => { await admin.click('#lic-jo'); await admin.waitForTimeout(500); };
  const cancelar  = async () => { await admin.click('#lic-jc'); await admin.waitForTimeout(200); };
  const aberta    = () => admin.locator('#lic-janela.on').isVisible();

  // ---------- material de prova ----------
  const nomeCas = 'Janelas ' + marca;
  let d = await api('casamento_criar', {
    nome: nomeCas, data: '2027-05-08', local: 'Salão ' + marca,
    noivos_email: 'casal.' + marca + '@exemplo.ao', noivos_senha: 'senhaforte123',
  });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  // Um segundo casamento, para a parte da Gestão: o primeiro vai ser apagado.
  const emailCasal = 'casal2.' + marca + '@exemplo.ao';
  d = await api('casamento_criar', {
    nome: 'Gestão ' + marca, data: '2027-06-12',
    noivos_email: emailCasal, noivos_senha: 'senhaforte123',
  });
  ok(d && d.success, 'criou o casamento da Gestão');
  const cid2 = d.id;
  const contaEmail = 'conta.' + marca + '@exemplo.pt';
  d = await api('utilizador_criar', { nome: 'Conta ' + marca, email: contaEmail,
    senha: 'senhaforte123', papel_plataforma: 'suporte' });
  ok(d && d.success, 'criou a conta de prova');
  const uid = d.id;

  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(600);

  // ---------- 1. mudar o estado de um casamento ----------
  let txt = await abrir('mudarEstado', cid, 'arquivado', nomeCas);
  ok(/contas/i.test(txt) && /Nada se apaga/i.test(txt),
     'arquivar avisa que as contas ficam paradas e que nada se apaga');
  await cancelar();
  let ficha = await api('casamento_ficha&id=' + cid);
  ok(ficha.casamento.estado !== 'arquivado', 'cancelar a janela não arquiva nada');

  await abrir('mudarEstado', cid, 'arquivado', nomeCas);
  await confirmar();
  // Arquivar recarrega a página depois de dizer quantas contas foram atrás:
  // espera-se por essa recarga em vez de correr em cima dela.
  await admin.waitForTimeout(2200);
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(600);
  ficha = await api('casamento_ficha&id=' + cid);
  ok(ficha.casamento.estado === 'arquivado', 'confirmar arquiva mesmo');

  // ---------- 2. suspender uma conta ----------
  await admin.evaluate(() => verVista('contas'));
  await admin.waitForTimeout(400);
  txt = await abrir('estadoConta', uid, 'suspenso');
  ok(/reativada/i.test(txt), 'suspender explica que a conta volta quando for reativada');
  await cancelar();
  let contas = (await api('utilizador_lista&q=' + encodeURIComponent(contaEmail))).contas || [];
  ok((contas.find(c => c.id === uid) || {}).estado !== 'suspenso',
     'cancelar não suspende a conta');

  await abrir('estadoConta', uid, 'suspenso');
  await confirmar();
  contas = (await api('utilizador_lista&q=' + encodeURIComponent(contaEmail))).contas || [];
  ok((contas.find(c => c.id === uid) || {}).estado === 'suspenso', 'confirmar suspende');

  // Reativar não pergunta nada: devolver o acesso não é decisão perigosa.
  await admin.evaluate((id) => estadoConta(id, 'ativo'), uid);
  await admin.waitForTimeout(400);
  ok(!(await aberta()), 'reativar não faz perguntas — só o que fecha portas as faz');

  // ---------- 3. repor a senha ----------
  txt = await abrir('reporSenha', uid, contaEmail);
  ok(/uma vez/i.test(txt), 'repor a senha avisa que a nova só aparece uma vez');
  await confirmar();
  const segredo = await admin.textContent('#senha-reposta');
  ok(/\S/.test(segredo || '') && segredo.includes(contaEmail),
     'e confirmar mostra a senha nova no painel');

  // ---------- 4. apagar um casamento exige o nome escrito ----------
  await admin.evaluate(() => verVista('casamentos'));
  await admin.waitForTimeout(400);
  txt = await abrir('apagar', cid, nomeCas);
  ok(/Não se desfaz/i.test(txt), 'apagar avisa que não se desfaz');
  ok(await admin.locator('#lf-escrever').isVisible(), 'e pede o nome escrito');

  await admin.fill('#lf-escrever', 'nome ao acaso');
  await admin.click('#lic-jo'); await admin.waitForTimeout(400);
  ok(await aberta(), 'com o nome errado a janela fica aberta');
  ok(/não confere/i.test(await admin.textContent('#lic-jerro')), 'e diz que o nome não confere');
  ficha = await api('casamento_ficha&id=' + cid);
  ok(ficha && ficha.success, 'e o casamento continua lá — nada foi apagado');

  await admin.fill('#lf-escrever', nomeCas);
  await confirmar();
  await admin.waitForTimeout(1800);   // apagar também recarrega a página
  ficha = await api('casamento_ficha&id=' + cid);
  ok(!(ficha && ficha.success), 'com o nome certo o casamento é apagado');

  // ---------- 5. apagar tudo o sistema ----------
  // Chega-se ao pé do abismo e não se salta: prova-se que a porta está trancada.
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(600);
  txt = await abrir('apagarSistemaTudo');
  ok(/casamentos/i.test(txt) && /modelos/i.test(txt) && /contas/i.test(txt),
     'apagar tudo lista o que se perde antes de perguntar');
  ok(await admin.locator('#lf-escrever').isVisible(), 'e exige a confirmação escrita');
  await admin.fill('#lf-escrever', 'apagar');
  await admin.click('#lic-jo'); await admin.waitForTimeout(400);
  ok(await aberta(), 'meia palavra não chega — a janela não fecha');
  const listaAntes = (await api('casamento_lista&estado=todos')).casamentos || [];
  await cancelar();
  const listaDepois = (await api('casamento_lista&estado=todos')).casamentos || [];
  ok(listaAntes.length === listaDepois.length && !(await aberta()),
     'e cancelar deixa o sistema exactamente como estava');

  // ---------- 6. apagar dados sem escolher nada ----------
  await admin.evaluate(() => verVista('dados'));
  await admin.waitForTimeout(400);
  await admin.evaluate(() => {
    document.querySelectorAll('#dados-inc input[value]').forEach(i => { i.checked = false; });
  });
  await admin.evaluate(() => { apagarDados(); });
  await admin.waitForTimeout(400);
  ok(!(await aberta()), 'apagar dados sem nada assinalado nem chega a perguntar');

  // ---------- 7. a Gestão dos noivos usa a mesma janela ----------
  // O motor vive em assets/janela.js precisamente para isto: a mesma pergunta,
  // com o mesmo cuidado, do lado de quem não é admin.
  const casal = await entrar(await b.newContext(), emailCasal, 'senhaforte123');
  let natCasal = 0;
  casal.on('dialog', d => { natCasal++; d.dismiss(); });
  casal.on('pageerror', e => errs.push('gestao: ' + e.message));
  await casal.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(600);

  await casal.evaluate(() => { apagarTudo(); });
  await casal.waitForSelector('#lic-janela.on', { timeout: 4000 });
  const txtC = await casal.textContent('#lic-janela .pl-modal-corpo');
  ok(/convidados/i.test(txtC) && /orçamento/i.test(txtC),
     'na Gestão, apagar tudo lista o que se perde');
  ok(await casal.locator('#lf-escrever').isVisible(),
     'e exige a confirmação escrita, como do lado do admin');
  await casal.fill('#lf-escrever', 'apagar');   // minúsculas não servem
  await casal.click('#lic-jo'); await casal.waitForTimeout(400);
  ok(await casal.locator('#lic-janela.on').isVisible(),
     'texto errado não apaga nada — a janela fica');
  await casal.click('#lic-jc'); await casal.waitForTimeout(200);
  ok(natCasal === 0, `a Gestão também não abre janelas nativas (${natCasal})`);

  // ---------- 8. os editores de convite ----------
  // Eram a última área com janelas do browser. A prova é a mesma: carregar no
  // que pergunta, e ver que quem responde é a casa.
  await api('casamento_abrir&id=' + cid2);
  await admin.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(1800);

  // «Guardar Como» pede o nome da versão. Era um prompt() de uma linha; passou
  // a ser um formulário — e um formulário sabe recusar o vazio, coisa que um
  // prompt() nunca soube. (Mexe-se primeiro no convite: sem nada por gravar,
  // não há o que guardar.)
  await admin.evaluate(() => {
    EST.val['textos.kicker'] = 'PROVA ' + Date.now(); marcarSujo(true);
  });
  await admin.evaluate(() => { guardar(); });
  await admin.waitForSelector('#lic-janela.on', { timeout: 6000 });
  ok(await admin.locator('#lf-nome').isVisible(),
     'o editor do convite pede o nome da versão num campo, e não num prompt()');
  await admin.click('#lic-jo'); await admin.waitForTimeout(400);
  ok(await admin.locator('#lic-janela.on').isVisible(),
     'e sem nome não guarda — a janela fica, a dizer o que falta');
  ok(/nome/i.test(await admin.textContent('#lic-jerro')), 'com o aviso à vista');
  await admin.fill('#lf-nome', 'Versão da prova');
  await admin.click('#lic-jo'); await admin.waitForTimeout(1500);
  const vs = await api('versao_lista&ambito=digital');
  ok((vs.versoes || []).some(v => v.nome === 'Versão da prova'),
     'e com nome guarda mesmo a versão');

  // ---------- limpeza ----------
  await api('utilizador_apagar&id=' + uid, {});
  await api('casamento_estado&id=' + cid2 + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + cid2, {});

  ok(nativos === 0, `nenhuma janela nativa em todo o percurso (${nativos})`);
  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
