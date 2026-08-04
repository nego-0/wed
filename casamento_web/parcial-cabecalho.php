<?php
// ============================================================
// parcial-cabecalho.php — O cabeçalho e o menu, num sítio só
// Antes, o mesmo menu estava copiado em oito páginas: acrescentar
// uma entrada obrigava a oito edições e bastava esquecer uma para
// o site ficar incoerente. Agora escreve-se aqui uma vez.
// ============================================================

/** As entradas do menu, por ordem. A chave é o "id" usado em $ativo. */
function menuPrincipal(): array {
    return [
        'painel'  => ['index.php',           'Painel'],
        'mesas'   => ['mesas.php',           'Mesas'],
        'grafica' => ['graficas.php',        'Convite impresso'],
        'convite' => ['convite-editor.php',  'Convite digital'],
        'porta'   => ['porteiro.php',        'Porta'],
    ];
}

/**
 * Escreve o cabeçalho da página.
 *
 * @param string $titulo  Título grande (ex.: "Mesas")
 * @param string $sub     Linha de apoio por baixo do título
 * @param string $ativo   Chave do menu a destacar (ver menuPrincipal())
 * @param array  $opcoes  'sem_porta' => true  (esconde a entrada "Porta")
 *                        'no_print'  => true  (não sai no papel)
 */
function cabecalho(string $titulo, string $sub, string $ativo, array $opcoes = []): void {
    // As páginas já calculam $CAS; se não, calcula-se aqui a partir da ligação.
    $CAS = $GLOBALS['CAS'] ?? null;
    if (!is_array($CAS) || !isset($CAS['mono'])) {
        $CAS = casalInfo(isset($GLOBALS['conn']) ? defsAtuais($GLOBALS['conn']) : defsPadrao());
    }
    $itens = menuPrincipal();
    if (!empty($opcoes['sem_porta'])) unset($itens['porta']);
    $semPapel = !empty($opcoes['no_print']) ? ' no-print' : '';
    ?>
<header class="topo<?= $semPapel ?>">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div>
      <h1><?= escP($titulo) ?></h1>
      <?php if ($sub !== ''): ?><div class="sub"><?= escP($sub) ?></div><?php endif; ?>
    </div>
    <nav class="nav<?= $semPapel ?>">
      <?php foreach ($itens as $chave => [$url, $rotulo]): ?>
      <a href="<?= $url ?>"<?= $chave === $ativo ? ' class="ativo" aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>
<?php
}
