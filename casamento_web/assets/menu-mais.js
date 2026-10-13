/* ============================================================
   menu-mais.js — O menu "⋯" das listas do admin

   Vivia copiado em modelos.php e em plataforma.php, linha por linha. Estava a
   dar: uma correção feita num sítio não chegava ao outro.

   Abre por baixo do botão, como manda o costume — mas VIRA-SE PARA CIMA quando
   não há espaço em baixo. Sem isto, o menu do último cartão de uma grelha (ou
   de qualquer cartão, se a página crescer por cima dele) abria fora do ecrã:
   parecia não fazer nada.
   ============================================================ */
(function (global) {
  'use strict';

  function fecharTodos() {
    document.querySelectorAll('.mm-pop').forEach(function (x) {
      x.style.display = 'none';
      x.classList.remove('acima');
    });
  }

  function abrirMais(ev, id) {
    ev.stopPropagation();
    var alvo = document.getElementById('mm-' + id);
    var jaAberto = alvo && alvo.style.display !== 'none';
    fecharTodos();
    if (!alvo || jaAberto) return;
    alvo.style.display = '';
    // Medir depois de aparecer: só aí é que a caixa tem altura.
    var r = alvo.getBoundingClientRect();
    var folgaEmBaixo = (global.innerHeight || 0) - r.bottom;
    if (folgaEmBaixo < 8 && r.top > r.height) alvo.classList.add('acima');
  }

  document.addEventListener('click', fecharTodos);
  addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharTodos(); });

  global.abrirMais = abrirMais;
  global.fecharMenusMais = fecharTodos;
})(window);
