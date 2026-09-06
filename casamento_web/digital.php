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
     Uma ficha por secção, lado a lado: a fotografia que lá está e o que se
     pode fazer com ela. Nada de camadas nem de réguas — quem vem aqui quer pôr
     uma fotografia sua. */
  .ft-cab{ margin:-.2rem 0 .8rem; }
  .ft-secs{ display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:.7rem; }
  .ft-sec{ display:grid; grid-template-columns:auto 1fr; gap:.75rem; align-items:start;
           border:1px solid var(--line); border-radius:12px; padding:.6rem .7rem; background:#fff; }
  /* A mesma caixa para todas as secções. As janelas do convite têm feitios
     diferentes — uma capa a toda a altura do ecrã não é um passe de entrada —,
     mas uma grelha de miniaturas com quatro feitios era uma escada. O feitio
     de cada uma, e o que lá cabe, vê-se em ponto grande. */
  .ft-agora{ position:relative; border-radius:9px; overflow:hidden; background:var(--cream);
             width:124px; aspect-ratio:4/3; }
  .ft-agora img{ width:100%; height:100%; object-fit:cover; display:block; }
  .ft-agora .et{ position:absolute; left:0; bottom:0; right:0; text-align:center;
                 font-size:.58rem; letter-spacing:.03em; text-transform:uppercase;
                 white-space:nowrap; padding:.15rem; background:rgba(14,15,12,.72); color:#fff; }
  .ft-sec.nossa .ft-agora{ outline:2px solid var(--gold); outline-offset:-2px; }
  /* A lupa, por cima da fotografia: vê-se pequena, e é em grande que se decide. */
  .ft-lupa{ position:absolute; top:.25rem; right:.25rem; width:24px; height:24px; padding:0;
            border:0; border-radius:7px; background:rgba(14,15,12,.6); color:#fff;
            font-size:.85rem; line-height:1; cursor:pointer; display:flex;
            align-items:center; justify-content:center; }
  .ft-lupa:hover{ background:rgba(14,15,12,.9); }
  .ft-lupa:focus-visible{ outline:2px solid var(--gold); outline-offset:2px; }
  .ft-nome{ font-size:.9rem; color:var(--ink); font-weight:600; }
  .ft-desc{ font-size:.76rem; color:#8a8f88; margin-top:.05rem; line-height:1.35; }
  .ft-acoes{ display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.45rem; }
  .ft-acoes .btn{ font-size:.76rem; padding:.28rem .6rem; }
  .ft-erro{ flex-basis:100%; font-size:.76rem; color:var(--danger); margin-top:.1rem; }
  .ft-erro:empty{ display:none; }
  .ft-vazio{ font-size:.85rem; color:#8a8f88; line-height:1.55; }

  /* ---- Em ponto grande, com a moldura da secção ----
     A fotografia inteira, e por cima a janela por onde a secção a mostra: o
     que fica de fora escurece. É a pergunta e a resposta no mesmo sítio. */
  .ft-lente{ position:fixed; inset:0; z-index:80; display:none; flex-direction:column;
             align-items:center; justify-content:center; gap:.7rem; padding:2rem 2rem 1.2rem;
             background:rgba(10,14,10,.86); backdrop-filter:blur(2px); }
  .ft-lente.on{ display:flex; }
  .ft-palco{ position:relative; display:flex; min-height:0; touch-action:none; }
  .ft-palco img{ min-height:0; max-width:100%; max-height:100%; border-radius:10px;
                 box-shadow:0 20px 60px rgba(0,0,0,.5); display:block; }
  /* O de fora escurece com uma sombra enorme à volta da moldura: é uma caixa
     só, e o buraco é sempre exatamente o recorte. */
  .ft-janela{ position:absolute; border:1px solid rgba(255,255,255,.9); cursor:grab;
              box-shadow:0 0 0 9999px rgba(10,14,10,.62); }
  .ft-janela:active, .ft-janela.a-mover{ cursor:grabbing; }
  .ft-janela:focus-visible{ outline:2px solid var(--gold); outline-offset:2px; }
  /* Os terços, para se enquadrar por eles. */
  .ft-janela::before, .ft-janela::after{ content:''; position:absolute; inset:0;
                                         pointer-events:none; opacity:.45; }
  .ft-janela::before{ background:
      linear-gradient(to right, transparent 33.2%, rgba(255,255,255,.7) 33.2% 33.5%,
                      transparent 33.5% 66.4%, rgba(255,255,255,.7) 66.4% 66.7%, transparent 66.7%); }
  .ft-janela::after{ background:
      linear-gradient(to bottom, transparent 33.2%, rgba(255,255,255,.7) 33.2% 33.5%,
                      transparent 33.5% 66.4%, rgba(255,255,255,.7) 66.4% 66.7%, transparent 66.7%); }
  /* A legenda por baixo, e não por cima: uma legenda em cima da fotografia
     tapa-lhe justamente o pedaço que se veio aqui ver. */
  .ft-lente-pe{ flex:none; z-index:1; display:flex; align-items:center; gap:.8rem;
                flex-wrap:wrap; justify-content:center; text-align:center; }
  .ft-lente-pe .leg{ color:var(--ivory); font-size:.85rem; }
  .ft-lente-ac{ display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;
                justify-content:center; }
  .ft-lente-dica{ color:#c9cfc6; font-size:.78rem; }
  /* Por guardar: o aviso é discreto, mas não passa por dizer nada. */
  .ft-lente-dica.aviso{ color:var(--gold-pale); }
  .ft-lente-dica.aviso::before{ content:'●'; font-size:.6em; vertical-align:.25em;
                                margin-right:.35rem; color:var(--gold); }
  /* Os botões do pé vivem sobre o escuro: os da casa são para fundo claro e
     desapareciam aqui. */
  .ft-lente-ac .btn{ font-size:.76rem; padding:.28rem .7rem; background:rgba(255,255,255,.1);
                     border:1px solid rgba(233,223,201,.42); color:var(--ivory); }
  .ft-lente-ac .btn:hover{ background:rgba(255,255,255,.2); }
  /* O «Guardar» é o que se veio aqui fazer: veste-se como tal enquanto houver
     o que guardar, e apaga-se quando não houver. */
  .ft-lente-ac .btn-ouro{ background:var(--gold); border-color:var(--gold);
                          color:var(--forest-deep); font-weight:600; }
  .ft-lente-ac .btn-ouro:hover{ background:var(--gold-soft); border-color:var(--gold-soft); }
  .ft-lente-ac .btn-ouro[disabled]{ background:none; border-color:rgba(233,223,201,.25);
                                    color:#9aa196; font-weight:400; cursor:default; }
  .ft-lente .fechar{ position:absolute; top:.8rem; right:1rem; background:none; border:0;
                     color:#fff; font-size:1.8rem; line-height:1; cursor:pointer; z-index:2; }

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
        <p class="dica ft-cab">Uma por secção, as vossas. A lupa abre a fotografia em
          ponto grande e mostra a moldura da secção — é aí que se escolhe o que fica no
          convite. <span class="ft-formatos">jpg, png ou webp, até <b id="ft-max">5</b> MB.</span></p>
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

<!-- A fotografia em ponto grande, e a janela por onde a secção a mostra -->
<div class="ft-lente" id="ft-lente" role="dialog" aria-modal="true" aria-label="Fotografia em ponto grande">
  <button type="button" class="fechar" id="ft-lente-fechar" onclick="ftLenteFechar()"
          aria-label="Fechar">&times;</button>
  <div class="ft-palco" id="ft-palco">
    <img id="ft-lente-img" src="" alt="">
    <div class="ft-janela" id="ft-janela" hidden tabindex="0" role="application"
         aria-label="Enquadramento: arraste a moldura, ou use as setas"></div>
  </div>
  <div class="ft-lente-pe">
    <span class="leg" id="ft-lente-leg"></span>
    <span class="ft-lente-ac" id="ft-lente-ac"></span>
  </div>
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
let FT_SECS = [], FT_MAX = 5;

function ftEsc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function ftEl(id){ return document.getElementById(id); }

/** A secção com esta chave, tal como o servidor a descreveu. */
function ftSec(sec){ return FT_SECS.find(x => x.chave === sec) || null; }

async function ftCarregar(){
  const d = await api('convite_fotos', { method:'GET' });
  if (!d || !d.success) return;
  FT_SECS = d.seccoes || []; FT_MAX = d.max_mb || 5;
  const mx = ftEl('ft-max');
  if (mx) mx.textContent = FT_MAX;
  ftPintar();
}

function ftPintar(){
  const cx = ftEl('ft-secs'); if (!cx) return;
  if (!FT_SECS.length){
    cx.innerHTML = '<p class="ft-vazio">Este convite não mostra nenhuma secção com fotografia.</p>';
    return;
  }
  // A caixa é a mesma para todas — quatro janelas de feitios diferentes numa
  // grelha davam uma escada. Qual é o feitio de cada secção, e o que lá cabe,
  // vê-se em ponto grande, que é onde isso se decide.
  cx.innerHTML = FT_SECS.map(sc =>
      '<div class="ft-sec' + (sc.nossa ? ' nossa' : '') + '"'
    + ' data-sec="' + ftEsc(sc.chave) + '">'
    + '<div class="ft-agora">'
    +   (sc.atual ? '<img src="' + ftEsc(sc.atual) + '"'
                    + (sc.pos ? ' style="object-position:' + sc.pos.x + '% ' + sc.pos.y + '%"' : '')
                    + ' alt="A fotografia da secção ' + ftEsc(sc.rotulo) + '">' : '')
    +   '<button type="button" class="ft-lupa" data-ft="lupa"'
    +     ' title="Ver em ponto grande" aria-label="Ver em ponto grande">⤢</button>'
    +   '<span class="et">' + (sc.nossa ? 'vossa' : 'do modelo') + '</span>'
    + '</div>'
    + '<div>'
    +   '<div class="ft-nome">' + ftEsc(sc.rotulo) + '</div>'
    +   '<div class="ft-desc">' + ftEsc(sc.descricao) + '</div>'
    +   '<div class="ft-acoes">'
    +     '<button class="btn btn-sm btn-ouro" data-ft="enviar">'
    +       (sc.nossa ? 'Trocar' : '＋ A nossa') + '</button>'
    +     (sc.enq ? '<button class="btn btn-sm" data-ft="lupa">Enquadrar</button>' : '')
    +     (sc.nossa ? '<button class="btn btn-sm btn-fantasma" data-ft="repor">De origem</button>' : '')
    +     '<span class="ft-erro"></span>'
    +   '</div>'
    + '</div></div>').join('');
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

// ============================================================
// Em ponto grande — e é aqui que se enquadra
//
// A miniatura mostra que fotografia está em cada secção; não dá para decidir
// nela o que fica no convite. Em grande, vê-se a fotografia inteira e, por
// cima, a janela por onde a secção a mostra: o que fica de fora escurece. É a
// pergunta e a resposta no mesmo sítio — arrasta-se a moldura até ela conter o
// que interessa.
// ============================================================
let FT_LENTE = null;      // a secção aberta
let FT_MOLDURA = true;    // a moldura está à vista?
let FT_GRAVADO = null;    // o enquadramento como está no servidor
let FT_DITO = '';         // o que o pé está a dizer, para não o repintar em vão

function ftLente(sec){
  const sc = ftSec(sec); if (!sc || !sc.atual) return;
  FT_LENTE = sc; FT_MOLDURA = true;
  FT_GRAVADO = sc.pos ? { x: sc.pos.x, y: sc.pos.y } : null;
  const im = ftEl('ft-lente-img');
  im.onload = () => { ftJanelaPintar(); ftLentePe(); };
  im.src = sc.atual;
  im.alt = 'A fotografia da secção ' + sc.rotulo;
  ftEl('ft-lente-leg').textContent = sc.rotulo + ' · ' + (sc.nossa ? 'vossa' : 'do modelo');
  FT_DITO = '';
  ftLentePe();
  ftEl('ft-lente').classList.add('on');
  ftJanelaPintar();
  if (sc.enq) ftEl('ft-janela').focus(); else ftEl('ft-lente-fechar').focus();
}

/** Há enquadramento por guardar? */
function ftSujo(){
  return !!(FT_LENTE && FT_LENTE.pos && FT_GRAVADO
            && (FT_LENTE.pos.x !== FT_GRAVADO.x || FT_LENTE.pos.y !== FT_GRAVADO.y));
}

/**
 * Fechar não é desistir.
 *
 * Um enquadramento a meio é trabalho, e trabalho não se perde por se carregar
 * no ✕. Havendo o que guardar, pergunta-se — e quem descarta volta ao que
 * estava gravado, e não a nada.
 */
async function ftLenteFechar(){
  if (ftSujo()){
    const r = await licConfirmar({
      titulo: 'Guardar o enquadramento?', icone: '🖼️', confirmar: 'Guardar',
      cancelar: 'Descartar',
      texto: 'Mexeu na moldura de «' + licEsc(FT_LENTE.rotulo) + '» e ainda não guardou. '
           + 'Se descartar, a secção fica com o enquadramento que tinha.'
    });
    if (r.sim){ await ftGuardarPos(); }
    else { FT_LENTE.pos.x = FT_GRAVADO.x; FT_LENTE.pos.y = FT_GRAVADO.y; }
  }
  ftEl('ft-lente').classList.remove('on');
  FT_LENTE = null; FT_GRAVADO = null; FT_DITO = '';
}

/**
 * O pé da lente: o que se pode fazer, e o que falta fazer.
 *
 * O «Guardar» está aqui porque tem de estar: sem ele, ninguém sabe se o que
 * arrastou ficou. Enquanto não houver nada por guardar, está apagado — e diz
 * «Guardado», que é a outra metade da mesma resposta.
 */
function ftLentePe(){
  const pe = ftEl('ft-lente-ac'), sc = FT_LENTE;
  if (!sc || !sc.enq){ pe.innerHTML = ''; FT_DITO = ''; return; }
  const sujo = ftSujo();
  const dica = !FT_MOLDURA ? 'A fotografia inteira, sem moldura.'
             : sujo        ? 'Enquadramento por guardar.'
             : ftPresa();
  const chave = (FT_MOLDURA ? 'm' : '-') + (sujo ? 's' : '-') + dica;
  if (chave === FT_DITO) return;          // nada mudou: não se repinta
  FT_DITO = chave;
  pe.innerHTML =
      '<span class="ft-lente-dica' + (sujo ? ' aviso' : '') + '">' + ftEsc(dica) + '</span>'
    + (FT_MOLDURA
        ? '<button type="button" class="btn btn-sm btn-ouro" data-lt="guardar"'
          + (sujo ? '' : ' disabled') + '>'
          + (sujo ? 'Guardar enquadramento' : 'Guardado') + '</button>'
          + (sujo ? '<button type="button" class="btn btn-sm btn-fantasma" data-lt="desfazer">Desfazer</button>'
                  : '<button type="button" class="btn btn-sm btn-fantasma" data-lt="centrar">Centrar</button>')
        : '')
    + '<button type="button" class="btn btn-sm" data-lt="moldura">'
    +   (FT_MOLDURA ? 'Ver sem moldura' : 'Enquadrar') + '</button>';
}

/**
 * Que lado desta fotografia é que não se mexe.
 *
 * Uma fotografia larga numa janela estreita já cabe inteira em altura: puxá-la
 * para cima ou para baixo não faz nada, e quem tenta fica a pensar que a coisa
 * está avariada. Mais vale dizê-lo.
 */
function ftPresa(){
  const m = ftJanelaMedidas();
  if (!m) return 'Arraste a moldura para escolher o que fica no convite.';
  const soX = (m.ih - m.h) < 0.5, soY = (m.iw - m.w) < 0.5;
  if (soX && soY) return 'Esta fotografia cabe inteira na moldura — não há nada para correr.';
  if (soX) return 'Arraste a moldura na horizontal: em altura, a fotografia já cabe inteira.';
  if (soY) return 'Arraste a moldura na vertical: em largura, a fotografia já cabe inteira.';
  return 'Arraste a moldura para escolher o que fica no convite.';
}

/** "9/16" -> 0.5625 (largura a dividir pela altura). */
function ftProporcao(p){
  const m = String(p || '').split('/');
  const a = parseFloat(m[0]), b = parseFloat(m[1]);
  return (a > 0 && b > 0) ? a / b : 1;
}

/**
 * A janela por onde a secção vê a fotografia, em píxeis do ecrã.
 *
 * É a mesma conta que o browser faz com object-fit:cover — a maior área com a
 * forma da secção que cabe na fotografia (dividida pela aproximação) — e o
 * ponto guardado diz onde ela está. Sem isto, a moldura seria um desenho
 * bonito que não corresponderia ao recorte verdadeiro.
 */
function ftJanelaMedidas(){
  const sc = FT_LENTE, im = ftEl('ft-lente-img');
  if (!sc || !sc.enq || !sc.pos || !im.naturalWidth) return null;
  const iw = im.naturalWidth, ih = im.naturalHeight;
  const A = ftProporcao(sc.proporcao), z = Math.max(1, (sc.pos.zoom || 100) / 100);
  const w = Math.min(iw, ih * A) / z, h = Math.min(ih, iw / A) / z;
  const r = im.getBoundingClientRect();
  const pr = ftEl('ft-palco').getBoundingClientRect();
  const s = r.width / iw;                       // do tamanho real para o ecrã
  return { iw, ih, w, h, s,
           dx: r.left - pr.left, dy: r.top - pr.top };
}

function ftJanelaPintar(){
  const jan = ftEl('ft-janela'), sc = FT_LENTE;
  const m = ftJanelaMedidas();
  if (!m || !FT_MOLDURA){ jan.hidden = true; return; }
  jan.hidden = false;
  jan.style.left   = (m.dx + (sc.pos.x / 100) * (m.iw - m.w) * m.s) + 'px';
  jan.style.top    = (m.dy + (sc.pos.y / 100) * (m.ih - m.h) * m.s) + 'px';
  jan.style.width  = (m.w * m.s) + 'px';
  jan.style.height = (m.h * m.s) + 'px';
}

/** Põe o ponto (em %) e repinta. É rascunho: só o «Guardar» o torna real. */
function ftPor(x, y){
  const sc = FT_LENTE; if (!sc || !sc.pos) return;
  sc.pos.x = Math.round(Math.max(0, Math.min(100, x)) * 10) / 10;
  sc.pos.y = Math.round(Math.max(0, Math.min(100, y)) * 10) / 10;
  ftJanelaPintar();
  ftLentePe();
}

/** Guarda o enquadramento — e diz que guardou, que é metade do trabalho. */
async function ftGuardarPos(){
  const sc = FT_LENTE; if (!sc || !sc.pos) return;
  const alvo = { chave: sc.chave, x: sc.pos.x, y: sc.pos.y };
  const d = await api('convite_foto_posicao',
    { method:'POST', body: JSON.stringify(alvo) });
  if (!d || !d.success){ toast((d && d.message) || 'Não foi possível guardar.', true); return; }
  ftAplicar(d);
  // A lista foi refeita: a lente tem de voltar a apontar para a secção nova.
  FT_LENTE = ftSec(alvo.chave) || FT_LENTE;
  FT_GRAVADO = { x: alvo.x, y: alvo.y };
  if (FT_LENTE.pos){ FT_LENTE.pos.x = alvo.x; FT_LENTE.pos.y = alvo.y; }
  ftJanelaPintar();
  ftLentePe();
  toast('Enquadramento guardado. O convite já mostra este pedaço.');
}

// Cola-se ao centro e aos terços — são as posições que de facto se procuram
// (um rosto ao centro, o horizonte num terço). Com Shift arrasta-se livre.
const FT_IMAS = [0, 33.333, 50, 66.667, 100];
function ftColar(v){
  for (const a of FT_IMAS) if (Math.abs(v - a) < 2.5) return a;
  return v;
}

function ftArrastar(ev){
  const sc = FT_LENTE;
  if (!sc || !sc.enq || !FT_MOLDURA) return;
  const m = ftJanelaMedidas(); if (!m) return;
  const palco = ftEl('ft-palco');
  const jan = ftEl('ft-janela');
  const jr = jan.getBoundingClientRect();
  // Agarrar a moldura mantém o ponto onde se lhe pegou; carregar fora dela
  // traz-lhe o centro, que é o gesto de quem aponta ao que quer ver.
  const dentro = ev.clientX >= jr.left && ev.clientX <= jr.right
              && ev.clientY >= jr.top  && ev.clientY <= jr.bottom;
  const pega = dentro ? { x: ev.clientX - jr.left, y: ev.clientY - jr.top }
                      : { x: jr.width / 2, y: jr.height / 2 };
  palco.setPointerCapture(ev.pointerId);
  jan.classList.add('a-mover');
  const mover = e2 => {
    const pr = palco.getBoundingClientRect();
    // Canto superior esquerdo da moldura, em píxeis da fotografia real.
    const px = ((e2.clientX - pr.left - pega.x) - m.dx) / m.s;
    const py = ((e2.clientY - pr.top  - pega.y) - m.dy) / m.s;
    // Um eixo sem folga (a moldura cobre a fotografia toda nessa direção) não
    // se mexe — e o valor guardado fica como está, que volta a fazer sentido se
    // a fotografia for trocada por outra de outro feitio.
    const livreX = m.iw - m.w, livreY = m.ih - m.h;
    let x = livreX > 0.5 ? (px / livreX) * 100 : sc.pos.x;
    let y = livreY > 0.5 ? (py / livreY) * 100 : sc.pos.y;
    if (!e2.shiftKey){ x = ftColar(x); y = ftColar(y); }
    ftPor(x, y);
  };
  const largar = () => {
    jan.classList.remove('a-mover');
    palco.removeEventListener('pointermove', mover);
    palco.removeEventListener('pointerup', largar);
    palco.removeEventListener('pointercancel', largar);
    ftLentePe();          // o «Guardar» acende-se; gravar é decisão de quem arrastou
  };
  mover(ev);
  palco.addEventListener('pointermove', mover);
  palco.addEventListener('pointerup', largar);
  palco.addEventListener('pointercancel', largar);
  ev.preventDefault();
}

// Com o teclado: as setas mexem 2% de cada vez, e Enter guarda. Quem não usa
// rato também tem uma fotografia para enquadrar.
function ftTecla(ev){
  const sc = FT_LENTE;
  if (!sc || !sc.pos || !FT_MOLDURA) return;
  if (ev.key === 'Enter'){ ev.preventDefault(); if (ftSujo()) ftGuardarPos(); return; }
  const passos = { ArrowLeft:[-2,0], ArrowRight:[2,0], ArrowUp:[0,-2], ArrowDown:[0,2] };
  const p = passos[ev.key];
  if (!p) return;
  ev.preventDefault(); ev.stopPropagation();
  ftPor(sc.pos.x + p[0], sc.pos.y + p[1]);
}

// ---------- trocar e repor ----------
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
    // Uma fotografia nova chega ao centro: é agora que se enquadra, e é para
    // isso que a lente abre sozinha.
    const nova = ftSec(sec);
    if (nova && nova.enq) ftLente(sec);
  });
  inp.click();
}

async function ftRepor(sec){
  const sc = ftSec(sec) || {};
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

// ---------- os gestos ----------
ftEl('ft-secs').addEventListener('click', ev => {
  const bt = ev.target.closest('[data-ft]');
  if (!bt) return;
  const sec = bt.closest('.ft-sec').dataset.sec;
  if (bt.dataset.ft === 'enviar') return ftEnviar(sec);
  if (bt.dataset.ft === 'repor')  return ftRepor(sec);
  if (bt.dataset.ft === 'lupa')   return ftLente(sec);
});
ftEl('ft-lente-ac').addEventListener('click', ev => {
  const bt = ev.target.closest('[data-lt]');
  if (!bt) return;
  if (bt.dataset.lt === 'guardar')  return ftGuardarPos();
  if (bt.dataset.lt === 'centrar')  return ftPor(50, 50);
  if (bt.dataset.lt === 'desfazer'){
    if (FT_GRAVADO) ftPor(FT_GRAVADO.x, FT_GRAVADO.y);
    return;
  }
  if (bt.dataset.lt === 'moldura'){ FT_MOLDURA = !FT_MOLDURA; ftLentePe(); ftJanelaPintar(); }
});
ftEl('ft-palco').addEventListener('pointerdown', ftArrastar);
ftEl('ft-janela').addEventListener('keydown', ftTecla);
ftEl('ft-lente').addEventListener('click', ev => {
  // Só o fundo fecha — e mede-se por o alvo SER o fundo, e não por ele estar
  // fora do palco: um botão do pé que se redesenha a si próprio já não está na
  // página quando o clique chega aqui, e fechava a lente ao ser carregado.
  if (ev.target === ev.currentTarget) ftLenteFechar();
});
document.addEventListener('keydown', ev => {
  if (ev.key === 'Escape') ftLenteFechar();
});
window.addEventListener('resize', ftJanelaPintar);
ftCarregar();
</script>
</body>
</html>
