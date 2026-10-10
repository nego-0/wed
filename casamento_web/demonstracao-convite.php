<?php
// Sem sessão, códigos de convite ou consultas a casamentos: lê apenas a peça
// de origem global que o administrador publicou para a casa.
require_once __DIR__.'/db.php';
require_once __DIR__.'/parcial-demonstracao.php';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
$d=demonstracaoDefsComModelo($conn);
$tpl=file_get_contents(__DIR__.'/assets/convite-base.html');
$tpl=ordenarBlocos($tpl,$d,['{noiva}'=>(string)$d['casal.noiva'],'{noivo}'=>(string)$d['casal.noivo']]);
$out=strtr(aplicarSeccoes($tpl,$d),convitePlaceholders($d)+[
 '{{GUEST_NAME}}'=>'Família Mendes','{{MESA_BLOCK}}'=>'<p class="guest-mesa">Mesa Acácia · 2 lugares</p>',
 '{{MSG_PESSOAL}}'=>'','{{CONFIRM_URL}}'=>'#demo-rsvp','{{DOWNLOAD_URL}}'=>'#demo-rsvp','{{QR_VALUE}}'=>'DEMONSTRACAO-SEM-VALIDADE'
]);
// Inclui também as fontes escolhidas no editor, inseridas por TEMA_VARS.
// Só depois de renderizar é que todas as faces estão presentes no documento.
$out=preg_replace_callback('~url\((assets/convite/fonts/[a-z0-9-]+\.woff2)\)~',
    fn($m)=>'url(data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/'.$m[1])).')',$out);
// Links do modelo não devem sair da experiência fictícia.
$out=preg_replace('/<a\b([^>]*?)href="[^"]*"([^>]*)>/i','<a $1href="#demo-rsvp"$2>',$out);
$out=str_replace('target="_blank"','',$out);
$out=str_replace('</head>','<link rel="stylesheet" href="'.escP(asset('assets/demonstracao-convite.css')).'"></head>',$out);
// A demonstração usa o mesmo modal e o mesmo ponto de montagem do convite
// publicado. Só o conteúdo é local e fictício, para nunca escrever numa festa.
$demoRsvp='<div id="demo-rsvp" hidden><p>Família Mendes · 2 pessoas</p>'
    .'<button id="demo-sim" type="button">Confirmar presença</button>'
    .'<p id="demo-resposta" role="status"></p></div>';
$demoJs='<script>document.addEventListener("click",function(e){if(!e.target.matches("#demo-sim"))return;'
    .'e.target.disabled=true;e.target.textContent="Presença confirmada";'
    .'document.getElementById("demo-resposta").textContent="Obrigado! Guardámos o vosso sim. Até ao grande dia!";});</script>';
$out=str_replace('</body>',$demoRsvp.$demoJs.'</body>',$out);
echo $out;
