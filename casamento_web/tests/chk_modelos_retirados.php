<?php
require_once __DIR__ . '/../convite-pacotes.php';

$falhas = 0;
function retiradoOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$retirados = [
    'jasmine-white','royal-v2-green','spring-garden-blue',
    'double-happiness-green','porcelain-brown','royal-blue',
    'porcelain-v2-green','hoa-kho-orange','mahal-gold',
    'porcelain-v2-pink','lien-hoa-pink',
];
retiradoOk(!is_dir(__DIR__ . '/../assets/convite/modelos/chungdoi-exact'),
    'os recursos dos onze modelos foram removidos');
foreach ($retirados as $key) {
    retiradoOk(convitePacoteResolver('digital', $key, '1.0.0', CONVITE_PACOTE_SCHEMA, false) === null,
        "$key deixou de estar registado");
}
$catalogo = convitePacotesCatalogo('digital');
retiradoOk(count($catalogo) === 1
    && ($catalogo[0]['renderer_key'] ?? '') === CONVITE_PACOTE_PADRAO,
    'o catálogo activo contém apenas Kulemba Contemporâneo');
$db = file_get_contents(__DIR__ . '/../db.php');
retiradoOk(str_contains($db, 'const ESQUEMA_VERSAO = 74;')
    && str_contains($db, 'v74 — retira os onze renderizadores experimentais'),
    'a migração de limpeza acompanha a remoção');

exit($falhas ? 1 : 0);
