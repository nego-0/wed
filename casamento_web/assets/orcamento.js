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

  function render() {
    renderResumo(ORC.resumo);
    renderCategorias(ORC.categorias, ORC.sem_categoria);
    renderDespesas(ORC.despesas, ORC.categorias);
    renderPagamentos(ORC.pagamentos);
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
      { n: fmt(r.comprometido), l: 'Comprometido', cls: 'ouro' },
      { n: fmt(r.pago), l: 'Já pago', cls: 'pago' },
      { n: margemTxt, l: margemLbl, cls: 'margem' + (margemMau ? ' mau' : '') }
    ];
    $('o-kpis').innerHTML = kpis.map(function (k) {
      return '<div class="kpi ' + k.cls + '"><div class="n">' + k.n + '</div><div class="l">' + esc(k.l) + '</div></div>';
    }).join('');

    // A barra: pago + contratado + previsto sobre o maior de (teto, total gasto).
    var totalDesp = r.pago + r.contratado + r.previsto;
    var denom = Math.max(r.base, totalDesp, 1);
    var segs = [['g-pago', r.pago, 'Pago'], ['g-contr', r.contratado, 'Contratado'], ['g-prev', r.previsto, 'Previsto']];
    $('o-barra').innerHTML = segs.map(function (s) {
      if (s[1] <= 0) return '';
      var w = pct(s[1], denom);
      return '<span class="' + s[0] + '" style="width:' + w + '%" title="' + s[2] + ': ' + esc(fmt(s[1])) + '">'
        + (w > 14 ? esc(fmt(s[1])) : '') + '</span>';
    }).join('');

    var folga = r.base > 0 ? Math.max(0, r.base - totalDesp) : 0;
    var leg = [['var(--o-pago)', 'Pago', r.pago], ['var(--o-contr)', 'Contratado', r.contratado],
               ['var(--o-prev)', 'Previsto', r.previsto]];
    if (r.base > 0) leg.push(['var(--o-track)', 'Folga até ao teto', folga]);
    $('o-legenda').innerHTML = leg.map(function (l) {
      return '<span><i style="background:' + l[0] + '"></i>' + esc(l[1]) + ' <b>' + esc(fmt(l[2])) + '</b></span>';
    }).join('');
  }

  // ---- categorias ----
  function renderCategorias(cats, semCat) {
    var box = $('lista-categorias');
    if (!cats.length && (!semCat || semCat.n === 0)) {
      box.innerHTML = '<div class="vazio">Ainda sem categorias.<br>Crie as que fizerem sentido para a vossa festa — nada vem imposto.'
        + (PODE ? '<br><button class="btn btn-fantasma" onclick="abrirCategoria()">+ Criar a primeira</button>' : '') + '</div>';
      return;
    }
    var h = '<div class="o-cats">';
    cats.forEach(function (c) {
      var prev = num(c.previsto), real = num(c.real_total);
      var acima = prev > 0 && real > prev;
      var p = prev > 0 ? pct(real, prev) : (real > 0 ? 100 : 0);
      h += '<div class="o-cat' + (acima ? ' acima' : '') + '">'
        + (prev > 0 ? '<span class="pct">' + Math.round(prev > 0 ? (real / prev) * 100 : 0) + '%</span>' : '')
        + '<div class="nome">' + esc(c.nome) + '</div>'
        + '<div class="vals"><b>' + fmt(real) + '</b>' + (prev > 0 ? ' de ' + fmt(prev) : ' · sem previsto') + '</div>'
        + '<div class="meter"><i style="width:' + p + '%"></i></div>';
      if (PODE) {
        h += '<div class="acs">'
          + '<button class="mini" onclick="orcEditarCategoria(' + c.id + ')">Editar</button>'
          + '<button class="mini perigo" onclick="orcApagarCategoria(' + c.id + ',\'' + esc(c.nome).replace(/'/g, "\\'") + '\')">Apagar</button>'
          + '</div>';
      }
      h += '</div>';
    });
    if (semCat && semCat.n > 0) {
      h += '<div class="o-cat semcat"><div class="nome" style="color:#8a7a4e">Sem categoria</div>'
        + '<div class="vals"><b>' + fmt(semCat.valor) + '</b> · ' + semCat.n + ' despesa(s)</div>'
        + '<div class="dica" style="margin:.4rem 0 0">Abra cada uma e dê-lhe uma categoria.</div></div>';
    }
    box.innerHTML = h + '</div>';
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
    var h = '<div class="tabela-scroll"><table class="desp"><thead><tr>'
      + '<th>Despesa</th><th>Categoria</th><th>Estado</th><th>Fatura</th>'
      + '<th style="text-align:right">Valor</th>' + (PODE ? '<th></th>' : '') + '</tr></thead><tbody>';
    desp.forEach(function (d) {
      var parc = num(d.n_parcelas) > 0
        ? '<div class="d-forn">' + fmt(d.pago_parcelas) + ' em ' + d.n_parcelas + ' parcela(s)</div>' : '';
      h += '<tr>'
        + '<td><div class="d-nome">' + esc(d.descricao) + '</div>'
        + (d.fornecedor ? '<div class="d-forn">' + esc(d.fornecedor) + '</div>' : '')
        + parc + '</td>'
        + '<td>' + (d.categoria_id && nomeCat[d.categoria_id] ? esc(nomeCat[d.categoria_id]) : '<span style="color:#b9beb6">—</span>') + '</td>'
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
        h += '<span><button class="mini" onclick="orcLiquidar(' + p.id + ',' + (pago ? 'false' : 'true') + ')">'
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

  // ---- categorias ----
  function abrirCategoria() {
    $('m-cat-titulo').textContent = 'Nova categoria';
    $('mc-id').value = ''; $('mc-nome').value = ''; $('mc-previsto').value = '';
    abrir('m-cat'); setTimeout(function () { $('mc-nome').focus(); }, 50);
  }
  window.abrirCategoria = abrirCategoria;

  window.orcEditarCategoria = function (id) {
    var c = (ORC.categorias || []).find(function (x) { return +x.id === +id; });
    if (!c) return;
    $('m-cat-titulo').textContent = 'Editar categoria';
    $('mc-id').value = c.id; $('mc-nome').value = c.nome;
    $('mc-previsto').value = paraCampo(c.previsto);
    abrir('m-cat');
  };

  async function guardarCategoria() {
    var d = await window.api('orc_categoria_guardar', {
      method: 'POST', body: JSON.stringify({
        id: $('mc-id').value, nome: $('mc-nome').value.trim(), previsto: $('mc-previsto').value.trim()
      })
    });
    if (d && d.success) { fechar('m-cat'); toast('Categoria guardada.'); carregar(); }
  }
  window.guardarCategoria = guardarCategoria;

  window.orcApagarCategoria = async function (id, nome) {
    if (!confirm('Apagar a categoria "' + nome + '"?\n\nAs despesas ficam — passam a estar sem categoria.')) return;
    var d = await window.api('orc_categoria_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Categoria apagada.'); carregar(); }
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
        + '<span><button class="mini perigo" onclick="orcApagarParcela(' + p.id + ')">✕</button></span></div>';
    }).join('');
  }

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
