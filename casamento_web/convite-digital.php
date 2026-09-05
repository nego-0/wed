<?php
// ============================================================
// convite-digital.php — Serve o convite original personalizado.
//   • Visualização: recursos externos (leve, cacheável).
//   • ?download=1: monta na hora uma versão autossuficiente
//     (imagens, áudio, tipos de letra e QR embutidos) para ver
//     completamente offline. Como é gerada dinamicamente, não
//     fica guardada e não está sujeita ao limite de 1 MB.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

// O convite tem de ser encontrado ANTES de se lerem as definições: é o código
// que revela de que casamento se trata, e as definições (nomes, cores, textos)
// são de cada casamento. Ao contrário, o convidado recebia o convite certo
// vestido com o desenho de outro casal.
$codigo   = strtoupper(trim($_GET['c'] ?? ''));
$c        = $codigo !== '' ? carregarConvite($conn, $codigo, 'codigo') : null;

$DEFS = defsAtuais($conn);
$download = isset($_GET['download']) && $_GET['download'] === '1';

// Pré-visualização do editor (só admin): convidado de exemplo, sem tocar na BD.
$demo = isset($_GET['demo']) && $_GET['demo'] === '1';
if ($demo) {
    // Também o admin da plataforma, que desenha modelos sem casamento aberto.
    if (!ehAdmin() && !ehAdminPlataforma()) { http_response_code(403); exit('Apenas administração.'); }
    $c = ['id'=>0, 'codigo'=>'EXEMPLO', 'nome_exibicao'=>'Família Exemplo', 'sufixo'=>null,
          'mostrar_num_mesa'=>1, 'lugares'=>4, 'mesa_nome'=>'Mesa 1',
          'msg_pessoal'=>'', 'membros'=>[]];
}

// ---- Tela do editor -----------------------------------------
// Só admin, e sempre dentro de ?demo=1. Marca as secções e os textos para o
// editor os poder selecionar e reescrever ao vivo.
$modoEditor = $demo && isset($_GET['editor']) && $_GET['editor'] === '1';

// Prova encolhida (a miniatura da página de entrada): o convite é o mesmo, mas
// sem os botões flutuantes, que numa miniatura só fazem barulho e se sobrepõem
// ao rótulo por cima.
$modoProva = $demo && ($_GET['prova'] ?? '') === '1';

// Prova de um MODELO da casa: o desenho é o do modelo, e não o de casamento
// nenhum. É o que permite à página dos modelos mostrar o que cada um é, em vez
// de o pedir a quem só tem o nome para adivinhar.
if ($demo && (int)($_GET['modelo'] ?? 0) > 0) {
    // O terceiro valor: o modelo VISTO. O segundo é o modelo em edição, que
    // só o admin tem — e usá-lo aqui fazia a prova do casal cair no convite
    // dele, com todas as miniaturas iguais.
    [$defsMod, , $MOD] = defsDoEditor($conn, 'digital');
    if ($MOD) $DEFS = $defsMod;
}

// Rascunho por gravar: o editor envia o estado em edição para a tela poder
// mostrar o que ainda não foi para a base de dados — secções escondidas,
// listas, efeitos. Nada é gravado aqui; os valores passam pela mesma validação
// da gravação, para o que se vê ser mesmo o que ficaria guardado.
if ($modoEditor && isset($_POST['rascunho'])) {
    $r = json_decode((string)$_POST['rascunho'], true);
    if (is_array($r)) {
        $padrao = defsPadrao();
        foreach ($r as $k => $v) {
            if (!array_key_exists($k, $padrao) || !is_string($v)) continue;
            $ok = validarDefinicao($k, $v);
            if ($ok === null) continue;                       // inválido: fica o que já lá estava
            $DEFS[$k] = ($ok === '') ? $padrao[$k] : $ok;     // vazio = valor original
        }
    }
}

