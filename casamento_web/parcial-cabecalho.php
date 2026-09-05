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
        'orcamento' => ['orcamento.php',     'Orçamento'],
        'gestao'  => ['gestao.php',          'Gestão'],
        'licenca' => ['licenca.php',         'Licença'],
        // A entrada da plataforma só aparece a quem tem mais do que um
        // casamento à mão — para quem só tem o seu, seria uma porta para nada.
        'plataforma' => ['plataforma.php',   'Casamentos'],
        'modelos'    => ['modelos.php',      'Modelos'],
    ];
}

/**
 * O módulo de licença que comanda cada entrada do menu.
 *
 * As que não estão aqui não dependem de licença nenhuma: a Gestão é onde o
 * casal exporta e apaga os seus dados, e essa porta fica aberta mesmo com a
 * licença revogada — é o que as políticas lhe prometem (Lei n.º 22/11,
 * artigos 26.º e 28.º).
 */
function menuModulos(): array {
    return [
        'painel'    => 'convidados',
        'porta'     => 'porta',
        'mesas'     => 'mesas',
        'orcamento' => 'orcamento',
        'grafica'   => 'impresso',
        'convite'   => 'digital',
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
    // Sem casamento aberto — o pessoal da casa acabado de entrar — não há casal
    // nenhum a nomear. Pôr aqui o primeiro do sistema era vestir a página de
    // quem gere a plataforma com o nome de um casal ao acaso.
    $semCasamento = function_exists('casamentoAtual') && casamentoAtual() <= 0;
    $CAS = $GLOBALS['CAS'] ?? null;
    if ($semCasamento) {
        $CAS = ['mono' => PLATAFORMA['marca'], 'casal' => PLATAFORMA['nome'],
                'noiva' => '', 'noivo' => ''];
    } elseif (!is_array($CAS) || !isset($CAS['mono'])) {
        // As páginas já calculam $CAS; se não, calcula-se aqui a partir da ligação.
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
    // O orçamento é dos noivos: o porteiro, que trabalha à porta, não tem lá
    // contas nenhumas. (Numa visita de suporte, o papel continua 'admin' e a
    // entrada fica — vê-se, mas em leitura, como o resto da página.)
    if (!function_exists('ehAdmin') || !ehAdmin()) unset($itens['orcamento']);
    // Os modelos são da casa: quem não responde por ela não tem lá o que fazer.
    if (!function_exists('ehAdminPlataforma') || !ehAdminPlataforma()) unset($itens['modelos']);

    // ---- o que a licença abre ----
    // Uma entrada para um módulo que este casamento não tem é uma porta que só
    // sabe dizer "não". Tira-se do menu, e a página da Licença — que fica —
    // é que conta o que lá havia e como o ter.
    if (!$semCasamento && function_exists('podeModulo') && isset($GLOBALS['conn'])) {
        foreach (menuModulos() as $chave => $modulo) {
            if (!podeModulo($modulo)) unset($itens[$chave]);
        }
    }
    // A Licença é do casal — o porteiro não pede planos nenhuns.
    //
    // Quem responde pela casa também a vê, mas só quando tem um casamento
    // aberto: é a forma de ir ver, do lado de dentro, exactamente o que o casal
    // vê. A decisão continua a tomar-se em Casamentos → Licenças; esta página,
    // para o pessoal da plataforma, é de leitura (ver $soVer em licenca.php).
    if (!function_exists('ehAdmin') || !ehAdmin()) unset($itens['licenca']);
    elseif (ehPessoalPlataforma() && $semCasamento) unset($itens['licenca']);
    // Sem casamento aberto, as entradas do menu levavam todas ao mesmo sítio:
    // de volta a esta página, porque não há casamento nenhum para mostrar. Um
    // menu que só sabe dizer "não" é pior do que um menu curto.
    if ($semCasamento) $itens = array_intersect_key($itens, ['plataforma' => 1, 'modelos' => 1]);
    $semPapel = !empty($opcoes['no_print']) ? ' no-print' : '';
    ?>
<?php include __DIR__ . '/parcial-tema.php'; ?>
<header class="topo<?= $semPapel ?>">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div>
      <h1><?= escP($titulo) ?></h1>
      <?php if ($sub !== ''): ?><div class="sub"><?= escP($sub) ?></div><?php endif; ?>
      <?php
        // Quanto tempo de licença resta a este casamento — logo abaixo dos
        // nomes, para o casal saber sempre com que prazo conta. Só quando há
        // casamento aberto e a licença tem limite.
        if (!$semCasamento && function_exists('licencaInfo') && isset($GLOBALS['conn'])):
          $licInfo   = licencaInfo($GLOBALS['conn'], casamentoAtual());
          $licFrase  = licencaFrase($licInfo);
          if ($licFrase !== ''):
            $licMau = !$licInfo['iniciada'] || (int)$licInfo['dias'] < 15; ?>
        <div class="sub licenca-restante<?= $licMau ? ' aviso' : '' ?>"><?= escP($licFrase) ?></div>
      <?php endif; endif; ?>
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
    tiraSuporte(!empty($opcoes['no_print']));
    // A pastilha circular do tema — discreta, no canto. Só onde há cabeçalho
    // (páginas com estilo.css); nunca no papel.
    if (empty($opcoes['no_print'])) include __DIR__ . '/parcial-seletor-tema.php';
}

/**
 * A tira da visita de suporte, e o modo de leitura do ecrã.
 *
 * Fica à parte de cabecalho() porque as páginas que mais precisam dela — os
 * dois editores e a porta — têm barra própria e nunca chamaram o cabeçalho
 * partilhado. Eram precisamente as que ficavam sem aviso nenhum.
 */
function tiraSuporte(bool $noPrint = false): void {
    if (!function_exists('emVisitaDeSuporte')) return;
    $visita = emVisitaDeSuporte();
    $daCasa = function_exists('entrouComoPlataforma') && entrouComoPlataforma();
    if (!$visita && !$daCasa) return;
    static $jaSaiu = false;
    if ($jaSaiu) return;                 // uma página, uma tira
    $jaSaiu = true;
    $semPapel = $noPrint ? ' no-print' : '';

    // O admin da plataforma não é nenhum dos casais. Entra em qualquer
    // casamento porque responde pela casa — e essa diferença tem de estar à
    // vista, senão passa a tarde a mexer na festa de um casal convencido de
    // que está na sua própria conta.
    if (!$visita): ?>
<div class="tira-suporte<?= $semPapel ?>">
  Está a ver este casamento como <b>administração da plataforma</b>, e não como os noivos.
  Tudo o que fizer aqui é na festa deles.
  <a href="plataforma.php">ver os casamentos</a> ·
  <a href="#" onclick="sairDoCasamento(event)">sair deste casamento</a>
</div>
<script>
// Sair do casamento sem terminar a sessão. Sem isto, a única forma de sair era
// abrir outro — ou ir-se embora, que é responder a uma pergunta com outra.
function sairDoCasamento(ev){
  ev.preventDefault();
  fetch('api.php?action=casamento_fechar', { method:'POST',
    headers:{ 'X-CSRF-Token': window.CSRF || '' } })
    .then(() => location.href = 'plataforma.php');
}
</script>
<style>
  .tira-suporte{ background:var(--warn-bg); border-bottom:1px solid var(--warn); color:var(--ink);
                 text-align:center; padding:.45rem .8rem; font-size:.82rem; }
  .tira-suporte a{ color:inherit; }
  @media print{ .tira-suporte{ display:none !important; } }
</style>
<?php return; endif;

    $podeMexer = podeCorrigir();

    if (!$podeMexer):
        // O ecrã em modo de leitura. Vai com 'defer' para correr depois de
        // toda a página — inclusive do api.js que carrega lá em baixo, que é
        // o que este ficheiro precisa de embrulhar. ?>
<script>window.SO_VER = true; window.SO_VER_ACOES = <?= json_encode(acoesDoCasamento()) ?>;</script>
<script defer src="<?= asset('assets/so-ver.js') ?>"></script>
<style>
  .so-ver-off{ opacity:.42; cursor:not-allowed !important; filter:grayscale(.65); }
  .so-ver-off:hover{ opacity:.42; box-shadow:none; }
</style>
<?php endif; ?>
<div class="tira-suporte<?= $semPapel ?>">
  Visita de suporte <b><?= $podeMexer ? 'com permissão de correção' : 'de leitura' ?></b>
  — <?= $podeMexer ? 'pode ver e corrigir.'
                   : 'pode ver tudo; alterar, não — o que estiver apagado nesta página não responde.' ?>
  <a href="gestao.php">terminar a visita</a>
</div>
<style>
  .tira-suporte{ background:var(--warn-bg); border-bottom:1px solid var(--warn); color:var(--ink);
                 text-align:center; padding:.45rem .8rem; font-size:.82rem; }
  .tira-suporte a{ color:inherit; }
  @media print{ .tira-suporte{ display:none !important; } }
</style>
<?php
}
