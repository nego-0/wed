# Gestão de Convidados — Isabel & Abednego

Sistema em PHP + MySQL para gerir os convidados do casamento: criação de convites (digitais e físicos), geração dos nomes a exibir, confirmação de presença (RSVP), código QR de entrada e página do porteiro para validar convites à porta do evento.

O sistema foi desenhado para **coexistir** com a sua lista atual: cria tabelas novas com o prefixo `cw_` e não altera as tabelas antigas (`guests`, `invite_groups`, `mesas`). Pode, num clique, importar a lista existente para o novo formato.

---

## Ficheiros

| Ficheiro | Função |
|---|---|
| `config.php` | Configuração do evento (data, hora, local, WhatsApp). **Não contém segredos** — pode ir para o Git. |
| `config.local.php` | **Segredos:** ligação à base de dados e utilizadores (nome + senha). **Não é versionado** (ver `.gitignore`). Crie-o a partir de `config.local.example.php`. |
| `db.php` | Ligação, criação automática das tabelas e funções partilhadas. |
| `auth.php` | Autenticação por sessão, por **nome de utilizador + senha** (administrador e porteiro). |
| `api.php` | Todos os pedidos JSON (gestão, RSVP público e porteiro) e exportação CSV. |
| `login.php` / `logout.php` | Entrada e saída. |
| `registo.php` | **Inscrição pública** de um casal: cria a conta e o casamento em espera, e não abre a porta a ninguém — quem aprova é o admin da plataforma. |
| `plataforma.php` | Os casamentos que o sistema serve: fila de aprovação, criação de casamentos, gestão de contas e (para o suporte) a entrada por código. |
| `equipa.php` | Quem entra neste casamento (noivos, porteiro), os códigos de suporte que o casal gera e revoga, e a mudança da própria senha. |
| `manifest.php` | O manifesto da aplicação da porta, com o nome do casamento aberto. |
| `parcial-endereco.php` | A barra do endereço público, onde se geram links e QR. |
| `index.php` | Painel de administração (convites, convidados, mesas, importação, QR). |
| `mesas.php` | Planta visual das mesas: posição (arrastar), capacidade e ocupação, com atribuição de convites. |
| `convite-editor.php` | **Personalização completa do convite digital**: textos, história, cronograma, manual, fotos, música, cores e efeitos — com pré-visualização ao vivo. |
| `personalizacao.php` | Motor da personalização: valores originais, validação e composição do convite. |
| `convite.php` | Página pública de confirmação de presença + passe de entrada com QR. |
| `porteiro.php` | Página do porteiro: leitura de QR por câmara e busca manual. |
| `impressos.php` | Etiquetas dos convites físicos com QR, prontas a imprimir (acessível a partir de *Gráfica*). |
| `cartoes.php` | **Cartão de convite 10×15 cm** (um por convidado), para impressão a dourado sobre acrílico (acessível a partir de *Gráfica*). |
| `porta-chaves.php` | **Porta-chaves comemorativo** 45×60 mm: peça 3D virável, com escolha de acabamento e da quadra do verso (acessível a partir de *Gráfica*). |
| `graficas.php` | **Entregáveis à gráfica**: lista de produção dos convites físicos, brindes por género e manuais de impressão. |
| `editor-cartao.php` | **Editor do convite físico**, ao estilo de um editor de imagem: camadas, propriedades e pré-visualização ao vivo. |
| `editor-brindes.php` | **Editor dos brindes**: peça por género, variações disponíveis à gráfica e quantidade de cada uma. |
| `assets/editor.css` | Ambiente dos editores (barra de ferramentas, mesa de trabalho e painéis). |
| `manual.php` | **Manual de impressão gerado** (cartão e porta-chaves): reflete a configuração atual, imprimível em A4. |
| `pecas.php` | Biblioteca das peças de design: paletas, geradores de SVG (folhagem, volutas, floreados), monograma, faces do porta-chaves e modelo dos brindes. |
| `assets/pecas.css` | Estilos das peças (cartão e porta-chaves), partilhados pelas páginas que as desenham. |
| `assets/pecas/` | Biblioteca vetorial das peças (ícones SVG recoloráveis) e a imagem dos corações entrelaçados. |
| `assets/estilo.css` | Estilo visual, alinhado com o convite (verde-floresta, dourado e marfim). |

---

## Estrutura da base de dados (tabelas novas)

