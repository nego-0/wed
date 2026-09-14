<?php
// ============================================================
// manifest.php — O manifesto da aplicação da porta, por casamento
//
// Era um ficheiro fixo com o nome de um casal. Instalada no telemóvel do
// porteiro, a aplicação chamava-se sempre o mesmo — e quem trabalha em dois
// casamentos ficava com dois ícones iguais e sem saber qual é qual.
//
// Agora o nome vem do casamento aberto. É servido pelo PHP, e não como
// ficheiro, para poder mudar com quem o pede.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

// O manifesto é pedido pelo navegador com as credenciais da página; sem
// sessão, serve-se o genérico, que é melhor do que servir o nome de outro.
$casal = '';
if (podeEntrar()) {
    $CAS = casalInfo(defsAtuais($conn));
    $casal = trim((string)$CAS['casal']);
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');   // muda com o casamento aberto

echo json_encode([
    'name'             => 'Entrada do evento' . ($casal !== '' ? ' · ' . $casal : ''),
    'short_name'       => 'Entrada',
    'description'      => 'Leitura de convites e registo de entradas à porta do evento.',
    'start_url'        => 'porteiro.php',
    'scope'            => '.',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#16261E',
    'theme_color'      => '#16261E',
    'lang'             => 'pt',
    'icons'            => [[
        'src'     => 'assets/pecas/icons/coracao.svg',
        'sizes'   => 'any',
        'type'    => 'image/svg+xml',
        'purpose' => 'any',
    ]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
