<?php
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
function capturaAjuda(string $modulo,int $topico,string $dispositivo,?int $passo=null,string $modo='ajuda'): string {
    $raiz='assets/ajuda/capturas/'.$modulo.'-'.($topico+1);
    if($passo!==null){
        if($modo==='demonstracao'){
            $demo='assets/ajuda/capturas/demonstracao/'.$modulo.'-'.($topico+1).'-'.($passo+1).'-'.$dispositivo.'.jpg';
            if(is_file(__DIR__.'/'.$demo)) return $demo;
        }
        $cena=$raiz.'-'.($passo+1).'-'.$dispositivo.'.jpg';
        if(is_file(__DIR__.'/'.$cena)) return $cena;
    }
    return $raiz.'-'.$dispositivo.'.jpg';
}
/** Vocabulário visual disponível no gerador e no gestor do administrador. */
function tiposStickerAjuda(): array {
    return ['abrir','activar','adicionar','arrastar','ausentes','categorias','comparar','configurar','confirmar','contador','convite','corrigir','descarregar','desmarcar','despesa','editar','entregar','enviar','escrever','estados','factura','familia','filtrar','guardar','historia','lotacao','modelos','mover','paleta','parcelas','pedido','pesquisar','planta','preencher','preparar','presenca','proteger','publicar','prazos','qr','resumo','rever','rolar','selecionar','sentar','stock','telemovel','tocar','valores','versoes','whatsapp'];
}
/** Mantém um vocabulário visual próprio para o guia operacional e para a
 *  narrativa comercial, mesmo quando o administrador melhora os títulos. */
