<?php
// ============================================================
// config.local.example.php — MODELO dos segredos
// ------------------------------------------------------------
// Copie este ficheiro para "config.local.php" e preencha com os
// seus valores reais:
//     cp config.local.example.php config.local.php
//
// O "config.local.php" NÃO deve ser versionado (ver .gitignore).
// Este modelo (sem segredos) pode ficar no repositório.
// ============================================================

return [

    // ---- Ligação à base de dados ---------------------------
    // Tenta 'local' primeiro (XAMPP/Wamp) e depois 'online' (alojamento).
    'db' => [
        'local'  => ['host' => 'localhost',        'user' => 'root',            'pass' => '',                 'db' => 'wedding_guests'],
        'online' => ['host' => 'SEU_HOST_MYSQL',   'user' => 'SEU_UTILIZADOR',  'pass' => 'SUA_PALAVRA_PASSE', 'db' => 'SUA_BASE_DE_DADOS'],
    ],

    // ---- Utilizadores (nome de utilizador + senha) ---------
    // 'papel': 'admin' (acede a tudo) ou 'porteiro' (só a página da porta).
    // Recomendado: 'senha_hash' (a senha nunca fica legível no ficheiro).
    // Gere o hash da sua senha com:
    //   php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"
    // e cole o resultado em 'senha_hash'.
    'utilizadores' => [
        ['utilizador' => 'admin',    'senha_hash' => 'COLE_AQUI_O_HASH', 'papel' => 'admin'],
        ['utilizador' => 'porteiro', 'senha_hash' => 'COLE_AQUI_O_HASH', 'papel' => 'porteiro'],

        // Alternativa mais simples (senha legível neste ficheiro):
        // ['utilizador' => 'admin', 'senha' => 'a-sua-senha', 'papel' => 'admin'],
    ],

];
