<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
// Sem BD no ecrã de entrada: usa os nomes do config (defaults).
$CAS = casalInfo(defsPadrao());
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
        $erro = 'Nome de utilizador ou palavra-passe incorretos.'
              . ($restantes > 0 && $restantes <= 2 ? " Restam $restantes tentativa(s)." : '');
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  body{ display:flex; align-items:center; justify-content:center; padding:1.25rem; }
  .login{ width:100%; max-width:400px; text-align:center; }
  .login .card{ padding:2.4rem 2rem; }
  .brasao{ width:70px; height:70px; margin:0 auto 1rem; border:2px solid var(--gold-soft); border-radius:50%;
    display:flex; align-items:center; justify-content:center; color:var(--gold); font-family:var(--serif); font-weight:700; font-size:1.3rem; }
  .casal{ font-family:var(--script); font-size:2.4rem; color:var(--forest); line-height:1; margin-bottom:.2rem; }
  .evento{ font-family:var(--serif); font-size:.95rem; color:var(--gold); letter-spacing:2px; text-transform:uppercase; margin-bottom:1.6rem; }
  .erro{ background:var(--danger-bg); color:var(--danger); border-radius:10px; padding:.6rem; font-size:.85rem; margin-bottom:1rem; }
  .dica{ font-size:.78rem; color:#9aa09a; margin-top:1.1rem; }
</style>
</head>
<body>
  <div class="login">
    <div class="card">
      <div class="brasao"><?= escP($CAS['mono']) ?></div>
      <div class="casal"><?= escP($CAS['casal']) ?></div>
      <div class="evento">Gestão de Convidados</div>
      <?php if ($erro): ?><div class="erro"><?= $erro ?></div><?php endif; ?>
      <form method="post">
        <div style="text-align:left; margin-bottom:1rem;">
          <label for="utilizador">Nome de utilizador</label>
          <input type="text" id="utilizador" name="utilizador" autofocus required autocomplete="username" autocapitalize="none" spellcheck="false">
        </div>
        <div style="text-align:left; margin-bottom:1rem;">
          <label for="senha">Palavra-passe</label>
          <input type="password" id="senha" name="senha" required autocomplete="current-password">
        </div>
        <button class="btn btn-verde" style="width:100%; justify-content:center;" type="submit">Entrar</button>
      </form>
      <div class="dica">Administração e porta de entrada usam contas distintas.<br>
        Ainda não tem conta? <a href="registo.php" style="color:var(--gold)">Inscreva o seu casamento</a>.</div>
    </div>
  </div>
</body>
</html>
