<?php
// ============================================================
// config.php — Configuração central do sistema (SEM segredos)
// Isabel & Abednego · Gestão de Convidados
//
// Este ficheiro pode ir para o controlo de versões (Git/GitHub):
// NÃO contém palavras-passe nem dados de ligação à base de dados.
//
// Os segredos ficam em "config.local.php", que NÃO é versionado
// (ver .gitignore). Para começar, copie o modelo:
//     cp config.local.example.php config.local.php
// e preencha os valores reais nesse ficheiro.
// ============================================================

// ---- Carregar segredos locais ------------------------------
// config.local.php devolve um array com 'db' e 'utilizadores'.
$__localFile = __DIR__ . '/config.local.php';
$LOCAL = is_readable($__localFile) ? require $__localFile : [];
if (!is_array($LOCAL)) $LOCAL = [];

/**
 * Lê um valor de config.local.php; se não existir, tenta uma variável
 * de ambiente com o mesmo nome (em maiúsculas); por fim, usa o default.
 */
function cfg_local(string $chave, $default = null) {
    global $LOCAL;
    if (is_array($LOCAL) && array_key_exists($chave, $LOCAL)) return $LOCAL[$chave];
    $env = getenv(strtoupper($chave));
    return $env !== false ? $env : $default;
}

// ---- Evento -------------------------------------------------
const EVENTO = [
    'noiva'     => 'Isabel',
    'noivo'     => 'Abednego',
    'data_iso'  => '2026-12-19',                 // usado no contador e QR
    'data_ext'  => '19 de Dezembro de 2026',     // texto exibido
    'hora'      => '16:00',                       // AJUSTE: hora real da cerimónia
    'local'     => 'Estufa Municipal de Moçâmedes',
    'cidade'    => 'Namibe, Angola',
    'whatsapp'  => '244000000000',                // AJUSTE: nº WhatsApp p/ contacto (formato internacional, só dígitos)
];

/** Escapa texto para HTML. Usado em todas as páginas, tenham convite ou não. */
function escP($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- A plataforma, que não é de casal nenhum ----------------
// A casa serve vários casamentos. Há sítios que são de todos e de nenhum — a
// entrada, a inscrição — e onde mostrar o nome de um casal era mostrar o de
// outras pessoas a quem lá chega. Estes são os nomes da CASA.
const PLATAFORMA = [
    'nome'  => 'Gestão de Convidados',
    'sub'   => 'Convites, mesas e entradas do seu casamento',
    'marca' => '✦',
];

// ---- Base de dados -----------------------------------------
// Os dados reais de ligação vêm de config.local.php ('db').
// O default abaixo serve apenas para desenvolvimento local (XAMPP/Wamp).
// Tenta 'local' primeiro e depois 'online'.
define('DB_CONFIGS', cfg_local('db', [
    'local' => ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'wedding_guests'],
]));

// ---- Utilizadores (semente inicial) -------------------------
// As contas vivem na base de dados (tabela cw_utilizadores) e gerem-se pela
// aplicação. Esta lista serve só para SEMEAR a primeira instalação: a migração
// v7 copia-a para a base, uma única vez, e a partir daí é ignorada.
// Cada entrada tem 'utilizador', 'papel' ('admin' ou 'porteiro') e 'senha'
// OU 'senha_hash'. Ver auth.php.
define('UTILIZADORES', cfg_local('utilizadores', []));

// ---- Regras -------------------------------------------------
// Mesa mínima para os editores de convite: três colunas (ferramentas, peça e
// painéis) e uma barra de opções que não deve passar a duas linhas. Abaixo
// disto o editor ainda abre, mas avisa — ver assets/editor-espaco.js.
const EDITOR_MIN_L = 1200;   // largura, em px de CSS
const EDITOR_MIN_A = 700;    // altura

