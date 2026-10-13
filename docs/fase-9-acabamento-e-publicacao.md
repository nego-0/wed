# Fase 9 — acabamento e validação para publicação

A revisão final cobre os onze modelos digitais em mobile e desktop. A matriz abre cada envelope pelo teclado, confirma a ausência de transbordo, mede o arranque, verifica o mapa real, o código QR de acesso, a galeria e o seu modal, os comandos de música, o scroll automático, a confirmação parcial da lista nominal e o encerramento da resposta sem qualquer escrita na API.

Em desktop, a mesma matriz usa o tema escuro do sistema e a preferência de movimento reduzido. Nessa condição, a abertura do envelope continua imediata e a rotação automática da galeria permanece parada. Os diálogos e imagens interactivas recebem nomes e comandos acessíveis, permitindo operar a peça com teclado e leitor de ecrã.

A revisão encontrou um comando de confirmação escondido no HTML original do modelo Royal Blue. O runtime passa a reutilizar apenas comandos realmente visíveis e acrescenta a cena de confirmação no estilo do próprio modelo quando necessário. A galeria ampliada também anuncia explicitamente o seu propósito.

O arranque local deixa de depender de uma pasta de sessões específica do WAMP. Quando a pasta configurada não existe ou não permite escrita, a aplicação cria uma pasta privada temporária para a sessão. Isto mantém a instalação directamente acessível depois de alterações e não muda o comportamento dos servidores que já possuem uma pasta válida.

## Critérios verificados

- onze modelos em viewport mobile e desktop;
- envelope, abertura por teclado e movimento reduzido;
- dados de exemplo e quatro fotografias administradas pela plataforma;
- galeria, ampliação, navegação, miniaturas e fecho;
- música opcional e estado reproduzir/pausar;
- scroll automático com pausa, retoma e animação pelo compositor;
- mapa real, ligação externa e código QR desenhado;
- confirmação em modal com presença parcial e sem gravação na demonstração;
- modo escuro, acessibilidade, limites de tempo e complexidade do DOM;
- inexistência de erros JavaScript, respostas HTTP inválidas ou pedidos de escrita.

## Execução

`tests/chk_fase9_publicacao.js` descobre os modelos activos através da API e executa a matriz completa. A variável `TEST_MODEL` permite repetir isoladamente um modelo durante uma correção. As verificações estruturais e de pacotes continuam em `chk_modelos_exactos.js` e `chk_pacotes_modelos_exactos.js`.