- **`cw_convites`** — o convite é a unidade central: código único, nome a exibir, sufixo opcional, tipo (`digital`/`fisico`/`ambos`), lado, número de lugares, mesa, telefone, estados de RSVP e de entrada, mensagens e observações.
- **`cw_convidados`** — as pessoas nominais de cada convite (com RSVP e presença individuais).
- **`cw_mesas`** — mesas com capacidade e ocupação.
- **`cw_casamentos`** — quem é quem: nome, noivos, data, estado (`pendente`/`ativo`/`suspenso`/`arquivado`) e o endereço público por onde os convidados chegam.
- **`cw_utilizadores`** — as contas (email, senha cifrada, papel na plataforma, estado).
- **`cw_acessos`** — quem entra em que casamento, e como (`noivos` / `porteiro`).
- **`cw_suporte_codigos`** — as chaves temporárias que o casal dá ao suporte.

Todas as tabelas de dados levam `casamento_id`. A ligação à base **audita cada
instrução**: uma consulta que mexa nos dados de um casamento sem dizer de qual
rebenta nas provas (`AMBITO_ESTRITO=1`) e fica no log em produção.

---

## Vários casamentos, várias contas

A casa serve vários casais ao mesmo tempo. Há dois níveis de papéis, e convém
não os confundir:

| Nível | Papéis | O que pode |
|---|---|---|
| No casamento | `noivos` | gere tudo o que é desse casamento |
| No casamento | `porteiro` | só a porta: procurar convites e registar entradas |
| Na plataforma | `admin` | vê todos os casamentos, aprova inscrições, gere contas |
| Na plataforma | `suporte` | **nada, por direito próprio** — só entra com um código que o casal lhe der |

**Como entra um casal novo.** Inscreve-se em `registo.php`. A conta e o
casamento ficam `pendente`, e a entrada recusa-lhe o acesso — de propósito. O
admin da plataforma vê a inscrição na fila em `plataforma.php` e aprova-a;
aprovar o casamento ativa, no mesmo gesto, a conta de quem se inscreveu.

**Como o suporte ajuda.** O casal gera, em `equipa.php`, um código que diz se
dá para **ver** ou para **ver e corrigir**, e por quantos dias. Entrega-o. O
suporte escreve-o em `plataforma.php` e passa a acompanhar aquele casamento,
com uma tira no cabeçalho que não deixa esquecer em casa de quem está. Um
código de leitura recusa qualquer escrita, no servidor. Revogar fecha a porta
**já** — inclusive a quem estava lá dentro nesse momento.

Num código de leitura o ecrã acompanha a fechadura: `assets/so-ver.js` desliga
os controlos que iriam bater com o nariz na porta e deixa vivos os que só
mostram. Descobre-os lendo o próprio código da página, contra a mesma lista de
ações que o servidor usa (`acoesDoCasamento()`, em `config.php`) — não há como
uma ficar para trás da outra. Uma página pode desmentir a descoberta com
`data-escrita="0"` (só abre coisas que escrevem, como o menu "…") ou
`data-escrita="1"` (a leitura do código não lá chega). O que escapar continua
a ser recusado, com mensagem, antes de sair do navegador.

Os **gestos** não têm botão que se apague — arrastar uma mesa, largar uma
pastilha noutra, escolher numa lista que se abre. Aí é a própria página que
sabe quais deles escrevem: `assets/mesas.js` pergunta antes de armar o gesto, e
a planta apresenta-se fixa (o mesmo caminho que já tinha para "mesas fixas"),
com tudo à vista e tudo tocável. As caixas do bloqueio continuam a mostrar o
que o **casal** configurou — trocá-las pela nossa trava dava a quem vem ajudar
uma leitura errada da planta alheia.

**Senhas.** Cada pessoa muda a sua em `equipa.php`. Não há envio de correio
configurado, e prometer um email que nunca chega seria pior: quando alguém
perde a senha, o admin da plataforma repõe-na e recebe no ecrã, uma vez, uma
senha temporária para lha entregar.

**O endereço público.** Os QR e os links dos convites são absolutos e, uma vez
impressos, são para sempre. Cada casamento tem o seu endereço, fixado na barra
que aparece nas páginas que geram links e QR — que avisa, antes de imprimir,
quando o endereço só existe na máquina de quem o está a ver.

---

## Instalação no InfinityFree (ou outro alojamento)

