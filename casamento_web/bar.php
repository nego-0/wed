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
  /* A folha das medidas está em bar.css: as abas, o interruptor, a barra de
     ferramentas e o cartão de uma bebida são componentes, e vivem lá. Aqui
     fica só o que é desta página e de mais lado nenhum. */

  /* As folhas das mesas, na pré-visualização */
  .b-folhas{ display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:.9rem; }
  .b-folha{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
            padding:1rem; text-align:center; }
  .b-folha .mesa{ font-family:var(--serif); font-size:1.3rem; color:var(--ink); }
  .b-folha canvas{ margin:.5rem auto .3rem; display:block; }
  .b-folha .lnk{ font-size:.7rem; color:var(--ink-fraco); word-break:break-all; line-height:1.3; }
  .b-folha .rod{ font-size:.68rem; color:var(--ink-fraco); opacity:.8; margin-top:.5rem; }
  @media print{
    .topo, .b-abas, .b-chave, .no-print, .barra-endereco{ display:none !important; }
    .b-folha{ break-inside:avoid; border:1px dashed var(--line); }
  }
</style>
</head>
<body>
<?php cabecalho('Bar', 'O menu de bebidas que os convidados abrem na mesa', 'bar'); ?>

<main class="container">
  <div class="b-chave" id="b-chave">
    <div class="farol" id="b-farol"></div>
    <div>
      <div class="est" id="b-est">A copa está <b>fechada</b></div>
      <p class="dica">Enquanto estiver fechada, ninguém pede — o menu diz-lhes a que horas abre.</p>
    </div>
    <div class="numeros" id="b-numeros"></div>
    <button class="btn btn-ouro" id="b-chave-bt" onclick="barChave()">Abrir o bar</button>
  </div>

  <div class="b-abas" role="tablist">
    <button class="b-aba on" role="tab" id="ab-menu"   onclick="barAba('menu')"></button>
    <button class="b-aba"    role="tab" id="ab-gav"    onclick="barAba('gav')"></button>
    <button class="b-aba"    role="tab" id="ab-mesas"  onclick="barAba('mesas')"></button>
    <button class="b-aba"    role="tab" id="ab-regras" onclick="barAba('regras')"></button>
    <button class="b-aba"    role="tab" id="ab-gente"  onclick="barAba('gente')"></button>
  </div>

  <section id="pn-menu">
    <!-- A barra de ferramentas: procurar, filtrar por gaveta, filtrar por
         estado, e a acção principal à direita. O conteúdo desenha-se em JS
         porque as gavetas são dados. -->
    <div class="b-fer" id="b-fer-menu"></div>
    <div class="b-pastilhas" id="b-filtros" style="margin:-.4rem 0 1.1rem"></div>
    <div class="b-grelha" id="b-grelha"></div>
  </section>

  <section id="pn-gav" hidden>
    <div class="b-fer" id="b-fer-gav"></div>
    <div class="b-cats" id="b-cats"></div>
    <p class="dica">As gavetas arrumam o menu e dão-lhe cor — e é a cor que o
      convidado vê ao lado de cada bebida. Apagar uma não apaga as bebidas:
      elas ficam sem gaveta.</p>
  </section>

  <section id="pn-mesas" hidden>
    <p class="dica">Uma folha por mesa, para recortar e pousar. É por aqui que os
      convidados entram no menu — não há link no convite, porque um convite é de
      uma família e o bar precisa de saber qual das pessoas está a pedir.</p>
    <div class="acoes no-print" style="justify-content:flex-start;margin-bottom:1rem">
      <?php // A impressão vive em bar-qr.php: uma folha para a tesoura e um
            // ecrã para trabalhar querem coisas contrárias, e a folha não quer
            // cabeçalho nem menu nenhum. Aqui é só a pré-visualização. ?>
      <a class="btn btn-ouro" href="bar-qr.php" target="_blank" rel="noopener">
        Abrir as folhas para imprimir</a>
    </div>
    <div class="b-folhas" id="b-folhas"></div>
  </section>

  <?php // ---- As Regras do Bar ----------------------------------------
        // O painel é desenhado por assets/bar-regras.js, e o MESMO painel é
        // montado na copa. Aqui fica só o sítio onde ele entra: duas cópias
        // parecidas do mesmo ecrã foi como as regras começaram a divergir. ?>
  <section id="pn-regras" hidden>
    <div id="pn-regras-cx"></div>
  </section>

  <?php // ---- A equipa do bar ---------------------------------------
        // Os noivos criam e tiram as contas do bar sem passar pela Gestão, e
        // entram nos dois postos com todos os recursos: são a casa, e a casa
        // tem de poder servir uma mesa quando falta alguém. ?>
  <section id="pn-gente" hidden>
    <div class="b-postos" id="b-postos"></div>
    <div class="b-fer" id="b-fer-gente"></div>
    <div class="b-cartao-claro" id="b-equipa"></div>
    <p class="dica">Cada conta serve um posto. A senha aparece uma vez, ao
      criar — copie-a e entregue-a em mão; não há correio configurado, e
      inventar um envio que não acontece era pior do que dizer isto.</p>
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
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/bar-pecas.js') ?>"></script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-regras.js') ?>"></script>
<script src="<?= asset('assets/bar-montagem.js') ?>"></script>
</body>
</html>
