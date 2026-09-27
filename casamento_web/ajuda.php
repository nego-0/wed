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

/** Converte o texto editável do administrador em tópicos de ajuda completos. */
function topicosDaAjuda(string $texto): array {
    $topicos=[]; $titulo='Como fazer'; $passos=[];
    $guardar=function()use(&$topicos,&$titulo,&$passos){if($passos)$topicos[]=['titulo'=>$titulo,'passos'=>$passos];$passos=[];};
    foreach(preg_split('/\R/u',$texto) as $linha){
        $linha=trim($linha); if($linha==='')continue;
        if(preg_match('/^\d+[.)]\s*(.+)$/u',$linha,$m)) {
            [$curto,$narracao]=array_pad(array_map('trim',explode('||',$m[1],2)),2,'');
            if($narracao==='') $narracao='Siga a indicação no ecrã e confirme o resultado antes de avançar para o passo seguinte.';
            $passos[]=['titulo'=>$curto,'narracao'=>$narracao];
        }
        else {$guardar(); $titulo=mb_convert_case(mb_strtolower($linha,'UTF-8'),MB_CASE_TITLE,'UTF-8');}
    }
    $guardar();
    if(!$topicos){
        $linhas=array_values(array_filter(array_map('trim',preg_split('/[.;]\s+/u',$texto))));
        $topicos[]=['titulo'=>'Como fazer','passos'=>array_map(fn($x)=>['titulo'=>$x,'narracao'=>'Siga a indicação no ecrã e confirme o resultado antes de avançar.'],$linhas)];
    }
    return $topicos;
}
function gifAjuda(string $modulo,int $topico,int $passo,string $dispositivo): string {
    return 'assets/ajuda/passos/'.$modulo.'-'.($topico+1).'-'.($passo+1).'-'.$dispositivo.'.gif';
}
?><!doctype html><html lang="pt"><head><?php include __DIR__.'/parcial-icone.php'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ajuda · <?= escP($CAS['casal']) ?></title>
<link rel="stylesheet" href="<?= asset('assets/fontes.css') ?>"><link rel="stylesheet" href="<?= asset('assets/estilo.css') ?>"><link rel="stylesheet" href="<?= asset('assets/ajuda.css') ?>"></head>
<body><?php cabecalho('Ajuda','Respostas visuais para realizar cada tarefa com segurança.','ajuda'); ?>
<main id="conteudo" class="aj-wrap">
  <section class="aj-hero"><div><span class="aj-kicker">Comunidade de ajuda Kulemba</span><h2>Faça cada operação, passo a passo.</h2><p>Escolha um tópico e acompanhe as instruções pela imagem da mesma interface que tem à sua frente.</p></div><div class="aj-pesquisa"><label for="aj-busca">Pesquisar uma dúvida ou tarefa</label><span data-ico="procurar" aria-hidden="true"></span><input id="aj-busca" type="search" placeholder="Ex.: mudar um convidado de mesa" autocomplete="off"><kbd>/</kbd></div></section>
  <?php if(!$itens): ?>
  <div class="aj-vazio"><h2>A ajuda aparecerá com os vossos módulos</h2><p>Quando a licença for atribuída, esta página mostrará apenas os guias correspondentes.</p><a class="btn" href="licenca.php">Ver licença</a></div>
  <?php else: $totalTopicos=array_sum(array_map(fn($x)=>count(topicosDaAjuda($x['conteudo'])),$itens)); ?>
  <div class="aj-forum">
    <aside class="aj-categorias" aria-label="Categorias da ajuda"><div class="aj-categorias-titulo"><span data-ico="conversa"></span><div><b>Categorias</b><small><?= $totalTopicos ?> tópicos oficiais</small></div></div><nav class="aj-submenu"><button class="ativo" type="button" data-aj-modulo="todos"><span data-ico="brilho"></span><b>Todos os tópicos</b><small><?= $totalTopicos ?></small></button><?php foreach($itens as $x): $quantos=count(topicosDaAjuda($x['conteudo'])); ?><button type="button" data-aj-modulo="<?=escP($x['modulo'])?>"><span data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><b><?=escP($rot[$x['modulo']]??$x['modulo'])?></b><small><?= $quantos ?></small></button><?php endforeach ?></nav></aside>
    <section class="aj-conteudo"><div class="aj-forum-topo"><div><span class="aj-kicker">Perguntas e respostas</span><h2>Tópicos de utilização</h2></div><p class="aj-resultado" id="aj-resultado" role="status"></p></div>
      <div class="aj-lista" id="aj-grid"><?php $primeiro=true; foreach($itens as $x): foreach(topicosDaAjuda($x['conteudo']) as $n=>$topico): $pesquisaPassos=implode(' ',array_map(fn($p)=>$p['titulo'].' '.$p['narracao'],$topico['passos'])); $pesquisa=mb_strtolower(($rot[$x['modulo']]??$x['modulo']).' '.$x['titulo'].' '.$x['resumo'].' '.$topico['titulo'].' '.$pesquisaPassos,'UTF-8'); ?>
        <article class="aj-card" data-modulo="<?=escP($x['modulo'])?>" data-pesquisa="<?=escP($pesquisa)?>">
          <details<?= $primeiro?' open':'' ?>>
            <summary><span class="aj-avatar" data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><span class="aj-assunto"><small><?=escP($rot[$x['modulo']]??$x['modulo'])?> · Guia oficial</small><b><?=escP($topico['titulo'])?></b><em><?=escP($x['resumo'])?></em></span><span class="aj-meta"><b><?=count($topico['passos'])?></b><small>passos</small><i data-ico="direita"></i></span></summary>
            <div class="aj-resposta"><header><span class="aj-avatar kulemba">K</span><div><b>Equipa Kulemba</b><small>Resposta verificada · acompanha a versão actual</small></div></header><p>Abra cada passo pela ordem indicada. O GIF usa a interface real e adapta-se automaticamente ao computador ou telemóvel.</p>
              <ol class="aj-passos"><?php foreach($topico['passos'] as $i=>$passo): $desk=gifAjuda($x['modulo'],$n,$i,'desktop'); $mov=gifAjuda($x['modulo'],$n,$i,'mobile'); ?><li><details class="aj-passo"<?= $i===0?' open':'' ?>><summary><span><?= $i+1 ?></span><span><small>Passo <?= $i+1 ?></small><b><?=escP($passo['titulo'])?></b></span><i data-ico="direita" aria-hidden="true"></i></summary><div class="aj-passo-corpo"><picture><source media="(max-width:700px)" srcset="<?=asset($mov)?>"><img src="<?=asset($desk)?>" alt="Demonstração do passo <?= $i+1 ?>: <?=escP($passo['titulo'])?>" loading="lazy"></picture><div><small>Narração detalhada</small><p><?=escP($passo['narracao'])?></p><em>Conclua este passo antes de abrir o seguinte.</em></div></div></details></li><?php endforeach ?></ol>
              <footer><span data-ico="visto"></span>Conclua um passo antes de avançar para o seguinte.</footer>
            </div>
          </details>
        </article>
      <?php $primeiro=false; endforeach; endforeach ?></div>
      <div class="aj-sem-resultados" id="aj-sem-resultados" hidden><span data-ico="procurar"></span><h2>Não encontrámos esse tópico</h2><p>Tente outra palavra ou escolha uma categoria.</p></div>
    </section>
  </div>
  <?php endif ?>
</main>
<?php include __DIR__.'/parcial-seletor-tema.php'; ?><script src="<?=asset('assets/ajuda.js')?>"></script></body></html>
