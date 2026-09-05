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
require_once __DIR__ . '/parcial-cabecalho.php';   // tiraSuporte()
// Desenhar um modelo da casa não é entrar em casa de casal nenhum: quem
// responde pela plataforma chega aqui sem ter casamento aberto.
[$DEFS_ED, $MODELO] = defsDoEditor($conn, 'digital');
if (!$MODELO) exigirAdmin(); elseif (!ehAdminPlataforma()) exigirAdmin();
// Desenhar a peça é o que distingue os escalões «com edição» dos outros: quem
// leva o modelo padrão sem edição vê a peça em toda a parte, mas não entra
// aqui. (Um modelo da casa é outra coisa — esse é do admin da plataforma, e o
// $MODELO acima já tratou disso.)
if (!$MODELO && !podeEditarPeca('digital')) {
    header('Location: licenca.php?quero=digital&preciso=editar'); exit;
}
$CAS = $MODELO ? ['casal' => $MODELO['nome'], 'mono' => '◆', 'noiva' => '', 'noivo' => '']
               : casalInfo($DEFS_ED);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $MODELO ? 'Modelo · ' : 'Convite digital · ' ?><?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/editor.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
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
  /* O cadeado é comum aos dois editores: o desenho está em assets/editor.css. */
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
  /* Linhas que a lista mostra mas não governa — as cerimónias, que se escrevem
     acima. Traço a tracejado para se ver, à vista, que ali não se escreve. */
  .it-fixo{ border-style:dashed; background:#1f211c; }
  .it-fixo .sel-nada{ font-size:.72rem; }
  /* O cursor do tamanho, na cor da casa — o azul do sistema destoava de tudo
     o resto do painel. */
  .campo input[type=range]{ width:100%; accent-color:var(--ed-ouro); background:transparent;
    height:20px; cursor:pointer; }

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
  /* Guias magnéticas: aparecem só no instante em que o ponto se cola a elas. */
  .enq-caixa .guias{ position:absolute; inset:0; pointer-events:none; }
  .enq-caixa .guias::before, .enq-caixa .guias::after{ content:''; position:absolute; opacity:0;
        background:var(--ed-ouro); transition:opacity .08s; }
  .enq-caixa .guias::before{ top:0; bottom:0; left:var(--gx,50%); width:1px; }
  .enq-caixa .guias::after{ left:0; right:0; top:var(--gy,50%); height:1px; }
  .enq-caixa .guias.v::before{ opacity:.95; }
  .enq-caixa .guias.h::after{ opacity:.95; }
  .enq-lin{ display:flex; align-items:center; gap:.4rem; margin-top:.35rem; }
  .enq-lin input[type=range]{ flex:1; accent-color:var(--ed-ouro); }
  .enq-rot{ font-size:.68rem; color:var(--ed-texto-2); text-transform:uppercase; letter-spacing:.07em; }
  .enq-dica{ font-size:.66rem; color:var(--ed-texto-2); margin-top:.2rem; }

  .nome-v{ flex:1; min-width:0; font-size:.82rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  @media (max-width:900px){ .ed-paineis{ width:250px; } }
</style>
</head>
<body class="editor">
<?php if ($MODELO): ?>
  <div class="tira-modelo">
    A desenhar o modelo <b><?= escP($MODELO['nome']) ?></b> — um desenho da casa, não o convite de
    um casal. <a href="modelos.php">voltar aos modelos</a>
  </div>
  <style>
    .tira-modelo{ background:var(--gold-pale); border-bottom:1px solid var(--gold-soft); color:var(--ink);
                  text-align:center; padding:.4rem .8rem; font-size:.82rem; }
    .tira-modelo a{ color:inherit; }
  </style>
<?php endif; ?>
<?php tiraSuporte(true); ?>

<div class="ed-menu">
  <div class="marca"><span class="ed-mono"><?= escP($CAS['mono']) ?></span> Editor</div>
  <span class="doc">Convite digital · <b><?= escP($CAS['casal']) ?></b></span>
  <div class="cresce"></div>
  <a href="versao.php" class="versao-app" title="Versão instalada — clique para o detalhe"><?= versaoApp() ?></a>
  <a href="digital.php">← Convite digital</a>
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
  <?php // As versões são de um casamento: guardam o que ESTE casal decidiu. Um
        // modelo da casa não tem versões, e o seletor saía vazio — um controlo
        // que não faz nada é pior do que um controlo que não está lá. ?>
  <?php if (!$MODELO): ?>
    <button id="bt-versao" class="btn-versao"
            title="Versões e modelos">—</button>
    <span class="ed-sep"></span>
  <?php endif; ?>
  <button class="bt" id="bt-repor" onclick="reporSeccao()">Repor Secção</button>
  <button class="bt primario" id="bt-guardar" onclick="guardar()">Guardar</button>
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
        <!-- Onde a tela estava a ser lida, para lá voltar sem se dar por isso. -->
        <input type="hidden" name="tela_y" id="tela-y" value="0">
        <input type="hidden" name="tela_sec" id="tela-sec" value="">
        <input type="hidden" name="tela_dy" id="tela-dy" value="0">
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
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/versoes.js') ?>"></script>
<script src="<?= asset('assets/tela-livre.js') ?>"></script>
<script>window.EDITOR_MIN = { l: <?= EDITOR_MIN_L ?>, a: <?= EDITOR_MIN_A ?> };</script>
<script src="<?= asset('assets/editor-espaco.js') ?>"></script>
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const PADRAO   = <?= json_encode(defsPadrao(), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS   = <?= json_encode($DEFS_ED, JSON_UNESCAPED_UNICODE) ?>;
// Quando se está a desenhar um modelo da casa, é ele que se grava — e não as
// definições de um casamento.
const MODELO   = <?= json_encode($MODELO, JSON_UNESCAPED_UNICODE) ?>;
const SECCOES  = <?= json_encode(seccoesConvite(), JSON_UNESCAPED_UNICODE) ?>;
// Blocos que se podem arrastar na tela, por camada. Só as duas telas de
// tamanho conhecido (o envelope e a capa de entrada) os têm: o resto do
// convite é texto que corre, e uma composição à mão numa página que cresce
// com o conteúdo desmancha-se no telemóvel seguinte.
const LIVRES = <?= json_encode(posicoesLivres($DEFS_ED), JSON_UNESCAPED_UNICODE) ?>;
const MODELOS  = <?= json_encode(modelosBloco(), JSON_UNESCAPED_UNICODE) ?>;
const PRIMEIRO = <?= json_encode(BLOCO_PRIMEIRO) ?>;   // a capa abre sempre
const ULTIMO   = <?= json_encode(BLOCO_ULTIMO) ?>;     // o fecho encerra sempre
const BLOCOS_MAX = <?= (int)BLOCOS_MAX ?>;
const FONTES     = <?= json_encode(fontesConvite(), JSON_UNESCAPED_UNICODE) ?>;
const PAPEIS     = <?= json_encode(papeisTipo(), JSON_UNESCAPED_UNICODE) ?>;
const CASAL_NOME = <?= json_encode($CAS['casal']) ?>;
const MARKDOWN = <?= json_encode(camposMarkdown()) ?>;
const ICONES   = <?= json_encode(iconesConvite()) ?>;
// O nome por que cada ícone se chama nas listas. As chaves são de
// programador; ninguém escolhe uma «crianca».
const NOMES_ICONE   = <?= json_encode(nomesIcones(), JSON_UNESCAPED_UNICODE) ?>;
const NOMES_EMBLEMA = <?= json_encode(nomesEmblemasCasa(), JSON_UNESCAPED_UNICODE) ?>;
const nomeIcone = n => NOMES_ICONE[n] || n;
/** As opções de um selector de ícone, já com nome e por ordem alfabética. */
function opcoesIcone(atual){
  return Object.keys(ICONES)
    .map(n => [n, nomeIcone(n)])
    .sort((a,b) => a[1].localeCompare(b[1], 'pt'))
    .map(([n,r]) => `<option value="${n}"${n===atual?' selected':''}>${esc(r)}</option>`).join('');
}
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
                  'evento.whatsapp','footer.local',
                  // As cerimónias são compostas pelo servidor ("Ás 15h", e o
                  // bloco inteiro a nascer e a morrer com a hora).
                  'evento.civil_titulo','evento.civil_hora','evento.civil_local',
                  'evento.religiosa_titulo','evento.religiosa_hora','evento.religiosa_local'];

const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));

