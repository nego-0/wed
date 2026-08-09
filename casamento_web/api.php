<?php
// ============================================================
// api.php — Endpoints JSON (admin, RSVP público, porteiro)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';

$acao = $_GET['action'] ?? '';

// ---- Utilitários -------------------------------------------
function corpo(): array {
    static $c = null;
    if ($c === null) $c = json_decode(file_get_contents('php://input'), true) ?: [];
    return $c;
}
function ok(array $extra = []): void  { echo json_encode(['success' => true]  + $extra); exit; }
function erro(string $m): void        { echo json_encode(['success' => false, 'message' => $m]); exit; }

/** Exige um token CSRF válido nos pedidos autenticados que alteram dados. */
function exigirCsrf(): void {
    if (!csrfValido()) {
        http_response_code(419);
        erro('Sessão expirada ou pedido inválido. Recarregue a página e tente de novo.');
    }
}

/**
 * Momento a gravar nas operações CRUD: usa a hora local do cliente
 * (enviada em "ts") quando válida; caso contrário, recorre ao NOW() do servidor.
 * Devolve um fragmento SQL seguro ('AAAA-MM-DD HH:MM:SS' ou NOW()).
 */
function tsSql(): string {
    global $conn;
    $b  = corpo();
    $ts = $b['ts'] ?? ($_GET['ts'] ?? ($_POST['ts'] ?? ''));
    if (is_string($ts) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts)) {
        return "'" . $conn->real_escape_string($ts) . "'";
    }
    return 'NOW()';
}
$TS = tsSql(); // hora local do cliente para esta requisição

