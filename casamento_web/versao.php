<?php
// ============================================================
// versao.php — O que está mesmo instalado neste servidor
//
// Serve para responder a uma pergunta que por telefone é impossível:
// "o servidor tem a correção X?". Em vez de adivinhar, abre-se esta
// página e lê-se. Cada correção recente tem uma marca no código; aqui
// procura-se essa marca e diz-se se está lá ou não.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';   // escP()
exigirAdmin();

/** Uma correção, e a marca que a denuncia no código instalado. */
function correcoesEsperadas(): array {
    return [
        ['Lista de convites numa linha só',
         'assets/estilo.css', 'grid-template-columns:auto auto 1fr auto'],
        ['Ícone dos botões com medida (linha baixa)',
         'assets/estilo.css', '.btn-ico svg{ width:16px'],
        ['Símbolos ♂/♀ legíveis',
         'index.php', 'font-size:1.15em'],
        ['Endereços dos assets com marca de versão',
         'config.php', 'function asset('],
        ['Barras reclamam o gesto horizontal (tátil / rato de precisão)',
         'assets/editor.css', 'input[type=range]{ touch-action:pan-y'],
        ['Aviso "por guardar" fora da barra de opções (o editor deixa de saltar)',
         'assets/editor.css', '.marca-sujo{'],
        ['Seletor de cor fora do <label>',
         'editor-cartao.php', 'class="cor-linha">'],
        ['Redesenho adiado durante um gesto',
         'assets/editor-adiar.js', 'global.adiavel'],
        ['Diagnóstico do gesto com ?diag=1',
         'editor-cartao.php', 'editor-diag.js'],
        ['Alternativas das camadas decorativas do cartão',
         'pecas.php', 'function cartaoMolduras('],
        ['Cores do convite digital com nome',
         'personalizacao.php', 'function temaVarsRotulos('],
        ['Versões dos dois convites, num seletor na barra superior',
         'assets/versoes.js', 'Pôr em vigor'],
        ['Capa (envelope) com monograma editável no editor digital',
         'convite-editor.php', "CAPA_ID = 'capa'"],
    ];
}

$resultados = [];
foreach (correcoesEsperadas() as [$nome, $ficheiro, $marca]) {
    $abs = __DIR__ . '/' . $ficheiro;
    $conteudo = is_readable($abs) ? file_get_contents($abs) : false;
    $resultados[] = [
        'nome'     => $nome,
        'ficheiro' => $ficheiro,
        'existe'   => $conteudo !== false,
        'ok'       => $conteudo !== false && strpos($conteudo, $marca) !== false,
    ];
}
$faltam = count(array_filter($resultados, fn($r) => !$r['ok']));

$emFalta = [];
foreach (ficheirosApp() as $f) if (!is_readable(__DIR__ . '/' . $f)) $emFalta[] = $f;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Versão instalada</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  body{ padding:1.5rem; max-width:820px; margin:0 auto; }
  h1{ margin-bottom:.2rem; }
  .assin{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:1.5rem; color:var(--gold);
          background:var(--cream); border:1px solid var(--line); border-radius:10px;
          padding:.5rem .9rem; display:inline-block; margin:.4rem 0 1rem; }
  table{ width:100%; border-collapse:collapse; font-size:.88rem; }
  th,td{ text-align:left; padding:.45rem .5rem; border-bottom:1px solid var(--line); vertical-align:top; }
  th{ font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:#8a8f88; }
  .sim{ color:#1f7a3d; font-weight:600; }
  .nao{ color:var(--danger); font-weight:600; }
  td.f{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.78rem; color:#8a8f88; }
  .aviso{ border-radius:10px; padding:.8rem 1rem; margin:1rem 0; line-height:1.55; }
  .aviso.mau{ background:#fbeceb; border:1px solid #e6c3bf; }
  .aviso.bom{ background:#eaf4ee; border:1px solid #bcdcc8; }
  .copiar{ margin-top:1rem; }
  pre{ background:var(--cream); border:1px solid var(--line); border-radius:8px; padding:.7rem;
       font-size:.78rem; white-space:pre-wrap; }
</style>
</head>
<body>
<h1>Versão instalada</h1>
<p style="color:#8a8f88;margin:.2rem 0">Assinatura do que está neste servidor. Duas instalações
iguais dão a mesma assinatura.</p>
<div class="assin"><?= versaoApp() ?></div>

<?php if ($faltam): ?>
  <div class="aviso mau"><b><?= $faltam ?> correção(ões) recente(s) não estão neste servidor.</b><br>
  O código que está a correr é mais antigo do que o que foi entregue. Enquanto assim for,
  qualquer correção nova também não chega.</div>
<?php else: ?>
  <div class="aviso bom"><b>Está tudo cá.</b> Este servidor tem todas as correções recentes.</div>
<?php endif; ?>

<table>
  <tr><th>Correção</th><th>Onde</th><th>Está?</th></tr>
  <?php foreach ($resultados as $r): ?>
    <tr>
      <td><?= escP($r['nome']) ?></td>
      <td class="f"><?= escP($r['ficheiro']) ?><?= $r['existe'] ? '' : ' <span class="nao">(ficheiro em falta)</span>' ?></td>
      <td class="<?= $r['ok'] ? 'sim' : 'nao' ?>"><?= $r['ok'] ? 'sim' : 'NÃO' ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<?php if ($emFalta): ?>
  <h3 style="margin-top:1.4rem">Ficheiros que faltam</h3>
  <pre><?= escP(implode("\n", $emFalta)) ?></pre>
<?php endif; ?>

<div class="copiar">
  <button class="btn" onclick="copiar()">Copiar este resumo</button>
</div>
<pre id="resumo" style="display:none"><?= escP(versaoApp()) ?> · <?= $faltam ?> em falta
<?php foreach ($resultados as $r) echo ($r['ok'] ? '[ok] ' : '[--] ') . $r['nome'] . "\n"; ?>
PHP <?= PHP_VERSION ?> · <?= escP($_SERVER['SERVER_SOFTWARE'] ?? '?') ?></pre>
<script>
function copiar(){
  const t = document.getElementById('resumo').textContent;
  navigator.clipboard && navigator.clipboard.writeText(t);
  document.querySelector('.copiar .btn').textContent = 'Copiado';
}
</script>
</body>
</html>