// Definições que este editor NÃO governa (o cartão impresso é editado à parte;
// as de media são gravadas pelo próprio upload).
const ALHEIAS = ['cartao.', 'media.'];

// ---------- rótulos e limites ----------
// Os limites são os que o servidor aplica: mostrá-los evita a surpresa de ver
// o texto cortado depois de gravar.
const CAMPOS = {
  'capa.monograma':['Monograma do selo','texto',12], 'capa.dica':['Dica de abertura','texto',40],
  'casal.noiva':['Nome da noiva','texto',80], 'casal.noivo':['Nome do noivo','texto',80],
  'textos.kicker':['Frase do topo','texto',80], 'textos.hero_sub':['Subtítulo da capa','texto',80],
  'textos.convite_eyebrow':['Chamada','texto',120],
  'textos.lead':['Texto principal','area',4000],
  'textos.guest_label':['Rótulo do convidado','texto',80],
  'textos.closing':['Texto de fecho','area',4000],
  'historia.eyebrow':['Chamada','texto',120], 'historia.titulo':['Título','texto',120],
  'historia.quote':['Citação de abertura','area',4000], 'historia.autor':['Autor da citação','texto',80],
  'interludio.quote':['Citação','area',4000], 'interludio.autor':['Autor','texto',80],
  'interludio.fecho':['Texto de fecho','area',4000],
  'gd.eyebrow':['Chamada','texto',120],
  'evento.venue_titulo':['Título do momento','texto',80],
  'cronograma.titulo':['Título do cronograma','texto',120],
  'evento.civil_titulo':['Nome da cerimónia civil','texto',40],
  'evento.civil_hora':['Hora da cerimónia civil','hora',5],
  'evento.civil_local':['Local da cerimónia civil','texto',80],
  'evento.religiosa_titulo':['Nome da cerimónia religiosa','texto',40],
  'evento.religiosa_hora':['Hora da cerimónia religiosa','hora',5],
  'evento.religiosa_local':['Local da cerimónia religiosa','texto',80],
  'acesso.eyebrow':['Chamada','texto',120], 'acesso.titulo':['Título','texto',120],
  'acesso.instrucao':['Instrução junto ao QR','area',4000], 'acesso.nota':['Nota de rodapé','area',4000],
  'manual.eyebrow':['Chamada do manual','texto',120], 'manual.titulo':['Título do manual','texto',120],
  'manual.intro':['Introdução do manual','area',4000],
  'rsvp.titulo':['Título do RSVP','area',4000], 'rsvp.sub':['Subtítulo do RSVP','area',4000],
  'rsvp.deadline':['Prazo de confirmação','texto',80],
  'footer.local':['Localidade no rodapé','texto',80], 'footer.quote':['Citação do rodapé','area',4000],
  'evento.data':['Data do evento','data',10], 'evento.hora':['Hora','hora',5],
  'evento.local':['Local','texto',120], 'evento.cidade':['Cidade / região','texto',80],
  // 'evento.maps' de propósito fora: a ligação do mapa é dado do evento e
  // edita-se só na gestão dos noivos, não neste editor.
  'evento.whatsapp':['WhatsApp de contacto','texto',20],
};
// Campos extra que cada secção mostra, além dos que se selecionam na tela.
const EXTRA = {
  'hero':['evento.data','evento.hora'],
  // A ligação do Google Maps NÃO se edita aqui: é dado do evento, e muda-se só
  // na área de gestão dos noivos (gestao.php). O editor mostra-a no convite,
  // não a governa. Ver também CAMPOS e RECOMPOR, sem 'evento.maps'.
  'grande-dia':['evento.local','evento.cidade'],
  'final':['footer.local','evento.whatsapp'],
};
// Listas editáveis, por secção
const LISTAS_SEC = { 'historia':'historia.capitulos', 'grande-dia':'cronograma.itens', 'final':'manual.itens' };
// A fotografia (e o seu enquadramento) que cada secção tem. Repor a secção
// repõe também isto — a foto volta à do desenho de origem, e a que o casal
// tenha posto à mão é largada. A música de fundo não é de secção nenhuma.
const MEDIA_SEC = {
  'hero':       ['media.hero', 'foto.hero'],
  'historia':   ['media.historia'],
  'interludio': ['media.interludio', 'foto.interludio'],
  'acesso':     ['media.acesso', 'foto.acesso'],
};
// TODAS as chaves de origem que pertencem a cada secção — para repor a secção
// INTEIRA (textos, estilos próprios como o selo/abertura, visibilidade, o
// enquadramento e a foto), e não só uns campos à mão. As cores, os tipos de
// letra e a escala do texto são do convite INTEIRO (tema global), não de uma
// secção: repô-los aqui mudaria a peça toda, e por isso ficam de fora.
const SEC_DONO = {
  'hero':       k => ['textos.kicker','textos.hero_sub','casal.noiva','casal.noivo','media.hero','foto.hero'].includes(k),
  'convite':    k => ['textos.convite_eyebrow','textos.lead','textos.guest_label','textos.closing'].includes(k),
  'historia':   k => /^historia\./.test(k) || k === 'media.historia',
  'interludio': k => /^interludio\./.test(k) || k === 'media.interludio' || k === 'foto.interludio',
  'grande-dia': k => /^(gd|evento|cronograma|cer)\./.test(k),
  'acesso':     k => /^acesso\./.test(k) || k === 'media.acesso' || k === 'foto.acesso',
  'final':      k => /^(rsvp|manual|footer)\./.test(k),
  'capa':       k => /^capa\./.test(k),
};
function chavesDaSeccao(sec){
  const m = SEC_DONO[sec]; return m ? Object.keys(PADRAO).filter(m) : [];
}
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
  // Secções trancadas: não se arrastam nem se escondem sem primeiro destrancar.
  // Numa lista que se reordena a arrastar, um gesto distraído desfaz o que se
  // levou meia hora a compor — e o desfazer nem sempre se lembra de o dizer.
  trancados: new Set((ATUAIS['layout.trancados']||'').split(',').filter(Boolean),),
  // Onde cada bloco móvel foi parar, em % da sua tela. Sem entrada = no sítio
  // que o design lhe deu.
  pos: (()=>{ try { return JSON.parse(ATUAIS['layout.posicoes']||'{}') || {}; } catch(e){ return {}; } })(),
};
// O gravado é "x y"; cá dentro dá jeito ter os números à mão.
function posDe(id){
  const v = EST.pos[id]; if (!v) return {x:0, y:0, a:0};
  const p = String(v).split(/\s+/);
  return { x: parseFloat(p[0])||0, y: parseFloat(p[1])||0, a: parseFloat(p[2])||0 };
}
function ler(k){ try { return JSON.parse(ATUAIS[k]||'[]')||[]; } catch(e){ return []; } }

// ---------- as camadas: secções de origem + as livres, pela ordem ----------
function blocoLivre(id){ return EST.blocos.find(b=>b.id===id) || null; }
/** Lista ordenada de camadas, com a capa à cabeça e o fecho no fim. */
// A capa que abre (o envelope selado com o monograma) é a primeira camada,
// sempre fixa e sempre visível: é a porta de entrada do convite.
const CAPA_ID = 'capa';
const CAPA_ROTULO = 'Envelope';
function camadas(){
  const validos = Object.keys(SECCOES).concat(EST.blocos.map(b=>b.id));
  const ord = [];
  EST.ordem.forEach(id=>{ if (validos.includes(id) && !ord.includes(id)) ord.push(id); });
  validos.forEach(id=>{ if (!ord.includes(id)) ord.push(id); });
  const meio = ord.filter(id=>id!==PRIMEIRO && id!==ULTIMO);
  const final = [PRIMEIRO, ...meio, ULTIMO];
  EST.ordem = final;
  const conteudo = final.map(id=>{
    const b = blocoLivre(id);
    return b ? { id, rotulo: b.titulo || 'Secção livre', livre: true, fixa: false }
             : { id, rotulo: SECCOES[id] ? SECCOES[id].rotulo : id, livre: false,
                 fixa: (id===PRIMEIRO || id===ULTIMO) };
  });
  return [{ id: CAPA_ID, rotulo: CAPA_ROTULO, livre: false, fixa: true }, ...conteudo];
}
/** Rótulo de uma camada, seja secção, envelope ou bloco livre. */
function rotuloCamada(k){
  if (k === CAPA_ID) return CAPA_ROTULO;
  const b = blocoLivre(k);
  return b ? (b.titulo || 'secção livre') : (SECCOES[k] ? SECCOES[k].rotulo : k);
}
/** Iniciais para o monograma automático (o servidor faz a versão definitiva). */
function inicialJS(s){ s = (s||'').trim(); return s ? s[0].toUpperCase() : ''; }
function monogramaAuto(){ return inicialJS(EST.val['casal.noiva']) + '&' + inicialJS(EST.val['casal.noivo']); }
function novoId(){
  let n = 1, id;
  do { id = 'bl' + n++; } while (EST.blocos.some(b=>b.id===id));
  return id;
}

