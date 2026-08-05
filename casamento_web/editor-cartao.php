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

$pal      = cartaoPaletaEfetiva($defs);
$estilo   = cartaoEstiloVars($defs);
$folhagem = $defs['cartao.folhagem'];
$camadas  = cartaoCamadasVisiveis($defs);
$ev       = cartaoDadosEvento($defs);

// Chaves que este editor governa — as do cartão, e só essas. Serve para gravar
// apenas o que mudou, sem pisar o convite digital.
$chavesCartao = chavesDoAmbito('impresso');

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
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/pecas.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/editor.css') ?>" rel="stylesheet">
</head>
<body class="editor">

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
  <button class="bt" onclick="reporCamada()" title="Repor os textos originais da camada escolhida">Repor esta camada</button>
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
    <button class="ferr" data-ferr="limpo" title="Ver sem marcas (P)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
  </div>

  <!-- Mesa de trabalho -->
  <div class="ed-mesa" id="mesa">
    <div class="ed-arte marcar" id="arte" style="--esc:.5">
      <div class="escala" id="escala" style="width:720px;height:1080px;transform:scale(var(--esc));transform-origin:top left">
        <?= renderCartaoConvite($ev, $conv, $pal, $folhagem, $comLug, $camadas, $estilo) ?>
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
    <div class="ed-painel fechado" id="p-versoes">
      <h3 onclick="alternarPainel(this)">Versões <span class="chev">▾</span></h3>
      <div class="ed-painel-corpo" id="versoes"></div>
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
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const $ = id => document.getElementById(id);

const PALETAS  = <?= json_encode(cartaoPaletas(), JSON_UNESCAPED_UNICODE) ?>;
const RAMOS    = <?= json_encode($ramosJs, JSON_UNESCAPED_UNICODE) ?>;
const CAMADAS  = <?= json_encode(cartaoCamadas(), JSON_UNESCAPED_UNICODE) ?>;
const CAMPOS   = <?= json_encode($camposPorCamada, JSON_UNESCAPED_UNICODE) ?>;
const ORNAMENTOS = ['ramos','volutas','moldura','floreados'];   // camadas sem texto
const MOLDURAS  = <?= json_encode(cartaoMolduras(), JSON_UNESCAPED_UNICODE) ?>;
const FOLHAGENS = <?= json_encode(cartaoFolhagens(), JSON_UNESCAPED_UNICODE) ?>;
// O que cada camada decorativa oferece, além de se poder esconder.
const ORN_ESCALA = { ramos:'cartao.ramos_escala', volutas:'cartao.volutas_escala',
                     floreados:'cartao.floreados_escala' };
const NOMES_PROVA = <?= json_encode($nomesProva, JSON_UNESCAPED_UNICODE) ?>;
const PADRAO   = <?= json_encode(array_intersect_key(defsPadrao(), array_flip($chavesCartao)), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS   = <?= json_encode(array_intersect_key($defs, array_flip($chavesCartao)), JSON_UNESCAPED_UNICODE) ?>;
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
                  'cartao.frase_convite':160, 'cartao.reservado':30, 'cartao.civil_titulo':40,
                  'cartao.frase_final':220, 'evento.venue_titulo':40, 'evento.local':80 };
const MESES    = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const DIAS     = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];

