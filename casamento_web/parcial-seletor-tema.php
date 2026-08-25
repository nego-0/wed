<?php
// ============================================================
// parcial-seletor-tema.php — A pastilha circular do tema
// ------------------------------------------------------------
// Discreta, fixa no canto. Cada utilizador escolhe o seu tema; a
// escolha fica no navegador (localStorage) e ganha à base que o
// admin define. Aplica-se de imediato, sem recarregar. «Usar o
// tema da casa» apaga a escolha pessoal e volta à base.
// ============================================================
$__amostras = function_exists('temasAmostras') ? temasAmostras() : [];
?>
<div class="tema-fab" id="temaFab">
  <button type="button" class="tema-fab-btn" aria-label="Escolher o tema visual" title="Tema"
          aria-haspopup="true" aria-expanded="false" onclick="temaFabToggle(event)">
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
      <path d="M12 3a9 9 0 000 18z" fill="currentColor"/>
    </svg>
  </button>
  <div class="tema-fab-pop" id="temaFabPop" role="menu" aria-hidden="true">
    <div class="tema-fab-tit">Tema visual</div>
    <?php foreach (temasDisponiveis() as $chave => $rot):
        $c = $__amostras[$chave]['cores'] ?? ['#888', '#888', '#eee']; ?>
    <button type="button" class="tema-fab-op" data-tema-op="<?= $chave ?>" role="menuitemradio"
            onclick="temaEscolher('<?= $chave ?>')">
      <span class="tema-fab-dots"><i style="background:<?= $c[0] ?>"></i><i style="background:<?= $c[1] ?>"></i><i style="background:<?= $c[2] ?>"></i></span>
      <span class="tema-fab-nome"><?= escP($rot) ?></span>
      <span class="tema-fab-check" aria-hidden="true">&#10003;</span>
    </button>
    <?php endforeach; ?>
    <button type="button" class="tema-fab-reset" onclick="temaRepor()">Usar o tema da casa</button>
  </div>
</div>
<script>
(function(){
  var VALIDOS = ['niras','classico','azul','escuro'];
  function atual(){ return document.documentElement.getAttribute('data-tema') || 'niras'; }
  function marcarAtual(){
    var a = atual();
    document.querySelectorAll('#temaFabPop .tema-fab-op').forEach(function(b){
      b.classList.toggle('on', b.dataset.temaOp === a);
    });
  }
  function fechar(){
    var p = document.getElementById('temaFabPop'), btn = document.querySelector('#temaFab .tema-fab-btn');
    if (p){ p.classList.remove('aberto'); p.setAttribute('aria-hidden','true'); }
    if (btn) btn.setAttribute('aria-expanded','false');
  }
  window.temaFabToggle = function(e){
    if (e) e.stopPropagation();
    var p = document.getElementById('temaFabPop'), btn = document.querySelector('#temaFab .tema-fab-btn');
    var abrir = !p.classList.contains('aberto');
    p.classList.toggle('aberto', abrir); p.setAttribute('aria-hidden', abrir ? 'false' : 'true');
    if (btn) btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) marcarAtual();
  };
  window.temaEscolher = function(t){
    if (VALIDOS.indexOf(t) < 0) return;
    try { localStorage.setItem('tema', t); } catch (e) {}
    document.documentElement.setAttribute('data-tema', t);
    marcarAtual(); fechar();
  };
  window.temaRepor = function(){
    try { localStorage.removeItem('tema'); } catch (e) {}
    location.reload();   // volta à base que o admin definiu
  };
  document.addEventListener('click', function(e){
    var f = document.getElementById('temaFab'); if (f && !f.contains(e.target)) fechar();
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') fechar(); });
})();
</script>
