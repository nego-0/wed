/* ============================================================
   planos.js — A montra dos planos, e a escolha do casal
   ------------------------------------------------------------
   Um só motor para os dois sítios onde se escolhe um plano: a
   inscrição (ainda sem sessão) e a área da licença (a pedir um
   reforço). Ambos precisam do mesmo: desenhar os pacotes, deixar
   montar um plano à peça, somar a conta e recolher a escolha.

   Uso:
     Planos.montar(alvo, catalogo, { tenho, moeda, aoMudar });
     Planos.escolha();   // { pacote, escaloes, meses, total, vazio }

   'tenho' são as concessões que o casamento já tem (chave do
   módulo => {ativo, limite, editar, todos_modelos}): o que já se
   tem aparece marcado e não se volta a cobrar.
   ============================================================ */
(function (global) {
  'use strict';

  var CAT = { modulos: [], pacotes: [] };
  var TENHO = {};
  var MOEDA = 'Kz';
  var AO_MUDAR = null;
  var ALVO = null;
  var COM_PACOTES = true;
  var SECCOES = [];                        // secções de foto do convite digital
  var PRAZOS = [];                         // prazos de licença, com o seu factor
  // 'prazo' são os meses escolhidos; é ele que multiplica todos os preços.
  var sel = { pacote: 0, escaloes: {}, fotos: {}, prazo: 0 };

  // ---------- utilitários ----------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Preço à angolana: 98 000 Kz. Sem cêntimos quando são zero — ninguém
      escreve o preço de um plano com «,00» ao fim. */
  function moeda(v) {
    v = Number(v) || 0;
    var casas = Math.abs(v % 1) > 0.001 ? 2 : 0;
    return v.toLocaleString('pt-PT', { minimumFractionDigits: casas, maximumFractionDigits: casas })
         + ' ' + MOEDA;
  }

  /** O prazo escolhido, ou o primeiro em destaque, ou o primeiro que houver. */
  function prazoAtual() {
    if (!PRAZOS.length) return null;
    for (var i = 0; i < PRAZOS.length; i++) if (PRAZOS[i].meses === sel.prazo) return PRAZOS[i];
    for (var j = 0; j < PRAZOS.length; j++) if (PRAZOS[j].etiqueta) return PRAZOS[j];
    return PRAZOS[0];
  }
  function fator() { var p = prazoAtual(); return p ? p.fator : 1; }

  /** Um preço do preçário (que é do prazo base) no prazo que está escolhido. */
  function comPrazo(v) { return Math.round((Number(v) || 0) * fator() * 100) / 100; }

  function modulo(chave) {
    for (var i = 0; i < CAT.modulos.length; i++) if (CAT.modulos[i].chave === chave) return CAT.modulos[i];
    return null;
  }
  function escalao(id) {
    for (var i = 0; i < CAT.modulos.length; i++) {
      var e = CAT.modulos[i].escaloes;
      for (var j = 0; j < e.length; j++) if (e[j].id === id) return e[j];
    }
    return null;
  }

  /**
   * Como se diz, numa linha, o que um escalão dá.
   *
   * Um módulo simples (mesas, orçamento) não tem medida nenhuma: ou se leva ou
   * não. Devolve vazio, e quem o escreve não põe travessão para nada — «Planta
   * de mesas — Planta de mesas» não diz a ninguém o que não sabia.
   */
  function medida(e) {
    if (e.modulo === 'convidados') return e.limite > 0 ? 'até ' + e.limite + ' convidados' : 'convidados sem limite';
    if (e.modulo === 'impresso' || e.modulo === 'digital') {
      if (e.todos_modelos) return 'todos os modelos, com edição';
      return e.editar ? 'modelo padrão, com edição' : 'modelo padrão, sem edição';
    }
    return '';
  }

  /** Este escalão já está coberto pelo que o casamento tem? */
  function jaTem(e) {
    var t = TENHO[e.modulo];
    if (!t || !t.ativo) return false;
    if (e.modulo === 'convidados') return t.limite === 0 || (e.limite > 0 && t.limite >= e.limite);
    if (e.modulo === 'impresso' || e.modulo === 'digital') {
      return (t.editar ? 1 : 0) >= (e.editar ? 1 : 0)
          && (t.todos_modelos ? 1 : 0) >= (e.todos_modelos ? 1 : 0);
    }
    return true;
  }

  // ---------- desenho ----------
  /**
   * O prazo, primeiro de tudo.
   *
   * O preço depende dele, e por isso pergunta-se antes de mostrar preço nenhum:
   * uma montra que só revela o prazo no fim faz o casal escolher duas vezes.
   */
  function desenharPrazos() {
    if (PRAZOS.length < 2) return '';
    var actual = prazoAtual();
    var h = '<div class="pl-prazos"><div class="pl-prazos-cab">'
      + '<b>Por quanto tempo precisam da plataforma?</b>'
      + '<span>Quanto mais tempo, menos custa cada mês. Os preços abaixo já contam com a escolha.</span>'
      + '</div><div class="pl-prazos-op">';
    PRAZOS.forEach(function (p) {
      var on = actual && p.meses === actual.meses;
      // Quanto custa por mês, em relação ao prazo mais curto: é a conta que
      // mostra que um compromisso maior é mesmo melhor negócio, e não retórica.
      var base = PRAZOS[0];
      var porMes = base ? (p.fator / p.meses) / (base.fator / base.meses) : 1;
      var poupa = Math.round((1 - porMes) * 100);
      h += '<label class="pl-prazo-op' + (on ? ' on' : '') + '">'
        + '<input type="radio" name="pl-prazo" value="' + p.meses + '"' + (on ? ' checked' : '') + '>'
        + '<span>'
        + (p.etiqueta ? '<span class="pl-prazo-fita">' + esc(p.etiqueta) + '</span>' : '')
        + '<span class="pl-prazo-nome">' + esc(p.nome) + '</span>'
        + (p.resumo ? '<span class="pl-prazo-res">' + esc(p.resumo) + '</span>' : '')
        + (poupa > 0 ? '<span class="pl-prazo-poupa">−' + poupa + '% por mês</span>' : '')
        + '</span></label>';
    });
    return h + '</div></div>';
  }

  function desenharPacotes() {
    // Num reforço não se mostram pacotes. Um pacote tem preço fechado e inclui
    // coisas que o casal já tem: cobrá-las outra vez contradiz a promessa de que
    // ele «paga só a diferença». À peça, o que já se tem aparece marcado «já
    // tem» e não entra na conta — que é exactamente a diferença.
    if (!COM_PACOTES) return '';
    var pacs = CAT.pacotes.filter(function (p) { return p.ativo; });
    if (!pacs.length) return '';
    var h = '<div class="pl-pacotes">';
    pacs.forEach(function (p) {
      // O que o pacote inclui, em linguagem de casamento e não de base de dados.
      var linhas = [], cobertos = {};
      p.itens.forEach(function (id) {
        var e = escalao(id);
        if (!e) return;
        cobertos[e.modulo] = true;
        var med = medida(e);
        linhas.push('<li><span>' + esc(e.modulo_nome)
                  + (med ? ' — ' + esc(med) : '') + '</span></li>');
      });
      // E o que não inclui: dizê-lo é honesto, e é o que faz subir de plano.
      CAT.modulos.forEach(function (m) {
        if (!m.ativo || cobertos[m.chave]) return;
        linhas.push('<li class="nao"><span>' + esc(m.nome) + '</span></li>');
      });

      h += '<div class="pl-pac' + (p.destaque ? ' destaque' : '') + (sel.pacote === p.id ? ' on' : '') + '"'
        + ' data-pacote="' + p.id + '" role="button" tabindex="0"'
        + ' aria-pressed="' + (sel.pacote === p.id ? 'true' : 'false') + '">'
        + (p.etiqueta ? '<span class="pl-fita">' + esc(p.etiqueta) + '</span>' : '')
        + '<h3>' + esc(p.nome) + '</h3>'
        + '<p class="pl-promessa">' + esc(p.promessa) + '</p>'
        + '<div class="pl-preco"><b>' + esc(moeda(comPrazo(p.preco)).replace(' ' + MOEDA, '')) + '</b>'
        +   '<span class="moeda">' + esc(MOEDA) + '</span>'
        +   (p.poupanca > 0 ? '<span class="pl-antes">' + esc(moeda(comPrazo(p.avulso))) + '</span>' : '')
        + '</div>'
        + (p.poupanca > 0
            ? '<span class="pl-poupa">Poupa ' + esc(moeda(comPrazo(p.poupanca))) + '</span>' : '')
        + '<div class="pl-prazo">' + (prazoAtual() ? prazoAtual().meses : p.meses)
        +   ' meses de licença</div>'
        + '<ul class="pl-inclui">' + linhas.join('') + '</ul>'
        + '<button type="button" class="btn ' + (sel.pacote === p.id ? 'btn-ouro' : 'btn-linha') + ' pl-escolher">'
        +   (sel.pacote === p.id ? '✓ Escolhido' : 'Escolher ' + esc(p.nome)) + '</button>'
        + '</div>';
    });
    return h + '</div>';
  }

  function desenharMedida() {
    var h = '<div class="pl-medida"><h3>Ou monte o plano à sua medida</h3>'
          + '<div class="dica">Leve só o que precisa. Pode reforçar mais tarde, sem perder nada do que já fez.</div>';
    CAT.modulos.forEach(function (m) {
      if (!m.ativo) return;
      var escs = m.escaloes.filter(function (e) { return e.ativo; });
      if (!escs.length) return;
      h += '<div class="pl-mod" data-chave="' + esc(m.chave) + '"><div class="pl-mod-cab">'
         + '<div class="pl-mod-ico">' + esc(m.icone || '•') + '</div><div>'
         + '<div class="pl-mod-nome">' + esc(m.nome)
         + (m.obrigatorio ? ' <span class="pl-mod-obrig">obrigatório</span>' : '') + '</div>'
         + (m.beneficio ? '<div class="pl-mod-benef">' + esc(m.beneficio) + '</div>' : '')
         + '<div class="pl-mod-resumo">' + esc(m.resumo) + '</div>'
         + '</div></div>';
      // O módulo a trabalhar, em imagem. Descrever por palavras o que se pode
      // MOSTRAR é pedir ao casal um acto de fé — e o que se vende aqui é,
      // literalmente, aquilo que ele vai ver todos os dias.
      if (m.imagem) {
        h += '<figure class="pl-retrato" data-ampliar="' + esc(m.imagem) + '"'
           + ' data-titulo="' + esc(m.nome) + '" role="button" tabindex="0"'
           + ' aria-label="Ver «' + esc(m.nome) + '» em tamanho grande">'
           + '<img src="' + esc(m.imagem) + '" alt="' + esc(m.nome) + ' na plataforma"'
           + ' loading="lazy" decoding="async">'
           + '<figcaption>' + esc(m.nome) + ' — como fica no seu casamento'
           + '<span class="pl-lupa">ampliar</span></figcaption></figure>';
      }
      h += '<div class="pl-escs">';

      // «Não levar» é uma escolha como as outras — excepto num módulo obrigatório,
      // onde não é escolha nenhuma: oferecer uma opção que o servidor vai
      // recusar é convidar ao erro. Nesses, diz-se porquê e passa-se à frente.
      if (m.obrigatorio) {
        h += '<div class="pl-obrig">Incluído em todos os planos — é a base de que o '
           + 'resto depende. Escolha a medida.</div>';
      } else {
        var nada = !sel.escaloes[m.chave];
        h += '<label class="pl-esc' + (nada ? ' on' : '') + '">'
           + '<input type="radio" name="pl-' + esc(m.chave) + '" value="0"' + (nada ? ' checked' : '') + '>'
           + '<span><span class="pl-esc-nome">Não levar</span>'
           + '<span class="pl-esc-res">Fica de fora deste plano.</span></span></label>';
      }

      escs.forEach(function (e) {
        var tem = jaTem(e);
        var on = sel.escaloes[m.chave] === e.id;
        h += '<label class="pl-esc' + (on ? ' on' : '') + (tem ? ' tem' : '') + '">'
           + '<input type="radio" name="pl-' + esc(m.chave) + '" value="' + e.id + '"'
           +   (on ? ' checked' : '') + (tem ? ' disabled' : '') + '>'
           + '<span><span class="pl-esc-nome">' + esc(e.nome) + '</span>'
           + (e.resumo ? '<span class="pl-esc-res">' + esc(e.resumo) + '</span>' : '')
           + (tem ? '<span class="pl-esc-tem">✓ Já tem</span>'
                  : '<span class="pl-esc-preco">' + esc(moeda(comPrazo(e.preco))) + '</span>')
           + '</span></label>';
      });
      h += '</div>';
      if (m.chave === 'digital') h += desenharFotos();
      h += '</div>';
    });
    return h + '</div>';
  }

  /**
   * As fotografias de cada secção do convite digital.
   *
   * Só aparecem quando o casal leva o convite digital — e são particularmente
   * importantes no escalão SEM edição, onde esta é a única vez que ele as
   * escolhe. Dizê-lo aqui, e não depois, é a diferença entre uma escolha
   * informada e uma surpresa.
   */
  function desenharFotos() {
    var escId = sel.escaloes['digital'];
    if (!escId || !SECCOES.length) return '';
    var e = escalao(escId);
    if (!e) return '';
    var podeEditar = !!e.editar;

    var h = '<div class="pl-fotos">'
      + '<div class="pl-fotos-cab"><b>As fotografias do vosso convite</b>'
      + '<span>Escolham a imagem de cada secção. Ficam já no convite.</span></div>';

    h += podeEditar
      ? '<div class="pl-fotos-nota boa">Com <b>edição</b>: estas são as fotografias com que '
        + 'o convite nasce, e podem trocá-las por outras — ou pelas vossas — sempre que quiserem.</div>'
      : '<div class="pl-fotos-nota aviso"><b>Atenção:</b> o escalão «' + esc(e.nome) + '» é '
        + '<b>sem edição</b>. Estas fotografias ficam fixas no vosso convite e <b>não poderão '
        + 'ser alteradas</b> depois. Para as poder trocar mais tarde — ou usar fotografias '
        + 'vossas — escolham um escalão com edição.</div>';

    SECCOES.forEach(function (sc) {
      var escolhida = sel.fotos[sc.chave] || (sc.fotos[0] && sc.fotos[0].src);
      h += '<div class="pl-sec"><div class="pl-sec-cab">'
         + '<b>' + esc(sc.rotulo) + '</b><span>' + esc(sc.descricao) + '</span></div>'
         + '<div class="pl-sec-tiras">';
      sc.fotos.forEach(function (ft) {
        h += '<label class="pl-ft' + (ft.src === escolhida ? ' on' : '') + '"'
           + ' title="' + esc(ft.nome) + '">'
           + '<input type="radio" name="ft-' + esc(sc.chave) + '" value="' + esc(ft.src) + '"'
           + (ft.src === escolhida ? ' checked' : '') + '>'
           + '<img src="' + esc(ft.src) + '" alt="' + esc(ft.nome) + '"'
           + ' loading="lazy" decoding="async">'
           + '<span class="pl-ft-visto">✓</span></label>';
      });
      h += '</div></div>';
    });
    return h + '</div>';
  }

  function desenharConta() {
    var c = Planos.escolha();
    // Num reforço o total É a diferença: o que já se tem está desligado e fora
    // da conta. Dizê-lo pelo nome evita que o casal julgue que vai pagar tudo
    // outra vez — que é exactamente o receio que trava um upgrade.
    var det, rot = COM_PACOTES ? 'Total a pedir' : 'Diferença a pagar';
    if (c.vazio) {
      det = COM_PACOTES
          ? 'Escolha um pacote acima, ou marque os módulos que quer levar.'
          : 'Marque os módulos que quer juntar à sua licença.';
    } else if (c.pacote) {
      var p = null;
      CAT.pacotes.forEach(function (x) { if (x.id === c.pacote) p = x; });
      det = 'Pacote <b>' + esc(p ? p.nome : '') + '</b> · ' + c.meses + ' meses'
          + (p && p.poupanca > 0
              ? ' · <span class="pl-conta-poupa">poupa ' + esc(moeda(comPrazo(p.poupanca)))
                + '</span>' : '');
    } else if (COM_PACOTES) {
      det = c.escaloes.length + ' módulo(s) à medida · ' + c.meses + ' meses';
    } else {
      det = 'Junta ' + c.escaloes.length + ' módulo(s) à licença que já tem. '
          + 'O que já está pago não volta a ser cobrado.';
    }
    return '<div class="pl-conta"><div class="pl-conta-txt">'
         + '<div class="pl-conta-rot">' + rot + '</div>'
         + '<div class="pl-conta-val">' + esc(moeda(c.total)) + '</div>'
         + '<div class="pl-conta-det">' + det + '</div></div>'
         + '<div id="pl-acoes"></div></div>';
  }

  function pintar() {
    if (!ALVO) return;
    ALVO.innerHTML = desenharPrazos() + desenharPacotes() + desenharMedida() + desenharConta();
    ligar();
    if (typeof AO_MUDAR === 'function') AO_MUDAR(Planos.escolha());
  }

  function ligar() {
    ALVO.querySelectorAll('[data-pacote]').forEach(function (el) {
      function tocar() {
        var id = parseInt(el.dataset.pacote, 10);
        // Clicar no que já está escolhido desfaz: é a única forma de voltar
        // ao plano à medida sem recarregar a página.
        sel.pacote = (sel.pacote === id) ? 0 : id;
        if (sel.pacote) sel.escaloes = {};
        pintar();
      }
      el.addEventListener('click', tocar);
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); tocar(); }
      });
    });
    ALVO.querySelectorAll('.pl-prazos input').forEach(function (inp) {
      inp.addEventListener('change', function () {
        sel.prazo = parseInt(inp.value, 10) || 0;
        pintar();   // muda o prazo, mudam todos os preços
      });
    });
    ALVO.querySelectorAll('.pl-esc input').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var mod = inp.name.replace(/^pl-/, '');
        var id  = parseInt(inp.value, 10);
        if (id > 0) sel.escaloes[mod] = id; else delete sel.escaloes[mod];
        sel.pacote = 0;                    // à medida e pacote não coexistem
        pintar();
      });
    });
    // As fotografias do convite: trocar uma não repinta a montra inteira — isso
    // fazia o ecrã saltar e perdia-se o sítio onde se estava a escolher.
    ALVO.querySelectorAll('.pl-ft input').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var chave = inp.name.replace(/^ft-/, '');
        sel.fotos[chave] = inp.value;
        var tira = inp.closest('.pl-sec-tiras');
        if (tira) tira.querySelectorAll('.pl-ft').forEach(function (l) {
          l.classList.toggle('on', l.contains(inp));
        });
        if (typeof AO_MUDAR === 'function') AO_MUDAR(Planos.escolha());
      });
    });
    ALVO.querySelectorAll('[data-ampliar]').forEach(function (el) {
      function abrir() { ampliar(el.dataset.ampliar, el.dataset.titulo); }
      el.addEventListener('click', abrir);
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); }
      });
    });
  }

  /** A imagem de um módulo em grande, para se ver mesmo o que se está a comprar. */
  function ampliar(src, titulo) {
    var m = document.getElementById('pl-lupa');
    if (!m) {
      m = document.createElement('div');
      m.id = 'pl-lupa'; m.className = 'pl-modal pl-modal-img';
      m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      document.body.appendChild(m);
      m.addEventListener('click', function () { m.classList.remove('on'); });
    }
    m.innerHTML = '<figure class="pl-lupa-cx">'
      + '<img src="' + esc(src) + '" alt="' + esc(titulo || '') + '">'
      + '<figcaption>' + esc(titulo || '') + ' <span>toque para fechar</span></figcaption>'
      + '</figure>';
    m.classList.add('on');
    function fecha(ev) {
      if (ev.key !== 'Escape') return;
      m.classList.remove('on');
      document.removeEventListener('keydown', fecha);
    }
    document.addEventListener('keydown', fecha);
  }

  // ---------- políticas ----------
  /** O texto das políticas, da marcação simples do admin para HTML. */
  function textoPoliticas(corpo) {
    var linhas = String(corpo || '').split('\n');
    var h = '', lista = false, par = [];
    function fecharPar() {
      if (par.length) { h += '<p>' + realces(par.join(' ')) + '</p>'; par = []; }
    }
    function fecharLista() { if (lista) { h += '</ul>'; lista = false; } }
    linhas.forEach(function (ln) {
      var t = ln.trim();
      if (t === '') { fecharPar(); fecharLista(); return; }
      if (t.indexOf('## ') === 0) {
        fecharPar(); fecharLista();
        h += '<h4>' + esc(t.slice(3)) + '</h4>';
        return;
      }
      if (t.indexOf('- ') === 0) {
        fecharPar();
        if (!lista) { h += '<ul>'; lista = true; }
        h += '<li>' + realces(t.slice(2)) + '</li>';
        return;
      }
      // Uma alínea que continua na linha seguinte junta-se à anterior.
      if (lista) { h = h.replace(/<\/li>$/, ' ' + realces(t) + '</li>'); return; }
      par.push(t);
    });
    fecharPar(); fecharLista();
    return h;
  }

  /** As referências à lei ficam realçadas — é o que dá peso ao texto. */
  function realces(s) {
    return esc(s).replace(
      /((?:Lei|Decreto)[^,;.]*?n\.º\s?\d+\/\d+|artigos?\s\d+\.º(?:\s?[a-z]\))?(?:\s(?:a|e)\s\d+\.º)?)/g,
      '<span class="lei">$1</span>');
  }

  function janelaPoliticas(pol, aoAceitar) {
    var m = document.getElementById('pl-modal');
    if (!m) {
      m = document.createElement('div');
      m.id = 'pl-modal'; m.className = 'pl-modal';
      m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      document.body.appendChild(m);
      m.addEventListener('click', function (e) { if (e.target === m) fecharPoliticas(); });
    }
    m.innerHTML = '<div class="pl-modal-cx"><div class="pl-modal-cab">'
      + '<h3>' + esc(pol.titulo) + '</h3>'
      + '<button type="button" class="btn btn-fantasma btn-sm" id="pl-fechar">Fechar</button></div>'
      + '<div class="pl-modal-corpo"><div class="pl-texto">' + textoPoliticas(pol.corpo) + '</div></div>'
      + '<div class="pl-modal-rodape">'
      + '<span class="dica" style="margin:0;flex:1">Versão ' + (pol.versao || 1) + '</span>'
      + (aoAceitar ? '<button type="button" class="btn btn-ouro btn-sm" id="pl-aceitar">Li e aceito</button>' : '')
      + '</div></div>';
    m.classList.add('on');
    document.getElementById('pl-fechar').onclick = fecharPoliticas;
    var b = document.getElementById('pl-aceitar');
    if (b) b.onclick = function () { fecharPoliticas(); aoAceitar(); };
    document.addEventListener('keydown', escFecha);
  }
  function escFecha(e) { if (e.key === 'Escape') fecharPoliticas(); }
  function fecharPoliticas() {
    var m = document.getElementById('pl-modal');
    if (m) m.classList.remove('on');
    document.removeEventListener('keydown', escFecha);
  }

  // ---------- interface pública ----------
  var Planos = {
    montar: function (alvo, catalogo, opcoes) {
      opcoes = opcoes || {};
      ALVO = typeof alvo === 'string' ? document.getElementById(alvo) : alvo;
      CAT = catalogo || { modulos: [], pacotes: [] };
      TENHO = opcoes.tenho || {};
      MOEDA = opcoes.moeda || 'Kz';
      AO_MUDAR = opcoes.aoMudar || null;
      COM_PACOTES = opcoes.pacotes !== false;
      SECCOES = opcoes.seccoes || [];
      PRAZOS  = (CAT.prazos || []).slice();
      sel = { pacote: 0, escaloes: {}, fotos: {}, prazo: opcoes.prazo || 0 };

      // Um módulo obrigatório que o casamento ainda não tenha nasce escolhido —
      // no escalão mais barato, que é o ponto de entrada. Deixá-lo por marcar
      // era mostrar um plano impossível e só o dizer no fim.
      CAT.modulos.forEach(function (m) {
        if (!m.ativo || !m.obrigatorio) return;
        var t = TENHO[m.chave];
        if (t && t.ativo) return;
        var livres = m.escaloes.filter(function (e) { return e.ativo && !jaTem(e); });
        if (livres.length) sel.escaloes[m.chave] = livres[0].id;
      });
      // Sem nada escolhido, começa-se pelo pacote em destaque: é o que serve a
      // maioria, e uma montra que não sugere nada obriga a decidir do zero.
      if (opcoes.sugerir !== false && COM_PACOTES) {
        CAT.pacotes.forEach(function (p) { if (p.ativo && p.destaque) sel.pacote = p.id; });
      }
      pintar();
      return Planos;
    },

    /** A escolha actual, pronta a enviar à API. */
    escolha: function () {
      // As fotografias só contam se o convite digital for mesmo levado — e, num
      // pacote, é o pacote que diz se o traz.
      var comDigital = false;
      if (sel.pacote) {
        var pk = null;
        CAT.pacotes.forEach(function (x) { if (x.id === sel.pacote) pk = x; });
        if (pk) pk.itens.forEach(function (id) {
          var e = escalao(id); if (e && e.modulo === 'digital') comDigital = true;
        });
      } else if (sel.escaloes['digital']) {
        comDigital = true;
      }
      var fotos = {};
      if (comDigital) SECCOES.forEach(function (sc) {
        var v = sel.fotos[sc.chave] || (sc.fotos[0] && sc.fotos[0].src);
        if (v) fotos[sc.chave] = v;
      });

      // O prazo escolhido manda: é ele que define os meses e multiplica o preço.
      var pz = prazoAtual();
      var meses = pz ? pz.meses : 12;

      if (sel.pacote) {
        var p = null;
        CAT.pacotes.forEach(function (x) { if (x.id === sel.pacote) p = x; });
        if (p) return { pacote: p.id, escaloes: p.itens.slice(), meses: meses,
                        total: comPrazo(p.preco), fotos: fotos, vazio: false };
      }
      var ids = [], total = 0;
      Object.keys(sel.escaloes).forEach(function (m) {
        var e = escalao(sel.escaloes[m]);
        if (!e) return;
        ids.push(e.id); total += comPrazo(e.preco);
      });
      return { pacote: 0, escaloes: ids, meses: meses, total: total, fotos: fotos,
               vazio: ids.length === 0 };
    },

    /** Marca uma escolha vinda de fora (um pedido já submetido, a reabrir). */
    repor: function (pacoteId, escalaoIds, fotos, meses) {
      sel = { pacote: 0, escaloes: {}, fotos: fotos || {}, prazo: meses || 0 };
      if (pacoteId) {
        sel.pacote = pacoteId;
      } else {
        (escalaoIds || []).forEach(function (id) {
          var e = escalao(id);
          if (e) sel.escaloes[e.modulo] = e.id;
        });
      }
      pintar();
    },

    politicas: janelaPoliticas,
    fecharPoliticas: fecharPoliticas,
    texto: textoPoliticas,
    moeda: moeda,
    medida: medida
  };

  global.Planos = Planos;
})(window);
