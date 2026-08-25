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
<?php // Sem o ficheiro oficial, mostra-se APENAS a palavra (marcador neutro).
      // Nenhum símbolo é reconstruído: o desenho da marca entra só com o
      // ficheiro oficial em assets/logo-niras.svg (ou .png). ?>
<span class="marca-niras sem-simbolo <?= escP($classe) ?>" role="img" aria-label="NIRAS">
  <span class="marca-wordmark">NIRAS</span>
</span>
<?php }
