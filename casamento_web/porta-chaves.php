<?php
// ============================================================
// porta-chaves.php — Porta-chaves comemorativo (45×60 mm)
// Peça de lembrança em acrílico de dois lados, com monograma I&A.
// Página-produto: inclina com o cursor, vira ao clique, e permite
// escolher o acabamento e a quadra do verso.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
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

  /* ---- A peça ---- */
  .peca-zona{ display:flex; justify-content:center; margin:44px 0 4px; perspective:1400px; }
  .peca{ width:250px; height:340px; position:relative; transform-style:preserve-3d; cursor:pointer;
         transition:transform .34s cubic-bezier(.2,.7,.2,1); animation:kcSway 6.6s ease-in-out infinite; }
  .peca.virada{ transition:transform 1.05s cubic-bezier(.66,.03,.2,1); }
  @keyframes kcSway{ 0%,100%{ rotate:y 0deg } 50%{ rotate:y 1.9deg } }
  .face{ position:absolute; inset:0; border-radius:20px; backface-visibility:hidden; -webkit-backface-visibility:hidden;
         box-shadow:0 30px 60px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.22), inset 0 -1px 0 rgba(0,0,0,.35);
         display:flex; align-items:center; justify-content:center; transition:opacity .2s .46s; }
  .face-verso{ transform:rotateY(180deg); }
  .painel{ position:absolute; inset:11px; border-radius:9px; display:flex; flex-direction:column;
           align-items:center; justify-content:space-evenly; text-align:center; padding:20px 16px; }
  .moldura{ position:absolute; inset:9px; border-radius:11px; border:1px solid; pointer-events:none; }
  .moldura.dupla{ inset:13px; border-radius:8px; opacity:.5; }
  .brilho{ position:absolute; inset:0; border-radius:20px; pointer-events:none; overflow:hidden; }
  .brilho::before{ content:''; position:absolute; top:-40%; left:var(--sx,0%); width:38%; height:180%;
                   background:linear-gradient(100deg,transparent,rgba(255,255,255,.13),transparent);
                   transform:rotate(14deg); transition:left .2s ease-out; }

  /* argola metálica */
  .argola{ position:absolute; top:-44px; left:50%; transform:translateX(-50%); z-index:4; }
  .argola .anel{ width:46px; height:46px; border-radius:50%; border:5px solid transparent;
                 background:conic-gradient(#f2f2f0,#9a9a95,#e6e6e2,#8d8d88,#f2f2f0) border-box;
                 -webkit-mask:linear-gradient(#000 0 0) padding-box, linear-gradient(#000 0 0);
                 -webkit-mask-composite:xor; mask-composite:exclude; }
  .argola .elo{ width:13px; height:22px; border-radius:4px; margin:-6px auto 0;
                background:linear-gradient(90deg,#8d8d88,#efefec,#9a9a95); }

  /* frente */
  .cartela{ font-family:'Cormorant Garamond',serif; font-size:7.5px; letter-spacing:.44em;
            text-transform:uppercase; display:flex; align-items:center; gap:9px; }
  .cartela i{ display:block; width:22px; height:1px; }
  /* divisores ornamentais */
  .div-orn{ display:flex; align-items:center; justify-content:center; gap:7px; margin:11px 0 9px; }
  .div-orn i{ display:block; width:30px; height:1px; }
  .div-orn b{ display:block; transform:rotate(45deg); }
  .div-orn .lc{ width:6px; height:6px; }                       /* losango cheio */
  .div-orn .lp{ width:3.5px; height:3.5px; }                   /* losango pequeno */
  .div-orn .lz{ width:7px; height:7px; border:1px solid; }     /* losango vazado */
  .data-peca{ font-family:'Cormorant Garamond',serif; font-size:12.5px; letter-spacing:.30em; }
  /* esquadrias e losangos dos cantos do painel */
  .canto{ position:absolute; width:13px; height:13px; border:1px solid; }
  .canto-se{ top:15px; left:15px; border-right:0; border-bottom:0; }
  .canto-sd{ top:15px; right:15px; border-left:0; border-bottom:0; }
  .canto-ie{ bottom:15px; left:15px; border-right:0; border-top:0; }
  .canto-id{ bottom:15px; right:15px; border-left:0; border-top:0; }
  .canto-lz{ position:absolute; width:6px; height:6px; transform:rotate(45deg); margin:23px; }

  /* verso */
  .quadra{ font-family:'Cormorant Garamond',serif; font-style:italic; font-size:16.5px; line-height:1.5;
           white-space:pre-line; margin:10px 0; }
  .coords{ display:grid; grid-template-columns:auto auto; gap:5px 10px; align-items:baseline;
           justify-content:center; margin-top:4px; }
  .coords .v{ font-family:'Cormorant Garamond',serif; font-size:13.5px; text-align:right; }
  .coords .c{ font-size:9.5px; letter-spacing:.26em; text-align:left; }

  /* monograma (usado nas duas faces) */
  .mono{ position:relative; }
  .mono-anel{ position:absolute; inset:0; border-radius:50%; border:1px solid;
              box-shadow:inset 0 0 12px rgba(0,0,0,.16), 0 1px 3px rgba(0,0,0,.10); }
  .mono-guilloche{ position:absolute; border-radius:50%; opacity:.55;
     -webkit-mask:radial-gradient(circle, transparent 66%, #000 71%, #000 95%, transparent 100%);
             mask:radial-gradient(circle, transparent 66%, #000 71%, #000 95%, transparent 100%); }
  .mono-fio{ position:absolute; border-radius:50%; border:1px solid; }
  .mono-pont{ border-style:dotted; opacity:.7; }
  .mono-letras{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                font-family:'Pinyon Script',cursive; line-height:1; }
  .mono-centro{ position:absolute; left:50%; bottom:16%; transform:translateX(-50%); opacity:.6; }

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
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Porta-chaves</h1><div class="sub">Lembrança em acrílico · 45 × 60 mm</div></div>
    <nav class="nav">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="impressos.php">Convites físicos</a>
      <a href="cartoes.php">Cartões 10×15</a>
      <a href="porta-chaves.php" class="ativo">Porta-chaves</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="barra">
    <div class="cresce"></div>
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
      <div class="peca" id="peca" onclick="virar()">
        <div class="argola"><div class="anel"></div><div class="elo"></div></div>

        <!-- FRENTE -->
        <div class="face" style="background:<?= $ac['fundo'] ?>">
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
            <div><?= renderMonograma(140, $ac, $ia, $ib) ?></div>
            <?= chaveiroDivisor($ac, 'duplo') ?>
            <div class="data-peca" style="color:<?= $ac['nomes'] ?>"><?= escP($dataPt) ?></div>
          </div>
          <?= chaveiroCantos($ac) ?>
          <div class="brilho"></div>
        </div>

        <!-- VERSO -->
        <div class="face face-verso" style="background:<?= $ac['fundo'] ?>">
          <div class="moldura" style="border-color:<?= $ac['ouro'] ?>"></div>
          <div class="moldura dupla" style="border-color:<?= $ac['fio'] ?>"></div>
          <div class="painel">
            <?= chaveiroDivisor($ac, 'simples') ?>
            <div class="quadra" id="quadra-verso" style="color:<?= $ac['quadra'] ?>"><?= escP($quadras[$qSel]) ?></div>
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
const CSRF = <?= json_encode(csrfToken()) ?>;
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
