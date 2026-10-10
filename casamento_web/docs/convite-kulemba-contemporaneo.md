# Kulemba Contemporâneo

A segunda composição do convite digital usa papel marfim, fotografia e
desenho de traço fino. A capa fechada apresenta um pórtico de dois arcos;
depois de aberta, a fotografia tem a sua própria janela e os nomes ficam
numa área de leitura abaixo dela. A história usa capítulos numerados e o
programa do dia uma sequência vertical, legível também em ecrãs estreitos.

Os catorze símbolos têm desenhos SVG próprios para Kulemba, conservando os
nomes e significados das opções existentes. Os emblemas, ornamentos das
cerimónias, filetes, passe de entrada e confirmação seguem a mesma composição.
Pequenos pontos de luz podem ser activados nos efeitos; a preferência do
dispositivo por movimento reduzido desliga-os.

## Edição

Em **Envelope → Linguagem visual**, escolher **Kulemba Contemporâneo**.
Mantêm-se as opções de texto, cores, tipos de letra, fotografias, ramos,
tamanho dos emblemas, molduras, selos, abertura, ordem, visibilidade e
posicionamento dos blocos, bem como as secções livres.

- As fotografias têm janelas de 4:5 na capa e 3:2 nas outras secções.
  O painel de enquadramento apresenta estas proporções e o zoom é recortado
  dentro da janela, sem cobrir os nomes nem os textos.
- Desligar **Mostrar as molduras** remove bordas, fundo e ornamentos da
  moldura dos cartões. Os símbolos continuam sujeitos à escolha do editor.
- As cores e a tipografia vêm das definições existentes. A mudança de
  composição não substitui os textos ou as fotografias guardadas.
- Os deslocamentos da capa usam a dimensão real da composição, que pode
  crescer com os nomes, a tipografia e a largura do ecrã.
- Alterações consecutivas não deixam o descarte da prévia anterior
  interromper o carregamento da nova composição.

## Modelo padrão e demonstração

O administrador continua a designar a peça de origem nos modelos. A
demonstração usa esse desenho com o casal fictício; respeita os ícones,
a visibilidade e as fontes do modelo. Para Kulemba, usa os mesmos
enquadramentos e composição do convite real.

Esta revisão actualiza a apresentação da linguagem `kulemba`; não cria outro
modelo, não altera a escolha do administrador e não necessita de migração.
As definições guardadas e o desenho clássico continuam compatíveis.

## Verificação

`tests/chk_kulemba_contemporaneo.js` verifica o editor, a gravação e a
demonstração, além de molduras, proporções, símbolos e larguras móveis.
Executar exclusivamente numa instalação de testes com a conta administrativa
indicada por `TEST_PASSWORD` e endereço em `BASE_URL`.
