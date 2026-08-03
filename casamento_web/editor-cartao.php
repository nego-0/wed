<?php
// ============================================================
// editor-cartao.php — Edição do convite físico (cartão 10×15)
// Ambiente ao estilo de um editor de imagem: ferramentas à
// esquerda, mesa de trabalho ao centro, camadas e propriedades
// à direita. A pré-visualização é ao vivo (sem recarregar).
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

$pal      = cartaoPaleta($defs['cartao.paleta']);
$folhagem = $defs['cartao.folhagem'];
$camadas  = cartaoCamadasVisiveis($defs);
$ev       = cartaoDadosEvento($defs);

// Convite de exemplo: usa um real (físico) para a prova ser fiel.
$r = $conn->query("SELECT c.*, m.nome AS mesa_nome FROM {$P}convites c
                   LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                   WHERE c.tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."
                   ORDER BY c.nome_exibicao LIMIT 1");
$exemplo = $r ? $r->fetch_assoc() : null;
$comNumeroNome = ($defs['cartao.numero_no_nome'] ?? '1') === '1';
if ($exemplo) {
    $conv = ['nome' => nomeParaCartao($exemplo, $comNumeroNome), 'mesas' => mesasDoConvite($conn, $exemplo)];
    $comLug = !isset($exemplo['mostrar_num_mesa']) || (int)$exemplo['mostrar_num_mesa'] === 1;
} else {
    $conv = ['nome' => 'Família Agostinho', 'mesas' => [['nome'=>'Mesa Luar','n'=>1], ['nome'=>'Mesa Solar','n'=>4]]];
    $comLug = true;
}

$nomesProva = $exemplo
    ? ['com' => nomeParaCartao($exemplo, true), 'sem' => nomeParaCartao($exemplo, false)]
    : ['com' => $conv['nome'], 'sem' => $conv['nome']];

// Trepadeiras de todas as folhagens: trocar de folhagem não volta ao servidor.
$ramosJs = [];
foreach (cartaoFolhagens() as $k => $f) $ramosJs[$k] = svgTrepadeira($k, 'currentColor');

// Campos de texto editáveis, por camada
$camposPorCamada = [
    'abertura'  => [['cartao.abertura', 'Texto de abertura', 'area', 'abertura']],
    'nomes'     => [['casal.noiva', 'Nome da noiva', 'texto', 'noiva'], ['casal.noivo', 'Nome do noivo', 'texto', 'noivo']],
    'frase'     => [['cartao.frase_convite', 'Frase de convite', 'area', 'frase']],
    'convidado' => [['cartao.reservado', 'Rótulo', 'texto', 'reservado'],
                    ['cartao.numero_no_nome', 'Mostrar o (N) de lugares no nome', 'bool', '']],
    'logistica' => [['cartao.civil_titulo', 'Cerimónia', 'texto', 'civil_titulo'],
                    ['cartao.civil_hora', 'Hora da cerimónia (HH:MM)', 'hora', ''],
                    ['evento.venue_titulo', 'Receção', 'texto', 'copo_titulo'],
                    ['evento.local', 'Local', 'texto', ''],
                    ['evento.hora', 'Hora da receção (HH:MM)', 'hora', '']],
    'fecho'     => [['cartao.frase_final', 'Frase final', 'area', 'frase_final']],
    'data'      => [['evento.data', 'Data do evento', 'data', '']],
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Editar convite físico · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<link href="assets/editor.css" rel="stylesheet">
</head>
<body class="editor">

<div class="ed-menu">
  <div class="marca"><span class="ed-mono"><?= escP($CAS['mono']) ?></span> Editor</div>
  <span class="doc">Convite físico · <b>Cartão 10 × 15 cm</b></span>
  <div class="cresce"></div>
  <a href="graficas.php">← Entregáveis à gráfica</a>
  <span class="ed-sep"></span>
  <a href="cartoes.php">Ver todos os cartões</a>
</div>

<div class="ed-opcoes">
  <span class="rot">Paleta</span>
  <div class="amostras" id="amostras">
    <?php foreach (cartaoPaletas() as $k => $p): ?>
      <button class="amostra <?= $k === $defs['cartao.paleta'] ? 'on' : '' ?>" data-paleta="<?= $k ?>"
              title="<?= escP($p['nome']) ?>" style="background:<?= $p['accent'] ?>"></button>
    <?php endforeach; ?>
  </div>
  <span class="ed-sep"></span>
  <span class="rot">Folhagem</span>
  <select id="folhagem" style="background:#191a16;border:1px solid var(--ed-linha);color:var(--ed-texto);border-radius:6px;padding:.28rem .5rem;font-family:inherit">
    <?php foreach (cartaoFolhagens() as $k => $f): ?>
      <option value="<?= $k ?>" <?= $k === $folhagem ? 'selected' : '' ?>><?= escP($f['nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <span class="ed-sep"></span>
  <div class="zoom">
    <button class="bt bt-min" onclick="zoomPasso(-1)" title="Reduzir">−</button>
    <span class="val" id="zoom-val">—</span>
    <button class="bt bt-min" onclick="zoomPasso(1)" title="Ampliar">+</button>
    <button class="bt bt-min" onclick="ajustar()" title="Ajustar à janela">Ajustar</button>
  </div>
  <div class="cresce"></div>
  <button class="bt" onclick="repor()">Repor originais</button>
  <button class="bt primario" id="bt-guardar" onclick="guardar()">Guardar</button>
</div>

<div class="ed-corpo">
  <!-- Ferramentas -->
  <div class="ed-ferramentas">
    <button class="ferr on" data-ferr="mover" title="Selecionar camada (V)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 3l7 17 2.5-6.5L20 11z"/></svg>
    </button>
    <button class="ferr" data-ferr="texto" title="Editar texto (T)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h14M12 5v14M9 19h6"/></svg>
    </button>
    <button class="ferr" data-ferr="mao" title="Mover a vista (H)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M9 11V5.5a1.5 1.5 0 0 1 3 0V11m0-1.5V4.5a1.5 1.5 0 0 1 3 0V11m0-1a1.5 1.5 0 0 1 3 0v5a6 6 0 0 1-6 6h-1a6 6 0 0 1-6-6v-3a1.5 1.5 0 0 1 3 0"/></svg>
    </button>
    <button class="ferr" data-ferr="zoom" title="Ampliar (Z)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M8 11h6M11 8v6"/></svg>
    </button>
  </div>

  <!-- Mesa de trabalho -->
  <div class="ed-mesa" id="mesa">
    <div class="ed-arte marcar" id="arte" style="--esc:.5">
      <div class="escala" id="escala" style="width:720px;height:1080px;transform:scale(var(--esc));transform-origin:top left">
        <?= renderCartaoConvite($ev, $conv, $pal, $folhagem, $comLug, $camadas) ?>
      </div>
    </div>
  </div>

  <!-- Painéis -->
  <div class="ed-paineis">
    <div class="ed-painel" id="p-props">
      <h3 onclick="alternarPainel(this)">Propriedades <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="props">
        <div class="vazio-painel">Escolha uma camada — na lista abaixo ou clicando no cartão — para editar o que ela mostra.</div>
      </div>
    </div>
    <div class="ed-painel cresce">
      <h3 onclick="alternarPainel(this)">Camadas <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="camadas"></div>
    </div>
  </div>
</div>

<div class="ed-estado">
  <span>Cartão 10 × 15 cm · 720 × 1080 px</span>
  <span class="ed-sep"></span>
  <span id="estado-exemplo">Prova com: <?= escP($conv['nome']) ?></span>
  <span class="cresce"></span>
  <span id="estado-msg"></span>
</div>

<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const $ = id => document.getElementById(id);

const PALETAS  = <?= json_encode(cartaoPaletas(), JSON_UNESCAPED_UNICODE) ?>;
const RAMOS    = <?= json_encode($ramosJs, JSON_UNESCAPED_UNICODE) ?>;
const CAMADAS  = <?= json_encode(cartaoCamadas(), JSON_UNESCAPED_UNICODE) ?>;
const CAMPOS   = <?= json_encode($camposPorCamada, JSON_UNESCAPED_UNICODE) ?>;
const ORNAMENTOS = ['ramos','volutas','moldura','floreados'];   // camadas sem texto
const NOMES_PROVA = <?= json_encode($nomesProva, JSON_UNESCAPED_UNICODE) ?>;
const MESES    = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const DIAS     = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];

// Estado do documento
let est = {
  paleta:   <?= json_encode($defs['cartao.paleta']) ?>,
  folhagem: <?= json_encode($folhagem) ?>,
  camadas:  <?= json_encode($camadas) ?>,
  textos:   <?= json_encode(array_intersect_key($defs, array_flip([
                 'cartao.abertura','cartao.frase_convite','cartao.reservado','cartao.civil_titulo',
                 'cartao.civil_hora','cartao.frase_final','casal.noiva','casal.noivo',
                 'evento.venue_titulo','evento.local','evento.hora','evento.data','cartao.numero_no_nome'])), JSON_UNESCAPED_UNICODE) ?>
};
const original = JSON.parse(JSON.stringify(est));
let sujo = false, selecionada = null, ferramenta = 'mover';

function marcarSujo(v){ sujo = v; $('estado-msg').textContent = v ? 'Alterações por guardar' : ''; $('estado-msg').className = v ? 'sujo' : ''; }
function msg(t){ $('estado-msg').textContent = t; $('estado-msg').className = ''; }

// ---------- Zoom ----------
const PASSOS = [.25,.33,.5,.66,.75,1,1.5,2];
let zoom = .5;
function aplicarZoom(){ $('arte').style.setProperty('--esc', zoom);
  $('escala').style.transform = 'scale(' + zoom + ')';
  $('arte').style.width  = Math.round(720*zoom)+'px';
  $('arte').style.height = Math.round(1080*zoom)+'px';
  $('zoom-val').textContent = Math.round(zoom*100)+'%'; }
function zoomPasso(d){ const i = PASSOS.findIndex(p => p >= zoom - .001);
  const j = Math.max(0, Math.min(PASSOS.length-1, (i<0?5:i)+d)); zoom = PASSOS[j]; aplicarZoom(); }
function ajustar(){ const m = $('mesa').getBoundingClientRect();
  zoom = Math.max(.1, Math.min(2, Math.min((m.height-56)/1080, (m.width-56)/720))); aplicarZoom(); }

// ---------- Paleta e folhagem (ao vivo, sem servidor) ----------
function aplicarPaleta(k){
  const p = PALETAS[k] || PALETAS.ouro; est.paleta = k;
  const c = document.querySelector('#escala .cartao');
  c.style.setProperty('--ct-accent', p.accent);
  c.style.setProperty('--ct-name',   p.nameColor);
  c.style.setProperty('--ct-sub',    p.sub);
  c.style.setProperty('--ct-head',   p.head);
  c.style.setProperty('--ct-soft',   p.soft);
  document.querySelectorAll('#amostras .amostra').forEach(b => b.classList.toggle('on', b.dataset.paleta === k));
}
function aplicarFolhagem(k){
  est.folhagem = k;
  document.querySelectorAll('#escala .ct-ramo').forEach(r => { r.innerHTML = RAMOS[k] || ''; });
}

// ---------- Camadas ----------
function renderCamadas(){
  $('camadas').innerHTML = Object.entries(CAMADAS).map(([k, rot]) => {
    const vis = est.camadas[k] !== 0;
    return `<div class="camada ${selecionada===k?'sel':''} ${vis?'':'oculta'}" data-k="${k}" onclick="selecionar('${k}')">
      <button class="olho" onclick="event.stopPropagation();alternarCamada('${k}')" title="${vis?'Ocultar':'Mostrar'}">
        ${vis
          ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="2.8"/></svg>'
          : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l16 16M10.7 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.4 8.3A17 17 0 0 0 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"/></svg>'}
      </button>
      <span class="nome">${rot}</span>
      <span class="mini" title="${ORNAMENTOS.includes(k)?'Camada decorativa':'Camada de texto'}">${ORNAMENTOS.includes(k)?'◈':'T'}</span>
    </div>`;
  }).join('');
}
function alternarCamada(k){
  est.camadas[k] = est.camadas[k] === 0 ? 1 : 0;
  const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
  if (alvo) alvo.classList.toggle('ct-oculta', est.camadas[k] === 0);
  renderCamadas(); marcarSujo(true);
}
function selecionar(k){
  selecionada = k;
  document.querySelectorAll('#escala [data-camada]').forEach(n => n.classList.remove('sel-camada'));
  const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
  if (alvo) alvo.classList.add('sel-camada');
  renderCamadas(); renderProps();
}

// ---------- Propriedades ----------
function renderProps(){
  const campos = CAMPOS[selecionada];
  if (!selecionada) { $('props').innerHTML = '<div class="vazio-painel">Escolha uma camada — na lista abaixo ou clicando no cartão — para editar o que ela mostra.</div>'; return; }
  const rot = CAMADAS[selecionada];
  if (!campos) {
    $('props').innerHTML = `<div class="vazio-painel"><b>${rot}</b><br>Camada decorativa: não tem texto para editar.
      Use o olho na lista de camadas para a mostrar ou ocultar, e a paleta para lhe mudar a cor.</div>`;
    return;
  }
  $('props').innerHTML = `<div class="vazio-painel" style="margin-bottom:.6rem"><b>${rot}</b></div>` + campos.map(([chave, rotulo, tipo]) => {
    const v = est.textos[chave] ?? '';
    if (tipo === 'bool') {
      return `<div class="campo"><label style="display:flex;align-items:center;gap:.45rem;text-transform:none;letter-spacing:0">
        <input type="checkbox" data-chave="${chave}" ${String(v)==='1'?'checked':''} onchange="editarBool(this)"
               style="width:15px;height:15px;accent-color:var(--ed-ouro);cursor:pointer">
        ${rotulo}</label>
        <div class="ajuda">No cartão, os lugares já aparecem por baixo de cada mesa.</div></div>`;
    }
    const ctl = tipo === 'area'
      ? `<textarea data-chave="${chave}" oninput="editarTexto(this)">${escaparHtml(v)}</textarea>`
      : `<input type="${tipo==='data'?'date':(tipo==='hora'?'time':'text')}" data-chave="${chave}" value="${escaparAttr(v)}" oninput="editarTexto(this)">`;
    return `<div class="campo"><label>${rotulo}</label>${ctl}</div>`;
  }).join('');
}
function escaparHtml(s){ return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escaparAttr(s){ return String(s).replace(/["&<>]/g, c => ({'"':'&quot;','&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

// Escreve no cartão à medida que se escreve no campo
function editarTexto(el){
  const chave = el.dataset.chave, valor = el.value;
  est.textos[chave] = valor;
  pintarTexto(chave, valor);
  marcarSujo(true);
}
function editarBool(el){
  const chave = el.dataset.chave, valor = el.checked ? '1' : '0';
  est.textos[chave] = valor;
  pintarTexto(chave, valor);
  marcarSujo(true);
}
function pintarTexto(chave, valor){
  const c = document.querySelector('#escala .cartao');
  const porCampo = {
    'cartao.abertura':'abertura', 'cartao.frase_convite':'frase', 'cartao.reservado':'reservado',
    'cartao.civil_titulo':'civil_titulo', 'cartao.frase_final':'frase_final',
    'casal.noiva':'noiva', 'casal.noivo':'noivo', 'evento.venue_titulo':'copo_titulo'
  };
  if (porCampo[chave]) {
    const n = c.querySelector(`[data-campo="${porCampo[chave]}"]`);
    if (n) n.innerHTML = chave === 'cartao.abertura' ? escaparHtml(valor).replace(/\n/g,'<br>') : escaparHtml(valor);
    return;
  }
  // Campos compostos: hora e local entram nas linhas de detalhe; a data é escrita por extenso.
  if (chave === 'cartao.civil_hora') { const n = c.querySelectorAll('.ct-detalhe')[0]; if (n) n.textContent = 'às ' + horaPt(valor); return; }
  if (chave === 'evento.hora' || chave === 'evento.local') {
    const n = c.querySelector('.ct-detalhe-2');
    if (n) n.innerHTML = escaparHtml(est.textos['evento.local'] || '') + '<br>às ' + horaPt(est.textos['evento.hora'] || '');
    return;
  }
  if (chave === 'cartao.numero_no_nome') {
    const n = c.querySelector('.ct-convidado');
    if (n) n.textContent = valor === '1' ? NOMES_PROVA.com : NOMES_PROVA.sem;
    return;
  }
  if (chave === 'evento.data') {
    const d = new Date(valor + 'T12:00:00');
    if (isNaN(d)) return;
    const dt = c.querySelector('.ct-data'), di = c.querySelector('.ct-dia');
    if (dt) dt.textContent = d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear();
    if (di) di.textContent = DIAS[d.getDay()];
  }
}
function horaPt(hhmm){ const [h,m] = String(hhmm||'').split(':'); if(h===undefined) return ''; return (+h)+'h'+(+m?String(m).padStart(2,'0'):''); }

// ---------- Ferramentas ----------
document.querySelectorAll('.ferr').forEach(b => b.addEventListener('click', () => {
  ferramenta = b.dataset.ferr;
  document.querySelectorAll('.ferr').forEach(x => x.classList.toggle('on', x === b));
  $('mesa').classList.toggle('mao', ferramenta === 'mao');
  $('arte').classList.toggle('marcar', ferramenta === 'mover' || ferramenta === 'texto');
  if (ferramenta === 'texto' && selecionada) { const t = $('props').querySelector('input,textarea'); if (t) t.focus(); }
}));
document.addEventListener('keydown', e => {
  if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
  const m = { v:'mover', t:'texto', h:'mao', z:'zoom' }[e.key.toLowerCase()];
  if (m) { document.querySelector(`.ferr[data-ferr="${m}"]`).click(); }
  if (e.key === '0') ajustar();
});

// Clicar no cartão seleciona a camada (ou amplia, com a ferramenta de zoom)
$('escala').addEventListener('click', e => {
  if (ferramenta === 'zoom') { zoomPasso(e.shiftKey ? -1 : 1); return; }
  const alvo = e.target.closest('[data-camada]');
  if (alvo) selecionar(alvo.dataset.camada);
});
// Ferramenta "mão": arrastar a vista
(() => {
  let a = null;
  $('mesa').addEventListener('pointerdown', e => {
    if (ferramenta !== 'mao') return;
    a = { x:e.clientX, y:e.clientY, l:$('mesa').scrollLeft, t:$('mesa').scrollTop };
    e.preventDefault();
  });
  window.addEventListener('pointermove', e => {
    if (!a) return;
    $('mesa').scrollLeft = a.l - (e.clientX - a.x);
    $('mesa').scrollTop  = a.t - (e.clientY - a.y);
  });
  window.addEventListener('pointerup', () => { a = null; });
})();

function alternarPainel(h){ h.parentElement.classList.toggle('fechado'); }

// ---------- Guardar / repor ----------
async function guardar(){
  const defs = Object.assign({}, est.textos, {
    'cartao.paleta': est.paleta,
    'cartao.folhagem': est.folhagem,
    'cartao.camadas': JSON.stringify(est.camadas)
  });
  $('bt-guardar').disabled = true; msg('A guardar…');
  try {
    const r = await fetch('api.php?action=defs_save', {method:'POST', headers:{'X-CSRF-Token':CSRF}, body: JSON.stringify({defs})});
    const d = await r.json();
    if (!d.success) throw new Error(d.message || 'erro');
    marcarSujo(false); msg('Guardado.');
    setTimeout(() => { if(!sujo) msg(''); }, 2500);
  } catch (err) { msg('Não foi possível guardar.'); }
  $('bt-guardar').disabled = false;
}
function repor(){
  if (!confirm('Repor todos os valores como estavam ao abrir o editor?')) return;
  est = JSON.parse(JSON.stringify(original));
  aplicarPaleta(est.paleta); aplicarFolhagem(est.folhagem);
  $('folhagem').value = est.folhagem;
  Object.entries(est.textos).forEach(([k,v]) => pintarTexto(k,v));
  Object.entries(est.camadas).forEach(([k,v]) => {
    const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
    if (alvo) alvo.classList.toggle('ct-oculta', v === 0);
  });
  renderCamadas(); renderProps(); marcarSujo(false); msg('Reposto (por guardar).');
}
window.addEventListener('beforeunload', e => { if (sujo) { e.preventDefault(); e.returnValue = ''; } });

// Ligações
document.getElementById('amostras').addEventListener('click', e => {
  const b = e.target.closest('.amostra'); if (!b) return;
  aplicarPaleta(b.dataset.paleta); marcarSujo(true);
});
$('folhagem').addEventListener('change', e => { aplicarFolhagem(e.target.value); marcarSujo(true); });
window.addEventListener('resize', () => { if (zoom <= .5) ajustar(); });

renderCamadas(); renderProps(); ajustar();
</script>
</body>
</html>
