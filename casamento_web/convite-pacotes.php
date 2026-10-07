<?php
// ============================================================
// convite-pacotes.php — Registo fechado dos renderizadores.
//
// Um modelo guarda escolhas e limites. O pacote é o código instalado que sabe
// transformar essas escolhas em HTML. A base só guarda a identidade do pacote;
// nunca guarda caminhos, PHP ou JavaScript executável vindos do navegador.
// ============================================================

const CONVITE_PACOTE_SCHEMA = 1;
const CONVITE_PACOTE_PADRAO = 'kulemba-contemporaneo';
const CONVITE_PACOTE_PADRAO_VERSAO = '1.0.0';
const CONVITE_PACOTE_PORCELANA = 'porcelain-v2';
const CONVITE_PACOTE_PORCELANA_VERSAO = '1.0.0';

/** Lê o manifesto instalado e recusa um pacote incompleto. */
function convitePacoteManifesto(string $ficheiro): array {
    $json = is_readable($ficheiro) ? file_get_contents($ficheiro) : false;
    $m = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($m)) throw new RuntimeException('O manifesto do pacote de convite está ilegível.');
    foreach (['schema','id','versao','nome','ambito','adaptador'] as $k) {
        if (!array_key_exists($k, $m) || $m[$k] === '') {
            throw new RuntimeException('O manifesto do pacote de convite não declara ' . $k . '.');
        }
    }
    if ((int)$m['schema'] !== CONVITE_PACOTE_SCHEMA) {
        throw new RuntimeException('O esquema do pacote de convite não é suportado.');
    }
    return $m;
}

/**
 * Pacotes realmente instalados nesta versão da aplicação.
 *
 * Os caminhos nascem no código e passam por realpath; nenhum valor persistido
 * pode escolher um ficheiro arbitrário do servidor.
 */
function convitePacotesRegistados(): array {
    static $pacotes = null;
    if ($pacotes !== null) return $pacotes;

    $template = realpath(__DIR__ . '/assets/convite-base.html');
    $base = realpath(__DIR__);
    if ($template === false || $base === false || !str_starts_with(str_replace('\\', '/', $template), str_replace('\\', '/', $base) . '/')) {
        throw new RuntimeException('O template do pacote Kulemba Contemporâneo não está disponível.');
    }

    $raizKulemba = __DIR__ . '/assets/convite/modelos/kulemba-contemporaneo/1.0.0';
    $manifestoKulemba = convitePacoteManifesto($raizKulemba . '/manifesto.json');
    $raizPorcelana = __DIR__ . '/assets/convite/modelos/porcelain-v2/1.0.0';
    $manifestoPorcelana = convitePacoteManifesto($raizPorcelana . '/manifesto.json');
    $templatePorcelana = realpath($raizPorcelana . '/porcelain-v2.html');
    $cssPorcelana = realpath($raizPorcelana . '/porcelain-v2.css');
    if ($templatePorcelana === false || $cssPorcelana === false
        || !str_starts_with(str_replace('\\', '/', $templatePorcelana), str_replace('\\', '/', $base) . '/')
        || !str_starts_with(str_replace('\\', '/', $cssPorcelana), str_replace('\\', '/', $base) . '/')) {
        throw new RuntimeException('O documento do pacote Porcelana V2 não está disponível.');
    }

    $pacotes = [
        'digital' => [
            CONVITE_PACOTE_PADRAO => [
                CONVITE_PACOTE_PADRAO_VERSAO => [
                    'ambito' => 'digital',
                    'renderer_key' => CONVITE_PACOTE_PADRAO,
                    'renderer_version' => CONVITE_PACOTE_PADRAO_VERSAO,
                    'renderer_schema' => CONVITE_PACOTE_SCHEMA,
                    'manifesto' => $manifestoKulemba,
                    // Adaptador legado: conserva o HTML actual byte a byte.
                    'template' => $template,
                    'checksum' => hash_file('sha256', $template),
                ],
            ],
            CONVITE_PACOTE_PORCELANA => [
                CONVITE_PACOTE_PORCELANA_VERSAO => [
                    'ambito' => 'digital',
                    'renderer_key' => CONVITE_PACOTE_PORCELANA,
                    'renderer_version' => CONVITE_PACOTE_PORCELANA_VERSAO,
                    'renderer_schema' => CONVITE_PACOTE_SCHEMA,
                    'manifesto' => $manifestoPorcelana,
                    // Documento e composição próprios; os marcadores
                    // semânticos continuam a ser os mesmos da plataforma.
                    'template' => $templatePorcelana,
                    'stylesheets' => [[
                        'path' => $cssPorcelana,
                        'href' => 'assets/convite/modelos/porcelain-v2/1.0.0/porcelain-v2.css',
                    ]],
                    'checksum' => hash('sha256', hash_file('sha256', $templatePorcelana)
                        . hash_file('sha256', $cssPorcelana)
                        . hash_file('sha256', $raizPorcelana . '/manifesto.json')),
                ],
            ],
        ],
    ];
    return $pacotes;
}

/** Identidade persistível do pacote de origem de um âmbito. */
function convitePacoteOrigem(string $ambito): array {
    if ($ambito !== 'digital') {
        return ['renderer_key'=>null, 'renderer_version'=>null, 'renderer_schema'=>CONVITE_PACOTE_SCHEMA];
    }
    return [
        'renderer_key' => CONVITE_PACOTE_PADRAO,
        'renderer_version' => CONVITE_PACOTE_PADRAO_VERSAO,
        'renderer_schema' => CONVITE_PACOTE_SCHEMA,
    ];
}

/**
 * Resolve uma identidade persistida exclusivamente dentro do registo fechado.
 * Com fallback, modelos digitais antigos continuam a usar o pacote de origem.
 */
function convitePacoteResolver(
    string $ambito,
    ?string $rendererKey,
    ?string $rendererVersion,
    ?int $rendererSchema = null,
    bool $fallback = true
): ?array {
    $origem = convitePacoteOrigem($ambito);
    $key = trim((string)$rendererKey);
    $version = trim((string)$rendererVersion);
    $schema = $rendererSchema ?: CONVITE_PACOTE_SCHEMA;
    if ($key === '' || $version === '') {
        $key = (string)($origem['renderer_key'] ?? '');
        $version = (string)($origem['renderer_version'] ?? '');
        $schema = (int)($origem['renderer_schema'] ?? CONVITE_PACOTE_SCHEMA);
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $key)
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-z0-9.-]+)?$/i', $version)) {
        return $fallback ? convitePacoteResolver($ambito, null, null, null, false) : null;
    }
    $pacote = convitePacotesRegistados()[$ambito][$key][$version] ?? null;
    if (!$pacote || (int)$pacote['renderer_schema'] !== $schema) {
        return $fallback ? convitePacoteResolver($ambito, null, null, null, false) : null;
    }
    return $pacote;
}

/** Compara a identidade persistível de dois pacotes já resolvidos. */
function convitePacoteIgual(?array $a, ?array $b): bool {
    if ($a === null || $b === null) return false;
    return (string)($a['ambito'] ?? '') === (string)($b['ambito'] ?? '')
        && (string)($a['renderer_key'] ?? '') === (string)($b['renderer_key'] ?? '')
        && (string)($a['renderer_version'] ?? '') === (string)($b['renderer_version'] ?? '')
        && (int)($a['renderer_schema'] ?? 0) === (int)($b['renderer_schema'] ?? 0);
}
