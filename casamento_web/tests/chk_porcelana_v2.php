<?php
require_once __DIR__ . '/../personalizacao.php';

$falhas = 0;
function porcelanaOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$exactos = convitePacotesExactos();
porcelanaOk(count($exactos) === 11, 'o registo declara os onze documentos visuais');

$catalogo = [];
foreach (catalogoModelosDeCasa() as $modelo) {
    $key = (string)($modelo['renderer_key'] ?? '');
    if (isset($exactos[$key])) $catalogo[$key] = $modelo;
}
porcelanaOk(count($catalogo) === 11, 'o catálogo instala os onze modelos como escolhas independentes');

foreach ($exactos as $key => $nome) {
    $pacote = convitePacoteResolver('digital', $key, '1.0.0', CONVITE_PACOTE_SCHEMA, false);
    porcelanaOk(is_array($pacote), "$nome resolve no registo fechado");
    if (!is_array($pacote)) continue;
    $manifesto = $pacote['manifesto'] ?? [];
    $html = is_readable((string)($pacote['template'] ?? ''))
        ? (string)file_get_contents((string)$pacote['template']) : '';
    porcelanaOk(($manifesto['id'] ?? '') === $key
        && ($manifesto['idioma'] ?? '') === 'pt'
        && ($manifesto['adaptador'] ?? '') === 'html-semantico',
        "$nome publica identidade, idioma e adaptador próprios");
    porcelanaOk(str_contains($html, 'data-estilo="' . $key . '"')
        && str_contains($html, 'data-landing-screenshot-id="invite-envelope"'),
        "$nome conserva documento e envelope próprios");
    porcelanaOk(str_contains($html, 'data-kulemba="confirmacao"')
        && !preg_match('/<form[^>]*>(?:(?!<\/form>)[\s\S])*?<textarea/i', $html)
        && !preg_match('/SEND WISHES|ENVOYER UN VOEU|GỬI LỜI CHÚC/iu', $html),
        "$nome usa confirmação modal e não oferece envio de mensagens");
    porcelanaOk(!preg_match('#<(?:script|link)[^>]+(?:src|href)="https?://#i', $html),
        "$nome carrega código e folhas de estilo apenas da aplicação");
}

$legado = convitePacoteResolver('digital', CONVITE_PACOTE_PORCELANA_LEGADO,
    CONVITE_PACOTE_PORCELANA_VERSAO, CONVITE_PACOTE_SCHEMA, false);
porcelanaOk(is_array($legado) && !empty($legado['legado']),
    'o pacote Porcelana anterior permanece resolvível para versões históricas');

$desenho = array_flip(chavesDesenho('digital'));
porcelanaOk(!isset($desenho['casal.noiva']) && !isset($desenho['casal.noivo'])
    && !isset($desenho['evento.data']) && !isset($desenho['media.hero']),
    'trocar de modelo conserva casal, data e fotografias');

exit($falhas ? 1 : 0);
