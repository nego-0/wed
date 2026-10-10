// O histórico da licença, do lado do casal.
//
// Três coisas que faltavam, e todas do mesmo feitio: informação que a casa
// tinha e o casal não via.
//
//   1. As NOTAS que o admin escreve ao decidir só apareciam nas recusas. Numa
//      aprovação — «concedido por 12 meses, o prazo conta do dia 1» — a nota
//      era escrita e nunca chegava a ninguém.
//   2. Uma licença REVOGADA deixava um histórico que acabava no dia em que ela
//      fora concedida, como se depois nada tivesse acontecido. A revogação não
//      é um pedido, e por isso não estava na lista de pedidos — mas é a coisa
//      que o casal mais precisa de ver, com a data e o motivo.
//   3. Cada linha era um resumo sem forma de o abrir. Quem tem dúvidas sobre a
//      conta quer o detalhe: o que cada módulo custou, o que foi descontado, e
//      a nota inteira — em papel, se for preciso.
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
  const marca = 'lh' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  const cat = (await api('lic_catalogo')).catalogo;
  const esc = (m, e) => cat.modulos.find(x => x.chave === m).escaloes.find(x => x.chave === e);
  const conv80 = esc('convidados', 'convidados_80');
  const conv200 = esc('convidados', 'convidados_200');
  const prazo = (cat.prazos || []).find(p => Math.abs(+p.fator - 1) < 0.001);

  const email = 'casal.' + marca + '@exemplo.ao';
  let d = await api('casamento_criar', { nome: 'Histórico ' + marca, data: '2027-12-04',
                                         noivos_email: email, noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  // Um casamento criado pela administração nasce com tudo aberto — quem o criou
  // já decidiu. Para esta prova precisa-se do princípio da história, com o
  // casal ainda sem licença nenhuma: tira-se-lhe tudo, e ele pede do zero.
  d = await api('lic_conceder', { casamento: cid, escaloes: [], meses: 0 });
  ok(d && d.success && d.modulos === 0, 'e deixou-o sem licença, para a história começar do zero');

  const casal = await entrar(await b.newContext(), email, 'senhaforte123');
  casal.on('pageerror', e => errs.push('licenca: ' + e.message));
  const apiC = casal._api;

  // ---------- o percurso: pedido, aprovação com nota, reforço, revogação ----------
  const NOTA_OK = 'Concedido. O prazo conta a partir de hoje, ' + marca + '.';
  d = await apiC('lic_pedir', { pacote: 0, escaloes: [conv80.id], meses: prazo.meses,
                                aceito: true, nota: 'o nosso primeiro pedido' });
  ok(d && d.success, 'o casal pede');
  let peds = await api('lic_pedidos&estado=pendente');
  let pid = (peds.pedidos || []).find(p => +p.casamento_id === cid).id;
  d = await api('lic_decidir', { id: pid, decisao: 'aprovar', nota: NOTA_OK });
  ok(d && d.success, 'a administração aprova, com uma nota escrita');

  // ---------- 1. a nota de uma APROVAÇÃO chega ao casal ----------
  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForSelector('#lic-hist .lic-h', { timeout: 8000 });
  let txt = await casal.textContent('#lic-hist');
  ok(txt.includes(NOTA_OK.slice(0, 60)),
     'a nota que o admin escreveu na APROVAÇÃO aparece ao casal');
  ok(/Nota da administra/i.test(txt), 'e vem rotulada, para se saber de quem é');

  // ---------- 2. clicar abre o detalhe, e o detalhe tem o que a linha não cabe ----------
  await casal.click('#lic-hist .lic-h-clic');
  await casal.waitForSelector('#lic-janela.on', { timeout: 5000 });
  let det = await casal.textContent('#lic-janela .pl-modal-corpo');
  ok(/Pedido inicial/i.test(det), 'o detalhe diz que tipo de decisão foi');
  ok(det.includes(NOTA_OK), 'traz a nota INTEIRA, e não cortada');
  ok(/Até 80 convidados/i.test(det), 'e o escalão que foi concedido');
  ok(/Pedido em/i.test(det) && /Decidido em/i.test(det),
     'com as duas datas: quando se pediu e quando se decidiu');
  const temImprimir = await casal.evaluate(() =>
    [...document.querySelectorAll('#lic-janela .pl-modal-rodape button')]
      .some(b => /imprimir/i.test(b.textContent)));
  ok(temImprimir, 'e um botão de imprimir');

  // Imprimir marca o corpo, para o CSS deixar sair só o detalhe: sem isto, a
  // montra dos planos ia toda atrás no papel.
  await casal.evaluate(() => { window.print = () => {}; imprimirDetalhe(); });
  await casal.waitForTimeout(200);
  ok(await casal.evaluate(() => document.body.classList.contains('a-imprimir-detalhe')),
     'imprimir isola o detalhe do resto da página');
  await casal.evaluate(() => document.body.classList.remove('a-imprimir-detalhe'));
  await casal.click('#lic-jc'); await casal.waitForTimeout(200);

  // ---------- um reforço, para o detalhe ter desconto que mostrar ----------
  d = await apiC('lic_pedir', { pacote: 0, escaloes: [conv200.id], meses: prazo.meses,
                                aceito: true });
  ok(d && d.success, 'o casal pede um reforço');
  peds = await api('lic_pedidos&estado=pendente');
  pid = (peds.pedidos || []).find(p => +p.casamento_id === cid).id;
  await api('lic_decidir', { id: pid, decisao: 'aprovar', nota: '' });

  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForSelector('#lic-hist .lic-h', { timeout: 8000 });
  await casal.click('#lic-hist .lic-h-clic');   // o mais recente: o reforço
  await casal.waitForSelector('#lic-janela.on', { timeout: 5000 });
  det = await casal.textContent('#lic-janela .pl-modal-corpo');
  ok(/Reforço/i.test(det), 'o detalhe de um reforço diz que é um reforço');
  ok(/Já pago/i.test(det) && /Diferença paga/i.test(det),
     'e mostra o que já estava pago e a diferença — a conta explica-se sozinha');
  await casal.click('#lic-jc'); await casal.waitForTimeout(200);

  // ---------- 3. a revogação entra no histórico, com data e motivo ----------
  const MOTIVO = 'Partilha de credenciais com terceiros (ponto 2 das políticas).';
  d = await api('lic_revogar', { casamento: cid, motivo: MOTIVO });
  ok(d && d.success, 'a administração revoga a licença');

  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(900);
  const visivel = await casal.locator('#lic-hist').isVisible();
  ok(visivel, 'com a licença revogada, o histórico CONTINUA visível');
  txt = await casal.textContent('#lic-hist');
  ok(/Licença revogada/i.test(txt), 'e a revogação é uma linha dele');
  ok(txt.includes(MOTIVO.slice(0, 50)), 'com o motivo que o admin escreveu');

  await casal.click('#lic-hist .lic-h-clic');   // a revogação, que é a mais recente
  await casal.waitForSelector('#lic-janela.on', { timeout: 5000 });
  det = await casal.textContent('#lic-janela .pl-modal-corpo');
  ok(/Data da revoga/i.test(det), 'o detalhe da revogação traz a data');
  ok(det.includes(MOTIVO), 'e o motivo por inteiro');
  ok(/continua revogada/i.test(det), 'e diz que a licença ainda está revogada');

  // ---------- 4. uma recusa diz-se UMA vez ----------
  // Havia um cartão «O seu pedido anterior não foi aceite» por cima do
  // histórico, a repetir o que a linha da recusa já diz. Duas vezes a mesma
  // coisa, uma por cima da outra, fazia parecer que eram duas decisões.
  const MOTIVO_NAO = 'Faltam os dados de facturação.';
  d = await apiC('lic_pedir', { pacote: 0, escaloes: [conv80.id], meses: prazo.meses, aceito: true });
  ok(d && d.success, 'o casal pede outra vez');
  peds = await api('lic_pedidos&estado=pendente');
  pid = (peds.pedidos || []).find(p => +p.casamento_id === cid).id;
  d = await api('lic_decidir', { id: pid, decisao: 'recusar', nota: MOTIVO_NAO });
  ok(d && d.success, 'e a administração recusa, com o motivo escrito');

  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForSelector('#lic-hist .lic-h', { timeout: 8000 });
  const cartao = await casal.evaluate(() => {
    const cx = document.getElementById('lic-pedido-cx');
    return { texto: (cx.textContent || '').trim(),
             visivel: !!cx && cx.offsetParent !== null };
  });
  ok(!cartao.visivel && cartao.texto === '',
     'não há cartão nenhum a repetir a recusa por cima do histórico');
  const pagina = await casal.textContent('body');
  ok(pagina.includes(MOTIVO_NAO), 'o motivo da recusa lê-se no histórico');
  ok((pagina.match(/Faltam os dados de facturação/g) || []).length === 1,
     'e uma só vez em toda a página — a recusa é uma decisão, não duas');

  // ---------- limpeza ----------
  await api('lic_conceder', { casamento: cid, escaloes: [], meses: 0 });
  await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + cid, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
