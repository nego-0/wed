# Editores por capacidades

Os convites digitais e impressos usam uma solução híbrida: há uma estrutura de
editor comum por suporte e cada modelo fornece o seu desenho e a sua ficha de
capacidades. Criar um modelo novo não cria uma cópia do editor.

## Ficha do modelo

`cw_modelos.capacidades` guarda JSON no esquema 2. A ficha contém:

- `seccoes`: secções digitais ou camadas impressas presentes no inspector;
- `obrigatorios`: elementos que não podem ser retirados da ficha;
- `paineis`: conteúdo, camadas, cores, tipografia, media, efeitos, composição e
  guias de impressão, conforme o suporte;
- `componentes`: texto, fotografia, galeria, cronologia, local/mapa,
  confirmação, música ou ornamentos;
- `media` e `efeitos`: opções individuais que o inspector pode apresentar;
- `movimentaveis`: elementos que aceitam posicionamento livre;
- `campos_editaveis`: chaves semânticas que os noivos podem alterar;
- `cores_permitidas` e `tipografias_permitidas`;
- `limites`: formato, quantidade de blocos/fotografias e margens de impressão.

Modelos antigos, cuja ficha é `NULL`, recebem todas as capacidades do suporte.
Assim a migração não retira ferramentas a peças já publicadas. Ao primeiro
ajuste feito pelo admin, a ficha integral é guardada.

## Dois níveis de edição

O admin da plataforma desenha o modelo e abre **Definir capacidades** na página
Modelos para escolher o contrato oferecido aos noivos. O editor monta o
inspector com esse contrato. Opções bloqueadas ficam fora da interface e a API
volta a validar `campos_editaveis`, impedindo que um pedido manual contorne o
inspector.

Os noivos editam a peça derivada do modelo. A ficha é procurada no modelo em
vigor, no modelo de proveniência ou na peça de origem atribuída ao casamento.
O conteúdo bloqueado continua a ser desenhado; apenas deixa de poder ser
alterado. Elementos obrigatórios são acrescentados de volta durante a
normalização mesmo que um pedido tente omiti-los.

## Dados semânticos e troca de modelo

Os valores continuam nas chaves comuns de `cw_definicoes`, entre outras:

```
casal.noiva
casal.noivo
evento.data
evento.local
historia.titulo
media.hero
media.historia
rsvp.titulo
```

Aplicar um modelo substitui apenas as chaves de desenho permitidas para o
suporte. Nomes, datas, locais e fotografias próprias permanecem no casamento.
O modelo muda a apresentação e passa a ser registado como proveniência da peça.

## Compatibilidade e transporte

As capacidades são saneadas contra um catálogo fechado no servidor. Chaves
desconhecidas, secções inexistentes e limites fora do intervalo não entram. A
exportação e a importação de modelos e do sistema incluem a ficha, para que o
mesmo modelo ofereça as mesmas ferramentas noutra instalação.

O contrato PHP vive em `editor-modelo.php`; o núcleo adaptável do navegador em
`assets/editor-hibrido.js`; a gestão administrativa e a validação da API ficam
em `modelos.php` e `api.php`.
