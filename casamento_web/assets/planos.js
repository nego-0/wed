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
  var PECA_ORIGEM = '';                    // o modelo de onde as secções vêm
  var FOTO_MAX_MB = 5;                     // o que o servidor aceita por fotografia
  var PRAZOS = [];                         // prazos de licença, com o seu factor
  // 'prazo' são os meses escolhidos; é ele que multiplica todos os preços.
  // 'aMedida' diz se o casal abriu a secção dos módulos avulso. Fechada por
  // omissão: a maioria leva um pacote, e cinco módulos abertos à partida faziam
  // da página um rolo de cinco mil pixéis onde o botão de submeter se perdia.
  var sel = { pacote: 0, escaloes: {}, fotos: {}, prazo: 0, aMedida: false };

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

  /**
   * O que o mesmo plano custaria SEM desconto de prazo — a conta a régua e
   * esquadro: o preço do prazo mais curto, vezes quantas vezes ele cabe no
   * prazo escolhido.
   *
   * É este o número que se risca. E é um número honesto: 24 meses ao preço de
   * 6 meses seriam mesmo quatro vezes o preço de 6 meses. O que o casal poupa
   * é a diferença entre isso e o que paga.
   */
  function precoPadrao(v) {
    var p = prazoAtual(), base = prazoBase();
    if (!p || !base || p.meses === base.meses) return null;
    return Math.round((Number(v) || 0) * (base.fator * p.meses / base.meses) * 100) / 100;
  }

  /** O prazo de referência: o mais curto, que é onde o preçário está escrito. */
  function prazoBase() {
    if (!PRAZOS.length) return null;
    return PRAZOS.reduce(function (a, x) { return x.meses < a.meses ? x : a; }, PRAZOS[0]);
  }

  /** Quanto por cento se poupa, neste prazo, por não pagar o proporcional. */
  function descontoPct() {
    var p = prazoAtual(), base = prazoBase();
    if (!p || !base || p.meses === base.meses) return 0;
    var proporcional = base.fator * p.meses / base.meses;
    return Math.round((1 - p.fator / proporcional) * 100);
  }

  /**
   * Um preço, com o proporcional riscado ao lado quando há desconto de prazo.
   * Sem desconto (prazo base), sai só o preço — riscar um número igual ao que
   * está ao lado não é uma promoção, é ruído.
   */
  function precoComDesconto(v, cls) {
    var pad = precoPadrao(v);
    var agora = comPrazo(v);
    var h = '<span class="' + (cls || 'pl-esc-preco') + '">' + esc(moeda(agora)) + '</span>';
    if (pad !== null && pad > agora + 0.5) {
      h = '<span class="pl-riscado">' + esc(moeda(pad)) + '</span>' + h;
    }
    return h;
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

  /**
   * Quanto se desconta a este escalão pelo que já se tem no mesmo módulo.
   *
   * Subir de «até 80 convidados» para «até 200» é pagar o degrau — não é
   * comprar a lista outra vez. O servidor faz esta mesma conta ao registar o
   * pedido; aqui repete-se para o número que o casal vê ser o que vai pagar.
   * (Nunca passa do preço do escalão: descer não devolve dinheiro.)
   */
  function credito(e) {
    var t = TENHO[e.modulo];
    if (!t || !t.ativo) return 0;
    return Math.min(+e.preco || 0, +t.credito || 0);
  }

  /** O que este escalão custa a ESTE casamento, já com o que ele tem descontado. */
  function precoLiquido(e) { return (+e.preco || 0) - credito(e); }

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

  /**
   * Marca os módulos obrigatórios que ainda faltam, no escalão mais barato.
   *
   * Corre ao montar a página e sempre que a secção à medida se abre: escolher
   * um pacote limpa a escolha à peça, e sem isto quem voltasse aos módulos
   * encontrava o obrigatório por marcar — um plano que o servidor ia recusar.
   */
  function preSelecionarObrigatorios() {
    CAT.modulos.forEach(function (m) {
      if (!m.ativo || !m.obrigatorio) return;
      if (sel.escaloes[m.chave]) return;
      var t = TENHO[m.chave];
      if (t && t.ativo) return;
      var livres = m.escaloes.filter(function (e) { return e.ativo && !jaTem(e); });
      if (livres.length) sel.escaloes[m.chave] = livres[0].id;
    });
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
        // Riscado: o que este mesmo pacote custaria sem o desconto do prazo.
        // (A poupança de comprar em pacote, em vez de à peça, vem a seguir.)
        +   (precoPadrao(p.preco) !== null && precoPadrao(p.preco) > comPrazo(p.preco) + 0.5
              ? '<span class="pl-antes">' + esc(moeda(precoPadrao(p.preco))) + '</span>' : '')
        + '</div>'
        + (p.poupanca > 0
            ? '<span class="pl-poupa">Poupa ' + esc(moeda(comPrazo(p.poupanca)))
              + ' face à compra à peça</span>' : '')
        + '<div class="pl-prazo">' + (prazoAtual() ? prazoAtual().meses : p.meses)
        +   ' meses de licença</div>'
        + '<ul class="pl-inclui">' + linhas.join('') + '</ul>'
        + '<div class="pl-pac-ac">'
        +   '<button type="button" class="btn ' + (sel.pacote === p.id ? 'btn-ouro' : 'btn-linha') + ' pl-escolher">'
        +     (sel.pacote === p.id ? '✓ Escolhido' : 'Escolher ' + esc(p.nome)) + '</button>'
        // Ver o que este pacote traz, em imagens — uma por módulo incluído.
        +   '<button type="button" class="pl-exemplo-lig" data-exemplo-pac="' + p.id + '">'
        +     'Ver exemplo</button>'
        + '</div>'
        + '</div>';
    });
    return h + '</div>';
  }

  /**
   * A porta para o plano à medida.
   *
   * Fechada por omissão. Os módulos todos abertos faziam da inscrição uma
   * página de cinco mil pixéis, com o botão de submeter perdido no fundo — e um
   * formulário que não se vê é um formulário que não avança. Quem quer montar o
   * seu plano abre-a; quem leva um pacote nem dá por ela.
   */
  function desenharAbrirMedida() {
    var n = 0;
    CAT.modulos.forEach(function (m) { if (m.ativo && m.escaloes.some(function (e) { return e.ativo; })) n++; });
    if (!n) return '';
    if (sel.aMedida) return '';
    return '<div class="pl-medida-porta">'
      + '<div class="pl-medida-porta-txt">'
      +   '<b>Nenhum destes serve? Monte o seu.</b>'
      +   '<span>Escolha módulo a módulo e pague só o que levar. '
      +   'São ' + n + ' módulos à escolha, e pode reforçar mais tarde sem perder nada.</span>'
      + '</div>'
      + '<button type="button" class="btn btn-linha" id="pl-abrir-medida">'
      +   'Montar o meu pacote</button>'
      + '</div>';
  }

  function desenharMedida() {
    // Só se desenha depois de aberta — ver desenharAbrirMedida().
    if (!sel.aMedida) return '';
    var h = '<div class="pl-medida"><div class="pl-medida-cab">'
          + '<div><h3>O seu pacote, à medida</h3>'
          + '<div class="dica">Leve só o que precisa. Pode reforçar mais tarde, sem perder nada do que já fez.</div></div>'
          + (COM_PACOTES ? '<button type="button" class="btn btn-fantasma btn-sm" id="pl-fechar-medida">'
             + 'Voltar aos pacotes</button>' : '')
          + '</div>';
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
         + '</div>'
         // A captura do módulo a trabalhar, atrás de um botão. Mostrada de
         // enfiada, empurrava tudo para baixo; escondida atrás de um botão,
         // continua a um clique de quem a quer ver — e não estorva quem não.
         + (m.imagem
             ? '<button type="button" class="btn btn-linha btn-sm pl-exemplo"'
               + ' data-exemplo="' + esc(m.chave) + '">Ver exemplo</button>'
             : '')
         + '</div>';
      h += '<div class="pl-escs">';

      // «Não levar» é uma escolha como as outras — excepto num módulo
      // obrigatório que ainda não se tem, onde não é escolha nenhuma: oferecer
      // uma opção que o servidor vai recusar é convidar ao erro.
      //
      // Num reforço a coisa inverte-se: o módulo obrigatório JÁ está na
      // licença, o servidor aceita um pedido sem ele, e o casal tem de o poder
      // deixar de fora — senão marca-o por engano, não tem como desmarcar, e
      // fica a pagar um degrau que não queria. Aqui, «Não levar» quer dizer
      // «fica como está».
      var tenhoJa = TENHO[m.chave] && TENHO[m.chave].ativo;
      if (m.obrigatorio && !tenhoJa) {
        h += '<div class="pl-obrig">Incluído em todos os planos — é a base de que o '
           + 'resto depende. Escolha a medida.</div>';
      } else {
        var nada = !sel.escaloes[m.chave];
        h += '<label class="pl-esc' + (nada ? ' on' : '') + '">'
           + '<input type="radio" name="pl-' + esc(m.chave) + '" value="0"' + (nada ? ' checked' : '') + '>'
           + '<span><span class="pl-esc-nome">'
           + (tenhoJa ? 'Deixar como está' : 'Não levar') + '</span>'
           + '<span class="pl-esc-res">'
           + (tenhoJa ? 'Não mexe no que já tem, e não entra na conta.'
                      : 'Fica de fora deste plano.')
           + '</span></span></label>';
      }

      escs.forEach(function (e) {
        var tem = jaTem(e);
        var on = sel.escaloes[m.chave] === e.id;
        // Com crédito, o preço que se mostra é o degrau — e diz-se de onde vem.
        // Um «16 000» sem explicação ao lado de um preçário que diz «28 000»
        // parece um erro; com a linha, é a promessa a cumprir-se à vista.
        var cr = credito(e);
        var preco = cr > 0
          ? precoComDesconto(e.preco - cr)
            + '<span class="pl-esc-credito">já tem <b>' + esc(moeda(comPrazo(cr)))
            + '</b> pagos neste módulo</span>'
          : precoComDesconto(e.preco);
        h += '<label class="pl-esc' + (on ? ' on' : '') + (tem ? ' tem' : '') + '">'
           + '<input type="radio" name="pl-' + esc(m.chave) + '" value="' + e.id + '"'
           +   (on ? ' checked' : '') + (tem ? ' disabled' : '') + '>'
           + '<span><span class="pl-esc-nome">' + esc(e.nome) + '</span>'
           + (e.resumo ? '<span class="pl-esc-res">' + esc(e.resumo) + '</span>' : '')
           + (tem ? '<span class="pl-esc-tem">✓ Já tem</span>' : preco)
           + '</span></label>';
      });
      h += '</div>';
      if (m.chave === 'digital') h += desenharFotos();
      h += '</div>';
    });
    return h + '</div>';
  }

  /**
   * O escalão de convite digital que o plano actual traz, ou null.
   *
   * Num pacote é o pacote que o traz; à peça, é a escolha do módulo. As duas
   * respostas contam, porque as fotografias pedem-se em ambos os casos.
   */
  function escalaoDigital() {
    var achado = null;
    if (sel.pacote) {
      var pk = null;
      CAT.pacotes.forEach(function (x) { if (x.id === sel.pacote) pk = x; });
      if (pk) pk.itens.forEach(function (id) {
        var e = escalao(id); if (e && e.modulo === 'digital') achado = e;
      });
      return achado;
    }
    var id = sel.escaloes['digital'];
    return id ? escalao(id) : null;
  }

  /**
   * As fotografias de cada secção do convite digital.
   *
   * Só aparecem quando o casal leva o convite digital, e as secções são as da
   * PEÇA DE ORIGEM — o desenho com que o convite dele vai nascer.
   *
   * Duas escolhas por secção: uma da galeria da casa, ou uma FOTOGRAFIA SUA. E
   * a diferença entre os escalões está aqui, inteira: com edição, isto é um
   * adianto e troca-se depois; SEM edição, é a única vez que o casal escolhe, e
   * por isso a fotografia própria passa de comodidade a obrigação — um convite
   * que não se pode editar, feito com a fotografia de outro casal, fica assim
   * para sempre.
   */
  function desenharFotos() {
    var e = escalaoDigital();
    if (!e || !SECCOES.length) return '';
    var podeEditar = !!e.editar;

    var h = '<div class="pl-fotos"' + (podeEditar ? '' : ' data-exige="1"') + '>'
      + '<div class="pl-fotos-cab"><b>As fotografias do vosso convite</b>'
      + '<span>Uma por secção d' + (PECA_ORIGEM ? 'o modelo «' + esc(PECA_ORIGEM) + '»'
                                                : 'o convite')
      + '. Da galeria da casa, ou vossas.</span></div>';

    h += podeEditar
      ? '<div class="pl-fotos-nota boa">Com <b>edição</b>: estas são as fotografias com que '
        + 'o convite nasce, e podem trocá-las por outras — ou pelas vossas — sempre que '
        + 'quiserem. Enviar as vossas agora é só adiantar trabalho.</div>'
      : '<div class="pl-fotos-nota aviso"><b>Atenção:</b> o escalão «' + esc(e.nome) + '» é '
        + '<b>sem edição</b>. Estas fotografias ficam fixas no vosso convite e <b>não poderão '
        + 'ser alteradas</b> depois — por isso é preciso <b>enviarem já as vossas</b>, uma '
        + 'para cada secção. Para as poder trocar mais tarde, escolham um escalão com edição.</div>';

    SECCOES.forEach(function (sc) {
      var minha = MINHAS[sc.chave];
      var escolhida = sel.fotos[sc.chave] || (sc.fotos[0] && sc.fotos[0].src);
      h += '<div class="pl-sec" data-sec="' + esc(sc.chave) + '"><div class="pl-sec-cab">'
         + '<b>' + esc(sc.rotulo) + '</b><span>' + esc(sc.descricao) + '</span>'
         + (!podeEditar && !minha ? '<span class="pl-sec-falta">falta a vossa</span>' : '')
         + '</div>';

      // A fotografia do casal, quando já foi enviada: a prova com marca de água.
      if (minha) {
        h += '<div class="pl-minha">'
           + '<img src="' + esc(minha.prova) + '" alt="A vossa fotografia, em prova">'
           + '<div class="pl-minha-txt"><b>A vossa fotografia</b>'
           + '<span>' + esc(minha.nome) + '</span></div>'
           + '<div class="pl-minha-acoes">'
           + '<button type="button" class="pl-btn-ver" data-sec="' + esc(sc.chave) + '">Ver maior</button>'
           + '<button type="button" class="pl-btn-tirar" data-sec="' + esc(sc.chave) + '">Trocar</button>'
           + '</div></div>';
      } else {
        h += '<div class="pl-envio">'
           + '<button type="button" class="pl-btn-env" data-sec="' + esc(sc.chave) + '">'
           + '＋ Enviar a nossa fotografia</button>'
           + '<span class="pl-envio-nota">jpg, png ou webp · até ' + FOTO_MAX_MB + ' MB'
           + (podeEditar ? ' · opcional' : '') + '</span>'
           + '</div>';
        // A galeria da casa é a ALTERNATIVA a mandar a sua — e por isso só
        // aparece onde há alternativa. No escalão sem edição a fotografia do
        // casal é obrigatória: oferecer-lhe ali uma da casa era oferecer-lhe
        // uma escolha que ele não tem.
        if (sc.fotos.length && podeEditar) {
          h += '<div class="pl-sec-ou">ou uma da galeria da casa</div><div class="pl-sec-tiras">';
          sc.fotos.forEach(function (ft) {
            h += '<label class="pl-ft' + (ft.src === escolhida ? ' on' : '') + '"'
               + ' title="' + esc(ft.nome) + '">'
               + '<input type="radio" name="ft-' + esc(sc.chave) + '" value="' + esc(ft.src) + '"'
               + (ft.src === escolhida ? ' checked' : '') + '>'
               + '<img src="' + esc(ft.src) + '" alt="' + esc(ft.nome) + '"'
               + ' loading="lazy" decoding="async">'
               + '<span class="pl-ft-visto">✓</span></label>';
          });
          h += '</div>';
        }
      }
      h += '</div>';
    });
    return h + '</div>';
  }

  // ---------- as fotografias do casal ----------
  //
  // Ficam do lado do servidor (presas à sessão) e aqui guarda-se só o que é
  // preciso para as mostrar: o nome do ficheiro e o endereço da PROVA — a
  // fotografia encolhida e atravessada pela marca de água. O ficheiro em si
  // nunca volta: antes de a licença estar fechada, quem enviou vê o
  // enquadramento e não recebe de volta uma fotografia pronta a usar.
  var MINHAS = {};

  /** Um aviso junto ao próprio botão: é onde o olho está quando ele falha. */
  function avisoFoto(botao, texto) {
    var sec = botao.closest('.pl-sec'); if (!sec) return;
    var av = sec.querySelector('.pl-envio-erro');
    if (!av) {
      av = document.createElement('div');
      av.className = 'pl-envio-erro';
      (sec.querySelector('.pl-envio') || sec).appendChild(av);
    }
    av.textContent = texto;
  }

  /** Pede um ficheiro ao utilizador e envia-o para a secção indicada. */
  function enviarFoto(chave, aoFim) {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/jpeg,image/png,image/webp';
    inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      document.body.removeChild(inp);
      if (!f) return;
      if (f.size > FOTO_MAX_MB * 1048576) {
        aoFim('A fotografia tem mais de ' + FOTO_MAX_MB + ' MB. Escolham uma mais leve.');
        return;
      }
      var fd = new FormData();
      fd.append('chave', chave);
      fd.append('ficheiro', f);
      fetch('api.php?action=registo_foto', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.success) { aoFim((d && d.message) || 'Não foi possível enviar a fotografia.'); return; }
          MINHAS[chave] = { nome: d.nome, prova: d.prova };
          aoFim(null);
        })
        .catch(function () { aoFim('Não foi possível falar com o servidor.'); });
    });
    inp.click();
  }

  /** Larga uma fotografia enviada (ou todas, sem chave). */
  function tirarFoto(chave, aoFim) {
    fetch('api.php?action=registo_foto_tirar', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chave: chave || '' })
    }).then(function () {
      if (chave) delete MINHAS[chave]; else MINHAS = {};
      if (aoFim) aoFim();
    }).catch(function () { if (aoFim) aoFim(); });
  }

  /** A prova em grande, para o casal ver o que enviou. */
  function verProva(chave) {
    var m = MINHAS[chave]; if (!m) return;
    var cx = document.getElementById('pl-prova');
    if (!cx) {
      cx = document.createElement('div');
      cx.id = 'pl-prova'; cx.className = 'pl-prova';
      cx.innerHTML = '<div class="pl-prova-cx" role="dialog" aria-modal="true" aria-label="A vossa fotografia">'
        + '<img alt="A vossa fotografia, com marca de água">'
        + '<p>É assim que a fotografia entra no convite. A marca de água é só desta '
        + 'pré-visualização — o convite sai sem ela.</p>'
        + '<button type="button" class="btn btn-fantasma btn-sm">Fechar</button></div>';
      document.body.appendChild(cx);
      cx.addEventListener('click', function (ev) {
        if (ev.target === cx || ev.target.tagName === 'BUTTON') cx.classList.remove('on');
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') cx.classList.remove('on');
      });
    }
    cx.querySelector('img').src = m.prova;
    cx.classList.add('on');
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
    ALVO.innerHTML = desenharPrazos() + desenharPacotes()
                   + desenharAbrirMedida() + desenharMedida() + desenharConta();
    ligar();
    conferirFotos();
    if (typeof AO_MUDAR === 'function') AO_MUDAR(Planos.escolha());
  }

  /**
   * O plano deixou de trazer o convite digital: as fotografias que o casal
   * mandou para ele ficam sem destino, e largam-se já — do ecrã e do servidor.
   * Guardá-las à espera de que ele mude outra vez de ideias era guardar
   * fotografias de alguém sem razão nenhuma para o fazer.
   */
  function conferirFotos() {
    var tem = false;
    for (var k in MINHAS) if (Object.prototype.hasOwnProperty.call(MINHAS, k)) { tem = true; break; }
    if (!tem || escalaoDigital()) return;
    tirarFoto('', pintar);   // MINHAS fica vazio: a repintura não volta aqui
  }

  function ligar() {
    ALVO.querySelectorAll('[data-pacote]').forEach(function (el) {
      function tocar() {
        var id = parseInt(el.dataset.pacote, 10);
        // Clicar no que já está escolhido desfaz: é a única forma de voltar
        // ao plano à medida sem recarregar a página.
        sel.pacote = (sel.pacote === id) ? 0 : id;
        if (sel.pacote) { sel.escaloes = {}; sel.aMedida = false; }
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
    // Enviar, ver e trocar a fotografia do casal. Repinta-se só no fim: durante
    // o envio o botão diz o que está a fazer, para não parecer que não fez nada.
    ALVO.querySelectorAll('.pl-btn-env').forEach(function (b) {
      b.addEventListener('click', function () {
        var rot = b.textContent;
        b.disabled = true; b.textContent = 'A enviar…';
        enviarFoto(b.dataset.sec, function (erro) {
          b.disabled = false; b.textContent = rot;
          if (erro) { avisoFoto(b, erro); return; }
          pintar();
          if (typeof AO_MUDAR === 'function') AO_MUDAR(Planos.escolha());
        });
      });
    });
    ALVO.querySelectorAll('.pl-btn-ver').forEach(function (b) {
      b.addEventListener('click', function () { verProva(b.dataset.sec); });
    });
    ALVO.querySelectorAll('.pl-btn-tirar').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        tirarFoto(b.dataset.sec, function () {
          pintar();
          if (typeof AO_MUDAR === 'function') AO_MUDAR(Planos.escolha());
        });
      });
    });

    // A porta do plano à medida, nos dois sentidos.
    var abrir = ALVO.querySelector('#pl-abrir-medida');
    if (abrir) abrir.addEventListener('click', function () {
      sel.aMedida = true; sel.pacote = 0;   // à medida e pacote não coexistem
      preSelecionarObrigatorios();          // o pacote tinha limpado a escolha
      pintar();
      var cx = ALVO.querySelector('.pl-medida');
      if (cx) cx.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    var fechar = ALVO.querySelector('#pl-fechar-medida');
    if (fechar) fechar.addEventListener('click', function () {
      sel.aMedida = false;
      pintar();
      var cx = ALVO.querySelector('.pl-pacotes');
      if (cx) cx.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // «Ver exemplo» de um módulo, e de um pacote (a galeria dos seus módulos).
    ALVO.querySelectorAll('[data-exemplo]').forEach(function (el) {
      el.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        var m = modulo(el.dataset.exemplo);
        if (m && m.imagem) galeria([{ src: m.imagem, titulo: m.nome, nota: m.resumo }], 0);
      });
    });
    ALVO.querySelectorAll('[data-exemplo-pac]').forEach(function (el) {
      el.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();   // não escolhe o pacote
        var id = parseInt(el.dataset.exemploPac, 10);
        var p = null;
        CAT.pacotes.forEach(function (x) { if (x.id === id) p = x; });
        if (!p) return;
        var vistos = {}, imgs = [];
        p.itens.forEach(function (eid) {
          var e = escalao(eid);
          if (!e || vistos[e.modulo]) return;
          vistos[e.modulo] = true;
          var m = modulo(e.modulo);
          if (m && m.imagem) imgs.push({ src: m.imagem, titulo: m.nome, nota: medida(e) || m.resumo });
        });
        if (imgs.length) galeria(imgs, 0, 'Pacote ' + p.nome);
      });
    });
  }

  /**
   * As imagens em grande — uma, ou a galeria de um pacote inteiro.
   *
   * É o que está por trás de cada «Ver exemplo»: o casal vê exactamente o que
   * vai comprar, sem que as capturas ocupem a página toda enquanto ele decide.
   */
  var GAL = { imgs: [], i: 0, titulo: '' };
  function galeria(imgs, i, titulo) {
    GAL = { imgs: imgs || [], i: i || 0, titulo: titulo || '' };
    var m = document.getElementById('pl-lupa');
    if (!m) {
      m = document.createElement('div');
      m.id = 'pl-lupa'; m.className = 'pl-modal pl-modal-img';
      m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
      document.body.appendChild(m);
      m.addEventListener('click', function (ev) {
        if (ev.target === m) fecharGaleria();
      });
    }
    pintarGaleria();
    m.classList.add('on');
    document.addEventListener('keydown', teclaGaleria);
  }
  function pintarGaleria() {
    var m = document.getElementById('pl-lupa');
    if (!m || !GAL.imgs.length) return;
    var it = GAL.imgs[GAL.i], varias = GAL.imgs.length > 1;
    m.innerHTML = '<figure class="pl-lupa-cx">'
      + '<div class="pl-lupa-topo">'
      +   '<span>' + esc(GAL.titulo || it.titulo)
      +   (varias ? ' <small>' + (GAL.i + 1) + ' de ' + GAL.imgs.length + '</small>' : '') + '</span>'
      +   '<button type="button" class="pl-lupa-x" id="pl-lupa-fechar" aria-label="Fechar">×</button>'
      + '</div>'
      + '<img src="' + esc(it.src) + '" alt="' + esc(it.titulo) + '">'
      + '<figcaption><b>' + esc(it.titulo) + '</b>'
      +   (it.nota ? ' — ' + esc(it.nota) : '') + '</figcaption>'
      + (varias
          ? '<div class="pl-lupa-nav">'
            + '<button type="button" id="pl-lupa-ant" aria-label="Anterior">‹ Anterior</button>'
            + '<button type="button" id="pl-lupa-seg" aria-label="Seguinte">Seguinte ›</button>'
            + '</div>'
          : '')
      + '</figure>';
    m.querySelector('#pl-lupa-fechar').onclick = fecharGaleria;
    var a = m.querySelector('#pl-lupa-ant'), b2 = m.querySelector('#pl-lupa-seg');
    if (a) a.onclick = function () { GAL.i = (GAL.i - 1 + GAL.imgs.length) % GAL.imgs.length; pintarGaleria(); };
    if (b2) b2.onclick = function () { GAL.i = (GAL.i + 1) % GAL.imgs.length; pintarGaleria(); };
  }
  function teclaGaleria(ev) {
    if (ev.key === 'Escape') { fecharGaleria(); return; }
    if (!GAL.imgs.length || GAL.imgs.length < 2) return;
    if (ev.key === 'ArrowLeft')  { GAL.i = (GAL.i - 1 + GAL.imgs.length) % GAL.imgs.length; pintarGaleria(); }
    if (ev.key === 'ArrowRight') { GAL.i = (GAL.i + 1) % GAL.imgs.length; pintarGaleria(); }
  }
  function fecharGaleria() {
    var m = document.getElementById('pl-lupa');
    if (m) m.classList.remove('on');
    document.removeEventListener('keydown', teclaGaleria);
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
      PECA_ORIGEM = opcoes.pecaOrigem || '';
      FOTO_MAX_MB = opcoes.fotoMaxMb || 5;
      PRAZOS  = (CAT.prazos || []).slice();
      sel = { pacote: 0, escaloes: {}, fotos: {}, prazo: opcoes.prazo || 0 };
      // Uma montra nova começa sem fotografias: as da anterior já foram
      // entregues ao casamento que se criou, ou largadas com ele.
      MINHAS = {};

      preSelecionarObrigatorios();
      // Sem nada escolhido, começa-se pelo pacote em destaque: é o que serve a
      // maioria, e uma montra que não sugere nada obriga a decidir do zero.
      if (opcoes.sugerir !== false && COM_PACOTES) {
        CAT.pacotes.forEach(function (p) { if (p.ativo && p.destaque) sel.pacote = p.id; });
      }
      // Sem pacotes (um reforço, ou um preçário só de módulos) não há porta a
      // abrir: os módulos são a única coisa que há, e mostram-se logo.
      if (!COM_PACOTES || !CAT.pacotes.some(function (p) { return p.ativo; })) sel.aMedida = true;
      pintar();
      return Planos;
    },

    /**
     * A escolha actual inclui este módulo?
     *
     * Quem pergunta é a página que tem campos a depender dele — o registo
     * esconde a conta do porteiro enquanto o plano não trouxer a «porta». Serve
     * tanto o pacote como a escolha à peça, porque escolha() já resolve os dois.
     */
    temModulo: function (chave) {
      var tem = false;
      (this.escolha().escaloes || []).forEach(function (id) {
        var e = escalao(id); if (e && e.modulo === chave) tem = true;
      });
      return tem;
    },

    /**
     * O que falta em fotografias, ou null quando está tudo bem.
     *
     * Só o escalão SEM edição as exige: é a única vez em que o casal as
     * escolhe, e um convite que não se pode editar feito com a fotografia de
     * outro casal fica assim para sempre. Com edição não falta nada — as que
     * não vierem ficam com o desenho da casa e trocam-se depois.
     *
     * O servidor faz a mesma pergunta antes de criar o que quer que seja: isto
     * é para se responder ANTES de submeter, e não com um erro no fim.
     */
    faltamFotos: function () {
      var e = escalaoDigital();
      if (!e || e.editar) return null;
      var faltam = [];
      SECCOES.forEach(function (sc) { if (!MINHAS[sc.chave]) faltam.push(sc.rotulo); });
      if (!faltam.length) return null;
      return 'O escalão «' + e.nome + '» é sem edição: as fotografias ficam fixas no convite. '
           + 'Envie' + (faltam.length > 1 ? ' as' : ' a') + ' que falta'
           + (faltam.length > 1 ? 'm' : '') + ': ' + faltam.join(', ') + '.';
    },

    /** As secções para que o casal já enviou fotografia sua. */
    fotosProprias: function () { return Object.keys(MINHAS); },

    /** Larga todas as fotografias enviadas (o formulário foi submetido, ou desistiu-se). */
    largarFotos: function (aoFim) { tirarFoto('', aoFim); },

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
        ids.push(e.id); total += comPrazo(precoLiquido(e));
      });
      return { pacote: 0, escaloes: ids, meses: meses, total: total, fotos: fotos,
               vazio: ids.length === 0 };
    },

    /** Marca uma escolha vinda de fora (um pedido já submetido, a reabrir). */
    repor: function (pacoteId, escalaoIds, fotos, meses) {
      sel = { pacote: 0, escaloes: {}, fotos: fotos || {}, prazo: meses || 0,
              // A reabrir um pedido à medida, a secção abre-se com ele: senão
              // o casal via um plano «à medida» sem nada à vista.
              aMedida: !pacoteId };
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
