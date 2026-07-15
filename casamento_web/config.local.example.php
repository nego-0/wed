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
    // Use 'senha' (texto simples) OU 'senha_hash' (recomendado).
    //
    // Gerar um hash de senha:
    //   php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"
    'utilizadores' => [
        ['utilizador' => 'admin',    'senha' => 'MUDE_ESTA_SENHA', 'papel' => 'admin'],
        ['utilizador' => 'porteiro', 'senha' => 'MUDE_ESTA_SENHA', 'papel' => 'porteiro'],

        // Exemplo com hash em vez de texto simples:
        // ['utilizador' => 'admin', 'senha_hash' => '$2y$10$....', 'papel' => 'admin'],
    ],

];
