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

$codigo   = strtoupper(trim($_GET['c'] ?? ''));
$c        = $codigo !== '' ? carregarConvite($conn, $codigo, 'codigo') : null;
$download = isset($_GET['download']) && $_GET['download'] === '1';

// ---- Convite inválido: página breve e autossuficiente --------
if (!$c) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Convite · Isabel &amp; Abednego</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'font-family:Georgia,serif;background:#16261E;color:#FBF8F1;text-align:center;padding:2rem}a{color:#D9BC8C}</style>'
       . '</head><body><div><p style="font-size:1.6rem;color:#D9BC8C">Isabel &amp; Abednego</p>'
       . '<p>Este convite não foi encontrado. Confirme o endereço, por favor, ou fale com os noivos.</p>'
       . '<p><a href="https://wa.me/' . htmlspecialchars(EVENTO['whatsapp']) . '">Falar pelo WhatsApp</a></p></div></body></html>';
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
$nome = htmlspecialchars(nomeConviteVisivel($c), ENT_QUOTES, 'UTF-8');

$mesaBlock = '';
$distrMesas = mesasDoConvite($conn, $c);
if ($distrMesas) {
    // Opção do convite digital: mostrar (ou não) o "(N pessoas)" ao lado de cada mesa.
    $comNumMesa = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
    $txtMesas = htmlspecialchars(textoMesas($distrMesas, $comNumMesa), ENT_QUOTES, 'UTF-8');
    $rotuloMesa = count($distrMesas) > 1 ? 'Mesas' : 'Mesa';
    $mesaBlock = "<p class=\"guest-mesa\" style=\"margin-top:12px;font-family:'Cormorant Garamond',serif;"
        . "font-size:17px;letter-spacing:.02em;color:#B4864A\">{$rotuloMesa}: "
        . "<b style=\"font-weight:600;color:#2C4536\">{$txtMesas}</b></p>";
}

$confirmUrl  = htmlspecialchars(base_url() . '/convite.php?c=' . $c['codigo'], ENT_QUOTES, 'UTF-8');
$downloadUrl = htmlspecialchars('convite-digital.php?c=' . $c['codigo'] . '&download=1', ENT_QUOTES, 'UTF-8');
$qrValue     = base_url() . '/convite-digital.php?c=' . $c['codigo'];

// A nota dos parênteses só aparece quando o número é mesmo mostrado no convite
$guestNote = mostraNumeroConvite($c)
    ? '<p class="guest-note">O número entre parênteses corresponde ao número de pessoas para as quais o convite é destinado.</p>'
    : '';

$out = strtr($tpl, [
    '{{GUEST_NAME}}'   => $nome,
    '{{MESA_BLOCK}}'   => $mesaBlock,
    '{{GUEST_NOTE}}'   => $guestNote,
    '{{CONFIRM_URL}}'  => $confirmUrl,
    '{{DOWNLOAD_URL}}' => $downloadUrl,
    '{{QR_VALUE}}'     => $qrValue,
]);

// ---- Descarga: embutir tudo e transmitir (offline) -----------
if ($download) {
    $out = embutirRecursos($out, __DIR__);
    // Retira o botão flutuante de descarga do ficheiro guardado
    $out = preg_replace('#<a id="dlBtn".*?</a>\s*#s', '', $out, 1);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Convite-Isabel-Abednego.html"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// ---- Visualização normal (recursos externos) -----------------
header('Content-Type: text/html; charset=utf-8');
echo $out;


// ============================================================
// Converte as referências a recursos externos em dados embutidos
// (base64), para o ficheiro poder ser visto completamente offline.
// ============================================================
function embutirRecursos(string $html, string $base): string {
    $mime = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','mp4'=>'audio/mp4','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','woff2'=>'font/woff2'];

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
        '#src="(assets/convite/[^"]+\.(?:jpg|jpeg|png|mp3|m4a|mp4))"#i',
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
