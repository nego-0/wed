// Quem chega à porta tem por onde perguntar.
//
// A entrada e a inscrição não tinham resposta para nada: quem chegava com uma
// dúvida — quanto custa, como funciona, se é preciso pagar já — fechava a
// página e ia-se embora, e nunca se ficava a saber porquê.
//
// A caixa do canto responde às perguntas que se repetem, com as respostas já
// escritas pela casa. Não é uma conversa a sério e não finge sê-lo: diz
// «perguntas frequentes», e deixa os contactos para quem precise mesmo de
// falar com uma pessoa.
//
// O que esta prova garante, por esta ordem:
//   1. a caixa aparece nas DUAS páginas públicas, e responde;
//   2. desligada, não aparece — e o servidor não devolve sequer as perguntas;
//   3. uma pergunta desligada não chega ao público;
//   4. o que não está preenchido não se inventa (contactos);
//   5. é do ADMIN: quem não é da casa não lê nem escreve isto.
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
  const marca = 'at' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  // Estado de partida, para o repor no fim: esta prova mexe numa definição da
  // casa, e a casa é a mesma para as provas seguintes.
  const antes = (await api('atendimento_ler')).def || {};

  // ---------- 1. a caixa aparece, e responde ----------
  let d = await api('atendimento_guardar', {
    ativo: 1, nome: 'Sofia ' + marca, cargo: 'Atendimento',
    saudacao: 'Bem-vindos ' + marca + '. Em que podemos ajudar?',
    telefone: '+244 923 000 111', whatsapp: '', email: 'ola@exemplo.ao',
    horario: 'Segunda a sexta' });
  ok(d && d.success, 'o admin liga o atendimento e escreve quem atende');

  const PERG = 'Quanto custa ' + marca + '?';
  const RESP = 'Depende do que levarem. Está tudo escrito na página de inscrição, ' + marca + '.';
  d = await api('atendimento_faq_guardar', { id: 0, pergunta: PERG, resposta: RESP, ordem: 5, ativo: 1 });
  ok(d && d.success, 'e cria uma pergunta com a sua resposta');
  const pid = d.id;

  const publica = await (await b.newContext()).newPage();
  publica.on('pageerror', e => errs.push('login: ' + e.message));
  await publica.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await publica.waitForSelector('#at-botao', { timeout: 8000 });
  ok(true, 'na entrada, a caixa está lá — sem ninguém ter feito login');

  await publica.click('#at-botao');
  await publica.waitForTimeout(400);
  let txt = await publica.textContent('#at-painel');
  ok(/Sofia/.test(txt), 'aberta, mostra quem atende');
  ok(txt.includes('Bem-vindos ' + marca), 'e a mensagem de boas-vindas que o admin escreveu');
  ok(txt.includes(PERG), 'com a pergunta na lista');
  ok(!txt.includes(RESP), 'mas ainda sem a resposta — pergunta-se primeiro');

  await publica.click('#at-sug .at-q');
  await publica.waitForTimeout(1000);
  txt = await publica.textContent('#at-fio');
  ok(txt.includes(PERG) && txt.includes(RESP),
     'tocar na pergunta escreve-a e responde-lhe, pela ordem em que se lê');

  // A vista sobe até à RESPOSTA. A pergunta está lá em baixo, na lista, e a
  // resposta nasce lá em cima, no fio: sem isto, quem carregava não via nada
  // acontecer e carregava outra vez.
  const naVista = await publica.evaluate(() => {
    const corpo = document.getElementById('at-corpo');
    const r = document.querySelector('#at-fio .at-resp:last-child');
    if (!r) return null;
    const cr = corpo.getBoundingClientRect(), rr = r.getBoundingClientRect();
    return { dentro: rr.top >= cr.top - 2 && rr.top < cr.bottom,
             topo: Math.round(rr.top - cr.top) };
  });
  ok(naVista && naVista.dentro,
     `a resposta fica à vista, e no cimo dela (${naVista && naVista.topo}px do topo)`);

  // Os contactos: os que existem, e só esses.
  const ct = await publica.evaluate(() => {
    const c = document.querySelector('.at-contactos');
    return c ? { txt: c.textContent, links: [...c.querySelectorAll('a')].map(a => a.getAttribute('href')) } : null;
  });
  ok(ct && ct.links.some(h => /^tel:/.test(h)), 'o telefone aparece, e é para marcar');
  ok(ct && ct.links.some(h => /^mailto:/.test(h)), 'o email também');
  ok(ct && !ct.links.some(h => /wa\.me/.test(h)),
     'o WhatsApp, que ficou em branco, NÃO aparece — não se inventa um contacto que não existe');

  // A mesma caixa na inscrição, que é a outra porta de entrada.
  const reg = await (await b.newContext()).newPage();
  reg.on('pageerror', e => errs.push('registo: ' + e.message));
  await reg.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await reg.waitForSelector('#at-botao', { timeout: 8000 });
  ok(true, 'e na inscrição também');
  await reg.context().close();

  // ---------- 2. uma pergunta desligada não chega ao público ----------
  d = await api('atendimento_faq_guardar', { id: pid, pergunta: PERG, resposta: RESP,
                                             ordem: 5, ativo: 0 });
  ok(d && d.success, 'o admin desliga a pergunta');
  const soPublico = await publica.evaluate(async () =>
    (await fetch('api.php?action=atendimento_publico')).json());
  ok(!(soPublico.perguntas || []).some(p => p.pergunta === PERG),
     'e ela deixa de vir para o público — não é só escondida no ecrã');
  const soAdmin = await api('atendimento_ler');
  ok((soAdmin.perguntas || []).some(p => p.pergunta === PERG),
     'mas continua guardada, para o admin a voltar a ligar');

  // ---------- a casa nasce a saber dizer como se fala com ela ----------
  ok((soAdmin.perguntas || []).some(p => /falo com uma pessoa/i.test(p.pergunta)),
     'entre as perguntas de origem está a de como falar com a equipa');
  const pContacto = (soAdmin.perguntas || []).find(p => /falo com uma pessoa/i.test(p.pergunta));
  ok(pContacto && /telefone/i.test(pContacto.resposta) && /email/i.test(pContacto.resposta),
     'e a resposta encaminha para os contactos — sem repetir os números, que envelheceriam');

  // ---------- 3. desligado, não há caixa nenhuma ----------
  await api('atendimento_guardar', { ativo: 0, nome: 'Sofia ' + marca });
  const desligado = await publica.evaluate(async () =>
    (await fetch('api.php?action=atendimento_publico')).json());
  ok(desligado && desligado.ativo === false, 'desligado, o servidor diz só isso');
  ok(!desligado.perguntas && !desligado.saudacao,
     'e não manda as perguntas nem a saudação — o que não se mostra não se envia');
  await publica.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await publica.waitForTimeout(900);
  ok(await publica.locator('#at-botao').count() === 0,
     'e na página não fica botão nenhum a abrir para o vazio');
  await publica.context().close();

  // ---------- 4. isto é da casa ----------
  // Escrever nas páginas que toda a gente vê antes de entrar não é coisa que se
  // deixe a um casal.
  const casal = await entrar(await b.newContext(), 'admin', 'noivos2026');   // referência
  await casal.context().close();
  const semSessao = await (await b.newContext()).newPage();
  await semSessao.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  const tenta = (a, c) => semSessao.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(c || {}) });
    return r.json();
  }, { a, c });
  let r2 = await tenta('atendimento_guardar', { ativo: 1, nome: 'Intruso' });
  ok(r2 && r2.success === false, 'de fora, ninguém escreve o atendimento');
  r2 = await tenta('atendimento_ler');
  ok(r2 && r2.success === false, 'nem lê a vista completa (a desligada incluída)');
  r2 = await tenta('atendimento_faq_guardar', { id: 0, pergunta: 'x', resposta: 'y' });
  ok(r2 && r2.success === false, 'nem cria perguntas');
  r2 = await tenta('atendimento_faq_apagar&id=' + pid);
  ok(r2 && r2.success === false, 'nem as apaga');
  await semSessao.context().close();

  // ---------- 5. o servidor recusa uma pergunta pela metade ----------
  let mau = await api('atendimento_faq_guardar', { id: 0, pergunta: 'E se?', resposta: '' });
  ok(mau && mau.success === false, 'uma pergunta sem resposta é recusada');
  ok(/resposta/i.test(mau.message || ''), 'e diz o que falta: ' + (mau.message || '').slice(0, 60));
  mau = await api('atendimento_guardar', { ativo: 1, nome: '' });
  ok(mau && mau.success === false, 'e o atendimento sem nome de quem atende também');

  // ---------- 6. o encaixe do chat ao vivo ----------
  // Não há ferramenta nenhuma ligada — o que se prova é a COSTURA: desligada,
  // não sai nada para a página; ligada, o botão aparece e o script só se
  // carrega a pedido; e um endereço em claro é recusado.
  await api('atendimento_guardar', { ativo: 1, nome: 'Sofia ' + marca,
    saudacao: 'Bem-vindos ' + marca, chat_modo: 'nenhum' });
  let pub2 = await admin.evaluate(async () =>
    (await fetch('api.php?action=atendimento_publico')).json());
  ok(pub2.ao_vivo && pub2.ao_vivo.modo === 'nenhum',
     'sem ferramenta escolhida, a página só sabe que não há chat ao vivo');
  ok(!pub2.ao_vivo.script,
     'e nem o endereço de script nenhum viaja para quem não vai precisar dele');

  mau = await api('atendimento_guardar', { ativo: 1, nome: 'Sofia ' + marca,
    chat_modo: 'script', chat_script: 'http://chat.exemplo/loader.js' });
  ok(mau && mau.success === false, 'um script em http:// é recusado');
  ok(/https/i.test(mau.message || ''), 'e diz porquê: ' + (mau.message || '').slice(0, 70));
  mau = await api('atendimento_guardar', { ativo: 1, nome: 'Sofia ' + marca,
    chat_modo: 'script', chat_script: '' });
  ok(mau && mau.success === false, 'e escolher a ferramenta sem dizer qual também');

  const SCRIPT = 'https://chat.exemplo.invalido/loader.js';
  d = await api('atendimento_guardar', { ativo: 1, nome: 'Sofia ' + marca,
    saudacao: 'Bem-vindos ' + marca, chat_modo: 'script', chat_script: SCRIPT,
    chat_rotulo: 'Falar agora ' + marca });
  ok(d && d.success, 'com um endereço https, guarda-se');

  const comChat = await (await b.newContext()).newPage();
  comChat.on('pageerror', e => errs.push('chat: ' + e.message));
  await comChat.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await comChat.waitForSelector('#at-botao', { timeout: 8000 });
  // O script do fornecedor NÃO se carrega à entrada: fazer toda a gente pagar
  // o peso de uma coisa que a maioria não usa era o defeito a evitar.
  const antesDeAbrir = await comChat.evaluate((s) =>
    [...document.scripts].some(x => x.src === s), SCRIPT);
  ok(!antesDeAbrir, 'ao carregar a página, o script do fornecedor ainda não entrou');

  await comChat.click('#at-botao');
  await comChat.waitForTimeout(400);
  ok(await comChat.locator('#at-vivo').count() === 1, 'a caixa mostra o botão de falar ao vivo');
  ok((await comChat.textContent('#at-vivo')).includes('Falar agora'),
     'com o texto que o admin escolheu');
  ok(await comChat.evaluate(() => typeof Atendimento.registarAoVivo === 'function'),
     'e o contrato para o fornecedor está exposto (Atendimento.registarAoVivo)');

  // Um fornecedor de mentira, registado como o verdadeiro se registaria.
  await comChat.evaluate(() => {
    window.__abriu = 0;
    Atendimento.registarAoVivo({ nome: 'prova',
      pronto: () => true, abrir: () => { window.__abriu++; } });
  });
  await comChat.click('#at-vivo');
  await comChat.waitForTimeout(500);
  ok(await comChat.evaluate(() => window.__abriu) === 1,
     'carregar no botão manda abrir a janela do fornecedor');
  ok(await comChat.evaluate((s) => [...document.scripts].some(x => x.src === s), SCRIPT),
     'e é só aí que o script do fornecedor entra na página');

  // Sem ninguém registado, o botão não fica mudo: diz o que se passa.
  const sozinho = await (await b.newContext()).newPage();
  await sozinho.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await sozinho.waitForSelector('#at-botao', { timeout: 8000 });
  await sozinho.click('#at-botao'); await sozinho.waitForTimeout(300);
  await sozinho.click('#at-vivo');
  await sozinho.waitForTimeout(600);
  // O script aponta para um domínio que não existe, por isso ou ele ainda está
  // a ser esperado, ou já falhou — as duas coisas se dizem, e é isso que conta:
  // o botão não fica mudo a fingir que abriu alguma coisa.
  const estado = await sozinho.textContent('#at-vivo-estado');
  ok(/A abrir|não chegou a carregar|não está disponível/i.test(estado),
     'sem fornecedor do outro lado, o botão diz o que se passa: «' + estado.trim() + '»');
  await sozinho.context().close();
  await comChat.context().close();

  // ---------- limpeza: repor a casa como estava ----------
  await api('atendimento_faq_apagar&id=' + pid);
  await api('atendimento_guardar', {
    ativo: String(antes.ativo) === '1' ? 1 : 0, nome: antes.nome || 'Atendimento',
    cargo: antes.cargo || '', saudacao: antes.saudacao || '',
    telefone: antes.telefone || '', whatsapp: antes.whatsapp || '',
    email: antes.email || '', horario: antes.horario || '',
    chat_modo: antes.chat_modo || 'nenhum', chat_script: antes.chat_script || '',
    chat_rotulo: antes.chat_rotulo || '' });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
