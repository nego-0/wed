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
const TEMA_VARS_EDITAVEIS = ['ink','forest','forest-deep','ivory','cream','sand','gold','gold-soft','gold-pale','blush','text'];

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
        'textos.kicker' => 'Vamos nos casar',
        'textos.hero_sub' => 'O nosso casamento',
        'textos.convite_eyebrow' => 'Venha partilhar a nossa alegria',
        'textos.lead' => "Há amores que, como o amanhecer, chegam devagar — e o nosso chegou para iluminar toda uma vida. É com o coração cheio de júbilo que {noiva} e {noivo} têm a honra de convidar V.\u{00A0}Exa. a partilhar a celebração do seu enlace matrimonial, e a alegria de um dia que ficará para sempre guardado na memória.",
        'textos.guest_label' => 'Convite reservado a',
        'textos.closing' => 'A vossa presença será o mais belo dos presentes — a luz e a música que tornarão eterno o mais feliz dos nossos dias.',
        'textos.nota_parenteses' => 'O número entre parênteses corresponde ao número de lugares para os quais o convite é destinado.',
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
        'cartao.numero_no_nome' => '1',   // '(N)' de lugares no nome do convidado, no cartão
    ];
}

// ---- Ler / fundir com a BD ---------------------------------
function definicoesBD(mysqli $conn): array {
    global $P;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $r = @$conn->query("SELECT chave, valor FROM {$P}definicoes");
    if ($r) while ($x = $r->fetch_assoc()) $cache[$x['chave']] = $x['valor'];
    return $cache;
}

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
        case 'cartao.numero_no_nome':
            return $valor === '1' ? '1' : '0';
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
    $mono  = inicialU($noiva).'&'.inicialU($noivo);
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
    $temaVars = '';
    if ($ovJson) {
        $temaVars = ':root{';
        foreach ($ovJson as $v => $cor) if (in_array($v, TEMA_VARS_EDITAVEIS, true) && corHexValida($cor)) $temaVars .= '--'.$v.':'.$cor.';';
        $temaVars .= '}';
    }
    $petais = json_encode([$pal['gold-pale'], $pal['gold-soft'], $pal['blush'], $pal['cream']]);

    return [
        '{{TITLE}}' => escP($casal.' — '.$dataExt),
        '{{MONO}}' => escP($mono),
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
    ];
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
