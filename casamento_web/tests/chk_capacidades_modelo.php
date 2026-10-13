<?php
// Prova sem base de dados: a ficha é um contrato puro e pode ser validada em
// qualquer instalação antes de abrir os editores.
require_once __DIR__ . '/../personalizacao.php';
require_once __DIR__ . '/../editor-modelo.php';

$falhas = 0;
function capOk(bool $cond, string $msg): void {
    global $falhas;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $msg . PHP_EOL;
    if (!$cond) $falhas++;
}

$digital = capacidadesModeloPadrao('digital');
capOk($digital['schema'] === 3, 'a ficha usa o esquema explícito de capacidades');
capOk(in_array('rolagem_automatica', $digital['recursos'], true), 'recursos gerais pertencem à ficha');
capOk(in_array('confirmacao', $digital['componentes'], true), 'o digital declara o componente de confirmação');
capOk(in_array('media.musica', $digital['media'], true), 'música é uma capacidade independente');
capOk(in_array('casal.noiva', $digital['campos_editaveis'], true), 'os campos usam chaves semânticas comuns');

$limitada = normalizarCapacidadesModelo('digital', [
    'schema'=>3,
    'seccoes'=>['historia','inventada'], 'obrigatorios'=>['hero'],
    'paineis'=>['conteudo'], 'media'=>[], 'efeitos'=>[], 'movimentaveis'=>[],
    'campos_editaveis'=>['casal.noiva','chave.inventada'],
    'cores_permitidas'=>['gold','inexistente'], 'tipografias_permitidas'=>[],
    'componentes'=>['texto'], 'limites'=>['largura'=>50000,'max_blocos'=>2,'max_fotos'=>1],
]);
capOk($limitada['seccoes'] === ['historia','hero'], 'uma secção obrigatória volta sempre à ficha');
capOk($limitada['campos_editaveis'] === ['casal.noiva'], 'chaves desconhecidas são recusadas');
capOk($limitada['cores_permitidas'] === ['gold'], 'a paleta só aceita variáveis conhecidas');
capOk($limitada['limites']['largura'] === 5000, 'os limites são saneados no servidor');

$impresso = capacidadesModeloPadrao('impresso');
capOk(in_array('guias', $impresso['paineis'], true), 'o impresso declara sangria, corte e área segura');
capOk($impresso['limites']['largura'] === 720 && $impresso['limites']['altura'] === 1080,
      'o formato do impresso pertence à ficha do modelo');

exit($falhas ? 1 : 0);
