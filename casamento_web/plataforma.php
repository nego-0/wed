<?php
// ============================================================
// plataforma.php — Os casamentos que o sistema serve
//
// A casa vista de cima: quem é da plataforma (admin, suporte) vê aqui todos
// os casamentos, abre o que precisa e cria novos. Quem é dos noivos e tem mais
// do que um vê aqui os seus, e escolhe em qual quer trabalhar.
//
// Serve também de guarda: saber SEMPRE em que casamento se está é o que evita
// editar o convite do casal errado.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';

// Qualquer pessoa autenticada chega aqui; o que vê é que difere.
if (!papel() && !ehPessoalPlataforma()) {
    header('Location: login.php?r=' . urlencode('plataforma.php')); exit;
}

$meus    = casamentosDoUtilizador($conn);
$aberto  = casamentoAtual();
$daCasa  = ehPessoalPlataforma();
$mandaNaCasa = ehAdminPlataforma();   // criar casamentos e gerir contas é do admin

// Números de cada casamento, numa consulta só por tabela — em alojamento
// partilhado, uma consulta por linha da lista custa caro.
$conta = [];
foreach ([['convites', 'convites'], ['convidados', 'pessoas'], ['mesas', 'mesas']] as [$tab, $rot]) {
    $r = @$conn->query("SELECT casamento_id, COUNT(*) n FROM {$P}$tab GROUP BY casamento_id");
    if ($r) while ($x = $r->fetch_assoc()) $conta[(int)$x['casamento_id']][$rot] = (int)$x['n'];
}
// Casamentos sem uma conta de noivos. Desde que o admin da plataforma deixou de
// ocupar um lugar que não era dele, isto deixou de ser uma hipótese teórica:
// um casamento pode ficar sem ninguém que seja MESMO dele — e é preciso vê-lo,
// senão o casal nunca recebe as chaves da sua própria festa.
$semDono = [];
$r = @$conn->query("SELECT c.id FROM {$P}casamentos c
                    LEFT JOIN {$P}acessos a ON a.casamento_id = c.id AND a.papel = 'noivos'
                    WHERE c.estado <> 'arquivado' GROUP BY c.id HAVING COUNT(a.id) = 0");
if ($r) while ($x = $r->fetch_assoc()) $semDono[(int)$x['id']] = true;

// ---- Estatísticas de toda a casa (só para quem responde por ela) ----------
// Esta é a página onde o pessoal da plataforma aterra ao entrar. Aterrar numa
// lista de nomes não diz nada sobre o estado do sistema: quantos casamentos
// estão de pé, quanta gente lá dentro, o que está à espera de alguém.
//
// As consultas atravessam casamentos de propósito e dizem-no ('casamento_id > 0'),
// que é o que o guarda de âmbito exige para as deixar passar.
$G = null;
if ($daCasa) {
    $G = ['casamentos'=>0, 'ativos'=>0, 'pendentes'=>0, 'suspensos'=>0,
          'convites'=>0, 'pessoas'=>0, 'confirmadas'=>0, 'presentes'=>0,
          'contas'=>0, 'contas_ativas'=>0, 'contas_espera'=>0, 'contas_suspensas'=>0,
          'codigos'=>0, 'sem_dono'=>count($semDono), 'proximo'=>null];

    $r = @$conn->query("SELECT estado, COUNT(*) n FROM {$P}casamentos GROUP BY estado");
    if ($r) while ($x = $r->fetch_assoc()) {
        $G['casamentos'] += (int)$x['n'];
        if ($x['estado'] === 'ativo')    $G['ativos']    = (int)$x['n'];
        if ($x['estado'] === 'pendente') $G['pendentes'] = (int)$x['n'];
        if ($x['estado'] === 'suspenso') $G['suspensos'] = (int)$x['n'];
    }

    $vivos = soVivos($conn, 'c');
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}convites c WHERE c.casamento_id > 0 AND $vivos");
    if ($r) $G['convites'] = (int)$r->fetch_assoc()['n'];

    $r = @$conn->query("SELECT COUNT(*) tot,
                               SUM(CASE WHEN g.rsvp='confirmado' THEN 1 ELSE 0 END) conf,
                               COALESCE(SUM(g.presente),0) pres
                        FROM {$P}convidados g
                        JOIN {$P}convites c ON c.id = g.convite_id AND c.casamento_id = g.casamento_id
                        WHERE g.casamento_id > 0 AND $vivos");
    if ($r && ($x = $r->fetch_assoc())) {
        $G['pessoas']     = (int)$x['tot'];
        $G['confirmadas'] = (int)$x['conf'];
        $G['presentes']   = (int)$x['pres'];
    }

    $r = @$conn->query("SELECT estado, COUNT(*) n FROM {$P}utilizadores GROUP BY estado");
    if ($r) while ($x = $r->fetch_assoc()) {
        $G['contas'] += (int)$x['n'];
        if ($x['estado'] === 'ativo')    $G['contas_ativas']    = (int)$x['n'];
        if ($x['estado'] === 'pendente') $G['contas_espera']    = (int)$x['n'];
        if ($x['estado'] === 'suspenso') $G['contas_suspensas'] = (int)$x['n'];
    }

    // Portas abertas ao suporte, neste momento. É o número que um administrador
    // deve poder ver de relance: cada um destes é alguém de fora com acesso a
    // uma festa que não é sua.
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}suporte_codigos
                        WHERE casamento_id > 0 AND revogado_em IS NULL
                          AND (expira_em IS NULL OR expira_em > NOW())");
    if ($r) $G['codigos'] = (int)$r->fetch_assoc()['n'];

    $r = @$conn->query("SELECT nome, data_evento FROM {$P}casamentos
                        WHERE estado='ativo' AND data_evento >= CURDATE()
                        ORDER BY data_evento LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) $G['proximo'] = $x;
}

