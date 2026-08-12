<?php
// ============================================================
// pecas.php — Peças de design (biblioteca, sem output)
//
// Material do handoff de design do convite físico:
//   · Cartão de convite 10×15 cm (impressão UV a dourado sobre acrílico)
//
// Aqui ficam as paletas, os geradores de SVG (folhagem, volutas,
// floreados) e os dados da peça. As páginas (cartoes.php, graficas.php)
// só compõem o HTML.
// ============================================================
require_once __DIR__ . '/personalizacao.php';

// ------------------------------------------------------------
// CARTÃO DE CONVITE 10×15
// ------------------------------------------------------------

/** Cartão a 720×1080 px = 100×150 mm (7,2 px = 1 mm). */
const CARTAO_L = 720;
const CARTAO_A = 1080;

/**
 * Paletas do cartão. Só o dourado é impresso; os vários tons dão
 * profundidade no ecrã. Numa impressão a um só dourado usa-se o "accent".
 */
function cartaoPaletas(): array {
    return [
        'ouro'      => ['nome'=>'Ouro quente', 'accent'=>'#a1854e', 'nameColor'=>'#c9a862', 'sub'=>'#b39a72', 'head'=>'#cfae68', 'soft'=>'#dcc79c'],
        'salvia'    => ['nome'=>'Verde sálvia','accent'=>'#7d8a6a', 'nameColor'=>'#93a178', 'sub'=>'#95a082', 'head'=>'#a7b48b', 'soft'=>'#c6cfb6'],
        'terracota' => ['nome'=>'Terracota',   'accent'=>'#b07655', 'nameColor'=>'#c88f70', 'sub'=>'#c09079', 'head'=>'#d29f83', 'soft'=>'#e0bca8'],
        'rosa'      => ['nome'=>'Rosa antigo', 'accent'=>'#a67480', 'nameColor'=>'#c295a0', 'sub'=>'#bc969f', 'head'=>'#ce9fa9', 'soft'=>'#debec5'],
    ];
}

/** Variantes de folhagem das trepadeiras. */
function cartaoFolhagens(): array {
    return [
        'eucalipto' => ['nome'=>'Eucalipto', 'L'=>34, 'W'=>22, 'fo'=>0.16, 'splay'=>44, 'sw'=>1.5],
        'oliveira'  => ['nome'=>'Oliveira',  'L'=>40, 'W'=>12, 'fo'=>0.0,  'splay'=>40, 'sw'=>1.4],
        'feto'      => ['nome'=>'Feto',      'L'=>22, 'W'=>8,  'fo'=>0.14, 'splay'=>52, 'sw'=>1.3],
        'florido'   => ['nome'=>'Florido',   'L'=>32, 'W'=>22, 'fo'=>0.16, 'splay'=>46, 'sw'=>1.5],
    ];
}

/** Paleta efetiva (chave -> array), com recurso ao ouro quente. */
function cartaoPaleta(string $chave): array {
    $p = cartaoPaletas();
    return $p[$chave] ?? $p['ouro'];
}

/** Feitios da moldura, para o editor os oferecer e o cartão os desenhar. */
function cartaoMolduras(): array {
    return [
        'simples' => ['nome'=>'Linha simples', 'nota'=>'Como foi desenhada'],
        'dupla'   => ['nome'=>'Linha dupla',   'nota'=>'Duas linhas, mais cerimoniosa'],
        'fina'    => ['nome'=>'Filete fino',   'nota'=>'Discreta, quase só sugerida'],
        'cantos'  => ['nome'=>'Só os cantos',  'nota'=>'Esquadrias nos quatro cantos'],
    ];
}

