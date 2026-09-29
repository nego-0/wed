<?php
// Componentes de produção, alimentados apenas por um casal fictício.
require_once __DIR__ . '/pecas.php';
function demonstracaoDefs(mysqli $conn): array {
    // Uma única fonte de verdade: os dados que o administrador mantém em
    // Modelos → Dados de exemplo. Nunca se lê o casamento actualmente aberto.
    return array_replace(defsPadrao(), exemploModelo($conn));
}

/**
 * Veste os dados fictícios com o modelo que o administrador definiu como
 * peça de origem. O desenho vem do modelo; o casal, as fotografias e a
 * narrativa continuam a ser os da demonstração comercial.
 */
function demonstracaoDefsComModelo(mysqli $conn): array {
    $demo = demonstracaoDefs($conn);
    $modelo = modeloDeOrigem($conn, 'digital', 0);
    if (!$modelo) return $demo;
    $desenho = desenhoDoModeloId($conn, 'digital', (int)$modelo['id']);
    if (!is_array($desenho)) return $demo;
    // O modelo fornece o desenho. A identidade inteira volta a ser aplicada no
    // fim para um modelo antigo nunca reintroduzir nomes ou fotografias reais.
    return array_replace($demo, $desenho, exemploModelo($conn));
}

function demonstracaoInterface(string $m, string $modeloDigital = ''): void {
    global $conn;
    $dadosDemo=demonstracaoDefs($conn);
    $casalDemo=trim(($dadosDemo['casal.noiva']??'Marta').' & '.($dadosDemo['casal.noivo']??'Pedro'),' &');
    switch($m) {
      case 'convidados': ?>
        <div class="stats"><div class="stat"><div class="n">6</div><div class="l">Convidados</div></div><div class="stat"><div class="n" data-confirmados>4</div><div class="l">Confirmados</div></div><div class="stat"><div class="n" data-pendentes>2</div><div class="l">Pendentes</div></div></div>
        <div class="demo-toolbar"><label for="demo-busca">Pesquisar convidados<input id="demo-busca" type="search" placeholder="Nome ou família…"></label><label for="demo-filtro">Estado<select id="demo-filtro"><option value="todos">Todos</option><option value="confirmado">Confirmados</option><option value="pendente">Pendentes</option></select></label></div>
        <div id="demo-convidados"></div>
        <?php break;
      case 'mesas': ?>
        <div class="demo-toolbar"><span class="demo-dica">Escolha uma pessoa e toque na mesa onde a quer sentar.</span><label for="demo-pessoa">Convidado<select id="demo-pessoa"></select></label></div>
        <div class="demo-sala"><div class="demo-palco"><?=escP($casalDemo)?></div><div id="demo-mesas"></div></div><p class="demo-dica">Os lugares disponíveis actualizam-se quando muda uma pessoa.</p>
        <?php break;
      case 'impresso':
        $d=$dadosDemo; ?>
        <div class="demo-toolbar demo-toolbar-paleta"><label for="demo-paleta">Paleta<select id="demo-paleta" aria-describedby="demo-paleta-dica"><?php foreach(cartaoPaletas() as $k=>$p): ?><option value="<?=escP($k)?>"><?=escP($p['nome'])?></option><?php endforeach ?></select></label><p id="demo-paleta-dica" class="demo-dica">Experimente as cores do convite impresso.</p></div>
        <div class="demo-papel"><div class="demo-escala"><?=renderCartaoConvite(cartaoDadosEvento($d),['nome'=>'Família Mendes','mesas'=>[['nome'=>'Acácia','n'=>2]]],cartaoPaleta('ouro'),'eucalipto')?></div></div>
        <?php break;
      case 'digital': ?>
        <div class="demo-digital"><div class="demo-digital-intro"><span class="demo-selo"><?= $modeloDigital !== '' ? 'Modelo padrão · '.escP($modeloDigital) : 'Uma história contada com carinho' ?></span><h4>Um convite que dá vontade de abrir.</h4><p>Conheça <?=escP($casalDemo)?>, descubra os detalhes do dia e confirme a presença no final.</p><a href="demonstracao-convite.php" target="_blank" rel="noopener">Ver convite numa janela completa ↗</a></div><iframe title="Convite digital de <?=escP($casalDemo)?> — demonstração" loading="lazy" src="demonstracao-convite.php" sandbox="allow-scripts"></iframe></div>
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
