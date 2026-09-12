// maps-campo.js — o campo da ligação do Google Maps, com ajuda para escolher o
// sítio e ler-lhe as coordenadas.
//
// Não embute mapa nenhum (isso pedia uma chave da API do Google e rede aberta):
// abre o Google Maps numa aba, o casal encontra o sítio e traz de lá a
// ligação. Da ligação tiram-se as coordenadas — que é o que se pediu — sem
// depender de serviço externo nenhum. Funciona sem rede na própria página.
//
// Marca-se um campo com data-mapa; se tiver data-mapa-local a apontar para o id
// do campo do nome do local, o botão já abre o Maps à procura desse sítio.
(function () {
  'use strict';

  // As coordenadas escondem-se em vários feitios de endereço do Google Maps.
  // Prova-se do mais preciso (o ponto do lugar, !3d!4d) para o mais geral (o
  // centro do mapa, @lat,lng), e por fim um par solto de números com casas
  // decimais suficientes para ser mesmo uma coordenada.
  function coordsDe(url) {
    if (!url) return null;
    var m =
      url.match(/!3d(-?\d{1,3}\.\d+)!4d(-?\d{1,3}\.\d+)/) ||
      url.match(/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/) ||
      url.match(/[?&](?:q|query|ll|sll|destination|center)=(-?\d{1,3}\.\d+),\s*(-?\d{1,3}\.\d+)/) ||
      url.match(/(-?\d{1,3}\.\d{4,}),\s*(-?\d{1,3}\.\d{4,})/);
    if (!m) return null;
    var lat = parseFloat(m[1]), lng = parseFloat(m[2]);
    if (isNaN(lat) || isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return { lat: lat, lng: lng };
  }

  function estilos() {
    if (document.getElementById('maps-campo-css')) return;
    var s = document.createElement('style');
    s.id = 'maps-campo-css';
    s.textContent =
      '.mapa-ferramentas{display:flex;flex-wrap:wrap;gap:.4rem .7rem;align-items:center;margin:.35rem 0 0;font-size:.82rem}' +
      '.mapa-btn{display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;border:1px solid var(--line,#d8dcd4);' +
      'background:#fff;color:inherit;border-radius:8px;padding:.32rem .6rem;font:inherit;font-size:.82rem;line-height:1}' +
      '.mapa-btn:hover{border-color:var(--gold,#a1854e)}' +
      '.mapa-btn svg{width:14px;height:14px;flex:none}' +
      '.mapa-coords{color:#6b7169;display:inline-flex;align-items:center;gap:.35rem}' +
      '.mapa-coords svg{width:14px;height:14px;flex:none}' +
      '.mapa-coords.tem{color:var(--forest,#2f5d3a)}' +
      '.mapa-coords .copiar{cursor:pointer;text-decoration:underline;color:inherit}';
    document.head.appendChild(s);
  }

  var ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';

  function ligar(inp) {
    if (inp.dataset.mapaPronto) return;
    inp.dataset.mapaPronto = '1';
    estilos();

    var barra = document.createElement('div');
    barra.className = 'mapa-ferramentas';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mapa-btn';
    btn.innerHTML = ICON + '<span>Escolher no Google Maps</span>';
    var coords = document.createElement('span');
    coords.className = 'mapa-coords';
    barra.appendChild(btn);
    barra.appendChild(coords);
    inp.insertAdjacentElement('afterend', barra);

    var localEl = inp.dataset.mapaLocal ? document.getElementById(inp.dataset.mapaLocal) : null;

    btn.addEventListener('click', function () {
      var v = (inp.value || '').trim();
      var url;
      if (/^https?:\/\//i.test(v)) {
        url = v;                                   // já tem ligação: abre-a para afinar
      } else {
        var q = localEl && localEl.value ? localEl.value.trim() : '';
        url = q
          ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q)
          : 'https://www.google.com/maps';
      }
      window.open(url, '_blank', 'noopener');
    });

    function mostrar() {
      var v = (inp.value || '').trim();
      var c = coordsDe(v);
      if (c) {
        var txt = c.lat.toFixed(5) + ', ' + c.lng.toFixed(5);
        coords.className = 'mapa-coords tem';
        coords.innerHTML = ICON + '<span>' + txt + '</span> · <span class="copiar" role="button" tabindex="0">copiar</span>';
        var cp = coords.querySelector('.copiar');
        var copiar = function () {
          if (navigator.clipboard) navigator.clipboard.writeText(txt);
          cp.textContent = 'copiado';
          setTimeout(function () { cp.textContent = 'copiar'; }, 1500);
        };
        cp.addEventListener('click', copiar);
        cp.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); copiar(); } });
      } else if (v) {
        coords.className = 'mapa-coords';
        coords.textContent = 'Ligação guardada — abra-a no mapa para ler as coordenadas.';
      } else {
        coords.className = 'mapa-coords';
        coords.textContent = 'Escolha no mapa e cole aqui a ligação; as coordenadas aparecem sozinhas.';
      }
    }
    inp.addEventListener('input', mostrar);
    mostrar();
  }

  function varrer(raiz) {
    (raiz || document).querySelectorAll('input[data-mapa]').forEach(ligar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { varrer(); });
  } else {
    varrer();
  }
  // Para formulários que se desenham por JavaScript (o registo mostra/esconde
  // secções): quem os montar pode chamar window.MapaCampo.varrer().
  window.MapaCampo = { varrer: varrer, coordsDe: coordsDe };
})();
