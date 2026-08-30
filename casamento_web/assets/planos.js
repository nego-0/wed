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
  var sel = { pacote: 0, escaloes: {} };   // escaloes: modulo => escalao_id

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
        + '<div class="pl-preco"><b>' + esc(moeda(p.preco).replace(' ' + MOEDA, '')) + '</b>'
        +   '<span class="moeda">' + esc(MOEDA) + '</span>'
        +   (p.poupanca > 0 ? '<span class="pl-antes">' + esc(moeda(p.avulso)) + '</span>' : '')
        + '</div>'
        + (p.poupanca > 0 ? '<span class="pl-poupa">Poupa ' + esc(moeda(p.poupanca)) + '</span>' : '')
        + '<div class="pl-prazo">' + p.meses + ' meses de licença</div>'
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
      h += '<div class="pl-mod"><div class="pl-mod-cab">'
         + '<div class="pl-mod-ico">' + esc(m.icone || '•') + '</div><div>'
         + '<div class="pl-mod-nome">' + esc(m.nome) + '</div>'
         + (m.beneficio ? '<div class="pl-mod-benef">' + esc(m.beneficio) + '</div>' : '')
         + '<div class="pl-mod-resumo">' + esc(m.resumo) + '</div>'
         + '</div></div><div class="pl-escs">';

      // «Não levar» é uma escolha como as outras, e tem de se poder desfazer.
      var nada = !sel.escaloes[m.chave];
      h += '<label class="pl-esc' + (nada ? ' on' : '') + '">'
         + '<input type="radio" name="pl-' + esc(m.chave) + '" value="0"' + (nada ? ' checked' : '') + '>'
         + '<span><span class="pl-esc-nome">Não levar</span>'
         + '<span class="pl-esc-res">Fica de fora deste plano.</span></span></label>';

      escs.forEach(function (e) {
        var tem = jaTem(e);
        var on = sel.escaloes[m.chave] === e.id;
        h += '<label class="pl-esc' + (on ? ' on' : '') + (tem ? ' tem' : '') + '">'
           + '<input type="radio" name="pl-' + esc(m.chave) + '" value="' + e.id + '"'
           +   (on ? ' checked' : '') + (tem ? ' disabled' : '') + '>'
           + '<span><span class="pl-esc-nome">' + esc(e.nome) + '</span>'
           + (e.resumo ? '<span class="pl-esc-res">' + esc(e.resumo) + '</span>' : '')
           + (tem ? '<span class="pl-esc-tem">✓ Já tem</span>'
                  : '<span class="pl-esc-preco">' + esc(moeda(e.preco)) + '</span>')
           + '</span></label>';
      });
      h += '</div></div>';
    });
    return h + '</div>';
  }

  function desenharConta() {
    var c = Planos.escolha();
    var det, rot = 'Total a pedir';
    if (c.vazio) {
      det = COM_PACOTES
          ? 'Escolha um pacote acima, ou marque os módulos que quer levar.'
          : 'Marque os módulos que quer juntar à sua licença.';
    } else if (c.pacote) {
      var p = null;
      CAT.pacotes.forEach(function (x) { if (x.id === c.pacote) p = x; });
      det = 'Pacote <b>' + esc(p ? p.nome : '') + '</b> · ' + c.meses + ' meses'
          + (p && p.poupanca > 0
              ? ' · <span class="pl-conta-poupa">poupa ' + esc(moeda(p.poupanca)) + '</span>' : '');
    } else {
      det = c.escaloes.length + ' módulo(s) à medida · ' + c.meses + ' meses';
    }
    return '<div class="pl-conta"><div class="pl-conta-txt">'
         + '<div class="pl-conta-rot">' + rot + '</div>'
         + '<div class="pl-conta-val">' + esc(moeda(c.total)) + '</div>'
         + '<div class="pl-conta-det">' + det + '</div></div>'
         + '<div id="pl-acoes"></div></div>';
  }

  function pintar() {
    if (!ALVO) return;
    ALVO.innerHTML = desenharPacotes() + desenharMedida() + desenharConta();
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
    ALVO.querySelectorAll('.pl-esc input').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var mod = inp.name.replace(/^pl-/, '');
        var id  = parseInt(inp.value, 10);
        if (id > 0) sel.escaloes[mod] = id; else delete sel.escaloes[mod];
        sel.pacote = 0;                    // à medida e pacote não coexistem
        pintar();
      });
    });
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
      sel = { pacote: 0, escaloes: {} };
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
      if (sel.pacote) {
        var p = null;
        CAT.pacotes.forEach(function (x) { if (x.id === sel.pacote) p = x; });
        if (p) return { pacote: p.id, escaloes: p.itens.slice(), meses: p.meses,
                        total: p.preco, vazio: false };
      }
      var ids = [], total = 0;
      Object.keys(sel.escaloes).forEach(function (m) {
        var e = escalao(sel.escaloes[m]);
        if (!e) return;
        ids.push(e.id); total += e.preco;
      });
      return { pacote: 0, escaloes: ids, meses: 12, total: total, vazio: ids.length === 0 };
    },

    /** Marca uma escolha vinda de fora (um pedido já submetido, a reabrir). */
    repor: function (pacoteId, escalaoIds) {
      sel = { pacote: 0, escaloes: {} };
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
