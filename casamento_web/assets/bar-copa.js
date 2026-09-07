/* ============================================================
   bar-copa.js — O posto do copeiro (copa.php)

   Uma leitura só (bar_estado) traz a fila, o stock, os motivos e as contas; o
   ecrã refresca-se sozinho de oito em oito segundos, porque quem está na copa
   não pode estar a carregar em «recarregar» com as mãos molhadas.

   A decisão é de duas portas: aprovar (e a bebida fica prometida) ou recusar
   (e diz-se porquê). Nunca há uma terceira que deixe o pedido no limbo.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var PODE = !window.SO_VER_UI;
  var EST = null;
  var desvio = 0;            // relógio do servidor menos o do browser, em ms
  var relogio = null, tique = null;

  // As peças comuns aos quatro ecrãs do bar (assets/bar-pecas.js). São as
  // mesmas em todo o módulo de propósito: quem aprende a procurar na
  // montagem já sabe procurar aqui.
  var ico = window.ICO, BP = window.BP;
  var esc = BP.esc, toast = BP.toast, chave = BP.chave, foto = BP.foto;
  var campoBusca = BP.campoBusca, ligarBusca = BP.ligarBusca;
  var btIco = BP.btIco, vazio = BP.vazio;
  function ha(quando) { return BP.ha(quando, desvio); }

  /* ---- o que quem está a olhar está a perguntar ----------------
     Vive fora de EST porque não vem do servidor. Sobrevive às voltas de oito
     segundos: nada seria pior do que uma procura escrita a meio a desfazer-se
     sozinha enquanto a copa lê o resultado. */
  var VER = { aba: 'analise', busca: '', buscaStock: '' };

  function minutos(quando) {
    var t = Date.parse(String(quando || '').replace(' ', 'T'));
    return isNaN(t) ? 0 : (Date.now() + desvio - t) / 60000;
  }

  // ---- carregar ----------------------------------------------
  async function carregar(silencioso) {
    var d = await window.api('bar_estado', { method: 'GET', silencioso: !!silencioso });
    var sinal = $('b-sinal');
    if (!d || !d.success) {
      sinal.className = 'b-sinal';
      sinal.textContent = 'sem ligação';
      return;
    }
    if (d.agora) {
      // O relógio do tablet da copa pode estar errado — e um «há 40 min» falso
      // faz aprovar à pressa o que não é urgente. Vale o do servidor.
      var t = Date.parse(d.agora);
      if (!isNaN(t)) desvio = t - Date.now();
    }
    EST = d;
    sinal.className = 'b-sinal on';
    sinal.textContent = 'ligado';
    pintarChave();
    pintarFerramentas();
    // Com os números à vista é a eles que a volta serve: pintarFila() sai
    // pela porta do lado para não os apagar por baixo de quem os está a ler.
    if (VER.aba === 'num') pintarNumeros(); else pintarFila();
    pintarStock();
    pintarBandeiras();
  }

  /** Telemóveis que valem uma segunda vista. Não acusam ninguém — a copa
      conhece a sala e decide; isto limita-se a apontar (§5.3). */
  function pintarBandeiras() {
    var cx = $('b-bandeiras');
    if (!cx) return;
    var bs = EST.bandeiras || [];
    cx.hidden = !bs.length;
    if (!bs.length) return;
    cx.innerHTML = '<div class="b-tit">' + ico.ico('olho') + 'A olhar duas vezes <small>não é acusação: '
      + 'é o que dá nas vistas</small></div>'
      + bs.map(function (x) {
          return '<div class="b-cartao"><b>' + esc(x.nome) + '</b><br>'
            + '<span class="onde">' + esc(x.texto) + '</span></div>';
        }).join('');
  }

  /**
   * O interruptor do bar, no topo.
   *
   * É o mesmo componente da montagem (§25.2 quer um módulo, não quatro): o
   * farol que pisca devagar quando está aberto, a palavra ao lado, e só
   * depois o botão. Um ecrã que responde «está aberto?» pelo rótulo do botão
   * obriga a ler ao contrário — «Fechar o bar» significa que está aberto — e
   * é exactamente a leitura que se engana à uma da manhã.
   *
   * As contagens aqui são as da NOITE (o que já saiu, o que anda na sala). As
   * da fila vivem nas pastilhas, ao pé do sítio onde se carrega para lá ir.
   */
  function pintarChave() {
    var e = EST.estado || {};
    var cx = $('b-chave');
    cx.classList.toggle('on', !!e.aberto);
    $('b-farol').innerHTML = ico.ico(e.aberto ? 'aberto' : 'trancado');
    $('b-est').textContent = e.aberto ? 'Bar aberto' : 'Bar fechado';
    $('b-dica').textContent = e.aberto
      ? 'Os pedidos entram na fila assim que alguém carrega no telemóvel.'
      : 'Ninguém consegue pedir. O que já está na fila mantém-se.';
    $('b-numeros').innerHTML =
        num(e.a_caminho || 0, 'na sala')
      + num(e.bebidas_entregues || 0, 'servidas');
    var bt = $('b-chave-bt');
    bt.innerHTML = ico.ico(e.aberto ? 'trancado' : 'aberto')
      + (e.aberto ? 'Fechar o bar' : 'Abrir o bar');
    bt.className = 'btn ' + (e.aberto ? 'btn-fantasma' : 'btn-ouro');
    bt.disabled = !PODE;
  }
  function num(v, rot) {
    return '<div><b>' + v + '</b><small>' + esc(rot) + '</small></div>';
  }

  /* ---- as quatro vistas da fila --------------------------------
     Ícone, palavra e contagem na mesma pastilha: o ícone acha-se de relance
     na vigésima vez, a palavra desfaz a dúvida na primeira, e o número diz
     se vale a pena lá ir antes de se lá ir. */
  var ABAS = [
    ['analise', 'Por decidir',   'analise'],
    ['espera',  'Por entregar',  'tabuleiro'],
    ['fim',     'Já resolvidos', 'visto'],
    ['num',     'Os números',    'grafico']
  ];

  function pintarFerramentas() {
    var e = EST.estado || {};
    var conta = { analise: e.em_analise || 0,
                  espera: (e.aprovados || 0) + (e.a_caminho || 0),
                  fim: '', num: '' };
    var fer = $('b-fer-fila');
    // A procura só se redesenha quando ainda não existe: repintá-la a cada
    // volta de oito segundos roubava o cursor a quem estava a escrever.
    var jaHa = !!$('q-fila');
    fer.innerHTML = '<div class="b-pastilhas">'
      + ABAS.map(function (a) {
          return BP.pilula({ rot: a[1], icone: a[2], n: conta[a[0]],
                             ligada: VER.aba === a[0],
                             accao: 'copaFiltro(\'' + a[0] + '\')' });
        }).join('')
      + '</div>'
      + (VER.aba === 'num' ? ''
         : campoBusca('q-fila', 'Procurar código, nome ou mesa', VER.busca));
    // As pastilhas SÃO as abas: o papel tem de acompanhar o desenho.
    Array.prototype.forEach.call(fer.querySelectorAll('.b-pilula'), function (b, n) {
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', VER.aba === ABAS[n][0] ? 'true' : 'false');
    });
    if (VER.aba !== 'num') {
      ligarBusca('q-fila', function (v) { VER.busca = v; pintarFila(); });
      // Quem estava a escrever continua a escrever: só se devolve o foco se
      // ele já cá estava, e nunca na primeira pintura da página.
      if (jaHa && document.activeElement === document.body && VER.busca) {
        var el = $('q-fila');
        el.focus(); el.setSelectionRange(el.value.length, el.value.length);
      }
    }
  }

  window.copaFiltro = function (qual) {
    VER.aba = qual;
    pintarFerramentas();
    if (qual === 'num') pintarNumeros(); else pintarFila();
  };

  function pedidosDaAba() {
    var fila = EST.fila || [];
    if (VER.aba === 'analise') {
      return fila.filter(function (p) { return p.estado === 'em_analise'; });
    }
    if (VER.aba === 'espera') {
      return fila.filter(function (p) { return p.estado !== 'em_analise'; });
    }
    return EST.resolvidos || [];
  }

  /**
   * A procura da fila.
   *
   * Uma noite cheia são trinta pedidos na lista e um convidado à frente do
   * balcão a dizer «o meu era o B-14» — ou, mais provável, «sou o Manuel da
   * mesa 7». Por isso a procura apanha o código, o nome, a mesa E as bebidas:
   * qualquer uma dessas coisas é o que a pessoa tem para dar.
   */
  function peneira(ps) {
    var q = chave(VER.busca);
    if (!q) return ps;
    return ps.filter(function (p) {
      var saco = [p.codigo, p.convidado, p.mesa, p.mesa_qr, p.pedido_por, p.criado_por]
        .concat((p.itens || []).map(function (l) { return l.nome; }));
      return chave(saco.join(' ')).indexOf(q) >= 0;
    });
  }

  /**
   * Os números da noite.
   *
   * A pergunta que a copa faz de verdade não é «quantas saíram» — é «chega até
   * ao fim?». Por isso a previsão de rutura vem primeiro, e o que saiu vem
   * depois: um é uma decisão a tomar agora, o outro é a história da festa.
   */
  async function pintarNumeros() {
    var cx = $('b-fila');
    cx.innerHTML = '<div class="b-cartao b-esq" style="height:120px"></div>';
    var d = await window.api('bar_numeros', { method: 'GET', silencioso: true });
    if (!d || !d.success) { cx.innerHTML = '<div class="b-cartao b-vazio">Não deu.</div>'; return; }
    if (VER.aba !== 'num') return;        // a copa mudou de aba entretanto

    // Só o que já teve saída: uma bebida parada não «acaba nunca», simplesmente
    // não se sabe — e um número inventado aqui mandava alguém à cidade em vão.
    var acabam = d.rutura.filter(function (x) { return x.acaba_em_min !== null; });
    var html = '<div class="b-cartao"><div class="b-tit">' + ico.ico('raio') + 'Chega até ao fim?'
      + '<small>ao ritmo dos últimos 20 minutos</small></div>';
    html += acabam.length
      ? '<div class="b-stock lista">' + acabam.slice(0, 8).map(function (x) {
          var luz = x.acaba_em_min < 30 ? 'mau' : (x.acaba_em_min < 90 ? 'meio' : 'bom');
          return '<div class="b-item">'
            + '<div><div class="nm"><span class="b-semaforo ' + luz + '"></span>'
            +   esc(x.nome) + '</div>'
            + '<div class="sub">' + x.disponivel + ' disponíveis · ' + x.por_hora
            +   ' por hora</div></div>'
            + '<div class="qt">' + hhmm(x.acaba_em_min) + '</div></div>';
        }).join('') + '</div>'
      : '<p class="dica" style="color:var(--gold-pale)">Ainda não saiu nada — '
        + 'sem saída não há ritmo, e sem ritmo não há previsão que se respeite.</p>';
    html += '</div>';

    // O que a festa bebeu.
    var top = d.consumo.filter(function (x) { return x.servidas > 0 || x.a_sair > 0; });
    html += '<div class="b-cartao"><div class="b-tit">' + ico.ico('grafico') + 'O que a festa bebeu</div>'
      + (top.length
          ? '<div class="b-stock lista">' + top.slice(0, 12).map(function (x) {
              return '<div class="b-item">'
                + '<div><div class="nm">' + esc(x.nome) + '</div>'
                + '<div class="sub">' + esc(x.gaveta || 'sem gaveta')
                +   (x.a_sair ? ' · ' + x.a_sair + ' por sair' : '') + '</div></div>'
                + '<div class="qt">' + x.servidas + '</div></div>';
            }).join('') + '</div>'
          : '<p class="dica" style="color:var(--gold-pale)">Nada servido ainda.</p>')
      + '</div>';

    // Os tempos, e as recusas — o que correu mal, dito sem rodeios.
    var t = d.tempos || {};
    html += '<div class="b-cartao"><div class="b-tit">' + ico.ico('relogio') + 'Os tempos</div>'
      + '<div class="b-tempos" style="margin:0;padding:0;border:0">'
      +   caixa('Análise', t.analise) + caixa('Recolha', t.recolha)
      +   caixa('Percurso', t.percurso) + caixa('Do pedido à mesa', t.total)
      + '</div></div>';

    if (d.recusas.length) {
      html += '<div class="b-cartao"><div class="b-tit">' + ico.ico('traco') + 'Recusas'
        + '<small>o que correu mal</small></div><div class="b-stock lista">'
        + d.recusas.map(function (r) {
            return '<div class="b-item"><div><div class="nm">'
              + esc(r.motivo) + '</div></div><div class="qt">' + r.n + '</div></div>';
          }).join('') + '</div></div>';
    }
    if (d.mesas.length) {
      html += '<div class="b-cartao"><div class="b-tit">' + ico.ico('mesa') + 'Por mesa</div><div class="b-stock lista">'
        + d.mesas.map(function (m) {
            return '<div class="b-item"><div><div class="nm">'
              + esc(m.mesa) + '</div></div><div class="qt">' + m.n + '</div></div>';
          }).join('') + '</div></div>';
    }
    cx.innerHTML = html;
  }

  function caixa(rot, s) {
    return '<div><div class="n">' + (s === null || s === undefined ? '–'
      : (s < 90 ? Math.round(s) + ' s' : Math.round(s / 60) + ' min'))
      + '</div><div class="l">' + esc(rot) + '</div></div>';
  }

  /** Minutos em «1h20» ou «40 min» — o que a copa lê de relance. */
  function hhmm(m) {
    if (m < 60) return m + ' min';
    return Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0');
  }

  /* O vazio de cada aba diz o que ali não está E porquê — «nada por decidir»
     numa copa em dia é uma boa notícia, e deve ler-se como tal. */
  var VAZIOS = {
    analise: ['chavena', 'A copa está em dia',
              'Nada por decidir. Aproveite: é o único momento da noite em que isto acontece.'],
    espera:  ['tabuleiro', 'Nada por entregar',
              'Tudo o que foi aprovado já chegou à mesa.'],
    fim:     ['relogio', 'A noite ainda agora começou',
              'Ainda não há pedidos resolvidos para mostrar aqui.']
  };

  function pintarFila() {
    var cx = $('b-fila');
    if (VER.aba === 'num') return;
    var todos = pedidosDaAba();
    if (!todos.length) {
      var v = VAZIOS[VER.aba] || VAZIOS.fim;
      cx.innerHTML = '<div class="b-cartao">' + vazio(v[0], v[1], v[2]) + '</div>';
      return;
    }
    var ps = peneira(todos);
    if (!ps.length) {
      // Um vazio por causa da procura tem de o confessar: senão lê-se como
      // «a fila está vazia» e a copa deixa de olhar para trinta pedidos.
      cx.innerHTML = '<div class="b-cartao">'
        + vazio('procurar', 'Nada com «' + VER.busca + '»',
                'São ' + todos.length + (todos.length === 1 ? ' pedido' : ' pedidos')
                + ' nesta vista; nenhum responde ao que procura.',
                '<button class="btn btn-fantasma" onclick="copaLimparBusca()">'
                + ico.ico('volta') + 'Ver todos</button>')
        + '</div>';
      return;
    }
    cx.innerHTML = ps.map(cartao).join('')
      + (ps.length < todos.length
          ? '<p class="dica" style="color:var(--gold-pale);text-align:center">'
            + ps.length + ' de ' + todos.length + ' pedidos</p>'
          : '');
  }

  window.copaLimparBusca = function () {
    VER.busca = '';
    pintarFerramentas();
    pintarFila();
  };

  function cartao(p) {
    // A cor da moldura conta o tempo: um pedido de dez minutos por decidir já
    // é um convidado a olhar para o telemóvel.
    var idade = p.estado === 'em_analise' ? minutos(p.criado_em) : 0;
    var classe = idade > 10 ? ' muito-velho' : (idade > 5 ? ' velho' : '');
    var linhas = (p.itens || []).map(function (l) {
      return '<span class="b-linha">' + foto(l) + '<b>' + l.quantidade + '×</b> '
        + esc(l.nome) + '</span>';
    }).join('');

    // Onde entregar, e — se for diferente — de onde o pedido veio. Uma pessoa
    // que pediu na mesa 3 para a mesa 7 mudou de lugar, e quem entrega
    // precisa de saber as duas coisas.
    var onde = p.mesa ? 'Mesa ' + esc(p.mesa) : 'Sem mesa indicada';
    if (p.mesa_qr && p.mesa && p.mesa_qr !== p.mesa) onde += ' · pediu na ' + esc(p.mesa_qr);
    // Quem lançou o pedido, quando não foi quem o bebe. São dois casos e a
    // copa tem de os distinguir: um empregado ao balcão é serviço normal; um
    // convidado a pedir por outro é o vizinho de mesa a dar uma ajuda — e é
    // também o sítio por onde alguém tentaria beber à conta de outrem, por
    // isso diz-se o nome e não se esconde num ícone.
    if (p.criado_por) onde += ' · lançado por ' + esc(p.criado_por);
    if (p.pedido_por) onde += ' · pedido por <b>' + esc(p.pedido_por) + '</b>';

    // Um pedido que deixou de caber numa regra posta depois de ele entrar.
    // Não se recusa sozinho: assinala-se, e a copa decide.
    var fora = (EST.fora || []).filter(function (x) { return x.id === p.id; })[0];

    return '<div class="b-cartao b-ped' + classe + (fora ? ' fora' : '') + '">'
      + '<div class="b-ped-topo">'
      +   '<span class="cod">' + esc(p.codigo) + '</span>'
      +   (p.convidado_id
            ? '<button type="button" class="quem quem-bt" onclick="copaFicha('
              + p.convidado_id + ')" title="Ficha e regras desta pessoa">'
              + esc(p.convidado || 'Sem nome') + '</button>'
            : '<span class="quem">Sem nome</span>')
      +   BP.estado(p.estado, p.estado_nome)
      +   '<span class="ha">' + esc(ha(p.criado_em)) + '</span>'
      + '</div>'
      + '<div class="onde">' + onde + '</div>'
      + '<div class="b-linhas">' + linhas + '</div>'
      + (p.motivo ? '<div class="onde">Motivo: ' + esc(p.motivo) + '</div>' : '')
      + (fora ? '<div class="b-bandeira">' + ico.ico('aviso')
                + esc(fora.porque) + '</div>' : '')
      + (PODE ? acoes(p) : '')
      + '</div>';
  }

  function acoes(p) {
    if (p.estado === 'em_analise') {
      // Aprovar é o gesto de sempre e Recusar é a excepção: por isso um leva o
      // peso e o outro o contorno. Mas ambos são botões inteiros, com ícone e
      // palavra — a decisão que o convidado sente não se toma num ícone só.
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro" onclick="copaAprovar(' + p.id + ')">'
        +   ico.ico('visto') + 'Aprovar</button>'
        + '<button class="btn btn-fantasma" onclick="copaRecusar(' + p.id + ')">'
        +   ico.ico('xis') + 'Recusar</button>'
        + '</div>';
    }
    if (p.estado === 'aprovado' || p.estado === 'a_caminho' || p.estado === 'falhou') {
      return '<div class="b-acoes">'
        + '<button class="btn btn-fantasma" onclick="copaCancelar(' + p.id + ')">'
        +   ico.ico('volta') + 'Cancelar</button>'
        + '</div>';
    }
    return '';
  }

  // ---- decidir ------------------------------------------------
  window.copaAprovar = async function (id) {
    var d = await window.api('bar_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aprovar' }) });
    if (!d || !d.success) return;
    toast('Aprovado. Fica prometido até sair.');
    await carregar(true);
  };

  window.copaRecusar = function (id) {
    // Recusar sem dizer porquê é o que faz um convidado pedir outra vez. Os
    // motivos da lista são um toque; escrever é para o caso raro.
    var motivos = (EST.motivos || []).map(function (m) {
      return { v: String(m.id), r: m.texto };
    });
    motivos.push({ v: '0', r: 'Outro — escrevo eu' });
    licFormulario({
      titulo: 'Recusar o pedido',
      guardar: 'Recusar',
      perigo: true,
      dica: 'O convidado vê o motivo no telemóvel. Diga-o como o diria em pessoa.',
      campos: [
        { id: 'motivo_id', rot: 'Motivo', tipo: 'escolha',
          valor: motivos.length > 1 ? motivos[0].v : '0', opcoes: motivos },
        { id: 'motivo_texto', rot: 'Ou escreva um', tipo: 'area', linhas: 2, valor: '' }
      ],
      aoGuardar: async function (v) {
        var mid = parseInt(v.motivo_id, 10) || 0;
        if (!mid && !v.motivo_texto) {
          licJanelaErro('Escolha um motivo da lista ou escreva um.');
          return false;
        }
        var d = await window.api('bar_decidir', { method: 'POST',
          body: JSON.stringify({ id: id, decisao: 'recusar',
                                 motivo_id: mid, motivo_texto: v.motivo_texto }) });
        if (!d || !d.success) return false;
        toast('Recusado, com o motivo.');
        await carregar(true);
        return true;
      }
    });
  };

  window.copaCancelar = async function (id) {
    var r = await licConfirmar({ titulo: 'Cancelar o pedido', icone: 'volta', perigo: true,
      texto: 'O que estava prometido volta ao stock disponível. Use isto quando o '
           + 'pedido já não faz sentido — a pessoa foi-se embora, ou desistiu em voz alta.',
      confirmar: 'Cancelar o pedido', cancelar: 'Deixar estar' });
    if (!r.sim) return;
    var d = await window.api('bar_cancelar_copa', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Cancelado.');
    await carregar(true);
  };

  // ---- o interruptor ------------------------------------------
  window.copaChave = async function () {
    var aberto = !!(EST && EST.estado && EST.estado.aberto);
    if (aberto) {
      var r = await licConfirmar({ titulo: 'Fechar o bar', icone: 'trancado',
        texto: 'Ninguém consegue pedir enquanto estiver fechado. Os pedidos que já '
             + 'estão na fila mantêm-se — fechar não é apagar.',
        confirmar: 'Fechar', cancelar: 'Continuar aberto' });
      if (!r.sim) return;
    }
    var d = await window.api(aberto ? 'bar_fechar' : 'bar_abrir', { method: 'POST', body: '{}' });
    if (!d || !d.success) return;
    toast(aberto ? 'Bar fechado.' : 'Bar aberto. Já podem pedir.');
    await carregar(true);
  };

  // ---- o stock -------------------------------------------------
  /**
   * A coluna do stock, e a procura dentro dela.
   *
   * Um menu bem montado são vinte ou trinta bebidas, e a pergunta que a copa
   * faz é sempre sobre UMA: «ainda há gin?». Percorrer trinta linhas com o
   * olho, a meia-luz e com pressa, para responder a isso, é trabalho que a
   * máquina devia estar a fazer.
   */
  function pintarStock() {
    var todos = (EST.itens || []).filter(function (i) { return i.estado === 'ativo'; });
    var cx = $('b-stock');
    var tit = $('b-tit-stock');
    // O título ganha o seu sinal uma vez, e não a cada volta de oito segundos.
    if (tit && !tit.querySelector('.ico')) tit.insertAdjacentHTML('afterbegin', ico.ico('caixa'));
    if (!todos.length) {
      cx.innerHTML = vazio('caixa', 'O armazém está vazio',
        'Sem bebidas no menu não há stock que contar.');
      $('b-stock-nota').textContent = '';
      $('b-fer-stock').innerHTML = '';
      return;
    }
    // A procura só se monta uma vez: repintá-la a cada volta roubava o cursor
    // a quem estava a escrever «tóni…».
    if (!$('q-stock')) {
      $('b-fer-stock').innerHTML = campoBusca('q-stock', 'Procurar no armazém', VER.buscaStock);
      ligarBusca('q-stock', function (v) { VER.buscaStock = v; pintarStock(); });
    }
    var acabar = todos.filter(function (i) { return i.disponivel <= 5; }).length;
    $('b-stock-nota').textContent = acabar
      ? acabar + (acabar === 1 ? ' a acabar' : ' a acabar')
      : 'tudo com folga';
    var q = chave(VER.buscaStock);
    var itens = q ? todos.filter(function (i) {
      return chave(i.nome + ' ' + (i.categoria || '')).indexOf(q) >= 0;
    }) : todos;
    if (!itens.length) {
      cx.innerHTML = vazio('procurar', 'Nada com esse nome',
        'São ' + todos.length + ' bebidas no armazém.');
      return;
    }
    cx.innerHTML = itens.map(function (i) {
      // Três cores e nada mais: com folga, a acabar, acabou. Um número
      // sozinho não diz se 8 é muito ou pouco.
      var luz = i.disponivel <= 0 ? 'mau' : (i.disponivel <= 5 ? 'meio' : 'bom');
      return '<div class="b-item"' + (PODE ? ' role="button" tabindex="0"'
        + ' onclick="copaAcerto(' + i.id + ')"'
        + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();copaAcerto('
        + i.id + ')}" title="Acertar o stock de ' + esc(i.nome) + '"' : '') + '>'
        + foto(i)
        + '<div><div class="nm"><span class="b-semaforo ' + luz + '"></span>' + esc(i.nome) + '</div>'
        +   '<div class="sub">' + i.stock + ' na copa'
        +   (i.reservado ? ' · ' + i.reservado + ' prometidas' : '') + '</div></div>'
        + '<div class="qt">' + i.disponivel + '</div>'
        + '</div>';
    }).join('');
  }

  window.copaAcerto = function (id) {
    var i = (EST.itens || []).filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    licFormulario({
      titulo: esc(i.nome),
      guardar: 'Guardar',
      dica: 'Há <b>' + i.stock + '</b> lançadas, <b>' + i.reservado + '</b> prometidas. '
          + 'Some o que chegou, ou acerte para o que realmente há na copa.',
      campos: [
        { id: 'entrou', rot: 'Chegaram agora', tipo: 'numero', valor: 0, min: 0,
          dica: 'Somam-se ao que já lá estava.' },
        { id: 'stock', rot: 'Ou: contei e há', tipo: 'numero', valor: i.stock, min: 0,
          dica: 'Substitui o número. Precisa de nota.' },
        { id: 'nota', rot: 'Nota', tipo: 'text', valor: '', largura: 2,
          dica: 'Fica no livro do stock: «caiu uma caixa», «vieram 12 e não 24».' }
      ],
      aoGuardar: async function (v) {
        var entrou = parseInt(v.entrou, 10) || 0;
        var novo   = parseInt(v.stock, 10);
        var d;
        if (entrou) {
          d = await window.api('bar_stock_repor', { method: 'POST',
            body: JSON.stringify({ item_id: id, quantidade: entrou, nota: v.nota }) });
        } else if (!isNaN(novo) && novo !== i.stock) {
          if (!v.nota) { licJanelaErro('Um acerto explica-se: escreva a nota.'); return false; }
          d = await window.api('bar_stock_acerto', { method: 'POST',
            body: JSON.stringify({ item_id: id, stock: novo, nota: v.nota }) });
        } else {
          licJanelaErro('Diga quantas chegaram, ou quantas contou.');
          return false;
        }
        if (!d || !d.success) return false;
        toast('Stock actualizado.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- os motivos de recusa ------------------------------------
  window.copaMotivos = function () {
    var lista = (EST.motivos || []).map(function (m) {
      return '<div class="j-linha"><span>' + esc(m.texto) + '</span>'
        + '<button type="button" class="j-x" title="Tirar este motivo" '
        + 'aria-label="Tirar este motivo" onclick="copaMotivoApagar(' + m.id + ')">'
        + ico.ico('lixo') + '</button></div>';
    }).join('') || '<p class="dica">Ainda não há motivos guardados.</p>';
    licFormulario({
      titulo: 'Motivos de recusa',
      guardar: 'Acrescentar',
      dica: 'Os motivos da lista poupam a escrita a meio da noite. '
          + 'Escreva-os como os diria a quem pediu.',
      campos: [{ id: 'texto', rot: 'Motivo novo', tipo: 'text', valor: '',
                 dica: 'Ex.: «Acabou o espumante — temos vinho branco fresco.»' }],
      extra: '<div class="j-sec">' + ico.ico('nota') + 'Os que já lá estão</div>' + lista,
      aoGuardar: async function (v) {
        if (!v.texto) { licJanelaErro('Escreva o motivo.'); return false; }
        var d = await window.api('bar_motivo_guardar', { method: 'POST',
          body: JSON.stringify({ texto: v.texto }) });
        if (!d || !d.success) return false;
        await carregar(true);
        copaMotivos();
        return false;   // a janela fica, para se acrescentar outro
      }
    });
  };

  window.copaMotivoApagar = async function (id) {
    var d = await window.api('bar_motivo_apagar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    await carregar(true);
    licFecharJanela();
    copaMotivos();
  };

  // ---- a ficha de um convidado, com as regras dele ---------------
  // É uma coisa que se faz a correr, no meio da festa, com a pessoa à frente:
  // «este senhor já vai no quinto whisky», «esta senhora está grávida».
  window.copaFicha = async function (id) {
    var d = await window.api('bar_ficha&convidado=' + id, { method: 'GET' });
    if (!d || !d.success) return;
    // «2× whisky (1 por servir)» — para quem está a decidir, a diferença entre
    // o que já bebeu e o que ainda está na fila é a informação toda.
    var levou = d.levou.length
      ? d.levou.map(function (l) {
          return l.n + '× ' + esc(l.nome)
            + (l.por_servir ? ' <small>(' + l.por_servir + ' por servir)</small>' : '');
        }).join(' · ')
      : 'ainda não pediu nada';
    var regras = d.regras.length
      ? d.regras.map(function (r) {
          // Uma regra marcada para as 2h está escrita mas ainda não trava
          // nada, e o ecrã tem de o dizer — senão parece que já vale.
          return '<div class="j-linha' + (r.vigor === 'agora' ? '' : ' espera') + '">'
            + '<span>' + esc(r.frase)
            + (r.vigor === 'ainda' ? ' <small>— ainda não são horas</small>' : '')
            + (r.vigor === 'passou' ? ' <small>— já passou a hora</small>' : '')
            + (r.nota ? '<br><small>' + esc(r.nota) + '</small>' : '')
            + '</span>'
            + '<button type="button" class="j-x" title="Levantar esta regra" '
            + 'aria-label="Levantar esta regra" onclick="copaRegraFora('
            + r.id + ',' + id + ')">' + ico.ico('xis') + '</button></div>';
        }).join('')
      : '<p class="dica">Sem regras próprias — valem-lhe as da casa.</p>';

    licJanela('Ficha de ' + esc(d.convidado.nome),
      '<div class="dica">' + esc(d.convidado.convite) + '</div>'
      + '<p style="margin:.6rem 0"><b>Já levou:</b> ' + levou + '</p>'
      + '<div class="j-sec">' + ico.ico('trancado') + 'Regras desta pessoa</div>'
      + regras
      + '<div style="margin-top:.7rem">'
      +   '<button type="button" class="j-bt j-bt-sim" onclick="copaRegraNova(' + id + ')">'
      +     ico.ico('mais') + 'Regra nova</button>'
      + '</div>'
      + (d.pin_travado
          ? '<div class="j-sec">' + ico.ico('aviso') + 'Código travado</div>'
            + '<div class="j-linha"><span>Foram cinco enganos seguidos no código '
            + 'do convite.</span><button type="button" class="j-bt j-bt-sim" '
            + 'onclick="copaPinSoltar(' + id + ')">Levantar</button></div>'
          : '')
      + '<div class="j-sec">' + ico.ico('pessoas') + 'Telemóveis</div>'
      + (d.dispositivos.length
          ? d.dispositivos.map(function (t) {
              return '<div class="j-linha"><span>um telemóvel'
                + (t.trocas ? ' <small>(já pediu por ' + (t.trocas + 1) + ' pessoas)</small>' : '')
                + '</span><button type="button" class="j-x" title="Soltar este telemóvel" '
                + 'aria-label="Soltar este telemóvel" onclick="copaSoltar('
                + t.id + ',' + id + ')">' + ico.ico('mudar') + '</button></div>';
            }).join('')
          : '<p class="dica">Nenhum — ainda ninguém se escolheu neste nome.</p>')
      + '<p class="dica">Soltar serve quando a vida dá um nó: um telemóvel '
      +   'emprestado, um nome escolhido por engano. O próximo a abrir a página '
      +   'volta a escolher-se.</p>', null, { cancelar: 'Fechar' });
  };

  /** Uma regra escreve-se como quem fala, com listas em vez de campos. */
  window.copaRegraNova = function (convidadoId) {
    var itens = (EST.itens || []).map(function (i) { return { v: 'i' + i.id, r: i.nome }; });
    var cats  = (EST.categorias || []).map(function (c) { return { v: 'c' + c.id, r: c.nome }; });
    var sobre = [{ v: 'tudo', r: 'qualquer bebida' }].concat(cats, itens);
    licFormulario({
      titulo: 'Regra para esta pessoa',
      guardar: 'Pôr a regra',
      largo: true,
      dica: 'Três números dizem tudo: <b>0</b> proíbe; <b>N</b> sem intervalo é um '
          + 'tecto para a noite; <b>N</b> com intervalo é «N de cada vez».',
      campos: [
        { id: 'sobre', rot: 'Pode pedir', tipo: 'escolha', valor: 'tudo', opcoes: sobre },
        { id: 'quantidade', rot: 'No máximo', tipo: 'numero', valor: 1, min: 0, max: 99,
          dica: '0 = não pode pedir isto.' },
        { id: 'janela_min', rot: 'A cada (minutos)', tipo: 'numero', valor: 0, min: 0, max: 1440,
          dica: '0 = é um tecto para a noite inteira.' },
        // As horas são a última linha da tabela de §8: «nada de destilados
        // antes das 21h» é a regra que SAI às 21h. Vazias, vale a noite toda.
        { id: 'vigora_hora', rot: 'A partir das', tipo: 'hora', valor: '',
          dica: 'Vazio, vale já.' },
        { id: 'expira_hora', rot: 'Até às', tipo: 'hora', valor: '',
          dica: 'Vazio, vale até ao fim. Uma hora já passada é a madrugada seguinte.' },
        { id: 'mensagem', rot: 'O que ele lê', tipo: 'text', valor: '', largura: 2,
          dica: 'Vazio, lê o texto de sempre. Nunca lê a nota.' },
        { id: 'nota', rot: 'Porquê (só nós vemos)', tipo: 'text', valor: '', largura: 2,
          dica: 'Ex.: «pediu-nos para o travarmos», «conduz».' }
      ],
      aoGuardar: async function (v) {
        var escopo = 'tudo', alvo = 0;
        if (v.sobre.charAt(0) === 'i') { escopo = 'item';      alvo = parseInt(v.sobre.slice(1), 10); }
        else if (v.sobre.charAt(0) === 'c') { escopo = 'categoria'; alvo = parseInt(v.sobre.slice(1), 10); }
        var d = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify({
          escopo: escopo, alvo_id: alvo, sujeito: 'convidado', alvo_convidado_id: convidadoId,
          unidade: 'bebidas', quantidade: parseInt(v.quantidade, 10) || 0,
          janela_min: parseInt(v.janela_min, 10) || 0,
          vigora_hora: v.vigora_hora, expira_hora: v.expira_hora,
          mensagem: v.mensagem, nota: v.nota }) });
        if (!d || !d.success) return false;
        toast('Regra posta. Vale já.');
        await carregar(true);
        avisarFora(d.fora);
        licFecharJanela();
        copaFicha(convidadoId);
        return false;
      }
    });
  };

  /** O travão do código levanta-se num clique: a pessoa está ali à frente. */
  window.copaPinSoltar = async function (convidadoId) {
    var d = await window.api('bar_pin_soltar', { method: 'POST',
      body: JSON.stringify({ convidado_id: convidadoId }) });
    if (!d || !d.success) return;
    toast('Travão levantado. Já pode voltar a escrever o código.');
    licFecharJanela();
    copaFicha(convidadoId);
  };

  window.copaSoltar = async function (id, convidadoId) {
    var d = await window.api('bar_soltar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Telemóvel solto.');
    licFecharJanela();
    copaFicha(convidadoId);
  };

  window.copaRegraFora = async function (id, convidadoId) {
    var d = await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    await carregar(true);
    licFecharJanela();
    if (convidadoId) copaFicha(convidadoId);
  };

  /** Uma regra vale de imediato, mas não recusa nada sozinha: quem a pôs pode
      muito bem querer servir o copo que já estava pedido (§8.0.1). */
  function avisarFora(fora) {
    if (!fora || !fora.length) return;
    toast(fora.length === 1
      ? 'O pedido ' + fora[0].codigo + ' já não cabe nas regras — está assinalado na fila.'
      : fora.length + ' pedidos na fila já não cabem nas regras.', true);
  }

  // ---- as regras da casa ---------------------------------------
  window.copaRegras = function () {
    var f = (EST && EST.defs) || {};
    licFormulario({
      titulo: 'Regras da casa',
      guardar: 'Guardar',
      largo: true,
      dica: 'Como o bar se porta com quem pede. Muda a meio da noite se for preciso.',
      campos: [
        { id: 'bar.mensagem_fechado', rot: 'O que dizer quando está fechado', tipo: 'area',
          linhas: 2, valor: f['bar.mensagem_fechado'] || '', largura: 2,
          dica: 'Ex.: «O bar abre depois do brinde, por volta das 21h.»' },
        { id: 'bar.procura_min', rot: 'Letras para procurar o nome', tipo: 'numero',
          valor: f['bar.procura_min'] || '4', min: 1, max: 8,
          dica: 'Menos letras, mais nomes de cada vez na lista.' },
        { id: 'bar.ip_modo', rot: 'Pedidos da mesma rede', tipo: 'escolha',
          valor: f['bar.ip_modo'] || 'registo',
          opcoes: [{ v: 'registo', r: 'Registar, sem incomodar' },
                   { v: 'aviso',   r: 'Registar e avisar a copa' },
                   { v: 'estrito', r: 'Recusar o segundo nome' }],
          dica: 'Numa festa quase todos partilham o mesmo wi-fi: «estrito» é '
              + 'para salas onde cada mesa tem a sua rede.' },
        { id: 'bar.garcon_direto', rot: 'Empregado lança pedidos', tipo: 'sim',
          valor: f['bar.garcon_direto'] === '1', aoLado: 'Sim, para quem não tem rede' },
        { id: 'bar.trocar_nome', rot: 'Trocar de nome no mesmo telemóvel', tipo: 'sim',
          valor: f['bar.trocar_nome'] === '1', aoLado: 'Deixar, avisando a copa' },
        { id: 'bar.pedir_pin', rot: 'Pedir o código do convite', tipo: 'sim',
          valor: f['bar.pedir_pin'] === '1', aoLado: 'Sim, quatro dígitos ao escolher o nome',
          largura: 2,
          dica: 'Devolve o segredo que se perdeu ao tirar o link do convite — e '
              + 'devolve também o atrito. Os códigos saem em bar-qr.php, para '
              + 'irem no convite de cada família.' }
      ],
      aoGuardar: async function (v) {
        var env = {};
        Object.keys(v).forEach(function (k) {
          env[k] = (k === 'bar.garcon_direto' || k === 'bar.trocar_nome'
                 || k === 'bar.pedir_pin')
            ? (v[k] ? '1' : '0') : String(v[k]);
        });
        var d = await window.api('bar_defs', { method: 'POST', body: JSON.stringify(env) });
        if (!d || !d.success) return false;
        toast('Regras guardadas.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- pedir por um convidado ----------------------------------
  // Sem rede, o convidado pede em voz alta e a copa lança por ele. É o mesmo
  // pedido, com o nome de quem o lançou colado — para não haver dúvidas de
  // quem serviu o quê.
  window.copaPedirPor = function () {
    var itens = (EST.itens || []).filter(function (i) {
      return i.estado === 'ativo' && i.disponivel > 0;
    });
    if (!itens.length) { toast('Não há nada disponível para pedir.', true); return; }
    licFormulario({
      titulo: 'Pedir por um convidado',
      guardar: 'Lançar o pedido',
      largo: true,
      dica: 'Escreva parte do nome, escolha a pessoa, e depois a bebida.',
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
        toast('Pedido ' + d.pedido.codigo + ' lançado.');
        await carregar(true);
        return true;
      }
    });
    ligarProcuraPessoal();
  };

  var ppEscolhido = null, ppEspera = null;
  function ligarProcuraPessoal() {
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
    saida.innerHTML = d.nomes.slice(0, 6).map(function (n) {
      return '<button type="button" class="j-bt j-bt-nao" style="min-height:34px;'
        + 'padding:.15rem .6rem;margin:.15rem .25rem 0 0" onclick="copaEscolher(' + n.id + ')">'
        + esc(n.nome) + (n.mesa ? ' · ' + esc(n.mesa) : '') + '</button>';
    }).join('');
    window.__ppNomes = d.nomes;
  }
  window.copaEscolher = function (id) {
    var n = (window.__ppNomes || []).filter(function (x) { return x.id === id; })[0];
    if (!n) return;
    ppEscolhido = n;
    var cx = document.getElementById('lf-nome');
    if (cx) cx.value = n.nome;
    var saida = document.getElementById('pp-achados');
    if (saida) saida.innerHTML = '<b>' + esc(n.nome) + '</b>'
      + (n.mesa ? ' — entrega na ' + esc(n.mesa) : ' — sem mesa marcada');
  };

  /* ---- os atalhos da coluna do stock ---------------------------
     Três coisas que se fazem a meio da noite e que não pertencem à fila: pedir
     por quem não tem rede, arrumar os motivos de recusa, e mudar as regras da
     casa quando a festa muda de feição. Empilhados e com o ícone na mesma
     coluna leem-se como uma lista do que se pode fazer — e não como três
     botões cinzentos ao acaso. */
  function pintarAtalhos() {
    var cx = $('b-atalhos');
    if (!cx || !PODE) { if (cx) cx.innerHTML = ''; return; }
    cx.innerHTML =
        '<button class="btn btn-fantasma" onclick="copaPedirPor()">'
      +   ico.ico('mao') + 'Pedir por um convidado</button>'
      + '<button class="btn btn-fantasma" onclick="copaMotivos()">'
      +   ico.ico('nota') + 'Motivos de recusa</button>'
      + '<button class="btn btn-fantasma" onclick="copaRegras()">'
      +   ico.ico('trancado') + 'Regras da casa</button>';
  }

  // ---- o relógio ------------------------------------------------
  pintarAtalhos();
  carregar();
  relogio = setInterval(function () { if (!document.hidden) carregar(true); }, 8000);
  // Os «há N min» envelhecem sozinhos entre leituras: sem isto, um pedido
  // ficava «há 2 min» durante oito segundos de cada vez.
  tique = setInterval(function () { if (!document.hidden && EST) pintarFila(); }, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) carregar(true);
  });
})();
