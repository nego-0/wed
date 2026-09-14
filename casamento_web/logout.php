<?php
require_once __DIR__ . '/auth.php';
terminarSessao();
header('Location: login.php');
exit;