// ---- Convite inválido: página breve e autossuficiente --------
if (!$c) {
    // Sem código válido não se sabe de que casamento se trata — e o que aqui
    // aparecesse seria o do primeiro casamento da casa, que nada tem que ver
    // com quem escreveu o endereço errado. Página neutra: sem nomes, sem
    // contactos de ninguém.
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Convite não encontrado</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'font-family:Georgia,serif;background:#16261E;color:#FBF8F1;text-align:center;padding:2rem}a{color:#D9BC8C}</style>'
       . '</head><body><div><p style="font-size:1.6rem;color:#D9BC8C">Convite</p>'
       . '<p>Este convite não foi encontrado. Confirme o endereço, por favor, '
       . 'ou fale com quem lho enviou.</p></div></body></html>';
    exit;
}

// ---- Carregar o modelo (leve) --------------------------------
$tplPath = __DIR__ . '/assets/convite-base.html';
$tpl = is_readable($tplPath) ? file_get_contents($tplPath) : false;
if ($tpl === false || $tpl === '') {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $msg = 'O modelo do convite (assets/convite-base.html) não está disponível no servidor.';
    if (isset($_GET['diag']) && $_GET['diag'] === '1') {
        $dir  = __DIR__ . '/assets';
        $cvd  = __DIR__ . '/assets/convite';
        $l1   = is_dir($dir) ? implode(', ', array_diff(scandir($dir), ['.','..'])) : '(sem pasta assets)';
        $l2   = is_dir($cvd) ? implode(', ', array_diff(scandir($cvd), ['.','..'])) : '(sem pasta assets/convite)';
        $msg .= '<pre style="white-space:pre-wrap;font:13px monospace">Caminho: ' . htmlspecialchars($tplPath)
             .  "\nExiste: " . (file_exists($tplPath)?'sim':'não')
             .  "\n/assets: " . htmlspecialchars($l1)
             .  "\n/assets/convite: " . htmlspecialchars($l2) . '</pre>';
    } else {
        $msg .= ' Acrescente &diag=1 ao endereço para ver detalhes.';
    }
    echo '<div style="max-width:640px;margin:3rem auto;font-family:system-ui,sans-serif;line-height:1.6;padding:0 1rem">' . $msg . '</div>';
    exit;
}

// ---- Personalização ------------------------------------------
$pal  = paletaEfetiva($DEFS);
$nome = escP(nomeConviteVisivel($c));

$mesaBlock = '';
$distrMesas = $c['id'] ? mesasDoConvite($conn, $c)
            : (!empty($c['mesa_nome']) ? [['nome'=>$c['mesa_nome'], 'n'=>(int)$c['lugares']]] : []);
if ($distrMesas) {
    // Opção do convite digital: mostrar (ou não) o "(N pessoas)" ao lado de cada mesa.
    $comNumMesa = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
    $txtMesas = escP(textoMesas($distrMesas, $comNumMesa));
    $rotuloMesa = count($distrMesas) > 1 ? 'Mesas' : 'Mesa';
    $mesaBlock = "<p class=\"guest-mesa\" style=\"margin-top:12px;font-family:'Cormorant Garamond',serif;"
        . "font-size:17px;letter-spacing:.02em;color:{$pal['gold']}\">{$rotuloMesa}: "
        . "<b style=\"font-weight:600;color:{$pal['forest']}\">{$txtMesas}</b></p>";
}

$confirmUrl  = escP(enderecoPublico() . '/convite.php?c=' . $c['codigo']);
$downloadUrl = escP('convite-digital.php?c=' . $c['codigo'] . '&download=1');
$qrValue     = enderecoPublico() . '/convite-digital.php?c=' . $c['codigo'];

// Mensagem pessoal deste convite (opcional)
$msgPessoal = trim((string)($c['msg_pessoal'] ?? ''));
$msgBlock = $msgPessoal !== ''
    ? "<p class=\"guest-msg\" style=\"margin-top:16px;font-family:'Cormorant Garamond',serif;font-style:italic;"
      . "font-size:16px;line-height:1.6;color:{$pal['forest']}\">" . mdTexto($msgPessoal) . '</p>'
    : '';

