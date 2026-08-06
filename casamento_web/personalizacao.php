<?php
// ============================================================
// personalizacao.php — Personalização do convite digital
// Defaults (= aspeto original), catálogo de ícones, paletas,
// motor de placeholders e gravação das definições (cw_definicoes).
// ============================================================
require_once __DIR__ . '/config.php';

// ---- Escape utilitário -------------------------------------
function escP($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Texto do editor -> HTML seguro: escapa tudo, aplica mini-markdown
 * (**negrito**, *itálico*), tokens {noiva}/{noivo} e \n -> <br>.
 */
function mdTexto(string $t, array $tokens = []): string {
    if ($tokens) $t = strtr($t, $tokens);
    $t = escP($t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $t);
    $t = preg_replace('/\*(.+?)\*/s', '<i>$1</i>', $t);
    return nl2br($t, false);
}

/** Primeira letra (UTF-8, sem mbstring). */
function inicialU(string $s): string {
    return preg_match('/^./u', trim($s), $m) ? strtoupper($m[0]) : '';
}

function slugCasal(string $noiva, string $noivo): string {
    $s = $noiva . '-' . $noivo;
    $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ç'=>'c',
                    'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ú'=>'U','Ç'=>'C']);
    $s = preg_replace('/[^A-Za-z0-9\-]+/', '', $s);
    return $s !== '' ? $s : 'Convite';
}

// ---- Meses / dias em português -----------------------------
const MESES_PT  = [1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const DIAS_PT   = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];

/** '20:30' -> 'Ás 20h30' · '16:00' -> 'Ás 16h' */
function horaTexto(string $hhmm, bool $comAs = true): string {
    [$h, $m] = array_pad(explode(':', $hhmm), 2, '00');
    $txt = (int)$h . 'h' . ((int)$m ? sprintf('%02d', (int)$m) : '');
    return ($comAs ? 'Ás ' : '') . $txt;
}

function dataExtensa(string $ymd): string {
    $p = explode('-', $ymd);
    if (count($p) !== 3) return $ymd;
    return (int)$p[2] . ' de ' . (MESES_PT[(int)$p[1]] ?? '') . ' de ' . $p[0];
}

// ---- Paletas -----------------------------------------------
// As cores do convite digital que se podem mesmo escolher. Eram onze, mas
// --ink, --sand e --blush estão declaradas no modelo e nenhuma regra as usa:
// mexer nelas não mudava nada, e o painel parecia avariado.
const TEMA_VARS_EDITAVEIS = ['forest','forest-deep','ivory','cream','gold','gold-soft','gold-pale','text'];

/**
 * O que cada cor pinta, em português. Sem isto o painel mostrava "--gold-pale"
 * e ninguém podia adivinhar o que ia mudar ao mexer-lhe.
 */
function temaVarsRotulos(): array {
    return [
        'forest'      => ['rotulo'=>'Verde principal',  'onde'=>'Títulos e ícones sobre o papel claro'],
        'forest-deep' => ['rotulo'=>'Verde escuro',     'onde'=>'Fundo das secções escuras'],
        'ivory'       => ['rotulo'=>'Papel claro',      'onde'=>'Fundo claro, e o texto sobre o verde escuro'],
        'cream'       => ['rotulo'=>'Papel creme',      'onde'=>'Fundo do interlúdio'],
        'gold'        => ['rotulo'=>'Dourado',          'onde'=>'Filetes, molduras e destaques'],
        'gold-soft'   => ['rotulo'=>'Dourado suave',    'onde'=>'Chamadas, datas e a maioria dos detalhes'],
        'gold-pale'   => ['rotulo'=>'Dourado pálido',   'onde'=>'Texto de apoio sobre fundo escuro'],
        'text'        => ['rotulo'=>'Texto de leitura', 'onde'=>'Os parágrafos que se leem de corrido'],
    ];
}
// As cinco cores do cartão impresso (ver cartaoPaletas() em pecas.php).
const CARTAO_VARS_COR = ['accent','name','sub','head','soft'];

function temasPredef(): array {
    return [
        'floresta' => ['nome'=>'Floresta & Ouro','ink'=>'#20342A','forest'=>'#2C4536','forest-deep'=>'#16261E','ivory'=>'#FBF8F1','cream'=>'#F5EEDF','sand'=>'#E9DFC9','gold'=>'#B4864A','gold-soft'=>'#D9BC8C','gold-pale'=>'#EFE3CB','blush'=>'#E4CDBB','text'=>'#3B4A40'],
        'borgonha' => ['nome'=>'Borgonha','ink'=>'#3A1F26','forest'=>'#5A2734','forest-deep'=>'#2A1216','ivory'=>'#FBF6F2','cream'=>'#F5E9E2','sand'=>'#E9D8CC','gold'=>'#B4864A','gold-soft'=>'#D9BC8C','gold-pale'=>'#EFE3CB','blush'=>'#E4C4BB','text'=>'#4A3238'],
        'meianoite' => ['nome'=>'Meia-noite','ink'=>'#1E2A3C','forest'=>'#2A3B55','forest-deep'=>'#131C2B','ivory'=>'#F8F9FB','cream'=>'#EDF0F4','sand'=>'#DCE2EA','gold'=>'#B49254','gold-soft'=>'#D9C08C','gold-pale'=>'#EFE6CB','blush'=>'#C9D2E0','text'=>'#39424E'],
        'terracota' => ['nome'=>'Terracota','ink'=>'#40241A','forest'=>'#8A4B33','forest-deep'=>'#3C1F14','ivory'=>'#FBF7F1','cream'=>'#F5EBDF','sand'=>'#EADCC9','gold'=>'#B4794A','gold-soft'=>'#D9AE8C','gold-pale'=>'#EFDCCB','blush'=>'#E4C9BB','text'=>'#4A3B34'],
    ];
}

// ---- Catálogo de ícones (SVG de traço, viewBox 24) ---------
function iconesConvite(): array {
    return [
        'aneis'     => '<circle cx="9" cy="14.5" r="4.6"/><circle cx="15" cy="14.5" r="4.6"/><path d="M9 6.6l1.5 1.5L9 9.6 7.5 8.1z" fill="currentColor" stroke="none"/><path d="M9 9.6v.8" />',
        'bolo'      => '<path d="M4 20.5h16" stroke-linecap="round"/><path d="M5.5 20V14.6c0-.9.7-1.6 1.6-1.6h9.8c.9 0 1.6.7 1.6 1.6V20"/><path d="M8.4 13v-2.6c0-.7.5-1.2 1.2-1.2h4.8c.7 0 1.2.5 1.2 1.2V13"/><path d="M12 9.2V6.6"/><path d="M12 3.8c1 .7 1 2 0 2.7-1-.7-1-2 0-2.7z" fill="currentColor" stroke="none"/><path d="M5.8 16.6h12.4" opacity=".55"/>',
        'musica'    => '<path d="M9 17.4V5.2l9-1.8v11.4"/><ellipse cx="6.8" cy="17.6" rx="2.5" ry="1.9" fill="currentColor" stroke="none"/><ellipse cx="15.8" cy="14.8" rx="2.5" ry="1.9" fill="currentColor" stroke="none"/><path d="M9 8.6l9-1.8"/>',
        'buffet'    => '<path d="M3.8 16.8h16.4" stroke-linecap="round"/><path d="M5.2 16.8a6.8 6.8 0 0 1 13.6 0"/><circle cx="12" cy="8.4" r="1"/><path d="M6.6 19.6h10.8" stroke-linecap="round"/><path d="M9.4 5.6c.7-.7.7-1.7 0-2.4M12 5c.7-.7.7-1.7 0-2.4M14.6 5.6c.7-.7.7-1.7 0-2.4" opacity=".7" stroke-linecap="round"/>',
        'envelope'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 8l9 6 9-6"/>',
        'relogio'   => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/>',
        'casal'     => '<path d="M16 11c1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3 1.3 3 3 3zM8 11c1.7 0 3-1.3 3-3S9.7 5 8 5 5 6.3 5 8s1.3 3 3 3zM2 20v-1c0-2.2 2.7-4 6-4 1 0 2 .2 2.8.5M13 19.5c0-2.2 2.7-4 6-4" stroke-linejoin="round"/><path d="M20 16l2 4M22 16l-2 4"/>',
        'telemovel' => '<rect x="7" y="2" width="10" height="20" rx="2"/><circle cx="12" cy="9" r="2.6"/><path d="M12 5.4v1M12 11.6V13"/>',
        'crianca'   => '<circle cx="8" cy="5.5" r="2"/><path d="M8 7.5v5.5M5 9.5l3 1 3-1M8 13l-1.8 7M8 13l1.8 7" stroke-linejoin="round"/><circle cx="17" cy="6.5" r="2"/><path d="M17 8.5v5M14.5 10.5l2.5 1 2.5-1M17 13.5l-1.6 6.5M17 13.5l1.6 6.5" stroke-linejoin="round"/>',
        'taca'      => '<path d="M8 3h8M9 3l1.5 8L8 21h8l-2.5-10L15 3M8 21h8" stroke-linejoin="round"/>',
        'brinde'    => '<path d="M6 3h4l-.6 6a2.6 2.6 0 0 1-2.7 2.4A2.6 2.6 0 0 1 6.6 9L6 3z"/><path d="M8 11.5V20M5.5 20h5"/><path d="M14 5h4l-.5 5a2.4 2.4 0 0 1-4.8-.3L14 5z"/><path d="M16 12.5V20M13.5 20h5"/>',
        'coracao'   => '<path d="M12 20s-7.5-4.8-9.3-9C1.2 7.6 3.2 4.5 6.4 4.5c2 0 3.6 1.1 4.6 2.8 1-1.7 2.6-2.8 4.6-2.8 3.2 0 5.2 3.1 3.7 6.5-1.8 4.2-9.3 9-9.3 9z"/>',
        'estrela'   => '<path d="M12 3l2.7 5.6 6.1.8-4.5 4.3 1.1 6-5.4-2.9-5.4 2.9 1.1-6L3.2 9.4l6.1-.8z" stroke-linejoin="round"/>',
        'camera'    => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8.5 7l1.5-2.5h4L15.5 7"/><circle cx="12" cy="13.5" r="3.5"/>',
    ];
}

