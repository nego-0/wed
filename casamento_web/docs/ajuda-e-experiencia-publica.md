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
verificada, fácil de seguir. Cada passo abre a sua narração detalhada e um GIF
da interface real. O elemento `picture` escolhe o GIF de computador ou de
telemóvel conforme o ecrã. O conteúdo continua editável em Administração →
Atendimento: escreva `||` entre o título curto e a narração do passo.

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
`assets/ajuda/capturas/`: dois tópicos por módulo, em desktop e mobile. Em
seguida chama `tests/gerar-gifs-ajuda.py`, que transforma essas capturas nos 84
GIFs de `assets/ajuda/passos/`: três passos, dois tamanhos e dois tópicos por
módulo. Defina `PYTHON` se o executável não estiver disponível como `python`.
