/* ============================================================
   versoes.js — Seletor de versões na barra superior

   Cada peça (convite digital, convite impresso) guarda as suas
   versões em separado. Está "em vigor" a versão cujo conteúdo é o
   que a peça mostra neste momento — a que os convidados recebem e a
   que o manual de impressão retrata. Não é uma marca guardada: é uma
   verdade sobre a peça, verificada de cada vez que a lista se lê.

   Substitui o antigo painel lateral por um <select> na barra de
   cima, ao pé do "Guardar": a versão em vigor aparece já escolhida;
   escolher outra aplica-a; guardar uma nova, renomear, atualizar ou
   apagar são ações do mesmo seletor. Depois de aplicar, o utilizador
   continua a editar e a peça afasta-se do que está gravado — o
   seletor passa então a mostrar "com alterações — fora de versão".

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

  /**
   * Monta o seletor.
   * @param {object} op
   *   op.ambito    'digital' | 'impresso'
   *   op.alvo      id ou elemento do <select> onde desenhar
   *   op.sujo      função que diz se há alterações por gravar
   *   op.msg       função para escrever na barra de estado
   *   op.aoAplicar chamada depois de aplicar; por omissão recarrega a página
   * @returns {{recarregar: function}}
   */
  function montar(op) {
    var sel = typeof op.alvo === 'string' ? document.getElementById(op.alvo) : op.alvo;
    if (!sel) return { recarregar: function () {} };

    var ambito = op.ambito || 'digital';
    var sujo   = op.sujo || function () { return false; };
    var dizer  = op.msg  || function () {};
    var api    = global.api;
    var lista  = [];
    var alvoId = 0;            // a versão sobre a qual agem renomear/atualizar/apagar
    var ocupado = false;

    function q(accao, extra) {
      return accao + '&ambito=' + encodeURIComponent(ambito) + (extra || '');
    }

    /** Corre uma ação da API, com o seletor travado enquanto espera. */
    async function correr(fn) {
      if (ocupado) return null;
      ocupado = true; sel.disabled = true;
      try { return await fn(); }
      finally { ocupado = false; sel.disabled = false; }
    }

    function porId(id) {
      return lista.filter(function (v) { return String(v.id) === String(id); })[0];
    }

    function opt(valor, texto, escolhido, inerte) {
      return '<option value="' + valor + '"' + (escolhido ? ' selected' : '') +
             (inerte ? ' disabled' : '') + '>' + esc(texto) + '</option>';
    }

    // ---- desenho ----
    // A versão em vigor fica escolhida; se nenhuma bater certo, fica escolhida
    // uma opção-estado ("com alterações"), para o seletor nunca mentir sobre
    // qual versão está em uso. As ações de gerir apontam à versão mais
    // relevante — a em vigor, ou a última aplicada.
    function desenhar() {
      var emVigor   = lista.filter(function (v) { return v.em_vigor; })[0];
      var escolhida = lista.filter(function (v) { return v.escolhida; })[0];
      var alvo = emVigor || escolhida || lista[0] || null;
      alvoId = alvo ? alvo.id : 0;

      var html = '';
      if (!lista.length) {
        html += '<optgroup label="Estado">' +
                opt('__nada', 'Sem versões guardadas', true, true) + '</optgroup>';
      } else {
        if (!emVigor) {
          html += '<optgroup label="Estado atual">' +
                  opt('__estado', '● Com alterações — fora de versão', true, true) +
                  '</optgroup>';
        }
        html += '<optgroup label="Pôr em vigor">';
        lista.forEach(function (v) {
          var etiqueta = v.nome +
            (v.em_vigor ? '  — em vigor' : (v.escolhida ? '  — última aplicada' : ''));
          html += opt(v.id, etiqueta, !!(emVigor && v.id === emVigor.id));
        });
        html += '</optgroup>';
      }

      html += '<optgroup label="Gerir">';
      html += opt('__nova', '＋ Guardar como nova versão…');
      if (alvo) {
        html += opt('__renomear',  'Renomear «' + alvo.nome + '»…');
        html += opt('__atualizar', 'Atualizar «' + alvo.nome + '» com o estado atual');
        html += opt('__apagar',    'Apagar «' + alvo.nome + '»…');
      }
      html += '</optgroup>';

      sel.innerHTML = html;
    }

    async function recarregar() {
      var d = await api(q('versao_lista'), { silencioso: true });
      if (!d || !d.success) { sel.innerHTML = '<option>—</option>'; return; }
      lista = d.versoes || [];
      desenhar();
    }

    // ---- ações ----
    async function criarNova() {
      var nome = (prompt('Nome para a nova versão — fotografa o que está gravado agora:', '') || '').trim();
      if (!nome) { desenhar(); return; }
      if (sujo() && !confirm('Tem alterações por gravar.\n\nA versão guarda a peça como está gravada, sem elas. Continuar?')) { desenhar(); return; }
      var d = await correr(function () {
        return api(q('versao_criar'), { method: 'POST', body: JSON.stringify({ nome: nome, ambito: ambito }) });
      });
      if (!d || !d.success) { desenhar(); return dizer((d && d.message) || 'Não foi possível guardar a versão.'); }
      await recarregar();
      dizer('Versão guardada: ' + nome);
    }

    async function aplicar(id) {
      var v = porId(id), nome = v ? v.nome : '';
      if (sujo() && !confirm('Tem alterações por gravar.\n\nAplicar "' + nome + '" descarta-as. Continuar?')) { desenhar(); return; }
      if (!sujo() && !confirm('Pôr "' + nome + '" em vigor?\n\nA peça passa a ser como estava quando a guardou — é o que os convidados passam a receber.')) { desenhar(); return; }
      var d = await correr(function () { return api(q('versao_aplicar', '&id=' + id)); });
      if (!d || !d.success) { desenhar(); return dizer((d && d.message) || 'Não foi possível aplicar a versão.'); }
      dizer('Versão em vigor: ' + nome + '. A recarregar…');
      if (op.aoAplicar) op.aoAplicar(d);
      else setTimeout(function () { global.location.reload(); }, 700);
    }

    async function atualizar(id) {
      var v = porId(id), nome = v ? v.nome : '';
      if (!confirm('Atualizar "' + nome + '" com o que está gravado agora?\n\nO conteúdo antigo da versão perde-se.')) { desenhar(); return; }
      var d = await correr(function () { return api(q('versao_atualizar', '&id=' + id)); });
      if (!d || !d.success) { desenhar(); return dizer((d && d.message) || 'Não foi possível atualizar a versão.'); }
      await recarregar();
      dizer('Versão atualizada: ' + nome);
    }

    async function renomear(id) {
      var v = porId(id);
      var novo = prompt('Novo nome para a versão:', v ? v.nome : '');
      if (novo === null) { desenhar(); return; }
      novo = novo.trim();
      if (!novo) { desenhar(); return dizer('O nome não pode ficar vazio.'); }
      var d = await correr(function () {
        return api(q('versao_renomear', '&id=' + id), { method: 'POST', body: JSON.stringify({ nome: novo }) });
      });
      if (!d || !d.success) { desenhar(); return dizer((d && d.message) || 'Não foi possível mudar o nome.'); }
      await recarregar();
      dizer('Versão renomeada: ' + novo);
    }

    async function apagar(id) {
      var v = porId(id);
      var extra = v && v.em_vigor
        ? '\n\nÉ a versão que está em vigor. A peça não muda — perde-se só o registo de que este era o estado guardado.'
        : '\n\nA peça não muda; perde-se apenas este ponto de regresso.';
      if (!confirm('Apagar a versão "' + (v ? v.nome : '') + '"?' + extra)) { desenhar(); return; }
      var d = await correr(function () { return api(q('versao_apagar', '&id=' + id)); });
      if (!d || !d.success) { desenhar(); return dizer((d && d.message) || 'Não foi possível apagar a versão.'); }
      await recarregar();
      dizer('Versão apagada.');
    }

    // Escolher no seletor: uma versão aplica-se; as ações de gerir agem sobre
    // a versão-alvo. As opções-estado voltam a desenhar-se (não são escolha).
    sel.addEventListener('change', function () {
      var v = sel.value;
      if (v === '__nova')      return criarNova();
      if (v === '__renomear')  return renomear(alvoId);
      if (v === '__atualizar') return atualizar(alvoId);
      if (v === '__apagar')    return apagar(alvoId);
      if (v === '__estado' || v === '__nada' || v === '') { desenhar(); return; }
      var id = parseInt(v, 10);
      if (!id) { desenhar(); return; }
      var emVigor = lista.filter(function (x) { return x.em_vigor; })[0];
      if (emVigor && emVigor.id === id) return;   // já está em vigor
      aplicar(id);
    });

    desenhar();       // esqueleto imediato, para o seletor não abrir vazio
    recarregar();
    return { recarregar: recarregar };
  }

  global.Versoes = { montar: montar };
})(window);