/** O feitio da moldura, em variáveis CSS. */
function cartaoMolduraVars(string $estilo): string {
    // --ct-mold-linha é a espessura da linha visível; --ct-mold-larg é a borda
    // da caixa (zero no feitio "cantos", que desenha só as esquadrias).
    switch ($estilo) {
        case 'fina':   return '--ct-mold-linha:.7px;--ct-mold-larg:.7px';
        case 'dupla':  return '--ct-mold-linha:1.4px;--ct-mold-larg:1.4px;'
                            . '--ct-mold-sombra:inset 0 0 0 4px transparent, inset 0 0 0 5.4px var(--ct-accent)';
        case 'cantos': return '--ct-mold-linha:1.4px;--ct-mold-larg:0;--ct-mold-cantos:block';
        default:       return '--ct-mold-linha:1.4px;--ct-mold-larg:1.4px';
    }
}

/** A espessura de origem de cada feitio, em px — o editor precisa dela para
 *  compensar o zoom da prova. */
function cartaoMolduraLinha(string $estilo): float {
    return $estilo === 'fina' ? 0.7 : 1.4;
}

/** Folhagem efetiva (chave -> array), com recurso a eucalipto. */
function cartaoFolhagem(string $chave): array {
    $f = cartaoFolhagens();
    return $f[$chave] ?? $f['eucalipto'];
}

/**
 * Nomes das cores da paleta do cartão como estão em cartaoPaletas(), por
 * variável CSS: 'accent' -> --ct-accent, mas a chave da paleta é 'nameColor'.
 */
function cartaoChavesCor(): array {
    return ['accent'=>'accent', 'name'=>'nameColor', 'sub'=>'sub', 'head'=>'head', 'soft'=>'soft'];
}

/**
 * Paleta que o cartão usa mesmo: a escolhida, com as cores livres por cima.
 * Sem cores livres é exatamente a paleta escolhida — quem não mexer não nota.
 */
function cartaoPaletaEfetiva(array $defs): array {
    $pal = cartaoPaleta($defs['cartao.paleta'] ?? 'ouro');
    $ov  = json_decode($defs['cartao.cores'] ?: '[]', true) ?: [];
    foreach (cartaoChavesCor() as $var => $chave) {
        if (corHexValida($ov[$var] ?? null)) $pal[$chave] = strtoupper($ov[$var]);
    }
    return $pal;
}

/**
 * Papéis tipográficos do cartão. São os mesmos três do convite digital, mas
 * com escolhas próprias: o cartão é gravado a dourado e pede outra letra.
 */
function papeisCartao(): array {
    return ['script' => ['chave'=>'cartao.fonte_script', 'rotulo'=>'Nomes e caligrafia', 'origem'=>'alexbrush'],
            'serif'  => ['chave'=>'cartao.fonte_serif',  'rotulo'=>'Frases e data',      'origem'=>'cormorant'],
            'sans'   => ['chave'=>'cartao.fonte_sans',   'rotulo'=>'Rótulos e detalhes', 'origem'=>'montserrat']];
}

/**
 * As variáveis CSS do cartão: cor, letra e escala do texto.
 * Vão no atributo style do .cartao, para o editor as poder trocar ao vivo e as
 * páginas de impressão as trazerem já resolvidas.
 */
function cartaoEstiloVars(array $defs): string {
    $pal = cartaoPaletaEfetiva($defs);
    $v = '--ct-accent:'.$pal['accent'].';--ct-name:'.$pal['nameColor']
       . ';--ct-sub:'.$pal['sub'].';--ct-head:'.$pal['head'].';--ct-soft:'.$pal['soft'];
    $fontes = fontesConvite();
    foreach (papeisCartao() as $papel => $p) {
        $id = $defs[$p['chave']] ?? $p['origem'];
        $f  = $fontes[$id] ?? $fontes[$p['origem']];
        $v .= ';--cf-'.$papel.':'.$f['css'];
    }
    $esc = (int)($defs['cartao.escala'] ?? 100);
    $v .= ';--ct-esc:'.(max(85, min(115, $esc)) / 100);
    // Camadas decorativas: feitio e margem da moldura, tamanho de cada ornamento.
    $v .= ';--ct-mold-margem:'.max(16, min(48, (int)($defs['cartao.moldura_margem'] ?? 28))).'px';
    $v .= ';'.cartaoMolduraVars($defs['cartao.moldura_estilo'] ?? 'simples');
    foreach (['ramos','volutas','floreados'] as $orn) {
        $n = (int)($defs['cartao.'.$orn.'_escala'] ?? 100);
        $v .= ';--ct-esc-'.$orn.':'.(max(60, min(140, $n)) / 100);
    }
    return $v;
}

