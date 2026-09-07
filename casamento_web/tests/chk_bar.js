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

  // ---- o que ficou de trás ----
  // Uma corrida que morra a meio deixa bebidas «ZZ» e pedidos por decidir, e
  // esses impedem apagar as bebidas na corrida seguinte — a prova passava a
  // falhar por causa de si própria. Limpa-se à entrada, não só à saída.
  await noivos.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const p of e.fila) {
      await window.api(p.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: p.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    const e2 = await window.api('bar_estado');
    for (const i of e2.itens.filter(x => /^ZZ /.test(x.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
  });

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

  // ---- as folhas para a tesoura ----
  // Vivem numa página à parte porque uma folha para imprimir e um ecrã para
  // trabalhar querem coisas contrárias: aquela não quer cabeçalho, nem menu,
  // nem botões — só papel.
  const folha = await casa.newPage();
  vigiar(folha, 'bar-qr.php');
  await folha.goto(BASE + '/bar-qr.php', { waitUntil: 'networkidle' });
  await folha.waitForTimeout(800);
  ok((await folha.locator('.cartao').count()) >= 1, 'bar-qr.php dá um cartão por mesa');
  const cartaoQr = await folha.locator('.cartao').first().innerText();
  ok(/Sem rede\?/.test(cartaoQr),
     'com o rodapé que diz o que fazer sem rede — é a saída de sempre');
  ok(/bebidas\.php\?m=/.test(cartaoQr),
     'e o endereço escrito, para quem prefira escrever a apontar a câmara');
  const urlDoQr = await folha.locator('.cartao canvas').first().getAttribute('data-url');
  await folha.close();

  // ============ 1b. a fotografia de uma bebida ============
  // «Uma fotografia a sério vende melhor do que um nome», diz a própria
  // página — e é o único caminho da montagem que não passa por JSON, por isso
  // é o que mais facilmente se parte sem ninguém dar por isso.
  const png = await noivos.evaluate(() => {
    const c = document.createElement('canvas'); c.width = 300; c.height = 300;
    const x = c.getContext('2d');
    x.fillStyle = '#B24C7A'; x.fillRect(0, 0, 300, 300);
    return c.toDataURL('image/png').split(',')[1];
  });
  const fich = require('path').join(require('os').tmpdir(), 'zz-bebida.png');
  require('fs').writeFileSync(fich, Buffer.from(png, 'base64'));

  // As bebidas nasceram pela API; a grelha só as tem depois de repintar.
  await noivos.reload({ waitUntil: 'networkidle' });
  await noivos.waitForTimeout(800);
  // Sem fotografia, o cartão põe a CHAPA: um véu da cor da gaveta com o copo
  // dela desenhado a traço (§25.8). Era uma inicial em corpo 32 sobre cor
  // cheia — dezasseis dessas numa grelha eram uma parede de tinta, e a
  // primeira fotografia a sério ficava a parecer o intruso.
  ok((await noivos.locator('.b-cart .capa .b-chapa svg').count()) >= 1,
     'sem fotografia, o cartão desenha o copo da gaveta — e não uma inicial em corpo grande');

  const escolher = noivos.waitForEvent('filechooser');
  // O botão da fotografia passou a ser só o ícone: o rótulo vive no
  // aria-label (e no title), que é o que o torna acessível e o que se procura
  // aqui — se um dia deixar de ter nome, esta linha falha, e é isso que se quer.
  await noivos.locator('.b-cart:has-text("ZZ Espumante") button[aria-label*="fotografia"]')
    .first().click();
  await (await escolher).setFiles(fich);
  await noivos.waitForTimeout(2200);
  const foto = await noivos.locator('.b-cart:has-text("ZZ Espumante") .capa img')
                           .getAttribute('src').catch(() => null);
  ok(!!foto && foto.startsWith('assets/bar/'), 'a fotografia sobe e fica arrumada: ' + foto);
  ok((await noivos.request.get(BASE + '/' + foto)).ok(), 'e serve-se pela web');

  // ============ 2. a porta pública ============
  const salao = await b.newContext({ viewport: { width: 390, height: 844 } });
  const mau = await salao.newPage();
  await mau.goto(BASE + '/bebidas.php?m=NAOEXISTE1', { waitUntil: 'networkidle' });
  ok(/não serve/i.test(await mau.locator('h1').innerText()),
     'um código de mesa inventado bate com a porta');
  await mau.close();

  const impressa = await salao.newPage();
  await impressa.goto(urlDoQr, { waitUntil: 'networkidle' });
  await impressa.waitForTimeout(700);
  ok(await impressa.locator('.b-procura h1').isVisible(),
     'o endereço impresso no cartão abre mesmo o menu — é a única porta que há');
  await impressa.close();

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
  ok((await conv.locator('.b-bebida:has-text("ZZ Espumante") .b-foto img')
                .getAttribute('src').catch(() => null)) === foto,
     'com a fotografia que a copa lá pôs, e não outra');

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
  // A lista vem do mais novo para o mais velho, e o histórico da pessoa
  // sobrevive entre corridas — é o pedido de agora que se confere.
  ok(/análise/i.test(await conv.locator('.b-meu .b-est').first().innerText()),
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
  // A contagem mudou de sítio no desenho novo: vive dentro da pastilha que
  // leva à vista, e não numa barra no topo a repetir o mesmo número (§25.19).
  ok((await copa.locator('.b-pilula:has-text("Por decidir") .n').innerText()) === '1',
     'a copa vê o pedido na fila');
  const naFila = await copa.locator('.b-ped').first().innerText();
  ok(naFila.includes(codigo) && /Mesa /.test(naFila),
     'com o código e a mesa — que é o que faz o trabalho andar');

  const antes = await copa.evaluate(async () => {
    const e = await window.api('bar_estado');
    const i = e.itens.find(x => x.nome === 'ZZ Cerveja');
    return { stock: i.stock, reservado: i.reservado, disponivel: i.disponivel,
             // As contas da noite são de toda a noite, e a prova pode correr
             // duas vezes seguidas: mede-se a diferença, não o total.
             entregues: e.estado.entregues, bebidas: e.estado.bebidas_entregues };
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
  // O mesmo nas entregas: a contagem vive no cabeçalho da fila a que pertence.
  ok((await ent.locator('.b-secao:has-text("Por apanhar") .n').innerText()) === '1',
     'o empregado vê um por apanhar');
  ok((await ent.locator('.b-ped').first().innerText()).includes(codigo), 'com o mesmo código');

  await ent.locator('.b-ped .btn-ouro').first().click();          // Apanhar
  await ent.waitForTimeout(900);
  ok((await ent.locator('.b-secao:has-text("Comigo") .n').innerText()) === '1',
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
  ok(fim.entregues === antes.entregues + 1 && fim.bebidas === antes.bebidas + 2,
     'as contas da noite sobem com a entrega: mais 1 pedido, mais 2 bebidas');
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

  // ============ 8b. a copa é a mesma nos quatro temas ============
  // Ela é escura porque é meia-noite no salão, não porque o casal escolheu
  // uma paleta escura. Com os tokens normais, o tema «escuro» virava-os ao
  // contrário — --gold-pale passa de verde claro a quase preto — e os rótulos
  // da barra desapareciam contra o próprio fundo. Daí os --sala-*, que tema
  // nenhum redefine. Mede-se, em vez de se confiar.
  const luz = (rgb) => {
    const c = (String(rgb).match(/\d+/g) || [0, 0, 0]).slice(0, 3).map(v => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const contraste = (a, b) => {
    const [x, y] = [luz(a), luz(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
  };
  const medidas = {};
  for (const tema of ['niras', 'classico', 'azul', 'escuro']) {
    await copa.evaluate(t => { try { localStorage.setItem('tema', t); } catch (e) {} }, tema);
    await copa.reload({ waitUntil: 'networkidle' });
    await copa.waitForTimeout(700);
    const m = await copa.evaluate(() => ({
      rotulo: getComputedStyle(document.querySelector('.b-barra .l')).color,
      numero: getComputedStyle(document.querySelector('.b-barra .n')).color,
      texto:  getComputedStyle(document.body).color,
      fundo:  getComputedStyle(document.body).backgroundImage.slice(0, 48)
    }));
    medidas[tema] = m;
    const pior = Math.min(contraste(m.rotulo, 'rgb(12,25,37)'),
                          contraste(m.numero, 'rgb(12,25,37)'),
                          contraste(m.texto,  'rgb(12,25,37)'));
    ok(pior >= 4.5, `[${tema}] tudo se lê no escuro do salão (o pior é ${pior.toFixed(1)}:1)`);
  }
  const iguais = ['classico', 'azul', 'escuro'].every(t =>
    JSON.stringify(medidas[t]) === JSON.stringify(medidas.niras));
  ok(iguais, 'e a copa é exactamente a mesma nos quatro: o tema não lhe toca');
  await copa.evaluate(() => { try { localStorage.removeItem('tema'); } catch (e) {} });

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
      // Apagar a bebida leva a fotografia com ela; confere-se logo a seguir.
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });
  ok((await noivos.request.get(BASE + '/' + foto)).status() === 404,
     'apagar a bebida leva a fotografia do disco: não ficam órfãs em assets/bar/');
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
