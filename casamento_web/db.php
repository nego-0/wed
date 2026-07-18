<?php
// ============================================================
// db.php — Ligação, esquema e funções partilhadas
// ============================================================
require_once __DIR__ . '/config.php';

// ---- Ligação (tenta local, depois online) ------------------
// No PHP 8.1+ o mysqli lança exceções por defeito. Desligamos esse modo
// para que uma tentativa de ligação falhada devolva erro em vez de abortar
// o script (o que causaria um HTTP 500 antes de tentar a config seguinte).
mysqli_report(MYSQLI_REPORT_OFF);

$conn = null; $CONFIG_ATIVA = null; $ULTIMO_ERRO = '';
foreach (DB_CONFIGS as $nome => $cfg) {
    try {
        $t = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
        if ($t && !$t->connect_error) { $conn = $t; $CONFIG_ATIVA = $nome; break; }
        if ($t && $t->connect_error) { $ULTIMO_ERRO = $t->connect_error; }
    } catch (\Throwable $e) {
        $ULTIMO_ERRO = $e->getMessage();
    }
}
if (!$conn) {
    http_response_code(503);
    $msg = 'Erro de ligação à base de dados. Verifique o config.php.';
    // Diagnóstico opcional: aceda com ?diag=1 para ver o motivo técnico.
    if (isset($_GET['diag']) && $_GET['diag'] === '1' && $ULTIMO_ERRO !== '') {
        $msg .= ' [Detalhe: ' . htmlspecialchars($ULTIMO_ERRO) . ']';
    }
    die($msg);
}
$conn->set_charset('utf8mb4');

// Alinha o fuso da base de dados com o fuso local (config.php), para que
// CURRENT_TIMESTAMP/NOW() fiquem na hora local e não na do servidor.
try {
    $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    @$conn->query("SET time_zone = '$offset'");
} catch (\Throwable $e) { /* mantém o fuso do servidor se falhar */ }

$P = PREFIXO; // atalho para o prefixo

// ---- Esquema (tabelas novas, prefixadas) -------------------
$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}mesas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(191) NOT NULL UNIQUE,
        capacidade INT DEFAULT NULL,
        pos_x DECIMAL(6,2) DEFAULT NULL,          -- posição na planta (% horizontal, 0-100)
        pos_y DECIMAL(6,2) DEFAULT NULL,          -- posição na planta (% vertical, 0-100)
        forma VARCHAR(20) DEFAULT 'redonda',      -- redonda/oval/quadrada/retangular/comprida/ferradura
        cor VARCHAR(20) DEFAULT NULL,             -- cor da mesa (chave da paleta), NULL = marfim
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}convites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(12) NOT NULL UNIQUE,
        nome_exibicao VARCHAR(255) NOT NULL,          -- nome no convite (ex: Família Agostinho)
        sufixo VARCHAR(120) DEFAULT NULL,             -- override do texto entre parênteses
        mostrar_numero TINYINT(1) DEFAULT 1,          -- mostrar o número entre parênteses no nome do convite
        mostrar_num_mesa TINYINT(1) DEFAULT 1,        -- mostrar o nº de pessoas por mesa no convite digital
        tipo ENUM('digital','fisico','ambos') DEFAULT 'digital',
        lado ENUM('noivo','noiva','ambos') DEFAULT 'noivo',
        lugares INT NOT NULL DEFAULT 1,
        mesa_id INT DEFAULT NULL,
        telefone VARCHAR(50) DEFAULT NULL,
        impresso TINYINT(1) DEFAULT 0,                -- convite físico já impresso
        enviado TINYINT(1) DEFAULT 0,                 -- convite digital já enviado
        rsvp_estado ENUM('pendente','confirmado','recusado','parcial') DEFAULT 'pendente',
        rsvp_confirmados INT DEFAULT NULL,            -- nº que confirmou presença
        rsvp_mensagem TEXT DEFAULT NULL,
        rsvp_em TIMESTAMP NULL DEFAULT NULL,
        checkin_estado ENUM('aguardando','presente','parcial') DEFAULT 'aguardando',
        checkin_presentes INT DEFAULT 0,
        checkin_em TIMESTAMP NULL DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        msg_pessoal TEXT DEFAULT NULL,               -- mensagem pessoal mostrada no convite digital
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}convidados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        convite_id INT NOT NULL,
        nome VARCHAR(255) NOT NULL,
        principal TINYINT(1) DEFAULT 0,
        rsvp ENUM('pendente','confirmado','recusado') DEFAULT 'pendente',
        presente TINYINT(1) DEFAULT 0,
        presente_em TIMESTAMP NULL DEFAULT NULL,
        mesa_id INT DEFAULT NULL,                  -- mesa individual (opcional); senão usa a do convite
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (convite_id) REFERENCES {$P}convites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migração suave: garantir a coluna mostrar_numero em instalações anteriores
$col = $conn->query("SHOW COLUMNS FROM {$P}convites LIKE 'mostrar_numero'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convites ADD COLUMN mostrar_numero TINYINT(1) DEFAULT 1 AFTER sufixo");
}

