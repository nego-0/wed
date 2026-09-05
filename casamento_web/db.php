<?php
// ============================================================
// db.php — Ligação, esquema e funções partilhadas
// ============================================================
require_once __DIR__ . '/config.php';

// ---- Ligação (tenta local, depois online) ------------------
// No PHP 8.1+ o mysqli lança exceções por defeito. Desligamos esse modo
// para que uma tentativa de ligação falhada devolva erro em vez de abortar
// o script (o que causaria um HTTP 500 antes de tentar a config seguinte).
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Ligação que vigia o âmbito.
 *
 * Com vários casamentos na mesma base, uma consulta esquecida sem filtro de
 * dono mostra os convidados de um casal a outro. São perto de 150 sítios a
 * tocar nas tabelas: confiar em revê-los todos a olho é confiar de mais.
 *
 * Esta ligação olha para cada instrução antes de a correr. Se ela mexe numa
 * tabela que pertence a um casamento e não menciona 'casamento_id', reclama:
 * rebenta nas provas (para a falha ser impossível de ignorar) e regista no
 * log em produção (para nunca derrubar uma página em casamento).
 */
class LigacaoAmbito extends mysqli {
    /** Tabelas cujos dados pertencem a um casamento. */
    private const TABELAS = ['convites','convidados','mesas','versoes','registo','definicoes',
                             'orcamento_categorias','orcamento_despesas','orcamento_pagamentos',
                             'lic_pedidos','lic_concessoes'];
    public static bool $vigiar = false;   // ligado só depois de o esquema estar pronto

    private function auditar(string $sql): void {
        if (!self::$vigiar) return;
        $s = ltrim($sql);
        // Só instruções de dados: o esquema e as leituras de metadados não têm
        // dono, e a própria migração corre antes de haver âmbito.
        if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $s)) return;
        $p = preg_quote(PREFIXO, '/');
        $alvo = '/\b' . $p . '(' . implode('|', self::TABELAS) . ')\b/i';
        if (!preg_match($alvo, $s, $m)) return;
        if (stripos($s, 'casamento_id') !== false) return;
        // information_schema e afins não contam.
        if (stripos($s, 'information_schema') !== false) return;
        $aviso = 'Consulta sem âmbito de casamento (tabela ' . $m[0] . '): '
               . preg_replace('/\s+/', ' ', mb_substr($s, 0, 240));
        if (defined('AMBITO_ESTRITO') && AMBITO_ESTRITO) throw new RuntimeException($aviso);
        error_log($aviso);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT) {
        $this->auditar($query);
        return parent::query($query, $result_mode);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query) {
        $this->auditar($query);
        return parent::prepare($query);
    }
}

$conn = null; $CONFIG_ATIVA = null; $ULTIMO_ERRO = '';
foreach (DB_CONFIGS as $nome => $cfg) {
    try {
        $t = @new LigacaoAmbito($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
        if ($t && !$t->connect_error) { $conn = $t; $CONFIG_ATIVA = $nome; break; }
        if ($t && $t->connect_error) { $ULTIMO_ERRO = $t->connect_error; }
    } catch (\Throwable $e) {
        $ULTIMO_ERRO = $e->getMessage();
    }
}
if (!$conn) {
    http_response_code(503);
    $msg = 'Erro de ligação à base de dados. Verifique o config.php.';
    // Diagnóstico opcional: aceda com ?diag=1 para ver o motivo técnico.
    if (isset($_GET['diag']) && $_GET['diag'] === '1' && $ULTIMO_ERRO !== '') {
        $msg .= ' [Detalhe: ' . htmlspecialchars($ULTIMO_ERRO) . ']';
    }
    die($msg);
}
$conn->set_charset('utf8mb4');

// Alinha o fuso da base de dados com o fuso local (config.php), para que
// CURRENT_TIMESTAMP/NOW() fiquem na hora local e não na do servidor.
try {
    $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    @$conn->query("SET time_zone = '$offset'");
} catch (\Throwable $e) { /* mantém o fuso do servidor se falhar */ }

$P = PREFIXO; // atalho para o prefixo

// ============================================================
// Qual o casamento em causa
//
// Todo o dado pertence a um casamento. Este é o ÚNICO sítio que responde a
// "qual?", para que não haja duas respostas diferentes no mesmo pedido — é o
// que separa um sistema de vários casais de uma fuga de dados entre eles.
//
// A resposta vem, por esta ordem:
//   1. o que o pedido já fixou (usarCasamento) — é o caso do convite público,
//      onde é o código do convidado que revela de que casamento se trata;
//   2. o casamento aberto na sessão de quem entrou;
//   3. o nº 1, para o sistema continuar a servir quem tem uma instalação de um
//      casamento só e ainda não passou por um ecrã de escolha.
// ============================================================
const CASAMENTO_SISTEMA = 0;   // definições que não são de casamento nenhum

function casamentoAtual(): int {
    if (isset($GLOBALS['CASAMENTO_ID'])) return (int)$GLOBALS['CASAMENTO_ID'];
    if (isset($_SESSION['casamento_id'])) return (int)$_SESSION['casamento_id'];
    return 1;
}

/** Fixa o casamento em causa para o resto do pedido. */
function usarCasamento(int $id): void {
    $GLOBALS['CASAMENTO_ID'] = max(1, $id);
}

/**
 * Fragmento SQL que prende uma consulta ao casamento em causa. Segue o mesmo
 * idioma do soVivos(): entra no WHERE, ao lado das outras condições.
 *
 *   WHERE " . doCasamento('c') . " AND ..."
 */
function doCasamento(string $alias = ''): string {
    $col = $alias === '' ? 'casamento_id' : "$alias.casamento_id";
    return "$col = " . casamentoAtual();
}

// ---- Esquema (tabelas novas, prefixadas) -------------------
$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}mesas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(191) NOT NULL UNIQUE,
        capacidade INT DEFAULT NULL,
        pos_x DECIMAL(6,2) DEFAULT NULL,          -- posição na planta (% horizontal, 0-100)
        pos_y DECIMAL(6,2) DEFAULT NULL,          -- posição na planta (% vertical, 0-100)
        forma VARCHAR(20) DEFAULT 'redonda',      -- redonda/oval/quadrada/retangular/comprida/ferradura
        cor VARCHAR(20) DEFAULT NULL,             -- cor da mesa (chave da paleta), NULL = marfim
        rotacao SMALLINT NOT NULL DEFAULT 0,      -- graus (0-359): como a mesa está posta no salão
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}convites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(12) NOT NULL UNIQUE,
        nome_exibicao VARCHAR(255) NOT NULL,          -- nome no convite (ex: Família Agostinho)
        sufixo VARCHAR(120) DEFAULT NULL,             -- texto opcional entre parênteses no nome (ex: e acompanhante)
        mostrar_num_mesa TINYINT(1) DEFAULT 1,        -- mostrar o nº de pessoas por mesa no convite digital
        tipo ENUM('digital','fisico','ambos') DEFAULT 'digital',
        lado ENUM('noivo','noiva','ambos') DEFAULT 'noivo',
        lugares INT NOT NULL DEFAULT 1,
        mesa_id INT DEFAULT NULL,
        telefone VARCHAR(50) DEFAULT NULL,
        impresso TINYINT(1) DEFAULT 0,                -- convite físico já impresso
        enviado TINYINT(1) DEFAULT 0,                 -- convite digital já enviado
        rsvp_estado ENUM('pendente','confirmado','recusado','parcial') DEFAULT 'pendente',
        rsvp_confirmados INT DEFAULT NULL,            -- nº que confirmou presença
        rsvp_mensagem TEXT DEFAULT NULL,
        rsvp_em TIMESTAMP NULL DEFAULT NULL,
        checkin_estado ENUM('aguardando','presente','parcial') DEFAULT 'aguardando',
        checkin_presentes INT DEFAULT 0,
        checkin_em TIMESTAMP NULL DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        msg_pessoal TEXT DEFAULT NULL,               -- mensagem pessoal mostrada no convite digital
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}convidados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        convite_id INT NOT NULL,
        nome VARCHAR(255) NOT NULL,
        principal TINYINT(1) DEFAULT 0,
        rsvp ENUM('pendente','confirmado','recusado') DEFAULT 'pendente',
        presente TINYINT(1) DEFAULT 0,
        presente_em TIMESTAMP NULL DEFAULT NULL,
        mesa_id INT DEFAULT NULL,                  -- mesa individual (opcional); senão usa a do convite
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (convite_id) REFERENCES {$P}convites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================================
// Migrações com versão
//
// Antes, cada pedido corria ~25 instruções DDL (CREATE TABLE IF NOT EXISTS,
// SHOW COLUMNS, ALTER TABLE). Em alojamento partilhado isso é latência em
// TODAS as páginas e chamadas à API. Agora guarda-se a versão do esquema em
// cw_definicoes e só se corre o que falta.
// ============================================================
const ESQUEMA_VERSAO = 34;

/** Acrescenta uma coluna se ainda não existir (usado dentro das migrações). */
function migColuna(mysqli $c, string $tabela, string $coluna, string $def): void {
    $r = @$c->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $c->real_escape_string($coluna) . "'");
    if ($r && $r->num_rows === 0) @$c->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def");
}
/** Cria um índice se ainda não existir. */
function migIndice(mysqli $c, string $tabela, string $nome, string $colunas): void {
    $r = @$c->query("SHOW INDEX FROM `$tabela` WHERE Key_name='" . $c->real_escape_string($nome) . "'");
    if ($r && $r->num_rows === 0) @$c->query("CREATE INDEX `$nome` ON `$tabela` ($colunas)");
}
/** Larga uma coluna, se ainda existir (usado dentro das migrações). */
function migLargarColuna(mysqli $c, string $tabela, string $coluna): void {
    $r = @$c->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $c->real_escape_string($coluna) . "'");
    if ($r && $r->num_rows) @$c->query("ALTER TABLE `$tabela` DROP COLUMN `$coluna`");
}

// ============================================================
// O preçário de origem — os módulos, as suas medidas e os pacotes
//
// Uma casa nova precisa de ter o que vender no primeiro dia. Isto é o ponto de
// partida, não a lei: o admin muda nomes, medidas e preços na página das
// licenças, e é o que estiver na base que manda daí para a frente.
// ============================================================

/**
 * O ecrã que mostra cada módulo a trabalhar, na montra.
 *
 * São capturas do produto a sério (ver assets/montra/), e não desenhos: o que
 * se vende é isto. O admin pode trocá-las na página das licenças.
 */
function imagensDaMontra(): array {
    return [
        'convidados' => 'assets/montra/convidados.jpg',
        'porta'      => 'assets/montra/porta.jpg',
        'mesas'      => 'assets/montra/mesas.jpg',
        'orcamento'  => 'assets/montra/orcamento.jpg',
        'impresso'   => 'assets/montra/impresso.jpg',
        'digital'    => 'assets/montra/digital.jpg',
    ];
}

/** As chaves de módulo que o sistema conhece, e o que cada uma comanda. */
function licencaModulosTudo(): array {
    return [
        'convidados' => ['limite' => 0, 'editar' => 0, 'todos_modelos' => 0],
        // A porta é o dia do casamento: o QR, quem já entrou, quem falta. Vive
        // à parte da lista porque é outro trabalho, feito por outra pessoa (o
        // porteiro), noutro dia — e há casamentos que a querem sem mais nada.
        'porta'      => ['limite' => 0, 'editar' => 0, 'todos_modelos' => 0],
        'mesas'      => ['limite' => 0, 'editar' => 0, 'todos_modelos' => 0],
        'orcamento'  => ['limite' => 0, 'editar' => 0, 'todos_modelos' => 0],
        'impresso'   => ['limite' => 0, 'editar' => 1, 'todos_modelos' => 1],
        'digital'    => ['limite' => 0, 'editar' => 1, 'todos_modelos' => 1],
    ];
}

