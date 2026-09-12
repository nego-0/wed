# Bar — sexta passagem: o motor assistido

**Prompt de implementação.** Escrito contra o estado actual do módulo (esquema
v39, 78 provas verdes, `docs/modulo-bar.md` §1–§30). Quem o executar deve ler
primeiro o §8 (as regras) e o §30 (a passagem anterior) desse documento.

---

## 0. Porque é que este prompt é mais curto do que o pedido

O pedido original descreve um sistema inteiro. Grande parte dele **já está
construída** — e a parte que falta é menor do que parece, mas é a mais
importante: hoje o motor **trava**, e o que se quer é que ele **proponha**.

O que se segue diz três coisas, por esta ordem: o que já existe e não se toca,
o que é mesmo novo, e o que se deixa de fora de propósito.

---

## 1. O que já existe — não reconstruir

| Pedido | Onde já vive |
|---|---|
| Três grupos (admin · copeiro · convidado/garçom) | `podeCopa()`, `podeEntregar()`; §2 |
| Motor que lê estado da copa, stock, ritmo, pedidos recentes, janelas, restrições de bebida, de convidado e de convite | `barVeredicto()`, `barConsumo()`, `barRitmoDaCasa()`, `barLimiteQueManda()`; §8 |
| Hierarquia de regras com resolução de conflito | `barLimiteQueManda()` — nove degraus, do mais específico ao mais geral; §8.0 |
| Janelas temporais deslizantes | `janela_min` + `p.criado_em >= NOW() - INTERVAL n MINUTE` |
| Aceitação parcial e redução automática | `bar_decidir` com `cortes`; o `pode` do veredicto já limita o convidado antes de pedir; §27.4 |
| Resultados do pedido: aceite · aceite em parte · aguarda copa · recusado | os estados `em_analise` / `aprovado` / `recusado`, e `aprovado` com cortes |
| Justificativa interna **separada** da pública | `bar_limites.nota` (só o pessoal) vs `bar_limites.mensagem` (o convidado); §9 |
| Motivos de recusa configuráveis | tabela `bar_motivos` |
| Suspender uma bebida e ela voltar sozinha | regra com `expira_em`; travão `suspensa`; §30.3 |
| Previsão de esgotamento | `barRutura()` — «acaba em 40 min» ao ritmo dos últimos 20 |
| Painel da noite em tempo real | aba «Os números»: estado, tempos, consumo, ritmo, rutura, recusas, mesas, por pessoa, caudal |
| Auditoria de tudo o que se faz | `registar()` → `cw_logs`, com nome e detalhe por acção (`nomesDeAcao()`) |
| Editar a regra a partir de onde o problema se vê | ficha da pessoa, ficha da bebida, barra de «Os números»; §30.4 |
| Regras absolutas, sem porta de serviço | `barTravaoDe()` em todas as portas, balcão incluído; §30.2 |

**Sobre os «limites por evento».** O pedido manda-os abolir. Já não existem: o
módulo não conhece nada maior do que a noite — abre-se com `bar_abrir`,
fecha-se com `bar_fechar`, e `janela_min = 0` quer dizer «ao todo, nesta
noite». Não há nada a remover nem a renomear.

---

## 2. O que é mesmo novo

Cinco coisas. Tudo o resto do pedido é uma destas cinco, ou já existe.

### 2.1 O modo de execução de uma regra

Hoje toda a regra é absoluta: trava no momento do pedido e mais nada. Passa a
haver **uma** coluna `modo` em `bar_limites`, com quatro valores:

| modo | O que o motor faz |
|---|---|
| `trava` | Recusa no acto, como hoje. **É o valor de fábrica** — nenhuma regra já escrita muda de comportamento. |
| `sugere` | Não trava. Quando a condição se cumpre, levanta um alerta com uma acção proposta. |
| `confirma` | Não trava, mas o alerta fica aberto até alguém responder — não caduca sozinho. |
| `avisa` | Levanta o alerta sem propor acção nenhuma. |

> **Um eixo, não dois.** O pedido traz também `ABSOLUTA / RECOMENDADA /
> AJUSTÁVEL / AUTOMÁTICA`, que é a mesma pergunta feita outra vez: «absoluta» é
> `trava`, «automática» é `trava`, «recomendada» é `sugere`, e «ajustável» é uma
> propriedade de quem edita (o copeiro pode editar qualquer regra desde §29), não
> da regra. Duas classificações sobrepostas são duas coisas para o copeiro
> perceber à uma da manhã, e duas maneiras de elas discordarem.

### 2.2 Os alertas, e o painel onde se decidem

Tabela nova, `bar_alertas`. Um alerta é uma **proposta com data**, não um log:

```
id · casamento_id · regra_id (pode ser NULL) · tipo · nivel
situacao   — o retrato numérico do momento (JSON: o que se mediu)
sugestao   — a acção proposta (JSON: {accao, parametros})
estado     — aberto | aplicado | ignorado | adaptado | caducado
decidido_por · decidido_em · nota · criado_em
```

