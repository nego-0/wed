// O cesto de quem está a escolher não se esvazia sozinho.
//
// O menu do convidado recarrega-se de tempos a tempos, e ao recarregar apara o
// cesto — uma bebida que saiu do menu, ou que a copa suspendeu, não pode ficar
// lá dentro. Isso está certo. O que estava errado eram duas coisas por cima
// disso, e a segunda escondia-se atrás da primeira:
//
//   1. A CHAVE. Desde que a garrafa passou a poder escolher-se, o cesto é
//      indexado por `id:unidade` («17:garrafa»). A linha que procurava a
//      bebida no menu continuou a comparar `String(x.id)` — «17» — com a chave
//      inteira. Nunca eram iguais, o «não encontrei esta bebida» dava sempre
//      verdadeiro, e o cesto esvaziava-se POR COMPLETO a cada volta do menu,
//      com o bar inteiro por servir e nada de facto mudado.
//
//   2. O RELÓGIO. Mesmo com a chave certa, a volta corria de trinta em trinta
//      segundos por um relógio fixo, sem olhar a quem estava do outro lado.
//      Passa a esperar que a pessoa pare: vinte segundos sem um toque, sem um
//      scroll, sem uma tecla. Um gesto ao segundo dezanove adia a volta
//      inteira.
//
// O que NÃO pode partir-se a arranjar isto: o cesto continua a ter de ceder
// quando o bar muda mesmo — uma bebida que acaba, uma regra que entra em vigor
// — e a pessoa tem de LER que cedeu. Um número que se mexe sozinho e calado é
// o que faz a página parecer avariada.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { limparBar } = require('./limpar-bar');
const marca = 'zzc' + Math.floor(Math.random() * 1e5);

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

  // A ardósia limpa: uma regra deixada por outra corrida, ou um pedido em
  // análise (que gasta quota desde que entra), fariam o cesto ceder pela razão
  // errada — e esta prova mede exactamente quando ele cede.
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
  }, 'ZZC ');

  // Duas bebidas: uma ao copo e uma das duas maneiras, para o cesto ter
  // chaves das duas formas («id:copo» e «id:garrafa») — que é onde o defeito
  // da chave vivia.
  const agua = await post('bar_item_guardar',
    { nome: 'ZZC Água ' + marca, servir: 'copo', max_por_pedido: 9 });
  const tinto = await post('bar_item_guardar',
    { nome: 'ZZC Tinto ' + marca, alcoolico: 1, servir: 'ambos',
      doses_garrafa: 6, max_por_pedido: 9 });
  for (const d of [agua, tinto]) {
    await post('bar_stock_repor', { item_id: d.id, quantidade: 90, nota: 'prova' });
  }
  ok(agua.success && tinto.success, 'duas bebidas de prova, uma só ao copo e uma das duas maneiras');

  const cena = await p.evaluate(async m => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await post('mesa_save', { nome: 'ZZC Mesa ' + m, capacidade: 6,
                                         forma: 'redonda', cor: 'neutra' });
    const mesa = (dm.mesas || []).filter(x => x.nome === 'ZZC Mesa ' + m).pop();
    await post('convite_save', { nome_exibicao: 'ZZC Provador ' + m,
      mesa: mesa ? String(mesa.id) : '', membros: [{ nome: 'ZZC Provador ' + m }] });
    return { mesa: mesa ? +mesa.id : 0, token: mesa ? mesa.bar_token : '' };
  }, marca);
  ok(/^[A-Z0-9]{10}$/.test(cena.token || ''),
     'e uma mesa com o seu código de QR: ' + cena.token);
  await post('bar_abrir');

  const salao = await b.newContext({ viewport: { width: 390, height: 844 },
                                     isMobile: true, hasTouch: true });
  const conv = await salao.newPage();
  conv.on('pageerror', e => errs.push('convidado: ' + e.message));
  await conv.goto(BASE + '/bebidas.php?c=2026-ia&m=' + cena.token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(1500);
  await conv.fill('#b-q', 'ZZC Provador');
  await conv.waitForTimeout(1200);
  await conv.locator('.b-nome').first().click();
  await conv.waitForTimeout(2000);

  const noCesto = () => conv.evaluate(() => {
    const rod = document.getElementById('b-rodape');
    return { resumo: (document.getElementById('b-resumo') || {}).textContent || '',
             visivel: !!(rod && !rod.hidden) };
  });

  // ---- escolhe-se: um copo de água e uma garrafa de tinto ----
  await conv.evaluate(async ({ a, t }) => {
    barMais(+a, 'copo'); barMais(+a, 'copo');
    await new Promise(r => setTimeout(r, 300));
    barUnidade(+t, 'garrafa');
    await new Promise(r => setTimeout(r, 400));
    barMais(+t, 'garrafa');
    await new Promise(r => setTimeout(r, 300));
  }, { a: agua.id, t: tinto.id });
  const escolhido = await noCesto();
  ok(escolhido.visivel && /3|2/.test(escolhido.resumo),
     'o convidado escolhe duas águas e uma garrafa de tinto: «' + escolhido.resumo + '»');

  // ============ 1. o menu recarrega e o cesto FICA ============
  //
  // Chama-se a volta à mão, que é exactamente o que o relógio fazia. Nada
  // mudou no bar: o cesto tem de sair de lá igual. Esvaziava-se todo.
  const depoisDaVolta = await conv.evaluate(async () => {
    await window.barTesteRecarregarMenu();
    await new Promise(r => setTimeout(r, 900));
    const rod = document.getElementById('b-rodape');
    return { resumo: (document.getElementById('b-resumo') || {}).textContent || '',
             visivel: !!(rod && !rod.hidden) };
  });
  ok(depoisDaVolta.visivel && depoisDaVolta.resumo === escolhido.resumo,
     'o menu recarrega e o cesto fica como estava — esvaziava-se todo, porque '
     + 'a chave «id:unidade» era comparada com o id só: «'
     + depoisDaVolta.resumo + '» (era «' + escolhido.resumo + '»)');

  // ============ 2. e não recarrega enquanto a pessoa mexe ============
  //
  // Vinte segundos é o tempo de paragem. Mexe-se ao décimo e ao vigésimo: a
  // volta tem de continuar adiada — senão o cesto de quem está a escolher
  // continua à mercê de um relógio, ainda que agora o aparasse com mais
  // cuidado.
  const antesDoRelogio = await conv.evaluate(() => window.barTesteUltimoMenu());
  await conv.waitForTimeout(9000);
  await conv.mouse.wheel(0, 120);           // um gesto ao nono segundo
  await conv.waitForTimeout(9000);
  await conv.mouse.wheel(0, -120);          // e outro ao décimo oitavo
  await conv.waitForTimeout(6000);
  const comActividade = await conv.evaluate(() => ({
    ultimo: window.barTesteUltimoMenu(),
    resumo: (document.getElementById('b-resumo') || {}).textContent || '' }));
  ok(comActividade.ultimo === antesDoRelogio,
     'com a pessoa a mexer, passam vinte e quatro segundos e o menu NÃO vai '
     + 'buscar nada — a paragem conta-se desde o último gesto, e não desde a '
     + 'última volta');
  ok(comActividade.resumo === escolhido.resumo,
     'e o cesto dela continua intacto: «' + comActividade.resumo + '»');

  // ============ 3. parada, o menu volta a andar ============
  await conv.waitForTimeout(22000);
  const parada = await conv.evaluate(() => ({
    ultimo: window.barTesteUltimoMenu(),
    resumo: (document.getElementById('b-resumo') || {}).textContent || '' }));
  ok(parada.ultimo > antesDoRelogio,
     'parada vinte segundos, o menu recarrega — quem larga o telemóvel em cima '
     + 'da mesa volta a pegar-lhe com o bar do momento, e não com o de há dez '
     + 'minutos');
  ok(parada.resumo === escolhido.resumo,
     'e mesmo aí o cesto fica: a volta não é uma razão para desmarcar nada — '
     + 'só o bar ter mudado é: «' + parada.resumo + '»');

  // ============ 4. mas quando o bar MUDA mesmo, o cesto cede — e diz-se ====
  //
  // Esta é a guarda que não pode partir-se a arranjar o resto. A copa esconde
  // a água: ela sai do menu, e o que estava escolhido dela não pode ficar lá a
  // prometer uma bebida que já não se serve.
  await post('bar_item_guardar', { id: agua.id, nome: 'ZZC Água ' + marca,
                                   servir: 'copo', max_por_pedido: 9, estado: 'oculto' });
  const cedeu = await conv.evaluate(async () => {
    await window.barTesteRecarregarMenu();
    await new Promise(r => setTimeout(r, 1200));
    const av = document.getElementById('b-avisos');
    return { resumo: (document.getElementById('b-resumo') || {}).textContent || '',
             aviso: av ? av.textContent.replace(/\s+/g, ' ').trim() : '' };
  });
  ok(cedeu.resumo !== escolhido.resumo,
     'a copa esconde a água e o cesto cede nela: «' + cedeu.resumo + '»');
  ok(/cesto foi acertado|já não cab/i.test(cedeu.aviso),
     'e a página DIZ porquê, em vez de mexer nos números de alguém em '
     + 'silêncio: «' + cedeu.aviso.slice(0, 120) + '»');

  // ---- arrumar ----
  const sobrou = await limparBar(p, 'ZZC ');
  ok(sobrou.length === 0, 'a prova não deixa bebidas para trás: '
     + (sobrou.join(', ') || 'nenhuma'));
  await p.evaluate(async () => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await (await fetch('api.php?action=mesa_list')).json();
    for (const x of (dm.mesas || [])) {
      if (String(x.nome).startsWith('ZZC ')) await post('mesa_apagar', { id: +x.id });
    }
  });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  await b.close();
  console.log(f ? '\n' + f + ' FALHA(S)' : '\nTUDO VERDE');
  process.exit(f ? 1 : 0);
})();
