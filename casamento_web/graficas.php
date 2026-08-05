<?php
// ============================================================
// graficas.php — Convite impresso
// O que a gráfica precisa de receber do convite físico:
//   1. Lista de produção — nome, mesas e QR de cada convite
//   2. Manual de impressão do cartão (gerado da configuração atual)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
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
        cartaoPaletaEfetiva($defs),
        $defs['cartao.folhagem'],
        $comLug,
        cartaoCamadasVisiveis($defs),
        cartaoEstiloVars($defs)
    );
    exit;
}

// Qual a versão do cartão que está em vigor — a que se imprime e a que o
// manual retrata. Sem isto, a página do impresso dizia menos do que a do
// digital sobre o estado da própria peça.
$emVigor = versaoEmVigor($conn, 'impresso');
$nVersoes = (int)($conn->query("SELECT COUNT(*) FROM {$P}versoes WHERE ambito='impresso'")
                       ->fetch_row()[0] ?? 0);

$abas = ['convites' => 'Lista de produção', 'manuais' => 'Manual de impressão'];
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

// ---- 2. Manual do cartão -----------------------------------
// Gerado da configuração atual (manual.php), pelo que acompanha as edições.
// O do pacote de design fica como referência histórica.
$manual = [
    'peca'     => 'cartao',
    'titulo'   => 'Cartão de convite 10 × 15 cm',
    'sub'      => 'Impressão UV a dourado sobre acrílico transparente',
    'editor'   => 'editor-cartao.php',
    'original' => 'assets/pecas/manuais/cartao-10x15.html',
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Convite impresso · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/pecas.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  /* Faixa com o estado da peça, acima das abas. */
  .estado-peca{ display:flex; align-items:center; gap:.7rem; flex-wrap:wrap;
    background:#fff; border:1px solid var(--line); border-radius:14px;
    padding:.7rem .95rem; margin-bottom:1rem; }
  .estado-peca .cresce{ flex:1; }
  .estado-peca .txt{ font-size:.85rem; color:#6d726b; }
  .estado-peca .qtd{ white-space:nowrap; }
  .selo-v{ display:inline-flex; align-items:center; gap:.35rem; border-radius:50px;
           padding:.2rem .7rem; font-size:.8rem; white-space:nowrap; }
  .selo-v.ok{ background:#eaf4ee; border:1px solid #bcdcc8; color:#1f6b38; }
  .selo-v.fora{ background:#fdf3e6; border:1px solid var(--gold-soft); color:#8A6031; }
  .btn-sm{ padding:.3rem .8rem; font-size:.82rem; }

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

  .por-definir{ color:#8a8f88; font-style:italic; }

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
  }
</style>
</head>
<body>
<?php cabecalho('Convite impresso', 'O que a gráfica recebe do convite físico: lista de produção e manual', 'grafica', ['no_print'=>true]); ?>

<div class="container">
  <div class="estado-peca no-print">
    <?php if ($emVigor): ?>
      <span class="selo-v ok">✓ Em vigor: <b><?= escP($emVigor['nome']) ?></b></span>
      <span class="txt">É esta versão do cartão que se imprime, e a que o manual retrata.</span>
    <?php elseif ($nVersoes): ?>
      <span class="selo-v fora">Fora de qualquer versão</span>
      <span class="txt">O cartão tem alterações que não estão guardadas em nenhuma versão.
        É este estado que se imprime.</span>
    <?php else: ?>
      <span class="selo-v fora">Sem versões guardadas</span>
      <span class="txt">Ainda não guardou nenhuma versão do cartão. Guarde uma no editor
        para poder experimentar mudanças e voltar atrás.</span>
    <?php endif; ?>
    <span class="cresce"></span>
    <span class="txt qtd"><?= $nVersoes ?> <?= $nVersoes === 1 ? 'versão guardada' : 'versões guardadas' ?></span>
    <a class="btn btn-sm" href="editor-cartao.php">Editar o cartão</a>
  </div>

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

  <?php else: ?>
    <div class="barra no-print">
      <span class="tag neutra">Manual de impressão do cartão</span>
    </div>
    <div class="nota-man">O manual é <b>gerado a partir da configuração atual</b>: paleta, folhagem,
      elementos ativos e textos saem tal como estão definidos no editor do cartão.
      Depois de editar o cartão, basta abrir o manual outra vez.</div>
    <div class="manuais">
      <div class="man">
        <div class="ico">📄</div>
        <h3><?= escP($manual['titulo']) ?></h3>
        <p><?= escP($manual['sub']) ?></p>
        <div class="acoes">
          <a class="btn btn-ouro" href="manual.php?peca=<?= escP($manual['peca']) ?>">Abrir manual</a>
          <a class="btn" href="<?= escP($manual['editor']) ?>">Editar o cartão</a>
        </div>
      </div>
    </div>

    <h3 style="margin:1.8rem 0 .4rem">Documento original do design</h3>
    <p class="nota-orig">O manual ilustrado que veio com o design. Serve de <b>referência</b> —
      descreve o cartão como foi entregue e <b>não reflete</b> as edições feitas depois.</p>
    <div class="manuais">
      <?php $existe = is_readable(__DIR__ . '/' . $manual['original']); ?>
      <div class="man orig">
        <h3><?= escP($manual['titulo']) ?></h3>
        <div class="acoes">
          <?php if ($existe): ?>
            <a class="btn" href="<?= escP($manual['original']) ?>" target="_blank" rel="noopener">Abrir original</a>
            <a class="btn" href="<?= escP($manual['original']) ?>" download>Descarregar</a>
          <?php else: ?>
            <span class="por-definir">Em falta: <?= escP($manual['original']) ?></span>
          <?php endif; ?>
        </div>
      </div>
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
