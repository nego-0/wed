<?php
// ============================================================
// digital.php — Página de entrada do convite digital
//
// O menu "Convite digital" abria o editor de imediato, o que é como
// entrar numa casa pela oficina. Aqui fica o mesmo que o convite
// impresso tem: o estado da peça, os convites a enviar, as versões
// guardadas — e o editor à distância de um botão.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

// Uma página só. Havia duas abas — "Convites a enviar" e "Estado e versões" —
// mas o estado já está no cartão do topo e as versões cabem lá ao lado: as
// abas escondiam metade do que a página tem para dizer, sem nada a ganhar.
$res = $conn->query("SELECT c.*, m.nome AS mesa_nome
                     FROM {$P}convites c
                     LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                     WHERE c.tipo IN ('digital','ambos') AND ".soVivos($conn,'c')."
                     ORDER BY c.nome_exibicao");
$convites = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ---- Versões guardadas e a que está em vigor ------------------
$emVigor  = versaoEmVigor($conn, 'digital');
$estadoVs = versaoEstado($conn, 'digital');   // modelo partilhado com o painel
$versoes  = [];
$r = $conn->query("SELECT id, nome, utilizador, criado_em, atualizado_em
                   FROM {$P}versoes WHERE ambito='digital' ORDER BY id DESC");
if ($r) $versoes = $r->fetch_all(MYSQLI_ASSOC);

// Contagens para o resumo do topo
$tot = $conn->query("SELECT COUNT(*) FROM {$P}convites
                     WHERE tipo IN ('digital','ambos') AND ".soVivos($conn,''))->fetch_row()[0] ?? 0;
$enviados = 0;
if (colunaExiste($conn, "{$P}convites", 'enviado_em')) {
    $enviados = $conn->query("SELECT COUNT(*) FROM {$P}convites
                              WHERE tipo IN ('digital','ambos') AND enviado_em IS NOT NULL
                              AND ".soVivos($conn,''))->fetch_row()[0] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Convite digital · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 200px; }

  /* ---- Estado da peça ----------------------------------------
     Três colunas em ecrãs largos: a prova, o que há para saber e fazer, e as
     versões mais recentes. Antes eram duas, a do meio esticava-se a ocupar
     tudo e sobrava meia página vazia. */
  .peca{ display:grid; grid-template-columns:170px minmax(260px,1fr) minmax(220px,340px);
         gap:1.4rem; align-items:start;
         background:#fff; border:1px solid var(--line); border-radius:16px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  @media (max-width:1100px){ .peca{ grid-template-columns:150px 1fr; } .peca-vs{ grid-column:1 / -1; } }
  @media (max-width:560px){ .peca{ grid-template-columns:1fr; } .peca-prova{ max-width:190px; } }

  .peca-prova{ border-radius:12px; overflow:hidden; border:1px solid var(--line);
               background:var(--forest-deep); position:relative; aspect-ratio:390/640; }
  /* A prova é o convite verdadeiro, encolhido. Não recebe cliques: é para ver. */
  .peca-prova iframe{ position:absolute; top:0; left:0; width:390px; height:640px; border:0;
                      transform:scale(var(--pv,.44)); transform-origin:top left; pointer-events:none; }
  .peca-prova .lupa{ position:absolute; left:0; right:0; bottom:0; text-align:center;
                     padding:.4rem; background:rgba(14,15,12,.82);
                     color:var(--gold-pale); font-size:.72rem; text-decoration:none; }
  .peca-prova .lupa:hover{ background:rgba(14,15,12,.95); color:#fff; }

  .peca h2{ margin:0 0 .4rem; font-size:1.35rem; }
  .peca .estado-linha{ margin:0; font-size:.88rem; line-height:1.55; color:#6d726b; }
  /* Nome próprio: não herda o .acoes global, que empurra tudo para a direita. */
  .peca-acoes{ display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.9rem; justify-content:flex-start; }
  .selo-v{ display:inline-flex; align-items:center; gap:.35rem; border-radius:50px;
           padding:.2rem .7rem; font-size:.8rem; }
  .selo-v.ok{ background:#eaf4ee; border:1px solid #bcdcc8; color:#1f6b38; }
  .selo-v.fora{ background:#fdf3e6; border:1px solid var(--gold-soft); color:#8A6031; }
  .mini{ display:flex; gap:1.2rem; flex-wrap:wrap; margin:.8rem 0 0; }
  .mini div{ font-size:.78rem; color:#8a8f88; }
  .mini b{ display:block; font-family:var(--serif); font-size:1.35rem; color:var(--ink); line-height:1.1; }

  /* Coluna das versões recentes, dentro do cartão */
  .peca-vs{ border-left:1px solid var(--line); padding-left:1.2rem; }
  @media (max-width:1100px){ .peca-vs{ border-left:0; border-top:1px solid var(--line);
                                       padding-left:0; padding-top:.9rem; } }
  .peca-vs h3{ font-size:.72rem; text-transform:uppercase; letter-spacing:.07em;
               color:#8a8f88; margin:0 0 .5rem; }
  .peca-vs ul{ list-style:none; margin:0; padding:0; }
  .peca-vs li{ display:flex; align-items:baseline; gap:.45rem; padding:.28rem 0;
               border-bottom:1px solid var(--cream); font-size:.84rem; }
  .peca-vs li:last-child{ border-bottom:0; }
  .peca-vs li .nm{ font-family:var(--serif); color:var(--ink); min-width:0;
                   overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .peca-vs li .qd{ margin-left:auto; }
  .peca-vs li .em{ font-size:.68rem; color:#1f6b38; white-space:nowrap; }
  .peca-vs li .qd{ font-size:.72rem; color:#a3a8a1; white-space:nowrap; }
  .peca-vs .maisv{ font-size:.78rem; display:inline-block; margin-top:.5rem; }
  .peca-vs .nada{ font-size:.82rem; color:#8a8f88; line-height:1.5; }

  /* ---- Lista dos convites digitais ---- */
  .prod-scroll{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .prod{ width:100%; border-collapse:collapse; min-width:560px; background:#fff;
         border:1px solid var(--line); border-radius:14px; overflow:hidden; }
  .prod th{ background:var(--cream); font-size:.74rem; text-transform:uppercase; letter-spacing:.06em;
            color:#7a8078; text-align:left; padding:.6rem .8rem; font-weight:600; }
  .prod td{ border-top:1px solid var(--line); padding:.6rem .8rem; vertical-align:middle; font-size:.9rem; }
  .prod tr:hover td{ background:#fcfbf7; }
  .prod .n{ color:var(--gold-soft); font-family:var(--serif); font-weight:700; width:2.2rem; }
  .prod .nm{ font-family:var(--serif); font-size:1.05rem; color:var(--ink); }
  .prod .cod{ font-family:var(--serif); letter-spacing:2px; }
  .prod canvas{ display:block; background:#fff; }
  .prod .ac{ white-space:nowrap; }
  .prod .ac a{ font-size:.82rem; margin-right:.6rem; }

</style>
</head>
<body>
<?php cabecalho('Convite digital', 'O convite que os convidados abrem no telemóvel', 'convite'); ?>

<main class="container">
  <!-- Estado da peça -->
  <div class="peca">
    <div class="peca-prova">
      <iframe src="convite-digital.php?demo=1&amp;prova=1" title="Prova do convite" loading="lazy" scrolling="no"></iframe>
      <a class="lupa" href="convite-digital.php?demo=1" target="_blank" rel="noopener">Abrir em tamanho real</a>
    </div>
    <div>
      <h2><?= escP($CAS['casal']) ?></h2>
      <div class="estado-linha">
        <?php if ($estadoVs['estado'] === 'vigor'): ?>
          <span class="selo-v ok">✓ Em vigor: <b><?= escP($estadoVs['nome']) ?></b></span><br>
          É esta versão que os convidados recebem quando o convite é enviado ou aberto.
        <?php elseif ($estadoVs['estado'] === 'alterada'): ?>
          <span class="selo-v fora"><b><?= escP($estadoVs['nome']) ?></b> · com alterações</span><br>
          A peça tem alterações que ainda não guardou como versão. É este estado que os
          convidados recebem. Guarde-as no editor, ou volte a «<?= escP($estadoVs['nome']) ?>» ao lado.
        <?php elseif ($estadoVs['estado'] === 'nenhuma'): ?>
          <span class="selo-v fora">Sem versão em vigor</span><br>
          Nenhuma das versões guardadas corresponde ao que a peça mostra agora. É este estado
          que os convidados recebem. Guarde-o no editor, ou volte a uma das versões ao lado.
        <?php else: ?>
          <span class="selo-v fora">Sem versões guardadas</span><br>
          Ainda não guardou nenhuma versão. Guarde uma no editor para poder experimentar
          mudanças e voltar atrás sem receio.
        <?php endif; ?>
      </div>
      <div class="mini">
        <div><b><?= (int)$tot ?></b> convites digitais</div>
        <?php if ($enviados): ?><div><b><?= (int)$enviados ?></b> já enviados</div><?php endif; ?>
        <div><b><?= count($versoes) ?></b> versões guardadas</div>
      </div>
      <div class="peca-acoes">
        <a class="btn btn-ouro" href="convite-editor.php">Editar o convite</a>
        <a class="btn" href="convite-digital.php?demo=1" target="_blank" rel="noopener">Ver como um convidado</a>
        <a class="btn" href="index.php">Painel de convidados</a>
      </div>
    </div>

    <div class="peca-vs">
      <h3>Versões guardadas</h3>
      <?php if (!$versoes): ?>
        <p class="nada">Nenhuma ainda. Guarde a primeira no editor para poder
          experimentar mudanças e voltar atrás.</p>
      <?php else: ?>
        <ul>
          <?php foreach (array_slice($versoes, 0, 5) as $v):
            $vig = $emVigor && (int)$emVigor['id'] === (int)$v['id']; ?>
            <li>
              <span class="nm"><?= escP($v['nome']) ?></span>
              <?php if ($vig): ?><span class="em">✓ em vigor</span><?php endif; ?>
              <span class="qd"><?= escP($v['utilizador'] ?: '—') ?> ·
                <?= escP(date('d/m H:i', strtotime($v['criado_em']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($versoes) > 5): ?>
          <a class="maisv" href="convite-editor.php">Ver as <?= count($versoes) ?> no editor</a>
        <?php else: ?>
          <a class="maisv" href="convite-editor.php">Gerir no editor</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="barra no-print">
      <div class="cresce"><input type="search" id="busca" placeholder="Procurar convite ou código…" oninput="filtrar()"></div>
      <span class="tag neutra"><?= count($convites) ?> convites digitais</span>
      <a class="btn" href="index.php">Enviar pelo painel</a>
    </div>

  <?php if (!$convites): ?>
    <div class="vazio"><div class="ico">✉</div><p>Ainda não há convites marcados como digitais.<br>
      No painel, defina o tipo do convite como “Digital” ou “Ambos”.</p></div>
  <?php else: ?>
    <div class="prod-scroll">
    <table class="prod" id="prod">
      <thead><tr><th></th><th>Convite</th><th>Código</th><th>QR</th><th></th></tr></thead>
      <tbody>
      <?php $n = 1; foreach ($convites as $c):
        $nome = nomeConviteVisivel($c);
        $link = base_url() . '/convite-digital.php?c=' . $c['codigo'];
      ?>
        <tr data-busca="<?= escP(strtolower($nome . ' ' . $c['codigo'])) ?>">
          <td class="n"><?= $n ?></td>
          <td class="nm"><?= escP($nome) ?></td>
          <td class="cod"><?= escP($c['codigo']) ?></td>
          <td><canvas class="qr" data-link="<?= escP($link) ?>"></canvas></td>
          <td class="ac">
            <a href="<?= escP($link) ?>" target="_blank" rel="noopener">Abrir</a>
            <a href="#" onclick="copiar('<?= escP($link) ?>');return false">Copiar link</a>
            <a href="<?= escP($link) ?>&amp;download=1">Descarregar</a>
          </td>
        </tr>
      <?php $n++; endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</main>

<div class="toast" id="toast"></div>
<script>
// A prova encolhe para caber na caixa, seja qual for a largura da coluna.
(function ajustarProva(){
  const cx = document.querySelector('.peca-prova');
  if (!cx) return;
  const medir = () => cx.style.setProperty('--pv', (cx.clientWidth / 390).toFixed(4));
  medir(); window.addEventListener('resize', medir);
})();

document.querySelectorAll('canvas.qr').forEach(cv => {
  try { new QRious({ element: cv, value: cv.dataset.link, size: 60, level: 'M',
                     background: '#fff', foreground: '#20342A' }); } catch (e) {}
});

function filtrar(){
  const q = (document.getElementById('busca').value || '').toLowerCase().trim();
  document.querySelectorAll('#prod tbody tr').forEach(tr => {
    tr.style.display = !q || tr.dataset.busca.includes(q) ? '' : 'none';
  });
}
function copiar(t){
  const feito = () => toast('Link do convite copiado.');
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(feito).catch(feito);
  else { const a = document.createElement('textarea'); a.value = t; document.body.appendChild(a);
         a.select(); try { document.execCommand('copy'); } catch(e){} a.remove(); feito(); }
}
function toast(m){
  const t = document.getElementById('toast');
  t.textContent = m; t.classList.add('mostrar');
  setTimeout(() => t.classList.remove('mostrar'), 2200);
}
</script>
</body>
</html>
