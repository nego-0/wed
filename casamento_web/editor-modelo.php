<?php
// Contrato entre os modelos e os dois editores. Cada suporte conserva a sua
// mesa de trabalho; o manifesto comum decide que ferramentas o desenho usa.

function manifestoEditorModelo(string $ambito, array $defs, ?array $modelo = null): array {
    $id = $modelo ? ('modelo-'.(int)($modelo['id'] ?? 0)) : 'peca-actual';
    if ($ambito === 'impresso') {
        $camadas = cartaoCamadasVisiveis($defs);
        return [
            'schema'        => 1,
            'id'            => $id,
            'ambito'        => 'impresso',
            'conteudo'      => true,
            'camadas'       => array_keys($camadas),
            'cores'         => isset($defs['cartao.paleta']),
            'tipografia'    => isset($defs['cartao.fonte_serif']),
            'ornamentos'    => isset($defs['cartao.folhagem']) || isset($defs['cartao.moldura_estilo']),
            'composicao'    => true,
            'guiasImpressao'=> true,
            'media'         => false,
            'efeitos'       => false,
        ];
    }

    $temMedia = count(array_filter(array_keys($defs), fn($k) => str_starts_with($k, 'media.'))) > 0;
    return [
        'schema'        => 1,
        'id'            => $id,
        'ambito'        => 'digital',
        'conteudo'      => true,
        'camadas'       => array_keys(seccoesConvite()),
        'cores'         => isset($defs['tema.paleta']) || isset($defs['digital.estilo']),
        'tipografia'    => isset($defs['tipo.serif']) || isset($defs['tipo.script']) || isset($defs['tipo.sans']),
        'media'         => $temMedia,
        'efeitos'       => isset($defs['fx.petalas']) || isset($defs['fx.autoplay']),
        'composicao'    => isset($defs['layout.posicoes']),
        'previas'       => ['telemovel'=>390, 'desenho'=>640, 'tablet'=>820],
        'guiasImpressao'=> false,
    ];
}
