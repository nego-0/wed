// Há bebidas que só se pedem ao COPO.
//
// O whisky bom, o espumante da meia-noite: o casal comprou-os a contar, e não
// quer vê-los sair inteiros para uma mesa só. Até aqui não havia como o dizer —
// um pedido era um pedido, sem unidade nenhuma —, e por isso a única defesa era
// o «máximo por pedido», que limita a quantidade mas não impede que a
// quantidade seja a garrafa.
//
// Passa a haver três maneiras de servir cada bebida: só ao copo (o padrão, que
// é o que a casa sempre fez), só à garrafa, ou as duas. E há duas coisas que
// esta prova guarda com cuidado:
//
//   1. A TRAVA É DO SERVIDOR. Esconder a garrafa na carta não chega: a carta
//      que a pessoa tem aberta pode ter dois minutos, e um pedido chega por
//      onde quiser chegar. Por isso a prova pede a garrafa pela API, à bruta,
//      de uma bebida que só se serve ao copo — e tem de levar com um não.
//   2. UMA GARRAFA GASTA AS DOSES QUE TEM DENTRO. O stock conta-se em copos,
//      que é o que acaba a meio da noite; pedir uma garrafa de seis tira seis.
//      Sem isto, uma garrafa gastava uma unidade e o stock mentia a noite toda.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
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

  const post = (acao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) });
    return r.json().catch(() => ({ success: false }));
  }, { a: acao, c: corpo });

  // ---- duas bebidas de prova, com maneiras de servir diferentes ----
  const soCopo = await post('bar_item_guardar',
    { nome: 'ZZ Whisky do bom', alcoolico: 1, servir: 'copo', max_por_pedido: 20 });
  const ambos = await post('bar_item_guardar',
    { nome: 'ZZ Tinto da casa', alcoolico: 1, servir: 'ambos',
      doses_garrafa: 6, max_por_pedido: 20 });
  ok(soCopo.success && ambos.success, 'criou as duas bebidas de prova');
  const idCopo = soCopo.id, idAmbos = ambos.id;

  // Stock que chegue para as contas, em COPOS.
  await post('bar_stock_repor', { item_id: idCopo, quantidade: 40, nota: 'prova' });
  await post('bar_stock_repor', { item_id: idAmbos, quantidade: 40, nota: 'prova' });
  await post('bar_abrir', { abrir: true });

  // A lista completa vem com o estado do bar — não há acção «bar_itens».
  const lerCarta = () => p.evaluate(async () => {
    const r = await fetch('api.php?action=bar_estado');
    return (await r.json()).itens || [];
  });
  const carta = await lerCarta();
  const cCopo = carta.find(x => x.id === idCopo) || {};
  const cAmbos = carta.find(x => x.id === idAmbos) || {};
  ok(cCopo.servir === 'copo', 'a primeira serve-se só ao copo: ' + cCopo.servir);
  ok(cAmbos.servir === 'ambos' && cAmbos.doses_garrafa === 6,
     'a segunda serve-se das duas maneiras, com seis copos por garrafa');
  ok(cCopo.garrafas_possiveis === 0,
     'e de uma que só sai ao copo não há garrafas nenhumas a oferecer: '
     + cCopo.garrafas_possiveis);
  ok(cAmbos.garrafas_possiveis === Math.floor(40 / 6),
     'da outra, o que há dá para ' + cAmbos.garrafas_possiveis + ' garrafas — '
     + 'quarenta copos a seis, e não quarenta garrafas');

  // ---- o padrão de quem já lá estava ----
  // Só as que não são de provas: uma corrida anterior pode ter deixado lá as
  // suas. O padrão é `ZZ` mais as letras de cada prova (ZZU, ZZG, ZZK...) —
  // pedir o espaço a seguir ao ZZ deixava passar todas menos as desta, e uma
  // bebida «ao copo ou à garrafa» esquecida por outra corrida fazia esta
  // linha falhar por uma razão que não é a que ela mede.
  const velhas = carta.filter(x => x.id !== idCopo && x.id !== idAmbos
                                && !/^ZZ/i.test(x.nome || ''));
  ok(velhas.every(x => x.servir === 'copo'),
     'a carta que já existia fica toda ao copo — que é exactamente o que a casa '
     + 'fazia antes de a garrafa existir: ' + velhas.length + ' bebida(s)');

  // ---- a trava é do SERVIDOR ----
  // O convidado entra pela porta dele: a página da mesa, com o código do QR, e
  // um nome escolhido da lista. Pedir a partir da sessão dos noivos não provava
  // nada — é justamente o pedido do CONVIDADO que tem de ser travado.
  // Para haver quem peça, tem de haver quem esteja sentado à mesa: a porta do
  // bar abre no «quem está a pedir?», e essa lista é a da mesa do QR.
  const sentou = await p.evaluate(async () => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) }).then(r => r.json());
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json());
    const ms = await (await fetch('api.php?action=mesa_list')).json();
    // A mesa dos noivos tem painel próprio e não serve para isto.
    let mesa = (ms.mesas || []).find(m => m.especial !== 'noivos');
    const jaHavia = !!mesa;
    if (!mesa) {
      await post('mesa_save', { nome: 'ZZ Mesa do bar', capacidade: 8,
                                forma: 'redonda', cor: 'neutra' });
      const ms2 = await (await fetch('api.php?action=mesa_list')).json();
      const livres = (ms2.mesas || []).filter(m => m.especial !== 'noivos');
      mesa = livres[livres.length - 1];
    }
    if (!mesa) return { erro: 'não há mesa onde sentar' };
    const criou = !jaHavia;
    const cv = await post('convite_save', { nome_exibicao: 'ZZ Provadores', tipo: 'ambos',
      membros: [{ nome: 'ZZ Provador' }, { nome: 'ZZ Provadora' }] });
    const cid = cv.id || (cv.convite && cv.convite.id);
    await g('convite_rsvp_manual&id=' + cid + '&estado=confirmado');
    await post('convite_mesa', { id: cid, mesa_id: mesa.id });
    return { convite: cid, mesa: +mesa.id, nome: mesa.nome, criou: criou };
  });
  ok(!!sentou.convite, 'sentou duas pessoas numa mesa, para haver quem peça');

  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  // O mesa_list devolve o id como TEXTO e o BAR_MESAS como número: comparar
  // à letra não dava nada, e a prova ficava sem mesa por onde entrar.
  const token = await p.evaluate(m => ((window.BAR_MESAS || [])
                                       .find(x => +x.id === +m) || {}).token, sentou.mesa);
  // O código da mesa é gerado com ela e não muda — é o que vai no QR pousado
  // em cima dela, e a folha imprime-se uma vez só.
  ok(/^[A-Z0-9]{10}$/.test(token || ''), 'há uma mesa com código para o QR: ' + token);

  const salao = await b.newContext({ viewport: { width: 390, height: 844 } });
  const conv = await salao.newPage();
  conv.on('pageerror', e => errs.push(e.message));
  await conv.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(900);
  // A lista de nomes só aparece depois de se escreverem quatro letras: com
  // menos seria meio índice da festa à vista de quem pegasse no telemóvel.
  await conv.fill('#b-q', 'ZZ Provador');
  await conv.waitForTimeout(900);
  const temNomes = await conv.locator('.b-nome').count();
  ok(temNomes > 0, 'a porta da mesa abre no «quem está a pedir?»: ' + temNomes + ' nome(s)');
  await conv.locator('.b-nome').first().click();
  await conv.waitForTimeout(1400);

  // O «m» é o código do QR, e é ele a porta: sem token, a API nem olha para o
  // pedido. É o mesmo caminho por onde o telemóvel do convidado fala.
  const pedir = (id, un, q) => conv.evaluate(async ({ i, u, n, m, t }) => {
    const r = await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, itens: [{ item_id: i, quantidade: n || 1, unidade: u }],
                             mesa_id: m, mesa_qr_id: m }) });
    return r.json().catch(() => ({ success: false }));
  }, { i: id, u: un, n: q, m: sentou.mesa, t: token });

  const tentativa = await pedir(idCopo, 'garrafa');
  ok(tentativa.success === false && /só ao copo/i.test(tentativa.message || ''),
     'pedir a garrafa de uma bebida que só sai ao copo leva com um não, vindo do '
     + 'servidor e não da carta: «' + (tentativa.message || '?') + '»');

  const aoCopo = await pedir(idCopo, 'copo');
  ok(aoCopo.success === true,
     'e ao copo a mesma bebida passa: ' + (aoCopo.success ? 'pedida' : (aoCopo.message || '?')));

  // ---- uma garrafa gasta as doses que leva dentro ----
  const comGarrafa = await pedir(idAmbos, 'garrafa');
  ok(comGarrafa.success === true,
     'a que serve das duas maneiras aceita a garrafa: '
     + (comGarrafa.success ? 'pedida' : (comGarrafa.message || '?')));
  const linha = comGarrafa.success ? (comGarrafa.pedido.itens || [])[0] : null;
  ok(linha && linha.unidade === 'garrafa',
     'e o pedido guarda que foi À GARRAFA — é o que a copa tem de servir: '
     + (linha ? linha.unidade : '?'));

  // O tecto do pedido conta em DOSES: com «máximo 20 por pedido», sete
  // garrafas de seis são 42 doses e não sete unidades.
  const demais = await pedir(idAmbos, 'garrafa', 7);
  ok(demais.success === false,
     'sete garrafas de seis não cabem num tecto de vinte doses: «'
     + (demais.message || '?') + '»');

  // ---- a montagem deixa escolher, e o que se escolhe fica ----
  const mudou = await post('bar_item_guardar',
    { id: idAmbos, nome: 'ZZ Tinto da casa', alcoolico: 1, servir: 'copo', max_por_pedido: 20 });
  ok(mudou.success, 'o casal muda a bebida para «só ao copo»');
  const agora = (await lerCarta()).find(x => x.id === idAmbos) || {};
  ok(agora.servir === 'copo', 'e fica assim guardado: ' + agora.servir);
  const jaNao = await pedir(idAmbos, 'garrafa');
  ok(jaNao.success === false,
     'e a garrafa que ontem se podia pedir hoje já não se pede: «'
     + (jaNao.message || '?') + '»');

  // ---- arrumar ----
  // A mesa também: ela nasce no mesmo sítio onde a chk_planta põe as dela, e
  // duas mesas empilhadas na mesma coordenada fazem uma tapar o clique da
  // outra. Foi assim que esta prova pôs a da planta a falhar.
  await p.evaluate(async ({ c, m }) => {
    const g = a => fetch('api.php?action=' + a,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => null);
    await g('convite_delete&id=' + c + '&definitivo=1');
    if (m) await g('mesa_delete&id=' + m);
  }, { c: sentou.convite, m: sentou.criou ? sentou.mesa : 0 }).catch(() => {});
  for (const id of [idCopo, idAmbos]) {
    await p.evaluate(async i => {
      await fetch('api.php?action=bar_item_apagar&id=' + i,
        { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
    }, id);
  }

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
