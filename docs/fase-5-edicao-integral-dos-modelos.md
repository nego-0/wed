# Fase 5 — edição integral dos modelos

Os editores passam a montar o inspector a partir de duas fichas independentes:

1. o contrato do pacote visual instalado, que declara o que o modelo sabe desenhar;
2. a liberdade configurada pelo administrador, que declara o que os noivos podem alterar.

O servidor cruza as duas fichas antes de entregar o manifesto ao navegador. Assim, um modelo que preserve cores e tipografia próprias não mostra controlos sem efeito, mesmo que uma configuração antiga os tenha autorizado. O modo administrador recebe todas as ferramentas suportadas pelo pacote; o modo dos noivos recebe apenas a intersecção autorizada.

Os campos também usam os metadados semânticos comuns do sistema. Os nomes, tipos e limites deixam de depender de HTML escrito à mão em cada modelo, o que permite incorporar novos pacotes sem duplicar o editor.

## Validação

`tests/chk_manifesto_editor.php` verifica a separação entre administrador e noivos, a filtragem pelo pacote, as secções obrigatórias e os rótulos editoriais dos campos.
