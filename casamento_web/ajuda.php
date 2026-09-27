<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/personalizacao.php';
require_once __DIR__.'/parcial-cabecalho.php';
exigirAdmin();
$DEFS=defsAtuais($conn); $CAS=casalDaFicha($conn); $mods=licencaModulos($conn);
$permitidos=[]; foreach($mods as $k=>$v) if(!empty($v['ativo']))$permitidos[$k]=1;
$itens=[]; $r=$conn->query("SELECT modulo,titulo,resumo,conteudo FROM {$P}atendimento_conteudos WHERE tipo='ajuda' AND ativo=1 ORDER BY ordem,id");
if($r) while($x=$r->fetch_assoc()) if(ehAdminPlataforma()||isset($permitidos[$x['modulo']])) $itens[]=$x;
$rot=['convidados'=>'Convidados','mesas'=>'Mesas','impresso'=>'Convite impresso','digital'=>'Convite digital','porta'=>'Porta','bar'=>'Bar','orcamento'=>'Orçamento'];
$ico=['convidados'=>'pessoas','mesas'=>'mesa','impresso'=>'carta','digital'=>'telemovel','porta'=>'porta','bar'=>'taca','orcamento'=>'moeda'];
function passosDoSticker(string $texto): array {
    $operacao='Como fazer'; $passos=[];
    foreach(preg_split('/\R/u',$texto) as $linha){
        $linha=trim($linha); if($linha==='')continue;
        if(preg_match('/^\d+[.)]\s*(.+)$/u',$linha,$m)){
            $passos[]=['operacao'=>$operacao,'texto'=>$m[1]];
        } else $operacao=mb_convert_case($linha,MB_CASE_TITLE,'UTF-8');
    }
    if(!$passos) foreach(array_filter(array_map('trim',preg_split('/[.;]\s+/u',$texto))) as $linha)
        $passos[]=['operacao'=>'Como fazer','texto'=>$linha];
    return array_slice($passos,0,8);
}
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
  <div class="aj-grid" id="aj-grid"><?php foreach($itens as $n=>$x): $passos=passosDoSticker($x['conteudo']); ?>
    <article class="aj-card" id="<?=escP($x['modulo'])?>" data-modulo="<?=escP($x['modulo'])?>" data-pesquisa="<?=escP(mb_strtolower(($rot[$x['modulo']]??$x['modulo']).' '.$x['titulo'].' '.$x['resumo'].' '.$x['conteudo'],'UTF-8'))?>">
      <div class="aj-sticker" data-aj-sticker data-modulo="<?=escP($x['modulo'])?>" aria-label="Passos animados de <?=escP($x['titulo'])?>">
        <div class="aj-sticker-topo"><span class="aj-sticker-icone" data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><div><small><?=escP($rot[$x['modulo']]??$x['modulo'])?></small><b><?=escP($passos[0]['operacao']??'Como fazer')?></b></div><i class="aj-sticker-pulso"></i></div>
        <ol><?php foreach($passos as $i=>$passo): ?><li<?= $i===0?' class="ativo"':'' ?> data-operacao="<?=escP($passo['operacao'])?>"><span><?= $i+1 ?></span><p><?=escP($passo['texto'])?></p></li><?php endforeach ?></ol>
        <div class="aj-sticker-cursor" aria-hidden="true"><span data-ico="direita"></span></div>
      </div>
      <div class="aj-corpo"><span class="aj-mod"><i data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></i><?=escP($rot[$x['modulo']]??$x['modulo'])?></span><h2><?=escP($x['titulo'])?></h2><p class="aj-resumo"><?=escP($x['resumo'])?></p><details <?= $n===0?'open':'' ?>><summary>Ver operações e passos</summary><div class="aj-passos"><?=escP($x['conteudo'])?></div></details></div>
    </article>
  <?php endforeach ?></div>
  <div class="aj-sem-resultados" id="aj-sem-resultados" hidden><span data-ico="procurar"></span><h2>Não encontrámos esse passo</h2><p>Tente outra palavra ou escolha um módulo acima.</p></div>
  <?php endif ?>
</main>
<?php include __DIR__.'/parcial-seletor-tema.php'; ?><script src="<?=asset('assets/ajuda.js')?>"></script></body></html>
