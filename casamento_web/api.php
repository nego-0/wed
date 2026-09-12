<?php
// ============================================================
// api.php — Endpoints JSON (admin, RSVP público, porteiro)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

$acao = $_GET['action'] ?? '';

// ---- Utilitários -------------------------------------------
function corpo(): array {
    static $c = null;
    if ($c === null) $c = json_decode(file_get_contents('php://input'), true) ?: [];
    return $c;
}
function ok(array $extra = []): void  { echo json_encode(['success' => true]  + $extra); exit; }
function erro(string $m): void        { echo json_encode(['success' => false, 'message' => $m]); exit; }

/**
 * Uma lista vinda do pedido, quer seja um array JSON (["a","b"]) quer uma
 * string com vírgulas ("a,b"). As checkboxes do ecrã mandam arrays; um link de
 * descarga manda a string na query — os dois têm de servir.
 */
function listaCorpo($v): array {
    $itens = is_array($v) ? $v : explode(',', (string)$v);
    return array_values(array_filter(array_map(fn($x) => trim((string)$x), $itens), fn($x) => $x !== ''));
}

/**
 * Diagnostica um upload: devolve '' se está bom, ou uma mensagem clara do que
 * correu mal.
 *
 * "Falha no envio do ficheiro." não dizia nada a ninguém — e a causa mais comum
 * (a música do convite, alguns MB, contra um upload_max_filesize baixo no
 * alojamento) ficava invisível. Aqui separa-se o limite do PHP
 * (upload_max_filesize / post_max_size) do limite da própria aplicação, e diz-se
 * qual foi atingido e qual é.
 *
 * $campo  = a chave em $_FILES (normalmente 'ficheiro').
 * $maxApp = o tamanho máximo que a aplicação aceita, em bytes.
 */
function problemaUpload(string $campo, int $maxApp): string {
    // Passar o post_max_size faz o PHP deitar fora $_POST e $_FILES inteiros
    // ANTES de aqui chegarmos: fica um POST com corpo mas sem ficheiro nenhum.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return 'O envio é maior do que o servidor aceita de uma vez (limite: '
             . ini_get('post_max_size') . '). Escolha um ficheiro mais pequeno.';
    }
    if (empty($_FILES[$campo])) return 'Nenhum ficheiro foi recebido.';
    switch ((int)($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE)) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
            return 'O ficheiro é maior do que o servidor permite (limite: '
                 . ini_get('upload_max_filesize') . '). Escolha um ficheiro mais pequeno.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'O ficheiro é demasiado grande.';
        case UPLOAD_ERR_PARTIAL:
            return 'O envio foi interrompido a meio. Tente outra vez.';
        case UPLOAD_ERR_NO_FILE:
            return 'Nenhum ficheiro foi escolhido.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'O servidor não conseguiu guardar o ficheiro. Tente outra vez.';
        default:
            return 'Falha no envio do ficheiro.';
    }
    if ((int)($_FILES[$campo]['size'] ?? 0) > $maxApp) {
        return 'Ficheiro demasiado grande (máx. ' . (int)round($maxApp / 1048576) . ' MB).';
    }
    return '';
}

/**
 * Pasta temporária dos envios por pedaços. Fica FORA da raiz do site (não é
 * servível), e é limpa dos restos velhos a cada envio novo.
 */
function chunkDir(): string {
    $d = sys_get_temp_dir() . '/cw_upload';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/**
 * A origem de um ficheiro que chega à API: um envio normal ($_FILES) OU um
 * ficheiro montado por pedaços (campo 'chunk_token').
 *
 * O envio por pedaços existe porque há alojamentos que limitam CADA envio
 * (upload_max_filesize) a poucos MB e não deixam mudar esse limite — a música do
 * convite, maior do que isso, era recusada antes de chegar aqui. Parte-se em
 * pedaços pequenos (ver a ação 'upload_chunk'), que passam, e junta-se num
 * ficheiro temporário; aqui trata-se dos dois casos por igual.
 *
 * Devolve ['tmp'=>caminho, 'nome'=>nome do ficheiro, 'size'=>bytes,
 * 'uploaded'=>bool]. Em erro, chama erro() e não regressa.
 */
function origemUpload(string $campo, int $maxApp): array {
    $token = (string)($_POST['chunk_token'] ?? '');
    if ($token !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) erro('Sessão de envio inválida.');
        $tmp  = chunkDir() . '/' . $token . '.part';
        $size = is_file($tmp) ? (int)filesize($tmp) : 0;
        if ($size <= 0) erro('O envio por partes não chegou completo. Tente outra vez.');
        if ($size > $maxApp) {
            @unlink($tmp);
            erro('Ficheiro demasiado grande (máx. ' . (int)round($maxApp / 1048576) . ' MB).');
        }
        return ['tmp' => $tmp, 'nome' => basename((string)($_POST['nome'] ?? 'ficheiro')),
                'size' => $size, 'uploaded' => false];
    }
    if ($p = problemaUpload($campo, $maxApp)) erro($p);
    return ['tmp' => $_FILES[$campo]['tmp_name'], 'nome' => (string)$_FILES[$campo]['name'],
            'size' => (int)$_FILES[$campo]['size'], 'uploaded' => true];
}

/** Move para o destino o ficheiro de origem (enviado ou montado por pedaços). */
function moverUpload(array $src, string $dest): bool {
    if (!empty($src['uploaded'])) return @move_uploaded_file($src['tmp'], $dest);
    if (@rename($src['tmp'], $dest)) return true;
    // rename falha entre sistemas de ficheiros diferentes (o tmp e o site podem
    // estar em partições distintas): copia-se e apaga-se a origem.
    if (@copy($src['tmp'], $dest)) { @unlink($src['tmp']); return true; }
    return false;
}

// ============================================================
// OS FICHEIROS QUE OS CASAIS ENVIAM
// ------------------------------------------------------------
// As fotografias do convite entram pela página do convite digital, já dentro
// da casa e com o casal identificado. Houve um tempo em que entravam antes
// disso, na própria inscrição, para uma área de espera presa à sessão: era
// preciso porque o escalão sem edição fixava as fotografias no acto da compra
// e nunca mais as deixava trocar. Deixou de ser assim — as fotografias são do
// casal, não da licença —, e a área de espera foi-se com essa regra.
// ============================================================
const FOTO_CONVITE_MAX = 5 * 1024 * 1024;   // o que se aceita por fotografia

/**
 * Uma pasta de ficheiros enviados, criada já trancada.
 *
 * O .htaccess que a acompanha recusa a entrega de scripts: uma pasta onde
 * qualquer visitante pode pôr um ficheiro não pode ser uma pasta de onde o
 * servidor executa o que lá está. Escreve-se na criação, e não à mão, para uma
 * instalação nova não nascer com a porta aberta.
 */
function pastaDeEnvios(string $rel): string {
    $d = __DIR__ . '/' . $rel;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $ht = $d . '/.htaccess';
    if (is_dir($d) && !is_file($ht)) {
        @file_put_contents($ht,
            "# Pasta de uploads do convite: apenas ficheiros estáticos.\n"
          . "# Bloqueia a execução/entrega de scripts.\n"
          . "<FilesMatch \"\\.(?i:php|phtml|phar|cgi|pl|py|sh)$\">\n"
          . "Require all denied\n"
          . "</FilesMatch>\n");
    }
    return $d;
}

// Onde ficam as fotografias que o casal envia — do editor, ou da área de
// fotografias da página do convite digital.
const CUSTOM_FOTO_DIR = 'assets/convite/custom';
const BAR_FOTO_DIR = 'assets/bar';         // as fotografias das bebidas

// A pasta das que, noutro tempo, entravam com a licença. Já não entra lá nada,
// mas as que lá estão continuam a ser de alguém: o nome fica para elas se
// reconhecerem e se poderem largar quando a peça deixar de as mostrar.
const LIC_FOTO_DIR = 'assets/convite/licenca';

/**
 * Exige poder escrever no casamento aberto.
 *
 * A única situação em que não se pode é a visita de suporte com um código de
 * "ver". Fica aqui, num sítio só, à frente de tudo o que altera dados: um
 * código de ver que deixasse mexer não era um código de ver.
 */
function exigirCorrecao(): void {
    if (!podeCorrigir()) {
        http_response_code(403);
        erro('Está a acompanhar este casamento com um código de leitura. '
           . 'Para corrigir, peça ao casal um código com permissão de correção.');
    }
}

/**
 * Quantos gestores ficariam neste casamento se tirássemos esta conta.
 * Serve para não deixar um casamento sem ninguém que lhe mexa — um erro
 * de um clique que só se desfaz por fora, na base de dados.
 */
function contaNoivos(mysqli $conn, int $cid, int $exceto): int {
    global $P;
    $st = $conn->prepare("SELECT COUNT(*) n FROM {$P}acessos
                          WHERE casamento_id=? AND papel='noivos' AND utilizador_id <> ?");
    if (!$st) return 0;
    $st->bind_param('ii', $cid, $exceto); $st->execute();
    return (int)$st->get_result()->fetch_assoc()['n'];
}

/**
 * Os dados do evento, escritos à nascença do casamento.
 *
 * Perguntar tudo no primeiro registo evita o casamento que fica meses com o
 * local de outra pessoa — os valores de origem vêm do config.php, e um casal
 * que nunca abra o editor manda convites com a morada errada. Aqui grava-se o
 * que ele escreveu, e o resto fica no original até alguém lá ir.
 */
function guardarEventoDoRegisto(mysqli $conn, int $cid, array $d): int {
    $mapa = [
        'hora'            => 'evento.hora',
        'venue_titulo'    => 'evento.venue_titulo',
        'local'           => 'evento.local',
        'cidade'          => 'evento.cidade',
        'convidados'      => 'evento.convidados',
        'whatsapp'        => 'evento.whatsapp',
        'maps'            => 'evento.maps',
        'civil_hora'      => 'evento.civil_hora',
        'civil_local'     => 'evento.civil_local',
        'civil_maps'      => 'evento.civil_maps',
        'religiosa_hora'  => 'evento.religiosa_hora',
        'religiosa_local' => 'evento.religiosa_local',
        'religiosa_maps'  => 'evento.religiosa_maps',
    ];
    $defs = [];
    foreach ($mapa as $campo => $chave) {
        if (!array_key_exists($campo, $d)) continue;      // não veio: fica o original
        $defs[$chave] = (string)$d[$campo];
    }
    // O orçamento total é do casamento (como a ficha), e não do desenho do
    // convite: grava-se à parte, pelo mesmo ajudante que a Gestão usa.
    $temTeto = array_key_exists('orcamento_total', $d);
    if (!$defs && !$temTeto) return 0;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $r = $defs ? guardarDefinicoes($conn, $defs) : ['gravadas' => 0];
    if ($temTeto) orcamentoDefinirTeto($conn, $cid, $d['orcamento_total']);
    usarCasamento($anterior > 0 ? $anterior : $cid);
    return (int)($r['gravadas'] ?? 0);
}

/** Senha temporária legível, para se entregar a quem se convida. */
/** Apaga do disco um ficheiro de fatura, com cuidado: só dentro de assets/faturas/. */
function apagarFaturaFich(string $caminho): void {
    if ($caminho === '' || str_contains($caminho, '..')) return;
    if (!str_starts_with($caminho, 'assets/faturas/')) return;
    @unlink(__DIR__ . '/' . $caminho);
}

/**
 * Um caminho é uma fotografia ENVIADA por alguém, e não um asset de origem?
 *
 * As que os casais enviam vivem em custom/ — venham do editor ou da área de
 * fotografias da página do convite digital. A pasta licenca/ é do tempo em que
 * elas entravam pela inscrição: já não entra lá nada, mas as que lá estão
 * continuam a ser de alguém, e o que se segue tem de valer para elas também —
 * repor uma secção, apagar uma versão, voltar à origem: tudo isso pode largar
 * o ficheiro, desde que mais ninguém o use.
 */
function ehFotoCustom(string $caminho): bool {
    return $caminho !== '' && !str_contains($caminho, '..')
        && (str_starts_with($caminho, CUSTOM_FOTO_DIR . '/')
         || str_starts_with($caminho, LIC_FOTO_DIR . '/'));
}

/**
 * Fotografias trocadas no editor mas ainda POR GUARDAR numa versão.
 *
 * Trocar uma foto grava-a logo na peça (para se ver na tela), mas ela só FICA se
 * o casal actualizar/guardar uma versão. Enquanto isso não acontece, a troca é
 * provisória: guarda-se aqui o ficheiro novo e o valor anterior, para se poder
 * repor a foto antiga e apagar a nova se o casal sair sem guardar. É por
 * casamento e por sessão — cada um trata das suas.
 */
function &pendenteMedia(): array {
    $cid = casamentoAtual();
    if (!isset($_SESSION['media_pendente']) || !is_array($_SESSION['media_pendente'])) {
        $_SESSION['media_pendente'] = [];
    }
    if (!isset($_SESSION['media_pendente'][$cid]) || !is_array($_SESSION['media_pendente'][$cid])) {
        $_SESSION['media_pendente'][$cid] = [];
    }
    return $_SESSION['media_pendente'][$cid];
}

/** Regista uma troca de foto por confirmar: o ficheiro novo e o valor anterior. */
function marcarMediaPendente(mysqli $conn, string $chave, string $novo, string $anterior): void {
    $pend = &pendenteMedia();
    // Se já havia uma troca por guardar nesta secção, o «anterior» a preservar é
    // o da primeira (o último valor MESMO guardado); e o ficheiro intermédio,
    // que nunca chegou a ficar, pode ir já.
    if (isset($pend[$chave])) {
        $intermedio = (string)($pend[$chave]['novo'] ?? '');
        $anteriorReal = (string)($pend[$chave]['anterior'] ?? $anterior);
        if ($intermedio !== '' && $intermedio !== $novo && $intermedio !== $anteriorReal
            && ehFotoCustom($intermedio) && !ficheiroEmVersao($conn, $intermedio)) {
            @unlink(__DIR__ . '/' . $intermedio);
        }
        $anterior = $anteriorReal;
    }
    $pend[$chave] = ['novo' => $novo, 'anterior' => $anterior];
}

/**
 * O casal saiu sem guardar: repõe cada foto pendente no valor anterior e apaga
 * o ficheiro novo (se ninguém mais o usa). Devolve quantos ficheiros se apagaram.
 */
function descartarMediaPendente(mysqli $conn): int {
    $pend = &pendenteMedia();
    $reverter = []; $apagados = 0;
    foreach ($pend as $chave => $par) {
        $novo = (string)($par['novo'] ?? '');
        $anterior = (string)($par['anterior'] ?? '');
        $reverter[$chave] = $anterior;
        if ($novo !== '' && $novo !== $anterior && ehFotoCustom($novo) && !ficheiroEmVersao($conn, $novo)) {
            @unlink(__DIR__ . '/' . $novo); $apagados++;
        }
    }
    if ($reverter) guardarDefinicoes($conn, $reverter);
    $cid = casamentoAtual();
    unset($_SESSION['media_pendente'][$cid]);
    return $apagados;
}

/**
 * As fotos pendentes ficaram (o casal guardou uma versão, ou aplicou outra):
 * larga-se o registo e apagam-se os ficheiros que já ninguém usa — nem a peça
 * em vigor, nem versão guardada nenhuma. Não repõe nada: a peça já é o que é.
 */
function assentarMediaPendente(mysqli $conn): int {
    $pend = &pendenteMedia();
    if (!$pend) { return 0; }
    $emUso = [];
    foreach (defsAtuais($conn) as $v) if (is_string($v)) $emUso[$v] = true;
    $apagados = 0;
    foreach ($pend as $par) {
        foreach (['novo', 'anterior'] as $q) {
            $f = (string)($par[$q] ?? '');
            if (ehFotoCustom($f) && !isset($emUso[$f]) && !ficheiroEmVersao($conn, $f)) {
                @unlink(__DIR__ . '/' . $f); $apagados++;
            }
        }
    }
    $cid = casamentoAtual();
    unset($_SESSION['media_pendente'][$cid]);
    return $apagados;
}

function senhaTemporaria(): string {
    $a = 'abcdefghijkmnpqrstuvwxyz23456789';   // sem l, o, 0, 1
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

/**
 * Cria uma conta NOVA e liga-a a um casamento, com um papel. Devolve
 * ['id','email','senha','novo'] — a senha vem preenchida, para se entregar uma
 * vez. Usada ao criar/editar um casamento com as contas dos noivos e do porteiro.
 *
 * Um email é de UMA conta e de UMA função: se já existir, recusa-se, em vez de
 * o religar. Reatribuir um email que já é de alguém a outro papel seria abrir
 * uma porta com uma chave que já é de outra pessoa. Chama erro() (que termina)
 * se o email for inválido ou já estiver em uso — por isso valide ANTES de criar
 * o que quer que seja à volta.
 */
function contaParaCasamento(mysqli $conn, string $email, string $nome, string $senha,
                            int $cid, string $papel): array {
    global $P;
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro("Email inválido para a conta de $papel.");
    $st = $conn->prepare("SELECT id FROM {$P}utilizadores WHERE email=? LIMIT 1");
    $st->bind_param('s', $email); $st->execute();
    if ($st->get_result()->fetch_row()) {
        erro("Já existe uma conta com o email $email. Cada email serve uma só conta — use outro.");
    }
    if (mb_strlen($senha) < 8) $senha = senhaTemporaria();
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                          VALUES (?,?,?, 'ativo')");
    $st->bind_param('sss', $email, $nome, $hash);
    if (!$st->execute()) erro("Já existe uma conta com o email $email.");
    $uid = $conn->insert_id;
    $st = $conn->prepare("INSERT IGNORE INTO {$P}acessos (utilizador_id, casamento_id, papel)
                          VALUES (?,?,?)");
    $st->bind_param('iis', $uid, $cid, $papel); @$st->execute();
    return ['id' => $uid, 'email' => $email, 'senha' => $senha, 'novo' => true];
}

/**
 * As mesmas portas de exigirAdmin()/exigirPorta(), mas a responder como API.
 *
 * As originais reencaminham para o login, o que numa página é o certo e numa
 * chamada de API é uma armadilha: o pedido recebe a página de entrada em HTML
 * onde esperava JSON, e quem chamou fica sem perceber que lhe faltava o
 * acesso. Aqui responde-se 403 e diz-se porquê.
 */
function exigirAdminApi(): void {
    global $acao;
    if (ehAdmin()) return;
    // As ações da própria plataforma não são de casamento nenhum: quem responde
    // pela casa tem de as poder fazer sem ter uma festa aberta — é assim que
    // entra. Cada uma delas confere depois, por si, se é mesmo admin da casa.
    if (ehAdminPlataforma() && in_array($acao, acoesSemCasamento(), true)) return;
    http_response_code(403);
    erro(utilizadorId()
        ? 'Não tem um casamento aberto com poderes de gestão.'
        : 'Sessão terminada. Entre de novo.');
}
function exigirPortaApi(): void {
    if (podeEntrar()) return;
    http_response_code(403);
    erro(utilizadorId()
        ? 'Não tem um casamento aberto.'
        : 'Sessão terminada. Entre de novo.');
}

/**
 * A porta de licença do lado da API.
 *
 * As páginas mandam quem não tem o módulo para a montra; aqui devolve-se um
 * erro que diz o mesmo por outras palavras. É a segunda fechadura: esconder a
 * entrada do menu não impede ninguém de chamar a ação à mão.
 */
function exigirModuloApi(string $chave): void {
    if (podeModulo($chave)) return;
    http_response_code(403);
    erro('A licença deste casamento não inclui este módulo. '
       . 'Veja os planos na página da Licença.');
}

/**
 * Cabem mais tantas pessoas na lista deste casamento?
 *
 * O limite é do escalão de convidados. 0 = sem limite. Conta-se o que já lá
 * está mais o que se quer acrescentar — recusar só quando já se passou era
 * deixar passar sempre a última.
 */
function exigirCabidaConvidados(mysqli $conn, int $aAcrescentar): void {
    if ($aAcrescentar <= 0) return;
    $lim = limiteConvidados();
    if ($lim === 0) return;                       // sem limite
    if ($lim < 0) {
        http_response_code(403);
        erro('A licença deste casamento não inclui a lista de convidados.');
    }
    $tem = convidadosContados($conn, casamentoAtual());
    if ($tem + $aAcrescentar <= $lim) return;
    $livres = max(0, $lim - $tem);
    erro("A sua licença chega a $lim convidados e já tem $tem. "
       . ($livres > 0
            ? "Ainda cabem $livres. Para mais, reforce a licença na página da Licença."
            : 'Reforce a licença na página da Licença para convidar mais gente.'));
}

/** Exige um token CSRF válido nos pedidos autenticados que alteram dados. */
function exigirCsrf(): void {
    if (!csrfValido()) {
        http_response_code(419);
        erro('Sessão expirada ou pedido inválido. Recarregue a página e tente de novo.');
    }
}

/**
 * Momento a gravar nas operações CRUD: usa a hora local do cliente
 * (enviada em "ts") quando válida; caso contrário, recorre ao NOW() do servidor.
 * Devolve um fragmento SQL seguro ('AAAA-MM-DD HH:MM:SS' ou NOW()).
 */
function tsSql(): string {
    global $conn;
    $b  = corpo();
    $ts = $b['ts'] ?? ($_GET['ts'] ?? ($_POST['ts'] ?? ''));
    if (is_string($ts) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts)) {
        return "'" . $conn->real_escape_string($ts) . "'";
    }
    return 'NOW()';
}
$TS = tsSql(); // hora local do cliente para esta requisição

// ============================================================
// EXPORTAÇÃO CSV (admin)
// ============================================================
if ($acao === 'export') {
    exigirAdmin();
    // O nome do ficheiro é o do casal aberto: com vários casamentos na mesma
    // casa, três exportações com o mesmo nome acabam por se sobrepor na pasta
    // das transferências de quem as fez.
    $alcunha = strtolower(casalInfo(defsAtuais($conn))['casal']);
    $alcunha = preg_replace('/[^a-z0-9]+/', '_',
                 iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $alcunha) ?: 'convidados');
    $alcunha = trim((string)$alcunha, '_') ?: 'convidados';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=convidados_' . $alcunha . '.csv');
    $out = fopen('php://output', 'w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Convite','Tipo','Lado','Lugares','Mesa','Estado RSVP','Confirmados','Presentes','Telefone','Membros','Codigo','Link']);
    $res = $conn->query("SELECT c.*, m.nome AS mesa_nome,
                                GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR ', ') AS membros
                         FROM {$P}convites c
                         LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                         LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                         WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
                         GROUP BY c.id ORDER BY c.nome_exibicao");
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            nomeConvite($r), $r['tipo'], $r['lado'], $r['lugares'], $r['mesa_nome'],
            $r['rsvp_estado'], $r['rsvp_confirmados'], $r['checkin_presentes'],
            $r['telefone'], $r['membros'], $r['codigo'],
            enderecoPublico().'/convite.php?c='.$r['codigo']
        ]);
    }
    fclose($out); exit;
}

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// AÇÕES PÚBLICAS (RSVP) — sem login, protegidas por código
// ============================================================
if ($acao === 'rsvp_submit') {
    $d = corpo();
    $codigo = trim($d['codigo'] ?? '');
    $c = carregarConvite($conn, $codigo, 'codigo');
    if (!$c) erro('Convite não encontrado.');

    $decisao   = ($d['decisao'] ?? '') === 'sim' ? 'sim' : 'nao';
    $mensagem  = trim($d['mensagem'] ?? '');
    $confirm   = max(0, min((int)($d['confirmados'] ?? 0), (int)$c['lugares']));
    $membros   = is_array($d['membros'] ?? null) ? $d['membros'] : [];

    if ($decisao === 'nao') {
        $estado = 'recusado'; $confirm = 0;
        // Ao recusar, repõe também a presença: quem não vem não pode ficar "presente".
        $conn->query("UPDATE {$P}convidados SET rsvp='recusado', presente=0, presente_em=NULL WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
        $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE " . doCasamento() . " AND id=".(int)$c['id']);
    } else {
        // Atualiza cada pessoa, se a página enviou a lista nominal
        $tot = 0; $vai = 0;
        foreach ($membros as $mm) {
            $mid = (int)($mm['id'] ?? 0);
            $ok  = !empty($mm['vai']);
            $tot++; if ($ok) $vai++;
            // Quem confirma fica 'confirmado'; quem não confirma fica 'pendente' (aguarda),
            // para aparecer no card/filtro Pendentes. Recusa total trata-se no ramo 'nao'.
            $rs  = $ok ? 'confirmado' : 'pendente';
            if ($mid) {
                $q = $conn->prepare("UPDATE {$P}convidados SET rsvp=? WHERE " . doCasamento() . " AND id=? AND convite_id=?");
                $q->bind_param('sii', $rs, $mid, $c['id']); $q->execute();
            }
        }
        if ($tot > 0) {
            // Estado derivado das escolhas por pessoa
            $confirm = $vai;
            $estado  = $vai <= 0 ? 'recusado' : ($vai >= $tot ? 'confirmado' : 'parcial');
            // Caso terminal: alinha os membros ao estado (os não confirmados ficaram 'pendente' acima).
            if ($estado === 'recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
            elseif ($estado === 'confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
        } else {
            // Sem lista nominal: usa o número indicado
            if ($confirm < 1) $confirm = 1;
            $estado = ($confirm >= (int)$c['lugares']) ? 'confirmado' : 'parcial';
        }
    }

    $st = $conn->prepare("UPDATE {$P}convites
                          SET rsvp_estado=?, rsvp_confirmados=?, rsvp_mensagem=?, rsvp_em=$TS
                          WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('sisi', $estado, $confirm, $mensagem, $c['id']); // string, int, string, int
    $st->execute();

    ok(['estado' => $estado, 'confirmados' => $confirm]);
}

// ============================================================
// REGISTO PÚBLICO — um casal inscreve-se, o admin é que abre a porta
// ============================================================
if ($acao === 'registo_publico') {
    $d = corpo();
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $senha = (string)($d['senha'] ?? '');
    $data  = trim((string)($d['data'] ?? ''));
    if ($noiva === '' || $noivo === '')          erro('Indique os nomes dos noivos.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    if (mb_strlen($senha) < 8)                    erro('A senha precisa de pelo menos 8 caracteres.');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro('Data inválida.');

    // Trava contra enchentes: um registo de cada vez por visitante, e um teto
    // global por hora. Sem isto, um guião automático enchia a fila de
    // aprovação de lixo e o admin deixava de ver os pedidos verdadeiros.
    if (!empty($_SESSION['registo_feito']) && (time() - (int)$_SESSION['registo_feito']) < 900) {
        erro('Já foi enviado um registo há pouco. Aguarde, por favor.');
    }
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}casamentos
                        WHERE estado='pendente' AND criado_em > (NOW() - INTERVAL 1 HOUR)");
    if ($r && (int)$r->fetch_assoc()['n'] >= 20) {
        erro('Há demasiados registos à espera neste momento. Tente mais tarde, por favor.');
    }

    // A conta primeiro: se o email já existir, não se cria casamento nenhum.
    //
    // Nasce ATIVA, ao contrário do casamento. O casal entra desde já — mas o
    // casamento fica 'pendente' e sem módulos concedidos, e é isso que faz com
    // que ele só encontre lá dentro a página da sua licença. Deixá-lo à porta
    // até alguém aprovar era pedir-lhe que escolhesse um plano e depois
    // fechar-lhe a porta na cara.
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $nomeConta = trim("$noiva & $noivo");
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                          VALUES (?,?,?, 'ativo')");
    $st->bind_param('sss', $email, $nomeConta, $hash);
    if (!$st->execute()) erro('Já existe uma conta com esse email. Tente entrar, ou use outro.');
    $uid = $conn->insert_id;

    // O período de licença que o casal deseja (em meses; 0 = não indicado). Fica
    // guardado, mas o relógio só arranca quando o admin aprovar (ver casamento_estado).
    $meses = max(0, min(120, (int)($d['licenca_meses'] ?? 0)));
    $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado, licenca_meses)
                          VALUES (?,?,?,?, 'pendente', ?)");
    $dataOuNulo = $data !== '' ? $data : null;
    $st->bind_param('ssssi', $nomeConta, $noiva, $noivo, $dataOuNulo, $meses);
    if (!$st->execute()) {
        // Sem casamento, a conta ficaria a pairar: desfaz-se.
        $conn->query("DELETE FROM {$P}utilizadores WHERE id=" . (int)$uid);
        erro('Não foi possível registar. Tente de novo, por favor.');
    }
    $cid = $conn->insert_id;

    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?, 'noivos')");
    $st->bind_param('ii', $uid, $cid); @$st->execute();

    // A conta do porteiro, se o casal a quis já indicar. Entra 'pendente' como o
    // casamento: passa a ativa quando o admin aprovar (retomarContasDoCasamento).
    //
    // Mas só se o plano escolhido trouxer o «Controlo à porta». Sem esse
    // módulo, o porteiro entrava e não tinha porta nenhuma para guardar: uma
    // conta a mais na equipa, com uma senha a circular, para não fazer nada. O
    // formulário já esconde estes campos quando o plano não o inclui; isto é a
    // segunda tranca, para quem chame a API à mão.
    $portaNaLicenca = licPlanoTemModulo($conn, (array)($d['licenca'] ?? []), 'porta');
    $portEmail = mb_strtolower(trim((string)($d['porteiro_email'] ?? '')));
    $porteiroIgnorado = ($portEmail !== '' && !$portaNaLicenca);
    if ($portaNaLicenca && $portEmail !== '' && filter_var($portEmail, FILTER_VALIDATE_EMAIL) && $portEmail !== $email) {
        $portSenha = (string)($d['porteiro_senha'] ?? '');
        if (mb_strlen($portSenha) >= 8) {
            $ph = password_hash($portSenha, PASSWORD_DEFAULT);
            $pn = 'Porteiro · ' . $nomeConta;
            $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                                  VALUES (?,?,?, 'pendente')");
            $st->bind_param('sss', $portEmail, $pn, $ph);
            if (@$st->execute()) {
                $pid = $conn->insert_id;
                $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel)
                                      VALUES (?,?, 'porteiro')");
                $st->bind_param('ii', $pid, $cid); @$st->execute();
            }
        }
    }

    $gravadas = guardarEventoDoRegisto($conn, $cid, $d);
    semearOrcamento($conn, $cid);   // começa com as gavetas de origem, como os do admin
    semearBar($conn, $cid);

    $_SESSION['registo_feito'] = time();
    usarCasamento($cid);

    // A peça de origem que a casa tem HOJE fica a ser a deste casamento. Foi
    // este o modelo que o casal viu nas capturas da montra ao inscrever-se; se
    // o admin designar outro amanhã, é para quem vier a seguir.
    fixarPecaOrigemDoCasal($conn, $cid);

    // O plano que o casal escolheu vira pedido de licença, pendente. É o que o
    // admin vai ver na página das licenças, e é o que o casal vê ao entrar.
    $pedido = 0;
    if (!empty($d['licenca'])) {
        $pedido = licRegistarPedido($conn, $cid, (array)$d['licenca'], 'inicial');
    }

    registar($conn, 'registo_publico', $nomeConta, $email);
    ok(['casamento' => $cid, 'dados_do_evento' => $gravadas, 'pedido' => $pedido,
        // Se vieram dados de porteiro sem o módulo que os justifica, a conta não
        // se criou — e diz-se, para o casal não ficar à espera dela.
        'porteiro_ignorado' => $porteiroIgnorado]);
}

// ============================================================
// ATENDIMENTO — a caixa de perguntas das páginas públicas
//
// Quem chega ao login ou à inscrição com uma dúvida não tinha por onde a pôr:
// fechava a página e ia-se embora, e nunca se ficava a saber porquê. As
// perguntas são sempre as mesmas meia dúzia — quanto custa, como funciona, se
// é preciso pagar já —, e por isso não é preciso ninguém do outro lado a
// teclar: chegam as respostas já escritas, e os contactos para quem precise
// mesmo de falar com uma pessoa.
// ============================================================

/** As definições do atendimento (vivem no casamento 0: são da casa). */
function atendimentoDefs(mysqli $conn): array {
    global $P;
    $d = [];
    $r = @$conn->query("SELECT chave, valor FROM {$P}definicoes
                        WHERE casamento_id=0 AND chave LIKE 'atendimento.%'");
    if ($r) while ($x = $r->fetch_row()) $d[substr((string)$x[0], 12)] = (string)$x[1];
    return $d + ['ativo' => '0', 'nome' => 'Atendimento', 'cargo' => '', 'foto' => '',
                 'saudacao' => '', 'telefone' => '', 'whatsapp' => '', 'email' => '',
                 'horario' => '',
                 // O chat AO VIVO, para quando houver um. Ver licAoVivo().
                 'chat_modo' => 'nenhum', 'chat_script' => '', 'chat_rotulo' => ''];
}

/**
 * A ligação a um chat ao vivo — a costura, não o pano.
 *
 * A caixa de perguntas responde ao que se repete, e isso resolve a maioria. O
 * que ela não faz é falar com uma pessoa em tempo real, e mais tarde ou mais
 * cedo vai querer-se lá uma ferramenta dessas (Tawk, Crisp, Chatwoot, o que
 * for). Fica aqui o encaixe pronto, e DESLIGADO: enquanto ninguém configurar
 * nada, a página pública não carrega script nenhum de fora nem mostra botão
 * nenhum a prometer o que não existe.
 *
 * O contrato com a página está escrito em assets/atendimento.js.
 *
 * Só se aceita https://. Um script de terceiros corre nas páginas de entrada e
 * de inscrição com todos os poderes da página: quem o põe aqui está a confiar
 * nesse fornecedor, e ao menos que a ligação até ele não seja em claro.
 */
function atendimentoAoVivo(array $d): array {
    $modo = (string)($d['chat_modo'] ?? 'nenhum');
    $src  = trim((string)($d['chat_script'] ?? ''));
    if ($modo !== 'script' || $src === '' || !preg_match('~^https://~i', $src)) {
        return ['modo' => 'nenhum'];
    }
    return ['modo' => 'script', 'script' => $src,
            'rotulo' => trim((string)($d['chat_rotulo'] ?? '')) ?: 'Falar com uma pessoa'];
}

/** As perguntas. $todas inclui as desligadas — é a vista do admin. */
function atendimentoFaq(mysqli $conn, bool $todas = false): array {
    global $P;
    $onde = $todas ? '' : 'WHERE ativo=1';
    $r = @$conn->query("SELECT id, pergunta, resposta, ordem, ativo FROM {$P}atendimento_faq
                        $onde ORDER BY ordem, id");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id']; $x['ordem'] = (int)$x['ordem']; $x['ativo'] = (int)$x['ativo'];
        $out[] = $x;
    }
    return $out;
}

if ($acao === 'atendimento_publico') {
    // Sem sessão nenhuma: é para quem ainda não entrou. Devolve só o que se
    // mostra, e nada mais — desligado, não devolve sequer as perguntas.
    $d = atendimentoDefs($conn);
    if ((string)$d['ativo'] !== '1') { ok(['ativo' => false]); }
    ok(['ativo' => true,
        'atendente' => ['nome' => $d['nome'], 'cargo' => $d['cargo'], 'foto' => $d['foto']],
        'saudacao'  => $d['saudacao'],
        'contactos' => ['telefone' => $d['telefone'], 'whatsapp' => $d['whatsapp'],
                        'email' => $d['email'], 'horario' => $d['horario']],
        'perguntas' => atendimentoFaq($conn, false),
        // Sem chat ao vivo configurado, sai só {modo:'nenhum'}: nem o endereço
        // do script viaja para quem não vai precisar dele.
        'ao_vivo'   => atendimentoAoVivo($d)]);
}

if ($acao === 'atendimento_ler') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê o atendimento.');
    ok(['def' => atendimentoDefs($conn), 'perguntas' => atendimentoFaq($conn, true)]);
}

if ($acao === 'atendimento_guardar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita o atendimento.');
    exigirCsrf();
    $d = corpo();
    $campos = [
        'ativo'    => !empty($d['ativo']) ? '1' : '0',
        'nome'     => mb_substr(trim((string)($d['nome'] ?? '')), 0, 80),
        'cargo'    => mb_substr(trim((string)($d['cargo'] ?? '')), 0, 80),
        'saudacao' => mb_substr(trim((string)($d['saudacao'] ?? '')), 0, 600),
        'telefone' => mb_substr(trim((string)($d['telefone'] ?? '')), 0, 40),
        'whatsapp' => mb_substr(trim((string)($d['whatsapp'] ?? '')), 0, 40),
        'email'    => mb_substr(trim((string)($d['email'] ?? '')), 0, 120),
        'horario'  => mb_substr(trim((string)($d['horario'] ?? '')), 0, 120),
        // O encaixe do chat ao vivo (ver atendimentoAoVivo).
        'chat_modo'   => (($d['chat_modo'] ?? '') === 'script') ? 'script' : 'nenhum',
        'chat_script' => mb_substr(trim((string)($d['chat_script'] ?? '')), 0, 400),
        'chat_rotulo' => mb_substr(trim((string)($d['chat_rotulo'] ?? '')), 0, 60),
    ];
    if ($campos['nome'] === '') erro('Dê um nome a quem atende — é o que aparece na caixa.');
    if ($campos['email'] !== '' && !filter_var($campos['email'], FILTER_VALIDATE_EMAIL))
        erro('O email de contacto é inválido.');
    // Um script de terceiros corre nas páginas públicas com todos os poderes
    // delas. Exige-se https:// — não para o tornar seguro, que isso depende de
    // em quem se confia, mas para a ligação até ele não ser em claro.
    if ($campos['chat_modo'] === 'script') {
        if ($campos['chat_script'] === '')
            erro('Indique o endereço do script do chat, ou deixe o chat ao vivo em «nenhum».');
        if (!preg_match('~^https://~i', $campos['chat_script']))
            erro('O endereço do script tem de começar por https:// — não se carrega código de fora em claro.');
    }
    foreach ($campos as $ch => $vl) {
        $chave = 'atendimento.' . $ch;
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,?,?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        if (!$st) continue;
        $st->bind_param('ss', $chave, $vl);
        @$st->execute();
    }
    registar($conn, 'atendimento_guardar', $campos['nome'],
             $campos['ativo'] === '1' ? 'ligado' : 'desligado');
    ok(['def' => atendimentoDefs($conn)]);
}

if ($acao === 'atendimento_faq_guardar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita as perguntas.');
    exigirCsrf();
    $d = corpo();
    $id  = (int)($d['id'] ?? 0);
    $per = mb_substr(trim((string)($d['pergunta'] ?? '')), 0, 200);
    $res = mb_substr(trim((string)($d['resposta'] ?? '')), 0, 4000);
    $ord = max(0, min(9999, (int)($d['ordem'] ?? 0)));
    $atv = !empty($d['ativo']) ? 1 : 0;
    if ($per === '') erro('Escreva a pergunta.');
    if ($res === '') erro('Escreva a resposta — uma pergunta sem resposta não serve de nada.');
    if ($id > 0) {
        $st = $conn->prepare("UPDATE {$P}atendimento_faq
                              SET pergunta=?, resposta=?, ordem=?, ativo=? WHERE id=?");
        $st->bind_param('ssiii', $per, $res, $ord, $atv, $id);
        if (!$st->execute()) erro('Não foi possível guardar a pergunta.');
    } else {
        // Sem ordem indicada, entra no fim: é onde uma pergunta nova pertence.
        if ($ord === 0) {
            $r = @$conn->query("SELECT COALESCE(MAX(ordem),0)+10 FROM {$P}atendimento_faq");
            $ord = ($r && ($x = $r->fetch_row())) ? (int)$x[0] : 10;
        }
        $st = $conn->prepare("INSERT INTO {$P}atendimento_faq (pergunta,resposta,ordem,ativo)
                              VALUES (?,?,?,?)");
        $st->bind_param('ssii', $per, $res, $ord, $atv);
        if (!$st->execute()) erro('Não foi possível criar a pergunta.');
        $id = $conn->insert_id;
    }
    registar($conn, 'atendimento_pergunta', $per, 'id ' . $id);
    ok(['id' => $id, 'perguntas' => atendimentoFaq($conn, true)]);
}

if ($acao === 'atendimento_faq_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga perguntas.');
    exigirCsrf();
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT pergunta FROM {$P}atendimento_faq WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $x = $st->get_result()->fetch_assoc();
    if (!$x) erro('Pergunta não encontrada.');
    $st = $conn->prepare("DELETE FROM {$P}atendimento_faq WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar a pergunta.');
    registar($conn, 'atendimento_pergunta_apagar', (string)$x['pergunta'], 'id ' . $id);
    ok(['perguntas' => atendimentoFaq($conn, true)]);
}

if ($acao === 'atendimento_foto') {
    // A cara de quem atende. Fica em assets/atendimento/, fora do que é de um
    // casamento: é da casa, e vê-se antes de haver casamento nenhum.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda a foto.');
    exigirCsrf();
    $src = origemUpload('ficheiro', 3 * 1024 * 1024);
    $ext = strtolower(pathinfo($src['nome'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) erro('Use JPG, PNG ou WEBP.');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $src['tmp']); finfo_close($fi);
        if (!in_array($mt, ['image/jpeg','image/png','image/webp'], true))
            erro('O conteúdo do ficheiro não corresponde a uma imagem.');
    }
    $dir = __DIR__ . '/assets/atendimento';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nomeFich = 'atendente-' . time() . '-' . random_int(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!moverUpload($src, "$dir/$nomeFich")) erro('Não foi possível guardar a imagem.');
    $caminho = 'assets/atendimento/' . $nomeFich;

    // A anterior sai: era só desta caixa, e ninguém mais lhe pega.
    $antiga = (string)(atendimentoDefs($conn)['foto'] ?? '');
    if ($antiga !== '' && str_starts_with($antiga, 'assets/atendimento/')) @unlink(__DIR__ . '/' . $antiga);

    $chave = 'atendimento.foto';
    $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,?,?)
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $st->bind_param('ss', $chave, $caminho); @$st->execute();
    ok(['path' => $caminho]);
}

if ($acao === 'atendimento_foto_tirar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda a foto.');
    exigirCsrf();
    $antiga = (string)(atendimentoDefs($conn)['foto'] ?? '');
    if ($antiga !== '' && str_starts_with($antiga, 'assets/atendimento/')) @unlink(__DIR__ . '/' . $antiga);
    @$conn->query("UPDATE {$P}definicoes SET valor='' WHERE casamento_id=0 AND chave='atendimento.foto'");
    ok(['path' => '']);
}

// ============================================================
// LICENÇAS — o preçário, os pedidos e o que cada casamento tem
//
// O preçário é da casa: módulos, os seus escalões (as medidas em que se vendem)
// e os pacotes. O casal escolhe um pacote ou monta o seu, aceita as políticas e
// submete; o admin aprova, recusa ou revoga. O que fica de pé são as concessões
// — e são elas, e nunca o pedido, que abrem as portas (ver licencaModulos).
// ============================================================

/** O preçário inteiro, pronto para desenhar: módulos com escalões, e pacotes. */
function licCatalogo(mysqli $conn): array {
    global $P;
    $mods = [];
    $r = @$conn->query("SELECT id,chave,nome,resumo,beneficio,icone,imagem,obrigatorio,ordem,ativo
                        FROM {$P}lic_modulos ORDER BY ordem, id");
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id']; $x['ativo'] = (int)$x['ativo']; $x['ordem'] = (int)$x['ordem'];
        $x['obrigatorio'] = (int)$x['obrigatorio'];
        $x['escaloes'] = [];
        $mods[$x['id']] = $x;
    }
    $r = @$conn->query("SELECT id,modulo_id,chave,nome,resumo,preco,limite,editar,todos_modelos,ordem,ativo
                        FROM {$P}lic_escaloes ORDER BY ordem, id");
    if ($r) while ($x = $r->fetch_assoc()) {
        $mid = (int)$x['modulo_id'];
        if (!isset($mods[$mid])) continue;
        $mods[$mid]['escaloes'][] = [
            'id' => (int)$x['id'], 'chave' => $x['chave'], 'nome' => $x['nome'],
            'resumo' => $x['resumo'], 'preco' => (float)$x['preco'],
            'limite' => (int)$x['limite'], 'editar' => (int)$x['editar'],
            'todos_modelos' => (int)$x['todos_modelos'],
            'ordem' => (int)$x['ordem'], 'ativo' => (int)$x['ativo'],
            'modulo' => $mods[$mid]['chave'], 'modulo_nome' => $mods[$mid]['nome'],
        ];
    }

    $pacs = [];
    $r = @$conn->query("SELECT id,chave,nome,promessa,resumo,preco,meses,etiqueta,destaque,ordem,ativo
                        FROM {$P}lic_pacotes ORDER BY ordem, id");
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id']; $x['preco'] = (float)$x['preco'];
        $x['meses'] = (int)$x['meses']; $x['destaque'] = (int)$x['destaque'];
        $x['ordem'] = (int)$x['ordem']; $x['ativo'] = (int)$x['ativo'];
        $x['itens'] = [];
        $pacs[$x['id']] = $x;
    }
    $r = @$conn->query("SELECT pacote_id, escalao_id FROM {$P}lic_pacote_itens");
    if ($r) while ($x = $r->fetch_row()) {
        $p = (int)$x[0];
        if (isset($pacs[$p])) $pacs[$p]['itens'][] = (int)$x[1];
    }
    // Quanto custariam, à peça, os escalões de cada pacote: é o que dá a
    // poupança. Uma conta e não uma promessa — o número tem de bater certo.
    $precoEsc = [];
    foreach ($mods as $m) foreach ($m['escaloes'] as $e) $precoEsc[$e['id']] = $e['preco'];
    foreach ($pacs as &$p) {
        $avulso = 0.0;
        foreach ($p['itens'] as $eid) $avulso += (float)($precoEsc[$eid] ?? 0);
        $p['avulso'] = $avulso;
        $p['poupanca'] = max(0, $avulso - $p['preco']);
    }
    unset($p);

    return ['modulos' => array_values($mods), 'pacotes' => array_values($pacs),
            'prazos' => licPrazos($conn)];
}

/**
 * Os prazos de licença, e o factor de preço de cada um.
 *
 * Os preços do preçário são os do prazo BASE (factor 1.000). Escolher outro
 * prazo multiplica-os — é assim que seis meses e dois anos deixam de custar o
 * mesmo, que é o que acontecia enquanto o prazo não tinha preço nenhum.
 */
function licPrazos(mysqli $conn): array {
    global $P;
    $out = [];
    $r = @$conn->query("SELECT id,meses,nome,resumo,fator,etiqueta,ordem,ativo
                        FROM {$P}lic_prazos WHERE ativo=1 ORDER BY ordem, meses");
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['id' => (int)$x['id'], 'meses' => (int)$x['meses'], 'nome' => $x['nome'],
                  'resumo' => $x['resumo'], 'fator' => (float)$x['fator'],
                  'etiqueta' => $x['etiqueta'], 'ordem' => (int)$x['ordem']];
    }
    return $out;
}

/** O factor de preço de um prazo. Sem prazo conhecido, não se multiplica nada. */
function licFator(mysqli $conn, int $meses): float {
    foreach (licPrazos($conn) as $p) if ($p['meses'] === $meses) return (float)$p['fator'];
    return 1.0;
}

/**
 * Quanto vale, hoje, o que este casamento já tem — módulo a módulo.
 *
 * É o desconto de um reforço: quem tem «até 80 convidados» e quer «até 200»
 * paga o degrau, e não a lista toda outra vez. Só conta o que se sabe medir —
 * uma concessão dada à mão não tem escalão, e nesses casos não há número
 * nenhum a descontar (mas também não há upgrade a fazer: um módulo concedido
 * sem escalão vem sempre sem limites, e por isso já cobre tudo).
 *
 * Devolve [chave_do_modulo => preço de catálogo do escalão em vigor].
 */
function licCreditosEmVigor(mysqli $conn, int $cid): array {
    global $P;
    if ($cid <= 0) return [];
    $out = [];
    $r = @$conn->query("SELECT c.modulo_chave, e.preco
                        FROM {$P}lic_concessoes c
                        JOIN {$P}lic_escaloes e ON e.id = c.escalao_id
                        WHERE c.casamento_id = " . (int)$cid);
    if ($r) while ($x = $r->fetch_assoc()) $out[(string)$x['modulo_chave']] = (float)$x['preco'];
    return $out;
}

/** As chaves de módulo que nenhum plano pode dispensar. */
function licObrigatorios(mysqli $conn): array {
    global $P;
    $out = [];
    $r = @$conn->query("SELECT chave FROM {$P}lic_modulos WHERE obrigatorio=1 AND ativo=1");
    if ($r) while ($x = $r->fetch_row()) $out[] = (string)$x[0];
    return $out;
}

/** As políticas de utilização em vigor (a versão publicada mais alta). */
function licPolitica(mysqli $conn): array {
    global $P;
    $r = @$conn->query("SELECT id,versao,titulo,corpo,atualizado_em FROM {$P}lic_politicas
                        WHERE publicada=1 ORDER BY versao DESC LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) {
        $x['id'] = (int)$x['id']; $x['versao'] = (int)$x['versao'];
        return $x;
    }
    return ['id' => 0, 'versao' => 0, 'titulo' => 'Políticas de Utilização',
            'corpo' => '', 'atualizado_em' => null];
}

/** O pedido de licença de um casamento num certo estado, com os seus itens. */
function licPedido(mysqli $conn, int $cid, string $estado = 'pendente'): ?array {
    global $P;
    if ($cid <= 0) return null;
    $st = @$conn->prepare("SELECT * FROM {$P}lic_pedidos
                           WHERE casamento_id=? AND estado=? ORDER BY id DESC LIMIT 1");
    if (!$st) return null;
    $st->bind_param('is', $cid, $estado);
    if (!@$st->execute()) return null;
    $p = $st->get_result()->fetch_assoc();
    if (!$p) return null;
    $p['id'] = (int)$p['id']; $p['total'] = (float)$p['total']; $p['meses'] = (int)$p['meses'];
    $j = json_decode((string)($p['fotos'] ?? ''), true);
    $p['fotos'] = is_array($j) ? $j : [];
    $p['itens'] = [];
    $r = @$conn->query("SELECT escalao_id,modulo_chave,escalao_nome,preco,credito,limite,editar,todos_modelos
                        FROM {$P}lic_pedido_itens WHERE pedido_id=" . (int)$p['id'] . " ORDER BY id");
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['escalao_id'] = (int)$x['escalao_id']; $x['preco'] = (float)$x['preco'];
        $x['credito'] = (float)$x['credito'];
        $x['limite'] = (int)$x['limite']; $x['editar'] = (int)$x['editar'];
        $x['todos_modelos'] = (int)$x['todos_modelos'];
        $p['itens'][] = $x;
    }
    return $p;
}

/**
 * Grava (ou regrava) o pedido de licença pendente de um casamento.
 *
 * Um casamento tem, quando muito, um pedido pendente: mexer na escolha
 * reescreve o que lá está em vez de abrir um segundo. Devolve o id, ou 0.
 *
 * O preço de cada escalão fica congelado no pedido. Um preçário que mude
 * amanhã não pode reescrever aquilo com que o casal concordou hoje.
 */
/**
 * O plano PEDIDO inclui este módulo?
 *
 * Serve para não criar aquilo que a licença não abre. A conta do porteiro é o
 * caso: sem o módulo «Controlo à porta», é uma conta que entra e não encontra
 * nada — e que fica na lista da equipa a dizer que alguém tem um lugar que
 * afinal não tem. Lê-se o mesmo plano que o pedido vai registar: um pacote traz
 * os seus escalões, e sem pacote vale a escolha à peça.
 */
function licPlanoTemModulo(mysqli $conn, array $d, string $modulo): bool {
    global $P;
    $escIds = [];
    $pacoteId = (int)($d['pacote'] ?? 0);
    if ($pacoteId > 0) {
        $r = @$conn->query("SELECT escalao_id FROM {$P}lic_pacote_itens WHERE pacote_id=$pacoteId");
        if ($r) while ($x = $r->fetch_row()) $escIds[] = (int)$x[0];
    } else {
        foreach ((array)($d['escaloes'] ?? []) as $e) { $e = (int)$e; if ($e > 0) $escIds[] = $e; }
    }
    if (!$escIds) return false;
    return licEscaloesTemModulo($conn, $escIds, $modulo);
}

/** Algum destes escalões é do módulo indicado? */
function licEscaloesTemModulo(mysqli $conn, array $escIds, string $modulo): bool {
    global $P;
    $ids = array_values(array_unique(array_filter(array_map('intval', $escIds))));
    if (!$ids) return false;
    $lista = implode(',', $ids);
    $mod = $conn->real_escape_string($modulo);
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}lic_escaloes e
                        JOIN {$P}lic_modulos m ON m.id = e.modulo_id
                        WHERE e.id IN ($lista) AND m.chave='$mod' AND e.ativo=1 AND m.ativo=1");
    return $r && (int)$r->fetch_assoc()['n'] > 0;
}

/** O casamento JÁ TEM este módulo concedido? */
function licCasamentoTemModulo(mysqli $conn, int $cid, string $modulo): bool {
    global $P;
    if ($cid <= 0) return false;
    $mod = $conn->real_escape_string($modulo);
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}lic_concessoes
                        WHERE casamento_id=" . (int)$cid . " AND modulo_chave='$mod'");
    return $r && (int)$r->fetch_assoc()['n'] > 0;
}

function licRegistarPedido(mysqli $conn, int $cid, array $d, string $tipo,
                           ?string &$porque = null): int {
    global $P;
    $porque = null;
    if ($cid <= 0) return 0;
    $tipo = $tipo === 'upgrade' ? 'upgrade' : 'inicial';

    // Um pacote traz os seus escalões; sem pacote, vale a escolha à peça.
    $pacoteId = (int)($d['pacote'] ?? 0);
    $escIds = [];
    $pacNome = ''; $meses = max(0, min(120, (int)($d['meses'] ?? 0)));
    if ($pacoteId > 0) {
        $st = @$conn->prepare("SELECT nome, meses FROM {$P}lic_pacotes WHERE id=? AND ativo=1");
        if (!$st) return 0;
        $st->bind_param('i', $pacoteId); @$st->execute();
        $pa = $st->get_result()->fetch_assoc();
        if (!$pa) return 0;
        $pacNome = (string)$pa['nome'];
        if ($meses <= 0) $meses = (int)$pa['meses'];
        $r = @$conn->query("SELECT escalao_id FROM {$P}lic_pacote_itens WHERE pacote_id=$pacoteId");
        if ($r) while ($x = $r->fetch_row()) $escIds[] = (int)$x[0];
    } else {
        foreach ((array)($d['escaloes'] ?? []) as $e) { $e = (int)$e; if ($e > 0) $escIds[] = $e; }
    }
    $escIds = array_values(array_unique($escIds));
    if (!$escIds) { $porque = 'Escolha um pacote, ou pelo menos um módulo.'; return 0; }

    // Os escalões, tal como estão hoje. Só entram os que existem e estão de pé,
    // e um módulo só conta uma vez: dois escalões do mesmo módulo era vender
    // «até 80» e «sem limite» ao mesmo casamento.
    $lista = implode(',', array_map('intval', $escIds));
    $r = @$conn->query("SELECT e.id, e.nome, e.preco, e.limite, e.editar, e.todos_modelos, m.chave modulo
                        FROM {$P}lic_escaloes e JOIN {$P}lic_modulos m ON m.id = e.modulo_id
                        WHERE e.id IN ($lista) AND e.ativo=1 AND m.ativo=1
                        ORDER BY m.ordem, e.ordem");
    // O que já está pago desconta-se. Subir de «até 80 convidados» para «até
    // 200» não é comprar a lista outra vez: é pagar o degrau. Cobrar o escalão
    // novo por inteiro fazia o reforço custar mais do que o plano inteiro tinha
    // custado — e desmentia a promessa, escrita na própria página, de que se
    // paga só a diferença.
    //
    // Credita-se pelo preço de HOJE do escalão em vigor, e não pelo que foi
    // pago na altura: é o único número que se pode comparar com o de hoje sem
    // misturar duas tabelas de preços. Nunca desce abaixo de zero — descer de
    // escalão não devolve dinheiro, dá o escalão mais baixo.
    $creditos = licCreditosEmVigor($conn, $cid);

    $itens = []; $total = 0.0;
    if ($r) while ($x = $r->fetch_assoc()) {
        $mc = (string)$x['modulo'];
        if (isset($itens[$mc])) continue;
        $cheio  = (float)$x['preco'];
        $credito = min($cheio, (float)($creditos[$mc] ?? 0));
        $itens[$mc] = ['escalao_id' => (int)$x['id'], 'modulo_chave' => $mc,
                       'escalao_nome' => (string)$x['nome'], 'preco' => $cheio - $credito,
                       'credito' => $credito,
                       'limite' => (int)$x['limite'], 'editar' => (int)$x['editar'],
                       'todos_modelos' => (int)$x['todos_modelos']];
        $total += $cheio - $credito;
    }
    if (!$itens) return 0;

    // Nenhum plano dispensa os módulos obrigatórios. A lista de convidados é o
    // coração da casa: sem ela, as mesas sentam quem? A porta recebe quem?
    // Recusa-se aqui, e não só no ecrã — o ecrã esconde o botão, mas a ação
    // continua a poder ser chamada à mão.
    foreach (licObrigatorios($conn) as $ob) {
        if (isset($itens[$ob])) continue;
        // Num reforço basta que o casamento JÁ o tenha: quem já tem a lista de
        // convidados não a compra outra vez para poder juntar as mesas.
        $g = licencaModulos($conn, $cid);
        if (!empty($g[$ob]['ativo'])) continue;
        $nome = $ob;
        $rn = @$conn->query("SELECT nome FROM {$P}lic_modulos WHERE chave='"
                            . $conn->real_escape_string($ob) . "' LIMIT 1");
        if ($rn && ($x = $rn->fetch_row())) $nome = (string)$x[0];
        $porque = "Todos os planos incluem «{$nome}» — é a base de que o resto depende. "
                . 'Escolha um escalão desse módulo.';
        return 0;
    }

    if ($pacoteId > 0) {
        // O pacote tem preço próprio — é essa a vantagem de o levar.
        $st = @$conn->prepare("SELECT preco FROM {$P}lic_pacotes WHERE id=?");
        if ($st) { $st->bind_param('i', $pacoteId); @$st->execute();
                   $pp = $st->get_result()->fetch_assoc();
                   if ($pp) $total = (float)$pp['preco']; }
    }
    if ($meses <= 0) $meses = 12;

    // E o prazo tem preço: os valores do preçário são os do prazo base, e o
    // factor do prazo escolhido multiplica-os. Guarda-se o preço já
    // multiplicado, item a item, para o pedido continuar a poder ser lido
    // sozinho daqui a um ano — sem depender de uma tabela de factores que
    // entretanto pode ter mudado.
    $fator = licFator($conn, $meses);
    if ($fator > 0 && abs($fator - 1.0) > 0.0001) {
        foreach ($itens as $k => $it) {
            $itens[$k]['preco']   = round($it['preco'] * $fator, 2);
            $itens[$k]['credito'] = round($it['credito'] * $fator, 2);
        }
        $total = round($total * $fator, 2);
    }

    // A coluna 'fotos' do pedido é do tempo em que o casal escolhia as
    // fotografias do convite ao comprar a licença. Continua a guardar o que os
    // pedidos antigos lá puseram; novos não põem nada.
    $fotosJson = null;
    $pol   = licPolitica($conn);
    $nota  = mb_substr(trim((string)($d['nota'] ?? '')), 0, 1000);
    $ip    = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $moeda = 'Kz';

    // Um pendente já existente é o mesmo pedido a mudar de ideias.
    $antigo = licPedido($conn, $cid, 'pendente');
    if ($antigo) {
        $st = @$conn->prepare("UPDATE {$P}lic_pedidos
            SET tipo=?, pacote_id=?, pacote_nome=?, meses=?, total=?, moeda=?, nota_casal=?,
                politica_versao=?, fotos=?, aceite_em=NOW(), aceite_ip=?, criado_em=NOW()
            WHERE id=? AND casamento_id=?");
        if (!$st) return 0;
        $pid0 = $pacoteId ?: null; $pv = (int)$pol['versao']; $pidRow = (int)$antigo['id'];
        // s i s i d s s i s s i i — doze tipos para doze variáveis.
        $st->bind_param('sisidssissii', $tipo, $pid0, $pacNome, $meses, $total, $moeda,
                        $nota, $pv, $fotosJson, $ip, $pidRow, $cid);
        if (!@$st->execute()) return 0;
        $pedidoId = $pidRow;
        @$conn->query("DELETE FROM {$P}lic_pedido_itens WHERE pedido_id=$pedidoId");
    } else {
        $st = @$conn->prepare("INSERT INTO {$P}lic_pedidos
            (casamento_id, tipo, estado, pacote_id, pacote_nome, meses, total, moeda,
             nota_casal, politica_versao, fotos, aceite_em, aceite_ip)
            VALUES (?,?,'pendente',?,?,?,?,?,?,?,?,NOW(),?)");
        if (!$st) return 0;
        $pid0 = $pacoteId ?: null; $pv = (int)$pol['versao'];
        $st->bind_param('isisidssiss', $cid, $tipo, $pid0, $pacNome, $meses, $total, $moeda,
                        $nota, $pv, $fotosJson, $ip);
        if (!@$st->execute()) return 0;
        $pedidoId = $conn->insert_id;
    }

    $st = @$conn->prepare("INSERT INTO {$P}lic_pedido_itens
        (pedido_id, escalao_id, modulo_chave, escalao_nome, preco, credito, limite, editar, todos_modelos)
        VALUES (?,?,?,?,?,?,?,?,?)");
    if ($st) foreach ($itens as $it) {
        $st->bind_param('iissddiii', $pedidoId, $it['escalao_id'], $it['modulo_chave'],
                        $it['escalao_nome'], $it['preco'], $it['credito'], $it['limite'],
                        $it['editar'], $it['todos_modelos']);
        @$st->execute();
    }

    // A peça de origem que a casa tem HOJE passa a ser a deste casamento. Foi
    // este o modelo que ele viu nas capturas ao escolher o plano; se o admin
    // designar outro amanhã, é para quem vier a seguir.
    fixarPecaOrigemDoCasal($conn, $cid);

    // O casamento passa a dizer que tem um pedido em cima da mesa — excepto se
    // já tem licença ativa e isto é um reforço: aí continua a valer o que tem.
    $st = @$conn->prepare("UPDATE {$P}casamentos SET licenca_estado='pendente'
                           WHERE id=? AND licenca_estado <> 'ativa'");
    if ($st) { $st->bind_param('i', $cid); @$st->execute(); }
    return $pedidoId;
}

/**
 * Concede a um casamento o que um pedido aprovado lhe dá.
 *
 * Um reforço acrescenta e melhora, nunca tira: quem já tinha «sem limite» não
 * fica com «até 200» por ter pedido outra coisa qualquer. Devolve os módulos
 * que passaram a estar abertos ou que subiram de escalão.
 */
function licAplicarPedido(mysqli $conn, int $cid, array $pedido): array {
    global $P;
    $mudou = [];
    $atual = [];
    $r = @$conn->query("SELECT modulo_chave, limite, editar, todos_modelos
                        FROM {$P}lic_concessoes WHERE casamento_id=" . (int)$cid);
    if ($r) while ($x = $r->fetch_assoc()) $atual[(string)$x['modulo_chave']] = $x;

    foreach ($pedido['itens'] as $it) {
        $mc = (string)$it['modulo_chave'];
        $lim = (int)$it['limite']; $ed = (int)$it['editar']; $tm = (int)$it['todos_modelos'];
        if (isset($atual[$mc])) {
            // Sem limite (0) ganha sempre a qualquer número; entre números, o maior.
            $limA = (int)$atual[$mc]['limite'];
            $lim = ($limA === 0 || $lim === 0) ? 0 : max($limA, $lim);
            $ed  = max($ed, (int)$atual[$mc]['editar']);
            $tm  = max($tm, (int)$atual[$mc]['todos_modelos']);
            if ($lim === $limA && $ed === (int)$atual[$mc]['editar']
                && $tm === (int)$atual[$mc]['todos_modelos']) {
                continue;   // já tinha isto, ou melhor
            }
        }
        $st = @$conn->prepare("INSERT INTO {$P}lic_concessoes
            (casamento_id, modulo_chave, escalao_id, escalao_nome, limite, editar, todos_modelos, pedido_id, desde)
            VALUES (?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE escalao_id=VALUES(escalao_id), escalao_nome=VALUES(escalao_nome),
                limite=VALUES(limite), editar=VALUES(editar), todos_modelos=VALUES(todos_modelos),
                pedido_id=VALUES(pedido_id), desde=NOW()");
        if (!$st) continue;
        $eid = (int)$it['escalao_id']; $en = (string)$it['escalao_nome']; $pid = (int)$pedido['id'];
        $st->bind_param('isisiiii', $cid, $mc, $eid, $en, $lim, $ed, $tm, $pid);
        if (@$st->execute()) $mudou[] = $mc;
    }
    return $mudou;
}

/**
 * Abre todos os módulos a um casamento, sem limites.
 *
 * É o que um casamento criado PELA ADMINISTRAÇÃO recebe: quem o criou fê-lo de
 * propósito, e entregar-lhe uma casa sem portas nenhumas — obrigando-o a ir a
 * seguir conceder cinco módulos à mão — era transformar um gesto em dois. Os
 * pedidos de licença são para quem se inscreve de fora; aqui já houve decisão.
 */
function licConcederTudo(mysqli $conn, int $cid, string $rotulo = 'Concedido pela administração'): int {
    global $P;
    if ($cid <= 0) return 0;
    $n = 0;
    foreach (licencaModulosTudo() as $mc => $g) {
        $st = @$conn->prepare("INSERT INTO {$P}lic_concessoes
            (casamento_id, modulo_chave, escalao_nome, limite, editar, todos_modelos, desde)
            VALUES (?,?,'Tudo incluído',0,?,?,NOW())
            ON DUPLICATE KEY UPDATE limite=0, editar=VALUES(editar),
                todos_modelos=VALUES(todos_modelos)");
        if (!$st) continue;
        $ed = (int)$g['editar']; $tm = (int)$g['todos_modelos'];
        $st->bind_param('isii', $cid, $mc, $ed, $tm);
        if (@$st->execute()) $n++;
    }
    $st = @$conn->prepare("UPDATE {$P}casamentos SET licenca_estado='ativa', licenca_pacote=?
                           WHERE id=?");
    if ($st) { $st->bind_param('si', $rotulo, $cid); @$st->execute(); }
    return $n;
}

/**
 * Os pedidos já decididos deste casamento, do mais recente para trás.
 *
 * É o extracto da licença: o que foi pedido, quando, quanto custou e o que a
 * administração respondeu. Sem isto, o casal que reforçou a licença três vezes
 * não tinha onde confirmar o que pagou de cada vez.
 */
function licHistorico(mysqli $conn, int $cid): array {
    global $P;
    if ($cid <= 0) return [];
    $st = @$conn->prepare("SELECT id, tipo, estado, pacote_nome, meses, total, moeda,
                                  nota_admin, criado_em, decidido_em
                           FROM {$P}lic_pedidos
                           WHERE casamento_id = ? AND estado IN ('aprovado','recusado')
                           ORDER BY decidido_em DESC, id DESC LIMIT 30");
    if (!$st) return [];
    $st->bind_param('i', $cid);
    if (!@$st->execute()) return [];
    $r = $st->get_result();
    $out = [];
    while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id']; $x['total'] = (float)$x['total']; $x['meses'] = (int)$x['meses'];
        $x['itens'] = [];
        $out[(int)$x['id']] = $x;
    }
    if ($out) {
        $ids = implode(',', array_map('intval', array_keys($out)));
        $ri = @$conn->query("SELECT pedido_id, modulo_chave, escalao_nome, preco, credito
                             FROM {$P}lic_pedido_itens WHERE pedido_id IN ($ids) ORDER BY id");
        if ($ri) while ($x = $ri->fetch_assoc()) {
            $p = (int)$x['pedido_id'];
            if (!isset($out[$p])) continue;
            $x['preco'] = (float)$x['preco'];
            $x['credito'] = (float)$x['credito'];
            $out[$p]['itens'][] = $x;
        }
    }
    $lista = array_values($out);

    // A revogação também é história — e é a que mais importa contar.
    //
    // Não é um pedido, e por isso não estava em lado nenhum desta lista: o
    // casal via a licença fechada e um histórico que acabava no dia em que ela
    // lhe fora concedida, como se nada tivesse acontecido depois. Entra aqui,
    // com a data e o motivo que o admin escreveu, porque a pergunta que o
    // casal faz primeiro é «o que é que aconteceu, e quando».
    $rv = @$conn->query("SELECT licenca_revogada_em, licenca_revogada_motivo, licenca_estado
                         FROM {$P}casamentos WHERE id=" . (int)$cid . " LIMIT 1");
    if ($rv && ($x = $rv->fetch_assoc()) && !empty($x['licenca_revogada_em'])) {
        array_unshift($lista, [
            'id'           => 0,
            'tipo'         => 'revogacao',
            'estado'       => 'revogado',
            'pacote_nome'  => '',
            'meses'        => 0,
            'total'        => 0.0,
            'moeda'        => 'Kz',
            'nota_admin'   => (string)$x['licenca_revogada_motivo'],
            'criado_em'    => $x['licenca_revogada_em'],
            'decidido_em'  => $x['licenca_revogada_em'],
            'em_vigor'     => ((string)$x['licenca_estado'] === 'revogada'),
            'itens'        => [],
        ]);
    }
    return $lista;
}

/** Tudo o que a página da licença do casal precisa de saber, num sítio só. */
function licResumo(mysqli $conn, int $cid): array {
    global $P;
    $cas = ['nome' => '', 'estado' => '', 'licenca_estado' => 'sem', 'licenca_pacote' => '',
            'revogada_em' => null, 'revogada_motivo' => ''];
    if ($cid > 0) {
        $r = @$conn->query("SELECT nome, estado, licenca_estado, licenca_pacote,
                                   licenca_revogada_em, licenca_revogada_motivo
                            FROM {$P}casamentos WHERE id=" . (int)$cid . " LIMIT 1");
        if ($r && ($x = $r->fetch_assoc())) {
            $cas = ['nome' => (string)$x['nome'], 'estado' => (string)$x['estado'],
                    'licenca_estado' => (string)($x['licenca_estado'] ?: 'sem'),
                    'licenca_pacote' => (string)$x['licenca_pacote'],
                    'revogada_em' => $x['licenca_revogada_em'],
                    'revogada_motivo' => (string)$x['licenca_revogada_motivo']];
        }
    }
    return [
        'casamento'  => $cas,
        'prazo'      => licencaInfo($conn, $cid),
        'modulos'    => licencaModulos($conn, $cid),
        'pendente'   => licPedido($conn, $cid, 'pendente'),
        // O último recusado vinha à parte, para um cartão próprio na página. O
        // histórico já traz as recusas, com data e motivo — deixou de ser preciso.
        'historico'  => licHistorico($conn, $cid),
        'catalogo'   => licCatalogo($conn),
        'politica'   => licPolitica($conn),
        'convidados' => convidadosContados($conn, $cid),
        'moeda'      => 'Kz',
    ];
}

// ---- o preçário, à vista de todos (a montra) ----
// É público de propósito: a página de inscrição precisa dele antes de haver
// sessão nenhuma, e um preçário é para se ver.
if ($acao === 'lic_catalogo') {
    ok(['catalogo' => licCatalogo($conn), 'politica' => licPolitica($conn), 'moeda' => 'Kz']);
}
if ($acao === 'lic_politica') {
    ok(['politica' => licPolitica($conn)]);
}

// ---- o que o casal tem, e o que pode pedir ----
if ($acao === 'lic_estado') {
    exigirAdminApi();
    ok(['licenca' => licResumo($conn, casamentoAtual())]);
}

if ($acao === 'lic_pedir') {
    // O casal pede — de novo, ou a reforçar o que já tem. Enquanto o admin não
    // decidir, o pedido é dele: pode mexer-lhe as vezes que quiser.
    exigirAdminApi(); exigirCsrf(); exigirCorrecao();
    $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $d = corpo();
    if (empty($d['aceito'])) erro('É preciso aceitar as políticas de utilização para submeter o pedido.');
    $tem = licencaEstado($conn, $cid) === 'ativa';
    $porque = null;
    $pid = licRegistarPedido($conn, $cid, $d, $tem ? 'upgrade' : 'inicial', $porque);
    if (!$pid) erro($porque ?: 'Escolha pelo menos um módulo ou um pacote.');
    $ped = licPedido($conn, $cid, 'pendente');
    registar($conn, 'licenca_pedido', $ped['pacote_nome'] ?: 'à medida',
             ($tem ? 'reforço' : 'inicial') . ' · ' . count($ped['itens'] ?? []) . ' módulo(s) · '
             . number_format((float)$ped['total'], 2, ',', ' ') . ' Kz');
    ok(['pedido' => $ped, 'licenca' => licResumo($conn, $cid)]);
}

if ($acao === 'lic_pedido_cancelar') {
    exigirAdminApi(); exigirCsrf(); exigirCorrecao();
    $cid = casamentoAtual();
    $ped = licPedido($conn, $cid, 'pendente');
    if (!$ped) erro('Não há nenhum pedido à espera.');
    $st = $conn->prepare("UPDATE {$P}lic_pedidos SET estado='cancelado', decidido_em=NOW()
                          WHERE id=? AND casamento_id=?");
    $pid = (int)$ped['id'];
    $st->bind_param('ii', $pid, $cid);
    if (!$st->execute()) erro('Não foi possível cancelar o pedido.');
    // Sem pedido e sem licença, o casamento volta ao ponto de partida.
    $st = $conn->prepare("UPDATE {$P}casamentos SET licenca_estado='sem'
                          WHERE id=? AND licenca_estado='pendente'");
    $st->bind_param('i', $cid); @$st->execute();
    registar($conn, 'licenca_pedido_cancelar', $ped['pacote_nome'] ?: 'à medida');
    ok(['licenca' => licResumo($conn, $cid)]);
}

// ---- do lado de quem decide ----
if ($acao === 'lic_pedidos') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê os pedidos de licença.');
    $estado = (string)($_GET['estado'] ?? 'pendente');
    if (!in_array($estado, ['pendente','aprovado','recusado','cancelado','todos'], true)) $estado = 'pendente';
    $onde = $estado === 'todos' ? '1=1' : "p.estado='" . $conn->real_escape_string($estado) . "'";
    $r = @$conn->query("SELECT p.*, c.nome casamento_nome, c.estado casamento_estado, c.data_evento
                        FROM {$P}lic_pedidos p JOIN {$P}casamentos c ON c.id = p.casamento_id
                        WHERE $onde AND p.casamento_id = p.casamento_id
                        ORDER BY p.estado='pendente' DESC, p.criado_em DESC LIMIT 200");
    $lista = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id']; $x['casamento_id'] = (int)$x['casamento_id'];
        $x['total'] = (float)$x['total']; $x['meses'] = (int)$x['meses'];
        $x['itens'] = [];
        $lista[(int)$x['id']] = $x;
    }
    if ($lista) {
        $ids = implode(',', array_map('intval', array_keys($lista)));
        $ri = @$conn->query("SELECT pedido_id, modulo_chave, escalao_nome, preco, credito, limite, editar, todos_modelos
                             FROM {$P}lic_pedido_itens WHERE pedido_id IN ($ids) ORDER BY id");
        if ($ri) while ($x = $ri->fetch_assoc()) {
            $p = (int)$x['pedido_id'];
            if (!isset($lista[$p])) continue;
            $x['preco'] = (float)$x['preco']; $x['limite'] = (int)$x['limite'];
            $x['editar'] = (int)$x['editar']; $x['todos_modelos'] = (int)$x['todos_modelos'];
            $lista[$p]['itens'][] = $x;
        }
    }
    $n = @$conn->query("SELECT COUNT(*) FROM {$P}lic_pedidos WHERE estado='pendente' AND casamento_id>0");
    ok(['pedidos' => array_values($lista),
        'pendentes' => $n ? (int)$n->fetch_row()[0] : 0]);
}

if ($acao === 'lic_decidir') {
    // Aprovar abre a porta às duas coisas: os módulos pedidos passam a estar
    // concedidos E o casamento, se ainda estava à espera, fica ativo com o
    // relógio da licença a contar. Aprovar só a licença deixava o casal a olhar
    // para uma porta que continuava fechada.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma decide pedidos de licença.');
    exigirCsrf();
    $d = corpo();
    $pid = (int)($d['id'] ?? 0);
    $dec = (string)($d['decisao'] ?? '');
    if (!in_array($dec, ['aprovar','recusar'], true)) erro('Decisão inválida.');
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 1000);

    $st = $conn->prepare("SELECT p.*, c.nome casamento_nome FROM {$P}lic_pedidos p
                          JOIN {$P}casamentos c ON c.id = p.casamento_id
                          WHERE p.id=? AND p.casamento_id > 0");
    $st->bind_param('i', $pid); $st->execute();
    $ped = $st->get_result()->fetch_assoc();
    if (!$ped) erro('Pedido não encontrado.');
    if ($ped['estado'] !== 'pendente') erro('Este pedido já foi decidido.');
    $cid = (int)$ped['casamento_id'];
    $ped['id'] = $pid;
    $ped['itens'] = licPedido($conn, $cid, 'pendente')['itens'] ?? [];

    if ($dec === 'recusar') {
        $st = $conn->prepare("UPDATE {$P}lic_pedidos SET estado='recusado', nota_admin=?,
                              decidido_em=NOW(), decidido_por=? WHERE id=? AND casamento_id=?");
        $uid = utilizadorId();
        $st->bind_param('siii', $nota, $uid, $pid, $cid);
        $st->execute();
        // Recusar um pedido inicial deixa o casamento sem licença; recusar um
        // reforço não mexe no que o casal já tinha.
        $st = $conn->prepare("UPDATE {$P}casamentos SET licenca_estado='sem'
                              WHERE id=? AND licenca_estado='pendente'");
        $st->bind_param('i', $cid); @$st->execute();
        registar($conn, 'licenca_recusar', (string)$ped['casamento_nome'], $nota);
        ok(['id' => $pid, 'estado' => 'recusado']);
    }

    // Aprovar.
    $mudou = licAplicarPedido($conn, $cid, $ped);
    $meses = (int)$ped['meses'];
    $st = $conn->prepare("UPDATE {$P}lic_pedidos SET estado='aprovado', nota_admin=?,
                          decidido_em=NOW(), decidido_por=? WHERE id=? AND casamento_id=?");
    $uid = utilizadorId();
    $st->bind_param('siii', $nota, $uid, $pid, $cid);
    $st->execute();

    $pacote = (string)($ped['pacote_nome'] ?: 'Plano à medida');
    $st = $conn->prepare("UPDATE {$P}casamentos
                          SET licenca_estado='ativa', licenca_pacote=?, licenca_meses=?,
                              licenca_revogada_em=NULL, licenca_revogada_motivo=NULL
                          WHERE id=?");
    $st->bind_param('sii', $pacote, $meses, $cid);
    $st->execute();

    // Um casamento à espera passa a ativo, com as suas contas; um que já estava
    // de pé fica como está — um reforço não é uma reabertura.
    $contas = 0;
    $r = @$conn->query("SELECT estado FROM {$P}casamentos WHERE id=$cid");
    $estCas = ($r && ($x = $r->fetch_row())) ? (string)$x[0] : '';
    if ($estCas === 'pendente' || $estCas === 'suspenso') {
        @$conn->query("UPDATE {$P}casamentos SET estado='ativo' WHERE id=$cid");
        $contas = retomarContasDoCasamento($conn, $cid);
    }
    iniciarLicenca($conn, $cid);

    registar($conn, 'licenca_aprovar', (string)$ped['casamento_nome'],
             $pacote . ' · ' . count($ped['itens']) . ' módulo(s) · ' . $meses . ' mês(es)'
             . ($contas ? " · $contas conta(s) ativada(s)" : ''));
    ok(['id' => $pid, 'estado' => 'aprovado', 'modulos' => $mudou,
        'contas_ativadas' => $contas, 'licenca' => licencaInfo($conn, $cid)]);
}

if ($acao === 'lic_revogar') {
    // A licença cai por incumprimento das políticas. Fecha-se tudo o que ela
    // abria e diz-se porquê — a razão vai para o casal e fica no registo. Os
    // dados ficam: o casal continua a poder exportá-los, como as políticas
    // prometem (Lei n.º 22/11, artigos 26.º e 28.º).
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma revoga licenças.');
    exigirCsrf();
    $d = corpo();
    $cid = (int)($d['casamento'] ?? 0);
    $motivo = mb_substr(trim((string)($d['motivo'] ?? '')), 0, 1000);
    if ($motivo === '') erro('Indique o motivo da revogação — o casal tem direito a sabê-lo.');
    $st = $conn->prepare("SELECT nome, licenca_estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $cid); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    // Revogar o que já está revogado não tira nada a ninguém: só reescreve por
    // cima do motivo que o casal já leu e apaga a data em que aconteceu. O
    // caminho de volta é conceder a licença de novo.
    if ((string)$c['licenca_estado'] === 'revogada')
        erro('A licença deste casamento já está revogada. Para lhe dar acesso outra vez, '
           . 'conceda-lhe a licença em «Licença: módulos e prazo…».');

    @$conn->query("DELETE FROM {$P}lic_concessoes WHERE casamento_id=" . (int)$cid);
    $st = $conn->prepare("UPDATE {$P}casamentos SET licenca_estado='revogada',
                          licenca_revogada_em=NOW(), licenca_revogada_motivo=? WHERE id=?");
    $st->bind_param('si', $motivo, $cid);
    if (!$st->execute()) erro('Não foi possível revogar a licença.');
    registar($conn, 'licenca_revogar', (string)$c['nome'], $motivo);
    ok(['casamento' => $cid, 'estado' => 'revogada']);
}

if ($acao === 'lic_conceder') {
    // A administração dá (ou tira) módulos a um casamento sem passar por pedido
    // nenhum — é como se abre a porta a um casamento criado aqui dentro, e como
    // se corrige um engano.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma concede módulos.');
    exigirCsrf();
    $d = corpo();
    $cid = (int)($d['casamento'] ?? 0);
    if ($cid <= 0) erro('Indique o casamento.');
    $st = $conn->prepare("SELECT nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $cid); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');

    $escIds = [];
    foreach ((array)($d['escaloes'] ?? []) as $e) { $e = (int)$e; if ($e > 0) $escIds[] = $e; }
    @$conn->query("DELETE FROM {$P}lic_concessoes WHERE casamento_id=" . (int)$cid);
    $n = 0;
    if ($escIds) {
        $lista = implode(',', array_map('intval', array_unique($escIds)));
        $r = @$conn->query("SELECT e.id, e.nome, e.limite, e.editar, e.todos_modelos, m.chave modulo
                            FROM {$P}lic_escaloes e JOIN {$P}lic_modulos m ON m.id = e.modulo_id
                            WHERE e.id IN ($lista) ORDER BY m.ordem, e.ordem");
        $vistos = [];
        if ($r) while ($x = $r->fetch_assoc()) {
            $mc = (string)$x['modulo'];
            if (isset($vistos[$mc])) continue;
            $vistos[$mc] = true;
            $st = $conn->prepare("INSERT INTO {$P}lic_concessoes
                (casamento_id, modulo_chave, escalao_id, escalao_nome, limite, editar, todos_modelos, desde)
                VALUES (?,?,?,?,?,?,?,NOW())");
            if (!$st) continue;
            $eid = (int)$x['id']; $en = (string)$x['nome']; $lim = (int)$x['limite'];
            $ed = (int)$x['editar']; $tm = (int)$x['todos_modelos'];
            $st->bind_param('isisiii', $cid, $mc, $eid, $en, $lim, $ed, $tm);
            if (@$st->execute()) $n++;
        }
    }
    // Dar mesas sem lista de convidados é entregar uma casa sem chão: as mesas
    // sentam quem? Tirar TUDO continua a ser legítimo (é como se fecha uma
    // licença); o que se recusa é o meio-termo que não funciona.
    if ($n > 0) {
        $atualG = licencaModulos($conn, $cid);
        foreach (licObrigatorios($conn) as $ob) {
            if (!empty($atualG[$ob]['ativo'])) continue;
            $rn = @$conn->query("SELECT nome FROM {$P}lic_modulos WHERE chave='"
                                . $conn->real_escape_string($ob) . "' LIMIT 1");
            $nm = ($rn && ($x = $rn->fetch_row())) ? (string)$x[0] : $ob;
            erro("Um casamento com módulos tem de ter «{$nm}» — é a base de que o resto "
               . 'depende. Escolha um escalão desse módulo, ou tire-lhe todos os módulos.');
        }
    }

    $meses = max(0, min(120, (int)($d['meses'] ?? 0)));
    $novoEstado = $n > 0 ? 'ativa' : 'sem';
    $rotulo = $n > 0 ? 'Concedido pela administração' : '';
    // Reiniciar o relógio é uma decisão à parte de mudar o número de meses:
    // corrigir um prazo mal escrito não pode dar tempo novo por acidente.
    $reiniciar = !empty($d['reiniciar']);
    $st = $conn->prepare("UPDATE {$P}casamentos SET licenca_estado=?, licenca_pacote=?,
                          licenca_meses=?" . ($reiniciar ? ", licenca_ate=NULL" : "") . ",
                          licenca_revogada_em=NULL, licenca_revogada_motivo=NULL
                          WHERE id=?");
    $st->bind_param('ssii', $novoEstado, $rotulo, $meses, $cid);
    @$st->execute();
    $contas = 0;
    if ($n > 0) {
        iniciarLicenca($conn, $cid);
        // Dar licença a um registo que ainda esperava é abrir-lhe a casa: a
        // aprovação de um casamento é a decisão da sua licença, e esta é a outra
        // forma de a tomar (a administração concede à mão, sem pedido). Sem
        // isto, um casal a quem se desse licença ficava na mesma à porta.
        $rr = @$conn->query("SELECT estado FROM {$P}casamentos WHERE id=$cid");
        if ($rr && ($xx = $rr->fetch_row()) && (string)$xx[0] === 'pendente') {
            @$conn->query("UPDATE {$P}casamentos SET estado='ativo' WHERE id=$cid");
            $contas = retomarContasDoCasamento($conn, $cid);
        }
    }
    registar($conn, 'licenca_conceder', (string)$c['nome'],
             "$n módulo(s) · " . ($meses ? "$meses mês(es)" : 'sem limite')
             . ($reiniciar ? ' · relógio a contar de hoje' : '')
             . ($contas ? " · casamento aberto, $contas conta(s) ativada(s)" : ''));
    ok(['casamento' => $cid, 'modulos' => $n, 'estado' => $novoEstado,
        'contas_ativadas' => $contas, 'licenca' => licencaInfo($conn, $cid)]);
}

// ---- o preçário, do lado de quem o define ----
if ($acao === 'lic_modulo_guardar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita o preçário.');
    exigirCsrf();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) erro('Módulo não encontrado.');
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('O módulo precisa de um nome.');
    $resumo = mb_substr(trim((string)($d['resumo'] ?? '')), 0, 180);
    $benef  = mb_substr(trim((string)($d['beneficio'] ?? '')), 0, 180);
    $icone  = mb_substr(trim((string)($d['icone'] ?? '')), 0, 8);
    $ativo  = !empty($d['ativo']) ? 1 : 0;
    $st = $conn->prepare("UPDATE {$P}lic_modulos SET nome=?, resumo=?, beneficio=?, icone=?, ativo=? WHERE id=?");
    $st->bind_param('ssssii', $nome, $resumo, $benef, $icone, $ativo, $id);
    if (!$st->execute()) erro('Não foi possível guardar o módulo.');
    registar($conn, 'lic_modulo_guardar', $nome);
    ok(['catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_escalao_guardar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita o preçário.');
    exigirCsrf();
    $d = corpo();
    $id  = (int)($d['id'] ?? 0);
    $mid = (int)($d['modulo'] ?? 0);
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('O escalão precisa de um nome.');
    $resumo = mb_substr(trim((string)($d['resumo'] ?? '')), 0, 180);
    $preco  = max(0, min(999999999, (float)($d['preco'] ?? 0)));
    $limite = max(0, min(100000, (int)($d['limite'] ?? 0)));
    $editar = !empty($d['editar']) ? 1 : 0;
    $todos  = !empty($d['todos_modelos']) ? 1 : 0;
    $ordem  = max(0, min(9999, (int)($d['ordem'] ?? 0)));
    $ativo  = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $conn->prepare("UPDATE {$P}lic_escaloes SET nome=?, resumo=?, preco=?, limite=?,
                              editar=?, todos_modelos=?, ordem=?, ativo=? WHERE id=?");
        $st->bind_param('ssdiiiiii', $nome, $resumo, $preco, $limite, $editar, $todos, $ordem, $ativo, $id);
        if (!$st->execute()) erro('Não foi possível guardar o escalão.');
    } else {
        $r = @$conn->query("SELECT chave FROM {$P}lic_modulos WHERE id=$mid");
        if (!$r || !$r->num_rows) erro('Módulo não encontrado.');
        $chave = (string)$r->fetch_row()[0] . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $st = $conn->prepare("INSERT INTO {$P}lic_escaloes
            (modulo_id, chave, nome, resumo, preco, limite, editar, todos_modelos, ordem, ativo)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('isssdiiiii', $mid, $chave, $nome, $resumo, $preco, $limite,
                        $editar, $todos, $ordem, $ativo);
        if (!$st->execute()) erro('Não foi possível criar o escalão.');
        $id = $conn->insert_id;
    }
    registar($conn, 'lic_escalao_guardar', $nome, number_format($preco, 2, ',', ' ') . ' Kz');
    ok(['id' => $id, 'catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_escalao_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita o preçário.');
    exigirCsrf();
    $id = (int)(corpo()['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}lic_escaloes WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $e = $st->get_result()->fetch_assoc();
    if (!$e) erro('Escalão não encontrado.');
    // Um escalão que já foi concedido não desaparece: desliga-se. Apagá-lo era
    // deixar sem chão as licenças que assentam nele.
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}lic_concessoes WHERE escalao_id=$id AND casamento_id>0");
    $usos = $r ? (int)$r->fetch_row()[0] : 0;
    if ($usos > 0) {
        @$conn->query("UPDATE {$P}lic_escaloes SET ativo=0 WHERE id=$id");
        registar($conn, 'lic_escalao_desligar', (string)$e['nome'], "$usos licença(s) assentam nele");
        ok(['desligado' => true, 'usos' => $usos, 'catalogo' => licCatalogo($conn)]);
    }
    @$conn->query("DELETE FROM {$P}lic_pacote_itens WHERE escalao_id=$id");
    @$conn->query("DELETE FROM {$P}lic_escaloes WHERE id=$id");
    registar($conn, 'lic_escalao_apagar', (string)$e['nome']);
    ok(['catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_pacote_guardar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os pacotes.');
    exigirCsrf();
    $d = corpo();
    $id   = (int)($d['id'] ?? 0);
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('O pacote precisa de um nome.');
    $prom   = mb_substr(trim((string)($d['promessa'] ?? '')), 0, 180);
    $resumo = mb_substr(trim((string)($d['resumo'] ?? '')), 0, 2000);
    $preco  = max(0, min(999999999, (float)($d['preco'] ?? 0)));
    $meses  = max(1, min(120, (int)($d['meses'] ?? 12)));
    $etiq   = mb_substr(trim((string)($d['etiqueta'] ?? '')), 0, 40);
    $dest   = !empty($d['destaque']) ? 1 : 0;
    $ordem  = max(0, min(9999, (int)($d['ordem'] ?? 0)));
    $ativo  = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $conn->prepare("UPDATE {$P}lic_pacotes SET nome=?, promessa=?, resumo=?, preco=?,
                              meses=?, etiqueta=?, destaque=?, ordem=?, ativo=? WHERE id=?");
        $st->bind_param('sssdisiiii', $nome, $prom, $resumo, $preco, $meses, $etiq, $dest, $ordem, $ativo, $id);
        if (!$st->execute()) erro('Não foi possível guardar o pacote.');
    } else {
        $chave = 'pacote_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $st = $conn->prepare("INSERT INTO {$P}lic_pacotes
            (chave, nome, promessa, resumo, preco, meses, etiqueta, destaque, ordem, ativo)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('ssssdisiii', $chave, $nome, $prom, $resumo, $preco, $meses, $etiq, $dest, $ordem, $ativo);
        if (!$st->execute()) erro('Não foi possível criar o pacote.');
        $id = $conn->insert_id;
    }
    // Só um pacote pode estar em destaque: dois «mais escolhidos» não escolhem nada.
    if ($dest) @$conn->query("UPDATE {$P}lic_pacotes SET destaque=0 WHERE id <> $id");

    if (array_key_exists('escaloes', $d)) {
        @$conn->query("DELETE FROM {$P}lic_pacote_itens WHERE pacote_id=$id");
        $vistos = [];
        foreach ((array)$d['escaloes'] as $e) {
            $e = (int)$e;
            if ($e <= 0 || isset($vistos[$e])) continue;
            $vistos[$e] = true;
            @$conn->query("INSERT IGNORE INTO {$P}lic_pacote_itens (pacote_id, escalao_id) VALUES ($id, $e)");
        }
    }

    // Um pacote sem os módulos obrigatórios é um pacote que ninguém pode
    // comprar: o pedido seria recusado à chegada. Avisa-se aqui, onde ainda se
    // pode corrigir, em vez de deixar o casal descobrir no fim.
    $faltam = [];
    if (array_key_exists('escaloes', $d)) {
        $temMod = [];
        $r = @$conn->query("SELECT m.chave FROM {$P}lic_pacote_itens pi
                            JOIN {$P}lic_escaloes e ON e.id = pi.escalao_id
                            JOIN {$P}lic_modulos  m ON m.id = e.modulo_id
                            WHERE pi.pacote_id = $id");
        if ($r) while ($x = $r->fetch_row()) $temMod[(string)$x[0]] = true;
        foreach (licObrigatorios($conn) as $ob) {
            if (isset($temMod[$ob])) continue;
            $rn = @$conn->query("SELECT nome FROM {$P}lic_modulos WHERE chave='"
                                . $conn->real_escape_string($ob) . "' LIMIT 1");
            $faltam[] = ($rn && ($x = $rn->fetch_row())) ? (string)$x[0] : $ob;
        }
    }

    // Os preços dos módulos, mexidos aqui mesmo.
    //
    // Montar um pacote é o momento em que se olha para os preços à peça — é
    // deles que sai a poupança que o pacote anuncia. Obrigar a sair daqui,
    // ir ao preçário, corrigir um número e voltar era partir em três um gesto
    // que é um só.
    $precos = 0;
    if (!empty($d['precos']) && is_array($d['precos'])) {
        $st = $conn->prepare("UPDATE {$P}lic_escaloes SET preco=? WHERE id=?");
        if ($st) foreach ($d['precos'] as $eid => $pv) {
            $eid = (int)$eid;
            $pv  = max(0, min(999999999, (float)$pv));
            if ($eid <= 0) continue;
            $st->bind_param('di', $pv, $eid);
            if (@$st->execute() && $conn->affected_rows > 0) $precos++;
        }
    }

    registar($conn, 'lic_pacote_guardar', $nome, number_format($preco, 2, ',', ' ') . ' Kz'
             . ($precos ? " · $precos preço(s) de módulo alterado(s)" : '')
             . ($faltam ? ' · SEM ' . implode(', ', $faltam) : ''));
    ok(['id' => $id, 'precos_mudados' => $precos, 'faltam' => $faltam,
        'catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_pacote_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os pacotes.');
    exigirCsrf();
    $id = (int)(corpo()['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}lic_pacotes WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $p = $st->get_result()->fetch_assoc();
    if (!$p) erro('Pacote não encontrado.');
    @$conn->query("DELETE FROM {$P}lic_pacote_itens WHERE pacote_id=$id");
    @$conn->query("DELETE FROM {$P}lic_pacotes WHERE id=$id");
    registar($conn, 'lic_pacote_apagar', (string)$p['nome']);
    ok(['catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_prazo_guardar') {
    // Os prazos e os seus factores. O factor é o que multiplica os preços do
    // preçário — que são, por definição, os do prazo de factor 1.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os prazos.');
    exigirCsrf();
    $d = corpo();
    $id    = (int)($d['id'] ?? 0);
    $meses = max(1, min(120, (int)($d['meses'] ?? 0)));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 60);
    if ($nome === '') $nome = $meses . ' meses';
    $resumo = mb_substr(trim((string)($d['resumo'] ?? '')), 0, 160);
    $fator  = max(0.001, min(999, (float)($d['fator'] ?? 1)));
    $etiq   = mb_substr(trim((string)($d['etiqueta'] ?? '')), 0, 40);
    $ordem  = max(0, min(9999, (int)($d['ordem'] ?? 0)));
    $ativo  = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $conn->prepare("UPDATE {$P}lic_prazos SET meses=?, nome=?, resumo=?, fator=?,
                              etiqueta=?, ordem=?, ativo=? WHERE id=?");
        $st->bind_param('issdsiii', $meses, $nome, $resumo, $fator, $etiq, $ordem, $ativo, $id);
        if (!$st->execute()) erro('Já existe um prazo com esse número de meses.');
    } else {
        $st = $conn->prepare("INSERT INTO {$P}lic_prazos (meses,nome,resumo,fator,etiqueta,ordem,ativo)
                              VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('issdsii', $meses, $nome, $resumo, $fator, $etiq, $ordem, $ativo);
        if (!$st->execute()) erro('Já existe um prazo com esse número de meses.');
        $id = $conn->insert_id;
    }
    registar($conn, 'lic_prazo_guardar', $nome, "$meses meses · factor $fator");
    ok(['id' => $id, 'catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_prazo_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os prazos.');
    exigirCsrf();
    $id = (int)(corpo()['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}lic_prazos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $p = $st->get_result()->fetch_assoc();
    if (!$p) erro('Prazo não encontrado.');
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}lic_prazos WHERE ativo=1");
    if ($r && (int)$r->fetch_row()[0] <= 1)
        erro('Tem de ficar pelo menos um prazo: é ele que dá o preço.');
    @$conn->query("DELETE FROM {$P}lic_prazos WHERE id=$id");
    registar($conn, 'lic_prazo_apagar', (string)$p['nome']);
    ok(['catalogo' => licCatalogo($conn)]);
}

if ($acao === 'lic_politica_guardar') {
    // Editar as políticas publica uma versão NOVA. A anterior fica: é a prova
    // do texto a que cada casal disse que sim, e essa não se reescreve.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita as políticas.');
    exigirCsrf();
    $d = corpo();
    $titulo = mb_substr(trim((string)($d['titulo'] ?? '')), 0, 160);
    $corpoT = trim((string)($d['corpo'] ?? ''));
    if ($titulo === '') erro('As políticas precisam de um título.');
    if (mb_strlen($corpoT) < 200) erro('O texto das políticas está demasiado curto.');
    $r = @$conn->query("SELECT MAX(versao) FROM {$P}lic_politicas");
    $nova = ($r ? (int)$r->fetch_row()[0] : 0) + 1;
    $st = $conn->prepare("INSERT INTO {$P}lic_politicas (versao, titulo, corpo, publicada)
                          VALUES (?,?,?,1)");
    $st->bind_param('iss', $nova, $titulo, $corpoT);
    if (!$st->execute()) erro('Não foi possível publicar as políticas.');
    registar($conn, 'lic_politica_guardar', $titulo, "versão $nova");
    ok(['politica' => licPolitica($conn)]);
}

// ============================================================
// A partir daqui: exige login
// ============================================================
// Autenticado, mas ainda sem casa: o suporte antes de lhe darem um código, ou
// um registo aprovado a que falte o casamento. Estas ações são as que se pode
// fazer nesse estado.
if (in_array($acao, ['suporte_entrar','senha_mudar'], true)) {
    if (!utilizadorId()) { http_response_code(401); erro('Sessão terminada. Entre de novo.'); }
    exigirCsrf();

    if ($acao === 'suporte_entrar') {
        // O código é do casal para o suporte. Não é uma segunda porta de
        // entrada: quem o usa já se autenticou, e tem de ser da casa.
        if (!ehPessoalPlataforma()) erro('Só o pessoal da plataforma usa códigos de suporte.');
        $cod = mb_strtoupper(trim((string)(corpo()['codigo'] ?? '')));
        if ($cod === '') erro('Indique o código.');
        $st = $conn->prepare("SELECT id, casamento_id, pode_corrigir, expira_em, revogado_em
                              FROM {$P}suporte_codigos WHERE codigo=? LIMIT 1");
        $st->bind_param('s', $cod); $st->execute();
        $s = $st->get_result()->fetch_assoc();
        // A mesma resposta para código errado, revogado ou expirado: quem
        // tentar adivinhar não fica a saber qual dos três acertou.
        $mau = 'Código inválido, revogado ou expirado.';
        if (!$s) erro($mau);
        if ($s['revogado_em'] !== null) erro($mau);
        if ($s['expira_em'] !== null && strtotime($s['expira_em']) < time()) erro($mau);

        $cid = (int)$s['casamento_id'];
        $q = $conn->prepare("SELECT nome, estado FROM {$P}casamentos WHERE id=?");
        $q->bind_param('i', $cid); $q->execute();
        $cas = $q->get_result()->fetch_assoc();
        if (!$cas || $cas['estado'] === 'arquivado') erro($mau);

        $acessos = suporteAcessos();
        $acessos[$cid] = ['corrigir' => (int)$s['pode_corrigir'], 'codigo' => (int)$s['id']];
        $_SESSION['suporte_acessos'] = $acessos;

        $uid = utilizadorId();
        $st = $conn->prepare("UPDATE {$P}suporte_codigos SET usado_por=?, usado_em=NOW() WHERE id=?");
        $st->bind_param('ii', $uid, $s['id']); @$st->execute();

        abrirCasamento($conn, $cid);
        registar($conn, 'suporte_entrou', $cas['nome'],
                 (int)$s['pode_corrigir'] ? 'pode corrigir' : 'só ver');
        ok(['casamento' => $cid, 'nome' => $cas['nome'], 'pode_corrigir' => (int)$s['pode_corrigir']]);
    }

    if ($acao === 'senha_mudar') {
        $d = corpo();
        $velha = (string)($d['atual'] ?? '');
        $nova  = (string)($d['nova'] ?? '');
        if (mb_strlen($nova) < 8) erro('A nova senha precisa de pelo menos 8 caracteres.');
        $uid = utilizadorId();
        $r = $conn->query("SELECT senha_hash FROM {$P}utilizadores WHERE id=$uid LIMIT 1");
        $u = $r ? $r->fetch_assoc() : null;
        if (!$u || !password_verify($velha, (string)$u['senha_hash'])) erro('A senha atual não confere.');
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $st = $conn->prepare("UPDATE {$P}utilizadores SET senha_hash=? WHERE id=?");
        $st->bind_param('si', $hash, $uid);
        if (!$st->execute()) erro('Não foi possível mudar a senha.');
        registar($conn, 'senha_mudada', utilizadorAtual() ?? '');
        ok();
    }
}

// ---- Porteiro (admin ou porteiro) --------------------------
if (in_array($acao, ['porta_buscar','porta_checkin','porta_stats','porta_entradas','porta_dados'], true)) {
    exigirPortaApi();
    // O segundo trinco: esconder o posto da porta no menu não impede ninguém de
    // chamar a ação à mão. A lista faz falta na mesma — é sobre ela que se lê.
    exigirModuloApi('convidados');
    exigirModuloApi('porta');
    if ($acao === 'porta_checkin') { exigirCsrf(); exigirCorrecao(); }  // altera dados

    if ($acao === 'porta_stats') {
        $s = estatisticas($conn);
        ok(['presentes' => $s['presentes'], 'lug_confirm' => $s['lug_confirm'],
            'no_local' => $s['no_local'], 'convites' => $s['convites']]);
    }

    if ($acao === 'porta_dados') {
        // Cópia completa e leve da lista, para o porteiro poder procurar
        // SEM ligação à internet (guardada no dispositivo). Só o essencial.
        $r = $conn->query("SELECT c.id, c.codigo, c.nome_exibicao, c.sufixo, c.lugares,
                                  c.rsvp_estado, c.rsvp_confirmados, c.checkin_estado, c.checkin_presentes,
                                  c.observacoes, m.nome AS mesa_nome
                           FROM {$P}convites c
                           LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                           WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
                           ORDER BY c.nome_exibicao");
        $convites = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $porId = [];
        foreach ($convites as &$c) {
            $c['nome_final'] = nomeConvite($c);
            $c['membros'] = [];
            $porId[(int)$c['id']] = &$c;
        }
        unset($c);
        $rg = $conn->query("SELECT id, convite_id, nome, rsvp, presente FROM {$P}convidados
                            WHERE " . doCasamento() . " ORDER BY principal DESC, nome");
        if ($rg) while ($g = $rg->fetch_assoc()) {
            $cid = (int)$g['convite_id'];
            if (isset($porId[$cid])) $porId[$cid]['membros'][] = $g;
        }
        ok(['convites' => $convites, 'gerado_em' => date('c')]);
    }

    if ($acao === 'porta_entradas') {
        $r = $conn->query("SELECT id FROM {$P}convites
                           WHERE " . doCasamento() . " AND checkin_estado IN ('presente','parcial') AND ".soVivos($conn,'')."
                           ORDER BY checkin_em DESC, atualizado_em DESC");
        $ids = array_column($r->fetch_all(MYSQLI_ASSOC), 'id');
        $lista = array_map(fn($id) => carregarConvite($conn, (int)$id), $ids);
        $s = estatisticas($conn);
        ok(['entradas' => $lista, 'presentes' => $s['presentes'], 'no_local' => $s['no_local']]);
    }

    if ($acao === 'porta_buscar') {
        $termo = trim($_GET['q'] ?? '');
        if ($termo === '') erro('Indique um código ou nome.');
        // extrai código de um URL, se o QR trouxer o link completo
        if (preg_match('/[?&]c=([A-Z0-9]+)/i', $termo, $m)) $termo = $m[1];

        // 1) tenta por código exato, DENTRO do casamento aberto — o porteiro de
        // um casamento não pode ler o convite de outro por lhe passar um QR
        // alheio pela câmara.
        $c = carregarConvite($conn, strtoupper($termo), 'codigo_local');
        if ($c) ok(['convite' => $c]);

        // 2) procura por nome do convite ou de um membro
        $like = "%$termo%";
        $st = $conn->prepare("SELECT DISTINCT c.id FROM {$P}convites c
                              LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                              WHERE " . doCasamento('c') . " AND (c.nome_exibicao LIKE ? OR g.nome LIKE ?) AND ".soVivos($conn,'c')."
                              ORDER BY c.nome_exibicao LIMIT 12");
        $st->bind_param('ss', $like, $like); $st->execute();
        $ids = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        if (!$ids) erro('Nenhum convite corresponde a "'.htmlspecialchars($termo).'".');
        if (count($ids) === 1) ok(['convite' => carregarConvite($conn, (int)$ids[0])]);
        $lista = array_map(fn($id) => carregarConvite($conn, (int)$id), $ids);
        ok(['varios' => $lista]);
    }

    if ($acao === 'porta_checkin') {
        $d = corpo();
        $id   = (int)($d['convite_id'] ?? 0);
        $modo = $d['modo'] ?? 'todos';   // 'todos' | 'membro' | 'anular'
        $mid  = (int)($d['membro_id'] ?? 0);
        $excecao = !empty($d['excecao']); // autorização manual do porteiro
        $c = carregarConvite($conn, $id);
        if (!$c) erro('Convite inválido.');

        if ($modo === 'membro' && $mid) {
            $q = $conn->prepare("SELECT presente, rsvp, nome FROM {$P}convidados
                                 WHERE " . doCasamento() . " AND id=? AND convite_id=?");
            $q->bind_param('ii', $mid, $id); $q->execute();
            $cur = $q->get_result()->fetch_assoc();
            if (!$cur) erro('Pessoa não encontrada neste convite.');
            $jaPresente = (int)$cur['presente'] === 1;
            if (!$jaPresente && $cur['rsvp'] !== 'confirmado' && !$excecao) {
                erro(($cur['nome'] ?: 'Esta pessoa') . ' não confirmou presença e não pode dar entrada.');
            }
            $novo = $jaPresente ? 0 : 1;
            $q = $conn->prepare("UPDATE {$P}convidados SET presente=?, presente_em=".($novo?$TS:'NULL')." WHERE " . doCasamento() . " AND id=? AND convite_id=?");
            $q->bind_param('iii', $novo, $mid, $id); $q->execute();
            recalcularCheckin($conn, $id, $TS);
        } elseif ($modo === 'anular') {
            $conn->query("UPDATE {$P}convidados SET presente=0, presente_em=NULL WHERE " . doCasamento() . " AND convite_id=$id");
            $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE " . doCasamento() . " AND id=$id");
        } else { // 'todos'
            if (count($c['membros']) > 0) {
                if ($excecao) {
                    // Entrada excecional autorizada: admite todas as pessoas do convite
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE " . doCasamento() . " AND convite_id=$id");
                } else {
                    $r = $conn->query("SELECT COUNT(*) n FROM {$P}convidados
                                       WHERE " . doCasamento() . " AND convite_id=$id AND rsvp='confirmado'");
                    $nconf = (int)$r->fetch_assoc()['n'];
                    if ($nconf === 0) erro('Ninguém neste convite confirmou presença. Não é possível dar entrada.');
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE " . doCasamento() . " AND convite_id=$id AND rsvp='confirmado'");
                }
                recalcularCheckin($conn, $id, $TS);
            } else {
                if (!$excecao && !in_array($c['rsvp_estado'], ['confirmado','parcial'], true)) {
                    erro('Este convite não confirmou presença. Não é possível dar entrada.');
                }
                $n = (int)($c['rsvp_confirmados'] ?: $c['lugares']);
                $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado='presente', checkin_presentes=?, checkin_em=$TS WHERE " . doCasamento() . " AND id=?");
                $st->bind_param('ii', $n, $id); $st->execute();
            }
        }
        $qual = ['membro'=>'entrada de 1 pessoa', 'anular'=>'entrada anulada'][$modo] ?? 'entrada do convite';
        registar($conn, 'checkin', $c['nome_final'] ?? '', $qual . ($excecao ? ' (excecional)' : ''));
        ok(['convite' => carregarConvite($conn, $id)]);
    }
}

// ============================================================
// O BAR — o menu de bebidas que os convidados pedem da mesa
//
// O desenho inteiro está em docs/modulo-bar.md. Em resumo: em cada mesa há um
// QR; o convidado abre-o, escolhe-se numa lista de nomes, e pede. O pedido cai
// na copa, que aprova ou recusa; aprovado, aparece ao entregador, que o leva.
//
// Duas contas por bebida, e é o que evita vender a última garrafa duas vezes:
//   stock      — o que existe. Só desce na ENTREGA.
//   reservado  — o que está prometido a pedidos já aprovados.
//   disponível — a diferença, e é isso que se oferece.
//
// As ações públicas (do convidado) não têm sessão: o token da mesa diz de que
// casamento se trata, tal como o código do convite o diz em convite.php. A
// identidade da pessoa vive num testemunho no telemóvel — um telemóvel, uma
// pessoa.
// ============================================================

/** Exige o módulo e um casamento aberto. Devolve o id do casamento. */
function barCid(): int {
    exigirModuloApi('bar');
    $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    return $cid;
}

/** A porta pública: sem token válido não se entra, e sem módulo também não. */
function barPortaPublica(mysqli $conn): array {
    $token = strtoupper(trim((string)($_GET['m'] ?? (corpo()['m'] ?? ''))));
    $mesa = barMesaDoToken($conn, $token);
    if (!$mesa) erro('Este código de mesa não serve. Chame um garçom.');
    if (!podeModulo('bar')) erro('Este casamento não serve bebidas por aqui.');
    return $mesa;
}

// ---- o telemóvel, e a pessoa a que ele pertence -------------

/** O testemunho que vive no telemóvel. Novo, se ainda não houver. */
function barTestemunho(): string {
    $t = (string)($_COOKIE['bar_disp'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $t)) return $t;
    $t = bin2hex(random_bytes(16));
    // Até ao fim do evento; a festa não dura mais do que isto.
    setcookie('bar_disp', $t, ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/',
                               'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['bar_disp'] = $t;
    return $t;
}

/** Quem é este telemóvel, neste casamento? 0 = ainda ninguém. */
function barQuemSou(mysqli $conn): int {
    global $P;
    $t = (string)($_COOKIE['bar_disp'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) return 0;
    $h = hash('sha256', $t);
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT convidado_id FROM {$P}bar_dispositivos
                          WHERE casamento_id=? AND token_hash=? AND bloqueado=0 LIMIT 1");
    if (!$st) return 0;
    $st->bind_param('is', $cid, $h);
    if (!$st->execute()) return 0;
    $r = $st->get_result()->fetch_assoc();
    return $r ? (int)$r['convidado_id'] : 0;
}

/** Prende este telemóvel a uma pessoa. */
function barPrender(mysqli $conn, int $convidadoId, int $conviteId): void {
    global $P;
    $cid = casamentoAtual();
    $h = hash('sha256', barTestemunho());
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $st = $conn->prepare("INSERT INTO {$P}bar_dispositivos
            (casamento_id, token_hash, convidado_id, convite_id, primeiro_ip, ultimo_ip, criado_em, ultimo_em)
            VALUES (?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
              -- A CONTA DAS TROCAS VEM PRIMEIRO, e não é estilo: as atribuições
              -- de um ON DUPLICATE correm da esquerda para a direita, e com
              -- convidado_id já reescrito a comparação dava sempre falso — o
              -- contador ficava eternamente a zero e a copa nunca via bandeira
              -- nenhuma. Compara-se enquanto o valor antigo ainda lá está.
              trocas=trocas + (convidado_id <> VALUES(convidado_id)),
              convidado_id=VALUES(convidado_id), convite_id=VALUES(convite_id),
              ultimo_ip=VALUES(ultimo_ip), ultimo_em=NOW()");
    if ($st) { $st->bind_param('isiiss', $cid, $h, $convidadoId, $conviteId, $ip, $ip); @$st->execute(); }
}

/**
 * Por quem é este pedido — por mim, ou por outra pessoa que mo pediu?
 *
 * Numa mesa há sempre quem não tenha telemóvel à mão, quem o tenha sem bateria
 * e quem não queira lidar com aquilo: pede ao vizinho. Isso já era possível,
 * mas só pela porta errada — trocando o nome do telemóvel (§5.3) —, e essa
 * troca REBINDA o aparelho: o pedido passava a dizer que quem pediu foi a
 * outra pessoa, e quem pediu de facto desaparecia do registo. Pedir «por» é a
 * porta certa, e deixa MELHOR rasto do que a que substitui: o pedido guarda os
 * dois nomes, e o meu telemóvel continua meu.
 *
 * O que não muda: **a quota é de quem bebe**. Os limites contam-se contra a
 * pessoa nomeada, e por isso pedir por outro não é maneira de contornar um
 * tecto — é maneira de gastar o dela.
 *
 * As barreiras são as que já existiam, e nenhuma nova:
 *   • dentro do MESMO convite é livre e não se comenta, como já era: a família
 *     é a unidade doméstica em todo este módulo;
 *   • para OUTRO convite obedece a `bar.trocar_nome`, o mesmo interruptor que
 *     governa a troca de nome, porque é a mesma pergunta — este telemóvel pode
 *     agir por outra família?
 *   • e, com o PIN ligado, pedir por outro convite exige o código DESSE
 *     convite. Sem isto o PIN não valia nada: bastava não trocar de nome e
 *     pedir «pelo padrinho» para o contornar por inteiro.
 *
 * Devolve [id, convidado, porOutro].
 */
function barParaQuem(mysqli $conn, int $eu, array $g, array $d): array {
    $porId = (int)($d['por_id'] ?? 0);
    if ($porId <= 0 || $porId === $eu) return [$eu, $g, false];

    $alvo = barConvidado($conn, $porId);
    if (!$alvo) erro('Não encontrámos essa pessoa na lista.');
    if ((int)$alvo['convite_id'] !== (int)$g['convite_id']) {
        if (barDef($conn, 'bar.trocar_nome') !== '1') {
            erro('Neste casamento só se pede pelas pessoas do seu convite. '
               . 'Chame um garçom — ele pede por ' . $alvo['nome'] . '.');
        }
    }
    return [$porId, $alvo, true];
}

/* `barIpDeOutrem()` viveu aqui: procurava outro nome a pedir do mesmo endereço,
   para o modo «estrito» recusar o segundo. Saiu na quarta passagem, com o resto
   da conversa do wi-fi partilhado — os convidados pedem pela rede dos próprios
   telemóveis, e um endereço deixou de dizer alguma coisa sobre quem está a
   pedir. O que restava era um travão que nunca acertava: com NAT trancava a
   festa ao primeiro que pedisse; sem NAT não impedia nada. */

/**
 * Os telemóveis que a copa deve olhar duas vezes.
 *
 * Uma bandeira, e não acusa ninguém: um telemóvel que já trocou de nome entre
 * convites. A copa conhece a sala e decide; o sistema limita-se a apontar.
 */
function barBandeiras(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $out = [];

    $r = @$conn->query("SELECT d.trocas, d.ultimo_ip, g.nome
                        FROM {$P}bar_dispositivos d
                        JOIN {$P}convidados g ON g.id = d.convidado_id AND g.casamento_id = d.casamento_id
                        WHERE d.casamento_id=$cid AND d.bloqueado=0 AND d.trocas > 0
                        ORDER BY d.trocas DESC LIMIT 20");
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['tipo' => 'trocas', 'nome' => $x['nome'], 'n' => (int)$x['trocas'],
                  'texto' => 'este telemóvel já pediu por ' . ((int)$x['trocas'] + 1) . ' pessoas'];
    }

    // A segunda bandeira — «N nomes da mesma ligação» — saiu com a lógica do
    // wi-fi partilhado: numa festa em que cada um usa os seus dados, dois nomes
    // do mesmo endereço não querem dizer nada um sobre o outro.
    return $out;
}

/** A pessoa, com o seu convite e a sua mesa. Null se não for deste casamento. */
function barConvidado(mysqli $conn, int $id): ?array {
    global $P;
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT g.id, g.nome, g.convite_id, c.nome_exibicao, c.mesa_id
                          FROM {$P}convidados g
                          JOIN {$P}convites c ON c.id = g.convite_id AND c.casamento_id = g.casamento_id
                          WHERE g.casamento_id=? AND g.id=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('ii', $cid, $id);
    if (!$st->execute()) return null;
    return $st->get_result()->fetch_assoc() ?: null;
}

// ============================================================
// OS LIMITES — quanto, de quem, e de quanto em quanto tempo
//
// Uma tabela só (cw_bar_limites) com quatro eixos: o QUÊ (um item, uma
// categoria, ou tudo), de QUEM (uma pessoa, uma família, ou toda a gente),
// QUANTO, e EM QUANTO TEMPO. Ver docs/modulo-bar.md §8.
//
// A gramática é de três formas, e é pequena de propósito:
//   quantidade 0            → proibido
//   quantidade N, janela 0  → N ao todo, a noite inteira
//   quantidade N, janela M  → N a cada M minutos
//
// E a regra que dá sentido a tudo: o limite mais específico SUBSTITUI o mais
// geral, não se soma a ele. Somar seria o contrário do que quem escreve a
// regra está a tentar fazer — dar um tecto a alguém passaria a aumentar-lhe a
// quota.
// ============================================================

/**
 * Os limites em vigor deste casamento — os que valem AGORA, seja qual for o
 * modo deles.
 *
 * Uma regra pode ter hora de entrada e hora de saída, e as duas juntas fazem
 * uma janela: «nada de destilados antes das 21h» é a regra que sai às 21h, e
 * «a partir das 2h, uma bebida por hora» é a que entra às 2h. Fora da sua
 * janela a regra não é levantada — está lá, escrita, à espera da hora; o que
 * ela não faz é contar.
 *
 * É contra esta lista que o motor de sugestões mede (§31.2). Quem quer saber
 * o que TRAVA um pedido usa `barLimites()`, que é outra pergunta.
 */
function barLimitesVivos(mysqli $conn): array {
    global $P;
    // Lê-se uma vez por pedido: o veredicto de um menu de vinte bebidas
    // consulta-os vinte vezes, e são sempre os mesmos.
    if (isset($GLOBALS['__bar_limites_vivos'])) return $GLOBALS['__bar_limites_vivos'];
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT * FROM {$P}bar_limites
                        WHERE casamento_id=$cid AND ativo=1
                          AND (vigora_em IS NULL OR vigora_em <= NOW())
                          AND (expira_em IS NULL OR expira_em > NOW())
                        ORDER BY id");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        foreach (['id','alvo_id','quantidade','janela_min','alvo_convidado_id','alvo_convite_id'] as $k) {
            $x[$k] = $x[$k] === null ? null : (int)$x[$k];
        }
        // Uma regra escrita antes do v40 não tem modo na memória de quem a leu
        // de um ficheiro antigo. Vale 'trava', que é o que ela fazia.
        $x['modo'] = $x['modo'] ?? 'trava';
        $out[] = $x;
    }
    return $GLOBALS['__bar_limites_vivos'] = $out;
}

/**
 * As regras que TRAVAM um pedido agora.
 *
 * São as que valem agora E estão em modo `trava`. As outras três — `sugere`,
 * `confirma`, `avisa` — não recusam nada a ninguém: tocam a campainha ao
 * copeiro e deixam passar (§31.2).
 *
 * A escolha de filtrar AQUI, e não em cada sítio que decide, é deliberada: são
 * quatro os sítios que usam esta lista para travar alguma coisa — o veredicto
 * de uma bebida, o do acto de pedir, o caudal da casa e o que a página do
 * convidado mostra do caudal. Filtrar em quatro sítios é esquecer num, e o que
 * se esquecia era uma regra a recusar bebidas num modo em que prometeu não
 * recusar nenhuma.
 */
function barLimites(mysqli $conn): array {
    if (isset($GLOBALS['__bar_limites'])) return $GLOBALS['__bar_limites'];
    $out = array_values(array_filter(barLimitesVivos($conn),
        fn($l) => ($l['modo'] ?? 'trava') === 'trava'));
    return $GLOBALS['__bar_limites'] = $out;
}

/** Esquecer os limites lidos. Uma regra acabada de pôr vale de IMEDIATO —
    inclusive para os pedidos que já estavam na fila por decidir (§8.0.1). */
function barLimitesEsquecer(): void {
    unset($GLOBALS['__bar_limites'], $GLOBALS['__bar_limites_todos'],
          $GLOBALS['__bar_limites_vivos']);
}

/**
 * Todas as regras escritas, incluindo as que ainda não são horas de valer.
 *
 * O veredicto usa `barLimites()`, que só conhece as que valem agora. Mas o
 * ecrã da copa tem de ver a regra que marcou para as 2h — senão escrevia-a e
 * ela desaparecia, e a única leitura possível seria «não guardou». Cada linha
 * traz um `vigor`: `agora`, `ainda` (à espera da hora) ou `passou`.
 */
function barLimitesTodos(mysqli $conn): array {
    global $P;
    if (isset($GLOBALS['__bar_limites_todos'])) return $GLOBALS['__bar_limites_todos'];
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT * FROM {$P}bar_limites
                        WHERE casamento_id=$cid AND ativo=1 ORDER BY id");
    $agora = time();
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        foreach (['id','alvo_id','quantidade','janela_min','alvo_convidado_id','alvo_convite_id'] as $k) {
            $x[$k] = $x[$k] === null ? null : (int)$x[$k];
        }
        $de  = $x['vigora_em'] ? strtotime($x['vigora_em']) : null;
        $ate = $x['expira_em'] ? strtotime($x['expira_em']) : null;
        $x['vigor'] = ($de !== null && $de > $agora) ? 'ainda'
                    : (($ate !== null && $ate <= $agora) ? 'passou' : 'agora');
        $x['modo'] = $x['modo'] ?? 'trava';
        $out[] = $x;
    }
    return $GLOBALS['__bar_limites_todos'] = $out;
}

/**
 * O limite que manda sobre esta pessoa e esta bebida.
 *
 * Nove degraus, do mais específico para o mais geral (§8.0). O primeiro que
 * existir é o que vale — os outros nem se olham.
 *
 * A ordem tem duas chaves, e por esta ordem: primeiro quão específica é a
 * regra sobre a BEBIDA (uma bebida > uma gaveta > tudo), e só depois sobre
 * QUEM (uma pessoa > um convite > toda a gente). É o que faz «2 caipirinhas
 * por convidado» ganhar a «a Rita: 6 bebidas ao todo» quando o que está em
 * causa é uma caipirinha — a regra que fala da bebida é a que sabe do assunto.
 */
function barLimiteQueManda(array $limites, array $item, int $convidadoId, int $conviteId): ?array {
    $cat = (int)($item['categoria_id'] ?? 0);
    $iid = (int)$item['id'];
    $degraus = [
        ['item',      $iid, 'convidado', $convidadoId, null],
        ['item',      $iid, 'convidado', null,         $conviteId],
        ['categoria', $cat, 'convidado', $convidadoId, null],
        ['categoria', $cat, 'convidado', null,         $conviteId],
        ['item',      $iid, 'convidado', null,         null],
        ['categoria', $cat, 'convidado', null,         null],
        // «A Rita: no máximo três bebidas, ao todo.» Uma regra de uma pessoa
        // sobre TUDO faltava aqui, e o ecrã da copa oferecia-a — «qualquer
        // bebida» é a primeira opção da lista, e portanto era a que saía se
        // ninguém mexesse. A regra gravava-se, lia-se na ficha, e não travava
        // coisa nenhuma: a pessoa continuava a pedir, e quem a escreveu ficava
        // convencida de que o bar a estava a cumprir. É o pior tipo de falha
        // que uma regra pode ter.
        ['tudo',      0,    'convidado', $convidadoId, null],
        ['tudo',      0,    'convidado', null,         $conviteId],
        ['tudo',      0,    'convidado', null,         null],
    ];
    foreach ($degraus as [$escopo, $alvo, $suj, $pessoa, $convite]) {
        if ($escopo === 'categoria' && $cat === 0) continue;
        foreach ($limites as $l) {
            if ($l['sujeito'] !== $suj || $l['escopo'] !== $escopo) continue;
            if ($l['unidade'] !== 'bebidas') continue;
            if ($escopo !== 'tudo' && $l['alvo_id'] !== $alvo) continue;
            if ($l['alvo_convidado_id'] !== $pessoa) continue;
            if ($l['alvo_convite_id'] !== $convite) continue;
            return $l;
        }
    }
    return null;
}

/**
 * Quanto é que já foi consumido dentro da janela de um limite.
 *
 * Conta o que a copa aceitou fazer — nem recusados nem cancelados. Um pedido
 * recusado não gasta a quota de ninguém: seria castigar duas vezes.
 */
function barConsumo(mysqli $conn, array $l, int $convidadoId, int $conviteId,
                    int $excluir = 0): array {
    global $P;
    $cid = casamentoAtual();
    $bons = "p.estado IN ('em_analise','aprovado','a_caminho','entregue','falhou')";
    // Um pedido pode pedir para não se contar a si próprio. Serve a quem está
    // a DECIDIR um pedido que já está na fila: ele conta como consumo (está em
    // análise, e a quota é gasta desde que entra), e sem isto um pedido de
    // duas bebidas com um tecto de duas media-se contra si mesmo e nunca podia
    // ser aprovado.
    if ($excluir > 0) $bons .= ' AND p.id <> ' . $excluir;
    $janela = $l['janela_min'] > 0
        ? " AND p.criado_em >= (NOW() - INTERVAL " . (int)$l['janela_min'] . " MINUTE)" : '';

    // De quem: a pessoa, a família, ou a casa inteira.
    if ($l['sujeito'] === 'casa')            $quem = '';
    elseif ($l['alvo_convite_id'] !== null)  $quem = ' AND p.convite_id=' . (int)$l['alvo_convite_id'];
    else                                     $quem = ' AND p.convidado_id=' . $convidadoId;
    // Um limite geral (sem alvo) conta por pessoa: «2 caipirinhas por convidado».
    if ($l['sujeito'] === 'convidado' && $l['alvo_convite_id'] === null) {
        $quem = ' AND p.convidado_id=' . $convidadoId;
    }

    // Sobre o quê: um item, uma categoria, ou tudo.
    $sobre = '';
    if ($l['escopo'] === 'item')           $sobre = ' AND pi.item_id=' . (int)$l['alvo_id'];
    elseif ($l['escopo'] === 'categoria')  $sobre = ' AND i.categoria_id=' . (int)$l['alvo_id'];

    if ($l['unidade'] === 'pedidos') {
        $sql = "SELECT COUNT(DISTINCT p.id) n, MIN(p.criado_em) velho
                FROM {$P}bar_pedidos p
                WHERE p.casamento_id=$cid AND $bons$janela$quem";
        if ($sobre !== '') {
            $sql = "SELECT COUNT(DISTINCT p.id) n, MIN(p.criado_em) velho
                    FROM {$P}bar_pedidos p
                    JOIN {$P}bar_pedido_itens pi ON pi.pedido_id=p.id AND pi.casamento_id=p.casamento_id
                    LEFT JOIN {$P}bar_itens i ON i.id=pi.item_id AND i.casamento_id=pi.casamento_id
                    WHERE p.casamento_id=$cid AND $bons$janela$quem$sobre";
        }
    } else {
        $sql = "SELECT COALESCE(SUM(pi.quantidade),0) n, MIN(p.criado_em) velho
                FROM {$P}bar_pedido_itens pi
                JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                LEFT JOIN {$P}bar_itens i ON i.id=pi.item_id AND i.casamento_id=pi.casamento_id
                WHERE pi.casamento_id=$cid AND $bons$janela$quem$sobre";
    }
    $x = @$conn->query($sql);
    $r = $x ? $x->fetch_assoc() : null;
    return ['usado' => (int)($r['n'] ?? 0), 'mais_velho' => $r['velho'] ?? null];
}

/** Quantos segundos faltam até este limite voltar a abrir. 0 = nunca abre hoje. */
function barEspera(array $l, ?string $maisVelho): int {
    if ($l['janela_min'] <= 0) return 0;      // tecto da noite: não renova
    if (!$maisVelho) return 0;
    $t = strtotime($maisVelho);
    if ($t === false) return 0;
    return max(0, ($t + $l['janela_min'] * 60) - time());
}

/**
 * O que trava a casa toda, agora. Devolve null quando a copa está com folga.
 *
 * É o caudal (§8.2): enquanto a copa estiver dentro dele, ninguém dá por ele;
 * quando o ultrapassa, a espera sobe para toda a gente ao mesmo tempo — e a
 * mensagem muda de tom, porque não é o convidado que pediu de mais.
 *
 * Só as regras de BEBIDAS. As da casa contadas em `pedidos` são outra coisa —
 * travam o acto de pedir, e não o que se serve — e vão por barVeredictoPedido.
 * Enquanto isto não distinguia as duas, a MESMA regra fazia coisas diferentes
 * consoante o caminho que a avaliasse: aqui fechava todas as bebidas, uma a
 * uma, com a mensagem do caudal; lá era ignorada por não ser «de convidado».
 * Duas leituras da mesma linha é como um ecrã diz uma coisa e o servidor faz
 * outra — que é exactamente o que se via entre a página do convidado e a copa.
 */
function barRitmoDaCasa(mysqli $conn, int $excluir = 0): ?array {
    $pior = null;
    foreach (barLimites($conn) as $l) {
        if ($l['sujeito'] !== 'casa') continue;
        if ($l['unidade'] !== 'bebidas') continue;
        $c = barConsumo($conn, $l, 0, 0, $excluir);
        if ($c['usado'] < $l['quantidade']) continue;
        $s = barEspera($l, $c['mais_velho']);
        if ($pior === null || $s > $pior['segundos']) {
            $pior = ['segundos' => $s, 'limite' => $l,
                     'mensagem' => $l['mensagem'] ?: ''];
        }
    }
    return $pior;
}

/**
 * O veredicto sobre uma bebida, para uma pessoa, agora.
 *
 * Devolve quantas pode levar (`pode`), e — quando é zero — porquê e por quanto
 * tempo. O convidado nunca lê a `nota`: essa é de quem escreveu a regra.
 */
function barVeredicto(mysqli $conn, array $item, int $convidadoId, int $conviteId,
                      ?array $ritmo, int $excluir = 0): array {
    $out = ['pode' => min((int)$item['disponivel'], (int)$item['max_por_pedido']),
            'travao' => null, 'espera_s' => 0, 'mensagem' => ''];

    if ((int)$item['disponivel'] <= 0) {
        return ['pode' => 0, 'travao' => 'stock', 'espera_s' => 0, 'mensagem' => ''];
    }
    // O caudal da casa corre por cima de tudo, e nenhum limite individual o
    // levanta: é o ritmo da copa, e a copa é de todos.
    if ($ritmo) {
        return ['pode' => 0, 'travao' => 'casa', 'espera_s' => $ritmo['segundos'],
                'mensagem' => $ritmo['mensagem']];
    }
    $l = barLimiteQueManda(barLimites($conn), $item, $convidadoId, $conviteId);
    if (!$l) return $out;

    if ($l['quantidade'] <= 0) {
        // Uma proibição pode ter hora de saída — é o que a copa põe quando
        // suspende uma bebida que está a sair depressa de mais (§30.4). Nesse
        // caso não é «esta noite não»: é «agora não», e a diferença é a única
        // coisa que a pessoa quer saber. A regra deixa de valer sozinha quando
        // a hora chega (barLimites filtra por expira_em), e por isso não há
        // nada para levantar depois — a bebida volta ao menu por si.
        $ate = !empty($l['expira_em']) ? strtotime($l['expira_em']) : false;
        $falta = $ate ? max(0, $ate - time()) : 0;
        return ['pode' => 0, 'travao' => $falta > 0 ? 'suspensa' : 'proibido',
                'espera_s' => $falta, 'mensagem' => $l['mensagem'] ?: ''];
    }
    $c = barConsumo($conn, $l, $convidadoId, $conviteId, $excluir);
    $sobra = $l['quantidade'] - $c['usado'];
    if ($sobra <= 0) {
        return ['pode' => 0, 'travao' => $l['janela_min'] > 0 ? 'intervalo' : 'tecto',
                'espera_s' => barEspera($l, $c['mais_velho']),
                'mensagem' => $l['mensagem'] ?: ''];
    }
    $out['pode'] = min($out['pode'], $sobra);
    return $out;
}

/* ============================================================
   AS MENSAGENS DO CASAL (§31.5)

   O que o convidado lê quando um pedido não passa é a voz da festa, e não a
   da aplicação. Até aqui essa voz era só nossa: os textos de fábrica, escritos
   uma vez, iguais em todos os casamentos. Um casal que trate os convidados por
   «tu», ou que queira uma frase sua, não tinha onde a pôr.

   Três coisas que isto NÃO é:
     • não é um sítio para mensagens por pessoa — uma mensagem diferente é a
       pessoa perceber que foi apontada, e o §9 existe para o evitar;
     • não é obrigatório — uma linha vazia usa o texto de fábrica, e por isso
       ninguém tem de preencher nada para o bar funcionar;
     • não muda o que a regra decide, só o que se diz sobre a decisão.
   ============================================================ */

/** As situações que se podem reescrever, e o que cada uma quer dizer. */
function barSituacoes(): array {
    return [
        'stock'        => 'A bebida acabou',
        'casa'         => 'A copa está a dar vazão a muitos pedidos',
        'proibido'     => 'Esta bebida não é para esta pessoa',
        'suspensa'     => 'Bebida fechada por um bocado',
        'intervalo'    => 'Ainda não são horas da próxima',
        'tecto'        => 'Já levou o que a casa serve',
        'corte'        => 'Cabe menos do que pediu',
        'copa_fechada' => 'O bar está fechado',
        'copa_pausada' => 'A copa está em pausa',
        'aguarda_copa' => 'O pedido entrou e está à espera de decisão',
    ];
}

/** As variáveis que se podem escrever dentro de uma mensagem. */
function barVariaveis(): array {
    return ['{BEBIDA}'   => 'o nome da bebida',
            '{PEDIDAS}'  => 'quantas a pessoa pediu',
            '{ACEITES}'  => 'quantas se podem servir',
            '{TEMPO}'    => 'quanto falta, em palavras',
            '{NOME}'     => 'o nome de quem pede'];
}

/** As mensagens escritas pelo casal, por situação. Lê-se uma vez por pedido. */
function barMensagens(mysqli $conn): array {
    global $P;
    if (isset($GLOBALS['__bar_mensagens'])) return $GLOBALS['__bar_mensagens'];
    $cid = casamentoAtual();
    $out = [];
    $r = @$conn->query("SELECT situacao, texto FROM {$P}bar_mensagens
                        WHERE casamento_id=$cid AND ativo=1");
    if ($r) while ($x = $r->fetch_assoc()) {
        if (trim((string)$x['texto']) !== '') $out[$x['situacao']] = $x['texto'];
    }
    // Herança: a mensagem de bar fechado viveu numa definição à parte antes de
    // haver esta tabela. Continua a ler-se enquanto ninguém escrever a nova —
    // um casal que a tenha escrito há meses não a perde por termos arrumado o
    // sítio onde ela mora. Assim que ele guardar a nova, é a nova que manda.
    if (!isset($out['copa_fechada'])) {
        $velha = trim(barDef($conn, 'bar.mensagem_fechado'));
        if ($velha !== '') $out['copa_fechada'] = $velha;
    }
    return $GLOBALS['__bar_mensagens'] = $out;
}

/** Esquecer as mensagens lidas — uma acabada de guardar vale já. */
function barMensagensEsquecer(): void { unset($GLOBALS['__bar_mensagens']); }

/**
 * A mensagem do casal para esta situação, com as variáveis trocadas.
 *
 * Devolve '' quando não há — e é isso que faz o texto de fábrica continuar a
 * valer sem ninguém ter de o repetir.
 */
function barMensagem(mysqli $conn, string $situacao, array $vars = []): string {
    return barTrocarVariaveis(barMensagens($conn)[$situacao] ?? '', $vars);
}

/**
 * O que se diz a quem não pode pedir agora (§9).
 *
 * Três degraus, e por esta ordem: a mensagem da REGRA (a mais específica — foi
 * escrita a pensar naquele caso), depois a da SITUAÇÃO (do casal, §31.5), e só
 * depois a de fábrica. O mais específico ganha, como em todo o resto do módulo.
 *
 * Os textos fazem parte do módulo: são eles que decidem se isto parece uma
 * casa que cuida ou um torniquete. Três coisas nunca aparecem aqui — a `nota`
 * da regra (é de quem a escreveu), a diferença entre «a casa limita» e
 * «limitámos-lhe a si» (essa conversa faz-se de pessoa para pessoa), e a
 * palavra «limite».
 */
function barTextoTravao(mysqli $conn, array $item, array $v, int $pedidas = 0,
                        string $quem = ''): string {
    if ($v['mensagem'] !== '') return $v['mensagem'];
    $nome = '«' . $item['nome'] . '»';
    // A situação é o travão; quando cabe alguma coisa mas menos do que se
    // pediu, é o «corte» — que não é travão nenhum, é uma conta.
    $sit = $v['travao'] ?: 'corte';
    $doCasal = barMensagem($conn, $sit, [
        '{BEBIDA}'  => $item['nome'],
        '{PEDIDAS}' => $pedidas,
        '{ACEITES}' => (int)$v['pode'],
        '{TEMPO}'   => $v['espera_s'] > 0 ? barRelogio((int)$v['espera_s']) : '',
        '{NOME}'    => $quem,
    ]);
    if ($doCasal !== '') return $doCasal;
    // E, em último, o de fábrica — escrito com as mesmas variáveis, e trocado
    // pela mesma função. Assim o que o casal vê no editor como «o que está lá
    // hoje» é exactamente o que sai daqui, e não uma cópia que um dia diverge.
    return barTrocarVariaveis(barTextosFabrica()[$sit] ?? '', [
        '{BEBIDA}'  => $item['nome'],
        '{PEDIDAS}' => $pedidas,
        '{ACEITES}' => (int)$v['pode'],
        '{TEMPO}'   => $v['espera_s'] > 0 ? barRelogio((int)$v['espera_s']) : '',
        '{NOME}'    => $quem,
    ]);
}

/**
 * Os textos de fábrica, por situação, com as variáveis por trocar.
 *
 * São a voz por omissão do módulo — e são também o que o editor mostra por
 * baixo de cada caixa, para quem escreve a sua saber o que está a substituir.
 * Uma segunda cópia para o ecrã seria uma cópia a divergir.
 *
 * Três coisas que nunca aparecem nestes textos: a `nota` da regra (é de quem a
 * escreveu), a diferença entre «a casa limita» e «limitámos-lhe a si» (essa
 * conversa faz-se de pessoa para pessoa), e a palavra «limite».
 */
function barTextosFabrica(): array {
    return [
        'stock'     => 'A «{BEBIDA}» acabou. Escolha outra — a copa tem mais para provar.',
        'casa'      => 'A copa está a dar vazão a muitos pedidos neste momento. '
                     . 'O seu abre daqui a {TEMPO} — e fica na frente quando abrir.',
        'proibido'  => 'A «{BEBIDA}» não está disponível para si esta noite. '
                     . 'Fale com um garçom se achar que é engano.',
        // A copa fechou esta bebida por um bocado. Diz-se quanto falta e mais
        // nada: o motivo é da casa, e «está a sair depressa de mais» dito ao
        // convidado lê-se como uma acusação a quem a pediu.
        'suspensa'  => 'A «{BEBIDA}» está indisponível de momento. '
                     . 'Volte a tentar daqui a {TEMPO}.',
        'intervalo' => 'A próxima «{BEBIDA}» abre daqui a {TEMPO}.',
        'tecto'     => 'Já levou o que a casa serve de «{BEBIDA}» esta noite. '
                     . 'Há mais para provar.',
        'corte'     => 'De «{BEBIDA}» podemos servir-lhe {ACEITES} neste momento.',
        'copa_fechada' => 'A copa está fechada neste momento.',
        'copa_pausada' => 'A copa está a recuperar do movimento. '
                        . 'Volte a tentar daqui a {TEMPO}.',
        'aguarda_copa' => 'A copa está a ver. Diga este número a quem entregar.',
    ];
}

/** Trocar as variáveis de um texto, e apagar as que sobrarem. */
function barTrocarVariaveis(string $t, array $vars): string {
    if ($t === '') return '';
    foreach ($vars as $k => $v) $t = str_replace($k, (string)$v, $t);
    // As que sobrarem apagam-se: um convidado não tem de ler «{TEMPO}» porque
    // quem escreveu a frase usou uma variável que aquela situação não tem.
    return trim(preg_replace('/\{[A-Z_]+\}/u', '', $t));
}

/**
 * Uma regra dita em voz alta.
 *
 * O ecrã não recompõe a frase a partir dos campos: lê-a como o servidor a
 * escreveu. Duas gramáticas para a mesma regra é como se chega a um ecrã que
 * diz uma coisa e a um servidor que faz outra.
 */
function barRegraFrase(mysqli $conn, array $l): string {
    $sobre = 'tudo';
    if ($l['escopo'] === 'item') {
        $i = barItem($conn, (int)$l['alvo_id']);
        $sobre = $i ? '«' . $i['nome'] . '»' : 'uma bebida que já não existe';
    } elseif ($l['escopo'] === 'categoria') {
        $c = null;
        foreach (barCategorias($conn) as $x) if ((int)$x['id'] === (int)$l['alvo_id']) $c = $x;
        $sobre = $c ? $c['nome'] : 'uma gaveta que já não existe';
    }
    $unid = $l['unidade'] === 'pedidos' ? 'pedido' : 'bebida';
    if ((int)$l['quantidade'] === 0) {
        return 'não pode pedir ' . $sobre . barHorasFrase($l);
    }
    $q = (int)$l['quantidade'];
    $quanto = $q . ' ' . $unid . ($q === 1 ? '' : 's');
    if ((int)$l['janela_min'] === 0) {
        return 'no máximo ' . $quanto . ' de ' . $sobre . ', ao todo' . barHorasFrase($l);
    }
    return $quanto . ' de ' . $sobre . ' a cada ' . barRelogio((int)$l['janela_min'] * 60)
         . barHorasFrase($l);
}

/**
 * Uma hora escrita («21:00») no momento que ela quer dizer.
 *
 * Quem põe uma regra no meio de uma festa pensa em horas, não em datas — e uma
 * festa atravessa a meia-noite, o que torna «às 2h» ambíguo. Resolve-se com
 * uma assimetria que é a leitura certa dos dois casos:
 *
 *   • o PRINCÍPIO («a partir das 21h») que já passou hoje quer dizer que a
 *     regra já começou. Empurrá-lo para amanhã calava-a a noite inteira.
 *   • o FIM («até às 2h») que já passou hoje quer dizer a madrugada seguinte.
 *     Deixá-lo hoje matava a regra no instante em que se escrevesse.
 *
 * Devolve null se a hora não for hora.
 */
function barHoraMomento(string $hhmm, bool $fim): ?string {
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) return null;
    $hoje = mktime((int)$m[1], (int)$m[2], 0);
    if ($fim && $hoje <= time()) $hoje += 86400;
    return date('Y-m-d H:i:s', $hoje);
}

/**
 * O rabo da frase quando a regra tem horas: «, das 21h às 2h».
 *
 * Vazio quando não as tem, que é o caso comum — uma regra sem horas vale o que
 * a festa durar, e dizê-lo em cada linha seria ruído.
 */
function barHorasFrase(array $l): string {
    $h = fn($v) => date((int)date('i', strtotime($v)) ? 'H\hi' : 'H\h', strtotime($v));
    $de  = !empty($l['vigora_em']) ? $h($l['vigora_em']) : '';
    $ate = !empty($l['expira_em']) ? $h($l['expira_em']) : '';
    if ($de !== '' && $ate !== '') return ', das ' . $de . ' às ' . $ate;
    if ($de !== '')  return ', a partir das ' . $de;
    if ($ate !== '') return ', até às ' . $ate;
    return '';
}

/** Uma regra como o ecrã da copa a quer: a frase, e o que a identifica. */
function barRegraLinha(mysqli $conn, array $l): array {
    $quem = 'toda a gente';
    if ($l['sujeito'] === 'casa') {
        $quem = 'a copa (o caudal da casa)';
    } elseif ($l['alvo_convidado_id']) {
        $g = barConvidado($conn, (int)$l['alvo_convidado_id']);
        $quem = $g ? $g['nome'] : 'alguém que já não está na lista';
    } elseif ($l['alvo_convite_id']) {
        $quem = 'o convite inteiro';
    }
    return [
        'id' => (int)$l['id'],
        'frase' => barRegraFrase($conn, $l),
        'quem' => $quem,
        'sujeito' => $l['sujeito'],
        'escopo' => $l['escopo'],
        'alvo_id' => (int)$l['alvo_id'],
        'unidade' => $l['unidade'],
        'quantidade' => (int)$l['quantidade'],
        'janela_min' => (int)$l['janela_min'],
        'alvo_convidado_id' => $l['alvo_convidado_id'],
        'alvo_convite_id' => $l['alvo_convite_id'],
        'mensagem' => $l['mensagem'],
        'nota' => $l['nota'],                 // só o pessoal vê
        // Travar, sugerir, confirmar ou avisar. É o que separa uma regra que
        // fecha a porta de uma que toca a campainha (§31.2).
        'modo' => $l['modo'] ?? 'trava',
        'vigora_em' => $l['vigora_em'] ?? null,
        'expira_em' => $l['expira_em'],
        // `agora`, `ainda` (à espera da hora) ou `passou`. As regras lidas por
        // barLimites() valem todas agora, e por isso vêm sem marca nenhuma.
        'vigor' => $l['vigor'] ?? 'agora',
        'criado_por' => $l['criado_por'],
    ];
}

/**
 * O que uma pessoa já levou esta noite, por bebida.
 *
 * Conta o mesmo que os limites contam — tudo o que a copa aceitou fazer, o que
 * ainda está por decidir incluído. Se contasse só o entregue, a ficha dizia
 * «ainda não levou nada» com dois copos na fila, e não explicava o veredicto
 * que ela própria mostra. O que está por servir vai à parte, porque para quem
 * está a decidir a diferença importa: uma pessoa com três na fila ainda não
 * bebeu nada.
 */
function barConsumoPessoal(mysqli $conn, int $convidadoId): array {
    global $P;
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT pi.nome_no_momento nome,
                                 SUM(pi.quantidade) n,
                                 SUM(CASE WHEN p.estado='entregue' THEN pi.quantidade ELSE 0 END) servidas
                          FROM {$P}bar_pedido_itens pi
                          JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                          WHERE pi.casamento_id=? AND p.convidado_id=?
                            AND p.estado IN ('em_analise','aprovado','a_caminho','entregue','falhou')
                          GROUP BY pi.nome_no_momento ORDER BY n DESC, nome");
    if (!$st) return [];
    $st->bind_param('ii', $cid, $convidadoId);
    if (!$st->execute()) return [];
    $out = [];
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $out[] = ['nome' => $x['nome'], 'n' => (int)$x['n'],
                  'servidas' => (int)$x['servidas'],
                  'por_servir' => (int)$x['n'] - (int)$x['servidas']];
    }
    return $out;
}


/**
 * Os pedidos por decidir que deixaram de caber nas regras.
 *
 * Uma regra vale de imediato, mas não recusa nada sozinha: quem a pôs pode
 * muito bem querer servir o copo que já estava pedido. Assinala-se, e a copa
 * decide (§8.0.1).
 */
/**
 * O que impede servir este pedido, agora. Null quando nada impede.
 *
 * Corre as mesmas contas que o convidado viu no telemóvel, mas para as
 * quantidades que a copa está mesmo a aprovar. É a guarda que faltava: a fila
 * assinalava os pedidos fora das regras e o botão aprovava-os à mesma.
 *
 * O próprio pedido desconta-se da conta (`$excluir`): ele já está na fila com
 * o estado `em_analise`, e barConsumo() conta o que a copa aceitou fazer —
 * incluindo-o. Sem isto, um pedido de duas bebidas com um tecto de duas
 * media-se contra si próprio e nunca podia ser aprovado.
 *
 * `$finais` são as linhas depois do corte: [['li' => linha, 'q' => quantidade]].
 */
function barTravaoDe(mysqli $conn, int $convidadoId, int $conviteId,
                     array $finais, int $excluir = 0): ?string {
    barLimitesEsquecer();
    $ritmo = barRitmoDaCasa($conn, $excluir);
    foreach ($finais as $f) {
        $item = barItem($conn, (int)$f['li']['item_id']);
        if (!$item) continue;
        $v = barVeredicto($conn, $item, $convidadoId, $conviteId, $ritmo, $excluir);
        if ((int)$f['q'] > (int)$v['pode']) {
            return 'As regras do bar não deixam servir isto: '
                 . barTextoTravao($conn, $item, $v, (int)$f['q'])
                 . ' Corte a quantidade, ou levante a regra em «Regras do Bar».';
        }
    }
    return null;
}

function barFilaContraRegras(mysqli $conn): array {
    $fora = [];
    $ritmo = barRitmoDaCasa($conn);
    foreach (barPedidos($conn, "p.estado='em_analise' ORDER BY p.id") as $p) {
        $gid = (int)$p['convidado_id']; $conv = (int)$p['convite_id'];
        foreach (barItensDoPedido($conn, (int)$p['id']) as $li) {
            $item = barItem($conn, $li['item_id']);
            if (!$item) continue;
            $v = barVeredicto($conn, $item, $gid, $conv, $ritmo);
            if ($li['quantidade'] > $v['pode']) {
                $fora[] = ['id' => (int)$p['id'], 'codigo' => $p['codigo_curto'],
                           'porque' => barTextoTravao($conn, $item, $v, $li['quantidade'])];
                break;
            }
        }
    }
    return $fora;
}

/* ============================================================
   O MOTOR DE SUGESTÕES (§31.2)

   Até aqui o motor de regras fazia uma coisa e uma só: recusava. É a leitura
   certa para «esta pessoa não bebe álcool», e a errada para «o gin está a sair
   depressa de mais» — nessa, quem decide é quem está a olhar para a sala.

   O que se segue MEDE e PROPÕE. Não aplica nada: escreve um alerta, e o
   copeiro aplica, adapta ou ignora (fase 3). A única coisa que este código faz
   sozinho é falar.

   Corre a cada leitura de `bar_estado` — de oito em oito segundos, enquanto
   alguém tiver a copa aberta no ecrã. NÃO corre num processo à parte: um
   processo a correr sozinho numa noite de festa é uma peça a mais para falhar,
   e ninguém a estaria a ver falhar.
   ============================================================ */

/**
 * A trava que impede o mesmo alerta de nascer de oito em oito segundos.
 *
 * Cada alerta tem uma CHAVE que é a sua identidade — «gin, degrau 30». Enquanto
 * houver um alerta vivo com essa chave, não nasce outro. Sem isto, o painel
 * enchia-se de cópias do mesmo aviso e deixava de se poder ler, que é a mesma
 * coisa que não haver painel nenhum.
 *
 * «Vivo» inclui o que já foi decidido: um alerta ignorado foi RESPONDIDO, e
 * voltar a fazer a pergunta a cada oito segundos é não ter ouvido a resposta.
 * A chave só se liberta quando a condição passa (barAlertaCaducar).
 */
function barAlertaVivo(mysqli $conn, string $chave): bool {
    global $P;
    $cid = casamentoAtual();
    // Olha-se para o ÚLTIMO desta chave, e não para «existe algum». A diferença
    // é o que faz a trava soltar-se: enquanto o último não estiver caducado, a
    // chave está tomada — esteja o alerta por responder ou já respondido.
    $st = $conn->prepare("SELECT estado FROM {$P}bar_alertas
                          WHERE casamento_id=? AND chave=? ORDER BY id DESC LIMIT 1");
    if (!$st) return true;                 // na dúvida, não se insiste
    $st->bind_param('is', $cid, $chave);
    @$st->execute();
    $x = $st->get_result()->fetch_assoc();
    return $x ? $x['estado'] !== 'caducado' : false;
}

/** Levantar um alerta, se ainda não houver um vivo com esta chave. */
function barAlertaLevantar(mysqli $conn, string $chave, string $tipo, string $nivel,
                           array $situacao, array $sugestao, ?int $regraId = null): bool {
    global $P;
    if (barAlertaVivo($conn, $chave)) return false;
    $cid = casamentoAtual();
    $st = $conn->prepare("INSERT INTO {$P}bar_alertas
            (casamento_id,regra_id,tipo,nivel,chave,situacao,sugestao,estado,criado_em)
            VALUES (?,?,?,?,?,?,?,'aberto',NOW())");
    if (!$st) return false;
    $sit = json_encode($situacao, JSON_UNESCAPED_UNICODE);
    $sug = json_encode($sugestao, JSON_UNESCAPED_UNICODE);
    $st->bind_param('iisssss', $cid, $regraId, $tipo, $nivel, $chave, $sit, $sug);
    return (bool)@$st->execute();
}

/**
 * A condição passou: liberta-se a chave.
 *
 * É isto que faz «um degrau, um alerta» funcionar nos dois sentidos. O alerta
 * de 30% não volta a nascer enquanto a bebida não subir acima de 30% outra vez
 * — e quando sobe, o que estava fica marcado `caducado` e a chave fica livre
 * para a próxima descida. Um alerta caducado não se apaga: continua no
 * histórico da noite, que é metade da razão de ele existir.
 */
function barAlertaCaducar(mysqli $conn, string $chave): void {
    global $P;
    $cid = casamentoAtual();
    // Os que estavam por responder fecham-se: a pergunta deixou de fazer
    // sentido, e responder a uma pergunta que já não existe é trabalho a mais.
    $st = $conn->prepare("UPDATE {$P}bar_alertas SET estado='caducado'
                          WHERE casamento_id=? AND chave=? AND estado='aberto'");
    if ($st) { $st->bind_param('is', $cid, $chave); @$st->execute(); }

    // E se ficou um já RESPONDIDO a segurar a chave, deixa-se a marca de que a
    // condição passou.
    //
    // Sem isto, um alerta aplicado às 23h segurava a chave a noite inteira: a
    // bebida era reposta, voltava a descer aos mesmos 15%, e o segundo
    // esgotamento passava em silêncio — que é exactamente o que o alerta
    // existe para não deixar acontecer. A marca é uma linha própria, e não uma
    // reescrita do estado do alerta antigo: o que foi aplicado tem de continuar
    // a ler-se como aplicado no histórico da noite.
    if (!barAlertaVivo($conn, $chave)) return;
    $st = $conn->prepare("INSERT INTO {$P}bar_alertas
            (casamento_id,tipo,nivel,chave,situacao,sugestao,estado,criado_em)
            VALUES (?,'fim','aviso',?,'{}','{}','caducado',NOW())");
    if ($st) { $st->bind_param('is', $cid, $chave); @$st->execute(); }
}

/** Os degraus da percentagem de stock, do maior para o menor. */
function barDegraus(mysqli $conn): array {
    $v = array_filter(array_map('intval',
        explode(',', (string)barDef($conn, 'bar.degraus_stock'))), fn($x) => $x > 0);
    rsort($v);
    return $v;
}

/**
 * O nível de um degrau de stock.
 *
 * Os dois primeiros degraus são notícia, o terceiro é para agir, e daí para
 * baixo é para agir JÁ. Sai da posição e não do número: quem puser
 * «80,60,40,20» quer a mesma escada com outros números.
 */
function barNivelDoDegrau(int $posicao, int $quantos): string {
    if ($posicao === 0 && $quantos > 2) return 'aviso';
    if ($posicao <= 1 && $quantos > 3) return 'atencao';
    return $posicao >= $quantos - 2 ? 'critico' : 'atencao';
}

/**
 * Mede a noite e levanta o que houver a levantar. Devolve quantos alertas
 * nasceram nesta passagem — zero é o caso normal, e é o bom.
 */
function barSugestoes(mysqli $conn): int {
    // Um bar fechado não tem ritmo nem stock a vigiar. Medir com a festa
    // acabada era encher o painel de avisos sobre uma noite que já terminou.
    if (!barAberto($conn)) return 0;
    $novos = 0;

    // ---- os degraus da percentagem ----------------------------
    // «Restam 15% do gin» é a pergunta que a copa faz quando olha para o
    // armazém. O `stock_minimo` responde a outra — «restam 5 garrafas» —, e as
    // duas continuam a viver lado a lado porque são mesmo duas perguntas.
    $degraus = barDegraus($conn);
    foreach (barItens($conn, true) as $i) {
        if ($i['percentagem'] === null) continue;     // a noite ainda não abriu
        $pc = (int)$i['percentagem'];
        foreach ($degraus as $n => $d) {
            $chave = 'stock:' . $i['id'] . ':' . $d;
            if ($pc > $d) { barAlertaCaducar($conn, $chave); continue; }
            // Só o degrau MAIS APERTADO que a bebida cruzou é que fala. Sem
            // isto, uma bebida a 12% levantava três alertas de uma vez — 50,
            // 30 e 15 — e os três diziam a mesma coisa por números diferentes.
            $maisApertado = true;
            foreach ($degraus as $outro) if ($outro < $d && $pc <= $outro) $maisApertado = false;
            if (!$maisApertado) continue;
            $nivel = barNivelDoDegrau($n, count($degraus));
            // O que se propõe sobe de tom com o degrau: primeiro cortar o que
            // cada pedido leva, e só no fim fechar a bebida.
            $accao = $nivel === 'critico' && $n >= count($degraus) - 1
                   ? ['accao' => 'suspender_bebida', 'item_id' => $i['id'],
                      'minutos' => max(1, (int)barDef($conn, 'bar.pausa_min'))]
                   : ['accao' => 'baixar_max_por_pedido', 'item_id' => $i['id'],
                      'para' => max(1, (int)$i['max_por_pedido'] - 1)];
            if ($accao['accao'] === 'baixar_max_por_pedido'
                && $accao['para'] >= (int)$i['max_por_pedido']) {
                // Já está em 1: não há o que cortar, e propor «baixe para 1»
                // a quem já está em 1 é o sistema a não saber o que está a ver.
                $accao = ['accao' => 'nenhuma'];
            }
            if (barAlertaLevantar($conn, $chave, 'stock_degrau', $nivel,
                    ['item_id' => $i['id'], 'bebida' => $i['nome'],
                     'percentagem' => $pc, 'degrau' => $d,
                     'disponivel' => $i['disponivel'], 'base' => $i['base_noite']],
                    $accao)) $novos++;
        }
    }

    // ---- esgotamento iminente, ao ritmo a que está a sair ------
    // O degrau diz QUANTO resta; isto diz QUANTO TEMPO resta, que é a conta que
    // manda alguém à cidade buscar mais — ou não manda, se já não houver tempo.
    foreach (barRutura($conn) as $x) {
        $chave = 'rutura:' . $x['id'];
        if ($x['acaba_em_min'] === null || $x['acaba_em_min'] > 30) {
            barAlertaCaducar($conn, $chave);
            continue;
        }
        if (barAlertaLevantar($conn, $chave, 'rutura', 'critico',
                ['item_id' => $x['id'], 'bebida' => $x['nome'],
                 'disponivel' => $x['disponivel'], 'por_hora' => $x['por_hora'],
                 'acaba_em_min' => $x['acaba_em_min']],
                ['accao' => 'baixar_max_por_pedido', 'item_id' => $x['id'], 'para' => 1]
            )) $novos++;
    }

    // ---- as regras que NÃO travam ------------------------------
    // Uma regra em `sugere`, `confirma` ou `avisa` não recusa nada a ninguém.
    // O que ela faz é isto: quando a condição dela se cumpre, toca a campainha.
    // Sem esta parte, pôr uma regra em «sugere» era desligá-la — e uma regra
    // desligada que continua escrita no painel é a pior coisa que este módulo
    // pode ter (§8.0).
    foreach (barLimitesVivos($conn) as $l) {
        $modo = $l['modo'] ?? 'trava';
        if ($modo === 'trava') continue;
        $novos += barSugereRegra($conn, $l, $modo);
    }
    return $novos;
}

/**
 * Uma regra que não trava, medida.
 *
 * Três formas, e cada uma mede-se onde faz sentido:
 *   • a da CASA conta-se de uma vez — é o caudal, e é um número só;
 *   • a de UMA PESSOA (ou de um convite) conta-se contra essa pessoa;
 *   • a de TODA A GENTE conta-se por cabeça, numa consulta agrupada. Varrer
 *     convidado a convidado seria uma consulta por pessoa a cada oito segundos.
 */
function barSugereRegra(mysqli $conn, array $l, string $modo): int {
    global $P;
    $cid = casamentoAtual();
    $tecto = (int)$l['quantidade'];
    $nivel = $modo === 'avisa' ? 'aviso' : ($modo === 'confirma' ? 'critico' : 'atencao');
    $frase = barRegraFrase($conn, $l);
    $novos = 0;

    // Uma regra de quantidade 0 é uma proibição, e uma proibição que não trava
    // não tem condição nenhuma para medir: ou está lá e fecha, ou não está.
    if ($tecto <= 0) return 0;

    if ($l['sujeito'] === 'casa') {
        $chave = 'regra:' . $l['id'];
        $c = barConsumo($conn, $l, 0, 0);
        if ($c['usado'] < $tecto) { barAlertaCaducar($conn, $chave); return 0; }
        $accao = $modo === 'avisa' ? ['accao' => 'nenhuma']
               : ['accao' => 'pausar_copa',
                  'minutos' => max(1, (int)barDef($conn, 'bar.pausa_min'))];
        return barAlertaLevantar($conn, $chave, 'caudal', $nivel,
            ['regra' => $frase, 'usado' => $c['usado'], 'tecto' => $tecto,
             'janela_min' => (int)$l['janela_min'], 'unidade' => $l['unidade']],
            $accao, (int)$l['id']) ? 1 : 0;
    }

    // De uma pessoa em concreto, ou de um convite inteiro.
    if ($l['alvo_convidado_id'] !== null || $l['alvo_convite_id'] !== null) {
        $gid = (int)($l['alvo_convidado_id'] ?? 0);
        $c = barConsumo($conn, $l, $gid, (int)($l['alvo_convite_id'] ?? 0));
        $chave = 'regra:' . $l['id'];
        if ($c['usado'] < $tecto) { barAlertaCaducar($conn, $chave); return 0; }
        $g = $gid ? barConvidado($conn, $gid) : null;
        $accao = $modo === 'avisa' || !$gid ? ['accao' => 'nenhuma']
               : ['accao' => 'travar_convidado', 'convidado_id' => $gid,
                  'minutos' => max(1, (int)barDef($conn, 'bar.pausa_min'))];
        return barAlertaLevantar($conn, $chave, 'regra_pessoa', $nivel,
            ['regra' => $frase, 'usado' => $c['usado'], 'tecto' => $tecto,
             'convidado_id' => $gid, 'nome' => $g['nome'] ?? 'um convite'],
            $accao, (int)$l['id']) ? 1 : 0;
    }

    // De toda a gente, contada POR CABEÇA. Uma consulta agrupada, e não uma
    // por convidado: a diferença entre um número e trezentos a cada oito
    // segundos é a diferença entre isto correr e isto não poder existir.
    $bons = "p.estado IN ('em_analise','aprovado','a_caminho','entregue','falhou')";
    $janela = $l['janela_min'] > 0
        ? " AND p.criado_em >= (NOW() - INTERVAL " . (int)$l['janela_min'] . " MINUTE)" : '';
    $sobre = '';
    if ($l['escopo'] === 'item')          $sobre = ' AND pi.item_id=' . (int)$l['alvo_id'];
    elseif ($l['escopo'] === 'categoria') $sobre = ' AND i.categoria_id=' . (int)$l['alvo_id'];

    if ($l['unidade'] === 'pedidos') {
        $sql = "SELECT p.convidado_id gid, COUNT(DISTINCT p.id) n
                FROM {$P}bar_pedidos p
                WHERE p.casamento_id=$cid AND $bons$janela
                GROUP BY p.convidado_id HAVING n >= $tecto";
    } else {
        $sql = "SELECT p.convidado_id gid, COALESCE(SUM(pi.quantidade),0) n
                FROM {$P}bar_pedido_itens pi
                JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                LEFT JOIN {$P}bar_itens i ON i.id=pi.item_id AND i.casamento_id=pi.casamento_id
                WHERE pi.casamento_id=$cid AND $bons$janela$sobre
                GROUP BY p.convidado_id HAVING n >= $tecto";
    }
    $r = @$conn->query($sql);
    $passaram = [];
    if ($r) while ($x = $r->fetch_assoc()) $passaram[(int)$x['gid']] = (int)$x['n'];

    // Uma chave por pessoa: duas pessoas a passar o mesmo tecto são duas
    // conversas diferentes, e juntá-las num alerta só dava um painel que diz
    // «alguém» — que é a informação de que a copa menos precisa.
    foreach ($passaram as $gid => $n) {
        $g = barConvidado($conn, $gid);
        if (!$g) continue;
        $accao = $modo === 'avisa' ? ['accao' => 'nenhuma']
               : ['accao' => 'travar_convidado', 'convidado_id' => $gid,
                  'minutos' => max(1, (int)barDef($conn, 'bar.pausa_min'))];
        if (barAlertaLevantar($conn, 'regra:' . $l['id'] . ':' . $gid, 'regra_pessoa', $nivel,
                ['regra' => $frase, 'usado' => $n, 'tecto' => $tecto,
                 'convidado_id' => $gid, 'nome' => $g['nome']],
                $accao, (int)$l['id'])) $novos++;
    }
    // Quem desceu abaixo do tecto liberta a sua chave — numa regra com janela,
    // o tempo passa e a pessoa volta a caber.
    $st = $conn->prepare("SELECT chave FROM {$P}bar_alertas
                          WHERE casamento_id=? AND estado <> 'caducado'
                            AND chave LIKE CONCAT('regra:', ?, ':%')");
    if ($st) {
        $rid = (int)$l['id'];
        $st->bind_param('ii', $cid, $rid);
        @$st->execute();
        $res = $st->get_result();
        while ($x = $res->fetch_assoc()) {
            $quem = (int)substr($x['chave'], strrpos($x['chave'], ':') + 1);
            if (!isset($passaram[$quem])) barAlertaCaducar($conn, $x['chave']);
        }
    }
    return $novos;
}

/** Os alertas por decidir, e os últimos resolvidos. É o que o painel desenha. */
function barAlertas(mysqli $conn, int $quantos = 30): array {
    global $P;
    $cid = casamentoAtual();
    // As marcas de «a condição passou» (tipo 'fim') não são alertas: são o
    // registo de que a chave se libertou, e não têm nada para ninguém ler.
    $r = @$conn->query("SELECT * FROM {$P}bar_alertas WHERE casamento_id=$cid
                        AND tipo <> 'fim'
                        ORDER BY estado <> 'aberto', id DESC LIMIT " . max(1, min(200, $quantos)));
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['id' => (int)$x['id'], 'regra_id' => $x['regra_id'] === null ? null : (int)$x['regra_id'],
                  'tipo' => $x['tipo'], 'nivel' => $x['nivel'], 'chave' => $x['chave'],
                  'situacao' => json_decode((string)$x['situacao'], true) ?: [],
                  'sugestao' => json_decode((string)$x['sugestao'], true) ?: [],
                  'estado' => $x['estado'], 'decidido_por' => $x['decidido_por'],
                  'decidido_em' => $x['decidido_em'], 'nota' => $x['nota'],
                  'criado_em' => $x['criado_em']];
    }
    return $out;
}

/**
 * O caudal da copa, para ela se ver a si própria (§8.2).
 *
 * Enquanto estiver dentro do caudal ninguém dá por ele; a copa vê-o a subir e
 * pode afrouxá-lo ou apertá-lo antes de a espera chegar às mesas.
 */
function barCaudal(mysqli $conn): ?array {
    foreach (barLimites($conn) as $l) {
        if ($l['sujeito'] !== 'casa' || $l['janela_min'] <= 0) continue;
        $c = barConsumo($conn, $l, 0, 0);
        return ['id' => (int)$l['id'], 'usado' => $c['usado'],
                'limite' => (int)$l['quantidade'], 'janela_min' => (int)$l['janela_min'],
                'unidade' => $l['unidade'],
                'cheio' => $c['usado'] >= (int)$l['quantidade']];
    }
    return null;
}

/** Segundos em palavras: «3 minutos», «1h20», «40 segundos». */
function barRelogio(int $s): string {
    if ($s <= 0)   return 'um instante';
    if ($s < 60)   return $s . ' segundos';
    $m = (int)round($s / 60);
    if ($m < 60)   return $m . ($m === 1 ? ' minuto' : ' minutos');
    $h = intdiv($m, 60); $r = $m % 60;
    return $h . 'h' . str_pad((string)$r, 2, '0', STR_PAD_LEFT);
}

/**
 * Pode esta pessoa fazer um pedido, agora?
 *
 * Os limites de unidade `pedidos` não são de bebida nenhuma — são sobre o ACTO
 * de pedir («um pedido de 20 em 20 minutos»), e por isso não cabem no veredicto
 * de um item: travam a página inteira, e é assim que se mostram. Sem isto, uma
 * regra de pedidos ficava escrita e não travava coisa nenhuma.
 *
 * Devolve null quando a pessoa pode pedir.
 */
function barVeredictoPedido(mysqli $conn, int $convidadoId, int $conviteId): ?array {
    $pior = null;
    foreach (barLimites($conn) as $l) {
        if ($l['unidade'] !== 'pedidos') continue;
        // Contadas em pedidos, valem as da pessoa E as da casa: as duas travam
        // o mesmo gesto — carregar em «Pedir» — e por isso dizem-se no mesmo
        // sítio, que é a faixa que fecha a página inteira. Faltar aqui a da
        // casa era a metade que fazia as duas telas discordarem.
        if ($l['sujeito'] === 'convidado') {
            // A quem se aplica: a esta pessoa, ao convite dela, ou a toda a gente.
            if ($l['alvo_convidado_id'] !== null && $l['alvo_convidado_id'] !== $convidadoId) continue;
            if ($l['alvo_convite_id'] !== null && $l['alvo_convite_id'] !== $conviteId) continue;
        }

        $c = barConsumo($conn, $l, $convidadoId, $conviteId);
        if ((int)$l['quantidade'] > 0 && $c['usado'] < (int)$l['quantidade']) continue;
        $s = barEspera($l, $c['mais_velho']);
        if ($pior === null || $s > $pior['espera_s']) {
            $pior = ['espera_s' => $s, 'mensagem' => $l['mensagem'] ?: '',
                     // O tom muda com o dono da regra: quem esbarra no caudal
                     // da copa não pediu de mais, e não se lhe fala como se
                     // tivesse pedido (§8.2).
                     'travao' => (int)$l['quantidade'] === 0 ? 'proibido'
                               : ($l['sujeito'] === 'casa' ? 'casa' : 'ritmo')];
        }
    }
    return $pior;
}

/** O que se diz a quem já pediu há pouco (§9). */
function barTextoPedido(array $v): string {
    if ($v['mensagem'] !== '') return $v['mensagem'];
    if ($v['travao'] === 'proibido') {
        return 'Os seus pedidos passam agora por um garçom. Chame um — ele trata disso.';
    }
    if ($v['travao'] === 'casa') {
        // A culpa não é de quem lê. Dizer-lhe «fica bem assim» quando é a copa
        // que está cheia é acusá-lo de uma coisa que ele não fez.
        return 'A copa está a dar vazão aos pedidos que já tem. Volte a tentar '
             . 'daqui a ' . barRelogio($v['espera_s']) . '.';
    }
    return 'Fica bem assim por uns minutos. O próximo pedido abre daqui a '
         . barRelogio($v['espera_s']) . '.';
}

/**
 * Até três alternativas da mesma gaveta que passem TODOS os testes agora.
 *
 * Sugerir o que também está travado é pior do que não sugerir nada — manda a
 * pessoa bater com o nariz numa segunda porta fechada.
 */
function barAlternativas(array $itens, array $travada): array {
    $out = [];
    foreach ($itens as $i) {
        if ((int)$i['id'] === (int)$travada['id']) continue;
        if ((int)($i['categoria_id'] ?? 0) !== (int)($travada['categoria_id'] ?? 0)) continue;
        if ((int)$i['pode_pedir'] <= 0) continue;
        $out[] = ['id' => (int)$i['id'], 'nome' => $i['nome']];
        if (count($out) >= 3) break;
    }
    return $out;
}

// ---- o menu e o stock ---------------------------------------

/** As gavetas do menu. */
function barCategorias(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT id, nome, ordem, cor FROM {$P}bar_categorias
                        WHERE casamento_id=$cid ORDER BY ordem, nome");
    $out = [];
    // Os números saem do MySQL como texto. As bebidas já vêm com o seu
    // categoria_id em inteiro, e uma comparação estrita entre "1" e 1 no
    // browser não junta bebida nenhuma à sua gaveta — o menu ficava vazio.
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['id' => (int)$x['id'], 'nome' => $x['nome'],
                  'ordem' => (int)$x['ordem'], 'cor' => $x['cor']];
    }
    return $out;
}

/**
 * As bebidas, com as três contas feitas.
 *
 * $tudo=true traz também as escondidas — é o que a montagem e a copa querem
 * ver; o convidado só vê o que está no menu e com que se possa contar.
 */
function barItens(mysqli $conn, bool $tudo = false): array {
    global $P;
    $cid = casamentoAtual();
    $onde = $tudo ? '' : " AND i.estado='ativo'";
    $r = @$conn->query("SELECT i.*, c.nome AS categoria, c.cor AS categoria_cor, c.ordem AS cat_ordem
                        FROM {$P}bar_itens i
                        LEFT JOIN {$P}bar_categorias c ON c.id = i.categoria_id AND c.casamento_id = i.casamento_id
                        WHERE i.casamento_id=$cid$onde
                        ORDER BY COALESCE(c.ordem, 9999), c.nome, i.ordem, i.nome");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $x['id'] = (int)$x['id'];
        $x['categoria_id'] = $x['categoria_id'] === null ? null : (int)$x['categoria_id'];
        $x['stock'] = (int)$x['stock'];
        $x['reservado'] = (int)$x['reservado'];
        $x['disponivel'] = max(0, $x['stock'] - $x['reservado']);
        $x['max_por_pedido'] = max(1, (int)$x['max_por_pedido']);
        // O limiar de «a acabar» é da BEBIDA, e vai com ela para todos os
        // ecrãs: a montagem põe a marca, a copa pinta o semáforo, e os dois
        // dizem a mesma coisa porque leem o mesmo número.
        $x['stock_minimo'] = max(0, (int)($x['stock_minimo'] ?? 8));
        $x['a_acabar'] = $x['disponivel'] <= $x['stock_minimo'];
        $x['alcoolico'] = (int)$x['alcoolico'];
        // Com quantas a noite abriu, e quanto disso resta. É outra pergunta que
        // não a do `stock_minimo`: cinco whiskies é uma emergência e cinco
        // águas não é nada (isso é o limiar, em unidades), mas «resta 15% do
        // que havia» diz-se igual para as duas. `null` enquanto o bar não
        // abrir — uma percentagem sobre uma noite que não começou é um número
        // inventado, e um número inventado num painel é pior do que um vazio.
        $x['base_noite'] = max(0, (int)($x['base_noite'] ?? 0));
        $x['percentagem'] = $x['base_noite'] > 0
            ? max(0, min(100, (int)round($x['disponivel'] / $x['base_noite'] * 100)))
            : null;
        // Quanto é que se pode pedir DESTE item, agora, sem olhar a ninguém:
        // o que há e o que cabe num pedido. É o que a copa e a montagem veem.
        $x['pode_pedir'] = min($x['disponivel'], $x['max_por_pedido']);
        $out[] = $x;
    }
    return $out;
}

/**
 * O mesmo menu, mas visto POR ALGUÉM: com os limites aplicados.
 *
 * `pode_pedir` deixa de ser «o que há» e passa a ser «o que esta pessoa pode
 * levar agora». Quando é zero, vem também o porquê (`travao`), quanto falta
 * (`espera_s`) e o que se pode beber entretanto (`alternativas`) — que é o que
 * transforma uma porta fechada numa sugestão (§9).
 */
function barItensPara(mysqli $conn, int $convidadoId, int $conviteId): array {
    $itens = barItens($conn);
    $ritmo = barRitmoDaCasa($conn);
    foreach ($itens as &$i) {
        $v = barVeredicto($conn, $i, $convidadoId, $conviteId, $ritmo);
        $i['pode_pedir'] = $v['pode'];
        $i['travao']     = $v['travao'];
        $i['espera_s']   = $v['espera_s'];
        $i['aviso']      = $v['mensagem'];
    }
    unset($i);
    // As alternativas só se calculam depois de todos terem veredicto: sugerir
    // uma bebida que também está travada é pior do que não sugerir nada.
    foreach ($itens as &$i) {
        $i['alternativas'] = $i['pode_pedir'] > 0 || $i['travao'] === 'casa'
            ? [] : barAlternativas($itens, $i);
    }
    unset($i);
    return $itens;
}

/** Uma bebida, ou null. */
function barItem(mysqli $conn, int $id): ?array {
    foreach (barItens($conn, true) as $i) if ((int)$i['id'] === $id) return $i;
    return null;
}

/**
 * Mexe no stock e escreve porquê.
 *
 * A coluna é a leitura barata; o livro-razão é a verdade contra a qual se
 * confere. Nunca se mexe numa sem escrever no outro.
 */
function barMoverStock(mysqli $conn, int $itemId, int $delta, string $motivo,
                       ?int $pedidoId = null, string $nota = ''): void {
    global $P;
    $cid = casamentoAtual();
    if ($delta !== 0) {
        $st = $conn->prepare("UPDATE {$P}bar_itens SET stock = GREATEST(0, stock + ?)
                              WHERE casamento_id=? AND id=?");
        if ($st) { $st->bind_param('iii', $delta, $cid, $itemId); @$st->execute(); }
        // A base da noite nunca fica abaixo do que há. É o denominador da
        // percentagem («restam 15% do gin»), e uma regra só: se o stock subiu
        // acima dela, ela sobe atrás — chegaram mais caixas, ou a contagem
        // achou garrafas que já lá estavam. Sem isto a percentagem passava dos
        // 100%, que é um número que ninguém sabe ler.
        //
        // Para BAIXO nunca desce: menos garrafas do que a noite tinha é
        // exactamente a notícia que a percentagem existe para dar.
        if ($delta > 0) {
            @$conn->query("UPDATE {$P}bar_itens SET base_noite = stock
                           WHERE casamento_id=$cid AND id=$itemId AND base_noite < stock");
        }
    }
    $quem = (string)(utilizadorAtual() ?? '');
    $st = $conn->prepare("INSERT INTO {$P}bar_stock_mov
            (casamento_id,item_id,delta,motivo,pedido_id,utilizador,nota,criado_em)
            VALUES (?,?,?,?,?,?,?,NOW())");
    if ($st) {
        $st->bind_param('iiisiss', $cid, $itemId, $delta, $motivo, $pedidoId, $quem, $nota);
        @$st->execute();
    }
}

/** Reserva (ou liberta, com sinal negativo) unidades prometidas. */
function barReservar(mysqli $conn, int $itemId, int $q): void {
    global $P;
    $cid = casamentoAtual();
    $st = $conn->prepare("UPDATE {$P}bar_itens SET reservado = GREATEST(0, reservado + ?)
                          WHERE casamento_id=? AND id=?");
    if ($st) { $st->bind_param('iii', $q, $cid, $itemId); @$st->execute(); }
}

// ---- os pedidos ---------------------------------------------

/** Os itens de um pedido. */
function barItensDoPedido(mysqli $conn, int $pedidoId): array {
    global $P;
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT pi.item_id, pi.nome_no_momento, pi.quantidade, i.foto
                          FROM {$P}bar_pedido_itens pi
                          LEFT JOIN {$P}bar_itens i ON i.id = pi.item_id AND i.casamento_id = pi.casamento_id
                          WHERE pi.casamento_id=? AND pi.pedido_id=? ORDER BY pi.id");
    if (!$st) return [];
    $st->bind_param('ii', $cid, $pedidoId);
    if (!$st->execute()) return [];
    $out = [];
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $out[] = ['item_id' => (int)$x['item_id'], 'nome' => $x['nome_no_momento'],
                  'quantidade' => (int)$x['quantidade'], 'foto' => $x['foto']];
    }
    return $out;
}

/** Um pedido, tal como os três ecrãs o querem ver. */
function barPedidoLinha(mysqli $conn, array $p, bool $paraPessoal = false): array {
    $itens = barItensDoPedido($conn, (int)$p['id']);
    $total = 0; foreach ($itens as $i) $total += $i['quantidade'];
    $out = [
        'id'        => (int)$p['id'],
        'codigo'    => $p['codigo_curto'],
        'estado'    => $p['estado'],
        'estado_nome' => barEstados()[$p['estado']] ?? $p['estado'],
        'itens'     => $itens,
        'total'     => $total,
        'mesa'      => $p['mesa_nome'] ?? null,
        'mesa_id'   => $p['mesa_id'] === null ? null : (int)$p['mesa_id'],
        'criado_em' => $p['criado_em'],
        'decidido_em' => $p['decidido_em'],
        'apanhado_em' => $p['apanhado_em'],
        'entregue_em' => $p['entregue_em'],
        'motivo'    => $p['motivo_texto'] ?: ($p['motivo_nome'] ?? null),
        // De quem é a bebida, e quem a pediu — os dois nomes, quando são dois.
        // O convidado também os vê: é a resposta a «esta é a minha?».
        'para'       => $p['convidado_nome'] ?? null,
        'para_id'    => $p['convidado_id'] === null ? null : (int)$p['convidado_id'],
        'pedido_por' => $p['lancou_nome'] ?? null,
        'pedido_por_id' => $p['criado_por_convidado_id'] === null
                         ? null : (int)$p['criado_por_convidado_id'],
    ];
    if ($paraPessoal) {
        // Do lado do pessoal, quem pediu e de onde — que é o que faz o
        // trabalho andar. O convidado não precisa de saber quem decidiu.
        $out += [
            'convidado'   => $p['convidado_nome'] ?? null,
            'convidado_id' => $p['convidado_id'] === null ? null : (int)$p['convidado_id'],
            'convite'     => $p['convite_nome'] ?? null,
            'mesa_qr'     => $p['mesa_qr_nome'] ?? null,
            'criado_por'  => $p['criado_por'],
            'decidido_por' => $p['decidido_por'],
            'entregue_por' => $p['entregue_por'],
            // O que o garçom viu à mesa. Só o pessoal a lê: é uma observação
            // sobre uma pessoa, e mostrá-la a essa pessoa era outra coisa.
            'nota_entrega' => $p['nota_entrega'] ?? null,
            'ip'          => $p['ip'],
        ];
    }
    return $out;
}

/** A consulta de pedidos, com tudo o que os ecrãs mostram. */
function barPedidos(mysqli $conn, string $onde, array $tipos = [], array $vals = []): array {
    global $P;
    $cid = casamentoAtual();
    $sql = "SELECT p.*, m.nome AS mesa_nome, mq.nome AS mesa_qr_nome,
                   g.nome AS convidado_nome, c.nome_exibicao AS convite_nome,
                   lc.nome AS lancou_nome,
                   mo.texto AS motivo_nome
            FROM {$P}bar_pedidos p
            LEFT JOIN {$P}mesas m  ON m.id  = p.mesa_id    AND m.casamento_id = p.casamento_id
            LEFT JOIN {$P}mesas mq ON mq.id = p.mesa_qr_id AND mq.casamento_id = p.casamento_id
            LEFT JOIN {$P}convidados g ON g.id = p.convidado_id AND g.casamento_id = p.casamento_id
            LEFT JOIN {$P}convites c   ON c.id = p.convite_id   AND c.casamento_id = p.casamento_id
            LEFT JOIN {$P}convidados lc ON lc.id = p.criado_por_convidado_id
                                       AND lc.casamento_id = p.casamento_id
            LEFT JOIN {$P}bar_motivos mo ON mo.id = p.motivo_id AND mo.casamento_id = p.casamento_id
            WHERE p.casamento_id=$cid AND $onde";
    $st = $conn->prepare($sql);
    if (!$st) return [];
    if ($tipos) $st->bind_param(implode('', $tipos), ...$vals);
    if (!$st->execute()) return [];
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Um pedido pelo id, ou null. */
function barPedido(mysqli $conn, int $id): ?array {
    $r = barPedidos($conn, 'p.id=? ORDER BY p.id LIMIT 1', ['i'], [$id]);
    return $r[0] ?? null;
}

/** Os motivos de recusa que a copa tem à mão. */
function barMotivos(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT id, texto, ordem FROM {$P}bar_motivos
                        WHERE casamento_id=$cid AND ativo=1 ORDER BY ordem, id");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) $out[] = ['id' => (int)$x['id'], 'texto' => $x['texto']];
    return $out;
}

/**
 * Os tempos da noite, em segundos.
 *
 * Quatro contas, e cada uma diz outra coisa: a análise diz se a copa está a
 * acompanhar; a espera de recolha, se faltam garçons; o percurso, se o
 * salão é grande; e o total é o único que o convidado conhece.
 */
function barTempos(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT
            AVG(TIMESTAMPDIFF(SECOND, criado_em, decidido_em))   AS analise,
            AVG(TIMESTAMPDIFF(SECOND, decidido_em, apanhado_em)) AS recolha,
            AVG(TIMESTAMPDIFF(SECOND, apanhado_em, entregue_em)) AS percurso,
            AVG(TIMESTAMPDIFF(SECOND, criado_em, entregue_em))   AS total,
            COUNT(*) AS n
          FROM {$P}bar_pedidos
          WHERE casamento_id=$cid AND estado='entregue' AND entregue_em IS NOT NULL");
    $x = $r ? $r->fetch_assoc() : [];
    $s = fn($k) => isset($x[$k]) && $x[$k] !== null ? (int)round((float)$x[$k]) : null;
    return ['analise' => $s('analise'), 'recolha' => $s('recolha'),
            'percurso' => $s('percurso'), 'total' => $s('total'), 'n' => (int)($x['n'] ?? 0)];
}

// ============================================================
// A ESTATÍSTICA — o que saiu, a que ritmo, e quanto falta
//
// Serve três perguntas diferentes, e é por isso que não é um número só:
// a copa quer saber se chega até ao fim; os noivos querem saber o que a festa
// bebeu; e o convidado quer saber o que JÁ pediu — e só o dele.
// ============================================================

/** O que saiu, por bebida e por gaveta. */
function barConsumoGeral(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT i.id, i.nome, i.stock, i.reservado,
                               c.nome AS gaveta,
                               COALESCE(SUM(CASE WHEN p.estado='entregue' THEN pi.quantidade END),0) servidas,
                               COALESCE(SUM(CASE WHEN p.estado IN ('em_analise','aprovado','a_caminho')
                                                 THEN pi.quantidade END),0) a_sair
                        FROM {$P}bar_itens i
                        LEFT JOIN {$P}bar_categorias c ON c.id=i.categoria_id AND c.casamento_id=i.casamento_id
                        LEFT JOIN {$P}bar_pedido_itens pi ON pi.item_id=i.id AND pi.casamento_id=i.casamento_id
                        LEFT JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                        WHERE i.casamento_id=$cid
                        GROUP BY i.id ORDER BY servidas DESC, i.nome");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['id' => (int)$x['id'], 'nome' => $x['nome'], 'gaveta' => $x['gaveta'],
                  'servidas' => (int)$x['servidas'], 'a_sair' => (int)$x['a_sair'],
                  'stock' => (int)$x['stock'],
                  'disponivel' => max(0, (int)$x['stock'] - (int)$x['reservado'])];
    }
    return $out;
}

/**
 * Quanto tempo falta até acabar, ao ritmo dos últimos vinte minutos.
 *
 * Não é adivinhação: é uma regra de três com o que saiu há pouco. Vale para
 * decidir se se manda buscar mais gelo ou se se põe um limite — e é por isso
 * que só se calcula para o que já teve saída. Uma bebida parada não «acaba
 * nunca»: simplesmente não se sabe, e diz-se null em vez de um número
 * bonito que ninguém devia acreditar.
 */
function barRutura(mysqli $conn, int $minutos = 20): array {
    global $P;
    $cid = casamentoAtual();
    $m = max(5, min(120, $minutos));
    $r = @$conn->query("SELECT pi.item_id, SUM(pi.quantidade) n
                        FROM {$P}bar_pedido_itens pi
                        JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                        WHERE pi.casamento_id=$cid
                          AND p.estado IN ('em_analise','aprovado','a_caminho','entregue')
                          AND p.criado_em >= (NOW() - INTERVAL $m MINUTE)
                        GROUP BY pi.item_id");
    $ritmo = [];
    if ($r) while ($x = $r->fetch_assoc()) $ritmo[(int)$x['item_id']] = (int)$x['n'];

    $out = [];
    foreach (barItens($conn, true) as $i) {
        $porMin = ($ritmo[$i['id']] ?? 0) / $m;
        $out[] = ['id' => $i['id'], 'nome' => $i['nome'],
                  'disponivel' => $i['disponivel'],
                  'por_hora' => round($porMin * 60, 1),
                  // Minutos até zero. Null quando não há saída: não se sabe.
                  'acaba_em_min' => $porMin > 0 ? (int)floor($i['disponivel'] / $porMin) : null];
    }
    // As que acabam primeiro à frente — é o que a copa precisa de ver.
    usort($out, function ($a, $b) {
        if ($a['acaba_em_min'] === null) return $b['acaba_em_min'] === null ? 0 : 1;
        if ($b['acaba_em_min'] === null) return -1;
        return $a['acaba_em_min'] <=> $b['acaba_em_min'];
    });
    return $out;
}

/** As recusas por motivo: a lista do que correu mal na festa. */
function barRecusas(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT COALESCE(mo.texto, p.motivo_texto, 'sem motivo') motivo, COUNT(*) n
                        FROM {$P}bar_pedidos p
                        LEFT JOIN {$P}bar_motivos mo ON mo.id=p.motivo_id AND mo.casamento_id=p.casamento_id
                        WHERE p.casamento_id=$cid AND p.estado='recusado'
                        GROUP BY motivo ORDER BY n DESC LIMIT 12");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) $out[] = ['motivo' => $x['motivo'], 'n' => (int)$x['n']];
    return $out;
}

/** As bebidas que saíram em cada intervalo de dez minutos, para ver o ritmo. */
function barRitmoHistorico(mysqli $conn, int $horas = 4): array {
    global $P;
    $cid = casamentoAtual();
    $h = max(1, min(12, $horas));
    $r = @$conn->query("SELECT FLOOR(UNIX_TIMESTAMP(p.criado_em)/600)*600 t,
                               SUM(pi.quantidade) n
                        FROM {$P}bar_pedido_itens pi
                        JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                        WHERE pi.casamento_id=$cid
                          AND p.estado IN ('em_analise','aprovado','a_caminho','entregue')
                          AND p.criado_em >= (NOW() - INTERVAL $h HOUR)
                        GROUP BY t ORDER BY t");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['t' => (int)$x['t'], 'n' => (int)$x['n']];
    }
    return $out;
}

/** As mesas que mais pediram — serve para o ano seguinte e para a conta. */
function barPorMesa(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT COALESCE(m.nome,'sem mesa') mesa, SUM(pi.quantidade) n
                        FROM {$P}bar_pedido_itens pi
                        JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                        LEFT JOIN {$P}mesas m ON m.id=p.mesa_id AND m.casamento_id=p.casamento_id
                        WHERE pi.casamento_id=$cid AND p.estado='entregue'
                        GROUP BY mesa ORDER BY n DESC LIMIT 12");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) $out[] = ['mesa' => $x['mesa'], 'n' => (int)$x['n']];
    return $out;
}

/**
 * O que cada convidado bebeu, do mais para o menos.
 *
 * A pergunta a que isto responde não é curiosidade: é «quem é que preciso de
 * ir ver?». Numa festa de cem pessoas há sempre três ou quatro que se
 * destacam, e o resto é uma linha plana — e é justamente o destaque que a copa
 * quer ver, cedo, para poder falar com alguém antes de ser tarde.
 *
 * Conta o ENTREGUE, e não o pedido: uma pessoa que pediu seis e recebeu duas
 * bebeu duas. Doze chegam: a partir daí é uma lista, não é um gráfico.
 */
function barPorConvidado(mysqli $conn, int $limite = 12): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT g.id, g.nome, SUM(pi.quantidade) n
                        FROM {$P}bar_pedido_itens pi
                        JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                        JOIN {$P}convidados g ON g.id=p.convidado_id
                        WHERE pi.casamento_id=$cid AND p.estado='entregue'
                        GROUP BY g.id, g.nome
                        ORDER BY n DESC, g.nome LIMIT " . max(1, min(50, $limite)));
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $out[] = ['id' => (int)$x['id'], 'nome' => $x['nome'], 'n' => (int)$x['n']];
    }
    return $out;
}

/** O estado do bar, para qualquer ecrã do pessoal. */
function barEstadoGeral(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $n = fn(string $sql) => (int)(@$conn->query($sql)->fetch_row()[0] ?? 0);
    return [
        'aberto'     => barAberto($conn),
        // Segundos até a copa sair da pausa. Zero é o caso normal, e a pausa
        // desfaz-se sozinha — por isso é um número que conta para baixo, e não
        // um interruptor que alguém tem de se lembrar de desligar.
        'pausa_s'    => barPausaSegundos($conn),
        // Quantos minutos propor a quem pausa à mão. É a mesma definição que o
        // motor usa quando propõe a pausa — a casa tem UM número, e não um para
        // a máquina e outro para as pessoas.
        'pausa_min'  => max(1, (int)barDef($conn, 'bar.pausa_min')),
        'em_analise' => $n("SELECT COUNT(*) FROM {$P}bar_pedidos WHERE casamento_id=$cid AND estado='em_analise'"),
        'aprovados'  => $n("SELECT COUNT(*) FROM {$P}bar_pedidos WHERE casamento_id=$cid AND estado='aprovado'"),
        'a_caminho'  => $n("SELECT COUNT(*) FROM {$P}bar_pedidos WHERE casamento_id=$cid AND estado='a_caminho'"),
        'entregues'  => $n("SELECT COUNT(*) FROM {$P}bar_pedidos WHERE casamento_id=$cid AND estado='entregue'"),
        'bebidas_entregues' => $n("SELECT COALESCE(SUM(pi.quantidade),0)
                                   FROM {$P}bar_pedido_itens pi
                                   JOIN {$P}bar_pedidos p ON p.id = pi.pedido_id AND p.casamento_id = pi.casamento_id
                                   WHERE pi.casamento_id=$cid AND p.estado='entregue'"),
    ];
}

// ============================================================
// O CONVIDADO — sem sessão, pelo token da mesa
// ============================================================

if ($acao === 'bar_mesa') {
    // A porta: que mesa é esta, se o bar está aberto, e quem este telemóvel já
    // é (se já é alguém).
    $mesa = barPortaPublica($conn);
    $eu = barQuemSou($conn);
    $quem = $eu ? barConvidado($conn, $eu) : null;
    ok(['mesa' => ['id' => (int)$mesa['id'], 'nome' => $mesa['nome']],
        'aberto' => barAberto($conn),
        'mensagem_fechado' => barDef($conn, 'bar.mensagem_fechado'),
        'procura_min' => max(1, (int)barDef($conn, 'bar.procura_min')),
        'eu' => $quem ? ['id' => (int)$quem['id'], 'nome' => $quem['nome'],
                         'convite' => $quem['nome_exibicao'],
                         'mesa_id' => $quem['mesa_id'] === null ? null : (int)$quem['mesa_id']] : null]);
}

if ($acao === 'bar_procurar') {
    // A caixa de procura. A partir de quatro letras, até oito nomes — nem
    // menos (a caixa seria um índice da festa) nem mais (uma lista para
    // folhear). Nunca diz onde a pessoa está sentada.
    barPortaPublica($conn);
    $cid = casamentoAtual();
    $q = barChave((string)($_GET['q'] ?? ''));
    $min = max(1, (int)barDef($conn, 'bar.procura_min'));
    if (mb_strlen($q) < $min) {
        erro('Escreva pelo menos ' . $min . ' letras do seu nome.');
    }
    $r = @$conn->query("SELECT g.id, g.nome, c.nome_exibicao
                        FROM {$P}convidados g
                        JOIN {$P}convites c ON c.id = g.convite_id AND c.casamento_id = g.casamento_id
                        WHERE g.casamento_id=$cid AND " . soVivos($conn, 'c') . "
                        ORDER BY g.nome LIMIT 2000");
    $achados = [];
    if ($r) while ($g = $r->fetch_assoc()) {
        if (strpos(barChave((string)$g['nome']), $q) === false) continue;
        $achados[] = ['id' => (int)$g['id'], 'nome' => $g['nome'],
                      'convite' => $g['nome_exibicao']];
        if (count($achados) >= 8) break;
    }
    ok(['nomes' => $achados]);
}

if ($acao === 'bar_sou') {
    // «Sou eu» — o telemóvel fica preso a este nome.
    //
    // É a única barreira real contra pedir em nome de outro, e é honesto dizer
    // porquê: sem link no convite, o nome deixou de ser segredo (§5.2). O que
    // fica de pé é que quem quiser pedir por outro tem de o fazer do SEU
    // telemóvel, o que deixa rasto e a copa vê.
    barPortaPublica($conn);
    $d = corpo();
    $id = (int)($d['convidado_id'] ?? 0);
    $g = barConvidado($conn, $id);
    if (!$g) erro('Não encontrámos esse nome.');

    $antes = barQuemSou($conn);
    $conviteNovo = (int)$g['convite_id'];
    $troca = null;
    if ($antes && $antes !== $id) {
        $ga = barConvidado($conn, $antes);
        // Trocar para outro nome do MESMO convite é uso normal e não se
        // comenta: o telemóvel da família é um só, e a mãe pede pelo filho.
        // Para outro convite é onde a fraude vive — e também o telemóvel
        // emprestado a quem ficou sem bateria.
        $mesmoConvite = $ga && (int)$ga['convite_id'] === $conviteNovo;
        if (!$mesmoConvite) {
            if (barDef($conn, 'bar.trocar_nome') !== '1') {
                erro('Este telemóvel já está a pedir por ' . ($ga['nome'] ?? 'outra pessoa')
                   . '. Chame um garçom — ele resolve isto num instante.');
            }
            $troca = ['de' => $ga['nome'] ?? '?', 'para' => $g['nome']];
        }
    }

    barPrender($conn, $id, $conviteNovo);
    if ($troca) {
        registar($conn, 'bar_trocou_nome', $troca['para'],
                 'este telemóvel pedia por ' . $troca['de']);
    }
    ok(['eu' => ['id' => $id, 'nome' => $g['nome'], 'convite' => $g['nome_exibicao'],
                 'mesa_id' => $g['mesa_id'] === null ? null : (int)$g['mesa_id']]]);
}

if ($acao === 'bar_mesas') {
    // As mesas, para escolher onde entregar: as pessoas trocam de lugar.
    //
    // Duas portas para a mesma lista. O convidado entra pela pública, com o
    // código da mesa; o pessoal do bar entra pela sessão — precisa dela para
    // mudar a mesa de um pedido, e não tem código nenhum na mão.
    if (podeCopa() || podeEntregar()) { barCid(); }
    else { barPortaPublica($conn); }
    $cid = casamentoAtual();
    barGarantirTokens($conn, $cid);
    $r = @$conn->query("SELECT id, nome FROM {$P}mesas WHERE casamento_id=$cid
                        ORDER BY (especial='noivos') DESC, nome");
    $out = [];
    if ($r) while ($m = $r->fetch_assoc()) $out[] = ['id' => (int)$m['id'], 'nome' => $m['nome']];
    ok(['mesas' => $out]);
}

if ($acao === 'bar_por_quem') {
    // «Vou pedir por esta pessoa» — confirma-se ANTES de escolher as bebidas.
    //
    // Existe para não haver duas más soluções: confirmar o código com um
    // pedido vazio gastaria uma tentativa do travão por cada pedido de
    // verdade (cinco erros trancam o convite, e assim bastavam dois enganos),
    // e deixar a conferência para o fim mandava a pessoa escolher as bebidas
    // todas para só então descobrir que não pode pedir por aquele nome.
    barPortaPublica($conn);
    $eu = barQuemSou($conn);
    if (!$eu) erro('Diga-nos primeiro quem é.');
    $mim = barConvidado($conn, $eu);
    if (!$mim) erro('Não encontrámos o seu nome.');
    [$paraId, $g, $porOutro] = barParaQuem($conn, $eu, $mim, corpo());
    ok(['para' => ['id' => $paraId, 'nome' => $g['nome'], 'convite' => $g['nome_exibicao'],
                   'eu' => !$porOutro,
                   'mesa_id' => $g['mesa_id'] === null ? null : (int)$g['mesa_id']]]);
}

if ($acao === 'bar_menu') {
    // O menu, como ESTE convidado o vê: com os limites dele já aplicados.
    //
    // Ou como o vê a pessoa POR QUEM ele está a pedir: com `por`, o menu é o
    // de quem vai beber — os tectos dessa pessoa, as esperas dessa pessoa, as
    // bebidas que ela não pode. Um menu que mostrasse as MINHAS quotas e
    // depois recusasse o pedido no fim seria uma promessa a fingir, e a recusa
    // chegaria com a bebida já escolhida (§9).
    barPortaPublica($conn);
    $eu = barQuemSou($conn);
    if (!$eu) erro('Diga-nos primeiro quem é.');
    $mim = barConvidado($conn, $eu);
    if (!$mim) erro('Não encontrámos o seu nome.');
    // Aqui não se pede o código: o menu não é um pedido, e trancar a leitura
    // com o PIN dava uma janela para o adivinhar sem gastar tentativas. O que
    // o PIN guarda é o acto de pedir, e é lá que ele é conferido.
    $por = (int)($_GET['por'] ?? 0);
    if ($por > 0) $eu = $por;
    $g = barConvidado($conn, $eu);
    if (!$g) erro('Não encontrámos essa pessoa na lista.');
    $porOutro = (int)$g['id'] !== (int)$mim['id'];
    $ritmo = barRitmoDaCasa($conn);
    ok(['para' => ['id' => (int)$g['id'], 'nome' => $g['nome'],
                   'convite' => $g['nome_exibicao'], 'eu' => !$porOutro,
                   'mesa_id' => $g['mesa_id'] === null ? null : (int)$g['mesa_id']],
        'categorias' => barCategorias($conn),
        'itens'  => array_map(fn($i) => [
            'id' => $i['id'], 'nome' => $i['nome'], 'descricao' => $i['descricao'],
            'foto' => $i['foto'], 'foto_pos' => $i['foto_pos'],
            'categoria_id' => $i['categoria_id'], 'categoria' => $i['categoria'],
            'categoria_cor' => $i['categoria_cor'], 'alcoolico' => $i['alcoolico'],
            'disponivel' => $i['disponivel'], 'pode_pedir' => $i['pode_pedir'],
            // Porque não pode, quanto falta, e o que sai já em vez disto.
            'travao' => $i['travao'], 'espera_s' => $i['espera_s'],
            'aviso' => $i['aviso'], 'alternativas' => $i['alternativas'],
        ], barItensPara($conn, $eu, (int)$g['convite_id'])),
        // O ritmo da casa é de todos, e diz-se à parte: a mensagem que o
        // convidado lê muda de tom quando é a copa que está cheia, e não ele
        // que pediu de mais (§8.2).
        'ritmo' => $ritmo ? ['espera_s' => $ritmo['segundos'],
                             'mensagem' => $ritmo['mensagem']] : null,
        // E o travão do acto de pedir, que não é de bebida nenhuma: trava a
        // página inteira, e é assim que se mostra.
        'pedido' => (function () use ($conn, $eu, $g) {
            $v = barVeredictoPedido($conn, $eu, (int)$g['convite_id']);
            return $v ? ['espera_s' => $v['espera_s'], 'texto' => barTextoPedido($v)] : null;
        })(),
        // A pausa da copa diz-se AQUI, e não só na recusa: o menu que deixasse
        // escolher três bebidas para depois responder «estamos em pausa» é a
        // mesma promessa a fingir que o resto desta acção existe para evitar.
        // Vai com os segundos que faltam para o relógio da página os contar.
        'pausa' => (function () use ($conn) {
            $s = barPausaSegundos($conn);
            if (!$s) return null;
            return ['espera_s' => $s,
                    'mensagem' => barMensagem($conn, 'copa_pausada',
                                              ['{TEMPO}' => barRelogio($s)])
                      ?: 'A copa está a recuperar do movimento.'];
        })(),
        'aberto' => barAberto($conn)]);
}

if ($acao === 'bar_pedir') {
    // O pedido. O que a página mostrou é uma promessa; o que aqui se calcula é
    // a decisão — a disponibilidade volta a conferir-se no momento.
    barPortaPublica($conn);
    $cid = casamentoAtual();
    if (!barAberto($conn)) {
        erro(barMensagem($conn, 'copa_fechada') ?: 'A copa está fechada neste momento.');
    }
    // A pausa. Diz-se quanto falta, e não «feche a página»: quem está com o
    // telemóvel na mão quer saber se vale a pena esperar — e vale, porque a
    // copa reabre sozinha.
    if ($falta = barPausaSegundos($conn)) {
        erro(barMensagem($conn, 'copa_pausada', ['{TEMPO}' => barRelogio($falta)])
          ?: 'A copa está a recuperar do movimento. Volte a tentar daqui a '
           . barRelogio($falta) . '.');
    }
    $eu = barQuemSou($conn);
    if (!$eu) erro('Diga-nos primeiro quem é.');
    $mim = barConvidado($conn, $eu);
    if (!$mim) erro('Não encontrámos o seu nome.');

    $d = corpo();
    // Por mim, ou por quem mo pediu à mesa. A quota é sempre de quem bebe.
    [$paraId, $g, $porOutro] = barParaQuem($conn, $eu, $mim, $d);
    $pedidos = is_array($d['itens'] ?? null) ? $d['itens'] : [];
    $mesaId  = (int)($d['mesa_id'] ?? 0);
    $mesaQr  = (int)($d['mesa_qr_id'] ?? 0);

    // Os limites voltam a conferir-se aqui, um a um, contra o estado deste
    // instante. O menu que a pessoa tem aberto pode ter dois minutos, e nesses
    // dois minutos a copa pode ter-lhe posto uma regra ou o caudal ter enchido.
    $ritmo = barRitmoDaCasa($conn);
    $conviteId = (int)$g['convite_id'];
    // Primeiro o travão do ACTO de pedir («um pedido de 20 em 20 minutos»),
    // que é da pessoa e não de bebida nenhuma.
    $vp = barVeredictoPedido($conn, $paraId, $conviteId);
    if ($vp) erro(barTextoPedido($vp));
    $linhas = [];
    foreach ($pedidos as $li) {
        $iid = (int)($li['item_id'] ?? 0);
        $q   = (int)($li['quantidade'] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;
        $item = barItem($conn, $iid);
        if (!$item || $item['estado'] !== 'ativo') erro('Uma das bebidas já não está no menu.');
        $v = barVeredicto($conn, $item, $paraId, $conviteId, $ritmo);
        if ($q > $v['pode']) {
            // A recusa fala como a página fala: diz o que se passa e quanto
            // falta, e não «limite excedido».
            erro(barTextoTravao($conn, $item, $v, $q, $g['nome'] ?? ''));
        }
        $linhas[] = [$item, $q];
    }
    if (!$linhas) erro('Escolha pelo menos uma bebida.');

    $codigo = barCodigoCurto();
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $disp = hash('sha256', (string)($_COOKIE['bar_disp'] ?? ''));
    // O dispositivo e o IP são os de QUEM LANÇOU, e não os de quem bebe: são a
    // prova de onde o pedido partiu, e trocá-los apagava-a. Quem bebe está no
    // convidado_id; quem lançou, quando não é a mesma pessoa, na coluna nova.
    $st = $conn->prepare("INSERT INTO {$P}bar_pedidos
            (casamento_id,codigo_curto,convidado_id,convite_id,mesa_id,mesa_qr_id,
             estado,dispositivo,ip,criado_por_convidado_id,criado_em)
            VALUES (?,?,?,?,?,?,'em_analise',?,?,?,NOW())");
    $mesaN = $mesaId ?: null; $mesaQrN = $mesaQr ?: null;
    $lancou = $porOutro ? $eu : null;
    $st->bind_param('isiiiissi', $cid, $codigo, $paraId, $conviteId, $mesaN, $mesaQrN,
                    $disp, $ip, $lancou);
    if (!@$st->execute()) erro('Não foi possível enviar o pedido.');
    $pid = $conn->insert_id;

    foreach ($linhas as [$item, $q]) {
        $si = $conn->prepare("INSERT INTO {$P}bar_pedido_itens
                (casamento_id,pedido_id,item_id,nome_no_momento,quantidade) VALUES (?,?,?,?,?)");
        $iid = (int)$item['id']; $nome = (string)$item['nome'];
        $si->bind_param('iiisi', $cid, $pid, $iid, $nome, $q);
        @$si->execute();
    }
    $resumo = implode(', ', array_map(fn($l) => $l[1] . '× ' . $l[0]['nome'], $linhas));
    if ($porOutro) {
        registar($conn, 'bar_pedido_amigo', $g['nome'],
                 '#' . $codigo . ' · ' . $resumo . ' · lançado por ' . $mim['nome']);
    } else {
        registar($conn, 'bar_pedido', $g['nome'], '#' . $codigo . ' · ' . $resumo);
    }
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $pid))]);
}

if ($acao === 'bar_meus_pedidos') {
    // Os meus, e também os que lancei por outros: quem pediu a cerveja pela
    // mãe é quem vai querer saber se ela já chegou. Ela vê-o na mesma, no
    // telemóvel dela — o pedido é dela.
    barPortaPublica($conn);
    $eu = barQuemSou($conn);
    if (!$eu) ok(['pedidos' => []]);
    $ps = barPedidos($conn, '(p.convidado_id=? OR p.criado_por_convidado_id=?)
                             ORDER BY p.id DESC LIMIT 20', ['i', 'i'], [$eu, $eu]);
    ok(['pedidos' => array_map(fn($p) => barPedidoLinha($conn, $p), $ps),
        'aberto' => barAberto($conn)]);
}

if ($acao === 'bar_cancelar') {
    // Desistir, enquanto ninguém decidiu.
    barPortaPublica($conn);
    $cid = casamentoAtual();
    $eu = barQuemSou($conn);
    $id = (int)(corpo()['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    // Meu, ou lançado por mim: quem pediu pelo vizinho e se enganou na bebida
    // tem de o poder desfazer — a alternativa era pedir ao vizinho que
    // desistisse de um pedido que ele não fez.
    $meu = (int)$p['convidado_id'] === $eu
        || (int)($p['criado_por_convidado_id'] ?? 0) === $eu;
    if (!$p || !$meu) erro('Esse pedido não é seu.');
    if ($p['estado'] !== 'em_analise') erro('Esse pedido já foi decidido.');
    @$conn->query("UPDATE {$P}bar_pedidos SET estado='cancelado' WHERE casamento_id=$cid AND id=$id");
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $id))]);
}

// ============================================================
// A COPA — a fila, a decisão, o stock e a montagem do menu
// ============================================================

/** Todas as notas de entrega de uma pessoa, da mais recente para trás. */
function barNotasDe(mysqli $conn, int $convidadoId): array {
    global $P;
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT p.nota_entrega, p.codigo_curto, p.entregue_em, p.entregue_por
                          FROM {$P}bar_pedidos p
                          WHERE p.casamento_id=? AND p.convidado_id=?
                            AND p.nota_entrega IS NOT NULL AND p.nota_entrega <> ''
                          ORDER BY p.entregue_em DESC, p.id DESC LIMIT 50");
    if (!$st) return [];
    $st->bind_param('ii', $cid, $convidadoId);
    if (!$st->execute()) return [];
    $out = [];
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) {
        $out[] = ['texto' => (string)$x['nota_entrega'],
                  'codigo' => (string)$x['codigo_curto'],
                  'quando' => $x['entregue_em'],
                  'quem' => (string)($x['entregue_por'] ?? '')];
    }
    return $out;
}

/**
 * As notas que os garçons escreveram, por convidado.
 *
 * O garçom é o único do bar que fala com o convidado. O que ele traz da mesa
 * — «pediu para não lhe servirem mais», «está com os miúdos», «não era para
 * ele, era para a mãe» — não tinha onde ficar, e portanto morria ali. Agora
 * fica no pedido, e a copa lê-o no momento em que serve: quando essa pessoa
 * pede outra vez.
 *
 * Só as últimas três de cada um: uma nota de há quatro horas já não descreve
 * a mesma noite, e uma lista comprida por cima de um pedido deixa de se ler.
 */
function barNotasDeEntrega(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $r = @$conn->query("SELECT p.convidado_id, p.codigo_curto, p.nota_entrega,
                               p.entregue_por, p.entregue_em
                        FROM {$P}bar_pedidos p
                        WHERE p.casamento_id=$cid AND p.convidado_id IS NOT NULL
                          AND p.nota_entrega IS NOT NULL AND p.nota_entrega <> ''
                        ORDER BY p.entregue_em DESC, p.id DESC");
    $out = [];
    if ($r) while ($x = $r->fetch_assoc()) {
        $g = (int)$x['convidado_id'];
        if (!isset($out[$g])) $out[$g] = [];
        if (count($out[$g]) >= 3) continue;
        $out[$g][] = ['codigo' => $x['codigo_curto'], 'texto' => $x['nota_entrega'],
                      'quem' => $x['entregue_por'], 'quando' => $x['entregue_em']];
    }
    // Chaves em texto: um objecto JSON com chaves numéricas volta como array
    // no JavaScript, e a copa procura por id.
    $txt = [];
    foreach ($out as $k => $v) $txt[(string)$k] = $v;
    return $txt;
}

if ($acao === 'bar_estado') {
    // Tudo o que a copa e a montagem desenham, numa leitura só. Um ecrã que
    // se refresca de dez em dez segundos não pode pedir cinco coisas de cada
    // vez — e a copa quer ver a fila e o stock lado a lado.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    // O motor mede AQUI, na leitura que a copa já faz de oito em oito
    // segundos. Sem processo à parte: um processo a correr sozinho numa noite
    // de festa é uma peça a mais para falhar, e ninguém a estaria a ver falhar
    // (§31.2). Não aplica nada — escreve alertas, e o copeiro decide.
    barSugestoes($conn);
    // Os que estão vivos primeiro, e depois os últimos resolvidos: a copa
    // precisa de poder voltar atrás a um que acabou de recusar.
    $vivos = barPedidos($conn, "p.estado IN ('em_analise','aprovado','a_caminho','falhou')
                                ORDER BY FIELD(p.estado,'em_analise','falhou','a_caminho','aprovado'),
                                         p.criado_em, p.id");
    $fim = barPedidos($conn, "p.estado IN ('entregue','recusado','cancelado')
                              ORDER BY p.id DESC LIMIT 40");
    ok(['estado'     => barEstadoGeral($conn),
        'defs'       => barDefsAtuais($conn),
        'categorias' => barCategorias($conn),
        'itens'      => barItens($conn, true),
        'motivos'    => barMotivos($conn),
        'tempos'     => barTempos($conn),
        'agora'      => date('c'),
        // TODAS as regras, e não só as que valem agora: o painel das Regras do
        // Bar tem de mostrar a que foi marcada para as 2h — senão escrevia-se
        // e ela desaparecia, e a única leitura possível era «não guardou».
        // Cada linha traz o seu `vigor`, e o ecrã diz qual é qual.
        'regras'     => array_map(fn($l) => barRegraLinha($conn, $l), barLimitesTodos($conn)),
        // Os que deixaram de caber numa regra posta depois de terem entrado.
        // Não se recusam sozinhos: quem pôs a regra pode querer servir o copo
        // que já estava pedido.
        'fora'       => barFilaContraRegras($conn),
        'caudal'     => barCaudal($conn),
        // O que o motor propôs e ainda não foi respondido, mais os últimos
        // resolvidos. O painel é da fase 3; a leitura entra já, para o motor
        // se poder ver a trabalhar.
        'alertas'    => barAlertas($conn),
        // O que o casal escreveu para cada situação, e o vocabulário do
        // editor: as situações que existem e as variáveis que se podem usar.
        // Vem do servidor porque é ele quem as substitui — duas listas, uma de
        // cada lado, acabavam a discordar.
        'mensagens'  => barMensagens($conn),
        'situacoes'  => barSituacoes(),
        'variaveis'  => barVariaveis(),
        'fabrica'    => barTextosFabrica(),
        // Telemóveis que valem uma segunda vista. Não acusam ninguém: a copa
        // conhece a sala e decide — o sistema limita-se a apontar (§5.3).
        'bandeiras'  => barBandeiras($conn),
        // O que os garçons trouxeram da mesa, por convidado. A copa vê-o
        // colado ao pedido seguinte dessa pessoa — que é o momento em que a
        // observação vale alguma coisa (§27).
        'notas'      => barNotasDeEntrega($conn),
        'fila'       => array_map(fn($p) => barPedidoLinha($conn, $p, true), $vivos),
        'resolvidos' => array_map(fn($p) => barPedidoLinha($conn, $p, true), $fim)]);
}

if ($acao === 'bar_decidir') {
    // Aprovar (e reservar) ou recusar (com motivo).
    $cid = barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if ($p['estado'] !== 'em_analise') erro('Esse pedido já foi decidido.');
    $quem = (string)(utilizadorAtual() ?? '');
    $itens = barItensDoPedido($conn, $id);

    if (($d['decisao'] ?? '') === 'aprovar') {
        /* ---- aprovar, inteiro ou em parte ------------------------
           Havia duas portas, e a vida do bar tem três. «Pediu quatro cervejas
           e só há duas» não era nem aprovar (não se serve o que não há) nem
           recusar (recusar quatro por causa de duas é servir zero, e a pessoa
           volta a pedir daí a um minuto). A terceira é a que se faz sempre ao
           balcão: serve-se o que se pode e diz-se porquê.

           O `cortes` chega como {item_id: quantidade nova}, e a quantidade
           nova pode ser 0 — que é tirar a linha do pedido. Um corte pede
           motivo pela mesma razão que uma recusa: quem recebe menos do que
           pediu tem direito a saber porquê, e sem essa frase pede outra vez. */
        $cortes = is_array($d['cortes'] ?? null) ? $d['cortes'] : [];
        $mudou = [];
        if ($cortes) {
            foreach ($itens as $li) {
                $k = (string)$li['item_id'];
                if (!array_key_exists($k, $cortes)) continue;
                $nova = max(0, min((int)$li['quantidade'], (int)$cortes[$k]));
                if ($nova === (int)$li['quantidade']) continue;
                $mudou[] = ['linha' => $li, 'nova' => $nova];
            }
        }
        if ($mudou) {
            $mid = (int)($d['motivo_id'] ?? 0) ?: null;
            $txt = mb_substr(trim((string)($d['motivo_texto'] ?? '')), 0, 200);
            if (!$mid && $txt === '') {
                erro('Servir menos do que se pediu explica-se: escolha um motivo ou escreva um.');
            }
        }
        // O pedido depois do corte. Se ficar vazio, isto já não é uma
        // aprovação parcial — é uma recusa, e trata-se como tal.
        $finais = [];
        foreach ($itens as $li) {
            $nova = (int)$li['quantidade'];
            foreach ($mudou as $m) if ($m['linha']['item_id'] === $li['item_id']) $nova = $m['nova'];
            if ($nova > 0) $finais[] = ['li' => $li, 'q' => $nova];
        }
        if (!$finais) erro('Cortou tudo. Se não há nada para servir, recuse o pedido — '
                         . 'a pessoa fica a saber, e o pedido não fica a meio.');

        // Se entretanto faltar stock, não se aprova o que não se pode servir.
        foreach ($finais as $f) {
            $item = barItem($conn, $f['li']['item_id']);
            if (!$item || $item['disponivel'] < $f['q']) {
                erro('Já não há «' . $f['li']['nome'] . '» que chegue para este pedido.');
            }
        }
        /* E as REGRAS. A fila já assinalava os pedidos que deixaram de caber
           numa regra posta depois de eles entrarem — mas assinalar era tudo o
           que fazia: o botão «Aprovar» continuava a aprovar. Uma regra que a
           copa pode saltar com um clique não é uma regra; é um aviso. Agora o
           servidor recusa, seja quem for que carregue.

           A conta faz-se sobre o que FICA depois do corte, e não sobre o que
           foi pedido: é essa a razão de a terceira porta existir. Quem pediu
           quatro e só pode duas continua a poder levar duas. */
        $travado = barTravaoDe($conn, (int)$p['convidado_id'], (int)$p['convite_id'],
                               $finais, $id);
        if ($travado !== null) erro($travado);
        // Grava-se o corte ANTES de reservar: o que se reserva é o que fica.
        foreach ($mudou as $m) {
            $li = $m['linha'];
            if ($m['nova'] > 0) {
                $q = $conn->prepare("UPDATE {$P}bar_pedido_itens SET quantidade=?
                                     WHERE casamento_id=$cid AND pedido_id=? AND item_id=?");
                $q->bind_param('iii', $m['nova'], $id, $li['item_id']);
            } else {
                $q = $conn->prepare("DELETE FROM {$P}bar_pedido_itens
                                     WHERE casamento_id=$cid AND pedido_id=? AND item_id=?");
                $q->bind_param('ii', $id, $li['item_id']);
            }
            @$q->execute();
        }
        foreach ($finais as $f) barReservar($conn, $f['li']['item_id'], $f['q']);

        // Numa aprovação parcial o motivo fica GUARDADO no pedido: é o que o
        // convidado lê no telemóvel quando o pedido dele encolhe, e é o que a
        // copa relê daqui a meia hora sem ter de se lembrar.
        if ($mudou) {
            $mid = (int)($d['motivo_id'] ?? 0) ?: null;
            $txt = mb_substr(trim((string)($d['motivo_texto'] ?? '')), 0, 200);
            $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='aprovado', motivo_id=?,
                                  motivo_texto=?, decidido_por=?, decidido_em=NOW()
                                  WHERE casamento_id=$cid AND id=?");
            $st->bind_param('issi', $mid, $txt, $quem, $id);
        } else {
            $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='aprovado', decidido_por=?, decidido_em=NOW()
                                  WHERE casamento_id=$cid AND id=?");
            $st->bind_param('si', $quem, $id);
        }
        @$st->execute();
        $conta = [];
        foreach ($mudou as $m) {
            $conta[] = $m['linha']['nome'] . ': ' . $m['linha']['quantidade'] . '→' . $m['nova'];
        }
        registar($conn, $mudou ? 'bar_aprovado_parte' : 'bar_aprovado', $p['convidado_nome'] ?? '',
                 '#' . $p['codigo_curto'] . ($conta ? ' · ' . implode(', ', $conta) : ''));
    } else {
        $mid = (int)($d['motivo_id'] ?? 0) ?: null;
        $txt = mb_substr(trim((string)($d['motivo_texto'] ?? '')), 0, 200);
        if (!$mid && $txt === '') erro('Diga porquê: escolha um motivo ou escreva um.');
        $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='recusado', motivo_id=?, motivo_texto=?,
                              decidido_por=?, decidido_em=NOW() WHERE casamento_id=$cid AND id=?");
        $st->bind_param('issi', $mid, $txt, $quem, $id); @$st->execute();
        registar($conn, 'bar_recusado', $p['convidado_nome'] ?? '',
                 '#' . $p['codigo_curto'] . ' · ' . ($txt ?: 'motivo da lista'));
    }
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $id), true),
        'estado' => barEstadoGeral($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_cancelar_copa') {
    // Cancelar um pedido já aprovado — a reserva volta ao stock disponível.
    $cid = barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $id = (int)(corpo()['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if (!in_array($p['estado'], ['aprovado', 'a_caminho', 'falhou'], true)) {
        erro('Só se cancela um pedido que esteja por entregar.');
    }
    if ($p['estado'] !== 'falhou') {
        foreach (barItensDoPedido($conn, $id) as $li) barReservar($conn, $li['item_id'], -$li['quantidade']);
    }
    @$conn->query("UPDATE {$P}bar_pedidos SET estado='cancelado' WHERE casamento_id=$cid AND id=$id");
    registar($conn, 'bar_cancelado', $p['convidado_nome'] ?? '', '#' . $p['codigo_curto']);
    ok(['estado' => barEstadoGeral($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_abrir' || $acao === 'bar_fechar') {
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $abrir = $acao === 'bar_abrir';
    // Abrir o bar fixa a BASE DA NOITE de cada bebida: é o denominador de
    // «restam 15% do gin». Fixa-se aqui, e não na primeira venda, porque a
    // pergunta que a percentagem responde é «quanto é que já se bebeu DESTA
    // noite» — e a noite começa quando a copa abre, não quando alguém pede.
    //
    // Reabrir a meio de uma festa não volta a fixar nada: as bebidas já saíram,
    // e refazer a base punha tudo a 100% com metade do armazém na rua. Só as
    // bebidas que ainda não têm base é que a recebem.
    if ($abrir) {
        $cid = casamentoAtual();
        @$conn->query("UPDATE {$P}bar_itens SET base_noite = stock
                       WHERE casamento_id=$cid AND base_noite <= 0");
    }
    barGuardarDefs($conn, ['bar.aberto' => $abrir ? '1' : '0']);
    registar($conn, $abrir ? 'bar_abriu' : 'bar_fechou', '', '');
    ok(['aberto' => $abrir]);
}

if ($acao === 'bar_pausa') {
    // A pausa à mão — o mesmo gesto que o motor propõe no painel, feito pela
    // copa quando é ela a ver o que a máquina ainda não viu (a bandeja que caiu,
    // o brinde que encheu o balcão de uma vez).
    //
    // Os minutos vêm do ecrã; a HORA calcula-se aqui. O tablet da copa pode
    // estar noutro fuso ou com o relógio trocado, e uma pausa que nasce com a
    // hora do browser nasce expirada — a copa carregaria em «pausar» e nada
    // aconteceria, sem uma palavra a dizer porquê.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $d = corpo();
    if (!empty($d['levantar'])) {
        barGuardarDefs($conn, ['bar.pausada_ate' => '']);
        esquecerDefinicoes($conn);
        registar($conn, 'bar_pausa', '', 'levantou a pausa');
        ok(['pausa_s' => 0]);
    }
    $min = (int)($d['minutos'] ?? 0);
    if ($min <= 0) $min = max(1, (int)barDef($conn, 'bar.pausa_min'));
    $min = max(1, min(240, $min));
    barGuardarDefs($conn, ['bar.pausada_ate' => date('Y-m-d H:i:s', time() + $min * 60)]);
    esquecerDefinicoes($conn);
    registar($conn, 'bar_pausa', '', 'copa em pausa por ' . $min . ' min');
    ok(['pausa_s' => barPausaSegundos($conn), 'minutos' => $min]);
}

if ($acao === 'bar_defs') {
    // As regras da casa: como se trata o IP, quantas letras a procura pede, se
    // os garçons pedem por quem não tem rede, e o que diz o menu fechado.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $d = corpo();
    $q = [];
    foreach (array_keys(barDefsPadrao()) as $k) {
        // 'bar.aberto' abre-se pelo interruptor, não por aqui.
        if ($k === 'bar.aberto') continue;
        if (array_key_exists($k, $d)) $q[$k] = (string)$d[$k];
    }
    barGuardarDefs($conn, $q);
    registar($conn, 'bar_regras', '', implode(', ', array_keys($q)));
    ok(['defs' => barDefsAtuais($conn)]);
}

if ($acao === 'bar_stock_repor' || $acao === 'bar_stock_acerto') {
    // Repor é somar o que chegou; acertar é dizer a verdade sobre o que há.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    exigirCorrecao();
    $d = corpo();
    $iid = (int)($d['item_id'] ?? 0);
    $item = $iid ? barItem($conn, $iid) : null;
    if (!$item) erro('Bebida não encontrada.');
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 160);
    if ($acao === 'bar_stock_repor') {
        $q = (int)($d['quantidade'] ?? 0);
        if ($q === 0) erro('Diga quantas entraram.');
        barMoverStock($conn, $iid, $q, $q > 0 ? 'entrada' : 'quebra', null, $nota);
    } else {
        $novo = max(0, (int)($d['stock'] ?? 0));
        if ($nota === '') erro('Um acerto explica-se: escreva uma nota.');
        barMoverStock($conn, $iid, $novo - (int)$item['stock'], 'acerto', null, $nota);
    }
    registar($conn, 'bar_stock', $item['nome'], $nota);
    ok(['itens' => barItens($conn, true)]);
}

if ($acao === 'bar_categoria_guardar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual(); $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 60);
    if ($nome === '') erro('A gaveta precisa de um nome.');
    $cor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($d['cor'] ?? '')) ? strtoupper($d['cor']) : null;
    $ordem = (int)($d['ordem'] ?? 0);
    if ($id) {
        $st = $conn->prepare("UPDATE {$P}bar_categorias SET nome=?, cor=?, ordem=? WHERE casamento_id=$cid AND id=?");
        $st->bind_param('ssii', $nome, $cor, $ordem, $id);
    } else {
        $st = $conn->prepare("INSERT INTO {$P}bar_categorias (casamento_id,nome,cor,ordem) VALUES (?,?,?,?)");
        $st->bind_param('issi', $cid, $nome, $cor, $ordem);
    }
    @$st->execute();
    if (!$id) $id = $conn->insert_id;
    registar($conn, 'bar_categoria', $nome, '');
    ok(['id' => $id, 'categorias' => barCategorias($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_categoria_apagar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    if (!$id) erro('Gaveta inválida.');
    // As bebidas não se perdem com a gaveta: ficam sem gaveta.
    @$conn->query("UPDATE {$P}bar_itens SET categoria_id=NULL WHERE casamento_id=$cid AND categoria_id=$id");
    @$conn->query("DELETE FROM {$P}bar_categorias WHERE casamento_id=$cid AND id=$id");
    registar($conn, 'bar_categoria', '#' . $id, 'apagada');
    ok(['categorias' => barCategorias($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_item_guardar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual(); $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('A bebida precisa de um nome.');
    $desc = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 200);
    $cat  = (int)($d['categoria_id'] ?? 0) ?: null;
    $alc  = !empty($d['alcoolico']) ? 1 : 0;
    $vol  = (int)($d['volume_ml'] ?? 0) ?: null;
    $maxp = max(1, min(20, (int)($d['max_por_pedido'] ?? 2)));
    $est  = ($d['estado'] ?? 'ativo') === 'oculto' ? 'oculto' : 'ativo';
    $ord  = (int)($d['ordem'] ?? 0);
    // A partir de quantas se diz «a acabar». É por bebida porque cinco
    // garrafas de whisky é uma emergência e cinco águas não é nada — e era
    // exactamente isso que um número fixo para todas não sabia distinguir.
    $min  = max(0, min(9999, (int)($d['stock_minimo'] ?? 8)));
    if ($id) {
        $st = $conn->prepare("UPDATE {$P}bar_itens SET categoria_id=?, nome=?, descricao=?, alcoolico=?,
                              volume_ml=?, max_por_pedido=?, stock_minimo=?, estado=?, ordem=?
                              WHERE casamento_id=$cid AND id=?");
        $st->bind_param('issiiiisii', $cat, $nome, $desc, $alc, $vol, $maxp, $min, $est, $ord, $id);
        @$st->execute();
    } else {
        $st = $conn->prepare("INSERT INTO {$P}bar_itens
                (casamento_id,categoria_id,nome,descricao,alcoolico,volume_ml,max_por_pedido,
                 stock_minimo,estado,ordem,stock)
                VALUES (?,?,?,?,?,?,?,?,?,?,0)");
        $st->bind_param('iissiiiisi', $cid, $cat, $nome, $desc, $alc, $vol, $maxp, $min, $est, $ord);
        @$st->execute();
        $id = $conn->insert_id;
        // O stock inicial, quando vem junto: entra como entrada, com razão.
        $q0 = (int)($d['stock'] ?? 0);
        if ($q0 > 0) barMoverStock($conn, $id, $q0, 'entrada', null, 'stock inicial');
    }
    registar($conn, 'bar_item', $nome, '');
    ok(['id' => $id, 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_item_apagar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    $item = $id ? barItem($conn, $id) : null;
    if (!$item) erro('Bebida não encontrada.');
    // Um item com pedidos por entregar não se apaga: o pedido ficaria sem
    // nome. Esconde-se, que é o que quem pergunta quer dizer.
    $porEntregar = (int)(@$conn->query("SELECT COUNT(*) FROM {$P}bar_pedido_itens pi
                                        JOIN {$P}bar_pedidos p ON p.id=pi.pedido_id AND p.casamento_id=pi.casamento_id
                                        WHERE pi.casamento_id=$cid AND pi.item_id=$id
                                          AND p.estado IN ('em_analise','aprovado','a_caminho')")->fetch_row()[0] ?? 0);
    if ($porEntregar > 0) erro('Esta bebida está em ' . $porEntregar . ' pedido(s) por entregar. Esconda-a, em vez de a apagar.');
    if ($item['foto'] && str_starts_with((string)$item['foto'], BAR_FOTO_DIR)) {
        @unlink(__DIR__ . '/' . $item['foto']);
    }
    @$conn->query("DELETE FROM {$P}bar_itens WHERE casamento_id=$cid AND id=$id");
    // Os alertas desta bebida vão com ela. Um alerta sobre uma garrafa que já
    // não está no menu é ruído que o copeiro não pode resolver: as acções que
    // ele propõe — baixar o máximo, suspender — não têm sobre o que agir.
    @$conn->query("UPDATE {$P}bar_alertas SET estado='caducado'
                   WHERE casamento_id=$cid AND estado='aberto'
                     AND (chave LIKE 'stock:$id:%' OR chave='rutura:$id')");
    registar($conn, 'bar_item_apagado', $item['nome'], '');
    ok(['itens' => barItens($conn, true)]);
}

if ($acao === 'bar_item_foto') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)($_POST['id'] ?? 0);
    $item = $id ? barItem($conn, $id) : null;
    if (!$item) erro('Bebida não encontrada.');
    $src = origemUpload('ficheiro', FOTO_CONVITE_MAX);
    $inf = @getimagesize($src['tmp']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$inf || !isset($tipos[(int)$inf[2]])) erro('O ficheiro não é uma imagem que saibamos ler (jpg, png ou webp).');
    if ((int)$inf[0] < 200 || (int)$inf[1] < 200) erro('A fotografia é pequena de mais (mínimo 200×200).');
    $dir = pastaDeEnvios(BAR_FOTO_DIR);
    $nomeF = 'b' . $cid . '-' . $id . '-' . time() . '-' . random_int(100, 999) . '.' . $tipos[(int)$inf[2]];
    if (!moverUpload($src, "$dir/$nomeF")) erro('Não foi possível guardar a fotografia.');
    $antiga = (string)$item['foto'];
    $caminho = BAR_FOTO_DIR . '/' . $nomeF;
    $st = $conn->prepare("UPDATE {$P}bar_itens SET foto=? WHERE casamento_id=$cid AND id=?");
    $st->bind_param('si', $caminho, $id); @$st->execute();
    if ($antiga !== '' && str_starts_with($antiga, BAR_FOTO_DIR)) @unlink(__DIR__ . '/' . $antiga);
    registar($conn, 'bar_item', $item['nome'], 'fotografia');
    ok(['foto' => $caminho, 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_item_foto_tirar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)(corpo()['id'] ?? 0);
    $item = $id ? barItem($conn, $id) : null;
    if (!$item) erro('Bebida não encontrada.');
    if ($item['foto'] && str_starts_with((string)$item['foto'], BAR_FOTO_DIR)) {
        @unlink(__DIR__ . '/' . $item['foto']);
    }
    @$conn->query("UPDATE {$P}bar_itens SET foto=NULL WHERE casamento_id=$cid AND id=$id");
    ok(['itens' => barItens($conn, true)]);
}

if ($acao === 'bar_motivo_guardar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual(); $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $txt = mb_substr(trim((string)($d['texto'] ?? '')), 0, 120);
    if ($txt === '') erro('Escreva o motivo.');
    $ord = (int)($d['ordem'] ?? 0);
    if ($id) {
        $st = $conn->prepare("UPDATE {$P}bar_motivos SET texto=?, ordem=? WHERE casamento_id=$cid AND id=?");
        $st->bind_param('sii', $txt, $ord, $id);
    } else {
        $st = $conn->prepare("INSERT INTO {$P}bar_motivos (casamento_id,texto,ordem) VALUES (?,?,?)");
        $st->bind_param('isi', $cid, $txt, $ord);
    }
    @$st->execute();
    registar($conn, 'bar_motivo', $txt, '');
    ok(['motivos' => barMotivos($conn)]);
}

if ($acao === 'bar_mensagens_guardar') {
    // O que o convidado lê em cada situação, pela voz do casal (§31.5).
    // Guardam-se todas de uma vez: é um formulário só, e um formulário que
    // gravasse campo a campo deixava metade escrita se a rede caísse a meio.
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $d = corpo();
    $n = 0;
    foreach (array_keys(barSituacoes()) as $sit) {
        if (!array_key_exists($sit, $d)) continue;
        $txt = mb_substr(trim((string)$d[$sit]), 0, 240);
        if ($txt === '') {
            // Apagar é voltar ao texto de fábrica. Guardar uma linha vazia
            // seria guardar «o casal quer dizer nada», que não é a mesma coisa.
            $st = $conn->prepare("DELETE FROM {$P}bar_mensagens
                                  WHERE casamento_id=? AND situacao=?");
            if ($st) { $st->bind_param('is', $cid, $sit); @$st->execute(); }
        } else {
            $st = $conn->prepare("INSERT INTO {$P}bar_mensagens (casamento_id,situacao,texto,ativo)
                                  VALUES (?,?,?,1)
                                  ON DUPLICATE KEY UPDATE texto=VALUES(texto), ativo=1");
            if ($st) { $st->bind_param('iss', $cid, $sit, $txt); @$st->execute(); }
        }
        $n++;
    }
    barMensagensEsquecer();
    registar($conn, 'bar_mensagens', '', $n . ' situação(ões)');
    ok(['mensagens' => barMensagens($conn)]);
}

if ($acao === 'bar_motivo_apagar') {
    barCid(); if (!podeCopa()) erro('Só a copa.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    @$conn->query("UPDATE {$P}bar_motivos SET ativo=0 WHERE casamento_id=$cid AND id=" . (int)$id);
    ok(['motivos' => barMotivos($conn)]);
}

if ($acao === 'bar_mesa_token') {
    // Gerar (ou regerar) o código da mesa. Regerar invalida a folha que já
    // esteja pousada — por isso é um gesto explícito.
    barCid(); if (!ehAdmin()) erro('Só os noivos.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)(corpo()['mesa_id'] ?? 0);
    $t = barTokenNovo();
    $st = $conn->prepare("UPDATE {$P}mesas SET bar_token=? WHERE casamento_id=$cid AND id=?");
    $st->bind_param('si', $t, $id); @$st->execute();
    ok(['token' => $t]);
}

// ---- as regras: quanto, de quem, de quanto em quanto tempo ----

if ($acao === 'bar_regras') {
    // As regras em vigor, ditas em português. O ecrã não recompõe a frase:
    // lê-a como o servidor a escreveu, para não haver duas gramáticas.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    ok(['regras' => array_map(fn($l) => barRegraLinha($conn, $l), barLimitesTodos($conn)),
        'itens'  => array_map(fn($i) => ['id' => $i['id'], 'nome' => $i['nome'],
                                         'categoria_id' => $i['categoria_id']], barItens($conn, true)),
        'categorias' => barCategorias($conn)]);
}

if ($acao === 'bar_regra_guardar') {
    // Pôr (ou mudar) uma regra. Vale de imediato — inclusive para os pedidos
    // que já estejam na fila por decidir (§8.0.1).
    $cid = barCid();
    if (!podeCopa()) erro('Só a copa põe regras.');   // o entregador serve, não julga
    exigirCorrecao();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);

    $escopo = in_array($d['escopo'] ?? '', ['item','categoria','tudo'], true) ? $d['escopo'] : 'tudo';
    $alvo   = $escopo === 'tudo' ? 0 : (int)($d['alvo_id'] ?? 0);
    if ($escopo !== 'tudo' && $alvo <= 0) erro('Diga sobre o quê é a regra.');
    $sujeito = ($d['sujeito'] ?? '') === 'casa' ? 'casa' : 'convidado';
    $unidade = ($d['unidade'] ?? '') === 'pedidos' ? 'pedidos' : 'bebidas';
    // Uma regra da casa é o caudal da copa: não se põe a uma pessoa.
    $pessoa  = $sujeito === 'casa' ? null : ((int)($d['alvo_convidado_id'] ?? 0) ?: null);
    $convite = $sujeito === 'casa' ? null : ((int)($d['alvo_convite_id'] ?? 0) ?: null);
    if ($pessoa && $convite) erro('Uma regra é de uma pessoa ou de um convite, não das duas.');
    if ($pessoa && !barConvidado($conn, $pessoa)) erro('Não encontrámos esse convidado.');

    $qtd    = max(0, min(999, (int)($d['quantidade'] ?? 0)));
    $janela = max(0, min(1440, (int)($d['janela_min'] ?? 0)));
    // «Pedidos» só faz sentido sobre tudo: contar pedidos de uma bebida é
    // contar bebidas por outro nome, e daria dois caminhos para a mesma coisa.
    if ($unidade === 'pedidos' && $escopo !== 'tudo') erro('Contar pedidos só se faz sobre tudo.');

    $msg  = mb_substr(trim((string)($d['mensagem'] ?? '')), 0, 160) ?: null;
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 160) ?: null;

    // As horas chegam como o ecrã as pede — «21:00» —, ou como um momento
    // inteiro para quem chame a API à mão. Vazias, a regra vale o que a festa
    // durar, que é o caso comum.
    $momento = function ($v, bool $fim) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $v)) {
            return str_replace('T', ' ', substr($v, 0, 16)) . ':00';
        }
        return barHoraMomento($v, $fim);
    };
    $vigora = $momento($d['vigora_hora'] ?? ($d['vigora_em'] ?? ''), false);
    $expira = $momento($d['expira_hora'] ?? ($d['expira_em'] ?? ''), true);
    // «Daqui a N minutos», contado pelo relógio DO SERVIDOR. Quem suspende uma
    // bebida a meio de uma festa não pensa numa hora, pensa num bocado — e se
    // fosse o ecrã a converter esse bocado numa hora, converteria-o pelo
    // relógio do telemóvel: a casa corre em Africa/Luanda, o aparelho de quem
    // está a trabalhar corre no que quiser, e uma pausa de dez minutos podia
    // nascer expirada ou durar uma hora.
    $daqui = (int)($d['expira_min'] ?? 0);
    if ($daqui > 0) $expira = date('Y-m-d H:i:s', time() + min(1440, $daqui) * 60);
    // Uma janela ao contrário não é uma janela: das 21h às 2h é a madrugada
    // seguinte, e é isso que quem a escreve quer dizer.
    if ($vigora && $expira && strtotime($expira) <= strtotime($vigora)) {
        $expira = date('Y-m-d H:i:s', strtotime($expira) + 86400);
    }
    $quem = (string)(utilizadorAtual() ?? '');
    // Travar (recusa no acto), sugerir, confirmar ou avisar. Quem não disser
    // nada fica a travar — é o que todas as regras deste módulo faziam antes de
    // haver modos, e uma regra que mudasse de comportamento por omissão era a
    // pior maneira de estrear a funcionalidade.
    $modo = in_array($d['modo'] ?? '', ['trava','sugere','confirma','avisa'], true)
          ? $d['modo'] : 'trava';

    if ($id) {
        $st = $conn->prepare("UPDATE {$P}bar_limites SET escopo=?, alvo_id=?, sujeito=?,
                 alvo_convidado_id=?, alvo_convite_id=?, unidade=?, quantidade=?, janela_min=?,
                 mensagem=?, nota=?, vigora_em=?, expira_em=?, modo=?
                 WHERE casamento_id=$cid AND id=?");
        $st->bind_param('sisiisiisssssi', $escopo, $alvo, $sujeito, $pessoa, $convite,
                        $unidade, $qtd, $janela, $msg, $nota, $vigora, $expira, $modo, $id);
    } else {
        $st = $conn->prepare("INSERT INTO {$P}bar_limites
                 (casamento_id,escopo,alvo_id,sujeito,alvo_convidado_id,alvo_convite_id,
                  unidade,quantidade,janela_min,mensagem,nota,vigora_em,expira_em,modo,criado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('isisiisiissssss', $cid, $escopo, $alvo, $sujeito, $pessoa, $convite,
                        $unidade, $qtd, $janela, $msg, $nota, $vigora, $expira, $modo, $quem);
    }
    if (!@$st->execute()) erro('Não foi possível guardar a regra.');
    if (!$id) $id = $conn->insert_id;
    barLimitesEsquecer();

    // Pela lista TODA, e não pelas que valem agora: uma regra marcada para as
    // 2h ainda não vale, e mesmo assim tem de aparecer escrita a quem a pôs.
    $l = null;
    foreach (barLimitesTodos($conn) as $x) if ((int)$x['id'] === $id) $l = $x;
    registar($conn, 'bar_regra', $pessoa ? (barConvidado($conn, $pessoa)['nome'] ?? '') : '',
             $l ? barRegraFrase($conn, $l) : '');
    ok(['regras' => array_map(fn($x) => barRegraLinha($conn, $x), barLimitesTodos($conn)),
        'fila' => barFilaContraRegras($conn)]);
}

if ($acao === 'bar_regra_apagar') {
    $cid = barCid();
    if (!podeCopa()) erro('Só a copa põe regras.');
    exigirCorrecao();
    $id = (int)(corpo()['id'] ?? 0);
    $antes = null;
    foreach (barLimitesTodos($conn) as $x) if ((int)$x['id'] === $id) $antes = $x;
    // Levanta-se, não se apaga: o registo de ações fica a poder dizer o que
    // esteve em vigor durante a noite.
    @$conn->query("UPDATE {$P}bar_limites SET ativo=0 WHERE casamento_id=$cid AND id=$id");
    barLimitesEsquecer();
    registar($conn, 'bar_regra_fora', '', $antes ? barRegraFrase($conn, $antes) : '#' . $id);
    ok(['regras' => array_map(fn($x) => barRegraLinha($conn, $x), barLimitesTodos($conn)),
        'fila' => barFilaContraRegras($conn)]);
}

/**
 * Executar a acção que um alerta propôs.
 *
 * As quatro acções que o motor sabe propor, e mais nenhuma. A lista é fechada
 * de propósito: um alerta não é um sítio de onde se possa mandar fazer
 * qualquer coisa, é um sítio de onde se responde SIM a uma pergunta concreta.
 *
 * Devolve o que se fez, em palavras, para o registo de acções. Null se não deu.
 */
function barAlertaAplicar(mysqli $conn, array $sug): ?string {
    global $P;
    $cid = casamentoAtual();
    $accao = (string)($sug['accao'] ?? '');
    $min = max(1, min(240, (int)($sug['minutos'] ?? 10)));

    if ($accao === 'nenhuma') return 'sem acção';

    if ($accao === 'pausar_copa') {
        barGuardarDefs($conn, ['bar.pausada_ate' => date('Y-m-d H:i:s', time() + $min * 60)]);
        esquecerDefinicoes($conn);
        return 'copa em pausa por ' . $min . ' min';
    }

    if ($accao === 'baixar_max_por_pedido') {
        $iid = (int)($sug['item_id'] ?? 0);
        $item = $iid ? barItem($conn, $iid) : null;
        if (!$item) return null;
        $para = max(1, min(20, (int)($sug['para'] ?? 1)));
        $st = $conn->prepare("UPDATE {$P}bar_itens SET max_por_pedido=?
                              WHERE casamento_id=? AND id=?");
        if (!$st) return null;
        $st->bind_param('iii', $para, $cid, $iid);
        if (!@$st->execute()) return null;
        return '«' . $item['nome'] . '»: no máximo ' . $para . ' por pedido';
    }

    // Suspender uma bebida e travar uma pessoa são a mesma coisa por dentro:
    // uma regra de quantidade 0 com hora de saída, que se desfaz sozinha
    // (§30.3). Ter duas maneiras de escrever a mesma regra era ter duas
    // maneiras de ela discordar de si própria.
    if ($accao === 'suspender_bebida' || $accao === 'travar_convidado') {
        $bebida = $accao === 'suspender_bebida';
        $iid = (int)($sug['item_id'] ?? 0);
        $gid = (int)($sug['convidado_id'] ?? 0);
        $item = $bebida ? ($iid ? barItem($conn, $iid) : null) : null;
        $g    = $bebida ? null : ($gid ? barConvidado($conn, $gid) : null);
        if ($bebida ? !$item : !$g) return null;
        $escopo = $bebida ? 'item' : 'tudo';
        $alvo   = $bebida ? $iid : 0;
        $pessoa = $bebida ? null : $gid;
        $expira = date('Y-m-d H:i:s', time() + $min * 60);
        $nota   = 'Posta pelo motor, ' . $min . ' min';
        $zero = 0; $suj = 'convidado'; $uni = 'bebidas'; $modo = 'trava';
        $st = $conn->prepare("INSERT INTO {$P}bar_limites
                 (casamento_id,escopo,alvo_id,sujeito,alvo_convidado_id,unidade,
                  quantidade,janela_min,nota,expira_em,modo,criado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'motor')");
        if (!$st) return null;
        $st->bind_param('isisisiissss', $cid, $escopo, $alvo, $suj, $pessoa, $uni,
                        $zero, $zero, $nota, $expira, $modo);
        if (!@$st->execute()) return null;
        barLimitesEsquecer();
        return $bebida
            ? '«' . $item['nome'] . '» suspensa por ' . $min . ' min'
            : $g['nome'] . ' travado por ' . $min . ' min';
    }
    return null;
}

if ($acao === 'bar_alerta_decidir') {
    // O que a copa responde a uma proposta do motor. Três saídas, e nenhuma
    // delas é deixar o alerta no ar: aplicar, adaptar (aplicar com outro
    // número) ou ignorar.
    $cid = barCid();
    if (!podeCopa()) erro('Só a copa decide os alertas.');
    exigirCorrecao();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $decisao = (string)($d['decisao'] ?? '');
    if (!in_array($decisao, ['aplicar', 'adaptar', 'ignorar'], true)) {
        erro('Diga o que fazer com o alerta.');
    }
    $st = $conn->prepare("SELECT * FROM {$P}bar_alertas
                          WHERE casamento_id=? AND id=? LIMIT 1");
    $st->bind_param('ii', $cid, $id); @$st->execute();
    $a = $st->get_result()->fetch_assoc();
    if (!$a) erro('Esse alerta já não está aqui.');
    if ($a['estado'] !== 'aberto') erro('Esse alerta já foi decidido.');

    $sug = json_decode((string)$a['sugestao'], true) ?: [];
    // Adaptar é aplicar com outro número. Só os NÚMEROS se adaptam — a acção
    // em si não: um alerta que propõe suspender uma bebida não vira, a meio,
    // uma pausa da copa. Quem quer outra coisa fecha o alerta e faz o gesto
    // pela sua porta, que continua toda lá.
    if ($decisao === 'adaptar') {
        if (isset($d['minutos'])) $sug['minutos'] = (int)$d['minutos'];
        if (isset($d['para']))    $sug['para']    = (int)$d['para'];
    }
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 240) ?: null;
    $quem = (string)(utilizadorAtual() ?? '');
    $feito = null;

    if ($decisao !== 'ignorar') {
        $feito = barAlertaAplicar($conn, $sug);
        if ($feito === null) erro('Não foi possível aplicar: o que o alerta propunha já não existe.');
    }
    $estado = $decisao === 'ignorar' ? 'ignorado'
            : ($decisao === 'adaptar' ? 'adaptado' : 'aplicado');
    $novaSug = json_encode($sug, JSON_UNESCAPED_UNICODE);
    $st = $conn->prepare("UPDATE {$P}bar_alertas
                          SET estado=?, decidido_por=?, decidido_em=NOW(), nota=?, sugestao=?
                          WHERE casamento_id=? AND id=?");
    $st->bind_param('ssssii', $estado, $quem, $nota, $novaSug, $cid, $id);
    @$st->execute();

    // Ignorar regista-se como se regista aplicar. É metade da razão de o painel
    // existir: no dia seguinte, a pergunta «porque é que o gin acabou às duas»
    // tem resposta escrita — e a resposta pode muito bem ser «porque alguém
    // decidiu, às onze, que não era preciso fazer nada», o que é uma decisão
    // legítima e tem de se poder ver.
    registar($conn, 'bar_alerta', $a['tipo'],
             $estado . ' · ' . ($feito ?? 'nada feito')
           . ($nota ? ' · ' . $nota : ''));
    ok(['alertas' => barAlertas($conn), 'estado' => barEstadoGeral($conn),
        'itens' => barItens($conn, true),
        'regras' => array_map(fn($x) => barRegraLinha($conn, $x), barLimitesTodos($conn))]);
}

if ($acao === 'bar_numeros') {
    // Os números da noite. Uma leitura só, porque quem os abre quer ver a
    // festa toda de uma vez e não coleccionar separadores.
    barCid();
    if (!podeCopa()) erro('Só a copa e os noivos.');
    ok(['estado'  => barEstadoGeral($conn),
        'tempos'  => barTempos($conn),
        'consumo' => barConsumoGeral($conn),
        'rutura'  => barRutura($conn),
        'recusas' => barRecusas($conn),
        'ritmo'   => barRitmoHistorico($conn),
        'mesas'   => barPorMesa($conn),
        // Quem bebeu o quê. É o gráfico que responde a «com quem é que preciso
        // de falar antes de ser tarde» (§27).
        'pessoas' => barPorConvidado($conn),
        'caudal'  => barCaudal($conn),
        'agora'   => date('c')]);
}

if ($acao === 'bar_meu_consumo') {
    // O convidado vê SÓ o seu — o da pessoa a que este telemóvel está preso, e
    // nem sequer o do resto da família. Não há aqui parâmetro nenhum a dizer
    // de quem é: é sempre de quem está do outro lado do testemunho, e é por
    // isso que não se pode pedir o de outro.
    barPortaPublica($conn);
    $eu = barQuemSou($conn);
    if (!$eu) ok(['levou' => [], 'limites' => []]);
    $g = barConvidado($conn, $eu);
    if (!$g) ok(['levou' => [], 'limites' => []]);

    // Quanto lhe falta de cada limite que lhe diga respeito.
    $faltas = [];
    $ritmo = barRitmoDaCasa($conn);
    foreach (barItensPara($conn, $eu, (int)$g['convite_id']) as $i) {
        if ($i['travao'] === null && $i['pode_pedir'] >= $i['max_por_pedido']) continue;
        $faltas[] = ['nome' => $i['nome'], 'pode' => $i['pode_pedir'],
                     'travao' => $i['travao'], 'espera_s' => $i['espera_s']];
    }
    ok(['levou'   => barConsumoPessoal($conn, $eu),
        'limites' => $faltas,
        'ritmo'   => $ritmo ? ['espera_s' => $ritmo['segundos']] : null]);
}

// `bar_soltar` viveu aqui: um botão na ficha que largava o telemóvel de uma
// pessoa, para quando a vida dava um nó — um aparelho emprestado, um nome
// escolhido por engano. Saiu com a terceira passagem, e o que o substitui já
// cá estava: `bar.trocar_nome`, que deixa o telemóvel passar para outro nome
// sozinho. Resolvia-se pela mão da copa o que se resolve na mesa, e a ficha
// ganhou o espaço todo para a pergunta que ali se faz mesmo — o que é que
// esta pessoa pode beber.

if ($acao === 'bar_ficha') {
    // A ficha de um convidado: o que já levou e as regras dele. É o ecrã que
    // se abre com a pessoa à frente, a meio da festa, para decidir.
    barCid();
    if (!podeCopa()) erro('Só a copa.');
    $gid = (int)($_GET['convidado'] ?? 0);
    $g = $gid ? barConvidado($conn, $gid) : null;
    if (!$g) erro('Não encontrámos esse convidado.');
    ok(['convidado' => ['id' => $gid, 'nome' => $g['nome'],
                        'convite' => $g['nome_exibicao'],
                        'convite_id' => (int)$g['convite_id']],
        'levou'  => barConsumoPessoal($conn, $gid),
        'regras' => array_values(array_filter(
            array_map(fn($l) => barRegraLinha($conn, $l), barLimitesTodos($conn)),
            fn($r) => $r['alvo_convidado_id'] === $gid
                   || $r['alvo_convite_id'] === (int)$g['convite_id'])),
        // TODAS as notas que os garçons escreveram nas entregas desta pessoa.
        // A fila mostra as três últimas ao decidir — chegam para o gesto de um
        // minuto. A ficha é o outro momento, o de perceber a noite de alguém, e
        // aí três não chegam: «pediu para não lhe servirem mais» escrito às 23h
        // é o que explica o que se está a ver à uma da manhã.
        'notas'  => barNotasDe($conn, $gid)]);
}

// ============================================================
// AS ENTREGAS — apanhar, entregar, e o stock a descer
// ============================================================

if ($acao === 'bar_entrega_lista') {
    barCid();
    if (!podeEntregar()) erro('Só as entregas.');
    $ps = barPedidos($conn, "p.estado IN ('aprovado','a_caminho','falhou')
                             ORDER BY FIELD(p.estado,'a_caminho','falhou','aprovado'), p.decidido_em, p.id");
    ok(['pedidos' => array_map(fn($p) => barPedidoLinha($conn, $p, true), $ps),
        'estado'  => barEstadoGeral($conn),
        'eu'      => utilizadorAtual(),
        'tempos'  => barTempos($conn)]);
}

if ($acao === 'bar_apanhar') {
    barCid(); if (!podeEntregar()) erro('Só as entregas.'); exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)(corpo()['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if (!in_array($p['estado'], ['aprovado', 'falhou'], true)) erro('Esse pedido já não está por apanhar.');
    $quem = (string)(utilizadorAtual() ?? '');
    $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='a_caminho', entregue_por=?, apanhado_em=NOW()
                          WHERE casamento_id=$cid AND id=?");
    $st->bind_param('si', $quem, $id); @$st->execute();
    registar($conn, 'bar_apanhado', $p['convidado_nome'] ?? '', '#' . $p['codigo_curto']);
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $id), true), 'estado' => barEstadoGeral($conn)]);
}

if ($acao === 'bar_entregue') {
    // É aqui, e só aqui, que o stock real desce.
    //
    // A copa também fecha um pedido, e não só as entregas: serviu-se ao balcão,
    // ou o garçom levou-o e esqueceu-se de marcar. Ficar à espera de um gesto
    // que já não vem deixava o pedido «por entregar» a noite inteira e a bebida
    // reservada por nada.
    barCid(); if (!podeEntregar() && !podeCopa()) erro('Só o pessoal do bar.');
    exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)(corpo()['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if (!in_array($p['estado'], ['a_caminho', 'aprovado'], true)) erro('Esse pedido não está por entregar.');
    foreach (barItensDoPedido($conn, $id) as $li) {
        barMoverStock($conn, $li['item_id'], -$li['quantidade'], 'entrega', $id, '');
        barReservar($conn, $li['item_id'], -$li['quantidade']);
    }
    $quem = (string)(utilizadorAtual() ?? '');
    // A nota do garçom, se ele a escreveu. É opcional de propósito: a acção
    // que se faz cem vezes por noite não pode exigir escrita, e a nota vale
    // justamente por ser a excepção — «pediu para não lhe servirem mais»,
    // «está com os miúdos». A copa lê-a ao decidir o pedido seguinte.
    $nota = mb_substr(trim((string)(corpo()['nota'] ?? '')), 0, 240) ?: null;
    $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='entregue', entregue_por=?,
                          nota_entrega=?, apanhado_em=COALESCE(apanhado_em, NOW()), entregue_em=NOW()
                          WHERE casamento_id=$cid AND id=?");
    $st->bind_param('ssi', $quem, $nota, $id); @$st->execute();
    registar($conn, 'bar_entregue', $p['convidado_nome'] ?? '',
             '#' . $p['codigo_curto'] . ($p['mesa_nome'] ? ' · mesa ' . $p['mesa_nome'] : '')
           . ($nota ? ' · nota: ' . $nota : ''));
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $id), true),
        'estado' => barEstadoGeral($conn), 'tempos' => barTempos($conn)]);
}

if ($acao === 'bar_mudar_mesa') {
    // A pessoa mudou de sítio. O pedido segue-a.
    //
    // Acontece a toda a hora numa festa: pede-se sentado e levanta-se para
    // dançar. Sem isto, o garçom levava a bebida a uma mesa vazia e voltava com
    // ela — ou marcava «não estava na mesa», que manda o pedido de volta à copa
    // e faz a pessoa esperar outra vez por uma coisa que já estava pronta.
    $cid = barCid();
    if (!podeEntregar() && !podeCopa()) erro('Só o pessoal do bar.');
    exigirCorrecao();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if (in_array($p['estado'], ['entregue', 'recusado', 'cancelado'], true)) {
        erro('Esse pedido já está fechado.');
    }
    // 0 é uma resposta legítima: «sem mesa» — quem está de pé ao balcão.
    $mesa = (int)($d['mesa_id'] ?? 0);
    $nome = null;
    if ($mesa > 0) {
        $st = $conn->prepare("SELECT nome FROM {$P}mesas WHERE casamento_id=? AND id=? LIMIT 1");
        $st->bind_param('ii', $cid, $mesa);
        @$st->execute();
        $x = $st->get_result()->fetch_assoc();
        if (!$x) erro('Essa mesa não é deste casamento.');
        $nome = (string)$x['nome'];
    }
    $novo = $mesa > 0 ? $mesa : null;
    $st2 = $conn->prepare("UPDATE {$P}bar_pedidos SET mesa_id=? WHERE casamento_id=? AND id=?");
    $st2->bind_param('iii', $novo, $cid, $id);
    if (!@$st2->execute()) erro('Não foi possível mudar a mesa.');
    registar($conn, 'bar_mudou_mesa', $p['convidado_nome'] ?? '',
             '#' . $p['codigo_curto'] . ' · ' . ($p['mesa_nome'] ?: 'sem mesa')
           . ' → ' . ($nome ?: 'sem mesa'));
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $id), true)]);
}

if ($acao === 'bar_falhou') {
    // Não estava na mesa. A reserva volta, e o pedido volta à copa.
    barCid(); if (!podeEntregar()) erro('Só as entregas.'); exigirCorrecao();
    $cid = casamentoAtual();
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $p = $id ? barPedido($conn, $id) : null;
    if (!$p) erro('Pedido não encontrado.');
    if (!in_array($p['estado'], ['a_caminho', 'aprovado'], true)) erro('Esse pedido não está a caminho.');
    foreach (barItensDoPedido($conn, $id) as $li) barReservar($conn, $li['item_id'], -$li['quantidade']);
    $txt = mb_substr(trim((string)($d['motivo_texto'] ?? '')), 0, 200);
    $st = $conn->prepare("UPDATE {$P}bar_pedidos SET estado='falhou', motivo_texto=? WHERE casamento_id=$cid AND id=?");
    $st->bind_param('si', $txt, $id); @$st->execute();
    registar($conn, 'bar_falhou', $p['convidado_nome'] ?? '', '#' . $p['codigo_curto'] . ' · ' . $txt);
    ok(['estado' => barEstadoGeral($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_pedir_por') {
    // O pedido de quem não tem rede: o garçom lança-o por ele.
    $cid = barCid();
    if (!podeEntregar() && !podeCopa()) erro('Só o pessoal do bar.');
    exigirCorrecao();
    if (!barAberto($conn)) erro('A copa está fechada neste momento.');
    // A pausa vale também ao balcão. É a mesma razão de §30.2: uma paragem que
    // se contorna pela porta de serviço não é uma paragem — e quem a pôs foi a
    // própria copa, a dizer que não tem mãos a medir.
    //
    // Os textos daqui não passam pelas mensagens do casal (§31.5): essas são a
    // voz da festa a falar com quem bebe, e quem lê isto é quem trabalha. A um
    // copeiro diz-se o que se passa e o que ele pode fazer; a um convidado
    // conta-se outra história, e é essa que o casal escreve.
    if ($falta = barPausaSegundos($conn)) {
        erro('A copa está em pausa por mais ' . barRelogio($falta)
           . '. Levante a pausa se for mesmo para servir agora.');
    }
    $d = corpo();
    $gid = (int)($d['convidado_id'] ?? 0);
    $g = $gid ? barConvidado($conn, $gid) : null;
    if (!$g) erro('Escolha o convidado.');
    $mesaId = (int)($d['mesa_id'] ?? 0) ?: null;
    $linhas = [];
    foreach ((is_array($d['itens'] ?? null) ? $d['itens'] : []) as $li) {
        $iid = (int)($li['item_id'] ?? 0); $q = (int)($li['quantidade'] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;
        $item = barItem($conn, $iid);
        if (!$item) continue;
        $linhas[] = [$item, $q];
    }
    if (!$linhas) erro('Escolha pelo menos uma bebida.');

    /* ---- em que estado nasce este pedido ----------------------
       Depende de QUEM o lança, e a diferença é a mesma que separa os dois
       postos: a copa decide, o garçom serve.

       O COPEIRO que lança um pedido pelo balcão já o decidiu — está a olhar
       para a pessoa e para as garrafas. Fazê-lo nascer «em análise» era pô-lo
       a aprovar aquilo que acabou de escrever, e a fila enchia-se de pedidos
       que só esperavam por quem os tinha criado. Nasce APROVADO (fica
       prometido, e alguém o leva), ou ENTREGUE quando o copo já foi na mão.

       O GARÇOM não decide nada. Enquanto o pedido dele nascia aprovado, quem
       anda na sala tinha à mão a única porta do bar que não passava por
       ninguém: escrevia o pedido e ele estava servido. Não é falta de
       confiança — é que a decisão é um posto, e um posto não se exerce por
       acidente de onde se está a escrever. O que ele lança SUBMETE-SE: entra
       na fila por decidir, como o de qualquer convidado. */
    $daCopa = podeCopa();
    $jaEntregue = $daCopa && !empty($d['entregue']);
    $estado = $jaEntregue ? 'entregue' : ($daCopa ? 'aprovado' : 'em_analise');
    foreach ($linhas as [$item, $q]) {
        if ((int)$item['disponivel'] < $q) {
            erro('Já não há «' . $item['nome'] . '» que chegue: restam '
               . (int)$item['disponivel'] . '.');
        }
    }
    /* ---- e as regras valem aqui como valem em todo o lado ----
       Esta porta não as consultava. O resultado era o pior que uma regra pode
       ter: escrevia-se «uma cerveja de hora a hora», via-se escrita no painel,
       e o bar servia dez — bastava que o pedido entrasse pelo balcão. Quem a
       pôs ficava convencido de que a casa a estava a cumprir, e o aviso que
       explica a espera nunca chegava a aparecer a ninguém.

       O alcance de uma regra é absoluto ou não é regra nenhuma. O balcão não é
       excepção: é só outra maneira de entrar. */
    $finais = array_map(fn($l) => ['li' => ['item_id' => (int)$l[0]['id']], 'q' => $l[1]], $linhas);
    if ($travao = barTravaoDe($conn, $gid, (int)$g['convite_id'], $finais)) erro($travao);
    $codigo = barCodigoCurto();
    $quem = (string)(utilizadorAtual() ?? '');
    $conviteId = (int)$g['convite_id'];
    // Quem decidiu só se escreve quando alguém decidiu. Um pedido submetido
    // pelo garçom nasce por decidir, e pôr-lhe aqui um nome e uma hora de
    // decisão era assinar por ele uma coisa que ele não fez — e os tempos da
    // copa («quanto demora a analisar») passavam a contar zeros que ninguém
    // gastou.
    $decidiu   = $daCopa ? '?,NOW()' : 'NULL,NULL';
    $entregou  = $jaEntregue ? '?,NOW(),NOW()' : 'NULL,NULL,NULL';
    $st = $conn->prepare("INSERT INTO {$P}bar_pedidos
            (casamento_id,codigo_curto,convidado_id,convite_id,mesa_id,estado,criado_por,criado_em,
             decidido_por,decidido_em,entregue_por,apanhado_em,entregue_em)
            VALUES (?,?,?,?,?,'$estado',?,NOW(),$decidiu,$entregou)");
    if ($jaEntregue)      $st->bind_param('isiiisss', $cid, $codigo, $gid, $conviteId, $mesaId, $quem, $quem, $quem);
    elseif ($daCopa)      $st->bind_param('isiiiss',  $cid, $codigo, $gid, $conviteId, $mesaId, $quem, $quem);
    else                  $st->bind_param('isiiis',   $cid, $codigo, $gid, $conviteId, $mesaId, $quem);
    if (!@$st->execute()) erro('Não foi possível lançar o pedido.');
    $pid = $conn->insert_id;
    foreach ($linhas as [$item, $q]) {
        $si = $conn->prepare("INSERT INTO {$P}bar_pedido_itens
                (casamento_id,pedido_id,item_id,nome_no_momento,quantidade) VALUES (?,?,?,?,?)");
        $iid = (int)$item['id']; $nome = (string)$item['nome'];
        $si->bind_param('iiisi', $cid, $pid, $iid, $nome, $q);
        @$si->execute();
    }
    // O stock segue a mesma regra de sempre (§4): aprovar PROMETE, entregar
    // BAIXA. Um pedido que nasce entregue faz as duas coisas de uma vez, e a
    // conta fica exactamente onde ficaria se tivesse passado pelos dois ecrãs.
    // Um que nasce POR DECIDIR não promete nada: quem promete é a aprovação, e
    // reservar aqui contaria a mesma garrafa duas vezes quando ela chegasse.
    foreach ($linhas as [$item, $q]) {
        if ($jaEntregue)   barMoverStock($conn, (int)$item['id'], -$q, 'entrega', $pid, 'lançado ao balcão');
        elseif ($daCopa)   barReservar($conn, (int)$item['id'], $q);
    }
    $resumo = implode(', ', array_map(fn($l) => $l[1] . '× ' . $l[0]['nome'], $linhas));
    registar($conn, 'bar_pedido_por', $g['nome'],
             '#' . $codigo . ' · ' . $resumo
           . ($jaEntregue ? ' · entregue no acto'
                          : ($daCopa ? ' · por entregar' : ' · à espera da copa')));
    ok(['pedido' => barPedidoLinha($conn, barPedido($conn, $pid), true),
        'estado' => barEstadoGeral($conn), 'itens' => barItens($conn, true)]);
}

if ($acao === 'bar_procurar_pessoal') {
    // A mesma procura, do lado de dentro: sem mínimo de letras nem tecto, que
    // aqui quem procura tem conta e é o seu trabalho.
    barCid();
    if (!podeEntregar() && !podeCopa()) erro('Só o pessoal do bar.');
    $cid = casamentoAtual();
    $q = barChave((string)($_GET['q'] ?? ''));
    // Trinta chegam para quem escreve um nome à procura de uma pessoa. Não
    // chegam para encher uma lista de escolha com procura por dentro — essa
    // quer a lista toda de uma vez, e depois filtra sem voltar ao servidor.
    $tecto = max(1, min(500, (int)($_GET['limite'] ?? 30)));
    $r = @$conn->query("SELECT g.id, g.nome, c.nome_exibicao, c.mesa_id, m.nome AS mesa
                        FROM {$P}convidados g
                        JOIN {$P}convites c ON c.id = g.convite_id AND c.casamento_id = g.casamento_id
                        LEFT JOIN {$P}mesas m ON m.id = c.mesa_id AND m.casamento_id = c.casamento_id
                        WHERE g.casamento_id=$cid AND " . soVivos($conn, 'c') . "
                        ORDER BY g.nome LIMIT 2000");
    $out = [];
    if ($r) while ($g = $r->fetch_assoc()) {
        if ($q !== '' && strpos(barChave((string)$g['nome']), $q) === false) continue;
        $out[] = ['id' => (int)$g['id'], 'nome' => $g['nome'], 'convite' => $g['nome_exibicao'],
                  'mesa_id' => $g['mesa_id'] === null ? null : (int)$g['mesa_id'], 'mesa' => $g['mesa']];
        if (count($out) >= $tecto) break;
    }
    ok(['nomes' => $out]);
}

if ($acao === 'bar_itens_pedir') {
    // As bebidas que o pessoal do bar pode lançar por alguém.
    //
    // Isto vivia dentro de `bar_estado`, que é a leitura da COPA — a fila, o
    // stock, as regras, os motivos, as notas dos garçons. O garçom não tem
    // acesso a ela (e bem: não é trabalho dele), mas o ecrã das entregas
    // pedia-lha à mesma para encher a lista da janela «Pedir por alguém».
    // Vinha um «Só a copa.», a lista chegava vazia, e a janela dizia «Não há
    // nada disponível para pedir» com a copa cheia de garrafas. O garçom
    // ficava sem poder lançar um pedido por quem não tem rede — que é metade
    // da razão de o posto existir (§14).
    //
    // A leitura é só o que a janela precisa. Aberta aos dois postos, não lhes
    // dá nada do resto da copa.
    barCid();
    if (!podeEntregar() && !podeCopa()) erro('Só o pessoal do bar.');
    $out = [];
    foreach (barItens($conn) as $i) {
        if ((int)$i['disponivel'] <= 0) continue;
        $out[] = ['id' => (int)$i['id'], 'nome' => $i['nome'],
                  'categoria' => (string)($i['categoria'] ?? ''),
                  'disponivel' => (int)$i['disponivel'],
                  'max_por_pedido' => (int)$i['max_por_pedido']];
    }
    ok(['itens' => $out, 'aberto' => barDef($conn, 'bar.aberto') === '1']);
}

// O CSRF das ações do pessoal do bar.
//
// A barreira geral (exigirCsrf, logo a seguir a exigirAdminApi) fica abaixo
// desta secção, porque o bar tem gente que não é admin: o copeiro e o
// garçom. Confere-se aqui, contra a mesma lista de config.php — as ações
// públicas do convidado não estão nela, e é por isso que passam sem token:
// não há sessão nenhuma para roubar, e a chave é o código da mesa.
if (str_starts_with($acao, 'bar_') && in_array($acao, acoesDeEscrita(), true)) {
    exigirCsrf();
}

// ---- Admin --------------------------------------------------
exigirAdminApi();

// Endpoints de admin que alteram dados: exigem token CSRF válido.
// A lista vive em config.php (acoesDeEscrita), para o ecrã poder desligar
// exatamente os mesmos controlos que o servidor recusaria.
if (in_array($acao, acoesDeEscrita(), true)) {
    exigirCsrf();
    // E, se estiver a ver a casa com um código de leitura, fica-se por ver.
    // As ações da própria plataforma (criar casamentos, mexer em contas) não
    // são dados de casamento nenhum e não passam por aqui.
    if (in_array($acao, acoesDoCasamento(), true)) exigirCorrecao();
}

// ---- Personalização do convite digital ---------------------
if ($acao === 'defs_save') {
    $d = corpo();
    $defs = is_array($d['defs'] ?? null) ? $d['defs'] : [];
    $nomeVersao = mb_substr(trim((string)($d['versao_nome'] ?? '')), 0, 80);
    // Os editores pedem a guarda do desenho da casa; quem chama a API em cru
    // (a Gestão, os cartões, uma prova) grava como sempre gravou.
    $proteger = !empty($d['proteger_desenho']);

    // Que peças é que esta gravação toca no DESENHO. Só o desenho está em
    // causa: os dados do casamento (nomes, datas, locais) são do casal e
    // gravam-se sempre — quem os escreve é a Gestão, não quem mexe num modelo.
    $tocaDesenho = [];
    foreach (array_keys(ambitosVersao()) as $amb) {
        $desenho = array_flip(chavesDesenho($amb));
        foreach (array_keys($defs) as $k) {
            if (isset($desenho[$k])) { $tocaDesenho[] = $amb; break; }
        }
    }

    // Desenhar a peça é o que separa os escalões «com edição» dos outros. Os
    // dados do casamento continuam a gravar-se — o que se recusa é mexer no
    // DESENHO de uma peça que a licença dá só para usar como está.
    foreach ($tocaDesenho as $amb) {
        if (!podeModulo($amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$amb]['rotulo'] ?? $amb) . '.');
        }
        if (!podeEditarPeca($amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe o ' . (ambitosVersao()[$amb]['rotulo'] ?? $amb)
               . ' no modelo padrão, sem edição. Para o desenhar à sua maneira, '
               . 'reforce a licença na página da Licença.');
        }
    }

    // Um MODELO da casa não se reescreve por baixo: ele serve todos os casais,
    // e as alterações deste casal nascem como versão dele, com nome. Sem nome
    // não se grava nada; o editor pede-o e volta a tentar.
    if ($proteger && $nomeVersao === '' && $tocaDesenho) {
        $daCasa = array_values(array_filter($tocaDesenho,
                    fn($amb) => pecaEmModeloDaCasa($conn, $amb)));
        if ($daCasa) {
            $base = versaoEstado($conn, $daCasa[0]);
            echo json_encode(['success' => false, 'precisa_versao' => true,
                'ambito' => $daCasa[0], 'base' => $base['nome'],
                'message' => '«' . $base['nome'] . '» é um desenho da casa, e serve todos os '
                           . 'casais: as suas alterações ficam numa versão sua. Dê-lhe um nome.']);
            exit;
        }
    }

    $r = guardarDefinicoes($conn, $defs);
    if ($r['gravadas'] || $r['repostas']) {
        // Mexer no desenho à mão tira o modelo de vigor: o que a peça mostra
        // deixou de ser puramente o dele. Guarda-se de onde veio, para se lhe
        // poder continuar a chamar pelo nome ("«Borgonha» · com alterações").
        foreach ($tocaDesenho as $amb) esquecerModeloEmVigor($conn, $amb, true);
        // Alterar o convite passa a deixar rasto, como já acontece com os convites.
        registar($conn, 'convite_editado_defs', '',
                 $r['gravadas'].' alterada(s), '.$r['repostas'].' reposta(s)');
    }
    // Com nome, o que se acabou de gravar fica guardado como versão do casal —
    // dela, e só dela: uma versão vive no casamento a que pertence.
    if ($nomeVersao !== '' && $tocaDesenho) {
        $id = criarVersaoDaPeca($conn, $tocaDesenho[0], $nomeVersao);
        $r['versao'] = ['id' => $id, 'nome' => $nomeVersao, 'ambito' => $tocaDesenho[0]];
    }
    ok($r);
}

// ---- Versões dos convites -----------------------------------
// Cada peça (digital / impresso) tem as suas versões, e uma delas está em
// vigor — a predefinida. Tornar predefinida é aplicar: as definições dessa
// versão passam a ser as que o convite usa.

/** Âmbito pedido, validado. */
function ambitoPedido(): string {
    $a = $_GET['ambito'] ?? (corpo()['ambito'] ?? 'digital');
    return isset(ambitosVersao()[$a]) ? $a : 'digital';
}

/**
 * Guarda a peça, tal como está agora, como versão deste casamento.
 *
 * Uma versão é do casal e só dele: vive em cw_versoes com o seu casamento_id,
 * e não há por onde outro casal lá chegar. É o que permite mexer num desenho
 * da casa sem o reescrever — o que sai daqui é uma peça nova, com nome.
 */
function criarVersaoDaPeca(mysqli $conn, string $ambito, string $nome): int {
    global $P;
    $nome = mb_substr(trim($nome), 0, 80);
    if ($nome === '') erro('Dê um nome à versão, para a reconhecer mais tarde.');

    $st = $conn->prepare("SELECT COUNT(*) FROM {$P}versoes WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    if ((int)$st->get_result()->fetch_row()[0] >= VERSOES_MAX) {
        erro('Chegou ao máximo de '.VERSOES_MAX.' versões desta peça. Apague uma para guardar outra.');
    }
    $json = jsonOuNulo(instantaneoAmbito($conn, $ambito));
    if ($json === null) erro('Não foi possível preparar a versão.');

    $u = utilizadorAtual() ?? '';
    $st = $conn->prepare("INSERT INTO {$P}versoes (casamento_id, nome, defs, utilizador, ambito) VALUES (" . casamentoAtual() . ",?,?,?,?)");
    $st->bind_param('ssss', $nome, $json, $u, $ambito);
    if (!$st->execute()) erro('Não foi possível guardar a versão.');
    $id = $conn->insert_id;
    // Uma versão acabada de guardar É a peça neste momento — foi tirada dela.
    // Antes só a primeira ficava marcada, e a marca ficava presa numa versão
    // antiga enquanto o convite já mostrava outra coisa.
    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE " . doCasamento() . " AND id=$id");
    // A peça passa a ser desta versão, e não do modelo de onde veio.
    esquecerModeloEmVigor($conn, $ambito);
    // As fotos trocadas ficaram guardadas nesta versão: largam o estado de
    // «por confirmar» e apaga-se o que já não serve.
    if ($ambito === 'digital') assentarMediaPendente($conn);
    registar($conn, 'versao_guardada', $nome, ambitosVersao()[$ambito]['rotulo']);
    return $id;
}

if ($acao === 'versao_criar') {

    // Uma versão é um desenho guardado: quem leva a peça sem edição não tem
    // desenhos seus para guardar, e por isso não chega aqui.
    {
        $_amb = ambitoPedido();
        if (!podeModulo($_amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$_amb]['rotulo'] ?? $_amb) . '.');
        }
        if (!podeEditarPeca($_amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe esta peça no modelo padrão, sem edição. '
               . 'Para guardar versões suas, reforce a licença na página da Licença.');
        }
    }
    $d = corpo();
    ok(['id' => criarVersaoDaPeca($conn, ambitoPedido(), (string)($d['nome'] ?? ''))]);
}

if ($acao === 'versao_lista') {
    exigirModuloApi(ambitoPedido());
    $ambito = ambitoPedido();
    $st = $conn->prepare("SELECT id, nome, utilizador, criado_em, atualizado_em, predefinida, defs
                          FROM {$P}versoes WHERE " . doCasamento() . " AND ambito=? ORDER BY predefinida DESC, id DESC");
    $st->bind_param('s', $ambito); $st->execute();
    $linhas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = [];
    $algumaEmVigor = false;

    foreach ($linhas as $v) {
        // "Em vigor" é uma verdade sobre a peça, não uma marca guardada: é-o a
        // versão cujo conteúdo bate certo com o que a peça mostra agora. Uma
        // marca guardada acabava a mentir — dizia "em vigor" numa versão
        // enquanto o convite enviado mostrava outra coisa.
        $v['em_vigor'] = versaoIgualAoAtual($conn, $ambito, $v['defs']);
        if ($v['em_vigor']) $algumaEmVigor = true;
        unset($v['defs']);                       // a lista não precisa do conteúdo
        $v['escolhida'] = (int)$v['predefinida']; // a última que o utilizador aplicou
        unset($v['predefinida']);
        $v['padrao'] = 0;
        $out[] = $v;
    }
    // As que estão em vigor à cabeça; depois as mais recentes.
    usort($out, fn($a, $b) => ($b['em_vigor'] <=> $a['em_vigor']) ?: ($b['id'] <=> $a['id']));

    // A versão padrão fecha a lista, sempre no mesmo sítio: é a peça como o
    // sistema a traz de origem — o ponto de regresso. Não vem da tabela, por
    // isso não se apaga nem se reescreve; quem a editar guarda com outro nome.
    // Em vigor quando a peça repousa no desenho de origem — o do modelo de
    // origem (designado pelo admin, ou o de fábrica), e não só o de fábrica cru.
    $naOrigem = naOrigem($conn, $ambito);
    if ($naOrigem) $algumaEmVigor = true;
    // O nome é o do modelo de origem da casa — não «Original», que não é
    // modelo nenhum. O padrão continua a ser o ponto de regresso (padrao=1).
    $out[] = ['id' => VERSAO_PADRAO_ID, 'nome' => nomeDaOrigem($conn, $ambito), 'utilizador' => null,
              'criado_em' => null, 'atualizado_em' => null,
              'em_vigor' => $naOrigem, 'escolhida' => 0, 'padrao' => 1];

    ok(['versoes' => $out, 'max' => VERSOES_MAX, 'ambito' => $ambito,
        'rotulo' => ambitosVersao()[$ambito]['rotulo'],
        // Sem nenhuma a bater certo, a peça tem alterações que não estão
        // guardadas em versão nenhuma — e o painel tem de o dizer.
        'alguma_em_vigor' => $algumaEmVigor]);
}

if ($acao === 'versao_aplicar') {

    // Uma versão é um desenho guardado: quem leva a peça sem edição não tem
    // desenhos seus para guardar, e por isso não chega aqui.
    {
        $_amb = ambitoPedido();
        if (!podeModulo($_amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$_amb]['rotulo'] ?? $_amb) . '.');
        }
        if (!podeEditarPeca($_amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe esta peça no modelo padrão, sem edição. '
               . 'Para guardar versões suas, reforce a licença na página da Licença.');
        }
    }
    // Torna esta a versão em vigor: aplica as suas definições e marca-a.
    $id = (int)($_GET['id'] ?? 0);

    // A padrão não está na tabela: aplicá-la é devolver a peça à origem — ao
    // desenho do modelo de origem (o que o admin designou, ou o de fábrica).
    // Repõe-se tudo de fábrica e, por cima, escreve-se o desenho desse modelo;
    // quando ele traz o desenho de fábrica (o caso comum), dá exatamente o
    // mesmo que um regresso puro à origem. É o nome DELE que a peça passa a dar.
    if ($id === VERSAO_PADRAO_ID) {
        $ambito = ambitoPedido();
        $base = padraoAmbito($ambito);
        $orig = modeloDeOrigem($conn, $ambito);
        if ($orig) {
            $des = desenhoDoModeloId($conn, $ambito, (int)$orig['id']);
            if ($des) $base = array_merge($base, $des);
        }
        $r = guardarDefinicoes($conn, $base);
        $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
        $st->bind_param('s', $ambito); $st->execute();
        esquecerModeloEmVigor($conn, $ambito);
        if ($ambito === 'digital') assentarMediaPendente($conn);
        $nomeOrigem = nomeDaOrigem($conn, $ambito);
        registar($conn, 'versao_aplicada', $nomeOrigem, $r['repostas'].' definição(ões)');
        ok($r + ['nome' => $nomeOrigem, 'ambito' => $ambito]);
    }

    $st = $conn->prepare("SELECT nome, defs, ambito FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i', $id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    if (!$v) erro('Versão não encontrada.');
    $j = json_decode($v['defs'], true);
    if (!is_array($j)) erro('Esta versão está ilegível.');

    // Só as chaves do próprio âmbito: aplicar uma versão do cartão não pode
    // mexer no convite digital, nem o contrário.
    $permitidas = array_flip(chavesDoAmbito($v['ambito']));
    $defs = [];
    foreach ($j as $k => $val) if (isset($permitidas[$k]) && is_string($val)) $defs[$k] = $val;
    $r = guardarDefinicoes($conn, $defs);

    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $v['ambito']); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE " . doCasamento() . " AND id=$id");
    esquecerModeloEmVigor($conn, $v['ambito']);
    if ($v['ambito'] === 'digital') assentarMediaPendente($conn);

    registar($conn, 'versao_aplicada', $v['nome'], $r['gravadas'].' definição(ões)');
    ok($r + ['nome' => $v['nome'], 'ambito' => $v['ambito']]);
}

if ($acao === 'versao_atualizar') {

    // Uma versão é um desenho guardado: quem leva a peça sem edição não tem
    // desenhos seus para guardar, e por isso não chega aqui.
    {
        $_amb = ambitoPedido();
        if (!podeModulo($_amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$_amb]['rotulo'] ?? $_amb) . '.');
        }
        if (!podeEditarPeca($_amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe esta peça no modelo padrão, sem edição. '
               . 'Para guardar versões suas, reforce a licença na página da Licença.');
        }
    }
    // Reescreve o conteúdo da versão com o que está em vigor agora.
    $id = (int)($_GET['id'] ?? 0);
    // A peça de origem (o modelo da casa) não se reescreve.
    if ($id === VERSAO_PADRAO_ID) erro('«'.nomeDaOrigem($conn, ambitoPedido()).'» é o desenho de origem da casa: não se reescreve. Guarde as suas alterações como uma versão nova.');
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i', $id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    if (!$v) erro('Versão não encontrada.');
    $json = jsonOuNulo(instantaneoAmbito($conn, $v['ambito']));
    if ($json === null) erro('Não foi possível preparar a versão.');
    $st = $conn->prepare("UPDATE {$P}versoes SET defs=?, atualizado_em=NOW() WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('si', $json, $id);
    if (!$st->execute()) erro('Não foi possível atualizar a versão.');
    if ($v['ambito'] === 'digital') assentarMediaPendente($conn);
    registar($conn, 'versao_atualizada', $v['nome'], '');
    ok(['nome' => $v['nome']]);
}

if ($acao === 'versao_renomear') {

    // Uma versão é um desenho guardado: quem leva a peça sem edição não tem
    // desenhos seus para guardar, e por isso não chega aqui.
    {
        $_amb = ambitoPedido();
        if (!podeModulo($_amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$_amb]['rotulo'] ?? $_amb) . '.');
        }
        if (!podeEditarPeca($_amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe esta peça no modelo padrão, sem edição. '
               . 'Para guardar versões suas, reforce a licença na página da Licença.');
        }
    }
    $d = corpo();
    $id = (int)($_GET['id'] ?? ($d['id'] ?? 0));
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('O nome não pode ficar vazio.');
    // A peça de origem (o modelo da casa) não muda de nome por aqui.
    if ($id === VERSAO_PADRAO_ID) erro('«'.nomeDaOrigem($conn, ambitoPedido()).'» é o desenho de origem da casa: muda-se de nome nos Modelos, não aqui.');
    $st = $conn->prepare("UPDATE {$P}versoes SET nome=? WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('si', $nome, $id);
    if (!$st->execute()) erro('Não foi possível mudar o nome.');
    registar($conn, 'versao_renomeada', $nome, 'id '.$id);
    ok(['nome' => $nome]);
}

if ($acao === 'versao_apagar') {

    // Uma versão é um desenho guardado: quem leva a peça sem edição não tem
    // desenhos seus para guardar, e por isso não chega aqui.
    {
        $_amb = ambitoPedido();
        if (!podeModulo($_amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$_amb]['rotulo'] ?? $_amb) . '.');
        }
        if (!podeEditarPeca($_amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe esta peça no modelo padrão, sem edição. '
               . 'Para guardar versões suas, reforce a licença na página da Licença.');
        }
    }
    $id = (int)($_GET['id'] ?? 0);
    // A peça de origem (o modelo da casa) não se apaga.
    if ($id === VERSAO_PADRAO_ID) erro('«'.nomeDaOrigem($conn, ambitoPedido()).'» é o desenho de origem da casa: não se apaga.');
    $rn = $conn->prepare("SELECT nome, ambito, predefinida, defs FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $rn->bind_param('i', $id); $rn->execute();
    $x = $rn->get_result()->fetch_assoc();
    if (!$x) erro('Versão não encontrada.');
    // As fotografias que ESTA versão trazia — para as poder apagar do disco a
    // seguir, se mais ninguém as usar. Lêem-se antes de apagar a versão.
    $fotosDaVersao = [];
    $j = json_decode((string)$x['defs'], true);
    if (is_array($j)) {
        foreach ($j as $k => $val) {
            if (is_string($k) && str_starts_with($k, 'media.') && is_string($val)
                && ehFotoCustom($val)) {
                $fotosDaVersao[] = $val;
            }
        }
    }
    $st = $conn->prepare("DELETE FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar a versão.');
    // Apagar a versão apaga as fotos que só ela tinha anexadas — mas nunca uma
    // que a peça ainda mostra (está nas definições em vigor) ou que outra versão
    // guardada ainda usa. Assim não se acumulam ficheiros órfãos no disco, e não
    // se parte uma foto que continua a ser precisa noutro lado.
    $emUso = defsAtuais($conn);
    $emUsoAgora = [];
    foreach ($emUso as $vv) if (is_string($vv)) $emUsoAgora[$vv] = true;
    $apagadas = 0;
    foreach (array_unique($fotosDaVersao) as $cam) {
        if (isset($emUsoAgora[$cam])) continue;                 // a peça ainda a mostra
        if (ficheiroEmVersao($conn, $cam)) continue;            // outra versão ainda a usa
        @unlink(__DIR__ . '/' . $cam);
        $apagadas++;
    }
    // Apagar não muda a peça — só se perde o ponto de regresso. Antes promovia-se
    // outra a "em vigor" sem lhe aplicar nada, o que era falso: ficava marcada
    // uma versão cujo conteúdo não era o que o convite mostrava.
    registar($conn, 'versao_apagada', $x['nome'],
             ambitosVersao()[$x['ambito']]['rotulo'] . ($apagadas ? " · $apagadas foto(s) apagada(s)" : ''));
    ok(['fotos_apagadas' => $apagadas]);
}

if ($acao === 'upload_chunk') {
    // Recebe um PEDAÇO de um ficheiro grande e junta-o ao que já veio, num
    // ficheiro temporário. Cada pedaço é pequeno de propósito (o cliente parte
    // em ~1 MB), para passar em alojamentos que limitam cada envio a 2 MB. O
    // 'token' identifica o ficheiro em construção; devolve-se no primeiro pedaço
    // e o cliente reenvia-o nos seguintes. Quando o último chega, o
    // def_upload/modelo_exemplo_upload consome-o pelo mesmo token.
    $dir = chunkDir();
    foreach (glob($dir . '/*.part') ?: [] as $velho) {
        if (@filemtime($velho) < time() - 3600) @unlink($velho);   // restos de +1h
    }
    if ($p = problemaUpload('ficheiro', 3 * 1024 * 1024)) erro($p);   // cada pedaço ≤ 3 MB
    $i = (int)($_POST['i'] ?? -1);
    $n = (int)($_POST['n'] ?? 0);
    if ($n < 1 || $n > 64 || $i < 0 || $i >= $n) erro('Pedaço de envio inválido.');
    $token = (string)($_POST['token'] ?? '');
    if ($i === 0)                                    $token = bin2hex(random_bytes(16));
    elseif (!preg_match('/^[a-f0-9]{32}$/', $token)) erro('Sessão de envio inválida.');
    $part = $dir . '/' . $token . '.part';
    if ($i === 0)              @unlink($part);        // recomeça do zero
    elseif (!is_file($part))   erro('O envio por partes perdeu-se. Recomece.');
    $ok = false;
    if (($in = @fopen($_FILES['ficheiro']['tmp_name'], 'rb'))) {
        if (($out = @fopen($part, 'ab'))) { stream_copy_to_stream($in, $out); fclose($out); $ok = true; }
        fclose($in);
    }
    if (!$ok) erro('Não foi possível guardar o pedaço.');
    if (@filesize($part) > 12 * 1024 * 1024) { @unlink($part); erro('Ficheiro demasiado grande.'); }
    ok(['token' => $token, 'done' => ($i === $n - 1)]);
}

if ($acao === 'def_upload') {
    // Upload de imagem/música do convite (grava o ficheiro e a definição). O
    // ficheiro pode vir de uma vez ($_FILES) ou montado por pedaços (chunk_token,
    // para a música passar em servidores com limite de envio baixo).
    $chave = $_POST['chave'] ?? '';
    $tiposImg = ['media.hero','media.historia','media.interludio','media.acesso'];
    $ehMusica = $chave === 'media.musica';
    $max = $ehMusica ? 8*1024*1024 : 5*1024*1024;
    $src = origemUpload('ficheiro', $max);
    if (!$ehMusica && !in_array($chave, $tiposImg, true)) erro('Campo de ficheiro inválido.');
    $ext = strtolower(pathinfo($src['nome'], PATHINFO_EXTENSION));
    $extsOk = $ehMusica ? ['m4a','mp3'] : ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $extsOk, true)) erro('Formato não suportado (' . implode('/', $extsOk) . ').');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $src['tmp']); finfo_close($fi);
        $mimesOk = $ehMusica ? ['audio/mp4','audio/x-m4a','audio/mpeg','video/mp4','audio/mp3']
                             : ['image/jpeg','image/png','image/webp'];
        if (!in_array($mt, $mimesOk, true)) erro('O conteúdo do ficheiro não corresponde ao formato.');
    }
    $dir = pastaDeEnvios(CUSTOM_FOTO_DIR);
    $nomeFich = str_replace('media.', '', $chave) . '-' . time() . '-' . random_int(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!moverUpload($src, "$dir/$nomeFich")) erro('Não foi possível guardar o ficheiro.');
    $caminho = CUSTOM_FOTO_DIR . '/' . $nomeFich;
    // A troca fica logo à vista na tela, mas é PROVISÓRIA: só fica mesmo se o
    // casal guardar/actualizar uma versão. Por isso não se apaga já o ficheiro
    // anterior — pode ser preciso repô-lo se o casal sair sem guardar. Guarda-se
    // o par (novo, anterior) para o poder desfazer. A música (media.musica) não
    // entra neste jogo: não é foto e não anda por versões.
    $antigo = (string)(defsAtuais($conn)[$chave] ?? '');
    if (!$ehMusica) {
        marcarMediaPendente($conn, $chave, $caminho, $antigo);
    } elseif (ehFotoCustom($antigo) && !ficheiroEmVersao($conn, $antigo)) {
        @unlink(__DIR__ . '/' . $antigo);   // música: comporta-se como antes
    }
    guardarDefinicoes($conn, [$chave => $caminho]);
    ok(['path' => $caminho]);
}

if ($acao === 'media_descartar') {
    // O casal saiu do editor sem guardar: repõem-se as fotos trocadas no valor
    // anterior e apagam-se os ficheiros novos. É o pedido que a página envia ao
    // sair (pagehide), por sendBeacon. Sem trocas por guardar, não faz nada.
    exigirCorrecao();
    $n = descartarMediaPendente($conn);
    ok(['apagados' => $n]);
}

if ($acao === 'def_media_repor') {
    // Repõe fotografias (media.*) no valor de origem e APAGA o ficheiro custom
    // que o casal tinha enviado para essa secção — a não ser que uma versão
    // guardada ainda o use. Serve o "Repor Secção" do editor, que larga mesmo a
    // foto posta à mão. (media.* está fora da gravação normal — ver ALHEIAS.)
    exigirCorrecao();
    $d = corpo();
    $chaves = is_array($d['chaves'] ?? null) ? $d['chaves'] : [];
    $padrao = defsPadrao();
    $atuais = defsAtuais($conn);
    $novos = []; $limpos = [];
    foreach ($chaves as $k) {
        if (!is_string($k) || !str_starts_with($k, 'media.')) continue;
        if (!array_key_exists($k, $padrao)) continue;
        $antigo = (string)($atuais[$k] ?? '');
        if (ehFotoCustom($antigo) && !ficheiroEmVersao($conn, $antigo)) {
            @unlink(__DIR__ . '/' . $antigo);
            $limpos[] = $antigo;
        }
        $novos[$k] = (string)$padrao[$k];
    }
    if ($novos) guardarDefinicoes($conn, $novos);
    registar($conn, 'media_reposta', implode(', ', array_keys($novos)), count($limpos) . ' ficheiro(s) apagado(s)');
    ok(['repostas' => array_keys($novos), 'apagados' => count($limpos)]);
}

// ============================================================
// AS FOTOGRAFIAS DO CONVITE, FORA DO EDITOR
//
// As fotografias são do casal — não do desenho, nem da licença. Pediam-se na
// inscrição, uma vez, porque o escalão sem edição as fixava para sempre; e
// quem tinha edição trocava-as no editor, entre camadas, réguas e painéis, que
// é uma oficina para quem só quer pôr uma fotografia sua.
//
// Passam a ter área própria na página do convite digital: uma secção, uma
// fotografia, e pronto. Vale para qualquer licença que traga o convite —
// incluindo a que não deixa editar o desenho, porque trocar a fotografia não é
// mexer no desenho.
//
// O que se grava aqui é DEFINITIVO: não passa pelo jogo do «pendente» do
// editor (marcarMediaPendente), que existe para se poder sair sem guardar.
// Aqui não há sair sem guardar — carregou, ficou.
// ============================================================

/** A secção de fotografia com esta chave, ou null. */
function fotoSeccao(mysqli $conn, string $chave): ?array {
    foreach (seccoesDeFoto($conn, defsAtuais($conn)) as $sc) {
        if ($sc['chave'] === $chave) return $sc;
    }
    return null;
}

/**
 * Põe uma fotografia numa secção e larga a que lá estava.
 *
 * O ficheiro anterior só sai do disco se for de envio (custom/, ou a antiga
 * licenca/) e se nenhuma VERSÃO guardada ainda o usar — apagá-lo aí era
 * estragar uma versão que o casal gravou. É a mesma regra do editor.
 *
 * Nas secções que recortam, o enquadramento vai junto: o que lá estava tinha
 * sido escolhido para a fotografia anterior e, numa composição nova, corta no
 * sítio errado. Sem $enqValor volta ao centro (é o que o editor faz); com ele,
 * fica exatamente esse — é assim que «voltar à de origem» devolve também o
 * enquadramento de origem.
 */
function fotoTrocar(mysqli $conn, string $chave, string $novo,
                    string $enqChave = '', ?string $enqValor = null): void {
    $antigo  = (string)(defsAtuais($conn)[$chave] ?? '');
    $guardar = [$chave => $novo];
    if ($enqChave !== '') {
        if ($enqValor === null) {
            $e = lerEnquadramento((string)(defsAtuais($conn)[$enqChave] ?? ''));
            $enqValor = '50 50 ' . (int)$e['zoom'];
        }
        $guardar[$enqChave] = $enqValor;
    }
    guardarDefinicoes($conn, $guardar);
    if ($antigo !== '' && $antigo !== $novo
        && ehFotoCustom($antigo) && !ficheiroEmVersao($conn, $antigo)) {
        @unlink(__DIR__ . '/' . $antigo);
    }
}

if ($acao === 'convite_fotos') {
    exigirModuloApi('digital');
    ok(['seccoes' => seccoesDeFoto($conn, defsAtuais($conn)),
        'max_mb'  => (int)round(FOTO_CONVITE_MAX / 1048576)]);
}

if ($acao === 'convite_foto_enviar') {
    exigirModuloApi('digital');
    $chave = (string)($_POST['chave'] ?? '');
    $sc = fotoSeccao($conn, $chave);
    if (!$sc) erro('Essa secção não tem fotografia no vosso convite.');

    $src = origemUpload('ficheiro', FOTO_CONVITE_MAX);
    // O nome do ficheiro não prova nada: o que manda é o conteúdo.
    $inf = @getimagesize($src['tmp']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$inf || !isset($tipos[(int)$inf[2]])) {
        erro('O ficheiro não é uma imagem que saibamos ler (jpg, png ou webp).');
    }
    if ((int)$inf[0] < 400 || (int)$inf[1] < 400) {
        erro('A fotografia é pequena de mais para o convite (mínimo 400×400).');
    }
    $ext = $tipos[(int)$inf[2]];
    $dir = pastaDeEnvios(CUSTOM_FOTO_DIR);
    $nomeFich = str_replace('media.', '', $chave) . '-' . time()
              . '-' . random_int(100, 999) . '.' . $ext;
    if (!moverUpload($src, "$dir/$nomeFich")) erro('Não foi possível guardar a fotografia.');
    $caminho = CUSTOM_FOTO_DIR . '/' . $nomeFich;
    fotoTrocar($conn, $chave, $caminho, (string)$sc['enq']);
    registar($conn, 'convite_foto', $chave, basename((string)$src['nome']));
    ok(['chave' => $chave, 'src' => $caminho,
        'seccoes' => seccoesDeFoto($conn, defsAtuais($conn))]);
}

// A galeria da casa não se abre aqui. É material de modelo — está lá para quem
// os desenha, e chega ao casal já escolhida, dentro do modelo que ele tem. Ao
// casal cabe a fotografia dele: manda a sua, ou fica com a que o modelo deu.

if ($acao === 'convite_foto_repor') {
    exigirModuloApi('digital');
    $d = corpo();
    $chave = (string)($d['chave'] ?? '');
    $sc = fotoSeccao($conn, $chave);
    if (!$sc) erro('Essa secção não tem fotografia no vosso convite.');
    // De origem é de origem: a fotografia e o enquadramento com que ela nasceu.
    $enq = (string)$sc['enq'];
    fotoTrocar($conn, $chave, (string)$sc['origem'], $enq,
               $enq !== '' ? (string)(defsPadrao()[$enq] ?? '50 50 100') : null);
    registar($conn, 'convite_foto_reposta', $chave, 'voltou à fotografia de origem');
    ok(['chave' => $chave, 'src' => $sc['origem'],
        'seccoes' => seccoesDeFoto($conn, defsAtuais($conn))]);
}

/**
 * O enquadramento de uma secção: que ponto da fotografia fica à vista.
 *
 * As secções que recortam mostram uma janela estreita de uma fotografia larga;
 * qual pedaço lá cabe era coisa que só o editor sabia ajustar. Uma fotografia
 * cortada pelo meio da cara não se resolve escolhendo outra fotografia.
 */
if ($acao === 'convite_foto_posicao') {
    exigirModuloApi('digital');
    $d = corpo();
    $chave = (string)($d['chave'] ?? '');
    $sc = fotoSeccao($conn, $chave);
    if (!$sc) erro('Essa secção não tem fotografia no vosso convite.');
    if ((string)$sc['enq'] === '') erro('Esta fotografia aparece inteira — não há o que enquadrar.');
    $x = max(0.0, min(100.0, (float)($d['x'] ?? 50)));
    $y = max(0.0, min(100.0, (float)($d['y'] ?? 50)));
    // A aproximação é do editor; aqui mexe-se no ponto, e ela fica como estava.
    $zoom = (int)lerEnquadramento((string)(defsAtuais($conn)[$sc['enq']] ?? ''))['zoom'];
    guardarDefinicoes($conn, [$sc['enq'] => round($x, 1) . ' ' . round($y, 1) . ' ' . $zoom]);
    registar($conn, 'convite_foto_posicao', $chave, round($x) . '% ' . round($y) . '%');
    ok(['chave' => $chave, 'seccoes' => seccoesDeFoto($conn, defsAtuais($conn))]);
}

if ($acao === 'convite_list') {
    exigirModuloApi('convidados');
    $tipo=$_GET['tipo']??''; $lado=$_GET['lado']??''; $estado=$_GET['estado']??'';
    $mesa=$_GET['mesa']??''; $busca=trim($_GET['busca']??'');
    $impresso=$_GET['impresso']??''; $enviado=$_GET['enviado']??'';
    $genero=$_GET['genero']??''; $brinde=$_GET['brinde']??'';
    $temGen = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBri = colunaExiste($conn, "{$P}convidados", 'brinde');
    $temPapel = colunaExiste($conn, "{$P}convidados", 'papel');
    $exprGen = $temGen ? "COALESCE(g.genero,'')" : "''";
    $exprBri = $temBri ? "g.brinde" : "0";
    // Id da mesa dos noivos: padrinhos/madrinhas sentam-se lá pelo papel (não por mesa_id).
    $noivosId = 0;
    if ($temPapel) { $nr=$conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1"); if ($nr && $row=$nr->fetch_row()) $noivosId=(int)$row[0]; }
    // Mesa EFETIVA de um membro (alias): a dos noivos se for padrinho/madrinha, senão a própria/do convite.
    $effMesa = function(string $a) use ($temPapel,$noivosId) {
        return $temPapel
            ? "CASE WHEN {$a}.papel IN ('padrinho','madrinha') THEN ".($noivosId?:'NULL')." ELSE COALESCE({$a}.mesa_id, c.mesa_id) END"
            : "COALESCE({$a}.mesa_id, c.mesa_id)";
    };
    $temMesaExpr = fn(string $a) => $temPapel ? "({$a}.mesa_id IS NOT NULL OR {$a}.papel IN ('padrinho','madrinha'))" : "{$a}.mesa_id IS NOT NULL";
    // O âmbito abre o WHERE: tudo o que se filtre a seguir já é deste casamento.
    $w="WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c'); $t=''; $p=[];   // fora os que estão na reciclagem
    if (in_array($tipo,['digital','fisico','ambos'],true))            { $w.=" AND c.tipo=?"; $t.='s'; $p[]=$tipo; }
    if (in_array($lado,['noivo','noiva','ambos'],true))              { $w.=" AND c.lado=?"; $t.='s'; $p[]=$lado; }
    // Filtro por estado: além do estado do convite, inclui convites com um integrante
    // nesse estado (ex.: "pendentes" mostra também os parciais com gente ainda pendente).
    if ($estado==='pendente') {
        // Pendentes inclui os totalmente pendentes, os parciais (têm lugares por confirmar)
        // e os que têm algum integrante ainda pendente.
        $w.=" AND (c.rsvp_estado IN ('pendente','parcial') OR EXISTS(SELECT 1 FROM {$P}convidados ge WHERE ge.convite_id=c.id AND ge.rsvp='pendente'))";
    } elseif (in_array($estado,['confirmado','recusado'],true)) {
        $w.=" AND (c.rsvp_estado=? OR EXISTS(SELECT 1 FROM {$P}convidados ge WHERE ge.convite_id=c.id AND ge.rsvp=?))";
        $t.='ss'; $p[]=$estado; $p[]=$estado;
    } elseif ($estado==='parcial') { $w.=" AND c.rsvp_estado='parcial'"; }
    if ($impresso==='1') { $w.=" AND c.impresso=1"; }
    if ($impresso==='0') { $w.=" AND c.impresso=0 AND c.tipo IN ('fisico','ambos')"; }
    if ($enviado==='1')  { $w.=" AND c.enviado=1"; }
    // Convites com pelo menos um integrante do género escolhido / que recebe brinde (só se as colunas existirem)
    if ($temGen && in_array($genero,['m','f'],true)) { $w.=" AND EXISTS (SELECT 1 FROM {$P}convidados gg WHERE gg.convite_id=c.id AND gg.genero=?)"; $t.='s'; $p[]=$genero; }
    if ($temBri && $brinde==='1')                    { $w.=" AND EXISTS (SELECT 1 FROM {$P}convidados gb WHERE gb.convite_id=c.id AND gb.brinde=1)"; }
    if ($mesa==='__SEM_MESA__') {
        // Com pelo menos um lugar por colocar: sem mesa de convite e com alguém (ou lugar sem nome) ainda por sentar.
        $w.=" AND c.mesa_id IS NULL AND ( EXISTS (SELECT 1 FROM {$P}convidados gs WHERE gs.convite_id=c.id AND gs.mesa_id IS NULL)
                                        OR c.lugares > (SELECT COUNT(*) FROM {$P}convidados gn WHERE gn.convite_id=c.id) )";
    }
    elseif ($mesa!=='') {
        // Presença EFETIVA nesta mesa: um membro sentado lá (mesa própria, senão a do convite),
        // ou lugares sem nome de um convite cuja mesa é esta. Se a mesa filtrada for a dos
        // noivos, inclui também padrinhos/madrinhas (que lá se sentam pelo papel, não por mesa_id).
        $exprPad = $temPapel
            ? " OR EXISTS (SELECT 1 FROM {$P}convidados gp WHERE gp.convite_id=c.id AND gp.papel IN ('padrinho','madrinha')
                           AND EXISTS (SELECT 1 FROM {$P}mesas mn WHERE mn.especial='noivos' AND mn.nome=?))"
            : "";
        $w.=" AND ( EXISTS (SELECT 1 FROM {$P}convidados gm JOIN {$P}mesas mm ON mm.id=COALESCE(gm.mesa_id,c.mesa_id)
                            WHERE gm.convite_id=c.id AND mm.nome=?)
                  OR ( m.nome=? AND c.lugares > (SELECT COUNT(*) FROM {$P}convidados gc WHERE gc.convite_id=c.id) )
                  $exprPad )";
        $t.='ss'; $p[]=$mesa; $p[]=$mesa;
        if ($temPapel) { $t.='s'; $p[]=$mesa; }
    }
    if ($busca!==''){ $w.=" AND (c.nome_exibicao LIKE ? OR c.codigo LIKE ? OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.nome LIKE ?))";
                      $t.='sss'; $l="%$busca%"; $p[]=$l; $p[]=$l; $p[]=$l; }
    $sql="SELECT c.*, m.nome AS mesa_nome,
                 COALESCE(
                   (SELECT mm.nome FROM {$P}convidados g4 JOIN {$P}mesas mm ON mm.id={$effMesa('g4')}
                      WHERE g4.convite_id=c.id ORDER BY {$temMesaExpr('g4')} DESC, mm.nome LIMIT 1),
                   m.nome
                 ) AS mesa_efetiva_nome,
                 GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR '||') AS membros_txt,
                 GROUP_CONCAT(CONCAT_WS('\x1f', g.nome, $exprGen, $exprBri)
                              ORDER BY g.principal DESC, g.nome SEPARATOR '\x1e') AS membros_det,
                 (SELECT COUNT(DISTINCT {$effMesa('g2')})
                    FROM {$P}convidados g2 WHERE g2.convite_id=c.id) AS mesas_distintas
          FROM {$P}convites c
          LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
          LEFT JOIN {$P}convidados g ON g.convite_id=c.id
          $w GROUP BY c.id ORDER BY c.nome_exibicao";

    // Quantos convites correspondem ao filtro (para o "mostrar mais" saber
    // quantos faltam). É uma consulta leve: só conta, não junta membros.
    $sqlTotal = "SELECT COUNT(*) FROM {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id $w";
    $stc = $conn->prepare($sqlTotal);
    if ($t) $stc->bind_param($t, ...$p);
    $stc->execute();
    $total = (int)($stc->get_result()->fetch_row()[0] ?? 0);

    // Traz-se um pedaço de cada vez: com centenas de convites, mandar tudo de
    // uma vez enche a rede e o telemóvel demora a desenhar a lista.
    $porPag = (int)($_GET['por_pagina'] ?? LISTA_POR_PAGINA);
    $porPag = max(10, min(1000, $porPag));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $sql   .= " LIMIT " . $porPag . " OFFSET " . (($pagina - 1) * $porPag);

    $st=$conn->prepare($sql);
    if ($t) $st->bind_param($t, ...$p);
    $st->execute();
    $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['nome_final']=nomeConvite($r);
        $r['membros']=$r['membros_txt']?explode('||',$r['membros_txt']):[];
        // Detalhe por integrante (nome, género, brinde) para as pastilhas com ícones.
        $det = (string)($r['membros_det'] ?? '');
        $lista=[];
        if ($det!=='') foreach (explode("\x1e", $det) as $linha) {
            $c3=explode("\x1f", $linha);
            $lista[]=['nome'=>$c3[0]??'', 'genero'=>$c3[1]??'', 'brinde'=>(int)($c3[2]??0)];
        }
        $r['membros_det']=$lista;
        unset($r['membros_txt']);
    }
    unset($r);
    ok(['convites'=>$rows, 'stats'=>estatisticas($conn), 'mesas'=>listarMesas($conn),
        'total'=>$total, 'pagina'=>$pagina, 'por_pagina'=>$porPag,
        'ha_mais'=>($pagina * $porPag) < $total]);
}

if ($acao === 'convite_get') {
    exigirModuloApi('convidados');
    $c = carregarConvite($conn, (int)($_GET['id']??0));
    $c ? ok(['convite'=>$c]) : erro('Convite não encontrado.');
}

if ($acao === 'convite_save') {
    exigirModuloApi('convidados');
    $d = corpo();
    $id       = (int)($d['id'] ?? 0);
    $nome     = trim($d['nome_exibicao'] ?? '');
    if ($nome === '') erro('O nome do convite é obrigatório.');
    $tipo     = in_array($d['tipo']??'',['digital','fisico','ambos'],true)?$d['tipo']:'digital';
    $lado     = in_array($d['lado']??'',['noivo','noiva','ambos'],true)?$d['lado']:'noivo';
    $telefone = trim($d['telefone'] ?? ''); if ($telefone==='') $telefone=null;
    $obs      = trim($d['observacoes'] ?? ''); if ($obs==='') $obs=null;
    $msgP     = trim($d['msg_pessoal'] ?? ''); if ($msgP==='') $msgP=null;
    $mesaId   = trim($d['mesa']??'')!=='' ? resolverMesa($conn, $d['mesa']) : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) $mesaId = null; // a mesa dos noivos não é atribuível a convites
    $mostrarNM = !empty($d['mostrar_num_mesa']) ? 1 : 0;
    $membros  = is_array($d['membros'] ?? null) ? $d['membros'] : [];

    // Os lugares são as pessoas convidadas: contam-se os nomes em vez de se
    // pedirem à parte. Um campo separado só podia discordar da lista — e a
    // importação já fazia esta conta. Mínimo de 1, para um convite nunca
    // ficar sem lugar nenhum enquanto o nome não é escrito.
    $nomeados = 0;
    foreach ($membros as $m) { if (trim(is_array($m) ? ($m['nome'] ?? '') : $m) !== '') $nomeados++; }
    $lugares = max(1, $nomeados);

    // O escalão de convidados é um tecto de PESSOAS, e este convite reescreve
    // as suas: o que conta é a diferença. Guardar um convite de cinco que já
    // tinha cinco não gasta lugar nenhum, e é isso que faz com que corrigir um
    // nome não bata com o nariz no limite.
    $jaNeste = 0;
    if ($id) {
        $rq = @$conn->query("SELECT COUNT(*) FROM {$P}convidados
                             WHERE " . doCasamento() . " AND convite_id=" . (int)$id);
        if ($rq) $jaNeste = (int)$rq->fetch_row()[0];
    }
    exigirCabidaConvidados($conn, $nomeados - $jaNeste);

    $novoConvite = !$id;
    if ($id) {
        // 'sufixo' fica de fora: já não se pede no formulário, e reescrevê-lo
        // aqui apagaria o que convites antigos ainda tenham guardado.
        $st=$conn->prepare("UPDATE {$P}convites SET nome_exibicao=?,mostrar_num_mesa=?,tipo=?,lado=?,lugares=?,mesa_id=?,telefone=?,observacoes=?,msg_pessoal=?,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('sissiisssi',$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP,$id);
        $st->execute();
    } else {
        $codigo=gerarCodigo($conn);
        $st=$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,mostrar_num_mesa,tipo,lado,lugares,mesa_id,telefone,observacoes,msg_pessoal,criado_em,atualizado_em) VALUES (" . casamentoAtual() . ",?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
        $st->bind_param('ssissiisss',$codigo,$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP);
        $st->execute(); $id=$conn->insert_id;
    }

    // Preserva estados (rsvp/presença/mesa individual) por nome, antes de reconstruir a lista de membros.
    // SELECT * para tolerar colunas que possam ainda não existir na BD (esquema por migrar).
    $anterior=[];
    $r=$conn->query("SELECT * FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=$id");
    if ($r) while($x=$r->fetch_assoc()) $anterior[strtolower(trim($x['nome']))]=$x;

    // Colunas opcionais: só entram no INSERT se existirem (evita 500 por "Unknown column").
    $temPapel  = colunaExiste($conn, "{$P}convidados", 'papel');
    $temGenero = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBrinde = colunaExiste($conn, "{$P}convidados", 'brinde');

    $presenca = in_array($d['presenca'] ?? '', ['pendente','confirmado','parcial','recusado'], true) ? $d['presenca'] : '';

    $conn->query("DELETE FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=$id");
    $primeiro=true; $vaiCount=0; $totMembros=0;
    foreach ($membros as $m) {
        $mn=trim(is_array($m)?($m['nome']??''):$m);
        if ($mn==='') continue;
        $totMembros++;
        $vai = is_array($m) ? !empty($m['vai']) : false;
        $princ=$primeiro?1:0; $primeiro=false;
        $ant=$anterior[strtolower($mn)] ?? null;
        // Estado RSVP de cada pessoa, conforme a presença escolhida no painel
        if     ($presenca==='confirmado') $rsvp='confirmado';
        elseif ($presenca==='recusado')   $rsvp='recusado';
        // Parcial: quem confirma fica 'confirmado'; quem ainda não confirmou fica 'pendente'
        // (aguarda resposta), não 'recusado'. Assim aparece no card/filtro Pendentes.
        elseif ($presenca==='parcial')  { $rsvp = $vai?'confirmado':'pendente'; if($vai)$vaiCount++; }
        elseif ($presenca==='pendente')   $rsvp='pendente';
        else                              $rsvp = $ant['rsvp'] ?? 'pendente'; // sem presença: preserva
        $pres=(int)($ant['presente'] ?? 0);
        // Mesa individual: se o editor a enviou (chave 'mesa_id' presente), usa-a;
        // caso contrário, preserva a que já existia (por nome).
        if (is_array($m) && array_key_exists('mesa_id', $m)) {
            $mesaMembro = ($m['mesa_id']!=='' && $m['mesa_id']!==null) ? (int)$m['mesa_id'] : null;
        } else {
            $mesaMembro = isset($ant['mesa_id']) && $ant['mesa_id']!==null ? (int)$ant['mesa_id'] : null;
        }
        // Papel (padrinho/madrinha): se o editor o enviou, usa-o; senão preserva o anterior (por nome).
        if (is_array($m) && array_key_exists('papel', $m)) {
            $papelMembro = in_array($m['papel'], ['padrinho','madrinha'], true) ? $m['papel'] : null;
        } else {
            $papelMembro = in_array($ant['papel'] ?? '', ['padrinho','madrinha'], true) ? $ant['papel'] : null;
        }
        // Género ('m'/'f') e "Recebe Brinde": enviados pelo editor; senão preserva o anterior (por nome).
        if (is_array($m) && array_key_exists('genero', $m)) {
            $genMembro = in_array($m['genero'], ['m','f'], true) ? $m['genero'] : null;
        } else {
            $genMembro = in_array($ant['genero'] ?? '', ['m','f'], true) ? $ant['genero'] : null;
        }
        if (is_array($m) && array_key_exists('brinde', $m)) {
            $brindeMembro = !empty($m['brinde']) ? 1 : 0;
        } else {
            $brindeMembro = (int)($ant['brinde'] ?? 0) === 1 ? 1 : 0;
        }
        // INSERT construído dinamicamente com as colunas existentes (tolerante a esquema por migrar).
        $cols=['casamento_id','convite_id','nome','principal','rsvp','presente','presente_em','mesa_id'];
        $plc =[(string)casamentoAtual(),'?','?','?','?','?', ($pres?$TS:'NULL'), '?'];
        $typ ='isisii'; $val=[$id,$mn,$princ,$rsvp,$pres,$mesaMembro];
        if ($temPapel)  { $cols[]='papel';  $plc[]='?'; $typ.='s'; $val[]=$papelMembro; }
        if ($temGenero) { $cols[]='genero'; $plc[]='?'; $typ.='s'; $val[]=$genMembro; }
        if ($temBrinde) { $cols[]='brinde'; $plc[]='?'; $typ.='i'; $val[]=$brindeMembro; }
        $q=$conn->prepare("INSERT INTO {$P}convidados (".implode(',',$cols).") VALUES (".implode(',',$plc).")");
        if ($q) { $q->bind_param($typ, ...$val); $q->execute(); }
    }
    recalcularCheckin($conn,$id,$TS); // atualiza contadores de presença; não toca no RSVP

    // Aplica a presença escolhida ao convite
    if ($presenca !== '') {
        if ($presenca === 'confirmado') {
            $conn->query("UPDATE {$P}convites SET rsvp_estado='confirmado', rsvp_confirmados=$lugares, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
        } elseif ($presenca === 'recusado') {
            $conn->query("UPDATE {$P}convites SET rsvp_estado='recusado', rsvp_confirmados=0, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
        } elseif ($presenca === 'parcial') {
            if ($totMembros > 0) {
                // Presença exata: contagem e estado derivados das marcações individuais
                $estado = $vaiCount<=0 ? 'recusado' : ($vaiCount>=$totMembros ? 'confirmado' : 'parcial');
                $conn->query("UPDATE {$P}convites SET rsvp_estado='$estado', rsvp_confirmados=$vaiCount, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
                // Se afinal o convite é totalmente confirmado/recusado, alinha os membros a esse estado
                // (os não confirmados ficaram 'pendente' acima; aqui reconcilia-se o caso terminal).
                if ($estado==='recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE " . doCasamento() . " AND convite_id=$id");
                elseif ($estado==='confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE " . doCasamento() . " AND convite_id=$id");
            } else {
                $conn->query("UPDATE {$P}convites SET rsvp_estado='parcial', rsvp_confirmados=COALESCE(rsvp_confirmados,1), rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
            }
        } else { // pendente
            $conn->query("UPDATE {$P}convites SET rsvp_estado='pendente', rsvp_confirmados=NULL, rsvp_em=NULL WHERE " . doCasamento() . " AND id=$id");
        }
    }

    registar($conn, $novoConvite ? 'convite_criado' : 'convite_editado', $nome, 'id '.$id);
    ok(['convite'=>carregarConvite($conn,$id),'stats'=>estatisticas($conn)]);
}

if ($acao === 'convite_delete') {
    exigirModuloApi('convidados');
    // Eliminação REVERSÍVEL: o convite sai das listas mas fica recuperável.
    // (?definitivo=1 apaga mesmo, usado ao esvaziar a reciclagem.)
    $id  = (int)($_GET['id'] ?? 0);
    $def = !empty($_GET['definitivo']);
    $nome = '';
    $rn = $conn->prepare("SELECT nome_exibicao FROM {$P}convites WHERE " . doCasamento() . " AND id=?");
    $rn->bind_param('i',$id); $rn->execute();
    if ($x = $rn->get_result()->fetch_assoc()) $nome = $x['nome_exibicao'];

    if ($def) {
        $st = $conn->prepare("DELETE FROM {$P}convites WHERE " . doCasamento() . " AND id=?");
    } else {
        $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=$TS WHERE " . doCasamento() . " AND id=?");
    }
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível eliminar.');
    registar($conn, $def ? 'convite_apagado' : 'convite_eliminado', $nome, 'id '.$id);
    ok(['stats'=>estatisticas($conn), 'id'=>$id, 'nome'=>$nome, 'reversivel'=>!$def]);
}

if ($acao === 'convite_restaurar') {
    exigirModuloApi('convidados');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=NULL WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível repor o convite.');
    registar($conn, 'convite_reposto', '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

// ---- Casamentos ---------------------------------------------
// O mínimo para haver mais do que um. Os ecrãs de gestão (lista, aprovação de
// registos, convites de acesso) são da etapa seguinte; aqui fica o que a
// aplicação precisa para saber em qual está e para as provas poderem existir.

if ($acao === 'casamento_criar') {
    // Criar casamentos é do admin da casa. O suporte entra nos que o casal lhe
    // abrir por código, e não abre casas novas.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma cria casamentos.');
    $d = corpo();
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 160);
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    if ($nome === '') $nome = trim($noiva . ' & ' . $noivo);
    if (trim($nome, ' &') === '') erro('Dê um nome ao casamento.');
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data'] ?? '')) ? $d['data'] : null;

    // A licença: quantos meses dura (0 = sem limite) e se arranca já ativa. O
    // relógio só começa a contar quando a licença fica ativa — aqui, se assim se
    // pedir; senão fica definida mas parada, para o admin a iniciar quando quiser.
    $meses = max(0, min(120, (int)($d['licenca_meses'] ?? 0)));
    $licencaAtiva = !empty($d['licenca_ativa']);

    // As contas a criar com o casamento — validam-se os emails ANTES de criar
    // nada, para não deixar um casamento órfão se um email vier torto.
    $noivosEmail   = mb_strtolower(trim((string)($d['noivos_email'] ?? '')));
    $porteiroEmail = mb_strtolower(trim((string)($d['porteiro_email'] ?? '')));
    if ($noivosEmail !== '' && !filter_var($noivosEmail, FILTER_VALIDATE_EMAIL))
        erro('O email da conta dos noivos é inválido.');
    if ($porteiroEmail !== '' && !filter_var($porteiroEmail, FILTER_VALIDATE_EMAIL))
        erro('O email da conta do porteiro é inválido.');
    if ($noivosEmail !== '' && $noivosEmail === $porteiroEmail)
        erro('A conta dos noivos e a do porteiro não podem ter o mesmo email.');
    // Um porteiro sem o módulo «Controlo à porta» é uma conta que entra e não
    // encontra nada. Diz-se AQUI, antes de se criar o que quer que seja, para o
    // casamento não ficar a meio. Sem escalões indicados a licença nasce
    // completa (licConcederTudo, abaixo) e a porta vem lá dentro.
    $escPedidos = [];
    foreach ((array)($d['escaloes'] ?? []) as $e) { $e = (int)$e; if ($e > 0) $escPedidos[] = $e; }
    if ($porteiroEmail !== '' && $escPedidos && !licEscaloesTemModulo($conn, $escPedidos, 'porta'))
        erro('A licença escolhida não inclui o «Controlo à porta»: sem esse módulo '
           . 'não há conta de porteiro para criar. Junte o módulo, ou deixe o email em branco.');

    // Nasce ativo quando é o admin a criá-lo. O registo público entra como
    // 'pendente' e é o admin que o faz passar a ativo (etapa 5).
    $ate = ($meses > 0 && $licencaAtiva)
         ? (new DateTimeImmutable('today'))->modify("+$meses months")->format('Y-m-d') : null;
    $st = $conn->prepare("INSERT INTO {$P}casamentos
                          (nome, noiva, noivo, data_evento, estado, licenca_meses, licenca_ate)
                          VALUES (?,?,?,?, 'ativo', ?, ?)");
    $st->bind_param('ssssis', $nome, $noiva, $noivo, $data, $meses, $ate);
    if (!$st->execute()) erro('Não foi possível criar o casamento.');
    $novo = $conn->insert_id;
    $gravadas = guardarEventoDoRegisto($conn, $novo, $d);
    semearOrcamento($conn, $novo);   // começa com as gavetas de origem
    semearBar($conn, $novo);         // e com as gavetas do bar e os motivos de recusa

    // E a licença: um casamento criado aqui dentro nasce com tudo aberto. Quem
    // o criou já decidiu — não há pedido nenhum a analisar. Se se quiser dar-lhe
    // menos, é em «Módulos da licença…», ou mandando os escalões neste pedido.
    if ($escPedidos) {
        $lista = implode(',', array_map('intval', array_unique($escPedidos)));
        $rr = @$conn->query("SELECT e.id, e.nome, e.limite, e.editar, e.todos_modelos, m.chave modulo
                             FROM {$P}lic_escaloes e JOIN {$P}lic_modulos m ON m.id = e.modulo_id
                             WHERE e.id IN ($lista) ORDER BY m.ordem, e.ordem");
        $vistos = [];
        if ($rr) while ($x = $rr->fetch_assoc()) {
            $mc = (string)$x['modulo'];
            if (isset($vistos[$mc])) continue;
            $vistos[$mc] = true;
            $st2 = $conn->prepare("INSERT INTO {$P}lic_concessoes
                (casamento_id, modulo_chave, escalao_id, escalao_nome, limite, editar, todos_modelos, desde)
                VALUES (?,?,?,?,?,?,?,NOW())");
            if (!$st2) continue;
            $eid = (int)$x['id']; $en = (string)$x['nome']; $lim = (int)$x['limite'];
            $ed = (int)$x['editar']; $tm = (int)$x['todos_modelos'];
            $st2->bind_param('isisiii', $novo, $mc, $eid, $en, $lim, $ed, $tm);
            @$st2->execute();
        }
        @$conn->query("UPDATE {$P}casamentos SET licenca_estado='ativa',
                       licenca_pacote='Concedido pela administração' WHERE id=" . (int)$novo);
    } else {
        licConcederTudo($conn, $novo);
    }

    // E a peça de origem de hoje fica a ser a dele, como no registo público:
    // um casamento aberto pela casa também nasce com um convite à vista.
    fixarPecaOrigemDoCasal($conn, $novo);

    // As contas, se vieram. Guardam-se as senhas geradas para as mostrar uma vez.
    $contas = [];
    if ($noivosEmail !== '') {
        $contas['noivos'] = contaParaCasamento($conn, $noivosEmail,
            mb_substr(trim((string)($d['noivos_nome'] ?? $nome)), 0, 120),
            (string)($d['noivos_senha'] ?? ''), $novo, 'noivos');
    }
    if ($porteiroEmail !== '') {
        $contas['porteiro'] = contaParaCasamento($conn, $porteiroEmail,
            mb_substr(trim((string)($d['porteiro_nome'] ?? ('Porteiro · ' . $nome))), 0, 120),
            (string)($d['porteiro_senha'] ?? ''), $novo, 'porteiro');
    }

    registar($conn, 'casamento_criado', $nome, 'id ' . $novo
        . ($meses ? " · licença $meses mês(es)" . ($licencaAtiva ? ' (ativa)' : ' (por iniciar)') : ''));
    ok(['id' => $novo, 'nome' => $nome, 'dados_do_evento' => $gravadas,
        'licenca' => licencaInfo($conn, $novo), 'contas' => $contas]);
}

if ($acao === 'casamento_abrir') {
    // Passa a ser este o casamento em causa, para os pedidos seguintes.
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT id, nome, estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    if ($c['estado'] === 'arquivado') erro('Esse casamento está arquivado.');
    // Ter o número não chega: é preciso ter lugar lá dentro. Sem isto, bastava
    // escrever outro id no endereço para entrar no casamento de outro casal.
    if (!abrirCasamento($conn, (int)$c['id'])) erro('Não tem acesso a esse casamento.');
    registar($conn, 'casamento_aberto', $c['nome'], 'id ' . (int)$c['id']);
    ok(['id' => (int)$c['id'], 'nome' => $c['nome']]);
}

if ($acao === 'utilizador_editar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita contas.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT email, papel_plataforma FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');

    $email = mb_strtolower(trim((string)($d['email'] ?? $u['email'])));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $plat  = array_key_exists('papel_plataforma', $d)
           ? (in_array($d['papel_plataforma'], ['admin','suporte'], true) ? $d['papel_plataforma'] : null)
           : $u['papel_plataforma'];

    // Não se tira a si próprio o papel que lhe permite estar nesta página: o
    // sistema ficava sem quem responde por ele, e a única saída era a base.
    if ($id === utilizadorId() && $plat !== 'admin') erro('Não pode tirar-se a si próprio da administração.');
    if ($u['papel_plataforma'] === 'admin' && $plat !== 'admin') {
        $r = $conn->query("SELECT COUNT(*) n FROM {$P}utilizadores
                           WHERE papel_plataforma='admin' AND estado='ativo' AND id <> " . $id);
        if ($r && (int)$r->fetch_assoc()['n'] === 0) erro('É o último admin da plataforma.');
    }
    // Uma conta que passa a suporte larga os lugares que tinha: passa a entrar
    // por código, e um lugar próprio seria uma porta paralela.
    if ($plat === 'suporte' && $u['papel_plataforma'] !== 'suporte') {
        $conn->query("DELETE FROM {$P}acessos WHERE utilizador_id=" . $id);
    }

    $st = $conn->prepare("UPDATE {$P}utilizadores SET email=?, nome=?, papel_plataforma=? WHERE id=?");
    $st->bind_param('sssi', $email, $nome, $plat, $id);
    if (!$st->execute()) erro('Já existe uma conta com esse email.');
    registar($conn, 'conta_editada', $email, $plat ? ('plataforma: ' . $plat) : 'sem papel de plataforma');
    ok(['id' => $id, 'email' => $email, 'papel_plataforma' => $plat]);
}

if ($acao === 'utilizador_casamentos') {
    // Os lugares desta conta, para os poder dar e tirar num sítio só.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê os lugares de uma conta.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT a.casamento_id, a.papel, c.nome, c.estado
                          FROM {$P}acessos a JOIN {$P}casamentos c ON c.id = a.casamento_id
                          WHERE a.utilizador_id = ? ORDER BY c.nome");
    $st->bind_param('i', $id); $st->execute();
    ok(['acessos' => $st->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

if ($acao === 'utilizador_apagar') {
    // Só contas ÓRFÃS: as que já não pertencem a casamento nenhum. Uma conta
    // ligada a um casamento apaga-se tirando-lhe primeiro o lugar lá — assim
    // não há como, num clique, deixar um casal sem quem lhe gere a festa.
    // Desativar uma conta em funcionamento é outra coisa, e faz-se à parte.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga contas.');
    $id = (int)($_GET['id'] ?? 0);
    if ($id === utilizadorId()) erro('Não pode apagar a sua própria conta.');
    $st = $conn->prepare("SELECT u.id, u.email, u.papel_plataforma,
                                 (SELECT COUNT(*) FROM {$P}acessos a WHERE a.utilizador_id = u.id) n
                          FROM {$P}utilizadores u WHERE u.id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    if ((int)$u['n'] > 0) erro('Esta conta ainda tem lugar num casamento. Retire-lho primeiro.');
    // O último admin da plataforma não se apaga: ficaria uma casa sem chaves,
    // e a única saída era ir à base de dados por fora.
    if ($u['papel_plataforma'] === 'admin') {
        $r = $conn->query("SELECT COUNT(*) n FROM {$P}utilizadores
                           WHERE papel_plataforma='admin' AND estado='ativo' AND id <> " . (int)$id);
        if ($r && (int)$r->fetch_assoc()['n'] === 0) erro('É o último admin da plataforma.');
    }
    $st = $conn->prepare("DELETE FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar a conta.');
    registar($conn, 'utilizador_apagado', (string)$u['email']);
    ok(['id' => $id]);
}

if ($acao === 'casamento_identidade') {
    // A ficha do casamento: os nomes e a data que definem tudo o resto. Quem
    // gere o casamento aberto pode mudá-la.
    if (!ehAdmin()) erro('Não gere este casamento.');
    $d = corpo();
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    $data  = trim((string)($d['data_evento'] ?? ''));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 160);
    if ($noiva === '' || $noivo === '') erro('Indique os nomes dos noivos.');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro('Data inválida.');
    if ($nome === '') $nome = "$noiva & $noivo";

    $cid = casamentoAtual();
    $st = $conn->prepare("UPDATE {$P}casamentos SET nome=?, noiva=?, noivo=?, data_evento=? WHERE id=?");
    $dataOuNulo = $data !== '' ? $data : null;
    $st->bind_param('ssssi', $nome, $noiva, $noivo, $dataOuNulo, $cid);
    if (!$st->execute()) erro('Não foi possível guardar.');

    // A ficha é o VALOR DE ORIGEM das peças (ver identidadeCasamento). Se o
    // convite tinha estes campos escritos por cima — porque alguém os mudou no
    // editor —, essa cópia continuaria a ganhar, e mudar o nome aqui não mudava
    // nada lá. Tira-se a cópia: quem quiser um nome diferente NO CONVITE volta
    // a escrevê-lo no editor, de propósito.
    $chaves = ["'casal.noiva'", "'casal.noivo'"];
    if ($data !== '') $chaves[] = "'evento.data'";
    $conn->query("DELETE FROM {$P}definicoes WHERE " . doCasamento()
                 . " AND chave IN (" . implode(',', $chaves) . ")");

    registar($conn, 'casamento_ficha', $nome, $data !== '' ? $data : 'sem data');
    ok(['nome' => $nome, 'noiva' => $noiva, 'noivo' => $noivo, 'data_evento' => $data]);
}

if ($acao === 'casamento_endereco') {
    // O endereço por onde os convidados chegam a ESTE casamento. Quem gere o
    // casamento aberto pode fixá-lo — é ele que sai nos QR e nos links.
    $d = corpo();
    $novo = limparEndereco((string)($d['endereco'] ?? ''));
    if ($novo === null) erro('Endereço inválido. Escreva algo como https://casamento.exemplo.pt');
    $st = $conn->prepare("UPDATE {$P}casamentos SET endereco_publico=? WHERE id=?");
    $id = casamentoAtual();
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível guardar o endereço.');
    registar($conn, 'endereco_publico', $novo !== '' ? $novo : '(deduzido do pedido)');
    ok(['endereco' => $novo]);
}

if ($acao === 'utilizador_criar') {
    // Contas criadas pela casa. O registo público entra 'pendente' e espera
    // aprovação; usa a mesma tabela.
    //
    // Três tipos, e a diferença entre eles não é decorativa:
    //   • noivos   — gerem um casamento; ligam-se a ele aqui.
    //   • porteiro — só a porta desse casamento.
    //   • suporte  — NÃO se liga a casamento nenhum. Entra com o código que o
    //     casal gerar, e é esse código que lhe abre a porta e diz o que pode
    //     fazer lá dentro. Prender uma conta de suporte a um casamento seria
    //     dar-lhe pela porta das traseiras o que o casal tem de decidir.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma cria contas.');
    $d = corpo();
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $senha = (string)($d['senha'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    if (mb_strlen($senha) < 8) erro('A senha precisa de pelo menos 8 caracteres.');
    $plat = in_array($d['papel_plataforma'] ?? '', ['admin','suporte'], true) ? $d['papel_plataforma'] : null;
    if ($plat === 'suporte' && (int)($d['casamento_id'] ?? 0) > 0) {
        erro('Uma conta de suporte não se prende a um casamento: entra com o código que o casal gerar.');
    }
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, papel_plataforma, estado)
                          VALUES (?,?,?,?, 'ativo')");
    $st->bind_param('ssss', $email, $nome, $hash, $plat);
    if (!$st->execute()) erro('Já existe uma conta com esse email.');
    $uid = $conn->insert_id;

    // Liga-se logo a um casamento, se vier indicado — é o caso comum: criar a
    // conta dos noivos de um casamento acabado de abrir.
    $cid = (int)($d['casamento_id'] ?? 0);
    $papelCas = in_array($d['papel'] ?? '', ['noivos','porteiro'], true) ? $d['papel'] : 'noivos';
    if ($cid > 0) {
        $st = $conn->prepare("INSERT IGNORE INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)");
        $st->bind_param('iis', $uid, $cid, $papelCas);
        @$st->execute();
    }
    registar($conn, 'conta_criada', $email, $plat ? ('plataforma: '.$plat) : ('casamento '.$cid));
    ok(['id' => $uid, 'email' => $email]);
}

// ---- Quem entra neste casamento -----------------------------
// A gestão dos lugares é de quem gere o casamento aberto: são os noivos que
// convidam o seu porteiro, e não a plataforma que lho impõe. Numa visita de
// suporte com código de leitura, isto não se mexe (exigirCorrecao acima).

/** Quem manda nos lugares deste casamento? */
function mandaNosAcessos(int $cid): bool {
    return ehAdminPlataforma() || ($cid === casamentoAtual() && ehAdmin());
}

if ($acao === 'acesso_lista') {
    exigirAdminApi();
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT a.utilizador_id, a.papel, u.email, u.nome, u.estado, u.ultimo_acesso,
                                 u.papel_plataforma
                          FROM {$P}acessos a JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                          WHERE a.casamento_id = ? ORDER BY a.papel, u.nome, u.email");
    $st->bind_param('i', $cid); $st->execute();
    ok(['acessos' => $st->get_result()->fetch_all(MYSQLI_ASSOC), 'eu' => utilizadorId()]);
}

if ($acao === 'acesso_convidar') {
    // Dá lugar a um porteiro no casamento aberto, pelo email. A conta é sempre
    // NOVA: um email é de uma só conta, e não se reatribui a quem já é de alguém.
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $d = corpo();
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    // Os postos que o casal pode convidar: a porta, e os dois do bar.
    $postos = ['porteiro', 'copeiro', 'entregador'];
    $papelCas = in_array($d['papel'] ?? '', array_merge(['noivos'], $postos), true)
              ? $d['papel'] : 'porteiro';
    // Os noivos convidam POSTOS. Passar a gestão do casamento a outra conta
    // é coisa que se faz com quem responde pela casa presente — senão bastava
    // um convite mal dirigido para o casamento passar a ser de outra pessoa.
    if (!ehAdminPlataforma() && !in_array($papelCas, $postos, true)) $papelCas = 'porteiro';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    // A mesma regra do registo: sem o módulo, o posto não existe — e a conta
    // entrava para não encontrar nada que fazer.
    $exige = ['porteiro' => ['porta', 'o «Controlo à porta»', 'o porteiro'],
              'copeiro'  => ['bar',   'o «Bar da festa»',     'o copeiro'],
              'entregador' => ['bar', 'o «Bar da festa»',     'o garçom']];
    if (isset($exige[$papelCas])) {
        [$mod, $nome, $quem] = $exige[$papelCas];
        if (!licCasamentoTemModulo($conn, $cid, $mod)) {
            erro('A vossa licença não inclui ' . $nome . '. Junte esse módulo à licença '
               . 'e depois convide ' . $quem . '.');
        }
    }

    // Um email já em uso não se realoca: cada email serve uma só conta.
    $st = $conn->prepare("SELECT id FROM {$P}utilizadores WHERE email=? LIMIT 1");
    $st->bind_param('s', $email); $st->execute();
    if ($st->get_result()->fetch_row()) {
        erro('Já existe uma conta com esse email. Cada email serve uma só conta — use outro.');
    }
    // Conta nova: senha temporária, mostrada uma vez a quem convida, para lha
    // entregar. Não há correio configurado — e inventar um envio que não
    // acontece seria pior do que dizer as coisas como são.
    $senhaNova = senhaTemporaria();
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $hash = password_hash($senhaNova, PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                          VALUES (?,?,?, 'ativo')");
    $st->bind_param('sss', $email, $nome, $hash);
    if (!$st->execute()) erro('Não foi possível criar a conta.');
    $uid = $conn->insert_id;
    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE papel=VALUES(papel)");
    $st->bind_param('iis', $uid, $cid, $papelCas);
    if (!$st->execute()) erro('Não foi possível dar o acesso.');
    registar($conn, 'acesso_dado', $email, $papelCas);
    ok(['utilizador' => $uid, 'email' => $email, 'papel' => $papelCas, 'senha' => $senhaNova]);
}

if ($acao === 'acesso_papel') {
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $papelCas = in_array($_GET['papel'] ?? '', ['noivos','porteiro','copeiro','entregador'], true)
              ? $_GET['papel'] : '';
    if ($uid <= 0 || $papelCas === '') erro('Indique a conta e o papel.');
    // Ninguém se despromove a si próprio: o casamento ficaria sem quem o gere.
    if ($uid === utilizadorId() && $papelCas !== 'noivos') erro('Não pode tirar-se a si próprio a gestão.');
    if ($papelCas !== 'noivos' && contaNoivos($conn, $cid, $uid) === 0) {
        erro('Este casamento ficaria sem ninguém a geri-lo.');
    }
    $st = $conn->prepare("UPDATE {$P}acessos SET papel=? WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('sii', $papelCas, $uid, $cid);
    if (!$st->execute()) erro('Não foi possível mudar o papel.');
    registar($conn, 'acesso_papel', 'conta '.$uid, $papelCas);
    ok(['utilizador' => $uid, 'papel' => $papelCas]);
}

if ($acao === 'acesso_tirar') {
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    if ($uid <= 0) erro('Indique a conta.');
    if ($uid === utilizadorId()) erro('Não pode tirar-se a si próprio deste casamento.');
    if (contaNoivos($conn, $cid, $uid) === 0) erro('Este casamento ficaria sem ninguém a geri-lo.');
    $st = $conn->prepare("DELETE FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('ii', $uid, $cid);
    if (!$st->execute()) erro('Não foi possível tirar o acesso.');
    registar($conn, 'acesso_tirado', 'conta '.$uid, 'casamento '.$cid);
    ok(['utilizador' => $uid]);
}

if ($acao === 'acesso_dar') {
    // Dá (ou muda) o lugar de alguém num casamento, indicando qual. É a versão
    // da plataforma; o casal usa 'acesso_convidar', no casamento que tem aberto.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma dá acessos.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $cid = (int)($_GET['casamento'] ?? 0);
    $papelCas = in_array($_GET['papel'] ?? '', ['noivos','porteiro'], true) ? $_GET['papel'] : 'noivos';
    if ($uid <= 0 || $cid <= 0) erro('Indique a conta e o casamento.');
    $q = $conn->prepare("SELECT papel_plataforma FROM {$P}utilizadores WHERE id=?");
    $q->bind_param('i', $uid); $q->execute();
    $alvo = $q->get_result()->fetch_assoc();
    if (!$alvo) erro('Conta não encontrada.');
    if (($alvo['papel_plataforma'] ?? '') === 'suporte') {
        erro('Uma conta de suporte não se prende a um casamento: entra com o código que o casal gerar.');
    }
    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE papel=VALUES(papel)");
    $st->bind_param('iis', $uid, $cid, $papelCas);
    if (!$st->execute()) erro('Não foi possível dar o acesso.');
    registar($conn, 'acesso_dado', 'conta '.$uid, 'casamento '.$cid.' · '.$papelCas);
    ok(['utilizador' => $uid, 'casamento' => $cid, 'papel' => $papelCas]);
}

// ---- Códigos de suporte -------------------------------------
// A porta que o casal abre ao suporte, e fecha quando quiser. Um código diz
// a que casamento dá acesso, se deixa só ver ou também corrigir, e até quando.

if ($acao === 'suporte_codigo_lista') {
    exigirAdminApi();
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT s.id, s.codigo, s.pode_corrigir, s.criado_em, s.expira_em,
                                 s.usado_em, s.revogado_em, u.email AS usado_por_email
                          FROM {$P}suporte_codigos s
                          LEFT JOIN {$P}utilizadores u ON u.id = s.usado_por
                          WHERE s.casamento_id=? ORDER BY s.id DESC LIMIT 40");
    $st->bind_param('i', $cid); $st->execute();
    $lista = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $agora = time();
    foreach ($lista as &$l) {
        $l['estado'] = $l['revogado_em'] !== null ? 'revogado'
                     : (($l['expira_em'] !== null && strtotime($l['expira_em']) < $agora) ? 'expirado' : 'valido');
    }
    unset($l);
    ok(['codigos' => $lista]);
}

if ($acao === 'suporte_codigo_criar') {
    exigirAdminApi();
    if (emVisitaDeSuporte()) erro('Uma visita de suporte não gera códigos de acesso.');
    $d = corpo();
    $corrigir = !empty($d['pode_corrigir']) ? 1 : 0;
    // Prazo curto por omissão: um código sem fim é uma porta que fica aberta.
    $dias = (int)($d['dias'] ?? 7);
    $dias = max(1, min($dias, 90));
    $cid  = casamentoAtual();
    $uid  = utilizadorId();

    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $cod = '';
        for ($i = 0; $i < 8; $i++) $cod .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $q = $conn->prepare("SELECT id FROM {$P}suporte_codigos WHERE codigo=? LIMIT 1");
        $q->bind_param('s', $cod); $q->execute();
        $existe = $q->get_result()->fetch_assoc();
    } while ($existe);

    $st = $conn->prepare("INSERT INTO {$P}suporte_codigos
                            (casamento_id, codigo, pode_corrigir, criado_por, expira_em)
                          VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    $st->bind_param('isiii', $cid, $cod, $corrigir, $uid, $dias);
    if (!$st->execute()) erro('Não foi possível gerar o código.');
    registar($conn, 'suporte_codigo', $cod, ($corrigir ? 'pode corrigir' : 'só ver') . " · $dias dia(s)");
    ok(['codigo' => $cod, 'pode_corrigir' => $corrigir, 'dias' => $dias]);
}

if ($acao === 'suporte_codigo_revogar') {
    exigirAdminApi();
    $id  = (int)($_GET['id'] ?? 0);
    $cid = casamentoAtual();
    $st = $conn->prepare("UPDATE {$P}suporte_codigos SET revogado_em=NOW()
                          WHERE id=? AND casamento_id=? AND revogado_em IS NULL");
    $st->bind_param('ii', $id, $cid);
    if (!$st->execute() || $conn->affected_rows === 0) erro('Esse código já não está de pé.');
    registar($conn, 'suporte_codigo_revogado', 'código ' . $id);
    ok(['id' => $id]);
}

if ($acao === 'suporte_sair' || $acao === 'casamento_fechar') {
    // Sair do casamento em que se está a trabalhar, sem terminar a sessão.
    // Vale para a visita de suporte (que assim não espera que o código expire)
    // e para quem responde pela casa, que entra e sai de casamentos alheios o
    // dia todo — e cuja única saída era abrir outro ou ir-se embora.
    $nome = '';
    $r = @$conn->query("SELECT nome FROM {$P}casamentos WHERE id=" . casamentoAtual());
    if ($r && ($x = $r->fetch_assoc())) $nome = (string)$x['nome'];
    if ($nome !== '') registar($conn, 'casamento_fechado', $nome);
    fecharCasamento();
    ok(['nome' => $nome]);
}

// ---- Contas, vistas pela plataforma -------------------------
if ($acao === 'casamento_lista') {
    // A lista da administração, servida como a das contas: procurável e por
    // ordem de USO. O número é a ordem por que foram criados, que é a menos
    // útil de todas — quem abre a página quer ver em cima aquilo em que andou.
    if (!ehPessoalPlataforma()) erro('Só o pessoal da plataforma vê os casamentos.');
    $q  = trim((string)($_GET['q'] ?? ''));
    $est = (string)($_GET['estado'] ?? 'ativo');
    if (!in_array($est, ['ativo','pendente','suspenso','arquivado','todos'], true)) $est = 'ativo';

    $onde = $est === 'todos' ? '1=1' : "c.estado = '" . $conn->real_escape_string($est) . "'";
    $liga = '';
    if ($q !== '') {
        $s = $conn->real_escape_string($q);
        $onde .= " AND (c.nome LIKE '%$s%' OR c.noiva LIKE '%$s%' OR c.noivo LIKE '%$s%')";
    }
    $r = @$conn->query("SELECT c.id, c.nome, c.noiva, c.noivo, c.estado, c.data_evento, c.ultimo_acesso,
                               c.licenca_meses, c.licenca_ate, c.licenca_estado, c.licenca_pacote,
                               DATEDIFF(c.licenca_ate, CURDATE()) licenca_dias,
                               -- Quantos módulos a licença abre, e se há pedido
                               -- à espera: a lista tem de dizer, num relance,
                               -- qual é o casamento que está à porta.
                               (SELECT COUNT(*) FROM {$P}lic_concessoes lc
                                 WHERE lc.casamento_id = c.id) lic_modulos,
                               (SELECT COUNT(*) FROM {$P}lic_pedidos lp
                                 WHERE lp.casamento_id = c.id AND lp.estado='pendente') lic_pedidos,
                               (SELECT COUNT(*) FROM {$P}convites v
                                 WHERE v.casamento_id = c.id AND v.eliminado_em IS NULL) convites,
                               (SELECT COUNT(*) FROM {$P}convidados g
                                 WHERE g.casamento_id = c.id) pessoas,
                               -- Quantos já disseram que vêm. Um casamento com
                               -- 200 convites e 3 confirmações é uma notícia
                               -- diferente de um com 200 e 180; a lista dizia
                               -- os dois da mesma maneira.
                               (SELECT COUNT(*) FROM {$P}convidados g2
                                 WHERE g2.casamento_id = c.id AND g2.rsvp = 'confirmado') confirmados,
                               (SELECT COUNT(*) FROM {$P}acessos a
                                 WHERE a.casamento_id = c.id AND a.papel='noivos') donos
                        FROM {$P}casamentos c
                        WHERE $onde
                        ORDER BY c.ultimo_acesso IS NULL, c.ultimo_acesso DESC, c.id DESC
                        LIMIT 200");
    $lista = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];

    // Quem não é admin da casa só vê aqueles onde tem lugar.
    if (!ehAdminPlataforma()) {
        $meus = casamentosDoUtilizador($conn);
        $lista = array_values(array_filter($lista, fn($c) => isset($meus[(int)$c['id']])));
    }
    ok(['casamentos' => $lista, 'aberto' => casamentoAtual(), 'estado' => $est]);
}

if ($acao === 'utilizador_lista') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê as contas.');
    $q = trim((string)($_GET['q'] ?? ''));
    // 'tipo=plataforma' devolve só as contas administrativas (admin e suporte).
    // As de noivos/porteiro vivem nos dados do próprio casamento (Gestão), e não
    // se misturam com estas — ver a aba «Contas administrativas» da plataforma.
    $tipo = (string)($_GET['tipo'] ?? '');
    $filtroTipo = $tipo === 'plataforma' ? " u.papel_plataforma IS NOT NULL"
               : ($tipo === 'casamento'  ? " u.papel_plataforma IS NULL" : '');
    $onde = [];
    if ($filtroTipo !== '') $onde[] = $filtroTipo;
    if ($q !== '')          $onde[] = " (u.email LIKE ? OR u.nome LIKE ?)";
    $sql = "SELECT u.id, u.email, u.nome, u.papel_plataforma, u.estado, u.criado_em, u.ultimo_acesso,
                   (SELECT COUNT(*) FROM {$P}acessos a WHERE a.utilizador_id = u.id) casamentos
            FROM {$P}utilizadores u"
         . ($onde ? " WHERE" . implode(' AND', $onde) : '')
         . " ORDER BY u.estado='pendente' DESC, u.id DESC LIMIT 100";
    $st = $conn->prepare($sql);
    if ($q !== '') { $like = "%$q%"; $st->bind_param('ss', $like, $like); }
    $st->execute();
    ok(['contas' => $st->get_result()->fetch_all(MYSQLI_ASSOC), 'eu' => utilizadorId()]);
}

if ($acao === 'utilizador_estado') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda o estado das contas.');
    $id = (int)($_GET['id'] ?? 0);
    $novo = (string)($_GET['estado'] ?? '');
    if (!in_array($novo, ['pendente','ativo','suspenso','inativo'], true)) erro('Estado inválido.');
    if ($id === utilizadorId()) erro('Não pode mudar o estado da sua própria conta.');
    $st = $conn->prepare("SELECT email FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    $st = $conn->prepare("UPDATE {$P}utilizadores SET estado=? WHERE id=?");
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível mudar o estado.');
    registar($conn, 'conta_estado', (string)$u['email'], $novo);
    ok(['id' => $id, 'estado' => $novo]);
}

if ($acao === 'utilizador_repor_senha') {
    // Não há correio configurado, e um envio que não acontece era pior do que
    // não o prometer: gera-se uma senha temporária, mostra-se UMA vez a quem
    // a há de entregar, e quem a receber muda-a na sua conta.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma repõe senhas.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT email FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    $nova = senhaTemporaria();
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    $st = $conn->prepare("UPDATE {$P}utilizadores SET senha_hash=? WHERE id=?");
    $st->bind_param('si', $hash, $id);
    if (!$st->execute()) erro('Não foi possível repor a senha.');
    registar($conn, 'senha_reposta', (string)$u['email']);
    ok(['id' => $id, 'email' => $u['email'], 'senha' => $nova]);
}

if ($acao === 'acesso_tirar_de') {
    // Tira o lugar de uma conta num casamento indicado. O 'acesso_tirar' é do
    // casal, e trabalha sempre no casamento aberto; este é da plataforma, que
    // arruma contas sem ter de abrir a casa de cada uma.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma arruma lugares por aqui.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $cid = (int)($_GET['casamento'] ?? 0);
    if ($uid <= 0 || $cid <= 0) erro('Indique a conta e o casamento.');
    $st = $conn->prepare("DELETE FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('ii', $uid, $cid);
    if (!$st->execute()) erro('Não foi possível tirar o lugar.');
    registar($conn, 'acesso_tirado', 'conta ' . $uid, 'casamento ' . $cid);
    ok(['utilizador' => $uid, 'casamento' => $cid]);
}

if ($acao === 'conta_apagar_do_casamento') {
    // «Tirar conta» — elimina mesmo a conta ligada a este casamento, e não só o
    // seu lugar. Com um email por conta e por função, a conta de um porteiro (ou
    // de um casal) existe por causa deste casamento: tirá-la é apagá-la. Serve
    // aos noivos (no casamento aberto) e ao admin (a partir da lista, indicando
    // o casamento). Por segurança, se a conta ainda tiver lugar noutro casamento,
    // só se lhe tira o lugar aqui — não se leva o que também é de outra festa.
    $cid = (int)($_GET['casamento'] ?? 0);
    if ($cid <= 0) $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    if ($uid <= 0) erro('Indique a conta.');
    if ($uid === utilizadorId()) erro('Não pode eliminar a sua própria conta.');
    // O papel desta conta NESTE casamento — para só travar a saída de quem o
    // gere. Tirar um porteiro nunca deixa o casamento sem dono; tirar o último
    // casal, sim, e isso não se faz sem passar a gestão a outra conta antes.
    $st = $conn->prepare("SELECT papel FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=? LIMIT 1");
    $st->bind_param('ii', $uid, $cid); $st->execute();
    $ac = $st->get_result()->fetch_assoc();
    if (!$ac) erro('Essa conta não tem lugar neste casamento.');
    if ($ac['papel'] === 'noivos' && contaNoivos($conn, $cid, $uid) === 0) {
        erro('Este casamento ficaria sem ninguém a geri-lo. Dê a gestão a outra conta primeiro.');
    }
    $st = $conn->prepare("SELECT email, papel_plataforma FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $uid); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    if ($u['papel_plataforma'] !== null) erro('As contas da plataforma não se eliminam por aqui.');

    // Tira o lugar neste casamento.
    $st = $conn->prepare("DELETE FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('ii', $uid, $cid);
    if (!$st->execute()) erro('Não foi possível tirar o acesso.');
    // Se a conta já não serve casamento nenhum, elimina-se de vez.
    $r = $conn->query("SELECT COUNT(*) n FROM {$P}acessos WHERE utilizador_id=" . $uid);
    $restam = $r ? (int)$r->fetch_assoc()['n'] : 0;
    $apagada = false;
    if ($restam === 0) {
        $st = $conn->prepare("DELETE FROM {$P}utilizadores WHERE id=?");
        $st->bind_param('i', $uid); @$st->execute();
        $apagada = true;
    }
    registar($conn, 'conta_apagada', (string)$u['email'],
             $apagada ? 'eliminada' : 'tirada do casamento ' . $cid . ' (tem outros)');
    ok(['utilizador' => $uid, 'apagada' => $apagada]);
}

if ($acao === 'casamento_estado') {
    // Suspender, arquivar, reabrir. Já NÃO serve para aprovar um registo novo.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda o estado de um casamento.');
    $id = (int)($_GET['id'] ?? 0);
    $novo = (string)($_GET['estado'] ?? '');
    if (!in_array($novo, ['pendente','ativo','suspenso','arquivado'], true)) erro('Estado inválido.');
    $st = $conn->prepare("SELECT nome, estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');

    // Um registo que nunca foi aprovado abre-se pelo PEDIDO DE LICENÇA, e não
    // por aqui. São a mesma decisão — que plano é que este casal leva — e ter
    // dois caminhos para ela deixava-os desalinhados: um casamento ativo sem
    // licença nenhuma é um casal que entra e não pode fazer nada.
    //
    // Reabrir um casamento suspenso ou arquivado continua a passar por aqui:
    // esse já foi aprovado uma vez, e o que se decide é outra coisa.
    if ($novo === 'ativo' && (string)$c['estado'] === 'pendente') {
        erro('Um registo novo abre-se aprovando o seu pedido de licença, em Licenças. '
           . 'É lá que se decide o que este casal leva — e aprovar o pedido activa '
           . 'o casamento e as suas contas no mesmo gesto.');
    }

    // E fechar a casa a quem tem licença EM VIGOR também não se faz por aqui.
    //
    // Suspender ou arquivar tira ao casal o que ele comprou, sem o dizer: a
    // página da licença continuaria a mostrar-lha activa enquanto ele não
    // conseguia entrar. A licença decide-se primeiro — revogar deixa motivo
    // escrito, que o casal lê — e só depois se fecha a porta. Uma licença
    // expirada não trava nada, porque já não está a dar nada.
    if (in_array($novo, ['suspenso','arquivado'], true)) {
        $lic  = licencaEstado($conn, $id);
        $info = licencaInfo($conn, $id);
        if ($lic === 'ativa' && empty($info['expirada'])) {
            erro('Este casamento tem licença em vigor. Suspendê-lo ou arquivá-lo agora '
               . 'tirava ao casal o que ele pagou sem lhe dizer porquê — a licença '
               . 'continuaria a dizer-lhe que está activa. Revogue a licença primeiro '
               . '(o motivo fica escrito, e o casal lê-o), ou espere que expire.');
        }
    }
    $st = $conn->prepare("UPDATE {$P}casamentos SET estado=? WHERE id=?");
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível mudar o estado.');

    // O estado do casamento arrasta o das contas que só existem por causa dele.
    //
    // Aprovar é abrir a porta às duas coisas ao mesmo tempo: o casamento passa
    // a ativo E a conta de quem se inscreveu deixa de estar à espera. Aprovar
    // só o casamento deixava o casal de fora, sem perceber porquê — e o admin
    // convencido de que já tinha tratado do assunto.
    //
    // Arquivar é o inverso: as contas do casal e do porteiro daquele casamento
    // ficam paradas, porque já não há lá nada para fazer. Só as que não têm
    // outro casamento de pé — quem é porteiro em dois não pode ficar fechado
    // por causa de um — e nunca o pessoal da casa, que não depende de
    // casamento nenhum para existir.
    $contas = 0;
    if ($novo === 'ativo') {
        // Ativar arranca (ou reinicia, se tinha expirado) o relógio da licença,
        // e devolve à vida as contas que tinham parado com o casamento.
        iniciarLicenca($conn, $id);
        $contas = retomarContasDoCasamento($conn, $id);
    } elseif ($novo === 'arquivado' || $novo === 'suspenso') {
        // Arquivar e suspender fecham a porta às contas que só dele dependem.
        $contas = pararContasDoCasamento($conn, $id);
    }
    // Arquivar ou suspender o casamento aberto tem de o fechar: senão a sessão
    // continuava a trabalhar dentro de uma casa que já saiu das listas de pé.
    if (($novo === 'arquivado' || $novo === 'suspenso') && (int)($_SESSION['casamento_id'] ?? 0) === $id) {
        $_SESSION['casamento_id'] = 0;
        $_SESSION['papel'] = null;
    }
    $parou = ($novo === 'arquivado' || $novo === 'suspenso');
    $rotulo = $parou ? 'conta(s) parada(s)' : 'conta(s) ativada(s)';
    registar($conn, 'casamento_estado', $c['nome'], $novo . ($contas ? " · $contas $rotulo" : ''));
    ok(['id' => $id, 'estado' => $novo,
        'contas_ativadas' => $parou ? 0 : $contas,
        'contas_paradas'  => $parou ? $contas : 0]);
}

if ($acao === 'casamento_licenca') {
    // Define ou ajusta a licença de um casamento: o período (meses) e, se se
    // pedir, arranca já o relógio. Serve para estender uma licença a acabar, ou
    // para iniciar uma que ficou por começar. Reiniciar dá período novo a contar
    // de hoje. É do admin da plataforma.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma gere licenças.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    $meses = max(0, min(120, (int)($d['licenca_meses'] ?? 0)));
    // 'iniciar' arranca o relógio de hoje; 'reiniciar' fá-lo mesmo que já esteja
    // a correr (estender). Sem nenhum, só se grava o período (fica por iniciar).
    $iniciar   = !empty($d['iniciar']);
    $reiniciar = !empty($d['reiniciar']);
    if ($meses <= 0) {
        // Sem limite: apaga qualquer expiração.
        $conn->query("UPDATE {$P}casamentos SET licenca_meses=0, licenca_ate=NULL WHERE id=$id");
    } elseif ($reiniciar) {
        $conn->query("UPDATE {$P}casamentos SET licenca_meses=$meses,
                      licenca_ate=DATE_ADD(CURDATE(), INTERVAL $meses MONTH) WHERE id=$id");
    } else {
        $conn->query("UPDATE {$P}casamentos SET licenca_meses=$meses WHERE id=$id");
        if ($iniciar) iniciarLicenca($conn, $id);   // só arranca se ainda não corria
    }
    registar($conn, 'casamento_licenca', (string)$c['nome'],
             $meses ? "$meses mês(es)" . ($iniciar || $reiniciar ? ' (a contar)' : '') : 'sem limite');
    ok(['id' => $id, 'licenca' => licencaInfo($conn, $id)]);
}

if ($acao === 'casamento_ficha') {
    // A ficha COMPLETA de um casamento, para o admin a editar da lista sem ter
    // de o abrir: a identidade, os dados do evento e as contas ligadas a ele.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê a ficha completa.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT id, nome, noiva, noivo, data_evento, estado,
                                 licenca_meses, licenca_estado, licenca_pacote
                          FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');

    // Os dados do evento vivem nas definições do casamento. Lêem-se as chaves
    // que o formulário de novo casamento também escreve — mais o teto do orçamento.
    $chaves = ['evento.hora','evento.venue_titulo','evento.local','evento.cidade','evento.convidados',
               'evento.whatsapp','evento.maps','evento.civil_hora','evento.civil_local','evento.civil_maps',
               'evento.religiosa_hora','evento.religiosa_local','evento.religiosa_maps','orcamento.total'];
    $evento = [];
    $q = $conn->prepare("SELECT valor FROM {$P}definicoes WHERE casamento_id=? AND chave=?");
    foreach ($chaves as $ch) {
        $q->bind_param('is', $id, $ch); $q->execute();
        $row = $q->get_result()->fetch_row();
        $evento[$ch] = $row ? (string)$row[0] : '';
    }

    // As contas de noivos e porteiro deste casamento.
    $st = $conn->prepare("SELECT a.utilizador_id, a.papel, u.email, u.nome, u.estado, u.ultimo_acesso
                          FROM {$P}acessos a JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                          WHERE a.casamento_id = ? AND a.papel IN ('noivos','porteiro')
                          ORDER BY a.papel, u.nome, u.email");
    $st->bind_param('i', $id); $st->execute();
    $contas = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    ok(['casamento' => $c, 'evento' => $evento, 'contas' => $contas,
        'licenca' => licencaInfo($conn, $id),
        // Os módulos concedidos, para o painel poder marcar o que já lá está
        // em vez de obrigar o admin a adivinhar de memória.
        'licenca_modulos' => licencaModulos($conn, $id),
        'licenca_pedido'  => licPedido($conn, $id, 'pendente')]);
}

if ($acao === 'casamento_editar') {
    // Editar TODOS os dados de um casamento a partir da lista (identidade +
    // evento + orçamento) e, se se pedir, criar/ligar as contas de noivos e
    // porteiro. É do admin da plataforma — a versão da lista do 'casamento_identidade',
    // que só trabalha no casamento aberto.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita casamentos.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT id, nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');

    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    $data  = trim((string)($d['data'] ?? ''));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 160);
    if ($noiva === '' || $noivo === '') erro('Indique os nomes dos noivos.');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro('Data inválida.');
    if ($nome === '') $nome = "$noiva & $noivo";

    // Valida os emails das contas ANTES de mexer em nada, para não deixar o
    // casamento a meio se um vier torto.
    $noivosEmail   = mb_strtolower(trim((string)($d['noivos_email'] ?? '')));
    $porteiroEmail = mb_strtolower(trim((string)($d['porteiro_email'] ?? '')));
    if ($noivosEmail !== '' && !filter_var($noivosEmail, FILTER_VALIDATE_EMAIL))
        erro('O email da conta dos noivos é inválido.');
    if ($porteiroEmail !== '' && !filter_var($porteiroEmail, FILTER_VALIDATE_EMAIL))
        erro('O email da conta do porteiro é inválido.');
    if ($noivosEmail !== '' && $noivosEmail === $porteiroEmail)
        erro('A conta dos noivos e a do porteiro não podem ter o mesmo email.');
    // A mesma regra da criação: o porteiro só existe onde há porta para guardar.
    if ($porteiroEmail !== '' && !licCasamentoTemModulo($conn, $id, 'porta'))
        erro('A licença deste casamento não inclui o «Controlo à porta»: sem esse módulo '
           . 'não há conta de porteiro para criar. Junte o módulo à licença primeiro.');

    $st = $conn->prepare("UPDATE {$P}casamentos SET nome=?, noiva=?, noivo=?, data_evento=? WHERE id=?");
    $dataOuNulo = $data !== '' ? $data : null;
    $st->bind_param('ssssi', $nome, $noiva, $noivo, $dataOuNulo, $id);
    if (!$st->execute()) erro('Não foi possível guardar a ficha.');

    // Como no 'casamento_identidade': a ficha é o valor de origem das peças, por
    // isso apaga-se qualquer cópia escrita por cima no editor, senão mudar o
    // nome aqui não mudava nada no convite.
    usarCasamento($id);
    $chaves = ["'casal.noiva'", "'casal.noivo'"];
    if ($data !== '') $chaves[] = "'evento.data'";
    $conn->query("DELETE FROM {$P}definicoes WHERE " . doCasamento()
                 . " AND chave IN (" . implode(',', $chaves) . ")");

    // Os dados do evento e o teto do orçamento, pelo mesmo ajudante do registo.
    $gravadas = guardarEventoDoRegisto($conn, $id, $d);

    // As contas: cria (ou liga, se o email já tiver conta) as que vierem no
    // pedido. O contaParaCasamento faz o INSERT IGNORE do lugar, por isso é
    // seguro mesmo que a conta já exista.
    $contas = [];
    if ($noivosEmail !== '') {
        $contas['noivos'] = contaParaCasamento($conn, $noivosEmail,
            mb_substr(trim((string)($d['noivos_nome'] ?? $nome)), 0, 120),
            (string)($d['noivos_senha'] ?? ''), $id, 'noivos');
    }
    if ($porteiroEmail !== '') {
        $contas['porteiro'] = contaParaCasamento($conn, $porteiroEmail,
            mb_substr(trim((string)($d['porteiro_nome'] ?? ('Porteiro · ' . $nome))), 0, 120),
            (string)($d['porteiro_senha'] ?? ''), $id, 'porteiro');
    }

    registar($conn, 'casamento_ficha', $nome, 'edição completa (id ' . $id . ')');
    ok(['id' => $id, 'nome' => $nome, 'noiva' => $noiva, 'noivo' => $noivo,
        'data_evento' => $data, 'dados_do_evento' => $gravadas, 'contas' => $contas]);
}

/**
 * Apaga as contas que só existem por causa deste casamento — as de casamento
 * (noivos/porteiro) sem lugar em mais nenhum. Nunca o pessoal da plataforma
 * (admin/suporte), e nunca quem ainda é porteiro ou casal noutra festa. Corre
 * ANTES de se apagarem os acessos, porque é por eles que se sabe de quem é cada
 * conta. Devolve quantas contas foram eliminadas.
 */
function apagarContasDoCasamento(mysqli $conn, int $cid): int {
    global $P;
    $ids = [];
    $r = @$conn->query("SELECT DISTINCT a.utilizador_id
                        FROM {$P}acessos a JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                        WHERE a.casamento_id=$cid AND u.papel_plataforma IS NULL");
    if ($r) while ($x = $r->fetch_row()) $ids[] = (int)$x[0];
    $n = 0;
    foreach ($ids as $uid) {
        $q = @$conn->query("SELECT COUNT(*) FROM {$P}acessos WHERE utilizador_id=$uid AND casamento_id <> $cid");
        if ($q && (int)$q->fetch_row()[0] > 0) continue;   // ainda serve outra festa
        $conn->query("DELETE FROM {$P}acessos WHERE utilizador_id=$uid");
        $conn->query("DELETE FROM {$P}utilizadores WHERE id=$uid");
        $n++;
    }
    return $n;
}

if ($acao === 'casamento_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga casamentos.');
    // Apaga um casamento e TUDO o que é dele. Não se desfaz, e por isso pede-se
    // um passo antes: só se apaga o que já está arquivado.
    //
    // A trava antiga era o número — "o nº1 não se apaga" —, o que protegia um
    // casamento por acaso de ter sido o primeiro e deixava todos os outros à
    // mão de um clique. Arquivar primeiro protege-os a todos, e pela razão
    // certa: um casamento arquivado já saiu das listas de trabalho, já ninguém
    // o está a usar, e apagá-lo é uma segunda decisão e não a mesma.
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    if ($c['estado'] !== 'arquivado') {
        erro('Só se apaga um casamento arquivado. Arquive-o primeiro — e leve os dados, '
           . 'se ainda os quiser.');
    }
    // O que se vai levar, para o dizer depois: apagar em silêncio um casamento
    // com 200 convidados lá dentro não é resposta para quem carregou no botão.
    $levou = [];
    foreach (['convites' => 'convites', 'convidados' => 'pessoas', 'mesas' => 'mesas'] as $tab => $rot) {
        $r = @$conn->query("SELECT COUNT(*) n FROM {$P}$tab WHERE casamento_id=" . $id);
        $levou[$rot] = $r ? (int)$r->fetch_assoc()['n'] : 0;
    }
    // As fotos que o casal anexou vivem no disco; limpa-se a peça à origem antes
    // de apagar as linhas, para não as deixar órfãs no servidor.
    reporFabricaPartes($conn, $id, array_keys(partesCasamento()));
    // As contas de casamento (noivos/porteiro) só existem por causa dele: apagar
    // o casamento apaga-as também. Corre antes dos acessos, que é por eles que se
    // sabe de quem são.
    $levou['contas'] = apagarContasDoCasamento($conn, $id);
    // Pela ordem certa: os convidados dependem dos convites, e as parcelas das
    // despesas, e estas das categorias.
    //
    // As dez tabelas do bar entram nesta lista, e primeiro: apagar um
    // casamento deixava-as para trás, com pedidos e telemóveis a apontar para
    // convidados que já não existiam. Órfãos numa base que ninguém volta a
    // olhar são a pior espécie — não dão erro nenhum, só ocupam e confundem
    // quem um dia for contar linhas. Os alertas saem antes das regras, que é
    // para onde o `regra_id` deles aponta.
    foreach (['bar_pedido_itens','bar_pedidos','bar_stock_mov','bar_alertas','bar_limites',
              'bar_motivos','bar_mensagens','bar_dispositivos','bar_itens','bar_categorias',
              'convidados','convites','mesas','versoes','registo','definicoes',
              'acessos','suporte_codigos',
              'orcamento_pagamentos','orcamento_despesas','orcamento_categorias'] as $t) {
        $st = $conn->prepare("DELETE FROM {$P}$t WHERE casamento_id=?");
        $st->bind_param('i', $id); @$st->execute();
    }
    $st = $conn->prepare("DELETE FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    if ((int)($_SESSION['casamento_id'] ?? 0) === $id) {
        $_SESSION['casamento_id'] = 0;
        $_SESSION['papel'] = null;
    }
    registar($conn, 'casamento_apagado', $c['nome'],
             $levou['convites'] . ' convites · ' . $levou['pessoas'] . ' pessoas · '
             . $levou['contas'] . ' contas');
    ok(['id' => $id, 'nome' => $c['nome'], 'levou' => $levou]);
}

if ($acao === 'esquema_info') {
    // Retrato do esqueleto de dados. Serve para uma prova poder afirmar que a
    // migração para vários casamentos ficou bem feita — sobretudo que nenhum
    // dado ficou sem dono, que é o que separa isto de uma fuga entre casais.
    $um = function (string $sql) use ($conn) {
        $r = @$conn->query($sql); return $r ? (int)$r->fetch_row()[0] : -1;
    };
    // O registo fica de fora desta conta, e de propósito: uma ação da PLATAFORMA
    // (criar um casamento, aprovar um registo, mexer numa conta) não pertence a
    // casamento nenhum, e o seu rasto vive no 0 — o mesmo sítio reservado onde
    // está a versão do esquema. Contá-lo como órfão era chamar defeito ao que
    // está certo, e habituar a prova a ver vermelho.
    $orfaos = 0;
    foreach (['convites','convidados','mesas','versoes'] as $t) {
        $orfaos += max(0, $um("SELECT COUNT(*) FROM {$P}$t WHERE casamento_id IS NULL OR casamento_id < 1"));
    }
    $registoSistema = max(0, $um("SELECT COUNT(*) FROM {$P}registo WHERE casamento_id = 0"));
    $registoOrfao   = max(0, $um("SELECT COUNT(*) FROM {$P}registo WHERE casamento_id IS NULL OR casamento_id < 0"));
    $orfaos += $registoOrfao;
    // As definições do sistema (versão do esquema) vivem no casamento 0.
    $sistemaFora = $um("SELECT COUNT(*) FROM {$P}definicoes WHERE chave='schema.versao' AND casamento_id=0") === 1;
    // O nome da mesa tem de ser único por casamento, não em toda a tabela.
    $mesaOk = false;
    $rk = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='uq_mesa_nome'");
    if ($rk) {
        $cols = [];
        while ($x = $rk->fetch_assoc()) $cols[] = $x['Column_name'];
        $mesaOk = in_array('casamento_id', $cols, true) && in_array('nome', $cols, true);
    }
    ok(['esquema' => [
        'versao'      => ESQUEMA_VERSAO,
        'casamentos'  => $um("SELECT COUNT(*) FROM {$P}casamentos"),
        'contas'      => $um("SELECT COUNT(*) FROM {$P}utilizadores"),
        'acessos'     => $um("SELECT COUNT(*) FROM {$P}acessos"),
        'orfaos'      => $orfaos,
        'registo_da_plataforma' => $registoSistema,
        'sistema_fora_de_casamento' => $sistemaFora,
        'mesa_unica_por_casamento'  => $mesaOk,
    ]]);
}

if ($acao === 'reciclagem') {
    // Convites eliminados (recuperáveis). Ao abrir a reciclagem aproveita-se
    // para deitar fora o que já lá está há mais de RECICLAGEM_DIAS — assim a
    // limpeza acontece sozinha, sem uma tarefa agendada no servidor.
    @$conn->query("DELETE FROM {$P}convites
                   WHERE " . doCasamento() . " AND eliminado_em IS NOT NULL
                     AND eliminado_em < DATE_SUB(NOW(), INTERVAL ".RECICLAGEM_DIAS." DAY)");
    $r = $conn->query("SELECT id, codigo, nome_exibicao, lugares, eliminado_em
                       FROM {$P}convites WHERE " . doCasamento() . " AND eliminado_em IS NOT NULL
                       ORDER BY eliminado_em DESC");
    ok(['convites' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [], 'dias' => RECICLAGEM_DIAS]);
}

/**
 * Uma linha do registo, com tudo o que dela se sabe.
 *
 * O ecrã mostrava a chave de programador («lic_escalao_guardar») e escondia
 * metade dos campos. Aqui sai tudo — quem, com que papel, o quê (por extenso e
 * em cru), sobre o quê, o detalhe, quando e de onde — para a linha se poder
 * abrir e responder sem se ir buscar mais nada ao servidor.
 */
function registoLinha(array $x): array {
    [$frase, $familia] = nomeDaAcao((string)$x['accao']);
    return [
        'id'         => isset($x['id']) ? (int)$x['id'] : 0,
        'utilizador' => (string)($x['utilizador'] ?? ''),
        'papel'      => (string)($x['papel'] ?? ''),
        'accao'      => (string)$x['accao'],
        'frase'      => $frase,
        'familia'    => $familia,
        'alvo'       => (string)($x['alvo'] ?? ''),
        'detalhe'    => (string)($x['detalhe'] ?? ''),
        'ip'         => (string)($x['ip'] ?? ''),
        'criado_em'  => (string)($x['criado_em'] ?? ''),
    ];
}

if ($acao === 'registo_lista') {
    // O histórico só cresce: se mandássemos tudo, ao fim de um mês eram
    // milhares de linhas em cada abertura da janela. Vai por pedaços.
    $porPag = max(10, min(500, (int)($_GET['por_pagina'] ?? 100)));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $total  = (int)(@$conn->query("SELECT COUNT(*) FROM {$P}registo WHERE " . doCasamento() . "")?->fetch_row()[0] ?? 0);
    $r = $conn->query("SELECT id, utilizador, papel, accao, alvo, detalhe, ip, criado_em
                       FROM {$P}registo WHERE " . doCasamento() . " ORDER BY id DESC
                       LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag));
    $linhas = [];
    if ($r) while ($x = $r->fetch_assoc()) $linhas[] = registoLinha($x);
    ok(['registos' => $linhas,
        'total' => $total, 'pagina' => $pagina, 'ha_mais' => ($pagina * $porPag) < $total]);
}

if ($acao === 'registo_auditoria') {
    // O registo completo da casa — de todos os casamentos e da plataforma —,
    // com filtros e pesquisa. Só o admin. Ao contrário do casal (que só vê o
    // seu, por registo_lista), esta vista atravessa os casamentos de propósito:
    // por isso desliga-se a vigia de âmbito à volta das consultas, que é a
    // válvula honesta para uma leitura transversal e legítima.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê o registo completo.');
    $porPag = max(10, min(200, (int)($_GET['por_pagina'] ?? 60)));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $cond = ['1=1']; $par = []; $tipos = '';
    if (isset($_GET['casamento']) && $_GET['casamento'] !== '' && $_GET['casamento'] !== 'todos') {
        $cond[] = 'r.casamento_id = ?'; $par[] = (int)$_GET['casamento']; $tipos .= 'i';
    }
    if (!empty($_GET['accao'])) { $cond[] = 'r.accao = ?'; $par[] = (string)$_GET['accao']; $tipos .= 's'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['de'] ?? '')))  { $cond[] = 'r.criado_em >= ?'; $par[] = $_GET['de'] . ' 00:00:00'; $tipos .= 's'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ate'] ?? ''))) { $cond[] = 'r.criado_em <= ?'; $par[] = $_GET['ate'] . ' 23:59:59'; $tipos .= 's'; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $cond[] = '(r.utilizador LIKE ? OR r.alvo LIKE ? OR r.detalhe LIKE ? OR r.accao LIKE ?)';
        $like = '%' . $q . '%'; array_push($par, $like, $like, $like, $like); $tipos .= 'ssss';
    }
    $where = implode(' AND ', $cond);

    $prev = LigacaoAmbito::$vigiar; LigacaoAmbito::$vigiar = false;
    try {
        $stc = $conn->prepare("SELECT COUNT(*) FROM {$P}registo r WHERE $where");
        if ($par) $stc->bind_param($tipos, ...$par);
        $stc->execute(); $total = (int)$stc->get_result()->fetch_row()[0];

        $sql = "SELECT r.id, r.casamento_id, c.nome AS casamento, r.utilizador, r.papel,
                       r.accao, r.alvo, r.detalhe, r.ip, r.criado_em
                FROM {$P}registo r LEFT JOIN {$P}casamentos c ON c.id = r.casamento_id
                WHERE $where ORDER BY r.id DESC LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag);
        $st = $conn->prepare($sql);
        if ($par) $st->bind_param($tipos, ...$par);
        $st->execute();
        $rows = [];
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) {
            $rows[] = registoLinha($x) + [
                'casamento_id' => (int)$x['casamento_id'],
                'casamento'    => (string)($x['casamento'] ?? ''),
            ];
        }

        // A lista de ações para o filtro: com o nome por extenso, para o admin
        // escolher «apagou um convite» e não «convite_apagado».
        $accoes = [];
        $ra = $conn->query("SELECT DISTINCT accao FROM {$P}registo ORDER BY accao");
        if ($ra) while ($x = $ra->fetch_row()) {
            [$frase] = nomeDaAcao((string)$x[0]);
            $accoes[] = ['chave' => (string)$x[0], 'nome' => $frase];
        }
        usort($accoes, fn($a, $b) => strcoll($a['nome'], $b['nome']));
    } finally {
        LigacaoAmbito::$vigiar = $prev;
    }
    ok(['registos' => $rows, 'total' => $total, 'pagina' => $pagina,
        'ha_mais' => ($pagina * $porPag) < $total, 'accoes' => $accoes]);
}

if ($acao === 'sistema_tema_guardar') {
    // O tema é uma definição da casa (casamento_id=0), que só o admin muda.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda o tema do sistema.');
    $d = corpo();
    $tema = (string)($d['tema'] ?? '');
    if (!isset(temasDisponiveis()[$tema])) erro('Tema desconhecido.');
    $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=0 AND chave='sistema.tema'");
    $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (0, 'sistema.tema', ?)");
    $st->bind_param('s', $tema); $st->execute();
    registar($conn, 'tema_sistema', $tema, temasDisponiveis()[$tema]);
    ok(['tema' => $tema, 'rotulo' => temasDisponiveis()[$tema]]);
}

if ($acao === 'convite_flag') {
    exigirModuloApi('convidados');
    $id=(int)($_GET['id']??0); $campo=$_GET['campo']??''; $valor=!empty($_GET['valor'])?1:0;
    if (!in_array($campo,['impresso','enviado'],true)) erro('Campo inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET $campo=?, atualizado_em=$TS WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('ii',$valor,$id); $st->execute();
    registar($conn, $campo.($valor?'_sim':'_nao'), '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

if ($acao === 'convite_rsvp_manual') {
    exigirModuloApi('convidados');
    $id=(int)($_GET['id']??0); $estado=$_GET['estado']??'';
    if (!in_array($estado,['pendente','confirmado','recusado','parcial'],true)) erro('Estado inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET rsvp_estado=?, rsvp_em=$TS WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('si',$estado,$id); $st->execute();
    registar($conn, 'rsvp_manual', '', 'id '.$id.' -> '.$estado);
    ok(['stats'=>estatisticas($conn)]);
}

// ---- Mesas --------------------------------------------------
const FORMAS_MESA = ['redonda','oval','quadrada','retangular','comprida','ferradura'];
const CORES_MESA  = ['neutra','verde','ouro','terracota','azul','ameixa','rosa','salva'];

const TAMANHOS_MESA = ['auto','p','m','g'];

if ($acao === 'mesa_list') { exigirModuloApi('mesas');
    ok(['mesas'=>listarMesas($conn), 'canvas'=>plantaConfig($conn)]); }
if ($acao === 'planta_size') {
    exigirModuloApi('mesas');
    // Guarda as dimensões do canvas da planta (px), definidas ao arrastar as bordas.
    $d=corpo();
    $w = isset($d['largura']) && $d['largura']!=='' ? max(280, min(4000, (int)$d['largura'])) : null;
    $h = isset($d['altura'])  && $d['altura']!==''  ? max(200, min(4000, (int)$d['altura']))  : null;
    foreach (['planta.largura'=>$w, 'planta.altura'=>$h] as $chave=>$val) {
        $cid = casamentoAtual();
        if ($val === null) { $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='".$conn->real_escape_string($chave)."'"); continue; }
        $st=$conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $sv=(string)$val; $st->bind_param('iss',$cid,$chave,$sv); $st->execute();
    }
    ok(['canvas'=>plantaConfig($conn)]);
}
if ($acao === 'planta_rotulo') {
    exigirModuloApi('mesas');
    // Tamanho (px) do nome das mesas. Não segue o tamanho da mesa: é escolha de
    // quem desenha o salão, e a mesma para todas — senão o nome da mesa pequena
    // era sempre o que menos se lia.
    $d = corpo();
    $v = plantaRotulo($d['rotulo'] ?? PLANTA_ROTULO_PADRAO);
    $cid = casamentoAtual(); $chave = 'planta.rotulo'; $sv = (string)$v;
    $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $st->bind_param('iss', $cid, $chave, $sv); $st->execute();
    ok(['canvas' => plantaConfig($conn)]);
}
if ($acao === 'planta_bloqueio') {
    exigirModuloApi('mesas');
    // Trava/destrava o arrasto das mesas, o redimensionar do canvas e o
    // deslocar da vista lá dentro.
    $d = corpo();
    foreach (['bloq_mesas'  => 'planta.bloq_mesas',
              'bloq_canvas' => 'planta.bloq_canvas',
              'bloq_scroll' => 'planta.bloq_scroll'] as $campo => $chave) {
        if (!array_key_exists($campo, $d)) continue;
        $val = !empty($d[$campo]) ? '1' : '0';
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (" . casamentoAtual() . ",?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('ss', $chave, $val); $st->execute();
    }
    ok(['canvas' => plantaConfig($conn)]);
}
if ($acao === 'mesa_save') {
    exigirModuloApi('mesas');
    $d=corpo(); $id=(int)($d['id']??0); $nome=trim($d['nome']??'');
    $cap=($d['capacidade']??'')!==''?max(1,(int)$d['capacidade']):null;
    $forma=in_array($d['forma']??'',FORMAS_MESA,true)?$d['forma']:'redonda';
    $cor=in_array($d['cor']??'',CORES_MESA,true)?$d['cor']:null; // NULL = marfim
    $tam=in_array($d['tamanho']??'',['p','m','g'],true)?$d['tamanho']:null; // NULL = automático
    // A rotação em graus, arrumada em degraus de 15: é o que a mão acerta, e
    // poupa a que duas mesas a par fiquem tortas uma para a outra.
    $rot=((int)round(((int)($d['rotacao'] ?? 0)) / 15) * 15) % 360; if ($rot < 0) $rot += 360;
    if ($nome==='') erro('Nome da mesa obrigatório.');
    if ($id){ $st=$conn->prepare("UPDATE {$P}mesas SET nome=?,capacidade=?,forma=?,cor=?,tamanho=?,rotacao=? WHERE " . doCasamento() . " AND id=?"); $st->bind_param('sisssii',$nome,$cap,$forma,$cor,$tam,$rot,$id); }
    else    { $st=$conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,tamanho,rotacao,bar_token) VALUES (" . casamentoAtual() . ",?,?,?,?,?,?,'" . barTokenNovo() . "')"); $st->bind_param('sisssi',$nome,$cap,$forma,$cor,$tam,$rot); }
    @$st->execute();
    if ($conn->errno===1062) erro('Já existe uma mesa com esse nome.');
    $novoId = $id ?: $conn->insert_id;
    ok(['mesas'=>listarMesas($conn),'id'=>$novoId]);
}
if ($acao === 'mesa_noivos') {
    exigirModuloApi('mesas');
    // Repõe a mesa (especial) dos noivos, se tiver sido eliminada. Se já existir, devolve-a.
    $ja = $conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1")->fetch_assoc();
    if ($ja) { ok(['mesas'=>listarMesas($conn),'id'=>(int)$ja['id'],'existia'=>true]); }
    $nome='Noivos'; $n=2;
    while ($conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome='".$conn->real_escape_string($nome)."'")->num_rows) { $nome='Noivos '.$n++; }
    $st=$conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (" . casamentoAtual() . ",?,2,'redonda','ouro','noivos',50,42)");
    $st->bind_param('s',$nome); $st->execute();
    $novoId=$conn->insert_id; // capturar antes de listarMesas() (que corre outras queries)
    ok(['mesas'=>listarMesas($conn),'id'=>$novoId]);
}
if ($acao === 'mesa_pos') {
    exigirModuloApi('mesas');
    // Guarda a posição (e opcionalmente a forma) de uma mesa na planta.
    $d=corpo(); $id=(int)($d['id']??0);
    if (!$id) erro('Mesa inválida.');
    // A percentagem é do mundo BASE — o que cabe no canvas a 100%. Um salão
    // grande passa dos 100: o mundo estica e é o scroll que lá leva. O tecto
    // (600) é só para uma posição estragada não pôr o mundo em seis mil por
    // cento e deixar a planta inutilizável.
    $x = isset($d['x']) && $d['x']!=='' ? max(0.0, min(600.0, (float)$d['x'])) : null;
    $y = isset($d['y']) && $d['y']!=='' ? max(0.0, min(600.0, (float)$d['y'])) : null;
    $forma = in_array($d['forma']??'',FORMAS_MESA,true) ? $d['forma'] : null;
    if ($forma !== null) {
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=?,forma=? WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('ddsi',$x,$y,$forma,$id);
    } else {
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=? WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('ddi',$x,$y,$id);
    }
    $st->execute();
    ok();
}
if ($acao === 'mesa_delete') {
    exigirModuloApi('mesas');
    $id=(int)($_GET['id']??0);
    $nm = $conn->query("SELECT nome FROM {$P}mesas WHERE " . doCasamento() . " AND id=$id");
    $nomeMesa = ($nm && $x=$nm->fetch_assoc()) ? $x['nome'] : '';
    $conn->query("UPDATE {$P}convites SET mesa_id=NULL WHERE " . doCasamento() . " AND mesa_id=$id");
    $conn->query("UPDATE {$P}convidados SET mesa_id=NULL WHERE " . doCasamento() . " AND mesa_id=$id"); // mesas individuais também
    $st=$conn->prepare("DELETE FROM {$P}mesas WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$id); $st->execute();
    registar($conn, 'mesa_eliminada', $nomeMesa, 'id '.$id);
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convite_mesa') {
    exigirModuloApi('mesas');
    // Senta (mesa_id) ou retira (mesa_id vazio) um convite inteiro de uma mesa.
    // Define a mesa "padrão" do convite; os membros sem mesa própria seguem-na.
    $d=corpo(); $id=(int)($d['id']??0);
    if (!$id) erro('Convite inválido.');
    $mesaId = (isset($d['mesa_id']) && $d['mesa_id']!=='' && $d['mesa_id']!==null) ? (int)$d['mesa_id'] : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) erro('A mesa dos noivos só admite padrinhos e madrinhas (pelo papel).');
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=?,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?"); $st->bind_param('ii',$mesaId,$id); }
    else        { $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=NULL,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$id); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_mesa') {
    exigirModuloApi('mesas');
    // Atribui/retira a mesa individual de UMA pessoa (permite dividir um convite por mesas).
    // mesa_id vazio -> a pessoa volta a seguir a mesa do convite.
    $d=corpo(); $gid=(int)($d['id']??0);
    if (!$gid) erro('Pessoa inválida.');
    $mesaId = (isset($d['mesa_id']) && $d['mesa_id']!=='' && $d['mesa_id']!==null) ? (int)$d['mesa_id'] : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) erro('A mesa dos noivos só admite padrinhos e madrinhas (pelo papel).');
    // Sentar numa mesa normal tira a pessoa da mesa de honra: limpa o papel
    // (padrinho/madrinha). Só se limpa se a coluna existir — tolerante a esquema por migrar.
    $limpaPapel = colunaExiste($conn, "{$P}convidados", 'papel') ? ", papel=NULL" : "";
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=?$limpaPapel WHERE " . doCasamento() . " AND id=?"); $st->bind_param('ii',$mesaId,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$gid); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_papel') {
    exigirModuloApi('convidados');
    // Define o papel do convidado: 'padrinho' (ala esquerda), 'madrinha' (ala direita) ou '' (nenhum).
    // O papel deteta automaticamente as alas da mesa dos noivos.
    $d=corpo(); $gid=(int)($d['id']??0);
    if (!$gid) erro('Pessoa inválida.');
    $papel = in_array($d['papel']??'', ['padrinho','madrinha'], true) ? $d['papel'] : null;
    // Tornar-se padrinho/madrinha coloca a pessoa na mesa de honra: limpa a mesa individual.
    if ($papel){ $st=$conn->prepare("UPDATE {$P}convidados SET papel=?, mesa_id=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('si',$papel,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET papel=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$gid); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_list') {
    exigirModuloApi('convidados');
    // Todas as pessoas nomeadas, com a mesa efetiva (individual, senão a do convite).
    // Colunas opcionais protegidas (tolerante a esquema por migrar).
    $selGen = colunaExiste($conn, "{$P}convidados", 'genero') ? "g.genero" : "'' AS genero";
    $selBri = colunaExiste($conn, "{$P}convidados", 'brinde') ? "g.brinde" : "0 AS brinde";
    $sql="SELECT g.id, g.nome, g.convite_id, g.mesa_id AS mesa_pessoa, g.rsvp, g.presente, g.papel, $selGen, $selBri,
                 c.nome_exibicao, c.sufixo, c.lugares, c.mesa_id AS mesa_convite, c.codigo,
                 mp.nome AS mesa_pessoa_nome, mc.nome AS mesa_convite_nome,
                 mp.especial AS mesa_pessoa_esp, mc.especial AS mesa_convite_esp
          FROM {$P}convidados g
          JOIN {$P}convites c ON g.convite_id=c.id
          LEFT JOIN {$P}mesas mp ON g.mesa_id=mp.id
          LEFT JOIN {$P}mesas mc ON c.mesa_id=mc.id
          WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
          ORDER BY c.nome_exibicao, g.principal DESC, g.nome";
    $rows=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    // Mesa dos noivos (para a deteção automática de padrinhos/madrinhas).
    $noivos = $conn->query("SELECT id, nome FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1")->fetch_assoc();
    foreach ($rows as &$r) {
        $r['convite_nome']  = nomeConvite($r); // usa nome_exibicao/lugares/sufixo
        $ehPad = in_array($r['papel'] ?? '', ['padrinho','madrinha'], true);
        if ($ehPad && $noivos) {
            // Padrinho/madrinha: sentado sempre na mesa dos noivos, na respetiva ala.
            $r['mesa_efetiva_id']   = (int)$noivos['id'];
            $r['mesa_efetiva_nome'] = $noivos['nome'];
            $r['mesa_efetiva_esp']  = 'noivos';
        } else {
            $r['mesa_efetiva_id']   = $r['mesa_pessoa'] !== null ? (int)$r['mesa_pessoa'] : ($r['mesa_convite'] !== null ? (int)$r['mesa_convite'] : null);
            $r['mesa_efetiva_nome'] = $r['mesa_pessoa_nome'] ?: ($r['mesa_convite_nome'] ?: null);
            $r['mesa_efetiva_esp']  = $r['mesa_pessoa'] !== null ? $r['mesa_pessoa_esp'] : $r['mesa_convite_esp'];
        }
    }
    unset($r);
    ok(['convidados'=>$rows]);
}

// ---- Importar da lista antiga ------------------------------
// ============================================================
// LEVAR OS DADOS DAQUI, E TRAZÊ-LOS DE VOLTA
//
// Os dados de um casamento são do casal, não da casa. Tem de haver forma de os
// levar — para guardar, para mudar de servidor, para não ficar refém de
// ninguém. E o admin precisa do mesmo à escala da casa, que é o que se chama
// uma cópia de segurança.
//
// O ficheiro é JSON e diz de si: formato, versão do esquema, quando e por quem
// foi feito. As mesas viajam pelo NOME e não pelo número — os números são desta
// base e não querem dizer nada noutra.
// ============================================================

/**
 * As contagens do que ficou num ficheiro de exportação, para irem no cabeçalho.
 * Lêem-se do próprio $saida já montado — assim contam exatamente o que lá está,
 * quer seja a casa inteira, um casamento ou só umas secções dele.
 */
function resumoExportacao(array $saida): array {
    $r = ['casamentos' => count($saida['casamentos'] ?? []),
          'convites' => 0, 'pessoas' => 0, 'mesas' => 0, 'versoes' => 0,
          'orcamento_despesas' => 0, 'bar_itens' => 0];
    foreach ((array)($saida['casamentos'] ?? []) as $c) {
        $r['mesas']   += count((array)($c['mesas'] ?? []));
        $r['versoes'] += count((array)($c['versoes'] ?? []));
        $r['orcamento_despesas'] += count((array)($c['orcamento']['despesas'] ?? []));
        $r['bar_itens'] += count((array)($c['bar']['itens'] ?? []));
        foreach ((array)($c['convites'] ?? []) as $cv) {
            $r['convites']++;
            $r['pessoas'] += count((array)($cv['membros'] ?? []));
        }
    }
    if (isset($saida['modelos'])) $r['modelos'] = count((array)$saida['modelos']);
    if (isset($saida['contas'])) {
        $cc = 0; $ca = 0;
        foreach ((array)$saida['contas'] as $u) { !empty($u['papel_plataforma']) ? $ca++ : $cc++; }
        $r['contas'] = $cc + $ca;
        $r['contas_casamento'] = $cc;
        $r['contas_administrativas'] = $ca;
    }
    return $r;
}

/** Tudo o que compõe um casamento, pronto a escrever num ficheiro. */
function retratoCasamento(mysqli $conn, int $cid): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);

    $um = function (string $sql) use ($conn) {
        $r = @$conn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    };
    $ficha = [];
    $r = @$conn->query("SELECT nome, noiva, noivo, data_evento, estado, endereco_publico
                        FROM {$P}casamentos WHERE id=$cid LIMIT 1");
    if ($r) $ficha = $r->fetch_assoc() ?: [];

    $defs = [];
    foreach ($um("SELECT chave, valor FROM {$P}definicoes WHERE casamento_id=$cid") as $d) {
        $defs[$d['chave']] = $d['valor'];
    }

    $mesas = $um("SELECT nome, capacidade, forma, cor, especial, pos_x, pos_y, tamanho
                  FROM {$P}mesas WHERE casamento_id=$cid ORDER BY nome");

    // O nome da mesa, e não o seu número: o número é desta base.
    $convites = $um("SELECT c.codigo, c.nome_exibicao, c.sufixo, c.tipo, c.lado, c.lugares,
                            m.nome AS mesa, c.telefone, c.msg_pessoal, c.observacoes,
                            c.rsvp_estado, c.rsvp_confirmados, c.rsvp_mensagem,
                            c.checkin_estado, c.checkin_presentes,
                            c.enviado, c.impresso, c.mostrar_num_mesa, c.eliminado_em
                     FROM {$P}convites c LEFT JOIN {$P}mesas m ON m.id = c.mesa_id
                     WHERE c.casamento_id=$cid ORDER BY c.id");
    $porCodigo = [];
    foreach ($convites as $i => $c) $porCodigo[$c['codigo']] = $i;
    foreach ($convites as &$c) $c['membros'] = [];
    unset($c);
    foreach ($um("SELECT c.codigo, g.nome, g.genero, g.principal, g.rsvp, g.presente,
                         g.brinde, g.papel, mg.nome AS mesa
                  FROM {$P}convidados g
                  JOIN {$P}convites c ON c.id = g.convite_id
                  LEFT JOIN {$P}mesas mg ON mg.id = g.mesa_id
                  WHERE g.casamento_id=$cid ORDER BY g.principal DESC, g.nome") as $g) {
        $cod = $g['codigo']; unset($g['codigo']);
        if (isset($porCodigo[$cod])) $convites[$porCodigo[$cod]]['membros'][] = $g;
    }

    $versoes = $um("SELECT nome, ambito, defs, predefinida, utilizador, criado_em, atualizado_em
                    FROM {$P}versoes WHERE casamento_id=$cid ORDER BY id");

    $acessos = $um("SELECT u.email, u.nome, a.papel FROM {$P}acessos a
                    JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                    WHERE a.casamento_id=$cid ORDER BY a.papel, u.email");

    // ---- o orçamento ----
    // As gavetas viajam com o seu nome; as despesas guardam o NOME da gaveta a
    // que pertencem (o número é desta base) e levam as suas parcelas dentro,
    // como os convites levam os integrantes. O teto e a moeda já vão em
    // 'definicoes', por isso não têm aqui tratamento à parte.
    $orcCategorias = $um("SELECT nome, previsto, ordem, cor FROM {$P}orcamento_categorias
                          WHERE casamento_id=$cid ORDER BY ordem, nome");
    $orcDespesas = $um("SELECT d.id, d.descricao, d.fornecedor, d.valor, d.estado, d.nota, d.criado_em,
                               c.nome AS categoria
                        FROM {$P}orcamento_despesas d
                        LEFT JOIN {$P}orcamento_categorias c ON c.id = d.categoria_id
                        WHERE d.casamento_id=$cid ORDER BY d.id");
    $porDespId = [];
    foreach ($orcDespesas as $i => $d) { $porDespId[$d['id']] = $i; }
    foreach ($orcDespesas as &$d) $d['pagamentos'] = [];
    unset($d);
    foreach ($um("SELECT despesa_id, valor, data_prevista, pago_em, nota
                  FROM {$P}orcamento_pagamentos WHERE casamento_id=$cid
                  ORDER BY (data_prevista IS NULL), data_prevista, id") as $p) {
        $did = $p['despesa_id']; unset($p['despesa_id']);
        if (isset($porDespId[$did])) $orcDespesas[$porDespId[$did]]['pagamentos'][] = $p;
    }
    foreach ($orcDespesas as &$d) unset($d['id']);   // o id é desta base
    unset($d);

    // ---- o bar ----
    // Viaja a MONTAGEM do bar, e não a noite: as gavetas, as bebidas com o que
    // há delas, os motivos de recusa e as regras. Os pedidos e os telemóveis
    // ficam de fora de propósito — são o estado de uma festa a decorrer, e
    // trazê-los de outra base punha o stock a mentir (o «reservado» é a soma
    // dos pedidos aprovados por entregar, e reconstruí-lo a partir de um
    // ficheiro é convidar a que as duas contas deixem de bater).
    //
    // Tudo o que aponta para outra coisa aponta por NOME: o número é desta
    // base e não sobrevive a uma importação.
    $barCategorias = $um("SELECT nome, ordem, cor FROM {$P}bar_categorias
                          WHERE casamento_id=$cid ORDER BY ordem, nome");
    $barItens = $um("SELECT i.nome, i.descricao, i.alcoolico, i.volume_ml,
                            i.max_por_pedido, i.estado, i.ordem, i.stock,
                            c.nome AS categoria
                     FROM {$P}bar_itens i
                     LEFT JOIN {$P}bar_categorias c ON c.id = i.categoria_id
                                                   AND c.casamento_id = i.casamento_id
                     WHERE i.casamento_id=$cid ORDER BY i.ordem, i.nome");
    $barMotivos = $um("SELECT texto, ordem, ativo FROM {$P}bar_motivos
                       WHERE casamento_id=$cid ORDER BY ordem, id");
    // O que se diz ao convidado em cada situação. É escrita do casal — viaja
    // com o menu, como os motivos de recusa. Os ALERTAS, esses, não viajam:
    // são propostas sobre um momento, e um momento não se importa de outra
    // base. É a mesma linha que já separa a montagem da noite.
    $barMensagens = $um("SELECT situacao, texto, ativo FROM {$P}bar_mensagens
                         WHERE casamento_id=$cid ORDER BY situacao");
    $barLimites = $um("SELECT l.escopo, l.sujeito, l.unidade, l.quantidade, l.janela_min,
                              l.mensagem, l.nota, l.vigora_em, l.expira_em, l.ativo, l.modo,
                              it.nome AS alvo_item, ct.nome AS alvo_categoria,
                              g.nome AS alvo_convidado, cv.nome_exibicao AS alvo_convite
                       FROM {$P}bar_limites l
                       LEFT JOIN {$P}bar_itens it ON it.id = l.alvo_id AND l.escopo='item'
                                                 AND it.casamento_id = l.casamento_id
                       LEFT JOIN {$P}bar_categorias ct ON ct.id = l.alvo_id AND l.escopo='categoria'
                                                      AND ct.casamento_id = l.casamento_id
                       LEFT JOIN {$P}convidados g ON g.id = l.alvo_convidado_id
                                                 AND g.casamento_id = l.casamento_id
                       LEFT JOIN {$P}convites cv ON cv.id = l.alvo_convite_id
                                                AND cv.casamento_id = l.casamento_id
                       WHERE l.casamento_id=$cid ORDER BY l.id");

    usarCasamento($anterior > 0 ? $anterior : 1);
    return ['ficha' => $ficha, 'definicoes' => $defs, 'mesas' => $mesas,
            'convites' => $convites, 'versoes' => $versoes, 'acessos' => $acessos,
            'orcamento' => ['categorias' => $orcCategorias, 'despesas' => $orcDespesas],
            'bar' => ['categorias' => $barCategorias, 'itens' => $barItens,
                      'motivos' => $barMotivos, 'limites' => $barLimites,
                      'mensagens' => $barMensagens]];
}

if ($acao === 'dados_exportar') {
    $ambito = ($_GET['ambito'] ?? 'casamento') === 'sistema' ? 'sistema' : 'casamento';
    $comSenhas = !empty($_GET['senhas']);
    // As secções que o casal escolheu levar (só faz sentido no âmbito casamento).
    $partes = array_values(array_intersect(
        array_filter(array_map('trim', explode(',', (string)($_GET['partes'] ?? '')))),
        array_keys(partesCasamento())));
    // Os âmbitos que o admin escolheu levar (só no âmbito sistema). Sem escolha,
    // vale o de sempre: os casamentos e as contas.
    // As contas contam-se em duas famílias: as de CASAMENTO (noivos/porteiro) e
    // as ADMINISTRATIVAS (admin/suporte). Cada uma escolhe-se à parte; o token
    // antigo «contas» vale pelas duas, para os links de sempre continuarem.
    $incRaw = array_filter(array_map('trim', explode(',', (string)($_GET['inc'] ?? ''))));
    $incCas = !$incRaw || in_array('casamentos', $incRaw, true);
    $incMod = in_array('modelos', $incRaw, true);
    $incContasCas = !$incRaw || in_array('contas', $incRaw, true) || in_array('contas_casamento', $incRaw, true);
    $incContasAdm = !$incRaw || in_array('contas', $incRaw, true) || in_array('contas_admin', $incRaw, true);

    $ids = [];
    if ($ambito === 'sistema') {
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva a casa inteira.');
        if ($incCas) {
            // Todos, ou só os escolhidos (lista de ids em 'casamentos').
            $sel = array_filter(array_map('intval', explode(',', (string)($_GET['casamentos'] ?? ''))));
            if ($sel) {
                $lista = implode(',', $sel);
                $r = @$conn->query("SELECT id FROM {$P}casamentos WHERE id IN ($lista) ORDER BY id");
            } else {
                $r = @$conn->query("SELECT id FROM {$P}casamentos ORDER BY id");
            }
            if ($r) while ($x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
        }
    } elseif (!empty($_GET['id'])) {
        // Um casamento em concreto. Só o admin da casa, e serve sobretudo para
        // os arquivados: esses não se podem abrir, e sem isto "levar os dados"
        // de um arquivado levava, calado, os do casamento que estivesse aberto.
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva os dados de outro casamento.');
        $ids = [(int)$_GET['id']];
        $r = @$conn->query("SELECT id FROM {$P}casamentos WHERE id=" . $ids[0]);
        if (!$r || !$r->num_rows) erro('Casamento não encontrado.');
    } else {
        exigirAdminApi();
        $ids = [casamentoAtual()];
        if ($ids[0] <= 0) erro('Não há casamento aberto.');
    }

    $saida = [
        'formato'    => 'casamento-web/1',
        'esquema'    => ESQUEMA_VERSAO,
        'ambito'     => $ambito,
        'gerado_em'  => date('c'),
        'gerado_por' => utilizadorAtual() ?? '',
        // O resumo do que vai no ficheiro fica no cabeçalho — preenche-se no fim,
        // mas a chave nasce já aqui para ficar por cima, à vista de quem o abre.
        'resumo'     => null,
        'casamentos' => [],
    ];
    if ($partes) $saida['partes'] = $partes;
    foreach ($ids as $cid) {
        $retrato = retratoCasamento($conn, $cid);
        $saida['casamentos'][] = $partes ? retratoParcial($retrato, $partes) : $retrato;
    }

    if ($ambito === 'sistema' && $incMod) {
        // Os modelos da casa — o mesmo conteúdo que 'modelos_exportar'.
        $r = @$conn->query("SELECT nome, descricao, ambito, defs, visivel FROM {$P}modelos ORDER BY ambito, nome");
        $modelos = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($modelos as &$m) $m['defs'] = json_decode($m['defs'], true) ?: [];
        unset($m);
        $saida['modelos'] = $modelos;
    }
    if ($ambito === 'sistema' && ($incContasCas || $incContasAdm)) {
        // As contas escolhidas — as de casamento (papel_plataforma NULL), as
        // administrativas (admin/suporte), ou ambas. As senhas só a pedido, que
        // um ficheiro com senhas (ainda que cifradas) guarda-se como se guarda a
        // base de dados, não como se guarda uma folha de cálculo.
        $cond = ($incContasCas && $incContasAdm) ? '1=1'
              : ($incContasCas ? 'papel_plataforma IS NULL' : 'papel_plataforma IS NOT NULL');
        $cols = 'id, email, nome, papel_plataforma, estado, criado_em' . ($comSenhas ? ', senha_hash' : '');
        $r = @$conn->query("SELECT $cols FROM {$P}utilizadores WHERE $cond ORDER BY id");
        $contas = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($contas as &$c) unset($c['id']);
        unset($c);
        $saida['contas'] = $contas;
        $saida['com_senhas'] = $comSenhas ? 1 : 0;
    }

    // O resumo do que ficou no ficheiro — quantos casamentos, convites, pessoas,
    // mesas, versões, despesas, modelos e contas. É a primeira coisa que se lê ao
    // abrir o ficheiro, e a forma de confirmar de relance que não se perdeu nada.
    $saida['resumo'] = resumoExportacao($saida);

    $nome = 'dados-' . ($ambito === 'sistema' ? 'sistema' : 'casamento') . '-' . date('Y-m-d') . '.json';
    registar($conn, 'dados_exportados', $ambito,
             count($ids) . ' casamento(s)' . ($partes ? ' · ' . implode('+', $partes) : ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $nome);
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// Importação por secção
//
// Escrever um casamento a partir de um retrato faz-se por peças: as mesas, os
// convites (com as pessoas dentro), as versões, o orçamento e a ficha/desenho.
// Cada peça tem o seu escritor, e a importação — cheia ou selectiva — compõe-se
// deles. Assim o casal pode trazer só a lista de convidados sem tocar no resto,
// e a importação da casa inteira continua a ser a soma de todas as peças.
// ============================================================

/** Mapa nome→id das mesas de um casamento, para religar convites pelo nome. */
function mapaMesas(mysqli $conn, int $cid): array {
    global $P; $m = [];
    $r = @$conn->query("SELECT id, nome FROM {$P}mesas WHERE casamento_id=$cid");
    if ($r) while ($x = $r->fetch_assoc()) $m[(string)$x['nome']] = (int)$x['id'];
    return $m;
}

/** Escreve as mesas de um retrato. Devolve quantas. */
function impMesas(mysqli $conn, int $cid, array $mesas): int {
    global $P; $n = 0;
    foreach ($mesas as $m) {
        if (!is_array($m) || trim((string)($m['nome'] ?? '')) === '') continue;
        $nm = (string)$m['nome']; $cap = (int)($m['capacidade'] ?? 8);
        $forma = (string)($m['forma'] ?? 'redonda'); $cor = (string)($m['cor'] ?? 'neutra');
        $esp = isset($m['especial']) && $m['especial'] !== null ? (string)$m['especial'] : null;
        $px = isset($m['pos_x']) && $m['pos_x'] !== null ? (float)$m['pos_x'] : null;
        $py = isset($m['pos_y']) && $m['pos_y'] !== null ? (float)$m['pos_y'] : null;
        $tam = (int)($m['tamanho'] ?? 100);
        $st = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y,tamanho)
                              VALUES ($cid,?,?,?,?,?,?,?,?)");
        $st->bind_param('sisssddi', $nm, $cap, $forma, $cor, $esp, $px, $py, $tam);
        if (@$st->execute()) $n++;
    }
    return $n;
}

/** Escreve os convites (e as pessoas dentro). Devolve ['convites','pessoas','codigos_trocados']. */
function impConvites(mysqli $conn, int $cid, array $convites): array {
    global $P;
    $idMesa = mapaMesas($conn, $cid);          // as mesas que já lá estão, pelo nome
    $feito = ['convites' => 0, 'pessoas' => 0, 'codigos_trocados' => 0];
    foreach ($convites as $c) {
        if (!is_array($c) || trim((string)($c['nome_exibicao'] ?? '')) === '') continue;
        $codigo = strtoupper(trim((string)($c['codigo'] ?? '')));
        if ($codigo === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $codigo)) {
            $codigo = gerarCodigo($conn); $feito['codigos_trocados']++;
        } else {
            $q = $conn->prepare("SELECT id FROM {$P}convites WHERE casamento_id > 0 AND codigo=? LIMIT 1");
            $q->bind_param('s', $codigo); $q->execute();
            if ($q->get_result()->fetch_assoc()) { $codigo = gerarCodigo($conn); $feito['codigos_trocados']++; }
        }
        $mesaId = $idMesa[(string)($c['mesa'] ?? '')] ?? null;
        $st = $conn->prepare("INSERT INTO {$P}convites
              (casamento_id, codigo, nome_exibicao, sufixo, tipo, lado, lugares, mesa_id, telefone,
               msg_pessoal, observacoes, rsvp_estado, rsvp_confirmados, rsvp_mensagem,
               checkin_estado, checkin_presentes, enviado, impresso, mostrar_num_mesa, eliminado_em)
              VALUES ($cid,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $vals = [
            $codigo,
            mb_substr((string)$c['nome_exibicao'], 0, 160),
            isset($c['sufixo']) && $c['sufixo'] !== null ? (string)$c['sufixo'] : null,
            in_array($c['tipo'] ?? '', ['digital','fisico','ambos'], true) ? $c['tipo'] : 'digital',
            in_array($c['lado'] ?? '', ['noivo','noiva','ambos'], true) ? $c['lado'] : 'ambos',
            max(1, (int)($c['lugares'] ?? 1)),
            $mesaId,
            isset($c['telefone']) && $c['telefone'] !== null ? (string)$c['telefone'] : null,
            isset($c['msg_pessoal']) && $c['msg_pessoal'] !== null ? (string)$c['msg_pessoal'] : null,
            isset($c['observacoes']) && $c['observacoes'] !== null ? (string)$c['observacoes'] : null,
            in_array($c['rsvp_estado'] ?? '', ['pendente','confirmado','parcial','recusado'], true) ? $c['rsvp_estado'] : 'pendente',
            (int)($c['rsvp_confirmados'] ?? 0),
            isset($c['rsvp_mensagem']) && $c['rsvp_mensagem'] !== null ? (string)$c['rsvp_mensagem'] : null,
            in_array($c['checkin_estado'] ?? '', ['aguardando','presente','parcial'], true) ? $c['checkin_estado'] : 'aguardando',
            (int)($c['checkin_presentes'] ?? 0),
            (int)!empty($c['enviado']),
            (int)!empty($c['impresso']),
            isset($c['mostrar_num_mesa']) ? (int)$c['mostrar_num_mesa'] : 1,
            isset($c['eliminado_em']) && $c['eliminado_em'] !== null ? (string)$c['eliminado_em'] : null,
        ];
        $st->bind_param('sssssiissssissiiiis', ...$vals);   // 19 colunas, pela ordem acima
        if (!@$st->execute()) continue;
        $convId = $conn->insert_id; $feito['convites']++;

        foreach ((array)($c['membros'] ?? []) as $g) {
            if (!is_array($g) || trim((string)($g['nome'] ?? '')) === '') continue;
            $gm = $idMesa[(string)($g['mesa'] ?? '')] ?? null;
            $q = $conn->prepare("INSERT INTO {$P}convidados
                  (casamento_id, convite_id, nome, genero, principal, rsvp, presente, brinde, papel, mesa_id)
                  VALUES ($cid,?,?,?,?,?,?,?,?,?)");
            $gnome = mb_substr((string)$g['nome'], 0, 120);
            $gen  = in_array($g['genero'] ?? '', ['m','f'], true) ? $g['genero'] : null;
            $prin = (int)!empty($g['principal']);
            $rsvp = in_array($g['rsvp'] ?? '', ['pendente','confirmado','recusado'], true) ? $g['rsvp'] : 'pendente';
            $pres = (int)!empty($g['presente']);
            $bri  = (int)!empty($g['brinde']);
            $pap  = isset($g['papel']) && $g['papel'] !== null ? (string)$g['papel'] : null;
            $q->bind_param('issisiisi', $convId, $gnome, $gen, $prin, $rsvp, $pres, $bri, $pap, $gm);
            if (@$q->execute()) $feito['pessoas']++;
        }
    }
    return $feito;
}

/** Escreve versões (a lista já vem filtrada pelo âmbito que se quer). Devolve quantas. */
function impVersoes(mysqli $conn, int $cid, array $versoes): int {
    global $P; $n = 0;
    foreach ($versoes as $v) {
        if (!is_array($v) || trim((string)($v['nome'] ?? '')) === '') continue;
        $st = $conn->prepare("INSERT INTO {$P}versoes (casamento_id, nome, ambito, defs, predefinida, utilizador)
                              VALUES ($cid,?,?,?,?,?)");
        $vn = mb_substr((string)$v['nome'], 0, 80);
        $va = in_array($v['ambito'] ?? '', ['digital','impresso'], true) ? $v['ambito'] : 'digital';
        $vd = (string)($v['defs'] ?? '{}');
        $vp = (int)!empty($v['predefinida']);
        $vu = (string)($v['utilizador'] ?? '');
        $st->bind_param('sssis', $vn, $va, $vd, $vp, $vu);
        if (@$st->execute()) $n++;
    }
    return $n;
}

/**
 * Escreve a montagem do bar: gavetas, bebidas, motivos e regras.
 *
 * As gavetas primeiro, para as bebidas as reencontrarem pelo NOME; as bebidas
 * antes das regras, pela mesma razão. Tudo o que aponta para outra coisa
 * aponta por nome — o número era da outra base.
 *
 * O que NÃO entra aqui: pedidos e telemóveis. São o estado de uma noite a
 * decorrer, e o `reservado` de cada bebida é a soma dos pedidos aprovados por
 * entregar — trazê-los de um ficheiro punha as duas contas do stock a divergir
 * logo à entrada, que é exactamente o que o módulo inteiro existe para evitar.
 * Por isso o stock entra com `reservado = 0`: um bar que se importa está por
 * abrir.
 */
function impBar(mysqli $conn, int $cid, array $bar): array {
    global $P;
    $feito = ['bar_categorias' => 0, 'bar_itens' => 0, 'bar_motivos' => 0,
              'bar_regras' => 0, 'bar_mensagens' => 0];

    $idCat = [];
    foreach ((array)($bar['categorias'] ?? []) as $c) {
        if (!is_array($c) || trim((string)($c['nome'] ?? '')) === '') continue;
        $nm = mb_substr((string)$c['nome'], 0, 60);
        $ord = (int)($c['ordem'] ?? 0);
        $cc = strtolower(trim((string)($c['cor'] ?? '')));
        $cor = preg_match('/^#[0-9a-f]{6}$/', $cc) ? $cc : null;
        $st = $conn->prepare("INSERT INTO {$P}bar_categorias (casamento_id,nome,ordem,cor)
                              VALUES ($cid,?,?,?)");
        $st->bind_param('sis', $nm, $ord, $cor);
        if (@$st->execute()) { $idCat[$nm] = $conn->insert_id; $feito['bar_categorias']++; }
    }

    $idItem = [];
    foreach ((array)($bar['itens'] ?? []) as $i) {
        if (!is_array($i) || trim((string)($i['nome'] ?? '')) === '') continue;
        $nm = mb_substr((string)$i['nome'], 0, 80);
        $catId = $idCat[(string)($i['categoria'] ?? '')] ?? null;
        $desc = isset($i['descricao']) && $i['descricao'] !== null
              ? mb_substr((string)$i['descricao'], 0, 200) : null;
        $alc = (int)!empty($i['alcoolico']);
        $vol = (int)($i['volume_ml'] ?? 0) ?: null;
        $maxp = max(1, min(20, (int)($i['max_por_pedido'] ?? 2)));
        $est = ($i['estado'] ?? 'ativo') === 'oculto' ? 'oculto' : 'ativo';
        $ord = (int)($i['ordem'] ?? 0);
        $stk = max(0, (int)($i['stock'] ?? 0));
        // A bebida nasce a ZERO e o stock entra pelo livro-razão, como qualquer
        // outra entrada. Escrevê-lo aqui E lançar o movimento a seguir dava o
        // dobro das garrafas — foi o que aconteceu à primeira, e é o erro que
        // a regra de ouro do módulo existe para impedir: a coluna e o
        // livro-razão têm de contar a mesma história, e só contam se houver um
        // caminho por onde o stock se mexe.
        $st = $conn->prepare("INSERT INTO {$P}bar_itens
                (casamento_id,categoria_id,nome,descricao,alcoolico,volume_ml,
                 max_por_pedido,estado,ordem,stock,reservado)
                VALUES ($cid,?,?,?,?,?,?,?,?,0,0)");
        $st->bind_param('issiiisi', $catId, $nm, $desc, $alc, $vol, $maxp, $est, $ord);
        if (!@$st->execute()) continue;
        $idItem[$nm] = $conn->insert_id;
        $feito['bar_itens']++;
        if ($stk > 0) barMoverStock($conn, $idItem[$nm], $stk, 'importacao', null, 'stock importado');
    }

    foreach ((array)($bar['motivos'] ?? []) as $m) {
        if (!is_array($m) || trim((string)($m['texto'] ?? '')) === '') continue;
        $tx = mb_substr((string)$m['texto'], 0, 120);
        $ord = (int)($m['ordem'] ?? 0);
        $at = isset($m['ativo']) ? (int)!empty($m['ativo']) : 1;
        $st = $conn->prepare("INSERT INTO {$P}bar_motivos (casamento_id,texto,ordem,ativo)
                              VALUES ($cid,?,?,?)");
        $st->bind_param('sii', $tx, $ord, $at);
        if (@$st->execute()) $feito['bar_motivos']++;
    }

    foreach ((array)($bar['mensagens'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $sit = mb_substr(trim((string)($m['situacao'] ?? '')), 0, 40);
        if ($sit === '') continue;
        $tx = mb_substr((string)($m['texto'] ?? ''), 0, 240);
        $at = isset($m['ativo']) ? (int)!empty($m['ativo']) : 1;
        // Uma situação, uma linha. Se o ficheiro trouxer a mesma duas vezes,
        // fica a última — e não duas, que era o bar a dizer duas coisas
        // diferentes à mesma pergunta.
        $st = $conn->prepare("INSERT INTO {$P}bar_mensagens (casamento_id,situacao,texto,ativo)
                              VALUES ($cid,?,?,?)
                              ON DUPLICATE KEY UPDATE texto=VALUES(texto), ativo=VALUES(ativo)");
        $st->bind_param('ssi', $sit, $tx, $at);
        if (@$st->execute()) $feito['bar_mensagens']++;
    }

    // As regras por último: precisam das bebidas, das gavetas, das pessoas e
    // dos convites já escritos para os reencontrar pelo nome.
    $idPessoa = []; $idConvite = [];
    $r = @$conn->query("SELECT id, nome FROM {$P}convidados WHERE casamento_id=$cid");
    if ($r) while ($x = $r->fetch_assoc()) $idPessoa[$x['nome']] = (int)$x['id'];
    $r = @$conn->query("SELECT id, nome_exibicao FROM {$P}convites WHERE casamento_id=$cid");
    if ($r) while ($x = $r->fetch_assoc()) $idConvite[$x['nome_exibicao']] = (int)$x['id'];

    foreach ((array)($bar['limites'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $escopo = in_array($l['escopo'] ?? '', ['item','categoria','tudo'], true) ? $l['escopo'] : 'tudo';
        $alvo = 0;
        if ($escopo === 'item')      $alvo = $idItem[(string)($l['alvo_item'] ?? '')] ?? 0;
        if ($escopo === 'categoria') $alvo = $idCat[(string)($l['alvo_categoria'] ?? '')] ?? 0;
        // Uma regra sobre uma bebida que não veio no ficheiro não se inventa
        // como regra de TUDO: isso alargava-a a coisas que ninguém quis travar.
        if ($escopo !== 'tudo' && $alvo <= 0) continue;
        $suj = ($l['sujeito'] ?? '') === 'casa' ? 'casa' : 'convidado';
        $uni = ($l['unidade'] ?? '') === 'pedidos' ? 'pedidos' : 'bebidas';
        $pessoa  = $suj === 'casa' ? null : ($idPessoa[(string)($l['alvo_convidado'] ?? '')] ?? null);
        $convite = $suj === 'casa' ? null : ($idConvite[(string)($l['alvo_convite'] ?? '')] ?? null);
        if ($pessoa && $convite) $convite = null;         // é de uma ou de outro
        $qtd = max(0, min(999, (int)($l['quantidade'] ?? 0)));
        $jan = max(0, min(1440, (int)($l['janela_min'] ?? 0)));
        if ($uni === 'pedidos' && $escopo !== 'tudo') continue;   // como na API
        $msg = isset($l['mensagem']) && $l['mensagem'] !== null
             ? mb_substr((string)$l['mensagem'], 0, 160) : null;
        $nota = isset($l['nota']) && $l['nota'] !== null
              ? mb_substr((string)$l['nota'], 0, 160) : null;
        $hora = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string)$v)
                        ? str_replace('T', ' ', substr((string)$v, 0, 16)) . ':00' : null;
        $vig = $hora($l['vigora_em'] ?? '');
        $exp = $hora($l['expira_em'] ?? '');
        $at = isset($l['ativo']) ? (int)!empty($l['ativo']) : 1;
        // Uma regra de um ficheiro antigo (anterior ao v40) não traz modo. Cai
        // em 'trava', que é o que ela fazia na base de onde saiu.
        $modo = in_array($l['modo'] ?? '', ['trava','sugere','confirma','avisa'], true)
              ? $l['modo'] : 'trava';
        $st = $conn->prepare("INSERT INTO {$P}bar_limites
                (casamento_id,escopo,alvo_id,sujeito,alvo_convidado_id,alvo_convite_id,
                 unidade,quantidade,janela_min,mensagem,nota,vigora_em,expira_em,ativo,modo,criado_por)
                VALUES ($cid,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'importado')");
        $st->bind_param('sisiisiissssis', $escopo, $alvo, $suj, $pessoa, $convite,
                        $uni, $qtd, $jan, $msg, $nota, $vig, $exp, $at, $modo);
        if (@$st->execute()) $feito['bar_regras']++;
    }
    return $feito;
}

/** Escreve o orçamento: gavetas, despesas e parcelas. Devolve os três totais. */
function impOrcamento(mysqli $conn, int $cid, array $orc): array {
    global $P;
    $feito = ['orc_categorias' => 0, 'orc_despesas' => 0, 'orc_pagamentos' => 0];
    // As gavetas primeiro, para as despesas as reencontrarem pelo nome (o número
    // era da outra base). Depois cada despesa, e as suas parcelas dentro.
    $idCat = [];
    foreach ((array)($orc['categorias'] ?? []) as $c) {
        if (!is_array($c) || trim((string)($c['nome'] ?? '')) === '') continue;
        $nm = mb_substr((string)$c['nome'], 0, 80);
        $prev = orcValor($c['previsto'] ?? '0');
        $ord = (int)($c['ordem'] ?? 0);
        $cc = strtolower(trim((string)($c['cor'] ?? '')));
        $cor = preg_match('/^#[0-9a-f]{6}$/', $cc) ? $cc : null;
        $st = $conn->prepare("INSERT INTO {$P}orcamento_categorias (casamento_id,nome,previsto,ordem,cor) VALUES ($cid,?,?,?,?)");
        $st->bind_param('ssis', $nm, $prev, $ord, $cor);
        if (@$st->execute()) { $idCat[$nm] = $conn->insert_id; $feito['orc_categorias']++; }
    }
    foreach ((array)($orc['despesas'] ?? []) as $dsp) {
        if (!is_array($dsp) || trim((string)($dsp['descricao'] ?? '')) === '') continue;
        $catId = $idCat[(string)($dsp['categoria'] ?? '')] ?? null;
        $desc = mb_substr((string)$dsp['descricao'], 0, 160);
        $forn = isset($dsp['fornecedor']) && $dsp['fornecedor'] !== null ? mb_substr((string)$dsp['fornecedor'], 0, 120) : null;
        $val  = orcValor($dsp['valor'] ?? '0');
        $estado = in_array($dsp['estado'] ?? '', ['previsto','pago'], true) ? $dsp['estado'] : 'previsto';
        $nota = isset($dsp['nota']) && $dsp['nota'] !== null ? mb_substr((string)$dsp['nota'], 0, 255) : null;
        $st = $conn->prepare("INSERT INTO {$P}orcamento_despesas (casamento_id,categoria_id,descricao,fornecedor,valor,estado,nota)
                              VALUES ($cid,?,?,?,?,?,?)");
        $st->bind_param('isssss', $catId, $desc, $forn, $val, $estado, $nota);
        if (!@$st->execute()) continue;
        $despId = $conn->insert_id; $feito['orc_despesas']++;

        foreach ((array)($dsp['pagamentos'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $pv = orcValor($p['valor'] ?? '0');
            $pd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['data_prevista'] ?? '')) ? $p['data_prevista'] : null;
            $pp = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['pago_em'] ?? '')) ? $p['pago_em'] : null;
            $pn = isset($p['nota']) && $p['nota'] !== null ? mb_substr((string)$p['nota'], 0, 160) : null;
            $q = $conn->prepare("INSERT INTO {$P}orcamento_pagamentos (casamento_id,despesa_id,valor,data_prevista,pago_em,nota)
                                 VALUES ($cid,?,?,?,?,?)");
            $q->bind_param('issss', $despId, $pv, $pd, $pp, $pn);
            if (@$q->execute()) $feito['orc_pagamentos']++;
        }
    }
    return $feito;
}

/** Escreve a ficha (se pedida) e as definições. Devolve quantas definições. */
function impFichaDefs(mysqli $conn, int $cid, array $r, bool $comFicha): int {
    global $P;
    if ($comFicha && !empty($r['ficha'])) {
        $f = $r['ficha'];
        $st = $conn->prepare("UPDATE {$P}casamentos SET nome=?, noiva=?, noivo=?, data_evento=? WHERE id=?");
        $nome = mb_substr((string)($f['nome'] ?? ''), 0, 160);
        $noiva = mb_substr((string)($f['noiva'] ?? ''), 0, 80);
        $noivo = mb_substr((string)($f['noivo'] ?? ''), 0, 80);
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['data_evento'] ?? '')) ? $f['data_evento'] : null;
        $st->bind_param('ssssi', $nome, $noiva, $noivo, $data, $cid);
        @$st->execute();
    }
    $n = 0;
    foreach ((array)($r['definicoes'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_string($v)) continue;
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (?,?,?)");
        $st->bind_param('iss', $cid, $k, $v);
        if (@$st->execute()) $n++;
    }
    return $n;
}

/**
 * Escreve um casamento INTEIRO a partir de um retrato. Devolve o que fez.
 *
 * Os códigos dos convites são únicos em todo o sistema: se um já estiver
 * tomado, gera-se outro — e diz-se quantos, porque um código que muda é um QR
 * já impresso que deixa de servir.
 */
function reporCasamento(mysqli $conn, int $cid, array $r, bool $comFicha): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);

    // Fora o que lá estava. É o que "substituir" quer dizer, e a página di-lo
    // antes de chegar aqui. As parcelas antes das despesas, e estas antes das
    // categorias, para as chaves estrangeiras não travarem.
    // O bar sai primeiro, e de dentro para fora: as suas linhas apontam para
    // convidados e mesas, e apagar essas antes deixava a fila a apontar para
    // gente que já não existe.
    foreach (['bar_pedido_itens', 'bar_pedidos', 'bar_stock_mov', 'bar_alertas', 'bar_limites',
              'bar_motivos', 'bar_mensagens', 'bar_dispositivos', 'bar_itens', 'bar_categorias',
              'convidados', 'convites', 'mesas', 'versoes', 'definicoes',
              'orcamento_pagamentos', 'orcamento_despesas', 'orcamento_categorias'] as $t) {
        $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
    }

    $feito = ['mesas' => 0, 'convites' => 0, 'pessoas' => 0, 'versoes' => 0,
              'definicoes' => 0, 'codigos_trocados' => 0,
              'orc_categorias' => 0, 'orc_despesas' => 0, 'orc_pagamentos' => 0,
              'bar_categorias' => 0, 'bar_itens' => 0, 'bar_motivos' => 0,
              'bar_regras' => 0, 'bar_mensagens' => 0];
    $feito['definicoes'] = impFichaDefs($conn, $cid, $r, $comFicha);
    $feito['mesas']      = impMesas($conn, $cid, (array)($r['mesas'] ?? []));
    $cv = impConvites($conn, $cid, (array)($r['convites'] ?? []));
    $feito['convites'] = $cv['convites']; $feito['pessoas'] = $cv['pessoas'];
    $feito['codigos_trocados'] = $cv['codigos_trocados'];
    $feito['versoes'] = impVersoes($conn, $cid, (array)($r['versoes'] ?? []));
    foreach (impOrcamento($conn, $cid, (array)($r['orcamento'] ?? [])) as $k => $v) $feito[$k] = $v;
    // O bar por último: as regras dele apontam para bebidas, gavetas, pessoas
    // e convites, e todos esses têm de estar escritos para se reencontrarem
    // pelo nome.
    foreach (impBar($conn, $cid, (array)($r['bar'] ?? [])) as $k => $v) $feito[$k] = $v;

    usarCasamento($anterior > 0 ? $anterior : $cid);
    return $feito;
}

/** As secções que um casal pode escolher, e a etiqueta de cada uma. */
function partesCasamento(): array {
    return ['convidados' => 'Lista de convidados', 'mesas' => 'Mesas',
            'digital' => 'Versões do convite digital', 'impresso' => 'Versões do convite impresso',
            'orcamento' => 'Orçamento', 'bar' => 'Bar (menu, stock e regras)'];
}

/** Um retrato ficando só com as secções pedidas (a ficha vai sempre, para nomear). */
function retratoParcial(array $r, array $partes): array {
    $out = ['ficha' => $r['ficha'] ?? [], 'partes' => array_values($partes)];
    if (in_array('convidados', $partes, true)) $out['convites'] = $r['convites'] ?? [];
    if (in_array('mesas', $partes, true))      $out['mesas'] = $r['mesas'] ?? [];
    $amb = [];
    if (in_array('digital', $partes, true))  $amb[] = 'digital';
    if (in_array('impresso', $partes, true)) $amb[] = 'impresso';
    if ($amb) $out['versoes'] = array_values(array_filter((array)($r['versoes'] ?? []),
        fn($v) => in_array($v['ambito'] ?? '', $amb, true)));
    if (in_array('orcamento', $partes, true)) {
        $out['orcamento'] = $r['orcamento'] ?? [];
        $out['definicoes'] = array_intersect_key((array)($r['definicoes'] ?? []),
            array_flip(['orcamento.total', 'orcamento.moeda']));
    }
    if (in_array('bar', $partes, true)) {
        $out['bar'] = $r['bar'] ?? [];
        // As definições do bar viajam com ele: sem elas o bar chegava montado
        // mas com as regras da casa de fábrica — e o interruptor a dizer que
        // está fechado, que é o de origem.
        $out['definicoes'] = ($out['definicoes'] ?? []) + array_filter(
            (array)($r['definicoes'] ?? []),
            fn($k) => str_starts_with($k, 'bar.'), ARRAY_FILTER_USE_KEY);
    }
    return $out;
}

/**
 * Escreve só as secções pedidas de um retrato, substituindo o que lá estava
 * DESSAS secções e deixando o resto intacto. Devolve o que fez.
 */
function reporCasamentoPartes(mysqli $conn, int $cid, array $r, array $partes): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $feito = ['mesas' => 0, 'convites' => 0, 'pessoas' => 0, 'versoes' => 0,
              'codigos_trocados' => 0, 'orc_categorias' => 0, 'orc_despesas' => 0, 'orc_pagamentos' => 0];

    if (in_array('mesas', $partes, true)) {
        // Trocar a planta larga os lugares que apontavam para as mesas antigas.
        $conn->query("UPDATE {$P}convites SET mesa_id=NULL WHERE casamento_id=$cid");
        $conn->query("UPDATE {$P}convidados SET mesa_id=NULL WHERE casamento_id=$cid");
        $conn->query("DELETE FROM {$P}mesas WHERE casamento_id=$cid");
        $feito['mesas'] = impMesas($conn, $cid, (array)($r['mesas'] ?? []));
    }
    if (in_array('convidados', $partes, true)) {
        $conn->query("DELETE FROM {$P}convidados WHERE casamento_id=$cid");
        $conn->query("DELETE FROM {$P}convites WHERE casamento_id=$cid");
        $cv = impConvites($conn, $cid, (array)($r['convites'] ?? []));
        $feito['convites'] = $cv['convites']; $feito['pessoas'] = $cv['pessoas'];
        $feito['codigos_trocados'] = $cv['codigos_trocados'];
    }
    foreach (['digital', 'impresso'] as $amb) {
        if (!in_array($amb, $partes, true)) continue;
        $st = $conn->prepare("DELETE FROM {$P}versoes WHERE casamento_id=? AND ambito=?");
        $st->bind_param('is', $cid, $amb); $st->execute();
        $vs = array_values(array_filter((array)($r['versoes'] ?? []), fn($v) => ($v['ambito'] ?? '') === $amb));
        $feito['versoes'] += impVersoes($conn, $cid, $vs);
    }
    if (in_array('orcamento', $partes, true)) {
        foreach (['orcamento_pagamentos', 'orcamento_despesas', 'orcamento_categorias'] as $t) {
            $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
        }
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave IN ('orcamento.total','orcamento.moeda')");
        foreach (impOrcamento($conn, $cid, (array)($r['orcamento'] ?? [])) as $k => $v) $feito[$k] = $v;
        foreach (['orcamento.total', 'orcamento.moeda'] as $k) {
            $val = $r['definicoes'][$k] ?? null;
            if (is_string($val) && $val !== '') {
                $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,?,?)");
                $st->bind_param('ss', $k, $val); @$st->execute();
            }
        }
        esquecerDefinicoes($conn);
    }
    if (in_array('bar', $partes, true)) {
        // Pela ordem inversa das dependências, para não deixar órfãos: os
        // movimentos e as linhas de pedido antes das bebidas, e assim adiante.
        // Os PEDIDOS também caem — um bar novo não pode ficar com a fila do
        // antigo, que apontaria para bebidas que já não existem.
        foreach (['bar_pedido_itens', 'bar_pedidos', 'bar_stock_mov', 'bar_limites',
                  'bar_motivos', 'bar_itens', 'bar_categorias'] as $t) {
            $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
        }
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave LIKE 'bar.%'");
        foreach (impBar($conn, $cid, (array)($r['bar'] ?? [])) as $k => $v) $feito[$k] = $v;
        foreach ((array)($r['definicoes'] ?? []) as $k => $val) {
            if (!str_starts_with((string)$k, 'bar.') || !is_string($val) || $val === '') continue;
            $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,?,?)");
            $st->bind_param('ss', $k, $val); @$st->execute();
        }
        esquecerDefinicoes($conn);
    }

    usarCasamento($anterior > 0 ? $anterior : $cid);
    return $feito;
}

/**
 * Devolve uma peça (digital/impresso) ao desenho de origem: o de fábrica, com o
 * desenho do modelo de origem por cima, se o houver. A identidade do casal
 * (as chaves casal. e evento.) é da ficha do casamento, não do desenho — fica.
 * As fotografias postas à mão saem, do desenho e do disco. Corre no casamento
 * em uso (usarCasamento já foi chamado por quem chama).
 */
function reporPecaAOrigem(mysqli $conn, string $ambito): void {
    $atuais = defsAtuais($conn);
    $base = padraoAmbito($ambito);
    $orig = modeloDeOrigem($conn, $ambito);
    if ($orig) {
        $des = desenhoDoModeloId($conn, $ambito, (int)$orig['id']);
        if ($des) $base = array_merge($base, $des);
    }
    // A identidade não é desenho: mantém-se o que a ficha do casamento diz.
    foreach ($base as $k => $v) {
        if (preg_match('/^(casal|evento)\./', $k)) $base[$k] = (string)($atuais[$k] ?? $v);
    }
    // As fotografias do casal saem do disco (as que mais ninguém use).
    foreach ($atuais as $k => $v) {
        if (str_starts_with($k, 'media.') && ehFotoCustom((string)$v) && !ficheiroEmVersao($conn, (string)$v)) {
            @unlink(__DIR__ . '/' . $v);
        }
    }
    guardarDefinicoes($conn, $base);
}

/**
 * Repõe de fábrica as secções pedidas: apaga o que o casal lá pôs, sem trazer
 * nada de volta. Devolve quanto se apagou de cada secção.
 */
function reporFabricaPartes(mysqli $conn, int $cid, array $partes): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $um = fn(string $sql) => (int)(@$conn->query($sql)?->fetch_row()[0] ?? 0);
    $feito = [];

    if (in_array('convidados', $partes, true)) {
        $feito['convites'] = $um("SELECT COUNT(*) FROM {$P}convites WHERE casamento_id=$cid");
        $feito['pessoas']  = $um("SELECT COUNT(*) FROM {$P}convidados WHERE casamento_id=$cid");
        $conn->query("DELETE FROM {$P}convidados WHERE casamento_id=$cid");
        $conn->query("DELETE FROM {$P}convites WHERE casamento_id=$cid");
    }
    if (in_array('mesas', $partes, true)) {
        $feito['mesas'] = $um("SELECT COUNT(*) FROM {$P}mesas WHERE casamento_id=$cid");
        $conn->query("UPDATE {$P}convites SET mesa_id=NULL WHERE casamento_id=$cid");
        $conn->query("UPDATE {$P}convidados SET mesa_id=NULL WHERE casamento_id=$cid");
        $conn->query("DELETE FROM {$P}mesas WHERE casamento_id=$cid");
    }
    foreach (['digital', 'impresso'] as $amb) {
        if (!in_array($amb, $partes, true)) continue;
        // Apaga as versões guardadas dessa peça...
        $q = $conn->prepare("SELECT COUNT(*) FROM {$P}versoes WHERE casamento_id=? AND ambito=?");
        $q->bind_param('is', $cid, $amb); $q->execute();
        $feito['versoes'] = ($feito['versoes'] ?? 0) + (int)$q->get_result()->fetch_row()[0];
        $d = $conn->prepare("DELETE FROM {$P}versoes WHERE casamento_id=? AND ambito=?");
        $d->bind_param('is', $cid, $amb); $d->execute();
        // ...e devolve a peça ao desenho de origem, largando as fotos que o casal
        // pôs à mão. A identidade (nomes, data) é da ficha e fica. Reposição de
        // fábrica é apagar o que se acrescentou, não só as versões guardadas.
        reporPecaAOrigem($conn, $amb);
    }
    if (in_array('orcamento', $partes, true)) {
        $feito['orc_despesas'] = $um("SELECT COUNT(*) FROM {$P}orcamento_despesas WHERE casamento_id=$cid");
        foreach (['orcamento_pagamentos', 'orcamento_despesas', 'orcamento_categorias'] as $t) {
            $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
        }
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave IN ('orcamento.total','orcamento.moeda')");
        esquecerDefinicoes($conn);
    }
    if (in_array('bar', $partes, true)) {
        // Reposição de fábrica do bar: sai tudo, e volta a semente — as gavetas
        // e os motivos de recusa, para a copa não recomeçar numa folha em
        // branco. É o mesmo bar de origem que um casamento novo recebe.
        $feito['bar_itens'] = $um("SELECT COUNT(*) FROM {$P}bar_itens WHERE casamento_id=$cid");
        foreach (['bar_pedido_itens', 'bar_pedidos', 'bar_stock_mov', 'bar_alertas', 'bar_limites',
                  'bar_motivos', 'bar_mensagens', 'bar_dispositivos', 'bar_itens',
                  'bar_categorias'] as $t) {
            $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
        }
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave LIKE 'bar.%'");
        esquecerDefinicoes($conn);
        semearBar($conn, $cid);
    }

    usarCasamento($anterior > 0 ? $anterior : $cid);
    return $feito;
}

if ($acao === 'dados_importar') {
    $d = corpo();
    $f = is_array($d['ficheiro'] ?? null) ? $d['ficheiro'] : null;
    $modo = ($d['modo'] ?? 'substituir') === 'novo' ? 'novo' : 'substituir';
    if (!$f) erro('Ficheiro inválido: não se percebeu o conteúdo.');
    if (($f['formato'] ?? '') !== 'casamento-web/1') {
        erro('Este ficheiro não é uma exportação deste sistema.');
    }
    $lista = is_array($f['casamentos'] ?? null) ? $f['casamentos'] : [];
    if (!$lista) erro('O ficheiro não traz casamento nenhum.');

    $resumo = [];
    if ($modo === 'novo') {
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma traz casamentos novos.');
        foreach ($lista as $r) {
            if (!is_array($r)) continue;
            $fi = (array)($r['ficha'] ?? []);
            $nome  = mb_substr(trim((string)($fi['nome'] ?? 'Casamento importado')), 0, 160) ?: 'Casamento importado';
            $noiva = mb_substr((string)($fi['noiva'] ?? ''), 0, 80);
            $noivo = mb_substr((string)($fi['noivo'] ?? ''), 0, 80);
            $data  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($fi['data_evento'] ?? '')) ? $fi['data_evento'] : null;
            $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado)
                                  VALUES (?,?,?,?, 'ativo')");
            $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
            if (!$st->execute()) continue;
            $novo = $conn->insert_id;
            $feito = reporCasamento($conn, $novo, $r, false);
            $feito['id'] = $novo; $feito['nome'] = $nome;
            $resumo[] = $feito;
            registar($conn, 'dados_importados', $nome, 'casamento novo #' . $novo);
        }
        if (!$resumo) erro('Não foi possível criar casamento nenhum a partir do ficheiro.');
    } else {
        exigirAdminApi();
        exigirCorrecao();
        $cid = casamentoAtual();
        if ($cid <= 0) erro('Não há casamento aberto.');
        if (count($lista) > 1) {
            erro('Este ficheiro traz ' . count($lista) . ' casamentos. Para os trazer todos, '
               . 'use "criar casamentos novos" na página de administração.');
        }
        $r0 = $lista[0];
        // As secções que o casal escolheu trazer. Vale a interseção do que se
        // pediu com o que o ficheiro traz mesmo — trazer «convidados» de um
        // ficheiro que não os tem esvaziaria a lista sem aviso.
        $partesPedidas = array_values(array_intersect(listaCorpo($d['partes'] ?? ''), array_keys(partesCasamento())));
        $noFicheiro = (isset($r0['partes']) && is_array($r0['partes']))
            ? $r0['partes'] : array_keys(partesCasamento());   // retrato cheio traz tudo
        if ($partesPedidas) {
            $ef = array_values(array_intersect($partesPedidas, $noFicheiro));
            if (!$ef) erro('O ficheiro não traz as secções que escolheu.');
            $feito = reporCasamentoPartes($conn, $cid, $r0, $ef);
            $feito['partes'] = $ef;
        } else {
            $comFicha = !empty($d['com_ficha']);
            $feito = reporCasamento($conn, $cid, $r0, $comFicha);
        }
        $feito['id'] = $cid;
        $resumo[] = $feito;
        registar($conn, 'dados_importados', 'substituição', json_encode($feito));
    }
    ok(['modo' => $modo, 'resumo' => $resumo]);
}

if ($acao === 'casamento_repor_fabrica') {
    // O casal repõe de fábrica as secções que escolher: apaga o que lá pôs
    // (lista de convidados, mesas, versões, orçamento), sem trazer nada de volta.
    exigirAdminApi();
    exigirCorrecao();
    $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $d = corpo();
    // 'tudo' apaga o casamento inteiro (tudo o que é dele). É o que a gestão dos
    // noivos pede: os dados são deles, e ou se levam/trazem/apagam por inteiro
    // ou não se percebe o que ficou para trás. A lista de partes continua a
    // servir quem chame a API com uma escolha.
    $partes = !empty($d['tudo'])
        ? array_keys(partesCasamento())
        : array_values(array_intersect(listaCorpo($d['partes'] ?? ''), array_keys(partesCasamento())));
    if (!$partes) erro('Escolha o que quer repor de fábrica.');
    $feito = reporFabricaPartes($conn, $cid, $partes);
    registar($conn, 'casamento_reposto', 'fábrica', !empty($d['tudo']) ? 'tudo' : implode('+', $partes));
    ok(['partes' => $partes, 'feito' => $feito]);
}


// ============================================================
// ORÇAMENTO — o curso das despesas do casamento
//
// Três tabelas com dono (categorias, despesas, pagamentos) e dois ajustes que
// vivem em cw_definicoes (o teto e a moeda). Tudo por casamento e só para os
// noivos: exigirAdminApi() barra o porteiro, e a leitura (orc_estado) fica de
// fora de acoesDoCasamento() para uma visita de suporte poder VER sem mexer.
//
// O dinheiro chega do ecrã como texto e sai normalizado para DECIMAL —
// orcValor() (em db.php) aceita "1.234,56", "1234.56" ou "1 234,56" sem se
// enganar; o teto e a moeda gravam-se pelos ajudantes de db.php, os mesmos
// que a Gestão e o registo usam.
// ============================================================

if ($acao === 'orc_estado') {
    exigirModuloApi('orcamento');
    // Uma leitura só: o retrato em números, as gavetas com o real de cada uma,
    // as despesas e as parcelas. É o que a página desenha.
    $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');

    $cats = [];
    $rc = @$conn->query("SELECT c.id, c.nome, c.previsto, c.ordem, c.cor,
            COALESCE((SELECT SUM(valor) FROM {$P}orcamento_despesas d
                      WHERE d.casamento_id=$cid AND d.categoria_id=c.id),0) AS real_total,
            COALESCE((SELECT SUM(valor) FROM {$P}orcamento_despesas d
                      WHERE d.casamento_id=$cid AND d.categoria_id=c.id AND d.estado='pago'),0) AS pago
            FROM {$P}orcamento_categorias c WHERE c.casamento_id=$cid
            ORDER BY c.ordem, c.nome");
    if ($rc) $cats = $rc->fetch_all(MYSQLI_ASSOC);

    // O que ficou sem gaveta (categoria apagada): conta na barra, e o casal vê
    // que existe para lhe dar destino.
    $semCat = @$conn->query("SELECT COALESCE(SUM(valor),0) AS s, COUNT(*) AS n
            FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND categoria_id IS NULL")->fetch_assoc();

    $desp = [];
    // Duas somas por despesa, e não uma: o que está repartido em prestações
    // (paguem-se ou não) e o que dessas já foi liquidado. Andavam confundidas
    // numa só, chamada 'pago_parcelas', que somava tudo — e assim ninguém podia
    // descontar do previsto o que já tinha saído.
    $rd = @$conn->query("SELECT dd.id, dd.categoria_id, dd.descricao, dd.fornecedor, dd.valor, dd.estado, dd.nota, dd.fatura,
            COALESCE((SELECT SUM(valor) FROM {$P}orcamento_pagamentos p
                      WHERE p.casamento_id=$cid AND p.despesa_id=dd.id),0) AS parcelado,
            COALESCE((SELECT SUM(valor) FROM {$P}orcamento_pagamentos p
                      WHERE p.casamento_id=$cid AND p.despesa_id=dd.id
                        AND p.pago_em IS NOT NULL),0) AS liquidado,
            (SELECT COUNT(*) FROM {$P}orcamento_pagamentos p
                      WHERE p.casamento_id=$cid AND p.despesa_id=dd.id) AS n_parcelas
            FROM {$P}orcamento_despesas dd WHERE dd.casamento_id=$cid
            ORDER BY dd.criado_em DESC, dd.id DESC");
    if ($rd) $desp = $rd->fetch_all(MYSQLI_ASSOC);

    $pags = [];
    $rp = @$conn->query("SELECT p.id, p.despesa_id, p.valor, p.data_prevista, p.pago_em, p.nota,
            d.descricao AS despesa
            FROM {$P}orcamento_pagamentos p
            JOIN {$P}orcamento_despesas d ON d.id = p.despesa_id AND d.casamento_id=$cid
            WHERE p.casamento_id=$cid
            ORDER BY (p.data_prevista IS NULL), p.data_prevista, p.id");
    if ($rp) $pags = $rp->fetch_all(MYSQLI_ASSOC);

    ok([
        'resumo'     => orcamentoResumo($conn),
        'moeda'      => orcamentoMoeda($conn),
        'categorias' => $cats,
        'sem_categoria' => ['valor' => (float)($semCat['s'] ?? 0), 'n' => (int)($semCat['n'] ?? 0)],
        'despesas'   => $desp,
        'pagamentos' => $pags,
    ]);
}

if ($acao === 'orc_ajuste') {
    exigirModuloApi('orcamento');
    // O teto e a moeda — geridos na Gestão, mas o mesmo endpoint serve. Vivem
    // em cw_definicoes para viajarem no retrato do casamento sem tratamento à
    // parte. Teto a zero (ou vazio) = sem teto: a barra mede-se então pela soma
    // dos previstos das categorias.
    $d = corpo(); $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    if (array_key_exists('total', $d)) orcamentoDefinirTeto($conn, $cid, $d['total']);
    if (array_key_exists('moeda', $d)) orcamentoDefinirMoeda($conn, $cid, $d['moeda']);
    registar($conn, 'orcamento_ajuste', '', 'teto e moeda');
    ok(['resumo' => orcamentoResumo($conn), 'moeda' => orcamentoMoeda($conn)]);
}

if ($acao === 'orc_categoria_guardar') {
    exigirModuloApi('orcamento');
    $d = corpo(); $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $id = (int)($d['id'] ?? 0);
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('Dê um nome à categoria.');
    $prev = orcValor($d['previsto'] ?? '0');
    // A cor é escolha do casal. Vazio (ou ausente) = deixa a sugerida, que o
    // ecrã calcula sozinho; só se guarda um #RRGGBB válido. Uma cor torta não
    // é erro — ignora-se e a categoria fica com a sugestão.
    $temCor = array_key_exists('cor', $d);
    $cor = null;
    if ($temCor) {
        $c = strtolower(trim((string)$d['cor']));
        if (preg_match('/^#[0-9a-f]{6}$/', $c)) $cor = $c;
    }
    if ($id) {
        // Só se mexe na cor quando ela vem no pedido — guardar o nome não apaga
        // a cor escolhida antes.
        if ($temCor) {
            $st = $conn->prepare("UPDATE {$P}orcamento_categorias SET nome=?, previsto=?, cor=? WHERE casamento_id=$cid AND id=?");
            $st->bind_param('sssi', $nome, $prev, $cor, $id); @$st->execute();
        } else {
            $st = $conn->prepare("UPDATE {$P}orcamento_categorias SET nome=?, previsto=? WHERE casamento_id=$cid AND id=?");
            $st->bind_param('ssi', $nome, $prev, $id); @$st->execute();
        }
    } else {
        $ord = (int)(@$conn->query("SELECT COALESCE(MAX(ordem),-1)+1 AS o FROM {$P}orcamento_categorias WHERE casamento_id=$cid")->fetch_assoc()['o'] ?? 0);
        $st = $conn->prepare("INSERT INTO {$P}orcamento_categorias (casamento_id,nome,previsto,ordem,cor) VALUES ($cid,?,?,?,?)");
        $st->bind_param('ssis', $nome, $prev, $ord, $cor); @$st->execute();
        $id = $conn->insert_id;
    }
    registar($conn, 'orcamento_categoria', $nome, $id ? ('id ' . $id) : 'nova');
    ok(['id' => $id, 'resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_categoria_apagar') {
    exigirModuloApi('orcamento');
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    if (!$id) erro('Categoria inválida.');
    // As despesas ficam: a chave estrangeira põe-lhes categoria_id a NULL. O
    // dinheiro não desaparece só porque a gaveta mudou de nome.
    $st = $conn->prepare("DELETE FROM {$P}orcamento_categorias WHERE casamento_id=$cid AND id=?");
    $st->bind_param('i', $id); @$st->execute();
    registar($conn, 'orcamento_categoria_apagada', '', 'id ' . $id);
    ok(['resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_despesa_guardar') {
    exigirModuloApi('orcamento');
    $d = corpo(); $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $id = (int)($d['id'] ?? 0);
    $desc = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 160);
    if ($desc === '') erro('Descreva a despesa.');
    $forn = mb_substr(trim((string)($d['fornecedor'] ?? '')), 0, 120); $forn = $forn === '' ? null : $forn;
    $val  = orcValor($d['valor'] ?? '0');
    $estado = in_array($d['estado'] ?? '', ['previsto','pago'], true) ? $d['estado'] : 'previsto';
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 255); $nota = $nota === '' ? null : $nota;
    // A categoria só vale se for deste casamento — senão fica sem gaveta.
    $catId = null;
    if (!empty($d['categoria_id'])) {
        $c = (int)$d['categoria_id'];
        $q = $conn->prepare("SELECT id FROM {$P}orcamento_categorias WHERE casamento_id=$cid AND id=? LIMIT 1");
        $q->bind_param('i', $c); $q->execute();
        if ($q->get_result()->fetch_row()) $catId = $c;
    }
    if ($id) {
        $st = $conn->prepare("UPDATE {$P}orcamento_despesas SET categoria_id=?, descricao=?, fornecedor=?, valor=?, estado=?, nota=?
                              WHERE casamento_id=$cid AND id=?");
        $st->bind_param('isssssi', $catId, $desc, $forn, $val, $estado, $nota, $id); @$st->execute();
    } else {
        $st = $conn->prepare("INSERT INTO {$P}orcamento_despesas (casamento_id,categoria_id,descricao,fornecedor,valor,estado,nota)
                              VALUES ($cid,?,?,?,?,?,?)");
        $st->bind_param('isssss', $catId, $desc, $forn, $val, $estado, $nota); @$st->execute();
        $id = $conn->insert_id;
    }
    registar($conn, 'orcamento_despesa', $desc, $estado);
    ok(['id' => $id, 'resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_despesa_apagar') {
    exigirModuloApi('orcamento');
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    if (!$id) erro('Despesa inválida.');
    // A fatura anexada vai com ela: o ficheiro sai do disco.
    $q = $conn->prepare("SELECT fatura FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND id=? LIMIT 1");
    $q->bind_param('i', $id); $q->execute();
    $ant = $q->get_result()->fetch_assoc();
    if ($ant && !empty($ant['fatura'])) apagarFaturaFich((string)$ant['fatura']);
    // As parcelas vão atrás dela (CASCADE na base).
    $st = $conn->prepare("DELETE FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND id=?");
    $st->bind_param('i', $id); @$st->execute();
    registar($conn, 'orcamento_despesa_apagada', '', 'id ' . $id);
    ok(['resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_despesa_fatura') {
    exigirModuloApi('orcamento');
    // Anexa (ou troca) a fatura/recibo de uma despesa — foto ou PDF. Guarda-se
    // por casamento, em assets/faturas/<cid>/, e o caminho fica na despesa.
    exigirCorrecao();
    $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $id = (int)($_POST['id'] ?? 0);
    $q = $conn->prepare("SELECT fatura FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND id=? LIMIT 1");
    $q->bind_param('i', $id); $q->execute();
    $desp = $q->get_result()->fetch_assoc();
    if (!$desp) erro('Despesa inválida.');
    if ($p = problemaUpload('ficheiro', 8*1024*1024)) erro($p);
    $f = $_FILES['ficheiro'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $extsOk = ['jpg','jpeg','png','webp','pdf'];
    if (!in_array($ext, $extsOk, true)) erro('A fatura tem de ser uma imagem (JPG, PNG, WEBP) ou um PDF.');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
        $mimesOk = ['image/jpeg','image/png','image/webp','application/pdf'];
        if (!in_array($mt, $mimesOk, true)) erro('O conteúdo do ficheiro não corresponde a uma imagem ou PDF.');
    }
    $dir = __DIR__ . '/assets/faturas/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nomeFich = 'desp' . $id . '-' . time() . '-' . random_int(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nomeFich")) erro('Não foi possível guardar a fatura.');
    if (!empty($desp['fatura'])) apagarFaturaFich((string)$desp['fatura']);   // fora a anterior
    $caminho = 'assets/faturas/' . $cid . '/' . $nomeFich;
    $st = $conn->prepare("UPDATE {$P}orcamento_despesas SET fatura=? WHERE casamento_id=$cid AND id=?");
    $st->bind_param('si', $caminho, $id); @$st->execute();
    registar($conn, 'orcamento_fatura', '', 'despesa ' . $id);
    ok(['id' => $id, 'fatura' => $caminho]);
}

if ($acao === 'orc_despesa_fatura_apagar') {
    exigirModuloApi('orcamento');
    exigirCorrecao();
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    if (!$id) erro('Despesa inválida.');
    $q = $conn->prepare("SELECT fatura FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND id=? LIMIT 1");
    $q->bind_param('i', $id); $q->execute();
    $desp = $q->get_result()->fetch_assoc();
    if (!$desp) erro('Despesa inválida.');
    if (!empty($desp['fatura'])) apagarFaturaFich((string)$desp['fatura']);
    $st = $conn->prepare("UPDATE {$P}orcamento_despesas SET fatura=NULL WHERE casamento_id=$cid AND id=?");
    $st->bind_param('i', $id); @$st->execute();
    ok(['id' => $id]);
}

if ($acao === 'orc_pagamento_guardar') {
    exigirModuloApi('orcamento');
    $d = corpo(); $cid = casamentoAtual();
    if ($cid <= 0) erro('Não há casamento aberto.');
    $id = (int)($d['id'] ?? 0);
    $despId = (int)($d['despesa_id'] ?? 0);
    // A parcela pertence a uma despesa deste casamento — e não a outra qualquer.
    $q = $conn->prepare("SELECT id FROM {$P}orcamento_despesas WHERE casamento_id=$cid AND id=? LIMIT 1");
    $q->bind_param('i', $despId); $q->execute();
    if (!$q->get_result()->fetch_row()) erro('Despesa inválida.');
    $val = orcValor($d['valor'] ?? '0');
    $dataP  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data_prevista'] ?? '')) ? $d['data_prevista'] : null;
    $pagoEm = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['pago_em'] ?? '')) ? $d['pago_em'] : null;
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 160); $nota = $nota === '' ? null : $nota;
    if ($id) {
        $st = $conn->prepare("UPDATE {$P}orcamento_pagamentos SET valor=?, data_prevista=?, pago_em=?, nota=?
                              WHERE casamento_id=$cid AND id=? AND despesa_id=?");
        $st->bind_param('ssssii', $val, $dataP, $pagoEm, $nota, $id, $despId); @$st->execute();
    } else {
        $st = $conn->prepare("INSERT INTO {$P}orcamento_pagamentos (casamento_id,despesa_id,valor,data_prevista,pago_em,nota)
                              VALUES ($cid,?,?,?,?,?)");
        $st->bind_param('issss', $despId, $val, $dataP, $pagoEm, $nota); @$st->execute();
        $id = $conn->insert_id;
    }
    registar($conn, 'orcamento_pagamento', '', 'id ' . $id);
    ok(['id' => $id, 'resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_pagamento_liquidar') {
    exigirModuloApi('orcamento');
    // Dar por paga (ou desmarcar) uma parcela. Sem data explícita, fica hoje.
    $d = corpo(); $cid = casamentoAtual();
    $id = (int)($d['id'] ?? ($_GET['id'] ?? 0));
    if (!$id) erro('Pagamento inválido.');
    $liq = !empty($d['pago']) || (($_GET['pago'] ?? '') === '1');
    if ($liq) {
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['pago_em'] ?? '')) ? $d['pago_em'] : date('Y-m-d');
        $st = $conn->prepare("UPDATE {$P}orcamento_pagamentos SET pago_em=? WHERE casamento_id=$cid AND id=?");
        $st->bind_param('si', $data, $id);
    } else {
        $st = $conn->prepare("UPDATE {$P}orcamento_pagamentos SET pago_em=NULL WHERE casamento_id=$cid AND id=?");
        $st->bind_param('i', $id);
    }
    @$st->execute();
    ok(['resumo' => orcamentoResumo($conn)]);
}

if ($acao === 'orc_pagamento_apagar') {
    exigirModuloApi('orcamento');
    $cid = casamentoAtual();
    $id = (int)($_GET['id'] ?? (corpo()['id'] ?? 0));
    if (!$id) erro('Pagamento inválido.');
    $st = $conn->prepare("DELETE FROM {$P}orcamento_pagamentos WHERE casamento_id=$cid AND id=?");
    $st->bind_param('i', $id); @$st->execute();
    ok(['resumo' => orcamentoResumo($conn)]);
}


// ============================================================
// MODELOS DE CONVITE — os desenhos que a casa oferece a todos
//
// As versões são de cada casamento: o que ESTE casal guardou. Os modelos são o
// outro lado — desenhos prontos, feitos pela casa, para um casal começar de um
// convite bonito em vez de uma folha em branco.
//
// Aplicar um modelo é COPIÁ-LO para as definições do casamento. A partir daí o
// desenho é do casal: mexer no modelo depois disso não lhe toca, e um casal que
// tenha personalizado o seu convite não acorda com ele mudado porque a casa
// mexeu num modelo. É a diferença entre dar uma receita e cozinhar em casa
// alheia.
// ============================================================

if ($acao === 'modelo_lista') {
    // Os casais veem os que lhes são destinados; quem gere a casa vê todos,
    // para poder preparar um modelo antes de o mostrar.
    if (!utilizadorId()) { http_response_code(401); erro('Sessão terminada. Entre de novo.'); }
    $a = ($_GET['ambito'] ?? '') ;
    $onde = isset(ambitosVersao()[$a]) ? "ambito='" . $conn->real_escape_string($a) . "'" : '1=1';
    if (!ehAdminPlataforma()) {
        // Um casal vê um modelo se estiver publicado E se lhe for destinado:
        // ou é de todos, ou é dos escolhidos e ele é um deles.
        $cid = casamentoAtual();
        $onde .= " AND visivel=1 AND (alcance='todos' OR id IN
                     (SELECT modelo_id FROM {$P}modelo_casamentos WHERE casamento_id=" . (int)$cid . "))";

        // E a licença ainda pode apertar mais: um escalão «só ao padrão» vê um
        // modelo apenas — o que a casa designou como peça de origem daquele
        // âmbito. Mostrar-lhe a galeria toda para depois recusar a escolha era
        // vender-lhe com os olhos o que a licença não lhe dá.
        foreach (array_keys(ambitosVersao()) as $amb) {
            if (!podeModulo($amb)) { $onde .= " AND ambito <> '" . $conn->real_escape_string($amb) . "'"; continue; }
            if (podeTodosModelos($amb)) continue;
            $onde .= " AND NOT (ambito='" . $conn->real_escape_string($amb) . "'
                                AND id <> " . (int)padraoDoAmbito($conn, $amb) . ")";
        }
    }
    $r = @$conn->query("SELECT id, nome, descricao, ambito, defs, visivel, alcance, criado_por, criado_em, atualizado_em
                        FROM {$P}modelos WHERE $onde ORDER BY ambito, nome");
    $modelos = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];

    // Qual deles é JÁ o desenho da peça. Sem isto o painel oferecia "pôr em
    // vigor" a um modelo que já estava em vigor — e o casal carregava, nada
    // mudava, e concluía que a função não funcionava. Compara-se o que aplicar
    // produziria com o que a peça mostra: é a mesma conta de modelo_aplicar.
    // Duas perguntas parecidas, e não são a mesma:
    //
    //   $origemCasaId  qual modelo a CASA designou — é o que a página dos
    //                  modelos assinala, e é uma verdade da casa, que o admin
    //                  tem de ver mesmo sem casamento aberto;
    //   $origemId      qual é a peça de origem DESTE casamento — a que lhe
    //                  ficou presa quando pediu a licença. É esta que diz em
    //                  que modelo a peça dele repousa.
    //
    // Confundi-las fazia a página dizer que o casal estava no modelo novo da
    // casa quando ele continua, muito bem, no que comprou.
    $atual = []; $vigorId = []; $origemId = []; $origemCasaId = [];
    $naOrigem = []; $fabricaId = []; $designadaId = [];
    foreach (array_keys(ambitosVersao()) as $amb) {
        $oc = modeloDeOrigem($conn, $amb, 0);
        $origemCasaId[$amb] = $oc ? (int)$oc['id'] : 0;
        $o = modeloDeOrigem($conn, $amb);
        $origemId[$amb] = $o ? (int)$o['id'] : 0;
        $fab = modeloDeFabrica($conn, $amb);
        $fabricaId[$amb] = $fab ? (int)$fab['id'] : 0;
        $designadaId[$amb] = pecaOrigemId($conn, $amb);
    }
    if (casamentoAtual() > 0) {
        foreach (array_keys(ambitosVersao()) as $amb) {
            $atual[$amb] = instantaneoAmbito($conn, $amb);
            $vigorId[$amb] = modeloEmVigorId($conn, $amb);
            $naOrigem[$amb] = naOrigem($conn, $amb);
        }
    }
    foreach ($modelos as &$m) {
        $amb = $m['ambito'];
        $m['em_vigor'] = false;
        $m['mesmo_desenho'] = false;
        // Este modelo é a peça de origem que a CASA designou? (Assinala-o no painel.)
        $m['de_origem'] = isset($origemCasaId[$amb]) && (int)$m['id'] === $origemCasaId[$amb];
        // E é a deste casamento? Pode ser outro: a peça de origem de um casal
        // é a que ele viu ao pedir a licença, não a que a casa tem hoje.
        $m['de_origem_minha'] = isset($origemId[$amb]) && (int)$m['id'] === $origemId[$amb];
        // É o ficheiro de origem de fábrica (a rede de segurança de sempre)?
        $m['de_fabrica'] = isset($fabricaId[$amb]) && (int)$m['id'] === $fabricaId[$amb];
        // Protegido de apagar: o de fábrica, ou o designado como origem.
        $m['protegido'] = $m['de_fabrica']
            || (isset($designadaId[$amb]) && (int)$m['id'] === $designadaId[$amb]);
        if (isset($atual[$amb])) {
            // «Mesmo desenho»: aplicá-lo seria um não-fazer-nada visível. «Em
            // vigor»: é ESTE o modelo que foi aplicado, e o desenho continua o
            // dele. Só um pode estar em vigor; vários podem ter o mesmo desenho.
            //
            // Quando ninguém foi aplicado à mão mas a peça repousa no desenho de
            // origem, é o modelo de origem que está em vigor — senão o painel
            // dizia que nenhum estava, com a peça a mostrar o desenho dele.
            $vig = ($vigorId[$amb] ?? 0) ?: (($naOrigem[$amb] ?? false) ? ($origemId[$amb] ?? 0) : 0);
            $m['mesmo_desenho'] = modeloIgualAPeca($amb, (string)$m['defs'], $atual[$amb]);
            $m['em_vigor'] = $m['mesmo_desenho'] && (int)$m['id'] === $vig;
        }
        // O desenho não vai para o cliente: é grande, e a lista só precisa de
        // saber quem é quem.
        unset($m['defs']);
    }
    unset($m);
    // Ao admin junta-se quais casamentos cada modelo "de escolhidos" alcança,
    // para o painel os mostrar assinalados.
    if (ehAdminPlataforma() && $modelos) {
        $sel = [];
        $rr = @$conn->query("SELECT modelo_id, casamento_id FROM {$P}modelo_casamentos");
        if ($rr) while ($x = $rr->fetch_assoc()) $sel[(int)$x['modelo_id']][] = (int)$x['casamento_id'];
        foreach ($modelos as &$m) $m['casamentos'] = $sel[(int)$m['id']] ?? [];
        unset($m);
    }
    // Ao admin junta-se o catálogo dos modelos de origem, com o que falta —
    // para o painel poder oferecer repô-los se algum foi apagado por lapso.
    $extra = ['modelos' => $modelos];
    if (ehAdminPlataforma()) $extra['catalogo'] = catalogoModelosEmFalta($conn);
    ok($extra);
}

if ($acao === 'modelos_restaurar') {
    // Repõe os modelos que a casa traz de origem — os que faltarem, ou uns
    // quantos escolhidos. Serve de rede quando o admin apaga algum por lapso.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma restaura modelos.');
    exigirCorrecao();
    $d = corpo();
    $alvos = null;
    if (!empty($d['alvos']) && is_array($d['alvos'])) {
        $alvos = [];
        foreach ($d['alvos'] as $a) {
            if (isset($a['ambito'], $a['nome']) && isset(ambitosVersao()[$a['ambito']]))
                $alvos[] = ['ambito' => (string)$a['ambito'], 'nome' => (string)$a['nome']];
        }
    }
    $repor = !empty($d['repor']);
    $res = restaurarModelosDeCasa($conn, $alvos, $repor);
    $feito = array_merge($res['criados'], $res['repostos']);
    registar($conn, 'modelos_restaurados', $feito ? implode(', ', $feito) : '(nada em falta)');
    ok($res + ['catalogo' => catalogoModelosEmFalta($conn)]);
}

if ($acao === 'modelo_visibilidade') {
    // Quem vê este modelo: todos os casais, ou só os escolhidos. É do admin.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma gere a visibilidade dos modelos.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $alcance = ($d['alcance'] ?? 'todos') === 'selecionados' ? 'selecionados' : 'todos';
    // Os ids vêm do cliente: só entram os que são mesmo casamentos, para não
    // encher a tabela de junção com números inventados.
    $ids = [];
    if ($alcance === 'selecionados') {
        foreach ((array)($d['casamentos'] ?? []) as $c) {
            $c = (int)$c;
            if ($c > 0) $ids[$c] = true;
        }
        if ($ids) {
            $lista = implode(',', array_map('intval', array_keys($ids)));
            $rr = @$conn->query("SELECT id FROM {$P}casamentos WHERE id IN ($lista)");
            $validos = [];
            if ($rr) while ($x = $rr->fetch_assoc()) $validos[(int)$x['id']] = true;
            $ids = $validos;
        }
        // Escolhidos sem escolha nenhuma não faz sentido: seria um modelo que
        // ninguém vê, o que já é o "rascunho". Guarda-se como 'todos' e o painel
        // avisa. (Aqui só se normaliza: sem ids, volta a 'todos'.)
        if (!$ids) $alcance = 'todos';
    }

    $st = $conn->prepare("UPDATE {$P}modelos SET alcance=? WHERE id=?");
    $st->bind_param('si', $alcance, $id); $st->execute();
    $conn->query("DELETE FROM {$P}modelo_casamentos WHERE modelo_id=" . $id);
    if ($alcance === 'selecionados' && $ids) {
        $st = $conn->prepare("INSERT INTO {$P}modelo_casamentos (modelo_id, casamento_id) VALUES (?, ?)");
        foreach (array_keys($ids) as $c) { $st->bind_param('ii', $id, $c); @$st->execute(); }
    }
    registar($conn, 'modelo_visibilidade', (string)$m['nome'],
             $alcance === 'todos' ? 'todos os casamentos' : count($ids) . ' casamento(s)');
    ok(['id' => $id, 'alcance' => $alcance, 'casamentos' => array_keys($ids)]);
}

if ($acao === 'modelo_exemplo') {
    // Os dados com que um modelo NOVO nasce. Só de leitura.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê os dados de exemplo.');
    // A galeria vai com o resto: o painel precisa dela para a janela de escolha.
    ok(['exemplo' => exemploModelo($conn), 'fabrica' => exemploDeFabrica(),
        'chaves' => chavesExemplo(), 'galeria' => galeriaCompleta($conn),
        'categorias' => categoriasGaleria(), 'ocultas' => count(galeriaOcultas($conn))]);
}

if ($acao === 'modelo_exemplo_guardar') {
    // O admin muda o casal e o evento de exemplo. Vale para os modelos que se
    // criarem daqui para a frente: os que já existem ficam como estão.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os dados de exemplo.');
    $d = corpo();
    $fabrica = exemploDeFabrica();
    $mudados = []; $invalidas = [];
    foreach (chavesExemplo() as $k) {
        if (!array_key_exists($k, $d)) continue;
        $v = trim((string)$d[$k]);
        // Campo deixado em branco onde branco não é uma resposta (um modelo sem
        // nome de noiva não é um modelo): volta ao de fábrica, e não a um erro.
        if ($v === '' && !podeSerVazio($k)) $v = (string)($fabrica[$k] ?? '');
        if (str_starts_with($k, 'media.')) {
            // Um caminho de ficheiro nosso, e um que exista: um exemplo com uma
            // imagem partida é pior do que um exemplo com a de fábrica.
            if ($v !== '' && (!preg_match('#^assets/convite/[\w./-]+$#', $v)
                              || str_contains($v, '..') || !is_file(__DIR__ . '/' . $v))) {
                $invalidas[] = $k; continue;
            }
        } else {
            // A mesma validação de sempre — é ela que sabe o que cada chave
            // aceita, e uma cópia aqui ficava para trás à primeira mudança.
            $limpo = validarDefinicao($k, $v);
            if ($limpo === null) { $invalidas[] = $k; continue; }
            $v = $limpo;
        }
        $mudados[$k] = $v;
    }
    if ($invalidas) erro('Valor inválido em: ' . implode(', ', $invalidas));
    if (!$mudados) erro('Nada para guardar.');

    // Os dados de exemplo são do sistema, não de um casamento: vivem na linha 0.
    // Igual ao de fábrica é não ter escolha nenhuma — a linha sai, como em
    // qualquer definição que volte ao valor de origem.
    $ins = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (0,?,?)
                           ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $del = $conn->prepare("DELETE FROM {$P}definicoes WHERE casamento_id=0 AND chave=?");
    foreach ($mudados as $k => $v) {
        $chave = 'modelo.exemplo.' . $k;
        if ($v === ($fabrica[$k] ?? null)) { $del->bind_param('s', $chave); $del->execute(); }
        else { $ins->bind_param('ss', $chave, $v); $ins->execute(); }
    }
    registar($conn, 'modelo_exemplo', 'dados de exemplo dos modelos', implode(', ', array_keys($mudados)));
    ok(['exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_exemplo_upload') {
    // Uma imagem para o convite de exemplo. Vai para uma pasta só dela: não se
    // mistura com as fotografias de um casamento, que são de alguém.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os dados de exemplo.');
    // 'chave' diz em que secção a pôr em vigor, e pode vir vazia: da janela de
    // gestão envia-se para a galeria sem a usar já em lado nenhum.
    $chave = $_POST['chave'] ?? '';
    $ehMusica = $chave === 'media.musica';
    $categoria = $_POST['categoria'] ?? ($chave ? categoriaDaChave($chave) : 'sem');
    if (!isset(categoriasGaleria()[$categoria])) $categoria = 'sem';
    // O ficheiro pode vir de uma vez ($_FILES) ou montado por pedaços
    // (chunk_token) — a música e as fotografias de exemplo, não comprimidas,
    // podem passar o limite de envio do servidor.
    $max = $ehMusica ? 8*1024*1024 : 5*1024*1024;
    $src = origemUpload('ficheiro', $max);
    if ($chave !== '' && !$ehMusica
        && !in_array($chave, ['media.hero','media.historia','media.interludio','media.acesso'], true)) {
        erro('Campo de ficheiro inválido.');
    }
    $ext = strtolower(pathinfo($src['nome'], PATHINFO_EXTENSION));
    $extsOk = $ehMusica ? ['m4a','mp3'] : ['jpg','jpeg','png','webp','svg'];
    if (!in_array($ext, $extsOk, true)) erro('Formato não suportado (' . implode('/', $extsOk) . ').');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $src['tmp']); finfo_close($fi);
        $mimesOk = $ehMusica ? ['audio/mp4','audio/x-m4a','audio/mpeg','video/mp4','audio/mp3']
                             : ['image/jpeg','image/png','image/webp','image/svg+xml','text/plain','text/xml'];
        if (!in_array($mt, $mimesOk, true)) erro('O conteúdo do ficheiro não corresponde ao formato.');
        if ($ext === 'svg' && !in_array($mt, ['image/svg+xml','text/plain','text/xml'], true)) erro('SVG inválido.');
    }
    $dir = __DIR__ . '/assets/convite/exemplo';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // O prefixo do nome é a categoria: é dele que ela se lê ao listar a galeria.
    $nomeFich = ($ehMusica ? 'musica' : $categoria) . '-' . time() . '-' . random_int(100, 999)
              . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!moverUpload($src, "$dir/$nomeFich")) erro('Não foi possível guardar o ficheiro.');
    // A anterior NÃO se apaga: as enviadas juntam-se à galeria desta secção, e
    // quem prepara vários modelos quer poder voltar a uma que já tinha enviado.
    // Para a tirar de vez há a ação modelo_exemplo_apagar.
    $caminho = 'assets/convite/exemplo/' . $nomeFich;
    if ($chave !== '') {
        $chaveDef = 'modelo.exemplo.' . $chave;
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (0,?,?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('ss', $chaveDef, $caminho); $st->execute();
    }
    registar($conn, 'modelo_exemplo', 'imagem para a galeria', $categoria);
    ok(['path' => $caminho, 'galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_exemplo_apagar') {
    // Tirar uma fotografia da galeria. As que o admin enviou apagam-se mesmo;
    // as da casa só se ESCONDEM — o ficheiro vem com a instalação e um deploy
    // trá-lo-ia de volta, por isso o que se guarda é a decisão de não a querer.
    // Assim também se podem repor, que é o que um apagar irreversível não dava.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $src = (string)(corpo()['src'] ?? '');
    if (str_contains($src, '..')) erro('Caminho inválido.');
    $daCasa = !str_starts_with($src, GALERIA_ENVIADAS);
    if ($daCasa) {
        $existe = false;
        foreach (galeriaExemplo() as $it) if ($it['ficheiro'] === $src) $existe = true;
        if (!$existe) erro('Essa fotografia não é da galeria.');
    } elseif (!is_file(__DIR__ . '/' . $src)) {
        erro('Essa imagem já não está cá.');
    }
    // Se estava em vigor nalguma secção, essa volta ao valor de fábrica em vez
    // de ficar a apontar para uma fotografia que já não se vê.
    foreach (exemploModelo($conn) as $k => $v) {
        if ($v !== $src) continue;
        $chaveDef = 'modelo.exemplo.' . $k;
        $st = $conn->prepare("DELETE FROM {$P}definicoes WHERE casamento_id=0 AND chave=?");
        $st->bind_param('s', $chaveDef); $st->execute();
    }
    if ($daCasa) {
        $oc = galeriaOcultas($conn); $oc[] = $src; guardarGaleriaOcultas($conn, $oc);
    } else {
        @unlink(__DIR__ . '/' . $src);
    }
    registar($conn, 'modelo_exemplo', $daCasa ? 'fotografia da casa escondida' : 'fotografia apagada',
             basename($src));
    ok(['galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn),
        'ocultas' => count(galeriaOcultas($conn))]);
}

if ($acao === 'modelo_exemplo_repor') {
    // Trazer de volta as fotografias da casa que foram escondidas.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $quantas = count(galeriaOcultas($conn));
    if (!$quantas) erro('Não há nenhuma escondida.');
    guardarGaleriaOcultas($conn, []);
    registar($conn, 'modelo_exemplo', 'galeria da casa reposta', $quantas . ' fotografia(s)');
    ok(['galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn), 'ocultas' => 0]);
}

if ($acao === 'modelo_exemplo_categoria') {
    // Mudar a categoria de uma imagem enviada. A categoria vive no prefixo do
    // nome, por isso mudá-la é mudar o ficheiro de nome — e quem estivesse a
    // apontar para o nome antigo passa a apontar para o novo.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $d = corpo();
    $src = (string)($d['src'] ?? '');
    $cat = (string)($d['categoria'] ?? '');
    if (!isset(categoriasGaleria()[$cat])) erro('Categoria inválida.');
    if (!str_starts_with($src, GALERIA_ENVIADAS) || str_contains($src, '..')) {
        erro('As imagens da casa já vêm arrumadas — só as suas se mudam de sítio.');
    }
    $abs = __DIR__ . '/' . $src;
    if (!is_file($abs)) erro('Essa imagem já não está cá.');

    $resto = substr(basename($src), strpos(basename($src), '-') + 1);
    $novoNome = $cat . '-' . $resto;
    $novoSrc = GALERIA_ENVIADAS . $novoNome;
    if ($novoSrc !== $src) {
        if (!@rename($abs, __DIR__ . '/' . $novoSrc)) erro('Não foi possível mudar a categoria.');
        $st = $conn->prepare("UPDATE {$P}definicoes SET valor=? WHERE casamento_id=0 AND valor=?");
        $st->bind_param('ss', $novoSrc, $src); $st->execute();
    }
    registar($conn, 'modelo_exemplo', 'categoria da imagem', $cat);
    ok(['src' => $novoSrc, 'galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_criar') {
    // Nasce do que está em vigor no casamento aberto: preparar um modelo é
    // desenhar o convite como se fosse para alguém, e depois guardá-lo aqui.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma faz modelos.');
    $d = corpo();
    $ambito = isset(ambitosVersao()[$d['ambito'] ?? '']) ? $d['ambito'] : 'digital';
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    if ($nome === '') erro('Dê um nome ao modelo.');
    $descricao = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 400);

    // Ou os valores que vieram no pedido (importação), ou o retrato do que o
    // casamento aberto mostra agora.
    if (is_array($d['defs'] ?? null)) {
        $permitidas = array_flip(chavesModelo($ambito));
        $defs = [];
        foreach ($d['defs'] as $k => $v) if (isset($permitidas[$k]) && is_string($v)) $defs[$k] = $v;
    } elseif (casamentoAtual() > 0 && empty($d['do_zero'])) {
        // Do que o casamento aberto mostra agora — é assim que se guarda um
        // convite que se acabou de desenhar para alguém. A IDENTIDADE, essa,
        // fica de exemplo: um modelo da casa não é o retrato de um casal.
        $defs = instantaneoModelo($conn, $ambito);
    } else {
        // Sem casamento aberto, o modelo nasce do desenho de origem e desenha-se
        // no editor a seguir. Obrigar a abrir a casa de um casal para fazer um
        // modelo da CASA era pedir emprestado o que não é preciso.
        //
        // Mas o desenho de origem é o do PRIMEIRO casal: sem esta troca, um
        // modelo feito do zero nascia com o nome e as fotografias dele — o
        // mesmo problema, pela porta do lado.
        $defs = comIdentidadeDeExemplo($conn, $ambito, padraoAmbito($ambito));
    }
    if (!$defs) erro('Não há nada para guardar neste modelo.');

    $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                          VALUES (?,?,?,?,?,?)");
    $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
    $vis = empty($d['visivel']) ? 0 : 1;
    $quem = utilizadorAtual() ?? '';
    $st->bind_param('ssssis', $nome, $descricao, $ambito, $j, $vis, $quem);
    if (!$st->execute()) erro('Não foi possível guardar o modelo.');
    // O número do modelo lê-se JÁ: registar() escreve uma linha no histórico, e
    // a partir daí insert_id é o dessa linha — devolvia-se um número que não é
    // de modelo nenhum, e o modelo acabado de criar ficava inalcançável.
    $novoId = $conn->insert_id;
    registar($conn, 'modelo_criado', $nome, $ambito . ' · ' . count($defs) . ' definição(ões)');
    ok(['id' => $novoId, 'nome' => $nome, 'ambito' => $ambito, 'definicoes' => count($defs)]);
}

if ($acao === 'modelo_editar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita modelos.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $nome = mb_substr(trim((string)($d['nome'] ?? $m['nome'])), 0, 120);
    if ($nome === '') erro('Dê um nome ao modelo.');
    $descricao = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 400);
    $vis = empty($d['visivel']) ? 0 : 1;

    // "Recapturar" é o que torna isto trabalhável: abre-se um casamento, mexe-se
    // no convite até ficar bem, e traz-se o resultado para o modelo.
    if (!empty($d['recapturar'])) {
        if (casamentoAtual() <= 0) erro('Abra um casamento para trazer de lá o desenho.');
        $defs = instantaneoModelo($conn, $m['ambito']);   // sem a identidade do casal
        $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("UPDATE {$P}modelos SET nome=?, descricao=?, visivel=?, defs=?,
                                     atualizado_em=NOW() WHERE id=?");
        $st->bind_param('ssisi', $nome, $descricao, $vis, $j, $id);
    } else {
        $st = $conn->prepare("UPDATE {$P}modelos SET nome=?, descricao=?, visivel=?,
                                     atualizado_em=NOW() WHERE id=?");
        $st->bind_param('ssii', $nome, $descricao, $vis, $id);
    }
    if (!$st->execute()) erro('Não foi possível guardar.');
    registar($conn, 'modelo_editado', $nome, empty($d['recapturar']) ? '' : 'desenho recapturado');
    ok(['id' => $id, 'nome' => $nome]);
}

if ($acao === 'modelo_defs') {
    // O desenho de um modelo, vindo do editor. É o que faz um modelo poder ser
    // trabalhado sem se abrir a casa de um casal.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma desenha modelos.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $d = corpo();
    // Um modelo do cartão pode ainda guardar a logística (cerimónias e receção)
    // — é o que o admin desenha aqui. Não se aplica ao casal (ver modelo_aplicar).
    $permitidas = array_flip(chavesModelo($m['ambito']));
    $padrao = defsPadrao();
    $defs = []; $invalidas = [];
    foreach ((array)($d['defs'] ?? []) as $k => $v) {
        if (!isset($permitidas[$k]) || !is_string($v)) continue;
        $ok = validarDefinicao($k, $v);
        if ($ok === null) { $invalidas[] = $k; continue; }
        // Igual ao original não se guarda: o modelo fica só com o que o desenho
        // mudou, e um valor de origem que mude no futuro acompanha-o.
        if ($ok === (string)($padrao[$k] ?? '')) continue;
        $defs[$k] = $ok;
    }
    $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
    $st = $conn->prepare("UPDATE {$P}modelos SET defs=?, atualizado_em=NOW() WHERE id=?");
    $st->bind_param('si', $j, $id);
    if (!$st->execute()) erro('Não foi possível guardar o modelo.');
    registar($conn, 'modelo_desenhado', (string)$m['nome'], count($defs) . ' definição(ões)');
    ok(['gravadas' => count($defs), 'invalidas' => $invalidas]);
}

if ($acao === 'modelo_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga modelos.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');
    // A peça de origem não se apaga: nem o ficheiro de origem de fábrica, nem o
    // modelo que o admin tenha designado como origem enquanto o estiver.
    $razao = razaoProtecaoOrigem($conn, $id, (string)$m['ambito']);
    if ($razao !== null) erro('«' . $m['nome'] . '» ' . $razao . '.');
    $st = $conn->prepare("DELETE FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar.');
    // Apagar um modelo não desfaz nada em casamento nenhum: quem o aplicou
    // ficou com uma cópia, e é dele.
    registar($conn, 'modelo_apagado', (string)$m['nome']);
    ok(['id' => $id, 'nome' => $m['nome']]);
}

if ($acao === 'modelo_pecaorigem') {
    // O admin designa qual modelo da casa É a «peça de origem» de um âmbito: o
    // ponto de regresso, e o nome por que a peça se dá a conhecer quando não
    // tem versão nem outro modelo aplicado. É uma escolha da casa (global), não
    // de um casamento. id=0 devolve a designação ao automático (o de fábrica,
    // achado pelo desenho).
    //
    // Vale para quem VIER A SEGUIR. Os casamentos que já existem ficam com o
    // modelo que lhes prendemos quando pediram a licença — foi esse que eles
    // viram nas capturas da montra, e é esse que compraram.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma define a peça de origem.');
    exigirCorrecao();
    $ambito = ambitoPedido();
    $id = (int)($_GET['id'] ?? 0);
    $nome = null;
    if ($id > 0) {
        $st = $conn->prepare("SELECT nome, ambito, visivel, alcance FROM {$P}modelos WHERE id=?");
        $st->bind_param('i', $id); $st->execute();
        $m = $st->get_result()->fetch_assoc();
        if (!$m) erro('Modelo não encontrado.');
        if ($m['ambito'] !== $ambito) erro('Esse modelo é de outra peça.');
        // A peça de origem serve todos os casais: tem de estar publicada e ser
        // de todos. Um modelo só para alguns não pode ser o ponto de regresso.
        if ((int)$m['visivel'] !== 1 || $m['alcance'] !== 'todos')
            erro('A peça de origem tem de ser um modelo publicado e disponível a todos.');
        $nome = (string)$m['nome'];
    }
    definirPecaOrigem($conn, $ambito, $id);
    // Quantos casamentos ficam com o que tinham: é o que o admin precisa de
    // saber para não julgar que acabou de mudar o convite a toda a gente.
    global $P;
    $chave = 'modelo.pecaorigem.' . $ambito;
    $stp = @$conn->prepare("SELECT COUNT(*) FROM {$P}definicoes
                            WHERE casamento_id > 0 AND chave=?");
    $presos = 0;
    if ($stp) { $stp->bind_param('s', $chave); $stp->execute();
                $presos = (int)$stp->get_result()->fetch_row()[0]; }
    registar($conn, 'peca_origem_definida', $nome ?? '(automático)',
             $ambito . ($presos ? " · $presos casamento(s) mantêm o que tinham" : ''));
    ok(['ambito' => $ambito, 'id' => $id, 'nome' => $nome ?? nomeDaOrigem($conn, $ambito, 0),
        'presos' => $presos]);
}

if ($acao === 'modelo_aplicar') {
    // Do lado do casal: traz o desenho da casa para o seu convite.
    exigirAdminApi();
    exigirCorrecao();
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito, defs, visivel, alcance FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');
    // Um casal só aplica o que vê: publicado, e destinado a ele (de todos, ou
    // dos escolhidos com ele entre eles). O admin aplica qualquer um.
    if (!ehAdminPlataforma()) {
        $podeVer = (int)$m['visivel'] === 1;
        if ($podeVer && $m['alcance'] === 'selecionados') {
            $st = $conn->prepare("SELECT 1 FROM {$P}modelo_casamentos
                                  WHERE modelo_id=? AND casamento_id=? LIMIT 1");
            $cid = casamentoAtual();
            $st->bind_param('ii', $id, $cid); $st->execute();
            $podeVer = (bool)$st->get_result()->fetch_row();
        }
        if (!$podeVer) erro('Esse modelo não está disponível.');

        // E o que a licença deixa: sem o módulo não se aplica nada, e num
        // escalão «só ao padrão» aplica-se o padrão — que é, afinal, o que ele
        // já tem. Vale como reposição, e é para isso que continua a servir.
        $amb = (string)$m['ambito'];
        if (!podeModulo($amb)) {
            http_response_code(403);
            erro('A licença deste casamento não inclui o '
               . (ambitosVersao()[$amb]['rotulo'] ?? $amb) . '.');
        }
        if (!podeTodosModelos($amb) && $id !== padraoDoAmbito($conn, $amb)) {
            http_response_code(403);
            erro('A sua licença dá-lhe o modelo padrão desta peça. Para escolher entre '
               . 'todos os modelos, reforce a licença na página da Licença.');
        }
    }
    $j = json_decode((string)$m['defs'], true);
    if (!is_array($j)) erro('Esse modelo está ilegível.');

    // Só as chaves do próprio âmbito: um modelo do cartão não mexe no convite
    // digital, nem o contrário. E de propósito chavesDoAmbito, não chavesModelo:
    // a logística que o admin meteu no modelo é só para o desenhar; aplicar um
    // modelo do cartão nunca reescreve as cerimónias que o casal já marcou.
    // Só o DESENHO: um modelo não escreve o nome dos noivos, os dados do evento
    // nem as fotografias de quem o aplica. Guarda-os (a prova precisa deles),
    // mas guarda-os genéricos e nunca os impõe — a festa é de cada casal.
    $permitidas = array_flip(chavesDesenho($m['ambito']));
    $doModelo = [];
    foreach ($j as $k => $v) if (isset($permitidas[$k]) && is_string($v)) $doModelo[$k] = $v;
    // Aplicar um modelo é FICAR com ele, não misturá-lo com o que estava:
    // parte-se do desenho de origem do âmbito e põe-se o modelo por cima. O que
    // o casal tinha à mão e o modelo não traz volta à origem, em vez de ficar
    // pelo meio. Um modelo vazio — o de origem da casa — devolve a peça à
    // origem, que é o que se espera de "aplicar o modelo da casa".
    $defs = array_merge(padraoDesenho($m['ambito']), $doModelo);
    // As fotografias não são desenho — são de cada casal, e por isso um modelo
    // não as impõe. Mas um casal que ainda não pôs foto nenhuma fica melhor
    // servido com as do modelo (que o admin escolheu a condizer) do que com as
    // de origem. Regra, secção a secção: se a foto do casal ainda é a de origem
    // (não lhe mexeu), empresta-se a do modelo e o seu enquadramento; uma foto
    // que o casal já trocou fica intocada — nunca se apaga trabalho seu.
    if ($m['ambito'] === 'digital') {
        $padrao = defsPadrao();
        $atuais = defsAtuais($conn);
        foreach (fotosDeModelo() as $kMedia => $kFoto) {
            $doCasal  = (string)($atuais[$kMedia] ?? '');
            $deOrigem = (string)($padrao[$kMedia] ?? '');
            $noModelo = (array_key_exists($kMedia, $j) && is_string($j[$kMedia])) ? $j[$kMedia] : '';
            if ($doCasal === $deOrigem && $noModelo !== '') {
                $defs[$kMedia] = $noModelo;
                if ($kFoto !== null && array_key_exists($kFoto, $j) && is_string($j[$kFoto]))
                    $defs[$kFoto] = $j[$kFoto];
            }
        }
    }
    // O que a peça mostrava ANTES, para se poder dizer com verdade se mudou.
    // Sem isto, aplicar um modelo que já era o desenho em vigor recarregava a
    // página sem nada mudar — e quem o fez concluía, com razão, que não tinha
    // funcionado. 'gravadas' não serve: conta escritas, não diferenças.
    $antesDefs = instantaneoAmbito($conn, $m['ambito']);
    $r = guardarDefinicoes($conn, $defs);
    $depoisDefs = instantaneoAmbito($conn, $m['ambito']);
    $r['mudou'] = $antesDefs != $depoisDefs;
    // Deixa de haver versão em vigor: o que a peça mostra agora veio de fora.
    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $m['ambito']); $st->execute();
    // E fica registado QUE modelo está em vigor — para a lista marcar um só, e
    // não todos os que por acaso tenham o mesmo desenho.
    marcarModeloEmVigor($conn, $m['ambito'], $id);
    registar($conn, 'modelo_aplicado', (string)$m['nome'], $r['gravadas'] . ' definição(ões)');
    ok($r + ['nome' => $m['nome'], 'ambito' => $m['ambito']]);
}

if ($acao === 'modelos_exportar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva os modelos.');
    $r = @$conn->query("SELECT nome, descricao, ambito, defs, visivel FROM {$P}modelos ORDER BY ambito, nome");
    $lista = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($lista as &$m) { $m['defs'] = json_decode($m['defs'], true) ?: []; }
    unset($m);
    // Os modelos por âmbito, para o resumo do cabeçalho dizer de relance quantos
    // digitais e quantos impressos vão no ficheiro.
    $porAmbito = [];
    foreach ($lista as $m) { $a = $m['ambito'] ?? '?'; $porAmbito[$a] = ($porAmbito[$a] ?? 0) + 1; }
    $saida = ['formato' => 'casamento-web/modelos/1', 'esquema' => ESQUEMA_VERSAO,
              'gerado_em' => date('c'), 'gerado_por' => utilizadorAtual() ?? '',
              'resumo' => ['modelos' => count($lista)] + $porAmbito,
              'modelos' => $lista];
    registar($conn, 'modelos_exportados', count($lista) . ' modelo(s)');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=modelos-' . date('Y-m-d') . '.json');
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($acao === 'modelos_importar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma traz modelos.');
    $d = corpo();
    $f = is_array($d['ficheiro'] ?? null) ? $d['ficheiro'] : null;
    if (!$f || ($f['formato'] ?? '') !== 'casamento-web/modelos/1') {
        erro('Este ficheiro não é uma exportação de modelos deste sistema.');
    }
    $entrou = 0; $saltou = 0;
    foreach ((array)($f['modelos'] ?? []) as $m) {
        if (!is_array($m)) { $saltou++; continue; }
        $nome = mb_substr(trim((string)($m['nome'] ?? '')), 0, 120);
        $ambito = isset(ambitosVersao()[$m['ambito'] ?? '']) ? $m['ambito'] : 'digital';
        $permitidas = array_flip(chavesModelo($ambito));
        $defs = [];
        foreach ((array)($m['defs'] ?? []) as $k => $v) {
            if (isset($permitidas[$k]) && is_string($v)) $defs[$k] = $v;
        }
        if ($nome === '' || !$defs) { $saltou++; continue; }
        $descricao = mb_substr(trim((string)($m['descricao'] ?? '')), 0, 400);
        $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
        $vis = empty($m['visivel']) ? 0 : 1;
        $quem = utilizadorAtual() ?? '';
        $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                              VALUES (?,?,?,?,?,?)");
        $st->bind_param('ssssis', $nome, $descricao, $ambito, $j, $vis, $quem);
        if (@$st->execute()) $entrou++; else $saltou++;
    }
    if (!$entrou) erro('O ficheiro não trouxe modelo nenhum aproveitável.');
    registar($conn, 'modelos_importados', $entrou . ' modelo(s)');
    ok(['entraram' => $entrou, 'saltados' => $saltou]);
}

if ($acao === 'sistema_importar') {
    // A importação da casa, por âmbitos escolhidos (casamentos, modelos, contas),
    // a partir de um ficheiro da exportação da casa. Os casamentos entram SEMPRE
    // como novos — não se mistura nem se substitui nada do que já cá está. As
    // contas que já existem (mesmo email) saltam-se: um email é de uma só conta.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma importa a casa.');
    $d = corpo();
    $f = is_array($d['ficheiro'] ?? null) ? $d['ficheiro'] : null;
    if (!$f || ($f['formato'] ?? '') !== 'casamento-web/1') {
        erro('Este ficheiro não é uma exportação deste sistema.');
    }
    $inc = listaCorpo($d['inc'] ?? '');
    if (!$inc) erro('Escolha o que quer importar.');
    $res = ['casamentos' => 0, 'modelos' => 0, 'contas' => 0, 'contas_saltadas' => 0];
    $criados = [];

    if (in_array('casamentos', $inc, true)) {
        foreach ((array)($f['casamentos'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $fi = (array)($r['ficha'] ?? []);
            $nome  = mb_substr(trim((string)($fi['nome'] ?? 'Casamento importado')), 0, 160) ?: 'Casamento importado';
            $noiva = mb_substr((string)($fi['noiva'] ?? ''), 0, 80);
            $noivo = mb_substr((string)($fi['noivo'] ?? ''), 0, 80);
            $data  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($fi['data_evento'] ?? '')) ? $fi['data_evento'] : null;
            $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado) VALUES (?,?,?,?, 'ativo')");
            $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
            if (!$st->execute()) continue;
            $novo = $conn->insert_id;
            reporCasamento($conn, $novo, $r, false);
            $res['casamentos']++; $criados[] = $nome;
        }
    }
    if (in_array('modelos', $inc, true)) {
        foreach ((array)($f['modelos'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $nome = mb_substr(trim((string)($m['nome'] ?? '')), 0, 120);
            $ambito = isset(ambitosVersao()[$m['ambito'] ?? '']) ? $m['ambito'] : 'digital';
            $permitidas = array_flip(chavesModelo($ambito));
            $defs = [];
            foreach ((array)($m['defs'] ?? []) as $k => $v) if (isset($permitidas[$k]) && is_string($v)) $defs[$k] = $v;
            if ($nome === '' || !$defs) continue;
            $descricao = mb_substr(trim((string)($m['descricao'] ?? '')), 0, 400);
            $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
            $vis = empty($m['visivel']) ? 0 : 1;
            $quem = utilizadorAtual() ?? '';
            $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por) VALUES (?,?,?,?,?,?)");
            $st->bind_param('ssssis', $nome, $descricao, $ambito, $j, $vis, $quem);
            if (@$st->execute()) $res['modelos']++;
        }
    }
    // As contas escolhidas — de casamento, administrativas, ou ambas. O token
    // antigo «contas» vale pelas duas.
    $impCC = in_array('contas_casamento', $inc, true) || in_array('contas', $inc, true);
    $impCA = in_array('contas_admin', $inc, true) || in_array('contas', $inc, true);
    if ($impCC || $impCA) {
        foreach ((array)($f['contas'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $plat = in_array($c['papel_plataforma'] ?? null, ['admin', 'suporte'], true) ? $c['papel_plataforma'] : null;
            // Só a família que se pediu: uma conta de admin não entra num
            // «importar só contas de casamento», nem o contrário.
            if ($plat !== null && !$impCA) continue;
            if ($plat === null && !$impCC) continue;
            $email = mb_strtolower(trim((string)($c['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $q = $conn->prepare("SELECT id FROM {$P}utilizadores WHERE email=? LIMIT 1");
            $q->bind_param('s', $email); $q->execute();
            if ($q->get_result()->fetch_row()) { $res['contas_saltadas']++; continue; }
            $nome = mb_substr((string)($c['nome'] ?? ''), 0, 120);
            $estado = in_array($c['estado'] ?? '', ['ativo', 'pendente', 'suspenso', 'inativo'], true) ? $c['estado'] : 'ativo';
            $hash = (isset($c['senha_hash']) && is_string($c['senha_hash']) && $c['senha_hash'] !== '')
                  ? $c['senha_hash'] : password_hash(senhaTemporaria(), PASSWORD_DEFAULT);
            $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, papel_plataforma, estado) VALUES (?,?,?,?,?)");
            $st->bind_param('sssss', $email, $nome, $hash, $plat, $estado);
            if (@$st->execute()) $res['contas']++;
        }
    }
    registar($conn, 'sistema_importado', implode('+', $inc), json_encode($res));
    ok(['inc' => array_values($inc), 'res' => $res, 'criados' => $criados]);
}

if ($acao === 'sistema_repor_fabrica') {
    // Gestão de dados, do lado da casa: é APAGAR. Apaga os modelos personalizados
    // (ficam os de origem), apaga as contas que não são de plataforma (nunca a
    // própria), e mexe nos casamentos escolhidos de uma de duas maneiras — esvaziá-
    // los (fica o casamento, sem os dados) ou apagá-los por inteiro. Não se desfaz.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga os dados da casa.');
    exigirCorrecao();
    $d = corpo();
    $alvos = listaCorpo($d['alvos'] ?? '');
    // 'tudo' = a casa inteira: os modelos personalizados, as contas (a própria
    // nunca) e TODOS os casamentos, apagados por inteiro. É a limpeza completa,
    // ao lado da escolhida — sem obrigar a assinalar tudo à mão e a arriscar
    // deixar alguma coisa para trás.
    $tudo = !empty($d['tudo']);
    if ($tudo) {
        $alvos = ['modelos', 'contas_casamento', 'contas_admin', 'casamentos'];
        $todos = [];
        $r = @$conn->query("SELECT id FROM {$P}casamentos ORDER BY id");
        if ($r) while ($x = $r->fetch_row()) $todos[] = (int)$x[0];
        $d['casamentos'] = $todos;
        $d['casamentos_modo'] = 'apagar';
        // Sem casamento nenhum, não há o que apagar por casamentos — mas as
        // contas e os modelos ainda podem existir, e vão à mesma.
        if (!$todos) $alvos = ['modelos', 'contas_casamento', 'contas_admin'];
    }
    $res = [];
    if (in_array('modelos', $alvos, true)) {
        // Apaga os modelos que o admin criou; ficam os de origem da casa
        // (criado_por='sistema'). Com eles, os laços de «visível só a estes».
        $ids = [];
        $r = @$conn->query("SELECT id FROM {$P}modelos WHERE criado_por <> 'sistema'");
        if ($r) while ($x = $r->fetch_row()) $ids[] = (int)$x[0];
        foreach ($ids as $id) {
            $conn->query("DELETE FROM {$P}modelo_casamentos WHERE modelo_id=$id");
            $conn->query("DELETE FROM {$P}modelos WHERE id=$id");
        }
        $res['modelos'] = count($ids);
    }
    $eu = utilizadorId();
    $apagarContas = function (string $cond) use ($conn, $P, $eu): int {
        $ids = [];
        $r = @$conn->query("SELECT id FROM {$P}utilizadores WHERE ($cond) AND id <> " . (int)$eu);
        if ($r) while ($x = $r->fetch_row()) $ids[] = (int)$x[0];
        foreach ($ids as $id) {
            $conn->query("DELETE FROM {$P}acessos WHERE utilizador_id=$id");
            $conn->query("DELETE FROM {$P}utilizadores WHERE id=$id");
        }
        return count($ids);
    };
    // «contas» (antigo) = as de casamento. As duas famílias, à parte:
    if (in_array('contas', $alvos, true) || in_array('contas_casamento', $alvos, true)) {
        // Contas de casamento (noivos/porteiro). A própria nunca se apaga.
        $res['contas_casamento'] = $apagarContas('papel_plataforma IS NULL');
    }
    if (in_array('contas_admin', $alvos, true)) {
        // Contas administrativas (admin/suporte) — exceto a sua, para não se
        // trancar fora.
        $res['contas_admin'] = $apagarContas('papel_plataforma IS NOT NULL');
    }
    if (in_array('casamentos', $alvos, true)) {
        $ids = array_values(array_filter(array_map('intval', listaCorpo($d['casamentos'] ?? ''))));
        if (!$ids) erro('Escolha os casamentos.');
        // Duas maneiras: «esvaziar» deixa a ficha e as contas do casamento e tira-
        // lhe os dados; «apagar» leva o casamento inteiro. O primeiro é o de sempre.
        $modo = (($d['casamentos_modo'] ?? 'esvaziar') === 'apagar') ? 'apagar' : 'esvaziar';
        $partes = array_keys(partesCasamento());
        $n = 0;
        foreach ($ids as $cid) {
            $cid = (int)$cid;
            $q = @$conn->query("SELECT id FROM {$P}casamentos WHERE id=$cid");
            if (!$q || !$q->num_rows) continue;
            // Nos dois casos limpa-se primeiro por peças: além de esvaziar os dados,
            // isto apaga do disco as fotos que o casal anexou, que uma limpeza só de
            // linhas na base deixaria órfãs.
            reporFabricaPartes($conn, $cid, $partes);
            if ($modo === 'apagar') {
                // As contas de casamento (noivos/porteiro) só existem por causa
                // dele — vão com ele. Antes dos acessos, que é por eles que se
                // sabe de quem são.
                $res['contas_casamento'] = ($res['contas_casamento'] ?? 0) + apagarContasDoCasamento($conn, $cid);
                foreach (['convidados','convites','mesas','versoes','registo','definicoes',
                          'acessos','suporte_codigos',
                          'orcamento_pagamentos','orcamento_despesas','orcamento_categorias'] as $t) {
                    $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
                }
                $conn->query("DELETE FROM {$P}casamentos WHERE id=$cid");
                if ((int)($_SESSION['casamento_id'] ?? 0) === $cid) {
                    $_SESSION['casamento_id'] = 0; $_SESSION['papel'] = null;
                }
            }
            $n++;
        }
        $res[$modo === 'apagar' ? 'casamentos_apagados' : 'casamentos'] = $n;
    }
    if (!$res) erro('Escolha o que quer apagar.');
    registar($conn, 'sistema_dados_apagados', $tudo ? 'tudo' : 'gestão', json_encode($res));
    ok(['res' => $res] + ($tudo ? ['tudo' => true] : []));
}

erro('Ação desconhecida.');
