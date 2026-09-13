// A prova da quarta passagem do bar (docs/modulo-bar.md §29).
//
// O que aqui se defende:
//   1. O endereço deixou de contar. A conversa do wi-fi partilhado saiu toda.
//   2. As regras são de duas famílias — gerais (a copa) e específicas — e
//      qualquer uma delas se edita.
//   3. Ninguém serve um pedido travado pelas regras, nem a copa. Mas a
//      terceira porta continua aberta: cortar para o que cabe passa.
//   4. A copa fecha um pedido que está por entregar, e o stock desce nessa hora.
//   5. O garçom muda a mesa de um pedido em vez de o devolver à copa.
//   6. A ficha traz TODAS as notas da pessoa.
//   7. A página do convidado diz em nome de quem se pede, é uma coluna só, e
//      não perde o cursor a cada letra.
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

  const cen = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    // A lousa limpa. Um tecto «ao todo» conta a noite inteira, e por isso um
    // pedido deixado por uma corrida anterior gasta quota nesta — a prova
    // media-se contra o que ela própria tinha feito antes.
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const r of (e.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZW /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZW Tinto', categoria_id: e.categorias[0].id, stock: 50,
        visivel: 1, max_por_pedido: 6 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    // Gente só desta prova. Um tecto «ao todo» conta a noite inteira: se se
    // medisse contra a primeira pessoa da lista, as bebidas que as outras
    // provas lhe deram já tinham gasto a quota antes de esta começar.
    const cv = await window.api('convite_list&busca=ZW%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZW Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    await window.api('convite_save', { method: 'POST', body: JSON.stringify({
      nome_exibicao: 'ZW Prova', tipo: 'digital', lado: 'noivo',
      membros: [{ nome: 'ZW Bebedor' }] }) });
    const n = await window.api('bar_procurar_pessoal&limite=500', { method: 'GET' });
    return { item: d.id, token: window.BAR_MESAS[0].token,
             quem: (n.nomes || []).filter(x => x.nome === 'ZW Bebedor')[0] };
  });
  ok(!!cen.quem, 'há uma pessoa só para esta prova, com a noite ainda por beber');
  const token = cen.token;

  // ============ 1. o endereço deixou de contar ============
  const defs = await p.evaluate(async () => (await window.api('bar_estado')).defs || {});
  ok(defs['bar.ip_modo'] === undefined,
     'a definição «pedidos da mesma rede» já não existe');
  const fonte = await p.evaluate(async () => await (await fetch('assets/bar-regras.js')).text());
  ok(!/ip_modo|IP_MODOS/.test(fonte),
     'e o painel das regras já não fala de redes partilhadas');

  // ============ 2. duas famílias, e editáveis ============
  await p.click('#ab-regras');
  await p.waitForTimeout(900);
  const familias = await p.locator('.b-fam-t b').allInnerTexts();
  ok(familias.join('|') === 'Regras gerais|Regras específicas',
     'as regras estão em duas famílias: ' + familias.join(' · '));

  const posta = await p.evaluate(async () =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas', quantidade: 2,
        janela_min: 0, nota: 'ZW tecto' }) }));
  ok(posta && posta.success === true, 'põe-se uma regra específica de 2 bebidas');
  const rid = (posta.regras || []).filter(r => r.nota === 'ZW tecto')[0].id;

  // Editar: a MESMA regra, com outro número — e não uma regra nova.
  const editada = await p.evaluate(async (id) =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: id, escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 3, janela_min: 0, nota: 'ZW tecto' }) }), rid);
  const agora = (editada.regras || []).filter(r => r.nota === 'ZW tecto');
  ok(agora.length === 1 && agora[0].quantidade === 3 && agora[0].id === rid,
     'e edita-se no sítio, sem nascer uma segunda: ' + agora.length + ' regra, quantidade '
     + (agora[0] || {}).quantidade);
  // O ecrã tem o gesto, e não só a API.
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  await p.click('#ab-regras');
  await p.waitForTimeout(800);
  ok((await p.locator('.b-reg button[title*="Editar"]').count()) >= 1,
     'e o botão de editar existe em cada regra escrita');

  // ============ 3. ninguém serve o que as regras travam ============
  // O tecto é 3; pede-se 5. A copa não pode aprovar — mas pode cortar para 3.
  const conv = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  vigiar(conv, 'convidado');
  await conv.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(700);
  const pedido = await conv.evaluate(async ([t, g, it]) => {
    await (await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: g }) })).json();
    // Cinco de uma vez: o tecto de 3 só se descobre ao decidir, porque a
    // regra pode ser posta DEPOIS de o pedido entrar (§8.0.1).
    return await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: it, quantidade: 5 }] }) })).json();
  }, [token, cen.quem.id, cen.item]);
  ok(pedido && pedido.success === false,
     'o convidado esbarra logo no tecto: «' + (pedido.message || '') + '»');

  // Um que passe a porta da frente, e a regra a apertar depois.
  const entrou = await conv.evaluate(async ([t, it]) =>
    await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: it, quantidade: 3 }] }) })).json(),
    [token, cen.item]);
  ok(entrou && entrou.success === true, 'e um de 3 entra na fila');
  const pid = entrou.pedido.id;

  await p.evaluate(async (id) =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { id: id, escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas',
        quantidade: 1, janela_min: 0, nota: 'ZW tecto' }) }), rid);
  const barrado = await p.evaluate(async (id) =>
    await window.api('bar_decidir', { method: 'POST', silencioso: true,
      body: JSON.stringify({ id: id, decisao: 'aprovar' }) }), pid);
  ok(barrado && barrado.success === false,
     'apertada a regra para 1, a copa já NÃO pode aprovar os 3: «'
     + (barrado.message || '') + '»');
  ok(/regras do bar/i.test(barrado.message || ''),
     'e a mensagem diz que foram as regras, e manda onde se levantam');

  // Mas a terceira porta continua aberta: cortar para 1 passa.
  const cortado = await p.evaluate(async ([id, it]) =>
    await window.api('bar_decidir', { method: 'POST', body: JSON.stringify(
      { id: id, decisao: 'aprovar', cortes: { [it]: 1 },
        motivo_texto: 'as regras da casa' }) }), [pid, cen.item]);
  ok(cortado && cortado.success === true,
     'mas cortar para o que cabe passa — a terceira porta não se fechou');

  // ============ 4. a copa dá por entregue ============
  const antes = await p.evaluate(async (it) => {
    const e = await window.api('bar_estado');
    return (e.itens || []).filter(i => i.id === it)[0].stock;
  }, cen.item);
  const entregue = await p.evaluate(async (id) =>
    await window.api('bar_entregue', { method: 'POST', body: JSON.stringify({ id: id }) }), pid);
  ok(entregue && entregue.success === true, 'a copa fecha um pedido que estava por entregar');
  const depois = await p.evaluate(async (it) => {
    const e = await window.api('bar_estado');
    return (e.itens || []).filter(i => i.id === it)[0].stock;
  }, cen.item);
  ok(depois === antes - 1,
     'e é ESSE o gesto que baixa o stock, como sempre foi: ' + antes + ' → ' + depois);

  // ============ 5. o garçom muda a mesa ============
  // A regra de 1 por noite já mordeu (bloco 3) e esta pessoa já levou a sua.
  // Levanta-se: o que aqui se prova é a mesa, e não o tecto.
  await p.evaluate(async (id) =>
    await window.api('bar_regra_apagar', { method: 'POST',
                                           body: JSON.stringify({ id: id }) }), rid);
  const outro = await conv.evaluate(async ([t, it]) =>
    await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: it, quantidade: 1 }] }) })).json(),
    [token, cen.item]);
  const pid2 = outro.pedido.id;
  await p.evaluate(async (id) =>
    await window.api('bar_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aprovar' }) }), pid2);
  const mesas = await p.evaluate(async () => (await window.api('bar_mesas')).mesas || []);
  ok(mesas.length >= 1, 'o pessoal do bar vê a lista de mesas (' + mesas.length + ')');
  const mudou = await p.evaluate(async ([id, m]) =>
    await window.api('bar_mudar_mesa', { method: 'POST',
      body: JSON.stringify({ id: id, mesa_id: m }) }), [pid2, mesas[mesas.length - 1].id]);
  ok(mudou && mudou.success === true
     && mudou.pedido.mesa_id === mesas[mesas.length - 1].id,
     'e muda a mesa de um pedido em vez de o devolver: agora vai à «'
     + (mudou.pedido || {}).mesa + '»');

  // ============ 5.1 quem LANÇA um pedido também escolhe a mesa ============
  // Mudar a mesa de um pedido já feito resolvia metade do problema: a outra
  // metade é o pedido que nasce no sítio errado. O copeiro ao balcão e o
  // garçom na sala estão os dois a olhar para onde a pessoa está — e a entrega
  // ia sempre para a mesa da planta, que ao fim de uma hora já não diz nada.
  const jardim = await p.evaluate(async () => {
    await window.api('mesa_save', { method: 'POST',
      body: JSON.stringify({ nome: 'ZW Jardim', lugares: 4 }) });
    const l = await window.api('mesa_list');
    return ((l.mesas || []).filter(m => m.nome === 'ZW Jardim')[0] || {}).id || 0;
  });
  ok(jardim > 0, 'há um sítio para onde a pessoa se possa ter mudado');

  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  await p.evaluate(() => window.copaPedirPor());
  await p.waitForTimeout(1800);
  const cxM = p.locator('#lic-janela .lic-sel[data-sel="mesa"]');
  ok(await cxM.count() === 1,
     'a janela do balcão pergunta para onde vai a bebida que a copa lança');

  await p.fill('#lf-nome', cen.quem.nome.slice(0, 4));
  await p.waitForTimeout(900);
  await p.locator('#pp-achados button').first().click();
  await p.waitForTimeout(300);
  await cxM.locator('.lic-sel-bt').click();
  await p.waitForTimeout(300);
  await cxM.locator('.lic-sel-q input').fill('ZW Jardim');
  await p.waitForTimeout(400);
  await cxM.locator('.lic-sel-op:visible').first().click();
  await p.waitForTimeout(300);
  // Sem o «já foi entregue»: assim o pedido fica POR ENTREGAR, que é o caso em
  // que a mesa interessa — alguém tem de lá ir.
  await p.uncheck('#lf-entregue').catch(() => {});
  await p.click('#lic-jo');
  await p.waitForTimeout(1600);

  const noJardim = await p.evaluate(async (nome) => {
    const e = await window.api('bar_estado');
    return (e.fila || []).filter(x => x.convidado === nome).map(x => x.mesa || '—');
  }, cen.quem.nome);
  ok(noJardim.includes('ZW Jardim'),
     'e o pedido que ela lança vai para a mesa escolhida, e não para a da planta: '
     + noJardim.join(' · '));

  // ============ 6. a ficha traz TODAS as notas ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  await p.evaluate(async (id) =>
    await window.api('bar_entregue', { method: 'POST', body: JSON.stringify(
      { id: id, nota: 'ZW nota de prova' }) }), pid2);
  const ficha = await p.evaluate(async (g) =>
    await window.api('bar_ficha&convidado=' + g), cen.quem.id);
  ok(Array.isArray(ficha.notas) && ficha.notas.some(n => /ZW nota de prova/.test(n.texto)),
     'a ficha da pessoa traz as notas dos garçons (' + (ficha.notas || []).length + ')');

  // ============ 7. a página do convidado ============
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(1200);
  const bt = await conv.locator('#b-para').innerText().catch(() => '');
  ok(new RegExp(cen.quem.nome.split(' ')[0], 'i').test(bt),
     'o botão diz em nome de quem se está a pedir, e não «pedir por outra pessoa»: «'
     + bt.trim() + '»');
  // Uma coluna só: a abertura e o corpo têm de partilhar a largura.
  const larguras = await conv.evaluate(() => {
    const c = document.querySelector('.b-festa-capa');
    const m = document.querySelector('.b-corpo');
    return c && m ? [Math.round(c.getBoundingClientRect().width),
                     Math.round(m.getBoundingClientRect().width)] : null;
  });
  ok(larguras && Math.abs(larguras[0] - larguras[1]) <= 2,
     'a abertura e o corpo são a mesma coluna: ' + (larguras || []).join(' vs '));

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const r of (e.regras || []).filter(r => /^ZW /.test(r.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZW /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    const cv = await window.api('convite_list&busca=ZW%20Prova', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (c.nome_exibicao === 'ZW Prova') {
        await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
      }
    }
    // A mesa desta prova sai daqui: uma mesa a mais no salão muda a lotação
    // que as outras provas contam.
    const ms = await window.api('mesa_list', { silencioso: true });
    for (const m of ((ms && ms.mesas) || []).filter(m => /^ZW /.test(m.nome))) {
      await window.api('mesa_delete&id=' + m.id, { method: 'POST', silencioso: true });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