// ---- Defaults (= convite original, byte a byte) ------------
function defsPadrao(): array {
    return [
        'casal.noiva' => EVENTO['noiva'],
        'casal.noivo' => EVENTO['noivo'],
        'evento.data' => EVENTO['data_iso'],
        'evento.hora' => '20:30',
        'evento.venue_titulo' => "Copo d’água",
        'evento.local'  => EVENTO['local'],
        'evento.cidade' => 'Namibe · Angola',
        'evento.maps'   => 'https://maps.app.goo.gl/9o8MAHokTFRpgDBG9',
        'evento.whatsapp' => EVENTO['whatsapp'],
        // ---- Capa que abre (o envelope selado com o monograma) ----
        // Monograma vazio = as iniciais dos nomes (ex.: "I&A"); pode dar-se um à mão.
        'capa.monograma' => '',
        'capa.dica' => 'Toque para abrir',
        'textos.kicker' => 'Vamos nos casar',
        'textos.hero_sub' => 'O nosso casamento',
        'textos.convite_eyebrow' => 'Venha partilhar a nossa alegria',
        'textos.lead' => "Há amores que, como o amanhecer, chegam devagar — e o nosso chegou para iluminar toda uma vida. É com o coração cheio de júbilo que {noiva} e {noivo} têm a honra de convidar V.\u{00A0}Exa. a partilhar a celebração do seu enlace matrimonial, e a alegria de um dia que ficará para sempre guardado na memória.",
        'textos.guest_label' => 'Convite reservado a',
        'textos.closing' => 'A vossa presença será o mais belo dos presentes — a luz e a música que tornarão eterno o mais feliz dos nossos dias.',
        'gd.eyebrow' => 'Guarde esta data',
        'historia.visivel' => '1',
        'historia.eyebrow' => 'A nossa história',
        'historia.titulo'  => 'Dois olhares, um caminho',
        'historia.quote'   => 'Amamos aquilo que nos completa.',
        'historia.autor'   => 'Goethe',
        'historia.capitulos' => json_encode([
            ['t'=>'Um olhar, por acaso', 'x'=>'Há encontros que chegam sem aviso. O deles aconteceu assim: dois caminhos que se cruzaram nos corredores da mesma escola da vida, num tempo em que nenhum dos dois procurava nada — e, talvez por isso, encontraram tudo.'],
            ['t'=>'Devagar, como as coisas certas', 'x'=>'Primeiro foi um sorriso. Depois, as conversas que teimavam em não terminar, o silêncio confortável, a vontade de estar perto sem precisar de motivo. A amizade fez o que faz sempre que é verdadeira: abriu caminho ao amor.'],
            ['t'=>'E o amor floresceu', 'x'=>'Um dia, olharam um para o outro e perceberam que o futuro já tinha nome. Sem pressa, como florescem as coisas que vieram para ficar.'],
        ], JSON_UNESCAPED_UNICODE),
        'interludio.visivel' => '1',
        'interludio.quote' => "“Que não seja imortal, posto que é chama,\nmas que seja infinito enquanto dure.”",
        'interludio.autor' => 'Vinicius de Moraes',
        'interludio.fecho' => "Duas vidas, um só caminho —\ne todo o tempo do mundo pela frente.",
        'cronograma.visivel' => '1',
        'cronograma.titulo' => 'Cronograma do dia',
        'cronograma.itens' => json_encode([
            ['h'=>'20H30','p'=>'Noite','t'=>'Chegada dos noivos','s'=>'O grande momento','i'=>'aneis'],
            ['h'=>'21H00','p'=>'Noite','t'=>'Corte do bolo','s'=>'Doçura partilhada','i'=>'bolo'],
            ['h'=>'21H20','p'=>'Noite','t'=>'Abertura da pista','s'=>'A dança começa','i'=>'musica'],
            ['h'=>'21H30','p'=>'Noite','t'=>'Abertura do buffet','s'=>'À mesa, em festa','i'=>'buffet'],
        ], JSON_UNESCAPED_UNICODE),
        'acesso.eyebrow' => 'O seu passe de entrada',
        'acesso.titulo' => 'Apresente à chegada',
        'acesso.instrucao' => 'Por gentileza, apresente este código ao porteiro para ter acesso ao evento.',
        'acesso.nota' => '**Nota importante:** pedimos que não partilhe este convite com outras pessoas. O acesso é pessoal e intransmissível.',
        'manual.visivel' => '1',
        'manual.eyebrow' => 'Etiqueta',
        'manual.titulo' => 'Manual do convidado',
        'manual.intro' => 'Este é um momento único para nós — a sua colaboração é de extrema importância.',
        'manual.itens' => json_encode([
            ['i'=>'envelope','x'=>"Não **partilhe**\no seu convite"],
            ['i'=>'relogio','x'=>"Seja\n**pontual**"],
            ['i'=>'casal','x'=>"Convidado\n**não convida**"],
            ['i'=>'telemovel','x'=>"Tire fotos sem\n**atrapalhar** o fotógrafo"],
            ['i'=>'crianca','x'=>"Não levar\n**criança**"],
            ['i'=>'taca','x'=>"**Aproveite**\na festa"],
        ], JSON_UNESCAPED_UNICODE),
        'rsvp.titulo' => "Contamos com a\nsua presença",
        'rsvp.sub' => 'Cada história de amor é bela — mas a nossa terá um capítulo escrito também por si.',
        'rsvp.deadline' => 'Confirme a sua presença até 5 de Dezembro',
        'footer.local' => 'Moçâmedes',
        'footer.quote' => '“Amor é fogo que arde sem se ver.” — Luís de Camões',
        'media.hero' => 'assets/convite/hero.jpg',
        'media.historia' => 'assets/convite/historia.jpg',
        'media.interludio' => 'assets/convite/interludio.jpg',
        'media.acesso' => 'assets/convite/acesso.jpg',
        'media.musica' => 'assets/convite/musica.m4a',
        'tema.paleta' => '',
        'fx.petalas' => '1',
        'fx.autoplay' => '1',
        // ---- Cartão de convite 10×15 (impressão a dourado sobre acrílico) ----
        'cartao.paleta' => 'ouro',
        'cartao.folhagem' => 'eucalipto',
        'cartao.abertura' => "Junto com\nsuas famílias",
        'cartao.frase_convite' => 'honram-se em convidá-los para a celebração do seu enlace matrimonial.',
        'cartao.reservado' => 'Reservado a',
        'cartao.civil_titulo' => 'Cerimónia Civil',
        'cartao.civil_hora' => '10:30',
        'cartao.frase_final' => 'Há dias que se vivem uma vez e se recordam para sempre, e a sua companhia será parte do mais nobre que havemos de recordar.',
        'cartao.camadas' => '',   // vazio = todas as camadas visíveis
        // Cor e letra livres, por cima da paleta escolhida. Vazio = a paleta manda.
        'cartao.cores' => '',
        'cartao.fonte_script' => 'alexbrush',
        'cartao.fonte_serif'  => 'cormorant',
        'cartao.fonte_sans'   => 'montserrat',
        'cartao.escala' => '100',
        // Alternativas das camadas decorativas: sem isto só se podiam ligar e
        // desligar, e quem não gostasse da moldura ficava sem saída.
        'cartao.moldura_estilo' => 'simples',   // simples · dupla · fina · cantos
        'cartao.moldura_margem' => '28',        // px até à borda do cartão
        'cartao.ramos_escala' => '100',
        'cartao.volutas_escala' => '100',
        'cartao.floreados_escala' => '100',
        // ---- Enquadramento das fotografias recortadas ----
        // "x y zoom": que ponto da fotografia fica no centro do recorte (em %)
        // e quanto se aproxima (100 = sem aproximação). Os valores de origem são
        // os que o design trazia calibrados para as fotografias originais.
        'foto.hero'       => '50 8 100',
        'foto.interludio' => '50 26 100',
        'foto.acesso'     => '50 32 100',
        // ---- Estrutura do convite ----
        // Ordem das secções (a capa abre e o fecho encerra, sempre) e as
        // secções livres que o casal acrescente, em JSON.
        'layout.ordem'  => 'hero,convite,historia,interludio,grande-dia,acesso,final',
        'layout.blocos' => '',
        // ---- Tipografia ----
        // Três papéis, e um tamanho para o texto que se lê. A tipografia de
        // display (nomes, datas, títulos grandes) fica como o design a deixou.
        'tipo.serif'  => 'cormorant',
        'tipo.script' => 'pinyon',
        'tipo.sans'   => 'jost',
        'tipo.escala' => '100',
    ];
}

