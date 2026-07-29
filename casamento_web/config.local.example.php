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
        'local'  => ['host' => 'localhost',        'user' => 'root',            'pass' => '',                 'db' => 'wed'],
        'online' => ['host' => 'sql300.infinityfree.com',   'user' => 'if0_40371922',  'pass' => 'ncm202605', 'db' => 'if0_40371922_wed'],
    ],

    // ---- Utilizadores (nome de utilizador + senha) ---------
    // 'papel': 'admin' (acede a tudo) ou 'porteiro' (só a página da porta).
    // Recomendado: 'senha_hash' (a senha nunca fica legível no ficheiro).
    // Gere o hash da sua senha com:
    //   php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"
    // e cole o resultado em 'senha_hash'.
    'utilizadores' => [
        ['utilizador' => 'admin',    'senha_hash' => '$2y$10$r/Jwoyuz8q8DGhXup6eLqO5hohSTRsY/szp1YqHWXRPP2wdn2poD.', 'papel' => 'admin'],
        ['utilizador' => 'porteiro', 'senha_hash' => '$2y$10$r/Jwoyuz8q8DGhXup6eLqO5hohSTRsY/szp1YqHWXRPP2wdn2poD.', 'papel' => 'porteiro'],

        // Alternativa mais simples (senha legível neste ficheiro):
        // ['utilizador' => 'admin', 'senha' => 'a-sua-senha', 'papel' => 'admin'],
    ],

];
