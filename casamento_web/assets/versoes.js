/* ============================================================
   versoes.js — Versões e modelos, num painel próprio

   Cada peça (convite digital, convite impresso) guarda as suas versões em
   separado. Está "em vigor" a versão cujo conteúdo é o que a peça mostra neste
   momento — a que os convidados recebem e a que o manual de impressão retrata.
   Não é uma marca guardada: é uma verdade sobre a peça, verificada de cada vez
   que a lista se lê.

   ---- Porque deixou de ser um <select> ----
   Cabia lá tudo: as versões do casal, os modelos da casa e as ações de gerir,
   misturados em grupos de um menu suspenso. Três consequências, todas más:

   1. escolher um modelo parecia escolher uma versão, e não é a mesma coisa —
      uma é o que ESTE casal guardou, a outra é um desenho da casa;
   2. os modelos escolhiam-se pelo NOME, às cegas. Um modelo é um desenho: sem
      o ver, ninguém sabe o que vai receber;
   3. aplicar um modelo que já era o desenho em vigor recarregava a página sem
      nada mudar, em silêncio — e quem o fez concluía que não tinha funcionado.
      Era esta a queixa de "os noivos não conseguem pôr modelos em vigor": a
      ação corria bem e não dizia nada.

   Passa a ser um botão que diz o estado, e um painel com dois separadores:
   VERSÕES (as do casal, com o que se faz a cada uma) e MODELOS (os da casa, em
   miniaturas desenhadas a sério). Depois de aplicar, diz-se o que aconteceu —
   incluindo quando não mudou nada.

   Usado por convite-editor.php (ambito 'digital') e por editor-cartao.php
   (ambito 'impresso'). Precisa de assets/api.js.
   ============================================================ */
