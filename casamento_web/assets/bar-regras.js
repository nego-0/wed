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

  /* ---- os quatro grupos de limites ------------------------------
     A ordem é a do alcance: o que trava toda a gente primeiro, o que trava uma
     pessoa por último. Quem abre este painel a meio de uma festa quer saber o
     que está a travar a sala, e não o que está a travar o senhor da mesa 4. */
  var GRUPOS = [
    ['casa',   'O caudal da copa', 'raio',
     'Quantas bebidas a copa serve por período, seja quem for que peça. '
   + 'É o travão da COZINHA, não o de ninguém: quando pega, a espera sobe '
   + 'para todos ao mesmo tempo e o convidado lê que a copa está a dar vazão.'],
    ['todos',  'Para toda a gente', 'pessoas',
     'O que vale para cada convidado, um a um. «2 bebidas a cada 20 min» é '
   + 'duas por pessoa, e não duas na sala.'],
    ['convite', 'Para um convite', 'mesa',
     'A mesma conta, partilhada pela família toda do convite.'],
    ['pessoa', 'Para uma pessoa', 'pessoa',
     'A extensão: a mesma regra, com nome. É a que se põe a meio da noite, com '
   + 'a pessoa à frente — e a que se abre clicando no nome dela na fila.']
  ];

  /** Em que grupo cai uma regra. */
  function grupoDe(r) {
    if (r.sujeito === 'casa') return 'casa';
    if (r.alvo_convidado_id) return 'pessoa';
    if (r.alvo_convite_id) return 'convite';
    return 'todos';
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
      + '</div>';
    pintar();
  }

  /** Repintar tudo a partir da última leitura. */
  function pintar() {
    if (!ctx || !document.getElementById('br-defs')) return;
    pintarDefs();
    pintarLimites();
    pintarMotivos();
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
      else if (d[2] === 'escolha') { lido = IP_MODOS[v] || IP_MODOS.registo; }
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
    var todas = EST().regras || [];
    if (!todas.length) {
      cx.innerHTML = vazio('relogio', 'Sem limites nenhuns',
        'O bar serve o que houver, a quem pedir, à velocidade a que pedirem. '
      + 'É uma escolha legítima — e é a que está feita enquanto isto estiver vazio.',
        PODE() ? '<button class="btn btn-ouro" onclick="barRegraNova()">'
               + ico.ico('mais') + 'Primeira regra</button>' : '');
      return;
    }
    var q = chave(VER.buscaLim || '');
    var lista = q ? todas.filter(function (r) {
      return chave(r.frase + ' ' + r.quem + ' ' + (r.nota || '')).indexOf(q) >= 0;
    }) : todas;
    if (!lista.length) {
      cx.innerHTML = vazio('procurar', 'Nada com esse nome',
                           'São ' + todas.length + ' regras.');
      return;
    }
    cx.innerHTML = GRUPOS.map(function (g) {
      var dele = lista.filter(function (r) { return grupoDe(r) === g[0]; });
      if (!dele.length) return '';
      return '<div class="b-grupo">'
        + '<div class="b-grupo-t">' + ico.ico(g[2]) + '<b>' + esc(g[1]) + '</b>'
        +   '<span class="n">' + dele.length + '</span></div>'
        + '<p class="b-grupo-d">' + esc(g[3]) + '</p>'
        + dele.map(linhaRegra).join('')
        + '</div>';
    }).join('');
  }

  /** Uma regra, como se lê. A frase vem do servidor: duas gramáticas para a
      mesma regra é como se chega a um ecrã que diz uma coisa e a um servidor
      que faz outra. */
  function linhaRegra(r) {
    var espera = r.vigor === 'ainda' ? ' <small class="espera">ainda não são horas</small>'
               : r.vigor === 'passou' ? ' <small class="espera">já passou a hora</small>' : '';
    return '<div class="b-reg' + (r.vigor === 'agora' ? '' : ' espera') + '">'
      + '<span class="txt"><b>' + esc(r.quem) + '</b>' + espera
      +   '<span class="fr">' + esc(r.frase) + '</span>'
      +   (r.mensagem ? '<small>lê: «' + esc(r.mensagem) + '»</small>' : '')
      +   (r.nota ? '<small class="so-nos">' + ico.ico('olho') + esc(r.nota) + '</small>' : '')
      + '</span>'
      + (PODE() ? btIco('lixo', 'Levantar esta regra',
                        'barRegraFora(' + r.id + ')', 'perigo') : '')
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
          + 'como se procura um nome, e o que fazer com dois telemóveis na mesma rede.',
      campos: [
        { id: 'bar.mensagem_fechado', rot: 'O que dizer quando está fechado', tipo: 'area',
          valor: f['bar.mensagem_fechado'] || '', largura: 3, linhas: 2,
          dica: 'Vazio, o menu diz só que ainda não abriu.' },
        { id: 'bar.procura_min', rot: 'Letras para procurar', tipo: 'numero',
          valor: parseInt(f['bar.procura_min'] || '4', 10), min: 1, max: 10 },
        { id: 'bar.ip_modo', rot: 'Pedidos da mesma rede', tipo: 'escolha',
          valor: f['bar.ip_modo'] || 'registo',
          opcoes: Object.keys(IP_MODOS).map(function (k) { return { v: k, r: IP_MODOS[k] }; }),
          dica: 'Num salão com um wi-fi só, «estrito» tranca a festa inteira.' },
        { id: 'bar.trocar_nome', rot: 'Trocar de nome no mesmo telemóvel', tipo: 'sim',
          valor: f['bar.trocar_nome'] === '1', aoLado: 'Deixar, avisando a copa' }
      ],
      aoGuardar: async function (v) {
        var d = await window.api('bar_defs', { method: 'POST', body: JSON.stringify({
          'bar.mensagem_fechado': v['bar.mensagem_fechado'],
          'bar.procura_min': String(v['bar.procura_min'] || 4),
          'bar.ip_modo': v['bar.ip_modo'],
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
   * A janela de uma regra. É UMA, e é esta.
   *
   * `pre` pré-preenche o «a quem»: a ficha de uma pessoa abre-a já com o nome
   * dela escolhido, e por isso a regra individual deixou de ser um formulário
   * à parte — é esta janela, com um campo já respondido.
   */
  window.barRegraNova = async function (pre) {
    pre = pre || {};
    var est = EST();
    var itens = (est.itens || []).map(function (i) { return { v: 'i' + i.id, r: i.nome }; });
    var cats  = (est.categorias || []).map(function (c) { return { v: 'c' + c.id, r: c.nome }; });
    var sobre = [{ v: 'tudo', r: 'qualquer bebida' }].concat(cats, itens);

    // A lista de convidados só se pede se for precisa — e pede-se inteira, que
    // é o que a escolha com procura quer: filtra por dentro, sem voltar ao
    // servidor a cada letra.
    var pessoas = [];
    var d = await window.api('bar_procurar_pessoal&limite=500', { method: 'GET', silencioso: true });
    if (d && d.success) {
      pessoas = (d.nomes || []).map(function (n) {
        return { v: String(n.id), r: n.nome + (n.mesa ? ' · ' + n.mesa : '') };
      });
    }

    var quem = pre.convidado_id ? 'pessoa' : 'todos';
    var aQuem = [
      { v: 'todos',  r: 'Toda a gente (por convidado)' },
      { v: 'casa',   r: 'A copa — o caudal da casa' },
      { v: 'pessoa', r: 'Uma pessoa' }
    ];

    licFormulario({
      titulo: pre.nome ? 'Regra para ' + pre.nome : 'Regra do bar',
      guardar: 'Pôr a regra',
      largo: true,
      dica: '<b>0</b> proíbe. <b>N</b> sem intervalo é um tecto para a noite. '
          + '<b>N</b> com intervalo é «N de cada vez».',
      campos: [
        { id: 'quem', rot: 'A quem', tipo: 'escolha', valor: quem, opcoes: aQuem,
          procura: false,
          dica: 'O caudal da copa conta a sala toda; os outros contam por pessoa.' },
        { id: 'pessoa', rot: 'Qual pessoa', tipo: 'escolha',
          valor: pre.convidado_id ? String(pre.convidado_id) : (pessoas[0] || {}).v,
          opcoes: pessoas.length ? pessoas : [{ v: '', r: 'Ninguém na lista' }],
          procura: true, dicaProcura: 'Escreva parte do nome',
          dica: 'Só conta quando «a quem» for «uma pessoa».' },
        { id: 'sobre', rot: 'Sobre o quê', tipo: 'escolha', valor: pre.sobre || 'tudo',
          opcoes: sobre, procura: true, dicaProcura: 'Bebida ou gaveta' },
        { id: 'conta', rot: 'Contado em', tipo: 'escolha', valor: 'bebidas',
          procura: false,
          opcoes: [{ v: 'bebidas', r: 'Bebidas' }, { v: 'pedidos', r: 'Pedidos' }],
          dica: '«Pedidos» trava o acto de pedir, e só se faz sobre qualquer bebida.' },
        { id: 'quantidade', rot: 'No máximo', tipo: 'numero', valor: 2, min: 0, max: 99,
          dica: '0 = não pode pedir isto.' },
        { id: 'janela_min', rot: 'A cada (minutos)', tipo: 'numero', valor: 0, min: 0, max: 1440,
          dica: '0 = é um tecto para a noite inteira.' },
        { id: 'vigora_hora', rot: 'A partir das', tipo: 'hora', valor: '',
          dica: 'Vazio, vale já.' },
        { id: 'expira_hora', rot: 'Até às', tipo: 'hora', valor: '',
          dica: 'Vazio, vale até ao fim. Uma hora já passada é a madrugada seguinte.' },
        { id: 'mensagem', rot: 'O que ele lê', tipo: 'text', valor: '', largura: 3,
          dica: 'Vazio, lê o texto de sempre. Nunca lê a nota.' },
        { id: 'nota', rot: 'Porquê (só nós vemos)', tipo: 'text', valor: '', largura: 3,
          dica: 'Ex.: «pediu-nos para o travarmos», «conduz».' }
      ],
      // «Qual pessoa» só faz sentido quando a regra é de uma pessoa, e «sobre o
      // quê» só faz sentido quando se contam bebidas — contar PEDIDOS de uma
      // bebida é contar bebidas por outro nome, e o servidor recusa-o. Em vez
      // de deixar a pessoa escrever uma regra impossível e só depois lhe dizer
      // que não, os campos aparecem e desaparecem com a resposta que os torna
      // relevantes.
      aoMontar: function (f) {
        var quemEl  = f.campo('quem');
        var contaEl = f.campo('conta');
        var ajustar = function () {
          f.mostrar('pessoa', quemEl.value === 'pessoa');
          f.mostrar('sobre',  contaEl.value !== 'pedidos');
        };
        quemEl.addEventListener('change', ajustar);
        contaEl.addEventListener('change', ajustar);
        ajustar();
      },
      aoGuardar: async function (v) {
        var escopo = 'tudo', alvo = 0;
        if (v.conta === 'pedidos') v.sobre = 'tudo';
        if (v.sobre.charAt(0) === 'i') { escopo = 'item'; alvo = parseInt(v.sobre.slice(1), 10); }
        else if (v.sobre.charAt(0) === 'c') { escopo = 'categoria'; alvo = parseInt(v.sobre.slice(1), 10); }
        if (v.conta === 'pedidos' && escopo !== 'tudo') {
          licJanelaErro('Contar pedidos só se faz sobre qualquer bebida: contar '
                      + 'pedidos de UMA bebida é contar bebidas por outro nome.');
          return false;
        }
        var pessoaId = 0;
        if (v.quem === 'pessoa') {
          pessoaId = parseInt(v.pessoa, 10) || 0;
          if (!pessoaId) { licJanelaErro('Escolha a pessoa.'); return false; }
        }
        var r = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify({
          escopo: escopo, alvo_id: alvo,
          sujeito: v.quem === 'casa' ? 'casa' : 'convidado',
          alvo_convidado_id: pessoaId,
          unidade: v.conta,
          quantidade: parseInt(v.quantidade, 10) || 0,
          janela_min: parseInt(v.janela_min, 10) || 0,
          vigora_hora: v.vigora_hora, expira_hora: v.expira_hora,
          mensagem: v.mensagem, nota: v.nota
        }) });
        if (!r || !r.success) return false;
        toast('Regra posta. Vale já.');
        await refrescar();
        if (typeof window.barRegraPosta === 'function') window.barRegraPosta(r, pre);
        return true;
      }
    });
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

  window.BR = { ligar: ligar, montar: montar, pintar: pintar, DEFINICOES: DEFINICOES, IP_MODOS: IP_MODOS };
})();
