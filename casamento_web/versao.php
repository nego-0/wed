<?php
// ============================================================
// versao.php — O que está mesmo instalado neste servidor
//
// Serve para responder a uma pergunta que por telefone é impossível:
// "o servidor tem a correção X?". Em vez de adivinhar, abre-se esta
// página e lê-se. Cada correção recente tem uma marca no código; aqui
// procura-se essa marca e diz-se se está lá ou não.
//
// ---- A REGRA -----------------------------------------------
// Quem mexe na aplicação acrescenta aqui a marca do que mexeu. Uma linha,
// no fim da lista: o nome da alteração, o ficheiro, e um pedaço de texto
// que só exista depois dela. Sem isso esta página envelhece em silêncio —
// continua a dizer "está tudo cá" enquanto o servidor tem código de há
// meses, que é exatamente a mentira que ela existe para evitar.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
exigirAdmin();

/** Uma correção, e a marca que a denuncia no código instalado. */
function correcoesEsperadas(): array {
    return [
        ['Lista de convites numa linha só',
         'assets/estilo.css', 'grid-template-columns:auto auto 1fr auto'],
        ['Ícone dos botões com medida (linha baixa)',
         'assets/estilo.css', '.btn-ico svg{ width:16px'],
        ['Símbolos de género legíveis',
         'index.php', 'font-size:1.15em'],
        ['Endereços dos assets com marca de versão',
         'config.php', 'function asset('],
        ['Barras reclamam o gesto horizontal (tátil / rato de precisão)',
         'assets/editor.css', 'input[type=range]{ touch-action:pan-y'],
        ['Aviso "por guardar" fora da barra de opções (o editor deixa de saltar)',
         'assets/editor.css', '.marca-sujo{'],
        ['Seletor de cor fora do <label>',
         'editor-cartao.php', 'class="cor-linha">'],
        ['Redesenho adiado durante um gesto',
         'assets/editor-adiar.js', 'global.adiavel'],
        ['Diagnóstico do gesto com ?diag=1',
         'editor-cartao.php', 'editor-diag.js'],
        ['Alternativas das camadas decorativas do cartão',
         'pecas.php', 'function cartaoMolduras('],
        ['Cores do convite digital com nome',
         'personalizacao.php', 'function temaVarsRotulos('],
        ['Versões dos dois convites, num painel próprio na barra superior',
         'assets/versoes.js', 'function montar('],
        ['Versão padrão «Original», que não se apaga nem se reescreve',
         'personalizacao.php', 'VERSAO_PADRAO_NOME'],
        ['Capa (envelope) com monograma editável no editor digital',
         'convite-editor.php', "CAPA_ID = 'capa'"],

        // ---- Vários casamentos na mesma casa ----
        ['Cada dado sabe de que casamento é',
         'db.php', 'function doCasamento('],
        ['Guarda de âmbito: consulta sem dono reclama',
         'db.php', 'class LigacaoAmbito'],
        ['Contas na base de dados, e não no ficheiro de configuração',
         'auth.php', 'function casamentosDoUtilizador('],
        ['Página dos casamentos, com fila de aprovação',
         'plataforma.php', 'function carregarContas('],
        ['A porta não lê o convite de outro casamento',
         'api.php', "'codigo_local'"],
        ['Convite público só de casamentos ativos',
         'db.php', "w.estado='ativo'"],
        ['Cópia offline da porta separada por casamento',
         'porteiro.php', "'porta.dados.' + CASAMENTO"],
        ['Manifesto da porta com o nome do casamento aberto',
         'manifest.php', 'application/manifest+json'],

        // ---- Endereço público (esquema v8) ----
        ['Endereço público por casamento (QR e links)',
         'db.php', 'function enderecoPublico('],
        ['Aviso antes de imprimir QR para um endereço local',
         'parcial-endereco.php', 'function barraEndereco('],

        // ---- Contas, papéis e suporte ----
        ['Inscrição pública de um casal, com aprovação',
         'registo.php', 'registo_publico'],
        ['Códigos de suporte: ver, corrigir, revogar',
         'api.php', "'suporte_codigo_criar'"],
        ['Revogar um código expulsa quem já lá estava',
         'auth.php', 'revogado_em IS NULL'],
        ['Ecrã em modo de leitura (o que escreve fica apagado)',
         'assets/so-ver.js', 'soVerAviso'],
        ['Gestos da planta travados no modo de leitura',
         'assets/mesas.js', 'function travaLeitura('],

        // ---- A ficha do casamento manda nas peças ----
        ['Nomes e data do casal chegam sozinhos a todas as peças',
         'personalizacao.php', 'function identidadeCasamento('],
        ['Área de gestão do casamento (ficha, evento, equipa, conta)',
         'gestao.php', 'casamento_identidade'],
        ['Entrada e inscrição sem nome de casal nenhum',
         'config.php', 'const PLATAFORMA'],
        ['O admin da plataforma não é nenhum dos casais',
         'auth.php', 'function entrouComoPlataforma('],
        ['Entrada do admin: administração com números de todo o sistema',
         'plataforma.php', 'class="numeros"'],
        ['Ações da plataforma funcionam sem casamento aberto',
         'config.php', 'function acoesSemCasamento('],
        ['Levar e trazer os dados (casamento, ou a casa inteira)',
         'api.php', 'function retratoCasamento('],
        ['Importação da lista antiga ("guests") removida',
         'api.php', 'dados_importar'],
        ['Arquivar, reabrir e apagar casamentos (apagar só depois de arquivar)',
         'plataforma.php', 'function apagar('],
        ['Arquivar um casamento para as contas que só existem por causa dele',
         'auth.php', "SET u.estado='inativo'"],
        ['A lista principal da administração só mostra casamentos ativos',
         'plataforma.php', '$suspensos'],
        ['Convidados esperados, e as duas cerimónias, nos dados do evento',
         'personalizacao.php', "'evento.religiosa_local'"],
        ['Os dados do evento pedem-se no primeiro registo',
         'api.php', 'function guardarEventoDoRegisto('],
        ['Sair do casamento sem terminar a sessão',
         'auth.php', 'function fecharCasamento('],
        ['Lista de casamentos dinâmica, por ordem de uso',
         'api.php', "'casamento_lista'"],
        ['Contas: criar, editar, apagar e dar lugares em casamentos',
         'api.php', "'utilizador_editar'"],
        ['Conta de suporte não se prende a casamentos; noivos só criam porteiros',
         'api.php', 'não se prende a um casamento'],
        ['Modelos de convite da casa, para todos os casais',
         'modelos.php', 'modelo_criar'],
        ['Os modelos aparecem no painel de versões dos editores',
         'assets/versoes.js', 'Modelos da casa'],
        ['Desenhar um modelo sem abrir o casamento de ninguém',
         'personalizacao.php', 'function defsDoEditor('],
        ['Trancar camadas: não se arrastam nem se escondem',
         'convite-editor.php', 'function alternarTranca('],
        ['Ponto focal com guias magnéticas (centro e terços)',
         'convite-editor.php', 'const IMAS ='],

        // ---- Tela de posicionamento livre ----
        ['Arrastar blocos na peça, com alinhamento magnético e guias',
         'assets/tela-livre.js', 'function encostar('],
        ['Deslocamento guardado em % da peça (e não em píxeis)',
         'personalizacao.php', 'function validarPosicoes('],
        ['O cartão impresso leva as camadas para onde as puserem',
         'assets/pecas.css', '.cartao [data-camada]{ translate:'],
        ['Camadas do cartão com cadeado próprio',
         'editor-cartao.php', 'function alternarTranca('],
        ['Envelope e capa de entrada com blocos arrastáveis',
         'personalizacao.php', 'function posicoesLivres('],
        ['Todas as páginas do convite com blocos arrastáveis',
         'personalizacao.php', "\$por('grande-dia'"],
        ['Nas páginas que correm, a régua é a largura (o texto reflui, a largura não)',
         'personalizacao.php', '.page{--uw:'],
        ['Secções livres compõem-se assim que nascem',
         'convite-editor.php', 'function livresTodos('],
        ['Id de posição com dois pontos, para não se confundir com uma definição',
         'personalizacao.php', 'function idPosicaoValido('],
        ['A composição livre acompanha o convite que o convidado abre',
         'personalizacao.php', 'function cssPosicoes('],
        ['Arrasto dentro da tela do convite digital (o iframe manda no gesto)',
         'convite-digital.php', 'function montarLivres('],

        // ---- Volta, mesa mínima e o manual a par ----
        ['Virar blocos: Alt + arrastar, barra no painel, Alt + setas',
         'assets/tela-livre.js', 'function colarAng('],
        ['A volta viaja no mesmo valor gravado ("x y ângulo")',
         'personalizacao.php', 'const POS_ANGULO'],
        ['Aviso de ecrã pequeno para o editor (com saída para continuar)',
         'assets/editor-espaco.js', 'esp-aviso'],
        ['Mesa mínima do editor definida num sítio só',
         'config.php', 'const EDITOR_MIN_L'],
        ['O manual diz o feitio da moldura e o tamanho dos ornamentos de agora',
         'manual.php', '$moldLinha'],
        ['O manual lista as camadas movidas e viradas, em % e em mm',
         'manual.php', 'Composição — camadas fora do sítio de origem'],

        // ---- Formulário do convite ----
        ['Género e papel em pastilhas, e não em caixas de escolha',
         'index.php', 'function segMembro('],
        ['O papel segue o género: Padrinho ou Madrinha, conforme',
         'index.php', 'function sincroPapelGenero('],
        ['Cada pessoa em duas linhas alinhadas, com tudo à vista',
         'index.php', 'grid-template-columns:2fr 1fr 1fr auto'],

        // ---- Painel da administração e modelos ----
        ['Painéis que dobram: o que se usa uma vez por mês não come o ecrã',
         'assets/estilo.css', '.painel.dobra{ padding:0; }'],
        ['A ação que estraga fica atrás do "⋯", e não ao lado da que não estraga',
         'assets/estilo.css', '.mm-pop button.perigo'],
        ['A lista de casamentos diz a data, quanto falta e quantos confirmaram',
         'plataforma.php', 'function dataCasamento('],
        ['Quantos confirmaram, por casamento, na lista da administração',
         'api.php', "g2.rsvp = 'confirmado') confirmados"],
        ['Os números da administração levam mesmo a algum lado',
         'plataforma.php', 'numeros button.n'],
        ['Os modelos mostram a cara, e não só o nome',
         'modelos.php', 'function ajustarCara('],
        ['Prova do cartão de um modelo, sem casamento nenhum pelo meio',
         'modelo-prova.php', 'defsDoEditor'],
        ['O convite digital desenha-se com as definições de um modelo',
         'convite-digital.php', "(int)(\$_GET['modelo'] ?? 0) > 0"],

        // ---- Cerimónias, cronograma, e o que estava partido ----
        ['Hora por preencher não é meia-noite (era "às 0h" em todos os cartões)',
         'personalizacao.php', "if (\$hhmm === '') return '';"],
        ['O mesmo, na cópia em JavaScript do editor',
         'editor-cartao.php', 'const v = String(hhmm||\'\').trim(); if (!v) return \'\';'],
        ['Cerimónias com acrescentar e remover, nos dois editores',
         'editor-cartao.php', 'function acrescentarCerimonia('],
        ['O convite digital passa a anunciar as cerimónias',
         'personalizacao.php', 'function cerimoniasHtml('],
        ['Título de cada cerimónia partilhado pelas duas peças (esquema v14)',
         'db.php', "SET chave='evento.civil_titulo'"],
        ['Um bloco de logística só, servidor e editor a desenhá-lo igual',
         'pecas.php', 'function cartaoLogistica('],
        ['O editor do cartão grava mesmo os dados do evento que mostra',
         'editor-cartao.php', '$chavesEditor = array_merge('],
        ['Cronograma (e as outras listas) rearranjam-se',
         'convite-editor.php', 'function moverItem('],
        ['O menu "⋯" dos modelos deixou de ser cortado pelo cartão',
         'modelos.php', 'era ele que cortava o menu'],
        ['Um modelo não tem versões: o seletor vazio saiu',
         'editor-cartao.php', 'if (!MODELO) PAINEL_VERSOES = Versoes.montar('],

        // ---- Floreados ----
        ['Uma camada por mover não leva transformação nenhuma (nem a zero)',
         'assets/pecas.css', 'translate:var(--mv, none)'],
        ['Os floreados voltaram ao sítio que o desenho de origem lhes deu',
         'assets/pecas.css', '.ct-floreado-e{ left:-26px; top:-4px; }'],
        ['O clássico é o traço da referência, ponto por ponto',
         'pecas.php', 'M148 98 C 90 100 36 84 20 36 C 12 14 34 2 46 20'],
        ['Cinco feitios de floreado, à escolha no editor',
         'pecas.php', 'function cartaoFloreados('],
        ['Todos ancorados no mesmo ponto: trocar de feitio não desalinha',
         'pecas.php', 'a MESMA âncora'],

        // ---- Cantos, molduras e elos ----
        ['Cinco volutas de canto, à escolha no editor',
         'pecas.php', 'function cartaoVolutas('],
        ['Sete molduras, três delas novas',
         'pecas.php', "'tripla'"],
        ['A "linha dupla" passou a ser mesmo duas linhas',
         'pecas.php', 'Uma sombra transparente não apaga'],
        ['Seis elos entre os nomes, o "&" entre eles',
         'pecas.php', 'function cartaoElos('],
        ['O manual de impressão anuncia volutas, floreados e elo',
         'manual.php', 'Entre os nomes'],
        ['Uma lista só de feitios de moldura: o editor lê a do servidor',
         'editor-cartao.php', 'const MOLDURA_VARS'],
        ['Mudar de moldura já não deixa agarradas as variáveis da anterior',
         'editor-cartao.php', 'c.style.removeProperty(k)'],
        ['O floreado "filete" acompanha os nomes em vez de os riscar',
         'pecas.php', 'lia-se como um risco em diagonal'],
        ['A voluta "leque" são três arcos do mesmo centro, e não seis riscos',
         'pecas.php', 'Três quartos de círculo do mesmo centro'],
        ['O elo "filete" fica centrado entre os nomes',
         'assets/pecas.css', 'margin:12px auto'],

        // ---- O cartão volta a ser o do desenho de origem ----
        ['Pegar numa camada já não desloca os ornamentos que ela leva',
         'assets/pecas.css', '.ct-ramos, .ct-volutas, .ct-floreados{ color:var(--ct-accent);'],
        ['O coração entre os nomes tem a altura do original (24 px)',
         'assets/pecas.css', 'color:var(--ct-accent); line-height:1.2'],
        ['Os feitios de elo mantêm a entrelinha curta que pedem',
         'assets/pecas.css', 'margin:-2px 0 0; line-height:1'],
        ['Arrastar move a camada escolhida, e não a que está por dentro dela',
         'assets/tela-livre.js', 'if (op.escolhida)'],

        // ---- Cerimónias mais fáceis de achar, e o menu "⋯" a direito ----
        ['A camada da logística chama-se pelo que lá se faz: cerimónias e receção',
         'pecas.php', "'Cerimónias e receção'"],
        ['No editor do impresso, as cerimónias vêm à cabeça do painel da camada',
         'editor-cartao.php', 'as cerimónias vêm ANTES da receção'],
        ['O botão "mais ações" leva três pontos em SVG, e não o glifo caído',
         'assets/estilo.css', '.ico-mais{'],

        // ---- Cerimónias também no editor de modelos do admin ----
        ['Um modelo do cartão pode levar a logística (cerimónias e receção)',
         'personalizacao.php', 'function chavesModelo('],
        ['No editor de modelos, as cerimónias marcam-se como no do casal',
         'editor-cartao.php', 'as cerimónias marcam-se na mesma'],
        ['Mas são de exemplo: aplicar o modelo não reescreve as do casal',
         'api.php', 'nunca reescreve as cerimónias que o casal já marcou'],

        // ---- Ligações do Google Maps dos locais ----
        ['Cada cerimónia ganha a sua ligação do Google Maps',
         'personalizacao.php', "'evento.civil_maps'"],
        ['O campo do mapa abre o Google Maps e lê as coordenadas da ligação',
         'assets/maps-campo.js', 'function coordsDe('],
        ['Os formulários do casamento (gestão e registo) trazem os campos de mapa',
         'gestao.php', "'evento.civil_maps'"],
        ['O registo público leva as ligações do mapa dos locais',
         'registo.php', 'data-mapa data-mapa-local="civil_local"'],
        ['No convite, o local com mapa vira uma ligação "ver no mapa"',
         'personalizacao.php', "\$maps !== ''"],
        ['O form "Novo casamento" do admin também traz os campos de mapa',
         'plataforma.php', 'id="n-civil-maps"'],
        ['A ligação do mapa deixou de se editar no editor do convite digital',
         'convite-editor.php', "'grande-dia':['evento.local','evento.cidade']"],

        // ---- «Onde nos casamos»: as cerimónias em cartões ----
        ['As cerimónias e o copo d’água são cartões com emblema e moldura desenhada',
         'personalizacao.php', 'function seloCerimonia('],
        ['A moldura refaz-se à medida do cartão (festão nos lados, abóbada no copo d’água)',
         'assets/convite-base.html', 'function abobada('],
        ['Cada cartão leva o seu botão «Ver no mapa»',
         'personalizacao.php', 'class="cer-map"'],
        ['O cronograma do dia abre com as cerimónias, com o seu selo e o local',
         'personalizacao.php', 'function cerimoniasDoCronograma('],
        ['O emblema de cada cerimónia escolhe-se: o da casa, ou um símbolo desenhado',
         'personalizacao.php', 'function emblemaDesenhado('],
        ['Os emblemas mostram-se com ou sem ramos, e o conjunto cresce ou diminui',
         'convite-editor.php', 'function mudarTamanhoEmblema('],
        ['As molduras dos cartões podem mostrar-se ou esconder-se',
         'convite-editor.php', "alternarCer('cer.moldura')"],
        ['No editor, o cronograma abre com as cerimónias, por hora, com ícone à escolha',
         'convite-editor.php', 'function linhasCerimoniaCrono('],
        ['Cada ícone e cada emblema chama-se pelo nome, e não pela chave de programador',
         'personalizacao.php', 'function nomesIcones('],
        ['Retocar uma propriedade não mexe a tela: ela fica no ponto onde se estava a ler',
         'convite-editor.php', 'function ancoraTela('],
        ['E entra em fundido: a tela nova compõe-se por baixo e só depois se troca',
         'convite-editor.php', 'function trocarTela('],
        ['O emblema desenhado tem as medidas dos da casa: o anel não muda, só sai o louro',
         'assets/convite-base.html', '.cerimonias.sem-ramos .cer-medal'],
        ['As janelas de confirmação vestem-se sozinhas — também dentro dos editores',
         'assets/janela.css', 'body.editor .pl-modal'],
        ['As fotografias do convite carregam-se na página do convite digital, sem editor',
         'api.php', "if (\$acao === 'convite_foto_enviar')"],
        ['E a inscrição deixou de as pedir: a fotografia é do casal, não da licença',
         'personalizacao.php', 'function seccoesDeFoto('],
        ['As fotografias vivem numa aba da peça, no lugar do link para o painel',
         'digital.php', 'function pecaAba('],
        ['E enquadram-se ali mesmo: arrastar escolhe o que fica à vista',
         'api.php', "if (\$acao === 'convite_foto_posicao')"],
        ['A lupa mostra cada fotografia inteira, com a moldura da secção por cima',
         'digital.php', 'function ftJanelaMedidas('],
        ['E o enquadramento guarda-se num botão — arrastar é rascunho até lá',
         'digital.php', 'function ftSujo('],
        ['A moldura tem a forma da janela do convite, medida e não adivinhada',
         'personalizacao.php', "'proporcao'=>'390/844'"],
        ['A fotografia da história também recorta, e por isso também se enquadra',
         'assets/convite-base.html', '--foco-historia'],
        ['As secções com fotografia saem do convite do casal, e não de uma lista à parte',
         'personalizacao.php', 'foreach (ordemBlocos($defs) as $id)'],
        ['A galeria da casa é material de modelo: o casal não escolhe de lá',
         'api.php', 'A galeria da casa não se abre aqui'],
        ['A peça de origem que o casal viu ao pedir a licença fica a ser a dele',
         'personalizacao.php', 'function fixarPecaOrigemDoCasal('],
        ['O cabeçalho conta os dias que faltam, em todas as páginas da casa',
         'parcial-cabecalho.php', 'function contagem('],
        ['E diz sempre de quem é a festa: casal e data no mesmo sítio, em toda a parte',
         'parcial-cabecalho.php', 'topo-casal'],
        // Esta dizia «e conta ao segundo». Deixou de ser verdade, e de
        // propósito: ao segundo só na semana da festa (EMO-001). Uma marca que
        // continuasse a afirmar o contrário passava a guardar uma mentira.
        ['A contagem ficou no lugar da data, e abranda com a distância',
         'parcial-cabecalho.php', 'var quero = perto ? 1000 : 60000;'],
        ['Os cartões do orçamento filtram as despesas e o calendário',
         'assets/orcamento.js', 'window.orcFiltrarEstado'],
        ['E o que falta pagar desconta as prestações já liquidadas, na lista como no cartão',
         'assets/orcamento.js', 'function porPagar('],
        ['Cada ação do registo tem nome por extenso, e a linha abre com tudo o que se sabe',
         'db.php', 'function nomesDeAcao('],
        ['E o registo passa a guardar de onde partiu cada ação (esquema v35)',
         'db.php', "migColuna(\$conn, \"{\$P}registo\", 'ip'"],

        // ---- Os modelos de origem da casa (esquema v15) ----
        ['O impresso e o digital de origem constam da lista de modelos',
         'db.php', 'os modelos de origem da casa'],
        ['Aplicar um modelo é ficar com ele — o de origem devolve a peça à origem',
         'api.php', 'Aplicar um modelo é FICAR com ele'],

        // ---- Visibilidade dos modelos por casamento (esquema v16) ----
        ['Um modelo pode destinar-se a todos os casais ou só aos escolhidos',
         'db.php', 'visibilidade dos modelos por casamento'],
        ['O admin escolhe quem vê cada modelo, no painel dos modelos',
         'modelos.php', 'function quemVe('],
        ['As opções de um modelo abrem numa janela, e não espremidas no cartão',
         'modelos.php', 'id="ov-modelo"'],
        ['O casal só vê e aplica os modelos que lhe são destinados',
         'api.php', "alcance='todos' OR id IN"],

        // ---- Um modelo é desenho, não é o casal que o compôs ----
        ['Um modelo novo nasce com noivos e evento de exemplo, não com os da oficina',
         'personalizacao.php', 'function instantaneoModelo('],
        ['Aplicar um modelo impõe o desenho e não toca no nome de ninguém',
         'personalizacao.php', 'function chavesDesenho('],
        ['Um modelo do cartão guarda o casal de exemplo, que é o corpo da prova',
         'personalizacao.php', "'casal.noiva', 'casal.noivo', 'evento.data'"],
        ['Um modelo já feito não se reescreve por baixo de quem o desenhou',
         'personalizacao.php', 'já feito não se reescreve'],

        // ---- Os dados de exemplo dos modelos, à mão do admin (esquema v19) ----
        ['O admin edita o casal, o evento e as imagens com que um modelo nasce',
         'personalizacao.php', 'function exemploModelo('],
        ['E fá-lo na página dos modelos, com as imagens à vista',
         'modelos.php', 'data-vista="exemplo"'],
        ['Os dados de exemplo são a identidade inteira, e não meia dúzia de campos',
         'personalizacao.php', 'Derivada, e não escrita à mão'],
        ['Cada um valida como sempre validou — sem uma segunda cópia das regras',
         'api.php', '$limpo = validarDefinicao($k, $v);'],
        ['As imagens de exemplo são fotografias, e de uma galeria da casa',
         'personalizacao.php', 'function galeriaExemplo('],
        ['O admin escolhe qual usar em cada secção, numa janela com todas',
         'modelos.php', 'function abrirGaleria('],
        ['A galeria é uma lista só, arrumada por separadores de categoria',
         'personalizacao.php', 'function categoriasGaleria('],
        ['Incluindo «sem categoria», para guardar sem decidir o lugar já',
         'modelos.php', 'function mudarAba('],
        ['E acrescenta as suas, na categoria que quiser',
         'personalizacao.php', 'function galeriaCompleta('],
        ['Que se arrumam depois noutra categoria',
         'api.php', "acao === 'modelo_exemplo_categoria'"],
        ['As que ele enviou apagam-se; as da casa não',
         'api.php', "acao === 'modelo_exemplo_apagar'"],
        ['E a galeria abre-se também só para a arrumar, sem secção nenhuma',
         'modelos.php', 'onclick="abrirGaleria()"'],
        ['As fotografias do convite de origem vivem na galeria, com as outras',
         'personalizacao.php', "'capa-isabel-abednego.jpg'"],
        ['As da casa também se tiram da galeria — escondendo, para se poderem repor',
         'personalizacao.php', 'function galeriaOcultas('],
        ['E repõem-se todas de uma vez',
         'api.php', "acao === 'modelo_exemplo_repor'"],

        // ---- Versões e modelos: um painel, e não um <select> de tudo ----
        ['O botão da barra diz o estado da peça; o painel é que trata do resto',
         'assets/editor.css', '.btn-versao{'],
        ['Versões e modelos em abas separadas — não são a mesma coisa',
         'assets/versoes.js', 'function htmlModelos('],
        ['Os modelos escolhem-se a olho, pela sua cara desenhada a sério',
         'assets/versoes.js', 'function escalarProvas('],
        ['O casal vê a prova de um modelo que lhe seja destinado',
         'personalizacao.php', 'O CASAL está a escolher'],
        ['E vê-a com o seu nome: a miniatura mostra o resultado, não o modelo',
         'personalizacao.php', 'miniatura não promete nada'],
        ['Aplicar um modelo que já estava em vigor di-lo, em vez de fingir',
         'api.php', "'mudou'] = \$antesDefs"],

        // ---- A casa oferece mesmo outros desenhos (esquema v20) ----
        ['Os modelos da casa são desenhos diferentes, e não a origem com dois nomes',
         'db.php', 'modelos da casa que sejam MESMO outros desenhos'],
        ['O desenho de origem é um modelo como os outros, com nome próprio',
         'db.php', "SET nome='Isabel & Abednego'"],
        ['«Em vigor» é o modelo que foi mesmo aplicado, e não todos os de igual desenho',
         'personalizacao.php', 'function modeloEmVigorId('],
        ['Aplicar um modelo guarda-o como o em vigor',
         'api.php', "marcarModeloEmVigor(\$conn, \$m['ambito'], \$id);"],
        ['Editar à mão, ou aplicar uma versão, tira o modelo de vigor',
         'api.php', 'esquecerModeloEmVigor($conn, $amb, true)'],
        ['A lista distingue «mesmo desenho» de «em vigor» — vários vs. um só',
         'api.php', "\$m['em_vigor'] = \$m['mesmo_desenho'] &&"],
        ['Um modelo já em vigor não se oferece para aplicar outra vez',
         'assets/versoes.js', 'var jaEsta = !!m.em_vigor;'],
        ['E a barra diz o nome do modelo em vigor, em vez de «Alterado»',
         'assets/versoes.js', 'function modeloEmVigor('],
        ['Um modelo feito do zero também nasce com a identidade de exemplo',
         'api.php', 'comIdentidadeDeExemplo($conn, $ambito, padraoAmbito($ambito))'],
        ['O convite de origem ficou como sempre esteve — mexeu-se nos modelos',
         'db.php', "valor LIKE 'assets/convite/casal/%'"],
        ['O menu "⋯" vira-se para cima quando não há espaço em baixo',
         'assets/menu-mais.js', "classList.add('acima')"],
        ['O modelo em vigor manda na barra sobre a «Original» de origem derivada',
         'assets/versoes.js', 'if (v && v.padrao && mod) v = null;'],
        ['Um modelo empresta as fotos às secções que o casal ainda não mexeu',
         'api.php', 'foreach (fotosDeModelo() as $kMedia => $kFoto) {'],
        ['A peça diz o nome do modelo de onde veio, e não «Original»',
         'personalizacao.php', 'function modeloDaPeca('],
        ['Alterar um desenho da casa obriga a uma versão do casal, com nome',
         'api.php', "'precisa_versao' => true"],

        // ---- O bar da festa (fases 1 a 4) ----
        ['Bar: oito tabelas próprias, com o token do QR em cada mesa',
         'db.php', "bar_pedido_itens"],
        ['Bar: o menu monta-se na página dos noivos, com fotografia e stock',
         'bar.php', "barGarantirTokens(\$conn)"],
        ['Bar: as regras do bar guardam-se fora do vocabulário do convite',
         'db.php', 'function barGuardarDefs('],
        ['Bar: o convidado entra pelo token da mesa, sem sessão nem link no convite',
         'bebidas.php', 'barMesaDoToken($conn, $token)'],
        ['Bar: o nome vem de uma procura com mínimo de letras, e prende o telemóvel',
         'assets/bar-convidado.js', "chamar('bar_sou'"],
        ['Bar: a mesa de entrega escolhe-se — as pessoas trocam de lugar',
         'assets/bar-convidado.js', "id: 'mesa-entrega'"],
        ['Bar: a copa aprova ou recusa com motivo, e nunca deixa no limbo',
         'assets/bar-copa.js', "decisao: 'recusar'"],
        ['Bar: o stock real só desce na entrega; antes disso está prometido',
         'api.php', "barMoverStock(\$conn, \$li['item_id'], -\$li['quantidade'], 'entrega'"],
        ['Bar: o garçom apanha, entrega, ou devolve à copa o que não achou',
         'assets/bar-entrega.js', 'window.entFalhou = function'],
        ['Bar: quem não tem rede pede ao garçom, que lança por ele',
         'api.php', "\$acao === 'bar_pedir_por'"],
        ['Bar: os tempos da noite separam análise, recolha e percurso',
         'api.php', 'function barTempos('],
        ['Bar: a Gestão convida copeiros e garçons, e não só porteiros',
         'gestao.php', "\$postos['copeiro']"],
        ['Bar: cada posto tem a sua porta — copa e entregas',
         'auth.php', 'function exigirCopa('],

        // ---- Fase 5: os limites ----
        ['Bar: o limite mais específico substitui o geral, e não se soma a ele',
         'api.php', 'function barLimiteQueManda('],
        ['Bar: as regras de «pedidos» travam o acto de pedir, não uma bebida',
         'api.php', 'function barVeredictoPedido('],
        ['Bar: o caudal da casa corre por cima de todos os limites individuais',
         'api.php', 'function barRitmoDaCasa('],
        ['Bar: a espera explica-se e sugere o que sai já, em vez de fechar a porta',
         'api.php', 'function barAlternativas('],
        ['Bar: a regra lê-se em voz alta, escrita no servidor e não no ecrã',
         'api.php', 'function barRegraFrase('],
        ['Bar: uma regra nova assinala a fila, mas não recusa nada sozinha',
         'api.php', 'function barFilaContraRegras('],
        ['Bar: a ficha do convidado, com o que levou e as regras dele',
         'assets/bar-copa.js', 'window.copaFicha ='],

        // ---- Fase 6: um telemóvel, uma pessoa ----
        ['Bar: trocar de nome é livre no convite, assinalado fora dele',
         'api.php', "barDef(\$conn, 'bar.trocar_nome')"],
        ['Bar: a conta das trocas faz-se ANTES de o nome ser reescrito',
         'api.php', 'trocas=trocas + (convidado_id <> VALUES(convidado_id)),'],
        ['Bar: as bandeiras apontam sem acusar, e a copa decide',
         'api.php', 'function barBandeiras('],
        ['Bar: o garçom lança um pedido por quem não tem rede, sem passar pela copa',
         'api.php', "\$acao === 'bar_itens_pedir'"],

        // ---- Fase 7: os números ----
        ['Bar: a previsão de rutura, e o silêncio honesto do que não tem saída',
         'api.php', 'function barRutura('],
        ['Bar: as recusas agrupam-se por motivo — o que correu mal na festa',
         'api.php', 'function barRecusas('],
        ['Bar: os números da noite numa leitura só, na aba da copa',
         'assets/bar-copa.js', 'Chega até ao fim?'],
        ['Bar: o convidado vê só o SEU consumo, sem parâmetro por onde espreitar',
         'api.php', "\$acao === 'bar_meu_consumo'"],
        ['Bar: o painel dos noivos ganha a tira do bar, só quando ele trabalha',
         'index.php', 'async function tiraDoBar('],

        // ---- Fase 8: as folhas das mesas ----
        ['Bar: a folha A4 com um cartão por mesa, para recortar e pousar',
         'bar-qr.php', 'Sem rede? Chame um garçom'],
        ['Bar: o código e a folha de cada mesa, na ficha dela na planta',
         'assets/mesas.js', 'bar-qr.php?mesa='],

        // ---- Fase 9: o que ficara por fazer ----
        ['Bar: uma regra pode ter hora de entrada, e não só de saída',
         'api.php', 'function barHoraMomento('],
        ['Bar: a copa continua a ver a regra que ainda não é hora de valer',
         'api.php', 'function barLimitesTodos('],
        ['Bar: uma regra de UMA PESSOA sobre tudo passa a morder',
         'api.php', "['tudo',      0,    'convidado', \$convidadoId, null],"],
        ['Bar: o erro do convidado diz o que é, em vez de «Não deu.»',
         'assets/bar-convidado.js', 'function porque('],
        ['Bar: a tinta de segunda voz é um token, e não um cinzento inventado',
         'assets/estilo.css', '--ink-fraco:'],

        // ---- O convidado pede por outro convidado ----
        ['Bar: um convidado pede por outro sem trocar o nome do telemóvel',
         'api.php', 'function barParaQuem('],
        ['Bar: a quota é de quem bebe, e não de quem pede',
         'api.php', '$vp = barVeredictoPedido($conn, $paraId, $conviteId);'],
        ['Bar: o pedido guarda os dois nomes, e a copa vê-os',
         'assets/bar-copa.js', 'p.pedido_por'],
        ['Bar: a pastilha «a pedir para» apaga-se sozinha depois de cada pedido',
         'assets/bar-convidado.js', 'window.barParaMim'],

        // ---- O bar viaja no retrato ----
        ['Bar: o retrato leva o menu, o stock, os motivos e as regras',
         'api.php', 'function impBar('],
        ['Bar: apagar ou substituir um casamento já não deixa órfãos do bar',
         'api.php', "'bar_pedido_itens','bar_pedidos','bar_stock_mov','bar_alertas','bar_limites',"],
        ['Bar: um casamento de exemplo, pronto a importar',
         'docs/exemplos/bar-exemplo.json', '"bar"'],

        // ---- O bar cresce numa direcção e encolhe noutra (§27) ----
        ['Bar: o código do convite saiu do módulo, e as colunas com ele',
         'db.php', "migLargarColuna(\$conn, \"{\$P}convites\", 'bar_pin')"],
        ['Bar: cada bebida diz a partir de quantas está «a acabar»',
         'db.php', "migColuna(\$conn, \"{\$P}bar_itens\", 'stock_minimo'"],
        ['Bar: a copa e a montagem leem o MESMO limiar, e não dois inventados',
         'api.php', "\$x['a_acabar'] = \$x['disponivel'] <= \$x['stock_minimo'];"],
        ['Bar: a copa pode servir menos do que se pediu, com o motivo',
         'api.php', "\$cortes = is_array(\$d['cortes'] ?? null)"],
        ['Bar: e o corte exige um porquê — quem recebe menos tem direito a sabê-lo',
         'api.php', 'Servir menos do que se pediu explica-se'],
        // «Nasce aprovado» deixou de valer para toda a gente na quinta
        // passagem (§30.1): vale para a copa, e o garçom submete. A linha que
        // guardava a versão antiga está mais abaixo, com o resto dessa passagem.
        ['Bar: e pode nascer entregue, quando o copo já seguiu na mão',
         'assets/bar-copa.js', "rot: 'Já foi entregue?'"],
        ['Bar: o garçom escreve o que viu à mesa, e a copa lê-o',
         'api.php', 'function barNotasDeEntrega('],
        ['Bar: a nota aparece colada ao pedido seguinte dessa pessoa',
         'assets/bar-copa.js', 'function notasDe('],
        ['Bar: as regras da casa e os motivos passam para a montagem',
         'assets/bar-montagem.js', 'function pintarRegras('],
        ['Bar: os noivos gerem copeiros e garçons a partir do bar',
         'assets/bar-montagem.js', 'function pintarEquipa('],
        ['Bar: «Os números» actualiza-se sem apagar o que se está a ler',
         'assets/bar-copa.js', 'var numAmarrado = false;'],
        ['Bar: com gráfico do que a festa bebeu e de quem bebeu mais',
         'api.php', 'function barPorConvidado('],
        ['Bar: o botão do tema existe nos ecrãs do bar, como no resto da casa',
         'copa.php', "include __DIR__ . '/parcial-seletor-tema.php'"],
        ['Bar: o botão do tema sobe quando o cesto enche — deixava de se poder tocar no «Pedir»',
         'assets/bar.css', 'body.com-rodape .tema-fab{'],
        ['Bar: e é do tamanho do módulo (48px), que se toca de pé e à meia-luz',
         'assets/bar.css', 'body.b-servico .tema-fab-btn,'],

        // ---- A terceira passagem: o módulo entra no sistema (§28) ----
        ['Bar: os quatro temas aplicam-se por inteiro — fim do salão escuro por decreto',
         'assets/bar.css', 'body.b-servico{ min-height:100vh; }'],
        ['Bar: e a paleta paralela do salão saiu dos tokens da casa',
         'assets/estilo.css', 'Viveu aqui, durante um tempo, uma família --sala-*'],
        ['Bar: «Regras do Bar» é uma aba da copa, e não um link que a expulsa',
         'assets/bar-copa.js', "['regras',  'Regras do Bar', 'trancado']"],
        ['Bar: um painel de regras só, montado na copa e na montagem',
         'assets/bar-regras.js', 'window.BR = {'],
        ['Bar: os limites e intervalos passam a ter ecrã, agrupados por alcance',
         'assets/bar-regras.js', 'function grupoDe(r) {'],
        ['Bar: uma regra da casa contada em pedidos trava o gesto, e não as bebidas',
         'api.php', "if (\$l['unidade'] !== 'bebidas') continue;"],
        ['Bar: e diz-o sem acusar quem lê — a copa é que está cheia',
         'api.php', 'A copa está a dar vazão aos pedidos que já tem'],
        ['Bar: o garçom lê as bebidas e volta a poder lançar pedidos',
         'assets/bar-entrega.js', "window.api('bar_itens_pedir'"],
        ['Bar: a regra de uma pessoa é a mesma janela, com o «a quem» preenchido',
         'assets/bar-copa.js', 'window.barRegraNova({ convidado_id: convidadoId'],
        ['Bar: a ficha deixa de listar telemóveis, e o «Soltar» sai com ela',
         'api.php', '`bar_soltar` viveu aqui'],
        ['Escolhas com muitas opções ganham procura por dentro (sem jQuery)',
         'assets/janela.js', 'function licSelProcuraHtml('],
        ['E a procura não olha a acentos, como o resto da casa',
         'assets/janela.js', 'function licChave('],
        ['Bar: a página do convidado abre com o nome da casa',
         'bebidas.php', 'class="b-festa-capa"'],
        ['Bar: e fecha a dizer o que acontece a seguir',
         'assets/bar-convidado.js', 'function rodapeDaCasa('],
                ['Bar: o convidado vê o que leva álcool (o selo de «poucas» saiu com o número)',
         'assets/bar-convidado.js', "marcas += '<span class=\"b-selo alc\""],

        // ---- A quarta passagem: o que ficou por afinar (§29) ----
        ['Bar: a conversa do wi-fi partilhado saiu — cada um pede pela sua rede',
         'api.php', '`barIpDeOutrem()` viveu aqui'],
        ['Bar: ninguém serve um pedido travado pelas regras, nem a copa',
         'api.php', 'function barTravaoDe('],
        ['Bar: e a conta desconta o próprio pedido, senão nunca se aprovava',
         'api.php', 'if ($excluir > 0) $bons .='],
        ['Bar: regras em duas famílias — gerais (a copa) e específicas',
         'assets/bar-regras.js', 'var FAMILIAS = ['],
        ['Bar: o formulário de uma regra mostra a frase que ela vai ser',
         'assets/bar-regras.js', 'var escrever = function (geral, porPedidos)'],
        ['Bar: qualquer regra se edita, em vez de se levantar e reescrever',
         'assets/bar-regras.js', 'window.barRegraEditar = function'],
        ['Bar: a copa dá por entregue um pedido que está por entregar',
         'assets/bar-copa.js', 'window.copaEntregue = async function'],
        ['Bar: o garçom muda a mesa de um pedido em vez de o devolver',
         'api.php', "\$acao === 'bar_mudar_mesa'"],
        ['Bar: a ficha da pessoa traz todas as notas dos garçons',
         'api.php', 'function barNotasDe('],
        ['Bar: o convidado vê em nome de quem está a pedir',
         'assets/bar-convidado.js', 'var quem = para ? para.nome'],
        ['Bar: a página do convidado é uma coluna só, do título ao rodapé',
         'assets/bar.css', 'max-width:560px; margin-inline:auto; }'],
        ['Bar: a procura do menu deixa de perder o cursor a cada letra',
         'assets/bar-convidado.js', "campoBusca('q-menu', 'Procurar uma bebida', '')"],
        ['Escolha com procura: veste um <select> que já existe na página',
         'assets/janela.js', 'function licSelUpgrade('],
        ['E o formulário de convite escolhe a mesa por procura',
         'index.php', "licSelUpgrade(div.querySelector('.m-mesa')"],

        // ---- A quinta passagem: a porta de serviço, e a bebida que se fecha (§30) ----
        ['Bar: o garçom submete à copa; decidir continua a ser um posto',
         'api.php', "\$estado = \$jaEntregue ? 'entregue' : (\$daCopa ? 'aprovado' : 'em_analise')"],
        ['Bar: e um pedido por decidir não promete stock nenhum',
         'api.php', 'elseif ($daCopa)   barReservar('],
        ['Bar: as regras valem também no balcão — não há porta de serviço',
         'api.php', 'if ($travao = barTravaoDe($conn, $gid'],
        ['Bar: uma bebida suspende-se por um bocado e volta sozinha',
         'assets/bar-copa.js', 'window.copaSuspender = function'],
        ['Bar: e os minutos contam-se no relógio da casa, não no do telemóvel',
         'api.php', "\$daqui = (int)(\$d['expira_min'] ?? 0)"],
        ['Bar: «agora não» não é «esta noite não» — a proibição com hora tem espera',
         'api.php', "'travao' => \$falta > 0 ? 'suspensa' : 'proibido'"],
        // O texto da bebida suspensa saiu do `switch` para a lista de textos de
        // fábrica (§31.5), onde é um modelo com variáveis como os outros.
        ['Bar: e o convidado lê quanto falta, e não que a bebida acabou',
         'api.php', 'está indisponível de momento'],
        ['Bar: uma bebida tem ficha de regras, como uma pessoa tem',
         'assets/bar-copa.js', 'window.copaRegrasDaBebida = function'],
        ['Bar: e chega-se-lhe pela barra de «Os números», onde o problema se vê',
         'assets/bar-copa.js', "accao: i ? 'copaRegrasDaBebida('"],
        ['Bar: o nome de quem entrou lê-se uma vez só, dentro da pastilha',
         'bebidas.php', 'Duas pastilhas e mais nada.'],
        ['Escolha com procura: a lista assenta onde cabe, em linhas inteiras',
         'assets/janela.js', 'const assentar = () =>'],
        ['E vira-se para cima quando o espaço está todo lá',
         'assets/janela.css', '.lic-sel-pop.acima{'],
        ['Janela: um botão do CORPO tem a forma dos do rodapé, e não só a cor',
         'assets/janela.css', '.j-bt{ font-family:inherit;'],

        // ---- A sexta passagem, fase 1: o esquema do motor assistido ----
        ['Bar: cada regra passa a ter modo — travar, sugerir, confirmar ou avisar',
         'db.php', "ENUM('trava','sugere','confirma','avisa') NOT NULL DEFAULT 'trava'"],
        ['Bar: e as que já estavam escritas continuam a travar, como faziam',
         'db.php', 'if ($versaoAtual < 40) {'],
        ['Bar: a bebida guarda com quantas a noite abriu, para a percentagem ter conta',
         'db.php', "migColuna(\$conn, \"{\$P}bar_itens\", 'base_noite'"],
        ['Bar: os alertas do motor têm tabela, e uma chave que impede o mesmo duas vezes',
         'db.php', 'CREATE TABLE IF NOT EXISTS {$P}bar_alertas'],
        ['Bar: e o que se diz ao convidado em cada situação também',
         'db.php', 'CREATE TABLE IF NOT EXISTS {$P}bar_mensagens'],
        ['Bar: as duas tabelas novas entram na vigia de âmbito, como as outras oito',
         'db.php', "'bar_alertas','bar_mensagens'"],
        ['Bar: os degraus da percentagem arrumam-se do maior para o menor',
         'db.php', "if (\$chave === 'bar.degraus_stock')"],
        ['Bar: as mensagens viajam no retrato; os alertas, não — são de um momento',
         'api.php', "\$barMensagens = \$um(\"SELECT situacao, texto, ativo"],

        // ---- A sexta passagem, fase 2: o motor mede e propõe ----
        ['Bar: só as regras em «trava» é que travam — e num sítio só',
         'api.php', "fn(\$l) => (\$l['modo'] ?? 'trava') === 'trava'"],
        ['Bar: e há a lista de todas as que valem, seja qual for o modo',
         'api.php', 'function barLimitesVivos('],
        ['Bar: o motor mede na leitura da copa, e não num processo à parte',
         'api.php', 'function barSugestoes('],
        ['Bar: uma chave por alerta — o mesmo aviso não nasce de 8 em 8 segundos',
         'api.php', 'function barAlertaVivo('],
        ['Bar: e a chave só se liberta quando a condição passa',
         'api.php', 'function barAlertaCaducar('],
        ['Bar: uma regra que não trava mede-se à mesma, senão era uma regra desligada',
         'api.php', 'function barSugereRegra('],
        ['Bar: e a de toda a gente conta por cabeça, numa consulta agrupada',
         'api.php', 'GROUP BY p.convidado_id HAVING n >= $tecto'],
        ['Bar: a base da noite nunca fica abaixo do que há — senão passava dos 100%',
         'api.php', 'AND base_noite < stock'],
        ['Bar: e fixa-se ao abrir, sem se refazer a meio da festa',
         'api.php', 'AND base_noite <= 0'],
        ['Bar: a percentagem é null enquanto a noite não abrir, e não zero',
         'api.php', "\$x['percentagem'] = \$x['base_noite'] > 0"],
        ['Bar: apagar uma bebida leva os alertas dela — não se propõe sobre o que não há',
         'api.php', "chave LIKE 'stock:\$id:%'"],

        // ---- A sexta passagem, fase 3: o painel de decisões ----
        ['Bar: a copa responde aos alertas — aplicar, adaptar ou ignorar',
         'api.php', "\$acao === 'bar_alerta_decidir'"],
        ['Bar: e o que se aplica é uma lista fechada de acções, não um «faça isto»',
         'api.php', 'function barAlertaAplicar('],
        ['Bar: adaptar muda o NÚMERO, e não a acção — senão o alerta virava outro',
         'api.php', "if (\$decisao === 'adaptar')"],
        ['Bar: ignorar fica escrito, com quem e porquê — é uma decisão como as outras',
         'db.php', "'bar_alerta'        => ['decidiu um alerta do bar'"],
        ['Bar: a chave liberta-se quando a condição passa, mesmo já respondida',
         'api.php', "VALUES (?,'fim','aviso',?,'{}','{}','caducado',NOW())"],
        ['Bar: e a marca de «passou» não se lê como alerta no painel',
         'api.php', "AND tipo <> 'fim'"],
        ['Bar: a copa em pausa reabre sozinha — ninguém tem de se lembrar dela',
         'db.php', 'function barPausaSegundos('],
        ['Bar: e a pausa trava o convidado E o balcão, sem porta de serviço',
         'api.php', 'A copa está em pausa por mais '],
        ['Bar: o painel dos alertas é a primeira pastilha da copa',
         'assets/bar-copa.js', "['alertas', 'Alertas',       'sino'],"],
        ['Bar: e o que ele mostra é português, e não o JSON que mediu',
         'assets/bar-copa.js', 'function situacaoEmPalavras('],
        ['Bar: «ver a regra» não é botão à parte — chega-se lá por «adaptar»',
         'assets/bar-copa.js', 'window.copaAlertaAdaptar = function'],

        // ---- A sexta passagem, fase 4: a voz da festa ----
        ['Bar: o que o convidado lê passa a ser escrito pelo casal',
         'api.php', 'function barMensagens('],
        ['Bar: e os textos de fábrica são UMA lista, que o editor também mostra',
         'api.php', 'function barTextosFabrica('],
        ['Bar: com as mesmas variáveis dos dois lados, trocadas pela mesma função',
         'api.php', 'function barTrocarVariaveis('],
        ['Bar: a da regra manda sobre a da situação, e esta sobre a de fábrica',
         'api.php', "\$sit = \$v['travao'] ?: 'corte';"],
        ['Bar: uma variável que a frase não use não chega ao convidado',
         'api.php', "preg_replace('/\\{[A-Z_]+\\}/u', '', \$t)"],
        ['Bar: apagar a frase é voltar ao de fábrica, e não guardar um vazio',
         'api.php', "\$acao === 'bar_mensagens_guardar'"],
        ['Bar: a mensagem de fechado herda-se da definição antiga, sem se perder',
         'api.php', "\$velha = trim(barDef(\$conn, 'bar.mensagem_fechado'));"],
        ['Bar: e o editor das frases vive com as outras regras do bar',
         'assets/bar-regras.js', 'function pintarMensagens('],

        // ---- A sexta passagem, fase 5: a pausa à mão ----
        ['Bar: a copa põe e levanta a pausa do seu cabeçalho, sem fechar o bar',
         'api.php', "\$acao === 'bar_pausa'"],
        ['Bar: os minutos são do ecrã, a hora é do servidor — a casa tem o seu fuso',
         'assets/bar-copa.js', 'window.copaPausa = function'],
        ['Bar: e o cabeçalho tem TRÊS estados, que a pausa não é um aberto com asterisco',
         'assets/bar-copa.js', "cx.classList.toggle('pausa', pausa);"],
        ['Bar: com o que falta a contar ao segundo, e não de oito em oito',
         'assets/bar-copa.js', 'function tiquePausa('],
        ['Bar: o menu do convidado sabe da pausa ANTES de o deixar escolher',
         'assets/bar-convidado.js', '} else if (pausa) {'],
        ['Bar: e o servidor manda-lha com o menu, não só na recusa do pedido',
         'api.php', "'pausa' => (function () use (\$conn) {"],
        ['Bar: pausar à mão fica no registo, como tudo o que muda a noite',
         'db.php', "'bar_pausa'         => ['pôs ou levantou a pausa da copa'"],

        // ---- A sexta passagem, fase 6: o fecho ----
        ['Bar: o modo escolhe-se na janela da regra, e não só pela API',
         'assets/bar-regras.js', "{ id: 'modo', rot: 'Quando o número for passado'"],
        ['Bar: e a frase da janela diz que aquela regra não recusa nada',
         'assets/bar-regras.js', 'var oQueFaz = function ()'],
        ['Bar: a lista assinala a regra que propõe em vez de travar',
         'assets/bar-regras.js', 'function pastilhaModo('],
        ['Bar: uma proibição só existe a travar — não se corrige em silêncio',
         'api.php', "if (\$qtd <= 0 && \$modo !== 'trava') {"],
        ['Bar: um alerta de «confirma» fica aberto até alguém responder',
         'api.php', 'function barAlertaPedeResposta('],
        ['Bar: e o painel diz quais é que estão à espera de resposta',
         'assets/bar-copa.js', "'<span class=\"b-al-pede\">pede resposta</span>' : '')"],

        // ---- A arrumação de bebidas.php e da aprovação parcial ----
        ['Bar: o menu do convidado não se reescreve quando não mudou nada',
         'assets/bar-convidado.js', 'if (pintado[caixa] === html) return false;'],
        ['Bar: as duas barras do topo têm a largura toda da coluna',
         'assets/bar.css', '.b-festa-topo .b-mesa{ width:100%; }'],
        ['Bar: e a procura do menu também, que ali está sozinha na linha',
         'assets/bar.css', '.b-festa .b-busca{ max-width:none; }'],
        ['Bar: a mesa de entrega escolhe-se com procura, como a pessoa',
         'assets/bar-convidado.js', "rot: 'Entregar em', classe: 'lic-sel-pagina'"],
        ['Bar: servir menos sabe quanto se pode servir ANTES de alguém escrever',
         'api.php', "\$acao === 'bar_tectos'"],
        ['Bar: e o número não sobe acima disso — nem escrito à mão',
         'assets/bar-copa.js', 'if (t && q > t.pode && !acima) acima ='],

        ['Bar: quem lança um pedido diz para onde vai a bebida',
         'assets/bar-pecas.js', 'function campoMesa('],
        ['Bar: e por omissão vai para a mesa da pessoa, como ia antes',
         'assets/bar-pecas.js', 'function mesaEscolhida('],
        ['Bar: a copa escolhe a mesa ao lançar do balcão',
         'assets/bar-copa.js', 'BP.campoMesa(mesas),'],
        ['Bar: e o garçom escolhe-a na sala, que é onde vê a pessoa',
         'assets/bar-entrega.js', 'BP.campoMesa(mesas)'],

        // ---- Os modelos e os dados de exemplo ----
        ['Modelos: a prova de um modelo não é o retrato do primeiro casal',
         'personalizacao.php', 'foreach (exemploModelo($conn) as $k => $v) $defs[$k] = $v;'],
        ['Modelos: e o que o casal ainda não pôs mostra-se com os dados de exemplo',
         'personalizacao.php', "if ((\$proprias[\$k] ?? '') === '') \$defs[\$k] = \$v;"],

        // ---- O desenho do bar: peças, ícones, procura ----
        ['Bar: os desenhos da casa num módulo só — seis copos e os sinais',
         'assets/icones.js', 'function copoDe('],
        ['Bar: uma caixa de ferramentas para os quatro ecrãs, e não quatro',
         'assets/bar-pecas.js', 'window.BP = {'],
        ['Bar: a chapa desenhada substitui a inicial em corpo grande',
         'assets/bar.css', '.b-chapa{'],
        ['Bar: procurar na fila da copa por código, nome, mesa ou bebida',
         'assets/bar-copa.js', "campoBusca('q-fila'"],
        ['Bar: procurar no armazém da copa',
         'assets/bar-copa.js', "campoBusca('q-stock'"],
        ['Bar: procurar nas entregas por mesa, nome ou bebida',
         'assets/bar-entrega.js', "campoBusca('q-ent'"],
        ['Bar: o convidado procura a bebida e filtra por gaveta',
         'assets/bar-convidado.js', 'window.barGaveta = function'],
        ['Bar: a mesa é o título do cartão de quem entrega',
         'assets/bar.css', '.b-destino .mesa{'],
        ['Bar: as listas em janela vestem-se com as cores da janela',
         'assets/janela.css', '.j-linha{'],
        ['Bar: um sim/não em janela mostra a pergunta, e não só a resposta',
         'assets/janela.js', "+ '<label for=\"lf-' + c.id + '\">' + licEsc(c.rot) + '</label>'"],
        ['Bar: nem um emoji nas quatro páginas — o visto do tema também saiu',
         'parcial-seletor-tema.php', 'M20 6.5 9.2 17.3 4 12.1'],

        // ---- A escolha com procura, os sinais desenhados, e o menu que não pisca ----
        ['A escolha com procura veste TODOS os <select> do sistema',
         'assets/janela.js', 'function licSelVestirTodos('],
        ['A escolha põe-se num valor por fora, sem se reconstruir',
         'assets/janela.js', 'function licSelDefinir('],
        ['bebidas.php: a mesa de entrega escolhe-se na página, sem janela',
         'assets/bar-convidado.js', 'function montarBarraMesa('],
        ['Sinais desenhados por nome, escritos em HTML',
         'assets/icones.js', 'function vestir('],
        ['Os sinais desenhados alinham como uma letra, em todo o sistema',
         'assets/estilo.css', '[data-ico] > svg{'],
        ['O emblema da casa é um desenho, e o monograma é texto',
         'config.php', "'mono'  => 'GC'"],
        ['O ícone de um módulo é o NOME de um sinal, e não um emoji',
         'db.php', "MODIFY icone VARCHAR(24)"],
        ['O admin escolhe o sinal do módulo de uma lista, com procura',
         'plataforma.php', 'function licIconesOpcoes('],
        ['bebidas.php: o menu não pisca — a contagem vive no relógio, não na pintura',
         'assets/bar-convidado.js', 'function poe(caixa, id, html)'],
        ['bebidas.php: o menu tem esqueleto fixo, e só muda o que mudou',
         'assets/bar-convidado.js', 'function esqueleto('],

        // ---- O stock que o convidado não lê, e o posto que decide ----
        ['O convidado não lê quanto stock resta',
         'assets/bar-convidado.js', 'O que se diz sobre a quantidade: NADA'],
        ['E o número nem sai do servidor para o menu do convidado',
         'api.php', '`disponivel` NÃO sai por aqui'],
        ['Quem lança do posto das entregas submete à copa, mesmo sendo admin',
         'api.php', "\$daCopa = podeCopa() && \$posto === 'copa';"],

        // ---- A escolha da casa: dimensões, e a janela que não se mexe ----
        ['Abrir uma escolha já não rola a janela por baixo dela',
         'assets/janela.js', 'Pôr a lista onde ela caiba — SEM mexer na janela'],
        ['A lista troca de lado só quando ganha mesmo espaço com isso',
         'assets/janela.js', 'const GANHO = Math.max(48, linha);'],
        ['A lista mede-se pelo que mostra, e não pela largura do campo',
         'assets/janela.css', 'width:max-content; min-width:100%;'],
        ['E encosta-se para dentro quando essa largura passaria a borda',
         'assets/janela.js', 'const passa = (cxR.left + pop.offsetWidth) - (limite.right - RESPIRO);'],
        ['O tecto da lista acompanha a altura do ecrã',
         'assets/janela.css', 'max-height:min(340px, 44vh)'],
        ['Alvos de 48px nas listas dos ecrãs de serviço',
         'assets/janela.css', '.b-cx .lic-sel-op, .b-festa .lic-sel-op{ min-height:48px; }'],
        ['Rolar dentro da lista já não a faz piscar',
         'assets/janela.js', 'if (e.target && e.target.nodeType === 1 && pop.contains(e.target)) return;'],
        ['E remedir a lista não lhe limpa a altura para a voltar a pôr',
         'assets/janela.js', 'NÃO MEXE NO DOM PARA MEDIR, e só escreve o que mudou'],
        ['A lista do combo das mesas já não se fecha debaixo do dedo',
         'assets/mesas.js', 'const rolouPorFora = (e) => !(comboAberto && e.target && e.target.nodeType === 1'],

        // ---- Auditoria de UI/UX: fases 1 e 2 (docs/auditoria-ui-ux.md §25) ----
        ['A cor de texto e a de preenchimento são tokens diferentes',
         'assets/estilo.css', '--gold-texto:#3C7517;'],
        ['E o que se escreve por cima do preenchimento tem token próprio',
         'assets/estilo.css', '--sobre-gold:var(--ivory);'],
        ['O gradiente do botão mede-se pelos dois extremos, não pela média',
         'assets/estilo.css', '--btn-a:#3C7517; --btn-b:#2F5C12; --btn-txt:#FFFFFF;'],
        ['No tema escuro o --gold-deep é CLARO, porque o --gold-pale é escuro',
         'assets/estilo.css', '--gold-deep:#9BDC63;'],
        ['O anel de foco da casa deixou de ser exclusivo das páginas de serviço',
         'assets/estilo.css', 'outline-width:2px; outline-style:solid; outline-color:var(--gold-texto);'],
        ['O botão anima propriedades nomeadas, e não o outline-width',
         'assets/estilo.css', 'transition:background-color .18s, color .18s, border-color .18s,'],
        ['44px de alvo mínimo, e só onde há dedo',
         'assets/estilo.css', '@media (pointer:coarse){'],
        ['O que muda sem recarregar é anunciado a quem lê o ecrã',
         'assets/api.js', 'function anunciar(msg) {'],
        ['Há uma região viva no cabeçalho partilhado',
         'parcial-cabecalho.php', 'id="avisos-vivos" class="so-leitor" role="status" aria-live="polite"'],
        ['E uma ligação de salto antes das doze do menu',
         'parcial-cabecalho.php', '<a class="salta-conteudo" href="#conteudo">Saltar para o conteúdo</a>'],
        ['O conteúdo de cada página vive dentro de um <main> só',
         'index.php', '<main id="conteudo">'],

        // ---- A escala tipográfica (docs/auditoria-ui-ux.md §18.2 e §25) ----
        ['Há uma escala tipográfica, e são oito degraus',
         'assets/estilo.css', '--t-etiqueta:.6875rem;'],
        ['O chão do sistema são 13px — abaixo disso só etiquetas',
         'assets/estilo.css', '--t-apoio:.8125rem;'],
        ['O texto corrente deixou de ser leve',
         'assets/estilo.css', 'color:var(--text); font-weight:400;'],
        ['A nota dentro de um rótulo volta a ser uma frase',
         'assets/estilo.css', 'label small, label .opt{'],
        ['E os 44px do menu valem só onde há dedo',
         'assets/estilo.css', '.topo .nav a{ min-height:44px; }'],

        // ---- A navegação no telemóvel (NAV-001) ----
        // Foi uma barra fixa em baixo com uma folha para o resto. Resolvia o
        // problema de encontrar os destinos e criava outro: era a TERCEIRA
        // coisa a flutuar por cima da página e tapava 39 dos 44px do botão do
        // tema. Passou a ser uma gaveta, que só existe quando se pede.
        ['No telemóvel os destinos vivem numa gaveta lateral',
         'parcial-cabecalho.php', '<nav class="gaveta'],
        ['E a escolha do tema vive lá dentro, em vez de flutuar num canto',
         'parcial-cabecalho.php', 'data-gv-tema='],
        ['A tira do cabeçalho recolhe quando a gaveta a substitui',
         'assets/estilo.css', '.topo .nav{ display:none; }'],
        ['O fundo escondido não tapa a página — [hidden] perde para uma classe',
         'assets/estilo.css', '.gaveta-fundo:not([hidden]){'],
        ['O foco fica preso na gaveta enquanto ela estiver aberta',
         'parcial-cabecalho.php', 'if (e.shiftKey && document.activeElement === primeiro)'],
        ['O cabeçalho mede-se depois de as fontes chegarem, e não antes',
         'parcial-cabecalho.php', 'new ResizeObserver(function () { medir(); }).observe(topo);'],
        ['Fixar o cabeçalho e reservar-lhe o lugar são a MESMA decisão',
         'assets/estilo.css', 'body.topo-fixo .topo{'],
        ['E quem faz o seu próprio topo não apanha o fixo sem quem o meça',
         'parcial-cabecalho.php', "document.body.classList.add('topo-fixo');"],
        ['A contagem nunca se perde: é o nome do casal que encolhe',
         'assets/estilo.css', '.topo .sub.topo-casal .tc-nome{'],

        // ---- Mexer uma mesa sem a arrastar, no telemóvel ----
        ['As setas empurram a mesa um passo de cada vez',
         'assets/mesas.js', 'async function empurrarMesa(dx, dy){'],
        ['E o «pôr aqui» põe-na onde o toque seguinte cair',
         'assets/mesas.js', 'function porAqui(){'],
        ['Numa página chamada Planta de Mesas, a planta vê-se ao chegar',
         'mesas.php', 'id="barra-add-dobra"'],

        // ---- A fotografia da bebida cabe inteira ----
        ['A fotografia de uma bebida cabe inteira, em vez de ser cortada',
         'assets/bar.css', '.b-foto img{ width:100%; height:100%; object-fit:contain;'],
        ['E o cartão da montagem mostra-a inteira também',
         'assets/bar.css', '.b-cart .capa img{ width:100%; height:100%; object-fit:contain;'],
        ['Há um sinal de reticências para dizer «e há mais»',
         'assets/icones.js', 'reticencias:'],

        // ---- Cada casamento vê o histórico DELE, e só o dele ----
        // registar() escrevia sempre no casamento aberto: o que era da casa
        // caía no histórico de quem por acaso estivesse aberto (um casal lia
        // lá o nome e o tamanho da festa de outro), e o que se decidia SOBRE um
        // casamento a partir da plataforma caía no zero, que o casal não vê.
        ['Quem decide sobre um casamento diz qual, e a linha fica no histórico dele',
         'db.php', 'function registar(mysqli $conn, string $accao, string $alvo = \'\', string $detalhe = \'\','],
        ['E o que é da casa fica na casa, sem poder escorregar para um casamento',
         'db.php', 'function registarDaCasa(mysqli $conn, string $accao, string $alvo = \'\', string $detalhe = \'\'): void {'],
        ['O histórico já escrito foi arrumado, casa por casa',
         'db.php', '    if ($versaoAtual < 44) {'],
        ['Reabrir o casamento que já estava aberto não escreve linha nenhuma',
         'api.php', '$jaAberto = (int)($_SESSION[\'casamento_id\'] ?? 0) === (int)$c[\'id\'];'],
        ['Cada ação do casal é uma frase, com a conta lá dentro',
         'index.php', 'grid-template-areas:"quando frase fam"; }'],
        ['E no telemóvel o quadro do admin vira cartões em vez de transbordar',
         'plataforma.php', '#aud-tabela .a-linha{ display:flex; flex-direction:column; position:relative;'],

        // ---- O cabeçalho sai do caminho (UI-002) ----
        ['No telemóvel o cabeçalho é fixo e o corpo guarda-lhe o lugar',
         'assets/estilo.css', 'body.topo-fixo{ padding-top:var(--topo-alt, 0px); }'],
        ['E encolhe a trabalhar, com o limiar medido na altura dele',
         'parcial-cabecalho.php', "CURTO = h;            // só encolhe quando ele já saiu de vista"],

        // ---- Uma primária por contexto (CTA-001) ----
        ['O painel tem UMA ação principal, e as de uma vez só vão para trás do «⋯»',
         'index.php', 'function abrirAccoes(ev){'],
        ['Os dois menus «⋯» do painel desenham-se no mesmo sítio',
         'index.php', 'function mostrarPop(ev, itens){'],

        // ---- Esqueletos e estados vazios (EST-001) ----
        ['Há uma peça de esqueleto e uma de vazio para a casa toda',
         'assets/estados.js', 'raiz.EST = { esqueleto: esqueleto, vazio: vazio };'],
        ['O esqueleto não pulsa a quem desligou as animações',
         'assets/estilo.css', '@media (prefers-reduced-motion:reduce){ .esqueleto{'],
        ['O vazio tem título, porquê e o gesto que o resolve',
         'assets/estilo.css', '.vazio b{ display:block;'],
        ['O painel marca o lugar da lista com o número certo de linhas',
         'index.php', 'const ESQ_LINHAS ='],
        ['A zero, não há esqueleto nenhum — encolher para o vazio era um salto',
         'plataforma.php', 'const ESQ_CASAMENTOS ='],
        ['E a grelha dos modelos também marca o lugar antes das provas chegarem',
         'modelos.php', 'const ESQ_MODELOS ='],
        ['Um vazio por causa de um filtro confessa-o, em vez de dizer que não há nada',
         'index.php', "EST.vazio('procurar', 'Nenhum convite com estes filtros',"],

        // ---- O resumo do funil da licença (CONV-001 e CONV-002) ----
        ['A altura da barra de baixo é medida, para quem se cola ao fundo a saber',
         'parcial-cabecalho.php', "setProperty('--nav-baixo-alt'"],
        ['E a conta do funil cola-se ao fundo LIVRE, não por baixo da barra',
         'assets/planos.css', 'bottom:var(--nav-baixo-alt, 0px)'],
        ['A conta diz o degrau que se leva, e não «3 módulo(s)»',
         'assets/planos.js', 'function nomesEscolhidos(c) {'],
        ['O gesto de pedir está na conta, onde a decisão se toma',
         'licenca.php', 'function vestirConta(c){'],
        ['E está à vista que um pedido se pode cancelar enquanto espera',
         'assets/planos.css', '.pl-conta-promessa{'],

        // ---- As perguntas da confirmação (RSVP-001) ----
        ['A confirmação deixa de ser só «vem ou não vem»',
         'db.php', 'CREATE TABLE IF NOT EXISTS {$P}rsvp_perguntas'],
        ['E as respostas são chave/valor, que é a forma do que lá está',
         'db.php', 'CREATE TABLE IF NOT EXISTS {$P}rsvp_respostas'],
        ['O prato pergunta-se a cada pessoa, e quem não vem não responde',
         'convite.php', 'function campoRsvp(array $p, int $quem, array $respostas): string {'],
        ['O que vem de fora não escolhe o que se guarda',
         'api.php', 'function guardarRespostasRsvp(mysqli $conn, int $conviteId, array $respostas): void {'],
        ['E o resumo soma-se sozinho, com quantos ainda faltam',
         'db.php', 'function rsvpResumo(mysqli $conn): array {'],
        ['O casal faz as suas perguntas, e a chave prende-se depois de existir',
         'index.php', 'const presa = !p.nova && p.chave;'],

        // ---- O prazo e os lembretes (RSVP-002) ----
        ['O convite diz até quando se espera resposta',
         'convite.php', "\$prazo = (string)(\$DEFS['rsvp.prazo'] ?? '');"],
        ['E o painel diz a quem falta, e a quem já se tocou',
         'index.php', 'async function abrirLembretes(){'],
        ['O lembrete fica marcado, para a segunda volta não repetir a primeira',
         'db.php', "migColuna(\$conn, \"{\$P}convites\", 'rsvp_lembrete_em'"],

        // ---- Onde vai cada módulo (UX-010) ----
        ['O painel diz onde vai cada módulo da licença',
         'api.php', "if (\$acao === 'painel_progresso') {"],
        ['E só os que a licença abre — uma barra do que não se usa é uma montra',
         'index.php', 'async function carregarTiraModulos(){'],

        // ---- Um número, um sítio (o painel deixa de se contradizer) ----
        // Havia DUAS tiras de cartões, e quatro rótulos apareciam nas duas com
        // números diferentes: «Confirmados 0» em cima e «Confirmações 16 de
        // 19» em baixo, «Impressos 7» e «Impressos 5 de 13». Os números até
        // estavam certos — contavam unidades diferentes —, mas nenhum dizia
        // qual, e o mesmo rótulo duas vezes no mesmo ecrã lê-se como erro.
        ['Cada número do painel vive num cartão só, e a linha de baixo diz o que conta',
         'index.php', 'function subProg(chave, nome){'],
        ['O cartão leva à página dona do número, sem deixar de filtrar a lista',
         'index.php', '.stat-cx{ position:relative; display:grid; }'],
        ['E a seta do canto vê-se: a 2,2:1 era um comando invisível',
         'index.php', '  .stat-ir:hover{ background:var(--cream); color:var(--gold-texto); }'],
        ['Os cartões à vista escolhem-se sem abrir os «Mais filtros»',
         'index.php', 'function moverCartao(chave, d){'],
        ['E a escolha é de cada casamento, e aguenta o recarregar',
         'index.php', "function chaveOrdem(){ return 'painel.cartoes.' + (window.CASAMENTO_ID || 0); }"],

        // ---- O orçamento de quem paga a prestações ----
        ['O quarto cartão mostra a margem, e cede o lugar ao atraso quando o há',
         'assets/orcamento.js', "l: 'Margem', cls: 'margem'"],
        ['A margem sai da legenda: o mesmo número duas vezes lê-se como dois',
         'assets/orcamento.js', "if (r.base > 0 && r.falta < 0) {"],
        ['Uma parcela por pagar diz «data limite», e a vermelho',
         'assets/orcamento.js', ": (p.data_prevista ? 'data limite ' + p.data_prevista : 'sem data limite');"],
        ['E avisa-se o que vence nos próximos catorze dias, dizendo de quê',
         'assets/orcamento.js', 'function avisoPrazos(lista) {'],
        ['As parcelas do mesmo produto andam juntas, com a conta do produto',
         'assets/orcamento.js', "if (AGRUPAR === 'produto') {"],
        ['E o calendário fica a um toque, para quem quer a ordem do tempo',
         'assets/orcamento.js', 'window.orcAgrupar = function (m) {'],

        // ---- Nenhum enchimento serve de tinta (a regra, agora guardada) ----
        ['Uma prova LÊ AS FOLHAS: o que o ecrã esconde, ela vê na mesma',
         'tests/chk_tokens_tinta.js', 'const TINTA = l => FOREST.test(l)'],
        ['«Mais filtros» e os ícones do painel leem-se no tema escuro',
         'index.php', 'border-radius:12px; padding:.55rem; font-family:inherit; font-size:var(--t-denso); color:var(--gold-texto); cursor:pointer; }'],
        ['E o ícone do cartão escolhido também — --gold-pale ali dentro era 1,55:1',
         'index.php', '.stat-f.ativo .si{ background:rgba(255,255,255,.15); color:var(--topo-txt); }'],
        ['Os sinais verde e vermelho viram com o tema em vez de serem cravados',
         'index.php', '.stat-f.verde .si{ color:var(--ok); }'],

        // ---- O painel e o orçamento contam o mesmo dinheiro ----
        ['O cartão das despesas conta as parcelas, como a página do orçamento',
         'api.php', '$ro = orcamentoResumo($conn);'],

        // ---- O que é do DIA só aparece no dia ----
        ['«Entradas» é conta de uma noite, e não aparece três meses antes',
         'index.php', "const doDia = new Set(['porta']);"],

        // ---- Cada casamento diz o nome do SEU casal ----
        ['Sem noiva e noivo na ficha, os nomes saem do nome do casamento',
         'personalizacao.php', '// painel e via, no cabeçalho e no monograma, o nome de outras'],
        ['E um casamento de um nome só não fica com meio monograma',
         'personalizacao.php', '    // Com um lado só — um casamento cujo nome não se parte em dois —, não se'],

        // ---- A tira de chegada é da hora da festa ----
        // Aparecia mal o último convite respondesse: num casamento de Dezembro
        // cuja lista fecha em Março, são nove meses de tira no cimo do painel.
        ['A tira de chegada só existe entre a hora marcada e as 6 da manhã seguinte',
         'index.php', 'function horaDaFesta(){'],
        ['E o dia sai do cabeçalho, que é quem já o tem',
         'index.php', "const dia  = el.getAttribute('data-dia')  || '';"],
        ['Na festa, a tira fala de quem CHEGOU, e não de convites que responderam',
         'index.php', "texto = '<b>A festa começou.</b> Já chegaram <b>' + chegaram + '</b>'"],
        ['A hora da festa chega sozinha a quem deixou o painel aberto',
         'index.php', 'setInterval(() => { if (ULTIMAS_STATS) momentoDeChegada(ULTIMAS_STATS); }, 60000);'],
        ['Marcar um convite à mão arrasta as pessoas que ele traz',
         'api.php', "if (\$estado === 'confirmado' || \$estado === 'recusado' || \$estado === 'pendente') {"],

        // ---- O nome de quem pediu é um alvo, e a pastilha não dança ----
        // A prova do desenho do bar só via estes dois defeitos por acidente:
        // o nome e a pastilha do estado só existem com um pedido no ecrã, e a
        // copa abria vazia. Passou a pôr um pedido na fila, e viu-os.
        ['Na copa, o nome de quem pediu tem 48px de alvo e não 20',
         'assets/bar.css', '          cursor:pointer; text-align:left; min-height:48px;'],
        ['E a pastilha do estado conta em números tabulares, como as outras',
         'assets/bar.css', '        text-transform:uppercase; letter-spacing:.04em; font-variant-numeric:tabular-nums; }'],
        ['A prova do desenho põe um pedido na fila, para ter um cartão que medir',
         'tests/chk_bar_desenho.js', "posto: 'entregas',"],

        // ---- Ao copo ou à garrafa ----
        // Não havia unidade nenhuma: um pedido era um pedido, e a única defesa
        // do whisky bom era o «máximo por pedido» — que limita a quantidade
        // mas não impede que a quantidade seja a garrafa.
        ['Cada bebida diz como se serve: só ao copo, só à garrafa, ou as duas',
         'db.php', "servir ENUM('copo','garrafa','ambos') NOT NULL DEFAULT 'copo'"],
        ['A carta que já existia fica ao copo, que é o que a casa fazia',
         'db.php', '    if ($versaoAtual < 46) {'],
        ['A trava é do servidor, e não da carta que o telemóvel tem aberta',
         'api.php', "erro((\$item['nome'] ?: 'Esta bebida') . ' serve-se só ao copo.');"],
        ['Uma garrafa gasta as doses que leva dentro: o stock conta-se em copos',
         'api.php', "\$doses = \$un === 'garrafa' ? max(1, (int)(\$item['doses_garrafa'] ?? 6)) : 1;"],
        ['O pedido guarda a unidade, que é o que a copa tem de servir',
         'db.php', "unidade ENUM('copo','garrafa') NOT NULL DEFAULT 'copo'"],
        ['E o cesto do convidado separa copos de garrafas da mesma bebida',
         'assets/bar-convidado.js', "function chaveCesto(id, un) { return id + ':' + (un === 'garrafa' ? 'garrafa' : 'copo'); }"],

        // ---- As pessoas confirmadas contam-se mesmo (os números certos) ----
        ['Marcar a presença à mão escreve a conta dos lugares, e não só o estado',
         'api.php', "\$conta = \$estado === 'confirmado' ? 'lugares'"],
        ['Um convite confirmado vale os lugares que tem, mesmo sem nomes escritos',
         'db.php', "WHEN rsvp_estado='confirmado' THEN COALESCE(NULLIF(rsvp_confirmados,0), lugares)"],
        ['Quem já estava marcado à mão foi acertado',
         'db.php', '    if ($versaoAtual < 45) {'],
        ['Sentar o convite inteiro conta como sentar — é o caminho normal da planta',
         'api.php', "WHEN c.mesa_id IS NOT NULL THEN \$lugConf"],

        // ---- Cada separador com endereço próprio (DENS-001) ----
        ['Os separadores do bar têm endereço próprio, e o «voltar» recua um',
         'assets/bar-montagem.js', 'function abaDoEndereco() {'],
        ['E abre-se o que o endereço pedir — no fim, quando tudo já existe',
         'assets/bar-montagem.js', 'window.barAba(abaDoEndereco(), true);'],

        // ---- Os separadores cumprem o que o papel promete (A11Y-003) ----
        ['Cada separador diz que painel comanda',
         'bar.php', 'role="tab" id="ab-menu"   aria-controls="pn-menu"'],
        ['E cada painel diz de quem é, e recebe o foco',
         'bar.php', '<section id="pn-menu" role="tabpanel" aria-labelledby="ab-menu" tabindex="0">'],
        ['As setas andam entre separadores, e o foco vai com elas',
         'assets/bar-montagem.js', "if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown')"],
        ['No convite digital, o mesmo — meio padrão é pior do que nenhum',
         'digital.php', "const tira = document.querySelector('.peca-abas'); if (!tira) return;"],

        // ---- O tom (EMO-001 e EMO-002) ----
        ['O cronómetro ao segundo só na semana da festa',
         'parcial-cabecalho.php', "t.textContent = dias < 7 ? relogio(ms) : '';"],
        ['E no dia, a tira diz quem já chegou',
         'index.php', 'function momentoDeChegada(s){'],
        ['A festa é uma vez por pessoa: repetida a cada visita era um enfeite',
         'index.php', "const chave = 'chegada.' + (window.CASAMENTO_ID || 0) + '.' + (total || 0);"],
        ['Quem desligou as animações não leva festa nenhuma',
         'index.php', '.chegada.festa, .chegada.festa .ch-ico{ animation:none; }'],
        ['E fora da hora da festa a tira não deixa sequer a caixa vazia',
         'index.php', '.chegada[hidden]{ display:none; }'],
        ['O mesmo nos tempos das entregas, nas pastilhas da mesa e no aviso dos modelos',
         'assets/bar.css', '.b-tempos[hidden]{ display:none; }'],

        // ---- O registo de ações diz QUEM ao certo ----
        ['Cada ação registada guarda o email de quem a fez, e não só o nome',
         'db.php', "migColuna(\$conn, \"{\$P}registo\", 'email', \"VARCHAR(190) DEFAULT NULL\");"],
        ['O email da conta com sessão aberta está à mão de quem regista',
         'auth.php', "function emailAtual(): ?string       { return \$_SESSION['email'] ?? null; }"],
        ['No painel dos noivos, o email vem dentro do campo «Quem»',
         'index.php', 'function quemAoCerto(r){'],
        ['Na auditoria da casa, vem na própria tabela e dá para procurar por ele',
         'api.php', "\$cond[] = '(r.utilizador LIKE ? OR r.email LIKE ? OR r.alvo LIKE ? OR r.detalhe LIKE ? OR r.accao LIKE ?)';"],

        // ---- O tema volta às páginas do serviço ----
        ['A pastilha do tema só se recolhe onde há gaveta que a receba',
         'assets/estilo.css', 'body.tem-gaveta .tema-fab{ display:none; }'],
        ['Copa, entregas e carta do convidado voltam a poder mudar de tema no telemóvel',
         'parcial-cabecalho.php', "document.body.classList.add('tem-gaveta');"],

        // ---- A planta, a partir da lista (UX) ----
        ['Carregar numa mesa da lista abre-a e centra-a, como se lhe tocassem na planta',
         'assets/mesas.js', 'function irAMesa(id){'],
        ['E o «pôr aqui» já não confunde o dedo que rola com o dedo que escolhe',
         'assets/mesas.js', 'const LIMIAR_ARRASTO = 12;'],
    ];
}

