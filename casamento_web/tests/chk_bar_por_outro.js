// Um convidado pede por outro convidado, da própria página de pedidos.
//
// Numa mesa há sempre quem não tenha o telemóvel à mão, quem o tenha sem
// bateria, e quem simplesmente não queira lidar com aquilo — e pede ao
// vizinho. Isto já era possível antes, mas só pela porta errada: trocar de
// nome no telemóvel (§5.3). Essa troca PRENDE o aparelho à outra pessoa, e o
// pedido passava a dizer que quem pediu foi ela — quem pediu de facto
// desaparecia do registo, e as bebidas seguintes saíam no nome errado.
//
// O que esta prova defende, por ordem de importância:
//
//   1. **A quota é de quem bebe.** Os limites contam-se contra a pessoa
//      nomeada. Se isto falhar, pedir por outro passa a ser a maneira de
//      contornar um tecto, e todo o §8 fica a valer zero.
//   2. **O rasto fica melhor, e não pior.** O pedido guarda os DOIS nomes, e a
//      copa vê-os. É a vantagem sobre a troca de nome, que apagava um deles.
//   3. **Volta-se a si sozinho.** Um «a pedir para outro» esquecido ligado
//      dava a ronda seguinte inteira em nome do vizinho, à conta dele.
//   4. **O código do convite continua a valer.** Com o PIN ligado, pedir por
//      quem é de OUTRO convite exige o código desse convite — senão bastava
//      não trocar de nome e pedir «pelo padrinho» para o contornar por
//      inteiro, e o PIN não guardava nada.
//   5. **Dentro do mesmo convite não se pede código nenhum**, porque a família
//      é a unidade doméstica de todo o módulo (§5.3, primeira linha).
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

  // Entre secções limpa-se a lousa — a fila e as regras — mas a BEBIDA fica: é
  // o cenário, e apagá-la a meio deixava as secções seguintes a pedir uma
  // bebida que já não existe (a recusa vinha, mas pela razão errada, e a prova
  // dava-se por satisfeita sem ter provado nada).
  const limparFila = () => p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    const r = await window.api('bar_regras');
    for (const x of (r.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
  });
  const limparTudo = async () => {
    await limparFila();
    await p.evaluate(async () => {
      const e = await window.api('bar_estado');
      for (const i of (e.itens || []).filter(i => /^ZZO /.test(i.nome))) {
        await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
      }
      await window.api('bar_defs', { method: 'POST',
        body: JSON.stringify({ 'bar.pedir_pin': '0' }) });
      const d = await window.api('convite_list&busca=ZZO', { silencioso: true });
      for (const c of ((d && d.convites) || [])) {
        if (/^ZZO /.test(c.nome_exibicao)) {
          await window.api('convite_delete&definitivo=1&id=' + c.id, { method: 'POST' });
        }
      }
    });
  };
  await limparTudo();

  const base = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    // Um segundo convite, para haver quem NÃO seja da família de quem pede —
    // é contra esse caso que o código do convite defende alguma coisa.
    await window.api('convite_save', { method: 'POST', body: JSON.stringify(
      { nome_exibicao: 'ZZO Outra Família', tipo: 'digital', lado: 'noivo',
        membros: [{ nome: 'ZZO Vizinho Quatro' }] }) });
    await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZZO Cerveja', categoria_id: e.categorias[0].id, stock: 60,
        estado: 'ativo', max_por_pedido: 5 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const e2 = await window.api('bar_estado');
    const n = await window.api('bar_procurar_pessoal&q=');
    return { item: (e2.itens || []).filter(i => i.nome === 'ZZO Cerveja')[0].id,
             quem: n.nomes, token: window.BAR_MESAS[0].token };
  });
  // A (quem pede) e B (por quem se pede) do MESMO convite; C de outro, se
  // houver — é ele que a parte do código defende.
  const A = base.quem[0];
  const B = base.quem.filter(x => x.id !== A.id && x.convite === A.convite)[0];
  const C = base.quem.filter(x => x.convite !== A.convite)[0];
  ok(!!B, 'há duas pessoas do mesmo convite para a prova: '
     + (B ? A.nome + ' e ' + B.nome : '—'));
  if (!B) { console.log('\nsem cenário: nada a provar'); await b.close(); process.exit(1); }

  const token = base.token;
  const abrir = async (quem) => {
    const c = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    vigiar(c, 'bebidas');
    await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
    await c.waitForTimeout(700);
    await c.evaluate(async ([t, id]) => {
      await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, convidado_id: id }) });
    }, [token, quem.id]);
    await c.reload({ waitUntil: 'networkidle' });
    await c.waitForTimeout(1000);
    return c;
  };
  const levou = (id) => p.evaluate(async (g) => {
    const d = await window.api('bar_ficha&convidado=' + g, { method: 'GET' });
    return (d.levou || []).filter(l => l.nome === 'ZZO Cerveja')
                          .reduce((s, l) => s + l.n, 0);
  }, id);

  const cA = await abrir(A);

  // ============ 1. a pastilha ============
  ok(await cA.locator('#b-para').isVisible(), 'a página oferece pedir por outra pessoa');
  ok(!/A pedir para/.test(await cA.locator('#b-para').innerText()),
     'e começa por si, que é o caso normal');

  // ============ 2. escolher, e pedir ============
  const escolher = async (c, quem) => {
    await c.click('#b-para');
    await c.waitForTimeout(500);
    await c.fill('#b-pq', quem.nome.slice(0, 6));
    await c.waitForTimeout(900);
    await c.locator('#b-pq-lista .b-nome').first().click();
    await c.waitForTimeout(1300);
  };
  await escolher(cA, B);
  ok(/A pedir para/.test(await cA.locator('#b-para').innerText()),
     'escolhida a pessoa, a pastilha di-lo: ' + (await cA.locator('#b-para').innerText()).trim());
  ok(await cA.locator('#b-para.on').count() === 1,
     'e acende, porque é um estado que se pode esquecer');

  const antesA = await levou(A.id), antesB = await levou(B.id);
  await cA.locator('.b-bebida:has-text("ZZO Cerveja") .b-mais button.up').first().click();
  await cA.waitForTimeout(400);
  ok(/Pedir para/.test(await cA.locator('#b-pedir').innerText()),
     'o botão do rodapé passa a dizer o nome — o último sítio onde se repara');
  await cA.click('#b-pedir');
  await cA.waitForTimeout(1600);
  await cA.locator('.pl-modal-rodape button').first().click();
  await cA.waitForTimeout(1200);

  // ============ 3. a quota é de quem bebe ============
  ok(await levou(B.id) === antesB + 1,
     'a bebida conta para quem a vai beber (' + antesB + ' → ' + (await levou(B.id)) + ')');
  ok(await levou(A.id) === antesA,
     'e NÃO para quem a pediu — senão pedir por outro era a maneira de furar um tecto');

  // ============ 4. volta-se a si sozinho ============
  ok(!/A pedir para/.test(await cA.locator('#b-para').innerText()),
     'enviado o pedido, a página volta a pedir para si');

  // ============ 5. a copa vê os dois nomes ============
  const fila = await p.evaluate(async () => {
    const d = await window.api('bar_estado');
    return (d.fila || []).map(x => ({ para: x.convidado, por: x.pedido_por }));
  });
  const linha = fila.filter(x => x.por)[0];
  ok(!!linha && linha.para === B.nome && linha.por === A.nome,
     'a copa vê a bebida de quem é e quem a pediu: '
     + (linha ? linha.para + ' · pedido por ' + linha.por : 'nenhuma linha marcada'));

  // ============ 6. o limite de quem bebe é que trava ============
  // Uma regra que proíbe B: A não pode contorná-la pedindo por B.
  await limparFila();
  await p.evaluate(async (bid) => {
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'convidado', alvo_convidado_id: bid,
        unidade: 'bebidas', quantidade: 0, janela_min: 0, nota: 'ZZO travado' }) });
  }, B.id);
  const recusa = await cA.evaluate(async ([t, bid, item]) => {
    return await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, por_id: bid, mesa_id: 1,
                             itens: [{ item_id: item, quantidade: 1 }] }) })).json();
  }, [token, B.id, base.item]);
  ok(recusa.success === false,
     'a regra de quem bebe trava o pedido feito por outro: «' + (recusa.message || '') + '»');
  // E o próprio A continua a poder pedir para si: a regra é dela, não dele.
  const minha = await cA.evaluate(async ([t, item]) => {
    return await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, mesa_id: 1,
                             itens: [{ item_id: item, quantidade: 1 }] }) })).json();
  }, [token, base.item]);
  ok(minha.success === true, 'e não trava quem pediu: a regra é de uma pessoa, não da mesa');
  await limparFila();

  // ============ 7. o código do convite ============
  if (!C) {
    console.log('(saltado: não há um segundo convite para provar o código)');
  } else {
    const pin = await p.evaluate(async () => {
      await window.api('bar_defs', { method: 'POST',
        body: JSON.stringify({ 'bar.pedir_pin': '1' }) });
      return true;
    });
    // Dentro do MESMO convite, nada muda: a família é a unidade doméstica.
    const semCodigo = await cA.evaluate(async ([t, bid]) =>
      await (await fetch('api.php?action=bar_por_quem&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, por_id: bid }) })).json(), [token, B.id]);
    ok(semCodigo.success === true,
       'com o código ligado, pedir por alguém do MEU convite não pede código nenhum');

    // Para outro convite, sem código, não passa.
    const semPin = await cA.evaluate(async ([t, cid]) =>
      await (await fetch('api.php?action=bar_por_quem&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, por_id: cid, pin: '' }) })).json(), [token, C.id]);
    ok(semPin.success === false,
       'e por alguém de OUTRO convite exige-o: «' + (semPin.message || '') + '»');

    // O código vem da exportação, e é ela própria uma verificação: os quatro
    // dígitos viajam com o convite. Sem isso, levar os dados e trazê-los de
    // volta invalidava em silêncio todos os códigos já impressos.
    // O retrato identifica os convites pelo NOME, e não por id — o id é desta
    // base de dados e não sobrevive a uma importação.
    const oPin = await p.evaluate(async (nome) => {
      const r = await fetch('api.php?action=dados_exportar&ambito=casamento',
                            { headers: { 'X-CSRF-Token': window.CSRF } });
      const d = await r.json();
      const c = ((d.casamentos || [])[0].convites || [])
                  .filter(x => x.nome_exibicao === nome)[0];
      return c ? (c.bar_pin || null) : null;
    }, C.convite);
    ok(!!oPin && /^\d{4}$/.test(oPin),
       'a exportação leva o código do convite — senão importar de volta '
       + 'invalidava em silêncio tudo o que já estava impresso');
    const comPin = await cA.evaluate(async ([t, cid, pn]) =>
      await (await fetch('api.php?action=bar_por_quem&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, por_id: cid, pin: pn || '' }) })).json(),
      [token, C.id, oPin]);
    ok(comPin.success === true, 'e com o código certo passa — é o segredo daquela família');
    await p.evaluate(async () => {
      await window.api('bar_defs', { method: 'POST',
        body: JSON.stringify({ 'bar.pedir_pin': '0' }) });
    });
  }

  // ============ arrumar ============
  await limparTudo();
  await p.evaluate(async () => { await window.api('bar_fechar', { method: 'POST', body: '{}' }); });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
