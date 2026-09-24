// Um número, um sítio — e os números certos.
//
// O painel tinha DUAS tiras de cartões. Em cima os filtros; em baixo, depois da
// barra de ações, uma tira de progresso por módulo. Quatro rótulos apareciam
// nas duas, com números diferentes:
//
//     Confirmados   0      (12 convites)  ←  em cima
//     Confirmações  16 de 19              ←  em baixo
//     Impressos     7      (7 convites)   ←  em cima
//     Impressos     5 de 13               ←  em baixo
//
// Dois deles estavam certos e mal explicados: um contava PESSOAS em convites
// impressos, o outro contava CONVITES impressos dos que são físicos. Nenhum
// dizia qual, e o mesmo rótulo com dois números no mesmo ecrã lê-se como erro.
// Agora há uma tira só, e cada linha de baixo nomeia o que conta.
//
// Os outros dois estavam MESMO errados, e é a parte que esta prova guarda com
// mais cuidado:
//
//   • «Confirmados 0» com «12 convites» por baixo, no mesmo cartão. As pessoas
//     confirmadas saíam de rsvp_confirmados, que só o RSVP público preenchia —
//     marcar a presença à mão, a partir da lista, deixava a coluna vazia.
//   • «Sentados: sem confirmados» numa planta com mesas cheias. A conta olhava
//     só para convidados.mesa_id (pessoa a pessoa) e nunca para
//     convites.mesa_id — sentar a família inteira, que é o caminho normal da
//     planta, não contava para nada.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzp' + Math.floor(Math.random() * 1e6);


/** Arruma um casamento de prova: arquiva-se primeiro, que é o que a casa exige
 *  antes de apagar — sem isso o apagar falhava em silêncio e os casamentos de
 *  prova iam-se juntando no arquivo de trabalho, a estragar as provas seguintes. */