let SEC = CAPA_ID;     // camada selecionada — abre no Envelope, a porta de entrada
let DEF = null;        // texto selecionado dentro dela
let SUJO = false;
let telaPronta = false;
// Verdadeiro enquanto é o EDITOR a pôr o cursor num campo, e não a pessoa.
// Sem esta distinção, clicar num texto dentro da tela dava duas ordens: uma a
// marcar o que se clicou (sem mexer) e outra, vinda do foco automático do
// campo, a ir buscá-lo — e a tela saltava logo a seguir ao clique.
let focoAutomatico = false;

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
// Uma foto trocada só FICA se o casal guardar/actualizar uma versão. Se sair
// sem o fazer — incluindo fechar a aba ou o navegador —, avisa-se o servidor
// para repor a foto anterior e apagar a nova. O servidor não faz nada se, afinal,
// já estiver tudo guardado (a troca deixou de estar pendente ao guardar a versão).
let FOTO_TROCADA = false;
window.addEventListener('pagehide', () => {
  if (!FOTO_TROCADA) return;
  try {
    navigator.sendBeacon('api.php?action=media_descartar&csrf=' + encodeURIComponent(window.CSRF || ''));
  } catch (e) {}
});
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

// ---------- posicionamento livre ----------
// O bloco escolhido na tela (o que as propriedades mostram e as setas afinam).
let LIVRE = null;

// Uma secção livre criada nesta sessão ainda não existia quando o servidor
// escreveu LIVRES. Os blocos dela são sempre os mesmos cinco, por isso
// compõem-se aqui — senão, uma secção acabada de criar só se deixava compor
// depois de recarregar a página.
const LIVRE_BLOCOS = [['numero','Número da página','.pageno'], ['chamada','Chamada','.eyebrow'],
                      ['titulo','Título','h2'], ['texto','Texto','.bl-texto'],
                      ['itens','Destaques','.mgrid']];
/** LIVRES, com as secções livres de agora acrescentadas. */
function livresTodos(){
  const fora = Object.assign({}, LIVRES);
  EST.blocos.forEach(b => {
    if (!/^bl[a-z0-9-]{1,20}$/.test(b.id || '')) return;
    LIVRE_BLOCOS.forEach(([k, rot, sel]) => {
      const id = b.id + ':' + k;
      if (!fora[id]) fora[id] = { rotulo:rot, sec:b.id, tela:'#'+b.id, sel:'#'+b.id+' '+sel, fixa:false };
    });
  });
  return fora;
}

/** O mapa gravado, com os números já separados — é o que a tela recebe. */
function mapaPos(){
  const fora = {};
  Object.keys(EST.pos).forEach(id => { fora[id] = posDe(id); });
  return fora;
}
/** Grava (ou apaga, quando volta à origem) o deslocamento e a volta. */
function guardarPos(id, x, y, a){
  a = a || 0;
  if (Math.abs(x) < 0.005 && Math.abs(y) < 0.005 && Math.abs(a) < 0.05) delete EST.pos[id];
  else EST.pos[id] = x + ' ' + y + (a ? ' ' + a : '');
}
function moverLivre(id, x, y, a){
  a = a || 0;
  guardarPos(id, x, y, a);
  enviarTela({tipo:'pos', id:id, x:x, y:y, a:a});
}
/** Só a volta, deixando o bloco onde está. */
function virarLivre(id, v, el){
  const p = posDe(id);
  moverLivre(id, p.x, p.y, TelaLivre.limitarAng(+v || 0));
  marcarSujo(true); registarPasso();
  if (el){ const c = el.closest('.campo').querySelector('.contador'); if (c) c.textContent = (+v||0) + '°'; }
  else renderProps();
  msg(`${(livresTodos()[id]||{}).rotulo || id}: ${(+v||0)}°`);
}
function reporLivre(id){
  const bl = livresTodos()[id] || { rotulo:id };
  if (!EST.pos[id]) return msg(`"${bl.rotulo}" já está no sítio de origem.`);
  moverLivre(id, 0, 0, 0);
  marcarSujo(true); registarPasso(); renderProps();
  msg(`"${bl.rotulo}" voltou ao sítio de origem.`);
}
async function reporLivresDa(sec){
  const L = livresTodos();
  const ids = Object.keys(L).filter(id => L[id].sec === sec && EST.pos[id]);
  if (!ids.length) return msg('Nada foi movido nesta camada.');
  const r = await licConfirmar({
    titulo: 'Repor a composição desta camada?',
    icone: '↩️', confirmar: 'Repor composição',
    texto: '<b>' + ids.length + '</b> bloco(s) voltam ao sítio que o design lhes deu.'
         + '<br><br><b>Ctrl+Z desfaz</b>, e nada fica gravado até guardar.'
  });
  if (!r.sim) return;
  ids.forEach(id => moverLivre(id, 0, 0, 0));
  marcarSujo(true); registarPasso(); renderProps();
  msg('Composição de origem reposta — por guardar.');
}
function escolherLivre(id){ LIVRE = id; renderProps(); enviarTela({tipo:'livre-sel', id:id}); }

/**
 * O painel de posição de uma camada. Só aparece nas duas que o têm; nas
 * outras, dizer "arraste" seria prometer o que a página não faz.
 */
function painelLivre(sec){
  const L = livresTodos();
  const ids = Object.keys(L).filter(id => L[id].sec === sec);
  if (!ids.length) return '';
  const num = n => (n > 0 ? '+' : '') + n.toFixed(2).replace(/\.?0+$/, '') + '%';
  const movidos = ids.filter(id => EST.pos[id]).length;
  const fixa = !!L[ids[0]].fixa;
  return `<div class="grupo"><h4>Posição dos blocos${movidos ? ` <span class="op">${movidos} movido${movidos>1?'s':''}</span>` : ''}</h4>
    ${ids.map(id => {
      const p = posDe(id), mov = !!EST.pos[id];
      const onde = mov ? [num(p.x) + ' · ' + num(p.y)].concat(p.a ? [p.a + '°'] : []).join(' · ')
                       : 'no sítio de origem';
      return `<div class="campo">
        <label>${esc(L[id].rotulo)}</label>
        <div class="pos-linha">
          <span class="val">${onde}</span>
          <button class="bt bt-min ${LIVRE===id?'primario':''}" onclick="escolherLivre('${id}')" title="Assinalar na tela">Ver</button>
          <button class="bt bt-min" onclick="reporLivre('${id}')" ${mov?'':'disabled'}>Repor</button>
        </div>
      </div>`;
    }).join('')}
    ${LIVRE && L[LIVRE] && L[LIVRE].sec === sec ? (() => {
      // A volta é do bloco escolhido, e só dele: uma barra por cada um dos
      // nove blocos desta página seria um painel que ninguém lê.
      const a = posDe(LIVRE).a;
      return `<div class="campo"><label>Volta de «${esc(L[LIVRE].rotulo)}»<span class="contador">${a}°</span></label>
        <div class="vs-lin"><input type="range" min="-180" max="180" step="1" value="${a}"
          oninput="virarLivre('${LIVRE}', this.value, this)" style="flex:1">
          <button class="bt bt-min" onclick="virarLivre('${LIVRE}', 0)">Repor</button></div>
        <div class="ajuda">Vira o bloco à volta do próprio centro. Também com <b>Alt</b> + arrastar na tela
          (encosta de 15 em 15 graus; o <b>Shift</b> solta-a), ou <b>Alt</b> + setas.</div></div>`;
    })() : `<div class="ajuda">Escolha um bloco (<b>Ver</b>, ou clique nele na tela) para lhe dar volta.</div>`}
    <div class="ajuda">Arraste cada bloco na tela. Cola-se ao centro, às bordas e aos outros blocos —
      o <b>Shift</b> desliga o íman e as <b>setas</b> afinam ponto a ponto.
      ${fixa
        ? 'O deslocamento é guardado em percentagem desta tela, para a composição chegar inteira ao telemóvel.'
        : 'Esta página cresce com o texto que lá está, por isso o deslocamento mede-se em percentagem da '
          + '<b>largura</b> — a única medida que não muda quando um parágrafo passa de três linhas a seis. '
          + 'O bloco movido <b>flutua</b>: deixa o lugar aberto e não empurra os vizinhos.'}</div>
    ${movidos ? `<button class="bt" style="width:100%;margin-top:.4rem" onclick="reporLivresDa('${sec}')">Repor a composição desta camada</button>` : ''}
  </div>`;
}

