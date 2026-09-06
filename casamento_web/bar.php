<?php
// ============================================================
// bar.php — A montagem do bar, para os noivos
//
// O menu de bebidas que os convidados vão abrir na mesa: as gavetas, as
// bebidas com fotografia, o stock inicial, e o interruptor que abre a copa. É
// dos noivos — exigirAdmin() barra o porteiro, e o módulo 'bar' barra quem não
// o comprou.
//
// A copa faz o mesmo trabalho no seu próprio ecrã (copa.php), mas de noite e à
// pressa. Esta página é a de antes: com tempo, com fotografias, com calma.
// O desenho está em docs/modulo-bar.md §25.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/parcial-endereco.php';
exigirAdmin();
exigirModulo('bar');

$visita = emVisitaDeSuporte();
$soVer  = $visita && !podeCorrigir();
$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
$ENDERECO = enderecoPublico();
barGarantirTokens($conn);

// As mesas, com o seu código: é o que vai no QR pousado em cima delas.
$mesas = [];
$rm = $conn->query("SELECT id, nome, bar_token FROM {$P}mesas WHERE " . doCasamento() . "
                    ORDER BY (especial='noivos') DESC, nome");
if ($rm) $mesas = $rm->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bar · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/bar.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  .b-abas{ display:flex; gap:.25rem; border-bottom:1px solid var(--line); margin:0 0 1.2rem; }
  .b-aba{ background:none; border:0; border-bottom:2px solid transparent; cursor:pointer;
          padding:.5rem .1rem; margin-right:1.3rem; font:inherit; font-size:.9rem; color:#8a8f88; }
  .b-aba:hover{ color:var(--ink); }
  .b-aba.on{ color:var(--ink); border-bottom-color:var(--gold); font-weight:600; }
  .b-aba:focus-visible{ outline:2px solid var(--gold); outline-offset:3px; border-radius:4px; }

  /* O interruptor do bar: é o que se carrega quando é a hora. */
  .b-chave{ display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
            background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
            padding:1rem 1.2rem; margin-bottom:1.2rem; }
  .b-chave .est{ font-family:var(--serif); font-size:1.25rem; }
  .b-chave .dica{ margin:0; }
  .b-chave .btn{ margin-left:auto; min-height:48px; }

  /* A grelha das bebidas, na montagem */
  .b-grelha{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:.9rem; }
  .b-cart{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
           overflow:hidden; display:flex; flex-direction:column; }
  .b-cart .b-foto{ border-radius:0; }
  .b-cart .corpo{ padding:.7rem .85rem .85rem; display:flex; flex-direction:column; gap:.3rem; flex:1; }
  .b-cart .nm{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .b-cart .ds{ font-size:.8rem; color:#8a8f88; line-height:1.4; }
  .b-cart .nums{ display:flex; gap:.9rem; margin-top:.2rem; font-size:.78rem; color:#8a8f88; }
  .b-cart .nums b{ font-family:var(--serif); font-size:1.15rem; color:var(--ink);
                   display:block; line-height:1.1; font-variant-numeric:tabular-nums; }
  .b-cart .acs{ display:flex; gap:.4rem; flex-wrap:wrap; margin-top:auto; padding-top:.5rem; }
  .b-cart .acs .btn{ font-size:.78rem; padding:.3rem .7rem; min-height:44px; }
  .b-cart.oculta{ opacity:.6; }
  .b-gav{ display:inline-flex; align-items:center; gap:.35rem; font-size:.72rem;
          text-transform:uppercase; letter-spacing:.06em; color:#8a8f88; }
  .b-gav i{ width:9px; height:9px; border-radius:3px; display:inline-block; }

  /* As gavetas */
  .b-cats{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
  .b-cat{ display:inline-flex; align-items:center; gap:.4rem; background:var(--card);
          border:1px solid var(--line); border-radius:50px; padding:.35rem .8rem; font-size:.85rem; }
  .b-cat i{ width:10px; height:10px; border-radius:3px; }
  .b-cat button{ background:none; border:0; cursor:pointer; color:#8a8f88; font-size:1rem;
                 line-height:1; padding:0 .1rem; }

  /* As folhas das mesas */
  .b-folhas{ display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:.9rem; }
  .b-folha{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
            padding:1rem; text-align:center; }
  .b-folha .mesa{ font-family:var(--serif); font-size:1.3rem; color:var(--ink); }
  .b-folha canvas{ margin:.5rem auto .3rem; display:block; }
  .b-folha .lnk{ font-size:.7rem; color:#8a8f88; word-break:break-all; line-height:1.3; }
  .b-folha .rod{ font-size:.68rem; color:#a3a8a1; margin-top:.5rem; }
  @media print{
    .topo, .b-abas, .b-chave, .no-print, .barra-endereco{ display:none !important; }
    .b-folha{ break-inside:avoid; border:1px dashed #999; }
  }

  /* O formulário de uma bebida */
  .b-form{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.8rem; }
  .b-form label{ display:block; font-size:.78rem; color:#8a8f88; margin-bottom:.25rem; }
  .b-form .larga{ grid-column:1 / -1; }
</style>
</head>
<body>
<?php cabecalho('Bar', 'O menu de bebidas que os convidados abrem na mesa', 'bar'); ?>

<main class="container">
  <div class="b-chave">
    <div>
      <div class="est" id="b-est">A copa está <b>fechada</b></div>
      <p class="dica">Enquanto estiver fechada, ninguém pede — o menu diz-lhes a que horas abre.</p>
    </div>
    <button class="btn btn-ouro" id="b-chave-bt" onclick="barChave()">Abrir o bar</button>
  </div>

  <div class="b-abas" role="tablist">
    <button class="b-aba on" role="tab" id="ab-menu"   onclick="barAba('menu')">O menu</button>
    <button class="b-aba"    role="tab" id="ab-gav"    onclick="barAba('gav')">Gavetas</button>
    <button class="b-aba"    role="tab" id="ab-mesas"  onclick="barAba('mesas')">Mesas e QR</button>
  </div>

  <section id="pn-menu">
    <div class="acoes" style="justify-content:flex-start;margin-bottom:1rem">
      <button class="btn btn-ouro" onclick="barNova()">+ Bebida</button>
      <span class="dica" style="margin:0;align-self:center">Uma fotografia a sério vende
        melhor do que um nome — e o menu é a montra da festa.</span>
    </div>
    <div class="b-grelha" id="b-grelha"></div>
  </section>

  <section id="pn-gav" hidden>
    <div class="b-cats" id="b-cats"></div>
    <div class="acoes" style="justify-content:flex-start">
      <button class="btn" onclick="barGaveta()">+ Gaveta</button>
    </div>
    <p class="dica">As gavetas arrumam o menu e dão-lhe cor. Apagar uma não apaga as
      bebidas: elas ficam sem gaveta.</p>
  </section>

  <section id="pn-mesas" hidden>
    <p class="dica">Uma folha por mesa, para recortar e pousar. É por aqui que os
      convidados entram no menu — não há link no convite, porque um convite é de
      uma família e o bar precisa de saber qual das pessoas está a pedir.</p>
    <div class="acoes no-print" style="justify-content:flex-start;margin-bottom:1rem">
      <button class="btn" onclick="window.print()">Imprimir as folhas</button>
    </div>
    <div class="b-folhas" id="b-folhas"></div>
  </section>
</main>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script>
window.BAR_MESAS = <?= json_encode(array_map(fn($m) => [
    'id' => (int)$m['id'], 'nome' => $m['nome'], 'token' => $m['bar_token']], $mesas), JSON_UNESCAPED_UNICODE) ?>;
window.BAR_ENDERECO = <?= json_encode($ENDERECO) ?>;
</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-montagem.js') ?>"></script>
</body>
</html>
