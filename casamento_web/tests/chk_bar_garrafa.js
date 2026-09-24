// A garrafa que não se conseguia pedir, pelos dois caminhos que a travavam.
//
// Isto voltou três vezes do salão, sempre com a mesma frase — «o ícone + parece
// desabilitado» —, e de cada vez a causa era outra. As duas primeiras já têm
// prova (chk_bar_unidade). Esta fecha as duas últimas, e ambas são a mesma
// doença: um número medido na unidade errada.
//
//   1. «COPOS POR GARRAFA» NUMA BEBIDA QUE SÓ SAI À GARRAFA.
//      O campo existe para ligar duas contas: o stock mede-se em copos, porque
//      é o copo que acaba, e uma garrafa gasta os que leva dentro. Numa bebida
//      que só sai à garrafa não há copo nenhum a contar — o que se tira da
//      caixa é a garrafa —, mas o campo continuava lá com o seu 6 de origem,
//      que ninguém tinha razão para ir mexer. Resultado: dez garrafas em stock
//      davam UMA garrafa pedível e sobravam quatro copos que não faziam
//      garrafa nenhuma. A bebida esgotava-se ao fim de nada, com o «+»
//      apagado e sem uma palavra a explicar porquê.
//
//   2. UMA REGRA BANAL DO BAR APAGAVA O «+» DE QUALQUER GARRAFA.
//      «No máximo 2 bebidas» é a primeira regra que qualquer casa escreve. O
//      consumo que a mede já vinha somado em ARTIGOS (é `pi.quantidade`: uma
//      garrafa pedida conta uma), mas o TECTO era comparado contra doses. Seis
//      nunca cabe em dois: com a regra posta, escolher «garrafa» numa bebida
//      anunciada como «ao copo ou à garrafa» apagava o botão. Duas contas
//      diferentes a fingir que eram a mesma, do lado do numerador e do
//      denominador.
//
// O que NÃO pode partir-se a arranjar isto: o stock continua a defender-se em
// doses (quatro copos não dão uma garrafa de doze), e a regra continua a
// travar quem já levou o que lhe cabia. As duas últimas provas medem isso.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { limparBar } = require('./limpar-bar');
const marca = 'zzg' + Math.floor(Math.random() * 1e5);

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
  const post = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) });
    return r.json().catch(() => ({ success: false }));
  }, { a, c });

  // A ARDÓSIA LIMPA, antes de medir seja o que for.
  //
  // Esta prova conta quota: põe uma regra de duas bebidas e verifica que a
  // primeira passa e a terceira não. Uma regra deixada por outra corrida, ou
  // um pedido em análise (que gasta quota desde que entra), fazem a conta
  // começar a meio — e então o «+» trava pela razão errada, com a frase
  // certa. Foi assim que esta prova passou uma vez e falhou a seguir sem nada
  // ter mudado no produto.
  const ardosiaLimpa = () => p.evaluate(async () => {
    const r = await window.api('bar_regras');
    for (const x of (r.regras || [])) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    const e = await window.api('bar_estado');
    for (const x of (e.fila || [])) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
  });
  await ardosiaLimpa();
  // E as MESAS que uma corrida morta a meio deixou. A limpeza do fim só
  // corre quando a prova chega ao fim; uma que rebente antes disso deixa a
  // sua mesa na planta, e as mesas nascem todas no mesmo canto — vinte
  // corridas depois há uma pilha delas em cima umas das outras, e é outra
  // prova, noutro ficheiro, que falha a dizer que não há canvas onde pegar.
  await p.evaluate(async pre => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const d = await (await fetch('api.php?action=mesa_list')).json();
    for (const m of (d.mesas || [])) {
      if (String(m.nome).startsWith(pre)) await post('mesa_apagar', { id: +m.id });
    }
  }, 'ZZG ');

  // ============ 1. o campo que não se aplica anula-se ============
  //
  // Guarda-se de propósito com o 6 lá dentro, que é o que o formulário mandava
  // antes de aprender a escondê-lo — e é o que qualquer registo antigo tem.
  const soGarrafa = await post('bar_item_guardar',
    { nome: 'ZZG Espumante ' + marca, alcoolico: 1, servir: 'garrafa', doses_garrafa: 6 });
  ok(soGarrafa.success, 'uma bebida que só sai à garrafa, guardada com «6 copos por garrafa»');

  // Pelo `bar_estado`, que é o que a montagem e a copa leem: o `bar_menu` é a
  // porta do convidado e não abre sem festa nem mesa.
  const doArmazem = (id) => p.evaluate(async i => {
    const d = await (await fetch('api.php?action=bar_estado')).json();
    return (d.itens || []).find(x => +x.id === +i) || null;
  }, id);
  const comoFicou = await doArmazem(soGarrafa.id);
  ok(comoFicou && comoFicou.doses_garrafa === 1,
     'o servidor anula-o: uma garrafa custa UMA garrafa, e não seis copos que '
     + 'não existem — ficou em ' + (comoFicou && comoFicou.doses_garrafa));

  // E o stock passa a contar garrafas, que é o que se tira da caixa: dez em
  // stock são dez garrafas pedíveis, e não uma com quatro copos a sobrar.
  await post('bar_stock_repor', { item_id: soGarrafa.id, quantidade: 10, nota: 'prova' });
  const dez = await doArmazem(soGarrafa.id);
  ok(dez && dez.garrafas_possiveis === 10,
     'e dez em stock são dez garrafas, não uma: ' + (dez && dez.garrafas_possiveis));

  // ---- o formulário deixa de perguntar o que não se aplica ----
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const noForm = await p.evaluate(async id => {
    barEditar(+id);
    await new Promise(r => setTimeout(r, 900));
    const linha = (i) => {
      const el = document.getElementById('lf-' + i);
      return el ? el.closest('.lic-f-c') : null;
    };
    const ld = linha('doses_garrafa');
    return { escondido: !!(ld && ld.hidden),
             rotuloServir: (document.querySelector('label[for="lf-servir"]') || {}).textContent };
  }, soGarrafa.id);
  ok(noForm.escondido,
     'e o formulário não o pergunta a quem escolheu «Só à garrafa» — um campo '
     + 'que não se aplica é uma pergunta a pedir a resposta errada');

  // ---- e reage à MUDANÇA, que é onde a pessoa está quando decide ----
  //
  // O formulário não é reactivo por si. Sem isto, o campo só desaparecia da
  // segunda vez que se abrisse a bebida — depois de já ter sido guardada com
  // o número errado, que é exactamente tarde de mais.
  const aoMudar = await p.evaluate(async () => {
    // `licSelDefinir` recebe a CAIXA, não o id: o valor vive num campo
    // escondido lá dentro, e é ele que leva o id de sempre.
    const porServir = (v) => licSelDefinir(
      document.getElementById('lf-servir').closest('.lic-sel'), v);
    const ver = () => {
      const el = document.getElementById('lf-doses_garrafa');
      const l = el ? el.closest('.lic-f-c') : null;
      return { escondido: !!(l && l.hidden),
               stock: (document.querySelector('label[for="lf-stock"]') || {}).textContent || '' };
    };
    porServir('copo');
    await new Promise(r => setTimeout(r, 400));
    const aoCopo = ver();
    porServir('garrafa');
    await new Promise(r => setTimeout(r, 400));
    return { aoCopo, aGarrafa: ver() };
  });
  ok(!aoMudar.aoCopo.escondido && aoMudar.aGarrafa.escondido,
     'e aparece e desaparece à medida que se muda o «Serve-se», sem fechar e '
     + 'reabrir a janela');
  await p.evaluate(() => licFecharJanela());

  // ---- a unidade do stock diz-se, e muda com ele ----
  //
  // É a outra metade da mesma coisa: com o campo escondido, o stock deixou de
  // se contar em copos e passou a contar-se em garrafas. Quem preenche tem de
  // o ler ali, e não no fim, depois de ter escrito dez a pensar em garrafas.
  const rotulos = await p.evaluate(async () => {
    barNova();
    await new Promise(r => setTimeout(r, 900));
    const rot = () => (document.querySelector('label[for="lf-stock"]') || {}).textContent || '';
    const aoCopo = rot();
    licSelDefinir(document.getElementById('lf-servir').closest('.lic-sel'), 'garrafa');
    await new Promise(r => setTimeout(r, 400));
    const aGarrafa = rot();
    licFecharJanela();
    return { aoCopo, aGarrafa };
  });
  ok(/COPOS/.test(rotulos.aoCopo) && /GARRAFAS/.test(rotulos.aGarrafa),
     'e o stock diz em que unidade se conta, e muda com o «Serve-se»: «'
     + rotulos.aoCopo + '» → «' + rotulos.aGarrafa + '»');

  // ============ 2. uma regra da casa não apaga o «+» da garrafa ============
  const ambos = await post('bar_item_guardar',
    { nome: 'ZZG Tinto ' + marca, alcoolico: 1, servir: 'ambos', doses_garrafa: 6 });
  await post('bar_stock_repor', { item_id: ambos.id, quantidade: 60, nota: 'prova' });

  const cena = await p.evaluate(async m => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await post('mesa_save', { nome: 'ZZG Mesa ' + m, capacidade: 6,
                                         forma: 'redonda', cor: 'neutra' });
    const mesa = (dm.mesas || []).filter(x => x.nome === 'ZZG Mesa ' + m).pop();
    await post('convite_save', { nome_exibicao: 'ZZG Provador ' + m,
      mesa: mesa ? String(mesa.id) : '', membros: [{ nome: 'ZZG Provador ' + m }] });
    return { mesa: mesa ? +mesa.id : 0, nome: 'ZZG Mesa ' + m };
  }, marca);
  await post('bar_abrir');

  // A regra mais banal que há, e a primeira que qualquer casa escreve.
  const regra = await post('bar_regra_guardar',
    { escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas', quantidade: 2, janela_min: 0 });
  ok(regra.success, 'a casa põe «no máximo 2 bebidas por convidado»');

  // Entra-se pelo endereço da festa com o CÓDIGO da mesa por cima — o que vai
  // no QR pousado em cima dela. O código vem do servidor com a mesa: é gerado
  // ao criá-la, e não se deduz do nome.
  const tok = await p.evaluate(async n => {
    const d = await (await fetch('api.php?action=mesa_list')).json();
    return ((d.mesas || []).find(x => x.nome === n) || {}).bar_token || '';
  }, cena.nome);
  ok(/^[A-Z0-9]{10}$/.test(tok), 'a mesa nasceu com o seu código de QR: ' + tok);

  const salao = await b.newContext({ viewport: { width: 390, height: 844 },
                                     isMobile: true, hasTouch: true });
  const conv = await salao.newPage();
  conv.on('pageerror', e => errs.push('convidado: ' + e.message));
  await conv.goto(BASE + '/bebidas.php?c=2026-ia&m=' + tok, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(1500);
  await conv.fill('#b-q', 'ZZG Provador');
  await conv.waitForTimeout(1200);
  await conv.locator('.b-nome').first().click();
  await conv.waitForTimeout(2000);

  const escolher = (id, un) => conv.evaluate(async ({ i, u }) => {
    barUnidade(+i, u);
    await new Promise(r => setTimeout(r, 700));
    const c = document.getElementById('bb-' + i);
    const up = c ? c.querySelector('.b-mais .up') : null;
    return { desactivado: !up || up.disabled,
             recado: (c && c.querySelector('.qtd') || {}).textContent || '' };
  }, { i: id, u: un });

  const aoCopo = await escolher(ambos.id, 'copo');
  ok(!aoCopo.desactivado, 'ao copo, a bebida pede-se — é o caso que nunca partiu');

  const aGarrafa = await escolher(ambos.id, 'garrafa');
  ok(!aGarrafa.desactivado,
     'e escolher «garrafa» NÃO apaga o «+»: era aqui que morria, com uma regra '
     + 'que a casa escreveu a pensar em copos — recado: «' + aGarrafa.recado + '»');

  const pedido = await conv.evaluate(async id => {
    barMais(+id, 'garrafa');
    await new Promise(r => setTimeout(r, 700));
    const rod = document.getElementById('b-rodape');
    if (!rod || rod.hidden) return { enviou: false, erro: 'não entrou no cesto' };
    document.getElementById('b-pedir').click();
    await new Promise(r => setTimeout(r, 2600));
    const e = document.querySelector('.b-erro');
    const meu = document.querySelector('.b-meu .oq');
    return { enviou: true, erro: e ? e.textContent.trim() : '',
             meu: meu ? meu.textContent.replace(/\s+/g, ' ').trim() : '' };
  }, ambos.id);
  ok(pedido.enviou && !pedido.erro && /garrafa/i.test(pedido.meu),
     'e o pedido passa no servidor, que fazia a mesma conta: «'
     + (pedido.erro || pedido.meu) + '»');

  // ---- mas a regra CONTINUA A VALER ----
  //
  // Esta é a prova que impede que o arranjo seja «deixar passar tudo». Duas
  // bebidas é o tecto: a garrafa acima gastou uma, e há-de caber mais uma —
  // a terceira não.
  const terceira = await conv.evaluate(async id => {
    const gasta = async (un) => {
      barUnidade(+id, un);
      await new Promise(r => setTimeout(r, 500));
      barMais(+id, un);
      await new Promise(r => setTimeout(r, 500));
      const rod = document.getElementById('b-rodape');
      if (!rod || rod.hidden) return false;
      document.getElementById('b-pedir').click();
      await new Promise(r => setTimeout(r, 2400));
      return true;
    };
    const segunda = await gasta('garrafa');
    const c = document.getElementById('bb-' + id);
    const up = c ? c.querySelector('.b-mais .up') : null;
    return { segunda, travado: !up || up.disabled,
             recado: (c && c.querySelector('.qtd') || {}).textContent || '' };
  }, ambos.id);
  ok(terceira.segunda && terceira.travado,
     'mas duas são duas: com o tecto gasto, o «+» trava — o arranjo não foi '
     + 'deitar a regra fora');
  ok(/levou|casa serve/i.test(terceira.recado || ''),
     'e diz porquê, em vez de deixar um botão morto: «' + terceira.recado + '»');

  // ---- e o STOCK continua a defender-se em doses ----
  //
  // Quatro copos não dão uma garrafa de doze. Esta trava é a certa, e é a que
  // tem de sobreviver a tudo o resto: é ela que impede a copa de prometer uma
  // garrafa que não existe.
  // A regra sai TODA, e os pedidos que ela já contou com ela: um pedido em
  // análise gasta quota, e deixá-lo lá fazia esta última secção começar com a
  // conta a meio — e então o «+» travava pelo tecto, que é a outra razão, e a
  // prova media a coisa errada com a frase certa.
  await ardosiaLimpa();
  const pouco = await post('bar_item_guardar',
    { nome: 'ZZG Pouco ' + marca, servir: 'ambos', doses_garrafa: 12 });
  await post('bar_stock_repor', { item_id: pouco.id, quantidade: 4, nota: 'prova' });
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(2200);
  const semGarrafa = await escolher(pouco.id, 'garrafa');
  ok(semGarrafa.desactivado && /sem garrafas/i.test(semGarrafa.recado || ''),
     'quatro copos não dão uma garrafa de doze, e essa continua travada, a '
     + 'dizer porquê: «' + semGarrafa.recado + '»');

  // ---- arrumar ----
  const sobrou = await limparBar(p, 'ZZG ');
  ok(sobrou.length === 0, 'a prova não deixa bebidas para trás: '
     + (sobrou.join(', ') || 'nenhuma'));
  await p.evaluate(async m => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await (await fetch('api.php?action=mesa_list')).json();
    for (const x of (dm.mesas || [])) {
      if (String(x.nome).startsWith('ZZG ')) await post('mesa_apagar', { id: +x.id });
    }
  }, marca);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  await b.close();
  console.log(f ? '\n' + f + ' FALHA(S)' : '\nTUDO VERDE');
  process.exit(f ? 1 : 0);
})();