// ============================================================
// Versões: cada peça tem as suas, e uma delas está em vigor
// ============================================================

/**
 * As duas peças que se versionam. São coisas distintas — o convite que os
 * convidados abrem e o cartão que vai para a gráfica — por isso cada uma tem
 * as suas versões e a sua predefinida.
 */
function ambitosVersao(): array {
    return [
        'digital'  => ['rotulo' => 'Convite digital',  'editor' => 'convite-editor.php'],
        'impresso' => ['rotulo' => 'Convite impresso', 'editor' => 'editor-cartao.php'],
    ];
}

/** As definições que pertencem a um âmbito. O cartão é o prefixo 'cartao.'. */
function chavesDoAmbito(string $ambito): array {
    $todas = array_keys(defsPadrao());
    return $ambito === 'impresso'
        ? array_values(array_filter($todas, fn($k) => str_starts_with($k, 'cartao.')))
        : array_values(array_filter($todas, fn($k) => !str_starts_with($k, 'cartao.')));
}

/** Fotografia do estado atual de uma peça, pronta a guardar como versão. */
function instantaneoAmbito(mysqli $conn, string $ambito): array {
    $atuais = defsAtuais($conn);
    $out = [];
    foreach (chavesDoAmbito($ambito) as $k) $out[$k] = (string)($atuais[$k] ?? '');
    return $out;
}

/** Compara uma versão guardada com o que está em vigor agora. */
function versaoIgualAoAtual(mysqli $conn, string $ambito, string $defsJson): bool {
    $guardado = json_decode($defsJson, true);
    if (!is_array($guardado)) return false;
    foreach (instantaneoAmbito($conn, $ambito) as $k => $v) {
        if ((string)($guardado[$k] ?? '') !== $v) return false;
    }
    return true;
}

/**
 * A versão que está em vigor num âmbito: aquela cujo conteúdo é o que a peça
 * mostra agora. Devolve null se a peça tiver alterações que não estão guardadas
 * em versão nenhuma.
 *
 * É calculada, não lida de uma marca: uma marca guardada acabava a apontar para
 * uma versão enquanto a peça já mostrava outra coisa.
 */
function versaoEmVigor(mysqli $conn, string $ambito): ?array {
    global $P;
    $st = $conn->prepare("SELECT id, nome, defs, criado_em, atualizado_em
                          FROM {$P}versoes WHERE ambito=? ORDER BY id DESC");
    if (!$st) return null;
    $st->bind_param('s', $ambito);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $v) {
        if (versaoIgualAoAtual($conn, $ambito, $v['defs'])) { unset($v['defs']); return $v; }
    }
    return null;
}

/**
 * Estado de versões de uma peça, num único modelo partilhado pela entrada
 * (digital.php / graficas.php) e pelo painel do editor (versoes.js). Antes
 * cada sítio dizia o estado por palavras suas: a entrada atirava um seco
 * "Fora de qualquer versão" enquanto o painel dizia, da mesma peça, "foi a
 * última aplicada · a peça mudou desde então" — a mesma verdade contada de
 * duas maneiras que pareciam contradizer-se.
 *
 * Devolve sempre ['estado', 'nome', 'id']:
 *   'vigor'    — há uma versão cujo conteúdo é o que a peça mostra agora
 *                ('nome' é a dela).
 *   'alterada' — nenhuma bate certo, mas a última aplicada é conhecida
 *                ('nome' é a dela): a peça derivou dessa versão.
 *   'nenhuma'  — há versões, nenhuma bate certo e nenhuma consta como a
 *                última aplicada (caso raro: apagou-se a aplicada).
 *   'sem'      — ainda não há versões nenhumas.
 */
function versaoEstado(mysqli $conn, string $ambito): array {
    global $P;
    $vazio = ['estado' => 'sem', 'nome' => '', 'id' => 0];
    $st = $conn->prepare("SELECT id, nome, predefinida, defs
                          FROM {$P}versoes WHERE ambito=? ORDER BY id DESC");
    if (!$st) return $vazio;
    $st->bind_param('s', $ambito);
    $st->execute();
    $linhas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$linhas) return $vazio;

    $escolhida = null;
    foreach ($linhas as $v) {
        if (versaoIgualAoAtual($conn, $ambito, $v['defs'])) {
            return ['estado' => 'vigor', 'nome' => $v['nome'], 'id' => (int)$v['id']];
        }
        if ((int)$v['predefinida'] === 1 && $escolhida === null) $escolhida = $v;
    }
    return $escolhida
        ? ['estado' => 'alterada', 'nome' => $escolhida['nome'], 'id' => (int)$escolhida['id']]
        : ['estado' => 'nenhuma', 'nome' => '', 'id' => 0];
}

/**
 * Alguma versão guardada aponta para este ficheiro?
 * Serve para não apagar do disco uma foto ou música a que uma versão antiga
 * ainda se agarra — repô-la deixaria o convite com um buraco.
 */
function ficheiroEmVersao(mysqli $conn, string $caminho): bool {
    global $P;
    if ($caminho === '') return false;
    $st = $conn->prepare("SELECT 1 FROM {$P}versoes WHERE defs LIKE CONCAT('%', ?, '%') LIMIT 1");
    if (!$st) return true;               // sem certeza, guarda-se o ficheiro
    $st->bind_param('s', $caminho);
    $st->execute();
    return (bool)$st->get_result()->fetch_row();
}

/**
 * Tipos de letra disponíveis para o convite digital.
 * O modelo já carrega Cormorant, Jost e Pinyon; os outros só entram quando
 * são mesmo escolhidos, para não obrigar todos os convidados a descarregá-los.
 */
function fontesConvite(): array {
    return [
        'cormorant' => ['nome'=>'Cormorant Garamond', 'css'=>"'Cormorant Garamond',serif", 'papeis'=>['serif'],
                        'face'=>null],
        'jost'      => ['nome'=>'Jost',               'css'=>"'Jost',sans-serif",          'papeis'=>['sans'],
                        'face'=>null],
        'pinyon'    => ['nome'=>'Pinyon Script',      'css'=>"'Pinyon Script',cursive",    'papeis'=>['script'],
                        'face'=>null],
        'alexbrush' => ['nome'=>'Alex Brush',         'css'=>"'Alex Brush',cursive",       'papeis'=>['script'],
                        'face'=>"@font-face{font-family:'Alex Brush';font-style:normal;font-weight:400;font-display:swap;"
                              . "src:url(assets/convite/fonts/alex-brush-latin-400-normal.woff2) format('woff2')}"],
        'montserrat'=> ['nome'=>'Montserrat',         'css'=>"'Montserrat',sans-serif",    'papeis'=>['sans','serif'],
                        'face'=>"@font-face{font-family:'Montserrat';font-style:normal;font-weight:300 700;font-display:swap;"
                              . "src:url(assets/convite/fonts/montserrat-latin-variable-normal.woff2) format('woff2')}"],
    ];
}