Níveis: **`aviso`**, **`atencao`**, **`critico`**. Três, e não quatro: no
pedido, «IMPORTANTE» e «CRÍTICO» querem os dois dizer *decida agora*.

Acções propostas (o conjunto fechado — nada fora desta lista):

`pausar_copa` · `reabrir_copa` · `fechar_copa` · `suspender_bebida` ·
`baixar_max_por_pedido` · `apertar_limite` · `travar_convidado` ·
(e `nenhuma`, para o modo `avisa`)

O copeiro tem sempre as mesmas quatro saídas: **Aplicar · Adaptar · Ignorar ·
Ver a regra**. «Ir directamente à regra» e «alterar a regra» do pedido são a
mesma saída — a janela da regra já abre a editar (§29.5).

O painel vive numa aba nova da copa, **antes** de «Por decidir»: é a primeira
coisa que se vê, e traz o número por decidir na pastilha. Um alerta por linha,
com o nível à esquerda, a situação em palavras, a acção proposta, e os quatro
botões. Nada de modal para ler — modal só para *adaptar*.

**Ignorar regista-se sempre**, com quem, quando, a regra, o que estava proposto
e o retrato do momento. A nota é **sempre opcional**, em todos os níveis: um
copeiro obrigado a escrever um porquê com as mãos molhadas escreve «x», e uma
auditoria cheia de «x» é pior do que uma auditoria sem nota nenhuma.

### 2.3 O stock em percentagem, com degraus

Coluna nova `base_noite` em `bar_itens`: quantas havia quando a noite abriu.
Escreve-se em `bar_abrir` (a partir do `stock` de então) e **sobe com cada
entrada** (`bar_stock_repor`) — chegam mais duas caixas, a base passa a
contá-las. Sem isto a percentagem passava dos 100%.

```
percentagem = base_noite > 0 ? round(disponivel / base_noite * 100) : null
```

Degraus configuráveis numa definição só (`bar.degraus_stock`, por omissão
`50,30,15,5`): ao cruzar cada um para baixo, nasce um alerta — `aviso`,
`atencao`, `critico`, `critico`. Aos 0% a bebida fica esgotada como já fica
hoje (`disponivel <= 0` → travão `stock`), sem alerta nenhum a pedir confirmação:
não há nada a decidir sobre uma garrafa vazia.

**Um degrau, um alerta.** O alerta de 30% não volta a nascer enquanto a bebida
não subir acima de 30% outra vez. Sem isto, a cada dez segundos nascia um
alerta novo e o painel deixava de se poder ler.

O `stock_minimo` de hoje (o «a acabar», por bebida, em unidades) **fica**: é o
que pinta o semáforo amarelo na coluna do stock, e é uma pergunta diferente —
cinco whiskies é uma emergência, cinco águas não é nada.

### 2.4 A copa pausada

Hoje a copa tem dois estados: aberta e fechada. Ganha **um** terceiro:
**pausada**, com hora de fim (`bar.pausada_ate`). Enquanto durar, os pedidos
são recusados com a mensagem de pausa e o tempo que falta; passada a hora, a
copa reabre **sozinha**.

> **Um estado, e não cinco.** «PAUSADA», «SUSPENSA» e «EM RECUPERAÇÃO» são três
> nomes para *fechada por um bocado*, e o mecanismo é o mesmo que já funciona
> para suspender uma bebida (§30.3). «AGUARDA DECISÃO DO COPEIRO» não é um
> estado da copa: é um alerta aberto, e vive no painel.

### 2.5 As mensagens configuráveis

Tabela nova `bar_mensagens`: `situacao` (chave), `texto`, `ativo`. Uma linha por
situação, editável pelos noivos em `bar.php`. As situações são exactamente os
travões que o motor já conhece, mais os novos:

`stock` · `casa` · `proibido` · `suspensa` · `intervalo` · `tecto` · `corte` ·
`copa_fechada` · `copa_pausada` · `aguarda_copa`

Variáveis, substituídas à saída: `{BEBIDA}` · `{PEDIDAS}` · `{ACEITES}` ·
`{TEMPO}` · `{NOME}`. O texto de fábrica é o que `barTextoTravao()` já escreve
hoje — uma linha vazia usa o de fábrica, e assim ninguém tem de preencher nada
para o bar funcionar.

A `mensagem` da regra continua a mandar sobre o modelo, como manda hoje.

---

## 3. O que fica de fora — e porquê

Isto é a parte do pedido que se recusa de propósito. Não é para poupar
trabalho: é porque construir estas coisas **piorava** o módulo.