if ($modoEditor) $tpl = marcarParaEditor($tpl);

// Ordem: primeiro reordenam-se as secções (e entram as livres), depois
// escondem-se as ocultas e só no fim se numeram as páginas — senão a
// numeração sairia da ordem antiga.
$tpl = ordenarBlocos($tpl, $DEFS, ['{noiva}' => $DEFS['casal.noiva'], '{noivo}' => $DEFS['casal.noivo']], $modoEditor);

$out = aplicarSeccoes($tpl, $DEFS);
$out = strtr($out, convitePlaceholders($DEFS) + [
    '{{GUEST_NAME}}'   => $nome,
    '{{MESA_BLOCK}}'   => $mesaBlock,
    '{{MSG_PESSOAL}}'  => $msgBlock,
    '{{CONFIRM_URL}}'  => $confirmUrl,
    '{{DOWNLOAD_URL}}' => $downloadUrl,
    '{{QR_VALUE}}'     => $qrValue,
]);

// ---- Descarga: embutir tudo e transmitir (offline) -----------
if ($download) {
    $out = embutirRecursos($out, __DIR__);
    // Retira o botão flutuante de descarga do ficheiro guardado
    $out = preg_replace('#<a id="dlBtn".*?</a>\s*#s', '', $out, 1);
    $CAS = casalInfo($DEFS);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Convite-' . slugCasal($CAS['noiva'], $CAS['noivo']) . '.html"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// ---- Prova encolhida: sem os botões flutuantes ----------------
if ($modoProva) {
    $out = str_replace('</head>',
        "<style>#dlBtn,#audioBtn{display:none!important}</style></head>", $out);
}

// ---- Tela do editor: ponte com a janela de edição -------------
// O motor de arrasto entra na própria tela: quem manda no gesto é o documento
// onde o rato está, e a tela é um iframe. A janela de edição só recebe o
// resultado (ver pontelEditor()).
if ($modoEditor) {
    // A que altura a tela estava a ser lida quando o editor a mandou recompor.
    // Vem no próprio pedido para a reposição poder acontecer já na primeira
    // pintura — uma volta pelo postMessage chegaria tarde, e via-se o salto.
    $rolo = ['y'   => max(0, (int)($_POST['tela_y'] ?? 0)),
             'sec' => preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['tela_sec'] ?? '')),
             'dy'  => max(-20000, min(20000, (int)($_POST['tela_dy'] ?? 0)))];
    $out = str_replace('</body>',
        '<script src="assets/tela-livre.js"></script>'
      . '<script>window.EDITOR_ROLO=' . json_encode($rolo) . ';</script>'
      . pontelEditor() . '</body>', $out);
}

// ---- Visualização normal (recursos externos) -----------------
header('Content-Type: text/html; charset=utf-8');
echo $out;

/**
 * Script que corre DENTRO da tela. Faz três coisas: assinala o que está sob
 * o rato, avisa o editor de onde se clicou, e aceita ordens para reescrever
 * um texto ou destacar uma secção — é isto que torna a edição imediata.
 */
