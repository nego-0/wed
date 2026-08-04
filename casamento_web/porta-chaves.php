<?php
// ============================================================
// porta-chaves.php — Porta-chaves comemorativo (45×60 mm)
// Peça de lembrança em acrílico de dois lados, com monograma I&A.
// Página-produto: inclina com o cursor, vira ao clique, e permite
// escolher o acabamento e a quadra do verso.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

// Acabamento e quadra (pré-visualizáveis por ?ac=&q= sem gravar)
$acSel = $_GET['ac'] ?? $defs['chaveiro.acabamento'];
$qSel  = isset($_GET['q']) ? (int)$_GET['q'] : (int)$defs['chaveiro.quadra'];
if (!isset(chaveiroAcabamentos()[$acSel])) $acSel = $defs['chaveiro.acabamento'];
$quadras = chaveiroQuadras();
if ($qSel < 0 || $qSel >= count($quadras)) $qSel = (int)$defs['chaveiro.quadra'];
$ac = chaveiroAcabamento($acSel);

$dataIso = $defs['evento.data'];
$dataPt  = date('d · m · Y', strtotime($dataIso));
$ia = inicialU($defs['casal.noiva']);
$ib = inicialU($defs['casal.noivo']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porta-chaves · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<style>
  .palco{ background:radial-gradient(120% 70% at 50% -6%, #2a2a1d, #14150e 34%, #0a0b07 72%);
          border-radius:18px; padding:52px 24px 60px; margin-bottom:1.4rem; position:relative; overflow:hidden; }
  .palco::after{ content:''; position:absolute; inset:0; pointer-events:none;
                 box-shadow:inset 0 0 140px rgba(0,0,0,.55); border-radius:18px; }
  .cab{ text-align:center; margin-bottom:34px; animation:riseIn 1s cubic-bezier(.2,.7,.2,1) both; }
  .cab .kicker{ font-family:'Cormorant Garamond',serif; font-size:10px; letter-spacing:.5em;
                text-transform:uppercase; color:#A98F5F; }
  .cab .coracoes{ display:block; margin:14px auto 2px; width:132px; height:auto; }
  .cab .titulo{ font-family:'Pinyon Script',cursive; font-size:58px; line-height:1.1; margin:10px 0 6px;
                background:linear-gradient(155deg,#fbf1d4 0%,#e0c184 30%,#b08f52 52%,#f7ead0 74%,#d5b478 100%);
                -webkit-background-clip:text; background-clip:text; color:transparent; }
  .cab .data{ display:flex; align-items:center; justify-content:center; gap:14px; color:#BCA271;
              font-family:'Cormorant Garamond',serif; font-style:italic; font-size:15px; }
  .cab .data i{ display:block; width:44px; height:1px; background:rgba(201,166,94,.45); }
  @keyframes riseIn{ from{ opacity:0; transform:translateY(16px) } to{ opacity:1; transform:none } }

  /* ---- A peça em palco ---- */
  .peca-zona{ display:flex; justify-content:center; margin:44px 0 4px; perspective:1400px; }

  /* ---- Seletores ---- */
  .secao-rot{ text-align:center; font-family:'Jost',sans-serif; font-size:10px; letter-spacing:.42em;
              text-transform:uppercase; color:#A98F5F; margin:34px 0 14px; }
  .acabamentos{ display:flex; justify-content:center; gap:26px; }
  .acab{ background:none; border:0; cursor:pointer; text-align:center; }
  .acab .bolo{ width:52px; height:52px; border-radius:50%; padding:3px; margin:0 auto; }
  .acab .bolo span{ display:block; width:100%; height:100%; border-radius:50%; }
  .acab .rot{ font-family:'Jost',sans-serif; font-size:10px; letter-spacing:.14em; margin-top:9px; }
  .quadras{ display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:12px; }
  .quadra-cx{ border:1px solid; border-radius:12px; padding:16px 18px; cursor:pointer; text-align:left;
              display:flex; gap:14px; align-items:flex-start; transition:border-color .2s, background .2s; }
  .quadra-cx .num{ font-family:'Cormorant Garamond',serif; font-size:13px; letter-spacing:.1em; min-width:26px; }
  .quadra-cx .txt{ font-family:'Cormorant Garamond',serif; font-style:italic; font-size:15px;
                   line-height:1.5; white-space:pre-line; }
  .rodape-peca{ text-align:center; font-family:'Jost',sans-serif; font-size:11px; letter-spacing:.18em;
                color:rgba(233,222,196,.5); margin-top:34px; }
  .dica{ text-align:center; font-family:'Jost',sans-serif; font-size:11px; letter-spacing:.14em;
         color:rgba(233,222,196,.42); margin-top:14px; }
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 160px; }
</style>
</head>
<body>
<?php cabecalho('Porta-chaves', 'Lembrança em acrílico · 45 × 60 mm', 'grafica'); ?>

<div class="container">
  <div class="barra">
    <div class="cresce"></div>
    <a class="btn" href="editor-brindes.php">Editar brindes</a>
    <button class="btn" onclick="guardarEscolha()">Guardar escolha</button>
  </div>

  <div class="palco">
    <div class="cab">
      <div class="kicker">Lembrança de casamento</div>
      <img class="coracoes" src="assets/pecas/coracoes-entrelacados.png" alt="" width="132" height="132">
      <div class="titulo"><?= escP($CAS['casal']) ?></div>
      <div class="data"><i></i><span><?= escP(dataExtensa($dataIso)) ?></span><i></i></div>
    </div>

    <div class="peca-zona">
      <div class="peca peca-viva" id="peca" onclick="virar()">
        <div class="argola"><div class="anel"></div><div class="elo"></div></div>

        <!-- FRENTE -->
        <?= renderChaveiroFrente($ac, $defs, $ia, $ib, $dataPt) ?>

        <!-- VERSO -->
        <?= renderChaveiroVerso($ac, $defs, $quadras[$qSel]) ?>
      </div>
    </div>
    <div class="dica">Passe o cursor para inclinar · clique para virar</div>

    <div class="secao-rot">Acabamento</div>
    <div class="acabamentos">
      <?php foreach (chaveiroAcabamentos() as $k => $a): $on = $k === $acSel; ?>
        <button class="acab" onclick="escolher('ac','<?= $k ?>')">
          <div class="bolo" style="background:<?= $on ? 'linear-gradient(150deg,#f4e1ac,#c69a45)' : 'rgba(201,166,94,.18)' ?>">
            <span style="background:<?= $a['amostra'] ?>"></span>
          </div>
          <div class="rot" style="color:<?= $on ? '#F1E6CE' : 'rgba(233,222,196,.42)' ?>"><?= escP($a['nome']) ?></div>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="secao-rot">A frase do verso</div>
    <div class="quadras">
      <?php foreach ($quadras as $i => $q): $on = $i === $qSel; ?>
        <div class="quadra-cx" onclick="escolher('q','<?= $i ?>')"
             style="border-color:<?= $on ? 'rgba(201,166,94,.75)' : 'rgba(201,166,94,.15)' ?>;
                    background:<?= $on ? 'rgba(201,166,94,.055)' : 'rgba(255,255,255,.012)' ?>">
          <div class="num" style="color:<?= $on ? '#E0C184' : 'rgba(201,166,94,.4)' ?>"><?= chaveiroRomanos()[$i] ?></div>
          <div class="txt" style="color:<?= $on ? '#F1E6CE' : 'rgba(233,222,196,.62)' ?>"><?= escP($q) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="rodape-peca">Acrílico de dois lados · 45 × 60 mm · argola metálica</div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const $=id=>document.getElementById(id);
function toast(m){const t=$('toast');t.textContent=m;t.className='toast mostrar';setTimeout(()=>t.className='toast',2200);}

const peca = $('peca');
let virada = false;

function virar(){
  virada = !virada;
  peca.classList.add('virada');
  aplicar();
}
// Inclinação com paralaxe + reflexo especular a acompanhar o cursor
let rx = 0, ry = 0;
function aplicar(){
  peca.style.transform = `rotateX(${rx}deg) rotateY(${ry + (virada?180:0)}deg)`;
}
peca.addEventListener('mousemove', e => {
  const r = peca.getBoundingClientRect();
  const px = (e.clientX - r.left) / r.width  - .5;
  const py = (e.clientY - r.top ) / r.height - .5;
  rx = -py * 14;              // até ±7°
  ry =  px * 20;              // até ±10°
  peca.classList.remove('virada');
  aplicar();
  peca.querySelectorAll('.brilho').forEach(b => b.style.setProperty('--sx', (px * 68) + '%'));
});
peca.addEventListener('mouseleave', () => {
  rx = 0; ry = 0;
  peca.classList.add('virada');
  aplicar();
  peca.querySelectorAll('.brilho').forEach(b => b.style.setProperty('--sx','0%'));
});

// Escolher acabamento/quadra (recarrega; não grava)
function escolher(campo, valor){
  const u = new URL(location.href);
  u.searchParams.set(campo, valor);
  location.href = u.toString();
}
// Gravar a escolha como predefinição da peça
async function guardarEscolha(){
  const u = new URL(location.href);
  const defs = {
    'chaveiro.acabamento': u.searchParams.get('ac') || <?= json_encode($acSel) ?>,
    'chaveiro.quadra':     String(u.searchParams.get('q') ?? <?= (int)$qSel ?>)
  };
  const r = await fetch('api.php?action=defs_save', {method:'POST', headers:{'X-CSRF-Token':CSRF}, body: JSON.stringify({defs})});
  const d = await r.json();
  toast(d.success ? 'Escolha guardada.' : (d.message||'Não foi possível guardar.'));
}
</script>
</body>
</html>
