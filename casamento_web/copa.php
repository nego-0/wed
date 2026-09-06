<?php
// ============================================================
// copa.php — O posto do copeiro
//
// Uma fila de pedidos por decidir, o stock ao lado, e dois botões grandes por
// pedido. É o ecrã que fica cinco horas ligado num canto do salão, à
// meia-luz — daí o escuro (docs/modulo-bar.md §25) e os alvos de 56px: quem
// trabalha aqui tem as mãos ocupadas e está de pé.
//
// A montagem do menu faz-se noutro sítio (bar.php, dos noivos), com tempo e
// com fotografias. Aqui é de noite e à pressa: o que se decide é servir ou não
// servir, e o que se corrige é o stock que a realidade desmentiu.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
exigirCopa();
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
<title>Copa · <?= escP($CAS['casal']) ?></title>
<?php // O salão é escuro nos quatro temas (bar.css fixa-lhe a paleta); o tema
      // continua a mandar nas janelas, que são cartões da casa. ?>
<?php include __DIR__ . '/parcial-tema.php'; ?>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/bar.css') ?>" rel="stylesheet">
<style>
  /* Duas colunas: a fila manda, o stock acompanha. Num tablet de pé fica
     uma só, com a fila em cima — é ela que tem o trabalho. */
  .b-duas{ display:grid; grid-template-columns:minmax(0,1.6fr) minmax(0,1fr); gap:1.2rem;
           align-items:start; }
  @media (max-width:900px){ .b-duas{ grid-template-columns:1fr; } }
  .b-tit{ font-family:var(--serif); font-size:1.05rem; color:var(--gold-soft);
          margin:0 0 .6rem; display:flex; align-items:baseline; gap:.5rem; }
  .b-tit small{ font-size:.75rem; color:var(--gold-pale); font-family:var(--sans); }
  .b-abas-n{ display:flex; gap:.4rem; margin:0 0 .9rem; flex-wrap:wrap; }
  .b-abas-n button{ background:rgba(255,255,255,.06); border:1px solid rgba(233,223,201,.2);
                    color:var(--ivory); border-radius:50px; padding:.45rem 1rem; cursor:pointer;
                    font:inherit; font-size:.85rem; min-height:44px; }
  .b-abas-n button.on{ background:var(--gold-soft); border-color:var(--gold-soft); color:var(--forest-deep);
                       font-weight:600; }
  .b-abas-n button:focus-visible{ outline:2px solid var(--gold-soft); outline-offset:3px; }
  .b-abas-n .cnt{ font-variant-numeric:tabular-nums; opacity:.75; }

  /* O cabeçalho é próprio, como o da porta: quem trabalha na copa não anda
     pelo menu do casal, e uma barra com «Convite impresso» aqui era ruído. */
  body.b-noite .topo .wrap{ display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
                            max-width:1180px; margin:0 auto; padding:0 .15rem; }
  body.b-noite .topo h1{ font-family:var(--serif); font-size:1.5rem; margin:0; color:var(--ivory); }
  body.b-noite .topo .sub{ font-size:.8rem; color:var(--gold-pale); }
  body.b-noite .topo .nav{ margin-left:auto; display:flex; gap:1rem; }
  body.b-noite .topo .nav a{ color:var(--gold-pale); font-size:.88rem; }
</style>
</head>
<body class="b-noite">
<?php tiraSuporte(true); ?>
<header class="topo">
  <div class="wrap">
    <div>
      <h1>Copa</h1>
      <div class="sub"><?= escP($CAS['casal']) ?> · a fila, a decisão e o stock</div>
    </div>
    <nav class="nav">
      <?php if (podeEntregar()): ?><a href="entregas.php">Entregas</a><?php endif; ?>
      <?php if (ehAdmin()): ?><a href="bar.php">Montar o menu</a><?php endif; ?>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="b-sala">
  <div class="b-barra">
    <div><div class="n" id="k-analise">–</div><div class="l">por decidir</div></div>
    <div><div class="n" id="k-aprovados">–</div><div class="l">por entregar</div></div>
    <div><div class="n" id="k-caminho">–</div><div class="l">a caminho</div></div>
    <div><div class="n" id="k-bebidas">–</div><div class="l">servidas</div></div>
    <span class="cresce"></span>
    <span class="b-sinal" id="b-sinal">a ligar…</span>
    <button class="btn btn-fantasma" id="b-chave-bt" onclick="copaChave()">…</button>
  </div>

  <div class="b-duas">
    <section>
      <div class="b-abas-n" role="tablist">
        <button class="on" id="fa-analise" onclick="copaFiltro('analise')" role="tab">
          Por decidir <span class="cnt" id="c-analise"></span></button>
        <button id="fa-espera" onclick="copaFiltro('espera')" role="tab">
          Por entregar <span class="cnt" id="c-espera"></span></button>
        <button id="fa-fim" onclick="copaFiltro('fim')" role="tab">Já resolvidos</button>
        <button id="fa-num" onclick="copaFiltro('num')" role="tab">Os números</button>
      </div>
      <div class="b-fila" id="b-fila">
        <div class="b-cartao b-esq" style="height:96px"></div>
        <div class="b-cartao b-esq" style="height:96px"></div>
      </div>
    </section>

    <aside>
      <h2 class="b-tit">Stock <small id="b-stock-nota"></small></h2>
      <div class="b-cartao"><div class="b-stock" id="b-stock">
        <div class="b-esq" style="height:44px"></div>
      </div></div>
      <p class="dica" style="color:var(--gold-pale);margin-top:.7rem">
        O stock só desce quando a bebida é entregue. O que está prometido
        aparece à parte — é bebida que ainda está na copa mas já tem dono.
      </p>
      <div id="b-bandeiras" class="b-flags" hidden></div>

      <div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-fantasma" onclick="copaPedirPor()">Pedir por um convidado</button>
        <button class="btn btn-fantasma" onclick="copaMotivos()">Motivos de recusa</button>
        <button class="btn btn-fantasma" onclick="copaRegras()">Regras da casa</button>
      </div>
    </aside>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-copa.js') ?>"></script>
</body>
</html>
