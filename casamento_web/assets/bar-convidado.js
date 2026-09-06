/* ============================================================
   bar-convidado.js — O menu na mão de quem está à mesa

   Três ecrãs, um de cada vez, na mesma página:

     1. «Quem é?»  — a caixa de procura. Só se sai dela escolhendo um nome.
     2. O menu     — as gavetas, as bebidas, o cesto no rodapé.
     3. Os pedidos — o que já se pediu e em que pé está.

   O 2 e o 3 vivem juntos: quem já pediu quer ver o menu outra vez sem perder
   de vista o pedido anterior.

   Sem sessão e sem CSRF: a chave é o token da mesa, que viaja em todos os
   pedidos. Ver docs/modulo-bar.md §5.
   ============================================================ */
(function () {
  'use strict';

  var TOKEN = window.BAR.token;
  var MESA  = window.BAR.mesa;          // a mesa do QR — onde a folha está pousada
  var eu = null;                        // quem este telemóvel é
  var mesaEntrega = MESA;               // para onde vai a bebida (pode mudar-se)
  var aberto = false, msgFechado = window.BAR.fechado || '';
  var procuraMin = 4;
  var menu = { categorias: [], itens: [] };
  var ritmo = null;          // o caudal da copa, quando está cheio
  var travaoPedido = null;   // «o próximo pedido abre em…»
  var cesto = {};                       // item_id -> quantidade
  var meus = [];
  var relogio = null, procuraEspera = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ---- a conversa com o servidor ----------------------------
  // O token vai sempre: é ele que diz de que casamento se trata.
  async function chamar(accao, corpo, extra) {
    var url = 'api.php?action=' + encodeURIComponent(accao) + '&m=' + encodeURIComponent(TOKEN);
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        url += '&' + k + '=' + encodeURIComponent(extra[k]);
      });
    }
    var opc = { headers: { 'Accept': 'application/json' } };
    if (corpo !== undefined) {
      corpo.m = TOKEN;
      opc.method = 'POST';
      opc.headers['Content-Type'] = 'application/json';
      opc.body = JSON.stringify(corpo);
    }
    var r, d;
    try { r = await fetch(url, opc); d = await r.json(); }
    catch (e) { return { success: false, error: 'Sem rede. Tente outra vez daqui a pouco.' }; }
    return d || { success: false, error: 'Resposta estranha do servidor.' };
  }

  function falhou(d) {
    // Um erro do bar não é uma catástrofe: diz-se o que é e deixa-se tentar
    // outra vez. Chamar um empregado é sempre a saída que resta.
    return '<div class="b-erro"><span>' + esc(d && d.error ? d.error : 'Não deu.') + '</span>'
      + '<button class="btn btn-claro" onclick="barRecarregar()">Tentar de novo</button></div>';
  }

  // ---- 1. quem é? -------------------------------------------
  function ecraProcura(aviso) {
    $('b-corpo').innerHTML =
      '<div class="b-procura">'
      + '<h1>Quem está a pedir?</h1>'
      + '<p>Escreva as primeiras letras do seu nome — pelo menos ' + procuraMin
      + ' — e escolha-se na lista.</p>'
      + '<input id="b-q" type="search" autocomplete="off" autocapitalize="words" '
      +   'spellcheck="false" placeholder="O seu nome" aria-label="Procurar o seu nome">'
      + '<div class="b-nomes" id="b-nomes" role="listbox" aria-label="Nomes encontrados"></div>'
      + (aviso ? '<div class="b-ajuda">' + esc(aviso) + '</div>' : '')
      + '<div class="b-ajuda">O bar precisa de saber a quem entregar. '
      +   'Se não se encontrar na lista, chame um empregado — ele pede por si.</div>'
      + '</div>';
    var q = $('b-q');
    q.addEventListener('input', function () {
      clearTimeout(procuraEspera);
      procuraEspera = setTimeout(procurar, 220);
    });
    q.focus();
  }

  async function procurar() {
    var termo = $('b-q') ? $('b-q').value.trim() : '';
    var cx = $('b-nomes');
    if (!cx) return;
    if (termo.length < procuraMin) {
      // Dizer quantas faltam vale mais do que uma lista vazia sem explicação.
      var faltam = procuraMin - termo.length;
      cx.innerHTML = termo.length
        ? '<div class="b-ajuda" style="margin:0">Falta' + (faltam > 1 ? 'm ' : ' ') + faltam
          + (faltam > 1 ? ' letras.' : ' letra.') + '</div>'
        : '';
      return;
    }
    var d = await chamar('bar_procurar', undefined, { q: termo });
    if (!d.success) { cx.innerHTML = falhou(d); return; }
    if (!d.nomes.length) {
      cx.innerHTML = '<div class="b-ajuda" style="margin:0">Ninguém com esse nome. '
        + 'Experimente o apelido, ou chame um empregado.</div>';
      return;
    }
    cx.innerHTML = d.nomes.map(function (n) {
      return '<button class="b-nome" type="button" role="option" onclick="barSou(' + n.id + ')">'
        + '<b>' + esc(n.nome) + '</b><span>' + esc(n.convite) + '</span></button>';
    }).join('');
  }

  window.barSou = async function (id) {
    var d = await chamar('bar_sou', { convidado_id: id });
    if (!d.success) { $('b-nomes').innerHTML = falhou(d); return; }
    eu = d.eu;
    // Se a pessoa tem mesa marcada e não é a do QR, é dela que se parte: quem
    // se levantou para ir buscar o menu quer a bebida no seu lugar.
    if (eu.mesa_id && eu.mesa_id !== MESA.id) mesaEntrega = await mesaPorId(eu.mesa_id);
    await carregarMenu();
  };

  /** A mesa com o seu nome. Um «mesa 7» no ecrã é um número sem sentido para
      quem está sentado à «Mesa dos Padrinhos». */
  async function mesaPorId(id) {
    if (id === MESA.id) return MESA;
    var lista = await asMesas();
    var m = (lista || []).filter(function (x) { return x.id === id; })[0];
    return m ? { id: m.id, nome: m.nome } : { id: id, nome: null };
  }

  // ---- 2. o menu --------------------------------------------
  function foto(i) {
    if (i.foto) {
      return '<div class="b-foto"><img src="' + esc(i.foto) + '" alt="' + esc(i.nome) + '"'
        + (i.foto_pos ? ' style="object-position:' + esc(i.foto_pos) + '"' : '')
        + ' loading="lazy"></div>';
    }
    return '<div class="b-foto"><span class="letra" aria-hidden="true"'
      + (i.categoria_cor ? ' style="background:' + esc(i.categoria_cor) + '"' : '')
      + '>' + esc((i.nome || '?').charAt(0).toUpperCase()) + '</span></div>';
  }

  function pintarTopo() {
    $('b-eu').textContent = eu ? eu.nome : 'Bar';
    var bm = $('b-mesa');
    if (!eu) { bm.hidden = true; return; }
    bm.hidden = false;
    // «Entregar em X» e não «na X»: as mesas chamam-se «Noivos», «Padrinhos»,
    // «7» — e metade delas não casa com artigo nenhum.
    bm.innerHTML = '🍽 Entregar em <b>' + esc(nomeDaMesa()) + '</b> <span aria-hidden="true">▾</span>';
    bm.setAttribute('aria-label', 'Entregar na mesa ' + nomeDaMesa() + '. Tocar para mudar.');
  }

  function nomeDaMesa() {
    if (mesaEntrega && mesaEntrega.nome) return mesaEntrega.nome;
    if (mesaEntrega && mesaEntrega.id === MESA.id) return MESA.nome;
    return 'mesa ' + (mesaEntrega ? mesaEntrega.id : '?');
  }

  function pintarMenu() {
    if (!eu) return;
    var html = '';

    if (!aberto) {
      html += '<div class="b-nota"><b>A copa ainda não está a servir.</b><br>'
        + esc(msgFechado || 'Assim que abrir, pode pedir daqui mesmo — a página avisa sozinha.')
        + '</div>';
    }

    // O caudal da copa: não é a pessoa que pediu de mais, é a casa que está
    // cheia — e o texto tem de dizer isso, não repreender ninguém.
    if (ritmo) {
      html += '<div class="b-nota">' + esc(ritmo.mensagem
        || 'A copa está a dar vazão a muitos pedidos neste momento.')
        + (ritmo.espera_s > 0
            ? '<br>O seu abre em <b class="b-conta" data-ate="'
              + (Date.now() + ritmo.espera_s * 1000) + '">' + esc(hms(ritmo.espera_s))
              + '</b> — e fica na frente quando abrir.' : '')
        + '</div>';
    } else if (travaoPedido) {
      // O travão do acto de pedir é da pessoa, e trava a página inteira.
      html += '<div class="b-nota">' + esc(travaoPedido.texto)
        + (travaoPedido.espera_s > 0
            ? ' <b class="b-conta" data-ate="'
              + (Date.now() + travaoPedido.espera_s * 1000) + '">'
              + esc(hms(travaoPedido.espera_s)) + '</b>' : '')
        + '</div>';
    }

    if (!menu.itens.length) {
      html += '<div class="b-vazio"><span class="ico">🍹</span>O menu ainda não tem bebidas.</div>';
    } else {
      // Por gaveta, na ordem em que a copa as arrumou. Nenhuma bebida se
      // perde pelo caminho: o que não coube em gaveta nenhuma cai em
      // «Outras» — um menu que esconde metade das bebidas em silêncio é pior
      // do que um menu feio.
      var porMostrar = menu.itens.slice();
      menu.categorias.forEach(function (c) {
        var dela = porMostrar.filter(function (i) { return Number(i.categoria_id) === Number(c.id); });
        if (!dela.length) return;
        porMostrar = porMostrar.filter(function (i) { return dela.indexOf(i) < 0; });
        html += '<div class="b-gaveta">' + esc(c.nome) + '</div><div class="b-menu">'
          + dela.map(cartao).join('') + '</div>';
      });
      if (porMostrar.length) {
        html += '<div class="b-gaveta">Outras</div><div class="b-menu">'
          + porMostrar.map(cartao).join('') + '</div>';
      }
    }

    html += pintarMeus();
    $('b-corpo').innerHTML = html;
    pintarTopo();
    pintarRodape();
  }

  function cartao(i) {
    var n = cesto[i.id] || 0;
    var travada = i.pode_pedir <= 0;

    // O que se diz sobre a quantidade muda com o que ela é: um número exacto
    // quando é pouco (é uma decisão a tomar já), e silêncio quando é muito.
    var qtd = '';
    if (!travada) qtd = i.disponivel <= 6 ? 'Só ' + i.disponivel + ' — últimas' : '';
    else if (i.travao === 'stock') qtd = 'Acabou';
    else if (i.travao === 'proibido') qtd = 'Não disponível para si';
    // Um relógio sozinho por baixo de um nome não diz que é uma espera: sem a
    // palavra, «1:29:55» tanto pode ser a hora a que abre como o que já passou.
    else if (i.espera_s > 0) qtd = 'Abre em ';
    else qtd = 'Já levou o que a casa serve';

    // A espera vive num relógio que anda no browser, ao segundo — como a do
    // cabeçalho, e pela mesma razão: uma contagem calculada no servidor nasce
    // velha. O data-ate é o instante em que abre; o tique trata do resto.
    var relogio = (travada && i.espera_s > 0)
      ? '<span class="b-conta" data-ate="' + (Date.now() + i.espera_s * 1000) + '">'
        + esc(hms(i.espera_s)) + '</span>' : '';

    // As alternativas são o que transforma uma porta fechada numa sugestão.
    var alt = (travada && i.alternativas && i.alternativas.length)
      ? '<div class="b-alt">Saem já: ' + i.alternativas.map(function (a) {
          return '<button type="button" onclick="barSaltar(' + a.id + ')">'
               + esc(a.nome) + '</button>';
        }).join(' ') + '</div>' : '';

    return '<div class="b-bebida' + (travada ? ' esgotada' : '') + '" id="bb-' + i.id + '">'
      + foto(i)
      + '<div class="nm">' + esc(i.nome) + '</div>'
      + (i.descricao ? '<div class="ds">' + esc(i.descricao) + '</div>' : '')
      + (travada && i.aviso ? '<div class="ds">' + esc(i.aviso) + '</div>' : '')
      + '<div class="pe">'
      +   '<span class="qtd">' + esc(qtd) + relogio + '</span>'
      +   (travada || !aberto ? '' :
            '<span class="b-mais">'
          + '<button type="button" onclick="barMenos(' + i.id + ')"' + (n ? '' : ' disabled')
          +   ' aria-label="Menos um ' + esc(i.nome) + '">−</button>'
          + '<span class="v" aria-live="polite">' + n + '</span>'
          + '<button type="button" onclick="barMais(' + i.id + ')"'
          +   (n >= i.pode_pedir ? ' disabled' : '')
          +   ' aria-label="Mais um ' + esc(i.nome) + '">+</button>'
          + '</span>')
      + '</div>' + alt + '</div>';
  }

  /** hh:mm:ss, ou mm:ss quando não chega a uma hora. */
  function hms(s) {
    s = Math.max(0, Math.round(s));
    var h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, g = s % 60;
    var dd = function (v) { return (v < 10 ? '0' : '') + v; };
    return (h ? h + ':' + dd(m) : m) + ':' + dd(g);
  }

  // Levar a pessoa à alternativa que ela escolheu, em vez de a deixar à
  // procura dela no meio do menu.
  window.barSaltar = function (id) {
    var el = $('bb-' + id);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('b-aponta');
    setTimeout(function () { el.classList.remove('b-aponta'); }, 1600);
  };

  function pintarMeus() {
    if (!meus.length) return '';
    // O que ELE levou, e só ele: nem o resto da família. Não há aqui
    // parâmetro nenhum a dizer de quem é — é sempre de quem está do outro
    // lado do testemunho, e é por isso que não se pode espreitar o de outro.
    var totais = {};
    meus.forEach(function (p) {
      if (p.estado === 'recusado' || p.estado === 'cancelado') return;
      (p.itens || []).forEach(function (l) {
        totais[l.nome] = (totais[l.nome] || 0) + l.quantidade;
      });
    });
    var nomes = Object.keys(totais);
    var conta = nomes.length
      ? '<div class="b-conta-minha">Já pediu ' + nomes.map(function (n) {
          return '<b>' + totais[n] + '×</b> ' + esc(n);
        }).join(' · ') + '</div>'
      : '';

    return '<div class="b-meus"><div class="b-gaveta">Os meus pedidos</div>' + conta
      + meus.map(function (p) {
          var oq = p.itens.map(function (l) { return l.quantidade + '× ' + l.nome; }).join(', ');
          return '<div class="b-meu">'
            + '<span class="cod">' + esc(p.codigo) + '</span>'
            + '<span class="oq">' + esc(oq)
            +   (p.motivo ? '<br><small>' + esc(p.motivo) + '</small>' : '') + '</span>'
            + '<span class="b-est ' + esc(p.estado) + '">' + esc(p.estado_nome) + '</span>'
            + (p.estado === 'em_analise'
                ? '<button class="btn btn-claro" style="min-height:40px;padding:.3rem .8rem;'
                  + 'font-size:.8rem" onclick="barDesistir(' + p.id + ')">Desistir</button>' : '')
            + '</div>';
        }).join('')
      + '</div>';
  }

  function pintarRodape() {
    var total = 0;
    Object.keys(cesto).forEach(function (k) { total += cesto[k]; });
    var rod = $('b-rodape');
    rod.hidden = total === 0;
    if (!total) return;
    $('b-resumo').textContent = total + (total === 1 ? ' bebida' : ' bebidas')
      + ' · ' + nomeDaMesa();
    $('b-pedir').disabled = !aberto;
  }

  window.barMais = function (id) {
    var i = menu.itens.filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    var n = (cesto[id] || 0) + 1;
    if (n > i.pode_pedir) return;
    cesto[id] = n;
    pintarMenu();
  };
  window.barMenos = function (id) {
    var n = (cesto[id] || 0) - 1;
    if (n > 0) cesto[id] = n; else delete cesto[id];
    pintarMenu();
  };

  // ---- a mesa de entrega ------------------------------------
  // As mesas pedem-se uma vez e ficam: são as mesmas a noite inteira, e
  // servem duas coisas — escolher onde entregar, e dar nome à mesa da pessoa.
  var mesas = null;
  async function asMesas() {
    if (mesas) return mesas;
    var d = await chamar('bar_mesas');
    if (d.success) mesas = d.mesas;
    return mesas;
  }

  window.barMesa = async function () {
    var lista = await asMesas();
    // Sem modal aberto, licJanelaErro não tem onde escrever: seria um toque
    // sem resposta nenhuma.
    if (!lista) { janelaAviso('Sem ligação', 'Não deu para ir buscar as mesas. Tente outra vez.'); return; }
    var d = { mesas: lista };
    licFormulario({
      titulo: 'Onde entregamos?',
      guardar: 'É aqui',
      dica: 'A folha do QR está na <b>' + esc(MESA.nome) + '</b>. Se mudou de lugar, '
          + 'diga-nos para onde — quem entrega procura-o lá.',
      campos: [{ id: 'mesa', rot: 'Mesa', tipo: 'escolha',
                 valor: mesaEntrega ? String(mesaEntrega.id) : String(MESA.id),
                 opcoes: d.mesas.map(function (m) { return { v: String(m.id), r: m.nome }; }) }],
      aoGuardar: function (v) {
        var m = d.mesas.filter(function (x) { return String(x.id) === String(v.mesa); })[0];
        if (m) { mesaEntrega = { id: m.id, nome: m.nome }; pintarMenu(); }
        return true;
      }
    });
  };

  // ---- o pedido ---------------------------------------------
  window.barEnviar = async function () {
    var itens = Object.keys(cesto).map(function (k) {
      return { item_id: parseInt(k, 10), quantidade: cesto[k] };
    });
    if (!itens.length) return;
    var bt = $('b-pedir');
    bt.disabled = true; bt.textContent = 'A enviar…';
    var d = await chamar('bar_pedir', {
      itens: itens, mesa_id: mesaEntrega ? mesaEntrega.id : MESA.id, mesa_qr_id: MESA.id
    });
    bt.disabled = false; bt.textContent = 'Pedir';
    if (!d.success) { janelaAviso('Não deu para pedir', esc(d.error)); return; }
    cesto = {};
    recibo(d.pedido);
    // O menu inteiro, e não só «os meus pedidos»: pedir é o momento em que os
    // limites mudam, e um cartão que ficou sem quota tem de perder o «+» já.
    // A cadência normal levaria meio minuto — meio minuto em que a pessoa
    // carrega no botão e leva com uma recusa que a página já sabia.
    await carregarMenu();
  };

  // Um recado de uma só saída: sem aoConfirmar, licJanela desenha só o botão
  // de fechar — dois botões para «já percebi» seriam uma escolha a fingir.
  function janelaAviso(titulo, html, botao) {
    licJanela(titulo, '<div class="lic-conf"><div class="lic-conf-ico">🍹</div>'
      + '<div class="lic-conf-txt">' + html + '</div></div>', null,
      { cancelar: botao || 'Está bem' });
  }

  function recibo(p) {
    // O número curto é o que se diz em voz alta quando o empregado chega. É a
    // única coisa desta página que alguém tem de decorar por dois minutos.
    janelaAviso('Pedido enviado',
      '<div class="b-recibo"><div class="cod">' + esc(p.codigo) + '</div>'
      + '<p>A copa está a ver. Diga este número a quem entregar.</p></div>',
      'Voltar ao menu');
  }

  window.barDesistir = async function (id) {
    var r = await licConfirmar({ titulo: 'Desistir do pedido', icone: '🍹',
      texto: 'Ainda ninguém o preparou, por isso pode desistir sem incomodar ninguém.',
      confirmar: 'Desistir', cancelar: 'Manter' });
    if (!r.sim) return;
    var d = await chamar('bar_cancelar', { id: id });
    if (!d.success) { janelaAviso('Não deu', esc(d.error)); return; }
    await recarregarMeus();
  };

  // ---- carregar e refrescar ---------------------------------
  async function carregarMenu() {
    var d = await chamar('bar_menu');
    if (!d.success) { $('b-corpo').innerHTML = falhou(d); return; }
    menu = { categorias: d.categorias, itens: d.itens };
    aberto = !!d.aberto;
    ritmo = d.ritmo || null;
    travaoPedido = d.pedido || null;
    // Uma bebida que desapareceu do menu não pode ficar no cesto.
    Object.keys(cesto).forEach(function (k) {
      var i = menu.itens.filter(function (x) { return String(x.id) === k; })[0];
      if (!i || i.pode_pedir <= 0) delete cesto[k];
      else if (cesto[k] > i.pode_pedir) cesto[k] = i.pode_pedir;
    });
    await recarregarMeus();
  }

  async function recarregarMeus() {
    var d = await chamar('bar_meus_pedidos');
    if (d.success) {
      meus = d.pedidos || [];
      if (d.aberto !== undefined) aberto = !!d.aberto;
    }
    pintarMenu();
  }

  window.barRecarregar = function () { arrancar(); };

  async function arrancar() {
    var d = await chamar('bar_mesa');
    if (!d.success) { $('b-corpo').innerHTML = falhou(d); return; }
    aberto = !!d.aberto;
    msgFechado = d.mensagem_fechado || msgFechado;
    procuraMin = d.procura_min || 4;
    if (d.mesa) MESA = d.mesa;
    if (d.eu) {
      eu = d.eu;
      // Ao voltar à página, a mesa de entrega volta a ser a da pessoa (ou a do
      // QR): uma escolha feita há duas horas já não diz onde ela está agora.
      mesaEntrega = eu.mesa_id ? await mesaPorId(eu.mesa_id) : MESA;
      await carregarMenu();
    } else {
      pintarTopo();
      ecraProcura('');
    }
  }

  // O relógio: enquanto a página estiver à vista, vai vendo se a copa decidiu.
  // Escondida, cala-se — a bateria de um telemóvel numa festa é o que é.
  /**
   * Os relógios das esperas, ao segundo.
   *
   * Correm no browser pela mesma razão que o do cabeçalho: uma contagem
   * calculada no servidor nasce velha. Quando um chega a zero, o menu
   * recarrega-se sozinho — a pessoa não tem de adivinhar que já pode.
   */
  function tique() {
    var contas = document.querySelectorAll('.b-conta[data-ate]');
    if (!contas.length) return;
    var acabou = false;
    for (var k = 0; k < contas.length; k++) {
      var falta = (parseInt(contas[k].dataset.ate, 10) - Date.now()) / 1000;
      if (falta <= 0) { acabou = true; contas[k].textContent = '00'; }
      else contas[k].textContent = hms(falta);
    }
    // Uma vez só por volta: recarregar por cada relógio que chega a zero seria
    // pedir o menu três vezes no mesmo segundo.
    if (acabou && !document.hidden) carregarMenu();
  }

  var batidas = 0;
  function bater() {
    if (document.hidden || !eu) return;
    batidas++;
    // O que muda depressa são os pedidos; o que muda devagar é o menu. Um
    // menu inteiro de trinta em trinta segundos chega, e poupa a rede do
    // salão, que numa festa é sempre pior do que parece.
    if (batidas % 3 === 0) carregarMenu(); else recarregarMeus();
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) bater(); });

  arrancar();
  relogio = setInterval(bater, 10000);
  setInterval(tique, 1000);
})();
