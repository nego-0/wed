/* ============================================================
   bar-regras.js — As Regras do Bar, num sítio só

   Este painel existe porque o módulo tinha as suas regras espalhadas por três
   sítios que não falavam uns com os outros:

     • as DEFINIÇÕES («como o bar se porta») viviam na montagem, em bar.php;
     • os MOTIVOS DE RECUSA, ao lado delas;
     • e os LIMITES — os tectos e os intervalos, que é o que mesmo trava um
       pedido — não tinham ecrã nenhum. Só se punham pela ficha de uma pessoa,
       na copa, e por isso só existiam por pessoa. As regras da casa inteira
       podiam existir na base de dados (a API sempre as soube guardar) mas não
       havia por onde as escrever.

   O resultado era o que se via de fora: o convidado esbarrava num intervalo
   que ninguém tinha posto num ecrã, e a copa mostrava outro. Duas telas a
   dizer coisas diferentes sobre a mesma noite.

   Agora é um painel só, e o MESMO painel: bar.php monta-o no seu separador,
   copa.php monta-o no dele. Não são duas cópias que se parecem — é o mesmo
   ficheiro chamado de dois sítios, que é a única maneira de não voltarem a
   divergir.

   As regras de pessoa continuam a existir, e continuam a abrir-se clicando no
   nome na fila. Mas passaram a ser o que sempre foram: uma EXTENSÃO das regras
   da casa — a mesma janela, com o «a quem» preenchido.
   ============================================================ */
