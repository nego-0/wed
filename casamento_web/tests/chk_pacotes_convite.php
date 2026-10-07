<?php
require_once __DIR__ . '/../convite-pacotes.php';

$falhas = 0;
function pacoteOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$origem = convitePacoteOrigem('digital');
pacoteOk($origem['renderer_key'] === 'kulemba-contemporaneo',
    'o convite digital tem uma identidade de pacote estável');
pacoteOk($origem['renderer_version'] === '1.0.0',
    'a identidade inclui uma versão semântica');

$pacote = convitePacoteResolver('digital', $origem['renderer_key'],
    $origem['renderer_version'], $origem['renderer_schema'], false);
pacoteOk(is_array($pacote), 'a identidade instalada resolve dentro do registo fechado');
pacoteOk(is_file((string)($pacote['template'] ?? '')), 'o pacote aponta para um template existente');
pacoteOk(($pacote['manifesto']['nome'] ?? '') === 'Kulemba Contemporâneo',
    'o manifesto apresenta o nome público em português');
pacoteOk(hash_file('sha256', $pacote['template']) === $pacote['checksum'],
    'o checksum descreve exactamente o template carregado');

pacoteOk(convitePacoteResolver('digital', '../../config', '1.0.0', 1, false) === null,
    'uma chave com travessia de caminho é recusada');
pacoteOk(convitePacoteResolver('digital', 'nao-instalado', '9.0.0', 1, false) === null,
    'um pacote ausente não é tratado como instalado');
$legado = convitePacoteResolver('digital', null, null);
pacoteOk(($legado['renderer_key'] ?? '') === CONVITE_PACOTE_PADRAO,
    'um modelo digital anterior à migração conserva o pacote de origem');
pacoteOk(convitePacoteOrigem('impresso')['renderer_key'] === null,
    'o cartão impresso não recebe um renderizador digital por engano');

exit($falhas ? 1 : 0);
