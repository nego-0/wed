<?php
// ============================================================
// entregas.php — O posto do garçom
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
  body.b-servico .topo .wrap{ display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
                            max-width:560px; margin:0 auto; padding:0 .15rem; }
  body.b-servico .topo h1{ font-family:var(--serif); font-size:1.5rem; margin:0; color:var(--ink); }
  body.b-servico .topo .sub{ font-size:.8rem; color:var(--ink-fraco); }
  body.b-servico .topo .nav{ margin-left:auto; display:flex; gap:1rem; }
  body.b-servico .topo .nav a{ color:var(--gold); font-size:.88rem; }

  /* As três filas: a minha, a que espera, e a que voltou. Cabeçalhos em vez
     de abas — num só ecrã, rolar é mais rápido do que escolher. O sinal à
     esquerda dá a cada fila uma cara, para se saber onde se está a meio de
     um rolar rápido com o telemóvel na mão. */
  .b-secao{ margin:1.4rem 0 .6rem; font-family:var(--serif); font-size:1.05rem;
            color:var(--gold-soft); display:flex; align-items:center; gap:.5rem; }
  .b-secao .ico{ width:17px; height:17px; opacity:.85; }
  .b-secao .n{ font-family:var(--sans); font-size:.72rem; font-weight:700;
               background:rgba(255,255,255,.1); border-radius:50px; padding:.1rem .5rem;
               font-variant-numeric:tabular-nums; }
  .b-secao small{ font-family:var(--sans); font-size:.74rem; color:var(--ink-fraco);
                  font-weight:400; margin-left:auto; text-align:right; }
  .b-ped .b-acoes .btn{ flex:1 1 100%; }
  /* O que está nas MINHAS mãos distingue-se do resto: no meio de três filas
     empilhadas, é o único grupo em que a próxima acção é minha. */
  .b-ped.minha{ border-color:var(--gold-soft); background:rgba(233,223,201,.055); }
</style>
</head>
<body class="b-servico">
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
  <?php // «Comigo» e «por apanhar» já estão escritos no cabeçalho de cada
        // fila, a dois dedos dos cartões que contam — repeti-los aqui era
        // dizer o mesmo número duas vezes no mesmo ecrã. Fica o que nenhuma
        // fila diz: quantas já foram servidas, e se a ligação está de pé. ?>
  <div class="b-barra">
    <div><div class="n" id="k-entregues">–</div><div class="l">servidas esta noite</div></div>
    <span class="cresce"></span>
    <span class="b-sinal" id="b-sinal">a ligar…</span>
  </div>

  <?php // A procura, e o atalho de quem pede sem rede. Vêm de JS porque levam
        // ícones — o HTML fica só com o sítio onde eles moram. ?>
  <div class="b-fer" id="b-fer"></div>

  <div id="b-listas">
    <div class="b-cartao b-esq" style="height:110px"></div>
    <div class="b-cartao b-esq" style="height:110px;margin-top:.7rem"></div>
  </div>

  <div class="b-tempos" id="b-tempos" hidden></div>
</div>

<?php // O mesmo botão de tema do resto da casa. A copa e as entregas são
      // escuras à mesma nos quatro temas — é a hora da noite que manda —,
      // mas as janelas que se abrem aqui e o tema que o resto do sistema
      // usa são os mesmos, e quem trabalha nestes ecrãs há-de poder
      // trocá-lo sem ir procurar outra página. ?>
<?php include __DIR__ . '/parcial-seletor-tema.php'; ?>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/bar-pecas.js') ?>"></script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-entrega.js') ?>"></script>
</body>
</html>
