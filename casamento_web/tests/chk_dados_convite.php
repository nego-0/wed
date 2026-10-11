<?php
// Prova de precedência e campos opcionais, com rollback obrigatório.
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../personalizacao.php';
$cid=(int)($conn->query("SELECT id FROM {$P}casamentos ORDER BY id LIMIT 1")->fetch_assoc()['id'] ?? 0);
if (!$cid) throw new RuntimeException('A prova precisa de um casamento local.');
$engine=$conn->query("SHOW TABLE STATUS LIKE '{$P}definicoes'")->fetch_assoc()['Engine'] ?? '';
if (strtolower($engine)!=='innodb') throw new RuntimeException('A prova exige definições transaccionais.');
$anterior=casamentoAtual();
$conn->begin_transaction();
try {
    usarCasamento($cid);
    $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id={$cid} AND chave IN
        ('casal.noiva','casal.noivo','media.hero','evento.civil_hora','evento.religiosa_hora')");
    $chave='modelo.exemplo.casal.noiva';$valor='Vera de Exemplo';
    $st=$conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,?,?)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $st->bind_param('ss',$chave,$valor);$st->execute();
    esquecerDefinicoes($conn);
    $exemplo=exemploModelo($conn);$d=defsAtuais($conn);
    if ($d['casal.noiva']!=='Vera de Exemplo'||$d['media.hero']!==$exemplo['media.hero'])
        throw new RuntimeException('Os dados ausentes não seguem o exemplo do admin.');
    $r=guardarDefinicoes($conn,['casal.noiva'=>'Clara','casal.noivo'=>'Miguel',
        'evento.civil_hora'=>'','evento.religiosa_hora'=>'']);
    if ($r['invalidas']) throw new RuntimeException('Campos válidos foram rejeitados.');
    $d=defsAtuais($conn);
    if ($d['casal.noiva']!=='Clara'||$d['casal.noivo']!=='Miguel'
        ||$d['evento.civil_hora']!==''||$d['evento.religiosa_hora']!=='')
        throw new RuntimeException('Os dados próprios ou a omissão das cerimónias perderam-se.');
    echo "PASS: exemplo do admin, precedência dos noivos e cerimónias omitidas.\n";
} finally {
    $conn->rollback();usarCasamento($anterior);esquecerDefinicoes($conn);
}