1. Carregue todos os ficheiros (incluindo a pasta `assets/`) para a pasta pública do site (`htdocs`).
2. **Crie o `config.local.php`** (é o único ficheiro com segredos): copie o modelo e preencha-o —
   `cp config.local.example.php config.local.php`. Indique lá os dados de ligação à base de dados
   em `db['online']` e os utilizadores. Faça upload do `config.local.php` para o servidor, a par do `config.php`.
3. Abra o site no navegador. As tabelas `cw_` são criadas sozinhas na primeira visita.

O sistema tenta primeiro a ligação `local` (útil para testes em XAMPP/Wamp) e, se falhar, usa a `online`.

> **Porque é que os segredos estão separados?** Assim o código (incluindo o `config.php`) pode ir para
> o Git/GitHub sem expor palavras-passe. O `config.local.php` está listado no `.gitignore` e nunca é
> versionado — existe apenas no seu computador e no servidor.

---

## Antes de publicar — ajustes

Em `config.local.php` (segredos):

- **Utilizadores:** defina `utilizador` e `senha` (ou `senha_hash`) para as contas `admin` e `porteiro`.
  O administrador acede a tudo; o porteiro só acede à página de entrada. **Mude as senhas por defeito.**
  Para uma senha em hash (recomendado): `php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"`
- **Base de dados:** confirme `db['online']` (host, utilizador, palavra-passe, base).

Em `config.php` (não sensível):

- **Hora da cerimónia:** o campo `EVENTO['hora']` está como `16:00` — ajuste para a hora real.
- **WhatsApp de contacto:** `EVENTO['whatsapp']` está com um número de exemplo — coloque o número real (formato internacional, só dígitos, ex.: `244923000000`).

---

## Primeira utilização

1. Entre com o **nome de utilizador e a senha** de administrador (definidos em `config.local.php`).
2. Se a lista antiga for detetada, aparece um aviso **“Importar a sua lista atual”**. Ao importar:
   - cada grupo de convite (ex.: *Família Agostinho*) torna-se um convite físico com os seus membros;
   - cada convidado sem grupo torna-se um convite digital individual;
   - confirmações, telefones e mesas são preservados.

---

## Como funciona

**Criar um convite.** Indique os nomes reais dos convidados; o sistema sugere automaticamente o nome a exibir (ex.: *Família Agostinho*, *Ana e Bruno*). Esse nome pode ser diferente dos nomes reais.

**Género e brinde.** Cada integrante pode ter um **género** (masculino/feminino) e a opção **"Recebe Brinde"** 🎁, definidos no editor do convite. No painel há **cartões de estatística** para *Masculino*, *Feminino* e *Brindes* (com a contagem de convidados), que também servem de **filtro** ao clicar. As **pastilhas com os nomes** (no painel e na gestão de mesas) mostram **ícones sugestivos**: ♂ (masculino), ♀ (feminino) e 🎁 para quem recebe brinde.

**Regra do número entre parênteses.** Se o convite for para **um só lugar**, mostra apenas o nome. Para mais do que um, acrescenta *(N)* lugares — ou o texto que escrever no campo **Sufixo** (ex.: *e acompanhante*).

**Tipo do convite.** *Digital*, *Físico* ou *Ambos*. Todos os convites têm sempre um link e um QR; o tipo serve para organizar a produção (marcar como *impresso* / *enviado*).

**Fixar mesas e canvas.** Por cima da planta, à esquerda dos botões de zoom, há duas opções — **Fixar mesas** e **Fixar canvas** — que travam o arrasto das mesas e o redimensionar do canvas, para evitar deslocações acidentais depois de a disposição estar definida. Ficam **guardadas na base de dados**, pelo que se mantêm entre sessões. Com as mesas fixas continua a poder **tocar numa mesa para ver os detalhes**; só o arrasto é que fica travado.

**Mesas.** Na página *Mesas* tem uma **planta visual do salão**: crie mesas com capacidade, escolha a **forma** (redonda, oval, quadrada, retangular, comprida ou em ferradura), uma **cor** de uma paleta e a **dimensão** (automática, pequena, média ou grande), **arraste-as** para a sua posição real e veja a ocupação através de um ponto de estado (vazia, a encher, completa, excede a capacidade). O formulário de adicionar mesa fica por cima do canvas; ao selecionar uma mesa, a sua aba abre um formulário de edição compacto. As posições ficam guardadas.

