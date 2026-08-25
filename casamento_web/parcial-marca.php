<?php
// ============================================================
// parcial-marca.php — O logótipo da NIRAS, num sítio só
// ------------------------------------------------------------
// USA O FICHEIRO OFICIAL, se existir: basta colocar o logótipo
// em assets/logo-niras.svg (preferido) ou assets/logo-niras.png
// e é esse, exatamente como está, que passa a aparecer em toda a
// parte (entrada, inscrição, cabeçalho). Enquanto o ficheiro não
// estiver lá, mostra-se uma reconstrução vetorial da marca, nas
// cores institucionais (verde + azul-noite).
// ============================================================

/** Caminho do logótipo oficial, se estiver presente; senão ''. */
function logoOficial(): string {
    foreach (['assets/logo-niras.svg', 'assets/logo-niras.png'] as $rel) {
        if (is_file(__DIR__ . '/' . $rel)) return $rel;
    }
    return '';
}

/**
 * Escreve o logótipo NIRAS.
 * @param string $classe  classes extra (ex.: 'grande so-niras')
 */
function marcaNiras(string $classe = ''): void {
    $oficial = logoOficial();
    if ($oficial !== '') { ?>
<span class="marca-niras <?= escP($classe) ?>" role="img" aria-label="NIRAS">
  <img class="marca-oficial" src="<?= asset($oficial) ?>" alt="NIRAS">
</span>
<?php return; } ?>
<span class="marca-niras <?= escP($classe) ?>" role="img" aria-label="NIRAS">
  <svg class="marca-simbolo" viewBox="0 0 120 100" width="58" height="48" aria-hidden="true">
    <g fill="#6CB33F">
      <path d="M2,88 L20,88 L2,70 Z"/>
      <path d="M8,64 L24,80 L36,68 L20,52 Z"/>
      <path d="M28,76 L70,34 L82,46 L40,88 Z"/>
      <path d="M34,50 L64,20 L76,32 L46,62 Z"/>
      <path d="M60,14 L84,14 L84,38 L74,28 L70,24 Z"/>
      <path d="M92,10 L112,10 L92,30 Z"/>
    </g>
  </svg>
  <span class="marca-wordmark">NIRAS</span>
</span>
<?php }
