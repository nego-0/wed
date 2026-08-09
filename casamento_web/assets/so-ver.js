/* ============================================================
   so-ver.js — O ecrã em modo de leitura

   Numa visita de suporte com um código de "ver", o servidor recusa qualquer
   escrita. Isso é a fechadura, e não muda. Mas deixar à vista dez botões que
   vão todos dar na mesma recusa é uma armadilha: a pessoa preenche um
   formulário inteiro para descobrir no fim que não podia.

   Este ficheiro faz duas coisas, por esta ordem de importância:

     1. Fecha a porta no cliente. Nenhum pedido de escrita chega a sair, e a
        mensagem é sempre a mesma — em vez de cada página inventar a sua.

     2. Desliga os controlos que levariam lá. Descobre-os lendo o próprio
        código: um botão com onclick="guardar()" leva a guardar(), guardar()
        chama api('convite_save'), e 'convite_save' está na lista de escritas
        que o servidor usa. É a mesma lista dos dois lados (config.php,
        acoesDeEscrita) — não há como uma ficar para trás da outra.

   O que escapar à descoberta — um manipulador posto por addEventListener, que
   não se deixa ler — continua a bater na porta do ponto 1. O ecrã pode ficar
   incompleto; a fechadura, não.
   ============================================================ */
(function (global, doc) {
  'use strict';
  if (!global.SO_VER) return;

  var ACOES = Array.isArray(global.SO_VER_ACOES) ? global.SO_VER_ACOES : [];
  var MSG = 'Está a acompanhar este casamento com um código de leitura. '
          + 'Para corrigir, peça ao casal um código com permissão de correção.';

  function avisar() {
    if (typeof global.toast === 'function') global.toast(MSG, true);
    else global.alert(MSG);
  }

  // ---------- 1. a porta ----------
  if (typeof global.api === 'function') {
    var apiOriginal = global.api;
    global.api = function (accao, opts) {
      var nome = String(accao).split('&')[0].split('?')[0];
      if (ACOES.indexOf(nome) !== -1) {
        if (!(opts && opts.silencioso)) avisar();
        return Promise.resolve({ success: false, message: MSG, _leitura: true });
      }
      return apiOriginal.apply(this, arguments);
    };
  }

  // ---------- 2. os controlos ----------
  // Uma função "escreve" se o seu código menciona uma ação de escrita, ou se
  // chama outra que escreva. Duas voltas chegam para o que há por aqui
  // (botão → função → api) e evitam andar a percorrer a aplicação inteira.
  var cacheFonte = {};

  function escreve(codigo, prof, vistos) {
    if (!codigo || prof > 2) return false;
    for (var i = 0; i < ACOES.length; i++) {
      if (codigo.indexOf(ACOES[i]) !== -1) return true;
    }
    // Nomes chamados aqui dentro: seguem-se os que existem em window.
    var chamadas = codigo.match(/\b[A-Za-z_$][\w$]*\s*\(/g) || [];
    for (var j = 0; j < chamadas.length; j++) {
      var nome = chamadas[j].slice(0, -1).trim();
      if (vistos[nome]) continue;
      vistos[nome] = true;
      var fn = global[nome];
      if (typeof fn !== 'function') continue;
      if (!(nome in cacheFonte)) {
        try { cacheFonte[nome] = Function.prototype.toString.call(fn); }
        catch (e) { cacheFonte[nome] = ''; }
      }
      if (escreve(cacheFonte[nome], prof + 1, vistos)) return true;
    }
    return false;
  }

  var ATRIBUTOS = ['onclick', 'onchange', 'oninput', 'onsubmit', 'ondblclick'];

  function tratar(el) {
    if (el.dataset.soVer) return;              // já visto
    // Uma página pode desmentir a descoberta nos dois sentidos: data-escrita="0"
    // para o que só ABRE coisas que escrevem (um menu de ações, que também tem
    // lá dentro coisas de ver), e data-escrita="1" para o que a leitura do
    // código não alcança.
    var declarado = el.getAttribute('data-escrita');
    if (declarado === '0') { el.dataset.soVer = 'ok'; return; }
    if (declarado === '1') { el.dataset.soVer = 'off'; marcarMorto(el); return; }
    var escreveMesmo = false;
    for (var i = 0; i < ATRIBUTOS.length && !escreveMesmo; i++) {
      var a = el.getAttribute(ATRIBUTOS[i]);
      if (a) escreveMesmo = escreve(a, 0, {});
    }
    el.dataset.soVer = escreveMesmo ? 'off' : 'ok';
    if (escreveMesmo) marcarMorto(el);
  }

  function marcarMorto(el) {
    el.classList.add('so-ver-off');
    el.setAttribute('title', MSG);
    // 'disabled' diz ao navegador, ao teclado e a quem lê o ecrã que aquilo
    // não está lá para ser usado. Onde não se aplica (uma âncora), fica a
    // classe — e o guarda de cliques mais abaixo trata do resto.
    if ('disabled' in el) { try { el.disabled = true; } catch (e) {} }
    el.setAttribute('aria-disabled', 'true');
  }

  function varrer(raiz) {
    var alvos = (raiz || doc).querySelectorAll(
      '[onclick],[onchange],[oninput],[onsubmit],[ondblclick]');
    for (var i = 0; i < alvos.length; i++) tratar(alvos[i]);
  }

  // Guarda de cliques: apanha o que ficou sem 'disabled' (âncoras) e o que
  // aparecer entre uma varredura e outra.
  doc.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('.so-ver-off') : null;
    if (!el) return;
    e.preventDefault(); e.stopPropagation();
    avisar();
  }, true);

  function arrancar() {
    varrer(doc);
    // As listas desta aplicação são desenhadas em JavaScript depois de a
    // página carregar: sem isto, os botões de cada linha nasciam vivos.
    if (typeof MutationObserver === 'function') {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var ns = muts[i].addedNodes;
          for (var j = 0; j < ns.length; j++) {
            if (ns[j].nodeType !== 1) continue;
            tratar(ns[j]);
            varrer(ns[j]);
          }
        }
      }).observe(doc.body, { childList: true, subtree: true });
    }
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})(window, document);
