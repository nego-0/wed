<?php
// ============================================================
// manual.php — Manual de impressão, gerado da configuração atual
//
// Ao contrário de um manual em ficheiro, este acompanha as edições:
// paleta, folhagem, camadas visíveis, textos, acabamento, variações
// escolhidas e quantidades saem daqui tal como estão definidos.
//   manual.php?peca=cartao
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

$pecaSel = 'cartao';   // só há o manual do cartão de convite

// ---- Conversões de medida ----------------------------------
// O cartão é desenhado a 7,2 px = 1 mm.
$pxmmCartao   = 720 / 100;
$mm2pt = fn(float $mm) => $mm * 2.83464567;
$med = function (float $px, float $pxmm) use ($mm2pt) {
    $mm = $px / $pxmm;
    return ['px' => $px, 'mm' => number_format($mm, 1, ',', ''), 'pt' => number_format($mm2pt($mm), 1, ',', '')];
};

$geradoEm = date('d/m/Y \à\s H:i');

// De que versão do cartão saiu este manual. O manual retrata sempre o que a
// peça mostra neste momento — dizer aqui qual é evita a dúvida de estar a
// imprimir por um manual tirado de outra versão.
$verManual = versaoEstado($conn, 'impresso');   // mesmo modelo de estado do resto do sistema

// ============================================================
// Dados do manual do CARTÃO
// ============================================================
if ($pecaSel === 'cartao') {
    $palKey   = $defs['cartao.paleta'];
    $pal      = cartaoPaletaEfetiva($defs);
    $estilo   = cartaoEstiloVars($defs);
    $folhKey  = $defs['cartao.folhagem'];
    $folh     = cartaoFolhagem($folhKey);
    $camadas  = cartaoCamadasVisiveis($defs);
    $rotulos  = cartaoCamadas();
    $ev       = cartaoDadosEvento($defs);

    // Prova visual com um convite real, se existir
    $r = $conn->query("SELECT c.*, m.nome AS mesa_nome FROM {$P}convites c
                       LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                       WHERE c.tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."
                       ORDER BY c.nome_exibicao LIMIT 1");
    $ex = $r ? $r->fetch_assoc() : null;
    $conv = $ex
        ? ['nome' => nomeParaCartao($ex), 'mesas' => mesasDoConvite($conn, $ex)]
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
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de impressão · Cartão 10 × 15 · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/pecas.css') ?>" rel="stylesheet">
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
  /* Numa amostra de cor, o fundo é o conteúdo. Sem isto o navegador
     descarta-o ao imprimir e a gráfica recebe cinco quadrados brancos com
     códigos hexadecimais por baixo — que é o oposto de uma amostra. */
  .cor .amostra{ height:46px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
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
<?php cabecalho('Manual de impressão', 'Gerado a partir da configuração atual', 'grafica', ['no_print'=>true]); ?>

<div class="container">
<div class="man-wrap">

  <div class="barra-man no-print">
    <a class="btn" href="graficas.php">‹ Convite impresso</a>
    <div class="cresce"></div>
    <a class="btn" href="editor-cartao.php">Editar o cartão</a>
    <button class="btn btn-ouro" onclick="window.print()">Imprimir manual</button>
  </div>


  <div class="man-cab">
    <span class="selo-vivo">Reflete a configuração atual</span>
    <h1>Cartão de convite · 100 × 150 mm</h1>
    <div class="sub"><?= escP($CAS['casal']) ?> · <?= escP($ev['data_ext']) ?></div>
    <div class="meta">Gerado em <b><?= escP($geradoEm) ?></b> ·
      Paleta <b><?= escP($pal['nome']) ?></b> · Folhagem <b><?= escP($folh['nome']) ?></b> ·
      <b><?= $totCartoes ?></b> <?= $totCartoes === 1 ? 'cartão' : 'cartões' ?> a produzir</div>
    <div class="meta">Versão do cartão:
      <?php if ($verManual['estado'] === 'vigor'): ?>
        <b><?= escP($verManual['nome']) ?></b> — é a que está em vigor, e é esta que se imprime.
      <?php else: ?>
        <b><?= escP($verManual['nome']) ?> · com alterações</b> — o cartão tem alterações que ainda
        não guardou como versão. É este estado que este manual retrata.
      <?php endif; ?>
    </div>
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
      <i>Entregáveis à gráfica → Convites físicos</i>.</div>
  </div>

  <div class="sec">
    <h2><span class="n">6</span> Prova</h2>
    <div class="provas">
      <div class="prova">
        <div class="palco palco-cartao"><div class="escala"><?= renderCartaoConvite($ev, $conv, $pal, $folhKey, true, $camadas, $estilo) ?></div></div>
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


  <p style="font-size:.78rem;color:#9aa09a;border-top:1px solid var(--line);padding-top:.7rem">
    Documento gerado pelo sistema de gestão de convidados em <?= escP($geradoEm) ?>.
    Acompanha as edições feitas nos editores — se a configuração mudar, volte a gerá-lo.
  </p>
</div>
</div>
</body>
</html>