**Estados guardados para bebidas e convidados.** O pedido quer
`DISPONÍVEL / REDUZIDA / SUSPENSA / BLOQUEADA / ESGOTADA` numa coluna, e o
mesmo para os convidados (`ACTIVO / RESTRITO / BLOQUEADO…`). Não. O estado de
uma bebida **deduz-se** do stock e das regras que a apanham, e o de uma pessoa
das regras que a nomeiam. Guardá-lo cria um segundo sítio onde a verdade mora,
e os dois sítios acabam a discordar — é a falha que §29.1 já documenta neste
módulo, e que custou uma passagem inteira a corrigir. Calcula-se um **rótulo**
para mostrar; não se grava um estado.

**Mensagens por convidado ou por grupo.** Uma festa não precisa, e o risco é
exactamente aquilo que o §9 proíbe: uma mensagem diferente é a pessoa perceber
que foi apontada.

**Justificativa obrigatória ao ignorar.** Ver §2.2.

**A proposta técnica de 28 pontos** (arquitectura, modelo de dados, diagramas,
pseudocódigo do pedido, do motor, do cálculo de percentagem). O motor **existe
e corre**: `barVeredicto()` *é* o pseudocódigo do pedido, e está comentado.
Um documento que descreve código a funcionar nasce velho no primeiro `git
commit` seguinte. O que se escreve é o **§31 de `docs/modulo-bar.md`**, no
formato das outras dez passagens: o que mudou, porquê, e o que se parte se
alguém desfizer.

**«Bloquear novos pedidos» como acção distinta de «fechar a copa».** É a mesma
coisa.

---

## 4. O trabalho, por fases

Cada fase fecha com a suite inteira verde. Ninguém começa a seguinte sem isso.

### Fase 1 — esquema v40
- `bar_limites.modo ENUM('trava','sugere','confirma','avisa') DEFAULT 'trava'`
- `bar_itens.base_noite INT NOT NULL DEFAULT 0`
- tabela `bar_alertas` (§2.2), tabela `bar_mensagens` (§2.5)
- definições novas: `bar.degraus_stock`, `bar.pausada_ate`, `bar.pausa_min`
- as três tabelas entram em `LigacaoAmbito::TABELAS` e no retrato
  (exportar/importar) — o bar viaja inteiro ou não viaja
- `ESQUEMA_VERSAO` 39 → 40, com migração que **não** toca em regras já escritas

### Fase 2 — o motor de sugestões
- `barSugestoes(mysqli $conn): array` — corre a cada leitura de `bar_estado`,
  mede, e devolve os alertas que nascem agora. Escreve-os na tabela.
- respeita o `modo` de cada regra: só `trava` continua a recusar no acto
- degraus de stock (§2.3), com a trava do «um degrau, um alerta»
- ritmo acima do caudal, esgotamento iminente, pedidos a mais num período curto
- **Não corre num cron.** A copa lê de oito em oito segundos; é aí que se mede.
  Um processo a correr sozinho numa noite de festa é uma peça a mais para
  falhar, e ninguém a estaria a ver falhar.

### Fase 3 — o painel de alertas
- aba nova na copa, antes de «Por decidir», com a conta na pastilha
- Aplicar · Adaptar · Ignorar · Ver a regra
- `bar_alerta_decidir` na API, atrás do mesmo cadeado das outras acções do bar
- cada decisão vai ao registo de acções

### Fase 4 — as mensagens
- `bar_mensagens` com as variáveis; editor em `bar.php`
- `barTextoTravao()` passa a consultá-la antes do texto de fábrica

### Fase 5 — a pausa da copa
- `bar.pausada_ate`; recusa com o tempo que falta; reabre sozinha
- a acção `pausar_copa` do painel

### Fase 6 — provas e documento
- `tests/chk_bar_sexta.js`, no formato das outras: cada linha defende **uma**
  coisa e diz o que se parte se ela cair
- **a prova que não pode faltar:** uma regra em `sugere` **não trava** o pedido
  e **levanta** o alerta; a mesma regra em `trava` recusa e **não** levanta
  alerta nenhum. É a fronteira toda desta passagem numa linha.
- `docs/modulo-bar.md` §31; linhas novas em `versao.php`

---

## 5. As regras da casa (não negociáveis)

- Português europeu em tudo — código, comentários, ecrã, commits.
- Comentários que dizem **porquê**, não o quê; e o que se parte se alguém
  desfizer. É o estilo do módulo inteiro.
- Toda a consulta com âmbito de casamento menciona `casamento_id`
  (`LigacaoAmbito` rebenta se não mencionar).
- As acções novas do bar ficam **acima** da barreira de `api.php`, com o seu
  próprio cadeado — nunca atrás de `exigirAdminApi()`.
- Nada de bibliotecas novas. Sem jQuery, sem Select2, sem frameworks.
- Os quatro temas aplicam-se por inteiro; nenhuma cor solta fora dos tokens.
- Alvos de toque de 48px nos ecrãs de serviço: isto usa-se de pé, a meia-luz.
- O convidado **nunca** lê a razão técnica. Nem o número da regra, nem o
  limite, nem o que o sistema sabe sobre ele.
