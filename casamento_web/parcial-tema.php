<?php
// ============================================================
// parcial-tema.php — Arranque e troca de tema, num sítio só
// O tema padrão é o NIRAS (o :root do estilo.css). Quem escolher
// o «Clássico» fica com essa preferência guardada no navegador e
// aplicada em <html data-tema="classico">. Corre cedo, para não
// haver salto de cor ao carregar. Incluir dentro do <head>.
// ============================================================
?>
<script>
(function(){ try{
  if (localStorage.getItem('tema') === 'classico')
    document.documentElement.setAttribute('data-tema','classico');
}catch(e){} })();
function trocarTema(){ try{
  var d = document.documentElement, classico = d.getAttribute('data-tema') === 'classico';
  if (classico){ d.removeAttribute('data-tema'); localStorage.setItem('tema','niras'); }
  else { d.setAttribute('data-tema','classico'); localStorage.setItem('tema','classico'); }
  atualizarRotuloTema();
}catch(e){} }
function atualizarRotuloTema(){ try{
  var classico = document.documentElement.getAttribute('data-tema') === 'classico';
  document.querySelectorAll('[data-tema-rotulo]').forEach(function(el){
    el.textContent = classico ? 'Tema clássico' : 'Tema NIRAS';
  });
}catch(e){} }
document.addEventListener('DOMContentLoaded', atualizarRotuloTema);
</script>