$resultados = [];
foreach (correcoesEsperadas() as [$nome, $ficheiro, $marca]) {
    $abs = __DIR__ . '/' . $ficheiro;
    $conteudo = is_readable($abs) ? file_get_contents($abs) : false;
    $resultados[] = [
        'nome'     => $nome,
        'ficheiro' => $ficheiro,
        'existe'   => $conteudo !== false,
        'ok'       => $conteudo !== false && strpos($conteudo, $marca) !== false,
    ];
}
$faltam = count(array_filter($resultados, fn($r) => !$r['ok']));

$emFalta = [];
foreach (ficheirosApp() as $f) if (!is_readable(__DIR__ . '/' . $f)) $emFalta[] = $f;

// O esquema da base é a outra metade da pergunta: os ficheiros podem estar
// todos cá e a migração não ter corrido — e então falta metade da correção,
// da pior maneira, porque à vista está tudo bem.
$esqR = @$conn->query("SELECT valor FROM " . PREFIXO . "definicoes
                       WHERE casamento_id=0 AND chave='schema.versao' LIMIT 1");
$esqInstalado = ($esqR && ($x = $esqR->fetch_assoc())) ? (int)$x['valor'] : 0;
$esqOk = ($esqInstalado === ESQUEMA_VERSAO);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Versão instalada</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<?php include __DIR__ . '/parcial-tema.php'; ?>
<style>
  body{ padding:1.5rem; max-width:820px; margin:0 auto; }
  h1{ margin-bottom:.2rem; }
  /* --gold é enchimento; a tinta é --gold-texto (2,82:1 em classico). */
  .assin{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:var(--t-seccao); color:var(--gold-texto);
          background:var(--cream); border:1px solid var(--line); border-radius:10px;
          padding:.5rem .9rem; display:inline-block; margin:.4rem 0 1rem; }
  table{ width:100%; border-collapse:collapse; font-size:var(--t-denso); }
  th,td{ text-align:left; padding:.45rem .5rem; border-bottom:1px solid var(--line); vertical-align:top; }
  th{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-fraco); }
  /* Verde cravado à mão: 3,29:1 no escuro. --ok é o mesmo verde a virar. */
  .sim{ color:var(--ok); font-weight:600; }
  .nao{ color:var(--danger); font-weight:600; }
  td.f{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:var(--t-apoio); color:var(--ink-fraco); }
  .aviso{ border-radius:10px; padding:.8rem 1rem; margin:1rem 0; line-height:1.55; }
  /* Fundos cravados à mão. No tema escuro ficavam duas ilhas CLARAS com o
     texto claro da página por cima: «Está tudo cá.» dava 1,37:1 — a frase
     que esta página existe para dizer era a que menos se via. */
  .aviso.mau{ background:var(--danger-bg); border:1px solid var(--danger); color:var(--text); }
  .aviso.bom{ background:var(--ok-bg); border:1px solid var(--ok); color:var(--text); }
  .copiar{ margin-top:1rem; }
  pre{ background:var(--cream); border:1px solid var(--line); border-radius:8px; padding:.7rem;
       font-size:var(--t-apoio); white-space:pre-wrap; }
