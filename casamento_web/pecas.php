<?php
// ============================================================
// pecas.php — Peças de design (biblioteca, sem output)
//
// Reúne o material dos handoffs de design:
//   · Cartão de convite 10×15 cm (impressão UV a dourado sobre acrílico)
//   · Porta-chaves comemorativo 45×60 mm (monograma I&A)
//
// Aqui ficam as paletas, os geradores de SVG (folhagem, volutas,
// floreados) e os dados das peças. As páginas (cartoes.php,
// porta-chaves.php) só compõem o HTML.
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

/** Folhagem efetiva (chave -> array), com recurso a eucalipto. */
function cartaoFolhagem(string $chave): array {
    $f = cartaoFolhagens();
    return $f[$chave] ?? $f['eucalipto'];
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
function svgFloreado(string $cor): string {
    return '<svg viewBox="0 0 150 110" style="width:100%;height:100%;display:block;overflow:visible">'
         . '<path d="M148 98 C 90 100 36 84 20 36 C 12 14 34 2 46 20" fill="none" stroke="'.$cor.'" stroke-width="1.5" stroke-linecap="round"/>'
         . '<path d="M46 20 C 41 11 30 11 27 21" fill="none" stroke="'.$cor.'" stroke-width="1.3" stroke-linecap="round"/>'
         . '</svg>';
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
        'civil_titulo' => $defs['cartao.civil_titulo'],
        'civil_hora'   => horaTexto($defs['cartao.civil_hora'], false),
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
 * Nome do convidado tal como sai no cartão. Com $comNumero a falso, omite
 * o "(N)" de lugares — que no cartão já aparece no bloco das mesas — mas
 * mantém sempre um sufixo escrito (ex.: "e acompanhante").
 */
function nomeParaCartao(array $c, bool $comNumero = true): string {
    if ($comNumero) return nomeConviteVisivel($c);
    $nome = trim($c['nome_exibicao']);
    $suf  = trim((string)($c['sufixo'] ?? ''));
    return $suf !== '' ? "$nome ($suf)" : $nome;
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
function renderCartaoConvite(array $ev, array $conv, array $pal, string $folhagem, bool $comLugares = true, array $camadas = []): string {
    $e = fn($s) => escP($s);
    // Camada oculta: ausente do array = visível.
    $oc = fn($k) => (array_key_exists($k, $camadas) && !$camadas[$k]) ? ' ct-oculta' : '';

    $vars = '--ct-accent:' . $pal['accent'] . ';--ct-name:' . $pal['nameColor']
          . ';--ct-sub:' . $pal['sub'] . ';--ct-head:' . $pal['head'] . ';--ct-soft:' . $pal['soft'];

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
    $blocoMesas = $mesas !== '' ? '<div class="ct-mesas' . $oc('mesas') . '" data-camada="mesas">' . $mesas . '</div>' : '';

    ob_start(); ?>
<div class="cartao" style="<?= $vars ?>">
  <!-- trepadeiras: canto superior-direito e inferior-esquerdo (rodada 180°) -->
  <div class="ct-ramos<?= $oc('ramos') ?>" data-camada="ramos">
    <div class="ct-ramo ct-ramo-sd"><?= svgTrepadeira($folhagem, 'currentColor') ?></div>
    <div class="ct-ramo ct-ramo-ie"><?= svgTrepadeira($folhagem, 'currentColor') ?></div>
  </div>

  <!-- moldura dourada contínua + volutas de canto -->
  <div class="ct-moldura<?= $oc('moldura') ?>" data-camada="moldura"></div>
  <div class="ct-volutas<?= $oc('volutas') ?>" data-camada="volutas">
    <div class="ct-voluta ct-voluta-se"><?= svgVoluta('currentColor') ?></div>
    <div class="ct-voluta ct-voluta-id"><?= svgVoluta('currentColor') ?></div>
  </div>

  <div class="ct-conteudo">
    <!-- topo: abertura + nomes + frase -->
    <div class="ct-topo">
      <div class="ct-abertura<?= $oc('abertura') ?>" data-camada="abertura"><?= nl2br($e($ev['abertura']), false) ?></div>
      <div class="ct-nomes<?= $oc('nomes') ?>" data-camada="nomes">
        <div class="ct-floreados<?= $oc('floreados') ?>" data-camada="floreados">
          <div class="ct-floreado ct-floreado-e"><?= svgFloreado('currentColor') ?></div>
          <div class="ct-floreado ct-floreado-d"><?= svgFloreado('currentColor') ?></div>
        </div>
        <div class="ct-nome" data-campo="noiva"><?= $e($ev['noiva']) ?></div>
        <div class="ct-coracao">&#9825;</div>
        <div class="ct-nome" data-campo="noivo"><?= $e($ev['noivo']) ?></div>
      </div>
      <p class="ct-frase<?= $oc('frase') ?>" data-camada="frase" data-campo="frase"><?= $e($ev['frase']) ?></p>
    </div>

    <!-- centro: o convidado (personalizado) -->
    <div class="ct-centro<?= $oc('convidado') ?>" data-camada="convidado">
      <div class="ct-filete"></div>
      <div class="ct-reservado" data-campo="reservado"><?= $e($ev['reservado']) ?></div>
      <div class="ct-convidado"><?= $e($conv['nome']) ?></div>
      <?= $blocoMesas ?>
      <div class="ct-filete"></div>
    </div>

    <!-- base: data + logística + frase final -->
    <div class="ct-base">
      <div class="ct-bloco-data<?= $oc('data') ?>" data-camada="data">
        <div class="ct-data"><?= $e($ev['data_ext']) ?></div>
        <div class="ct-dia"><?= $e($ev['dia_semana']) ?></div>
      </div>
      <div class="ct-tracinho"></div>
      <div class="ct-logistica<?= $oc('logistica') ?>" data-camada="logistica">
        <div class="ct-seccao" data-campo="civil_titulo"><?= $e($ev['civil_titulo']) ?></div>
        <div class="ct-detalhe">às <?= $e($ev['civil_hora']) ?></div>
        <div class="ct-seccao ct-seccao-2" data-campo="copo_titulo"><?= $e($ev['copo_titulo']) ?></div>
        <div class="ct-detalhe ct-detalhe-2"><?= $e($ev['local']) ?><br>às <?= $e($ev['copo_hora']) ?></div>
      </div>
      <div class="ct-tracinho ct-tracinho-2"></div>
      <p class="ct-fecho<?= $oc('fecho') ?>" data-camada="fecho" data-campo="frase_final"><?= $e($ev['frase_final']) ?></p>
    </div>

  </div>
</div>
<?php
    return ob_get_clean();
}

// ------------------------------------------------------------
// PORTA-CHAVES COMEMORATIVO (45×60 mm)
// ------------------------------------------------------------

/** Acabamentos do porta-chaves (chave => tokens de cor). */
function chaveiroAcabamentos(): array {
    return [
        'classic' => [
            'nome'    => 'Ouro sobre Ébano',
            'amostra' => 'radial-gradient(circle at 34% 28%, #241d14, #080705 78%)',
            'fundo'   => 'radial-gradient(120% 110% at 50% 4%, #1e1811, #0a0806 56%, #030202)',
            'ouro'    => '#C9A24A', 'fio' => 'rgba(201,162,74,.40)',
            'cartela' => '#C2A15C', 'quadra' => '#ECD7A6',
            'mono'    => '#ECD08A', 'nomes'  => '#F2DFAE',
        ],
        'forest' => [
            'nome'    => 'Floresta',
            'amostra' => 'radial-gradient(circle at 34% 28%, #33523d, #10201a 78%)',
            'fundo'   => 'radial-gradient(120% 110% at 50% 4%, #2f4d3a, #16261E 56%, #0a1510)',
            'ouro'    => '#D9BC8C', 'fio' => 'rgba(217,188,140,.40)',
            'cartela' => '#D9BC8C', 'quadra' => '#F1E6CE',
            'mono'    => '#EEDA9E', 'nomes'  => '#F1E6CE',
        ],
        'ivory' => [
            'nome'    => 'Marfim',
            'amostra' => 'radial-gradient(circle at 34% 28%, #FEFBF5, #E7D8BC 78%)',
            'fundo'   => 'radial-gradient(120% 110% at 50% 4%, #FEFCF7, #F6EDDC 56%, #E9DBC0)',
            'ouro'    => '#B4864A', 'fio' => 'rgba(180,134,74,.40)',
            'cartela' => '#8A6329', 'quadra' => '#39483E',
            'mono'    => '#8A6329', 'nomes'  => '#6B4A1E',
        ],
    ];
}

/** As 8 quadras disponíveis para o verso do porta-chaves. */
function chaveiroQuadras(): array {
    return [
        "Cada porta pede uma chave,\ncada caminho pede um passo;\no coração pede apenas\namor, e todos os dias.",
        "Onde mora o amor,\nencontra-se o lar;\ne nenhuma distância apaga\no caminho de volta.",
        "O amor é a memória\ndo futuro que escolhemos viver,\num dia de cada vez,\nsempre de mãos dadas.",
        "A felicidade é a arte\nde caminhar lado a lado,\nsem pressa de chegar,\nporque o caminho já é casa.",
        "Onde houver amor,\nhaverá sempre um lar,\nainda que mudem as paredes\ne o tempo passe por elas.",
        "O amor é a chave\nque abre todos os caminhos\ne nos traz, todos os dias,\nde volta a casa.",
        "O verdadeiro lar\nnão tem morada:\né o lugar onde dois corações\nse encontram e ficam.",
        "O amor abre portas\nque o tempo jamais fechará,\nnem a distância,\nnem o silêncio dos anos.",
    ];
}

/** Numeração romana das quadras. */
function chaveiroRomanos(): array {
    return ['I','II','III','IV','V','VI','VII','VIII'];
}

/** Acabamento efetivo, com recurso ao clássico. */
function chaveiroAcabamento(string $chave): array {
    $a = chaveiroAcabamentos();
    return $a[$chave] ?? $a['classic'];
}

/**
 * Divisores ornamentais da peça.
 *   'duplo'   — losango vazado ─ losango cheio ─ losango vazado (data, coordenadas)
 *   'simples' — filete · losango pequeno · losango grande · losango pequeno · filete (quadra)
 */
function chaveiroDivisor(array $ac, string $tipo = 'duplo'): string
{
    $ouro = $ac['ouro']; $fio = $ac['fio'];
    if ($tipo === 'duplo') {
        return '<div class="div-orn">'
             . '<b class="lz" style="border-color:'.$ouro.'"></b>'
             . '<i style="background:'.$fio.'"></i>'
             . '<b class="lc" style="background:'.$ouro.'"></b>'
             . '<i style="background:'.$fio.'"></i>'
             . '<b class="lz" style="border-color:'.$ouro.'"></b>'
             . '</div>';
    }
    return '<div class="div-orn">'
         . '<i style="background:'.$fio.'"></i>'
         . '<b class="lp" style="background:'.$ouro.'"></b>'
         . '<b class="lc" style="background:'.$ouro.'"></b>'
         . '<b class="lp" style="background:'.$ouro.'"></b>'
         . '<i style="background:'.$fio.'"></i>'
         . '</div>';
}

/** Os 4 losangos dourados dos cantos do painel impresso. */
function chaveiroCantos(array $ac): string
{
    $o = '';
    foreach (['top:0;left:0', 'top:0;right:0', 'bottom:0;left:0', 'bottom:0;right:0'] as $pos) {
        $o .= '<b class="canto-lz" style="'.$pos.';background:'.$ac['ouro'].'"></b>';
    }
    return $o;
}

/**
 * Frente da peça: cartela, monograma, divisor e data.
 * $plana = face solta (catálogos e provas), fora da peça 3D.
 */
function renderChaveiroFrente(array $ac, array $defs, string $ia, string $ib, string $dataPt, int $mono = 140, bool $plana = false): string
{
    ob_start(); ?>
<div class="face<?= $plana ? ' face-plana' : '' ?>" style="background:<?= $ac['fundo'] ?>">
  <div class="moldura" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="moldura dupla" style="border-color:<?= $ac['fio'] ?>"></div>
  <div class="canto canto-se" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="canto canto-sd" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="canto canto-ie" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="canto canto-id" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="painel">
    <div class="cartela" style="color:<?= $ac['cartela'] ?>">
      <i style="background:<?= $ac['fio'] ?>"></i><?= escP($defs['chaveiro.cartela']) ?><i style="background:<?= $ac['fio'] ?>"></i>
    </div>
    <div><?= renderMonograma($mono, $ac, $ia, $ib) ?></div>
    <?= chaveiroDivisor($ac, 'duplo') ?>
    <div class="data-peca" style="color:<?= $ac['nomes'] ?>"><?= escP($dataPt) ?></div>
  </div>
  <?= chaveiroCantos($ac) ?>
  <div class="brilho"></div>
</div>
<?php
    return ob_get_clean();
}

/** Verso da peça: divisor, quadra, divisor e coordenadas do local. */
function renderChaveiroVerso(array $ac, array $defs, string $quadra, bool $plana = false): string
{
    ob_start(); ?>
<div class="face <?= $plana ? 'face-plana' : 'face-verso' ?>" style="background:<?= $ac['fundo'] ?>">
  <div class="moldura" style="border-color:<?= $ac['ouro'] ?>"></div>
  <div class="moldura dupla" style="border-color:<?= $ac['fio'] ?>"></div>
  <div class="painel">
    <?= chaveiroDivisor($ac, 'simples') ?>
    <div class="quadra" style="color:<?= $ac['quadra'] ?>"><?= escP($quadra) ?></div>
    <?= chaveiroDivisor($ac, 'duplo') ?>
    <div class="coords">
      <?php foreach ([$defs['chaveiro.coord_lat'], $defs['chaveiro.coord_lon']] as $co):
        $co = trim($co);
        $card = preg_match('/([NSEW])$/u', $co, $mm) ? $mm[1] : '';
        $val  = trim(preg_replace('/\s*[NSEW]$/u', '', $co)); ?>
        <div class="v" style="color:<?= $ac['nomes'] ?>"><?= escP($val) ?></div>
        <div class="c" style="color:<?= $ac['ouro'] ?>"><?= escP($card) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?= chaveiroCantos($ac) ?>
  <div class="brilho"></div>
</div>
<?php
    return ob_get_clean();
}

// ------------------------------------------------------------
// BRINDES POR GÉNERO
// ------------------------------------------------------------

/**
 * Peças que podem ser atribuídas como brinde. Ao acrescentar uma nova
 * peça, basta registá-la aqui (e criar o respetivo renderizador).
 */
function brindesPecas(): array {
    return [
        'porta-chaves' => [
            'nome'      => 'Porta-chaves comemorativo',
            'medida'    => '45 × 60 mm',
            'material'  => 'Acrílico de dois lados, com argola metálica',
            'pagina'    => 'porta-chaves.php',
            'manual'    => 'assets/pecas/manuais/porta-chaves.html',
            'variacoes' => 'quadra',   // fonte das variações: as quadras do verso
        ],
    ];
}

/** Géneros a que se pode atribuir um brinde. */
function brindesGeneros(): array {
    return ['m' => 'Masculino', 'f' => 'Feminino'];
}

/**
 * Variações de uma peça, de forma genérica: [['id'=>0,'rotulo'=>'I','texto'=>'…'], …].
 * Cada peça declara em brindesPecas() de onde vêm as suas variações; para
 * acrescentar uma peça nova basta tratar aqui a sua fonte de variações.
 */
function pecaVariacoes(string $pecaId): array {
    $peca = brindesPecas()[$pecaId] ?? null;
    if (!$peca) return [];
    switch ($peca['variacoes']) {
        case 'quadra': {
            $rom = chaveiroRomanos(); $out = [];
            foreach (chaveiroQuadras() as $i => $q) {
                $out[] = ['id' => $i, 'rotulo' => $rom[$i] ?? (string)($i + 1), 'texto' => $q];
            }
            return $out;
        }
    }
    return [];
}

/**
 * Pré-visualização de uma peça, para o editor e os catálogos.
 * É o terceiro (e último) ponto de extensão de uma peça nova: catálogo em
 * brindesPecas(), fonte das variações em pecaVariacoes() e o desenho aqui.
 * $lado: 'frente' | 'verso'. Devolve '' se a peça não souber desenhar-se.
 */
function pecaPreVisualizacao(string $pecaId, int $varId, string $lado, array $defs): string {
    switch ($pecaId) {
        case 'porta-chaves': {
            $ac = chaveiroAcabamento($defs['chaveiro.acabamento']);
            if ($lado === 'frente') {
                return renderChaveiroFrente(
                    $ac, $defs, inicialU($defs['casal.noiva']), inicialU($defs['casal.noivo']),
                    date('d · m · Y', strtotime($defs['evento.data'])), 140, true
                );
            }
            $quadras = chaveiroQuadras();
            return renderChaveiroVerso($ac, $defs, $quadras[$varId] ?? ($quadras[0] ?? ''), true);
        }
    }
    return '';
}

/** Medidas da peça na pré-visualização (px), para o palco do editor. */
function pecaMedidas(string $pecaId): array {
    switch ($pecaId) {
        case 'porta-chaves': return ['largura' => 250, 'altura' => 340];
    }
    return ['largura' => 250, 'altura' => 340];
}

/**
 * Seleção de variações de um género: [indice => quantidade].
 * Guardada em JSON na definição brindes.{g}.variacoes. Vazio = todas as
 * variações disponíveis, sem quantidade definida (0).
 */
function brindeSelecao(array $defs, string $g, string $pecaId): array {
    $vars = pecaVariacoes($pecaId);
    $validos = array_column($vars, 'id');
    $j = json_decode($defs["brindes.$g.variacoes"] ?? '', true);
    $out = [];
    if (is_array($j) && $j) {
        foreach ($j as $i => $q) {
            $i = (int)$i;
            if (in_array($i, $validos, true)) $out[$i] = max(0, (int)$q);
        }
    }
    if (!$out) foreach ($validos as $i) $out[$i] = 0;   // vazio = todas
    ksort($out);
    return $out;
}

/**
 * Brinde atribuído a cada género, já resolvido: peça, variações disponíveis
 * (com quantidade a produzir) e quantos convidados o recebem.
 * $porGenero: ['m'=>n, 'f'=>n] — contagem vinda da base de dados.
 */
function brindesPorGenero(array $defs, array $porGenero): array {
    $pecas = brindesPecas();
    $out = [];
    foreach (brindesGeneros() as $g => $rotulo) {
        $chave = trim((string)($defs["brindes.$g.peca"] ?? ''));
        $peca  = $pecas[$chave] ?? null;
        $sel   = $peca ? brindeSelecao($defs, $g, $chave) : [];
        $vars  = [];
        if ($peca) {
            foreach (pecaVariacoes($chave) as $v) {
                if (!array_key_exists($v['id'], $sel)) continue;   // variação não disponível
                $v['quantidade'] = $sel[$v['id']];
                $vars[] = $v;
            }
        }
        $out[$g] = [
            'rotulo'     => $rotulo,
            'peca_id'    => $peca ? $chave : '',
            'peca'       => $peca,
            'variacoes'  => $vars,
            'total_pecas'=> array_sum(array_column($vars, 'quantidade')),
            'quantidade' => (int)($porGenero[$g] ?? 0),
        ];
    }
    return $out;
}

/**
 * Monograma I&A dentro do anel guilhoché.
 * Toda a composição é relativa a $size (px), como no design.
 */
function renderMonograma(int $size, array $ac, string $ia, string $ib): string {
    $band    = round($size * 0.055, 1);
    $inner   = round($size * 0.150, 1);
    $inner2  = round($size * 0.205, 1);
    $letra   = round($size * 0.40, 1);
    $nest    = round($size * 0.055, 1);
    $shiftX  = round($size * 0.05, 1);
    $optical = round($size * 0.02, 1);
    $dTopo   = round($size * 0.052, 1);
    $dLado   = round($size * 0.034, 1);
    $dOff    = round($size * 0.018, 1);
    $cor     = $ac['mono']; $fio = $ac['fio']; $ouro = $ac['ouro'];
    $centro  = round($size * 0.07, 1);

    $losango = function ($tam, $pos) use ($ouro) {
        return '<div style="position:absolute;'.$pos.';width:'.$tam.'px;height:'.$tam.'px;background:'.$ouro.';transform:translate(-50%,-50%) rotate(45deg);"></div>';
    };

    ob_start(); ?>
<div class="mono" style="width:<?= $size ?>px;height:<?= $size ?>px">
  <div class="mono-anel" style="border-color:<?= $ouro ?>"></div>
  <div class="mono-guilloche" style="inset:<?= $band ?>px;background:repeating-conic-gradient(from 0deg, <?= $fio ?> 0deg .8deg, transparent .8deg 3.4deg)"></div>
  <div class="mono-fio" style="inset:<?= $inner ?>px;border-color:<?= $fio ?>"></div>
  <div class="mono-fio mono-pont" style="inset:<?= $inner2 ?>px;border-color:<?= $fio ?>"></div>
  <?= $losango($dTopo, 'left:50%;top:'.(-$dOff).'px') ?>
  <?= $losango($dTopo, 'left:50%;top:calc(100% + '.$dOff.'px)') ?>
  <?= $losango($dLado, 'top:50%;left:'.(-$dOff).'px') ?>
  <?= $losango($dLado, 'top:50%;left:calc(100% + '.$dOff.'px)') ?>
  <div class="mono-letras" style="font-size:<?= $letra ?>px;color:<?= $cor ?>;transform:translate(<?= -$shiftX ?>px,<?= -$optical ?>px)">
    <span style="margin-right:<?= -$nest ?>px"><?= escP($ia) ?></span><span><?= escP($ib) ?></span>
  </div>
  <div class="mono-centro" style="font-size:<?= $centro ?>px;color:<?= $ouro ?>">&#10084;</div>
</div>
<?php
    return ob_get_clean();
}
