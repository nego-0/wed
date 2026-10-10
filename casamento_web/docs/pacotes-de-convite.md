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

## Onze modelos de composição própria

A pasta `assets/convite/modelos/chungdoi-exact/1.0.0` instala onze pacotes
independentes. Cada pacote conserva o documento, a ordem das páginas, as
fontes usadas, cores, ornamentos, ícones, envelope, efeitos e animações da
composição visual que foi comparada e aprovada. As fotografias de demonstração
e as músicas que vinham incorporadas foram eliminadas: capa, história,
interlúdio, acesso e música são sempre recursos semânticos escolhidos na
Kulemba. A aplicação não lhes aplica a estrutura nem a linguagem do Kulemba
Contemporâneo:

- **Jasmim Branco** (`jasmine-white`);
- **Real V2 Verde** (`royal-v2-green`);
- **Jardim Primaveril Azul** (`spring-garden-blue`);
- **Dupla Felicidade Verde** (`double-happiness-green`);
- **Porcelana Castanha** (`porcelain-brown`);
- **Real Azul** (`royal-blue`);
- **Porcelana V2 Verde** (`porcelain-v2-green`);
- **Flor Seca Laranja** (`hoa-kho-orange`);
- **Mahal Dourado** (`mahal-gold`);
- **Porcelana V2 Rosa** (`porcelain-v2-pink`);
- **Lótus Rosa** (`lien-hoa-pink`).

O conteúdo visível está em português. Os nomes, a data, as fotografias, a
música opcional, os locais e os dados de presentes entram por chaves
semânticas, sem alterar a composição de cada modelo. Cerimónia, copo d'água,
programa, galeria, calendário, mapa, rolagem automática, presentes, acesso e
confirmação usam o contrato comum. Se o administrador não configurar presentes
ou mensagens, a respectiva área não é desenhada; nenhum dado bancário de
exemplo chega ao convidado. A confirmação abre no modal Kulemba e o formulário
de envio de mensagens foi retirado dos onze modelos, conforme a decisão
funcional do produto.

Cada manifesto contém a ficha que o editor consome: secções, painéis, campos,
recursos, efeitos e compatibilidade. O inspector mostra apenas capacidades que
o pacote declara e, para os noivos, cruza-as com as liberdades concedidas pelo
administrador. As fontes foram reduzidas às famílias realmente usadas pelos
onze desenhos; a validação estrutural impede referências partidas, música
incorporada e o regresso das fotografias de demonstração.

`porcelain-v2@1.0.0`, publicado na fase anterior, permanece registado como
pacote legado. Não aparece como novo modelo no catálogo, mas continua a abrir
versões guardadas que tenham fixado essa identidade. Assim, a correcção visual
dos modelos novos não reinterpreta nem apaga peças históricas.

### Regra das famílias

Partilhar o contrato semântico não autoriza uniformizar a apresentação: ordem,
tipografia, cores, ornamentos, abertura, animações e ritmo visual pertencem ao
pacote. Uma secção transferida entre modelos recebe a linguagem do modelo de
destino; o admin decide que capacidades expõe aos noivos.

## Evolução prevista

Os novos pacotes reutilizam os dados semânticos e as capacidades do modelo. O
admin decide as capacidades concedidas aos noivos; o pacote decide como as
representar. Recursos transversais, como ícones, música, confirmação modal,
presentes e deslocamento automático, entram no contrato comum e podem ser
activados por cada modelo sem duplicar o editor.
