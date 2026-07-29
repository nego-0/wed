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
function renderCartaoConvite(array $ev, array $conv, array $pal, string $folhagem, bool $comLugares = true): string {
    $ac = $pal['accent']; $nc = $pal['nameColor']; $sub = $pal['sub']; $head = $pal['head']; $soft = $pal['soft'];
    $e = fn($s) => escP($s);

    // Blocos de mesa, separados por divisória vertical (o design usa 2 colunas).
    $mesas = '';
    foreach ($conv['mesas'] as $i => $m) {
        if ($i > 0) $mesas .= '<div class="ct-div-v" style="background:'.$ac.'"></div>';
        $mesas .= '<div class="ct-mesa">'
                . '<div class="ct-mesa-n" style="color:'.$head.'">'.$e($m['nome']).'</div>';
        if ($comLugares) {
            $n = (int)$m['n'];
            $mesas .= '<div class="ct-mesa-l" style="color:'.$soft.'">'.$n.' '.($n === 1 ? 'Lugar' : 'Lugares').'</div>';
        }
        $mesas .= '</div>';
    }
    $blocoMesas = $mesas !== '' ? '<div class="ct-mesas">'.$mesas.'</div>' : '';

    $filete = '<div class="ct-filete" style="background:linear-gradient(90deg,transparent,'.$ac.',transparent)"></div>';
    $tracinho = '<div class="ct-tracinho" style="background:'.$ac.'"></div>';

    ob_start(); ?>
<div class="cartao">
  <!-- trepadeiras: canto superior-direito e inferior-esquerdo (rodada 180°) -->
  <div class="ct-ramo ct-ramo-sd"><?= svgTrepadeira($folhagem, $ac) ?></div>
  <div class="ct-ramo ct-ramo-ie"><?= svgTrepadeira($folhagem, $ac) ?></div>

  <!-- moldura dourada contínua + volutas de canto -->
  <div class="ct-moldura" style="border-color:<?= $ac ?>"></div>
  <div class="ct-voluta ct-voluta-se"><?= svgVoluta($ac) ?></div>
  <div class="ct-voluta ct-voluta-id"><?= svgVoluta($ac) ?></div>

  <div class="ct-conteudo">
    <!-- topo: abertura + nomes + frase -->
    <div class="ct-topo">
      <div class="ct-abertura" style="color:<?= $soft ?>"><?= nl2br($e($ev['abertura']), false) ?></div>
      <div class="ct-nomes">
        <div class="ct-floreado ct-floreado-e"><?= svgFloreado($ac) ?></div>
        <div class="ct-floreado ct-floreado-d"><?= svgFloreado($ac) ?></div>
        <div class="ct-nome" style="color:<?= $nc ?>"><?= $e($ev['noiva']) ?></div>
        <div class="ct-coracao" style="color:<?= $ac ?>">&#9825;</div>
        <div class="ct-nome" style="color:<?= $nc ?>"><?= $e($ev['noivo']) ?></div>
      </div>
      <p class="ct-frase" style="color:<?= $sub ?>"><?= $e($ev['frase']) ?></p>
    </div>

    <!-- centro: o convidado (personalizado) -->
    <div class="ct-centro">
      <?= $filete ?>
      <div class="ct-reservado" style="color:<?= $soft ?>"><?= $e($ev['reservado']) ?></div>
      <div class="ct-convidado" style="color:<?= $nc ?>"><?= $e($conv['nome']) ?></div>
      <?= $blocoMesas ?>
      <?= $filete ?>
    </div>

    <!-- base: data + logística + frase final -->
    <div class="ct-base">
      <div class="ct-data" style="color:<?= $head ?>"><?= $e($ev['data_ext']) ?></div>
      <div class="ct-dia" style="color:<?= $soft ?>"><?= $e($ev['dia_semana']) ?></div>
      <?= $tracinho ?>
      <div class="ct-seccao" style="color:<?= $head ?>"><?= $e($ev['civil_titulo']) ?></div>
      <div class="ct-detalhe" style="color:<?= $soft ?>">às <?= $e($ev['civil_hora']) ?></div>
      <div class="ct-seccao ct-seccao-2" style="color:<?= $head ?>"><?= $e($ev['copo_titulo']) ?></div>
      <div class="ct-detalhe ct-detalhe-2" style="color:<?= $soft ?>"><?= $e($ev['local']) ?><br>às <?= $e($ev['copo_hora']) ?></div>
      <div class="ct-tracinho ct-tracinho-2" style="background:<?= $ac ?>"></div>
      <p class="ct-fecho" style="color:<?= $sub ?>"><?= $e($ev['frase_final']) ?></p>
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
