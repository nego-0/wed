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
        'bar'     => ['bar.php',             'Bar'],
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
        'bar'       => 'bar',
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
        $CAS = ['mono' => PLATAFORMA['mono'], 'casal' => PLATAFORMA['nome'],
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
    if (!function_exists('ehAdmin') || !ehAdmin()) { unset($itens['orcamento']); unset($itens['bar']); }
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
<?php // O alfabeto de sinais da casa, em TODAS as páginas que têm cabeçalho.
      // Vivia só nas quatro do bar, e por isso o resto do sistema escrevia os
      // seus sinais com emojis — que mudam de desenho conforme o telemóvel,
      // não obedecem à cor do tema, e trazem o seu próprio fundo colorido para
      // dentro de um convite. Aqui é uma folha só, partilhada, e vem ANTES de
      // janela.js, que a usa para desenhar o sinal de cada janela. ?>
<script src="<?= asset('assets/icones.js') ?>"></script>
<header class="topo<?= $semPapel ?>">
  <!-- Primeira paragem de teclado da página. Fica invisível até receber foco:
       quem usa rato nunca a vê, quem tabula não atravessa doze ligações de
       menu para chegar ao conteúdo (docs/auditoria-ui-ux.md §7). -->
  <a class="salta-conteudo" href="#conteudo">Saltar para o conteúdo</a>
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div class="topo-txt">
      <h1><?= escP($titulo) ?></h1>
      <?php if ($sub !== ''): ?><div class="sub"><?= escP($sub) ?></div><?php endif; ?>
      <?php
        // Quem é o casal e quanto falta — em todas as páginas, no mesmo sítio.
        // Andava misturado na linha de apoio de algumas (o painel, as mesas) e
        // ausente das outras: em metade da casa não se sabia de quem era a
        // festa que se estava a mexer.
        //
        // No lugar da data está agora a contagem. A data lê-se uma vez e nunca
        // mais muda — quem trabalha aqui já a sabe de cor; o que se quer saber
        // ao abrir a página é quanto falta. Continua à mão, no título da
        // contagem, para quem a for procurar.
        if (!$semCasamento && $dataDoEvento !== ''): ?>
        <div class="sub topo-casal"><?= escP($CAS['casal']) ?>
          · <?php contagem($dataDoEvento, $horaDoEvento, !empty($opcoes['no_print'])); ?></div>
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
    <nav class="nav<?= $semPapel ?>">
      <?php foreach ($itens as $chave => [$url, $rotulo]): ?>
      <a href="<?= $url ?>"<?= $chave === $ativo ? ' class="ativo" aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>
<?php
  // ---------- A navegação de baixo, no telemóvel ----------
  //
  // A tira do cabeçalho continua onde estava, e no ecrã largo é ela que manda.
  // Mas a 390px ela escondia 741px de si própria numa rolagem horizontal sem
  // indício nenhum: três destinos visíveis em doze, e três quartos da aplicação
  // por descobrir (docs/auditoria-ui-ux.md §8). Uma funcionalidade que não se
  // encontra vale zero, por melhor que seja.
  //
  // Aqui em baixo ficam os destinos do trabalho de todos os dias, ao alcance do
  // polegar e sempre à vista; o resto — administração, licença, contas — vai
  // para uma folha que sobe quando se pede. A ordem não é a do menu de cima: é
  // a do que o casal faz mais vezes.
  //
  // Fica FORA do <header> de propósito. As provas lêem `header nav a` para
  // saber o menu de uma página, e uma segunda cópia lá dentro duplicava-lhes
  // todos os destinos.
  $ordemBaixo = ['painel', 'mesas', 'convite', 'orcamento', 'bar', 'grafica', 'porta'];
  // Rótulos curtos, só para a barra. «Convite digital» tem 15 caracteres e a
  // coluna tem 78px: cortava-se a meio, e um rótulo cortado não é um rótulo.
  // O nome por extenso continua no menu de cima e na folha, onde há largura.
  $curtoDe = [
    'convite' => 'Convite', 'grafica' => 'Impresso', 'plataforma' => 'Casamentos',
  ];
  $icoDe = [
    'painel' => 'pessoas', 'mesas'   => 'mesa',   'grafica'    => 'carta',
    'convite'=> 'telemovel','porta'  => 'porta',  'bar'        => 'taca',
    'orcamento'=>'moeda',  'gestao'  => 'mala',   'licenca'    => 'chave',
    'plataforma'=>'anel',  'modelos' => 'documento',
  ];
  // Até quatro: com cinco alvos numa barra de 390px, cada um fica com 78px e o
  // rótulo deixa de caber sem cortar.
  $naBarra = [];
  foreach ($ordemBaixo as $k) {
    if (count($naBarra) >= 4) break;
    if (isset($itens[$k])) $naBarra[] = $k;
  }
  $naFolha = array_diff(array_keys($itens), $naBarra);
  $ativoNaFolha = in_array($ativo, $naFolha, true);
?>
<nav class="nav-baixo<?= $semPapel ?>" aria-label="Destinos principais">
  <?php foreach ($naBarra as $k): [$url, $rotulo] = $itens[$k];
        $eh = ($k === $ativo); ?>
  <a href="<?= $url ?>" class="nb-item<?= $eh ? ' ativo' : '' ?>"<?= $eh ? ' aria-current="page"' : '' ?>>
    <span class="nb-ico" data-ico="<?= $icoDe[$k] ?? 'ponto' ?>"></span>
    <span class="nb-rot"><?= escP($curtoDe[$k] ?? $rotulo) ?></span>
  </a>
  <?php endforeach; ?>
  <button type="button" class="nb-item nb-mais<?= $ativoNaFolha ? ' ativo' : '' ?>"
          id="nb-mais" aria-expanded="false" aria-controls="folha-mais">
    <span class="nb-ico" data-ico="reticencias"></span>
    <span class="nb-rot">Mais</span>
  </button>
</nav>
<div class="folha-fundo<?= $semPapel ?>" id="folha-fundo" hidden></div>
<div class="folha-mais<?= $semPapel ?>" id="folha-mais" hidden
     role="dialog" aria-modal="true" aria-labelledby="fm-tit">
  <div class="fm-pega" aria-hidden="true"></div>
  <h2 class="fm-tit" id="fm-tit">Mais</h2>
  <div class="fm-lista">
    <?php foreach ($naFolha as $k): [$url, $rotulo] = $itens[$k];
          $eh = ($k === $ativo); ?>
    <a href="<?= $url ?>"<?= $eh ? ' class="ativo" aria-current="page"' : '' ?>>
      <span class="fm-ico" data-ico="<?= $icoDe[$k] ?? 'ponto' ?>"></span><?= escP($rotulo) ?></a>
    <?php endforeach; ?>
    <a href="logout.php" class="fm-sair"><span class="fm-ico" data-ico="porta"></span>Sair</a>
  </div>
</div>
<!-- A região viva por onde passam os avisos. A aplicação faz quase tudo sem
     recarregar — aprovar um pedido, guardar uma despesa, mudar uma mesa — e
     até aqui nenhuma dessas confirmações chegava a um leitor de ecrã: havia
     zero aria-live em todo o sistema. O api.js escreve aqui (ver anunciar()). -->
<div id="avisos-vivos" class="so-leitor" role="status" aria-live="polite" aria-atomic="true"></div>
<script>
/* O cabeçalho encolhe assim que se começa a trabalhar.
   Escreve-se no DOM só quando o estado MUDA, e uma vez por fotograma: a
   rajada de eventos de uma rolagem junta-se toda no mesmo repintar, que é a
   regra que esta casa aprendeu a arranjar o tremor da lista de escolha. */
(function () {
  'use strict';
  var topo = document.querySelector('.topo');
  if (!topo) return;
  var CURTO = 0, LONGO = 0, curto = false, marcado = false;

  /* O corpo guarda o lugar do cabeçalho, e o limiar é a altura dele.
     Mede-se aqui e não no CSS porque a altura muda de página para página — o
     subtítulo tem uma ou duas linhas, a tira da licença aparece ou não. */
  function medir() {
    if (curto) return;                    // encolhido, a medida não é a cheia
    var h = Math.round(topo.getBoundingClientRect().height);
    if (!h) return;
    document.documentElement.style.setProperty('--topo-alt', h + 'px');
    CURTO = h;            // só encolhe quando ele já saiu de vista
    LONGO = Math.max(8, Math.round(h * 0.35));
  }

  /* E a barra de baixo, pelo mesmo motivo: quem se cola ao fundo do ecrã tem
     de saber quanto é que ela ocupa. Mede-se em vez de se assumir 56px — há
     páginas que fazem o seu próprio cabeçalho e não têm barra nenhuma (o
     registo é uma), e nessas isto fica a zero e nada se levanta à toa.
     Foi esta barra que enterrou 57 dos 111px da conta do funil da licença por
     baixo dela, e não se via em fotografia: só a meio da rolagem. */
  function medirBaixo() {
    var nb = document.querySelector('.nav-baixo');
    var h = nb && getComputedStyle(nb).display !== 'none'
          ? Math.round(nb.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--nav-baixo-alt', h + 'px');
  }

  function ver() {
    marcado = false;
    var y = window.scrollY || document.documentElement.scrollTop || 0;
    var quer = curto ? (y > LONGO) : (y > CURTO);
    if (quer === curto) return;
    curto = quer;
    document.body.classList.toggle('topo-curto', curto);
  }
  window.addEventListener('scroll', function () {
    if (marcado) return;
    marcado = true;
    requestAnimationFrame(ver);
  }, { passive: true });
  window.addEventListener('resize', function () { medir(); medirBaixo(); }, { passive: true });
  medir();
  medirBaixo();
})();
</script>
<script>
/* A folha do «Mais»: sobe, e sai por onde se espera.
   Um painel que só fecha no botão que o abriu obriga a apontar; este fecha no
   fundo escurecido, no Escape, e ao escolher um destino. O foco entra na folha
   e volta ao botão quando ela se fecha — sem isso, quem navega por teclado
   ficava atrás dela, a tabular por uma página que já não vê. */
(function () {
  'use strict';
  var bt = document.getElementById('nb-mais');
  var folha = document.getElementById('folha-mais');
  var fundo = document.getElementById('folha-fundo');
  if (!bt || !folha || !fundo) return;

  function abrir() {
    folha.hidden = false; fundo.hidden = false;
    requestAnimationFrame(function () { folha.classList.add('aberta'); });
    bt.setAttribute('aria-expanded', 'true');
    var primeiro = folha.querySelector('a');
    if (primeiro) primeiro.focus();
  }
  function fechar(devolverFoco) {
    folha.classList.remove('aberta');
    bt.setAttribute('aria-expanded', 'false');
    // Espera-se a descida antes de a tirar da árvore, senão ela desaparece de
    // repente em vez de sair. Quem pediu menos movimento não espera nada.
    var lento = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180;
    setTimeout(function () { folha.hidden = true; fundo.hidden = true; }, lento);
    if (devolverFoco) bt.focus();
  }
  bt.addEventListener('click', function () {
    if (bt.getAttribute('aria-expanded') === 'true') fechar(true); else abrir();
  });
  fundo.addEventListener('click', function () { fechar(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !folha.hidden) { e.stopPropagation(); fechar(true); }
  }, true);
  folha.addEventListener('click', function (e) {
    if (e.target.closest('a')) fechar(false);   // escolheu: a página vai mudar
  });
})();
</script>
<script>
/* Os avisos de sucesso já existem em toda a aplicação, sob a forma de toasts —
   mas um toast é pintura: quem lê o ecrã nunca soube que a despesa ficou
   guardada. Em vez de reescrever as onze implementações de toast() espalhadas
   pelas páginas, embrulha-se a que existir, uma vez, quando a página acaba de
   montar. O `api.js` já anuncia os ERROS por sua conta (ver anunciar()).

   Onde o toast() é privado de um módulo — o bar-pecas.js tem o seu dentro de um
   IIFE — isto não lhe chega, e essas páginas continuam a anunciar só os erros.
   Fica dito para quem lá voltar. */
document.addEventListener('DOMContentLoaded', function () {
  var orig = window.toast;
  if (typeof orig !== 'function' || typeof window.anunciar !== 'function') return;
  window.toast = function (m) { window.anunciar(m); return orig.apply(this, arguments); };
});
</script>
<?php
    contagemScript();
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
 *
 * O guião que a faz andar sai à parte — contagemScript(), depois do cabeçalho.
 * Aqui dentro ficaria no meio de uma linha de texto, e o texto da linha passava
 * a incluir o código-fonte do guião.
 *
 * Uma linha só, com três pedaços — «faltam» · «132 dias» · «04:12:33» — para
 * caber no fio do texto onde estava a data, e igual na barra dos editores. Os
 * segundos contam-se de facto: uma contagem que não mexe é uma data escrita de
 * outra maneira.
 */
function contagem(string $data, string $hora, bool $noPrint = false): void {
    if ($data === '') return;
    $quando = dataExtensa($data) . ($hora !== '' ? ', às ' . str_replace(':', 'h', $hora) : '');
    ?><span class="contagem<?= $noPrint ? ' no-print' : '' ?>" id="topo-contagem"
         data-dia="<?= escP($data) ?>" data-hora="<?= escP($hora) ?>"
         title="<?= escP($quando) ?>"><span class="cg-l"></span> <span
         class="cg-n">—</span> <span class="cg-t"></span></span><?php
}

/**
 * A contagem decrescente do cabeçalho.
 *
 * Quem conta é o browser: uma contagem calculada no servidor fica velha no
 * instante em que a página é servida, e o casal deixa a página aberta a tarde
 * inteira. Corre uma vez por segundo — os segundos são metade do que faz uma
 * contagem ser uma contagem, e sem eles isto era a data escrita de outra
 * maneira.
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
  var caixas = [].slice.call(document.querySelectorAll('.contagem[data-dia]'));
  if (!caixas.length) return;

  function plural(v, um, muitos){ return v + ' ' + (v === 1 ? um : muitos); }
  function dd(v){ return (v < 10 ? '0' : '') + v; }
  /** O que sobra depois dos dias inteiros: hh:mm:ss, sempre com as duas casas. */
  function relogio(ms){
    var s = Math.max(0, Math.floor(ms / 1000));
    return dd(Math.floor(s / 3600) % 24) + ':' + dd(Math.floor(s / 60) % 60) + ':' + dd(s % 60);
  }

  function pintar(cx){
    var p = (cx.dataset.dia || '').split('-'), h = (cx.dataset.hora || '00:00').split(':');
    // Meia-noite local quando não há hora: o dia conta desde que começa.
    var alvo = new Date(+p[0], +p[1] - 1, +p[2], +h[0] || 0, +h[1] || 0, 0, 0);
    // O dia seguinte ao casamento, para saber quando a festa já passou.
    var fim  = new Date(+p[0], +p[1] - 1, +p[2] + 1, 0, 0, 0, 0);
    var pre = cx.querySelector('.cg-l'), n = cx.querySelector('.cg-n'),
        t   = cx.querySelector('.cg-t');
    var agora = new Date();
    cx.classList.remove('hoje', 'passou');

    if (agora >= fim){
      cx.classList.add('passou');
      pre.textContent = 'há';
      n.textContent = plural(Math.floor((agora - fim) / 86400000) + 1, 'dia', 'dias');
      t.textContent = '';
      return;
    }
    var mesmoDia = agora.getFullYear() === alvo.getFullYear()
                && agora.getMonth() === alvo.getMonth()
                && agora.getDate() === alvo.getDate();
    if (mesmoDia || agora >= alvo){
      cx.classList.add('hoje');
      pre.textContent = '';
      n.textContent = 'É HOJE';
      // Antes da hora marcada, o relógio ainda tem que contar; depois dela, a
      // festa está a acontecer e um cronómetro só estorvava.
      t.textContent = agora < alvo ? relogio(alvo - agora) : '';
      return;
    }
    var ms = alvo - agora, dias = Math.floor(ms / 86400000);
    pre.textContent = dias === 1 ? 'falta' : 'faltam';
    n.textContent = dias >= 1 ? plural(dias, 'dia', 'dias') : '';
    t.textContent = relogio(ms);
  }

  function todas(){ for (var i = 0; i < caixas.length; i++) pintar(caixas[i]); }
  todas();
  setInterval(todas, 1000);
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
                 text-align:center; padding:.45rem .8rem; font-size:var(--t-apoio); }
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
                 text-align:center; padding:.45rem .8rem; font-size:var(--t-apoio); }
  .tira-suporte a{ color:inherit; }
  @media print{ .tira-suporte{ display:none !important; } }
</style>
<?php
}
