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

/**
 * Ligação que vigia o âmbito.
 *
 * Com vários casamentos na mesma base, uma consulta esquecida sem filtro de
 * dono mostra os convidados de um casal a outro. São perto de 150 sítios a
 * tocar nas tabelas: confiar em revê-los todos a olho é confiar de mais.
 *
 * Esta ligação olha para cada instrução antes de a correr. Se ela mexe numa
 * tabela que pertence a um casamento e não menciona 'casamento_id', reclama:
 * rebenta nas provas (para a falha ser impossível de ignorar) e regista no
 * log em produção (para nunca derrubar uma página em casamento).
 */
class LigacaoAmbito extends mysqli {
    /** Tabelas cujos dados pertencem a um casamento. */
    private const TABELAS = ['convites','convidados','mesas','versoes','registo','definicoes',
                             'orcamento_categorias','orcamento_despesas','orcamento_pagamentos'];
    public static bool $vigiar = false;   // ligado só depois de o esquema estar pronto

    private function auditar(string $sql): void {
        if (!self::$vigiar) return;
        $s = ltrim($sql);
        // Só instruções de dados: o esquema e as leituras de metadados não têm
        // dono, e a própria migração corre antes de haver âmbito.
        if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $s)) return;
        $p = preg_quote(PREFIXO, '/');
        $alvo = '/\b' . $p . '(' . implode('|', self::TABELAS) . ')\b/i';
        if (!preg_match($alvo, $s, $m)) return;
        if (stripos($s, 'casamento_id') !== false) return;
        // information_schema e afins não contam.
        if (stripos($s, 'information_schema') !== false) return;
        $aviso = 'Consulta sem âmbito de casamento (tabela ' . $m[0] . '): '
               . preg_replace('/\s+/', ' ', mb_substr($s, 0, 240));
        if (defined('AMBITO_ESTRITO') && AMBITO_ESTRITO) throw new RuntimeException($aviso);
        error_log($aviso);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT) {
        $this->auditar($query);
        return parent::query($query, $result_mode);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query) {
        $this->auditar($query);
        return parent::prepare($query);
    }
}

