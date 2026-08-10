<?php
// ============================================================
// modelos.php — Os desenhos que a casa oferece a todos
//
// As versões (cw_versoes) são de cada casamento: o que ESTE casal guardou. Os
// modelos são o outro lado — convites prontos, feitos pela casa, para um casal
// começar de qualquer coisa bonita em vez de uma folha em branco.
//
// Como se faz um modelo: abre-se um casamento, desenha-se o convite no editor
// até ficar bem, e guarda-se aqui. É por isso que a página pede um casamento
// aberto para criar ou recapturar — o modelo é uma FOTOGRAFIA de um convite a
// sério, e não um formulário à parte que teria de repetir o editor inteiro.
//
// Aplicar um modelo COPIA-O para as definições do casamento. A partir daí o
// desenho é do casal: mexer no modelo depois disso não lhe toca, e um casal que
// tenha personalizado o convite não acorda com ele mudado porque a casa mexeu
// num modelo.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';

if (!ehAdminPlataforma()) {
    header('Location: ' . (utilizadorId() ? 'plataforma.php' : 'login.php?r=modelos.php')); exit;
}

$aberto = casamentoAtual();
$nomeAberto = '';
if ($aberto > 0) {
    $r = @$conn->query("SELECT nome FROM {$P}casamentos WHERE id=$aberto LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) $nomeAberto = (string)$x['nome'];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modelos de convite · Plataforma</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:.8rem; line-height:1.5; }
  .lf{ display:grid; grid-template-columns:2fr 3fr 1fr auto; gap:.7rem; align-items:end; }
  .mod{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem;
        display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center; margin-bottom:.6rem; }
  .mod .selo{ width:38px; height:38px; border-radius:10px; background:var(--cream); color:var(--forest);
              display:flex; align-items:center; justify-content:center; border:1px solid var(--line); font-size:1.1rem; }
  .mod .nm{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .mod .meta{ font-size:.8rem; color:#8a8f88; display:flex; gap:.7rem; flex-wrap:wrap; margin-top:.15rem; }
  .mod .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.publicado{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.rascunho{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .filtros{ display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .chip{ border:1px solid var(--line); background:#fff; color:#6c7570; border-radius:50px;
         padding:.3rem .8rem; font-size:.8rem; font-family:var(--sans); cursor:pointer; }
  .chip.on{ background:var(--forest); border-color:var(--forest); color:var(--ivory); }
  .aviso{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
          border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1rem; line-height:1.5; }
  .editor-mod{ grid-column:1/-1; border-top:1px dashed var(--line); margin-top:.7rem; padding-top:.8rem; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .mod{ grid-template-columns:auto 1fr; }
                            .mod .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Modelos de convite', 'Os desenhos que a casa oferece a todos os casais', 'modelos'); ?>

<main class="container">

  <?php if ($aberto <= 0): ?>
    <div class="aviso">
      Não tem casamento nenhum aberto. Pode ver, publicar e apagar modelos —
      mas <b>criar um modelo é fotografar um convite a sério</b>: abra um casamento,
      desenhe lá o convite como quer que ele fique, e volte aqui para o guardar.
      <a href="plataforma.php">Escolher um casamento</a>.
    </div>
  <?php endif; ?>

  <div class="painel">
    <h3>Novo modelo</h3>
    <div class="dica">
      <?php if ($aberto > 0): ?>
        Guarda o convite de <b><?= escP($nomeAberto) ?></b>, tal como está agora, como um modelo
        para toda a gente. Só o desenho viaja — nomes, datas e convidados ficam onde estão.
      <?php else: ?>
        Precisa de um casamento aberto: o modelo é uma fotografia do convite que lá estiver.
      <?php endif; ?>
    </div>
    <div class="lf">
      <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Clássico verde"></div>
      <div><label>Descrição</label><input type="text" id="n-desc" placeholder="Para quem é, o que tem de particular"></div>
      <div><label>Peça</label>
        <select id="n-ambito"><option value="digital">Convite digital</option>
                              <option value="impresso">Convite impresso</option></select></div>
      <div><button class="btn btn-ouro" onclick="criar()" <?= $aberto > 0 ? '' : 'disabled' ?>>Guardar modelo</button></div>
    </div>
    <div class="dica" style="margin:.7rem 0 0">
      <label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400">
        <input type="checkbox" id="n-visivel" checked style="width:auto;margin:0">
        Publicar já (os casais passam a vê-lo no seletor de versões)
      </label>
    </div>
  </div>

  <div class="painel">
    <h3>Modelos</h3>
    <div class="dica">Um modelo publicado aparece a todos os casais, no seletor de versões do editor.
      Um por publicar fica só para si — serve para o preparar com calma.</div>
    <div class="filtros" id="filtros">
      <button class="chip on" data-ambito="" onclick="filtrar('')">Todos</button>
      <button class="chip" data-ambito="digital" onclick="filtrar('digital')">Convite digital</button>
      <button class="chip" data-ambito="impresso" onclick="filtrar('impresso')">Convite impresso</button>
    </div>
    <div id="lista"><div class="dica">A carregar…</div></div>
  </div>

  <div class="painel">
    <h3>Levar e trazer</h3>
    <div class="dica">Os modelos num ficheiro, para os guardar ou os levar para outra instalação.
      Trazer <b>acrescenta</b>: não substitui nem mistura nada com os que já cá estão.</div>
    <div class="lf" style="grid-template-columns:auto 1fr auto">
      <div><a class="btn" href="api.php?action=modelos_exportar">Descarregar os modelos</a></div>
      <div><label>Trazer de um ficheiro</label>
        <input type="file" id="imp" accept=".json,application/json"></div>
      <div><button class="btn" onclick="importar()">Trazer</button></div>
    </div>
    <div class="segredo" id="imp-res" style="display:none;background:var(--gold-pale);
         border:1px dashed var(--gold-soft);border-radius:10px;padding:.8rem .9rem;margin-top:.9rem;font-size:.88rem"></div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const TEM_CASAMENTO = <?= $aberto > 0 ? 'true' : 'false' ?>;
let AMBITO = '', MODELOS = {};
function toast(m, mau){ const t=$('toast'); t.textContent=m; t.className='toast mostrar'+(mau?' erro':'');
                        setTimeout(()=>t.className='toast', 2800); }

function filtrar(a){
  AMBITO = a;
  document.querySelectorAll('#filtros .chip').forEach(c => c.classList.toggle('on', c.dataset.ambito === a));
  carregar();
}

async function carregar(){
  const d = await api('modelo_lista' + (AMBITO ? '&ambito=' + AMBITO : ''));
  if (!d || !d.success) return;
  MODELOS = {};
  d.modelos.forEach(m => { MODELOS[m.id] = m; });
  const alvo = $('lista');
  if (!d.modelos.length){
    alvo.innerHTML = '<div class="dica">Ainda não há modelos. O primeiro faz-se do convite de um casamento aberto.</div>';
    return;
  }
  alvo.innerHTML = d.modelos.map(m => `
    <div class="mod">
      <div class="selo">${m.ambito === 'impresso' ? '&#9635;' : '&#9993;'}</div>
      <div>
        <div class="nm">${esc(m.nome)}
          <span class="et ${+m.visivel ? 'publicado' : 'rascunho'}">${+m.visivel ? 'publicado' : 'por publicar'}</span>
          <span class="et">${m.ambito === 'impresso' ? 'impresso' : 'digital'}</span></div>
        <div class="meta">
          ${m.descricao ? `<span>${esc(m.descricao)}</span>` : ''}
          <span>de ${esc(m.criado_por || '—')}</span>
          <span>${esc((m.atualizado_em || m.criado_em || '').slice(0,10))}</span>
        </div>
      </div>
      <div class="ac">
        <button class="btn btn-sm" onclick="editar(${m.id})">Editar</button>
        <button class="btn btn-sm" onclick="publicar(${m.id}, ${+m.visivel ? 0 : 1})">
          ${+m.visivel ? 'Retirar' : 'Publicar'}</button>
        <button class="btn btn-sm" onclick="apagar(${m.id}, '${esc(m.nome)}')">Apagar</button>
      </div>
      <div class="editor-mod" id="ed-${m.id}" style="display:none"></div>
    </div>`).join('');
}

async function criar(){
  const nome = $('n-nome').value.trim();
  if (!nome) return toast('Dê um nome ao modelo.', true);
  const d = await api('modelo_criar', { method:'POST', body: JSON.stringify({
    nome, descricao: $('n-desc').value.trim(), ambito: $('n-ambito').value,
    visivel: $('n-visivel').checked }) });
  if (!d || !d.success) return;
  $('n-nome').value = $('n-desc').value = '';
  toast('Modelo guardado com ' + d.definicoes + ' definição(ões).');
  carregar();
}

function editar(id){
  const cx = $('ed-' + id);
  if (cx.style.display !== 'none'){ cx.style.display = 'none'; return; }
  const m = MODELOS[id] || {};
  cx.style.display = '';
  cx.innerHTML = `
    <div class="lf" style="grid-template-columns:2fr 3fr auto;margin:0">
      <div><label>Nome</label><input type="text" id="e-nome-${id}" value="${esc(m.nome)}"></div>
      <div><label>Descrição</label><input type="text" id="e-desc-${id}" value="${esc(m.descricao || '')}"></div>
      <div><button class="btn btn-ouro btn-sm" onclick="guardar(${id})">Guardar</button></div>
    </div>
    <div class="dica" style="margin:.7rem 0 0">
      ${TEM_CASAMENTO
        ? `<button class="btn btn-sm" onclick="recapturar(${id})">Trazer o desenho do casamento aberto</button>
           <span style="margin-left:.5rem">Substitui o desenho guardado neste modelo pelo que o
           casamento aberto mostra agora. Os casais que já o usaram não são tocados.</span>`
        : 'Para trocar o desenho deste modelo, abra o casamento onde o desenhou.'}
    </div>`;
}

async function guardar(id, recapturar){
  const m = MODELOS[id] || {};
  const d = await api('modelo_editar', { method:'POST', body: JSON.stringify({
    id, nome: $('e-nome-' + id).value.trim(), descricao: $('e-desc-' + id).value.trim(),
    visivel: +m.visivel ? 1 : 0, recapturar: !!recapturar }) });
  if (d && d.success){ toast('Modelo guardado.'); carregar(); }
}
async function recapturar(id){
  if (!confirm('Trazer o desenho do casamento aberto para este modelo?\n\n'
    + 'O desenho que o modelo tinha perde-se. Quem já o aplicou fica como está.')) return;
  guardar(id, true);
}
async function publicar(id, visivel){
  const m = MODELOS[id] || {};
  const d = await api('modelo_editar', { method:'POST', body: JSON.stringify({
    id, nome: m.nome, descricao: m.descricao || '', visivel }) });
  if (d && d.success){ toast(visivel ? 'Publicado.' : 'Retirado dos casais.'); carregar(); }
}
async function apagar(id, nome){
  if (!confirm('Apagar o modelo "' + nome + '"?\n\n'
    + 'Os casais que já o usaram ficam como estão — o desenho passou a ser deles.')) return;
  const d = await api('modelo_apagar&id=' + id, { method:'POST' });
  if (d && d.success){ toast('Modelo apagado.'); carregar(); }
}

async function importar(){
  const f = $('imp').files[0];
  if (!f) return toast('Escolha o ficheiro primeiro.', true);
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  const d = await api('modelos_importar', { method:'POST', body: JSON.stringify({ ficheiro: dados }) });
  if (!d || !d.success) return;
  $('imp-res').style.display = '';
  $('imp-res').innerHTML = `Entraram <b>${d.entraram}</b> modelo(s)`
    + (d.saltados ? ` · ${d.saltados} saltado(s) por não trazerem desenho aproveitável.` : '.');
  carregar();
}

carregar();
</script>
</body>
</html>
