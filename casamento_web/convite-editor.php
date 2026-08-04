<?php
// ============================================================
// convite-editor.php — Tela de edição do convite digital
//
// Mesmo ambiente do editor do cartão: tela ao centro, camadas e
// propriedades à direita, ferramentas à esquerda. O convite é servido
// dentro da tela por convite-digital.php?demo=1&editor=1, marcado com
// data-sec/data-def; escrever aqui reescreve lá dentro na hora, sem
// voltar ao servidor. Tudo passa por um histórico com desfazer.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Convite digital · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/editor.css" rel="stylesheet">
<style>
  /* ---- Tela ---- */
  .cv-palco{ background:#0e0f0c; border-radius:10px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,.6);
             transition:width .18s ease; }
  #tela{ display:block; width:100%; border:0; background:#16261E; }
  .ed-mesa{ align-items:flex-start; }

  /* ---- Camadas (secções) ---- */
  .camada .op{ font-size:.68rem; color:var(--ed-texto-2); border:1px solid var(--ed-linha);
               border-radius:4px; padding:0 .25rem; }
  .camada.fixa .olho{ opacity:.25; cursor:not-allowed; }

  /* ---- Propriedades ---- */
  .sel-nada{ color:var(--ed-texto-2); font-size:.8rem; line-height:1.55; }
  .campo .dica-md{ font-size:.68rem; color:var(--ed-texto-2); margin-top:.2rem; }
  .contador{ float:right; font-variant-numeric:tabular-nums; }
  .contador.perto{ color:var(--ed-ouro-claro); }
  .contador.cheio{ color:#e08a7d; }
  .campo.invalido input, .campo.invalido textarea{ border-color:#e08a7d; box-shadow:0 0 0 2px rgba(224,138,125,.2); }

  /* ---- Listas (capítulos, momentos, células) ---- */
  .it{ border:1px solid var(--ed-linha); border-radius:7px; padding:.45rem .5rem; margin-bottom:.45rem; background:#232520; }
  .it-topo{ display:flex; gap:.35rem; align-items:center; margin-bottom:.35rem; }
  .it-topo .n{ font-family:'Cormorant Garamond',serif; color:var(--ed-ouro-claro); font-size:.8rem; width:1.2rem; }
  .it-topo .cresce{ flex:1; }
  .it input, .it textarea, .it select{ width:100%; box-sizing:border-box; background:#191a16;
    border:1px solid var(--ed-linha); color:var(--ed-texto); border-radius:5px; padding:.3rem .4rem;
    font-family:inherit; font-size:.8rem; margin-bottom:.3rem; }
  .it textarea{ min-height:46px; resize:vertical; }
  .it .lin{ display:flex; gap:.3rem; }

  /* ---- Cores ---- */
  .cor-linha{ display:flex; align-items:center; gap:.4rem; margin-bottom:.3rem; }
  .cor-linha input[type=color]{ width:28px; height:22px; border:none; background:none; padding:0; cursor:pointer; flex:none; }
  .cor-linha span{ font-size:.72rem; color:var(--ed-texto-2); }
  .temas{ display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:.6rem; }
  .tema-bt{ display:flex; align-items:center; gap:.3rem; background:#2a2c28; border:1px solid var(--ed-linha);
    color:var(--ed-texto); border-radius:6px; padding:.25rem .45rem; cursor:pointer; font-family:inherit; font-size:.72rem; }
  .tema-bt i{ width:11px; height:11px; border-radius:50%; display:block; }

  /* ---- Media ---- */
  .med{ display:flex; gap:.45rem; align-items:center; margin-bottom:.45rem; }
  .med img{ width:52px; height:36px; object-fit:cover; border-radius:4px; border:1px solid var(--ed-linha); flex:none; }
  .med .nm{ flex:1; min-width:0; font-size:.74rem; }
  .med .nm small{ display:block; color:var(--ed-texto-2); font-size:.66rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .med input[type=file]{ display:none; }

  /* ---- Enquadramento (ponto focal + aproximação) ---- */
  .enq{ margin:-.2rem 0 .7rem; }
  .enq-caixa{ position:relative; width:100%; max-height:180px; overflow:hidden; border-radius:6px;
    border:1px solid var(--ed-linha); background:#111; cursor:crosshair; touch-action:none; }
  .enq-caixa img{ width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
  .enq-caixa .mira{ position:absolute; width:20px; height:20px; margin:-10px 0 0 -10px; border-radius:50%;
    border:2px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,.6), 0 2px 6px rgba(0,0,0,.5); pointer-events:none; }
  .enq-caixa .mira::after{ content:''; position:absolute; inset:6px; border-radius:50%; background:var(--ed-ouro); }
  .enq-lin{ display:flex; align-items:center; gap:.4rem; margin-top:.35rem; }
  .enq-lin input[type=range]{ flex:1; accent-color:var(--ed-ouro); }
  .enq-rot{ font-size:.68rem; color:var(--ed-texto-2); text-transform:uppercase; letter-spacing:.07em; }
  .enq-dica{ font-size:.66rem; color:var(--ed-texto-2); margin-top:.2rem; }

  .ed-estado .aviso-txt{ color:var(--ed-ouro-claro); }
  @media (max-width:900px){ .ed-paineis{ width:250px; } }
</style>
</head>
<body class="editor">

<div class="ed-menu">
  <div class="marca"><span class="ed-mono"><?= escP($CAS['mono']) ?></span> Editor</div>
  <span class="doc">Convite digital · <b><?= escP($CAS['casal']) ?></b></span>
  <div class="cresce"></div>
  <a href="index.php">← Painel</a>
  <span class="ed-sep"></span>
  <a href="convite-digital.php?demo=1" target="_blank" rel="noopener">Abrir o convite</a>
</div>

<div class="ed-opcoes">
  <button class="bt bt-min" id="bt-desfazer" onclick="desfazer()" title="Desfazer (Ctrl+Z)" disabled>↶ Desfazer</button>
  <button class="bt bt-min" id="bt-refazer" onclick="refazer()" title="Refazer (Ctrl+Shift+Z)" disabled>↷ Refazer</button>
  <span class="ed-sep"></span>
  <span class="rot">Largura</span>
  <select id="largura" onchange="aplicarLargura()">
    <option value="390">Telemóvel</option>
    <option value="640" selected>Como foi desenhado</option>
    <option value="820">Tablet</option>
  </select>
  <span class="ed-sep"></span>
  <div class="zoom">
    <button class="bt bt-min" onclick="zoomPasso(-1)" title="Reduzir">−</button>
    <span class="val" id="zoom-val">100%</span>
    <button class="bt bt-min" onclick="zoomPasso(1)" title="Ampliar">+</button>
  </div>
  <div class="cresce"></div>
  <span class="rot" id="marca-sujo" hidden>alterações por guardar</span>
  <button class="bt" onclick="reporSeccao()">Repor esta secção</button>
  <button class="bt primario" onclick="guardar()">Guardar</button>
</div>

<div class="ed-corpo">
  <div class="ed-ferramentas">
    <button class="ferr on" data-ferr="selecionar" title="Selecionar (V)" onclick="ferramenta('selecionar')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3l7 17 2.5-6.5L21 11z"/></svg>
    </button>
    <button class="ferr" data-ferr="limpo" title="Ver sem marcas (P)" onclick="ferramenta('limpo')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
  </div>

  <div class="ed-mesa" id="mesa">
    <div class="cv-palco" id="palco" style="width:640px">
      <iframe id="tela" name="tela" title="Convite"></iframe>
      <!-- A tela é servida por POST para poder receber o rascunho por gravar. -->
      <form id="f-tela" method="post" target="tela" action="convite-digital.php?demo=1&editor=1" hidden>
        <input type="hidden" name="rascunho" id="rascunho">
      </form>
    </div>
  </div>

  <div class="ed-paineis">
    <div class="ed-painel" id="p-props">
      <h3 onclick="alternarPainel(this)">Propriedades <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="props"></div>
    </div>
    <div class="ed-painel">
      <h3 onclick="alternarPainel(this)">Camadas <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="camadas"></div>
    </div>
    <div class="ed-painel fechado">
      <h3 onclick="alternarPainel(this)">Cores <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="cores"></div>
    </div>
    <div class="ed-painel fechado">
      <h3 onclick="alternarPainel(this)">Fotos e música <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="media"></div>
    </div>
    <div class="ed-painel fechado cresce">
      <h3 onclick="alternarPainel(this)">Efeitos <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="efeitos"></div>
    </div>
  </div>
</div>

<div class="ed-estado">
  <span id="estado">Escolha um texto na tela, ou uma camada à direita.</span>
  <div class="cresce"></div>
  <span class="aviso-txt" id="passos"></span>
</div>

<script src="assets/api.js"></script>
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const PADRAO   = <?= json_encode(defsPadrao(), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS   = <?= json_encode(defsAtuais($conn), JSON_UNESCAPED_UNICODE) ?>;
const SECCOES  = <?= json_encode(seccoesConvite(), JSON_UNESCAPED_UNICODE) ?>;
const MARKDOWN = <?= json_encode(camposMarkdown()) ?>;
const ICONES   = <?= json_encode(iconesConvite()) ?>;
// Fotografias recortadas: têm ponto focal e aproximação (as outras mostram-se inteiras).
const FOTOS_LISTA = <?= json_encode(array_map(fn($id,$f)=>$f+['id'=>$id], array_keys(fotosEnquadraveis()), fotosEnquadraveis()), JSON_UNESCAPED_UNICODE) ?>;
const FOTOS = {}, FOTOS_POR_ID = {};
FOTOS_LISTA.forEach(f=>{ FOTOS[f.media]=f; FOTOS_POR_ID[f.id]=f; });
let MEDIA_V = Date.now();   // rebenta a cache das miniaturas depois de trocar um ficheiro
const TEMAS    = <?= json_encode(temasPredef()) ?>;
const TEMA_VARS= <?= json_encode(TEMA_VARS_EDITAVEIS) ?>;

const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));

// Definições que este editor NÃO governa (o cartão impresso é editado à parte;
// as de media são gravadas pelo próprio upload).
const ALHEIAS = ['cartao.', 'media.'];

// ---------- rótulos e limites ----------
// Os limites são os que o servidor aplica: mostrá-los evita a surpresa de ver
// o texto cortado depois de gravar.
const CAMPOS = {
  'casal.noiva':['Nome da noiva','texto',80], 'casal.noivo':['Nome do noivo','texto',80],
  'textos.kicker':['Frase do topo','texto',80], 'textos.hero_sub':['Subtítulo da capa','texto',80],
  'textos.convite_eyebrow':['Chamada','texto',120],
  'textos.lead':['Texto principal','area',4000],
  'textos.guest_label':['Rótulo do convidado','texto',80],
  'textos.closing':['Texto de fecho','area',4000],
  'textos.nota_parenteses':['Nota dos parênteses','area',4000],
  'historia.eyebrow':['Chamada','texto',120], 'historia.titulo':['Título','texto',120],
  'historia.quote':['Citação de abertura','area',4000], 'historia.autor':['Autor da citação','texto',80],
  'interludio.quote':['Citação','area',4000], 'interludio.autor':['Autor','texto',80],
  'interludio.fecho':['Texto de fecho','area',4000],
  'gd.eyebrow':['Chamada','texto',120],
  'evento.venue_titulo':['Título do momento','texto',80],
  'cronograma.titulo':['Título do cronograma','texto',120],
  'acesso.eyebrow':['Chamada','texto',120], 'acesso.titulo':['Título','texto',120],
  'acesso.instrucao':['Instrução junto ao QR','area',4000], 'acesso.nota':['Nota de rodapé','area',4000],
  'manual.eyebrow':['Chamada do manual','texto',120], 'manual.titulo':['Título do manual','texto',120],
  'manual.intro':['Introdução do manual','area',4000],
  'rsvp.titulo':['Título do RSVP','area',4000], 'rsvp.sub':['Subtítulo do RSVP','area',4000],
  'rsvp.deadline':['Prazo de confirmação','texto',80],
  'footer.local':['Localidade no rodapé','texto',80], 'footer.quote':['Citação do rodapé','area',4000],
  'evento.data':['Data do evento','data',10], 'evento.hora':['Hora','hora',5],
  'evento.local':['Local','texto',120], 'evento.cidade':['Cidade / região','texto',80],
  'evento.maps':['Ligação do Google Maps','texto',500],
  'evento.whatsapp':['WhatsApp de contacto','texto',20],
};
// Campos extra que cada secção mostra, além dos que se selecionam na tela.
const EXTRA = {
  'hero':['evento.data','evento.hora'],
  'convite':['textos.nota_parenteses'],
  'grande-dia':['evento.local','evento.cidade','evento.maps'],
  'final':['footer.local','evento.whatsapp'],
};
// Listas editáveis, por secção
const LISTAS_SEC = { 'historia':'historia.capitulos', 'grande-dia':'cronograma.itens', 'final':'manual.itens' };
const LISTA_CAMPOS = {
  'historia.capitulos':{ rot:'Capítulos', novo:()=>({t:'',x:''}), max:8,
    campos:[['t','Título','input'],['x','Texto','textarea']] },
  'cronograma.itens':{ rot:'Momentos do dia', novo:()=>({h:'',p:'Noite',t:'',s:'',i:'coracao'}), max:12,
    campos:[['h','Hora','input'],['p','Período','input'],['t','Título','input'],['s','Subtítulo','input']], icone:true },
  'manual.itens':{ rot:'Células do manual', novo:()=>({i:'coracao',x:''}), max:12,
    campos:[['x','Texto','textarea']], icone:true },
};
// Interruptores de visibilidade por secção (nem todas se podem esconder)
const VISIVEL = { 'historia':'historia.visivel', 'interludio':'interludio.visivel' };
// Sub-blocos opcionais que vivem dentro de uma secção
const SUB_VISIVEL = { 'grande-dia':[['cronograma.visivel','Mostrar o cronograma']],
                      'final':[['manual.visivel','Mostrar o manual do convidado']] };

// ---------- estado ----------
let EST = {
  val: {...ATUAIS},
  listas: {
    'historia.capitulos': ler('historia.capitulos'),
    'cronograma.itens':   ler('cronograma.itens'),
    'manual.itens':       ler('manual.itens'),
  },
  paleta: (()=>{ try { return JSON.parse(ATUAIS['tema.paleta']||'{}')||{}; } catch(e){ return {}; } })(),
};
function ler(k){ try { return JSON.parse(ATUAIS[k]||'[]')||[]; } catch(e){ return []; } }

let SEC = 'hero';      // camada selecionada
let DEF = null;        // texto selecionado dentro dela
let SUJO = false;
let telaPronta = false;

// ---------- histórico (desfazer / refazer) ----------
// Um editor sem desfazer obriga a pensar duas vezes antes de cada gesto.
const HIST = [instantaneo()];
let hPos = 0;
function instantaneo(){ return JSON.stringify(EST); }
let tGuardaHist = null;
function registarPasso(){
  clearTimeout(tGuardaHist);
  tGuardaHist = setTimeout(()=>{
    const agora = instantaneo();
    if (agora === HIST[hPos]) return;
    HIST.length = hPos + 1;          // um passo novo apaga o "refazer"
    HIST.push(agora);
    if (HIST.length > 60) HIST.shift();
    hPos = HIST.length - 1;
    marcarBotoes();
  }, 350);                            // agrupa a escrita contínua num só passo
}
function aplicarEstado(json){
  EST = JSON.parse(json);
  renderCamadas(); renderProps(); renderCores(); renderMedia(); renderEfeitos();
  recarregarTela();
  marcarBotoes();
}
function desfazer(){ if (hPos<=0) return; clearTimeout(tGuardaHist); hPos--; aplicarEstado(HIST[hPos]); marcarSujo(true); msg('Desfeito.'); }
function refazer(){ if (hPos>=HIST.length-1) return; clearTimeout(tGuardaHist); hPos++; aplicarEstado(HIST[hPos]); marcarSujo(true); msg('Refeito.'); }
function marcarBotoes(){
  $('bt-desfazer').disabled = hPos<=0;
  $('bt-refazer').disabled  = hPos>=HIST.length-1;
  $('passos').textContent = HIST.length>1 ? (hPos+1)+' de '+HIST.length+' passos' : '';
}

// ---------- alterações por guardar ----------
function marcarSujo(v){ if (SUJO===v) return; SUJO=v; $('marca-sujo').hidden = !v; }
window.addEventListener('beforeunload', e=>{ if (SUJO){ e.preventDefault(); e.returnValue=''; } });
function msg(t){ $('estado').textContent = t; }

// ---------- markdown igual ao do servidor ----------
// O servidor compõe estes textos com mdTexto(); a tela tem de os reescrever
// com o mesmo aspeto, senão o que se vê a escrever não é o que fica gravado.
function mdJs(t){
  const tk = { '{noiva}': EST.val['casal.noiva']||'', '{noivo}': EST.val['casal.noivo']||'' };
  let s = String(t??'').replace(/\{noiva\}|\{noivo\}/g, m => tk[m]);
  s = esc(s);
  s = s.replace(/\*\*([\s\S]+?)\*\*/g, '<b>$1</b>');
  s = s.replace(/\*([\s\S]+?)\*/g, '<i>$1</i>');
  return s.replace(/\n/g, '<br>');
}
function paraTela(chave, valor){
  return MARKDOWN.includes(chave) ? mdJs(valor) : esc(valor);
}

// ---------- comunicação com a tela ----------
function enviarTela(m){
  const f = $('tela'); if (!f || !f.contentWindow) return;
  f.contentWindow.postMessage(Object.assign({fonte:'editor'}, m), '*');
}
window.addEventListener('message', e=>{
  const d = e.data||{}; if (d.fonte !== 'tela') return;
  if (d.tipo === 'pronta'){
    telaPronta = true;
    enviarTela({tipo:'tema', vars:EST.paleta});
    if (SEC) enviarTela({tipo:'marcar', sec:SEC, def:DEF});
    return;
  }
  if (d.tipo === 'selecionar'){
    if (d.sec && SECCOES[d.sec]) SEC = d.sec;
    DEF = (d.def && CAMPOS[d.def]) ? d.def : null;
    renderCamadas(); renderProps();
    enviarTela({tipo:'marcar', sec:SEC, def:DEF});
    if (DEF){ msg('A editar: ' + CAMPOS[DEF][0]);
              const el = document.querySelector('#props [data-chave="'+DEF+'"]');
              if (el){ el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }
    else msg('Camada: ' + (SECCOES[SEC]?SECCOES[SEC].rotulo:SEC));
  }
});
/**
 * Redesenha a tela a partir do RASCUNHO, não da base de dados. É o que permite
 * ver secções escondidas, listas e efeitos antes de gravar — o servidor compõe
 * essas partes (numeração das páginas, capitulares, ícones) e não dá para as
 * remendar no browser.
 */
function recarregarTela(){
  telaPronta = false;
  $('rascunho').value = JSON.stringify(serializar());
  $('f-tela').submit();
}

// ---------- camadas ----------
const OLHO_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const OLHO_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 3l18 18M10.6 10.7a3 3 0 004.2 4.2M9.9 5.2A9.6 9.6 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4M6.3 6.4A17 17 0 002 12s3.5 7 10 7a9.9 9.9 0 004-.8"/></svg>';
function renderCamadas(){
  $('camadas').innerHTML = Object.entries(SECCOES).map(([k,s])=>{
    const chaveVis = VISIVEL[k];
    const vis = !chaveVis || EST.val[chaveVis] !== '0';
    return `<div class="camada ${SEC===k?'sel':''} ${vis?'':'oculta'} ${chaveVis?'':'fixa'}" onclick="irCamada('${k}')">
      <button class="olho" title="${chaveVis ? (vis?'Esconder esta secção':'Mostrar esta secção') : 'Esta secção é sempre visível'}"
              onclick="event.stopPropagation();${chaveVis?`alternarSec('${k}')`:''}">${vis?OLHO_ON:OLHO_OFF}</button>
      <span class="nome">${esc(s.rotulo)}</span>
      ${chaveVis?'':'<span class="op">fixa</span>'}
    </div>`;
  }).join('');
}
function irCamada(k){
  SEC = k; DEF = null;
  renderCamadas(); renderProps();
  enviarTela({tipo:'marcar', sec:k, def:null});
  msg('Camada: ' + SECCOES[k].rotulo);
}
function alternarSec(k){
  const chave = VISIVEL[k]; if (!chave) return;
  EST.val[chave] = EST.val[chave]==='0' ? '1' : '0';
  marcarSujo(true); registarPasso(); renderCamadas();
  recarregarTela();   // esconder/mostrar muda a numeração das páginas: vem do servidor
  msg((EST.val[chave]==='0'?'Escondida: ':'Visível: ') + SECCOES[k].rotulo);
}

// ---------- propriedades ----------
function renderProps(){
  const s = SECCOES[SEC]; if (!s){ $('props').innerHTML=''; return; }
  const chaves = (s.campos||[]).concat(EXTRA[SEC]||[]).filter(c=>CAMPOS[c]);
  let h = `<div class="sel-nada" style="margin-bottom:.6rem"><b>${esc(s.rotulo)}</b></div>`;
  (SUB_VISIVEL[SEC]||[]).forEach(([chave,rot])=>{
    h += `<div class="campo"><label style="display:flex;align-items:center;gap:.4rem;text-transform:none;letter-spacing:0">
      <input type="checkbox" ${EST.val[chave]!=='0'?'checked':''} onchange="alternarSub('${chave}')"
             style="width:15px;height:15px;accent-color:var(--ed-ouro);cursor:pointer"> ${rot}</label></div>`;
  });
  h += chaves.map(c=>campoHTML(c)).join('');
  const lk = LISTAS_SEC[SEC];
  if (lk) h += listaHTML(lk);
  $('props').innerHTML = h;
  if (DEF){ const el = document.querySelector('#props [data-chave="'+DEF+'"]'); if (el) el.closest('.campo').scrollIntoView({block:'nearest'}); }
}
function campoHTML(chave){
  const [rot, tipo, max] = CAMPOS[chave];
  const v = EST.val[chave] ?? '';
  const sel = DEF===chave ? ' style="box-shadow:0 0 0 2px rgba(217,188,140,.3);border-radius:6px;padding:.2rem"' : '';
  const cont = max ? `<span class="contador ${classeCont(v.length,max)}">${v.length}/${max}</span>` : '';
  const md = MARKDOWN.includes(chave) ? '<div class="dica-md">Aceita **negrito**, *itálico* e {noiva} / {noivo}.</div>' : '';
  const ctl = tipo==='area'
    ? `<textarea data-chave="${chave}" maxlength="${max}" oninput="editar(this)" onfocus="focar('${chave}')">${esc(v)}</textarea>`
    : `<input type="${tipo==='data'?'date':(tipo==='hora'?'time':'text')}" data-chave="${chave}" ${max?`maxlength="${max}"`:''}
              value="${esc(v)}" oninput="editar(this)" onfocus="focar('${chave}')">`;
  return `<div class="campo"${sel}><label>${esc(rot)}${cont}</label>${ctl}${md}</div>`;
}
function classeCont(n,max){ return n>=max ? 'cheio' : (n>max*0.9 ? 'perto' : ''); }

function focar(chave){
  DEF = chave;
  enviarTela({tipo:'marcar', sec:SEC, def:chave});
  msg('A editar: ' + CAMPOS[chave][0]);
}
function editar(el){
  const chave = el.dataset.chave, v = el.value;
  EST.val[chave] = v;
  const lab = el.closest('.campo').querySelector('.contador');
  const max = CAMPOS[chave][2];
  if (lab){ lab.textContent = v.length+'/'+max; lab.className = 'contador '+classeCont(v.length,max); }
  // Escreve na tela imediatamente — é isto que faz a diferença entre um
  // formulário e um editor.
  enviarTela({tipo:'texto', def:chave, html:paraTela(chave,v)});
  marcarSujo(true); registarPasso();
}
function alternarSub(chave){
  EST.val[chave] = EST.val[chave]==='0' ? '1' : '0';
  marcarSujo(true); registarPasso(); recarregarTela();
}

// ---------- listas ----------
function listaHTML(lk){
  const cfg = LISTA_CAMPOS[lk], itens = EST.listas[lk]||[];
  let h = `<div class="campo"><label>${cfg.rot}<span class="contador ${classeCont(itens.length,cfg.max)}">${itens.length}/${cfg.max}</span></label></div>`;
  h += itens.map((it,i)=>`<div class="it">
      <div class="it-topo"><span class="n">${i+1}</span>
        ${cfg.icone?`<select onchange="editarItem('${lk}',${i},'i',this.value)" style="width:auto;margin:0;flex:1">
          ${Object.keys(ICONES).map(n=>`<option value="${n}" ${n===it.i?'selected':''}>${n}</option>`).join('')}</select>`:'<span class="cresce"></span>'}
        <button class="bt bt-min" onclick="removerItem('${lk}',${i})" title="Remover">✕</button>
      </div>
      ${cfg.campos.map(([c,ph,tag])=> tag==='textarea'
        ? `<textarea placeholder="${ph}" oninput="editarItem('${lk}',${i},'${c}',this.value)">${esc(it[c]||'')}</textarea>`
        : `<input type="text" placeholder="${ph}" value="${esc(it[c]||'')}" oninput="editarItem('${lk}',${i},'${c}',this.value)">`
      ).join('')}
    </div>`).join('');
  if (itens.length < cfg.max)
    h += `<button class="bt" style="width:100%" onclick="juntarItem('${lk}')">+ Acrescentar</button>`;
  else
    h += `<div class="sel-nada">Chegou ao máximo de ${cfg.max}. Remova um para juntar outro.</div>`;
  return h;
}
let tLista = null;
function editarItem(lk,i,campo,v){
  if (!EST.listas[lk][i]) return;
  EST.listas[lk][i][campo] = v;
  marcarSujo(true); registarPasso();
  // As listas são compostas pelo servidor (capitulares, ícones): atualiza-se
  // a tela com uma pausa, para não recarregar a cada tecla.
  clearTimeout(tLista); tLista = setTimeout(recarregarTela, 900);
}
function juntarItem(lk){
  const cfg = LISTA_CAMPOS[lk];
  if (EST.listas[lk].length >= cfg.max) return;
  EST.listas[lk].push(cfg.novo());
  marcarSujo(true); registarPasso(); renderProps();
  clearTimeout(tLista); tLista = setTimeout(recarregarTela, 600);
}
function removerItem(lk,i){
  EST.listas[lk].splice(i,1);
  marcarSujo(true); registarPasso(); renderProps();
  clearTimeout(tLista); tLista = setTimeout(recarregarTela, 600);
}

// ---------- cores ----------
function renderCores(){
  $('cores').innerHTML =
    `<div class="temas">` + Object.entries(TEMAS).map(([k,t])=>
      `<button class="tema-bt" onclick="aplicarTema('${k}')" title="${esc(t.nome)}">
        <i style="background:${t.forest}"></i><i style="background:${t.gold}"></i>${esc(t.nome)}</button>`).join('') +
      `<button class="tema-bt" onclick="aplicarTema('')">Repor</button></div>` +
    TEMA_VARS.map(v=>{
      const cor = EST.paleta[v] || TEMAS['floresta'][v];
      return `<label class="cor-linha"><input type="color" value="${cor}" oninput="editarCor('${v}',this.value)"><span>--${v}</span></label>`;
    }).join('');
}
function editarCor(v,cor){
  EST.paleta[v] = cor.toUpperCase();
  enviarTela({tipo:'tema', vars:{[v]:EST.paleta[v]}});   // a tela muda de cor na hora
  marcarSujo(true); registarPasso();
}
function aplicarTema(k){
  EST.paleta = {};
  if (k && TEMAS[k]) TEMA_VARS.forEach(v=>{ if (TEMAS[k][v]) EST.paleta[v] = TEMAS[k][v].toUpperCase(); });
  renderCores(); marcarSujo(true); registarPasso();
  if (k) enviarTela({tipo:'tema', vars:EST.paleta}); else recarregarTela();
}

// ---------- fotos e música ----------
const MEDIA = [['media.hero','Capa'],['media.historia','História'],['media.interludio','Interlúdio'],
               ['media.acesso','Passe de entrada'],['media.musica','Música de fundo']];

/** "50 8 100" -> {x,y,zoom} */
function lerEnq(v){
  const p = String(v||'').trim().split(/\s+/).map(Number);
  return { x: isFinite(p[0])?p[0]:50, y: isFinite(p[1])?p[1]:50, zoom: isFinite(p[2])?p[2]:100 };
}
function escreverEnq(e){ return `${Math.round(e.x*10)/10} ${Math.round(e.y*10)/10} ${Math.round(e.zoom)}`; }

function renderMedia(){
  $('media').innerHTML = MEDIA.map(([k,rot])=>{
    const v = EST.val[k]||'', img = k!=='media.musica';
    const f = FOTOS[k];          // as recortadas têm enquadramento
    let h = `<div class="med">
      ${img?`<img src="${esc(v)}?v=${MEDIA_V}" alt="">`:`<span class="ico-prev" style="width:52px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid var(--ed-linha);border-radius:4px">♪</span>`}
      <span class="nm">${rot}<small>${esc(v.split('/').pop())}</small></span>
      <label class="bt bt-min">Trocar<input type="file" accept="${img?'image/*':'audio/*,.m4a,.mp3'}" onchange="enviarFicheiro('${k}',this)"></label>
    </div>`;
    if (f){
      const e = lerEnq(EST.val[f.chave]);
      h += `<div class="enq" data-foto="${f.id}">
        <div class="enq-caixa" style="aspect-ratio:${f.proporcao}" onpointerdown="arrastarFoco(event,'${f.id}')">
          <img src="${esc(v)}?v=${MEDIA_V}" alt="" style="object-position:${e.x}% ${e.y}%;transform:scale(${e.zoom/100})">
          <span class="mira" style="left:${e.x}%;top:${e.y}%"></span>
        </div>
        <div class="enq-lin">
          <span class="enq-rot">Aproximar</span>
          <input type="range" min="100" max="220" step="1" value="${e.zoom}" oninput="mudarZoom('${f.id}',this.value)">
          <button class="bt bt-min" onclick="reporFoco('${f.id}')" title="Repor o enquadramento original">Repor</button>
        </div>
        <div class="enq-dica">Arraste sobre a imagem para escolher o que fica ao centro.</div>
      </div>`;
    }
    return h;
  }).join('') + `<div class="sel-nada">As imagens são reduzidas antes do envio. A música aceita até 8 MB.</div>`;
}

// ---------- enquadramento das fotografias ----------
function aplicarFocoTela(id){
  const f = FOTOS_POR_ID[id], e = lerEnq(EST.val[f.chave]);
  enviarTela({tipo:'foco', vars:{ ['foco-'+id]: e.x+'% '+e.y+'%', ['zoom-'+id]: String(e.zoom/100) }});
}
function actualizarMira(id){
  const f = FOTOS_POR_ID[id], e = lerEnq(EST.val[f.chave]);
  const cx = document.querySelector(`.enq[data-foto="${id}"]`); if (!cx) return;
  cx.querySelector('.mira').style.left = e.x+'%';
  cx.querySelector('.mira').style.top  = e.y+'%';
  const im = cx.querySelector('img');
  im.style.objectPosition = e.x+'% '+e.y+'%';
  im.style.transform = 'scale('+(e.zoom/100)+')';
}
function porFoco(id, x, y){
  const f = FOTOS_POR_ID[id], e = lerEnq(EST.val[f.chave]);
  e.x = Math.max(0, Math.min(100, x)); e.y = Math.max(0, Math.min(100, y));
  EST.val[f.chave] = escreverEnq(e);
  actualizarMira(id); aplicarFocoTela(id); marcarSujo(true); registarPasso();
}
function arrastarFoco(ev, id){
  const caixa = ev.currentTarget;
  caixa.setPointerCapture(ev.pointerId);
  const mover = e2 => {
    const r = caixa.getBoundingClientRect();
    porFoco(id, (e2.clientX-r.left)/r.width*100, (e2.clientY-r.top)/r.height*100);
  };
  mover(ev);
  const largar = () => { caixa.removeEventListener('pointermove', mover); caixa.removeEventListener('pointerup', largar); };
  caixa.addEventListener('pointermove', mover);
  caixa.addEventListener('pointerup', largar);
  ev.preventDefault();
}
function mudarZoom(id, v){
  const f = FOTOS_POR_ID[id], e = lerEnq(EST.val[f.chave]);
  e.zoom = +v; EST.val[f.chave] = escreverEnq(e);
  actualizarMira(id); aplicarFocoTela(id); marcarSujo(true); registarPasso();
  msg('Aproximação: ' + Math.round(e.zoom) + '%');
}
function reporFoco(id){
  const f = FOTOS_POR_ID[id];
  EST.val[f.chave] = PADRAO[f.chave];
  renderMedia(); aplicarFocoTela(id); marcarSujo(true); registarPasso();
  msg('Enquadramento reposto: ' + f.rotulo);
}
async function comprimir(file, maxLado=1600, q=0.82){
  try{
    const bmp = await createImageBitmap(file);
    const e = Math.min(1, maxLado/Math.max(bmp.width,bmp.height));
    const w = Math.round(bmp.width*e), h = Math.round(bmp.height*e);
    const cv = document.createElement('canvas'); cv.width=w; cv.height=h;
    cv.getContext('2d').drawImage(bmp,0,0,w,h);
    return await new Promise(r=>cv.toBlob(r,'image/jpeg',q)) || file;
  }catch(e){ return file; }
}
function agora(){ const d=new Date(), p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
async function enviarFicheiro(chave, input){
  const f = input.files && input.files[0]; if (!f) return;
  const musica = chave==='media.musica';
  if (musica && f.size > 8*1024*1024) return msg('A música excede 8 MB.');
  msg('A enviar…');
  const dados = musica ? f : await comprimir(f);
  const fd = new FormData();
  fd.append('chave',chave); fd.append('ts',agora());
  fd.append('ficheiro', dados, musica ? f.name : 'foto.jpg');
  const r = await fetch('api.php?action=def_upload',{method:'POST',headers:{'X-CSRF-Token':window.CSRF},body:fd});
  const d = await r.json();
  if (!d.success) return msg(d.message||'Falha no envio.');
  EST.val[chave] = d.path; ATUAIS[chave] = d.path;
  MEDIA_V = Date.now();
  // O enquadramento anterior tinha sido escolhido para a fotografia anterior:
  // uma nova composição ficaria cortada no sítio errado. Volta ao centro, para
  // ser reajustado à nova foto.
  const foto = FOTOS[chave];
  if (foto){
    const e = lerEnq(EST.val[foto.chave]); e.x = 50; e.y = 50;
    EST.val[foto.chave] = escreverEnq(e);
    msg('Fotografia trocada — o enquadramento voltou ao centro. Arraste para o ajustar.');
  } else {
    msg('Ficheiro atualizado.');
  }
  marcarSujo(true); registarPasso();
  renderMedia(); recarregarTela();
}

// ---------- efeitos ----------
function renderEfeitos(){
  $('efeitos').innerHTML = [['fx.petalas','Pétalas a cair'],['fx.autoplay','Música arranca ao abrir']]
    .map(([k,rot])=>`<div class="campo"><label style="display:flex;align-items:center;gap:.4rem;text-transform:none;letter-spacing:0">
      <input type="checkbox" ${EST.val[k]==='1'?'checked':''} onchange="alternarFx('${k}')"
             style="width:15px;height:15px;accent-color:var(--ed-ouro);cursor:pointer"> ${rot}</label></div>`).join('');
}
function alternarFx(k){
  EST.val[k] = EST.val[k]==='1' ? '0' : '1';
  marcarSujo(true); registarPasso(); recarregarTela();
}

// ---------- ferramentas, zoom, largura ----------
let ferrAtual = 'selecionar';
/** Aplica a ferramenta à tela. Sem mensagem: é chamada a cada recarga e não
 *  pode apagar o que a barra de estado esteja a dizer (ex.: o resultado de guardar). */
function aplicarFerramenta(){
  document.querySelectorAll('.ferr').forEach(b=>b.classList.toggle('on', b.dataset.ferr===ferrAtual));
  const doc = $('tela').contentDocument;
  if (doc && doc.body) doc.body.classList.toggle('ed-marcar', ferrAtual==='selecionar');
}
function ferramenta(f){
  ferrAtual = f; aplicarFerramenta();
  msg(f==='limpo' ? 'Vista limpa: sem marcas de seleção.' : 'Clique num texto do convite para o editar.');
}
const PASSOS = [.5,.6,.75,.9,1,1.25,1.5];
let zoom = 1;
function aplicarZoom(){
  $('palco').style.transform = 'scale('+zoom+')';
  $('palco').style.transformOrigin = 'top center';
  $('zoom-val').textContent = Math.round(zoom*100)+'%';
}
function zoomPasso(d){
  const i = PASSOS.indexOf(zoom);
  zoom = PASSOS[Math.max(0, Math.min(PASSOS.length-1, (i<0?4:i)+d))];
  aplicarZoom();
}
function aplicarLargura(){
  const w = $('largura').value;
  $('palco').style.width = w+'px';
}
function ajustarAltura(){
  const h = $('mesa').clientHeight - 52;
  $('tela').style.height = Math.max(420, h/zoom) + 'px';
}
window.addEventListener('resize', ajustarAltura);

function alternarPainel(h){ h.parentElement.classList.toggle('fechado'); }

// ---------- atalhos ----------
document.addEventListener('keydown', e=>{
  const emCampo = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='z'){ e.preventDefault(); e.shiftKey?refazer():desfazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='y'){ e.preventDefault(); refazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){ e.preventDefault(); guardar(); return; }
  if (emCampo) return;
  if (e.key.toLowerCase()==='v') ferramenta('selecionar');
  if (e.key.toLowerCase()==='p') ferramenta('limpo');
});

// ---------- guardar ----------
function serializar(){
  const v = {...EST.val};
  v['historia.capitulos'] = JSON.stringify(EST.listas['historia.capitulos']);
  v['cronograma.itens']   = JSON.stringify(EST.listas['cronograma.itens']);
  v['manual.itens']       = JSON.stringify(EST.listas['manual.itens']);
  v['tema.paleta']        = Object.keys(EST.paleta).length ? JSON.stringify(EST.paleta) : '';
  return v;
}
function rotuloDe(chave){ return CAMPOS[chave] ? CAMPOS[chave][0] : chave; }
async function guardar(){
  const v = serializar();
  // Só o que este editor governa, e só o que mudou: assim não pisa o cartão
  // impresso nem reescreve definições que ninguém tocou.
  const defs = {};
  Object.keys(PADRAO).forEach(k=>{
    if (ALHEIAS.some(p=>k.startsWith(p))) return;
    const novo = String(v[k] ?? '');
    if (novo !== String(ATUAIS[k] ?? '')) defs[k] = novo;
  });
  if (!Object.keys(defs).length){ msg('Não há alterações por guardar.'); marcarSujo(false); return; }
  const d = await api('defs_save', {method:'POST', body:JSON.stringify({defs})});
  if (!d.success) return msg(d.message || 'Erro ao guardar.');
  const inv = d.invalidas || [];
  Object.keys(defs).forEach(k=>{ if (!inv.includes(k)) ATUAIS[k] = defs[k]; });
  marcarSujo(false);
  marcarInvalidos(inv);
  msg(inv.length ? `Guardado, mas ${inv.length} campo(s) não foram aceites: ${inv.map(rotuloDe).join(', ')}.`
                 : 'Convite guardado.');
  recarregarTela();
}
function marcarInvalidos(inv){
  document.querySelectorAll('#props .campo').forEach(c=>c.classList.remove('invalido'));
  inv.forEach(k=>{
    const el = document.querySelector('#props [data-chave="'+k+'"]');
    if (el) el.closest('.campo').classList.add('invalido');
  });
}

function reporSeccao(){
  const s = SECCOES[SEC]; if (!s) return;
  if (!confirm(`Repor os textos originais de "${s.rotulo}"?\n\nPode desfazer com Ctrl+Z.`)) return;
  (s.campos||[]).concat(EXTRA[SEC]||[]).forEach(k=>{ if (k in PADRAO) EST.val[k] = PADRAO[k]; });
  const lk = LISTAS_SEC[SEC];
  if (lk && lk in PADRAO){ try { EST.listas[lk] = JSON.parse(PADRAO[lk]||'[]'); } catch(e){} }
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
  msg(`"${s.rotulo}" reposta — por guardar. Ctrl+Z desfaz.`);
}

// ---------- arranque ----------
renderCamadas(); renderProps(); renderCores(); renderMedia(); renderEfeitos();
aplicarZoom(); ajustarAltura(); marcarBotoes();
$('tela').addEventListener('load', ()=>{ ajustarAltura(); aplicarFerramenta(); });
recarregarTela();                       // primeira pintura da tela
msg('Clique num texto do convite para o editar.');
</script>
</body>
</html>
