// montra.js — refaz as capturas de ecrã que a montra dos planos mostra.
//
// NÃO é uma prova (o correr.js não lhe pega). É a ferramenta que se corre
// quando o produto muda de cara: as imagens de assets/montra/ são capturas do
// produto a sério, e uma montra que mostra o ecrã de há seis meses vende uma
// coisa que já não existe.
//
// O que faz, por esta ordem:
//
//   1. semeia o casamento de demonstração com uma festa a sério — convidados,
//      mesas, orçamento, entradas — porque um ecrã vazio não vende nada;
//   2. abre cada página com uma conta de NOIVOS (criada aqui, apagada no fim),
//      e não com a do admin: senão a tira amarela da visita de suporte entrava
//      em todas as capturas;
//   3. corta o miolo de cada página — o cabeçalho fica de fora, como nas
//      capturas de origem: a montra vende o módulo, não a moldura;
//   4. escreve os JPEG por cima dos que lá estão.
//
// Uso:  node montra.js            (todas)
//       node montra.js orcamento  (só uma)
//
// Depois de correr, OLHE para as imagens. Uma captura tremida ou cortada a
// meio de uma linha é pior do que a antiga.
const { chromium } = require('playwright-core');
const fs = require('fs'), path = require('path');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const DEST = path.join(__dirname, '..', 'assets', 'montra');

const ADMIN = { u: 'admin', s: 'noivos2026' };
const NOIVOS = { email: 'montra@exemplo.local', senha: 'Montra-2026-Prova' };

// As medidas das capturas de origem. Mantêm-se: a montra desenha-as com
// proporções que já estão escolhidas, e mudá-las mexe no desenho da página.
const MEDIDAS = {
  convidados: { w: 1100, h: 697 },
  mesas:      { w: 1100, h: 697 },
  orcamento:  { w: 1100, h: 697 },
  porta:      { w: 1100, h: 1102 },
  impresso:   { w: 620,  h: 930 },
  digital:    { w: 560,  h: 1211 },
};

const so = process.argv[2] || '';
const quero = (k) => !so || k === so;

const api = (p, a, c) => p.evaluate(async ({ a, c }) => {
  const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
    headers: Object.assign({ 'X-CSRF-Token': window.CSRF || '' },
                           c ? { 'Content-Type': 'application/json' } : {}),
    body: c ? JSON.stringify(c) : undefined });
  try { return await r.json(); } catch (e) { return { success: false }; }
}, { a, c });

async function entrar(p, u, s) {
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', u);
  await p.fill('input[name=senha]', s);
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
}

/**
 * Uma festa a sério no casamento de demonstração.
 *
 * Os números são inventados mas plausíveis, e é essa a única exigência: quem
 * olha para a montra tem de reconhecer o seu próprio casamento no ecrã. Não se
 * apaga nada do que já lá esteja — semeia-se por cima, e o que já existir com
 * o mesmo nome fica como está.
 */
