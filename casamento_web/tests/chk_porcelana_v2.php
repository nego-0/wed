<?php
require_once __DIR__ . '/../convite-pacotes.php';

$falhas = 0;
function porcelanaOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$pacote = convitePacoteResolver('digital', CONVITE_PACOTE_PORCELANA_LEGADO,
    CONVITE_PACOTE_PORCELANA_VERSAO, CONVITE_PACOTE_SCHEMA, false);
porcelanaOk(is_array($pacote) && !empty($pacote['legado']),
    'o pacote Porcelana V2 histórico permanece resolvível');
porcelanaOk(is_file((string)($pacote['template'] ?? ''))
    && basename((string)$pacote['template']) === 'porcelain-v2.html',
    'a versão histórica conserva o seu documento próprio');
porcelanaOk(count(convitePacotesCatalogo('digital')) === 1,
    'o pacote histórico não aparece no catálogo de modelos novos');

exit($falhas ? 1 : 0);
