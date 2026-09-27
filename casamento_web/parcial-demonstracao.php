<?php
// Componentes de produção, alimentados apenas por um casal fictício.
require_once __DIR__ . '/pecas.php';
function demonstracaoDefs(): array {
    $d=array_replace(defsPadrao(), exemploDeFabrica());
    $d['casal.noiva']='Lia'; $d['casal.noivo']='Daniel'; $d['evento.data']='2027-07-17';
    $d['evento.local']='Jardim das Acácias'; $d['evento.cidade']='Luanda';
    $d['evento.civil_local']='Jardim das Acácias'; $d['evento.religiosa_local']='Capela do Jardim';
    $d['media.hero']='assets/convite/galeria/capa-jardim-solar.jpg';
    $d['media.historia']='assets/convite/galeria/historia-jardim-solar.jpg';
    $d['media.interludio']='assets/convite/galeria/interludio-jardim-solar.jpg';
    $d['media.acesso']='assets/convite/galeria/acesso-jardim-solar.jpg';
    $d['media.musica']='';
    // Uma história própria, escrita para este casal fictício.
    $d['capa.dica']='Abram connosco este novo capítulo';
    $d['textos.kicker']='Lia & Daniel · 17 de Julho de 2027';
    $d['textos.hero_sub']='Escolhemos caminhar juntos';
    $d['textos.convite_eyebrow']='O nosso dia também é vosso';
    $d['textos.lead']='Entre conversas demoradas e passeios sem pressa, encontrámos o nosso lugar um no outro. Agora queremos celebrar este amor com as pessoas que fazem parte da nossa história. Venham brindar connosco!';
    $d['textos.closing']='Guardámos um lugar à mesa e um abraço para vos receber.';
    $d['historia.eyebrow']='Como tudo começou';
    $d['historia.titulo']='Dos pequenos encontros ao grande sim';
    $d['historia.quote']='A nossa melhor descoberta foi perceber que juntos nos sentimos em casa.';
    $d['historia.autor']='Lia & Daniel';
    $d['historia.capitulos']=json_encode([
        ['t'=>'Uma conversa que ficou', 'x'=>'Conhecemo-nos num almoço de amigos. Entre gargalhadas e histórias, a tarde passou depressa. A conversa continuou muito depois de nos despedirmos.'],
        ['t'=>'O nosso lugar favorito', 'x'=>'Vieram os passeios pelo jardim, as músicas partilhadas e os planos de domingo. Sem darmos conta, os dias mais simples tornaram-se os mais especiais.'],
        ['t'=>'Um sim, muitos amanhãs', 'x'=>'O pedido chegou num fim de tarde, com o mesmo sorriso do primeiro encontro. Dissemos sim a uma vida em conjunto. E queremos começar esse caminho convosco.'],
    ],JSON_UNESCAPED_UNICODE);
    $d['interludio.quote']='Que nunca nos faltem tempo para nós, motivos para rir e vontade de continuar.';
    $d['interludio.autor']='Lia & Daniel';
    $d['interludio.fecho']='De mãos dadas, para o que vier.';
    $d['evento.civil_hora']='16:00'; $d['evento.hora']='18:00';
    $d['cronograma.itens']=json_encode([
        ['h'=>'16H00','p'=>'Tarde','t'=>'O nosso sim','s'=>'Cerimónia no jardim','i'=>'aneis'],
        ['h'=>'18H00','p'=>'Tarde','t'=>'Um brinde a nós','s'=>'Recepção e fotografias','i'=>'taca'],
        ['h'=>'19H00','p'=>'Noite','t'=>'Todos à mesa','s'=>'Jantar entre família e amigos','i'=>'buffet'],
        ['h'=>'21H00','p'=>'Noite','t'=>'A pista é nossa','s'=>'Bolo, música e muitos abraços','i'=>'musica'],
    ],JSON_UNESCAPED_UNICODE);
    $d['acesso.eyebrow']='Estamos à vossa espera';
    $d['acesso.titulo']='O vosso lugar está reservado';
    $d['acesso.instrucao']='À chegada, apresentem o convite à nossa equipa. Nós ajudamos a encontrar a mesa e a começar a festa.';
    $d['acesso.nota']='Família Mendes · Mesa Acácia · 2 lugares';
    $d['manual.eyebrow']='Para aproveitar o dia'; $d['manual.titulo']='Venham celebrar connosco';
    $d['manual.intro']='Tragam o vosso melhor sorriso. Do resto, cuidamos juntos.';
    $d['manual.itens']=json_encode([
        ['i'=>'relogio','x'=>'Cheguem com tempo
para um **abraço**'],
        ['i'=>'envelope','x'=>'Tenham o **convite**
à mão na entrada'],
        ['i'=>'telemovel','x'=>'Guardem **memórias**
e vivam o momento'],
        ['i'=>'taca','x'=>'Brindem, dancem
e **divirtam-se**'],
    ],JSON_UNESCAPED_UNICODE);
    $d['rsvp.titulo']='Vamos celebrar
juntos?';
    $d['rsvp.sub']='Digam-nos se podemos contar convosco. Mal podemos esperar por este dia!';
    $d['rsvp.prazo']='2027-06-30'; $d['rsvp.deadline']='Confirmem até 30 de Junho de 2027';
    $d['footer.local']='Jardim das Acácias · Luanda';
    $d['footer.quote']='O melhor deste dia será partilhá-lo convosco.';
    foreach(['hero','historia','interludio','acesso'] as $foto)$d['foto.'.$foto]='50 0 100';
    return $d;
}

/**
 * Veste os dados fictícios com o modelo que o administrador definiu como
 * peça de origem. O desenho vem do modelo; o casal, as fotografias e a
 * narrativa continuam a ser os da demonstração comercial.
 */
function demonstracaoDefsComModelo(mysqli $conn): array {
    $demo = demonstracaoDefs();
    $modelo = modeloDeOrigem($conn, 'digital', 0);
    if (!$modelo) return $demo;
    $desenho = desenhoDoModeloId($conn, 'digital', (int)$modelo['id']);
    if (!is_array($desenho)) return $demo;
    $conteudo = '/^(casal|evento|media|foto|textos|historia|interludio|cronograma|acesso|manual|rsvp|footer)\./';
    $final = array_replace($demo, $desenho);
    foreach ($demo as $chave => $valor) {
        if (preg_match($conteudo, $chave) && !str_ends_with($chave, '.visivel')
            && !str_starts_with($chave, 'cronograma.icone_')) $final[$chave] = $valor;
    }
    return $final;
}

function demonstracaoInterface(string $m, string $modeloDigital = ''): void {
    switch($m) {
      case 'convidados': ?>
        <div class="stats"><div class="stat"><div class="n">6</div><div class="l">Convidados</div></div><div class="stat"><div class="n" data-confirmados>4</div><div class="l">Confirmados</div></div><div class="stat"><div class="n" data-pendentes>2</div><div class="l">Pendentes</div></div></div>
        <div class="demo-toolbar"><label for="demo-busca">Pesquisar convidados<input id="demo-busca" type="search" placeholder="Nome ou família…"></label><label for="demo-filtro">Estado<select id="demo-filtro"><option value="todos">Todos</option><option value="confirmado">Confirmados</option><option value="pendente">Pendentes</option></select></label></div>
        <div id="demo-convidados"></div>
        <?php break;
      case 'mesas': ?>
        <div class="demo-toolbar"><span class="demo-dica">Escolha uma pessoa e toque na mesa onde a quer sentar.</span><label for="demo-pessoa">Convidado<select id="demo-pessoa"></select></label></div>
        <div class="demo-sala"><div class="demo-palco">Lia &amp; Daniel</div><div id="demo-mesas"></div></div><p class="demo-dica">Os lugares disponíveis actualizam-se quando muda uma pessoa.</p>
        <?php break;
      case 'impresso':
        $d=demonstracaoDefs(); ?>
        <div class="demo-toolbar demo-toolbar-paleta"><label for="demo-paleta">Paleta<select id="demo-paleta" aria-describedby="demo-paleta-dica"><?php foreach(cartaoPaletas() as $k=>$p): ?><option value="<?=escP($k)?>"><?=escP($p['nome'])?></option><?php endforeach ?></select></label><p id="demo-paleta-dica" class="demo-dica">Experimente as cores do convite impresso.</p></div>
        <div class="demo-papel"><div class="demo-escala"><?=renderCartaoConvite(cartaoDadosEvento($d),['nome'=>'Família Mendes','mesas'=>[['nome'=>'Acácia','n'=>2]]],cartaoPaleta('ouro'),'eucalipto')?></div></div>
        <?php break;
      case 'digital': ?>
        <div class="demo-digital"><div class="demo-digital-intro"><span class="demo-selo"><?= $modeloDigital !== '' ? 'Modelo padrão · '.escP($modeloDigital) : 'Uma história contada com carinho' ?></span><h4>Um convite que dá vontade de abrir.</h4><p>Conheça Lia e Daniel, descubra os detalhes do dia e confirme a presença no final.</p><a href="demonstracao-convite.php" target="_blank" rel="noopener">Ver convite numa janela completa ↗</a></div><iframe title="Convite digital de Lia e Daniel — demonstração" loading="lazy" src="demonstracao-convite.php" sandbox="allow-scripts"></iframe></div>
        <?php break;
      case 'porta': ?>
        <div class="stats"><div class="stat"><div class="n" data-presentes>0</div><div class="l">Presentes</div></div><div class="stat"><div class="n" data-esperados>4</div><div class="l">Confirmados</div></div></div>
        <label for="demo-porta-busca">Pesquisar nome<input id="demo-porta-busca" type="search" placeholder="Ex.: Ana Mendes"></label><div id="demo-porta"></div>
        <?php break;
      case 'bar': ?>
        <div class="demo-servico"><section><span class="demo-selo">01 · Convidado</span><h4>O que vai beber?</h4><p>Mesa Acácia</p><div id="demo-bebidas"></div><button class="btn btn-ouro" id="demo-pedir" type="button" disabled>Enviar pedido</button></section><section class="b-cartao"><span class="demo-selo">02 · Copa e entrega</span><h4>Pedidos da mesa</h4><div id="demo-pedido"><p>Escolha uma bebida para acompanhar o pedido até à entrega.</p></div></section></div>
        <?php break;
      case 'orcamento': ?>
        <div class="stats"><div class="stat"><div class="n">850 000 Kz</div><div class="l">Planeado</div></div><div class="stat"><div class="n" data-pago>500 000 Kz</div><div class="l">Pago</div></div><div class="stat"><div class="n" data-falta>350 000 Kz</div><div class="l">Por pagar</div></div></div>
        <div class="demo-tabela"><table><thead><tr><th>Despesa</th><th>Categoria</th><th>Valor</th><th>Pagamento</th></tr></thead><tbody id="demo-despesas"></tbody></table></div>
        <?php break;
    }
}
