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

  var FILTRO_CAT = null;    // null = todas; 'sem' = sem categoria; senão o id (string)

  function render() {
    renderResumo(ORC.resumo);
    renderCatBar(ORC.categorias, ORC.sem_categoria);
    renderChips(ORC.categorias, ORC.sem_categoria);
    renderDespesas(ORC.despesas, ORC.categorias);
    renderPagamentos(ORC.pagamentos);
  }

  // ---- cor estável por categoria (paleta de dados, boa em claro e escuro) ----
  var PALETA_CAT = ['#4C8C1E', '#2E86C8', '#B4864A', '#A5473F', '#7A5CA8', '#2F9E8F',
                    '#C98A2E', '#B24C7A', '#5B7BD6', '#6B8E23', '#8A5A2B', '#D0524B'];
  function corCat(id) {
    if (id == null || id === '' || id === 'sem') return '#b9c2bb';
    var n = Math.abs(parseInt(id, 10)) || 0;
    return PALETA_CAT[n % PALETA_CAT.length];
  }

  // ---- saúde do orçamento: KPIs + barra ----
  function renderResumo(r) {
    var margemMau = r.acima_do_teto;
    var margemTxt = r.base <= 0 ? '—'
      : (r.falta >= 0 ? fmt(r.falta) : fmt(Math.abs(r.falta)));
    var margemLbl = r.base <= 0 ? 'Defina um teto ou previstos'
      : (r.falta >= 0 ? 'Margem até ao teto' : 'Acima do teto');

    var kpis = [
      { n: r.base > 0 ? fmt(r.base) : 'sem teto', l: 'Orçamento', cls: '' },
      { n: fmt(r.previsto), l: 'Por pagar', cls: 'ouro' },
      { n: fmt(r.pago), l: 'Já pago', cls: 'pago' },
      { n: margemTxt, l: margemLbl, cls: 'margem' + (margemMau ? ' mau' : '') }
    ];
    $('o-kpis').innerHTML = kpis.map(function (k) {
      return '<div class="kpi ' + k.cls + '"><div class="n">' + k.n + '</div><div class="l">' + esc(k.l) + '</div></div>';
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

    var folga = r.base > 0 ? Math.max(0, r.base - totalDesp) : 0;
    var leg = [['var(--o-pago)', 'Pago', r.pago], ['var(--o-prev)', 'Por pagar', r.previsto]];
    if (r.base > 0) leg.push(['var(--o-track)', 'Folga até ao teto', folga]);
    $('o-legenda').innerHTML = leg.map(function (l) {
      return '<span><i style="background:' + l[0] + '"></i>' + esc(l[1]) + ' <b>' + esc(fmt(l[2])) + '</b></span>';
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

  // ---- clicar numa cor/pastilha filtra as despesas e mostra o valor real ----
  window.orcFiltrar = function (cat) {
    FILTRO_CAT = (cat == null || cat === 'null') ? null : String(cat);
    renderChips(ORC.categorias, ORC.sem_categoria);
    renderDespesas(ORC.despesas, ORC.categorias);
    var alvo = $('lista-despesas');
    if (alvo && FILTRO_CAT != null) alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

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

    // O filtro por categoria: a lista encolhe, e o cabeçalho mostra o valor REAL.
    var lista = desp, cab = '';
    if (FILTRO_CAT != null) {
      lista = (FILTRO_CAT === 'sem')
        ? desp.filter(function (d) { return !d.categoria_id; })
        : desp.filter(function (d) { return +d.categoria_id === +FILTRO_CAT; });
      var nome = FILTRO_CAT === 'sem' ? 'Sem categoria' : (nomeCat[FILTRO_CAT] || 'Categoria');
      var real = lista.reduce(function (s, d) { return s + num(d.valor); }, 0);
      cab = '<div class="o-filtro"><span class="o-filtro-cat"><i style="background:' + corCat(FILTRO_CAT) + '"></i>'
        + esc(nome) + '</span><b>' + esc(fmt(real)) + '</b> · ' + lista.length + ' despesa(s)'
        + '<button class="mini" onclick="orcFiltrar(null)">&times; limpar filtro</button></div>';
    }
    if (!lista.length) { box.innerHTML = cab + '<div class="vazio">Sem despesas nesta categoria.</div>'; return; }

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
    var hoje = new Date().toISOString().slice(0, 10);
    var meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
    var h = '', mesAtual = '';
    pags.forEach(function (p) {
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
  window.catInline = function (modo) {
    var sel = $('md-categoria');
    if (modo === 'editar') {
      if (!sel.value) { toast('Escolha uma categoria para editar, ou use «+ nova».', true); return; }
      CAT_MODO = 'editar';
      $('md-cat-nome').value = sel.options[sel.selectedIndex].textContent;
      $('md-cat-apagar').style.display = '';
    } else {
      CAT_MODO = 'nova';
      $('md-cat-nome').value = '';
      $('md-cat-apagar').style.display = 'none';
    }
    $('md-cat-inline').style.display = '';
    setTimeout(function () { $('md-cat-nome').focus(); }, 40);
  };
  window.catInlineFechar = function () { $('md-cat-inline').style.display = 'none'; CAT_MODO = ''; };

  window.catInlineGuardar = async function () {
    var nome = $('md-cat-nome').value.trim();
    if (!nome) { toast('Dê um nome à categoria.', true); return; }
    var corpo = { nome: nome };
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
    if (!confirm('Apagar esta categoria?\n\nAs despesas ficam — passam a «sem categoria».')) return;
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
    if (!confirm('Apagar esta despesa?\n\nAs suas parcelas e a fatura vão com ela. Isto não se desfaz.')) return;
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
    if (!confirm('Remover a fatura desta despesa?')) return;
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