// ============================================================
// EXPORTAÇÃO CSV (admin)
// ============================================================
if ($acao === 'export') {
    exigirAdmin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=convidados_isabel_abednego.csv');
    $out = fopen('php://output', 'w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Convite','Tipo','Lado','Lugares','Mesa','Estado RSVP','Confirmados','Presentes','Telefone','Membros','Codigo','Link']);
    $res = $conn->query("SELECT c.*, m.nome AS mesa_nome,
                                GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR ', ') AS membros
                         FROM {$P}convites c
                         LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                         LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                         WHERE ".soVivos($conn,'c')."
                         GROUP BY c.id ORDER BY c.nome_exibicao");
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            nomeConvite($r), $r['tipo'], $r['lado'], $r['lugares'], $r['mesa_nome'],
            $r['rsvp_estado'], $r['rsvp_confirmados'], $r['checkin_presentes'],
            $r['telefone'], $r['membros'], $r['codigo'],
            base_url().'/convite.php?c='.$r['codigo']
        ]);
    }
    fclose($out); exit;
}

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// AÇÕES PÚBLICAS (RSVP) — sem login, protegidas por código
// ============================================================
if ($acao === 'rsvp_submit') {
    $d = corpo();
    $codigo = trim($d['codigo'] ?? '');
    $c = carregarConvite($conn, $codigo, 'codigo');
    if (!$c) erro('Convite não encontrado.');

    $decisao   = ($d['decisao'] ?? '') === 'sim' ? 'sim' : 'nao';
    $mensagem  = trim($d['mensagem'] ?? '');
    $confirm   = max(0, min((int)($d['confirmados'] ?? 0), (int)$c['lugares']));
    $membros   = is_array($d['membros'] ?? null) ? $d['membros'] : [];

    if ($decisao === 'nao') {
        $estado = 'recusado'; $confirm = 0;
        // Ao recusar, repõe também a presença: quem não vem não pode ficar "presente".
        $conn->query("UPDATE {$P}convidados SET rsvp='recusado', presente=0, presente_em=NULL WHERE convite_id=".(int)$c['id']);
        $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE id=".(int)$c['id']);
    } else {
        // Atualiza cada pessoa, se a página enviou a lista nominal
        $tot = 0; $vai = 0;
        foreach ($membros as $mm) {
            $mid = (int)($mm['id'] ?? 0);
            $ok  = !empty($mm['vai']);
            $tot++; if ($ok) $vai++;
            // Quem confirma fica 'confirmado'; quem não confirma fica 'pendente' (aguarda),
            // para aparecer no card/filtro Pendentes. Recusa total trata-se no ramo 'nao'.
            $rs  = $ok ? 'confirmado' : 'pendente';
            if ($mid) {
                $q = $conn->prepare("UPDATE {$P}convidados SET rsvp=? WHERE id=? AND convite_id=?");
                $q->bind_param('sii', $rs, $mid, $c['id']); $q->execute();
            }
        }
        if ($tot > 0) {
            // Estado derivado das escolhas por pessoa
            $confirm = $vai;
            $estado  = $vai <= 0 ? 'recusado' : ($vai >= $tot ? 'confirmado' : 'parcial');
            // Caso terminal: alinha os membros ao estado (os não confirmados ficaram 'pendente' acima).
            if ($estado === 'recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE convite_id=".(int)$c['id']);
            elseif ($estado === 'confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE convite_id=".(int)$c['id']);
        } else {
            // Sem lista nominal: usa o número indicado
            if ($confirm < 1) $confirm = 1;
            $estado = ($confirm >= (int)$c['lugares']) ? 'confirmado' : 'parcial';
        }
    }

    $st = $conn->prepare("UPDATE {$P}convites
                          SET rsvp_estado=?, rsvp_confirmados=?, rsvp_mensagem=?, rsvp_em=$TS
                          WHERE id=?");
    $st->bind_param('sisi', $estado, $confirm, $mensagem, $c['id']); // string, int, string, int
    $st->execute();

    ok(['estado' => $estado, 'confirmados' => $confirm]);
}

// ============================================================
// A partir daqui: exige login
// ============================================================

// ---- Porteiro (admin ou porteiro) --------------------------
if (in_array($acao, ['porta_buscar','porta_checkin','porta_stats','porta_entradas','porta_dados'], true)) {
    exigirPorta();
    if ($acao === 'porta_checkin') exigirCsrf(); // altera dados: protegido por CSRF

    if ($acao === 'porta_stats') {
        $s = estatisticas($conn);
        ok(['presentes' => $s['presentes'], 'lug_confirm' => $s['lug_confirm'],
            'no_local' => $s['no_local'], 'convites' => $s['convites']]);
    }

    if ($acao === 'porta_dados') {
        // Cópia completa e leve da lista, para o porteiro poder procurar
        // SEM ligação à internet (guardada no dispositivo). Só o essencial.
        $r = $conn->query("SELECT c.id, c.codigo, c.nome_exibicao, c.sufixo, c.lugares,
                                  c.rsvp_estado, c.rsvp_confirmados, c.checkin_estado, c.checkin_presentes,
                                  c.observacoes, m.nome AS mesa_nome
                           FROM {$P}convites c
                           LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                           WHERE ".soVivos($conn,'c')."
                           ORDER BY c.nome_exibicao");
        $convites = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $porId = [];
        foreach ($convites as &$c) {
            $c['nome_final'] = nomeConvite($c);
            $c['membros'] = [];
            $porId[(int)$c['id']] = &$c;
        }
        unset($c);
        $rg = $conn->query("SELECT id, convite_id, nome, rsvp, presente FROM {$P}convidados
                            ORDER BY principal DESC, nome");
        if ($rg) while ($g = $rg->fetch_assoc()) {
            $cid = (int)$g['convite_id'];
            if (isset($porId[$cid])) $porId[$cid]['membros'][] = $g;
        }
        ok(['convites' => $convites, 'gerado_em' => date('c')]);
    }

    if ($acao === 'porta_entradas') {
        $r = $conn->query("SELECT id FROM {$P}convites
                           WHERE checkin_estado IN ('presente','parcial') AND ".soVivos($conn,'')."
                           ORDER BY checkin_em DESC, atualizado_em DESC");
        $ids = array_column($r->fetch_all(MYSQLI_ASSOC), 'id');
        $lista = array_map(fn($id) => carregarConvite($conn, (int)$id), $ids);
        $s = estatisticas($conn);
        ok(['entradas' => $lista, 'presentes' => $s['presentes'], 'no_local' => $s['no_local']]);
    }

    if ($acao === 'porta_buscar') {
        $termo = trim($_GET['q'] ?? '');
        if ($termo === '') erro('Indique um código ou nome.');
        // extrai código de um URL, se o QR trouxer o link completo
        if (preg_match('/[?&]c=([A-Z0-9]+)/i', $termo, $m)) $termo = $m[1];

        // 1) tenta por código exato
        $c = carregarConvite($conn, strtoupper($termo), 'codigo');
        if ($c) ok(['convite' => $c]);

        // 2) procura por nome do convite ou de um membro
        $like = "%$termo%";
        $st = $conn->prepare("SELECT DISTINCT c.id FROM {$P}convites c
                              LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                              WHERE (c.nome_exibicao LIKE ? OR g.nome LIKE ?) AND ".soVivos($conn,'c')."
                              ORDER BY c.nome_exibicao LIMIT 12");
        $st->bind_param('ss', $like, $like); $st->execute();
        $ids = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        if (!$ids) erro('Nenhum convite corresponde a "'.htmlspecialchars($termo).'".');
        if (count($ids) === 1) ok(['convite' => carregarConvite($conn, (int)$ids[0])]);
        $lista = array_map(fn($id) => carregarConvite($conn, (int)$id), $ids);
        ok(['varios' => $lista]);
    }

    if ($acao === 'porta_checkin') {
        $d = corpo();
        $id   = (int)($d['convite_id'] ?? 0);
        $modo = $d['modo'] ?? 'todos';   // 'todos' | 'membro' | 'anular'
        $mid  = (int)($d['membro_id'] ?? 0);
        $excecao = !empty($d['excecao']); // autorização manual do porteiro
        $c = carregarConvite($conn, $id);
        if (!$c) erro('Convite inválido.');

        if ($modo === 'membro' && $mid) {
            $q = $conn->prepare("SELECT presente, rsvp, nome FROM {$P}convidados WHERE id=? AND convite_id=?");
            $q->bind_param('ii', $mid, $id); $q->execute();
            $cur = $q->get_result()->fetch_assoc();
            if (!$cur) erro('Pessoa não encontrada neste convite.');
            $jaPresente = (int)$cur['presente'] === 1;
            if (!$jaPresente && $cur['rsvp'] !== 'confirmado' && !$excecao) {
                erro(($cur['nome'] ?: 'Esta pessoa') . ' não confirmou presença e não pode dar entrada.');
            }
            $novo = $jaPresente ? 0 : 1;
            $q = $conn->prepare("UPDATE {$P}convidados SET presente=?, presente_em=".($novo?$TS:'NULL')." WHERE id=? AND convite_id=?");
            $q->bind_param('iii', $novo, $mid, $id); $q->execute();
            recalcularCheckin($conn, $id, $TS);
        } elseif ($modo === 'anular') {
            $conn->query("UPDATE {$P}convidados SET presente=0, presente_em=NULL WHERE convite_id=$id");
            $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE id=$id");
        } else { // 'todos'
            if (count($c['membros']) > 0) {
                if ($excecao) {
                    // Entrada excecional autorizada: admite todas as pessoas do convite
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE convite_id=$id");
                } else {
                    $r = $conn->query("SELECT COUNT(*) n FROM {$P}convidados WHERE convite_id=$id AND rsvp='confirmado'");
                    $nconf = (int)$r->fetch_assoc()['n'];
                    if ($nconf === 0) erro('Ninguém neste convite confirmou presença. Não é possível dar entrada.');
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE convite_id=$id AND rsvp='confirmado'");
                }
                recalcularCheckin($conn, $id, $TS);
            } else {
                if (!$excecao && !in_array($c['rsvp_estado'], ['confirmado','parcial'], true)) {
                    erro('Este convite não confirmou presença. Não é possível dar entrada.');
                }
                $n = (int)($c['rsvp_confirmados'] ?: $c['lugares']);
                $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado='presente', checkin_presentes=?, checkin_em=$TS WHERE id=?");
                $st->bind_param('ii', $n, $id); $st->execute();
            }
        }
        $qual = ['membro'=>'entrada de 1 pessoa', 'anular'=>'entrada anulada'][$modo] ?? 'entrada do convite';
        registar($conn, 'checkin', $c['nome_final'] ?? '', $qual . ($excecao ? ' (excecional)' : ''));
        ok(['convite' => carregarConvite($conn, $id)]);
    }
}

// ---- Admin --------------------------------------------------
exigirAdmin();

// Endpoints de admin que alteram dados: exigem token CSRF válido.
if (in_array($acao, ['convite_save','convite_delete','convite_flag','convite_rsvp_manual',
                     'mesa_save','mesa_delete','mesa_pos','convite_mesa','convidado_mesa','importar',
                     'mesa_noivos','planta_size','planta_bloqueio','convidado_papel','defs_save','def_upload',
                     'convite_restaurar','versao_criar','versao_aplicar','versao_atualizar',
                     'versao_renomear','versao_apagar'], true)) {
    exigirCsrf();
}

// ---- Personalização do convite digital ---------------------
if ($acao === 'defs_save') {
    $d = corpo();
    $defs = is_array($d['defs'] ?? null) ? $d['defs'] : [];
    $r = guardarDefinicoes($conn, $defs);
    // Alterar o convite passa a deixar rasto, como já acontece com os convites.
    if ($r['gravadas'] || $r['repostas']) {
        registar($conn, 'convite_editado_defs', '',
                 $r['gravadas'].' alterada(s), '.$r['repostas'].' reposta(s)');
    }
    ok($r);
}

// ---- Versões dos convites -----------------------------------
// Cada peça (digital / impresso) tem as suas versões, e uma delas está em
// vigor — a predefinida. Tornar predefinida é aplicar: as definições dessa
// versão passam a ser as que o convite usa.

/** Âmbito pedido, validado. */
function ambitoPedido(): string {
    $a = $_GET['ambito'] ?? (corpo()['ambito'] ?? 'digital');
    return isset(ambitosVersao()[$a]) ? $a : 'digital';
}

if ($acao === 'versao_criar') {
    $d = corpo();
    $ambito = ambitoPedido();
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('Dê um nome à versão, para a reconhecer mais tarde.');

    $st = $conn->prepare("SELECT COUNT(*) FROM {$P}versoes WHERE ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    if ((int)$st->get_result()->fetch_row()[0] >= VERSOES_MAX) {
        erro('Chegou ao máximo de '.VERSOES_MAX.' versões desta peça. Apague uma para guardar outra.');
    }
    $json = jsonOuNulo(instantaneoAmbito($conn, $ambito));
    if ($json === null) erro('Não foi possível preparar a versão.');

    $u = utilizadorAtual() ?? '';
    $st = $conn->prepare("INSERT INTO {$P}versoes (nome, defs, utilizador, ambito) VALUES (?,?,?,?)");
    $st->bind_param('ssss', $nome, $json, $u, $ambito);
    if (!$st->execute()) erro('Não foi possível guardar a versão.');
    $id = $conn->insert_id;
    // Uma versão acabada de guardar É a peça neste momento — foi tirada dela.
    // Antes só a primeira ficava marcada, e a marca ficava presa numa versão
    // antiga enquanto o convite já mostrava outra coisa.
    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE id=$id");
    registar($conn, 'versao_guardada', $nome, ambitosVersao()[$ambito]['rotulo']);
    ok(['id' => $id]);
}

if ($acao === 'versao_lista') {
    $ambito = ambitoPedido();
    $st = $conn->prepare("SELECT id, nome, utilizador, criado_em, atualizado_em, predefinida, defs
                          FROM {$P}versoes WHERE ambito=? ORDER BY predefinida DESC, id DESC");
    $st->bind_param('s', $ambito); $st->execute();
    $linhas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = [];
    $algumaEmVigor = false;

    foreach ($linhas as $v) {
        // "Em vigor" é uma verdade sobre a peça, não uma marca guardada: é-o a
        // versão cujo conteúdo bate certo com o que a peça mostra agora. Uma
        // marca guardada acabava a mentir — dizia "em vigor" numa versão
        // enquanto o convite enviado mostrava outra coisa.
        $v['em_vigor'] = versaoIgualAoAtual($conn, $ambito, $v['defs']);
        if ($v['em_vigor']) $algumaEmVigor = true;
        unset($v['defs']);                       // a lista não precisa do conteúdo
        $v['escolhida'] = (int)$v['predefinida']; // a última que o utilizador aplicou
        unset($v['predefinida']);
        $v['padrao'] = 0;
        $out[] = $v;
    }
    // As que estão em vigor à cabeça; depois as mais recentes.
    usort($out, fn($a, $b) => ($b['em_vigor'] <=> $a['em_vigor']) ?: ($b['id'] <=> $a['id']));

    // A versão padrão fecha a lista, sempre no mesmo sítio: é a peça como o
    // sistema a traz de origem — o ponto de regresso. Não vem da tabela, por
    // isso não se apaga nem se reescreve; quem a editar guarda com outro nome.
    $noPadrao = noPadrao($conn, $ambito);
    if ($noPadrao) $algumaEmVigor = true;
    $out[] = ['id' => VERSAO_PADRAO_ID, 'nome' => VERSAO_PADRAO_NOME, 'utilizador' => null,
              'criado_em' => null, 'atualizado_em' => null,
              'em_vigor' => $noPadrao, 'escolhida' => 0, 'padrao' => 1];

    ok(['versoes' => $out, 'max' => VERSOES_MAX, 'ambito' => $ambito,
        'rotulo' => ambitosVersao()[$ambito]['rotulo'],
        // Sem nenhuma a bater certo, a peça tem alterações que não estão
        // guardadas em versão nenhuma — e o painel tem de o dizer.
        'alguma_em_vigor' => $algumaEmVigor]);
}

if ($acao === 'versao_aplicar') {
    // Torna esta a versão em vigor: aplica as suas definições e marca-a.
    $id = (int)($_GET['id'] ?? 0);

    // A padrão não está na tabela: aplicá-la é devolver a peça à origem.
    if ($id === VERSAO_PADRAO_ID) {
        $ambito = ambitoPedido();
        $r = aplicarPadrao($conn, $ambito);
        $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE ambito=?");
        $st->bind_param('s', $ambito); $st->execute();
        registar($conn, 'versao_aplicada', VERSAO_PADRAO_NOME, $r['repostas'].' definição(ões)');
        ok($r + ['nome' => VERSAO_PADRAO_NOME, 'ambito' => $ambito]);
    }

    $st = $conn->prepare("SELECT nome, defs, ambito FROM {$P}versoes WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    if (!$v) erro('Versão não encontrada.');
    $j = json_decode($v['defs'], true);
    if (!is_array($j)) erro('Esta versão está ilegível.');

    // Só as chaves do próprio âmbito: aplicar uma versão do cartão não pode
    // mexer no convite digital, nem o contrário.
    $permitidas = array_flip(chavesDoAmbito($v['ambito']));
    $defs = [];
    foreach ($j as $k => $val) if (isset($permitidas[$k]) && is_string($val)) $defs[$k] = $val;
    $r = guardarDefinicoes($conn, $defs);

    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE ambito=?");
    $st->bind_param('s', $v['ambito']); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE id=$id");

    registar($conn, 'versao_aplicada', $v['nome'], $r['gravadas'].' definição(ões)');
    ok($r + ['nome' => $v['nome'], 'ambito' => $v['ambito']]);
}

if ($acao === 'versao_atualizar') {
    // Reescreve o conteúdo da versão com o que está em vigor agora.
    $id = (int)($_GET['id'] ?? 0);
    // A versão padrão é a peça de origem: não se reescreve.
    if ($id === VERSAO_PADRAO_ID) erro('A versão «'.VERSAO_PADRAO_NOME.'» é a peça de origem: não se reescreve. Guarde as suas alterações como uma versão nova.');
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}versoes WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    if (!$v) erro('Versão não encontrada.');
    $json = jsonOuNulo(instantaneoAmbito($conn, $v['ambito']));
    if ($json === null) erro('Não foi possível preparar a versão.');
    $st = $conn->prepare("UPDATE {$P}versoes SET defs=?, atualizado_em=NOW() WHERE id=?");
    $st->bind_param('si', $json, $id);
    if (!$st->execute()) erro('Não foi possível atualizar a versão.');
    registar($conn, 'versao_atualizada', $v['nome'], '');
    ok(['nome' => $v['nome']]);
}

if ($acao === 'versao_renomear') {
    $d = corpo();
    $id = (int)($_GET['id'] ?? ($d['id'] ?? 0));
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') erro('O nome não pode ficar vazio.');
    // A versão padrão é a peça de origem: não se renomeia.
    if ($id === VERSAO_PADRAO_ID) erro('A versão «'.VERSAO_PADRAO_NOME.'» é a peça de origem: não muda de nome. Guarde as suas alterações como uma versão nova.');
    $st = $conn->prepare("UPDATE {$P}versoes SET nome=? WHERE id=?");
    $st->bind_param('si', $nome, $id);
    if (!$st->execute()) erro('Não foi possível mudar o nome.');
    registar($conn, 'versao_renomeada', $nome, 'id '.$id);
    ok(['nome' => $nome]);
}

if ($acao === 'versao_apagar') {
    $id = (int)($_GET['id'] ?? 0);
    // A versão padrão é a peça de origem: não se apaga.
    if ($id === VERSAO_PADRAO_ID) erro('A versão «'.VERSAO_PADRAO_NOME.'» é a peça de origem: não se apaga. Guarde as suas alterações como uma versão nova.');
    $rn = $conn->prepare("SELECT nome, ambito, predefinida FROM {$P}versoes WHERE id=?");
    $rn->bind_param('i', $id); $rn->execute();
    $x = $rn->get_result()->fetch_assoc();
    if (!$x) erro('Versão não encontrada.');
    $st = $conn->prepare("DELETE FROM {$P}versoes WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar a versão.');
    // Apagar não muda a peça — só se perde o ponto de regresso. Antes promovia-se
    // outra a "em vigor" sem lhe aplicar nada, o que era falso: ficava marcada
    // uma versão cujo conteúdo não era o que o convite mostrava.
    registar($conn, 'versao_apagada', $x['nome'], ambitosVersao()[$x['ambito']]['rotulo']);
    ok();
}

if ($acao === 'def_upload') {
    // Upload de imagem/música do convite (grava o ficheiro e a definição).
    $chave = $_POST['chave'] ?? '';
    $tiposImg = ['media.hero','media.historia','media.interludio','media.acesso'];
    $ehMusica = $chave === 'media.musica';
    if (!$ehMusica && !in_array($chave, $tiposImg, true)) erro('Campo de ficheiro inválido.');
    if (empty($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) erro('Falha no envio do ficheiro.');
    $f = $_FILES['ficheiro'];
    $max = $ehMusica ? 8*1024*1024 : 5*1024*1024;
    if ($f['size'] > $max) erro('Ficheiro demasiado grande (máx. ' . ($ehMusica ? '8' : '5') . ' MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $extsOk = $ehMusica ? ['m4a','mp3'] : ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $extsOk, true)) erro('Formato não suportado (' . implode('/', $extsOk) . ').');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
        $mimesOk = $ehMusica ? ['audio/mp4','audio/x-m4a','audio/mpeg','video/mp4','audio/mp3']
                             : ['image/jpeg','image/png','image/webp'];
        if (!in_array($mt, $mimesOk, true)) erro('O conteúdo do ficheiro não corresponde ao formato.');
    }
    $dir = __DIR__ . '/assets/convite/custom';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nomeFich = str_replace('media.', '', $chave) . '-' . time() . '-' . random_int(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nomeFich")) erro('Não foi possível guardar o ficheiro.');
    // Apaga o ficheiro custom anterior desta chave (nunca os originais), a não
    // ser que alguma versão guardada ainda o use — aplicá-la traria de volta um
    // caminho sem ficheiro por trás.
    $antigo = defsAtuais($conn)[$chave] ?? '';
    if (str_starts_with($antigo, 'assets/convite/custom/') && !str_contains($antigo, '..')
        && !ficheiroEmVersao($conn, $antigo)) {
        @unlink(__DIR__ . '/' . $antigo);
    }
    $caminho = 'assets/convite/custom/' . $nomeFich;
    guardarDefinicoes($conn, [$chave => $caminho]);
    ok(['path' => $caminho]);
}

if ($acao === 'convite_list') {
    $tipo=$_GET['tipo']??''; $lado=$_GET['lado']??''; $estado=$_GET['estado']??'';
    $mesa=$_GET['mesa']??''; $busca=trim($_GET['busca']??'');
    $impresso=$_GET['impresso']??''; $enviado=$_GET['enviado']??'';
    $genero=$_GET['genero']??''; $brinde=$_GET['brinde']??'';
    $temGen = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBri = colunaExiste($conn, "{$P}convidados", 'brinde');
    $temPapel = colunaExiste($conn, "{$P}convidados", 'papel');
    $exprGen = $temGen ? "COALESCE(g.genero,'')" : "''";
    $exprBri = $temBri ? "g.brinde" : "0";
    // Id da mesa dos noivos: padrinhos/madrinhas sentam-se lá pelo papel (não por mesa_id).
    $noivosId = 0;
    if ($temPapel) { $nr=$conn->query("SELECT id FROM {$P}mesas WHERE especial='noivos' LIMIT 1"); if ($nr && $row=$nr->fetch_row()) $noivosId=(int)$row[0]; }
    // Mesa EFETIVA de um membro (alias): a dos noivos se for padrinho/madrinha, senão a própria/do convite.
    $effMesa = function(string $a) use ($temPapel,$noivosId) {
        return $temPapel
            ? "CASE WHEN {$a}.papel IN ('padrinho','madrinha') THEN ".($noivosId?:'NULL')." ELSE COALESCE({$a}.mesa_id, c.mesa_id) END"
            : "COALESCE({$a}.mesa_id, c.mesa_id)";
    };
    $temMesaExpr = fn(string $a) => $temPapel ? "({$a}.mesa_id IS NOT NULL OR {$a}.papel IN ('padrinho','madrinha'))" : "{$a}.mesa_id IS NOT NULL";
    $w="WHERE ".soVivos($conn,'c'); $t=''; $p=[];   // fora os que estão na reciclagem
    if (in_array($tipo,['digital','fisico','ambos'],true))            { $w.=" AND c.tipo=?"; $t.='s'; $p[]=$tipo; }
    if (in_array($lado,['noivo','noiva','ambos'],true))              { $w.=" AND c.lado=?"; $t.='s'; $p[]=$lado; }
    // Filtro por estado: além do estado do convite, inclui convites com um integrante
    // nesse estado (ex.: "pendentes" mostra também os parciais com gente ainda pendente).
    if ($estado==='pendente') {
        // Pendentes inclui os totalmente pendentes, os parciais (têm lugares por confirmar)
        // e os que têm algum integrante ainda pendente.
        $w.=" AND (c.rsvp_estado IN ('pendente','parcial') OR EXISTS(SELECT 1 FROM {$P}convidados ge WHERE ge.convite_id=c.id AND ge.rsvp='pendente'))";
    } elseif (in_array($estado,['confirmado','recusado'],true)) {
        $w.=" AND (c.rsvp_estado=? OR EXISTS(SELECT 1 FROM {$P}convidados ge WHERE ge.convite_id=c.id AND ge.rsvp=?))";
        $t.='ss'; $p[]=$estado; $p[]=$estado;
    } elseif ($estado==='parcial') { $w.=" AND c.rsvp_estado='parcial'"; }
    if ($impresso==='1') { $w.=" AND c.impresso=1"; }
    if ($impresso==='0') { $w.=" AND c.impresso=0 AND c.tipo IN ('fisico','ambos')"; }
    if ($enviado==='1')  { $w.=" AND c.enviado=1"; }
    // Convites com pelo menos um integrante do género escolhido / que recebe brinde (só se as colunas existirem)
    if ($temGen && in_array($genero,['m','f'],true)) { $w.=" AND EXISTS (SELECT 1 FROM {$P}convidados gg WHERE gg.convite_id=c.id AND gg.genero=?)"; $t.='s'; $p[]=$genero; }
    if ($temBri && $brinde==='1')                    { $w.=" AND EXISTS (SELECT 1 FROM {$P}convidados gb WHERE gb.convite_id=c.id AND gb.brinde=1)"; }
    if ($mesa==='__SEM_MESA__') {
        // Com pelo menos um lugar por colocar: sem mesa de convite e com alguém (ou lugar sem nome) ainda por sentar.
        $w.=" AND c.mesa_id IS NULL AND ( EXISTS (SELECT 1 FROM {$P}convidados gs WHERE gs.convite_id=c.id AND gs.mesa_id IS NULL)
                                        OR c.lugares > (SELECT COUNT(*) FROM {$P}convidados gn WHERE gn.convite_id=c.id) )";
    }
    elseif ($mesa!=='') {
        // Presença EFETIVA nesta mesa: um membro sentado lá (mesa própria, senão a do convite),
        // ou lugares sem nome de um convite cuja mesa é esta. Se a mesa filtrada for a dos
        // noivos, inclui também padrinhos/madrinhas (que lá se sentam pelo papel, não por mesa_id).
        $exprPad = $temPapel
            ? " OR EXISTS (SELECT 1 FROM {$P}convidados gp WHERE gp.convite_id=c.id AND gp.papel IN ('padrinho','madrinha')
                           AND EXISTS (SELECT 1 FROM {$P}mesas mn WHERE mn.especial='noivos' AND mn.nome=?))"
            : "";
        $w.=" AND ( EXISTS (SELECT 1 FROM {$P}convidados gm JOIN {$P}mesas mm ON mm.id=COALESCE(gm.mesa_id,c.mesa_id)
                            WHERE gm.convite_id=c.id AND mm.nome=?)
                  OR ( m.nome=? AND c.lugares > (SELECT COUNT(*) FROM {$P}convidados gc WHERE gc.convite_id=c.id) )
                  $exprPad )";
        $t.='ss'; $p[]=$mesa; $p[]=$mesa;
        if ($temPapel) { $t.='s'; $p[]=$mesa; }
    }
    if ($busca!==''){ $w.=" AND (c.nome_exibicao LIKE ? OR c.codigo LIKE ? OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.nome LIKE ?))";
                      $t.='sss'; $l="%$busca%"; $p[]=$l; $p[]=$l; $p[]=$l; }
    $sql="SELECT c.*, m.nome AS mesa_nome,
                 COALESCE(
                   (SELECT mm.nome FROM {$P}convidados g4 JOIN {$P}mesas mm ON mm.id={$effMesa('g4')}
                      WHERE g4.convite_id=c.id ORDER BY {$temMesaExpr('g4')} DESC, mm.nome LIMIT 1),
                   m.nome
                 ) AS mesa_efetiva_nome,
                 GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR '||') AS membros_txt,
                 GROUP_CONCAT(CONCAT_WS('\x1f', g.nome, $exprGen, $exprBri)
                              ORDER BY g.principal DESC, g.nome SEPARATOR '\x1e') AS membros_det,
                 (SELECT COUNT(DISTINCT {$effMesa('g2')})
                    FROM {$P}convidados g2 WHERE g2.convite_id=c.id) AS mesas_distintas
          FROM {$P}convites c
          LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
          LEFT JOIN {$P}convidados g ON g.convite_id=c.id
          $w GROUP BY c.id ORDER BY c.nome_exibicao";

    // Quantos convites correspondem ao filtro (para o "mostrar mais" saber
    // quantos faltam). É uma consulta leve: só conta, não junta membros.
    $sqlTotal = "SELECT COUNT(*) FROM {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id $w";
    $stc = $conn->prepare($sqlTotal);
    if ($t) $stc->bind_param($t, ...$p);
    $stc->execute();
    $total = (int)($stc->get_result()->fetch_row()[0] ?? 0);

    // Traz-se um pedaço de cada vez: com centenas de convites, mandar tudo de
    // uma vez enche a rede e o telemóvel demora a desenhar a lista.
    $porPag = (int)($_GET['por_pagina'] ?? LISTA_POR_PAGINA);
    $porPag = max(10, min(1000, $porPag));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $sql   .= " LIMIT " . $porPag . " OFFSET " . (($pagina - 1) * $porPag);

    $st=$conn->prepare($sql);
    if ($t) $st->bind_param($t, ...$p);
    $st->execute();
    $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['nome_final']=nomeConvite($r);
        $r['membros']=$r['membros_txt']?explode('||',$r['membros_txt']):[];
        // Detalhe por integrante (nome, género, brinde) para as pastilhas com ícones.
        $det = (string)($r['membros_det'] ?? '');
        $lista=[];
        if ($det!=='') foreach (explode("\x1e", $det) as $linha) {
            $c3=explode("\x1f", $linha);
            $lista[]=['nome'=>$c3[0]??'', 'genero'=>$c3[1]??'', 'brinde'=>(int)($c3[2]??0)];
        }
        $r['membros_det']=$lista;
        unset($r['membros_txt']);
    }
    unset($r);
    ok(['convites'=>$rows, 'stats'=>estatisticas($conn), 'mesas'=>listarMesas($conn),
        'total'=>$total, 'pagina'=>$pagina, 'por_pagina'=>$porPag,
        'ha_mais'=>($pagina * $porPag) < $total]);
}

if ($acao === 'convite_get') {
    $c = carregarConvite($conn, (int)($_GET['id']??0));
    $c ? ok(['convite'=>$c]) : erro('Convite não encontrado.');
}

if ($acao === 'convite_save') {
    $d = corpo();
    $id       = (int)($d['id'] ?? 0);
    $nome     = trim($d['nome_exibicao'] ?? '');
    if ($nome === '') erro('O nome do convite é obrigatório.');
    $tipo     = in_array($d['tipo']??'',['digital','fisico','ambos'],true)?$d['tipo']:'digital';
    $lado     = in_array($d['lado']??'',['noivo','noiva','ambos'],true)?$d['lado']:'noivo';
    $telefone = trim($d['telefone'] ?? ''); if ($telefone==='') $telefone=null;
    $obs      = trim($d['observacoes'] ?? ''); if ($obs==='') $obs=null;
    $msgP     = trim($d['msg_pessoal'] ?? ''); if ($msgP==='') $msgP=null;
    $mesaId   = trim($d['mesa']??'')!=='' ? resolverMesa($conn, $d['mesa']) : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) $mesaId = null; // a mesa dos noivos não é atribuível a convites
    $mostrarNM = !empty($d['mostrar_num_mesa']) ? 1 : 0;
    $membros  = is_array($d['membros'] ?? null) ? $d['membros'] : [];

    // Os lugares são as pessoas convidadas: contam-se os nomes em vez de se
    // pedirem à parte. Um campo separado só podia discordar da lista — e a
    // importação já fazia esta conta. Mínimo de 1, para um convite nunca
    // ficar sem lugar nenhum enquanto o nome não é escrito.
    $nomeados = 0;
    foreach ($membros as $m) { if (trim(is_array($m) ? ($m['nome'] ?? '') : $m) !== '') $nomeados++; }
    $lugares = max(1, $nomeados);

    $novoConvite = !$id;
    if ($id) {
        // 'sufixo' fica de fora: já não se pede no formulário, e reescrevê-lo
        // aqui apagaria o que convites antigos ainda tenham guardado.
        $st=$conn->prepare("UPDATE {$P}convites SET nome_exibicao=?,mostrar_num_mesa=?,tipo=?,lado=?,lugares=?,mesa_id=?,telefone=?,observacoes=?,msg_pessoal=?,atualizado_em=$TS WHERE id=?");
        $st->bind_param('sissiisssi',$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP,$id);
        $st->execute();
    } else {
        $codigo=gerarCodigo($conn);
        $st=$conn->prepare("INSERT INTO {$P}convites (codigo,nome_exibicao,mostrar_num_mesa,tipo,lado,lugares,mesa_id,telefone,observacoes,msg_pessoal,criado_em,atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
        $st->bind_param('ssissiisss',$codigo,$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP);
        $st->execute(); $id=$conn->insert_id;
    }

    // Preserva estados (rsvp/presença/mesa individual) por nome, antes de reconstruir a lista de membros.
    // SELECT * para tolerar colunas que possam ainda não existir na BD (esquema por migrar).
    $anterior=[];
    $r=$conn->query("SELECT * FROM {$P}convidados WHERE convite_id=$id");
    if ($r) while($x=$r->fetch_assoc()) $anterior[strtolower(trim($x['nome']))]=$x;

    // Colunas opcionais: só entram no INSERT se existirem (evita 500 por "Unknown column").
    $temPapel  = colunaExiste($conn, "{$P}convidados", 'papel');
    $temGenero = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBrinde = colunaExiste($conn, "{$P}convidados", 'brinde');

    $presenca = in_array($d['presenca'] ?? '', ['pendente','confirmado','parcial','recusado'], true) ? $d['presenca'] : '';

    $conn->query("DELETE FROM {$P}convidados WHERE convite_id=$id");
    $primeiro=true; $vaiCount=0; $totMembros=0;
    foreach ($membros as $m) {
        $mn=trim(is_array($m)?($m['nome']??''):$m);
        if ($mn==='') continue;
        $totMembros++;
        $vai = is_array($m) ? !empty($m['vai']) : false;
        $princ=$primeiro?1:0; $primeiro=false;
        $ant=$anterior[strtolower($mn)] ?? null;
        // Estado RSVP de cada pessoa, conforme a presença escolhida no painel
        if     ($presenca==='confirmado') $rsvp='confirmado';
        elseif ($presenca==='recusado')   $rsvp='recusado';
        // Parcial: quem confirma fica 'confirmado'; quem ainda não confirmou fica 'pendente'
        // (aguarda resposta), não 'recusado'. Assim aparece no card/filtro Pendentes.
        elseif ($presenca==='parcial')  { $rsvp = $vai?'confirmado':'pendente'; if($vai)$vaiCount++; }
        elseif ($presenca==='pendente')   $rsvp='pendente';
        else                              $rsvp = $ant['rsvp'] ?? 'pendente'; // sem presença: preserva
        $pres=(int)($ant['presente'] ?? 0);
        // Mesa individual: se o editor a enviou (chave 'mesa_id' presente), usa-a;
        // caso contrário, preserva a que já existia (por nome).
        if (is_array($m) && array_key_exists('mesa_id', $m)) {
            $mesaMembro = ($m['mesa_id']!=='' && $m['mesa_id']!==null) ? (int)$m['mesa_id'] : null;
        } else {
            $mesaMembro = isset($ant['mesa_id']) && $ant['mesa_id']!==null ? (int)$ant['mesa_id'] : null;
        }
        // Papel (padrinho/madrinha): se o editor o enviou, usa-o; senão preserva o anterior (por nome).
        if (is_array($m) && array_key_exists('papel', $m)) {
            $papelMembro = in_array($m['papel'], ['padrinho','madrinha'], true) ? $m['papel'] : null;
        } else {
            $papelMembro = in_array($ant['papel'] ?? '', ['padrinho','madrinha'], true) ? $ant['papel'] : null;
        }
        // Género ('m'/'f') e "Recebe Brinde": enviados pelo editor; senão preserva o anterior (por nome).
        if (is_array($m) && array_key_exists('genero', $m)) {
            $genMembro = in_array($m['genero'], ['m','f'], true) ? $m['genero'] : null;
        } else {
            $genMembro = in_array($ant['genero'] ?? '', ['m','f'], true) ? $ant['genero'] : null;
        }
        if (is_array($m) && array_key_exists('brinde', $m)) {
            $brindeMembro = !empty($m['brinde']) ? 1 : 0;
        } else {
            $brindeMembro = (int)($ant['brinde'] ?? 0) === 1 ? 1 : 0;
        }
        // INSERT construído dinamicamente com as colunas existentes (tolerante a esquema por migrar).
        $cols=['convite_id','nome','principal','rsvp','presente','presente_em','mesa_id'];
        $plc =['?','?','?','?','?', ($pres?$TS:'NULL'), '?'];
        $typ ='isisii'; $val=[$id,$mn,$princ,$rsvp,$pres,$mesaMembro];
        if ($temPapel)  { $cols[]='papel';  $plc[]='?'; $typ.='s'; $val[]=$papelMembro; }
        if ($temGenero) { $cols[]='genero'; $plc[]='?'; $typ.='s'; $val[]=$genMembro; }
        if ($temBrinde) { $cols[]='brinde'; $plc[]='?'; $typ.='i'; $val[]=$brindeMembro; }
        $q=$conn->prepare("INSERT INTO {$P}convidados (".implode(',',$cols).") VALUES (".implode(',',$plc).")");
        if ($q) { $q->bind_param($typ, ...$val); $q->execute(); }
    }
    recalcularCheckin($conn,$id,$TS); // atualiza contadores de presença; não toca no RSVP

    // Aplica a presença escolhida ao convite
    if ($presenca !== '') {
        if ($presenca === 'confirmado') {
            $conn->query("UPDATE {$P}convites SET rsvp_estado='confirmado', rsvp_confirmados=$lugares, rsvp_em=$TS WHERE id=$id");
        } elseif ($presenca === 'recusado') {
            $conn->query("UPDATE {$P}convites SET rsvp_estado='recusado', rsvp_confirmados=0, rsvp_em=$TS WHERE id=$id");
        } elseif ($presenca === 'parcial') {
            if ($totMembros > 0) {
                // Presença exata: contagem e estado derivados das marcações individuais
                $estado = $vaiCount<=0 ? 'recusado' : ($vaiCount>=$totMembros ? 'confirmado' : 'parcial');
                $conn->query("UPDATE {$P}convites SET rsvp_estado='$estado', rsvp_confirmados=$vaiCount, rsvp_em=$TS WHERE id=$id");
                // Se afinal o convite é totalmente confirmado/recusado, alinha os membros a esse estado
                // (os não confirmados ficaram 'pendente' acima; aqui reconcilia-se o caso terminal).
                if ($estado==='recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE convite_id=$id");
                elseif ($estado==='confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE convite_id=$id");
            } else {
                $conn->query("UPDATE {$P}convites SET rsvp_estado='parcial', rsvp_confirmados=COALESCE(rsvp_confirmados,1), rsvp_em=$TS WHERE id=$id");
            }
        } else { // pendente
            $conn->query("UPDATE {$P}convites SET rsvp_estado='pendente', rsvp_confirmados=NULL, rsvp_em=NULL WHERE id=$id");
        }
    }

    registar($conn, $novoConvite ? 'convite_criado' : 'convite_editado', $nome, 'id '.$id);
    ok(['convite'=>carregarConvite($conn,$id),'stats'=>estatisticas($conn)]);
}

if ($acao === 'convite_delete') {
    // Eliminação REVERSÍVEL: o convite sai das listas mas fica recuperável.
    // (?definitivo=1 apaga mesmo, usado ao esvaziar a reciclagem.)
    $id  = (int)($_GET['id'] ?? 0);
    $def = !empty($_GET['definitivo']);
    $nome = '';
    $rn = $conn->prepare("SELECT nome_exibicao FROM {$P}convites WHERE id=?");
    $rn->bind_param('i',$id); $rn->execute();
    if ($x = $rn->get_result()->fetch_assoc()) $nome = $x['nome_exibicao'];

    if ($def) {
        $st = $conn->prepare("DELETE FROM {$P}convites WHERE id=?");
    } else {
        $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=$TS WHERE id=?");
    }
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível eliminar.');
    registar($conn, $def ? 'convite_apagado' : 'convite_eliminado', $nome, 'id '.$id);
    ok(['stats'=>estatisticas($conn), 'id'=>$id, 'nome'=>$nome, 'reversivel'=>!$def]);
}

if ($acao === 'convite_restaurar') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=NULL WHERE id=?");
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível repor o convite.');
    registar($conn, 'convite_reposto', '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

if ($acao === 'esquema_info') {
    // Retrato do esqueleto de dados. Serve para uma prova poder afirmar que a
    // migração para vários casamentos ficou bem feita — sobretudo que nenhum
    // dado ficou sem dono, que é o que separa isto de uma fuga entre casais.
    $um = function (string $sql) use ($conn) {
        $r = @$conn->query($sql); return $r ? (int)$r->fetch_row()[0] : -1;
    };
    $orfaos = 0;
    foreach (['convites','convidados','mesas','versoes','registo'] as $t) {
        $orfaos += max(0, $um("SELECT COUNT(*) FROM {$P}$t WHERE casamento_id IS NULL OR casamento_id < 1"));
    }
    // As definições do sistema (versão do esquema) vivem no casamento 0.
    $sistemaFora = $um("SELECT COUNT(*) FROM {$P}definicoes WHERE chave='schema.versao' AND casamento_id=0") === 1;
    // O nome da mesa tem de ser único por casamento, não em toda a tabela.
    $mesaOk = false;
    $rk = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='uq_mesa_nome'");
    if ($rk) {
        $cols = [];
        while ($x = $rk->fetch_assoc()) $cols[] = $x['Column_name'];
        $mesaOk = in_array('casamento_id', $cols, true) && in_array('nome', $cols, true);
    }
    ok(['esquema' => [
        'versao'      => ESQUEMA_VERSAO,
        'casamentos'  => $um("SELECT COUNT(*) FROM {$P}casamentos"),
        'contas'      => $um("SELECT COUNT(*) FROM {$P}utilizadores"),
        'acessos'     => $um("SELECT COUNT(*) FROM {$P}acessos"),
        'orfaos'      => $orfaos,
        'sistema_fora_de_casamento' => $sistemaFora,
        'mesa_unica_por_casamento'  => $mesaOk,
    ]]);
}

if ($acao === 'reciclagem') {
    // Convites eliminados (recuperáveis). Ao abrir a reciclagem aproveita-se
    // para deitar fora o que já lá está há mais de RECICLAGEM_DIAS — assim a
    // limpeza acontece sozinha, sem uma tarefa agendada no servidor.
    @$conn->query("DELETE FROM {$P}convites
                   WHERE eliminado_em IS NOT NULL
                     AND eliminado_em < DATE_SUB(NOW(), INTERVAL ".RECICLAGEM_DIAS." DAY)");
    $r = $conn->query("SELECT id, codigo, nome_exibicao, lugares, eliminado_em
                       FROM {$P}convites WHERE eliminado_em IS NOT NULL
                       ORDER BY eliminado_em DESC");
    ok(['convites' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [], 'dias' => RECICLAGEM_DIAS]);
}

if ($acao === 'registo_lista') {
    // O histórico só cresce: se mandássemos tudo, ao fim de um mês eram
    // milhares de linhas em cada abertura da janela. Vai por pedaços.
    $porPag = max(10, min(500, (int)($_GET['por_pagina'] ?? 100)));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $total  = (int)(@$conn->query("SELECT COUNT(*) FROM {$P}registo")?->fetch_row()[0] ?? 0);
    $r = $conn->query("SELECT utilizador, papel, accao, alvo, detalhe, criado_em
                       FROM {$P}registo ORDER BY id DESC
                       LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag));
    ok(['registos' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'total' => $total, 'pagina' => $pagina, 'ha_mais' => ($pagina * $porPag) < $total]);
}

if ($acao === 'convite_flag') {
    $id=(int)($_GET['id']??0); $campo=$_GET['campo']??''; $valor=!empty($_GET['valor'])?1:0;
    if (!in_array($campo,['impresso','enviado'],true)) erro('Campo inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET $campo=?, atualizado_em=$TS WHERE id=?");
    $st->bind_param('ii',$valor,$id); $st->execute();
    registar($conn, $campo.($valor?'_sim':'_nao'), '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

if ($acao === 'convite_rsvp_manual') {
    $id=(int)($_GET['id']??0); $estado=$_GET['estado']??'';
    if (!in_array($estado,['pendente','confirmado','recusado','parcial'],true)) erro('Estado inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET rsvp_estado=?, rsvp_em=$TS WHERE id=?");
    $st->bind_param('si',$estado,$id); $st->execute();
    registar($conn, 'rsvp_manual', '', 'id '.$id.' -> '.$estado);
    ok(['stats'=>estatisticas($conn)]);
}

// ---- Mesas --------------------------------------------------
const FORMAS_MESA = ['redonda','oval','quadrada','retangular','comprida','ferradura'];
const CORES_MESA  = ['neutra','verde','ouro','terracota','azul','ameixa','rosa','salva'];

const TAMANHOS_MESA = ['auto','p','m','g'];

if ($acao === 'mesa_list') { ok(['mesas'=>listarMesas($conn), 'canvas'=>plantaConfig($conn)]); }
if ($acao === 'planta_size') {
    // Guarda as dimensões do canvas da planta (px), definidas ao arrastar as bordas.
    $d=corpo();
    $w = isset($d['largura']) && $d['largura']!=='' ? max(280, min(4000, (int)$d['largura'])) : null;
    $h = isset($d['altura'])  && $d['altura']!==''  ? max(200, min(4000, (int)$d['altura']))  : null;
    foreach (['planta.largura'=>$w, 'planta.altura'=>$h] as $chave=>$val) {
        if ($val === null) { $conn->query("DELETE FROM {$P}definicoes WHERE chave='".$conn->real_escape_string($chave)."'"); continue; }
        $st=$conn->prepare("INSERT INTO {$P}definicoes (chave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $sv=(string)$val; $st->bind_param('ss',$chave,$sv); $st->execute();
    }
    ok(['canvas'=>plantaConfig($conn)]);
}
if ($acao === 'planta_bloqueio') {
    // Trava/destrava o arrasto das mesas e o redimensionar do canvas.
    $d = corpo();
    foreach (['bloq_mesas' => 'planta.bloq_mesas', 'bloq_canvas' => 'planta.bloq_canvas'] as $campo => $chave) {
        if (!array_key_exists($campo, $d)) continue;
        $val = !empty($d[$campo]) ? '1' : '0';
        $st = $conn->prepare("INSERT INTO {$P}definicoes (chave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('ss', $chave, $val); $st->execute();
    }
    ok(['canvas' => plantaConfig($conn)]);
}
if ($acao === 'mesa_save') {
    $d=corpo(); $id=(int)($d['id']??0); $nome=trim($d['nome']??'');
    $cap=($d['capacidade']??'')!==''?max(1,(int)$d['capacidade']):null;
    $forma=in_array($d['forma']??'',FORMAS_MESA,true)?$d['forma']:'redonda';
    $cor=in_array($d['cor']??'',CORES_MESA,true)?$d['cor']:null; // NULL = marfim
    $tam=in_array($d['tamanho']??'',['p','m','g'],true)?$d['tamanho']:null; // NULL = automático
    if ($nome==='') erro('Nome da mesa obrigatório.');
    if ($id){ $st=$conn->prepare("UPDATE {$P}mesas SET nome=?,capacidade=?,forma=?,cor=?,tamanho=? WHERE id=?"); $st->bind_param('sisssi',$nome,$cap,$forma,$cor,$tam,$id); }
    else    { $st=$conn->prepare("INSERT INTO {$P}mesas (nome,capacidade,forma,cor,tamanho) VALUES (?,?,?,?,?)"); $st->bind_param('sisss',$nome,$cap,$forma,$cor,$tam); }
    @$st->execute();
    if ($conn->errno===1062) erro('Já existe uma mesa com esse nome.');
    $novoId = $id ?: $conn->insert_id;
    ok(['mesas'=>listarMesas($conn),'id'=>$novoId]);
}
if ($acao === 'mesa_noivos') {
    // Repõe a mesa (especial) dos noivos, se tiver sido eliminada. Se já existir, devolve-a.
    $ja = $conn->query("SELECT id FROM {$P}mesas WHERE especial='noivos' LIMIT 1")->fetch_assoc();
    if ($ja) { ok(['mesas'=>listarMesas($conn),'id'=>(int)$ja['id'],'existia'=>true]); }
    $nome='Noivos'; $n=2;
    while ($conn->query("SELECT id FROM {$P}mesas WHERE nome='".$conn->real_escape_string($nome)."'")->num_rows) { $nome='Noivos '.$n++; }
    $st=$conn->prepare("INSERT INTO {$P}mesas (nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (?,2,'redonda','ouro','noivos',50,42)");
    $st->bind_param('s',$nome); $st->execute();
    $novoId=$conn->insert_id; // capturar antes de listarMesas() (que corre outras queries)
    ok(['mesas'=>listarMesas($conn),'id'=>$novoId]);
}
if ($acao === 'mesa_pos') {
    // Guarda a posição (e opcionalmente a forma) de uma mesa na planta.
    $d=corpo(); $id=(int)($d['id']??0);
    if (!$id) erro('Mesa inválida.');
    $x = isset($d['x']) && $d['x']!=='' ? max(0.0, min(100.0, (float)$d['x'])) : null;
    $y = isset($d['y']) && $d['y']!=='' ? max(0.0, min(100.0, (float)$d['y'])) : null;
    $forma = in_array($d['forma']??'',FORMAS_MESA,true) ? $d['forma'] : null;
    if ($forma !== null) {
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=?,forma=? WHERE id=?");
        $st->bind_param('ddsi',$x,$y,$forma,$id);
    } else {
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=? WHERE id=?");
        $st->bind_param('ddi',$x,$y,$id);
    }
    $st->execute();
    ok();
}
if ($acao === 'mesa_delete') {
    $id=(int)($_GET['id']??0);
    $nm = $conn->query("SELECT nome FROM {$P}mesas WHERE id=$id");
    $nomeMesa = ($nm && $x=$nm->fetch_assoc()) ? $x['nome'] : '';
    $conn->query("UPDATE {$P}convites SET mesa_id=NULL WHERE mesa_id=$id");
    $conn->query("UPDATE {$P}convidados SET mesa_id=NULL WHERE mesa_id=$id"); // mesas individuais também
    $st=$conn->prepare("DELETE FROM {$P}mesas WHERE id=?"); $st->bind_param('i',$id); $st->execute();
    registar($conn, 'mesa_eliminada', $nomeMesa, 'id '.$id);
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convite_mesa') {
    // Senta (mesa_id) ou retira (mesa_id vazio) um convite inteiro de uma mesa.
    // Define a mesa "padrão" do convite; os membros sem mesa própria seguem-na.
    $d=corpo(); $id=(int)($d['id']??0);
    if (!$id) erro('Convite inválido.');
    $mesaId = (isset($d['mesa_id']) && $d['mesa_id']!=='' && $d['mesa_id']!==null) ? (int)$d['mesa_id'] : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) erro('A mesa dos noivos só admite padrinhos e madrinhas (pelo papel).');
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=?,atualizado_em=$TS WHERE id=?"); $st->bind_param('ii',$mesaId,$id); }
    else        { $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=NULL,atualizado_em=$TS WHERE id=?"); $st->bind_param('i',$id); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_mesa') {
    // Atribui/retira a mesa individual de UMA pessoa (permite dividir um convite por mesas).
    // mesa_id vazio -> a pessoa volta a seguir a mesa do convite.
    $d=corpo(); $gid=(int)($d['id']??0);
    if (!$gid) erro('Pessoa inválida.');
    $mesaId = (isset($d['mesa_id']) && $d['mesa_id']!=='' && $d['mesa_id']!==null) ? (int)$d['mesa_id'] : null;
    if ($mesaId && mesaEhNoivos($conn,$mesaId)) erro('A mesa dos noivos só admite padrinhos e madrinhas (pelo papel).');
    // Sentar numa mesa normal tira a pessoa da mesa de honra: limpa o papel
    // (padrinho/madrinha). Só se limpa se a coluna existir — tolerante a esquema por migrar.
    $limpaPapel = colunaExiste($conn, "{$P}convidados", 'papel') ? ", papel=NULL" : "";
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=?$limpaPapel WHERE id=?"); $st->bind_param('ii',$mesaId,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=NULL WHERE id=?"); $st->bind_param('i',$gid); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_papel') {
    // Define o papel do convidado: 'padrinho' (ala esquerda), 'madrinha' (ala direita) ou '' (nenhum).
    // O papel deteta automaticamente as alas da mesa dos noivos.
    $d=corpo(); $gid=(int)($d['id']??0);
    if (!$gid) erro('Pessoa inválida.');
    $papel = in_array($d['papel']??'', ['padrinho','madrinha'], true) ? $d['papel'] : null;
    // Tornar-se padrinho/madrinha coloca a pessoa na mesa de honra: limpa a mesa individual.
    if ($papel){ $st=$conn->prepare("UPDATE {$P}convidados SET papel=?, mesa_id=NULL WHERE id=?"); $st->bind_param('si',$papel,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET papel=NULL WHERE id=?"); $st->bind_param('i',$gid); }
    $st->execute();
    ok(['mesas'=>listarMesas($conn)]);
}
if ($acao === 'convidado_list') {
    // Todas as pessoas nomeadas, com a mesa efetiva (individual, senão a do convite).
    // Colunas opcionais protegidas (tolerante a esquema por migrar).
    $selGen = colunaExiste($conn, "{$P}convidados", 'genero') ? "g.genero" : "'' AS genero";
    $selBri = colunaExiste($conn, "{$P}convidados", 'brinde') ? "g.brinde" : "0 AS brinde";
    $sql="SELECT g.id, g.nome, g.convite_id, g.mesa_id AS mesa_pessoa, g.rsvp, g.presente, g.papel, $selGen, $selBri,
                 c.nome_exibicao, c.sufixo, c.lugares, c.mesa_id AS mesa_convite, c.codigo,
                 mp.nome AS mesa_pessoa_nome, mc.nome AS mesa_convite_nome,
                 mp.especial AS mesa_pessoa_esp, mc.especial AS mesa_convite_esp
          FROM {$P}convidados g
          JOIN {$P}convites c ON g.convite_id=c.id
          LEFT JOIN {$P}mesas mp ON g.mesa_id=mp.id
          LEFT JOIN {$P}mesas mc ON c.mesa_id=mc.id
          WHERE ".soVivos($conn,'c')."
          ORDER BY c.nome_exibicao, g.principal DESC, g.nome";
    $rows=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    // Mesa dos noivos (para a deteção automática de padrinhos/madrinhas).
    $noivos = $conn->query("SELECT id, nome FROM {$P}mesas WHERE especial='noivos' LIMIT 1")->fetch_assoc();
    foreach ($rows as &$r) {
        $r['convite_nome']  = nomeConvite($r); // usa nome_exibicao/lugares/sufixo
        $ehPad = in_array($r['papel'] ?? '', ['padrinho','madrinha'], true);
        if ($ehPad && $noivos) {
            // Padrinho/madrinha: sentado sempre na mesa dos noivos, na respetiva ala.
            $r['mesa_efetiva_id']   = (int)$noivos['id'];
            $r['mesa_efetiva_nome'] = $noivos['nome'];
            $r['mesa_efetiva_esp']  = 'noivos';
        } else {
            $r['mesa_efetiva_id']   = $r['mesa_pessoa'] !== null ? (int)$r['mesa_pessoa'] : ($r['mesa_convite'] !== null ? (int)$r['mesa_convite'] : null);
            $r['mesa_efetiva_nome'] = $r['mesa_pessoa_nome'] ?: ($r['mesa_convite_nome'] ?: null);
            $r['mesa_efetiva_esp']  = $r['mesa_pessoa'] !== null ? $r['mesa_pessoa_esp'] : $r['mesa_convite_esp'];
        }
    }
    unset($r);
    ok(['convidados'=>$rows]);
}

// ---- Importar da lista antiga ------------------------------
if ($acao === 'importar') {
    if (!listaAntigaExiste($conn)) erro('Não foi encontrada a lista antiga (tabela "guests").');
    $jaTem=(int)$conn->query("SELECT COUNT(*) FROM {$P}convites")->fetch_row()[0];
    if ($jaTem>0 && empty($_GET['forcar'])) erro('Já existem convites. Para reimportar, confirme a substituição.');
    if (!empty($_GET['forcar'])) { $conn->query("DELETE FROM {$P}convites"); }

    $criadosC=0; $criadosG=0;

    // Mapa mesa antiga -> nova
    $mapaMesa=[];
    if ($conn->query("SHOW TABLES LIKE 'mesas'")->num_rows) {
        $res=$conn->query("SELECT id,name,capacity FROM mesas");
        while($m=$res->fetch_assoc()) $mapaMesa[(int)$m['id']]=resolverMesa($conn,$m['name']);
    }
    $estadoMap=['confirmado'=>'confirmado','recusado'=>'recusado','pendente'=>'pendente'];

    // Grupos (convites físicos) — cada invite_group vira um convite
    if ($conn->query("SHOW TABLES LIKE 'invite_groups'")->num_rows) {
        $grupos=$conn->query("SELECT id,name FROM invite_groups ORDER BY name");
        while($g=$grupos->fetch_assoc()){
            $st=$conn->prepare("SELECT name,confirmed,phone,notes,table_id FROM guests WHERE group_id=? ORDER BY name");
            $st->bind_param('i',$g['id']); $st->execute();
            $membros=$st->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!$membros) continue;
            $tel=null; $mesa=null; $notas=null; $conf=0; $rec=0;
            foreach($membros as $m){ if(!$tel&&$m['phone'])$tel=$m['phone']; if(!$mesa&&$m['table_id'])$mesa=$mapaMesa[(int)$m['table_id']]??null; if(!$notas&&$m['notes'])$notas=$m['notes'];
                                     if($m['confirmed']==='confirmado')$conf++; if($m['confirmed']==='recusado')$rec++; }
            $n=count($membros);
            $estado = $conf===$n?'confirmado':($rec===$n?'recusado':(($conf||$rec)?'parcial':'pendente'));
            $codigo=gerarCodigo($conn);
            $st=$conn->prepare("INSERT INTO {$P}convites (codigo,nome_exibicao,tipo,lado,lugares,mesa_id,telefone,rsvp_estado,rsvp_confirmados,observacoes,criado_em,atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
            $tipo='fisico'; $lado='noivo';
            $st->bind_param('ssssiissis',$codigo,$g['name'],$tipo,$lado,$n,$mesa,$tel,$estado,$conf,$notas);
            $st->execute(); $cid=$conn->insert_id; $criadosC++;
            $primeiro=true;
            foreach($membros as $m){ $r=$estadoMap[$m['confirmed']]??'pendente'; $pr=$primeiro?1:0; $primeiro=false;
                $q=$conn->prepare("INSERT INTO {$P}convidados (convite_id,nome,principal,rsvp) VALUES (?,?,?,?)");
                $q->bind_param('isis',$cid,$m['name'],$pr,$r); $q->execute(); $criadosG++; }
        }
    }

    // Convidados sem grupo -> convite digital individual
    $sem=$conn->query("SELECT name,side,confirmed,phone,notes,table_id FROM guests WHERE group_id IS NULL ORDER BY name");
    while($m=$sem->fetch_assoc()){
        $codigo=gerarCodigo($conn);
        $lado=in_array($m['side'],['noivo','noiva'],true)?$m['side']:'noivo';
        $estado=$estadoMap[$m['confirmed']]??'pendente';
        $conf=$estado==='confirmado'?1:0;
        $mesa=$m['table_id']?($mapaMesa[(int)$m['table_id']]??null):null;
        $st=$conn->prepare("INSERT INTO {$P}convites (codigo,nome_exibicao,tipo,lado,lugares,mesa_id,telefone,rsvp_estado,rsvp_confirmados,observacoes,criado_em,atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
        $tipo='digital'; $lug=1;
        $st->bind_param('ssssiissis',$codigo,$m['name'],$tipo,$lado,$lug,$mesa,$m['phone'],$estado,$conf,$m['notes']);
        $st->execute(); $cid=$conn->insert_id; $criadosC++;
        $q=$conn->prepare("INSERT INTO {$P}convidados (convite_id,nome,principal,rsvp) VALUES (?,?,1,?)");
        $q->bind_param('iss',$cid,$m['name'],$estado); $q->execute(); $criadosG++;
    }

    ok(['convites'=>$criadosC,'convidados'=>$criadosG]);
}

erro('Ação desconhecida.');
