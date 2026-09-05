/* ============================================================
   moeda.js — Formatar campos de preço (espaço nos milhares, duas casas)

   Um sítio só para a máscara, porque agora é mais do que uma página a pedi-la:
   o orçamento, a Gestão e os formulários de registo. Expõe window.Moeda.
   O servidor (orcValor, em db.php) reconhece o texto que isto produz, por isso
   o campo pode viajar tal e qual.
   ============================================================ */
(function (g) {
  'use strict';

  function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }
  function agrupar(inteiro) { return inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  // Só dígitos e uma vírgula (a primeira).
  function limpar(s) {
    s = String(s).replace(/[^\d,]/g, '');
    var i = s.indexOf(',');
    if (i !== -1) s = s.slice(0, i + 1) + s.slice(i + 1).replace(/,/g, '');
    return s;
  }

  // Formata o que está no campo. comCasas=true força as duas casas decimais.
  function formatar(s, comCasas) {
    s = limpar(s);
    if (s === '') return '';
    var partes = s.split(',');
    var ip = partes[0].replace(/^0+(?=\d)/, ''); if (ip === '') ip = '0';
    var dp = partes.length > 1 ? partes[1].slice(0, 2) : null;
    var out = agrupar(ip);
    if (dp !== null) out += ',' + dp;
    if (comCasas) {
      if (dp === null) out += ',00';
      else if (dp.length === 1) out += '0';
      else if (dp.length === 0) out += '00';
    }
    return out;
  }

  // Um número (ou "1234.50" do servidor) para o campo: "1 234,50" — ou vazio.
  function paraCampo(v) {
    var n = num(v);
    if (n === 0) return '';
    var partes = n.toFixed(2).split('.');
    return agrupar(partes[0]) + ',' + partes[1];
  }

  // Reformata enquanto se escreve, sem perder o cursor de vista.
  function aoDigitar(el) {
    var fim = el.selectionStart == null ? el.value.length : el.selectionStart;
    var antes = el.value.slice(0, fim);
    var digitosAntes = (antes.match(/[\d,]/g) || []).length;
    el.value = formatar(el.value, false);
    var pos = 0, cont = 0;
    while (pos < el.value.length && cont < digitosAntes) {
      if (/[\d,]/.test(el.value[pos])) cont++;
      pos++;
    }
    if (el.setSelectionRange) { try { el.setSelectionRange(pos, pos); } catch (e) { /* ignore */ } }
  }
  function aoSair(el) { if (el.value.trim() !== '') el.value = formatar(el.value, true); }

  /** Liga a máscara a todos os campos que casam com o seletor (por omissão .campo-moeda). */
  function ligar(seletor) {
    document.querySelectorAll(seletor || '.campo-moeda').forEach(function (el) {
      el.addEventListener('input', function () { aoDigitar(el); });
      el.addEventListener('blur', function () { aoSair(el); });
    });
  }

  g.Moeda = { num: num, formatar: formatar, paraCampo: paraCampo, ligar: ligar };
})(window);
