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
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/editor.css') ?>" rel="stylesheet">
<style>
  /* Só para as amostras do painel de tipografia mostrarem a letra certa. */
  @font-face{font-family:'Alex Brush';src:url(assets/convite/fonts/alex-brush-latin-400-normal.woff2) format('woff2');font-display:swap}
  @font-face{font-family:'Montserrat';src:url(assets/convite/fonts/montserrat-latin-variable-normal.woff2) format('woff2');font-weight:300 700;font-display:swap}
  @font-face{font-family:'Pinyon Script';src:url(assets/convite/fonts/pinyon-script-latin-400-normal.woff2) format('woff2');font-display:swap}
</style>
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
  .camada[draggable=true]{ cursor:grab; }
  .camada.a-arrastar{ opacity:.4; }
  .camada.cair-antes{ box-shadow:inset 0 2px 0 var(--ed-ouro); }
  .camada.cair-depois{ box-shadow:inset 0 -2px 0 var(--ed-ouro); }
  .add-sec{ display:flex; gap:.3rem; margin-top:.5rem; padding-top:.5rem; border-top:1px solid var(--ed-linha); }
  .add-sec select{ flex:1; min-width:0; background:#191a16; border:1px solid var(--ed-linha);
    color:var(--ed-texto); border-radius:5px; padding:.25rem .3rem; font-family:inherit; font-size:.76rem; }

  /* ---- Propriedades ---- */
  .sel-nada{ color:var(--ed-texto-2); font-size:.8rem; line-height:1.55; }
  .campo .dica-md{ font-size:.68rem; color:var(--ed-texto-2); margin-top:.2rem; }

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

  .nome-v{ flex:1; min-width:0; font-size:.82rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
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
      <h3 onclick="alternarPainel(this)">Tipografia <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="tipografia"></div>
    </div>
    <div class="ed-painel fechado">
      <h3 onclick="alternarPainel(this)">Versões <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="versoes"></div>
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
  <span class="marca-sujo" id="marca-sujo">alterações por guardar</span>
</div>

<script src="<?= asset('assets/editor-adiar.js') ?>"></script>
<?php if (($_GET['diag'] ?? '') === '1'): ?>
<script src="<?= asset('assets/editor-diag.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/versoes.js') ?>"></script>
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const PADRAO   = <?= json_encode(defsPadrao(), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS   = <?= json_encode(defsAtuais($conn), JSON_UNESCAPED_UNICODE) ?>;
const SECCOES  = <?= json_encode(seccoesConvite(), JSON_UNESCAPED_UNICODE) ?>;
const MODELOS  = <?= json_encode(modelosBloco(), JSON_UNESCAPED_UNICODE) ?>;
const PRIMEIRO = <?= json_encode(BLOCO_PRIMEIRO) ?>;   // a capa abre sempre
const ULTIMO   = <?= json_encode(BLOCO_ULTIMO) ?>;     // o fecho encerra sempre
const BLOCOS_MAX = <?= (int)BLOCOS_MAX ?>;
const FONTES     = <?= json_encode(fontesConvite(), JSON_UNESCAPED_UNICODE) ?>;
const PAPEIS     = <?= json_encode(papeisTipo(), JSON_UNESCAPED_UNICODE) ?>;
const CASAL_NOME = <?= json_encode($CAS['casal']) ?>;
const MARKDOWN = <?= json_encode(camposMarkdown()) ?>;
const ICONES   = <?= json_encode(iconesConvite()) ?>;
// Fotografias recortadas: têm ponto focal e aproximação (as outras mostram-se inteiras).
const FOTOS_LISTA = <?= json_encode(array_map(fn($id,$f)=>$f+['id'=>$id], array_keys(fotosEnquadraveis()), fotosEnquadraveis()), JSON_UNESCAPED_UNICODE) ?>;
const FOTOS = {}, FOTOS_POR_ID = {};
FOTOS_LISTA.forEach(f=>{ FOTOS[f.media]=f; FOTOS_POR_ID[f.id]=f; });
let MEDIA_V = Date.now();   // rebenta a cache das miniaturas depois de trocar um ficheiro
const TEMAS    = <?= json_encode(temasPredef()) ?>;
const TEMA_VARS= <?= json_encode(TEMA_VARS_EDITAVEIS) ?>;
const TEMA_ROT = <?= json_encode(temaVarsRotulos(), JSON_UNESCAPED_UNICODE) ?>;
// Textos que o servidor compõe (data por extenso, "às 20h30", morada em várias
// linhas): pintá-los em cru na tela mostrava "20:30" onde ficará "às 20h30".
// Para estes espera-se uma pausa e recarrega-se a tela, como nas listas.
const RECOMPOR = ['evento.data','evento.hora','evento.local','evento.cidade',
                  'evento.maps','evento.whatsapp','footer.local','textos.nota_parenteses'];

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
  blocos: ler('layout.blocos'),                                  // secções livres
  ordem:  (ATUAIS['layout.ordem']||'').split(',').filter(Boolean), // ordem das secções
};
function ler(k){ try { return JSON.parse(ATUAIS[k]||'[]')||[]; } catch(e){ return []; } }

// ---------- as camadas: secções de origem + as livres, pela ordem ----------
function blocoLivre(id){ return EST.blocos.find(b=>b.id===id) || null; }
/** Lista ordenada de camadas, com a capa à cabeça e o fecho no fim. */
function camadas(){
  const validos = Object.keys(SECCOES).concat(EST.blocos.map(b=>b.id));
  const ord = [];
  EST.ordem.forEach(id=>{ if (validos.includes(id) && !ord.includes(id)) ord.push(id); });
  validos.forEach(id=>{ if (!ord.includes(id)) ord.push(id); });
  const meio = ord.filter(id=>id!==PRIMEIRO && id!==ULTIMO);
  const final = [PRIMEIRO, ...meio, ULTIMO];
  EST.ordem = final;
  return final.map(id=>{
    const b = blocoLivre(id);
    return b ? { id, rotulo: b.titulo || 'Secção livre', livre: true, fixa: false }
             : { id, rotulo: SECCOES[id] ? SECCOES[id].rotulo : id, livre: false,
                 fixa: (id===PRIMEIRO || id===ULTIMO) };
  });
}
function novoId(){
  let n = 1, id;
  do { id = 'bl' + n++; } while (EST.blocos.some(b=>b.id===id));
  return id;
}

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
  renderCamadas(); renderProps(); renderCores(); renderMedia(); renderEfeitos(); renderTipografia();
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
function marcarSujo(v){ if (SUJO===v) return; SUJO=v; $('marca-sujo').classList.toggle('on', v); }
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
  if (d.tipo === 'atalho'){
    if (d.tecla === 'z') d.shift ? refazer() : desfazer();
    if (d.tecla === 'y') refazer();
    if (d.tecla === 's') guardar();
    return;
  }
  if (d.tipo === 'selecionar'){
    if (d.sec && (SECCOES[d.sec] || blocoLivre(d.sec))) SEC = d.sec;
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

// Os redesenhos de painel passam pelo guarda de assets/editor-adiar.js: se
// houver um gesto em curso (barra a ser arrastada, painel de cores aberto),
// esperam pelo fim em vez de trocar o elemento por baixo do rato.
const adiar = window.adiavel || ((n, f) => f);
const renderProps      = adiar('props',      (...a) => renderPropsJa(...a));
const renderCores      = adiar('cores',      (...a) => renderCoresJa(...a));
const renderTipografia = adiar('tipografia', (...a) => renderTipografiaJa(...a));

// ---------- camadas ----------
const OLHO_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const OLHO_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 3l18 18M10.6 10.7a3 3 0 004.2 4.2M9.9 5.2A9.6 9.6 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4M6.3 6.4A17 17 0 002 12s3.5 7 10 7a9.9 9.9 0 004-.8"/></svg>';
function renderCamadas(){
  const lista = camadas();
  $('camadas').innerHTML = lista.map((c,i)=>{
    const chaveVis = VISIVEL[c.id];
    const podeEsconder = !!chaveVis || c.livre;
    const vis = c.livre ? true : (!chaveVis || EST.val[chaveVis] !== '0');
    const movivel = !c.fixa;
    return `<div class="camada ${SEC===c.id?'sel':''} ${vis?'':'oculta'} ${movivel?'':'fixa'}"
      draggable="${movivel}" data-id="${c.id}" data-i="${i}"
      ondragstart="arrastarCamada(event)" ondragover="sobreCamada(event)"
      ondrop="largarCamada(event)" ondragend="fimArrasto(event)"
      onclick="irCamada('${c.id}')">
      <button class="olho" title="${podeEsconder ? (vis?'Esconder esta secção':'Mostrar esta secção') : 'Esta secção é sempre visível'}"
              onclick="event.stopPropagation();${chaveVis?`alternarSec('${c.id}')`:''}">${vis?OLHO_ON:OLHO_OFF}</button>
      <span class="nome">${esc(c.rotulo)}</span>
      ${c.livre ? '<span class="op">livre</span>' : (c.fixa ? '<span class="op">fixa</span>' : '')}
    </div>`;
  }).join('') + `
    <div class="add-sec">
      ${EST.blocos.length < BLOCOS_MAX
        ? `<select id="modelo-novo">${Object.entries(MODELOS).map(([k,m])=>`<option value="${k}">${esc(m.rotulo)}</option>`).join('')}</select>
           <button class="bt bt-min" onclick="juntarBloco()">+ Acrescentar</button>`
        : `<span class="sel-nada">Chegou ao máximo de ${BLOCOS_MAX} secções livres.</span>`}
    </div>
    <div class="sel-nada" style="margin-top:.35rem">Arraste as camadas para trocar a ordem. A capa abre e o fecho encerra.</div>`;
}

// ---------- arrastar para reordenar ----------
let idArrastado = null;
function arrastarCamada(ev){
  const el = ev.currentTarget;
  if (el.classList.contains('fixa')) { ev.preventDefault(); return; }
  idArrastado = el.dataset.id;
  el.classList.add('a-arrastar');
  ev.dataTransfer.effectAllowed = 'move';
  ev.dataTransfer.setData('text/plain', idArrastado);   // Firefox exige dados
}
function sobreCamada(ev){
  const el = ev.currentTarget;
  if (!idArrastado || el.dataset.id === idArrastado || el.classList.contains('fixa')) return;
  ev.preventDefault();
  ev.dataTransfer.dropEffect = 'move';
  // Marca de onde vai cair: acima ou abaixo, conforme a metade em que se está.
  const r = el.getBoundingClientRect();
  el.classList.toggle('cair-antes', ev.clientY < r.top + r.height/2);
  el.classList.toggle('cair-depois', ev.clientY >= r.top + r.height/2);
}
function largarCamada(ev){
  const el = ev.currentTarget;
  if (!idArrastado || el.dataset.id === idArrastado || el.classList.contains('fixa')) return;
  ev.preventDefault();
  const r = el.getBoundingClientRect();
  const antes = ev.clientY < r.top + r.height/2;
  const ordem = EST.ordem.filter(id=>id!==idArrastado);
  const alvo = ordem.indexOf(el.dataset.id);
  ordem.splice(antes ? alvo : alvo+1, 0, idArrastado);
  EST.ordem = ordem;
  limparMarcas();
  marcarSujo(true); registarPasso(); renderCamadas(); recarregarTela();
  msg('Ordem alterada. A numeração das páginas acompanha.');
}
function fimArrasto(){ idArrastado = null; limparMarcas(); }
function limparMarcas(){
  document.querySelectorAll('.camada').forEach(e=>e.classList.remove('cair-antes','cair-depois','a-arrastar'));
}

// ---------- secções livres ----------
function juntarBloco(){
  if (EST.blocos.length >= BLOCOS_MAX) return;
  const m = MODELOS[$('modelo-novo').value] || MODELOS['livre'];
  const b = { id: novoId(), eyebrow: m.eyebrow, titulo: m.titulo, texto: m.texto,
              itens: (m.itens||[]).map(it=>({...it})) };
  EST.blocos.push(b);
  // Entra antes do fecho, que é sempre o último.
  EST.ordem = EST.ordem.filter(id=>id!==ULTIMO).concat([b.id, ULTIMO]);
  SEC = b.id; DEF = null;
  marcarSujo(true); registarPasso();
  renderCamadas(); renderProps(); recarregarTela();
  msg('Secção acrescentada: ' + (b.titulo||'nova secção') + '. Arraste-a na lista para a mudar de sítio.');
}
function apagarBloco(id){
  const b = blocoLivre(id); if (!b) return;
  if (!confirm(`Apagar a secção "${b.titulo||'livre'}"?\n\nPode desfazer com Ctrl+Z.`)) return;
  EST.blocos = EST.blocos.filter(x=>x.id!==id);
  EST.ordem  = EST.ordem.filter(x=>x!==id);
  SEC = PRIMEIRO; DEF = null;
  marcarSujo(true); registarPasso();
  renderCamadas(); renderProps(); recarregarTela();
  msg('Secção apagada. Ctrl+Z devolve-a.');
}
let tBloco = null;
function editarBloco(id, campo, v){
  const b = blocoLivre(id); if (!b) return;
  b[campo] = v;
  if (campo === 'titulo') renderCamadas();          // o nome da camada acompanha
  marcarSujo(true); registarPasso();
  clearTimeout(tBloco); tBloco = setTimeout(recarregarTela, 800);
}
function juntarItemBloco(id){
  const b = blocoLivre(id); if (!b || (b.itens||[]).length >= 8) return;
  (b.itens = b.itens || []).push({i:'coracao', t:'', x:''});
  marcarSujo(true); registarPasso(); renderProps();
  clearTimeout(tBloco); tBloco = setTimeout(recarregarTela, 600);
}
function editarItemBloco(id, i, campo, v){
  const b = blocoLivre(id); if (!b || !b.itens[i]) return;
  b.itens[i][campo] = v;
  marcarSujo(true); registarPasso();
  clearTimeout(tBloco); tBloco = setTimeout(recarregarTela, 800);
}
function removerItemBloco(id, i){
  const b = blocoLivre(id); if (!b) return;
  b.itens.splice(i,1);
  marcarSujo(true); registarPasso(); renderProps();
  clearTimeout(tBloco); tBloco = setTimeout(recarregarTela, 600);
}
function irCamada(k){
  SEC = k; DEF = null;
  renderCamadas(); renderProps();
  enviarTela({tipo:'marcar', sec:k, def:null});
  const b = blocoLivre(k);
  msg('Camada: ' + (b ? (b.titulo||'secção livre') : (SECCOES[k] ? SECCOES[k].rotulo : k)));
}
function alternarSec(k){
  const chave = VISIVEL[k]; if (!chave) return;
  EST.val[chave] = EST.val[chave]==='0' ? '1' : '0';
  marcarSujo(true); registarPasso(); renderCamadas();
  recarregarTela();   // esconder/mostrar muda a numeração das páginas: vem do servidor
  msg((EST.val[chave]==='0'?'Escondida: ':'Visível: ') + SECCOES[k].rotulo);
}

// ---------- propriedades ----------
function renderPropsJa(){
  const livre = blocoLivre(SEC);
  if (livre) return renderPropsLivre(livre);
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

/** Propriedades de uma secção livre: os mesmos campos para todas. */
function renderPropsLivre(b){
  const cp = (campo, rot, tipo, max) => {
    const v = b[campo] || '';
    const ctl = tipo==='area'
      ? `<textarea maxlength="${max}" oninput="editarBloco('${b.id}','${campo}',this.value);contarAqui(this,${max})">${esc(v)}</textarea>`
      : `<input type="text" maxlength="${max}" value="${esc(v)}" oninput="editarBloco('${b.id}','${campo}',this.value);contarAqui(this,${max})">`;
    return `<div class="campo"><label>${rot}<span class="contador ${classeCont(v.length,max)}">${v.length}/${max}</span></label>${ctl}</div>`;
  };
  const itens = b.itens || [];
  $('props').innerHTML =
    `<div class="sel-nada" style="margin-bottom:.6rem"><b>Secção livre</b> — acrescentada por si</div>` +
    cp('eyebrow','Chamada','texto',120) +
    cp('titulo','Título','texto',120) +
    cp('texto','Texto','area',2000) +
    `<div class="campo"><label>Destaques<span class="contador ${classeCont(itens.length,8)}">${itens.length}/8</span></label></div>` +
    itens.map((it,i)=>`<div class="it">
        <div class="it-topo"><span class="n">${i+1}</span>
          <select onchange="editarItemBloco('${b.id}',${i},'i',this.value)" style="width:auto;margin:0;flex:1">
            ${Object.keys(ICONES).map(n=>`<option value="${n}" ${n===it.i?'selected':''}>${n}</option>`).join('')}</select>
          <button class="bt bt-min" onclick="removerItemBloco('${b.id}',${i})" title="Remover">✕</button>
        </div>
        <input type="text" placeholder="Título" value="${esc(it.t||'')}" oninput="editarItemBloco('${b.id}',${i},'t',this.value)">
        <textarea placeholder="Texto" oninput="editarItemBloco('${b.id}',${i},'x',this.value)">${esc(it.x||'')}</textarea>
      </div>`).join('') +
    (itens.length < 8 ? `<button class="bt" style="width:100%" onclick="juntarItemBloco('${b.id}')">+ Destaque</button>` : '') +
    `<div class="campo" style="margin-top:.8rem"><button class="bt" style="width:100%;color:#e08a7d" onclick="apagarBloco('${b.id}')">Apagar esta secção</button></div>`;
}
/** Atualiza o contador do campo que está a ser escrito. */
function contarAqui(el, max){
  const c = el.closest('.campo').querySelector('.contador');
  if (c){ c.textContent = el.value.length+'/'+max; c.className = 'contador '+classeCont(el.value.length,max); }
}

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
  if (RECOMPOR.includes(chave)){
    clearTimeout(tRecompor); tRecompor = setTimeout(recarregarTela, 900);
  } else {
    enviarTela({tipo:'texto', def:chave, html:paraTela(chave,v)});
  }
  marcarSujo(true); registarPasso();
}
let tRecompor = null;
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
function renderCoresJa(){
  $('cores').innerHTML =
    `<div class="temas">` + Object.entries(TEMAS).map(([k,t])=>
      `<button class="tema-bt" onclick="aplicarTema('${k}')" title="${esc(t.nome)}">
        <i style="background:${t.forest}"></i><i style="background:${t.gold}"></i>${esc(t.nome)}</button>`).join('') +
      `<button class="tema-bt" onclick="aplicarTema('')">Repor</button></div>` +
    TEMA_VARS.map(v=>{
      const cor = EST.paleta[v] || TEMAS['floresta'][v];
      const r = TEMA_ROT[v] || {rotulo:v, onde:''};
      return `<label class="cor-linha" title="${esc(r.onde)}">
          <input type="color" value="${cor}" oninput="editarCor('${v}',this.value)">
          <span>${esc(r.rotulo)}</span>
          ${EST.paleta[v] ? `<button class="bt bt-min" onclick="event.preventDefault();limparCor('${v}')" title="Voltar à cor de origem">↺</button>` : ''}
        </label>
        <div class="cor-onde">${esc(r.onde)}</div>`;
    }).join('');
}
function editarCor(v, cor, el){
  EST.paleta[v] = cor.toUpperCase();
  enviarTela({tipo:'tema', vars:{[v]:EST.paleta[v]}});   // a tela muda de cor na hora
  marcarSujo(true); registarPasso();
  // O ↺ já existe na linha; basta deixar de estar apagado. Fazê-lo nascer aqui
  // mexia no elemento a que o painel de cores do navegador está preso, e ele
  // fechava-se a meio da escolha.
  if (el){
    const bt = el.closest('.cor-linha').querySelector('.repor-cor');
    if (bt){ bt.classList.remove('vazio'); bt.tabIndex = 0; }
  }
}
function limparCor(v){
  delete EST.paleta[v];
  // Sem valor próprio volta a valer o do modelo: manda-se o de origem à tela.
  enviarTela({tipo:'tema', vars:{[v]: TEMAS['floresta'][v]}});
  renderCores(); marcarSujo(true); registarPasso();
  msg('Cor reposta: ' + ((TEMA_ROT[v]||{}).rotulo || v));
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

// ---------- tipografia ----------
function renderTipografiaJa(){
  $('tipografia').innerHTML =
    Object.entries(PAPEIS).map(([papel,p])=>{
      const escolhida = EST.val[p.chave] || p.origem;
      const opcoes = Object.entries(FONTES).filter(([,f])=>f.papeis.includes(papel));
      return `<div class="campo"><label>${esc(p.rotulo)}</label>
        <select onchange="mudarFonte('${p.chave}',this.value)">
          ${opcoes.map(([k,f])=>`<option value="${k}" ${k===escolhida?'selected':''}>${esc(f.nome)}${k===p.origem?' (de origem)':''}</option>`).join('')}
        </select>
        <div class="amostra-f" style="font-family:${FONTES[escolhida].css}">${esc(CASAL_NOME)}</div>
      </div>`;
    }).join('') +
    `<div class="campo"><label>Tamanho do texto<span class="contador">${EST.val['tipo.escala']||100}%</span></label>
      <div class="enq-lin"><input type="range" min="80" max="130" step="5" value="${EST.val['tipo.escala']||100}"
        oninput="mudarEscala(this.value,this)">
        <button class="bt bt-min" onclick="mudarEscala(100)">Repor</button></div>
      <div class="dica-md">Só o texto que se lê. Os nomes, a data e os títulos grandes ficam como o design os deixou.</div>
    </div>`;
}
function mudarFonte(chave, v){
  EST.val[chave] = v;
  marcarSujo(true); registarPasso(); renderTipografia();
  // A família é uma variável CSS: a tela muda sem voltar ao servidor. Mas se a
  // fonte for uma das que não vêm carregadas, é preciso o servidor emitir o
  // @font-face — daí a recarga nesse caso.
  if (FONTES[v].face) recarregarTela();
  else enviarTela({tipo:'tema', vars:{['f-'+chave.slice(5)]: FONTES[v].css}});
  msg('Tipo de letra: ' + FONTES[v].nome);
}
function mudarEscala(v, el){
  EST.val['tipo.escala'] = String(v);
  marcarSujo(true); registarPasso();
  // Redesenhar o painel a meio do arrasto trocava a faixa por baixo do rato e
  // o arrasto morria: só se atualiza o número ao lado do rótulo.
  if (el){ const c = el.closest('.campo').querySelector('.contador'); if (c) c.textContent = v + '%'; }
  else renderTipografia();
  enviarTela({tipo:'tema', vars:{'esc-txt': String(v/100)}});
  msg('Tamanho do texto: ' + v + '%');
}

// ---------- versões guardadas ----------
// O painel em si vive em assets/versoes.js, partilhado com o editor do
// convite impresso; aqui só se lhe dão as amarras desta página.
function renderVersoes(){
  Versoes.montar({
    ambito: 'digital',
    alvo:   'versoes',
    sujo:   () => SUJO,
    msg,
    aoAplicar: () => setTimeout(()=>{ SUJO = false; location.reload(); }, 700)
  });
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
  v['layout.blocos']      = EST.blocos.length ? JSON.stringify(EST.blocos) : '';
  v['layout.ordem']       = EST.ordem.join(',');
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
  if (blocoLivre(SEC)) return msg('Esta secção foi acrescentada por si — use "Apagar esta secção".');
  const s = SECCOES[SEC]; if (!s) return;
  if (!confirm(`Repor os textos originais de "${s.rotulo}"?\n\nPode desfazer com Ctrl+Z.`)) return;
  (s.campos||[]).concat(EXTRA[SEC]||[]).forEach(k=>{ if (k in PADRAO) EST.val[k] = PADRAO[k]; });
  const lk = LISTAS_SEC[SEC];
  if (lk && lk in PADRAO){ try { EST.listas[lk] = JSON.parse(PADRAO[lk]||'[]'); } catch(e){} }
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
  msg(`"${s.rotulo}" reposta — por guardar. Ctrl+Z desfaz.`);
}

// ---------- arranque ----------
renderCamadas(); renderProps(); renderCores(); renderMedia(); renderEfeitos(); renderTipografia(); renderVersoes();
aplicarZoom(); ajustarAltura(); marcarBotoes();
$('tela').addEventListener('load', ()=>{ ajustarAltura(); aplicarFerramenta(); });
recarregarTela();                       // primeira pintura da tela
msg('Clique num texto do convite para o editar.');
</script>
<script src="<?= asset('assets/editor-paineis.js') ?>"></script>
</body>
</html>
