// Orçamento — o curso das despesas.
//
// Prova o módulo de ponta a ponta: as contas do resumo, o isolamento entre
// casamentos (um casal não vê o orçamento de outro) e — o que mais importa —
// a ida e volta no export/import: um casamento levado para um ficheiro e
// trazido para outro tem de chegar com as gavetas, as despesas, as parcelas E
// o teto (que vive nas definições).
//
// Corre com AMBITO_ESTRITO=1: qualquer consulta ao orçamento sem dizer de que
// casamento é rebenta a página, e a prova apanha-a.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext()).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const N = v => Number(v) || 0;

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });
  const abrir = (id) => api('casamento_abrir&id=' + id, {});
  const catId = (estado, nome) => (estado.categorias.find(c => c.nome === nome) || {}).id;

  // ---------- terreno: dois casamentos frescos ----------
  const w1 = await api('casamento_criar', { nome: 'PROVA Orçamento A', noiva: 'Ana', noivo: 'Bento' });
  const w2 = await api('casamento_criar', { nome: 'PROVA Orçamento B', noiva: 'Rita', noivo: 'Zé' });
  if (!w1.success || !w2.success) { console.log('FAIL: não criou os casamentos'); await b.close(); process.exit(1); }
  console.log('   casamentos:', w1.id, w2.id);

  // ---------- as gavetas de origem ----------
  await abrir(w1.id);
  let e1 = await api('orc_estado');
  ok(e1 && e1.success, 'orc_estado responde num casamento novo');
  ok((e1.categorias || []).length >= 10, 'um casamento novo já nasce com as gavetas de origem');
  const catFoto = catId(e1, 'Fotografia e vídeo');
  ok(!!catFoto, 'existe a gaveta "Fotografia e vídeo"');

  // ---------- teto, moeda e despesas ----------
  await api('orc_ajuste', { total: '2000000', moeda: 'AOA' });
  await api('orc_despesa_guardar', { descricao: 'Reportagem foto+vídeo', valor: '500000', estado: 'pago', categoria_id: catFoto });
  const dContr = await api('orc_despesa_guardar', { descricao: 'Banda', valor: '300000', estado: 'contratado', categoria_id: catFoto });
  await api('orc_despesa_guardar', { descricao: 'Lembranças', valor: '200000', estado: 'previsto' });

  e1 = await api('orc_estado');
  const r = e1.resumo;
  console.log('   resumo A:', JSON.stringify({ pago: r.pago, contratado: r.contratado, previsto: r.previsto, teto: r.teto, falta: r.falta }));
  ok(N(r.pago) === 500000, 'o pago soma 500 000');
  ok(N(r.contratado) === 300000, 'o contratado soma 300 000');
  ok(N(r.previsto) === 200000, 'o previsto soma 200 000');
  ok(N(r.comprometido) === 800000, 'o comprometido é contratado + pago = 800 000');
  ok(N(r.teto) === 2000000, 'o teto ficou nos 2 000 000');
  ok(N(r.falta) === 1200000, 'a margem até ao teto é 1 200 000');
  ok(e1.moeda === 'AOA', 'a moeda ficou em AOA');
  const catFotoReal = N((e1.categorias.find(c => c.id === catFoto) || {}).real_total);
  ok(catFotoReal === 800000, 'a gaveta "Fotografia e vídeo" soma o real das suas duas despesas');

  // ---------- uma parcela, e dá-se por paga ----------
  await api('orc_pagamento_guardar', { despesa_id: dContr.id, valor: '150000', data_prevista: '2026-10-01' });
  e1 = await api('orc_estado');
  ok((e1.pagamentos || []).length === 1, 'a parcela aparece no calendário');
  const pagId = e1.pagamentos[0].id;
  await api('orc_pagamento_liquidar', { id: pagId, pago: 1 });
  e1 = await api('orc_estado');
  ok(!!(e1.pagamentos[0].pago_em), 'dar por paga marca a data de pagamento');

  // ---------- isolamento: o B não vê nada do A ----------
  await abrir(w2.id);
  const e2 = await api('orc_estado');
  ok((e2.despesas || []).length === 0, 'o casamento B não vê despesa nenhuma do A');
  ok(N(e2.resumo.comprometido) === 0, 'e o comprometido do B é zero');

  // ---------- export do A: o orçamento vai no retrato ----------
  await abrir(w1.id);
  const dump = await p.evaluate(async () => {
    const r = await fetch('api.php?action=dados_exportar', { headers: { 'X-CSRF-Token': window.CSRF } });
    return r.json();
  });
  const oA = (dump.casamentos || [])[0] || {};
  ok(oA.orcamento && (oA.orcamento.categorias || []).length >= 10, 'o export leva as categorias');
  ok((oA.orcamento.despesas || []).length === 3, 'o export leva as três despesas');
  const dumpBanda = (oA.orcamento.despesas || []).find(d => d.descricao === 'Banda') || {};
  ok((dumpBanda.pagamentos || []).length === 1, 'e leva a parcela dentro da sua despesa');
  ok(dumpBanda.categoria === 'Fotografia e vídeo', 'a despesa guarda o NOME da gaveta, não o número');
  ok((oA.definicoes || {})['orcamento.total'] === '2000000.00', 'o teto viaja nas definições');

  // ---------- import para um casamento novo: chega tudo ----------
  const imp = await api('dados_importar', { modo: 'novo', ficheiro: dump });
  ok(imp && imp.success, 'o import cria um casamento novo a partir do ficheiro');
  const w3 = ((imp.resumo || [])[0] || {}).id;
  ok(!!w3, 'e devolve o id do casamento criado');
  console.log('   casamento importado:', w3);

  await abrir(w3);
  const e3 = await api('orc_estado');
  ok((e3.despesas || []).length === 3, 'o casamento importado tem as três despesas');
  ok(N(e3.resumo.comprometido) === 800000, 'com as mesmas contas (comprometido 800 000)');
  ok(N(e3.resumo.teto) === 2000000, 'e com o teto que viajou nas definições');
  ok(e3.moeda === 'AOA', 'e com a moeda AOA');
  ok((e3.pagamentos || []).length === 1 && !!e3.pagamentos[0].pago_em, 'a parcela paga também atravessou');
  const impBanda = (e3.despesas || []).find(d => d.descricao === 'Banda') || {};
  const impCat = (e3.categorias.find(c => c.id === impBanda.categoria_id) || {}).nome;
  ok(impCat === 'Fotografia e vídeo', 'a despesa reencontrou a sua gaveta pelo nome');

  // ---------- o teto pelo formulário do admin (casamento_criar) ----------
  const wForm = await api('casamento_criar', { nome: 'PROVA Teto Admin', noiva: 'F', noivo: 'G', orcamento_total: '3 000 000,00' });
  ok(wForm && wForm.success, 'o admin cria um casamento com orçamento total');
  await abrir(wForm.id);
  const eForm = await api('orc_estado');
  ok(N(eForm.resumo.teto) === 3000000, 'o orçamento total do formulário do admin fica gravado como teto');
  ok((eForm.categorias || []).length >= 10, 'e o casamento criado à mão nasce com as gavetas');

  // ---------- o teto pelo registo público ----------
  const anon = await (await b.newContext()).newPage();
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  const emailPub = 'prova.orc.' + Date.now() + '@exemplo.ao';
  const reg = await anon.evaluate(async (email) => {
    const r = await fetch('api.php?action=registo_publico', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ noiva: 'Púb', noivo: 'Lica', email: email, senha: '12345678', orcamento_total: '1 800 000,00' }) });
    return r.json();
  }, emailPub);
  ok(reg && reg.success, 'o registo público aceita o orçamento total');
  const cidPub = reg.casamento;
  await anon.close();
  await abrir(cidPub);
  const ePub = await api('orc_estado');
  ok(N(ePub.resumo.teto) === 1800000, 'o teto indicado no registo público fica gravado');
  ok((ePub.categorias || []).length >= 10, 'e o casamento do registo público nasce com as gavetas');

  // ---------- o teto define-se na Gestão ----------
  await abrir(w1.id);
  await p.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(300);
  ok(!!(await p.$('#d-orc-total')), 'a Gestão tem o campo do orçamento total');
  await p.fill('#d-orc-total', '4500000');
  await p.click('button[onclick="guardarOrcamento()"]');
  await p.waitForTimeout(400);
  const eGest = await api('orc_estado');
  ok(N(eGest.resumo.teto) === 4500000, 'guardar na Gestão altera o teto do orçamento');

  // ---------- a página desenha ----------
  await p.goto(BASE + '/orcamento.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(400);
  const comprometidoTxt = await p.textContent('#c-comprometido');
  ok(comprometidoTxt && !/^—/.test(comprometidoTxt.trim()), 'a página mostra o comprometido, não um traço');
  const segmentos = await p.$$eval('#c-barra span', els => els.length);
  ok(segmentos >= 3, 'a barra do curso tem os três segmentos (pago, contratado, previsto)');
  // O teto já não se define aqui — mudou-se para a Gestão.
  ok(!(await p.$('#a-total')), 'a página do orçamento já não tem o campo do teto');
  const scroll = await p.$('.tabela-scroll');
  ok(!!scroll, 'a tabela de despesas está num quadro que rola (responsiva)');

  // O porteiro não chega aqui: a página é dos noivos.
  ok(!(await p.$('nav a[href="orcamento.php"]')) === false, 'o menu tem a entrada do Orçamento para o admin');

  // limpeza das contas do registo público (casamento primeiro, depois a conta órfã)
  await api('casamento_estado&id=' + cidPub + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + cidPub, {});
  const listaU = await api('utilizador_lista');
  const conta = (listaU.contas || []).find(u => u.email === emailPub);
  if (conta) await api('utilizador_apagar&id=' + conta.id, {});

  // ---------- limpeza ----------
  for (const id of [w1.id, w2.id, w3, wForm.id]) {
    await api('casamento_estado&id=' + id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + id, {});
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
