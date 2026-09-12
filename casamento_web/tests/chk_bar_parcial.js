// A terceira porta da copa, o limiar de cada bebida, e a nota do garçom.
//
// O bar tinha duas portas — aprovar e recusar — e a vida do balcão tem três.
// «Pediu quatro cervejas e só há duas» não é nem uma nem outra: recusar quatro
// por causa de duas é servir ZERO, e a pessoa volta a pedir daí a um minuto.
// O que se faz de facto é servir o que se pode e dizer porquê.
//
// O que esta prova defende:
//
//   1. **O corte é mesmo um corte.** As quantidades do pedido mudam na base, o
//      que fica reservado é o que ficou, e o resto volta ao disponível. Se
//      alguém um dia reservar as quantidades ORIGINAIS depois de as cortar, o
//      bar promete bebida que não vai servir — e a conta do stock parte-se em
//      silêncio, que é a avaria que este módulo mais teme.
//   2. **Um corte sem motivo não passa.** Quem recebe menos do que pediu tem
//      direito a saber porquê; sem essa frase, pede outra vez.
//   3. **Cortar tudo não é aprovar nada.** Um pedido a zero não fica «aprovado
//      e vazio»: é uma recusa, e tem de se fazer como recusa.
//   4. **O limiar de «a acabar» é da BEBIDA.** Era 5 na copa e 8 na montagem,
//      os dois inventados: cinco garrafas de whisky é uma emergência, cinco
//      águas não é nada. Agora quem monta o menu escolhe, e os dois ecrãs leem
//      o mesmo número.
//   5. **A nota do garçom chega à copa.** Ele é o único do bar que fala com o
//      convidado; o que traz da mesa tem de aparecer quando essa pessoa pedir
//      a seguir — não num relatório que ninguém abre.
//   6. **Um pedido lançado ao balcão nasce decidido.** Quem o escreve está a
//      olhar para a pessoa e para as garrafas; pô-lo a esperar por si próprio
//      enchia a fila de trabalho imaginário.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const vigiar = (p, tag) => {
    p.on('pageerror', e => errs.push(tag + ': ' + e.message));
    p.on('console', m => { if (m.type() === 'error') errs.push(tag + ': ' + m.text()); });
  };

  // ============ montar ============
  const casa = await b.newContext({ viewport: { width: 1280, height: 950 } });
  const p = await casa.newPage();
  vigiar(p, 'copa');
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);

  const limpar = () => p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    const e2 = await window.api('bar_estado');
    for (const i of (e2.itens || []).filter(i => /^ZZP /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
  });
  await limpar();

  const base = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const cat = e.categorias[0].id;
    // Uma bebida com muito stock e limiar alto, outra com pouco e limiar baixo:
    // é a diferença que o número por bebida existe para saber exprimir.
    const mk = async (nome, stock, min) => (await window.api('bar_item_guardar', {
      method: 'POST', body: JSON.stringify({ nome, categoria_id: cat, stock,
        stock_minimo: min, visivel: 1, max_por_pedido: 9 }) })).id;
    // A cerveja tem 40 com limiar 4 (folgada); o whisky tem 12 com limiar 15
    // (a acabar). O mesmo número absoluto — 12 — seria «folgado» com o limiar
    // da cerveja: é exactamente essa diferença que o número por bebida existe
    // para saber exprimir.
    const ids = { cerveja: await mk('ZZP Cerveja', 40, 4),
                  whisky:  await mk('ZZP Whisky', 12, 15) };
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const n = await window.api('bar_procurar_pessoal&q=Convidad');
    return { ids, quem: (n.nomes || [])[0] };
  });
  ok(!!base.quem, 'há um convidado com quem provar isto');
  const token = await p.evaluate(() => window.BAR_MESAS[0].token);

  // ============ 1. o limiar é da bebida ============
  const limiares = await p.evaluate(async (ids) => {
    const e = await window.api('bar_estado');
    const acha = (id) => (e.itens || []).filter(i => i.id === id)[0];
    return { cerveja: acha(ids.cerveja), whisky: acha(ids.whisky) };
  }, base.ids);
  ok(limiares.cerveja.stock_minimo === 4 && limiares.whisky.stock_minimo === 15,
     'cada bebida guarda o SEU limiar (4 e 15), e não um número da casa');
  ok(limiares.cerveja.a_acabar === false,
     '40 cervejas com limiar 4 não estão a acabar');
  ok(limiares.whisky.a_acabar === true,
     'mas 12 whiskys com limiar 15 estão — a mesma conta, outro número');

  // ============ 2. um pedido, e o corte ============
  const cel = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  vigiar(cel, 'bebidas');
  await cel.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await cel.waitForTimeout(600);
  const pedir = (itens) => cel.evaluate(async ([t, gid, its]) => {
    await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: gid }) });
    return await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: null, itens: its }) })).json();
  }, [token, base.quem.id, itens]);

  const ped = await pedir([{ item_id: base.ids.cerveja, quantidade: 4 },
                           { item_id: base.ids.whisky,  quantidade: 2 }]);
  ok(ped.success === true, 'o convidado pede 4 cervejas e 2 whiskys');
  const pid = ped.pedido.id;

  const antes = await p.evaluate(async (ids) => {
    const e = await window.api('bar_estado');
    const a = (id) => (e.itens || []).filter(i => i.id === id)[0];
    return { cerveja: a(ids.cerveja).disponivel, whisky: a(ids.whisky).disponivel };
  }, base.ids);

  // Sem motivo, não passa: quem recebe menos do que pediu tem direito a saber.
  const semPorque = await p.evaluate(async ([id, ids]) =>
    await window.api('bar_decidir', { method: 'POST', body: JSON.stringify({
      id: id, decisao: 'aprovar',
      cortes: { [ids.cerveja]: 2, [ids.whisky]: 2 } }) }), [pid, base.ids]);
  ok(semPorque && semPorque.success === false,
     'cortar sem motivo é recusado: «' + ((semPorque || {}).message || '') + '»');

  // Cortar TUDO não é aprovar nada: é uma recusa, e faz-se como recusa.
  const tudoZero = await p.evaluate(async ([id, ids]) =>
    await window.api('bar_decidir', { method: 'POST', body: JSON.stringify({
      id: id, decisao: 'aprovar', motivo_texto: 'ZZP nada',
      cortes: { [ids.cerveja]: 0, [ids.whisky]: 0 } }) }), [pid, base.ids]);
  ok(tudoZero && tudoZero.success === false,
     'e cortar tudo também não: isso é recusar — «' + ((tudoZero || {}).message || '') + '»');

  // O corte a sério: 4 cervejas passam a 2, o whisky sai do pedido.
  const cortou = await p.evaluate(async ([id, ids]) =>
    await window.api('bar_decidir', { method: 'POST', body: JSON.stringify({
      id: id, decisao: 'aprovar',
      motivo_texto: 'ZZP só restam duas — guardo-lhe as próximas',
      cortes: { [ids.cerveja]: 2, [ids.whisky]: 0 } }) }), [pid, base.ids]);
  ok(cortou && cortou.success === true, 'com motivo, o corte passa');
  ok(cortou.pedido.estado === 'aprovado', 'e o pedido fica APROVADO, não recusado');
  const linhas = (cortou.pedido.itens || []);
  ok(linhas.length === 1 && linhas[0].quantidade === 2,
     'o pedido ficou com uma linha de 2 — a outra saiu: '
     + linhas.map(l => l.quantidade + '× ' + l.nome).join(', '));
  ok(/só restam duas/.test(cortou.pedido.motivo || ''),
     'e o motivo fica guardado NO pedido, que é onde o convidado o lê');

  // A conta do stock: reserva-se o que FICOU, e não o que se pediu.
  const depois = await p.evaluate(async (ids) => {
    const e = await window.api('bar_estado');
    const a = (id) => (e.itens || []).filter(i => i.id === id)[0];
    return { cerveja: a(ids.cerveja).disponivel, whisky: a(ids.whisky).disponivel };
  }, base.ids);
  ok(depois.cerveja === antes.cerveja - 2,
     'promete-se 2 cervejas e não 4: o que se reserva é o que se vai servir ('
     + antes.cerveja + ' → ' + depois.cerveja + ')');
  ok(depois.whisky === antes.whisky,
     'e o whisky que saiu do pedido não fica prometido a ninguém ('
     + antes.whisky + ' → ' + depois.whisky + ')');

  // O convidado vê o novo número e o motivo — é isso que o impede de repetir.
  const meu = await cel.evaluate(async (t) =>
    await (await fetch('api.php?action=bar_meus_pedidos&m=' + t)).json(), token);
  const oMeu = ((meu.pedidos || [])).filter(x => x.id === pid)[0];
  ok(oMeu && (oMeu.itens || []).length === 1 && oMeu.itens[0].quantidade === 2,
     'e no telemóvel dele o pedido já é o pedido cortado');
  ok(oMeu && /só restam duas/.test(oMeu.motivo || ''),
     'com o motivo à vista, e não uma quantidade que encolheu em silêncio');

  // ============ 3. a nota do garçom ============
  const entregue = await p.evaluate(async (id) => {
    await window.api('bar_apanhar', { method: 'POST', body: JSON.stringify({ id: id }) });
    return await window.api('bar_entregue', { method: 'POST',
      body: JSON.stringify({ id: id, nota: 'ZZP pediu para não lhe servirem mais' }) });
  }, pid);
  ok(entregue && entregue.success === true, 'o garçom entrega, e escreve o que viu à mesa');

  const pedido2 = await pedir([{ item_id: base.ids.cerveja, quantidade: 1 }]);
  ok(pedido2.success === true, 'a mesma pessoa pede outra vez');
  const notas = await p.evaluate(async (gid) => {
    const e = await window.api('bar_estado');
    return (e.notas || {})[String(gid)] || [];
  }, base.quem.id);
  ok(notas.length === 1 && /não lhe servirem mais/.test(notas[0].texto),
     'e a copa lê a nota ao decidir o pedido seguinte dessa pessoa');

  // Aparece mesmo no ecrã, colada ao pedido — não só na resposta da API.
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1300);
  const noEcra = await p.evaluate(() => {
    const n = document.querySelector('.b-ped .b-notas');
    return n ? n.innerText.replace(/\s+/g, ' ') : '';
  });
  ok(/não lhe servirem mais/.test(noEcra),
     'e vê-se no cartão, e não só nos dados: «' + noEcra.slice(0, 70) + '»');

  // ============ 4. o pedido do balcão nasce decidido ============
  const balcao = await p.evaluate(async ([gid, iid]) =>
    await window.api('bar_pedir_por', { method: 'POST', body: JSON.stringify({
      convidado_id: gid, itens: [{ item_id: iid, quantidade: 1 }] }) }),
    [base.quem.id, base.ids.cerveja]);
  ok(balcao && balcao.pedido.estado === 'aprovado',
     'um pedido lançado ao balcão nasce aprovado — quem o escreve já o decidiu');

  const antesBalcao = await p.evaluate(async (id) => {
    const e = await window.api('bar_estado');
    return (e.itens || []).filter(i => i.id === id)[0];
  }, base.ids.cerveja);
  const jaFoi = await p.evaluate(async ([gid, iid]) =>
    await window.api('bar_pedir_por', { method: 'POST', body: JSON.stringify({
      convidado_id: gid, entregue: true,
      itens: [{ item_id: iid, quantidade: 1 }] }) }),
    [base.quem.id, base.ids.cerveja]);
  ok(jaFoi && jaFoi.pedido.estado === 'entregue',
     'e com o copo já na mão nasce ENTREGUE, sem passar por entrega nenhuma');
  const depoisBalcao = await p.evaluate(async (id) => {
    const e = await window.api('bar_estado');
    return (e.itens || []).filter(i => i.id === id)[0];
  }, base.ids.cerveja);
  // A regra de ouro do módulo: aprovar PROMETE, entregar BAIXA. Um pedido que
  // nasce entregue tem de fazer as duas coisas, senão o stock diverge — e
  // mede-se a diferença, e não o total, porque outras coisas já saíram nesta
  // mesma corrida.
  ok(depoisBalcao.stock === antesBalcao.stock - 1,
     'e baixa o stock real na hora (' + antesBalcao.stock + ' → ' + depoisBalcao.stock + ')');
  ok(depoisBalcao.reservado === antesBalcao.reservado,
     'sem passar por «prometido»: já não há nada para prometer, a bebida saiu');

  // ============ 5. o código do convite já não existe ============
  const semPin = await p.evaluate(async () => {
    const d = await window.api('bar_estado');
    return { defs: Object.keys(d.defs || {}).filter(k => /pin|garcon/.test(k)) };
  });
  ok(semPin.defs.length === 0,
     'as regras da casa já não têm o código do convite nem o interruptor do garçom'
     + (semPin.defs.length ? ': ' + semPin.defs.join(', ') : ''));

  // ============ arrumar ============
  await limpar();
  await p.evaluate(async () => { await window.api('bar_fechar', { method: 'POST', body: '{}' }); });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