</style>
</head>
<body>
<h1>Versão instalada</h1>
<p style="color:var(--ink-fraco);margin:.2rem 0">Assinatura do que está neste servidor. Duas instalações
iguais dão a mesma assinatura.</p>
<div class="assin"><?= versaoApp() ?></div>

<p style="margin:0 0 1rem">Esquema da base de dados:
  <b class="<?= $esqOk ? 'sim' : 'nao' ?>">v<?= $esqInstalado ?></b>
  <?php if (!$esqOk): ?>
    — esperava-se <b>v<?= ESQUEMA_VERSAO ?></b>. A migração não correu, ou correu a meio:
    os ficheiros podem estar todos cá e faltar na mesma metade da correção.
  <?php else: ?>
    <span style="color:var(--ink-fraco)">(em dia)</span>
  <?php endif; ?>
</p>

<?php if ($faltam): ?>
  <div class="aviso mau"><b><?= $faltam ?> correção(ões) recente(s) não estão neste servidor.</b><br>
  O código que está a correr é mais antigo do que o que foi entregue. Enquanto assim for,
  qualquer correção nova também não chega.</div>
<?php else: ?>
  <div class="aviso bom"><b>Está tudo cá.</b> Este servidor tem todas as correções recentes.</div>
<?php endif; ?>

