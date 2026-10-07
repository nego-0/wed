# Pacotes de convite

Um modelo contém escolhas visuais, limites e capacidades. Um pacote contém o
código instalado que transforma essas escolhas no convite entregue ao
convidado. Esta separação permite acrescentar desenhos com estruturas
diferentes sem transformar o editor num único ficheiro cheio de condições.

## Identidade persistida

Cada modelo digital guarda três valores:

- `renderer_key`: identificador estável do pacote;
- `renderer_version`: versão semântica do código;
- `renderer_schema`: versão do contrato entre o pacote e a aplicação.

Os valores são transportados pelas exportações de modelos. Ficheiros antigos,
no formato `casamento-web/modelos/1`, continuam a entrar e recebem o pacote de
origem. O formato `casamento-web/modelos/2` conserva a identidade e recusa um
pacote que não esteja instalado.

## Registo fechado

`convite-pacotes.php` é a lista de pacotes autorizados pela aplicação. A base
de dados nunca escolhe caminhos nem fornece PHP ou JavaScript executável. A
resolução aceita apenas uma identidade registada e devolve caminhos calculados
pelo código, contidos na aplicação.

Cada versão instalada possui um `manifesto.json` com nome público em português,
âmbito, versão, esquema e adaptador. Uma versão publicada não deve ser alterada:
uma mudança incompatível cria outra versão e a anterior permanece disponível
para abrir peças históricas.

As versões guardadas pelos noivos fixam a mesma identidade. Comparar uma
versão com a peça em vigor considera o conteúdo e o renderizador; dois
instantâneos com os mesmos textos e fotografias, mas com estruturas diferentes,
deixam de ser confundidos. Exportar e importar um casamento transporta essa
identidade. Um ficheiro antigo, que ainda não a contém, recebe o pacote de
origem; uma identidade explícita só entra se o pacote estiver instalado.

## Primeiro pacote

`kulemba-contemporaneo@1.0.0` envolve o convite digital que já existia. O seu
adaptador aponta para `assets/convite-base.html`, pelo que esta migração atribui
uma identidade ao desenho actual sem alterar HTML, fotografias, animações ou
comportamento. O pacote passa a ser a origem dos modelos digitais antigos e dos
modelos da casa.

## Evolução prevista

Os novos pacotes reutilizam os dados semânticos e as capacidades do modelo. O
admin decide as capacidades concedidas aos noivos; o pacote decide como as
representar. Recursos transversais, como ícones, música, confirmação modal,
presentes e deslocamento automático, entram no contrato comum e podem ser
activados por cada modelo sem duplicar o editor.
