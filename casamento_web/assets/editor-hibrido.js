/* ============================================================
   editor-hibrido.js — núcleo comum dos editores Kulemba

   O convite digital e o impresso conservam mesas de trabalho próprias. O
   que é comum (capacidades do modelo, navegação adaptável, acessibilidade e
   recuperação de rascunhos) vive aqui. Assim um modelo declara o que pode
   editar sem obrigar a manter um editor diferente para cada desenho.
   ============================================================ */
(function (global) {
  'use strict';

  var cfg = global.EDITOR_HIBRIDO || {};
  var manifesto = cfg.manifesto || {};
  var temporizador = null;
  var apiRascunho = null;

  function sel(q, raiz) { return (raiz || document).querySelector(q); }
  function todos(q, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(q)); }

  function tornarAcessivel() {
    todos('button[title]').forEach(function (b) {
      if (!b.hasAttribute('aria-label')) b.setAttribute('aria-label', b.title);
    });
    todos('.ed-painel > h3').forEach(function (h) {
      if (h.dataset.a11y) return;
      h.dataset.a11y = '1'; h.tabIndex = 0; h.setAttribute('role', 'button');
      h.setAttribute('aria-expanded', h.parentElement.classList.contains('fechado') ? 'false' : 'true');
      h.addEventListener('click', function () {
        h.setAttribute('aria-expanded', h.parentElement.classList.contains('fechado') ? 'false' : 'true');
      });
      h.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault(); h.click();
      });
    });
    todos('.camada').forEach(function (c) {
      if (c.dataset.a11y) return;
      c.dataset.a11y = '1';
      if (!c.hasAttribute('tabindex')) c.tabIndex = 0;
      if (!c.hasAttribute('role')) c.setAttribute('role', 'button');
      todos('button[title]', c).forEach(function (b) {
        if (!b.hasAttribute('aria-label')) b.setAttribute('aria-label', b.title);
      });
      c.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target === c) {
          e.preventDefault(); c.click();
        }
      });
    });
  }

  function aplicarCapacidades() {
    var mapa = {
      cores: '#p-cores, .ed-painel[data-grupo="cores"]',
      tipografia: '#p-tipografia, .ed-painel[data-grupo="tipografia"]',
      media: '.ed-painel[data-grupo="media"]',
      efeitos: '.ed-painel[data-grupo="efeitos"]'
    };
    Object.keys(mapa).forEach(function (cap) {
      if (manifesto[cap] !== false) return;
      todos(mapa[cap]).forEach(function (el) { el.hidden = true; });
    });
    document.body.dataset.editorAmbito = cfg.ambito || '';
    document.body.dataset.editorModelo = manifesto.id || 'base';
  }

  function painelPor(nome) {
    var alvos = {
      propriedades: '#p-props',
      camadas: '.ed-painel[data-grupo="camadas"]',
      design: '#p-cores, #p-tipografia, .ed-painel[data-grupo="cores"], .ed-painel[data-grupo="tipografia"]',
      media: '.ed-painel[data-grupo="media"], .ed-painel[data-grupo="efeitos"]'
    };
    return todos(alvos[nome] || '');
  }

  function abrirInspector(nome) {
    document.body.classList.add('ed-inspector-on');
    todos('.ed-painel').forEach(function (p) { p.classList.add('fechado'); });
    var ps = painelPor(nome);
    if (!ps.length) ps = painelPor('propriedades');
    ps.forEach(function (p) {
      p.classList.remove('fechado');
      var h = sel(':scope > h3', p); if (h) h.setAttribute('aria-expanded', 'true');
    });
    var primeiro = ps[0]; if (primeiro) primeiro.scrollIntoView({ block: 'nearest' });
    todos('.ed-movel-bt').forEach(function (b) { b.classList.toggle('on', b.dataset.abrir === nome); });
  }
  function fecharInspector() {
    document.body.classList.remove('ed-inspector-on');
    todos('.ed-movel-bt').forEach(function (b) { b.classList.remove('on'); });
  }

  function montarNavegacao() {
    if (sel('.ed-movel')) return;
    var nav = document.createElement('nav');
    nav.className = 'ed-movel'; nav.setAttribute('aria-label', 'Ferramentas do editor');
    nav.innerHTML =
      '<button type="button" class="ed-movel-bt on" data-abrir="previa"><span>▣</span>Prévia</button>' +
      '<button type="button" class="ed-movel-bt" data-abrir="propriedades"><span>✎</span>Editar</button>' +
      '<button type="button" class="ed-movel-bt" data-abrir="camadas"><span>▤</span>Camadas</button>' +
      '<button type="button" class="ed-movel-bt" data-abrir="design"><span>◉</span>Design</button>' +
      '<button type="button" class="ed-movel-bt guardar" data-abrir="guardar"><span>✓</span>Guardar</button>';
    document.body.appendChild(nav);
    nav.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      if (b.dataset.abrir === 'previa') return fecharInspector();
      if (b.dataset.abrir === 'guardar') {
        var g = document.getElementById('bt-guardar'); if (g) g.click(); return;
      }
      abrirInspector(b.dataset.abrir);
    });
    var fechar = document.createElement('button');
    fechar.type = 'button'; fechar.className = 'ed-inspector-fechar';
    fechar.setAttribute('aria-label', 'Fechar painel'); fechar.innerHTML = '&times;';
    fechar.addEventListener('click', fecharInspector);
    var paineis = sel('.ed-paineis'); if (paineis) paineis.insertBefore(fechar, paineis.firstChild);
  }

  function montarMaisAcoes() {
    var d = sel('.ed-mais'); if (!d) return;
    document.addEventListener('click', function (e) {
      if (!d.open || d.contains(e.target)) return; d.removeAttribute('open');
    });
    d.addEventListener('click', function (e) {
      if (e.target.closest('.ed-mais-menu button')) d.removeAttribute('open');
    });
  }

  function montarGuiasImpressao() {
    var b = document.getElementById('bt-guias-impressao');
    if (!b) return;
    b.addEventListener('click', function () {
      var arte = document.getElementById('arte'); if (!arte) return;
      var on = arte.classList.toggle('guias-impressao');
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.textContent = on ? 'Ocultar margens de impressão' : 'Mostrar margens de impressão';
    });
  }

  function chaveRascunho() {
    return 'kulemba.editor.rascunho.' + (cfg.ambito || 'peca') + '.' + (cfg.chave || 'actual');
  }
  function lerRascunho() {
    try { return JSON.parse(localStorage.getItem(chaveRascunho()) || 'null'); }
    catch (e) { return null; }
  }
  function apagarRascunho() {
    try { localStorage.removeItem(chaveRascunho()); } catch (e) {}
  }
  function gravarRascunho() {
    if (!apiRascunho || !apiRascunho.capturar) return;
    try {
      localStorage.setItem(chaveRascunho(), JSON.stringify({
        versao: 1, guardadoEm: Date.now(), estado: apiRascunho.capturar()
      }));
      var s = sel('.ed-rascunho-estado'); if (s) s.textContent = 'Rascunho protegido neste dispositivo';
    } catch (e) {}
  }
  function alterado(v) {
    if (!apiRascunho) return;
    clearTimeout(temporizador);
    if (!v) { apagarRascunho(); return; }
    temporizador = setTimeout(gravarRascunho, 650);
  }
  function montarRecuperacao(r) {
    var box = document.createElement('div'); box.className = 'ed-recuperar';
    var quando = new Date(r.guardadoEm || Date.now()).toLocaleString('pt-PT', { dateStyle:'short', timeStyle:'short' });
    box.innerHTML = '<div><b>Há um rascunho por recuperar</b><span>Guardado neste dispositivo em ' + quando + '.</span></div>' +
      '<button type="button" class="bt ed-descartar">Descartar</button>' +
      '<button type="button" class="bt primario ed-restaurar">Recuperar</button>';
    document.body.appendChild(box);
    box.querySelector('.ed-descartar').addEventListener('click', function () { apagarRascunho(); box.remove(); });
    box.querySelector('.ed-restaurar').addEventListener('click', function () {
      try { apiRascunho.restaurar(r.estado); box.remove(); apiRascunho.mostrarSujo(); alterado(true); }
      catch (e) { box.querySelector('span').textContent = 'Este rascunho já não é compatível com o modelo actual.'; }
    });
  }
  function ligarRascunho(op) {
    apiRascunho = op;
    var r = lerRascunho();
    if (r && r.estado != null) montarRecuperacao(r);
  }

  function observarInterface() {
    tornarAcessivel();
    var paineis = sel('.ed-paineis');
    if (!paineis) return;
    new MutationObserver(function () { tornarAcessivel(); }).observe(paineis, { childList:true, subtree:true });
  }

  function iniciar() {
    aplicarCapacidades(); montarNavegacao(); montarMaisAcoes(); montarGuiasImpressao(); observarInterface();
    var estado = sel('.ed-estado');
    if (estado && !sel('.ed-rascunho-estado', estado)) {
      var s = document.createElement('span'); s.className = 'ed-rascunho-estado'; s.setAttribute('role','status');
      estado.insertBefore(s, estado.firstChild);
    }
  }

  global.EditorHibrido = {
    iniciar: iniciar, ligarRascunho: ligarRascunho, alterado: alterado,
    guardado: apagarRascunho, abrirInspector: abrirInspector, fecharInspector: fecharInspector
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})(window);
