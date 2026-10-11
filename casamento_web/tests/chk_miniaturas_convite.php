<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../personalizacao.php';
require_once __DIR__.'/../convite-miniatura.php';
$defs=array_replace(defsPadrao(),exemploDeFabrica(),['casal.noiva'=>'Clara','casal.noivo'=>'Miguel']);
$pacotes=convitePacotesRegistados()['digital'];
foreach($pacotes as $chave=>$versoes){
    if($chave===CONVITE_PACOTE_PORCELANA_LEGADO)continue;
    $p=$versoes['1.0.0'];$template=file_get_contents($p['template']);
    $html=conviteMiniaturaEnvelope($template,$defs,'Família Teste');
    foreach(['<script','<audio','<iframe','<main','{{'] as $proibido){
        if(str_contains($html,$proibido))throw new RuntimeException($chave.': a miniatura contém '.$proibido);
    }
    if(!str_contains($html,'data-miniatura="envelope"')||!str_contains($html,'Clara')||!str_contains($html,'Miguel'))
        throw new RuntimeException($chave.': envelope ou identidade ausente');
    if(strlen($html)>=strlen($template))throw new RuntimeException($chave.': o convite completo foi entregue');
    echo 'PASS: '.$chave.' · '.strlen($html).' / '.strlen($template)." bytes\n";
}