<table>
  <tr><th>Correção</th><th>Onde</th><th>Está?</th></tr>
  <?php foreach ($resultados as $r): ?>
    <tr>
      <td><?= escP($r['nome']) ?></td>
      <td class="f"><?= escP($r['ficheiro']) ?><?= $r['existe'] ? '' : ' <span class="nao">(ficheiro em falta)</span>' ?></td>
      <td class="<?= $r['ok'] ? 'sim' : 'nao' ?>"><?= $r['ok'] ? 'sim' : 'NÃO' ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<?php if ($emFalta): ?>
  <h3 style="margin-top:1.4rem">Ficheiros que faltam</h3>
  <pre><?= escP(implode("\n", $emFalta)) ?></pre>
<?php endif; ?>

<div class="copiar">
  <button class="btn" onclick="copiar()">Copiar este resumo</button>
</div>
<pre id="resumo" style="display:none"><?= escP(versaoApp()) ?> · esquema v<?= $esqInstalado ?>/<?= ESQUEMA_VERSAO ?> · <?= $faltam ?> em falta
<?php foreach ($resultados as $r) echo ($r['ok'] ? '[ok] ' : '[--] ') . $r['nome'] . "\n"; ?>
PHP <?= PHP_VERSION ?> · <?= escP($_SERVER['SERVER_SOFTWARE'] ?? '?') ?></pre>
<script>
function copiar(){
  const t = document.getElementById('resumo').textContent;
  navigator.clipboard && navigator.clipboard.writeText(t);
  document.querySelector('.copiar .btn').textContent = 'Copiado';
}
</script>
<?php include __DIR__ . "/parcial-seletor-tema.php"; ?>
</body>
</html>
