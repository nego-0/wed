<?php
require_once __DIR__ . '/../convite-pacotes.php';

$catalogo = convitePacotesCatalogo('digital');
$exactos = array_values(array_filter($catalogo, static fn(array $p): bool =>
    array_key_exists((string)$p['renderer_key'], convitePacotesExactos())
));
if (count($exactos) !== 11) {
    throw new RuntimeException('O catálogo deve oferecer exactamente os onze modelos activos.');
}
foreach ($exactos as $pacote) {
    if ($pacote['renderer_version'] !== CONVITE_PACOTE_EXACTO_ACTUAL) {
        throw new RuntimeException($pacote['renderer_key'] . ' ainda oferece uma versão histórica.');
    }
    $antigo = convitePacoteResolver('digital', $pacote['renderer_key'], '1.0.0', 1, false);
    $actual = convitePacoteResolver('digital', $pacote['renderer_key'], CONVITE_PACOTE_EXACTO_ACTUAL, 1, false);
    if (!$antigo || !$actual) {
        throw new RuntimeException($pacote['renderer_key'] . ' não resolve as duas versões instaladas.');
    }
}

$runtime = file_get_contents(__DIR__ . '/../assets/convite/modelos/chungdoi-exact/1.1.0/exact-runtime.js');
foreach (['canonizarEstrutura', 'normalizarEnvelopeCanonico', 'normalizarCapaInternaCanonica', 'normalizarGaleriaCanonica'] as $proibida) {
    if (str_contains($runtime, $proibida)) {
        throw new RuntimeException('O runtime activo ainda reconstrói a estrutura: ' . $proibida);
    }
}

echo "OK: 11 pacotes 1.1.0 activos; 1.0.0 preservada; reconstrução canónica removida\n";
