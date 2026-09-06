/* ============================================================
   bar-montagem.js — A montagem do bar (bar.php)

   O menu antes da festa: as gavetas, as bebidas com fotografia e stock, e as
   folhas de QR para pousar nas mesas. Uma leitura só (bar_estado) traz tudo; o
   servidor é que manda, e aqui limitamo-nos a desenhar.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var PODE = !window.SO_VER_UI;
  var EST = null;

  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }
  function toast(m, mau) {
    var t = $('toast'); if (!t) return;
    t.textContent = m; t.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(function () { t.className = 'toast'; }, 2600);
  }
  window.toast = toast;

  /** A caixa da fotografia — ou a inicial na cor da gaveta, que é melhor do
      que um quadrado cinzento a dizer que ninguém tratou do menu. */
  function foto(item) {
    if (item.foto) {
      return '<div class="b-foto"><img src="' + esc(item.foto) + '" alt="' + esc(item.nome)
           + '" loading="lazy" decoding="async"></div>';
    }
    var cor = item.categoria_cor || 'var(--gold-soft)';
    return '<div class="b-foto"><span class="letra" style="background:' + esc(cor) + '">'
         + esc((item.nome || '?').trim().charAt(0).toUpperCase()) + '</span></div>';
  }

  // ---- carregar e desenhar ----
  async function carregar() {
    var d = await window.api('bar_estado', { method: 'GET' });
    if (!d || !d.success) return;
    EST = d;
    pintarChave();
    pintarMenu();
    pintarGavetas();
  }

  function pintarChave() {
    var aberto = !!(EST.estado && EST.estado.aberto);
    $('b-est').innerHTML = 'A copa está <b>' + (aberto ? 'aberta' : 'fechada') + '</b>';
    var bt = $('b-chave-bt');
    bt.textContent = aberto ? 'Fechar o bar' : 'Abrir o bar';
    bt.className = 'btn ' + (aberto ? 'btn-fantasma' : 'btn-ouro');
    bt.disabled = !PODE;
  }

  function pintarMenu() {
    var cx = $('b-grelha');
    var itens = EST.itens || [];
    if (!itens.length) {
      cx.innerHTML = '<div class="b-vazio"><span class="ico">🍹</span>'
        + 'O menu está vazio. A primeira bebida é a que dá vontade às outras.'
        + (PODE ? '<br><br><button class="btn btn-ouro" onclick="barNova()">+ Primeira bebida</button>' : '')
        + '</div>';
      return;
    }
    cx.innerHTML = itens.map(function (i) {
      var gav = i.categoria
        ? '<span class="b-gav"><i style="background:' + esc(i.categoria_cor || '#b9c2bb') + '"></i>'
          + esc(i.categoria) + '</span>' : '<span class="b-gav">sem gaveta</span>';
      return '<div class="b-cart' + (i.estado === 'oculto' ? ' oculta' : '') + '">'
        + foto(i)
        + '<div class="corpo">'
        +   gav
        +   '<div class="nm">' + esc(i.nome) + (i.alcoolico ? ' <small title="Com álcool">🍷</small>' : '') + '</div>'
        +   (i.descricao ? '<div class="ds">' + esc(i.descricao) + '</div>' : '')
        +   '<div class="nums">'
        +     '<div><b>' + i.stock + '</b>em stock</div>'
        +     '<div><b>' + i.disponivel + '</b>disponível</div>'
        +     (i.reservado ? '<div><b>' + i.reservado + '</b>prometidas</div>' : '')
        +   '</div>'
        +   (PODE ? '<div class="acs">'
        +     '<button class="btn btn-sm" onclick="barEditar(' + i.id + ')">Editar</button>'
        +     '<button class="btn btn-sm" onclick="barFoto(' + i.id + ')">Fotografia</button>'
        +     '<button class="btn btn-sm btn-fantasma" onclick="barRepor(' + i.id + ')">+ Stock</button>'
        +     '<button class="btn btn-sm btn-fantasma" title="Apagar" onclick="barApagar(' + i.id + ')">✕</button>'
        +   '</div>' : '')
        + '</div></div>';
    }).join('');
  }

  function pintarGavetas() {
    var cx = $('b-cats');
    var cats = EST.categorias || [];
    if (!cats.length) { cx.innerHTML = '<span class="dica" style="margin:0">Ainda sem gavetas.</span>'; return; }
    cx.innerHTML = cats.map(function (c) {
      return '<span class="b-cat"><i style="background:' + esc(c.cor || '#b9c2bb') + '"></i>'
        + esc(c.nome)
        + (PODE ? '<button title="Mudar" onclick="barGaveta(' + c.id + ')">✎</button>'
                + '<button title="Apagar" onclick="barGavetaApagar(' + c.id + ',\'' + esc(c.nome) + '\')">✕</button>' : '')
        + '</span>';
    }).join('');
  }

  // ---- as abas ----
  window.barAba = function (qual) {
    ['menu', 'gav', 'mesas'].forEach(function (k) {
      $('ab-' + k).classList.toggle('on', k === qual);
      $('ab-' + k).setAttribute('aria-selected', k === qual ? 'true' : 'false');
      $('pn-' + k).hidden = k !== qual;
    });
    if (qual === 'mesas') pintarFolhas();
  };

  // ---- as folhas das mesas ----
  var folhasFeitas = false;
  function pintarFolhas() {
    if (folhasFeitas) return;
    folhasFeitas = true;
    var cx = $('b-folhas');
    var mesas = window.BAR_MESAS || [];
    if (!mesas.length) {
      cx.innerHTML = '<div class="b-vazio">Ainda não há mesas. Crie-as na planta, e as folhas saem daqui.</div>';
      return;
    }
    cx.innerHTML = mesas.map(function (m) {
      var url = window.BAR_ENDERECO + '/bebidas.php?m=' + m.token;
      return '<div class="b-folha"><div class="mesa">' + esc(m.nome) + '</div>'
        + '<canvas class="b-qr" data-link="' + esc(url) + '"></canvas>'
        + '<div class="lnk">' + esc(url) + '</div>'
        + '<div class="rod">Sem rede? Chame um empregado — ele faz o pedido por si.</div></div>';
    }).join('');
    cx.querySelectorAll('canvas.b-qr').forEach(function (cv) {
      try {
        new QRious({ element: cv, value: cv.dataset.link, size: 150, level: 'M',
                     background: '#fff', foreground: '#20342A' });
      } catch (e) {}
    });
  }

  // ---- abrir e fechar ----
  window.barChave = async function () {
    var aberto = !!(EST.estado && EST.estado.aberto);
    if (aberto) {
      var r = await licConfirmar({
        titulo: 'Fechar o bar?', icone: '🔒', confirmar: 'Fechar',
        texto: 'Os convidados deixam de poder pedir. Os pedidos que já estão na fila '
             + 'continuam lá — fechar não os apaga.'
      });
      if (!r.sim) return;
    }
    var d = await window.api(aberto ? 'bar_fechar' : 'bar_abrir', { method: 'POST' });
    if (!d || !d.success) return;
    toast(d.aberto ? 'O bar está aberto.' : 'O bar está fechado.');
    carregar();
  };

  // ---- gavetas ----
  window.barGaveta = function (id) {
    var c = (EST.categorias || []).find(function (x) { return +x.id === +id; }) || {};
    licFormulario({
      titulo: id ? 'Mudar a gaveta' : 'Gaveta nova', guardar: 'Guardar',
      campos: [
        { id: 'nome', rot: 'Nome', valor: c.nome || '' },
        { id: 'cor', rot: 'Cor (#rrggbb)', valor: c.cor || '#4C8C1E' }
      ],
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A gaveta precisa de um nome.'), false;
        var d = await window.api('bar_categoria_guardar', { method: 'POST',
          body: JSON.stringify({ id: id || 0, nome: v.nome, cor: v.cor }) });
        if (!d || !d.success) return false;
        toast('Gaveta guardada.'); carregar(); return true;
      }
    });
  };

  window.barGavetaApagar = async function (id, nome) {
    var r = await licConfirmar({
      titulo: 'Apagar a gaveta «' + licEsc(nome) + '»?', icone: '🗂️', perigo: true,
      confirmar: 'Apagar',
      texto: 'As bebidas que estão nela não se perdem — ficam sem gaveta, e podem ir para outra.'
    });
    if (!r.sim) return;
    var d = await window.api('bar_categoria_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Gaveta apagada.'); carregar(); }
  };

  // ---- bebidas ----
  function camposDaBebida(i) {
    var opcoes = [{ v: '', r: '— sem gaveta —' }].concat(
      (EST.categorias || []).map(function (c) { return { v: c.id, r: c.nome }; }));
    var campos = [
      { id: 'nome', rot: 'Nome', valor: i.nome || '', largura: 2 },
      { id: 'categoria_id', rot: 'Gaveta', tipo: 'escolha', opcoes: opcoes, valor: i.categoria_id || '' },
      { id: 'descricao', rot: 'Uma linha sobre ela', valor: i.descricao || '', largura: 2 },
      { id: 'max_por_pedido', rot: 'Máximo por pedido', tipo: 'numero', valor: i.max_por_pedido || 2, min: 1 },
      { id: 'alcoolico', rot: 'Álcool', tipo: 'sim', aoLado: 'Tem álcool', valor: !!i.alcoolico },
      { id: 'estado', rot: 'No menu', tipo: 'escolha', valor: i.estado || 'ativo',
        opcoes: [{ v: 'ativo', r: 'À vista' }, { v: 'oculto', r: 'Escondida' }] }
    ];
    if (!i.id) campos.push({ id: 'stock', rot: 'Quantas há, para começar', tipo: 'numero', valor: 0, min: 0 });
    return campos;
  }

  window.barNova = function () {
    licFormulario({
      titulo: 'Bebida nova', guardar: 'Criar', largo: true, campos: camposDaBebida({}),
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A bebida precisa de um nome.'), false;
        var d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(v) });
        if (!d || !d.success) return false;
        toast('Bebida no menu. Falta a fotografia.');
        await carregar();
        barFoto(d.id);
        return true;
      }
    });
  };

  window.barEditar = function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    licFormulario({
      titulo: i.nome, guardar: 'Guardar', largo: true, campos: camposDaBebida(i),
      aoGuardar: async function (v) {
        if (!v.nome) return licJanelaErro('A bebida precisa de um nome.'), false;
        v.id = id;
        var d = await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(v) });
        if (!d || !d.success) return false;
        toast('Guardado.'); carregar(); return true;
      }
    });
  };

  window.barApagar = async function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    var c = await licConfirmar({
      titulo: 'Apagar «' + licEsc(i.nome) + '»?', icone: '🗑️', perigo: true, confirmar: 'Apagar',
      texto: 'Sai do menu e leva a fotografia. O que já foi entregue fica no histórico.'
    });
    if (!c.sim) return;
    var d = await window.api('bar_item_apagar&id=' + id, { method: 'POST' });
    if (d && d.success) { toast('Fora do menu.'); carregar(); }
  };

  window.barRepor = function (id) {
    var i = (EST.itens || []).find(function (x) { return +x.id === +id; });
    if (!i) return;
    licFormulario({
      titulo: 'Stock de «' + licEsc(i.nome) + '»', guardar: 'Somar',
      dica: 'Há <b>' + i.stock + '</b> em stock e <b>' + i.disponivel + '</b> disponíveis. '
          + 'Some o que chegou — ou ponha um número negativo, se algo se partiu.',
      campos: [
        { id: 'quantidade', rot: 'Quantas entraram', tipo: 'numero', valor: 12 },
        { id: 'nota', rot: 'Nota (opcional)', valor: '' }
      ],
      aoGuardar: async function (v) {
        if (!v.quantidade) return licJanelaErro('Diga quantas entraram.'), false;
        var d = await window.api('bar_stock_repor', { method: 'POST',
          body: JSON.stringify({ item_id: id, quantidade: v.quantidade, nota: v.nota }) });
        if (!d || !d.success) return false;
        toast('Stock actualizado.'); carregar(); return true;
      }
    });
  };

  window.barFoto = function (id) {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp';
    inp.style.display = 'none'; document.body.appendChild(inp);
    inp.addEventListener('change', async function () {
      var f = inp.files && inp.files[0];
      inp.remove();
      if (!f) return;
      var fd = new FormData();
      fd.append('id', id); fd.append('ficheiro', f);
      toast('A enviar…');
      var d = await window.api('bar_item_foto', { method: 'POST', body: fd });
      if (d && d.success) { toast('Fotografia posta.'); carregar(); }
    });
    inp.click();
  };

  carregar();
})();
