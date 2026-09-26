<?php
// Uma marca para todos os temas; o branco é aplicado por CSS nos fundos escuros.
function logoOficial(): string { return 'assets/logo-kulemba.png'; }
function marcaKulemba(string $classe = ''): void { ?>
<span class="marca-kulemba <?= escP($classe) ?>">
  <img class="marca-oficial" src="<?= asset(logoOficial()) ?>" alt="Kulemba" width="1850" height="422">
</span>
<?php }
