<?php
// ============================================================
// equipa.php — Quem entra neste casamento, e com que chave
//
// Três coisas que andavam sem sítio:
//   • os lugares no casamento aberto (noivos, porteiro) — que são do casal a
//     dar e a tirar, e não da plataforma a impor;
//   • os códigos que o casal gera para o suporte poder ver, ou corrigir, e
//     que revoga quando quiser;
//   • a própria conta — mudar a senha, que até aqui não tinha onde se fazer.
//
// A última é para toda a gente, incluindo quem só trabalha à porta.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
exigirPorta();

$souAdmin = ehAdmin();
$visita   = emVisitaDeSuporte();
$soVer    = $visita && !podeCorrigir();
$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Equipa · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem 1.3rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:1rem; line-height:1.5; }
  .linha{ display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center;
          padding:.65rem 0; border-top:1px solid var(--line); }
  .linha:first-of-type{ border-top:0; }
  .linha .selo{ width:34px; height:34px; border-radius:9px; background:var(--cream); color:var(--forest);
                display:flex; align-items:center; justify-content:center; font-family:var(--serif); border:1px solid var(--line); }
  .linha .nm{ font-size:.95rem; color:var(--ink); }
  .linha .mt{ font-size:.78rem; color:#8a8f88; margin-top:.1rem; }
  .linha .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.ativo{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.pendente,.et.expirado{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.suspenso,.et.revogado{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .et.valido{ background:var(--gold-pale); color:var(--ink); border-color:var(--gold-soft); }
  .lf{ display:grid; grid-template-columns:2fr 1fr auto; gap:.7rem; align-items:end; margin-top:1rem;
       padding-top:1rem; border-top:1px dashed var(--line); }
  .cod{ font-family:ui-monospace,monospace; font-size:1.05rem; letter-spacing:.12em; color:var(--ink); }
  .aviso-visita{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
                 border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1.2rem; line-height:1.5; }
  .segredo{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
            padding:.8rem .9rem; margin-top:.9rem; font-size:.88rem; line-height:1.6; }
  @media (max-width:640px){ .lf{ grid-template-columns:1fr; } .linha{ grid-template-columns:auto 1fr; }
                            .linha .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Equipa', 'Quem entra neste casamento, e com que chave', 'equipa'); ?>

<main class="container">

  <?php if ($visita): ?>
    <div class="aviso-visita">
      Está a acompanhar este casamento com um código de suporte
      <b><?= $soVer ? 'de leitura' : 'com permissão de correção' ?></b>.
      <?= $soVer ? 'Pode ver tudo; alterar, não.' : 'Pode ver e corrigir.' ?>
      <a href="#" onclick="sairVisita();return false">Terminar a visita</a>.
    </div>
  <?php endif; ?>

  <?php if ($souAdmin): ?>
    <div class="painel">
      <h3>Quem entra neste casamento</h3>
      <div class="dica">Os <b>noivos</b> gerem tudo. O <b>porteiro</b> só vê a porta:
        procura convites e regista entradas, e mais nada.</div>
      <div id="lista-acessos"><div class="dica">A carregar…</div></div>

      <?php if (!$soVer): ?>
      <div class="lf">
        <div><label>Email de quem convida</label>
          <input type="email" id="a-email" placeholder="porteiro@exemplo.pt" autocapitalize="none" spellcheck="false"></div>
        <div><label>Papel</label>
          <select id="a-papel"><option value="porteiro">Porteiro</option><option value="noivos">Noivos</option></select></div>
        <div><button class="btn btn-ouro" onclick="convidar()">Dar acesso</button></div>
      </div>
      <div class="segredo" id="senha-nova" style="display:none"></div>
      <?php endif; ?>
    </div>

    <div class="painel">
      <h3>Códigos de suporte</h3>
      <div class="dica">Se precisar de ajuda de quem gere a plataforma, gere aqui um código e entregue-o.
        Sem código, o suporte não entra neste casamento. Pode revogá-lo a qualquer momento —
        e o código deixa de servir mesmo que a pessoa ainda o tenha.</div>
      <div id="lista-codigos"><div class="dica">A carregar…</div></div>

      <?php if (!$visita): ?>
      <div class="lf">
        <div><label>O que permite</label>
          <select id="s-corrigir">
            <option value="0">Só ver</option>
            <option value="1">Ver e corrigir</option>
          </select></div>
        <div><label>Válido por</label>
          <select id="s-dias">
            <option value="1">1 dia</option>
            <option value="7" selected>7 dias</option>
            <option value="30">30 dias</option>
          </select></div>
        <div><button class="btn btn-ouro" onclick="gerarCodigo()">Gerar código</button></div>
      </div>
      <div class="segredo" id="codigo-novo" style="display:none"></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="painel">
    <h3>A minha conta</h3>
    <div class="dica">Entrou como <b><?= escP(utilizadorAtual() ?? '') ?></b>.</div>
    <div class="lf" style="border-top:0;padding-top:0;margin-top:0;grid-template-columns:1fr 1fr auto">
      <div><label>Senha atual</label><input type="password" id="p-atual" autocomplete="current-password"></div>
      <div><label>Nova senha</label><input type="password" id="p-nova" autocomplete="new-password"></div>
      <div><button class="btn" onclick="mudarSenha()">Mudar senha</button></div>
    </div>
    <div class="dica" style="margin:.6rem 0 0">Pelo menos 8 caracteres.</div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const SOU_ADMIN = <?= $souAdmin ? 'true' : 'false' ?>;
const SO_VER    = <?= $soVer ? 'true' : 'false' ?>;

// ---------- quem entra ----------
async function carregarAcessos(){
  const d = await api('acesso_lista');
  if (!d || !d.success) return;
  const alvo = $('lista-acessos');
  if (!d.acessos.length){ alvo.innerHTML = '<div class="dica">Ninguém, além de si.</div>'; return; }
  alvo.innerHTML = d.acessos.map(a => {
    const eu = +a.utilizador_id === +d.eu;
    const nome = a.nome || a.email;
    const acoes = (SO_VER || eu) ? '' : `
      <button class="btn btn-sm" onclick="trocarPapel(${a.utilizador_id}, '${a.papel === 'noivos' ? 'porteiro' : 'noivos'}')">
        Passar a ${a.papel === 'noivos' ? 'porteiro' : 'noivos'}</button>
      <button class="btn btn-sm" onclick="tirar(${a.utilizador_id}, '${esc(nome)}')">Tirar</button>`;
    return `<div class="linha">
      <div class="selo">${esc(nome.slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(nome)} ${eu ? '<span class="et">é você</span>' : ''}
          <span class="et ${esc(a.estado)}">${esc(a.estado)}</span></div>
        <div class="mt">${esc(a.email)} · ${a.papel === 'noivos' ? 'gere o casamento' : 'só a porta'}</div>
      </div>
      <div class="ac">${acoes}</div>
    </div>`;
  }).join('');
}

async function convidar(){
  const email = $('a-email').value.trim();
  if (!email) return toast('Indique o email.', true);
  const d = await api('acesso_convidar', { method:'POST',
    body: JSON.stringify({ email, papel: $('a-papel').value }) });
  if (!d || !d.success) return;
  $('a-email').value = '';
  if (d.senha){
    $('senha-nova').style.display = '';
    $('senha-nova').innerHTML = `Conta criada para <b>${esc(d.email)}</b>.
      Senha temporária: <b class="cod">${esc(d.senha)}</b><br>
      Entregue-lha agora — não volta a aparecer. Ela deve mudá-la na página Equipa.`;
  } else {
    toast('Acesso dado a ' + d.email + '.');
  }
  carregarAcessos();
}

async function trocarPapel(uid, papel){
  const d = await api('acesso_papel&utilizador=' + uid + '&papel=' + papel, { method:'POST' });
  if (d && d.success) carregarAcessos();
}
async function tirar(uid, nome){
  if (!confirm('Tirar o acesso de ' + nome + ' a este casamento?\n\nA conta continua a existir.')) return;
  const d = await api('acesso_tirar&utilizador=' + uid, { method:'POST' });
  if (d && d.success) carregarAcessos();
}

// ---------- códigos de suporte ----------
async function carregarCodigos(){
  const d = await api('suporte_codigo_lista');
  if (!d || !d.success) return;
  const alvo = $('lista-codigos');
  if (!d.codigos.length){ alvo.innerHTML = '<div class="dica">Nenhum código gerado.</div>'; return; }
  alvo.innerHTML = d.codigos.map(c => {
    const usado = c.usado_em ? `usado por ${esc(c.usado_por_email || 'alguém')}` : 'ainda não usado';
    const expira = c.expira_em ? ('expira em ' + esc(c.expira_em.slice(0,10))) : 'sem prazo';
    const revogar = (c.estado === 'valido')
      ? `<button class="btn btn-sm" onclick="revogar(${c.id})">Revogar</button>` : '';
    return `<div class="linha">
      <div class="selo">&#128273;</div>
      <div>
        <div class="nm"><span class="cod">${esc(c.codigo)}</span>
          <span class="et ${esc(c.estado)}">${esc(c.estado)}</span>
          <span class="et">${c.pode_corrigir == 1 ? 'ver e corrigir' : 'só ver'}</span></div>
        <div class="mt">${expira} · ${usado}</div>
      </div>
      <div class="ac">${revogar}</div>
    </div>`;
  }).join('');
}

async function gerarCodigo(){
  const d = await api('suporte_codigo_criar', { method:'POST',
    body: JSON.stringify({ pode_corrigir: $('s-corrigir').value === '1', dias: +$('s-dias').value }) });
  if (!d || !d.success) return;
  $('codigo-novo').style.display = '';
  $('codigo-novo').innerHTML = `Código: <b class="cod">${esc(d.codigo)}</b> ·
    ${d.pode_corrigir ? 'ver e corrigir' : 'só ver'} · válido ${d.dias} dia(s).<br>
    Entregue-o a quem lhe vai dar apoio. Enquanto não o revogar, essa pessoa entra aqui com ele.`;
  carregarCodigos();
}
async function revogar(id){
  if (!confirm('Revogar este código?\n\nQuem o tiver deixa de entrar, já.')) return;
  const d = await api('suporte_codigo_revogar&id=' + id, { method:'POST' });
  if (d && d.success) carregarCodigos();
}

// ---------- a minha conta ----------
async function mudarSenha(){
  const atual = $('p-atual').value, nova = $('p-nova').value;
  if (!atual || !nova) return toast('Preencha as duas senhas.', true);
  const d = await api('senha_mudar', { method:'POST', body: JSON.stringify({ atual, nova }) });
  if (d && d.success){ $('p-atual').value = $('p-nova').value = ''; toast('Senha mudada.'); }
}

async function sairVisita(){
  const d = await api('suporte_sair', { method:'POST' });
  if (d && d.success) location.href = 'plataforma.php';
}

if (SOU_ADMIN){ carregarAcessos(); carregarCodigos(); }
</script>
</body>
</html>
