# Fase 7 — troca segura de modelos e versões

Uma mudança de modelo aplica apenas a apresentação: composição, secções visíveis, molduras, ícones, efeitos, tipografia e paleta. O conteúdo semântico do casamento permanece intacto, incluindo nomes, data, locais, textos, cronograma, informações do copo d'água, fotografias, confirmação, presentes e acesso.

O mesmo princípio vale ao regressar à peça de origem. A operação já não repõe dados de fábrica por cima do conteúdo do casal.

As versões continuam a ser instantâneos completos que o utilizador guardou deliberadamente. Além das definições e do pacote visual, cada nova versão fixa agora a ficha de capacidades em vigor no momento da gravação. Uma alteração administrativa posterior ao modelo de origem não abre nem fecha ferramentas numa versão antiga. Versões anteriores, sem esta ficha, continuam válidas e herdam a configuração de origem como compatibilidade.

A exportação e importação de dados transportam também a ficha de capacidades da versão.

## Validação

Os testes distinguem conteúdo semântico de apresentação, verificam a persistência da identidade do renderizador e confirmam a nova coluna e o transporte da ficha editorial. `tests/chk_fase7_troca_segura.js` cria um casamento temporário, troca o modelo, confirma no convite renderizado que o texto do casal permaneceu e guarda uma versão protegida; no fim remove os dados de prova.