function tipoStickerAjuda(string $modulo,int $topico,int $passo,string $modo,string $titulo,string $padrao): string {
    $mapas=[
      'ajuda'=>[
        'convidados'=>[['familia','configurar','enviar'],['pesquisar','estados','resumo']],
        'mesas'=>[['adicionar','configurar','arrastar'],['sentar','selecionar','lotacao']],
        'impresso'=>[['modelos','editar','versoes'],['publicar','rever','descarregar']],
        'digital'=>[['convite','historia','presenca'],['telemovel','rolar','confirmar']],
        'porta'=>[['qr','presenca','contador'],['corrigir','desmarcar','ausentes']],
        'bar'=>[['categorias','stock','activar'],['pedido','preparar','entregar']],
        'orcamento'=>[['despesa','configurar','parcelas'],['valores','filtrar','prazos']],
      ],
      'demonstracao'=>[
        'convidados'=>[['familia','proteger','contador'],['pesquisar','estados','resumo']],
        'mesas'=>[['planta','configurar','arrastar'],['sentar','lotacao','mover']],
        'impresso'=>[['modelos','editar','versoes'],['rever','paleta','descarregar']],
        'digital'=>[['convite','historia','confirmar'],['telemovel','publicar','whatsapp']],
        'porta'=>[['qr','presenca','contador'],['corrigir','ausentes','proteger']],
        'bar'=>[['categorias','stock','activar'],['pedido','preparar','entregar']],
        'orcamento'=>[['despesa','parcelas','factura'],['valores','filtrar','prazos']],
      ],
    ];
    if(isset($mapas[$modo][$modulo][$topico][$passo])) return $mapas[$modo][$modulo][$topico][$passo];
    $t=mb_strtolower(trim($titulo),'UTF-8');
    $regras=['proteger'=>['privad','acesso limitado'],'descarregar'=>['descarreg','manual de impressão'],'entregar'=>['entrega'],'preparar'=>['prepara'],'enviar'=>['envia','ligação'],'filtrar'=>['filtr'],'comparar'=>['compare','retrato','controlo'],'pesquisar'=>['pesquis','encontrar'],'arrastar'=>['arrast'],'rolar'=>['percorra','desça'],'activar'=>['abra o serviço','active'],'guardar'=>['guard'],'adicionar'=>['nova','crie'],'editar'=>['ajuste','actualize','texto'],'selecionar'=>['selector','escolha'],'preencher'=>['indique','adicione','preencha'],'rever'=>['reveja','visão','pré-visualização'],'confirmar'=>['confirm','registe'],'abrir'=>['abra']];
    foreach($regras as $tipo=>$termos) foreach($termos as $termo) if(str_contains($t,$termo)) return $tipo;
    return $padrao;
}
/** Tipo, posição por dispositivo e eventual percurso desde o passo anterior. */
function stickerAjuda(string $modulo,int $topico,int $passo,string $titulo='',string $modo='ajuda',?array $personalizado=null): array {
    // tipo, desktop x/y, mobile x/y, rolagem desktop/mobile. Cada ponto foi
    // revisto sobre a captura correspondente, não transposto entre formatos.
    $alvos=[
      'convidados'=>[
        [['tocar',70,82,79,76,0,0],['escrever',50,52,50,67,0,0],['confirmar',71,93,73,93,1,1]],
        [['escrever',35,79,30,63,0,0],['tocar',77,94,37,98,0,1],['confirmar',50,20,50,12,1,1]],
      ],
      'mesas'=>[
        [['tocar',21,35,50,48,0,0],['escrever',21,35,37,55,0,0],['arrastar',35,71,50,92,1,1]],
        [['tocar',78,22,50,94,0,0],['tocar',81,48,50,94,1,0],['confirmar',50,7,50,8,1,1]],
      ],
      'impresso'=>[
        [['tocar',89,22,78,33,0,0],['escrever',89,40,71,67,0,0],['confirmar',23,36,29,43,0,0]],
        [['tocar',70,8,50,13,0,0],['tocar',50,42,50,47,1,1],['confirmar',83,8,27,8,1,1]],
      ],
      'digital'=>[
        [['tocar',28,55,27,50,0,0],['escrever',89,55,68,65,0,0],['confirmar',23,37,29,48,0,0]],
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
        [['tocar',79,84,22,50,0,0],['escrever',50,31,50,24,0,0],['confirmar',66,77,81,84,0,0]],
        [['tocar',50,10,50,12,0,0],['tocar',50,46,50,45,1,1],['confirmar',50,76,50,85,1,1]],
      ],
    ];
    $linha=$alvos[$modulo][$topico][$passo]??['tocar',50,50,50,50,0,0];
    $anterior=$passo>0?($alvos[$modulo][$topico][$passo-1]??$linha):$linha;

    // O gerador mede o centro do controlo real em cada captura. Ler essas
    // medidas evita copiar coordenadas à mão quando um botão muda de lugar e
    // conserva a tabela acima como recurso para instalações sem o manifesto.
    static $medidos=null;
    if($medidos===null){
        $medidos=['ajuda'=>[],'demonstracao'=>[]];
        foreach(['ajuda'=>'alvos-cenas.json','demonstracao'=>'alvos-demonstracao.json'] as $contexto=>$nome){
          $f=__DIR__.'/assets/ajuda/capturas/'.$nome;
          $js=is_readable($f)?json_decode((string)file_get_contents($f),true):[];
          foreach(is_array($js)?$js:[] as $m){
              if(!isset($m['modulo'],$m['topico'],$m['passo'],$m['dispositivo']))continue;
              $medidos[$contexto][$m['modulo']][(int)$m['topico']][(int)$m['passo']][$m['dispositivo']]=$m;
          }
        }
    }
    $contexto=$modo==='demonstracao'?'demonstracao':'ajuda';
    $mapa=$medidos[$contexto][$modulo]??[];
    $apoio=$medidos['ajuda'][$modulo]??[];
    $atual=$mapa[$topico+1][$passo+1]??$apoio[$topico+1][$passo+1]??[];
    $antes=$passo>0?($mapa[$topico+1][$passo]??$apoio[$topico+1][$passo]??[]):$atual;
    if(isset($atual['desktop']['x'],$atual['desktop']['y'])){
        $d=$atual['desktop']; $dp=$antes['desktop']??$d;
        $linha[0]=(string)($d['tipo']??$linha[0]); $linha[1]=(float)$d['x']; $linha[2]=(float)$d['y'];
        $linha[5]=!empty($d['rolar']); $anterior[1]=(float)($dp['x']??$linha[1]); $anterior[2]=(float)($dp['y']??$linha[2]);
    }
    if(isset($atual['mobile']['x'],$atual['mobile']['y'])){
        $m=$atual['mobile']; $mp=$antes['mobile']??$m;
        $linha[0]=(string)($m['tipo']??$linha[0]); $linha[3]=(float)$m['x']; $linha[4]=(float)$m['y'];
        $linha[6]=!empty($m['rolar']); $anterior[3]=(float)($mp['x']??$linha[3]); $anterior[4]=(float)($mp['y']??$linha[4]);
    }
    $tipo=tipoStickerAjuda($modulo,$topico,$passo,$modo,$titulo,(string)$linha[0]);
    if($personalizado){
        $permitidos=tiposStickerAjuda();
        if(in_array((string)($personalizado[0]??''),$permitidos,true)) $tipo=(string)$personalizado[0];
        foreach([1,2,3,4] as $i) if(isset($personalizado[$i])&&is_numeric($personalizado[$i])) $linha[$i]=max(0,min(100,(float)$personalizado[$i]));
        if(array_key_exists(5,$personalizado)) $linha[5]=!empty($personalizado[5]);
        if(array_key_exists(6,$personalizado)) $linha[6]=!empty($personalizado[6]);
        if(isset($personalizado[7],$personalizado[8])){$anterior[1]=(float)$personalizado[7];$anterior[2]=(float)$personalizado[8];}
        if(isset($personalizado[9],$personalizado[10])){$anterior[3]=(float)$personalizado[9];$anterior[4]=(float)$personalizado[10];}
    }
    return [
      'tipo'=>$tipo,
      'desktop'=>['x'=>$linha[1],'y'=>$linha[2],'px'=>$anterior[1],'py'=>$anterior[2],'rolar'=>(bool)$linha[5]],
      'mobile'=>['x'=>$linha[3],'y'=>$linha[4],'px'=>$anterior[3],'py'=>$anterior[4],'rolar'=>(bool)$linha[6]],
    ];
}

