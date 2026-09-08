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
    if (VER.aba === 'num') pintarNumeros();
    else if (VER.aba === 'regras') pintarRegras();
    else pintarFila();
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
    ['num',     'Os números',    'grafico'],
    // As regras entram aqui, a seguir aos números, e não como um link para
    // bar.php: bar.php é dos noivos, e o copeiro que lá carregasse aterrava na
    // página de entrada — o atalho mandava-o para fora do próprio posto.
    ['regras',  'Regras do Bar', 'trancado']
  ];
  /** As abas que não são a fila: têm painel próprio e não usam a procura. */
  function abaDePainel(a) { return a === 'num' || a === 'regras'; }

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
      + (abaDePainel(VER.aba) ? ''
         : campoBusca('q-fila', 'Procurar código, nome ou mesa', VER.busca));
    // As pastilhas SÃO as abas: o papel tem de acompanhar o desenho.
    Array.prototype.forEach.call(fer.querySelectorAll('.b-pilula'), function (b, n) {
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', VER.aba === ABAS[n][0] ? 'true' : 'false');
    });
    if (!abaDePainel(VER.aba)) {
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
    // Sair dos números larga a moldura: quem volta quer ver os de AGORA, e
    // não os de há dez minutos enquanto a leitura nova não chega.
    if (qual !== 'num') numAmarrado = false;
    pintarFerramentas();
    if (qual === 'num') pintarNumeros();
    else if (qual === 'regras') pintarRegras();
    else pintarFila();
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

  /* ============================================================
     OS NÚMEROS DA NOITE

     Duas mudanças em relação ao que era:

     1. **Actualiza-se sozinho, e sem piscar.** Antes, cada volta apagava o
        painel inteiro e punha um esqueleto no lugar — e quem estava a ler uma
        linha via-a desaparecer debaixo dos olhos. Agora a leitura é
        assíncrona e o que muda é o CONTEÚDO de cada cartão; o esqueleto só
        aparece à primeira vez, quando de facto ainda não há nada.

     2. **Dois gráficos.** «Chega até ao fim?» já respondia à pergunta urgente;
        faltava a forma da noite — que bebidas é que a festa está a beber, e
        quem é que se destaca. Uma coluna de números não mostra uma forma: o
        olho vê comprimentos, não lê doze linhas de dígitos.

     Os gráficos são barras horizontais desenhadas à mão, com os tokens da
     casa. Barras horizontais e não colunas porque o que varia são NOMES —
     «Espumante da casa», «Maria Fernandes» — e um nome deitado de lado numa
     coluna de 40px não se lê.
     ============================================================ */
  var NUM = null;            // a última leitura dos números
  var numAmarrado = false;   // a moldura dos cartões já está montada?

  /**
   * Uma barra horizontal, numa lista ordenada.
   *
   * A largura é sobre o MAIOR e não sobre o total: com doze bebidas, a
   * percentagem do total dá doze tracinhos indistinguíveis. Sobre o maior,
   * a primeira enche a barra e as outras leem-se contra ela — que é a
   * comparação que se está a fazer.
   */
  function barras(linhas, maximo, opc) {
    opc = opc || {};
    var max = Math.max(1, maximo);
    return '<div class="b-graf">' + linhas.map(function (l) {
      var pc = Math.round(l.n / max * 100);
      return '<div class="b-graf-l' + (l.classe ? ' ' + l.classe : '') + '"'
        + (l.cor ? ' style="--tinta:' + esc(l.cor) + '"' : '')
        + (l.accao ? ' role="button" tabindex="0" onclick="' + l.accao + '"'
                   + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();' + l.accao + '}"'
                   + ' title="' + esc(l.titulo || l.nome) + '"' : '') + '>'
        + '<span class="nm">' + esc(l.nome) + '</span>'
        + '<span class="tr"><i style="width:' + Math.max(pc, 2) + '%"></i></span>'
        + '<span class="v">' + l.n + esc(opc.sufixo || '') + '</span>'
        + '</div>';
    }).join('') + '</div>';
  }

  /** O molde dos cartões. Desenha-se uma vez; depois só o miolo muda. */
  function molduraNumeros() {
    var cartao = function (id, icone, tit, nota) {
      return '<div class="b-cartao"><div class="b-tit">' + ico.ico(icone) + tit
        + (nota ? '<small>' + nota + '</small>' : '') + '</div>'
        + '<div id="' + id + '"></div></div>';
    };
    $('b-fila').innerHTML =
        cartao('nm-rutura',  'raio',    'Chega até ao fim?', 'ao ritmo dos últimos 20 minutos')
      + cartao('nm-bebidas', 'grafico', 'O que a festa bebeu', 'entregues')
      + cartao('nm-pessoas', 'pessoas', 'Quem bebeu mais', 'entregues, por pessoa')
      + cartao('nm-tempos',  'relogio', 'Os tempos', '')
      + '<div id="nm-extra"></div>';
    numAmarrado = true;
  }

  async function pintarNumeros(silencioso) {
    var cx = $('b-fila');
    // O esqueleto só na PRIMEIRA vez. Numa volta de rotina, apagar o painel
    // para o voltar a escrever é fazer piscar o que a pessoa está a ler.
    if (!numAmarrado) {
      cx.innerHTML = '<div class="b-cartao b-esq" style="height:120px"></div>'
                   + '<div class="b-cartao b-esq" style="height:180px;margin-top:.7rem"></div>';
    }
    var d = await window.api('bar_numeros', { method: 'GET', silencioso: true });
    if (VER.aba !== 'num') return;        // a copa mudou de aba entretanto
    if (!d || !d.success) {
      // Sem ligação, o que estava fica: um painel de números em branco vale
      // menos do que números de há dez segundos, e a barra do topo já diz
      // que a ligação caiu.
      if (!numAmarrado) cx.innerHTML = '<div class="b-cartao">'
        + vazio('aviso', 'Não deu para ler os números', 'A página tenta outra vez sozinha.') + '</div>';
      return;
    }
    NUM = d;
    if (!numAmarrado) molduraNumeros();
    desenharNumeros();
  }

  function desenharNumeros() {
    var d = NUM; if (!d) return;

    // ---- chega até ao fim? -------------------------------------
    // Só o que já teve saída: uma bebida parada não «acaba nunca», simplesmente
    // não se sabe — e um número inventado aqui mandava alguém à cidade em vão.
    var acabam = (d.rutura || []).filter(function (x) { return x.acaba_em_min !== null; });
    $('nm-rutura').innerHTML = acabam.length
      ? '<div class="b-stock lista">' + acabam.slice(0, 8).map(function (x) {
          var luz = x.acaba_em_min < 30 ? 'mau' : (x.acaba_em_min < 90 ? 'meio' : 'bom');
          return '<div class="b-item">'
            + '<div><div class="nm"><span class="b-semaforo ' + luz + '"></span>'
            +   esc(x.nome) + '</div>'
            + '<div class="sub">' + x.disponivel + ' disponíveis · ' + x.por_hora
            +   ' por hora</div></div>'
            + '<div class="qt">' + hhmm(x.acaba_em_min) + '</div></div>';
        }).join('') + '</div>'
      : '<p class="b-nota">Ainda não saiu nada — sem saída não há ritmo, e sem '
        + 'ritmo não há previsão que se respeite.</p>';

    // ---- o que a festa bebeu, em barras ------------------------
    // Clicar abre as regras daquela bebida — é o mesmo princípio de «Quem bebeu
    // mais»: o gráfico aponta, e o que se abre é o sítio onde se faz alguma
    // coisa a respeito do que ele aponta. É AQUI que o copeiro vê o gin a sair
    // depressa de mais, e por isso é daqui que ele o há-de poder travar, sem
    // atravessar dois separadores para chegar à mesma bebida.
    var top = (d.consumo || []).filter(function (x) { return x.servidas > 0; })
                               .sort(function (a, b) { return b.servidas - a.servidas; });
    var maxB = top.length ? top[0].servidas : 1;
    $('nm-bebidas').innerHTML = top.length
      ? barras(top.slice(0, 12).map(function (x) {
          var i = (EST.itens || []).filter(function (y) { return y.nome === x.nome; })[0];
          return { nome: x.nome, n: x.servidas, cor: i && i.categoria_cor,
                   accao: i ? 'copaRegrasDaBebida(' + i.id + ')' : '',
                   titulo: i ? 'Ver e pôr regras de ' + x.nome : '',
                   classe: i ? 'toca' : '' };
        }), maxB)
        + (top.length > 12 ? '<p class="b-nota">e mais ' + (top.length - 12) + '.</p>' : '')
      : '<p class="b-nota">Nada servido ainda.</p>';

    // ---- quem bebeu mais ---------------------------------------
    // Clicar abre a ficha: o gráfico aponta a pessoa, e a ficha é onde se faz
    // alguma coisa a respeito dela. Sem isso, era um gráfico bonito e mudo.
    var gente = d.pessoas || [];
    $('nm-pessoas').innerHTML = gente.length
      ? barras(gente.map(function (g) {
          return { nome: g.nome, n: g.n, accao: 'copaFicha(' + g.id + ')',
                   titulo: 'Abrir a ficha de ' + g.nome, classe: 'toca' };
        }), gente[0].n)
      : '<p class="b-nota">Ninguém levou nada ainda.</p>';

    // ---- os tempos ---------------------------------------------
    var t = d.tempos || {};
    $('nm-tempos').innerHTML = '<div class="b-tempos" style="margin:0;padding:0;border:0">'
      + caixa('Análise', t.analise) + caixa('Recolha', t.recolha)
      + caixa('Percurso', t.percurso) + caixa('Do pedido à mesa', t.total)
      + '</div>';

    // ---- recusas e mesas, quando as há -------------------------
    var extra = '';
    if ((d.recusas || []).length) {
      extra += '<div class="b-cartao"><div class="b-tit">' + ico.ico('traco') + 'Recusas'
        + '<small>o que correu mal</small></div>'
        + barras(d.recusas.map(function (r) { return { nome: r.motivo, n: r.n }; }),
                 d.recusas[0].n) + '</div>';
    }
    if ((d.mesas || []).length) {
      extra += '<div class="b-cartao"><div class="b-tit">' + ico.ico('mesa') + 'Por mesa</div>'
        + barras(d.mesas.map(function (m) { return { nome: m.mesa, n: m.n }; }),
                 d.mesas[0].n) + '</div>';
    }
    $('nm-extra').innerHTML = extra;
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

  /* O lançador ao balcão, no TOPO da fila por decidir.
     Estava numa coluna lateral, ao pé do stock, com o nome de outra coisa
     («pedir por um convidado»). Mas o gesto é este: alguém está à frente do
     copeiro, e o que ele vai fazer é aprovar um pedido. Fica onde o gesto
     acontece, e com o nome do gesto. */
  function lancador() {
    if (!PODE || VER.aba !== 'analise') return '';
    return '<button class="b-lancar" onclick="copaPedirPor()">'
      + '<span class="sinal">' + ico.ico('mais') + '</span>'
      + '<span class="txt"><b>Aprovar pedido</b>'
      +   '<small>Alguém pediu ao balcão — entra já aprovado, e pode sair '
      +   'como entregue no acto.</small></span>'
      + ico.ico('seta') + '</button>';
  }

  /* ---- as Regras do Bar, dentro da copa -------------------------
     O MESMO painel da montagem (assets/bar-regras.js), montado no mesmo sítio
     onde vive a fila. Antes havia aqui um atalho para bar.php#regras — e
     bar.php é dos noivos: o copeiro que lhe carregasse era mandado para a
     página de entrada, a meio de uma noite de trabalho. */
  var regrasMontadas = false;
  function pintarRegras() {
    var cx = $('b-fila');
    if (VER.aba !== 'regras') return;
    if (!regrasMontadas) {
      regrasMontadas = true;
      cx.innerHTML = '<div id="pn-regras-cx"></div>';
      window.BR.montar({ alvo: 'pn-regras-cx' });
    } else if (!document.getElementById('pn-regras-cx')) {
      // A fila esteve cá pelo meio e levou o painel: volta a montar-se.
      regrasMontadas = false;
      pintarRegras();
    } else {
      window.BR.pintar();
    }
  }

  function pintarFila() {
    var cx = $('b-fila');
    if (abaDePainel(VER.aba)) return;
    // A fila e os painéis dividem o mesmo espaço; ao voltar a ela, o painel
    // que lá estava sai — e o próximo regresso volta a montá-lo.
    regrasMontadas = false;
    var todos = pedidosDaAba();
    if (!todos.length) {
      var v = VAZIOS[VER.aba] || VAZIOS.fim;
      cx.innerHTML = lancador() + '<div class="b-cartao">' + vazio(v[0], v[1], v[2]) + '</div>';
      return;
    }
    var ps = peneira(todos);
    if (!ps.length) {
      // Um vazio por causa da procura tem de o confessar: senão lê-se como
      // «a fila está vazia» e a copa deixa de olhar para trinta pedidos.
      cx.innerHTML = lancador() + '<div class="b-cartao">'
        + vazio('procurar', 'Nada com «' + VER.busca + '»',
                'São ' + todos.length + (todos.length === 1 ? ' pedido' : ' pedidos')
                + ' nesta vista; nenhum responde ao que procura.',
                '<button class="btn btn-fantasma" onclick="copaLimparBusca()">'
                + ico.ico('volta') + 'Ver todos</button>')
        + '</div>';
      return;
    }
    cx.innerHTML = lancador() + ps.map(cartao).join('')
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
    // copa tem de os distinguir: um garçom ao balcão é serviço normal; um
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
      + notasDe(p)
      + (PODE ? acoes(p) : '')
      + '</div>';
  }

  /**
   * O que os garçons trouxeram da mesa sobre ESTA pessoa.
   *
   * O garçom é o único do bar que fala com o convidado, e o que ele ouve —
   * «pediu para não lhe servirem mais», «está com os miúdos» — morria à mesa.
   * Agora aparece aqui, colado ao pedido seguinte dessa pessoa, que é o único
   * momento em que a informação serve para alguma coisa.
   *
   * Só na fila por decidir: num pedido já aprovado seria uma observação sem
   * decisão pela frente.
   */
  function notasDe(p) {
    if (p.estado !== 'em_analise' || !p.convidado_id) return '';
    var ns = ((EST.notas || {})[String(p.convidado_id)]) || [];
    if (!ns.length) return '';
    return '<div class="b-notas">'
      + '<span class="tit">' + ico.ico('nota')
      +   (ns.length === 1 ? 'Da mesa' : 'Da mesa (' + ns.length + ')') + '</span>'
      + ns.map(function (n) {
          return '<span class="n">' + esc(n.texto)
            + '<em>' + esc(n.quem || 'garçom') + ' · ' + esc(ha(n.quando)) + '</em></span>';
        }).join('')
      + '</div>';
  }

  function acoes(p) {
    if (p.estado === 'em_analise') {
      // Aprovar é o gesto de sempre e Recusar é a excepção: por isso um leva o
      // peso e o outro o contorno. Mas ambos são botões inteiros, com ícone e
      // palavra — a decisão que o convidado sente não se toma num ícone só.
      // Três portas, e não duas: «servir menos» é o que se faz ao balcão
      // quando há duas e pediram quatro. Recusar quatro por causa de duas é
      // servir zero, e a pessoa volta a pedir daí a um minuto (§27).
      // A do meio só aparece quando há o que cortar — com uma linha de uma
      // bebida só, cortar é recusar, e há um botão para isso ao lado.
      var podeCortar = (p.itens || []).length > 1
                    || ((p.itens || [])[0] && p.itens[0].quantidade > 1);
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro" onclick="copaAprovar(' + p.id + ')">'
        +   ico.ico('visto') + 'Aprovar</button>'
        + (podeCortar
            ? '<button class="btn btn-fantasma" onclick="copaParcial(' + p.id + ')">'
              + ico.ico('menos') + 'Servir menos</button>' : '')
        + '<button class="btn btn-fantasma" onclick="copaRecusar(' + p.id + ')">'
        +   ico.ico('xis') + 'Recusar</button>'
        + '</div>';
    }
    if (p.estado === 'aprovado' || p.estado === 'a_caminho' || p.estado === 'falhou') {
      // Dar por entregue daqui: serviu-se ao balcão, ou o garçom levou-a e
      // esqueceu-se de marcar. Sem isto o pedido ficava «por entregar» a noite
      // inteira e a bebida reservada por nada — e o stock da copa a mentir.
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro" onclick="copaEntregue(' + p.id + ')">'
        +   ico.ico('visto') + 'Dar por entregue</button>'
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

  /**
   * Servir menos do que se pediu.
   *
   * Cada linha do pedido ganha um número que se pode baixar — até zero, que é
   * tirar a bebida do pedido. Quem corta diz porquê, da mesma lista que serve
   * as recusas ou por palavras suas: alguém vai receber menos do que pediu, e
   * uma quantidade que encolhe em silêncio faz a pessoa pedir outra vez.
   */
  window.copaParcial = function (id) {
    var p = (EST.fila || []).filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    var motivos = (EST.motivos || []).map(function (m) {
      return { v: String(m.id), r: m.texto };
    });
    motivos.push({ v: '0', r: 'Outro — escrevo eu' });

    var campos = (p.itens || []).map(function (l) {
      var i = (EST.itens || []).filter(function (x) { return x.id === l.item_id; })[0];
      var ha = i ? i.disponivel : null;
      return { id: 'q' + l.item_id, rot: l.nome, tipo: 'numero',
               valor: l.quantidade, min: 0, max: l.quantidade,
               dica: 'Pediu ' + l.quantidade
                   + (ha !== null ? ' · há ' + ha + ' por servir' : '')
                   + '. Zero tira a bebida do pedido.' };
    });
    campos.push({ id: 'motivo_id', rot: 'Porquê', tipo: 'escolha',
                  valor: motivos.length > 1 ? motivos[0].v : '0', opcoes: motivos });
    campos.push({ id: 'motivo_texto', rot: 'Ou escreva', tipo: 'text', valor: '',
                  dica: 'Ex.: «Só restam duas — guardo-lhe as próximas.»' });

    licFormulario({
      titulo: 'Servir menos — ' + esc(p.codigo),
      guardar: 'Aprovar assim',
      largo: true,
      dica: 'Baixe o que não pode servir. O convidado vê o novo número <b>e</b> '
          + 'o motivo — é isso que o impede de voltar a pedir já a seguir.',
      campos: campos,
      aoGuardar: async function (v) {
        var cortes = {}, mexeu = false;
        (p.itens || []).forEach(function (l) {
          var q = parseInt(v['q' + l.item_id], 10);
          if (isNaN(q)) q = l.quantidade;
          cortes[String(l.item_id)] = q;
          if (q !== l.quantidade) mexeu = true;
        });
        if (!mexeu) {
          licJanelaErro('Não baixou nada. Para servir o pedido inteiro há o botão «Aprovar».');
          return false;
        }
        var todosZero = Object.keys(cortes).every(function (k) { return cortes[k] === 0; });
        if (todosZero) {
          licJanelaErro('Cortou tudo. Se não há nada para servir, use «Recusar» — '
                      + 'a pessoa fica a saber, e o pedido não fica a meio.');
          return false;
        }
        var mid = parseInt(v.motivo_id, 10) || 0;
        if (!mid && !v.motivo_texto) {
          licJanelaErro('Diga porquê: escolha um motivo ou escreva um.');
          return false;
        }
        var d = await window.api('bar_decidir', { method: 'POST',
          body: JSON.stringify({ id: id, decisao: 'aprovar', cortes: cortes,
                                 motivo_id: mid, motivo_texto: v.motivo_texto }) });
        if (!d || !d.success) return false;
        toast('Aprovado em parte, com o motivo.');
        await carregar(true);
        return true;
      }
    });
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
    // O limiar é da BEBIDA e vem do servidor (§27): a copa deixou de ter um
    // número inventado seu, e passou a dizer o mesmo que a montagem diz.
    var acabar = todos.filter(function (i) { return i.a_acabar; }).length;
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
      var luz = i.disponivel <= 0 ? 'mau' : (i.a_acabar ? 'meio' : 'bom');
      return '<div class="b-item"' + (PODE ? ' role="button" tabindex="0"'
        + ' onclick="copaAcerto(' + i.id + ')"'
        + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();copaAcerto('
        + i.id + ')}" title="Acertar o stock de ' + esc(i.nome) + '"' : '') + '>'
        + foto(i)
        + '<div><div class="nm"><span class="b-semaforo ' + luz + '"></span>' + esc(i.nome) + '</div>'
        +   '<div class="sub">' + i.stock + ' na copa'
        +   (i.reservado ? ' · ' + i.reservado + ' prometidas' : '')
        +   (i.a_acabar ? ' · avisa aos ' + i.stock_minimo : '') + '</div></div>'
        + '<div class="qt">' + i.disponivel + '</div>'
        + '</div>';
    }).join('');
  }

  window.copaAcerto = function (id) {
    var i = (EST.itens || []).filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    // Duas coisas se fazem a uma bebida a meio de uma noite, e só uma delas é
    // contar garrafas. A outra é TRAVÁ-LA: o copeiro olha para «Os números»,
    // vê o gin a sair a três por minuto, e quer fechá-lo por meia hora. Isso
    // vivia noutro ecrã, noutro separador, a três gestos de distância — e a
    // meio de uma festa três gestos é o mesmo que não existir.
    var regras = regrasDaBebida(id);
    licFormulario({
      titulo: esc(i.nome),
      guardar: 'Guardar',
      dica: 'Há <b>' + i.stock + '</b> lançadas, <b>' + i.reservado + '</b> prometidas. '
          + 'Some o que chegou, ou acerte para o que realmente há na copa.',
      extra: PODE
        ? '<div class="j-sec">' + ico.ico('trancado') + 'Quanto a esta bebida'
          + (regras.length ? ' <small>' + regras.length + '</small>' : '') + '</div>'
          + linhasDeRegra(regras, id)
          + '<div class="b-bt-fila">'
          +   '<button type="button" class="j-bt" onclick="copaSuspender(' + id + ')">'
          +     ico.ico('relogio') + 'Suspender por um bocado</button>'
          +   '<button type="button" class="j-bt" onclick="copaRegraDeBebida(' + id + ')">'
          +     ico.ico('mais') + 'Regra desta bebida</button>'
          + '</div>'
        : '',
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

  /* ---- as regras DE UMA BEBIDA ---------------------------------
     A ficha de uma pessoa mostra as regras dela desde sempre. A de uma bebida
     não existia: para saber o que travava o gin era preciso ir às Regras do
     Bar e ler a lista toda à procura da palavra «gin». */

  /** As regras escritas sobre esta bebida — só as dela, não as que a apanham
      por serem de toda a gente. É por essas que se pergunta ao carregar-lhe. */
  function regrasDaBebida(id) {
    return (EST.regras || []).filter(function (r) {
      return r.escopo === 'item' && Number(r.alvo_id) === Number(id);
    });
  }

  /** As mesmas linhas da ficha de uma pessoa: a frase, o vigor, editar, tirar. */
  function linhasDeRegra(regras, itemId) {
    if (!regras.length) {
      return '<p class="dica">Nenhuma. Valem-lhe as regras gerais da casa.</p>';
    }
    return regras.map(function (r) {
      return '<div class="j-linha' + (r.vigor === 'agora' ? '' : ' espera') + '">'
        + '<span>' + esc(r.frase)
        + (r.vigor === 'ainda' ? ' <small>— ainda não são horas</small>' : '')
        + (r.vigor === 'passou' ? ' <small>— já passou a hora</small>' : '')
        + (r.nota ? '<br><small>' + esc(r.nota) + '</small>' : '')
        + '</span>'
        // Os mesmos dois botões do painel das Regras do Bar, e pela mesma
        // ordem: quem aprendeu a editar uma regra lá não tem de reaprender aqui.
        + (PODE ? '<span class="b-reg-bt">'
                + btIco('lapis', 'Editar esta regra',
                        'copaRegraEditar(' + r.id + ',' + itemId + ')')
                + btIco('lixo', 'Levantar esta regra',
                        'copaRegraForaDaBebida(' + r.id + ',' + itemId + ')', 'perigo')
                + '</span>' : '')
        + '</div>';
    }).join('');
  }

  /**
   * A janela de uma bebida vista pelas suas regras.
   *
   * É a mesma coisa que `copaAcerto` mostra em baixo, mas por si só: é aqui
   * que «Os números» aterra quando se carrega numa barra do gráfico. Quem
   * chega por ali não quer contar garrafas — viu um número a subir depressa e
   * quer fazer alguma coisa a respeito dele.
   */
  window.copaRegrasDaBebida = function (id) {
    var i = (EST.itens || []).filter(function (x) { return x.id === id; })[0];
    if (!i) { toast('Essa bebida já não está no menu.', true); return; }
    var regras = regrasDaBebida(id);
    licJanela('Regras de ' + esc(i.nome),
      '<div class="dica">' + i.disponivel + ' disponíveis'
      + (i.reservado ? ' · ' + i.reservado + ' prometidas' : '') + '.</div>'
      + '<div class="j-sec">' + ico.ico('trancado') + 'Quanto a esta bebida'
      + (regras.length ? ' <small>' + regras.length + '</small>' : '') + '</div>'
      + linhasDeRegra(regras, id)
      + (PODE
          ? '<div class="b-bt-fila">'
            + '<button type="button" class="j-bt" onclick="copaSuspender(' + id + ')">'
            +   ico.ico('relogio') + 'Suspender por um bocado</button>'
            + '<button type="button" class="j-bt j-bt-sim" onclick="copaRegraDeBebida(' + id + ')">'
            +   ico.ico('mais') + 'Regra nova</button>'
            + '</div>'
          : ''),
      null, { cancelar: 'Fechar' });
  };

  /** Uma regra desta bebida, na janela partilhada das Regras do Bar. */
  window.copaRegraDeBebida = function (id) {
    licFecharJanela();
    window.barRegraNova({ sobre: 'i' + id });
  };

  /* De que bebida se saiu para ir editar uma regra.
     A janela partilhada só recebe a regra — não sabe de onde se veio, nem tem
     de saber. Sem esta lembrança, quem editasse uma regra do gin era largado
     no ecrã de trás como se nada tivesse acontecido. Vale para UMA volta: põe
     -se ao sair, gasta-se ao voltar. Guardá-la mais tempo dava o efeito
     contrário — editar depois uma regra geral abria a janela do gin. */
  var voltarABebida = 0;

  window.copaRegraEditar = function (id, itemId) {
    voltarABebida = itemId || 0;
    licFecharJanela();
    window.barRegraEditar(id);
  };

  window.copaRegraForaDaBebida = async function (id, itemId) {
    var d = await window.api('bar_regra_apagar', { method: 'POST',
                                                   body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    await carregar(true);
    licFecharJanela();
    copaRegrasDaBebida(itemId);
  };

  /**
   * Fechar uma bebida por um bocado.
   *
   * É o gesto do meio da noite: o gin está a sair a três por minuto e a copa
   * não tem mãos a medi-lo. Não se levanta o menu, não se apaga a bebida — põe
   * -se-lhe uma regra com hora de saída, e ela VOLTA SOZINHA quando o tempo
   * passar. Ninguém tem de se lembrar de a levantar, que é a parte que sempre
   * corre mal: uma pausa esquecida é uma bebida que ficou fechada a noite toda.
   *
   * Os minutos contam-se no servidor (expira_min): a casa corre numa hora e o
   * telemóvel de quem trabalha corre noutra.
   */
  window.copaSuspender = function (id) {
    var i = (EST.itens || []).filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    licFecharJanela();
    licFormulario({
      titulo: 'Suspender ' + esc(i.nome),
      guardar: 'Suspender',
      dica: 'A bebida sai do menu de toda a gente e volta sozinha quando o '
          + 'tempo acabar. Quem a pedir entretanto lê quanto falta.',
      campos: [
        { id: 'minutos', rot: 'Por quanto tempo', tipo: 'escolha', valor: '30',
          procura: false,
          opcoes: [{ v: '10', r: '10 minutos' }, { v: '15', r: '15 minutos' },
                   { v: '30', r: 'Meia hora' },  { v: '60', r: 'Uma hora' },
                   { v: '120', r: 'Duas horas' }] },
        { id: 'mensagem', rot: 'O que se diz a quem a pedir', tipo: 'text', valor: '',
          largura: 2,
          dica: 'Em branco, a casa diz «está indisponível de momento» e conta '
              + 'o tempo que falta — que é o que a pessoa quer saber.' }
      ],
      aoGuardar: async function (v) {
        var min = parseInt(v.minutos, 10) || 30;
        var d = await window.api('bar_regra_guardar', { method: 'POST', silencioso: true,
          body: JSON.stringify({ escopo: 'item', alvo_id: id, sujeito: 'convidado',
                                 unidade: 'bebidas', quantidade: 0, janela_min: 0,
                                 expira_min: min, mensagem: v.mensagem,
                                 nota: 'Suspensa pela copa por ' + min + ' min' }) });
        if (!d || !d.success) {
          licJanelaErro((d && d.message) || 'Não foi possível suspender.');
          return false;
        }
        avisarFora(d.fora);
        toast(i.nome + ' suspensa por ' + min + ' min. Volta sozinha.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- a ficha de um convidado, com as regras dele ---------------
  // É uma coisa que se faz a correr, no meio da festa, com a pessoa à frente:
  // «este senhor já vai no quinto whisky», «esta senhora está grávida».
  /** As notas de entrega desta pessoa, todas, da mais recente para trás. */
  function notasDaFicha(ns) {
    ns = ns || [];
    if (!ns.length) {
      return '<div class="j-sec">' + ico.ico('nota') + 'Notas dos garçons</div>'
           + '<p class="dica">Nenhuma. Os garçons escrevem-nas ao entregar, e '
           + 'só quando há alguma coisa a dizer.</p>';
    }
    return '<div class="j-sec">' + ico.ico('nota') + 'Notas dos garçons '
      +   '<small>' + ns.length + '</small></div>'
      + ns.map(function (n) {
          return '<div class="b-nota-ficha"><span>' + esc(n.texto) + '</span>'
            + '<em>' + esc(n.codigo || '') + (n.quando ? ' · ' + ha(n.quando) : '')
            + '</em></div>';
        }).join('');
  }

  var fichaAberta = null;
  window.copaFicha = async function (id) {
    var d = await window.api('bar_ficha&convidado=' + id, { method: 'GET' });
    if (!d || !d.success) return;
    fichaAberta = { id: id, nome: d.convidado.nome };
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
      // As notas dos garçons. A fila mostra as três últimas ao decidir, que
      // chegam para o gesto de um minuto; a ficha é o outro momento — o de
      // perceber a noite de alguém —, e aí três não chegam. Uma nota escrita
      // às 23h («pediu para não lhe servirem mais») é o que explica o que se
      // está a ver à uma da manhã.
      + notasDaFicha(d.notas), null, { cancelar: 'Fechar' });
    // A lista dos telemóveis e o «Soltar» saíram daqui. Eram a manutenção de
    // uma coisa que a ficha não é: a ficha existe para decidir o que esta
    // pessoa pode beber, e três linhas sobre que aparelho se escolheu em que
    // nome só tiravam espaço à única pergunta que se faz a meio de uma festa.
  };

  /* ---- a regra de uma pessoa -----------------------------------
     Já não é um formulário à parte. É a janela das Regras do Bar
     (assets/bar-regras.js) com o «a quem» já respondido: uma regra de pessoa é
     uma EXTENSÃO das regras da casa, e não uma segunda espécie de regra com a
     sua própria gramática. Havia duas janelas parecidas, com campos
     diferentes, e era por aí que as duas telas começavam a discordar. */
  window.copaRegraNova = function (convidadoId) {
    var nome = (fichaAberta && fichaAberta.nome) || '';
    licFecharJanela();
    window.barRegraNova({ convidado_id: convidadoId, nome: nome });
  };

  /** Depois de pôr ou levantar uma regra pela janela partilhada: a copa
      recarrega, avisa do que deixou de caber, e volta à ficha de onde saiu. */
  window.barRegraPosta = function (r, pre) {
    avisarFora(r && r.fora);
    var volta = voltarABebida; voltarABebida = 0;
    if (pre && pre.convidado_id) { copaFicha(pre.convidado_id); return; }
    // Uma regra escrita a partir de uma bebida devolve à bebida: quem entrou
    // por «Os números» a ver o gin a subir quer ver a regra escrita ali, e não
    // ser deixado no ecrã de onde saiu como se nada tivesse acontecido.
    var sobre = pre && pre.sobre;
    if (typeof sobre === 'string' && sobre.charAt(0) === 'i') {
      copaRegrasDaBebida(parseInt(sobre.slice(1), 10));
    } else if (volta) {
      copaRegrasDaBebida(volta);
    }
  };

  /**
   * Fechar um pedido que está por entregar, a partir da copa.
   *
   * Pergunta-se antes: é o gesto que baixa o stock a sério (§4), e feito por
   * engano tira do armazém uma bebida que ainda lá está.
   */
  window.copaEntregue = async function (id) {
    var p = (EST.fila || []).filter(function (x) { return x.id === id; })[0] || {};
    var r = await licConfirmar({
      titulo: 'Dar por entregue?',
      texto: 'O pedido ' + esc(p.codigo || '') + ' fecha, e as bebidas saem do '
           + 'armazém. Faça-o quando ele estiver mesmo na mão de quem o pediu.',
      confirmar: 'Sim, foi entregue', icone: 'visto'
    });
    if (!r || !r.sim) return;
    var d = await window.api('bar_entregue', { method: 'POST',
                                               body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Pedido entregue.');
    await carregar(true);
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

  /* ============================================================
     APROVAR UM PEDIDO AO BALCÃO

     Chamava-se «pedir por um convidado» e estava numa coluna lateral, ao pé
     do stock. Mas quem lança um pedido daqui JÁ o decidiu — está a olhar para
     a pessoa e para as garrafas —, e por isso ele nasce aprovado e não em
     análise: pô-lo a esperar por quem acabou de o escrever era encher a fila
     de trabalho imaginário.

     Duas saídas, e a segunda é a mais comum: o copo já foi na mão, e o pedido
     nasce ENTREGUE, sem passar por entrega nenhuma. Sem isso, vai para «por
     entregar» como um aprovado qualquer, e um garçom leva-o.

     Por isso subiu para o topo da fila por decidir, com o nome do gesto:
     «Aprovar pedido».
     ============================================================ */
  window.copaPedirPor = function () {
    var itens = (EST.itens || []).filter(function (i) {
      return i.estado === 'ativo' && i.disponivel > 0;
    });
    if (!itens.length) { toast('Não há nada disponível para pedir.', true); return; }
    licFormulario({
      titulo: 'Aprovar um pedido',
      guardar: 'Aprovar',
      largo: true,
      // «Entra já aprovado» é verdade quanto à FILA, e não quanto às regras.
      // As regras do bar valem aqui como valem em qualquer outra porta: se uma
      // delas travar esta bebida, o pedido não passa — e é assim que tem de
      // ser, senão bastava lançar pelo balcão para as furar todas.
      dica: 'É um pedido feito ao balcão: entra já aprovado, porque quem o '
          + 'escreve é quem o decide. As regras do bar valem à mesma.',
      campos: [
        { id: 'nome', rot: 'Nome do convidado', tipo: 'text', valor: '', largura: 2,
          dica: '<span id="pp-achados"></span>' },
        { id: 'item', rot: 'Bebida', tipo: 'escolha', valor: String(itens[0].id),
          opcoes: itens.map(function (i) {
            return { v: String(i.id), r: i.nome + ' (' + i.disponivel + ')' };
          }) },
        { id: 'quantidade', rot: 'Quantas', tipo: 'numero', valor: 1, min: 1, max: 12 },
        { id: 'entregue', rot: 'Já foi entregue?', tipo: 'sim', valor: true, largura: 2,
          aoLado: 'Sim, o copo já seguiu com a pessoa',
          dica: 'Desligue se a bebida ainda tem de ir à mesa: o pedido passa '
              + 'para «por entregar» e um garçom leva-o.' }
      ],
      aoGuardar: async function (v) {
        if (!ppEscolhido) { licJanelaErro('Escolha o convidado na lista.'); return false; }
        // Silencioso, para a recusa das regras se ler DENTRO da janela, ao pé
        // do campo que a há-de resolver, e não numa nota que passa no canto.
        var d = await window.api('bar_pedir_por', { method: 'POST', silencioso: true,
          body: JSON.stringify({ convidado_id: ppEscolhido.id,
                                 mesa_id: ppEscolhido.mesa_id,
                                 entregue: !!v.entregue,
                                 itens: [{ item_id: parseInt(v.item, 10),
                                           quantidade: parseInt(v.quantidade, 10) || 1 }] }) });
        if (!d || !d.success) {
          licJanelaErro((d && d.message) || 'Não foi possível lançar o pedido.');
          return false;
        }
        toast(v.entregue
          ? 'Pedido ' + d.pedido.codigo + ' servido. O stock já desceu.'
          : 'Pedido ' + d.pedido.codigo + ' aprovado — está por entregar.');
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

  /* A coluna do stock teve aqui uma lista de atalhos. Ficou vazia: «pedir por
     um convidado» subiu para o topo da fila, que é onde o gesto acontece, e as
     regras passaram a ser uma aba desta mesma página. O que restava era um
     botão a mandar o copeiro para bar.php — uma página a que ele não tem
     acesso, e que portanto o punha na tela de entrada. Um atalho que expulsa
     quem lhe carrega é pior do que atalho nenhum. */

  // ---- o relógio ------------------------------------------------
  // O módulo das regras liga-se ANTES da primeira leitura, e não quando o
  // separador se abre: a janela de uma regra de pessoa sai da ficha, e a ficha
  // abre-se da fila — sem isto, quem nunca tivesse ido ao separador das regras
  // abria a janela com o «Sobre o quê» vazio.
  window.BR.ligar({
    estado: function () { return EST || {}; },
    pode: PODE,
    recarregar: function () { return carregar(true); }
  });
  carregar();
  relogio = setInterval(function () { if (!document.hidden) carregar(true); }, 8000);
  // Os «há N min» envelhecem sozinhos entre leituras: sem isto, um pedido
  // ficava «há 2 min» durante oito segundos de cada vez.
  tique = setInterval(function () { if (!document.hidden && EST) pintarFila(); }, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) carregar(true);
  });
})();