async function arrumarCasamento(pg, id){
  return pg.evaluate(async i => {
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json()).catch(() => null);
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json()).catch(() => null);
    // São TRÊS passos, e por boa razão: um casamento com licença em vigor não
    // se arquiva, e um que não esteja arquivado não se apaga. A casa obriga a
    // desfazer pela ordem em que se fez, e uma prova que salte um passo deixa
    // o casamento no arquivo de trabalho a estragar as provas seguintes.
    await post('lic_revogar', { casamento: i, motivo: 'Fim da prova automática' });
    await g('casamento_estado&id=' + i + '&estado=arquivado');
    return g('casamento_apagar&id=' + i);
  }, id);
}

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

  // Um casamento só desta prova: os números têm de ser os DELE, e num arquivo
  // partilhado nunca se sabe o que já lá está.
  const novo = await p.evaluate(async n => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: n, licenca: 12 }) });
    return r.json();
  }, 'ZZ Painel ' + marca);
  ok(novo.success, 'criou o casamento de prova');
  const cid = novo.id;
  await p.evaluate(async i => { await fetch('api.php?action=casamento_abrir&id=' + i,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);

  // Seis convites de dois lugares, todos confirmados À MÃO — que é o caminho
  // que deixava o painel a dizer zero.
  const feito = await p.evaluate(async () => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) }).then(r => r.json());
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json());
    const ids = [];
    for (let i = 0; i < 6; i++) {
      // Os lugares contam-se pelos NOMES escritos: é assim que a casa os
      // define, e um campo «lugares» à parte é ignorado de propósito.
      const d = await post('convite_save', { nome_exibicao: 'Casal ' + i, tipo: 'ambos',
        membros: [{ nome: 'Ela ' + i }, { nome: 'Ele ' + i }] });
      const id = d.id || (d.convite && d.convite.id);
      if (id) ids.push(id);
    }
    for (const id of ids) await g('convite_rsvp_manual&id=' + id + '&estado=confirmado');
    return ids;
  });
  ok(feito.length === 6, 'seis convites de dois lugares, confirmados à mão');

  // ---- 1. as pessoas confirmadas contam-se ----
  const st = await p.evaluate(async () => {
    const r = await fetch('api.php?action=convite_list&por_pagina=50');
    return (await r.json()).stats;
  });
  ok(st.confirmados === 6, 'seis convites confirmados: ' + st.confirmados);
  ok(st.pes_confirmados === 12,
     'e DOZE pessoas confirmadas, que é o que seis convites de dois lugares querem dizer — '
     + 'dava 0, e o cartão lia-se «Confirmados 0 · 6 convites»: ' + st.pes_confirmados);

  // ---- 2. sentar o convite inteiro conta ----
  const antes = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=painel_progresso')).json();
    return d.modulos.find(m => m.chave === 'mesas');
  });
  ok(antes && antes.total === 12,
     'a planta sabe que há doze pessoas por sentar — dizia «sem confirmados»: '
     + (antes ? antes.total : '?'));
  ok(antes && antes.feito === 0, 'e nenhuma sentada ainda');

  const dep = await p.evaluate(async ids => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) }).then(r => r.json());
    const m = await post('mesa_save', { nome: 'ZZ Mesa', capacidade: 10, forma: 'redonda', cor: 'neutra' });
    // Senta-se o CONVITE inteiro, que é o caminho da planta.
    await post('convite_mesa', { id: ids[0], mesa_id: m.id });
    await post('convite_mesa', { id: ids[1], mesa_id: m.id });
    const d = await (await fetch('api.php?action=painel_progresso')).json();
    return d.modulos.find(x => x.chave === 'mesas');
  }, feito);
  ok(dep && dep.feito === 4,
     'sentar dois convites inteiros senta QUATRO pessoas: ' + (dep ? dep.feito : '?')
     + ' — antes ficava em 0 por mais mesas que se enchesse');

  // ---- 3. uma tira só, e cada número diz o que conta ----
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2600);
  const painel = await p.evaluate(() => {
    const cs = [...document.querySelectorAll('#stats .stat-f')].map(c => ({
      rot: (c.querySelector('.sl') || {}).textContent || '',
      num: (c.querySelector('.sn') || {}).textContent || '',
      sub: ((c.querySelector('.ss') || {}).textContent || '').trim(),
    }));
    return { cs, tiraAntiga: !!document.getElementById('tira-modulos'),
             rotulos: cs.map(c => c.rot) };
  });
  ok(!painel.tiraAntiga,
     'a segunda tira desapareceu — os seus números vivem agora nos cartões');
  const repetidos = painel.rotulos.filter((r, i) => painel.rotulos.indexOf(r) !== i);
  ok(repetidos.length === 0,
     'e nenhum rótulo aparece duas vezes no painel: ' + (repetidos.join(', ') || 'nenhum'));

  const conf = painel.cs.find(c => c.rot === 'Confirmados');
  ok(conf && conf.num === '12',
     'o cartão diz «Confirmados 12»: ' + (conf ? conf.num : '?'));
  ok(conf && /de \d+ responderam/.test(conf.sub),
     'e a linha de baixo diz o que conta, em vez de deixar adivinhar: «' + (conf ? conf.sub : '') + '»');
  const sent = painel.cs.find(c => c.rot === 'Sentados');
  ok(sent && /de 12 confirmados/.test(sent.sub),
     'e «Sentados» conta sobre os mesmos doze do cartão ao lado: «' + (sent ? sent.sub : '') + '»');

  // ---- 4. o cartão leva à página dona do número ----
  const caminhos = await p.evaluate(() => {
    const por = {};
    document.querySelectorAll('#stats .stat-cx').forEach(cx => {
      const r = (cx.querySelector('.sl') || {}).textContent;
      const a = cx.querySelector('.stat-ir');
      if (r && a) por[r] = a.getAttribute('href');
    });
    document.querySelectorAll('#stats a.stat-f').forEach(a => {
      const r = (a.querySelector('.sl') || {}).textContent;
      if (r) por[r] = a.getAttribute('href');
    });
    return por;
  });
  ok(caminhos['Sentados'] === 'mesas.php',
     'de «Sentados» vai-se à planta: ' + caminhos['Sentados']);
  ok(caminhos['Digitais'] === 'digital.php',
     'e de «Digitais» aos convites digitais, sem deixar de filtrar a lista: ' + caminhos['Digitais']);

  // O filtro continua a ser o cartão: o canto é um extra, não uma troca.
  await p.click('#stats .stat-cx:has(.sl:text-is("Digitais")) .stat-f');
  await p.waitForTimeout(1200);
  const filtrou = await p.evaluate(() => ({
    url: location.pathname,
    ativo: !!document.querySelector('#stats .stat-f.ativo .sl'),
    qual: (document.querySelector('#stats .stat-f.ativo .sl') || {}).textContent,
  }));
  ok(filtrou.url.endsWith('index.php') && filtrou.qual === 'Digitais',
     'carregar no cartão filtra a lista e fica-se no painel: ' + filtrou.qual);

  // ---- 5. escolher os cartões à vista, sem abrir os «Mais filtros» ----
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await m.fill('input[name=utilizador]', 'admin');
  await m.fill('input[name=senha]', 'noivos2026');
  await m.click('button[type=submit]');
  await m.waitForLoadState('networkidle');
  await m.evaluate(async i => { await fetch('api.php?action=casamento_abrir&id=' + i,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);
  await m.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(2600);

  const vistos = () => m.evaluate(() =>
    [...document.querySelectorAll('#stats .stat-f .sl')].map(x => x.textContent));
  const v0 = await vistos();
  ok(v0[0] === 'Todos', 'de origem, o primeiro cartão é «Todos»');

  const abertoAntes = await m.evaluate(() => STATS_ABERTO);
  await m.click('#stats-arrumar');
  await m.waitForTimeout(700);
  const modo = await m.evaluate(() => ({
    setas: document.querySelectorAll('#stats .stat-setas').length,
    aceso: document.getElementById('stats-arrumar').classList.contains('on'),
    maisFiltros: STATS_ABERTO,
  }));
  ok(modo.setas > 0 && modo.aceso, 'o botão discreto acende e põe setas em cada cartão');
  ok(modo.maisFiltros === abertoAntes,
     'e chega-se lá SEM abrir os «Mais filtros» — que é o que foi pedido');

  // As setas não podem assentar por cima do que o cartão diz.
  const tapa = await m.evaluate(() => {
    const a = document.querySelector('#stats .stat-arr');
    const ss = a.querySelector('.ss').getBoundingClientRect();
    const se = a.querySelector('.stat-setas').getBoundingClientRect();
    return { ss: Math.round(ss.bottom), se: Math.round(se.top) };
  });
  ok(tapa.ss <= tapa.se,
     'e as setas ficam ABAIXO da linha de baixo, em vez de a taparem: '
     + tapa.ss + ' → ' + tapa.se);

  await m.evaluate(() => { for (let i = 0; i < 20; i++) moverCartao('mesas', -1); });
  await m.waitForTimeout(800);
  const v1 = await vistos();
  ok(v1[0] === 'Sentados',
     '«Sentados» sobe ao primeiro lugar, vindo de trás do «Mais filtros»: ' + v1[0]);

  await m.reload({ waitUntil: 'networkidle' });
  await m.waitForTimeout(2600);
  const v2 = await vistos();
  ok(v2[0] === 'Sentados', 'e lá fica depois de recarregar a página: ' + v2[0]);

  await m.evaluate(() => reporCartoes());
  await m.waitForTimeout(700);
  const v3 = await vistos();
  ok(v3[0] === 'Todos', 'e há caminho de volta ao que vinha de origem: ' + v3[0]);

  // ---- arrumar ----
  await arrumarCasamento(p, cid);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
