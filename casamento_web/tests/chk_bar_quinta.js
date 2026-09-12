// A prova da quinta passagem do bar (docs/modulo-bar.md §30).
//
// O que aqui se defende:
//   1. O garçom SUBMETE, não serve. O pedido dele nasce por decidir, e não
//      promete stock nenhum antes de a copa o aprovar.
//   2. As regras valem em TODAS as portas — a do balcão incluída. Era a porta
//      de serviço: escrevia-se uma regra, via-se escrita, e o bar servia à
//      mesma desde que o pedido entrasse por ali.
//   3. Uma bebida suspende-se por um bocado e VOLTA SOZINHA. O convidado lê
//      quanto falta, e não «não está disponível esta noite».
//   4. A copa chega às regras de uma bebida por onde vê o problema: pela
//      coluna do stock e pelo gráfico de «Os números».
//   5. A página do convidado não escreve o nome dele duas vezes.
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

  const casa = await b.newContext({ viewport: { width: 1280, height: 1000 } });
  const p = await casa.newPage();
  vigiar(p, 'noivos');
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

  // ---- o mundo desta prova ----
  // Gente só nossa, e uma bebida só nossa: um tecto «ao todo» conta a noite
  // inteira, e medido contra a primeira pessoa da lista media-se contra o que
  // as outras provas já lhe tinham dado a beber.
  const cen = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const r of (e.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZQ /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZQ Genebra', categoria_id: e.categorias[0].id, stock: 40,
        visivel: 1, max_por_pedido: 6 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const cv = await window.api('convite_list&busca=ZQ%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZQ Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    await window.api('convite_save', { method: 'POST', body: JSON.stringify({
      nome_exibicao: 'ZQ Prova', tipo: 'digital', lado: 'noivo',
      membros: [{ nome: 'ZQ Bebedor' }] }) });
    const n = await window.api('bar_procurar_pessoal&limite=500', { method: 'GET' });
    return { item: d.id, token: window.BAR_MESAS[0].token,
             quem: (n.nomes || []).filter(x => x.nome === 'ZQ Bebedor')[0] };
  });
  ok(!!cen.quem && !!cen.item, 'há uma bebida e uma pessoa só desta prova');

  // ---- a conta do stock, para se ver o que promete e o que não promete ----
  const stockDe = (id) => p.evaluate(async (it) => {
    const e = await window.api('bar_estado');
    const i = (e.itens || []).filter(x => x.id === it)[0] || {};
    return { stock: i.stock, reservado: i.reservado, disponivel: i.disponivel };
  }, id);

  // ============ 1. o garçom submete, não serve ============
  const ges = await casa.newPage();
  vigiar(ges, 'gestao');
  await ges.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await ges.waitForTimeout(900);
  // A conta de uma corrida anterior fica na base, e um email é de uma conta só.
  await ges.evaluate(async () => {
    const d = await window.api('acesso_lista', { method: 'GET', silencioso: true });
    for (const a of ((d && d.acessos) || [])) {
      if (a.email === 'zq.garcom@exemplo.pt') {
        await window.api('conta_apagar_do_casamento&utilizador=' + a.utilizador_id,
                         { method: 'POST', silencioso: true });
      }
    }
  });
  await ges.selectOption('#a-papel', 'entregador');
  await ges.fill('#a-email', 'zq.garcom@exemplo.pt');
  await ges.fill('#a-nome', 'ZQ Garçom');
  await ges.click('button:has-text("Convidar")');
  await ges.waitForTimeout(1600);
  const senha = await ges.locator('#senha-nova .cod').innerText().catch(() => '');
  ok(!!senha, 'cria-se uma conta de garçom para provar isto');

  const dele = await b.newContext({ viewport: { width: 390, height: 844 } });
  const gar = await dele.newPage();
  vigiar(gar, 'entregas (garçom)');
  await gar.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await gar.fill('input[name=utilizador]', 'zq.garcom@exemplo.pt');
  await gar.fill('input[name=senha]', senha);
  await gar.click('button[type=submit]');
  await gar.waitForLoadState('networkidle');
  await gar.waitForTimeout(1200);
  ok(/entregas\.php/.test(gar.url()), 'o garçom entra e aterra nas entregas');

  const antesG = await stockDe(cen.item);
  const doGarcom = await gar.evaluate(async ([g, it]) =>
    await window.api('bar_pedir_por', { method: 'POST', silencioso: true,
      body: JSON.stringify({ convidado_id: g, itens: [{ item_id: it, quantidade: 1 }] }) }),
    [cen.quem.id, cen.item]);
  ok(doGarcom && doGarcom.success === true && doGarcom.pedido.estado === 'em_analise',
     'o pedido do garçom nasce POR DECIDIR, e não servido: «'
     + ((doGarcom.pedido || {}).estado || doGarcom.message) + '»');
  const depoisG = await stockDe(cen.item);
  ok(depoisG.reservado === antesG.reservado,
     'e não promete garrafa nenhuma antes de a copa o aprovar: '
     + antesG.reservado + ' → ' + depoisG.reservado);

  // A copa aprova, e é AÍ que a promessa se faz.
  await p.evaluate(async (id) =>
    await window.api('bar_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aprovar' }) }), doGarcom.pedido.id);
  const aprovado = await stockDe(cen.item);
  ok(aprovado.reservado === antesG.reservado + 1,
     'aprovado pela copa, aí sim: ' + antesG.reservado + ' → ' + aprovado.reservado);
  // E entrega-se: «O que a festa bebeu» conta o ENTREGUE, e sem isto o gráfico
  // que a secção 4 vai clicar não tem barra nenhuma para clicar.
  await p.evaluate(async (id) =>
    await window.api('bar_entregue', { method: 'POST',
                                       body: JSON.stringify({ id: id }) }), doGarcom.pedido.id);

  // ============ 2. as regras valem também no balcão ============
  // Uma bebida fechada a toda a gente. O copeiro lança pelo balcão — a porta
  // que não consultava regra nenhuma — e tem de esbarrar como qualquer outro.
  const rid = await p.evaluate(async (it) => {
    const d = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, nota: 'ZQ fechada' }) });
    return (d.regras || []).filter(r => r.nota === 'ZQ fechada')[0].id;
  }, cen.item);
  ok(!!rid, 'fecha-se a bebida a toda a gente');

  const peloBalcao = await p.evaluate(async ([g, it]) =>
    await window.api('bar_pedir_por', { method: 'POST', silencioso: true,
      body: JSON.stringify({ convidado_id: g, entregue: true,
                             itens: [{ item_id: it, quantidade: 1 }] }) }),
    [cen.quem.id, cen.item]);
  ok(peloBalcao && peloBalcao.success === false,
     'e o copeiro NÃO a serve pelo balcão: «' + (peloBalcao.message || '') + '»');
  ok(/regras do bar/i.test(peloBalcao.message || ''),
     'com a razão à vista — foram as regras, e diz onde se levantam');

  const peloGarcom = await gar.evaluate(async ([g, it]) =>
    await window.api('bar_pedir_por', { method: 'POST', silencioso: true,
      body: JSON.stringify({ convidado_id: g, itens: [{ item_id: it, quantidade: 1 }] }) }),
    [cen.quem.id, cen.item]);
  ok(peloGarcom && peloGarcom.success === false,
     'nem o garçom a submete — a regra é a mesma nas duas portas');

  // ============ 3. suspender, e voltar sozinha ============
  await p.evaluate(async (id) =>
    await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: id }) }), rid);

  // Uma suspensão que JÁ EXPIROU não trava nada: é a prova de que a bebida
  // volta sozinha sem ninguém lhe tocar. Escreve-se com um minuto e depois
  // recua-se a hora de saída na própria regra, que é o que o tempo faria.
  const susp = await p.evaluate(async (it) =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, expira_min: 45,
        nota: 'ZQ suspensa' }) }), cen.item);
  const sid = (susp.regras || []).filter(r => r.nota === 'ZQ suspensa')[0];
  ok(!!sid && !!sid.expira_em, 'suspende-se a bebida por 45 minutos, com hora de saída');

  // O que o convidado lê: «indisponível de momento», e quanto falta.
  const conv = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  vigiar(conv, 'convidado');
  await conv.goto(BASE + '/bebidas.php?m=' + cen.token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(700);
  await conv.evaluate(async ([t, g]) => {
    await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: g }) });
  }, [cen.token, cen.quem.id]);
  const barrada = await conv.evaluate(async ([t, it]) => {
    const d = await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: it, quantidade: 1 }] }) })).json();
    return d;
  }, [cen.token, cen.item]);
  ok(barrada && barrada.success === false && /indisponível de momento/i.test(barrada.message || ''),
     'o convidado lê «indisponível de momento», e não «esta noite»: «'
     + (barrada.message || '') + '»');
  ok(/daqui a/i.test(barrada.message || ''),
     'e lê quanto falta, que é a única coisa que ele quer saber');

  // Passada a hora, a bebida volta — sem ninguém levantar a regra. Recua-se a
  // hora de saída, que é o que o tempo faria se a prova pudesse esperar 45
  // minutos: a regra fica escrita, e deixa de contar para o veredicto.
  await p.evaluate(async ([id, it]) =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: id, escopo: 'item', alvo_id: it, sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 0, janela_min: 0, expira_em: '2000-01-01 00:00',
        nota: 'ZQ suspensa' }) }), [sid.id, cen.item]);
  const voltou = await conv.evaluate(async ([t, it]) =>
    await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: it, quantidade: 1 }] }) })).json(),
    [cen.token, cen.item]);
  ok(voltou && voltou.success === true,
     'passada a hora, a bebida volta sozinha — a regra continua escrita e já não trava');

  // ============ 4. as regras de uma bebida, por onde se vê o problema ============
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  // Pela coluna do stock: carregar numa bebida traz o acerto E as regras dela.
  await p.locator('#b-stock .b-item:has-text("ZQ Genebra")').first().click();
  await p.waitForTimeout(900);
  const naJanela = await p.evaluate(() => {
    const j = document.getElementById('lic-janela');
    return j ? j.innerText : '';
  });
  ok(/Quanto a esta bebida/.test(naJanela),
     'a janela de uma bebida traz também as regras dela');
  ok(/Suspender por um bocado/.test(naJanela),
     'e o gesto de a fechar por um bocado, que é o que se faz a meio da noite');
  await p.keyboard.press('Escape');
  await p.waitForTimeout(500);

  // Por «Os números»: a barra de uma bebida abre as regras dela. As abas da
  // copa são pastilhas sem id — clica-se-lhes pelo nome, que é o que a copa vê.
  await p.locator('#b-fer-fila .b-pilula:has-text("Os números")').first().click();
  await p.waitForTimeout(1800);
  const barra = p.locator('#nm-bebidas .b-graf-l.toca:has-text("ZQ Genebra")');
  ok(await barra.count() === 1,
     'o gráfico das bebidas traz a nossa, e ela responde ao toque');
  await barra.first().click();
  await p.waitForTimeout(900);
  const daBebida = await p.evaluate(() => {
    const j = document.getElementById('lic-janela');
    return j ? j.innerText : '';
  });
  ok(/Regras de ZQ Genebra/.test(daBebida),
     'e carregar nela abre as regras daquela bebida: «'
     + daBebida.split('\n')[0] + '»');
  ok(/Regra nova/.test(daBebida) && /Suspender/.test(daBebida),
     'com onde acrescentar e onde suspender');
  await p.keyboard.press('Escape');
  await p.waitForTimeout(400);

  // ============ 5. o nome do convidado, uma vez só ============
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(1300);
  const nomes = await conv.evaluate((nome) => {
    const topo = document.querySelector('.b-festa-topo');
    const t = topo ? topo.innerText : '';
    let n = 0, i = 0;
    while ((i = t.indexOf(nome, i)) >= 0) { n++; i += nome.length; }
    return { vezes: n, temEu: !!document.getElementById('b-eu'), texto: t.trim() };
  }, cen.quem.nome);
  ok(!nomes.temEu, 'a barra já não tem a linha do nome à parte');
  ok(nomes.vezes === 1,
     'e o nome de quem entrou lê-se uma vez só, dentro da pastilha ('
     + nomes.vezes + '×)');

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const r of (e.regras || []).filter(r => /^ZQ /.test(r.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZQ /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const cv = await window.api('convite_list&busca=ZQ%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZQ Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });
  await ges.evaluate(async () => {
    const d = await window.api('acesso_lista', { method: 'GET', silencioso: true });
    for (const a of ((d && d.acessos) || [])) {
      if (a.email === 'zq.garcom@exemplo.pt') {
        await window.api('conta_apagar_do_casamento&utilizador=' + a.utilizador_id,
                         { method: 'POST', silencioso: true });
      }
    }
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
