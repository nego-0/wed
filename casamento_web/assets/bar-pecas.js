/* ============================================================
   bar-pecas.js — As peças de que os quatro ecrãs do bar são feitos

   O módulo tem quatro páginas (montar, copa, entregas, convidado) e as quatro
   fazem as mesmas coisas: escapar texto, dizer «feito» num rodapé, procurar
   sem acentos, desenhar uma caixa de procura, uma pastilha de filtro, um botão
   de ícone, um estado vazio e a miniatura de uma bebida.

   Estavam escritas quatro vezes, com quatro pequenas diferenças que ninguém
   escolheu — uma procura que ignorava acentos aqui e não ali, um vazio com
   botão numa página e sem botão na outra. Isso é o que faz um módulo parecer
   quatro trabalhos de pessoas diferentes.

   Aqui ficam uma vez. Quem quiser um filtro novo herda o comportamento todo,
   e uma correcção de acessibilidade feita neste ficheiro chega às quatro
   páginas no mesmo instante.

   Depende de assets/icones.js (window.ICO), que tem de vir antes.
   ============================================================ */
(function () {
  'use strict';

  var ico = window.ICO;

  /** Texto que vai para dentro de HTML. A primeira regra da casa. */
  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }

  /** Uma aspa simples, para o que vai dentro de um onclick="...('aqui')". */
  function apo(s) { return esc(s).replace(/'/g, '&#39;'); }

  /**
   * O rodapé que diz o que aconteceu.
   *
   * Dura 2,6 s — o suficiente para se ler uma frase curta, pouco para
   * incomodar quem já seguiu em frente. Erros ficam com a cor do erro.
   */
  function toast(m, mau) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = m;
    t.className = 'toast mostrar' + (mau ? ' erro' : '');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.className = 'toast'; }, 2600);
  }

  /**
   * A chave de procura: sem acentos, sem maiúsculas.
   *
   * Quem escreve «agua» num teclado de telemóvel, de pé e com um copo na
   * outra mão, quer encontrar «Água». Uma procura que exige o acento é uma
   * procura que não serve o sítio onde é usada.
   */
  function chave(s) {
    // Uma implementação só. A mesma conta vive em janela.js (licChave), porque
    // a escolha com procura precisa dela em páginas que não carregam esta
    // folha — e duas cópias da mesma regra são duas cópias que divergem: já
    // aconteceu neste módulo, quando cada ecrã trazia a sua procura e uma
    // ignorava acentos e a outra não. Aqui prefere-se a de lá quando existe.
    if (typeof window.licChave === 'function') return window.licChave(s);
    return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  /**
   * A caixa de procura, com a lupa dentro e o ✕ que só aparece quando há o
   * que limpar — um botão de limpar sempre visível é um botão a piscar
   * «carrega em mim» quando não há nada para fazer.
   */
  function campoBusca(id, dica, valor) {
    return '<div class="b-busca' + (valor ? ' tem' : '') + '" id="' + id + '-cx">'
      + ico.ico('procurar')
      + '<input type="search" id="' + id + '" placeholder="' + esc(dica) + '" '
      +   'value="' + esc(valor || '') + '" autocomplete="off" spellcheck="false" '
      +   'aria-label="' + esc(dica) + '">'
      + '<button type="button" class="limpar" aria-label="Limpar a procura">'
      +   ico.ico('xis') + '</button></div>';
  }

  /**
   * Liga um campo de procura ao que ele filtra.
   *
   * A espera de 160 ms existe para não repintar uma grelha inteira a cada
   * tecla; o ✕ devolve o foco ao campo, porque quem limpa é quase sempre para
   * escrever outra coisa.
   */
  function ligarBusca(id, aoMudar) {
    var el = document.getElementById(id);
    if (!el) return;
    var cx = document.getElementById(id + '-cx'), espera = null;
    var correr = function () {
      cx.classList.toggle('tem', !!el.value);
      clearTimeout(espera);
      espera = setTimeout(function () { aoMudar(el.value.trim()); }, 160);
    };
    el.addEventListener('input', correr);
    cx.querySelector('.limpar').addEventListener('click', function () {
      el.value = ''; correr(); el.focus();
    });
  }

  /**
   * Uma pastilha de filtro.
   *
   * Ligada, não muda só de cor: ganha peso e fundo cheio. Quem não distingue
   * as duas cores continua a ver qual está escolhida (§25.7). A contagem vive
   * dentro da pastilha — é ela que diz se vale a pena carregar.
   *
   * cfg: { rot, icone, n, cor, ligada, accao, titulo }
   */
  function pilula(cfg) {
    var n = cfg.n;
    return '<button type="button" class="b-pilula' + (cfg.ligada ? ' on' : '') + '"'
      + (cfg.cor ? ' style="--pt:' + esc(cfg.cor) + '"' : '')
      + (cfg.titulo ? ' title="' + esc(cfg.titulo) + '"' : '')
      + ' onclick="' + cfg.accao + '" aria-pressed="' + (cfg.ligada ? 'true' : 'false') + '">'
      + (cfg.cor ? '<span class="pt"></span>' : '')
      + (cfg.icone ? ico.ico(cfg.icone) : '')
      + esc(cfg.rot)
      + (n === null || n === undefined || n === '' ? '' : '<span class="n">' + n + '</span>')
      + '</button>';
  }

  /**
   * Um botão que é só um ícone.
   *
   * O rótulo mora no title E no aria-label: o desenho é reconhecível de
   * relance, e quem precisar da palavra — o rato parado, o leitor de ecrã —
   * tem-na sempre. Um ícone sem nome acessível é um botão mudo.
   */
  function btIco(icone, rot, accao, extra) {
    return '<button type="button" class="b-bt-ico ' + (extra || '') + '" '
      + 'title="' + esc(rot) + '" aria-label="' + esc(rot) + '" '
      + 'onclick="' + accao + '">' + ico.ico(icone) + '</button>';
  }

  /**
   * O estado vazio.
   *
   * Nunca é só «não há nada»: diz o que falta, porquê, e — quando existe —
   * dá o gesto que o resolve. Um vazio por causa de um filtro tem de o
   * confessar, senão lê-se como «está tudo vazio» e a pessoa vai criar o que
   * já lá está.
   */
  function vazio(icone, titulo, texto, botao) {
    // O texto vai num <p> seu: sem ele, o botão é um irmão inline do texto e
    // encosta-se ao fim da última linha em vez de ficar por baixo, centrado.
    // Só se via com frases longas — que são precisamente as que explicam bem.
    return '<div class="b-vazio">' + ico.ico(icone)
      + '<b>' + esc(titulo) + '</b>'
      + '<p>' + esc(texto) + '</p>' + (botao || '') + '</div>';
  }

  /**
   * A miniatura de uma bebida: a fotografia, ou o copo da gaveta a traço.
   *
   * Sem fotografia havia um quadrado de cor cheia com a inicial lá dentro.
   * A chapa diz mais — aquilo é uma cerveja — e pesa muito menos.
   */
  function foto(i) {
    if (i.foto) {
      // foto_pos é o enquadramento que o casal escolheu na montagem: numa
      // garrafa alta, o centro automático corta-lhe o rótulo.
      return '<div class="b-foto"><img src="' + esc(i.foto) + '" alt=""'
        + (i.foto_pos ? ' style="object-position:' + esc(i.foto_pos) + '"' : '')
        + ' loading="lazy"></div>';
    }
    // A classe diz a quem a desenha que ali não há fotografia — e há sítios
    // (o menu do convidado) onde a chapa sem fotografia deve ser uma faixa e
    // não um painel, para não empurrar o menu para fora do ecrã.
    return '<div class="b-foto sem-foto"><span class="b-chapa" style="--tinta:'
      + esc(i.categoria_cor || 'var(--gold-soft)') + '">'
      + ico.copo(i.nome, i.categoria) + '</span></div>';
  }

  /* ---- o estado de um pedido, em forma -------------------------
     Cor, palavra e FORMA: a cor sozinha não chega a quem não a distingue, e
     numa fila de trinta pedidos o desenho é o que se lê primeiro (§25.7). */
  var SINAIS = { em_analise: 'analise', aprovado: 'relogio', a_caminho: 'seta',
                 entregue: 'visto', recusado: 'traco', falhou: 'volta',
                 cancelado: 'xis' };
  function sinal(estado) { return ico.ico(SINAIS[estado] || 'relogio'); }

  /** A pastilha inteira de um estado, para quem só tem o pedido à mão. */
  function estado(e, nome) {
    return '<span class="b-est ' + esc(e) + '">' + sinal(e) + esc(nome || e) + '</span>';
  }

  /**
   * Há quanto tempo, em português curto — «agora mesmo», «há 7 min», «há 1h05».
   *
   * O desvio é a diferença entre o relógio do servidor e o deste aparelho: o
   * tablet da copa pode estar meia hora ao lado, e um «há 40 min» falso faz
   * aprovar à pressa o que não era urgente.
   */
  function ha(quando, desvio) {
    if (!quando) return '';
    var t = Date.parse(String(quando).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var s = Math.max(0, Math.round((Date.now() + (desvio || 0) - t) / 1000));
    if (s < 60) return 'agora mesmo';
    var m = Math.round(s / 60);
    if (m < 60) return 'há ' + m + ' min';
    return 'há ' + Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0');
  }

  /* ---- para onde vai a bebida, quando é o pessoal a lançar -------
     O garçom e o copeiro lançam pedidos por quem está à mesa, e até aqui a
     entrega ia sempre para a mesa MARCADA da pessoa — a que ela tem na planta.
     Numa festa isso é verdade durante a primeira hora: depois as pessoas
     levantam-se, juntam-se noutra mesa, ficam no jardim. Quem lança o pedido
     está a olhar para o sítio onde a pessoa está, e é esse sítio que tem de
     poder dizer.

     A escolha começa em «a mesa da pessoa»: é o que estava a acontecer antes e
     é o caso normal, e assim ninguém tem de responder a uma pergunta a mais
     por cada pedido. As duas janelas usam a mesma peça — são o mesmo gesto em
     dois postos, e duas cópias acabavam a divergir numa delas. */
  var MESAS = null;
  async function mesasDoBar() {
    if (MESAS) return MESAS;
    var d = await window.api('bar_mesas', { method: 'GET', silencioso: true });
    MESAS = (d && d.success) ? (d.mesas || []) : [];
    return MESAS;
  }

  /** O campo «Entregar em», já com as mesas todas e a procura por dentro. */
  function campoMesa(mesas) {
    return { id: 'mesa', rot: 'Entregar em', tipo: 'escolha', valor: '',
             procura: true, dicaProcura: 'Nome ou número da mesa', largura: 3,
             opcoes: [{ v: '', r: 'Onde a pessoa está sentada' },
                      { v: '0', r: 'Sem mesa — fica ao balcão' }]
                     .concat((mesas || []).map(function (m) {
                       return { v: String(m.id), r: m.nome };
                     })),
             dica: 'Mude se a pessoa não estiver no lugar dela — quem entrega '
                 + 'procura-a onde aqui disser.' };
  }

  /**
   * A mesa que sai do campo: a escolhida, ou a da pessoa quando não se escolheu.
   * Devolve 0 para «ao balcão», que é o que o servidor lê como «sem mesa».
   */
  function mesaEscolhida(valor, pessoa) {
    if (valor === '' || valor === undefined || valor === null) {
      return (pessoa && pessoa.mesa_id) ? parseInt(pessoa.mesa_id, 10) : 0;
    }
    return parseInt(valor, 10) || 0;
  }

  window.BP = {
    esc: esc, apo: apo, toast: toast, chave: chave,
    campoBusca: campoBusca, ligarBusca: ligarBusca,
    pilula: pilula, btIco: btIco, vazio: vazio,
    foto: foto, sinal: sinal, estado: estado, ha: ha,
    mesasDoBar: mesasDoBar, campoMesa: campoMesa, mesaEscolhida: mesaEscolhida
  };
  // As páginas do bar chamam toast() à seca, como o resto da casa.
  window.toast = toast;
})();
