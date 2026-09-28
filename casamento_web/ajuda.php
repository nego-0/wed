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
function capturaAjuda(string $modulo,int $topico,string $dispositivo): string {
    return 'assets/ajuda/capturas/'.$modulo.'-'.($topico+1).'-'.$dispositivo.'.jpg';
}
/** Tipo e posição do sticker sobre o controlo real, em percentagem da captura. */
function stickerAjuda(string $modulo,int $topico,int $passo): array {
    $tipos=[
      'convidados'=>[['tocar','escrever','confirmar'],['escrever','tocar','confirmar']],
      'mesas'=>[['tocar','escrever','arrastar'],['tocar','tocar','confirmar']],
      'impresso'=>[['tocar','escrever','confirmar'],['tocar','tocar','confirmar']],
      'digital'=>[['tocar','escrever','confirmar'],['tocar','arrastar','confirmar']],
      'porta'=>[['escrever','tocar','confirmar'],['tocar','tocar','confirmar']],
      'bar'=>[['tocar','escrever','confirmar'],['tocar','tocar','confirmar']],
      'orcamento'=>[['tocar','escrever','confirmar'],['tocar','tocar','confirmar']],
    ];
    $pontos=[
      'convidados'=>[[[50,8],[48,43],[50,88]],[[23,53],[48,77],[43,24]]],
      'mesas'=>[[[12,24],[48,25],[48,68]],[[82,47],[82,58],[52,18]]],
      'impresso'=>[[[17,11],[78,45],[83,11]],[[56,12],[54,43],[83,8]]],
      'digital'=>[[[83,10],[82,47],[82,13]],[[50,48],[50,67],[17,94]]],
      'porta'=>[[[50,50],[50,34],[50,29]],[[34,28],[65,28],[50,36]]],
      'bar'=>[[[24,45],[76,55],[82,17]],[[31,50],[31,50],[66,27]]],
      'orcamento'=>[[[50,10],[50,48],[66,88]],[[50,12],[52,48],[50,82]]],
    ];
    $tipo=$tipos[$modulo][$topico][$passo]??'tocar';
    [$x,$y]=$pontos[$modulo][$topico][$passo]??[50,50];
    return ['tipo'=>$tipo,'x'=>$x,'y'=>$y];
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
      <div class="aj-lista" id="aj-grid"><?php $primeiro=true; foreach($itens as $x): foreach(topicosDaAjuda($x['conteudo']) as $n=>$topico): $desk=capturaAjuda($x['modulo'],$n,'desktop'); $mov=capturaAjuda($x['modulo'],$n,'mobile'); $pesquisaPassos=implode(' ',array_map(fn($p)=>$p['titulo'].' '.$p['narracao'],$topico['passos'])); $pesquisa=mb_strtolower(($rot[$x['modulo']]??$x['modulo']).' '.$x['titulo'].' '.$x['resumo'].' '.$topico['titulo'].' '.$pesquisaPassos,'UTF-8'); ?>
        <article class="aj-card" data-modulo="<?=escP($x['modulo'])?>" data-pesquisa="<?=escP($pesquisa)?>">
          <details<?= $primeiro?' open':'' ?>>
            <summary><span class="aj-avatar" data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><span class="aj-assunto"><small><?=escP($rot[$x['modulo']]??$x['modulo'])?> · Guia oficial</small><b><?=escP($topico['titulo'])?></b><em><?=escP($x['resumo'])?></em></span><span class="aj-meta"><b><?=count($topico['passos'])?></b><small>passos</small><i data-ico="direita"></i></span></summary>
            <div class="aj-resposta"><header><span class="aj-avatar kulemba">K</span><div><b>Equipa Kulemba</b><small>Resposta verificada · acompanha a versão actual</small></div></header><p>Abra cada passo pela ordem indicada. A captura nítida mostra a interface real e o sticker animado indica a acção exacta.</p>
              <div class="aj-guia"><figure class="aj-demonstracao"><picture><source media="(max-width:700px)" srcset="<?=asset($mov)?>"><img src="<?=asset($desk)?>" alt="Ecrã real de <?=escP($rot[$x['modulo']]??$x['modulo'])?> para <?=escP($topico['titulo'])?>" loading="lazy"></picture><?php foreach($topico['passos'] as $i=>$passo): $sticker=stickerAjuda($x['modulo'],$n,$i); ?><span class="aj-sticker-marca<?= $i===0?' ativo':'' ?>" data-sticker-passo="<?=$i?>" style="--sx:<?=$sticker['x']?>%;--sy:<?=$sticker['y']?>%" aria-hidden="true"><img class="aj-sticker <?=escP($sticker['tipo'])?>" src="<?=asset('assets/ajuda/stickers/'.$sticker['tipo'].'.svg')?>" alt=""></span><?php endforeach ?><figcaption><span data-ico="brilho"></span><b>Acção em destaque</b><small data-aj-legenda><?=escP($topico['passos'][0]['titulo']??'Abra o primeiro passo')?></small></figcaption></figure>
                <ol class="aj-passos"><?php foreach($topico['passos'] as $i=>$passo): ?><li><details class="aj-passo" data-passo="<?=$i?>"<?= $i===0?' open':'' ?>><summary><span><?= $i+1 ?></span><span><small>Passo <?= $i+1 ?></small><b><?=escP($passo['titulo'])?></b></span><i data-ico="direita" aria-hidden="true"></i></summary><div class="aj-passo-detalhe"><small>Narração detalhada</small><p><?=escP($passo['narracao'])?></p><em>Conclua este passo antes de abrir o seguinte.</em></div></details></li><?php endforeach ?></ol>
              </div>
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