/**
 * As duas cerimónias, no painel: cada uma acrescenta-se e remove-se por
 * inteiro. É a HORA que decide se existe — remover é limpá-la —, mas fazer
 * isso à mão num campo de hora não se descobre.
 *
 * O convite digital nem sequer as mostrava: um casal que tivesse marcado a
 * igreja no registo via essa informação só no papel.
 */
const CERIMONIAS = [
  ['civil',     'Cerimónia civil',     '10:30'],
  ['religiosa', 'Cerimónia religiosa', '15:00']
];
function blocoCerimonias(){
  return `<div class="grupo"><h4>Cerimónias</h4>
    <div class="ajuda">Opcionais, as duas. Há casamentos só com uma, e há quem faça as duas
      no mesmo sítio — o que não for acrescentado não aparece no convite. São as mesmas do
      cartão impresso: escritas aqui, valem nas duas peças.</div>
    ${CERIMONIAS.map(([k, rot, horaPadrao]) => {
      const hora = EST.val['evento.' + k + '_hora'] || '';
      if (!hora) {
        return `<div class="campo cer-fora">
          <button class="bt" style="width:100%" onclick="acrescentarCerimonia('${k}','${horaPadrao}')">
            + Acrescentar ${rot.toLowerCase()}</button></div>`;
      }
      return `<div class="campo cer-dentro">
        <label>${rot}
          <button class="bt bt-min" onclick="removerCerimonia('${k}','${rot}')"
                  title="Tirar esta cerimónia do convite">Remover</button></label>
        <input type="text" data-chave="evento.${k}_titulo" maxlength="40"
               value="${esc(EST.val['evento.' + k + '_titulo'] || '')}"
               placeholder="Como se chama" oninput="editar(this)">
        <div class="vs-lin" style="margin-top:.35rem">
          <input type="time" data-chave="evento.${k}_hora" value="${esc(hora)}"
                 oninput="editar(this)" style="flex:0 0 110px">
          <input type="text" data-chave="evento.${k}_local" maxlength="80"
                 value="${esc(EST.val['evento.' + k + '_local'] || '')}"
                 placeholder="Onde é (opcional)" oninput="editar(this)" style="flex:1">
        </div>
      </div>`;
    }).join('')}
  </div>`;
}
/**
 * O aspeto dos cartões: o emblema de cada um, os ramos, o tamanho e a moldura.
 *
 * O emblema escolhe-se por cartão — há quem queira a igreja na religiosa e as
 * alianças na civil —, mas os ramos, o tamanho e a moldura valem para os três:
 * são o aspeto do CONJUNTO, e três cartões com molduras diferentes não são um
 * conjunto, são três cartões.
 */
const EMBLEMAS_CER = [['civil','Cerimónia civil'],['religiosa','Cerimónia religiosa'],
                      ['copo','Copo d’água']];
function blocoAspetoCerimonias(){
  // O primeiro da lista é o emblema que a casa desenhou para aquele cartão, e
  // chama-se pelo que é: «Igreja», «Pergaminho e pena», «Taças em brinde».
  const ops = (k, atual) =>
    `<option value="original"${atual==='original'?' selected':''}>${esc(NOMES_EMBLEMA[k]||'Emblema da casa')}</option>`
    + opcoesIcone(atual);
  const tam = parseInt(EST.val['cer.tamanho']||'100',10) || 100;
  return `<div class="grupo"><h4>Emblemas e molduras</h4>
    <div class="ajuda">O emblema que encima cada cartão, e como o conjunto se apresenta.
      Cada escolha aparece na tela ao lado.</div>
    ${EMBLEMAS_CER.map(([k,rot])=>`<div class="campo">
      <label>Emblema · ${rot}</label>
      <select onchange="mudarEmblema('${k}',this.value)">${ops(k, EST.val['cer.emblema_'+k]||'original')}</select>
    </div>`).join('')}
    <div class="campo"><label style="display:flex;align-items:center;gap:.4rem;text-transform:none;letter-spacing:0">
      <input type="checkbox" ${EST.val['cer.ramos']!=='0'?'checked':''} onchange="alternarCer('cer.ramos')"
             style="width:15px;height:15px;accent-color:var(--ed-ouro);cursor:pointer"> Com ramos à volta do emblema</label>
      <div class="dica-md">Sem ramos, fica só o anel — o mesmo desenho, mais discreto.</div></div>
    <div class="campo"><label style="display:flex;align-items:center;gap:.4rem;text-transform:none;letter-spacing:0">
      <input type="checkbox" ${EST.val['cer.moldura']!=='0'?'checked':''} onchange="alternarCer('cer.moldura')"
             style="width:15px;height:15px;accent-color:var(--ed-ouro);cursor:pointer"> Mostrar as molduras dos cartões</label>
      <div class="dica-md">Sem moldura, os cartões ficam só com o que lá está escrito.</div></div>
    <div class="campo"><label>Tamanho do emblema<span class="contador">${tam}%</span></label>
      <input type="range" min="60" max="160" step="10" value="${tam}"
             oninput="mudarTamanhoEmblema(this.value)" style="width:100%">
    </div>
  </div>`;
}
function mudarEmblema(k, v){
  if (v !== 'original' && !(v in ICONES)) v = 'original';
  EST.val['cer.emblema_' + k] = v;
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
}
function alternarCer(chave){
  EST.val[chave] = EST.val[chave] === '0' ? '1' : '0';
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
}
let tTamEmb = null;
function mudarTamanhoEmblema(v){
  v = String(Math.max(60, Math.min(160, parseInt(v,10) || 100)));
  EST.val['cer.tamanho'] = v;
  const c = document.querySelector('#props input[type=range]');
  if (c){ const lbl = c.closest('.campo').querySelector('.contador'); if (lbl) lbl.textContent = v + '%'; }
  marcarSujo(true);
  // Arrastar um cursor dispara dezenas de vezes: recarrega-se a tela quando a
  // mão pára, e não a cada pixel.
  clearTimeout(tTamEmb);
  tTamEmb = setTimeout(()=>{ registarPasso(); recarregarTela(); }, 320);
}
function acrescentarCerimonia(k, horaPadrao){
  EST.val['evento.' + k + '_hora'] = horaPadrao;
  if (!EST.val['evento.' + k + '_titulo']) EST.val['evento.' + k + '_titulo'] = PADRAO['evento.' + k + '_titulo'];
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
  msg('Cerimónia acrescentada — ajuste a hora e o local.');
}
async function removerCerimonia(k, rot){
  const r = await licConfirmar({
    titulo: 'Tirar a ' + licEsc(rot.toLowerCase()) + ' do convite?',
    icone: '⛪', confirmar: 'Tirar do convite',
    texto: 'A <b>hora</b> e o <b>local</b> desta cerimónia são apagados, e ela deixa de se '
         + 'anunciar aos convidados.<br><br><b>Ctrl+Z desfaz.</b>'
  });
  if (!r.sim) return;
  EST.val['evento.' + k + '_hora'] = '';
  EST.val['evento.' + k + '_local'] = '';
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
  msg(`"${rot}" deixou de se anunciar.`);
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
    enviarTela({tipo:'capa', mostrar: SEC===CAPA_ID});   // a tela recarrega escondida
    enviarTela({tipo:'livres', mapa:livresTodos(), pos:mapaPos()});
    // Assinala a secção, mas SEM a ir buscar: a tela acabou de repor o ponto
    // onde estava. Rolar aqui era o segundo tempo do salto.
    if (SEC) enviarTela({tipo:'marcar', sec:SEC, def:DEF, rolar:false});
    return;
  }
  // Arrasto na tela: o gesto correu lá dentro, aqui só chega o resultado.
  if (d.tipo === 'pegou'){ if (livresTodos()[d.id]) { LIVRE = d.id; renderProps(); } return; }
  if (d.tipo === 'moveu'){
    const bl = livresTodos()[d.id]; if (!bl) return;
    LIVRE = d.id;
    guardarPos(d.id, d.x, d.y, d.a);
    marcarSujo(true); registarPasso(); renderProps();
    msg(`${bl.rotulo}: ${d.x}% · ${d.y}%${d.a ? ' · ' + d.a + '°' : ''}`);
    return;
  }
  if (d.tipo === 'atalho'){
    if (d.tecla === 'z') d.shift ? refazer() : desfazer();
    if (d.tecla === 'y') refazer();
    if (d.tecla === 's') guardar();
    return;
  }
  if (d.tipo === 'selecionar'){
    if (d.sec && (d.sec===CAPA_ID || SECCOES[d.sec] || blocoLivre(d.sec))) SEC = d.sec;
    DEF = (d.def && CAMPOS[d.def]) ? d.def : null;
    renderCamadas(); renderProps();
    enviarTela({tipo:'capa', mostrar: SEC===CAPA_ID});
    // Clicou-se DENTRO da tela: o que se escolheu já está à vista.
    enviarTela({tipo:'marcar', sec:SEC, def:DEF, rolar:false});
    if (DEF){ msg('A editar: ' + CAMPOS[DEF][0]);
              const el = document.querySelector('#props [data-chave="'+DEF+'"]');
              if (el){ focoAutomatico = true;
                       el.focus(); el.setSelectionRange(el.value.length, el.value.length);
                       focoAutomatico = false; } }
    else msg('Camada: ' + rotuloCamada(SEC));
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
  // A tela vai ser recomposta de raiz, e um documento novo começa no princípio.
  // Leva consigo o sítio onde estava a ser lida: sem isto, cada retoque punha o
  // convite no topo e mandava-o descer outra vez até à secção — a maquete
  // andava para cima e para baixo a cada tecla.
  const a = ancoraTela();
  $('tela-y').value   = String(a.y);
  $('tela-sec').value = a.sec;
  $('tela-dy').value  = String(a.dy);
  $('rascunho').value = JSON.stringify(serializar());
  $('f-tela').submit();
}
/**
 * Onde a tela está a ser lida — e não só a que altura.
 *
 * Um número de pixéis não resiste: a página recomposta raramente tem a altura
 * exacta da anterior (uma linha a mais no texto, uma fotografia que ainda não
 * chegou), e voltar ao mesmo pixel deixava a secção uns 300 mais acima ou mais
 * abaixo. Guarda-se a secção que ocupa o topo da vista e a que distância dele
 * está: isso reencontra-se em qualquer altura de página.
 */
