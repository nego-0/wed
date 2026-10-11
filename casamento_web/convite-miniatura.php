<?php
/** Documento estático só com o envelope. Não monta o convite nem o runtime. */
function conviteMiniaturaEnvelope(string $template, array $defs, string $nomeConvidado): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $anterior = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $template, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    $xpath = new DOMXPath($doc);
    $capa = $xpath->query('//*[@id="cover"]')->item(0);
    if (!$capa) throw new RuntimeException('O modelo não declara um envelope.');
    $head = '';
    foreach ($xpath->query('//head/meta | //head/link | //head/style') as $node) {
        if ($node instanceof DOMElement && $node->tagName === 'link'
            && !in_array(strtolower($node->getAttribute('rel')), ['stylesheet','icon','alternate icon','apple-touch-icon'], true)) continue;
        if ($node instanceof DOMElement && $node->tagName==='link'
            && str_contains($node->getAttribute('href'), 'convite-runtime.css')) continue;
        $head .= $doc->saveHTML($node);
    }
    $corpo = $doc->getElementsByTagName('body')->item(0);
    $attrs = '';
    foreach (['class','data-estilo','style'] as $attr) {
        if ($corpo && $corpo->hasAttribute($attr)) $attrs .= ' ' . $attr . '="' . escP($corpo->getAttribute($attr)) . '"';
    }
    $dt = new DateTime($defs['evento.data'] . ' ' . $defs['evento.hora']);
    $tipo = cssTipografia($defs);
    $vars = $tipo['vars'];
    foreach (paletaEfetiva($defs) as $k=>$v) if (corHexValida($v)) $vars .= '--'.$k.':'.$v.';';
    $mono = trim((string)($defs['capa.monograma'] ?? ''));
    if ($mono === '') $mono = inicialU($defs['casal.noiva']) . '&' . inicialU($defs['casal.noivo']);
    $tokens = [
        '{{NOIVA}}'=>escP($defs['casal.noiva']), '{{NOIVO}}'=>escP($defs['casal.noivo']),
        '{{MONO}}'=>escP($mono), '{{DIA}}'=>$dt->format('j'), '{{ANO}}'=>$dt->format('Y'),
        '{{MES_NOME}}'=>escP(MESES_PT[(int)$dt->format('n')]),
        '{{MES_ABREV}}'=>escP(mb_substr(MESES_PT[(int)$dt->format('n')],0,3)),
        '{{COVER_HINT}}'=>escP($defs['capa.dica']), '{{GUEST_NAME}}'=>escP($nomeConvidado),
        '{{GUEST_LABEL}}'=>escP($defs['textos.guest_label']), '{{KICKER}}'=>escP($defs['textos.kicker']),
        '{{LEAD}}'=>mdTexto($defs['textos.lead'], ['{noiva}'=>$defs['casal.noiva'], '{noivo}'=>$defs['casal.noivo']]),
        '{{ABERTURA}}'=>escP($defs['capa.abertura']), '{{SELO}}'=>escP($defs['capa.selo']),
        '{{ESTILO_DIGITAL}}'=>escP($defs['digital.estilo']),
        '{{TEMA_VARS}}'=>$tipo['faces'].':root{'.$vars.'}',
    ];
    $html = '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'.$head
        .'<title>Envelope do convite</title><style>html,body{margin:0;height:100%;overflow:hidden!important}'
        .'#cover{position:fixed!important;inset:0!important;display:flex!important;opacity:1!important;visibility:visible!important}'
        .'*,*::before,*::after{animation:none!important;transition:none!important;pointer-events:none!important}</style></head>'
        .'<body'.$attrs.' data-miniatura="envelope">'.$doc->saveHTML($capa).'</body></html>';
    return strtr($html, $tokens);
}