/** Configuração compacta dos stickers de um material editável. */
function dadosStickersAjuda($dados): array {
    $j=is_array($dados)?$dados:json_decode((string)$dados,true);
    return is_array($j)&&isset($j['stickers'])&&is_array($j['stickers'])?$j['stickers']:[];
}

/** Gera a configuração inicial a partir das capturas medidas e do texto actual. */
function gerarStickersAjuda(string $modulo,string $conteudo,string $modo): array {
    if($modo==='demo') $modo='demonstracao';
    $saida=[];
    foreach(topicosDaAjuda($conteudo) as $t=>$topico){
        foreach($topico['passos'] as $p=>$passo){
            $s=stickerAjuda($modulo,$t,$p,$passo['titulo'],$modo);
            $d=$s['desktop'];$m=$s['mobile'];
            $saida[$t][$p]=[$s['tipo'],round($d['x'],1),round($d['y'],1),round($m['x'],1),round($m['y'],1),(int)$d['rolar'],(int)$m['rolar'],round($d['px'],1),round($d['py'],1),round($m['px'],1),round($m['py'],1)];
        }
    }
    return $saida;
}

/**
 * A mesma central visual serve dois contextos, com a mesma disposição, capturas
 * e stickers — muda só a voz. Em 'ajuda' (a Central autenticada) fala-se de
 * tarefas e passos; em 'demonstracao' (a montra pública) fala-se de vantagens e
 * recursos, para quem ainda está a decidir. O conteúdo de cada um chega de
 * colecções próprias, por isso aqui só se escolhe o vocabulário da moldura.
 */
