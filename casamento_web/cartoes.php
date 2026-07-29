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
<style>
  /* ---- Escala: o cartão é desenhado a 720×1080 px (= 100×150 mm) ---- */
  .folha{ width:calc(720px * var(--esc)); height:calc(1080px * var(--esc)); }
  .escala{ width:720px; height:1080px; transform:scale(var(--esc)); transform-origin:top left; }
  .grelha-cartoes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(calc(720px * var(--esc)),1fr)); gap:1.6rem; justify-items:center; --esc:.42; }

  /* O acrílico é transparente: mostra-se sobre fundo escuro, como no design */
  .folha{ background:radial-gradient(120% 100% at 50% 15%,#2a2b26 0%,#191a16 55%,#0e0f0c 100%); border-radius:14px; overflow:hidden; }

  .cartao{ width:720px; height:1080px; position:relative; overflow:hidden; background:transparent;
           font-family:'Montserrat',sans-serif; }

  .ct-ramo{ position:absolute; width:132px; height:270px; z-index:2; }
  .ct-ramo-sd{ top:24px; right:24px; }
  .ct-ramo-ie{ bottom:24px; left:24px; transform:rotate(180deg); }
  .ct-moldura{ position:absolute; top:28px; left:28px; right:28px; bottom:28px; border:1.4px solid; z-index:2; pointer-events:none; }
  .ct-voluta{ position:absolute; width:178px; height:178px; z-index:2; pointer-events:none; }
  .ct-voluta-se{ top:24px; left:24px; }
  .ct-voluta-id{ bottom:24px; right:24px; transform:rotate(180deg); }

  .ct-conteudo{ position:relative; z-index:3; height:100%; box-sizing:border-box; padding:58px 58px 78px;
                display:flex; flex-direction:column; align-items:center; justify-content:space-between; text-align:center; }
  .ct-topo{ display:flex; flex-direction:column; align-items:center; }
  .ct-abertura{ font-size:13px; font-weight:500; letter-spacing:.3em; line-height:2.05; text-transform:uppercase; }
  .ct-nomes{ position:relative; margin:22px 0 6px; padding:0 30px; }
  .ct-floreado{ position:absolute; width:150px; height:104px; z-index:0; opacity:.85; }
  .ct-floreado-e{ left:-26px; top:-4px; }
  .ct-floreado-d{ right:-26px; bottom:-4px; transform:rotate(180deg); }
  .ct-nome{ position:relative; z-index:1; font-family:'Alex Brush',cursive; font-size:75px; line-height:.9; }
  .ct-coracao{ position:relative; z-index:1; font-size:20px; margin:2px 0; }
  .ct-frase{ margin:14px 0 0; max-width:400px; font-family:'Cormorant Garamond',serif; font-style:italic;
             font-size:20px; line-height:1.55; }

  .ct-centro{ display:flex; flex-direction:column; align-items:center; gap:12px; }
  .ct-filete{ width:120px; height:1px; opacity:.6; }
  .ct-reservado{ font-size:12px; font-weight:500; letter-spacing:.28em; text-transform:uppercase; }
  .ct-convidado{ font-family:'Alex Brush',cursive; font-size:48px; line-height:.9; }
  .ct-mesas{ display:flex; gap:34px; align-items:stretch; margin-top:2px; }
  .ct-mesa{ display:flex; flex-direction:column; align-items:center; gap:4px; }
  .ct-mesa-n{ font-size:12px; font-weight:600; letter-spacing:.18em; text-transform:uppercase; }
  .ct-mesa-l{ font-size:12px; font-weight:500; letter-spacing:.14em; text-transform:uppercase; }
  .ct-div-v{ width:1px; opacity:.35; }

  .ct-base{ display:flex; flex-direction:column; align-items:center; width:449px; }
  .ct-data{ font-family:'Cormorant Garamond',serif; font-style:italic; font-size:40px; line-height:1; }
  .ct-dia{ margin-top:14px; font-size:12px; font-weight:500; letter-spacing:.32em; text-transform:uppercase; }
  .ct-tracinho{ width:44px; height:1px; opacity:.5; margin:30px 0; }
  .ct-tracinho-2{ margin:26px 0 16px; }
  .ct-seccao{ font-size:14px; font-weight:600; letter-spacing:.2em; text-transform:uppercase; }
  .ct-seccao-2{ margin-top:26px; }
  .ct-detalhe{ margin-top:9px; font-size:13px; font-weight:500; letter-spacing:.14em; text-transform:uppercase; }
  .ct-detalhe-2{ line-height:1.9; }
  .ct-fecho{ margin:0; max-width:450px; font-family:'Cormorant Garamond',serif; font-style:italic;
             font-size:19px; line-height:1.6; }

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
    .grelha-cartoes{ display:block; --esc:.5249; }   /* 720px -> 100mm */
    .folha{ background:#fff; border-radius:0; break-after:page; page-break-after:always; margin:0; }
    .folha:last-child{ break-after:auto; page-break-after:auto; }
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
      <a href="impressos.php">Convites físicos</a>
      <a href="cartoes.php" class="ativo">Cartões 10×15</a>
      <a href="porta-chaves.php">Porta-chaves</a>
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
    <button class="btn" onclick="guardarEstilo()">Guardar estilo</button>
    <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
  </div>

  <?php if (!$convites): ?>
    <div class="vazio no-print"><div class="ico">✉</div><p>Ainda não há convites marcados como físicos.<br>No painel, defina o tipo do convite como “Físico” ou “Ambos”.</p></div>
  <?php else: ?>
  <div class="grelha-cartoes">
    <?php foreach ($convites as $c):
      // Personalização: nome tal como aparece no convite + mesas efetivas.
      $mesas = mesasDoConvite($conn, $c);
      // Respeita a opção "mostrar o nº de lugares" do convite (igual ao digital e às etiquetas).
      $comLugares = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
      $conv = ['nome' => nomeConviteVisivel($c), 'mesas' => $mesas];
    ?>
    <div>
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
