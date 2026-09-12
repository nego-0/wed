/* ============================================================
   editor-espaco.js — O editor precisa de mesa para trabalhar

   Os editores têm três colunas — ferramentas, peça e painéis — e
   uma barra de opções que não deve passar a duas linhas. Abaixo de
   certa medida deixam de caber: os painéis comem a peça, a barra
   quebra e o editor salta por baixo do rato a meio de um gesto.

   Isso não se descobre a olho. Descobre-se depois de meia hora de
   trabalho, quando um arrasto sai torto e não se percebe porquê.
   Daí o aviso: diz a medida que falta, e deixa continuar a quem
   quiser mesmo — não é uma porta fechada, é um aviso de trabalho.

   A página define window.EDITOR_MIN = {l, a, nome}.
   ============================================================ */
(function () {
  'use strict';
  var M = window.EDITOR_MIN;
  if (!M || !M.l) return;

  var CHAVE = 'editor.espaco.avancar';
  // Continuar mesmo assim vale para a sessão de trabalho, não para sempre:
  // amanhã, noutro ecrã, o aviso volta a ser informação nova.
  function jaAvancou() { try { return sessionStorage.getItem(CHAVE) === '1'; } catch (e) { return false; } }
  function marcarAvancou() { try { sessionStorage.setItem(CHAVE, '1'); } catch (e) {} }

  function falta() {
    var l = window.innerWidth, a = window.innerHeight;
    if (l >= M.l && a >= M.a) return null;
    return { l: l, a: a, estreito: l < M.l, baixo: a < M.a };
  }

  var caixa = null, chip = null;

  function montarCaixa() {
    if (caixa) return caixa;
    caixa = document.createElement('div');
    caixa.className = 'esp-aviso';
    caixa.innerHTML =
      '<div class="esp-cartao" role="alertdialog" aria-labelledby="esp-tit">' +
        '<h2 id="esp-tit">Este ecrã é pequeno para o editor</h2>' +
        '<p class="esp-med"></p>' +
        '<p>O editor precisa de <b>' + M.l + ' × ' + M.a + '</b> para as ferramentas, a peça e os ' +
          'painéis caberem lado a lado. Mais apertado do que isto, os painéis comem a peça e a barra ' +
          'de cima passa a duas linhas — o editor salta por baixo do rato a meio de um gesto, e o que ' +
          'se vê deixa de ser o que sai impresso.</p>' +
        '<p class="esp-sug">Aumente a janela, rode o tablet, ou volte a abrir num ecrã maior. ' +
          'Se estiver com o navegador em meia janela, este é o momento de a alargar.</p>' +
        '<div class="esp-bt">' +
          '<button type="button" class="esp-ok">Continuar mesmo assim</button>' +
          '<a class="esp-volta" href="index.php">Voltar ao painel</a>' +
        '</div>' +
      '</div>';
    caixa.querySelector('.esp-ok').addEventListener('click', function () {
      marcarAvancou(); rever();
    });
    document.body.appendChild(caixa);
    return caixa;
  }

  /** Marca discreta na barra de estado, para quem escolheu continuar. */
  function montarChip() {
    if (chip) return chip;
    var barra = document.querySelector('.ed-estado');
    chip = document.createElement('span');
    chip.className = 'esp-chip';
    chip.title = 'O editor está mais apertado do que o recomendado (' + M.l + ' × ' + M.a + ').';
    chip.textContent = '⚠ ecrã apertado';
    chip.addEventListener('click', function () {
      // Voltar a ver o aviso por inteiro: quem continuou pode querer relê-lo.
      try { sessionStorage.removeItem(CHAVE); } catch (e) {}
      rever();
    });
    // À cabeça da barra, e não no fim: a barra de estado é uma linha só, com
    // overflow escondido — no fim, a marca era a primeira coisa a ser cortada,
    // que é o contrário de um aviso.
    if (barra) barra.insertBefore(chip, barra.firstChild); else document.body.appendChild(chip);
    return chip;
  }

  function rever() {
    var f = falta();
    if (!f) {                                   // já cabe: sai tudo da frente
      if (caixa) caixa.classList.remove('on');
      if (chip) chip.classList.remove('on');
      document.body.classList.remove('esp-travado');
      return;
    }
    var med = f.estreito && f.baixo ? 'largura e altura'
            : (f.estreito ? 'largura' : 'altura');
    montarChip().classList.add('on');
    if (jaAvancou()) {
      if (caixa) caixa.classList.remove('on');
      document.body.classList.remove('esp-travado');
      return;
    }
    var c = montarCaixa();
    c.querySelector('.esp-med').innerHTML =
      'Tem <b>' + f.l + ' × ' + f.a + '</b>. Falta ' + med + '.';
    c.classList.add('on');
    document.body.classList.add('esp-travado');
  }

  var t = null;
  window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(rever, 160); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', rever);
  else rever();

  window.editorEspacoRever = rever;   // para as provas e para quem precise
})();
