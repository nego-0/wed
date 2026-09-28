# Experiência pública e Central de Ajuda

O login, o registo e a demonstração usam três fotografias editoriais geradas
para a Kulemba. Os ficheiros finais estão em `assets/marketing/`, em WebP. O
CSS de entrada está isolado em `assets/entrada.css`; a montra pública continua
em `assets/central.css`. Os cartões flutuantes param quando o dispositivo pede
movimento reduzido.

## Navegação administrativa

`menuAdministracao()` em `parcial-cabecalho.php` é a fonte única das ligações
da administração. Nas páginas administrativas, essas ligações ocupam as
pastilhas do próprio cabeçalho; já não existe uma segunda barra. Cada endereço
`plataforma.php?vista=...` abre directamente a área pedida. Modelos mantém a
sua página própria dentro da mesma navegação.

## Uma central, dois contextos

`parcial-ajuda.php` contém o renderizador usado tanto por `ajuda.php` como por
a secção `#demonstracao` de `atendimento.php`. A disposição, as capturas, os
stickers, a pesquisa e os filtros são os mesmos nos dois contextos; muda só a
**voz**, escolhida pelo parâmetro `$modo`: `ajuda` fala de tarefas e passos de
utilização, `demonstracao` fala de vantagens e recursos, para quem ainda está a
decidir. Cada um lê a sua colecção — a Ajuda os guias (`tipo=ajuda`), a montra o
texto de marketing (`tipo=demo`) — e o texto de fábrica da montra vive em
`demonstracaoComercialPadrao()`. A página pública apresenta todos os materiais
activos; depois da entrada, a licença limita a Ajuda aos módulos adquiridos pelo
casamento. O administrador edita as duas colecções, à parte, em **Administração
→ Atendimento**: a **Montra pública (marketing)** e os **Materiais de ajuda**.

## Ajuda por licença

`ajuda.php` só recebe conteúdos activos dos módulos contratados pelo casamento.
O administrador da plataforma pode rever o conjunto completo. A página permite
pesquisar várias palavras e filtrar por módulo. Cada cabeçalho escrito no
conteúdo torna-se um tópico oficial; as linhas numeradas formam uma resposta
verificada, fácil de seguir. A captura real permanece à esquerda e os passos
ficam à direita; abrir um passo expande apenas a sua narração. O elemento
`picture` escolhe a captura de computador ou telemóvel, e um sticker vectorial
animado assinala a acção correspondente. Cada formato tem coordenadas próprias;
quando dois controlos estão afastados, uma mão com seta mostra primeiro a
rolagem entre o passo anterior e o actual. O conteúdo continua editável em
Administração → Atendimento: escreva `||` entre o título curto e a narração.

A migração v58 acrescenta as narrações apenas aos materiais que ainda têm o
texto exacto da versão 57. Materiais que o administrador já editou não são
alterados.

## Actualizar as capturas

O administrador edita o campo de passos em **Administração → Atendimento**, tanto
nos **Materiais de ajuda** como na **Montra pública**. Linhas como `1. Abra Mesas || No menu, escolha Mesas`
tornam-se imediatamente passos: antes de `||` fica o título, depois fica a
narração expandida. Uma linha sem número funciona como título da operação (ou do
tema, na montra). As duas colecções assentam nas mesmas capturas e stickers.

Depois de uma alteração visual ao produto, execute
`node tests/gerar-capturas-ajuda.js` contra uma instalação de testes. O script
cria dados de exemplo contextualizados através da API real, abre cada módulo e
grava 84 JPEG em `assets/ajuda/capturas/`: três passos de dois tópicos, nos sete
módulos, em desktop e mobile. Assim, “Novo convite”, o formulário preenchido e
“Guardar convite” são estados reais; os passos finais de actualização e
filtragem também mostram o respectivo resultado. No convite digital, a cena
passa brevemente pela abertura e acompanha a rolagem até à secção indicada.

`HELP_CAPTURE_SCENES_ONLY=1` actualiza apenas as cenas dos passos;
`HELP_CAPTURE_DEVICE=desktop` ou `mobile` limita temporariamente a execução a
um formato. `HELP_CAPTURE_MODAL_ONLY=1` continua aceite para não quebrar
rotinas antigas. O gerador mede cada alvo no elemento real e grava os valores
em `alvos-cenas.json`; `ajuda.php` usa esse manifesto para colocar o sticker e
para representar a rolagem. Os stickers reutilizáveis vivem em
`assets/ajuda/stickers/`.

O renderizador escolhe os stickers por contexto e pela posição estável do passo: os guias usam acções operacionais; a montra combina as descrições comerciais com benefícios como protecção, comparação e confirmação. `node tests/gerar-stickers-ajuda.js` regenera os 17 SVG semânticos; com `HELP_STICKER_SHEET` também produz a folha visual de revisão. `node tests/chk_stickers_semanticos.js` valida os 42 passos dos dois contextos em computador e telemóvel.

Antes de publicar novas coordenadas, execute
`node tests/rever-stickers-ajuda.js`. O script abre os três passos de cada um
dos 14 tópicos em computador e telemóvel, confirma que os 84 alvos pertencem à
respectiva captura e grava os quadros indicados por `HELP_STICKER_REVIEW` para
inspecção visual. Quando um passo exige rolagem, a cena muda para o estado
final enquanto a mão mostra o percurso; essa transição também recebe um quadro
intermédio.
