/* ============================================================
   tela-livre.js — Posicionamento livre com alinhamento magnético
   Partilhado pelo editor do cartão impresso e pelo do convite
   digital (onde corre DENTRO da tela, que é um iframe).

   O que faz: arrastar um bloco pela própria peça, guardando o
   deslocamento em PERCENTAGEM da tela — nunca em pixels. É o que
   permite compor o cartão a 33% de zoom e a composição sair igual
   na gráfica, e compor o envelope no computador e ele chegar
   inteiro ao telemóvel.

   O alinhamento magnético não é uma grelha: são as linhas que já
   existem na peça — o centro e as bordas da tela, e as bordas e os
   centros dos outros blocos. Um bloco "cola" quando um dos seus
   pontos passa a menos de TOL da linha de outro, e a guia acende-se
   só nesse momento: guias sempre acesas ensinam o olho a ignorá-las.
   O Shift desliga o íman, para o ajuste fino.
   ============================================================ */
(function (raizGlobal) {
  'use strict';

  /** Distância, em % da tela, a que um ponto se cola a uma linha. */
  var TOL = 1.0;
  /** Limite do deslocamento, em % — o mesmo do servidor (POS_LIMITE). */
  var LIMITE = 60;

  function limitar(n) { return Math.max(-LIMITE, Math.min(LIMITE, n)); }
  function arred(n) { return Math.round(n * 100) / 100; }

  /**
   * Retângulo de um elemento em % da tela, já descontado o deslocamento
   * que ele tem agora. É a posição "de origem" — a que o design lhe deu —
   * e é contra ela que se calculam as linhas de alinhamento.
   */
  function caixaBase(el, tela, pos) {
    var r = el.getBoundingClientRect(), t = tela.getBoundingClientRect();
    if (!t.width || !t.height) return null;
    var esq = (r.left - t.left) / t.width * 100 - pos.x;
    var top = (r.top - t.top) / t.height * 100 - pos.y;
    var l = r.width / t.width * 100, a = r.height / t.height * 100;
    return { e: esq, c: esq + l / 2, d: esq + l, t: top, m: top + a / 2, b: top + a, l: l, a: a };
  }

  /**
   * Encontra o encosto de um eixo: entre os três pontos do bloco
   * (início, meio, fim) e todas as linhas conhecidas, qual o par mais
   * próximo. Devolve a correção a somar ao deslocamento, e a linha onde
   * a guia se acende. Sem nada por perto, devolve null.
   */
  function encostar(pontos, linhas, desloc) {
    var melhor = null;
    for (var i = 0; i < pontos.length; i++) {
      for (var j = 0; j < linhas.length; j++) {
        var d = linhas[j] - (pontos[i] + desloc);
        if (Math.abs(d) > TOL) continue;
        if (!melhor || Math.abs(d) < Math.abs(melhor.d)) melhor = { d: d, linha: linhas[j] };
      }
    }
    return melhor;
  }

  /** Linhas únicas e ordenadas (evita comparar dez vezes a mesma). */
  function juntar(lista) {
    var visto = {}, fora = [];
    for (var i = 0; i < lista.length; i++) {
      var k = Math.round(lista[i] * 100) / 100;
      if (visto[k]) continue;
      visto[k] = 1; fora.push(k);
    }
    return fora;
  }

  /**
   * Liga o arrasto numa tela.
   *
   * op = {
   *   tela,                  // o elemento que É a peça (a caixa de referência)
   *   blocos: () => [{id, el}],   // o que se pode mover, agora
   *   pos: id => ({x,y}),         // deslocamento gravado, em %
   *   trancado: id => bool,       // camadas trancadas não se mexem
   *   mover: (id,x,y) => {},      // aplicar ao vivo (durante o arrasto)
   *   largar: (id,x,y) => {},     // fim do gesto: é aqui que se regista o passo
   *   pegar: id => {},            // opcional: seleciona a camada ao começar
   *   guias,                      // opcional: elemento onde as guias acendem
   *   ativo: () => bool           // opcional: false desliga o gesto
   * }
   */
  function ligar(op) {
    var g = null;   // gesto em curso

    function podeMover() { return !op.ativo || op.ativo(); }

    function acender(gx, gy) {
      if (!op.guias) return;
      if (gx === null) op.guias.classList.remove('v');
      else { op.guias.style.setProperty('--gx', gx + '%'); op.guias.classList.add('v'); }
      if (gy === null) op.guias.classList.remove('h');
      else { op.guias.style.setProperty('--gy', gy + '%'); op.guias.classList.add('h'); }
    }
    function apagar() { if (op.guias) op.guias.classList.remove('v', 'h'); }

    function comecar(e) {
      if (e.button !== undefined && e.button !== 0) return;
      if (!podeMover()) return;
      var blocos = op.blocos(), meu = null;
      for (var i = 0; i < blocos.length; i++) {
        if (blocos[i].el && blocos[i].el.contains(e.target)) {
          // O mais interior ganha: arrastar os nomes move os nomes, não o
          // bloco inteiro que os contém. Para mover o conjunto escolhe-se
          // ele na lista de camadas e arrasta-se pelo que ele tem de seu.
          if (!meu || meu.el.contains(blocos[i].el)) meu = blocos[i];
        }
      }
      if (!meu || op.trancado && op.trancado(meu.id)) return;

      var t = op.tela.getBoundingClientRect();
      if (!t.width || !t.height) return;
      var p = op.pos(meu.id) || { x: 0, y: 0 };
      var base = caixaBase(meu.el, op.tela, p);
      if (!base) return;

      // As linhas contra as quais este bloco se alinha: a tela (bordas,
      // centro e terços) e todos os outros blocos, onde eles estão agora.
      var lx = [0, 33.333, 50, 66.667, 100], ly = [0, 33.333, 50, 66.667, 100];
      for (var j = 0; j < blocos.length; j++) {
        if (blocos[j] === meu || !blocos[j].el) continue;
        // Dos outros conta a posição de agora (com o deslocamento deles já
        // aplicado): alinha-se com o que se vê, não com o que já não está lá.
        var c = caixaBase(blocos[j].el, op.tela, { x: 0, y: 0 });
        if (!c || (c.l <= 0 && c.a <= 0)) continue;
        lx.push(c.e, c.c, c.d); ly.push(c.t, c.m, c.b);
      }
      // O sítio de origem também é uma linha: voltar atrás tem de ser fácil.
      lx.push(base.e, base.c, base.d); ly.push(base.t, base.m, base.b);

      g = { id: meu.id, x0: p.x, y0: p.y, px: e.clientX, py: e.clientY,
            larg: t.width, alt: t.height, base: base,
            lx: juntar(lx), ly: juntar(ly), mexeu: false };
      if (op.pegar) op.pegar(meu.id);
      e.preventDefault();
    }

    function mover(e) {
      if (!g) return;
      var nx = limitar(g.x0 + (e.clientX - g.px) / g.larg * 100);
      var ny = limitar(g.y0 + (e.clientY - g.py) / g.alt * 100);
      if (!g.mexeu && Math.abs(nx - g.x0) < 0.05 && Math.abs(ny - g.y0) < 0.05) return;
      g.mexeu = true;

      var gx = null, gy = null;
      if (!e.shiftKey) {   // Shift = ajuste fino, sem íman
        var ex = encostar([g.base.e, g.base.c, g.base.d], g.lx, nx);
        var ey = encostar([g.base.t, g.base.m, g.base.b], g.ly, ny);
        if (ex) { nx = limitar(nx + ex.d); gx = ex.linha; }
        if (ey) { ny = limitar(ny + ey.d); gy = ey.linha; }
      }
      acender(gx, gy);
      g.ux = arred(nx); g.uy = arred(ny);
      op.mover(g.id, g.ux, g.uy);
    }

    function largar() {
      if (!g) return;
      var f = g; g = null; apagar();
      if (f.mexeu && op.largar) op.largar(f.id, f.ux, f.uy);
    }

    op.tela.addEventListener('pointerdown', comecar);
    // O rato sai da tela a meio do gesto mais vezes do que se imagina: os
    // ouvintes vivem na janela, senão o bloco fica preso a meio caminho.
    window.addEventListener('pointermove', mover);
    window.addEventListener('pointerup', largar);
    window.addEventListener('pointercancel', largar);

    return {
      desligar: function () {
        op.tela.removeEventListener('pointerdown', comecar);
        window.removeEventListener('pointermove', mover);
        window.removeEventListener('pointerup', largar);
        window.removeEventListener('pointercancel', largar);
        apagar();
      },
      emCurso: function () { return !!g; }
    };
  }

  raizGlobal.TelaLivre = { ligar: ligar, limitar: limitar, arred: arred, TOL: TOL, LIMITE: LIMITE };
})(window);
