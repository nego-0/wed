<?php
// ============================================================
// auth.php — Autenticação por sessão (nome de utilizador + senha)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';   // as contas vivem na base, não no ficheiro

if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sessão mais seguro: inacessível ao JavaScript (HttpOnly),
    // só por HTTPS quando disponível (Secure) e restrito ao site (SameSite).
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Papéis -------------------------------------------------
// Há dois níveis, e convém não os confundir:
//
//   • no casamento aberto — 'admin' (quem o gere: os noivos, ou quem da
//     plataforma o esteja a acompanhar) ou 'porteiro' (só a porta). É este
//     que $_SESSION['papel'] guarda, com os mesmos nomes de sempre, para as
//     páginas continuarem a perguntar exigirAdmin()/exigirPorta() sem saber
//     que o mundo mudou por baixo.
//
//   • na plataforma — 'admin' (pessoal da casa, vê e faz tudo) ou 'suporte'.
//     Vive à parte, em papelPlataforma().
function papel(): ?string            { return $_SESSION['papel'] ?? null; }
function utilizadorAtual(): ?string  { return $_SESSION['utilizador'] ?? null; }
function utilizadorId(): int         { return (int)($_SESSION['utilizador_id'] ?? 0); }
function ehAdmin(): bool             { return papel() === 'admin'; }
function podeEntrar(): bool          { return in_array(papel(), ['admin', 'porteiro'], true); }

function papelPlataforma(): ?string  { return $_SESSION['papel_plataforma'] ?? null; }
function ehAdminPlataforma(): bool   { return papelPlataforma() === 'admin'; }
function ehSuporte(): bool           { return papelPlataforma() === 'suporte'; }
function ehPessoalPlataforma(): bool { return in_array(papelPlataforma(), ['admin','suporte'], true); }

// ---- Visitas do suporte ------------------------------------
// O suporte não entra em casa de ninguém por direito próprio. O casal gera um
// código, entrega-o, e é esse código que abre a porta — dizendo se é só para
// ver ou também para corrigir. A visita vive na sessão: fecha-se ao sair, e o
// casal pode revogar o código a qualquer momento.

/**
 * Casamentos abertos nesta sessão por um código de suporte.
 *
 * Confere os códigos na base, uma vez por pedido. Sem isto, revogar um código
 * só fechava a porta a quem ainda não tinha entrado: quem já lá estava ficava
 * lá dentro enquanto não fechasse a sessão — e "revogar" tem de querer dizer
 * agora, não da próxima vez.
 */
function suporteAcessos(): array {
    global $P;
    static $validado = false;
    $ac = is_array($_SESSION['suporte_acessos'] ?? null) ? $_SESSION['suporte_acessos'] : [];
    if (!$ac || $validado) return $ac;
    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) return $ac;
    $validado = true;

    $ids = [];
    foreach ($ac as $v) { $id = (int)($v['codigo'] ?? 0); if ($id > 0) $ids[] = $id; }
    $vivos = [];
    if ($ids) {
        $lista = implode(',', $ids);   // inteiros, saídos de casts
        $r = @$conn->query("SELECT id, casamento_id, pode_corrigir FROM {$P}suporte_codigos
                            WHERE id IN ($lista) AND revogado_em IS NULL
                              AND (expira_em IS NULL OR expira_em > NOW())");
        if ($r) while ($x = $r->fetch_assoc()) $vivos[(int)$x['id']] = $x;
    }

    $novo = [];
    foreach ($ac as $cid => $v) {
        $codigo = (int)($v['codigo'] ?? 0);
        if (!isset($vivos[$codigo])) continue;                       // revogado ou expirado
        // A permissão também pode ter mudado: manda a que está na base.
        $novo[(int)$cid] = ['corrigir' => (int)$vivos[$codigo]['pode_corrigir'], 'codigo' => $codigo];
    }
    if ($novo !== $ac) {
        $_SESSION['suporte_acessos'] = $novo;
        // Se a visita que morreu era o casamento aberto, fecha-se já — senão a
        // sessão continuava com papel de gestão sobre uma casa onde já não entra.
        $aberto = (int)($_SESSION['casamento_id'] ?? 0);
        if ($aberto > 0 && isset($ac[$aberto]) && !isset($novo[$aberto])) {
            $_SESSION['casamento_id'] = 0;
            $_SESSION['papel'] = null;
        }
    }
    return $novo;
}

