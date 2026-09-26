<?php
// Sem sessão, códigos de convite ou consultas a casamentos: lê apenas a peça
// de origem global que o administrador publicou para a casa.
require_once __DIR__.'/db.php';
require_once __DIR__.'/parcial-demonstracao.php';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
$d=demonstracaoDefsComModelo($conn);
$tpl=file_get_contents(__DIR__.'/assets/convite-base.html');
// O iframe tem origem opaca. Incorporar apenas as fontes locais do modelo
// mantém a tipografia real sem abrir permissões de acesso à página principal.
$tpl=preg_replace_callback('~url\((assets/convite/fonts/[a-z0-9-]+\.woff2)\)~',
    fn($m)=>'url(data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/'.$m[1])).')',$tpl);
$tpl=ordenarBlocos($tpl,$d,['{noiva}'=>'Lia','{noivo}'=>'Daniel']);
$out=strtr(aplicarSeccoes($tpl,$d),convitePlaceholders($d)+[
 '{{GUEST_NAME}}'=>'Família Mendes','{{MESA_BLOCK}}'=>'<p class="guest-mesa">Mesa Acácia · 2 lugares</p>',
 '{{MSG_PESSOAL}}'=>'','{{CONFIRM_URL}}'=>'#demo-rsvp','{{DOWNLOAD_URL}}'=>'#demo-rsvp','{{QR_VALUE}}'=>'DEMONSTRACAO-SEM-VALIDADE'
]);
// Links do modelo não devem sair da experiência fictícia.
$out=preg_replace('/<a\b([^>]*?)href="[^"]*"([^>]*)>/i','<a $1href="#demo-rsvp"$2>',$out);
$out=str_replace('target="_blank"','',$out);
$out=str_replace('</head>','<link rel="stylesheet" href="'.escP(asset('assets/demonstracao-convite.css')).'"></head>',$out);
// A confirmação de exemplo ocupa o lugar da acção do próprio convite.
$out=preg_replace('~<a\s+class="btn btn-gold rv d3" href="#demo-rsvp"[^>]*>.*?</a>~s',
    '<div id="demo-rsvp"><p>Família Mendes · 2 pessoas</p><button id="demo-sim">Confirmar presença</button><p id="demo-resposta" role="status"></p></div>',$out);
$out=str_replace('</body>','<script>document.getElementById("demo-sim").addEventListener("click",function(){this.disabled=true;this.textContent="Presença confirmada";document.getElementById("demo-resposta").textContent="Obrigado! Guardámos o vosso sim. Até ao grande dia!";});</script></body>',$out);
echo $out;
