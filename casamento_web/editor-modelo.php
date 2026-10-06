<?php
// Ficha de capacidades dos modelos. Os dois editores conservam mesas de
// trabalho próprias, mas deixam de adivinhar ferramentas a partir dos valores
// guardados: cada modelo declara explicitamente o que pode ser personalizado.

if (!function_exists('cartaoCamadas')) require_once __DIR__ . '/pecas.php';

/** Catálogo fechado que o admin pode autorizar em cada suporte. */
function catalogoCapacidadesModelo(string $ambito): array {
    $paineis = [
        'conteudo'=>'Conteúdo e propriedades', 'camadas'=>'Camadas e secções',
        'cores'=>'Cores', 'tipografia'=>'Tipografia', 'media'=>'Fotografias e música',
        'efeitos'=>'Efeitos', 'composicao'=>'Posicionamento livre',
        'guias'=>'Sangria, corte e área segura',
    ];
    if ($ambito === 'impresso') {
        $camadas = cartaoCamadas();
        $campos = [];
        foreach (chavesModelo('impresso') as $k) $campos[$k] = $k;
        return [
            'seccoes'=>$camadas,
            'paineis'=>array_intersect_key($paineis, array_flip(['conteudo','camadas','cores','tipografia','composicao','guias'])),
            'media'=>[], 'efeitos'=>[], 'movimentaveis'=>$camadas,
            'campos_editaveis'=>$campos,
            'cores_permitidas'=>array_combine(array_keys(cartaoChavesCor()), array_keys(cartaoChavesCor())),
            'tipografias_permitidas'=>array_map(fn($f)=>(string)$f['nome'], fontesConvite()),
            'componentes'=>['texto'=>'Texto','ornamento'=>'Ornamentos','local'=>'Local e logística'],
            'limites'=>['largura'=>720, 'altura'=>1080, 'sangria'=>3, 'area_segura'=>5],
        ];
    }

    $seccoes = ['capa'=>'Envelope'] + array_map(fn($s)=>(string)$s['rotulo'], seccoesConvite());
    $campos = [];
    foreach (chavesModelo('digital') as $k) $campos[$k] = $k;
    $media = [
        'media.hero'=>'Fotografia de capa', 'media.historia'=>'Fotografia da história',
        'media.interludio'=>'Fotografia do interlúdio', 'media.acesso'=>'Fotografia do passe',
        'media.musica'=>'Música',
    ];
    return [
        'seccoes'=>$seccoes,
        'paineis'=>array_intersect_key($paineis, array_flip(['conteudo','camadas','cores','tipografia','media','efeitos','composicao'])),
        'media'=>$media,
        'efeitos'=>['fx.petalas'=>'Movimento decorativo', 'fx.autoplay'=>'Música automática'],
        'movimentaveis'=>array_map(fn($x)=>(string)$x['rotulo'], posicoesLivres(defsPadrao())),
        'campos_editaveis'=>$campos,
        'cores_permitidas'=>array_combine(TEMA_VARS_EDITAVEIS, TEMA_VARS_EDITAVEIS),
        'tipografias_permitidas'=>array_map(fn($f)=>(string)$f['nome'], fontesConvite()),
        'componentes'=>['texto'=>'Texto','fotografia'=>'Fotografia e enquadramento','galeria'=>'Galeria',
          'cronologia'=>'Cronologia','local_mapa'=>'Local e mapa','confirmacao'=>'Confirmação','musica'=>'Música'],
        'limites'=>['largura'=>640, 'max_blocos'=>BLOCOS_MAX, 'max_fotos'=>4],
    ];
}

