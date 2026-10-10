<?php
require_once __DIR__ . '/../personalizacao.php';

$falhas = 0;
function secaoAntigaOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$modelos = modelosBloco();
foreach (['galeria', 'mensagens', 'presentes'] as $tipo) {
    secaoAntigaOk(isset($modelos[$tipo]) && ($modelos[$tipo]['tipo'] ?? '') === $tipo,
        "o editor oferece a secção opcional $tipo");
}

$defs = defsPadrao();
$galeria = renderBlocoLivre($modelos['galeria'] + ['id'=>'blgaleria'], [], false, 'kulemba', $defs);
secaoAntigaOk(str_contains($galeria, 'bloco-livre--galeria')
    && substr_count($galeria, 'data-kulemba-galeria-item') === 4,
    'Kulemba usa as quatro fotografias configuradas na galeria');

$mensagens = renderBlocoLivre($modelos['mensagens'] + ['id'=>'blmensagens'], [], false, 'porcelana-rosa', $defs);
secaoAntigaOk(str_contains($mensagens, 'pv2-added--mensagens')
    && str_contains($mensagens, 'extra-message'),
    'Porcelana conserva a composição própria na secção de mensagens');

$presente = array_replace($modelos['presentes'], ['id'=>'blpresentes', 'modo'=>'qr', 'qr'=>'IBAN AO06 0000']);
$presenteHtml = renderBlocoLivre($presente, [], false, 'kulemba', $defs);
secaoAntigaOk(str_contains($presenteHtml, 'data-presente-qr="IBAN AO06 0000"'),
    'a secção de presentes continua a gerar o QR pelo runtime comum');

$json = json_encode([$modelos['mensagens'] + ['id'=>'blmensagens']], JSON_UNESCAPED_UNICODE);
$validado = json_decode((string)validarDefinicao('layout.blocos', (string)$json), true);
secaoAntigaOk(($validado[0]['tipo'] ?? '') === 'mensagens',
    'guardar e reabrir conserva o tipo da secção');

foreach ([CONVITE_PACOTE_PADRAO, CONVITE_PACOTE_PORCELANA_LEGADO] as $pacoteId) {
    $pacote = convitePacoteResolver('digital', $pacoteId, '1.0.0', CONVITE_PACOTE_SCHEMA, false);
    secaoAntigaOk(in_array('mensagens', $pacote['manifesto']['editor']['componentes'] ?? [], true),
        "$pacoteId declara suporte a mensagens");
}
$porcelana = convitePacoteResolver('digital', CONVITE_PACOTE_PORCELANA_LEGADO,
    '1.0.0', CONVITE_PACOTE_SCHEMA, false);
secaoAntigaOk(($porcelana['manifesto']['editor']['componentes_incorporados'] ?? []) === ['galeria','presentes'],
    'Porcelana não oferece duplicados das secções que já incorpora');

exit($falhas ? 1 : 0);
