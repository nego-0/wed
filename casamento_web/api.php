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

/** Um caminho é uma fotografia posta à mão (custom), e não um asset de origem? */
function ehFotoCustom(string $caminho): bool {
    return $caminho !== '' && !str_contains($caminho, '..')
        && str_starts_with($caminho, 'assets/convite/custom/');
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
    $portEmail = mb_strtolower(trim((string)($d['porteiro_email'] ?? '')));
    if ($portEmail !== '' && filter_var($portEmail, FILTER_VALIDATE_EMAIL) && $portEmail !== $email) {
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

    $_SESSION['registo_feito'] = time();
    usarCasamento($cid);

    // O plano que o casal escolheu vira pedido de licença, pendente. É o que o
    // admin vai ver na página das licenças, e é o que o casal vê ao entrar.
    $pedido = 0;
    if (!empty($d['licenca'])) {
        $pedido = licRegistarPedido($conn, $cid, (array)$d['licenca'], 'inicial');
    }

    registar($conn, 'registo_publico', $nomeConta, $email);
    ok(['casamento' => $cid, 'dados_do_evento' => $gravadas, 'pedido' => $pedido]);
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

/**
 * As secções do convite digital que levam fotografia, e a galeria de cada uma.
 *
 * É isto que a inscrição oferece a quem escolhe o convite digital: escolher já
 * a fotografia de cada secção. No escalão SEM edição é a única vez que o casal
 * as escolhe — daí serem oferecidas aqui, e não só dentro do editor.
 */
function licSeccoesFoto(mysqli $conn): array {
    $gal = galeriaCompleta($conn);
    $out = [];
    foreach (['capa' => 'Capa', 'historia' => 'História',
              'interludio' => 'Interlúdio', 'acesso' => 'Acesso (QR)'] as $cat => $rotulo) {
        $chave = chaveDaCategoria($cat);
        if (!$chave) continue;
        $fotos = [];
        foreach ($gal as $g) {
            if (($g['categoria'] ?? '') !== $cat) continue;
            $fotos[] = ['src' => $g['src'], 'nome' => $g['nome']];
        }
        if (!$fotos) continue;
        $out[] = ['cat' => $cat, 'chave' => $chave, 'rotulo' => $rotulo,
                  'descricao' => [
                      'capa'       => 'A primeira imagem, atrás dos vossos nomes.',
                      'historia'   => 'A que acompanha a vossa história.',
                      'interludio' => 'A pausa a meio do convite.',
                      'acesso'     => 'A que fica junto ao código de entrada.',
                  ][$cat] ?? '',
                  'fotos' => $fotos];
    }
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

    // As fotografias de cada secção do convite digital. Só se guardam as que
    // são mesmo da galeria: o casal escolhe de uma lista, e um caminho vindo de
    // fora não é uma escolha — é outra coisa qualquer.
    $fotosOk = [];
    if (!empty($d['fotos']) && is_array($d['fotos'])) {
        $validas = [];
        foreach (licSeccoesFoto($conn) as $sc) {
            foreach ($sc['fotos'] as $ft) $validas[$sc['chave']][$ft['src']] = true;
        }
        foreach ($d['fotos'] as $chave => $src) {
            $chave = (string)$chave; $src = (string)$src;
            if (isset($validas[$chave][$src])) $fotosOk[$chave] = $src;
        }
    }
    $fotosJson = $fotosOk ? json_encode($fotosOk, JSON_UNESCAPED_SLASHES) : null;

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

    // As fotografias que ele escolheu vão já para o convite.
    if ($fotosOk) licAplicarFotos($conn, $cid, $fotosOk);

    // O casamento passa a dizer que tem um pedido em cima da mesa — excepto se
    // já tem licença ativa e isto é um reforço: aí continua a valer o que tem.
    $st = @$conn->prepare("UPDATE {$P}casamentos SET licenca_estado='pendente'
                           WHERE id=? AND licenca_estado <> 'ativa'");
    if ($st) { $st->bind_param('i', $cid); @$st->execute(); }
    return $pedidoId;
}

/**
 * Escreve no convite do casamento as fotografias escolhidas no pedido.
 *
 * Faz-se logo à inscrição (e outra vez a cada alteração do pedido) e não só na
 * aprovação: o casal escolheu-as, são dele, e não há razão para o convite
 * esperar por uma decisão administrativa para ficar com a cara que ele quis.
 * Devolve quantas ficaram gravadas.
 */
function licAplicarFotos(mysqli $conn, int $cid, array $fotos): int {
    if ($cid <= 0 || !$fotos) return 0;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $defs = [];
    foreach ($fotos as $chave => $src) $defs[(string)$chave] = (string)$src;
    $r = guardarDefinicoes($conn, $defs);
    usarCasamento($anterior > 0 ? $anterior : $cid);
    return (int)($r['gravadas'] ?? 0);
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
        'recusado'   => licPedido($conn, $cid, 'recusado'),
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
    ok(['catalogo' => licCatalogo($conn), 'politica' => licPolitica($conn), 'moeda' => 'Kz',
        'seccoes_foto' => licSeccoesFoto($conn)]);
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
    $st = $conn->prepare("SELECT nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $cid); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');

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
                && str_starts_with($val, 'assets/convite/custom/') && !str_contains($val, '..')) {
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
    $dir = __DIR__ . '/assets/convite/custom';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nomeFich = str_replace('media.', '', $chave) . '-' . time() . '-' . random_int(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!moverUpload($src, "$dir/$nomeFich")) erro('Não foi possível guardar o ficheiro.');
    $caminho = 'assets/convite/custom/' . $nomeFich;
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
        if (str_starts_with($antigo, 'assets/convite/custom/') && !str_contains($antigo, '..')
            && !ficheiroEmVersao($conn, $antigo)) {
            @unlink(__DIR__ . '/' . $antigo);
            $limpos[] = $antigo;
        }
        $novos[$k] = (string)$padrao[$k];
    }
    if ($novos) guardarDefinicoes($conn, $novos);
    registar($conn, 'media_reposta', implode(', ', array_keys($novos)), count($limpos) . ' ficheiro(s) apagado(s)');
    ok(['repostas' => array_keys($novos), 'apagados' => count($limpos)]);
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

    // E a licença: um casamento criado aqui dentro nasce com tudo aberto. Quem
    // o criou já decidiu — não há pedido nenhum a analisar. Se se quiser dar-lhe
    // menos, é em «Módulos da licença…», ou mandando os escalões neste pedido.
    $escPedidos = [];
    foreach ((array)($d['escaloes'] ?? []) as $e) { $e = (int)$e; if ($e > 0) $escPedidos[] = $e; }
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
    $papelCas = in_array($d['papel'] ?? '', ['noivos','porteiro'], true) ? $d['papel'] : 'porteiro';
    // Os noivos convidam PORTEIROS. Passar a gestão do casamento a outra conta
    // é coisa que se faz com quem responde pela casa presente — senão bastava
    // um convite mal dirigido para o casamento passar a ser de outra pessoa.
    if (!ehAdminPlataforma()) $papelCas = 'porteiro';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');

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
    $papelCas = in_array($_GET['papel'] ?? '', ['noivos','porteiro'], true) ? $_GET['papel'] : '';
    if ($uid <= 0 || $papelCas === '') erro('Indique a conta e o papel.');
    // Ninguém se despromove a si próprio: o casamento ficaria sem quem o gere.
    if ($uid === utilizadorId() && $papelCas !== 'noivos') erro('Não pode tirar-se a si próprio a gestão.');
    if ($papelCas === 'porteiro' && contaNoivos($conn, $cid, $uid) === 0) {
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
    foreach (['convidados','convites','mesas','versoes','registo','definicoes',
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

if ($acao === 'registo_lista') {
    // O histórico só cresce: se mandássemos tudo, ao fim de um mês eram
    // milhares de linhas em cada abertura da janela. Vai por pedaços.
    $porPag = max(10, min(500, (int)($_GET['por_pagina'] ?? 100)));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $total  = (int)(@$conn->query("SELECT COUNT(*) FROM {$P}registo WHERE " . doCasamento() . "")?->fetch_row()[0] ?? 0);
    $r = $conn->query("SELECT utilizador, papel, accao, alvo, detalhe, criado_em
                       FROM {$P}registo WHERE " . doCasamento() . " ORDER BY id DESC
                       LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag));
    ok(['registos' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [],
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

        $sql = "SELECT r.casamento_id, c.nome AS casamento, r.utilizador, r.papel,
                       r.accao, r.alvo, r.detalhe, r.criado_em
                FROM {$P}registo r LEFT JOIN {$P}casamentos c ON c.id = r.casamento_id
                WHERE $where ORDER BY r.id DESC LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag);
        $st = $conn->prepare($sql);
        if ($par) $st->bind_param($tipos, ...$par);
        $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

        $accoes = [];
        $ra = $conn->query("SELECT DISTINCT accao FROM {$P}registo ORDER BY accao");
        if ($ra) while ($x = $ra->fetch_row()) $accoes[] = $x[0];
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
if ($acao === 'planta_bloqueio') {
    exigirModuloApi('mesas');
    // Trava/destrava o arrasto das mesas e o redimensionar do canvas.
    $d = corpo();
    foreach (['bloq_mesas' => 'planta.bloq_mesas', 'bloq_canvas' => 'planta.bloq_canvas'] as $campo => $chave) {
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
    if ($nome==='') erro('Nome da mesa obrigatório.');
    if ($id){ $st=$conn->prepare("UPDATE {$P}mesas SET nome=?,capacidade=?,forma=?,cor=?,tamanho=? WHERE " . doCasamento() . " AND id=?"); $st->bind_param('sisssi',$nome,$cap,$forma,$cor,$tam,$id); }
    else    { $st=$conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,tamanho) VALUES (" . casamentoAtual() . ",?,?,?,?,?)"); $st->bind_param('sisss',$nome,$cap,$forma,$cor,$tam); }
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
    $x = isset($d['x']) && $d['x']!=='' ? max(0.0, min(100.0, (float)$d['x'])) : null;
    $y = isset($d['y']) && $d['y']!=='' ? max(0.0, min(100.0, (float)$d['y'])) : null;
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
          'orcamento_despesas' => 0];
    foreach ((array)($saida['casamentos'] ?? []) as $c) {
        $r['mesas']   += count((array)($c['mesas'] ?? []));
        $r['versoes'] += count((array)($c['versoes'] ?? []));
        $r['orcamento_despesas'] += count((array)($c['orcamento']['despesas'] ?? []));
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

    usarCasamento($anterior > 0 ? $anterior : 1);
    return ['ficha' => $ficha, 'definicoes' => $defs, 'mesas' => $mesas,
            'convites' => $convites, 'versoes' => $versoes, 'acessos' => $acessos,
            'orcamento' => ['categorias' => $orcCategorias, 'despesas' => $orcDespesas]];
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
    foreach (['convidados', 'convites', 'mesas', 'versoes', 'definicoes',
              'orcamento_pagamentos', 'orcamento_despesas', 'orcamento_categorias'] as $t) {
        $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
    }

    $feito = ['mesas' => 0, 'convites' => 0, 'pessoas' => 0, 'versoes' => 0,
              'definicoes' => 0, 'codigos_trocados' => 0,
              'orc_categorias' => 0, 'orc_despesas' => 0, 'orc_pagamentos' => 0];
    $feito['definicoes'] = impFichaDefs($conn, $cid, $r, $comFicha);
    $feito['mesas']      = impMesas($conn, $cid, (array)($r['mesas'] ?? []));
    $cv = impConvites($conn, $cid, (array)($r['convites'] ?? []));
    $feito['convites'] = $cv['convites']; $feito['pessoas'] = $cv['pessoas'];
    $feito['codigos_trocados'] = $cv['codigos_trocados'];
    $feito['versoes'] = impVersoes($conn, $cid, (array)($r['versoes'] ?? []));
    foreach (impOrcamento($conn, $cid, (array)($r['orcamento'] ?? [])) as $k => $v) $feito[$k] = $v;

    usarCasamento($anterior > 0 ? $anterior : $cid);
    return $feito;
}

/** As secções que um casal pode escolher, e a etiqueta de cada uma. */
function partesCasamento(): array {
    return ['convidados' => 'Lista de convidados', 'mesas' => 'Mesas',
            'digital' => 'Versões do convite digital', 'impresso' => 'Versões do convite impresso',
            'orcamento' => 'Orçamento'];
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
    $rd = @$conn->query("SELECT dd.id, dd.categoria_id, dd.descricao, dd.fornecedor, dd.valor, dd.estado, dd.nota, dd.fatura,
            COALESCE((SELECT SUM(valor) FROM {$P}orcamento_pagamentos p
                      WHERE p.casamento_id=$cid AND p.despesa_id=dd.id),0) AS pago_parcelas,
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
    // Qual modelo é a peça de origem de cada âmbito é uma verdade da CASA (a
    // designação vive no 0), independente de haver casamento aberto — o admin
    // tem de a ver na página dos modelos mesmo sem um casamento à frente.
    $atual = []; $vigorId = []; $origemId = []; $naOrigem = []; $fabricaId = []; $designadaId = [];
    foreach (array_keys(ambitosVersao()) as $amb) {
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
        // Este modelo é a peça de origem deste âmbito? (Assinala-o no painel.)
        $m['de_origem'] = isset($origemId[$amb]) && (int)$m['id'] === $origemId[$amb];
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
    registar($conn, 'peca_origem_definida', $nome ?? '(automático)', $ambito);
    ok(['ambito' => $ambito, 'id' => $id, 'nome' => $nome ?? nomeDaOrigem($conn, $ambito)]);
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