function pontelEditor(): string {
    return <<<'JS'
<style id="ed-marcas">
  /* Controlos destinados aos convidados não fazem parte da peça a editar. */
  #dlBtn, #audioBtn{ display:none !important; }
  /* A tela é para editar, não para assistir. As secções entram com um fade e
     um deslize de 34 pixéis, e a tela recompõe-se a cada retoque: o que se
     estava a olhar tornava a deslizar para o lugar a cada tecla. Quem recebe o
     convite continua a vê-las entrar. */
  .rv{ opacity:1 !important; transform:none !important; transition:none !important; }
  [data-def]{ outline:1px dashed transparent; outline-offset:3px; transition:outline-color .12s; cursor:text; }
  body.ed-marcar [data-def]:hover{ outline-color:rgba(217,188,140,.75); }
  [data-def].ed-sel{ outline:1.5px solid #D9BC8C !important; outline-offset:3px; }
  [data-sec].ed-sec-sel{ box-shadow:inset 0 0 0 2px rgba(217,188,140,.5); }
  body.ed-marcar [data-sec]:hover{ box-shadow:inset 0 0 0 1px rgba(217,188,140,.25); }
  /* Tela de posicionamento livre: o cursor é o que anuncia o que se agarra. */
  body.ed-livre [data-livre]{ cursor:grab; }
  body.ed-livre.ed-a-mover [data-livre]{ cursor:grabbing; }
  body.ed-livre [data-livre].ed-livre-sel{ outline:1.5px dashed rgba(217,188,140,.85); outline-offset:4px; }
  /* As guias acendem-se só quando o bloco está mesmo colado a uma linha. */
  .ed-guias{ position:absolute; inset:0; pointer-events:none; z-index:900; }
  .ed-guias::before, .ed-guias::after{ content:''; position:absolute; opacity:0; background:#D9BC8C;
    box-shadow:0 0 3px rgba(217,188,140,.8); transition:opacity .08s; }
  .ed-guias::before{ top:0; bottom:0; left:var(--gx,50%); width:1px; }
  .ed-guias::after{ left:0; right:0; top:var(--gy,50%); height:1px; }
  .ed-guias.v::before{ opacity:.95; }
  .ed-guias.h::after{ opacity:.95; }
</style>
<script>
(function(){
  var pai = window.parent; if (pai === window) return;
  document.body.classList.add('ed-marcar');

  // A tela mostra o conteúdo, não a capa: quem edita não quer voltar a abri-la a
  // cada recarga. Fica escondida mas FECHADA (sem .open), para que, quando a
  // camada "Envelope" for escolhida, ela apareça com o selo à mostra.
  // Os convidados continuam a recebê-la fechada e por abrir.
  var capa = document.getElementById('cover');
  if (capa){ capa.classList.remove('open'); document.body.classList.add('opened'); capa.style.display = 'none'; }

  // ---- Voltar ao ponto onde se estava a ler ----------------------
  // A tela recompõe-se de raiz a cada retoque (é o servidor que monta as
  // secções), e um documento novo começa no princípio. A janela de edição diz,
  // no pedido, a que altura estava — e volta-se lá antes de se ver o contrário.
  // Sem animação: o html tem scroll-behavior:smooth, e até um scrollTo direito
  // saía a descer devagar, que é justamente o que se quer deixar de ver.
  (function(){
    var onde = window.EDITOR_ROLO || {};
    if (!onde.y && !onde.sec) return;
    var raiz = document.documentElement, suave = raiz.style.scrollBehavior;
    raiz.style.scrollBehavior = 'auto';
    var mexeu = false;
    ['wheel','touchstart','keydown'].forEach(function(ev){
      window.addEventListener(ev, function(){ mexeu = true; }, {passive:true, once:true});
    });
    // O alvo recalcula-se de cada vez: a secção pode mudar de sítio enquanto a
    // página assenta, e é a ELA que se quer voltar, não a um pixel qualquer.
    function alvoAgora(){
      var s = onde.sec ? document.querySelector('[data-sec="' + onde.sec + '"]') : null;
      if (!s) return Math.max(0, onde.y || 0);
      return Math.max(0, Math.round(s.getBoundingClientRect().top + window.scrollY - (onde.dy || 0)));
    }
    function repor(){
      if (mexeu) return;
      var alvo = alvoAgora();
      if (Math.abs(window.scrollY - alvo) > 1) window.scrollTo(0, alvo);
    }
    repor();
    // A página ainda cresce enquanto as imagens e os tipos de letra chegam, e
    // uma página mais curta trava a rolagem a meio. Insiste-se até assentar —
    // e larga-se à primeira vez que alguém mexe.
    var fim = Date.now() + 1500;
    var t = setInterval(function(){
      repor();
      if (mexeu || Date.now() > fim){ clearInterval(t); raiz.style.scrollBehavior = suave; }
    }, 60);
    window.addEventListener('load', repor);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(repor);
  })();
  function mostrarCapa(sim){ if (capa) capa.style.display = sim ? '' : 'none'; }
  function envia(m){ pai.postMessage(Object.assign({fonte:'tela'}, m), '*'); }

  document.addEventListener('click', function(e){
    var noCapa = e.target.closest('#cover');
    var alvo = e.target.closest('[data-def]');
    var sec  = e.target.closest('[data-sec]');
    // Dentro da tela não se navega: os links são para os convidados.
    var link = e.target.closest('a'); if (link) e.preventDefault();
    envia({ tipo:'selecionar', def: alvo ? alvo.dataset.def : null, sec: sec ? sec.dataset.sec : null });
    // Clicar na capa é para a editar, não para a abrir: trava o openCover.
    if (noCapa) e.stopPropagation();
  }, true);

  // Depois de clicar no convite o teclado fica dentro da tela, e os atalhos do
  // editor deixavam de responder. Reencaminham-se para a janela de edição.
  document.addEventListener('keydown', function(e){
    var k = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && (k === 'z' || k === 'y' || k === 's')){
      e.preventDefault();
      envia({ tipo:'atalho', tecla:k, shift:e.shiftKey });
    }
  });

  /**
   * Ir a uma secção — e chegar lá.
   *
   * A rolagem suave calcula o destino no instante em que arranca, e a página
   * ainda está a crescer (fotografias, tipos de letra): o que se pediu ficava
   * uns trezentos pixéis acima do que se via. Depois de a animação assentar,
   * confere-se e corrige-se de uma vez — a não ser que a pessoa já tenha
   * mexido, que então o lugar é dela.
   */
  function irA(el, bloco){
    if (!el) return;
    el.scrollIntoView({block: bloco, behavior: 'smooth'});
    var mexeu = false, larga = function(){ mexeu = true; };
    ['wheel','touchstart','keydown'].forEach(function(ev){
      window.addEventListener(ev, larga, {passive:true, once:true}); });
    clearTimeout(irA._t);
    irA._t = setTimeout(function(){
      ['wheel','touchstart','keydown'].forEach(function(ev){
        window.removeEventListener(ev, larga); });
      if (mexeu) return;
      var r = el.getBoundingClientRect();
      var falta = bloco === 'center' ? r.top - (window.innerHeight - r.height) / 2 : r.top;
      if (Math.abs(falta) <= 4) return;
      var raiz = document.documentElement, suave = raiz.style.scrollBehavior;
      raiz.style.scrollBehavior = 'auto';
      window.scrollBy(0, falta);
      raiz.style.scrollBehavior = suave;
    }, 900);
  }

  window.addEventListener('message', function(e){
    var d = e.data || {}; if (d.fonte !== 'editor') return;
    if (d.tipo === 'texto'){
      // Todas as ocorrências: os nomes dos noivos aparecem na capa e no convite.
      document.querySelectorAll('[data-def="' + d.def + '"]').forEach(function(el){ el.innerHTML = d.html; });
    }
    if (d.tipo === 'marcar'){
      document.querySelectorAll('.ed-sel').forEach(function(x){ x.classList.remove('ed-sel'); });
      document.querySelectorAll('.ed-sec-sel').forEach(function(x){ x.classList.remove('ed-sec-sel'); });
      // Assinalar e IR VER são duas coisas: quem marca diz qual quer. Marcar a
      // secção a cada recomposição da tela — que é a cada retoque — punha o
      // convite a subir e a descer sozinho enquanto se escrevia.
      var rolar = d.rolar === true;
      if (d.def){ document.querySelectorAll('[data-def="' + d.def + '"]').forEach(function(x){ x.classList.add('ed-sel'); });
                  var a = document.querySelector('[data-def="' + d.def + '"]');
                  if (a && rolar) irA(a, 'center'); }
      if (d.sec){ var s = document.querySelector('[data-sec="' + d.sec + '"]');
                  if (s){ s.classList.add('ed-sec-sel');
                          if (rolar && !d.def) irA(s, 'start'); } }
    }
    if (d.tipo === 'irPara'){
      irA(document.querySelector('[data-sec="' + d.sec + '"]'), 'start');
    }
    // Mostrar ou esconder a capa que abre, conforme a camada escolhida.
    if (d.tipo === 'capa'){ mostrarCapa(!!d.mostrar); }
    // Pré-ver uma abertura: troca o modo, mostra a capa e joga a animação uma
    // vez — depois volta a fechar-se, pronta para a próxima escolha.
    if (d.tipo === 'capa_previa' && capa){
      capa.setAttribute('data-abre', d.abre || 'portas');
      mostrarCapa(true);
      capa.classList.remove('open');
      void capa.offsetWidth;                 // reinicia a transição
      requestAnimationFrame(function(){ capa.classList.add('open'); });
      clearTimeout(capa._reseal);
      capa._reseal = setTimeout(function(){ capa.classList.remove('open'); }, 2200);
    }
    // Trocar o feitio do selo: muda na hora, sem recarregar nem abrir.
    if (d.tipo === 'capa_selo' && capa){
      capa.setAttribute('data-selo', d.selo || 'cera');
      mostrarCapa(true);
    }
    // Cores e enquadramento entram como variáveis CSS: mudam a peça sem a
    // voltar a pedir ao servidor.
    if (d.tipo === 'tema' || d.tipo === 'foco'){
      var r = document.documentElement;
      Object.keys(d.vars||{}).forEach(function(k){ r.style.setProperty('--'+k, d.vars[k]); });
    }
    // Posicionamento livre: o editor manda o que se pode mover e onde está.
    if (d.tipo === 'livres'){ montarLivres(d.mapa||{}, d.pos||{}); }
    if (d.tipo === 'pos'){ pintarPos(d.id, d.x, d.y, d.a); }
    if (d.tipo === 'livre-sel'){
      marcarLivre(d.id);
      irA(elDe(d.id), 'center');
    }
  });

  // ---- Posicionamento livre, dentro da tela --------------------
  // O arrasto tem de correr AQUI: o rato está neste documento, e a janela de
  // edição só veria coordenadas que não sabe traduzir. Daqui sai apenas o
  // resultado — que bloco, e para onde.
  var LIVRES = {}, TELAS = [];

  function elDe(id){ return LIVRES[id] ? LIVRES[id].el : null; }
  function pintarPos(id, x, y, a){
    var el = elDe(id); if (!el) return;
    a = a || 0;
    var origem = Math.abs(x) < 0.005 && Math.abs(y) < 0.005 && Math.abs(a) < 0.05;
    el.style.setProperty('--px', String(x));
    el.style.setProperty('--py', String(y));
    el.style.setProperty('--pa', String(a));
    // Na origem tiram-se: ver o comentário em cssPosicoes().
    el.style.setProperty('--mv', origem ? '' : 'calc('+x+'*var(--uw,1vw)) calc('+y+'*var(--uh,1vh))');
    el.style.setProperty('--rt', origem ? '' : a + 'deg');
    if (LIVRES[id]) LIVRES[id].pos = { x:x, y:y, a:a };
  }
  function marcarLivre(id){
    Object.keys(LIVRES).forEach(function(k){
      if (LIVRES[k].el) LIVRES[k].el.classList.toggle('ed-livre-sel', k === id);
    });
  }

  function guiasDe(tela){
    var g = tela.querySelector(':scope > .ed-guias');
    if (!g){
      g = document.createElement('span'); g.className = 'ed-guias';
      // A tela precisa de ser o contexto de posicionamento das guias; o
      // envelope e a moldura da entrada já são relativos ou fixos.
      if (getComputedStyle(tela).position === 'static') tela.style.position = 'relative';
      tela.appendChild(g);
    }
    return g;
  }

  /**
   * Recebe o mapa de blocos móveis (id -> selector) e liga o arrasto em cada
   * tela de tamanho conhecido. Os blocos são marcados com data-livre, que é o
   * que o CSS usa para os deslocar ao vivo.
   */
  function montarLivres(mapa, pos){
    TELAS.forEach(function(t){ t.desligar(); }); TELAS = [];
    LIVRES = {};
    document.body.classList.add('ed-livre');
    var porTela = {};
    Object.keys(mapa).forEach(function(id){
      var el = document.querySelector(mapa[id].sel);
      if (!el) return;
      el.dataset.livre = id;
      var p = pos[id] || { x:0, y:0, a:0 };
      LIVRES[id] = { el:el, pos:p, tela:mapa[id].tela };
      (porTela[mapa[id].tela] = porTela[mapa[id].tela] || []).push(id);
    });
    Object.keys(porTela).forEach(function(selTela){
      var tela = document.querySelector(selTela); if (!tela) return;
      var ids = porTela[selTela];
      TELAS.push(window.TelaLivre.ligar({
        tela: tela,
        guias: guiasDe(tela),
        blocos: function(){ return ids.map(function(id){ return { id:id, el:elDe(id) }; }); },
        pos: function(id){ return (LIVRES[id]||{}).pos || {x:0,y:0,a:0}; },
        trancado: function(){ return false; },
        pegar: function(id){ document.body.classList.add('ed-a-mover'); marcarLivre(id);
                             envia({ tipo:'pegou', id:id }); },
        mover: function(id, x, y, a){ pintarPos(id, x, y, a); },
        largar: function(id, x, y, a){ document.body.classList.remove('ed-a-mover');
                                       pintarPos(id, x, y, a);
                                       envia({ tipo:'moveu', id:id, x:x, y:y, a:a||0 }); }
      }));
    });
  }

  envia({ tipo:'pronta' });
})();
</script>
JS;
}


