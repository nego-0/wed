// Levar, trazer e repor os dados — por partes.
//
// O casal escolhe o que leva, o que traz e o que repõe de fábrica (lista de
// convidados, mesas, versões, orçamento). O admin faz o mesmo à escala da casa
// (casamentos, modelos, contas). O que se prova é que cada operação mexe SÓ no
// que se assinala — e deixa o resto quieto.
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const ROOT = path.resolve(__dirname, '..') + '/';
const existe = fp => { try { return fs.existsSync(ROOT + fp); } catch (e) { return false; } };

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
  // O cabeçalho leva um resumo do que se exportou — e vem por cima, antes dos dados.
  ok(soConv.resumo && soConv.resumo.convites === 1 && soConv.resumo.casamentos === 1,
     'o cabeçalho traz o resumo do que foi exportado (casamentos, convites)');
  ok(Object.keys(soConv).indexOf('resumo') < Object.keys(soConv).indexOf('casamentos'),
     'o resumo fica no cabeçalho, antes dos casamentos');

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

  // ---------- admin: exportar por âmbitos (contas separadas por família) ----------
  const sis = await baixar('&ambito=sistema&inc=modelos,contas_admin');
  ok(Array.isArray(sis.modelos) && sis.modelos.length > 0, 'admin: inc=modelos traz os modelos');
  ok(Array.isArray(sis.contas) && sis.contas.every(c => c.papel_plataforma),
     'inc=contas_admin traz SÓ as contas administrativas');
  ok(!sis.casamentos.length, 'e NÃO traz casamentos quando não se pedem');
  ok(sis.resumo && sis.resumo.modelos === sis.modelos.length
     && sis.resumo.contas_administrativas === sis.contas.length,
     'o resumo do admin conta os modelos e separa as contas por família');
  const sisCC = await baixar('&ambito=sistema&inc=contas_casamento');
  ok((sisCC.contas || []).every(c => !c.papel_plataforma),
     'inc=contas_casamento traz SÓ as contas de casamento (noivos/porteiro)');

  // ---------- admin: esvaziar um casamento (fica o casamento, sem os dados) ----------
  const rfa = await api('sistema_repor_fabrica',
    { alvos: ['casamentos'], casamentos: [w.id], casamentos_modo: 'esvaziar' }, 1);
  ok(rfa.success && rfa.res.casamentos === 1, 'admin esvazia 1 casamento');
  const aindaLa = await api('casamento_abrir&id=' + w.id, {});
  ok(aindaLa.success, 'e o casamento continua a existir (só esvaziado)');
  ok((await api('convite_list')).convites.length === 0
     && (await api('versao_lista&ambito=digital')).versoes.filter(v => !v.padrao).length === 0,
     'e o casamento fica vazio (convites e versões)');

  // ---------- admin: apagar um casamento por inteiro (leva as contas dele) ----------
  const wd = await api('casamento_criar', { nome: 'ZZ Del ' + mk, noiva: 'D', noivo: 'E' }, 1);
  await api('casamento_abrir&id=' + wd.id, {});
  await api('convite_save', { nome_exibicao: 'Del ' + mk, tipo: 'ambos', lado: 'noiva', membros: ['Z'] }, 1);
  const emailDel = 'del.porta.' + mk + '@ex.pt';
  await api('acesso_convidar', { email: emailDel, papel: 'porteiro' }, 1);   // conta que só existe por causa deste casamento
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailDel))).contas || []).length === 1, 'a conta de porteiro existe antes de apagar');
  const rda = await api('sistema_repor_fabrica',
    { alvos: ['casamentos'], casamentos: [wd.id], casamentos_modo: 'apagar' }, 1);
  ok(rda.success && rda.res.casamentos_apagados === 1, 'admin apaga 1 casamento por inteiro');
  ok(rda.res.contas_casamento >= 1, 'e a operação diz que levou as contas do casamento');
  await api('casamento_abrir&id=1', {});
  ok(!(await api('casamento_lista&estado=todos')).casamentos.some(c => +c.id === +wd.id),
     'e o casamento já não consta da lista');
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailDel))).contas || []).length === 0,
     'a conta de porteiro foi apagada com o casamento');

  // ---------- casal: repor de fábrica das versões apaga a foto anexada ----------
  const w2 = await api('casamento_criar', { nome: 'ZZ SelF ' + mk, noiva: 'F', noivo: 'G' }, 1);
  await api('casamento_abrir&id=' + w2.id, {});
  const P1 = await p.evaluate(async () => {
    const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
    const bin = atob(b64); const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const fd = new FormData();
    fd.append('chave', 'media.hero'); fd.append('ficheiro', new Blob([arr], { type: 'image/png' }), 'f.png');
    const r = await fetch('api.php?action=def_upload', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF }, body: fd });
    return (await r.json()).path;
  });
  ok(existe(P1), 'a foto do casal existe no disco');
  await api('casamento_repor_fabrica', { partes: ['digital'] }, 1);
  ok(!existe(P1), 'repor de fábrica «digital» apaga a foto que o casal pôs');

  // ---------- admin: repor «modelos» apaga os personalizados ----------
  const mod = await api('modelo_criar', { nome: 'ZZ ModSel ' + mk, descricao: 'x', ambito: 'digital', visivel: true }, 1);
  ok((await api('modelo_lista&ambito=digital')).modelos.some(m => +m.id === +mod.id), 'modelo personalizado criado');
  const rm = await api('sistema_repor_fabrica', { alvos: ['modelos'] }, 1);
  ok(rm.success && rm.res.modelos >= 1, 'repor de fábrica «modelos» APAGA os personalizados');
  ok(!(await api('modelo_lista&ambito=digital')).modelos.some(m => +m.id === +mod.id), 'o modelo personalizado desapareceu');
  ok((await api('modelo_lista&ambito=digital')).modelos.length >= 1, 'e os modelos de origem ficam');

  // ---------- admin: apagar contas de casamento vs administrativas ----------
  const emailN = 'sel.porta.' + mk + '@ex.pt';
  const emailA = 'sel.sup.' + mk + '@ex.pt';
  await api('casamento_abrir&id=' + w2.id, {});
  await api('acesso_convidar', { email: emailN, papel: 'porteiro' }, 1);       // conta de casamento
  await api('utilizador_criar', { email: emailA, nome: 'Sup', senha: 'segredo12345', papel_plataforma: 'suporte' }, 1); // administrativa
  // Apagar só as de casamento: o suporte fica.
  const rc = await api('sistema_repor_fabrica', { alvos: ['contas_casamento'] }, 1);
  ok(rc.success && rc.res.contas_casamento >= 1, 'repor «contas de casamento» apaga noivos/porteiro');
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailN))).contas || []).length === 0, 'a conta de casamento desapareceu');
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailA))).contas || []).length === 1, 'a conta administrativa ficou');
  // Apagar as administrativas: o suporte sai, o admin (próprio) fica.
  const ra = await api('sistema_repor_fabrica', { alvos: ['contas_admin'] }, 1);
  ok(ra.success && ra.res.contas_admin >= 1, 'repor «contas administrativas» apaga admin/suporte');
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailA))).contas || []).length === 0, 'o suporte desapareceu');
  ok(((await api('utilizador_lista&q=admin')).contas || []).some(c => c.papel_plataforma === 'admin'),
     'e a própria conta (admin) fica — não se tranca fora');

  // ---------- apagar um casamento (a via normal) leva também as suas contas ----------
  const wc = await api('casamento_criar', { nome: 'ZZ ApC ' + mk, noiva: 'A', noivo: 'B' }, 1);
  await api('casamento_abrir&id=' + wc.id, {});
  const emailC = 'apc.porta.' + mk + '@ex.pt';
  await api('acesso_convidar', { email: emailC, papel: 'porteiro' }, 1);
  await api('casamento_abrir&id=1', {});
  // A licença sai primeiro: com ela em vigor, a casa não se fecha.
  await api('lic_revogar', { casamento: wc.id, motivo: 'Fim da prova ' + mk }, 1);
  await api('casamento_estado&id=' + wc.id + '&estado=arquivado', {});
  const delc = await api('casamento_apagar&id=' + wc.id, {});
  ok(delc.success && delc.levou && delc.levou.contas >= 1, 'casamento_apagar diz quantas contas levou');
  ok(((await api('utilizador_lista&q=' + encodeURIComponent(emailC))).contas || []).length === 0,
     'e a conta de porteiro desapareceu com o casamento');

  // ---------- os painéis existem no ecrã ----------
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  ok(await p.locator('#vista-chips .chip[data-vista="dados"]').count() === 1,
     'a pastilha «Gestão de Dados» está na barra do admin');
  await p.evaluate(() => verVista('dados')); await p.waitForTimeout(400);
  ok(await p.locator('#dados-inc input[value="casamentos"]').count() === 1
     && await p.locator('#dados-inc input[value="modelos"]').count() === 1
     && await p.locator('#dados-inc input[value="contas_casamento"]').count() === 1
     && await p.locator('#dados-inc input[value="contas_admin"]').count() === 1,
     'com as caixas de âmbito (casamentos, modelos, contas de casamento, contas administrativas)');
  ok(await p.locator('input[name="cas-modo"][value="esvaziar"]').count() === 1
     && await p.locator('input[name="cas-modo"][value="apagar"]').count() === 1,
     'e com a escolha de esvaziar ou apagar os casamentos');
  ok(await p.locator('#vista-dados .btn.perigo').last().innerText().then(t => t.trim() === 'Apagar'),
     'o botão vermelho diz «Apagar» (já não «Repor de fábrica»)');

  // ---------- e, ao lado da escolha, a casa INTEIRA de uma vez ----------
  // A escolha por âmbitos serve para levar um bocado; mas quem quer a casa toda
  // não tem de a assinalar peça a peça (e arriscar deixar alguma para trás).
  ok(await p.evaluate(() => ['exportarSistemaTudo','importarSistemaTudo','apagarSistemaTudo']
       .every(n => typeof window[n] === 'function')),
     'o admin tem exportar/importar/apagar TUDO, além da escolha por âmbitos');
  ok(await p.locator('#dados-tudo-senhas').count() === 1,
     'e pode levar as senhas nessa exportação inteira');

  // Exportar tudo leva mesmo tudo: casamentos, modelos e contas, sem escolher
  // nada. (Das contas resta a do admin — a limpeza por famílias, acima, levou as
  // outras; o que importa é que as três famílias venham no mesmo ficheiro.)
  const tudo = await baixar('&ambito=sistema&inc=casamentos,modelos,contas_casamento,contas_admin');
  ok((tudo.casamentos || []).length >= 2 && (tudo.modelos || []).length >= 1
     && (tudo.contas || []).length >= 1,
     `a exportação inteira leva casamentos, modelos e contas `
     + `(${(tudo.casamentos||[]).length}/${(tudo.modelos||[]).length}/${(tudo.contas||[]).length})`);
  // E sem 'inc' nenhum não é o mesmo: o de sempre deixa os modelos de fora.
  const semInc = await baixar('&ambito=sistema');
  ok(semInc.modelos === undefined && (tudo.modelos || []).length >= 1,
     'e é mais do que a exportação de sempre, que não levava os modelos');

  // ---------- os noivos: os seus dados mexem-se por inteiro ----------
  // Sem escolha de partes — «tudo» apaga tudo o que é do casamento.
  await api('casamento_abrir&id=' + w.id, {});
  const cheio = await baixar('&ambito=casamento');
  ok(cheio.partes === undefined,
     'a exportação dos noivos é o retrato cheio — não declara partes');
  const rfTudo = await api('casamento_repor_fabrica', { tudo: true }, 1);
  ok(rfTudo.success && (rfTudo.partes || []).length === 5,
     `«tudo» apaga as cinco partes de uma vez (${(rfTudo.partes||[]).join(', ')})`);
  const vazio = (await baixar('&ambito=casamento')).casamentos[0] || {};
  ok((vazio.convites || []).length === 0 && (vazio.mesas || []).length === 0
     && ((vazio.orcamento || {}).categorias || []).length === 0,
     'e o casamento fica sem convites, sem mesas e sem orçamento');
  // E trazer de volta o retrato cheio, também sem escolher partes, repõe tudo.
  const voltou = await api('dados_importar', { modo: 'substituir', ficheiro: cheio }, 1);
  ok(voltou.success, 'trazer o retrato cheio de volta corre');
  const reposto = (await baixar('&ambito=casamento')).casamentos[0] || {};
  ok((reposto.convites || []).length === (cheio.casamentos[0].convites || []).length,
     'e os convites voltam todos');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1', {});
  for (const id of [w.id, w2.id]) {
    await api('lic_revogar', { casamento: id, motivo: 'Fim da prova ' + mk });
    await api('casamento_estado&id=' + id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + id, {});
  }
  // Apagar contas por família esvaziou o pool da base — inclusive o porteiro que
  // o casamento nº1 tinha de origem. Repõe-se esse estado (um porteiro no nº1, e
  // uma conta administrativa), para as provas seguintes encontrarem o que
  // esperam. A conta «admin», essa, nunca se apaga.
  await api('acesso_convidar', { email: 'base.porteiro.' + mk + '@exemplo.pt', papel: 'porteiro' }, 1);
  await api('utilizador_criar', { email: 'base.suporte.' + mk + '@exemplo.pt', nome: 'Base Suporte', senha: 'segredo12345', papel_plataforma: 'suporte' }, 1);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
