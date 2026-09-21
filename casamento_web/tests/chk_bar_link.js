// Duas portas para o bar, e uma copa que diz que está fechada.
//
// PRIMEIRA PARTE — o link da festa.
//
// A única porta do menu era o código da MESA. Isso amarrava duas perguntas que
// não têm de andar juntas: de que festa se trata, e para onde vai a bebida.
// Sem uma folha pousada em cima de uma mesa não havia menu nenhum — e há
// convidados de pé no jardim, há quem esteja ao balcão, há a folha que se
// molha ou vai parar ao bolso de alguém, e há o próprio casal a querer ver a
// carta que mandou fazer.
//
// Cada casamento passa a ter o seu código. O da mesa continua a valer e
// continua a dizer a mesa — que é o que sempre fez de útil.
//
// SEGUNDA PARTE — a copa fechada.
//
// Com o bar fechado ou em pausa, a página desenhava o menu todo e punha um
// aviso POR CIMA. Escolher três bebidas para depois descobrir que não se pode
// pedir nada é a promessa que esta página existe para não fazer. Agora é o
// mesmo ecrã do «Este código não serve»: uma taça, uma frase, e mais nada —
// e a frase é a que o CASAL escreveu, não uma da casa a dizer o mesmo de
// outra maneira.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzk' + Math.floor(Math.random() * 1e5);
const RECADO = 'Abrimos as ' + (10 + Math.floor(Math.random() * 8)) + ' e um quarto, ' + marca;

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const ctx = await b.newContext({ viewport: { width: 390, height: 844 },
                                   isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  const post = (pg, a, c) => pg.evaluate(({ a, c }) => fetch('api.php?action=' + a,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c === undefined ? undefined : JSON.stringify(c) }).then(r => r.json()), { a, c });

  // Como estava a copa antes de lhe mexermos — para a deixar como estava.
  const antes = await p.evaluate(async () =>
    (await (await fetch('api.php?action=bar_estado')).json()));
  const estavaAberto = !!(antes.estado && antes.estado.aberto);

  // ---- 1. o casal vê o link da festa, e ele leva ao menu ----
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const link = await p.evaluate(async () => {
    barAba('mesas');
    await new Promise(r => setTimeout(r, 900));
    const el = document.getElementById('b-link-casa');
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { valor: el.value, visivel: r.width > 0 && r.height > 0,
             temBotao: !!document.querySelector('.b-lig .btn') };
  });
  ok(!!link, 'o casal tem o link da festa à vista, no Bar › Mesas e códigos');
  ok(link && /bebidas\.php\?c=[A-Z0-9]{6,16}$/.test(link.valor || ''),
     'e é um endereço da festa (?c=), não de uma mesa: ' + (link && link.valor));
  ok(link && link.visivel && link.temBotao, 'com um botão para o copiar');

  // ---- 2. com a copa FECHADA, não há menu nenhum ----
  if (estavaAberto) await post(p, 'bar_fechar');
  await post(p, 'bar_mensagens_guardar', { copa_fechada: RECADO });

  const gv = await b.newContext({ viewport: { width: 390, height: 844 },
                                  isMobile: true, hasTouch: true });
  const g = await gv.newPage();
  g.on('pageerror', e => errs.push('convidado: ' + e.message));
  await g.goto(link.valor, { waitUntil: 'networkidle' });
  const fechada = await g.evaluate(() => ({
    titulo: (document.querySelector('h1') || {}).textContent || '',
    texto: (document.querySelector('p') || {}).textContent || '',
    temMenu: !!document.getElementById('b-corpo'),
    temCesto: !!document.getElementById('b-rodape'),
    temTaca: !!document.querySelector('svg'),
  }));
  ok(/não está a servir|fechad/i.test(fechada.titulo),
     'com a copa fechada, o convidado lê que ela está fechada: «' + fechada.titulo + '»');
  ok(fechada.texto.includes(RECADO),
     'e lê o recado que o CASAL escreveu, não uma frase da casa: «' + fechada.texto.trim() + '»');
  ok(!fechada.temMenu && !fechada.temCesto,
     'e o menu não chega a ser desenhado — escolher para depois não se poder '
     + 'pedir é a promessa que isto existe para não fazer');
  ok(fechada.temTaca,
     'no mesmo ecrã do «Este código não serve»: a taça, uma frase, e mais nada');

  // O código da MESA diz o mesmo — é o mesmo bar, só outra porta.
  const umaMesa = await p.evaluate(() => (window.BAR_MESAS || [])[0]);
  ok(!!(umaMesa && umaMesa.token), 'há uma mesa com código para comparar');
  await g.goto(BASE + '/bebidas.php?m=' + umaMesa.token, { waitUntil: 'networkidle' });
  const fechadaMesa = await g.evaluate(() => ({
    titulo: (document.querySelector('h1') || {}).textContent || '',
    temMenu: !!document.getElementById('b-corpo') }));
  ok(/não está a servir|fechad/i.test(fechadaMesa.titulo) && !fechadaMesa.temMenu,
     'e pela porta da mesa diz-se exactamente o mesmo — é o mesmo bar');

  // ---- 2b. e com a copa EM PAUSA, o mesmo ----
  //
  // A pausa é o terceiro estado, e o único que se desfaz sozinho. Também não
  // tem menu: durante a pausa não se pode pedir, e um menu por baixo do aviso
  // convidava a escolher para depois recusar.
  await post(p, 'bar_abrir');
  await post(p, 'bar_mensagens_guardar', { copa_pausada: 'Estamos a repor, ' + marca });
  await post(p, 'bar_pausa', { minutos: 12 });
  await g.goto(link.valor, { waitUntil: 'networkidle' });
  const emPausa = await g.evaluate(() => ({
    titulo: (document.querySelector('h1') || {}).textContent || '',
    texto: (document.querySelector('p') || {}).textContent || '',
    conta: (document.getElementById('b-conta') || {}).textContent || '',
    temMenu: !!document.getElementById('b-corpo') }));
  ok(/pausa/i.test(emPausa.titulo) && !emPausa.temMenu,
     'com a copa em pausa, também não há menu: «' + emPausa.titulo + '»');
  ok(emPausa.texto.includes(marca),
     'e diz o recado do casal: «' + emPausa.texto.trim() + '»');
  ok(/minuto|^\d+:\d\d$/.test(emPausa.conta),
     'com o tempo que falta ao lado — quem espera quer saber se vale a pena: «'
     + emPausa.conta + '»');
  // A pausa desfaz-se SOZINHA. Sem contagem, a pessoa ficava a olhar para uma
  // frase parada sem saber que a copa já tinha reaberto.
  await g.waitForTimeout(3500);
  const andou = await g.evaluate(() =>
    (document.getElementById('b-conta') || {}).textContent || '');
  ok(/^\d+:\d\d$/.test(andou) && andou !== emPausa.conta,
     'e a contagem anda, para a página voltar ao menu por si quando chegar a '
     + 'zero: ' + emPausa.conta + ' → ' + andou);
  await post(p, 'bar_pausa', { levantar: 1 });
  await post(p, 'bar_mensagens_guardar', { copa_pausada: '' });
  await post(p, 'bar_fechar');

  // ---- 3. um código que não serve continua a dizê-lo ----
  await g.goto(BASE + '/bebidas.php?c=NAOEXISTE99', { waitUntil: 'networkidle' });
  const mau = await g.evaluate(() => (document.querySelector('h1') || {}).textContent || '');
  ok(/não serve/i.test(mau), 'um código inventado continua a não servir: «' + mau + '»');

  // ---- 4. com a copa ABERTA, o link da festa dá menu ----
  await post(p, 'bar_abrir');
  await g.goto(link.valor, { waitUntil: 'networkidle' });
  await g.waitForTimeout(2200);
  const aberta = await g.evaluate(() => ({
    temProcura: !!document.getElementById('b-q'),
    semMesa: window.BAR && window.BAR.mesa === null,
    casa: window.BAR ? window.BAR.casa : '',
    token: window.BAR ? window.BAR.token : 'x',
  }));
  ok(aberta.temProcura,
     'com a copa aberta, quem entra pelo link da festa chega ao «Quem está a pedir?»');
  ok(aberta.semMesa && aberta.token === '',
     'sem mesa nenhuma — não há folha pousada em cima de mesa nenhuma');
  ok(/^[A-Z0-9]{6,16}$/.test(aberta.casa || ''),
     'e com o código da festa na mão, que é o que assina os pedidos');

  // ---- 5. e pede-se de verdade por esta porta ----
  //
  // Esta é a parte que interessa: não basta o menu abrir. Um convidado que
  // entre pelo link da festa tem de conseguir chegar ao fim e pedir — senão
  // trocou-se uma porta fechada por uma antecâmara.
  const NOME = 'ZZK Convidado ' + marca;
  const feito = await p.evaluate(async ({ n, m }) => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await post('mesa_save', { nome: 'ZZK Mesa ' + m, capacidade: 6,
                                         forma: 'redonda', cor: 'neutra' });
    const mesa = (dm.mesas || []).filter(x => x.nome === 'ZZK Mesa ' + m).pop();
    const dc = await post('convite_save', { nome_exibicao: n, membros: [{ nome: n }] });
    const di = await post('bar_item_guardar', { nome: 'ZZK Água ' + m, servir: 'copo',
                                                max_por_pedido: 5 });
    if (di.id) await post('bar_stock_repor', { item_id: +di.id, quantidade: 40, nota: 'prova' });
    return { mesa: mesa ? +mesa.id : 0,
             convite: dc.convite ? +dc.convite.id : 0,
             item: +di.id || 0 };
  }, { n: NOME, m: marca });
  ok(feito.mesa && feito.convite && feito.item,
     'preparou uma mesa, um convidado e uma bebida de prova');

  await g.goto(link.valor, { waitUntil: 'networkidle' });
  await g.waitForTimeout(1400);
  await g.fill('#b-q', NOME);
  await g.waitForTimeout(1200);
  const nomes = await g.locator('.b-nome').count();
  ok(nomes > 0, 'pelo link da festa chega-se à lista de nomes: ' + nomes);
  await g.locator('.b-nome').first().click();
  await g.waitForTimeout(1800);

  const noMenu = await g.evaluate(() => {
    const bm = document.getElementById('b-mesa');
    return { temEscolhaDeMesa: !!(bm && !bm.hidden && bm.querySelector('.lic-sel')),
             rotulo: bm ? bm.textContent.replace(/\s+/g, ' ').trim().slice(0, 44) : '' };
  });
  ok(noMenu.temEscolhaDeMesa,
     'e depois de se identificar, a mesa é dela a escolher — é a peça que o '
     + 'código da mesa dava de graça: «' + noMenu.rotulo + '»');

  // Sem mesa, o servidor pergunta-a em vez de mandar o pedido para a copa com
  // o destino em branco e o entregador a andar pelo salão.
  const pedir = (mesaId) => g.evaluate(async ({ i, m, c }) => {
    const r = await fetch('api.php?action=bar_pedir&c=' + c, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ c: c, itens: [{ item_id: i, quantidade: 1, unidade: 'copo' }],
                             mesa_id: m, mesa_qr_id: 0 }) });
    return r.json().catch(() => ({ success: false }));
  }, { i: feito.item, m: mesaId, c: aberta.casa });

  const semMesa = await pedir(0);
  ok(semMesa.success === false && /mesa/i.test(semMesa.message || ''),
     'pedir sem dizer a mesa leva com uma pergunta, e não com um pedido às '
     + 'cegas: «' + (semMesa.message || '?') + '»');

  const comMesa = await pedir(feito.mesa);
  ok(comMesa.success === true,
     'e com a mesa escolhida o pedido passa — a porta nova serve para pedir, '
     + 'não só para espreitar: ' + (comMesa.success ? 'pedido' : (comMesa.message || '?')));

  // ---- arrumar: a copa fica como estava, e a prova não deixa nada ----
  await post(p, 'bar_mensagens_guardar', { copa_fechada: '' });
  if (!estavaAberto) await post(p, 'bar_fechar');
  await p.evaluate(async x => {
    const post = (a) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => {});
    if (x.item) await post('bar_item_apagar&id=' + x.item);
    if (x.convite) await post('convite_delete&id=' + x.convite);
    if (x.mesa) await post('mesa_delete&id=' + x.mesa);
  }, feito);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