// Migração suave: posição e forma das mesas (para a planta visual)
foreach ([
    'pos_x' => "DECIMAL(6,2) DEFAULT NULL",
    'pos_y' => "DECIMAL(6,2) DEFAULT NULL",
    'forma' => "VARCHAR(20) DEFAULT 'redonda'",
    'cor'   => "VARCHAR(20) DEFAULT NULL",
] as $coluna => $definicao) {
    $r = $conn->query("SHOW COLUMNS FROM {$P}mesas LIKE '$coluna'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE {$P}mesas ADD COLUMN $coluna $definicao");
    }
}

// Migração suave: mesa individual por convidado (permite dividir um convite por várias mesas)
$col = $conn->query("SHOW COLUMNS FROM {$P}convidados LIKE 'mesa_id'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convidados ADD COLUMN mesa_id INT DEFAULT NULL AFTER presente_em");
}

// Migração suave: lado do convidado na mesa dos noivos (padrinhos/madrinhas)
$col = $conn->query("SHOW COLUMNS FROM {$P}convidados LIKE 'lado_noivos'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convidados ADD COLUMN lado_noivos VARCHAR(10) DEFAULT NULL AFTER mesa_id");
}

// Migração suave: papel do convidado (padrinho/madrinha) — deteta as alas dos noivos automaticamente
$col = $conn->query("SHOW COLUMNS FROM {$P}convidados LIKE 'papel'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convidados ADD COLUMN papel VARCHAR(20) DEFAULT NULL AFTER lado_noivos");
}

// Migração suave: mesa especial (noivos) e tamanho manual da mesa
foreach (['especial' => "VARCHAR(20) DEFAULT NULL", 'tamanho' => "VARCHAR(10) DEFAULT NULL"] as $coluna => $definicao) {
    $r = $conn->query("SHOW COLUMNS FROM {$P}mesas LIKE '$coluna'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE {$P}mesas ADD COLUMN $coluna $definicao");
}

// Migração suave: opção de mostrar o nº de pessoas por mesa no convite digital
$col = $conn->query("SHOW COLUMNS FROM {$P}convites LIKE 'mostrar_num_mesa'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convites ADD COLUMN mostrar_num_mesa TINYINT(1) DEFAULT 1 AFTER mostrar_numero");
}

// Migração suave: mensagem pessoal por convite (convite digital)
$col = $conn->query("SHOW COLUMNS FROM {$P}convites LIKE 'msg_pessoal'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE {$P}convites ADD COLUMN msg_pessoal TEXT DEFAULT NULL AFTER observacoes");
}

// Definições do convite digital (personalização; ver personalizacao.php)
$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}definicoes (
        chave VARCHAR(64) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================================
// Funções partilhadas
// ============================================================

/** URL base do site (funciona em local e online, para links e QR). */
function base_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return "$scheme://$host$dir";
}

/** Código único e legível (sem caracteres ambíguos). */
function gerarCodigo(mysqli $conn): string {
    global $P;
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem O,0,I,1
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $st = $conn->prepare("SELECT id FROM {$P}convites WHERE codigo=? LIMIT 1");
        $st->bind_param('s', $c); $st->execute();
        $existe = $st->get_result()->fetch_assoc();
    } while ($existe);
    return $c;
}

/**
 * Nome final do convite, aplicando a regra do número entre parênteses.
 * - 1 lugar  -> apenas o nome (sem parênteses)
 * - >1 lugar -> "Nome (N)"  ou o sufixo personalizado, se existir.
 */
function nomeConvite(array $c): string {
    $nome = trim($c['nome_exibicao']);
    $lug  = (int)($c['lugares'] ?? 1);
    $suf  = trim((string)($c['sufixo'] ?? ''));
    if ($suf !== '')  return "$nome ($suf)";
    if ($lug <= 1)    return $nome;
    return "$nome ($lug)";
}

/**
 * Nome tal como aparece NO CONVITE do convidado, respeitando a opção
 * "mostrar_numero". Um sufixo textual mantém-se sempre; o número entre
 * parênteses (o nº de lugares) só surge quando a opção está ativa.
 */