// Estado do documento
let est = {
  paleta:   <?= json_encode($defs['cartao.paleta']) ?>,
  folhagem: <?= json_encode($folhagem) ?>,
  camadas:  <?= json_encode($camadas) ?>,
  cores:    (()=>{ try { return JSON.parse(<?= json_encode($defs['cartao.cores']) ?> || '{}') || {}; } catch(e){ return {}; } })(),
  fontes:   <?= json_encode(array_intersect_key($defs, array_flip(['cartao.fonte_script','cartao.fonte_serif','cartao.fonte_sans'])), JSON_UNESCAPED_UNICODE) ?>,
  escala:   <?= json_encode($defs['cartao.escala']) ?>,
  deco:     <?= json_encode(array_intersect_key($defs, array_flip([
                 'cartao.moldura_estilo','cartao.moldura_margem',
                 'cartao.ramos_escala','cartao.volutas_escala','cartao.floreados_escala'])), JSON_UNESCAPED_UNICODE) ?>,
  textos:   <?= json_encode(array_intersect_key($defs, array_flip([
                 'cartao.abertura','cartao.frase_convite','cartao.reservado','cartao.civil_titulo',
                 'cartao.civil_hora','cartao.frase_final','casal.noiva','casal.noivo',
                 'evento.venue_titulo','evento.local','evento.hora','evento.data','cartao.numero_no_nome'])), JSON_UNESCAPED_UNICODE) ?>
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
  aplicarFolhagem(est.folhagem);
  $('folhagem').value = est.folhagem;
  Object.entries(est.textos).forEach(([k,v]) => pintarTexto(k,v));
  Object.keys(CAMADAS).forEach(k => {
    const alvo = document.querySelector(`#escala [data-camada="${k}"]`);
    if (alvo) alvo.classList.toggle('ct-oculta', est.camadas[k] === 0);
  });
  aplicarDeco();
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
function renderPropsJa(){
  const campos = CAMPOS[selecionada];
  if (!selecionada) { $('props').innerHTML = '<div class="vazio-painel">Escolha uma camada — na lista abaixo ou clicando no cartão — para editar o que ela mostra.</div>'; return; }
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
      </div>`;
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
  }).join('');
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
  const linha  = feitio === 'fina' ? '.7px' : '1.4px';
  c.style.setProperty('--ct-mold-linha', linha);
  c.style.setProperty('--ct-mold-larg', feitio === 'cantos' ? '0' : linha);
  c.style.setProperty('--ct-mold-sombra',
    feitio === 'dupla' ? 'inset 0 0 0 4px transparent, inset 0 0 0 5.4px var(--ct-accent)' : 'none');
  c.style.setProperty('--ct-mold-cantos', feitio === 'cantos' ? 'block' : 'none');
  Object.entries(ORN_ESCALA).forEach(([orn, chave]) =>
    c.style.setProperty('--ct-esc-' + orn, String((+est.deco[chave] || 100) / 100)));
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
function usarFerramenta(f){
  ferramenta = f;
  document.querySelectorAll('.ferr').forEach(x => x.classList.toggle('on', x.dataset.ferr === f));
  $('mesa').classList.toggle('mao', f === 'mao');
  // A vista limpa tira as marcas de seleção, para se ver o cartão como sai impresso.
  $('arte').classList.toggle('marcar', f === 'mover' || f === 'texto');
  if (f === 'limpo') document.querySelectorAll('#escala [data-camada]').forEach(n => n.classList.remove('sel-camada'));
  else if (selecionada) selecionar(selecionada);
  if (f === 'texto' && selecionada) { const t = $('props').querySelector('input,textarea'); if (t) t.focus(); }
  msg(f === 'limpo' ? 'Vista limpa: sem marcas de seleção.' : 'Clique numa camada do cartão para a editar.');
}
document.querySelectorAll('.ferr').forEach(b => b.addEventListener('click', () => usarFerramenta(b.dataset.ferr)));

document.addEventListener('keydown', e => {
  // Os atalhos de comando valem mesmo dentro de um campo — é lá que se escreve.
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='z'){ e.preventDefault(); e.shiftKey?refazer():desfazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='y'){ e.preventDefault(); refazer(); return; }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){ e.preventDefault(); guardar(); return; }
  if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
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
    'cartao.camadas':  JSON.stringify(est.camadas),
    'cartao.cores':    Object.keys(est.cores).length ? JSON.stringify(est.cores) : '',
    'cartao.escala':   String(est.escala || 100)
  });
}
function rotuloDe(chave){
  for (const campos of Object.values(CAMPOS)) {
    const c = (campos||[]).find(x => x[0] === chave);
    if (c) return c[1];
  }
  return chave;
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
  if (!Object.keys(defs).length){ marcarSujo(false); return msg('Não há alterações por guardar.'); }
  $('bt-guardar').disabled = true; msg('A guardar…');
  const d = await api('defs_save', {method:'POST', body: JSON.stringify({defs})});
  $('bt-guardar').disabled = false;
  if (!d.success) return msg(d.message || 'Não foi possível guardar.');
  const inv = d.invalidas || [];
  Object.keys(defs).forEach(k => { if (!inv.includes(k)) ATUAIS[k] = defs[k]; });
  marcarSujo(false);
  marcarInvalidos(inv);
  msg(inv.length ? `Guardado, mas ${inv.length} campo(s) não foram aceites: ${inv.map(rotuloDe).join(', ')}.`
                 : 'Guardado.');
  if (!inv.length) setTimeout(() => { if(!sujo) msg(''); }, 2500);
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
Versoes.montar({
  ambito: 'impresso',
  alvo:   'versoes',
  sujo:   () => sujo,
  msg,
  aoAplicar: () => setTimeout(() => { sujo = false; location.reload(); }, 700)
});

renderCamadas(); renderProps(); renderCores(); renderTipografia();
marcarBotoes(); ajustar();
msg('Clique numa camada do cartão para a editar.');
</script>
<script src="<?= asset('assets/editor-paineis.js') ?>"></script>
</body>
</html>
