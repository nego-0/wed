<?php
// ============================================================
// entregas.php — O posto do empregado de sala
//
// Uma coluna, alvos enormes, e um botão por pedido. Este ecrã anda na mão de
// alguém que atravessa o salão com um tabuleiro: o que aqui se faz tem de se
// fazer com um polegar, sem parar de andar (docs/modulo-bar.md §25).
//
// É também o único sítio onde o stock real desce. Enquanto a bebida não sai
// para a mesa, ela está prometida mas ainda está na copa — e é essa distinção
// que impede o bar de vender o que já não tem.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';   // tiraSuporte()
exigirEntregas();
exigirModulo('bar');

$visita = emVisitaDeSuporte();
$soVer  = $visita && !podeCorrigir();
$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#16261E">
<title>Entregas · <?= escP($CAS['casal']) ?></title>
<?php include __DIR__ . '/parcial-tema.php'; ?>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/bar.css') ?>" rel="stylesheet">
<style>
  body.b-noite .topo .wrap{ display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
                            max-width:560px; margin:0 auto; padding:0 .15rem; }
  body.b-noite .topo h1{ font-family:var(--serif); font-size:1.5rem; margin:0; color:var(--ivory); }
  body.b-noite .topo .sub{ font-size:.8rem; color:var(--gold-pale); }
  body.b-noite .topo .nav{ margin-left:auto; display:flex; gap:1rem; }
  body.b-noite .topo .nav a{ color:var(--gold-pale); font-size:.88rem; }

  /* As três filas: a minha, a que espera, e a que voltou. Cabeçalhos em vez
     de abas — num só ecrã, rolar é mais rápido do que escolher. */
  .b-secao{ margin:1.4rem 0 .6rem; font-family:var(--serif); font-size:1.05rem;
            color:var(--gold-soft); display:flex; align-items:baseline; gap:.5rem; }
  .b-secao small{ font-family:var(--sans); font-size:.74rem; color:var(--gold-pale);
                  font-weight:400; }
  .b-ped .b-acoes .btn{ flex:1 1 100%; }
  .b-ped.minha{ border-color:var(--gold-soft); }

</style>
</head>
<body class="b-noite">
<?php tiraSuporte(true); ?>
<header class="topo">
  <div class="wrap">
    <div>
      <h1>Entregas</h1>
      <div class="sub"><?= escP($CAS['casal']) ?></div>
    </div>
    <nav class="nav">
      <?php if (podeCopa()): ?><a href="copa.php">Copa</a><?php endif; ?>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="b-sala estreita">
  <div class="b-barra">
    <div><div class="n" id="k-minhas">–</div><div class="l">comigo</div></div>
    <div><div class="n" id="k-espera">–</div><div class="l">por apanhar</div></div>
    <div><div class="n" id="k-entregues">–</div><div class="l">servidas</div></div>
    <span class="cresce"></span>
    <span class="b-sinal" id="b-sinal">a ligar…</span>
  </div>

  <button class="btn btn-fantasma b-bt-grande" onclick="entPedirPor()">
    Pedir por um convidado sem rede</button>

  <div id="b-listas">
    <div class="b-cartao b-esq" style="height:110px"></div>
    <div class="b-cartao b-esq" style="height:110px;margin-top:.7rem"></div>
  </div>

  <div class="b-tempos" id="b-tempos" hidden></div>
</div>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-entrega.js') ?>"></script>
</body>
</html>
