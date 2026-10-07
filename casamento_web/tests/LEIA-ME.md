# Provas de ponta a ponta

Correm um Chromium a sério contra uma instalação viva e verificam o que o
utilizador vê. Ficam aqui, no repositório, porque já se perderam várias vezes
quando viviam fora dele.

## Como correr

Precisam de uma base de dados de pé e do site servido:

```sh
mysqld_safe &                                      # ou o MariaDB do sistema
php -S 127.0.0.1:8920 -t casamento_web             # o site
cd casamento_web/tests && npm i playwright-core    # uma vez
node correr.js                                     # todas
node chk_deco.js                                   # só uma
```

`amostras.js` não é uma prova e o `correr.js` não lhe pega. Desenha um cartão
por cada feitio de floreado, voluta, moldura e elo — o cartão inteiro e um
recorte de perto — para se poderem pôr lado a lado e decidir se são bonitos.
Foi assim que se apanhou o floreado "filete" feito de retas e o "leque" com
seis riscos cruzados: nenhum dos dois dava erro, só ficavam feios.

`montra.js` também não é prova: é a ferramenta que refaz as capturas de
`assets/montra/`, as que a montra dos planos mostra a quem se vem inscrever.
Semeia o casamento de demonstração com uma festa a sério (convidados, mesas,
orçamento com parcelas, entradas à porta), abre cada página com uma conta de
noivos criada só para isso — com a do admin, a tira amarela da visita de
suporte entrava em todas as imagens — e escreve os JPEG por cima dos que lá
estão. Corre-se quando o produto muda de cara: uma montra que mostra o ecrã
de há seis meses vende uma coisa que já não existe. `node montra.js` faz
todas; `node montra.js orcamento` faz só uma. Depois, OLHE para as imagens.

`gerar-capturas-ajuda.js` abre os módulos reais e actualiza as imagens desktop
e mobile usadas por trás dos stickers vectoriais. Antes de cada fluxo, cria
dados de exemplo pela API e mede o centro do controlo real; as 84 coordenadas
ficam em `assets/ajuda/capturas/alvos-cenas.json`. Reaplica a peça padrão
publicada pelo admin, usa a identidade fictícia Marta & Pedro e abre as gavetas
móveis necessárias, inclusive Camadas no editor impresso. Deve correr apenas
numa instalação de testes, porque prepara e normaliza esses dados. `chk_experiencia_kulemba.js`
confirma que os 14 tópicos são pesquisáveis, os 42 passos abrem apenas a sua
narração, a captura permanece ao lado e tudo cabe no telemóvel.
`chk_atendimento_marketing.js` confirma que a secção pública `#demonstracao`
usa a mesma central, a mesma ordem e os mesmos materiais da página de Ajuda.
`rever-stickers-ajuda.js` gera 84 quadros de revisão — três passos, dois
formatos e catorze tópicos — e quadros intermédios para toda a rolagem animada.
Defina `HELP_STICKER_REVIEW` com uma pasta temporária antes de o executar.
`gerar-stickers-ajuda.js` recria as ilustrações vectoriais associadas aos
verbos dos passos; com `HELP_STICKER_SHEET` também produz uma folha PNG para
revisão visual. `chk_stickers_semanticos.js` verifica a correspondência das 42
descrições em computador e telemóvel.
Nos fluxos que mudam de estado,
`HELP_CAPTURE_SCENES_ONLY=1 node tests/gerar-capturas-ajuda.js` recria as cenas
antes de abrir, no topo e depois da rolagem até ao botão final. O nome antigo
`HELP_CAPTURE_MODAL_ONLY` continua aceite. Para depurar apenas um formato, use
`HELP_CAPTURE_DEVICE=desktop` ou `HELP_CAPTURE_DEVICE=mobile`.
`HELP_CAPTURE_MODULE=digital` limita a geração a um módulo e conserva no
manifesto as coordenadas dos restantes.

```sh
TEST_OUT=/tmp/amostras node amostras.js
```

Três variáveis de ambiente, todas com valor por omissão:

| Variável   | Para quê                        | Por omissão                                      |
|------------|---------------------------------|--------------------------------------------------|
| `BASE_URL` | onde o site responde            | `http://127.0.0.1:8920`                          |
| `CHROMIUM` | o executável do navegador       | `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` |
| `TEST_OUT` | onde ficam as capturas de ecrã  | a pasta temporária do sistema                    |

