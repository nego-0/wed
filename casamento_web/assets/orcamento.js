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
    return s + ' ' + MOEDA;
  }
  function pct(parte, todo) { return todo > 0 ? Math.max(0, Math.min(100, (parte / todo) * 100)) : 0; }

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
    // ajustes (só se o utilizador não estiver a escrever agora)
    if (document.activeElement !== $('a-total')) $('a-total').value = ORC.resumo.teto > 0 ? ORC.resumo.teto : '';
    if (document.activeElement !== $('a-moeda')) $('a-moeda').value = MOEDA === 'Kz' ? '' : MOEDA;
  }

  // ---- retrato do curso ----
  function renderResumo(r) {
    $('c-comprometido').innerHTML = fmt(r.comprometido)
      + ' <small>de ' + esc(r.base > 0 ? fmt(r.base) : 'sem teto') + '</small>';

    var cx = $('c-falta-cx'), v = $('c-falta'), l = $('c-falta-l');
    if (r.base <= 0) {
      cx.className = 'curso-falta'; v.textContent = '—';
      l.textContent = 'Defina um teto ou os previstos';
    } else if (r.falta < 0) {
      cx.className = 'curso-falta mau'; v.textContent = fmt(Math.abs(r.falta));
      l.textContent = 'Acima do teto';
    } else {
      cx.className = 'curso-falta ok'; v.textContent = fmt(r.falta);
      l.textContent = 'Margem até ao teto';
    }

    var totalDesp = r.pago + r.contratado + r.previsto;
    var denom = Math.max(r.base, totalDesp, 1);
    var barra = $('c-barra'); barra.innerHTML = '';
    [['s-pago', r.pago, 'Pago'], ['s-contr', r.contratado, 'Contratado'], ['s-prev', r.previsto, 'Previsto']]
      .forEach(function (seg) {
        if (seg[1] <= 0) return;
        var w = pct(seg[1], denom);
        var el = document.createElement('span');
        el.className = seg[0]; el.style.width = w + '%';
        el.title = seg[2] + ': ' + fmt(seg[1]);
        if (w > 12) el.textContent = fmt(seg[1]);
        barra.appendChild(el);
      });
  }

  // ---- categorias ----
  function renderCategorias(cats, semCat) {
    var box = $('lista-categorias');
    if (!cats.length && (!semCat || semCat.n === 0)) {
      box.innerHTML = '<div class="vazio">Ainda sem categorias. Crie uma para começar a arrumar as despesas.</div>';
      return;
    }
    var h = '';
    cats.forEach(function (c) {
      var prev = num(c.previsto), real = num(c.real_total);
      var acima = prev > 0 && real > prev;
      h += '<div class="cat">'
        + '<div class="cat-topo"><span class="cat-nome">' + esc(c.nome) + '</span>'
        + '<span class="cat-vals"><b>' + fmt(real) + '</b>'
        + (prev > 0 ? ' de ' + fmt(prev) : ' · sem previsto') + '</span></div>'
        + '<div class="cat-barra' + (acima ? ' acima' : '') + '"><i style="width:'
        + (prev > 0 ? pct(real, prev) : (real > 0 ? 100 : 0)) + '%"></i></div>';
      if (PODE) {
        h += '<div class="cat-ac">'
          + '<button class="mini" onclick="orcEditarCategoria(' + c.id + ')">Editar</button>'
          + '<button class="mini perigo" onclick="orcApagarCategoria(' + c.id + ',\'' + esc(c.nome).replace(/'/g, "\\'") + '\')">Apagar</button>'
          + '</div>';
      }
      h += '</div>';
    });
    if (semCat && semCat.n > 0) {
      h += '<div class="cat"><div class="cat-topo"><span class="cat-nome" style="color:#8a8f88">Sem categoria</span>'
        + '<span class="cat-vals"><b>' + fmt(semCat.valor) + '</b> · ' + semCat.n + ' despesa(s)</span></div>'
        + '<div class="dica" style="margin:.3rem 0 0">Estas despesas ficaram sem gaveta. Abra cada uma e escolha-lhe uma categoria.</div></div>';
    }
    box.innerHTML = h;
  }

  // ---- despesas ----
  function renderDespesas(desp, cats) {
    var box = $('lista-despesas');
    if (!desp.length) {
      box.innerHTML = '<div class="vazio">Ainda sem despesas. Comece por lançar as que já conhece.</div>';
      return;
    }
    var nomeCat = {};
    cats.forEach(function (c) { nomeCat[c.id] = c.nome; });
    var h = '<table class="desp"><thead><tr><th>Despesa</th><th>Categoria</th><th>Estado</th>'
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
        + '<td class="d-val">' + fmt(d.valor) + '</td>';
      if (PODE) {
        h += '<td class="d-ac">'
          + '<button class="mini" onclick="orcEditarDespesa(' + d.id + ')">Abrir</button>'
          + '<button class="mini perigo" onclick="orcApagarDespesa(' + d.id + ')">✕</button></td>';
      }
      h += '</tr>';
    });
    h += '</tbody></table>';
    box.innerHTML = h;
  }

  // ---- calendário de pagamentos ----
  function renderPagamentos(pags) {
    var box = $('lista-pagamentos');
    if (!pags.length) {
      box.innerHTML = '<div class="vazio">Sem parcelas marcadas. Abra uma despesa para lhe juntar sinais e prestações.</div>';
      return;
    }
    var hoje = new Date().toISOString().slice(0, 10);
    var h = '';
    pags.forEach(function (p) {
      var pago = !!p.pago_em;
      var venceu = !pago && p.data_prevista && p.data_prevista < hoje;
      var dataTxt = pago ? ('pago ' + p.pago_em) : (p.data_prevista || 'sem data');
      h += '<div class="pag">'
        + '<span class="data ' + (pago ? 'pago' : (venceu ? 'venceu' : '')) + '">' + esc(dataTxt) + '</span>'
        + '<span class="desc">' + esc(p.despesa) + (p.nota ? '<small>' + esc(p.nota) + '</small>' : '') + '</span>'
        + '<span class="mt">' + fmt(p.valor) + '</span>';
      if (PODE) {
        h += '<span>'
          + '<button class="mini" onclick="orcLiquidar(' + p.id + ',' + (pago ? 'false' : 'true') + ')">'
          + (pago ? 'Desmarcar' : 'Dar por pago') + '</button></span>';
      } else {
        h += '<span></span>';
      }
      h += '</div>';
    });
    box.innerHTML = h;
  }

  // ---- modais ----
  function abrir(id) { $(id).classList.add('aberto'); }
  function fechar(id) { $(id).classList.remove('aberto'); }
  window.fechar = fechar;

  // ---- ajustes (teto / moeda) ----
  async function guardarAjustes() {
    var d = await window.api('orc_ajuste', {
      method: 'POST',
      body: JSON.stringify({ total: $('a-total').value.trim(), moeda: $('a-moeda').value.trim() })
    });
    if (d && d.success) { toast('Guardado.'); carregar(); }
  }
  window.guardarAjustes = guardarAjustes;

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
    $('mc-previsto').value = num(c.previsto) || '';
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
    abrir('m-desp'); setTimeout(function () { $('md-desc').focus(); }, 50);
  }
  window.abrirDespesa = abrirDespesa;

  window.orcEditarDespesa = function (id) {
    var d = (ORC.despesas || []).find(function (x) { return +x.id === +id; });
    if (!d) return;
    $('m-desp-titulo').textContent = 'Editar despesa';
    $('md-id').value = d.id; $('md-desc').value = d.descricao;
    $('md-valor').value = num(d.valor) || ''; $('md-estado').value = d.estado;
    $('md-fornecedor').value = d.fornecedor || ''; $('md-nota').value = d.nota || '';
    preencheCategorias('md-categoria', d.categoria_id);
    $('md-parcelas-cx').style.display = PODE ? '' : 'none';
    renderParcelasNoModal(id);
    abrir('m-desp');
  };

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
    // Fica aberta se acabou de nascer, para se poderem juntar parcelas.
    if (!$('md-id').value && d.id) {
      $('md-id').value = d.id;
      $('m-desp-titulo').textContent = 'Editar despesa';
      $('md-parcelas-cx').style.display = PODE ? '' : 'none';
      toast('Despesa criada. Junte-lhe parcelas, se quiser.');
      await carregar(); renderParcelasNoModal(d.id);
    } else {
      fechar('m-desp'); toast('Despesa guardada.'); carregar();
    }
  }
  window.guardarDespesa = guardarDespesa;

  window.orcApagarDespesa = async function (id) {
    if (!confirm('Apagar esta despesa?\n\nAs suas parcelas vão com ela. Isto não se desfaz.')) return;
    var d = await window.api('orc_despesa_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Despesa apagada.'); carregar(); }
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

  // Sem permissão de escrita, os botões de topo não fazem sentido.
  if (!PODE) {
    document.querySelectorAll('.painel .btn, #a-guardar').forEach(function (b) { b.style.display = 'none'; });
  }

  carregar();
})();
