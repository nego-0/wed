/* ============================================================
   versoes.js — Painel de versões, partilhado pelos dois editores

   Cada peça (convite digital, convite impresso) guarda as suas
   versões em separado. Uma delas está "em vigor" — a predefinida:
   é a que o convite mostra. Aplicar uma versão é torná-la a
   predefinida.

   Depois de aplicar, o utilizador continua a editar; a versão
   passa então a estar desatualizada face ao que está gravado. O
   painel diz isso, e oferece as duas saídas: voltar a aplicá-la
   (perdendo as mudanças) ou atualizá-la (guardando-as nela).

   Usado por convite-editor.php (ambito 'digital') e por
   editor-cartao.php (ambito 'impresso'). Precisa de assets/api.js.
   ============================================================ */
(function (global) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /** Data do MySQL para algo curto e legível. */
  function fmtData(sql) {
    if (!sql) return '';
    var d = new Date(String(sql).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleString('pt-PT',
      { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  /**
   * Monta o painel.
   * @param {object} op
   *   op.ambito    'digital' | 'impresso'
   *   op.alvo      id ou elemento onde desenhar
   *   op.sujo      função que diz se há alterações por gravar
   *   op.msg       função para escrever na barra de estado
   *   op.aoAplicar chamada depois de aplicar; por omissão recarrega
   * @returns {{recarregar: function}}
   */
  function montar(op) {
    var caixa = typeof op.alvo === 'string' ? document.getElementById(op.alvo) : op.alvo;
    if (!caixa) return { recarregar: function () {} };

    var ambito = op.ambito || 'digital';
    var sujo   = op.sujo || function () { return false; };
    var dizer  = op.msg  || function () {};
    var api    = global.api;
    var lista  = [];
    var max    = 0;
    var ocupado = false;

    function q(accao, extra) {
      return accao + '&ambito=' + encodeURIComponent(ambito) + (extra || '');
    }

    /** Corre uma ação da API, com o painel travado enquanto espera. */
    async function correr(fn) {
      if (ocupado) return null;
      ocupado = true;
      caixa.classList.add('vs-ocupado');
      try { return await fn(); }
      finally { ocupado = false; caixa.classList.remove('vs-ocupado'); }
    }

    // ---- desenho ----
    function estado(v) {
      if (v.predefinida && v.igual_ao_atual) return ['vigor',  'Em vigor'];
      if (v.predefinida)                     return ['mudou',  'Em vigor · com alterações desde então'];
      if (v.igual_ao_atual)                  return ['igual',  'Igual ao que está gravado'];
      return ['', ''];
    }

    function desenhar() {
      var topo =
        '<div class="campo vs-nova">' +
          '<label>Guardar como nova versão</label>' +
          '<div class="vs-lin">' +
            '<input type="text" class="vs-nome-novo" maxlength="80" placeholder="ex.: antes de mudar as cores">' +
            '<button class="bt bt-min vs-criar">Guardar</button>' +
          '</div>' +
          '<div class="vs-dica">Fotografa o que está <b>gravado</b> agora. Se tiver alterações por gravar, grave-as primeiro.</div>' +
        '</div>';

      var corpo = lista.length
        ? lista.map(function (v) {
            var e = estado(v), acs = [];
            if (!v.predefinida || !v.igual_ao_atual) {
              acs.push('<button class="bt bt-min vs-aplicar" data-id="' + v.id + '">' +
                       (v.predefinida ? 'Voltar a esta' : 'Tornar predefinida') + '</button>');
            }
            if (!v.igual_ao_atual) {
              acs.push('<button class="bt bt-min vs-atualizar" data-id="' + v.id + '" ' +
                       'title="Reescreve a versão com o que está gravado agora">Atualizar</button>');
            }
            acs.push('<button class="bt bt-min vs-renomear" data-id="' + v.id + '" title="Mudar o nome">Nome</button>');
            acs.push('<button class="bt bt-min vs-apagar" data-id="' + v.id + '" title="Apagar esta versão">✕</button>');

            return '<div class="vs-item' + (v.predefinida ? ' vs-em-vigor' : '') + '">' +
                     '<div class="vs-topo">' +
                       '<span class="vs-nome">' + esc(v.nome) + '</span>' +
                       (e[1] ? '<span class="vs-selo vs-' + e[0] + '">' + esc(e[1]) + '</span>' : '') +
                     '</div>' +
                     '<div class="vs-dica">' + esc(v.utilizador || '—') + ' · ' + fmtData(v.criado_em) +
                       (v.atualizado_em ? ' · atualizada ' + fmtData(v.atualizado_em) : '') +
                     '</div>' +
                     '<div class="vs-acoes">' + acs.join('') + '</div>' +
                   '</div>';
          }).join('')
        : '<div class="vazio-painel">Ainda não guardou nenhuma versão desta peça. ' +
          'Guarde uma antes de experimentar mudanças grandes — volta a ela num clique.</div>';

      var rodape = lista.length ? '<div class="vs-dica vs-conta">' + lista.length + ' de ' + max + ' versões.</div>' : '';
      caixa.innerHTML = topo + corpo + rodape;
    }

    async function recarregar() {
      var d = await api(q('versao_lista'), { silencioso: true });
      if (!d || !d.success) {
        caixa.innerHTML = '<div class="vazio-painel">Não foi possível ler as versões.</div>';
        return;
      }
      lista = d.versoes || [];
      max   = d.max || 0;
      desenhar();
    }

    function nomeDe(id) {
      var v = lista.filter(function (x) { return String(x.id) === String(id); })[0];
      return v ? v.nome : '';
    }

    // ---- ações ----
    async function criar() {
      var campo = caixa.querySelector('.vs-nome-novo');
      var nome = (campo && campo.value || '').trim();
      if (!nome) { dizer('Dê um nome à versão, para a reconhecer mais tarde.'); if (campo) campo.focus(); return; }
      if (sujo() && !confirm('Tem alterações por gravar.\n\nA versão guarda a peça como está gravada, sem elas. Continuar?')) return;
      var d = await correr(function () {
        return api(q('versao_criar'), { method: 'POST', body: JSON.stringify({ nome: nome, ambito: ambito }) });
      });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível guardar a versão.');
      await recarregar();
      dizer('Versão guardada: ' + nome);
    }

    async function aplicar(id) {
      var nome = nomeDe(id);
      if (sujo() && !confirm('Tem alterações por gravar.\n\nAplicar "' + nome + '" descarta-as. Continuar?')) return;
      else if (!sujo() && !confirm('Tornar "' + nome + '" a versão predefinida?\n\nA peça passa a ser como estava quando a guardou.')) return;
      var d = await correr(function () { return api(q('versao_aplicar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível aplicar a versão.');
      dizer('Versão em vigor: ' + nome + '. A recarregar…');
      if (op.aoAplicar) op.aoAplicar(d);
      else setTimeout(function () { global.location.reload(); }, 700);
    }

    async function atualizar(id) {
      var nome = nomeDe(id);
      if (!confirm('Atualizar "' + nome + '" com o que está gravado agora?\n\nO conteúdo antigo da versão perde-se.')) return;
      var d = await correr(function () { return api(q('versao_atualizar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível atualizar a versão.');
      await recarregar();
      dizer('Versão atualizada: ' + nome);
    }

    async function renomear(id) {
      var novo = prompt('Novo nome para a versão:', nomeDe(id));
      if (novo === null) return;
      novo = novo.trim();
      if (!novo) return dizer('O nome não pode ficar vazio.');
      var d = await correr(function () {
        return api(q('versao_renomear', '&id=' + id), { method: 'POST', body: JSON.stringify({ nome: novo }) });
      });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível mudar o nome.');
      await recarregar();
      dizer('Versão renomeada: ' + novo);
    }

    async function apagar(id) {
      var v = lista.filter(function (x) { return String(x.id) === String(id); })[0];
      var extra = v && v.predefinida
        ? '\n\nEsta é a versão em vigor. A peça não muda; passa a estar em vigor a mais recente das que ficam.'
        : '\n\nA peça não muda; perde-se apenas este ponto de regresso.';
      if (!confirm('Apagar a versão "' + (v ? v.nome : '') + '"?' + extra)) return;
      var d = await correr(function () { return api(q('versao_apagar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível apagar a versão.');
      await recarregar();
      dizer('Versão apagada.');
    }

    // Um só ouvinte na caixa: o conteúdo é redesenhado a cada ação.
    caixa.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b || !caixa.contains(b)) return;
      if (b.classList.contains('vs-criar'))     return criar();
      if (b.classList.contains('vs-aplicar'))   return aplicar(b.dataset.id);
      if (b.classList.contains('vs-atualizar')) return atualizar(b.dataset.id);
      if (b.classList.contains('vs-renomear'))  return renomear(b.dataset.id);
      if (b.classList.contains('vs-apagar'))    return apagar(b.dataset.id);
    });
    caixa.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.classList.contains('vs-nome-novo')) { e.preventDefault(); criar(); }
    });

    desenhar();       // esqueleto imediato, para o painel não abrir vazio
    recarregar();
    return { recarregar: recarregar };
  }

  global.Versoes = { montar: montar };
})(window);
