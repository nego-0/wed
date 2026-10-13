# Fase 8 — demonstração e ajuda ligadas à interface real

A demonstração pública deixou de reconstruir o convite Kulemba Contemporâneo à parte. `demonstracao-convite.php` abre agora o mesmo renderizador usado pelos convidados, com o modelo de origem escolhido pelo administrador e com a fonte global de dados de exemplo. Parâmetros externos são descartados para a montra nunca revelar uma peça, uma versão ou um casamento real.

A confirmação da montra também reutiliza o formulário real. Mostra a lista de convidados definida nos dados de exemplo, respeita o estilo do modelo e percorre a resposta completa, mas termina localmente sem enviar dados à API. A demonstração pode assim ser usada sem conta e sem alterar contagens ou o registo de acções.

O gerador das capturas da Ajuda e da Demonstração deixou de impor os nomes Marta e Pedro. Antes de captar a interface, lê a identidade configurada pelo administrador e aplica-a ao casamento técnico usado pelas páginas operacionais. As imagens resultantes continuam separadas em desktop e mobile, com alvos medidos para cada passo.

## Validação

`tests/chk_fase8_demonstracao_ajuda.js` abre a peça pública sem sessão, confirma que um documento de modelo real foi montado, abre o formulário em modal, verifica a lista nominal e conclui uma resposta sem pedidos de escrita. As suites da central verificam as 84 cenas, os stickers semânticos, o modo escuro e a ausência de transbordo.
