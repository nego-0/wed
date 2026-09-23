<?php
// ============================================================
// bar-qr.php — As folhas de mesa, prontas a recortar
//
// É por aqui que os convidados entram no bar, e é a única porta: não há link
// no convite impresso nem no digital, e isso não é esquecimento (ver
// docs/modulo-bar.md §4). Um convite é de uma família e o bar precisa de saber
// qual das pessoas está a pedir; e um cartão impresso em Outubro não garante
// um bar que só existe em Dezembro.
//
// Cada cartão leva quatro coisas, por esta ordem de tamanho:
//   • o nome da mesa em grande — é o que o garçom procura no salão;
//   • o QR, que é como quase toda a gente entra;
//   • o endereço escrito, para quem prefira escrever ou tenha a câmara estragada;
//   • e o rodapé que diz o que fazer sem rede, que é a saída de sempre.
//
// A página existe à parte de bar.php porque uma folha para imprimir e um ecrã
// para trabalhar querem coisas contrárias: esta não tem cabeçalho, nem menu,
// nem botões — só papel.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-endereco.php';
exigirAdmin();
exigirModulo('bar');

$DEFS = defsAtuais($conn);
$CAS  = casalDaFicha($conn);
$ENDERECO = rtrim(enderecoPublico(), '/');
$LINK_FESTA = barLinkDaFesta($conn);

// Uma mesa só, quando se vem reimprimir a folha de uma que se estragou.
$soEsta = (int)($_GET['mesa'] ?? 0);
$onde = $soEsta > 0 ? ' AND id=' . $soEsta : '';
$mesas = [];
$r = $conn->query("SELECT id, nome, bar_token FROM {$P}mesas WHERE " . doCasamento() . "$onde
                   ORDER BY (especial='noivos') DESC, nome");
if ($r) $mesas = $r->fetch_all(MYSQLI_ASSOC);
// Uma mesa antiga pode ainda não ter código (a coluna esteve vazia entre duas
// versões). Dá-se-lhe um AQUI, antes de imprimir: uma folha com um QR que não
// abre nada é pior do que uma folha a menos, porque ninguém desconfia dela.
foreach ($mesas as &$_m) {
    if (trim((string)($_m['bar_token'] ?? '')) === '') {
        $_m['bar_token'] = barMesaTokenGarantir($conn, (int)$_m['id']);
    }
}
unset($_m);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<?php include __DIR__ . '/parcial-icone.php'; ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Folhas do bar · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  /* Duas colunas por folha A4, quatro cartões por página: dá um cartão de
     ~9×13 cm, que é o que cabe de pé numa mesa sem tapar a decoração. */
  body{ background:var(--cream); }
  .barra{ max-width:900px; margin:1.2rem auto; padding:0 1rem; display:flex;
          gap:.6rem; align-items:center; flex-wrap:wrap; }
  .barra .dica{ margin:0; flex:1 1 16rem; }
  .folhas{ max-width:900px; margin:0 auto 3rem; padding:0 1rem;
           display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
  .cartao{ background:var(--card); border:1px dashed var(--line); border-radius:var(--radius);
           padding:1.4rem 1rem 1rem; text-align:center; break-inside:avoid; }
  .cartao .mono{ font-family:var(--serif); font-size:var(--t-etiqueta); font-weight:600; letter-spacing:.18em;
                 text-transform:uppercase; color:var(--gold-deep); }
  /* O nome da mesa é a maior coisa do cartão: é o que o garçom lê de longe
     quando anda com um tabuleiro à procura dela. */
  .cartao .mesa{ font-family:var(--serif); font-size:2.1rem; color:var(--ink);
                 line-height:1.1; margin:.25rem 0 .1rem; }
  .cartao .conv{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-bottom:.7rem; }
  .cartao canvas{ display:block; margin:0 auto; }
  .cartao .lnk{ font-size:var(--t-apoio); color:var(--ink-fraco); word-break:break-all;
                line-height:1.35; margin-top:.5rem; }
  .cartao .lnk b{ color:var(--ink); font-size:var(--t-apoio); }
  .cartao .rod{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-top:.6rem;
                border-top:1px solid var(--line); padding-top:.5rem; line-height:1.4; }

  /* A lista dos códigos: uma folha de trabalho, não um cartão de mesa. Duas
     colunas, para caber uma festa de 200 pessoas em duas ou três páginas. */
  .corte{ max-width:900px; margin:2rem auto 0; border-top:1px solid var(--line); }

  @media print{
    /* Os códigos começam em folha nova: pousa-se a folha das mesas na mesa e
       guarda-se esta, que não é para andar à vista de ninguém. */
    /* No papel não há barra, nem fundo, nem tinta desperdiçada em molduras
       cheias: o tracejado é para a tesoura. */
    .barra, .no-print{ display:none !important; }
    body{ background:#fff; }
    .folhas{ max-width:none; margin:0; padding:0; gap:0; }
    .cartao{ border-radius:0; padding:1.1cm .6cm .7cm; }
    @page{ size:A4; margin:1cm; }
  }
</style>
</head>
<body>

<div class="barra no-print">
  <p class="dica"><b>Uma folha por mesa</b>, para recortar e pousar. O endereço de cada
    uma leva o nome da mesa, e por isso faz duas coisas de uma vez: abre o menu e diz
    onde entregar. Sem folha nenhuma funciona na mesma — o <b>link da festa</b>, no
    Bar &rsaquo; Mesas e códigos, abre o menu e deixa a mesa à escolha. Não há link no
    convite, de propósito: um convite é de uma família, e o bar precisa de saber qual
    das pessoas está a pedir.</p>
  <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
  <a class="btn" href="bar.php">Voltar ao bar</a>
</div>

<?php if (!$mesas): ?>
  <div class="barra"><p class="dica">Ainda não há mesas.
    <a href="mesas.php">Desenhe o salão</a> e as folhas saem daqui.</p></div>
<?php else: ?>
<div class="folhas">
  <?php foreach ($mesas as $m):
    // O endereço da festa com o CÓDIGO da mesa por cima: a folha diz de que
    // casamento é (quem a apanha do chão sabe onde a devolver) e leva a bebida
    // à mesa certa sem ninguém ter de a escolher. O código é estável — o nome
    // da mesa pode mudar depois de a folha estar impressa, e muda.
    $url = $LINK_FESTA . '&m=' . rawurlencode((string)($m['bar_token'] ?? '')); ?>
  <div class="cartao">
    <div class="mono"><?= escP($CAS['mono']) ?> · Bar</div>
    <div class="mesa"><?= escP($m['nome']) ?></div>
    <div class="conv">Aponte a câmara para pedir as suas bebidas</div>
    <canvas class="qr" data-url="<?= escP($url) ?>"></canvas>
    <div class="lnk">ou escreva<br><b><?= escP($url) ?></b></div>
    <div class="rod">Sem rede? Chame um garçom — ele faz o pedido por si.</div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<script>
// O QR desenha-se no cliente: são poucos, e assim a folha não depende de
// serviço nenhum lá fora para se imprimir na véspera da festa.
document.querySelectorAll('canvas.qr').forEach(function (c) {
  new QRious({ element: c, value: c.dataset.url, size: 200, level: 'M',
               background: '#ffffff', foreground: '#12222F' });
});
</script>
</body>
</html>
