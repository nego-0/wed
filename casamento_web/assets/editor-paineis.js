/* ============================================================
   editor-paineis.js — Painéis redimensionáveis do editor

   Duas pegas: uma na aresta esquerda da coluna (largura) e uma no
   fundo de cada painel (altura). O que se escolhe fica guardado no
   dispositivo, para o editor abrir como se deixou.

   Partilhado por editor-cartao.php e convite-editor.php.
   ============================================================ */
(function () {
  'use strict';

  var COL_MIN = 220, COL_MAX = 620;
  var ALT_MIN = 80,  ALT_MAX = 900;
  // A chave inclui a página: o editor do cartão e o do convite têm painéis
  // diferentes, e não faz sentido partilharem medidas.
  var CHAVE = 'ed.paineis.' + location.pathname.split('/').pop();

  function lerGuardado() {
    try { return JSON.parse(localStorage.getItem(CHAVE) || '{}') || {}; } catch (e) { return {}; }
  }
  function guardar(dados) {
    try { localStorage.setItem(CHAVE, JSON.stringify(dados)); } catch (e) { /* sem espaço: segue */ }
  }

  var estado = lerGuardado();
  var coluna = document.querySelector('.ed-paineis');
  if (!coluna) return;

  /** Nome estável do painel, para o guardar. Usa o id, senão o título. */
  function idPainel(p, i) {
    return p.id || (p.querySelector('h3') ? p.querySelector('h3').textContent.trim().replace(/[▾\s]+$/, '') : 'p' + i);
  }

  function aplicar() {
    if (estado.largura) coluna.style.width = estado.largura + 'px';
    document.querySelectorAll('.ed-paineis .ed-painel').forEach(function (p, i) {
      var h = (estado.alturas || {})[idPainel(p, i)];
      if (h) { p.style.setProperty('--alt-painel', h + 'px'); p.classList.add('redimensionado'); }
    });
  }

  /** Arrastar genérico: chama aoMover com o deslocamento desde o início. */
  function arrastar(pega, aoIniciar, aoMover) {
    pega.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      pega.setPointerCapture(e.pointerId);
      pega.classList.add('ativo');
      document.body.style.userSelect = 'none';
      var x0 = e.clientX, y0 = e.clientY, base = aoIniciar();
      function mover(e2) { aoMover(base, e2.clientX - x0, e2.clientY - y0); }
      function largar() {
        pega.classList.remove('ativo');
        document.body.style.userSelect = '';
        pega.removeEventListener('pointermove', mover);
        pega.removeEventListener('pointerup', largar);
        guardar(estado);
      }
      pega.addEventListener('pointermove', mover);
      pega.addEventListener('pointerup', largar);
    });
  }

  // ---- largura da coluna ----
  // A pega entra ANTES da coluna, como irmã: dentro dela deslizaria com o
  // conteúdo e deixava de se alcançar depois de rolar os painéis.
  var pegaCol = document.createElement('div');
  pegaCol.className = 'ed-redim-col';
  pegaCol.title = 'Arraste para mudar a largura dos painéis';
  coluna.parentNode.insertBefore(pegaCol, coluna);
  arrastar(pegaCol,
    function () { return coluna.getBoundingClientRect().width; },
    function (base, dx) {
      // A pega está à esquerda: arrastar para a esquerda alarga.
      var w = Math.round(Math.max(COL_MIN, Math.min(COL_MAX, base - dx)));
      coluna.style.width = w + 'px';
      estado.largura = w;
    });

  // ---- altura de cada painel ----
  document.querySelectorAll('.ed-paineis .ed-painel').forEach(function (p, i) {
    var corpo = p.querySelector('.ed-painel-corpo');
    if (!corpo || p.classList.contains('cresce')) return;   // o painel elástico manda-se sozinho
    var pega = document.createElement('div');
    pega.className = 'ed-redim';
    pega.title = 'Arraste para mudar a altura · duplo clique para repor';
    p.appendChild(pega);
    var nome = idPainel(p, i);
    arrastar(pega,
      function () { return corpo.getBoundingClientRect().height; },
      function (base, dx, dy) {
        var h = Math.round(Math.max(ALT_MIN, Math.min(ALT_MAX, base + dy)));
        p.classList.add('redimensionado');
        p.style.setProperty('--alt-painel', h + 'px');
        (estado.alturas = estado.alturas || {})[nome] = h;
      });
    pega.addEventListener('dblclick', function () {
      p.classList.remove('redimensionado');
      p.style.removeProperty('--alt-painel');
      if (estado.alturas) delete estado.alturas[nome];
      guardar(estado);
    });
  });

  aplicar();
})();
