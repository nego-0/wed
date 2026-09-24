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

    // ---- Utilizador administrador (semente inicial) --------
    // Uma instalação de origem nasce só com o administrador da plataforma — sem
    // casamento nenhum e sem porteiros. Os casamentos criam-se depois na
    // administração, e cada porteiro é convidado dentro do seu casamento.
    // Recomendado: 'senha_hash' (a senha nunca fica legível no ficheiro).
    // Gere o hash da sua senha com:
    //   php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"
    // e cole o resultado em 'senha_hash'.
    'utilizadores' => [
        ['utilizador' => 'admin', 'senha_hash' => '$2y$10$r/Jwoyuz8q8DGhXup6eLqO5hohSTRsY/szp1YqHWXRPP2wdn2poD.', 'papel' => 'admin'],

        // Alternativa mais simples (senha legível neste ficheiro):
        // ['utilizador' => 'admin', 'senha' => 'a-sua-senha', 'papel' => 'admin'],
    ],

    // ---- Casamento de demonstração (só desenvolvimento) ----
    // A instalação de origem nasce sem casamento nenhum. Para correr a suite de
    // provas (tests/), descomente a linha abaixo: cria um casamento nº1 de
    // trabalho. NÃO a use numa instalação a sério.
    // 'semear_demo' => true,

];
