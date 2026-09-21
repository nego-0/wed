<?php
// ============================================================
// bebidas.php — O menu, para quem está na festa
//
// Duas portas, e são duas perguntas diferentes:
//
//   ?c=CÓDIGO  — o código DA FESTA. Diz de que casamento se trata, e mais
//                nada. É o link do casal: vai no convite, no grupo da
//                família, no cartaz à entrada.
//
//   ?m=CÓDIGO  — o código DA MESA, impresso na folha pousada em cima dela.
//                Diz a mesma coisa que o outro E diz para onde vai a bebida.
//
// Durante muito tempo houve só o segundo, e isso amarrava duas coisas que não
// têm de andar juntas. Sem folha em cima da mesa não havia menu nenhum — e há
// gente de pé no jardim, há quem esteja ao balcão, há a folha que se molha ou
// vai parar ao bolso de alguém, e há o próprio casal a querer ver a carta.
// Quem entra pelo código da festa escolhe a mesa na página, como sempre pôde.
//
// Nenhum dos dois é segredo — quem se senta à mesa lê o da mesa, e o da festa
// anda no convite. O que impede alguém de pedir em nome de outro é o passo
// seguinte: a pessoa escolhe-se numa lista pela procura do nome, e o telemóvel
// fica preso a esse nome (ver docs/modulo-bar.md §5). É honesto dizê-lo: isto
// trava o engano e o abuso distraído, não um impostor decidido.
//
// A página veste o convite do casal — as cores e as letras saem das mesmas
// definições. Um menu com a paleta da casa, no meio da festa deles, seria uma
// peça de outro casamento.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

/**
 * Uma página inteira com um recado, e mais nada.
 *
 * É o ecrã do «Este código não serve», e passa a ser também o da copa fechada
 * e o da copa em pausa. A razão de ser o MESMO ecrã: nos três casos não há
 * nada para pedir, e um menu desenhado por baixo de um aviso a dizer que não
 * se pode pedir é uma montra — convida a escolher para depois recusar. Quem
 * chega quer saber se vale a pena esperar, e quer saber já.
 *
 * Com paleta, veste-se do convite do casal; sem ela (código que não serve, e
 * portanto festa que não se sabe qual é) fica em português simples, que é o
 * que se pode fazer quando nem as cores se conhecem.
 */
function barRecado(string $titulo, string $texto, ?array $pal = null,
                   array $tipo = [], int $http = 200, int $faltam = 0): void {
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    $fundo = $pal['ivory'] ?? '#FBF8F1';
    $tinta = $pal['text']  ?? '#20342A';
    $ouro  = $pal['gold']  ?? '#B4864A';
    $fraco = $pal['cream'] ? $tinta : '#6b7268';
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<meta name="theme-color" content="' . escP($fundo) . '">'
       . '<title>Bar</title>'
       . ($tipo ? '<link href="' . escP(asset('assets/fontes.css')) . '" rel="stylesheet">' : '')
       . '<style>' . ($tipo['faces'] ?? '') . ':root{' . ($tipo['vars'] ?? '') . '}</style>'
       . '</head>'
       . '<body style="font:16px/1.6 ' . ($tipo ? 'var(--f-sans, system-ui), ' : '')
       . 'system-ui,sans-serif;background:' . escP($fundo) . ';color:' . escP($tinta) . ';margin:0">'
       . '<div style="max-width:24rem;margin:18vh auto;padding:0 1.5rem;text-align:center">'
       // Uma taça a traço, e não um emoji: nesta página não entram as folhas
       // de estilo nem os ícones da casa, e o emoji sai como o desenho de
       // outro sistema operativo — ou como o quadrado do «não sei desenhar
       // isto».
       . '<svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="' . escP($ouro) . '" '
       . 'stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
       . '<path d="M7.6 3.2h8.8l-.5 5.1a4.4 4.4 0 0 1-7.8 0z"/><path d="M12 12.7V20"/>'
       . '<path d="M8.4 20.6h7.2"/></svg>'
       // O tamanho vai escrito: aqui não há tokens da casa carregados, e um
       // var(--t-titulo) sem ninguém que o defina deixa o título do tamanho
       // do texto.
       . '<h1 style="font-weight:400;font-size:1.5rem;margin:.6rem 0 .4rem'
       . ($tipo ? ';font-family:var(--f-serif, Georgia, serif)' : '') . '">'
       . escP($titulo) . '</h1>'
       . '<p style="color:' . escP($fraco) . ';opacity:.78">' . escP($texto) . '</p>'
       // A contagem, quando há uma espera com fim à vista. Não é enfeite: uma
       // pausa desfaz-se SOZINHA, e sem isto a pessoa ficava a olhar para uma
       // frase parada sem saber que a copa já tinha reaberto — e a única saída
       // era recarregar de vez em quando, à sorte. O relógio anda, e ao chegar
       // a zero a página vai buscar o menu por sua conta.
       //
       // O instante do fim vem do SERVIDOR (agora + faltam), e não da hora do
       // browser: a casa corre em Africa/Luanda e o telemóvel do convidado
       // pode estar noutro fuso ou com o relógio trocado.
       . ($faltam > 0
          ? '<p style="font-size:1.1rem;margin-top:1.1rem"><b id="b-conta" '
            . 'data-faltam="' . (int)$faltam . '" aria-live="polite">'
            . escP(barRelogio($faltam)) . '</b></p>'
            . '<script>(function(){'
            . 'var e=document.getElementById("b-conta");'
            . 'var fim=Date.now()+(+e.dataset.faltam)*1000;'
            . 'function passo(){'
            . 'var s=Math.max(0,Math.round((fim-Date.now())/1000));'
            . 'if(s<=0){location.reload();return;}'
            . 'var m=Math.floor(s/60);'
            . 'e.textContent=m+":"+String(s%60).padStart(2,"0");'
            . 'setTimeout(passo,1000);}'
            . 'passo();})();</script>'
          : '')
       . '</div></body></html>';
    exit;
}

