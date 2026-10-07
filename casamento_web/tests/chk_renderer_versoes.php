<?php
require_once __DIR__ . '/../convite-pacotes.php';

$falhas = 0;
function versaoRendererOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$atual = convitePacoteResolver('digital', 'kulemba-contemporaneo', '1.0.0', 1, false);
versaoRendererOk(convitePacoteIgual($atual, $atual),
    'a identidade completa do pacote é estável');
$outraVersao = $atual;
$outraVersao['renderer_version'] = '2.0.0';
versaoRendererOk(!convitePacoteIgual($atual, $outraVersao),
    'conteúdo igual não confunde versões de renderizador diferentes');
$outroEsquema = $atual;
$outroEsquema['renderer_schema'] = 2;
versaoRendererOk(!convitePacoteIgual($atual, $outroEsquema),
    'uma mudança de contrato distingue o instantâneo');

$db = file_get_contents(__DIR__ . '/../db.php');
$api = file_get_contents(__DIR__ . '/../api.php');
$personalizacao = file_get_contents(__DIR__ . '/../personalizacao.php');
versaoRendererOk(str_contains($db, "idx_versao_renderer"),
    'a migração indexa a identidade do renderizador nas versões');
versaoRendererOk(str_contains($api, 'renderer_key, renderer_version, renderer_schema'),
    'gravação, aplicação e transporte das versões incluem a identidade');
versaoRendererOk(str_contains($api, 'O renderizador desta versão não está instalado'),
    'aplicar uma versão recusa um pacote ausente');
versaoRendererOk(str_contains($personalizacao, 'convitePacoteIgual($pacoteGuardado, pacoteDaPeca'),
    'o estado em vigor compara conteúdo e pacote');

exit($falhas ? 1 : 0);
