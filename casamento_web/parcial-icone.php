<?php
// A marca da aplicação, declarada num único sítio para todas as páginas.
// Todos os tamanhos nascem do símbolo de oferta e união da marca Kulemba.
// `asset()` troca o endereço quando o ficheiro muda.
$iconeAsset = static function (string $ficheiro): string {
    return function_exists('asset') ? asset('assets/' . $ficheiro) : 'assets/' . $ficheiro;
};
?>
<link rel="icon" href="<?= escP($iconeAsset('icone-sistema-32.png')) ?>" type="image/png" sizes="32x32">
<link rel="icon" href="<?= escP($iconeAsset('icone-sistema-512.png')) ?>" type="image/png" sizes="512x512">
<link rel="alternate icon" href="<?= escP(function_exists('asset') ? asset('favicon.ico') : 'favicon.ico') ?>" type="image/x-icon">
<link rel="apple-touch-icon" href="<?= escP($iconeAsset('icone-sistema-180.png')) ?>" sizes="180x180">
<meta name="theme-color" content="#16283a">
<link rel="stylesheet" href="<?= escP($iconeAsset('modais.css')) ?>">
<script defer src="<?= escP($iconeAsset('modais.js')) ?>"></script>
