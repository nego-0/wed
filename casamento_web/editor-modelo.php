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
        foreach (camposInspectorModelo('impresso') as $campo) {
            $campos[$campo['chave']] = $campo['rotulo'];
        }
        return [
            'seccoes'=>$camadas,
            'paineis'=>array_intersect_key($paineis, array_flip(['conteudo','camadas','cores','tipografia','composicao','guias'])),
            'media'=>[], 'efeitos'=>[], 'movimentaveis'=>$camadas,
            'campos_editaveis'=>$campos,
            'cores_permitidas'=>array_combine(array_keys(cartaoChavesCor()), array_keys(cartaoChavesCor())),
            'tipografias_permitidas'=>array_map(fn($f)=>(string)$f['nome'], fontesConvite()),
            'componentes'=>['texto'=>'Texto','ornamento'=>'Ornamentos','local'=>'Local e logística'],
            'recursos'=>['biblioteca_icones'=>'Biblioteca global de ícones'],
            'limites'=>['largura'=>720, 'altura'=>1080, 'sangria'=>3, 'area_segura'=>5],
        ];
    }

    $seccoes = ['capa'=>'Envelope'] + array_map(fn($s)=>(string)$s['rotulo'], seccoesConvite());
    $campos = [];
    foreach (camposInspectorModelo('digital') as $campo) {
        $campos[$campo['chave']] = $campo['rotulo'];
    }
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
          'cronologia'=>'Cronologia','local_mapa'=>'Local e mapa','confirmacao'=>'Confirmação','musica'=>'Música',
          'presentes'=>'Presentes e formas de oferta','mensagens'=>'Mensagens dos convidados'],
        'recursos'=>['biblioteca_icones'=>'Biblioteca global de ícones',
          'rolagem_automatica'=>'Rolagem automática','presentes_qr'=>'Presentes com texto, QR e métodos'],
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
        'schema'=>3, 'ambito'=>$ambito,
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
        'recursos'=>array_keys($c['recursos']),
        'limites'=>$c['limites'],
    ];
}

