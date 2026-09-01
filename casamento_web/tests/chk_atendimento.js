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
  await publica.waitForTimeout(400);
  txt = await publica.textContent('#at-fio');
  ok(txt.includes(PERG) && txt.includes(RESP),
     'tocar na pergunta escreve-a e responde-lhe, pela ordem em que se lê');

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

  // ---------- limpeza: repor a casa como estava ----------
  await api('atendimento_faq_apagar&id=' + pid);
  await api('atendimento_guardar', {
    ativo: String(antes.ativo) === '1' ? 1 : 0, nome: antes.nome || 'Atendimento',
    cargo: antes.cargo || '', saudacao: antes.saudacao || '',
    telefone: antes.telefone || '', whatsapp: antes.whatsapp || '',
    email: antes.email || '', horario: antes.horario || '' });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
