# Fontes de design — arquivo, não código

Os originais do desenho das peças: molduras, monogramas, volutas, floreados,
divisórias e ornamentos, tal como saíram do trabalho de design.

**Nada aqui é servido nem carregado pelo site.** Esta pasta fica de propósito
fora de `casamento_web/`, que é o que se envia para o servidor — assim não
ocupa espaço no alojamento nem se confunde com os ficheiros em uso.

## Porque estão aqui e não em `assets/`

O sistema começou por usar estes ficheiros como imagens. Hoje **desenha os
mesmos motivos por código**, em SVG gerado (ver `svgVoluta()`, `svgFloreado()`,
`svgTrepadeira()`, `svgBraco()`, `svgEspiral()` e `svgFolha()`, em
`casamento_web/pecas.php`), para que a cor, a espessura e a escala acompanhem a
paleta e as opções do editor sem voltar ao servidor. Os ficheiros deixaram de
ser lidos, mas guardam-se: são o traço original, útil se algum motivo tiver de
ser redesenhado ou entregue a uma gráfica.

## O que continua em uso, e onde

Dois ficheiros do mesmo pacote **não** vieram para aqui, porque o sistema
precisa deles:

| Ficheiro | Onde é usado |
|---|---|
| `casamento_web/assets/pecas/icons/coracao.svg` | Ícone da aplicação do porteiro (`manifest.php`) |
| `casamento_web/assets/pecas/manuais/cartao-10x15.html` | Botão «Abrir original» na página do convite impresso |

## Se algum destes motivos voltar a ser preciso

Copie o ficheiro para `casamento_web/assets/pecas/` e referencie-o a partir do
código. Nada aqui está perdido: mesmo que a pasta desapareça, o histórico do
git guarda tudo.
