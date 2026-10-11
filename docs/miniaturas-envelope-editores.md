# Miniaturas dos modelos da casa

O catálogo «Versões e modelos» pede uma prova específica do envelope de cada modelo digital: `convite-digital.php?demo=1&prova=1&miniatura=envelope&modelo=…`.

A resposta contém apenas a capa `#cover`, os estilos, fontes e ornamentos necessários para a apresentar. Não contém as restantes secções nem scripts, áudio, mapas, formulários ou a galeria. As animações ficam paradas nesta miniatura. Abrir o editor ou o convite completo continua a usar o documento integral e os seus recursos interactivos.

O envelope respeita o desenho do modelo e recebe os dados efectivos escolhidos pela aplicação. A miniatura não executa a abertura nem o scroll automático. O mesmo catálogo partilhado é utilizado pelos dois editores; a prova do cartão impresso continua a apresentar o cartão físico.

## Verificação

`casamento_web/tests/chk_miniaturas_convite.php` verifica os doze pacotes digitais disponíveis para novos modelos: envelope presente, nomes personalizados, ausência de conteúdo integral e resposta menor que o documento completo. A prova de navegador `chk_modelos_componentes.js` verifica ainda que as miniaturas dos onze novos modelos não pedem o runtime, fotografias da galeria, mapas ou música.
