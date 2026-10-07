# Fase 2 — editor orientado pelo manifesto

O pacote instalado declara em `manifesto.json` as secções, painéis, media,
efeitos, componentes e recursos que o seu renderizador suporta. O servidor
cruza essa declaração com um catálogo fechado; uma entrada desconhecida nunca
se transforma em código, ficheiro ou controlo executável.

`manifestoEditorModelo()` produz o contrato entregue ao navegador. O contrato
inclui a ordem dos painéis e os metadados semânticos dos campos. O núcleo
`editor-hibrido.js` monta o inspector nessa ordem e cada editor fornece apenas
os controlos especializados do seu suporte.

Há dois modos explícitos:

- **administrador** — trabalha todo o contrato suportado pelo pacote e pode
  preparar a estrutura, mesmo que determinada opção não seja entregue aos
  noivos;
- **noivos** — recebe apenas as secções, ferramentas, campos, cores, tipos,
  movimentos e recursos autorizados na ficha do modelo.

`campos_editaveis` é, portanto, uma liberdade concedida aos noivos. A API que
grava o desenho de um modelo é exclusiva do administrador e não volta a aplicar
esse filtro. A API da peça do casal continua a aplicá-lo no servidor.

Modelos anteriores, cuja ficha tem esquema 2 ou está vazia, são normalizados
para o esquema 3 e recebem o conjunto integral compatível. As versões antigas
continuam ligadas ao renderizador que guardaram.

## Recursos gerais

A ficha pode autorizar `biblioteca_icones`, `rolagem_automatica` e
`presentes_qr`. A biblioteca de ícones é comum aos estilos e inclui símbolos
editoriais, música, presente, QR, transferência, cartão, dinheiro, banco e
pagamento por aplicação. Os ícones da música parada e a tocar são escolhas do
modelo.

A rolagem automática guarda uma velocidade entre 8 e 120 píxeis por segundo.
Começa depois de abrir o convite, respeita a preferência de movimento reduzido
do dispositivo e pausa durante 4,5 segundos quando a pessoa roda, toca, clica
ou usa o teclado.