// Os arquivados saem de casamentosDoUtilizador() — é o que arquivar quer dizer.
// Mas alguém tem de os poder ver, senão arquivar era perdê-los: ficavam na base
// sem porta por onde voltar.
$arquivados = [];
if (ehAdminPlataforma()) {
    $r = @$conn->query("SELECT id, nome, data_evento FROM {$P}casamentos
                        WHERE estado='arquivado' ORDER BY nome");
    if ($r) $arquivados = $r->fetch_all(MYSQLI_ASSOC);
}

// A lista de cima é a das casas EM FUNCIONAMENTO. Um casamento por aprovar já
// tem o seu lugar na fila, um arquivado tem a sua secção, e um suspenso passa a
// ter a dele: misturados, a lista principal deixava de responder à pergunta que
// se lhe faz de manhã — em quantos casamentos é que estamos a trabalhar.
$suspensos = [];
foreach ($meus as $id => $c) {
    if (($c['estado'] ?? '') !== 'ativo') {
        if (($c['estado'] ?? '') === 'suspenso') $suspensos[$id] = $c;
        unset($meus[$id]);
    }
}

// Registos à espera de aprovação (só o admin da plataforma os despacha).
$pendentes = [];
if (ehAdminPlataforma()) {
    $r = @$conn->query("SELECT id, nome, noiva, noivo, criado_em FROM {$P}casamentos
                        WHERE estado='pendente' ORDER BY criado_em");
    if ($r) $pendentes = $r->fetch_all(MYSQLI_ASSOC);
}

// Sem casamento aberto não há casal a nomear — e ler as definições do
// casamento 0 devolvia o casal de origem do config.php, que não é de ninguém
// aqui. O cabeçalho, nesse caso, veste-se da casa.
$CAS = $aberto > 0 ? casalInfo(defsAtuais($conn))
                   : ['mono'=>PLATAFORMA['marca'], 'casal'=>PLATAFORMA['nome'], 'noiva'=>'', 'noivo'=>''];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Casamentos · Plataforma</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .cas-lista{ display:grid; gap:.7rem; }
  .cas{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem;
        display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center; }
  .cas.aberto{ border-color:var(--gold-soft); box-shadow:0 0 0 3px rgba(180,134,74,.14); }
  .cas .selo{ width:38px; height:38px; border-radius:10px; background:var(--cream); color:var(--forest);
              display:flex; align-items:center; justify-content:center; font-family:var(--serif);
              font-size:1rem; border:1px solid var(--line); }
  .cas.aberto .selo{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .cas .nm{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .cas .meta{ font-size:.8rem; color:#8a8f88; display:flex; gap:.7rem; flex-wrap:wrap; margin-top:.15rem; }
  .cas .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.ativo{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.pendente{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.suspenso{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .et.inativo{ background:var(--cream); color:#8a8f88; border-color:var(--line); }
  .et.agora{ background:var(--gold-pale); color:var(--ink); border-color:var(--gold-soft); }
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:.8rem; line-height:1.5; }

  /* Os painéis que dobram vivem em assets/estilo.css: são de duas páginas. */
  .cod{ font-family:ui-monospace,monospace; letter-spacing:.12em; }
  .falta{ color:var(--warn); font-weight:500; }
  .filtros{ display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .chip{ border:1px solid var(--line); background:#fff; color:#6c7570; border-radius:50px;
         padding:.3rem .8rem; font-size:.8rem; font-family:var(--sans); cursor:pointer; }
  .chip.on{ background:var(--forest); border-color:var(--forest); color:var(--ivory); }
  .editor-conta{ grid-column:1/-1; border-top:1px dashed var(--line); margin-top:.7rem; padding-top:.8rem; }
  .editor-conta .lf{ margin-top:.5rem; }
  .lugares{ display:flex; gap:.4rem; flex-wrap:wrap; margin:.5rem 0; }
  .lugar{ background:var(--cream); border:1px solid var(--line); border-radius:50px;
          padding:.15rem .3rem .15rem .7rem; font-size:.8rem; display:inline-flex; gap:.4rem; align-items:center; }
  .lugar button{ border:0; background:none; color:var(--danger); cursor:pointer; font-size:1rem; line-height:1; }
  .numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:.7rem; margin-bottom:.8rem; }
  .numeros .n{ background:#fff; border:1px solid var(--line); border-radius:12px;
               padding:.85rem 1rem; }
  .numeros .n b{ display:block; font-family:var(--serif); font-size:1.9rem; line-height:1;
                 color:var(--forest); }
  .numeros .n span{ display:block; font-size:.76rem; text-transform:uppercase;
                    letter-spacing:.05em; color:#8a8f88; margin-top:.35rem; }
  .numeros .n em{ display:block; font-style:normal; font-size:.76rem; color:#a2a8a2; margin-top:.2rem; }
  .numeros .n.alerta{ border-color:var(--gold-soft); background:var(--gold-pale); }
  .numeros .n.alerta b{ color:var(--gold); }
  /* Um número que leva a algum lado tem de o parecer. Os que são só contagem
     ficam como estavam — fingir que tudo é clicável é pior do que não o ser. */
  .numeros button.n{ font:inherit; text-align:left; cursor:pointer; transition:.15s; }
  .numeros button.n:hover{ border-color:var(--gold-soft); transform:translateY(-2px);
                           box-shadow:0 6px 16px rgba(180,134,74,.12); }
  .numeros button.n::after{ content:'ver →'; display:block; font-size:.72rem; color:var(--gold);
                            margin-top:.35rem; opacity:0; transition:.15s; }
  .numeros button.n:hover::after{ opacity:1; }

  /* ---- A linha de um casamento ----
     Dizia convites, pessoas e quando se lá mexeu. Faltava o que mais importa
     a quem gere casamentos: QUANDO é, e quantos já disseram que vêm. */
  .cas .quando{ font-variant-numeric:tabular-nums; }
  .cas .conta{ color:var(--gold); font-weight:500; }
  .cas .barra{ height:4px; border-radius:50px; background:var(--cream); overflow:hidden;
               margin-top:.45rem; max-width:260px; }
  .cas .barra i{ display:block; height:100%; background:var(--ok); }
  .cas .ac .btn{ white-space:nowrap; }
  /* O menu "⋯" vive em assets/estilo.css: é de duas páginas. */
  .linha-info{ display:flex; gap:1.2rem; flex-wrap:wrap; font-size:.84rem; color:#8a8f88;
               margin-bottom:1.4rem; padding:0 .2rem; }
  .segredo{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
            padding:.8rem .9rem; margin-top:.9rem; font-size:.88rem; line-height:1.6; }
  .lf{ display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:.7rem; align-items:end; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .cas{ grid-template-columns:auto 1fr; } .cas .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho($daCasa ? 'Administração' : 'Casamentos',
                $daCasa ? 'O estado do sistema e os casamentos que ele serve'
                        : 'Os casamentos a que tem acesso', 'plataforma'); ?>

<main class="container">

  <?php if ($G): ?>
    <?php // Os números que levam a algum lado são botões e levam mesmo lá:
          // um painel de contagens que não responde ao clique convida a
          // tentar e a não perceber porque nada aconteceu. ?>
    <div class="numeros">
      <button type="button" class="n" onclick="filtrarCasamentos('ativo',1)"
              title="Ver os casamentos ativos"><b><?= $G['ativos'] ?></b><span>casamentos ativos</span>
        <?php if ($G['pendentes']): ?><em><?= $G['pendentes'] ?> à espera</em><?php endif; ?></button>
      <div class="n"><b><?= $G['convites'] ?></b><span>convites</span>
        <em>em toda a casa</em></div>
      <div class="n"><b><?= $G['pessoas'] ?></b><span>pessoas convidadas</span>
        <em><?= $G['confirmadas'] ?> confirmaram</em></div>
      <div class="n"><b><?= $G['presentes'] ?></b><span>entradas registadas</span>
        <em>desde sempre</em></div>
      <?php if (ehAdminPlataforma()): ?>
        <button type="button" class="n" onclick="irPara('lista-contas')"
                title="Ver a lista de contas"><b><?= $G['contas_ativas'] ?></b><span>contas ativas</span>
          <em><?= $G['contas_espera'] ?> por aprovar · <?= $G['contas_suspensas'] ?> suspensas</em></button>
      <?php else: ?>
        <div class="n"><b><?= $G['contas_ativas'] ?></b><span>contas ativas</span>
          <em><?= $G['contas_espera'] ?> por aprovar · <?= $G['contas_suspensas'] ?> suspensas</em></div>
      <?php endif; ?>
      <div class="n<?= $G['codigos'] ? ' alerta' : '' ?>"><b><?= $G['codigos'] ?></b>
        <span>códigos de suporte de pé</span>
        <em><?= $G['codigos'] ? 'acesso de fora, agora' : 'nenhuma porta aberta' ?></em></div>
    </div>
    <div class="linha-info">
      <?php if ($G['proximo']): ?>
        <span>Próximo casamento: <b><?= escP($G['proximo']['nome']) ?></b>
          <?= escP(date('d/m/Y', strtotime($G['proximo']['data_evento']))) ?></span>
      <?php else: ?>
        <span>Nenhum casamento ativo com data marcada.</span>
      <?php endif; ?>
      <?php if ($G['sem_dono']): ?>
        <span class="falta"><?= $G['sem_dono'] ?> casamento(s) sem conta dos noivos</span>
      <?php endif; ?>
      <?php if ($G['suspensos']): ?>
        <a href="#" onclick="filtrarCasamentos('suspenso',1);return false"><?= $G['suspensos'] ?> suspenso(s)</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pendentes): ?>
    <div class="painel" style="border-color:var(--warn); border-left:4px solid var(--warn)">
      <h3><?= count($pendentes) ?> registo(s) à espera de aprovação</h3>
      <div class="dica">Um casal inscreveu-se e aguarda que lhe abram a porta. Até ser aprovado, não entra.</div>
      <div class="cas-lista">
        <?php foreach ($pendentes as $p): ?>
          <div class="cas">
            <div class="selo">?</div>
            <div>
              <div class="nm"><?= escP($p['nome']) ?></div>
              <div class="meta"><span>Inscrito em <?= escP(date('d/m/Y', strtotime($p['criado_em']))) ?></span></div>
            </div>
            <div class="ac">
              <button class="btn btn-ouro btn-sm" onclick="aprovar(<?= (int)$p['id'] ?>)">Aprovar</button>
              <button class="btn btn-sm" onclick="recusar(<?= (int)$p['id'] ?>)">Recusar</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (ehSuporte()): ?>
    <div class="painel">
      <h3>Entrar com um código do casal</h3>
      <div class="dica">O suporte não entra em casa de ninguém por direito próprio: é o casal
        que gera um código e o entrega. O código diz se pode só ver ou também corrigir,
        e o casal revoga-o quando quiser.</div>
      <div class="lf" style="grid-template-columns:1fr auto">
        <div><label>Código</label>
          <input type="text" id="s-codigo" placeholder="XXXXXXXX" autocapitalize="characters" spellcheck="false"></div>
        <div><button class="btn btn-ouro" onclick="entrarComCodigo()">Entrar</button></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($mandaNaCasa): ?>
    <details class="painel dobra" id="d-casamento">
      <summary><span class="mais">+</span> Novo casamento
        <small>criar um casamento à mão, já ativo</small></summary>
      <div class="dica">Cria o casamento já ativo, com os seus dados. A conta dos noivos liga-se a
        ele a seguir, em <b>Gestão</b>. O que ficar em branco fica no original e edita-se depois —
        mas o que se preencher aqui poupa o casal de mandar convites com a morada de outra pessoa.</div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr">
        <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Isabel &amp; Abednego"></div>
        <div><label>Noiva</label><input type="text" id="n-noiva" placeholder="Isabel"></div>
        <div><label>Noivo</label><input type="text" id="n-noivo" placeholder="Abednego"></div>
      </div>
      <div class="lf" style="grid-template-columns:repeat(4,1fr)">
        <div><label>Data</label><input type="date" id="n-data"></div>
        <div><label>Hora da festa</label><input type="time" id="n-hora"></div>
        <div><label>Convidados que espera</label><input type="number" id="n-convidados" min="1" max="5000"></div>
        <div><label>WhatsApp</label><input type="text" id="n-whatsapp" inputmode="numeric"></div>
      </div>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label>Local da festa</label><input type="text" id="n-local"></div>
        <div><label>Cidade / região</label><input type="text" id="n-cidade"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label>Orçamento total <small style="color:#8a8f88">· opcional, serve de teto no Orçamento</small></label>
          <input type="text" id="n-orcamento" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label>Local da festa · Google Maps</label>
          <input type="url" id="n-maps" data-mapa data-mapa-local="n-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="dica" style="margin:.9rem 0 .4rem">As cerimónias, se as houver.</div>
      <div class="lf" style="grid-template-columns:repeat(4,1fr)">
        <div><label>Civil · hora</label><input type="time" id="n-civil-hora"></div>
        <div><label>Civil · local</label><input type="text" id="n-civil-local"></div>
        <div><label>Religiosa · hora</label><input type="time" id="n-religiosa-hora"></div>
        <div><label>Religiosa · local</label><input type="text" id="n-religiosa-local"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label>Civil · Google Maps</label>
          <input type="url" id="n-civil-maps" data-mapa data-mapa-local="n-civil-local" placeholder="https://maps.app.goo.gl/…"></div>
        <div><label>Religiosa · Google Maps</label>
          <input type="url" id="n-religiosa-maps" data-mapa data-mapa-local="n-religiosa-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="fim" style="display:flex;gap:.6rem;align-items:center;margin-top:1rem">
        <button class="btn btn-ouro" onclick="criar()">Criar</button>
        <span class="dica" style="margin:0">Só os nomes são obrigatórios.</span>
      </div>
    </details>
  <?php endif; ?>

  <div class="painel" style="margin-top:1.4rem">
    <h3>Casamentos</h3>
    <div class="dica">Por ordem do que se mexeu por último — quem abre esta página de manhã
      quer ver em cima aquilo em que andou ontem, e não o mais antigo do sistema.</div>
    <div class="lf" style="grid-template-columns:1fr auto;margin-bottom:.7rem">
      <div><input type="search" id="q-cas" placeholder="Procurar casamento ou noivos…"
                  oninput="carregarCasamentos()"></div>
      <div></div>
    </div>
    <div class="filtros" id="filtros-cas">
      <?php foreach (['ativo'=>'Ativos','pendente'=>'Por aprovar','suspenso'=>'Suspensos',
                      'arquivado'=>'Arquivados','todos'=>'Todos'] as $k => $rot): ?>
        <button class="chip<?= $k === 'ativo' ? ' on' : '' ?>" data-estado="<?= $k ?>"
                onclick="filtrarCasamentos('<?= $k ?>')"><?= escP($rot) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="cas-lista" id="lista-casamentos"><div class="dica">A carregar…</div></div>
  </div>

  <?php if (ehAdminPlataforma()): ?>
    <div class="painel">
      <h3>Contas</h3>
      <div class="dica">Todas as contas do sistema. Uma conta <b>suspensa</b> não entra —
        e a mensagem que recebe é a mesma de senha errada, para quem tenta adivinhar
        não ficar a saber que a conta existe.</div>
      <details class="dobra dobra-dentro" id="d-conta">
      <summary><span class="mais">+</span> Nova conta
        <small>noivos, porteiro, suporte ou administração</small></summary>
      <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin-bottom:.2rem">
        <div><label>Nome</label><input type="text" id="c-nome" placeholder="Nome de quem usa a conta"></div>
        <div><label>Email</label><input type="email" id="c-email" autocapitalize="none" spellcheck="false"></div>
        <div><label>Tipo</label>
          <select id="c-tipo" onchange="tipoMudou()">
            <option value="noivos">Noivos</option>
            <option value="porteiro">Porteiro</option>
            <option value="suporte">Suporte</option>
            <option value="admin">Admin da plataforma</option>
          </select></div>
        <div><button class="btn btn-ouro" onclick="criarConta()">Criar conta</button></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr auto;margin-top:.4rem" id="linha-casamento">
        <div><label>Casamento a que fica ligada</label>
          <select id="c-casamento"><option value="">— nenhum, ligo depois —</option></select></div>
        <div></div>
      </div>
      <div class="dica" id="nota-tipo" style="margin:.4rem 0 .2rem">A senha é gerada aqui e mostrada uma vez.</div>
      </details>

      <div class="lf" style="grid-template-columns:1fr auto;margin:.8rem 0 .6rem;border:0;padding:0">
        <div><input type="search" id="q-conta" placeholder="Procurar por email ou nome…"
                    oninput="carregarContas()"></div>
        <div></div>
      </div>
      <div id="lista-contas"><div class="dica">A carregar…</div></div>
      <div class="segredo" id="senha-reposta" style="display:none"></div>
    </div>
  <details class="painel dobra" id="d-copia" style="margin-top:1.4rem">
      <summary><span class="mais">+</span> Cópia de segurança
        <small>levar a casa inteira num ficheiro, ou trazer casamentos de outro</small></summary>
      <div class="dica">Levar a casa inteira num ficheiro: todos os casamentos, com as suas fichas,
        convites, pessoas, mesas e desenhos, mais a lista de contas. Um casamento à parte
        descarrega-se abrindo-o e indo a <b>Gestão</b>.</div>
      <div class="lf" style="grid-template-columns:auto auto 1fr">
        <div><a class="btn" href="api.php?action=dados_exportar&amp;ambito=sistema">Descarregar tudo</a></div>
        <div><a class="btn" href="api.php?action=dados_exportar&amp;ambito=sistema&amp;senhas=1"
               onclick="return confirm('O ficheiro leva as senhas cifradas de todas as contas.\n\n'
                 + 'Guarde-o como guardaria a base de dados. Continuar?')">Descarregar com senhas</a></div>
        <div></div>
      </div>
      <div class="dica" style="margin:.7rem 0 0">Sem as senhas, o ficheiro serve para repor os dados
        mas não os acessos — quem lá estiver terá de receber senhas novas.</div>

      <div class="lf" style="grid-template-columns:1fr auto">
        <div><label for="sis-ficheiro">Trazer casamentos de um ficheiro</label>
          <input type="file" id="sis-ficheiro" accept=".json,application/json"></div>
        <div><button class="btn" onclick="importarSistema()">Criar como casamentos novos</button></div>
      </div>
      <div class="dica" style="margin:.5rem 0 0">Cria casamentos <b>novos</b>: não substitui nem
        mistura nada com o que já cá está. Para substituir um casamento em concreto, abra-o e use a
        página de Gestão.</div>
      <div class="segredo" id="sis-resultado" style="display:none"></div>
    </details>
  <?php endif; ?>
</main>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/maps-campo.js') ?>"></script>
<script src="<?= asset('assets/menu-mais.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script>
if (window.Moeda) window.Moeda.ligar('.campo-moeda');
// Esta página chamava toast() sem o ter: as mensagens de erro rebentavam em
// silêncio na consola, em vez de aparecerem a quem estava a olhar.
function toast(m, mau){
  const el = document.getElementById('toast');
  el.textContent = m; el.className = 'toast mostrar' + (mau ? ' erro' : '');
  setTimeout(() => el.className = 'toast', 2800);
}

async function abrir(id){
  const d = await api('casamento_abrir&id=' + id, { method:'POST' });
  if (d && d.success) location.href = 'index.php';
}
async function criar(){
  const v = id => (document.getElementById(id).value || '').trim();
  if (!v('n-nome') && !v('n-noiva') && !v('n-noivo')) {
    return toast('Indique ao menos os nomes dos noivos.', true);
  }
  const d = await api('casamento_criar', { method:'POST', body: JSON.stringify({
    nome: v('n-nome'), noiva: v('n-noiva'), noivo: v('n-noivo'), data: v('n-data'),
    hora: v('n-hora'), local: v('n-local'), cidade: v('n-cidade'), maps: v('n-maps'),
    convidados: v('n-convidados'), whatsapp: v('n-whatsapp'),
    orcamento_total: v('n-orcamento'),
    civil_hora: v('n-civil-hora'), civil_local: v('n-civil-local'), civil_maps: v('n-civil-maps'),
    religiosa_hora: v('n-religiosa-hora'), religiosa_local: v('n-religiosa-local'), religiosa_maps: v('n-religiosa-maps'),
  }) });
  if (d && d.success) location.reload();
}
async function aprovar(id){
  const d = await api('casamento_estado&id=' + id + '&estado=ativo', { method:'POST' });
  if (d && d.success) location.reload();
}
async function recusar(id){
  if (!confirm('Recusar este registo?\n\nO casal deixa de poder entrar. Nada se apaga.')) return;
  const d = await api('casamento_estado&id=' + id + '&estado=suspenso', { method:'POST' });
  if (d && d.success) location.reload();
}
async function entrarComCodigo(){
  const codigo = document.getElementById('s-codigo').value.trim();
  if (!codigo) return toast('Escreva o código que o casal lhe deu.', true);
  const d = await api('suporte_entrar', { method:'POST', body: JSON.stringify({ codigo }) });
  if (d && d.success) location.href = 'index.php';
}

// ---------- arquivar, reabrir, apagar ----------
const AVISO_ESTADO = {
  arquivado: 'Arquivar «%s»?\n\nSai das listas de trabalho, e as contas que só existem '
           + 'por causa dele ficam paradas — o casal deixa de entrar. Nada se apaga: '
           + 'reabrir devolve o casamento e as contas.',
  suspenso:  'Suspender «%s»?\n\nO casal deixa de entrar, e os convites deixam de abrir '
           + 'para os convidados. Nada se apaga.',
  ativo:     'Reabrir «%s»?\n\nVolta às listas e o casal volta a entrar.',
};
async function mudarEstado(id, estado, nome){
  if (!confirm(AVISO_ESTADO[estado].replace('%s', nome))) return;
  const d = await api('casamento_estado&id=' + id + '&estado=' + estado, { method:'POST' });
  if (!d || !d.success) return;
  // Dizer quantas contas foram atrás: quem arquiva tem de saber que fechou a
  // porta a pessoas, e não só a um casamento.
  if (d.contas_paradas)  toast(d.contas_paradas + ' conta(s) ficaram paradas com ele.');
  if (d.contas_ativadas) toast(d.contas_ativadas + ' conta(s) voltaram com ele.');
  setTimeout(() => location.reload(), d.contas_paradas || d.contas_ativadas ? 1600 : 0);
}
async function apagar(id, nome){
  // Escrever o nome, e não só carregar em "OK": é a única coisa aqui que não
  // se desfaz, e um clique distraído não deve chegar para a fazer.
  const escrito = prompt('Apagar «' + nome + '» DE VEZ?\n\n'
    + 'Vão-se os convites, as pessoas, as mesas, o desenho e o histórico. Não se desfaz.\n'
    + 'Se ainda quiser os dados, cancele e use «Levar os dados» primeiro.\n\n'
    + 'Para confirmar, escreva o nome do casamento:');
  if (escrito === null) return;
  if (escrito.trim() !== nome.trim()) return toast('O nome não confere. Nada foi apagado.', true);
  const d = await api('casamento_apagar&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  toast('Apagado: ' + (d.levou ? `${d.levou.convites} convite(s), ${d.levou.pessoas} pessoa(s).` : ''));
  setTimeout(() => location.reload(), 1500);
}

// ---------- trazer casamentos de um ficheiro ----------
async function importarSistema(){
  const f = document.getElementById('sis-ficheiro').files[0];
  if (!f) return toast('Escolha o ficheiro primeiro.', true);
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  if (!dados || dados.formato !== 'casamento-web/1') {
    return toast('Este ficheiro não é uma exportação deste sistema.', true);
  }
  const n = (dados.casamentos || []).length;
  if (!confirm(`Criar ${n} casamento(s) novo(s) a partir deste ficheiro?\n\n`
    + 'Nada do que já cá está é tocado.')) return;
  const d = await api('dados_importar', { method:'POST',
    body: JSON.stringify({ modo: 'novo', ficheiro: dados }) });
  if (!d || !d.success) return;
  const linhas = (d.resumo || []).map(r =>
    `<b>${esc(r.nome)}</b> — ${r.convites} convite(s), ${r.pessoas} pessoa(s), ${r.mesas} mesa(s)`
    + (r.codigos_trocados ? ` · ${r.codigos_trocados} código(s) trocado(s)` : ''));
  const cx = document.getElementById('sis-resultado');
  cx.style.display = '';
  cx.innerHTML = 'Criados:<br>' + linhas.join('<br>');
  setTimeout(() => location.reload(), 2500);
}

// ---------- os casamentos, por ordem de uso ----------
let ESTADO_CAS = 'ativo';
function filtrarCasamentos(e, saltar){
  ESTADO_CAS = e;
  document.querySelectorAll('#filtros-cas .chip').forEach(c =>
    c.classList.toggle('on', c.dataset.estado === e));
  carregarCasamentos();
  // Vindo de um número lá de cima, o filtro muda uma lista que está fora do
  // ecrã: sem levar o olho lá, parece que o clique não fez nada.
  if (saltar) irPara('lista-casamentos');
}
const quando = s => {
  if (!s) return 'nunca aberto';
  const d = new Date(s.replace(' ', 'T'));
  const dias = Math.floor((Date.now() - d) / 86400000);
  if (dias <= 0) return 'aberto hoje';
  if (dias === 1) return 'aberto ontem';
  if (dias < 30) return 'aberto há ' + dias + ' dias';
  return 'aberto em ' + s.slice(0, 10);
};
async function carregarCasamentos(){
  const alvo = document.getElementById('lista-casamentos');
  const q = (document.getElementById('q-cas').value || '').trim();
  const d = await api('casamento_lista&estado=' + ESTADO_CAS + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (!d || !d.success) return;
  if (!d.casamentos.length){
    alvo.innerHTML = '<div class="dica">Nenhum casamento aqui.</div>'; return;
  }
  alvo.innerHTML = d.casamentos.map(c => {
    const aberto = +c.id === +d.aberto;
    const arq = c.estado === 'arquivado';
    const n = esc(c.nome);
    // A ação principal fica sozinha e à vista; suspender, arquivar e apagar
    // vão para o "⋯". Ao mesmo peso, um clique distraído arquivava a festa de
    // alguém — e as três eram lidas como se fossem tão comuns como abrir.
    let principal = '';
    if (aberto) principal = '<a class="btn btn-ouro btn-sm" href="index.php">Continuar</a>';
    else if (!arq) principal = `<button class="btn btn-ouro btn-sm" onclick="abrir(${c.id})">Abrir</button>`;

    const mais = [];
    // Sair sem ir embora: quem responde pela casa entra e sai de casamentos
    // alheios o dia todo, e a única saída era abrir outro ou terminar sessão.
    if (aberto) mais.push(`<button onclick="fecharCasamento()">Sair deste casamento</button>`);
    if (MANDA_NA_CASA) {
      if (arq) {
        mais.push(`<button onclick="mudarEstado(${c.id},'ativo','${n}')">Reabrir</button>`);
        mais.push(`<a href="api.php?action=dados_exportar&ambito=casamento&id=${c.id}">Levar os dados</a>`);
        mais.push('<hr>');
        mais.push(`<button class="perigo" onclick="apagar(${c.id},'${n}')">Apagar para sempre</button>`);
      } else {
        mais.push('<hr>');
        mais.push(c.estado === 'suspenso'
          ? `<button onclick="mudarEstado(${c.id},'ativo','${n}')">Reativar</button>`
          : `<button class="perigo" onclick="mudarEstado(${c.id},'suspenso','${n}')">Suspender</button>`);
        mais.push(`<button class="perigo" onclick="mudarEstado(${c.id},'arquivado','${n}')">Arquivar</button>`);
      }
    }
    const menu = mais.length
      ? `<span class="mm"><button class="btn btn-sm" title="Mais ações"
             onclick="abrirMais(event,${c.id})"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
           <span class="mm-pop" id="mm-${c.id}" style="display:none">${mais.join('')}</span></span>`
      : '';

    // Confirmações: um casamento com 200 pessoas e 3 confirmadas é outra
    // notícia que um com 200 e 180, e a lista dizia os dois da mesma maneira.
    const pes = +c.pessoas || 0, conf = +c.confirmados || 0;
    const pc = pes ? Math.round(conf / pes * 100) : 0;

    return `<div class="cas${aberto ? ' aberto' : ''}">
      <div class="selo">${esc((c.nome || '?').slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(c.nome)}
          ${aberto ? '<span class="et agora">aberto agora</span>' : ''}
          <span class="et ${esc(c.estado)}">${esc(c.estado)}</span></div>
        <div class="meta">
          ${dataCasamento(c.data_evento)}
          <span><b>${c.convites}</b> convites</span>
          <span><b>${pes}</b> pessoas${pes ? ` · <b>${conf}</b> confirmaram` : ''}</span>
          <span>${esc(quando(c.ultimo_acesso))}</span>
          ${+c.donos === 0 ? '<span class="falta">sem conta dos noivos</span>' : ''}
        </div>
        ${pes ? `<div class="barra" title="${conf} de ${pes} confirmaram (${pc}%)"><i style="width:${pc}%"></i></div>` : ''}
      </div>
      <div class="ac">${principal}${menu}</div>
    </div>`;
  }).join('');
}

/** A data do casamento, e quanto falta — que é o que se quer saber primeiro. */
function dataCasamento(iso){
  if (!iso || iso === '0000-00-00') return '<span style="color:#b0b5af">sem data</span>';
  const d = new Date(iso + 'T12:00:00');
  if (isNaN(d)) return '';
  const hoje = new Date(); hoje.setHours(12,0,0,0);
  const dias = Math.round((d - hoje) / 86400000);
  const data = d.toLocaleDateString('pt-PT', { day:'2-digit', month:'short', year:'numeric' });
  let falta;
  if (dias > 1)       falta = `<span class="conta">faltam ${dias} dias</span>`;
  else if (dias === 1) falta = '<span class="conta">é amanhã</span>';
  else if (dias === 0) falta = '<span class="conta">é hoje</span>';
  else                falta = `<span style="color:#b0b5af">há ${Math.abs(dias)} dias</span>`;
  return `<span class="quando">${esc(data)}</span> ${falta}`;
}

/* O menu "⋯" (abrirMais) vive em assets/menu-mais.js: era o mesmo código aqui
   e em modelos.php, e uma correção feita num sítio não chegava ao outro. */

/** Leva o olho até um sítio da página e assinala-o por um instante. */
function irPara(id){
  const el = document.getElementById(id); if (!el) return;
  el.scrollIntoView({ behavior:'smooth', block:'center' });
}
async function fecharCasamento(){
  const d = await api('casamento_fechar', { method:'POST' });
  if (d && d.success) location.reload();
}

// ---------- contas (só o admin da plataforma) ----------
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const $ = id => document.getElementById(id);
let CONTAS = {};   // as contas já carregadas, para o editor não as ir procurar outra vez
// Uma conta de suporte não se prende a casamento nenhum: entra com o código
// que o casal gerar. Dizê-lo aqui evita a pergunta de quem tenta ligá-la a um.
function tipoMudou(){
  const tipo = $('c-tipo').value;
  const preso = (tipo === 'noivos' || tipo === 'porteiro');
  $('linha-casamento').style.display = preso ? '' : 'none';
  $('nota-tipo').innerHTML = {
    noivos:   'Gere um casamento inteiro. A senha é gerada aqui e mostrada uma vez.',
    porteiro: 'Só a porta do casamento: procurar convites e registar entradas.',
    suporte:  '<b>Não se liga a casamento nenhum.</b> Entra com o código que o casal gerar — '
            + 'é esse código que lhe abre a porta e diz se pode ver ou também corrigir.',
    admin:    'Responde pela casa: vê todos os casamentos, aprova registos e gere contas.',
  }[tipo];
}
async function encherCasamentos(){
  const d = await api('casamento_lista&estado=todos');
  if (!d || !d.success) return;
  $('c-casamento').innerHTML = '<option value="">— nenhum, ligo depois —</option>'
    + d.casamentos.map(c => `<option value="${c.id}">${esc(c.nome)}</option>`).join('');
}
async function criarConta(){
  const tipo = $('c-tipo').value;
  const email = $('c-email').value.trim();
  if (!email) return toast('Indique o email.', true);
  const senha = 'tmp' + Math.random().toString(36).slice(2, 10);
  const corpo = { email, nome: $('c-nome').value.trim(), senha };
  if (tipo === 'suporte' || tipo === 'admin') corpo.papel_plataforma = tipo;
  else { corpo.papel = tipo; corpo.casamento_id = +$('c-casamento').value || 0; }
  const d = await api('utilizador_criar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  $('c-nome').value = $('c-email').value = '';
  const cx = $('senha-reposta');
  cx.style.display = '';
  cx.innerHTML = `Conta criada para <b>${esc(d.email)}</b>. Senha: <b class="cod">${esc(senha)}</b><br>
    Entregue-lha agora — não volta a aparecer. Ela deve mudá-la em Gestão.`;
  carregarContas();
}

async function carregarContas(){
  const alvo = document.getElementById('lista-contas');
  if (!alvo) return;
  const q = (document.getElementById('q-conta').value || '').trim();
  const d = await api('utilizador_lista' + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (!d || !d.success) return;
  CONTAS = {};
  d.contas.forEach(c => { CONTAS[c.id] = c; });
  if (!d.contas.length){ alvo.innerHTML = '<div class="dica">Nenhuma conta corresponde.</div>'; return; }
  alvo.innerHTML = d.contas.map(c => {
    const eu = +c.id === +d.eu;
    const nome = c.nome || c.email;
    const plat = c.papel_plataforma ? `<span class="et agora">${esc(c.papel_plataforma)} da plataforma</span>` : '';
    const trocaEstado = c.estado === 'ativo' ? 'suspenso' : 'ativo';
    // 'inativo' quer dizer "o casamento dela foi arquivado", e não "alguém a
    // fechou". Sem o dizer, a lista parecia ter contas avariadas.
    const porque = c.estado === 'inativo'
      ? ' <span class="meta">parada com o casamento arquivado</span>' : '';
    // Como na lista de casamentos: editar à frente, e o que estraga atrás do
    // "⋯". Repor a senha de alguém e apagar-lhe a conta não são gestos que
    // devam estar a um clique de distância do que se faz todos os dias.
    const acoes = `<button class="btn btn-sm" onclick="editarConta(${c.id})">Editar</button>`
      + (eu ? '<span class="meta">é você</span>' : `
      <span class="mm"><button class="btn btn-sm" title="Mais ações"
            onclick="abrirMais(event,'c${c.id}')"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
        <span class="mm-pop" id="mm-c${c.id}" style="display:none">
          <button onclick="reporSenha(${c.id}, '${esc(c.email)}')">Repor senha</button>
          <hr>
          <button class="${c.estado === 'ativo' ? 'perigo' : ''}" onclick="estadoConta(${c.id}, '${trocaEstado}')">
            ${c.estado === 'ativo' ? 'Suspender' : 'Ativar'}</button>
          <button class="perigo" onclick="apagarConta(${c.id}, '${esc(c.email)}')">Apagar</button>
        </span></span>`);
    return `<div class="cas">
      <div class="selo">${esc(nome.slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(nome)} <span class="et ${esc(c.estado)}">${esc(c.estado)}</span> ${plat}${porque}</div>
        <div class="meta"><span>${esc(c.email)}</span>
          <span>${c.casamentos} casamento(s)</span>
          <span>${c.ultimo_acesso ? 'último acesso ' + esc(c.ultimo_acesso.slice(0,10)) : 'nunca entrou'}</span></div>
      </div>
      <div class="ac">${acoes}</div>
      <div class="editor-conta" id="ed-${c.id}" style="display:none"></div>
    </div>`;
  }).join('');
}

// ---------- editar uma conta, e os casamentos onde tem lugar ----------
async function editarConta(id){
  const cx = document.getElementById('ed-' + id);
  if (cx.style.display !== 'none'){ cx.style.display = 'none'; return; }
  cx.style.display = '';
  cx.innerHTML = '<div class="dica">A carregar…</div>';
  const [lug, cas] = await Promise.all([
    api('utilizador_casamentos&id=' + id), api('casamento_lista&estado=todos'),
  ]);
  const c = CONTAS[id] || {};
  const tipo = c.papel_plataforma || 'casamento';
  const preso = tipo === 'casamento';
  cx.innerHTML = `
    <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin:0">
      <div><label>Nome</label><input type="text" id="e-nome-${id}" value="${esc(c.nome || '')}"></div>
      <div><label>Email</label><input type="email" id="e-email-${id}" value="${esc(c.email || '')}"></div>
      <div><label>Tipo</label>
        <select id="e-tipo-${id}">
          <option value="casamento"${preso ? ' selected' : ''}>Conta de casamento</option>
          <option value="suporte"${tipo === 'suporte' ? ' selected' : ''}>Suporte</option>
          <option value="admin"${tipo === 'admin' ? ' selected' : ''}>Admin da plataforma</option>
        </select></div>
      <div><button class="btn btn-ouro btn-sm" onclick="guardarConta(${id})">Guardar</button></div>
    </div>
    ${tipo === 'suporte' ? `<div class="dica" style="margin:.6rem 0 0">Conta de suporte:
       entra nos casamentos com o código que cada casal gerar, e não por lugar próprio.</div>`
    : `<div class="lugares" id="lug-${id}">${
        (lug.acessos || []).length
          ? (lug.acessos || []).map(a => `<span class="lugar">${esc(a.nome)} · ${esc(a.papel)}
              <button title="Tirar" onclick="tirarLugar(${id},${a.casamento_id})">×</button></span>`).join('')
          : '<span class="dica">Sem lugar em casamento nenhum.</span>'}</div>
      <div class="lf" style="grid-template-columns:2fr 1fr auto;margin-top:.4rem">
        <div><select id="nl-cas-${id}">${(cas.casamentos || []).map(w =>
              `<option value="${w.id}">${esc(w.nome)}</option>`).join('')}</select></div>
        <div><select id="nl-papel-${id}"><option value="noivos">Noivos</option><option value="porteiro">Porteiro</option></select></div>
        <div><button class="btn btn-sm" onclick="darLugar(${id})">Dar lugar</button></div>
      </div>`}`;
}
async function guardarConta(id){
  const tipo = document.getElementById('e-tipo-' + id).value;
  const d = await api('utilizador_editar', { method:'POST', body: JSON.stringify({
    id, nome: document.getElementById('e-nome-' + id).value.trim(),
    email: document.getElementById('e-email-' + id).value.trim(),
    papel_plataforma: tipo === 'casamento' ? '' : tipo,
  }) });
  if (d && d.success){ toast('Conta guardada.'); carregarContas(); }
}
async function darLugar(id){
  const d = await api('acesso_dar&utilizador=' + id
    + '&casamento=' + document.getElementById('nl-cas-' + id).value
    + '&papel=' + document.getElementById('nl-papel-' + id).value, { method:'POST' });
  if (d && d.success){ document.getElementById('ed-' + id).style.display = 'none'; editarConta(id); }
}
async function tirarLugar(id, casamento){
  const d = await api('acesso_tirar_de&utilizador=' + id + '&casamento=' + casamento, { method:'POST' });
  if (d && d.success){ document.getElementById('ed-' + id).style.display = 'none'; editarConta(id); }
}
async function apagarConta(id, email){
  if (!confirm('Apagar a conta de ' + email + '?\n\nNão se desfaz. Se ela ainda tiver lugar '
    + 'nalgum casamento, tire-lho primeiro em Editar.')) return;
  const d = await api('utilizador_apagar&id=' + id, { method:'POST' });
  if (d && d.success){ toast('Conta apagada.'); carregarContas(); }
}
async function estadoConta(id, estado){
  if (estado === 'suspenso' && !confirm('Suspender esta conta?\n\nDeixa de entrar até ser reativada.')) return;
  const d = await api('utilizador_estado&id=' + id + '&estado=' + estado, { method:'POST' });
  if (d && d.success) carregarContas();
}
async function reporSenha(id, email){
  if (!confirm('Repor a senha de ' + email + '?\n\nA senha atual deixa de servir. A nova aparece aqui, uma vez.')) return;
  const d = await api('utilizador_repor_senha&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  const cx = document.getElementById('senha-reposta');
  cx.style.display = '';
  cx.innerHTML = `Senha nova de <b>${esc(d.email)}</b>: <b class="cod">${esc(d.senha)}</b><br>
    Entregue-lha agora — não volta a aparecer. Ela deve mudá-la na página Gestão.`;
}
carregarContas();

// ---------- arranque ----------
const MANDA_NA_CASA = <?= $mandaNaCasa ? 'true' : 'false' ?>;
carregarCasamentos();
carregarContas();
if (MANDA_NA_CASA){ encherCasamentos(); tipoMudou(); }
</script>
</body>
</html>
