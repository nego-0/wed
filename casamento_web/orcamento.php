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
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem 1.3rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:1rem; line-height:1.5; }

  /* ---- Retrato do curso ---- */
  .curso-topo{ display:flex; flex-wrap:wrap; gap:1rem 2rem; align-items:flex-end; justify-content:space-between; }
  .curso-num{ font-family:var(--serif); font-size:2.1rem; font-weight:700; color:var(--ink); line-height:1; }
  .curso-num small{ font-size:1rem; color:#8a8f88; font-weight:400; }
  .curso-l{ font-size:.72rem; text-transform:uppercase; letter-spacing:1px; color:#8a8f88; margin-top:.35rem; }
  .curso-falta{ text-align:right; }
  .curso-falta .v{ font-family:var(--serif); font-size:1.5rem; font-weight:700; }
  .curso-falta.ok .v{ color:var(--ok); } .curso-falta.mau .v{ color:var(--danger); }

  .barra{ height:34px; border-radius:9px; overflow:hidden; display:flex; margin-top:1rem;
          border:1px solid var(--line); background:var(--cream); }
  .barra span{ display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:600;
               color:#fff; letter-spacing:.02em; white-space:nowrap; overflow:hidden; min-width:0; }
  .s-pago{ background:var(--ok); } .s-contr{ background:var(--gold); }
  .s-prev{ background:var(--gold-pale); color:var(--forest); }
  .legenda{ display:flex; flex-wrap:wrap; gap:1rem; margin-top:.8rem; font-size:.8rem; color:#8a8f88; }
  .legenda i{ width:11px; height:11px; border-radius:3px; display:inline-block; margin-right:.4rem; vertical-align:middle; }

  /* ---- Ajustes (teto / moeda) ---- */
  .ajustes{ display:grid; grid-template-columns:1fr 160px auto; gap:.7rem; align-items:end; }
  @media(max-width:640px){ .ajustes{ grid-template-columns:1fr; } }

  /* ---- Categorias ---- */
  .cat{ border-top:1px solid var(--line); padding:.75rem 0; }
  .cat:first-of-type{ border-top:0; }
  .cat-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.8rem; }
  .cat-nome{ font-size:.98rem; color:var(--ink); font-weight:600; }
  .cat-vals{ font-size:.82rem; color:#8a8f88; white-space:nowrap; }
  .cat-vals b{ color:var(--ink); font-weight:600; }
  .cat-barra{ height:8px; border-radius:5px; background:var(--cream); margin-top:.45rem; overflow:hidden; }
  .cat-barra > i{ display:block; height:100%; background:var(--gold); }
  .cat-barra.acima > i{ background:var(--danger); }
  .cat-ac{ display:flex; gap:.5rem; margin-top:.4rem; }
  .mini{ font-size:.75rem; color:#8a8f88; background:transparent; border:1px solid var(--line);
         border-radius:50px; padding:.12rem .55rem; cursor:pointer; }
  .mini:hover{ background:var(--cream); color:var(--ink); }
  .mini.perigo:hover{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }

  /* ---- Despesas ---- */
  table.desp{ width:100%; border-collapse:collapse; }
  table.desp th{ text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.06em;
                 color:#8a8f88; font-weight:600; padding:.4rem .5rem; border-bottom:1px solid var(--line); }
  table.desp td{ padding:.55rem .5rem; border-bottom:1px solid var(--line); font-size:.9rem; vertical-align:top; }
  table.desp tr:last-child td{ border-bottom:0; }
  .desp .d-nome{ color:var(--ink); font-weight:500; }
  .desp .d-forn{ font-size:.78rem; color:#8a8f88; }
  .desp .d-val{ text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; color:var(--ink); }
  .desp .d-ac{ text-align:right; white-space:nowrap; }
  .est{ font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; border-radius:50px;
        padding:.1rem .55rem; border:1px solid var(--line); white-space:nowrap; }
  .est.previsto{ background:var(--gold-pale); color:var(--forest); border-color:var(--gold-soft); }
  .est.contratado{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .est.pago{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }

  /* ---- Calendário de pagamentos ---- */
  .pag{ display:grid; grid-template-columns:auto 1fr auto auto; gap:.7rem; align-items:center;
        padding:.55rem 0; border-top:1px solid var(--line); }
  .pag:first-of-type{ border-top:0; }
  .pag .data{ font-variant-numeric:tabular-nums; font-size:.85rem; color:var(--ink); white-space:nowrap; }
  .pag .data.venceu{ color:var(--danger); font-weight:600; }
  .pag .data.pago{ color:var(--ok); }
  .pag .desc{ font-size:.88rem; color:var(--ink); min-width:0; }
  .pag .desc small{ display:block; color:#8a8f88; font-size:.76rem; }
  .pag .mt{ font-variant-numeric:tabular-nums; font-size:.88rem; color:var(--ink); white-space:nowrap; text-align:right; }

  .vazio{ text-align:center; padding:2rem 1rem; color:#9aa09a; font-size:.9rem; }
  .modal-fundo{ position:fixed; inset:0; background:rgba(22,38,30,.45); display:none;
                align-items:center; justify-content:center; padding:1rem; z-index:60; }
  .modal-fundo.aberto{ display:flex; }
  .modal{ background:#fff; border-radius:16px; padding:1.4rem 1.5rem; max-width:520px; width:100%;
          max-height:90vh; overflow:auto; box-shadow:0 20px 60px rgba(22,38,30,.3); }
  .modal h3{ margin:0 0 1rem; }
  .modal .campo{ margin-bottom:.9rem; }
  .modal .lin2{ display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
  .modal .fim{ display:flex; gap:.6rem; justify-content:flex-end; margin-top:1.2rem; }
  .aviso-visita{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
                 border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1.2rem; line-height:1.5; }
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

  <!-- Retrato do curso -->
  <div class="painel">
    <div class="curso-topo">
      <div>
        <div class="curso-num" id="c-comprometido">—</div>
        <div class="curso-l">Comprometido (contratado + pago)</div>
      </div>
      <div class="curso-falta" id="c-falta-cx">
        <div class="v" id="c-falta">—</div>
        <div class="curso-l" id="c-falta-l">Margem até ao teto</div>
      </div>
    </div>
    <div class="barra" id="c-barra"></div>
    <div class="legenda">
      <span><i class="s-pago"></i>Pago</span>
      <span><i class="s-contr"></i>Contratado</span>
      <span><i class="s-prev"></i>Previsto</span>
      <span><i style="background:var(--cream);border:1px solid var(--line)"></i>Folga até ao teto</span>
    </div>
  </div>

  <!-- Teto e moeda -->
  <div class="painel">
    <h3>Teto e moeda</h3>
    <p class="dica">O teto é opcional: sem ele, a barra mede-se pela soma dos previstos das categorias.</p>
    <div class="ajustes">
      <div class="campo">
        <label for="a-total">Orçamento total (teto)</label>
        <input type="text" id="a-total" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00">
      </div>
      <div class="campo">
        <label for="a-moeda">Moeda</label>
        <input type="text" id="a-moeda" maxlength="8" placeholder="Kz">
      </div>
      <button class="btn btn-ouro" id="a-guardar" onclick="guardarAjustes()">Guardar</button>
    </div>
  </div>

  <!-- Categorias -->
  <div class="painel">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem">
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
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem">
      <div>
        <h3>Despesas</h3>
        <p class="dica" style="margin-bottom:0">Cada compromisso, com o seu estado: previsto, contratado ou pago.</p>
      </div>
      <button class="btn btn-ouro" onclick="abrirDespesa()">+ Despesa</button>
    </div>
    <div id="lista-despesas" style="margin-top:1rem"></div>
  </div>

  <!-- Pagamentos (o curso no tempo) -->
  <div class="painel">
    <h3>Calendário de pagamentos</h3>
    <p class="dica">Os sinais e prestações, por data. O que é preciso ter em caixa, e quando. Abra uma despesa para lhe juntar parcelas.</p>
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

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script>window.SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/orcamento.js') ?>"></script>
</body>
</html>
