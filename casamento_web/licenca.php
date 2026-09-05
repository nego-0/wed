<?php
// ============================================================
// licenca.php — A licença do casal: o que tem, e o que pode ter
//
// É a única página que um casal recém-inscrito vê. De propósito: a conta abre
// no minuto em que ele se inscreve — não faz sentido pedir-lhe que escolha um
// plano e fechar-lhe a porta a seguir — mas até a administração conceder os
// módulos não há painel nenhum para mostrar. Então mostra-se-lhe isto: o seu
// pedido, com a possibilidade de lhe mexer enquanto ninguém decidiu.
//
// Depois de concedida a licença, a mesma página muda de função e passa a ser o
// sítio onde se vê o que se tem, quanto falta do prazo, quantos convidados
// cabem — e onde se pede um reforço.
//
// Chega-se aqui de três maneiras: pelo menu, por uma porta fechada
// (exigirModulo manda para cá com ?quero=<módulo>), ou por não haver mais nada
// para ver.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
exigirAdmin();

$cid   = casamentoAtual();
// Só ver, sem mexer. Duas gentes chegam aqui sem ser o casal:
//   • o suporte numa visita sem correcção — como em toda a casa;
//   • o pessoal da plataforma, que abre esta página para ver o que o casal vê.
//     Pedir um plano é um acto do casal (é ele que aceita as políticas, e é o
//     aceite dele que fica registado); a administração concede em Casamentos →
//     Licenças, que é onde a decisão tem nome e fica no registo de ações.
$souDaCasa = ehPessoalPlataforma();
$soVer = $souDaCasa || (emVisitaDeSuporte() && !podeCorrigir());
$DEFS  = defsAtuais($conn);
$CAS   = casalInfo($DEFS);