**Mesa dos noivos.** A planta tem, por padrão, uma **pastilha especial** dos noivos — com uma ilustração própria (as alianças entrelaçadas) — para representar o casal, com a **mesma dimensão** das restantes mesas. Só existe uma. Pode **eliminá-la** (no painel da mesa) e, se quiser, **repô-la** no botão *Mesa dos noivos* que surge por cima do canvas. As suas **alas laterais** (padrinhos à esquerda, madrinhas à direita) são preenchidas **automaticamente pelo papel de cada convidado**: quem tiver o papel *Padrinho* entra na ala esquerda e quem tiver *Madrinha* na ala direita, sem atribuição manual. O papel define-se no **editor do convite** (cada integrante tem um seletor *Convidado / Padrinho / Madrinha*) ou diretamente no painel da mesa dos noivos. **Só padrinhos e madrinhas** podem ficar nesta mesa — nenhum outro convidado lhe pode ser atribuído.

**Zoom.** A **barra de zoom** tem três níveis: **50%** (vista ampla), **100%** (vista panorâmica padrão) e **150%** (vista de área). O **canvas mantém sempre o mesmo tamanho** — o zoom não altera o canvas, apenas amplia ou reduz o conteúdo dentro dele: a 50% as mesas ficam mais pequenas no mesmo espaço, a 100% preenchem-no e a 150% ficam ampliadas, com deslocamento (scroll) para percorrer o salão.

**Dimensões do canvas.** Pode **redimensionar o canvas arrastando as suas bordas** — a borda direita (largura), a inferior (altura) ou o canto inferior direito (ambas). O tamanho escolhido é **guardado na base de dados** e reposto nas visitas seguintes. As **barras de deslocamento só aparecem quando o conteúdo não cabe** (por exemplo, ao ampliar para 150%); quando tudo está visível, ficam ocultas.

**Maximizar a planta.** O ícone de **maximizar** (junto à barra de zoom) expande a tela de disposições ao ecrã inteiro, **mantendo o conjunto de abas ao lado**. Toque novamente no ícone (ou prima **Esc**) para restaurar.

**Dividir um convite por várias mesas.** Cada pessoa de um convite pode ficar numa mesa diferente. Há várias formas de o fazer: no painel de uma mesa (cada pessoa tem um seletor de mesa), **arrastando** um cartão da lista *Pessoas* (abaixo da planta) para uma mesa, **arrastando uma pessoa de uma mesa para outra diretamente na planta** (ao selecionar uma mesa, as suas pessoas surgem como pastilhas arrastáveis), ou no **editor do convite** (no painel principal, cada integrante tem um seletor de mesa — *"Mesa do convite"* por omissão). A mesa do convite continua a ser o padrão (para quem não tem mesa própria e para os lugares sem nome). A ocupação de cada mesa conta as pessoas pela sua mesa efetiva. No painel principal, um convite dividido aparece marcado como *"Dividido · N mesas"*.

**Mesas no convite.** O convite (digital, físico e a página de confirmação) menciona **todas as mesas** dos seus integrantes, com o número de lugares em cada uma — ex.: *"Mesas: A (1 lugar) e B (4 lugares)"*. No editor há a opção *"Mostrar o número de lugares por mesa no convite"*, que liga/desliga o *(N lugares)* junto a cada mesa **tanto no convite digital como no físico** (cartões).

