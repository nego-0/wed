// O painel de administração: o que a etapa desta ronda acrescentou.
//
//   1. o suporte tem um posto SIMPLES — código + a sua senha — e não o painel
//      de estado da casa nem a lista de casamentos;
//   2. o formulário de «Novo casamento» apanha, por campo, um email torto, uma
//      palavra-passe curta e uma confirmação que não bate certo;
//   3. «Gerir licença» é um modal que define o período e arranca o relógio;
//   4. «Editar todos os dados» edita a identidade e o evento de um casamento a
//      partir da lista, e cria/tira as contas de noivos e porteiro.
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
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  // ---------- 1. o posto do suporte é simples ----------
  // Cria-se uma conta de suporte com senha conhecida e entra-se com ela.
  const supEmail = 'sup.' + marca + '@exemplo.pt';
  const supSenha = 'suporte12345';
  await api('utilizador_criar', { email: supEmail, nome: 'Suporte ' + marca,
                                  senha: supSenha, papel_plataforma: 'suporte' });
  const sup = await entrar(await b.newContext(), supEmail, supSenha);
  sup.on('pageerror', e => errs.push(e.message));
  ok(sup.url().includes('plataforma.php'), 'o suporte entra na plataforma');
  ok(await sup.locator('#s-codigo').count() === 1, 'e vê o campo do código do casal');
  ok(await sup.locator('#sp-nova').count() === 1, 'e um campo para mudar a sua própria senha');
  ok(await sup.locator('.numeros').count() === 0, 'mas NÃO vê o painel de estado da casa');
  ok(await sup.locator('#lista-casamentos').count() === 0, 'nem a lista de casamentos');
  // Muda a sua senha, sabendo a atual.
  await sup.fill('#sp-atual', supSenha); await sup.fill('#sp-nova', 'outroseg12345');
  const mud = await sup._api('senha_mudar', { atual: supSenha, nova: 'outroseg12345' });
  ok(mud && mud.success, 'o suporte muda a sua própria palavra-passe');

  // ---------- 2. o «Novo casamento» valida as contas por campo ----------
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.evaluate(() => verVista('novo')); await admin.waitForTimeout(200);
  await admin.fill('#n-noiva', 'Nina' + marca);
  await admin.fill('#n-noivos-email', 'nina(a)ex');   // sem @ válido
  await admin.fill('#n-noivos-senha', '123');         // curta
  await admin.fill('#n-noivos-confirmar', '999');     // não bate
  await admin.evaluate(() => criar()); await admin.waitForTimeout(200);
  const maus = await admin.$$eval('#vista-novo .campo.mau input', els => els.map(e => e.id));
  ok(maus.includes('n-noivos-email'), 'um email torto na conta dos noivos é apanhado');
  ok(maus.includes('n-noivos-senha'), 'uma palavra-passe curta é apanhada');
  ok(maus.includes('n-noivos-confirmar'), 'e uma confirmação diferente é apanhada');

  // ---------- 3. «Gerir licença» é um modal que define e arranca ----------
  const w = await api('casamento_criar', { nome: 'ZZ Lic ' + marca, noiva: 'Ana', noivo: 'Beto' });
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(600);
  await admin.evaluate((id) => gerirLicenca(id), w.id);
  await admin.waitForTimeout(200);
  ok(await admin.locator('#ov-licenca.aberto').count() === 1, 'o modal de licença abre');
  await admin.selectOption('#lic-periodo', '6');
  await admin.evaluate(() => licPeriodoMudou());
  await admin.evaluate(() => { document.getElementById('lic-reiniciar').checked = true; });
  await admin.evaluate(() => guardarLicenca()); await admin.waitForTimeout(300);
  const lic = (await api('casamento_lista&estado=todos&q=ZZ Lic ' + marca)).casamentos.find(c => +c.id === +w.id);
  ok(lic && +lic.licenca_meses === 6 && lic.licenca_ate, 'a licença fica em 6 meses, com o relógio a contar');

  // ---------- 4. «Editar todos os dados» edita ficha + contas ----------
  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(600);
  await admin.evaluate((id) => editarTudo(id), w.id);
  await admin.waitForTimeout(500);
  ok(await admin.locator('#ov-editar.aberto').count() === 1, 'o editor completo abre');
  ok(await admin.inputValue('#ed-noiva') === 'Ana', 'e vem preenchido com o que já lá está');
  await admin.fill('#ed-hora', '17:45');
  await admin.fill('#ed-local', 'Salão ' + marca);
  await admin.fill('#ed-noiva', 'Ana Maria');
  // Os campos que antes faltavam ao editor: título do local e os mapas das cerimónias.
  await admin.fill('#ed-venue', 'Copo d’água ' + marca);
  await admin.fill('#ed-civil-local', 'Conservatória ' + marca);
  await admin.fill('#ed-religiosa-local', 'Igreja ' + marca);
  await admin.evaluate(() => guardarDadosCasamento()); await admin.waitForTimeout(400);
  let ficha = await api('casamento_ficha&id=' + w.id);
  ok(ficha.casamento.noiva === 'Ana Maria', 'guardar muda a identidade');
  ok(ficha.evento['evento.hora'] === '17:45' && ficha.evento['evento.local'] === 'Salão ' + marca,
     'e guarda a hora e o local do evento');
  ok(ficha.evento['evento.venue_titulo'] === 'Copo d’água ' + marca, 'guarda o título do local (campo antes em falta)');
  ok(ficha.evento['evento.civil_local'] === 'Conservatória ' + marca
     && ficha.evento['evento.religiosa_local'] === 'Igreja ' + marca, 'guarda os locais das cerimónias');

  // adicionar um porteiro pelo editor
  const portEmail = 'porta.' + marca + '@exemplo.pt';
  await admin.evaluate(() => { document.getElementById('ed-nova-conta').open = true; });
  await admin.selectOption('#ed-np-papel', 'porteiro');
  await admin.fill('#ed-np-email', portEmail);
  await admin.evaluate(() => adicionarConta()); await admin.waitForTimeout(500);
  ficha = await api('casamento_ficha&id=' + w.id);
  const port = (ficha.contas || []).find(c => c.email === portEmail && c.papel === 'porteiro');
  ok(!!port, 'adicionar uma conta de porteiro liga-a ao casamento');

  // tirar-lhe o acesso pelo editor: a pergunta já não é um confirm() do
  // browser, é a janela da casa — abre-se, e confirma-se carregando no botão.
  // (sem await: licConfirmar só volta depois de se responder)
  await admin.evaluate((uid) => { tirarContaLigada(uid, 'porteiro'); }, port.utilizador_id);
  await admin.waitForSelector('#lic-janela.on', { timeout: 4000 });
  ok(await admin.locator('#lic-janela.on').isVisible(),
     'tirar a conta pergunta numa janela, e não num confirm() do browser');
  await admin.click('#lic-jo');
  await admin.waitForTimeout(500);
  ficha = await api('casamento_ficha&id=' + w.id);
  ok(!(ficha.contas || []).some(c => c.email === portEmail), 'e tirar o acesso desliga-a do casamento');

  // ---------- limpeza ----------
  await api('casamento_estado&id=' + w.id + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + w.id, {});
  for (const em of [supEmail, portEmail]) {
    const u = ((await api('utilizador_lista&q=' + encodeURIComponent(em))).contas || []).find(x => x.email === em);
    if (u) await api('utilizador_apagar&id=' + u.id, {});
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
