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

/**
 * Os casamentos a que este utilizador chega, e com que papel.
 * O pessoal da plataforma chega a todos; os restantes, só àqueles em que
 * têm lugar (tabela de acessos).
 */
function casamentosDoUtilizador(mysqli $conn): array {
    global $P;
    $out = [];
    if (ehPessoalPlataforma()) {
        $r = @$conn->query("SELECT id, nome, estado FROM {$P}casamentos
                            WHERE estado <> 'arquivado' ORDER BY id");
        if ($r) while ($x = $r->fetch_assoc()) {
            // Quem é da casa entra como quem gere — o que o suporte pode
            // MEXER é outra conversa, e decide-se no ponto de escrita.
            $x['papel'] = 'noivos';
            $out[(int)$x['id']] = $x;
        }
        return $out;
    }
    $st = @$conn->prepare("SELECT c.id, c.nome, c.estado, a.papel
                           FROM {$P}acessos a JOIN {$P}casamentos c ON c.id = a.casamento_id
                           WHERE a.utilizador_id = ? AND c.estado <> 'arquivado'
                           ORDER BY c.id");
    if (!$st) return $out;
    $uid = utilizadorId();
    $st->bind_param('i', $uid); $st->execute();
    $r = $st->get_result();
    while ($x = $r->fetch_assoc()) $out[(int)$x['id']] = $x;
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
    // 'noivos' é quem gere a peça — as páginas conhecem-no por 'admin'.
    $_SESSION['papel'] = $p === 'porteiro' ? 'porteiro' : 'admin';
    usarCasamento($casamentoId);
    return true;
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
 * Autentica por nome de utilizador + senha (definidos em config.local.php).
 * Aceita senha em texto simples ('senha') ou em hash ('senha_hash').
 * Devolve o papel ('admin'/'porteiro') em caso de sucesso, ou null.
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
    // quem quiser troca depois. Sem nenhum, a conta entra mas não vê peça
    // nenhuma — é o caso de um registo aprovado a que ainda falta o casamento.
    $lista = casamentosDoUtilizador($conn);
    if ($lista) {
        abrirCasamento($conn, (int)array_key_first($lista));
    } else {
        $_SESSION['papel'] = ehPessoalPlataforma() ? 'admin' : null;
    }
    return papel();
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

/** Exige admin; caso contrário redireciona para o login. */
function exigirAdmin(): void {
    if (!ehAdmin()) { header('Location: login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')); exit; }
}

/** Exige admin ou porteiro. */
function exigirPorta(): void {
    if (!podeEntrar()) { header('Location: login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? 'porteiro.php')); exit; }
}
