<?php
require_once __DIR__ . '/../personalizacao.php';

$falhas = 0;
function porcelanaOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$pacote = convitePacoteResolver('digital', CONVITE_PACOTE_PORCELANA,
    CONVITE_PACOTE_PORCELANA_VERSAO, CONVITE_PACOTE_SCHEMA, false);
porcelanaOk(is_array($pacote), 'o pacote Porcelana V2 resolve no registo fechado');
porcelanaOk(($pacote['manifesto']['adaptador'] ?? '') === 'porcelana-v2-v1',
    'a família tem documento próprio e não é uma pele do convite anterior');
porcelanaOk(is_file((string)($pacote['template'] ?? ''))
    && basename((string)$pacote['template']) === 'porcelain-v2.html',
    'o pacote carrega o documento próprio da identidade Porcelana');

$variantes = $pacote['manifesto']['variantes'] ?? [];
porcelanaOk(($variantes['verde']['nome'] ?? '') === 'Porcelana Verde'
    && ($variantes['rosa']['nome'] ?? '') === 'Porcelana Rosa',
    'as duas variantes comerciais têm nomes públicos em português');

$modelos = [];
foreach (catalogoModelosDeCasa() as $m) {
    if (in_array($m['nome'], ['Porcelana Verde','Porcelana Rosa'], true)) $modelos[$m['nome']] = $m;
}
porcelanaOk(count($modelos) === 2, 'o catálogo instala Verde e Rosa como modelos independentes');
porcelanaOk(count(array_unique(array_column($modelos, 'renderer_key'))) === 1
    && reset($modelos)['renderer_key'] === CONVITE_PACOTE_PORCELANA,
    'as variantes usam o mesmo renderizador versionado');
$defsVerde = json_decode($modelos['Porcelana Verde']['defs'] ?? '{}', true) ?: [];
$defsRosa = json_decode($modelos['Porcelana Rosa']['defs'] ?? '{}', true) ?: [];
porcelanaOk(($defsVerde['digital.estilo'] ?? '') === 'porcelana-verde'
    && ($defsRosa['digital.estilo'] ?? '') === 'porcelana-rosa',
    'cada modelo selecciona a pintura correcta');
porcelanaOk(($defsVerde['tema.paleta'] ?? '') !== ($defsRosa['tema.paleta'] ?? ''),
    'a troca de variante altera a paleta sem duplicar o pacote');

$desenho = array_flip(chavesDesenho('digital'));
porcelanaOk(!isset($desenho['casal.noiva']) && !isset($desenho['casal.noivo'])
    && !isset($desenho['evento.data']) && !isset($desenho['media.hero']),
    'aplicar ou trocar o modelo conserva casal, data e fotografias');

$html = file_get_contents((string)$pacote['template']);
$css = file_get_contents((string)$pacote['stylesheets'][0]['path']);
porcelanaOk(str_contains($html, 'pv2-photo-stack') && str_contains($html, 'pv2-date-grid--reception')
    && str_contains($html, 'pv2-schedule') && str_contains($html, 'pv2-gift'),
    'o documento reproduz galeria, recepção, cronograma e presentes da identidade');
porcelanaOk(str_contains($html, 'data-kulemba="confirmacao"')
    && !str_contains(mb_strtolower($html), 'mensagem')
    && !str_contains(mb_strtolower($html), 'guestbook'),
    'a confirmação usa o modal comum e não existe botão de mensagens');
porcelanaOk(str_contains($css, 'fundo-verde.webp') && str_contains($css, 'fundo-rosa.webp')
    && !preg_match('#https?://#i', $css . $html),
    'as pinturas pertencem ao pacote e não dependem de recursos remotos');
porcelanaOk(!str_contains(mb_strtolower($css . $html), 'chungdoi'),
    'o pacote não incorpora nomes, código ou endereços do fornecedor estudado');

exit($falhas ? 1 : 0);
