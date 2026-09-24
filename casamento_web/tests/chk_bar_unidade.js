// Copo ou garrafa, à vista dos três que precisam de saber.
//
// A casa já sabia servir uma bebida ao copo, à garrafa, ou das duas maneiras.
// O que faltava era DIZÊ-LO. O convidado olhava para «Whisky» e não sabia se
// lhe traziam um copo ou a garrafa inteira — descobria-o com o tabuleiro à
// frente. A copa lia «2× Tinto» e não sabia o que pôr no tabuleiro. O garçom
// lia o mesmo e, na dúvida, voltava atrás a perguntar, que é fazer a mesa
// esperar duas vezes.
//
// A unidade dizia-se, sim, mas só quando era GARRAFA. O copo era o silêncio —
// e um silêncio só se lê bem se a pessoa souber que o silêncio significa
// alguma coisa. Quem não sabe, adivinha.
//
// E quando a bebida admite as duas, o convidado passa a ESCOLHER, com o copo
// marcado de origem. Antes eram dois pares de botões iguais, um por baixo do
// outro, cada um com o seu rótulo pequeno: uma pergunta implícita («qual
// destes é o meu?») em vez de uma resposta que se pode mudar. O copo é a
// dose, é o que a maior parte das pessoas quer, e uma garrafa escolhida por
// engano é um engano caro.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { limparBar } = require('./limpar-bar');
const marca = 'zzu' + Math.floor(Math.random() * 1e5);

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push('noivos: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  const post = (acao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) });
    return r.json().catch(() => ({ success: false }));
  }, { a: acao, c: corpo });

  // ---- o mundo desta prova: três bebidas, uma por cada maneira de servir ----
  const soCopo = await post('bar_item_guardar',
    { nome: 'ZZU Whisky ' + marca, alcoolico: 1, servir: 'copo', max_por_pedido: 20 });
  const ambos = await post('bar_item_guardar',
    { nome: 'ZZU Tinto ' + marca, alcoolico: 1, servir: 'ambos',
      doses_garrafa: 6, max_por_pedido: 20 });
  const soGarrafa = await post('bar_item_guardar',
    { nome: 'ZZU Espumante ' + marca, alcoolico: 1, servir: 'garrafa',
      doses_garrafa: 6, max_por_pedido: 20 });
  ok(soCopo.success && ambos.success && soGarrafa.success,
     'três bebidas de prova: uma só ao copo, uma das duas maneiras, uma só à garrafa');
  for (const d of [soCopo, ambos, soGarrafa]) {
    await post('bar_stock_repor', { item_id: d.id, quantidade: 80, nota: 'prova' });
  }

  // Alguém a pedir, e uma mesa onde entregar.
  const cena = await p.evaluate(async m => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await post('mesa_save', { nome: 'ZZU Mesa ' + m, capacidade: 6,
                                         forma: 'redonda', cor: 'neutra' });
    const mesa = (dm.mesas || []).filter(x => x.nome === 'ZZU Mesa ' + m).pop();
    const dc = await post('convite_save', { nome_exibicao: 'ZZU Provador ' + m,
      mesa: mesa ? String(mesa.id) : '', membros: [{ nome: 'ZZU Provador ' + m }] });
    return { mesa: mesa ? +mesa.id : 0, convite: dc.convite ? +dc.convite.id : 0 };
  }, marca);
  ok(cena.mesa && cena.convite, 'e alguém sentado a uma mesa, para haver quem peça');

  const estadoAntes = await p.evaluate(async () =>
    (await (await fetch('api.php?action=bar_estado')).json()));
  const estavaAberto = !!(estadoAntes.estado && estadoAntes.estado.aberto);
  if (!estavaAberto) await post('bar_abrir');

  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const token = await p.evaluate(m => ((window.BAR_MESAS || [])
                                       .find(x => +x.id === +m) || {}).token, cena.mesa);

  // ============ 1. o convidado ============
  const salao = await b.newContext({ viewport: { width: 390, height: 844 },
                                     isMobile: true, hasTouch: true });
  const conv = await salao.newPage();
  conv.on('pageerror', e => errs.push('convidado: ' + e.message));
  await conv.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(1200);
  await conv.fill('#b-q', 'ZZU Provador');
  await conv.waitForTimeout(1100);
  await conv.locator('.b-nome').first().click();
  await conv.waitForTimeout(1800);

  const ler = id => conv.evaluate(i => {
    const c = document.getElementById('bb-' + i);
    if (!c) return null;
    const s = c.querySelector('.b-serve');
    const bts = [...c.querySelectorAll('.b-un-bt')];
    return {
      serve: s ? s.textContent.replace(/\s+/g, ' ').trim() : '',
      visivel: !!(s && s.getClientRects().length),
      escolhas: bts.map(x => ({ rot: x.textContent.trim(),
                                on: x.classList.contains('on'),
                                alto: Math.round(x.getBoundingClientRect().height) })),
      contadores: c.querySelectorAll('.b-mais').length,
    };
  }, id);

  const vCopo = await ler(soCopo.id);
  ok(vCopo && /só ao copo/i.test(vCopo.serve) && vCopo.visivel,
     'uma bebida que só sai ao copo diz-o no cartão, antes de ser pedida: «'
     + (vCopo && vCopo.serve) + '»');
  ok(vCopo && vCopo.escolhas.length === 0 && vCopo.contadores === 1,
     'e não pergunta nada — não há escolha nenhuma a fazer');

  const vGarrafa = await ler(soGarrafa.id);
  ok(vGarrafa && /só à garrafa/i.test(vGarrafa.serve),
     'uma que só sai à garrafa diz isso, e não fica calada a fingir que é um '
     + 'copo: «' + (vGarrafa && vGarrafa.serve) + '»');

  // ---- e A GARRAFA PEDE-SE ----
  //
  // Não se pedia. O «máximo por pedido» e o stock estavam somados no mesmo
  // número, e são contas de unidades diferentes: o stock mede-se em DOSES
  // (uma garrafa de seis gasta seis) e o máximo por pedido conta ARTIGOS
  // (uma garrafa é uma coisa pedida). O máximo nasce em dois; seis nunca é
  // menor ou igual a dois. O «+» da garrafa nascia desactivado em toda a
  // casa, com a carta acabada de montar e nada por configurar — e o
  // servidor recusava o pedido que lá chegasse por outra via.
  //
  // Esta bebida é de propósito a mais desfavorável que há: seis doses por
  // garrafa contra o máximo por pedido DE ORIGEM.
  const soGarrafaPadrao = await post('bar_item_guardar',
    { nome: 'ZZU Champanhe ' + marca, alcoolico: 1, servir: 'garrafa', doses_garrafa: 6 });
  await post('bar_stock_repor', { item_id: soGarrafaPadrao.id, quantidade: 60, nota: 'prova' });
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(2200);
  const podeGarrafa = await conv.evaluate(async id => {
    const c = document.getElementById('bb-' + id);
    if (!c) return { existe: false };
    const up = c.querySelector('.b-mais .up');
    const antes = !!(up && up.disabled);
    barMais(id, 'garrafa');
    await new Promise(r => setTimeout(r, 600));
    const c2 = document.getElementById('bb-' + id);
    return { existe: true, desactivado: antes,
             conta: (c2.querySelector('.b-mais .v') || {}).textContent,
             cesto: !document.getElementById('b-rodape').hidden };
  }, soGarrafaPadrao.id);
  ok(podeGarrafa.existe && !podeGarrafa.desactivado,
     'o «+» de uma bebida só à garrafa NÃO nasce desactivado — nascia, e com '
     + 'ele nenhuma garrafa desta casa era pedível');
  ok(podeGarrafa.conta === '1' && podeGarrafa.cesto,
     'e carregar nele põe a garrafa no cesto: ' + podeGarrafa.conta);

  const pedidoGarrafa = await conv.evaluate(async () => {
    document.getElementById('b-pedir').click();
    await new Promise(r => setTimeout(r, 2400));
    const e = document.querySelector('.b-erro');
    const meu = document.querySelector('.b-meu .oq');
    return { erro: e ? e.textContent.trim() : '',
             meu: meu ? meu.textContent.replace(/\s+/g, ' ').trim() : '' };
  });
  ok(!pedidoGarrafa.erro && /garrafa/i.test(pedidoGarrafa.meu),
     'e o pedido passa no servidor, que fazia a mesma conta errada: «'
     + (pedidoGarrafa.erro || pedidoGarrafa.meu) + '»');

  // O stock continua a contar-se em doses: sem copos que cheguem para uma
  // garrafa inteira, ela não se pede. A trava certa é esta, e é a que fica.
  //
  // A bebida é de propósito das que saem DAS DUAS MANEIRAS: só aí é que
  // «copos por garrafa» quer dizer alguma coisa. Numa que só sai à garrafa o
  // campo não se aplica — o stock dessas são garrafas, e uma garrafa custa
  // uma —, e o servidor anula-o ao guardar.
  const semStock = await post('bar_item_guardar',
    { nome: 'ZZU Pouco ' + marca, servir: 'ambos', doses_garrafa: 12 });
  await post('bar_stock_repor', { item_id: semStock.id, quantidade: 4, nota: 'prova' });
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(2200);
  const pouco = await conv.evaluate(async id => {
    // Sai das duas maneiras, e o copo é o de origem: é preciso pedir a
    // garrafa para se ver o que a garrafa faz. Ao copo esta serve-se à mesma,
    // que é precisamente o que a frase abaixo tem de dizer.
    barUnidade(id, 'garrafa');
    await new Promise(r => setTimeout(r, 700));
    const c = document.getElementById('bb-' + id);
    if (!c) return null;
    const up = c.querySelector('.b-mais .up');
    return { travado: !up || up.disabled,
             recado: (c.querySelector('.qtd') || {}).textContent || '' };
  }, semStock.id);
  ok(pouco && pouco.travado,
     'mas quatro copos não dão uma garrafa de doze, e essa continua travada — '
     + 'a conta das doses é a que protege o stock, e não se perdeu');
  // E DIZ PORQUÊ. Um «+» desactivado sem uma palavra lê-se como avaria, e foi
  // assim que isto voltou duas vezes: «o ícone + parece desabilitado». A
  // bebida não está esgotada — há copos —, só não há garrafa inteira, e essa
  // diferença é tudo o que a pessoa precisa de saber.
  ok(pouco && /sem garrafas/i.test(pouco.recado || ''),
     'e diz porquê, em vez de deixar um botão morto: «' + (pouco && pouco.recado) + '»');

  const vAmbos = await ler(ambos.id);
  ok(vAmbos && vAmbos.escolhas.length === 2,
     'uma que sai das duas maneiras dá a ESCOLHER, em vez de dois pares de '
     + 'botões iguais: ' + (vAmbos ? vAmbos.escolhas.map(x => x.rot).join(' / ') : '—'));
  const marcada = vAmbos && vAmbos.escolhas.filter(x => x.on);
  ok(marcada && marcada.length === 1 && /copo/i.test(marcada[0].rot),
     'com o COPO marcado de origem — uma garrafa escolhida por engano é um '
     + 'engano caro: «' + (marcada && marcada[0] ? marcada[0].rot : '—') + '»');
  ok(vAmbos && vAmbos.escolhas.every(x => x.alto >= 32),
     'e as duas do tamanho de um alvo que se toca de pé: '
     + (vAmbos ? vAmbos.escolhas.map(x => x.alto + 'px').join(', ') : '—'));
  ok(vAmbos && vAmbos.contadores === 1,
     'um contador só, o da unidade escolhida: ' + (vAmbos && vAmbos.contadores));

  // ---- a escolha muda, e o que se pede muda com ela ----
  await conv.evaluate(i => barUnidade(i, 'garrafa'), ambos.id);
  await conv.waitForTimeout(700);
  const depois = await ler(ambos.id);
  const agora = depois && depois.escolhas.filter(x => x.on);
  ok(agora && agora.length === 1 && /garrafa/i.test(agora[0].rot),
     'carregar em «garrafa» passa a escolha para lá: «'
     + (agora && agora[0] ? agora[0].rot : '—') + '»');

  await conv.evaluate(i => barMais(i, 'garrafa'), ambos.id);
  await conv.waitForTimeout(600);
  await conv.evaluate(i => { barUnidade(i, 'copo'); barMais(i, 'copo'); }, ambos.id);
  await conv.waitForTimeout(700);
  const cesto = await conv.evaluate(i => {
    const c = document.getElementById('bb-' + i);
    return { noCesto: c.classList.contains('no-cesto'),
             aVista: (c.querySelector('.b-mais .v') || {}).textContent };
  }, ambos.id);
  ok(cesto.noCesto,
     'com uma garrafa E um copo no cesto, o cartão continua a dizer que lá tem '
     + 'alguma coisa, ainda que só uma das unidades esteja à vista');
  ok(cesto.aVista === '1', 'e o contador mostra o da unidade escolhida: ' + cesto.aVista);

  // ---- e o pedido sai com as duas linhas ----
  await conv.click('#b-pedir');
  await conv.waitForTimeout(2200);
  const meu = await conv.evaluate(() => {
    const el = document.querySelector('.b-meu .oq');
    return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
  });
  ok(/garrafa/i.test(meu) && /copo/i.test(meu),
     'e «os meus pedidos» diz de cada linha se é copo ou garrafa — é aqui que '
     + 'se confere, com o «Desistir» ainda ao lado: «' + meu + '»');

  // ============ 2. a copa ============
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2400);
  const naCopa = await p.evaluate(m => {
    const linhas = [...document.querySelectorAll('.b-linha')]
      .map(x => x.textContent.replace(/\s+/g, ' ').trim())
      .filter(t => t.includes(m));
    return { linhas,
             unidades: [...document.querySelectorAll('.b-linha .b-un')]
                         .map(x => x.textContent.trim()) };
  }, marca);
  ok(naCopa.linhas.length >= 2,
     'o pedido chega à copa com as duas linhas: ' + naCopa.linhas.join(' | '));
  ok(naCopa.linhas.some(t => /copo/i.test(t)) && naCopa.linhas.some(t => /garrafa/i.test(t)),
     'e cada uma diz o que pôr no tabuleiro — o copo também, que era o que '
     + 'ficava por dizer');

  // ============ 3. as entregas ============
  // TODOS os pedidos desta prova, e não só o primeiro: ela faz dois (a
  // garrafa sozinha, e o copo mais a garrafa do tinto), e é preciso que as
  // duas unidades cheguem ao ecrã de quem entrega para haver o que comparar.
  const aprovados = await p.evaluate(async m => {
    const feitos = [];
    for (let volta = 0; volta < 4; volta++) {
      const d = await (await fetch('api.php?action=bar_estado')).json();
      const pend = (d.fila || []).filter(x => x.estado === 'em_analise'
        && (x.itens || []).some(l => (l.nome || '').includes(m)));
      if (!pend.length) break;
      for (const x of pend) {
        const r = await fetch('api.php?action=bar_decidir', { method: 'POST',
          headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: x.id, decisao: 'aprovar' }) });
        if ((await r.json()).success) feitos.push(x.id);
      }
    }
    return feitos;
  }, marca);
  ok(aprovados.length >= 2,
     'a copa aprova-os, para chegarem a quem entrega: ' + aprovados.join(', '));

  await p.goto(BASE + '/entregas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2400);
  const naEntrega = await p.evaluate(m => [...document.querySelectorAll('.b-linha')]
    .map(x => x.textContent.replace(/\s+/g, ' ').trim())
    .filter(t => t.includes(m)), marca);
  ok(naEntrega.length >= 2
     && naEntrega.some(t => /copo/i.test(t)) && naEntrega.some(t => /garrafa/i.test(t)),
     'e o garçom lê a unidade em cada linha antes de carregar o tabuleiro: '
     + naEntrega.join(' | '));

  // ============ 4. e lê-se nos quatro temas ============
  // Isto vive no ecrã do convidado, que veste o convite do CASAL e não os
  // temas da casa — mas as JANELAS dele são cartões da casa, e a escolha da
  // unidade tem de se ler com qualquer um deles por baixo.
  const lum = c => { const f = v => { v /= 255;
      return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); };
    return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); };
  const razao = (a, c) => { const l1 = lum(a), l2 = lum(c);
    return (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05); };
  const maus = [];
  for (const tema of ['niras', 'classico', 'azul', 'escuro']) {
    const cores = await conv.evaluate(async ({ t, i }) => {
      document.documentElement.setAttribute('data-tema', t);
      await new Promise(r => setTimeout(r, 400));
      const c = document.getElementById('bb-' + i);
      const on = c && c.querySelector('.b-un-bt.on');
      const off = c && c.querySelector('.b-un-bt:not(.on)');
      if (!on || !off) return null;
      const n = s => (s.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);
      const fundoDe = el => { let bg = 'rgba(0, 0, 0, 0)', x = el;
        while (x && /rgba\(0, 0, 0, 0\)|transparent/.test(bg)) {
          bg = getComputedStyle(x).backgroundColor; x = x.parentElement; }
        return n(bg); };
      return { onT: n(getComputedStyle(on).color), onF: fundoDe(on),
               offT: n(getComputedStyle(off).color), offF: fundoDe(off) };
    }, { t: tema, i: ambos.id });
    if (!cores) { maus.push(tema + ': não se encontrou a escolha'); continue; }
    const a = razao(cores.onT, cores.onF), c = razao(cores.offT, cores.offF);
    console.log('   ' + tema.padEnd(9) + 'escolhida ' + a.toFixed(2)
                + ':1 · a outra ' + c.toFixed(2) + ':1');
    if (a < 4.5) maus.push(tema + ' escolhida ' + a.toFixed(2) + ':1');
    if (c < 4.5) maus.push(tema + ' a outra ' + c.toFixed(2) + ':1');
  }
  ok(maus.length === 0,
     'as duas metades da escolha lêem-se nos quatro temas: '
     + (maus.join(', ') || 'todas acima de 4,5:1'));

  // ---- arrumar ----
  // Primeiro os PEDIDOS, depois as bebidas: uma bebida com pedidos por
  // entregar não se apaga (e faz bem — o pedido ficaria com uma linha sem
  // nome). Esta prova pede de propósito, por isso tem sempre de os fechar.
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const ficaram = await limparBar(p, { itens: [soCopo.id, ambos.id, soGarrafa.id,
                                              soGarrafaPadrao.id, semStock.id], marca });
  ok(ficaram.length === 0,
     'a prova não deixa bebidas atrás de si — uma que fique aparece na carta da '
     + 'corrida seguinte e faz falhar outra prova: ' + (ficaram.join(' | ') || 'nada'));
  await p.evaluate(async x => {
    const g = a => fetch('api.php?action=' + a,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => null);
    if (x.convite) await g('convite_delete&id=' + x.convite + '&definitivo=1');
    if (x.mesa) await g('mesa_delete&id=' + x.mesa);
  }, { convite: cena.convite, mesa: cena.mesa });
  if (!estavaAberto) await post('bar_fechar');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
