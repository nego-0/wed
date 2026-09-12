// A prova da sexta passagem do bar (docs/bar-motor-assistido.md).
//
// FASE 1 — o esquema v40. Que o sítio onde o motor vai escrever existe, está
// com âmbito, viaja no retrato, e — a parte que mais importa — que abrir o
// esquema **não mexeu em nada do que já lá estava**. Uma migração que muda o
// comportamento de uma regra já escrita é a pior espécie de migração: ninguém
// a vê acontecer, e o bar passa a fazer outra coisa a meio de uma festa.
//
// FASE 2 — o motor mede e propõe. A fronteira toda desta passagem cabe em duas
// linhas, e são as primeiras que aqui se defendem:
//
//     uma regra em `sugere` NÃO trava o pedido, e LEVANTA o alerta;
//     a mesma regra em `trava` recusa, e não levanta alerta nenhum.
//
// Se isto cair, ou o módulo ganhou um modo que não faz nada — e uma regra
// desligada que continua escrita no painel é a pior coisa que este módulo pode
// ter —, ou ganhou um modo que trava à mesma, e aí mentiu a quem o escolheu.
//
// FASE 3 — o painel: aplicar, adaptar, ignorar. O que se defende é que os três
// botões FAZEM o que dizem — e que ignorar fica escrito, porque é uma decisão
// como as outras.
//
// FASE 4 — a voz da festa. As frases que o convidado lê passam a ser do casal,
// e não da aplicação. Em branco, valem as de fábrica: ninguém tem de preencher
// nada para o bar funcionar.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const casa = await b.newContext({ viewport: { width: 1280, height: 1000 } });
  const p = await casa.newPage();
  p.on('pageerror', e => errs.push('noivos: ' + e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push('noivos: ' + m.text()); });

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
  await p.waitForTimeout(800);

  // O retrato do bar, lido como o ficheiro que se descarrega — é ele que diz
  // o que viaja e o que fica.
  const retratoDoBar = () => p.evaluate(async () => {
    const r = await fetch('api.php?action=dados_exportar&ambito=casamento&partes=bar',
                          { headers: { 'X-CSRF-Token': window.CSRF } });
    const d = await r.json();
    return { esquema: d.esquema, bar: ((d.casamentos || [])[0] || {}).bar || {} };
  });

  // ============ 1. a versão do esquema ============
  const primeiro = await retratoDoBar();
  ok(primeiro.esquema === 40,
     'o esquema anuncia-se na versão 40 (lido: ' + primeiro.esquema + ')');

  // ============ 2. o modo de cada regra ============
  // Uma regra escrita sem dizer o modo nasce a TRAVAR — que é o que todas as
  // regras deste módulo faziam antes de haver modos. É esta linha que garante
  // que abrir o v40 não mudou o comportamento de nada.
  const posta = await p.evaluate(async () =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas', quantidade: 2,
        janela_min: 30, nota: 'ZS sem modo' }) }));
  const r = (posta.regras || []).filter(x => x.nota === 'ZS sem modo')[0];
  ok(!!r, 'escreve-se uma regra sem dizer o modo');
  const bar = (await retratoDoBar()).bar;
  const escrita = (bar.limites || []).filter(x => x.nota === 'ZS sem modo')[0];
  ok(escrita && escrita.modo === 'trava',
     'e nasce a TRAVAR, como todas as regras faziam antes do v40: «'
     + (escrita ? escrita.modo : '—') + '»');

  // ============ 3. o retrato leva o que é montagem, e deixa a noite ============
  ok(Array.isArray(bar.mensagens),
     'o retrato do bar traz as mensagens — é escrita do casal, e viaja com o menu');
  ok(bar.alertas === undefined,
     'e NÃO traz os alertas: são propostas sobre um momento, e um momento não '
     + 'se importa de outra base');
  ok((bar.itens || []).every(i => i.base_noite === undefined),
     'nem a base da noite de cada bebida — uma percentagem sobre uma noite que '
     + 'não começou é um número inventado');

  // ============ 4. as definições novas ============
  const defs = await p.evaluate(async () => (await window.api('bar_estado')).defs || {});
  ok(defs['bar.degraus_stock'] === '50,30,15,5',
     'os degraus da percentagem de stock vêm de fábrica em 50,30,15,5: '
     + defs['bar.degraus_stock']);
  ok(defs['bar.pausada_ate'] === '', 'a copa não nasce em pausa');
  ok(defs['bar.pausa_min'] === '10', 'e a pausa que se propõe é de 10 minutos');

  // Os degraus guardam-se sempre do maior para o menor. Escritos ao contrário,
  // o alerta de 15% nascia antes do de 30% e a copa via a bebida a ficar
  // «crítica» com metade do stock ainda na mão.
  const arrumados = await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.degraus_stock': '5, 40,15,40 ,90' }) });
    return (await window.api('bar_estado')).defs['bar.degraus_stock'];
  });
  ok(arrumados === '90,40,15,5',
     'e arrumam-se sozinhos, do maior para o menor e sem repetidos: ' + arrumados);

  const lixo = await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.pausada_ate': 'logo à noite' }) });
    return (await window.api('bar_estado')).defs['bar.pausada_ate'];
  });
  ok(lixo === '', 'a hora de fim da pausa só se guarda se for uma hora');

  // A vigia de âmbito das duas tabelas novas não se prova daqui: ela só
  // ACRESCENTA uma verificação, e por isso a sua falta não dá erro nenhum —
  // dá, um dia, uma consulta sem âmbito que ninguém apanhou. Fica pinada em
  // `versao.php`, que é a ferramenta desta casa para «esta linha tem de estar
  // neste ficheiro», e é `chk_versao.js` que a cobra.

  // ==================================================================
  // FASE 2 — o motor mede e propõe
  // ==================================================================

  // O mundo desta parte: gente só nossa e uma bebida só nossa, para os tectos
  // «ao todo» não se medirem contra o que as outras provas já beberam.
  const cen = await p.evaluate(async () => {
    // Os degraus voltam aos de fábrica: a secção 4 mexeu-lhes para provar que
    // se arrumam, e daqui para baixo mede-se contra 50,30,15,5.
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.degraus_stock': '50,30,15,5' }) });
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const x of (e.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZS /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const cv = await window.api('convite_list&busca=ZS%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZS Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    await window.api('convite_save', { method: 'POST', body: JSON.stringify({
      nome_exibicao: 'ZS Prova', tipo: 'digital', lado: 'noivo',
      membros: [{ nome: 'ZS Bebedor' }] }) });
    const d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZS Gin', categoria_id: e.categorias[0].id, stock: 20,
        visivel: 1, max_por_pedido: 4 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const n = await window.api('bar_procurar_pessoal&limite=500', { method: 'GET' });
    return { item: d.id, token: window.BAR_MESAS[0].token,
             quem: (n.nomes || []).filter(x => x.nome === 'ZS Bebedor')[0] };
  });
  ok(!!cen.item && !!cen.quem, 'há uma bebida e uma pessoa só desta parte da prova');

  const pedir = (q) => p.evaluate(async ([t, g, it, n]) => {
    await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: g }) });
    return await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1, itens: [{ item_id: it, quantidade: n }] }) })).json();
  }, [cen.token, cen.quem.id, cen.item, q]);

  const alertasDe = (regraId) => p.evaluate(async (rid) => {
    const e = await window.api('bar_estado');
    return (e.alertas || []).filter(a => a.regra_id === rid && a.estado === 'aberto');
  }, regraId);

  // ============ 6. a fronteira: travar contra sugerir ============
  // Uma regra apertada: uma bebida por pessoa, ao todo.
  const porRegra = (modo) => p.evaluate(async ([m, id]) => {
    const d = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: id || 0, escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 1, janela_min: 0, modo: m, nota: 'ZS tecto' }) });
    return (d.regras || []).filter(r => r.nota === 'ZS tecto')[0];
  }, [modo, 0]);

  const rTrava = await porRegra('trava');
  ok(rTrava && rTrava.modo === 'trava', 'põe-se a regra em modo «trava»');
  const um = await pedir(1);
  ok(um && um.success === true, 'a primeira bebida passa — ainda cabe no tecto');
  const dois = await pedir(1);
  ok(dois && dois.success === false,
     'a segunda esbarra: em «trava», a regra RECUSA — «' + (dois.message || '') + '»');
  const semAlerta = await alertasDe(rTrava.id);
  ok(semAlerta.length === 0,
     'e não levanta alerta nenhum: quem trava não tem nada a propor ('
     + semAlerta.length + ')');

  // A MESMA regra, o MESMO tecto já ultrapassado, só o modo muda.
  const rSugere = await p.evaluate(async ([id]) => {
    const d = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: id, escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 1, janela_min: 0, modo: 'sugere', nota: 'ZS tecto' }) });
    return (d.regras || []).filter(r => r.nota === 'ZS tecto')[0];
  }, [rTrava.id]);
  ok(rSugere && rSugere.modo === 'sugere', 'passa-se a mesma regra a «sugere»');
  const passou = await pedir(1);
  ok(passou && passou.success === true,
     'e agora o pedido PASSA — em «sugere» a regra não recusa nada a ninguém');
  const comAlerta = await alertasDe(rSugere.id);
  ok(comAlerta.length >= 1,
     'mas o motor tocou a campainha: ' + comAlerta.length + ' alerta(s) por decidir');
  const a = comAlerta[0] || {};
  ok(a.tipo === 'regra_pessoa' && (a.situacao || {}).nome === 'ZS Bebedor',
     'e o alerta diz de QUEM se trata, e não «alguém»: «'
     + ((a.situacao || {}).nome || '—') + '»');
  ok((a.sugestao || {}).accao === 'travar_convidado',
     'com uma acção proposta, e não só um lamento: ' + ((a.sugestao || {}).accao || '—'));

  // ============ 7. um degrau, um alerta ============
  const degrau = await p.evaluate(async (it) => {
    // 5 de 20 são 25%: cruza o degrau dos 30 e não o dos 15.
    await window.api('bar_stock_acerto', { method: 'POST', body: JSON.stringify(
      { item_id: it, stock: 5, nota: 'ZS a descer' }) });
    const e1 = await window.api('bar_estado');
    const i = (e1.itens || []).filter(x => x.id === it)[0];
    const um = (e1.alertas || []).filter(x => x.tipo === 'stock_degrau'
                                         && (x.situacao || {}).item_id === it);
    // Segunda leitura, oito segundos depois: não pode nascer outro igual.
    const e2 = await window.api('bar_estado');
    const dois = (e2.alertas || []).filter(x => x.tipo === 'stock_degrau'
                                           && (x.situacao || {}).item_id === it);
    return { pc: i.percentagem, base: i.base_noite, um: um.length, dois: dois.length,
             degrau: (um[0] || {}).situacao ? um[0].situacao.degrau : null,
             nivel: (um[0] || {}).nivel };
  }, cen.item);
  ok(degrau.base === 20 && degrau.pc === 25,
     'a base da noite fixou-se em 20, e 5 disso são 25%: ' + degrau.pc + '%');
  ok(degrau.um === 1 && degrau.degrau === 30,
     'cruzar os 25% levanta UM alerta, o do degrau mais apertado (30) e não três');
  ok(degrau.dois === 1,
     'e a leitura seguinte não levanta outro igual — senão o painel enchia-se '
     + 'de cópias de oito em oito segundos');

  // A bebida volta a subir: a chave liberta-se, e o alerta caduca.
  const subiu = await p.evaluate(async (it) => {
    await window.api('bar_stock_repor', { method: 'POST', body: JSON.stringify(
      { item_id: it, quantidade: 30, nota: 'ZS chegaram caixas' }) });
    const e = await window.api('bar_estado');
    const i = (e.itens || []).filter(x => x.id === it)[0];
    return { pc: i.percentagem, base: i.base_noite,
             abertos: (e.alertas || []).filter(x => x.tipo === 'stock_degrau'
                        && (x.situacao || {}).item_id === it && x.estado === 'aberto').length };
  }, cen.item);
  ok(subiu.base === 35 && subiu.pc === 100,
     'chegaram mais caixas e a base sobe com elas — sem isto a percentagem '
     + 'passava dos 100%: base ' + subiu.base + ', ' + subiu.pc + '%');
  ok(subiu.abertos === 0,
     'e o alerta do degrau caduca sozinho: a condição passou, a chave liberta-se');

  // ==================================================================
  // FASE 3 — o painel, e o que os três botões fazem
  // ==================================================================

  /**
   * Leva a bebida a uma percentagem exacta e devolve o alerta que nasceu daí.
   *
   * A percentagem tem de ser escolhida, e não deixada ao acaso: o degrau que
   * se cruza decide a acção proposta (aos 25% propõe-se cortar o máximo por
   * pedido; no último degrau propõe-se suspender a bebida), e uma prova que
   * não sabe qual dos dois vai receber não está a provar nada.
   */
  const descerPara = (pc) => p.evaluate(async ([it, alvo]) => {
    const e0 = await window.api('bar_estado');
    const base = (e0.itens || []).filter(x => x.id === it)[0].base_noite;
    await window.api('bar_stock_acerto', { method: 'POST', body: JSON.stringify(
      { item_id: it, stock: Math.round(base * alvo / 100), nota: 'ZS a descer' }) });
    const e = await window.api('bar_estado');
    return (e.alertas || []).filter(x => x.tipo === 'stock_degrau'
             && (x.situacao || {}).item_id === it && x.estado === 'aberto')[0];
  }, [cen.item, pc]);

  /** Repõe a bebida no cheio: é o que liberta a chave para o alerta seguinte. */
  const encher = () => p.evaluate(async (it) => {
    const e0 = await window.api('bar_estado');
    const i = (e0.itens || []).filter(x => x.id === it)[0];
    await window.api('bar_stock_acerto', { method: 'POST', body: JSON.stringify(
      { item_id: it, stock: i.base_noite, nota: 'ZS a encher' }) });
    await window.api('bar_estado');
  }, cen.item);

  // ============ 8. aplicar faz mesmo o que promete ============
  const al = await descerPara(25);
  ok(!!al && (al.sugestao || {}).accao === 'baixar_max_por_pedido',
     'a bebida volta a descer e o motor propõe baixar o máximo por pedido');
  const aplicado = await p.evaluate(async ([id, it]) => {
    const antes = ((await window.api('bar_estado')).itens || [])
      .filter(x => x.id === it)[0].max_por_pedido;
    const d = await window.api('bar_alerta_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aplicar' }) });
    const i = (d.itens || []).filter(x => x.id === it)[0];
    const a = (d.alertas || []).filter(x => x.id === id)[0];
    return { antes: antes, depois: i.max_por_pedido, estado: a.estado, por: a.decidido_por };
  }, [al.id, cen.item]);
  ok(aplicado.depois < aplicado.antes,
     'aplicar MUDA mesmo a bebida — ' + aplicado.antes + ' → ' + aplicado.depois
     + ' por pedido; um botão que não faz nada é pior do que não haver botão');
  ok(aplicado.estado === 'aplicado' && aplicado.por,
     'e o alerta fecha-se com o nome de quem o decidiu: ' + aplicado.por);

  // ============ 9. adaptar é aplicar com outro número ============
  // O alerta anterior foi respondido e a chave está gasta; enche-se a bebida
  // para a libertar, e volta-se a descer ao mesmo degrau.
  await encher();
  const al2 = await descerPara(25);
  ok(!!al2, 'levanta-se outro alerta para o adaptar');
  const adaptado = await p.evaluate(async ([id, it]) => {
    const d = await window.api('bar_alerta_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'adaptar', para: 1 }) });
    const i = (d.itens || []).filter(x => x.id === it)[0];
    const a = (d.alertas || []).filter(x => x.id === id)[0];
    return { max: i.max_por_pedido, estado: a.estado, para: (a.sugestao || {}).para };
  }, [al2.id, cen.item]);
  ok(adaptado.max === 1 && adaptado.para === 1,
     'adaptar aplica com o número que a copa escreveu, e não com o proposto: '
     + adaptado.max + ' por pedido');
  ok(adaptado.estado === 'adaptado',
     'e fica marcado como adaptado, e não como aplicado — a diferença conta-se '
     + 'no dia seguinte');

  // ============ 10. ignorar fecha e fica escrito ============
  await encher();
  const al3 = await descerPara(25);
  const ignorado = await p.evaluate(async ([id, it]) => {
    const antes = ((await window.api('bar_estado')).itens || [])
      .filter(x => x.id === it)[0].max_por_pedido;
    const d = await window.api('bar_alerta_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'ignorar', nota: 'ZS já mandei buscar mais' }) });
    const i = (d.itens || []).filter(x => x.id === it)[0];
    const a = (d.alertas || []).filter(x => x.id === id)[0];
    return { mexeu: i.max_por_pedido !== antes, estado: a.estado, nota: a.nota, por: a.decidido_por };
  }, [al3.id, cen.item]);
  ok(!ignorado.mexeu, 'ignorar não mexe em nada — é isso que ignorar quer dizer');
  ok(ignorado.estado === 'ignorado' && ignorado.por && /já mandei buscar/.test(ignorado.nota || ''),
     'mas fica escrito quem ignorou e porquê: é uma decisão como as outras, e '
     + 'no dia seguinte tem de se poder ver');

  // E não se decide duas vezes o mesmo alerta.
  const outraVez = await p.evaluate(async (id) =>
    await window.api('bar_alerta_decidir', { method: 'POST', silencioso: true,
      body: JSON.stringify({ id: id, decisao: 'aplicar' }) }), al3.id);
  ok(outraVez && outraVez.success === false,
     'e um alerta já decidido não se decide outra vez: «' + (outraVez.message || '') + '»');

  // ============ 11. a pausa da copa vale mesmo ============
  // `pausar_copa` é uma das acções que o motor propõe. Sem a pausa a valer, o
  // botão «Aplicar» não fazia nada — e um botão que finge é pior do que um
  // botão que não existe.
  // A pausa põe-se pela PORTA POR ONDE O PAINEL A PÕE — aplicando o alerta —, e
  // não escrevendo a hora à mão. Escrita à mão, escrevia-se pelo relógio do
  // browser, que aqui corre em UTC enquanto a casa corre em Africa/Luanda: a
  // pausa nascia expirada e a prova passava sem provar nada.
  const semPausa = await p.evaluate(async () =>
    (await window.api('bar_estado')).estado.pausa_s);
  ok(semPausa === 0, 'a copa não está em pausa antes de alguém a pôr');

  // Uma regra da CASA que não trava: é ela que levanta o alerta do caudal, e é
  // esse que propõe pausar. Contada em pedidos, e esta noite já houve vários.
  const caudal = await p.evaluate(async () => {
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'casa', unidade: 'pedidos', quantidade: 1,
        janela_min: 60, modo: 'sugere', nota: 'ZS caudal' }) });
    const e = await window.api('bar_estado');
    return (e.alertas || []).filter(x => x.tipo === 'caudal' && x.estado === 'aberto')[0];
  });
  ok(!!caudal && (caudal.sugestao || {}).accao === 'pausar_copa',
     'a copa passa o caudal e o motor propõe pô-la em pausa');

  const comPausa = await p.evaluate(async ([id, t, it]) => {
    const d = await window.api('bar_alerta_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aplicar' }) });
    const pedido = await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1, itens: [{ item_id: it, quantidade: 1 }] }) })).json();
    return { pausa_s: (d.estado || {}).pausa_s, ok: pedido.success, msg: pedido.message || '' };
  }, [caudal.id, cen.token, cen.item]);
  ok(comPausa.pausa_s > 0,
     'aplicá-lo põe mesmo a copa em pausa, contada pelo relógio da casa ('
     + comPausa.pausa_s + 's)');
  ok(comPausa.ok === false && /recuperar do movimento/i.test(comPausa.msg),
     'e o convidado esbarra nela, com o tempo que falta: «' + comPausa.msg + '»');

  // ============ 12. a aba dos alertas está na copa, e à frente ============
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  const abas = await p.locator('#b-fer-fila .b-pilula').allInnerTexts();
  ok(/Alertas/.test(abas[0] || ''),
     'a copa abre com «Alertas» como primeira pastilha: ' + abas.join(' · '));
  await p.locator('#b-fer-fila .b-pilula:has-text("Alertas")').first().click();
  await p.waitForTimeout(900);
  const painel = await p.evaluate(() => {
    const c = document.querySelector('.b-alerta');
    return { quantos: document.querySelectorAll('.b-alerta').length,
             texto: c ? c.innerText.replace(/\s+/g, ' ') : '' };
  });
  ok(painel.quantos >= 1, 'e o painel desenha os alertas (' + painel.quantos + ')');
  ok(!/\{|\}|"accao"/.test(painel.texto),
     'em português, e não em JSON: «' + painel.texto.slice(0, 90) + '»');

  // ==================================================================
  // FASE 4 — a voz da festa
  // ==================================================================
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  // A secção 11 deixou a copa em pausa, e a pausa trava ANTES do veredicto —
  // é para isso que ela existe. Levanta-se, senão o que aqui se lia era sempre
  // a frase da pausa e nunca a da situação que se está a provar.
  await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.pausada_ate': '' }) });
  });

  // ============ 13. as dez situações, e o que cada uma diz hoje ============
  const voz = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    return { sits: Object.keys(e.situacoes || {}), fab: e.fabrica || {},
             vars: Object.keys(e.variaveis || {}), msgs: e.mensagens || {} };
  });
  ok(voz.sits.length === 10,
     'há dez situações que o casal pode reescrever: ' + voz.sits.length);
  ok(voz.sits.every(s => (voz.fab[s] || '') !== ''),
     'e todas trazem o texto de fábrica, para se ver o que se está a substituir');
  ok(/\{BEBIDA\}/.test(voz.fab.stock || ''),
     'os de fábrica são escritos com as MESMAS variáveis — não há duas '
     + 'gramáticas: «' + (voz.fab.stock || '') + '»');

  // ============ 14. uma frase do casal substitui a de fábrica ============
  // O tecto de uma bebida, para haver uma recusa a ler. A regra fica sem
  // mensagem própria: é a da SITUAÇÃO que se está a provar.
  const paraRecusar = await p.evaluate(async ([it]) => {
    const e = await window.api('bar_estado');
    for (const x of (e.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, modo: 'trava', nota: 'ZS fechada' }) });
    return true;
  }, [cen.item]);
  ok(paraRecusar, 'fecha-se a bebida para haver uma recusa que se possa ler');

  const deFabrica = await pedir(1);
  ok(deFabrica.success === false && /não está disponível para si esta noite/.test(deFabrica.message),
     'sem frase do casal, o convidado lê a de fábrica: «' + deFabrica.message + '»');

  const comVoz = await p.evaluate(async () => {
    await window.api('bar_mensagens_guardar', { method: 'POST', body: JSON.stringify(
      { proibido: 'Hoje a {BEBIDA} não dá, {NOME} — mas há muito mais. Beijinhos!' }) });
    return (await window.api('bar_estado')).mensagens.proibido;
  });
  ok(/Hoje a \{BEBIDA\}/.test(comVoz || ''),
     'o casal escreve a sua, com variáveis lá dentro');

  const lida = await pedir(1);
  ok(lida.success === false && /Hoje a ZS Gin não dá, ZS Bebedor/.test(lida.message),
     'e é ELA que o convidado lê, com as variáveis já trocadas: «'
     + lida.message + '»');
  ok(!/\{|\}/.test(lida.message),
     'sem chavetas nenhumas à vista — nem as que a frase não usa');

  // ============ 15. a da regra manda sobre a da situação ============
  const daRegra = await p.evaluate(async ([it]) => {
    const e = await window.api('bar_estado');
    const r = (e.regras || []).filter(x => x.nota === 'ZS fechada')[0];
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: r.id, escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, modo: 'trava', nota: 'ZS fechada',
        mensagem: 'Esta é a da regra, e ganha.' }) });
    return true;
  }, [cen.item]);
  const venceu = await pedir(1);
  ok(daRegra && venceu.message === 'Esta é a da regra, e ganha.',
     'a mensagem da REGRA continua a mandar sobre a da situação — é a mais '
     + 'específica, e o mais específico ganha em todo o módulo: «'
     + venceu.message + '»');

  // ============ 16. apagar a frase devolve a de fábrica ============
  const voltou = await p.evaluate(async ([it]) => {
    const e = await window.api('bar_estado');
    const r = (e.regras || []).filter(x => x.nota === 'ZS fechada')[0];
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: r.id, escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, modo: 'trava', nota: 'ZS fechada', mensagem: '' }) });
    await window.api('bar_mensagens_guardar', { method: 'POST',
      body: JSON.stringify({ proibido: '' }) });
    return (await window.api('bar_estado')).mensagens.proibido;
  }, [cen.item]);
  ok(voltou === undefined, 'apagar a frase apaga mesmo a linha, e não guarda um vazio');
  const outraVezFabrica = await pedir(1);
  ok(/não está disponível para si esta noite/.test(outraVezFabrica.message),
     'e o convidado volta a ler a de fábrica — em branco não é «não digas nada»');

  // ============ 17. o editor está lá, com o de fábrica à vista ============
  await p.locator('#ab-regras').click();
  await p.waitForTimeout(900);
  const editor = await p.evaluate(() => {
    const c = document.querySelectorAll('.b-msg');
    const um = c[0];
    return { quantas: c.length,
             temCaixa: !!(um && um.querySelector('textarea')),
             temFabrica: !!(um && um.querySelector('.fab')
                            && um.querySelector('.fab').textContent.trim()),
             temVars: !!document.querySelector('.b-vars code') };
  });
  ok(editor.quantas === 10, 'o editor traz as dez caixas (' + editor.quantas + ')');
  ok(editor.temCaixa && editor.temFabrica,
     'cada uma com a sua caixa e o texto de fábrica por baixo');
  ok(editor.temVars, 'e a lista das variáveis que se podem usar');

  // ============ arrumar ============
  // De volta a bar.php: apagar convites é dos noivos, e é lá que essa porta
  // está aberta.
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const x of (e.regras || []).filter(x => /^ZS /.test(x.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZS /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const cv = await window.api('convite_list&busca=ZS%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZS Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.degraus_stock': '50,30,15,5', 'bar.pausada_ate': '' }) });
    // As frases voltam ao de fábrica: as outras provas contam com os textos
    // que o módulo traz, e uma frase deixada aqui mudava-lhes o chão.
    const limpar = {};
    for (const k of Object.keys((await window.api('bar_estado')).situacoes || {})) limpar[k] = '';
    await window.api('bar_mensagens_guardar', { method: 'POST', body: JSON.stringify(limpar) });
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
