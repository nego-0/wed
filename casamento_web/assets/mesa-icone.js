/**
 * O ícone de uma mesa: a forma, as cadeiras à volta, e o número lá dentro.
 *
 * É o desenho por que as plantas de sala se dizem em toda a parte — a mesa
 * vista de cima, com um traço por cada cadeira. Serve para o que um quadrado
 * colorido nunca serviu: olhar para uma lista de mesas e ver, sem ler, quais
 * são as grandes, quais as pequenas, e quais estão cheias.
 *
 * O número ao centro é a LOTAÇÃO quando ela se conhece («7/10»), e a
 * CAPACIDADE quando não há ninguém sentado ainda («10»). Uma mesa sem
 * capacidade definida não mostra número nenhum — inventar um seria pior do que
 * não ter.
 *
 * As cadeiras desenham-se onde uma mesa daquela forma as tem de verdade:
 * à volta, numa redonda; nos lados compridos e nas pontas, numa retangular;
 * por fora do U, numa ferradura. Um ícone que põe cadeiras onde elas não
 * cabem deixa de ser um plano e passa a ser um enfeite.
 */
(function (global) {
  'use strict';

  var TAU = Math.PI * 2;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g,
      function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
  }

  /** Uma cadeira: um rectângulo de cantos redondos, virado para a mesa. */
  function cadeira(x, y, ang, cls) {
    var w = 7, h = 5.4;
    return '<rect class="mi-c' + (cls ? ' ' + cls : '') + '"'
         + ' x="' + (x - w / 2).toFixed(2) + '" y="' + (y - h / 2).toFixed(2) + '"'
         + ' width="' + w + '" height="' + h + '" rx="1.6"'
         + ' transform="rotate(' + ang.toFixed(1) + ' ' + x.toFixed(2) + ' ' + y.toFixed(2) + ')"/>';
  }

  /** Cadeiras em volta de uma elipse — a redonda e a oval. */
  function emVolta(cx, cy, rx, ry, n, folga) {
    var out = '', i, a, x, y;
    for (i = 0; i < n; i++) {
      a = -TAU / 4 + (i / n) * TAU;                 // começa em cima
      x = cx + Math.cos(a) * (rx + folga);
      y = cy + Math.sin(a) * (ry + folga);
      out += cadeira(x, y, a * 180 / Math.PI, '');
    }
    return out;
  }

  /**
   * Cadeiras à volta de um rectângulo, repartidas como na vida: primeiro os
   * dois lados compridos (é onde cabem), e as pontas só quando sobra gente.
   */
  function emRetangulo(x0, y0, w, h, n, folga) {
    if (n <= 0) return '';
    var pontas = n >= 6 ? 2 : (n >= 3 ? (n % 2) : 0);
    if (pontas > 0 && n - pontas < 2) pontas = 0;
    var lados = n - pontas;
    var cima = Math.ceil(lados / 2), baixo = lados - cima;
    var out = '', i, passo;

    passo = w / (cima + 1);
    for (i = 0; i < cima; i++) out += cadeira(x0 + passo * (i + 1), y0 - folga, -90, '');
    passo = w / (baixo + 1);
    for (i = 0; i < baixo; i++) out += cadeira(x0 + passo * (i + 1), y0 + h + folga, 90, '');
    if (pontas >= 1) out += cadeira(x0 - folga, y0 + h / 2, 180, '');
    if (pontas >= 2) out += cadeira(x0 + w + folga, y0 + h / 2, 0, '');
    return out;
  }

  /** Cadeiras pelo lado de fora de um U. */
  function emFerradura(x0, y0, w, h, n, folga) {
    if (n <= 0) return '';
    var out = '', i;
    var lados = Math.min(n, 2 * Math.max(1, Math.round((n - 1) / 3)));
    var porLado = Math.max(1, Math.round(lados / 2));
    var emBaixo = Math.max(0, n - porLado * 2);
    var passo = h / (porLado + 1);
    for (i = 0; i < porLado; i++) {
      out += cadeira(x0 - folga, y0 + passo * (i + 1), 180, '');
      out += cadeira(x0 + w + folga, y0 + passo * (i + 1), 0, '');
    }
    passo = w / (emBaixo + 1);
    for (i = 0; i < emBaixo; i++) out += cadeira(x0 + passo * (i + 1), y0 + h + folga, 90, '');
    return out;
  }

  var FORMAS = {
    redonda:    { tampo: '<circle class="mi-t" cx="50" cy="50" r="20"/>', ny: 50,
                  volta: function (n) { return emVolta(50, 50, 20, 20, n, 8); } },
    oval:       { tampo: '<ellipse class="mi-t" cx="50" cy="50" rx="27" ry="16"/>', ny: 50,
                  volta: function (n) { return emVolta(50, 50, 27, 16, n, 8); } },
    quadrada:   { tampo: '<rect class="mi-t" x="34" y="34" width="32" height="32" rx="3"/>', ny: 50,
                  volta: function (n) { return emRetangulo(34, 34, 32, 32, n, 8); } },
    retangular: { tampo: '<rect class="mi-t" x="26" y="36" width="48" height="28" rx="3"/>', ny: 50,
                  volta: function (n) { return emRetangulo(26, 36, 48, 28, n, 8); } },
    comprida:   { tampo: '<rect class="mi-t" x="18" y="40" width="64" height="20" rx="3"/>', ny: 50,
                  volta: function (n) { return emRetangulo(18, 40, 64, 20, n, 8); } },
    // O número desce para a barra de baixo do U: o centro geométrico da
    // ferradura cai no vão, e o número saía por cima do vazio.
    ferradura:  { tampo: '<path class="mi-t" d="M26 30 h13 v30 h22 v-30 h13 v44 h-48 z"/>', ny: 67,
                  volta: function (n) { return emFerradura(26, 30, 48, 44, n, 8); } },
    // A mesa dos noivos não é uma mesa como as outras: é sempre a mesma, e o
    // que dela interessa não é quantos cabem — é que é dos noivos.
    noivos:     { tampo: '<rect class="mi-t" x="24" y="40" width="52" height="20" rx="3"/>', ny: 50,
                  volta: function () { return cadeira(41, 32, -90, '') + cadeira(59, 32, -90, ''); } },
  };

  /**
   * Desenha o ícone.
   *
   * @param m.forma      redonda|oval|quadrada|retangular|comprida|ferradura|noivos
   * @param m.capacidade quantos lugares tem (0/vazio = por definir)
   * @param m.ocupacao   quantos já lá estão sentados
   * @param m.rotulo     texto do title (por omissão, monta-se um)
   * @param opc.tam      lado do ícone em px (por omissão 44)
   * @param opc.numero   false para não escrever número nenhum
   */
  function mesaIcone(m, opc) {
    m = m || {}; opc = opc || {};
    var forma = FORMAS[m.forma] ? m.forma : (m.especial === 'noivos' ? 'noivos' : 'redonda');
    var F = FORMAS[forma];
    var cap = Math.max(0, parseInt(m.capacidade, 10) || 0);
    var oc  = Math.max(0, parseInt(m.ocupacao, 10) || 0);

    // Quantas cadeiras se desenham. Sem capacidade definida, desenha-se pelo
    // que já lá está sentado; sem nada, uma silhueta com quatro, que é o que
    // uma mesa parece. Acima de 14 o desenho deixa de se ler, e o número passa
    // a fazer o trabalho todo.
    var n = cap || oc || 4;
    var desenhadas = Math.min(n, 14);

    var cheia = cap > 0 && oc >= cap;
    var vazia = oc === 0;
    var num = '';
    if (opc.numero !== false && forma !== 'noivos') {
      if (cap > 0 && oc > 0) num = oc + '/' + cap;
      else if (cap > 0)      num = String(cap);
      else if (oc > 0)       num = String(oc);
    }
    // Duas linhas de texto não cabem: com «12/12» encolhe-se a letra.
    var fs = num.length >= 5 ? 13 : num.length >= 4 ? 15 : 18;

    var titulo = m.rotulo != null ? m.rotulo
      : (m.nome ? m.nome + ' · ' : '')
        + (cap > 0 ? oc + ' de ' + cap + ' lugares' : oc + ' lugar(es)');

    return '<svg class="mesa-ico' + (cheia ? ' cheia' : '') + (vazia ? ' vazia' : '')
         + ' f-' + forma + '" viewBox="0 0 100 100" width="' + (opc.tam || 44) + '"'
         + ' height="' + (opc.tam || 44) + '" role="img" aria-label="' + esc(titulo) + '">'
         + '<title>' + esc(titulo) + '</title>'
         + F.volta(desenhadas)
         + F.tampo
         + (num ? '<text class="mi-n" x="50" y="' + (F.ny || 50) + '" text-anchor="middle"'
                + ' dominant-baseline="central" font-size="' + fs + '">' + esc(num) + '</text>' : '')
         + '</svg>';
  }

  mesaIcone.formas = Object.keys(FORMAS).filter(function (k) { return k !== 'noivos'; });

  /**
   * Onde acaba o desenho, em unidades do viewBox (0..100).
   *
   * Quem escreve o nome da mesa por baixo dela precisa de saber onde ela acaba
   * — e cada forma acaba num sítio diferente. Um valor único deixava o nome
   * colado à redonda e a pairar longe da comprida, que é mais achatada. Conta o
   * tampo, a folga das cadeiras (8) e meia cadeira (2.7).
   */
  var FUNDOS = { redonda:80.7, oval:76.7, quadrada:76.7, retangular:74.7,
                 comprida:70.7, ferradura:84.7, noivos:60 };
  mesaIcone.fundo = function (forma) { return FUNDOS[forma] || 80.7; };

  global.mesaIcone = mesaIcone;
})(window);
