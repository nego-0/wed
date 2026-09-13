/* ============================================================
   bar-entrega.js — O posto do garçom (entregas.php)

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

  // As peças comuns do módulo (assets/bar-pecas.js): a mesma procura, o mesmo
  // vazio, a mesma miniatura que a copa e a montagem usam.
  var ico = window.ICO, BP = window.BP;
  var esc = BP.esc, toast = BP.toast, chave = BP.chave, foto = BP.foto;
  var campoBusca = BP.campoBusca, ligarBusca = BP.ligarBusca, vazio = BP.vazio;
  function ha(quando) { return BP.ha(quando, desvio); }

  /* Um tabuleiro cheio são seis ou oito cartões na lista, e a pergunta de quem
     o segura é sempre a mesma: «qual é o da Laranjeira?». A procura apanha a
     mesa, o nome, o código e as bebidas — o que quer que a pessoa tenha na
     cabeça nesse momento. */
  var busca = '';

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

  /** A barra: a procura, e o atalho de quem pede sem rede. */
  function pintarFerramentas() {
    var fer = $('b-fer');
    if ($('q-ent')) return;             // já lá está; não se rouba o cursor
    fer.innerHTML = campoBusca('q-ent', 'Procurar mesa, nome ou bebida', busca)
      + (PODE ? '<button class="btn btn-fantasma" onclick="entPedirPor()" '
              + 'title="Lançar um pedido por quem não tem rede">'
              + ico.ico('mao') + 'Pedir por alguém</button>' : '');
    ligarBusca('q-ent', function (v) { busca = v; pintar(); });
  }

  /** O que a procura deixa passar. Sem procura, passa tudo. */
  function peneira(ps) {
    var q = chave(busca);
    if (!q) return ps;
    return ps.filter(function (p) {
      var saco = [p.codigo, p.convidado, p.mesa, p.mesa_qr, p.pedido_por]
        .concat((p.itens || []).map(function (l) { return l.nome; }));
      return chave(saco.join(' ')).indexOf(q) >= 0;
    });
  }

  function pintar() {
    var ps = EST.pedidos || [];
    // «Meu» é o que eu apanhei: dois garçons no mesmo salão não podem
    // estar a ver a mesma lista como se fosse de ambos.
    var minhas   = ps.filter(function (p) { return p.estado === 'a_caminho' && p.entregue_por === EU; });
    var doOutro  = ps.filter(function (p) { return p.estado === 'a_caminho' && p.entregue_por !== EU; });
    var espera   = ps.filter(function (p) { return p.estado === 'aprovado'; });
    var voltaram = ps.filter(function (p) { return p.estado === 'falhou'; });

    $('k-entregues').textContent = (EST.estado && EST.estado.entregues) || 0;
    pintarFerramentas();

    // A procura corta as quatro filas de uma vez: quem escreve «laranjeira»
    // quer o pedido da Laranjeira, esteja ele em que fila estiver.
    var mF = peneira(minhas), eF = peneira(espera),
        vF = peneira(voltaram), oF = peneira(doOutro);
    if (busca && !(mF.length + eF.length + vF.length + oF.length)) {
      $('b-listas').innerHTML = '<div class="b-cartao">'
        + vazio('procurar', 'Nada com «' + busca + '»',
                'Nenhum dos ' + ps.length + ' pedidos em curso responde ao que procura.',
                '<button class="btn btn-fantasma" onclick="entLimpar()">'
                + ico.ico('volta') + 'Ver todos</button>') + '</div>';
      pintarTempos();
      return;
    }

    var html = '';
    // A nota da secção só faz sentido quando há lá alguma coisa: «pouse-os e
    // carregue em Entregue» por cima de «nada nas mãos» é uma ordem sem objecto.
    html += seccao('mao', 'Comigo', mF,
                   ['tabuleiro', 'Nada nas mãos',
                    'Apanhe um da fila de baixo e ele passa para aqui.'],
                   mF.length ? 'pouse-os e carregue em Entregue' : '');
    html += seccao('sino', 'Por apanhar', eF,
                   ['visto', 'A copa está em dia',
                    'Não há nada aprovado à espera de quem o leve.'],
                   eF.length ? 'os mais antigos primeiro' : '');
    if (vF.length) {
      html += seccao('volta', 'Voltaram', vF, null,
                     'ninguém estava na mesa — tente outra vez ou devolva à copa');
    }
    if (oF.length) {
      html += seccao('pessoas', 'Com outros', oF, null,
                     oF.length === 1 ? '1 pedido a caminho' : oF.length + ' pedidos a caminho');
    }
    $('b-listas').innerHTML = html;
    pintarTempos();
  }

  window.entLimpar = function () {
    busca = '';
    var el = $('q-ent');
    if (el) { el.value = ''; el.parentNode.classList.remove('tem'); }
    pintar();
  };

  /**
   * Um cabeçalho de fila: o sinal, o nome, quantos são, e a nota do lado.
   *
   * A contagem vive no cabeçalho e não numa pastilha, porque aqui não há abas:
   * rola-se. E numa lista que se rola, saber quantos faltam ANTES de os contar
   * com o olho é o que evita a segunda passagem.
   */
  function seccao(icone, titulo, ps, semNada, nota) {
    var h = '<div class="b-secao">' + ico.ico(icone) + esc(titulo)
      + (ps.length ? '<span class="n">' + ps.length + '</span>' : '')
      + (nota ? '<small>' + esc(nota) + '</small>' : '') + '</div>';
    if (!ps.length) {
      // Com procura escrita, um vazio de fila não é notícia nenhuma — o que
      // ali falta é o que a procura cortou, e dizê-lo em cada fila seria
      // repetir a mesma frase quatro vezes.
      if (!semNada || busca) return '';
      return h + '<div class="b-cartao">' + vazio(semNada[0], semNada[1], semNada[2]) + '</div>';
    }
    return h + ps.map(function (p) { return cartao(p, p.estado === 'a_caminho' && p.entregue_por !== EU); }).join('');
  }

  function cartao(p, deOutro) {
    var linhas = (p.itens || []).map(function (l) {
      return '<span class="b-linha">' + foto(l) + '<b>' + l.quantidade + '×</b> '
        + esc(l.nome) + '</span>';
    }).join('');

    // Notas de percurso: são a excepção, e por isso ficam por baixo e em
    // corpo pequeno. Uma pessoa que mudou de mesa, ou uma bebida que outro
    // pediu — o garçom bate à mesa e diz o nome de quem a vai BEBER.
    var notas = [];
    if (p.mesa_qr && p.mesa && p.mesa_qr !== p.mesa) notas.push('pediu na ' + esc(p.mesa_qr));
    if (p.pedido_por) notas.push('pedido por ' + esc(p.pedido_por));
    if (deOutro) notas.push('vai com ' + esc(p.entregue_por || 'outra pessoa'));

    // O título é o que faz ANDAR. Quase sempre é a mesa; quando ela falta, é
    // o nome — porque é por ele que se pergunta no salão. Deixar «Sem mesa»
    // como título era pôr uma ausência em corpo grande e empurrar para letra
    // miúda a única coisa que ali serve para achar a pessoa.
    var temMesa = !!p.mesa;
    return '<div class="b-cartao b-ped' + (p.estado === 'a_caminho' && !deOutro ? ' minha' : '') + '">'
      + '<div class="b-destino">' + ico.ico(temMesa ? 'mesa' : 'pessoa')
      +   '<span class="mesa">' + esc(temMesa ? p.mesa : (p.convidado || 'Sem nome')) + '</span>'
      +   '<span class="ha">' + esc(ha(p.decidido_em || p.criado_em)) + '</span>'
      + '</div>'
      + '<div class="b-quem2"><span class="cod">' + esc(p.codigo) + '</span>'
      +   (temMesa
            ? '<span class="nm">' + esc(p.convidado || 'Sem nome') + '</span>'
            : '<span class="sem">' + ico.ico('aviso') + 'Sem mesa — pergunte na copa</span>')
      + '</div>'
      + '<div class="b-linhas">' + linhas + '</div>'
      + (notas.length ? '<div class="onde">' + notas.join(' · ') + '</div>' : '')
      + (p.motivo ? '<div class="b-bandeira">' + ico.ico('aviso')
                  + 'Da última vez: ' + esc(p.motivo) + '</div>' : '')
      + (deOutro ? '' : (PODE ? acoes(p) : ''))
      + '</div>';
  }

  function acoes(p) {
    if (p.estado === 'a_caminho') {
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro b-bt-grande" onclick="entEntregue(' + p.id + ')">'
        +   ico.ico('visto') + 'Entregue</button>'
        + '<div class="b-acoes-menor">'
        +   '<button class="btn btn-fantasma" onclick="entEntregueNota(' + p.id + ')">'
        +     ico.ico('nota') + 'Entregue, com nota</button>'
        // Mudar a mesa antes de dizer que ela estava vazia: a pessoa pediu
        // sentada e levantou-se para dançar, e isso acontece a toda a hora.
        // «Não estava na mesa» manda o pedido de volta à copa e faz esperar
        // outra vez por uma bebida que já estava pronta.
        +   '<button class="btn btn-fantasma" onclick="entMudarMesa(' + p.id + ')">'
        +     ico.ico('mesa') + 'Mudar de mesa</button>'
        +   '<button class="btn btn-fantasma" onclick="entFalhou(' + p.id + ')">'
        +     ico.ico('volta') + 'Não estava na mesa</button>'
        + '</div></div>';
    }
    // Aprovado ou de volta: apanhar é o passo seguinte, e entregar directo
    // existe para quem já tem a bebida na mão quando carrega.
    return '<div class="b-acoes">'
      + '<button class="btn btn-ouro b-bt-grande" onclick="entApanhar(' + p.id + ')">'
      +   ico.ico('mao') + 'Apanhar</button>'
      + '<button class="btn btn-fantasma" onclick="entEntregue(' + p.id + ')">'
      +   ico.ico('visto') + 'Já entreguei</button>'
      + '</div>';
  }

  // ---- as três acções -------------------------------------------
  window.entApanhar = async function (id) {
    var d = await window.api('bar_apanhar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('É seu. Boa viagem.');
    await carregar(true);
  };

  window.entEntregue = async function (id, nota) {
    // Sem confirmação: é a acção que se faz cem vezes por noite, e uma
    // pergunta a meio do salão com um tabuleiro na mão é um pedido caído. A
    // nota é o caminho À PARTE, no botão do lado — nunca no do meio.
    var d = await window.api('bar_entregue', { method: 'POST',
      body: JSON.stringify({ id: id, nota: nota || '' }) });
    if (!d || !d.success) return;
    toast(nota ? 'Entregue, com a nota para a copa.' : 'Entregue. O stock já desceu.');
    await carregar(true);
  };

  /**
   * Entregar, e dizer o que se viu.
   *
   * O garçom é o único do bar que fala com o convidado. O que ele traz da
   * mesa — «pediu para não lhe servirem mais», «está com os miúdos», «não era
   * para ele» — não tinha onde ficar, e por isso morria ali. Aqui fica no
   * pedido, e a copa lê-o quando essa pessoa pedir a seguir.
   *
   * É um caminho à parte de propósito. Entregar tem de continuar a ser um
   * toque; pedir uma frase escrita cem vezes por noite era garantir que
   * ninguém escrevia nenhuma.
   */
  window.entEntregueNota = function (id) {
    var p = (EST.pedidos || []).filter(function (x) { return x.id === id; })[0];
    licFormulario({
      titulo: 'Entregue — com uma nota',
      guardar: 'Entregue',
      dica: p ? 'Sobre <b>' + esc(p.convidado || 'o convidado') + '</b>, na '
                + esc(p.mesa || 'mesa') + '. Só a copa lê isto.' : '',
      campos: [{ id: 'nota', rot: 'O que aconteceu à mesa', tipo: 'area', linhas: 2,
                 valor: '', largura: 2,
                 dica: 'Ex.: «Pediu para não lhe servirem mais nada.» · '
                     + '«Levou duas, mas era para a mãe.»' }],
      aoGuardar: async function (v) {
        if (!v.nota) { licJanelaErro('Escreva a nota — ou use o botão «Entregue», sem ela.'); return false; }
        await window.entEntregue(id, v.nota);
        return true;
      }
    });
  };

  /* ---- mudar a mesa de um pedido -------------------------------
     A lista das mesas pede-se uma vez e fica (BP.mesasDoBar guarda-a): num
     salão são vinte ou trinta, não mudam durante a festa, e pedi-las a cada
     janela era uma ida ao servidor de cada vez que alguém se levanta. É a
     mesma lista que a janela de lançar um pedido usa — duas listas das mesmas
     mesas acabavam a responder coisas diferentes à mesma pergunta. */
  window.entMudarMesa = async function (id) {
    var MESAS = await BP.mesasDoBar();
    if (!MESAS.length) { toast('Não há mesas marcadas neste casamento.', true); return; }
    var p = ((EST && EST.pedidos) || []).filter(function (x) { return x.id === id; })[0] || {};
    var opcoes = [{ v: '0', r: 'Sem mesa — ao balcão' }].concat(
      MESAS.map(function (m) { return { v: String(m.id), r: m.nome }; }));
    licFormulario({
      titulo: 'Mudar a mesa do ' + (p.codigo || 'pedido'),
      guardar: 'Mudar',
      dica: 'A pessoa mudou de sítio. O pedido segue-a — e não volta à copa.',
      campos: [
        { id: 'mesa', rot: 'Entregar em', tipo: 'escolha',
          valor: String(p.mesa_id || 0), opcoes: opcoes,
          procura: true, dicaProcura: 'Nome da mesa', largura: 3 }
      ],
      aoGuardar: async function (v) {
        var d = await window.api('bar_mudar_mesa', { method: 'POST', body: JSON.stringify(
          { id: id, mesa_id: parseInt(v.mesa, 10) || 0 }) });
        if (!d || !d.success) return false;
        toast('Mesa mudada.');
        await carregar(true);
        return true;
      }
    });
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
    // A lista das bebidas pede-se só quando é preciso, que é raro. E pede-se a
    // `bar_itens_pedir`, e não a `bar_estado`: aquela é a leitura da copa, o
    // garçom não tem acesso a ela, e o que chegava aqui era um 403 disfarçado
    // de lista vazia — a janela dizia «Não há nada disponível para pedir» com
    // a copa cheia, e o garçom ficava sem poder lançar um pedido.
    var e = await window.api('bar_itens_pedir', { method: 'GET', silencioso: true });
    var itens = (e && e.success ? e.itens : []);
    if (!itens.length) { toast('Não há nada disponível para pedir.', true); return; }
    // Quem anda na sala é quem melhor sabe onde a pessoa está: a mesa de
    // entrega escolhe-se aqui, e não fica presa à da planta.
    var mesas = await BP.mesasDoBar();
    licFormulario({
      titulo: 'Pedir por um convidado',
      guardar: 'Lançar o pedido',
      largo: true,
      // Quem lança daqui SUBMETE, e não serve. O pedido entra na fila por
      // decidir como o de qualquer convidado — é a copa que o aprova. Dizê-lo
      // no formulário evita a única leitura errada possível: a de que carregar
      // no botão põe a bebida no tabuleiro.
      dica: 'Escreva parte do nome, escolha a pessoa, e depois a bebida. '
          + 'O pedido entra na fila por decidir — quem o aprova é a copa.',
      campos: [
        { id: 'nome', rot: 'Nome do convidado', tipo: 'text', valor: '', largura: 2,
          dica: '<span id="pp-achados"></span>' },
        { id: 'item', rot: 'Bebida', tipo: 'escolha', valor: String(itens[0].id),
          opcoes: itens.map(function (i) {
            return { v: String(i.id), r: i.nome + ' (' + i.disponivel + ')' };
          }) },
        { id: 'quantidade', rot: 'Quantas', tipo: 'numero', valor: 1, min: 1, max: 12 },
        BP.campoMesa(mesas)
      ],
      aoGuardar: async function (v) {
        if (!ppEscolhido) { licJanelaErro('Escolha o convidado na lista.'); return false; }
        // Silencioso: se forem as regras a travar, a razão lê-se dentro da
        // janela, ao pé do campo que a há-de resolver.
        var d = await window.api('bar_pedir_por', { method: 'POST', silencioso: true,
          body: JSON.stringify({ convidado_id: ppEscolhido.id,
                                 mesa_id: BP.mesaEscolhida(v.mesa, ppEscolhido),
                                 itens: [{ item_id: parseInt(v.item, 10),
                                           quantidade: parseInt(v.quantidade, 10) || 1 }] }) });
        if (!d || !d.success) {
          licJanelaErro((d && d.message) || 'Não foi possível lançar o pedido.');
          return false;
        }
        toast('Pedido ' + d.pedido.codigo + ' na fila da copa, por decidir.');
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
