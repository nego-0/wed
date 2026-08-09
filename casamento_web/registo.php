<?php
// ============================================================
// registo.php — Um casal inscreve-se
//
// A inscrição não abre a porta: cria a conta e o casamento em 'pendente' e
// põe-nos na fila de aprovação. Quem aprova é o admin da plataforma, na
// página dos casamentos. Enquanto não aprovar, a conta não entra.
//
// É de propósito que a página não promete correio nenhum: não há envio
// configurado, e prometer um email que nunca chega é pior do que dizer ao
// casal, aqui e agora, o que vai acontecer a seguir.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

// Quem já entrou não tem nada a fazer aqui.
if (podeEntrar()) { header('Location: index.php'); exit; }
$CAS = casalInfo(defsPadrao());
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscrever o nosso casamento</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  body{ display:flex; align-items:center; justify-content:center; padding:1.25rem; }
  .reg{ width:100%; max-width:520px; }
  .reg .card{ padding:2.2rem 2rem; }
  .brasao{ width:64px; height:64px; margin:0 auto .9rem; border:2px solid var(--gold-soft); border-radius:50%;
    display:flex; align-items:center; justify-content:center; color:var(--gold);
    font-family:var(--serif); font-weight:700; font-size:1.2rem; }
  .tit{ font-family:var(--serif); font-size:1.6rem; color:var(--forest); text-align:center; line-height:1.2; }
  .sub{ text-align:center; color:#8a8f88; font-size:.88rem; margin:.4rem 0 1.5rem; line-height:1.5; }
  .par{ display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
  .campo{ margin-bottom:.9rem; text-align:left; }
  .nota{ font-size:.78rem; color:#9aa09a; margin-top:.3rem; }
  .msg{ border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1rem; line-height:1.5; }
  .msg.mau{ background:var(--danger-bg); color:var(--danger); }
  .feito{ text-align:center; }
  .feito .ico{ font-size:2.4rem; color:var(--gold); }
  @media (max-width:520px){ .par{ grid-template-columns:1fr; } }
</style>
</head>
<body>
  <div class="reg">
    <div class="card" id="formulario">
      <div class="brasao">&#10047;</div>
      <div class="tit">Inscrever o nosso casamento</div>
      <div class="sub">Deixe aqui os vossos nomes e uma conta de acesso.<br>
        A inscrição é revista por quem gere a plataforma antes de abrir.</div>

      <div class="msg mau" id="erro" style="display:none"></div>

      <div class="par">
        <div class="campo"><label for="noiva">Nome da noiva</label>
          <input type="text" id="noiva" autocomplete="off" required></div>
        <div class="campo"><label for="noivo">Nome do noivo</label>
          <input type="text" id="noivo" autocomplete="off" required></div>
      </div>
      <div class="campo"><label for="data">Data do casamento <span style="font-weight:400;color:#9aa09a">(se já a souberem)</span></label>
        <input type="date" id="data"></div>
      <div class="campo"><label for="email">Email de acesso</label>
        <input type="email" id="email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
        <div class="nota">É por aqui que entram depois de a inscrição ser aprovada.</div></div>
      <div class="campo"><label for="senha">Palavra-passe</label>
        <input type="password" id="senha" autocomplete="new-password" required>
        <div class="nota">Pelo menos 8 caracteres.</div></div>

      <button class="btn btn-verde" style="width:100%;justify-content:center" id="btn" onclick="enviar()">Inscrever</button>
      <div class="nota" style="text-align:center;margin-top:1rem">
        Já têm conta? <a href="login.php" style="color:var(--gold)">Entrar</a>
      </div>
    </div>

    <div class="card feito" id="obrigado" style="display:none">
      <div class="ico">&#10003;</div>
      <div class="tit">Inscrição enviada</div>
      <p style="color:#6c7570;line-height:1.6">
        Ficou na fila de aprovação. Assim que quem gere a plataforma a aprovar,
        podem entrar com o email e a palavra-passe que acabaram de escolher.
      </p>
      <p class="nota">Até lá, a entrada recusa o acesso — não é engano.</p>
      <a class="btn" href="login.php">Ir para a entrada</a>
    </div>
  </div>

<script>
const $ = id => document.getElementById(id);
async function enviar(){
  const dados = {
    noiva: $('noiva').value.trim(), noivo: $('noivo').value.trim(),
    data:  $('data').value, email: $('email').value.trim(), senha: $('senha').value,
  };
  $('erro').style.display = 'none';
  $('btn').disabled = true;
  try {
    const r = await fetch('api.php?action=registo_publico', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(dados) });
    const d = await r.json();
    if (d && d.success) {
      $('formulario').style.display = 'none';
      $('obrigado').style.display = '';
      return;
    }
    $('erro').textContent = (d && d.message) || 'Não foi possível inscrever.';
    $('erro').style.display = '';
  } catch (e) {
    $('erro').textContent = 'Não foi possível falar com o servidor.';
    $('erro').style.display = '';
  }
  $('btn').disabled = false;
}
document.addEventListener('keydown', e => { if (e.key === 'Enter') enviar(); });
</script>
</body>
</html>