/** Papéis tipográficos e a fonte de origem de cada um. */
function papeisTipo(): array {
    return ['serif'  => ['chave'=>'tipo.serif',  'rotulo'=>'Títulos e leitura', 'origem'=>'cormorant'],
            'script' => ['chave'=>'tipo.script', 'rotulo'=>'Caligrafia',        'origem'=>'pinyon'],
            'sans'   => ['chave'=>'tipo.sans',   'rotulo'=>'Rótulos e detalhes','origem'=>'jost']];
}

/** CSS da tipografia escolhida: variáveis + os @font-face que forem precisos. */
function cssTipografia(array $defs): array {
    $fontes = fontesConvite();
    $vars = ''; $faces = '';
    foreach (papeisTipo() as $papel => $p) {
        $id = $defs[$p['chave']] ?? $p['origem'];
        $f  = $fontes[$id] ?? $fontes[$p['origem']];
        $vars .= '--f-'.$papel.':'.$f['css'].';';
        if ($f['face'] && strpos($faces, $f['face']) === false) $faces .= $f['face'];
    }
    $esc = max(80, min(130, (int)($defs['tipo.escala'] ?? 100)));
    $vars .= '--esc-txt:'.round($esc/100, 3).';';
    return ['vars'=>$vars, 'faces'=>$faces];
}

// ============================================================
// Blocos: as secções do convite, na ordem escolhida
// ============================================================

/** Secções que vêm com o convite. A capa é sempre a primeira e o fecho o último. */
function blocosBase(): array { return ['hero','convite','historia','interludio','grande-dia','acesso','final']; }
const BLOCO_PRIMEIRO = 'hero';
const BLOCO_ULTIMO   = 'final';
const BLOCOS_MAX     = 6;      // secções livres, além das que já vêm no convite

/** Modelos de secção livre oferecidos no editor (só pré-preenchem o conteúdo). */
function modelosBloco(): array {
    return [
        'presentes' => ['rotulo'=>'Lista de presentes', 'icone'=>'brinde',
            'eyebrow'=>'Com todo o carinho', 'titulo'=>'Lista de presentes',
            'texto'=>'A vossa presença é o nosso maior presente. Para quem quiser oferecer algo mais, deixamos aqui algumas ideias.',
            'itens'=>[['i'=>'coracao','t'=>'','x'=>'']]],
        'chegar' => ['rotulo'=>'Como chegar', 'icone'=>'envelope',
            'eyebrow'=>'Para não se perder', 'titulo'=>'Como chegar',
            'texto'=>'Deixamos as indicações para chegar ao local da celebração.',
            'itens'=>[['i'=>'relogio','t'=>'','x'=>'']]],
        'alojamento' => ['rotulo'=>'Alojamento', 'icone'=>'estrela',
            'eyebrow'=>'Para quem vem de longe', 'titulo'=>'Onde ficar',
            'texto'=>'Algumas sugestões de alojamento perto do local.',
            'itens'=>[['i'=>'estrela','t'=>'','x'=>'']]],
        'padrinhos' => ['rotulo'=>'Padrinhos e madrinhas', 'icone'=>'aneis',
            'eyebrow'=>'Ao nosso lado', 'titulo'=>'Padrinhos e madrinhas',
            'texto'=>'As pessoas que escolhemos para estar connosco neste dia.',
            'itens'=>[['i'=>'aneis','t'=>'','x'=>'']]],
        'livre' => ['rotulo'=>'Secção livre', 'icone'=>'coracao',
            'eyebrow'=>'', 'titulo'=>'Nova secção', 'texto'=>'', 'itens'=>[]],
    ];
}

/** Secções livres gravadas, já validadas. */
function blocosLivres(array $defs): array {
    $j = json_decode($defs['layout.blocos'] ?? '', true);
    return is_array($j) ? $j : [];
}

/**
 * Ordem efetiva das secções: o que está gravado, limpo de ids desconhecidos,
 * com a capa à cabeça e o fecho no fim, e com o que faltar acrescentado.
 */
function ordemBlocos(array $defs): array {
    $validos = array_merge(blocosBase(), array_column(blocosLivres($defs), 'id'));
    $pedida  = array_filter(array_map('trim', explode(',', (string)($defs['layout.ordem'] ?? ''))));
    $ordem = [];
    foreach ($pedida as $id) if (in_array($id, $validos, true) && !in_array($id, $ordem, true)) $ordem[] = $id;
    foreach ($validos as $id) if (!in_array($id, $ordem, true)) $ordem[] = $id;   // nunca se perde nada
    // A capa abre e o fecho encerra: é o que faz o convite ler-se como um livro.
    $ordem = array_values(array_diff($ordem, [BLOCO_PRIMEIRO, BLOCO_ULTIMO]));
    array_unshift($ordem, BLOCO_PRIMEIRO);
    $ordem[] = BLOCO_ULTIMO;
    return $ordem;
}

/** Compõe o HTML de uma secção livre, com as classes do próprio convite. */
function renderBlocoLivre(array $b, array $tokens, bool $editor = false): string {
    $icones = iconesConvite();
    $eyebrow = trim((string)($b['eyebrow'] ?? ''));
    $titulo  = trim((string)($b['titulo'] ?? ''));
    $texto   = trim((string)($b['texto'] ?? ''));
    $itens   = is_array($b['itens'] ?? null) ? $b['itens'] : [];
    $id      = escP($b['id'] ?? 'bloco');

    // data-sec só no editor (é o que permite clicar na secção dentro da tela):
    // o convite dos convidados sai sem marca nenhuma.
    $marca = $editor ? ' data-sec="'.$id.'"' : '';
    $h  = '  <section id="'.$id.'"'.$marca.' class="page pad bloco-livre">'."\n";
    $h .= '    <span class="pageno rv">— um —</span>'."\n";
    if ($eyebrow !== '') $h .= '    <span class="eyebrow rv">'.escP(strtr($eyebrow, $tokens)).'</span>'."\n";
    if ($titulo  !== '') $h .= '    <h2 class="rv d1">'.escP(strtr($titulo, $tokens)).'</h2>'."\n";
    if ($texto   !== '') $h .= '    <p class="bl-texto rv d1">'.mdTexto($texto, $tokens).'</p>'."\n";
    if ($itens) {
        $celulas = '';
        foreach ($itens as $i => $it) {
            $ic = $icones[$it['i'] ?? ''] ?? $icones['coracao'];
            $t  = trim((string)($it['t'] ?? ''));
            $x  = trim((string)($it['x'] ?? ''));
            if ($t === '' && $x === '') continue;
            $celulas .= '        <div class="mcell rv'.($i % 2 ? ' d1' : '').'"><svg viewBox="0 0 24 24">'.$ic.'</svg>'
               .  ($t !== '' ? '<b>'.escP(strtr($t, $tokens)).'</b>' : '')
               .  ($x !== '' ? '<p>'.mdTexto($x, $tokens).'</p>' : '')
               .  '</div>'."\n";
        }
        if ($celulas !== '') $h .= '    <div class="mgrid">'."\n".$celulas.'    </div>'."\n";
    }
    $h .= '  </section>'."\n";
    return $h;
}

/**
 * Reordena as secções e insere as livres.
 * O modelo traz cada secção entre <!--BLOCO:id--> … <!--/BLOCO:id-->; aqui
 * separam-se, e voltam a ser emitidas pela ordem escolhida.
 */
function ordenarBlocos(string $html, array $defs, array $tokens = [], bool $editor = false): string {
    if (!preg_match('#<main>(.*)</main>#s', $html, $m)) return $html;
    $corpo = $m[1];

    $pecas = [];
    if (preg_match_all('#<!--BLOCO:([a-z0-9\-]+)-->(.*?)<!--/BLOCO:\1-->#s', $corpo, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) $pecas[$x[1]] = $x[2];
    }
    if (!$pecas) return $html;

    foreach (blocosLivres($defs) as $b) {
        if (!empty($b['id'])) $pecas[$b['id']] = "\n".renderBlocoLivre($b, $tokens, $editor);
    }

    $novo = '';
    foreach (ordemBlocos($defs) as $id) if (isset($pecas[$id])) $novo .= $pecas[$id];
    // str_replace e não preg_replace: o HTML pode conter $ ou \\, que numa
    // substituição por expressão regular seriam lidos como retrovisores.
    return str_replace($m[0], '<main>'.$novo.'</main>', $html);
}