// ---- Geometria: curva de Bézier cúbica ----------------------

/** Ponto na curva de Bézier cúbica definida por 4 pontos [x,y]. */
function bezPonto(array $P, float $t): array {
    $u = 1 - $t;
    return [
        $u*$u*$u*$P[0][0] + 3*$u*$u*$t*$P[1][0] + 3*$u*$t*$t*$P[2][0] + $t*$t*$t*$P[3][0],
        $u*$u*$u*$P[0][1] + 3*$u*$u*$t*$P[1][1] + 3*$u*$t*$t*$P[2][1] + $t*$t*$t*$P[3][1],
    ];
}

/** Derivada (tangente) da curva no parâmetro $t — dá o ângulo da folha. */
function bezTangente(array $P, float $t): array {
    $u = 1 - $t;
    return [
        3*$u*$u*($P[1][0]-$P[0][0]) + 6*$u*$t*($P[2][0]-$P[1][0]) + 3*$t*$t*($P[3][0]-$P[2][0]),
        3*$u*$u*($P[1][1]-$P[0][1]) + 6*$u*$t*($P[2][1]-$P[1][1]) + 3*$t*$t*($P[3][1]-$P[2][1]),
    ];
}

/** Uma folha em elipse (dois arcos Q), rodada e posicionada. */
function svgFolha(float $cx, float $cy, float $ang, array $g, string $cor): string {
    $L = $g['L']; $W = $g['W'];
    return sprintf(
        '<path d="M%.1f 0 Q 0 %.1f %.1f 0 Q 0 %.1f %.1f 0 Z" transform="translate(%.1f %.1f) rotate(%.1f)" fill="%s" fill-opacity="%s" stroke="%s" stroke-width="1.3"/>',
        -$L/2, -$W/2, $L/2, $W/2, -$L/2, $cx, $cy, $ang, $cor, $g['fo'], $cor
    );
}

/** Um "braço" da trepadeira: caule em Bézier + folhas alternadas ao longo dele. */
function svgBraco(array $P, int $n, int $inicio, array $g, string $cor): string {
    $s = sprintf('<path d="M%s %s C %s %s %s %s %s %s" fill="none" stroke="%s" stroke-width="%s" stroke-linecap="round"/>',
        $P[0][0],$P[0][1], $P[1][0],$P[1][1], $P[2][0],$P[2][1], $P[3][0],$P[3][1], $cor, $g['sw']);
    for ($i = 0; $i < $n; $i++) {
        $t   = ($i + 0.6) / ($n + 0.2);
        $pt  = bezPonto($P, $t);
        $d   = bezTangente($P, $t);
        $ang = atan2($d[1], $d[0]) * 180 / M_PI;
        $lado = (($i + $inicio) % 2 === 0) ? 1 : -1;
        $la  = $ang + $lado * $g['splay'];
        $rad = $la * M_PI / 180;
        $off = $g['L']/2 + 2;
        $s  .= svgFolha($pt[0] + cos($rad)*$off, $pt[1] + sin($rad)*$off, $la, $g, $cor);
    }
    return $s;
}

/**
 * Trepadeira do canto (dois braços: um pelo topo, outro pela lateral).
 * Caixa 132×270 px no cartão; viewBox 214×434.
 */
function svgTrepadeira(string $folhagem, string $cor): string {
    $g = cartaoFolhagem($folhagem);
    $topo    = svgBraco([[192,26],[150,16],[96,30],[34,22]],   6, 0, $g, $cor);
    $lateral = svgBraco([[192,26],[206,104],[180,240],[192,416]], 8, 1, $g, $cor);
    return '<svg viewBox="0 0 214 434" preserveAspectRatio="xMidYMid meet" style="width:100%;height:100%;display:block;overflow:visible">'
         . $topo . $lateral . '</svg>';
}

