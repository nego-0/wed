<?php
// Sem sessão, códigos de convite, modelos privados ou consultas a casamentos.
require_once __DIR__.'/parcial-demonstracao.php';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
$d=demonstracaoDefs();
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
$out=str_replace('</head>','<style>#dlBtn,#audioBtn{display:none!important}#demo-rsvp{padding:36px 24px;background:#fbf8f1;color:#20342a;text-align:center}#demo-rsvp button{margin:12px;padding:12px 20px;background:#2c4536;color:white;border:0;border-radius:24px;cursor:pointer}</style></head>',$out);
$out=str_replace('</body>','<section id="demo-rsvp"><h2>Contamos consigo?</h2><p>Família Mendes · 2 pessoas</p><button id="demo-sim">Confirmar presença</button><p id="demo-resposta" role="status">Resposta de demonstração: nada é enviado.</p></section><script>document.getElementById("demo-sim").addEventListener("click",function(){this.disabled=true;this.textContent="Presença confirmada";document.getElementById("demo-resposta").textContent="Obrigado! A confirmação de exemplo foi registada apenas nesta vista.";});</script></body>',$out);
echo $out;