async function semear(p) {
  console.log('  a semear a festa…');
  const mesas = [
    ['Amigos', 10, 'redonda', 28, 30], ['Colegas', 12, 'redonda', 62, 30],
    ['Família da noiva', 10, 'comprida', 28, 62], ['Família do noivo', 8, 'comprida', 62, 62],
    ['Padrinhos', 6, 'redonda', 45, 78],
  ];
  const convites = [
    ['Amigos do Nelson', 'Amigos',           ['Beatriz', 'Joana', 'Nelson'],           'digital', 'confirmado'],
    ['Colegas do escritório', 'Colegas',      ['Bruno', 'Alda', 'Rui', 'Sofia'],        'digital', 'confirmado'],
    ['Família Bengui', 'Amigos',              ['Inês', 'Nelson B.', 'Tiago'],           'ambos',   'pendente'],
    ['Família Cardoso', 'Colegas',            ['Ana', 'Marta', 'Paulo', 'Rita'],        'fisico',  'recusado'],
    ['Tios da noiva', 'Família da noiva',     ['Lurdes', 'Alberto', 'Cátia'],           'digital', 'confirmado'],
    ['Avós', 'Família da noiva',              ['Amélia', 'Joaquim'],                    'fisico',  'pendente'],
    ['Primos do noivo', 'Família do noivo',   ['Hélder', 'Cláudia', 'Nuno'],            'digital', 'pendente'],
    ['Padrinhos de batismo', 'Padrinhos',     ['Teresa', 'Miguel'],                     'ambos',   'confirmado'],
    ['Vizinhos da quinta', 'Colegas',         ['Fernanda', 'José', 'Carla', 'Duarte'],  'digital', 'pendente'],
  ];
  const categorias = [
    ['Espaço e decoração', '#2E86C8'], ['Buffet', '#B4864A'], ['Traje', '#A5473F'],
    ['Fotografia', '#7A5CA8'], ['Música', '#2F9E8F'], ['Convites', '#C98A2E'],
  ];
  // A quinta coluna são as parcelas: [valor, dias a contar de hoje]. As
  // negativas já venceram — é o que faz o cartão «Em atraso» contar alguma
  // coisa, e uma montra que mostra a função apagada não a vende.
  const despesas = [
    ['Quinta das Acácias · aluguer', 1400000, 'pago',     'Espaço e decoração', []],
    ['Arranjos florais',              380000, 'previsto', 'Espaço e decoração', [[180000, -6], [200000, 24]]],
    ['Menu para 120 pessoas',        1900000, 'pago',     'Buffet', []],
    ['Bolo dos noivos',               240000, 'previsto', 'Buffet', [[240000, 41]]],
    ['Vestido e alterações',          620000, 'pago',     'Traje', []],
    ['Fato do noivo',                 340000, 'pago',     'Traje', []],
    ['Reportagem de fotografia',      700000, 'pago',     'Fotografia', []],
    ['DJ e som',                      380000, 'previsto', 'Música', [[190000, 12], [190000, 55]]],
    ['Impressão dos convites',        150000, 'previsto', 'Convites', [[150000, -2]]],
  ];

  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const feito = await p.evaluate(async (d) => {
    const chamar = async (a, c) => {
      const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
        headers: Object.assign({ 'X-CSRF-Token': window.CSRF },
                               c ? { 'Content-Type': 'application/json' } : {}),
        body: c ? JSON.stringify(c) : undefined });
      try { return await r.json(); } catch (e) { return { success: false }; }
    };
    const conta = { mesas: 0, convites: 0, despesas: 0, parcelas: 0, entradas: 0 };

    // ---- as mesas ----
    const jaMesas = ((await chamar('mesa_list')).mesas || []).map(m => m.nome);
    for (const [nome, capacidade, forma, pos_x, pos_y] of d.mesas) {
      if (jaMesas.includes(nome)) continue;
      await chamar('mesa_save', { nome, capacidade, forma, pos_x, pos_y, cor: 'verde' });
      conta.mesas++;
    }

    // ---- os convites, com as pessoas lá dentro ----
    const jaConv = ((await chamar('convite_list')).convites || []).map(c => c.nome_exibicao);
    const ids = [];
    for (const [nome, mesa, membros, tipo, estado] of d.convites) {
      if (jaConv.includes(nome)) continue;
      const r = await chamar('convite_save', {
        nome_exibicao: nome, mesa, tipo, presenca: estado,
        lado: /noiva|Amigos/.test(mesa) ? 'noiva' : 'noivo',
        membros: membros.map(x => ({ nome: x, vai: estado === 'confirmado' })) });
      if (!r || !r.success || !r.convite) continue;
      conta.convites++;
      if (estado === 'confirmado') ids.push(r.convite.id);
    }

    // ---- o orçamento ----
    await chamar('orc_ajuste', { total: 7200000, moeda: 'Kz' });
    const est = await chamar('orc_estado');
    const jaCat = {}; (est.categorias || []).forEach(c => { jaCat[c.nome] = c.id; });
    for (const [nome, cor] of d.categorias) {
      if (jaCat[nome]) continue;
      const r = await chamar('orc_categoria_guardar', { nome, cor });
      if (r && r.success) jaCat[nome] = r.id;
    }
    const jaDesp = (est.despesas || []).map(x => x.descricao);
    const iso = n => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
    for (const [descricao, valor, estado, cat, parcelas] of d.despesas) {
      if (jaDesp.includes(descricao)) continue;
      const r = await chamar('orc_despesa_guardar',
                   { descricao, valor, estado, categoria_id: jaCat[cat] || null });
      conta.despesas++;
      // As parcelas: é o que enche o calendário, e é uma delas, já vencida,
      // que dá ao cartão «Em atraso» um número em vez de um zero apagado.
      if (r && r.success && r.id) for (const [v, dias] of (parcelas || [])) {
        await chamar('orc_pagamento_guardar',
                     { despesa_id: r.id, valor: v, data_prevista: iso(dias) });
        conta.parcelas++;
      }
    }

    // ---- a porta: alguns já entraram ----
    // É o que faz o ecrã do porteiro valer a captura: vazio, mostra três zeros.
    for (const convite_id of ids.slice(0, 3)) {
      const r = await chamar('porta_checkin', { convite_id, modo: 'todos' });
      if (r && r.success) conta.entradas++;
    }
    return conta;
  }, { mesas, convites, categorias, despesas });
  console.log('  ', JSON.stringify(feito));
}

