/* ============================================================
   editor-diag.js — Relatório de um gesto, no browser de quem o faz

   Carrega-se só com ?diag=1. Fica à espera de um arrasto numa barra ou
   num seletor de cor e conta o que aconteceu: que tipo de ponteiro, se
   os movimentos chegaram, se o controlo fugiu do sítio, se o gesto foi
   roubado. Serve para diagnosticar à distância um problema que não se
   reproduz noutra máquina.

   Nada disto corre sem ?diag=1 na barra de endereço.
   ============================================================ */
(function () {
  'use strict';
  if (!/[?&]diag=1(&|$)/.test(location.search)) return;

  var caixa = document.createElement('div');
  caixa.id = 'ed-diag';
  caixa.innerHTML =
    '<b>Diagnóstico do gesto</b>' +
    '<p>Arraste uma barra (ou escolha uma cor) como faria normalmente. ' +
    'O relatório aparece aqui em baixo — copie-o com o botão.</p>' +
    '<pre id="ed-diag-txt">à espera de um gesto…</pre>' +
    '<button type="button" id="ed-diag-copiar">Copiar</button> ' +
    '<button type="button" id="ed-diag-fechar">Fechar</button>';
  var est = document.createElement('style');
  est.textContent =
    '#ed-diag{position:fixed;right:12px;bottom:12px;z-index:9999;width:360px;max-width:92vw;' +
    'background:#14150f;color:#d6d3c9;border:1px solid #b4864a;border-radius:10px;padding:.7rem .8rem;' +
    "font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 12px 40px rgba(0,0,0,.6)}" +
    '#ed-diag b{color:#d9bc8c;font-family:system-ui,sans-serif}' +
    '#ed-diag p{margin:.4rem 0;color:#8f938a;font-family:system-ui,sans-serif}' +
    '#ed-diag pre{white-space:pre-wrap;margin:.5rem 0;padding:.5rem;background:#0b0c08;border-radius:6px;max-height:40vh;overflow:auto}' +
    '#ed-diag button{background:#3a3d37;border:1px solid #3b3e39;color:#d6d3c9;border-radius:6px;' +
    'padding:.3rem .6rem;cursor:pointer;font:inherit}';
  document.head.appendChild(est);
  document.body.appendChild(caixa);

  var saida = caixa.querySelector('#ed-diag-txt');
  caixa.querySelector('#ed-diag-fechar').onclick = function () { caixa.remove(); };
  caixa.querySelector('#ed-diag-copiar').onclick = function () {
    navigator.clipboard && navigator.clipboard.writeText(saida.textContent);
    this.textContent = 'Copiado';
    setTimeout(function () { caixa.querySelector('#ed-diag-copiar').textContent = 'Copiar'; }, 1500);
  };

  function alvoInteressa(el) {
    return el && el.tagName === 'INPUT' && (el.type === 'range' || el.type === 'color');
  }

  var g = null;
  document.addEventListener('pointerdown', function (e) {
    if (!alvoInteressa(e.target)) return;
    var el = e.target, r = el.getBoundingClientRect();
    g = {
      tipo: e.pointerType, controlo: el.type, botoes: e.buttons,
      valor0: el.value, x0: Math.round(r.left), y0: Math.round(r.top),
      moves: 0, inputs: 0, perdeuCaptura: false, noTrocado: false,
      touchAction: getComputedStyle(el).touchAction,
      largura: window.innerWidth, dpr: window.devicePixelRatio,
      el: el, t0: Date.now()
    };
    el.addEventListener('lostpointercapture', marcaPerda);
  }, true);

  // A captura implícita solta-se sempre no fim do gesto — só interessa quando
  // se perde ANTES de levantar o dedo, que é sinal de o gesto ter sido roubado.
  function marcaPerda() { if (g && !g.aAcabar) g.perdeuCaptura = true; }

  document.addEventListener('pointermove', function (e) {
    if (g && e.buttons) g.moves++;
  }, true);
  document.addEventListener('input', function (e) {
    if (g && e.target === g.el) g.inputs++;
  }, true);

  function fim() {
    if (!g) return;
    var el = g.el, r = el.getBoundingClientRect();
    g.noTrocado = !document.contains(el);
    var dx = Math.round(r.left) - g.x0, dy = Math.round(r.top) - g.y0;
    var linhas = [
      'ponteiro       : ' + g.tipo + (g.tipo !== 'mouse' ? '   <-- não é rato' : ''),
      'controlo       : input[type=' + g.controlo + ']',
      'touch-action   : ' + g.touchAction,
      'movimentos     : ' + g.moves + (g.moves < 3 ? '   <-- quase nenhum chegou' : ''),
      'alterações     : ' + g.inputs + (g.inputs < 2 ? '   <-- só a do clique' : ''),
      'valor          : ' + g.valor0 + ' -> ' + el.value,
      'controlo fugiu : ' + (dx || dy ? dx + ',' + dy + ' px   <-- saiu de baixo do cursor' : 'não'),
      'perdeu captura : ' + (g.perdeuCaptura ? 'sim   <-- o gesto foi roubado' : 'não'),
      'nó substituído : ' + (g.noTrocado ? 'sim   <-- foi redesenhado a meio' : 'não'),
      'janela         : ' + g.largura + 'px, dpr ' + g.dpr,
      'duração        : ' + (Date.now() - g.t0) + ' ms',
      'navegador      : ' + navigator.userAgent
    ];
    saida.textContent = linhas.join('\n');
    el.removeEventListener('lostpointercapture', marcaPerda);
    g = null;
  }
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    document.addEventListener(ev, function () { if (g) g.aAcabar = true; setTimeout(fim, 60); }, true);
  });
  // O seletor de cor não devolve pointerup depois de o painel do sistema abrir.
  document.addEventListener('change', function (e) {
    if (g && e.target === g.el && g.controlo === 'color') setTimeout(fim, 60);
  }, true);
})();
