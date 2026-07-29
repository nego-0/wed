<?php
// ============================================================
// graficas.php — Entregáveis à gráfica
// Reúne num só sítio o que a gráfica precisa de receber:
//   1. Convites físicos — lista simplificada (nome, mesas, QR)
//   2. Brindes — a peça atribuída a cada género e as suas variações
//   3. Manuais — instruções de impressão de cada peça
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

$abas = ['convites' => 'Convites físicos', 'brindes' => 'Brindes', 'manuais' => 'Manuais'];
$aba  = $_GET['aba'] ?? 'convites';
if (!isset($abas[$aba])) $aba = 'convites';

// ---- 1. Convites físicos -----------------------------------
$convites = [];
if ($aba === 'convites') {
    $res = $conn->query("SELECT c.*, m.nome AS mesa_nome
                         FROM {$P}convites c
                         LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                         WHERE c.tipo IN ('fisico','ambos')
                         ORDER BY c.nome_exibicao");
    $convites = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// ---- 2. Brindes por género ---------------------------------
// Conta os convidados marcados como "recebe brinde", por género.
$porGenero = [];
if ($aba === 'brindes'
    && colunaExiste($conn, "{$P}convidados", 'brinde')
    && colunaExiste($conn, "{$P}convidados", 'genero')) {
    $r = $conn->query("SELECT COALESCE(genero,'') AS g, COUNT(*) AS n
                       FROM {$P}convidados WHERE brinde=1 GROUP BY COALESCE(genero,'')");
    if ($r) while ($x = $r->fetch_assoc()) $porGenero[$x['g']] = (int)$x['n'];
}
$brindes = brindesPorGenero($defs, $porGenero);
$semGenero = (int)($porGenero[''] ?? 0);   // recebem brinde mas sem género definido

$acPeca  = chaveiroAcabamento($defs['chaveiro.acabamento']);
$quadras = chaveiroQuadras();
$romanos = chaveiroRomanos();

// ---- 3. Manuais --------------------------------------------
$manuais = [
    ['ficheiro' => 'assets/pecas/manuais/cartao-10x15.html',
     'titulo'   => 'Cartão de convite 10 × 15 cm',
     'sub'      => 'Impressão UV a dourado sobre acrílico transparente',
     'peca'     => 'cartoes.php'],
    ['ficheiro' => 'assets/pecas/manuais/porta-chaves.html',
     'titulo'   => 'Porta-chaves comemorativo',
     'sub'      => 'Acrílico de dois lados, 45 × 60 mm, com argola',
     'peca'     => 'porta-chaves.php'],
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entregáveis à gráfica · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<script src="assets/qrious.min.js"></script>
<style>
  .abas{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.2rem; }
  .abas a{ background:#fff; border:1px solid var(--line); border-radius:50px; padding:.45rem 1.1rem;
           font-size:.88rem; color:var(--text); text-decoration:none; }
  .abas a:hover{ border-color:var(--gold-soft); }
  .abas a.on{ background:var(--forest); border-color:var(--forest); color:#fff; }
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 200px; }

  /* ---- Lista de produção dos convites ---- */
  .prod{ width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; }
  .prod th{ background:var(--cream); font-size:.74rem; text-transform:uppercase; letter-spacing:.06em;
            color:#7a8078; text-align:left; padding:.6rem .8rem; font-weight:600; }
  .prod td{ border-top:1px solid var(--line); padding:.7rem .8rem; vertical-align:middle; font-size:.9rem; }
  .prod tr:hover td{ background:#fcfbf7; }
  .prod .n{ color:var(--gold-soft); font-family:var(--serif); font-weight:700; width:2.2rem; }
  .prod .nm{ font-family:var(--serif); font-size:1.05rem; color:var(--ink); }
  .prod .ms{ color:var(--forest); font-size:.84rem; }
  .prod .cod{ font-family:var(--serif); letter-spacing:2px; }
  .prod canvas{ display:block; background:#fff; }
  .prod .ver{ font-size:.82rem; white-space:nowrap; }

  /* ---- Brindes ---- */
  .brinde-cx{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.25rem; margin-bottom:1.2rem; }
  .brinde-topo{ display:flex; gap:1rem; align-items:baseline; flex-wrap:wrap; margin-bottom:.3rem; }
  .brinde-topo h3{ margin:0; }
  .brinde-meta{ font-size:.86rem; color:#7a8078; }
  .brinde-meta b{ color:var(--ink); }
  .variacoes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:1rem; margin-top:1rem; }
  .var-item{ text-align:center; }
  .var-palco{ width:calc(250px * .62); height:calc(340px * .62); margin:0 auto;
              border-radius:12px; overflow:hidden; }
  .var-palco .escala{ width:250px; height:340px; transform:scale(.62); }
  .var-rot{ font-size:.78rem; color:#7a8078; margin-top:.5rem; }
  .var-rot b{ font-family:var(--serif); color:var(--gold); }
  .por-definir{ color:#8a8f88; font-style:italic; }

  /* ---- Manuais ---- */
  .manuais{ display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem; }
  .man{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem; }
  .man .ico{ font-size:1.6rem; color:var(--gold); }
  .man h3{ margin:.4rem 0 .2rem; }
  .man p{ font-size:.86rem; color:#7a8078; margin:0 0 .9rem; }
  .man .acoes{ display:flex; gap:.5rem; flex-wrap:wrap; }

  @media print{
    @page{ margin:12mm; }
    body{ background:#fff; }
    .no-print{ display:none !important; }
    .topo{ background:#fff !important; color:var(--ink) !important; border-bottom:2px solid var(--gold); padding:0 0 .6rem !important; }
    .topo::after{ display:none; } .topo .monograma{ color:var(--gold); border-color:var(--gold); }
    .topo h1{ color:var(--ink); } .topo .sub{ color:var(--gold); }
    .prod tr{ break-inside:avoid; }
    .brinde-cx, .var-item{ break-inside:avoid; }
  }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Entregáveis à gráfica</h1><div class="sub">O que a gráfica recebe: convites, brindes e manuais</div></div>
    <nav class="nav no-print">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="impressos.php">Convites físicos</a>
      <a href="cartoes.php">Cartões 10×15</a>
      <a href="porta-chaves.php">Porta-chaves</a>
      <a href="graficas.php" class="ativo">Gráfica</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="abas no-print">
    <?php foreach ($abas as $k => $r): ?>
      <a href="?aba=<?= $k ?>" class="<?= $k === $aba ? 'on' : '' ?>"><?= escP($r) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($aba === 'convites'): ?>
    <div class="barra no-print">
      <div class="cresce"><input type="search" id="busca" placeholder="Procurar convite, mesa ou código…" oninput="filtrar()"></div>
      <span class="tag neutra"><?= count($convites) ?> convites físicos</span>
      <a class="btn" href="cartoes.php">Ver todos os modelos</a>
      <button class="btn btn-ouro" onclick="window.print()">Imprimir lista</button>
    </div>

    <?php if (!$convites): ?>
      <div class="vazio"><div class="ico">✉</div><p>Ainda não há convites marcados como físicos.<br>No painel, defina o tipo do convite como “Físico” ou “Ambos”.</p></div>
    <?php else: ?>
    <table class="prod" id="prod">
      <thead><tr>
        <th></th><th>Convite</th><th>Mesas</th><th>Código</th><th>QR</th><th class="no-print"></th>
      </tr></thead>
      <tbody>
      <?php $n = 1; foreach ($convites as $c):
        $nome  = nomeConviteVisivel($c);
        $distr = mesasDoConvite($conn, $c);
        $comLug = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
        $txtMesas = textoMesas($distr, $comLug);
        $link  = base_url() . '/convite.php?c=' . $c['codigo'];
        $blob  = strtolower($nome . ' ' . $txtMesas . ' ' . $c['codigo']);
      ?>
        <tr data-busca="<?= escP($blob) ?>">
          <td class="n"><?= $n ?></td>
          <td class="nm"><?= escP($nome) ?></td>
          <td class="ms"><?= $txtMesas !== '' ? escP($txtMesas) : '<span class="por-definir">sem mesa</span>' ?></td>
          <td class="cod"><?= escP($c['codigo']) ?></td>
          <td><canvas class="qr" data-link="<?= escP($link) ?>"></canvas></td>
          <td class="no-print ver"><a href="cartoes.php?id=<?= (int)$c['id'] ?>">Ver modelo completo</a></td>
        </tr>
      <?php $n++; endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  <?php elseif ($aba === 'brindes'): ?>
    <div class="barra no-print">
      <span class="tag neutra">Brinde atribuído por género</span>
      <div class="cresce"></div>
      <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
    </div>

    <?php if ($semGenero > 0): ?>
      <div class="banner-aviso" style="background:var(--gold-pale);border:1px solid var(--gold-soft);border-radius:12px;padding:.8rem 1rem;margin-bottom:1.2rem;font-size:.88rem">
        <b><?= $semGenero ?></b> convidado(s) marcados para receber brinde ainda <b>sem género definido</b> — não entram em nenhuma das contagens abaixo.
      </div>
    <?php endif; ?>

    <?php foreach ($brindes as $g => $b): ?>
      <div class="brinde-cx">
        <div class="brinde-topo">
          <h3><?= escP($b['rotulo']) ?></h3>
          <?php if ($b['peca']): ?>
            <span class="brinde-meta"><b><?= escP($b['peca']['nome']) ?></b> ·
              <?= escP($b['peca']['medida']) ?> · <?= escP($b['peca']['material']) ?></span>
          <?php else: ?>
            <span class="brinde-meta por-definir">Brinde ainda por definir para este género.</span>
          <?php endif; ?>
        </div>

        <?php if ($b['peca']): ?>
          <?php
            $qtd = $b['quantidade']; $nv = count($b['variacoes']);
            $porVar = $nv ? (int)ceil($qtd / $nv) : 0;
          ?>
          <div class="brinde-meta">
            <b><?= $qtd ?></b> <?= $qtd === 1 ? 'convidado recebe' : 'convidados recebem' ?> este brinde ·
            <b><?= $nv ?></b> <?= $nv === 1 ? 'variação' : 'variações' ?> (frase do verso)
            <?php if ($qtd > 0 && $nv): ?>
              <?php if ($qtd >= $nv): ?>
                · ≈ <b><?= $porVar ?></b> <?= $porVar === 1 ? 'peça' : 'peças' ?> por variação
              <?php else: ?>
                · uma peça por variação, usando <b><?= $qtd ?></b> das <b><?= $nv ?></b>
              <?php endif; ?>
            <?php endif; ?>
            · <a href="<?= escP($b['peca']['pagina']) ?>">ver a peça</a>
            · <a href="<?= escP($b['peca']['manual']) ?>" target="_blank" rel="noopener">manual</a>
          </div>

          <div class="variacoes">
            <?php foreach ($b['variacoes'] as $i): ?>
              <div class="var-item">
                <div class="var-palco" style="background:<?= $acPeca['fundo'] ?>">
                  <div class="escala"><?= renderChaveiroVerso($acPeca, $defs, $quadras[$i], true) ?></div>
                </div>
                <div class="var-rot">Variação <b><?= escP($romanos[$i]) ?></b></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <div class="barra no-print">
      <span class="tag neutra">Manuais de impressão</span>
    </div>
    <div class="manuais">
      <?php foreach ($manuais as $m): $existe = is_readable(__DIR__ . '/' . $m['ficheiro']); ?>
        <div class="man">
          <div class="ico">📄</div>
          <h3><?= escP($m['titulo']) ?></h3>
          <p><?= escP($m['sub']) ?></p>
          <div class="acoes">
            <?php if ($existe): ?>
              <a class="btn btn-ouro" href="<?= escP($m['ficheiro']) ?>" target="_blank" rel="noopener">Abrir manual</a>
              <a class="btn" href="<?= escP($m['ficheiro']) ?>" download>Descarregar</a>
            <?php else: ?>
              <span class="por-definir">Manual em falta em <?= escP($m['ficheiro']) ?></span>
            <?php endif; ?>
            <a class="btn" href="<?= escP($m['peca']) ?>">Ver a peça</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
const $=id=>document.getElementById(id);
// QR de cada convite (mesmo aspeto das etiquetas)
document.querySelectorAll('canvas.qr').forEach(cv=>{
  new QRious({element:cv, value:cv.dataset.link, size:88, level:'M', foreground:'#20342A', background:'#ffffff'});
});
function filtrar(){
  const q=$('busca').value.toLowerCase();
  document.querySelectorAll('#prod tbody tr').forEach(tr=>{
    tr.style.display = tr.dataset.busca.includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
