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
require_once __DIR__ . '/parcial-marca.php';

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
<link href="<?= asset('assets/planos.css') ?>" rel="stylesheet">
<?php include __DIR__ . '/parcial-tema.php'; ?>
<style>
  body{ display:flex; align-items:flex-start; justify-content:center; padding:1.5rem 1.25rem; }
  .reg{ width:100%; max-width:1040px; }
  /* Duas larguras na mesma página: os dados do casal são uma conversa a dois e
     ficam estreitos; a montra dos planos precisa de espaço para se ver. */
  .reg .card{ padding:2.3rem 2.1rem; max-width:560px; margin-left:auto; margin-right:auto; }
  .reg-planos{ margin-top:2.2rem; }
  .reg-passo{ display:inline-flex; align-items:center; gap:.45rem; font-size:.72rem;
    letter-spacing:.1em; text-transform:uppercase; color:var(--gold);
    background:var(--gold-pale); border-radius:50px; padding:.3rem .85rem; }
  .reg-enviar{ max-width:560px; margin:1.4rem auto 0; }
  .brasao{ width:64px; height:64px; margin:0 auto .9rem; border:2px solid var(--gold-soft); border-radius:50%;
    display:flex; align-items:center; justify-content:center; color:var(--gold);
    font-family:var(--serif); font-weight:700; font-size:1.2rem; }
  .tit{ font-family:var(--serif); font-size:1.65rem; color:var(--ink); text-align:center; line-height:1.2; }
  .sub{ text-align:center; color:#8a8f88; font-size:.9rem; margin:.4rem auto 1.6rem; line-height:1.55; max-width:42ch; }

  .par{ display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
  .campo{ margin-bottom:1rem; text-align:left; }
  .campo label{ display:flex; align-items:baseline; gap:.35rem; }
  .req{ color:var(--danger); font-weight:700; }
  .nota{ font-size:.76rem; color:#9aa09a; margin-top:.32rem; line-height:1.5; }

  /* Estado dos campos: erro a vermelho, válido com um visto discreto. */
  .campo input, .campo select{ transition:border-color .15s, box-shadow .15s; }
  .campo.mau input, .campo.mau select{ border-color:var(--danger); }
  .campo.mau input:focus, .campo.mau select:focus{ box-shadow:0 0 0 3px rgba(165,71,63,.15); }
  .campo.ok input:not(:focus), .campo.ok select:not(:focus){ border-color:#bcd6c4; }
  .err{ display:none; color:var(--danger); font-size:.77rem; margin-top:.34rem; line-height:1.45; }
  .campo.mau .err{ display:block; }
  .campo.mau .nota{ display:none; }         /* o erro fala mais alto que a dica */
  .aviso-campo{ display:none; color:var(--warn); font-size:.77rem; margin-top:.34rem; }
  .campo.avisar .aviso-campo{ display:block; }

  /* Palavra-passe: olho para mostrar, e a força ao lado. */
  .pw-wrap{ position:relative; }
  /* Espaço à direita para o botão «mostrar»/«ocultar» — sem isto, o texto que
     se escreve passa por baixo do botão e fica ilegível. A folga acompanha a
     largura da palavra mais longa. */
  .pw-wrap input{ padding-right:4.6rem; }
  .pw-olho{ position:absolute; right:.5rem; top:50%; transform:translateY(-50%); border:0; background:none;
            cursor:pointer; color:#9aa09a; font-size:.74rem; padding:.2rem .3rem; }
  .pw-olho:hover{ color:var(--ink); }
  .pw-forca{ display:flex; align-items:center; gap:.5rem; margin-top:.4rem; }
  .pw-barras{ display:flex; gap:3px; flex:1; }
  .pw-barras i{ height:4px; flex:1; border-radius:50px; background:var(--sand); transition:background .2s; }
  .pw-forca.f1 i:nth-child(1){ background:var(--danger); }
  .pw-forca.f2 i:nth-child(-n+2){ background:var(--warn); }
  .pw-forca.f3 i:nth-child(-n+3){ background:#9a9a3c; }
  .pw-forca.f4 i{ background:var(--ok); }
  .pw-rot{ font-size:.72rem; color:#9aa09a; white-space:nowrap; min-width:4.5rem; text-align:right; }

  /* Secções e blocos opcionais que dobram. */
  .seccao{ font-family:var(--serif); color:var(--ink); font-size:1.08rem;
           margin:1.6rem 0 .8rem; padding-bottom:.35rem; border-bottom:1px solid var(--line);
           display:flex; align-items:baseline; gap:.5rem; }
  .seccao .op{ font-family:var(--sans); font-size:.74rem; color:#9aa09a; font-weight:400; }
  details.bloco{ border:1px solid var(--line); border-radius:12px; margin:1rem 0; background:var(--card); }
  details.bloco > summary{ list-style:none; cursor:pointer; padding:.85rem 1rem; display:flex;
      align-items:center; gap:.6rem; font-family:var(--serif); color:var(--ink); font-size:1rem; }
  details.bloco > summary::-webkit-details-marker{ display:none; }
  details.bloco > summary .op{ font-family:var(--sans); font-size:.74rem; color:#9aa09a; font-weight:400; margin-left:auto; }
  details.bloco > summary .chev{ transition:transform .2s; color:var(--gold-soft); }
  details.bloco[open] > summary .chev{ transform:rotate(90deg); }
  details.bloco > .bloco-corpo{ padding:0 1rem 1rem; }

  .msg{ border-radius:10px; padding:.75rem .95rem; font-size:.86rem; margin-bottom:1.1rem; line-height:1.5; }
  .msg.mau{ background:var(--danger-bg); color:var(--danger); border:1px solid rgba(165,71,63,.25); }
  .btn-enviar{ width:100%; justify-content:center; margin-top:.4rem; }
  .btn-enviar[disabled]{ opacity:.6; cursor:progress; }
  .entrar-nota{ text-align:center; margin-top:1.1rem; font-size:.84rem; color:#8a8f88; }

  .feito{ text-align:center; }
  .feito .ico{ width:60px; height:60px; margin:0 auto 1rem; border-radius:50%; background:var(--ok-bg);
               color:var(--ok); display:flex; align-items:center; justify-content:center; font-size:1.9rem; }
  .feito .cx{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
              padding:.8rem 1rem; margin:1rem 0; font-size:.88rem; color:#6c7570; line-height:1.6; }
  @media (max-width:520px){ .par{ grid-template-columns:1fr; } .reg .card{ padding:1.8rem 1.3rem; } }
</style>
</head>
<body>
  <div class="reg">
    <form class="card" id="formulario" novalidate autocomplete="on" onsubmit="return false">
      <?php marcaNiras('grande so-niras'); ?>
      <div class="brasao so-classico"><?= escP(PLATAFORMA['marca']) ?></div>
      <div class="tit">Inscrever o nosso casamento</div>
      <div class="sub">Deixem os dados do vosso casamento e uma conta de acesso.
        A inscrição é revista por quem gere a plataforma antes de abrir.</div>

      <div class="msg mau" id="erro" style="display:none" role="alert"></div>

      <div class="seccao">O casal <span class="op">os campos com <span class="req">*</span> são obrigatórios</span></div>
      <div class="par">
        <div class="campo"><label for="noiva">Nome da noiva <span class="req">*</span></label>
          <input type="text" id="noiva" autocomplete="off" required aria-required="true">
          <div class="err"></div></div>
        <div class="campo"><label for="noivo">Nome do noivo <span class="req">*</span></label>
          <input type="text" id="noivo" autocomplete="off" required aria-required="true">
          <div class="err"></div></div>
      </div>

      <div class="seccao">A vossa conta</div>
      <div class="campo"><label for="email">Email de acesso <span class="req">*</span></label>
        <input type="email" id="email" autocomplete="email" autocapitalize="none" spellcheck="false" inputmode="email" required aria-required="true">
        <div class="err"></div>
        <div class="nota">É por aqui que entram depois de a inscrição ser aprovada.</div></div>
      <div class="par">
        <div class="campo"><label for="senha">Palavra-passe <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" id="senha" autocomplete="new-password" required aria-required="true">
            <button type="button" class="pw-olho" id="olho1" onclick="verSenha('senha','olho1')" aria-label="Mostrar a palavra-passe">mostrar</button>
          </div>
          <div class="pw-forca" id="pw-forca" aria-hidden="true"><span class="pw-barras"><i></i><i></i><i></i><i></i></span><span class="pw-rot" id="pw-rot"></span></div>
          <div class="err"></div>
          <div class="nota">Pelo menos 8 caracteres.</div></div>
        <div class="campo"><label for="confirmar">Repetir a palavra-passe <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" id="confirmar" autocomplete="new-password" required aria-required="true">
            <button type="button" class="pw-olho" id="olho2" onclick="verSenha('confirmar','olho2')" aria-label="Mostrar a palavra-passe">mostrar</button>
          </div>
          <div class="err"></div></div>
      </div>

      <div class="seccao">O casamento <span class="op">tudo opcional — dá para completar depois</span></div>
      <div class="par">
        <div class="campo"><label for="data">Data do casamento</label>
          <input type="date" id="data">
          <div class="aviso-campo"></div></div>
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
          <input type="number" id="convidados" min="1" max="5000" placeholder="150">
          <div class="err"></div></div>
        <div class="campo"><label for="whatsapp">WhatsApp de contacto</label>
          <input type="tel" id="whatsapp" placeholder="244900000000" inputmode="tel">
          <div class="err"></div></div>
      </div>
      <div class="campo"><label for="orcamento_total">Orçamento total</label>
        <input type="text" id="orcamento_total" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00">
        <div class="nota">Serve de teto para acompanhar as despesas. Fica para preencher depois, se preferir.</div></div>

      <details class="bloco">
        <summary><span class="chev">›</span>As cerimónias<span class="op">civil e religiosa</span></summary>
        <div class="bloco-corpo">
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
          <div class="campo" style="margin-bottom:.2rem"><label for="religiosa_maps">Religiosa · Google Maps</label>
            <input type="url" id="religiosa_maps" data-mapa data-mapa-local="religiosa_local" placeholder="https://maps.app.goo.gl/…"></div>
        </div>
      </details>

      <details class="bloco">
        <summary><span class="chev">›</span>Conta do porteiro<span class="op">opcional</span></summary>
        <div class="bloco-corpo">
          <div class="nota" style="margin:0 0 .8rem">Quem regista as entradas à porta. Se a criarem, precisa das duas coisas —
            email (utilizador) e palavra-passe. Fica pronta quando a inscrição for aprovada.</div>
          <div class="campo"><label for="porteiro_email">Email (utilizador) do porteiro</label>
            <input type="email" id="porteiro_email" autocapitalize="none" spellcheck="false" inputmode="email" placeholder="porta-…">
            <div class="err"></div></div>
          <div class="campo" style="margin-bottom:.2rem"><label for="porteiro_senha">Palavra-passe do porteiro</label>
            <div class="pw-wrap">
              <input type="text" id="porteiro_senha" autocomplete="off" spellcheck="false">
              <button type="button" class="pw-olho" id="olho3" onclick="sugerirPorteiroBtn()" aria-label="Sugerir">sugerir</button>
            </div>
            <div class="err"></div></div>
        </div>
      </details>

    </form>

    <?php // ---- a montra: o que o casal vai levar ---- ?>
    <section class="reg-planos" id="planos-sec">
      <div class="pl-topo">
        <span class="reg-passo">Passo 2 de 2</span>
        <h2 style="margin-top:.7rem">Escolham o que querem levar</h2>
        <p>Um pacote pronto, ou um plano à vossa medida. Podem reforçá-lo mais tarde
           sem perder nada do que já tiverem feito.</p>
      </div>

      <div id="reg-planos"></div>

      <div class="pl-aceite" id="reg-aceite-cx">
        <input type="checkbox" id="reg-aceite">
        <label for="reg-aceite">
          Li e aceito as <a id="reg-ver-pol">políticas de utilização e protecção de dados</a>.
          Comprometo-me a tratar os dados dos nossos convidados nos termos da
          Lei n.º 22/11, de 17 de Junho, informando-os da recolha e recolhendo apenas
          o necessário ao casamento.
        </label>
      </div>

      <div class="reg-enviar">
        <button class="btn btn-verde btn-enviar" id="btn" type="button" onclick="enviar()">Inscrever o nosso casamento</button>
        <div class="entrar-nota">Já têm conta? <a href="login.php" style="color:var(--gold)">Entrar</a></div>
      </div>
    </section>

    <div class="card feito" id="obrigado" style="display:none">
      <div class="ico">&#10003;</div>
      <div class="tit">Está feito — podem entrar já</div>
      <p style="color:#6c7570;line-height:1.6;margin-top:.6rem">
        A vossa conta está aberta. Entrem com o email e a palavra-passe que acabaram
        de escolher: encontram lá o pedido de licença, e podem alterá-lo enquanto
        a administração não o decidir.
      </p>
      <div class="cx" id="feito-email"></div>
      <p class="nota">Os módulos que pediram abrem assim que a licença for concedida.</p>
      <a class="btn btn-verde" style="width:100%;justify-content:center" href="login.php">Entrar agora</a>
    </div>
  </div>

<script src="<?= asset('assets/planos.js') ?>"></script>
<script src="<?= asset('assets/maps-campo.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
if (window.Moeda) window.Moeda.ligar('.campo-moeda');
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const val = id => ($(id).value || '').trim();

// ---------- estado de um campo (erro / válido) ----------
function marca(id, erro){
  const el = $(id), c = el.closest('.campo'); if (!c) return !erro;
  const box = c.querySelector('.err');
  if (erro){ c.classList.add('mau'); c.classList.remove('ok'); if (box) box.textContent = erro; el.setAttribute('aria-invalid','true'); }
  else { c.classList.remove('mau'); el.removeAttribute('aria-invalid'); if (val(id)) c.classList.add('ok'); else c.classList.remove('ok'); }
  return !erro;
}

// A regra de cada campo. Devolve o texto do erro, ou '' se está bem.
function regra(id){
  const v = val(id);
  switch (id){
    case 'noiva':  return v ? '' : 'Falta o nome da noiva.';
    case 'noivo':  return v ? '' : 'Falta o nome do noivo.';
    case 'email':  return !v ? 'Indiquem o email de acesso.' : (EMAIL_RE.test(v) ? '' : 'Esse email não parece válido.');
    case 'senha':  return v.length >= 8 ? '' : 'Pelo menos 8 caracteres.';
    case 'confirmar':
      if (!v) return 'Repitam a palavra-passe.';
      return v === $('senha').value ? '' : 'As palavras-passe não coincidem.';
    case 'convidados': {
      if (!v) return '';
      const n = Number(v);
      return (Number.isInteger(n) && n >= 1 && n <= 5000) ? '' : 'Um número entre 1 e 5000.';
    }
    case 'whatsapp': {
      if (!v) return '';
      const digitos = (v.match(/\d/g) || []).length;
      if (/[a-z]/i.test(v)) return 'O contacto deve ter só números.';
      return digitos >= 8 && digitos <= 15 ? '' : 'Um número de telefone válido.';
    }
    case 'porteiro_email':
      if (!v && !val('porteiro_senha')) return '';               // par vazio: opcional
      return EMAIL_RE.test(v) ? '' : 'Email do porteiro inválido.';
    case 'porteiro_senha':
      if (!v && !val('porteiro_email')) return '';
      return v.length >= 8 ? '' : 'Pelo menos 8 caracteres para o porteiro.';
  }
  return '';
}
function validaCampo(id){ return marca(id, regra(id)); }

// Os campos que se validam, e os que se acompanham em par (mexer num revê o outro).
const CAMPOS = ['noiva','noivo','email','senha','confirmar','convidados','whatsapp',
                'porteiro_email','porteiro_senha'];
const PARES = { senha:['confirmar'], confirmar:['senha'],
                porteiro_email:['porteiro_senha'], porteiro_senha:['porteiro_email'] };

CAMPOS.forEach(id => {
  const el = $(id);
  // Valida ao sair do campo; e, se já tinha erro, corrige-o ao vivo enquanto escreve.
  el.addEventListener('blur', () => validaCampo(id));
  el.addEventListener('input', () => {
    if (el.closest('.campo').classList.contains('mau')) validaCampo(id);
    (PARES[id] || []).forEach(p => { if ($(p).closest('.campo').classList.contains('mau')) validaCampo(p); });
  });
});

// ---------- força da palavra-passe ----------
$('senha').addEventListener('input', () => {
  const v = $('senha').value, box = $('pw-forca'), rot = $('pw-rot');
  let n = 0;
  if (v.length >= 8) n++;
  if (v.length >= 12) n++;
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) n++;
  if (/\d/.test(v) && /[^\w\s]/.test(v)) n++;
  if (v && n === 0) n = 1;
  box.className = 'pw-forca f' + n;
  rot.textContent = v ? ['', 'fraca', 'razoável', 'boa', 'forte'][n] : '';
});
function verSenha(id, olhoId){
  const el = $(id), b = $(olhoId), ver = el.type === 'password';
  el.type = ver ? 'text' : 'password';
  b.textContent = ver ? 'ocultar' : 'mostrar';
}

// ---------- data no passado: aviso, não impedimento ----------
$('data').addEventListener('change', () => {
  const c = $('data').closest('.campo'), av = c.querySelector('.aviso-campo');
  const v = $('data').value;
  if (v && v < new Date().toISOString().slice(0,10)){
    c.classList.add('avisar'); if (av) av.textContent = 'Essa data já passou — confirmem, se faz favor.';
  } else c.classList.remove('avisar');
});

// ---------- a montra dos planos ----------
// O preçário vem do servidor: é ele que manda, e assim mexer nos preços não
// obriga a tocar nesta página. Enquanto não chega, a secção fica escondida —
// mais vale não ter montra nenhuma do que uma montra vazia.
let POLITICA = null;

async function carregarPlanos(){
  try {
    const r = await fetch('api.php?action=lic_catalogo');
    const d = await r.json();
    if (!d || !d.success) throw new Error('sem catálogo');
    POLITICA = d.politica;
    const temAlgo = (d.catalogo.pacotes || []).some(p => p.ativo)
                 || (d.catalogo.modulos || []).some(m => m.ativo && m.escaloes.some(e => e.ativo));
    if (!temAlgo) throw new Error('catálogo vazio');
    Planos.montar('reg-planos', d.catalogo, { moeda: d.moeda });
  } catch (e) {
    // Sem preçário não se pode escolher plano nenhum, mas a inscrição não pode
    // ficar refém disso: esconde-se a montra e o casal inscreve-se na mesma. A
    // administração concede-lhe os módulos à mão.
    $('planos-sec').querySelector('.pl-topo').style.display = 'none';
    $('reg-planos').style.display = 'none';
    $('reg-aceite-cx').style.display = 'none';
  }
}
carregarPlanos();

$('reg-ver-pol').addEventListener('click', () => {
  if (!POLITICA) return;
  Planos.politicas(POLITICA, () => {
    $('reg-aceite').checked = true;
    $('reg-aceite-cx').classList.remove('mau');
  });
});
$('reg-aceite').addEventListener('change', () => $('reg-aceite-cx').classList.remove('mau'));

// ---------- porteiro: sugestão de utilizador único, a partir dos nomes ----------
function slug(s){
  return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
let PORT_TOCADO = false;
$('porteiro_email').addEventListener('input', () => { PORT_TOCADO = true; });
function sugerirPorteiro(){
  if (PORT_TOCADO) return;
  const base = [slug(val('noiva')), slug(val('noivo'))].filter(Boolean).join('-') || 'casamento';
  const suf = Math.random().toString(36).slice(2, 5);
  const host = (location.hostname || 'convite.local').replace(/^www\./, '');
  $('porteiro_email').value = 'porta-' + base + '-' + suf + '@' + host;
  if (!$('porteiro_senha').value) $('porteiro_senha').value = 'porta' + Math.random().toString(36).slice(2, 10);
}
// Botão «sugerir»: força uma sugestão nova, mesmo que já se tenha mexido.
function sugerirPorteiroBtn(){ PORT_TOCADO = false; sugerirPorteiro(); PORT_TOCADO = true; }
$('noiva').addEventListener('input', sugerirPorteiro);
$('noivo').addEventListener('input', sugerirPorteiro);

// ---------- envio ----------
function erroGeral(txt){
  const e = $('erro');
  if (txt){ e.textContent = txt; e.style.display = ''; e.scrollIntoView({ behavior:'smooth', block:'center' }); }
  else e.style.display = 'none';
}

async function enviar(){
  erroGeral('');
  // Valida tudo; abre os blocos dobrados que tenham erro, para o campo estar à vista.
  let primeiro = null;
  CAMPOS.forEach(id => { if (!validaCampo(id) && !primeiro) primeiro = id; });
  if (primeiro){
    const c = $(primeiro).closest('details.bloco'); if (c) c.open = true;
    $(primeiro).focus(); $(primeiro).closest('.campo').scrollIntoView({ behavior:'smooth', block:'center' });
    erroGeral('Faltam alguns dados — veja os campos assinalados a vermelho.');
    return;
  }

  // O plano: só se exige quando há montra. Sem preçário publicado, a inscrição
  // segue sem plano nenhum e é a administração que concede os módulos.
  const temMontra = $('reg-planos').style.display !== 'none';
  let plano = null;
  if (temMontra){
    const c = Planos.escolha();
    if (c.vazio){
      erroGeral('Escolham um pacote, ou pelo menos um módulo do plano à medida.');
      $('planos-sec').scrollIntoView({ behavior:'smooth', block:'start' });
      return;
    }
    if (!$('reg-aceite').checked){
      $('reg-aceite-cx').classList.add('mau');
      erroGeral('É preciso aceitar as políticas de utilização.');
      $('reg-aceite-cx').scrollIntoView({ behavior:'smooth', block:'center' });
      return;
    }
    plano = { pacote: c.pacote, escaloes: c.escaloes, meses: c.meses, aceito: true };
  }

  const dados = {
    noiva: val('noiva'), noivo: val('noivo'), data: val('data'),
    email: val('email'), senha: $('senha').value,
    hora: val('hora'), local: val('local'), cidade: val('cidade'), maps: val('maps'),
    convidados: val('convidados'), whatsapp: val('whatsapp'),
    orcamento_total: val('orcamento_total'),
    licenca: plano,
    licenca_meses: plano ? plano.meses : 0,
    porteiro_email: val('porteiro_email'), porteiro_senha: $('porteiro_senha').value,
    civil_hora: val('civil_hora'), civil_local: val('civil_local'), civil_maps: val('civil_maps'),
    religiosa_hora: val('religiosa_hora'), religiosa_local: val('religiosa_local'), religiosa_maps: val('religiosa_maps'),
  };
  const btn = $('btn');
  btn.disabled = true; const rot = btn.textContent; btn.textContent = 'A enviar…';
  try {
    const r = await fetch('api.php?action=registo_publico', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(dados) });
    const d = await r.json();
    if (d && d.success){
      $('feito-email').innerHTML = 'A vossa entrada: <b>' + dados.email.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])) + '</b>';
      $('formulario').style.display = 'none';
      $('planos-sec').style.display = 'none';
      $('obrigado').style.display = '';
      window.scrollTo({ top:0, behavior:'smooth' });
      return;
    }
    // Erro do servidor: se for do email (já existe / inválido), aponta-o ao campo.
    const m = (d && d.message) || 'Não foi possível inscrever.';
    if (/email/i.test(m)){ marca('email', m); $('email').focus(); }
    erroGeral(m);
  } catch (e){
    erroGeral('Não foi possível falar com o servidor. Tente de novo, por favor.');
  }
  btn.disabled = false; btn.textContent = rot;
}
</script>
<?php include __DIR__ . "/parcial-seletor-tema.php"; ?>
</body>
</html>
