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
| `chk_versao.js`         | a página de versão diz a verdade, e ?diag=1 traz o diagnóstico  |

## Deixam a base como a encontraram

Cada prova repõe o que mexeu. Se uma falhar a meio pode deixar rasto — a
reciclagem e as versões guardadas são os sítios onde isso se nota. `correr.js`
avisa quando encontra lixo de uma corrida anterior.
