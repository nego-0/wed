<?php
// ============================================================
// bebidas.php — O menu, para quem está sentado à mesa
//
// A única porta do bar para os convidados, e abre-se por um código que está
// impresso em cima da mesa (?m=TOKEN). Não há sessão, não há login e não há
// link no convite: é o token da mesa que diz de que casamento se trata, tal
// como o código do convite faz em convite-digital.php.
//
// O token não é segredo — quem se senta à mesa lê-o. O que impede alguém de
// pedir em nome de outro é o passo seguinte: a pessoa escolhe-se numa lista
// pela procura do nome, e o telemóvel fica preso a esse nome (ver
// docs/modulo-bar.md §5). É honesto dizê-lo: isto trava o engano e o abuso
// distraído, não um impostor decidido.
//
// A página veste o convite do casal — as cores e as letras saem das mesmas
// definições. Um menu com a paleta da casa, no meio da festa deles, seria uma
// peça de outro casamento.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

$token = strtoupper(trim((string)($_GET['m'] ?? '')));
$mesa  = $token !== '' ? barMesaDoToken($conn, $token) : null;

// Sem mesa não há página nenhuma: nem sabemos de que casamento se trata, e
// portanto nem com que cores pedir desculpa. Fica em português simples.
if (!$mesa || !podeModulo('bar')) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Bar</title></head>'
       . '<body style="font:16px/1.6 system-ui,sans-serif;background:#FBF8F1;color:#20342A;margin:0">'
       . '<div style="max-width:24rem;margin:18vh auto;padding:0 1.5rem;text-align:center">'
       // Uma taça a traço, e não um emoji: numa página de erro sem folhas de
       // estilo nem ícones carregados, o emoji sai como o desenho de outro
       // sistema operativo — ou como o quadrado do «não sei desenhar isto».
       . '<svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="#B4864A" '
       . 'stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
       . '<path d="M7.6 3.2h8.8l-.5 5.1a4.4 4.4 0 0 1-7.8 0z"/><path d="M12 12.7V20"/>'
       . '<path d="M8.4 20.6h7.2"/></svg>'
       . '<h1 style="font-weight:400;font-size:1.3rem">Este código não serve</h1>'
       . '<p style="color:#6b7268">Talvez a folha seja de outra festa, ou o bar ainda não '
       . 'esteja montado. Chame um empregado — ele resolve isto num instante.</p>'
       . '</div></body></html>';
    exit;
}

$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
$pal  = paletaEfetiva($DEFS);
$tipo = cssTipografia($DEFS);
$mensagemFechada = barDef($conn, 'bar.mensagem_fechado');
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
               background:var(--c-verde); color:var(--c-fundo); font:inherit; font-size:.95rem;
               padding:.6rem 1.2rem; min-height:48px; }
.b-festa .btn[disabled]{ opacity:.4; cursor:default; }
.b-festa .btn-claro{ background:transparent; color:var(--c-verde); }
.b-festa .btn:focus-visible, .b-festa .b-nome:focus-visible, .b-festa .b-mesa:focus-visible,
.b-festa .b-mais button:focus-visible, .b-festa input:focus-visible{
  outline:2px solid var(--c-ouro); outline-offset:2px; }

/* O aviso de que a copa está fechada, ou de que há um limite a cumprir. */
.b-nota{ background:var(--c-creme); border:1px solid rgba(0,0,0,.08); border-radius:12px;
         padding:.85rem 1rem; margin-bottom:1rem; font-size:.9rem; line-height:1.5; }
.b-nota b{ font-family:var(--c-serif); }

/* O recibo depois de pedir: o número que se diz ao empregado. */
.b-recibo{ text-align:center; padding:.5rem 0 1rem; }
.b-recibo .cod{ font-family:var(--c-serif); font-size:2.6rem; color:var(--c-ouro);
                line-height:1.1; letter-spacing:.04em; }
</style>
</head>
<body class="b-festa">

<header class="b-festa-topo">
  <div class="mono"><?= escP($CAS['casal']) ?></div>
  <div class="eu" id="b-eu">Bar</div>
  <div class="b-pastilhas">
    <button class="b-mesa" id="b-mesa" type="button" onclick="barMesa()" hidden></button>
    <button class="b-mesa b-para" id="b-para" type="button" onclick="barPara()" hidden></button>
  </div>
</header>

<main class="b-corpo" id="b-corpo">
  <div class="b-esq" style="height:120px;margin-bottom:.8rem"></div>
  <div class="b-esq" style="height:220px"></div>
</main>

<div class="b-rodape" id="b-rodape" hidden>
  <span class="resumo" id="b-resumo"></span>
  <button class="btn" id="b-pedir" onclick="barEnviar()">Pedir</button>
</div>

<script>
window.BAR = {
  token: <?= json_encode($token) ?>,
  mesa: <?= json_encode(['id' => (int)$mesa['id'], 'nome' => $mesa['nome']], JSON_UNESCAPED_UNICODE) ?>,
  fechado: <?= json_encode($mensagemFechada) ?>
};
</script>
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/bar-pecas.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/bar-convidado.js') ?>"></script>
</body>
</html>