(function () {
  'use strict';
  var ico = window.ICO, BP = window.BP;
  var esc = BP.esc, toast = BP.toast, chave = BP.chave, vazio = BP.vazio;
  var campoBusca = BP.campoBusca, ligarBusca = BP.ligarBusca, btIco = BP.btIco;

  /* ---- as definições, e o que cada uma quer dizer ---------------
     Estavam em bar-montagem.js. Vieram para aqui com o painel: quem lê o
     painel encontra aqui tudo o que ele mostra. */
  var DEFINICOES = [
    ['bar.mensagem_fechado', 'O que dizer quando está fechado', 'area',
     'Aparece no menu do convidado enquanto a copa não abre. '
   + 'Ex.: «O bar abre depois do brinde, por volta das 21h.»'],
    ['bar.procura_min', 'Letras para procurar o nome', 'numero',
     'Quantas letras o convidado escreve antes de a lista aparecer. '
   + 'Menos letras, mais nomes de cada vez.'],
    ['bar.trocar_nome', 'Trocar de nome no mesmo telemóvel', 'sim',
     'Um telemóvel por pessoa é a regra. Isto abre a excepção — e avisa a copa '
   + 'sempre que acontece.']
  ];

  /* ---- as duas famílias de regras -------------------------------
     A separação é a que se faz em voz alta ao explicar o bar a alguém:

     • as GERAIS são da COPA. Dizem o que a cozinha aguenta, seja quem for que
       peça, e quando pegam a espera sobe para toda a gente ao mesmo tempo.
       Não são de ninguém, e por isso o convidado que esbarra numa delas lê que
       a copa está a dar vazão — não que pediu de mais.

     • as ESPECÍFICAS são de alguma coisa: de uma bebida, do acto de pedir, de
       uma pessoa. Essas sim contam por convidado.

     Andaram muito tempo misturadas numa lista só, ordenada por «alcance», e o
     que isso dava era um painel onde o caudal da copa aparecia ao lado de «o
     senhor da mesa 4 não pode destilados» como se fossem a mesma espécie de
     coisa. São duas conversas diferentes e passaram a ter dois sítios. */
  var FAMILIAS = [
    { id: 'gerais', rot: 'Regras gerais', icone: 'raio',
      dica: 'São da COPA, e valem para a sala toda. Dizem o que a cozinha '
          + 'aguenta por período, seja quem for que peça — quando pegam, a '
          + 'espera sobe para todos ao mesmo tempo.',
      grupos: [
        ['g-bebidas', 'O caudal de bebidas',
         'Quantas bebidas a copa serve por período. É o travão do que sai.'],
        ['g-pedidos', 'O caudal de pedidos',
         'Quantos pedidos a copa aceita por período. É o travão do que entra — '
       + 'trava o gesto de pedir, e não uma bebida em particular.']
      ] },
    { id: 'especificas', rot: 'Regras específicas', icone: 'filtro',
      dica: 'São de alguma coisa — de uma bebida, do acto de pedir, de uma '
          + 'pessoa — e contam sempre POR CONVIDADO. «2 a cada 20 min» é duas '
          + 'por pessoa, e não duas na sala.',
      grupos: [
        ['e-bebida', 'De uma bebida ou gaveta',
         'O tecto ou o intervalo de uma bebida, ou de uma gaveta inteira.'],
        ['e-pedidos', 'Do acto de pedir',
         'Quantos pedidos cada convidado pode fazer por período, seja o que for '
       + 'que peça.'],
        ['e-quem', 'De uma pessoa ou de um convite',
         'A mesma regra, com nome. É a que se põe a meio da noite, com a pessoa '
       + 'à frente — e a que se abre clicando no nome dela na fila.'],
        ['e-todos', 'De toda a gente',
         'O que vale para cada convidado, um a um, sobre qualquer bebida.']
      ] }
  ];

  /** A que família pertence uma regra. */
  function familiaDe(r) { return r.sujeito === 'casa' ? 'gerais' : 'especificas'; }

  /** E em que grupo, dentro dela. */
  function grupoDe(r) {
    if (r.sujeito === 'casa') return r.unidade === 'pedidos' ? 'g-pedidos' : 'g-bebidas';
    if (r.alvo_convidado_id || r.alvo_convite_id) return 'e-quem';
    if (r.escopo !== 'tudo') return 'e-bebida';
    if (r.unidade === 'pedidos') return 'e-pedidos';
    return 'e-todos';
  }

  /* ---- o contexto: quem nos montou, e onde ----------------------
     Guarda-se para o painel se repintar sozinho depois de guardar uma regra,
     sem a página que o hospeda ter de saber quando. */
  var ctx = null;

  /**
   * Ligar o módulo à página. Chama-se UMA vez, ao arrancar, e não quando o
   * painel se desenha.
   *
   * A distinção não é cosmética: a janela de uma regra (barRegraNova) precisa
   * da lista de bebidas e das gavetas, e essas vêm daqui. Enquanto isto vivia
   * dentro de `montar`, abrir a regra de uma pessoa pela ficha SEM ter passado
   * antes pelo separador das regras dava um «Sobre o quê» vazio — o módulo
   * ainda não sabia quem o estava a usar.
   *
   * opc = { estado: () => EST (a última leitura de bar_estado),
   *         pode: bool (falso no modo de leitura),
   *         recarregar: () => Promise (voltar a ler o servidor) }
   */
  function ligar(opc) { ctx = opc; }

  /** Desenhar o painel dentro de um elemento. */
  function montar(opc) {
    if (opc && (opc.estado || opc.recarregar)) ligar(opc);
    var cx = document.getElementById(opc.alvo);
    if (!cx) return;
    cx.innerHTML =
        '<div class="b-regras">'
      +   '<section class="b-cartao">'
      +     '<div class="b-sec">' + ico.ico('trancado') + 'Como o bar se porta</div>'
      +     '<div id="br-defs"></div>'
      +   '</section>'
      +   '<section class="b-cartao">'
      +     '<div class="b-sec">' + ico.ico('relogio') + 'Limites e intervalos</div>'
      +     '<p class="b-nota">Um número e um tempo dizem tudo: <b>0</b> proíbe; '
      +       '<b>N</b> sem intervalo é um tecto para a noite inteira; '
      +       '<b>N</b> com intervalo é «N de cada vez». '
      +       'É daqui que saem os travões que o convidado vê no telemóvel.</p>'
      +     '<div id="br-fer-lim"></div>'
      +     '<div id="br-limites"></div>'
      +   '</section>'
      +   '<section class="b-cartao">'
      +     '<div class="b-sec">' + ico.ico('nota') + 'Motivos de recusa</div>'
      +     '<p class="b-nota">A lista que o copeiro escolhe ao recusar ou ao '
      +       'cortar um pedido. Ele pode sempre escrever outro à mão — isto é '
      +       'para não ter de o fazer vinte vezes por noite.</p>'
      +     '<div id="br-fer-mot"></div>'
      +     '<div id="br-motivos"></div>'
      +   '</section>'
      +   '<section class="b-cartao">'
      +     '<div class="b-sec">' + ico.ico('chavena') + 'O que o bar diz aos convidados</div>'
      +     '<p class="b-nota">Estas frases são a voz da <b>vossa</b> festa, e não '
      +       'a da aplicação. Deixem em branco as que estiverem bem como estão — '
      +       'em branco vale o texto de fábrica, que está escrito por baixo de '
      +       'cada caixa.</p>'
      +     '<div id="br-mensagens"></div>'
      +   '</section>'
      + '</div>';
    pintar();
  }

  /** Repintar tudo a partir da última leitura. */
  function pintar() {
    if (!ctx || !document.getElementById('br-defs')) return;
    pintarDefs();
    pintarLimites();
    pintarMotivos();
    pintarMensagens();
  }

  function EST() { return (ctx && ctx.estado && ctx.estado()) || {}; }
  function PODE() { return !!(ctx && ctx.pode); }

  // ---- como o bar se porta ---------------------------------------
  function pintarDefs() {
    var f = EST().defs || {};
    document.getElementById('br-defs').innerHTML = DEFINICOES.map(function (d) {
      var v = f[d[0]];
      var lido, fraco = false;
      if (d[2] === 'sim')          { lido = v === '1' ? 'Sim' : 'Não'; fraco = v !== '1'; }
      else if (d[0] === 'bar.procura_min') { lido = (v || '4') + ' letras'; }
      else { lido = v ? '«' + v + '»' : 'sem texto'; fraco = !v; }
      return '<div class="b-def"><span class="txt"><b>' + esc(d[1]) + '</b>'
        + '<small>' + esc(d[3]) + '</small></span>'
        + '<span class="val' + (fraco ? ' nao' : '') + '">' + esc(lido) + '</span></div>';
    }).join('')
      + (PODE() ? '<div style="margin-top:1rem"><button class="btn btn-ouro" '
                + 'onclick="barRegrasEditar()">' + ico.ico('lapis')
                + 'Mudar as regras</button></div>' : '');
  }

  // ---- limites e intervalos ---------------------------------------
  function pintarLimites() {
    var cx = document.getElementById('br-limites');
    var fer = document.getElementById('br-fer-lim');
    if (fer && !document.getElementById('q-lim')) {
      fer.innerHTML = campoBusca('q-lim', 'Procurar uma regra', VER.buscaLim)
        + (PODE() ? '<button class="btn btn-ouro" onclick="barRegraNova()">'
                  + ico.ico('mais') + 'Regra nova</button>' : '');
      ligarBusca('q-lim', function (v) { VER.buscaLim = v; pintarLimites(); });
    }
    // As duas caixas aparecem SEMPRE, cheias ou vazias. Um painel que só
    // mostra a estrutura depois de já haver regras obriga quem chega a
    // descobrir que ela existe — e a distinção entre o que trava a copa e o
    // que trava um convidado é justamente o que é preciso perceber ANTES de
    // escrever a primeira.
    var todas = EST().regras || [];
    var q = chave(VER.buscaLim || '');
    var lista = q ? todas.filter(function (r) {
      return chave(r.frase + ' ' + r.quem + ' ' + (r.nota || '')).indexOf(q) >= 0;
    }) : todas;
    if (todas.length && !lista.length) {
      cx.innerHTML = vazio('procurar', 'Nada com esse nome',
                           'São ' + todas.length + ' regras.');
      return;
    }
    cx.innerHTML = FAMILIAS.map(function (fam) {
      var dela = lista.filter(function (r) { return familiaDe(r) === fam.id; });
      return '<div class="b-fam' + (dela.length ? '' : ' vazia') + '">'
        + '<div class="b-fam-t">' + ico.ico(fam.icone) + '<b>' + esc(fam.rot) + '</b>'
        +   '<span class="n">' + dela.length + '</span></div>'
        + '<p class="b-fam-d">' + esc(fam.dica) + '</p>'
        + (dela.length
            ? fam.grupos.map(function (g) {
                var seus = dela.filter(function (r) { return grupoDe(r) === g[0]; });
                if (!seus.length) return '';
                return '<div class="b-grupo">'
                  + '<div class="b-grupo-t"><b>' + esc(g[1]) + '</b>'
                  +   '<span class="n">' + seus.length + '</span></div>'
                  + '<p class="b-grupo-d">' + esc(g[2]) + '</p>'
                  + seus.map(linhaRegra).join('')
                  + '</div>';
              }).join('')
            : '<p class="b-fam-nada">Nenhuma. ' + (fam.id === 'gerais'
                ? 'A copa serve à velocidade a que lhe pedirem.'
                : 'Cada convidado pede o que quiser, à hora que quiser.') + '</p>')
        + '</div>';
    }).join('');
  }

  /** Uma regra, como se lê. A frase vem do servidor: duas gramáticas para a
      mesma regra é como se chega a um ecrã que diz uma coisa e a um servidor
      que faz outra. */
  /** O que a regra faz ao ser passada, numa pastilha. Só aparece quando NÃO
      trava: «trava» é o que toda a gente assume ao ler uma regra, e uma
      pastilha em todas as linhas era ruído por cima da que interessa — a que
      diz que aquela regra, afinal, não recusa bebida nenhuma. */
  var MODOS = { sugere:   ['propõe',        'não recusa: propõe à copa o que fazer'],
                confirma: ['pede resposta', 'não recusa: espera uma resposta da copa'],
                avisa:    ['só avisa',      'não recusa: dá a notícia à copa'] };
  function pastilhaModo(r) {
    var m = MODOS[r.modo];
    return m ? ' <small class="modo" title="' + esc(m[1]) + '">' + esc(m[0]) + '</small>' : '';
  }

  function linhaRegra(r) {
    var espera = r.vigor === 'ainda' ? ' <small class="espera">ainda não são horas</small>'
               : r.vigor === 'passou' ? ' <small class="espera">já passou a hora</small>' : '';
    return '<div class="b-reg' + (r.vigor === 'agora' ? '' : ' espera') + '">'
      + '<span class="txt"><b>' + esc(r.quem) + '</b>' + espera
      +   '<span class="fr">' + esc(r.frase) + pastilhaModo(r) + '</span>'
      +   (r.mensagem ? '<small>lê: «' + esc(r.mensagem) + '»</small>' : '')
      +   (r.nota ? '<small class="so-nos">' + ico.ico('olho') + esc(r.nota) + '</small>' : '')
      + '</span>'
      + (PODE() ? '<span class="b-reg-bt">'
                + btIco('lapis', 'Editar esta regra', 'barRegraEditar(' + r.id + ')')
                + btIco('lixo', 'Levantar esta regra',
                        'barRegraFora(' + r.id + ')', 'perigo') + '</span>' : '')
      + '</div>';
  }

  // ---- motivos de recusa ------------------------------------------
  function pintarMotivos() {
    var cx = document.getElementById('br-motivos');
    var fer = document.getElementById('br-fer-mot');
    if (fer && !document.getElementById('q-mot') && PODE()) {
      fer.innerHTML = campoBusca('q-mot', 'Procurar um motivo', VER.buscaMot)
        + '<button class="btn btn-ouro" onclick="barMotivoNovo()">'
        + ico.ico('mais') + 'Motivo</button>';
      ligarBusca('q-mot', function (v) { VER.buscaMot = v; pintarMotivos(); });
    }
    var todos = EST().motivos || [];
    if (!todos.length) {
      cx.innerHTML = vazio('nota', 'Ainda sem motivos guardados',
        'Sem lista, o copeiro escreve tudo à mão — o que a meio da noite '
      + 'quer dizer que escreve pouco.',
        PODE() ? '<button class="btn btn-ouro" onclick="barMotivoNovo()">'
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
        + (PODE() ? btIco('lixo', 'Tirar este motivo', 'barMotivoApagar(' + m.id + ')', 'perigo') : '')
        + '</div>';
    }).join('');
  }

  /* ---- o que o bar diz aos convidados ----------------------------
     A voz da festa, e não a da aplicação. Cada situação tem uma caixa; em
     branco vale o texto de fábrica, que fica escrito por baixo — quem escreve
     a sua vê exactamente o que está a substituir, e quem não quer mexer não
     tem de preencher nada.

     As situações, as variáveis e os textos de fábrica vêm todos do SERVIDOR
     (bar_estado): é ele quem troca as variáveis e quem escolhe o texto, e duas
     listas — uma de cada lado — acabavam a discordar uma da outra. */
  function pintarMensagens() {
    var cx = document.getElementById('br-mensagens');
    if (!cx) return;
    var e = EST();
    var sits = e.situacoes || {};
    var fab  = e.fabrica || {};
    var msgs = e.mensagens || {};
    var vars = e.variaveis || {};
    var chaves = Object.keys(sits);
    if (!chaves.length) { cx.innerHTML = ''; return; }

    var legenda = Object.keys(vars).map(function (v) {
      return '<code>' + esc(v) + '</code> <small>' + esc(vars[v]) + '</small>';
    }).join(' · ');

    cx.innerHTML = '<p class="b-vars">' + legenda + '</p>'
      + chaves.map(function (k) {
          var posto = msgs[k] || '';
          return '<div class="b-msg' + (posto ? ' posto' : '') + '">'
            + '<label for="msg-' + k + '"><b>' + esc(sits[k]) + '</b>'
            + (posto ? '<span class="sel">vossa</span>' : '') + '</label>'
            + (PODE()
                ? '<textarea id="msg-' + k + '" rows="2" maxlength="240" '
                  + 'placeholder="Em branco: fica como está em baixo">'
                  + esc(posto) + '</textarea>'
                : '<p class="lida">' + esc(posto || fab[k] || '') + '</p>')
            + '<small class="fab">' + esc(fab[k] || '') + '</small>'
            + '</div>';
        }).join('')
      + (PODE() ? '<div style="margin-top:1rem"><button class="btn btn-ouro" '
                + 'onclick="barMensagensGuardar()">' + ico.ico('visto')
                + 'Guardar as frases</button></div>' : '');
  }

  window.barMensagensGuardar = async function () {
    var sits = Object.keys(EST().situacoes || {});
    var env = {};
    sits.forEach(function (k) {
      var el = document.getElementById('msg-' + k);
      if (el) env[k] = el.value;
    });
    var d = await window.api('bar_mensagens_guardar', { method: 'POST',
                                                        body: JSON.stringify(env) });
    if (!d || !d.success) return;
    toast('Guardado. É isto que os convidados vão ler.');
    await refrescar();
  };

  /* ---- o que o painel guarda entre pinturas ---------------------- */
  var VER = { buscaLim: '', buscaMot: '' };

  /* ============================================================
     AS ACÇÕES
     Vivem em window porque os botões as chamam por onclick, como no resto do
     módulo. Cada uma recarrega e repinta — o painel não adivinha.
     ============================================================ */

  async function refrescar() {
    if (ctx && ctx.recarregar) await ctx.recarregar();
    pintar();
  }

  window.barRegrasEditar = function () {
    var f = EST().defs || {};
    licFormulario({
      titulo: 'Como o bar se porta',
      guardar: 'Guardar',
      largo: true,
      dica: 'São as regras que não se contam em bebidas: o que o convidado lê, '
          + 'como se procura um nome, e o que fazer quando um telemóvel muda de mão.',
      campos: [
        { id: 'bar.mensagem_fechado', rot: 'O que dizer quando está fechado', tipo: 'area',
          valor: f['bar.mensagem_fechado'] || '', largura: 3, linhas: 2,
          dica: 'Vazio, o menu diz só que ainda não abriu.' },
        { id: 'bar.procura_min', rot: 'Letras para procurar', tipo: 'numero',
          valor: parseInt(f['bar.procura_min'] || '4', 10), min: 1, max: 10 },
        { id: 'bar.trocar_nome', rot: 'Trocar de nome no mesmo telemóvel', tipo: 'sim',
          valor: f['bar.trocar_nome'] === '1', aoLado: 'Deixar, avisando a copa' }
      ],
      aoGuardar: async function (v) {
        var d = await window.api('bar_defs', { method: 'POST', body: JSON.stringify({
          'bar.mensagem_fechado': v['bar.mensagem_fechado'],
          'bar.procura_min': String(v['bar.procura_min'] || 4),
          'bar.trocar_nome': v['bar.trocar_nome'] ? '1' : '0'
        }) });
        if (!d || !d.success) return false;
        toast('Regras guardadas.');
        await refrescar();
        return true;
      }
    });
  };

  /**
   * A janela de uma regra. É UMA, e é esta — para pôr e para editar.
   *
   * `pre` pré-preenche o que já se souber:
   *   { convidado_id, nome }  — a ficha de uma pessoa abre-a com o nome posto;
   *   { regra }               — editar uma que já existe.
   *
   * O formulário estava denso: dez campos seguidos, e três deles só faziam
   * sentido consoante a resposta de outro. Agora começa pela pergunta que
   * separa as duas conversas — geral ou específica —, esconde o que não vem ao
   * caso, e mostra em cima, por palavras, a frase que a regra vai passar a
   * ser. Quem escreve uma regra a meio de uma festa não devia ter de a
   * imaginar a partir de três números.
   */
  window.barRegraNova = async function (pre) {
    pre = pre || {};
    var r = pre.regra || null;
    var est = EST();
    var itens = (est.itens || []).map(function (i) { return { v: 'i' + i.id, r: i.nome }; });
    var cats  = (est.categorias || []).map(function (c) { return { v: 'c' + c.id, r: c.nome }; });
    var sobre = [{ v: 'tudo', r: 'Qualquer bebida' }].concat(cats, itens);

    // A lista de convidados só se pede quando é precisa, e pede-se inteira:
    // a escolha com procura filtra por dentro, sem voltar ao servidor.
    var pessoas = [];
    var precisaPessoas = !!(pre.convidado_id || (r && r.alvo_convidado_id));
    if (precisaPessoas || !r) {
      var d = await window.api('bar_procurar_pessoal&limite=500',
                               { method: 'GET', silencioso: true });
      if (d && d.success) {
        pessoas = (d.nomes || []).map(function (n) {
          return { v: String(n.id), r: n.nome + (n.mesa ? ' · ' + n.mesa : '') };
        });
      }
    }

    // ---- o que já está escrito, quando se edita --------------------
    var hora = function (t) {
      // «2026-09-08 21:00:00» → «21:00». Vazio fica vazio.
      var m = /(\d{2}):(\d{2})/.exec(String(t || '').slice(10));
      return m ? m[1] + ':' + m[2] : '';
    };
    var familia = r ? (r.sujeito === 'casa' ? 'gerais' : 'especificas') : 'especificas';
    var alcance = 'todos';
    if (pre.convidado_id) alcance = 'pessoa';
    else if (r && r.alvo_convidado_id) alcance = 'pessoa';
    else if (r && r.alvo_convite_id) alcance = 'convite';
    var sobreV = 'tudo';
    if (r && r.escopo === 'item') sobreV = 'i' + r.alvo_id;
    else if (r && r.escopo === 'categoria') sobreV = 'c' + r.alvo_id;
    else if (pre.sobre) sobreV = pre.sobre;

    licFormulario({
      titulo: r ? 'Editar a regra'
            : (pre.nome ? 'Regra para ' + pre.nome : 'Regra nova do bar'),
      guardar: r ? 'Guardar' : 'Pôr a regra',
      largo: true,
      dica: '<div class="b-frase" id="br-frase">…</div>',
      campos: [
        { id: 'familia', rot: 'Que espécie de regra', tipo: 'escolha', valor: familia,
          procura: false, largura: 3,
          opcoes: [
            { v: 'especificas', r: 'Específica — conta por convidado' },
            { v: 'gerais',      r: 'Geral — o caudal da copa, para a sala toda' }
          ],
          dica: 'A geral diz o que a COPA aguenta, seja quem for que peça. '
              + 'A específica conta por pessoa.' },

        { id: 'conta', rot: 'Contar', tipo: 'escolha',
          valor: (r && r.unidade) || 'bebidas', procura: false,
          opcoes: [{ v: 'bebidas', r: 'Bebidas servidas' },
                   { v: 'pedidos', r: 'Pedidos feitos' }],
          dica: '«Pedidos» trava o gesto de pedir, e não uma bebida.' },
        { id: 'sobre', rot: 'De que bebida', tipo: 'escolha', valor: sobreV,
          opcoes: sobre, procura: true, dicaProcura: 'Bebida ou gaveta',
          dica: 'Uma bebida, uma gaveta inteira, ou tudo.' },
        { id: 'alcance', rot: 'A quem se aplica', tipo: 'escolha', valor: alcance,
          procura: false,
          opcoes: [{ v: 'todos',   r: 'A cada convidado' },
                   { v: 'pessoa',  r: 'Só a uma pessoa' },
                   { v: 'convite', r: 'A um convite inteiro' }] },
        { id: 'pessoa', rot: 'Qual pessoa', tipo: 'escolha',
          valor: String(pre.convidado_id || (r && r.alvo_convidado_id) || ''),
          opcoes: pessoas.length ? pessoas : [{ v: '', r: 'Ninguém na lista' }],
          procura: true, dicaProcura: 'Escreva parte do nome' },

        { id: 'quantidade', rot: 'No máximo', tipo: 'numero',
          valor: r ? r.quantidade : 2, min: 0, max: 99,
          dica: '<b>0</b> proíbe por completo.' },
        { id: 'janela_min', rot: 'A cada (minutos)', tipo: 'numero',
          valor: r ? r.janela_min : 0, min: 0, max: 1440,
          dica: '<b>0</b> é um tecto para a noite inteira.' },

        // O que a regra FAZ quando o número é passado. É a escolha mais pesada
        // do formulário — separa uma regra que recusa bebidas de uma que toca a
        // campainha à copa — e por isso está aqui, a seguir aos números que ela
        // manda, e escrita por extenso: «sugere» num select de uma palavra não
        // diz a ninguém que a bebida sai à mesma.
        { id: 'modo', rot: 'Quando o número for passado', tipo: 'escolha',
          valor: (r && r.modo) || 'trava', procura: false, largura: 3,
          opcoes: [
            { v: 'trava',    r: 'Travar — recusa o pedido no momento' },
            { v: 'sugere',   r: 'Sugerir — deixa passar e propõe à copa o que fazer' },
            { v: 'confirma', r: 'Confirmar — deixa passar e espera resposta da copa' },
            { v: 'avisa',    r: 'Avisar — deixa passar e só dá a notícia' }
          ],
          dica: 'Só <b>travar</b> recusa bebidas a alguém. Os outros três deixam '
              + 'o pedido passar e põem um alerta no painel da copa — e o de '
              + '<b>confirmar</b> fica lá até alguém responder, mesmo que a '
              + 'situação passe entretanto.' },

        { id: 'vigora_hora', rot: 'A partir das', tipo: 'hora',
          valor: r ? hora(r.vigora_em) : '', dica: 'Vazio, vale já.' },
        { id: 'expira_hora', rot: 'Até às', tipo: 'hora',
          valor: r ? hora(r.expira_em) : '',
          dica: 'Vazio, vale até ao fim. Uma hora já passada é a madrugada seguinte.' },

        { id: 'mensagem', rot: 'O que o convidado lê', tipo: 'text',
          valor: (r && r.mensagem) || '', largura: 3,
          dica: 'Vazio, lê o texto de sempre. Nunca lê a nota.' },
        { id: 'nota', rot: 'Porquê (só nós vemos)', tipo: 'text',
          valor: (r && r.nota) || '', largura: 3,
          dica: 'Ex.: «pediu-nos para o travarmos», «conduz».' }
      ],

      /* Os campos que só fazem sentido consoante outros, e a frase em cima.
         Em vez de deixar escrever uma regra impossível e só depois dizer que
         não, o que não vem ao caso desaparece — e o que fica lê-se de uma vez
         na linha de cima. */
      aoMontar: function (f) {
        var fam = f.campo('familia'), conta = f.campo('conta');
        var alc = f.campo('alcance'), qtd = f.campo('quantidade');
        var jan = f.campo('janela_min'), sob = f.campo('sobre');
        var pes = f.campo('pessoa'), mod = f.campo('modo');
        var frase = document.getElementById('br-frase');

        var nomeDe = function (sel) {
          var op = sel && sel.closest('.lic-sel');
          var bt = op && op.querySelector('.lic-sel-bt .txt');
          return bt ? bt.textContent : (sel ? sel.value : '');
        };

        var ajustar = function () {
          var geral = fam.value === 'gerais';
          var porPedidos = conta.value === 'pedidos';
          // Uma regra de PEDIDOS não é de bebida nenhuma: contar pedidos de uma
          // bebida é contar bebidas por outro nome, e o servidor recusa-o.
          f.mostrar('sobre', !porPedidos);
          // O caudal da copa é de todos por definição: não tem «a quem».
          f.mostrar('alcance', !geral);
          f.mostrar('pessoa', !geral && alc.value === 'pessoa');
          if (frase) frase.innerHTML = escrever(geral, porPedidos);
        };

        /** O que a regra faz ao ser passada, em meia linha, por baixo da frase.
            Sem isto a frase dizia «no máximo 2 bebidas» com a mesma cara nos
            quatro modos — e em três deles a terceira bebida sai à mesma. */
        var oQueFaz = function () {
          var m = mod ? mod.value : 'trava';
          if (m === 'trava') return '';
          var t = m === 'sugere'   ? 'Não recusa nada: a copa recebe um alerta com uma proposta.'
                : m === 'confirma' ? 'Não recusa nada: a copa recebe um alerta que fica à espera de resposta.'
                :                    'Não recusa nada: a copa fica a saber, e mais nada.';
          return '<small class="b-frase-modo">' + esc(t) + '</small>';
        };

        /** A frase que a regra vai ser, escrita como o servidor a escreveria. */
        var escrever = function (geral, porPedidos) {
          var n = parseInt(qtd.value, 10);
          var m = parseInt(jan.value, 10) || 0;
          var unid = porPedidos ? 'pedido' : 'bebida';
          // «60 bebidas de qualquer bebida» é uma frase a dizer duas vezes a
          // mesma coisa: quando a regra é sobre tudo, o «de quê» cala-se.
          var nomeSob = porPedidos ? '' : (nomeDe(sob) || '');
          var oQue = (!nomeSob || /^qualquer bebida$/i.test(nomeSob))
                   ? '' : ' de ' + nomeSob;
          var quem = geral ? 'A copa'
                   : alc.value === 'pessoa' ? (nomeDe(pes) || 'essa pessoa').split(' · ')[0]
                   : alc.value === 'convite' ? 'Cada convite'
                   : 'Cada convidado';
          if (!(n >= 0)) return '…';
          if (n === 0) {
            // Uma proibição não tem número para se atingir: ou está lá e fecha
            // a porta, ou não está. Nos outros três modos não haveria nada a
            // medir nem nada a propor — a regra ficava escrita a não fazer
            // nada, que é a pior coisa que este painel pode mostrar.
            return '<b>' + esc(quem) + '</b> não pode pedir'
                 + esc(oQue || (porPedidos ? ' nada' : ' bebida nenhuma')) + '.'
                 + (mod && mod.value !== 'trava'
                     ? '<small class="b-frase-modo aviso">Uma proibição só existe '
                       + 'a travar: sem número para passar, não há nada a avisar.</small>'
                     : '');
          }
          var quanto = n + ' ' + unid + (n === 1 ? '' : 's');
          return '<b>' + esc(quem) + '</b>: no máximo <b>' + esc(quanto) + '</b>'
               + esc(oQue) + (m ? ' <b>a cada ' + m + ' min</b>' : ', ao todo')
               + '.' + oQueFaz();
        };

        [fam, conta, alc, qtd, jan, sob, pes, mod].forEach(function (el) {
          if (!el) return;
          el.addEventListener('change', ajustar);
          el.addEventListener('input', ajustar);
        });
        ajustar();
      },

      aoGuardar: async function (v) {
        var geral = v.familia === 'gerais';
        var porPedidos = v.conta === 'pedidos';
        var escopo = 'tudo', alvo = 0;
        if (!porPedidos) {
          if (v.sobre.charAt(0) === 'i') { escopo = 'item'; alvo = parseInt(v.sobre.slice(1), 10); }
          else if (v.sobre.charAt(0) === 'c') { escopo = 'categoria'; alvo = parseInt(v.sobre.slice(1), 10); }
        }
        var pessoaId = 0, conviteId = 0;
        if (!geral && v.alcance === 'pessoa') {
          pessoaId = parseInt(v.pessoa, 10) || 0;
          if (!pessoaId) { licJanelaErro('Escolha a pessoa.'); return false; }
        }
        if (!geral && v.alcance === 'convite') {
          // O convite vem da pessoa escolhida: é a família dela.
          conviteId = (r && r.alvo_convite_id) || 0;
          if (!conviteId) {
            licJanelaErro('Uma regra de convite põe-se pela ficha de alguém desse convite.');
            return false;
          }
        }
        var env = {
          escopo: escopo, alvo_id: alvo,
          sujeito: geral ? 'casa' : 'convidado',
          alvo_convidado_id: pessoaId,
          alvo_convite_id: conviteId,
          unidade: v.conta,
          quantidade: parseInt(v.quantidade, 10) || 0,
          janela_min: parseInt(v.janela_min, 10) || 0,
          vigora_hora: v.vigora_hora, expira_hora: v.expira_hora,
          modo: v.modo,
          mensagem: v.mensagem, nota: v.nota
        };
        if (r) env.id = r.id;
        var d = await window.api('bar_regra_guardar', { method: 'POST',
                                                       body: JSON.stringify(env) });
        if (!d || !d.success) return false;
        toast(r ? 'Regra guardada.' : 'Regra posta. Vale já.');
        await refrescar();
        if (typeof window.barRegraPosta === 'function') window.barRegraPosta(d, pre);
        return true;
      }
    });
  };

  /** Editar uma regra que já existe. A janela é a mesma, preenchida. */
  window.barRegraEditar = function (id) {
    var r = (EST().regras || []).filter(function (x) { return x.id === id; })[0];
    if (!r) { toast('Essa regra já não está aqui.', true); return; }
    window.barRegraNova({ regra: r });
  };

  window.barRegraFora = async function (id) {
    var r = await window.api('bar_regra_apagar', { method: 'POST',
                                                  body: JSON.stringify({ id: id }) });
    if (!r || !r.success) return;
    toast('Regra levantada.');
    await refrescar();
    if (typeof window.barRegraPosta === 'function') window.barRegraPosta(r, {});
  };

  window.barMotivoNovo = function () {
    licFormulario({
      titulo: 'Motivo de recusa',
      guardar: 'Guardar',
      dica: 'O convidado lê isto no telemóvel. Escreva-o como o diria em pessoa — '
          + 'e diga o que HÁ, não só o que falta.',
      campos: [{ id: 'texto', rot: 'O motivo', tipo: 'text', valor: '', largura: 3,
                 dica: 'Ex.: «Esta acabou — temos tinto da casa e espumante.»' }],
      aoGuardar: async function (v) {
        if (!v.texto) { licJanelaErro('Escreva o motivo.'); return false; }
        var d = await window.api('bar_motivo_guardar', { method: 'POST',
          body: JSON.stringify({ texto: v.texto }) });
        if (!d || !d.success) return false;
        toast('Motivo guardado.');
        await refrescar();
        return true;
      }
    });
  };

  window.barMotivoApagar = async function (id) {
    var r = await licConfirmar({
      titulo: 'Tirar este motivo?',
      texto: 'Sai da lista de escolha do copeiro. Os pedidos que já o levaram '
           + 'ficam com ele escrito.',
      confirmar: 'Tirar', perigo: true, icone: 'lixo'
    });
    if (!r || !r.sim) return;
    var d = await window.api('bar_motivo_apagar', { method: 'POST',
                                                   body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Motivo tirado.');
    await refrescar();
  };

  window.BR = { ligar: ligar, montar: montar, pintar: pintar, DEFINICOES: DEFINICOES };
})();