const PREFIXO   = 'cw_';   // prefixo das tabelas novas (não mexe na lista antiga)
// Teto de lugares por omissão. Cada casamento tem o seu ('evento.convidados',
// pedido no registo); este é só o valor de origem de quem nunca o preencheu.
const MAX_LUGARES_TOTAL = 150;
const RECICLAGEM_DIAS   = 30;   // dias que um convite eliminado fica recuperável
const LISTA_POR_PAGINA  = 60;   // convites carregados de cada vez no painel
const VERSOES_MAX       = 12;   // versões guardadas de cada convite (digital, impresso)

// Fuso não é crítico aqui; datas guardadas em UTC pelo MySQL.
date_default_timezone_set('Africa/Luanda');

/**
 * Caminho de um ficheiro de assets com a data da última alteração no fim.
 *
 * Sem isto, um CSS corrigido não chegava a quem já tinha visitado o site: o
 * navegador servia a cópia em cache e o defeito continuava à vista. Com a marca
 * de tempo, cada alteração é um endereço novo — e enquanto nada muda o
 * navegador continua a poupar o pedido.
 */
function asset(string $rel): string {
    $abs = __DIR__ . '/' . ltrim($rel, '/');
    $t = @filemtime($abs);
    return $rel . ($t ? '?v=' . $t : '');
}

/**
 * Ficheiros que compõem a aplicação, para efeitos de versão.
 * A data de modificação não serve: um envio por FTP baralha-a. O que conta é
 * o conteúdo.
 */
function ficheirosApp(): array {
    // Toda a página que se instala entra aqui. 'digital.php' e o cabeçalho
    // partilhado ficaram de fora quando foram criados, e por isso mexer neles
    // não mudava a assinatura — que existe justamente para dizer se o que está
    // instalado é o que se julga.
    return ['index.php','api.php','db.php','config.php','personalizacao.php','pecas.php',
            'editor-cartao.php','convite-editor.php','convite-digital.php','mesas.php',
            'cartoes.php','graficas.php','digital.php','manual.php','impressos.php',
            'porteiro.php','convite.php','login.php','auth.php',
            'parcial-cabecalho.php','parcial-endereco.php','versao.php','plataforma.php',
            'registo.php','gestao.php','orcamento.php','modelos.php','modelo-prova.php',
            'licenca.php','manifest.php','sw.js',
            'assets/estilo.css','assets/editor.css','assets/pecas.css','assets/planos.css',
            'assets/api.js','assets/mesas.js','assets/versoes.js','assets/orcamento.js','assets/moeda.js',
            'assets/planos.js',
            'assets/editor-paineis.js','assets/editor-adiar.js','assets/editor-diag.js',
            'assets/so-ver.js',
            'assets/convite-base.html'];
}

/**
 * As ações da API que ALTERAM dados. Uma lista só, usada em três sítios: o
 * token CSRF, a recusa de escrita numa visita de leitura, e o ecrã (que
 * desliga os controlos que iriam bater com o nariz na porta).
 *
 * Escrita em duas partes de propósito: as da plataforma (criar casamentos,
 * mexer em contas) não são dados de casamento nenhum, e por isso não caem na
 * regra do "só ver".
 */
function acoesDaPlataforma(): array {
    return ['casamento_criar','casamento_abrir','casamento_fechar','casamento_estado',
            'casamento_licenca','casamento_editar',
            'casamento_apagar','utilizador_criar','utilizador_editar','utilizador_apagar',
            'utilizador_estado','utilizador_repor_senha','acesso_tirar_de','suporte_sair',
            'modelo_criar','modelo_editar','modelo_defs','modelo_apagar','modelo_visibilidade',
            'modelo_exemplo_guardar','modelo_exemplo_upload','modelo_exemplo_apagar',
            'modelo_exemplo_categoria','modelo_exemplo_repor','modelo_pecaorigem',
            'modelos_importar','modelos_restaurar','sistema_importar','sistema_repor_fabrica',
            'sistema_tema_guardar','upload_chunk',
            // O preçário das licenças e as decisões sobre os pedidos.
            'lic_decidir','lic_revogar','lic_conceder','lic_modulo_guardar',
            'lic_escalao_guardar','lic_escalao_apagar','lic_pacote_guardar','lic_pacote_apagar',
            'lic_politica_guardar'];
}

