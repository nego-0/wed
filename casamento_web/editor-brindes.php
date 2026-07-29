<?php
// ============================================================
// editor-brindes.php — Edição dos brindes por género
// Mesmo ambiente do editor do cartão. Permite escolher a peça
// atribuída a cada género, quais das variações ficam disponíveis
// para a gráfica e a quantidade a produzir de cada uma.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

// Quantos convidados recebem brinde, por género (guia para as quantidades).
$porGenero = [];
if (colunaExiste($conn, "{$P}convidados", 'brinde') && colunaExiste($conn, "{$P}convidados", 'genero')) {
    $r = $conn->query("SELECT COALESCE(genero,'') AS g, COUNT(*) AS n
                       FROM {$P}convidados WHERE brinde=1 GROUP BY COALESCE(genero,'')");
    if ($r) while ($x = $r->fetch_assoc()) $porGenero[$x['g']] = (int)$x['n'];
}
$semGenero = (int)($porGenero[''] ?? 0);

$generos = brindesGeneros();
$brindes = brindesPorGenero($defs, $porGenero);

// Tudo o que o editor precisa de saber, por género e por peça.
$estado = [];
foreach ($generos as $g => $rot) {
    $pid = $brindes[$g]['peca_id'];
    $sel = $pid ? brindeSelecao($defs, $g, $pid) : [];
    $estado[$g] = [
        'rotulo'     => $rot,
        'peca'       => $pid,
        'selecao'    => (object)$sel,      // {indice: quantidade}
        'convidados' => (int)($porGenero[$g] ?? 0),
    ];
}
$catalogo = [];
foreach (brindesPecas() as $k => $p) {
    $catalogo[$k] = ['nome' => $p['nome'], 'medida' => $p['medida'], 'material' => $p['material'],
                     'pagina' => $p['pagina'], 'variacoes' => pecaVariacoes($k)];
}

$ac      = chaveiroAcabamento($defs['chaveiro.acabamento']);
$quadras = chaveiroQuadras();
$gInicial = array_key_first($generos);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Editar brindes · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<link href="assets/editor.css" rel="stylesheet">
<style>
  /* Pré-visualização do verso, com a quadra da variação escolhida */
  .palco-peca{ background:<?= $ac['fundo'] ?>; border-radius:14px; overflow:hidden;
               width:calc(250px * var(--esc,1)); height:calc(340px * var(--esc,1)); }
  .palco-peca .escala{ width:250px; height:340px; transform:scale(var(--esc,1)); transform-origin:top left; }
  .lado{ display:flex; gap:.3rem; justify-content:center; margin-bottom:.7rem; }
</style>
</head>
<body class="editor">

<div class="ed-menu">
  <div class="marca"><span class="ed-mono"><?= escP($CAS['mono']) ?></span> Editor</div>
  <span class="doc">Brindes · <b>por género</b></span>
  <div class="cresce"></div>
  <a href="graficas.php?aba=brindes">← Entregáveis à gráfica</a>
  <span class="ed-sep"></span>
  <a href="porta-chaves.php">Ver a peça</a>
</div>

<div class="ed-opcoes">
  <div class="docs" id="docs">
    <?php foreach ($generos as $g => $rot): ?>
      <button class="doc-aba <?= $g === $gInicial ? 'on' : '' ?>" data-g="<?= $g ?>"><?= escP($rot) ?></button>
    <?php endforeach; ?>
  </div>
  <span class="ed-sep"></span>
  <span class="rot">Peça</span>
  <select id="peca" style="background:#191a16;border:1px solid var(--ed-linha);color:var(--ed-texto);border-radius:6px;padding:.28rem .5rem;font-family:inherit">
    <option value="">— por definir —</option>
    <?php foreach ($catalogo as $k => $p): ?>
      <option value="<?= $k ?>"><?= escP($p['nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <span class="ed-sep"></span>
  <button class="bt bt-min" onclick="todas(true)">Ativar todas</button>
  <button class="bt bt-min" onclick="todas(false)">Desativar todas</button>
  <button class="bt bt-min" onclick="distribuir()" title="Reparte os convidados pelas variações ativas">Repartir pelos convidados</button>
  <div class="cresce"></div>
  <button class="bt" onclick="repor()">Repor</button>
  <button class="bt primario" id="bt-guardar" onclick="guardar()">Guardar</button>
</div>

<div class="ed-corpo">
  <div class="ed-ferramentas">
    <button class="ferr on" title="Variações"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg></button>
    <button class="ferr" onclick="virar()" title="Virar a peça"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M21 4v4h-4M3 20v-4h4"/></svg></button>
  </div>

  <div class="ed-mesa" id="mesa">
    <div>
      <div class="lado"><span class="rot" id="rot-lado">Verso</span></div>
      <div class="ed-arte"><div class="palco-peca" style="--esc:1.15"><div class="escala" id="peca-vista"></div></div></div>
      <div style="text-align:center;color:var(--ed-texto-2);margin-top:.6rem;font-size:.8rem" id="legenda-var"></div>
    </div>
  </div>

  <div class="ed-paineis">
    <div class="ed-painel">
      <h3 onclick="alternarPainel(this)">Produção <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="producao"></div>
    </div>
    <div class="ed-painel cresce">
      <h3 onclick="alternarPainel(this)">Variações disponíveis <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="variacoes"></div>
    </div>
  </div>
</div>

<div class="ed-estado">
  <span id="estado-peca"></span>
  <span class="cresce"></span>
  <span id="estado-msg"></span>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const $ = id => document.getElementById(id);
const CATALOGO  = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
const SEM_GENERO = <?= (int)$semGenero ?>;
const FRENTE_HTML = <?= json_encode(renderChaveiroFrente($ac, $defs, inicialU($defs['casal.noiva']), inicialU($defs['casal.noivo']), date('d · m · Y', strtotime($defs['evento.data'])), 140, true), JSON_UNESCAPED_UNICODE) ?>;
const VERSOS = <?= json_encode(array_map(fn($q) => renderChaveiroVerso($ac, $defs, $q, true), $quadras), JSON_UNESCAPED_UNICODE) ?>;

let est = <?= json_encode($estado, JSON_UNESCAPED_UNICODE) ?>;
const original = JSON.parse(JSON.stringify(est));
let g = <?= json_encode($gInicial) ?>;
let varSel = null, lado = 'verso', sujo = false;

function marcarSujo(v){ sujo=v; $('estado-msg').textContent = v ? 'Alterações por guardar' : ''; $('estado-msg').className = v ? 'sujo' : ''; }
function msg(t){ $('estado-msg').textContent = t; $('estado-msg').className=''; }
function alternarPainel(h){ h.parentElement.classList.toggle('fechado'); }
function pecaAtual(){ return CATALOGO[est[g].peca] || null; }

// ---------- Vista da peça ----------
function renderVista(){
  const p = pecaAtual();
  if (!p) { $('peca-vista').innerHTML = ''; $('legenda-var').textContent = 'Sem peça atribuída a este género.'; $('rot-lado').textContent=''; return; }
  $('rot-lado').textContent = lado === 'verso' ? 'Verso' : 'Frente';
  if (lado === 'frente') { $('peca-vista').innerHTML = FRENTE_HTML; $('legenda-var').textContent = 'A frente é igual em todas as variações.'; return; }
  const i = varSel !== null ? varSel : (Object.keys(est[g].selecao)[0] ?? 0);
  $('peca-vista').innerHTML = VERSOS[i] ?? '';
  const v = p.variacoes.find(x => x.id === +i);
  $('legenda-var').textContent = v ? ('Variação ' + v.rotulo) : '';
}
function virar(){ lado = lado === 'verso' ? 'frente' : 'verso'; renderVista(); }

// ---------- Painel de produção ----------
function renderProducao(){
  const p = pecaAtual(), e = est[g];
  const ativas = Object.keys(e.selecao).length;
  const total = Object.values(e.selecao).reduce((s,q)=>s+(+q||0),0);
  let html = '';
  if (!p) {
    html = `<div class="vazio-painel">Nenhuma peça atribuída ao género <b>${e.rotulo}</b>.<br>
            Escolha uma peça na barra de cima para começar. O sistema fica aberto a novas peças —
            basta registá-las no catálogo.</div>`;
  } else {
    const falta = e.convidados - total;
    html = `<div class="campo"><label>Peça</label><div>${p.nome}<div class="ajuda">${p.medida} · ${p.material}</div></div></div>
      <div class="campo"><label>Convidados que recebem</label><div><b>${e.convidados}</b> ${e.convidados===1?'convidado':'convidados'}</div></div>
      <div class="campo"><label>Variações ativas</label><div><b>${ativas}</b> de ${p.variacoes.length}</div></div>
      <div class="campo"><label>Total a produzir</label><div><b>${total}</b> ${total===1?'peça':'peças'}</div></div>`;
    if (e.convidados === 0) {
      html += `<div class="aviso alerta">Ainda ninguém deste género está marcado como <b>“Recebe brinde”</b> no painel.</div>`;
    } else if (falta === 0) {
      html += `<div class="aviso ok">As quantidades cobrem exatamente os ${e.convidados} convidados.</div>`;
    } else if (falta > 0) {
      html += `<div class="aviso alerta">Faltam <b>${falta}</b> ${falta===1?'peça':'peças'} para cobrir os ${e.convidados} convidados.</div>`;
    } else {
      html += `<div class="aviso alerta">Há <b>${-falta}</b> ${-falta===1?'peça':'peças'} a mais do que convidados (margem de reserva).</div>`;
    }
    if (ativas === 0) html += `<div class="aviso alerta">Sem variações ativas, a gráfica não recebe nada para produzir.</div>`;
  }
  if (SEM_GENERO > 0) html += `<div class="aviso alerta">${SEM_GENERO} convidado(s) com brinde mas <b>sem género</b> não entram em nenhuma contagem.</div>`;
  $('producao').innerHTML = html;
  $('estado-peca').textContent = p ? (e.rotulo + ' · ' + p.nome) : (e.rotulo + ' · sem peça');
}

// ---------- Painel de variações ----------
function renderVariacoes(){
  const p = pecaAtual(), e = est[g];
  if (!p) { $('variacoes').innerHTML = '<div class="vazio-painel">Escolha primeiro uma peça.</div>'; return; }
  $('variacoes').innerHTML = p.variacoes.map(v => {
    const on = Object.prototype.hasOwnProperty.call(e.selecao, v.id);
    const q = on ? e.selecao[v.id] : '';
    return `<div class="variacao ${on?'on':'desligada'} ${varSel===v.id?'sel':''}" onclick="verVariacao(${v.id})">
      <input type="checkbox" ${on?'checked':''} onclick="event.stopPropagation();alternarVar(${v.id})" title="Disponível para a gráfica">
      <div class="txt"><div class="rom">Variação ${v.rotulo}</div><div class="frase">${escaparHtml(v.texto)}</div></div>
      <div class="qtd"><input type="number" min="0" max="9999" value="${q}" ${on?'':'disabled'}
           onclick="event.stopPropagation()" oninput="mudarQtd(${v.id}, this.value)" title="Quantidade a produzir"></div>
    </div>`;
  }).join('');
}
function escaparHtml(s){ return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

function alternarVar(i){
  const e = est[g];
  if (Object.prototype.hasOwnProperty.call(e.selecao, i)) delete e.selecao[i];
  else e.selecao[i] = 0;
  marcarSujo(true); renderVariacoes(); renderProducao();
}
function mudarQtd(i, v){
  est[g].selecao[i] = Math.max(0, Math.min(9999, parseInt(v || '0', 10) || 0));
  marcarSujo(true); renderProducao();
}
function verVariacao(i){ varSel = i; lado = 'verso'; renderVista(); renderVariacoes(); }
function todas(ligar){
  const p = pecaAtual(); if (!p) return;
  const e = est[g];
  if (ligar) p.variacoes.forEach(v => { if (!(v.id in e.selecao)) e.selecao[v.id] = 0; });
  else e.selecao = {};
  marcarSujo(true); renderVariacoes(); renderProducao();
}
// Reparte os convidados pelas variações ativas (resto distribuído pelas primeiras)
function distribuir(){
  const e = est[g], ids = Object.keys(e.selecao).map(Number).sort((a,b)=>a-b);
  if (!ids.length) return alert('Ative primeiro pelo menos uma variação.');
  if (!e.convidados) return alert('Ainda não há convidados deste género marcados como “Recebe brinde”.');
  const base = Math.floor(e.convidados / ids.length), resto = e.convidados % ids.length;
  ids.forEach((id, k) => { e.selecao[id] = base + (k < resto ? 1 : 0); });
  marcarSujo(true); renderVariacoes(); renderProducao();
}

// ---------- Género e peça ----------
document.getElementById('docs').addEventListener('click', ev => {
  const b = ev.target.closest('.doc-aba'); if (!b) return;
  g = b.dataset.g; varSel = null;
  document.querySelectorAll('.doc-aba').forEach(x => x.classList.toggle('on', x === b));
  $('peca').value = est[g].peca || '';
  renderTudo();
});
$('peca').addEventListener('change', ev => {
  est[g].peca = ev.target.value;
  est[g].selecao = {};                      // peça nova, seleção limpa
  const p = pecaAtual();
  if (p) p.variacoes.forEach(v => { est[g].selecao[v.id] = 0; });   // por omissão, todas disponíveis
  varSel = null; marcarSujo(true); renderTudo();
});

function renderTudo(){ renderProducao(); renderVariacoes(); renderVista(); }

// ---------- Guardar / repor ----------
async function guardar(){
  const defs = {};
  Object.entries(est).forEach(([gen, e]) => {
    defs['brindes.' + gen + '.peca'] = e.peca || '';
    defs['brindes.' + gen + '.variacoes'] = Object.keys(e.selecao).length ? JSON.stringify(e.selecao) : '';
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
  if (!confirm('Repor as escolhas como estavam ao abrir o editor?')) return;
  est = JSON.parse(JSON.stringify(original));
  $('peca').value = est[g].peca || ''; varSel = null;
  renderTudo(); marcarSujo(false); msg('Reposto (por guardar).');
}
window.addEventListener('beforeunload', e => { if (sujo) { e.preventDefault(); e.returnValue = ''; } });

$('peca').value = est[g].peca || '';
renderTudo();
</script>
</body>
</html>