Entram com **admin / noivos2026**.

A prova `chk_kulemba_contemporaneo.js` aceita também `TEST_USER` (por omissão
`admin`) e `TEST_PASSWORD` (por omissão `noivos2026`). Use uma base de testes:
guarda um texto e fontes temporários num modelo, muda o modelo de origem e
repõe ambas as definições no fim, incluindo quando há falhas. `BASE_URL` e
`CHROMIUM` seguem os valores acima; no Windows, sem `CHROMIUM`, procura o Chrome
em `C:\Program Files\Google\Chrome\Application\chrome.exe`. `SCREENSHOTS`,
quando definido, indica a pasta para as capturas opcionais desta prova.

```sh
BASE_URL=http://127.0.0.1:8920 TEST_USER=admin TEST_PASSWORD=noivos2026 node chk_kulemba_contemporaneo.js
```

## O que cada uma prova

| Ficheiro                | Assunto                                                        |
|-------------------------|----------------------------------------------------------------|
| `chk_arrasto.js`        | faixas e seletores de cor deixam-se arrastar até ao fim         |
| `chk_experiencia_kulemba.js` | menu administrativo comum, imagens públicas e central partilhada pesquisável |
| `chk_atendimento_marketing.js` | identidade entre a demonstração pública e a Ajuda, incluindo edição administrativa |
| `chk_admin_stickers.js` | geração e gestão separada dos stickers da Demonstração e da Ajuda pelo admin |
| `chk_exemplo_demonstracao.js` | peça padrão e dados de exemplo do admin no convite, confirmação e montra; fábrica Marta & Pedro |
| `chk_demonstracao_interactiva.js` | sete módulos na central pública, convite e confirmação reais, sem escritas nem transbordo |
| `chk_stickers_semanticos.js` | 42 descrições ligadas a 20 stickers animados em desktop e mobile |
| `chk_compacto.js`       | a lista de convites cabe numa linha por convite, com marca de versão nos assets |
| `chk_cores_textos.js`   | cores com nome, textos que pintam ao vivo, nomes dos noivos     |
| `chk_deco.js`           | feitios da moldura, tamanho dos ornamentos, e tudo a chegar à impressão |
| `e2e_v3.js`             | planta das mesas: zoom, papéis, mesa dos noivos                 |
| `e2e_ui.js`             | arrastar convites entre mesas                                   |
| `e2e_mobile.js`         | cartões de estatística no telemóvel                             |
| `e2e_statcards.js`      | filtros pelos cartões de estatística                            |
| `e2e_lixo.js`           | reciclagem: eliminar, repor, anular                             |
| `nav_check.js`          | todas as páginas respondem e o menu marca a certa               |
| `chk_paginas.js`        | cada página chega ao fim, sem rebentar nem deixar erro do PHP   |
| `chk_versao.js`         | a página de versão diz a verdade, e ?diag=1 traz o diagnóstico  |
| `chk_versao_vigor.js`   | a versão em vigor é a que os convidados recebem e o manual retrata |
| `chk_digital_menu.js`   | entrada do convite digital, e o menu "⋯" a abrir para cima quando não cabe |
| `chk_digital_previa_atual.js` | miniatura e atalhos mostram a peça em vigor no casamento aberto, nunca o convite da demonstração |
| `chk_capa.js`           | a capa (envelope) com monograma editável no editor do convite digital |
| `chk_sem_numero.js`     | o número de lugares não entra no nome do convite, em peça nenhuma |
| `chk_versao_grava.js`   | guardar uma versão apanha o que está no ecrã, e não só o que já foi gravado |
| `chk_versao_padrao.js`  | a versão "Original" repõe a peça tal como veio, sem esconder secções |
| `chk_form_convite.js`   | o formulário do convite pede tudo o que pedia, em menos espaço |
| `chk_impressao_cor.js`  | as cores dos cartões sobrevivem à impressão                     |
| `chk_multi_fundacao.js` | o esquema de vários casamentos: colunas, chaves e o casamento nº1 |
| `chk_isolamento.js`     | nenhuma consulta toca em dados de casamento sem dizer de qual   |
| `chk_plataforma.js`     | vários casamentos e várias contas: cada um só entra no seu      |
| `chk_publico_multi.js`  | a porta pública com vários casamentos: código, casamento inativo, o porteiro que não lê o convite alheio, e o endereço dos QR |
| `chk_identidade.js`     | os nomes e a data da ficha do casamento chegam sozinhos a todas as peças, e cada casamento tem a sua |
| `chk_contas.js`         | registo público e aprovação, códigos de suporte (ver / corrigir / revogar), equipa do casamento e contas suspensas |
| `chk_editor_avancado.js`| desenhar modelos sem casa emprestada, camadas trancadas que resistem ao arrasto, e o ponto focal que se cola às guias |
| `chk_orc_mobile.js`     | despesas, botão de fatura e calendário sem transbordo nem texto cortado a 390 px |
| `chk_orc_categoria_pesquisa.js` | pesquisa e criação directa de categorias na despesa, com cor, e valores compactos nas barras estreitas |
| `chk_kulemba_contemporaneo.js` | linguagem Kulemba no editor e demonstração: ícones, molduras, fotografias, efeitos, gravação, fontes embebidas, CSP e larguras de 320, 390 e 1440 px |
| `chk_capacidades_modelo.php` | ficha persistente de capacidades: secções, componentes, campos semânticos, cores, tipografias, movimento e limites por modelo |
| `chk_historico_versoes_editor.js` | histórico com camadas trancadas, ícones de desfazer/refazer, ausência de rascunhos e versões apenas do utilizador |
| `chk_modelos.js`        | modelos da casa: nascem de um convite a sério, aplicam-se, e depois disso o desenho é do casal |
| `chk_modelo_versao.js`  | a peça chama o modelo pelo nome (em vigor e com alterações), e alterá-lo obriga a uma versão do casal, com nome, que mais ninguém vê |
| `chk_orcamento.js`      | o curso das despesas: contas do resumo, isolamento entre casamentos, a ida e volta no export/import, e o teto pelos formulários de registo e pela Gestão |
| `chk_floreados.js`      | os floreados abraçam os nomes no sítio que o desenho de origem lhes deu, o clássico é o traço da referência ponto por ponto, e os cinco feitios ficam todos na mesma âncora |
| `chk_ornamentos.js`     | as cinco volutas de canto e os seis elos entre os nomes: cada uma cabe na sua caixa, o elo fica centrado, e as escolhas chegam à folha de cartões e ao manual |
| `chk_cerimonias.js`     | cerimónias que se acrescentam e removem nas duas peças, cronograma que se rearranja, e a hora vazia que já não é meia-noite |
| `chk_admin_ui.js`       | o painel da administração arrumado por quem o usa, e os modelos a mostrarem a cara |
| `chk_admin_mobile.js`   | todas as áreas administrativas cabem no telemóvel, com descrições no corpo e o seletor de mesa em diálogo centrado |
| `chk_editor_espaco.js`  | o editor avisa quando o ecrã é pequeno (e deixa continuar), e o manual de impressão segue o cartão em vigor |
| `chk_sair_editor.js`    | os dois editores mostram «Sair do Editor», regressam ao contexto certo e protegem alterações por guardar |
| `chk_tela_livre.js`     | posicionamento livre: arrastar blocos no cartão, no envelope e nas páginas do corpo (incluindo secções livres), íman, cadeado, e a composição a chegar ao convidado |
| `chk_dados.js`          | levar os dados e trazê-los de volta: o que sai volta igual, substituir substitui, e cada um só leva o que é seu |
| `chk_so_ver.js`         | o ecrã em modo de leitura: o que escreve fica apagado, o que só mostra continua vivo, e os gestos da planta não arrancam — mas arrancam com um código de correção |

## Deixam a base como a encontraram

Cada prova repõe o que mexeu. Se uma falhar a meio pode deixar rasto — a
reciclagem e as versões guardadas são os sítios onde isso se nota. `correr.js`
avisa quando encontra lixo de uma corrida anterior.

## Modo estrito do âmbito (vários casamentos)

Arranque o servidor com `AMBITO_ESTRITO=1`:

```
AMBITO_ESTRITO=1 php -S 127.0.0.1:8920 -t .
```

Assim, qualquer consulta que toque numa tabela de casamento sem dizer de qual
rebenta a página em vez de passar despercebida — e as provas apanham-na. Em
produção deixa-se desligado: a falha vai para o log sem derrubar nada.
