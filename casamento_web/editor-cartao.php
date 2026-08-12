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
require_once __DIR__ . '/parcial-cabecalho.php';   // tiraSuporte()
require_once __DIR__ . '/personalizacao.php';
[$defs, $MODELO] = defsDoEditor($conn, 'impresso');
if (!$MODELO) exigirAdmin(); elseif (!ehAdminPlataforma()) exigirAdmin();
$CAS  = $MODELO ? ['casal' => $MODELO['nome'], 'mono' => '◆', 'noiva' => '', 'noivo' => '']
                : casalInfo($defs);

$pal      = cartaoPaletaEfetiva($defs);
$estilo   = cartaoEstiloVars($defs);
$folhagem = $defs['cartao.folhagem'];
$camadas  = cartaoCamadasVisiveis($defs);
$posicoes = cartaoPosicoes($defs);
$trancadas= cartaoTrancadas($defs);
$ev       = cartaoDadosEvento($defs);

// Chaves que este editor governa — as do cartão, e só essas. Serve para gravar
// apenas o que mudou, sem pisar o convite digital.
$chavesCartao = chavesDoAmbito('impresso');
// ...e as do evento e do casal que este editor mostra no cartão. São de âmbito
// digital (não começam por 'cartao.'), mas quem as escreve aqui espera que
// fiquem escritas.
$chavesEditor = array_merge($chavesCartao, [
    'casal.noiva', 'casal.noivo', 'evento.data', 'evento.hora', 'evento.local',
    'evento.venue_titulo',
    'evento.civil_titulo', 'evento.civil_hora', 'evento.civil_local',
    'evento.religiosa_titulo', 'evento.religiosa_hora', 'evento.religiosa_local',
]);

