<?php
// ============================================================
// orcamento.php — O curso das despesas do casamento
//
// Um módulo à parte, para os noivos verem para onde vai o dinheiro e a que
// ritmo. Assenta no que o sistema já sabe fazer: âmbito por casamento (um casal
// nunca vê as contas de outro), a API de ação única, os papéis. É dos noivos —
// exigirAdmin() barra o porteiro, que trabalha à porta e não tem cá contas.
//
// A página é uma casca: pede o estado à API (orc_estado) e desenha. O teto e a
// moeda vivem nas definições do casamento, e por isso viajam no export/import
// sem tratamento à parte, como o resto do orçamento.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
exigirAdmin();

$visita = emVisitaDeSuporte();
$soVer  = $visita && !podeCorrigir();
$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Orçamento · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  /* Os três estados do dinheiro, e o que passa do teto. Vêm sempre com rótulo
     (legenda e valor em cada segmento), por isso a cor nunca conta sozinha. */
  :root{
    --o-pago:#2f7d4f;      /* já saiu          */
    --o-contr:#B4864A;     /* contratado       */
    --o-prev:#D9BC8C;      /* previsto         */
    --o-over:#a5473f;      /* acima do teto    */
    --o-track:#efe8da;     /* o carril vazio   */
  }
  main.container{ max-width:920px; }
  .painel{ background:#fff; border:1px solid var(--line); border-radius:16px;
           padding:1.25rem 1.35rem; margin-bottom:1.15rem; box-shadow:0 1px 2px rgba(22,38,30,.03); }
  .painel h3{ margin:0 0 .15rem; font-size:1.08rem; }
  .painel .dica{ font-size:.84rem; color:#8a8f88; margin-bottom:1rem; line-height:1.5; }
  .painel-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.7rem 1rem; flex-wrap:wrap; }
  .painel-topo .btn{ flex:0 0 auto; }

  /* ---- Saúde do orçamento: os números que se leem primeiro ---- */
  .o-hero{ background:linear-gradient(158deg,#fff 0%, #fffdf8 100%);
           border-color:var(--gold-soft); }
  .o-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.7rem; margin-bottom:1.15rem; }
  .kpi{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:.85rem .95rem; position:relative; overflow:hidden; }
  .kpi::before{ content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--gold-soft); }
  .kpi .n{ font-family:var(--serif); font-size:1.7rem; font-weight:700; color:var(--ink); line-height:1.05;
           font-variant-numeric:tabular-nums; }
  .kpi .n small{ font-size:.9rem; color:#9aa09a; font-weight:400; }
  .kpi .l{ font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; color:#8a8f88; margin-top:.3rem; }
  .kpi.pago::before{ background:var(--o-pago); }   .kpi.pago .n{ color:var(--o-pago); }
  .kpi.ouro::before{ background:var(--o-contr); }
  .kpi.margem::before{ background:var(--o-pago); } .kpi.margem .n{ color:var(--o-pago); }
  .kpi.margem.mau::before{ background:var(--o-over); } .kpi.margem.mau .n{ color:var(--o-over); }

  .o-barra{ height:30px; border-radius:8px; background:var(--o-track); display:flex; overflow:hidden;
            border:1px solid var(--line); gap:2px; padding:2px; }
  .o-barra span{ display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:600;
                 color:#fff; white-space:nowrap; overflow:hidden; min-width:0; border-radius:5px;
                 transition:width .35s ease; }
  .o-barra .g-pago{ background:var(--o-pago); }
  .o-barra .g-contr{ background:var(--o-contr); }
  .o-barra .g-prev{ background:var(--o-prev); color:var(--forest-deep); }
  .o-legenda{ display:flex; flex-wrap:wrap; gap:.4rem 1.1rem; margin-top:.8rem; font-size:.8rem; color:#6c7570; }
  .o-legenda span{ display:inline-flex; align-items:center; gap:.4rem; }
  .o-legenda i{ width:11px; height:11px; border-radius:3px; display:inline-block; border:1px solid rgba(0,0,0,.05); }
  .o-legenda b{ color:var(--ink); font-variant-numeric:tabular-nums; }

  /* ---- Categorias: onde pesa a festa ---- */
  .o-cats{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:.8rem; }
  .o-cat{ border:1px solid var(--line); border-radius:12px; padding:.85rem .95rem; background:#fff;
          transition:.14s; position:relative; }
  .o-cat:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.1); }
  .o-cat .nome{ font-weight:600; color:var(--ink); font-size:.95rem; }
  .o-cat .vals{ font-size:.82rem; color:#8a8f88; margin-top:.15rem; font-variant-numeric:tabular-nums; }
  .o-cat .vals b{ color:var(--ink); }
  .o-cat .meter{ height:7px; border-radius:5px; background:var(--o-track); margin-top:.55rem; overflow:hidden; }
  .o-cat .meter > i{ display:block; height:100%; background:var(--o-contr); border-radius:5px; }
  .o-cat.acima .meter > i{ background:var(--o-over); }
  .o-cat .pct{ position:absolute; top:.8rem; right:.9rem; font-size:.72rem; font-weight:700;
               color:var(--o-contr); font-variant-numeric:tabular-nums; }
  .o-cat.acima .pct{ color:var(--o-over); }
  .o-cat .acs{ display:flex; gap:.4rem; margin-top:.6rem; opacity:0; transition:.14s; }
  .o-cat:hover .acs, .o-cat:focus-within .acs{ opacity:1; }
  .mini{ font-size:.74rem; color:#6c7570; background:transparent; border:1px solid var(--line);
         border-radius:50px; padding:.14rem .6rem; cursor:pointer; }
  .mini:hover{ background:var(--cream); color:var(--ink); }
  .mini.perigo:hover{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .o-cat.semcat{ background:var(--gold-pale); border-style:dashed; }

  /* ---- Despesas ---- */
  .tabela-scroll{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
  table.desp{ width:100%; border-collapse:collapse; min-width:560px; }
  table.desp th{ text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.06em;
                 color:#8a8f88; font-weight:600; padding:.45rem .55rem; border-bottom:1px solid var(--line); }
  table.desp td{ padding:.6rem .55rem; border-bottom:1px solid var(--line); font-size:.9rem; vertical-align:middle; }
  table.desp tr:last-child td{ border-bottom:0; }
  table.desp tbody tr:hover{ background:#fdfbf6; }
  .d-nome{ color:var(--ink); font-weight:500; }
  .d-forn{ font-size:.77rem; color:#8a8f88; }
  .d-val{ text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; color:var(--ink); font-weight:500; }
  .d-ac{ text-align:right; white-space:nowrap; }
  .est{ font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; border-radius:50px;
        padding:.12rem .6rem; border:1px solid var(--line); white-space:nowrap; display:inline-flex; align-items:center; gap:.3rem; }
  .est::before{ content:''; width:7px; height:7px; border-radius:50%; background:currentColor; }
  .est.previsto{ background:#fbf5e9; color:#9a7a3c; border-color:var(--gold-soft); }
  .est.contratado{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .est.pago{ background:var(--ok-bg); color:var(--o-pago); border-color:var(--o-pago); }

  /* ---- Fatura (foto ou PDF) ---- */
  .fat-thumb{ width:38px; height:38px; border-radius:7px; object-fit:cover; border:1px solid var(--line);
              cursor:zoom-in; display:block; }
  .fat-chip{ display:inline-flex; align-items:center; gap:.35rem; font-size:.76rem; color:var(--forest);
             border:1px solid var(--gold-soft); background:var(--gold-pale); border-radius:50px;
             padding:.14rem .6rem; text-decoration:none; }
  .fat-chip:hover{ background:var(--gold-soft); }
  .fat-anexar{ font-size:.74rem; color:#8a8f88; border:1px dashed var(--line); border-radius:50px;
               padding:.14rem .6rem; cursor:pointer; background:transparent; }
  .fat-anexar:hover{ border-color:var(--gold-soft); color:var(--forest); }
  .fat-x{ border:0; background:none; color:var(--danger); cursor:pointer; font-size:1rem; line-height:1; margin-left:.2rem; }

  /* ---- Calendário de pagamentos ---- */
  .o-mes{ font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#a2a8a2;
          margin:.9rem 0 .3rem; font-weight:600; }
  .o-mes:first-child{ margin-top:0; }
  .pag{ display:grid; grid-template-columns:auto 1fr auto auto; gap:.7rem; align-items:center;
        padding:.55rem .2rem; border-top:1px solid var(--line); }
  .pag:first-of-type{ border-top:0; }
  .pag .data{ font-variant-numeric:tabular-nums; font-size:.85rem; color:var(--ink); white-space:nowrap;
              display:inline-flex; align-items:center; gap:.4rem; }
  .pag .data::before{ content:''; width:8px; height:8px; border-radius:50%; background:var(--gold-soft); }
  .pag .data.venceu{ color:var(--o-over); font-weight:600; } .pag .data.venceu::before{ background:var(--o-over); }
  .pag .data.pago{ color:var(--o-pago); } .pag .data.pago::before{ background:var(--o-pago); }
  .pag .desc{ font-size:.88rem; color:var(--ink); min-width:0; }
  .pag .desc small{ display:block; color:#8a8f88; font-size:.76rem; }
  .pag .mt{ font-variant-numeric:tabular-nums; font-size:.88rem; color:var(--ink); white-space:nowrap; text-align:right; font-weight:500; }

  .vazio{ text-align:center; padding:2rem 1rem; color:#9aa09a; font-size:.9rem; }
  .vazio .btn{ margin-top:.8rem; }

  /* ---- Modais ---- */
  .modal-fundo{ position:fixed; inset:0; background:rgba(22,38,30,.5); display:none;
                align-items:center; justify-content:center; padding:1rem; z-index:60; }
  .modal-fundo.aberto{ display:flex; }
  .modal{ background:#fff; border-radius:16px; padding:1.4rem 1.5rem; max-width:540px; width:100%;
          max-height:90vh; overflow:auto; box-shadow:0 20px 60px rgba(22,38,30,.3); }
  .modal h3{ margin:0 0 1rem; }
  .modal .campo{ margin-bottom:.9rem; }
  .modal .lin2{ display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
  .modal .fim{ display:flex; gap:.6rem; justify-content:flex-end; margin-top:1.2rem; }
  .md-fatura{ border-top:1px solid var(--line); margin-top:.4rem; padding-top:.9rem; }
  .md-fatura .prev{ display:flex; align-items:center; gap:.7rem; margin-top:.5rem; }
  .md-fatura .prev img{ width:64px; height:64px; object-fit:cover; border-radius:8px; border:1px solid var(--line); cursor:zoom-in; }
  .aviso-visita{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
                 border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1.2rem; line-height:1.5; }

  /* Ver uma fatura em grande. */
  #lightbox{ position:fixed; inset:0; z-index:120; display:none; align-items:center; justify-content:center;
             padding:2rem; background:rgba(20,25,22,.86); cursor:zoom-out; }
  #lightbox.on{ display:flex; }
  #lightbox img{ max-width:95vw; max-height:95vh; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.5); }

  @media (max-width:640px){
    .painel{ padding:1rem 1.05rem; }
    .kpi .n{ font-size:1.45rem; }
    .pag .data{ grid-row:1; grid-column:1; }
    .pag .mt{ grid-row:1; grid-column:2; }
    .pag .desc{ grid-row:2; grid-column:1 / -1; }
    .pag > span:last-child{ grid-row:3; grid-column:1 / -1; }
    .modal{ padding:1.2rem 1.1rem; }
    .modal .lin2{ grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<?php cabecalho('Orçamento', 'O curso das despesas — quanto se planeou, quanto saiu, o que falta', 'orcamento'); ?>

<main class="container">

  <?php if ($visita): ?>
    <div class="aviso-visita">
      Está a acompanhar este casamento com um código de suporte
      <b><?= $soVer ? 'de leitura' : 'com permissão de correção' ?></b>.
      <?= $soVer ? 'Vê as contas; alterá-las, não.' : '' ?>
    </div>
  <?php endif; ?>

  <!-- Saúde do orçamento -->
  <div class="painel o-hero">
    <div class="o-kpis" id="o-kpis"></div>
    <div class="o-barra" id="o-barra"></div>
    <div class="o-legenda" id="o-legenda"></div>
    <p class="dica" style="margin:.9rem 0 0">O <b>teto</b> e a <b>moeda</b> definem-se em
      <a href="gestao.php" style="color:var(--gold)">Gestão</a>. Sem teto, a barra mede-se pela soma
      dos previstos das categorias.</p>
  </div>

  <!-- Categorias -->
  <div class="painel">
    <div class="painel-topo">
      <div>
        <h3>Categorias</h3>
        <p class="dica" style="margin-bottom:0">Onde pesa a festa. A barra de cada uma é o real sobre o previsto.</p>
      </div>
      <button class="btn btn-fantasma" onclick="abrirCategoria()">+ Categoria</button>
    </div>
    <div id="lista-categorias" style="margin-top:1rem"></div>
  </div>

  <!-- Despesas -->
  <div class="painel">
    <div class="painel-topo">
      <div>
        <h3>Despesas</h3>
        <p class="dica" style="margin-bottom:0">Cada compromisso, com o seu estado e a sua fatura.</p>
      </div>
      <button class="btn btn-ouro" onclick="abrirDespesa()">+ Despesa</button>
    </div>
    <div id="lista-despesas" style="margin-top:1rem"></div>
  </div>

  <!-- Pagamentos (o curso no tempo) -->
  <div class="painel">
    <h3>Calendário de pagamentos</h3>
    <p class="dica">Os sinais e prestações, por data. O que é preciso ter em caixa, e quando.
      Abra uma despesa para lhe juntar parcelas.</p>
    <div id="lista-pagamentos"></div>
  </div>

</main>

<!-- Modal categoria -->
<div class="modal-fundo" id="m-cat">
  <div class="modal">
    <h3 id="m-cat-titulo">Categoria</h3>
    <input type="hidden" id="mc-id">
    <div class="campo">
      <label for="mc-nome">Nome</label>
      <input type="text" id="mc-nome" maxlength="80" placeholder="ex.: Decoração e flores">
    </div>
    <div class="campo">
      <label for="mc-previsto">Previsto</label>
      <input type="text" id="mc-previsto" class="campo-moeda" inputmode="decimal" placeholder="0,00">
    </div>
    <div class="fim">
      <button class="btn btn-fantasma" onclick="fechar('m-cat')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardarCategoria()">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal despesa -->
<div class="modal-fundo" id="m-desp">
  <div class="modal">
    <h3 id="m-desp-titulo">Despesa</h3>
    <input type="hidden" id="md-id">
    <div class="campo">
      <label for="md-desc">Descrição</label>
      <input type="text" id="md-desc" maxlength="160" placeholder="ex.: Menu para 120 pessoas">
    </div>
    <div class="lin2">
      <div class="campo">
        <label for="md-valor">Valor</label>
        <input type="text" id="md-valor" class="campo-moeda" inputmode="decimal" placeholder="0,00">
      </div>
      <div class="campo">
        <label for="md-estado">Estado</label>
        <select id="md-estado">
          <option value="previsto">Previsto</option>
          <option value="contratado">Contratado</option>
          <option value="pago">Pago</option>
        </select>
      </div>
    </div>
    <div class="lin2">
      <div class="campo">
        <label for="md-categoria">Categoria</label>
        <select id="md-categoria"><option value="">— sem categoria —</option></select>
      </div>
      <div class="campo">
        <label for="md-fornecedor">Fornecedor</label>
        <input type="text" id="md-fornecedor" maxlength="120" placeholder="opcional">
      </div>
    </div>
    <div class="campo">
      <label for="md-nota">Nota</label>
      <input type="text" id="md-nota" maxlength="255" placeholder="opcional — nº de contrato, condições…">
    </div>

    <!-- Fatura: só depois de a despesa existir -->
    <div class="md-fatura" id="md-fatura-cx" style="display:none">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <label style="margin:0">Fatura / recibo</label>
        <span class="dica" style="margin:0">foto ou PDF · até 8 MB</span>
      </div>
      <div id="md-fatura" style="margin-top:.5rem"></div>
      <input type="file" id="md-fatura-input" accept="image/*,application/pdf" style="display:none">
    </div>

    <div id="md-parcelas-cx" style="display:none;border-top:1px solid var(--line);margin-top:.6rem;padding-top:.9rem">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <label style="margin:0">Parcelas</label>
        <button class="mini" type="button" onclick="abrirParcela()">+ Parcela</button>
      </div>
      <div id="md-parcelas" style="margin-top:.5rem"></div>
    </div>
    <div class="fim">
      <button class="btn btn-fantasma" onclick="fechar('m-desp')">Fechar</button>
      <button class="btn btn-ouro" onclick="guardarDespesa()">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal parcela -->
<div class="modal-fundo" id="m-pag">
  <div class="modal">
    <h3 id="m-pag-titulo">Parcela</h3>
    <input type="hidden" id="mp-id">
    <input type="hidden" id="mp-despesa">
    <div class="lin2">
      <div class="campo">
        <label for="mp-valor">Valor</label>
        <input type="text" id="mp-valor" class="campo-moeda" inputmode="decimal" placeholder="0,00">
      </div>
      <div class="campo">
        <label for="mp-data">Vence a</label>
        <input type="date" id="mp-data">
      </div>
    </div>
    <div class="lin2">
      <div class="campo">
        <label for="mp-pago">Pago a (se já pago)</label>
        <input type="date" id="mp-pago">
      </div>
      <div class="campo">
        <label for="mp-nota">Nota</label>
        <input type="text" id="mp-nota" maxlength="160" placeholder="opcional">
      </div>
    </div>
    <div class="fim">
      <button class="btn btn-fantasma" onclick="fechar('m-pag')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardarParcela()">Guardar</button>
    </div>
  </div>
</div>

<div id="lightbox"></div>
<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script src="<?= asset('assets/orcamento.js') ?>"></script>
</body>
</html>
