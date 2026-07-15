<?php
// ============================================================
// auth.php — Autenticação por sessão (nome de utilizador + senha)
// ============================================================
require_once __DIR__ . '/config.php';

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

function papel(): ?string           { return $_SESSION['papel'] ?? null; }
function utilizadorAtual(): ?string  { return $_SESSION['utilizador'] ?? null; }
function ehAdmin(): bool             { return papel() === 'admin'; }
function podeEntrar(): bool          { return in_array(papel(), ['admin', 'porteiro'], true); }

/**
 * Autentica por nome de utilizador + senha (definidos em config.local.php).
 * Aceita senha em texto simples ('senha') ou em hash ('senha_hash').
 * Devolve o papel ('admin'/'porteiro') em caso de sucesso, ou null.
 */
function autenticar(string $utilizador, string $senha): ?string {
    $utilizador = trim($utilizador);
    if ($utilizador === '' || $senha === '') return null;

    foreach (UTILIZADORES as $u) {
        if (!is_array($u) || !isset($u['utilizador'])) continue;
        // Nome de utilizador sem distinção de maiúsculas/minúsculas.
        if (strcasecmp(trim((string)$u['utilizador']), $utilizador) !== 0) continue;

        $ok = false;
        if (!empty($u['senha_hash'])) {
            $ok = password_verify($senha, (string)$u['senha_hash']);
        } elseif (isset($u['senha'])) {
            // hash_equals evita ataques de temporização na comparação.
            $ok = hash_equals((string)$u['senha'], $senha);
        }
        if (!$ok) return null;

        $papel = in_array($u['papel'] ?? '', ['admin', 'porteiro'], true) ? $u['papel'] : 'porteiro';
        // Renova o ID da sessão ao autenticar (evita fixação de sessão).
        session_regenerate_id(true);
        $_SESSION['papel']      = $papel;
        $_SESSION['utilizador'] = trim((string)$u['utilizador']);
        return $papel;
    }
    return null;
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
