<?php
// ============================================================
// parcial-tema.php — Aplica o tema, cedo, para não haver salto
// ------------------------------------------------------------
// Duas camadas:
//   • base — o tema do sistema, que o admin define (Definições);
//   • escolha pessoal — cada utilizador pode trocar, e essa fica
//     no navegador (localStorage 'tema'). A escolha pessoal ganha
//     à base. Muda-se na pastilha circular (ver parcial-seletor-tema).
// Incluir dentro do <head> (ou o mais cedo possível no corpo).
// ============================================================
$temaSistema = function_exists('temaSistema') ? temaSistema() : 'jardim';
?>
<script>
(function(){
  var base = <?= json_encode($temaSistema) ?>, escolha = null;
  try { escolha = localStorage.getItem('tema'); } catch (e) {}
  // Mantém a preferência e as cores nos navegadores que usavam o nome antigo.
  if (escolha === 'niras') {
    escolha = 'jardim';
    try { localStorage.setItem('tema', escolha); } catch (e) {}
  }
  var validos = ['jardim','classico','azul','escuro'];
  var t = (escolha && validos.indexOf(escolha) >= 0) ? escolha : base;
  document.documentElement.setAttribute('data-tema', t);
})();
</script>