function acoesDoCasamento(): array {
    return ['convite_save','convite_delete','convite_flag','convite_rsvp_manual','convite_restaurar',
            'mesa_save','mesa_delete','mesa_pos','mesa_noivos','convite_mesa','convidado_mesa',
            'convidado_papel','planta_size','planta_bloqueio',
            'defs_save','def_upload','def_media_repor','media_descartar','porta_checkin',
            'versao_criar','versao_aplicar','versao_atualizar','versao_renomear','versao_apagar',
            'casamento_identidade','casamento_endereco',
            'acesso_dar','acesso_convidar','acesso_tirar','acesso_papel','conta_apagar_do_casamento',
            'casamento_repor_fabrica',
            'suporte_codigo_criar','suporte_codigo_revogar',
            'dados_importar','modelo_aplicar',
            'orc_ajuste','orc_categoria_guardar','orc_categoria_apagar',
            'orc_despesa_guardar','orc_despesa_apagar','orc_despesa_fatura','orc_despesa_fatura_apagar',
            'orc_pagamento_guardar','orc_pagamento_apagar','orc_pagamento_liquidar',
            // O casal pede a licença, e muda de ideias enquanto ninguém decidiu.
            'lic_pedir','lic_pedido_cancelar'];
}

/**
 * Ações que NÃO precisam de um casamento aberto.
 *
 * O pessoal da casa entra sem casamento nenhum — de propósito, para não
 * aterrar na festa de um casal ao acaso. Mas as ações da plataforma (criar
 * casamentos, aprovar registos, gerir contas) são exatamente as que ele precisa
 * de fazer nesse estado. Sem esta lista, quem responde pela casa entrava e não
 * conseguia fazer nada — nem sequer abrir um casamento.
 */
function acoesSemCasamento(): array {
    return array_merge(acoesDaPlataforma(), ['utilizador_lista', 'utilizador_casamentos',
                                             'casamento_lista', 'casamento_ficha', 'esquema_info', 'acesso_dar',
                                             'conta_apagar_do_casamento',
                                             'dados_exportar', 'dados_importar',
                                             'modelo_lista', 'modelo_defs', 'modelo_exemplo',
                                             'modelos_exportar', 'modelos_importar',
                                             'registo_auditoria',
                                             'lic_pedidos', 'lic_decidir', 'lic_revogar', 'lic_conceder',
                                             'lic_modulo_guardar', 'lic_escalao_guardar', 'lic_escalao_apagar',
                                             'lic_pacote_guardar', 'lic_pacote_apagar', 'lic_politica_guardar']);
}

function acoesDeEscrita(): array {
    return array_merge(acoesDoCasamento(), acoesDaPlataforma());
}

/**
 * Assinatura curta do que está instalado. Muda sempre que qualquer ficheiro
 * muda, e é igual em duas instalações iguais — serve para confirmar, à
 * distância, se um servidor tem mesmo a versão que se julga.
 */
function versaoApp(): string {
    static $v = null;
    if ($v !== null) return $v;
    $h = '';
    foreach (ficheirosApp() as $f) {
        $abs = __DIR__ . '/' . $f;
        $h .= is_readable($abs) ? md5_file($abs) : 'ausente';
    }
    return $v = substr(md5($h), 0, 8);
}

// ---- Vigilância do âmbito de casamento ----------------------
// Com vários casamentos na mesma base, uma consulta sem filtro de dono é uma
// fuga de dados. Em modo estrito a ligação rebenta em vez de a deixar passar;
// liga-se nas provas (AMBITO_ESTRITO=1 no ambiente) e deixa-se desligado em
// produção, onde a falha vai para o log sem derrubar a página.
if (!defined('AMBITO_ESTRITO')) {
    define('AMBITO_ESTRITO', (string)getenv('AMBITO_ESTRITO') === '1');
}
