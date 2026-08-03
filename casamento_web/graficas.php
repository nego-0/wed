<?php
// ============================================================
// graficas.php — Entregáveis à gráfica
// Reúne num só sítio o que a gráfica precisa de receber:
//   1. Convites físicos — lista simplificada (nome, mesas, QR)
//   2. Brindes — a peça atribuída a cada género e as suas variações
//   3. Manuais — instruções de impressão de cada peça
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
exigirAdmin();

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);

// ---- Fragmento: só o cartão de um convite -------------------
// Serve a pré-visualização expandida (pedida por fetch a partir da lista).
if (isset($_GET['modelo'])) {
    $mid = (int)$_GET['modelo'];
    $st = $conn->prepare("SELECT c.*, m.nome AS mesa_nome FROM {$P}convites c
                          LEFT JOIN {$P}mesas m ON c.mesa_id=m.id WHERE c.id=? AND ".soVivos($conn,'c')." LIMIT 1");
    $st->bind_param('i', $mid); $st->execute();
    $c = $st->get_result()->fetch_assoc();
    if (!$c) { http_response_code(404); exit('Convite não encontrado.'); }
    $comLug = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
    echo renderCartaoConvite(
        cartaoDadosEvento($defs),
        ['nome' => nomeParaCartao($c, ($defs['cartao.numero_no_nome'] ?? '1') === '1'),
         'mesas' => mesasDoConvite($conn, $c)],
        cartaoPaleta($defs['cartao.paleta']),
        $defs['cartao.folhagem'],
        $comLug
    );
    exit;
}

$abas = ['convites' => 'Convites físicos', 'brindes' => 'Brindes', 'manuais' => 'Manuais'];
$aba  = $_GET['aba'] ?? 'convites';
if (!isset($abas[$aba])) $aba = 'convites';

// ---- 1. Convites físicos -----------------------------------
$convites = [];
if ($aba === 'convites') {
    $res = $conn->query("SELECT c.*, m.nome AS mesa_nome
                         FROM {$P}convites c
                         LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                         WHERE c.tipo IN ('fisico','ambos') AND ".soVivos($conn,'c')."
                         ORDER BY c.nome_exibicao");
    $convites = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// ---- 2. Brindes por género ---------------------------------
// Conta os convidados marcados como "recebe brinde", por género.
$porGenero = [];
if ($aba === 'brindes'
    && colunaExiste($conn, "{$P}convidados", 'brinde')
    && colunaExiste($conn, "{$P}convidados", 'genero')) {
    $r = $conn->query("SELECT COALESCE(genero,'') AS g, COUNT(*) AS n
                       FROM {$P}convidados WHERE brinde=1 GROUP BY COALESCE(genero,'')");
    if ($r) while ($x = $r->fetch_assoc()) $porGenero[$x['g']] = (int)$x['n'];
}
$brindes = brindesPorGenero($defs, $porGenero);
$semGenero = (int)($porGenero[''] ?? 0);   // recebem brinde mas sem género definido

$acPeca  = chaveiroAcabamento($defs['chaveiro.acabamento']);
$quadras = chaveiroQuadras();
$romanos = chaveiroRomanos();

// ---- 3. Manuais --------------------------------------------
// Os manuais são GERADOS da configuração atual (manual.php), pelo que
// acompanham as edições. Os do pacote de design ficam como referência
// histórica — não refletem as alterações feitas nos editores.
$manuais = [
    ['peca'     => 'cartao',
     'titulo'   => 'Cartão de convite 10 × 15 cm',
     'sub'      => 'Impressão UV a dourado sobre acrílico transparente',
     'editor'   => 'editor-cartao.php',
     'original' => 'assets/pecas/manuais/cartao-10x15.html'],
    ['peca'     => 'porta-chaves',
     'titulo'   => 'Porta-chaves comemorativo',
     'sub'      => 'Acrílico de dois lados, 45 × 60 mm, com argola',
     'editor'   => 'editor-brindes.php',
     'original' => 'assets/pecas/manuais/porta-chaves.html'],
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entregáveis à gráfica · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<link href="assets/pecas.css" rel="stylesheet">
<script src="assets/qrious.min.js"></script>
<style>
  .abas{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.2rem; }
  .abas a{ background:#fff; border:1px solid var(--line); border-radius:50px; padding:.45rem 1.1rem;
           font-size:.88rem; color:var(--text); text-decoration:none; }
  .abas a:hover{ border-color:var(--gold-soft); }
  .abas a.on{ background:var(--forest); border-color:var(--forest); color:#fff; }
  .barra{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
  .barra .cresce{ flex:1 1 200px; }

  /* ---- Lista de produção dos convites ---- */
  /* Em ecrãs estreitos desliza a tabela, não a página inteira. */
  .prod-scroll{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .prod{ width:100%; border-collapse:collapse; min-width:520px; background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; }
  .prod th{ background:var(--cream); font-size:.74rem; text-transform:uppercase; letter-spacing:.06em;
            color:#7a8078; text-align:left; padding:.6rem .8rem; font-weight:600; }
  .prod td{ border-top:1px solid var(--line); padding:.7rem .8rem; vertical-align:middle; font-size:.9rem; }
  .prod tr:hover td{ background:#fcfbf7; }
  .prod .n{ color:var(--gold-soft); font-family:var(--serif); font-weight:700; width:2.2rem; }
  .prod .nm{ font-family:var(--serif); font-size:1.05rem; color:var(--ink); }
  .prod .ms{ color:var(--forest); font-size:.84rem; }
  .prod .cod{ font-family:var(--serif); letter-spacing:2px; }
  .prod canvas{ display:block; background:#fff; }
  .prod .ver{ font-size:.82rem; white-space:nowrap; }
  .prod tbody tr{ cursor:pointer; }

  /* ---- Modelo expandido (sobreposição) ---- */
  .ov-modelo{ position:fixed; inset:0; background:rgba(14,15,12,.86); display:none;
              align-items:center; justify-content:center; z-index:80; padding:1rem; }
  .ov-modelo.aberto{ display:flex; }
  .mod-cx{ display:flex; flex-direction:column; align-items:center; gap:.8rem; max-height:100%; }
  .mod-topo{ display:flex; align-items:center; gap:.9rem; color:var(--gold-pale); flex-wrap:wrap; justify-content:center; }
  .mod-topo .nm{ font-family:var(--serif); font-size:1.15rem; }
  .mod-topo .btn{ padding:.35rem .9rem; font-size:.82rem; }
  .mod-fechar{ background:none; border:1px solid rgba(239,227,203,.35); color:var(--gold-pale);
               border-radius:50%; width:30px; height:30px; cursor:pointer; font-size:1rem; line-height:1; }
  .mod-palco{ background:radial-gradient(120% 100% at 50% 15%,#2a2b26 0%,#191a16 55%,#0e0f0c 100%);
              border-radius:14px; overflow:hidden;
              width:calc(720px * var(--me,.6)); height:calc(1080px * var(--me,.6)); }
  .mod-palco .escala{ width:720px; height:1080px; transform:scale(var(--me,.6)); }
  .mod-vazio{ color:var(--gold-pale); font-family:var(--serif); }

  /* ---- Brindes ---- */
  .brinde-cx{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.25rem; margin-bottom:1.2rem; }
  .brinde-topo{ display:flex; gap:1rem; align-items:baseline; flex-wrap:wrap; margin-bottom:.3rem; }
  .brinde-topo h3{ margin:0; }
  .brinde-meta{ font-size:.86rem; color:#7a8078; }
  .brinde-meta b{ color:var(--ink); }
  .variacoes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:1rem; margin-top:1rem; }
  .var-item{ text-align:center; }
  .var-palco{ width:calc(250px * .62); height:calc(340px * .62); margin:0 auto;
              border-radius:12px; overflow:hidden; }
  .var-palco .escala{ width:250px; height:340px; transform:scale(.62); }
  .var-rot{ font-size:.78rem; color:#7a8078; margin-top:.5rem; }
  .var-rot b{ font-family:var(--serif); color:var(--gold); }
  .por-definir{ color:#8a8f88; font-style:italic; }
  .falta{ color:#a5473f; }
  .sobra{ color:#1f7a3d; }

  /* ---- Manuais ---- */
  .manuais{ display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem; }
  .man{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem; }
  .man .ico{ font-size:1.6rem; color:var(--gold); }
  .man h3{ margin:.4rem 0 .2rem; }
  .man p{ font-size:.86rem; color:#7a8078; margin:0 0 .9rem; }
  .man .acoes{ display:flex; gap:.5rem; flex-wrap:wrap; }
  .man.orig{ background:#fbfaf6; }
  .man.orig h3{ font-size:1rem; margin:0 0 .6rem; color:#7a8078; }
  .nota-man{ background:var(--gold-pale); border:1px solid var(--gold-soft); border-radius:12px;
             padding:.8rem 1rem; font-size:.88rem; margin-bottom:1.2rem; }
  .nota-orig{ font-size:.84rem; color:#7a8078; margin:0 0 .9rem; }

  @media print{
    @page{ margin:12mm; }
    body{ background:#fff; }
    .no-print{ display:none !important; }
    .topo{ background:#fff !important; color:var(--ink) !important; border-bottom:2px solid var(--gold); padding:0 0 .6rem !important; }
    .topo::after{ display:none; } .topo .monograma{ color:var(--gold); border-color:var(--gold); }
    .topo h1{ color:var(--ink); } .topo .sub{ color:var(--gold); }
    .prod-scroll{ overflow:visible; }
    .prod{ min-width:0; }
    .prod tr{ break-inside:avoid; }
    .brinde-cx, .var-item{ break-inside:avoid; }
  }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div><h1>Entregáveis à gráfica</h1><div class="sub">O que a gráfica recebe: convites, brindes e manuais</div></div>
    <nav class="nav no-print">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="graficas.php" class="ativo">Gráfica</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="abas no-print">
    <?php foreach ($abas as $k => $r): ?>
      <a href="?aba=<?= $k ?>" class="<?= $k === $aba ? 'on' : '' ?>"><?= escP($r) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($aba === 'convites'): ?>
    <div class="barra no-print">
      <div class="cresce"><input type="search" id="busca" placeholder="Procurar convite, mesa ou código…" oninput="filtrar()"></div>
      <span class="tag neutra"><?= count($convites) ?> convites físicos</span>
      <a class="btn" href="cartoes.php">Ver todos os modelos</a>
      <a class="btn" href="impressos.php">Etiquetas para envelopes</a>
      <a class="btn" href="editor-cartao.php">Editar o cartão</a>
      <button class="btn btn-ouro" onclick="window.print()">Imprimir lista</button>
    </div>

    <?php if (!$convites): ?>
      <div class="vazio"><div class="ico">✉</div><p>Ainda não há convites marcados como físicos.<br>No painel, defina o tipo do convite como “Físico” ou “Ambos”.</p></div>
    <?php else: ?>
    <div class="prod-scroll">
    <table class="prod" id="prod">
      <thead><tr>
        <th></th><th>Convite</th><th>Mesas</th><th>Código</th><th>QR</th><th class="no-print"></th>
      </tr></thead>
      <tbody>
      <?php $n = 1; foreach ($convites as $c):
        $nome  = nomeConviteVisivel($c);
        $distr = mesasDoConvite($conn, $c);
        $comLug = !isset($c['mostrar_num_mesa']) || (int)$c['mostrar_num_mesa'] === 1;
        $txtMesas = textoMesas($distr, $comLug);
        $link  = base_url() . '/convite.php?c=' . $c['codigo'];
        $blob  = strtolower($nome . ' ' . $txtMesas . ' ' . $c['codigo']);
      ?>
        <tr data-busca="<?= escP($blob) ?>" data-id="<?= (int)$c['id'] ?>" data-nome="<?= escP($nome) ?>"
            onclick="abrirModelo(this)" title="Ver o modelo expandido">
          <td class="n"><?= $n ?></td>
          <td class="nm"><?= escP($nome) ?></td>
          <td class="ms"><?= $txtMesas !== '' ? escP($txtMesas) : '<span class="por-definir">sem mesa</span>' ?></td>
          <td class="cod"><?= escP($c['codigo']) ?></td>
          <td><canvas class="qr" data-link="<?= escP($link) ?>"></canvas></td>
          <td class="no-print ver"><a href="cartoes.php?id=<?= (int)$c['id'] ?>" onclick="event.stopPropagation()">Imprimir</a></td>
        </tr>
      <?php $n++; endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

  <?php elseif ($aba === 'brindes'): ?>
    <div class="barra no-print">
      <span class="tag neutra">Brinde atribuído por género</span>
      <a class="btn" href="editor-brindes.php">Editar brindes</a>
      <div class="cresce"></div>
      <button class="btn btn-ouro" onclick="window.print()">Imprimir</button>
    </div>

    <?php if ($semGenero > 0): ?>
      <div class="banner-aviso" style="background:var(--gold-pale);border:1px solid var(--gold-soft);border-radius:12px;padding:.8rem 1rem;margin-bottom:1.2rem;font-size:.88rem">
        <b><?= $semGenero ?></b> convidado(s) marcados para receber brinde ainda <b>sem género definido</b> — não entram em nenhuma das contagens abaixo.
      </div>
    <?php endif; ?>

    <?php foreach ($brindes as $g => $b): ?>
      <div class="brinde-cx">
        <div class="brinde-topo">
          <h3><?= escP($b['rotulo']) ?></h3>
          <?php if ($b['peca']): ?>
            <span class="brinde-meta"><b><?= escP($b['peca']['nome']) ?></b> ·
              <?= escP($b['peca']['medida']) ?> · <?= escP($b['peca']['material']) ?></span>
          <?php else: ?>
            <span class="brinde-meta por-definir">Brinde ainda por definir para este género.</span>
          <?php endif; ?>
        </div>

        <?php if ($b['peca']): ?>
          <?php $qtd = $b['quantidade']; $nv = count($b['variacoes']); $tot = $b['total_pecas']; ?>
          <div class="brinde-meta">
            <b><?= $qtd ?></b> <?= $qtd === 1 ? 'convidado recebe' : 'convidados recebem' ?> este brinde ·
            <b><?= $nv ?></b> <?= $nv === 1 ? 'variação disponível' : 'variações disponíveis' ?>
            <?php if ($tot > 0): ?>
              · <b><?= $tot ?></b> <?= $tot === 1 ? 'peça' : 'peças' ?> a produzir
              <?php if ($tot < $qtd): ?><span class="falta">(faltam <?= $qtd - $tot ?>)</span>
              <?php elseif ($tot > $qtd && $qtd > 0): ?><span class="sobra">(+<?= $tot - $qtd ?> de reserva)</span>
              <?php endif; ?>
            <?php else: ?>
              · <span class="falta">quantidades por definir</span>
            <?php endif; ?>
            · <a href="<?= escP($b['peca']['pagina']) ?>">ver a peça</a>
            · <a href="<?= escP($b['peca']['manual']) ?>" target="_blank" rel="noopener">manual</a>
          </div>

          <div class="variacoes">
            <?php foreach ($b['variacoes'] as $v): ?>
              <div class="var-item">
                <div class="var-palco" style="background:<?= $acPeca['fundo'] ?>">
                  <div class="escala"><?= renderChaveiroVerso($acPeca, $defs, $v['texto'], true) ?></div>
                </div>
                <div class="var-rot">Variação <b><?= escP($v['rotulo']) ?></b>
                  <?php if ($v['quantidade'] > 0): ?><br><?= $v['quantidade'] ?> <?= $v['quantidade'] === 1 ? 'peça' : 'peças' ?><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <div class="barra no-print">
      <span class="tag neutra">Manuais de impressão</span>
    </div>
    <div class="nota-man">Os manuais são <b>gerados a partir da configuração atual</b>: paleta, folhagem,
      elementos ativos, textos, acabamento, variações e quantidades saem tal como estão definidos nos editores.
      Depois de editar uma peça, basta abrir o manual outra vez.</div>
    <div class="manuais">
      <?php foreach ($manuais as $m): ?>
        <div class="man">
          <div class="ico">📄</div>
          <h3><?= escP($m['titulo']) ?></h3>
          <p><?= escP($m['sub']) ?></p>
          <div class="acoes">
            <a class="btn btn-ouro" href="manual.php?peca=<?= escP($m['peca']) ?>">Abrir manual</a>
            <a class="btn" href="<?= escP($m['editor']) ?>">Editar a peça</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <h3 style="margin:1.8rem 0 .4rem">Documentos originais do design</h3>
    <p class="nota-orig">Os manuais ilustrados que vieram com o design. Servem de <b>referência</b> —
      descrevem a peça como foi entregue e <b>não refletem</b> as edições feitas depois.</p>
    <div class="manuais">
      <?php foreach ($manuais as $m): $existe = is_readable(__DIR__ . '/' . $m['original']); ?>
        <div class="man orig">
          <h3><?= escP($m['titulo']) ?></h3>
          <div class="acoes">
            <?php if ($existe): ?>
              <a class="btn" href="<?= escP($m['original']) ?>" target="_blank" rel="noopener">Abrir original</a>
              <a class="btn" href="<?= escP($m['original']) ?>" download>Descarregar</a>
            <?php else: ?>
              <span class="por-definir">Em falta: <?= escP($m['original']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Modelo do convite, expandido -->
<div class="ov-modelo" id="ov-modelo" onclick="if(event.target===this) fecharModelo()">
  <div class="mod-cx">
    <div class="mod-topo">
      <span class="nm" id="mod-nome"></span>
      <a class="btn" id="mod-imprimir" href="#">Imprimir este cartão</a>
      <button class="mod-fechar" onclick="fecharModelo()" title="Fechar (Esc)" aria-label="Fechar">✕</button>
    </div>
    <div class="mod-palco" id="mod-palco"><div class="escala" id="mod-corpo"></div></div>
  </div>
</div>

<script>
const $=id=>document.getElementById(id);
// QR de cada convite (mesmo aspeto das etiquetas)
document.querySelectorAll('canvas.qr').forEach(cv=>{
  new QRious({element:cv, value:cv.dataset.link, size:88, level:'M', foreground:'#20342A', background:'#ffffff'});
});
function filtrar(){
  const q=$('busca').value.toLowerCase();
  document.querySelectorAll('#prod tbody tr').forEach(tr=>{
    tr.style.display = tr.dataset.busca.includes(q) ? '' : 'none';
  });
}

// ---- Modelo expandido ----
// O cartão é pedido ao servidor (fragmento) e ampliado até caber no ecrã.
function escalaModelo(){
  const esc = Math.min((window.innerHeight - 150) / 1080, (window.innerWidth - 60) / 720);
  return Math.max(.25, Math.min(1, esc));
}
async function abrirModelo(tr){
  const id = tr.dataset.id;
  $('mod-nome').textContent = tr.dataset.nome;
  $('mod-imprimir').href = 'cartoes.php?id=' + id;
  $('mod-corpo').innerHTML = '<div class="mod-vazio">A carregar…</div>';
  $('ov-modelo').style.setProperty('--me', escalaModelo());
  $('ov-modelo').classList.add('aberto');
  try {
    const r = await fetch('graficas.php?modelo=' + encodeURIComponent(id));
    if (!r.ok) throw new Error(r.status);
    $('mod-corpo').innerHTML = await r.text();
  } catch (err) {
    $('mod-corpo').innerHTML = '<div class="mod-vazio">Não foi possível carregar o modelo.</div>';
  }
}
function fecharModelo(){ $('ov-modelo').classList.remove('aberto'); $('mod-corpo').innerHTML=''; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') fecharModelo(); });
window.addEventListener('resize', ()=>{
  if($('ov-modelo').classList.contains('aberto')) $('ov-modelo').style.setProperty('--me', escalaModelo());
});
</script>
</body>
</html>
