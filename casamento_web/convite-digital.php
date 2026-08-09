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
    if (!ehAdmin()) { http_response_code(403); exit('Apenas administração.'); }
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
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $CAS = casalInfo($DEFS);
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Convite · ' . escP($CAS['casal']) . '</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'font-family:Georgia,serif;background:#16261E;color:#FBF8F1;text-align:center;padding:2rem}a{color:#D9BC8C}</style>'
       . '</head><body><div><p style="font-size:1.6rem;color:#D9BC8C">' . escP($CAS['casal']) . '</p>'
       . '<p>Este convite não foi encontrado. Confirme o endereço, por favor, ou fale com os noivos.</p>'
       . '<p><a href="https://wa.me/' . escP($DEFS['evento.whatsapp']) . '">Falar pelo WhatsApp</a></p></div></body></html>';
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

$confirmUrl  = escP(base_url() . '/convite.php?c=' . $c['codigo']);
$downloadUrl = escP('convite-digital.php?c=' . $c['codigo'] . '&download=1');
$qrValue     = base_url() . '/convite-digital.php?c=' . $c['codigo'];

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
if ($modoEditor) $out = str_replace('</body>', pontelEditor() . '</body>', $out);

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
  [data-def]{ outline:1px dashed transparent; outline-offset:3px; transition:outline-color .12s; cursor:text; }
  body.ed-marcar [data-def]:hover{ outline-color:rgba(217,188,140,.75); }
  [data-def].ed-sel{ outline:1.5px solid #D9BC8C !important; outline-offset:3px; }
  [data-sec].ed-sec-sel{ box-shadow:inset 0 0 0 2px rgba(217,188,140,.5); }
  body.ed-marcar [data-sec]:hover{ box-shadow:inset 0 0 0 1px rgba(217,188,140,.25); }
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

  window.addEventListener('message', function(e){
    var d = e.data || {}; if (d.fonte !== 'editor') return;
    if (d.tipo === 'texto'){
      // Todas as ocorrências: os nomes dos noivos aparecem na capa e no convite.
      document.querySelectorAll('[data-def="' + d.def + '"]').forEach(function(el){ el.innerHTML = d.html; });
    }
    if (d.tipo === 'marcar'){
      document.querySelectorAll('.ed-sel').forEach(function(x){ x.classList.remove('ed-sel'); });
      document.querySelectorAll('.ed-sec-sel').forEach(function(x){ x.classList.remove('ed-sec-sel'); });
      if (d.def){ document.querySelectorAll('[data-def="' + d.def + '"]').forEach(function(x){ x.classList.add('ed-sel'); });
                  var a = document.querySelector('[data-def="' + d.def + '"]');
                  if (a) a.scrollIntoView({block:'center', behavior:'smooth'}); }
      if (d.sec){ var s = document.querySelector('[data-sec="' + d.sec + '"]');
                  if (s){ s.classList.add('ed-sec-sel'); if(!d.def) s.scrollIntoView({block:'start', behavior:'smooth'}); } }
    }
    if (d.tipo === 'irPara'){
      var s2 = document.querySelector('[data-sec="' + d.sec + '"]');
      if (s2) s2.scrollIntoView({block:'start', behavior:'smooth'});
    }
    // Mostrar ou esconder a capa que abre, conforme a camada escolhida.
    if (d.tipo === 'capa'){ mostrarCapa(!!d.mostrar); }
    // Cores e enquadramento entram como variáveis CSS: mudam a peça sem a
    // voltar a pedir ao servidor.
    if (d.tipo === 'tema' || d.tipo === 'foco'){
      var r = document.documentElement;
      Object.keys(d.vars||{}).forEach(function(k){ r.style.setProperty('--'+k, d.vars[k]); });
    }
  });

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
