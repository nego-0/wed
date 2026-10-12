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

As pastas `assets/convite/modelos/chungdoi-exact/1.0.0` e `1.1.0` instalam os
onze pacotes. A versão 1.0.0 permanece intacta para abrir peças históricas. A
versão activa 1.1.0 corrige a integração sem reescrever os documentos: cada
pacote conserva as fontes, cores, fundos, molduras, ornamentos, ícones,
envelope, capa, efeitos, animações e galeria da composição visual comparada.

A referência aprovada da Porcelana V2 Rosa está congelada em
`tests/fixtures/convites/porcelana-v2-rosa`. O manifesto fixa o SHA-256 do HTML
e `tests/chk_referencia_porcelana_v2.js` falha se um byte mudar. A referência é
um critério de aceitação; não é usada para clonar o HTML da Porcelana sobre os
outros dez modelos.

Os documentos 1.1.0 recebem âncoras `data-exact-fragment` durante a preparação
controlada pelo script `tests/marcar_ancoras_exactas.js`. No browser, o runtime
usa apenas essas âncoras estruturais. Não procura títulos visíveis, não infere
secções pelo idioma, não ordena todos os nós e não apaga contentores nativos.
Cerimónia, galeria, copo d'água, programa e acesso seguem o fluxo validado na
Porcelana V2 Rosa, mas cada secção adopta a paleta e a tipografia do modelo em
que é montada.

As fotografias das fontes e as músicas que vinham incorporadas foram
eliminadas. Capa, história, interlúdio, acesso, galeria e música recebem sempre
os recursos semânticos definidos na Kulemba. A aplicação não lhes aplica a
estrutura nem a linguagem do Kulemba Contemporâneo:

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

O conteúdo visível está em português. Os nomes, data, fotografias, música
opcional, locais e presentes entram por chaves semânticas, sem alterar a
composição de cada modelo. O runtime oculta apenas os fragmentos de dados da
fonte assinalados no HTML e monta no mesmo lugar os dados do casamento ou do
exemplo definido pelo administrador. Assim, nomes, locais, mensagens e contas
bancárias dos documentos estudados nunca chegam ao convidado.

Envelope e capa continuam a pertencer a cada modelo. O contrato comum substitui
somente o conteúdo variável: nomes, data, convidado e restantes campos
autorizados. A abertura, o som, os ornamentos e o movimento permanecem os do
respectivo documento.

Sem secções opcionais, o fluxo de conteúdo é: grande dia, galeria, copo
d'água com mapa, programa, passe de entrada e confirmação. História,
interlúdio, manual, mensagens, presentes e blocos livres entram apenas quando
configurados. O lightbox da galeria é comum e acessível, mas os cartões, pontos
e animação continuam nativos. Presentes usam um único modal e nunca reutilizam
o diálogo bancário da fonte.

Cada manifesto contém a ficha que o editor consome: secções, painéis, campos,
recursos, efeitos e compatibilidade. O inspector mostra apenas capacidades que
o pacote declara e, para os noivos, cruza-as com as liberdades concedidas pelo
administrador. As fontes foram reduzidas às famílias realmente usadas pelos
onze desenhos; a validação estrutural impede referências partidas, música
incorporada e o regresso das fotografias de demonstração.

Os pacotes `chungdoi-exact@1.0.0` e `porcelain-v2@1.0.0`, publicados nas fases
anteriores, permanecem registados como legados. Não aparecem como novos modelos
no catálogo, mas continuam a abrir versões guardadas que tenham fixado essas
identidades. A migração 73 aponta apenas os modelos exactos de sistema para
1.1.0; não altera versões guardadas nem modelos criados pelos utilizadores.

### Regra das famílias

Partilhar a estrutura semântica não autoriza uniformizar a apresentação:
tipografia, cores, fundos, molduras, ornamentos, abertura, animações e ritmo
visual pertencem ao pacote. Uma secção transferida entre modelos recebe a
linguagem do modelo de destino; o admin decide que capacidades expõe aos
noivos.

## Evolução prevista

Os novos pacotes reutilizam os dados semânticos e as capacidades do modelo. O
admin decide as capacidades concedidas aos noivos; o pacote decide como as
representar. Recursos transversais, como ícones, música, confirmação modal,
presentes e deslocamento automático, entram no contrato comum e podem ser
activados por cada modelo sem duplicar o editor.
