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
        sufixo VARCHAR(120) DEFAULT NULL,             -- texto opcional entre parênteses no nome (ex: e acompanhante)
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

// ============================================================
// Migrações com versão
//
// Antes, cada pedido corria ~25 instruções DDL (CREATE TABLE IF NOT EXISTS,
// SHOW COLUMNS, ALTER TABLE). Em alojamento partilhado isso é latência em
// TODAS as páginas e chamadas à API. Agora guarda-se a versão do esquema em
// cw_definicoes e só se corre o que falta.
// ============================================================
const ESQUEMA_VERSAO = 6;

/** Acrescenta uma coluna se ainda não existir (usado dentro das migrações). */
function migColuna(mysqli $c, string $tabela, string $coluna, string $def): void {
    $r = @$c->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $c->real_escape_string($coluna) . "'");
    if ($r && $r->num_rows === 0) @$c->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def");
}
/** Cria um índice se ainda não existir. */
function migIndice(mysqli $c, string $tabela, string $nome, string $colunas): void {
    $r = @$c->query("SHOW INDEX FROM `$tabela` WHERE Key_name='" . $c->real_escape_string($nome) . "'");
    if ($r && $r->num_rows === 0) @$c->query("CREATE INDEX `$nome` ON `$tabela` ($colunas)");
}
/** Larga uma coluna, se ainda existir (usado dentro das migrações). */
function migLargarColuna(mysqli $c, string $tabela, string $coluna): void {
    $r = @$c->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $c->real_escape_string($coluna) . "'");
    if ($r && $r->num_rows) @$c->query("ALTER TABLE `$tabela` DROP COLUMN `$coluna`");
}