/** Esta sessão está num casamento por código de suporte (e não por direito)? */
function emVisitaDeSuporte(?int $casamentoId = null): bool {
    $id = $casamentoId ?? casamentoAtual();
    return isset(suporteAcessos()[$id]);
}

/**
 * Pode escrever no casamento aberto?
 *
 * Só é falso num caso: visita de suporte com um código de "ver". É a última
 * porta antes da escrita, e por isso responde sobre o casamento em causa —
 * não sobre quem é a pessoa.
 */
function podeCorrigir(): bool {
    $v = suporteAcessos()[casamentoAtual()] ?? null;
    if ($v === null) return true;                  // não é visita: manda o papel
    return !empty($v['corrigir']);
}

/**
 * Os casamentos a que este utilizador chega, e com que papel.
 *
 * O admin da plataforma chega a todos — é quem responde pela casa. O suporte
 * chega apenas aos que lhe foram abertos por código, e aos que tenha por
 * acesso próprio. Os restantes, só àqueles em que têm lugar.
 */
function casamentosDoUtilizador(mysqli $conn): array {
    global $P;
    $out = [];
    // Os casamentos onde esta conta tem lugar por direito próprio.
    $st = @$conn->prepare("SELECT c.id, c.nome, c.estado, a.papel
                           FROM {$P}acessos a JOIN {$P}casamentos c ON c.id = a.casamento_id
                           WHERE a.utilizador_id = ? AND c.estado <> 'arquivado'
                           ORDER BY c.id");
    if ($st) {
        $uid = utilizadorId();
        $st->bind_param('i', $uid); $st->execute();
        $r = $st->get_result();
        while ($x = $r->fetch_assoc()) $out[(int)$x['id']] = $x;
    }
    // O admin da plataforma chega a todos — mas como QUEM RESPONDE PELA CASA, e
    // não como um dos noivos. Chamar-lhe 'noivos' punha-o na equipa do casal,
    // na lista de quem gere o casamento, como se fosse da família: o sistema
    // dizia uma coisa que não é verdade, e o casal via lá dentro alguém que
    // nunca convidou.
    if (ehAdminPlataforma()) {
        $r = @$conn->query("SELECT id, nome, estado FROM {$P}casamentos
                            WHERE estado <> 'arquivado' ORDER BY id");
        if ($r) while ($x = $r->fetch_assoc()) {
            if (isset($out[(int)$x['id']])) continue;   // tem lugar próprio: manda esse
            $x['papel'] = 'plataforma';
            $out[(int)$x['id']] = $x;
        }
        ksort($out);
    }
    // E os que um código de suporte abriu nesta sessão.
    foreach (suporteAcessos() as $id => $v) {
        if (isset($out[(int)$id])) continue;
        $q = @$conn->prepare("SELECT id, nome, estado FROM {$P}casamentos
                              WHERE id=? AND estado <> 'arquivado'");
        if (!$q) continue;
        $q->bind_param('i', $id); $q->execute();
        if ($x = $q->get_result()->fetch_assoc()) {
            $x['papel']   = 'noivos';
            $x['suporte'] = empty($v['corrigir']) ? 'ver' : 'corrigir';
            $out[(int)$x['id']] = $x;
        }
    }
    ksort($out);
    return $out;
}

/** Este utilizador pode abrir este casamento? Com que papel? */
function papelNoCasamento(mysqli $conn, int $casamentoId): ?string {
    $lista = casamentosDoUtilizador($conn);
    return isset($lista[$casamentoId]) ? $lista[$casamentoId]['papel'] : null;
}

/**
 * Abre um casamento na sessão: passa a ser o que as páginas mostram, e o papel
 * do utilizador nesse casamento passa a ser o que as páginas verificam.
 * Devolve false se o utilizador não lá tiver lugar.
 */
function abrirCasamento(mysqli $conn, int $casamentoId): bool {
    $p = papelNoCasamento($conn, $casamentoId);
    if ($p === null) return false;
    $_SESSION['casamento_id'] = $casamentoId;
    // 'noivos' é quem gere a peça — as páginas conhecem-no por 'admin'. O
    // pessoal da casa entra com os mesmos poderes, mas fica registado que é
    // ISSO que ele é: as páginas dizem-no, e a equipa do casal não o inclui.
    $_SESSION['papel'] = $p === 'porteiro' ? 'porteiro' : 'admin';
    $_SESSION['como_plataforma'] = ($p === 'plataforma');
    usarCasamento($casamentoId);
    // Fica o rasto de quando se trabalhou nele pela última vez: é o que põe a
    // lista da administração por ordem de uso, e não por ordem de criação.
    @$conn->query("UPDATE {$GLOBALS['P']}casamentos SET ultimo_acesso = NOW()
                   WHERE id = " . (int)$casamentoId);
    return true;
}

/**
 * Fecha o casamento aberto, sem fechar a sessão.
 *
 * Quem responde pela casa entra e sai de casamentos alheios o dia todo. Sem
 * isto, a única forma de sair de um era abrir outro — ou terminar a sessão
 * inteira, o que é responder a uma pergunta com outra.
 */
function fecharCasamento(): void {
    $cid = casamentoAtual();
    // Se lá estava por um código de suporte, a visita termina com a saída.
    $ac = suporteAcessos();
    if (isset($ac[$cid])) { unset($ac[$cid]); $_SESSION['suporte_acessos'] = $ac; }
    $_SESSION['casamento_id'] = 0;
    $_SESSION['papel'] = null;
    $_SESSION['como_plataforma'] = false;
    unset($GLOBALS['CASAMENTO_ID']);
}

/** Está a ver este casamento por ser da casa, e não por ser dele? */
function entrouComoPlataforma(): bool {
    return !empty($_SESSION['como_plataforma']);
}

// ============================================================
// A licença de uso de um casamento
//
// Cada casamento tem um período de uso, em meses ('licenca_meses'; 0 = sem
// limite). O relógio começa quando o casamento fica ativo — aí grava-se a data
// de expiração ('licenca_ate'). Expirada, o casamento é suspenso sozinho, e com
// ele as contas que só dele dependem.
// ============================================================

/** O que se sabe da licença de um casamento, pronto para mostrar e decidir. */
function licencaInfo(mysqli $conn, int $cid): array {
    global $P;
    $base = ['meses' => 0, 'ate' => null, 'ilimitada' => true,
             'iniciada' => false, 'expirada' => false, 'dias' => null];
    if ($cid <= 0) return $base;
    $st = @$conn->prepare("SELECT licenca_meses, licenca_ate FROM {$P}casamentos WHERE id=? LIMIT 1");
    if (!$st) return $base;
    $st->bind_param('i', $cid); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    if (!$r) return $base;
    $meses = (int)$r['licenca_meses'];
    $ate   = $r['licenca_ate'] ?: null;
    if ($meses <= 0) return ['meses' => 0, 'ate' => null, 'ilimitada' => true,
                             'iniciada' => false, 'expirada' => false, 'dias' => null];
    if ($ate === null) return ['meses' => $meses, 'ate' => null, 'ilimitada' => false,
                               'iniciada' => false, 'expirada' => false, 'dias' => null];
    $hoje = new DateTimeImmutable('today');
    $fim  = new DateTimeImmutable($ate);
    $dias = (int)$hoje->diff($fim)->format('%r%a');
    return ['meses' => $meses, 'ate' => $ate, 'ilimitada' => false,
            'iniciada' => true, 'expirada' => $dias < 0, 'dias' => $dias];
}

/**
 * Arranca (ou reinicia) o relógio da licença de um casamento: grava a data de
 * expiração a partir de hoje. Só mexe se houver período definido e a licença
 * ainda não estiver a correr (ou já ter expirado — reativar dá período novo).
 */
function iniciarLicenca(mysqli $conn, int $cid): void {
    global $P;
    $info = licencaInfo($conn, $cid);
    if ($info['ilimitada']) return;                       // sem limite: nada a marcar
    if ($info['iniciada'] && !$info['expirada']) return;  // já a correr: não se reinicia
    $meses = (int)$info['meses'];
    @$conn->query("UPDATE {$P}casamentos
                   SET licenca_ate = DATE_ADD(CURDATE(), INTERVAL $meses MONTH)
                   WHERE id = " . (int)$cid);
}

/**
 * Para as contas que só existem por causa deste casamento: passam a 'inativo'.
 * São as contas de noivos/porteiro cujo único casamento de pé é este, e nunca
 * o pessoal da plataforma, que não depende de casamento nenhum. Devolve quantas.
 *
 * 'inativo' (e não 'suspenso') de propósito: é o estado "parada por causa do
 * casamento", que reabrir o casamento desfaz — ao contrário de uma suspensão
 * que alguém decidiu sobre a pessoa.
 */
function pararContasDoCasamento(mysqli $conn, int $cid): int {
    global $P;
    $st = @$conn->prepare("UPDATE {$P}utilizadores u
                           JOIN {$P}acessos a ON a.utilizador_id = u.id
                           SET u.estado='inativo'
                           WHERE a.casamento_id = ?
                             AND u.estado = 'ativo'
                             AND u.papel_plataforma IS NULL
                             AND NOT EXISTS (
                                   SELECT 1 FROM {$P}acessos a2
                                   JOIN {$P}casamentos c2 ON c2.id = a2.casamento_id
                                   WHERE a2.utilizador_id = u.id
                                     AND a2.casamento_id <> ?
                                     AND c2.estado NOT IN ('arquivado','suspenso'))");
    if (!$st) return 0;
    $st->bind_param('ii', $cid, $cid);
    return $st->execute() ? $conn->affected_rows : 0;
}

/**
 * Devolve à vida as contas que tinham parado com este casamento: 'inativo' e
 * 'pendente' voltam a 'ativo'. Não toca em 'suspenso' — reabrir um casamento
 * não desfaz uma decisão tomada sobre uma pessoa. Devolve quantas voltaram.
 */
function retomarContasDoCasamento(mysqli $conn, int $cid): int {
    global $P;
    $st = @$conn->prepare("UPDATE {$P}utilizadores u
                           JOIN {$P}acessos a ON a.utilizador_id = u.id
                           SET u.estado='ativo'
                           WHERE a.casamento_id = ? AND u.estado IN ('pendente','inativo')");
    if (!$st) return 0;
    $st->bind_param('i', $cid);
    return $st->execute() ? $conn->affected_rows : 0;
}

/** Uma frase curta sobre o que resta da licença, para o cabeçalho e as listas. */
function licencaRotulo(array $info): string {
    if ($info['ilimitada']) return '';
    if (!$info['iniciada'])  return 'licença por iniciar';
    $d = (int)$info['dias'];
    if ($d < 0)   return 'licença expirada';
    if ($d === 0) return 'licença termina hoje';
    if ($d === 1) return 'falta 1 dia de licença';
    if ($d < 45)  return "faltam $d dias de licença";
    $m = (int)round($d / 30);
    return "faltam ~$m meses de licença";
}

/**
 * A frase da licença para o cabeçalho: em DIAS e com a data de expiração entre
 * parênteses — «Possui 92 dias de licença de uso (25/11/2026)». Vazia quando
 * não há limite.
 */
function licencaFrase(array $info): string {
    if ($info['ilimitada']) return '';
    if (!$info['iniciada'])  return 'Licença de uso por iniciar';
    $data  = $info['ate'] ? date('d/m/Y', strtotime($info['ate'])) : '';
    $entre = $data !== '' ? " ($data)" : '';
    $d = (int)$info['dias'];
    if ($d < 0)   return 'Licença de uso expirada' . $entre;
    if ($d === 0) return 'Último dia de licença de uso' . $entre;
    $dia = $d === 1 ? 'dia' : 'dias';
    return "Possui $d $dia de licença de uso" . $entre;
}

// ============================================================
// O que a licença abre — os módulos
//
// A licença deixou de ser só um prazo: diz também O QUÊ. Cada casamento tem
// concessões (uma por módulo) que dizem se o módulo está aberto e em que
// medida — quantos convidados cabem, se a peça se pode editar, se o casal
// chega a todos os modelos ou só ao padrão.
//
// Quem responde pela casa não tem licença nenhuma a cumprir: entra em todo o
// lado, é essa a função. O que ele vê é o casamento como o casal o teria com
// tudo aberto — não vale a pena esconder-lhe portas que ele tem de arranjar.
// ============================================================

/**
 * As concessões de um casamento, por chave de módulo. Lê-se uma vez por pedido.
 *
 * Devolve, para cada uma das cinco chaves: ['ativo', 'limite', 'editar',
 * 'todos_modelos', 'nome']. 'limite' 0 = sem limite.
 */
function licencaModulos(mysqli $conn, ?int $cid = null): array {
    global $P;
    $cid = $cid ?? casamentoAtual();
    static $cache = [];
    if (isset($cache[$cid])) return $cache[$cid];

    $base = [];
    foreach (array_keys(licencaModulosTudo()) as $k) {
        $base[$k] = ['ativo' => false, 'limite' => 0, 'editar' => false,
                     'todos_modelos' => false, 'nome' => ''];
    }
    if ($cid <= 0) return $cache[$cid] = $base;

    $st = @$conn->prepare("SELECT modulo_chave, escalao_nome, limite, editar, todos_modelos
                           FROM {$P}lic_concessoes WHERE casamento_id = ?");
    if ($st) {
        $st->bind_param('i', $cid);
        if (@$st->execute()) {
            $r = $st->get_result();
            while ($x = $r->fetch_assoc()) {
                $k = (string)$x['modulo_chave'];
                if (!isset($base[$k])) continue;
                $base[$k] = ['ativo' => true, 'limite' => (int)$x['limite'],
                             'editar' => (bool)(int)$x['editar'],
                             'todos_modelos' => (bool)(int)$x['todos_modelos'],
                             'nome' => (string)$x['escalao_nome']];
            }
        }
    }
    return $cache[$cid] = $base;
}

/** Em que ponto está a licença deste casamento: sem, pendente, ativa, revogada. */
function licencaEstado(mysqli $conn, ?int $cid = null): string {
    global $P;
    $cid = $cid ?? casamentoAtual();
    if ($cid <= 0) return 'sem';
    static $cache = [];
    if (isset($cache[$cid])) return $cache[$cid];
    $r = @$conn->query("SELECT licenca_estado FROM {$P}casamentos WHERE id=" . (int)$cid . " LIMIT 1");
    $e = ($r && ($x = $r->fetch_row())) ? (string)$x[0] : 'sem';
    return $cache[$cid] = ($e !== '' ? $e : 'sem');
}

/** Este casamento tem este módulo aberto? (Quem responde pela casa tem tudo.) */
function podeModulo(string $chave): bool {
    if (ehPessoalPlataforma()) return true;
    $m = licencaModulos($GLOBALS['conn']);
    return !empty($m[$chave]['ativo']);
}

/**
 * Quantos convidados cabem nesta licença. 0 = sem limite.
 *
 * Sem o módulo, o limite é -1: não é "sem limite", é "nem um".
 */
function limiteConvidados(): int {
    if (ehPessoalPlataforma()) return 0;
    $m = licencaModulos($GLOBALS['conn']);
    if (empty($m['convidados']['ativo'])) return -1;
    return (int)$m['convidados']['limite'];
}

/** Quantas pessoas já estão na lista deste casamento. */
function convidadosContados(mysqli $conn, ?int $cid = null): int {
    global $P;
    $cid = $cid ?? casamentoAtual();
    if ($cid <= 0) return 0;
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}convidados WHERE casamento_id=" . (int)$cid);
    return ($r && ($x = $r->fetch_row())) ? (int)$x[0] : 0;
}

/** Pode desenhar esta peça ('digital' | 'impresso'), ou só usá-la como está? */
function podeEditarPeca(string $ambito): bool {
    if (ehPessoalPlataforma()) return true;
    $m = licencaModulos($GLOBALS['conn']);
    return !empty($m[$ambito]['ativo']) && !empty($m[$ambito]['editar']);
}

/** Chega a toda a galeria de modelos desta peça, ou só ao padrão da casa? */
function podeTodosModelos(string $ambito): bool {
    if (ehPessoalPlataforma()) return true;
    $m = licencaModulos($GLOBALS['conn']);
    return !empty($m[$ambito]['ativo']) && !empty($m[$ambito]['todos_modelos']);
}

/**
 * A licença deste casamento está à espera de decisão (ou nunca houve nenhuma)?
 *
 * É o estado em que o casal já entra — de propósito — mas só vê o seu pedido.
 * Um casamento criado pela administração fica em 'sem' até lhe darem módulos:
 * dá no mesmo, e a página da licença explica-lhe o que fazer.
 */
function licencaPorAbrir(mysqli $conn, ?int $cid = null): bool {
    if (ehPessoalPlataforma()) return false;
    return in_array(licencaEstado($conn, $cid), ['sem', 'pendente', 'revogada'], true);
}

/**
 * A porta de cada página de módulo.
 *
 * Não devolve um "não pode": manda para a página da licença, que mostra ao
 * casal o que aquele módulo faz e como o ter. Uma porta fechada que explica
 * como se abre vale mais do que um erro.
 */
function exigirModulo(string $chave): void {
    if (podeModulo($chave)) return;
    // Os noivos vão para a montra — é lá que se resolve.
    if (ehAdmin()) {
        header('Location: licenca.php?quero=' . urlencode($chave));
        exit;
    }
    // O porteiro não pede planos nenhuns: a licença é do casal, e mandá-lo para
    // uma página que ele não pode ver era trocar uma porta fechada por outra.
    // Diz-se-lhe o que se passa, e a quem falar.
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $marca = defined('PLATAFORMA') ? (PLATAFORMA['nome'] ?? 'Casamento') : 'Casamento';
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Sem acesso · ' . htmlspecialchars($marca, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<link href="assets/estilo.css" rel="stylesheet"></head><body>'
       . '<div style="max-width:34rem;margin:14vh auto;padding:2rem 1.5rem;text-align:center">'
       . '<div style="font-size:2.2rem;margin-bottom:.8rem">🔒</div>'
       . '<h1 style="font-family:var(--serif);font-size:1.5rem;color:var(--ink);margin:0 0 .6rem">'
       . 'Esta parte não está disponível</h1>'
       . '<p style="color:#8a8f88;line-height:1.6;font-size:.92rem">A licença deste casamento não '
       . 'inclui, neste momento, o que esta página faz. Fale com os noivos — são eles que gerem '
       . 'a licença.</p>'
       . '<p style="margin-top:1.4rem"><a class="btn btn-linha" href="logout.php">Sair</a></p>'
       . '</div></body></html>';
    exit;
}

/**
 * Suspende os casamentos cuja licença expirou, e para as contas com eles.
 *
 * Corre nos pontos de entrada (login, página da plataforma): é barata quando
 * não há nada a fazer, e é o que garante que uma licença vencida fecha a porta
 * mesmo sem ninguém carregar num botão. Devolve quantos casamentos suspendeu.
 */
function suspenderLicencasExpiradas(mysqli $conn): int {
    global $P;
    $r = @$conn->query("SELECT id FROM {$P}casamentos
                        WHERE estado='ativo' AND licenca_ate IS NOT NULL AND licenca_ate < CURDATE()");
    if (!$r || !$r->num_rows) return 0;
    $ids = [];
    while ($x = $r->fetch_row()) $ids[] = (int)$x[0];
    foreach ($ids as $cid) {
        @$conn->query("UPDATE {$P}casamentos SET estado='suspenso' WHERE id=$cid");
        pararContasDoCasamento($conn, $cid);
    }
    return count($ids);
}

/**
 * Token CSRF da sessão (gerado na primeira utilização). Deve ser incluído
 * nos pedidos que alteram dados, no cabeçalho "X-CSRF-Token".
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Valida o token CSRF recebido (cabeçalho X-CSRF-Token ou campo 'csrf'). */
function csrfValido(): bool {
    $esperado = $_SESSION['csrf'] ?? '';
    if ($esperado === '') return false;
    $recebido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($recebido === '') $recebido = (string)($_POST['csrf'] ?? ($_GET['csrf'] ?? ''));
    return $recebido !== '' && hash_equals($esperado, $recebido);
}

/**
 * Autentica por email (ou nome) + senha, contra as contas da base.
 *
 * Devolve, em caso de sucesso: 'admin' ou 'porteiro' (o papel no casamento
 * que se abriu), ou 'plataforma' quando a conta é boa mas ainda não tem
 * casamento nenhum onde entrar. Devolve null quando não autentica.
 */
function autenticar(string $utilizador, string $senha): ?string {
    global $conn, $P;
    $utilizador = trim($utilizador);
    if ($utilizador === '' || $senha === '') return null;

    // Entra-se pelo email ou pelo nome — quem vinha do ficheiro de
    // configuração tinha nome ('admin') e ficou com um email composto.
    $st = @$conn->prepare("SELECT id, email, nome, senha_hash, papel_plataforma, estado
                           FROM {$P}utilizadores
                           WHERE email = ? OR nome = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('ss', $utilizador, $utilizador);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) return null;
    if (!password_verify($senha, (string)$u['senha_hash'])) return null;
    // Antes de deixar entrar, fecha-se a porta às licenças vencidas: um
    // casamento expirado é suspenso, e as contas que dele dependem passam a
    // 'inativo' — e é isso que a verificação de estado, logo abaixo, apanha.
    // (O pessoal da plataforma não depende de casamento nenhum e passa sempre.)
    suspenderLicencasExpiradas($conn);
    if ($u['papel_plataforma'] === null) {
        $q = @$conn->query("SELECT estado FROM {$P}utilizadores WHERE id=" . (int)$u['id']);
        if ($q && ($x = $q->fetch_assoc())) $u['estado'] = $x['estado'];
    }
    // Um registo por aprovar, ou uma conta suspensa, não entra. A mensagem
    // fica igual à de senha errada: quem tenta adivinhar não fica a saber
    // que a conta existe.
    if ($u['estado'] !== 'ativo') return null;

    // Renova o ID da sessão ao autenticar (evita fixação de sessão).
    session_regenerate_id(true);
    $_SESSION['utilizador_id']    = (int)$u['id'];
    $_SESSION['utilizador']       = $u['nome'] ?: $u['email'];
    $_SESSION['papel_plataforma'] = $u['papel_plataforma'] ?: null;

    @$conn->query("UPDATE {$P}utilizadores SET ultimo_acesso = NOW() WHERE id = " . (int)$u['id']);

    // Abre-se um casamento: se só há um, é esse; se há vários, o primeiro, e
    // quem quiser troca depois.
    //
    // Sem nenhum, não se abre nada — e é preciso dizê-lo com clareza. O
    // suporte entra sempre assim (só lá chega com um código que o casal lhe
    // dê), e antes ficava com 'admin' e sem casamento aberto: casamentoAtual()
    // respondia 1 por omissão e a pessoa aterrava, com poderes de gestão, na
    // casa do primeiro casal do sistema.
    // O pessoal da casa não aterra na festa de ninguém. Chega a todos os
    // casamentos, mas nenhum é o "seu": abrir-lhe o primeiro da lista punha-o
    // dentro do casamento de um casal ao acaso, com poderes de gestão e sem ter
    // pedido nada. Vai para a página dos casamentos, e escolhe.
    if (ehPessoalPlataforma()) {
        $_SESSION['casamento_id'] = 0;
        $_SESSION['papel'] = null;
        return 'plataforma';
    }
    $lista = casamentosDoUtilizador($conn);
    if ($lista) {
        abrirCasamento($conn, (int)array_key_first($lista));
        return papel();
    }
    $_SESSION['casamento_id'] = 0;   // 0 = casamento nenhum, e não "o primeiro"
    $_SESSION['papel'] = null;
    // Autenticou-se: não é uma senha errada. Só ainda não tem casa onde entrar.
    return 'plataforma';
}

function terminarSessao(): void {
    $_SESSION = [];
    // Apaga também o cookie de sessão no navegador.
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Confere as visitas de suporte logo à entrada do pedido, antes de qualquer
// página ou endpoint perguntar pelo papel. Deixar isto para quando alguém
// chamasse suporteAcessos() era deixá-lo por fazer: um pedido que só pergunte
// ehAdmin() — e são quase todos — nunca lá chegava, e um código revogado
// continuava a servir.
suporteAcessos();

/** Exige admin; caso contrário redireciona para o login. */
function exigirAdmin(): void {
    if (ehAdmin()) return;
    header('Location: ' . portaFechada('index.php')); exit;
}

/** Exige admin ou porteiro. */
function exigirPorta(): void {
    if (podeEntrar()) return;
    header('Location: ' . portaFechada('porteiro.php')); exit;
}

/**
 * Para onde mandar quem não pode ver esta página.
 *
 * Quem não tem sessão vai para a entrada, e volta aqui depois. Mas quem TEM
 * sessão e só não escolheu casamento nenhum — o pessoal da casa, acabado de
 * entrar — não pode ir para o login: já lá esteve, e ver o ecrã de entrada
 * outra vez parece uma sessão que caiu. Vai escolher um casamento, que é o que
 * lhe falta.
 */
function portaFechada(string $omissao): string {
    if (utilizadorId() && casamentoAtual() <= 0) return 'plataforma.php';
    return 'login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? $omissao);
}
