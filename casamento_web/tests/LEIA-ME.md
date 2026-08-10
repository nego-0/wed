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

Três variáveis de ambiente, todas com valor por omissão:

| Variável   | Para quê                        | Por omissão                                      |
|------------|---------------------------------|--------------------------------------------------|
| `BASE_URL` | onde o site responde            | `http://127.0.0.1:8920`                          |
| `CHROMIUM` | o executável do navegador       | `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` |
| `TEST_OUT` | onde ficam as capturas de ecrã  | a pasta temporária do sistema                    |

Entram com **admin / noivos2026**.

## O que cada uma prova

| Ficheiro                | Assunto                                                        |
|-------------------------|----------------------------------------------------------------|
| `chk_arrasto.js`        | faixas e seletores de cor deixam-se arrastar até ao fim         |
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
| `chk_modelos.js`        | modelos da casa: nascem de um convite a sério, aplicam-se, e depois disso o desenho é do casal |
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