/** Ficha integral usada quando um modelo antigo ainda não tem configuração. */
function capacidadesModeloPadrao(string $ambito): array {
    $c = catalogoCapacidadesModelo($ambito);
    $obrigatorias = $ambito === 'digital'
        ? ['capa','hero','convite','grande-dia','acesso','final']
        : ['abertura','nomes','convidado','data','logistica'];
    return [
        'schema'=>2, 'ambito'=>$ambito,
        'seccoes'=>array_keys($c['seccoes']),
        'paineis'=>array_keys($c['paineis']),
        'media'=>array_keys($c['media']),
        'efeitos'=>array_keys($c['efeitos']),
        'movimentaveis'=>array_keys($c['movimentaveis']),
        'obrigatorios'=>$obrigatorias,
        'campos_editaveis'=>array_keys($c['campos_editaveis']),
        'cores_permitidas'=>array_keys($c['cores_permitidas']),
        'tipografias_permitidas'=>array_keys($c['tipografias_permitidas']),
        'componentes'=>array_keys($c['componentes']),
        'limites'=>$c['limites'],
    ];
}

/** Remove opções desconhecidas e garante que uma secção obrigatória existe. */
function normalizarCapacidadesModelo(string $ambito, array|string|null $valor): array {
    if (is_string($valor)) $valor = json_decode($valor, true);
    $base = capacidadesModeloPadrao($ambito);
    if (!is_array($valor) || !$valor) return $base;
    $cat = catalogoCapacidadesModelo($ambito);
    foreach (['seccoes','paineis','media','efeitos','movimentaveis','campos_editaveis',
              'cores_permitidas','tipografias_permitidas','componentes'] as $grupo) {
        if (!array_key_exists($grupo, $valor) || !is_array($valor[$grupo])) continue;
        $permitidas = array_flip(array_keys($cat[$grupo] ?? []));
        $base[$grupo] = array_values(array_unique(array_filter(array_map('strval', $valor[$grupo]),
            fn($k)=>isset($permitidas[$k]))));
    }
    if (isset($valor['obrigatorios']) && is_array($valor['obrigatorios'])) {
        $permitidas = array_flip(array_keys($cat['seccoes']));
        $base['obrigatorios'] = array_values(array_unique(array_filter(array_map('strval', $valor['obrigatorios']),
            fn($k)=>isset($permitidas[$k]))));
    }
    $base['seccoes'] = array_values(array_unique(array_merge($base['seccoes'], $base['obrigatorios'])));
    foreach ($cat['limites'] as $k=>$origem) {
        $n = (int)($valor['limites'][$k] ?? $origem);
        $base['limites'][$k] = max(1, min(5000, $n));
    }
    return $base;
}

/** Contrato que o inspector recebe no navegador. */
function manifestoEditorModelo(string $ambito, array $defs, ?array $modelo = null): array {
    $id = $modelo ? ('modelo-'.(int)($modelo['id'] ?? 0)) : 'peca-actual';
    $ficha = normalizarCapacidadesModelo($ambito, $modelo['capacidades'] ?? null);
    $paineis = array_flip($ficha['paineis']);
    if (!isset($paineis['composicao'])) $ficha['movimentaveis'] = [];
    return $ficha + [
        'id'=>$id, 'conteudo'=>isset($paineis['conteudo']),
        'camadas'=>$ficha['seccoes'],
        'cores'=>isset($paineis['cores']), 'tipografia'=>isset($paineis['tipografia']),
        'media'=>isset($paineis['media']) && count($ficha['media']) > 0,
        'efeitos'=>isset($paineis['efeitos']) && count($ficha['efeitos']) > 0,
        'composicao'=>isset($paineis['composicao']),
        'guiasImpressao'=>isset($paineis['guias']),
    ];
}

/** Ficha do modelo de que a peça deriva, para limitar também o editor do casal. */
function modeloCapacidadesDaPeca(mysqli $conn, string $ambito): ?array {
    global $P;
    $id = modeloProvenienciaId($conn, $ambito);
    if ($id <= 0) {
        $m = modeloDeOrigem($conn, $ambito);
        $id = (int)($m['id'] ?? 0);
    }
    if ($id <= 0) return null;
    $st = $conn->prepare("SELECT id,nome,ambito,capacidades FROM {$P}modelos WHERE id=? AND ambito=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('is', $id, $ambito); $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}
