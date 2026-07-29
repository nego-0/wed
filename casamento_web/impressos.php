<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
$CAS = casalInfo(defsAtuais($conn));
$res = $conn->query("SELECT c.*, m.nome AS mesa_nome,
                            GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR ' · ') AS membros
                     FROM {$P}convites c
                     LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                     LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                     WHERE c.tipo IN ('fisico','ambos')
                     GROUP BY c.id ORDER BY c.nome_exibicao");
$convites = $res->fetch_all(MYSQLI_ASSOC);
$impressos = 0; foreach ($convites as $c) if ($c['impresso']) $impressos++;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Convites físicos · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<script src="assets/qrious.min.js"></script>
<style>
  .grelha-cartoes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:1rem; }
  .cartao{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem; text-align:center; position:relative; }
  .cartao.impresso{ border-color:var(--ok); box-shadow:0 6px 18px rgba(47,125,79,.14); }
  .cartao .num{ position:absolute; top:.7rem; left:.9rem; font-family:var(--serif); font-weight:700; color:var(--gold-soft); font-size:1.1rem; }
  .cartao .selo-i{ width:44px;height:44px;margin:0 auto .5rem;border:2px solid var(--gold-soft);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--gold);font-family:var(--serif);font-weight:700; }
  .cartao .nm{ font-family:var(--serif); font-size:1.3rem; font-weight:600; color:var(--ink); line-height:1.15; }
  .cartao .mm{ font-size:.8rem; color:#8a8f88; margin:.35rem 0 .6rem; }
  .cartao canvas{ background:#fff; }
  .cartao .cod{ font-family:var(--serif); letter-spacing:3px; color:var(--ink); margin-top:.3rem; }
  .cartao .mesa{ font-size:.82rem; color:var(--forest); margin-top:.3rem; }
  .cartao .marca{ display:inline-flex; align-items:center; gap:.4rem; margin-top:.7rem; font-size:.82rem; color:#9aa09a; cursor:pointer; }
  .cartao .marca .cx{ width:20px;height:20px;border:2px solid var(--line);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:#fff; }
  .cartao.impresso .marca{ color:var(--ok); } .cartao.impresso .marca .cx{ background:var(--ok); border-color:var(--ok); }
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 200px; }

  @media print{
    @page{ margin:12mm; }
    body{ background:#fff; }
    .no-print{ display:none !important; }
    .topo{ background:#fff !important; color:var(--ink) !important; border-bottom:2px solid var(--gold); padding:0 0 .6rem !important; }
    .topo::after{ display:none; } .topo .monograma{ color:var(--gold); border-color:var(--gold); }
    .topo h1{ color:var(--ink); } .topo .sub{ color:var(--gold); }
    .grelha-cartoes{ grid-template-columns:repeat(2,1fr); gap:10px; }
    .cartao{ break-inside:avoid; box-shadow:none !important; border:1px solid #ccc; }
    .cartao .marca{ display:none; }
  }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Convites físicos</h1><div class="sub">Etiquetas para envelopes · com QR de entrada</div></div>
    <nav class="nav no-print">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="impressos.php" class="ativo">Convites físicos</a>
      <a href="cartoes.php">Cartões 10×15</a>
      <a href="porta-chaves.php">Porta-chaves</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="barra no-print">
    <div class="cresce"><input type="search" id="busca" placeholder="Procurar…" oninput="filtrar()"></div>
    <span class="tag neutra"><?= count($convites) ?> convites · <span id="cont-impressos"><?= $impressos ?></span> impressos</span>
    <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
  </div>

  <?php if (!$convites): ?>
    <div class="vazio"><div class="ico">✉</div><p>Ainda não há convites marcados como físicos.<br>No painel, defina o tipo do convite como “Físico” ou “Ambos”.</p></div>
  <?php else: ?>
  <div class="grelha-cartoes" id="grelha">
    <?php $n=1; foreach ($convites as $c):
      $nomeFinal = nomeConviteVisivel($c);
      $link = base_url().'/convite.php?c='.$c['codigo'];
      $blob = strtolower($nomeFinal.' '.$c['membros'].' '.$c['codigo'].' '.$c['mesa_nome']);
    ?>
    <div class="cartao <?= $c['impresso']?'impresso':'' ?>" data-busca="<?= htmlspecialchars($blob) ?>" data-id="<?= $c['id'] ?>">
      <div class="num"><?= $n ?></div>
      <div class="selo-i"><?= escP($CAS['mono']) ?></div>
      <div class="nm"><?= htmlspecialchars($nomeFinal) ?></div>
      <?php if ($c['membros'] && count(array_filter(explode(' · ',$c['membros'])))>1): ?>
        <div class="mm"><?= htmlspecialchars($c['membros']) ?></div>
      <?php endif; ?>
      <canvas class="qr" data-link="<?= htmlspecialchars($link) ?>"></canvas>
      <div class="cod"><?= htmlspecialchars($c['codigo']) ?></div>
      <?php $distr = mesasDoConvite($conn, $c); if ($distr):
        // Mesma opção do convite digital: mostrar (ou não) o "(N lugares)" ao lado de cada mesa.
        $comNumMesa = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1; ?>
        <div class="mesa"><?= count($distr)>1?'Mesas':'Mesa' ?>: <?= htmlspecialchars(textoMesas($distr, $comNumMesa)) ?></div>
      <?php endif; ?>
      <div class="marca no-print" onclick="marcar(this,<?= $c['id'] ?>)">
        <span class="cx">✓</span><span class="txt"><?= $c['impresso']?'Impresso':'Marcar impresso' ?></span>
      </div>
    </div>
    <?php $n++; endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const $=id=>document.getElementById(id);
function toast(m){const t=$('toast');t.textContent=m;t.className='toast mostrar';setTimeout(()=>t.className='toast',2200);}

// pintar todos os QR
document.querySelectorAll('canvas.qr').forEach(cv=>{
  new QRious({element:cv,value:cv.dataset.link,size:150,level:'M',foreground:'#20342A',background:'#ffffff'});
});

function filtrar(){
  const q=$('busca').value.toLowerCase();
  document.querySelectorAll('.cartao').forEach(c=>{ c.style.display=c.dataset.busca.includes(q)?'':'none'; });
}
async function marcar(el,id){
  const cartao=el.closest('.cartao'); const vai=!cartao.classList.contains('impresso');
  const agora=()=>{ const d=new Date(),p=n=>String(n).padStart(2,'0');
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); };
  const r=await fetch(`api.php?action=convite_flag&id=${id}&campo=impresso&valor=${vai?1:0}&ts=${encodeURIComponent(agora())}`, {headers:{'X-CSRF-Token':CSRF}}); const d=await r.json();
  if(d.success){ cartao.classList.toggle('impresso',vai); el.querySelector('.txt').textContent=vai?'Impresso':'Marcar impresso';
    $('cont-impressos').textContent=document.querySelectorAll('.cartao.impresso').length; toast('Atualizado.'); }
}
</script>
</body>
</html>