function renderCentralAjuda(array $itens,array $rot,array $ico,string $modo='ajuda'): void {
    $mkt = $modo === 'demonstracao';
    $T = $mkt ? [
        'kicker'=>'Conheça a plataforma Kulemba',
        'titulo'=>'Tudo o que o vosso casamento ganha, recurso a recurso.',
        'intro'=>'Escolha um módulo e descubra as vantagens sobre a mesma interface que vai usar no grande dia.',
        'busca_rot'=>'Procure um recurso ou vantagem','busca_ph'=>'Ex.: confirmar presenças no próprio convite',
        'cat_conta'=>'recursos em destaque','cat_todos'=>'Todos os recursos',
        'forum_kicker'=>'Vantagens e recursos','forum_titulo'=>'O que a plataforma faz por si',
        'selo'=>'Em destaque','meta'=>'recursos',
        'resposta_estado'=>'Apresentação do módulo · sempre na versão mais recente',
        'resposta_intro'=>'Percorra cada recurso. A imagem mostra a interface real e o sticker assinala exactamente onde a vantagem acontece.',
        'destaque'=>'Recurso em destaque','passo_rot'=>'Recurso','passo_detalhe'=>'O que ganha',
        'passo_fecho'=>'Incluído no módulo.','rodape'=>'Cada módulo trabalha ligado aos outros durante toda a celebração.',
    ] : [
        'kicker'=>'Comunidade de ajuda Kulemba',
        'titulo'=>'Faça cada operação, passo a passo.',
        'intro'=>'Escolha um tópico e acompanhe as instruções pela imagem da mesma interface que tem à sua frente.',
        'busca_rot'=>'Pesquisar uma dúvida ou tarefa','busca_ph'=>'Ex.: mudar um convidado de mesa',
        'cat_conta'=>'tópicos oficiais','cat_todos'=>'Todos os tópicos',
        'forum_kicker'=>'Perguntas e respostas','forum_titulo'=>'Tópicos de utilização',
        'selo'=>'Guia oficial','meta'=>'passos',
        'resposta_estado'=>'Resposta verificada · acompanha a versão actual',
        'resposta_intro'=>'Abra cada passo pela ordem indicada. A captura nítida mostra a interface real e o sticker animado indica a acção exacta.',
        'destaque'=>'Acção em destaque','passo_rot'=>'Passo','passo_detalhe'=>'Narração detalhada',
        'passo_fecho'=>'Conclua este passo antes de abrir o seguinte.','rodape'=>'Conclua um passo antes de avançar para o seguinte.',
    ]; ?>
<section class="aj-hero"><div><span class="aj-kicker"><?=escP($T['kicker'])?></span><h2><?=escP($T['titulo'])?></h2><p><?=escP($T['intro'])?></p></div><div class="aj-pesquisa"><label for="aj-busca"><?=escP($T['busca_rot'])?></label><span data-ico="procurar" aria-hidden="true"></span><input id="aj-busca" type="search" placeholder="<?=escP($T['busca_ph'])?>" autocomplete="off"><kbd>/</kbd></div></section>
  <?php if(!$itens): ?>
  <div class="aj-vazio"><h2>A ajuda aparecerá com os vossos módulos</h2><p>Quando a licença for atribuída, esta página mostrará apenas os guias correspondentes.</p><a class="btn" href="licenca.php">Ver licença</a></div>
  <?php else: $totalTopicos=array_sum(array_map(fn($x)=>count(topicosDaAjuda($x['conteudo'])),$itens)); ?>
  <div class="aj-forum">
    <aside class="aj-categorias" aria-label="Categorias da ajuda"><div class="aj-categorias-titulo"><span data-ico="conversa"></span><div><b>Categorias</b><small><?= $totalTopicos ?> <?=escP($T['cat_conta'])?></small></div></div><nav class="aj-submenu"><button class="ativo" type="button" data-aj-modulo="todos"><span data-ico="brilho"></span><b><?=escP($T['cat_todos'])?></b><small><?= $totalTopicos ?></small></button><?php foreach($itens as $x): $quantos=count(topicosDaAjuda($x['conteudo'])); ?><button type="button" data-aj-modulo="<?=escP($x['modulo'])?>"><span data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><b><?=escP($rot[$x['modulo']]??$x['modulo'])?></b><small><?= $quantos ?></small></button><?php endforeach ?></nav></aside>
    <section class="aj-conteudo"><div class="aj-forum-topo"><div><span class="aj-kicker"><?=escP($T['forum_kicker'])?></span><h2><?=escP($T['forum_titulo'])?></h2></div><p class="aj-resultado" id="aj-resultado" role="status"></p></div>
      <div class="aj-lista" id="aj-grid"><?php $primeiro=true; foreach($itens as $x): $stickersGeridos=dadosStickersAjuda($x['stickers']??''); foreach(topicosDaAjuda($x['conteudo']) as $n=>$topico): $pesquisaPassos=implode(' ',array_map(fn($p)=>$p['titulo'].' '.$p['narracao'],$topico['passos'])); $pesquisa=mb_strtolower(($rot[$x['modulo']]??$x['modulo']).' '.$x['titulo'].' '.$x['resumo'].' '.$topico['titulo'].' '.$pesquisaPassos,'UTF-8'); ?>
        <article class="aj-card" data-modulo="<?=escP($x['modulo'])?>" data-topico="<?=$n?>" data-pesquisa="<?=escP($pesquisa)?>">
          <details<?= $primeiro?' open':'' ?>>
            <summary><span class="aj-avatar" data-ico="<?=escP($ico[$x['modulo']]??'documento')?>"></span><span class="aj-assunto"><small><?=escP($rot[$x['modulo']]??$x['modulo'])?> · <?=escP($T['selo'])?></small><b><?=escP($topico['titulo'])?></b><em><?=escP($x['resumo'])?></em></span><span class="aj-meta"><b><?=count($topico['passos'])?></b><small><?=escP($T['meta'])?></small><i data-ico="direita"></i></span></summary>
            <div class="aj-resposta"><header><span class="aj-avatar kulemba">K</span><div><b>Equipa Kulemba</b><small><?=escP($T['resposta_estado'])?></small></div></header><p><?=escP($T['resposta_intro'])?></p>
              <div class="aj-guia"><figure class="aj-demonstracao"><div class="aj-imagem"><?php foreach($topico['passos'] as $i=>$passo): $cenaDesk=capturaAjuda($x['modulo'],$n,'desktop',$i,$modo); $cenaMov=capturaAjuda($x['modulo'],$n,'mobile',$i,$modo); ?><picture class="aj-cena<?= $i===0?' ativo':'' ?>" data-cena-passo="<?=$i?>"><source media="(max-width:700px)" srcset="<?=asset($cenaMov)?>"><img src="<?=asset($cenaDesk)?>" alt="Passo <?=$i+1?> de <?=escP($topico['titulo'])?> em <?=escP($rot[$x['modulo']]??$x['modulo'])?>" loading="lazy"></picture><?php endforeach; foreach($topico['passos'] as $i=>$passo): $sticker=stickerAjuda($x['modulo'],$n,$i,$passo['titulo'],$modo,$stickersGeridos[$n][$i]??null); $d=$sticker['desktop']; $m=$sticker['mobile']; $classes=($d['rolar']?' tem-rolagem-d':'').($m['rolar']?' tem-rolagem-m':''); $estilo="--dx:{$d['x']}%;--dy:{$d['y']}%;--dpx:{$d['px']}%;--dpy:{$d['py']}%;--mx:{$m['x']}%;--my:{$m['y']}%;--mpx:{$m['px']}%;--mpy:{$m['py']}%"; ?><span class="aj-sticker-marca<?=$classes?><?= $i===0?' ativo':'' ?>" data-sticker-passo="<?=$i?>" data-sticker-tipo="<?=escP($sticker['tipo'])?>" style="<?=$estilo?>" aria-hidden="true"><img class="aj-sticker <?=escP($sticker['tipo'])?>" src="<?=asset('assets/ajuda/stickers/'.$sticker['tipo'].'.svg')?>" alt=""></span><?php if($d['rolar']||$m['rolar']): ?><span class="aj-scroll-marca<?= $d['rolar']?' rolagem-d':'' ?><?= $m['rolar']?' rolagem-m':'' ?>" data-scroll-passo="<?=$i?>" style="<?=$estilo?>" aria-hidden="true"><img src="<?=asset('assets/ajuda/stickers/rolar.svg')?>" alt=""></span><?php endif; endforeach ?></div><figcaption><span class="aj-legenda-icone"><i data-ico="brilho"></i><img class="aj-legenda-sticker" data-aj-sticker-legenda alt=""></span><b><?=escP($T['destaque'])?></b><small data-aj-legenda><?=escP($topico['passos'][0]['titulo']??'Abra o primeiro passo')?></small></figcaption></figure>
                <ol class="aj-passos"><?php foreach($topico['passos'] as $i=>$passo): ?><li><details class="aj-passo" data-passo="<?=$i?>"<?= $i===0?' open':'' ?>><summary><span><?= $i+1 ?></span><span><small><?=escP($T['passo_rot'])?> <?= $i+1 ?></small><b><?=escP($passo['titulo'])?></b></span><i data-ico="direita" aria-hidden="true"></i></summary><div class="aj-passo-detalhe"><small><?=escP($T['passo_detalhe'])?></small><p><?=escP($passo['narracao'])?></p><em><?=escP($T['passo_fecho'])?></em></div></details></li><?php endforeach ?></ol>
              </div>
              <footer><span data-ico="visto"></span><?=escP($T['rodape'])?></footer>
            </div>
          </details>
        </article>
      <?php $primeiro=false; endforeach; endforeach ?></div>
      <div class="aj-sem-resultados" id="aj-sem-resultados" hidden><span data-ico="procurar"></span><h2>Não encontrámos esse tópico</h2><p>Tente outra palavra ou escolha uma categoria.</p></div>
    </section>
  </div>
  <?php endif ?>
<?php }
