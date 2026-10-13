# Secções, refinamentos e dados dos convites

Os onze modelos recebem os recursos refinados na Porcelana V2 Rosa através de componentes partilhados. Cada documento mantém o envelope, fontes, imagens decorativas, fundos e cores da sua composição. As secções acrescentadas, o formulário de confirmação e os presentes seguem a mesma identidade visual do modelo que os recebe.

## Conteúdo e recursos

| Área | Comportamento |
| --- | --- |
| Apresentação cerimonial | Famílias, noivos com alianças entrelaçadas, anúncio, destinatário e resumo opcional da mesa e lugares. |
| Cerimónias | Civil e religiosa opcionais, com data, hora, local e ligação para o mapa. Omitir a hora omite a cerimónia e o seu espaço. |
| Copo d'água | Texto, data, chegada dos noivos, corte do bolo, contagem decrescente, mapa e calendário com dados efectivos. |
| Programa | Itens e ícones configurados no editor; a secção respeita a sua visibilidade. |
| História e interlúdio | Textos, capítulos, autores, fotografia e enquadramento configurados; secções opcionais. |
| Informações úteis | Introdução, itens com ícones e secções livres autorizadas pelo modelo. |
| Galeria | As quatro fotografias de capa, história, interlúdio e acesso. A imagem clicada passa ao centro, abre ampliada e actualiza os pontos e o contador. |
| Presentes | A imagem animada original abre um modal centrado com texto, QR ou métodos bancários e os ícones escolhidos. Só aparece quando configurada. |
| Confirmação | Modal com o formulário real, convidados nominais e presença parcial. A demonstração não grava respostas. |
| Acesso | Código QR, nome do convite, resumo da mesa e textos configurados. |
| Música e movimento | Ícones distintos de reprodução e pausa escolhidos no editor, abertura do envelope, rotação da galeria e scroll automático comuns; movimento reduzido respeitado. |

A ordem das secções usa o índice do editor dentro da composição original, sem deslocar a capa, os ornamentos, o rodapé ou os modais para o meio do conteúdo. As fichas de capacidades dos onze pacotes declaram os novos campos e componentes. A liberdade atribuída pelo administrador continua a limitar o que os noivos podem editar.

Os modelos Kulemba anteriores também podem apresentar a secção opcional de presentes com uma imagem vectorial animada que abre os mesmos modos de conteúdo em modal, conservando as suas cores e tipografia.

## Origem dos dados

Os valores guardados pelos noivos prevalecem. Os dados ainda não personalizados usam o exemplo actual definido pelo administrador, incluindo nomes, evento, imagens, enquadramento e música. As provas do administrador, Ajuda e Demonstração usam esse conjunto de exemplo. Escolher um desenho na prévia dos noivos mantém os dados próprios do casal.

Os nomes, locais, datas, traduções secundárias e detalhes pessoais das referências deixam de aparecer nos modelos. As áreas que tinham fotografias de origem recebem as fotografias semânticas configuradas. Não são acrescentadas fotografias nem faixas musicais aos pacotes.

Uma cerimónia explicitamente omitida permanece omitida depois de guardar, voltar a abrir e visualizar a prévia. Os instantâneos das versões e os renderizadores anteriores continuam disponíveis.

## Validação

- `chk_modelos_componentes.js`: percorre os onze modelos com dados personalizados sem gravação, quatro fotografias, ordem das secções, campos opcionais, galeria e presentes em mobile e desktop; guarda capturas de revisão em `tests/output/componentes-modelos`.
- `chk_fase9_publicacao.js`: percorre os onze modelos em mobile, desktop e movimento reduzido, cobrindo envelope, música, mapa, calendário, QR, galeria e confirmação parcial, sem erros JavaScript nem escritas na API.
- `chk_dados_convite.php`: verifica no MySQL a precedência do exemplo e dos dados próprios e a persistência de cerimónias omitidas; termina sempre com rollback.
- `chk_presentes_modal.js`: verifica os modos texto, QR e métodos no modelo Kulemba em 1440, 390 e 320 px, incluindo centralização, fecho e retorno do foco.
- Provas estruturais de manifestos, pacotes, versões, recursos, presentes e secções opcionais anteriores; lint PHP e JavaScript.

As capturas são artefactos locais de teste e não são publicadas no repositório. Os testes usam prévias sem gravação e a prova transaccional não deixa dados de teste na base.
