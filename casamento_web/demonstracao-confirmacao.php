<?php
// Formulário fictício aberto pelo convite da montra. Reutiliza a confirmação
// real e os convidados definidos pelo administrador, mas o modo de demonstração
// simula o envio no próprio navegador e nunca escreve uma resposta.
define('KULEMBA_DEMONSTRACAO_PUBLICA', true);
$_GET = [
    'demo' => '1',
    'modal' => '1',
    'tema' => preg_replace('/[^a-z0-9-]/', '', (string)($_GET['tema'] ?? '')),
];

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
require __DIR__ . '/convite.php';