/** As fotografias que são recortadas e por isso precisam de enquadramento. */
function fotosEnquadraveis(): array {
    return [
        'hero'       => ['chave'=>'foto.hero',       'media'=>'media.hero',       'rotulo'=>'Capa',              'proporcao'=>'9/16'],
        'interludio' => ['chave'=>'foto.interludio', 'media'=>'media.interludio', 'rotulo'=>'Interlúdio',        'proporcao'=>'9/16'],
        'acesso'     => ['chave'=>'foto.acesso',     'media'=>'media.acesso',     'rotulo'=>'Passe de entrada',  'proporcao'=>'16/11'],
    ];
}

/** "50 8 100" -> ['x'=>50,'y'=>8,'zoom'=>100]. Tolerante a valores estragados. */
function lerEnquadramento(string $v): array {
    $p = preg_split('/\s+/', trim($v));
    $n = fn($i, $def) => isset($p[$i]) && is_numeric($p[$i]) ? (float)$p[$i] : $def;
    return ['x' => max(0, min(100, $n(0, 50))),
            'y' => max(0, min(100, $n(1, 50))),
            'zoom' => max(100, min(300, $n(2, 100)))];
}

// ---- Ler / fundir com a BD ---------------------------------
function definicoesBD(mysqli $conn, bool $recarregar = false): array {
    global $P;
    static $cache = null;
    if ($cache !== null && !$recarregar) return $cache;
    $cache = [];
    $r = @$conn->query("SELECT chave, valor FROM {$P}definicoes");
    if ($r) while ($x = $r->fetch_assoc()) $cache[$x['chave']] = $x['valor'];
    return $cache;
}

/**
 * Esquece o que estava lido, para a próxima leitura ir à base de dados.
 *
 * Sem isto, gravar e voltar a ler no mesmo pedido devolvia os valores de antes
 * da gravação — e uma versão criada logo a seguir a gravar guardava o estado
 * antigo, não o que se acabara de gravar.
 */
function esquecerDefinicoes(mysqli $conn): void { definicoesBD($conn, true); }

/** Definições efetivas: defaults + valores personalizados (BD ganha). */
function defsAtuais(mysqli $conn): array {
    $defs = defsPadrao();
    foreach (definicoesBD($conn) as $k => $v) {
        if (array_key_exists($k, $defs) && $v !== null && $v !== '') $defs[$k] = $v;
    }
    return $defs;
}

/** Nome/monograma do casal (para cabeçalhos das outras páginas). */
function casalInfo(array $defs): array {
    $noiva = $defs['casal.noiva']; $noivo = $defs['casal.noivo'];
    return ['noiva'=>$noiva, 'noivo'=>$noivo,
            'casal'=>$noiva.' & '.$noivo,
            'mono'=>inicialU($noiva).'&'.inicialU($noivo)];
}

// ---- Validação e gravação ----------------------------------
function corHexValida($c): bool { return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c); }

/**
 * Serializa uma lista para JSON, ou null se falhar.
 * json_encode() devolve false perante texto que não seja UTF-8 válido. Como
 * validarDefinicao() declara ?string, esse false chegaria a quem chama já
 * convertido em '' — e '' significa "repor o original", ou seja, apagava a
 * linha em silêncio. Devolver null trata o caso como o que é: valor inválido.
 */
function jsonOuNulo(array $lista): ?string {
    $j = json_encode($lista, JSON_UNESCAPED_UNICODE);
    return $j === false ? null : $j;
}

/** Valida um valor pela chave; devolve o valor normalizado ou null (inválido). */
function validarDefinicao(string $chave, string $valor): ?string {
    $valor = trim($valor);
    if (str_ends_with($chave, '.visivel') || str_starts_with($chave, 'fx.')) {
        return $valor === '1' ? '1' : '0';
    }
    switch ($chave) {
        case 'capa.monograma':
            // Curto: cabe num selo. Vazio volta às iniciais automáticas.
            return mb_substr($valor, 0, 12);
        case 'capa.dica':
            // Vazio volta ao "Toque para abrir" (guardarDefinicoes repõe o default),
            // para a capa não ficar sem indicação de que se toca para abrir.
            return mb_substr($valor, 0, 40);
        case 'evento.data':
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? $valor : null;
        case 'evento.hora':
        case 'cartao.civil_hora':
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) ? $valor : null;
        // Enums das peças de design (chaves fixas aqui para não depender de pecas.php)
        case 'cartao.paleta':
            return in_array($valor, ['ouro','salvia','terracota','rosa'], true) ? $valor : null;
        case 'cartao.folhagem':
            return in_array($valor, ['eucalipto','oliveira','feto','florido'], true) ? $valor : null;
        case 'foto.hero':
        case 'foto.interludio':
        case 'foto.acesso': {
            // "x y zoom" — posição do ponto que fica no centro do recorte, e aproximação.
            if (!preg_match('/^\d{1,3}(\.\d+)?\s+\d{1,3}(\.\d+)?\s+\d{2,3}(\.\d+)?$/', $valor)) return null;
            $e = lerEnquadramento($valor);
            return round($e['x'],1).' '.round($e['y'],1).' '.round($e['zoom']);
        }
        case 'layout.ordem': {
            // Lista de ids separados por vírgula. Guarda-se o que é reconhecível;
            // ordemBlocos() trata de pôr a capa à frente e o fecho no fim.
            $ids = array_filter(array_map('trim', explode(',', $valor)),
                                fn($i) => preg_match('/^[a-z0-9\-]{1,24}$/', $i));
            return implode(',', array_unique($ids));
        }
        case 'layout.blocos': {
            if ($valor === '') return '';
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $icones = iconesConvite(); $out = []; $vistos = [];
            foreach (array_slice($j, 0, BLOCOS_MAX) as $b) {
                if (!is_array($b)) continue;
                $id = (string)($b['id'] ?? '');
                // O id entra no HTML e na ordem: só letras, números e hífen.
                if (!preg_match('/^bl[a-z0-9\-]{1,20}$/', $id) || in_array($id, $vistos, true)) continue;
                if (in_array($id, blocosBase(), true)) continue;      // não pode chocar com as secções de origem
                $vistos[] = $id;
                $itens = [];
                foreach (array_slice(is_array($b['itens'] ?? null) ? $b['itens'] : [], 0, 8) as $it) {
                    if (!is_array($it)) continue;
                    $t = mb_substr(trim((string)($it['t'] ?? '')), 0, 80);
                    $x = mb_substr(trim((string)($it['x'] ?? '')), 0, 400);
                    if ($t === '' && $x === '') continue;
                    $itens[] = ['i' => isset($icones[$it['i'] ?? '']) ? $it['i'] : 'coracao', 't' => $t, 'x' => $x];
                }
                $out[] = [
                    'id'      => $id,
                    'eyebrow' => mb_substr(trim((string)($b['eyebrow'] ?? '')), 0, 120),
                    'titulo'  => mb_substr(trim((string)($b['titulo'] ?? '')), 0, 120),
                    'texto'   => mb_substr(trim((string)($b['texto'] ?? '')), 0, 2000),
                    'itens'   => $itens,
                ];
            }
            return $out ? jsonOuNulo($out) : '';
        }
        case 'tipo.serif':
        case 'tipo.script':
        case 'tipo.sans': {
            // A fonte tem de existir e servir para este papel.
            $papel = substr($chave, 5);
            $f = fontesConvite()[$valor] ?? null;
            return ($f && in_array($papel, $f['papeis'], true)) ? $valor : null;
        }
        case 'tipo.escala':
            return ctype_digit($valor) ? (string)max(80, min(130, (int)$valor)) : null;
        case 'cartao.cores': {
            // Cores livres por cima da paleta: {"accent":"#RRGGBB", ...}
            if ($valor === '') return '';
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $out = [];
            foreach (CARTAO_VARS_COR as $v) if (corHexValida($j[$v] ?? null)) $out[$v] = strtoupper($j[$v]);
            return $out ? json_encode($out) : '';
        }
        case 'cartao.fonte_script':
        case 'cartao.fonte_serif':
        case 'cartao.fonte_sans': {
            $papel = substr($chave, 13);           // 'cartao.fonte_' + papel
            $f = fontesConvite()[$valor] ?? null;
            return ($f && in_array($papel, $f['papeis'], true)) ? $valor : null;
        }
        case 'cartao.escala':
            // Mais apertado que no convite digital: o cartão tem 10×15 cm fixos
            // e o texto não tem para onde crescer sem transbordar.
            return ctype_digit($valor) ? (string)max(85, min(115, (int)$valor)) : null;
        case 'cartao.moldura_estilo':
            return in_array($valor, ['simples','dupla','fina','cantos'], true) ? $valor : null;
        case 'cartao.moldura_margem':
            return ctype_digit($valor) ? (string)max(16, min(48, (int)$valor)) : null;
        case 'cartao.ramos_escala':
        case 'cartao.volutas_escala':
        case 'cartao.floreados_escala':
            return ctype_digit($valor) ? (string)max(60, min(140, (int)$valor)) : null;
        case 'cartao.camadas': {
            // Visibilidade das camadas do cartão: {"nome_da_camada": 0|1}
            if ($valor === '') return '';
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $validas = ['ramos','volutas','moldura','floreados','abertura','nomes',
                        'frase','convidado','mesas','data','logistica','fecho'];
            $out = [];
            foreach ($j as $k => $v) if (in_array($k, $validas, true)) $out[$k] = empty($v) ? 0 : 1;
            return $out ? json_encode($out) : '';
        }
        case 'evento.maps':
            return preg_match('#^https://#', $valor) ? mb_substr($valor, 0, 500) : null;
        case 'evento.whatsapp':
            $d = preg_replace('/\D/', '', $valor); return $d;
        case 'historia.capitulos': {
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $out = [];
            foreach (array_slice($j, 0, 8) as $c) {
                if (!is_array($c)) continue;
                $t = trim((string)($c['t'] ?? '')); $x = trim((string)($c['x'] ?? ''));
                if ($t === '' && $x === '') continue;
                $out[] = ['t'=>mb_substr($t,0,200), 'x'=>mb_substr($x,0,2000)];
            }
            return jsonOuNulo($out);
        }
        case 'cronograma.itens': {
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $icones = iconesConvite(); $out = [];
            foreach (array_slice($j, 0, 12) as $c) {
                if (!is_array($c)) continue;
                $t = trim((string)($c['t'] ?? '')); if ($t === '') continue;
                $out[] = ['h'=>mb_substr(trim((string)($c['h'] ?? '')),0,20), 'p'=>mb_substr(trim((string)($c['p'] ?? '')),0,30),
                          't'=>mb_substr($t,0,80), 's'=>mb_substr(trim((string)($c['s'] ?? '')),0,80),
                          'i'=>isset($icones[$c['i'] ?? '']) ? $c['i'] : 'coracao'];
            }
            return jsonOuNulo($out);
        }
        case 'manual.itens': {
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $icones = iconesConvite(); $out = [];
            foreach (array_slice($j, 0, 12) as $c) {
                if (!is_array($c)) continue;
                $x = trim((string)($c['x'] ?? '')); if ($x === '') continue;
                $out[] = ['i'=>isset($icones[$c['i'] ?? '']) ? $c['i'] : 'coracao', 'x'=>mb_substr($x,0,200)];
            }
            return jsonOuNulo($out);
        }
        case 'tema.paleta': {
            if ($valor === '') return '';
            $j = json_decode($valor, true); if (!is_array($j)) return null;
            $out = [];
            foreach (TEMA_VARS_EDITAVEIS as $v) if (corHexValida($j[$v] ?? null)) $out[$v] = strtoupper($j[$v]);
            return $out ? json_encode($out) : '';
        }
    }
    if (str_starts_with($chave, 'media.')) {
        // Só caminhos internos (originais ou uploads em custom/), sem "..".
        if (str_contains($valor, '..')) return null;
        return preg_match('#^assets/convite/[A-Za-z0-9._\-/]+$#', $valor) ? $valor : null;
    }
    return mb_substr($valor, 0, 4000); // texto simples/markdown
}