/**
 * Tira do ecrã o que não é produto.
 *
 * A pastilha do tema é uma escolha de quem está a ver, e a tira da visita de
 * suporte é do admin: nenhuma das duas existe para o casal que vai comprar, e
 * na captura ficavam a prometer coisas que não são do módulo.
 */
async function limpar(p) {
  await p.evaluate(() => {
    document.querySelectorAll('.tema-fab, .tira-suporte, #at-bolha, .at-bolha,'
      // No convite, os dois botões flutuantes: descarregar e o som. São do
      // convidado, não do módulo — e numa miniatura leem-se como sujidade.
      + ' #dlBtn, #audioBtn').forEach(e => e.remove());
  });
}

/** Corta o miolo da página (sem o cabeçalho) para a medida pedida. */
async function capturar(p, nome, seletor) {
  const { w, h } = MEDIDAS[nome];
  const cx = await p.$(seletor);
  if (!cx) { console.log('  ! ' + nome + ': não achei ' + seletor); return; }
  await limpar(p);
  const cx2 = await cx.boundingBox();
  // Corta-se a partir do canto do miolo, mas nunca para fora da janela: uma
  // captura que passe a borda sai com uma faixa preta.
  const janela = await p.evaluate(() => ({ w: innerWidth, h: innerHeight }));
  const x = Math.max(0, Math.min(Math.round(cx2.x), janela.w - w));
  const y = Math.max(0, Math.round(cx2.y));
  const dest = path.join(DEST, nome + '.jpg');
  await p.screenshot({ path: dest, type: 'jpeg', quality: 82,
    clip: { x, y, width: Math.min(w, janela.w - x),
            height: Math.min(h, janela.h - y, Math.round(cx2.height)) } });
  console.log('  →', path.relative(process.cwd(), dest),
              Math.round(fs.statSync(dest).size / 1024) + ' KB');
}

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const admin = await (await b.newContext()).newPage();
  await entrar(admin, ADMIN.u, ADMIN.s);
  await api(admin, 'casamento_abrir&id=1', {});
  await semear(admin);

  // Uma conta de noivos, só para as capturas: com a do admin, a tira amarela
  // da visita de suporte entrava em todas elas.
  console.log('  a criar a conta de noivos das capturas…');
  const conta = await api(admin, 'utilizador_criar',
    { email: NOIVOS.email, nome: 'Montra', senha: NOIVOS.senha,
      casamento_id: 1, papel: 'noivos' });
  if (!conta.success && !/já existe/i.test(conta.message || '')) {
    console.log('  ! não consegui criar a conta:', conta.message);
  }

  // A janela mede o que a captura mede: assim o miolo cabe inteiro, com a
  // margem que tem à volta, e não se corta a coluna da direita.
  const ctx = await b.newContext({ viewport: { width: 1100, height: 1500 },
                                   deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await entrar(p, NOIVOS.email, NOIVOS.senha);

  const paginas = [
    ['convidados', '/index.php',     '.container'],
    ['mesas',      '/mesas.php',     '.container'],
    ['orcamento',  '/orcamento.php', '.container'],
  ];
  for (const [nome, url, sel] of paginas) {
    if (!quero(nome)) continue;
    console.log(nome);
    await p.goto(BASE + url, { waitUntil: 'networkidle' });
    await p.waitForTimeout(2200);
    await capturar(p, nome, sel);
  }

  // ---- a porta: é o posto do porteiro, e a conta é outra ----
  if (quero('porta')) {
    console.log('porta');
    // Metade da largura ao dobro da escala: é um posto de telemóvel, e a
    // captura tem de se ler como tal. E na aba de quem JÁ entrou — a de
    // registar mostra um botão e uma caixa de procura, que não conta nada.
    const pt = await (await b.newContext({ viewport: { width: 550, height: 780 },
                                           deviceScaleFactor: 2 })).newPage();
    await entrar(pt, 'porteiro@local', 'noivos2026');
    await pt.goto(BASE + '/porteiro.php', { waitUntil: 'networkidle' });
    await pt.waitForTimeout(1800);
    await pt.evaluate(() => mudarAba('ent'));
    await pt.waitForTimeout(1200);
    await limpar(pt);
    const bbp = await (await pt.$('.contentor')).boundingBox();
    await pt.screenshot({ path: path.join(DEST, 'porta.jpg'), type: 'jpeg', quality: 82,
      clip: { x: 0, y: Math.round(bbp.y), width: 550,
              height: Math.min(551, 780 - Math.round(bbp.y)) } });
    console.log('  → assets/montra/porta.jpg');
  }

  // ---- as duas peças: mostram-se como o convidado as vê ----
  if (quero('digital')) {
    console.log('digital');
    // O convite de um convidado a sério, e ABERTO: a capa fechada é bonita mas
    // não mostra nada do que se está a vender — a contagem, a mesa, o pedido de
    // confirmação. Toca-se no envelope e espera-se que ele acabe de abrir.
    const cod = await api(admin, 'convite_list').then(d =>
      ((d.convites || []).find(c => /Amigos do Nelson/.test(c.nome_exibicao)) || {}).codigo);
    const dg = await (await b.newContext({ viewport: { width: 560, height: 1240 } })).newPage();
    await dg.goto(BASE + '/convite-digital.php?c=' + (cod || ''), { waitUntil: 'networkidle' });
    await dg.waitForTimeout(1800);
    await dg.evaluate(() => { const c = document.getElementById('cover'); if (c) c.click(); });
    await dg.waitForTimeout(2600);
    await limpar(dg);
    await dg.screenshot({ path: path.join(DEST, 'digital.jpg'), type: 'jpeg', quality: 82,
      clip: { x: 0, y: 0, width: MEDIDAS.digital.w, height: MEDIDAS.digital.h } });
    console.log('  → assets/montra/digital.jpg  (convite', cod + ')');
  }
  if (quero('impresso')) {
    console.log('impresso');
    // O cartão desenha-se a ~310px na página; ao dobro da escala sai com os
    // 620 que a montra pede, e com o desenho nítido em vez de esticado.
    const im = await (await b.newContext({ viewport: { width: 700, height: 900 },
                                           deviceScaleFactor: 2 })).newPage();
    await entrar(im, NOIVOS.email, NOIVOS.senha);
    await im.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
    await im.waitForTimeout(2200);
    await limpar(im);
    const cartao = await im.$('.cartao-item .folha');
    if (cartao) {
      const bb = await cartao.boundingBox();
      await im.screenshot({ path: path.join(DEST, 'impresso.jpg'), type: 'jpeg', quality: 84,
        clip: { x: Math.round(bb.x), y: Math.round(bb.y),
                width: Math.round(bb.width), height: Math.round(bb.height) } });
      console.log('  → assets/montra/impresso.jpg');
    } else console.log('  ! não achei o cartão');
  }

  // ---- arrumar: a conta das capturas não fica ----
  console.log('  a tirar a conta das capturas…');
  const lista = await api(admin, 'utilizador_lista');
  const eu = (lista.utilizadores || []).find(u => u.email === NOIVOS.email);
  if (eu) await api(admin, 'utilizador_apagar&id=' + eu.id, {});
  await b.close();
  console.log('\nFeito. Agora OLHE para as imagens.');
})();
