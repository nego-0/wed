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

O administrador edita o campo de passos em **Administração → Atendimento →
Materiais de ajuda**. Linhas como `1. Abra Mesas || No menu, escolha Mesas`
tornam-se imediatamente passos: antes de `||` fica o título, depois fica a
narração expandida. Uma linha sem número funciona como título da operação.

Depois de uma alteração visual ao produto, execute
`node tests/gerar-capturas-ajuda.js` contra uma instalação com dados de exemplo.
O script abre cada módulo real e grava 28 JPEG em
`assets/ajuda/capturas/`: dois tópicos por módulo, em desktop e mobile. Os
stickers reutilizáveis vivem em `assets/ajuda/stickers/`; a posição e o tipo de
gesto de cada passo são definidos por `stickerAjuda()` em `ajuda.php`.

Antes de publicar novas coordenadas, execute
`node tests/rever-stickers-ajuda.js`. O script abre os três passos de cada um
dos 14 tópicos em computador e telemóvel, confirma que os 84 alvos pertencem à
respectiva captura e grava os quadros indicados por `HELP_STICKER_REVIEW` para
inspecção visual. Os percursos de rolagem também recebem um quadro intermédio.
