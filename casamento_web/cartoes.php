<?php
// ============================================================
// cartoes.php — Cartão de convite 10×15 cm (um por convidado)
// Design de impressão UV a dourado sobre acrílico transparente.
// O nome do convidado e as mesas vêm da base de dados.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

// Estilo escolhido (pode ser pré-visualizado por ?paleta=&folhagem= sem gravar)
$paletaSel   = $_GET['paleta']   ?? $defs['cartao.paleta'];
$folhagemSel = $_GET['folhagem'] ?? $defs['cartao.folhagem'];
if (!isset(cartaoPaletas()[$paletaSel]))     $paletaSel   = $defs['cartao.paleta'];
if (!isset(cartaoFolhagens()[$folhagemSel])) $folhagemSel = $defs['cartao.folhagem'];
$pal = cartaoPaleta($paletaSel);
$ev  = cartaoDadosEvento($defs);

// Convites físicos (os que levam cartão impresso)
$res = $conn->query("SELECT c.*, m.nome AS mesa_nome
                     FROM {$P}convites c
                     LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                     WHERE c.tipo IN ('fisico','ambos')
                     ORDER BY c.nome_exibicao");
$convites = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Um único convite (?id=) — útil para imprimir só um
$soId = (int)($_GET['id'] ?? 0);
if ($soId) $convites = array_values(array_filter($convites, fn($c) => (int)$c['id'] === $soId));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cartões 10×15 · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<style>
  /* ---- Escala: o cartão é desenhado a 720×1080 px (= 100×150 mm) ---- */
  .folha{ width:calc(720px * var(--esc)); height:calc(1080px * var(--esc)); }
  .escala{ width:720px; height:1080px; transform:scale(var(--esc)); }
  .grelha-cartoes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(calc(720px * var(--esc)),1fr)); gap:1.6rem; justify-items:center; --esc:.42; }
  /* Um só cartão (?id=): mostra-se em grande, já que não compete por espaço */
  .grelha-cartoes.unica{ --esc:.78; }

  /* ---- Barra de estilo ---- */
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 160px; }
  .amostras{ display:flex; gap:.45rem; align-items:center; flex-wrap:wrap; }
  .amostra{ width:30px; height:30px; border-radius:50%; border:2px solid transparent; cursor:pointer; padding:0; }
  .amostra.on{ border-color:var(--ink); box-shadow:0 0 0 2px #fff inset; }
  .rot{ font-size:.78rem; color:#8a8f88; letter-spacing:.08em; text-transform:uppercase; }
  .legenda{ text-align:center; font-size:.78rem; color:#8a8f88; margin-top:.45rem; }
  .legenda a{ color:var(--gold); }

  /* ---- Impressão: 100×150 mm, um cartão por página, sem fundo ---- */
  @media print{
    @page{ size:100mm 150mm; margin:0; }
    body{ background:#fff; }
    .no-print{ display:none !important; }
    .container{ padding:0; max-width:none; }
    /* A regra tem de vencer também a vista de um só cartão (.unica). */
    .grelha-cartoes, .grelha-cartoes.unica{ display:block; --esc:.5248; }   /* 720px -> 100mm */
    /* A quebra vai no item, não na folha: a folha não é o último filho da grelha,
       pelo que o :last-child nunca lá pegava e sobrava uma página em branco. */
    .cartao-item{ margin:0; break-after:page; page-break-after:always; }
    .cartao-item:last-child{ break-after:auto; page-break-after:auto; }
    /* Medidas exatas da página, para não transbordar por sub-pixéis. */
    .folha{ width:100mm; height:150mm; overflow:hidden; background:#fff; border-radius:0; margin:0; }
    .legenda{ display:none; }
  }
</style>
</head>
<body>
<header class="topo no-print">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Cartões 10×15</h1><div class="sub">Convite para impressão a dourado sobre acrílico</div></div>
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
  <div class="barra no-print">
    <span class="rot">Paleta</span>
    <div class="amostras">
      <?php foreach (cartaoPaletas() as $k => $p): ?>
        <button class="amostra <?= $k === $paletaSel ? 'on' : '' ?>" title="<?= escP($p['nome']) ?>"
                style="background:<?= $p['accent'] ?>" onclick="estilo('paleta','<?= $k ?>')"></button>
      <?php endforeach; ?>
    </div>
    <span class="rot">Folhagem</span>
    <select id="folhagem" onchange="estilo('folhagem',this.value)">
      <?php foreach (cartaoFolhagens() as $k => $f): ?>
        <option value="<?= $k ?>" <?= $k === $folhagemSel ? 'selected' : '' ?>><?= escP($f['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="cresce"></div>
    <span class="tag neutra"><?= count($convites) ?> cartões</span>
    <a class="btn" href="editor-cartao.php">Editar o cartão</a>
    <button class="btn" onclick="guardarEstilo()">Guardar estilo</button>
    <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
  </div>

  <?php if (!$convites): ?>
    <div class="vazio no-print"><div class="ico">✉</div><p>Ainda não há convites marcados como físicos.<br>No painel, defina o tipo do convite como “Físico” ou “Ambos”.</p></div>
  <?php else: ?>
  <div class="grelha-cartoes <?= $soId ? 'unica' : '' ?>">
    <?php foreach ($convites as $c):
      // Personalização: nome tal como aparece no convite + mesas efetivas.
      $mesas = mesasDoConvite($conn, $c);
      // Respeita a opção "mostrar o nº de lugares" do convite (igual ao digital e às etiquetas).
      $comLugares = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
      $conv = ['nome' => nomeConviteVisivel($c), 'mesas' => $mesas];
    ?>
    <div class="cartao-item">
      <div class="folha"><div class="escala"><?= renderCartaoConvite($ev, $conv, $pal, $folhagemSel, $comLugares) ?></div></div>
      <div class="legenda no-print"><?= escP($c['codigo']) ?> ·
        <a href="?id=<?= (int)$c['id'] ?>&paleta=<?= escP($paletaSel) ?>&folhagem=<?= escP($folhagemSel) ?>">imprimir só este</a></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($soId): ?>
    <p class="no-print" style="text-align:center;margin-top:1rem"><a href="cartoes.php">← Ver todos os cartões</a></p>
  <?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const $=id=>document.getElementById(id);
function toast(m){const t=$('toast');t.textContent=m;t.className='toast mostrar';setTimeout(()=>t.className='toast',2200);}

// Pré-visualizar paleta/folhagem (só muda o URL; não grava)
function estilo(campo, valor){
  const u = new URL(location.href);
  u.searchParams.set(campo, valor);
  location.href = u.toString();
}
// Gravar o estilo atual como predefinição do cartão
async function guardarEstilo(){
  const u = new URL(location.href);
  const defs = {
    'cartao.paleta':   u.searchParams.get('paleta')   || <?= json_encode($paletaSel) ?>,
    'cartao.folhagem': u.searchParams.get('folhagem') || <?= json_encode($folhagemSel) ?>
  };
  const r = await fetch('api.php?action=defs_save', {method:'POST', headers:{'X-CSRF-Token':CSRF}, body: JSON.stringify({defs})});
  const d = await r.json();
  toast(d.success ? 'Estilo guardado como predefinição.' : (d.message||'Não foi possível guardar.'));
}
</script>
</body>
</html>
