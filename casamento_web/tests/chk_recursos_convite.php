<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../personalizacao.php';
function falha(string $m): never { fwrite(STDERR, "FALHOU: $m\n"); exit(1); }
$d = defsPadrao();
foreach (['geral.rolagem_auto','geral.rolagem_velocidade','geral.icone_musica_tocar','geral.icone_musica_pausa'] as $k)
    if (!array_key_exists($k, $d)) falha("origem ausente: $k");
$icones = iconesConvite();
foreach (['presente','qr','transferencia','cartao','dinheiro','banco','pagamento-movel','pausa','tocar'] as $i)
    if (!isset($icones[$i])) falha("ícone global ausente: $i");
if (validarDefinicao('geral.rolagem_velocidade', '2') !== '8') falha('limite mínimo da velocidade');
if (validarDefinicao('geral.rolagem_velocidade', '999') !== '120') falha('limite máximo da velocidade');
if (validarDefinicao('geral.icone_musica_pausa', 'inexistente') !== null) falha('ícone desconhecido aceite');
$ph = convitePlaceholders($d);
foreach (['{{ROLAGEM_AUTO}}','{{ROLAGEM_VELOCIDADE}}','{{ICONE_MUSICA_PARADA}}','{{ICONE_MUSICA_TOCANDO}}'] as $k)
    if (!isset($ph[$k]) || $ph[$k] === '') falha("marcador não composto: $k");
$html = strtr(file_get_contents(__DIR__.'/../assets/convite-base.html'), $ph);
if (str_contains($html, '{{ROLAGEM_')) falha('marcador de rolagem ficou no HTML');
if (!str_contains($html, 'iniciarRolagemAutomatica')) falha('motor de rolagem ausente');
echo "OK — ícones globais, música e rolagem automática.\n";
