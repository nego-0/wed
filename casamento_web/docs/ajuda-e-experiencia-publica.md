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
pesquisar várias palavras e filtrar por módulo. Cada guia transforma as linhas
numeradas do conteúdo num sticker animado: todos os textos ficam visíveis e o
passo em foco avança automaticamente. O conteúdo continua editável em
Administração → Atendimento.

A migração v57 substitui apenas o texto genérico que veio da semente antiga.
Materiais que o administrador já editou não são alterados.

## Actualizar as animações

Não há ficheiros de vídeo para voltar a gravar. O administrador edita o campo
de passos em **Administração → Atendimento → Materiais de ajuda**. Linhas como
`1. Abra Mesas` tornam-se imediatamente os cartões do sticker, na ordem em que
foram escritas. Uma linha sem número funciona como título da operação seguinte.
