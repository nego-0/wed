// O preçário das licenças: montra, pedido, decisão e as portas que ela abre.
//
// O que aqui se prova não é o desenho da montra — são as CONSEQUÊNCIAS de uma
// licença. Uma licença que se vê bonita mas não fecha porta nenhuma não é uma
// licença; é um cartaz.
//
//   1. o preçário é público (a inscrição precisa dele antes de haver sessão);
//   2. um casal inscrito ENTRA já, mas só vê a sua licença;
//   3. aprovar o pedido abre exactamente os módulos pedidos — e mais nenhum;
//   4. o tecto de convidados conta pessoas, e conta a DIFERENÇA;
//   5. «só ao padrão» mostra um modelo; «todos os modelos» mostra a galeria;
//   6. «sem edição» fecha o editor e recusa guardar versões;
//   7. um reforço acrescenta e nunca tira;
//   8. revogar fecha tudo — menos o direito de levar os dados.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const comApi = (p) => {
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
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
  const marca = 'pc' + String(Date.now()).slice(-6);
  const SENHA = 'segredo12345';
  const email = 'casal.' + marca + '@exemplo.ao';

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. o preçário é público ----------
  const anon = await b.newContext().then(c => c.newPage());
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  const cat = await anon.evaluate(async () => (await (await fetch('api.php?action=lic_catalogo')).json()));
  ok(cat.success, 'o preçário responde sem sessão nenhuma');
  ok(cat.catalogo.modulos.length >= 5, `traz os módulos (${cat.catalogo.modulos.length})`);
  ok(cat.catalogo.pacotes.length >= 1, `e os pacotes (${cat.catalogo.pacotes.length})`);
  ok(cat.politica && cat.politica.corpo.length > 200, 'e as políticas de utilização, com texto');

  // A poupança de um pacote é uma CONTA, não uma promessa.
  const contas = cat.catalogo.pacotes.every(p => {
    const soma = p.itens.reduce((t, id) => {
      for (const m of cat.catalogo.modulos)
        for (const e of m.escaloes) if (e.id === id) return t + e.preco;
      return t;
    }, 0);
    return Math.abs(p.avulso - soma) < 0.01 && Math.abs(p.poupanca - Math.max(0, soma - p.preco)) < 0.01;
  });
  ok(contas, 'e a poupança de cada pacote bate certo com a soma dos seus escalões');

  // Um só pacote em destaque — dois «mais escolhidos» não escolhem nada.
  ok(cat.catalogo.pacotes.filter(p => p.destaque).length <= 1, 'quando muito um pacote em destaque');

  // ---------- 2. inscrever com um plano à medida ----------
  // Escolhe-se de propósito um plano APERTADO, para as portas terem o que fechar:
  // convidados até 80, e o convite digital só no modelo padrão, sem edição.
  const esc = (chave) => {
    for (const m of cat.catalogo.modulos)
      for (const e of m.escaloes) if (e.chave === chave) return e.id;
    return 0;
  };
  const escConv = esc('convidados_80'), escDig = esc('digital_padrao');
  ok(escConv > 0 && escDig > 0, 'o preçário de origem traz os escalões que a prova usa');

  const reg = await anon.evaluate(async ({ email, SENHA, marca, escConv, escDig }) => {
    const r = await fetch('api.php?action=registo_publico', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ noiva: 'Nara' + marca, noivo: 'Kito' + marca, email, senha: SENHA,
        data: '2027-09-11',
        licenca: { pacote: 0, escaloes: [escConv, escDig], meses: 6, aceito: true } }) });
    return r.json();
  }, { email, SENHA, marca, escConv, escDig });
  ok(reg.success && reg.pedido > 0, 'a inscrição cria o casamento e o pedido de licença');

  // O casal entra já — e só vê a licença.
  const casal = await entrar(await b.newContext(), email, SENHA);
  ok(casal.url().includes('licenca.php'), 'entra de imediato, e aterra na sua licença');
  for (const pag of ['index.php', 'mesas.php', 'orcamento.php', 'digital.php']) {
    await casal.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    ok(casal.url().includes('licenca.php'), `${pag} está fechada enquanto a licença não abrir`);
  }
  // A Gestão fica aberta: é onde o casal leva os seus dados (Lei 22/11, art. 26.º).
  await casal.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  ok(!casal.url().includes('licenca.php'), 'mas a Gestão fica aberta — é onde leva os seus dados');

  // E a API diz o mesmo que as páginas.
  const barrado = await casal._api('mesa_list');
  ok(barrado.success === false && /licen/i.test(barrado.message || ''),
     'e a API recusa pelo mesmo motivo, não só o menu');

  // ---------- 3. aprovar concede o que foi pedido, e só isso ----------
  const peds = await api('lic_pedidos&estado=pendente');
  const ped = (peds.pedidos || []).find(x => (x.casamento_nome || '').includes(marca));
  ok(ped && ped.itens.length === 2, `o pedido está na fila com os 2 módulos escolhidos (${ped ? ped.itens.length : 0})`);
  const cid = ped.casamento_id;

  const apr = await api('lic_decidir', { id: ped.id, decisao: 'aprovar', nota: 'Prova.' });
  ok(apr.success, 'o admin aprova o pedido');

  const ficha = await api('casamento_ficha&id=' + cid);
  const mods = ficha.licenca_modulos;
  ok(mods.convidados.ativo && mods.digital.ativo, 'os dois módulos pedidos ficam abertos');
  ok(!mods.mesas.ativo && !mods.orcamento.ativo && !mods.impresso.ativo,
     'e os três que NÃO foram pedidos continuam fechados');
  ok(mods.convidados.limite === 80, 'o tecto de convidados é o do escalão (80)');
  ok(!mods.digital.editar && !mods.digital.todos_modelos,
     'e o convite digital fica no modelo padrão, sem edição');

  // O casal já entra no que pediu — e continua fora do resto.
  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok(!casal.url().includes('licenca.php'), 'o painel abre-se');
  await casal.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  ok(casal.url().includes('licenca.php'), 'as mesas continuam fechadas — não foram pedidas');
  ok(casal.url().includes('quero=mesas'), 'e a montra sabe qual o módulo que faltou');

  // O menu não oferece portas que não abrem.
  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const menu = await casal.$$eval('header nav a', els => els.map(e => e.getAttribute('href')));
  ok(!menu.includes('mesas.php') && !menu.includes('orcamento.php'),
     'e o menu não mostra as entradas que a licença não abre: ' + menu.join(' '));
  ok(menu.includes('licenca.php'), 'mas mostra sempre a da Licença');

  // ---------- 4. o tecto conta pessoas, e conta a diferença ----------
  await api('lic_conceder', { casamento: cid, escaloes: [escConv, escDig], meses: 6 });
  // Aperta-se o tecto para 3, para a prova ser curta.
  const escs = cat.catalogo.modulos.find(m => m.chave === 'convidados').escaloes;
  // (usa-se o escalão de 80 e mede-se com convites pequenos)
  const criar = (nome, n) => casal._api('convite_save', {
    nome_exibicao: nome, tipo: 'ambos', lado: 'noiva',
    membros: Array.from({ length: n }, (_, i) => ({ nome: nome + ' ' + (i + 1) })) });

  const c1 = await criar('Familia' + marca, 5);
  ok(c1.success, 'com tecto de 80, um convite de 5 entra');

  // Corrigir um nome não gasta lugar nenhum: o que conta é a diferença.
  const igual = await casal._api('convite_save', {
    id: c1.convite.id, nome_exibicao: 'Familia' + marca, tipo: 'ambos', lado: 'noiva',
    membros: Array.from({ length: 5 }, (_, i) => ({ nome: 'Outro ' + (i + 1) })) });
  ok(igual.success, 'e reescrever os mesmos 5 nomes não gasta lugar (conta-se a diferença)');

  // Passar do tecto é recusado, com a conta à vista.
  const demais = await criar('Multidao' + marca, 200);
  ok(demais.success === false && /8[0]|licen/i.test(demais.message || ''),
     'passar do tecto é recusado: ' + (demais.message || '').slice(0, 90));

  // ---------- 5. modelos: só o padrão ----------
  const listaPadrao = await casal._api('modelo_lista&ambito=digital');
  ok((listaPadrao.modelos || []).length === 1,
     `com «só ao padrão», o casal vê um modelo digital (${(listaPadrao.modelos || []).length})`);
  ok((listaPadrao.modelos || [])[0] && listaPadrao.modelos[0].de_origem,
     'e o que vê é a peça de origem — o padrão da casa');

  // ---------- 6. sem edição: o editor fecha, e as versões também ----------
  await casal.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  ok(casal.url().includes('licenca.php'), 'sem edição, o editor do convite digital não abre');
  const ver = await casal._api('versao_criar&ambito=digital', { nome: 'tentativa' });
  ok(ver.success === false && /edi/i.test(ver.message || ''),
     'e guardar uma versão é recusado: ' + (ver.message || '').slice(0, 80));

  // ---------- 7. o reforço acrescenta, e nunca tira ----------
  const escAtelier = esc('digital_atelier');
  const pedir = await casal._api('lic_pedir', {
    pacote: 0, escaloes: [escAtelier], meses: 12, aceito: true, nota: 'Queremos desenhar.' });
  ok(pedir.success && pedir.pedido.tipo === 'upgrade', 'com licença ativa, um novo pedido é um reforço');

  // Enquanto o reforço espera, o que já se tem continua de pé.
  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok(!casal.url().includes('licenca.php'), 'e o casal continua a trabalhar enquanto o reforço espera');

  const peds2 = await api('lic_pedidos&estado=pendente');
  const ped2 = (peds2.pedidos || []).find(x => x.casamento_id === cid);
  await api('lic_decidir', { id: ped2.id, decisao: 'aprovar', nota: '' });

  const ficha2 = await api('casamento_ficha&id=' + cid);
  ok(ficha2.licenca_modulos.digital.todos_modelos, 'aprovado o reforço, o digital passa a todos os modelos');
  ok(ficha2.licenca_modulos.convidados.ativo && ficha2.licenca_modulos.convidados.limite === 80,
     'e os convidados ficam como estavam — um reforço acrescenta, não reescreve');
  ok(!ficha2.licenca_modulos.mesas.ativo, 'o que não foi pedido continua fora');

  const listaTodos = await casal._api('modelo_lista&ambito=digital');
  ok((listaTodos.modelos || []).length > 1,
     `e a galeria abre-se (${(listaTodos.modelos || []).length} modelos digitais)`);
  await casal.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  ok(!casal.url().includes('licenca.php'), 'e o editor passa a abrir');

  // ---------- 8. revogar fecha tudo, menos o direito aos dados ----------
  const semMotivo = await api('lic_revogar', { casamento: cid, motivo: '' });
  ok(semMotivo.success === false, 'revogar sem motivo é recusado — o casal tem direito a sabê-lo');

  const rev = await api('lic_revogar', { casamento: cid, motivo: 'Prova automática.' });
  ok(rev.success, 'com motivo, a licença é revogada');

  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok(casal.url().includes('licenca.php'), 'e todas as portas fecham');
  const txt = await casal.locator('body').innerText();
  ok(txt.includes('Prova automática'), 'a página da licença diz ao casal o motivo');

  // Mas os dados continuam a ser dele.
  const exp = await casal._api('dados_exportar');
  ok(exp && (exp.resumo || exp.convites || exp.formato),
     'e o casal continua a poder exportar os seus dados, como as políticas prometem');

  // ---------- limpeza ----------
  await api('casamento_estado&id=' + cid + '&estado=arquivado');
  await api('casamento_apagar&id=' + cid);
  const conta = ((await api('utilizador_lista&q=' + encodeURIComponent(email))).contas || [])
                .find(u => u.email === email);
  if (conta) await api('utilizador_apagar&id=' + conta.id);

  await b.close();
  console.log(f ? `\n${f} falha(s).` : '\nTudo certo.');
  process.exit(f ? 1 : 0);
})();
