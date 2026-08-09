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
        'convite' => ['digital.php',         'Convite digital'],
        'porta'   => ['porteiro.php',        'Porta'],
        'equipa'  => ['equipa.php',          'Equipa'],
        // A entrada da plataforma só aparece a quem tem mais do que um
        // casamento à mão — para quem só tem o seu, seria uma porta para nada.
        'plataforma' => ['plataforma.php',   'Casamentos'],
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
    // "Casamentos" só faz sentido a quem escolhe entre vários.
    $variosCasamentos = false;
    if (function_exists('casamentosDoUtilizador') && isset($GLOBALS['conn'])) {
        $variosCasamentos = ehPessoalPlataforma()
                         || count(casamentosDoUtilizador($GLOBALS['conn'])) > 1;
    }
    if (!$variosCasamentos) unset($itens['plataforma']);
    $semPapel = !empty($opcoes['no_print']) ? ' no-print' : '';
    ?>
<header class="topo<?= $semPapel ?>">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div>
      <h1><?= escP($titulo) ?></h1>
      <?php if ($sub !== ''): ?><div class="sub"><?= escP($sub) ?></div><?php endif; ?>
      <?php if (!empty($variosCasamentos)):
        $nomeAberto = '';
        $stc = @$GLOBALS['conn']->prepare("SELECT nome FROM " . PREFIXO . "casamentos WHERE id=?");
        if ($stc) { $cid = casamentoAtual(); $stc->bind_param('i', $cid); $stc->execute();
                    $rowc = $stc->get_result()->fetch_assoc(); $nomeAberto = $rowc['nome'] ?? ''; }
        if ($nomeAberto !== ''): ?>
        <div class="sub" style="opacity:.85">A trabalhar em: <b><?= escP($nomeAberto) ?></b>
          · <a href="plataforma.php" style="color:inherit;text-decoration:underline">trocar</a></div>
      <?php endif; endif; ?>
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
    // Visita de suporte: uma tira que não deixa esquecer em casa de quem se
    // está. Sem isto, uma pessoa da plataforma passava a tarde a mexer na
    // festa de um casal convencida de que era a de outro.
    if (function_exists('emVisitaDeSuporte') && emVisitaDeSuporte()):
        $podeMexer = podeCorrigir(); ?>
<div class="tira-suporte<?= $semPapel ?>">
  Visita de suporte <b><?= $podeMexer ? 'com permissão de correção' : 'de leitura' ?></b>
  — <?= $podeMexer ? 'pode ver e corrigir.' : 'pode ver; alterar, não.' ?>
  <a href="equipa.php">terminar a visita</a>
</div>
<style>
  .tira-suporte{ background:var(--warn-bg); border-bottom:1px solid var(--warn); color:var(--ink);
                 text-align:center; padding:.45rem .8rem; font-size:.82rem; }
  .tira-suporte a{ color:inherit; }
  @media print{ .tira-suporte{ display:none !important; } }
</style>
<?php endif;
}
