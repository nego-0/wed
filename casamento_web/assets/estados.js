/* ============================================================
   estados.js — o que se mostra enquanto não há nada para mostrar

   Duas peças, e as duas respondem à mesma pergunta: «o que é que a pessoa vê
   quando a lista não tem linhas?»

   1. O ESQUELETO, enquanto o servidor não responde. A casa tinha vinte e tal
      sítios a escrever «A carregar…» numa linha de texto cinzenta, e o painel
      — a página onde se passa o tempo todo — não tinha sequer isso: ficava em
      branco e depois aparecia tudo de uma vez. Um branco não diz se está a
      pensar ou se avariou. O esqueleto diz as duas coisas que faltam: que já
      está a caminho, e que feitio vai ter quando chegar. Por isso tem a
      ALTURA das linhas verdadeiras — um esqueleto de outra altura é um salto
      de página disfarçado de cortesia.

   2. O VAZIO, quando a resposta chega e não traz nada. Nunca é só «não há
      nada»: diz o que falta, porquê, e — quando existe — dá o gesto que o
      resolve. E um vazio por causa de um filtro tem de o confessar, senão
      lê-se como «está tudo vazio» e a pessoa vai criar o que já lá está.

   A assinatura do vazio é a mesma que o módulo do bar já usava (bar-pecas.js),
   de propósito: duas peças com o mesmo nome e ordem diferente dos argumentos
   é uma armadilha à espera.
   ============================================================ */
(function (raiz) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function icone(nome) {
    // Os ícones são vestidos por icones.js — aqui só se deixa o lugar, para
    // isto funcionar mesmo nas páginas que ainda não o carregam.
    return nome ? '<div class="ico" data-ico="' + esc(nome) + '"></div>' : '';
  }

  /**
   * O esqueleto de uma lista.
   *
   * @param {number} n      quantas linhas (o que cabe no ecrã, não mais: um
   *                        esqueleto de trinta linhas é uma página a tremer).
   * @param {number} [alt]  a altura de UMA linha verdadeira, em pixéis.
   * @param {string} [cls]  'grelha' quando o que vem é uma grelha de cartões
   *                        e não uma pilha de linhas.
   *
   * As barras levam `aria-hidden`: são desenho, e um leitor de ecrã a soletrar
   * seis caixas vazias é pior do que o silêncio. Quem ouve recebe a frase.
   */
  function esqueleto(n, alt, cls) {
    var linhas = '';
    for (var i = 0; i < (n || 4); i++) {
      linhas += '<div class="esqueleto esq-linha" aria-hidden="true"'
        + (alt ? ' style="height:' + (+alt) + 'px"' : '') + '></div>';
    }
    return '<div class="esq-lista' + (cls === 'grelha' ? ' esq-grelha' : '') + '">'
      + '<span class="so-leitor">A carregar…</span>' + linhas + '</div>';
  }

  /**
   * O estado vazio.
   *
   * @param {string} icone  nome do ícone (assets/icones.js)
   * @param {string} titulo a frase curta: o que não há
   * @param {string} texto  porquê, e o que fazer a seguir
   * @param {string} [botao] HTML do gesto que resolve, quando existe um
   */
  function vazio(ico, titulo, texto, botao) {
    // O texto vai num <p> seu: sem ele, o botão é um irmão inline do texto e
    // encosta-se ao fim da última linha em vez de ficar por baixo, centrado.
    return '<div class="vazio">' + icone(ico)
      + '<b>' + esc(titulo) + '</b>'
      + '<p>' + esc(texto) + '</p>' + (botao || '') + '</div>';
  }

  raiz.EST = { esqueleto: esqueleto, vazio: vazio };
})(window);