/** Espiral (raio decrescente) usada na voluta de canto. */
function svgEspiral(float $cx, float $cy, float $r0, float $r1, float $a0, float $sweep, int $n): string {
    $d = '';
    for ($i = 0; $i <= $n; $i++) {
        $t = $i / $n;
        $a = $a0 + $sweep * $t;
        $r = $r0 + ($r1 - $r0) * $t;
        $x = $cx + cos($a) * $r;
        $y = $cy + sin($a) * $r;
        $d .= ($i === 0 ? sprintf('M %.1f %.1f', $x, $y) : sprintf(' L %.1f %.1f', $x, $y));
    }
    return $d;
}

/** Voluta ornamental de canto: varrimento + espiral + folha, espelhada, + losango. */
function svgVoluta(string $cor): string {
    $varrimento = 'M120 22 C 88 22 56 28 46 54';
    $caracol    = svgEspiral(43, 66, 15, 2.5, -M_PI*0.52, M_PI*1.72, 44);
    $folha      = '<path d="M90 20 Q 103 8 118 15 Q 105 27 90 20 Z" fill="'.$cor.'" opacity="0.9"/>';
    $grupo = '<path d="'.$varrimento.'" fill="none" stroke="'.$cor.'" stroke-width="1.5" stroke-linecap="round"/>'
           . '<path d="'.$caracol.'" fill="none" stroke="'.$cor.'" stroke-width="1.4" stroke-linecap="round"/>'
           . $folha;
    $espelhado = '<g transform="matrix(0 1 1 0 0 0)">'.$grupo.'</g>';
    $losango   = '<path d="M31 31 l7 7 -7 7 -7 -7 z" fill="'.$cor.'"/>';
    return '<svg viewBox="0 0 150 150" preserveAspectRatio="xMidYMid meet" style="width:100%;height:100%;display:block;overflow:visible">'
         . $grupo . $espelhado . $losango . '</svg>';
}

/** Floreado caligráfico que ladeia os nomes dos noivos. */
/**
 * Feitios do floreado que ladeia os nomes.
 *
 * Todos desenhados na mesma caixa (150 × 110) e com a MESMA âncora: a ponta
 * fina sai a meio da margem direita (y ≈ 55), que é o lado virado para os
 * nomes. Sem essa regra, trocar de feitio mudava também o sítio — e o que
 * era uma escolha de desenho passava a ser um desalinhamento.
 */
function cartaoFloreados(): array {
    return [
        'classico' => ['nome' => 'Clássico',  'nota' => 'Uma volta longa com o gancho na ponta'],
        'voluta'   => ['nome' => 'Voluta',    'nota' => 'Enrola-se sobre si, como as dos cantos'],
        'ramo'     => ['nome' => 'Raminho',   'nota' => 'Uma haste com folhas, a condizer com as trepadeiras'],
        'filete'   => ['nome' => 'Filete',    'nota' => 'Uma linha e um losango — o mais discreto'],
        'gota'     => ['nome' => 'Gota',      'nota' => 'Duas curvas que se cruzam numa lágrima'],
    ];
}

/** Feitio efetivo, com recurso ao clássico. */
function cartaoFloreado(string $chave): array {
    $f = cartaoFloreados();
    return $f[$chave] ?? $f['classico'];
}

/**
 * Um floreado, no feitio pedido. Só traço — o cartão é gravado a um só
 * dourado, e uma mancha cheia não se imprime a foil sem virar borrão.
 */
