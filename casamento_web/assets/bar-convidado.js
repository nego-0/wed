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
  var CASA  = window.BAR.casa || '';    // o código da festa, quando se entrou por ele
  // A mesa do QR — onde a folha está pousada. NULL quando se entrou pelo link
  // da festa, que é o caso novo: aí não há folha nenhuma e a mesa escolhe-se
  // na pastilha do topo. Guarda-se um objecto vazio em vez de null para o
  // resto do ficheiro poder continuar a escrever MESA.id sem se defender a
  // cada linha; o id zero nunca casa com o de mesa nenhuma, que é o que se
  // quer — «não é a mesma mesa» é verdade quando não há mesa.
  var SEM_MESA = { id: 0, nome: null };
  var MESA  = window.BAR.mesa || SEM_MESA;
  var eu = null;                        // quem este telemóvel é
  // Para onde vai a bebida (pode mudar-se). Sem mesa de entrada fica por
  // dizer, e é a página que a vai pedir.
  var mesaEntrega = window.BAR.mesa || null;
  var aberto = false, msgFechado = window.BAR.fechado || '';
  var procuraMin = 4;
  // Por quem estou a pedir. null = por mim, que é o estado normal e aquele a
  // que a página volta sozinha depois de cada pedido: um «para outro» que se
  // esquecesse ligado dava uma ronda inteira em nome do vizinho.
  var para = null;                      // {id, nome, convite, mesa_id}
  var menu = { categorias: [], itens: [] };
  var ritmo = null;          // o caudal da copa, quando está cheio
  var travaoPedido = null;   // «o próximo pedido abre em…»
  // A pausa da copa. Guarda-se o INSTANTE em que acaba, e não os segundos que
  // faltavam quando o menu chegou: o menu repinta-se a cada volta, e uma
  // contagem guardada em segundos voltava ao princípio de oito em oito.
  var pausa = null;          // {espera_s, mensagem}
  // Os instantes em que cada espera acaba. Guardam-se em ABSOLUTO, e não em
  // segundos: o menu repinta-se a cada volta, e uma contagem guardada em
  // segundos voltava ao princípio de dez em dez — a pessoa via «2:00» a vida
  // toda e nunca percebia que aquilo andava.
  var pausaAte = 0, ritmoAte = 0, travaoAte = 0;
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
    // O endereço viaja em todos os pedidos — é ele a chave, que aqui não há
    // sessão. A FESTA vai sempre (é ela que diz de que casa se fala); a mesa
    // vai quando se entrou por uma, e serve para o servidor saber onde
    // entregar sem ninguém ter de escolher.
    var url = 'api.php?action=' + encodeURIComponent(accao)
            + (CASA ? '&c=' + encodeURIComponent(CASA) : '')
            + (TOKEN ? '&m=' + encodeURIComponent(TOKEN) : '');
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        url += '&' + k + '=' + encodeURIComponent(extra[k]);
      });
    }
    var opc = { headers: { 'Accept': 'application/json' } };
    if (corpo !== undefined) {
      if (CASA) corpo.c = CASA;
      if (TOKEN) corpo.m = TOKEN;
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
    // Quem escreve no corpo por fora do menu tem de esquecer o que lá estava:
    // senão o menu seguinte, se calhar a ser igual ao último, era «saltado» e
    // a pessoa ficava a olhar para o ecrã de quem é.
    esqueletoPosto = false;
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
    // O nome de quem entrou já não se escreve à parte: vive dentro da pastilha
    // («A pedir para Ana»), que é onde ele quer dizer alguma coisa. Ver o
    // comentário da barra em bebidas.php.
    var seta ='<svg class="ico seta" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
      + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/></svg>';
    var bp = $('b-para');
    if (bp) {
      bp.hidden = !eu;
      bp.classList.toggle('on', !!para);
      // O botão diz sempre EM NOME DE QUEM se está a pedir — e por omissão
      // isso é a própria pessoa. Dizia «Pedir por outra pessoa», que é o que o
      // botão FAZ e não o que ele MOSTRA: quem olhava para a barra via o nome
      // do casal e um convite a pedir por outrem, e não via em nome de quem
      // estava a pedir. Agora lê-se de uma vez: «A pedir para Ana» — ela, ou
      // outra pessoa, se for o caso.
      var quem = para ? para.nome : (eu ? eu.nome : '');
      bp.innerHTML = ico.ico('pessoas')
        + (quem ? 'A pedir para <b>' + esc(quem) + '</b>' : 'Pedir por outra pessoa')
        + seta;
      bp.setAttribute('aria-label', quem
        ? 'A pedir para ' + quem + '. Tocar para pedir por outra pessoa da mesa.'
        : 'Pedir por outra pessoa que esteja consigo à mesa.');
    }
    var bm = $('b-mesa');
    if (!eu) { bm.hidden = true; return; }
    bm.hidden = false;
    // A mesa é uma ESCOLHA, e por isso é aqui a própria escolha — não um botão
    // que abre uma janela com uma lista lá dentro. Abrir uma janela inteira,
    // ler um título, escolher, e carregar em «É aqui» são quatro gestos para
    // dizer uma coisa que a lista diz sozinha. A barra fica com a mesa à vista,
    // e toca-se nela para a trocar (§32.2).
    montarBarraMesa();
  }

  /* ---- a barra da mesa: a lista, e não um botão para uma janela ----
     Monta-se uma vez, quando já se sabe quem é a pessoa (é daí que vem a mesa
     de partida) e quais são as mesas da casa. Depois só se lhe muda o valor:
     refazê-la a cada pintura fechava a lista na cara de quem a tinha aberto. */
  var barraMesaPronta = false;
  async function montarBarraMesa() {
    var cx = $('b-mesa');
    if (!cx) return;
    if (barraMesaPronta) {
      if (window.licSelDefinir) {
        window.licSelDefinir(cx.querySelector('.lic-sel'),
                             String((mesaEntrega || MESA).id), true);
      }
      return;
    }
    var lista = await asMesas();
    if (!lista || !lista.length || !window.licSelProcuraHtml) return;
    barraMesaPronta = true;
    cx.innerHTML = ico.ico('mesa') + '<span class="rot">Entregar em</span>'
      + window.licSelProcuraHtml(
          { id: 'mesa-entrega', rot: 'Entregar em', classe: 'lic-sel-pagina',
            dicaProcura: 'O nome ou o número da mesa',
            opcoes: lista.map(function (m) { return { v: String(m.id), r: m.nome }; }) },
          String((mesaEntrega || MESA).id));
    window.licSelProcuraLigar(cx);
    var campo = document.getElementById('lf-mesa-entrega');
    if (campo) {
      campo.addEventListener('change', function () {
        var id = parseInt(this.value, 10);
        var m = lista.filter(function (x) { return Number(x.id) === id; })[0];
        if (!m) return;
        mesaEntrega = { id: m.id, nome: m.nome };
        // O rodapé diz para onde vai o que está no cesto: muda com a mesa.
        pintarRodape();
      });
    }
  }

  /** A mesa por onde se entrou — a do QR, ou nenhuma se se entrou pelo link
      da festa. É a ela que se volta quando a pessoa não tem mesa marcada. */
  function mesaDeEntrada() { return window.BAR.mesa || null; }

  function nomeDaMesa() {
    if (mesaEntrega && mesaEntrega.nome) return mesaEntrega.nome;
    if (mesaEntrega && mesaEntrega.id === MESA.id) return MESA.nome;
    // Sem mesa nenhuma — quem entrou pelo link da festa e ainda não escolheu.
    // Diz-se o que falta fazer, e não «mesa ?», que é uma pergunta devolvida
    // a quem não a fez.
    if (!mesaEntrega || !mesaEntrega.id) return 'uma mesa por escolher';
    return 'mesa ' + mesaEntrega.id;
  }

  /* ---- o menu pinta-se por SECÇÕES ------------------------------
     Havia aqui um `innerHTML` só, com a página toda dentro. A cada volta do
     relógio — de dez em dez segundos — todos os cartões e todas as fotografias
     eram destruídos e refeitos à frente de quem estava a ler: a página piscava
     sozinha, e com a contagem de um tempo limite a correr piscava sempre,
     porque o texto do relógio ia dentro do html e mudava a cada segundo.

     Agora o corpo tem quatro caixas fixas, e cada uma só se reescreve quando o
     que ELA diz mudou. As gavetas e os cartões — a parte cara, a que tem
     imagens — ficam quietos enquanto a contagem anda numa faixa acima deles.

     E as contagens nascem VAZIAS: o número é escrito logo a seguir pelo
     tique(), que é quem o vai andando ao segundo. Assim o html de uma faixa
     com relógio é igual de volta para volta, e a caixa não se reescreve. */
  var pintado = { avisos: '', fer: '', menu: '', meus: '', pe: '' };
  var esqueletoPosto = false;

  /** As caixas fixas do corpo. Põem-se uma vez; depois só se lhes escreve dentro. */
  function esqueleto() {
    $('b-corpo').innerHTML =
        '<div id="b-avisos"></div>'
      + '<div id="b-fer-cx"></div>'
      + '<div id="b-menu-cx"></div>'
      + '<div id="b-meus-cx"></div>'
      + '<div id="b-pe-cx"></div>';
    esqueletoPosto = true;
    for (var k in pintado) pintado[k] = '';
  }

  /** Escrever numa caixa só se o que lá vai for outro. */
  function poe(caixa, id, html) {
    if (pintado[caixa] === html) return false;
    pintado[caixa] = html;
    var el = $(id);
    if (el) el.innerHTML = html;
    return true;
  }

  /** Uma contagem que nasce vazia — o tique() escreve-lhe o número. */
  function relogioDe(ate) {
    return '<b class="b-conta" data-ate="' + ate + '"></b>';
  }

  function htmlAvisos() {
    var html = '';
    if (!aberto) {
      html += '<div class="b-nota"><b>A copa ainda não está a servir.</b><br>'
        + esc(msgFechado || 'Assim que abrir, pode pedir daqui mesmo — a página avisa sozinha.')
        + '</div>';
    } else if (pausa) {
      // A pausa diz-se ANTES de escolher, e diz quanto falta. Quem está com o
      // telemóvel na mão quer saber se vale a pena esperar — e vale, porque a
      // copa reabre sozinha e a página recarrega-se quando o relógio chegar a
      // zero. «Volte mais tarde» mandava a pessoa ao balcão perguntar.
      html += '<div class="b-nota"><b>A copa está em pausa.</b><br>'
        + esc(pausa.mensagem)
        + '<br>Volta a servir em ' + relogioDe(pausaAte) + '.</div>';
    }

    // O caudal da copa: não é a pessoa que pediu de mais, é a casa que está
    // cheia — e o texto tem de dizer isso, não repreender ninguém.
    if (ritmo) {
      html += '<div class="b-nota">' + esc(ritmo.mensagem
        || 'A copa está a dar vazão a muitos pedidos neste momento.')
        + (ritmoAte
            ? '<br>O seu abre em ' + relogioDe(ritmoAte)
              + ' — e fica na frente quando abrir.' : '')
        + '</div>';
    } else if (travaoPedido) {
      // O travão do acto de pedir é da pessoa, e trava a página inteira.
      html += '<div class="b-nota">' + esc(travaoPedido.texto)
        + (travaoAte ? ' ' + relogioDe(travaoAte) : '') + '</div>';
    }
    return html;
  }

  function htmlMenu() {
    if (!menu.itens.length) {
      return vazio('taca', 'O menu ainda não tem bebidas',
                   'A copa está a acabar de o montar. Volte daqui a pouco.');
    }
    var vistos = peneira(menu.itens);
    if (!vistos.length) {
      return vazio('procurar', 'Nada com esse nome',
        'São ' + menu.itens.length + ' bebidas no menu; nenhuma responde ao que procura.',
        '<button class="btn btn-claro" onclick="barLimpar()">' + ico.ico('volta')
        + 'Ver o menu todo</button>');
    }
    // Por gaveta, na ordem em que a copa as arrumou. Nenhuma bebida se perde
    // pelo caminho: o que não coube em gaveta nenhuma cai em «Outras» — um
    // menu que esconde metade das bebidas em silêncio é pior do que um menu
    // feio.
    var html = '', porMostrar = vistos.slice();
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
    return html;
  }

  function pintarMenu() {
    if (!eu) return;
    if (!esqueletoPosto || !$('b-menu-cx')) esqueleto();

    poe('avisos', 'b-avisos', htmlAvisos());
    // A caixa da procura escreve-se UMA vez: reescrevê-la a cada letra era
    // apagar o campo onde a pessoa estava a escrever e devolver-lhe o cursor a
    // seguir — um remendo que se via. As pastilhas das gavetas vão com ela, e
    // mudam de estado por classe, sem se refazerem.
    if (poe('fer', 'b-fer-cx', ferramentas()) && menu.itens.length) {
      ligarBusca('q-menu', function (v) { busca = v; pintarMenu(); });
    }
    marcarGaveta();
    poe('menu', 'b-menu-cx', htmlMenu());
    poe('meus', 'b-meus-cx', pintarMeus());
    poe('pe', 'b-pe-cx', rodapeDaCasa());

    // Os relógios das faixas nascem vazios; escreve-se-lhes o número já, para
    // não haver um instante de nada antes do primeiro tique.
    tique(true);
    pintarTopo();
    pintarRodape();
  }

  /** A gaveta escolhida acende-se por classe, sem refazer as pastilhas. */
  function marcarGaveta() {
    var cx = $('b-fer-cx');
    if (!cx) return;
    cx.querySelectorAll('.b-pilula[data-chave]').forEach(function (b) {
      var ligada = Number(b.dataset.chave) === Number(gaveta);
      b.classList.toggle('on', ligada);
      b.setAttribute('aria-pressed', ligada ? 'true' : 'false');
    });
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
    /* Escreve-se UMA vez, e por isso não leva aqui nem o que está escrito na
       procura nem qual é a gaveta acesa: são as duas coisas que mudam a toda a
       hora, e pô-las no html obrigava a refazer a barra — a apagar o campo
       onde a pessoa está a escrever — a cada letra e a cada toque. O campo
       guarda o seu próprio texto; a gaveta acende-se por classe (marcarGaveta). */
    return '<div class="b-fer">' + campoBusca('q-menu', 'Procurar uma bebida', '') + '</div>'
      + '<div class="b-pastilhas rolo">'
      + chips.map(function (c) {
          var n = c.id
            ? menu.itens.filter(function (i) { return Number(i.categoria_id) === Number(c.id); }).length
            : menu.itens.length;
          return BP.pilula({ rot: c.nome, cor: c.cor || '', n: n, chave: c.id,
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
  window.barLimpar = function () {
    busca = ''; gaveta = 0;
    // A barra da procura já não se reescreve a cada volta: quem limpa o filtro
    // tem de limpar também o campo, senão ficava lá a palavra que já não filtra.
    var q = $('q-menu'), cx = $('q-menu-cx');
    if (q) q.value = '';
    if (cx) cx.classList.remove('tem');
    pintarMenu();
  };

  // Como se serve cada bebida, dito ao convidado. Isto não estava em lado
  // nenhum: quem olhava para «Whisky» não sabia se lhe traziam um copo ou a
  // garrafa, e descobria-o com o tabuleiro à frente. Uma bebida que só sai ao
  // copo tem de o dizer ANTES de ser pedida — é a mesma regra da copa e das
  // entregas, que também passaram a dizê-lo.
  // A frase vive em bar-pecas.js, com a copa e as entregas: é a mesma
  // informação e tem de ser as mesmas palavras.
  function comoSeServe(i) { return BP.comoServe(i && i.servir); }

  // Qual das unidades está escolhida em cada bebida que admite as duas.
  // O copo vem por omissão — é a dose, é o que a maior parte das pessoas quer,
  // e uma garrafa escolhida por engano é um engano caro.
  var unEscolhida = {};
  function unDe(i) {
    if (i.servir !== 'ambos') return i.servir === 'garrafa' ? 'garrafa' : 'copo';
    return unEscolhida[i.id] || 'copo';
  }
  window.barUnidade = function (id, un) {
    unEscolhida[id] = un === 'garrafa' ? 'garrafa' : 'copo';
    pintarMenu();
  };

  function cartao(i) {
    // Uma bebida que se sirva das duas maneiras deixa a pessoa DIZER qual —
    // e diz-se com uma escolha, não com dois pares de botões iguais um por
    // baixo do outro. Dois contadores lado a lado é uma pergunta implícita
    // («qual destes é o meu?»); uma escolha com o copo já marcado é uma
    // resposta que se pode mudar.
    var uns = [unDe(i)];
    // O cesto conta as DUAS unidades, mesmo que só uma esteja à vista: quem
    // pôs uma garrafa e depois voltou ao copo tem na mesma a garrafa no cesto,
    // e o cartão tem de continuar a dizê-lo.
    var todas = i.servir === 'ambos' ? ['copo', 'garrafa'] : uns;
    var n = todas.reduce(function (t, u) { return t + (cesto[chaveCesto(i.id, u)] || 0); }, 0);
    var travada = i.pode_pedir <= 0;

    // O que se diz sobre a quantidade: NADA, enquanto houver.
    //
    // Aqui dizia-se «Só 5 — últimas» quando o stock ia abaixo de meia dúzia.
    // A intenção era cortês; o efeito era o contrário de tudo o que este
    // módulo faz. Contar a um convidado quantas garrafas restam é convidá-lo a
    // correr para elas — e numa festa a corrida é literal: quem lê o número
    // pede três para garantir, e quem chega dez minutos depois não apanha
    // nenhuma. O aviso escasso produz a escassez que anuncia.
    //
    // É a mesma regra que já valia para os limites: o convidado nunca lê o que
    // o sistema sabe sobre ele nem sobre a casa — nem o número da regra, nem o
    // limite, nem o stock. O que ele precisa de saber é se pode pedir agora, e
    // isso diz-se sem números.
    //
    // «Acabou» fica, e não é excepção nenhuma: não é um limite, é o estado da
    // bebida. Quem está a olhar para uma bebida que não pode pedir tem de
    // saber porquê, senão carrega no botão a noite inteira.
    //
    // O `travada` continua a guardar a cadeia toda, e tem de continuar: as
    // linhas abaixo dizem todas PORQUE É QUE NÃO SE PODE PEDIR. Sem ele, uma
    // bebida à venda caía no último `else` e anunciava «Já levou o que a casa
    // serve» a quem ainda não tinha pedido nada.
    var qtd = '';
    // À VISTA, MAS SEM GARRAFA INTEIRA. O stock conta-se em copos, porque é o
    // copo que acaba; uma garrafa gasta os que leva dentro. Com quatro copos
    // e seis por garrafa não cabe garrafa nenhuma — e a bebida não está
    // esgotada, pelo que nada a marcava. O «+» ficava desactivado, calado, e
    // um botão morto sem explicação lê-se como avaria. Foi assim que isto
    // chegou duas vezes de volta: «o ícone + parece desabilitado».
    if (!travada && unDe(i) === 'garrafa' && (+i.garrafas_possiveis || 0) < 1) {
      qtd = i.servir === 'garrafa' ? 'Sem garrafas inteiras' : 'Sem garrafas — peça ao copo';
    }
    else if (!travada) qtd = '';
    else if (i.travao === 'stock') qtd = 'Acabou';
    else if (i.travao === 'proibido') qtd = 'Não disponível para si';
    // Um relógio sozinho por baixo de um nome não diz que é uma espera: sem a
    // palavra, «1:29:55» tanto pode ser a hora a que abre como o que já passou.
    else if (i.espera_s > 0) qtd = 'Abre em ';
    else qtd = 'Já levou o que a casa serve';

    // A espera vive num relógio que anda no browser, ao segundo — como a do
    // cabeçalho, e pela mesma razão: uma contagem calculada no servidor nasce
    // velha. O data-ate é o instante em que abre; o tique trata do resto.
    // O instante em que esta bebida abre — fixado quando o menu chegou, e não
    // recalculado a cada pintura. Era esta linha que ainda punha a grelha a
    // piscar: `Date.now()` mudava o html do cartão a cada volta, e o cartão
    // inteiro (fotografia incluída) era refeito por causa de um número que o
    // tique() escreve sozinho.
    var relogio = (travada && i.ate)
      ? '<span class="b-conta" data-ate="' + i.ate + '"></span>' : '';

    // As alternativas são o que transforma uma porta fechada numa sugestão.
    var alt = (travada && i.alternativas && i.alternativas.length)
      ? '<div class="b-alt">Saem já: ' + i.alternativas.map(function (a) {
          return '<button type="button" onclick="barSaltar(' + a.id + ')">'
               + esc(a.nome) + '</button>';
        }).join(' ') + '</div>' : '';

    // Uma marca de canto na fotografia: o que leva álcool. Havia uma segunda,
    // «Restam N», que dizia ao convidado quanto stock sobrava — e essa saiu
    // pela razão de cima. O que resta é da copa, e é lá que se vê.
    var marcas = '';
    if (i.alcoolico) {
      marcas += '<span class="b-selo alc" title="Com álcool" aria-label="Com álcool">'
              + ico.ico('gota') + '</span>';
    }

    // Como se serve — sempre, e não só quando é a garrafa. Um cartão calado
    // obrigava a pessoa a adivinhar, e quem adivinha adivinha o costume: o
    // copo. Quem quisesse a garrafa de um vinho que a serve nunca o saberia.
    var servico = '<div class="b-serve' + (i.servir === 'ambos' ? ' escolhe' : '') + '">'
      + (i.servir === 'ambos' && !travada && aberto && !pausa
         // A escolha, com o copo já marcado. É a dose, é o que a maior parte
         // das pessoas quer, e uma garrafa escolhida por engano é um engano
         // caro — por isso o padrão é o copo e a garrafa é uma decisão.
         ? ['copo', 'garrafa'].map(function (u) {
             var ligado = unDe(i) === u;
             return '<button type="button" class="b-un-bt' + (ligado ? ' on' : '') + '"'
               + ' onclick="barUnidade(' + i.id + ',\'' + u + '\')"'
               + ' aria-pressed="' + (ligado ? 'true' : 'false') + '"'
               + ' aria-label="Pedir ' + esc(i.nome) + ' ' + (u === 'garrafa' ? 'à garrafa' : 'ao copo') + '">'
               + (u === 'garrafa' ? 'garrafa' : 'copo') + '</button>';
           }).join('')
         : '<span class="b-serve-txt">' + esc(comoSeServe(i)) + '</span>')
      + '</div>';

    return '<div class="b-bebida' + (travada ? ' esgotada' : '') + (n ? ' no-cesto' : '')
      + '" id="bb-' + i.id + '" style="--tinta:' + esc(i.categoria_cor || 'transparent') + '">'
      + '<div class="cx-foto">' + foto(i)
      +   (marcas ? '<div class="selos">' + marcas + '</div>' : '') + '</div>'
      + '<div class="nm">' + esc(i.nome) + '</div>'
      + (i.descricao ? '<div class="ds">' + esc(i.descricao) + '</div>' : '')
      + (travada && i.aviso ? '<div class="ds">' + esc(i.aviso) + '</div>' : '')
      + servico
      + '<div class="pe">'
      +   '<span class="qtd">' + esc(qtd) + relogio + '</span>'
      +   (travada || !aberto || pausa ? '' : uns.map(function (u) {
            var q = cesto[chaveCesto(i.id, u)] || 0;
            // O que já está no cesto desta bebida, em doses: é contra isto que
            // se sabe se ainda cabe mais uma garrafa. Contam-se as DUAS
            // unidades: três copos e uma garrafa de seis são nove doses, e o
            // tecto é sobre o total.
            var gasto = todas.reduce(function (t, x) {
              return t + (cesto[chaveCesto(i.id, x)] || 0) * custoDe(i, x); }, 0);
            var cabe = cabeMais(i, u, gasto);
            var rot = u === 'garrafa' ? 'garrafa' : 'copo';
            return '<span class="b-mais">'
              + '<button type="button" class="dn" onclick="barMenos(' + i.id + ',\'' + u + '\')"'
              +   (q ? '' : ' disabled')
              +   ' aria-label="Menos um ' + esc(rot) + ' de ' + esc(i.nome) + '">'
              +   ico.ico('menos') + '</button>'
              + '<span class="v" aria-live="polite">' + q + '</span>'
              + '<button type="button" class="up" onclick="barMais(' + i.id + ',\'' + u + '\')"'
              +   (cabe ? '' : ' disabled')
              +   ' aria-label="Mais um ' + esc(rot) + ' de ' + esc(i.nome) + '">'
              +   ico.ico('mais') + '</button>'
              + '</span>';
          }).join(''))
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

  /**
   * O fecho da página.
   *
   * Não é enfeite: a página acabava no ar, e o que ficava por dizer era
   * justamente o que a pessoa pergunta ao garçom — «e agora?». Três linhas
   * respondem: o pedido vai à copa, alguém o traz, e é este o sítio onde ele
   * é entregue. O nome da mesa repete-se aqui de propósito: quem chega ao fim
   * do menu já rolou para longe da barra onde ele estava.
   */
  function rodapeDaCasa() {
    var onde = nomeDaMesa();
    return '<footer class="b-festa-pe">'
      + '<div class="filete" aria-hidden="true"><i></i><span></span><i></i></div>'
      + '<p>O pedido segue para a copa e um garçom leva-o'
      +   (onde ? ' à <b>' + esc(onde) + '</b>' : ' à sua mesa') + '.</p>'
      + '<p class="fraco">Pode fechar a página — quando voltar a abri-la, está '
      +   'tudo onde estava.</p>'
      + '</footer>';
  }

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
          // Com a unidade: é aqui que a pessoa confere o que pediu, e «2×
          // Tinto» não lhe diz se vêm dois copos ou duas garrafas. Quem
          // escolheu a garrafa por engano tem de o poder ver enquanto o
          // «Desistir» ainda está ao lado.
          var oq = p.itens.map(function (l) {
            return l.quantidade + '× ' + l.nome + ' (' + BP.unidade(l.unidade, l.quantidade) + ')';
          }).join(', ');
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
                  + 'font-size:var(--t-apoio)" onclick="barDesistir(' + p.id + ')">'
                  + ico.ico('xis') + 'Desistir</button>' : '')
            + '</div>';
        }).join('')
      + '</div>';
  }

  function pintarRodape() {
    var total = 0;
    Object.keys(cesto).forEach(function (k) { total += cesto[k]; });
    // Uma garrafa é UMA coisa pedida, ainda que leve seis copos lá dentro: o
    // rodapé conta o que a pessoa vai receber, e não as doses que isso gasta.
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
    $('b-pedir').disabled = !aberto || !!pausa;
  }

  // O cesto passa a ser por BEBIDA E UNIDADE: «2 copos de tinto» e «1 garrafa
  // de tinto» são duas linhas do mesmo pedido, e somá-las numa só perdia
  // justamente a coisa que a copa precisa de saber para servir.
  function chaveCesto(id, un) { return id + ':' + (un === 'garrafa' ? 'garrafa' : 'copo'); }
  // A unidade de origem de cada bebida: quem só se serve à garrafa abre na
  // garrafa, e o resto abre no copo.
  function unidadeBase(i) { return (i && i.servir === 'garrafa') ? 'garrafa' : 'copo'; }
  // Quantos copos gasta uma unidade — é por aqui que uma garrafa conta como as
  // doses que leva dentro, que é como o stock se conta.
  function custoDe(i, un) { return un === 'garrafa' ? Math.max(1, +i.doses_garrafa || 6) : 1; }

  /** Quantas desta bebida já estão no cesto, somando as duas unidades. */
  function itensNoCesto(i) {
    return ['copo', 'garrafa'].reduce(function (t, u) {
      return t + (cesto[chaveCesto(i.id, u)] || 0); }, 0);
  }

  /**
   * Ainda cabe mais uma? Duas contas, e são de unidades diferentes.
   *
   *   DOSES    — o stock (que se mede em copos, porque é o copo que acaba) e
   *              o tecto das regras. Uma garrafa de seis gasta seis.
   *   ARTIGOS  — o «máximo por pedido» desta bebida. Uma garrafa é UMA coisa
   *              pedida, ainda que leve seis copos lá dentro.
   *
   * Estavam somadas no mesmo número. O «máximo por pedido» nasce em dois e
   * uma garrafa tem seis doses: seis nunca é menor ou igual a dois, e por
   * isso o «+» da garrafa nascia desactivado — em toda a casa, com a carta
   * acabada de montar e nada por configurar. Nenhuma garrafa era pedível.
   */
  function cabeMais(i, un, gastoEmDoses) {
    var tecto = +i.max_itens || 1;
    return (gastoEmDoses + custoDe(i, un) <= i.pode_pedir)
        && (itensNoCesto(i) + 1 <= tecto);
  }

  window.barMais = function (id, un) {
    var i = menu.itens.filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    un = un === 'garrafa' ? 'garrafa' : 'copo';
    if (un === 'garrafa' && i.servir === 'copo') return;
    if (un === 'copo' && i.servir === 'garrafa') return;
    var k = chaveCesto(id, un);
    var n = (cesto[k] || 0) + 1;
    // O que já está no cesto DESTA bebida conta para o tecto, nas duas
    // unidades: três copos e uma garrafa de seis são nove doses, e não uma.
    var jaGasto = 0;
    ['copo', 'garrafa'].forEach(function (u) {
      var q = cesto[chaveCesto(id, u)] || 0;
      if (q) jaGasto += q * custoDe(i, u);
    });
    if (!cabeMais(i, un, jaGasto)) return;
    cesto[k] = n;
    pintarMenu();
  };
  window.barMenos = function (id, un) {
    var k = chaveCesto(id, un);
    var n = (cesto[k] || 0) - 1;
    if (n > 0) cesto[k] = n; else delete cesto[k];
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

  /* A janela «Onde entregamos?» saiu daqui.
     Era um modal com uma lista lá dentro e um botão a confirmar: quatro gestos
     para dizer o que a própria barra passou a dizer num. Fica registado porque
     o gesto não desapareceu — mudou de sítio, e está em montarBarraMesa(). */

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
      +   'style="width:100%;font-size:var(--t-corpo);padding:.8rem .9rem;border-radius:12px;'
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
    mesaEntrega = eu && eu.mesa_id ? await mesaPorId(eu.mesa_id) : mesaDeEntrada();
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
      var p = k.split(':');
      return { item_id: parseInt(p[0], 10), unidade: p[1] || 'copo', quantidade: cesto[k] };
    });
    if (!itens.length) return;
    var bt = $('b-pedir');
    var rotulo = bt.textContent;
    bt.disabled = true; bt.textContent = 'A enviar…';
    // Zero quando não há mesa — e aí o servidor pergunta-a, em vez de deixar
    // o pedido cair na copa com o destino em branco.
    var corpo = { itens: itens, mesa_id: (mesaEntrega && mesaEntrega.id) || MESA.id,
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
      mesaEntrega = eu && eu.mesa_id ? await mesaPorId(eu.mesa_id) : mesaDeEntrada();
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
    if (!d.success) { esqueletoPosto = false; $('b-corpo').innerHTML = falhou(d); return; }
    menu = { categorias: d.categorias, itens: d.itens };
    // Cada bebida travada leva o INSTANTE em que abre, e não os segundos que
    // faltavam quando a resposta chegou: é o que faz o cartão dela ser igual
    // de pintura para pintura enquanto o relógio anda.
    var agora = Date.now();
    menu.itens.forEach(function (i) {
      i.ate = (i.espera_s > 0) ? agora + i.espera_s * 1000 : 0;
    });
    aberto = !!d.aberto;
    ritmo = d.ritmo || null;
    travaoPedido = d.pedido || null;
    pausa = d.pausa || null;
    pausaAte  = pausa ? Date.now() + pausa.espera_s * 1000 : 0;
    ritmoAte  = (ritmo && ritmo.espera_s > 0) ? Date.now() + ritmo.espera_s * 1000 : 0;
    travaoAte = (travaoPedido && travaoPedido.espera_s > 0)
              ? Date.now() + travaoPedido.espera_s * 1000 : 0;
    // Uma bebida que desapareceu do menu não pode ficar no cesto.
    Object.keys(cesto).forEach(function (k) {
      var i = menu.itens.filter(function (x) { return String(x.id) === k; })[0];
      if (!i || i.pode_pedir <= 0) { delete cesto[k]; return; }
      var un = k.split(':')[1] || 'copo';
      // Uma bebida que passou a servir-se só ao copo deixa cair as garrafas
      // que alguém tivesse no cesto: o menu mudou debaixo dela.
      if ((un === 'garrafa' && i.servir === 'copo') ||
          (un === 'copo' && i.servir === 'garrafa')) { delete cesto[k]; return; }
      // As duas contas outra vez: quantas cabem em DOSES, e quantas cabem em
      // ARTIGOS. A menor das duas é o que fica no cesto.
      var porDoses = Math.floor(i.pode_pedir / custoDe(i, un));
      var tecto = Math.min(porDoses, +i.max_itens || 1);
      if (cesto[k] > tecto) { if (tecto > 0) cesto[k] = tecto; else delete cesto[k]; }
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
    if (!d.success) { esqueletoPosto = false; $('b-corpo').innerHTML = falhou(d); return; }
    aberto = !!d.aberto;
    msgFechado = d.mensagem_fechado || msgFechado;
    procuraMin = d.procura_min || 4;
    if (d.mesa) MESA = d.mesa;
    if (d.eu) {
      eu = d.eu;
      // Ao voltar à página, a mesa de entrega volta a ser a da pessoa (ou a do
      // QR): uma escolha feita há duas horas já não diz onde ela está agora.
      mesaEntrega = eu.mesa_id ? await mesaPorId(eu.mesa_id) : mesaDeEntrada();
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
  /**
   * Os relógios das esperas, ao segundo.
   *
   * É este que escreve o número dentro de cada contagem — o html nasce sem ele
   * de propósito, para uma faixa com relógio ser igual de volta para volta e a
   * secção dela não se ter de reescrever (ver `pintarMenu`). Escrever aqui é
   * mexer no texto de um <b>: não refaz nada, não pisca, e não custa nada.
   *
   * `sohEscrever` é para a chamada que vem logo a seguir a pintar: aí só se
   * quer pôr os números no sítio. Recarregar o menu de dentro da pintura era
   * um ciclo — pintar, ver um relógio a zero, recarregar, pintar.
   */
  function tique(sohEscrever) {
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
    if (acabou && !sohEscrever && !document.hidden) carregarMenu();
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