function ancoraTela(){
  try {
    const w = $('tela').contentWindow; if (!w) return {y:0, sec:'', dy:0};
    const y = Math.max(0, Math.round(w.scrollY || 0));
    let sec = '', dy = 0, achou = false;
    w.document.querySelectorAll('[data-sec]').forEach(el => {
      const t = el.getBoundingClientRect().top;
      if (t <= 4) { sec = el.dataset.sec; dy = Math.round(t); achou = true; }   // a última já passada
      else if (!achou && !sec) { sec = el.dataset.sec; dy = Math.round(t); }    // ainda nenhuma: a primeira à vista
    });
    return {y, sec, dy};
  } catch (e) { return {y:0, sec:'', dy:0}; }
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
const CADEADO_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>';
const CADEADO_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 017.5-2"/></svg>';

/** Tranca/destranca uma secção. Trancada não se arrasta, não se esconde. */
function alternarTranca(id){
  if (EST.trancados.has(id)) EST.trancados.delete(id); else EST.trancados.add(id);
  marcarSujo(true);
  renderCamadas();
  msg(EST.trancados.has(id) ? 'Secção trancada — não se arrasta nem se esconde.' : 'Secção destrancada.');
}
function renderCamadas(){
  const lista = camadas();
  $('camadas').innerHTML = lista.map((c,i)=>{
    const chaveVis = VISIVEL[c.id];
    const podeEsconder = !!chaveVis || c.livre;
    const vis = c.livre ? true : (!chaveVis || EST.val[chaveVis] !== '0');
    const trancada = EST.trancados.has(c.id);
    const movivel = !c.fixa && !trancada;
    return `<div class="camada ${SEC===c.id?'sel':''} ${vis?'':'oculta'} ${movivel?'':'fixa'} ${trancada?'trancada':''}"
      draggable="${movivel}" data-id="${c.id}" data-i="${i}"
      ondragstart="arrastarCamada(event)" ondragover="sobreCamada(event)"
      ondrop="largarCamada(event)" ondragend="fimArrasto(event)"
      onclick="irCamada('${c.id}')">
      <button class="olho" title="${trancada ? 'Trancada: destranque para esconder'
                                             : (podeEsconder ? (vis?'Esconder esta secção':'Mostrar esta secção') : 'Esta secção é sempre visível')}"
              onclick="event.stopPropagation();${(chaveVis && !trancada)?`alternarSec('${c.id}')`:''}">${vis?OLHO_ON:OLHO_OFF}</button>
      <span class="nome">${esc(c.rotulo)}</span>
      ${c.livre ? '<span class="op">livre</span>' : (c.fixa ? '<span class="op">fixa</span>' : '')}
      <button class="cadeado" title="${trancada ? 'Destrancar' : 'Trancar: não se arrasta nem se esconde'}"
              onclick="event.stopPropagation();alternarTranca('${c.id}')">${trancada?CADEADO_ON:CADEADO_OFF}</button>
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
async function apagarBloco(id){
  const b = blocoLivre(id); if (!b) return;
  const r = await licConfirmar({
    titulo: 'Apagar a secção «' + licEsc(b.titulo || 'livre') + '»?',
    icone: '🗑️', perigo: true, confirmar: 'Apagar secção',
    texto: 'A secção sai do convite, com o que tem lá dentro.'
         + '<br><br><b>Ctrl+Z devolve-a</b>, e nada fica gravado até guardar.'
  });
  if (!r.sim) return;
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
  renderCamadas(); renderProps(); rotularBotaoRepor();
  enviarTela({tipo:'capa', mostrar: k===CAPA_ID});   // revela ou esconde o envelope
  // Escolher uma camada é pedir para a ver: esta é a única marcação que rola.
  enviarTela({tipo:'marcar', sec:k, def:null, rolar:true});
  msg('Camada: ' + rotuloCamada(k));
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
  rotularBotaoRepor();               // o botão de repor acompanha sempre a secção
  if (SEC === CAPA_ID) return renderPropsCapa();
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
  if (SEC === 'grande-dia') h += blocoCerimonias() + blocoAspetoCerimonias();
  const lk = LISTAS_SEC[SEC];
  if (lk) h += listaHTML(lk);
  h += painelLivre(SEC);
  $('props').innerHTML = h;
  if (DEF){ const el = document.querySelector('#props [data-chave="'+DEF+'"]'); if (el) el.closest('.campo').scrollIntoView({block:'nearest'}); }
}
/** Propriedades da capa que abre (o envelope selado). */
function renderPropsCapa(){
  let h = `<div class="sel-nada" style="margin-bottom:.6rem"><b>${esc(CAPA_ROTULO)}</b> — a capa fechada que os convidados tocam para abrir.</div>`;
  h += campoHTML('capa.monograma');
  h += `<div class="dica-md" style="margin-top:-.35rem">Vazio = as iniciais dos nomes (<b>${esc(monogramaAuto())}</b>).
        O monograma aparece no selo, no separador do convite e no rodapé.</div>`;
  h += seloHTML();
  h += campoHTML('capa.dica');
  h += aberturaHTML();
  h += `<div class="sel-nada" style="margin-top:.7rem;line-height:1.5">Os <b>nomes</b> e a <b>data</b> na capa vêm da camada
        <b>Capa</b> e da data do evento — mudam-se aí, e a capa acompanha.</div>`;
  h += painelLivre(CAPA_ID);
  $('props').innerHTML = h;
  if (DEF){ const el = document.querySelector('#props [data-chave="'+DEF+'"]'); if (el) el.closest('.campo').scrollIntoView({block:'nearest'}); }
}
// As aberturas do envelope, pela mesma ordem que o servidor aceita.
const ABERTURAS = [['portas','Portas ao meio'],['subir','A subir'],
                   ['cruzado','Cruzado'],['esvair','A esvair-se']];
function aberturaHTML(){
  const atual = EST.val['capa.abertura'] || 'portas';
  const ops = ABERTURAS.map(([v,r])=>`<option value="${v}"${v===atual?' selected':''}>${esc(r)}</option>`).join('');
  return `<div class="campo"><label>Abertura</label>
    <select onchange="mudarAbertura(this.value)">${ops}</select>
    <div class="dica-md">Como o envelope se abre ao toque. Toque no seletor para ver cada uma na tela.</div></div>`;
}
function mudarAbertura(v){
  if (!ABERTURAS.some(([k])=>k===v)) v = 'portas';
  EST.val['capa.abertura'] = v;
  marcarSujo(true); registarPasso();
  // Mostra a abertura escolhida na tela, sem recarregar: joga a animação uma vez.
  enviarTela({tipo:'capa_previa', abre:v});
}
// Os feitios do selo do monograma, pela mesma ordem que o servidor aceita.
const SELOS = [['cera','Cera'],['anel','Anel'],['camafeu','Camafeu'],['liso','Liso']];
function seloHTML(){
  const atual = EST.val['capa.selo'] || 'cera';
  const ops = SELOS.map(([v,r])=>`<option value="${v}"${v===atual?' selected':''}>${esc(r)}</option>`).join('');
  return `<div class="campo"><label>Selo do monograma</label>
    <select onchange="mudarSelo(this.value)">${ops}</select>
    <div class="dica-md">O desenho do selo na capa. Escolha para o ver na tela.</div></div>`;
}
function mudarSelo(v){
  if (!SELOS.some(([k])=>k===v)) v = 'cera';
  EST.val['capa.selo'] = v;
  marcarSujo(true); registarPasso();
  enviarTela({tipo:'capa_selo', selo:v});   // troca o feitio na tela, sem recarregar
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
            ${opcoesIcone(it.i)}</select>
          <button class="bt bt-min" onclick="removerItemBloco('${b.id}',${i})" title="Remover">✕</button>
        </div>
        <input type="text" placeholder="Título" value="${esc(it.t||'')}" oninput="editarItemBloco('${b.id}',${i},'t',this.value)">
        <textarea placeholder="Texto" oninput="editarItemBloco('${b.id}',${i},'x',this.value)">${esc(it.x||'')}</textarea>
      </div>`).join('') +
    (itens.length < 8 ? `<button class="bt" style="width:100%" onclick="juntarItemBloco('${b.id}')">+ Destaque</button>` : '') +
    painelLivre(b.id) +
    `<div class="campo" style="margin-top:.8rem"><button class="bt" style="width:100%;color:#e08a7d" onclick="apagarBloco('${b.id}')">Apagar esta secção</button></div>`;
}
/** Atualiza o contador do campo que está a ser escrito. */
function contarAqui(el, max){
  const c = el.closest('.campo').querySelector('.contador');
  if (c){ c.textContent = el.value.length+'/'+max; c.className = 'contador '+classeCont(el.value.length,max); }
}

function focar(chave){
  DEF = chave;
  // Pôr o cursor num campo é perguntar onde ele está: aí vale a pena rolar.
  enviarTela({tipo:'marcar', sec:SEC, def:chave, rolar:!focoAutomatico});
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
  } else if (chave === 'capa.monograma'){
    // Vazio volta às iniciais automáticas, como fará o servidor ao gravar.
    enviarTela({tipo:'texto', def:chave, html: esc(v.trim() || monogramaAuto())});
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
/**
 * As cerimónias, como linhas do cronograma.
 *
 * Elas ABREM o dia — é lá que estão no convite —, e por isso têm de estar aqui
 * também, na mesma ordem: quem olha para a lista quer ver o dia como ele vai
 * sair. A hora e o nome não se escrevem aqui, porque já se escreveram em
 * «Cerimónias»: mostram-se, e diz-se onde se mudam. O que se escolhe é o
 * ícone, como em qualquer outra linha.
 */
function horaCartaoJS(h){ return String(h||'').slice(0,5).replace(':','H').toUpperCase(); }
function nomeCerimonia(k){
  const t = (EST.val['evento.'+k+'_titulo'] || PADRAO['evento.'+k+'_titulo'] || 'Cerimónia').trim();
  const m = /^(cerim[óo]nia)\s+(.+)$/i.exec(t);
  return m ? m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase() + ' ' + m[2].toLowerCase() : t;
}
function linhasCerimoniaCrono(){
  const linhas = [];
  [['civil','Manhã'],['religiosa','Tarde']].forEach(([k])=>{
    const hora = EST.val['evento.' + k + '_hora'] || '';
    if (!hora) return;
    linhas.push({ k, hora, h: horaCartaoJS(hora), nome: nomeCerimonia(k) });
  });
  linhas.sort((a,b)=>a.h.localeCompare(b.h));
  if (!linhas.length) return '';
  return linhas.map(l=>{
    const ic = EST.val['cronograma.icone_' + l.k] || 'selo';
    // A primeira opção é o selo — o emblema do cartão em pequeno —, e diz de
    // qual se trata: «Selo · Igreja». As outras são os ícones de traço, com o
    // mesmo nome que têm nas restantes linhas do dia.
    const emb = EST.val['cer.emblema_' + l.k] || 'original';
    const nomeSelo = emb === 'original' ? (NOMES_EMBLEMA[l.k] || 'emblema do cartão') : nomeIcone(emb);
    const ops = `<option value="selo"${ic==='selo'?' selected':''}>Selo · ${esc(nomeSelo)}</option>`
      + opcoesIcone(ic);
    return `<div class="it it-fixo">
      <div class="it-topo"><span class="n">⛪</span>
        <select onchange="mudarIconeCerimonia('${l.k}',this.value)" style="width:auto;margin:0;flex:1">${ops}</select>
      </div>
      <div class="sel-nada" style="text-align:left;padding:.1rem 0 0">
        <b>${esc(l.h)}</b> · ${esc(l.nome)} — a hora e o nome mudam-se em <b>Cerimónias</b>, aqui em cima.</div>
    </div>`;
  }).join('');
}
function mudarIconeCerimonia(k, v){
  if (v !== 'selo' && !(v in ICONES)) v = 'selo';
  EST.val['cronograma.icone_' + k] = v;
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
}

function listaHTML(lk){
  const cfg = LISTA_CAMPOS[lk], itens = EST.listas[lk]||[];
  let h = `<div class="campo"><label>${cfg.rot}<span class="contador ${classeCont(itens.length,cfg.max)}">${itens.length}/${cfg.max}</span></label></div>`;
  // As cerimónias vêm à cabeça, como no convite.
  if (lk === 'cronograma.itens') h += linhasCerimoniaCrono();
  h += itens.map((it,i)=>`<div class="it">
      <div class="it-topo"><span class="n">${i+1}</span>
        ${cfg.icone?`<select onchange="editarItem('${lk}',${i},'i',this.value)" style="width:auto;margin:0;flex:1">
          ${opcoesIcone(it.i)}</select>`:'<span class="cresce"></span>'}
        <button class="bt bt-min" onclick="moverItem('${lk}',${i},-1)" ${i===0?'disabled':''}
                title="Subir">↑</button>
        <button class="bt bt-min" onclick="moverItem('${lk}',${i},1)" ${i===itens.length-1?'disabled':''}
                title="Descer">↓</button>
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
/**
 * Troca um item de lugar. O cronograma é uma linha do tempo — a ordem é o
 * assunto dele —, e até aqui só se podia acertar apagando e voltando a
 * escrever. As setas valem para todas as listas: os capítulos da história
 * também se contam por ordem.
 */
function moverItem(lk, i, d){
  const l = EST.listas[lk]; if (!l) return;
  const j = i + d; if (j < 0 || j >= l.length) return;
  [l[i], l[j]] = [l[j], l[i]];
  marcarSujo(true); registarPasso(); renderProps(); recarregarTela();
  msg('Movido para ' + (j + 1) + '.º');
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
          <span class="guias"></span>
        </div>
        <div class="enq-lin">
          <span class="enq-rot">Aproximar</span>
          <input type="range" min="100" max="220" step="1" value="${e.zoom}" oninput="mudarZoom('${f.id}',this.value)">
          <button class="bt bt-min" onclick="reporFoco('${f.id}')" title="Repor o enquadramento original">Repor</button>
        </div>
        <div class="enq-dica">Arraste sobre a imagem para escolher o que fica ao centro.
          Cola-se ao centro e aos terços; com <b>Shift</b> arrasta livre.</div>
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
// Linhas a que o ponto focal se cola: o centro e os terços. São as posições que
// se procuram de facto — um rosto ao centro, o horizonte num terço — e acertar
// nelas à mão, num quadrado de 180px, é trabalho de paciência que ninguém deve
// ter de fazer. Com Shift arrasta-se livre, para quem quer mesmo 47%.
const IMAS = [33.333, 50, 66.667];
const IMA_DIST = 3.2;                     // em % da caixa: perto o suficiente
function colar(v){
  for (const a of IMAS) if (Math.abs(v - a) < IMA_DIST) return a;
  return v;
}
function arrastarFoco(ev, id){
  const caixa = ev.currentTarget;
  caixa.setPointerCapture(ev.pointerId);
  const guias = caixa.querySelector('.guias');
  const mover = e2 => {
    const r = caixa.getBoundingClientRect();
    let x = (e2.clientX-r.left)/r.width*100, y = (e2.clientY-r.top)/r.height*100;
    if (!e2.shiftKey) {
      const cx = colar(x), cy = colar(y);
      // As guias acendem-se só quando se está mesmo colado: uma grelha sempre
      // acesa é decoração, e deixa de dizer o que quer que seja.
      if (guias) {
        guias.style.setProperty('--gx', cx + '%');
        guias.style.setProperty('--gy', cy + '%');
        guias.classList.toggle('v', cx !== x);
        guias.classList.toggle('h', cy !== y);
      }
      x = cx; y = cy;
    } else if (guias) { guias.classList.remove('v','h'); }
    porFoco(id, x, y);
  };
  mover(ev);
  const largar = () => {
    if (guias) guias.classList.remove('v','h');
    caixa.removeEventListener('pointermove', mover); caixa.removeEventListener('pointerup', largar);
  };
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
  input.value = '';   // liberta o campo, para se poder reenviar o mesmo ficheiro
  let d;
  try {
    if (musica) {
      // A música vai por pedaços: não se comprime e pode passar o limite de
      // envio de alojamentos apertados. enviarFicheiroGrande trata do envio e
      // devolve o resultado da API (com aviso próprio em caso de falha).
      d = await window.enviarFicheiroGrande('def_upload',
            { chave: chave, ts: agora() }, f,
            p => msg('A enviar música… ' + Math.round(p * 100) + '%'));
    } else {
      const dados = await comprimir(f);
      const fd = new FormData();
      fd.append('chave',chave); fd.append('ts',agora());
      fd.append('ficheiro', dados, 'foto.jpg');
      const r = await fetch('api.php?action=def_upload',{method:'POST',headers:{'X-CSRF-Token':window.CSRF},body:fd});
      // Uma resposta que não é JSON (sessão expirada, erro do servidor) não pode
      // deixar o "A enviar…" preso sem explicação.
      d = await r.json();
    }
  } catch (e) {
    return msg('Não foi possível enviar o ficheiro. Verifique a ligação e tente outra vez.');
  }
  if (!d || !d.success) return msg((d && d.message) || 'Falha no envio.');
  EST.val[chave] = d.path; ATUAIS[chave] = d.path;
  // A partir de agora há uma troca de foto por confirmar: se o casal sair sem
  // guardar uma versão, o servidor repõe a anterior (ver o pagehide, acima). A
  // música não conta — não anda por versões.
  if (chave !== 'media.musica') FOTO_TROCADA = true;
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
let PAINEL_VERSOES = null;
function renderVersoes(){
  if (MODELO) return;   // um modelo da casa não tem versões
  if (PAINEL_VERSOES){ PAINEL_VERSOES.recarregar(); return; }   // já montado: só recarrega
  PAINEL_VERSOES = Versoes.montar({
    ambito: 'digital',
    alvo:   'bt-versao',
    sujo:   () => SUJO,
    // Grava as edições do ecrã antes de as fotografar na versão. Sem a guarda
    // do desenho da casa: o painel já pediu o nome e cria a versão a seguir.
    gravar: () => guardar({ semProteger: true }),
    msg,
    aoEstado:  rotularBotaoGuardar,   // o botão «Guardar» acompanha o estado
    aoAplicar: () => setTimeout(()=>{ SUJO = false; location.reload(); }, 700)
  });
}

// O botão principal do editor muda com o estado da peça (ver o pedido):
//   • um modelo da casa (só o admin lá chega) — «Guardar», grava direto.
//   • o casal, com uma versão SUA em vigor — «Actualizar» (actualiza-a).
//   • o casal, sem versão sua — «Guardar Como» (nasce uma versão com nome).
// Assim o casal nunca grava por cima de um desenho da casa: ou actualiza a sua
// versão, ou cria uma nova.
let MODO_GUARDAR = 'guardar_como', PROPRIA_ID = null;
function rotularBotaoGuardar(estado){
  const b = document.getElementById('bt-guardar'); if (!b) return;
  if (MODELO){ b.textContent = 'Guardar'; b.title = 'Guardar o modelo da casa'; return; }
  const propria = estado && estado.propria;
  if (propria){
    MODO_GUARDAR = 'actualizar'; PROPRIA_ID = propria.id;
    b.textContent = 'Actualizar';
    b.title = 'Actualizar a vossa versão em vigor «' + propria.nome + '»';
  } else {
    MODO_GUARDAR = 'guardar_como'; PROPRIA_ID = null;
    b.textContent = 'Guardar Como';
    b.title = 'Guardar as vossas alterações como uma versão vossa, com nome';
  }
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
  // As setas afinam o bloco escolhido na tela, ponto a ponto: 0,25% da tela,
  // ou 2% com Shift. É o ajuste que o rato não dá.
  const setas = { ArrowLeft:[-1,0], ArrowRight:[1,0], ArrowUp:[0,-1], ArrowDown:[0,1] };
  if (setas[e.key] && LIVRE && livresTodos()[LIVRE]){
    e.preventDefault();
    const passo = e.shiftKey ? 2 : 0.25, p = posDe(LIVRE), lim = TelaLivre.limitar;
    // Com Alt as setas viram em vez de deslocar: 1° de cada vez, 15° com Shift.
    if (e.altKey){ virarLivre(LIVRE, p.a + (setas[e.key][0] + setas[e.key][1]) * (e.shiftKey ? 15 : 1)); return; }
    moverLivre(LIVRE, TelaLivre.arred(lim(p.x + setas[e.key][0]*passo)),
                      TelaLivre.arred(lim(p.y + setas[e.key][1]*passo)), p.a);
    marcarSujo(true); registarPasso(); renderProps();
    return;
  }
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
  v['layout.trancados']   = [...EST.trancados].join(',');
  v['layout.posicoes']    = Object.keys(EST.pos).length ? JSON.stringify(EST.pos) : '';
  return v;
}
function rotuloDe(chave){ return CAMPOS[chave] ? CAMPOS[chave][0] : chave; }
// Um modelo guarda-se INTEIRO: não tem "o que mudou desde o casamento", tem o
// desenho que é. Só as chaves deste editor, que o servidor volta a filtrar.
function serializarTudo(){
  const v = serializar(), fora = {};
  Object.keys(PADRAO).forEach(k => {
    if (ALHEIAS.some(p => k.startsWith(p))) return;
    fora[k] = String(v[k] ?? '');
  });
  return fora;
}

/** As alterações deste editor, só o que mudou. */
function defsAlteradas(){
  const v = serializar(), defs = {};
  Object.keys(PADRAO).forEach(k=>{
    if (ALHEIAS.some(p=>k.startsWith(p))) return;
    const novo = String(v[k] ?? '');
    if (novo !== String(ATUAIS[k] ?? '')) defs[k] = novo;
  });
  return defs;
}
/** Grava as alterações na peça; devolve a resposta da API (ou false). */
async function gravarDefs(defs, proteger){
  if (!Object.keys(defs).length) return { success:true, invalidas:[], nada:true };
  const d = await api('defs_save', {method:'POST',
    body:JSON.stringify({defs, proteger_desenho:!!proteger}), semAviso:!!proteger});
  if (d && d.success){
    const inv = d.invalidas || [];
    Object.keys(defs).forEach(k=>{ if (!inv.includes(k)) ATUAIS[k] = defs[k]; });
    marcarSujo(false); marcarInvalidos(inv);
  }
  return d;
}

async function guardar(opcoes){
  const defs = defsAlteradas();

  // Um MODELO da casa (só o admin lá chega) grava-se direto e inteiro.
  if (MODELO){
    if (!Object.keys(defs).length){ msg('Não há alterações por guardar.'); marcarSujo(false); return true; }
    const d = await api('modelo_defs&id=' + MODELO.id, {method:'POST', body:JSON.stringify({defs: serializarTudo()})});
    if (!d || !d.success){ msg((d && d.message) || 'Erro ao guardar.'); return false; }
    const inv = d.invalidas || [];
    Object.keys(defs).forEach(k=>{ if (!inv.includes(k)) ATUAIS[k] = defs[k]; });
    marcarSujo(false); marcarInvalidos(inv);
    msg(inv.length ? `Guardado, mas ${inv.length} campo(s) não foram aceites.` : 'Modelo guardado.');
    recarregarTela(); return inv.length === 0;
  }

  // Chamado pelo painel de versões: grava e pronto (ele trata da versão).
  if (opcoes && opcoes.semProteger){
    if (!Object.keys(defs).length){ marcarSujo(false); return true; }
    const d = await gravarDefs(defs, false);
    if (!d || !d.success){ msg((d && d.message) || 'Erro ao guardar.'); return false; }
    recarregarTela(); return (d.invalidas || []).length === 0;
  }

  // O casal COM uma versão sua em vigor: «Actualizar» — grava e re-fotografa-a.
  if (MODO_GUARDAR === 'actualizar' && PROPRIA_ID){
    if (!Object.keys(defs).length){ msg('Não há alterações por guardar.'); marcarSujo(false); return true; }
    const d = await gravarDefs(defs, true);
    if (!d || !d.success){
      if (d && d.precisa_versao) return await guardarComo(defs);   // a versão sumiu: nasce outra
      msg((d && d.message) || 'Erro ao guardar.'); return false;
    }
    const u = await api('versao_atualizar&ambito=digital&id=' + PROPRIA_ID, {method:'POST'});
    if (!u || !u.success){ msg((u && u.message) || 'Não foi possível actualizar a versão.'); return false; }
    const inv = d.invalidas || [];
    msg(inv.length ? `Actualizada, mas ${inv.length} campo(s) não foram aceites.`
                   : `Versão «${u.nome || ''}» actualizada.`);
    if (PAINEL_VERSOES) PAINEL_VERSOES.recarregar();
    recarregarTela(); return inv.length === 0;
  }

  // O casal SEM versão sua: «Guardar Como» — nasce uma versão com nome.
  return await guardarComo(defs);
}

function guardarComo(defs){
  // Devolve uma promessa que só resolve quando a janela fecha: quem chama isto
  // está a meio de «guardar», e precisa de saber se guardou mesmo.
  return new Promise(resolve => {
    let respondeu = false;
    licFormulario({
      titulo: 'Guardar como uma versão vossa',
      dica: 'Fica só para o vosso casamento — o desenho da casa não se toca.',
      guardar: 'Guardar versão',
      campos: [{ id: 'nome', rot: 'Nome desta versão', largura: 3,
                 dica2: 'Ex.: a nossa, com a foto da praia' }],
      aoGuardar: async function (v) {
        if (!v.nome){ licJanelaErro('A vossa versão precisa de um nome.'); return false; }
        const d = await gravarDefs(defs, false);   // persiste; a versão a seguir fotografa-a
        if (d && !d.success){ licJanelaErro((d && d.message) || 'Erro ao guardar.'); return false; }
        const c = await api('versao_criar&ambito=digital',
                            {method:'POST', body:JSON.stringify({nome: v.nome, ambito:'digital'})});
        if (!c || !c.success){
          licJanelaErro((c && c.message) || 'Não foi possível guardar a versão.');
          return false;
        }
        msg(`Guardado na vossa versão «${v.nome}».`);
        if (PAINEL_VERSOES) PAINEL_VERSOES.recarregar();
        recarregarTela();
        respondeu = true; resolve(true);
      }
    });
    // Fechar sem guardar é não ter guardado.
    const m = document.getElementById('lic-janela');
    const obs = new MutationObserver(() => {
      if (!m.classList.contains('on') && !respondeu){
        obs.disconnect();
        msg('Por guardar: a vossa versão precisa de um nome.');
        resolve(false);
      }
      if (respondeu) obs.disconnect();
    });
    obs.observe(m, { attributes: true, attributeFilter: ['class'] });
  });
}
function marcarInvalidos(inv){
  document.querySelectorAll('#props .campo').forEach(c=>c.classList.remove('invalido'));
  inv.forEach(k=>{
    const el = document.querySelector('#props [data-chave="'+k+'"]');
    if (el) el.closest('.campo').classList.add('invalido');
  });
}

/** O botão diz sempre que secção repõe: «Repor Secção (Nome)». */
function rotularBotaoRepor(){
  const b = document.getElementById('bt-repor'); if (!b) return;
  b.textContent = 'Repor Secção (' + rotuloCamada(SEC) + ')';
}

async function reporSeccao(){
  if (blocoLivre(SEC)) return msg('Esta secção foi acrescentada por si — use "Apagar esta secção".');
  const ehCapa = SEC === CAPA_ID;
  const s = SECCOES[SEC];
  if (!ehCapa && !s) return;
  const rotulo = rotuloCamada(SEC);

  // Tudo o que é DESTA secção: as chaves de origem do seu namespace (textos,
  // estilos próprios, visibilidade, enquadramento), a foto, as listas e a
  // posição dos blocos. As cores e os tipos de letra são do convite inteiro e
  // não se mexem aqui.
  const chaves  = chavesDaSeccao(SEC);
  const fotos   = (MEDIA_SEC[SEC] || []).filter(k => k.startsWith('media.'));
  const blocos  = Object.keys(livresTodos()).filter(id => (livresTodos()[id]||{}).sec === SEC && EST.pos[id]);

  const r = await licConfirmar({
    titulo: 'Repor «' + licEsc(rotulo) + '» no modelo de origem?',
    icone: '↩️', perigo: !!fotos.length, confirmar: 'Repor secção',
    texto: fotos.length
      ? 'Voltam os <b>textos</b>, os <b>estilos</b>, a <b>composição</b> e a '
        + '<b>fotografia</b> de origem.<br><br>A foto que tenha posto à mão nesta secção é '
        + '<b>apagada já, e isso não se desfaz</b> — o resto desfaz-se com Ctrl+Z.'
      : 'Voltam os <b>textos</b>, os <b>estilos</b> e a <b>composição</b> desta secção.'
        + '<br><br><b>Ctrl+Z desfaz.</b>'
  });
  if (!r.sim) return;

  // 1) Os valores da secção (menos as fotos, que se gravam à parte).
  chaves.forEach(k => { if (!k.startsWith('media.') && k in PADRAO) EST.val[k] = PADRAO[k]; });
  // 2) As listas da secção.
  const lk = LISTAS_SEC[SEC];
  if (lk && lk in PADRAO){ try { EST.listas[lk] = JSON.parse(PADRAO[lk]||'[]'); } catch(e){} }
  // 3) A composição: os blocos desta secção voltam ao sítio de origem.
  blocos.forEach(id => moverLivre(id, 0, 0, 0));

  // 4) A(s) fotografia(s): gravam-se JÁ e APAGAM o ficheiro que o casal enviou —
  //    media.* está fora da gravação normal, e a foto neste editor nunca foi
  //    "por guardar". def_media_repor põe a de origem e limpa o ficheiro custom.
  if (fotos.length){
    fotos.forEach(k => { if (k in PADRAO){ EST.val[k] = PADRAO[k]; ATUAIS[k] = PADRAO[k]; } });
    const d = await api('def_media_repor', { method:'POST', body: JSON.stringify({ chaves: fotos }) });
    if (!d || !d.success) return msg((d && d.message) || 'Não foi possível repor a fotografia.');
    MEDIA_V = Date.now();
  }

  marcarSujo(true); registarPasso();
  renderCamadas(); renderProps(); renderMedia(); recarregarTela();
  msg(fotos.length
    ? `"${rotulo}" reposta no modelo de origem — a foto voltou à de origem; o resto fica por guardar.`
    : `"${rotulo}" reposta no modelo de origem — por guardar. Ctrl+Z desfaz.`);
}

// ---------- arranque ----------
renderCamadas(); renderProps(); renderCores(); renderMedia(); renderEfeitos(); renderTipografia(); renderVersoes();
aplicarZoom(); ajustarAltura(); marcarBotoes(); rotularBotaoRepor();
$('tela').addEventListener('load', ()=>{ ajustarAltura(); aplicarFerramenta(); });
recarregarTela();                       // primeira pintura da tela
msg('Clique num texto do convite para o editar.');
</script>
<script src="<?= asset('assets/editor-paineis.js') ?>"></script>
</body>
</html>