function nomeConviteVisivel(array $c): string {
    $nome = trim($c['nome_exibicao']);
    $lug  = (int)($c['lugares'] ?? 1);
    $suf  = trim((string)($c['sufixo'] ?? ''));
    $mostrar = !isset($c['mostrar_numero']) || (int)$c['mostrar_numero'] === 1;
    if ($suf !== '')          return "$nome ($suf)";
    if ($mostrar && $lug > 1) return "$nome ($lug)";
    return $nome;
}

/** Indica se o número entre parênteses (nº de lugares) é mostrado no convite. */
function mostraNumeroConvite(array $c): bool {
    $lug = (int)($c['lugares'] ?? 1);
    $suf = trim((string)($c['sufixo'] ?? ''));
    $mostrar = !isset($c['mostrar_numero']) || (int)$c['mostrar_numero'] === 1;
    return $mostrar && $suf === '' && $lug > 1;
}

/** Resolve/insere uma mesa pelo nome e devolve o id. */
function resolverMesa(mysqli $conn, string $nome): ?int {
    global $P;
    $nome = trim($nome);
    if ($nome === '') return null;
    $st = $conn->prepare("SELECT id FROM {$P}mesas WHERE nome=? LIMIT 1");
    $st->bind_param('s', $nome); $st->execute();
    if ($r = $st->get_result()->fetch_assoc()) return (int)$r['id'];
    $st = $conn->prepare("INSERT INTO {$P}mesas (nome) VALUES (?)");
    $st->bind_param('s', $nome); $st->execute();
    return $conn->insert_id;
}

