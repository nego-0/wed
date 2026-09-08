/* ============================================================
   bar-montagem.js — A montagem do bar (bar.php)

   O menu antes da festa: as gavetas, as bebidas com fotografia e stock, e as
   folhas de QR para pousar nas mesas. Uma leitura só (bar_estado) traz tudo; o
   servidor é que manda, e aqui limitamo-nos a desenhar.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var PODE = !window.SO_VER_UI;
  var EST = null;

  // As peças comuns aos quatro ecrãs do bar (assets/bar-pecas.js). Estavam
  // escritas aqui, e outra vez na copa, e outra vez nas entregas — com
  // pequenas diferenças que ninguém escolheu. Agora são as mesmas.
  var ico = window.ICO, BP = window.BP;
  var esc = BP.esc, toast = BP.toast, chave = BP.chave, vazio = BP.vazio;
  var campoBusca = BP.campoBusca, ligarBusca = BP.ligarBusca;
  var btIco = BP.btIco;

  /* ---- estado do ecrã: o que a barra de ferramentas escolheu ----
     Vive fora de EST porque não vem do servidor: é a pergunta que quem está a
     olhar está a fazer ao menu. Sobrevive a um recarregar dos dados, que é o
     que se quer — mudar uma bebida não pode desfazer o filtro. */
  var VER = { busca: '', gaveta: 0, estado: 'todas', buscaGav: '', buscaMot: '', buscaGente: '' };

  /**
   * A capa de uma bebida: a fotografia, ou a chapa da gaveta.
   *
   * Sem fotografia havia um rectângulo de cor cheia com a inicial em corpo
   * grande. Dezasseis desses numa grelha são uma parede de tinta — e quando
   * chega uma fotografia a sério, é ELA que fica a parecer o intruso. A chapa
   * faz o contrário: um véu da cor da gaveta e o copo desenhado a traço. Diz
   * mais (aquilo é uma cerveja) e pesa muito menos.
   */
  function capa(item) {
    var marcas = '';
    if (item.estado === 'oculto') {
      marcas += '<span class="b-marca oculta">' + ico.ico('olhoFechado') + 'Escondida</span>';
    }
    if (item.stock <= 0) {
      marcas += '<span class="b-marca zero">' + ico.ico('aviso') + 'Esgotada</span>';
    } else if (item.a_acabar) {
      marcas += '<span class="b-marca pouca">' + ico.ico('aviso') + 'Resta pouco</span>';
    }
    var dentro = item.foto
      ? '<img src="' + esc(item.foto) + '" alt="" loading="lazy" decoding="async">'
      : '<div class="b-chapa">' + ico.copo(item.nome, item.categoria) + '</div>';
    // O álcool é uma marca de canto e não uma palavra na linha da gaveta:
    // «ESPUMANTES E VINHOS · COM ÁLCOOL» partia a linha em duas num cartão de
    // 250px, e duas linhas de maiúsculas pequenas por cima do nome empurravam
    // o nome — que é o que se lê primeiro — para baixo.
    var alc = item.alcoolico
      ? '<span class="alc" title="Com álcool" aria-label="Com álcool">'
        + ico.ico('gota') + '</span>' : '';
    return '<div class="capa' + (item.foto ? '' : ' faixa') + '">' + dentro + alc
      + (marcas ? '<div class="marcas">' + marcas + '</div>' : '') + '</div>';
  }

  /** O medidor: o que há, o que está prometido, e a barra que se lê sem contar. */
  function medidor(i) {
    var stock = Math.max(0, i.stock || 0);
    var preso = Math.max(0, i.reservado || 0);
    var livre = Math.max(0, i.disponivel || 0);
    var classe = stock <= 0 ? ' zero' : (i.a_acabar ? ' pouca' : '');
    var pLivre = stock > 0 ? Math.round(livre / stock * 100) : 0;
    var pPreso = stock > 0 ? Math.round(preso / stock * 100) : 0;
    return '<div class="b-medidor' + classe + '">'
      + '<div class="linha"><b>' + livre + '</b> por servir'
      +   (preso ? '<span class="dir">' + preso + ' prometidas</span>' : '')
      + '</div>'
      + '<div class="barra" role="img" aria-label="' + livre + ' por servir de ' + stock + '">'
      +   '<span class="livre" style="width:' + pLivre + '%"></span>'
      +   '<span class="preso" style="width:' + pPreso + '%"></span>'
      + '</div></div>';
  }

  // ---- carregar e desenhar ------------------------------------
  async function carregar() {
    var d = await window.api('bar_estado', { method: 'GET' });
    if (!d || !d.success) return;
    EST = d;
    pintarChave();
    pintarFerramentas();
    pintarMenu();
    pintarGavetas();
  }

  function pintarChave() {
    var aberto = !!(EST.estado && EST.estado.aberto);
    var itens = EST.itens || [];
    $('b-chave').classList.toggle('on', aberto);
    // O farol responde à pergunta antes de se ler o texto — e é o texto que a
    // confirma, porque um sinal sozinho nunca chega (§25.7).
    $('b-farol').innerHTML = ico.ico(aberto ? 'aberto' : 'trancado');
    $('b-est').innerHTML = 'A copa está <b>' + (aberto ? 'aberta' : 'fechada') + '</b>';
    // Três números que contam a montagem: quantas bebidas no menu, quantas
    // garrafas no armazém, e quantas estão a acabar. A terceira é a única que
    // pede uma acção, e por isso é a única que muda de cor.
    var poucas = itens.filter(function (i) { return i.estado === 'ativo' && i.a_acabar; }).length;
    var garrafas = itens.reduce(function (t, i) { return t + Math.max(0, i.stock || 0); }, 0);
    $('b-numeros').innerHTML =
        '<div><b>' + itens.filter(function (i) { return i.estado === 'ativo'; }).length
      +   '</b><small>no menu</small></div>'
      + '<div><b>' + garrafas + '</b><small>em stock</small></div>'
      + (poucas ? '<div><b style="color:var(--warn)">' + poucas + '</b><small>a acabar</small></div>' : '');
    var bt = $('b-chave-bt');
    bt.innerHTML = ico.ico(aberto ? 'trancado' : 'aberto')
      + (aberto ? 'Fechar o bar' : 'Abrir o bar');
    bt.className = 'btn ' + (aberto ? 'btn-fantasma' : 'btn-ouro');
    bt.disabled = !PODE;
  }

  // ---- a barra de ferramentas ---------------------------------
  function pintarFerramentas() {
    var cats = EST.categorias || [];
    var itens = EST.itens || [];
    var conta = function (f) { return itens.filter(f).length; };

    $('b-fer-menu').innerHTML =
        campoBusca('b-q-menu', 'Procurar uma bebida…', VER.busca)
      + '<div class="b-pastilhas">'
      +   pilula('todas',      'Todas',          null,          conta(function () { return true; }))
      +   pilula('poucas',     'A acabar',       'aviso',       conta(function (i) { return i.a_acabar; }))
      +   pilula('escondidas', 'Escondidas',     'olhoFechado', conta(function (i) { return i.estado === 'oculto'; }))
      +   pilula('sem_foto',   'Sem fotografia', 'maquina',     conta(function (i) { return !i.foto; }))
      + '</div>'
      + '<span class="cresce"></span>'
      + (PODE ? '<button class="btn btn-ouro" onclick="barNova()">'
              + ico.ico('mais') + 'Nova bebida</button>' : '');

    // As gavetas são uma segunda fila: são dados, e podem ser muitas.
    $('b-filtros').innerHTML = cats.length
      ? BP.pilula({ rot: 'Todas as gavetas', ligada: VER.gaveta === 0,
                    accao: 'barFiltroGaveta(0)' })
        + cats.map(function (c) {
            var n = itens.filter(function (i) { return +i.categoria_id === +c.id; }).length;
            return BP.pilula({ rot: c.nome, cor: c.cor || '#b9c2bb', n: n,
                               ligada: +VER.gaveta === +c.id,
                               accao: 'barFiltroGaveta(' + c.id + ')' });
          }).join('')
      : '';

    $('b-fer-gav').innerHTML = campoBusca('b-q-gav', 'Procurar uma gaveta…', VER.buscaGav)
      + '<span class="cresce"></span>'
      + (PODE ? '<button class="btn btn-ouro" onclick="barGaveta()">'
              + ico.ico('mais') + 'Nova gaveta</button>' : '');
    ligarBusca('b-q-menu', function (v) { VER.busca = v; pintarMenu(); });
    ligarBusca('b-q-gav', function (v) { VER.buscaGav = v; pintarGavetas(); });
  }

  function pilula(qual, rot, icone, n) {
    return BP.pilula({ rot: rot, icone: icone, n: n, ligada: VER.estado === qual,
                       accao: 'barFiltroEstado(\'' + qual + '\')' });
  }
  window.barFiltroEstado = function (k) { VER.estado = k; pintarFerramentas(); pintarMenu(); };
  window.barFiltroGaveta = function (id) { VER.gaveta = +id; pintarFerramentas(); pintarMenu(); };

  /** O menu, já passado pelo que a barra de ferramentas escolheu. */
  function peneira(itens) {
    var q = chave(VER.busca);
    return itens.filter(function (i) {
      if (VER.gaveta && +i.categoria_id !== +VER.gaveta) return false;
      if (VER.estado === 'poucas' && !i.a_acabar) return false;
      if (VER.estado === 'escondidas' && i.estado !== 'oculto') return false;
      if (VER.estado === 'sem_foto' && i.foto) return false;
      if (q && chave(i.nome + ' ' + (i.descricao || '') + ' ' + (i.categoria || '')).indexOf(q) < 0) return false;
      return true;
    });
  }

  function pintarMenu() {
    var cx = $('b-grelha');
    var todos = EST.itens || [];
    if (!todos.length) {
      cx.innerHTML = vazio('taca', 'O menu está vazio',
        'A primeira bebida é a que dá vontade às outras.',
        PODE ? '<button class="btn btn-ouro" onclick="barNova()">'
             + ico.ico('mais') + 'Primeira bebida</button>' : '');
      return;
    }
    var itens = peneira(todos);
    if (!itens.length) {
      // Um filtro que não devolve nada tem de dizer QUE filtro é — senão
      // lê-se como «o menu está vazio», e a pessoa vai criar uma bebida que
      // já lá está.
      cx.innerHTML = vazio('procurar', 'Nada com esse critério',
        'Há ' + todos.length + ' bebidas no menu; nenhuma responde ao que está a pedir.',
        '<button class="btn" onclick="barLimparFiltros()">' + ico.ico('volta')
        + 'Ver todas outra vez</button>');
      return;
    }
    cx.innerHTML = itens.map(cartao).join('');
  }

  window.barLimparFiltros = function () {
    VER = { busca: '', gaveta: 0, estado: 'todas', buscaGav: VER.buscaGav,
            buscaMot: VER.buscaMot, buscaGente: VER.buscaGente };
    pintarFerramentas(); pintarMenu();
  };

  function cartao(i) {
    var cor = i.categoria_cor || '#b9c2bb';
    return '<div class="b-cart' + (i.estado === 'oculto' ? ' oculta' : '') + '" '
      + 'style="--tinta:' + esc(cor) + '">'
      + capa(i)
      + '<div class="corpo">'
      +   '<span class="gav"><i></i>' + esc(i.categoria || 'sem gaveta') + '</span>'
      +   '<div class="nm">' + esc(i.nome) + '</div>'
      +   (i.descricao ? '<div class="ds">' + esc(i.descricao) + '</div>' : '')
      +   medidor(i)
      +   (PODE ? '<div class="acs">'
      +     btIco('lapis',   'Editar',                 'barEditar(' + i.id + ')')
      +     btIco('maquina', i.foto ? 'Trocar a fotografia' : 'Pôr uma fotografia',
                             'barFoto(' + i.id + ')')
      +     btIco('caixa',   'Somar ao stock',         'barRepor(' + i.id + ')')
      +     btIco('lixo',    'Apagar do menu',         'barApagar(' + i.id + ')', 'perigo fim')
      +   '</div>' : '')
      + '</div></div>';
  }

  function pintarGavetas() {
    var cx = $('b-cats');
    var cats = EST.categorias || [];
    var q = chave(VER.buscaGav || '');
    var lista = q ? cats.filter(function (c) { return chave(c.nome).indexOf(q) >= 0; }) : cats;
    if (!cats.length) {
      cx.innerHTML = vazio('caixa', 'Ainda sem gavetas',
        'As gavetas arrumam o menu e dão-lhe a cor que o convidado vê.',
        PODE ? '<button class="btn btn-ouro" onclick="barGaveta()">' + ico.ico('mais')
             + 'Primeira gaveta</button>' : '');
      return;
    }
    if (!lista.length) {
      cx.innerHTML = vazio('procurar', 'Nenhuma gaveta com esse nome', 'São ' + cats.length + '.', '');
      return;
    }
    cx.innerHTML = lista.map(function (c) {
      var n = (EST.itens || []).filter(function (i) { return +i.categoria_id === +c.id; }).length;
      return '<div class="b-cat" style="--tinta:' + esc(c.cor || '#b9c2bb') + '">'
        + '<span class="sinal">' + ico.copo(c.nome) + '</span>'
        + '<span class="txt"><b>' + esc(c.nome) + '</b>'
        +   '<small>' + (n === 1 ? '1 bebida' : n + ' bebidas') + '</small></span>'
        + (PODE ? btIco('lapis', 'Mudar a gaveta', 'barGaveta(' + c.id + ')')
                + btIco('lixo', 'Apagar a gaveta', 'barGavetaApagar(' + c.id + ',\''
                  + esc(c.nome).replace(/'/g, '&#39;') + '\')', 'perigo') : '')
        + '</div>';
    }).join('');
  }

  // ---- as abas ----
  // Os rótulos moram aqui e não no HTML porque levam ícone, e um SVG inline no
  // meio de um <button> em PHP é ilegível para quem lá voltar.
  var ABAS = { menu:   ['taca',     'O menu'],
               gav:    ['caixa',    'Gavetas'],
               mesas:  ['qr',       'Mesas e QR'],
               regras: ['trancado', 'Regras da casa'],
               gente:  ['pessoas',  'A equipa'] };
  Object.keys(ABAS).forEach(function (k) {
    var el = $('ab-' + k); if (!el) return;
    el.innerHTML = ico.ico(ABAS[k][0]) + ABAS[k][1];
  });

  window.barAba = function (qual) {
    Object.keys(ABAS).forEach(function (k) {
      $('ab-' + k).classList.toggle('on', k === qual);
      $('ab-' + k).setAttribute('aria-selected', k === qual ? 'true' : 'false');
      $('pn-' + k).hidden = k !== qual;
    });
    if (qual === 'mesas')  pintarFolhas();
    if (qual === 'regras') pintarRegras();
    if (qual === 'gente')  pintarEquipa();
  };

  /* ============================================================
     AS REGRAS DA CASA, E OS MOTIVOS DE RECUSA

     Vinham da copa, em duas janelas de atalho. Estavam no sítio errado por
     duas razões: são decisões do CASAL (o que a casa serve, e como fala com
     quem recusa), e tomam-se ANTES da festa, com tempo — não às onze da noite
     entre dois pedidos, num tablet à meia-luz.

     O que fica na copa é o que lá tem de estar: escrever um motivo à mão em
     cada recusa. A lista poupa a escrita; nunca a proíbe.
     ============================================================ */
  var DEFINICOES = [
    ['bar.mensagem_fechado', 'O que dizer quando está fechado', 'area',
     'Aparece no menu do convidado enquanto a copa não abre. '
   + 'Ex.: «O bar abre depois do brinde, por volta das 21h.»'],
    ['bar.procura_min', 'Letras para procurar o nome', 'numero',
     'Quantas letras o convidado escreve antes de a lista aparecer. '
   + 'Menos letras, mais nomes de cada vez.'],
    ['bar.ip_modo', 'Pedidos da mesma rede', 'escolha',
     'Numa festa quase todos partilham o mesmo wi-fi: «estrito» é para salas '
   + 'onde cada mesa tem a sua rede.'],
    ['bar.trocar_nome', 'Trocar de nome no mesmo telemóvel', 'sim',
     'Um telemóvel por pessoa é a regra. Isto abre a excepção — e avisa a copa '
   + 'sempre que acontece.']
  ];
  var IP_MODOS = { registo: 'Registar, sem incomodar',
                   aviso:   'Registar e avisar a copa',
                   estrito: 'Recusar o segundo nome' };

  function pintarRegras() {
    var f = (EST && EST.defs) || {};
    var tits = document.querySelectorAll('#pn-regras .b-sec');
    if (tits[0]) tits[0].innerHTML = ico.ico('trancado') + 'Como o bar se porta';
    if (tits[1]) tits[1].innerHTML = ico.ico('nota') + 'Motivos de recusa';

    $('b-defs').innerHTML = DEFINICOES.map(function (d) {
      var v = f[d[0]];
      var lido, fraco = false;
      if (d[2] === 'sim')      { lido = v === '1' ? 'Sim' : 'Não'; fraco = v !== '1'; }
      else if (d[2] === 'escolha') lido = IP_MODOS[v] || IP_MODOS.registo;
      else if (d[0] === 'bar.procura_min') lido = (v || '4') + ' letras';
      else { lido = v ? '«' + v + '»' : 'sem texto'; fraco = !v; }
      return '<div class="b-def"><span class="txt"><b>' + esc(d[1]) + '</b>'
        + '<small>' + esc(d[3]) + '</small></span>'
        + '<span class="val' + (fraco ? ' nao' : '') + '">' + esc(lido) + '</span></div>';
    }).join('')
      + (PODE ? '<div style="margin-top:1rem"><button class="btn btn-ouro" '
              + 'onclick="barRegrasEditar()">' + ico.ico('lapis') + 'Mudar as regras</button></div>'
              : '');

    // A procura só se monta uma vez; a lista repinta-se sempre.
    if (!$('q-mot') && PODE) {
      $('b-fer-mot').innerHTML = campoBusca('q-mot', 'Procurar um motivo', VER.buscaMot || '')
        + '<button class="btn btn-ouro" onclick="barMotivoNovo()">'
        + ico.ico('mais') + 'Motivo</button>';
      ligarBusca('q-mot', function (v) { VER.buscaMot = v; pintarMotivos(); });
    }
    pintarMotivos();
  }

  function pintarMotivos() {
    var cx = $('b-motivos'); if (!cx) return;
    var todos = (EST.motivos || []);
    if (!todos.length) {
      cx.innerHTML = vazio('nota', 'Ainda sem motivos guardados',
        'Sem lista, o copeiro escreve tudo à mão — o que a meio da noite '
      + 'quer dizer que escreve pouco.',
        PODE ? '<button class="btn btn-ouro" onclick="barMotivoNovo()">'
             + ico.ico('mais') + 'Primeiro motivo</button>' : '');
      return;
    }
    var q = chave(VER.buscaMot || '');
    var lista = q ? todos.filter(function (m) { return chave(m.texto).indexOf(q) >= 0; }) : todos;
    if (!lista.length) {
      cx.innerHTML = vazio('procurar', 'Nada com esse nome', 'São ' + todos.length + ' motivos.');
      return;
    }
    cx.innerHTML = lista.map(function (m) {
      return '<div class="b-mot"><span>' + esc(m.texto) + '</span>'
        + (PODE ? btIco('lixo', 'Tirar este motivo', 'barMotivoApagar(' + m.id + ')', 'perigo') : '')
        + '</div>';
    }).join('');
  }

  window.barMotivoNovo = function () {
    licFormulario({
      titulo: 'Motivo de recusa',
      guardar: 'Guardar',
      dica: 'O convidado lê isto no telemóvel. Escreva-o como o diria em pessoa — '
          + 'e diga o que HÁ, não só o que falta.',
      campos: [{ id: 'texto', rot: 'O motivo', valor: '', largura: 2,
                 dica: 'Ex.: «Acabou o espumante — temos vinho branco fresco.»' }],
      aoGuardar: async function (v) {
        if (!v.texto) { licJanelaErro('Escreva o motivo.'); return false; }
        var d = await window.api('bar_motivo_guardar', { method: 'POST',
          body: JSON.stringify({ texto: v.texto }) });
        if (!d || !d.success) return false;
        toast('Motivo guardado.');
        await carregar();
        pintarRegras();
        return true;
      }
    });
  };

  window.barMotivoApagar = async function (id) {
    var m = (EST.motivos || []).filter(function (x) { return +x.id === +id; })[0];
    var r = await licConfirmar({ titulo: 'Tirar este motivo?', icone: 'lixo', perigo: true,
      texto: m ? '«' + m.texto + '» deixa de aparecer na lista do copeiro. As recusas que '
                 + 'já o usaram ficam como estão.' : '',
      confirmar: 'Tirar', cancelar: 'Deixar' });
    if (!r.sim) return;
    var d = await window.api('bar_motivo_apagar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Fora da lista.');
    await carregar();
    pintarRegras();
  };

  window.barRegrasEditar = function () {
    var f = (EST && EST.defs) || {};
    licFormulario({
      titulo: 'Regras da casa',
      guardar: 'Guardar',
      largo: true,
      dica: 'Como o bar se porta com quem pede. Pode mudar-se a meio da noite, '
          + 'e vale a partir do instante seguinte.',
      campos: [
        { id: 'bar.mensagem_fechado', rot: 'O que dizer quando está fechado', tipo: 'area',
          linhas: 2, valor: f['bar.mensagem_fechado'] || '', largura: 2,
          dica: 'Ex.: «O bar abre depois do brinde, por volta das 21h.»' },
        { id: 'bar.procura_min', rot: 'Letras para procurar o nome', tipo: 'numero',
          valor: f['bar.procura_min'] || '4', min: 1, max: 8,
          dica: 'Menos letras, mais nomes de cada vez na lista.' },
        { id: 'bar.ip_modo', rot: 'Pedidos da mesma rede', tipo: 'escolha',
          valor: f['bar.ip_modo'] || 'registo',
          opcoes: Object.keys(IP_MODOS).map(function (k) { return { v: k, r: IP_MODOS[k] }; }),
          dica: 'Numa festa quase todos partilham o mesmo wi-fi: «estrito» é '
              + 'para salas onde cada mesa tem a sua rede.' },
        { id: 'bar.trocar_nome', rot: 'Trocar de nome no mesmo telemóvel', tipo: 'sim',
          valor: f['bar.trocar_nome'] === '1', aoLado: 'Deixar, avisando a copa', largura: 2,
          dica: 'Um telemóvel por pessoa é a regra da casa (§5.3). Isto abre a '
              + 'excepção — e a copa fica a saber de cada vez que acontece.' }
      ],
      aoGuardar: async function (v) {
        var env = {};
        Object.keys(v).forEach(function (k) {
          env[k] = (k === 'bar.trocar_nome') ? (v[k] ? '1' : '0') : String(v[k]);
        });
        var d = await window.api('bar_defs', { method: 'POST', body: JSON.stringify(env) });
        if (!d || !d.success) return false;
        toast('Regras guardadas.');
        await carregar();
        pintarRegras();
        return true;
      }
    });
  };

  /* ============================================================
     A EQUIPA DO BAR

     Os dois postos criavam-se na Gestão, no meio das contas da casa. Só que
     quem monta o bar é quem depois precisa de um copeiro — e mandá-lo a outra
     página, procurar entre porteiros e noivos, para criar a conta de alguém
     que vai trabalhar NESTE ecrã, é fazer o caminho todo ao contrário.

     Aqui ficam os dois postos, as contas de cada um, e a porta para os dois
     ecrãs: os noivos são a casa, e a casa tem de poder decidir um pedido ou
     levar uma bebida quando falta alguém.
     ============================================================ */
  var POSTOS = {
    copeiro:    ['taca',   'Copeiro', 'Decide os pedidos e trata do stock.', 'copa.php', 'Abrir a copa'],
    entregador: ['mao',    'Garçom',  'Leva as bebidas às mesas.',   'entregas.php', 'Abrir as entregas']
  };
  var equipa = null;

  async function pintarEquipa() {
    var cx = $('b-postos');
    if (cx) {
      cx.innerHTML = Object.keys(POSTOS).map(function (k) {
        var p = POSTOS[k];
        // O sinal do copeiro é um copo a sério e não um glifo de interface:
        // é o que ele serve. Vem da caixa dos copos, pelo nome.
        var sinal = k === 'copeiro' ? ico.svg(ico.copos.taca) : ico.ico(p[0]);
        return '<div class="b-posto"><span class="sinal">' + sinal + '</span>'
          + '<span class="txt"><b>' + esc(p[1]) + '</b><small>' + esc(p[2]) + '</small></span>'
          + '<a class="btn btn-fantasma" href="' + p[3] + '">' + ico.ico('porta') + esc(p[4]) + '</a>'
          + '</div>';
      }).join('');
    }
    if (!$('q-gente') && PODE) {
      $('b-fer-gente').innerHTML = campoBusca('q-gente', 'Procurar por nome ou email', VER.buscaGente || '')
        + '<button class="btn btn-ouro" onclick="barContaNova()">'
        + ico.ico('mais') + 'Conta nova</button>';
      ligarBusca('q-gente', function (v) { VER.buscaGente = v; listarEquipa(); });
    }
    var d = await window.api('acesso_lista', { method: 'GET', silencioso: true });
    equipa = (d && d.success) ? d : { acessos: [], eu: 0 };
    listarEquipa();
  }

  function listarEquipa() {
    var cx = $('b-equipa'); if (!cx || !equipa) return;
    // Só os do BAR: os porteiros e os noivos gerem-se na Gestão, que é onde
    // eles pertencem. Este separador é do bar e mostra o bar.
    var todos = (equipa.acessos || []).filter(function (a) {
      return a.papel === 'copeiro' || a.papel === 'entregador';
    });
    if (!todos.length) {
      cx.innerHTML = vazio('pessoas', 'Ainda sem equipa',
        'Enquanto não houver contas, a copa e as entregas só se abrem daqui — '
      + 'por vocês, com a vossa conta.',
        PODE ? '<button class="btn btn-ouro" onclick="barContaNova()">'
             + ico.ico('mais') + 'Primeira conta</button>' : '');
      return;
    }
    var q = chave(VER.buscaGente || '');
    var lista = q ? todos.filter(function (a) {
      return chave((a.nome || '') + ' ' + a.email).indexOf(q) >= 0;
    }) : todos;
    if (!lista.length) {
      cx.innerHTML = vazio('procurar', 'Ninguém com esse nome', 'São ' + todos.length + ' contas.');
      return;
    }
    cx.innerHTML = lista.map(function (a) {
      var p = POSTOS[a.papel] || ['pessoa', a.papel, ''];
      var fora = a.estado !== 'ativo';
      return '<div class="b-conta">'
        + '<span class="quem"><b>' + esc(a.nome || a.email) + '</b>'
        +   '<small>' + esc(a.nome ? a.email : 'sem nome') + '</small></span>'
        + '<span class="papel' + (fora ? ' fora' : '') + '">' + esc(p[1]) + '</span>'
        + (PODE ? btIco('mudar', 'Trocar de posto',
                        'barContaPosto(' + a.utilizador_id + ')')
                + btIco('lixo', 'Tirar do bar',
                        'barContaTirar(' + a.utilizador_id + ')', 'perigo') : '')
        + '</div>';
    }).join('');
  }

  window.barContaNova = function () {
    licFormulario({
      titulo: 'Conta nova para o bar',
      guardar: 'Criar a conta',
      largo: true,
      dica: 'A senha aparece a seguir, uma vez. Copie-a e entregue-a em mão — '
          + 'não há correio configurado, e prometer um envio que não acontece '
          + 'era pior do que dizer isto.',
      campos: [
        { id: 'nome',  rot: 'Nome',  valor: '', dica: 'Para vocês saberem de quem é a conta.' },
        { id: 'email', rot: 'Email', valor: '', dica: 'É com ele que a pessoa entra.' },
        { id: 'papel', rot: 'Posto', tipo: 'escolha', valor: 'copeiro', largura: 2,
          opcoes: Object.keys(POSTOS).map(function (k) {
            return { v: k, r: POSTOS[k][1] + ' — ' + POSTOS[k][2] };
          }) }
      ],
      aoGuardar: async function (v) {
        if (!v.email) { licJanelaErro('Escreva o email.'); return false; }
        var d = await window.api('acesso_convidar', { method: 'POST',
          body: JSON.stringify({ email: v.email, nome: v.nome, papel: v.papel }) });
        if (!d || !d.success) return false;
        licFecharJanela();
        licJanela('Conta criada',
          '<p class="dica" style="margin:0 0 .4rem">A senha de <b>' + esc(v.email)
          + '</b>. Aparece uma vez só.</p>'
          + '<div class="b-senha">' + esc(d.senha) + '</div>'
          + '<p class="dica">Quem entrar com ela aterra logo '
          + (v.papel === 'copeiro' ? 'na copa' : 'nas entregas') + '.</p>',
          null, { cancelar: 'Já copiei' });
        await pintarEquipa();
        return false;
      }
    });
  };

  window.barContaPosto = async function (uid) {
    var a = ((equipa || {}).acessos || []).filter(function (x) { return +x.utilizador_id === +uid; })[0];
    if (!a) return;
    var novo = a.papel === 'copeiro' ? 'entregador' : 'copeiro';
    var r = await licConfirmar({ titulo: 'Trocar de posto', icone: 'mudar',
      texto: esc(a.nome || a.email) + ' passa de ' + POSTOS[a.papel][1]
           + ' a ' + POSTOS[novo][1] + '. Entra no outro ecrã da próxima vez '
           + 'que abrir a página.',
      confirmar: 'Trocar', cancelar: 'Deixar' });
    if (!r.sim) return;
    var d = await window.api('acesso_papel&utilizador=' + uid + '&papel=' + novo, { method: 'POST' });
    if (!d || !d.success) return;
    toast('Posto trocado.');
    await pintarEquipa();
  };

  window.barContaTirar = async function (uid) {
    var a = ((equipa || {}).acessos || []).filter(function (x) { return +x.utilizador_id === +uid; })[0];
    if (!a) return;
    var r = await licConfirmar({ titulo: 'Tirar do bar', icone: 'lixo', perigo: true,
      texto: esc(a.nome || a.email) + ' deixa de entrar no bar. O que já decidiu ou '
           + 'entregou fica no histórico com o nome dele — tirar o acesso não '
           + 'apaga o trabalho.',
      confirmar: 'Tirar', cancelar: 'Deixar ficar' });
    if (!r.sim) return;
    var d = await window.api('acesso_tirar&utilizador=' + uid, { method: 'POST' });
    if (!d || !d.success) return;
    toast('Fora do bar.');
    await pintarEquipa();
  };

  // ---- as folhas das mesas ----
  var folhasFeitas = false;
  function pintarFolhas() {
    if (folhasFeitas) return;
    folhasFeitas = true;
    var cx = $('b-folhas');
    var mesas = window.BAR_MESAS || [];
    if (!mesas.length) {
      cx.innerHTML = '<div class="b-vazio">Ainda não há mesas. Crie-as na planta, e as folhas saem daqui.</div>';
      return;
    }
    cx.innerHTML = mesas.map(function (m) {
      var url = window.BAR_ENDERECO + '/bebidas.php?m=' + m.token;
      return '<div class="b-folha"><div class="mesa">' + esc(m.nome) + '</div>'
        + '<canvas class="b-qr" data-link="' + esc(url) + '"></canvas>'
        + '<div class="lnk">' + esc(url) + '</div>'
        + '<div class="rod">Sem rede? Chame um garçom — ele faz o pedido por si.</div></div>';
    }).join('');
    cx.querySelectorAll('canvas.b-qr').forEach(function (cv) {
      try {
        new QRious({ element: cv, value: cv.dataset.link, size: 150, level: 'M',
                     background: '#fff', foreground: '#20342A' });
      } catch (e) {}
    });
  }

  // ---- abrir e fechar ----
  window.barChave = async function () {
    var aberto = !!(EST.estado && EST.estado.aberto);
    if (aberto) {
      var r = await licConfirmar({
        titulo: 'Fechar o bar?', icone: 'trancado', confirmar: 'Fechar',
        texto: 'Os convidados deixam de poder pedir. Os pedidos que já estão na fila '
             + 'continuam lá — fechar não os apaga.'
      });
      if (!r.sim) return;
    }
    var d = await window.api(aberto ? 'bar_fechar' : 'bar_abrir', { method: 'POST' });
    if (!d || !d.success) return;
    toast(d.aberto ? 'O bar está aberto.' : 'O bar está fechado.');
    carregar();
  };

  // ---- gavetas ----
  window.barGaveta = function (id) {
    var c = (EST.categorias || []).find(function (x) { return +x.id === +id; }) || {};
    licFormulario({
      titulo: id ? 'Mudar a gaveta' : 'Gaveta nova', guardar: 'Guardar',
      dica: 'A cor da gaveta é a que o convidado vê ao lado de cada bebida, e '
          + 'a que o menu usa quando ainda não há fotografia.',
      campos: [
        { id: 'nome', rot: 'Nome', valor: c.nome || '', largura: 2,
          dica2: 'Cervejas, Destilados, Sem álcool…' },
        // Um campo de texto a pedir «#rrggbb» é um teste de conhecimentos:
        // ninguém escolhe uma cor assim, e quem tentar acaba com um roxo que
        // berra ao lado do verde da casa.
        { id: 'cor', rot: 'Cor', tipo: 'cor', valor: c.cor || '#B24C7A', largura: 2 }
      ],
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A gaveta precisa de um nome.'), false;
        var d = await window.api('bar_categoria_guardar', { method: 'POST',
          body: JSON.stringify({ id: id || 0, nome: v.nome, cor: v.cor }) });
        if (!d || !d.success) return false;
        toast('Gaveta guardada.'); carregar(); return true;
      }
    });
  };

  window.barGavetaApagar = async function (id, nome) {
    var r = await licConfirmar({
      titulo: 'Apagar a gaveta «' + licEsc(nome) + '»?', icone: 'caixa', perigo: true,
      confirmar: 'Apagar',
      texto: 'As bebidas que estão nela não se perdem — ficam sem gaveta, e podem ir para outra.'
    });
    if (!r.sim) return;
    var d = await window.api('bar_categoria_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Gaveta apagada.'); carregar(); }
  };

  // ---- bebidas ----
  function camposDaBebida(i) {
    var opcoes = [{ v: '', r: '— sem gaveta —' }].concat(
      (EST.categorias || []).map(function (c) { return { v: c.id, r: c.nome }; }));
    var campos = [
      { id: 'nome', rot: 'Nome', valor: i.nome || '', largura: 2 },
      { id: 'categoria_id', rot: 'Gaveta', tipo: 'escolha', opcoes: opcoes, valor: i.categoria_id || '' },
      { id: 'descricao', rot: 'Uma linha sobre ela', valor: i.descricao || '', largura: 2 },
      { id: 'max_por_pedido', rot: 'Máximo por pedido', tipo: 'numero', valor: i.max_por_pedido || 2, min: 1 },
      // O limiar de «a acabar» é DESTA bebida, e é por isso que ele existe:
      // cinco garrafas de whisky é uma emergência e cinco águas não é nada.
      // O número que aqui se escreve é o mesmo que acende o aviso na
      // montagem e o semáforo na copa — um só, e não dois inventados.
      { id: 'stock_minimo', rot: 'Avisar quando restarem', tipo: 'numero',
        valor: i.stock_minimo === undefined ? 8 : i.stock_minimo, min: 0,
        dica: 'A partir daqui a bebida entra em «A acabar», aqui e na copa.' },
      { id: 'alcoolico', rot: 'Álcool', tipo: 'sim', aoLado: 'Tem álcool', valor: !!i.alcoolico },
      { id: 'estado', rot: 'No menu', tipo: 'escolha', valor: i.estado || 'ativo',
        opcoes: [{ v: 'ativo', r: 'À vista' }, { v: 'oculto', r: 'Escondida' }] }
    ];
    if (!i.id) campos.push({ id: 'stock', rot: 'Quantas há, para começar', tipo: 'numero', valor: 0, min: 0 });
    return campos;
  }

  window.barNova = function () {
    licFormulario({
      titulo: 'Bebida nova', guardar: 'Criar', largo: true, campos: camposDaBebida({}),
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A bebida precisa de um nome.'), false;
        var d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(v) });
        if (!d || !d.success) return false;
        toast('Bebida no menu. Falta a fotografia.');
        await carregar();
        barFoto(d.id);
        return true;
      }
    });
  };

  window.barEditar = function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    licFormulario({
      titulo: i.nome, guardar: 'Guardar', largo: true, campos: camposDaBebida(i),
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A bebida precisa de um nome.'), false;
        v.id = id;
        var d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(v) });
        if (!d || !d.success) return false;
        toast('Guardado.'); carregar(); return true;
      }
    });
  };

  window.barApagar = async function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    var c = await licConfirmar({
      titulo: 'Apagar «' + licEsc(i.nome) + '»?', icone: 'lixo', perigo: true, confirmar: 'Apagar',
      texto: 'Sai do menu e leva a fotografia. O que já foi entregue fica no histórico.'
    });
    if (!c.sim) return;
    var d = await window.api('bar_item_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Fora do menu.'); carregar(); }
  };

  window.barRepor = function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    licFormulario({
      titulo: 'Stock de «' + licEsc(i.nome) + '»', guardar: 'Somar',
      dica: 'Há <b>' + i.stock + '</b> em stock e <b>' + i.disponivel + '</b> disponíveis. '
          + 'Some o que chegou — ou ponha um número negativo, se algo se partiu.',
      campos: [
        { id: 'quantidade', rot: 'Quantas entraram', tipo: 'numero', valor: 12 },
        { id: 'nota', rot: 'Nota (opcional)', valor: '' }
      ],
      aoGuardar: async function (v) {
        if (!v.quantidade) return licJanelaErro('Diga quantas entraram.'), false;
        var d = await window.api('bar_stock_repor', { method: 'POST',
          body: JSON.stringify({ item_id: id, quantidade: v.quantidade, nota: v.nota }) });
        if (!d || !d.success) return false;
        toast('Stock actualizado.'); carregar(); return true;
      }
    });
  };

  window.barFoto = function (id) {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp';
    inp.style.display = 'none'; document.body.appendChild(inp);
    inp.addEventListener('change', async function () {
      var f = inp.files && inp.files[0];
      inp.remove();
      if (!f) return;
      var fd = new FormData();
      fd.append('id', id); fd.append('ficheiro', f);
      toast('A enviar…');
      var d = await window.api('bar_item_foto', { method: 'POST', body: fd });
      if (d && d.success) { toast('Fotografia posta.'); carregar(); }
    });
    inp.click();
  };

  carregar();
})();