function svgFloreado(string $cor, string $tipo = 'classico'): string {
    $t = fn(string $d, float $w = 1.5) =>
        '<path d="'.$d.'" fill="none" stroke="'.$cor.'" stroke-width="'.$w.'" stroke-linecap="round"/>';
    switch ($tipo) {
        case 'voluta':
            // Dois enrolamentos: o de fora abre, o de dentro fecha sobre si.
            $c = $t('M150 55 C 112 58 74 52 50 38 C 30 26 32 8 48 10 C 60 12 60 27 44 32 C 26 38 16 50 16 66')
               . $t('M16 66 C 15 76 22 82 30 80', 1.2);
            break;
        case 'ramo':
            // Haste com folhas alternadas: a mesma linguagem das trepadeiras.
            $c = $t('M150 55 C 108 60 62 52 26 28');
            foreach ([[112,52,-24],[88,49,20],[64,44,-28],[44,37,16]] as [$x,$y,$a]) {
                $c .= '<path d="M0 0 C 9 -7 22 -6 27 0 C 22 6 9 7 0 0 Z" transform="translate('.$x.' '.$y.') rotate('.$a.')"'
                    . ' fill="none" stroke="'.$cor.'" stroke-width="1.2"/>';
            }
            $c .= $t('M26 28 C 18 20 20 10 30 12', 1.2);
            break;
        case 'filete':
            // Uma régua que afina, com um losango a fechar. O mais discreto de
            // todos, e o que melhor se porta em cartões com nomes compridos.
            $c = $t('M150 55 L 52 55', 1.4)
               . '<path d="M52 55 l 9 -7 l 9 7 l -9 7 Z" fill="'.$cor.'" stroke="none"/>'
               . $t('M34 55 L 12 55', 1.1);
            break;
        case 'gota':
            // Duas curvas que se cruzam e fecham numa lágrima.
            $c = $t('M150 55 C 108 57 62 50 34 32 C 16 20 22 4 40 10 C 54 15 52 34 30 46 C 18 53 12 66 16 78')
               . $t('M150 55 C 116 53 78 48 56 38', 1.1);
            break;
        default:   // clássico — a volta longa de origem, agora ancorada a meio
            $c = $t('M150 55 C 92 58 38 44 22 22')
               . $t('M22 22 C 14 10 30 0 38 12 C 43 21 32 28 22 23', 1.3);
    }
    return '<svg viewBox="0 0 150 110" style="width:100%;height:100%;display:block;overflow:visible">'
         . $c . '</svg>';
}

/**
 * Dados do cartão a partir das definições do evento (parte comum a todos
 * os convidados). Os campos por convidado entram em renderCartaoConvite().
 */
function cartaoDadosEvento(array $defs): array {
    $data = $defs['evento.data'];
    $ts   = strtotime($data);
    return [
        'noiva'        => $defs['casal.noiva'],
        'noivo'        => $defs['casal.noivo'],
        'abertura'     => $defs['cartao.abertura'],
        'frase'        => $defs['cartao.frase_convite'],
        'reservado'    => $defs['cartao.reservado'],
        'data_ext'     => dataExtensa($data),
        'dia_semana'   => $ts ? DIAS_PT[(int)date('w', $ts)] : '',
        'civil_titulo' => $defs['evento.civil_titulo'],
        'civil_hora'   => horaTexto($defs['evento.civil_hora'] ?? '', false),
        'civil_local'  => trim((string)($defs['evento.civil_local'] ?? '')),
        // A religiosa é opcional, como a civil: sem hora, não se anuncia.
        'relig_titulo' => $defs['evento.religiosa_titulo'],
        'relig_hora'   => horaTexto($defs['evento.religiosa_hora'] ?? '', false),
        'relig_local'  => trim((string)($defs['evento.religiosa_local'] ?? '')),
        'floreado'     => $defs['cartao.floreado'] ?? 'classico',
        'copo_titulo'  => $defs['evento.venue_titulo'],
        'local'        => $defs['evento.local'],
        'copo_hora'    => horaTexto($defs['evento.hora'], false),
        'frase_final'  => $defs['cartao.frase_final'],
    ];
}

/**
 * Cartão de convite completo (720×1080), personalizado para um convidado.
 *
 * $conv: ['nome'=>string, 'mesas'=>[['nome'=>..,'n'=>..], …]]
 *        'mesas' vem de mesasDoConvite(); $comLugares controla se
 *        aparece o "N lugares" por baixo do nome da mesa.
 */