/** Grava um conjunto de definições; valor vazio ou igual ao default repõe o original. */
function guardarDefinicoes(mysqli $conn, array $novos): array {
    global $P;
    $padrao = defsPadrao();
    $gravadas = 0; $repostas = 0; $invalidas = [];
    foreach ($novos as $chave => $valor) {
        if (!array_key_exists($chave, $padrao) || !is_string($valor)) continue;
        $v = validarDefinicao($chave, $valor);
        if ($v === null) { $invalidas[] = $chave; continue; }
        if ($v === '' || $v === $padrao[$chave]) {
            $st = $conn->prepare("DELETE FROM {$P}definicoes WHERE chave=?");
            $st->bind_param('s', $chave); $st->execute();
            $repostas++;
        } else {
            $st = $conn->prepare("INSERT INTO {$P}definicoes (chave, valor) VALUES (?,?)
                                  ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $st->bind_param('ss', $chave, $v); $st->execute();
            $gravadas++;
        }
    }
    // O que estava lido em memória ficou velho neste instante.
    if ($gravadas || $repostas) esquecerDefinicoes($conn);
    return ['gravadas'=>$gravadas, 'repostas'=>$repostas, 'invalidas'=>$invalidas];
}

// ---- Motor: definições -> placeholders do modelo -----------
function icsSan(string $s): string {
    $s = preg_replace('/[\r\n\t\\\\]+/', ' ', $s);
    $s = str_replace(',', "\\\\,", $s);      // vírgula ICS escapada (\,) em fonte JS
    return str_replace("'", "\\'", $s);       // apóstrofo seguro numa string JS
}

function paletaEfetiva(array $defs): array {
    $base = temasPredef()['floresta'];
    unset($base['nome']);
    $ov = json_decode($defs['tema.paleta'] ?: '[]', true) ?: [];
    foreach (TEMA_VARS_EDITAVEIS as $v) if (corHexValida($ov[$v] ?? null)) $base[$v] = $ov[$v];
    return $base;
}

function convitePlaceholders(array $defs): array {
    $noiva = $defs['casal.noiva']; $noivo = $defs['casal.noivo'];
    $tokens = ['{noiva}'=>$noiva, '{noivo}'=>$noivo];
    $casal = $noiva.' & '.$noivo;
    // Monograma do selo: o que se escreveu à mão, ou as iniciais dos nomes.
    $monoAuto = inicialU($noiva).'&'.inicialU($noivo);
    $mono  = trim((string)($defs['capa.monograma'] ?? '')) !== '' ? $defs['capa.monograma'] : $monoAuto;
    $slug  = slugCasal($noiva, $noivo);

    // Data / hora
    $tz  = new DateTimeZone(date_default_timezone_get());
    try { $dt = new DateTime($defs['evento.data'].' '.$defs['evento.hora'], $tz); }
    catch (Throwable $e) { $dt = new DateTime('now', $tz); }
    $w = (int)$dt->format('w'); $d = (int)$dt->format('j'); $mes = (int)$dt->format('n'); $ano = $dt->format('Y');
    $mesNome = MESES_PT[$mes]; $dataExt = $d.' de '.$mesNome.' de '.$ano;

    // ICS (UTC)
    $ini = clone $dt; $ini->setTimezone(new DateTimeZone('UTC'));
    $fim = clone $ini; $fim->modify('+330 minutes');
    $agoraUtc = new DateTime('now', new DateTimeZone('UTC'));

    // Blocos repetíveis
    $icones = iconesConvite();
    $caps = json_decode($defs['historia.capitulos'], true) ?: [];
    $ROM = ['I','II','III','IV','V','VI','VII','VIII'];
    $htmlCaps = '';
    foreach ($caps as $i => $c) {
        $texto = mdTexto($c['x'] ?? '', $tokens);
        if ($i === 0 && preg_match('/^(<br\s*\/?>)*(\p{L})/u', $texto, $m, PREG_OFFSET_CAPTURE)) {
            // Capitular na primeira letra do primeiro capítulo (como no original).
            // Aqui o substr() por bytes é o correto: PREG_OFFSET_CAPTURE devolve
            // deslocamentos em bytes e strlen() mede a letra na mesma unidade.
            $pos = $m[2][1]; $letra = $m[2][0];
            $texto = substr($texto, 0, $pos).'<span class="drop">'.$letra.'</span>'.substr($texto, $pos + strlen($letra));
        }
        $htmlCaps .= '<div class="chapter"><div class="dot"></div>'
            . '<p class="ch-eyebrow">Capítulo '.($ROM[$i] ?? ($i+1)).'</p>'
            . '<h3>'.escP($c['t'] ?? '').'</h3>'
            . '<p>'.$texto.'</p></div>'."\n";
    }
    $crono = json_decode($defs['cronograma.itens'], true) ?: [];
    $htmlCrono = '';
    foreach ($crono as $c) {
        $ic = $icones[$c['i'] ?? ''] ?? $icones['coracao'];
        $htmlCrono .= '<div class="t-item">'
            . '<div class="half time"><div class="hh">'.escP($c['h'] ?? '').'<small>'.escP($c['p'] ?? '').'</small></div></div>'
            . '<div class="node"><svg viewBox="0 0 24 24">'.$ic.'</svg></div>'
            . '<div class="half desc"><div class="tt">'.escP($c['t'] ?? '').'<em>'.escP($c['s'] ?? '').'</em></div></div>'
            . '</div>'."\n";
    }
    $man = json_decode($defs['manual.itens'], true) ?: [];
    $htmlMan = '';
    foreach ($man as $i => $c) {
        $ic = $icones[$c['i'] ?? ''] ?? $icones['coracao'];
        $htmlMan .= '<div class="mcell rv'.($i % 2 ? ' d1' : '').'"><svg viewBox="0 0 24 24">'.$ic.'</svg>'
            . '<p>'.mdTexto($c['x'] ?? '', $tokens).'</p></div>'."\n";
    }

    // Tema (override das variáveis CSS) + cores derivadas
    $pal = paletaEfetiva($defs);
    $ovJson = json_decode($defs['tema.paleta'] ?: '[]', true) ?: [];
    $vars = '';
    foreach ($ovJson as $v => $cor) if (in_array($v, TEMA_VARS_EDITAVEIS, true) && corHexValida($cor)) $vars .= '--'.$v.':'.$cor.';';
    // Enquadramento das fotografias recortadas: que ponto fica ao centro e
    // quanto se aproxima. Sai como variáveis para o editor as poder mudar ao vivo.
    foreach (fotosEnquadraveis() as $id => $f) {
        $e = lerEnquadramento($defs[$f['chave']] ?? '');
        $vars .= '--foco-'.$id.':'.$e['x'].'% '.$e['y'].'%;';
        $vars .= '--zoom-'.$id.':'.($e['zoom']/100).';';
    }
    $tipo  = cssTipografia($defs);
    $vars .= $tipo['vars'];
    $temaVars = $tipo['faces'] . ($vars !== '' ? ':root{'.$vars.'}' : '');
    $petais = json_encode([$pal['gold-pale'], $pal['gold-soft'], $pal['blush'], $pal['cream']]);

    return [
        '{{TITLE}}' => escP($casal.' — '.$dataExt),
        '{{MONO}}' => escP($mono),
        '{{COVER_HINT}}' => escP($defs['capa.dica']),
        '{{NOIVA}}' => escP($noiva), '{{NOIVO}}' => escP($noivo),
        '{{CASAL_ALT}}' => escP($noiva.' e '.$noivo),
        '{{DIA}}' => $d, '{{ANO}}' => $ano,
        '{{MES_NOME}}' => escP($mesNome), '{{MES_ABREV}}' => escP(mb_substr($mesNome, 0, 3)),
        '{{DIA_SEMANA}}' => DIAS_PT[$w],
        '{{HORA_TXT}}' => escP(horaTexto($defs['evento.hora'])),
        '{{DATA_JS}}' => $defs['evento.data'].'T'.$defs['evento.hora'].':00'.$dt->format('P'),
        '{{KICKER}}' => escP($defs['textos.kicker']),
        '{{HERO_SUB}}' => escP($defs['textos.hero_sub']),
        '{{CONVITE_EYEBROW}}' => escP($defs['textos.convite_eyebrow']),
        '{{LEAD}}' => mdTexto($defs['textos.lead'], $tokens),
        '{{GUEST_LABEL}}' => escP($defs['textos.guest_label']),
        '{{CLOSING}}' => mdTexto($defs['textos.closing'], $tokens),
        '{{GD_EYEBROW}}' => escP($defs['gd.eyebrow']),
        '{{HIST_EYEBROW}}' => escP($defs['historia.eyebrow']),
        '{{HIST_TITULO}}' => escP($defs['historia.titulo']),
        '{{HIST_QUOTE}}' => mdTexto($defs['historia.quote'], $tokens),
        '{{HIST_AUTOR}}' => escP($defs['historia.autor']),
        '{{HIST_CAPITULOS}}' => $htmlCaps,
        '{{INTER_QUOTE}}' => mdTexto($defs['interludio.quote'], $tokens),
        '{{INTER_AUTOR}}' => escP($defs['interludio.autor']),
        '{{INTER_FECHO}}' => mdTexto($defs['interludio.fecho'], $tokens),
        '{{CRONO_TITULO}}' => escP($defs['cronograma.titulo']),
        '{{CRONO_SUB}}' => escP(DIAS_PT[$w].', '.$d.' de '.$mesNome),
        '{{CRONO_ITENS}}' => $htmlCrono,
        '{{VENUE_TITULO}}' => escP($defs['evento.venue_titulo']),
        '{{VENUE_LINHAS}}' => escP($defs['evento.local']).'<br>'.escP($defs['evento.cidade']),
        '{{MAPS_URL}}' => escP($defs['evento.maps']),
        '{{ACESSO_EYEBROW}}' => escP($defs['acesso.eyebrow']),
        '{{ACESSO_TITULO}}' => escP($defs['acesso.titulo']),
        '{{ACESSO_INSTRUCAO}}' => mdTexto($defs['acesso.instrucao'], $tokens),
        '{{ACESSO_NOTA}}' => mdTexto($defs['acesso.nota'], $tokens),
        '{{MANUAL_EYEBROW}}' => escP($defs['manual.eyebrow']),
        '{{MANUAL_TITULO}}' => escP($defs['manual.titulo']),
        '{{MANUAL_INTRO}}' => mdTexto($defs['manual.intro'], $tokens),
        '{{MANUAL_ITENS}}' => $htmlMan,
        '{{RSVP_TITULO}}' => mdTexto($defs['rsvp.titulo'], $tokens),
        '{{RSVP_SUB}}' => mdTexto($defs['rsvp.sub'], $tokens),
        '{{RSVP_DEADLINE}}' => escP($defs['rsvp.deadline']),
        '{{FOOTER_DATA}}' => $d.' · '.$mes.' · '.$ano.' &nbsp;&mdash;&nbsp; '.escP($defs['footer.local']),
        '{{FOOTER_QUOTE}}' => mdTexto($defs['footer.quote'], $tokens),
        '{{MUSICA}}' => escP($defs['media.musica']),
        '{{IMG_HERO}}' => escP($defs['media.hero']),
        '{{IMG_HISTORIA}}' => escP($defs['media.historia']),
        '{{IMG_INTERLUDIO}}' => escP($defs['media.interludio']),
        '{{IMG_ACESSO}}' => escP($defs['media.acesso']),
        '{{TEMA_VARS}}' => $temaVars,
        '{{PETAL_COLORS}}' => $petais,
        '{{FX_PETALAS}}' => $defs['fx.petalas'] === '1' ? 'true' : 'false',
        '{{FX_AUTOPLAY}}' => $defs['fx.autoplay'] === '1' ? 'true' : 'false',
        '{{QR_FG}}' => $pal['forest-deep'], '{{QR_BG}}' => $pal['ivory'],
        '{{ICS_UID}}' => 'UID:'.strtolower($slug).'@convite',
        '{{ICS_STAMP}}' => $agoraUtc->format('Ymd\THis\Z'),
        '{{ICS_DTSTART}}' => 'DTSTART:'.$ini->format('Ymd\THis\Z'),
        '{{ICS_DTEND}}' => 'DTEND:'.$fim->format('Ymd\THis\Z'),
        '{{ICS_SUMMARY}}' => 'SUMMARY:'.icsSan('Casamento de '.$casal),
        '{{ICS_LOCATION}}' => 'LOCATION:'.icsSan($defs['evento.local'].', '.$defs['evento.cidade']),
        '{{ICS_DESC}}' => 'DESCRIPTION:'.icsSan($defs['evento.venue_titulo'].' '.strtolower(horaTexto($defs['evento.hora'])).'. Confirme a sua presença.'),
        '{{ICS_FILE}}' => 'Casamento-'.$slug.'.ics',
    ];
}

/** Remove as secções ocultas (marcadores <!--SEC:x--> … <!--/SEC:x-->). */
function aplicarSeccoes(string $html, array $defs): string {
    foreach (['historia', 'interludio', 'cronograma', 'manual'] as $sec) {
        if (($defs[$sec.'.visivel'] ?? '1') !== '1') {
            $html = preg_replace('#<!--SEC:'.$sec.'-->.*?<!--/SEC:'.$sec.'-->#s', '', $html);
        }
    }
    $html = str_replace(['<!--SEC:historia-->','<!--/SEC:historia-->','<!--SEC:interludio-->','<!--/SEC:interludio-->',
                        '<!--SEC:cronograma-->','<!--/SEC:cronograma-->','<!--SEC:manual-->','<!--/SEC:manual-->'], '', $html);
    return numerarPaginas($html);
}

// ============================================================
// Modo de edição: marcar o convite para a tela o poder manipular
// ============================================================

/**
 * Secções do convite, pela ordem em que aparecem. É a lista de "camadas"
 * do editor. 'opcional' diz se pode ser escondida (tem marcador SEC:).
 */
function seccoesConvite(): array {
    return [
        'hero'       => ['rotulo'=>'Capa',              'opcional'=>false, 'campos'=>['textos.kicker','textos.hero_sub','casal.noiva','casal.noivo']],
        'convite'    => ['rotulo'=>'O convite',         'opcional'=>false, 'campos'=>['textos.convite_eyebrow','textos.lead','textos.guest_label','textos.closing']],
        'historia'   => ['rotulo'=>'História',          'opcional'=>true,  'campos'=>['historia.eyebrow','historia.titulo','historia.quote','historia.autor']],
        'interludio' => ['rotulo'=>'Interlúdio',        'opcional'=>true,  'campos'=>['interludio.quote','interludio.autor','interludio.fecho']],
        'grande-dia' => ['rotulo'=>'O grande dia',      'opcional'=>false, 'campos'=>['gd.eyebrow','evento.venue_titulo','cronograma.titulo']],
        'acesso'     => ['rotulo'=>'Passe de entrada',  'opcional'=>false, 'campos'=>['acesso.eyebrow','acesso.titulo','acesso.instrucao','acesso.nota']],
        'final'      => ['rotulo'=>'Confirmação e fecho','opcional'=>false,'campos'=>['rsvp.titulo','rsvp.sub','rsvp.deadline','manual.titulo','footer.quote']],
    ];
}

/**
 * Placeholder -> definição que o alimenta. Só os que ocupam um elemento
 * inteiro: são os que a tela consegue reescrever enquanto se escreve.
 */
function mapaDefEditor(): array {
    return [
        '{{KICKER}}'           => 'textos.kicker',
        '{{HERO_SUB}}'         => 'textos.hero_sub',
        '{{CONVITE_EYEBROW}}'  => 'textos.convite_eyebrow',
        '{{LEAD}}'             => 'textos.lead',
        '{{GUEST_LABEL}}'      => 'textos.guest_label',
        '{{CLOSING}}'          => 'textos.closing',
        '{{HIST_EYEBROW}}'     => 'historia.eyebrow',
        '{{HIST_TITULO}}'      => 'historia.titulo',
        '{{HIST_QUOTE}}'       => 'historia.quote',
        '{{HIST_AUTOR}}'       => 'historia.autor',
        '{{INTER_QUOTE}}'      => 'interludio.quote',
        '{{INTER_AUTOR}}'      => 'interludio.autor',
        '{{INTER_FECHO}}'      => 'interludio.fecho',
        '{{GD_EYEBROW}}'       => 'gd.eyebrow',
        '{{CRONO_TITULO}}'     => 'cronograma.titulo',
        '{{VENUE_TITULO}}'     => 'evento.venue_titulo',
        '{{ACESSO_EYEBROW}}'   => 'acesso.eyebrow',
        '{{ACESSO_TITULO}}'    => 'acesso.titulo',
        '{{ACESSO_INSTRUCAO}}' => 'acesso.instrucao',
        '{{ACESSO_NOTA}}'      => 'acesso.nota',
        '{{MANUAL_EYEBROW}}'   => 'manual.eyebrow',
        '{{MANUAL_TITULO}}'    => 'manual.titulo',
        '{{MANUAL_INTRO}}'     => 'manual.intro',
        '{{RSVP_TITULO}}'      => 'rsvp.titulo',
        '{{RSVP_SUB}}'         => 'rsvp.sub',
        '{{RSVP_DEADLINE}}'    => 'rsvp.deadline',
        '{{FOOTER_QUOTE}}'     => 'footer.quote',
        '{{COVER_HINT}}'       => 'capa.dica',
    ];
}

/**
 * Textos que aparecem em mais do que um sítio, ou lado a lado com outro dentro
 * do mesmo elemento — os nomes dos noivos partilham um <h1>. Para estes não
 * serve marcar o elemento que os contém (reescrevê-lo apagava o vizinho):
 * envolve-se cada ocorrência do próprio marcador.
 */
function mapaDefRepetido(): array {
    // O monograma aparece no selo da capa, no separador do convite e no rodapé.
    // É a marca do casal: muda-se num sítio, muda em todos.
    return ['{{NOIVA}}' => 'casal.noiva', '{{NOIVO}}' => 'casal.noivo',
            '{{MONO}}'  => 'capa.monograma'];
}

/**
 * Definições cujo texto passa pelo mini-markdown (**negrito**, *itálico*,
 * {noiva}/{noivo}, quebras de linha). A tela precisa de saber quais são para
 * as reescrever com o mesmo aspeto do servidor; as restantes são texto simples.
 */
function camposMarkdown(): array {
    return ['textos.lead','textos.closing','historia.quote','interludio.quote','interludio.fecho',
            'acesso.instrucao','acesso.nota','manual.intro','rsvp.titulo','rsvp.sub','footer.quote'];
}

/**
 * Prepara o modelo para a tela do editor: põe data-sec nas secções e
 * data-def no elemento que envolve cada texto. Corre ANTES da substituição
 * dos placeholders, e só no editor — o convite dos convidados sai limpo.
 */
function marcarParaEditor(string $html): string {
    // A capa que abre (o envelope selado) é uma camada como as outras, mas não
    // é uma <section> dentro de <main>: marca-se à parte.
    $html = preg_replace('#(<div id="cover")#', '$1 data-sec="capa"', $html, 1);
    // Secções -> camadas
    foreach (array_keys(seccoesConvite()) as $sec) {
        $html = preg_replace('#(<section id="'.preg_quote($sec, '#').'")#', '$1 data-sec="'.$sec.'"', $html, 1);
    }
    // Cada texto -> o elemento que o contém fica identificado pela sua definição
    foreach (mapaDefEditor() as $ph => $chave) {
        $html = preg_replace_callback(
            '#<([a-z0-9]+)((?:[^>"]|"[^"]*")*?)>(\s*)' . preg_quote($ph, '#') . '#i',
            fn($m) => '<' . $m[1] . $m[2] . ' data-def="' . $chave . '">' . $m[3] . $ph,
            $html, 1
        );
    }
    // Os repetidos ganham invólucro próprio, em todas as ocorrências. Sem isto,
    // escrever os nomes dos noivos não mudava nada na tela.
    foreach (mapaDefRepetido() as $ph => $chave) {
        $html = str_replace($ph, '<span data-def="' . $chave . '">' . $ph . '</span>', $html);
    }
    return $html;
}

/** Numerais por extenso para a numeração das páginas do convite. */
const ORDINAIS_PT = ['um','dois','três','quatro','cinco','seis','sete','oito','nove','dez'];

/**
 * Renumera as páginas pela ordem em que ficaram.
 * Os números estavam escritos à mão no modelo, por isso esconder uma secção
 * deixava um buraco visível na sequência ("— um — … — três —"). Aqui contam-se
 * as páginas que sobreviveram e reescreve-se cada uma pela sua posição.
 */
function numerarPaginas(string $html): string {
    $n = 0;
    return preg_replace_callback(
        '#(<span class="pageno[^"]*">)\s*—\s*[^<—]*\s*—\s*(</span>)#u',
        function ($m) use (&$n) {
            $rot = ORDINAIS_PT[$n] ?? (string)($n + 1);
            $n++;
            return $m[1] . '— ' . $rot . ' —' . $m[2];
        },
        $html
    );
}
