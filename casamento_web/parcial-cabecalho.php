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
        'ajuda'   => ['ajuda.php',           'Ajuda'],
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
    } elseif (isset($GLOBALS['conn']) && function_exists('casalDaFicha')) {
        // A barra diz quem é o casamento ABERTO, e vai buscá-lo à ficha dele —
        // não ao $CAS que a página tiver calculado. As páginas calculam-no a
        // partir das definições do CONVITE (casalInfo(defsAtuais(...))), e o
        // convite é um sítio onde o casal escreve o que quer: um nome
        // experimentado no editor, ou um de exemplo deixado lá dentro,
        // aparecia no topo de todas as páginas da aplicação.
        $CAS = casalDaFicha($GLOBALS['conn']);
    } elseif (!is_array($CAS) || !isset($CAS['mono'])) {
        $CAS = casalInfo(defsPadrao());
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
    if (!function_exists('ehAdmin') || !ehAdmin()) { unset($itens['orcamento']); unset($itens['bar']); unset($itens['ajuda']); }
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
    // Algumas páginas trabalham numa coluna mais estreita do que os 1180px
    // da casa (Orçamento, Licença e Manual). A introdução tem de começar no
    // mesmo eixo do conteúdo que apresenta, em vez de parecer pertencer a
    // outra grelha. Só se aceita uma medida CSS simples, definida pela própria
    // página; qualquer valor inesperado regressa à largura comum.
    $larguraPagina = (string)($opcoes['largura'] ?? '1180px');
    if (!preg_match('/^\d+(?:\.\d+)?(?:px|rem)$/', $larguraPagina)) $larguraPagina = '1180px';

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
      <?php
        // O cabeçalho identifica a página e marca o tempo até à celebração.
        // A descrição pertence ao corpo, onde pode respirar e ser lida como
        // introdução; a licença tem a sua página. O nome dos noivos também não
        // se repete em todas as páginas: o casamento aberto já é o contexto.
        if (!$semCasamento && $dataDoEvento !== ''): ?>
        <div class="sub topo-contagem-linha"><?php
          contagem($dataDoEvento, $horaDoEvento, !empty($opcoes['no_print']), true); ?></div>
      <?php endif; ?>
    </div>
    <nav class="nav<?= $semPapel ?>">
      <?php foreach ($itens as $chave => [$url, $rotulo]): ?>
      <a href="<?= $url ?>"<?= $chave === $ativo ? ' class="ativo" aria-current="page"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
      <a href="logout.php">Sair</a>
    </nav>
    <?php // O puxador da gaveta. É um <button> e não um <a> de propósito: as
          // provas lêem `header nav a` para saber o menu de cada página, e um
          // link aqui dentro duplicava-lhes um destino que não existe. ?>
    <button type="button" class="gaveta-bt<?= $semPapel ?>" id="gaveta-bt"
            aria-expanded="false" aria-controls="gaveta" aria-label="Abrir o menu">
      <span class="gv-tracos" aria-hidden="true"><i></i><i></i><i></i></span>
    </button>
  </div>
</header>
<?php
  // ---------- A gaveta lateral, no telemóvel ----------
  //
  // A tira do cabeçalho continua onde estava, e no ecrã largo é ela que manda.
  // Mas a 390px ela escondia 741px de si própria numa rolagem horizontal sem
  // indício nenhum: três destinos visíveis em doze, e três quartos da
  // aplicação por descobrir (docs/auditoria-ui-ux.md §8).
  //
  // Esteve aqui, durante um tempo, uma barra fixa em baixo com quatro destinos
  // e uma folha para o resto. Resolvia o problema de os encontrar e criava
  // outro: era a TERCEIRA coisa a flutuar por cima da página, depois do
  // cabeçalho fixo e da pastilha do tema — e tapava-lhe 39 dos 44px, com
  // z-index 90 contra 75. Uma página com três camadas a pairar por cima já não
  // é uma página: é um monte de barras com conteúdo a espreitar por entre elas.
  //
  // Agora há UMA gaveta, que só existe quando se pede. Traz os doze destinos
  // por extenso (não há coluna de 78px a cortar «Convite digital» a meio), traz
  // a escolha do tema — que deixou de ter de flutuar sozinha — e sai de cena
  // assim que se escolhe. Com ela fechada, a vista principal não tem nada por
  // cima a não ser o cabeçalho.
  //
  // O custo, dito em voz alta: cada destino ficou a um toque de distância a
  // mais do que estava na barra. Em troca, a página inteira é da pessoa.
  //
  // Fica FORA do <header> de propósito, como a barra ficava: as provas lêem
  // `header nav a` para saber o menu de uma página, e uma segunda cópia lá
  // dentro duplicava-lhes todos os destinos.
  $icoDe = [
    'painel' => 'pessoas', 'mesas'   => 'mesa',   'grafica'    => 'carta',
    'convite'=> 'telemovel','porta'  => 'porta',  'bar'        => 'taca',
    'orcamento'=>'moeda',  'gestao'  => 'mala',   'licenca'    => 'chave', 'ajuda'=>'conversa',
    'plataforma'=>'anel',  'modelos' => 'documento',
  ];
  $temasGaveta = function_exists('temasDisponiveis') ? temasDisponiveis() : [];
  $amostrasGaveta = function_exists('temasAmostras') ? temasAmostras() : [];
?>
<div class="gaveta-fundo<?= $semPapel ?>" id="gaveta-fundo" hidden></div>
<nav class="gaveta<?= $semPapel ?>" id="gaveta" hidden
     role="dialog" aria-modal="true" aria-labelledby="gv-tit">
  <div class="gv-topo">
    <h2 class="gv-tit" id="gv-tit">Ir para</h2>
    <button type="button" class="gv-fechar" id="gv-fechar" aria-label="Fechar o menu">&times;</button>
  </div>
  <div class="gv-lista">
    <?php foreach ($itens as $k => [$url, $rotulo]): $eh = ($k === $ativo); ?>
    <a href="<?= $url ?>"<?= $eh ? ' class="ativo" aria-current="page"' : '' ?>>
      <span class="gv-ico" data-ico="<?= $icoDe[$k] ?? 'ponto' ?>"></span><?= escP($rotulo) ?></a>
    <?php endforeach; ?>
    <a href="logout.php" class="gv-sair"><span class="gv-ico" data-ico="porta"></span>Sair</a>
  </div>
  <?php // O tema vive aqui dentro, e não a pairar num canto: era a pastilha
        // que a barra de baixo tapava. Num ecrã largo continua no canto, onde
        // não estorva ninguém. ?>
  <?php if ($temasGaveta): ?>
  <div class="gv-temas">
    <h3 class="gv-sub">Tema visual</h3>
    <?php foreach ($temasGaveta as $chave => $rot):
      $c = $amostrasGaveta[$chave]['cores'] ?? ['#888', '#888', '#eee']; ?>
    <button type="button" class="gv-tema" data-gv-tema="<?= escP($chave) ?>"
            onclick="temaEscolher('<?= escP($chave) ?>'); gavetaMarcarTema();">
      <span class="gv-tema-cores" aria-hidden="true"><i style="background:<?= escP($c[0]) ?>"></i><i
        style="background:<?= escP($c[1]) ?>"></i><i style="background:<?= escP($c[2]) ?>"></i></span>
      <span class="gv-tema-nome"><?= escP($rot) ?></span>
      <span class="gv-tema-visto" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
        stroke-linejoin="round"><path d="M20 6.5 9.2 17.3 4 12.1"/></svg></span>
    </button>
    <?php endforeach; ?>
    <button type="button" class="gv-tema-repor" onclick="temaRepor()">Usar o tema da casa</button>
  </div>
  <?php endif; ?>
</nav>
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
  /* A marca de que o cabeçalho partilhado ESTÁ nesta página. É ela que liga o
     `position:fixed` e o lugar reservado, no estilo.css — e é posta aqui, pelo
     mesmo script que mede, para as duas coisas não se poderem separar. */
  document.body.classList.add('topo-fixo');

  function medir() {
    if (curto) return;                    // encolhido, a medida não é a cheia
    var h = Math.round(topo.getBoundingClientRect().height);
    if (!h) return;
    document.documentElement.style.setProperty('--topo-alt', h + 'px');
    CURTO = h;            // só encolhe quando ele já saiu de vista
    LONGO = Math.max(8, Math.round(h * 0.35));
  }

  /* Quanto ocupa o que está colado ao fundo do ecrã.
     Nasceu por causa da barra de navegação de baixo, que enterrava 57 dos
     111px da conta do funil da licença — e não se via em fotografia nenhuma,
     só a meio da rolagem. A barra saiu (é agora uma gaveta lateral, que não
     tapa nada), mas a medida FICA e continua a medir-se em vez de se assumir:
     é assim que a conta do funil volta a colar-se ao fundo verdadeiro sem que
     ninguém tenha de se lembrar de lá ir mudar um número. Se amanhã voltar a
     haver alguma coisa colada ao fundo, basta dar-lhe esta classe. */
  function medirBaixo() {
    var nb = document.querySelector('.barra-fundo');
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

  /* E volta a medir-se sempre que o cabeçalho mudar de altura.
     Medir uma vez, ao correr o script, dava 111px para um cabeçalho que
     acabava com 128: as fontes da casa ainda não tinham chegado e as linhas
     eram mais baixas. O corpo guardava-lhe 111px de lugar e os 17px que
     faltavam ficavam a tapar o princípio do conteúdo — a tira de suporte,
     precisamente, que é um aviso. Um ResizeObserver não tem de adivinhar
     quando é que a página assentou: sabe. */
  if (typeof ResizeObserver === 'function') {
    new ResizeObserver(function () { medir(); }).observe(topo);
  } else {
    // Sem observador, mede-se outra vez quando as fontes chegarem.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(medir);
    setTimeout(medir, 400);
  }
})();
</script>
<script>
/* A gaveta: abre-se quando se pede, e sai por onde se espera.
   Um painel que só fecha no botão que o abriu obriga a apontar; esta fecha no
   fundo escurecido, no Escape, e ao escolher um destino. O foco entra nela e
   volta ao puxador quando ela se fecha — sem isso, quem navega por teclado
   ficava atrás dela, a tabular por uma página que já não vê.
   E o foco fica PRESO lá dentro enquanto estiver aberta: um diálogo modal que
   deixa tabular para a página que está por baixo não é modal nenhum. */
