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
    return $d;
}
function demonstracaoInterface(string $m): void {
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
        <div class="demo-toolbar"><label for="demo-paleta">Paleta<select id="demo-paleta"><?php foreach(cartaoPaletas() as $k=>$p): ?><option value="<?=escP($k)?>"><?=escP($p['nome'])?></option><?php endforeach ?></select></label><span class="demo-dica">Experimente as cores do convite impresso.</span></div>
        <div class="demo-papel"><div class="demo-escala"><?=renderCartaoConvite(cartaoDadosEvento($d),['nome'=>'Família Mendes','mesas'=>[['nome'=>'Acácia','n'=>2]]],cartaoPaleta('ouro'),'eucalipto')?></div></div>
        <?php break;
      case 'digital': ?>
        <div class="demo-digital"><div><span class="demo-selo">O convite que o convidado recebe</span><h4>Abra o envelope. Conheça a história.</h4><p>Percorra o convite e experimente a confirmação de presença no final. Esta é a mesma composição usada no convite digital, com dados de exemplo.</p></div><iframe title="Convite digital de Lia e Daniel — demonstração" loading="lazy" src="demonstracao-convite.php" sandbox="allow-scripts"></iframe></div>
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