/**
 * Nome do convidado tal como sai no cartão: o nome e, se existir, o sufixo
 * escrito entre parênteses (ex.: "e acompanhante"). O número de lugares não
 * entra no nome — aparece, quando pedido, no bloco das mesas.
 */
function nomeParaCartao(array $c): string {
    return nomeConviteVisivel($c);
}

/** Camadas do cartão (ordem de topo para base, como no painel de camadas). */
function cartaoCamadas(): array {
    return [
        'ramos'     => 'Trepadeiras',
        'volutas'   => 'Volutas de canto',
        'moldura'   => 'Moldura',
        'floreados' => 'Floreados',
        'abertura'  => 'Abertura',
        'nomes'     => 'Nomes dos noivos',
        'frase'     => 'Frase de convite',
        'convidado' => 'Bloco do convidado',
        'mesas'     => 'Mesas',
        'data'      => 'Data',
        'logistica' => 'Logística',
        'fecho'     => 'Frase final',
    ];
}

/** Deslocamento gravado de cada camada, em % do cartão (ausente = no sítio). */
function cartaoPosicoes(array $defs): array {
    return posicoesGravadas($defs['cartao.posicoes'] ?? '');
}

/**
 * O bloco de logística do cartão: as cerimónias que houver, e a receção.
 *
 * Cada cerimónia só aparece se tiver HORA — é assim que se acrescenta e se
 * remove uma, sem campo à parte a dizer "mostrar". O primeiro título visível
 * não leva a margem de cima (ct-seccao-2), senão o bloco nascia com um vazio
 * por cima quando a civil não existe.
 *
 * Vive numa função porque o editor a redesenha ao vivo, e duas cópias do
 * mesmo desenho acabam sempre a discordar uma da outra.
 */
function cartaoLogistica(array $ev): string {
    $e = fn($s) => escP($s);
    $h = ''; $primeiro = true;
    $bloco = function (string $titulo, string $hora, string $local) use (&$h, &$primeiro, $e) {
        if ($hora === '') return;
        $h .= '<div class="ct-seccao' . ($primeiro ? '' : ' ct-seccao-2') . '">' . $e($titulo) . '</div>'
            . '<div class="ct-detalhe">às ' . $e($hora)
            . ($local !== '' ? '<br>' . $e($local) : '') . '</div>';
        $primeiro = false;
    };
    $bloco($ev['civil_titulo'], $ev['civil_hora'], $ev['civil_local']);
    $bloco($ev['relig_titulo'], $ev['relig_hora'], $ev['relig_local']);
    // A receção não é opcional: é a festa, e é para ela que se convida.
    $h .= '<div class="ct-seccao' . ($primeiro ? '' : ' ct-seccao-2') . '" data-campo="copo_titulo">'
        . $e($ev['copo_titulo']) . '</div>'
        . '<div class="ct-detalhe ct-detalhe-2">' . $e($ev['local'])
        . ($ev['copo_hora'] !== '' ? '<br>às ' . $e($ev['copo_hora']) : '') . '</div>';
    return $h;
}

/** Camadas trancadas do cartão: não se arrastam nem se escondem. */
function cartaoTrancadas(array $defs): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($defs['cartao.trancados'] ?? '')))));
}

/** Visibilidade das camadas, a partir da definição JSON (ausente = visível). */
function cartaoCamadasVisiveis(array $defs): array {
    $j = json_decode($defs['cartao.camadas'] ?? '', true);
    $out = [];
    foreach (cartaoCamadas() as $k => $_) {
        $out[$k] = (is_array($j) && array_key_exists($k, $j)) ? (int)!empty($j[$k]) : 1;
    }
    return $out;
}

/**
 * Cartão de convite completo (720×1080), personalizado para um convidado.
 *
 * As cores entram como variáveis CSS (--ct-*) e os SVG usam currentColor:
 * assim o editor troca de paleta sem voltar ao servidor. Cada bloco leva
 * data-camada, para o painel de camadas o poder selecionar e ocultar.
 *
 * $conv: ['nome'=>string, 'mesas'=>[['nome'=>..,'n'=>..], …]]
 */
