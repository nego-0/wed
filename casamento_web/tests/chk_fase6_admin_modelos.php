<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../personalizacao.php';
require_once __DIR__ . '/../editor-modelo.php';

function falharF6(string $m): never { fwrite(STDERR, "FALHOU: $m\n"); exit(1); }

$pacotes = convitePacotesCatalogo('digital');
if (count($pacotes) !== 12) falharF6('catálogo não contém Kulemba e os onze modelos exactos');
foreach ($pacotes as $p) {
    if (($p['renderer_key'] ?? '') === CONVITE_PACOTE_PORCELANA_LEGADO) falharF6('pacote legado oferecido para novos modelos');
    if (($p['nome'] ?? '') === '' || ($p['renderer_version'] ?? '') === '') falharF6('pacote sem identidade administrável');
}
$ex = exemploDeFabrica();
foreach (['exemplo.convite_nome','exemplo.mesa_nome','exemplo.lugares','exemplo.convidados'] as $k) {
    if (!in_array($k, chavesExemplo(), true) || trim((string)($ex[$k] ?? '')) === '')
        falharF6('dado completo do convite de exemplo ausente: ' . $k);
}
$modelo = ['renderer_key'=>'porcelain-v2-pink','renderer_version'=>'1.0.0','renderer_schema'=>1];
$cat = catalogoCapacidadesDisponivel('digital', $modelo);
if ($cat['cores_permitidas'] !== [] || $cat['tipografias_permitidas'] !== [])
    falharF6('admin recebeu opções fora do pacote');
echo "OK — catálogo de pacotes, capacidades e convite de exemplo administráveis.\n";
