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
/** Tipo, posição por dispositivo e eventual percurso desde o passo anterior. */
function stickerAjuda(string $modulo,int $topico,int $passo): array {
    // tipo, desktop x/y, mobile x/y, rolagem desktop/mobile. Cada ponto foi
    // revisto sobre a captura correspondente, não transposto entre formatos.
    $alvos=[
      'convidados'=>[
        [['tocar',25,6,26,7,0,0],['escrever',36,21,50,21,0,0],['confirmar',75,94,84,96,1,1]],
        [['escrever',35,79,30,63,0,0],['tocar',77,94,37,98,0,1],['confirmar',50,20,50,12,1,1]],
      ],
      'mesas'=>[
        [['tocar',92,15,19,31,0,0],['escrever',28,15,50,31,0,0],['arrastar',34,70,50,71,1,1]],
        [['tocar',78,22,50,94,0,0],['tocar',81,48,50,94,1,0],['confirmar',50,7,50,8,1,1]],
      ],
      'impresso'=>[
        [['tocar',94,4,43,70,0,0],['escrever',50,45,43,70,1,0],['confirmar',14,14,43,70,1,0]],
        [['tocar',70,8,50,13,0,0],['tocar',50,42,50,47,1,1],['confirmar',83,8,27,8,1,1]],
      ],
      'digital'=>[
        [['tocar',95,4,43,70,0,0],['escrever',88,46,43,70,1,0],['confirmar',84,9,43,70,1,0]],
        [['tocar',50,37,50,28,0,0],['arrastar',50,72,50,65,1,1],['confirmar',50,94,50,94,1,1]],
      ],
      'porta'=>[
        [['escrever',42,45,50,49,0,0],['tocar',36,31,25,35,0,0],['confirmar',68,45,50,55,0,1]],
        [['tocar',67,28,73,29,0,0],['tocar',50,45,50,49,0,0],['confirmar',38,34,50,37,0,0]],
      ],
      'bar'=>[
        [['tocar',22,31,22,44,0,0],['escrever',85,39,78,46,0,0],['confirmar',89,22,50,27,0,1]],
        [['tocar',22,32,37,58,0,0],['confirmar',20,54,50,89,1,1],['tocar',83,10,25,13,1,1]],
      ],
      'orcamento'=>[
        [['tocar',35,19,30,15,0,0],['escrever',50,40,50,39,0,0],['confirmar',70,76,88,85,1,1]],
        [['tocar',50,10,50,12,0,0],['tocar',50,46,50,45,1,1],['confirmar',50,76,50,85,1,1]],
      ],
    ];
    $linha=$alvos[$modulo][$topico][$passo]??['tocar',50,50,50,50,0,0];
    $anterior=$passo>0?($alvos[$modulo][$topico][$passo-1]??$linha):$linha;
    return [
      'tipo'=>$linha[0],
      'desktop'=>['x'=>$linha[1],'y'=>$linha[2],'px'=>$anterior[1],'py'=>$anterior[2],'rolar'=>(bool)$linha[5]],
      'mobile'=>['x'=>$linha[3],'y'=>$linha[4],'px'=>$anterior[3],'py'=>$anterior[4],'rolar'=>(bool)$linha[6]],
    ];
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
              <div class="aj-guia"><figure class="aj-demonstracao"><div class="aj-imagem"><picture><source media="(max-width:700px)" srcset="<?=asset($mov)?>"><img src="<?=asset($desk)?>" alt="Ecrã real de <?=escP($rot[$x['modulo']]??$x['modulo'])?> para <?=escP($topico['titulo'])?>" loading="lazy"></picture><?php foreach($topico['passos'] as $i=>$passo): $sticker=stickerAjuda($x['modulo'],$n,$i); $d=$sticker['desktop']; $m=$sticker['mobile']; $classes=($d['rolar']?' tem-rolagem-d':'').($m['rolar']?' tem-rolagem-m':''); $estilo="--dx:{$d['x']}%;--dy:{$d['y']}%;--dpx:{$d['px']}%;--dpy:{$d['py']}%;--mx:{$m['x']}%;--my:{$m['y']}%;--mpx:{$m['px']}%;--mpy:{$m['py']}%"; ?><span class="aj-sticker-marca<?=$classes?><?= $i===0?' ativo':'' ?>" data-sticker-passo="<?=$i?>" style="<?=$estilo?>" aria-hidden="true"><img class="aj-sticker <?=escP($sticker['tipo'])?>" src="<?=asset('assets/ajuda/stickers/'.$sticker['tipo'].'.svg')?>" alt=""></span><?php if($d['rolar']||$m['rolar']): ?><span class="aj-scroll-marca<?= $d['rolar']?' rolagem-d':'' ?><?= $m['rolar']?' rolagem-m':'' ?>" data-scroll-passo="<?=$i?>" style="<?=$estilo?>" aria-hidden="true"><img src="<?=asset('assets/ajuda/stickers/rolar.svg')?>" alt=""></span><?php endif; endforeach ?></div><figcaption><span data-ico="brilho"></span><b>Acção em destaque</b><small data-aj-legenda><?=escP($topico['passos'][0]['titulo']??'Abra o primeiro passo')?></small></figcaption></figure>
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
