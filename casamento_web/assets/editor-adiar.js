/* ============================================================
   editor-adiar.js — Nada se redesenha por baixo do rato

   Um painel redesenhado a meio de um gesto troca o elemento que o
   utilizador está a agarrar: a barra deixa de acompanhar o rato à
   primeira mexida, e o painel de cores do navegador fecha-se sozinho.

   Aqui guarda-se um sinal de "está alguém a mexer" e adia-se qualquer
   redesenho até o gesto acabar. É rede de segurança: os sítios que se
   conhecem já evitam o redesenho, mas assim nenhum que escape estraga
   o gesto.

   Partilhado por editor-cartao.php e convite-editor.php.
   ============================================================ */
(function (global) {
  'use strict';

  var aMexer = false;          // rato em baixo dentro dos painéis
  var corAberta = null;        // seletor de cor com o painel do sistema aberto
  var pendentes = Object.create(null);

  function ocupado() { return aMexer || corAberta !== null; }

  function correrPendentes() {
    if (ocupado()) return;
    var nomes = Object.keys(pendentes);
    for (var i = 0; i < nomes.length; i++) {
      var fn = pendentes[nomes[i]];
      delete pendentes[nomes[i]];
      try { fn(); } catch (e) { /* um redesenho falhado não pode travar os outros */ }
    }
  }

  // ---- gesto com o rato dentro dos painéis ----
  document.addEventListener('pointerdown', function (e) {
    if (e.target.closest && e.target.closest('.ed-paineis')) aMexer = true;
  }, true);
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    document.addEventListener(ev, function () { aMexer = false; correrPendentes(); }, true);
  });

  // ---- seletor de cor: o painel do sistema vive fora da página ----
  // Não há evento de "abriu"/"fechou". Enquanto chegarem alterações, dá-se por
  // aberto; passado um tempo sem nenhuma, dá-se por fechado.
  var tCor = null;
  document.addEventListener('input', function (e) {
    if (!e.target || e.target.type !== 'color') return;
    corAberta = e.target;
    clearTimeout(tCor);
    tCor = setTimeout(function () { corAberta = null; correrPendentes(); }, 700);
  }, true);
  document.addEventListener('change', function (e) {
    if (!e.target || e.target.type !== 'color') return;
    clearTimeout(tCor);
    tCor = setTimeout(function () { corAberta = null; correrPendentes(); }, 250);
  }, true);

  /**
   * Envolve uma função de redesenho. Enquanto houver um gesto a decorrer, o
   * pedido fica de lado e corre quando o gesto acabar — só o último de cada
   * nome, que é o que interessa.
   */
  global.adiavel = function (nome, fn) {
    return function () {
      var args = arguments, self = this;
      if (!ocupado()) return fn.apply(self, args);
      pendentes[nome] = function () { fn.apply(self, args); };
    };
  };

  /** Para quem quiser saber se pode mexer no DOM sem estragar um gesto. */
  global.gestoEmCurso = ocupado;
})(window);
