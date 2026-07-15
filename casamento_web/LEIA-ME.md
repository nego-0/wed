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
| `index.php` | Painel de administração (convites, convidados, mesas, importação, QR). |
| `mesas.php` | Planta visual das mesas: posição (arrastar), capacidade e ocupação, com atribuição de convites. |
| `convite.php` | Página pública de confirmação de presença + passe de entrada com QR. |
| `porteiro.php` | Página do porteiro: leitura de QR por câmara e busca manual. |
| `impressos.php` | Etiquetas dos convites físicos com QR, prontas a imprimir. |
| `assets/estilo.css` | Estilo visual, alinhado com o convite (verde-floresta, dourado e marfim). |

---

## Estrutura da base de dados (tabelas novas)

- **`cw_convites`** — o convite é a unidade central: código único, nome a exibir, sufixo opcional, tipo (`digital`/`fisico`/`ambos`), lado, número de lugares, mesa, telefone, estados de RSVP e de entrada, mensagens e observações.
- **`cw_convidados`** — as pessoas nominais de cada convite (com RSVP e presença individuais).
- **`cw_mesas`** — mesas com capacidade e ocupação.

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

**Regra do número entre parênteses.** Se o convite for para **uma só pessoa**, mostra apenas o nome. Para mais do que uma, acrescenta *(N pessoas)* — ou o texto que escrever no campo **Sufixo** (ex.: *e acompanhante*).

**Tipo do convite.** *Digital*, *Físico* ou *Ambos*. Todos os convites têm sempre um link e um QR; o tipo serve para organizar a produção (marcar como *impresso* / *enviado*).

**Mesas.** Na página *Mesas* tem uma **planta visual do salão**: crie mesas (redondas ou retangulares) com capacidade, **arraste-as** para a sua posição real e veja a ocupação por cores (vazia, a encher, completa, excede a capacidade). Ao tocar numa mesa pode editar os dados, ver quem está sentado e **sentar/retirar convites**. As posições ficam guardadas.

**Dividir um convite por várias mesas.** Cada pessoa de um convite pode ficar numa mesa diferente. Há três formas de o fazer: no painel de uma mesa (cada pessoa tem um seletor de mesa), **arrastando** um cartão da lista *Pessoas* (abaixo da planta) para cima de uma mesa, ou no **editor do convite** (no painel principal, cada integrante tem um seletor de mesa — *"Mesa do convite"* por omissão). A mesa do convite continua a ser o padrão (para quem não tem mesa própria e para os lugares sem nome). A ocupação de cada mesa conta as pessoas pela sua mesa efetiva. No painel principal, um convite dividido aparece marcado como *"Dividido · N mesas"*.

**Lista abaixo da planta.** Por baixo do canvas das mesas há uma lista com duas abas — *Pessoas* e *Convites* — com pesquisa por nome e filtros (por mesa: todas / sem mesa / com mesa; e por estado de RSVP). Qualquer cartão pode ser arrastado para uma mesa.

**Confirmação de presença (RSVP).** Cada convite tem um link único (`convite.php?c=CÓDIGO`). O convidado confirma se comparece, quantas pessoas e quem, e pode deixar uma mensagem. Ao confirmar, recebe o **passe de entrada com QR**.

**Porteiro.** Na página de entrada, o porteiro lê o QR com a câmara ou procura pelo nome/código. Vê o estado do convite e regista a entrada (de todos ou de cada pessoa). **Junto a cada pessoa aparece a sua mesa**, e se o convite estiver dividido por mesas surge um aviso com as mesas envolvidas — para o porteiro poder orientar cada convidado. O contador de presenças atualiza em tempo real.

**Convites físicos.** A página *Convites físicos* gera as etiquetas com o nome e o QR de cada convite, prontas a imprimir para os envelopes.

---

## Notas importantes

- **Funciona sem internet.** As bibliotecas (leitor de QR e gerador de QR) e os tipos de letra são servidos localmente a partir da pasta `assets/` — não há dependências de CDN. Assim, a página do porteiro continua a funcionar mesmo que a ligação à internet falhe no local do evento.
- A leitura de QR por câmara exige **HTTPS** (regra do navegador, não do sistema). No InfinityFree, ative o certificado SSL grátis e aceda por `https://`. Em rede local de testes, `localhost` também é aceite.
- Editar um convite preserva as confirmações e presenças já registadas.
- O check-in à entrada só altera a presença — nunca apaga a confirmação feita pelo convidado.
- O código QR aponta para o link do convite, pelo que serve tanto ao convidado (abre o seu convite) como ao porteiro (identifica-o à entrada).
- O sistema não depende de extensões opcionais (mbstring, gd, intl), pelo que funciona em alojamento partilhado simples.
