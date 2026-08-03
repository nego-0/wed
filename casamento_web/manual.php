<?php
// ============================================================
// manual.php — Manual de impressão, gerado da configuração atual
//
// Ao contrário de um manual em ficheiro, este acompanha as edições:
// paleta, folhagem, camadas visíveis, textos, acabamento, variações
// escolhidas e quantidades saem daqui tal como estão definidos.
//   manual.php?peca=cartao | porta-chaves
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

$pecaSel = $_GET['peca'] ?? 'cartao';
if (!in_array($pecaSel, ['cartao', 'porta-chaves'], true)) $pecaSel = 'cartao';

// ---- Conversões de medida ----------------------------------
// O cartão é desenhado a 7,2 px = 1 mm; o porta-chaves a 250 px = 45 mm.
$pxmmCartao   = 720 / 100;
$pxmmChaveiro = 250 / 45;
$mm2pt = fn(float $mm) => $mm * 2.83464567;
$med = function (float $px, float $pxmm) use ($mm2pt) {
    $mm = $px / $pxmm;
    return ['px' => $px, 'mm' => number_format($mm, 1, ',', ''), 'pt' => number_format($mm2pt($mm), 1, ',', '')];
};

$geradoEm = date('d/m/Y \à\s H:i');

// ============================================================
// Dados do manual do CARTÃO
// ============================================================
if ($pecaSel === 'cartao') {
    $palKey   = $defs['cartao.paleta'];
    $pal      = cartaoPaleta($palKey);
    $folhKey  = $defs['cartao.folhagem'];
    $folh     = cartaoFolhagem($folhKey);
    $camadas  = cartaoCamadasVisiveis($defs);
    $rotulos  = cartaoCamadas();
    $ev       = cartaoDadosEvento($defs);
    $comNum   = ($defs['cartao.numero_no_nome'] ?? '1') === '1';

    // Prova visual com um convite real, se existir
    $r = $conn->query("SELECT c.*, m.nome AS mesa_nome FROM {$P}convites c
                       LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                       WHERE c.tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."
                       ORDER BY c.nome_exibicao LIMIT 1");
    $ex = $r ? $r->fetch_assoc() : null;
    $conv = $ex
        ? ['nome' => nomeParaCartao($ex, $comNum), 'mesas' => mesasDoConvite($conn, $ex)]
        : ['nome' => 'Família Agostinho', 'mesas' => [['nome'=>'Mesa Luar','n'=>1], ['nome'=>'Mesa Solar','n'=>4]]];

    // Quantos cartões há a produzir
    $totCartoes = (int)($conn->query("SELECT COUNT(*) FROM {$P}convites c WHERE tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."")->fetch_row()[0] ?? 0);

    $tipografia = [
        ['Nomes dos noivos',      "Alex Brush 400",            75],
        ['Nome do convidado',     "Alex Brush 400",            48],
        ['Data',                  "Cormorant Garamond it. 500", 40],
        ['Frase de convite',      "Cormorant Garamond it. 400", 20],
        ['Frase final',           "Cormorant Garamond it. 400", 19],
        ['Secções (maiúsculas)',  "Montserrat 600",            14],
        ['Abertura',              "Montserrat 500",            13],
        ['Detalhes / mesas',      "Montserrat 500–600",        12],
    ];
    $textos = [
        ['Abertura',            $ev['abertura']],
        ['Nomes',               $ev['noiva'] . ' / ' . $ev['noivo']],
        ['Frase de convite',    $ev['frase']],
        ['Rótulo do convidado', $ev['reservado']],
        ['Data',                $ev['data_ext'] . ' · ' . $ev['dia_semana']],
        ['Cerimónia',           $ev['civil_titulo'] . ' · às ' . $ev['civil_hora']],
        ['Receção',             $ev['copo_titulo'] . ' · ' . $ev['local'] . ' · às ' . $ev['copo_hora']],
        ['Frase final',         $ev['frase_final']],
    ];
}

// ============================================================
// Dados do manual do PORTA-CHAVES
// ============================================================
if ($pecaSel === 'porta-chaves') {
    $acKey = $defs['chaveiro.acabamento'];
    $ac    = chaveiroAcabamento($acKey);
    $ia = inicialU($defs['casal.noiva']); $ib = inicialU($defs['casal.noivo']);
    $dataPt = date('d · m · Y', strtotime($defs['evento.data']));

    // Quem recebe o brinde, por género
    $porGenero = [];
    if (colunaExiste($conn, "{$P}convidados", 'brinde') && colunaExiste($conn, "{$P}convidados", 'genero')) {
        $q = $conn->query("SELECT COALESCE(genero,'') AS g, COUNT(*) AS n
                           FROM {$P}convidados WHERE brinde=1 GROUP BY COALESCE(genero,'')");
        if ($q) while ($x = $q->fetch_assoc()) $porGenero[$x['g']] = (int)$x['n'];
    }
    $brindes = brindesPorGenero($defs, $porGenero);
    // Só os géneros a que esta peça está atribuída
    $usos = array_filter($brindes, fn($b) => $b['peca_id'] === 'porta-chaves');

    $tipografiaK = [
        ['Monograma (iniciais)', 'Pinyon Script 400', 140 * 0.40],
        ['Quadra do verso',      'Cormorant Garamond it. 400', 16.5],
        ['Data da frente',       'Cormorant Garamond 400', 12.5],
        ['Cartela',              'Cormorant Garamond 400', 7.5],
        ['Coordenadas (valor)',  'Cormorant Garamond 400', 13.5],
        ['Coordenadas (cardeal)','Jost 400', 9.5],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de impressão · <?= $pecaSel === 'cartao' ? 'Cartão 10 × 15' : 'Porta-chaves' ?> · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<style>
  .man-wrap{ max-width:960px; margin:0 auto; padding:0 1rem 3rem; }
  .man-cab{ border-bottom:3px double var(--gold-soft); padding-bottom:1rem; margin-bottom:1.6rem; }
  .man-cab h1{ margin:.2rem 0; }
  .man-cab .sub{ color:var(--gold); font-family:var(--serif); font-size:1.05rem; }
  .man-cab .meta{ font-size:.82rem; color:#7a8078; margin-top:.5rem; }
  .man-cab .meta b{ color:var(--ink); }
  .selo-vivo{ display:inline-block; background:var(--gold-pale); border:1px solid var(--gold-soft);
              border-radius:50px; padding:.15rem .7rem; font-size:.76rem; color:var(--ink); }

  .sec{ margin-bottom:1.8rem; break-inside:avoid; }
  .sec > h2{ font-size:1.1rem; border-bottom:1px solid var(--line); padding-bottom:.3rem; margin-bottom:.8rem;
             display:flex; align-items:baseline; gap:.6rem; }
  .sec > h2 .n{ font-family:var(--serif); color:var(--gold); font-size:1.3rem; }

  .tb{ width:100%; border-collapse:collapse; font-size:.88rem; }
  .tb th{ text-align:left; background:var(--cream); padding:.45rem .6rem; font-size:.74rem;
          text-transform:uppercase; letter-spacing:.05em; color:#7a8078; }
  .tb td{ border-top:1px solid var(--line); padding:.45rem .6rem; vertical-align:top; }
  .tb .num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .tb tr.off td{ color:#9aa09a; }

  .cores{ display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:.7rem; }
  .cor{ border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .cor .amostra{ height:46px; }
  .cor .txt{ padding:.4rem .55rem; font-size:.78rem; }
  .cor .txt b{ display:block; font-family:var(--serif); font-size:.9rem; }
  .cor .hex{ font-family:ui-monospace,monospace; color:#7a8078; }

  .aviso-p{ background:var(--gold-pale); border:1px solid var(--gold-soft); border-left:4px solid var(--gold);
            border-radius:8px; padding:.7rem .9rem; font-size:.88rem; margin:.7rem 0; }
  .aviso-p b{ color:var(--ink); }

  .provas{ display:flex; gap:1.2rem; flex-wrap:wrap; align-items:flex-start; }
  .prova{ text-align:center; }
  .prova .palco{ border-radius:10px; overflow:hidden; border:1px solid var(--line); }
  .prova .rot{ font-size:.78rem; color:#7a8078; margin-top:.4rem; }
  .prova .rot b{ color:var(--ink); }
  .palco-cartao{ width:calc(720px * .34); height:calc(1080px * .34); background:#fff; }
  .palco-cartao .escala{ width:720px; height:1080px; transform:scale(.34); transform-origin:top left; }
  .palco-kc{ width:calc(250px * .62); height:calc(340px * .62); }
  .palco-kc .escala{ width:250px; height:340px; transform:scale(.62); transform-origin:top left; }

  .lista-check{ margin:0; padding-left:1.2rem; font-size:.9rem; line-height:1.7; }
  .barra-man{ display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin:1rem 0 1.4rem; }
  .barra-man .cresce{ flex:1; }

  @media print{
    @page{ size:A4; margin:14mm; }
    body{ background:#fff; }
    .no-print{ display:none !important; }
    .man-wrap{ max-width:none; padding:0; }
    .sec{ break-inside:avoid; }
    .provas{ break-inside:avoid; }
  }
</style>
</head>
<body>
<header class="topo no-print">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Manual de impressão</h1><div class="sub">Gerado a partir da configuração atual</div></div>
    <nav class="nav">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="graficas.php" class="ativo">Gráfica</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
<div class="man-wrap">

  <div class="barra-man no-print">
    <a class="btn <?= $pecaSel === 'cartao' ? 'btn-ouro' : '' ?>" href="?peca=cartao">Cartão 10 × 15</a>
    <a class="btn <?= $pecaSel === 'porta-chaves' ? 'btn-ouro' : '' ?>" href="?peca=porta-chaves">Porta-chaves</a>
    <div class="cresce"></div>
    <a class="btn" href="<?= $pecaSel === 'cartao' ? 'editor-cartao.php' : 'editor-brindes.php' ?>">Editar</a>
    <button class="btn btn-ouro" onclick="window.print()">Imprimir manual</button>
  </div>

<?php if ($pecaSel === 'cartao'): ?>

  <div class="man-cab">
    <span class="selo-vivo">Reflete a configuração atual</span>
    <h1>Cartão de convite · 100 × 150 mm</h1>
    <div class="sub"><?= escP($CAS['casal']) ?> · <?= escP($ev['data_ext']) ?></div>
    <div class="meta">Gerado em <b><?= escP($geradoEm) ?></b> ·
      Paleta <b><?= escP($pal['nome']) ?></b> · Folhagem <b><?= escP($folh['nome']) ?></b> ·
      <b><?= $totCartoes ?></b> <?= $totCartoes === 1 ? 'cartão' : 'cartões' ?> a produzir</div>
  </div>

  <div class="sec">
    <h2><span class="n">1</span> Produção</h2>
    <table class="tb">
      <tr><th style="width:34%">Formato final</th><td>100 × 150 mm (retrato, proporção 2:3)</td></tr>
      <tr><th>Suporte</th><td>Acrílico transparente</td></tr>
      <tr><th>Impressão</th><td><b>UV, apenas a dourado</b> — sem base branca, para manter a transparência do acrílico</td></tr>
      <tr><th>Sangria</th><td>3 mm por lado</td></tr>
      <tr><th>Margem de segurança</th><td>Textos a ≥ 5 mm das arestas</td></tr>
      <tr><th>Espessura mínima de linha</th><td>0,3 mm</td></tr>
      <tr><th>Tinta sugerida</th><td>Metálica / foil dourado — Pantone metálico 871 C</td></tr>
    </table>
    <div class="aviso-p">Não há fundo impresso: <b>tudo o que é dourado imprime, o resto fica transparente</b>.
      A prova em ecrã aparece sobre fundo escuro só para simular o acrílico.</div>
  </div>

  <div class="sec">
    <h2><span class="n">2</span> Cor</h2>
    <p style="font-size:.9rem;margin:0 0 .7rem">Paleta em uso: <b><?= escP($pal['nome']) ?></b>.
      Numa impressão a <b>um só dourado</b>, use <b><?= strtoupper($pal['accent']) ?></b> (o tom de acento) em toda a peça.
      Os restantes tons servem para dar profundidade em ecrã.</p>
    <div class="cores">
      <?php foreach ([
        'accent' => ['Acento', 'Moldura, filetes, folhagem, divisórias'],
        'nameColor' => ['Nomes', 'Nomes dos noivos e do convidado'],
        'head' => ['Títulos', 'Data, secções, nome das mesas'],
        'soft' => ['Secundário', 'Rótulos e detalhes'],
        'sub' => ['Itálicos', 'Frases em Cormorant'],
      ] as $k => [$rot, $uso]): ?>
        <div class="cor">
          <div class="amostra" style="background:<?= $pal[$k] ?>"></div>
          <div class="txt"><b><?= escP($rot) ?></b><span class="hex"><?= strtoupper($pal[$k]) ?></span>
            <div style="color:#7a8078;margin-top:.15rem"><?= escP($uso) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="sec">
    <h2><span class="n">3</span> Tipografia</h2>
    <table class="tb">
      <thead><tr><th>Elemento</th><th>Tipo de letra</th><th class="num">px</th><th class="num">mm</th><th class="num">pt</th></tr></thead>
      <tbody>
      <?php foreach ($tipografia as [$rot, $fonte, $px]): $m = $med((float)$px, $pxmmCartao); ?>
        <tr><td><?= escP($rot) ?></td><td><?= escP($fonte) ?></td>
            <td class="num"><?= $px ?></td><td class="num"><?= $m['mm'] ?></td><td class="num"><?= $m['pt'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:.82rem;color:#7a8078;margin:.5rem 0 0">Tipos de letra: Alex Brush, Cormorant Garamond e
      Montserrat (Google Fonts, licença SIL OFL — uso comercial permitido).</p>
  </div>

  <div class="sec">
    <h2><span class="n">4</span> Elementos a imprimir</h2>
    <table class="tb">
      <thead><tr><th>Elemento</th><th class="num" style="width:22%">Imprime?</th></tr></thead>
      <tbody>
      <?php foreach ($rotulos as $k => $rot): $on = $camadas[$k] !== 0; ?>
        <tr class="<?= $on ? '' : 'off' ?>">
          <td><?= escP($rot) ?><?= $k === 'ramos' ? ' <span style="color:#7a8078">(variante: '.escP($folh['nome']).')</span>' : '' ?></td>
          <td class="num"><?= $on ? '<b>Sim</b>' : 'Não — omitir' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php $omitidas = array_keys(array_filter($camadas, fn($v) => $v === 0)); if ($omitidas): ?>
      <div class="aviso-p"><b>Atenção:</b> <?= count($omitidas) ?>
        <?= count($omitidas) === 1 ? 'elemento foi desligado' : 'elementos foram desligados' ?> e
        <b>não deve<?= count($omitidas) === 1 ? '' : 'm' ?> ser impresso<?= count($omitidas) === 1 ? '' : 's' ?></b>:
        <?= escP(implode(', ', array_map(fn($k) => $rotulos[$k], $omitidas))) ?>.</div>
    <?php endif; ?>
    <table class="tb" style="margin-top:.8rem">
      <tr><th style="width:34%">Moldura</th><td>Retângulo fechado, 1,4 px (≈ 0,2 mm), a 28 px (≈ 3,9 mm) da aresta</td></tr>
      <tr><th>Trepadeiras</th><td>Cantos superior-direito e inferior-esquerdo (rodada 180°), caixa 132 × 270 px</td></tr>
      <tr><th>Volutas</th><td>Cantos superior-esquerdo e inferior-direito (rodada 180°), caixa 178 × 178 px</td></tr>
    </table>
  </div>

  <div class="sec">
    <h2><span class="n">5</span> Textos</h2>
    <table class="tb">
      <?php foreach ($textos as [$rot, $val]): ?>
        <tr><th style="width:26%"><?= escP($rot) ?></th><td><?= nl2br(escP($val)) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <div class="aviso-p"><b>Variável por convidado:</b> o nome no bloco “<?= escP($ev['reservado']) ?>” e as
      mesas com o número de lugares mudam em cada cartão. A lista completa está em
      <i>Entregáveis à gráfica → Convites físicos</i>.
      <?= $comNum ? 'O nome inclui o “(N)” de lugares.' : 'O nome <b>não</b> inclui o “(N)” de lugares.' ?></div>
  </div>

  <div class="sec">
    <h2><span class="n">6</span> Prova</h2>
    <div class="provas">
      <div class="prova">
        <div class="palco palco-cartao"><div class="escala"><?= renderCartaoConvite($ev, $conv, $pal, $folhKey, true, $camadas) ?></div></div>
        <div class="rot">Exemplo: <b><?= escP($conv['nome']) ?></b> · fundo branco = zona não impressa</div>
      </div>
      <div style="flex:1;min-width:220px">
        <ul class="lista-check">
          <li>Confirmar que <b>não</b> há base branca sob o dourado.</li>
          <li>Confirmar a sangria de 3 mm e o corte a 100 × 150 mm.</li>
          <li>Verificar as linhas finas da folhagem (≥ 0,3 mm).</li>
          <li>Conferir o nome e as mesas de <b>cada</b> cartão pela lista de produção.</li>
          <li>Primeira prova física antes da tiragem completa.</li>
        </ul>
      </div>
    </div>
  </div>

<?php else: ?>

  <?php $totalPecas = array_sum(array_column($usos, 'total_pecas')); ?>
  <div class="man-cab">
    <span class="selo-vivo">Reflete a configuração atual</span>
    <h1>Porta-chaves comemorativo · 45 × 60 mm</h1>
    <div class="sub"><?= escP($CAS['casal']) ?> · <?= escP(dataExtensa($defs['evento.data'])) ?></div>
    <div class="meta">Gerado em <b><?= escP($geradoEm) ?></b> ·
      Acabamento <b><?= escP($ac['nome']) ?></b> ·
      <b><?= $totalPecas ?></b> <?= $totalPecas === 1 ? 'peça' : 'peças' ?> a produzir</div>
  </div>

  <div class="sec">
    <h2><span class="n">1</span> Produção</h2>
    <table class="tb">
      <tr><th style="width:34%">Formato final</th><td>45 × 60 mm</td></tr>
      <tr><th>Suporte</th><td>Acrílico de <b>dois lados</b> (frente e verso impressos)</td></tr>
      <tr><th>Cantos</th><td>Arredondados: exterior 20 px (≈ 3,6 mm); painel impresso 9 px (≈ 1,6 mm)</td></tr>
      <tr><th>Ferragem</th><td>Argola metálica prateada, furo centrado no topo</td></tr>
      <tr><th>Acabamento</th><td><b><?= escP($ac['nome']) ?></b></td></tr>
    </table>
  </div>

  <div class="sec">
    <h2><span class="n">2</span> Cor</h2>
    <div class="cores">
      <?php foreach ([
        'ouro' => ['Dourado', 'Moldura, losangos e divisores'],
        'cartela' => ['Cartela', 'Subtítulo da frente'],
        'mono' => ['Monograma', 'Iniciais'],
        'nomes' => ['Data e coordenadas', 'Valores e data'],
        'quadra' => ['Quadra', 'Frase do verso'],
      ] as $k => [$rot, $uso]): ?>
        <div class="cor">
          <div class="amostra" style="background:<?= $ac[$k] ?>"></div>
          <div class="txt"><b><?= escP($rot) ?></b><span class="hex"><?= strtoupper($ac[$k]) ?></span>
            <div style="color:#7a8078;margin-top:.15rem"><?= escP($uso) ?></div></div>
        </div>
      <?php endforeach; ?>
      <div class="cor"><div class="amostra" style="background:<?= $ac['fundo'] ?>"></div>
        <div class="txt"><b>Fundo</b><span class="hex">gradiente</span>
          <div style="color:#7a8078;margin-top:.15rem">Base da peça</div></div></div>
    </div>
  </div>

  <div class="sec">
    <h2><span class="n">3</span> Tipografia</h2>
    <table class="tb">
      <thead><tr><th>Elemento</th><th>Tipo de letra</th><th class="num">px</th><th class="num">mm</th><th class="num">pt</th></tr></thead>
      <tbody>
      <?php foreach ($tipografiaK as [$rot, $fonte, $px]): $m = $med((float)$px, $pxmmChaveiro); ?>
        <tr><td><?= escP($rot) ?></td><td><?= escP($fonte) ?></td>
            <td class="num"><?= number_format((float)$px, 1, ',', '') ?></td>
            <td class="num"><?= $m['mm'] ?></td><td class="num"><?= $m['pt'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:.82rem;color:#7a8078;margin:.5rem 0 0">Pinyon Script, Cormorant Garamond e Jost
      (Google Fonts, licença SIL OFL).</p>
  </div>

  <div class="sec">
    <h2><span class="n">4</span> Frente (igual em todas as peças)</h2>
    <table class="tb">
      <tr><th style="width:34%">Cartela</th><td><?= escP($defs['chaveiro.cartela']) ?></td></tr>
      <tr><th>Monograma</th><td>Iniciais <b><?= escP($ia . $ib) ?></b> em anel guilhoché, com 4 losangos (topo, base e laterais) e coração ao centro</td></tr>
      <tr><th>Data</th><td><?= escP($dataPt) ?></td></tr>
      <tr><th>Moldura</th><td>Dupla, com esquadrias e 4 losangos de canto</td></tr>
    </table>
  </div>

  <div class="sec">
    <h2><span class="n">5</span> Verso — variações a produzir</h2>
    <?php if (!$usos): ?>
      <div class="aviso-p">Esta peça <b>não está atribuída a nenhum género</b>. Atribua-a em
        <i>Editar brindes</i> para que apareçam aqui as variações e as quantidades.</div>
    <?php else: foreach ($usos as $b): ?>
      <p style="font-size:.9rem;margin:.2rem 0 .6rem"><b><?= escP($b['rotulo']) ?></b> ·
        <?= $b['quantidade'] ?> <?= $b['quantidade'] === 1 ? 'convidado recebe' : 'convidados recebem' ?> ·
        <b><?= count($b['variacoes']) ?></b> <?= count($b['variacoes']) === 1 ? 'variação' : 'variações' ?> ·
        total <b><?= $b['total_pecas'] ?></b> <?= $b['total_pecas'] === 1 ? 'peça' : 'peças' ?></p>
      <table class="tb">
        <thead><tr><th style="width:12%">Variação</th><th>Frase do verso</th><th class="num" style="width:18%">Quantidade</th></tr></thead>
        <tbody>
        <?php foreach ($b['variacoes'] as $v): ?>
          <tr><td><b><?= escP($v['rotulo']) ?></b></td>
              <td style="font-family:var(--serif);font-style:italic"><?= nl2br(escP($v['texto'])) ?></td>
              <td class="num"><?= $v['quantidade'] > 0 ? '<b>'.$v['quantidade'].'</b>' : '<span style="color:#a5473f">por definir</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th></th><th style="text-align:right">Total</th><th class="num"><?= $b['total_pecas'] ?></th></tr></tfoot>
      </table>
      <?php if ($b['total_pecas'] < $b['quantidade']): ?>
        <div class="aviso-p"><b>Atenção:</b> as quantidades somam <?= $b['total_pecas'] ?> peças, menos do que
          os <?= $b['quantidade'] ?> convidados que recebem o brinde.</div>
      <?php endif; ?>
    <?php endforeach; endif; ?>
    <table class="tb" style="margin-top:.8rem">
      <tr><th style="width:34%">Coordenadas (comuns)</th>
          <td><?= escP($defs['chaveiro.coord_lat']) ?> · <?= escP($defs['chaveiro.coord_lon']) ?></td></tr>
    </table>
  </div>

  <div class="sec">
    <h2><span class="n">6</span> Provas</h2>
    <div class="provas">
      <div class="prova">
        <div class="palco palco-kc" style="background:<?= $ac['fundo'] ?>">
          <div class="escala"><?= renderChaveiroFrente($ac, $defs, $ia, $ib, $dataPt, 140, true) ?></div>
        </div>
        <div class="rot"><b>Frente</b></div>
      </div>
      <?php $vs = $usos ? reset($usos)['variacoes'] : []; foreach (array_slice($vs, 0, 3) as $v): ?>
        <div class="prova">
          <div class="palco palco-kc" style="background:<?= $ac['fundo'] ?>">
            <div class="escala"><?= renderChaveiroVerso($ac, $defs, $v['texto'], true) ?></div>
          </div>
          <div class="rot">Verso · variação <b><?= escP($v['rotulo']) ?></b><?= $v['quantidade'] > 0 ? ' · '.$v['quantidade'].'x' : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($vs) > 3): ?>
      <p style="font-size:.82rem;color:#7a8078;margin:.6rem 0 0">Mostram-se as 3 primeiras variações;
        as <?= count($vs) ?> estão listadas na secção 5 e podem ver-se em
        <i>Entregáveis à gráfica → Brindes</i>.</p>
    <?php endif; ?>
  </div>

<?php endif; ?>

  <p style="font-size:.78rem;color:#9aa09a;border-top:1px solid var(--line);padding-top:.7rem">
    Documento gerado pelo sistema de gestão de convidados em <?= escP($geradoEm) ?>.
    Acompanha as edições feitas nos editores — se a configuração mudar, volte a gerá-lo.
  </p>
</div>
</div>
</body>
</html>
