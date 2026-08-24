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

// Antes de desenhar as listas, fecha-se a porta às licenças vencidas: os
// casamentos expirados passam a suspensos (e as contas deles param), para a
// página mostrar já o estado verdadeiro — é aqui que a suspensão automática se
// torna visível a quem responde pela casa.
if (ehPessoalPlataforma()) suspenderLicencasExpiradas($conn);

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
// O painel de estado da casa é do ADMIN. O suporte não tem aqui um posto de
// comando: entra num casamento com o código que o casal lhe der, e o que
// precisa de ver é isso — não o retrato de toda a casa.
$G = null;
if ($mandaNaCasa) {
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

  /* ---- Verificações de preenchimento (as mesmas do formulário público) ----
     Um campo com erro acende a vermelho e diz o que falta, logo por baixo. */
  .campo{ display:flex; flex-direction:column; }
  .campo input, .campo select{ transition:border-color .15s, box-shadow .15s; }
  .campo.mau input, .campo.mau select{ border-color:var(--danger); }
  .campo.mau input:focus, .campo.mau select:focus{ box-shadow:0 0 0 3px rgba(165,71,63,.15); }
  .campo.ok input:not(:focus){ border-color:#bcd6c4; }
  .err{ display:none; color:var(--danger); font-size:.77rem; margin-top:.34rem; line-height:1.45; }
  .campo.mau .err{ display:block; }
  /* Palavra-passe: o olho para mostrar, e a força ao lado. O campo reserva
     espaço à direita para o botão não passar por cima do que se escreve. */
  .pw-wrap{ position:relative; }
  .pw-wrap input{ padding-right:4.6rem; }
  .pw-olho{ position:absolute; right:.5rem; top:50%; transform:translateY(-50%); border:0; background:none;
            cursor:pointer; color:#9aa09a; font-size:.74rem; padding:.2rem .3rem; }
  .pw-olho:hover{ color:var(--forest); }
  .pw-forca{ display:flex; align-items:center; gap:.5rem; margin-top:.4rem; }
  .pw-barras{ display:flex; gap:3px; flex:1; }
  .pw-barras i{ height:4px; flex:1; border-radius:50px; background:var(--cream); transition:background .2s; }
  .pw-forca.f1 i:nth-child(1){ background:var(--danger); }
  .pw-forca.f2 i:nth-child(-n+2){ background:var(--warn); }
  .pw-forca.f3 i:nth-child(-n+3){ background:#9a9a3c; }
  .pw-forca.f4 i{ background:var(--ok); }
  .pw-rot{ font-size:.72rem; color:#9aa09a; white-space:nowrap; min-width:4.5rem; text-align:right; }

  /* O aviso de estado da licença, no modal, veste-se do que diz. */
  .lic-estado.ok{ background:var(--ok-bg); border-color:var(--ok); border-style:solid; }
  .lic-estado.warn{ background:var(--warn-bg); border-color:var(--warn); border-style:solid; }
  .lic-estado.danger{ background:var(--danger-bg); border-color:var(--danger); border-style:solid; }
  /* No modal, os campos empilham-se em ecrã estreito. */
  @media (max-width:560px){ .modal-corpo .lf{ grid-template-columns:1fr; } }
  /* Os separadores de secção do editor completo. */
  .ed-sec{ font-family:var(--serif); color:var(--forest); font-size:1.02rem; margin:1.2rem 0 .5rem;
           padding-top:.9rem; border-top:1px solid var(--line); }
  .ed-sec:first-of-type{ border-top:0; padding-top:0; margin-top:.2rem; }
  .ed-conta{ border:1px solid var(--line); border-radius:12px; padding:.7rem .8rem; margin-bottom:.6rem; }
  .ed-conta .cab{ display:flex; align-items:center; gap:.5rem; margin-bottom:.5rem; }
  .ed-conta .ac{ display:flex; gap:.4rem; flex-wrap:wrap; }
  /* As caixas de seleção do painel de dados (âmbitos e casamentos). */
  .dsel{ display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:.35rem .9rem; margin:.4rem 0 .9rem; }
  .dsel label{ display:flex; gap:.5rem; align-items:center; font-size:.9rem; color:var(--ink); cursor:pointer; }
  .dsel input{ width:auto; margin:0; accent-color:var(--forest); flex:none; }
  .dsel-cas{ max-height:230px; overflow:auto; border:1px solid var(--line); border-radius:10px;
             padding:.6rem .8rem; background:var(--cream); }
</style>
</head>
<body>
<?php
  // O título veste-se de quem chega: o admin vê a casa toda; o suporte tem um
  // posto simples, à volta do código; o casal vê os seus casamentos.
  if ($mandaNaCasa)      { $tit = 'Administração'; $sub = 'O estado do sistema e os casamentos que ele serve'; }
  elseif (ehSuporte())   { $tit = 'Suporte';       $sub = 'Entre num casamento com o código que o casal lhe der'; }
  else                   { $tit = 'Casamentos';    $sub = 'Os casamentos a que tem acesso'; }
  cabecalho($tit, $sub, 'plataforma');
?>

<main class="container">

<?php if (ehSuporte()): ?>
  <?php // ---- O posto do suporte: simples e ao que interessa ----
        // Nada de painel de estado da casa. O suporte não abre casamentos por
        // direito próprio: entra com o código que o casal gera e entrega, e
        // trata da sua própria conta. É só isto que precisa de ver. ?>
  <div class="painel">
    <h3>Entrar com um código do casal</h3>
    <div class="dica">O suporte não entra em casa de ninguém por direito próprio: é o casal
      que gera um código e o entrega. O código diz se pode só ver ou também corrigir,
      e o casal revoga-o quando quiser.</div>
    <div class="lf" style="grid-template-columns:1fr auto">
      <div><label for="s-codigo">Código</label>
        <input type="text" id="s-codigo" placeholder="XXXXXXXX" autocapitalize="characters"
               spellcheck="false" onkeydown="if(event.key==='Enter')entrarComCodigo()"></div>
      <div><button class="btn btn-ouro" onclick="entrarComCodigo()">Entrar</button></div>
    </div>
  </div>

  <div class="painel">
    <h3>A minha conta</h3>
    <div class="dica">Entrou como <b><?= escP(utilizadorAtual() ?? '') ?></b>. Aqui muda a sua
      própria palavra-passe — mais nada. Pelo menos 8 caracteres.</div>
    <div class="lf" style="grid-template-columns:1fr 1fr auto">
      <div><label for="sp-atual">Palavra-passe atual</label>
        <input type="password" id="sp-atual" autocomplete="current-password"></div>
      <div><label for="sp-nova">Nova palavra-passe</label>
        <input type="password" id="sp-nova" autocomplete="new-password"></div>
      <div><button class="btn" onclick="mudarMinhaSenha()">Mudar palavra-passe</button></div>
    </div>
  </div>

  <div class="toast" id="toast"></div>
  <script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
  <script src="<?= asset('assets/api.js') ?>"></script>
  <script>
  function toast(m, mau){
    const el = document.getElementById('toast');
    el.textContent = m; el.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(() => el.className = 'toast', 2800);
  }
  async function entrarComCodigo(){
    const codigo = document.getElementById('s-codigo').value.trim();
    if (!codigo) return toast('Escreva o código que o casal lhe deu.', true);
    const d = await api('suporte_entrar', { method:'POST', body: JSON.stringify({ codigo }) });
    if (d && d.success) location.href = 'index.php';
  }
  async function mudarMinhaSenha(){
    const atual = document.getElementById('sp-atual').value;
    const nova  = document.getElementById('sp-nova').value;
    if (!atual || !nova) return toast('Preencha as duas palavras-passe.', true);
    if (nova.length < 8)  return toast('A nova precisa de pelo menos 8 caracteres.', true);
    const d = await api('senha_mudar', { method:'POST', body: JSON.stringify({ atual, nova }) });
    if (d && d.success){
      document.getElementById('sp-atual').value = document.getElementById('sp-nova').value = '';
      toast('Palavra-passe mudada.');
    }
  }
  </script>
</main>
</body>
</html>
<?php return; // o suporte não vê nada do painel de administração abaixo ?>
<?php endif; ?>

  <?php // As pastilhas que comandam a página: os casamentos, criar um novo, e as
        // contas administrativas. As duas últimas são do admin da casa. ?>
  <div class="filtros vista-chips" id="vista-chips" style="margin-bottom:1.2rem">
    <button class="chip on" data-vista="casamentos" onclick="verVista('casamentos')">Casamentos</button>
    <?php if ($mandaNaCasa): ?>
      <button class="chip" data-vista="novo" onclick="verVista('novo')">&#43; Novo casamento</button>
    <?php endif; ?>
    <?php if (ehAdminPlataforma()): ?>
      <button class="chip" data-vista="contas" onclick="verVista('contas')">Contas administrativas</button>
      <button class="chip" data-vista="dados" onclick="verVista('dados')">Dados e reposição</button>
    <?php endif; ?>
  </div>

  <div id="vista-casamentos">
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
        <button type="button" class="n" onclick="verVista('contas')"
                title="Ver as contas administrativas"><b><?= $G['contas_ativas'] ?></b><span>contas ativas</span>
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


  <div class="painel" style="margin-top:1.4rem">
    <h3>Casamentos</h3>
    <div class="dica">Por ordem do que se mexeu por último — quem abre esta página de manhã
      quer ver em cima aquilo em que andou ontem, e não o mais antigo do sistema.</div>
    <div class="lf" style="grid-template-columns:1fr auto;margin-bottom:.7rem">
      <div><input type="search" id="q-cas" placeholder="Procurar casamento ou noivos…"
                  autocomplete="off" name="procurar-casamento" oninput="carregarCasamentos()"></div>
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
  </div><!-- /vista-casamentos -->

  <?php if ($mandaNaCasa): ?>
  <div id="vista-novo" style="display:none">
    <div class="painel">
      <h3>Novo casamento</h3>
      <div class="dica">Cria o casamento já ativo, com os seus dados e, se quiser, já com as contas
        dos noivos e do porteiro. O que ficar em branco fica no original e edita-se depois.</div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr">
        <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Isabel &amp; Abednego"></div>
        <div><label>Noiva</label><input type="text" id="n-noiva" placeholder="Isabel" oninput="sugerirPorteiro()"></div>
        <div><label>Noivo</label><input type="text" id="n-noivo" placeholder="Abednego" oninput="sugerirPorteiro()"></div>
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

      <div class="dica" style="margin:1.1rem 0 .4rem"><b>A licença de uso.</b> Quanto tempo o casamento
        fica disponível. Expirada, é suspenso sozinho.</div>
      <div class="lf" style="grid-template-columns:1fr 1fr auto;align-items:center">
        <div><label>Período de licença</label>
          <select id="n-licenca" onchange="licencaMudou()">
            <option value="0">Sem limite</option>
            <option value="3">3 meses</option>
            <option value="6" selected>6 meses</option>
            <option value="12">12 meses</option>
            <option value="outro">Outro…</option>
          </select></div>
        <div id="n-licenca-outro" style="display:none"><label>Meses</label>
          <input type="number" id="n-licenca-meses" min="1" max="120" placeholder="ex.: 18"></div>
        <div><label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400">
          <input type="checkbox" id="n-licenca-ativa" checked style="width:auto;margin:0">
          Iniciar com a licença já ativa</label></div>
      </div>

      <div class="dica" style="margin:1.1rem 0 .4rem"><b>Conta dos noivos</b> <small style="color:#8a8f88">· opcional — quem gere o casamento. Deixe em branco para não criar já.</small></div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr;align-items:start">
        <div class="campo"><label for="n-noivos-email">Email dos noivos</label>
          <input type="email" id="n-noivos-email" autocomplete="off" autocapitalize="none" spellcheck="false">
          <div class="err"></div></div>
        <div class="campo"><label for="n-noivos-senha">Palavra-passe</label>
          <div class="pw-wrap"><input type="password" id="n-noivos-senha" autocomplete="new-password" spellcheck="false" placeholder="em branco = gerada">
            <button type="button" class="pw-olho" id="olho-ne" onclick="verSenha('n-noivos-senha','olho-ne')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="pw-forca" id="forca-ne" aria-hidden="true"><span class="pw-barras"><i></i><i></i><i></i><i></i></span><span class="pw-rot" id="rot-ne"></span></div>
          <div class="err"></div></div>
        <div class="campo"><label for="n-noivos-confirmar">Repetir</label>
          <div class="pw-wrap"><input type="password" id="n-noivos-confirmar" autocomplete="new-password" spellcheck="false" placeholder="repita a palavra-passe">
            <button type="button" class="pw-olho" id="olho-nc" onclick="verSenha('n-noivos-confirmar','olho-nc')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="err"></div></div>
      </div>
      <div class="dica" style="margin:.9rem 0 .4rem"><b>Conta do porteiro</b> <small style="color:#8a8f88">· opcional — regista as entradas</small></div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr;align-items:start">
        <div class="campo"><label for="n-porteiro-email">Email (utilizador) do porteiro</label>
          <input type="email" id="n-porteiro-email" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="porta-…">
          <div class="err"></div></div>
        <div class="campo"><label for="n-porteiro-senha">Palavra-passe</label>
          <div class="pw-wrap"><input type="password" id="n-porteiro-senha" autocomplete="new-password" spellcheck="false" placeholder="em branco = gerada">
            <button type="button" class="pw-olho" id="olho-pe" onclick="verSenha('n-porteiro-senha','olho-pe')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="pw-forca" id="forca-pe" aria-hidden="true"><span class="pw-barras"><i></i><i></i><i></i><i></i></span><span class="pw-rot" id="rot-pe"></span></div>
          <div class="err"></div></div>
        <div class="campo"><label for="n-porteiro-confirmar">Repetir</label>
          <div class="pw-wrap"><input type="password" id="n-porteiro-confirmar" autocomplete="new-password" spellcheck="false" placeholder="repita a palavra-passe">
            <button type="button" class="pw-olho" id="olho-pc" onclick="verSenha('n-porteiro-confirmar','olho-pc')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="err"></div></div>
      </div>

      <div class="fim" style="display:flex;gap:.6rem;align-items:center;margin-top:1.1rem">
        <button class="btn btn-ouro" onclick="criar()">Criar casamento</button>
        <span class="dica" style="margin:0">Só os nomes são obrigatórios. As senhas geradas mostram-se uma vez.</span>
      </div>
      <div class="segredo" id="n-resultado" style="display:none"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (ehAdminPlataforma()): ?>
    <div id="vista-contas" style="display:none">
    <div class="painel">
      <h3>Contas administrativas</h3>
      <div class="dica">As contas de <b>administração</b> e <b>suporte</b> da casa. As contas de
        noivos e porteiro vivem em cada casamento — abra o casamento e veja-as em <b>Gestão</b>.
        Uma conta <b>suspensa</b> não entra, e recebe a mesma mensagem de senha errada.</div>
      <details class="dobra dobra-dentro" id="d-conta">
      <summary><span class="mais">+</span> Nova conta administrativa
        <small>suporte ou administração da plataforma</small></summary>
      <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin-bottom:.2rem;align-items:start">
        <div class="campo"><label for="c-nome">Nome</label>
          <input type="text" id="c-nome" placeholder="Nome de quem usa a conta"><div class="err"></div></div>
        <div class="campo"><label for="c-email">Email</label>
          <input type="email" id="c-email" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="err"></div></div>
        <div><label for="c-tipo">Tipo</label>
          <select id="c-tipo" onchange="tipoMudou()">
            <option value="suporte">Suporte</option>
            <option value="admin">Admin da plataforma</option>
          </select></div>
        <div><button class="btn btn-ouro" onclick="criarConta()">Criar conta</button></div>
      </div>
      <div class="dica" id="nota-tipo" style="margin:.4rem 0 .2rem">A senha é gerada aqui e mostrada uma vez.</div>
      </details>

      <div class="lf" style="grid-template-columns:1fr auto;margin:.8rem 0 .6rem;border:0;padding:0">
        <div><input type="search" id="q-conta" placeholder="Procurar por email ou nome…"
                    autocomplete="off" name="procurar-conta" oninput="carregarContas()"></div>
        <div></div>
      </div>
      <div id="lista-contas"><div class="dica">A carregar…</div></div>
      <div class="segredo" id="senha-reposta" style="display:none"></div>
    </div>
    </div><!-- /vista-contas -->

    <div id="vista-dados" style="display:none">
    <div class="painel">
      <h3>Dados e reposição</h3>
      <div class="dica">Um sítio só para levar a casa num ficheiro, trazê-la de volta, ou repor
        de fábrica — sempre <b>só o que assinalar</b>. Escolha os âmbitos e, para os casamentos,
        quais; depois <b>Exportar</b>, <b>Importar</b> ou <b>Repor de fábrica</b>.</div>

      <h4 class="ed-sec">Âmbitos</h4>
      <div class="dsel" id="dados-inc">
        <label><input type="checkbox" value="casamentos" checked onchange="dadosCasToggle()"> Casamentos</label>
        <label><input type="checkbox" value="modelos"> Modelos da casa</label>
        <label><input type="checkbox" value="contas_casamento"> Contas de casamento <small style="color:#8a8f88">· noivos e porteiros</small></label>
        <label><input type="checkbox" value="contas_admin"> Contas administrativas <small style="color:#8a8f88">· admin e suporte</small></label>
        <label><input type="checkbox" id="dados-senhas"> Contas <b>com senhas</b> <small style="color:#8a8f88">· só na exportação</small></label>
      </div>

      <div id="dados-cas-bloco">
        <div class="dica" style="display:flex;gap:.6rem;align-items:center;margin:.2rem 0 .4rem;flex-wrap:wrap">
          <span>Quais casamentos? <small>Vazio = todos, na exportação.</small></span>
          <button class="btn btn-sm" onclick="dadosCasTodos(true)">Todos</button>
          <button class="btn btn-sm" onclick="dadosCasTodos(false)">Nenhum</button>
        </div>
        <div class="dsel dsel-cas" id="dados-cas-lista"><div class="dica">A carregar…</div></div>
      </div>

      <div class="fim" style="flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-ouro" onclick="exportarDados()">Exportar selecionados</button>
        <button class="btn" onclick="document.getElementById('dados-ficheiro').click()">Importar de ficheiro…</button>
        <input type="file" id="dados-ficheiro" accept=".json,application/json" style="display:none" onchange="importarDados()">
        <button class="btn perigo" onclick="reporFabricaDados()">Repor de fábrica</button>
      </div>
      <div class="porcima" style="margin-top:.8rem">
        <b>Exportar</b> descarrega um ficheiro <code>.json</code>. <b>Importar</b> traz os casamentos
        <b>como novos</b> (não substitui nada), os modelos e as contas que ainda não existam.
        <b>Repor de fábrica</b> <b>apaga dados</b>: os modelos personalizados (ficam os de origem),
        as contas de casamento e/ou as administrativas (a sua nunca), e/ou esvazia os casamentos
        escolhidos. Não se desfaz.</div>
      <div class="segredo" id="dados-resultado" style="display:none"></div>
    </div>
    </div><!-- /vista-dados -->
  <?php endif; ?>
</main>

<?php // ---- Modal: gerir a licença de um casamento ---- ?>
<div class="overlay" id="ov-licenca">
  <div class="modal">
    <div class="modal-topo">
      <h3>Licença · <span id="lic-nome"></span></h3>
      <button class="fechar" onclick="fecharModal('ov-licenca')">&times;</button>
    </div>
    <div class="modal-corpo">
      <div class="segredo lic-estado" id="lic-estado" style="margin-top:0"></div>
      <div class="dica">Quanto tempo o casamento fica disponível. Expirada, é suspenso sozinho —
        e as contas que só dele dependem param com ele.</div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label for="lic-periodo">Período de licença</label>
          <select id="lic-periodo" onchange="licPeriodoMudou()">
            <option value="0">Sem limite</option>
            <option value="3">3 meses</option>
            <option value="6">6 meses</option>
            <option value="12">12 meses</option>
            <option value="outro">Outro…</option>
          </select></div>
        <div id="lic-outro" style="display:none"><label for="lic-meses">Meses</label>
          <input type="number" id="lic-meses" min="1" max="120" placeholder="ex.: 18"></div>
      </div>
      <label id="lic-reiniciar-linha" style="display:flex;gap:.5rem;align-items:center;font-weight:400;margin-top:.9rem">
        <input type="checkbox" id="lic-reiniciar" checked style="width:auto;margin:0">
        <span id="lic-reiniciar-rot">Reiniciar o relógio a contar de hoje</span></label>
      <div class="dica" style="margin:.4rem 0 0">Sem esta opção, o período fica guardado mas o relógio
        não é mexido — serve para corrigir o número de meses sem dar tempo novo.</div>
      <div class="fim" style="display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.2rem">
        <button class="btn" onclick="fecharModal('ov-licenca')">Cancelar</button>
        <button class="btn btn-ouro" onclick="guardarLicenca()">Guardar licença</button>
      </div>
    </div>
  </div>
</div>

<?php // ---- Modal: editar todos os dados de um casamento ---- ?>
<div class="overlay" id="ov-editar">
  <div class="modal" style="max-width:820px">
    <div class="modal-topo">
      <h3>Editar · <span id="ed-nome-topo"></span></h3>
      <button class="fechar" onclick="fecharModal('ov-editar')">&times;</button>
    </div>
    <div class="modal-corpo">
      <input type="hidden" id="ed-id">
      <h4 class="ed-sec">Identidade</h4>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr">
        <div><label for="ed-cnome">Nome do casamento</label><input type="text" id="ed-cnome"></div>
        <div><label for="ed-noiva">Noiva</label><input type="text" id="ed-noiva"></div>
        <div><label for="ed-noivo">Noivo</label><input type="text" id="ed-noivo"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="ed-data">Data</label><input type="date" id="ed-data"></div>
        <div><label for="ed-hora">Hora da festa</label><input type="time" id="ed-hora"></div>
        <div><label for="ed-convidados">Convidados que espera</label><input type="number" id="ed-convidados" min="1" max="5000"></div>
      </div>

      <h4 class="ed-sec">O evento</h4>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label for="ed-local">Local da festa</label><input type="text" id="ed-local"></div>
        <div><label for="ed-cidade">Cidade / região</label><input type="text" id="ed-cidade"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label for="ed-whatsapp">WhatsApp</label><input type="text" id="ed-whatsapp" inputmode="numeric"></div>
        <div><label for="ed-orcamento">Orçamento total <small style="color:#8a8f88">· opcional</small></label>
          <input type="text" id="ed-orcamento" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label for="ed-maps">Local da festa · Google Maps</label>
          <input type="url" id="ed-maps" data-mapa data-mapa-local="ed-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label for="ed-venue">Título do local <small style="color:#8a8f88">· ex.: «Copo d’água»</small></label>
          <input type="text" id="ed-venue"></div>
        <div></div>
      </div>
      <div class="dica" style="margin:.6rem 0 .3rem">As cerimónias, se as houver.</div>
      <div class="lf" style="grid-template-columns:1fr 2fr 1fr 2fr;align-items:start">
        <div><label for="ed-civil-hora">Civil · hora</label><input type="time" id="ed-civil-hora"></div>
        <div><label for="ed-civil-local">Civil · local</label><input type="text" id="ed-civil-local"></div>
        <div><label for="ed-religiosa-hora">Religiosa · hora</label><input type="time" id="ed-religiosa-hora"></div>
        <div><label for="ed-religiosa-local">Religiosa · local</label><input type="text" id="ed-religiosa-local"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr;align-items:start">
        <div><label for="ed-civil-maps">Civil · Google Maps</label>
          <input type="url" id="ed-civil-maps" data-mapa data-mapa-local="ed-civil-local" placeholder="https://maps.app.goo.gl/…"></div>
        <div><label for="ed-religiosa-maps">Religiosa · Google Maps</label>
          <input type="url" id="ed-religiosa-maps" data-mapa data-mapa-local="ed-religiosa-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="fim" style="display:flex;gap:.6rem;align-items:center;margin:.6rem 0 0">
        <button class="btn btn-ouro" onclick="guardarDadosCasamento()">Guardar dados</button>
        <span class="dica" style="margin:0">A identidade e o evento. As contas geram-se abaixo.</span>
      </div>

      <h4 class="ed-sec">Contas associadas</h4>
      <div class="dica" style="margin:-.3rem 0 .5rem">Os <b>noivos</b> gerem o casamento; o <b>porteiro</b>
        só regista entradas. Aqui muda-se o email, repõe-se a senha ou tira-se o acesso.</div>
      <div id="ed-contas"><div class="dica">A carregar…</div></div>

      <details class="dobra dobra-dentro" id="ed-nova-conta" style="margin-top:.8rem">
        <summary><span class="mais">+</span> Adicionar uma conta</summary>
        <div class="lf" style="grid-template-columns:1fr 2fr 2fr auto;margin-top:.5rem">
          <div><label>Papel</label>
            <select id="ed-np-papel"><option value="porteiro">Porteiro</option><option value="noivos">Noivos</option></select></div>
          <div class="campo"><label for="ed-np-email">Email</label>
            <input type="email" id="ed-np-email" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="err"></div></div>
          <div class="campo"><label for="ed-np-senha">Palavra-passe <small style="color:#8a8f88">· em branco = gerada</small></label>
            <div class="pw-wrap"><input type="password" id="ed-np-senha" autocomplete="new-password" spellcheck="false">
              <button type="button" class="pw-olho" id="olho-np" onclick="verSenha('ed-np-senha','olho-np')">mostrar</button></div>
            <div class="err"></div></div>
          <div><button class="btn btn-ouro btn-sm" onclick="adicionarConta()">Adicionar</button></div>
        </div>
      </details>

      <div class="segredo" id="ed-segredo" style="display:none"></div>
      <div class="fim" style="display:flex;justify-content:flex-end;margin-top:1rem">
        <button class="btn" onclick="fecharModal('ov-editar')">Fechar</button>
      </div>
    </div>
  </div>
</div>

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

// ---------- as pastilhas: casamentos · novo · contas · dados ----------
function verVista(v){
  ['casamentos','novo','contas','dados'].forEach(id => {
    const e = document.getElementById('vista-' + id);
    if (e) e.style.display = id === v ? '' : 'none';
  });
  document.querySelectorAll('#vista-chips .chip').forEach(c =>
    c.classList.toggle('on', c.dataset.vista === v));
  if (v === 'dados') carregarDadosCasamentos();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---------- o formulário de novo casamento: licença e contas ----------
function licencaMudou(){
  const el = document.getElementById('n-licenca-outro');
  if (el) el.style.display = document.getElementById('n-licenca').value === 'outro' ? '' : 'none';
}
function licencaMesesNovo(){
  const v = document.getElementById('n-licenca').value;
  return v === 'outro'
    ? Math.max(0, parseInt(document.getElementById('n-licenca-meses').value || '0', 10))
    : parseInt(v, 10);
}
// Sugere um utilizador único para o porteiro, a partir dos nomes. Só enquanto
// ninguém lhe tocar — quem escrever o seu próprio manda.
function slugNome(s){
  return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
let PORT_TOCADO = false;
function sugerirPorteiro(){
  const eIn = document.getElementById('n-porteiro-email');
  const sIn = document.getElementById('n-porteiro-senha');
  if (!eIn) return;
  if (!eIn.dataset.lig){ eIn.addEventListener('input', () => PORT_TOCADO = true); eIn.dataset.lig = '1'; }
  if (PORT_TOCADO) return;
  const base = [slugNome((document.getElementById('n-noiva')||{}).value),
                slugNome((document.getElementById('n-noivo')||{}).value)].filter(Boolean).join('-') || 'casamento';
  const suf = Math.random().toString(36).slice(2, 5);
  const host = (location.hostname || 'convite.local').replace(/^www\./, '');
  eIn.value = `porta-${base}-${suf}@${host}`;
  if (sIn && !sIn.value){
    sIn.value = 'porta' + Math.random().toString(36).slice(2, 10);
    const cIn = document.getElementById('n-porteiro-confirmar');
    if (cIn) cIn.value = sIn.value;   // a sugestão já vem confirmada consigo mesma
  }
}

// ---------- verificações de preenchimento das contas (email / senha) ----------
// As mesmas do formulário público, aqui só nos campos das contas: o email tem
// de parecer um email; a palavra-passe, se se escrever uma, precisa de 8 e de
// bater certo com a repetição. Em branco, a conta não se cria (ou a senha é
// gerada) — por isso um par vazio é válido.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const valc = id => (document.getElementById(id).value || '').trim();
function marca(id, erro){
  const el = document.getElementById(id), c = el.closest('.campo'); if (!c) return !erro;
  const box = c.querySelector('.err');
  if (erro){ c.classList.add('mau'); c.classList.remove('ok'); if (box) box.textContent = erro; el.setAttribute('aria-invalid','true'); }
  else { c.classList.remove('mau'); el.removeAttribute('aria-invalid'); if (valc(id)) c.classList.add('ok'); else c.classList.remove('ok'); }
  return !erro;
}
function regraConta(id){
  const v = valc(id);
  switch (id){
    case 'n-noivos-email':
      if (!v) return '';                                   // sem email: não se cria a conta
      return EMAIL_RE.test(v) ? '' : 'Esse email não parece válido.';
    case 'n-noivos-senha':
      if (!v) return '';                                   // em branco: senha gerada
      return v.length >= 8 ? '' : 'Pelo menos 8 caracteres.';
    case 'n-noivos-confirmar':
      if (!valc('n-noivos-senha')) return '';
      return v === document.getElementById('n-noivos-senha').value ? '' : 'As palavras-passe não coincidem.';
    case 'n-porteiro-email':
      if (!v && !valc('n-porteiro-senha')) return '';      // par vazio: opcional
      return EMAIL_RE.test(v) ? '' : 'Email do porteiro inválido.';
    case 'n-porteiro-senha':
      if (!v) return '';
      return v.length >= 8 ? '' : 'Pelo menos 8 caracteres para o porteiro.';
    case 'n-porteiro-confirmar':
      if (!valc('n-porteiro-senha')) return '';
      return v === document.getElementById('n-porteiro-senha').value ? '' : 'As palavras-passe não coincidem.';
  }
  return '';
}
function validaConta(id){ return marca(id, regraConta(id)); }
const CAMPOS_CONTA = ['n-noivos-email','n-noivos-senha','n-noivos-confirmar',
                      'n-porteiro-email','n-porteiro-senha','n-porteiro-confirmar'];
const PARES_CONTA = { 'n-noivos-senha':['n-noivos-confirmar'], 'n-noivos-confirmar':['n-noivos-senha'],
                      'n-porteiro-senha':['n-porteiro-confirmar','n-porteiro-email'],
                      'n-porteiro-confirmar':['n-porteiro-senha'],
                      'n-porteiro-email':['n-porteiro-senha'] };
CAMPOS_CONTA.forEach(id => {
  const el = document.getElementById(id); if (!el) return;
  el.addEventListener('blur', () => validaConta(id));
  el.addEventListener('input', () => {
    if (el.closest('.campo').classList.contains('mau')) validaConta(id);
    (PARES_CONTA[id] || []).forEach(p => { const pe = document.getElementById(p);
      if (pe && pe.closest('.campo').classList.contains('mau')) validaConta(p); });
  });
});

// A força de uma palavra-passe, para os dois campos de senha.
function ligarForca(campoId, caixaId, rotId){
  const el = document.getElementById(campoId), box = document.getElementById(caixaId);
  if (!el || !box) return;
  el.addEventListener('input', () => {
    const v = el.value, rot = document.getElementById(rotId);
    let n = 0;
    if (v.length >= 8) n++;
    if (v.length >= 12) n++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) n++;
    if (/\d/.test(v) && /[^\w\s]/.test(v)) n++;
    if (v && n === 0) n = 1;
    box.className = 'pw-forca f' + n;
    rot.textContent = v ? ['', 'fraca', 'razoável', 'boa', 'forte'][n] : '';
  });
}
ligarForca('n-noivos-senha', 'forca-ne', 'rot-ne');
ligarForca('n-porteiro-senha', 'forca-pe', 'rot-pe');
// A conta administrativa: limpa a marca de erro do email enquanto se corrige.
document.getElementById('c-email')?.addEventListener('input', () => {
  const c = document.getElementById('c-email').closest('.campo');
  if (c && c.classList.contains('mau')) marca('c-email',
    EMAIL_RE.test(document.getElementById('c-email').value.trim()) ? '' : 'Esse email não parece válido.');
});

// Mostrar / ocultar uma palavra-passe.
function verSenha(id, olhoId){
  const el = document.getElementById(id), b = document.getElementById(olhoId), ver = el.type === 'password';
  el.type = ver ? 'text' : 'password';
  b.textContent = ver ? 'ocultar' : 'mostrar';
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
  // As contas passam pelas mesmas verificações do formulário público: um email
  // torto ou uma confirmação que não bate certo são apanhados aqui, e o primeiro
  // campo com erro chama o olho a si.
  let primeiro = null;
  CAMPOS_CONTA.forEach(id => { if (!validaConta(id) && !primeiro) primeiro = id; });
  if (primeiro){
    const el = document.getElementById(primeiro);
    el.focus(); el.closest('.campo').scrollIntoView({ behavior:'smooth', block:'center' });
    return toast('Reveja os dados das contas assinalados a vermelho.', true);
  }
  const d = await api('casamento_criar', { method:'POST', body: JSON.stringify({
    nome: v('n-nome'), noiva: v('n-noiva'), noivo: v('n-noivo'), data: v('n-data'),
    hora: v('n-hora'), local: v('n-local'), cidade: v('n-cidade'), maps: v('n-maps'),
    convidados: v('n-convidados'), whatsapp: v('n-whatsapp'),
    orcamento_total: v('n-orcamento'),
    licenca_meses: licencaMesesNovo(),
    licenca_ativa: document.getElementById('n-licenca-ativa').checked,
    noivos_email: v('n-noivos-email'), noivos_senha: v('n-noivos-senha'),
    porteiro_email: v('n-porteiro-email'), porteiro_senha: v('n-porteiro-senha'),
    civil_hora: v('n-civil-hora'), civil_local: v('n-civil-local'), civil_maps: v('n-civil-maps'),
    religiosa_hora: v('n-religiosa-hora'), religiosa_local: v('n-religiosa-local'), religiosa_maps: v('n-religiosa-maps'),
  }) });
  if (!d || !d.success) return;
  // As senhas geradas mostram-se UMA vez: se recarregasse já, perdiam-se. Só se
  // recarrega quando não há nada de secreto para o admin copiar.
  const contas = d.contas || {};
  const linhas = [];
  ['noivos','porteiro'].forEach(t => {
    const c = contas[t];
    if (c) linhas.push(`<b>${t === 'noivos' ? 'Noivos' : 'Porteiro'}</b>: <span class="cod">${esc(c.email)}</span>`
      + (c.senha ? ` · senha <b class="cod">${esc(c.senha)}</b>` : ' · conta já existente, ligada'));
  });
  const lic = d.licenca && !d.licenca.ilimitada
    ? (d.licenca.iniciada ? `Licença de ${d.licenca.meses} mês(es), até ${esc((d.licenca.ate||'').split('-').reverse().join('/'))}.`
                          : `Licença de ${d.licenca.meses} mês(es), por iniciar.`)
    : 'Sem limite de licença.';
  const cx = document.getElementById('n-resultado');
  cx.style.display = '';
  cx.innerHTML = `Casamento <b>${esc(d.nome)}</b> criado. ${lic}`
    + (linhas.length ? '<br>' + linhas.join('<br>') + '<br><small>Entregue as senhas agora — não voltam a aparecer.</small>' : '')
    + `<br><button class="btn btn-sm" style="margin-top:.6rem" onclick="verVista('casamentos');carregarCasamentos()">Ver nos casamentos</button>`;
  ['n-nome','n-noiva','n-noivo','n-data','n-hora','n-local','n-cidade','n-maps','n-convidados','n-whatsapp',
   'n-orcamento','n-civil-hora','n-civil-local','n-civil-maps','n-religiosa-hora','n-religiosa-local',
   'n-religiosa-maps','n-noivos-email','n-noivos-senha','n-noivos-confirmar',
   'n-porteiro-email','n-porteiro-senha','n-porteiro-confirmar']
    .forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
  // Limpa as marcas de erro/válido e as barras de força, para o formulário
  // ficar como novo à próxima.
  document.querySelectorAll('#vista-novo .campo').forEach(c => c.classList.remove('mau','ok'));
  ['forca-ne','forca-pe'].forEach(i => { const e = document.getElementById(i); if (e) e.className = 'pw-forca'; });
  PORT_TOCADO = false;
  toast('Casamento criado.');
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

// ---------- dados e reposição (a pastilha do admin) ----------
async function carregarDadosCasamentos(){
  const alvo = document.getElementById('dados-cas-lista');
  if (!alvo || alvo.dataset.pronto) return;   // carrega uma vez
  const d = await api('casamento_lista&estado=todos');
  if (!d || !d.success) return;
  alvo.dataset.pronto = '1';
  alvo.innerHTML = (d.casamentos || []).map(c =>
    `<label><input type="checkbox" value="${c.id}"> ${esc(c.nome)}
       <small style="color:#8a8f88">${esc(c.estado)}</small></label>`).join('')
    || '<div class="dica">Nenhum casamento.</div>';
}
function dadosCasToggle(){
  const on = document.querySelector('#dados-inc input[value="casamentos"]').checked;
  document.getElementById('dados-cas-bloco').style.display = on ? '' : 'none';
}
function dadosCasTodos(v){
  document.querySelectorAll('#dados-cas-lista input').forEach(i => i.checked = v);
}
function incEscolhidos(){
  return Array.from(document.querySelectorAll('#dados-inc input[value]:checked')).map(i => i.value);
}
function casEscolhidos(){
  return Array.from(document.querySelectorAll('#dados-cas-lista input:checked')).map(i => i.value);
}
function exportarDados(){
  const inc = incEscolhidos();
  if (!inc.length) return toast('Escolha ao menos um âmbito.', true);
  const q = ['ambito=sistema', 'inc=' + encodeURIComponent(inc.join(','))];
  if (inc.includes('casamentos')){
    const ids = casEscolhidos();
    if (ids.length) q.push('casamentos=' + ids.join(','));   // vazio = todos
  }
  const temContas = inc.includes('contas_casamento') || inc.includes('contas_admin');
  if (document.getElementById('dados-senhas').checked && temContas) q.push('senhas=1');
  location.href = 'api.php?action=dados_exportar&' + q.join('&');
}
async function importarDados(){
  const inp = document.getElementById('dados-ficheiro');
  const f = inp.files[0]; inp.value = '';
  const inc = incEscolhidos();
  if (!inc.length) return toast('Escolha o que trazer do ficheiro.', true);
  if (!f) return;
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  if (!dados || dados.formato !== 'casamento-web/1') {
    return toast('Este ficheiro não é uma exportação deste sistema.', true);
  }
  const rot = { casamentos:'casamentos (como novos)', modelos:'modelos',
                contas_casamento:'contas de casamento', contas_admin:'contas administrativas' };
  if (!confirm('Trazer do ficheiro: ' + inc.map(i => rot[i]).join(', ') + '?\n\n'
    + 'Os casamentos entram como NOVOS; modelos e contas que já existam saltam-se. '
    + 'Nada do que já cá está é substituído.')) return;
  const d = await api('sistema_importar', { method:'POST', body: JSON.stringify({ inc, ficheiro: dados }) });
  if (!d || !d.success) return;
  const r = d.res || {};
  const cx = document.getElementById('dados-resultado');
  cx.style.display = '';
  cx.innerHTML = 'Trazidos: '
    + `<b>${r.casamentos || 0}</b> casamento(s), <b>${r.modelos || 0}</b> modelo(s), `
    + `<b>${r.contas || 0}</b> conta(s)` + (r.contas_saltadas ? ` (${r.contas_saltadas} já existiam)` : '') + '.';
  toast('Importação concluída.');
  document.getElementById('dados-cas-lista').dataset.pronto = '';   // relista os casamentos novos
  setTimeout(() => { carregarCasamentos(); carregarDadosCasamentos(); }, 300);
}
async function reporFabricaDados(){
  const inc = incEscolhidos();
  const alvos = [];
  const linhas = [];
  if (inc.includes('modelos')){ alvos.push('modelos'); linhas.push('apagar os modelos personalizados (ficam os de origem)'); }
  if (inc.includes('contas_casamento')){ alvos.push('contas_casamento'); linhas.push('apagar as contas de casamento (noivos e porteiros)'); }
  if (inc.includes('contas_admin')){     alvos.push('contas_admin');     linhas.push('apagar as contas administrativas (exceto a sua)'); }
  const ids = inc.includes('casamentos') ? casEscolhidos() : [];
  if (ids.length){ alvos.push('casamentos'); linhas.push('esvaziar ' + ids.length + ' casamento(s): lista, mesas, versões, orçamento'); }
  if (!alvos.length) {
    return toast('Assinale contas, modelos e/ou casamentos (com casamentos escolhidos) para apagar.', true);
  }
  if (!confirm('Repor de fábrica — isto APAGA dados:\n\n• ' + linhas.join('\n• ') + '\n\nNão se desfaz.')) return;
  const d = await api('sistema_repor_fabrica', { method:'POST',
    body: JSON.stringify({ alvos, casamentos: ids }) });
  if (!d || !d.success) return;
  const r = d.res || {};
  const p = [];
  if (r.modelos != null) p.push(`<b>${r.modelos}</b> modelo(s)`);
  if (r.contas_casamento != null) p.push(`<b>${r.contas_casamento}</b> conta(s) de casamento`);
  if (r.contas_admin != null) p.push(`<b>${r.contas_admin}</b> conta(s) administrativa(s)`);
  if (r.casamentos != null) p.push(`<b>${r.casamentos}</b> casamento(s) esvaziado(s)`);
  const cx = document.getElementById('dados-resultado');
  cx.style.display = '';
  cx.innerHTML = 'Apagado na reposição de fábrica: ' + p.join(' · ') + '.';
  toast('Reposição concluída.');
  document.getElementById('dados-cas-lista').dataset.pronto = '';
  setTimeout(() => { carregarCasamentos(); carregarDadosCasamentos(); }, 300);
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
  CASAMENTOS = {};
  d.casamentos.forEach(c => { CASAMENTOS[c.id] = c; });   // para os modais não irem procurar outra vez
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
        mais.push(`<button onclick="editarTudo(${c.id})">Editar todos os dados…</button>`);
        mais.push(`<button onclick="gerirLicenca(${c.id})">Gerir licença…</button>`);
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
          ${licencaMeta(c)}
          ${+c.donos === 0 ? '<span class="falta">sem conta dos noivos</span>' : ''}
        </div>
        ${pes ? `<div class="barra" title="${conf} de ${pes} confirmaram (${pc}%)"><i style="width:${pc}%"></i></div>` : ''}
      </div>
      <div class="ac">${principal}${menu}</div>
    </div>`;
  }).join('');
}

/** O que resta da licença, para a linha do casamento. */
function licencaMeta(c){
  const m = +c.licenca_meses || 0;
  if (!m) return '<span style="color:#b0b5af">licença sem limite</span>';
  if (!c.licenca_ate) return '<span class="falta">licença por iniciar</span>';
  const dias = c.licenca_dias == null ? null : +c.licenca_dias;
  if (dias == null) return '';
  if (dias < 0)  return '<span class="falta">licença expirada</span>';
  if (dias < 45) return `<span class="falta">licença: faltam ${dias} dia(s)</span>`;
  return `<span class="conta">licença: ~${Math.round(dias/30)} mes(es)</span>`;
}

/** Uma frase sobre o estado atual da licença, para o topo do modal. */
function licencaEstado(c){
  const m = +c.licenca_meses || 0;
  if (!m) return { txt: 'Sem limite de licença — o casamento não expira.', cls: 'ok' };
  if (!c.licenca_ate) return { txt: `Licença de ${m} mês(es), <b>por iniciar</b> — o relógio ainda não começou.`, cls: 'warn' };
  const dias = c.licenca_dias == null ? null : +c.licenca_dias;
  const ate = (c.licenca_ate || '').split('-').reverse().join('/');
  if (dias == null) return { txt: `Licença de ${m} mês(es).`, cls: '' };
  if (dias < 0)  return { txt: `Licença de ${m} mês(es) — <b>expirada</b> em ${esc(ate)} (há ${Math.abs(dias)} dia(s)).`, cls: 'danger' };
  if (dias < 45) return { txt: `Licença de ${m} mês(es) — termina em ${esc(ate)}, <b>faltam ${dias} dia(s)</b>.`, cls: 'warn' };
  return { txt: `Licença de ${m} mês(es) — ativa até ${esc(ate)} (~${Math.round(dias/30)} mês(es)).`, cls: 'ok' };
}

/** Gerir a licença de um casamento: um modal completo em vez de um prompt seco. */
let LICENCA_ALVO = 0;
function gerirLicenca(id){
  const c = CASAMENTOS[id]; if (!c) return;
  LICENCA_ALVO = id;
  const est = licencaEstado(c);
  const m = +c.licenca_meses || 0;
  const jaIniciada = m > 0 && !!c.licenca_ate;
  $('lic-nome').textContent = c.nome || 'Casamento';
  $('lic-estado').className = 'segredo lic-estado ' + (est.cls || '');
  $('lic-estado').innerHTML = est.txt;
  // O período começa no que está definido; «Outro…» abre a caixa dos meses.
  const presets = [0, 3, 6, 12];
  $('lic-periodo').value = presets.includes(m) ? String(m) : 'outro';
  $('lic-outro').style.display = presets.includes(m) ? 'none' : '';
  $('lic-meses').value = presets.includes(m) ? '' : m;
  // Por omissão, iniciar/reiniciar o relógio: é o gesto comum (dar tempo novo).
  $('lic-reiniciar').checked = true;
  $('lic-reiniciar-linha').style.display = ($('lic-periodo').value === '0') ? 'none' : '';
  $('lic-reiniciar-rot').textContent = jaIniciada
    ? 'Reiniciar o relógio a contar de hoje' : 'Iniciar já o relógio (a contar de hoje)';
  abrirModal('ov-licenca');
}
function licPeriodoMudou(){
  const v = $('lic-periodo').value;
  $('lic-outro').style.display = v === 'outro' ? '' : 'none';
  $('lic-reiniciar-linha').style.display = v === '0' ? 'none' : '';
}
function licMesesEscolhidos(){
  const v = $('lic-periodo').value;
  return v === 'outro' ? Math.max(0, Math.min(120, parseInt($('lic-meses').value || '0', 10))) : parseInt(v, 10);
}
async function guardarLicenca(){
  const meses = licMesesEscolhidos();
  const reiniciar = meses > 0 && $('lic-reiniciar').checked;
  const d = await api('casamento_licenca', { method:'POST',
    body: JSON.stringify({ id: LICENCA_ALVO, licenca_meses: meses, iniciar: reiniciar, reiniciar }) });
  if (!d || !d.success) return;
  fecharModal('ov-licenca');
  toast(meses
    ? 'Licença: ' + meses + ' mês(es)' + (reiniciar ? ', a contar de hoje.' : ' (guardada).')
    : 'Licença sem limite.');
  carregarCasamentos();
}

// ---------- editar TODOS os dados de um casamento (identidade + evento + contas) ----------
let EDITAR_ALVO = 0;
async function editarTudo(id){
  EDITAR_ALVO = id;
  $('ed-segredo').style.display = 'none'; $('ed-segredo').innerHTML = '';
  // Limpa a caixa de nova conta, para não trazer o que ficou de outro casamento.
  ['ed-np-email','ed-np-senha'].forEach(i => { const e = $(i); if (e) e.value = ''; });
  document.querySelectorAll('#ov-editar .campo').forEach(c => c.classList.remove('mau','ok'));
  const nc = $('ed-nova-conta'); if (nc) nc.open = false;
  $('ed-contas').innerHTML = '<div class="dica">A carregar…</div>';
  abrirModal('ov-editar');
  const d = await api('casamento_ficha&id=' + id);
  if (!d || !d.success){ fecharModal('ov-editar'); return; }
  const c = d.casamento, ev = d.evento || {};
  $('ed-id').value = id;
  $('ed-nome-topo').textContent = c.nome || 'Casamento';
  $('ed-cnome').value = c.nome || '';
  $('ed-noiva').value = c.noiva || '';
  $('ed-noivo').value = c.noivo || '';
  $('ed-data').value  = (c.data_evento && c.data_evento !== '0000-00-00') ? c.data_evento : '';
  const pv = (k, id2) => { const e = $(id2); if (e) e.value = ev[k] || ''; };
  pv('evento.hora','ed-hora'); pv('evento.local','ed-local'); pv('evento.cidade','ed-cidade');
  pv('evento.venue_titulo','ed-venue');
  pv('evento.convidados','ed-convidados'); pv('evento.whatsapp','ed-whatsapp'); pv('evento.maps','ed-maps');
  pv('evento.civil_hora','ed-civil-hora'); pv('evento.civil_local','ed-civil-local'); pv('evento.civil_maps','ed-civil-maps');
  pv('evento.religiosa_hora','ed-religiosa-hora'); pv('evento.religiosa_local','ed-religiosa-local'); pv('evento.religiosa_maps','ed-religiosa-maps');
  // O orçamento passa pela máscara, para se ver com os separadores certos.
  const orc = $('ed-orcamento');
  if (orc){ orc.value = ev['orcamento.total'] || '';
    if (window.Moeda && orc.value) orc.value = window.Moeda.paraCampo(orc.value); }
  pintarContasEd(d.contas || []);
}
function pintarContasEd(contas){
  const alvo = $('ed-contas');
  if (!contas.length){ alvo.innerHTML = '<div class="dica">Este casamento ainda não tem contas. Adicione uma abaixo.</div>'; return; }
  alvo.innerHTML = contas.map(a => {
    const uid = a.utilizador_id;
    const papel = a.papel === 'noivos' ? 'Noivos · gere o casamento' : 'Porteiro · só a porta';
    return `<div class="ed-conta" id="edc-${uid}">
      <div class="cab"><span class="et ${a.papel === 'noivos' ? 'agora' : ''}">${esc(papel)}</span>
        <span class="et ${esc(a.estado)}">${esc(a.estado)}</span></div>
      <div class="lf" style="grid-template-columns:2fr 2fr auto;margin:0">
        <div><label>Email</label><input type="email" id="edc-email-${uid}" value="${esc(a.email || '')}" autocapitalize="none" spellcheck="false"></div>
        <div><label>Nome</label><input type="text" id="edc-nome-${uid}" value="${esc(a.nome || '')}"></div>
        <div class="ac" style="align-self:end">
          <button class="btn btn-sm" onclick="guardarContaLigada(${uid})">Guardar</button>
          <button class="btn btn-sm" onclick="reporSenhaLigada(${uid}, '${esc(a.email)}')">Repor senha</button>
          <button class="btn btn-sm perigo" onclick="tirarContaLigada(${uid}, '${esc(a.nome || a.email)}')">Eliminar conta</button>
        </div>
      </div>
    </div>`;
  }).join('');
}
function dadosEventoEd(){
  const v = id => ($(id).value || '').trim();
  return {
    nome: v('ed-cnome'), noiva: v('ed-noiva'), noivo: v('ed-noivo'), data: v('ed-data'),
    hora: v('ed-hora'), local: v('ed-local'), cidade: v('ed-cidade'), maps: v('ed-maps'),
    venue_titulo: v('ed-venue'),
    convidados: v('ed-convidados'), whatsapp: v('ed-whatsapp'),
    orcamento_total: v('ed-orcamento'),
    civil_hora: v('ed-civil-hora'), civil_local: v('ed-civil-local'), civil_maps: v('ed-civil-maps'),
    religiosa_hora: v('ed-religiosa-hora'), religiosa_local: v('ed-religiosa-local'), religiosa_maps: v('ed-religiosa-maps'),
  };
}
async function guardarDadosCasamento(){
  const corpo = Object.assign({ id: EDITAR_ALVO }, dadosEventoEd());
  const d = await api('casamento_editar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  $('ed-nome-topo').textContent = d.nome || '';
  toast('Dados do casamento guardados.');
  carregarCasamentos();
}
async function adicionarConta(){
  const papel = $('ed-np-papel').value;
  const email = ($('ed-np-email').value || '').trim();
  const senha = $('ed-np-senha').value;
  const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  marca('ed-np-email', emailOk ? '' : 'Indique um email válido.');
  marca('ed-np-senha', (!senha || senha.length >= 8) ? '' : 'Pelo menos 8 caracteres (ou deixe em branco).');
  if (!emailOk || (senha && senha.length < 8)) return;
  const corpo = Object.assign({ id: EDITAR_ALVO }, dadosEventoEd());
  if (papel === 'noivos'){ corpo.noivos_email = email; corpo.noivos_senha = senha; }
  else { corpo.porteiro_email = email; corpo.porteiro_senha = senha; }
  const d = await api('casamento_editar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  const c = (d.contas || {})[papel];
  const cx = $('ed-segredo');
  if (c){
    cx.style.display = '';
    cx.innerHTML = `Conta de <b>${papel === 'noivos' ? 'noivos' : 'porteiro'}</b> ligada: <span class="cod">${esc(c.email)}</span>`
      + (c.senha ? ` · senha <b class="cod">${esc(c.senha)}</b><br><small>Entregue-a agora — não volta a aparecer.</small>`
                 : ' · conta já existente, ligada.');
  }
  $('ed-np-email').value = $('ed-np-senha').value = '';
  editarTudoRecarregar();
  carregarCasamentos();
  toast('Conta adicionada.');
}
// Recarrega só a lista de contas do modal, sem fechar nem perder o segredo mostrado.
async function editarTudoRecarregar(){
  const d = await api('casamento_ficha&id=' + EDITAR_ALVO);
  if (d && d.success) pintarContasEd(d.contas || []);
}
async function guardarContaLigada(uid){
  const d = await api('utilizador_editar', { method:'POST', body: JSON.stringify({
    id: uid, nome: ($('edc-nome-' + uid).value || '').trim(),
    email: ($('edc-email-' + uid).value || '').trim(),
  }) });
  if (d && d.success){ toast('Conta guardada.'); carregarCasamentos(); }
}
async function reporSenhaLigada(uid, email){
  if (!confirm('Repor a senha de ' + email + '?\n\nA senha atual deixa de servir. A nova aparece aqui, uma vez.')) return;
  const d = await api('utilizador_repor_senha&id=' + uid, { method:'POST' });
  if (!d || !d.success) return;
  const cx = $('ed-segredo');
  cx.style.display = '';
  cx.innerHTML = `Senha nova de <b>${esc(d.email)}</b>: <b class="cod">${esc(d.senha)}</b><br>
    <small>Entregue-a agora — não volta a aparecer.</small>`;
}
async function tirarContaLigada(uid, nome){
  if (!confirm('Eliminar a conta de ' + nome + '?\n\nA conta é apagada e deixa de entrar. '
    + 'Não se desfaz. O email fica livre para uma conta nova.')) return;
  const d = await api('conta_apagar_do_casamento&utilizador=' + uid + '&casamento=' + EDITAR_ALVO, { method:'POST' });
  if (d && d.success){ toast('Conta eliminada.'); editarTudoRecarregar(); carregarCasamentos(); }
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
let CASAMENTOS = {};  // idem, para os modais de licença e de edição completa

// Abrir e fechar as janelas modais (o «abrir» já existe, para abrir casamentos).
function abrirModal(id){ const e = $(id); if (e) e.classList.add('aberto'); }
function fecharModal(id){ const e = $(id); if (e) e.classList.remove('aberto'); }
// Só contas administrativas por aqui: suporte e admin. As de noivos/porteiro
// criam-se em cada casamento (no formulário de novo casamento, ou em Gestão).
function tipoMudou(){
  const tipo = $('c-tipo').value;
  $('nota-tipo').innerHTML = {
    suporte:  '<b>Não se liga a casamento nenhum.</b> Entra com o código que o casal gerar — '
            + 'é esse código que lhe abre a porta e diz se pode ver ou também corrigir.',
    admin:    'Responde pela casa: vê todos os casamentos, aprova registos e gere contas.',
  }[tipo];
}
async function criarConta(){
  const tipo = $('c-tipo').value;
  const email = $('c-email').value.trim();
  // Assinala o campo, em vez de um aviso solto: o email é obrigatório e tem de
  // parecer um email.
  const erroEmail = !email ? 'Indique o email da conta.'
                  : (EMAIL_RE.test(email) ? '' : 'Esse email não parece válido.');
  if (!marca('c-email', erroEmail)) { $('c-email').focus(); return; }
  const senha = 'tmp' + Math.random().toString(36).slice(2, 10);
  const corpo = { email, nome: $('c-nome').value.trim(), senha, papel_plataforma: tipo };
  const d = await api('utilizador_criar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  $('c-nome').value = $('c-email').value = '';
  $('c-email').closest('.campo').classList.remove('mau','ok');
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
  // Só as contas administrativas (admin e suporte). As de noivos/porteiro
  // pertencem a cada casamento e veem-se lá, em Gestão.
  const d = await api('utilizador_lista&tipo=plataforma' + (q ? '&q=' + encodeURIComponent(q) : ''));
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
          <span>${c.ultimo_acesso ? 'último acesso ' + esc(c.ultimo_acesso.slice(0,10)) : 'nunca entrou'}</span></div>
      </div>
      <div class="ac">${acoes}</div>
      <div class="editor-conta" id="ed-${c.id}" style="display:none"></div>
    </div>`;
  }).join('');
}

// ---------- editar uma conta administrativa (admin ou suporte) ----------
// Estas contas não se prendem a casamento nenhum: o admin responde por toda a
// casa, o suporte entra por código. Por isso não há aqui lugares a dar nem a
// tirar — só o nome, o email e qual dos dois papéis é.
async function editarConta(id){
  const cx = document.getElementById('ed-' + id);
  if (cx.style.display !== 'none'){ cx.style.display = 'none'; return; }
  cx.style.display = '';
  const c = CONTAS[id] || {};
  const tipo = c.papel_plataforma === 'admin' ? 'admin' : 'suporte';
  cx.innerHTML = `
    <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin:0">
      <div><label>Nome</label><input type="text" id="e-nome-${id}" value="${esc(c.nome || '')}"></div>
      <div><label>Email</label><input type="email" id="e-email-${id}" value="${esc(c.email || '')}"></div>
      <div><label>Tipo</label>
        <select id="e-tipo-${id}">
          <option value="suporte"${tipo === 'suporte' ? ' selected' : ''}>Suporte</option>
          <option value="admin"${tipo === 'admin' ? ' selected' : ''}>Admin da plataforma</option>
        </select></div>
      <div><button class="btn btn-ouro btn-sm" onclick="guardarConta(${id})">Guardar</button></div>
    </div>
    <div class="dica" style="margin:.6rem 0 0">${tipo === 'suporte'
      ? 'Suporte: entra nos casamentos com o código que cada casal gerar.'
      : 'Admin: responde pela casa — vê todos os casamentos, aprova registos e gere contas.'}</div>`;
}
async function guardarConta(id){
  const tipo = document.getElementById('e-tipo-' + id).value;
  const d = await api('utilizador_editar', { method:'POST', body: JSON.stringify({
    id, nome: document.getElementById('e-nome-' + id).value.trim(),
    email: document.getElementById('e-email-' + id).value.trim(),
    papel_plataforma: tipo,
  }) });
  if (d && d.success){ toast('Conta guardada.'); carregarContas(); }
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
// ---------- arranque ----------
const MANDA_NA_CASA = <?= $mandaNaCasa ? 'true' : 'false' ?>;
carregarCasamentos();
carregarContas();
if (document.getElementById('c-tipo')) tipoMudou();
</script>
</body>
</html>
