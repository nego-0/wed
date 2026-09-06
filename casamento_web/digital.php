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
require_once __DIR__ . '/parcial-endereco.php';
exigirAdmin();
exigirModulo('digital');

$defs = defsAtuais($conn);
$CAS  = casalInfo($defs);
$ENDERECO = enderecoPublico();   // para onde apontam os links e os QR desta lista

// Uma página só. Havia duas abas — "Convites a enviar" e "Estado e versões" —
// mas o estado já está no cartão do topo e as versões cabem lá ao lado: as
// abas escondiam metade do que a página tem para dizer, sem nada a ganhar.
$res = $conn->query("SELECT c.*, m.nome AS mesa_nome
                     FROM {$P}convites c
                     LEFT JOIN {$P}mesas m ON c.mesa_id=m.id
                     WHERE " . doCasamento('c') . " AND c.tipo IN ('digital','ambos') AND ".soVivos($conn,'c')."
                     ORDER BY c.nome_exibicao");
$convites = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ---- Versões guardadas e a que está em vigor ------------------
$emVigor  = versaoEmVigor($conn, 'digital');
$estadoVs = versaoEstado($conn, 'digital');   // modelo partilhado com o painel
$versoes  = [];
$r = $conn->query("SELECT id, nome, utilizador, criado_em, atualizado_em
                   FROM {$P}versoes WHERE " . doCasamento() . " AND ambito='digital' ORDER BY id DESC");
if ($r) $versoes = $r->fetch_all(MYSQLI_ASSOC);

// Contagens para o resumo do topo
$tot = $conn->query("SELECT COUNT(*) FROM {$P}convites
                     WHERE " . doCasamento() . " AND tipo IN ('digital','ambos') AND ".soVivos($conn,''))->fetch_row()[0] ?? 0;
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
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
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

  /* ---- As abas da peça --------------------------------------
     O estado e as fotografias são a mesma peça vista de dois lados. Numa aba,
     e não numa página à parte: trocar uma fotografia com a prova ao lado é ver
     a troca acontecer. */
  .peca-corpo{ min-width:0; }
  .peca-abas{ display:flex; gap:.25rem; border-bottom:1px solid var(--line); margin:.5rem 0 .9rem; }
  .p-aba{ background:none; border:0; border-bottom:2px solid transparent; cursor:pointer;
          padding:.42rem .1rem; margin-right:1.1rem; font:inherit; font-size:.86rem;
          color:#8a8f88; }
  .p-aba:hover{ color:var(--ink); }
  .p-aba.on{ color:var(--ink); border-bottom-color:var(--gold); font-weight:600; }
  .p-aba:focus-visible{ outline:2px solid var(--gold); outline-offset:3px; border-radius:4px; }
  /* Com as fotografias à vista, a coluna das versões sai e o corpo fica com a
     largura toda: quatro secções em 300px era uma escada. */
  .peca.fotos .peca-vs{ display:none; }
  .peca.fotos .peca-corpo{ grid-column:2 / -1; }
  /* Numa coluna só (telemóvel), a linha 2 já é o fim da grelha: aí o corpo
     ocupa-a inteira, como tudo o resto. */
  @media (max-width:560px){ .peca.fotos .peca-corpo{ grid-column:1 / -1; } }

  /* ---- As fotografias do convite ----------------------------
     Uma ficha por secção, lado a lado: a fotografia como o convite a mostra
     (recorte incluído), e ao lado o que se pode fazer com ela. Nada de camadas
     nem de réguas — quem vem aqui quer pôr uma fotografia sua. */
  .ft-cab{ margin:-.2rem 0 .8rem; }
  .ft-secs{ display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:.7rem; }
  .ft-sec{ display:grid; grid-template-columns:auto 1fr; gap:.75rem; align-items:start;
           border:1px solid var(--line); border-radius:12px; padding:.6rem .7rem; background:#fff; }
  /* A janela por onde o convite vê a fotografia: a mesma forma, e o mesmo ponto
     ao centro. Fixa-se a altura e a forma dá a largura — uma capa 9/16 e um
     passe 16/11 nunca teriam a mesma. */
  .ft-agora{ position:relative; border-radius:9px; overflow:hidden; background:var(--cream);
             height:92px; }
  .ft-agora img{ width:100%; height:100%; object-fit:cover; display:block; }
  .ft-agora.move{ cursor:grab; touch-action:none; }
  .ft-agora.move:active{ cursor:grabbing; }
  .ft-agora .et{ position:absolute; left:0; bottom:0; right:0; text-align:center;
                 font-size:.58rem; letter-spacing:.03em; text-transform:uppercase;
                 white-space:nowrap; padding:.15rem; background:rgba(14,15,12,.72); color:#fff; }
  .ft-sec.nossa .ft-agora{ outline:2px solid var(--gold); outline-offset:-2px; }
  /* A lupa, por cima da fotografia: vê-se pequena, e às vezes é preciso vê-la
     grande antes de decidir. */
  .ft-lupa{ position:absolute; top:.25rem; right:.25rem; width:24px; height:24px; padding:0;
            border:0; border-radius:7px; background:rgba(14,15,12,.6); color:#fff;
            font-size:.85rem; line-height:1; cursor:pointer; display:flex;
            align-items:center; justify-content:center; }
  .ft-lupa:hover{ background:rgba(14,15,12,.9); }
  .ft-lupa:focus-visible{ outline:2px solid var(--gold); outline-offset:2px; }
  /* A mira só aparece enquanto se arrasta: uma cruz sempre acesa é decoração. */
  .ft-mira{ position:absolute; width:16px; height:16px; margin:-8px 0 0 -8px; border-radius:50%;
            border:2px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,.45); display:none;
            pointer-events:none; }
  .ft-agora.a-mover .ft-mira{ display:block; }
  .ft-nome{ font-size:.9rem; color:var(--ink); font-weight:600; }
  .ft-desc{ font-size:.76rem; color:#8a8f88; margin-top:.05rem; line-height:1.35; }
  .ft-acoes{ display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.45rem; }
  .ft-acoes .btn{ font-size:.76rem; padding:.28rem .6rem; }
  .ft-erro{ flex-basis:100%; font-size:.76rem; color:var(--danger); margin-top:.1rem; }
  .ft-erro:empty{ display:none; }
  /* A galeria da casa abre debaixo da secção, e não numa janela: escolher uma
     fotografia é comparar, e comparar quer as duas à vista. */
  .ft-galeria{ display:none; grid-column:1 / -1; margin-top:.5rem; }
  .ft-sec.aberta .ft-galeria{ display:block; }
  .ft-tiras{ display:flex; gap:.4rem; overflow-x:auto; padding-bottom:.3rem; scrollbar-width:thin; }
  .ft-op{ position:relative; flex:none; width:76px; height:58px; border-radius:8px;
          overflow:hidden; cursor:pointer; border:2px solid transparent; padding:0;
          background:none; transition:border-color .15s, transform .15s; }
  .ft-op:hover{ transform:translateY(-2px); border-color:var(--gold-soft); }
  .ft-op.on{ border-color:var(--gold); }
  .ft-op img{ width:100%; height:100%; object-fit:cover; display:block; }
  .ft-op .visto{ position:absolute; inset:0; display:none; align-items:center;
                 justify-content:center; background:rgba(76,140,30,.45); color:#fff;
                 font-size:1.1rem; font-weight:800; }
  .ft-op.on .visto{ display:flex; }
  .ft-op:focus-visible{ outline:2px solid var(--gold); outline-offset:2px; }
  .ft-vazio{ font-size:.85rem; color:#8a8f88; line-height:1.55; }

  /* ---- Ver em ponto grande ---- */
  .ft-lente{ position:fixed; inset:0; z-index:80; display:none; flex-direction:column;
             align-items:center; justify-content:center; gap:.7rem; padding:2rem;
             background:rgba(10,14,10,.86); backdrop-filter:blur(2px); }
  .ft-lente.on{ display:flex; }
  /* A legenda por baixo, e não por cima: uma legenda em cima da fotografia
     tapa-lhe justamente o pedaço que se veio aqui ver. */
  .ft-lente img{ min-height:0; max-width:100%; max-height:100%; border-radius:10px;
                 box-shadow:0 20px 60px rgba(0,0,0,.5); }
  .ft-lente .leg{ flex:none; text-align:center; color:var(--ivory); font-size:.85rem; }
  .ft-lente .fechar{ position:absolute; top:.8rem; right:1rem; background:none; border:0;
                     color:#fff; font-size:1.8rem; line-height:1; cursor:pointer; }

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
    <div class="peca-corpo">
      <h2><?= escP($CAS['casal']) ?></h2>
      <!-- Duas abas, e não duas páginas: as fotografias são desta peça, e
           trocá-las com a prova ao lado é ver a troca acontecer. -->
      <div class="peca-abas" role="tablist" aria-label="A peça">
        <button type="button" class="p-aba on" role="tab" id="ab-estado"
                aria-selected="true" aria-controls="pn-estado"
                onclick="pecaAba('estado')">Estado da peça</button>
        <button type="button" class="p-aba" role="tab" id="ab-fotos"
                aria-selected="false" aria-controls="pn-fotos"
                onclick="pecaAba('fotos')">As fotografias</button>
      </div>

      <div class="p-painel" id="pn-estado" role="tabpanel" aria-labelledby="ab-estado">
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
      </div>
      </div><!-- /pn-estado -->

      <!-- As fotografias do convite: aqui, e não no editor -->
      <div class="p-painel" id="pn-fotos" role="tabpanel" aria-labelledby="ab-fotos" hidden>
        <p class="dica ft-cab">Uma por secção — as vossas, ou da galeria da casa. Arrastem
          sobre a fotografia para escolher o que fica à vista; a lupa mostra-a em grande.
          <span class="ft-formatos">jpg, png ou webp, até <b id="ft-max">5</b> MB.</span></p>
        <div class="ft-secs" id="ft-secs"><p class="ft-vazio">A carregar…</p></div>
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

  <?php barraEndereco('os links e os QR dos convites digitais'); ?>

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
        $link = $ENDERECO . '/convite-digital.php?c=' . $c['codigo'];
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

<!-- A fotografia em ponto grande: inteira, e não pelo recorte -->
<div class="ft-lente" id="ft-lente" role="dialog" aria-modal="true" aria-label="Fotografia em ponto grande">
  <button type="button" class="fechar" onclick="ftLenteFechar()" aria-label="Fechar">&times;</button>
  <img src="" alt="">
  <div class="leg"></div>
</div>

<div class="toast" id="toast"></div>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
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

// ============================================================
// As abas da peça
//
// O estado e as fotografias são a mesma peça vista de dois lados. A aba das
// fotografias tomou o lugar do link «Painel de convidados», que era uma porta
// para fora daquilo que se veio cá fazer — e o painel está no menu, a dois
// centímetros dali.
// ============================================================
function pecaAba(qual){
  document.querySelectorAll('.p-aba').forEach(b => {
    const on = b.id === 'ab-' + qual;
    b.classList.toggle('on', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  document.getElementById('pn-estado').hidden = qual !== 'estado';
  document.getElementById('pn-fotos').hidden  = qual !== 'fotos';
  // Com as fotografias à vista, a coluna das versões sai e o corpo fica com a
  // largura toda — quatro secções espremidas em 300px eram uma escada.
  document.querySelector('.peca').classList.toggle('fotos', qual === 'fotos');
}

// ============================================================
// As fotografias do convite
//
// O editor é uma oficina — camadas, réguas, painéis. Trocar uma fotografia não
// pede nada disso, e obrigar a lá entrar (ou, pior, a comprar o escalão que
// deixa lá entrar) para pôr a fotografia dos próprios noivos era vender-lhes
// um convite com a cara de outra pessoa. Aqui é uma secção, uma fotografia.
//
// Duas coisas que o editor tinha e faziam falta cá fora: ver a fotografia em
// ponto grande antes de decidir, e escolher que pedaço dela fica à vista. Uma
// fotografia cortada pelo meio da cara não se resolve escolhendo outra.
// ============================================================
let FT_SECS = [], FT_MAX = 5, FT_ABERTA = '';

function ftEsc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** A secção com esta chave, tal como o servidor a descreveu. */
function ftSec(sec){ return FT_SECS.find(x => x.chave === sec) || null; }

async function ftCarregar(){
  const d = await api('convite_fotos', { method:'GET' });
  if (!d || !d.success) return;
  FT_SECS = d.seccoes || []; FT_MAX = d.max_mb || 5;
  const mx = document.getElementById('ft-max');
  if (mx) mx.textContent = FT_MAX;
  ftPintar();
}

/** O estilo que põe a miniatura a recortar como o convite recorta. */
function ftRecorte(sc){
  if (!sc.pos) return '';
  return ' style="object-position:' + sc.pos.x + '% ' + sc.pos.y + '%'
       + ';transform:scale(' + (sc.pos.zoom / 100) + ')"';
}

function ftPintar(){
  const cx = document.getElementById('ft-secs'); if (!cx) return;
  if (!FT_SECS.length){
    cx.innerHTML = '<p class="ft-vazio">Este convite não mostra nenhuma secção com fotografia.</p>';
    return;
  }
  cx.innerHTML = FT_SECS.map(sc => {
    const aberta = FT_ABERTA === sc.chave;
    const move = !!sc.enq;
    const tiras = (sc.fotos || []).map(f =>
        '<button type="button" class="ft-op' + (f.src === sc.atual ? ' on' : '') + '"'
      + ' title="' + ftEsc(f.nome) + '" data-sec="' + ftEsc(sc.chave) + '"'
      + ' data-src="' + ftEsc(f.src) + '">'
      + '<img src="' + ftEsc(f.src) + '" alt="' + ftEsc(f.nome) + '" loading="lazy" decoding="async">'
      + '<span class="visto">✓</span></button>').join('');
    return '<div class="ft-sec' + (sc.nossa ? ' nossa' : '') + (aberta ? ' aberta' : '') + '"'
      + ' data-sec="' + ftEsc(sc.chave) + '">'
      + '<div class="ft-agora' + (move ? ' move' : '') + '"'
      +      ' style="aspect-ratio:' + ftEsc(sc.proporcao || '4/3') + '"'
      +      (move ? ' tabindex="0" role="application"'
                     + ' aria-label="Enquadramento da fotografia: arraste, ou use as setas"' : '') + '>'
      +   (sc.atual ? '<img src="' + ftEsc(sc.atual) + '"' + ftRecorte(sc)
                      + ' alt="A fotografia da secção ' + ftEsc(sc.rotulo) + '">' : '')
      +   (move && sc.pos ? '<span class="ft-mira" style="left:' + sc.pos.x + '%;top:'
                            + sc.pos.y + '%"></span>' : '')
      +   '<button type="button" class="ft-lupa" data-ft="lupa"'
      +     ' title="Ver em ponto grande" aria-label="Ver em ponto grande">⤢</button>'
      +   '<span class="et">' + (sc.nossa ? 'vossa' : 'da casa') + '</span>'
      + '</div>'
      + '<div>'
      +   '<div class="ft-nome">' + ftEsc(sc.rotulo) + '</div>'
      +   '<div class="ft-desc">' + ftEsc(sc.descricao) + '</div>'
      +   '<div class="ft-acoes">'
      +     '<button class="btn btn-sm btn-ouro" data-ft="enviar">'
      +       (sc.nossa ? 'Trocar' : '＋ A nossa') + '</button>'
      +     (tiras ? '<button class="btn btn-sm" data-ft="galeria">'
                     + (aberta ? 'Fechar' : 'Galeria') + '</button>' : '')
      +     (sc.nossa ? '<button class="btn btn-sm btn-fantasma" data-ft="repor">De origem</button>' : '')
      +     '<span class="ft-erro"></span>'
      +   '</div>'
      + '</div>'
      + (tiras ? '<div class="ft-galeria"><div class="ft-tiras">' + tiras + '</div></div>' : '')
      + '</div>';
  }).join('');
  // O arrasto é da caixa, e a caixa nasce outra vez a cada pintura.
  cx.querySelectorAll('.ft-agora.move').forEach(c => {
    c.addEventListener('pointerdown', ftArrastar);
    c.addEventListener('keydown', ftTecla);
  });
}

// ---------- ver em ponto grande ----------
function ftLente(sec){
  const sc = ftSec(sec); if (!sc || !sc.atual) return;
  const lt = document.getElementById('ft-lente');
  lt.querySelector('img').src = sc.atual;
  lt.querySelector('img').alt = 'A fotografia da secção ' + sc.rotulo;
  lt.querySelector('.leg').textContent = sc.rotulo + ' · '
    + (sc.nossa ? 'vossa' : 'da galeria da casa');
  lt.classList.add('on');
  lt.querySelector('.fechar').focus();
}
function ftLenteFechar(){ document.getElementById('ft-lente').classList.remove('on'); }

// ---------- enquadrar: que ponto da fotografia fica à vista ----------
// Cola-se ao centro e aos terços — são as posições que de facto se procuram
// (um rosto ao centro, o horizonte num terço) e acertar nelas à mão, num
// retângulo de 70px, era trabalho de paciência. Com Shift arrasta-se livre.
const FT_IMAS = [33.333, 50, 66.667];
function ftColar(v){
  for (const a of FT_IMAS) if (Math.abs(v - a) < 4) return a;
  return Math.round(v * 10) / 10;
}
/** Mostra já o novo ponto, sem esperar pelo servidor. */
function ftPor(caixa, sec, x, y){
  const sc = ftSec(sec); if (!sc || !sc.pos) return;
  sc.pos.x = x; sc.pos.y = y;
  const im = caixa.querySelector('img');
  if (im){ im.style.objectPosition = x + '% ' + y + '%'; }
  const mira = caixa.querySelector('.ft-mira');
  if (mira){ mira.style.left = x + '%'; mira.style.top = y + '%'; }
}
async function ftGuardarPos(sec){
  const sc = ftSec(sec); if (!sc || !sc.pos) return;
  const d = await api('convite_foto_posicao',
    { method:'POST', body: JSON.stringify({ chave: sec, x: sc.pos.x, y: sc.pos.y }) });
  if (!d || !d.success){ ftErro(sec, (d && d.message) || 'Não foi possível enquadrar.'); return; }
  ftAplicar(d);
}
function ftArrastar(ev){
  const caixa = ev.currentTarget;
  if (ev.target.closest('.ft-lupa')) return;      // a lupa é um botão, não um arrasto
  const sec = caixa.closest('.ft-sec').dataset.sec;
  caixa.setPointerCapture(ev.pointerId);
  caixa.classList.add('a-mover');
  const mover = e2 => {
    const r = caixa.getBoundingClientRect();
    let x = (e2.clientX - r.left) / r.width * 100, y = (e2.clientY - r.top) / r.height * 100;
    x = Math.max(0, Math.min(100, x)); y = Math.max(0, Math.min(100, y));
    if (!e2.shiftKey){ x = ftColar(x); y = ftColar(y); }
    ftPor(caixa, sec, x, y);
  };
  const largar = () => {
    caixa.classList.remove('a-mover');
    caixa.removeEventListener('pointermove', mover);
    caixa.removeEventListener('pointerup', largar);
    caixa.removeEventListener('pointercancel', largar);
    ftGuardarPos(sec);
  };
  mover(ev);
  caixa.addEventListener('pointermove', mover);
  caixa.addEventListener('pointerup', largar);
  caixa.addEventListener('pointercancel', largar);
  ev.preventDefault();
}
// Com o teclado: as setas mexem 2% de cada vez. Quem não usa rato também tem
// uma fotografia para enquadrar.
let FT_TECLA = 0;
function ftTecla(ev){
  const passos = { ArrowLeft:[-2,0], ArrowRight:[2,0], ArrowUp:[0,-2], ArrowDown:[0,2] };
  const p = passos[ev.key]; if (!p) return;
  const caixa = ev.currentTarget, sec = caixa.closest('.ft-sec').dataset.sec;
  const sc = ftSec(sec); if (!sc || !sc.pos) return;
  ev.preventDefault();
  caixa.classList.add('a-mover');
  ftPor(caixa, sec, Math.max(0, Math.min(100, sc.pos.x + p[0])),
                    Math.max(0, Math.min(100, sc.pos.y + p[1])));
  clearTimeout(FT_TECLA);
  FT_TECLA = setTimeout(() => { caixa.classList.remove('a-mover'); ftGuardarPos(sec); }, 500);
}

/** Um aviso junto ao botão que falhou: é aí que o olho está. */
function ftErro(sec, txt){
  const el = document.querySelector('.ft-sec[data-sec="' + sec + '"] .ft-erro');
  if (el) el.textContent = txt || '';
}

/** Depois de qualquer troca: a lista nova, e a prova a mostrar o que mudou. */
function ftAplicar(d){
  FT_SECS = d.seccoes || FT_SECS;
  ftPintar();
  const pv = document.querySelector('.peca-prova iframe');
  if (pv) pv.src = pv.src.split('#')[0] + '&r=' + Date.now();
}

function ftEnviar(sec){
  const inp = document.createElement('input');
  inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp';
  inp.style.display = 'none'; document.body.appendChild(inp);
  inp.addEventListener('change', async () => {
    const f = inp.files && inp.files[0];
    inp.remove();
    if (!f) return;
    if (f.size > FT_MAX * 1048576){
      ftErro(sec, 'A fotografia tem mais de ' + FT_MAX + ' MB. Escolham uma mais leve.');
      return;
    }
    ftErro(sec, '');
    const bt = document.querySelector('.ft-sec[data-sec="' + sec + '"] [data-ft="enviar"]');
    const rot = bt ? bt.textContent : '';
    if (bt){ bt.disabled = true; bt.textContent = 'A enviar…'; }
    const fd = new FormData();
    fd.append('chave', sec); fd.append('ficheiro', f);
    const d = await api('convite_foto_enviar', { method:'POST', body: fd });
    if (bt){ bt.disabled = false; bt.textContent = rot; }
    if (!d || !d.success){ ftErro(sec, (d && d.message) || 'Não foi possível enviar.'); return; }
    ftAplicar(d);
    toast('Fotografia trocada. O convite já a mostra.');
  });
  inp.click();
}

async function ftDaGaleria(sec, src){
  const d = await api('convite_foto_galeria',
                      { method:'POST', body: JSON.stringify({ chave: sec, src }) });
  if (!d || !d.success){ ftErro(sec, (d && d.message) || 'Não foi possível trocar.'); return; }
  ftAplicar(d);
  toast('Fotografia trocada. O convite já a mostra.');
}

async function ftRepor(sec){
  const sc = FT_SECS.find(x => x.chave === sec) || {};
  const r = await licConfirmar({
    titulo: 'Voltar à fotografia de origem em «' + licEsc(sc.rotulo || '') + '»?',
    icone: '↩️', perigo: true, confirmar: 'Voltar à de origem',
    texto: 'A secção volta a mostrar a fotografia com que o convite nasceu, e a '
         + 'vossa é <b>apagada</b>.<br><br>Se ela estiver guardada numa das vossas '
         + 'versões, o ficheiro fica — é essa versão que o segura.'
  });
  if (!r.sim) return;
  const d = await api('convite_foto_repor', { method:'POST', body: JSON.stringify({ chave: sec }) });
  if (!d || !d.success){ ftErro(sec, (d && d.message) || 'Não foi possível repor.'); return; }
  ftAplicar(d);
  toast('Secção de volta à fotografia de origem.');
}

document.getElementById('ft-secs').addEventListener('click', ev => {
  const op = ev.target.closest('.ft-op');
  if (op){ ftDaGaleria(op.dataset.sec, op.dataset.src); return; }
  const bt = ev.target.closest('[data-ft]');
  if (!bt) return;
  const sec = bt.closest('.ft-sec').dataset.sec;
  if (bt.dataset.ft === 'enviar')  return ftEnviar(sec);
  if (bt.dataset.ft === 'repor')   return ftRepor(sec);
  if (bt.dataset.ft === 'lupa')    return ftLente(sec);
  if (bt.dataset.ft === 'galeria'){ FT_ABERTA = FT_ABERTA === sec ? '' : sec; ftPintar(); }
});
document.getElementById('ft-lente').addEventListener('click', ev => {
  if (ev.target.closest('img')) return;     // carregar na fotografia não a fecha
  ftLenteFechar();
});
document.addEventListener('keydown', ev => {
  if (ev.key === 'Escape') ftLenteFechar();
});
ftCarregar();
</script>
</body>
</html>
