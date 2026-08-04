<?php
// ============================================================
// config.php — Configuração central do sistema (SEM segredos)
// Isabel & Abednego · Gestão de Convidados
//
// Este ficheiro pode ir para o controlo de versões (Git/GitHub):
// NÃO contém palavras-passe nem dados de ligação à base de dados.
//
// Os segredos ficam em "config.local.php", que NÃO é versionado
// (ver .gitignore). Para começar, copie o modelo:
//     cp config.local.example.php config.local.php
// e preencha os valores reais nesse ficheiro.
// ============================================================

// ---- Carregar segredos locais ------------------------------
// config.local.php devolve um array com 'db' e 'utilizadores'.
$__localFile = __DIR__ . '/config.local.php';
$LOCAL = is_readable($__localFile) ? require $__localFile : [];
if (!is_array($LOCAL)) $LOCAL = [];

/**
 * Lê um valor de config.local.php; se não existir, tenta uma variável
 * de ambiente com o mesmo nome (em maiúsculas); por fim, usa o default.
 */
function cfg_local(string $chave, $default = null) {
    global $LOCAL;
    if (is_array($LOCAL) && array_key_exists($chave, $LOCAL)) return $LOCAL[$chave];
    $env = getenv(strtoupper($chave));
    return $env !== false ? $env : $default;
}

// ---- Evento -------------------------------------------------
const EVENTO = [
    'noiva'     => 'Isabel',
    'noivo'     => 'Abednego',
    'data_iso'  => '2026-12-19',                 // usado no contador e QR
    'data_ext'  => '19 de Dezembro de 2026',     // texto exibido
    'hora'      => '16:00',                       // AJUSTE: hora real da cerimónia
    'local'     => 'Estufa Municipal de Moçâmedes',
    'cidade'    => 'Namibe, Angola',
    'whatsapp'  => '244000000000',                // AJUSTE: nº WhatsApp p/ contacto (formato internacional, só dígitos)
];

// ---- Base de dados -----------------------------------------
// Os dados reais de ligação vêm de config.local.php ('db').
// O default abaixo serve apenas para desenvolvimento local (XAMPP/Wamp).
// Tenta 'local' primeiro e depois 'online'.
define('DB_CONFIGS', cfg_local('db', [
    'local' => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'wedding_guests'],
]));

// ---- Utilizadores (nome de utilizador + senha) -------------
// Definidos em config.local.php ('utilizadores'). Cada entrada tem:
//   'utilizador', 'papel' ('admin' ou 'porteiro') e 'senha' OU 'senha_hash'.
// Ver auth.php para a lógica de autenticação.
define('UTILIZADORES', cfg_local('utilizadores', []));

// ---- Regras -------------------------------------------------
const PREFIXO   = 'cw_';   // prefixo das tabelas novas (não mexe na lista antiga)
const MAX_LUGARES_TOTAL = 150;  // teto de segurança de lugares no evento
const RECICLAGEM_DIAS   = 30;   // dias que um convite eliminado fica recuperável
const LISTA_POR_PAGINA  = 60;   // convites carregados de cada vez no painel
const VERSOES_MAX       = 12;   // versões guardadas do convite digital

// Fuso não é crítico aqui; datas guardadas em UTC pelo MySQL.
date_default_timezone_set('Africa/Luanda');
