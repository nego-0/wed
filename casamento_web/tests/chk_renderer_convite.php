<?php
require_once __DIR__ . '/../convite-pacotes.php';

$falhas = 0;
function rendererOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$pacote = convitePacoteResolver('digital', 'kulemba-contemporaneo', '1.0.0', 1, false);
$legado = realpath(__DIR__ . '/../assets/convite-base.html');
rendererOk(($pacote['template'] ?? null) === $legado,
    'o primeiro pacote conserva exactamente o template digital anterior');

$pagina = file_get_contents(__DIR__ . '/../convite-digital.php');
rendererOk(str_contains($pagina, "pacoteDaPeca(\$conn, 'digital', \$MOD)"),
    'a página resolve o pacote da peça ou do modelo em pré-visualização');
rendererOk(!str_contains($pagina, "\$tplPath = __DIR__ . '/assets/convite-base.html'"),
    'a página deixou de escolher directamente o template legado');
rendererOk(str_contains($pagina, "\$PACOTE['template']"),
    'só o caminho devolvido pelo registo é carregado');

exit($falhas ? 1 : 0);
