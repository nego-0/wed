/* ============================================================
   bar-entrega.js — O posto do empregado (entregas.php)

   Uma coluna, três filas e um botão por pedido. Quem usa isto atravessa o
   salão com um tabuleiro: cada acção tem de caber num polegar e não pode
   pedir confirmação nenhuma no caminho normal.

   As filas estão pela ordem em que a noite acontece:
     1. Comigo      — os que já apanhei. Falta pousá-los.
     2. Por apanhar — os que a copa aprovou e esperam por alguém.
     3. Voltaram    — os que não encontraram ninguém na mesa.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var PODE = !window.SO_VER_UI;
  var EST = null, EU = '';
  var desvio = 0;

  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }
  function toast(m, mau) {
    var t = $('toast'); if (!t) return;
    t.textContent = m; t.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(function () { t.className = 'toast'; }, 2600);
  }
  window.toast = toast;

  function foto(i) {
    if (i.foto) return '<div class="b-foto"><img src="' + esc(i.foto) + '" alt="" loading="lazy"></div>';
    return '<div class="b-foto"><span class="letra">'
      + esc((i.nome || '?').trim().charAt(0).toUpperCase()) + '</span></div>';
  }

  function ha(quando) {
    if (!quando) return '';
    var t = Date.parse(String(quando).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var s = Math.max(0, Math.round((Date.now() + desvio - t) / 1000));
    if (s < 60) return 'agora mesmo';
    var m = Math.round(s / 60);
    if (m < 60) return 'há ' + m + ' min';
    return 'há ' + Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0');
  }

  /** Segundos em palavras curtas: «2 min», «45 s». */
  function tempo(s) {
    if (s === null || s === undefined) return '–';
    if (s < 90) return Math.round(s) + ' s';
    return Math.round(s / 60) + ' min';
  }

  // ---- carregar ------------------------------------------------
  async function carregar(silencioso) {
    var d = await window.api('bar_entrega_lista', { method: 'GET', silencioso: !!silencioso });
    var sinal = $('b-sinal');
    if (!d || !d.success) {
      sinal.className = 'b-sinal'; sinal.textContent = 'sem ligação';
      return;
    }
    EST = d; EU = d.eu || '';
    sinal.className = 'b-sinal on'; sinal.textContent = 'ligado';
    pintar();
  }

  function pintar() {
    var ps = EST.pedidos || [];
    // «Meu» é o que eu apanhei: dois empregados no mesmo salão não podem
    // estar a ver a mesma lista como se fosse de ambos.
    var minhas   = ps.filter(function (p) { return p.estado === 'a_caminho' && p.entregue_por === EU; });
    var doOutro  = ps.filter(function (p) { return p.estado === 'a_caminho' && p.entregue_por !== EU; });
    var espera   = ps.filter(function (p) { return p.estado === 'aprovado'; });
    var voltaram = ps.filter(function (p) { return p.estado === 'falhou'; });

    $('k-minhas').textContent = minhas.length;
    $('k-espera').textContent = espera.length;
    $('k-entregues').textContent = (EST.estado && EST.estado.entregues) || 0;

    var html = '';
    // A nota da secção só faz sentido quando há lá alguma coisa: «pouse-os e
    // carregue em Entregue» por cima de «nada nas mãos» é uma ordem sem objecto.
    html += seccao('Comigo', minhas, 'Nada nas mãos. Apanhe um da fila de baixo.',
                   minhas.length ? 'pouse-os e carregue em Entregue' : '');
    html += seccao('Por apanhar', espera, 'A copa não tem nada aprovado à espera.',
                   espera.length ? 'os mais antigos primeiro' : '');
    if (voltaram.length) {
      html += seccao('Voltaram', voltaram, '',
                     'ninguém estava na mesa — tente outra vez ou devolva à copa');
    }
    if (doOutro.length) {
      html += '<div class="b-secao">Com outros<small>' + doOutro.length
        + (doOutro.length === 1 ? ' pedido' : ' pedidos') + ' a caminho</small></div>'
        + doOutro.map(function (p) { return cartao(p, true); }).join('');
    }
    $('b-listas').innerHTML = html;
    pintarTempos();
  }

  function seccao(titulo, ps, vazio, nota) {
    var h = '<div class="b-secao">' + esc(titulo)
      + (nota ? '<small>' + esc(nota) + '</small>' : '') + '</div>';
    if (!ps.length) {
      return vazio ? h + '<div class="b-cartao b-vazio">' + esc(vazio) + '</div>' : '';
    }
    return h + ps.map(function (p) { return cartao(p, false); }).join('');
  }

  function cartao(p, deOutro) {
    var linhas = (p.itens || []).map(function (l) {
      return '<span class="b-linha">' + foto(l) + '<b>' + l.quantidade + '×</b> '
        + esc(l.nome) + '</span>';
    }).join('');
    // A mesa é a informação que faz andar: primeiro grande, depois o resto.
    var onde = p.mesa ? 'Mesa ' + esc(p.mesa) : '<b>Sem mesa</b> — pergunte na copa';
    if (p.mesa_qr && p.mesa && p.mesa_qr !== p.mesa) {
      onde += ' <span style="opacity:.7">(pediu na ' + esc(p.mesa_qr) + ')</span>';
    }
    // Quem entrega precisa de saber que a bebida é de outra pessoa: bate-se à
    // mesa e diz-se o nome de quem a vai beber, não o de quem a pediu.
    if (p.pedido_por) {
      onde += ' <span style="opacity:.7">(pedido por ' + esc(p.pedido_por) + ')</span>';
    }
    return '<div class="b-cartao b-ped' + (p.estado === 'a_caminho' && !deOutro ? ' minha' : '') + '">'
      + '<div class="b-ped-topo">'
      +   '<span class="cod">' + esc(p.codigo) + '</span>'
      +   '<span class="quem">' + esc(p.convidado || 'Sem nome') + '</span>'
      +   '<span class="ha">' + esc(ha(p.decidido_em || p.criado_em)) + '</span>'
      + '</div>'
      + '<div class="onde">' + onde + '</div>'
      + '<div class="b-linhas">' + linhas + '</div>'
      + (p.motivo ? '<div class="onde">Da última vez: ' + esc(p.motivo) + '</div>' : '')
      + (deOutro
          ? '<div class="onde">Com ' + esc(p.entregue_por || 'alguém') + '</div>'
          : (PODE ? acoes(p) : ''))
      + '</div>';
  }

  function acoes(p) {
    if (p.estado === 'a_caminho') {
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro b-bt-grande" onclick="entEntregue(' + p.id + ')">'
        +   'Entregue</button>'
        + '<button class="btn btn-fantasma" onclick="entFalhou(' + p.id + ')">'
        +   'Não estava na mesa</button></div>';
    }
    // Aprovado ou de volta: apanhar é o passo seguinte, e entregar directo
    // existe para quem já tem a bebida na mão quando carrega.
    return '<div class="b-acoes">'
      + '<button class="btn btn-ouro b-bt-grande" onclick="entApanhar(' + p.id + ')">Apanhar</button>'
      + '<button class="btn btn-fantasma" onclick="entEntregue(' + p.id + ')">Já entreguei</button>'
      + '</div>';
  }

  // ---- as três acções -------------------------------------------
  window.entApanhar = async function (id) {
    var d = await window.api('bar_apanhar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('É seu. Boa viagem.');
    await carregar(true);
  };

  window.entEntregue = async function (id) {
    // Sem confirmação: é a acção que se faz cem vezes por noite, e uma
    // pergunta a meio do salão com um tabuleiro na mão é um pedido caído.
    var d = await window.api('bar_entregue', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Entregue. O stock já desceu.');
    await carregar(true);
  };

  window.entFalhou = function (id) {
    licFormulario({
      titulo: 'Não estava na mesa',
      guardar: 'Devolver à copa',
      dica: 'A bebida volta ao stock disponível e o pedido fica à espera. '
          + 'Diga o que viu — a copa decide se tenta outra vez.',
      campos: [{ id: 'motivo_texto', rot: 'O que aconteceu', tipo: 'text', valor: '',
                 dica: 'Ex.: «mesa vazia», «ninguém deu pelo nome».' }],
      aoGuardar: async function (v) {
        var d = await window.api('bar_falhou', { method: 'POST',
          body: JSON.stringify({ id: id, motivo_texto: v.motivo_texto }) });
        if (!d || !d.success) return false;
        toast('Devolvido à copa.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- os tempos da noite ----------------------------------------
  function pintarTempos() {
    var t = EST.tempos || {};
    var cx = $('b-tempos');
    if (!t.n) { cx.hidden = true; return; }
    cx.hidden = false;
    cx.innerHTML = [
      ['Da copa até si', t.recolha],
      ['No percurso', t.percurso],
      ['Do pedido à mesa', t.total]
    ].map(function (par) {
      return '<div><div class="n">' + esc(tempo(par[1])) + '</div>'
        + '<div class="l">' + esc(par[0]) + '</div></div>';
    }).join('')
      + '<div><div class="n">' + t.n + '</div><div class="l">entregues</div></div>';
  }

  // ---- pedir por um convidado sem rede ---------------------------
  var ppEscolhido = null, ppEspera = null;

  window.entPedirPor = async function () {
    // O menu não vem nesta leitura: pede-se só quando é preciso, que é raro.
    var e = await window.api('bar_estado', { method: 'GET', silencioso: true });
    var itens = (e && e.success ? e.itens : []).filter(function (i) {
      return i.estado === 'ativo' && i.disponivel > 0;
    });
    if (!itens.length) { toast('Não há nada disponível para pedir.', true); return; }
    licFormulario({
      titulo: 'Pedir por um convidado',
      guardar: 'Lançar o pedido',
      largo: true,
      dica: 'Escreva parte do nome, escolha a pessoa, e depois a bebida. '
          + 'O pedido vai à copa como qualquer outro.',
      campos: [
        { id: 'nome', rot: 'Nome do convidado', tipo: 'text', valor: '', largura: 2,
          dica: '<span id="pp-achados"></span>' },
        { id: 'item', rot: 'Bebida', tipo: 'escolha', valor: String(itens[0].id),
          opcoes: itens.map(function (i) {
            return { v: String(i.id), r: i.nome + ' (' + i.disponivel + ')' };
          }) },
        { id: 'quantidade', rot: 'Quantas', tipo: 'numero', valor: 1, min: 1, max: 12 }
      ],
      aoGuardar: async function (v) {
        if (!ppEscolhido) { licJanelaErro('Escolha o convidado na lista.'); return false; }
        var d = await window.api('bar_pedir_por', { method: 'POST',
          body: JSON.stringify({ convidado_id: ppEscolhido.id,
                                 mesa_id: ppEscolhido.mesa_id,
                                 itens: [{ item_id: parseInt(v.item, 10),
                                           quantidade: parseInt(v.quantidade, 10) || 1 }] }) });
        if (!d || !d.success) return false;
        toast('Pedido ' + d.pedido.codigo + ' na fila da copa.');
        await carregar(true);
        return true;
      }
    });
    ppLigar();
  };

  function ppLigar() {
    ppEscolhido = null;
    var cx = document.getElementById('lf-nome');
    if (!cx) return;
    cx.setAttribute('autocomplete', 'off');
    cx.addEventListener('input', function () {
      ppEscolhido = null;
      clearTimeout(ppEspera);
      ppEspera = setTimeout(ppProcurar, 220);
    });
  }

  async function ppProcurar() {
    var cx = document.getElementById('lf-nome');
    var saida = document.getElementById('pp-achados');
    if (!cx || !saida) return;
    var termo = cx.value.trim();
    if (termo.length < 2) { saida.textContent = 'Escreva pelo menos duas letras.'; return; }
    var d = await window.api('bar_procurar_pessoal&q=' + encodeURIComponent(termo),
                             { method: 'GET', silencioso: true });
    if (!d || !d.success) { saida.textContent = 'Não deu para procurar.'; return; }
    if (!d.nomes.length) { saida.textContent = 'Ninguém com esse nome.'; return; }
    window.__ppNomes = d.nomes;
    saida.innerHTML = d.nomes.slice(0, 6).map(function (n) {
      return '<button type="button" class="j-bt j-bt-nao" style="min-height:38px;'
        + 'padding:.2rem .7rem;margin:.2rem .25rem 0 0" onclick="entEscolher(' + n.id + ')">'
        + esc(n.nome) + (n.mesa ? ' · ' + esc(n.mesa) : '') + '</button>';
    }).join('');
  }

  window.entEscolher = function (id) {
    var n = (window.__ppNomes || []).filter(function (x) { return x.id === id; })[0];
    if (!n) return;
    ppEscolhido = n;
    var cx = document.getElementById('lf-nome');
    if (cx) cx.value = n.nome;
    var saida = document.getElementById('pp-achados');
    if (saida) saida.innerHTML = '<b>' + esc(n.nome) + '</b>'
      + (n.mesa ? ' — entrega na ' + esc(n.mesa) : ' — sem mesa marcada');
  };

  // ---- o relógio --------------------------------------------------
  carregar();
  setInterval(function () { if (!document.hidden) carregar(true); }, 8000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) carregar(true);
  });
})();
