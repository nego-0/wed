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
        ['Símbolos ♂/♀ legíveis',
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
        ['Versões dos dois convites, num seletor na barra superior',
         'assets/versoes.js', 'Pôr em vigor'],
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
         'api.php', "SET u.estado='inativo'"],
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
        ['Os modelos aparecem no seletor de versões dos editores',
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
         'editor-cartao.php', 'if (!MODELO) Versoes.montar('],
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
<style>
  body{ padding:1.5rem; max-width:820px; margin:0 auto; }
  h1{ margin-bottom:.2rem; }
  .assin{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:1.5rem; color:var(--gold);
          background:var(--cream); border:1px solid var(--line); border-radius:10px;
          padding:.5rem .9rem; display:inline-block; margin:.4rem 0 1rem; }
  table{ width:100%; border-collapse:collapse; font-size:.88rem; }
  th,td{ text-align:left; padding:.45rem .5rem; border-bottom:1px solid var(--line); vertical-align:top; }
  th{ font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:#8a8f88; }
  .sim{ color:#1f7a3d; font-weight:600; }
  .nao{ color:var(--danger); font-weight:600; }
  td.f{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.78rem; color:#8a8f88; }
  .aviso{ border-radius:10px; padding:.8rem 1rem; margin:1rem 0; line-height:1.55; }
  .aviso.mau{ background:#fbeceb; border:1px solid #e6c3bf; }
  .aviso.bom{ background:#eaf4ee; border:1px solid #bcdcc8; }
  .copiar{ margin-top:1rem; }
  pre{ background:var(--cream); border:1px solid var(--line); border-radius:8px; padding:.7rem;
       font-size:.78rem; white-space:pre-wrap; }
</style>
</head>
<body>
<h1>Versão instalada</h1>
<p style="color:#8a8f88;margin:.2rem 0">Assinatura do que está neste servidor. Duas instalações
iguais dão a mesma assinatura.</p>
<div class="assin"><?= versaoApp() ?></div>

<p style="margin:0 0 1rem">Esquema da base de dados:
  <b class="<?= $esqOk ? 'sim' : 'nao' ?>">v<?= $esqInstalado ?></b>
  <?php if (!$esqOk): ?>
    — esperava-se <b>v<?= ESQUEMA_VERSAO ?></b>. A migração não correu, ou correu a meio:
    os ficheiros podem estar todos cá e faltar na mesma metade da correção.
  <?php else: ?>
    <span style="color:#8a8f88">(em dia)</span>
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
</body>
</html>
