<?php
// ============================================================
// plataforma.php — Os casamentos que o sistema serve
//
// A casa vista de cima: quem é da plataforma (admin, suporte) vê aqui todos
// os casamentos, abre o que precisa e cria novos. Quem é dos noivos e tem mais
// do que um vê aqui os seus, e escolhe em qual quer trabalhar.
//
// Serve também de guarda: saber SEMPRE em que casamento se está é o que evita
// editar o convite do casal errado.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';

// Qualquer pessoa autenticada chega aqui; o que vê é que difere.
if (!papel() && !ehPessoalPlataforma()) {
    header('Location: login.php?r=' . urlencode('plataforma.php')); exit;
}

$meus    = casamentosDoUtilizador($conn);
$aberto  = casamentoAtual();
$daCasa  = ehPessoalPlataforma();
$mandaNaCasa = ehAdminPlataforma();   // criar casamentos e gerir contas é do admin

// Números de cada casamento, numa consulta só por tabela — em alojamento
// partilhado, uma consulta por linha da lista custa caro.
$conta = [];
foreach ([['convites', 'convites'], ['convidados', 'pessoas'], ['mesas', 'mesas']] as [$tab, $rot]) {
    $r = @$conn->query("SELECT casamento_id, COUNT(*) n FROM {$P}$tab GROUP BY casamento_id");
    if ($r) while ($x = $r->fetch_assoc()) $conta[(int)$x['casamento_id']][$rot] = (int)$x['n'];
}

