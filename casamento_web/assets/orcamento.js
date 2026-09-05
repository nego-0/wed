/* ============================================================
   orcamento.js — O curso das despesas, no ecrã

   Uma leitura só (orc_estado) traz tudo; as ações escrevem e devolvem o
   resumo já refeito. O servidor é que manda: numa visita de leitura recusa a
   escrita, e aqui limitamo-nos a esconder os botões para não convidar ao que
   iria bater no nariz na porta.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  };
  var PODE = !window.SO_VER_UI;        // pode mexer? (leitura de suporte não)
  var ORC = null;                      // o último estado recebido
  var MOEDA = 'Kz';
  var FATURA_ALVO = 0;                 // a despesa a que o próximo ficheiro se anexa

  function toast(m, mau) {
    var t = $('toast'); if (!t) return;
    t.textContent = m; t.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(function () { t.className = 'toast'; }, 2600);
  }
  window.toast = toast;               // api.js usa-o para os avisos

  // ---- dinheiro ----
  function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }
  function fmt(v) {
    var n = num(v);
    var casas = (Math.round(n * 100) % 100 === 0) ? 0 : 2;
    var s = n.toLocaleString('pt-PT', { minimumFractionDigits: casas, maximumFractionDigits: 2 });
    return s + ' ' + MOEDA;
  }
  function pct(parte, todo) { return todo > 0 ? Math.max(0, Math.min(100, (parte / todo) * 100)) : 0; }
  function paraCampo(v) { return window.Moeda ? window.Moeda.paraCampo(v) : String(num(v) || ''); }

  // ---- ver uma imagem em grande ----
  function maximizar(src) {
    if (!src) return;
    var ov = $('lightbox');
    ov.innerHTML = '<img src="' + String(src).replace(/"/g, '&quot;') + '" alt="">';
    ov.classList.add('on');
  }
  window.orcVerImagem = maximizar;
  document.addEventListener('click', function (ev) {
    if (ev.target && ev.target.id === 'lightbox') ev.target.classList.remove('on');
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { var o = $('lightbox'); if (o) o.classList.remove('on'); }
  });
  function ehImagem(src) { return /\.(jpe?g|png|webp)$/i.test(src || ''); }

  // ---- carregar ----
  async function carregar() {
    var d = await window.api('orc_estado', { method: 'GET' });
    if (!d || !d.success) return;
    ORC = d; MOEDA = d.moeda || 'Kz';
    render();
  }

  // Dois filtros, e cruzam-se: a gaveta (categoria) e o estado do dinheiro.
  // «Sem categoria» diz-se 'sem'; nos estados, null é tudo.
  var FILTRO_CAT = null;    // null = todas; 'sem' = sem categoria; senão o id (string)
  var FILTRO_EST = null;    // null = tudo; 'previsto' | 'pago' | 'atraso'

  /** Hoje, em ISO — é assim que as datas vêm do servidor. */
  function hojeISO() { return new Date().toISOString().slice(0, 10); }

  /** Uma parcela por liquidar cuja data já passou. */
  function emAtraso(p) {
    return !p.pago_em && p.data_prevista && p.data_prevista < hojeISO();
  }

  /** As despesas que têm parcelas em atraso — é por elas que o filtro pega. */
  function despesasEmAtraso() {
    var ids = {};
    (ORC.pagamentos || []).forEach(function (p) { if (emAtraso(p)) ids[p.despesa_id] = true; });
    return ids;
  }

  /** As despesas que passam pelos dois filtros. */
  function despesasFiltradas() {
    var atraso = FILTRO_EST === 'atraso' ? despesasEmAtraso() : null;
    return (ORC.despesas || []).filter(function (d) {
      if (FILTRO_CAT === 'sem' && d.categoria_id) return false;
      if (FILTRO_CAT != null && FILTRO_CAT !== 'sem' && +d.categoria_id !== +FILTRO_CAT) return false;
      if (FILTRO_EST === 'atraso') return !!atraso[d.id];
      if (FILTRO_EST != null && d.estado !== FILTRO_EST) return false;
      return true;
    });
  }

  /** E as parcelas — pelo estado delas, e pela gaveta da despesa a que pertencem. */
  function pagamentosFiltrados() {
    var cat = {};
    (ORC.despesas || []).forEach(function (d) { cat[d.id] = d.categoria_id; });
    return (ORC.pagamentos || []).filter(function (p) {
      if (FILTRO_CAT === 'sem' && cat[p.despesa_id]) return false;
      if (FILTRO_CAT != null && FILTRO_CAT !== 'sem'
          && +cat[p.despesa_id] !== +FILTRO_CAT) return false;
      if (FILTRO_EST === 'pago')     return !!p.pago_em;
      if (FILTRO_EST === 'previsto') return !p.pago_em;
      if (FILTRO_EST === 'atraso')   return emAtraso(p);
      return true;
    });
  }

  /** O nome do filtro em vigor, para o cabeçalho da lista dizer o que mostra. */
  function nomeDoEstado() {
    return { previsto: 'Por pagar', pago: 'Já pago', atraso: 'Em atraso' }[FILTRO_EST] || '';
  }

  function render() {
    CORES_CAT = {};
    (ORC.categorias || []).forEach(function (c) {
      if (c.cor) CORES_CAT[String(c.id)] = c.cor;
    });
    renderResumo(ORC.resumo);
    renderCatBar(ORC.categorias, ORC.sem_categoria);
    renderChips(ORC.categorias, ORC.sem_categoria);
    renderDespesas(ORC.despesas, ORC.categorias);
    renderPagamentos(ORC.pagamentos);
  }

  // ---- cor por categoria ----
  // Sugere-se sempre uma (paleta de dados, boa em claro e escuro, estável por
  // categoria); se o casal a trocou, é a dele que manda. CORES_CAT guarda as
  // escolhidas (id -> #hex), refrescado a cada leitura.
  var PALETA_CAT = ['#4C8C1E', '#2E86C8', '#B4864A', '#A5473F', '#7A5CA8', '#2F9E8F',
                    '#C98A2E', '#B24C7A', '#5B7BD6', '#6B8E23', '#8A5A2B', '#D0524B'];
  var CORES_CAT = {};
  function corSugerida(id) {
    var n = Math.abs(parseInt(id, 10)) || 0;
    return PALETA_CAT[n % PALETA_CAT.length];
  }
  function corCat(id) {
    if (id == null || id === '' || id === 'sem') return '#b9c2bb';
    return CORES_CAT[String(id)] || corSugerida(id);
  }

  // ---- saúde do orçamento: os cartões (que são o filtro) + a barra ----
  //
  // Os cartões não são só leitura: cada um é uma fatia das despesas, e clicar
  // nele mostra só essa fatia — na lista e no calendário. Os números diziam
  // «tem 40 000 por pagar» e deixavam a pessoa a procurar quais, à mão, numa
  // lista de trinta linhas.
  //
  // A margem até ao teto não é uma fatia de nada — é a diferença entre dois
  // números —, e por isso não está aqui: seria o único cartão que não filtrava.
  // Vive por baixo da barra, onde a folga já se lia na legenda.
  function renderResumo(r) {
    var atraso = despesasEmAtraso();
    var totalAtraso = (ORC.pagamentos || []).reduce(
      function (s, p) { return s + (emAtraso(p) ? num(p.valor) : 0); }, 0);
    var nAtraso = Object.keys(atraso).length;

    var kpis = [
      { est: null, n: r.base > 0 ? fmt(r.base) : 'sem teto', l: 'Orçamento', cls: '',
        dica: 'Ver todas as despesas' },
      { est: 'previsto', n: fmt(r.previsto), l: 'Por pagar', cls: 'ouro',
        dica: 'Ver só o que falta pagar' },
      { est: 'pago', n: fmt(r.pago), l: 'Já pago', cls: 'pago',
        dica: 'Ver só o que já saiu' },
      { est: 'atraso', n: fmt(totalAtraso), l: 'Em atraso', cls: 'atraso' + (nAtraso ? ' mau' : ''),
        dica: nAtraso ? 'Ver as ' + nAtraso + ' despesa(s) com parcelas vencidas'
                      : 'Nada vencido — nada para ver' }
    ];
    $('o-kpis').innerHTML = kpis.map(function (k) {
      var on = FILTRO_EST === k.est;
      var morto = k.est === 'atraso' && !nAtraso;
      return '<button type="button" class="kpi ' + k.cls + (on ? ' on' : '')
        + (morto ? ' morto' : '') + '" title="' + esc(k.dica) + '"'
        + ' aria-pressed="' + (on ? 'true' : 'false') + '"'
        + (morto ? ' disabled' : '')
        + ' onclick="orcFiltrarEstado(' + (k.est ? "'" + k.est + "'" : 'null') + ')">'
        + '<div class="n">' + k.n + '</div><div class="l">' + esc(k.l) + '</div></button>';
    }).join('');

    // A barra: pago + previsto (por pagar) sobre o maior de (teto, total a gastar).
    var totalDesp = r.pago + r.previsto;
    var denom = Math.max(r.base, totalDesp, 1);
    var segs = [['g-pago', r.pago, 'Pago'], ['g-prev', r.previsto, 'Por pagar']];
    $('o-barra').innerHTML = segs.map(function (s) {
      if (s[1] <= 0) return '';
      var w = pct(s[1], denom);
      return '<span class="' + s[0] + '" style="width:' + w + '%" title="' + s[2] + ': ' + esc(fmt(s[1])) + '">'
        + (w > 14 ? esc(fmt(s[1])) : '') + '</span>';
    }).join('');

    var leg = [['var(--o-pago)', 'Pago', r.pago], ['var(--o-prev)', 'Por pagar', r.previsto]];
    // A margem: uma leitura, e não uma gaveta — por isso não é cartão. Fica
    // aqui, ao pé da barra que a desenha, e é a mesma coisa que a folga que o
    // carril vazio mostra: dizê-la duas vezes era enchimento.
    if (r.base > 0) {
      leg.push(['var(--o-track)', r.falta >= 0 ? 'Margem até ao teto' : 'Acima do teto',
                Math.abs(r.falta), r.acima_do_teto]);
    }
    $('o-legenda').innerHTML = leg.map(function (l) {
      return '<span' + (l[3] ? ' class="mau"' : '') + '>'
        + (l[0] ? '<i style="background:' + l[0] + '"></i>' : '')
        + esc(l[1]) + ' <b>' + esc(fmt(l[2])) + '</b></span>';
    }).join('');
  }

  // ---- distribuição por categoria: soma dos reais (categorias + sem categoria) ----
  function itensCategoria(cats, semCat) {
    var itens = (cats || []).map(function (c) {
      return { id: String(c.id), nome: c.nome, val: num(c.real_total), cor: corCat(c.id) };
    });
    var semVal = semCat ? num(semCat.valor) : 0, semN = semCat ? +semCat.n : 0;
    if (semN > 0) itens.push({ id: 'sem', nome: 'Sem categoria', val: semVal, cor: corCat('sem'), semcat: true });
    var total = itens.reduce(function (s, x) { return s + x.val; }, 0);
    return { itens: itens, total: total };
  }

  // ---- a barra colorida (passar o rato mostra nome + valor + %) ----
  function renderCatBar(cats, semCat) {
    var box = $('o-catbar'); if (!box) return;
    var dd = itensCategoria(cats, semCat);
    var comValor = dd.itens.filter(function (x) { return x.val > 0; });
    if (dd.total <= 0) {
      box.innerHTML = '<div class="o-catbar-vazio">Sem despesas ainda. Lance a primeira para ver aqui a distribuição.</div>';
      return;
    }
    box.innerHTML = comValor.map(function (x) {
      var w = (x.val / dd.total) * 100, p = Math.round(w);
      return '<span class="seg" style="width:' + w + '%;background:' + x.cor + '" data-cat="' + esc(x.id) + '"'
        + ' title="' + esc(x.nome) + ': ' + esc(fmt(x.val)) + ' (' + p + '%)"'
        + ' onclick="orcFiltrar(\'' + esc(x.id) + '\')"></span>';
    }).join('');
  }

  // ---- as pastilhas de filtro, com o valor percentual ----
  function renderChips(cats, semCat) {
    var box = $('o-chips'); if (!box) return;
    var dd = itensCategoria(cats, semCat);
    if (!dd.itens.length) {
      box.innerHTML = '<div class="dica" style="margin:0">Ainda sem categorias — criam-se ao lançar uma despesa (botão «+ Despesa»).</div>';
      return;
    }
    var chips = ['<button class="chip-cat' + (FILTRO_CAT == null ? ' on' : '')
      + '" onclick="orcFiltrar(null)">Todas <b>' + esc(fmt(dd.total)) + '</b></button>'];
    dd.itens.forEach(function (x) {
      var p = dd.total > 0 ? Math.round((x.val / dd.total) * 100) : 0;
      chips.push('<button class="chip-cat' + (FILTRO_CAT === x.id ? ' on' : '') + '" onclick="orcFiltrar(\'' + esc(x.id) + '\')">'
        + '<i style="background:' + x.cor + '"></i>' + esc(x.nome) + ' <span class="pc">' + p + '%</span></button>');
    });
    box.innerHTML = chips.join('');
  }

  // ---- clicar filtra: a gaveta pelas pastilhas, o estado pelos cartões ----
  // Os dois cruzam-se, e ambos repintam as duas listas: quem escolheu «Por
  // pagar» quer ver as despesas por pagar E as parcelas por liquidar.
  function repintarFiltrado(rolarPara) {
    renderResumo(ORC.resumo);
    renderChips(ORC.categorias, ORC.sem_categoria);
    renderDespesas(ORC.despesas, ORC.categorias);
    renderPagamentos(ORC.pagamentos);
    var alvo = rolarPara && $(rolarPara);
    if (alvo) alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  window.orcFiltrar = function (cat) {
    FILTRO_CAT = (cat == null || cat === 'null') ? null : String(cat);
    repintarFiltrado(FILTRO_CAT != null ? 'lista-despesas' : null);
  };

  /** Um cartão de estatística escolhido — ou o mesmo outra vez, que desfaz. */
  window.orcFiltrarEstado = function (est) {
    FILTRO_EST = (est == null || FILTRO_EST === est) ? null : String(est);
    // «Em atraso» é sobre datas, e as datas vivem no calendário: é para lá que
    // se olha primeiro. Os outros dois são sobre despesas.
    repintarFiltrado(FILTRO_EST === 'atraso' ? 'lista-pagamentos'
                   : (FILTRO_EST ? 'lista-despesas' : null));
  };

  /** Larga tudo o que estiver a filtrar. */
  window.orcLimparFiltros = function () {
    FILTRO_CAT = null; FILTRO_EST = null;
    repintarFiltrado(null);
  };

  /**
   * A tira que diz o que a lista está a mostrar, e como sair dela.
   *
   * Uma lista encolhida sem dizer porquê é uma lista avariada: quem chega e vê
   * três despesas onde tinha trinta precisa de ler o motivo na própria lista, e
   * não de se lembrar do cartão em que carregou.
   */
  function tiraFiltro(quantos, valor, nomeCat) {
    if (FILTRO_CAT == null && FILTRO_EST == null) return '';
    var partes = [];
    if (FILTRO_CAT != null) {
      partes.push('<span class="o-filtro-cat"><i style="background:' + corCat(FILTRO_CAT)
        + '"></i>' + esc(nomeCat) + '</span>');
    }
    if (FILTRO_EST != null) {
      partes.push('<span class="o-filtro-est ' + FILTRO_EST + '">' + esc(nomeDoEstado()) + '</span>');
    }
    return '<div class="o-filtro">' + partes.join('')
      + '<b>' + esc(fmt(valor)) + '</b> · ' + quantos
      + '<button class="mini" onclick="orcLimparFiltros()">&times; limpar</button></div>';
  }

  // ---- despesas ----
  function celaFatura(d) {
    if (d.fatura) {
      var abrir = ehImagem(d.fatura)
        ? '<img class="fat-thumb" src="' + esc(d.fatura) + '" alt="fatura" onclick="orcVerImagem(\'' + esc(d.fatura) + '\')">'
        : '<a class="fat-chip" href="' + esc(d.fatura) + '" target="_blank" rel="noopener">&#128196; PDF</a>';
      return '<span style="display:inline-flex;align-items:center">' + abrir
        + (PODE ? '<button class="fat-x" title="Remover a fatura" onclick="orcApagarFatura(' + d.id + ')">&times;</button>' : '') + '</span>';
    }
    return PODE ? '<button class="fat-anexar" onclick="orcAnexarFatura(' + d.id + ')">+ fatura</button>'
                : '<span style="color:#b9beb6">—</span>';
  }

  function renderDespesas(desp, cats) {
    var box = $('lista-despesas');
    if (!desp.length) {
      box.innerHTML = '<div class="vazio">Ainda sem despesas. Comece por lançar as que já conhece.'
        + (PODE ? '<br><button class="btn btn-ouro" onclick="abrirDespesa()">+ Primeira despesa</button>' : '') + '</div>';
      return;
    }
    var nomeCat = {};
    cats.forEach(function (c) { nomeCat[c.id] = c.nome; });

    // Os filtros: a lista encolhe, e o cabeçalho diz o que ficou — quantas
    // despesas e quanto valem, que é a pergunta a seguir.
    var lista = despesasFiltradas();
    var real = lista.reduce(function (s, d) { return s + num(d.valor); }, 0);
    var nome = FILTRO_CAT === 'sem' ? 'Sem categoria' : (nomeCat[FILTRO_CAT] || 'Categoria');
    var cab = tiraFiltro(lista.length + ' despesa(s)', real, nome);
    if (!lista.length) {
      box.innerHTML = cab + '<div class="vazio">Nenhuma despesa responde a este filtro.</div>';
      return;
    }

    var h = cab + '<div class="tabela-scroll"><table class="desp"><thead><tr>'
      + '<th>Despesa</th><th>Categoria</th><th>Estado</th><th>Fatura</th>'
      + '<th style="text-align:right">Valor</th>' + (PODE ? '<th></th>' : '') + '</tr></thead><tbody>';
    lista.forEach(function (d) {
      var parc = num(d.n_parcelas) > 0
        ? '<div class="d-forn">' + fmt(d.pago_parcelas) + ' em ' + d.n_parcelas + ' parcela(s)</div>' : '';
      var celaCat = (d.categoria_id && nomeCat[d.categoria_id])
        ? '<span style="display:inline-flex;align-items:center;gap:.4rem"><i style="width:10px;height:10px;border-radius:3px;background:'
          + corCat(d.categoria_id) + ';display:inline-block;flex:none"></i>' + esc(nomeCat[d.categoria_id]) + '</span>'
        : '<span style="color:#b9beb6">—</span>';
      h += '<tr>'
        + '<td><div class="d-nome">' + esc(d.descricao) + '</div>'
        + (d.fornecedor ? '<div class="d-forn">' + esc(d.fornecedor) + '</div>' : '')
        + parc + '</td>'
        + '<td>' + celaCat + '</td>'
        + '<td><span class="est ' + d.estado + '">' + d.estado + '</span></td>'
        + '<td>' + celaFatura(d) + '</td>'
        + '<td class="d-val">' + fmt(d.valor) + '</td>';
      if (PODE) {
        h += '<td class="d-ac">'
          + '<button class="mini" onclick="orcEditarDespesa(' + d.id + ')">Abrir</button>'
          + '<button class="mini perigo" onclick="orcApagarDespesa(' + d.id + ')">✕</button></td>';
      }
      h += '</tr>';
    });
    h += '</tbody></table></div>';
    box.innerHTML = h;
  }

  // ---- calendário de pagamentos, agrupado por mês ----
  function renderPagamentos(pags) {
    var box = $('lista-pagamentos');
    if (!pags.length) {
      box.innerHTML = '<div class="vazio">Sem parcelas marcadas. Abra uma despesa para lhe juntar sinais e prestações.</div>';
      return;
    }
    // O calendário obedece aos mesmos filtros da lista: escolher «Por pagar»
    // e ver o calendário inteiro por baixo era responder a metade da pergunta.
    var nomeCat = {};
    (ORC.categorias || []).forEach(function (c) { nomeCat[c.id] = c.nome; });
    var lista = pagamentosFiltrados();
    var soma = lista.reduce(function (s, p) { return s + num(p.valor); }, 0);
    var cab = tiraFiltro(lista.length + ' parcela(s)', soma,
                         FILTRO_CAT === 'sem' ? 'Sem categoria' : (nomeCat[FILTRO_CAT] || 'Categoria'));
    if (!lista.length) {
      box.innerHTML = cab + '<div class="vazio">Nenhuma parcela responde a este filtro.</div>';
      return;
    }
    var hoje = new Date().toISOString().slice(0, 10);
    var meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
    var h = cab, mesAtual = '';
    lista.forEach(function (p) {
      var pago = !!p.pago_em;
      var chaveData = pago ? p.pago_em : p.data_prevista;
      var mes = chaveData ? (function () { var d = chaveData.split('-'); return meses[(+d[1] || 1) - 1] + ' de ' + d[0]; })() : 'Sem data';
      if (mes !== mesAtual) { h += '<div class="o-mes">' + esc(mes) + '</div>'; mesAtual = mes; }
      var venceu = !pago && p.data_prevista && p.data_prevista < hoje;
      var dataTxt = pago ? ('pago ' + p.pago_em) : (p.data_prevista || 'sem data');
      h += '<div class="pag">'
        + '<span class="data ' + (pago ? 'pago' : (venceu ? 'venceu' : '')) + '">' + esc(dataTxt) + '</span>'
        + '<span class="desc">' + esc(p.despesa) + (p.nota ? '<small>' + esc(p.nota) + '</small>' : '') + '</span>'
        + '<span class="mt">' + fmt(p.valor) + '</span>';
      if (PODE) {
        h += '<span style="display:inline-flex;gap:.35rem;justify-content:flex-end">'
          + '<button class="mini" onclick="orcEditarParcela(' + p.id + ')">Editar</button>'
          + '<button class="mini" onclick="orcLiquidar(' + p.id + ',' + (pago ? 'false' : 'true') + ')">'
          + (pago ? 'Desmarcar' : 'Dar por pago') + '</button></span>';
      } else { h += '<span></span>'; }
      h += '</div>';
    });
    box.innerHTML = h;
  }

  // ---- modais ----
  function abrir(id) { $(id).classList.add('aberto'); }
  function fechar(id) { $(id).classList.remove('aberto'); }
  window.fechar = fechar;

  // ---- categorias: criam-se e editam-se DENTRO do formulário de despesa ----
  // (não têm teto — são só gavetas com uma cor). A escolha fica no select da
  // despesa; «+ nova» acrescenta, «✎» renomeia (ou apaga) a que estiver escolhida.
  var CAT_MODO = '';   // 'nova' | 'editar'
  var CAT_COR = '';    // a cor escolhida no formulário (sugerida, ou a do casal)

  // As pastilhas de cor sugeridas + a escolha atual em destaque.
  function renderCatCores() {
    var box = $('md-cat-cores'); if (!box) return;
    box.innerHTML = PALETA_CAT.map(function (c) {
      return '<button type="button" class="cat-cor-op" data-cor="' + c + '" style="background:' + c
        + '" title="' + c + '" onclick="catCorEscolher(\'' + c + '\')"></button>';
    }).join('');
  }
  function setCatCor(hex) {
    CAT_COR = String(hex || '').toLowerCase();
    var inp = $('md-cat-cor'); if (inp && CAT_COR) inp.value = CAT_COR;
    var box = $('md-cat-cores');
    if (box) [].forEach.call(box.children, function (b) {
      b.classList.toggle('on', (b.dataset.cor || '').toLowerCase() === CAT_COR);
    });
  }
  window.catCorEscolher = function (hex) { setCatCor(hex); };

  window.catInline = function (modo) {
    var sel = $('md-categoria');
    renderCatCores();
    if (modo === 'editar') {
      if (!sel.value) { toast('Escolha uma categoria para editar, ou use «+ nova».', true); return; }
      CAT_MODO = 'editar';
      $('md-cat-nome').value = sel.options[sel.selectedIndex].textContent;
      $('md-cat-apagar').style.display = '';
      setCatCor(corCat(sel.value));                 // a que tem em vigor (guardada ou sugerida)
    } else {
      CAT_MODO = 'nova';
      $('md-cat-nome').value = '';
      $('md-cat-apagar').style.display = 'none';
      setCatCor(corSugerida((ORC.categorias || []).length)); // uma sugestão nova, variada
    }
    $('md-cat-inline').style.display = '';
    setTimeout(function () { $('md-cat-nome').focus(); }, 40);
  };
  window.catInlineFechar = function () { $('md-cat-inline').style.display = 'none'; CAT_MODO = ''; };

  // Escolher uma cor à mão (o seletor nativo) também tira o destaque das sugeridas.
  (function () {
    var inp = $('md-cat-cor');
    if (inp) inp.addEventListener('input', function () { setCatCor(this.value); });
  })();

  window.catInlineGuardar = async function () {
    var nome = $('md-cat-nome').value.trim();
    if (!nome) { toast('Dê um nome à categoria.', true); return; }
    var corpo = { nome: nome, cor: CAT_COR || '' };
    if (CAT_MODO === 'editar') corpo.id = $('md-categoria').value;
    var d = await window.api('orc_categoria_guardar', { method: 'POST', body: JSON.stringify(corpo) });
    if (!d || !d.success) return;
    var novoId = d.id;
    await carregar();                          // refresca a barra, as pastilhas e o estado
    preencheCategorias('md-categoria', novoId); // repõe o select, já com a nova escolhida
    catInlineFechar();
    toast(corpo.id ? 'Categoria guardada.' : 'Categoria criada.');
  };

  window.catInlineApagar = async function () {
    var id = $('md-categoria').value;
    if (!id) return;
    const r = await licConfirmar({
      titulo: 'Apagar esta categoria?',
      icone: '🏷️', confirmar: 'Apagar categoria',
      texto: 'As <b>despesas ficam</b> — passam a «sem categoria», e os valores não mudam.'
           + '<br><br>Só se perde a arrumação.'
    });
    if (!r.sim) return;
    var d = await window.api('orc_categoria_apagar&id=' + id, { method: 'POST' });
    if (!d || !d.success) return;
    await carregar();
    preencheCategorias('md-categoria', '');
    catInlineFechar();
    toast('Categoria apagada.');
  };

  // ---- despesas ----
  function preencheCategorias(sel, escolhida) {
    var s = $(sel); s.innerHTML = '<option value="">— sem categoria —</option>';
    (ORC.categorias || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.id; o.textContent = c.nome;
      if (+escolhida === +c.id) o.selected = true;
      s.appendChild(o);
    });
  }

  function abrirDespesa() {
    $('m-desp-titulo').textContent = 'Nova despesa';
    $('md-id').value = ''; $('md-desc').value = ''; $('md-valor').value = '';
    $('md-estado').value = 'previsto'; $('md-fornecedor').value = ''; $('md-nota').value = '';
    preencheCategorias('md-categoria', '');
    $('md-parcelas-cx').style.display = 'none';    // parcelas só depois de existir
    $('md-fatura-cx').style.display = 'none';       // fatura idem
    abrir('m-desp'); setTimeout(function () { $('md-desc').focus(); }, 50);
  }
  window.abrirDespesa = abrirDespesa;

  window.orcEditarDespesa = function (id) {
    var d = (ORC.despesas || []).find(function (x) { return +x.id === +id; });
    if (!d) return;
    $('m-desp-titulo').textContent = 'Editar despesa';
    $('md-id').value = d.id; $('md-desc').value = d.descricao;
    $('md-valor').value = paraCampo(d.valor); $('md-estado').value = d.estado;
    $('md-fornecedor').value = d.fornecedor || ''; $('md-nota').value = d.nota || '';
    preencheCategorias('md-categoria', d.categoria_id);
    $('md-parcelas-cx').style.display = PODE ? '' : 'none';
    $('md-fatura-cx').style.display = '';
    renderFaturaNoModal(d);
    renderParcelasNoModal(id);
    abrir('m-desp');
  };

  function renderFaturaNoModal(d) {
    var box = $('md-fatura');
    if (d.fatura) {
      var vis = ehImagem(d.fatura)
        ? '<img src="' + esc(d.fatura) + '" alt="fatura" onclick="orcVerImagem(\'' + esc(d.fatura) + '\')">'
        : '<a class="fat-chip" href="' + esc(d.fatura) + '" target="_blank" rel="noopener">&#128196; Abrir PDF</a>';
      box.innerHTML = '<div class="prev">' + vis
        + (PODE ? '<span><button class="mini" onclick="orcAnexarFatura(' + d.id + ')">Trocar</button> '
          + '<button class="mini perigo" onclick="orcApagarFatura(' + d.id + ')">Remover</button></span>' : '')
        + '</div>';
    } else {
      box.innerHTML = PODE
        ? '<button class="fat-anexar" onclick="orcAnexarFatura(' + d.id + ')">+ Anexar foto ou PDF</button>'
        : '<div class="dica" style="margin:0">Sem fatura anexada.</div>';
    }
  }

  function renderParcelasNoModal(despId) {
    var box = $('md-parcelas');
    var lista = (ORC.pagamentos || []).filter(function (p) { return +p.despesa_id === +despId; });
    if (!lista.length) { box.innerHTML = '<div class="dica" style="margin:0">Ainda sem parcelas.</div>'; return; }
    box.innerHTML = lista.map(function (p) {
      var estado = p.pago_em ? ('pago ' + p.pago_em) : (p.data_prevista || 'sem data');
      return '<div class="pag"><span class="data ' + (p.pago_em ? 'pago' : '') + '">' + esc(estado) + '</span>'
        + '<span class="desc">' + (p.nota ? esc(p.nota) : '') + '</span>'
        + '<span class="mt">' + fmt(p.valor) + '</span>'
        + '<span><button class="mini" onclick="orcEditarParcela(' + p.id + ')">Editar</button> '
        + '<button class="mini perigo" onclick="orcApagarParcela(' + p.id + ')">✕</button></span></div>';
    }).join('');
  }

  window.orcEditarParcela = function (id) {
    var p = (ORC.pagamentos || []).find(function (x) { return +x.id === +id; });
    if (!p) return;
    $('m-pag-titulo').textContent = 'Editar parcela';
    $('mp-id').value = p.id; $('mp-despesa').value = p.despesa_id;
    $('mp-valor').value = paraCampo(p.valor);
    $('mp-data').value = p.data_prevista || '';
    $('mp-pago').value = p.pago_em || '';
    $('mp-nota').value = p.nota || '';
    abrir('m-pag'); setTimeout(function () { $('mp-valor').focus(); }, 50);
  };

  async function guardarDespesa() {
    var d = await window.api('orc_despesa_guardar', {
      method: 'POST', body: JSON.stringify({
        id: $('md-id').value, descricao: $('md-desc').value.trim(), valor: $('md-valor').value.trim(),
        estado: $('md-estado').value, categoria_id: $('md-categoria').value,
        fornecedor: $('md-fornecedor').value.trim(), nota: $('md-nota').value.trim()
      })
    });
    if (!d || !d.success) return;
    // Fica aberta se acabou de nascer, para se poderem juntar fatura e parcelas.
    if (!$('md-id').value && d.id) {
      $('md-id').value = d.id;
      $('m-desp-titulo').textContent = 'Editar despesa';
      $('md-parcelas-cx').style.display = PODE ? '' : 'none';
      $('md-fatura-cx').style.display = '';
      toast('Despesa criada. Junte-lhe a fatura e as parcelas, se quiser.');
      await carregar();
      var nova = (ORC.despesas || []).find(function (x) { return +x.id === +d.id; }) || { id: d.id };
      renderFaturaNoModal(nova); renderParcelasNoModal(d.id);
    } else {
      fechar('m-desp'); toast('Despesa guardada.'); carregar();
    }
  }
  window.guardarDespesa = guardarDespesa;

  window.orcApagarDespesa = async function (id) {
    const r = await licConfirmar({
      titulo: 'Apagar esta despesa?',
      icone: '🗑️', perigo: true, confirmar: 'Apagar despesa',
      texto: 'As <b>prestações</b> já registadas e a <b>fatura</b> vão com ela.'
           + '<br><br><b>Isto não se desfaz.</b>'
    });
    if (!r.sim) return;
    var d = await window.api('orc_despesa_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Despesa apagada.'); carregar(); }
  };

  // ---- fatura ----
  window.orcAnexarFatura = function (id) {
    FATURA_ALVO = id;
    $('md-fatura-input').click();
  };
  $('md-fatura-input').addEventListener('change', async function () {
    var f = this.files[0]; if (!f || !FATURA_ALVO) return;
    var fd = new FormData();
    fd.append('id', FATURA_ALVO); fd.append('ficheiro', f);
    var d = await window.api('orc_despesa_fatura', { method: 'POST', body: fd });
    this.value = '';
    if (!d || !d.success) return;
    toast('Fatura anexada.');
    var alvo = FATURA_ALVO;
    await carregar();
    if ($('m-desp').classList.contains('aberto') && +$('md-id').value === +alvo) {
      var dd = (ORC.despesas || []).find(function (x) { return +x.id === +alvo; });
      if (dd) renderFaturaNoModal(dd);
    }
  });

  window.orcApagarFatura = async function (id) {
    const r = await licConfirmar({
      titulo: 'Remover a fatura desta despesa?',
      icone: '📄', confirmar: 'Remover fatura',
      texto: 'O ficheiro é <b>apagado do servidor</b>. A despesa e os pagamentos ficam '
           + 'como estão.<br><br>Pode anexar outra fatura depois.'
    });
    if (!r.sim) return;
    var d = await window.api('orc_despesa_fatura_apagar&id=' + id, { method: 'POST' });
    if (!d || !d.success) return;
    toast('Fatura removida.');
    await carregar();
    if ($('m-desp').classList.contains('aberto') && +$('md-id').value === +id) {
      var dd = (ORC.despesas || []).find(function (x) { return +x.id === +id; }) || { id: id };
      renderFaturaNoModal(dd);
    }
  };

  // ---- parcelas ----
  function abrirParcela() {
    var despId = $('md-id').value;
    if (!despId) { toast('Guarde a despesa primeiro.', true); return; }
    $('m-pag-titulo').textContent = 'Nova parcela';
    $('mp-id').value = ''; $('mp-despesa').value = despId;
    $('mp-valor').value = ''; $('mp-data').value = ''; $('mp-pago').value = ''; $('mp-nota').value = '';
    abrir('m-pag'); setTimeout(function () { $('mp-valor').focus(); }, 50);
  }
  window.abrirParcela = abrirParcela;

  async function guardarParcela() {
    var d = await window.api('orc_pagamento_guardar', {
      method: 'POST', body: JSON.stringify({
        id: $('mp-id').value, despesa_id: $('mp-despesa').value,
        valor: $('mp-valor').value.trim(), data_prevista: $('mp-data').value,
        pago_em: $('mp-pago').value, nota: $('mp-nota').value.trim()
      })
    });
    if (!d || !d.success) return;
    fechar('m-pag'); toast('Parcela guardada.');
    var despId = $('mp-despesa').value;
    await carregar();
    if ($('m-desp').classList.contains('aberto')) renderParcelasNoModal(despId);
  }
  window.guardarParcela = guardarParcela;

  window.orcApagarParcela = async function (id) {
    var d = await window.api('orc_pagamento_apagar&id=' + id, { method: 'POST' });
    if (!d || !d.success) return;
    var despId = $('md-id').value;
    toast('Parcela apagada.');
    await carregar();
    if ($('m-desp').classList.contains('aberto')) renderParcelasNoModal(despId);
  };

  window.orcLiquidar = async function (id, pago) {
    var d = await window.api('orc_pagamento_liquidar', {
      method: 'POST', body: JSON.stringify({ id: id, pago: pago ? 1 : 0 })
    });
    if (d && d.success) { toast(pago ? 'Dado por pago.' : 'Desmarcado.'); carregar(); }
  };

  // Fechar um modal ao clicar fora dele.
  document.addEventListener('click', function (ev) {
    if (ev.target.classList && ev.target.classList.contains('modal-fundo')) {
      ev.target.classList.remove('aberto');
    }
  });

  // Os campos de preço formatam-se ao escrever e fecham as duas casas ao sair.
  if (window.Moeda) window.Moeda.ligar('.campo-moeda');

  // Sem permissão de escrita, os botões de topo não fazem sentido.
  if (!PODE) {
    document.querySelectorAll('.painel-topo .btn').forEach(function (b) { b.style.display = 'none'; });
  }

  carregar();
})();