/** Recalcula APENAS o estado de entrada (check-in) a partir dos membros. Não toca no RSVP. */
function recalcularCheckin(mysqli $conn, int $conviteId, string $tsSql = 'NOW()'): void {
    global $P;
    $st = $conn->prepare("SELECT COUNT(*) tot,
                                 SUM(CASE WHEN rsvp='confirmado' THEN 1 ELSE 0 END) conf,
                                 COALESCE(SUM(presente),0) pres
                          FROM {$P}convidados WHERE convite_id=?");
    $st->bind_param('i', $conviteId); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $tot = (int)$r['tot']; $conf = (int)$r['conf']; $pres = (int)$r['pres'];
    if ($tot === 0) return; // convites sem membros nominais são geridos diretamente
    $base = $conf > 0 ? $conf : $tot; // "presente" quando todos os confirmados entraram
    $estado = $pres === 0 ? 'aguardando' : ($pres >= $base ? 'presente' : 'parcial');
    $em = $pres > 0 ? "COALESCE(checkin_em, $tsSql)" : 'NULL';
    $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado=?, checkin_presentes=?, checkin_em=$em WHERE id=?");
    $st->bind_param('sii', $estado, $pres, $conviteId); $st->execute();
}

/** Estatísticas globais para o painel. */
function estatisticas(mysqli $conn): array {
    global $P;
    $one = fn($sql) => (int)($conn->query($sql)->fetch_row()[0] ?? 0);
    $s = [];
    $s['convites']     = $one("SELECT COUNT(*) FROM {$P}convites");
    $s['lugares']      = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites");
    $s['convidados']   = $one("SELECT COUNT(*) FROM {$P}convidados");
    $s['digitais']     = $one("SELECT COUNT(*) FROM {$P}convites WHERE tipo IN ('digital','ambos')");
    $s['fisicos']      = $one("SELECT COUNT(*) FROM {$P}convites WHERE tipo IN ('fisico','ambos')");
    $s['noivos']       = $one("SELECT COUNT(*) FROM {$P}convites WHERE lado IN ('noivo','ambos')");
    $s['noivas']       = $one("SELECT COUNT(*) FROM {$P}convites WHERE lado IN ('noiva','ambos')");
    $s['impressos']    = $one("SELECT COUNT(*) FROM {$P}convites WHERE impresso=1");
    $s['enviados']     = $one("SELECT COUNT(*) FROM {$P}convites WHERE enviado=1");
    $s['confirmados']  = $one("SELECT COUNT(*) FROM {$P}convites WHERE rsvp_estado='confirmado'");
    $s['parciais']     = $one("SELECT COUNT(*) FROM {$P}convites WHERE rsvp_estado='parcial'");
    $s['recusados']    = $one("SELECT COUNT(*) FROM {$P}convites WHERE rsvp_estado='recusado'");
    $s['pendentes']    = $one("SELECT COUNT(*) FROM {$P}convites WHERE rsvp_estado='pendente'");
    $s['lug_confirm']  = $one("SELECT COALESCE(SUM(rsvp_confirmados),0) FROM {$P}convites");
    // Convidados reais (pessoas = lugares) por situação, tipo e lado
    $s['pes_confirmados'] = $s['lug_confirm'];
    $s['pes_pendentes']   = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE rsvp_estado='pendente'");
    $s['pes_recusados']   = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE rsvp_estado='recusado'");
    $s['pes_digitais']    = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE tipo IN ('digital','ambos')");
    $s['pes_fisicos']     = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE tipo IN ('fisico','ambos')");
    $s['pes_impressos']   = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE impresso=1");
    $s['pes_noivos']      = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE lado IN ('noivo','ambos')");
    $s['pes_noivas']      = $one("SELECT COALESCE(SUM(lugares),0) FROM {$P}convites WHERE lado IN ('noiva','ambos')");
    $s['capacidade']      = MAX_LUGARES_TOTAL;
    $s['presentes']    = $one("SELECT COALESCE(SUM(checkin_presentes),0) FROM {$P}convites");
    $s['no_local']     = $one("SELECT COUNT(*) FROM {$P}convites WHERE checkin_estado IN ('presente','parcial')");
    $s['mesas']        = $one("SELECT COUNT(*) FROM {$P}mesas");
    return $s;
}

/**
 * Lista de mesas com ocupação, considerando mesas individuais por convidado.
 * Ocupação de uma mesa =
 *   (nº de pessoas nomeadas cuja mesa efetiva é esta: mesa própria, senão a do convite)
 * + (lugares "sem nome" de cada convite atribuído a esta mesa: lugares − nº de nomeados).
 */
function listarMesas(mysqli $conn): array {
    global $P;
    $mesas = $conn->query("SELECT id, nome, capacidade, pos_x, pos_y, forma, cor, especial, tamanho
                           FROM {$P}mesas ORDER BY (especial='noivos') DESC, nome")->fetch_all(MYSQLI_ASSOC);
    $idx = [];
    $noivosId = 0;
    foreach ($mesas as $i => $m) {
        $mesas[$i]['ocupacao'] = 0; $mesas[$i]['convites'] = 0; $idx[(int)$m['id']] = $i;
        if (($m['especial'] ?? '') === 'noivos') $noivosId = (int)$m['id'];
    }
    $presenca = []; // mesaId => [conviteId => true] (para contar convites distintos por mesa)

    // 1) Pessoas nomeadas na sua mesa efetiva. Padrinhos/madrinhas sentam-se sempre
    //    na mesa dos noivos (deteção automática pelo papel), se ela existir.
    $res = $conn->query("SELECT g.convite_id, g.papel, COALESCE(g.mesa_id, c.mesa_id) AS eff
                         FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id = c.id");
    while ($r = $res->fetch_assoc()) {
        $eff = $r['eff'] !== null ? (int)$r['eff'] : 0;
        if ($noivosId && in_array($r['papel'] ?? '', ['padrinho', 'madrinha'], true)) $eff = $noivosId;
        if ($eff && isset($idx[$eff])) {
            $mesas[$idx[$eff]]['ocupacao'] += 1;
            $presenca[$eff][(int)$r['convite_id']] = true;
        }
    }
    // 2) Lugares sem nome (lugares além dos membros nomeados), na mesa do convite.
    $res = $conn->query("SELECT c.id, c.mesa_id, c.lugares, COUNT(g.id) AS nomeados
                         FROM {$P}convites c LEFT JOIN {$P}convidados g ON g.convite_id = c.id
                         GROUP BY c.id, c.mesa_id, c.lugares");
    while ($r = $res->fetch_assoc()) {
        $mid = $r['mesa_id'] !== null ? (int)$r['mesa_id'] : 0;
        if (!$mid || !isset($idx[$mid])) continue;
        $extra = max(0, (int)$r['lugares'] - (int)$r['nomeados']);
        if ($extra > 0) { $mesas[$idx[$mid]]['ocupacao'] += $extra; $presenca[$mid][(int)$r['id']] = true; }
    }
    foreach ($presenca as $mid => $set) { if (isset($idx[$mid])) $mesas[$idx[$mid]]['convites'] = count($set); }
    return $mesas;
}

/** Formatos (proporção) disponíveis para o canvas da planta. */
function formatosPlanta(): array {
    return ['16:10'=>'Panorâmica','3:2'=>'Paisagem','4:3'=>'Clássica','1:1'=>'Quadrada','21:9'=>'Ultra-larga'];
}
/** Configuração do canvas da planta (formato guardado nas definições). */
function plantaConfig(mysqli $conn): array {
    global $P;
    $fmt = '16:10';
    $r = @$conn->query("SELECT valor FROM {$P}definicoes WHERE chave='planta.formato' LIMIT 1");
    if ($r && ($x = $r->fetch_assoc()) && isset(formatosPlanta()[$x['valor']])) $fmt = $x['valor'];
    return ['formato' => $fmt];
}

/** Carrega um convite (por id ou código) já com os membros e o nome final. */
function carregarConvite(mysqli $conn, $chave, string $por = 'id'): ?array {
    global $P;
    $col = $por === 'codigo' ? 'codigo' : 'id';
    $st = $conn->prepare("SELECT c.*, m.nome AS mesa_nome
                          FROM {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                          WHERE c.$col=? LIMIT 1");
    $por === 'codigo' ? $st->bind_param('s', $chave) : $st->bind_param('i', $chave);
    $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) return null;
    $st = $conn->prepare("SELECT g.*, mg.nome AS mesa_nome, mg.especial AS mesa_especial
                          FROM {$P}convidados g LEFT JOIN {$P}mesas mg ON g.mesa_id = mg.id
                          WHERE g.convite_id=? ORDER BY g.principal DESC, g.nome");
    $st->bind_param('i', $c['id']); $st->execute();
    $c['membros'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    // Mesa efetiva de cada membro: a sua própria, senão a do convite.
    foreach ($c['membros'] as &$m) {
        $m['mesa_efetiva'] = $m['mesa_nome'] ?: ($c['mesa_nome'] ?: null);
    }
    unset($m);
    $c['nome_final'] = nomeConvite($c);
    return $c;
}

/**
 * Distribuição de um convite pelas mesas (considerando mesas individuais).
 * Devolve uma lista ordenada por nome de mesa: [['nome'=>..., 'n'=>nº pessoas], ...].
 * Cada pessoa nomeada conta na sua mesa efetiva (própria, senão a do convite);
 * os lugares "sem nome" (lugares além dos nomeados) contam na mesa do convite.
 */
function mesasDoConvite(mysqli $conn, array $c): array {
    global $P;
    $cid     = (int)$c['id'];
    $lugares = (int)($c['lugares'] ?? 0);
    $res = $conn->query("SELECT COALESCE(mg.nome, mc.nome) AS mesa
                         FROM {$P}convidados g
                         LEFT JOIN {$P}mesas mg ON g.mesa_id = mg.id
                         LEFT JOIN {$P}convites c ON g.convite_id = c.id
                         LEFT JOIN {$P}mesas mc ON c.mesa_id = mc.id
                         WHERE g.convite_id = $cid");
    $cont = []; $nomeados = 0;
    while ($r = $res->fetch_assoc()) {
        $nomeados++;
        $m = $r['mesa'];
        if ($m === null || $m === '') continue;
        $cont[$m] = ($cont[$m] ?? 0) + 1;
    }
    // Lugares sem nome -> mesa do convite
    $extra = max(0, $lugares - $nomeados);
    if ($extra > 0 && !empty($c['mesa_nome'])) {
        $cont[$c['mesa_nome']] = ($cont[$c['mesa_nome']] ?? 0) + $extra;
    }
    ksort($cont, SORT_NATURAL | SORT_FLAG_CASE);
    $out = [];
    foreach ($cont as $nome => $n) $out[] = ['nome' => $nome, 'n' => $n];
    return $out;
}

/**
 * Texto das mesas de um convite. Ex.: "A (1 pessoa) e B (4 pessoas)".
 * $comNumero controla se aparece o "(N pessoas)" ao lado de cada mesa.
 */
function textoMesas(array $lista, bool $comNumero = true): string {
    if (!$lista) return '';
    $partes = array_map(function ($m) use ($comNumero) {
        if (!$comNumero) return $m['nome'];
        $n = (int)$m['n'];
        return $m['nome'] . ' (' . $n . ' ' . ($n === 1 ? 'pessoa' : 'pessoas') . ')';
    }, $lista);
    if (count($partes) === 1) return $partes[0];
    $ultima = array_pop($partes);
    return implode(', ', $partes) . ' e ' . $ultima;
}

/** Detecta se a lista antiga existe (para oferecer importação). */
function listaAntigaExiste(mysqli $conn): bool {
    $r = $conn->query("SHOW TABLES LIKE 'guests'");
    return $r && $r->num_rows > 0;
}
