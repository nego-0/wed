<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/personalizacao.php';
require_once __DIR__.'/parcial-cabecalho.php';
exigirAdmin();
$DEFS=defsAtuais($conn); $CAS=casalDaFicha($conn); $mods=licencaModulos($conn);
$permitidos=[]; foreach($mods as $k=>$v) if(!empty($v['ativo']))$permitidos[$k]=1;
$itens=[]; $r=$conn->query("SELECT modulo,titulo,resumo,conteudo,media FROM {$P}atendimento_conteudos WHERE tipo='ajuda' AND ativo=1 ORDER BY ordem,id");
if($r) while($x=$r->fetch_assoc()) if(ehAdminPlataforma()||isset($permitidos[$x['modulo']])) {
    $auto='assets/ajuda/'.$x['modulo'].'.webm';
    if(!preg_match('~^assets/ajuda/guia-~',(string)$x['media']) && is_file(__DIR__.'/'.$auto)) $x['media']=$auto;
    $itens[]=$x;
}
$rot=['convidados'=>'Convidados','mesas'=>'Mesas','impresso'=>'Convite impresso','digital'=>'Convite digital','porta'=>'Porta','bar'=>'Bar','orcamento'=>'Orçamento'];
$ico=['convidados'=>'pessoas','mesas'=>'mesa','impresso'=>'carta','digital'=>'telemovel','porta'=>'porta','bar'=>'taca','orcamento'=>'moeda'];
?><!doctype html><html lang="pt"><head><?php include __DIR__.'/parcial-icone.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ajuda · <?= escP($CAS['casal']) ?></title>
<link rel="stylesheet" href="<?= asset('assets/fontes.css') ?>"><link rel="stylesheet" href="<?= asset('assets/estilo.css') ?>"><link rel="stylesheet" href="<?= asset('assets/ajuda.css') ?>"></head>
<body><?php cabecalho('Ajuda','Guias claros para usar os módulos do vosso casamento.','ajuda'); ?>
<main id="conteudo" class="aj-wrap">
  <section class="aj-hero"><div><span class="aj-kicker">Central de ajuda Kulemba</span><h2>Encontre a operação e veja-a acontecer.</h2><p>Os guias abaixo pertencem aos módulos incluídos na vossa licença. Pesquise uma tarefa ou escolha o módulo.</p></div><div class="aj-pesquisa"><label for="aj-busca">O que pretende fazer?</label><span data-ico="procurar" aria-hidden="true"></span><input id="aj-busca" type="search" placeholder="Ex.: mudar um convidado de mesa" autocomplete="off"><kbd>/</kbd></div></section>
  <?php if(!$itens): ?>
  <div class="aj-vazio"><h2>A ajuda aparecerá com os vossos módulos</h2><p>Quando a licença for atribuída, esta página mostrará apenas os guias correspondentes.</p><a class="btn" href="licenca.php">Ver licença</a></div>
  <?php else: ?>
  <nav class="aj-submenu" aria-label="Módulos da ajuda"><button class="ativo" type="button" data-aj-modulo="todos"><span data-ico="brilho"></span>Todos</button><?php foreach($itens as $x): ?><button type="button" data-aj-modulo="<?=escP($x['modulo'])?>"><span data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><?=escP($rot[$x['modulo']]??$x['modulo'])?></button><?php endforeach ?></nav>
  <p class="aj-resultado" id="aj-resultado" role="status"></p>
  <div class="aj-grid" id="aj-grid"><?php foreach($itens as $n=>$x):
    $ext=strtolower(pathinfo(parse_url((string)$x['media'],PHP_URL_PATH)??'',PATHINFO_EXTENSION));
    $posterNovo='assets/ajuda/'.$x['modulo'].'-poster.png';
    $poster=is_file(__DIR__.'/'.$posterNovo)?$posterNovo:'assets/ajuda/'.$x['modulo'].'.gif'; ?>
    <article class="aj-card" id="<?=escP($x['modulo'])?>" data-modulo="<?=escP($x['modulo'])?>" data-pesquisa="<?=escP(mb_strtolower(($rot[$x['modulo']]??$x['modulo']).' '.$x['titulo'].' '.$x['resumo'].' '.$x['conteudo'],'UTF-8'))?>">
      <div class="aj-media"><?php if(in_array($ext,['webm','mp4'],true)): ?><video src="<?=escP(asset($x['media']))?>" poster="<?=escP(asset($poster))?>" muted loop playsinline preload="metadata" aria-label="Demonstração: <?=escP($x['titulo'])?>"></video><button type="button" class="aj-reproduzir" aria-label="Reproduzir demonstração"><span data-ico="direita"></span></button><?php elseif($x['media']): ?><img src="<?=escP(asset($x['media']))?>" alt="Demonstração animada: <?=escP($x['titulo'])?>" loading="lazy"><?php endif ?><span class="aj-media-etiqueta">Interface real</span></div>
      <div class="aj-corpo"><span class="aj-mod"><i data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></i><?=escP($rot[$x['modulo']]??$x['modulo'])?></span><h2><?=escP($x['titulo'])?></h2><p class="aj-resumo"><?=escP($x['resumo'])?></p><details <?= $n===0?'open':'' ?>><summary>Ver operações e passos</summary><div class="aj-passos"><?=escP($x['conteudo'])?></div></details></div>
    </article>
  <?php endforeach ?></div>
  <div class="aj-sem-resultados" id="aj-sem-resultados" hidden><span data-ico="procurar"></span><h2>Não encontrámos esse passo</h2><p>Tente outra palavra ou escolha um módulo acima.</p></div>
  <?php endif ?>
</main>
<?php include __DIR__.'/parcial-seletor-tema.php'; ?><script src="<?=asset('assets/ajuda.js')?>"></script></body></html>
