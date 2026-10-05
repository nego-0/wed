<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-marca.php';
// A entrada não é de casamento nenhum.
//
// Enquanto houve um casal, pôr o nome dele aqui era acolhedor. Com a casa a
// servir vários, quem chega para entrar no SEU casamento era recebido pelo
// nome de outras pessoas — e o porteiro contratado por dois casais nunca sabia
// em qual estava a entrar. A entrada passou a ser da casa.
$erro = '';
$utilizador = trim((string)($_POST['utilizador'] ?? ''));

/**
 * Destino após entrar. Só se aceita um caminho interno simples — caso
 * contrário `?r=https://exemplo.com` levaria o utilizador para fora do site
 * depois de autenticar (redirecionamento aberto).
 */
$redir = (string)($_GET['r'] ?? 'index.php');
$redir = ltrim(parse_url($redir, PHP_URL_PATH) ?? '', '/');   // descarta esquema, domínio e query
if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $redir) || str_contains($redir, 'login')) {
    $redir = 'index.php';
}

// Trava simples contra tentativas repetidas (força bruta), por sessão.
const LOGIN_MAX = 5;            // tentativas antes de esperar
const LOGIN_ESPERA = 60;        // segundos de espera
$falhas  = (int)($_SESSION['login_falhas'] ?? 0);
$ultima  = (int)($_SESSION['login_ultima'] ?? 0);
$restam  = ($falhas >= LOGIN_MAX) ? (LOGIN_ESPERA - (time() - $ultima)) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($restam > 0) {
        $erro = "Demasiadas tentativas. Aguarde $restam segundo(s) e tente de novo.";
    } else {
        if ($falhas >= LOGIN_MAX) { $falhas = 0; $_SESSION['login_falhas'] = 0; }   // espera cumprida
        $papel = autenticar($utilizador, $_POST['senha'] ?? '');
        if ($papel) {
            unset($_SESSION['login_falhas'], $_SESSION['login_ultima']);
            // Cada um para onde lhe serve: a porta para a porta, e quem entrou
            // sem casamento aberto (o suporte, à espera de um código) para a
            // página dos casamentos, que é onde o pode arranjar.
            $destino = ['porteiro' => 'porteiro.php', 'copeiro' => 'copa.php',
                        'entregador' => 'entregas.php',
                        'plataforma' => 'plataforma.php'][$papel] ?? $redir;
            // Um casal cuja licença ainda não abriu nada vai direito à montra.
            // Mandá-lo ao painel para o painel o mandar de volta era fazê-lo
            // bater com o nariz numa porta antes de lhe dizermos onde é a que
            // se abre — e a licença é, nesse momento, a única coisa que ele tem
            // para fazer aqui.
            if ($papel === 'admin' && function_exists('licencaPorAbrir')
                && licencaPorAbrir($conn) && !ehPessoalPlataforma()) {
                $destino = 'licenca.php';
            }
            header('Location: ' . $destino);
            exit;
        }
        $_SESSION['login_falhas'] = $falhas + 1;
        $_SESSION['login_ultima'] = time();
        $restantes = LOGIN_MAX - ($falhas + 1);
        $erro = 'Email ou palavra-passe incorretos.'
              . ($restantes > 0 && $restantes <= 2 ? " Restam $restantes tentativa(s)." : '');
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<?php include __DIR__ . '/parcial-icone.php'; ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= escP(PLATAFORMA['nome']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/atendimento.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/entrada.css') ?>" rel="stylesheet">
<?php include __DIR__ . '/parcial-tema.php'; ?>
<style>
  body{ display:flex; align-items:center; justify-content:center; padding:1.25rem; }
  .login{ width:100%; max-width:400px; text-align:center; }
  .login .card{ padding:2.25rem 2rem; }
  .brasao{ width:82px; height:82px; margin:0 auto .8rem; display:block; object-fit:contain; }
  .casa{ font-family:var(--serif); font-size:var(--t-display); color:var(--ink); line-height:1.15; margin-bottom:.3rem; }
  .login-titulo{ font:400 clamp(1.75rem,3vw,2.15rem)/1.15 var(--serif); color:var(--ink); margin:.85rem 0 .5rem; }
  .evento{ font-family:var(--sans); font-size:var(--t-denso); line-height:1.55; color:var(--ink-fraco); margin:0 auto 1.6rem; max-width:34ch; }
  .erro{ background:var(--danger-bg); color:var(--danger); border:1px solid color-mix(in srgb,var(--danger) 28%,transparent); border-radius:10px; padding:.7rem .8rem; font-size:var(--t-denso); line-height:1.45; margin-bottom:1rem; text-align:left; }
  .dica{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-top:1.1rem; }
  /* Verificações por campo, como nos outros formulários da casa. */
  .campo{ text-align:left; margin-bottom:1rem; }
  .campo input{ transition:border-color .15s, box-shadow .15s; }
  .campo.mau input{ border-color:var(--danger); }
  .campo.mau input:focus{ box-shadow:0 0 0 3px rgba(165,71,63,.15); }
  .err{ display:none; color:var(--danger); font-size:var(--t-apoio); margin-top:.34rem; }
  .campo.mau .err{ display:block; }
  .pw-wrap{ position:relative; }
  .pw-wrap input{ padding-right:6.3rem; }
  .pw-olho{ position:absolute; right:.28rem; top:50%; transform:translateY(-50%); border:0; background:none;
            min-height:40px; min-width:88px; display:inline-flex; align-items:center; justify-content:center; gap:.35rem;
            cursor:pointer; color:var(--ink-fraco); font:600 var(--t-apoio)/1 var(--sans); padding:.35rem .45rem; border-radius:8px; }
  .pw-olho-ico{ display:inline-flex; }
  .pw-olho-ico svg{ width:17px; height:17px; }
  .pw-olho:hover{ color:var(--gold-texto); }
  .login-apoio{ display:flex; justify-content:flex-end; margin:-.25rem 0 1.05rem; }
  .login-ajuda{ border:0; padding:.25rem 0; background:none; color:var(--gold-texto); cursor:pointer;
                font:500 var(--t-apoio)/1.4 var(--sans); text-decoration:underline; text-decoration-color:transparent;
                text-underline-offset:3px; }
  .login-ajuda:hover{ text-decoration-color:currentColor; }
  .login-submit{ min-height:48px; position:relative; }
  .login-submit[aria-busy="true"]{ cursor:wait; opacity:.82; }
  .login-submit[aria-busy="true"]::after{ content:""; width:14px; height:14px; border:2px solid currentColor;
      border-right-color:transparent; border-radius:50%; animation:login-roda .65s linear infinite; }
  .sr-only{ position:absolute!important; width:1px!important; height:1px!important; padding:0!important; margin:-1px!important;
            overflow:hidden!important; clip:rect(0,0,0,0)!important; white-space:nowrap!important; border:0!important; }
  @media(max-width:520px){
    .login .marca-kulemba.grande .marca-oficial{ width:220px; }
    .login-titulo{ margin:.6rem 0 .35rem; }
    .login .evento{ margin-bottom:1rem; }
    .login .campo{ margin-bottom:.75rem; }
    .login-apoio{ margin:-.1rem 0 .7rem; }
  }
  @keyframes login-roda{ to{ transform:rotate(360deg); } }
  @media(prefers-reduced-motion:reduce){ .login-submit[aria-busy="true"]::after{ animation:none; } }
</style>
</head>
<body class="entrada-publica login-publica">
<main class="entrada-shell">
  <section class="entrada-visual" aria-label="Organização elegante do casamento">
    <img src="<?= asset('assets/marketing/login-kulemba.webp') ?>" alt="Um casal prepara convites e a organização do casamento" fetchpriority="high">
    <div class="entrada-orbita um"><span data-ico="carta"></span>Convites personalizados</div>
    <div class="entrada-orbita dois"><span data-ico="mesa"></span>Mesas organizadas</div>
    <div class="entrada-orbita tres"><span data-ico="pessoas"></span>Convidados confirmados</div>
    <div class="entrada-mensagem"><b>O vosso dia, organizado com serenidade.</b><span>Convites, convidados, mesas, orçamento e equipas a trabalhar no mesmo lugar.</span></div>
  </section>
  <div class="login">
    <a class="entrada-voltar" href="atendimento.php">← Conhecer a plataforma</a>
    <div class="card">
      <?php marcaKulemba('grande'); ?>
      <h1 class="login-titulo">Entrar na Kulemba</h1>
      <p class="evento">Aceda aos convites, convidados, mesas e restantes detalhes do vosso casamento.</p>
      <?php if ($erro): ?><div class="erro" id="login-erro" role="alert" aria-live="assertive" tabindex="-1"><?= escP($erro) ?></div><?php endif; ?>
      <form method="post" id="form-login" novalidate>
        <div class="campo">
          <label for="utilizador">Email</label>
          <input type="email" id="utilizador" name="utilizador" value="<?= escP($utilizador) ?>"
                 autofocus autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off"
                 spellcheck="false" enterkeyhint="next" aria-describedby="utilizador-erro">
          <div class="err" id="utilizador-erro"></div>
        </div>
        <div class="campo">
          <label for="senha">Palavra-passe</label>
          <div class="pw-wrap">
            <input type="password" id="senha" name="senha" autocomplete="current-password" enterkeyhint="go"
                   aria-describedby="senha-erro">
            <button type="button" class="pw-olho" id="olho" onclick="verSenha()" aria-label="Mostrar a palavra-passe">
              <span class="pw-olho-ico" data-ico="olho" aria-hidden="true"></span><span id="olho-texto">Mostrar</span>
            </button>
          </div>
          <div class="err" id="senha-erro"></div>
        </div>
        <div class="login-apoio"><button type="button" class="login-ajuda" onclick="abrirAjudaLogin()">Precisa de ajuda para entrar?</button></div>
        <button class="btn btn-verde login-submit" id="login-submit" style="width:100%; justify-content:center;" type="submit"><span>Entrar</span></button>
        <div id="login-estado" class="sr-only" role="status" aria-live="polite"></div>
      </form>
      <div class="dica">Ainda não têm conta? <a href="registo.php" style="color:var(--gold-texto)">Inscrevam o vosso casamento</a>.</div>
    </div>
  </div>
</main>
<script>
  const $ = id => document.getElementById(id);
  // A verificação que falta antes de ir ao servidor: assinala, por campo e em
  // português, o que ficou por preencher — em vez do aviso cru do navegador.
  function marca(id, erro){
    const c = $(id).closest('.campo'); if (!c) return !erro;
    const box = c.querySelector('.err');
    if (erro){ c.classList.add('mau'); if (box) box.textContent = erro; $(id).setAttribute('aria-invalid','true'); }
    else { c.classList.remove('mau'); $(id).removeAttribute('aria-invalid'); }
    return !erro;
  }
  function regra(id){
    const v = ($(id).value || '').trim();
    if (id === 'utilizador') return v ? '' : 'Escreva o seu email.';
    if (id === 'senha')      return $(id).value ? '' : 'Escreva a sua palavra-passe.';
    return '';
  }
  // Um campo em que ainda não se escreveu nada não se marca de errado ao perder
  // o foco. O primeiro campo tem autofocus: bastava carregar noutro sítio da
  // página — no atendimento, por exemplo — para o formulário ficar a vermelho
  // sem que ninguém tenha errado coisa nenhuma. Marca-se ao sair de um campo em
  // que se mexeu, e ao submeter.
  const TOCADO = {};
  ['utilizador','senha'].forEach(id => {
    $(id).addEventListener('input', () => {
      TOCADO[id] = true;
      if ($(id).closest('.campo').classList.contains('mau')) marca(id, regra(id));
    });
    $(id).addEventListener('blur', () => { if (TOCADO[id]) marca(id, regra(id)); });
  });
  $('form-login').addEventListener('submit', e => {
    let primeiro = null;
    ['utilizador','senha'].forEach(id => { if (!marca(id, regra(id)) && !primeiro) primeiro = id; });
    if (primeiro){ e.preventDefault(); $(primeiro).focus(); return; }
    const b = $('login-submit');
    b.setAttribute('aria-busy','true'); b.disabled = true;
    b.querySelector('span').textContent = 'A entrar';
    $('login-estado').textContent = 'A verificar os dados de acesso.';
  });
  function verSenha(){
    const el = $('senha'), b = $('olho'), t = $('olho-texto'), ver = el.type === 'password';
    el.type = ver ? 'text' : 'password';
    t.textContent = ver ? 'Ocultar' : 'Mostrar';
    b.setAttribute('aria-label', (ver ? 'Ocultar' : 'Mostrar') + ' a palavra-passe');
    b.querySelector('[data-ico]').dataset.ico = ver ? 'olhoFechado' : 'olho';
  }
  function abrirAjudaLogin(){
    const b = document.getElementById('at-botao');
    if (b){ b.click(); return; }
    location.href = 'atendimento.php#perguntas';
  }
  <?php if ($erro): ?>document.addEventListener('DOMContentLoaded',()=>$('login-erro').focus());<?php endif; ?>
</script>
<?php include __DIR__ . "/parcial-seletor-tema.php"; ?>
<script src="<?= asset('assets/icones.js') ?>"></script>
<script src="<?= asset('assets/atendimento.js') ?>"></script>
</body>
</html>