// O módulo que trouxe cá o casal, quando veio de uma porta fechada.
$quero = (string)($_GET['quero'] ?? '');
$modQuero = null;
if ($quero !== '') {
    $st = $conn->prepare("SELECT chave, nome, resumo, beneficio, icone FROM " . PREFIXO . "lic_modulos
                          WHERE chave=? AND ativo=1 LIMIT 1");
    if ($st) { $st->bind_param('s', $quero); $st->execute();
               $modQuero = $st->get_result()->fetch_assoc() ?: null; }
}
// E o que é que lhe faltava, ao certo: 'modulo' | 'editar' | 'modelos'.
//
// Não é o mesmo, e dizer que o módulo «não faz parte da sua licença» a quem o
// TEM — mas num escalão sem edição — é dizer-lhe uma coisa falsa, mesmo que a
// porta esteja fechada por boa razão. O casal olha para a montra, vê o convite
// impresso já marcado como seu, e não percebe do que se fala.
//
// Quem manda para cá diz o que estava a tentar fazer (?preciso=), e o que
// falta lê-se da licença — a fonte é sempre a licença, nunca o parâmetro.
$precisoQuero = in_array($_GET['preciso'] ?? '', ['editar','modelos'], true)
              ? $_GET['preciso'] : '';
$faltaQuero = '';

$LIC  = licencaEstado($conn, $cid);        // sem | pendente | ativa | revogada
$MODS = licencaModulos($conn, $cid);
$temAlgum = false;
foreach ($MODS as $m) if (!empty($m['ativo'])) { $temAlgum = true; break; }

if ($modQuero) {
    $tenho = $MODS[$modQuero['chave']] ?? null;
    if (empty($tenho['ativo']))                                  $faltaQuero = 'modulo';
    elseif ($precisoQuero === 'editar'  && empty($tenho['editar']))         $faltaQuero = 'editar';
    elseif ($precisoQuero === 'modelos' && empty($tenho['todos_modelos']))  $faltaQuero = 'modelos';
    // Sem nada em falta, o casal chegou aqui por uma ligação antiga ou à mão:
    // não se lhe inventa um problema que ele não tem.
    if ($faltaQuero === '') $modQuero = null;
}

// O título muda com o estado: quem espera não quer ler «A sua licença» como se
// já tivesse uma, e quem a perdeu ainda menos.
[$tit, $sub] = [
    'sem'      => ['Escolher o plano',   'Diga-nos o que precisa e nós abrimos-lhe a casa.'],
    'pendente' => ['O seu pedido',       'Está com a administração. Pode alterá-lo até ser decidido.'],
    'ativa'    => ['A sua licença',      'O que tem, até quando — e como crescer.'],
    'revogada' => ['Licença revogada',   'O acesso aos módulos está fechado.'],
][$LIC] ?? ['A sua licença', ''];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Licença · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/planos.css') ?>" rel="stylesheet">
<style>
  .lic-wrap{ max-width:1080px; margin:0 auto; padding:1.5rem 1.1rem 4rem; }
  .lic-estado{ display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap;
    border-radius:var(--radius); padding:1.3rem 1.4rem; margin-bottom:1.6rem;
    background:var(--card); border:1.5px solid var(--line); box-shadow:var(--shadow); }
  .lic-estado.espera{ border-color:var(--warn); background:var(--warn-bg); }
  .lic-estado.viva{ border-color:var(--ok); }
  .lic-estado.morta{ border-color:var(--danger); background:var(--danger-bg); }
  .lic-estado-ico{ width:48px; height:48px; flex:none; border-radius:14px;
    background:rgba(255,255,255,.7); display:flex; align-items:center; justify-content:center;
    font-size:1.4rem; }
  .lic-estado-txt{ flex:1; min-width:230px; }
  .lic-estado h2{ font-family:var(--serif); font-size:1.25rem; color:var(--ink); margin:0 0 .3rem; }
  .lic-estado p{ margin:0; font-size:.88rem; line-height:1.6; color:var(--text); }
  .lic-estado .ac{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }

  .lic-sec{ margin-bottom:2.4rem; }
  .lic-sec > h2{ font-family:var(--serif); font-size:1.35rem; color:var(--ink);
    margin:0 0 .3rem; }
  .lic-sec > .dica{ margin-bottom:1.1rem; }

  /* O pedido em cima da mesa, item a item. */
  .lic-pedido{ background:var(--card); border:1.5px solid var(--line);
    border-radius:var(--radius); overflow:hidden; }
  .lic-pedido-cab{ padding:1.1rem 1.3rem; background:var(--cream); border-bottom:1px solid var(--line);
    display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
  .lic-pedido-cab .nm{ font-family:var(--serif); font-size:1.2rem; color:var(--ink); flex:1; }
  .lic-pedido-cab .vl{ font-family:var(--serif); font-size:1.5rem; color:var(--ink);
    font-variant-numeric:tabular-nums; }
  .lic-itens{ list-style:none; margin:0; padding:.5rem 0; }
  .lic-itens li{ display:flex; gap:.8rem; align-items:baseline; padding:.6rem 1.3rem;
    font-size:.88rem; border-bottom:1px solid var(--line); }
  .lic-itens li:last-child{ border-bottom:none; }
  .lic-itens .mod{ font-weight:700; color:var(--ink); min-width:9.5rem; }
  .lic-itens .med{ flex:1; color:#8a8f88; }
  .lic-itens .pr{ font-variant-numeric:tabular-nums; color:var(--gold); font-weight:600; }
  .lic-pedido-pe{ padding:1rem 1.3rem; border-top:1px solid var(--line);
    display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
  .lic-pedido-pe .dica{ flex:1; margin:0; min-width:180px; }

  .lic-nota{ background:var(--cream); border-left:3px solid var(--gold); border-radius:8px;
    padding:.7rem .9rem; font-size:.85rem; line-height:1.55; margin-top:.9rem; }
  .lic-nota b{ color:var(--ink); }

  /* Campo de texto para a mensagem ao administrador. */
  .lic-msg{ margin-top:1.2rem; }
  .lic-msg label{ display:block; font-size:.82rem; font-weight:600; color:var(--ink); margin-bottom:.35rem; }
  .lic-msg textarea{ width:100%; min-height:74px; border:1.5px solid var(--line); border-radius:12px;
    padding:.65rem .8rem; font-family:var(--sans); font-size:.88rem; color:var(--text);
    background:var(--card); resize:vertical; }
  .lic-msg textarea:focus{ outline:none; border-color:var(--gold); box-shadow:0 0 0 3px var(--ring); }

  /* A ficha da licença: os factos, em pares rótulo/valor. */
  .lic-ficha{ display:grid; gap:.9rem 1.6rem; background:var(--card); border:1.5px solid var(--line);
    border-radius:var(--radius); padding:1.2rem 1.3rem;
    grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); }
  .lic-f{ display:flex; flex-direction:column; gap:.15rem; }
  .lic-f .r{ font-size:.7rem; letter-spacing:.07em; text-transform:uppercase; color:#8a8f88; }
  .lic-f .v{ font-size:1rem; color:var(--ink); font-weight:600; }
  .lic-f .v.grande{ font-family:var(--serif); font-size:1.4rem; font-weight:400;
    font-variant-numeric:tabular-nums; }
  .lic-f .n{ font-size:.76rem; color:#8a8f88; }
  .lic-f .v.aviso{ color:var(--warn); }

  /* O extracto dos pedidos já decididos. */
  .lic-h{ display:flex; gap:.9rem; align-items:flex-start; padding:.85rem 0;
    border-bottom:1px solid var(--line); flex-wrap:wrap; }
  .lic-h:last-child{ border-bottom:none; }
  .lic-h-selo{ width:26px; height:26px; flex:none; border-radius:50%; display:flex;
    align-items:center; justify-content:center; font-size:.8rem; font-weight:700; }
  .lic-h-selo.ok{ background:var(--ok-bg); color:var(--ok); }
  .lic-h-selo.nao{ background:var(--danger-bg); color:var(--danger); }
  .lic-h-txt{ flex:1; min-width:180px; }
  .lic-h-tit{ font-weight:600; color:var(--ink); font-size:.9rem; }
  .lic-h-det{ font-size:.79rem; color:#8a8f88; line-height:1.5; margin-top:.1rem; }
  .lic-h-vl{ font-variant-numeric:tabular-nums; font-weight:700; color:var(--ink);
    white-space:nowrap; text-align:right; }
  /* A nota que a administração escreveu. Aparece sempre que exista — também
     nas aprovações, onde antes se perdia. */
  .lic-h-nota{ display:block; font-size:.79rem; line-height:1.5; color:var(--text);
    margin-top:.35rem; background:var(--cream); border-left:3px solid var(--gold);
    border-radius:6px; padding:.4rem .6rem; }
  .lic-h-nota b{ color:var(--ink); }
  /* Cada linha abre o seu detalhe: diz-se que é clicável, e onde carregar. */
  .lic-h-clic{ cursor:pointer; border-radius:10px; margin:0 -.6rem; padding-left:.6rem;
    padding-right:.6rem; transition:background .12s; }
  .lic-h-clic:hover, .lic-h-clic:focus-visible{ background:var(--cream); outline:none; }
  .lic-h-clic:focus-visible{ box-shadow:0 0 0 2px var(--ring); }
  .lic-h-ver{ display:block; font-size:.7rem; font-weight:600; color:var(--gold);
    text-transform:uppercase; letter-spacing:.06em; margin-top:.15rem; }

  /* ---- o detalhe de uma decisão, na janela e no papel ---- */
  .lic-det{ font-size:.88rem; color:var(--text); }
  .lic-det-cab{ display:flex; gap:1rem; align-items:flex-start;
    border-bottom:1px solid var(--line); padding-bottom:.9rem; margin-bottom:1rem; }
  .lic-det-tit{ font-family:var(--serif); font-size:1.15rem; color:var(--ink); }
  .lic-det-sub{ font-size:.82rem; color:#8a8f88; margin-top:.15rem; }
  .lic-det-selo{ width:34px; height:34px; flex:none; border-radius:50%; display:flex;
    align-items:center; justify-content:center; font-weight:700; }
  .lic-det-selo.ok{ background:var(--ok-bg); color:var(--ok); }
  .lic-det-selo.nao{ background:var(--danger-bg); color:var(--danger); }
  .lic-d-tab{ display:grid; gap:.1rem; }
  .lic-d-l{ display:flex; gap:1rem; padding:.4rem 0; border-bottom:1px solid var(--line); }
  .lic-d-l:last-child{ border-bottom:none; }
  .lic-d-l span{ flex:1; color:#8a8f88; font-size:.82rem; }
  .lic-d-l b{ color:var(--ink); text-align:right; }
  .lic-d-mau{ color:var(--danger); }
  .lic-d-sec{ font-size:.72rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
    color:#8a8f88; margin:1.3rem 0 .5rem; }
  .lic-d-mods{ width:100%; border-collapse:collapse; font-size:.84rem; }
  .lic-d-mods th{ text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em;
    color:#8a8f88; font-weight:600; padding:.3rem .5rem; border-bottom:1px solid var(--line); }
  .lic-d-mods td{ padding:.45rem .5rem; border-bottom:1px solid var(--line); }
  .lic-d-mods .n{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .lic-d-mods tfoot td{ font-weight:700; color:var(--ink); border-bottom:none;
    border-top:2px solid var(--line); }
  .lic-d-nota{ background:var(--cream); border-left:3px solid var(--gold); border-radius:8px;
    padding:.7rem .9rem; line-height:1.6; white-space:pre-wrap; }
  .lic-d-nota.mau{ border-left-color:var(--danger); background:var(--danger-bg); }
  /* Só sai no papel — no ecrã, a janela já diz de quem é. */
  .lic-d-rodape{ display:none; }

  /* Imprimir o detalhe, e SÓ o detalhe. Sem isto, o window.print() levava a
     montra dos planos inteira atrás do que se queria no papel. */
  @media print{
    body.a-imprimir-detalhe > *{ display:none !important; }
    body.a-imprimir-detalhe #lic-janela{ display:block !important; position:static;
      background:none; padding:0; }
    body.a-imprimir-detalhe #lic-janela .pl-modal-cx{ box-shadow:none; border:0;
      max-width:none; max-height:none; }
    body.a-imprimir-detalhe #lic-janela .pl-modal-cab,
    body.a-imprimir-detalhe #lic-janela .pl-modal-rodape{ display:none !important; }
    body.a-imprimir-detalhe #lic-janela .pl-modal-corpo{ padding:0; overflow:visible; }
    .lic-d-rodape{ display:block !important; margin-top:1.6rem; padding-top:.6rem;
      border-top:1px solid #ccc; font-size:.7rem; color:#666; }
  }

  @media (max-width:640px){
    .lic-wrap{ padding:1rem .8rem 3rem; }
    .lic-itens li{ flex-wrap:wrap; gap:.3rem .8rem; }
    .lic-itens .mod{ min-width:0; }
  }
</style>
</head>
<body>
<?php cabecalho($tit, $sub, 'licenca'); ?>

<div class="lic-wrap">

<?php if ($souDaCasa): /* A casa a ver a licença de um casal. */ ?>
  <div class="lic-estado" style="border-color:var(--gold-soft); background:var(--gold-pale)">
    <div class="lic-estado-ico">👀</div>
    <div class="lic-estado-txt">
      <h2>Está a ver a licença de <?= escP($CAS['casal']) ?></h2>
      <p>É exactamente esta a página que o casal vê. Aqui não se pede nem se decide nada —
         a licença concede-se em
         <a href="plataforma.php"><b>Casamentos → Licenças</b></a>, que é onde a decisão
         fica com nome e data no registo de ações.</p>
    </div>
  </div>
<?php endif; ?>

<?php if ($modQuero):
      /* Veio de uma porta fechada: fala-se primeiro do que lhe falta — e do que
         lhe falta A SÉRIO. Ter o módulo num escalão sem edição não é o mesmo
         que não o ter, e tratá-los pela mesma frase deixava o casal a olhar
         para uma licença onde o módulo estava marcado como seu. */
      $temQuero  = $MODS[$modQuero['chave']] ?? [];
      $escQuero  = (string)($temQuero['nome'] ?? '');
?>
  <div class="pl-porta">
    <div class="pl-porta-ico"><?= escP($modQuero['icone'] ?: '🔒') ?></div>

    <?php if ($faltaQuero === 'modulo'): ?>
      <h2><?= escP($modQuero['nome']) ?> não faz parte da sua licença</h2>
      <?php if ($modQuero['beneficio']): ?>
        <p class="benef"><?= escP($modQuero['beneficio']) ?></p>
      <?php endif; ?>
      <p><?= escP($modQuero['resumo']) ?></p>
      <p style="margin-top:1rem">
        <?= $LIC === 'ativa'
            ? 'Junte-o à sua licença abaixo — o que já tem fica exactamente como está.'
            : 'Escolha abaixo o plano que o inclui.' ?></p>

    <?php elseif ($faltaQuero === 'editar'): ?>
      <h2>O editor do <?= escP(mb_strtolower($modQuero['nome'])) ?> não está aberto na sua licença</h2>
      <p class="benef">Tem o <?= escP(mb_strtolower($modQuero['nome'])) ?>
         <?= $escQuero !== '' ? 'no escalão «' . escP($escQuero) . '»' : '' ?>,
         e ele continua a funcionar: os convidados recebem-no na mesma.</p>
      <p>O que esse escalão <b>não</b> abre é o <b>editor</b> — mudar tipos de letra, cores,
         fotografias e a composição da peça. Para desenhar o convite à sua maneira,
         <b>suba o escalão deste módulo</b> para um <b>com edição</b>.</p>
      <p style="margin-top:1rem">Escolha-o abaixo. <b>Paga só a diferença</b> — o que já tem
         pago neste módulo é descontado.</p>

    <?php else: /* modelos */ ?>
      <h2>A sua licença dá-lhe o modelo padrão desta peça</h2>
      <p class="benef">Tem o <?= escP(mb_strtolower($modQuero['nome'])) ?>
         <?= $escQuero !== '' ? 'no escalão «' . escP($escQuero) . '»' : '' ?>,
         com o desenho da casa.</p>
      <p>Para escolher entre <b>todos os modelos</b> da galeria, é preciso o escalão que os
         inclui.</p>
      <p style="margin-top:1rem">Escolha-o abaixo. <b>Paga só a diferença</b> — o que já tem
         pago neste módulo é descontado.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php // ---- a barra de estado: onde é que este casal está ---- ?>
<?php if ($LIC === 'pendente'): ?>
  <div class="lic-estado espera">
    <div class="lic-estado-ico">⏳</div>
    <div class="lic-estado-txt">
      <h2>O seu pedido está à espera de decisão</h2>
      <p>Já tem conta e já entrou — falta só a administração conceder os módulos que pediu.
         Até lá, pode mudar o pedido as vezes que quiser: é seu.</p>
    </div>
  </div>
<?php elseif ($LIC === 'revogada'):
      $r = $conn->query("SELECT licenca_revogada_motivo, licenca_revogada_em FROM " . PREFIXO
                        . "casamentos WHERE id=" . (int)$cid);
      $rev = $r ? $r->fetch_assoc() : null; ?>
  <div class="lic-estado morta">
    <div class="lic-estado-ico">⚠️</div>
    <div class="lic-estado-txt">
      <h2>A licença deste casamento foi revogada</h2>
      <p><?= $rev && $rev['licenca_revogada_motivo']
              ? '<b>Motivo:</b> ' . escP($rev['licenca_revogada_motivo'])
              : 'Por incumprimento das políticas de utilização.' ?></p>
      <p style="margin-top:.5rem">Os seus dados não foram apagados: pode exportá-los a qualquer
         momento na página de Gestão. Para repor o acesso, fale com a administração.</p>
    </div>
  </div>
<?php elseif ($LIC === 'ativa'):
      $inf = licencaInfo($conn, $cid);
      $frase = licencaFrase($inf);
      $r = $conn->query("SELECT licenca_pacote FROM " . PREFIXO . "casamentos WHERE id=" . (int)$cid);
      $pac = $r ? (string)($r->fetch_assoc()['licenca_pacote'] ?? '') : ''; ?>
  <div class="lic-estado viva">
    <div class="lic-estado-ico">✓</div>
    <div class="lic-estado-txt">
      <h2>Licença ativa<?= $pac !== '' ? ' · ' . escP($pac) : '' ?></h2>
      <p><?= $frase !== '' ? escP($frase) : 'Sem limite de tempo.' ?></p>
    </div>
  </div>
<?php endif; ?>

<?php // ---- o que este casamento tem, módulo a módulo ---- ?>
<?php if ($temAlgum):
  $rm = $conn->query("SELECT chave, nome, icone FROM " . PREFIXO . "lic_modulos ORDER BY ordem, id");
  $cat = [];
  if ($rm) while ($x = $rm->fetch_assoc()) $cat[$x['chave']] = $x;
  $usados = convidadosContados($conn, $cid); ?>
  <div class="lic-sec">
    <h2>O que a sua licença abre</h2>
    <div class="dica">Os módulos que estão de pé, e em que medida.</div>
    <div class="pl-tenho">
      <?php foreach ($cat as $ch => $m):
        $g = $MODS[$ch] ?? ['ativo' => false];
        $tem = !empty($g['ativo']); ?>
        <div class="pl-tenho-c <?= $tem ? 'sim' : 'nao' ?>">
          <div class="pl-tenho-ico"><?= escP($m['icone'] ?: '•') ?></div>
          <div style="flex:1">
            <div class="pl-tenho-n"><?= escP($m['nome']) ?></div>
            <?php if (!$tem): ?>
              <div class="pl-tenho-d">Não incluído</div>
            <?php elseif ($ch === 'convidados'):
              $lim = (int)$g['limite'];
              $pc  = $lim > 0 ? min(100, round($usados / max(1, $lim) * 100)) : 0; ?>
              <div class="pl-tenho-d">
                <?= $lim > 0
                    ? '<b>' . $usados . '</b> de ' . $lim . ' convidados'
                    : '<b>' . $usados . '</b> convidados · sem limite' ?>
              </div>
              <?php if ($lim > 0): ?>
                <div class="pl-medidor<?= $pc >= 90 ? ' cheio' : '' ?>"><i style="width:<?= $pc ?>%"></i></div>
              <?php endif; ?>
            <?php elseif ($ch === 'impresso' || $ch === 'digital'): ?>
              <div class="pl-tenho-d"><b>✓</b>
                <?= !empty($g['todos_modelos']) ? 'Todos os modelos, com edição'
                    : (!empty($g['editar']) ? 'Modelo padrão, com edição' : 'Modelo padrão, sem edição') ?></div>
            <?php else: ?>
              <div class="pl-tenho-d"><b>✓</b> Incluído</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php // ---- os detalhes da licença em vigor ---- ?>
<div id="lic-detalhes" class="lic-sec" style="display:none"></div>

<?php // ---- o pedido em cima da mesa ---- ?>
<div id="lic-pedido-cx" class="lic-sec" style="display:none"></div>

<?php // ---- o extracto: o que já foi pedido e decidido ---- ?>
<div id="lic-hist" class="lic-sec" style="display:none"></div>

<?php // ---- a montra ---- ?>
<div class="lic-sec" id="lic-montra" style="display:none">
  <div class="pl-topo">
    <span class="pl-selo" id="lic-selo">Planos</span>
    <h2 id="lic-montra-tit">Escolha o que quer levar</h2>
    <p id="lic-montra-sub">Um pacote pronto, ou um plano à sua medida. Muda depois, quando quiser.</p>
  </div>
  <div id="lic-planos"></div>

  <div class="pl-aceite" id="lic-aceite-cx">
    <input type="checkbox" id="lic-aceite">
    <label for="lic-aceite">
      Li e aceito as <a id="lic-ver-pol">políticas de utilização e protecção de dados</a>,
      e confirmo que os dados dos convidados que vier a registar são tratados nos termos
      da Lei n.º 22/11, de 17 de Junho.
    </label>
  </div>

  <div class="lic-msg">
    <label for="lic-nota">Alguma coisa que a administração deva saber? <span class="dica" style="display:inline;margin:0">(opcional)</span></label>
    <textarea id="lic-nota" maxlength="1000" placeholder="Por exemplo: a data do casamento, ou um pedido especial."></textarea>
  </div>

  <div style="margin-top:1.2rem; display:flex; gap:.6rem; flex-wrap:wrap">
    <button class="btn btn-ouro" id="lic-submeter" <?= $soVer ? 'disabled' : '' ?>>Submeter pedido</button>
    <button class="btn btn-fantasma" id="lic-ver-pol2">Ler as políticas</button>
  </div>
</div>

</div><!-- /lic-wrap -->

<script>
window.CSRF = '<?= escP(csrfToken()) ?>';
const SO_VER = <?= $soVer ? 'true' : 'false' ?>;
const LIC_ESTADO = '<?= escP($LIC) ?>';
</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/planos.js') ?>"></script>
<script>
let LIC = null;

function fmt(v){ return Planos.moeda(v); }

function toast(msg, mau){
  let t = document.getElementById('lic-toast');
  if (!t){
    t = document.createElement('div'); t.id = 'lic-toast';
    t.style.cssText = 'position:fixed;left:50%;bottom:1.5rem;transform:translateX(-50%);z-index:3000;'
      + 'padding:.75rem 1.15rem;border-radius:50px;font-size:.88rem;font-weight:600;'
      + 'box-shadow:0 10px 30px rgba(0,0,0,.25);max-width:90vw;text-align:center';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.background = mau ? 'var(--danger)' : 'var(--forest)';
  t.style.color = '#fff';
  t.style.display = 'block';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.display = 'none'; }, 4200);
}

async function carregar(){
  const d = await api('lic_estado');
  if (!d || !d.success) return;
  LIC = d.licenca;
  desenharDetalhes();
  desenharPedido();
  desenharHistorico();
  desenharMontra();
}

// ---- os detalhes da licença em vigor ----
// Não é decoração: é o extracto do que o casal contratou. Quem paga tem
// direito a saber, sem perguntar a ninguém, o que comprou e até quando.
function desenharDetalhes(){
  const cx = document.getElementById('lic-detalhes');
  const c = LIC.casamento, pr = LIC.prazo;
  if (c.licenca_estado !== 'ativa'){ cx.style.display = 'none'; return; }

  // Quanto já foi aprovado, ao todo — a soma do que o casal pagou pela licença.
  const pago = (LIC.historico || [])
    .filter(h => h.estado === 'aprovado')
    .reduce((t, h) => t + (+h.total || 0), 0);

  const campos = [];
  campos.push(f('Plano', escapar(c.licenca_pacote || 'À medida')));
  campos.push(f('Módulos abertos',
    Object.values(LIC.modulos).filter(m => m.ativo).length + ' de '
    + Object.keys(LIC.modulos).length));
  if (pr.ilimitada){
    campos.push(f('Prazo', 'Sem limite', 'A licença não expira.'));
  } else if (!pr.iniciada){
    campos.push(f('Prazo', pr.meses + ' meses', 'O relógio ainda não começou.', 'aviso'));
  } else {
    const dias = +pr.dias;
    campos.push(f('Válida até', dataCurta(pr.ate),
      dias >= 0 ? 'faltam ' + dias + ' dia(s)' : 'expirou há ' + Math.abs(dias) + ' dia(s)',
      dias < 30 ? 'aviso' : ''));
    campos.push(f('Período contratado', pr.meses + ' meses'));
  }
  const lim = LIC.modulos.convidados && LIC.modulos.convidados.ativo
            ? +LIC.modulos.convidados.limite : null;
  if (lim !== null){
    campos.push(f('Convidados', LIC.convidados + (lim > 0 ? ' de ' + lim : ''),
      lim > 0 ? (lim - LIC.convidados) + ' lugares por usar' : 'sem limite',
      lim > 0 && LIC.convidados >= lim * 0.9 ? 'aviso' : ''));
  }
  if (pago > 0) campos.push(f('Total da licença', fmt(pago), 'aprovado até hoje', '', true));

  cx.style.display = '';
  cx.innerHTML = '<h2>Detalhes da licença em vigor</h2>'
    + '<div class="dica">O que contratou, e até quando.</div>'
    + '<div class="lic-ficha">' + campos.join('') + '</div>';

  function f(rot, val, nota, cls, grande){
    return '<div class="lic-f"><span class="r">' + rot + '</span>'
      + '<span class="v' + (grande ? ' grande' : '') + (cls ? ' ' + cls : '') + '">' + val + '</span>'
      + (nota ? '<span class="n">' + nota + '</span>' : '') + '</div>';
  }
}

// ---- o extracto: o que já foi pedido e decidido ----
/** Como se chama, em português, cada linha do histórico. */
function histRotulo(x){
  if (x.tipo === 'revogacao') return 'Licença revogada';
  return (x.tipo === 'upgrade' ? 'Reforço da licença' : 'Pedido inicial')
       + (x.estado === 'aprovado' ? ' · aprovado' : ' · recusado');
}
function histSelo(x){
  if (x.tipo === 'revogacao') return ['nao', '⚠'];
  return x.estado === 'aprovado' ? ['ok', '✓'] : ['nao', '✕'];
}

function desenharHistorico(){
  const cx = document.getElementById('lic-hist');
  const h = LIC.historico || [];
  if (!h.length){ cx.style.display = 'none'; cx.innerHTML = ''; return; }
  cx.style.display = '';
  cx.innerHTML = '<h2>Histórico da licença</h2>'
    + '<div class="dica">Tudo o que já foi decidido sobre esta licença, do mais recente para '
    +   'trás. <b>Carregue numa linha</b> para ver os detalhes e imprimir.</div>'
    + '<div class="lic-pedido" style="padding:.3rem 1.3rem">'
    + h.map((x, i) => {
        const [cls, ico] = histSelo(x);
        const mods = (x.itens || []).map(i2 => escapar(nomeModulo(i2.modulo_chave))).join(', ');
        // A nota do admin aparece SEMPRE que exista — não só nas recusas.
        // Quando ele escreve alguma coisa é porque quer que o casal a leia, e
        // escondê-la nas aprovações fazia com que metade das notas nunca
        // chegasse a ninguém. (Aqui vai cortada; o modal traz-lha inteira.)
        const nota = (x.nota_admin || '').trim();
        return '<div class="lic-h lic-h-clic" role="button" tabindex="0" data-hist="' + i + '">'
          + '<span class="lic-h-selo ' + cls + '">' + ico + '</span>'
          + '<span class="lic-h-txt"><span class="lic-h-tit">' + escapar(histRotulo(x))
          +   (x.pacote_nome ? ' · ' + escapar(x.pacote_nome) : '') + '</span>'
          + '<span class="lic-h-det">' + dataCurta(x.decidido_em)
          +   (mods ? ' · ' + mods : '')
          +   (x.estado === 'aprovado' && x.meses ? ' · ' + x.meses + ' meses' : '') + '</span>'
          + (nota ? '<span class="lic-h-nota"><b>'
              + (x.tipo === 'revogacao' ? 'Motivo' : 'Nota da administração')
              + ':</b> ' + escapar(cortar(nota, 150)) + '</span>' : '')
          + '</span>'
          + '<span class="lic-h-vl">'
          +   (x.estado === 'aprovado' ? fmt(x.total) : '—')
          +   '<span class="lic-h-ver">ver</span></span>'
          + '</div>';
      }).join('')
    + '</div>';

  cx.querySelectorAll('[data-hist]').forEach(el => {
    const abrir = () => janelaHistorico(+el.dataset.hist);
    el.addEventListener('click', abrir);
    el.addEventListener('keydown', ev => {
      if (ev.key === 'Enter' || ev.key === ' '){ ev.preventDefault(); abrir(); }
    });
  });
}

function cortar(s, n){ s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

// ---- o pedido em cima da mesa ----
function desenharPedido(){
  const cx = document.getElementById('lic-pedido-cx');
  const p  = LIC.pendente;
  if (!p){ cx.style.display = 'none'; cx.innerHTML = ''; return; }

  const itens = p.itens.map(it => {
    const e = { modulo: it.modulo_chave, limite: +it.limite, editar: +it.editar,
                todos_modelos: +it.todos_modelos, nome: it.escalao_nome };
    return '<li><span class="mod">' + nomeModulo(it.modulo_chave) + '</span>'
         + '<span class="med">' + Planos.medida(e) + '</span>'
         + '<span class="pr">' + fmt(it.preco) + '</span></li>';
  }).join('');

  cx.style.display = '';
  cx.innerHTML = '<h2>O pedido que está com a administração</h2>'
    + '<div class="dica">Submetido em ' + dataCurta(p.criado_em)
    + '. Pode alterá-lo ou cancelá-lo enquanto não for decidido.</div>'
    + '<div class="lic-pedido">'
    +   '<div class="lic-pedido-cab"><span class="nm">'
    +     (p.pacote_nome ? 'Pacote ' + escapar(p.pacote_nome) : 'Plano à medida')
    +     ' · ' + p.meses + ' meses</span>'
    +     '<span class="vl">' + fmt(p.total) + '</span></div>'
    +   '<ul class="lic-itens">' + itens + '</ul>'
    +   (p.nota_casal ? '<div style="padding:0 1.3rem 1rem"><div class="lic-nota">'
        + '<b>A sua mensagem:</b> ' + escapar(p.nota_casal) + '</div></div>' : '')
    +   '<div class="lic-pedido-pe">'
    +     '<span class="dica">Aceitou as políticas de utilização (versão '
    +       (p.politica_versao || 1) + ') em ' + dataCurta(p.aceite_em) + '.</span>'
    +     '<button class="btn btn-linha btn-sm" onclick="alterarPedido()"'
    +     (SO_VER ? ' disabled' : '') + '>Alterar</button>'
    +     '<button class="btn btn-fantasma btn-sm" onclick="cancelarPedido()"'
    +     (SO_VER ? ' disabled' : '') + '>Cancelar pedido</button>'
    +   '</div>'
    + '</div>';
}

// O pedido recusado tinha aqui um cartão só para ele. Deixou de ser preciso: o
// histórico passou a mostrar todas as decisões com a sua data e o motivo que a
// administração escreveu, e a recusa é uma delas. Duas vezes a mesma coisa, uma
// por cima da outra, fazia parecer que eram duas.

// ---- a montra ----
function desenharMontra(){
  const cx = document.getElementById('lic-montra');
  const temPendente = !!LIC.pendente;
  const ativa = LIC.casamento.licenca_estado === 'ativa';

  // Com um pedido em cima da mesa, a montra só se abre a pedido — senão o
  // casal vê duas coisas a competir pela mesma decisão.
  if (temPendente){ cx.style.display = 'none'; return; }
  cx.style.display = '';

  document.getElementById('lic-selo').textContent = ativa ? 'Reforçar a licença' : 'Planos';
  document.getElementById('lic-montra-tit').textContent = ativa
    ? 'Cresça sem perder nada do que já fez'
    : 'Escolha o que quer levar';
  document.getElementById('lic-montra-sub').textContent = ativa
    ? 'Junte módulos à sua licença. O que já tem fica como está — e paga só a diferença.'
    : 'Um pacote pronto, ou um plano à sua medida. Muda depois, quando quiser.';
  document.getElementById('lic-submeter').textContent = ativa ? 'Pedir reforço' : 'Submeter pedido';

  Planos.montar('lic-planos', LIC.catalogo, {
    tenho: LIC.modulos, moeda: LIC.moeda,
    // Com licença ativa não há pacotes nem sugestão: quem já tem alguma coisa
    // quer acrescentar, e um pacote — de preço fechado, com coisas que ele já
    // tem lá dentro — desmentia a promessa de que paga só a diferença.
    pacotes: !ativa,
    sugerir: !ativa
  });
}

function alterarPedido(){
  document.getElementById('lic-montra').style.display = '';
  const p = LIC.pendente;
  // A alterar um pedido: mostram-se os pacotes se ainda não há licença de pé
  // (é um pedido inicial), e só os módulos se já há (é um reforço).
  const temLic = LIC.casamento.licenca_estado === 'ativa';
  Planos.montar('lic-planos', LIC.catalogo,
                { tenho: LIC.modulos, moeda: LIC.moeda, pacotes: !temLic, sugerir: false });
  Planos.repor(p.pacote_id ? +p.pacote_id : 0, p.itens.map(i => +i.escalao_id));
  document.getElementById('lic-nota').value = p.nota_casal || '';
  document.getElementById('lic-aceite').checked = false;
  document.getElementById('lic-montra').scrollIntoView({ behavior:'smooth', block:'start' });
}

async function cancelarPedido(){
  const r = await licConfirmar({
    titulo: 'Cancelar o pedido de licença?',
    icone: '↩️', confirmar: 'Cancelar pedido',
    texto: 'O pedido sai da fila da administração e <b>deixa de ser analisado</b>.<br><br>'
         + 'Pode <b>voltar a pedir quando quiser</b>, e escolher outro plano.'
  });
  if (!r.sim) return;
  const d = await api('lic_pedido_cancelar', { method:'POST', body: JSON.stringify({}) });
  if (!d || !d.success) return;
  toast('Pedido cancelado.');
  LIC = d.licenca; desenharPedido(); desenharMontra();
}

async function submeter(){
  const cxA = document.getElementById('lic-aceite-cx');
  const aceite = document.getElementById('lic-aceite');
  const c = Planos.escolha();
  if (c.vazio){ toast('Escolha um pacote, ou pelo menos um módulo.', true); return; }
  if (!aceite.checked){
    cxA.classList.add('mau');
    toast('É preciso aceitar as políticas de utilização.', true);
    cxA.scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }
  cxA.classList.remove('mau');
  const b = document.getElementById('lic-submeter');
  b.disabled = true; const rot = b.textContent; b.textContent = 'A enviar…';
  const d = await api('lic_pedir', { method:'POST', body: JSON.stringify({
    pacote: c.pacote, escaloes: c.escaloes, meses: c.meses,
    nota: document.getElementById('lic-nota').value, aceito: true
  })});
  b.disabled = false; b.textContent = rot;
  if (!d || !d.success) return;
  toast('Pedido enviado. A administração vai analisá-lo.');
  LIC = d.licenca;
  document.getElementById('lic-nota').value = '';
  aceite.checked = false;
  desenharPedido(); desenharMontra();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---- o detalhe de uma linha do histórico ----
/**
 * Tudo o que se passou naquele dia, numa janela — e pronto a imprimir.
 *
 * A linha do histórico é um resumo: cabe-lhe dizer o quê e quando. O resto (o
 * que cada módulo custou, o que foi descontado, a nota inteira da
 * administração, as datas do pedido e da decisão) é o que se procura quando há
 * dúvidas sobre a conta — e é para isso que a janela existe, com um botão de
 * imprimir para quem precisa do papel.
 */
function janelaHistorico(i){
  const x = (LIC.historico || [])[i];
  if (!x) return;
  const rev = x.tipo === 'revogacao';
  const ok  = x.estado === 'aprovado';

  const linha = (rot, val) => val === '' || val == null ? ''
    : '<div class="lic-d-l"><span>' + escapar(rot) + '</span><b>' + val + '</b></div>';

  let corpo = '<div class="lic-det" id="lic-det-imp">'
    + '<div class="lic-det-cab"><div>'
    +   '<div class="lic-det-tit">' + escapar(histRotulo(x)) + '</div>'
    +   '<div class="lic-det-sub">' + escapar(LIC.casamento.nome || '') + '</div>'
    + '</div><div class="lic-det-selo ' + (rev || !ok ? 'nao' : 'ok') + '">'
    +   (rev ? '⚠' : ok ? '✓' : '✕') + '</div></div>';

  corpo += '<div class="lic-d-tab">'
    + linha(rev ? 'Data da revogação' : 'Decidido em', escapar(dataLonga(x.decidido_em)))
    + (rev ? '' : linha('Pedido em', escapar(dataLonga(x.criado_em))))
    + (rev ? '' : linha('Tipo', x.tipo === 'upgrade'
        ? 'Reforço de uma licença já em vigor' : 'Pedido inicial'))
    + (x.pacote_nome ? linha('Pacote', escapar(x.pacote_nome)) : '')
    + (!rev && ok && x.meses ? linha('Prazo contratado', x.meses + ' meses') : '')
    + (rev && x.em_vigor ? linha('Estado', '<span class="lic-d-mau">A licença continua '
        + 'revogada</span>') : '')
    + '</div>';

  if ((x.itens || []).length){
    const temCred = x.itens.some(it => +it.credito > 0);
    corpo += '<div class="lic-d-sec">' + (ok ? 'Módulos concedidos' : 'Módulos pedidos') + '</div>'
      + '<table class="lic-d-mods"><thead><tr><th>Módulo</th><th>Escalão</th>'
      + (temCred ? '<th class="n">Já pago</th>' : '') + '<th class="n">Valor</th></tr></thead><tbody>'
      + x.itens.map(it => '<tr><td>' + nomeModulo(it.modulo_chave) + '</td>'
          + '<td>' + escapar(it.escalao_nome || '') + '</td>'
          + (temCred ? '<td class="n">' + (+it.credito > 0 ? '− ' + fmt(it.credito) : '—') + '</td>' : '')
          + '<td class="n">' + fmt(it.preco) + '</td></tr>').join('')
      + '</tbody><tfoot><tr><td colspan="' + (temCred ? 3 : 2) + '">'
      + (x.tipo === 'upgrade' ? 'Diferença paga' : 'Total') + '</td>'
      + '<td class="n">' + fmt(x.total) + '</td></tr></tfoot></table>';
  }

  const nota = (x.nota_admin || '').trim();
  if (nota){
    corpo += '<div class="lic-d-sec">'
      + (rev ? 'Motivo da revogação' : 'Nota da administração') + '</div>'
      + '<div class="lic-d-nota' + (rev ? ' mau' : '') + '">' + escapar(nota) + '</div>';
  }
  corpo += '<div class="lic-d-rodape">' + escapar(LIC.casamento.nome || '')
        + ' · impresso em ' + escapar(dataLonga(new Date().toISOString())) + '</div>';
  corpo += '</div>';

  licJanela(escapar(histRotulo(x)), corpo, null, { cancelar: 'Fechar' });
  // O botão de imprimir vive no rodapé da janela, ao lado do «Fechar».
  const rod = document.querySelector('#lic-janela .pl-modal-rodape');
  if (rod){
    const b = document.createElement('button');
    b.className = 'btn btn-ouro btn-sm';
    b.textContent = 'Imprimir';
    b.onclick = imprimirDetalhe;
    rod.appendChild(b);
  }
}

/**
 * Imprime só o detalhe, e não a página inteira por trás dele.
 *
 * Marca-se o corpo com uma classe e deixa-se o CSS esconder o resto: uma
 * janela nova seria bloqueada pelos travões de pop-ups, e um window.print()
 * sem isto imprimia a montra dos planos toda a seguir ao que se queria.
 */
function imprimirDetalhe(){
  document.body.classList.add('a-imprimir-detalhe');
  const limpar = () => document.body.classList.remove('a-imprimir-detalhe');
  window.addEventListener('afterprint', limpar, { once: true });
  setTimeout(() => { window.print(); setTimeout(limpar, 1500); }, 60);
}

// ---- apoio ----
function dataLonga(s){
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? String(s) : d.toLocaleDateString('pt-PT',
    { day:'2-digit', month:'long', year:'numeric' });
}
function nomeModulo(ch){
  const m = (LIC.catalogo.modulos || []).find(x => x.chave === ch);
  return escapar(m ? m.nome : ch);
}
function escapar(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function dataCurta(s){
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? String(s) : d.toLocaleDateString('pt-PT',
    { day:'2-digit', month:'2-digit', year:'numeric' });
}

function verPoliticas(comAceitar){
  Planos.politicas(LIC.politica, comAceitar ? () => {
    const a = document.getElementById('lic-aceite');
    a.checked = true;
    document.getElementById('lic-aceite-cx').classList.remove('mau');
  } : null);
}

document.getElementById('lic-submeter').addEventListener('click', submeter);
document.getElementById('lic-ver-pol').addEventListener('click', () => verPoliticas(true));
document.getElementById('lic-ver-pol2').addEventListener('click', () => verPoliticas(true));
document.getElementById('lic-aceite').addEventListener('change', () =>
  document.getElementById('lic-aceite-cx').classList.remove('mau'));

carregar();
</script>
</body>
</html>
