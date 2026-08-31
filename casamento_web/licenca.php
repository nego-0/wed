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
$soVer = emVisitaDeSuporte() && !podeCorrigir();
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

$LIC  = licencaEstado($conn, $cid);        // sem | pendente | ativa | revogada
$MODS = licencaModulos($conn, $cid);
$temAlgum = false;
foreach ($MODS as $m) if (!empty($m['ativo'])) { $temAlgum = true; break; }

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
    white-space:nowrap; }

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

<?php if ($modQuero): /* Veio de uma porta fechada: fala-se primeiro do que lhe falta. */ ?>
  <div class="pl-porta">
    <div class="pl-porta-ico"><?= escP($modQuero['icone'] ?: '🔒') ?></div>
    <h2><?= escP($modQuero['nome']) ?> não faz parte da sua licença</h2>
    <?php if ($modQuero['beneficio']): ?>
      <p class="benef"><?= escP($modQuero['beneficio']) ?></p>
    <?php endif; ?>
    <p><?= escP($modQuero['resumo']) ?></p>
    <p style="margin-top:1rem">
      <?= $LIC === 'ativa'
          ? 'Junte-o à sua licença abaixo — o que já tem fica exactamente como está.'
          : 'Escolha abaixo o plano que o inclui.' ?></p>
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
function desenharHistorico(){
  const cx = document.getElementById('lic-hist');
  const h = LIC.historico || [];
  if (!h.length){ cx.style.display = 'none'; cx.innerHTML = ''; return; }
  cx.style.display = '';
  cx.innerHTML = '<h2>Histórico da licença</h2>'
    + '<div class="dica">Todos os pedidos já decididos, do mais recente para trás.</div>'
    + '<div class="lic-pedido" style="padding:.3rem 1.3rem">'
    + h.map(x => {
        const ok = x.estado === 'aprovado';
        const mods = (x.itens || []).map(i => escapar(nomeModulo(i.modulo_chave))).join(', ');
        return '<div class="lic-h">'
          + '<span class="lic-h-selo ' + (ok ? 'ok' : 'nao') + '">' + (ok ? '✓' : '✕') + '</span>'
          + '<span class="lic-h-txt"><span class="lic-h-tit">'
          +   (x.tipo === 'upgrade' ? 'Reforço' : 'Pedido inicial')
          +   (x.pacote_nome ? ' · ' + escapar(x.pacote_nome) : '') + '</span>'
          + '<span class="lic-h-det">' + dataCurta(x.decidido_em)
          +   (mods ? ' · ' + mods : '')
          +   (ok && x.meses ? ' · ' + x.meses + ' meses' : '')
          +   (!ok && x.nota_admin ? ' · ' + escapar(x.nota_admin) : '') + '</span></span>'
          + '<span class="lic-h-vl">' + (ok ? fmt(x.total) : '—') + '</span>'
          + '</div>';
      }).join('')
    + '</div>';
}

// ---- o pedido em cima da mesa ----
function desenharPedido(){
  const cx = document.getElementById('lic-pedido-cx');
  const p  = LIC.pendente;
  if (!p){ cx.style.display = 'none'; cx.innerHTML = ''; mostrarRecusa(); return; }

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

// Um pedido recusado tem de ser dito — e tem de se poder tentar de novo.
function mostrarRecusa(){
  const r = LIC.recusado;
  if (!r || LIC.casamento.licenca_estado === 'ativa') return;
  const cx = document.getElementById('lic-pedido-cx');
  cx.style.display = '';
  cx.innerHTML = '<h2>O seu pedido anterior não foi aceite</h2>'
    + '<div class="lic-pedido"><div class="lic-pedido-cab">'
    +   '<span class="nm">' + (r.pacote_nome ? escapar(r.pacote_nome) : 'Plano à medida') + '</span>'
    +   '<span class="dica" style="margin:0">' + dataCurta(r.decidido_em) + '</span></div>'
    + (r.nota_admin
        ? '<div style="padding:1rem 1.3rem"><div class="lic-nota"><b>Motivo:</b> '
          + escapar(r.nota_admin) + '</div></div>'
        : '<div style="padding:1rem 1.3rem"><div class="dica" style="margin:0">'
          + 'A administração não indicou um motivo. Pode submeter um novo pedido.</div></div>')
    + '</div>';
}

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
  if (!confirm('Cancelar o pedido de licença?\n\nPode voltar a pedir quando quiser.')) return;
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

// ---- apoio ----
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