// A tabela de definições tem de existir antes de se poder ler a versão.
$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}definicoes (
        chave VARCHAR(64) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rv = @$conn->query("SELECT valor FROM {$P}definicoes WHERE chave='schema.versao' LIMIT 1");
$versaoAtual = ($rv && $rv->num_rows) ? (int)$rv->fetch_assoc()['valor'] : 0;

if ($versaoAtual < ESQUEMA_VERSAO) {

    // ---- v1: colunas acrescentadas ao longo do desenvolvimento -----------
    if ($versaoAtual < 1) {
        migColuna($conn, "{$P}convites",   'mostrar_num_mesa', "TINYINT(1) DEFAULT 1");
        migColuna($conn, "{$P}convites",   'msg_pessoal',      "TEXT DEFAULT NULL");
        foreach (['pos_x' => "DECIMAL(6,2) DEFAULT NULL", 'pos_y' => "DECIMAL(6,2) DEFAULT NULL",
                  'forma' => "VARCHAR(20) DEFAULT 'redonda'", 'cor' => "VARCHAR(20) DEFAULT NULL",
                  'especial' => "VARCHAR(20) DEFAULT NULL", 'tamanho' => "VARCHAR(10) DEFAULT NULL"] as $col => $def) {
            migColuna($conn, "{$P}mesas", $col, $def);
        }
        foreach (['mesa_id' => "INT DEFAULT NULL", 'papel' => "VARCHAR(20) DEFAULT NULL",
                  'genero' => "VARCHAR(1) DEFAULT NULL", 'brinde' => "TINYINT(1) DEFAULT 0"] as $col => $def) {
            migColuna($conn, "{$P}convidados", $col, $def);
        }
    }

    // ---- v2: índices e integridade referencial ---------------------------
    if ($versaoAtual < 2) {
        // Colunas usadas em WHERE/JOIN que não tinham índice.
        migIndice($conn, "{$P}convites",   'idx_conv_mesa',    'mesa_id');
        migIndice($conn, "{$P}convites",   'idx_conv_estado',  'rsvp_estado');
        migIndice($conn, "{$P}convites",   'idx_conv_tipo',    'tipo');
        migIndice($conn, "{$P}convidados", 'idx_cvd_mesa',     'mesa_id');
        migIndice($conn, "{$P}convidados", 'idx_cvd_rsvp',     'rsvp');
        migIndice($conn, "{$P}convidados", 'idx_cvd_papel',    'papel');
        migIndice($conn, "{$P}convidados", 'idx_cvd_genero',   'genero, brinde');
        migIndice($conn, "{$P}mesas",      'idx_mesa_especial','especial');

        // A integridade das mesas dependia de o código se lembrar de limpar as
        // referências ao apagar. Passa a ser garantida pela base de dados.
        // (Falha em silêncio se houver dados órfãos — sem consequências.)
        $temFk = @$conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                                WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$P}convites'
                                AND CONSTRAINT_NAME='fk_conv_mesa' LIMIT 1");
        if ($temFk && $temFk->num_rows === 0) {
            @$conn->query("UPDATE {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                           SET c.mesa_id=NULL WHERE c.mesa_id IS NOT NULL AND m.id IS NULL");
            @$conn->query("ALTER TABLE {$P}convites ADD CONSTRAINT fk_conv_mesa
                           FOREIGN KEY (mesa_id) REFERENCES {$P}mesas(id) ON DELETE SET NULL");
        }
        $temFk2 = @$conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                                 WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$P}convidados'
                                 AND CONSTRAINT_NAME='fk_cvd_mesa' LIMIT 1");
        if ($temFk2 && $temFk2->num_rows === 0) {
            @$conn->query("UPDATE {$P}convidados g LEFT JOIN {$P}mesas m ON g.mesa_id=m.id
                           SET g.mesa_id=NULL WHERE g.mesa_id IS NOT NULL AND m.id IS NULL");
            @$conn->query("ALTER TABLE {$P}convidados ADD CONSTRAINT fk_cvd_mesa
                           FOREIGN KEY (mesa_id) REFERENCES {$P}mesas(id) ON DELETE SET NULL");
        }

        // lado_noivos ficou sem uso quando o "papel" passou a definir as alas:
        // era sempre escrita a NULL e nunca lida. Sai do esquema.
        $rl = @$conn->query("SHOW COLUMNS FROM {$P}convidados LIKE 'lado_noivos'");
        if ($rl && $rl->num_rows) @$conn->query("ALTER TABLE {$P}convidados DROP COLUMN lado_noivos");
    }

    // ---- v3: eliminação reversível e registo de atividade ----------------
    if ($versaoAtual < 3) {
        // Eliminar um convite passa a ser reversível durante algum tempo.
        migColuna($conn, "{$P}convites", 'eliminado_em', "TIMESTAMP NULL DEFAULT NULL");
        migIndice($conn, "{$P}convites", 'idx_conv_eliminado', 'eliminado_em');

        // Quem fez o quê (útil quando admin e porteiro partilham o dispositivo).
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}registo (
                id INT AUTO_INCREMENT PRIMARY KEY,
                utilizador VARCHAR(60) DEFAULT NULL,
                papel VARCHAR(20) DEFAULT NULL,
                accao VARCHAR(40) NOT NULL,
                alvo VARCHAR(120) DEFAULT NULL,
                detalhe VARCHAR(255) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reg_data (criado_em),
                INDEX idx_reg_accao (accao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ---- v4: versões guardadas do convite -------------------------------
    if ($versaoAtual < 4) {
        // Uma fotografia das definições do convite, com nome, para se poder
        // experimentar à vontade e voltar atrás sem medo.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}versoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(80) NOT NULL,
                defs MEDIUMTEXT NOT NULL,
                utilizador VARCHAR(60) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ver_data (criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ---- v5: versões dos dois convites, com uma predefinida ---------------
    if ($versaoAtual < 5) {
        // 'ambito' separa o convite digital do cartão impresso: são peças
        // distintas e cada uma tem a sua versão em vigor.
        migColuna($conn, "{$P}versoes", 'ambito',        "VARCHAR(10) NOT NULL DEFAULT 'digital'");
        migColuna($conn, "{$P}versoes", 'predefinida',   "TINYINT(1) NOT NULL DEFAULT 0");
        migColuna($conn, "{$P}versoes", 'atualizado_em', "TIMESTAMP NULL DEFAULT NULL");
        migIndice($conn, "{$P}versoes", 'idx_ver_ambito', 'ambito, predefinida');
    }

    // ---- v6: fim do "(N)" de lugares no nome do convidado -----------------
    if ($versaoAtual < 6) {
        // A coluna que ligava/desligava o número entre parênteses deixou de ser
        // usada — o número já não entra no nome. Sai do esquema.
        migLargarColuna($conn, "{$P}convites", 'mostrar_numero');

        // Definições órfãs: as chaves que governavam o número e a nota que o
        // explicava já não existem em defsPadrao(). Sem default a que voltar,
        // uma linha guardada ficaria presa para sempre — apaga-se.
        @$conn->query("DELETE FROM {$P}definicoes
                       WHERE chave IN ('cartao.numero_no_nome','textos.nota_parenteses')");
    }

    @$conn->query("INSERT INTO {$P}definicoes (chave,valor) VALUES ('schema.versao','" . ESQUEMA_VERSAO . "')
                   ON DUPLICATE KEY UPDATE valor='" . ESQUEMA_VERSAO . "'");
}

// A mesa (especial) dos noivos existe por padrão: cria-se UMA vez (primeira utilização).
// Depois fica eliminável — não volta a ser recriada automaticamente (repõe-se no botão da planta).
$flag = $conn->query("SELECT valor FROM {$P}definicoes WHERE chave='noivos.criada' LIMIT 1");
if ($flag && $flag->num_rows === 0) {
    $rn = $conn->query("SELECT id FROM {$P}mesas WHERE especial='noivos' LIMIT 1");
    if ($rn && $rn->num_rows === 0) {
        $nomeN = 'Noivos'; $n = 2;
        while ($conn->query("SELECT id FROM {$P}mesas WHERE nome='" . $conn->real_escape_string($nomeN) . "'")->num_rows) $nomeN = 'Noivos ' . $n++;
        $stN = $conn->prepare("INSERT INTO {$P}mesas (nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (?,2,'redonda','ouro','noivos',50,42)");
        $stN->bind_param('s', $nomeN); $stN->execute();
    }
    $conn->query("INSERT INTO {$P}definicoes (chave,valor) VALUES ('noivos.criada','1') ON DUPLICATE KEY UPDATE valor='1'");
}

// ============================================================
// Funções partilhadas
// ============================================================

/**
 * Regista uma ação no histórico (quem fez o quê). Nunca interrompe o fluxo:
 * se a tabela ainda não existir, ignora em silêncio.
 */
function registar(mysqli $conn, string $accao, string $alvo = '', string $detalhe = ''): void {
    global $P;
    $u = function_exists('utilizadorAtual') ? (utilizadorAtual() ?? '') : '';
    $p = function_exists('papel') ? (papel() ?? '') : '';
    $st = @$conn->prepare("INSERT INTO {$P}registo (utilizador,papel,accao,alvo,detalhe) VALUES (?,?,?,?,?)");
    if (!$st) return;
    $alvo = substr($alvo, 0, 120); $detalhe = substr($detalhe, 0, 255);
    $st->bind_param('sssss', $u, $p, $accao, $alvo, $detalhe);
    @$st->execute();
}

/**
 * Condição SQL que deixa de fora os convites postos na reciclagem.
 * Devolve "1=1" enquanto a coluna não existir (esquema por migrar), para que
 * a mesma consulta continue a funcionar numa base antiga.
 */
function soVivos(mysqli $conn, string $alias = 'c'): string {
    global $P;
    $col = $alias === '' ? 'eliminado_em' : "$alias.eliminado_em";
    return colunaExiste($conn, "{$P}convites", 'eliminado_em') ? "$col IS NULL" : '1=1';
}

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
 * Nome final do convite. Um sufixo textual (ex.: "e acompanhante") aparece
 * entre parênteses; sem ele, fica só o nome. O número de lugares nunca vai
 * para o nome — é informação de gestão, mostrada onde faz sentido (mesas).
 */
function nomeConvite(array $c): string {
    $nome = trim($c['nome_exibicao']);
    $suf  = trim((string)($c['sufixo'] ?? ''));
    return $suf !== '' ? "$nome ($suf)" : $nome;
}

/** O nome tal como aparece no convite do convidado — igual ao nome final. */
function nomeConviteVisivel(array $c): string {
    return nomeConvite($c);
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
    // Tolerante a queries que falhem (ex.: coluna ainda por migrar) — devolve 0.
    $linha = function (string $sql) use ($conn): array {
        $r = @$conn->query($sql);
        return $r ? ($r->fetch_assoc() ?: []) : [];
    };
    $n = fn(array $a, string $k) => (int)($a[$k] ?? 0);
    $vivos = soVivos($conn, 'c');   // os da reciclagem não entram nas contas

    // ---- 1 query: tudo o que se agrega sobre a tabela de convites --------
    // (antes eram ~20 queries separadas sobre a mesma tabela)
    $c = $linha("SELECT
        COUNT(*)                                              AS convites,
        COALESCE(SUM(lugares),0)                              AS lugares,
        COALESCE(SUM(rsvp_confirmados),0)                     AS lug_confirm,
        SUM(tipo IN ('digital','ambos'))                      AS digitais,
        SUM(tipo IN ('fisico','ambos'))                       AS fisicos,
        SUM(lado IN ('noivo','ambos'))                        AS noivos,
        SUM(lado IN ('noiva','ambos'))                        AS noivas,
        SUM(impresso=1)                                       AS impressos,
        SUM(enviado=1)                                        AS enviados,
        SUM(rsvp_estado='parcial')                            AS parciais,
        COALESCE(SUM(checkin_presentes),0)                    AS presentes,
        SUM(checkin_estado IN ('presente','parcial'))         AS no_local,
        COALESCE(SUM(CASE WHEN tipo IN ('digital','ambos') THEN lugares END),0) AS pes_digitais,
        COALESCE(SUM(CASE WHEN tipo IN ('fisico','ambos')  THEN lugares END),0) AS pes_fisicos,
        COALESCE(SUM(CASE WHEN impresso=1 THEN lugares END),0)                  AS pes_impressos,
        COALESCE(SUM(CASE WHEN lado IN ('noivo','ambos') THEN lugares END),0)    AS pes_noivos,
        COALESCE(SUM(CASE WHEN lado IN ('noiva','ambos') THEN lugares END),0)    AS pes_noivas,
        COALESCE(SUM(CASE WHEN rsvp_estado='pendente' THEN lugares END),0)       AS lug_pendentes,
        COALESCE(SUM(CASE WHEN rsvp_estado='recusado' THEN lugares END),0)       AS lug_recusados,
        COALESCE(SUM(CASE WHEN rsvp_estado='parcial'
                     THEN GREATEST(CAST(lugares AS SIGNED) - COALESCE(rsvp_confirmados,0), 0) END),0) AS lug_parc_pend
        FROM {$P}convites c WHERE $vivos");

    // ---- 1 query: contagem de convites por estado ------------------------
    // Um convite conta para um estado se o seu rsvp_estado for esse OU se
    // tiver um integrante nesse estado (ex.: "parcial" com gente pendente).
    $e = $linha("SELECT
        SUM(c.rsvp_estado='confirmado' OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='confirmado')) AS confirmados,
        SUM(c.rsvp_estado='recusado'   OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='recusado'))   AS recusados,
        SUM(c.rsvp_estado IN ('pendente','parcial') OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='pendente')) AS pendentes
        FROM {$P}convites c WHERE $vivos");

    // ---- 1 query: tudo sobre os convidados nomeados ----------------------
    $temGen = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBri = colunaExiste($conn, "{$P}convidados", 'brinde');
    // Além das pessoas, conta-se também a quantos CONVITES pertencem: os cartões
    // do painel dizem todos "N convite(s)", por isso precisam sempre deste número.
    $exprG  = $temGen ? "SUM(g.genero='m') AS masc, SUM(g.genero='f') AS fem,
                         COUNT(DISTINCT CASE WHEN g.genero='m' THEN g.convite_id END) AS conv_masc,
                         COUNT(DISTINCT CASE WHEN g.genero='f' THEN g.convite_id END) AS conv_fem"
                      : "0 AS masc, 0 AS fem, 0 AS conv_masc, 0 AS conv_fem";
    // Brindes por género: quantos recebem, homens e mulheres. Só faz sentido
    // quando existem as duas colunas; senão fica a zero.
    $exprBg = ($temBri && $temGen)
        ? "SUM(g.brinde=1 AND g.genero='m') AS brinde_m, SUM(g.brinde=1 AND g.genero='f') AS brinde_f,
           SUM(g.brinde=1 AND (g.genero IS NULL OR g.genero='')) AS brinde_sg"
        : "0 AS brinde_m, 0 AS brinde_f, 0 AS brinde_sg";
    $exprB  = $temBri ? "SUM(g.brinde=1) AS brinde,
                         COUNT(DISTINCT CASE WHEN g.brinde=1 THEN g.convite_id END) AS conv_brinde,
                         $exprBg"
                      : "0 AS brinde, 0 AS conv_brinde, $exprBg";
    $g = $linha("SELECT COUNT(*) AS convidados, $exprG, $exprB,
        SUM(g.rsvp='pendente' AND c.rsvp_estado NOT IN ('pendente','parcial')) AS pend_fora,
        SUM(g.rsvp='recusado' AND c.rsvp_estado<>'recusado')                   AS rec_fora
        FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id=c.id WHERE $vivos");

    $mesas = $linha("SELECT COUNT(*) AS n FROM {$P}mesas");

    $s = [
        'convites'    => $n($c,'convites'),   'lugares'   => $n($c,'lugares'),
        'convidados'  => $n($g,'convidados'), 'digitais'  => $n($c,'digitais'),
        'fisicos'     => $n($c,'fisicos'),    'noivos'    => $n($c,'noivos'),
        'noivas'      => $n($c,'noivas'),     'impressos' => $n($c,'impressos'),
        'enviados'    => $n($c,'enviados'),   'parciais'  => $n($c,'parciais'),
        'confirmados' => $n($e,'confirmados'),'recusados' => $n($e,'recusados'),
        'pendentes'   => $n($e,'pendentes'),  'lug_confirm' => $n($c,'lug_confirm'),
        'presentes'   => $n($c,'presentes'),  'no_local'  => $n($c,'no_local'),
        'mesas'       => $n($mesas,'n'),      'capacidade'=> MAX_LUGARES_TOTAL,
        'pes_digitais'  => $n($c,'pes_digitais'),  'pes_fisicos' => $n($c,'pes_fisicos'),
        'pes_impressos' => $n($c,'pes_impressos'), 'pes_noivos'  => $n($c,'pes_noivos'),
        'pes_noivas'    => $n($c,'pes_noivas'),
        'pes_masculino' => $n($g,'masc'), 'pes_feminino' => $n($g,'fem'), 'pes_brinde' => $n($g,'brinde'),
        'conv_masculino'=> $n($g,'conv_masc'), 'conv_feminino' => $n($g,'conv_fem'),
        'conv_brinde'   => $n($g,'conv_brinde'),
        'pes_brinde_m'  => $n($g,'brinde_m'), 'pes_brinde_f' => $n($g,'brinde_f'),
        'pes_brinde_sg' => $n($g,'brinde_sg'),   // recebem brinde mas sem género definido
    ];
    $s['pes_confirmados'] = $s['lug_confirm'];
    // Pessoas por confirmar: lugares dos convites pendentes + lugares por confirmar
    // dos parciais + integrantes pendentes de convites de outro estado (grupos disjuntos).
    $s['pes_pendentes'] = $n($c,'lug_pendentes') + $n($c,'lug_parc_pend') + $n($g,'pend_fora');
    $s['pes_recusados'] = $n($c,'lug_recusados') + $n($g,'rec_fora');
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
                         FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id = c.id
                         WHERE " . soVivos($conn, 'c'));
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
                         WHERE " . soVivos($conn, 'c') . "
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

/**
 * Indica se uma coluna existe numa tabela (com cache por pedido).
 * Torna o código tolerante a esquemas por migrar (uploads parciais em
 * alojamento partilhado) — evita erros 500 por "Unknown column".
 */
function colunaExiste(mysqli $conn, string $tabela, string $coluna): bool {
    static $cache = [];
    $chave = $tabela . '.' . $coluna;
    if (!array_key_exists($chave, $cache)) {
        $r = @$conn->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $conn->real_escape_string($coluna) . "'");
        $cache[$chave] = ($r && $r->num_rows > 0);
    }
    return $cache[$chave];
}

/** Indica se uma mesa é a mesa (especial) dos noivos. */
function mesaEhNoivos(mysqli $conn, int $id): bool {
    global $P;
    if ($id <= 0) return false;
    $r = $conn->query("SELECT 1 FROM {$P}mesas WHERE id=$id AND especial='noivos' LIMIT 1");
    return $r && $r->num_rows > 0;
}

/**
 * Dimensões guardadas do canvas da planta (largura/altura em px), definidas
 * pelo utilizador ao arrastar as bordas. NULL = automático (por defeito).
 */
function plantaConfig(mysqli $conn): array {
    global $P;
    // bloq_* travam o arrasto (mesas) e o redimensionar (canvas), contra arrastos acidentais.
    $cfg = ['largura' => null, 'altura' => null, 'bloq_mesas' => 0, 'bloq_canvas' => 0];
    $r = @$conn->query("SELECT chave, valor FROM {$P}definicoes
                        WHERE chave IN ('planta.largura','planta.altura','planta.bloq_mesas','planta.bloq_canvas')");
    if ($r) while ($x = $r->fetch_assoc()) {
        $v = (int)$x['valor'];
        if ($x['chave'] === 'planta.largura'     && $v > 0) $cfg['largura'] = $v;
        if ($x['chave'] === 'planta.altura'      && $v > 0) $cfg['altura']  = $v;
        if ($x['chave'] === 'planta.bloq_mesas')            $cfg['bloq_mesas']  = $v === 1 ? 1 : 0;
        if ($x['chave'] === 'planta.bloq_canvas')           $cfg['bloq_canvas'] = $v === 1 ? 1 : 0;
    }
    return $cfg;
}

/**
 * Carrega um convite (por id ou código) já com os membros e o nome final.
 * Convites na reciclagem ficam invisíveis, salvo pedido expresso ($eliminados).
 */
function carregarConvite(mysqli $conn, $chave, string $por = 'id', bool $eliminados = false): ?array {
    global $P;
    $col = $por === 'codigo' ? 'codigo' : 'id';
    $vivo = $eliminados ? '1=1' : soVivos($conn, 'c');
    $st = $conn->prepare("SELECT c.*, m.nome AS mesa_nome
                          FROM {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                          WHERE c.$col=? AND $vivo LIMIT 1");
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
 * Texto das mesas de um convite. Ex.: "A (1 lugar) e B (4 lugares)".
 * $comNumero controla se aparece o "(N lugares)" ao lado de cada mesa.
 */
function textoMesas(array $lista, bool $comNumero = true): string {
    if (!$lista) return '';
    $partes = array_map(function ($m) use ($comNumero) {
        if (!$comNumero) return $m['nome'];
        $n = (int)$m['n'];
        return $m['nome'] . ' (' . $n . ' ' . ($n === 1 ? 'lugar' : 'lugares') . ')';
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
