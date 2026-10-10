# Fase 6 — gestão administrativa dos modelos

Ao criar um convite digital, o administrador escolhe agora a base visual entre todos os pacotes instalados. A lista é produzida pelo registo fechado do servidor; pacotes legados continuam disponíveis para versões antigas, mas não são oferecidos em modelos novos.

O modelo nasce com a ficha de capacidades do pacote escolhido. A janela de capacidades mostra apenas opções que esse pacote implementa e volta a validar a escolha no servidor ao guardar.

O conjunto de exemplo foi ampliado com o nome do convite, a mesa, o número de lugares e a lista das pessoas referenciadas. Estes valores juntam-se ao casal, evento e quatro fotografias já geridos pelo administrador. A prova do editor, o convite da demonstração e o formulário de confirmação usam agora o mesmo convite fictício, eliminando famílias e mesas escritas diretamente no código.

## Validação

`tests/chk_fase6_admin_modelos.php` verifica o catálogo dos doze pacotes disponíveis, a exclusão do pacote legado, o conjunto de exemplo completo e o catálogo administrativo limitado pelo pacote.
