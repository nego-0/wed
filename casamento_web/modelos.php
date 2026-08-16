<?php
// ============================================================
// modelos.php — Os desenhos que a casa oferece a todos
//
// As versões (cw_versoes) são de cada casamento: o que ESTE casal guardou. Os
// modelos são o outro lado — convites prontos, feitos pela casa, para um casal
// começar de qualquer coisa bonita em vez de uma folha em branco.
//
// Como se faz um modelo: cria-se aqui (do desenho de origem, ou do convite de
// um casamento que esteja aberto) e desenha-se no editor de sempre, que abre em
// modo de modelo — sem casamento nenhum pelo meio. Pedir emprestada a casa de
// um casal para fazer um modelo da CASA era pedir o que não é preciso, e
// arriscar deixar lá o rascunho.
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
  /* ---- A grelha dos modelos ----
     Esta página é sobre DESENHOS, e não mostrava desenho nenhum: escolher um
     modelo pelo nome é escolher às cegas. Cada um passa a trazer a sua cara,
     desenhada a sério (o cartão por modelo-prova.php, o convite digital por
     convite-digital.php?modelo=N) e encolhida para caber. */
  .grelha{ display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:1rem; }
  /* Sem overflow:hidden aqui: era ele que cortava o menu "⋯" — o painel abria,
     mas ficava recortado pela borda do cartão e parecia não fazer nada. Quem
     precisa de recortar é a moldura da miniatura, e essa recorta-se a si. */
  .mod{ background:#fff; border:1px solid var(--line); border-radius:14px;
        display:flex; flex-direction:column; transition:.16s; }
  .mod:hover{ border-color:var(--gold-soft); box-shadow:0 8px 22px rgba(180,134,74,.14); }
  .mod.por-publicar{ background:repeating-linear-gradient(135deg,#fff 0 10px,#fdfbf6 10px 20px); }
  /* A moldura tem a proporção do cartão (2:3); a prova é desenhada em tamanho
     real e encolhida por transform, para o que se vê ser o que sai. */
  .cara{ position:relative; width:100%; aspect-ratio:2/3; overflow:hidden; background:#20211c;
         border-bottom:1px solid var(--line); display:block; border-radius:13px 13px 0 0; }
  .cara iframe{ position:absolute; top:0; left:0; border:0; transform-origin:top left; pointer-events:none; }
  .cara .selo{ position:absolute; right:.4rem; top:.4rem; z-index:2; width:26px; height:26px;
               border-radius:8px; background:rgba(255,255,255,.92); color:var(--forest);
               display:flex; align-items:center; justify-content:center; font-size:.9rem;
               border:1px solid var(--line); }
  .cara .lupa{ position:absolute; inset:0; z-index:3; display:flex; align-items:center;
               justify-content:center; background:rgba(22,38,30,.55); color:var(--ivory);
               font-size:.82rem; opacity:0; transition:.15s; text-decoration:none; }
  .mod:hover .cara .lupa{ opacity:1; }
  .mod .corpo{ padding:.7rem .8rem; display:flex; flex-direction:column; gap:.3rem; flex:1; }
  .mod .nm{ font-family:var(--serif); font-size:1.02rem; color:var(--ink); line-height:1.25; }
  .mod .meta{ font-size:.76rem; color:#8a8f88; display:flex; gap:.5rem; flex-wrap:wrap; }
  .mod .desc{ font-size:.8rem; color:#6c7570; line-height:1.45; }
  .mod .ac{ display:flex; gap:.35rem; align-items:center; flex-wrap:wrap;
            padding:.6rem .8rem; border-top:1px solid var(--line); margin-top:auto; }
  .mod .ac .btn{ font-size:.78rem; padding:.3rem .6rem; }
  .vazio-mod{ border:1px dashed var(--line); border-radius:14px; padding:2rem 1.2rem;
              text-align:center; color:#8a8f88; font-size:.9rem; line-height:1.6; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.publicado{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.rascunho{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.alcance{ background:#fff; color:#6c7570; text-transform:none; letter-spacing:0; }
  /* Janela das opções de um modelo: as escolhas e a lista de casamentos.
     Vive no modal (ver #ov-modelo), que tem largura para uma lista se ler. */
  .modal-corpo .escolhas{ display:flex; gap:1.4rem; flex-wrap:wrap; margin-bottom:.9rem; }
  .modal-corpo .op{ display:flex; align-items:center; gap:.5rem; font-size:.92rem; color:var(--text);
                    text-transform:none; letter-spacing:0; cursor:pointer; font-weight:400; }
  .modal-corpo .op input{ width:auto; margin:0; accent-color:var(--forest); flex:none; }
  .modal-corpo .lista-cas{ max-height:min(46vh,300px); overflow:auto; border:1px solid var(--line);
                           border-radius:12px; padding:.5rem .7rem; display:flex; flex-direction:column; }
  .modal-corpo .cas-item{ padding:.42rem .2rem; border-bottom:1px solid var(--line); }
  .modal-corpo .cas-item:last-child{ border-bottom:0; }
  .modal-corpo .cas-nome{ flex:1; min-width:0; }
  .modal-corpo .cas-data{ color:#8a8f88; font-size:.82rem; }
  .jan-fim{ display:flex; justify-content:flex-end; gap:.6rem; margin-top:1.2rem;
            border-top:1px solid var(--line); padding-top:1rem; }
  /* Os dados de exemplo: as imagens em fila, cada uma com a sua miniatura. */
  .exs{ display:grid; gap:.8rem; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); margin-top:.9rem; }
  .ex{ border:1px solid var(--line); border-radius:12px; padding:.6rem; background:#fff; }
  /* contain, e não cover: aqui escolhe-se uma imagem, e para a escolher é
     preciso vê-la inteira — a capa é ao alto e o resto ao baixo. */
  .ex .mini{ display:block; width:100%; aspect-ratio:4/3; object-fit:contain; border-radius:8px;
             background:#20211c; border:1px solid var(--line); }
  .ex label{ display:block; margin:.5rem 0 .3rem; font-size:.76rem; text-transform:uppercase;
             letter-spacing:.06em; color:#8a8f88; }
  .ex input[type=file]{ font-size:.76rem; width:100%; }
  .filtros{ display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .chip{ border:1px solid var(--line); background:#fff; color:#6c7570; border-radius:50px;
         padding:.3rem .8rem; font-size:.8rem; font-family:var(--sans); cursor:pointer; }
  .chip.on{ background:var(--forest); border-color:var(--forest); color:var(--ivory); }
  .aviso{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
          border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1rem; line-height:1.5; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .mod{ grid-template-columns:auto 1fr; }
                            .mod .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Modelos de convite', 'Os desenhos que a casa oferece a todos os casais', 'modelos'); ?>

<main class="container">

  <details class="painel dobra" id="d-novo">
    <summary><span class="mais">+</span> Novo modelo
      <small>nasce do desenho de origem e desenha-se no editor</small></summary>
    <div class="dica">
      <?php if ($aberto > 0): ?>
        Nasce do convite de <b><?= escP($nomeAberto) ?></b>, tal como está agora — ou do desenho de
        origem, se preferir começar do princípio. Só o desenho viaja: nomes, datas e convidados
        ficam onde estão. Depois de criado, desenha-se no editor, sem casamento nenhum pelo meio.
      <?php else: ?>
        Nasce do desenho de origem e desenha-se a seguir no editor — sem ter de pedir emprestada
        a casa de um casal para fazer um modelo da casa.
      <?php endif; ?>
    </div>
    <div class="lf">
      <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Clássico verde"></div>
      <div><label>Descrição</label><input type="text" id="n-desc" placeholder="Para quem é, o que tem de particular"></div>
      <div><label>Peça</label>
        <select id="n-ambito"><option value="digital">Convite digital</option>
                              <option value="impresso">Convite impresso</option></select></div>
      <div><button class="btn btn-ouro" onclick="criar()">Criar modelo</button></div>
    </div>
    <div class="dica" style="margin:.7rem 0 0">
      <label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400">
        <input type="checkbox" id="n-visivel" checked style="width:auto;margin:0">
        Publicar já (os casais passam a vê-lo no seletor de versões)
      </label>
      <?php if ($aberto > 0): ?>
      <label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400;margin-left:1.2rem">
        <input type="checkbox" id="n-zero" style="width:auto;margin:0">
        Começar do desenho de origem, e não do convite deste casamento
      </label>
      <?php endif; ?>
    </div>
  </details>

  <details class="painel dobra" id="d-exemplo">
    <summary><span class="mais">+</span> Dados de exemplo dos modelos
      <small>o casal e o evento com que um modelo novo nasce</small></summary>
    <div class="dica">
      Um modelo é um desenho, e serve todos os casais — por isso não pode nascer com o nome, a
      data e as fotografias do casamento onde foi composto. Nasce com <b>estes</b> dados, que não
      são de ninguém, e são os que se veem na prova e na miniatura do modelo.
      <br>Isto vale para os modelos que criar <b>daqui para a frente</b>: os que já existem ficam
      exatamente como estão.
    </div>
    <div class="lf" id="ex-campos"><div class="dica" style="margin:0">A carregar…</div></div>
    <div class="exs" id="ex-imagens"></div>
    <div class="jan-fim">
      <button class="btn" onclick="exemploFabrica()">Repor os de fábrica</button>
      <button class="btn btn-ouro" onclick="guardarExemplo()">Guardar</button>
    </div>
  </details>

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

  <details class="painel dobra" id="d-levar">
    <summary><span class="mais">+</span> Levar e trazer
      <small>guardar os modelos num ficheiro, ou trazê-los de outra instalação</small></summary>
    <div class="dica">Os modelos num ficheiro, para os guardar ou os levar para outra instalação.
      Trazer <b>acrescenta</b>: não substitui nem mistura nada com os que já cá estão.</div>
    <div class="lf" style="grid-template-columns:auto 1fr auto">
      <div><label>&nbsp;</label><a class="btn" href="api.php?action=modelos_exportar">Descarregar os modelos</a></div>
      <div><label for="imp">Trazer de um ficheiro</label>
        <input type="file" id="imp" accept=".json,application/json"></div>
      <div><label>&nbsp;</label><button class="btn" onclick="importar()">Trazer</button></div>
    </div>
    <div class="segredo" id="imp-res" style="display:none;background:var(--gold-pale);
         border:1px dashed var(--gold-soft);border-radius:10px;padding:.8rem .9rem;margin-top:.9rem;font-size:.88rem"></div>
  </details>
</main>

<div class="toast" id="toast"></div>

<!-- As opções de um modelo (mudar o nome, quem o vê) abrem AQUI, e não dentro
     do cartão: um cartão da grelha tem ~260px de largura, e uma lista de
     casamentos com procura espremida nessa coluna ficava ilegível — além de
     esticar a linha inteira da grelha e desalinhar os cartões vizinhos. -->
<div class="overlay" id="ov-modelo">
  <div class="modal">
    <div class="modal-topo">
      <h3 id="ov-titulo">Modelo</h3>
      <button class="fechar" onclick="fechar('ov-modelo')">&times;</button>
    </div>
    <div class="modal-corpo" id="ov-corpo"></div>
  </div>
</div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/menu-mais.js') ?>"></script>
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
    // O estado vazio dizia que o primeiro modelo se faz "do convite de um
    // casamento aberto" — deixou de ser verdade quando os modelos passaram a
    // nascer sem casa emprestada, e mandava o admin abrir um casamento à toa.
    alvo.innerHTML = `<div class="vazio-mod">
      ${AMBITO ? 'Ainda não há modelos desta peça.'
               : 'Ainda não há modelos.'}<br>
      Faça o primeiro em <b>Novo modelo</b>, aqui em cima: nasce do desenho de origem
      ${TEM_CASAMENTO ? 'ou do convite do casamento aberto, ' : ''}e desenha-se a seguir no editor.
    </div>`;
    return;
  }
  alvo.innerHTML = '<div class="grelha">' + d.modelos.map(m => {
    const impresso = m.ambito === 'impresso';
    const editor = (impresso ? 'editor-cartao' : 'convite-editor') + '.php?modelo=' + m.id;
    // A prova é desenhada em tamanho real e encolhida: o cartão tem 720px de
    // largura, o convite digital 640. É por isso que a escala difere.
    const larg = impresso ? 720 : 640, esc0 = impresso ? 0.29 : 0.33;
    const prova = impresso
      ? `modelo-prova.php?modelo=${m.id}`
      : `convite-digital.php?c=EXEMPLO&demo=1&prova=1&modelo=${m.id}`;
    return `<div class="mod${+m.visivel ? '' : ' por-publicar'}">
      <div class="cara">
        <span class="selo" title="${impresso ? 'Convite impresso' : 'Convite digital'}">${impresso ? '&#9635;' : '&#9993;'}</span>
        <iframe src="${prova}" loading="lazy" tabindex="-1" aria-hidden="true" scrolling="no"
                style="width:${larg}px;height:${Math.round(larg*1.5)}px;transform:scale(var(--e))"
                onload="ajustarCara(this)" data-larg="${larg}" data-esc="${esc0}"></iframe>
        <a class="lupa" href="${editor}">Abrir no editor</a>
      </div>
      <div class="corpo">
        <div class="nm">${esc(m.nome)}</div>
        ${m.descricao ? `<div class="desc">${esc(m.descricao)}</div>` : ''}
        <div class="meta">
          <span class="et ${+m.visivel ? 'publicado' : 'rascunho'}">${+m.visivel ? 'publicado' : 'por publicar'}</span>
          ${+m.visivel ? `<span class="et alcance" title="Quem vê este modelo">${
              m.alcance === 'selecionados'
                ? '&#9737; ' + (m.casamentos || []).length + ' casamento' + ((m.casamentos||[]).length===1?'':'s')
                : '&#9737; todos os casais'}</span>` : ''}
          <span>${esc((m.atualizado_em || m.criado_em || '').slice(0,10))}</span>
        </div>
      </div>
      <div class="ac">
        <a class="btn btn-sm btn-ouro" href="${editor}">Desenhar</a>
        <button class="btn btn-sm" onclick="publicar(${m.id}, ${+m.visivel ? 0 : 1})">
          ${+m.visivel ? 'Retirar' : 'Publicar'}</button>
        <span class="mm"><button class="btn btn-sm" title="Mais ações"
              onclick="abrirMais(event,${m.id})"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
          <span class="mm-pop" id="mm-${m.id}" style="display:none">
            <button onclick="quemVe(${m.id})">Quem vê este modelo</button>
            <button onclick="editar(${m.id})">Mudar o nome</button>
            <hr>
            <button class="perigo" onclick="apagar(${m.id}, '${esc(m.nome)}')">Apagar</button>
          </span></span>
      </div>
    </div>`;
  }).join('') + '</div>';
}

/** O menu "⋯" de um modelo. Um de cada vez, e fecha-se ao clicar fora. */
/**
 * Encolhe a prova para caber na moldura. A escala vem da largura real da
 * moldura, e não de um número fixo: a grelha muda de colunas com o ecrã, e
 * uma escala fixa deixava faixas por preencher ou cortava a peça.
 */
function ajustarCara(fr){
  const cx = fr.parentElement; if (!cx) return;
  const e = cx.clientWidth / (+fr.dataset.larg || 720);
  fr.style.setProperty('--e', e);
  fr.style.height = Math.ceil(cx.clientHeight / e) + 'px';
}
addEventListener('resize', () => {
  clearTimeout(window._tCara);
  window._tCara = setTimeout(() => document.querySelectorAll('.cara iframe').forEach(ajustarCara), 150);
});

async function criar(){
  const nome = $('n-nome').value.trim();
  if (!nome) return toast('Dê um nome ao modelo.', true);
  const d = await api('modelo_criar', { method:'POST', body: JSON.stringify({
    nome, descricao: $('n-desc').value.trim(), ambito: $('n-ambito').value,
    visivel: $('n-visivel').checked, do_zero: !!($('n-zero') && $('n-zero').checked) }) });
  if (!d || !d.success) return;
  $('n-nome').value = $('n-desc').value = '';
  toast('Modelo criado. Carregue em «Desenhar» para o compor.');
  carregar();
}

/** Abre a janela das opções de um modelo, com um título e um corpo. */
function abrirModelo(titulo, html){
  document.querySelectorAll('.mm-pop').forEach(x => x.style.display = 'none');
  $('ov-titulo').textContent = titulo;
  $('ov-corpo').innerHTML = html;
  abrir('ov-modelo');
  return $('ov-corpo');
}
function abrir(id){ $(id).classList.add('aberto'); }
function fechar(id){ $(id).classList.remove('aberto'); }
// Clicar no fundo fecha, como nas outras janelas da casa.
document.addEventListener('click', e => {
  const o = $('ov-modelo');
  if (o && e.target === o) fechar('ov-modelo');
});
addEventListener('keydown', e => { if (e.key === 'Escape') fechar('ov-modelo'); });

function editar(id){
  const m = MODELOS[id] || {};
  abrirModelo('Mudar o nome', `
    <div class="campo"><label for="e-nome-${id}">Nome</label>
      <input type="text" id="e-nome-${id}" value="${esc(m.nome)}"></div>
    <div class="campo"><label for="e-desc-${id}">Descrição</label>
      <input type="text" id="e-desc-${id}" value="${esc(m.descricao || '')}"></div>
    <div class="dica" style="margin:.2rem 0 1rem">
      ${TEM_CASAMENTO
        ? `<button class="btn btn-sm" onclick="recapturar(${id})">Trazer o desenho do casamento aberto</button>
           <div style="margin-top:.4rem">Substitui o desenho guardado neste modelo pelo que o
           casamento aberto mostra agora. Os casais que já o usaram não são tocados.</div>`
        : 'Para trocar o desenho deste modelo, abra o casamento onde o desenhou.'}
    </div>
    <div class="jan-fim">
      <button class="btn" onclick="fechar('ov-modelo')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardar(${id})">Guardar</button>
    </div>`);
  $('e-nome-' + id).focus();
}

async function guardar(id, recapturar){
  const m = MODELOS[id] || {};
  const d = await api('modelo_editar', { method:'POST', body: JSON.stringify({
    id, nome: $('e-nome-' + id).value.trim(), descricao: $('e-desc-' + id).value.trim(),
    visivel: +m.visivel ? 1 : 0, recapturar: !!recapturar }) });
  if (d && d.success){ fechar('ov-modelo'); toast('Modelo guardado.'); carregar(); }
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

/** Quem vê este modelo: todos os casais, ou só os casamentos escolhidos. */
async function quemVe(id){
  const m = MODELOS[id] || {};
  abrirModelo('Quem vê «' + m.nome + '»', '<div class="dica" style="margin:0">A carregar casamentos…</div>');
  const d = await api('casamento_lista&estado=ativo');
  const cas = (d && d.casamentos) || [];
  const sel = new Set((m.casamentos || []).map(Number));
  const escolhidos = m.alcance === 'selecionados';
  const aviso = +m.visivel ? '' :
    `<div class="aviso">Este modelo está <b>por publicar</b> — ninguém o vê enquanto não carregar
     em «Publicar». Aqui escolhe-se <b>quem</b> o verá depois.</div>`;
  $('ov-corpo').innerHTML = aviso + `
    <div class="escolhas">
      <label class="op"><input type="radio" name="alc-${id}" value="todos" ${escolhidos?'':'checked'}
             onchange="alternarAlcance(${id})"> Todos os casais</label>
      <label class="op"><input type="radio" name="alc-${id}" value="selecionados" ${escolhidos?'checked':''}
             onchange="alternarAlcance(${id})"> Só os casamentos escolhidos</label>
    </div>
    <div id="cx-cas-${id}" style="display:${escolhidos?'block':'none'}">
      <input type="text" placeholder="Procurar casamento…" oninput="filtrarCas(${id}, this.value)"
             style="margin-bottom:.5rem">
      <div class="lista-cas">
        ${cas.length ? cas.map(c => `<label class="op cas-item" data-nome="${esc((c.nome||'').toLowerCase())}">
            <input type="checkbox" value="${c.id}" ${sel.has(+c.id)?'checked':''}>
            <span class="cas-nome">${esc(c.nome)}</span>
            <span class="cas-data">${esc(c.data_evento ? c.data_evento.slice(0,10) : '')}</span>
          </label>`).join('')
          : '<div class="dica" style="margin:0">Não há casamentos ativos para escolher.</div>'}
      </div>
    </div>
    <div class="jan-fim">
      <button class="btn" onclick="fechar('ov-modelo')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardarVisibilidade(${id})">Guardar</button>
    </div>`;
}
function alternarAlcance(id){
  const esc = document.querySelector(`input[name="alc-${id}"]:checked`).value === 'selecionados';
  $('cx-cas-' + id).style.display = esc ? 'block' : 'none';
}
function filtrarCas(id, q){
  q = (q || '').trim().toLowerCase();
  document.querySelectorAll(`#cx-cas-${id} .cas-item`).forEach(el => {
    el.style.display = !q || el.dataset.nome.includes(q) ? '' : 'none';
  });
}
async function guardarVisibilidade(id){
  const alcance = document.querySelector(`input[name="alc-${id}"]:checked`).value;
  const casamentos = [...document.querySelectorAll(`#cx-cas-${id} input[type=checkbox]:checked`)].map(x => +x.value);
  if (alcance === 'selecionados' && !casamentos.length){
    return toast('Escolha ao menos um casamento, ou deixe em «Todos os casais».', true);
  }
  const d = await api('modelo_visibilidade', { method:'POST', body: JSON.stringify({ id, alcance, casamentos }) });
  if (d && d.success){
    fechar('ov-modelo');
    toast(d.alcance === 'todos' ? 'Passa a ver-se em todos os casais.'
                                : `Passa a ver-se em ${d.casamentos.length} casamento(s).`);
    carregar();
  }
}
async function apagar(id, nome){
  if (!confirm('Apagar o modelo "' + nome + '"?\n\n'
    + 'Os casais que já o usaram ficam como estão — o desenho passou a ser deles.')) return;
  const d = await api('modelo_apagar&id=' + id, { method:'POST' });
  if (d && d.success){ toast('Modelo apagado.'); carregar(); }
}

/* ---- Os dados de exemplo com que um modelo novo nasce -------------------
   Não são de casamento nenhum: vivem na linha 0 das definições. Editá-los não
   toca em modelo nenhum já feito — só nos que se criarem a seguir. */
const EX_ROTULO = {
  'casal.noiva':'Noiva', 'casal.noivo':'Noivo', 'evento.data':'Data',
  'evento.hora':'Hora', 'evento.venue_titulo':'Título do local',
  'evento.local':'Local', 'evento.cidade':'Cidade',
  'media.hero':'Capa', 'media.historia':'História',
  'media.interludio':'Interlúdio', 'media.acesso':'Acesso (QR)'
};
let EX_FABRICA = {};

async function carregarExemplo(){
  const d = await api('modelo_exemplo');
  if (!d || !d.success) return;
  EX_FABRICA = d.fabrica || {};
  pintarExemplo(d.exemplo || {});
}

function pintarExemplo(ex){
  $('ex-campos').innerHTML = Object.keys(EX_ROTULO)
    .filter(k => !k.startsWith('media.'))
    .map(k => `<div><label for="ex-${k}">${EX_ROTULO[k]}</label>
       <input type="${k==='evento.data'?'date':k==='evento.hora'?'time':'text'}"
              id="ex-${k}" value="${esc(ex[k] ?? '')}"></div>`).join('');
  $('ex-imagens').innerHTML = Object.keys(EX_ROTULO)
    .filter(k => k.startsWith('media.'))
    .map(k => `<div class="ex">
       <img class="mini" id="ex-img-${k}" src="${esc(ex[k] ?? '')}" alt="${EX_ROTULO[k]}">
       <label>${EX_ROTULO[k]}</label>
       <input type="file" accept="image/*" onchange="enviarExemplo('${k}', this)">
     </div>`).join('');
}

async function enviarExemplo(chave, el){
  const f = el.files[0];
  if (!f) return;
  const fd = new FormData();
  fd.append('chave', chave); fd.append('ficheiro', f);
  const d = await api('modelo_exemplo_upload', { method:'POST', body: fd });
  el.value = '';
  if (!d || !d.success) return;
  $('ex-img-' + chave).src = d.path + '?t=' + Date.now();
  toast('Imagem de exemplo trocada.');
}

async function guardarExemplo(){
  const corpo = {};
  Object.keys(EX_ROTULO).filter(k => !k.startsWith('media.'))
        .forEach(k => corpo[k] = $('ex-' + k).value);
  const d = await api('modelo_exemplo_guardar', { method:'POST', body: JSON.stringify(corpo) });
  if (d && d.success){ pintarExemplo(d.exemplo); toast('Dados de exemplo guardados.'); }
}

async function exemploFabrica(){
  if (!confirm('Repor o casal, o evento e as imagens de exemplo tal como vêm de fábrica?')) return;
  const corpo = {};
  Object.keys(EX_ROTULO).forEach(k => corpo[k] = EX_FABRICA[k] ?? '');
  const d = await api('modelo_exemplo_guardar', { method:'POST', body: JSON.stringify(corpo) });
  if (d && d.success){ pintarExemplo(d.exemplo); toast('Reposto o de fábrica.'); }
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
carregarExemplo();
</script>
</body>
</html>