// Convite de exemplo: usa um real (físico) para a prova ser fiel.
$r = $conn->query("SELECT c.*, m.nome AS mesa_nome FROM {$P}convites c
                   LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                   WHERE " . doCasamento('c') . " AND c.tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."
                   ORDER BY c.nome_exibicao LIMIT 1");
$exemplo = $r ? $r->fetch_assoc() : null;
if ($exemplo) {
    $conv = ['nome' => nomeParaCartao($exemplo), 'mesas' => mesasDoConvite($conn, $exemplo)];
    $comLug = !isset($exemplo['mostrar_num_mesa']) || (int)$exemplo['mostrar_num_mesa'] === 1;
} else {
    $conv = ['nome' => 'Família Agostinho', 'mesas' => [['nome'=>'Mesa Luar','n'=>1], ['nome'=>'Mesa Solar','n'=>4]]];
    $comLug = true;
}

// Trepadeiras de todas as folhagens: trocar de folhagem não volta ao servidor.
$ramosJs = [];
foreach (cartaoFolhagens() as $k => $f) $ramosJs[$k] = svgTrepadeira($k, 'currentColor');
// O mesmo para os floreados: trocar de feitio é uma decisão de desenho, e
// esperar por um pedido ao servidor para a ver não é decidir, é adivinhar.
$floreadosJs = [];
foreach (cartaoFloreados() as $k => $f) $floreadosJs[$k] = svgFloreado('currentColor', $k);
$volutasJs = [];  foreach (cartaoVolutas() as $k => $f) $volutasJs[$k] = svgVoluta('currentColor', $k);
$elosJs    = [];  foreach (cartaoElos()    as $k => $f) $elosJs[$k]    = htmlElo($k);

// O feitio de cada moldura, tal como o servidor o escreve, partido em pares
// para o editor os poder pôr um a um. Assim não há duas listas a divergir.
$molduraVarsJs = [];
foreach (array_keys(cartaoMolduras()) as $k) {
    $pares = [];
    foreach (explode(';', cartaoMolduraVars($k)) as $decl) {
        if (strpos($decl, ':') === false) continue;
        [$nome, $valor] = explode(':', $decl, 2);
        $pares[trim($nome)] = trim($valor);
    }
    $molduraVarsJs[$k] = $pares;
}

// Campos de texto editáveis, por camada
$camposPorCamada = [
    'abertura'  => [['cartao.abertura', 'Texto de abertura', 'area', 'abertura']],
    'nomes'     => [['casal.noiva', 'Nome da noiva', 'texto', 'noiva'], ['casal.noivo', 'Nome do noivo', 'texto', 'noivo']],
    'frase'     => [['cartao.frase_convite', 'Frase de convite', 'area', 'frase']],
    'convidado' => [['cartao.reservado', 'Rótulo', 'texto', 'reservado']],
    // A receção é a festa e está sempre cá; as duas cerimónias são opcionais e
    // têm bloco próprio, com acrescentar e remover (ver blocoCerimonias()).
    'logistica' => [['evento.venue_titulo', 'Receção', 'texto', 'copo_titulo'],
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
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/pecas.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/editor.css') ?>" rel="stylesheet">
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
  <span class="doc">Convite impresso · <b>Cartão 10 × 15 cm</b></span>
  <div class="cresce"></div>
  <a href="versao.php" class="versao-app" title="Versão instalada — clique para o detalhe"><?= versaoApp() ?></a>
  <a href="graficas.php">← Entregáveis à gráfica</a>
  <span class="ed-sep"></span>
  <a href="cartoes.php">Ver todos os cartões</a>
</div>

<div class="ed-opcoes">
  <button class="bt bt-min" id="bt-desfazer" onclick="desfazer()" title="Desfazer (Ctrl+Z)" disabled>↶ Desfazer</button>
  <button class="bt bt-min" id="bt-refazer" onclick="refazer()" title="Refazer (Ctrl+Shift+Z)" disabled>↷ Refazer</button>
  <span class="ed-sep"></span>
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
  <?php // As versões são de um casamento: guardam o que ESTE casal decidiu. Um
        // modelo da casa não tem versões, e o seletor saía vazio — um controlo
        // que não faz nada é pior do que um controlo que não está lá. ?>
  <?php if (!$MODELO): ?>
    <span class="rot">Versão</span>
    <select id="sel-versao" class="sel-versao"
            title="A versão em vigor — a que se imprime e a que o manual retrata. Escolha outra para a aplicar, ou use as ações de gerir."></select>
    <span class="ed-sep"></span>
  <?php endif; ?>
  <button class="bt" onclick="reporCamada()" title="Repor os textos originais da camada escolhida">Repor esta camada</button>
  <button class="bt" onclick="reporPosicoes()" title="Devolver todas as camadas ao sítio que o design lhes deu">Repor composição</button>
  <button class="bt" onclick="repor()">Repor originais</button>
  <button class="bt primario" id="bt-guardar" onclick="guardar()">Guardar</button>
</div>

<div class="ed-corpo">
  <!-- Ferramentas -->
  <div class="ed-ferramentas">
    <button class="ferr on" data-ferr="mover" title="Selecionar e arrastar camada (V)">
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
    <button class="ferr" data-ferr="limpo" title="Ver sem marcas (P)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
  </div>

  <!-- Mesa de trabalho -->
  <div class="ed-mesa" id="mesa">
    <div class="ed-arte marcar" id="arte" style="--esc:.5">
      <span class="tela-guias" id="guias"></span>
      <div class="escala" id="escala" style="width:720px;height:1080px;transform:scale(var(--esc));transform-origin:top left">
        <?= renderCartaoConvite($ev, $conv, $pal, $folhagem, $comLug, $camadas, $estilo, $posicoes) ?>
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
    <div class="ed-painel fechado" id="p-cores">
      <h3 onclick="alternarPainel(this)">Cores <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="cores"></div>
    </div>
    <div class="ed-painel fechado" id="p-tipografia">
      <h3 onclick="alternarPainel(this)">Tipografia <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="tipografia"></div>
    </div>
  </div>
</div>

<div class="ed-estado">
  <span>Cartão 10 × 15 cm · 720 × 1080 px</span>
  <span class="ed-sep"></span>
  <span id="estado-exemplo">Prova com: <?= escP($conv['nome']) ?></span>
  <span class="cresce"></span>
  <span class="aviso-txt" id="passos"></span>
  <span class="marca-sujo" id="marca-sujo">alterações por guardar</span>
  <span id="estado-msg"></span>
</div>

<script src="<?= asset('assets/editor-adiar.js') ?>"></script>
<?php if (($_GET['diag'] ?? '') === '1'): ?>
<script src="<?= asset('assets/editor-diag.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/versoes.js') ?>"></script>
<script src="<?= asset('assets/tela-livre.js') ?>"></script>
<script>window.EDITOR_MIN = { l: <?= EDITOR_MIN_L ?>, a: <?= EDITOR_MIN_A ?> };</script>
<script src="<?= asset('assets/editor-espaco.js') ?>"></script>
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const $ = id => document.getElementById(id);

const PALETAS  = <?= json_encode(cartaoPaletas(), JSON_UNESCAPED_UNICODE) ?>;
const RAMOS    = <?= json_encode($ramosJs, JSON_UNESCAPED_UNICODE) ?>;
const CAMADAS  = <?= json_encode(cartaoCamadas(), JSON_UNESCAPED_UNICODE) ?>;
const CAMPOS   = <?= json_encode($camposPorCamada, JSON_UNESCAPED_UNICODE) ?>;
const ORNAMENTOS = ['ramos','volutas','moldura','floreados'];   // camadas sem texto
const MOLDURAS  = <?= json_encode(cartaoMolduras(), JSON_UNESCAPED_UNICODE) ?>;
// As variáveis de cada feitio vêm do servidor já resolvidas. O editor chegou a
// tê-las escritas à mão, e a cópia ficou para trás quando nasceram feitios
// novos: o cartão saía impresso de um modo e mostrava-se de outro. Há uma
// definição só — cartaoMolduraVars() — e é esta.
const MOLDURA_VARS = <?= json_encode($molduraVarsJs, JSON_UNESCAPED_UNICODE) ?>;
const FOLHAGENS = <?= json_encode(cartaoFolhagens(), JSON_UNESCAPED_UNICODE) ?>;
const FLOREADOS = <?= json_encode(cartaoFloreados(), JSON_UNESCAPED_UNICODE) ?>;
const FLOREADOS_SVG = <?= json_encode($floreadosJs, JSON_UNESCAPED_UNICODE) ?>;
const VOLUTAS      = <?= json_encode(cartaoVolutas(), JSON_UNESCAPED_UNICODE) ?>;
const VOLUTAS_SVG  = <?= json_encode($volutasJs, JSON_UNESCAPED_UNICODE) ?>;
const ELOS         = <?= json_encode(cartaoElos(), JSON_UNESCAPED_UNICODE) ?>;
const ELOS_HTML    = <?= json_encode($elosJs, JSON_UNESCAPED_UNICODE) ?>;
// O que cada camada decorativa oferece, além de se poder esconder.
const ORN_ESCALA = { ramos:'cartao.ramos_escala', volutas:'cartao.volutas_escala',
                     floreados:'cartao.floreados_escala' };
// As chaves que ESTE editor governa. Não são só as do cartão: ele mostra (e
// deixa escrever) o local e a hora da festa, os nomes dos noivos e as
// cerimónias, que vivem no âmbito do evento. Sem elas aqui, guardar()
// filtrava-as e o que se escrevia nesses campos era deitado fora em silêncio.
const PADRAO   = <?= json_encode(array_intersect_key(defsPadrao(), array_flip($chavesEditor)), JSON_UNESCAPED_UNICODE) ?>;
// Um modelo é o DESENHO, e não a festa: dele só saem as chaves do cartão. É
// o que o servidor já exige (modelo_defs filtra pelo âmbito); dizê-lo também
// aqui evita mandar o que vai ser deitado fora.
const PADRAO_MODELO = <?= json_encode(array_intersect_key(defsPadrao(), array_flip($chavesCartao)), JSON_UNESCAPED_UNICODE) ?>;
// As chaves do evento não são do âmbito do cartão (o convite digital também as
// usa), mas o cartão mostra-as — e para as repor é preciso saber o original.
const PADRAO_EV = <?= json_encode(array_intersect_key(defsPadrao(), array_flip([
    'evento.civil_titulo','evento.religiosa_titulo','evento.venue_titulo'])), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS   = <?= json_encode(array_intersect_key($defs, array_flip($chavesCartao)), JSON_UNESCAPED_UNICODE) ?>;
// Desenhar um modelo da casa: grava-se nele, e não nas definições de um casamento.
const MODELO   = <?= json_encode($MODELO, JSON_UNESCAPED_UNICODE) ?>;
const FONTES   = <?= json_encode(fontesConvite(), JSON_UNESCAPED_UNICODE) ?>;
const PAPEIS   = <?= json_encode(papeisCartao(), JSON_UNESCAPED_UNICODE) ?>;
// Nome da variável CSS -> chave dentro da paleta (o "name" chama-se nameColor).
const CORES_VAR = <?= json_encode(cartaoChavesCor()) ?>;
const CORES_ROT = { accent:'Acento e traços', name:'Nomes', sub:'Frases', head:'Títulos', soft:'Rótulos' };
const CASAL_NOME = <?= json_encode($CAS['casal']) ?>;
// Limites do cartão. Não são os do servidor (que aceita muito mais): são o que
// cabe em 10×15 cm sem o texto transbordar da moldura. Mostrá-los à medida que
// se escreve evita descobrir o problema só na prova impressa.
const LIMITES = { 'casal.noiva':28, 'casal.noivo':28, 'cartao.abertura':60,
                  'cartao.frase_convite':160, 'cartao.reservado':30, 'evento.civil_titulo':40, 'evento.religiosa_titulo':40,
                  'cartao.frase_final':220, 'evento.venue_titulo':40, 'evento.local':80 };
const MESES    = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const DIAS     = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];

// Estado do documento
let est = {
  paleta:   <?= json_encode($defs['cartao.paleta']) ?>,
  folhagem: <?= json_encode($folhagem) ?>,
  floreado: <?= json_encode($defs['cartao.floreado']) ?>,
  voluta:   <?= json_encode($defs['cartao.voluta']) ?>,
  elo:      <?= json_encode($defs['cartao.elo']) ?>,
  camadas:  <?= json_encode($camadas) ?>,
  cores:    (()=>{ try { return JSON.parse(<?= json_encode($defs['cartao.cores']) ?> || '{}') || {}; } catch(e){ return {}; } })(),
  fontes:   <?= json_encode(array_intersect_key($defs, array_flip(['cartao.fonte_script','cartao.fonte_serif','cartao.fonte_sans'])), JSON_UNESCAPED_UNICODE) ?>,
  escala:   <?= json_encode($defs['cartao.escala']) ?>,
  deco:     <?= json_encode(array_intersect_key($defs, array_flip([
                 'cartao.moldura_estilo','cartao.moldura_margem',
                 'cartao.ramos_escala','cartao.volutas_escala','cartao.floreados_escala'])), JSON_UNESCAPED_UNICODE) ?>,
  textos:   <?= json_encode(array_intersect_key($defs, array_flip([
                 'cartao.abertura','cartao.frase_convite','cartao.reservado','cartao.frase_final',
                 'casal.noiva','casal.noivo','evento.venue_titulo','evento.local','evento.hora','evento.data',
                 'evento.civil_titulo','evento.civil_hora','evento.civil_local',
                 'evento.religiosa_titulo','evento.religiosa_hora','evento.religiosa_local'])), JSON_UNESCAPED_UNICODE) ?>,
  // Onde cada camada foi parar, em % do cartão. Sem entrada = no sítio que o
  // design lhe deu — e é assim que fica um cartão que ninguém arrastou.
  pos:      <?= json_encode((object)$posicoes, JSON_UNESCAPED_UNICODE) ?>,
  // Camadas trancadas: não se arrastam nem se escondem. A tela de
  // posicionamento livre precisa desta rede — bastava um gesto distraído
  // sobre a moldura para desmanchar a composição inteira.
  trancados: <?= json_encode($trancadas, JSON_UNESCAPED_UNICODE) ?>
};
const original = JSON.parse(JSON.stringify(est));
let sujo = false, selecionada = null, ferramenta = 'mover';

function marcarSujo(v){
  if (sujo === v) return;
  // O aviso é o selo ao lado; #estado-msg fica livre para as mensagens.
  sujo = v; $('marca-sujo').classList.toggle('on', v);
}
function msg(t){ $('estado-msg').textContent = t; $('estado-msg').className = ''; }

// ---------- Histórico (desfazer / refazer) ----------
// O mesmo mecanismo do editor do convite digital: fotografias do estado, com
// as teclas seguidas agrupadas num passo só.
const HIST = [instantaneo()];
let hPos = 0, tHist = null;
function instantaneo(){ return JSON.stringify(est); }
function registarPasso(){
  clearTimeout(tHist);
  tHist = setTimeout(() => {
    const agora = instantaneo();
    if (agora === HIST[hPos]) return;
    HIST.length = hPos + 1;          // um passo novo apaga o "refazer"
    HIST.push(agora);
    if (HIST.length > 60) HIST.shift();
    hPos = HIST.length - 1;
    marcarBotoes();
  }, 350);
}
/** Volta a pintar o cartão inteiro a partir de est — usado ao desfazer/refazer. */
function repintarTudo(){
  aplicarPaleta(est.paleta, true); aplicarCoresLivres(); aplicarTipografia(); aplicarDeco();
  aplicarFolhagem(est.folhagem); aplicarFloreado(est.floreado);
  aplicarVoluta(est.voluta); aplicarElo(est.elo);
  $('folhagem').value = est.folhagem;
  Object.entries(est.textos).forEach(([k,v]) => pintarTexto(k,v));
  pintarLogistica();
  Object.keys(CAMADAS).forEach(k => {
    const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
    if (alvo) alvo.classList.toggle('ct-oculta', est.camadas[k] === 0);
  });
  aplicarDeco(); aplicarPosicoes();
  renderCamadas(); renderProps(); renderCores(); renderTipografia();
}
function aplicarEstado(json){ est = JSON.parse(json); repintarTudo(); marcarBotoes(); }
function desfazer(){ if (hPos<=0) return; clearTimeout(tHist); hPos--; aplicarEstado(HIST[hPos]); marcarSujo(true); msg('Desfeito.'); }
function refazer(){ if (hPos>=HIST.length-1) return; clearTimeout(tHist); hPos++; aplicarEstado(HIST[hPos]); marcarSujo(true); msg('Refeito.'); }
function marcarBotoes(){
  $('bt-desfazer').disabled = hPos<=0;
  $('bt-refazer').disabled  = hPos>=HIST.length-1;
  $('passos').textContent = HIST.length>1 ? (hPos+1)+' de '+HIST.length+' passos' : '';
}

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

// ---------- Paleta, cores livres, letra e folhagem (ao vivo, sem servidor) ----------
const cartao = () => document.querySelector('#escala .cartao');

/** Escolher uma paleta apaga as cores livres: senão ficava sem efeito visível. */
function aplicarPaleta(k, manterLivres){
  const p = PALETAS[k] || PALETAS.ouro; est.paleta = k;
  if (!manterLivres) est.cores = {};
  const c = cartao();
  Object.entries(CORES_VAR).forEach(([v, chave]) => c.style.setProperty('--ct-'+v, p[chave]));
  document.querySelectorAll('#amostras .amostra').forEach(b => b.classList.toggle('on', b.dataset.paleta === k));
  if (!manterLivres) renderCores();
}
/** As cores escolhidas à mão, por cima da paleta. */
function aplicarCoresLivres(){
  const c = cartao();
  Object.entries(est.cores).forEach(([v, cor]) => c.style.setProperty('--ct-'+v, cor));
}
/** Cor efetiva de uma variável: a livre, se houver; senão a da paleta. */
function corDe(v){
  return est.cores[v] || (PALETAS[est.paleta] || PALETAS.ouro)[CORES_VAR[v]];
}
function aplicarTipografia(){
  const c = cartao();
  Object.entries(PAPEIS).forEach(([papel, p]) => {
    const id = est.fontes[p.chave] || p.origem;
    c.style.setProperty('--cf-'+papel, (FONTES[id] || FONTES[p.origem]).css);
  });
  c.style.setProperty('--ct-esc', String((+est.escala || 100) / 100));
}
function aplicarFolhagem(k){
  est.folhagem = k;
  document.querySelectorAll('#escala .ct-ramo').forEach(r => { r.innerHTML = RAMOS[k] || ''; });
}
function aplicarFloreado(k){
  est.floreado = k;
  document.querySelectorAll('#escala .ct-floreado').forEach(r => { r.innerHTML = FLOREADOS_SVG[k] || ''; });
}
function aplicarVoluta(k){
  est.voluta = k;
  document.querySelectorAll('#escala .ct-voluta').forEach(r => { r.innerHTML = VOLUTAS_SVG[k] || ''; });
}
/** O elo entre os nomes nasce e morre: troca-se o elemento, não só o texto. */
function aplicarElo(k){
  est.elo = k;
  const nomes = document.querySelector('#escala .ct-nomes'); if (!nomes) return;
  const antigo = nomes.querySelector('.ct-coracao');
  if (antigo) antigo.remove();
  const html = ELOS_HTML[k] || '';
  if (html) {
    const primeiro = nomes.querySelectorAll('.ct-nome')[0];
    if (primeiro) primeiro.insertAdjacentHTML('afterend', html);
  }
}

// ---------- Painel de cores ----------
function renderCoresJa(){
  $('cores').innerHTML =
    `<div class="campo"><label>Paletas de origem</label>
      <div class="amostras">` +
      Object.entries(PALETAS).map(([k,p]) =>
        `<button class="amostra ${k===est.paleta && !Object.keys(est.cores).length ? 'on':''}"
                 title="${escaparAttr(p.nome)}" style="background:${p.accent}"
                 onclick="escolherPaleta('${k}')"></button>`).join('') +
      `</div>
      <div class="ajuda">Escolher uma paleta limpa as cores mudadas à mão.</div>
    </div>` +
    // Nem <label> a envolver o seletor, nem botões a nascer durante a escolha:
    // o painel de cores do navegador fecha-se assim que o elemento a que está
    // preso muda de sítio ou de tamanho. O ↺ está sempre lá, só se apaga.
    Object.keys(CORES_VAR).map(v =>
      `<div class="cor-linha">
        <input type="color" value="${corDe(v)}" aria-label="${CORES_ROT[v]}" oninput="editarCor('${v}',this.value,this)">
        <span>${CORES_ROT[v]}</span>
        <button class="bt bt-min repor-cor${est.cores[v] ? '' : ' vazio'}" onclick="limparCor('${v}')"
                title="Voltar à cor da paleta" tabindex="${est.cores[v] ? 0 : -1}">↺</button>
      </div>`).join('') +
    (Object.keys(est.cores).length
      ? `<button class="bt" style="width:100%;margin-top:.4rem" onclick="limparCores()">Repor as cores da paleta</button>`
      : `<div class="ajuda">Cada cor pode ser mudada à mão — o cartão é gravado a um só dourado, mas a prova no ecrã mostra os tons.</div>`);
}
function escolherPaleta(k){
  aplicarPaleta(k); marcarSujo(true); registarPasso();
  msg('Paleta: ' + (PALETAS[k]||{}).nome);
}
function editarCor(v, cor, el){
  est.cores[v] = cor.toUpperCase();
  cartao().style.setProperty('--ct-'+v, est.cores[v]);
  marcarSujo(true); registarPasso();
  // Nada de redesenhar aqui: fecharia o painel de cores a meio da escolha.
  // O ↺ já existe na linha; basta deixar de estar apagado.
  if (el){
    const bt = el.closest('.cor-linha').querySelector('.repor-cor');
    if (bt){ bt.classList.remove('vazio'); bt.tabIndex = 0; }
  } else renderCores();
}
function limparCor(v){
  delete est.cores[v];
  cartao().style.setProperty('--ct-'+v, corDe(v));
  marcarSujo(true); registarPasso(); renderCores();
  msg('Cor reposta: ' + CORES_ROT[v]);
}
function limparCores(){
  est.cores = {};
  aplicarPaleta(est.paleta);
  marcarSujo(true); registarPasso();
  msg('Cores da paleta repostas.');
}

// ---------- Painel de tipografia ----------
function renderTipografiaJa(){
  $('tipografia').innerHTML =
    Object.entries(PAPEIS).map(([papel,p]) => {
      const escolhida = est.fontes[p.chave] || p.origem;
      const opcoes = Object.entries(FONTES).filter(([,f]) => f.papeis.includes(papel));
      return `<div class="campo"><label>${p.rotulo}</label>
        <select onchange="mudarFonte('${p.chave}',this.value)">
          ${opcoes.map(([k,f]) => `<option value="${k}" ${k===escolhida?'selected':''}>${escaparHtml(f.nome)}${k===p.origem?' (de origem)':''}</option>`).join('')}
        </select>
        <div class="amostra-f" style="font-family:${FONTES[escolhida].css}">${escaparHtml(CASAL_NOME)}</div>
      </div>`;
    }).join('') +
    `<div class="campo"><label>Tamanho do texto<span class="contador">${est.escala||100}%</span></label>
      <div class="vs-lin"><input type="range" min="85" max="115" step="5" value="${est.escala||100}"
        oninput="mudarEscala(this.value,this)" style="flex:1">
        <button class="bt bt-min" onclick="mudarEscala(100)">Repor</button></div>
      <div class="ajuda">Só as frases e os rótulos que se leem de corrido. Os nomes e a data ficam como o design os deixou —
        o cartão tem 10×15 cm e não há para onde crescer.</div>
    </div>`;
}
function mudarFonte(chave, v){
  est.fontes[chave] = v;
  aplicarTipografia(); marcarSujo(true); registarPasso(); renderTipografia();
  msg('Tipo de letra: ' + FONTES[v].nome);
}
function mudarEscala(v, el){
  est.escala = String(v);
  aplicarTipografia(); marcarSujo(true); registarPasso();
  if (el) valorNoRotulo(el, v + '%'); else renderTipografia();
  msg('Tamanho do texto: ' + v + '%');
}

// Os redesenhos de painel passam pelo guarda de assets/editor-adiar.js: se
// houver um gesto em curso (barra a ser arrastada, painel de cores aberto),
// esperam pelo fim em vez de trocar o elemento por baixo do rato.
const adiar = window.adiavel || ((n, f) => f);
const renderProps      = adiar('props',      (...a) => renderPropsJa(...a));
const renderCores      = adiar('cores',      (...a) => renderCoresJa(...a));
const renderTipografia = adiar('tipografia', (...a) => renderTipografiaJa(...a));

// ---------- Camadas ----------
const OLHO_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="2.8"/></svg>';
const OLHO_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l16 16M10.7 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.4 8.3A17 17 0 0 0 2 12s3.6 7 10 7c1.4 0 2.7-.3 3.8-.8"/></svg>';
const CADEADO_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.6a4 4 0 0 1 8 0v2.9"/></svg>';
const CADEADO_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.6a4 4 0 0 1 7.8-1"/></svg>';

function renderCamadas(){
  $('camadas').innerHTML = Object.entries(CAMADAS).map(([k, rot]) => {
    const vis = est.camadas[k] !== 0;
    const tr  = estaTrancada(k);
    const mov = !!est.pos[k];
    return `<div class="camada ${selecionada===k?'sel':''} ${vis?'':'oculta'} ${tr?'trancada':''}" data-k="${k}" onclick="selecionar('${k}')">
      <button class="olho" title="${tr ? 'Trancada: destranque para esconder' : (vis?'Ocultar':'Mostrar')}"
              onclick="event.stopPropagation();${tr?'':`alternarCamada('${k}')`}">${vis?OLHO_ON:OLHO_OFF}</button>
      <span class="nome">${rot}</span>
      ${mov ? '<span class="mini" title="Movida do sítio de origem">✥</span>' : ''}
      <span class="mini" title="${ORNAMENTOS.includes(k)?'Camada decorativa':'Camada de texto'}">${ORNAMENTOS.includes(k)?'◈':'T'}</span>
      <button class="cadeado" title="${tr ? 'Destrancar' : 'Trancar: não se arrasta nem se esconde'}"
              onclick="event.stopPropagation();alternarTranca('${k}')">${tr?CADEADO_ON:CADEADO_OFF}</button>
    </div>`;
  }).join('');
}
function alternarCamada(k){
  if (estaTrancada(k)) return msg(`"${CAMADAS[k]}" está trancada — destranque-a primeiro.`);
  est.camadas[k] = est.camadas[k] === 0 ? 1 : 0;
  const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
  if (alvo) alvo.classList.toggle('ct-oculta', est.camadas[k] === 0);
  renderCamadas(); marcarSujo(true); registarPasso();
  msg((est.camadas[k] === 0 ? 'Escondida: ' : 'Visível: ') + CAMADAS[k]);
}
function selecionar(k){
  selecionada = k;
  document.querySelectorAll('#escala [data-camada]').forEach(n => n.classList.remove('sel-camada'));
  const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
  if (alvo) alvo.classList.add('sel-camada');
  renderCamadas(); renderProps();
}

// ---------- Propriedades ----------
/**
 * O bloco de posição, comum a todas as camadas. Está no painel porque o
 * arrasto sozinho não diz onde a camada está nem como voltar atrás — e uma
 * composição que não se sabe desfazer não se experimenta.
 */
function blocoPosicao(k){
  const p = posDe(k), movida = !!est.pos[k], tr = estaTrancada(k);
  const num = n => (n > 0 ? '+' : '') + n.toFixed(2).replace(/\.?0+$/, '') + '%';
  const onde = movida
    ? [num(p.x) + ' · ' + num(p.y)].concat(p.a ? [p.a + '°'] : []).join(' · ')
    : 'no sítio de origem';
  return `<div class="campo">
    <label>Posição na tela</label>
    <div class="pos-linha">
      <span class="val">${onde}</span>
      <button class="bt bt-min" onclick="reporPosicao('${k}')" ${movida?'':'disabled'}>Repor</button>
    </div>
    <div class="ajuda">${tr
      ? 'Camada trancada: destranque-a (o cadeado, na lista de camadas) para a poder arrastar ou virar.'
      : 'Arraste a camada no cartão. Ela cola-se ao centro, às bordas e aos outros blocos — o <b>Shift</b> desliga o íman, e as setas afinam ponto a ponto.'}</div>
  </div>` + (tr ? '' : faixa({
    rot:'Volta', valor:p.a, min:-180, max:180, passo:1, unidade:'°', origem:0,
    fn:`mudarAngulo.bind(null,'${k}')`,
    ajuda:'Vira a camada à volta do próprio centro. Também com <b>Alt</b> + arrastar no cartão '
        + '(encosta de 15 em 15 graus; o <b>Shift</b> solta-a), ou <b>Alt</b> + setas.'}));
}

function renderPropsJa(){
  const campos = CAMPOS[selecionada];
  if (!selecionada) { $('props').innerHTML = '<div class="vazio-painel">Escolha uma camada — na lista abaixo ou clicando no cartão — para editar o que ela mostra e onde ela fica.</div>'; return; }
  const rot = CAMADAS[selecionada];
  if (ORNAMENTOS.includes(selecionada)) return renderPropsOrnamento(selecionada, rot);
  if (!campos) {
    // A camada das mesas não é decorativa: mostra texto, mas o texto não é
    // escrito aqui — vem do lugar de cada convidado.
    $('props').innerHTML = `<div class="vazio-painel"><b>${rot}</b><br>
      O que esta camada mostra vem das mesas de cada convidado, e por isso muda de cartão para cartão.
      Altera-se na <b>Planta de Mesas</b>, não aqui.<br><br>
      Aqui pode escondê-la (o olho na lista de camadas) e, na camada <b>Bloco do convidado</b>,
      escolher se o número de lugares aparece junto ao nome.</div>
      <div class="campo" style="margin-top:.5rem">
        <a class="bt" style="display:block;text-align:center;text-decoration:none" href="mesas.php">Abrir a Planta de Mesas</a>
      </div>` + blocoPosicao(selecionada);
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
    const max  = LIMITES[chave];
    const cont = max ? `<span class="contador ${classeCont(String(v).length,max)}">${String(v).length}/${max}</span>` : '';
    const ctl = tipo === 'area'
      ? `<textarea data-chave="${chave}" ${max?`maxlength="${max}"`:''} oninput="editarTexto(this)">${escaparHtml(v)}</textarea>`
      : `<input type="${tipo==='data'?'date':(tipo==='hora'?'time':'text')}" data-chave="${chave}" ${max?`maxlength="${max}"`:''}
                value="${escaparAttr(v)}" oninput="editarTexto(this)">`;
    return `<div class="campo"><label>${rotulo}${cont}</label>${ctl}</div>`;
  }).join('')
  + (selecionada === 'logistica' ? blocoCerimonias() : '')
  + (selecionada === 'nomes' ? feitio('Entre os nomes', ELOS, est.elo, 'mudarElo') : '')
  + blocoPosicao(selecionada);
}
/**
 * Propriedades de uma camada decorativa. Não têm texto, mas têm feitio: até
 * aqui só se podiam ligar e desligar, e quem não gostasse da moldura ficava
 * sem alternativa a não ser ficar sem ela.
 */
function renderPropsOrnamento(k, rot){
  let h = `<div class="vazio-painel" style="margin-bottom:.6rem"><b>${rot}</b></div>`;

  if (k === 'moldura'){
    const est0 = est.deco['cartao.moldura_estilo'] || 'simples';
    h += `<div class="campo"><label>Feitio</label>
      <select onchange="mudarMoldura(this.value)">
        ${Object.entries(MOLDURAS).map(([id,m]) =>
          `<option value="${id}" ${id===est0?'selected':''}>${escaparHtml(m.nome)}</option>`).join('')}
      </select>
      <div class="ajuda">${escaparHtml((MOLDURAS[est0]||{}).nota || '')}</div></div>`;
    h += faixa({rot:'Distância à borda', valor:est.deco['cartao.moldura_margem'] || 28,
                min:16, max:48, passo:1, unidade:'px', origem:28,
                fn:'mudarMolduraMargem',
                ajuda:'Quanto a moldura se afasta do rebordo do cartão.'});
  }

  if (k === 'floreados') h += feitio('Feitio', FLOREADOS, est.floreado, 'mudarFloreado');
  if (k === 'volutas')   h += feitio('Feitio', VOLUTAS,   est.voluta,   'mudarVoluta');

  if (k === 'ramos'){
    h += `<div class="campo"><label>Folhagem</label>
      <select onchange="mudarFolhagem(this.value)">
        ${Object.entries(FOLHAGENS).map(([id,f]) =>
          `<option value="${id}" ${id===est.folhagem?'selected':''}>${escaparHtml(f.nome)}</option>`).join('')}
      </select>
      <div class="ajuda">A mesma escolha da barra de cima, à mão de quem está a mexer nesta camada.</div></div>`;
  }

  if (ORN_ESCALA[k]){
    h += faixa({rot:'Tamanho', valor:est.deco[ORN_ESCALA[k]] || 100,
                min:60, max:140, passo:5, unidade:'%', origem:100,
                fn:`mudarOrnamento.bind(null,'${k}')`,
                ajuda:'Só este ornamento. O resto do cartão fica onde está.'});
  }

  h += blocoPosicao(k);
  h += `<div class="ajuda" style="margin-top:.6rem">A cor vem do painel <b>Cores</b>.
    Para tirar esta camada do cartão, use o olho na lista de camadas.</div>`;
  $('props').innerHTML = h;
}
/** Uma faixa com o valor à vista e um botão que devolve o de origem. */
function faixa(o){
  return `<div class="campo"><label>${o.rot}<span class="contador">${o.valor}${o.unidade}</span></label>
    <div class="vs-lin"><input type="range" min="${o.min}" max="${o.max}" step="${o.passo}" value="${o.valor}"
      oninput="(${o.fn})(this.value, this)" style="flex:1">
      <button class="bt bt-min" onclick="(${o.fn})(${o.origem})">Repor</button></div>
    <div class="ajuda">${o.ajuda}</div></div>`;
}
function mudarMoldura(v){
  est.deco['cartao.moldura_estilo'] = v;
  aplicarDeco(); marcarSujo(true); registarPasso(); renderProps();
  msg('Moldura: ' + (MOLDURAS[v]||{}).nome);
}
function mudarMolduraMargem(v, el){
  est.deco['cartao.moldura_margem'] = String(v);
  aplicarDeco(); marcarSujo(true); registarPasso();
  if (el) valorNoRotulo(el, v + 'px'); else renderProps();
  msg('Moldura a ' + v + ' px da borda.');
}
function mudarOrnamento(k, v, el){
  est.deco[ORN_ESCALA[k]] = String(v);
  aplicarDeco(); marcarSujo(true); registarPasso();
  if (el) valorNoRotulo(el, v + '%'); else renderProps();
  msg(CAMADAS[k] + ': ' + v + '%');
}
/** Um seletor de feitio, com a nota do que está escolhido por baixo. */
function feitio(rot, lista, atual, fn){
  return `<div class="campo"><label>${rot}</label>
    <select onchange="${fn}(this.value)">
      ${Object.entries(lista).map(([id,f]) =>
        `<option value="${id}" ${id===atual?'selected':''}>${escaparHtml(f.nome)}</option>`).join('')}
    </select>
    <div class="ajuda">${escaparHtml((lista[atual]||{}).nota || '')}</div></div>`;
}
function mudarFloreado(v){
  aplicarFloreado(v); marcarSujo(true); registarPasso(); renderProps();
  msg('Floreado: ' + (FLOREADOS[v]||{}).nome);
}
function mudarVoluta(v){
  aplicarVoluta(v); marcarSujo(true); registarPasso(); renderProps();
  msg('Volutas: ' + (VOLUTAS[v]||{}).nome);
}
function mudarElo(v){
  aplicarElo(v); marcarSujo(true); registarPasso(); renderProps();
  msg('Entre os nomes: ' + (ELOS[v]||{}).nome);
}
function mudarFolhagem(v){
  aplicarFolhagem(v); $('folhagem').value = v;
  marcarSujo(true); registarPasso(); renderProps();
  msg('Folhagem: ' + (FOLHAGENS[v]||{}).nome);
}
/** Põe no cartão o feitio das camadas decorativas. */
function aplicarDeco(){
  const c = cartao();
  c.style.setProperty('--ct-mold-margem', (est.deco['cartao.moldura_margem'] || 28) + 'px');
  // O feitio da moldura é um conjunto de variáveis — as mesmas que o servidor
  // escreve, para o que se vê aqui ser o que sai impresso.
  const feitio = est.deco['cartao.moldura_estilo'] || 'simples';
  // Limpa-se tudo o que qualquer feitio possa pôr antes de escrever o deste,
  // senão as variáveis do feitio anterior ficavam agarradas ao cartão (era
  // assim que "três linhas" deixava a linha de fora ao passar para "simples").
  // O que se apagar volta ao valor de origem da folha de estilo.
  Object.values(MOLDURA_VARS).forEach(vars =>
    Object.keys(vars).forEach(k => c.style.removeProperty(k)));
  Object.entries(MOLDURA_VARS[feitio] || MOLDURA_VARS.simples || {})
    .forEach(([k, v]) => c.style.setProperty(k, v));
  Object.entries(ORN_ESCALA).forEach(([orn, chave]) =>
    c.style.setProperty('--ct-esc-' + orn, String((+est.deco[chave] || 100) / 100)));
}

// ---------- Tela de posicionamento livre ----------
// Cada camada arrasta-se pelo próprio cartão. O deslocamento guarda-se em
// PERCENTAGEM da peça, nunca em pixels: é o que faz a composição feita a 33%
// de zoom sair igual em 720×1080 na gráfica.
function posDe(k){ const p = est.pos[k]; return p ? {x:p.x, y:p.y, a:p.a||0} : {x:0, y:0, a:0}; }
function estaTrancada(k){ return est.trancados.indexOf(k) >= 0; }
/** Escreve o deslocamento e a volta no cartão (e limpa a marca na origem). */
function pintarPos(k, x, y, a){
  const el = document.querySelector(`#escala [data-camada="${k}"]`);
  if (!el) return;
  a = a || 0;
  const naOrigem = Math.abs(x) < 0.005 && Math.abs(y) < 0.005 && Math.abs(a) < 0.05;
  el.style.setProperty('--px', naOrigem ? '' : String(x));
  el.style.setProperty('--py', naOrigem ? '' : String(y));
  el.style.setProperty('--pa', naOrigem ? '' : String(a));
  // Na origem, TIRAM-SE: um translate a zero continua a ser um translate, e
  // faz da camada o bloco contentor de quem lá dentro se posiciona em
  // absoluto (ver pecas.css). O que não foi movido não mexe em nada.
  el.style.setProperty('--mv', naOrigem ? '' : (x * 7.2) + 'px ' + (y * 10.8) + 'px');
  el.style.setProperty('--rt', naOrigem ? '' : a + 'deg');
  el.classList.toggle('movida', !naOrigem);
}
function definirPos(k, x, y, a){
  a = a || 0;
  if (Math.abs(x) < 0.005 && Math.abs(y) < 0.005 && Math.abs(a) < 0.05) delete est.pos[k];
  else est.pos[k] = {x:x, y:y, a:a};
  pintarPos(k, x, y, a);
}
/** Só a volta, deixando o bloco onde está. */
function definirAngulo(k, a){
  const p = posDe(k);
  definirPos(k, p.x, p.y, TelaLivre.limitarAng(+a || 0));
}
function mudarAngulo(k, v, el){
  definirAngulo(k, v);
  marcarSujo(true); registarPasso();
  if (el) valorNoRotulo(el, (+v||0) + '°'); else renderProps();
  renderCamadas();
  msg(`${CAMADAS[k]}: ${(+v||0)}°`);
}
/** Volta a pintar todas as camadas a partir de est.pos — usado ao desfazer. */
function aplicarPosicoes(){
  Object.keys(CAMADAS).forEach(k => { const p = posDe(k); pintarPos(k, p.x, p.y, p.a); });
  document.querySelectorAll('#escala [data-camada]').forEach(el =>
    el.classList.toggle('trancada-camada', estaTrancada(el.dataset.camada)));
}
function reporPosicao(k){
  if (!est.pos[k]) return msg('"' + CAMADAS[k] + '" já está no sítio de origem.');
  definirPos(k, 0, 0, 0); marcarSujo(true); registarPasso(); renderProps(); renderCamadas();
  msg(`"${CAMADAS[k]}" voltou ao sítio e à posição de origem.`);
}
function reporPosicoes(){
  if (!Object.keys(est.pos).length) return msg('Nenhuma camada foi movida.');
  if (!confirm('Repor TODAS as camadas no sítio que o design lhes deu?\n\nPode desfazer com Ctrl+Z.')) return;
  Object.keys(CAMADAS).forEach(k => definirPos(k, 0, 0, 0));
  marcarSujo(true); registarPasso(); renderProps(); renderCamadas();
  msg('Composição de origem reposta — por guardar.');
}
function alternarTranca(k){
  const i = est.trancados.indexOf(k);
  if (i >= 0) est.trancados.splice(i, 1); else est.trancados.push(k);
  aplicarPosicoes(); renderCamadas(); marcarSujo(true); registarPasso();
  msg(estaTrancada(k) ? `"${CAMADAS[k]}" trancada — não se arrasta nem se esconde.`
                      : `"${CAMADAS[k]}" destrancada.`);
}

/**
 * Atualiza o número ao lado do rótulo sem redesenhar o painel.
 *
 * Redesenhar durante o arrasto trocava o elemento por baixo do rato: o
 * navegador perdia o alvo e o arrasto morria à primeira mexida — dava para
 * clicar na faixa, mas não para a arrastar. O mesmo valia para os seletores
 * de cor, que fechavam sozinhos.
 */
function valorNoRotulo(el, texto){
  const campo = el && el.closest('.campo');
  const c = campo && campo.querySelector('.contador');
  if (c) c.textContent = texto;
}

/** Contador quase cheio (>90%) ou no limite: a cor avisa antes de cortar. */
function classeCont(n, max){ return n >= max ? 'cheio' : (n > max*0.9 ? 'perto' : ''); }
function escaparHtml(s){ return String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escaparAttr(s){ return String(s).replace(/["&<>]/g, c => ({'"':'&quot;','&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

// Escreve no cartão à medida que se escreve no campo
function editarTexto(el){
  const chave = el.dataset.chave, valor = el.value;
  est.textos[chave] = valor;
  const max = LIMITES[chave], c = el.closest('.campo').querySelector('.contador');
  if (c && max){ c.textContent = valor.length+'/'+max; c.className = 'contador '+classeCont(valor.length,max); }
  pintarTexto(chave, valor);
  marcarSujo(true); registarPasso();
}
function editarBool(el){
  const chave = el.dataset.chave, valor = el.checked ? '1' : '0';
  est.textos[chave] = valor;
  pintarTexto(chave, valor);
  marcarSujo(true); registarPasso();
}
function pintarTexto(chave, valor){
  const c = document.querySelector('#escala .cartao');
  const porCampo = {
    'cartao.abertura':'abertura', 'cartao.frase_convite':'frase', 'cartao.reservado':'reservado',
    'evento.civil_titulo':'civil_titulo', 'evento.religiosa_titulo':'relig_titulo', 'cartao.frase_final':'frase_final',
    'casal.noiva':'noiva', 'casal.noivo':'noivo', 'evento.venue_titulo':'copo_titulo'
  };
  if (porCampo[chave]) {
    const n = c.querySelector(`[data-campo="${porCampo[chave]}"]`);
    if (n) n.innerHTML = chave === 'cartao.abertura' ? escaparHtml(valor).replace(/\n/g,'<br>') : escaparHtml(valor);
    return;
  }
  // A logística inteira redesenha-se de uma vez: acrescentar ou remover uma
  // cerimónia muda a ESTRUTURA do bloco (títulos que nascem e morrem, e a
  // margem de cima que salta para o primeiro que sobrar), e remendar linha a
  // linha só acertava enquanto ninguém mexesse no que existe.
  if (/^evento\.(civil|religiosa)_|^evento\.(hora|local|venue_titulo)$/.test(chave)) { pintarLogistica(); return; }
  if (chave === 'evento.data') {
    const d = new Date(valor + 'T12:00:00');
    if (isNaN(d)) return;
    const dt = c.querySelector('.ct-data'), di = c.querySelector('.ct-dia');
    if (dt) dt.textContent = d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear();
    if (di) di.textContent = DIAS[d.getDay()];
  }
}
/**
 * Redesenha o bloco de logística — o mesmo desenho que cartaoLogistica() faz
 * no servidor. Cada cerimónia só entra se tiver hora, e o primeiro título
 * visível não leva a margem de cima.
 */
function pintarLogistica(){
  const box = document.querySelector('#escala .ct-logistica'); if (!box) return;
  const t = est.textos, hp = k => horaPt(t[k] || '');
  let h = '', primeiro = true;
  const bloco = (titulo, hora, local) => {
    if (!hora) return;
    h += `<div class="ct-seccao${primeiro ? '' : ' ct-seccao-2'}">${escaparHtml(titulo || '')}</div>`
       + `<div class="ct-detalhe">às ${escaparHtml(hora)}${local ? '<br>' + escaparHtml(local) : ''}</div>`;
    primeiro = false;
  };
  bloco(t['evento.civil_titulo'], hp('evento.civil_hora'), t['evento.civil_local']);
  bloco(t['evento.religiosa_titulo'], hp('evento.religiosa_hora'), t['evento.religiosa_local']);
  h += `<div class="ct-seccao${primeiro ? '' : ' ct-seccao-2'}" data-campo="copo_titulo">${escaparHtml(t['evento.venue_titulo'] || '')}</div>`
     + `<div class="ct-detalhe ct-detalhe-2">${escaparHtml(t['evento.local'] || '')}`
     + (hp('evento.hora') ? '<br>às ' + escaparHtml(hp('evento.hora')) : '') + `</div>`;
  box.innerHTML = h;
}

/**
 * As duas cerimónias, no painel: cada uma acrescenta-se e remove-se por
 * inteiro. É a HORA que decide se existe — remover é limpá-la —, mas fazer
 * isso à mão num campo de hora não se descobre, e deixava o casal a apagar
 * dígitos à espera que a linha desaparecesse.
 */
const CERIMONIAS = [
  ['civil',     'Cerimónia civil',     '10:30'],
  ['religiosa', 'Cerimónia religiosa', '15:00']
];
function blocoCerimonias(){
  // Num modelo, isto não se governa: as cerimónias são de cada casamento, e o
  // servidor (modelo_defs) só guarda as chaves do cartão. Mostrar os controlos
  // seria oferecer o que a gravação ia deitar fora.
  if (MODELO) return `<div class="campo" style="margin-top:.9rem"><label>Cerimónias</label>
    <div class="ajuda">As cerimónias, o local e a hora são de cada casamento — um modelo é o
      <b>desenho</b>, não a festa. Cada casal preenche-as na sua ficha, e o cartão acompanha.</div></div>`;
  return `<div class="campo" style="margin-top:.9rem"><label>Cerimónias</label>
    <div class="ajuda">Opcionais, as duas. Há casamentos só com uma, e há quem faça as duas
      no mesmo sítio — o que não for acrescentado não aparece no cartão.</div></div>`
    + CERIMONIAS.map(([k, rot, horaPadrao]) => {
      const hora = est.textos['evento.' + k + '_hora'] || '';
      if (!hora) {
        return `<div class="campo cer-fora">
          <button class="bt" style="width:100%" onclick="acrescentarCerimonia('${k}','${horaPadrao}')">
            + Acrescentar ${rot.toLowerCase()}</button></div>`;
      }
      return `<div class="campo cer-dentro">
        <label>${rot}
          <button class="bt bt-min" onclick="removerCerimonia('${k}','${rot}')"
                  title="Tirar esta cerimónia do cartão">Remover</button></label>
        <input type="text" data-chave="evento.${k}_titulo" maxlength="40"
               value="${escaparAttr(est.textos['evento.' + k + '_titulo'] || '')}"
               placeholder="Como se chama" oninput="editarTexto(this)">
        <div class="vs-lin" style="margin-top:.35rem">
          <input type="time" data-chave="evento.${k}_hora" value="${escaparAttr(hora)}"
                 oninput="editarTexto(this)" style="flex:0 0 110px">
          <input type="text" data-chave="evento.${k}_local" maxlength="80"
                 value="${escaparAttr(est.textos['evento.' + k + '_local'] || '')}"
                 placeholder="Onde é (opcional)" oninput="editarTexto(this)" style="flex:1">
        </div>
      </div>`;
    }).join('');
}
function acrescentarCerimonia(k, horaPadrao){
  est.textos['evento.' + k + '_hora'] = horaPadrao;
  if (!est.textos['evento.' + k + '_titulo']) est.textos['evento.' + k + '_titulo'] = PADRAO_EV['evento.' + k + '_titulo'];
  pintarLogistica(); marcarSujo(true); registarPasso(); renderProps();
  msg('Cerimónia acrescentada — ajuste a hora e o local.');
}
function removerCerimonia(k, rot){
  if (!confirm(`Tirar a ${rot.toLowerCase()} do cartão?\n\nA hora e o local são apagados. Ctrl+Z desfaz.`)) return;
  est.textos['evento.' + k + '_hora'] = '';
  est.textos['evento.' + k + '_local'] = '';
  pintarLogistica(); marcarSujo(true); registarPasso(); renderProps();
  msg(`"${rot}" deixou de se anunciar.`);
}

// Hora por preencher não é meia-noite — o mesmo que horaTexto() faz no
// servidor. Sem isto, ''.split(':') dava [''] e (+'') dava 0: uma cerimónia
// removida continuava a anunciar-se "às 0h" na prova do editor.
function horaPt(hhmm){
  const v = String(hhmm||'').trim(); if (!v) return '';
  const [h,m] = v.split(':'); if (h===undefined) return '';
  return (+h)+'h'+(+m?String(m).padStart(2,'0'):'');
}

// ---------- Ferramentas ----------
function usarFerramenta(f){
  ferramenta = f;
  document.querySelectorAll('.ferr').forEach(x => x.classList.toggle('on', x.dataset.ferr === f));
  $('mesa').classList.toggle('mao', f === 'mao');
  // A vista limpa tira as marcas de seleção, para se ver o cartão como sai impresso.
  $('arte').classList.toggle('marcar', f === 'mover' || f === 'texto');
  $('arte').classList.toggle('livre', f === 'mover');
  if (f === 'limpo') document.querySelectorAll('#escala [data-camada]').forEach(n => n.classList.remove('sel-camada'));
  else if (selecionada) selecionar(selecionada);
  if (f === 'texto' && selecionada) { const t = $('props').querySelector('input,textarea'); if (t) t.focus(); }
  msg(f === 'limpo' ? 'Vista limpa: sem marcas de seleção.'
    : (f === 'mover' ? 'Clique numa camada para a escolher — ou arraste-a para a mudar de sítio.'
                     : 'Clique numa camada do cartão para a editar.'));
}
document.querySelectorAll('.ferr').forEach(b => b.addEventListener('click', () => usarFerramenta(b.dataset.ferr)));

document.addEventListener('keydown', e => {
  // Os atalhos de comando valem mesmo dentro de um campo — é lá que se escreve.
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='z'){ e.preventDefault(); e.shiftKey?refazer():desfazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='y'){ e.preventDefault(); refazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){ e.preventDefault(); guardar(); return; }
  if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
  // As setas afinam a camada escolhida ponto a ponto — o que o rato não dá.
  // Um passo é 0,25% do cartão (1,8 px na largura); com Shift, 2%.
  const setas = { ArrowLeft:[-1,0], ArrowRight:[1,0], ArrowUp:[0,-1], ArrowDown:[0,1] };
  if (setas[e.key] && selecionada && !estaTrancada(selecionada)) {
    e.preventDefault();
    const passo = e.shiftKey ? 2 : 0.25, p = posDe(selecionada);
    // Com Alt as setas viram em vez de deslocar: 1° de cada vez, 15° com Shift.
    if (e.altKey) {
      const giro = (setas[e.key][0] + setas[e.key][1]) * (e.shiftKey ? 15 : 1);
      definirAngulo(selecionada, p.a + giro);
    } else
    definirPos(selecionada, TelaLivre.arred(TelaLivre.limitar(p.x + setas[e.key][0]*passo)),
                            TelaLivre.arred(TelaLivre.limitar(p.y + setas[e.key][1]*passo)), p.a);
    marcarSujo(true); registarPasso(); renderProps(); renderCamadas();
    return;
  }
  const m = { v:'mover', t:'texto', h:'mao', z:'zoom', p:'limpo' }[e.key.toLowerCase()];
  if (m) usarFerramenta(m);
  if (e.key === '0') ajustar();
});

// Clicar no cartão seleciona a camada (ou amplia, com a ferramenta de zoom)
$('escala').addEventListener('click', e => {
  if (ferramenta === 'zoom') { zoomPasso(e.shiftKey ? -1 : 1); return; }
  const alvo = e.target.closest('[data-camada]');
  if (alvo) selecionar(alvo.dataset.camada);
});

// Arrastar as camadas pelo próprio cartão (só com a ferramenta de mover).
// A caixa de referência é #escala — a peça em tamanho real, antes do zoom —
// para as percentagens não dependerem da ampliação a que se está a trabalhar.
TelaLivre.ligar({
  tela: $('escala'),
  guias: $('guias'),
  ativo: () => ferramenta === 'mover',
  blocos: () => Object.keys(CAMADAS).map(k =>
            ({ id:k, el: document.querySelector(`#escala [data-camada="${k}"]`) }))
            .filter(b => b.el && est.camadas[b.id] !== 0),
  pos: posDe,
  escolhida: () => selecionada,
  trancado: estaTrancada,
  pegar: k => { if (selecionada !== k) selecionar(k); $('arte').classList.add('a-mover'); },
  mover: (k, x, y, a) => pintarPos(k, x, y, a),
  largar: (k, x, y, a) => {
    $('arte').classList.remove('a-mover');
    definirPos(k, x, y, a);
    marcarSujo(true); registarPasso(); renderProps(); renderCamadas();
    msg(est.pos[k] ? `${CAMADAS[k]}: ${x}% · ${y}%${a ? ' · ' + a + '°' : ''}`
                   : `"${CAMADAS[k]}" voltou ao sítio de origem.`);
  }
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
/** O estado do editor como definições, prontas para gravar. */
function serializar(){
  return Object.assign({}, est.textos, est.fontes, est.deco, {
    'cartao.paleta':   est.paleta,
    'cartao.folhagem': est.folhagem,
    'cartao.floreado': est.floreado,
    'cartao.voluta':   est.voluta,
    'cartao.elo':      est.elo,
    'cartao.camadas':  JSON.stringify(est.camadas),
    'cartao.cores':    Object.keys(est.cores).length ? JSON.stringify(est.cores) : '',
    'cartao.escala':   String(est.escala || 100),
    // Só o que foi mesmo movido: um mapa vazio grava-se como vazio, e a peça
    // volta a ser exatamente a que o design desenhou.
    'cartao.posicoes': Object.keys(est.pos).length
                       ? JSON.stringify(Object.fromEntries(
                           Object.entries(est.pos).map(([k,p]) =>
                             [k, p.x + ' ' + p.y + (p.a ? ' ' + p.a : '')]))) : '',
    'cartao.trancados': est.trancados.join(',')
  });
}
function rotuloDe(chave){
  for (const campos of Object.values(CAMPOS)) {
    const c = (campos||[]).find(x => x[0] === chave);
    if (c) return c[1];
  }
  return chave;
}
// Um modelo guarda-se inteiro: é o desenho que é, e não a diferença para um
// casamento qualquer.
function serializarTudo(){
  const v = serializar(), fora = {};
  Object.keys(PADRAO_MODELO).forEach(k => { if (k in v) fora[k] = String(v[k] ?? ''); });
  return fora;
}

async function guardar(){
  const v = serializar();
  // Só o que mudou, e só as chaves do cartão: assim não se pisa o convite
  // digital nem se reescrevem definições que ninguém tocou.
  const defs = {};
  Object.keys(PADRAO).forEach(k => {
    if (!(k in v)) return;
    const novo = String(v[k] ?? '');
    if (novo !== String(ATUAIS[k] ?? '')) defs[k] = novo;
  });
  if (!Object.keys(defs).length){ marcarSujo(false); msg('Não há alterações por guardar.'); return true; }
  $('bt-guardar').disabled = true; msg('A guardar…');
  const d = MODELO
    ? await api('modelo_defs&id=' + MODELO.id, {method:'POST', body: JSON.stringify({defs: serializarTudo()})})
    : await api('defs_save', {method:'POST', body: JSON.stringify({defs})});
  $('bt-guardar').disabled = false;
  if (!d.success){ msg(d.message || 'Não foi possível guardar.'); return false; }
  const inv = d.invalidas || [];
  Object.keys(defs).forEach(k => { if (!inv.includes(k)) ATUAIS[k] = defs[k]; });
  marcarSujo(false);
  marcarInvalidos(inv);
  msg(inv.length ? `Guardado, mas ${inv.length} campo(s) não foram aceites: ${inv.map(rotuloDe).join(', ')}.`
                 : 'Guardado.');
  if (!inv.length) setTimeout(() => { if(!sujo) msg(''); }, 2500);
  return inv.length === 0;
}
function marcarInvalidos(inv){
  document.querySelectorAll('#props .campo').forEach(c => c.classList.remove('invalido'));
  inv.forEach(k => {
    const el = document.querySelector('#props [data-chave="'+k+'"]');
    if (el) el.closest('.campo').classList.add('invalido');
  });
}
function repor(){
  if (!confirm('Repor todos os valores como estavam ao abrir o editor?')) return;
  est = JSON.parse(JSON.stringify(original));
  repintarTudo(); marcarSujo(true); registarPasso();
  msg('Reposto — por guardar. Ctrl+Z desfaz.');
}
/** Devolve o feitio de origem de uma camada decorativa. */
function reporOrnamento(){
  const k = selecionada;
  if (!confirm(`Repor o feitio original de "${CAMADAS[k]}"?\n\nPode desfazer com Ctrl+Z.`)) return;
  if (k === 'moldura'){
    est.deco['cartao.moldura_estilo'] = PADRAO['cartao.moldura_estilo'];
    est.deco['cartao.moldura_margem'] = PADRAO['cartao.moldura_margem'];
  }
  if (ORN_ESCALA[k]) est.deco[ORN_ESCALA[k]] = PADRAO[ORN_ESCALA[k]];
  if (k === 'ramos') aplicarFolhagem(PADRAO['cartao.folhagem']), $('folhagem').value = PADRAO['cartao.folhagem'];
  if (k === 'floreados') aplicarFloreado(PADRAO['cartao.floreado']);
  if (k === 'volutas')   aplicarVoluta(PADRAO['cartao.voluta']);
  aplicarDeco(); renderProps(); marcarSujo(true); registarPasso();
  msg(`"${CAMADAS[k]}" reposta — por guardar. Ctrl+Z desfaz.`);
}

/** Repõe os textos de origem só da camada escolhida. */
function reporCamada(){
  if (!selecionada) return msg('Escolha primeiro uma camada, na lista ou no cartão.');
  if (ORNAMENTOS.includes(selecionada)) return reporOrnamento();
  const campos = CAMPOS[selecionada];
  if (!campos) return msg('"' + CAMADAS[selecionada] + '" mostra as mesas de cada convidado: altera-se na Planta de Mesas.');
  if (!confirm(`Repor os textos originais de "${CAMADAS[selecionada]}"?\n\nPode desfazer com Ctrl+Z.`)) return;
  campos.forEach(([chave]) => {
    if (!(chave in PADRAO)) return;
    est.textos[chave] = PADRAO[chave];
    pintarTexto(chave, PADRAO[chave]);
  });
  if (selecionada === 'nomes') aplicarElo(PADRAO['cartao.elo']);   // os nomes trazem o que os liga
  renderProps(); marcarSujo(true); registarPasso();
  msg(`"${CAMADAS[selecionada]}" reposta — por guardar. Ctrl+Z desfaz.`);
}
window.addEventListener('beforeunload', e => { if (sujo) { e.preventDefault(); e.returnValue = ''; } });

// Ligações
document.getElementById('amostras').addEventListener('click', e => {
  const b = e.target.closest('.amostra'); if (!b) return;
  escolherPaleta(b.dataset.paleta);
});
$('folhagem').addEventListener('change', e => {
  aplicarFolhagem(e.target.value); marcarSujo(true); registarPasso();
});
window.addEventListener('resize', () => { if (zoom <= .5) ajustar(); });

// ---------- Versões do convite impresso ----------
// Painel partilhado com o editor do convite digital (assets/versoes.js).
// Só há versões de um casamento; a desenhar um modelo, não há seletor.
if (!MODELO) Versoes.montar({
  ambito: 'impresso',
  alvo:   'sel-versao',
  sujo:   () => sujo,
  gravar: guardar,          // grava as edições do ecrã antes de as fotografar na versão
  msg,
  aoAplicar: () => setTimeout(() => { sujo = false; location.reload(); }, 700)
});

aplicarPosicoes();
renderCamadas(); renderProps(); renderCores(); renderTipografia();
marcarBotoes(); ajustar();
msg('Clique numa camada para a editar — ou arraste-a no cartão para a mudar de sítio.');
</script>
<script src="<?= asset('assets/editor-paineis.js') ?>"></script>
</body>
</html>
