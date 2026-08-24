// Levar, trazer e repor os dados — por partes.
//
// O casal escolhe o que leva, o que traz e o que repõe de fábrica (lista de
// convidados, mesas, versões, orçamento). O admin faz o mesmo à escala da casa
// (casamentos, modelos, contas). O que se prova é que cada operação mexe SÓ no
// que se assinala — e deixa o resto quieto.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('#utilizador', 'admin'); await p.fill('#senha', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  const baixar = (q) => p.evaluate(async (q) => (await fetch('api.php?action=dados_exportar' + q)).json(), q);
  const mk = 'zz' + String(Date.now()).slice(-6);

  const w = await api('casamento_criar', { nome: 'ZZ Sel ' + mk, noiva: 'Sara', noivo: 'Sim' }, 1);
  await api('casamento_abrir&id=' + w.id, {});
  await api('mesa_save', { id: 0, nome: 'Mesa ' + mk, capacidade: 6, forma: 'redonda', cor: 'ouro' }, 1);
  await api('convite_save', { nome_exibicao: 'Fam ' + mk, tipo: 'ambos', lado: 'noiva', membros: ['A', 'B'] }, 1);
  await api('versao_criar', { nome: 'V ' + mk, ambito: 'digital' }, 1);
  await api('orc_categoria_guardar', { nome: 'Flores', previsto: '1000' }, 1);

  // ---------- casal: exportar só uma parte ----------
  const soConv = await baixar('&ambito=casamento&partes=convidados');
  const r0 = soConv.casamentos[0];
  ok(r0.convites && r0.convites.length === 1, 'exportar partes=convidados traz os convites');
  ok(!r0.mesas && !r0.versoes && !r0.orcamento, 'e NÃO traz mesas/versões/orçamento');
  ok(JSON.stringify(soConv.partes) === '["convidados"]', 'o ficheiro declara as partes que leva');

  // ---------- casal: repor de fábrica só as mesas ----------
  const rf = await api('casamento_repor_fabrica', { partes: ['mesas'] }, 1);
  ok(rf.success && rf.feito.mesas === 1, 'repor de fábrica «mesas» apaga a mesa');
  ok((await api('mesa_list')).mesas.length === 0, 'a planta fica vazia');
  ok((await api('convite_list')).convites.length === 1, 'e os convites ficam intactos');

  // ---------- casal: importar só uma parte substitui só essa ----------
  await api('convite_save', { nome_exibicao: 'Extra ' + mk, tipo: 'digital', lado: 'ambos', membros: ['X'] }, 1);
  const imp = await api('dados_importar', { modo: 'substituir', partes: ['convidados'], ficheiro: soConv }, 1);
  ok(imp.success, 'importar partes=convidados corre');
  const nomes = (await api('convite_list')).convites.map(c => c.nome_exibicao);
  ok(nomes.length === 1 && nomes[0].includes('Fam'), 'importar substitui SÓ a lista de convidados');
  ok((await api('versao_lista&ambito=digital')).versoes.filter(v => !v.padrao).length === 1,
     'e não toca nas versões, que continuam');

  // ---------- admin: exportar por âmbitos ----------
  const sis = await baixar('&ambito=sistema&inc=modelos,contas');
  ok(Array.isArray(sis.modelos) && sis.modelos.length > 0, 'admin: inc=modelos traz os modelos');
  ok(Array.isArray(sis.contas), 'e as contas');
  ok(!sis.casamentos.length, 'e NÃO traz casamentos quando não se pedem');

  // ---------- admin: repor um casamento de fábrica ----------
  const rfa = await api('sistema_repor_fabrica', { alvos: ['casamentos'], casamentos: [w.id] }, 1);
  ok(rfa.success && rfa.res.casamentos === 1, 'admin repõe 1 casamento de fábrica');
  await api('casamento_abrir&id=' + w.id, {});
  ok((await api('convite_list')).convites.length === 0
     && (await api('versao_lista&ambito=digital')).versoes.filter(v => !v.padrao).length === 0,
     'e o casamento fica vazio (convites e versões)');

  // ---------- os painéis existem no ecrã ----------
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  ok(await p.locator('#vista-chips .chip[data-vista="dados"]').count() === 1,
     'a pastilha «Dados e reposição» está na barra do admin');
  await p.evaluate(() => verVista('dados')); await p.waitForTimeout(400);
  ok(await p.locator('#dados-inc input[value="casamentos"]').count() === 1
     && await p.locator('#dados-inc input[value="modelos"]').count() === 1
     && await p.locator('#dados-inc input[value="contas"]').count() === 1,
     'com as caixas de âmbito (casamentos, modelos, contas)');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1', {});
  await api('casamento_estado&id=' + w.id + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + w.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