$conn = null; $CONFIG_ATIVA = null; $ULTIMO_ERRO = '';
foreach (DB_CONFIGS as $nome => $cfg) {
    try {
        $t = @new LigacaoAmbito($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
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

// ============================================================
// Qual o casamento em causa
//
// Todo o dado pertence a um casamento. Este é o ÚNICO sítio que responde a
// "qual?", para que não haja duas respostas diferentes no mesmo pedido — é o
// que separa um sistema de vários casais de uma fuga de dados entre eles.
//
// A resposta vem, por esta ordem:
//   1. o que o pedido já fixou (usarCasamento) — é o caso do convite público,
//      onde é o código do convidado que revela de que casamento se trata;
//   2. o casamento aberto na sessão de quem entrou;
//   3. o nº 1, para o sistema continuar a servir quem tem uma instalação de um
//      casamento só e ainda não passou por um ecrã de escolha.
// ============================================================
const CASAMENTO_SISTEMA = 0;   // definições que não são de casamento nenhum

function casamentoAtual(): int {
    if (isset($GLOBALS['CASAMENTO_ID'])) return (int)$GLOBALS['CASAMENTO_ID'];
    if (isset($_SESSION['casamento_id'])) return (int)$_SESSION['casamento_id'];
    return 1;
}

/** Fixa o casamento em causa para o resto do pedido. */
function usarCasamento(int $id): void {
    $GLOBALS['CASAMENTO_ID'] = max(1, $id);
}

/**
 * Fragmento SQL que prende uma consulta ao casamento em causa. Segue o mesmo
 * idioma do soVivos(): entra no WHERE, ao lado das outras condições.
 *
 *   WHERE " . doCasamento('c') . " AND ..."
 */
function doCasamento(string $alias = ''): string {
    $col = $alias === '' ? 'casamento_id' : "$alias.casamento_id";
    return "$col = " . casamentoAtual();
}

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
const ESQUEMA_VERSAO = 27;

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

    // ---- v7: vários casamentos, vários utilizadores -----------------------
    // O sistema nasceu para um casamento só: a sua identidade estava metade no
    // config.php (constante EVENTO) e metade numa tabela de definições global.
    // Aqui abre-se para muitos — cada peça de dados passa a saber a quem
    // pertence, e as contas saem do ficheiro de configuração para a base.
    if ($versaoAtual < 7) {
        // Quem é quem. 'estado' pendente: um registo público só entra em
        // funcionamento depois de o admin o aprovar.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}casamentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(160) NOT NULL,
                noiva VARCHAR(80) DEFAULT NULL,
                noivo VARCHAR(80) DEFAULT NULL,
                data_evento DATE DEFAULT NULL,
                estado ENUM('pendente','ativo','suspenso','arquivado') NOT NULL DEFAULT 'pendente',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cas_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}utilizadores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                nome VARCHAR(120) DEFAULT NULL,
                senha_hash VARCHAR(255) NOT NULL,
                -- NULL = utilizador comum (noivos/porteiro). Só o pessoal da
                -- plataforma tem papel aqui.
                papel_plataforma ENUM('admin','suporte') DEFAULT NULL,
                estado ENUM('pendente','ativo','suspenso') NOT NULL DEFAULT 'pendente',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ultimo_acesso TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_util_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Quem entra em que casamento, e como. Um utilizador pode ser noivo de
        // um casamento e porteiro de outro.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}acessos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                utilizador_id INT NOT NULL,
                casamento_id INT NOT NULL,
                papel ENUM('noivos','porteiro') NOT NULL DEFAULT 'noivos',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_acesso (utilizador_id, casamento_id),
                INDEX idx_acesso_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // O suporte não entra por direito próprio: entra com um código que o
        // casal gera e pode revogar, e que diz se pode só ver ou também mexer.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}suporte_codigos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                codigo VARCHAR(16) NOT NULL UNIQUE,
                pode_corrigir TINYINT(1) NOT NULL DEFAULT 0,
                criado_por INT DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expira_em DATETIME DEFAULT NULL,
                usado_por INT DEFAULT NULL,
                usado_em DATETIME NULL DEFAULT NULL,
                revogado_em DATETIME NULL DEFAULT NULL,
                INDEX idx_sup_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- cada peça de dados passa a saber de quem é ----
        foreach (['convites', 'convidados', 'mesas', 'versoes', 'registo'] as $t) {
            migColuna($conn, "{$P}$t", 'casamento_id', "INT NOT NULL DEFAULT 1");
            migIndice($conn, "{$P}$t", "idx_{$t}_cas", 'casamento_id');
        }

        // ---- o casamento que já existia passa a ser o nº 1 ----
        // Só se HÁ um casamento anterior para preservar: uma instalação de
        // casamento único a passar para multi-casamento tem convidados, mesas ou
        // convites de quem já lá estava, e esses continuam a ser o casamento nº1.
        // Uma instalação NOVA e vazia nasce sem casamento nenhum — o admin cria o
        // primeiro; não se inventa um casal de origem que ninguém pediu.
        $r = @$conn->query("SELECT COUNT(*) FROM {$P}casamentos");
        $jaHa = $r ? (int)$r->fetch_row()[0] : 0;
        $temDadosAntigos = false;
        foreach (['convites', 'convidados', 'mesas'] as $t) {
            $rc = @$conn->query("SELECT 1 FROM {$P}$t LIMIT 1");
            if ($rc && $rc->num_rows) { $temDadosAntigos = true; break; }
        }
        if ($jaHa === 0 && $temDadosAntigos) {
            $lerDef = function (string $chave, string $fallback) use ($conn, $P) {
                $st = $conn->prepare("SELECT valor FROM {$P}definicoes WHERE chave=? LIMIT 1");
                if (!$st) return $fallback;
                $st->bind_param('s', $chave); $st->execute();
                $x = $st->get_result()->fetch_assoc();
                return ($x && $x['valor'] !== '') ? $x['valor'] : $fallback;
            };
            // A identidade estava repartida: o que o utilizador já tinha
            // gravado manda; o resto vem da constante EVENTO do config.php.
            $noiva = $lerDef('casal.noiva', EVENTO['noiva']);
            $noivo = $lerDef('casal.noivo', EVENTO['noivo']);
            $data  = $lerDef('evento.data', EVENTO['data_iso']);
            $nome  = trim($noiva . ' & ' . $noivo);
            $st = $conn->prepare("INSERT INTO {$P}casamentos (id, nome, noiva, noivo, data_evento, estado)
                                  VALUES (1, ?, ?, ?, ?, 'ativo')");
            $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
            @$st->execute();
        }

        // O nome da mesa era único em toda a tabela: dois casais não podiam
        // ambos ter uma "Mesa 1". Passa a ser único dentro de cada casamento.
        $ru = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='nome'");
        if ($ru && $ru->num_rows) @$conn->query("ALTER TABLE {$P}mesas DROP INDEX nome");
        $ru2 = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='uq_mesa_nome'");
        if ($ru2 && $ru2->num_rows === 0) {
            @$conn->query("ALTER TABLE {$P}mesas ADD UNIQUE KEY uq_mesa_nome (casamento_id, nome)");
        }

        // ---- as definições passam a ser por casamento ----
        // O 0 fica reservado ao sistema (schema.versao, noivos.criada): não
        // pertencem a casamento nenhum e não podem viajar com eles.
        migColuna($conn, "{$P}definicoes", 'casamento_id', "INT NOT NULL DEFAULT 1");
        @$conn->query("UPDATE {$P}definicoes SET casamento_id=0
                       WHERE chave IN ('schema.versao','noivos.criada')");
        // A chave deixa de bastar sozinha: 'tema.paleta' existe uma vez por
        // casamento. Troca-se a chave primária por (casamento, chave).
        $rk = @$conn->query("SHOW KEYS FROM {$P}definicoes WHERE Key_name='PRIMARY'");
        if ($rk && $rk->num_rows === 1) {   // ainda é só a coluna 'chave'
            @$conn->query("ALTER TABLE {$P}definicoes DROP PRIMARY KEY,
                           ADD PRIMARY KEY (casamento_id, chave)");
        }

        // ---- as contas saem do config.local.php para a base ----
        // Corre uma só vez: depois disto, gerem-se pela aplicação.
        $r = @$conn->query("SELECT COUNT(*) FROM {$P}utilizadores");
        if ($r && (int)$r->fetch_row()[0] === 0) {
            foreach (UTILIZADORES as $u) {
                if (!is_array($u) || empty($u['utilizador'])) continue;
                $nomeU = trim((string)$u['utilizador']);
                // Sem email no formato antigo: compõe-se um, que o utilizador
                // troca depois. O importante é não perder o acesso.
                $email = filter_var($nomeU, FILTER_VALIDATE_EMAIL) ? $nomeU : $nomeU . '@local';
                $hash  = !empty($u['senha_hash']) ? (string)$u['senha_hash']
                       : password_hash((string)($u['senha'] ?? bin2hex(random_bytes(8))), PASSWORD_DEFAULT);
                $ehAdmin = ($u['papel'] ?? '') === 'admin';
                $plat  = $ehAdmin ? 'admin' : null;
                $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, papel_plataforma, estado)
                                      VALUES (?,?,?,?,'ativo')");
                $st->bind_param('ssss', $email, $nomeU, $hash, $plat);
                if (!@$st->execute()) continue;
                $uid = $conn->insert_id;
                // Se o casamento nº1 existir (instalação antiga, com dados a
                // preservar), o admin fica seu dono e o porteiro seu porteiro. Numa
                // instalação nova não há casamento nenhum a que dar lugar — as
                // contas nascem sem festa, e o admin abre a primeira quando quiser.
                $r1 = @$conn->query("SELECT 1 FROM {$P}casamentos WHERE id=1 LIMIT 1");
                if ($r1 && $r1->num_rows) {
                    $papel = $ehAdmin ? 'noivos' : 'porteiro';
                    $st = $conn->prepare("INSERT IGNORE INTO {$P}acessos (utilizador_id, casamento_id, papel)
                                          VALUES (?, 1, ?)");
                    $st->bind_param('is', $uid, $papel);
                    @$st->execute();
                }
            }
        }
    }

    // ---- v8: o endereço público de cada casamento -------------------------
    // Os QR e os links dos convites são absolutos e, uma vez impressos, são
    // para sempre. Até aqui saíam do pedido em curso (base_url()): quem
    // preparasse os cartões a partir de um endereço de testes levava para a
    // gráfica um QR que aponta para uma máquina que o convidado nunca alcança.
    //
    // Fica ao lado do casamento, e não nas definições, de propósito: as
    // definições viajam nas versões do convite, e repor uma versão antiga não
    // pode mudar o endereço para onde apontam convites já entregues.
    if ($versaoAtual < 8) {
        migColuna($conn, "{$P}casamentos", 'endereco_publico', "VARCHAR(200) NOT NULL DEFAULT ''");
    }

    // ---- v9: o admin da plataforma não é nenhum dos casais ----------------
    // A v7 trouxe o 'admin' do config.local.php para a base e deu-lhe, além do
    // papel de plataforma, um lugar de NOIVOS no casamento nº1 — porque nesse
    // mundo de um casamento só ele era, de facto, o casal.
    //
    // Com a casa a servir vários, esse lugar passou a mentir: punha quem
    // responde pela plataforma dentro da equipa de um casal, na lista de quem
    // gere aquele casamento, como se fosse da família.
    //
    // Tira-se o lugar, não o acesso: o admin continua a chegar a todos os
    // casamentos por ser quem é (ver casamentosDoUtilizador), e a diferença é
    // que o sistema passa a dizer com que título lá entra. Se um casamento
    // precisar mesmo de uma conta de noivos, dá-se-lhe uma em Gestão — e a
    // página avisa quando não há nenhuma.
    if ($versaoAtual < 9) {
        @$conn->query("DELETE a FROM {$P}acessos a
                       JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                       WHERE u.papel_plataforma = 'admin'");
    }

    // ---- v10: contas paradas por o casamento ter sido arquivado -----------
    // 'suspenso' é uma decisão sobre a PESSOA — alguém decidiu fechar-lhe a
    // porta. Uma conta que para porque o casamento acabou não é a mesma coisa,
    // e tratá-las pelo mesmo nome tirava a única forma de as distinguir depois:
    // ao reabrir um casamento, quem se reativa é quem parou com ele, e não
    // quem foi suspenso por outra razão qualquer.
    if ($versaoAtual < 10) {
        @$conn->query("ALTER TABLE {$P}utilizadores
                       MODIFY estado ENUM('pendente','ativo','suspenso','inativo')
                       NOT NULL DEFAULT 'pendente'");
    }

    // ---- v11: a hora da cerimónia é do evento, não do cartão --------------
    // Estava em 'cartao.civil_hora', e por isso viajava nas versões do cartão
    // impresso: repor um desenho antigo mudava a HORA a que as pessoas se
    // apresentam na igreja. A hora é um facto do casamento; o desenho lê-a.
    if ($versaoAtual < 11) {
        // Copia-se em vez de renomear: a chave nova pode já existir nalgum
        // casamento, e a chave primária é (casamento, chave) — um rename cego
        // rebentava aí e deixava metade dos casamentos por migrar.
        @$conn->query("INSERT IGNORE INTO {$P}definicoes (casamento_id, chave, valor)
                       SELECT casamento_id, 'evento.civil_hora', valor
                       FROM {$P}definicoes WHERE chave='cartao.civil_hora'");
        // As versões guardadas do cartão levaram a chave antiga lá dentro; não
        // se reescrevem (são fotografias do que foi), mas a chave já não existe
        // e o instantâneo do âmbito deixa de a ver — que é o que se quer.
        @$conn->query("DELETE FROM {$P}definicoes WHERE chave='cartao.civil_hora'");
    }

    // ---- v12: quando é que se trabalhou neste casamento pela última vez ----
    // A lista da administração ordenava-se pelo número, que é a ordem por que
    // foram criados — a menos útil de todas. Quem abre a página de manhã quer
    // ver em cima aquilo em que andou ontem.
    if ($versaoAtual < 12) {
        migColuna($conn, "{$P}casamentos", 'ultimo_acesso', "DATETIME NULL DEFAULT NULL");
    }

    // ---- v13: modelos de convite da casa ----------------------------------
    // As versões (cw_versoes) são de cada casamento: o desenho que ESTE casal
    // guardou. Faltava o outro lado — os desenhos que a casa oferece a todos,
    // para um casal começar de um convite bonito em vez de uma folha em branco.
    //
    // Ficam à parte, e sem casamento_id, porque não são de casamento nenhum:
    // são da plataforma. Aplicar um modelo é copiá-lo para as definições do
    // casamento — a partir daí é dele, e mexer no modelo já não lhe toca.
    if ($versaoAtual < 13) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}modelos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(120) NOT NULL,
                descricao VARCHAR(400) DEFAULT NULL,
                ambito ENUM('digital','impresso') NOT NULL DEFAULT 'digital',
                defs MEDIUMTEXT NOT NULL,
                visivel TINYINT(1) NOT NULL DEFAULT 1,
                criado_por VARCHAR(120) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL DEFAULT NULL,
                INDEX idx_modelo_ambito (ambito, visivel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // v14 — o título da cerimónia civil passou de 'cartao.civil_titulo' para
    // 'evento.civil_titulo'. As duas peças anunciam a mesma cerimónia: com o
    // título fechado no âmbito do impresso, o convite digital não lhe podia
    // tocar e cada peça acabaria a dizer o seu. Quem já o tinha escrito
    // à mão não o perde.
    if ($versaoAtual < 14) {
        @$conn->query("UPDATE IGNORE {$P}definicoes SET chave='evento.civil_titulo'
                        WHERE chave='cartao.civil_titulo'");
        @$conn->query("DELETE FROM {$P}definicoes WHERE chave='cartao.civil_titulo'");
    }

    // v15 — os modelos de origem da casa. A lista de modelos começava vazia, e
    // o desenho que o sistema traz (o convite impresso e o digital tal como são
    // de origem) não constava dela: para o ter, o admin tinha de fazer um
    // "novo modelo do zero". Passa a estar lá desde o início, um por peça.
    //
    // defs vazio, de propósito: um modelo assenta sobre o desenho de origem e
    // guarda só o que muda — sem nada guardado, é o próprio desenho de origem,
    // e acompanha-o se ele mudar. Marcados com criado_por='sistema' para não os
    // duplicar; se o admin os apagar, é escolha dele e não voltam.
    if ($versaoAtual < 15) {
        foreach ([['impresso', 'Convite impresso (modelo da casa)'],
                  ['digital',  'Convite digital (modelo da casa)']] as [$amb, $nome]) {
            $existe = @$conn->query("SELECT 1 FROM {$P}modelos
                                     WHERE ambito='" . $conn->real_escape_string($amb) . "'
                                     AND criado_por='sistema' LIMIT 1");
            if ($existe && $existe->num_rows) continue;
            $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                                  VALUES (?, ?, ?, '{}', 1, 'sistema')");
            $desc = 'O desenho que o sistema traz de origem — o ponto de partida da casa.';
            $st->bind_param('sss', $nome, $desc, $amb);
            @$st->execute();
        }
    }

    // v16 — visibilidade dos modelos por casamento. Até aqui um modelo era de
    // todos (visivel=1) ou de ninguém (rascunho). Passa a poder ser de alguns:
    // 'alcance' diz se um modelo publicado se vê em todos os casamentos ou só
    // nos escolhidos, e a tabela de junção diz quais.
    if ($versaoAtual < 16) {
        migColuna($conn, "{$P}modelos", 'alcance',
                  "ENUM('todos','selecionados') NOT NULL DEFAULT 'todos'");
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}modelo_casamentos (
                modelo_id INT NOT NULL,
                casamento_id INT NOT NULL,
                PRIMARY KEY (modelo_id, casamento_id),
                INDEX idx_mc_casamento (casamento_id),
                FOREIGN KEY (modelo_id) REFERENCES {$P}modelos(id) ON DELETE CASCADE,
                FOREIGN KEY (casamento_id) REFERENCES {$P}casamentos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // v19 — desfaz a v18, que mudou as fotografias de origem do convite para
    // assets/convite/casal/. A troca das imagens de origem por desenho da casa
    // passou a valer só para os modelos NOVOS (ver exemploDeFabrica), e não
    // para o produto inteiro, por isso as fotografias voltaram ao sítio de
    // sempre. Quem já tinha corrido a v18 ficou a apontar para uma pasta que já
    // não existe: as linhas com o caminho de lá saem, e cada casamento volta a
    // valer o de origem — que é exatamente o mesmo ficheiro.
    //
    // (As versões 17 e 18 não existem mais: uma reescrevia os modelos já
    // guardados, o que era o erro que esta desfaz.)
    if ($versaoAtual < 19) {
        @$conn->query("DELETE FROM {$P}definicoes
                       WHERE chave IN ('media.hero','media.historia','media.interludio','media.acesso')
                       AND valor LIKE 'assets/convite/casal/%'");
    }

    // v20 — modelos da casa que sejam MESMO outros desenhos.
    //
    // A v15 semeou dois: "Convite digital (modelo da casa)" e o impresso, ambos
    // com defs vazio. Um modelo vazio É o desenho de origem — e por isso aplicar
    // qualquer deles devolvia a peça à origem, que passa a coincidir com a
    // versão "Original". Um casal via dois modelos, escolhia um, e ficava sempre
    // com "Original em vigor". Era a queixa de "não consigo pôr outro modelo em
    // vigor", e tinha razão: não havia outro. Havia a origem, com dois nomes.
    //
    // Semeiam-se agora variações a sério, uma por paleta que o sistema já tem.
    if ($versaoAtual < 20) {
        // personalizacao.php só depende do config.php: pode entrar aqui sem
        // circularidade, e assim as paletas não se duplicam nesta migração.
        require_once __DIR__ . '/personalizacao.php';

        // Os dois de origem passam a dizer o que são. Só se o nome for ainda o
        // semeado — um nome que o admin tenha mudado é dele.
        foreach ([['digital', 'Convite digital (modelo da casa)', 'Desenho de origem · convite digital'],
                  ['impresso', 'Convite impresso (modelo da casa)', 'Desenho de origem · convite impresso']]
                 as [$amb, $velho, $novo]) {
            $st = $conn->prepare("UPDATE {$P}modelos SET nome=?,
                                  descricao='O ponto de partida da casa, e o caminho de volta: aplicá-lo devolve a peça ao desenho de origem.'
                                  WHERE nome=? AND ambito=? AND criado_por='sistema'");
            $st->bind_param('sss', $novo, $velho, $amb); @$st->execute();
        }

        $ins = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, alcance, criado_por)
                               VALUES (?,?,?,?,1,'todos','sistema')");
        $jaLa = function (string $nome, string $amb) use ($conn, $P): bool {
            $st = $conn->prepare("SELECT 1 FROM {$P}modelos WHERE nome=? AND ambito=? LIMIT 1");
            $st->bind_param('ss', $nome, $amb); $st->execute();
            return (bool)$st->get_result()->fetch_row();
        };

        // ---- convite digital: uma paleta por modelo ----
        $temas = temasPredef();
        foreach ([['borgonha',  'Borgonha',   'Vinho profundo e ouro velho, para uma festa à noite.'],
                  ['meianoite', 'Meia-noite', 'Azul de fim de tarde, sóbrio e formal.'],
                  ['terracota', 'Terracota',  'Barro e areia, para uma festa ao ar livre.']]
                 as [$k, $nome, $desc]) {
            if (!isset($temas[$k]) || $jaLa($nome, 'digital')) continue;
            $paleta = [];
            foreach (TEMA_VARS_EDITAVEIS as $v) {
                if (isset($temas[$k][$v])) $paleta[$v] = strtoupper($temas[$k][$v]);
            }
            $defs = json_encode(['tema.paleta' => json_encode($paleta, JSON_UNESCAPED_SLASHES)],
                                JSON_UNESCAPED_UNICODE);
            $amb = 'digital';
            $ins->bind_param('ssss', $nome, $desc, $amb, $defs); @$ins->execute();
        }

        // ---- convite impresso: paleta e folhagem, que é o que ali se vê ----
        foreach ([['salvia',    'eucalipto', 'coracao',   'Sálvia',    'Verde acinzentado e folha de oliveira.'],
                  ['terracota', 'florido',   'losango',   'Terracota', 'Barro quente, com folhagem florida.'],
                  ['rosa',      'feto',      'comercial', 'Rosa velho','Rosa fumado e feto, para um cartão mais leve.']]
                 as [$pal, $folha, $elo, $nome, $desc]) {
            if ($jaLa($nome, 'impresso')) continue;
            $defs = json_encode(['cartao.paleta' => $pal, 'cartao.folhagem' => $folha,
                                 'cartao.elo' => $elo], JSON_UNESCAPED_UNICODE);
            $amb = 'impresso';
            $ins->bind_param('ssss', $nome, $desc, $amb, $defs); @$ins->execute();
        }
    }

    // v21 — o orçamento do casamento.
    //
    // Um módulo à parte, para os noivos verem o CURSO das despesas: quanto se
    // planeou, quanto se contratou, quanto já saiu, e o que falta pagar até ao
    // dia. Três tabelas, todas com dono (casamento_id) e vigiadas como as
    // outras — um casal nunca vê as contas de outro.
    //
    // O teto e a moeda não são tabela: vivem em cw_definicoes ('orcamento.total',
    // 'orcamento.moeda'), que já viaja no retrato do casamento. Assim o
    // orçamento inteiro entra e sai com o export/import sem tratamento especial
    // para os ajustes.
    if ($versaoAtual < 21) {
        // As gavetas do dinheiro. 'previsto' é o que se planeia gastar em cada
        // uma; a soma dá o orçamento por baixo, quando não há teto à mão.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_categorias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                nome VARCHAR(80) NOT NULL,
                previsto DECIMAL(12,2) NOT NULL DEFAULT 0,
                ordem INT NOT NULL DEFAULT 0,
                cor VARCHAR(7) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_orccat_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Cada compromisso, uma linha. O 'estado' tem só dois valores: previsto
        // (ainda por pagar) e pago (saiu por inteiro). Uma despesa prevista com
        // prestações já pagas conta o que dessas saiu como pago — ver
        // orcamentoResumo(). A categoria some por SET NULL: apagar uma gaveta
        // não apaga a despesa.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_despesas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                categoria_id INT DEFAULT NULL,
                descricao VARCHAR(160) NOT NULL,
                fornecedor VARCHAR(120) DEFAULT NULL,
                valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                estado ENUM('previsto','pago') NOT NULL DEFAULT 'previsto',
                nota VARCHAR(255) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_orcdesp_cas (casamento_id),
                INDEX idx_orcdesp_cat (categoria_id),
                FOREIGN KEY (categoria_id) REFERENCES {$P}orcamento_categorias(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // As saídas no tempo: sinal e prestações. É a data_prevista que ordena
        // o calendário — o \"curso\" no tempo, o que é preciso ter em caixa e
        // quando. Apagar a despesa leva as suas parcelas (CASCADE).
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_pagamentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                despesa_id INT NOT NULL,
                valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                data_prevista DATE DEFAULT NULL,
                pago_em DATE DEFAULT NULL,
                nota VARCHAR(160) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_orcpag_cas (casamento_id),
                INDEX idx_orcpag_desp (despesa_id),
                FOREIGN KEY (despesa_id) REFERENCES {$P}orcamento_despesas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Um conjunto de gavetas sensato à partida, para o casal não começar
        // numa folha em branco. São editáveis e apagáveis — cada festa gasta à
        // sua maneira. Semeadas no casamento que já existe (o nº 1); os novos
        // recebem-nas ao serem criados (ver api.php casamento_criar).
        semearOrcamento($conn, 1);
    }

    // v22 — o primeiro modelo deixa de ser especial.
    //
    // Os dois modelos que o sistema semeou (v15/v20) eram "Desenho de origem"
    // com defs vazio: um caso à parte, e as suas fotografias (o convite Isabel
    // & Abednego) viviam soltas em assets/convite/, longe das dos outros
    // modelos. Passam a ser modelos normais — nome próprio (Isabel & Abednego)
    // e defs completas, como um modelo do admin. As fotografias já foram
    // realojadas na galeria (no repositório e no defsPadrao); aqui trata-se só
    // dos dados.
    if ($versaoAtual < 22) {
        require_once __DIR__ . '/personalizacao.php';

        // Um retrato completo do desenho de origem de cada peça, no mesmo
        // formato de um modelo do admin (chavesModelo). As chaves de média já
        // apontam para a galeria, porque vêm do defsPadrao novo.
        $padrao = defsPadrao();
        foreach (['digital', 'impresso'] as $amb) {
            $snap = [];
            foreach (chavesModelo($amb) as $k) $snap[$k] = (string)($padrao[$k] ?? '');
            $defs = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // Só se ainda for o modelo semeado com o nome antigo — um nome que o
            // admin já tenha mudado é dele.
            $st = $conn->prepare("UPDATE {$P}modelos SET nome='Isabel & Abednego', defs=?
                                  WHERE ambito=? AND criado_por='sistema' AND nome LIKE 'Desenho de origem%'");
            $st->bind_param('ss', $defs, $amb); @$st->execute();
        }

        // Quem tinha escolhido à mão a foto de origem fica a apontar para o
        // sítio novo, para o convite não ficar com uma imagem partida.
        $realojar = [
            'media.hero'       => ['assets/convite/hero.jpg',       'assets/convite/galeria/capa-isabel-abednego.jpg'],
            'media.historia'   => ['assets/convite/historia.jpg',   'assets/convite/galeria/historia-isabel-abednego.jpg'],
            'media.interludio' => ['assets/convite/interludio.jpg', 'assets/convite/galeria/interludio-isabel-abednego.jpg'],
            'media.acesso'     => ['assets/convite/acesso.jpg',     'assets/convite/galeria/acesso-isabel-abednego.jpg'],
        ];
        foreach ($realojar as $chave => [$velho, $novo]) {
            $st = $conn->prepare("UPDATE {$P}definicoes SET valor=? WHERE chave=? AND valor=?");
            $st->bind_param('sss', $novo, $chave, $velho); @$st->execute();
        }
    }

    // v23 — a «peça de origem» passa a ser um modelo designado.
    //
    // Antes, o ponto de regresso de cada peça achava-se pelo DESENHO (o modelo
    // da casa cujo desenho era o de fábrica). Bastava o admin retocar esse
    // modelo para o desenho deixar de bater certo e a peça voltar a chamar-se
    // «Original». Agora o admin designa qual modelo é a peça de origem
    // (modelo.pecaorigem.<âmbito>, no 0), e semeia-se com o Isabel & Abednego —
    // o que já era a origem — para nada mudar de comportamento à partida.
    if ($versaoAtual < 23) {
        foreach (['digital', 'impresso'] as $amb) {
            $chave = 'modelo.pecaorigem.' . $amb;
            // Só se ainda não houver designação — uma escolha do admin é dele.
            $st = $conn->prepare("SELECT 1 FROM {$P}definicoes WHERE casamento_id=0 AND chave=? LIMIT 1");
            $st->bind_param('s', $chave); @$st->execute();
            if ($st->get_result()->fetch_row()) continue;
            // O modelo de origem: o Isabel & Abednego semeado (criado_por='sistema').
            $st = $conn->prepare("SELECT id FROM {$P}modelos
                                  WHERE ambito=? AND criado_por='sistema' AND nome='Isabel & Abednego'
                                  ORDER BY id LIMIT 1");
            $st->bind_param('s', $amb); @$st->execute();
            $r = $st->get_result()->fetch_row();
            if (!$r) continue;
            $id = (string)(int)$r[0];
            $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,?,?)
                                  ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $st->bind_param('ss', $chave, $id); @$st->execute();
        }
    }

    // v24 — a licença de uso de cada casamento.
    //
    // Um casamento passa a ter um período de uso: quantos meses a licença dura
    // ('licenca_meses', 0 = sem limite) e a data em que expira ('licenca_ate',
    // NULL = ilimitada ou ainda por iniciar). O relógio começa quando o
    // casamento fica ativo (aprovação, ou criação já ativa) — ver casamento_estado
    // e casamento_criar. Expirada a licença, o casamento é suspenso sozinho, e
    // com ele as contas que dele dependem (ver suspenderLicencasExpiradas).
    if ($versaoAtual < 24) {
        migColuna($conn, "{$P}casamentos", 'licenca_meses', "INT NOT NULL DEFAULT 0");
        migColuna($conn, "{$P}casamentos", 'licenca_ate',   "DATE DEFAULT NULL");
        migIndice($conn, "{$P}casamentos", 'idx_cas_licenca', 'licenca_ate');
    }

    // v25 — a fatura de cada despesa (foto ou PDF).
    //
    // Uma despesa passa a poder levar anexada a sua fatura/recibo: guarda-se o
    // caminho do ficheiro (em assets/faturas/<casamento>/), e o resto — abrir,
    // trocar, apagar — trata-se na API (ver orc_despesa_fatura).
    if ($versaoAtual < 25) {
        migColuna($conn, "{$P}orcamento_despesas", 'fatura', "VARCHAR(255) DEFAULT NULL");
    }

    // v26 — o orçamento passa a ter só dois estados: previsto e pago.
    //
    // O 'contratado' desapareceu: era um meio-termo que não dizia nada de novo à
    // conta (o que importa é o que ainda falta pagar e o que já saiu). As
    // despesas que estavam contratadas voltam a previstas, e o ENUM encolhe para
    // os dois estados. As prestações já pagas de uma despesa prevista contam,
    // daqui para a frente, como pago (ver orcamentoResumo).
    if ($versaoAtual < 26) {
        @$conn->query("UPDATE {$P}orcamento_despesas SET estado='previsto' WHERE estado='contratado'");
        @$conn->query("ALTER TABLE {$P}orcamento_despesas
                       MODIFY estado ENUM('previsto','pago') NOT NULL DEFAULT 'previsto'");
    }

    // v27 — cada categoria pode guardar a sua cor. A cor continua a ser
    // sugerida automaticamente (paleta estável por categoria, no ecrã); esta
    // coluna guarda a escolha do casal quando ele a troca. NULL = a sugerida.
    if ($versaoAtual < 27) {
        migColuna($conn, "{$P}orcamento_categorias", 'cor', "VARCHAR(7) DEFAULT NULL");
    }

    // A versão do esquema é do sistema, não de um casamento: vive no 0.
    @$conn->query("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,'schema.versao','" . ESQUEMA_VERSAO . "')
                   ON DUPLICATE KEY UPDATE valor='" . ESQUEMA_VERSAO . "'");
}

// ---- Semente de DEMONSTRAÇÃO (só desenvolvimento/testes) --------------------
// A instalação de origem nasce sem casamento nenhum — é o admin que cria o
// primeiro. Mas o ambiente de desenvolvimento e a suite de provas contam com um
// casamento de trabalho (o nº1, «Isabel & Abednego», com a mesa dos noivos).
// Liga-se com 'semear_demo' => true no config.local.php; nunca no produto (o
// config.local.example.php não o traz). Idempotente: só cria se não houver
// casamento nenhum.
if (cfg_local('semear_demo', false)) {
    $rq = @$conn->query("SELECT COUNT(*) FROM {$P}casamentos");
    if ($rq && (int)$rq->fetch_row()[0] === 0) {
        $noiva = EVENTO['noiva']; $noivo = EVENTO['noivo']; $data = EVENTO['data_iso'];
        $nome  = trim($noiva . ' & ' . $noivo);
        $st = @$conn->prepare("INSERT INTO {$P}casamentos (id, nome, noiva, noivo, data_evento, estado)
                               VALUES (1, ?, ?, ?, ?, 'ativo')");
        if ($st) { $st->bind_param('ssss', $nome, $noiva, $noivo, $data); @$st->execute(); }
        // A mesa dos noivos, como qualquer casamento tem.
        @$conn->query("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y)
                       VALUES (1,'Noivos',2,'redonda','ouro','noivos',50,42)");
        // Uma conta de porteiro do casamento de demonstração (a suite conta com
        // ela; não existe no produto). Só se não houver já uma com este email.
        $rp = @$conn->query("SELECT 1 FROM {$P}utilizadores WHERE email='porteiro@local' LIMIT 1");
        if ($rp && $rp->num_rows === 0) {
            $hash = password_hash('noivos2026', PASSWORD_DEFAULT);
            $pu = @$conn->prepare("INSERT INTO {$P}utilizadores (email,nome,senha_hash,papel_plataforma,estado)
                                   VALUES ('porteiro@local','Porteiro',?,NULL,'ativo')");
            if ($pu) {
                $pu->bind_param('s', $hash);
                if (@$pu->execute()) {
                    $puid = $conn->insert_id;
                    @$conn->query("INSERT INTO {$P}acessos (utilizador_id,casamento_id,papel)
                                   VALUES ($puid,1,'porteiro')");
                }
            }
        }
        // Um convite de exemplo com convidados (sem papel), para as provas que
        // precisam de um convite a sério (código) ou de cobaias.
        $cod = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        $ci = @$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,tipo,lado,lugares)
                               VALUES (1,?,?,'ambos','noiva',3)");
        if ($ci) {
            $nomeConv = 'Família Exemplo';
            $ci->bind_param('ss', $cod, $nomeConv); @$ci->execute();
            $convId = $conn->insert_id;
            $i = 0;
            foreach (['Convidado Um', 'Convidada Dois', 'Convidado Três'] as $nm) {
                $gi = @$conn->prepare("INSERT INTO {$P}convidados (casamento_id,convite_id,nome,principal)
                                       VALUES (1,?,?,?)");
                if ($gi) { $pr = $i === 0 ? 1 : 0; $gi->bind_param('isi', $convId, $nm, $pr); @$gi->execute(); }
                $i++;
            }
        }
    }
}

// A partir daqui o esquema está pronto e todo o dado tem dono: a ligação passa
// a reclamar de qualquer consulta que mexa numa tabela de casamento sem dizer
// de qual. Em provas rebenta; em produção fica no log.
LigacaoAmbito::$vigiar = true;

// A mesa (especial) dos noivos existe por padrão: cria-se UMA vez (primeira utilização).
// Depois fica eliminável — não volta a ser recriada automaticamente (repõe-se no botão da planta).
$flag = $conn->query("SELECT valor FROM {$P}definicoes WHERE chave='noivos.criada' AND casamento_id=0 LIMIT 1");
if ($flag && $flag->num_rows === 0) {
    $rn = $conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1");
    if ($rn && $rn->num_rows === 0) {
        $nomeN = 'Noivos'; $n = 2;
        while ($conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome='" . $conn->real_escape_string($nomeN) . "'")->num_rows) $nomeN = 'Noivos ' . $n++;
        $stN = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (" . casamentoAtual() . ",?,2,'redonda','ouro','noivos',50,42)");
        $stN->bind_param('s', $nomeN); $stN->execute();
    }
    $conn->query("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,'noivos.criada','1') ON DUPLICATE KEY UPDATE valor='1'");
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
    $st = @$conn->prepare("INSERT INTO {$P}registo (casamento_id,utilizador,papel,accao,alvo,detalhe) VALUES (" . casamentoAtual() . ",?,?,?,?,?)");
    if (!$st) return;
    $alvo = substr($alvo, 0, 120); $detalhe = substr($detalhe, 0, 255);
    $st->bind_param('sssss', $u, $p, $accao, $alvo, $detalhe);
    @$st->execute();
}

/** Os temas que o sistema oferece (chave => rótulo). O 1.º é o padrão. */
function temasDisponiveis(): array {
    return [
        'niras'    => 'NIRAS',
        'classico' => 'Clássico',
        'azul'     => 'Azul corporativo',
        'escuro'   => 'Escuro',
    ];
}

/** Amostras de cor e uma nota de cada tema — para o seletor e as Definições. */
function temasAmostras(): array {
    return [
        'niras'    => ['cores' => ['#16283A', '#63B22B', '#F6F8F4'], 'desc' => 'Azul-noite + verde institucional.'],
        'classico' => ['cores' => ['#2C4536', '#B4864A', '#FBF8F1'], 'desc' => 'Verde-floresta, dourado e marfim.'],
        'azul'     => ['cores' => ['#123C63', '#2E86C8', '#F4F7FB'], 'desc' => 'Azul corporativo, claro.'],
        'escuro'   => ['cores' => ['#0E1B25', '#8AD24A', '#17232C'], 'desc' => 'Grafite escuro, acento verde.'],
    ];
}

/**
 * O tema escolhido para o sistema — uma definição da casa (casamento_id=0),
 * que o admin controla. Vale para toda a gente e todas as páginas. Sem escolha,
 * é o NIRAS. Lê-se uma vez por pedido.
 */
function temaSistema(): string {
    static $t = null;
    if ($t !== null) return $t;
    $t = 'niras';
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $r = @$conn->query("SELECT valor FROM " . PREFIXO . "definicoes WHERE casamento_id=0 AND chave='sistema.tema' LIMIT 1");
        if ($r && ($row = $r->fetch_row())) {
            $v = (string)$row[0];
            if (isset(temasDisponiveis()[$v])) $t = $v;
        }
    }
    return $t;
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

/**
 * O endereço por onde os convidados deste casamento chegam.
 *
 * Se o casal (ou o pessoal da casa) tiver fixado um, é esse — e é esse que vai
 * nos QR, nos links partilhados e no PDF. Sem nada fixado, deduz-se do pedido
 * em curso, que é o que sempre se fez e serve bem quando há um só endereço.
 */
function enderecoPublico(?int $casamentoId = null): string {
    global $conn, $P;
    static $cache = [];
    $id = $casamentoId ?? casamentoAtual();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = '';
        if ($id > 0 && isset($conn)) {
            $r = @$conn->query("SELECT endereco_publico FROM {$P}casamentos WHERE id=" . (int)$id . " LIMIT 1");
            if ($r && ($x = $r->fetch_assoc())) $cache[$id] = rtrim((string)$x['endereco_publico'], '/');
        }
    }
    return $cache[$id] !== '' ? $cache[$id] : base_url();
}

/**
 * Um endereço que só existe na máquina de quem o está a ver.
 *
 * Serve para avisar antes de imprimir: um QR para 127.0.0.1 ou para a rede de
 * casa não abre no telemóvel de ninguém, e no papel já não há emenda.
 */
function enderecoSoLocal(string $url): bool {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($h === '') return true;
    return $h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.localhost')
        || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.|\[?::1)/', $h) === 1;
}

/** Aceita um endereço público escrito à mão. Devolve null se não servir. */
function limparEndereco(string $v): ?string {
    $v = trim($v);
    if ($v === '') return '';                       // vazio = voltar a deduzir do pedido
    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    $v = rtrim($v, '/');
    if (mb_strlen($v) > 200) return null;
    $p = parse_url($v);
    if (!$p || empty($p['host']) || str_contains($v, ' ')) return null;
    if (!empty($p['query']) || !empty($p['fragment'])) return null;
    return $v;
}

/** Código único e legível (sem caracteres ambíguos). */
function gerarCodigo(mysqli $conn): string {
    global $P;
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem O,0,I,1
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        // Sem âmbito de propósito: o código é a chave pública e tem de ser
        // único em todo o sistema, senão um endereço serviria dois casamentos.
        $st = $conn->prepare("SELECT id FROM {$P}convites WHERE casamento_id > 0 AND codigo=? LIMIT 1");
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
    $st = $conn->prepare("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome=? LIMIT 1");
    $st->bind_param('s', $nome); $st->execute();
    if ($r = $st->get_result()->fetch_assoc()) return (int)$r['id'];
    $st = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome) VALUES (" . casamentoAtual() . ",?)");
    $st->bind_param('s', $nome); $st->execute();
    return $conn->insert_id;
}

/** Recalcula APENAS o estado de entrada (check-in) a partir dos membros. Não toca no RSVP. */
function recalcularCheckin(mysqli $conn, int $conviteId, string $tsSql = 'NOW()'): void {
    global $P;
    $st = $conn->prepare("SELECT COUNT(*) tot,
                                 SUM(CASE WHEN rsvp='confirmado' THEN 1 ELSE 0 END) conf,
                                 COALESCE(SUM(presente),0) pres
                          FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=?");
    $st->bind_param('i', $conviteId); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $tot = (int)$r['tot']; $conf = (int)$r['conf']; $pres = (int)$r['pres'];
    if ($tot === 0) return; // convites sem membros nominais são geridos diretamente
    $base = $conf > 0 ? $conf : $tot; // "presente" quando todos os confirmados entraram
    $estado = $pres === 0 ? 'aguardando' : ($pres >= $base ? 'presente' : 'parcial');
    $em = $pres > 0 ? "COALESCE(checkin_em, $tsSql)" : 'NULL';
    $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado=?, checkin_presentes=?, checkin_em=$em WHERE " . doCasamento() . " AND id=?");
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
        FROM {$P}convites c WHERE " . doCasamento("c") . " AND $vivos");

    // ---- 1 query: contagem de convites por estado ------------------------
    // Um convite conta para um estado se o seu rsvp_estado for esse OU se
    // tiver um integrante nesse estado (ex.: "parcial" com gente pendente).
    $e = $linha("SELECT
        SUM(c.rsvp_estado='confirmado' OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='confirmado')) AS confirmados,
        SUM(c.rsvp_estado='recusado'   OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='recusado'))   AS recusados,
        SUM(c.rsvp_estado IN ('pendente','parcial') OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='pendente')) AS pendentes
        FROM {$P}convites c WHERE " . doCasamento("c") . " AND $vivos");

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
        FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id=c.id WHERE " . doCasamento("c") . " AND $vivos");

    $mesas = $linha("SELECT COUNT(*) AS n FROM {$P}mesas WHERE " . doCasamento() . "");

    $s = [
        'convites'    => $n($c,'convites'),   'lugares'   => $n($c,'lugares'),
        'convidados'  => $n($g,'convidados'), 'digitais'  => $n($c,'digitais'),
        'fisicos'     => $n($c,'fisicos'),    'noivos'    => $n($c,'noivos'),
        'noivas'      => $n($c,'noivas'),     'impressos' => $n($c,'impressos'),
        'enviados'    => $n($c,'enviados'),   'parciais'  => $n($c,'parciais'),
        'confirmados' => $n($e,'confirmados'),'recusados' => $n($e,'recusados'),
        'pendentes'   => $n($e,'pendentes'),  'lug_confirm' => $n($c,'lug_confirm'),
        'presentes'   => $n($c,'presentes'),  'no_local'  => $n($c,'no_local'),
        'mesas'       => $n($mesas,'n'),
        // Quantas pessoas este casal espera receber (MAX_LUGARES_TOTAL era o
        // mesmo teto para toda a gente, o que não quer dizer nada com vários).
        'capacidade'  => function_exists('defsAtuais')
                         ? (int)(defsAtuais($conn)['evento.convidados'] ?: MAX_LUGARES_TOTAL)
                         : MAX_LUGARES_TOTAL,
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
 * Normaliza um valor de dinheiro (do ecrã ou de um ficheiro) para
 * DECIMAL(12,2) em texto. Aceita "1.234,56", "1234.56" ou "1 234,56": o último
 * separador (vírgula ou ponto) é o decimal, o outro é dos milhares.
 *
 * Vive aqui, e não na API, porque a Gestão, o registo e o próprio módulo
 * partilham-no — um valor há de sair sempre igual, venha de onde vier.
 */
function orcValor($v): string {
    $s = trim((string)$v);
    if ($s === '') return '0.00';
    $s = preg_replace('/[^\d,.]/', '', $s);
    if ($s === '') return '0.00';
    $lc = strrpos($s, ','); $ld = strrpos($s, '.');
    $dec = max($lc === false ? -1 : $lc, $ld === false ? -1 : $ld);
    if ($dec >= 0) {
        $int = preg_replace('/\D/', '', substr($s, 0, $dec));
        $frac = preg_replace('/\D/', '', substr($s, $dec + 1));
        $num = ($int === '' ? '0' : $int) . '.' . substr($frac . '00', 0, 2);
    } else {
        $num = preg_replace('/\D/', '', $s) . '.00';
    }
    $f = (float)$num;
    if ($f < 0) $f = 0.0;
    if ($f > 99999999.99) $f = 99999999.99;   // cabe em DECIMAL(12,2)
    return number_format($f, 2, '.', '');
}

/** Define (ou limpa) o teto do orçamento de um casamento. Vazio/zero = sem teto. */
function orcamentoDefinirTeto(mysqli $conn, int $cid, $valor): void {
    global $P;
    if ($cid <= 0) return;
    $t = orcValor($valor);
    if ((float)$t <= 0) {
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.total'");
    } else {
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,'orcamento.total',?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('s', $t); $st->execute();
    }
}

/** Define (ou limpa) a moeda. Vazio ou 'Kz' volta ao Kwanza (linha apagada). */
function orcamentoDefinirMoeda(mysqli $conn, int $cid, $valor): void {
    global $P;
    if ($cid <= 0) return;
    $m = mb_substr(trim((string)$valor), 0, 8);
    if ($m === '' || $m === 'Kz') {
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.moeda'");
    } else {
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,'orcamento.moeda',?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('s', $m); $st->execute();
    }
}

/**
 * O orçamento nasce vazio, de propósito.
 *
 * Antes semeavam-se gavetas de origem («Local», «Alimentação»…), mas cada
 * casamento é seu: uma lista imposta obrigava o casal a apagar o que não lhe
 * servia antes de começar. Agora começa em branco e cada um cria as categorias
 * que quiser — a função fica para os pontos que a chamam não terem de saber
 * disto.
 */
function semearOrcamento(mysqli $conn, int $cid): void {
    // Sem gavetas de origem. (Ver a nota acima.)
}

/** A moeda do casamento (símbolo curto). Vazio volta ao Kwanza. */
function orcamentoMoeda(mysqli $conn): string {
    global $P;
    $st = @$conn->prepare("SELECT valor FROM {$P}definicoes WHERE casamento_id=" . casamentoAtual() . " AND chave='orcamento.moeda' LIMIT 1");
    if ($st && $st->execute() && ($x = $st->get_result()->fetch_assoc()) && trim((string)$x['valor']) !== '') {
        return mb_substr(trim((string)$x['valor']), 0, 8);
    }
    return 'Kz';
}

/**
 * O retrato do orçamento em números: o teto, o que já se comprometeu e o que
 * falta, e a repartição por gaveta. Serve a página, a pista do painel e a
 * prova — uma conta só, calculada num sítio.
 *
 * Tolerante como estatisticas(): se as tabelas ainda não migraram, devolve
 * zeros em vez de rebentar.
 */
function orcamentoResumo(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $linha = function (string $sql) use ($conn): array {
        $r = @$conn->query($sql); return $r ? ($r->fetch_assoc() ?: []) : [];
    };
    $f = fn(array $a, string $k) => (float)($a[$k] ?? 0);

    // Dois estados, e a conta feita por despesa: uma despesa 'pago' saiu por
    // inteiro; numa 'previsto', o que já saiu são as suas prestações liquidadas
    // (pago_em preenchido), e o que falta é o valor menos essas. Assim as
    // prestações pagas de uma despesa prevista contam no pago e descontam do
    // previsto, sem o previsto ir a negativo (GREATEST).
    $d = $linha("SELECT
        COALESCE(SUM(d.valor),0) AS total,
        COALESCE(SUM(CASE WHEN d.estado='pago' THEN d.valor ELSE 0 END),0) AS pago_desp,
        COALESCE(SUM(CASE WHEN d.estado='previsto' THEN COALESCE(pg.pagos,0) ELSE 0 END),0) AS pago_parc,
        COALESCE(SUM(CASE WHEN d.estado='previsto'
                          THEN GREATEST(d.valor - COALESCE(pg.pagos,0), 0) ELSE 0 END),0) AS previsto_liq,
        COUNT(*) AS n
        FROM {$P}orcamento_despesas d
        LEFT JOIN (SELECT despesa_id, COALESCE(SUM(valor),0) AS pagos
                   FROM {$P}orcamento_pagamentos
                   WHERE casamento_id=$cid AND pago_em IS NOT NULL
                   GROUP BY despesa_id) pg ON pg.despesa_id = d.id
        WHERE d.casamento_id=$cid");

    // O teto, se o casal o definiu; senão fica a zero e a base passa a ser a
    // soma dos previstos das categorias.
    $teto = 0.0;
    $rt = @$conn->query("SELECT valor FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.total' LIMIT 1");
    if ($rt && ($x = $rt->fetch_assoc())) $teto = max(0.0, (float)$x['valor']);

    $catPrev = $linha("SELECT COALESCE(SUM(previsto),0) AS s FROM {$P}orcamento_categorias WHERE casamento_id=$cid");
    $somaPrevisto = $f($catPrev, 's');

    $pago       = $f($d,'pago_desp') + $f($d,'pago_parc');   // o que já saiu
    $previsto   = $f($d,'previsto_liq');                     // o que ainda falta pagar
    $comprometido = $pago + $previsto;                       // tudo o que a festa vai custar
    $base = $teto > 0 ? $teto : $somaPrevisto;               // o tal "teto" efetivo

    return [
        'moeda'         => orcamentoMoeda($conn),
        'teto'          => $teto,                          // 0 = não definido
        'base'          => $base,                          // teto, ou soma dos previstos
        'categorias_previsto' => $somaPrevisto,
        'despesas'      => (int)($d['n'] ?? 0),
        'total'         => $f($d,'total'),                 // soma de todas as despesas
        'previsto'      => $previsto,                      // por pagar (previstos menos parcelas pagas)
        'pago'          => $pago,                          // pago (despesas pagas + parcelas liquidadas)
        'comprometido'  => $comprometido,
        'falta'         => $base - $comprometido,          // margem (negativo = passou)
        'acima_do_teto' => $base > 0 && $comprometido > $base,
    ];
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
                           FROM {$P}mesas WHERE " . doCasamento() . " ORDER BY (especial='noivos') DESC, nome")->fetch_all(MYSQLI_ASSOC);
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
                         WHERE " . doCasamento('c') . " AND " . soVivos($conn, 'c'));
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
                         WHERE " . doCasamento('c') . " AND " . soVivos($conn, 'c') . "
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
    $r = $conn->query("SELECT 1 FROM {$P}mesas WHERE " . doCasamento() . " AND id=$id AND especial='noivos' LIMIT 1");
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
                        WHERE " . doCasamento() . "
                          AND chave IN ('planta.largura','planta.altura','planta.bloq_mesas','planta.bloq_canvas')");
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
    $porCodigo = ($por === 'codigo' || $por === 'codigo_local');
    $col  = $porCodigo ? 'codigo' : 'id';
    $vivo = $eliminados ? '1=1' : soVivos($conn, 'c');
    // Por CÓDIGO ('codigo') é a porta pública: o convidado abre um endereço e
    // não há sessão nenhuma que diga de que casamento se trata — é o próprio
    // código que o revela. Por isso esta procura corre em todos os casamentos
    // (os códigos são únicos no sistema inteiro) e, mal o encontre, fixa o
    // âmbito para tudo o que vier a seguir no pedido. Só serve casamentos
    // ativos: um registo por aprovar, suspenso ou arquivado não tem convites
    // de pé no mundo.
    // Por CÓDIGO DA CASA ('codigo_local') é o mesmo código, mas dentro do
    // casamento aberto — é o que a porta usa, para o porteiro de um casamento
    // não ler (nem ficar a saber) o convite de outro.
    // Por ID é sempre dentro do casamento em causa: um id de outro casal não
    // pode ser alcançado escrevendo um número no endereço.
    $juncao = $por === 'codigo' ? " JOIN {$P}casamentos w ON w.id = c.casamento_id " : '';
    $ambito = $por === 'codigo' ? "c.casamento_id > 0 AND w.estado='ativo'" : doCasamento('c');
    $st = $conn->prepare("SELECT c.*, m.nome AS mesa_nome
                          FROM {$P}convites c$juncao LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                          WHERE $ambito AND c.$col=? AND $vivo LIMIT 1");
    $porCodigo ? $st->bind_param('s', $chave) : $st->bind_param('i', $chave);
    $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) return null;
    if ($por === 'codigo' && !empty($c['casamento_id'])) usarCasamento((int)$c['casamento_id']);
    $st = $conn->prepare("SELECT g.*, mg.nome AS mesa_nome, mg.especial AS mesa_especial
                          FROM {$P}convidados g LEFT JOIN {$P}mesas mg ON g.mesa_id = mg.id
                          WHERE " . doCasamento('g') . " AND g.convite_id=? ORDER BY g.principal DESC, g.nome");
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
                         WHERE " . doCasamento('g') . " AND g.convite_id = $cid");
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


