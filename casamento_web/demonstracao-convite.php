<?php
// A montra pública usa exactamente o renderizador, o modelo de origem e os
// dados de exemplo que o administrador publicou. A constante abre apenas esta
// prova fictícia; parâmetros recebidos do navegador são descartados para nunca
// expor uma peça, uma versão ou um casamento real.
define('KULEMBA_DEMONSTRACAO_PUBLICA', true);
$_GET = ['demo' => '1'];

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self' data:; font-src 'self' data:; frame-src 'self' https://www.google.com; connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
require __DIR__ . '/convite-digital.php';
