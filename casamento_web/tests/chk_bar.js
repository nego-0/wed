// O bar da festa, do princípio ao fim.
//
// Quatro ecrãs e uma volta completa: os noivos montam o menu (bar.php), o
// convidado escolhe-se numa lista e pede da mesa (bebidas.php), a copa aprova
// ou recusa (copa.php), e o empregado entrega (entregas.php).
//
// O que esta prova defende, acima de tudo, são as três contas do stock. Uma
// bebida tem o que existe, o que está prometido e o que sobra para oferecer —
// e a regra que impede vender a última garrafa duas vezes é uma só: APROVAR
// promete, ENTREGAR é que baixa. Se algum dia alguém trocar as voltas e fizer
// o stock descer na aprovação, um pedido cancelado passa a roubar bebida ao
// armazém, e a festa fica sem espumante numa folha de cálculo que diz que há.
//
// Defende também a porta pública. O convidado não tem sessão nem link no
// convite: entra por um código impresso em cima da mesa. Esse código tem de
// abrir o casamento certo — e um código inventado tem de bater com a porta.
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

  // ============ 1. os noivos montam o bar ============
  const casa = await b.newContext({ viewport: { width: 1200, height: 1100 } });
  const noivos = await casa.newPage();
  vigiar(noivos, 'bar.php');
  await noivos.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await noivos.fill('input[name=utilizador]', 'admin');
  await noivos.fill('input[name=senha]', 'noivos2026');
  await noivos.click('button[type=submit]');
  await noivos.waitForLoadState('networkidle');
  await noivos.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await noivos.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await noivos.waitForTimeout(700);

  ok((await noivos.locator('.b-aba').allTextContents()).join('|') === 'O menu|Gavetas|Mesas e QR',
     'a montagem tem as três abas: o menu, as gavetas e as folhas de QR');
  ok((await noivos.locator('.b-cat').count()) >= 4,
     'o bar nasce com gavetas semeadas, e não com um menu em branco');

  const montado = await noivos.evaluate(async () => {
    const e = await window.api('bar_estado');
    const cat = e.categorias[0].id;
    const ids = [];
    for (const [nome, stock] of [['ZZ Espumante', 40], ['ZZ Cerveja', 90]]) {
      const d = await window.api('bar_item_guardar', { method: 'POST',
        body: JSON.stringify({ nome, categoria_id: cat, stock, visivel: 1, max_por_pedido: 3 }) });
      ids.push(d && d.id);
    }
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const e2 = await window.api('bar_estado');
    return { ids, aberto: e2.estado.aberto };
  });
  ok(montado.ids.every(Boolean), 'os noivos lançam bebidas no menu');
  // As regras do bar não vivem no vocabulário do convite: se algum dia
  // voltarem a passar por guardarDefinicoes(), são deitadas fora em silêncio
  // e o interruptor deixa de guardar nada.
  ok(montado.aberto === true, 'abrir o bar guarda-se mesmo, e não só no ecrã');

  const mesa = await noivos.evaluate(() => window.BAR_MESAS[0]);
  ok(/^[A-Z0-9]{6,16}$/.test(mesa.token), 'cada mesa tem o seu código para o QR');

  await noivos.click('#ab-mesas');
  await noivos.waitForTimeout(400);
  ok((await noivos.locator('.b-folha canvas').count()) >= 1,
     'e a folha da mesa traz o QR desenhado, pronto a imprimir');

  // ============ 2. a porta pública ============
  const salao = await b.newContext({ viewport: { width: 390, height: 844 } });
  const mau = await salao.newPage();
  await mau.goto(BASE + '/bebidas.php?m=NAOEXISTE1', { waitUntil: 'networkidle' });
  ok(/não serve/i.test(await mau.locator('h1').innerText()),
     'um código de mesa inventado bate com a porta');
  await mau.close();

  const conv = await salao.newPage();
  vigiar(conv, 'bebidas.php');
  await conv.goto(BASE + '/bebidas.php?m=' + mesa.token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(800);
  ok(await conv.locator('.b-procura h1').isVisible(),
     'o código certo abre no «Quem está a pedir?» — o nome vem antes do menu');
  ok((await conv.locator('.b-festa-topo .mono').innerText()).trim().length > 0,
     'e a página veste o nome do casal, não a marca da casa');

  // ============ 3. a procura do nome ============
  await conv.fill('#b-q', 'con');
  await conv.waitForTimeout(600);
  ok(/[Ff]alta/.test(await conv.locator('#b-nomes').innerText()),
     'abaixo do mínimo diz quantas letras faltam, em vez de uma lista vazia');
  ok((await conv.locator('.b-nome').count()) === 0,
     'e não mostra nome nenhum: três letras seriam meio índice da festa');

  await conv.fill('#b-q', 'conv');
  await conv.waitForTimeout(700);
  const achados = await conv.locator('.b-nome').count();
  ok(achados > 0 && achados <= 8, 'com o mínimo de letras aparecem nomes, no máximo oito');
  ok(!/Mesa /.test(await conv.locator('#b-nomes').innerText()),
     'a lista nunca diz onde a pessoa está sentada — seria dizer a estranhos onde ela é');

  await conv.locator('.b-nome').first().click();
  await conv.waitForTimeout(900);
  ok((await conv.locator('#b-eu').innerText()).trim().length > 3,
     'escolher-se prende o telemóvel a esse nome');
  ok(await conv.locator('#b-mesa').isVisible(),
     'a mesa de entrega fica à vista e muda-se: as pessoas trocam de lugar');
  ok((await conv.locator('.b-bebida').count()) >= 2,
     'e o menu abre com as bebidas nas suas gavetas');

  // ============ 4. o pedido ============
  const mais = conv.locator('.b-bebida .b-mais button:last-child').first();
  await mais.click(); await conv.waitForTimeout(150);
  await mais.click(); await conv.waitForTimeout(300);
  ok(await conv.locator('#b-rodape').isVisible(),
     'o cesto vive no rodapé, na zona do polegar');
  ok(/2 bebidas/.test(await conv.locator('#b-resumo').innerText()),
     'e conta o que lá está, com a mesa a que vai');

  await conv.click('#b-pedir');
  await conv.waitForTimeout(1100);
  const codigo = await conv.locator('.b-recibo .cod').innerText().catch(() => '');
  ok(/^[A-Z]\d+$/.test(codigo),
     'o pedido devolve um código curto para se dizer em voz alta: ' + codigo);
  await conv.click('#lic-jc');
  await conv.waitForTimeout(700);
  ok(/análise/i.test(await conv.locator('.b-meu .b-est').innerText()),
     'e fica em «Os meus pedidos», em análise');
  // A barra tem display:flex de classe, que ganha à regra [hidden] do browser:
  // sem o !important da folha, ficava a dizer «2 bebidas» com o cesto vazio,
  // e a pessoa carregava outra vez em Pedir a contar que o primeiro se perdera.
  ok(await conv.locator('#b-rodape').isHidden(),
     'e o cesto esvazia-se à vista: a barra do rodapé desaparece com ele');

  // ============ 5. a copa decide ============
  const copa = await casa.newPage();
  vigiar(copa, 'copa.php');
  await copa.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await copa.waitForTimeout(1000);
  ok((await copa.locator('#k-analise').innerText()) === '1', 'a copa vê o pedido na fila');
  const naFila = await copa.locator('.b-ped').first().innerText();
  ok(naFila.includes(codigo) && /Mesa /.test(naFila),
     'com o código e a mesa — que é o que faz o trabalho andar');

  const antes = await copa.evaluate(async () => {
    const e = await window.api('bar_estado');
    const i = e.itens.find(x => x.nome === 'ZZ Cerveja');
    return { stock: i.stock, reservado: i.reservado, disponivel: i.disponivel };
  });
  await copa.locator('.b-ped .btn-ouro').first().click();
  await copa.waitForTimeout(1000);
  const aprovado = await copa.evaluate(async () => {
    const e = await window.api('bar_estado');
    const i = e.itens.find(x => x.nome === 'ZZ Cerveja');
    return { stock: i.stock, reservado: i.reservado, disponivel: i.disponivel,
             analise: e.estado.em_analise, aprovados: e.estado.aprovados };
  });
  ok(aprovado.stock === antes.stock,
     'APROVAR não toca no stock real (' + antes.stock + ' na copa, e continua lá)');
  ok(aprovado.reservado === antes.reservado + 2,
     'aprovar promete duas — é o que impede vendê-las a outro');
  ok(aprovado.disponivel === antes.disponivel - 2,
     'e é o disponível, não o stock, que baixa');
  ok(aprovado.analise === 0 && aprovado.aprovados === 1, 'a fila passa para «por entregar»');

  // ============ 6. a entrega, que é o que baixa o stock ============
  const ent = await casa.newPage();
  vigiar(ent, 'entregas.php');
  await ent.goto(BASE + '/entregas.php', { waitUntil: 'networkidle' });
  await ent.waitForTimeout(1000);
  ok((await ent.locator('#k-espera').innerText()) === '1', 'o empregado vê um por apanhar');
  ok((await ent.locator('.b-ped').first().innerText()).includes(codigo), 'com o mesmo código');

  await ent.locator('.b-ped .btn-ouro').first().click();          // Apanhar
  await ent.waitForTimeout(900);
  ok((await ent.locator('#k-minhas').innerText()) === '1',
     'apanhar marca-o como seu — dois empregados não tropeçam no mesmo pedido');

  await ent.locator('.b-ped.minha .btn-ouro').first().click();    // Entregue
  await ent.waitForTimeout(1100);
  const fim = await ent.evaluate(async () => {
    const e = await window.api('bar_estado');
    const i = e.itens.find(x => x.nome === 'ZZ Cerveja');
    return { stock: i.stock, reservado: i.reservado, disponivel: i.disponivel,
             entregues: e.estado.entregues, bebidas: e.estado.bebidas_entregues };
  });
  ok(fim.stock === antes.stock - 2,
     'ENTREGAR é o único momento em que o stock real desce ('
     + antes.stock + ' → ' + fim.stock + ')');
  ok(fim.reservado === antes.reservado,
     'e a promessa é libertada, em vez de ficar presa para sempre');
  ok(fim.disponivel === antes.disponivel - 2, 'as três contas fecham entre si');
  ok(fim.entregues === 1 && fim.bebidas === 2, 'as contas da noite: 1 pedido, 2 bebidas');
  ok(await ent.locator('#b-tempos').isVisible(),
     'e os tempos da noite aparecem depois da primeira entrega');

  // ============ 7. a recusa diz sempre porquê ============
  await conv.locator('.b-bebida .b-mais button:last-child').first().click();
  await conv.waitForTimeout(250);
  await conv.click('#b-pedir');
  await conv.waitForTimeout(1000);
  await conv.click('#lic-jc');
  await copa.reload({ waitUntil: 'networkidle' });
  await copa.waitForTimeout(1000);
  await copa.locator('.b-ped .btn-fantasma').first().click();     // Recusar
  await copa.waitForTimeout(600);
  ok(await copa.locator('#lf-motivo_id').isVisible(),
     'recusar abre a lista de motivos: um «não» seco manda a pessoa pedir outra vez');
  await copa.click('#lic-jo');
  await copa.waitForTimeout(1000);
  const recusa = await copa.evaluate(async () => {
    const e = await window.api('bar_estado');
    const p = e.resolvidos.find(x => x.estado === 'recusado');
    return p ? p.motivo : null;
  });
  ok(!!recusa, 'o pedido fica recusado com o motivo: «' + recusa + '»');

  // ============ 8. o convidado vê tudo sem tocar em nada ============
  await conv.waitForTimeout(11000);
  const meu = await conv.locator('.b-meu').first().innerText();
  ok(/não servido/i.test(meu),
     'o telemóvel actualiza-se sozinho, e diz «não servido» — não «RECUSADO»');
  ok(meu.includes(recusa), 'e mostra o motivo, para a pessoa saber o que pedir a seguir');

  // ============ 9. os dois postos existem mesmo ============
  // Sem isto o bar tinha dois ecrãs que ninguém podia abrir: a Gestão só sabia
  // convidar porteiros, e portanto não havia maneira de alguém SER copeiro.
  const ges = await casa.newPage();
  vigiar(ges, 'gestao.php');
  await ges.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await ges.waitForTimeout(900);
  ok((await ges.locator('#a-papel option').allTextContents()).join('|')
       === 'Porteiro|Copeiro|Empregado de sala',
     'a Gestão convida para os três postos, e não só para a porta');

  await ges.selectOption('#a-papel', 'copeiro');
  await ges.waitForTimeout(200);
  ok(/stock/.test(await ges.locator('#a-oque').innerText()),
     'e diz o que o posto vê antes de o dar: ' + (await ges.locator('#a-oque').innerText()));

  await ges.fill('#a-email', 'zz.copeiro@exemplo.pt');
  await ges.fill('#a-nome', 'ZZ Copeiro');
  await ges.click('button:has-text("Convidar")');
  await ges.waitForTimeout(1500);
  const senha = await ges.locator('#senha-nova .cod').innerText().catch(() => '');
  ok(!!senha, 'a conta nasce com senha temporária para entregar em mão');
  ok(/só a copa do bar/.test(await ges.locator('#lista-acessos').innerText()),
     'e a lista chama-lhe pelo posto — não «só a porta», que era o que lá estava');

  const dele = await b.newContext();
  const cop = await dele.newPage();
  vigiar(cop, 'copa (copeiro)');
  await cop.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await cop.fill('input[name=utilizador]', 'zz.copeiro@exemplo.pt');
  await cop.fill('input[name=senha]', senha);
  await cop.click('button[type=submit]');
  await cop.waitForLoadState('networkidle');
  await cop.waitForTimeout(1200);
  ok(/copa\.php/.test(cop.url()), 'o copeiro entra e aterra na copa, sem passar pelo painel');
  ok(!/Montar o menu|Entregas/.test(await cop.locator('.topo .nav').innerText()),
     'e o cabeçalho não lhe oferece portas que não são dele');
  await cop.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok(/login\.php/.test(cop.url()),
     'a lista de convidados fica-lhe fechada: ele veio servir bebidas');
  await cop.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  ok(/login\.php/.test(cop.url()), 'e a montagem do menu também — essa é dos noivos');

  // ============ 10. arrumar ============
  await copa.evaluate(async () => {
    const e = await window.api('bar_estado');
    // Os pedidos por entregar prendem as bebidas; cancelam-se primeiro.
    for (const p of e.fila) {
      await window.api('bar_cancelar_copa', { method: 'POST', body: JSON.stringify({ id: p.id }) });
    }
    for (const i of e.itens.filter(x => /^ZZ /.test(x.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });
  await ges.evaluate(async () => {
    const d = await window.api('acesso_lista');
    const zz = (d.acessos || []).find(a => a.email === 'zz.copeiro@exemplo.pt');
    if (zz) await window.api('conta_apagar_do_casamento&utilizador=' + zz.utilizador_id,
                             { method: 'POST' });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
