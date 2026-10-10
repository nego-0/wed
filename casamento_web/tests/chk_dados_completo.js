// Levar os dados de um casamento leva-os TODOS.
//
// A exportação existe para uma coisa: o casal poder sair daqui com o que é
// dele. Uma exportação que deixa uma parte para trás é pior do que nenhuma,
// porque ninguém confere um ficheiro de trinta mil linhas — confia. E o que
// não vai no ficheiro descobre-se do outro lado, quando já não há volta.
//
// Duas faltas, encontradas a olhar tabela a tabela contra o que o retrato
// escrevia:
//
//   1. AS PERGUNTAS DO RSVP E AS RESPOSTAS. «Tem alergias alimentares?»,
//      «precisa de transporte?» — perguntas que o casal escreveu, e as
//      respostas que são o que a lista de convidados tem de mais útil na
//      véspera. Não iam nem voltavam. Levar a lista e deixar isto para trás é
//      levar a lista e esquecer o que ela dizia.
//
//   2. COMO SE SERVE CADA BEBIDA. O `servir` e o `doses_garrafa` ficavam de
//      fora, e uma carta importada chegava com tudo ao copo — o espumante da
//      meia-noite incluído. O `stock_minimo` também: o «avisar quando
//      restarem» é por bebida de propósito (cinco garrafas de whisky é uma
//      emergência, cinco águas não é nada), e todas voltavam ao número de
//      fábrica.
//
// O que fica DE FORA continua a ficar, e por razões que se escrevem: os
// pedidos, os movimentos de stock, os alertas e os telemóveis do bar são o
// estado de uma festa a decorrer, e trazê-los de outra base punha o stock a
// mentir. Essa linha — a montagem viaja, a noite não — é a mesma de sempre.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzd' + Math.floor(Math.random() * 1e5);

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push('admin: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json().catch(() => ({ success: false }));
  }, { a, c });
  const licenciar = (id) => p.evaluate(async i => {
    const cat = await window.api('lic_catalogo');
    const mods = (cat.catalogo.modulos || []).filter(
      x => ['convidados', 'mesas', 'bar'].includes(x.chave));
    await window.api('lic_conceder', { method: 'POST', body: JSON.stringify(
      { casamento: i, escaloes: mods.map(x => x.escaloes[x.escaloes.length - 1].id), meses: 12 }) });
  }, id);

  // ============ a casa de origem ============
  const A = await api('casamento_criar',
    { nome: 'ZZD Origem ' + marca, noiva: 'Ana', noivo: 'Bento' });
  ok(!!A.id, 'um casamento de origem, com o bar licenciado');
  await licenciar(A.id);
  await api('casamento_abrir&id=' + A.id);

  // ---- o que o casal escreveu: as perguntas do RSVP ----
  await api('rsvp_perguntas_guardar', { perguntas: [
    { chave: 'alergias',   rotulo: 'Tem alergias alimentares?', tipo: 'texto',
      por_pessoa: 1, ordem: 1, ativa: 1 },
    { chave: 'transporte', rotulo: 'Precisa de transporte?', tipo: 'sim_nao',
      por_pessoa: 0, ordem: 2, ativa: 1 } ] });

  const cv = await api('convite_save', { nome_exibicao: 'ZZD Família ' + marca,
    membros: [{ nome: 'ZZD Rui' }, { nome: 'ZZD Sara' }] });
  ok(!!(cv.convite && cv.convite.codigo), 'um convite com duas pessoas lá dentro');

  // ---- e o que os convidados responderam ----
  const resp = await p.evaluate(async c => {
    const r = await fetch('api.php?action=rsvp_submit', { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ codigo: c, decisao: 'sim', confirmados: 2, respostas: [
        { chave: 'transporte', valor: 'sim', convidado: 0 },
        { chave: 'alergias',   valor: 'marisco', convidado: 0 } ] }) });
    return r.json();
  }, cv.convite.codigo);
  ok(resp && resp.success, 'e as respostas dele ao RSVP');

  // ---- o bar, com as bebidas a dizer como se servem ----
  const esp = await api('bar_item_guardar', { nome: 'ZZD Espumante ' + marca,
    servir: 'garrafa', doses_garrafa: 6, stock_minimo: 3, max_por_pedido: 4, alcoolico: 1 });
  const tin = await api('bar_item_guardar', { nome: 'ZZD Tinto ' + marca,
    servir: 'ambos', doses_garrafa: 8, stock_minimo: 12, alcoolico: 1 });
  await api('bar_stock_repor', { item_id: esp.id, quantidade: 30, nota: 'prova' });
  ok(esp.success && tin.success, 'duas bebidas: uma só à garrafa, outra das duas maneiras');

  // ============ 1. o ficheiro leva tudo ============
  const dump = await p.evaluate(async id => {
    const r = await fetch('api.php?action=dados_exportar&id=' + id);
    return r.json();
  }, A.id);
  const r0 = (dump.casamentos || [])[0] || {};

  ok(!!(r0.rsvp && (r0.rsvp.perguntas || []).length === 2),
     'o ficheiro leva as perguntas do RSVP que o casal escreveu: '
     + ((r0.rsvp && r0.rsvp.perguntas) || []).length);
  ok(!!(r0.rsvp && (r0.rsvp.respostas || []).length === 2),
     'e as respostas que os convidados deram: '
     + ((r0.rsvp && r0.rsvp.respostas) || []).length);
  // As respostas prendem-se por CÓDIGO, e não por um número desta base: um id
  // não sobrevive a uma importação, e uma resposta presa a um id órfão é uma
  // resposta perdida com o ar de estar lá.
  const umaResp = ((r0.rsvp && r0.rsvp.respostas) || [])[0] || {};
  ok(umaResp.codigo === cv.convite.codigo,
     'presas ao convite pelo código, e não por um número desta base: '
     + umaResp.codigo);

  const doEsp = (r0.bar && r0.bar.itens || []).find(x => /Espumante/.test(x.nome)) || {};
  const doTin = (r0.bar && r0.bar.itens || []).find(x => /Tinto/.test(x.nome)) || {};
  ok(doEsp.servir === 'garrafa' && doTin.servir === 'ambos',
     'e cada bebida leva COMO SE SERVE — ia tudo ao copo, o espumante da '
     + 'meia-noite incluído: «' + doEsp.servir + '» / «' + doTin.servir + '»');
  ok(+doTin.doses_garrafa === 8,
     'com os copos que saem de uma garrafa: ' + doTin.doses_garrafa);
  ok(+doEsp.stock_minimo === 3 && +doTin.stock_minimo === 12,
     'e o «avisar quando restarem» de cada uma, que é por bebida de propósito: '
     + doEsp.stock_minimo + ' / ' + doTin.stock_minimo);

  // A montagem viaja, a NOITE não. Esta linha é de propósito e tem de ficar.
  ok(!(r0.bar || {}).pedidos && !(r0.bar || {}).movimentos,
     'os pedidos e os movimentos de stock continuam de fora — são o estado de '
     + 'uma festa a decorrer, e de outra base punham o stock a mentir');

  // ============ 2. e a casa do outro lado recebe tudo ============
  const B = await api('casamento_criar',
    { nome: 'ZZD Destino ' + marca, noiva: 'Ana', noivo: 'Bento' });
  await licenciar(B.id);
  await api('casamento_abrir&id=' + B.id);
  const imp = await p.evaluate(async d => {
    const r = await fetch('api.php?action=dados_importar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ficheiro: d, modo: 'substituir' }) });
    return r.json().catch(() => ({ success: false }));
  }, dump);
  const res = (imp.resumo || [])[0] || {};
  ok(imp.success && res.id === B.id,
     'o ficheiro entra no casamento do lado: ' + (imp.message || ('#' + res.id)));
  ok(res.rsvp_perguntas === 2 && res.rsvp_respostas === 2,
     'e o resumo conta o RSVP que entrou: ' + res.rsvp_perguntas + ' perguntas, '
     + res.rsvp_respostas + ' respostas');

  // ---- e está mesmo lá, lido pelas mesmas portas por onde a casa o lê ----
  const chegou = await p.evaluate(async () => {
    const q = await (await fetch('api.php?action=rsvp_perguntas')).json();
    const e = await window.api('bar_estado');
    return { perguntas: (q.perguntas || []).map(x => x.chave + ':' + x.tipo),
             itens: (e.itens || []).map(x => ({ n: x.nome, s: x.servir,
                                                d: +x.doses_garrafa, m: +x.stock_minimo })) };
  });
  ok(chegou.perguntas.length === 2
     && chegou.perguntas.join('|').includes('alergias:texto')
     && chegou.perguntas.join('|').includes('transporte:sim_nao'),
     'as perguntas chegaram inteiras, com o tipo de cada uma: '
     + chegou.perguntas.join(', '));

  const nEsp = chegou.itens.find(x => /Espumante/.test(x.n)) || {};
  const nTin = chegou.itens.find(x => /Tinto/.test(x.n)) || {};
  ok(nEsp.s === 'garrafa' && nTin.s === 'ambos' && nTin.d === 8,
     'e as bebidas chegaram a saber como se servem: «' + nEsp.s + '» / «'
     + nTin.s + '» (' + nTin.d + ' copos por garrafa)');
  // A que só sai à garrafa chega com o campo anulado, como se ela tivesse
  // nascido aqui: um ficheiro não pode contornar por trás o que o formulário
  // e o servidor já não deixam escrever.
  ok(nEsp.d === 1,
     'com «copos por garrafa» anulado na que só sai à garrafa, como se tivesse '
     + 'nascido nesta casa: ' + nEsp.d);
  ok(nEsp.m === 3 && nTin.m === 12,
     'e cada uma com o seu limiar de «a acabar»: ' + nEsp.m + ' / ' + nTin.m);

  // AS RESPOSTAS REENCONTRARAM O CONVITE. Confere-se pela única porta que as
  // lê — a exportação do destino —, o que também prova a volta inteira: o que
  // saiu de uma casa entrou na outra e volta a sair de lá igual.
  //
  // O código do convite MUDOU à entrada, e tinha de mudar: o original ainda
  // vive nesta casa, e dois convites com o mesmo código abriam-se um ao
  // outro. Era aqui que as respostas se perdiam — presas ao código do
  // ficheiro, que já não é o código de ninguém.
  const revolta = await p.evaluate(async id => {
    const r = await fetch('api.php?action=dados_exportar&id=' + id);
    return r.json();
  }, B.id);
  const rB = (revolta.casamentos || [])[0] || {};
  const respB = (rB.rsvp && rB.rsvp.respostas) || [];
  ok(respB.length === 2,
     'e as respostas saem outra vez do destino, inteiras: ' + respB.length);
  const chaves = respB.map(x => x.chave + '=' + x.valor).sort().join(', ');
  ok(chaves === 'alergias=marisco, transporte=sim',
     'com o que cada pessoa respondeu, e não só a contagem: ' + chaves);
  const codB = ((rB.convites || [])[0] || {}).codigo;
  ok(!!codB && respB.every(x => x.codigo === codB),
     'presas ao convite DESTA casa, cujo código teve de mudar à entrada — o '
     + 'original ainda cá vive: «' + cv.convite.codigo + '» → «' + codB + '»');

  // ---- arrumar: a prova não deixa casamentos para trás ----
  for (const id of [A.id, B.id]) {
    await api('casamento_apagar&id=' + id, { confirmar: 'APAGAR' });
  }
  const sobrou = await p.evaluate(async m => {
    const d = await window.api('casamentos');
    return (d.casamentos || []).filter(x => String(x.nome).includes(m)).map(x => x.nome);
  }, marca);
  ok(sobrou.length === 0,
     'a prova não deixa casamentos para trás: ' + (sobrou.join(', ') || 'nenhum'));

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  await b.close();
  console.log(f ? '\n' + f + ' FALHA(S)' : '\nTUDO VERDE');
  process.exit(f ? 1 : 0);
})();
