<?php
require_once __DIR__ . '/auth.php';
// A entrada não é de casamento nenhum.
//
// Enquanto houve um casal, pôr o nome dele aqui era acolhedor. Com a casa a
// servir vários, quem chega para entrar no SEU casamento era recebido pelo
// nome de outras pessoas — e o porteiro contratado por dois casais nunca sabia
// em qual estava a entrar. A entrada passou a ser da casa.
$erro = '';

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
        $papel = autenticar($_POST['utilizador'] ?? '', $_POST['senha'] ?? '');
        if ($papel) {
            unset($_SESSION['login_falhas'], $_SESSION['login_ultima']);
            // Cada um para onde lhe serve: a porta para a porta, e quem entrou
            // sem casamento aberto (o suporte, à espera de um código) para a
            // página dos casamentos, que é onde o pode arranjar.
            $destino = ['porteiro' => 'porteiro.php', 'plataforma' => 'plataforma.php'][$papel] ?? $redir;
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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= escP(PLATAFORMA['nome']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  body{ display:flex; align-items:center; justify-content:center; padding:1.25rem; }
  .login{ width:100%; max-width:400px; text-align:center; }
  .login .card{ padding:2.4rem 2rem; }
  .brasao{ width:70px; height:70px; margin:0 auto 1rem; border:2px solid var(--gold-soft); border-radius:50%;
    display:flex; align-items:center; justify-content:center; color:var(--gold); font-family:var(--serif); font-weight:700; font-size:1.3rem; }
  .casa{ font-family:var(--serif); font-size:1.75rem; color:var(--forest); line-height:1.15; margin-bottom:.3rem; }
  .evento{ font-family:var(--sans); font-size:.86rem; color:#8a8f88; margin-bottom:1.6rem; }
  .erro{ background:var(--danger-bg); color:var(--danger); border-radius:10px; padding:.6rem; font-size:.85rem; margin-bottom:1rem; }
  .dica{ font-size:.78rem; color:#9aa09a; margin-top:1.1rem; }
  /* Verificações por campo, como nos outros formulários da casa. */
  .campo{ text-align:left; margin-bottom:1rem; }
  .campo input{ transition:border-color .15s, box-shadow .15s; }
  .campo.mau input{ border-color:var(--danger); }
  .campo.mau input:focus{ box-shadow:0 0 0 3px rgba(165,71,63,.15); }
  .err{ display:none; color:var(--danger); font-size:.77rem; margin-top:.34rem; }
  .campo.mau .err{ display:block; }
  .pw-wrap{ position:relative; }
  .pw-wrap input{ padding-right:4.6rem; }
  .pw-olho{ position:absolute; right:.5rem; top:50%; transform:translateY(-50%); border:0; background:none;
            cursor:pointer; color:#9aa09a; font-size:.74rem; padding:.2rem .3rem; }
  .pw-olho:hover{ color:var(--forest); }
</style>
</head>
<body>
  <div class="login">
    <div class="card">
      <div class="brasao"><?= escP(PLATAFORMA['marca']) ?></div>
      <div class="casa"><?= escP(PLATAFORMA['nome']) ?></div>
      <div class="evento"><?= escP(PLATAFORMA['sub']) ?></div>
      <?php if ($erro): ?><div class="erro"><?= $erro ?></div><?php endif; ?>
      <form method="post" id="form-login" novalidate>
        <div class="campo">
          <label for="utilizador">Email</label>
          <input type="text" id="utilizador" name="utilizador" autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
          <div class="err"></div>
        </div>
        <div class="campo">
          <label for="senha">Palavra-passe</label>
          <div class="pw-wrap">
            <input type="password" id="senha" name="senha" autocomplete="current-password">
            <button type="button" class="pw-olho" id="olho" onclick="verSenha()" aria-label="Mostrar a palavra-passe">mostrar</button>
          </div>
          <div class="err"></div>
        </div>
        <button class="btn btn-verde" style="width:100%; justify-content:center;" type="submit">Entrar</button>
      </form>
      <div class="dica">Entre com o seu email. Depois de entrar, escolhe-se o casamento.<br>
        Ainda não tem conta? <a href="registo.php" style="color:var(--gold)">Inscreva o seu casamento</a>.</div>
    </div>
  </div>
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
  ['utilizador','senha'].forEach(id => {
    $(id).addEventListener('blur', () => marca(id, regra(id)));
    $(id).addEventListener('input', () => { if ($(id).closest('.campo').classList.contains('mau')) marca(id, regra(id)); });
  });
  $('form-login').addEventListener('submit', e => {
    let primeiro = null;
    ['utilizador','senha'].forEach(id => { if (!marca(id, regra(id)) && !primeiro) primeiro = id; });
    if (primeiro){ e.preventDefault(); $(primeiro).focus(); }
  });
  function verSenha(){
    const el = $('senha'), b = $('olho'), ver = el.type === 'password';
    el.type = ver ? 'text' : 'password';
    b.textContent = ver ? 'ocultar' : 'mostrar';
  }
</script>
</body>
</html>
