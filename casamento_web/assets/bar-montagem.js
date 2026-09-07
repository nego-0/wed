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
  var VER = { busca: '', gaveta: 0, estado: 'todas', buscaGav: '' };

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
    } else if (item.stock <= 8) {
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
    var classe = stock <= 0 ? ' zero' : (stock <= 8 ? ' pouca' : '');
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
    var poucas = itens.filter(function (i) { return i.estado === 'ativo' && i.stock <= 8; }).length;
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
      +   pilula('poucas',     'A acabar',       'aviso',       conta(function (i) { return i.stock <= 8; }))
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
      if (VER.estado === 'poucas' && i.stock > 8) return false;
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
    VER = { busca: '', gaveta: 0, estado: 'todas', buscaGav: VER.buscaGav };
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
  var ABAS = { menu: ['taca', 'O menu'], gav: ['caixa', 'Gavetas'], mesas: ['qr', 'Mesas e QR'] };
  Object.keys(ABAS).forEach(function (k) {
    var el = $('ab-' + k); if (!el) return;
    el.innerHTML = ico.ico(ABAS[k][0]) + ABAS[k][1];
  });

  window.barAba = function (qual) {
    ['menu', 'gav', 'mesas'].forEach(function (k) {
      $('ab-' + k).classList.toggle('on', k === qual);
      $('ab-' + k).setAttribute('aria-selected', k === qual ? 'true' : 'false');
      $('pn-' + k).hidden = k !== qual;
    });
    if (qual === 'mesas') pintarFolhas();
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
        + '<div class="rod">Sem rede? Chame um empregado — ele faz o pedido por si.</div></div>';
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
