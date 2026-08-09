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

/**
 * Exige poder escrever no casamento aberto.
 *
 * A única situação em que não se pode é a visita de suporte com um código de
 * "ver". Fica aqui, num sítio só, à frente de tudo o que altera dados: um
 * código de ver que deixasse mexer não era um código de ver.
 */
function exigirCorrecao(): void {
    if (!podeCorrigir()) {
        http_response_code(403);
        erro('Está a acompanhar este casamento com um código de leitura. '
           . 'Para corrigir, peça ao casal um código com permissão de correção.');
    }
}

/**
 * Quantos gestores ficariam neste casamento se tirássemos esta conta.
 * Serve para não deixar um casamento sem ninguém que lhe mexa — um erro
 * de um clique que só se desfaz por fora, na base de dados.
 */
function contaNoivos(mysqli $conn, int $cid, int $exceto): int {
    global $P;
    $st = $conn->prepare("SELECT COUNT(*) n FROM {$P}acessos
                          WHERE casamento_id=? AND papel='noivos' AND utilizador_id <> ?");
    if (!$st) return 0;
    $st->bind_param('ii', $cid, $exceto); $st->execute();
    return (int)$st->get_result()->fetch_assoc()['n'];
}

/** Senha temporária legível, para se entregar a quem se convida. */
function senhaTemporaria(): string {
    $a = 'abcdefghijkmnpqrstuvwxyz23456789';   // sem l, o, 0, 1
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

/**
 * As mesmas portas de exigirAdmin()/exigirPorta(), mas a responder como API.
 *
 * As originais reencaminham para o login, o que numa página é o certo e numa
 * chamada de API é uma armadilha: o pedido recebe a página de entrada em HTML
 * onde esperava JSON, e quem chamou fica sem perceber que lhe faltava o
 * acesso. Aqui responde-se 403 e diz-se porquê.
 */
function exigirAdminApi(): void {
    if (ehAdmin()) return;
    http_response_code(403);
    erro(utilizadorId()
        ? 'Não tem um casamento aberto com poderes de gestão.'
        : 'Sessão terminada. Entre de novo.');
}
function exigirPortaApi(): void {
    if (podeEntrar()) return;
    http_response_code(403);
    erro(utilizadorId()
        ? 'Não tem um casamento aberto.'
        : 'Sessão terminada. Entre de novo.');
}

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
    // O nome do ficheiro é o do casal aberto: com vários casamentos na mesma
    // casa, três exportações com o mesmo nome acabam por se sobrepor na pasta
    // das transferências de quem as fez.
    $alcunha = strtolower(casalInfo(defsAtuais($conn))['casal']);
    $alcunha = preg_replace('/[^a-z0-9]+/', '_',
                 iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $alcunha) ?: 'convidados');
    $alcunha = trim((string)$alcunha, '_') ?: 'convidados';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=convidados_' . $alcunha . '.csv');
    $out = fopen('php://output', 'w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Convite','Tipo','Lado','Lugares','Mesa','Estado RSVP','Confirmados','Presentes','Telefone','Membros','Codigo','Link']);
    $res = $conn->query("SELECT c.*, m.nome AS mesa_nome,
                                GROUP_CONCAT(g.nome ORDER BY g.principal DESC, g.nome SEPARATOR ', ') AS membros
                         FROM {$P}convites c
                         LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                         LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                         WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
                         GROUP BY c.id ORDER BY c.nome_exibicao");
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            nomeConvite($r), $r['tipo'], $r['lado'], $r['lugares'], $r['mesa_nome'],
            $r['rsvp_estado'], $r['rsvp_confirmados'], $r['checkin_presentes'],
            $r['telefone'], $r['membros'], $r['codigo'],
            enderecoPublico().'/convite.php?c='.$r['codigo']
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
        $conn->query("UPDATE {$P}convidados SET rsvp='recusado', presente=0, presente_em=NULL WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
        $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE " . doCasamento() . " AND id=".(int)$c['id']);
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
                $q = $conn->prepare("UPDATE {$P}convidados SET rsvp=? WHERE " . doCasamento() . " AND id=? AND convite_id=?");
                $q->bind_param('sii', $rs, $mid, $c['id']); $q->execute();
            }
        }
        if ($tot > 0) {
            // Estado derivado das escolhas por pessoa
            $confirm = $vai;
            $estado  = $vai <= 0 ? 'recusado' : ($vai >= $tot ? 'confirmado' : 'parcial');
            // Caso terminal: alinha os membros ao estado (os não confirmados ficaram 'pendente' acima).
            if ($estado === 'recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
            elseif ($estado === 'confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE " . doCasamento() . " AND convite_id=".(int)$c['id']);
        } else {
            // Sem lista nominal: usa o número indicado
            if ($confirm < 1) $confirm = 1;
            $estado = ($confirm >= (int)$c['lugares']) ? 'confirmado' : 'parcial';
        }
    }

    $st = $conn->prepare("UPDATE {$P}convites
                          SET rsvp_estado=?, rsvp_confirmados=?, rsvp_mensagem=?, rsvp_em=$TS
                          WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('sisi', $estado, $confirm, $mensagem, $c['id']); // string, int, string, int
    $st->execute();

    ok(['estado' => $estado, 'confirmados' => $confirm]);
}

// ============================================================
// REGISTO PÚBLICO — um casal inscreve-se, o admin é que abre a porta
// ============================================================
if ($acao === 'registo_publico') {
    $d = corpo();
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $senha = (string)($d['senha'] ?? '');
    $data  = trim((string)($d['data'] ?? ''));
    if ($noiva === '' || $noivo === '')          erro('Indique os nomes dos noivos.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    if (mb_strlen($senha) < 8)                    erro('A senha precisa de pelo menos 8 caracteres.');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro('Data inválida.');

    // Trava contra enchentes: um registo de cada vez por visitante, e um teto
    // global por hora. Sem isto, um guião automático enchia a fila de
    // aprovação de lixo e o admin deixava de ver os pedidos verdadeiros.
    if (!empty($_SESSION['registo_feito']) && (time() - (int)$_SESSION['registo_feito']) < 900) {
        erro('Já foi enviado um registo há pouco. Aguarde, por favor.');
    }
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}casamentos
                        WHERE estado='pendente' AND criado_em > (NOW() - INTERVAL 1 HOUR)");
    if ($r && (int)$r->fetch_assoc()['n'] >= 20) {
        erro('Há demasiados registos à espera neste momento. Tente mais tarde, por favor.');
    }

    // A conta primeiro: se o email já existir, não se cria casamento nenhum.
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $nomeConta = trim("$noiva & $noivo");
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                          VALUES (?,?,?, 'pendente')");
    $st->bind_param('sss', $email, $nomeConta, $hash);
    if (!$st->execute()) erro('Já existe uma conta com esse email. Tente entrar, ou use outro.');
    $uid = $conn->insert_id;

    $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado)
                          VALUES (?,?,?,?, 'pendente')");
    $dataOuNulo = $data !== '' ? $data : null;
    $st->bind_param('ssss', $nomeConta, $noiva, $noivo, $dataOuNulo);
    if (!$st->execute()) {
        // Sem casamento, a conta ficaria a pairar: desfaz-se.
        $conn->query("DELETE FROM {$P}utilizadores WHERE id=" . (int)$uid);
        erro('Não foi possível registar. Tente de novo, por favor.');
    }
    $cid = $conn->insert_id;

    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?, 'noivos')");
    $st->bind_param('ii', $uid, $cid); @$st->execute();

    $_SESSION['registo_feito'] = time();
    usarCasamento($cid);
    registar($conn, 'registo_publico', $nomeConta, $email);
    ok(['casamento' => $cid]);
}

