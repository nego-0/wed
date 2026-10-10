/* Documento normalizado partilhado pelos editores Kulemba.
   O estado pode conter Set, listas e mapas. O histórico recebe sempre a mesma
   representação estável, independentemente da ordem em que as propriedades
   foram alteradas no navegador. */
(function (global) {
  'use strict';

  function clonar(valor) {
    if (valor instanceof Set) return new Set(Array.from(valor, clonar));
    if (Array.isArray(valor)) return valor.map(clonar);
    if (valor && typeof valor === 'object') {
      var out = {};
      Object.keys(valor).forEach(function (k) { out[k] = clonar(valor[k]); });
      return out;
    }
    return valor;
  }

  function porCaminho(raiz, caminho, transformar, origem) {
    var partes = caminho.split('.'), alvo = raiz;
    for (var i = 0; i < partes.length - 1; i++) {
      if (!alvo[partes[i]] || typeof alvo[partes[i]] !== 'object') alvo[partes[i]] = {};
      alvo = alvo[partes[i]];
    }
    var ultima = partes[partes.length - 1];
    alvo[ultima] = transformar(alvo[ultima], origem);
  }

  function normalizar(documento, esquema) {
    esquema = esquema || {};
    var out = clonar(documento || {});
    (esquema.sets || []).forEach(function (p) {
      porCaminho(out, p, function (v) {
        if (v instanceof Set) return v;
        if (Array.isArray(v)) return new Set(v);
        if (typeof v === 'string') return new Set(v.split(',').filter(Boolean));
        return new Set();
      });
    });
    (esquema.listas || []).forEach(function (p) {
      porCaminho(out, p, function (v) { return Array.isArray(v) ? v : []; });
    });
    (esquema.mapas || []).forEach(function (p) {
      porCaminho(out, p, function (v) {
        return v && typeof v === 'object' && !Array.isArray(v) && !(v instanceof Set) ? v : {};
      });
    });
    return out;
  }

  function paraJson(valor) {
    if (valor instanceof Set) return Array.from(valor).sort();
    if (Array.isArray(valor)) return valor.map(paraJson);
    if (valor && typeof valor === 'object') {
      var out = {};
      Object.keys(valor).sort().forEach(function (k) { out[k] = paraJson(valor[k]); });
      return out;
    }
    return valor;
  }

  function serializar(documento, esquema) {
    return JSON.stringify(paraJson(normalizar(documento, esquema)));
  }

  function hidratar(json, esquema) {
    var valor = typeof json === 'string' ? JSON.parse(json) : json;
    return normalizar(valor, esquema);
  }

  global.EditorDocumento = { normalizar: normalizar, serializar: serializar, hidratar: hidratar };
})(window);
