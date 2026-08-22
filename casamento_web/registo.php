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

// Quem já entrou não tem nada a fazer aqui.
if (podeEntrar()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscrever o nosso casamento · <?= escP(PLATAFORMA['nome']) ?></title>
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
  .seccao{ font-family:var(--serif); color:var(--forest); font-size:1.05rem;
           margin:1.4rem 0 .7rem; padding-bottom:.3rem; border-bottom:1px solid var(--line); }
  .seccao span{ font-family:var(--sans); font-size:.76rem; color:#9aa09a; font-weight:400; }
  @media (max-width:520px){ .par{ grid-template-columns:1fr; } }
</style>
</head>
<body>
  <div class="reg">
    <div class="card" id="formulario">
      <div class="brasao"><?= escP(PLATAFORMA['marca']) ?></div>
      <div class="tit">Inscrever o nosso casamento</div>
      <div class="sub">Deixe aqui os dados do vosso casamento e uma conta de acesso.<br>
        A inscrição é revista por quem gere a plataforma antes de abrir.</div>

      <div class="msg mau" id="erro" style="display:none"></div>

      <div class="par">
        <div class="campo"><label for="noiva">Nome da noiva</label>
          <input type="text" id="noiva" autocomplete="off" required></div>
        <div class="campo"><label for="noivo">Nome do noivo</label>
          <input type="text" id="noivo" autocomplete="off" required></div>
      </div>
      <div class="par">
        <div class="campo"><label for="data">Data do casamento</label>
          <input type="date" id="data"></div>
        <div class="campo"><label for="hora">Hora da festa</label>
          <input type="time" id="hora" value="20:30"></div>
      </div>
      <div class="par">
        <div class="campo"><label for="local">Local da festa</label>
          <input type="text" id="local" placeholder="Ex: Estufa Municipal"></div>
        <div class="campo"><label for="cidade">Cidade / região</label>
          <input type="text" id="cidade" placeholder="Ex: Namibe · Angola"></div>
      </div>
      <div class="campo"><label for="maps">Local da festa · Google Maps</label>
        <input type="url" id="maps" data-mapa data-mapa-local="local" placeholder="https://maps.app.goo.gl/…"></div>
      <div class="par">
        <div class="campo"><label for="convidados">Convidados que esperam</label>
          <input type="number" id="convidados" min="1" max="5000" placeholder="150"></div>
        <div class="campo"><label for="whatsapp">WhatsApp de contacto</label>
          <input type="text" id="whatsapp" placeholder="244900000000" inputmode="numeric"></div>
      </div>
      <div class="campo"><label for="orcamento_total">Orçamento total <span style="font-weight:400;color:#8a8f88">· opcional</span></label>
        <input type="text" id="orcamento_total" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00">
        <div class="nota">Serve de teto para acompanhar as despesas. Fica para preencher depois, se preferir.</div></div>

      <div class="seccao">As cerimónias <span>opcional — deixe em branco o que não se aplicar</span></div>
      <div class="par">
        <div class="campo"><label for="civil_hora">Civil · hora</label>
          <input type="time" id="civil_hora"></div>
        <div class="campo"><label for="civil_local">Civil · local</label>
          <input type="text" id="civil_local" placeholder="Ex: Conservatória"></div>
      </div>
      <div class="campo"><label for="civil_maps">Civil · Google Maps</label>
        <input type="url" id="civil_maps" data-mapa data-mapa-local="civil_local" placeholder="https://maps.app.goo.gl/…"></div>
      <div class="par">
        <div class="campo"><label for="religiosa_hora">Religiosa · hora</label>
          <input type="time" id="religiosa_hora"></div>
        <div class="campo"><label for="religiosa_local">Religiosa · local</label>
          <input type="text" id="religiosa_local" placeholder="Ex: Igreja de São José"></div>
      </div>
      <div class="campo"><label for="religiosa_maps">Religiosa · Google Maps</label>
        <input type="url" id="religiosa_maps" data-mapa data-mapa-local="religiosa_local" placeholder="https://maps.app.goo.gl/…"></div>

      <div class="seccao">A vossa conta</div>
      <div class="campo"><label for="email">Email de acesso</label>
        <input type="email" id="email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
        <div class="nota">É por aqui que entram depois de a inscrição ser aprovada.</div></div>
      <div class="campo"><label for="senha">Palavra-passe</label>
        <input type="password" id="senha" autocomplete="new-password" required>
        <div class="nota">Pelo menos 8 caracteres.</div></div>

      <div class="seccao">Período de licença de uso</div>
      <div class="campo"><label for="licenca">Quanto tempo pretendem usar a plataforma</label>
        <select id="licenca" onchange="licencaMudou()">
          <option value="3">3 meses</option>
          <option value="6" selected>6 meses</option>
          <option value="12">12 meses</option>
          <option value="outro">Outro…</option>
        </select>
        <div class="nota">O tempo começa a contar quando a inscrição for aprovada.</div></div>
      <div class="campo" id="licenca-outro" style="display:none">
        <label for="licenca_meses_custom">Meses</label>
        <input type="number" id="licenca_meses_custom" min="1" max="120" placeholder="ex.: 18"></div>

      <div class="seccao">Conta do porteiro <span>opcional — quem regista as entradas à porta</span></div>
      <div class="campo"><label for="porteiro_email">Email (utilizador) do porteiro</label>
        <input type="email" id="porteiro_email" autocapitalize="none" spellcheck="false" placeholder="porta-…">
        <div class="nota">Sugerimos um utilizador; podem trocá-lo. Fica pronto quando a inscrição for aprovada.</div></div>
      <div class="campo"><label for="porteiro_senha">Palavra-passe do porteiro</label>
        <input type="text" id="porteiro_senha" autocomplete="off" spellcheck="false">
        <div class="nota">Pelo menos 8 caracteres. Só se preenchida é que a conta do porteiro é criada.</div></div>

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

<script src="<?= asset('assets/maps-campo.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
if (window.Moeda) window.Moeda.ligar('.campo-moeda');

// O período de licença: presets, ou um número livre em «Outro…».
function licencaMudou(){
  $('licenca-outro').style.display = $('licenca').value === 'outro' ? '' : 'none';
}
function licencaMeses(){
  const v = $('licenca').value;
  return v === 'outro' ? Math.max(0, parseInt($('licenca_meses_custom').value || '0', 10)) : parseInt(v, 10);
}

// Uma sugestão de utilizador único para o porteiro, a partir dos nomes: fácil de
// dizer e improvável de colidir. Só se preenche enquanto ninguém lhe tocar.
function slug(s){
  return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
let PORT_TOCADO = false;
$('porteiro_email').addEventListener('input', () => { PORT_TOCADO = true; });
function sugerirPorteiro(){
  if (PORT_TOCADO) return;
  const base = [slug($('noiva').value), slug($('noivo').value)].filter(Boolean).join('-') || 'casamento';
  const suf = Math.random().toString(36).slice(2, 5);
  const host = (location.hostname || 'convite.local').replace(/^www\./, '');
  $('porteiro_email').value = `porta-${base}-${suf}@${host}`;
  if (!$('porteiro_senha').value) $('porteiro_senha').value = 'porta' + Math.random().toString(36).slice(2, 10);
}
$('noiva').addEventListener('input', sugerirPorteiro);
$('noivo').addEventListener('input', sugerirPorteiro);

async function enviar(){
  // Tudo o que a página pergunta vai no mesmo pedido: o casamento nasce com os
  // seus dados, e não com os do casal de origem do config.php à espera de que
  // alguém se lembre de os trocar.
  const campo = id => ($(id).value || '').trim();
  const dados = {
    noiva: campo('noiva'), noivo: campo('noivo'), data: campo('data'),
    email: campo('email'), senha: $('senha').value,
    hora: campo('hora'), local: campo('local'), cidade: campo('cidade'),
    maps: campo('maps'),
    convidados: campo('convidados'), whatsapp: campo('whatsapp'),
    orcamento_total: campo('orcamento_total'),
    licenca_meses: licencaMeses(),
    porteiro_email: campo('porteiro_email'), porteiro_senha: $('porteiro_senha').value,
    civil_hora: campo('civil_hora'), civil_local: campo('civil_local'), civil_maps: campo('civil_maps'),
    religiosa_hora: campo('religiosa_hora'), religiosa_local: campo('religiosa_local'), religiosa_maps: campo('religiosa_maps'),
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
