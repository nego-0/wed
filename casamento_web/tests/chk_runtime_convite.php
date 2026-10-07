<?php
function rtOk(bool $ok, string $mensagem): void {
    if (!$ok) { fwrite(STDERR, "FALHOU: $mensagem\n"); exit(1); }
    echo "PASS: $mensagem\n";
}
$raiz = dirname(__DIR__);
$modelo = file_get_contents($raiz.'/assets/convite-base.html');
$runtime = file_get_contents($raiz.'/assets/convite-runtime.js');
$css = file_get_contents($raiz.'/assets/convite-runtime.css');
$pagina = file_get_contents($raiz.'/convite-digital.php');
$rsvp = file_get_contents($raiz.'/convite.php');
$demo = file_get_contents($raiz.'/demonstracao-convite.php');

rtOk(str_contains($modelo, '{{RUNTIME_CONFIG}}')
    && str_contains($modelo, 'assets/convite-runtime.js')
    && str_contains($modelo, 'assets/convite-runtime.css'),
    'o modelo recebe dados e carrega um runtime externo comum');
foreach (['capa','audio','calendario','mapa','confirmacao'] as $ponto) {
    rtOk(str_contains($modelo, 'data-kulemba="'.$ponto.'"'), 'existe o ponto de montagem '.$ponto);
}
rtOk(str_contains($modelo, 'data-kulemba-qr="{{QR_VALUE}}"'), 'o QR de entrada é um ponto de montagem');
rtOk(!str_contains($modelo, 'function iniciarRolagemAutomatica')
    && !str_contains($modelo, 'new QRious({element:document.getElementById("qrCanvas")'),
    'rolagem e QR deixaram de ter uma cópia dentro do modelo');
foreach (['calendario-descarregar','mapa-abrir','qr-gerado','galeria-abrir','confirmacao-abrir','audio-tocar'] as $evento) {
    rtOk(str_contains($runtime, $evento), 'o runtime publica o evento '.$evento);
}
rtOk(str_contains($runtime, "location.hash==='#confirmar'")
    && str_contains($runtime, 'aria-modal="true"')
    && str_contains($runtime, "e.key!=='Tab'"),
    'a confirmação abre por endereço directo e prende o foco no modal');
rtOk(str_contains($css, 'height:100dvh') && str_contains($css, 'env(safe-area-inset-bottom)'),
    'o modal respeita o viewport e as áreas seguras do telemóvel');
rtOk(str_contains($pagina, "'/assets/convite-runtime.css'")
    && str_contains($pagina, "'/assets/convite-runtime.js'"),
    'a exportação incorpora o mesmo CSS e JavaScript');
rtOk(str_contains($rsvp, 'class="modo-modal"')
    && str_contains($rsvp, 'kulemba:rsvp-concluido'),
    'a confirmação conserva o formulário real dentro do modal e comunica o resultado');
rtOk(str_contains($demo, 'id="demo-rsvp" hidden')
    && !str_contains($demo, 'preg_replace(\'~<a\\s+class="btn btn-gold'),
    'a demonstração usa o modal comum com dados fictícios');