(function (global) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /** Data curta e legível: "12 Jun 2027". */
  function quando(iso) {
    if (!iso) return '';
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return '';
    var m = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
  }

  /** O endereço que desenha a cara de um modelo, por âmbito. */
  function provaDe(ambito, id) {
    return ambito === 'impresso'
      ? 'modelo-prova.php?modelo=' + id
      : 'convite-digital.php?c=EXEMPLO&demo=1&prova=1&modelo=' + id;
  }

  /**
   * Monta o botão de estado e o painel.
   * @param {object} op
   *   op.ambito    'digital' | 'impresso'
   *   op.alvo      id ou elemento do botão que abre o painel
   *   op.sujo      função que diz se há alterações por gravar
   *   op.gravar    função (async) que grava as alterações do editor; devolve
   *                false se falhar. Chamada antes de fotografar uma versão.
   *   op.msg       função para escrever na barra de estado
   *   op.aoAplicar chamada depois de aplicar; por omissão recarrega a página
   * @returns {{recarregar: function, abrir: function}}
   */
  function montar(op) {
    var bt = typeof op.alvo === 'string' ? document.getElementById(op.alvo) : op.alvo;
    if (!bt) return { recarregar: function () {}, abrir: function () {} };

    var ambito = op.ambito || 'digital';
    var sujo   = op.sujo || function () { return false; };
    var dizer  = op.msg  || function () {};
    var api    = global.api;
    var lista  = [];           // as versões deste casamento
    var modelos = [];          // os desenhos que a casa oferece a todos
    var aba = 'versoes';
    var ocupado = false;
    var jan = null;

    function q(accao, extra) {
      return accao + '&ambito=' + encodeURIComponent(ambito) + (extra || '');
    }

    async function correr(fn) {
      if (ocupado) return null;
      ocupado = true; if (jan) jan.classList.add('ocupado');
      try { return await fn(); }
      finally { ocupado = false; if (jan) jan.classList.remove('ocupado'); }
    }

    function porId(id) {
      return lista.filter(function (v) { return String(v.id) === String(id); })[0];
    }
    function emVigor()  { return lista.filter(function (v) { return v.em_vigor; })[0]; }
    function escolhida(){ return lista.filter(function (v) { return v.escolhida; })[0]; }
    /* O modelo cujo desenho é o da peça, se houver. Uma versão guardada tem
       prioridade: é do casal, e o modelo é da casa. */
    function modeloEmVigor(){ return modelos.filter(function (m) { return m.em_vigor; })[0]; }

    // ---- o botão da barra: diz o estado, e é só isso ----
    function pintarBotao() {
      var v = emVigor();
      var mod = modeloEmVigor();
      var base = escolhida() || lista.filter(function (x) { return x.padrao; })[0];
      // A «Original» é a peça de origem derivada, não uma versão que o casal
      // guardou. Se o casal pôs um modelo em vigor cujo desenho é o de origem,
      // é o modelo que manda — dizer «Original» escondia a escolha que ele fez.
      if (v && v.padrao && mod) v = null;
      if (v) {
        bt.className = 'btn-versao';
        bt.innerHTML = '<b>' + esc(v.nome) + '</b> <i>em vigor</i>';
        bt.title = 'A versão em vigor é «' + v.nome + '» — é o que os convidados recebem.';
      } else if (mod) {
        // Dizer "Alterado" quando a peça é exatamente um modelo é enganador: o
        // casal acabou de o pôr em vigor, e o botão dizia-lhe que não estava.
        bt.className = 'btn-versao modelo';
        bt.innerHTML = '<b>' + esc(mod.nome) + '</b> <i>modelo em vigor</i>';
        bt.title = 'A peça tem o desenho do modelo «' + mod.nome + '». '
                 + 'Guarde-o como versão para poder voltar a ele mais tarde.';
      } else {
        bt.className = 'btn-versao alterado';
        bt.innerHTML = '<b>Alterado</b> <i>' + (base ? 'de ' + esc(base.nome) : 'fora de versão') + '</i>';
        bt.title = 'A peça tem alterações que não estão em nenhuma versão guardada.';
      }
    }

    // ---- a janela ----
    function criarJanela() {
      if (jan) return jan;
      jan = document.createElement('div');
      jan.className = 'vs-jan';
      jan.innerHTML =
        '<div class="vs-caixa" role="dialog" aria-modal="true" aria-label="Versões e modelos">' +
          '<div class="vs-topo"><h3>Versões e modelos</h3>' +
            '<button class="vs-x" aria-label="Fechar">&times;</button></div>' +
          '<div class="vs-abas"></div>' +
          '<div class="vs-corpo"></div>' +
        '</div>';
      document.body.appendChild(jan);
      jan.addEventListener('click', function (e) { if (e.target === jan) fechar(); });
      jan.querySelector('.vs-x').addEventListener('click', fechar);
      return jan;
    }

    function abrir(qual) {
      criarJanela();
      if (qual) aba = qual;
      jan.classList.add('aberta');
      pintar();
      recarregar();
    }
    function fechar() { if (jan) jan.classList.remove('aberta'); }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && jan && jan.classList.contains('aberta')) fechar();
    });

    function pintar() {
      if (!jan || !jan.classList.contains('aberta')) return;
      jan.querySelector('.vs-abas').innerHTML =
        ['versoes', 'modelos'].map(function (a) {
          var rot = a === 'versoes' ? 'Versões guardadas' : 'Modelos da casa';
          var n = a === 'versoes' ? lista.length : modelos.length;
          return '<button class="vs-aba' + (aba === a ? ' on' : '') + '" data-aba="' + a + '">' +
                 rot + ' <b>' + n + '</b></button>';
        }).join('');
      jan.querySelectorAll('.vs-aba').forEach(function (b) {
        b.addEventListener('click', function () { aba = b.dataset.aba; pintar(); });
      });
      jan.querySelector('.vs-corpo').innerHTML = aba === 'versoes' ? htmlVersoes() : htmlModelos();
      ligar();
    }

    function htmlEstado() {
      var v = emVigor();
      var mod = modeloEmVigor();
      var base = escolhida() || lista.filter(function (x) { return x.padrao; })[0];
      // Ver pintarBotao(): o modelo em vigor manda sobre a «Original» derivada.
      if (v && v.padrao && mod) v = null;
      if (v) {
        return '<div class="vs-estado"><span class="vs-pt ok"></span>Em vigor: <b>' + esc(v.nome) + '</b>' +
               '<em>é este o convite que os seus convidados recebem</em></div>';
      }
      if (mod) {
        return '<div class="vs-estado"><span class="vs-pt ok"></span>Em vigor: o modelo <b>' +
               esc(mod.nome) + '</b><em>é este o convite que os seus convidados recebem. ' +
               'Guarde-o como versão se quiser poder voltar a ele depois de mexer.</em></div>';
      }
      return '<div class="vs-estado alt"><span class="vs-pt"></span>Com alterações por versionar' +
             (base ? ', a partir de <b>' + esc(base.nome) + '</b>' : '') +
             '<em>o que está no ecrã já é o que os convidados recebem — guarde uma versão para poder voltar aqui</em></div>';
    }

    function htmlVersoes() {
      var mod = modeloEmVigor();
      return htmlEstado() +
        '<div class="vs-lista">' + lista.map(function (v) {
          // A «Original» derivada cede a vez ao modelo em vigor (ver htmlEstado).
          var vigora = v.em_vigor && !(v.padrao && mod);
          var etiquetas =
            (vigora ? '<span class="vs-et ok">em vigor</span>' : '') +
            (v.padrao ? '<span class="vs-et">peça de origem</span>' : '') +
            (!vigora && v.escolhida ? '<span class="vs-et">última aplicada</span>' : '');
          var acoes = (vigora ? '' :
                '<button class="vs-b prim" data-ac="aplicar" data-id="' + v.id + '">Pôr em vigor</button>') +
            (v.padrao ? '' :
                '<button class="vs-b" data-ac="atualizar" data-id="' + v.id + '">Atualizar</button>' +
                '<button class="vs-b" data-ac="renomear" data-id="' + v.id + '">Mudar o nome</button>' +
                '<button class="vs-b perigo" data-ac="apagar" data-id="' + v.id + '">Apagar</button>');
          return '<div class="vs-it' + (vigora ? ' em-vigor' : '') + '">' +
                   '<div class="vs-it-nm">' + esc(v.nome) + ' ' + etiquetas +
                     '<span class="vs-q">' + esc(quando(v.criada_em)) + '</span></div>' +
                   '<div class="vs-it-ac">' + acoes + '</div></div>';
        }).join('') + '</div>' +
        '<div class="vs-fim"><button class="vs-b prim" data-ac="nova">Guardar como nova versão</button>' +
          '<span class="vs-nota">Fotografa o convite tal como está agora, para poder voltar a ele.</span></div>';
    }

    function htmlModelos() {
      if (!modelos.length) {
        return htmlEstado() +
          '<div class="vs-vazio">Ainda não há modelos disponíveis para si.<br>' +
          '<small>Os modelos são desenhos prontos que a casa publica. Quando houver, aparecem aqui ' +
          'com a sua cara, para escolher a olho.</small></div>';
      }
      return htmlEstado() +
        '<div class="vs-nota vs-nota-topo">Um modelo é um desenho pronto. Pôr um em vigor troca o ' +
        '<b>desenho</b> da peça — os seus nomes, datas, locais e fotografias ficam como estão. ' +
        'As versões que guardou também: pode voltar a qualquer uma.</div>' +
        '<div class="vs-grelha">' + modelos.map(function (m) {
          // Um modelo que JÁ é o desenho da peça não se oferece para aplicar:
          // dizia-se "pôr em vigor", nada mudava, e a culpa parecia ser do botão.
          var jaEsta = !!m.em_vigor;
          return '<div class="vs-mod' + (jaEsta ? ' em-vigor' : '') + '">' +
            '<div class="vs-cara"><iframe src="' + esc(provaDe(ambito, m.id)) + '" loading="lazy" ' +
              'tabindex="-1" scrolling="no" title="' + esc(m.nome) + '"></iframe></div>' +
            '<div class="vs-mod-pe"><span class="vs-mod-nm">' + esc(m.nome) +
              (jaEsta ? ' <span class="vs-et ok">em vigor</span>' : '') + '</span>' +
              (m.descricao ? '<span class="vs-mod-ds">' + esc(m.descricao) + '</span>' : '') +
              (jaEsta
                ? '<span class="vs-nota">É este o desenho da peça agora.</span>'
                : '<button class="vs-b prim" data-ac="modelo" data-id="' + m.id + '">Pôr em vigor</button>') +
            '</div></div>';
        }).join('') + '</div>';
    }

    /**
     * As miniaturas desenham-se em TAMANHO REAL e encolhem-se para caber: é o
     * que faz a prova ser mesmo a peça, e não uma versão apertada dela. As
     * larguras são as mesmas da página dos modelos — o cartão compõe-se a 720
     * de largura e o convite digital a 640.
     */
    function escalarProvas() {
      if (!jan) return;
      var largura = ambito === 'impresso' ? 720 : 640;
      var altura = Math.round(largura * 1.5);
      jan.querySelectorAll('.vs-cara').forEach(function (m) {
        var f = m.querySelector('iframe'); if (!f) return;
        var e = m.clientWidth / largura;
        f.style.width = largura + 'px'; f.style.height = altura + 'px';
        f.style.transform = 'scale(' + e + ')';
        m.style.height = Math.round(altura * e) + 'px';
      });
    }

    function ligar() {
      jan.querySelectorAll('[data-ac]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.dataset.id;
          switch (b.dataset.ac) {
            case 'aplicar':   return aplicar(id);
            case 'atualizar': return atualizar(id);
            case 'renomear':  return renomear(id);
            case 'apagar':    return apagar(id);
            case 'nova':      return criarNova();
            case 'modelo':    return aplicarModelo(id);
          }
        });
      });
      escalarProvas();
    }

    async function recarregar() {
      var d = await api(q('versao_lista'), { silencioso: true });
      lista = (d && d.success) ? (d.versoes || []) : [];
      var m = await api('modelo_lista&ambito=' + encodeURIComponent(ambito), { silencioso: true });
      modelos = (m && m.success) ? (m.modelos || []) : [];
      pintarBotao();
      pintar();
    }

    // ---- ações ----
    // Uma versão fotografa o que está GRAVADO. Se o editor tem alterações por
    // gravar, grava-as primeiro — senão a versão sairia sem elas.
    async function garantirGravado() {
      if (!sujo()) return true;
      if (op.gravar) { var ok = await op.gravar(); return ok !== false; }
      return confirm('Tem alterações por gravar.\n\nA versão guarda a peça como está gravada, sem elas. Continuar?');
    }

    function depoisDeAplicar(texto) {
      dizer(texto);
      fechar();
      if (op.aoAplicar) op.aoAplicar();
      else setTimeout(function () { global.location.reload(); }, 700);
    }

    async function criarNova() {
      var nome = (prompt('Nome para a nova versão — fotografa o convite tal como está:', '') || '').trim();
      if (!nome) return;
      if (!(await garantirGravado())) return;
      var d = await correr(function () {
        return api(q('versao_criar'), { method: 'POST', body: JSON.stringify({ nome: nome, ambito: ambito }) });
      });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível guardar a versão.');
      await recarregar();
      dizer('Versão guardada: ' + nome);
    }

    async function aplicar(id) {
      var v = porId(id); if (!v) return;
      var oQueFica = v.padrao
        ? 'A peça volta a ser como veio de origem — é o que os convidados passam a receber. As versões que guardou não se perdem.'
        : 'A peça passa a ser como estava quando a guardou — é o que os convidados passam a receber.';
      var aviso = sujo()
        ? 'Tem alterações por gravar.\n\nPôr «' + v.nome + '» em vigor descarta-as. Continuar?'
        : 'Pôr «' + v.nome + '» em vigor?\n\n' + oQueFica;
      if (!confirm(aviso)) return;
      var d = await correr(function () { return api(q('versao_aplicar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível aplicar a versão.');
      depoisDeAplicar('Versão em vigor: ' + v.nome + '. A recarregar…');
    }

    async function atualizar(id) {
      var v = porId(id); if (!v) return;
      if (!confirm('Atualizar «' + v.nome + '» com o convite tal como está agora?\n\nO conteúdo antigo da versão perde-se.')) return;
      if (!(await garantirGravado())) return;
      var d = await correr(function () { return api(q('versao_atualizar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível atualizar a versão.');
      await recarregar();
      dizer('Versão atualizada: ' + v.nome);
    }

    async function renomear(id) {
      var v = porId(id); if (!v) return;
      var novo = prompt('Novo nome para a versão:', v.nome);
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
      var v = porId(id); if (!v) return;
      var extra = v.em_vigor
        ? '\n\nÉ a versão que está em vigor. A peça não muda — perde-se só o registo de que este era o estado guardado.'
        : '\n\nA peça não muda; perde-se apenas este ponto de regresso.';
      if (!confirm('Apagar a versão «' + v.nome + '»?' + extra)) return;
      var d = await correr(function () { return api(q('versao_apagar', '&id=' + id)); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível apagar a versão.');
      await recarregar();
      dizer('Versão apagada.');
    }

    async function aplicarModelo(id) {
      var m = modelos.filter(function (x) { return String(x.id) === String(id); })[0];
      if (!m) return;
      if (!confirm('Pôr o modelo «' + m.nome + '» em vigor?\n\n'
        + 'O desenho da peça passa a ser o dele — é o que os convidados passam a receber. '
        + 'Os seus nomes, datas e fotografias ficam como estão, e as versões que guardou não se perdem.'
        + (sujo() ? '\n\nAs alterações por gravar perdem-se.' : ''))) return;
      var d = await correr(function () { return api('modelo_aplicar&id=' + id, { method: 'POST' }); });
      if (!d || !d.success) return dizer((d && d.message) || 'Não foi possível pôr o modelo em vigor.');
      // Quando o desenho não muda (o modelo era igual ao que já lá estava),
      // não se recarrega a página — mas o modelo em vigor MUDOU: passou a ser
      // este. Recarregar só o painel move a marca «em vigor» para ele e o botão
      // da barra passa a dizer o seu nome. É o feedback que faltava — antes
      // parecia que a ação não tinha feito nada.
      if (d.mudou === false) {
        await recarregar();
        return dizer('«' + m.nome + '» em vigor. O desenho é igual ao que já tinha.');
      }
      depoisDeAplicar('Modelo em vigor: ' + m.nome + '. A recarregar…');
    }

    bt.addEventListener('click', function () { abrir(); });
    addEventListener('resize', escalarProvas);

    pintarBotao();
    recarregar();
    return { recarregar: recarregar, abrir: abrir };
  }

  global.Versoes = { montar: montar };
})(window);
