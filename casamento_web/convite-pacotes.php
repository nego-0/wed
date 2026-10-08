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
const CONVITE_PACOTE_PORCELANA = 'porcelain-v2-green';
const CONVITE_PACOTE_PORCELANA_ROSA = 'porcelain-v2-pink';
const CONVITE_PACOTE_PORCELANA_LEGADO = 'porcelain-v2';
const CONVITE_PACOTE_PORCELANA_VERSAO = '1.0.0';

/** Os onze documentos visuais exactos instalados nesta edição. */
function convitePacotesExactos(): array {
    return [
        'jasmine-white'            => 'Jasmim Branco',
        'royal-v2-green'           => 'Real V2 Verde',
        'spring-garden-blue'       => 'Jardim Primaveril Azul',
        'double-happiness-green'   => 'Dupla Felicidade Verde',
        'porcelain-brown'          => 'Porcelana Castanha',
        'royal-blue'               => 'Real Azul',
        'porcelain-v2-green'       => 'Porcelana V2 Verde',
        'hoa-kho-orange'           => 'Flor Seca Laranja',
        'mahal-gold'               => 'Mahal Dourado',
        'porcelain-v2-pink'        => 'Porcelana V2 Rosa',
        'lien-hoa-pink'            => 'Lótus Rosa',
    ];
}

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
        ],
    ];

    // O pacote publicado na fase anterior continua resolvível exclusivamente
    // para as versões que já o fixaram. O catálogo novo deixa de o oferecer.
    $raizLegada = __DIR__ . '/assets/convite/modelos/porcelain-v2/1.0.0';
    $templateLegado = realpath($raizLegada . '/porcelain-v2.html');
    $cssLegado = realpath($raizLegada . '/porcelain-v2.css');
    if ($templateLegado !== false && $cssLegado !== false) {
        $pacotes['digital'][CONVITE_PACOTE_PORCELANA_LEGADO][CONVITE_PACOTE_PORCELANA_VERSAO] = [
            'ambito'=>'digital', 'renderer_key'=>CONVITE_PACOTE_PORCELANA_LEGADO,
            'renderer_version'=>CONVITE_PACOTE_PORCELANA_VERSAO,
            'renderer_schema'=>CONVITE_PACOTE_SCHEMA,
            'manifesto'=>convitePacoteManifesto($raizLegada . '/manifesto.json'),
            'template'=>$templateLegado,
            'stylesheets'=>[['path'=>$cssLegado,'href'=>'assets/convite/modelos/porcelain-v2/1.0.0/porcelain-v2.css']],
            'checksum'=>hash('sha256', hash_file('sha256',$templateLegado) . hash_file('sha256',$cssLegado)),
            'legado'=>true,
        ];
    }

    $raizExacta = __DIR__ . '/assets/convite/modelos/chungdoi-exact/1.0.0';
    $cssBase = realpath($raizExacta . '/exact-base.css');
    $runtime = realpath($raizExacta . '/exact-runtime.js');
    if ($cssBase === false || $runtime === false) {
        throw new RuntimeException('A base visual dos onze convites não está disponível.');
    }
    foreach (convitePacotesExactos() as $key => $nome) {
        $templateExacto = realpath($raizExacta . '/' . $key . '.html');
        $cssExacto = realpath($raizExacta . '/' . $key . '.css');
        $manifestoExacto = convitePacoteManifesto($raizExacta . '/' . $key . '.json');
        foreach ([$templateExacto, $cssExacto, $cssBase, $runtime] as $ficheiro) {
            if ($ficheiro === false || !str_starts_with(str_replace('\\', '/', $ficheiro), str_replace('\\', '/', $base) . '/')) {
                throw new RuntimeException('O documento do modelo ' . $nome . ' não está disponível.');
            }
        }
        $pacotes['digital'][$key]['1.0.0'] = [
            'ambito' => 'digital',
            'renderer_key' => $key,
            'renderer_version' => '1.0.0',
            'renderer_schema' => CONVITE_PACOTE_SCHEMA,
            'manifesto' => $manifestoExacto,
            'template' => $templateExacto,
            'stylesheets' => [
                ['path'=>$cssBase, 'href'=>'assets/convite/modelos/chungdoi-exact/1.0.0/exact-base.css'],
                ['path'=>$cssExacto, 'href'=>'assets/convite/modelos/chungdoi-exact/1.0.0/' . $key . '.css'],
            ],
            'checksum' => hash('sha256', hash_file('sha256', $templateExacto)
                . hash_file('sha256', $cssBase) . hash_file('sha256', $cssExacto)
                . hash_file('sha256', $runtime) . hash_file('sha256', $raizExacta . '/' . $key . '.json')),
        ];
    }
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