// A mesa é opcional; a festa não. Tenta-se primeiro pelo código da mesa, que
// responde às duas perguntas de uma vez.
$token     = strtoupper(trim((string)($_GET['m'] ?? '')));
$tokenCasa = strtoupper(trim((string)($_GET['c'] ?? '')));
$mesa      = $token !== ''     ? barMesaDoToken($conn, $token)      : null;
$casa      = $mesa === null && $tokenCasa !== ''
             ? barCasamentoDoToken($conn, $tokenCasa) : null;

// Sem um nem outro não há página nenhuma: nem sabemos de que casamento se
// trata, e portanto nem com que cores pedir desculpa. Fica em português
// simples, que é tudo o que se pode fazer sem saber de quem é a festa.
if ((!$mesa && !$casa) || !podeModulo('bar')) {
    barRecado('Este código não serve',
              'Talvez a folha seja de outra festa, ou o bar ainda não esteja montado. '
              . 'Chame um garçom — ele resolve isto num instante.',
              null, [], 404);
}

$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
$pal  = paletaEfetiva($DEFS);
$tipo = cssTipografia($DEFS);
$mensagemFechada = barMensagem($conn, 'copa_fechada');

// A copa fechada, e a copa em pausa. Daqui não se passa: o menu não chega a
// ser montado, e o convidado lê o recado do casal — o dele, o que ele
// escreveu, e não uma frase da casa a dizer o mesmo de outra maneira.
//
// Isto era um aviso POR CIMA do menu, com o menu todo desenhado por baixo.
// Escolher três bebidas para depois descobrir que não se pode pedir nada é a
// promessa que esta página existe para não fazer.
if (!barAberto($conn)) {
    barRecado('A copa ainda não está a servir',
              $mensagemFechada
                ?: 'Assim que abrir, pode pedir daqui mesmo. Volte a esta página dentro '
                 . 'de pouco — ou chame um garçom, que lhe sabe dizer.',
              $pal, $tipo);
}
$pausaS = barPausaSegundos($conn);
if ($pausaS > 0) {
    barRecado('A copa está em pausa',
              (barMensagem($conn, 'copa_pausada', ['{TEMPO}' => barRelogio($pausaS)])
                 ?: 'A copa está a recuperar do movimento.')
              . ' Volta a servir dentro de:',
              $pal, $tipo, 200, $pausaS);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="<?= escP($pal['ivory']) ?>">
<title>Bar · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<?php // estilo.css entra por causa das janelas: janela.css é escrita contra os
      // seus tokens. O corpo da página é logo vestido por baixo, em bar.css. ?>
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/bar.css') ?>" rel="stylesheet">
<style>
<?= $tipo['faces'] ?>
/* As cores e as letras do convite deste casal — o menu é uma peça da festa
   deles, não da casa que a serve. */
:root{
  --c-fundo:  <?= escP($pal['ivory']) ?>;
  --c-cartao: #ffffff;
  --c-tinta:  <?= escP($pal['text']) ?>;
  --c-verde:  <?= escP($pal['forest']) ?>;
  --c-ouro:   <?= escP($pal['gold']) ?>;
  --c-creme:  <?= escP($pal['cream']) ?>;
  <?= $tipo['vars'] ?>
  --c-serif: var(--f-serif, Georgia, serif);
  --c-sans:  var(--f-sans, system-ui, sans-serif);
}
.b-festa-topo .eu{ color:var(--c-verde); }
.b-bebida .nm{ color:var(--c-verde); }
.b-nome b{ color:var(--c-verde); }
/* O botão principal, na paleta do casal: a folha da casa não chega aqui. */
.b-festa .btn{ display:inline-flex; align-items:center; justify-content:center;
               border-radius:50px; border:1px solid var(--c-verde); cursor:pointer;
               /* A tinta clara do CASAL, e não um branco inventado: quem
                  escolher um verde pálido para o convite fica com um botão
                  legível na mesma. */
               background:var(--c-verde); color:var(--c-fundo); font:inherit; font-size:var(--t-corpo);
               padding:.6rem 1.2rem; min-height:48px; }
.b-festa .btn[disabled]{ opacity:.4; cursor:default; }
.b-festa .btn-claro{ background:transparent; color:var(--c-verde); }
.b-festa .btn:focus-visible, .b-festa .b-nome:focus-visible, .b-festa .b-mesa:focus-visible,
.b-festa .b-mais button:focus-visible, .b-festa input:focus-visible{
  outline:2px solid var(--c-ouro); outline-offset:2px; }

/* O aviso de que a copa está fechada, ou de que há um limite a cumprir. */
.b-nota{ background:var(--c-creme); border:1px solid rgba(0,0,0,.08); border-radius:12px;
         padding:.85rem 1rem; margin-bottom:1rem; font-size:var(--t-denso); line-height:1.5; }
.b-nota b{ font-family:var(--c-serif); }

/* O recibo depois de pedir: o número que se diz ao garçom. */
.b-recibo{ text-align:center; padding:.5rem 0 1rem; }
.b-recibo .cod{ font-family:var(--c-serif); font-size:2.6rem; color:var(--c-ouro);
                line-height:1.1; letter-spacing:.04em; }
</style>
</head>
<body class="b-festa">

<?php // ---- A abertura -------------------------------------------------
      // Não é colante, e é de propósito: aparece uma vez, ao chegar, e depois
      // sai da frente. Esta página é a única do módulo que um CONVIDADO vê, e
      // durante muito tempo abria como um formulário — o nome do casal em
      // corpo pequeno e logo os botões. Um bar de casamento não se apresenta
      // assim: dá-se o nome da casa, e só depois o balcão. ?>
<header class="b-festa-capa">
  <div class="marca">O bar da festa</div>
  <div class="mono"><?= escP($CAS['casal']) ?></div>
  <?php // O filete: dois traços e um losango, desenhados em CSS. Um ornamento
        // que fosse imagem tinha de ser servido, e esta página abre-se com a
        // rede do salão; que fosse um carácter (❦, ◆) saía como o quadrado do
        // «não sei desenhar isto» nas fontes que não o têm. ?>
  <div class="filete" aria-hidden="true"><i></i><span></span><i></i></div>
  <div class="lema" id="b-lema">Escolha o que lhe apetece — um garçom leva à mesa.</div>
</header>

<?php // ---- A barra de trabalho ------------------------------------------
      // Esta sim é colante: quem está a rolar o menu precisa de ver sempre em
      // nome de quem está a pedir e para que mesa vai.
      //
      // Duas pastilhas e mais nada. Havia aqui, por cima delas, uma linha com o
      // nome de quem tinha entrado — e desde que a pastilha passou a dizer «A
      // pedir para Ana» (§29.5) o nome ficou escrito duas vezes, uma por baixo
      // da outra. Repetir não é sublinhar: é ocupar a linha que a barra tem
      // para dizer o que MUDA, e empurrar o menu para fora do primeiro ecrã. ?>
<div class="b-festa-topo">
  <div class="b-pastilhas">
    <?php // A mesa é uma escolha, e por isso é aqui a própria escolha: a lista
          // da casa, com a procura por dentro. Era um botão que abria uma
          // janela com uma lista lá dentro — quatro gestos para dizer um. ?>
    <div class="b-mesa b-mesa-cx" id="b-mesa" hidden></div>
    <button class="b-mesa b-para" id="b-para" type="button" onclick="barPara()" hidden></button>
  </div>
</div>

<main class="b-corpo" id="b-corpo">
  <div class="b-esq" style="height:120px;margin-bottom:.8rem"></div>
  <div class="b-esq" style="height:220px"></div>
</main>

<?php // O botão de tema da casa. Aqui não muda o menu — este veste o convite
      // do casal (§25.2) —, mas muda as JANELAS, que são cartões da casa e
      // são o que o convidado lê quando algo corre mal. ?>
<?php include __DIR__ . '/parcial-seletor-tema.php'; ?>

<div class="b-rodape" id="b-rodape" hidden>
  <span class="resumo" id="b-resumo"></span>
  <button class="btn" id="b-pedir" onclick="barEnviar()">Pedir</button>
</div>

<script>
window.BAR = {
  // O código da mesa, quando se entrou por uma. Quem entrou pelo link da
  // festa chega aqui sem mesa nenhuma, e escolhe-a na pastilha do topo —
  // que é a mesma escolha que sempre esteve lá para quem se quis mudar.
  token: <?= json_encode($token) ?>,
  casa: <?= json_encode($tokenCasa) ?>,
  mesa: <?= $mesa
            ? json_encode(['id' => (int)$mesa['id'], 'nome' => $mesa['nome']], JSON_UNESCAPED_UNICODE)
            : 'null' ?>,
  fechado: <?= json_encode($mensagemFechada) ?>
};
</script>
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/bar-pecas.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-convidado.js') ?>"></script>
</body>
</html>
