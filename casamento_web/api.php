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

/**
 * Os dados do evento, escritos à nascença do casamento.
 *
 * Perguntar tudo no primeiro registo evita o casamento que fica meses com o
 * local de outra pessoa — os valores de origem vêm do config.php, e um casal
 * que nunca abra o editor manda convites com a morada errada. Aqui grava-se o
 * que ele escreveu, e o resto fica no original até alguém lá ir.
 */
function guardarEventoDoRegisto(mysqli $conn, int $cid, array $d): int {
    $mapa = [
        'hora'            => 'evento.hora',
        'venue_titulo'    => 'evento.venue_titulo',
        'local'           => 'evento.local',
        'cidade'          => 'evento.cidade',
        'convidados'      => 'evento.convidados',
        'whatsapp'        => 'evento.whatsapp',
        'maps'            => 'evento.maps',
        'civil_hora'      => 'evento.civil_hora',
        'civil_local'     => 'evento.civil_local',
        'civil_maps'      => 'evento.civil_maps',
        'religiosa_hora'  => 'evento.religiosa_hora',
        'religiosa_local' => 'evento.religiosa_local',
        'religiosa_maps'  => 'evento.religiosa_maps',
    ];
    $defs = [];
    foreach ($mapa as $campo => $chave) {
        if (!array_key_exists($campo, $d)) continue;      // não veio: fica o original
        $defs[$chave] = (string)$d[$campo];
    }
    if (!$defs) return 0;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $r = guardarDefinicoes($conn, $defs);
    usarCasamento($anterior > 0 ? $anterior : $cid);
    return (int)($r['gravadas'] ?? 0);
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
    global $acao;
    if (ehAdmin()) return;
    // As ações da própria plataforma não são de casamento nenhum: quem responde
    // pela casa tem de as poder fazer sem ter uma festa aberta — é assim que
    // entra. Cada uma delas confere depois, por si, se é mesmo admin da casa.
    if (ehAdminPlataforma() && in_array($acao, acoesSemCasamento(), true)) return;
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

    $gravadas = guardarEventoDoRegisto($conn, $cid, $d);

    $_SESSION['registo_feito'] = time();
    usarCasamento($cid);
    registar($conn, 'registo_publico', $nomeConta, $email);
    ok(['casamento' => $cid, 'dados_do_evento' => $gravadas]);
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
    // Mexer no desenho à mão tira o modelo de vigor: o que a peça mostra deixou
    // de ser puramente o dele. (Se voltarem ao desenho exato do modelo, a lista
    // volta a marcá-lo — a marca confirma-se contra o desenho, não fica presa.)
    if ($r['gravadas'] || $r['repostas']) {
        foreach (array_keys(ambitosVersao()) as $amb) esquecerModeloEmVigor($conn, $amb);
    }
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
    esquecerModeloEmVigor($conn, $ambito);
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
        esquecerModeloEmVigor($conn, $ambito);
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
    esquecerModeloEmVigor($conn, $v['ambito']);

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
    $gravadas = guardarEventoDoRegisto($conn, $novo, $d);
    registar($conn, 'casamento_criado', $nome, 'id ' . $novo);
    ok(['id' => $novo, 'nome' => $nome, 'dados_do_evento' => $gravadas]);
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

if ($acao === 'utilizador_editar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita contas.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT email, papel_plataforma FROM {$P}utilizadores WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) erro('Conta não encontrada.');

    $email = mb_strtolower(trim((string)($d['email'] ?? $u['email'])));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $plat  = array_key_exists('papel_plataforma', $d)
           ? (in_array($d['papel_plataforma'], ['admin','suporte'], true) ? $d['papel_plataforma'] : null)
           : $u['papel_plataforma'];

    // Não se tira a si próprio o papel que lhe permite estar nesta página: o
    // sistema ficava sem quem responde por ele, e a única saída era a base.
    if ($id === utilizadorId() && $plat !== 'admin') erro('Não pode tirar-se a si próprio da administração.');
    if ($u['papel_plataforma'] === 'admin' && $plat !== 'admin') {
        $r = $conn->query("SELECT COUNT(*) n FROM {$P}utilizadores
                           WHERE papel_plataforma='admin' AND estado='ativo' AND id <> " . $id);
        if ($r && (int)$r->fetch_assoc()['n'] === 0) erro('É o último admin da plataforma.');
    }
    // Uma conta que passa a suporte larga os lugares que tinha: passa a entrar
    // por código, e um lugar próprio seria uma porta paralela.
    if ($plat === 'suporte' && $u['papel_plataforma'] !== 'suporte') {
        $conn->query("DELETE FROM {$P}acessos WHERE utilizador_id=" . $id);
    }

    $st = $conn->prepare("UPDATE {$P}utilizadores SET email=?, nome=?, papel_plataforma=? WHERE id=?");
    $st->bind_param('sssi', $email, $nome, $plat, $id);
    if (!$st->execute()) erro('Já existe uma conta com esse email.');
    registar($conn, 'conta_editada', $email, $plat ? ('plataforma: ' . $plat) : 'sem papel de plataforma');
    ok(['id' => $id, 'email' => $email, 'papel_plataforma' => $plat]);
}

if ($acao === 'utilizador_casamentos') {
    // Os lugares desta conta, para os poder dar e tirar num sítio só.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê os lugares de uma conta.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT a.casamento_id, a.papel, c.nome, c.estado
                          FROM {$P}acessos a JOIN {$P}casamentos c ON c.id = a.casamento_id
                          WHERE a.utilizador_id = ? ORDER BY c.nome");
    $st->bind_param('i', $id); $st->execute();
    ok(['acessos' => $st->get_result()->fetch_all(MYSQLI_ASSOC)]);
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
    // Contas criadas pela casa. O registo público entra 'pendente' e espera
    // aprovação; usa a mesma tabela.
    //
    // Três tipos, e a diferença entre eles não é decorativa:
    //   • noivos   — gerem um casamento; ligam-se a ele aqui.
    //   • porteiro — só a porta desse casamento.
    //   • suporte  — NÃO se liga a casamento nenhum. Entra com o código que o
    //     casal gerar, e é esse código que lhe abre a porta e diz o que pode
    //     fazer lá dentro. Prender uma conta de suporte a um casamento seria
    //     dar-lhe pela porta das traseiras o que o casal tem de decidir.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma cria contas.');
    $d = corpo();
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    $nome  = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    $senha = (string)($d['senha'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Indique um email válido.');
    if (mb_strlen($senha) < 8) erro('A senha precisa de pelo menos 8 caracteres.');
    $plat = in_array($d['papel_plataforma'] ?? '', ['admin','suporte'], true) ? $d['papel_plataforma'] : null;
    if ($plat === 'suporte' && (int)($d['casamento_id'] ?? 0) > 0) {
        erro('Uma conta de suporte não se prende a um casamento: entra com o código que o casal gerar.');
    }
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
    $st = $conn->prepare("SELECT a.utilizador_id, a.papel, u.email, u.nome, u.estado, u.ultimo_acesso,
                                 u.papel_plataforma
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
    // Os noivos convidam PORTEIROS. Passar a gestão do casamento a outra conta
    // é coisa que se faz com quem responde pela casa presente — senão bastava
    // um convite mal dirigido para o casamento passar a ser de outra pessoa.
    if (!ehAdminPlataforma()) $papelCas = 'porteiro';
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
    $q = $conn->prepare("SELECT papel_plataforma FROM {$P}utilizadores WHERE id=?");
    $q->bind_param('i', $uid); $q->execute();
    $alvo = $q->get_result()->fetch_assoc();
    if (!$alvo) erro('Conta não encontrada.');
    if (($alvo['papel_plataforma'] ?? '') === 'suporte') {
        erro('Uma conta de suporte não se prende a um casamento: entra com o código que o casal gerar.');
    }
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

if ($acao === 'suporte_sair' || $acao === 'casamento_fechar') {
    // Sair do casamento em que se está a trabalhar, sem terminar a sessão.
    // Vale para a visita de suporte (que assim não espera que o código expire)
    // e para quem responde pela casa, que entra e sai de casamentos alheios o
    // dia todo — e cuja única saída era abrir outro ou ir-se embora.
    $nome = '';
    $r = @$conn->query("SELECT nome FROM {$P}casamentos WHERE id=" . casamentoAtual());
    if ($r && ($x = $r->fetch_assoc())) $nome = (string)$x['nome'];
    if ($nome !== '') registar($conn, 'casamento_fechado', $nome);
    fecharCasamento();
    ok(['nome' => $nome]);
}

// ---- Contas, vistas pela plataforma -------------------------
if ($acao === 'casamento_lista') {
    // A lista da administração, servida como a das contas: procurável e por
    // ordem de USO. O número é a ordem por que foram criados, que é a menos
    // útil de todas — quem abre a página quer ver em cima aquilo em que andou.
    if (!ehPessoalPlataforma()) erro('Só o pessoal da plataforma vê os casamentos.');
    $q  = trim((string)($_GET['q'] ?? ''));
    $est = (string)($_GET['estado'] ?? 'ativo');
    if (!in_array($est, ['ativo','pendente','suspenso','arquivado','todos'], true)) $est = 'ativo';

    $onde = $est === 'todos' ? '1=1' : "c.estado = '" . $conn->real_escape_string($est) . "'";
    $liga = '';
    if ($q !== '') {
        $s = $conn->real_escape_string($q);
        $onde .= " AND (c.nome LIKE '%$s%' OR c.noiva LIKE '%$s%' OR c.noivo LIKE '%$s%')";
    }
    $r = @$conn->query("SELECT c.id, c.nome, c.noiva, c.noivo, c.estado, c.data_evento, c.ultimo_acesso,
                               (SELECT COUNT(*) FROM {$P}convites v
                                 WHERE v.casamento_id = c.id AND v.eliminado_em IS NULL) convites,
                               (SELECT COUNT(*) FROM {$P}convidados g
                                 WHERE g.casamento_id = c.id) pessoas,
                               -- Quantos já disseram que vêm. Um casamento com
                               -- 200 convites e 3 confirmações é uma notícia
                               -- diferente de um com 200 e 180; a lista dizia
                               -- os dois da mesma maneira.
                               (SELECT COUNT(*) FROM {$P}convidados g2
                                 WHERE g2.casamento_id = c.id AND g2.rsvp = 'confirmado') confirmados,
                               (SELECT COUNT(*) FROM {$P}acessos a
                                 WHERE a.casamento_id = c.id AND a.papel='noivos') donos
                        FROM {$P}casamentos c
                        WHERE $onde
                        ORDER BY c.ultimo_acesso IS NULL, c.ultimo_acesso DESC, c.id DESC
                        LIMIT 200");
    $lista = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];

    // Quem não é admin da casa só vê aqueles onde tem lugar.
    if (!ehAdminPlataforma()) {
        $meus = casamentosDoUtilizador($conn);
        $lista = array_values(array_filter($lista, fn($c) => isset($meus[(int)$c['id']])));
    }
    ok(['casamentos' => $lista, 'aberto' => casamentoAtual(), 'estado' => $est]);
}

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
    if (!in_array($novo, ['pendente','ativo','suspenso','inativo'], true)) erro('Estado inválido.');
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

if ($acao === 'acesso_tirar_de') {
    // Tira o lugar de uma conta num casamento indicado. O 'acesso_tirar' é do
    // casal, e trabalha sempre no casamento aberto; este é da plataforma, que
    // arruma contas sem ter de abrir a casa de cada uma.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma arruma lugares por aqui.');
    $uid = (int)($_GET['utilizador'] ?? 0);
    $cid = (int)($_GET['casamento'] ?? 0);
    if ($uid <= 0 || $cid <= 0) erro('Indique a conta e o casamento.');
    $st = $conn->prepare("DELETE FROM {$P}acessos WHERE utilizador_id=? AND casamento_id=?");
    $st->bind_param('ii', $uid, $cid);
    if (!$st->execute()) erro('Não foi possível tirar o lugar.');
    registar($conn, 'acesso_tirado', 'conta ' . $uid, 'casamento ' . $cid);
    ok(['utilizador' => $uid, 'casamento' => $cid]);
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

    // O estado do casamento arrasta o das contas que só existem por causa dele.
    //
    // Aprovar é abrir a porta às duas coisas ao mesmo tempo: o casamento passa
    // a ativo E a conta de quem se inscreveu deixa de estar à espera. Aprovar
    // só o casamento deixava o casal de fora, sem perceber porquê — e o admin
    // convencido de que já tinha tratado do assunto.
    //
    // Arquivar é o inverso: as contas do casal e do porteiro daquele casamento
    // ficam paradas, porque já não há lá nada para fazer. Só as que não têm
    // outro casamento de pé — quem é porteiro em dois não pode ficar fechado
    // por causa de um — e nunca o pessoal da casa, que não depende de
    // casamento nenhum para existir.
    $contas = 0;
    if ($novo === 'ativo') {
        // Volta quem parou COM ele: 'inativo' e 'pendente'. Quem foi suspenso
        // por outra razão continua suspenso — reabrir um casamento não desfaz
        // uma decisão que alguém tomou sobre uma pessoa.
        $st = $conn->prepare("UPDATE {$P}utilizadores u
                              JOIN {$P}acessos a ON a.utilizador_id = u.id
                              SET u.estado='ativo'
                              WHERE a.casamento_id = ? AND u.estado IN ('pendente','inativo')");
        $st->bind_param('i', $id);
        if ($st->execute()) $contas = $conn->affected_rows;
    } elseif ($novo === 'arquivado') {
        $st = $conn->prepare("UPDATE {$P}utilizadores u
                              JOIN {$P}acessos a ON a.utilizador_id = u.id
                              SET u.estado='inativo'
                              WHERE a.casamento_id = ?
                                AND u.estado = 'ativo'
                                AND u.papel_plataforma IS NULL
                                AND NOT EXISTS (
                                      SELECT 1 FROM {$P}acessos a2
                                      JOIN {$P}casamentos c2 ON c2.id = a2.casamento_id
                                      WHERE a2.utilizador_id = u.id
                                        AND a2.casamento_id <> ?
                                        AND c2.estado <> 'arquivado')");
        $st->bind_param('ii', $id, $id);
        if ($st->execute()) $contas = $conn->affected_rows;
    }
    // Arquivar o casamento que está aberto tem de o fechar: senão a sessão
    // continuava a trabalhar dentro de uma casa que já saiu das listas.
    if ($novo === 'arquivado' && (int)($_SESSION['casamento_id'] ?? 0) === $id) {
        $_SESSION['casamento_id'] = 0;
        $_SESSION['papel'] = null;
    }
    $rotulo = $novo === 'arquivado' ? 'conta(s) parada(s)' : 'conta(s) ativada(s)';
    registar($conn, 'casamento_estado', $c['nome'], $novo . ($contas ? " · $contas $rotulo" : ''));
    ok(['id' => $id, 'estado' => $novo,
        'contas_ativadas' => $novo === 'arquivado' ? 0 : $contas,
        'contas_paradas'  => $novo === 'arquivado' ? $contas : 0]);
}

if ($acao === 'casamento_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga casamentos.');
    // Apaga um casamento e TUDO o que é dele. Não se desfaz, e por isso pede-se
    // um passo antes: só se apaga o que já está arquivado.
    //
    // A trava antiga era o número — "o nº1 não se apaga" —, o que protegia um
    // casamento por acaso de ter sido o primeiro e deixava todos os outros à
    // mão de um clique. Arquivar primeiro protege-os a todos, e pela razão
    // certa: um casamento arquivado já saiu das listas de trabalho, já ninguém
    // o está a usar, e apagá-lo é uma segunda decisão e não a mesma.
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, estado FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) erro('Casamento não encontrado.');
    if ($c['estado'] !== 'arquivado') {
        erro('Só se apaga um casamento arquivado. Arquive-o primeiro — e leve os dados, '
           . 'se ainda os quiser.');
    }
    // O que se vai levar, para o dizer depois: apagar em silêncio um casamento
    // com 200 convidados lá dentro não é resposta para quem carregou no botão.
    $levou = [];
    foreach (['convites' => 'convites', 'convidados' => 'pessoas', 'mesas' => 'mesas'] as $tab => $rot) {
        $r = @$conn->query("SELECT COUNT(*) n FROM {$P}$tab WHERE casamento_id=" . $id);
        $levou[$rot] = $r ? (int)$r->fetch_assoc()['n'] : 0;
    }
    // Pela ordem certa: os convidados dependem dos convites.
    foreach (['convidados','convites','mesas','versoes','registo','definicoes',
              'acessos','suporte_codigos'] as $t) {
        $st = $conn->prepare("DELETE FROM {$P}$t WHERE casamento_id=?");
        $st->bind_param('i', $id); @$st->execute();
    }
    $st = $conn->prepare("DELETE FROM {$P}casamentos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    if ((int)($_SESSION['casamento_id'] ?? 0) === $id) {
        $_SESSION['casamento_id'] = 0;
        $_SESSION['papel'] = null;
    }
    registar($conn, 'casamento_apagado', $c['nome'],
             $levou['convites'] . ' convites · ' . $levou['pessoas'] . ' pessoas');
    ok(['id' => $id, 'nome' => $c['nome'], 'levou' => $levou]);
}

if ($acao === 'esquema_info') {
    // Retrato do esqueleto de dados. Serve para uma prova poder afirmar que a
    // migração para vários casamentos ficou bem feita — sobretudo que nenhum
    // dado ficou sem dono, que é o que separa isto de uma fuga entre casais.
    $um = function (string $sql) use ($conn) {
        $r = @$conn->query($sql); return $r ? (int)$r->fetch_row()[0] : -1;
    };
    // O registo fica de fora desta conta, e de propósito: uma ação da PLATAFORMA
    // (criar um casamento, aprovar um registo, mexer numa conta) não pertence a
    // casamento nenhum, e o seu rasto vive no 0 — o mesmo sítio reservado onde
    // está a versão do esquema. Contá-lo como órfão era chamar defeito ao que
    // está certo, e habituar a prova a ver vermelho.
    $orfaos = 0;
    foreach (['convites','convidados','mesas','versoes'] as $t) {
        $orfaos += max(0, $um("SELECT COUNT(*) FROM {$P}$t WHERE casamento_id IS NULL OR casamento_id < 1"));
    }
    $registoSistema = max(0, $um("SELECT COUNT(*) FROM {$P}registo WHERE casamento_id = 0"));
    $registoOrfao   = max(0, $um("SELECT COUNT(*) FROM {$P}registo WHERE casamento_id IS NULL OR casamento_id < 0"));
    $orfaos += $registoOrfao;
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
        'registo_da_plataforma' => $registoSistema,
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
// ============================================================
// LEVAR OS DADOS DAQUI, E TRAZÊ-LOS DE VOLTA
//
// Os dados de um casamento são do casal, não da casa. Tem de haver forma de os
// levar — para guardar, para mudar de servidor, para não ficar refém de
// ninguém. E o admin precisa do mesmo à escala da casa, que é o que se chama
// uma cópia de segurança.
//
// O ficheiro é JSON e diz de si: formato, versão do esquema, quando e por quem
// foi feito. As mesas viajam pelo NOME e não pelo número — os números são desta
// base e não querem dizer nada noutra.
// ============================================================

/** Tudo o que compõe um casamento, pronto a escrever num ficheiro. */
function retratoCasamento(mysqli $conn, int $cid): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);

    $um = function (string $sql) use ($conn) {
        $r = @$conn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    };
    $ficha = [];
    $r = @$conn->query("SELECT nome, noiva, noivo, data_evento, estado, endereco_publico
                        FROM {$P}casamentos WHERE id=$cid LIMIT 1");
    if ($r) $ficha = $r->fetch_assoc() ?: [];

    $defs = [];
    foreach ($um("SELECT chave, valor FROM {$P}definicoes WHERE casamento_id=$cid") as $d) {
        $defs[$d['chave']] = $d['valor'];
    }

    $mesas = $um("SELECT nome, capacidade, forma, cor, especial, pos_x, pos_y, tamanho
                  FROM {$P}mesas WHERE casamento_id=$cid ORDER BY nome");

    // O nome da mesa, e não o seu número: o número é desta base.
    $convites = $um("SELECT c.codigo, c.nome_exibicao, c.sufixo, c.tipo, c.lado, c.lugares,
                            m.nome AS mesa, c.telefone, c.msg_pessoal, c.observacoes,
                            c.rsvp_estado, c.rsvp_confirmados, c.rsvp_mensagem,
                            c.checkin_estado, c.checkin_presentes,
                            c.enviado, c.impresso, c.mostrar_num_mesa, c.eliminado_em
                     FROM {$P}convites c LEFT JOIN {$P}mesas m ON m.id = c.mesa_id
                     WHERE c.casamento_id=$cid ORDER BY c.id");
    $porCodigo = [];
    foreach ($convites as $i => $c) $porCodigo[$c['codigo']] = $i;
    foreach ($convites as &$c) $c['membros'] = [];
    unset($c);
    foreach ($um("SELECT c.codigo, g.nome, g.genero, g.principal, g.rsvp, g.presente,
                         g.brinde, g.papel, mg.nome AS mesa
                  FROM {$P}convidados g
                  JOIN {$P}convites c ON c.id = g.convite_id
                  LEFT JOIN {$P}mesas mg ON mg.id = g.mesa_id
                  WHERE g.casamento_id=$cid ORDER BY g.principal DESC, g.nome") as $g) {
        $cod = $g['codigo']; unset($g['codigo']);
        if (isset($porCodigo[$cod])) $convites[$porCodigo[$cod]]['membros'][] = $g;
    }

    $versoes = $um("SELECT nome, ambito, defs, predefinida, utilizador, criado_em, atualizado_em
                    FROM {$P}versoes WHERE casamento_id=$cid ORDER BY id");

    $acessos = $um("SELECT u.email, u.nome, a.papel FROM {$P}acessos a
                    JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                    WHERE a.casamento_id=$cid ORDER BY a.papel, u.email");

    usarCasamento($anterior > 0 ? $anterior : 1);
    return ['ficha' => $ficha, 'definicoes' => $defs, 'mesas' => $mesas,
            'convites' => $convites, 'versoes' => $versoes, 'acessos' => $acessos];
}

if ($acao === 'dados_exportar') {
    $ambito = ($_GET['ambito'] ?? 'casamento') === 'sistema' ? 'sistema' : 'casamento';
    $comSenhas = !empty($_GET['senhas']);

    $ids = [];
    if ($ambito === 'sistema') {
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva a casa inteira.');
        $r = @$conn->query("SELECT id FROM {$P}casamentos ORDER BY id");
        if ($r) while ($x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
    } elseif (!empty($_GET['id'])) {
        // Um casamento em concreto. Só o admin da casa, e serve sobretudo para
        // os arquivados: esses não se podem abrir, e sem isto "levar os dados"
        // de um arquivado levava, calado, os do casamento que estivesse aberto.
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva os dados de outro casamento.');
        $ids = [(int)$_GET['id']];
        $r = @$conn->query("SELECT id FROM {$P}casamentos WHERE id=" . $ids[0]);
        if (!$r || !$r->num_rows) erro('Casamento não encontrado.');
    } else {
        exigirAdminApi();
        $ids = [casamentoAtual()];
        if ($ids[0] <= 0) erro('Não há casamento aberto.');
    }

    $saida = [
        'formato'    => 'casamento-web/1',
        'esquema'    => ESQUEMA_VERSAO,
        'ambito'     => $ambito,
        'gerado_em'  => date('c'),
        'gerado_por' => utilizadorAtual() ?? '',
        'casamentos' => [],
    ];
    foreach ($ids as $cid) $saida['casamentos'][] = retratoCasamento($conn, $cid);

    if ($ambito === 'sistema') {
        // As contas só na cópia da casa inteira — e as senhas só a pedido, que
        // um ficheiro com senhas (ainda que cifradas) guarda-se como se guarda
        // a base de dados, não como se guarda uma folha de cálculo.
        $cols = 'id, email, nome, papel_plataforma, estado, criado_em' . ($comSenhas ? ', senha_hash' : '');
        $r = @$conn->query("SELECT $cols FROM {$P}utilizadores ORDER BY id");
        $contas = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($contas as &$c) unset($c['id']);
        unset($c);
        $saida['contas'] = $contas;
        $saida['com_senhas'] = $comSenhas ? 1 : 0;
    }

    $nome = 'dados-' . ($ambito === 'sistema' ? 'sistema' : 'casamento') . '-' . date('Y-m-d') . '.json';
    registar($conn, 'dados_exportados', $ambito, count($ids) . ' casamento(s)');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $nome);
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Escreve um casamento a partir de um retrato. Devolve o que fez.
 *
 * Os códigos dos convites são únicos em todo o sistema: se um já estiver
 * tomado, gera-se outro — e diz-se quantos, porque um código que muda é um QR
 * já impresso que deixa de servir.
 */
function reporCasamento(mysqli $conn, int $cid, array $r, bool $comFicha): array {
    global $P;
    $anterior = casamentoAtual();
    usarCasamento($cid);
    $feito = ['mesas' => 0, 'convites' => 0, 'pessoas' => 0, 'versoes' => 0,
              'definicoes' => 0, 'codigos_trocados' => 0];

    // Fora o que lá estava. É o que "substituir" quer dizer, e a página diz-o
    // antes de chegar aqui.
    foreach (['convidados', 'convites', 'mesas', 'versoes', 'definicoes'] as $t) {
        $conn->query("DELETE FROM {$P}$t WHERE casamento_id=$cid");
    }

    if ($comFicha && !empty($r['ficha'])) {
        $f = $r['ficha'];
        $st = $conn->prepare("UPDATE {$P}casamentos SET nome=?, noiva=?, noivo=?, data_evento=? WHERE id=?");
        $nome = mb_substr((string)($f['nome'] ?? ''), 0, 160);
        $noiva = mb_substr((string)($f['noiva'] ?? ''), 0, 80);
        $noivo = mb_substr((string)($f['noivo'] ?? ''), 0, 80);
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['data_evento'] ?? '')) ? $f['data_evento'] : null;
        $st->bind_param('ssssi', $nome, $noiva, $noivo, $data, $cid);
        @$st->execute();
    }

    foreach ((array)($r['definicoes'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_string($v)) continue;
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (?,?,?)");
        $st->bind_param('iss', $cid, $k, $v);
        if (@$st->execute()) $feito['definicoes']++;
    }

    $idMesa = [];
    foreach ((array)($r['mesas'] ?? []) as $m) {
        if (!is_array($m) || trim((string)($m['nome'] ?? '')) === '') continue;
        $nm = (string)$m['nome']; $cap = (int)($m['capacidade'] ?? 8);
        $forma = (string)($m['forma'] ?? 'redonda'); $cor = (string)($m['cor'] ?? 'neutra');
        $esp = isset($m['especial']) && $m['especial'] !== null ? (string)$m['especial'] : null;
        $px = isset($m['pos_x']) && $m['pos_x'] !== null ? (float)$m['pos_x'] : null;
        $py = isset($m['pos_y']) && $m['pos_y'] !== null ? (float)$m['pos_y'] : null;
        $tam = (int)($m['tamanho'] ?? 100);
        $st = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y,tamanho)
                              VALUES ($cid,?,?,?,?,?,?,?,?)");
        $st->bind_param('sisssddi', $nm, $cap, $forma, $cor, $esp, $px, $py, $tam);
        if (@$st->execute()) { $idMesa[$nm] = $conn->insert_id; $feito['mesas']++; }
    }

    foreach ((array)($r['convites'] ?? []) as $c) {
        if (!is_array($c) || trim((string)($c['nome_exibicao'] ?? '')) === '') continue;
        $codigo = strtoupper(trim((string)($c['codigo'] ?? '')));
        if ($codigo === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $codigo)) {
            $codigo = gerarCodigo($conn); $feito['codigos_trocados']++;
        } else {
            $q = $conn->prepare("SELECT id FROM {$P}convites WHERE casamento_id > 0 AND codigo=? LIMIT 1");
            $q->bind_param('s', $codigo); $q->execute();
            if ($q->get_result()->fetch_assoc()) { $codigo = gerarCodigo($conn); $feito['codigos_trocados']++; }
        }
        $mesaId = $idMesa[(string)($c['mesa'] ?? '')] ?? null;
        $st = $conn->prepare("INSERT INTO {$P}convites
              (casamento_id, codigo, nome_exibicao, sufixo, tipo, lado, lugares, mesa_id, telefone,
               msg_pessoal, observacoes, rsvp_estado, rsvp_confirmados, rsvp_mensagem,
               checkin_estado, checkin_presentes, enviado, impresso, mostrar_num_mesa, eliminado_em)
              VALUES ($cid,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $vals = [
            $codigo,
            mb_substr((string)$c['nome_exibicao'], 0, 160),
            isset($c['sufixo']) && $c['sufixo'] !== null ? (string)$c['sufixo'] : null,
            in_array($c['tipo'] ?? '', ['digital','fisico','ambos'], true) ? $c['tipo'] : 'digital',
            in_array($c['lado'] ?? '', ['noivo','noiva','ambos'], true) ? $c['lado'] : 'ambos',
            max(1, (int)($c['lugares'] ?? 1)),
            $mesaId,
            isset($c['telefone']) && $c['telefone'] !== null ? (string)$c['telefone'] : null,
            isset($c['msg_pessoal']) && $c['msg_pessoal'] !== null ? (string)$c['msg_pessoal'] : null,
            isset($c['observacoes']) && $c['observacoes'] !== null ? (string)$c['observacoes'] : null,
            in_array($c['rsvp_estado'] ?? '', ['pendente','confirmado','parcial','recusado'], true) ? $c['rsvp_estado'] : 'pendente',
            (int)($c['rsvp_confirmados'] ?? 0),
            isset($c['rsvp_mensagem']) && $c['rsvp_mensagem'] !== null ? (string)$c['rsvp_mensagem'] : null,
            in_array($c['checkin_estado'] ?? '', ['aguardando','presente','parcial'], true) ? $c['checkin_estado'] : 'aguardando',
            (int)($c['checkin_presentes'] ?? 0),
            (int)!empty($c['enviado']),
            (int)!empty($c['impresso']),
            isset($c['mostrar_num_mesa']) ? (int)$c['mostrar_num_mesa'] : 1,
            isset($c['eliminado_em']) && $c['eliminado_em'] !== null ? (string)$c['eliminado_em'] : null,
        ];
        $st->bind_param('sssssiissssissiiiis', ...$vals);   // 19 colunas, pela ordem acima
        if (!@$st->execute()) continue;
        $convId = $conn->insert_id; $feito['convites']++;

        foreach ((array)($c['membros'] ?? []) as $g) {
            if (!is_array($g) || trim((string)($g['nome'] ?? '')) === '') continue;
            $gm = $idMesa[(string)($g['mesa'] ?? '')] ?? null;
            $q = $conn->prepare("INSERT INTO {$P}convidados
                  (casamento_id, convite_id, nome, genero, principal, rsvp, presente, brinde, papel, mesa_id)
                  VALUES ($cid,?,?,?,?,?,?,?,?,?)");
            $gnome = mb_substr((string)$g['nome'], 0, 120);
            $gen  = in_array($g['genero'] ?? '', ['m','f'], true) ? $g['genero'] : null;
            $prin = (int)!empty($g['principal']);
            $rsvp = in_array($g['rsvp'] ?? '', ['pendente','confirmado','recusado'], true) ? $g['rsvp'] : 'pendente';
            $pres = (int)!empty($g['presente']);
            $bri  = (int)!empty($g['brinde']);
            $pap  = isset($g['papel']) && $g['papel'] !== null ? (string)$g['papel'] : null;
            $q->bind_param('issisiisi', $convId, $gnome, $gen, $prin, $rsvp, $pres, $bri, $pap, $gm);
            if (@$q->execute()) $feito['pessoas']++;
        }
    }

    foreach ((array)($r['versoes'] ?? []) as $v) {
        if (!is_array($v) || trim((string)($v['nome'] ?? '')) === '') continue;
        $st = $conn->prepare("INSERT INTO {$P}versoes (casamento_id, nome, ambito, defs, predefinida, utilizador)
                              VALUES ($cid,?,?,?,?,?)");
        $vn = mb_substr((string)$v['nome'], 0, 80);
        $va = in_array($v['ambito'] ?? '', ['digital','impresso'], true) ? $v['ambito'] : 'digital';
        $vd = (string)($v['defs'] ?? '{}');
        $vp = (int)!empty($v['predefinida']);
        $vu = (string)($v['utilizador'] ?? '');
        $st->bind_param('sssis', $vn, $va, $vd, $vp, $vu);
        if (@$st->execute()) $feito['versoes']++;
    }

    usarCasamento($anterior > 0 ? $anterior : $cid);
    return $feito;
}

if ($acao === 'dados_importar') {
    $d = corpo();
    $f = is_array($d['ficheiro'] ?? null) ? $d['ficheiro'] : null;
    $modo = ($d['modo'] ?? 'substituir') === 'novo' ? 'novo' : 'substituir';
    if (!$f) erro('Ficheiro inválido: não se percebeu o conteúdo.');
    if (($f['formato'] ?? '') !== 'casamento-web/1') {
        erro('Este ficheiro não é uma exportação deste sistema.');
    }
    $lista = is_array($f['casamentos'] ?? null) ? $f['casamentos'] : [];
    if (!$lista) erro('O ficheiro não traz casamento nenhum.');

    $resumo = [];
    if ($modo === 'novo') {
        if (!ehAdminPlataforma()) erro('Só o admin da plataforma traz casamentos novos.');
        foreach ($lista as $r) {
            if (!is_array($r)) continue;
            $fi = (array)($r['ficha'] ?? []);
            $nome  = mb_substr(trim((string)($fi['nome'] ?? 'Casamento importado')), 0, 160) ?: 'Casamento importado';
            $noiva = mb_substr((string)($fi['noiva'] ?? ''), 0, 80);
            $noivo = mb_substr((string)($fi['noivo'] ?? ''), 0, 80);
            $data  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($fi['data_evento'] ?? '')) ? $fi['data_evento'] : null;
            $st = $conn->prepare("INSERT INTO {$P}casamentos (nome, noiva, noivo, data_evento, estado)
                                  VALUES (?,?,?,?, 'ativo')");
            $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
            if (!$st->execute()) continue;
            $novo = $conn->insert_id;
            $feito = reporCasamento($conn, $novo, $r, false);
            $feito['id'] = $novo; $feito['nome'] = $nome;
            $resumo[] = $feito;
            registar($conn, 'dados_importados', $nome, 'casamento novo #' . $novo);
        }
        if (!$resumo) erro('Não foi possível criar casamento nenhum a partir do ficheiro.');
    } else {
        exigirAdminApi();
        exigirCorrecao();
        $cid = casamentoAtual();
        if ($cid <= 0) erro('Não há casamento aberto.');
        if (count($lista) > 1) {
            erro('Este ficheiro traz ' . count($lista) . ' casamentos. Para os trazer todos, '
               . 'use "criar casamentos novos" na página de administração.');
        }
        $comFicha = !empty($d['com_ficha']);
        $feito = reporCasamento($conn, $cid, $lista[0], $comFicha);
        $feito['id'] = $cid;
        $resumo[] = $feito;
        registar($conn, 'dados_importados', 'substituição', json_encode($feito));
    }
    ok(['modo' => $modo, 'resumo' => $resumo]);
}


// ============================================================
// MODELOS DE CONVITE — os desenhos que a casa oferece a todos
//
// As versões são de cada casamento: o que ESTE casal guardou. Os modelos são o
// outro lado — desenhos prontos, feitos pela casa, para um casal começar de um
// convite bonito em vez de uma folha em branco.
//
// Aplicar um modelo é COPIÁ-LO para as definições do casamento. A partir daí o
// desenho é do casal: mexer no modelo depois disso não lhe toca, e um casal que
// tenha personalizado o seu convite não acorda com ele mudado porque a casa
// mexeu num modelo. É a diferença entre dar uma receita e cozinhar em casa
// alheia.
// ============================================================

if ($acao === 'modelo_lista') {
    // Os casais veem os que lhes são destinados; quem gere a casa vê todos,
    // para poder preparar um modelo antes de o mostrar.
    if (!utilizadorId()) { http_response_code(401); erro('Sessão terminada. Entre de novo.'); }
    $a = ($_GET['ambito'] ?? '') ;
    $onde = isset(ambitosVersao()[$a]) ? "ambito='" . $conn->real_escape_string($a) . "'" : '1=1';
    if (!ehAdminPlataforma()) {
        // Um casal vê um modelo se estiver publicado E se lhe for destinado:
        // ou é de todos, ou é dos escolhidos e ele é um deles.
        $cid = casamentoAtual();
        $onde .= " AND visivel=1 AND (alcance='todos' OR id IN
                     (SELECT modelo_id FROM {$P}modelo_casamentos WHERE casamento_id=" . (int)$cid . "))";
    }
    $r = @$conn->query("SELECT id, nome, descricao, ambito, defs, visivel, alcance, criado_por, criado_em, atualizado_em
                        FROM {$P}modelos WHERE $onde ORDER BY ambito, nome");
    $modelos = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];

    // Qual deles é JÁ o desenho da peça. Sem isto o painel oferecia "pôr em
    // vigor" a um modelo que já estava em vigor — e o casal carregava, nada
    // mudava, e concluía que a função não funcionava. Compara-se o que aplicar
    // produziria com o que a peça mostra: é a mesma conta de modelo_aplicar.
    $atual = []; $vigorId = [];
    if (casamentoAtual() > 0) {
        foreach (array_keys(ambitosVersao()) as $amb) {
            $atual[$amb] = instantaneoAmbito($conn, $amb);
            $vigorId[$amb] = modeloEmVigorId($conn, $amb);
        }
    }
    foreach ($modelos as &$m) {
        $amb = $m['ambito'];
        $m['em_vigor'] = false;
        $m['mesmo_desenho'] = false;
        if (isset($atual[$amb])) {
            $j = json_decode((string)$m['defs'], true);
            $permitidas = array_flip(chavesDesenho($amb));
            $doModelo = [];
            if (is_array($j)) foreach ($j as $k => $v) {
                if (isset($permitidas[$k]) && is_string($v)) $doModelo[$k] = $v;
            }
            $seAplicado = array_merge($atual[$amb], padraoDesenho($amb), $doModelo);
            // «Mesmo desenho»: aplicá-lo seria um não-fazer-nada visível. «Em
            // vigor»: é ESTE o modelo que foi aplicado, e o desenho continua o
            // dele. Só um pode estar em vigor; vários podem ter o mesmo desenho.
            $m['mesmo_desenho'] = $seAplicado == $atual[$amb];
            $m['em_vigor'] = $m['mesmo_desenho'] && (int)$m['id'] === ($vigorId[$amb] ?? 0);
        }
        // O desenho não vai para o cliente: é grande, e a lista só precisa de
        // saber quem é quem.
        unset($m['defs']);
    }
    unset($m);
    // Ao admin junta-se quais casamentos cada modelo "de escolhidos" alcança,
    // para o painel os mostrar assinalados.
    if (ehAdminPlataforma() && $modelos) {
        $sel = [];
        $rr = @$conn->query("SELECT modelo_id, casamento_id FROM {$P}modelo_casamentos");
        if ($rr) while ($x = $rr->fetch_assoc()) $sel[(int)$x['modelo_id']][] = (int)$x['casamento_id'];
        foreach ($modelos as &$m) $m['casamentos'] = $sel[(int)$m['id']] ?? [];
        unset($m);
    }
    ok(['modelos' => $modelos]);
}

if ($acao === 'modelo_visibilidade') {
    // Quem vê este modelo: todos os casais, ou só os escolhidos. É do admin.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma gere a visibilidade dos modelos.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $alcance = ($d['alcance'] ?? 'todos') === 'selecionados' ? 'selecionados' : 'todos';
    // Os ids vêm do cliente: só entram os que são mesmo casamentos, para não
    // encher a tabela de junção com números inventados.
    $ids = [];
    if ($alcance === 'selecionados') {
        foreach ((array)($d['casamentos'] ?? []) as $c) {
            $c = (int)$c;
            if ($c > 0) $ids[$c] = true;
        }
        if ($ids) {
            $lista = implode(',', array_map('intval', array_keys($ids)));
            $rr = @$conn->query("SELECT id FROM {$P}casamentos WHERE id IN ($lista)");
            $validos = [];
            if ($rr) while ($x = $rr->fetch_assoc()) $validos[(int)$x['id']] = true;
            $ids = $validos;
        }
        // Escolhidos sem escolha nenhuma não faz sentido: seria um modelo que
        // ninguém vê, o que já é o "rascunho". Guarda-se como 'todos' e o painel
        // avisa. (Aqui só se normaliza: sem ids, volta a 'todos'.)
        if (!$ids) $alcance = 'todos';
    }

    $st = $conn->prepare("UPDATE {$P}modelos SET alcance=? WHERE id=?");
    $st->bind_param('si', $alcance, $id); $st->execute();
    $conn->query("DELETE FROM {$P}modelo_casamentos WHERE modelo_id=" . $id);
    if ($alcance === 'selecionados' && $ids) {
        $st = $conn->prepare("INSERT INTO {$P}modelo_casamentos (modelo_id, casamento_id) VALUES (?, ?)");
        foreach (array_keys($ids) as $c) { $st->bind_param('ii', $id, $c); @$st->execute(); }
    }
    registar($conn, 'modelo_visibilidade', (string)$m['nome'],
             $alcance === 'todos' ? 'todos os casamentos' : count($ids) . ' casamento(s)');
    ok(['id' => $id, 'alcance' => $alcance, 'casamentos' => array_keys($ids)]);
}

if ($acao === 'modelo_exemplo') {
    // Os dados com que um modelo NOVO nasce. Só de leitura.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma vê os dados de exemplo.');
    // A galeria vai com o resto: o painel precisa dela para a janela de escolha.
    ok(['exemplo' => exemploModelo($conn), 'fabrica' => exemploDeFabrica(),
        'chaves' => chavesExemplo(), 'galeria' => galeriaCompleta($conn),
        'categorias' => categoriasGaleria(), 'ocultas' => count(galeriaOcultas($conn))]);
}

if ($acao === 'modelo_exemplo_guardar') {
    // O admin muda o casal e o evento de exemplo. Vale para os modelos que se
    // criarem daqui para a frente: os que já existem ficam como estão.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os dados de exemplo.');
    $d = corpo();
    $fabrica = exemploDeFabrica();
    $mudados = []; $invalidas = [];
    foreach (chavesExemplo() as $k) {
        if (!array_key_exists($k, $d)) continue;
        $v = trim((string)$d[$k]);
        // Campo deixado em branco onde branco não é uma resposta (um modelo sem
        // nome de noiva não é um modelo): volta ao de fábrica, e não a um erro.
        if ($v === '' && !podeSerVazio($k)) $v = (string)($fabrica[$k] ?? '');
        if (str_starts_with($k, 'media.')) {
            // Um caminho de ficheiro nosso, e um que exista: um exemplo com uma
            // imagem partida é pior do que um exemplo com a de fábrica.
            if ($v !== '' && (!preg_match('#^assets/convite/[\w./-]+$#', $v)
                              || str_contains($v, '..') || !is_file(__DIR__ . '/' . $v))) {
                $invalidas[] = $k; continue;
            }
        } else {
            // A mesma validação de sempre — é ela que sabe o que cada chave
            // aceita, e uma cópia aqui ficava para trás à primeira mudança.
            $limpo = validarDefinicao($k, $v);
            if ($limpo === null) { $invalidas[] = $k; continue; }
            $v = $limpo;
        }
        $mudados[$k] = $v;
    }
    if ($invalidas) erro('Valor inválido em: ' . implode(', ', $invalidas));
    if (!$mudados) erro('Nada para guardar.');

    // Os dados de exemplo são do sistema, não de um casamento: vivem na linha 0.
    // Igual ao de fábrica é não ter escolha nenhuma — a linha sai, como em
    // qualquer definição que volte ao valor de origem.
    $ins = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (0,?,?)
                           ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $del = $conn->prepare("DELETE FROM {$P}definicoes WHERE casamento_id=0 AND chave=?");
    foreach ($mudados as $k => $v) {
        $chave = 'modelo.exemplo.' . $k;
        if ($v === ($fabrica[$k] ?? null)) { $del->bind_param('s', $chave); $del->execute(); }
        else { $ins->bind_param('ss', $chave, $v); $ins->execute(); }
    }
    registar($conn, 'modelo_exemplo', 'dados de exemplo dos modelos', implode(', ', array_keys($mudados)));
    ok(['exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_exemplo_upload') {
    // Uma imagem para o convite de exemplo. Vai para uma pasta só dela: não se
    // mistura com as fotografias de um casamento, que são de alguém.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita os dados de exemplo.');
    // 'chave' diz em que secção a pôr em vigor, e pode vir vazia: da janela de
    // gestão envia-se para a galeria sem a usar já em lado nenhum.
    $chave = $_POST['chave'] ?? '';
    $ehMusica = $chave === 'media.musica';
    $categoria = $_POST['categoria'] ?? ($chave ? categoriaDaChave($chave) : 'sem');
    if (!isset(categoriasGaleria()[$categoria])) $categoria = 'sem';
    if ($chave !== '' && !$ehMusica
        && !in_array($chave, ['media.hero','media.historia','media.interludio','media.acesso'], true)) {
        erro('Campo de ficheiro inválido.');
    }
    if (empty($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) erro('Falha no envio do ficheiro.');
    $f = $_FILES['ficheiro'];
    $max = $ehMusica ? 8*1024*1024 : 5*1024*1024;
    if ($f['size'] > $max) erro('Ficheiro demasiado grande (máx. ' . ($ehMusica ? '8' : '5') . ' MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $extsOk = $ehMusica ? ['m4a','mp3'] : ['jpg','jpeg','png','webp','svg'];
    if (!in_array($ext, $extsOk, true)) erro('Formato não suportado (' . implode('/', $extsOk) . ').');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mt = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
        $mimesOk = $ehMusica ? ['audio/mp4','audio/x-m4a','audio/mpeg','video/mp4','audio/mp3']
                             : ['image/jpeg','image/png','image/webp','image/svg+xml','text/plain','text/xml'];
        if (!in_array($mt, $mimesOk, true)) erro('O conteúdo do ficheiro não corresponde ao formato.');
        if ($ext === 'svg' && !in_array($mt, ['image/svg+xml','text/plain','text/xml'], true)) erro('SVG inválido.');
    }
    $dir = __DIR__ . '/assets/convite/exemplo';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // O prefixo do nome é a categoria: é dele que ela se lê ao listar a galeria.
    $nomeFich = ($ehMusica ? 'musica' : $categoria) . '-' . time() . '-' . random_int(100, 999)
              . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nomeFich")) erro('Não foi possível guardar o ficheiro.');
    // A anterior NÃO se apaga: as enviadas juntam-se à galeria desta secção, e
    // quem prepara vários modelos quer poder voltar a uma que já tinha enviado.
    // Para a tirar de vez há a ação modelo_exemplo_apagar.
    $caminho = 'assets/convite/exemplo/' . $nomeFich;
    if ($chave !== '') {
        $chaveDef = 'modelo.exemplo.' . $chave;
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id, chave, valor) VALUES (0,?,?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('ss', $chaveDef, $caminho); $st->execute();
    }
    registar($conn, 'modelo_exemplo', 'imagem para a galeria', $categoria);
    ok(['path' => $caminho, 'galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_exemplo_apagar') {
    // Tirar uma fotografia da galeria. As que o admin enviou apagam-se mesmo;
    // as da casa só se ESCONDEM — o ficheiro vem com a instalação e um deploy
    // trá-lo-ia de volta, por isso o que se guarda é a decisão de não a querer.
    // Assim também se podem repor, que é o que um apagar irreversível não dava.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $src = (string)(corpo()['src'] ?? '');
    if (str_contains($src, '..')) erro('Caminho inválido.');
    $daCasa = !str_starts_with($src, GALERIA_ENVIADAS);
    if ($daCasa) {
        $existe = false;
        foreach (galeriaExemplo() as $it) if ($it['ficheiro'] === $src) $existe = true;
        if (!$existe) erro('Essa fotografia não é da galeria.');
    } elseif (!is_file(__DIR__ . '/' . $src)) {
        erro('Essa imagem já não está cá.');
    }
    // Se estava em vigor nalguma secção, essa volta ao valor de fábrica em vez
    // de ficar a apontar para uma fotografia que já não se vê.
    foreach (exemploModelo($conn) as $k => $v) {
        if ($v !== $src) continue;
        $chaveDef = 'modelo.exemplo.' . $k;
        $st = $conn->prepare("DELETE FROM {$P}definicoes WHERE casamento_id=0 AND chave=?");
        $st->bind_param('s', $chaveDef); $st->execute();
    }
    if ($daCasa) {
        $oc = galeriaOcultas($conn); $oc[] = $src; guardarGaleriaOcultas($conn, $oc);
    } else {
        @unlink(__DIR__ . '/' . $src);
    }
    registar($conn, 'modelo_exemplo', $daCasa ? 'fotografia da casa escondida' : 'fotografia apagada',
             basename($src));
    ok(['galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn),
        'ocultas' => count(galeriaOcultas($conn))]);
}

if ($acao === 'modelo_exemplo_repor') {
    // Trazer de volta as fotografias da casa que foram escondidas.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $quantas = count(galeriaOcultas($conn));
    if (!$quantas) erro('Não há nenhuma escondida.');
    guardarGaleriaOcultas($conn, []);
    registar($conn, 'modelo_exemplo', 'galeria da casa reposta', $quantas . ' fotografia(s)');
    ok(['galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn), 'ocultas' => 0]);
}

if ($acao === 'modelo_exemplo_categoria') {
    // Mudar a categoria de uma imagem enviada. A categoria vive no prefixo do
    // nome, por isso mudá-la é mudar o ficheiro de nome — e quem estivesse a
    // apontar para o nome antigo passa a apontar para o novo.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita a galeria.');
    $d = corpo();
    $src = (string)($d['src'] ?? '');
    $cat = (string)($d['categoria'] ?? '');
    if (!isset(categoriasGaleria()[$cat])) erro('Categoria inválida.');
    if (!str_starts_with($src, GALERIA_ENVIADAS) || str_contains($src, '..')) {
        erro('As imagens da casa já vêm arrumadas — só as suas se mudam de sítio.');
    }
    $abs = __DIR__ . '/' . $src;
    if (!is_file($abs)) erro('Essa imagem já não está cá.');

    $resto = substr(basename($src), strpos(basename($src), '-') + 1);
    $novoNome = $cat . '-' . $resto;
    $novoSrc = GALERIA_ENVIADAS . $novoNome;
    if ($novoSrc !== $src) {
        if (!@rename($abs, __DIR__ . '/' . $novoSrc)) erro('Não foi possível mudar a categoria.');
        $st = $conn->prepare("UPDATE {$P}definicoes SET valor=? WHERE casamento_id=0 AND valor=?");
        $st->bind_param('ss', $novoSrc, $src); $st->execute();
    }
    registar($conn, 'modelo_exemplo', 'categoria da imagem', $cat);
    ok(['src' => $novoSrc, 'galeria' => galeriaCompleta($conn), 'exemplo' => exemploModelo($conn)]);
}

if ($acao === 'modelo_criar') {
    // Nasce do que está em vigor no casamento aberto: preparar um modelo é
    // desenhar o convite como se fosse para alguém, e depois guardá-lo aqui.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma faz modelos.');
    $d = corpo();
    $ambito = isset(ambitosVersao()[$d['ambito'] ?? '']) ? $d['ambito'] : 'digital';
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 120);
    if ($nome === '') erro('Dê um nome ao modelo.');
    $descricao = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 400);

    // Ou os valores que vieram no pedido (importação), ou o retrato do que o
    // casamento aberto mostra agora.
    if (is_array($d['defs'] ?? null)) {
        $permitidas = array_flip(chavesModelo($ambito));
        $defs = [];
        foreach ($d['defs'] as $k => $v) if (isset($permitidas[$k]) && is_string($v)) $defs[$k] = $v;
    } elseif (casamentoAtual() > 0 && empty($d['do_zero'])) {
        // Do que o casamento aberto mostra agora — é assim que se guarda um
        // convite que se acabou de desenhar para alguém. A IDENTIDADE, essa,
        // fica de exemplo: um modelo da casa não é o retrato de um casal.
        $defs = instantaneoModelo($conn, $ambito);
    } else {
        // Sem casamento aberto, o modelo nasce do desenho de origem e desenha-se
        // no editor a seguir. Obrigar a abrir a casa de um casal para fazer um
        // modelo da CASA era pedir emprestado o que não é preciso.
        //
        // Mas o desenho de origem é o do PRIMEIRO casal: sem esta troca, um
        // modelo feito do zero nascia com o nome e as fotografias dele — o
        // mesmo problema, pela porta do lado.
        $defs = comIdentidadeDeExemplo($conn, $ambito, padraoAmbito($ambito));
    }
    if (!$defs) erro('Não há nada para guardar neste modelo.');

    $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                          VALUES (?,?,?,?,?,?)");
    $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
    $vis = empty($d['visivel']) ? 0 : 1;
    $quem = utilizadorAtual() ?? '';
    $st->bind_param('ssssis', $nome, $descricao, $ambito, $j, $vis, $quem);
    if (!$st->execute()) erro('Não foi possível guardar o modelo.');
    // O número do modelo lê-se JÁ: registar() escreve uma linha no histórico, e
    // a partir daí insert_id é o dessa linha — devolvia-se um número que não é
    // de modelo nenhum, e o modelo acabado de criar ficava inalcançável.
    $novoId = $conn->insert_id;
    registar($conn, 'modelo_criado', $nome, $ambito . ' · ' . count($defs) . ' definição(ões)');
    ok(['id' => $novoId, 'nome' => $nome, 'ambito' => $ambito, 'definicoes' => count($defs)]);
}

if ($acao === 'modelo_editar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma edita modelos.');
    $d = corpo();
    $id = (int)($d['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $nome = mb_substr(trim((string)($d['nome'] ?? $m['nome'])), 0, 120);
    if ($nome === '') erro('Dê um nome ao modelo.');
    $descricao = mb_substr(trim((string)($d['descricao'] ?? '')), 0, 400);
    $vis = empty($d['visivel']) ? 0 : 1;

    // "Recapturar" é o que torna isto trabalhável: abre-se um casamento, mexe-se
    // no convite até ficar bem, e traz-se o resultado para o modelo.
    if (!empty($d['recapturar'])) {
        if (casamentoAtual() <= 0) erro('Abra um casamento para trazer de lá o desenho.');
        $defs = instantaneoModelo($conn, $m['ambito']);   // sem a identidade do casal
        $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("UPDATE {$P}modelos SET nome=?, descricao=?, visivel=?, defs=?,
                                     atualizado_em=NOW() WHERE id=?");
        $st->bind_param('ssisi', $nome, $descricao, $vis, $j, $id);
    } else {
        $st = $conn->prepare("UPDATE {$P}modelos SET nome=?, descricao=?, visivel=?,
                                     atualizado_em=NOW() WHERE id=?");
        $st->bind_param('ssii', $nome, $descricao, $vis, $id);
    }
    if (!$st->execute()) erro('Não foi possível guardar.');
    registar($conn, 'modelo_editado', $nome, empty($d['recapturar']) ? '' : 'desenho recapturado');
    ok(['id' => $id, 'nome' => $nome]);
}

if ($acao === 'modelo_defs') {
    // O desenho de um modelo, vindo do editor. É o que faz um modelo poder ser
    // trabalhado sem se abrir a casa de um casal.
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma desenha modelos.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');

    $d = corpo();
    // Um modelo do cartão pode ainda guardar a logística (cerimónias e receção)
    // — é o que o admin desenha aqui. Não se aplica ao casal (ver modelo_aplicar).
    $permitidas = array_flip(chavesModelo($m['ambito']));
    $padrao = defsPadrao();
    $defs = []; $invalidas = [];
    foreach ((array)($d['defs'] ?? []) as $k => $v) {
        if (!isset($permitidas[$k]) || !is_string($v)) continue;
        $ok = validarDefinicao($k, $v);
        if ($ok === null) { $invalidas[] = $k; continue; }
        // Igual ao original não se guarda: o modelo fica só com o que o desenho
        // mudou, e um valor de origem que mude no futuro acompanha-o.
        if ($ok === (string)($padrao[$k] ?? '')) continue;
        $defs[$k] = $ok;
    }
    $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
    $st = $conn->prepare("UPDATE {$P}modelos SET defs=?, atualizado_em=NOW() WHERE id=?");
    $st->bind_param('si', $j, $id);
    if (!$st->execute()) erro('Não foi possível guardar o modelo.');
    registar($conn, 'modelo_desenhado', (string)$m['nome'], count($defs) . ' definição(ões)');
    ok(['gravadas' => count($defs), 'invalidas' => $invalidas]);
}

if ($acao === 'modelo_apagar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma apaga modelos.');
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');
    $st = $conn->prepare("DELETE FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) erro('Não foi possível apagar.');
    // Apagar um modelo não desfaz nada em casamento nenhum: quem o aplicou
    // ficou com uma cópia, e é dele.
    registar($conn, 'modelo_apagado', (string)$m['nome']);
    ok(['id' => $id, 'nome' => $m['nome']]);
}

if ($acao === 'modelo_aplicar') {
    // Do lado do casal: traz o desenho da casa para o seu convite.
    exigirAdminApi();
    exigirCorrecao();
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("SELECT nome, ambito, defs, visivel, alcance FROM {$P}modelos WHERE id=?");
    $st->bind_param('i', $id); $st->execute();
    $m = $st->get_result()->fetch_assoc();
    if (!$m) erro('Modelo não encontrado.');
    // Um casal só aplica o que vê: publicado, e destinado a ele (de todos, ou
    // dos escolhidos com ele entre eles). O admin aplica qualquer um.
    if (!ehAdminPlataforma()) {
        $podeVer = (int)$m['visivel'] === 1;
        if ($podeVer && $m['alcance'] === 'selecionados') {
            $st = $conn->prepare("SELECT 1 FROM {$P}modelo_casamentos
                                  WHERE modelo_id=? AND casamento_id=? LIMIT 1");
            $cid = casamentoAtual();
            $st->bind_param('ii', $id, $cid); $st->execute();
            $podeVer = (bool)$st->get_result()->fetch_row();
        }
        if (!$podeVer) erro('Esse modelo não está disponível.');
    }
    $j = json_decode((string)$m['defs'], true);
    if (!is_array($j)) erro('Esse modelo está ilegível.');

    // Só as chaves do próprio âmbito: um modelo do cartão não mexe no convite
    // digital, nem o contrário. E de propósito chavesDoAmbito, não chavesModelo:
    // a logística que o admin meteu no modelo é só para o desenhar; aplicar um
    // modelo do cartão nunca reescreve as cerimónias que o casal já marcou.
    // Só o DESENHO: um modelo não escreve o nome dos noivos, os dados do evento
    // nem as fotografias de quem o aplica. Guarda-os (a prova precisa deles),
    // mas guarda-os genéricos e nunca os impõe — a festa é de cada casal.
    $permitidas = array_flip(chavesDesenho($m['ambito']));
    $doModelo = [];
    foreach ($j as $k => $v) if (isset($permitidas[$k]) && is_string($v)) $doModelo[$k] = $v;
    // Aplicar um modelo é FICAR com ele, não misturá-lo com o que estava:
    // parte-se do desenho de origem do âmbito e põe-se o modelo por cima. O que
    // o casal tinha à mão e o modelo não traz volta à origem, em vez de ficar
    // pelo meio. Um modelo vazio — o de origem da casa — devolve a peça à
    // origem, que é o que se espera de "aplicar o modelo da casa".
    $defs = array_merge(padraoDesenho($m['ambito']), $doModelo);
    // O que a peça mostrava ANTES, para se poder dizer com verdade se mudou.
    // Sem isto, aplicar um modelo que já era o desenho em vigor recarregava a
    // página sem nada mudar — e quem o fez concluía, com razão, que não tinha
    // funcionado. 'gravadas' não serve: conta escritas, não diferenças.
    $antesDefs = instantaneoAmbito($conn, $m['ambito']);
    $r = guardarDefinicoes($conn, $defs);
    $depoisDefs = instantaneoAmbito($conn, $m['ambito']);
    $r['mudou'] = $antesDefs != $depoisDefs;
    // Deixa de haver versão em vigor: o que a peça mostra agora veio de fora.
    $st = $conn->prepare("UPDATE {$P}versoes SET predefinida=0 WHERE " . doCasamento() . " AND ambito=?");
    $st->bind_param('s', $m['ambito']); $st->execute();
    // E fica registado QUE modelo está em vigor — para a lista marcar um só, e
    // não todos os que por acaso tenham o mesmo desenho.
    marcarModeloEmVigor($conn, $m['ambito'], $id);
    registar($conn, 'modelo_aplicado', (string)$m['nome'], $r['gravadas'] . ' definição(ões)');
    ok($r + ['nome' => $m['nome'], 'ambito' => $m['ambito']]);
}

if ($acao === 'modelos_exportar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma leva os modelos.');
    $r = @$conn->query("SELECT nome, descricao, ambito, defs, visivel FROM {$P}modelos ORDER BY ambito, nome");
    $lista = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($lista as &$m) { $m['defs'] = json_decode($m['defs'], true) ?: []; }
    unset($m);
    $saida = ['formato' => 'casamento-web/modelos/1', 'esquema' => ESQUEMA_VERSAO,
              'gerado_em' => date('c'), 'gerado_por' => utilizadorAtual() ?? '',
              'modelos' => $lista];
    registar($conn, 'modelos_exportados', count($lista) . ' modelo(s)');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=modelos-' . date('Y-m-d') . '.json');
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($acao === 'modelos_importar') {
    if (!ehAdminPlataforma()) erro('Só o admin da plataforma traz modelos.');
    $d = corpo();
    $f = is_array($d['ficheiro'] ?? null) ? $d['ficheiro'] : null;
    if (!$f || ($f['formato'] ?? '') !== 'casamento-web/modelos/1') {
        erro('Este ficheiro não é uma exportação de modelos deste sistema.');
    }
    $entrou = 0; $saltou = 0;
    foreach ((array)($f['modelos'] ?? []) as $m) {
        if (!is_array($m)) { $saltou++; continue; }
        $nome = mb_substr(trim((string)($m['nome'] ?? '')), 0, 120);
        $ambito = isset(ambitosVersao()[$m['ambito'] ?? '']) ? $m['ambito'] : 'digital';
        $permitidas = array_flip(chavesModelo($ambito));
        $defs = [];
        foreach ((array)($m['defs'] ?? []) as $k => $v) {
            if (isset($permitidas[$k]) && is_string($v)) $defs[$k] = $v;
        }
        if ($nome === '' || !$defs) { $saltou++; continue; }
        $descricao = mb_substr(trim((string)($m['descricao'] ?? '')), 0, 400);
        $j = json_encode($defs, JSON_UNESCAPED_UNICODE);
        $vis = empty($m['visivel']) ? 0 : 1;
        $quem = utilizadorAtual() ?? '';
        $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                              VALUES (?,?,?,?,?,?)");
        $st->bind_param('ssssis', $nome, $descricao, $ambito, $j, $vis, $quem);
        if (@$st->execute()) $entrou++; else $saltou++;
    }
    if (!$entrou) erro('O ficheiro não trouxe modelo nenhum aproveitável.');
    registar($conn, 'modelos_importados', $entrou . ' modelo(s)');
    ok(['entraram' => $entrou, 'saltados' => $saltou]);
}

erro('Ação desconhecida.');
