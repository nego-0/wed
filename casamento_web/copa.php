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
  /* O título de uma coluna: o sinal, a palavra, e a nota do lado. O ícone
     ancora a coluna quando o olho volta a ela pela vigésima vez. */
  .b-tit{ font-family:var(--serif); font-size:1.05rem; color:var(--gold-soft);
          margin:0 0 .6rem; display:flex; align-items:center; gap:.5rem; }
  .b-tit .ico{ width:18px; height:18px; opacity:.8; }
  .b-tit small{ font-size:.75rem; color:var(--gold-pale); font-family:var(--sans);
                margin-left:auto; font-variant-numeric:tabular-nums; }

  /* O cabeçalho é próprio, como o da porta: quem trabalha na copa não anda
     pelo menu do casal, e uma barra com «Convite impresso» aqui era ruído. */
  body.b-noite .topo .wrap{ display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
                            max-width:1180px; margin:0 auto; padding:0 .15rem; }
  body.b-noite .topo h1{ font-family:var(--serif); font-size:1.5rem; margin:0; color:var(--ivory); }
  body.b-noite .topo .sub{ font-size:.8rem; color:var(--gold-pale); }
  body.b-noite .topo .nav{ margin-left:auto; display:flex; gap:1rem; }
  body.b-noite .topo .nav a{ color:var(--gold-pale); font-size:.88rem; }

  /* Os três atalhos da coluna do stock. Empilhados e alinhados à esquerda,
     com o ícone sempre na mesma coluna: lidos de cima a baixo são uma lista
     de coisas que se pode fazer, e não três botões espalhados. */
  .b-atalhos{ margin-top:1rem; display:grid; gap:.4rem; }
  .b-atalhos .btn{ justify-content:flex-start; gap:.6rem; width:100%; }

  /* A nota de rodapé de uma coluna. Explica uma regra do módulo e lê-se uma
     vez na vida — por isso é pequena e discreta, e não uma frase em corpo de
     texto a competir com os números que estão por cima dela. */
  .b-nota{ margin:.7rem 0 0; font-size:.78rem; line-height:1.55;
           color:var(--gold-pale); opacity:.85; }
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
  <?php // O mesmo interruptor da montagem, com o mesmo farol: a pergunta
        // «está aberto?» responde-se com um sinal a piscar e uma palavra,
        // e não só com o rótulo do botão. As contagens da FILA não estão
        // aqui — vivem nas pastilhas, que é onde se carrega para lá ir. ?>
  <div class="b-chave" id="b-chave">
    <span class="farol" id="b-farol"></span>
    <div>
      <div class="est" id="b-est">A ligar…</div>
      <p class="dica" id="b-dica">A ler a fila da noite.</p>
    </div>
    <div class="numeros" id="b-numeros"></div>
    <span class="b-sinal" id="b-sinal">a ligar…</span>
    <button class="btn btn-fantasma" id="b-chave-bt" onclick="copaChave()">…</button>
  </div>

  <div class="b-duas">
    <section>
      <?php // As quatro vistas da fila e a procura dentro dela. Vêm de JS
            // porque levam ícones e contagens — o HTML fica com a estrutura. ?>
      <div class="b-fer" id="b-fer-fila" role="tablist"></div>
      <div class="b-fila" id="b-fila">
        <div class="b-cartao b-esq" style="height:96px"></div>
        <div class="b-cartao b-esq" style="height:96px"></div>
      </div>
    </section>

    <aside>
      <h2 class="b-tit" id="b-tit-stock">Stock <small id="b-stock-nota"></small></h2>
      <div class="b-fer" id="b-fer-stock"></div>
      <div class="b-cartao"><div class="b-stock" id="b-stock">
        <div class="b-esq" style="height:44px"></div>
      </div></div>
      <p class="b-nota">
        O stock só desce quando a bebida é entregue. O que está prometido
        aparece à parte — é bebida que ainda está na copa mas já tem dono.
      </p>
      <div id="b-bandeiras" class="b-flags" hidden></div>

      <div class="b-atalhos" id="b-atalhos"></div>
    </aside>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<?php // Os ícones e as peças comuns vêm antes de tudo: o resto do módulo
      // desenha-se com eles e não sabe fazê-lo sem. ?>
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/bar-pecas.js') ?>"></script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-copa.js') ?>"></script>
</body>
</html>
