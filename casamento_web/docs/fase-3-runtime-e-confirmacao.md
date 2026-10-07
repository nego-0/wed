# Fase 3 — runtime comum e confirmação no convite

O convite deixou de transportar uma cópia própria da lógica de interacção. O
ficheiro `assets/convite-runtime.js` monta capa, música, rolagem automática,
entradas progressivas, contagem, calendário, mapas, galeria, QR, presentes e
confirmação. O CSS do modal e da galeria está em
`assets/convite-runtime.css`.

O PHP entrega dados num bloco JSON de esquema 1. Não escreve funções no
modelo. A visualização publicada, a tela do editor e a prova de um modelo
passam pelo mesmo `convite-digital.php`; a demonstração carrega os mesmos dois
recursos; a exportação incorpora esses recursos sem criar outra versão do
motor.

## Pontos de montagem

Um pacote liga recursos com atributos estáveis:

- `data-kulemba="capa"`, `audio`, `calendario`, `mapa` e `confirmacao`;
- `data-kulemba-galeria-item` nas fotografias ampliáveis;
- `data-kulemba-qr` no QR de entrada;
- `data-presente-qr` no QR da página de presentes.

O manifesto lista os pontos suportados em `runtime.montagens`. Um modelo pode
omitir qualquer um deles; o runtime simplesmente não monta esse recurso.

## Eventos

Os eventos são `CustomEvent` no documento e usam o prefixo `kulemba:`. O
contrato inicial inclui `pronto`, abertura da capa, estados do áudio e da
rolagem, calendário, mapa, galeria, QR e o ciclo da confirmação. Abertura da
capa e da confirmação são canceláveis, permitindo ao editor ou a uma futura
integração intervir sem alterar o modelo.

## Confirmação

O botão do convite abre um modal acessível. O formulário continua a ser
servido por `convite.php`, pelo que presença parcial, membros, perguntas,
validação, prazo e gravação mantêm uma única implementação. `?modal=1` retira
o invólucro de página que seria repetido dentro do modal.

O endereço do convite terminado em `#confirmar` abre directamente o modal.
O foco começa no botão Fechar, fica preso no diálogo enquanto este está aberto
e regressa ao botão do convite no fecho. Escape e clique no fundo também
fecham. Em telemóvel o painel usa `100dvh` e as áreas seguras do navegador.

A demonstração usa o mesmo modal com conteúdo fictício local. Nunca submete
dados. O formulário real avisa o documento pai por `postMessage` depois de
uma resposta, que o runtime converte no evento `kulemba:confirmacao-concluida`.

Movimento reduzido desliga a rolagem automática, as pétalas e as transições do
modal. Os recursos são locais e o runtime não contacta serviços externos.