**Painel de abas.** Ao lado do canvas há um conjunto de abas — *Pessoas*, *Convites*, *Sem mesa* e (ao selecionar uma mesa) a aba dessa mesa com os seus detalhes e convidados. As abas *Pessoas*/*Convites* têm pesquisa e filtro por estado de RSVP; cada cartão pode ser arrastado para uma mesa da planta. A criação de mesas fica num formulário compacto por cima das abas. As listas longas do painel de uma mesa (mudar de mesa, trazer uma pessoa, sentar um convite, nomear padrinho/madrinha) são **dropdowns de pesquisa**: abrem uma caixa com campo de procura e resultados filtrados à medida que escreve.

**Ao posicionar as mesas** surgem **linhas-guia magnéticas** que alinham a mesa que arrasta com o centro das outras (ou do salão). Ao selecionar uma mesa, as suas pessoas aparecem como pastilhas na planta que se arrastam para outra mesa.

**Confirmação de presença (RSVP).** Cada convite tem um link único (`convite.php?c=CÓDIGO`). O convidado confirma se comparece, quantos lugares e quem, e pode deixar uma mensagem. Ao confirmar, recebe o **passe de entrada com QR**.

**Porteiro.** Na página de entrada, o porteiro lê o QR com a câmara ou procura pelo nome/código. Vê o estado do convite e regista a entrada (de todos ou de cada pessoa). **Junto a cada pessoa aparece a sua mesa**, e se o convite estiver dividido por mesas surge um aviso com as mesas envolvidas — para o porteiro poder orientar cada convidado. O contador de presenças atualiza em tempo real.

**Convites físicos.** A página *Convites físicos* gera as etiquetas com o nome e o QR de cada convite, prontas a imprimir para os envelopes.

**Impressão.** Ao imprimir os cartões, cada convite sai numa página de **100 × 150 mm**, sem fundo (o acrílico é transparente: só o dourado é impresso) e sem o cromo da aplicação. *Imprimir só este* dá uma única página. A vista de um só cartão mostra-o em grande no ecrã.

**Cartões 10×15.** A página *Cartões 10×15* gera o **convite propriamente dito**, um por convidado, no formato 100×150 mm concebido para **impressão UV a dourado sobre acrílico transparente** (sem fundo impresso). Cada cartão é personalizado a partir da base de dados: o **nome tal como aparece no convite** e as **mesas do convidado com o número de lugares** — respeitando as opções “mostrar o número” e “mostrar o nº de lugares” de cada convite. Um convite dividido por várias mesas mostra uma coluna por mesa. Pode escolher a **paleta** (ouro quente, verde sálvia, terracota, rosa antigo) e a **folhagem** das trepadeiras (eucalipto, oliveira, feto, florido); o botão *Guardar estilo* fixa a escolha como predefinição. A folhagem, as volutas de canto e os floreados são **desenhados por código** (SVG gerado no servidor), pelo que acompanham a cor da paleta. Ao imprimir, cada cartão sai numa página de 100×150 mm.

**Entregáveis à gráfica.** A página *Gráfica* reúne, em três separadores, tudo o que a gráfica precisa de receber. É a **porta de entrada única** para as peças: os cartões 10×15, as etiquetas e o porta-chaves deixaram de ter menu próprio e chegam-se a partir daqui.

- **Convites físicos** — lista de produção simplificada: nome do convite tal como é impresso, mesas com o nº de lugares, código e **QR**. **Ao clicar numa linha, o modelo do cartão abre expandido** (ampliado até caber no ecrã), com ligação para o imprimir; *Esc* fecha. A lista é pesquisável e imprimível, e dá acesso a todos os modelos e às etiquetas para envelopes.
- **Brindes** — o brinde **atribuído a cada género** e as suas **variações**. De origem, o género masculino recebe o **porta-chaves**, cujas variações são as **frases do verso**: cada variação é mostrada tal como será produzida. Segue o **plano de tiragem do handoff** — **70 unidades** repartidas em 6 lotes de 9 e 2 de 8 —, ajustável no editor. Indica quantos convidados recebem o brinde (dos que estão marcados como *Recebe brinde*), quantas variações existem e como se distribuem. Convidados marcados para receber brinde mas **sem género definido** são assinalados à parte, para não passarem despercebidos. O género feminino fica como *por definir* até lhe ser atribuída uma peça.
- **Manuais** — os manuais de impressão de cada peça, **gerados a partir da configuração atual**: acompanham as edições. Levam as especificações de produção (formato, sangria, tinta, linhas mínimas), a **paleta em uso com os códigos de cor**, a tipografia convertida em mm e pt, **quais os elementos a imprimir** (assinalando os que foram desligados nos editores), os textos tal como estão, e — no porta-chaves — o acabamento e a **tabela das variações escolhidas com a quantidade de cada uma**, mais provas visuais da peça. São imprimíveis em A4. Os manuais ilustrados que vieram com o design continuam acessíveis à parte, como referência do original.

**Editar o convite físico.** Em *Gráfica → Convites físicos → Editar o cartão* abre-se um editor ao estilo de um editor de imagem: barra de ferramentas à esquerda, o cartão numa mesa de trabalho ao centro e, à direita, os painéis de **Propriedades** e **Camadas**.

- **Camadas** — as doze partes do cartão (trepadeiras, volutas, moldura, floreados, abertura, nomes, frase, bloco do convidado, mesas, data, logística e frase final). O **olho** mostra ou oculta cada uma; a lista distingue camadas de texto (T) das decorativas (◈).
- **Selecionar** — clicar numa camada, ou **diretamente no cartão**, marca-a e abre as suas propriedades.
- **Propriedades** — os textos dessa camada. O que se escreve aparece **imediatamente no cartão**.
- No **bloco do convidado** há ainda a opção **mostrar o “(N)” de lugares no nome**. Por omissão segue o convite, mas pode desligar-se: no cartão os lugares já aparecem por baixo de cada mesa, pelo que o número no nome é opcional.
- **Barra de opções** — paleta, folhagem e zoom (com *Ajustar à janela*). A troca de paleta e de folhagem é instantânea, sem ir ao servidor: as cores são variáveis CSS e as trepadeiras já vêm todas carregadas.
- **Ferramentas** — selecionar (V), texto (T), mão para arrastar a vista (H) e zoom (Z); `0` ajusta à janela.
- A prova usa um convite real, para se ver o resultado com nomes e mesas verdadeiros. *Guardar* grava; *Repor originais* devolve tudo ao estado inicial. Sair com alterações por guardar pede confirmação.

**Editar os brindes.** Em *Gráfica → Brindes → Editar brindes*, no mesmo ambiente:

- Cada **género** é um documento (separadores em cima) e tem uma **peça** atribuída, escolhida de um catálogo. O sistema está **aberto a novas peças**: uma peça nova encaixa em três pontos, todos em `pecas.php` — a entrada no catálogo (`brindesPecas()`), a fonte das suas variações (`pecaVariacoes()`) e o seu desenho (`pecaPreVisualizacao()`). O editor pede a pré-visualização ao servidor, pelo que qualquer peça registada aparece aqui sem mais alterações.
- **Variações disponíveis** — a lista das variações da peça (no porta-chaves, as frases do verso). O visto define **quais ficam disponíveis para a gráfica** e o campo ao lado **quantas produzir de cada uma**. Clicar numa variação mostra-a na mesa de trabalho.
- **Produção** — resume a peça, quantos convidados recebem o brinde, quantas variações estão ativas e o total a produzir, avisando se **faltam** peças ou se há **reserva** a mais. *Repartir pelos convidados* distribui automaticamente o total pelas variações ativas.
- Só as variações ativas (e as suas quantidades) chegam à página da gráfica.

**Porta-chaves.** A página *Porta-chaves* apresenta a lembrança em acrílico de dois lados (45×60 mm) com o monograma do casal: a peça **inclina-se com o cursor** e **vira ao clique**. Pode escolher o **acabamento** (ouro sobre ébano, floresta, marfim) e a **quadra** do verso (8 à escolha, com as coordenadas do local por baixo). O monograma, o anel guilhoché e os ornamentos são gerados a partir das iniciais e da data do evento.

**Personalizar o convite digital.** Na página *Convite digital* pode alterar **tudo** o que aparece no convite, sem tocar em código: nomes do casal, data e hora (que passam a valer também na página de confirmação), local e ligação do Google Maps, todos os textos (pode usar `{noiva}`/`{noivo}`, `**negrito**` e `*itálico*`), os capítulos da história, os momentos do cronograma e as regras do manual (com escolha de ícones), a visibilidade de secções inteiras (história, interlúdio, cronograma, manual), as **fotografias e a música** (enviadas do computador; as imagens são comprimidas automaticamente), a **paleta de cores** (4 paletas prontas + ajuste fino de cada cor; o QR acompanha) e os efeitos (pétalas, música automática). A pré-visualização ao lado atualiza ao guardar. Campos repostos ao valor original deixam de ocupar espaço na base de dados. Cada convite pode ainda ter uma **mensagem pessoal** (campo no editor de convites do painel).

---

## Notas importantes

- **Funciona sem internet.** As bibliotecas (leitor de QR e gerador de QR) e os tipos de letra são servidos localmente a partir da pasta `assets/` — não há dependências de CDN. Assim, a página do porteiro continua a funcionar mesmo que a ligação à internet falhe no local do evento.
- A leitura de QR por câmara exige **HTTPS** (regra do navegador, não do sistema). No InfinityFree, ative o certificado SSL grátis e aceda por `https://`. Em rede local de testes, `localhost` também é aceite.
- Editar um convite preserva as confirmações e presenças já registadas.
- O check-in à entrada só altera a presença — nunca apaga a confirmação feita pelo convidado.
- O código QR aponta para o link do convite, pelo que serve tanto ao convidado (abre o seu convite) como ao porteiro (identifica-o à entrada).
- O sistema não depende de extensões opcionais (mbstring, gd, intl), pelo que funciona em alojamento partilhado simples.