// ============================================================
// Converte as referências a recursos externos em dados embutidos
// (base64), para o ficheiro poder ser visto completamente offline.
// ============================================================
function embutirRecursos(string $html, string $base): string {
    $mime = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','mp4'=>'audio/mp4','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','woff2'=>'font/woff2'];

    $paraDataUri = function (string $rel) use ($base, $mime): ?string {
        $rel = ltrim($rel, '/');
        $abs = $base . '/' . $rel;
        if (!is_readable($abs)) return null;
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $tp  = $mime[$ext] ?? 'application/octet-stream';
        return 'data:' . $tp . ';base64,' . base64_encode(file_get_contents($abs));
    };

    // 1) Imagens e áudio:  src="assets/convite/...."
    $html = preg_replace_callback(
        '#src="(assets/convite/[^"]+\.(?:jpg|jpeg|png|webp|mp3|m4a|mp4))"#i',
        function ($m) use ($paraDataUri) {
            $d = $paraDataUri($m[1]);
            return $d ? 'src="' . $d . '"' : $m[0];
        }, $html);

    // 2) Tipos de letra:  url(assets/convite/fonts/....woff2)
    $html = preg_replace_callback(
        '#url\((assets/convite/fonts/[^)]+\.woff2)\)#i',
        function ($m) use ($paraDataUri) {
            $d = $paraDataUri($m[1]);
            return $d ? 'url(' . $d . ')' : $m[0];
        }, $html);

    // 3) QRious:  <script src="assets/qrious.min.js"></script> -> inline
    $qr = $base . '/assets/qrious.min.js';
    if (is_readable($qr)) {
        $js = file_get_contents($qr);
        $html = preg_replace(
            '#<script src="assets/qrious\.min\.js"></script>#',
            '<script>' . $js . '</script>',
            $html, 1);
    }
    return $html;
}
