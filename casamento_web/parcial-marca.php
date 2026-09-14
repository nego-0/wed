<?php
// ============================================================
// parcial-marca.php — O logótipo da NIRAS, num sítio só
// ------------------------------------------------------------
// USA O FICHEIRO OFICIAL: assets/logo-niras.(svg|png). Como o
// tema pode mudar no próprio navegador (escolha pessoal), levam-
// se as DUAS variantes e é o CSS que mostra a certa — a de tinta
// escura nos temas claros, a branca+verde no tema escuro.
// ============================================================

/** Primeiro ficheiro existente com este nome-base (.svg preferido). */
function logoFicheiro(string $base): string {
    foreach ([$base . '.svg', $base . '.png'] as $rel) {
        if (is_file(__DIR__ . '/' . $rel)) return $rel;
    }
    return '';
}
/** Compat.: o caminho do logótipo principal (claro), se existir. */
function logoOficial(): string { return logoFicheiro('assets/logo-niras'); }

/**
 * Escreve o logótipo NIRAS.
 * @param string $classe  classes extra (ex.: 'grande so-niras')
 */
function marcaNiras(string $classe = ''): void {
    $claro  = logoFicheiro('assets/logo-niras');
    $escuro = logoFicheiro('assets/logo-niras-branco');
    if ($claro !== '') { ?>
<span class="marca-niras <?= escP($classe) ?>" role="img" aria-label="NIRAS">
  <img class="marca-oficial marca-claro" src="<?= asset($claro) ?>" alt="NIRAS">
  <?php if ($escuro !== ''): ?><img class="marca-oficial marca-escuro" src="<?= asset($escuro) ?>" alt="NIRAS"><?php endif; ?>
</span>
<?php return; } ?>
<?php // Sem ficheiro oficial: só a palavra, como marcador neutro (sem símbolo reconstruído). ?>
<span class="marca-niras sem-simbolo <?= escP($classe) ?>" role="img" aria-label="NIRAS">
  <span class="marca-wordmark">NIRAS</span>
</span>
<?php }
