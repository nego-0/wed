<?php
// ============================================================
// router-dev.php — o servidor de desenvolvimento a fazer o que o Apache faz
// ------------------------------------------------------------
// Em produção há um .htaccess, e é ele que transforma
// `bebidas-2026-12-19-ia.php` em `bebidas.php?c=2026-12-19-ia`. O servidor
// embutido do PHP (`php -S`) não lê .htaccess nenhum: sem isto, o endereço
// bonito dava 404 em desenvolvimento e as provas tinham de fingir que ele
// existia — que é o mesmo que não o provar.
//
//     php -S 127.0.0.1:8920 -t . router-dev.php
//
// Só isto. Tudo o resto segue como seguia: devolver false diz ao servidor
// embutido «serve tu, como se eu não estivesse aqui».
// ============================================================

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// A mesma regra que está no .htaccess, e pela mesma razão. Se alguma delas
// mudar, esta é a que se nota primeiro — as provas correm contra ela.
if (preg_match('#^/bebidas-([A-Za-z0-9-]+)\.php$#', $caminho, $m)) {
    $_GET['c'] = $m[1];
    $_REQUEST['c'] = $m[1];
    // A pergunta que vinha no endereço não se perde: quem entra pelo QR de
    // uma mesa traz um `?m=`, e esse diz para onde levar a bebida.
    $_SERVER['SCRIPT_NAME'] = '/bebidas.php';
    $_SERVER['PHP_SELF'] = '/bebidas.php';
    require __DIR__ . '/bebidas.php';
    return true;
}

return false;