// Registos à espera de aprovação (só o admin da plataforma os despacha).
$pendentes = [];
if (ehAdminPlataforma()) {
    $r = @$conn->query("SELECT id, nome, noiva, noivo, criado_em FROM {$P}casamentos
                        WHERE estado='pendente' ORDER BY criado_em");
    if ($r) $pendentes = $r->fetch_all(MYSQLI_ASSOC);
}

$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Casamentos · Plataforma</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .cas-lista{ display:grid; gap:.7rem; }
  .cas{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem;
        display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center; }
  .cas.aberto{ border-color:var(--gold-soft); box-shadow:0 0 0 3px rgba(180,134,74,.14); }
  .cas .selo{ width:38px; height:38px; border-radius:10px; background:var(--cream); color:var(--forest);
              display:flex; align-items:center; justify-content:center; font-family:var(--serif);
              font-size:1rem; border:1px solid var(--line); }
  .cas.aberto .selo{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .cas .nm{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .cas .meta{ font-size:.8rem; color:#8a8f88; display:flex; gap:.7rem; flex-wrap:wrap; margin-top:.15rem; }
  .cas .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.ativo{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.pendente{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.suspenso{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .et.agora{ background:var(--gold-pale); color:var(--ink); border-color:var(--gold-soft); }
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:.8rem; line-height:1.5; }
  .cod{ font-family:ui-monospace,monospace; letter-spacing:.12em; }
  .segredo{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
            padding:.8rem .9rem; margin-top:.9rem; font-size:.88rem; line-height:1.6; }
  .lf{ display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:.7rem; align-items:end; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .cas{ grid-template-columns:auto 1fr; } .cas .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Casamentos', $daCasa ? 'Todos os casamentos que o sistema serve' : 'Os casamentos a que tem acesso', 'plataforma'); ?>

<main class="container">

  <?php if ($pendentes): ?>
    <div class="painel" style="border-color:var(--warn); border-left:4px solid var(--warn)">
      <h3><?= count($pendentes) ?> registo(s) à espera de aprovação</h3>
      <div class="dica">Um casal inscreveu-se e aguarda que lhe abram a porta. Até ser aprovado, não entra.</div>
      <div class="cas-lista">
        <?php foreach ($pendentes as $p): ?>
          <div class="cas">
            <div class="selo">?</div>
            <div>
              <div class="nm"><?= escP($p['nome']) ?></div>
              <div class="meta"><span>Inscrito em <?= escP(date('d/m/Y', strtotime($p['criado_em']))) ?></span></div>
            </div>
            <div class="ac">
              <button class="btn btn-ouro btn-sm" onclick="aprovar(<?= (int)$p['id'] ?>)">Aprovar</button>
              <button class="btn btn-sm" onclick="recusar(<?= (int)$p['id'] ?>)">Recusar</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (ehSuporte()): ?>
    <div class="painel">
      <h3>Entrar com um código do casal</h3>
      <div class="dica">O suporte não entra em casa de ninguém por direito próprio: é o casal
        que gera um código e o entrega. O código diz se pode só ver ou também corrigir,
        e o casal revoga-o quando quiser.</div>
      <div class="lf" style="grid-template-columns:1fr auto">
        <div><label>Código</label>
          <input type="text" id="s-codigo" placeholder="XXXXXXXX" autocapitalize="characters" spellcheck="false"></div>
        <div><button class="btn btn-ouro" onclick="entrarComCodigo()">Entrar</button></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($mandaNaCasa): ?>
    <div class="painel">
      <h3>Novo casamento</h3>
      <div class="dica">Cria o casamento já ativo. A conta dos noivos liga-se a ele a seguir.</div>
      <div class="lf">
        <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Isabel &amp; Abednego"></div>
        <div><label>Noiva</label><input type="text" id="n-noiva" placeholder="Isabel"></div>
        <div><label>Noivo</label><input type="text" id="n-noivo" placeholder="Abednego"></div>
        <div><button class="btn btn-ouro" onclick="criar()">Criar</button></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="cas-lista">
    <?php if (!$meus): ?>
      <div class="vazio"><div class="ico">✦</div>
        <p>Ainda não tem nenhum casamento.<br>
          <?= $daCasa ? 'Crie o primeiro no painel acima.' : 'Fale com quem lhe deu acesso.' ?></p></div>
    <?php endif; ?>

    <?php foreach ($meus as $id => $c):
      $eAberto = ((int)$id === (int)$aberto);
      $n = $conta[(int)$id] ?? []; ?>
      <div class="cas<?= $eAberto ? ' aberto' : '' ?>">
        <div class="selo"><?= escP(mb_strtoupper(mb_substr($c['nome'], 0, 1))) ?></div>
        <div>
          <div class="nm"><?= escP($c['nome']) ?>
            <?php if ($eAberto): ?><span class="et agora">aberto agora</span><?php endif; ?>
            <span class="et <?= escP($c['estado']) ?>"><?= escP($c['estado']) ?></span>
          </div>
          <div class="meta">
            <span><b><?= (int)($n['convites'] ?? 0) ?></b> convites</span>
            <span><b><?= (int)($n['pessoas'] ?? 0) ?></b> pessoas</span>
            <span><b><?= (int)($n['mesas'] ?? 0) ?></b> mesas</span>
            <?php if (($c['papel'] ?? '') === 'porteiro'): ?><span>o seu papel: porteiro</span><?php endif; ?>
          </div>
        </div>
        <div class="ac">
          <?php if ($eAberto): ?>
            <a class="btn btn-ouro btn-sm" href="index.php">Continuar</a>
          <?php else: ?>
            <button class="btn btn-sm" onclick="abrir(<?= (int)$id ?>)">Abrir</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (ehAdminPlataforma()): ?>
    <div class="painel" style="margin-top:1.4rem">
      <h3>Contas</h3>
      <div class="dica">Todas as contas do sistema. Uma conta <b>suspensa</b> não entra —
        e a mensagem que recebe é a mesma de senha errada, para quem tenta adivinhar
        não ficar a saber que a conta existe.</div>
      <div class="lf" style="grid-template-columns:1fr auto;margin-bottom:.6rem">
        <div><input type="search" id="q-conta" placeholder="Procurar por email ou nome…"
                    oninput="carregarContas()"></div>
        <div></div>
      </div>
      <div id="lista-contas"><div class="dica">A carregar…</div></div>
      <div class="segredo" id="senha-reposta" style="display:none"></div>
    </div>
  <?php endif; ?>
</main>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script>
async function abrir(id){
  const d = await api('casamento_abrir&id=' + id, { method:'POST' });
  if (d && d.success) location.href = 'index.php';
}
async function criar(){
  const nome = document.getElementById('n-nome').value.trim();
  const noiva = document.getElementById('n-noiva').value.trim();
  const noivo = document.getElementById('n-noivo').value.trim();
  if (!nome && !noiva && !noivo) return toast('Indique ao menos os nomes dos noivos.', true);
  const d = await api('casamento_criar', { method:'POST',
    body: JSON.stringify({ nome, noiva, noivo }) });
  if (d && d.success) location.reload();
}
async function aprovar(id){
  const d = await api('casamento_estado&id=' + id + '&estado=ativo', { method:'POST' });
  if (d && d.success) location.reload();
}
async function recusar(id){
  if (!confirm('Recusar este registo?\n\nO casal deixa de poder entrar. Nada se apaga.')) return;
  const d = await api('casamento_estado&id=' + id + '&estado=suspenso', { method:'POST' });
  if (d && d.success) location.reload();
}
async function entrarComCodigo(){
  const codigo = document.getElementById('s-codigo').value.trim();
  if (!codigo) return toast('Escreva o código que o casal lhe deu.', true);
  const d = await api('suporte_entrar', { method:'POST', body: JSON.stringify({ codigo }) });
  if (d && d.success) location.href = 'index.php';
}

// ---------- contas (só o admin da plataforma) ----------
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
async function carregarContas(){
  const alvo = document.getElementById('lista-contas');
  if (!alvo) return;
  const q = (document.getElementById('q-conta').value || '').trim();
  const d = await api('utilizador_lista' + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (!d || !d.success) return;
  if (!d.contas.length){ alvo.innerHTML = '<div class="dica">Nenhuma conta corresponde.</div>'; return; }
  alvo.innerHTML = d.contas.map(c => {
    const eu = +c.id === +d.eu;
    const nome = c.nome || c.email;
    const plat = c.papel_plataforma ? `<span class="et agora">${esc(c.papel_plataforma)} da plataforma</span>` : '';
    const trocaEstado = c.estado === 'ativo' ? 'suspenso' : 'ativo';
    const acoes = eu ? '<span class="meta">é você</span>' : `
      <button class="btn btn-sm" onclick="estadoConta(${c.id}, '${trocaEstado}')">
        ${c.estado === 'ativo' ? 'Suspender' : 'Ativar'}</button>
      <button class="btn btn-sm" onclick="reporSenha(${c.id}, '${esc(c.email)}')">Repor senha</button>`;
    return `<div class="cas">
      <div class="selo">${esc(nome.slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(nome)} <span class="et ${esc(c.estado)}">${esc(c.estado)}</span> ${plat}</div>
        <div class="meta"><span>${esc(c.email)}</span>
          <span>${c.casamentos} casamento(s)</span>
          <span>${c.ultimo_acesso ? 'último acesso ' + esc(c.ultimo_acesso.slice(0,10)) : 'nunca entrou'}</span></div>
      </div>
      <div class="ac">${acoes}</div>
    </div>`;
  }).join('');
}
async function estadoConta(id, estado){
  if (estado === 'suspenso' && !confirm('Suspender esta conta?\n\nDeixa de entrar até ser reativada.')) return;
  const d = await api('utilizador_estado&id=' + id + '&estado=' + estado, { method:'POST' });
  if (d && d.success) carregarContas();
}
async function reporSenha(id, email){
  if (!confirm('Repor a senha de ' + email + '?\n\nA senha atual deixa de servir. A nova aparece aqui, uma vez.')) return;
  const d = await api('utilizador_repor_senha&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  const cx = document.getElementById('senha-reposta');
  cx.style.display = '';
  cx.innerHTML = `Senha nova de <b>${esc(d.email)}</b>: <b class="cod">${esc(d.senha)}</b><br>
    Entregue-lha agora — não volta a aparecer. Ela deve mudá-la na página Gestão.`;
}
carregarContas();
</script>
</body>
</html>