/** Remove opções desconhecidas e garante que uma secção obrigatória existe. */
function normalizarCapacidadesModelo(string $ambito, array|string|null $valor): array {
    if (is_string($valor)) $valor = json_decode($valor, true);
    $base = capacidadesModeloPadrao($ambito);
    if (!is_array($valor) || !$valor) return $base;
    $schemaRecebido = (int)($valor['schema'] ?? 2);
    $cat = catalogoCapacidadesModelo($ambito);
    foreach (['seccoes','paineis','media','efeitos','movimentaveis','campos_editaveis',
              'cores_permitidas','tipografias_permitidas','componentes','recursos'] as $grupo) {
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
    // O esquema 3 introduziu recursos gerais. Modelos anteriores recebem-nos
    // sem perder as suas restrições antigas; o admin pode depois desligá-los.
    if ($schemaRecebido < 3) {
        $base['recursos'] = array_keys($cat['recursos'] ?? []);
        foreach (array_keys($cat['campos_editaveis'] ?? []) as $chave) {
            if (str_starts_with($chave, 'geral.')) $base['campos_editaveis'][] = $chave;
        }
        $base['campos_editaveis'] = array_values(array_unique($base['campos_editaveis']));
    }
    $base['schema'] = 3;
    return $base;
}

/** Metadados semânticos dos campos. O HTML dos editores deixa de ser a ficha. */
function camposInspectorModelo(string $ambito): array {
    $meta = [
        'capa.monograma'=>['Monograma do selo','texto',12], 'capa.dica'=>['Dica de abertura','texto',40],
        'casal.noiva'=>['Nome da noiva','texto',80], 'casal.noivo'=>['Nome do noivo','texto',80],
        'textos.kicker'=>['Frase do topo','texto',80], 'textos.hero_sub'=>['Subtítulo da capa','texto',80],
        'textos.convite_eyebrow'=>['Chamada','texto',120], 'textos.lead'=>['Texto principal','area',4000],
        'textos.guest_label'=>['Rótulo do convidado','texto',80], 'textos.closing'=>['Texto de fecho','area',4000],
        'textos.familias'=>['Frase das famílias','texto',120],
        'textos.anuncio_cerimonia'=>['Texto antes do nome do convidado','texto',180],
        'textos.lugares'=>['Rótulo dos lugares','texto',40], 'textos.mapa'=>['Texto do link do mapa','texto',60],
        'recepcao.titulo'=>["Título do copo d'água",'texto',80],
        'recepcao.subtitulo'=>['Texto de apresentação','texto',160],
        'recepcao.chegada_rotulo'=>['Rótulo da chegada','texto',80], 'recepcao.chegada_hora'=>['Hora da chegada','hora',5],
        'recepcao.bolo_rotulo'=>['Rótulo do bolo','texto',80], 'recepcao.bolo_hora'=>['Hora do bolo','hora',5],
        'recepcao.contagem_titulo'=>['Título da contagem','texto',80],
        'recepcao.local_titulo'=>["Título do local do copo d'água",'texto',100],
        'historia.eyebrow'=>['Chamada','texto',120], 'historia.titulo'=>['Título','texto',120],
        'historia.quote'=>['Citação de abertura','area',4000], 'historia.autor'=>['Autor da citação','texto',80],
        'interludio.quote'=>['Citação','area',4000], 'interludio.autor'=>['Autor','texto',80],
        'interludio.fecho'=>['Texto de fecho','area',4000], 'gd.eyebrow'=>['Chamada','texto',120],
        'evento.venue_titulo'=>['Título do momento','texto',80], 'cronograma.titulo'=>['Título do cronograma','texto',120],
        'evento.civil_titulo'=>['Nome da cerimónia civil','texto',40], 'evento.civil_hora'=>['Hora da cerimónia civil','hora',5],
        'evento.civil_local'=>['Local da cerimónia civil','texto',80],
        'evento.civil_maps'=>['Mapa da cerimónia civil','texto',500],
        'evento.religiosa_titulo'=>['Nome da cerimónia religiosa','texto',40],
        'evento.religiosa_hora'=>['Hora da cerimónia religiosa','hora',5],
        'evento.religiosa_local'=>['Local da cerimónia religiosa','texto',80],
        'evento.religiosa_maps'=>['Mapa da cerimónia religiosa','texto',500],
        'acesso.eyebrow'=>['Chamada','texto',120], 'acesso.titulo'=>['Título','texto',120],
        'acesso.instrucao'=>['Instrução junto ao QR','area',4000], 'acesso.nota'=>['Nota de rodapé','area',4000],
        'manual.eyebrow'=>['Chamada do manual','texto',120], 'manual.titulo'=>['Título do manual','texto',120],
        'manual.intro'=>['Introdução do manual','area',4000], 'rsvp.titulo'=>['Título do RSVP','area',4000],
        'rsvp.sub'=>['Subtítulo do RSVP','area',4000], 'rsvp.deadline'=>['Prazo de confirmação','texto',80],
        'footer.local'=>['Localidade no rodapé','texto',80], 'footer.quote'=>['Citação do rodapé','area',4000],
        'evento.data'=>['Data do evento','data',10], 'evento.hora'=>['Hora','hora',5],
        'evento.local'=>['Local','texto',120], 'evento.cidade'=>['Cidade / região','texto',80],
        'evento.whatsapp'=>['WhatsApp de contacto','texto',20],
        'geral.rolagem_auto'=>['Rolagem automática','booleano',1],
        'geral.rolagem_velocidade'=>['Velocidade da rolagem','numero',3],
        'geral.icone_musica_tocar'=>['Ícone de música parada','icone',40],
        'geral.icone_musica_pausa'=>['Ícone de música a tocar','icone',40],
    ];
    $chaves = chavesModelo($ambito);
    $out = [];
    foreach ($chaves as $chave) {
        $m = $meta[$chave] ?? [ucfirst(str_replace(['.','_'], ' ', $chave)), 'texto', 4000];
        $out[] = ['chave'=>$chave, 'rotulo'=>$m[0], 'tipo'=>$m[1], 'maximo'=>$m[2]];
    }
    return $out;
}

/** Declaração instalada no pacote, validada contra o catálogo do servidor. */
function declaracaoEditorPacote(string $ambito, ?array $modelo = null): array {
    if ($ambito !== 'digital' || !function_exists('convitePacoteResolver')) return [];
    $pacote = convitePacoteResolver($ambito, $modelo['renderer_key'] ?? null,
        $modelo['renderer_version'] ?? null, isset($modelo['renderer_schema']) ? (int)$modelo['renderer_schema'] : null);
    $editor = $pacote['manifesto']['editor'] ?? [];
    return is_array($editor) ? $editor : [];
}

/**
 * O que o código instalado consegue realmente editar neste modelo.
 *
 * A ficha guardada pelo administrador descreve a liberdade dos noivos. Esta
 * função descreve o limite anterior a essa liberdade: as ferramentas que o
 * pacote implementa. Mantê-lo num único sítio evita que a API, o editor e a
 * página dos modelos anunciem conjuntos diferentes.
 */
function capacidadesSuportadasModelo(string $ambito, ?array $modelo = null): array {
    $cat = catalogoCapacidadesModelo($ambito);
    $declarado = declaracaoEditorPacote($ambito, $modelo);
    $suportado = capacidadesModeloPadrao($ambito);
    $grupos = ['seccoes','paineis','media','efeitos','movimentaveis','campos_editaveis',
               'cores_permitidas','tipografias_permitidas','componentes','recursos'];
    foreach ($grupos as $grupo) {
        if (!array_key_exists($grupo, $declarado) || !is_array($declarado[$grupo])) continue;
        $aceites = array_flip(array_keys($cat[$grupo] ?? []));
        $suportado[$grupo] = array_values(array_unique(array_filter(
            array_map('strval', $declarado[$grupo]), fn($v)=>isset($aceites[$v]))));
    }
    if (isset($declarado['obrigatorios']) && is_array($declarado['obrigatorios'])) {
        $aceites = array_flip($suportado['seccoes']);
        $suportado['obrigatorios'] = array_values(array_unique(array_filter(
            array_map('strval', $declarado['obrigatorios']), fn($v)=>isset($aceites[$v]))));
    } else {
        $suportado['obrigatorios'] = array_values(array_intersect(
            $suportado['obrigatorios'], $suportado['seccoes']));
    }
    if (isset($declarado['limites']) && is_array($declarado['limites'])) {
        foreach ($suportado['limites'] as $k=>$origem) {
            if (!array_key_exists($k, $declarado['limites'])) continue;
            $suportado['limites'][$k] = max(1, min(5000, (int)$declarado['limites'][$k]));
        }
    }
    return $suportado;
}

/** Limita uma liberdade configurada pelo admin ao contrato do pacote. */
function limitarCapacidadesAoPacote(string $ambito, array|string|null $valor, ?array $modelo = null): array {
    $ficha = normalizarCapacidadesModelo($ambito, $valor);
    $suportado = capacidadesSuportadasModelo($ambito, $modelo);
    foreach (['seccoes','paineis','media','efeitos','movimentaveis','campos_editaveis',
              'cores_permitidas','tipografias_permitidas','componentes','recursos'] as $grupo) {
        $ficha[$grupo] = array_values(array_intersect(
            (array)($ficha[$grupo] ?? []), (array)($suportado[$grupo] ?? [])));
    }
    $ficha['obrigatorios'] = array_values(array_intersect(
        (array)($ficha['obrigatorios'] ?? []), (array)($suportado['seccoes'] ?? [])));
    $ficha['seccoes'] = array_values(array_unique(array_merge($ficha['seccoes'], $ficha['obrigatorios'])));
    foreach ((array)($suportado['limites'] ?? []) as $k=>$maximo) {
        $ficha['limites'][$k] = min((int)($ficha['limites'][$k] ?? $maximo), (int)$maximo);
    }
    return $ficha;
}

/** Contrato que o inspector recebe no navegador. */
function manifestoEditorModelo(string $ambito, array $defs, ?array $modelo = null, string $modo = 'noivos'): array {
    $id = $modelo ? ('modelo-'.(int)($modelo['id'] ?? 0)) : 'peca-actual';
    $fichaNoivos = normalizarCapacidadesModelo($ambito, $modelo['capacidades'] ?? null);
    $cat = catalogoCapacidadesModelo($ambito);
    $declarado = declaracaoEditorPacote($ambito, $modelo);
    $suportado = capacidadesSuportadasModelo($ambito, $modelo);
    $administrador = $modo === 'administrador';
    $ficha = $administrador ? $suportado : limitarCapacidadesAoPacote($ambito, $fichaNoivos, $modelo);
    $paineis = array_flip($ficha['paineis']);
    if (!isset($paineis['composicao'])) $ficha['movimentaveis'] = [];
    $rotulosPaineis = $cat['paineis'];
    $inspector = ['paineis'=>[], 'campos'=>[]];
    foreach ($ficha['paineis'] as $grupo) {
        $inspector['paineis'][] = ['id'=>$grupo, 'rotulo'=>$rotulosPaineis[$grupo] ?? ucfirst($grupo)];
    }
    $permitidos = array_flip($ficha['campos_editaveis']);
    foreach (camposInspectorModelo($ambito) as $campo) {
        if ($administrador || isset($permitidos[$campo['chave']])) $inspector['campos'][] = $campo;
    }
    return $ficha + [
        'schema'=>3, 'id'=>$id, 'modo'=>$administrador ? 'administrador' : 'noivos',
        'permissoes'=>['estrutura'=>$administrador, 'limites'=>$administrador,
                       'campos'=>$ficha['campos_editaveis'], 'liberdades_noivos'=>$fichaNoivos],
        'inspector'=>$inspector, 'pacote'=>$declarado,
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