// ============================================================
// A partir daqui: exige login
// ============================================================
// Autenticado, mas ainda sem casa: o suporte antes de lhe darem um código, ou
// um registo aprovado a que falte o casamento. Estas ações são as que se pode
// fazer nesse estado.
if (in_array($acao, ['suporte_entrar','senha_mudar'], true)) {
    if (!utilizadorId()) { http_response_code(401); erro('Sessão terminada. Entre de novo.'); }
    exigirCsrf();

    if ($acao === 'suporte_entrar') {
        // O código é do casal para o suporte. Não é uma segunda porta de
        // entrada: quem o usa já se autenticou, e tem de ser da casa.
        if (!ehPessoalPlataforma()) erro('Só o pessoal da plataforma usa códigos de suporte.');
        $cod = mb_strtoupper(trim((string)(corpo()['codigo'] ?? '')));
        if ($cod === '') erro('Indique o código.');
        $st = $conn->prepare("SELECT id, casamento_id, pode_corrigir, expira_em, revogado_em
                              FROM {$P}suporte_codigos WHERE codigo=? LIMIT 1");
        $st->bind_param('s', $cod); $st->execute();
        $s = $st->get_result()->fetch_assoc();
        // A mesma resposta para código errado, revogado ou expirado: quem
        // tentar adivinhar não fica a saber qual dos três acertou.
        $mau = 'Código inválido, revogado ou expirado.';
        if (!$s) erro($mau);
        if ($s['revogado_em'] !== null) erro($mau);
        if ($s['expira_em'] !== null && strtotime($s['expira_em']) < time()) erro($mau);

        $cid = (int)$s['casamento_id'];
        $q = $conn->prepare("SELECT nome, estado FROM {$P}casamentos WHERE id=?");
        $q->bind_param('i', $cid); $q->execute();
        $cas = $q->get_result()->fetch_assoc();
        if (!$cas || $cas['estado'] === 'arquivado') erro($mau);

        $acessos = suporteAcessos();
        $acessos[$cid] = ['corrigir' => (int)$s['pode_corrigir'], 'codigo' => (int)$s['id']];
        $_SESSION['suporte_acessos'] = $acessos;

        $uid = utilizadorId();
        $st = $conn->prepare("UPDATE {$P}suporte_codigos SET usado_por=?, usado_em=NOW() WHERE id=?");
        $st->bind_param('ii', $uid, $s['id']); @$st->execute();

        abrirCasamento($conn, $cid);
        registar($conn, 'suporte_entrou', $cas['nome'],
                 (int)$s['pode_corrigir'] ? 'pode corrigir' : 'só ver');
        ok(['casamento' => $cid, 'nome' => $cas['nome'], 'pode_corrigir' => (int)$s['pode_corrigir']]);
    }

    if ($acao === 'senha_mudar') {
        $d = corpo();
        $velha = (string)($d['atual'] ?? '');
        $nova  = (string)($d['nova'] ?? '');
        if (mb_strlen($nova) < 8) erro('A nova senha precisa de pelo menos 8 caracteres.');
        $uid = utilizadorId();
        $r = $conn->query("SELECT senha_hash FROM {$P}utilizadores WHERE id=$uid LIMIT 1");
        $u = $r ? $r->fetch_assoc() : null;
        if (!$u || !password_verify($velha, (string)$u['senha_hash'])) erro('A senha atual não confere.');
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $st = $conn->prepare("UPDATE {$P}utilizadores SET senha_hash=? WHERE id=?");
        $st->bind_param('si', $hash, $uid);
        if (!$st->execute()) erro('Não foi possível mudar a senha.');
        registar($conn, 'senha_mudada', utilizadorAtual() ?? '');
        ok();
    }
}

// ---- Porteiro (admin ou porteiro) --------------------------
if (in_array($acao, ['porta_buscar','porta_checkin','porta_stats','porta_entradas','porta_dados'], true)) {
    exigirPortaApi();
    if ($acao === 'porta_checkin') { exigirCsrf(); exigirCorrecao(); }  // altera dados

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
                           WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
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
                            WHERE " . doCasamento() . " ORDER BY principal DESC, nome");
        if ($rg) while ($g = $rg->fetch_assoc()) {
            $cid = (int)$g['convite_id'];
            if (isset($porId[$cid])) $porId[$cid]['membros'][] = $g;
        }
        ok(['convites' => $convites, 'gerado_em' => date('c')]);
    }

    if ($acao === 'porta_entradas') {
        $r = $conn->query("SELECT id FROM {$P}convites
                           WHERE " . doCasamento() . " AND checkin_estado IN ('presente','parcial') AND ".soVivos($conn,'')."
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

        // 1) tenta por código exato, DENTRO do casamento aberto — o porteiro de
        // um casamento não pode ler o convite de outro por lhe passar um QR
        // alheio pela câmara.
        $c = carregarConvite($conn, strtoupper($termo), 'codigo_local');
        if ($c) ok(['convite' => $c]);

        // 2) procura por nome do convite ou de um membro
        $like = "%$termo%";
        $st = $conn->prepare("SELECT DISTINCT c.id FROM {$P}convites c
                              LEFT JOIN {$P}convidados g ON g.convite_id=c.id
                              WHERE " . doCasamento('c') . " AND (c.nome_exibicao LIKE ? OR g.nome LIKE ?) AND ".soVivos($conn,'c')."
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
            $q = $conn->prepare("SELECT presente, rsvp, nome FROM {$P}convidados
                                 WHERE " . doCasamento() . " AND id=? AND convite_id=?");
            $q->bind_param('ii', $mid, $id); $q->execute();
            $cur = $q->get_result()->fetch_assoc();
            if (!$cur) erro('Pessoa não encontrada neste convite.');
            $jaPresente = (int)$cur['presente'] === 1;
            if (!$jaPresente && $cur['rsvp'] !== 'confirmado' && !$excecao) {
                erro(($cur['nome'] ?: 'Esta pessoa') . ' não confirmou presença e não pode dar entrada.');
            }
            $novo = $jaPresente ? 0 : 1;
            $q = $conn->prepare("UPDATE {$P}convidados SET presente=?, presente_em=".($novo?$TS:'NULL')." WHERE " . doCasamento() . " AND id=? AND convite_id=?");
            $q->bind_param('iii', $novo, $mid, $id); $q->execute();
            recalcularCheckin($conn, $id, $TS);
        } elseif ($modo === 'anular') {
            $conn->query("UPDATE {$P}convidados SET presente=0, presente_em=NULL WHERE " . doCasamento() . " AND convite_id=$id");
            $conn->query("UPDATE {$P}convites SET checkin_estado='aguardando', checkin_presentes=0, checkin_em=NULL WHERE " . doCasamento() . " AND id=$id");
        } else { // 'todos'
            if (count($c['membros']) > 0) {
                if ($excecao) {
                    // Entrada excecional autorizada: admite todas as pessoas do convite
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE " . doCasamento() . " AND convite_id=$id");
                } else {
                    $r = $conn->query("SELECT COUNT(*) n FROM {$P}convidados
                                       WHERE " . doCasamento() . " AND convite_id=$id AND rsvp='confirmado'");
                    $nconf = (int)$r->fetch_assoc()['n'];
                    if ($nconf === 0) erro('Ninguém neste convite confirmou presença. Não é possível dar entrada.');
                    $conn->query("UPDATE {$P}convidados SET presente=1, presente_em=$TS WHERE " . doCasamento() . " AND convite_id=$id AND rsvp='confirmado'");
                }
                recalcularCheckin($conn, $id, $TS);
            } else {
                if (!$excecao && !in_array($c['rsvp_estado'], ['confirmado','parcial'], true)) {
                    erro('Este convite não confirmou presença. Não é possível dar entrada.');
                }
                $n = (int)($c['rsvp_confirmados'] ?: $c['lugares']);
                $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado='presente', checkin_presentes=?, checkin_em=$TS WHERE " . doCasamento() . " AND id=?");
                $st->bind_param('ii', $n, $id); $st->execute();
            }
        }
        $qual = ['membro'=>'entrada de 1 pessoa', 'anular'=>'entrada anulada'][$modo] ?? 'entrada do convite';
        registar($conn, 'checkin', $c['nome_final'] ?? '', $qual . ($excecao ? ' (excecional)' : ''));
        ok(['convite' => carregarConvite($conn, $id)]);
    }
}

// ---- Admin --------------------------------------------------
exigirAdminApi();

// Endpoints de admin que alteram dados: exigem token CSRF válido.
// A lista vive em config.php (acoesDeEscrita), para o ecrã poder desligar
// exatamente os mesmos controlos que o servidor recusaria.
if (in_array($acao, acoesDeEscrita(), true)) {
    exigirCsrf();
    // E, se estiver a ver a casa com um código de leitura, fica-se por ver.
    // As ações da própria plataforma (criar casamentos, mexer em contas) não
    // são dados de casamento nenhum e não passam por aqui.
    if (in_array($acao, acoesDoCasamento(), true)) exigirCorrecao();
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

    $st = $conn->prepare("SELECT COUNT(*) FROM {$P}versoes WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    if ((int)$st->get_result()->fetch_row()[0] >= VERSOES_MAX) {
        erro('Chegou ao máximo de '.VERSOES_MAX.' versões desta peça. Apague uma para guardar outra.');
    }
    $json = jsonOuNulo(instantaneoAmbito($conn, $ambito));
    if ($json === null) erro('Não foi possível preparar a versão.');

    $u = utilizadorAtual() ?? '';
    $st = $conn->prepare("INSERT INTO {$P}versoes (casamento_id, nome, defs, utilizador, ambito) VALUES (" . casamentoAtual() . ",?,?,?,?)");
    $st->bind_param('ssss', $nome, $json, $u, $ambito);
    if (!$st->execute()) erro('Não foi possível guardar a versão.');
    $id = $conn->insert_id;
    // Uma versão acabada de guardar É a peça neste momento — foi tirada dela.
    // Antes só a primeira ficava marcada, e a marca ficava presa numa versão
    // antiga enquanto o convite já mostrava outra coisa.
    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $ambito); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE " . doCasamento() . " AND id=$id");
    registar($conn, 'versao_guardada', $nome, ambitosVersao()[$ambito]['rotulo']);
    ok(['id' => $id]);
}

if ($acao === 'versao_lista') {
    $ambito = ambitoPedido();
    $st = $conn->prepare("SELECT id, nome, utilizador, criado_em, atualizado_em, predefinida, defs
                          FROM {$P}versoes WHERE " . doCasamento() . " AND ambito=? ORDER BY predefinida DESC, id DESC");
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
        $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
        $st->bind_param('s', $ambito); $st->execute();
        registar($conn, 'versao_aplicada', VERSAO_PADRAO_NOME, $r['repostas'].' definição(ões)');
        ok($r + ['nome' => VERSAO_PADRAO_NOME, 'ambito' => $ambito]);
    }

    $st = $conn->prepare("SELECT nome, defs, ambito FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
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

    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $v['ambito']); $st->execute();
    $conn->query("UPDATE {$P}versoes SET predefinida=1 WHERE " . doCasamento() . " AND id=$id");

    registar($conn, 'versao_aplicada', $v['nome'], $r['gravadas'].' definição(ões)');
    ok($r + ['nome' => $v['nome'], 'ambito' => $v['ambito']]);
}

if ($acao === 'versao_atualizar') {
    // Reescreve o conteúdo da versão com o que está em vigor agora.
    $id = (int)($_GET['id'] ?? 0);
    // A versão padrão é a peça de origem: não se reescreve.
    if ($id === VERSAO_PADRAO_ID) erro('A versão «'.VERSAO_PADRAO_NOME.'» é a peça de origem: não se reescreve. Guarde as suas alterações como uma versão nova.');
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i', $id); $st->execute();
    $v = $st->get_result()->fetch_assoc();
    if (!$v) erro('Versão não encontrada.');
    $json = jsonOuNulo(instantaneoAmbito($conn, $v['ambito']));
    if ($json === null) erro('Não foi possível preparar a versão.');
    $st = $conn->prepare("UPDATE {$P}versoes SET defs=?, atualizado_em=NOW() WHERE " . doCasamento() . " AND id=?");
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
    $st = $conn->prepare("UPDATE {$P}versoes SET nome=? WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('si', $nome, $id);
    if (!$st->execute()) erro('Não foi possível mudar o nome.');
    registar($conn, 'versao_renomeada', $nome, 'id '.$id);
    ok(['nome' => $nome]);
}

if ($acao === 'versao_apagar') {
    $id = (int)($_GET['id'] ?? 0);
    // A versão padrão é a peça de origem: não se apaga.
    if ($id === VERSAO_PADRAO_ID) erro('A versão «'.VERSAO_PADRAO_NOME.'» é a peça de origem: não se apaga. Guarde as suas alterações como uma versão nova.');
    $rn = $conn->prepare("SELECT nome, ambito, predefinida FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
    $rn->bind_param('i', $id); $rn->execute();
    $x = $rn->get_result()->fetch_assoc();
    if (!$x) erro('Versão não encontrada.');
    $st = $conn->prepare("DELETE FROM {$P}versoes WHERE " . doCasamento() . " AND id=?");
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
    if ($temPapel) { $nr=$conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1"); if ($nr && $row=$nr->fetch_row()) $noivosId=(int)$row[0]; }
    // Mesa EFETIVA de um membro (alias): a dos noivos se for padrinho/madrinha, senão a própria/do convite.
    $effMesa = function(string $a) use ($temPapel,$noivosId) {
        return $temPapel
            ? "CASE WHEN {$a}.papel IN ('padrinho','madrinha') THEN ".($noivosId?:'NULL')." ELSE COALESCE({$a}.mesa_id, c.mesa_id) END"
            : "COALESCE({$a}.mesa_id, c.mesa_id)";
    };
    $temMesaExpr = fn(string $a) => $temPapel ? "({$a}.mesa_id IS NOT NULL OR {$a}.papel IN ('padrinho','madrinha'))" : "{$a}.mesa_id IS NOT NULL";
    // O âmbito abre o WHERE: tudo o que se filtre a seguir já é deste casamento.
    $w="WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c'); $t=''; $p=[];   // fora os que estão na reciclagem
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
        $st=$conn->prepare("UPDATE {$P}convites SET nome_exibicao=?,mostrar_num_mesa=?,tipo=?,lado=?,lugares=?,mesa_id=?,telefone=?,observacoes=?,msg_pessoal=?,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('sissiisssi',$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP,$id);
        $st->execute();
    } else {
        $codigo=gerarCodigo($conn);
        $st=$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,mostrar_num_mesa,tipo,lado,lugares,mesa_id,telefone,observacoes,msg_pessoal,criado_em,atualizado_em) VALUES (" . casamentoAtual() . ",?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
        $st->bind_param('ssissiisss',$codigo,$nome,$mostrarNM,$tipo,$lado,$lugares,$mesaId,$telefone,$obs,$msgP);
        $st->execute(); $id=$conn->insert_id;
    }

    // Preserva estados (rsvp/presença/mesa individual) por nome, antes de reconstruir a lista de membros.
    // SELECT * para tolerar colunas que possam ainda não existir na BD (esquema por migrar).
    $anterior=[];
    $r=$conn->query("SELECT * FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=$id");
    if ($r) while($x=$r->fetch_assoc()) $anterior[strtolower(trim($x['nome']))]=$x;

    // Colunas opcionais: só entram no INSERT se existirem (evita 500 por "Unknown column").
    $temPapel  = colunaExiste($conn, "{$P}convidados", 'papel');
    $temGenero = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBrinde = colunaExiste($conn, "{$P}convidados", 'brinde');

    $presenca = in_array($d['presenca'] ?? '', ['pendente','confirmado','parcial','recusado'], true) ? $d['presenca'] : '';

    $conn->query("DELETE FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=$id");
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
        $cols=['casamento_id','convite_id','nome','principal','rsvp','presente','presente_em','mesa_id'];
        $plc =[(string)casamentoAtual(),'?','?','?','?','?', ($pres?$TS:'NULL'), '?'];
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
            $conn->query("UPDATE {$P}convites SET rsvp_estado='confirmado', rsvp_confirmados=$lugares, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
        } elseif ($presenca === 'recusado') {
            $conn->query("UPDATE {$P}convites SET rsvp_estado='recusado', rsvp_confirmados=0, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
        } elseif ($presenca === 'parcial') {
            if ($totMembros > 0) {
                // Presença exata: contagem e estado derivados das marcações individuais
                $estado = $vaiCount<=0 ? 'recusado' : ($vaiCount>=$totMembros ? 'confirmado' : 'parcial');
                $conn->query("UPDATE {$P}convites SET rsvp_estado='$estado', rsvp_confirmados=$vaiCount, rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
                // Se afinal o convite é totalmente confirmado/recusado, alinha os membros a esse estado
                // (os não confirmados ficaram 'pendente' acima; aqui reconcilia-se o caso terminal).
                if ($estado==='recusado')        $conn->query("UPDATE {$P}convidados SET rsvp='recusado' WHERE " . doCasamento() . " AND convite_id=$id");
                elseif ($estado==='confirmado')  $conn->query("UPDATE {$P}convidados SET rsvp='confirmado' WHERE " . doCasamento() . " AND convite_id=$id");
            } else {
                $conn->query("UPDATE {$P}convites SET rsvp_estado='parcial', rsvp_confirmados=COALESCE(rsvp_confirmados,1), rsvp_em=$TS WHERE " . doCasamento() . " AND id=$id");
            }
        } else { // pendente
            $conn->query("UPDATE {$P}convites SET rsvp_estado='pendente', rsvp_confirmados=NULL, rsvp_em=NULL WHERE " . doCasamento() . " AND id=$id");
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
    $rn = $conn->prepare("SELECT nome_exibicao FROM {$P}convites WHERE " . doCasamento() . " AND id=?");
    $rn->bind_param('i',$id); $rn->execute();
    if ($x = $rn->get_result()->fetch_assoc()) $nome = $x['nome_exibicao'];

    if ($def) {
        $st = $conn->prepare("DELETE FROM {$P}convites WHERE " . doCasamento() . " AND id=?");
    } else {
        $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=$TS WHERE " . doCasamento() . " AND id=?");
    }
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível eliminar.');
    registar($conn, $def ? 'convite_apagado' : 'convite_eliminado', $nome, 'id '.$id);
    ok(['stats'=>estatisticas($conn), 'id'=>$id, 'nome'=>$nome, 'reversivel'=>!$def]);
}

if ($acao === 'convite_restaurar') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("UPDATE {$P}convites SET eliminado_em=NULL WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('i',$id); $ok = $st->execute();
    if (!$ok) erro('Não foi possível repor o convite.');
    registar($conn, 'convite_reposto', '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

// ---- Casamentos ---------------------------------------------
// O mínimo para haver mais do que um. Os ecrãs de gestão (lista, aprovação de
// registos, convites de acesso) são da etapa seguinte; aqui fica o que a
// aplicação precisa para saber em qual está e para as provas poderem existir.

if ($acao === 'casamento_criar') {
    // Criar casamentos é do admin da casa. O suporte entra nos que o casal lhe
    // abrir por código, e não abre casas novas.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma cria casamentos.');
    $d = corpo();
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 160);
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    if ($nome === '') $nome = trim($noiva . ' & ' . $noivo);
    if (trim($nome, ' &') === '') erro('Dê um nome ao casamento.');
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data'] ?? '')) ? $d['data'] : null;
    // Nasce ativo quando é o admin a criá-lo. O registo público entra como
    // 'pendente' e é o admin que o faz passar a ativo (etapa 5).
    $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado)
                          VALUES (?,?,?,?, 'ativo')");
    $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
    if (!$st->execute()) erro('Não foi possível criar o casamento.');
    $novo = $conn->insert_id;
    registar($conn, 'casamento_criado', $nome, 'id ' . $novo);
    ok(['id' => $novo, 'nome' => $nome]);
}

if ($acao === 'casamento_abrir') {
    // Passa a ser este o casamento em causa, para os pedidos seguintes.
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT id, nome, estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    if ($c['estado'] === 'arquivado') erro('Esse casamento está arquivado.');
    // Ter o número não chega: é preciso ter lugar lá dentro. Sem isto, bastava
    // escrever outro id no endereço para entrar no casamento de outro casal.
    if (!abrirCasamento($conn, (int)$c['id'])) erro('Não tem acesso a esse casamento.');
    registar($conn, 'casamento_aberto', $c['nome'], 'id ' . (int)$c['id']);
    ok(['id' => (int)$c['id'], 'nome' => $c['nome']]);
}

if ($acao === 'utilizador_apagar') {
    // Só contas ÓRFÃS: as que já não pertencem a casamento nenhum. Uma conta
    // ligada a um casamento apaga-se tirando-lhe primeiro o lugar lá — assim
    // não há como, num clique, deixar um casal sem quem lhe gere a festa.
    // Desativar uma conta em funcionamento é outra coisa, e faz-se à parte.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga contas.');
    $id = (int)($_GET['id'] ?? 0);
    if ($id === utilizadorId()) erro('Não pode apagar a sua própria conta.');
    $st = $conn->prepare("SELECT u.id, u.email, u.papel_plataforma,
                                 (SELECT COUNT(*) FROM {$P}acessos a WHERE a.utilizador_id = u.id) n
                          FROM {$P}utilizadores u WHERE u.id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    if ((int)$u['n'] > 0) erro('Esta conta ainda tem lugar num casamento. Retire-lho primeiro.');
    // O último admin da plataforma não se apaga: ficaria uma casa sem chaves,
    // e a única saída era ir à base de dados por fora.
    if ($u['papel_plataforma'] === 'admin') {
        $r = $conn->query("SELECT COUNT(*) n FROM {$P}utilizadores
                           WHERE papel_plataforma='admin' AND estado='ativo' AND id <> " . (int)$id);
        if ($r && (int)$r->fetch_assoc()['n'] === 0) erro('É o último admin da plataforma.');
    }
    $st = $conn->prepare("DELETE FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar a conta.');
    registar($conn, 'utilizador_apagado', (string)$u['email']);
    ok(['id' => $id]);
}

if ($acao === 'casamento_identidade') {
    // A ficha do casamento: os nomes e a data que definem tudo o resto. Quem
    // gere o casamento aberto pode mudá-la.
    if (!ehAdmin()) erro('Não gere este casamento.');
    $d = corpo();
    $noiva = mb_substr(trim((string)($d['noiva'] ?? '')), 0, 80);
    $noivo = mb_substr(trim((string)($d['noivo'] ?? '')), 0, 80);
    $data  = trim((string)($d['data_evento'] ?? ''));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 160);
    if ($noiva === '' || $noivo === '') erro('Indique os nomes dos noivos.');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro('Data inválida.');
    if ($nome === '') $nome = "$noiva & $noivo";

    $cid = casamentoAtual();
    $st = $conn->prepare("UPDATE {$P}casamentos SET nome=?, noiva=?, noivo=?, data_evento=? WHERE id=?");
    $dataOuNulo = $data !== '' ? $data : null;
    $st->bind_param('ssssi', $nome, $noiva, $noivo, $dataOuNulo, $cid);
    if (!$st->execute()) erro('Não foi possível guardar.');

    // A ficha é o VALOR DE ORIGEM das peças (ver identidadeCasamento). Se o
    // convite tinha estes campos escritos por cima — porque alguém os mudou no
    // editor —, essa cópia continuaria a ganhar, e mudar o nome aqui não mudava
    // nada lá. Tira-se a cópia: quem quiser um nome diferente NO CONVITE volta
    // a escrevê-lo no editor, de propósito.
    $chaves = ["'casal.noiva'", "'casal.noivo'"];
    if ($data !== '') $chaves[] = "'evento.data'";
    $conn->query("DELETE FROM {$P}definicoes WHERE " . doCasamento()
                 . " AND chave IN (" . implode(',', $chaves) . ")");

    registar($conn, 'casamento_ficha', $nome, $data !== '' ? $data : 'sem data');
    ok(['nome' => $nome, 'noiva' => $noiva, 'noivo' => $noivo, 'data_evento' => $data]);
}

if ($acao === 'casamento_endereco') {
    // O endereço por onde os convidados chegam a ESTE casamento. Quem gere o
    // casamento aberto pode fixá-lo — é ele que sai nos QR e nos links.
    $d = corpo();
    $novo = limparEndereco((string)($d['endereco'] ?? ''));
    if ($novo === null) erro('Endereço inválido. Escreva algo como https://casamento.exemplo.pt');
    $st = $conn->prepare("UPDATE {$P}casamentos SET endereco_publico=? WHERE id=?");
    $id = casamentoAtual();
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível guardar o endereço.');
    registar($conn, 'endereco_publico', $novo !== '' ? $novo : '(deduzido do pedido)');
    ok(['endereco' => $novo]);
}

if ($acao === 'utilizador_criar') {
    // Contas criadas pela casa. O registo público (que entra 'pendente' e
    // espera aprovação) é da etapa seguinte; usa a mesma tabela.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma cria contas.');
    $d = corpo();
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $senha = (string)($d['senha'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    if (mb_strlen($senha) < 8) erro('A senha precisa de pelo menos 8 caracteres.');
    $plat = in_array($d['papel_plataforma'] ?? '', ['admin','suporte'], true) ? $d['papel_plataforma'] : null;
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, papel_plataforma, estado)
                          VALUES (?,?,?,?, 'ativo')");
    $st->bind_param('ssss', $email, $nome, $hash, $plat);
    if (!$st->execute()) erro('Já existe uma conta com esse email.');
    $uid = $conn->insert_id;

    // Liga-se logo a um casamento, se vier indicado — é o caso comum: criar a
    // conta dos noivos de um casamento acabado de abrir.
    $cid = (int)($d['casamento_id'] ?? 0);
    $papelCas = in_array($d['papel'] ?? '', ['noivos','porteiro'], true) ? $d['papel'] : 'noivos';
    if ($cid > 0) {
        $st = $conn->prepare("INSERT IGNORE INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)");
        $st->bind_param('iis', $uid, $cid, $papelCas);
        @$st->execute();
    }
    registar($conn, 'conta_criada', $email, $plat ? ('plataforma: '.$plat) : ('casamento '.$cid));
    ok(['id' => $uid, 'email' => $email]);
}

// ---- Quem entra neste casamento -----------------------------
// A gestão dos lugares é de quem gere o casamento aberto: são os noivos que
// convidam o seu porteiro, e não a plataforma que lho impõe. Numa visita de
// suporte com código de leitura, isto não se mexe (exigirCorrecao acima).

/** Quem manda nos lugares deste casamento? */
function mandaNosAcessos(int $cid): bool {
    return ehAdminPlataforma() || ($cid === casamentoAtual() && ehAdmin());
}

if ($acao === 'acesso_lista') {
    exigirAdminApi();
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT a.utilizador_id, a.papel, u.email, u.nome, u.estado, u.ultimo_acesso
                          FROM {$P}acessos a JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                          WHERE a.casamento_id = ? ORDER BY a.papel, u.nome, u.email");
    $st->bind_param('i', $cid); $st->execute();
    ok(['acessos' => $st->get_result()->fetch_all(MYSQLI_ASSOC), 'eu' => utilizadorId()]);
}

if ($acao === 'acesso_convidar') {
    // Dá lugar a alguém no casamento aberto, pelo email. Se a conta ainda não
    // existir, cria-se — é como se convida um porteiro que nunca cá esteve.
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $d = corpo();
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $papelCas = in_array($d['papel'] ?? '', ['noivos','porteiro'], true) ? $d['papel'] : 'porteiro';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');

    $st = $conn->prepare("SELECT id, nome FROM {$P}utilizadores WHERE email=? LIMIT 1");
    $st->bind_param('s', $email); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $senhaNova = '';
    if ($u) {
        $uid = (int)$u['id'];
    } else {
        // Conta nova: senha temporária, mostrada uma vez a quem convida, para
        // lha entregar. Não há correio configurado — e inventar um envio que
        // não acontece seria pior do que dizer as coisas como são.
        $senhaNova = senhaTemporaria();
        $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
        $hash = password_hash($senhaNova, PASSWORD_DEFAULT);
        $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, estado)
                              VALUES (?,?,?, 'ativo')");
        $st->bind_param('sss', $email, $nome, $hash);
        if (!$st->execute()) erro('Não foi possível criar a conta.');
        $uid = $conn->insert_id;
    }
    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE papel=VALUES(papel)");
    $st->bind_param('iis', $uid, $cid, $papelCas);
    if (!$st->execute()) erro('Não foi possível dar o acesso.');
    registar($conn, 'acesso_dado', $email, $papelCas);
    ok(['utilizador' => $uid, 'email' => $email, 'papel' => $papelCas, 'senha' => $senhaNova]);
}

if ($acao === 'acesso_papel') {
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $papelCas = in_array($_GET['papel'] ?? '', ['noivos','porteiro'], true) ? $_GET['papel'] : '';
    if ($uid <= 0 || $papelCas === '') erro('Indique a conta e o papel.');
    // Ninguém se despromove a si próprio: o casamento ficaria sem quem o gere.
    if ($uid === utilizadorId() && $papelCas !== 'noivos') erro('Não pode tirar-se a si próprio a gestão.');
    if ($papelCas === 'porteiro' && contaNoivos($conn, $cid, $uid) === 0) {
        erro('Este casamento ficaria sem ninguém a geri-lo.');
    }
    $st = $conn->prepare("UPDATE {$P}acessos SET papel=? WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('sii', $papelCas, $uid, $cid);
    if (!$st->execute()) erro('Não foi possível mudar o papel.');
    registar($conn, 'acesso_papel', 'conta '.$uid, $papelCas);
    ok(['utilizador' => $uid, 'papel' => $papelCas]);
}

if ($acao === 'acesso_tirar') {
    $cid = casamentoAtual();
    if (!mandaNosAcessos($cid)) erro('Não gere este casamento.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    if ($uid <= 0) erro('Indique a conta.');
    if ($uid === utilizadorId()) erro('Não pode tirar-se a si próprio deste casamento.');
    if (contaNoivos($conn, $cid, $uid) === 0) erro('Este casamento ficaria sem ninguém a geri-lo.');
    $st = $conn->prepare("DELETE FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('ii', $uid, $cid);
    if (!$st->execute()) erro('Não foi possível tirar o acesso.');
    registar($conn, 'acesso_tirado', 'conta '.$uid, 'casamento '.$cid);
    ok(['utilizador' => $uid]);
}

if ($acao === 'acesso_dar') {
    // Dá (ou muda) o lugar de alguém num casamento, indicando qual. É a versão
    // da plataforma; o casal usa 'acesso_convidar', no casamento que tem aberto.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma dá acessos.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $cid = (int)($_GET['casamento'] ?? 0);
    $papelCas = in_array($_GET['papel'] ?? '', ['noivos','porteiro'], true) ? $_GET['papel'] : 'noivos';
    if ($uid <= 0 || $cid <= 0) erro('Indique a conta e o casamento.');
    $st = $conn->prepare("INSERT INTO {$P}acessos (utilizador_id, casamento_id, papel) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE papel=VALUES(papel)");
    $st->bind_param('iis', $uid, $cid, $papelCas);
    if (!$st->execute()) erro('Não foi possível dar o acesso.');
    registar($conn, 'acesso_dado', 'conta '.$uid, 'casamento '.$cid.' · '.$papelCas);
    ok(['utilizador' => $uid, 'casamento' => $cid, 'papel' => $papelCas]);
}

// ---- Códigos de suporte -------------------------------------
// A porta que o casal abre ao suporte, e fecha quando quiser. Um código diz
// a que casamento dá acesso, se deixa só ver ou também corrigir, e até quando.

if ($acao === 'suporte_codigo_lista') {
    exigirAdminApi();
    $cid = casamentoAtual();
    $st = $conn->prepare("SELECT s.id, s.codigo, s.pode_corrigir, s.criado_em, s.expira_em,
                                 s.usado_em, s.revogado_em, u.email AS usado_por_email
                          FROM {$P}suporte_codigos s
                          LEFT JOIN {$P}utilizadores u ON u.id = s.usado_por
                          WHERE s.casamento_id=? ORDER BY s.id DESC LIMIT 40");
    $st->bind_param('i', $cid); $st->execute();
    $lista = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $agora = time();
    foreach ($lista as &$l) {
        $l['estado'] = $l['revogado_em'] !== null ? 'revogado'
                     : (($l['expira_em'] !== null && strtotime($l['expira_em']) < $agora) ? 'expirado' : 'valido');
    }
    unset($l);
    ok(['codigos' => $lista]);
}

if ($acao === 'suporte_codigo_criar') {
    exigirAdminApi();
    if (emVisitaDeSuporte()) erro('Uma visita de suporte não gera códigos de acesso.');
    $d = corpo();
    $corrigir = !empty($d['pode_corrigir']) ? 1 : 0;
    // Prazo curto por omissão: um código sem fim é uma porta que fica aberta.
    $dias = (int)($d['dias'] ?? 7);
    $dias = max(1, min($dias, 90));
    $cid  = casamentoAtual();
    $uid  = utilizadorId();

    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $cod = '';
        for ($i = 0; $i < 8; $i++) $cod .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $q = $conn->prepare("SELECT id FROM {$P}suporte_codigos WHERE codigo=? LIMIT 1");
        $q->bind_param('s', $cod); $q->execute();
        $existe = $q->get_result()->fetch_assoc();
    } while ($existe);

    $st = $conn->prepare("INSERT INTO {$P}suporte_codigos
                            (casamento_id, codigo, pode_corrigir, criado_por, expira_em)
                          VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    $st->bind_param('isiii', $cid, $cod, $corrigir, $uid, $dias);
    if (!$st->execute()) erro('Não foi possível gerar o código.');
    registar($conn, 'suporte_codigo', $cod, ($corrigir ? 'pode corrigir' : 'só ver') . " · $dias dia(s)");
    ok(['codigo' => $cod, 'pode_corrigir' => $corrigir, 'dias' => $dias]);
}

if ($acao === 'suporte_codigo_revogar') {
    exigirAdminApi();
    $id  = (int)($_GET['id'] ?? 0);
    $cid = casamentoAtual();
    $st = $conn->prepare("UPDATE {$P}suporte_codigos SET revogado_em=NOW()
                          WHERE id=? AND casamento_id=? AND revogado_em IS NULL");
    $st->bind_param('ii', $id, $cid);
    if (!$st->execute() || $conn->affected_rows === 0) erro('Esse código já não está de pé.');
    registar($conn, 'suporte_codigo_revogado', 'código ' . $id);
    ok(['id' => $id]);
}

if ($acao === 'suporte_sair') {
    // Fecha a visita ao casamento aberto, sem esperar que o código expire.
    $cid = casamentoAtual();
    $acessos = suporteAcessos();
    unset($acessos[$cid]);
    $_SESSION['suporte_acessos'] = $acessos;
    $_SESSION['casamento_id'] = 0;
    $_SESSION['papel'] = null;
    ok();
}

// ---- Contas, vistas pela plataforma -------------------------
if ($acao === 'utilizador_lista') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê as contas.');
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = "SELECT u.id, u.email, u.nome, u.papel_plataforma, u.estado, u.criado_em, u.ultimo_acesso,
                   (SELECT COUNT(*) FROM {$P}acessos a WHERE a.utilizador_id = u.id) casamentos
            FROM {$P}utilizadores u";
    if ($q !== '') {
        $sql .= " WHERE u.email LIKE ? OR u.nome LIKE ?";
        $sql .= " ORDER BY u.estado='pendente' DESC, u.id DESC LIMIT 100";
        $st = $conn->prepare($sql);
        $like = "%$q%"; $st->bind_param('ss', $like, $like);
    } else {
        $sql .= " ORDER BY u.estado='pendente' DESC, u.id DESC LIMIT 100";
        $st = $conn->prepare($sql);
    }
    $st->execute();
    ok(['contas' => $st->get_result()->fetch_all(MYSQLI_ASSOC), 'eu' => utilizadorId()]);
}

if ($acao === 'utilizador_estado') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda o estado das contas.');
    $id = (int)($_GET['id'] ?? 0);
    $novo = (string)($_GET['estado'] ?? '');
    if (!in_array($novo, ['pendente','ativo','suspenso'], true)) erro('Estado inválido.');
    if ($id === utilizadorId()) erro('Não pode mudar o estado da sua própria conta.');
    $st = $conn->prepare("SELECT email FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    $st = $conn->prepare("UPDATE {$P}utilizadores SET estado=? WHERE id=?");
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível mudar o estado.');
    registar($conn, 'conta_estado', (string)$u['email'], $novo);
    ok(['id' => $id, 'estado' => $novo]);
}

if ($acao === 'utilizador_repor_senha') {
    // Não há correio configurado, e um envio que não acontece era pior do que
    // não o prometer: gera-se uma senha temporária, mostra-se UMA vez a quem
    // a há de entregar, e quem a receber muda-a na sua conta.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma repõe senhas.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT email FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');
    $nova = senhaTemporaria();
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    $st = $conn->prepare("UPDATE {$P}utilizadores SET senha_hash=? WHERE id=?");
    $st->bind_param('si', $hash, $id);
    if (!$st->execute()) erro('Não foi possível repor a senha.');
    registar($conn, 'senha_reposta', (string)$u['email']);
    ok(['id' => $id, 'email' => $u['email'], 'senha' => $nova]);
}

if ($acao === 'casamento_estado') {
    // Aprovar um registo (pendente → ativo), suspender ou arquivar.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma muda o estado de um casamento.');
    $id = (int)($_GET['id'] ?? 0);
    $novo = (string)($_GET['estado'] ?? '');
    if (!in_array($novo, ['pendente','ativo','suspenso','arquivado'], true)) erro('Estado inválido.');
    $st = $conn->prepare("SELECT nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    $st = $conn->prepare("UPDATE {$P}casamentos SET estado=? WHERE id=?");
    $st->bind_param('si', $novo, $id);
    if (!$st->execute()) erro('Não foi possível mudar o estado.');

    // Aprovar um registo é abrir a porta às duas coisas ao mesmo tempo: o
    // casamento passa a ativo E a conta de quem se inscreveu deixa de estar à
    // espera. Aprovar só o casamento deixava o casal de fora, sem perceber
    // porquê — e o admin convencido de que já tinha tratado do assunto.
    $contas = 0;
    if ($novo === 'ativo') {
        $st = $conn->prepare("UPDATE {$P}utilizadores u
                              JOIN {$P}acessos a ON a.utilizador_id = u.id
                              SET u.estado='ativo'
                              WHERE a.casamento_id = ? AND u.estado='pendente'");
        $st->bind_param('i', $id);
        if ($st->execute()) $contas = $conn->affected_rows;
    }
    registar($conn, 'casamento_estado', $c['nome'], $novo . ($contas ? " · $contas conta(s) ativada(s)" : ''));
    ok(['id' => $id, 'estado' => $novo, 'contas_ativadas' => $contas]);
}

if ($acao === 'casamento_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga casamentos.');
    // Apaga um casamento e tudo o que é dele. O nº 1 não se apaga: é o que
    // existia antes de haver vários, e apagá-lo por engano levava tudo.
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 1) erro('Este casamento não pode ser apagado.');
    $st = $conn->prepare("SELECT nome FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    // Pela ordem certa: os convidados dependem dos convites.
    foreach (['convidados','convites','mesas','versoes','registo','definicoes'] as $t) {
        $st = $conn->prepare("DELETE FROM {$P}$t WHERE casamento_id=?");
        $st->bind_param('i', $id); @$st->execute();
    }
    foreach (['acessos', 'suporte_codigos'] as $t) {
        $st = $conn->prepare("DELETE FROM {$P}$t WHERE casamento_id=?");
        $st->bind_param('i', $id); @$st->execute();
    }
    $st = $conn->prepare("DELETE FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    if ((int)($_SESSION['casamento_id'] ?? 0) === $id) unset($_SESSION['casamento_id']);
    registar($conn, 'casamento_apagado', $c['nome'], 'id ' . $id);
    ok(['id' => $id]);
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
                   WHERE " . doCasamento() . " AND eliminado_em IS NOT NULL
                     AND eliminado_em < DATE_SUB(NOW(), INTERVAL ".RECICLAGEM_DIAS." DAY)");
    $r = $conn->query("SELECT id, codigo, nome_exibicao, lugares, eliminado_em
                       FROM {$P}convites WHERE " . doCasamento() . " AND eliminado_em IS NOT NULL
                       ORDER BY eliminado_em DESC");
    ok(['convites' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [], 'dias' => RECICLAGEM_DIAS]);
}

if ($acao === 'registo_lista') {
    // O histórico só cresce: se mandássemos tudo, ao fim de um mês eram
    // milhares de linhas em cada abertura da janela. Vai por pedaços.
    $porPag = max(10, min(500, (int)($_GET['por_pagina'] ?? 100)));
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $total  = (int)(@$conn->query("SELECT COUNT(*) FROM {$P}registo WHERE " . doCasamento() . "")?->fetch_row()[0] ?? 0);
    $r = $conn->query("SELECT utilizador, papel, accao, alvo, detalhe, criado_em
                       FROM {$P}registo WHERE " . doCasamento() . " ORDER BY id DESC
                       LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag));
    ok(['registos' => $r ? $r->fetch_all(MYSQLI_ASSOC) : [],
        'total' => $total, 'pagina' => $pagina, 'ha_mais' => ($pagina * $porPag) < $total]);
}

if ($acao === 'convite_flag') {
    $id=(int)($_GET['id']??0); $campo=$_GET['campo']??''; $valor=!empty($_GET['valor'])?1:0;
    if (!in_array($campo,['impresso','enviado'],true)) erro('Campo inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET $campo=?, atualizado_em=$TS WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('ii',$valor,$id); $st->execute();
    registar($conn, $campo.($valor?'_sim':'_nao'), '', 'id '.$id);
    ok(['stats'=>estatisticas($conn)]);
}

if ($acao === 'convite_rsvp_manual') {
    $id=(int)($_GET['id']??0); $estado=$_GET['estado']??'';
    if (!in_array($estado,['pendente','confirmado','recusado','parcial'],true)) erro('Estado inválido.');
    $st=$conn->prepare("UPDATE {$P}convites SET rsvp_estado=?, rsvp_em=$TS WHERE " . doCasamento() . " AND id=?");
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
        $cid = casamentoAtual();
        if ($val === null) { $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='".$conn->real_escape_string($chave)."'"); continue; }
        $st=$conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $sv=(string)$val; $st->bind_param('iss',$cid,$chave,$sv); $st->execute();
    }
    ok(['canvas'=>plantaConfig($conn)]);
}
if ($acao === 'planta_bloqueio') {
    // Trava/destrava o arrasto das mesas e o redimensionar do canvas.
    $d = corpo();
    foreach (['bloq_mesas' => 'planta.bloq_mesas', 'bloq_canvas' => 'planta.bloq_canvas'] as $campo => $chave) {
        if (!array_key_exists($campo, $d)) continue;
        $val = !empty($d[$campo]) ? '1' : '0';
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (" . casamentoAtual() . ",?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
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
    if ($id){ $st=$conn->prepare("UPDATE {$P}mesas SET nome=?,capacidade=?,forma=?,cor=?,tamanho=? WHERE " . doCasamento() . " AND id=?"); $st->bind_param('sisssi',$nome,$cap,$forma,$cor,$tam,$id); }
    else    { $st=$conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,tamanho) VALUES (" . casamentoAtual() . ",?,?,?,?,?)"); $st->bind_param('sisss',$nome,$cap,$forma,$cor,$tam); }
    @$st->execute();
    if ($conn->errno===1062) erro('Já existe uma mesa com esse nome.');
    $novoId = $id ?: $conn->insert_id;
    ok(['mesas'=>listarMesas($conn),'id'=>$novoId]);
}
if ($acao === 'mesa_noivos') {
    // Repõe a mesa (especial) dos noivos, se tiver sido eliminada. Se já existir, devolve-a.
    $ja = $conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1")->fetch_assoc();
    if ($ja) { ok(['mesas'=>listarMesas($conn),'id'=>(int)$ja['id'],'existia'=>true]); }
    $nome='Noivos'; $n=2;
    while ($conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome='".$conn->real_escape_string($nome)."'")->num_rows) { $nome='Noivos '.$n++; }
    $st=$conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (" . casamentoAtual() . ",?,2,'redonda','ouro','noivos',50,42)");
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
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=?,forma=? WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('ddsi',$x,$y,$forma,$id);
    } else {
        $st=$conn->prepare("UPDATE {$P}mesas SET pos_x=?,pos_y=? WHERE " . doCasamento() . " AND id=?");
        $st->bind_param('ddi',$x,$y,$id);
    }
    $st->execute();
    ok();
}
if ($acao === 'mesa_delete') {
    $id=(int)($_GET['id']??0);
    $nm = $conn->query("SELECT nome FROM {$P}mesas WHERE " . doCasamento() . " AND id=$id");
    $nomeMesa = ($nm && $x=$nm->fetch_assoc()) ? $x['nome'] : '';
    $conn->query("UPDATE {$P}convites SET mesa_id=NULL WHERE " . doCasamento() . " AND mesa_id=$id");
    $conn->query("UPDATE {$P}convidados SET mesa_id=NULL WHERE " . doCasamento() . " AND mesa_id=$id"); // mesas individuais também
    $st=$conn->prepare("DELETE FROM {$P}mesas WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$id); $st->execute();
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
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=?,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?"); $st->bind_param('ii',$mesaId,$id); }
    else        { $st=$conn->prepare("UPDATE {$P}convites SET mesa_id=NULL,atualizado_em=$TS WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$id); }
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
    if ($mesaId){ $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=?$limpaPapel WHERE " . doCasamento() . " AND id=?"); $st->bind_param('ii',$mesaId,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET mesa_id=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$gid); }
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
    if ($papel){ $st=$conn->prepare("UPDATE {$P}convidados SET papel=?, mesa_id=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('si',$papel,$gid); }
    else        { $st=$conn->prepare("UPDATE {$P}convidados SET papel=NULL WHERE " . doCasamento() . " AND id=?"); $st->bind_param('i',$gid); }
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
          WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."
          ORDER BY c.nome_exibicao, g.principal DESC, g.nome";
    $rows=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    // Mesa dos noivos (para a deteção automática de padrinhos/madrinhas).
    $noivos = $conn->query("SELECT id, nome FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1")->fetch_assoc();
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
    if (!empty($_GET['forcar'])) { $conn->query("DELETE FROM {$P}convites WHERE " . doCasamento()); }

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
            $st=$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,tipo,lado,lugares,mesa_id,telefone,rsvp_estado,rsvp_confirmados,observacoes,criado_em,atualizado_em) VALUES (" . casamentoAtual() . ",?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
            $tipo='fisico'; $lado='noivo';
            $st->bind_param('ssssiissis',$codigo,$g['name'],$tipo,$lado,$n,$mesa,$tel,$estado,$conf,$notas);
            $st->execute(); $cid=$conn->insert_id; $criadosC++;
            $primeiro=true;
            foreach($membros as $m){ $r=$estadoMap[$m['confirmed']]??'pendente'; $pr=$primeiro?1:0; $primeiro=false;
                $q=$conn->prepare("INSERT INTO {$P}convidados (casamento_id,convite_id,nome,principal,rsvp) VALUES (" . casamentoAtual() . ",?,?,?,?)");
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
        $st=$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,tipo,lado,lugares,mesa_id,telefone,rsvp_estado,rsvp_confirmados,observacoes,criado_em,atualizado_em) VALUES (" . casamentoAtual() . ",?,?,?,?,?,?,?,?,?,?, $TS, $TS)");
        $tipo='digital'; $lug=1;
        $st->bind_param('ssssiissis',$codigo,$m['name'],$tipo,$lado,$lug,$mesa,$m['phone'],$estado,$conf,$m['notes']);
        $st->execute(); $cid=$conn->insert_id; $criadosC++;
        $q=$conn->prepare("INSERT INTO {$P}convidados (casamento_id,convite_id,nome,principal,rsvp) VALUES (" . casamentoAtual() . ",?,?,1,?)");
        $q->bind_param('iss',$cid,$m['name'],$estado); $q->execute(); $criadosG++;
    }

    ok(['convites'=>$criadosC,'convidados'=>$criadosG]);
}

erro('Ação desconhecida.');