(function () {
  'use strict';
  var bt = document.getElementById('gaveta-bt');
  var gv = document.getElementById('gaveta');
  var fundo = document.getElementById('gaveta-fundo');
  if (!bt || !gv || !fundo) return;
  // ESTA página tem gaveta, e é só aqui que a pastilha do tema se recolhe para
  // dentro dela. A copa, as entregas e a carta do convidado requerem este
  // ficheiro só para usar a tiraSuporte() — não desenham cabeçalho nenhum, não
  // têm gaveta, e ficaram sem nenhuma porta para o tema no telemóvel quando a
  // regra era `.tema-fab{display:none}` a seco. Uma classe entende-a toda a
  // gente, e diz a verdade: o tema mudou de sítio onde há sítio novo.
  document.body.classList.add('tem-gaveta');

  function focaveis() {
    return [].slice.call(gv.querySelectorAll('a[href], button:not([disabled])'))
      .filter(function (e) { return e.offsetParent !== null; });
  }
  function abrir() {
    gv.hidden = false; fundo.hidden = false;
    requestAnimationFrame(function () { gv.classList.add('aberta'); fundo.classList.add('aberto'); });
    bt.setAttribute('aria-expanded', 'true');
    // A página por baixo não rola enquanto a gaveta está aberta: rolar o que
    // está atrás de um painel modal é mexer no que não se está a ver.
    document.body.classList.add('com-gaveta');
    gavetaMarcarTema();
    var f = focaveis();
    if (f.length) f[0].focus();
  }
  function fechar(devolverFoco) {
    gv.classList.remove('aberta'); fundo.classList.remove('aberto');
    bt.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('com-gaveta');
    // Espera-se a saída antes de a tirar da árvore, senão desaparece de repente
    // em vez de sair. Quem pediu menos movimento não espera nada.
    var lento = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200;
    setTimeout(function () { gv.hidden = true; fundo.hidden = true; }, lento);
    if (devolverFoco) bt.focus();
  }
  bt.addEventListener('click', function () {
    if (bt.getAttribute('aria-expanded') === 'true') fechar(true); else abrir();
  });
  document.getElementById('gv-fechar').addEventListener('click', function () { fechar(true); });
  fundo.addEventListener('click', function () { fechar(false); });
  document.addEventListener('keydown', function (e) {
    if (gv.hidden) return;
    if (e.key === 'Escape') { e.stopPropagation(); fechar(true); return; }
    if (e.key !== 'Tab') return;
    var f = focaveis(); if (!f.length) return;
    var primeiro = f[0], ultimo = f[f.length - 1];
    if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
  }, true);
  gv.addEventListener('click', function (e) {
    if (e.target.closest('a')) fechar(false);   // escolheu: a página vai mudar
  });

  /* Qual dos temas está aceso. A escolha aplica-se de imediato (temaEscolher,
     em parcial-seletor-tema.php) e a gaveta fica aberta: quem está a comparar
     temas quer ver o efeito sem ter de reabrir o menu de cada vez. */
  window.gavetaMarcarTema = function () {
    var a = document.documentElement.getAttribute('data-tema') || 'niras';
    [].forEach.call(gv.querySelectorAll('[data-gv-tema]'), function (b) {
      var eu = b.getAttribute('data-gv-tema') === a;
      b.classList.toggle('on', eu);
      b.setAttribute('aria-pressed', eu ? 'true' : 'false');
    });
  };
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
    // A frase de apoio começa o conteúdo, em vez de engrossar o cabeçalho.
    // É centralizada aqui para todas as páginas seguirem a mesma regra.
    if ($sub !== ''): ?>
<div class="pagina-descricao<?= $semPapel ?>" style="--pagina-largura:<?= escP($larguraPagina) ?>"><p><?= escP($sub) ?></p></div>
<?php endif;
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
 * No cabeçalho sai como «132 Dias Até ao “Sim, Aceito”»; noutros contextos
 * conserva o formato compacto antigo. Na última semana junta o relógio.
 */
function contagem(string $data, string $hora, bool $noPrint = false, bool $modoSim = false): void {
    if ($data === '') return;
    $quando = dataExtensa($data) . ($hora !== '' ? ', às ' . str_replace(':', 'h', $hora) : '');
    ?><span class="contagem<?= $modoSim ? ' contagem-sim' : '' ?><?= $noPrint ? ' no-print' : '' ?>" id="topo-contagem"
         data-dia="<?= escP($data) ?>" data-hora="<?= escP($hora) ?>"
         title="<?= escP($quando) ?>"><?php if ($modoSim): ?><span class="cg-n">—</span> <span
         class="cg-l"></span><?php else: ?><span class="cg-l"></span> <span
         class="cg-n">—</span><?php endif; ?> <span class="cg-t"></span></span><?php
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
      var passados = Math.floor((agora - fim) / 86400000) + 1;
      pre.textContent = cx.classList.contains('contagem-sim') ? 'Desde o “Sim, Aceito”' : 'há';
      n.textContent = cx.classList.contains('contagem-sim')
        ? plural(passados, 'Dia', 'Dias')
        : plural(passados, 'dia', 'dias');
      t.textContent = '';
      return;
    }
    var mesmoDia = agora.getFullYear() === alvo.getFullYear()
                && agora.getMonth() === alvo.getMonth()
                && agora.getDate() === alvo.getDate();
    if (mesmoDia || agora >= alvo){
      cx.classList.add('hoje');
      pre.textContent = cx.classList.contains('contagem-sim') ? 'é o “Sim, Aceito”' : '';
      n.textContent = cx.classList.contains('contagem-sim') ? 'Hoje' : 'É HOJE';
      // Antes da hora marcada, o relógio ainda tem que contar; depois dela, a
      // festa está a acontecer e um cronómetro só estorvava.
      t.textContent = agora < alvo ? relogio(alvo - agora) : '';
      return;
    }
    var ms = alvo - agora, dias = Math.floor(ms / 86400000);
    pre.textContent = cx.classList.contains('contagem-sim')
      ? 'Até ao “Sim, Aceito”'
      : (dias === 1 ? 'falta' : 'faltam');
    n.textContent = cx.classList.contains('contagem-sim')
      ? plural(Math.max(0, dias), 'Dia', 'Dias')
      : (dias >= 1 ? plural(dias, 'dia', 'dias') : '');
    // O relógio só na última semana (docs/auditoria-ui-ux.md, EMO-001).
    //
    // A trezentos dias de distância, um cronómetro ao segundo não é uma
    // contagem: é um relógio de bomba no canto do cabeçalho, a mexer-se o dia
    // inteiro por cima do trabalho. E a segunda que passou não muda decisão
    // nenhuma — a esta distância planeia-se em semanas.
    //
    // Na semana da festa muda tudo: aí os segundos são a festa a chegar, e é
    // isso que se quer ver. É o mesmo número com dois significados, e o que
    // faz a diferença é a distância.
    t.textContent = dias < 7 ? relogio(ms) : '';
  }

  function todas(){ for (var i = 0; i < caixas.length; i++) pintar(caixas[i]); }
  todas();

  /* E o relógio da página também abranda com a distância.
     Estava a escrever no DOM uma vez por segundo, todo o ano, para mostrar um
     número que só muda à meia-noite. Longe da festa chega uma volta por
     minuto — a contagem de dias continua certa, e o cabeçalho deixa de se
     mexer por baixo dos olhos de quem está a trabalhar. */
  var ritmoAtual = 0, temporizador = null;
  function ritmo(){
    var perto = caixas.some(function (cx) {
      var p = (cx.dataset.dia || '').split('-'), h = (cx.dataset.hora || '00:00').split(':');
      var alvo = new Date(+p[0], +p[1] - 1, +p[2], +h[0] || 0, +h[1] || 0, 0, 0);
      var ms = alvo - new Date();
      // A última semana, o próprio dia e o dia seguinte contam como «perto».
      return ms < 7 * 86400000 && ms > -2 * 86400000;
    });
    var quero = perto ? 1000 : 60000;
    if (quero === ritmoAtual) return;
    ritmoAtual = quero;
    if (temporizador) clearInterval(temporizador);
    temporizador = setInterval(function(){ todas(); ritmo(); }, quero);
  }
  ritmo();
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