function renderCartaoConvite(array $ev, array $conv, array $pal, string $folhagem, bool $comLugares = true,
                            array $camadas = [], ?string $estilo = null, array $posicoes = []): string {
    $e = fn($s) => escP($s);
    // Camada oculta: ausente do array = visível.
    $oc = fn($k) => (array_key_exists($k, $camadas) && !$camadas[$k]) ? ' ct-oculta' : '';
    // Camada movida: o deslocamento entra como variáveis, e o CSS trata do
    // resto. Sem entrada no mapa não sai atributo nenhum — um cartão que
    // ninguém arrastou continua a ser exatamente o HTML de sempre.
    $ps = function (string $k) use ($posicoes): string {
        $p = $posicoes[$k] ?? null;
        if (!is_array($p)) return '';
        // Duas leituras do mesmo: --px/--py/--pa são o que está GRAVADO (em %
        // do cartão e em graus, que é como se lê e se confere); --mv/--rt são
        // o valor já convertido para o que o CSS aplica. Sem os segundos, a
        // regra teria de calcular a partir de zero — e um translate a zero
        // continua a ser um translate (ver o comentário em pecas.css).
        $x = (float)$p['x']; $y = (float)$p['y']; $a = (float)($p['a'] ?? 0);
        return ' style="--px:' . $x . ';--py:' . $y . ';--pa:' . $a
             . ';--mv:' . round($x * 7.2, 3) . 'px ' . round($y * 10.8, 3) . 'px'
             . ';--rt:' . $a . 'deg"';
    };

    // Quem passa $estilo (de cartaoEstiloVars) traz também a letra e a escala;
    // sem ele, só as cores — e o CSS trata do resto com os valores de origem.
    $vars = $estilo ?? ('--ct-accent:' . $pal['accent'] . ';--ct-name:' . $pal['nameColor']
          . ';--ct-sub:' . $pal['sub'] . ';--ct-head:' . $pal['head'] . ';--ct-soft:' . $pal['soft']);

    // Blocos de mesa, separados por divisória vertical (o design usa 2 colunas).
    $mesas = '';
    foreach ($conv['mesas'] as $i => $m) {
        if ($i > 0) $mesas .= '<div class="ct-div-v"></div>';
        $mesas .= '<div class="ct-mesa"><div class="ct-mesa-n">' . $e($m['nome']) . '</div>';
        if ($comLugares) {
            $n = (int)$m['n'];
            $mesas .= '<div class="ct-mesa-l">' . $n . ' ' . ($n === 1 ? 'Lugar' : 'Lugares') . '</div>';
        }
        $mesas .= '</div>';
    }
    $blocoMesas = $mesas !== '' ? '<div class="ct-mesas' . $oc('mesas') . '" data-camada="mesas"' . $ps('mesas') . '>' . $mesas . '</div>' : '';

    ob_start(); ?>
<div class="cartao" style="<?= $vars ?>">
  <!-- trepadeiras: canto superior-direito e inferior-esquerdo (rodada 180°) -->
  <div class="ct-ramos<?= $oc('ramos') ?>" data-camada="ramos"<?= $ps('ramos') ?>>
    <div class="ct-ramo ct-ramo-sd"><?= svgTrepadeira($folhagem, 'currentColor') ?></div>
    <div class="ct-ramo ct-ramo-ie"><?= svgTrepadeira($folhagem, 'currentColor') ?></div>
  </div>

  <!-- moldura dourada contínua + volutas de canto -->
  <div class="ct-moldura<?= $oc('moldura') ?>" data-camada="moldura"<?= $ps('moldura') ?>><i></i><i></i></div>
  <div class="ct-volutas<?= $oc('volutas') ?>" data-camada="volutas"<?= $ps('volutas') ?>>
    <div class="ct-voluta ct-voluta-se"><?= svgVoluta('currentColor') ?></div>
    <div class="ct-voluta ct-voluta-id"><?= svgVoluta('currentColor') ?></div>
  </div>

  <div class="ct-conteudo">
    <!-- topo: abertura + nomes + frase -->
    <div class="ct-topo">
      <div class="ct-abertura<?= $oc('abertura') ?>" data-camada="abertura"<?= $ps('abertura') ?> data-campo="abertura"><?= nl2br($e($ev['abertura']), false) ?></div>
      <div class="ct-nomes<?= $oc('nomes') ?>" data-camada="nomes"<?= $ps('nomes') ?>>
        <div class="ct-floreados<?= $oc('floreados') ?>" data-camada="floreados"<?= $ps('floreados') ?>>
          <div class="ct-floreado ct-floreado-e"><?= svgFloreado('currentColor', $ev['floreado']) ?></div>
          <div class="ct-floreado ct-floreado-d"><?= svgFloreado('currentColor', $ev['floreado']) ?></div>
        </div>
        <div class="ct-nome" data-campo="noiva"><?= $e($ev['noiva']) ?></div>
        <div class="ct-coracao">&#9825;</div>
        <div class="ct-nome" data-campo="noivo"><?= $e($ev['noivo']) ?></div>
      </div>
      <p class="ct-frase<?= $oc('frase') ?>" data-camada="frase"<?= $ps('frase') ?> data-campo="frase"><?= $e($ev['frase']) ?></p>
    </div>

    <!-- centro: o convidado (personalizado) -->
    <div class="ct-centro<?= $oc('convidado') ?>" data-camada="convidado"<?= $ps('convidado') ?>>
      <div class="ct-filete"></div>
      <div class="ct-reservado" data-campo="reservado"><?= $e($ev['reservado']) ?></div>
      <div class="ct-convidado"><?= $e($conv['nome']) ?></div>
      <?= $blocoMesas ?>
      <div class="ct-filete"></div>
    </div>

    <!-- base: data + logística + frase final -->
    <div class="ct-base">
      <div class="ct-bloco-data<?= $oc('data') ?>" data-camada="data"<?= $ps('data') ?>>
        <div class="ct-data"><?= $e($ev['data_ext']) ?></div>
        <div class="ct-dia"><?= $e($ev['dia_semana']) ?></div>
      </div>
      <div class="ct-tracinho"></div>
      <div class="ct-logistica<?= $oc('logistica') ?>" data-camada="logistica"<?= $ps('logistica') ?>>
        <?php // Cada cerimónia só aparece se tiver hora. Um cartão que anuncia
              // "às " sem hora nenhuma é pior do que um cartão sem a linha. ?>
        <?php if ($ev['civil_hora'] !== ''): ?>
          <div class="ct-seccao" data-campo="civil_titulo"><?= $e($ev['civil_titulo']) ?></div>
          <div class="ct-detalhe">às <?= $e($ev['civil_hora']) ?><?php
            if ($ev['civil_local'] !== ''): ?><br><?= $e($ev['civil_local']) ?><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($ev['relig_hora'] !== ''): ?>
          <div class="ct-seccao ct-seccao-2" data-campo="relig_titulo"><?= $e($ev['relig_titulo']) ?></div>
          <div class="ct-detalhe">às <?= $e($ev['relig_hora']) ?><?php
            if ($ev['relig_local'] !== ''): ?><br><?= $e($ev['relig_local']) ?><?php endif; ?></div>
        <?php endif; ?>
        <div class="ct-seccao ct-seccao-2" data-campo="copo_titulo"><?= $e($ev['copo_titulo']) ?></div>
        <div class="ct-detalhe ct-detalhe-2"><?= $e($ev['local']) ?><?php
          // Sem hora não se escreve "às " a apontar para nada.
          if ($ev['copo_hora'] !== ''): ?><br>às <?= $e($ev['copo_hora']) ?><?php endif; ?></div>
      </div>
      <div class="ct-tracinho ct-tracinho-2"></div>
      <p class="ct-fecho<?= $oc('fecho') ?>" data-camada="fecho"<?= $ps('fecho') ?> data-campo="frase_final"><?= $e($ev['frase_final']) ?></p>
    </div>

  </div>
</div>
<?php
    return ob_get_clean();
}
