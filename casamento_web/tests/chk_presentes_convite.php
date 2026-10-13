<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../personalizacao.php';
function falhaPres(string $m): never { fwrite(STDERR, "FALHOU: $m\n"); exit(1); }
$modelo = modelosBloco()['presentes'] ?? null;
if (!$modelo || ($modelo['tipo'] ?? '') !== 'presentes') falhaPres('modelo especializado ausente');
$bloco = $modelo + ['id'=>'bl1'];
$bloco['modo']='qr'; $bloco['qr']='Pagamento de Marta & Pedro';
$validado = validarDefinicao('layout.blocos', json_encode([$bloco], JSON_UNESCAPED_UNICODE));
$lista = json_decode((string)$validado, true);
if (($lista[0]['modo'] ?? '') !== 'qr' || ($lista[0]['qr'] ?? '') !== 'Pagamento de Marta & Pedro')
    falhaPres('QR não sobrevive à validação');
$html = renderBlocoLivre($lista[0], [], false, 'classico');
if (!str_contains($html, 'data-presente-qr="Pagamento de Marta &amp; Pedro"')) falhaPres('QR não chegou à página');
$lista[0]['modo']='metodos';
$html = renderBlocoLivre($lista[0], [], false, 'classico');
if (!str_contains($html, 'Transferência bancária') || !str_contains($html, '<svg')) falhaPres('método com ícone ausente');
$lista[0]['modo']='texto';
$html = renderBlocoLivre($lista[0], [], false, 'classico');
if (str_contains($html, 'data-presente-qr') || str_contains($html, 'Transferência bancária')) falhaPres('modo texto contém extras');
echo "OK — presentes em texto, QR e métodos com ícones.\n";