/** Escreve o preçário de origem — só quando ainda não há nenhum. */
function semearPrecario(mysqli $conn): void {
    global $P;
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}lic_modulos");
    if (!$r || (int)$r->fetch_row()[0] > 0) return;   // já há preçário: não se mexe

    // [chave, nome, resumo, benefício (a frase que vende), ícone, obrigatório, escalões]
    // Cada escalão: [chave, nome, resumo, preço, limite, editar, todos_modelos]
    //
    // A lista de convidados é OBRIGATÓRIA: é o coração da casa, e um plano sem
    // ela não é meio produto — é nenhum. As mesas sentam quem? A porta recebe
    // quem? O convite vai para quem? Todos os outros módulos assentam nela.
    $catalogo = [
        ['convidados', 'Lista de convidados',
         'Convites, acompanhantes e confirmações de presença.',
         'Saiba quem vem, sem contar nomes numa folha.', '👤', 1, [
            ['convidados_80',  'Até 80 convidados',   'Uma festa de família.',            18000, 80,  0, 0],
            ['convidados_200', 'Até 200 convidados',  'O tamanho da maioria dos casamentos.', 32000, 200, 0, 0],
            ['convidados_400', 'Até 400 convidados',  'Casamentos grandes, com folga.',   48000, 400, 0, 0],
            ['convidados_sem', 'Convidados sem limite', 'Não conte pessoas. Convide.',    65000, 0,   0, 0],
         ]],
        ['porta', 'Controlo à porta',
         'O posto do porteiro: lê o QR e marca quem entrou.',
         'Ninguém entra a mais, ninguém fica à porta por engano.', '🎟️', 0, [
            ['porta_sim', 'Controlo à porta', 'Leitor de QR, entradas ao minuto e quem falta.', 20000, 0, 0, 0],
         ]],
        ['mesas', 'Planta de mesas',
         'Desenhe o salão e sente cada convidado no seu lugar.',
         'Acabe com a folha de papel riscada mil vezes.', '🪑', 0, [
            ['mesas_sim', 'Planta de mesas', 'Mesas, lugares e a planta a arrastar.', 25000, 0, 0, 0],
         ]],
        ['orcamento', 'Orçamento',
         'Categorias, despesas, prestações e faturas num só sítio.',
         'Saiba para onde foi cada kwanza — antes da conta chegar.', '💰', 0, [
            ['orcamento_sim', 'Orçamento', 'Teto, despesas, pagamentos e faturas.', 22000, 0, 0, 0],
         ]],
        ['impresso', 'Convite impresso',
         'O convite em papel, pronto para a gráfica.',
         'Leve à gráfica um ficheiro que já está certo.', '✉️', 0, [
            ['impresso_padrao',  'Modelo padrão',        'O desenho da casa, pronto a usar.',        12000, 0, 0, 0],
            ['impresso_edicao',  'Padrão, com edição',   'O modelo padrão, seu para desenhar.',      28000, 0, 1, 0],
            ['impresso_atelier', 'Todos os modelos',     'A galeria inteira, e o editor sem limites.', 45000, 0, 1, 1],
         ]],
        ['digital', 'Convite digital',
         'A página do convite, com RSVP e código por convidado.',
         'Envie por WhatsApp e receba as respostas sozinho.', '📱', 0, [
            ['digital_padrao',  'Modelo padrão',       'O desenho da casa, pronto a enviar.',       12000, 0, 0, 0],
            ['digital_edicao',  'Padrão, com edição',  'O modelo padrão, seu para desenhar.',       28000, 0, 1, 0],
            ['digital_atelier', 'Todos os modelos',    'A galeria inteira, e o editor sem limites.', 45000, 0, 1, 1],
         ]],
    ];

    $esc = [];   // chave do escalão => id, para montar os pacotes a seguir
    $om = 0;
    foreach ($catalogo as [$chave, $nome, $resumo, $beneficio, $icone, $obrig, $escaloes]) {
        $om += 10;
        $img = imagensDaMontra()[$chave] ?? '';
        $st = $conn->prepare("INSERT INTO {$P}lic_modulos
                              (chave,nome,resumo,beneficio,icone,ordem,imagem,obrigatorio)
                              VALUES (?,?,?,?,?,?,?,?)");
        if (!$st) return;
        $st->bind_param('sssssisi', $chave, $nome, $resumo, $beneficio, $icone, $om, $img, $obrig);
        if (!@$st->execute()) continue;
        $mid = $conn->insert_id;
        $oe = 0;
        foreach ($escaloes as [$ec, $en, $er, $ep, $el, $ed, $et]) {
            $oe += 10;
            $st = $conn->prepare("INSERT INTO {$P}lic_escaloes
                (modulo_id,chave,nome,resumo,preco,limite,editar,todos_modelos,ordem)
                VALUES (?,?,?,?,?,?,?,?,?)");
            if (!$st) continue;
            $st->bind_param('isssdiiii', $mid, $ec, $en, $er, $ep, $el, $ed, $et, $oe);
            if (@$st->execute()) $esc[$ec] = $conn->insert_id;
        }
    }

    // Os três pacotes de origem. O do meio é o que se destaca — é o desenho
    // clássico, e é honesto: é mesmo o que serve a maioria dos casamentos.
    $pacotes = [
        ['essencial', 'Essencial', 'O necessário para convidar bem.',
         "Para quem quer o essencial bem feito: a lista de convidados sempre certa e os dois convites no desenho da casa. O controlo à porta junta-se depois, se quiser.",
         36000, 6, '', 0, 10,
         ['convidados_80', 'impresso_padrao', 'digital_padrao']],
        ['celebracao', 'Celebração', 'Do primeiro convite à porta do salão.',
         "Tudo o que se usa mesmo: convidados, planta do salão, orçamento, os dois convites seus para desenhar — e o posto do porteiro a marcar quem entra no dia.",
         112000, 12, 'O MAIS ESCOLHIDO', 1, 20,
         ['convidados_200', 'porta_sim', 'mesas_sim', 'orcamento_sim', 'impresso_edicao', 'digital_edicao']],
        ['atelier', 'Atelier', 'Sem limites, sem esperar por ninguém.',
         "Convidados sem limite, a galeria completa de modelos e o editor sem travões — para quem quer o casamento exactamente à sua maneira.",
         159000, 18, 'TUDO INCLUÍDO', 0, 30,
         ['convidados_sem', 'porta_sim', 'mesas_sim', 'orcamento_sim', 'impresso_atelier', 'digital_atelier']],
    ];
    foreach ($pacotes as [$ch, $nm, $pr, $rs, $pc, $ms, $et, $ds, $od, $itens]) {
        $st = $conn->prepare("INSERT INTO {$P}lic_pacotes
            (chave,nome,promessa,resumo,preco,meses,etiqueta,destaque,ordem)
            VALUES (?,?,?,?,?,?,?,?,?)");
        if (!$st) continue;
        $st->bind_param('ssssdisii', $ch, $nm, $pr, $rs, $pc, $ms, $et, $ds, $od);
        if (!@$st->execute()) continue;
        $pid = $conn->insert_id;
        foreach ($itens as $ic) {
            if (!isset($esc[$ic])) continue;
            @$conn->query("INSERT IGNORE INTO {$P}lic_pacote_itens (pacote_id, escalao_id)
                           VALUES ($pid, " . (int)$esc[$ic] . ")");
        }
    }
}

/**
 * Os prazos de licença de origem, e o que cada um custa em relação ao base.
 *
 * O prazo BASE é o de factor 1.000 — é a ele que se referem os preços escritos
 * no preçário. Os outros multiplicam-no. Os factores são sublineares (12 meses
 * não custa o dobro de 6): quem se compromete por mais tempo paga menos por
 * mês, e é assim que se recompensa o compromisso em vez de o penalizar.
 */
function semearPrazos(mysqli $conn): void {
    global $P;
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}lic_prazos");
    if (!$r || (int)$r->fetch_row()[0] > 0) return;

    // [meses, nome, resumo, factor, etiqueta, ordem]
    $prazos = [
        [6,  '6 meses',  'Para quem já tem data marcada e perto.',        1.000, '',                10],
        [12, '12 meses', 'Um ano inteiro — o tempo de um casamento.',     1.800, 'MELHOR ESCOLHA',  20],
        [18, '18 meses', 'Com folga para preparar tudo com calma.',       2.500, '',                30],
        [24, '24 meses', 'Dois anos, ao melhor preço por mês.',           3.000, 'MAIS ECONÓMICO',  40],
    ];
    foreach ($prazos as [$m, $n, $rs, $ft, $et, $od]) {
        $st = $conn->prepare("INSERT INTO {$P}lic_prazos (meses,nome,resumo,fator,etiqueta,ordem)
                              VALUES (?,?,?,?,?,?)");
        if (!$st) continue;
        $st->bind_param('issdsi', $m, $n, $rs, $ft, $et, $od);
        @$st->execute();
    }
}

/**
 * O atendimento das páginas públicas: quem responde, e a quê.
 *
 * Quem chega ao login ou à inscrição não tem por onde perguntar nada — e as
 * perguntas são sempre as mesmas meia dúzia. Em vez de um formulário que
 * ninguém lê e a que ninguém responde, uma caixa ao canto com as perguntas já
 * feitas e as respostas já escritas: quem quiser saber o preço sabe-o em dois
 * toques, e quem precisar mesmo de falar com alguém encontra o contacto.
 *
 * Tudo isto — o nome e a foto de quem atende, a saudação, as perguntas e os
 * contactos — é do admin, e edita-se em Casamentos → Atendimento.
 */
// A pergunta sobre falar com a equipa vive aqui em cima porque é usada em dois
// sítios: na semente de uma casa nova e na migração v33, que a leva a quem já
// cá estava. A resposta não repete os números — eles estão logo a seguir, na
// própria caixa, e escritos duas vezes ficavam a mentir mal um mudasse.
const PERGUNTA_CONTACTOS = 'Como falo com uma pessoa?';
const RESPOSTA_CONTACTOS =
    'Os contactos da nossa equipa estão aqui no fim desta caixa — telefone, WhatsApp e email, '
  . 'com o horário em que atendemos. É por aí que se fala connosco antes de haver conta nenhuma. '
  . 'Depois de entrarem, temos também a vossa área para tratar do que for do vosso casamento.';

function semearAtendimento(mysqli $conn): void {
    global $P;
    // As definições vivem no casamento 0: são da casa, não de um casal.
    $base = [
        'atendimento.ativo'     => '1',
        'atendimento.nome'      => 'Atendimento',
        'atendimento.cargo'     => 'Gestão de Convidados',
        'atendimento.foto'      => '',
        'atendimento.saudacao'  => 'Olá! Bem-vindos. Aqui respondemos às perguntas mais '
                                 . 'frequentes de quem está a pensar inscrever-se. '
                                 . 'Escolha uma abaixo — ou fale connosco pelos contactos.',
        'atendimento.telefone'  => '',
        'atendimento.whatsapp'  => '',
        'atendimento.email'     => '',
        'atendimento.horario'   => 'Segunda a sexta, das 9h às 17h',
        // O encaixe para um chat ao vivo, pronto e desligado. Enquanto o modo
        // for 'nenhum', as páginas públicas não carregam script nenhum de fora.
        'atendimento.chat_modo'   => 'nenhum',
        'atendimento.chat_script' => '',
        'atendimento.chat_rotulo' => 'Falar com uma pessoa',
    ];
    foreach ($base as $ch => $vl) {
        $st = @$conn->prepare("INSERT IGNORE INTO {$P}definicoes (casamento_id,chave,valor)
                               VALUES (0,?,?)");
        if (!$st) continue;
        $st->bind_param('ss', $ch, $vl);
        @$st->execute();
    }

    $r = @$conn->query("SELECT COUNT(*) FROM {$P}atendimento_faq");
    if (!$r || (int)$r->fetch_row()[0] > 0) return;
    // [pergunta, resposta, ordem]
    $faq = [
        ['Como funciona?',
         'Inscrevem-se aqui, escolhem o plano que querem e nós aprovamos. A partir daí têm a '
       . 'vossa área: lista de convidados, convites, planta de mesas e orçamento, tudo no mesmo '
       . 'sítio e sempre acessível.', 10],
        ['Quanto custa?',
         'Depende do que levarem e por quanto tempo. Há pacotes prontos e a possibilidade de '
       . 'montar o vosso, módulo a módulo, pagando só o que usam. Os preços estão todos na '
       . 'página de inscrição, sem letra pequena.', 20],
        ['Preciso de pagar já para me inscrever?',
         'Não. A inscrição é gratuita e fica à espera da nossa aprovação. Só depois de '
       . 'conversarmos convosco e de a licença ser concedida é que há alguma coisa a pagar.', 30],
        ['Os convidados precisam de instalar alguma coisa?',
         'Não. O convite é uma página que abre em qualquer telemóvel, e a confirmação de '
       . 'presença faz-se ali mesmo. Não há aplicação nenhuma para instalar.', 40],
        ['Posso mudar de plano depois?',
         'Sim, e paga só a diferença. O que já pagou num módulo desconta quando sobe de '
       . 'escalão — nunca se paga duas vezes pela mesma coisa.', 50],
        ['O que acontece aos nossos dados?',
         'São vossos. Podem exportá-los quando quiserem, e continuam a poder fazê-lo mesmo '
       . 'depois de a licença acabar, como as políticas de utilização prometem.', 60],
        [PERGUNTA_CONTACTOS, RESPOSTA_CONTACTOS, 70],
    ];
    foreach ($faq as [$p, $rp, $od]) {
        $st = @$conn->prepare("INSERT INTO {$P}atendimento_faq (pergunta,resposta,ordem)
                               VALUES (?,?,?)");
        if (!$st) continue;
        $st->bind_param('ssi', $p, $rp, $od);
        @$st->execute();
    }
}

/**
 * As políticas de utilização de origem, versão 1.
 *
 * O texto assenta na lei angolana que rege uma plataforma como esta: a Lei
 * n.º 22/11, de 17 de Junho (Protecção de Dados Pessoais), a Lei n.º 23/11, de
 * 20 de Junho (Comunicações Electrónicas e Serviços da Sociedade da
 * Informação) e a Lei n.º 7/17, de 16 de Fevereiro (Protecção das Redes e
 * Sistemas Informáticos). O admin edita-o na página das licenças; cada edição
 * publicada nasce como versão nova, para se saber sempre a que texto é que um
 * casal disse que sim.
 *
 * Marcação simples: «## » título, «- » alínea, linha em branco = parágrafo.
 */
function semearPoliticas(mysqli $conn): void {
    global $P;
    $r = @$conn->query("SELECT COUNT(*) FROM {$P}lic_politicas");
    if (!$r || (int)$r->fetch_row()[0] > 0) return;

    $titulo = 'Políticas de Utilização e Protecção de Dados';
    $corpo = <<<'TXT'
Ao pedir uma licença, o casal aceita as condições abaixo. O serviço é prestado
à distância, por via electrónica — é um serviço da sociedade da informação nos
termos da alínea ff) do artigo 4.º da Lei n.º 7/17, de 16 de Fevereiro, e da
Lei n.º 23/11, de 20 de Junho.

## 1. O que o casal pode fazer com a plataforma

- Usar os módulos que a sua licença inclui, no período contratado e para o seu
  próprio casamento.
- Convidar as pessoas da sua confiança (por exemplo, um porteiro) para a área
  do seu casamento, respondendo pelo que elas aí fizerem.
- Exportar, a qualquer momento, todos os seus dados, e apagá-los quando quiser.

## 2. O que não é permitido

- Partilhar as credenciais de acesso, ou ceder a licença a outro casamento.
- Aceder, ou tentar aceder, a dados de outro casal, a áreas de administração ou
  a qualquer parte do sistema que a licença não abranja.
- Contornar os limites da licença, alterar o funcionamento da plataforma, ou
  usar meios automáticos para a sobrecarregar.
- Carregar conteúdo ilícito, ofensivo, ou sobre o qual não tenha direitos.
- Usar os dados dos convidados para fins alheios ao casamento, designadamente
  publicidade. O envio de mensagens publicitárias por via electrónica exige o
  consentimento inequívoco e expresso do destinatário (artigo 19.º da Lei
  n.º 22/11).

O incumprimento destas regras permite à administração suspender ou revogar a
licença, nos termos do ponto 7. Os actos praticados contra sistemas e dados
informáticos são ainda puníveis nos termos da legislação penal, por remissão do
artigo 44.º da Lei n.º 7/17.

## 3. Os dados dos convidados

O casal é o responsável pelo tratamento dos dados dos seus convidados; a
plataforma é subcontratada e trata-os apenas por conta e segundo as instruções
do casal (artigos 5.º, alíneas i) e m), e 23.º da Lei n.º 22/11).

- Recolha apenas os dados de que precisa para o casamento — nomes, contactos e
  o necessário à recepção. Os dados devem ser pertinentes, adequados e não
  excessivos (artigo 8.º).
- Informe os seus convidados de que os dados estão a ser tratados, para quê, e
  de que podem aceder-lhes, corrigi-los, opor-se e pedir a sua eliminação
  (artigos 25.º a 28.º).
- Restrições alimentares, de saúde ou de mobilidade são dados sensíveis: só os
  registe com o consentimento inequívoco, expresso e escrito do titular
  (artigos 5.º, alínea c), 13.º e 14.º).
- Os dados são conservados enquanto a licença estiver de pé e são eliminados a
  pedido do casal, ou findo o período de conservação (artigo 11.º).

## 4. Segurança

A plataforma aplica as medidas técnicas e organizativas exigidas pelos artigos
30.º e 31.º da Lei n.º 22/11 e pelos artigos 12.º, 14.º e 19.º da Lei n.º 7/17:
senhas guardadas cifradas, separação estrita dos dados de cada casamento,
registo das acções de administração e cópias de segurança.

Cabe ao casal a parte que é sua: guardar a senha, não a partilhar, e avisar de
imediato a administração se suspeitar de um acesso indevido. Comunicações em
rede aberta não são absolutamente seguras — é o que o n.º 7 do artigo 25.º da
Lei n.º 22/11 manda dizer.

## 5. Registos de acesso

Para segurança e prova, a plataforma guarda registo das acções de gestão (quem
fez o quê e quando) e dos acessos. Estes registos são conservados pelo período
necessário e nunca são usados para outra finalidade (artigos 9.º e 11.º da Lei
n.º 22/11; artigo 20.º da Lei n.º 7/17).

## 6. Os seus direitos

O casal e os seus convidados podem, a todo o tempo, pedir à administração:
informação sobre os dados tratados, acesso, rectificação, actualização,
eliminação e oposição ao tratamento (artigos 25.º a 28.º da Lei n.º 22/11). Os
pedidos são atendidos no prazo legal de sessenta dias úteis. A área de Gestão
permite ao casal exportar e apagar todos os seus dados sem depender de ninguém.

## 7. Licença: concessão, alteração e revogação

- O pedido de licença fica pendente até ser aprovado pela administração. Até lá
  o casal entra na plataforma, mas só vê e altera o seu pedido.
- A licença é concedida pelo período contratado e abrange apenas os módulos
  pedidos, nas medidas pedidas.
- O casal pode pedir um reforço (upgrade) a qualquer momento; o pedido é
  analisado pela administração, que o pode aceitar ou recusar.
- A administração pode revogar a licença, a qualquer momento e com efeito
  imediato, em caso de incumprimento destas políticas. A revogação é
  fundamentada e comunicada ao casal, que mantém o direito de exportar os seus
  dados.
- Expirado o período contratado, o casamento é suspenso automaticamente.

## 8. Preços

Os preços apresentados são os que vigoram no momento do pedido e é esse o valor
que fica registado. Uma alteração posterior do preçário não afecta pedidos já
submetidos.

## 9. Lei aplicável

Aplica-se a lei angolana, designadamente a Lei n.º 22/11, de 17 de Junho, a Lei
n.º 23/11, de 20 de Junho, e a Lei n.º 7/17, de 16 de Fevereiro. A autoridade
de controlo em matéria de dados pessoais é a Agência de Protecção de Dados.
TXT;

    $st = @$conn->prepare("INSERT INTO {$P}lic_politicas (versao, titulo, corpo, publicada)
                           VALUES (1, ?, ?, 1)");
    if ($st) { $st->bind_param('ss', $titulo, $corpo); @$st->execute(); }
}

// A tabela de definições tem de existir antes de se poder ler a versão.
$conn->query("
    CREATE TABLE IF NOT EXISTS {$P}definicoes (
        chave VARCHAR(64) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rv = @$conn->query("SELECT valor FROM {$P}definicoes WHERE chave='schema.versao' LIMIT 1");
$versaoAtual = ($rv && $rv->num_rows) ? (int)$rv->fetch_assoc()['valor'] : 0;

if ($versaoAtual < ESQUEMA_VERSAO) {

    // ---- v1: colunas acrescentadas ao longo do desenvolvimento -----------
    if ($versaoAtual < 1) {
        migColuna($conn, "{$P}convites",   'mostrar_num_mesa', "TINYINT(1) DEFAULT 1");
        migColuna($conn, "{$P}convites",   'msg_pessoal',      "TEXT DEFAULT NULL");
        foreach (['pos_x' => "DECIMAL(6,2) DEFAULT NULL", 'pos_y' => "DECIMAL(6,2) DEFAULT NULL",
                  'forma' => "VARCHAR(20) DEFAULT 'redonda'", 'cor' => "VARCHAR(20) DEFAULT NULL",
                  'especial' => "VARCHAR(20) DEFAULT NULL", 'tamanho' => "VARCHAR(10) DEFAULT NULL"] as $col => $def) {
            migColuna($conn, "{$P}mesas", $col, $def);
        }
        foreach (['mesa_id' => "INT DEFAULT NULL", 'papel' => "VARCHAR(20) DEFAULT NULL",
                  'genero' => "VARCHAR(1) DEFAULT NULL", 'brinde' => "TINYINT(1) DEFAULT 0"] as $col => $def) {
            migColuna($conn, "{$P}convidados", $col, $def);
        }
    }

    // ---- v2: índices e integridade referencial ---------------------------
    if ($versaoAtual < 2) {
        // Colunas usadas em WHERE/JOIN que não tinham índice.
        migIndice($conn, "{$P}convites",   'idx_conv_mesa',    'mesa_id');
        migIndice($conn, "{$P}convites",   'idx_conv_estado',  'rsvp_estado');
        migIndice($conn, "{$P}convites",   'idx_conv_tipo',    'tipo');
        migIndice($conn, "{$P}convidados", 'idx_cvd_mesa',     'mesa_id');
        migIndice($conn, "{$P}convidados", 'idx_cvd_rsvp',     'rsvp');
        migIndice($conn, "{$P}convidados", 'idx_cvd_papel',    'papel');
        migIndice($conn, "{$P}convidados", 'idx_cvd_genero',   'genero, brinde');
        migIndice($conn, "{$P}mesas",      'idx_mesa_especial','especial');

        // A integridade das mesas dependia de o código se lembrar de limpar as
        // referências ao apagar. Passa a ser garantida pela base de dados.
        // (Falha em silêncio se houver dados órfãos — sem consequências.)
        $temFk = @$conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                                WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$P}convites'
                                AND CONSTRAINT_NAME='fk_conv_mesa' LIMIT 1");
        if ($temFk && $temFk->num_rows === 0) {
            @$conn->query("UPDATE {$P}convites c LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                           SET c.mesa_id=NULL WHERE c.mesa_id IS NOT NULL AND m.id IS NULL");
            @$conn->query("ALTER TABLE {$P}convites ADD CONSTRAINT fk_conv_mesa
                           FOREIGN KEY (mesa_id) REFERENCES {$P}mesas(id) ON DELETE SET NULL");
        }
        $temFk2 = @$conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                                 WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$P}convidados'
                                 AND CONSTRAINT_NAME='fk_cvd_mesa' LIMIT 1");
        if ($temFk2 && $temFk2->num_rows === 0) {
            @$conn->query("UPDATE {$P}convidados g LEFT JOIN {$P}mesas m ON g.mesa_id=m.id
                           SET g.mesa_id=NULL WHERE g.mesa_id IS NOT NULL AND m.id IS NULL");
            @$conn->query("ALTER TABLE {$P}convidados ADD CONSTRAINT fk_cvd_mesa
                           FOREIGN KEY (mesa_id) REFERENCES {$P}mesas(id) ON DELETE SET NULL");
        }

        // lado_noivos ficou sem uso quando o "papel" passou a definir as alas:
        // era sempre escrita a NULL e nunca lida. Sai do esquema.
        $rl = @$conn->query("SHOW COLUMNS FROM {$P}convidados LIKE 'lado_noivos'");
        if ($rl && $rl->num_rows) @$conn->query("ALTER TABLE {$P}convidados DROP COLUMN lado_noivos");
    }

    // ---- v3: eliminação reversível e registo de atividade ----------------
    if ($versaoAtual < 3) {
        // Eliminar um convite passa a ser reversível durante algum tempo.
        migColuna($conn, "{$P}convites", 'eliminado_em', "TIMESTAMP NULL DEFAULT NULL");
        migIndice($conn, "{$P}convites", 'idx_conv_eliminado', 'eliminado_em');

        // Quem fez o quê (útil quando admin e porteiro partilham o dispositivo).
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}registo (
                id INT AUTO_INCREMENT PRIMARY KEY,
                utilizador VARCHAR(60) DEFAULT NULL,
                papel VARCHAR(20) DEFAULT NULL,
                accao VARCHAR(40) NOT NULL,
                alvo VARCHAR(120) DEFAULT NULL,
                detalhe VARCHAR(255) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reg_data (criado_em),
                INDEX idx_reg_accao (accao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ---- v4: versões guardadas do convite -------------------------------
    if ($versaoAtual < 4) {
        // Uma fotografia das definições do convite, com nome, para se poder
        // experimentar à vontade e voltar atrás sem medo.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}versoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(80) NOT NULL,
                defs MEDIUMTEXT NOT NULL,
                utilizador VARCHAR(60) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ver_data (criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ---- v5: versões dos dois convites, com uma predefinida ---------------
    if ($versaoAtual < 5) {
        // 'ambito' separa o convite digital do cartão impresso: são peças
        // distintas e cada uma tem a sua versão em vigor.
        migColuna($conn, "{$P}versoes", 'ambito',        "VARCHAR(10) NOT NULL DEFAULT 'digital'");
        migColuna($conn, "{$P}versoes", 'predefinida',   "TINYINT(1) NOT NULL DEFAULT 0");
        migColuna($conn, "{$P}versoes", 'atualizado_em', "TIMESTAMP NULL DEFAULT NULL");
        migIndice($conn, "{$P}versoes", 'idx_ver_ambito', 'ambito, predefinida');
    }

    // ---- v6: fim do "(N)" de lugares no nome do convidado -----------------
    if ($versaoAtual < 6) {
        // A coluna que ligava/desligava o número entre parênteses deixou de ser
        // usada — o número já não entra no nome. Sai do esquema.
        migLargarColuna($conn, "{$P}convites", 'mostrar_numero');

        // Definições órfãs: as chaves que governavam o número e a nota que o
        // explicava já não existem em defsPadrao(). Sem default a que voltar,
        // uma linha guardada ficaria presa para sempre — apaga-se.
        @$conn->query("DELETE FROM {$P}definicoes
                       WHERE chave IN ('cartao.numero_no_nome','textos.nota_parenteses')");
    }

    // ---- v7: vários casamentos, vários utilizadores -----------------------
    // O sistema nasceu para um casamento só: a sua identidade estava metade no
    // config.php (constante EVENTO) e metade numa tabela de definições global.
    // Aqui abre-se para muitos — cada peça de dados passa a saber a quem
    // pertence, e as contas saem do ficheiro de configuração para a base.
    if ($versaoAtual < 7) {
        // Quem é quem. 'estado' pendente: um registo público só entra em
        // funcionamento depois de o admin o aprovar.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}casamentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(160) NOT NULL,
                noiva VARCHAR(80) DEFAULT NULL,
                noivo VARCHAR(80) DEFAULT NULL,
                data_evento DATE DEFAULT NULL,
                estado ENUM('pendente','ativo','suspenso','arquivado') NOT NULL DEFAULT 'pendente',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cas_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}utilizadores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                nome VARCHAR(120) DEFAULT NULL,
                senha_hash VARCHAR(255) NOT NULL,
                -- NULL = utilizador comum (noivos/porteiro). Só o pessoal da
                -- plataforma tem papel aqui.
                papel_plataforma ENUM('admin','suporte') DEFAULT NULL,
                estado ENUM('pendente','ativo','suspenso') NOT NULL DEFAULT 'pendente',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ultimo_acesso TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_util_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Quem entra em que casamento, e como. Um utilizador pode ser noivo de
        // um casamento e porteiro de outro.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}acessos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                utilizador_id INT NOT NULL,
                casamento_id INT NOT NULL,
                papel ENUM('noivos','porteiro') NOT NULL DEFAULT 'noivos',
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_acesso (utilizador_id, casamento_id),
                INDEX idx_acesso_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // O suporte não entra por direito próprio: entra com um código que o
        // casal gera e pode revogar, e que diz se pode só ver ou também mexer.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}suporte_codigos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                codigo VARCHAR(16) NOT NULL UNIQUE,
                pode_corrigir TINYINT(1) NOT NULL DEFAULT 0,
                criado_por INT DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expira_em DATETIME DEFAULT NULL,
                usado_por INT DEFAULT NULL,
                usado_em DATETIME NULL DEFAULT NULL,
                revogado_em DATETIME NULL DEFAULT NULL,
                INDEX idx_sup_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- cada peça de dados passa a saber de quem é ----
        foreach (['convites', 'convidados', 'mesas', 'versoes', 'registo'] as $t) {
            migColuna($conn, "{$P}$t", 'casamento_id', "INT NOT NULL DEFAULT 1");
            migIndice($conn, "{$P}$t", "idx_{$t}_cas", 'casamento_id');
        }

        // ---- o casamento que já existia passa a ser o nº 1 ----
        // Só se HÁ um casamento anterior para preservar: uma instalação de
        // casamento único a passar para multi-casamento tem convidados, mesas ou
        // convites de quem já lá estava, e esses continuam a ser o casamento nº1.
        // Uma instalação NOVA e vazia nasce sem casamento nenhum — o admin cria o
        // primeiro; não se inventa um casal de origem que ninguém pediu.
        $r = @$conn->query("SELECT COUNT(*) FROM {$P}casamentos");
        $jaHa = $r ? (int)$r->fetch_row()[0] : 0;
        $temDadosAntigos = false;
        foreach (['convites', 'convidados', 'mesas'] as $t) {
            $rc = @$conn->query("SELECT 1 FROM {$P}$t LIMIT 1");
            if ($rc && $rc->num_rows) { $temDadosAntigos = true; break; }
        }
        if ($jaHa === 0 && $temDadosAntigos) {
            $lerDef = function (string $chave, string $fallback) use ($conn, $P) {
                $st = $conn->prepare("SELECT valor FROM {$P}definicoes WHERE chave=? LIMIT 1");
                if (!$st) return $fallback;
                $st->bind_param('s', $chave); $st->execute();
                $x = $st->get_result()->fetch_assoc();
                return ($x && $x['valor'] !== '') ? $x['valor'] : $fallback;
            };
            // A identidade estava repartida: o que o utilizador já tinha
            // gravado manda; o resto vem da constante EVENTO do config.php.
            $noiva = $lerDef('casal.noiva', EVENTO['noiva']);
            $noivo = $lerDef('casal.noivo', EVENTO['noivo']);
            $data  = $lerDef('evento.data', EVENTO['data_iso']);
            $nome  = trim($noiva . ' & ' . $noivo);
            $st = $conn->prepare("INSERT INTO {$P}casamentos (id, nome, noiva, noivo, data_evento, estado)
                                  VALUES (1, ?, ?, ?, ?, 'ativo')");
            $st->bind_param('ssss', $nome, $noiva, $noivo, $data);
            @$st->execute();
        }

        // O nome da mesa era único em toda a tabela: dois casais não podiam
        // ambos ter uma "Mesa 1". Passa a ser único dentro de cada casamento.
        $ru = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='nome'");
        if ($ru && $ru->num_rows) @$conn->query("ALTER TABLE {$P}mesas DROP INDEX nome");
        $ru2 = @$conn->query("SHOW KEYS FROM {$P}mesas WHERE Key_name='uq_mesa_nome'");
        if ($ru2 && $ru2->num_rows === 0) {
            @$conn->query("ALTER TABLE {$P}mesas ADD UNIQUE KEY uq_mesa_nome (casamento_id, nome)");
        }

        // ---- as definições passam a ser por casamento ----
        // O 0 fica reservado ao sistema (schema.versao, noivos.criada): não
        // pertencem a casamento nenhum e não podem viajar com eles.
        migColuna($conn, "{$P}definicoes", 'casamento_id', "INT NOT NULL DEFAULT 1");
        @$conn->query("UPDATE {$P}definicoes SET casamento_id=0
                       WHERE chave IN ('schema.versao','noivos.criada')");
        // A chave deixa de bastar sozinha: 'tema.paleta' existe uma vez por
        // casamento. Troca-se a chave primária por (casamento, chave).
        $rk = @$conn->query("SHOW KEYS FROM {$P}definicoes WHERE Key_name='PRIMARY'");
        if ($rk && $rk->num_rows === 1) {   // ainda é só a coluna 'chave'
            @$conn->query("ALTER TABLE {$P}definicoes DROP PRIMARY KEY,
                           ADD PRIMARY KEY (casamento_id, chave)");
        }

        // ---- as contas saem do config.local.php para a base ----
        // Corre uma só vez: depois disto, gerem-se pela aplicação.
        $r = @$conn->query("SELECT COUNT(*) FROM {$P}utilizadores");
        if ($r && (int)$r->fetch_row()[0] === 0) {
            foreach (UTILIZADORES as $u) {
                if (!is_array($u) || empty($u['utilizador'])) continue;
                $nomeU = trim((string)$u['utilizador']);
                // Sem email no formato antigo: compõe-se um, que o utilizador
                // troca depois. O importante é não perder o acesso.
                $email = filter_var($nomeU, FILTER_VALIDATE_EMAIL) ? $nomeU : $nomeU . '@local';
                $hash  = !empty($u['senha_hash']) ? (string)$u['senha_hash']
                       : password_hash((string)($u['senha'] ?? bin2hex(random_bytes(8))), PASSWORD_DEFAULT);
                $ehAdmin = ($u['papel'] ?? '') === 'admin';
                $plat  = $ehAdmin ? 'admin' : null;
                $st = $conn->prepare("INSERT INTO {$P}utilizadores (email, nome, senha_hash, papel_plataforma, estado)
                                      VALUES (?,?,?,?,'ativo')");
                $st->bind_param('ssss', $email, $nomeU, $hash, $plat);
                if (!@$st->execute()) continue;
                $uid = $conn->insert_id;
                // Se o casamento nº1 existir (instalação antiga, com dados a
                // preservar), o admin fica seu dono e o porteiro seu porteiro. Numa
                // instalação nova não há casamento nenhum a que dar lugar — as
                // contas nascem sem festa, e o admin abre a primeira quando quiser.
                $r1 = @$conn->query("SELECT 1 FROM {$P}casamentos WHERE id=1 LIMIT 1");
                if ($r1 && $r1->num_rows) {
                    $papel = $ehAdmin ? 'noivos' : 'porteiro';
                    $st = $conn->prepare("INSERT IGNORE INTO {$P}acessos (utilizador_id, casamento_id, papel)
                                          VALUES (?, 1, ?)");
                    $st->bind_param('is', $uid, $papel);
                    @$st->execute();
                }
            }
        }
    }

    // ---- v8: o endereço público de cada casamento -------------------------
    // Os QR e os links dos convites são absolutos e, uma vez impressos, são
    // para sempre. Até aqui saíam do pedido em curso (base_url()): quem
    // preparasse os cartões a partir de um endereço de testes levava para a
    // gráfica um QR que aponta para uma máquina que o convidado nunca alcança.
    //
    // Fica ao lado do casamento, e não nas definições, de propósito: as
    // definições viajam nas versões do convite, e repor uma versão antiga não
    // pode mudar o endereço para onde apontam convites já entregues.
    if ($versaoAtual < 8) {
        migColuna($conn, "{$P}casamentos", 'endereco_publico', "VARCHAR(200) NOT NULL DEFAULT ''");
    }

    // ---- v9: o admin da plataforma não é nenhum dos casais ----------------
    // A v7 trouxe o 'admin' do config.local.php para a base e deu-lhe, além do
    // papel de plataforma, um lugar de NOIVOS no casamento nº1 — porque nesse
    // mundo de um casamento só ele era, de facto, o casal.
    //
    // Com a casa a servir vários, esse lugar passou a mentir: punha quem
    // responde pela plataforma dentro da equipa de um casal, na lista de quem
    // gere aquele casamento, como se fosse da família.
    //
    // Tira-se o lugar, não o acesso: o admin continua a chegar a todos os
    // casamentos por ser quem é (ver casamentosDoUtilizador), e a diferença é
    // que o sistema passa a dizer com que título lá entra. Se um casamento
    // precisar mesmo de uma conta de noivos, dá-se-lhe uma em Gestão — e a
    // página avisa quando não há nenhuma.
    if ($versaoAtual < 9) {
        @$conn->query("DELETE a FROM {$P}acessos a
                       JOIN {$P}utilizadores u ON u.id = a.utilizador_id
                       WHERE u.papel_plataforma = 'admin'");
    }

    // ---- v10: contas paradas por o casamento ter sido arquivado -----------
    // 'suspenso' é uma decisão sobre a PESSOA — alguém decidiu fechar-lhe a
    // porta. Uma conta que para porque o casamento acabou não é a mesma coisa,
    // e tratá-las pelo mesmo nome tirava a única forma de as distinguir depois:
    // ao reabrir um casamento, quem se reativa é quem parou com ele, e não
    // quem foi suspenso por outra razão qualquer.
    if ($versaoAtual < 10) {
        @$conn->query("ALTER TABLE {$P}utilizadores
                       MODIFY estado ENUM('pendente','ativo','suspenso','inativo')
                       NOT NULL DEFAULT 'pendente'");
    }

    // ---- v11: a hora da cerimónia é do evento, não do cartão --------------
    // Estava em 'cartao.civil_hora', e por isso viajava nas versões do cartão
    // impresso: repor um desenho antigo mudava a HORA a que as pessoas se
    // apresentam na igreja. A hora é um facto do casamento; o desenho lê-a.
    if ($versaoAtual < 11) {
        // Copia-se em vez de renomear: a chave nova pode já existir nalgum
        // casamento, e a chave primária é (casamento, chave) — um rename cego
        // rebentava aí e deixava metade dos casamentos por migrar.
        @$conn->query("INSERT IGNORE INTO {$P}definicoes (casamento_id, chave, valor)
                       SELECT casamento_id, 'evento.civil_hora', valor
                       FROM {$P}definicoes WHERE chave='cartao.civil_hora'");
        // As versões guardadas do cartão levaram a chave antiga lá dentro; não
        // se reescrevem (são fotografias do que foi), mas a chave já não existe
        // e o instantâneo do âmbito deixa de a ver — que é o que se quer.
        @$conn->query("DELETE FROM {$P}definicoes WHERE chave='cartao.civil_hora'");
    }

    // ---- v12: quando é que se trabalhou neste casamento pela última vez ----
    // A lista da administração ordenava-se pelo número, que é a ordem por que
    // foram criados — a menos útil de todas. Quem abre a página de manhã quer
    // ver em cima aquilo em que andou ontem.
    if ($versaoAtual < 12) {
        migColuna($conn, "{$P}casamentos", 'ultimo_acesso', "DATETIME NULL DEFAULT NULL");
    }

    // ---- v13: modelos de convite da casa ----------------------------------
    // As versões (cw_versoes) são de cada casamento: o desenho que ESTE casal
    // guardou. Faltava o outro lado — os desenhos que a casa oferece a todos,
    // para um casal começar de um convite bonito em vez de uma folha em branco.
    //
    // Ficam à parte, e sem casamento_id, porque não são de casamento nenhum:
    // são da plataforma. Aplicar um modelo é copiá-lo para as definições do
    // casamento — a partir daí é dele, e mexer no modelo já não lhe toca.
    if ($versaoAtual < 13) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}modelos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(120) NOT NULL,
                descricao VARCHAR(400) DEFAULT NULL,
                ambito ENUM('digital','impresso') NOT NULL DEFAULT 'digital',
                defs MEDIUMTEXT NOT NULL,
                visivel TINYINT(1) NOT NULL DEFAULT 1,
                criado_por VARCHAR(120) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL DEFAULT NULL,
                INDEX idx_modelo_ambito (ambito, visivel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // v14 — o título da cerimónia civil passou de 'cartao.civil_titulo' para
    // 'evento.civil_titulo'. As duas peças anunciam a mesma cerimónia: com o
    // título fechado no âmbito do impresso, o convite digital não lhe podia
    // tocar e cada peça acabaria a dizer o seu. Quem já o tinha escrito
    // à mão não o perde.
    if ($versaoAtual < 14) {
        @$conn->query("UPDATE IGNORE {$P}definicoes SET chave='evento.civil_titulo'
                        WHERE chave='cartao.civil_titulo'");
        @$conn->query("DELETE FROM {$P}definicoes WHERE chave='cartao.civil_titulo'");
    }

    // v15 — os modelos de origem da casa. A lista de modelos começava vazia, e
    // o desenho que o sistema traz (o convite impresso e o digital tal como são
    // de origem) não constava dela: para o ter, o admin tinha de fazer um
    // "novo modelo do zero". Passa a estar lá desde o início, um por peça.
    //
    // defs vazio, de propósito: um modelo assenta sobre o desenho de origem e
    // guarda só o que muda — sem nada guardado, é o próprio desenho de origem,
    // e acompanha-o se ele mudar. Marcados com criado_por='sistema' para não os
    // duplicar; se o admin os apagar, é escolha dele e não voltam.
    if ($versaoAtual < 15) {
        foreach ([['impresso', 'Convite impresso (modelo da casa)'],
                  ['digital',  'Convite digital (modelo da casa)']] as [$amb, $nome]) {
            $existe = @$conn->query("SELECT 1 FROM {$P}modelos
                                     WHERE ambito='" . $conn->real_escape_string($amb) . "'
                                     AND criado_por='sistema' LIMIT 1");
            if ($existe && $existe->num_rows) continue;
            $st = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, criado_por)
                                  VALUES (?, ?, ?, '{}', 1, 'sistema')");
            $desc = 'O desenho que o sistema traz de origem — o ponto de partida da casa.';
            $st->bind_param('sss', $nome, $desc, $amb);
            @$st->execute();
        }
    }

    // v16 — visibilidade dos modelos por casamento. Até aqui um modelo era de
    // todos (visivel=1) ou de ninguém (rascunho). Passa a poder ser de alguns:
    // 'alcance' diz se um modelo publicado se vê em todos os casamentos ou só
    // nos escolhidos, e a tabela de junção diz quais.
    if ($versaoAtual < 16) {
        migColuna($conn, "{$P}modelos", 'alcance',
                  "ENUM('todos','selecionados') NOT NULL DEFAULT 'todos'");
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}modelo_casamentos (
                modelo_id INT NOT NULL,
                casamento_id INT NOT NULL,
                PRIMARY KEY (modelo_id, casamento_id),
                INDEX idx_mc_casamento (casamento_id),
                FOREIGN KEY (modelo_id) REFERENCES {$P}modelos(id) ON DELETE CASCADE,
                FOREIGN KEY (casamento_id) REFERENCES {$P}casamentos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // v19 — desfaz a v18, que mudou as fotografias de origem do convite para
    // assets/convite/casal/. A troca das imagens de origem por desenho da casa
    // passou a valer só para os modelos NOVOS (ver exemploDeFabrica), e não
    // para o produto inteiro, por isso as fotografias voltaram ao sítio de
    // sempre. Quem já tinha corrido a v18 ficou a apontar para uma pasta que já
    // não existe: as linhas com o caminho de lá saem, e cada casamento volta a
    // valer o de origem — que é exatamente o mesmo ficheiro.
    //
    // (As versões 17 e 18 não existem mais: uma reescrevia os modelos já
    // guardados, o que era o erro que esta desfaz.)
    if ($versaoAtual < 19) {
        @$conn->query("DELETE FROM {$P}definicoes
                       WHERE chave IN ('media.hero','media.historia','media.interludio','media.acesso')
                       AND valor LIKE 'assets/convite/casal/%'");
    }

    // v20 — modelos da casa que sejam MESMO outros desenhos.
    //
    // A v15 semeou dois: "Convite digital (modelo da casa)" e o impresso, ambos
    // com defs vazio. Um modelo vazio É o desenho de origem — e por isso aplicar
    // qualquer deles devolvia a peça à origem, que passa a coincidir com a
    // versão "Original". Um casal via dois modelos, escolhia um, e ficava sempre
    // com "Original em vigor". Era a queixa de "não consigo pôr outro modelo em
    // vigor", e tinha razão: não havia outro. Havia a origem, com dois nomes.
    //
    // Semeiam-se agora variações a sério, uma por paleta que o sistema já tem.
    if ($versaoAtual < 20) {
        // personalizacao.php só depende do config.php: pode entrar aqui sem
        // circularidade, e assim as paletas não se duplicam nesta migração.
        require_once __DIR__ . '/personalizacao.php';

        // Os dois de origem passam a dizer o que são. Só se o nome for ainda o
        // semeado — um nome que o admin tenha mudado é dele.
        foreach ([['digital', 'Convite digital (modelo da casa)', 'Desenho de origem · convite digital'],
                  ['impresso', 'Convite impresso (modelo da casa)', 'Desenho de origem · convite impresso']]
                 as [$amb, $velho, $novo]) {
            $st = $conn->prepare("UPDATE {$P}modelos SET nome=?,
                                  descricao='O ponto de partida da casa, e o caminho de volta: aplicá-lo devolve a peça ao desenho de origem.'
                                  WHERE nome=? AND ambito=? AND criado_por='sistema'");
            $st->bind_param('sss', $novo, $velho, $amb); @$st->execute();
        }

        $ins = $conn->prepare("INSERT INTO {$P}modelos (nome, descricao, ambito, defs, visivel, alcance, criado_por)
                               VALUES (?,?,?,?,1,'todos','sistema')");
        $jaLa = function (string $nome, string $amb) use ($conn, $P): bool {
            $st = $conn->prepare("SELECT 1 FROM {$P}modelos WHERE nome=? AND ambito=? LIMIT 1");
            $st->bind_param('ss', $nome, $amb); $st->execute();
            return (bool)$st->get_result()->fetch_row();
        };

        // ---- convite digital: uma paleta por modelo ----
        $temas = temasPredef();
        foreach ([['borgonha',  'Borgonha',   'Vinho profundo e ouro velho, para uma festa à noite.'],
                  ['meianoite', 'Meia-noite', 'Azul de fim de tarde, sóbrio e formal.'],
                  ['terracota', 'Terracota',  'Barro e areia, para uma festa ao ar livre.']]
                 as [$k, $nome, $desc]) {
            if (!isset($temas[$k]) || $jaLa($nome, 'digital')) continue;
            $paleta = [];
            foreach (TEMA_VARS_EDITAVEIS as $v) {
                if (isset($temas[$k][$v])) $paleta[$v] = strtoupper($temas[$k][$v]);
            }
            $defs = json_encode(['tema.paleta' => json_encode($paleta, JSON_UNESCAPED_SLASHES)],
                                JSON_UNESCAPED_UNICODE);
            $amb = 'digital';
            $ins->bind_param('ssss', $nome, $desc, $amb, $defs); @$ins->execute();
        }

        // ---- convite impresso: paleta e folhagem, que é o que ali se vê ----
        foreach ([['salvia',    'eucalipto', 'coracao',   'Sálvia',    'Verde acinzentado e folha de oliveira.'],
                  ['terracota', 'florido',   'losango',   'Terracota', 'Barro quente, com folhagem florida.'],
                  ['rosa',      'feto',      'comercial', 'Rosa velho','Rosa fumado e feto, para um cartão mais leve.']]
                 as [$pal, $folha, $elo, $nome, $desc]) {
            if ($jaLa($nome, 'impresso')) continue;
            $defs = json_encode(['cartao.paleta' => $pal, 'cartao.folhagem' => $folha,
                                 'cartao.elo' => $elo], JSON_UNESCAPED_UNICODE);
            $amb = 'impresso';
            $ins->bind_param('ssss', $nome, $desc, $amb, $defs); @$ins->execute();
        }
    }

    // v21 — o orçamento do casamento.
    //
    // Um módulo à parte, para os noivos verem o CURSO das despesas: quanto se
    // planeou, quanto se contratou, quanto já saiu, e o que falta pagar até ao
    // dia. Três tabelas, todas com dono (casamento_id) e vigiadas como as
    // outras — um casal nunca vê as contas de outro.
    //
    // O teto e a moeda não são tabela: vivem em cw_definicoes ('orcamento.total',
    // 'orcamento.moeda'), que já viaja no retrato do casamento. Assim o
    // orçamento inteiro entra e sai com o export/import sem tratamento especial
    // para os ajustes.
    if ($versaoAtual < 21) {
        // As gavetas do dinheiro. 'previsto' é o que se planeia gastar em cada
        // uma; a soma dá o orçamento por baixo, quando não há teto à mão.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_categorias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                nome VARCHAR(80) NOT NULL,
                previsto DECIMAL(12,2) NOT NULL DEFAULT 0,
                ordem INT NOT NULL DEFAULT 0,
                cor VARCHAR(7) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_orccat_cas (casamento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Cada compromisso, uma linha. O 'estado' tem só dois valores: previsto
        // (ainda por pagar) e pago (saiu por inteiro). Uma despesa prevista com
        // prestações já pagas conta o que dessas saiu como pago — ver
        // orcamentoResumo(). A categoria some por SET NULL: apagar uma gaveta
        // não apaga a despesa.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_despesas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                categoria_id INT DEFAULT NULL,
                descricao VARCHAR(160) NOT NULL,
                fornecedor VARCHAR(120) DEFAULT NULL,
                valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                estado ENUM('previsto','pago') NOT NULL DEFAULT 'previsto',
                nota VARCHAR(255) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_orcdesp_cas (casamento_id),
                INDEX idx_orcdesp_cat (categoria_id),
                FOREIGN KEY (categoria_id) REFERENCES {$P}orcamento_categorias(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // As saídas no tempo: sinal e prestações. É a data_prevista que ordena
        // o calendário — o \"curso\" no tempo, o que é preciso ter em caixa e
        // quando. Apagar a despesa leva as suas parcelas (CASCADE).
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}orcamento_pagamentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                despesa_id INT NOT NULL,
                valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                data_prevista DATE DEFAULT NULL,
                pago_em DATE DEFAULT NULL,
                nota VARCHAR(160) DEFAULT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_orcpag_cas (casamento_id),
                INDEX idx_orcpag_desp (despesa_id),
                FOREIGN KEY (despesa_id) REFERENCES {$P}orcamento_despesas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Um conjunto de gavetas sensato à partida, para o casal não começar
        // numa folha em branco. São editáveis e apagáveis — cada festa gasta à
        // sua maneira. Semeadas no casamento que já existe (o nº 1); os novos
        // recebem-nas ao serem criados (ver api.php casamento_criar).
        semearOrcamento($conn, 1);
    }

    // v22 — o primeiro modelo deixa de ser especial.
    //
    // Os dois modelos que o sistema semeou (v15/v20) eram "Desenho de origem"
    // com defs vazio: um caso à parte, e as suas fotografias (o convite Isabel
    // & Abednego) viviam soltas em assets/convite/, longe das dos outros
    // modelos. Passam a ser modelos normais — nome próprio (Isabel & Abednego)
    // e defs completas, como um modelo do admin. As fotografias já foram
    // realojadas na galeria (no repositório e no defsPadrao); aqui trata-se só
    // dos dados.
    if ($versaoAtual < 22) {
        require_once __DIR__ . '/personalizacao.php';

        // Um retrato completo do desenho de origem de cada peça, no mesmo
        // formato de um modelo do admin (chavesModelo). As chaves de média já
        // apontam para a galeria, porque vêm do defsPadrao novo.
        $padrao = defsPadrao();
        foreach (['digital', 'impresso'] as $amb) {
            $snap = [];
            foreach (chavesModelo($amb) as $k) $snap[$k] = (string)($padrao[$k] ?? '');
            $defs = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // Só se ainda for o modelo semeado com o nome antigo — um nome que o
            // admin já tenha mudado é dele.
            $st = $conn->prepare("UPDATE {$P}modelos SET nome='Isabel & Abednego', defs=?
                                  WHERE ambito=? AND criado_por='sistema' AND nome LIKE 'Desenho de origem%'");
            $st->bind_param('ss', $defs, $amb); @$st->execute();
        }

        // Quem tinha escolhido à mão a foto de origem fica a apontar para o
        // sítio novo, para o convite não ficar com uma imagem partida.
        $realojar = [
            'media.hero'       => ['assets/convite/hero.jpg',       'assets/convite/galeria/capa-isabel-abednego.jpg'],
            'media.historia'   => ['assets/convite/historia.jpg',   'assets/convite/galeria/historia-isabel-abednego.jpg'],
            'media.interludio' => ['assets/convite/interludio.jpg', 'assets/convite/galeria/interludio-isabel-abednego.jpg'],
            'media.acesso'     => ['assets/convite/acesso.jpg',     'assets/convite/galeria/acesso-isabel-abednego.jpg'],
        ];
        foreach ($realojar as $chave => [$velho, $novo]) {
            $st = $conn->prepare("UPDATE {$P}definicoes SET valor=? WHERE chave=? AND valor=?");
            $st->bind_param('sss', $novo, $chave, $velho); @$st->execute();
        }
    }

    // v23 — a «peça de origem» passa a ser um modelo designado.
    //
    // Antes, o ponto de regresso de cada peça achava-se pelo DESENHO (o modelo
    // da casa cujo desenho era o de fábrica). Bastava o admin retocar esse
    // modelo para o desenho deixar de bater certo e a peça voltar a chamar-se
    // «Original». Agora o admin designa qual modelo é a peça de origem
    // (modelo.pecaorigem.<âmbito>, no 0), e semeia-se com o Isabel & Abednego —
    // o que já era a origem — para nada mudar de comportamento à partida.
    if ($versaoAtual < 23) {
        foreach (['digital', 'impresso'] as $amb) {
            $chave = 'modelo.pecaorigem.' . $amb;
            // Só se ainda não houver designação — uma escolha do admin é dele.
            $st = $conn->prepare("SELECT 1 FROM {$P}definicoes WHERE casamento_id=0 AND chave=? LIMIT 1");
            $st->bind_param('s', $chave); @$st->execute();
            if ($st->get_result()->fetch_row()) continue;
            // O modelo de origem: o Isabel & Abednego semeado (criado_por='sistema').
            $st = $conn->prepare("SELECT id FROM {$P}modelos
                                  WHERE ambito=? AND criado_por='sistema' AND nome='Isabel & Abednego'
                                  ORDER BY id LIMIT 1");
            $st->bind_param('s', $amb); @$st->execute();
            $r = $st->get_result()->fetch_row();
            if (!$r) continue;
            $id = (string)(int)$r[0];
            $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,?,?)
                                  ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $st->bind_param('ss', $chave, $id); @$st->execute();
        }
    }

    // v24 — a licença de uso de cada casamento.
    //
    // Um casamento passa a ter um período de uso: quantos meses a licença dura
    // ('licenca_meses', 0 = sem limite) e a data em que expira ('licenca_ate',
    // NULL = ilimitada ou ainda por iniciar). O relógio começa quando o
    // casamento fica ativo (aprovação, ou criação já ativa) — ver casamento_estado
    // e casamento_criar. Expirada a licença, o casamento é suspenso sozinho, e
    // com ele as contas que dele dependem (ver suspenderLicencasExpiradas).
    if ($versaoAtual < 24) {
        migColuna($conn, "{$P}casamentos", 'licenca_meses', "INT NOT NULL DEFAULT 0");
        migColuna($conn, "{$P}casamentos", 'licenca_ate',   "DATE DEFAULT NULL");
        migIndice($conn, "{$P}casamentos", 'idx_cas_licenca', 'licenca_ate');
    }

    // v25 — a fatura de cada despesa (foto ou PDF).
    //
    // Uma despesa passa a poder levar anexada a sua fatura/recibo: guarda-se o
    // caminho do ficheiro (em assets/faturas/<casamento>/), e o resto — abrir,
    // trocar, apagar — trata-se na API (ver orc_despesa_fatura).
    if ($versaoAtual < 25) {
        migColuna($conn, "{$P}orcamento_despesas", 'fatura', "VARCHAR(255) DEFAULT NULL");
    }

    // v26 — o orçamento passa a ter só dois estados: previsto e pago.
    //
    // O 'contratado' desapareceu: era um meio-termo que não dizia nada de novo à
    // conta (o que importa é o que ainda falta pagar e o que já saiu). As
    // despesas que estavam contratadas voltam a previstas, e o ENUM encolhe para
    // os dois estados. As prestações já pagas de uma despesa prevista contam,
    // daqui para a frente, como pago (ver orcamentoResumo).
    if ($versaoAtual < 26) {
        @$conn->query("UPDATE {$P}orcamento_despesas SET estado='previsto' WHERE estado='contratado'");
        @$conn->query("ALTER TABLE {$P}orcamento_despesas
                       MODIFY estado ENUM('previsto','pago') NOT NULL DEFAULT 'previsto'");
    }

    // v27 — cada categoria pode guardar a sua cor. A cor continua a ser
    // sugerida automaticamente (paleta estável por categoria, no ecrã); esta
    // coluna guarda a escolha do casal quando ele a troca. NULL = a sugerida.
    if ($versaoAtual < 27) {
        migColuna($conn, "{$P}orcamento_categorias", 'cor', "VARCHAR(7) DEFAULT NULL");
    }

    // v28 — o preçário das licenças: módulos, escalões, pacotes e pedidos.
    //
    // Até aqui a licença era só um prazo (quantos meses). Passa a dizer também
    // O QUÊ: que módulos o casamento tem, e em que medida — quantos convidados
    // cabem, se a peça se pode editar, se o casal chega a todos os modelos ou
    // só ao padrão. O preçário vive na casa (sem casamento_id); o que cada
    // casamento tem concedido vive por casamento.
    //
    // Cinco tabelas de catálogo (módulos, escalões, pacotes, itens do pacote,
    // políticas) e três de circulação (pedidos, itens do pedido, concessões).
    // O pedido guarda os preços do dia: mudar o preçário amanhã não reescreve o
    // que alguém pediu ontem.
    if ($versaoAtual < 28) {
        // ---- catálogo: o que se vende ----
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_modulos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chave VARCHAR(32) NOT NULL UNIQUE,
                nome VARCHAR(80) NOT NULL,
                resumo VARCHAR(180) DEFAULT '',
                beneficio VARCHAR(180) DEFAULT '',
                icone VARCHAR(8) DEFAULT '',
                imagem VARCHAR(255) DEFAULT '',
                obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
                ordem INT NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Um escalão é uma forma de ter o módulo: «até 200 convidados», «com
        // edição e todos os modelos». O preço é do escalão, nunca do módulo —
        // é isso que permite vender o mesmo recurso em medidas diferentes.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_escaloes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                modulo_id INT NOT NULL,
                chave VARCHAR(48) NOT NULL UNIQUE,
                nome VARCHAR(80) NOT NULL,
                resumo VARCHAR(180) DEFAULT '',
                preco DECIMAL(12,2) NOT NULL DEFAULT 0,
                limite INT NOT NULL DEFAULT 0,          -- convidados; 0 = sem limite
                editar TINYINT(1) NOT NULL DEFAULT 0,   -- peças: pode desenhar?
                todos_modelos TINYINT(1) NOT NULL DEFAULT 0,
                ordem INT NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_esc_modulo (modulo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_pacotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chave VARCHAR(32) NOT NULL UNIQUE,
                nome VARCHAR(80) NOT NULL,
                promessa VARCHAR(180) DEFAULT '',
                resumo TEXT,
                preco DECIMAL(12,2) NOT NULL DEFAULT 0,
                meses INT NOT NULL DEFAULT 12,
                etiqueta VARCHAR(40) DEFAULT '',
                destaque TINYINT(1) NOT NULL DEFAULT 0,
                ordem INT NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_pacote_itens (
                pacote_id INT NOT NULL,
                escalao_id INT NOT NULL,
                PRIMARY KEY (pacote_id, escalao_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // As políticas de utilização, com versão. A versão é o que fica no
        // pedido: para saber, mais tarde, a que texto exactamente é que o casal
        // disse que sim (Lei n.º 22/11, art. 5.º a) — consentimento informado).
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_politicas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                versao INT NOT NULL UNIQUE,
                titulo VARCHAR(160) NOT NULL,
                corpo MEDIUMTEXT,
                publicada TINYINT(1) NOT NULL DEFAULT 1,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ---- circulação: o que se pede e o que se concede ----
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_pedidos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                tipo ENUM('inicial','upgrade') NOT NULL DEFAULT 'inicial',
                estado ENUM('pendente','aprovado','recusado','cancelado') NOT NULL DEFAULT 'pendente',
                pacote_id INT DEFAULT NULL,
                pacote_nome VARCHAR(80) DEFAULT '',
                meses INT NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                moeda VARCHAR(8) NOT NULL DEFAULT 'Kz',
                nota_casal TEXT,
                nota_admin TEXT,
                politica_versao INT DEFAULT NULL,
                fotos TEXT,
                aceite_em DATETIME DEFAULT NULL,
                aceite_ip VARCHAR(45) DEFAULT '',
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
                decidido_em DATETIME DEFAULT NULL,
                decidido_por INT DEFAULT NULL,
                INDEX idx_ped_cas (casamento_id, estado),
                INDEX idx_ped_estado (estado, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // O preço do dia, congelado. Sem isto, mexer no preçário reescrevia o
        // passado e o casal via mudar aquilo com que já tinha concordado.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_pedido_itens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pedido_id INT NOT NULL,
                escalao_id INT NOT NULL,
                modulo_chave VARCHAR(32) NOT NULL,
                escalao_nome VARCHAR(80) NOT NULL,
                preco DECIMAL(12,2) NOT NULL DEFAULT 0,
                credito DECIMAL(12,2) NOT NULL DEFAULT 0,
                limite INT NOT NULL DEFAULT 0,
                editar TINYINT(1) NOT NULL DEFAULT 0,
                todos_modelos TINYINT(1) NOT NULL DEFAULT 0,
                INDEX idx_pit_pedido (pedido_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // O que o casamento TEM, agora: uma linha por módulo concedido. É esta
        // a tabela que manda nas portas — nunca o pedido.
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_concessoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                casamento_id INT NOT NULL,
                modulo_chave VARCHAR(32) NOT NULL,
                escalao_id INT DEFAULT NULL,
                escalao_nome VARCHAR(80) DEFAULT '',
                limite INT NOT NULL DEFAULT 0,
                editar TINYINT(1) NOT NULL DEFAULT 0,
                todos_modelos TINYINT(1) NOT NULL DEFAULT 0,
                pedido_id INT DEFAULT NULL,
                desde DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_conc (casamento_id, modulo_chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ---- o estado da licença, no próprio casamento ----
        // 'sem' é o que um casamento criado pelo admin tem: sem pedido nenhum,
        // e por isso sem módulos — até alguém lhos dar.
        migColuna($conn, "{$P}casamentos", 'licenca_estado',
                  "ENUM('sem','pendente','ativa','revogada') NOT NULL DEFAULT 'sem'");
        migColuna($conn, "{$P}casamentos", 'licenca_pacote', "VARCHAR(80) DEFAULT ''");
        migColuna($conn, "{$P}casamentos", 'licenca_revogada_em', "DATETIME DEFAULT NULL");
        migColuna($conn, "{$P}casamentos", 'licenca_revogada_motivo', "TEXT");
        migIndice($conn, "{$P}casamentos", 'idx_cas_lic_estado', 'licenca_estado');

        semearPrecario($conn);
        semearPrazos($conn);
        semearPoliticas($conn);

        // Os casamentos que já existiam foram feitos num mundo sem módulos:
        // tiram-se-lhes as portas de baixo dos pés se ficarem com a licença
        // vazia. Dá-se-lhes tudo, sem limite — é o que já tinham.
        $r = @$conn->query("SELECT id FROM {$P}casamentos");
        if ($r) {
            while ($x = $r->fetch_row()) {
                $cid = (int)$x[0];
                @$conn->query("UPDATE {$P}casamentos SET licenca_estado='ativa',
                               licenca_pacote='Licença anterior à tabela de preços'
                               WHERE id=$cid");
                foreach (licencaModulosTudo() as $mc => $g) {
                    @$conn->query("INSERT IGNORE INTO {$P}lic_concessoes
                        (casamento_id, modulo_chave, escalao_nome, limite, editar, todos_modelos)
                        VALUES ($cid, '" . $conn->real_escape_string($mc) . "', 'Tudo incluído',
                                0, " . (int)$g['editar'] . ", " . (int)$g['todos_modelos'] . ")");
                }
            }
        }
    }

    // v29 — a montra ganha imagem, e o pedido ganha as fotos do convite.
    //
    // Duas coisas que faltavam para a inscrição contar a história toda:
    //
    // 'imagem' em cada módulo é o ecrã que o mostra a trabalhar. Um preçário que
    // descreve por palavras aquilo que se pode simplesmente MOSTRAR está a
    // pedir ao casal um acto de fé; e o que se vende aqui é, literalmente,
    // aquilo que se vê.
    //
    // 'fotos' no pedido guarda as fotografias que o casal escolheu para cada
    // secção do convite digital, ainda antes de haver casamento aberto. Importa
    // sobretudo no escalão SEM edição: aí é a única vez que ele as escolhe, e
    // essa escolha tem de viajar com o pedido até à aprovação.
    if ($versaoAtual < 29) {
        migColuna($conn, "{$P}lic_modulos", 'imagem', "VARCHAR(255) DEFAULT ''");
        migColuna($conn, "{$P}lic_pedidos", 'fotos',  "TEXT");
        // As imagens de origem, para quem já tinha o preçário instalado.
        foreach (imagensDaMontra() as $chave => $img) {
            $st = @$conn->prepare("UPDATE {$P}lic_modulos SET imagem=?
                                   WHERE chave=? AND (imagem IS NULL OR imagem='')");
            if ($st) { $st->bind_param('ss', $img, $chave); @$st->execute(); }
        }
    }

    // v30 — o módulo obrigatório, e o preço em função do prazo.
    //
    // Duas correcções ao modelo de venda:
    //
    // 'obrigatorio' marca o módulo sem o qual não há plano nenhum. A gestão de
    // convidados é o coração da casa: um casamento com planta de mesas e sem
    // lista de convidados não é meio produto, é nenhum — as mesas sentam quem?
    //
    // E o preço passa a depender do PRAZO. Um casal que precisa da plataforma
    // seis meses e outro que a quer dois anos não estavam a comprar a mesma
    // coisa, e pagavam o mesmo. Cada prazo tem o seu factor, e é o factor que
    // multiplica os preços do preçário — que passam a ser «preços do prazo
    // base». Factores sublineares, de propósito: quem se compromete por mais
    // tempo paga menos por mês, que é como se recompensa um compromisso.
    if ($versaoAtual < 30) {
        migColuna($conn, "{$P}lic_modulos", 'obrigatorio', "TINYINT(1) NOT NULL DEFAULT 0");
        @$conn->query("UPDATE {$P}lic_modulos SET obrigatorio=1 WHERE chave='convidados'");

        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}lic_prazos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                meses INT NOT NULL UNIQUE,
                nome VARCHAR(60) NOT NULL,
                resumo VARCHAR(160) DEFAULT '',
                fator DECIMAL(6,3) NOT NULL DEFAULT 1.000,
                etiqueta VARCHAR(40) DEFAULT '',
                ordem INT NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        semearPrazos($conn);
    }

    // v31 — a porta sai de dentro da lista de convidados e passa a módulo.
    //
    // Eram dois trabalhos numa só linha do preçário: fazer a lista (meses
    // antes, à secretária, pelos noivos) e receber as pessoas (uma noite, à
    // entrada, pelo porteiro). Há casamentos que querem a lista e tratam da
    // porta à mão, e há quem já tenha a lista feita noutro lado e só queira o
    // leitor. Separá-los deixa cada um pagar o que usa.
    //
    // Quem já cá estava não perde nada: a porta estava incluída no que
    // comprou, e por isso quem tem a lista de convidados recebe-a concedida.
    // Tirar-lhe uma funcionalidade que já pagou seria a leitura errada desta
    // mudança — o módulo novo é para quem vier a seguir.
    if ($versaoAtual < 31) {
        // Num reforço, subir de escalão dentro de um módulo que já se tem passa
        // a descontar o que já está pago. Guarda-se o desconto ao lado do preço
        // para o pedido se explicar sozinho: «28 000 menos os 12 000 que já
        // tinha». Sem esta coluna, o admin via só o número final e não sabia de
        // onde vinha.
        migColuna($conn, "{$P}lic_pedido_itens", 'credito',
                  "DECIMAL(12,2) NOT NULL DEFAULT 0");

        $img = imagensDaMontra()['porta'] ?? '';
        $st = @$conn->prepare("INSERT IGNORE INTO {$P}lic_modulos
                               (chave,nome,resumo,beneficio,icone,ordem,imagem,obrigatorio,ativo)
                               VALUES ('porta',?,?,?,'🎟️',15,?,0,1)");
        if ($st) {
            $nm = 'Controlo à porta';
            $rs = 'O posto do porteiro: lê o QR e marca quem entrou.';
            $bf = 'Ninguém entra a mais, ninguém fica à porta por engano.';
            $st->bind_param('ssss', $nm, $rs, $bf, $img);
            @$st->execute();
        }
        $r = @$conn->query("SELECT id FROM {$P}lic_modulos WHERE chave='porta' LIMIT 1");
        $mid = ($r && ($x = $r->fetch_row())) ? (int)$x[0] : 0;
        if ($mid > 0) {
            @$conn->query("INSERT IGNORE INTO {$P}lic_escaloes
                (modulo_id,chave,nome,resumo,preco,limite,editar,todos_modelos,ordem,ativo)
                VALUES ($mid,'porta_sim','Controlo à porta',
                        'Leitor de QR, entradas ao minuto e quem falta.',20000,0,0,0,10,1)");
        }
        // O texto de «convidados» já não promete a porta — deixaria a montra a
        // vender duas vezes a mesma coisa.
        @$conn->query("UPDATE {$P}lic_modulos
                       SET resumo='Convites, acompanhantes e confirmações de presença.',
                           beneficio='Saiba quem vem, sem contar nomes numa folha.'
                       WHERE chave='convidados'
                         AND resumo='Convites, acompanhantes, confirmações e a porta no dia.'");
        // E quem já tem a lista fica com a porta, que era o que tinha comprado.
        @$conn->query("INSERT IGNORE INTO {$P}lic_concessoes
            (casamento_id, modulo_chave, escalao_id, escalao_nome, limite, editar, todos_modelos)
            SELECT casamento_id, 'porta', NULL, 'Incluído na licença anterior', 0, 0, 0
              FROM {$P}lic_concessoes WHERE modulo_chave='convidados'");
    }

    // v32 — o atendimento das páginas públicas.
    //
    // Quem chega ao login ou à inscrição e tem uma dúvida não tem por onde a
    // pôr: fecha a página e vai-se embora, e nunca se fica a saber porquê. As
    // perguntas são sempre as mesmas meia dúzia — quanto custa, como funciona,
    // se é preciso pagar já —, por isso não é preciso ninguém do outro lado a
    // teclar: basta terem as respostas já escritas, ao alcance de um toque, e
    // os contactos para quem precise mesmo de falar com uma pessoa.
    if ($versaoAtual < 32) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS {$P}atendimento_faq (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pergunta VARCHAR(200) NOT NULL,
                resposta TEXT NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        semearAtendimento($conn);
    }

    // v33 — o encaixe para um chat ao vivo no atendimento.
    //
    // Três definições novas, todas com valor de origem que deixa tudo como
    // estava: a ferramenta em 'nenhum'. Quem já esteja na v32 recebe-as aqui;
    // o semearAtendimento é INSERT IGNORE, por isso não mexe no que já lá está.
    if ($versaoAtual < 33) {
        semearAtendimento($conn);
        // E a pergunta sobre falar com a equipa, que faltava: numa casa que já
        // tem perguntas, o semearAtendimento não lhe toca (e ainda bem — não
        // repõe o que o admin tenha apagado), por isso vem aqui à mão. Uma vez
        // só: se a apagarem depois, fica apagada.
        $pq = PERGUNTA_CONTACTOS; $rs = RESPOSTA_CONTACTOS;
        $st = @$conn->prepare("SELECT COUNT(*) FROM {$P}atendimento_faq WHERE pergunta=?");
        $jaLa = true;
        if ($st) { $st->bind_param('s', $pq); @$st->execute();
                   $jaLa = (int)($st->get_result()->fetch_row()[0] ?? 1) > 0; }
        if (!$jaLa) {
            $r = @$conn->query("SELECT COALESCE(MAX(ordem),0)+10 FROM {$P}atendimento_faq");
            $od = ($r && ($x = $r->fetch_row())) ? (int)$x[0] : 10;
            $st = @$conn->prepare("INSERT INTO {$P}atendimento_faq (pergunta,resposta,ordem)
                                   VALUES (?,?,?)");
            if ($st) { $st->bind_param('ssi', $pq, $rs, $od); @$st->execute(); }
        }
    }

    // v34 — a rotação de cada mesa.
    //
    // Um salão real não tem as mesas todas alinhadas com as paredes: uma
    // comprida encostada à parede do lado fica de pé, uma ferradura abre-se
    // para o palco. Sem rotação, a planta desenhava sempre tudo ao direito e
    // deixava de descrever o salão que se ia montar. Graus, 0 = como sempre
    // esteve — por isso a coluna nasce a zero e nada muda para quem já cá está.
    if ($versaoAtual < 34) {
        migColuna($conn, "{$P}mesas", 'rotacao', "SMALLINT NOT NULL DEFAULT 0");
    }

    // A versão do esquema é do sistema, não de um casamento: vive no 0.
    @$conn->query("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,'schema.versao','" . ESQUEMA_VERSAO . "')
                   ON DUPLICATE KEY UPDATE valor='" . ESQUEMA_VERSAO . "'");
}

// ---- Semente de DEMONSTRAÇÃO (só desenvolvimento/testes) --------------------
// A instalação de origem nasce sem casamento nenhum — é o admin que cria o
// primeiro. Mas o ambiente de desenvolvimento e a suite de provas contam com um
// casamento de trabalho (o nº1, «Isabel & Abednego», com a mesa dos noivos).
// Liga-se com 'semear_demo' => true no config.local.php; nunca no produto (o
// config.local.example.php não o traz). Idempotente: só cria se não houver
// casamento nenhum.
if (cfg_local('semear_demo', false)) {
    $rq = @$conn->query("SELECT COUNT(*) FROM {$P}casamentos");
    if ($rq && (int)$rq->fetch_row()[0] === 0) {
        $noiva = EVENTO['noiva']; $noivo = EVENTO['noivo']; $data = EVENTO['data_iso'];
        $nome  = trim($noiva . ' & ' . $noivo);
        $st = @$conn->prepare("INSERT INTO {$P}casamentos (id, nome, noiva, noivo, data_evento, estado)
                               VALUES (1, ?, ?, ?, ?, 'ativo')");
        if ($st) { $st->bind_param('ssss', $nome, $noiva, $noivo, $data); @$st->execute(); }
        // A mesa dos noivos, como qualquer casamento tem.
        @$conn->query("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y)
                       VALUES (1,'Noivos',2,'redonda','ouro','noivos',50,42)");
        // Uma conta de porteiro do casamento de demonstração (a suite conta com
        // ela; não existe no produto). Só se não houver já uma com este email.
        $rp = @$conn->query("SELECT 1 FROM {$P}utilizadores WHERE email='porteiro@local' LIMIT 1");
        if ($rp && $rp->num_rows === 0) {
            $hash = password_hash('noivos2026', PASSWORD_DEFAULT);
            $pu = @$conn->prepare("INSERT INTO {$P}utilizadores (email,nome,senha_hash,papel_plataforma,estado)
                                   VALUES ('porteiro@local','Porteiro',?,NULL,'ativo')");
            if ($pu) {
                $pu->bind_param('s', $hash);
                if (@$pu->execute()) {
                    $puid = $conn->insert_id;
                    @$conn->query("INSERT INTO {$P}acessos (utilizador_id,casamento_id,papel)
                                   VALUES ($puid,1,'porteiro')");
                }
            }
        }
        // Um convite de exemplo com convidados (sem papel), para as provas que
        // precisam de um convite a sério (código) ou de cobaias.
        $cod = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        $ci = @$conn->prepare("INSERT INTO {$P}convites (casamento_id,codigo,nome_exibicao,tipo,lado,lugares)
                               VALUES (1,?,?,'ambos','noiva',3)");
        if ($ci) {
            $nomeConv = 'Família Exemplo';
            $ci->bind_param('ss', $cod, $nomeConv); @$ci->execute();
            $convId = $conn->insert_id;
            $i = 0;
            foreach (['Convidado Um', 'Convidada Dois', 'Convidado Três'] as $nm) {
                $gi = @$conn->prepare("INSERT INTO {$P}convidados (casamento_id,convite_id,nome,principal)
                                       VALUES (1,?,?,?)");
                if ($gi) { $pr = $i === 0 ? 1 : 0; $gi->bind_param('isi', $convId, $nm, $pr); @$gi->execute(); }
                $i++;
            }
        }

        // E a licença do casamento de trabalho: tudo aberto, sem limites. Um
        // casamento de demonstração sem licença nenhuma não tem página nenhuma
        // para mostrar — e a suite de provas mede o produto, não as portas.
        @$conn->query("UPDATE {$P}casamentos SET licenca_estado='ativa',
                       licenca_pacote='Demonstração — tudo incluído' WHERE id=1");
        foreach (licencaModulosTudo() as $mc => $g) {
            @$conn->query("INSERT IGNORE INTO {$P}lic_concessoes
                (casamento_id, modulo_chave, escalao_nome, limite, editar, todos_modelos)
                VALUES (1, '" . $conn->real_escape_string($mc) . "', 'Tudo incluído',
                        0, " . (int)$g['editar'] . ", " . (int)$g['todos_modelos'] . ")");
        }
    }
}

// A partir daqui o esquema está pronto e todo o dado tem dono: a ligação passa
// a reclamar de qualquer consulta que mexa numa tabela de casamento sem dizer
// de qual. Em provas rebenta; em produção fica no log.
LigacaoAmbito::$vigiar = true;

// A mesa (especial) dos noivos existe por padrão: cria-se UMA vez (primeira utilização).
// Depois fica eliminável — não volta a ser recriada automaticamente (repõe-se no botão da planta).
$flag = $conn->query("SELECT valor FROM {$P}definicoes WHERE chave='noivos.criada' AND casamento_id=0 LIMIT 1");
if ($flag && $flag->num_rows === 0) {
    $rn = $conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND especial='noivos' LIMIT 1");
    if ($rn && $rn->num_rows === 0) {
        $nomeN = 'Noivos'; $n = 2;
        while ($conn->query("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome='" . $conn->real_escape_string($nomeN) . "'")->num_rows) $nomeN = 'Noivos ' . $n++;
        $stN = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome,capacidade,forma,cor,especial,pos_x,pos_y) VALUES (" . casamentoAtual() . ",?,2,'redonda','ouro','noivos',50,42)");
        $stN->bind_param('s', $nomeN); $stN->execute();
    }
    $conn->query("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES (0,'noivos.criada','1') ON DUPLICATE KEY UPDATE valor='1'");
}

// ============================================================
// Funções partilhadas
// ============================================================

/**
 * Regista uma ação no histórico (quem fez o quê). Nunca interrompe o fluxo:
 * se a tabela ainda não existir, ignora em silêncio.
 */
function registar(mysqli $conn, string $accao, string $alvo = '', string $detalhe = ''): void {
    global $P;
    $u = function_exists('utilizadorAtual') ? (utilizadorAtual() ?? '') : '';
    $p = function_exists('papel') ? (papel() ?? '') : '';
    $st = @$conn->prepare("INSERT INTO {$P}registo (casamento_id,utilizador,papel,accao,alvo,detalhe) VALUES (" . casamentoAtual() . ",?,?,?,?,?)");
    if (!$st) return;
    $alvo = substr($alvo, 0, 120); $detalhe = substr($detalhe, 0, 255);
    $st->bind_param('sssss', $u, $p, $accao, $alvo, $detalhe);
    @$st->execute();
}

/** Os temas que o sistema oferece (chave => rótulo). O 1.º é o padrão. */
function temasDisponiveis(): array {
    return [
        'niras'    => 'NIRAS',
        'classico' => 'Clássico',
        'azul'     => 'Azul corporativo',
        'escuro'   => 'Escuro',
    ];
}

/** Amostras de cor e uma nota de cada tema — para o seletor e as Definições. */
function temasAmostras(): array {
    return [
        'niras'    => ['cores' => ['#16283A', '#63B22B', '#F6F8F4'], 'desc' => 'Azul-noite + verde institucional.'],
        'classico' => ['cores' => ['#2C4536', '#B4864A', '#FBF8F1'], 'desc' => 'Verde-floresta, dourado e marfim.'],
        'azul'     => ['cores' => ['#123C63', '#2E86C8', '#F4F7FB'], 'desc' => 'Azul corporativo, claro.'],
        'escuro'   => ['cores' => ['#0E1B25', '#8AD24A', '#17232C'], 'desc' => 'Grafite escuro, acento verde.'],
    ];
}

/**
 * O tema escolhido para o sistema — uma definição da casa (casamento_id=0),
 * que o admin controla. Vale para toda a gente e todas as páginas. Sem escolha,
 * é o NIRAS. Lê-se uma vez por pedido.
 */
function temaSistema(): string {
    static $t = null;
    if ($t !== null) return $t;
    $t = 'niras';
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $r = @$conn->query("SELECT valor FROM " . PREFIXO . "definicoes WHERE casamento_id=0 AND chave='sistema.tema' LIMIT 1");
        if ($r && ($row = $r->fetch_row())) {
            $v = (string)$row[0];
            if (isset(temasDisponiveis()[$v])) $t = $v;
        }
    }
    return $t;
}

/**
 * Condição SQL que deixa de fora os convites postos na reciclagem.
 * Devolve "1=1" enquanto a coluna não existir (esquema por migrar), para que
 * a mesma consulta continue a funcionar numa base antiga.
 */
function soVivos(mysqli $conn, string $alias = 'c'): string {
    global $P;
    $col = $alias === '' ? 'eliminado_em' : "$alias.eliminado_em";
    return colunaExiste($conn, "{$P}convites", 'eliminado_em') ? "$col IS NULL" : '1=1';
}

/** URL base do site (funciona em local e online, para links e QR). */
function base_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return "$scheme://$host$dir";
}

/**
 * O endereço por onde os convidados deste casamento chegam.
 *
 * Se o casal (ou o pessoal da casa) tiver fixado um, é esse — e é esse que vai
 * nos QR, nos links partilhados e no PDF. Sem nada fixado, deduz-se do pedido
 * em curso, que é o que sempre se fez e serve bem quando há um só endereço.
 */
function enderecoPublico(?int $casamentoId = null): string {
    global $conn, $P;
    static $cache = [];
    $id = $casamentoId ?? casamentoAtual();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = '';
        if ($id > 0 && isset($conn)) {
            $r = @$conn->query("SELECT endereco_publico FROM {$P}casamentos WHERE id=" . (int)$id . " LIMIT 1");
            if ($r && ($x = $r->fetch_assoc())) $cache[$id] = rtrim((string)$x['endereco_publico'], '/');
        }
    }
    return $cache[$id] !== '' ? $cache[$id] : base_url();
}

/**
 * Um endereço que só existe na máquina de quem o está a ver.
 *
 * Serve para avisar antes de imprimir: um QR para 127.0.0.1 ou para a rede de
 * casa não abre no telemóvel de ninguém, e no papel já não há emenda.
 */
function enderecoSoLocal(string $url): bool {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($h === '') return true;
    return $h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.localhost')
        || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.|\[?::1)/', $h) === 1;
}

/** Aceita um endereço público escrito à mão. Devolve null se não servir. */
function limparEndereco(string $v): ?string {
    $v = trim($v);
    if ($v === '') return '';                       // vazio = voltar a deduzir do pedido
    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    $v = rtrim($v, '/');
    if (mb_strlen($v) > 200) return null;
    $p = parse_url($v);
    if (!$p || empty($p['host']) || str_contains($v, ' ')) return null;
    if (!empty($p['query']) || !empty($p['fragment'])) return null;
    return $v;
}

/** Código único e legível (sem caracteres ambíguos). */
function gerarCodigo(mysqli $conn): string {
    global $P;
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem O,0,I,1
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        // Sem âmbito de propósito: o código é a chave pública e tem de ser
        // único em todo o sistema, senão um endereço serviria dois casamentos.
        $st = $conn->prepare("SELECT id FROM {$P}convites WHERE casamento_id > 0 AND codigo=? LIMIT 1");
        $st->bind_param('s', $c); $st->execute();
        $existe = $st->get_result()->fetch_assoc();
    } while ($existe);
    return $c;
}

/**
 * Nome final do convite. Um sufixo textual (ex.: "e acompanhante") aparece
 * entre parênteses; sem ele, fica só o nome. O número de lugares nunca vai
 * para o nome — é informação de gestão, mostrada onde faz sentido (mesas).
 */
function nomeConvite(array $c): string {
    $nome = trim($c['nome_exibicao']);
    $suf  = trim((string)($c['sufixo'] ?? ''));
    return $suf !== '' ? "$nome ($suf)" : $nome;
}

/** O nome tal como aparece no convite do convidado — igual ao nome final. */
function nomeConviteVisivel(array $c): string {
    return nomeConvite($c);
}

/** Resolve/insere uma mesa pelo nome e devolve o id. */
function resolverMesa(mysqli $conn, string $nome): ?int {
    global $P;
    $nome = trim($nome);
    if ($nome === '') return null;
    $st = $conn->prepare("SELECT id FROM {$P}mesas WHERE " . doCasamento() . " AND nome=? LIMIT 1");
    $st->bind_param('s', $nome); $st->execute();
    if ($r = $st->get_result()->fetch_assoc()) return (int)$r['id'];
    $st = $conn->prepare("INSERT INTO {$P}mesas (casamento_id,nome) VALUES (" . casamentoAtual() . ",?)");
    $st->bind_param('s', $nome); $st->execute();
    return $conn->insert_id;
}

/** Recalcula APENAS o estado de entrada (check-in) a partir dos membros. Não toca no RSVP. */
function recalcularCheckin(mysqli $conn, int $conviteId, string $tsSql = 'NOW()'): void {
    global $P;
    $st = $conn->prepare("SELECT COUNT(*) tot,
                                 SUM(CASE WHEN rsvp='confirmado' THEN 1 ELSE 0 END) conf,
                                 COALESCE(SUM(presente),0) pres
                          FROM {$P}convidados WHERE " . doCasamento() . " AND convite_id=?");
    $st->bind_param('i', $conviteId); $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $tot = (int)$r['tot']; $conf = (int)$r['conf']; $pres = (int)$r['pres'];
    if ($tot === 0) return; // convites sem membros nominais são geridos diretamente
    $base = $conf > 0 ? $conf : $tot; // "presente" quando todos os confirmados entraram
    $estado = $pres === 0 ? 'aguardando' : ($pres >= $base ? 'presente' : 'parcial');
    $em = $pres > 0 ? "COALESCE(checkin_em, $tsSql)" : 'NULL';
    $st = $conn->prepare("UPDATE {$P}convites SET checkin_estado=?, checkin_presentes=?, checkin_em=$em WHERE " . doCasamento() . " AND id=?");
    $st->bind_param('sii', $estado, $pres, $conviteId); $st->execute();
}

/** Estatísticas globais para o painel. */
function estatisticas(mysqli $conn): array {
    global $P;
    // Tolerante a queries que falhem (ex.: coluna ainda por migrar) — devolve 0.
    $linha = function (string $sql) use ($conn): array {
        $r = @$conn->query($sql);
        return $r ? ($r->fetch_assoc() ?: []) : [];
    };
    $n = fn(array $a, string $k) => (int)($a[$k] ?? 0);
    $vivos = soVivos($conn, 'c');   // os da reciclagem não entram nas contas

    // ---- 1 query: tudo o que se agrega sobre a tabela de convites --------
    // (antes eram ~20 queries separadas sobre a mesma tabela)
    $c = $linha("SELECT
        COUNT(*)                                              AS convites,
        COALESCE(SUM(lugares),0)                              AS lugares,
        COALESCE(SUM(rsvp_confirmados),0)                     AS lug_confirm,
        SUM(tipo IN ('digital','ambos'))                      AS digitais,
        SUM(tipo IN ('fisico','ambos'))                       AS fisicos,
        SUM(lado IN ('noivo','ambos'))                        AS noivos,
        SUM(lado IN ('noiva','ambos'))                        AS noivas,
        SUM(impresso=1)                                       AS impressos,
        SUM(enviado=1)                                        AS enviados,
        SUM(rsvp_estado='parcial')                            AS parciais,
        COALESCE(SUM(checkin_presentes),0)                    AS presentes,
        SUM(checkin_estado IN ('presente','parcial'))         AS no_local,
        COALESCE(SUM(CASE WHEN tipo IN ('digital','ambos') THEN lugares END),0) AS pes_digitais,
        COALESCE(SUM(CASE WHEN tipo IN ('fisico','ambos')  THEN lugares END),0) AS pes_fisicos,
        COALESCE(SUM(CASE WHEN impresso=1 THEN lugares END),0)                  AS pes_impressos,
        COALESCE(SUM(CASE WHEN lado IN ('noivo','ambos') THEN lugares END),0)    AS pes_noivos,
        COALESCE(SUM(CASE WHEN lado IN ('noiva','ambos') THEN lugares END),0)    AS pes_noivas,
        COALESCE(SUM(CASE WHEN rsvp_estado='pendente' THEN lugares END),0)       AS lug_pendentes,
        COALESCE(SUM(CASE WHEN rsvp_estado='recusado' THEN lugares END),0)       AS lug_recusados,
        COALESCE(SUM(CASE WHEN rsvp_estado='parcial'
                     THEN GREATEST(CAST(lugares AS SIGNED) - COALESCE(rsvp_confirmados,0), 0) END),0) AS lug_parc_pend
        FROM {$P}convites c WHERE " . doCasamento("c") . " AND $vivos");

    // ---- 1 query: contagem de convites por estado ------------------------
    // Um convite conta para um estado se o seu rsvp_estado for esse OU se
    // tiver um integrante nesse estado (ex.: "parcial" com gente pendente).
    $e = $linha("SELECT
        SUM(c.rsvp_estado='confirmado' OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='confirmado')) AS confirmados,
        SUM(c.rsvp_estado='recusado'   OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='recusado'))   AS recusados,
        SUM(c.rsvp_estado IN ('pendente','parcial') OR EXISTS(SELECT 1 FROM {$P}convidados g WHERE g.convite_id=c.id AND g.rsvp='pendente')) AS pendentes
        FROM {$P}convites c WHERE " . doCasamento("c") . " AND $vivos");

    // ---- 1 query: tudo sobre os convidados nomeados ----------------------
    $temGen = colunaExiste($conn, "{$P}convidados", 'genero');
    $temBri = colunaExiste($conn, "{$P}convidados", 'brinde');
    // Além das pessoas, conta-se também a quantos CONVITES pertencem: os cartões
    // do painel dizem todos "N convite(s)", por isso precisam sempre deste número.
    $exprG  = $temGen ? "SUM(g.genero='m') AS masc, SUM(g.genero='f') AS fem,
                         COUNT(DISTINCT CASE WHEN g.genero='m' THEN g.convite_id END) AS conv_masc,
                         COUNT(DISTINCT CASE WHEN g.genero='f' THEN g.convite_id END) AS conv_fem"
                      : "0 AS masc, 0 AS fem, 0 AS conv_masc, 0 AS conv_fem";
    // Brindes por género: quantos recebem, homens e mulheres. Só faz sentido
    // quando existem as duas colunas; senão fica a zero.
    $exprBg = ($temBri && $temGen)
        ? "SUM(g.brinde=1 AND g.genero='m') AS brinde_m, SUM(g.brinde=1 AND g.genero='f') AS brinde_f,
           SUM(g.brinde=1 AND (g.genero IS NULL OR g.genero='')) AS brinde_sg"
        : "0 AS brinde_m, 0 AS brinde_f, 0 AS brinde_sg";
    $exprB  = $temBri ? "SUM(g.brinde=1) AS brinde,
                         COUNT(DISTINCT CASE WHEN g.brinde=1 THEN g.convite_id END) AS conv_brinde,
                         $exprBg"
                      : "0 AS brinde, 0 AS conv_brinde, $exprBg";
    $g = $linha("SELECT COUNT(*) AS convidados, $exprG, $exprB,
        SUM(g.rsvp='pendente' AND c.rsvp_estado NOT IN ('pendente','parcial')) AS pend_fora,
        SUM(g.rsvp='recusado' AND c.rsvp_estado<>'recusado')                   AS rec_fora
        FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id=c.id WHERE " . doCasamento("c") . " AND $vivos");

    $mesas = $linha("SELECT COUNT(*) AS n FROM {$P}mesas WHERE " . doCasamento() . "");

    $s = [
        'convites'    => $n($c,'convites'),   'lugares'   => $n($c,'lugares'),
        'convidados'  => $n($g,'convidados'), 'digitais'  => $n($c,'digitais'),
        'fisicos'     => $n($c,'fisicos'),    'noivos'    => $n($c,'noivos'),
        'noivas'      => $n($c,'noivas'),     'impressos' => $n($c,'impressos'),
        'enviados'    => $n($c,'enviados'),   'parciais'  => $n($c,'parciais'),
        'confirmados' => $n($e,'confirmados'),'recusados' => $n($e,'recusados'),
        'pendentes'   => $n($e,'pendentes'),  'lug_confirm' => $n($c,'lug_confirm'),
        'presentes'   => $n($c,'presentes'),  'no_local'  => $n($c,'no_local'),
        'mesas'       => $n($mesas,'n'),
        // Quantas pessoas este casal espera receber (MAX_LUGARES_TOTAL era o
        // mesmo teto para toda a gente, o que não quer dizer nada com vários).
        'capacidade'  => function_exists('defsAtuais')
                         ? (int)(defsAtuais($conn)['evento.convidados'] ?: MAX_LUGARES_TOTAL)
                         : MAX_LUGARES_TOTAL,
        'pes_digitais'  => $n($c,'pes_digitais'),  'pes_fisicos' => $n($c,'pes_fisicos'),
        'pes_impressos' => $n($c,'pes_impressos'), 'pes_noivos'  => $n($c,'pes_noivos'),
        'pes_noivas'    => $n($c,'pes_noivas'),
        'pes_masculino' => $n($g,'masc'), 'pes_feminino' => $n($g,'fem'), 'pes_brinde' => $n($g,'brinde'),
        'conv_masculino'=> $n($g,'conv_masc'), 'conv_feminino' => $n($g,'conv_fem'),
        'conv_brinde'   => $n($g,'conv_brinde'),
        'pes_brinde_m'  => $n($g,'brinde_m'), 'pes_brinde_f' => $n($g,'brinde_f'),
        'pes_brinde_sg' => $n($g,'brinde_sg'),   // recebem brinde mas sem género definido
    ];
    $s['pes_confirmados'] = $s['lug_confirm'];
    // Pessoas por confirmar: lugares dos convites pendentes + lugares por confirmar
    // dos parciais + integrantes pendentes de convites de outro estado (grupos disjuntos).
    $s['pes_pendentes'] = $n($c,'lug_pendentes') + $n($c,'lug_parc_pend') + $n($g,'pend_fora');
    $s['pes_recusados'] = $n($c,'lug_recusados') + $n($g,'rec_fora');
    return $s;
}

/**
 * Normaliza um valor de dinheiro (do ecrã ou de um ficheiro) para
 * DECIMAL(12,2) em texto. Aceita "1.234,56", "1234.56" ou "1 234,56": o último
 * separador (vírgula ou ponto) é o decimal, o outro é dos milhares.
 *
 * Vive aqui, e não na API, porque a Gestão, o registo e o próprio módulo
 * partilham-no — um valor há de sair sempre igual, venha de onde vier.
 */
function orcValor($v): string {
    $s = trim((string)$v);
    if ($s === '') return '0.00';
    $s = preg_replace('/[^\d,.]/', '', $s);
    if ($s === '') return '0.00';
    $lc = strrpos($s, ','); $ld = strrpos($s, '.');
    $dec = max($lc === false ? -1 : $lc, $ld === false ? -1 : $ld);
    if ($dec >= 0) {
        $int = preg_replace('/\D/', '', substr($s, 0, $dec));
        $frac = preg_replace('/\D/', '', substr($s, $dec + 1));
        $num = ($int === '' ? '0' : $int) . '.' . substr($frac . '00', 0, 2);
    } else {
        $num = preg_replace('/\D/', '', $s) . '.00';
    }
    $f = (float)$num;
    if ($f < 0) $f = 0.0;
    if ($f > 99999999.99) $f = 99999999.99;   // cabe em DECIMAL(12,2)
    return number_format($f, 2, '.', '');
}

/** Define (ou limpa) o teto do orçamento de um casamento. Vazio/zero = sem teto. */
function orcamentoDefinirTeto(mysqli $conn, int $cid, $valor): void {
    global $P;
    if ($cid <= 0) return;
    $t = orcValor($valor);
    if ((float)$t <= 0) {
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.total'");
    } else {
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,'orcamento.total',?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('s', $t); $st->execute();
    }
}

/** Define (ou limpa) a moeda. Vazio ou 'Kz' volta ao Kwanza (linha apagada). */
function orcamentoDefinirMoeda(mysqli $conn, int $cid, $valor): void {
    global $P;
    if ($cid <= 0) return;
    $m = mb_substr(trim((string)$valor), 0, 8);
    if ($m === '' || $m === 'Kz') {
        $conn->query("DELETE FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.moeda'");
    } else {
        $st = $conn->prepare("INSERT INTO {$P}definicoes (casamento_id,chave,valor) VALUES ($cid,'orcamento.moeda',?)
                              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        $st->bind_param('s', $m); $st->execute();
    }
}

/**
 * O orçamento nasce vazio, de propósito.
 *
 * Antes semeavam-se gavetas de origem («Local», «Alimentação»…), mas cada
 * casamento é seu: uma lista imposta obrigava o casal a apagar o que não lhe
 * servia antes de começar. Agora começa em branco e cada um cria as categorias
 * que quiser — a função fica para os pontos que a chamam não terem de saber
 * disto.
 */
function semearOrcamento(mysqli $conn, int $cid): void {
    // Sem gavetas de origem. (Ver a nota acima.)
}

/** A moeda do casamento (símbolo curto). Vazio volta ao Kwanza. */
function orcamentoMoeda(mysqli $conn): string {
    global $P;
    $st = @$conn->prepare("SELECT valor FROM {$P}definicoes WHERE casamento_id=" . casamentoAtual() . " AND chave='orcamento.moeda' LIMIT 1");
    if ($st && $st->execute() && ($x = $st->get_result()->fetch_assoc()) && trim((string)$x['valor']) !== '') {
        return mb_substr(trim((string)$x['valor']), 0, 8);
    }
    return 'Kz';
}

/**
 * O retrato do orçamento em números: o teto, o que já se comprometeu e o que
 * falta, e a repartição por gaveta. Serve a página, a pista do painel e a
 * prova — uma conta só, calculada num sítio.
 *
 * Tolerante como estatisticas(): se as tabelas ainda não migraram, devolve
 * zeros em vez de rebentar.
 */
function orcamentoResumo(mysqli $conn): array {
    global $P;
    $cid = casamentoAtual();
    $linha = function (string $sql) use ($conn): array {
        $r = @$conn->query($sql); return $r ? ($r->fetch_assoc() ?: []) : [];
    };
    $f = fn(array $a, string $k) => (float)($a[$k] ?? 0);

    // Dois estados, e a conta feita por despesa: uma despesa 'pago' saiu por
    // inteiro; numa 'previsto', o que já saiu são as suas prestações liquidadas
    // (pago_em preenchido), e o que falta é o valor menos essas. Assim as
    // prestações pagas de uma despesa prevista contam no pago e descontam do
    // previsto, sem o previsto ir a negativo (GREATEST).
    $d = $linha("SELECT
        COALESCE(SUM(d.valor),0) AS total,
        COALESCE(SUM(CASE WHEN d.estado='pago' THEN d.valor ELSE 0 END),0) AS pago_desp,
        COALESCE(SUM(CASE WHEN d.estado='previsto' THEN COALESCE(pg.pagos,0) ELSE 0 END),0) AS pago_parc,
        COALESCE(SUM(CASE WHEN d.estado='previsto'
                          THEN GREATEST(d.valor - COALESCE(pg.pagos,0), 0) ELSE 0 END),0) AS previsto_liq,
        COUNT(*) AS n
        FROM {$P}orcamento_despesas d
        LEFT JOIN (SELECT despesa_id, COALESCE(SUM(valor),0) AS pagos
                   FROM {$P}orcamento_pagamentos
                   WHERE casamento_id=$cid AND pago_em IS NOT NULL
                   GROUP BY despesa_id) pg ON pg.despesa_id = d.id
        WHERE d.casamento_id=$cid");

    // O teto, se o casal o definiu; senão fica a zero e a base passa a ser a
    // soma dos previstos das categorias.
    $teto = 0.0;
    $rt = @$conn->query("SELECT valor FROM {$P}definicoes WHERE casamento_id=$cid AND chave='orcamento.total' LIMIT 1");
    if ($rt && ($x = $rt->fetch_assoc())) $teto = max(0.0, (float)$x['valor']);

    $catPrev = $linha("SELECT COALESCE(SUM(previsto),0) AS s FROM {$P}orcamento_categorias WHERE casamento_id=$cid");
    $somaPrevisto = $f($catPrev, 's');

    $pago       = $f($d,'pago_desp') + $f($d,'pago_parc');   // o que já saiu
    $previsto   = $f($d,'previsto_liq');                     // o que ainda falta pagar
    $comprometido = $pago + $previsto;                       // tudo o que a festa vai custar
    $base = $teto > 0 ? $teto : $somaPrevisto;               // o tal "teto" efetivo

    return [
        'moeda'         => orcamentoMoeda($conn),
        'teto'          => $teto,                          // 0 = não definido
        'base'          => $base,                          // teto, ou soma dos previstos
        'categorias_previsto' => $somaPrevisto,
        'despesas'      => (int)($d['n'] ?? 0),
        'total'         => $f($d,'total'),                 // soma de todas as despesas
        'previsto'      => $previsto,                      // por pagar (previstos menos parcelas pagas)
        'pago'          => $pago,                          // pago (despesas pagas + parcelas liquidadas)
        'comprometido'  => $comprometido,
        'falta'         => $base - $comprometido,          // margem (negativo = passou)
        'acima_do_teto' => $base > 0 && $comprometido > $base,
    ];
}


/**
 * Lista de mesas com ocupação, considerando mesas individuais por convidado.
 * Ocupação de uma mesa =
 *   (nº de pessoas nomeadas cuja mesa efetiva é esta: mesa própria, senão a do convite)
 * + (lugares "sem nome" de cada convite atribuído a esta mesa: lugares − nº de nomeados).
 */
function listarMesas(mysqli $conn): array {
    global $P;
    $mesas = $conn->query("SELECT id, nome, capacidade, pos_x, pos_y, forma, cor, especial, tamanho, rotacao
                           FROM {$P}mesas WHERE " . doCasamento() . " ORDER BY (especial='noivos') DESC, nome")->fetch_all(MYSQLI_ASSOC);
    $idx = [];
    $noivosId = 0;
    foreach ($mesas as $i => $m) {
        $mesas[$i]['ocupacao'] = 0; $mesas[$i]['convites'] = 0; $idx[(int)$m['id']] = $i;
        if (($m['especial'] ?? '') === 'noivos') $noivosId = (int)$m['id'];
    }
    $presenca = []; // mesaId => [conviteId => true] (para contar convites distintos por mesa)

    // 1) Pessoas nomeadas na sua mesa efetiva. Padrinhos/madrinhas sentam-se sempre
    //    na mesa dos noivos (deteção automática pelo papel), se ela existir.
    $res = $conn->query("SELECT g.convite_id, g.papel, COALESCE(g.mesa_id, c.mesa_id) AS eff
                         FROM {$P}convidados g JOIN {$P}convites c ON g.convite_id = c.id
                         WHERE " . doCasamento('c') . " AND " . soVivos($conn, 'c'));
    while ($r = $res->fetch_assoc()) {
        $eff = $r['eff'] !== null ? (int)$r['eff'] : 0;
        if ($noivosId && in_array($r['papel'] ?? '', ['padrinho', 'madrinha'], true)) $eff = $noivosId;
        if ($eff && isset($idx[$eff])) {
            $mesas[$idx[$eff]]['ocupacao'] += 1;
            $presenca[$eff][(int)$r['convite_id']] = true;
        }
    }
    // 2) Lugares sem nome (lugares além dos membros nomeados), na mesa do convite.
    $res = $conn->query("SELECT c.id, c.mesa_id, c.lugares, COUNT(g.id) AS nomeados
                         FROM {$P}convites c LEFT JOIN {$P}convidados g ON g.convite_id = c.id
                         WHERE " . doCasamento('c') . " AND " . soVivos($conn, 'c') . "
                         GROUP BY c.id, c.mesa_id, c.lugares");
    while ($r = $res->fetch_assoc()) {
        $mid = $r['mesa_id'] !== null ? (int)$r['mesa_id'] : 0;
        if (!$mid || !isset($idx[$mid])) continue;
        $extra = max(0, (int)$r['lugares'] - (int)$r['nomeados']);
        if ($extra > 0) { $mesas[$idx[$mid]]['ocupacao'] += $extra; $presenca[$mid][(int)$r['id']] = true; }
    }
    foreach ($presenca as $mid => $set) { if (isset($idx[$mid])) $mesas[$idx[$mid]]['convites'] = count($set); }
    return $mesas;
}

/**
 * Indica se uma coluna existe numa tabela (com cache por pedido).
 * Torna o código tolerante a esquemas por migrar (uploads parciais em
 * alojamento partilhado) — evita erros 500 por "Unknown column".
 */
function colunaExiste(mysqli $conn, string $tabela, string $coluna): bool {
    static $cache = [];
    $chave = $tabela . '.' . $coluna;
    if (!array_key_exists($chave, $cache)) {
        $r = @$conn->query("SHOW COLUMNS FROM `$tabela` LIKE '" . $conn->real_escape_string($coluna) . "'");
        $cache[$chave] = ($r && $r->num_rows > 0);
    }
    return $cache[$chave];
}

/** Indica se uma mesa é a mesa (especial) dos noivos. */
function mesaEhNoivos(mysqli $conn, int $id): bool {
    global $P;
    if ($id <= 0) return false;
    $r = $conn->query("SELECT 1 FROM {$P}mesas WHERE " . doCasamento() . " AND id=$id AND especial='noivos' LIMIT 1");
    return $r && $r->num_rows > 0;
}

/**
 * Tamanho (px) do nome das mesas na planta, dentro de limites que se leem:
 * abaixo de 9px não se lê, acima de 28px o nome tapa a mesa do lado.
 */
const PLANTA_ROTULO_PADRAO = 13;
const PLANTA_ROTULO_MIN    = 9;
const PLANTA_ROTULO_MAX    = 28;
function plantaRotulo($v): int {
    return max(PLANTA_ROTULO_MIN, min(PLANTA_ROTULO_MAX, (int)$v));
}

/**
 * Dimensões guardadas do canvas da planta (largura/altura em px), definidas
 * pelo utilizador ao arrastar as bordas. NULL = automático (por defeito).
 */
function plantaConfig(mysqli $conn): array {
    global $P;
    // bloq_* travam o arrasto (mesas), o redimensionar (canvas) e o deslocar da
    // vista (scroll), contra gestos acidentais. O scroll nasce DESTRAVADO: é
    // como se chega ao que está fora do ecrã, e trancá-lo por omissão era
    // esconder metade da planta a quem tem um monitor pequeno.
    // rotulo = tamanho (px) do nome das mesas na planta. É escolha de quem
    // desenha o salão, e não consequência do tamanho da mesa: numa planta com
    // mesas de dimensões diferentes, os nomes saíam todos diferentes.
    $cfg = ['largura' => null, 'altura' => null, 'rotulo' => PLANTA_ROTULO_PADRAO,
            'bloq_mesas' => 0, 'bloq_canvas' => 0, 'bloq_scroll' => 0];
    $r = @$conn->query("SELECT chave, valor FROM {$P}definicoes
                        WHERE " . doCasamento() . "
                          AND chave IN ('planta.largura','planta.altura','planta.rotulo',
                                        'planta.bloq_mesas','planta.bloq_canvas','planta.bloq_scroll')");
    if ($r) while ($x = $r->fetch_assoc()) {
        $v = (int)$x['valor'];
        if ($x['chave'] === 'planta.largura'     && $v > 0) $cfg['largura'] = $v;
        if ($x['chave'] === 'planta.altura'      && $v > 0) $cfg['altura']  = $v;
        if ($x['chave'] === 'planta.rotulo'      && $v > 0) $cfg['rotulo']  = plantaRotulo($v);
        if ($x['chave'] === 'planta.bloq_mesas')            $cfg['bloq_mesas']  = $v === 1 ? 1 : 0;
        if ($x['chave'] === 'planta.bloq_canvas')           $cfg['bloq_canvas'] = $v === 1 ? 1 : 0;
        if ($x['chave'] === 'planta.bloq_scroll')           $cfg['bloq_scroll'] = $v === 1 ? 1 : 0;
    }
    return $cfg;
}

/**
 * Carrega um convite (por id ou código) já com os membros e o nome final.
 * Convites na reciclagem ficam invisíveis, salvo pedido expresso ($eliminados).
 */
function carregarConvite(mysqli $conn, $chave, string $por = 'id', bool $eliminados = false): ?array {
    global $P;
    $porCodigo = ($por === 'codigo' || $por === 'codigo_local');
    $col  = $porCodigo ? 'codigo' : 'id';
    $vivo = $eliminados ? '1=1' : soVivos($conn, 'c');
    // Por CÓDIGO ('codigo') é a porta pública: o convidado abre um endereço e
    // não há sessão nenhuma que diga de que casamento se trata — é o próprio
    // código que o revela. Por isso esta procura corre em todos os casamentos
    // (os códigos são únicos no sistema inteiro) e, mal o encontre, fixa o
    // âmbito para tudo o que vier a seguir no pedido. Só serve casamentos
    // ativos: um registo por aprovar, suspenso ou arquivado não tem convites
    // de pé no mundo.
    // Por CÓDIGO DA CASA ('codigo_local') é o mesmo código, mas dentro do
    // casamento aberto — é o que a porta usa, para o porteiro de um casamento
    // não ler (nem ficar a saber) o convite de outro.
    // Por ID é sempre dentro do casamento em causa: um id de outro casal não
    // pode ser alcançado escrevendo um número no endereço.
    $juncao = $por === 'codigo' ? " JOIN {$P}casamentos w ON w.id = c.casamento_id " : '';
    $ambito = $por === 'codigo' ? "c.casamento_id > 0 AND w.estado='ativo'" : doCasamento('c');
    $st = $conn->prepare("SELECT c.*, m.nome AS mesa_nome
                          FROM {$P}convites c$juncao LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                          WHERE $ambito AND c.$col=? AND $vivo LIMIT 1");
    $porCodigo ? $st->bind_param('s', $chave) : $st->bind_param('i', $chave);
    $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) return null;
    if ($por === 'codigo' && !empty($c['casamento_id'])) usarCasamento((int)$c['casamento_id']);
    $st = $conn->prepare("SELECT g.*, mg.nome AS mesa_nome, mg.especial AS mesa_especial
                          FROM {$P}convidados g LEFT JOIN {$P}mesas mg ON g.mesa_id = mg.id
                          WHERE " . doCasamento('g') . " AND g.convite_id=? ORDER BY g.principal DESC, g.nome");
    $st->bind_param('i', $c['id']); $st->execute();
    $c['membros'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    // Mesa efetiva de cada membro: a sua própria, senão a do convite.
    foreach ($c['membros'] as &$m) {
        $m['mesa_efetiva'] = $m['mesa_nome'] ?: ($c['mesa_nome'] ?: null);
    }
    unset($m);
    $c['nome_final'] = nomeConvite($c);
    return $c;
}

/**
 * Distribuição de um convite pelas mesas (considerando mesas individuais).
 * Devolve uma lista ordenada por nome de mesa: [['nome'=>..., 'n'=>nº pessoas], ...].
 * Cada pessoa nomeada conta na sua mesa efetiva (própria, senão a do convite);
 * os lugares "sem nome" (lugares além dos nomeados) contam na mesa do convite.
 */
function mesasDoConvite(mysqli $conn, array $c): array {
    global $P;
    $cid     = (int)$c['id'];
    $lugares = (int)($c['lugares'] ?? 0);
    $res = $conn->query("SELECT COALESCE(mg.nome, mc.nome) AS mesa
                         FROM {$P}convidados g
                         LEFT JOIN {$P}mesas mg ON g.mesa_id = mg.id
                         LEFT JOIN {$P}convites c ON g.convite_id = c.id
                         LEFT JOIN {$P}mesas mc ON c.mesa_id = mc.id
                         WHERE " . doCasamento('g') . " AND g.convite_id = $cid");
    $cont = []; $nomeados = 0;
    while ($r = $res->fetch_assoc()) {
        $nomeados++;
        $m = $r['mesa'];
        if ($m === null || $m === '') continue;
        $cont[$m] = ($cont[$m] ?? 0) + 1;
    }
    // Lugares sem nome -> mesa do convite
    $extra = max(0, $lugares - $nomeados);
    if ($extra > 0 && !empty($c['mesa_nome'])) {
        $cont[$c['mesa_nome']] = ($cont[$c['mesa_nome']] ?? 0) + $extra;
    }
    ksort($cont, SORT_NATURAL | SORT_FLAG_CASE);
    $out = [];
    foreach ($cont as $nome => $n) $out[] = ['nome' => $nome, 'n' => $n];
    return $out;
}

/**
 * Texto das mesas de um convite. Ex.: "A (1 lugar) e B (4 lugares)".
 * $comNumero controla se aparece o "(N lugares)" ao lado de cada mesa.
 */
function textoMesas(array $lista, bool $comNumero = true): string {
    if (!$lista) return '';
    $partes = array_map(function ($m) use ($comNumero) {
        if (!$comNumero) return $m['nome'];
        $n = (int)$m['n'];
        return $m['nome'] . ' (' . $n . ' ' . ($n === 1 ? 'lugar' : 'lugares') . ')';
    }, $lista);
    if (count($partes) === 1) return $partes[0];
    $ultima = array_pop($partes);
    return implode(', ', $partes) . ' e ' . $ultima;
}


