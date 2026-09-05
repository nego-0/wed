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

    // O dia e a hora do casamento: dão a linha de identidade e a contagem.
    [$dataDoEvento, $horaDoEvento] = $semCasamento ? ['', ''] : diaDoCasamento();
    ?>
<?php include __DIR__ . '/parcial-tema.php'; ?>
<header class="topo<?= $semPapel ?>">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div class="topo-txt">
      <h1><?= escP($titulo) ?></h1>
      <?php if ($sub !== ''): ?><div class="sub"><?= escP($sub) ?></div><?php endif; ?>
      <?php
        // Quem é o casal e quando é o dia — em todas as páginas, no mesmo
        // sítio. Andava misturado na linha de apoio de algumas (o painel, as
        // mesas) e ausente das outras: em metade da casa não se sabia de quem
        // era a festa que se estava a mexer.
        if (!$semCasamento && $dataDoEvento !== ''): ?>
        <div class="sub topo-casal"><?= escP($CAS['casal']) ?>
          · <?= escP(dataExtensa($dataDoEvento)) ?></div>
      <?php elseif (!$semCasamento): ?>
        <div class="sub topo-casal"><?= escP($CAS['casal']) ?></div>
      <?php endif; ?>
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
    <?php contagem($dataDoEvento, $horaDoEvento, !empty($opcoes['no_print'])); ?>
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
 * O dia e a hora do casamento aberto, como [data, hora] — '' quando não há.
 *
 * A página pode já ter as definições em $DEFS (quase todas têm), e nesse caso
 * não se pergunta duas vezes à base. O formato confere-se aqui: uma data
 * estragada não vai fazer contas erradas no browser.
 */
function diaDoCasamento(): array {
    $d = $GLOBALS['DEFS'] ?? null;
    if (!is_array($d) && isset($GLOBALS['conn'])) $d = defsAtuais($GLOBALS['conn']);
    if (!is_array($d)) return ['', ''];
    $data = (string)($d['evento.data'] ?? '');
    $hora = (string)($d['evento.hora'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = '';
    if (!preg_match('/^\d{2}:\d{2}$/', $hora))       $hora = '';
    return [$data, $hora];
}

/**
 * A contagem decrescente, onde quer que haja um cabeçalho.
 *
 * É a pergunta que o casal faz todos os dias, e que até aqui só o convite
 * respondia — a casa onde ele trabalha ficava calada. Vai com a data no
 * atributo: quem conta é o browser, e não uma página que já ficou velha ao ser
 * servida. Sem data marcada, não sai nada: uma contagem para o nada não é uma
 * contagem.
 */
function contagem(string $data, string $hora, bool $noPrint = false): void {
    if ($data === '') return;
    ?>
    <div class="contagem<?= $noPrint ? ' no-print' : '' ?>" id="topo-contagem"
         data-dia="<?= escP($data) ?>" data-hora="<?= escP($hora) ?>" aria-live="polite">
      <div class="cg-n">—</div><div class="cg-l">para o grande dia</div>
    </div>
    <?php
    contagemScript();
}

/**
 * A contagem decrescente do cabeçalho.
 *
 * Quem conta é o browser: uma contagem calculada no servidor fica velha no
 * instante em que a página é servida, e o casal deixa a página aberta a tarde
 * inteira. Corre uma vez por minuto — mais do que isso não muda nada à vista,
 * e no último dia é o que separa 3h11 de 3h10.
 *
 * O dia do casamento não é um número: é «É HOJE». E o dia seguinte também não
 * conta para trás — passa a contar para a frente, que é o que um casal quer
 * ver depois de casar.
 *
 * Sai uma vez por página (o cabeçalho também só sai uma).
 */
function contagemScript(): void {
    static $jaSaiu = false;
    if ($jaSaiu) return;
    $jaSaiu = true;
    ?>
<script>
(function(){
  var cx = document.getElementById('topo-contagem');
  if (!cx) return;
  var n = cx.querySelector('.cg-n'), rot = cx.querySelector('.cg-l');
  var dia = cx.dataset.dia || '', hora = cx.dataset.hora || '';
  var p = dia.split('-'), h = (hora || '00:00').split(':');
  // Meia-noite local quando não há hora: o dia conta desde que começa.
  var alvo = new Date(+p[0], +p[1] - 1, +p[2], +h[0] || 0, +h[1] || 0, 0, 0);
  // O dia seguinte ao casamento, para saber quando a festa já passou.
  var fim = new Date(+p[0], +p[1] - 1, +p[2] + 1, 0, 0, 0, 0);

  function plural(v, um, muitos){ return v + ' ' + (v === 1 ? um : muitos); }

  function pintar(){
    var agora = new Date();
    if (agora >= fim){
      var dias = Math.floor((agora - fim) / 86400000) + 1;
      cx.classList.add('passou');
      n.textContent = plural(dias, 'dia', 'dias');
      rot.textContent = 'desde o grande dia';
      return;
    }
    if (agora >= alvo || (agora.getFullYear() === alvo.getFullYear()
        && agora.getMonth() === alvo.getMonth() && agora.getDate() === alvo.getDate())){
      cx.classList.add('hoje');
      n.textContent = 'É HOJE';
      rot.textContent = agora < alvo ? horasAte(alvo, agora) : 'que a festa é vossa';
      return;
    }
    var ms = alvo - agora;
    var dias2 = Math.floor(ms / 86400000);
    if (dias2 >= 1){
      n.textContent = plural(dias2, 'dia', 'dias');
      rot.textContent = 'para o grande dia';
    } else {
      n.textContent = horasAte(alvo, agora);
      rot.textContent = 'para o grande dia';
    }
  }
  function horasAte(a, agora){
    var ms = Math.max(0, a - agora);
    var hs = Math.floor(ms / 3600000), mi = Math.floor((ms % 3600000) / 60000);
    return hs > 0 ? (hs + 'h' + (mi < 10 ? '0' : '') + mi) : plural(mi, 'minuto', 'minutos');
  }
  pintar();
  setInterval(pintar, 60000);
})();
</script>
<?php
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
