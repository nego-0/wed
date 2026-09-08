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
  // Por quem estou a pedir. null = por mim, que é o estado normal e aquele a
  // que a página volta sozinha depois de cada pedido: um «para outro» que se
  // esquecesse ligado dava uma ronda inteira em nome do vizinho.
  var para = null;                      // {id, nome, convite, mesa_id}
  var menu = { categorias: [], itens: [] };
  var ritmo = null;          // o caudal da copa, quando está cheio
  var travaoPedido = null;   // «o próximo pedido abre em…»
  var cesto = {};                       // item_id -> quantidade
  var meus = [];
  var relogio = null, procuraEspera = null;

  function $(id) { return document.getElementById(id); }

  // As peças comuns do módulo (assets/bar-pecas.js). O convidado usa as
  // mesmas: a mesma procura, o mesmo copo desenhado, o mesmo estado com
  // forma. O que muda é a paleta, que aqui é a do convite do casal.
  var ico = window.ICO, BP = window.BP;
  var esc = BP.esc, chave = BP.chave, foto = BP.foto;
  var campoBusca = BP.campoBusca, ligarBusca = BP.ligarBusca, vazio = BP.vazio;

  /* O que o convidado está a pedir ao menu: uma palavra escrita, ou uma
     gaveta escolhida. Um menu de vinte bebidas num telemóvel é muito rolar
     para achar a água. */
  var busca = '', gaveta = 0;

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
    // `message`, e não `error`: é a palavra que a API inteira usa (erro() em
    // api.php escreve `message`). Este ficheiro lia `error`, que nunca existe,
    // e por isso TODOS os erros do ecrã do convidado saíam como «Não deu.» —
    // incluindo o que explica porque é que um pedido foi travado por uma
    // regra, que é justamente o que a pessoa precisa de ler.
    catch (e) { return { success: false, message: 'Sem rede. Tente outra vez daqui a pouco.' }; }
    return d || { success: false, message: 'Resposta estranha do servidor.' };
  }

  /** O que o servidor disse, ou uma frase de recurso. Nunca vazio. */
  function porque(d) {
    return (d && d.message) || 'Não deu.';
  }

  function falhou(d) {
    // Um erro do bar não é uma catástrofe: diz-se o que é e deixa-se tentar
    // outra vez. Chamar um garçom é sempre a saída que resta.
    return '<div class="b-erro"><span>' + esc(porque(d)) + '</span>'
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
      +   'Se não se encontrar na lista, chame um garçom — ele pede por si.</div>'
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
        + 'Experimente o apelido, ou chame um garçom.</div>';
      return;
    }
    cx.innerHTML = d.nomes.map(function (n) {
      return '<button class="b-nome" type="button" role="option" onclick="barSou(' + n.id + ')">'
        + '<b>' + esc(n.nome) + '</b><span>' + esc(n.convite) + '</span></button>';
    }).join('');
  }

  window.barSou = function (id) { return entrar(id); };

  async function entrar(id) {
    var d = await chamar('bar_sou', { convidado_id: id });
    if (!d.success) {
      if ($('b-nomes')) $('b-nomes').innerHTML = falhou(d);
      return;
    }
    eu = d.eu;
    // Se a pessoa tem mesa marcada e não é a do QR, é dela que se parte: quem
    // se levantou para ir buscar o menu quer a bebida no seu lugar.
    if (eu.mesa_id && eu.mesa_id !== MESA.id) mesaEntrega = await mesaPorId(eu.mesa_id);
    await carregarMenu();
  }

  /** A mesa com o seu nome. Um «mesa 7» no ecrã é um número sem sentido para
      quem está sentado à «Mesa dos Padrinhos». */
  async function mesaPorId(id) {
    if (id === MESA.id) return MESA;
    var lista = await asMesas();
    var m = (lista || []).filter(function (x) { return x.id === id; })[0];
    return m ? { id: m.id, nome: m.nome } : { id: id, nome: null };
  }

  // ---- 2. o menu --------------------------------------------
  function pintarTopo() {
    $('b-eu').textContent = eu ? eu.nome : 'Bar';
    var seta = '<svg class="ico seta" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
      + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/></svg>';
    var bp = $('b-para');
    if (bp) {
      bp.hidden = !eu;
      bp.classList.toggle('on', !!para);
      bp.innerHTML = ico.ico('pessoas')
        + (para ? 'A pedir para <b>' + esc(para.nome) + '</b>' : 'Pedir por outra pessoa')
        + seta;
      bp.setAttribute('aria-label', para
        ? 'A pedir para ' + para.nome + '. Tocar para mudar ou voltar a si.'
        : 'Pedir por outra pessoa que esteja consigo à mesa.');
    }
    var bm = $('b-mesa');
    if (!eu) { bm.hidden = true; return; }
    bm.hidden = false;
    // «Entregar em X» e não «na X»: as mesas chamam-se «Noivos», «Padrinhos»,
    // «7» — e metade delas não casa com artigo nenhum.
    bm.innerHTML = ico.ico('mesa') + 'Entregar em <b>' + esc(nomeDaMesa()) + '</b>' + seta;
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
      html += vazio('taca', 'O menu ainda não tem bebidas',
                    'A copa está a acabar de o montar. Volte daqui a pouco.');
    } else {
      html += ferramentas();
      var vistos = peneira(menu.itens);
      if (!vistos.length) {
        html += vazio('procurar', 'Nada com esse nome',
          'São ' + menu.itens.length + ' bebidas no menu; nenhuma responde ao que procura.',
          '<button class="btn btn-claro" onclick="barLimpar()">' + ico.ico('volta')
          + 'Ver o menu todo</button>');
      } else {
        // Por gaveta, na ordem em que a copa as arrumou. Nenhuma bebida se
        // perde pelo caminho: o que não coube em gaveta nenhuma cai em
        // «Outras» — um menu que esconde metade das bebidas em silêncio é pior
        // do que um menu feio.
        var porMostrar = vistos.slice();
        menu.categorias.forEach(function (c) {
          var dela = porMostrar.filter(function (i) { return Number(i.categoria_id) === Number(c.id); });
          if (!dela.length) return;
          porMostrar = porMostrar.filter(function (i) { return dela.indexOf(i) < 0; });
          html += tituloGaveta(c) + '<div class="b-menu">' + dela.map(cartao).join('') + '</div>';
        });
        if (porMostrar.length) {
          html += tituloGaveta({ id: 0, nome: 'Outras' })
            + '<div class="b-menu">' + porMostrar.map(cartao).join('') + '</div>';
        }
      }
    }

    html += pintarMeus();
    $('b-corpo').innerHTML = html;
    if (menu.itens.length) ligarBusca('q-menu', function (v) { busca = v; pintarMenu(); });
    pintarTopo();
    pintarRodape();
  }

  /** O título de uma gaveta: o copo dela, a sua cor, e o nome. */
  function tituloGaveta(c) {
    return '<div class="b-gaveta" id="gav-' + c.id + '"'
      + (c.cor ? ' style="--tinta:' + esc(c.cor) + '"' : '') + '>'
      + ico.copo(c.nome) + (c.cor ? '<i></i>' : '') + esc(c.nome) + '</div>';
  }

  /**
   * A procura e as gavetas, por cima do menu.
   *
   * Vinte bebidas num telemóvel são cinco ecrãs de rolar, e quem tem sede
   * sabe o que quer: escreve «agua» ou toca em «Sem álcool». As pastilhas
   * rolam de lado — quatro linhas delas empurravam o menu para fora do ecrã
   * antes de se ver a primeira bebida.
   *
   * Só aparece quando há menu que chegue para justificar uma ferramenta: com
   * seis bebidas, procurar é mais trabalho do que olhar.
   */
  function ferramentas() {
    if (menu.itens.length < 8) return '';
    var chips = [{ id: 0, nome: 'Tudo', cor: '' }].concat(
      menu.categorias.filter(function (c) {
        return menu.itens.some(function (i) { return Number(i.categoria_id) === Number(c.id); });
      }));
    return '<div class="b-fer">' + campoBusca('q-menu', 'Procurar uma bebida', busca) + '</div>'
      + '<div class="b-pastilhas rolo">'
      + chips.map(function (c) {
          var n = c.id
            ? menu.itens.filter(function (i) { return Number(i.categoria_id) === Number(c.id); }).length
            : menu.itens.length;
          return BP.pilula({ rot: c.nome, cor: c.cor || '', n: n,
                             ligada: Number(gaveta) === Number(c.id),
                             accao: 'barGaveta(' + c.id + ')' });
        }).join('')
      + '</div>';
  }

  /** O menu, já passado pelo que a procura e a gaveta escolheram. */
  function peneira(itens) {
    var q = chave(busca);
    return itens.filter(function (i) {
      if (gaveta && Number(i.categoria_id) !== Number(gaveta)) return false;
      if (q && chave(i.nome + ' ' + (i.descricao || '') + ' ' + (i.categoria || '')).indexOf(q) < 0) {
        return false;
      }
      return true;
    });
  }

  window.barGaveta = function (id) {
    // Tocar outra vez na gaveta escolhida desliga-a: é o gesto que toda a
    // gente tenta, e sem ele a única saída era achar o «Tudo».
    gaveta = (Number(gaveta) === Number(id)) ? 0 : Number(id);
    pintarMenu();
  };
  window.barLimpar = function () { busca = ''; gaveta = 0; pintarMenu(); };

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

    return '<div class="b-bebida' + (travada ? ' esgotada' : '') + (n ? ' no-cesto' : '')
      + '" id="bb-' + i.id + '" style="--tinta:' + esc(i.categoria_cor || 'transparent') + '">'
      + foto(i)
      + '<div class="nm">' + esc(i.nome) + '</div>'
      + (i.descricao ? '<div class="ds">' + esc(i.descricao) + '</div>' : '')
      + (travada && i.aviso ? '<div class="ds">' + esc(i.aviso) + '</div>' : '')
      + '<div class="pe">'
      +   '<span class="qtd">' + esc(qtd) + relogio + '</span>'
      +   (travada || !aberto ? '' :
            '<span class="b-mais">'
          + '<button type="button" class="dn" onclick="barMenos(' + i.id + ')"' + (n ? '' : ' disabled')
          +   ' aria-label="Menos um ' + esc(i.nome) + '">' + ico.ico('menos') + '</button>'
          + '<span class="v" aria-live="polite">' + n + '</span>'
          + '<button type="button" class="up" onclick="barMais(' + i.id + ')"'
          +   (n >= i.pode_pedir ? ' disabled' : '')
          +   ' aria-label="Mais um ' + esc(i.nome) + '">' + ico.ico('mais') + '</button>'
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
      // Só o que é MEU. A lista mostra também o que lancei por outros — para
      // eu poder dizer «já vem» a quem mo pediu —, mas a conta de quantas
      // levei é minha: a bebida da minha mãe não me pode aparecer na conta.
      if (eu && p.para_id && p.para_id !== eu.id) return;
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

    return '<div class="b-meus"><div class="b-gaveta">' + ico.ico('nota')
      + 'Os meus pedidos</div>' + conta
      + meus.map(function (p) {
          var oq = p.itens.map(function (l) { return l.quantidade + '× ' + l.nome; }).join(', ');
          // De quem é esta: a que lancei por outro, e a que outro lançou por
          // mim. Sem estas duas linhas a lista misturava as bebidas da mesa
          // toda sem dizer de quem eram.
          var dequem = '';
          if (eu && p.para_id && p.para_id !== eu.id) {
            dequem = '<br><small>para ' + esc(p.para || 'outra pessoa') + '</small>';
          } else if (p.pedido_por) {
            dequem = '<br><small>pedido por ' + esc(p.pedido_por) + '</small>';
          }
          return '<div class="b-meu">'
            + '<span class="cod">' + esc(p.codigo) + '</span>'
            + '<span class="oq">' + esc(oq) + dequem
            +   (p.motivo ? '<br><small>' + esc(p.motivo) + '</small>' : '') + '</span>'
            + BP.estado(p.estado, p.estado_nome)
            + (p.estado === 'em_analise'
                ? '<button class="btn btn-claro" style="min-height:40px;padding:.3rem .8rem;'
                  + 'font-size:.8rem" onclick="barDesistir(' + p.id + ')">'
                  + ico.ico('xis') + 'Desistir</button>' : '')
            + '</div>';
        }).join('')
      + '</div>';
  }

  function pintarRodape() {
    var total = 0;
    Object.keys(cesto).forEach(function (k) { total += cesto[k]; });
    var rod = $('b-rodape');
    rod.hidden = total === 0;
    // A barra tapa o canto de baixo à direita, onde mora o botão do tema. Quem
    // se desvia é ele, e é daqui que sabe quando: a folha podia perguntá-lo
    // sozinha com :has(), mas um telemóvel que não o entenda ficava com o
    // «Pedir» por baixo do botão flutuante — sem poder pedir, que é a única
    // coisa que aquela página faz. Uma classe entende-a toda a gente.
    document.body.classList.toggle('com-rodape', total > 0);
    if (!total) return;
    $('b-resumo').textContent = total + (total === 1 ? ' bebida' : ' bebidas')
      + (para ? ' para ' + para.nome : '') + ' · ' + nomeDaMesa();
    // O botão diz o nome quando o pedido é de outra pessoa: é o último sítio
    // onde alguém repara que se esqueceu de voltar a si.
    $('b-pedir').textContent = para ? 'Pedir para ' + para.nome : 'Pedir';
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

  // ---- pedir por outra pessoa -------------------------------
  /**
   * A mesma caixa de procura da entrada, agora dentro de uma janela.
   *
   * Numa mesa há sempre quem não tenha o telemóvel à mão, quem o tenha sem
   * bateria, e quem simplesmente não queira lidar com aquilo — e pede ao
   * vizinho. Isto já era possível pela porta errada: trocar de nome no
   * telemóvel. Só que essa troca PRENDE o aparelho à outra pessoa, e a seguir
   * as minhas bebidas passavam a contar na conta dela.
   *
   * Aqui o telemóvel continua meu. O que muda é de quem é a bebida — e, com
   * ela, de quem é a quota: os limites que a página passa a mostrar são os de
   * quem a vai beber.
   */
  window.barPara = async function () {
    var lista = null;
    licJanela('Pedir por outra pessoa',
      '<p class="dica" style="margin:0 0 .7rem">Para quem está consigo à mesa e '
      + 'não tem o telemóvel à mão. A bebida fica no nome dessa pessoa — e conta '
      + 'para as bebidas dela, não para as suas.</p>'
      + '<input id="b-pq" type="search" autocomplete="off" autocapitalize="words" '
      +   'spellcheck="false" placeholder="O nome dessa pessoa" '
      +   'aria-label="Procurar a pessoa por quem vai pedir" '
      +   'style="width:100%;font-size:1.05rem;padding:.8rem .9rem;border-radius:12px;'
      +   'border:1px solid rgba(0,0,0,.2)">'
      + '<div class="b-nomes" id="b-pq-lista" role="listbox" style="margin-top:.7rem"></div>'
      + (para
          ? '<button type="button" class="btn btn-claro" style="width:100%;margin-top:.8rem" '
            + 'onclick="barParaMim()">Voltar a pedir para mim</button>'
          : ''),
      null, { cancelar: 'Fechar' });

    var cx = document.getElementById('b-pq-lista');
    var cq = document.getElementById('b-pq');
    if (!cq) return;
    cq.focus();
    var espera = null;
    cq.addEventListener('input', function () {
      clearTimeout(espera);
      espera = setTimeout(async function () {
        var termo = cq.value.trim();
        if (termo.length < procuraMin) {
          var faltam = procuraMin - termo.length;
          cx.innerHTML = termo.length
            ? '<div class="b-ajuda" style="margin:0">Falta' + (faltam > 1 ? 'm ' : ' ')
              + faltam + (faltam > 1 ? ' letras.' : ' letra.') + '</div>'
            : '';
          return;
        }
        var d = await chamar('bar_procurar', undefined, { q: termo });
        if (!d.success) { cx.innerHTML = '<div class="b-ajuda" style="margin:0">'
          + esc(porque(d)) + '</div>'; return; }
        if (!d.nomes.length) {
          cx.innerHTML = '<div class="b-ajuda" style="margin:0">Ninguém com esse nome.</div>';
          return;
        }
        lista = {};
        d.nomes.forEach(function (n) { lista[n.id] = n; });
        cx.innerHTML = d.nomes.map(function (n) {
          // Eu próprio não entro na lista: para voltar a mim há o botão, que
          // diz o que faz. Um nome meu ali seria a mesma coisa por um caminho
          // que se lê pior.
          if (eu && n.id === eu.id) return '';
          return '<button class="b-nome" type="button" role="option" '
            + 'onclick="barParaEste(' + n.id + ')"><b>' + esc(n.nome) + '</b>'
            + '<span>' + esc(n.convite) + '</span></button>';
        }).join('');
      }, 220);
    });
    window.__barParaLista = function (id) { return lista ? lista[id] : null; };
  };

  window.barParaEste = async function (id) {
    var n = window.__barParaLista ? window.__barParaLista(id) : null;
    if (!n) return;
    licFecharJanela();
    await fixarPara({ id: n.id, nome: n.nome, convite: n.convite });
  };

  window.barParaMim = async function () {
    licFecharJanela();
    para = null;
    cesto = {};
    mesaEntrega = eu && eu.mesa_id ? await mesaPorId(eu.mesa_id) : MESA;
    await carregarMenu();
  };

  /** Fixa por quem se está a pedir, e passa a ver o menu com os limites dela. */
  async function fixarPara(p) {
    para = p;
    // O cesto era meu e passa a ser dela: as quantidades que lá estavam foram
    // medidas contra as MINHAS quotas, e levá-las para o nome dela era pedir
    // pela pessoa errada com a conta da outra.
    cesto = {};
    var d = await chamar('bar_menu', undefined, { por: p.id });
    if (d.success && d.para && d.para.mesa_id) mesaEntrega = await mesaPorId(d.para.mesa_id);
    await carregarMenu();
  }

  window.barEnviar = async function () {
    var itens = Object.keys(cesto).map(function (k) {
      return { item_id: parseInt(k, 10), quantidade: cesto[k] };
    });
    if (!itens.length) return;
    var bt = $('b-pedir');
    var rotulo = bt.textContent;
    bt.disabled = true; bt.textContent = 'A enviar…';
    var corpo = { itens: itens, mesa_id: mesaEntrega ? mesaEntrega.id : MESA.id,
                  mesa_qr_id: MESA.id };
    if (para) corpo.por_id = para.id;
    var d = await chamar('bar_pedir', corpo);
    bt.disabled = false; bt.textContent = rotulo;
    if (!d.success) { janelaAviso('Não deu para pedir', esc(porque(d))); return; }
    cesto = {};
    recibo(d.pedido);
    // Volta-se a mim, sempre. Deixar o «para outro» ligado depois de o pedido
    // seguir era o erro fácil de cometer e caro de desfazer: a ronda seguinte
    // saía toda em nome do vizinho, e a quota dele é que pagava.
    if (para) {
      para = null;
      mesaEntrega = eu && eu.mesa_id ? await mesaPorId(eu.mesa_id) : MESA;
    }
    // O menu inteiro, e não só «os meus pedidos»: pedir é o momento em que os
    // limites mudam, e um cartão que ficou sem quota tem de perder o «+» já.
    // A cadência normal levaria meio minuto — meio minuto em que a pessoa
    // carrega no botão e leva com uma recusa que a página já sabia.
    await carregarMenu();
  };

  // Um recado de uma só saída: sem aoConfirmar, licJanela desenha só o botão
  // de fechar — dois botões para «já percebi» seriam uma escolha a fingir.
  function janelaAviso(titulo, html, botao, icone) {
    licJanela(titulo, '<div class="lic-conf"><div class="lic-conf-ico">'
      + ico.ico(icone || 'taca') + '</div>'
      + '<div class="lic-conf-txt">' + html + '</div></div>', null,
      { cancelar: botao || 'Está bem' });
  }

  function recibo(p) {
    // O número curto é o que se diz em voz alta quando o garçom chega. É a
    // única coisa desta página que alguém tem de decorar por dois minutos.
    var deOutro = eu && p.para_id && p.para_id !== eu.id;
    janelaAviso('Pedido enviado',
      '<div class="b-recibo"><div class="cod">' + esc(p.codigo) + '</div>'
      + '<p>A copa está a ver. Diga este número a quem entregar.'
      + (deOutro
          ? '<br>Vai no nome de <b>' + esc(p.para) + '</b>, e conta para as bebidas '
            + 'dessa pessoa. Voltámos a pôr os seus pedidos em seu nome.'
          : '')
      + '</p></div>',
      'Voltar ao menu', 'visto');
  }

  window.barDesistir = async function (id) {
    var r = await licConfirmar({ titulo: 'Desistir do pedido', icone: 'volta',
      texto: 'Ainda ninguém o preparou, por isso pode desistir sem incomodar ninguém.',
      confirmar: 'Desistir', cancelar: 'Manter' });
    if (!r.sim) return;
    var d = await chamar('bar_cancelar', { id: id });
    if (!d.success) { janelaAviso('Não deu', esc(porque(d))); return; }
    await recarregarMeus();
  };

  // ---- carregar e refrescar ---------------------------------
  async function carregarMenu() {
    // Com «por», o menu vem com os limites de quem vai beber. É o que faz o
    // «+» desaparecer numa bebida que essa pessoa já não pode pedir — mostrar
    // as minhas quotas e recusar no fim seria uma promessa a fingir.
    var d = await chamar('bar_menu', undefined, para ? { por: para.id } : null);
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
