# Experiência pública e Central de Ajuda

O login, o registo e a demonstração usam três fotografias editoriais geradas
para a Kulemba. Os ficheiros finais estão em `assets/marketing/`, em WebP. O
CSS de entrada está isolado em `assets/entrada.css`; a montra pública continua
em `assets/central.css`. Os cartões flutuantes param quando o dispositivo pede
movimento reduzido.

## Navegação administrativa

`menuAdministracao()` em `parcial-cabecalho.php` é a fonte única das ligações
da administração. A barra aparece nas páginas administrativas, indica a área
actual e usa `plataforma.php?vista=...` para abrir directamente Casamentos,
Licenças, Contas, Dados, Acções, Atendimento ou Definições. Modelos usa a mesma
barra e mantém a sua página própria.

## Ajuda por licença

`ajuda.php` só recebe conteúdos activos dos módulos contratados pelo casamento.
O administrador da plataforma pode rever o conjunto completo. A página permite
pesquisar várias palavras, filtrar por módulo e reproduzir WEBM ou MP4, mantendo
GIF, PNG e WebP como alternativas. Conteúdo e multimédia continuam editáveis em
Administração → Atendimento.

A migração v57 substitui apenas o texto genérico que veio da semente antiga.
Materiais que o administrador já editou não são alterados.

## Actualizar as animações

Execute o gravador apenas numa instalação de testes com dados fictícios:

```sh
cd casamento_web/tests
BASE_URL=http://127.0.0.1:8920 \
TEST_USER=admin TEST_PASSWORD=<senha-de-teste> TEST_CASAMENTO=1 \
node gerar-ajuda.js
```

O script autentica a conta, abre o casamento indicado, visita cada módulo e
assinala os controlos reais sem submeter formulários. Cada gravação só substitui
`assets/ajuda/<modulo>.webm` depois de terminar e cria também a capa
`assets/ajuda/<modulo>-poster.png`. Para actualizar parte:

```sh
AJUDA_MODULOS=mesas,digital node gerar-ajuda.js
```

O Playwright precisa do componente FFmpeg para gravar vídeo. Instala-se uma vez
com `npx playwright install ffmpeg`. As variáveis `BASE_URL`, `CHROMIUM`,
`TEST_USER`, `TEST_PASSWORD` e `TEST_CASAMENTO` permitem adaptar o ambiente.
