<?php
// ============================================================
// mesas.php — Planta de mesas (posição, capacidade e ocupação)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mesas · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .layout{ display:grid; grid-template-columns:1fr 380px; gap:1.1rem; align-items:start; }
  @media (max-width:900px){ .layout{ grid-template-columns:1fr; } }

  /* Estatísticas */
  .stats-mesa{ display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.7rem; margin-bottom:1.1rem; }
  .sm{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:.8rem .7rem; text-align:center; }
  .sm .n{ font-family:var(--serif); font-size:1.7rem; font-weight:700; color:var(--ink); line-height:1; }
  .sm .l{ font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#8a8f88; margin-top:.25rem; }
  .sm.ok .n{ color:#1f7a3d; } .sm.alerta .n{ color:var(--danger); }

  /* Barra de adicionar mesa (acima do canvas, campos in-line) */
  .barra-add{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:.7rem .9rem; margin-bottom:1.1rem;
    display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; }
  .barra-add input[type=text]{ flex:1 1 170px; min-width:0; }
  .barra-add input[type=number]{ flex:0 1 90px; min-width:0; }
  .barra-add .grp{ display:flex; align-items:center; gap:.4rem; }
  .barra-add .grp .lbl{ font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; color:#9aa09a; }

  /* Seletores de forma e cor (partilhados por adicionar e editar) */
  .formas{ display:inline-flex; gap:.25rem; flex-wrap:wrap; }
  .formas button{ border:1.5px solid var(--line); background:#fff; border-radius:9px; cursor:pointer;
    width:34px; height:32px; display:inline-flex; align-items:center; justify-content:center; padding:0; }
  .formas button.on{ border-color:var(--forest); background:var(--cream); }
  .fsw{ display:inline-block; background:var(--gold-soft); }
  .fsw-redonda{ width:15px; height:15px; border-radius:50%; }
  .fsw-oval{ width:20px; height:12px; border-radius:50%; }
  .fsw-quadrada{ width:14px; height:14px; border-radius:3px; }
  .fsw-retangular{ width:20px; height:11px; border-radius:3px; }
  .fsw-comprida{ width:24px; height:8px; border-radius:3px; }
  .fsw-ferradura{ width:18px; height:14px; border-radius:6px 6px 0 0; clip-path:polygon(0 0,32% 0,32% 58%,68% 58%,68% 0,100% 0,100% 100%,0 100%); }
  .cores{ display:inline-flex; gap:.3rem; flex-wrap:wrap; }
  .cores button{ width:26px; height:26px; padding:0; border-radius:50%; border:1px solid var(--line); background:#fff; cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center; }
  .cores button.on{ box-shadow:0 0 0 2px var(--forest); border-color:var(--forest); }
  .csw{ display:block; width:18px; height:18px; border-radius:50%; border:1px solid rgba(0,0,0,.12); }
  .csw-neutra{ background:var(--cream); } .csw-verde{ background:#2C4536; } .csw-ouro{ background:#B4864A; }
  .csw-terracota{ background:#b5673f; } .csw-azul{ background:#4a6b7a; } .csw-ameixa{ background:#7a4a6b; }
  .csw-rosa{ background:#b56b78; } .csw-salva{ background:#6b7a53; }

  /* Planta */
  .planta-cartao{ background:#fff; border:1px solid var(--line); border-radius:16px; padding:1rem; }
  .planta-topo{ display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:.8rem; }
  .planta-topo .titulo{ font-family:var(--serif); font-size:1.2rem; color:var(--ink); font-weight:600; flex:1; }
  .legenda{ display:flex; gap:.8rem; flex-wrap:wrap; font-size:.76rem; color:#7a8078; }
  .legenda i{ width:10px; height:10px; border-radius:50%; display:inline-block; vertical-align:-1px; margin-right:.3rem; }
  .lg-vazia i{ background:#b7bbb5; } .lg-parcial i{ background:var(--gold); }
  .lg-cheia i{ background:#1f7a3d; } .lg-excede i{ background:var(--danger); }

  /* Canvas de tamanho FIXO (a moldura não muda com o zoom): é a janela de scroll.
     O tamanho é definido pelo utilizador (arrastar as bordas) e guardado na BD.
     O zoom amplia o "mundo" (.planta) dentro dela, sem alterar o tamanho do canvas. */
  .canvas-wrap{ position:relative; display:inline-block; max-width:100%; }
  .planta-viewport{ position:relative; box-sizing:border-box; overflow:auto; width:100%; height:56vh; --z:1;
    border-radius:14px; border:1px dashed var(--gold-soft); background:var(--ivory); }
  .planta{ position:relative; width:calc(max(1, var(--z))*100%); height:calc(max(1, var(--z))*100%);
    border-radius:14px; transition:width .18s ease, height .18s ease; touch-action:none; user-select:none;
    background:
      linear-gradient(var(--ivory),var(--ivory)),
      repeating-linear-gradient(0deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px),
      repeating-linear-gradient(90deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px);
    background-clip:padding-box; }

  /* Pegas de redimensionamento do canvas (bordas) */
  .rz{ position:absolute; z-index:6; background:transparent; touch-action:none; }
  .rz-e{ top:0; right:-3px; width:12px; height:100%; cursor:ew-resize; }
  .rz-s{ left:0; bottom:-3px; height:12px; width:100%; cursor:ns-resize; }
  .rz-se{ right:-4px; bottom:-4px; width:20px; height:20px; cursor:nwse-resize; }
  .rz-se::after{ content:""; position:absolute; right:3px; bottom:3px; width:11px; height:11px;
    border-right:2px solid var(--gold); border-bottom:2px solid var(--gold);
    border-bottom-right-radius:4px; opacity:.65; }
  .rz-e:hover, .rz-s:hover{ background:rgba(180,134,74,.14); }
  .rz-e:hover{ border-right:2px solid var(--gold); }
  .rz-s:hover{ border-bottom:2px solid var(--gold); }
  body.a-redimensionar{ cursor:nwse-resize; user-select:none; }

  /* Barra de zoom + botão de maximizar */
  .planta-ctrls{ display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
  .icon-btn{ border:1px solid var(--line); background:#fff; color:var(--text); border-radius:10px;
    width:34px; height:32px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; padding:0; }
  .icon-btn:hover{ border-color:var(--gold-soft); color:var(--forest); }
  .icon-btn .i-comp{ display:none; }
  body.mesas-max .icon-btn .i-exp{ display:none; }
  body.mesas-max .icon-btn .i-comp{ display:inline-flex; }

  /* Modo maximizado: a planta ocupa o ecrã, mantendo o conjunto de abas ao lado */
  body.mesas-max{ overflow:hidden; }
  body.mesas-max .container{ position:fixed; inset:0; z-index:1000; max-width:none; width:100%; margin:0;
    padding:1rem 1.2rem; overflow:hidden;
    background:linear-gradient(160deg,var(--ivory) 0%, var(--cream) 100%); }
  body.mesas-max .topo, body.mesas-max .stats-mesa, body.mesas-max .barra-add{ display:none; }
  body.mesas-max .rz{ display:none; }
  body.mesas-max .painel-mesas{ position:static; }
  body.mesas-max .tab-body{ max-height:calc(100vh - 9rem); }
  /* Travas contra arrastos acidentais (ficam à esquerda do zoom) */
  .bloqueios{ display:inline-flex; gap:.7rem; align-items:center; margin-right:.2rem; }
  .bloqueios label{ display:inline-flex; align-items:center; gap:.3rem; font-size:.78rem;
                    color:#7a8078; cursor:pointer; white-space:nowrap; }
  .bloqueios input{ width:15px; height:15px; accent-color:var(--gold); cursor:pointer; }
  .bloqueios label:has(input:checked){ color:var(--forest); font-weight:500; }
  /* Com as mesas fixas, o cursor deixa de sugerir arrasto */
  body.bloq-mesas .mesa-node{ cursor:pointer; }
  /* Com o canvas fixo, as pegas de redimensionar desaparecem */
  body.bloq-canvas .rz{ display:none; }

  .zoombar{ display:inline-flex; border:1px solid var(--line); border-radius:50px; overflow:hidden; }
  .zoombar button{ border:0; background:#fff; color:var(--text); font-family:inherit; font-size:.78rem;
    padding:.32rem .6rem; cursor:pointer; line-height:1.1; border-left:1px solid var(--line); }
  .zoombar button:first-child{ border-left:0; }
  .zoombar button.on{ background:var(--forest); color:#fff; }
  .planta .dica-vazia{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    color:#a7ad9f; font-size:.9rem; text-align:center; padding:1rem; }

  /* Linhas-guia magnéticas */
  .guia{ position:absolute; background:var(--gold); opacity:0; pointer-events:none; z-index:19; transition:opacity .08s; }
  .guia.gv{ top:-4px; bottom:-4px; width:1.5px; transform:translateX(-50%); }
  .guia.gh{ left:-4px; right:-4px; height:1.5px; transform:translateY(-50%); }
  .guia.on{ opacity:.75; }

  .mesa-node{ position:absolute; --d:calc(var(--dbase,80px)*var(--z,1)); transform:translate(-50%,-50%); cursor:grab;
    display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
    border:3px solid var(--gold-soft); background:var(--cream); color:var(--ink);
    box-shadow:0 4px 12px rgba(22,38,30,.12); transition:box-shadow .15s; }
  /* Formas */
  .forma-redonda{ width:var(--d); height:var(--d); border-radius:50%; }
  .forma-oval{ width:calc(var(--d)*1.5); height:calc(var(--d)*.78); border-radius:50%; }
  .forma-quadrada{ width:var(--d); height:var(--d); border-radius:14px; }
  .forma-retangular{ width:calc(var(--d)*1.5); height:calc(var(--d)*.72); border-radius:12px; }
  .forma-comprida{ width:calc(var(--d)*2.1); height:calc(var(--d)*.5); border-radius:10px; }
  .forma-ferradura{ width:calc(var(--d)*1.6); height:calc(var(--d)*1.05); border-radius:16px 16px 8px 8px;
    clip-path:polygon(0 0,30% 0,30% 42%,70% 42%,70% 0,100% 0,100% 100%,0 100%);
    justify-content:flex-end; padding-bottom:calc(var(--d)*0.14); }
  /* Mesa dos noivos — ilustração simbólica (alianças entrelaçadas), compacta */
  .forma-noivos{ width:calc(var(--d)*1.08); height:calc(var(--d)*1.0); border-radius:16px;
    justify-content:flex-end; padding-bottom:calc(var(--d)*0.12); }
  .cor-noivos, .forma-noivos{ background:linear-gradient(150deg,#faf1db,#f0dcae);
    border-color:#B4864A; color:#5c4321; box-shadow:0 6px 20px rgba(180,134,74,.38); }
  .noivos-rings{ position:absolute; top:calc(var(--d)*0.13); left:50%; transform:translateX(-50%); display:flex; }
  .noivos-rings i{ width:calc(var(--d)*0.32); height:calc(var(--d)*0.32); border-radius:50%;
    border:calc(var(--d)*0.058) solid #B4864A; background:transparent; box-sizing:border-box; }
  .noivos-rings i:last-child{ margin-left:calc(var(--d)*-0.13); border-color:#d0a75f; }

  /* Alas de padrinhos (esq.) e madrinhas (dir.) junto à mesa dos noivos */
  .noivos-ala{ position:absolute; --d:calc(var(--dbase,80px)*var(--z,1)); z-index:16;
    display:flex; flex-direction:column; gap:calc(var(--d)*0.06); width:calc(var(--d)*1.0); }
  .noivos-ala.esq{ transform:translate(calc(-100% - var(--d)*0.98), -50%); }
  .noivos-ala.dir{ transform:translate(calc(var(--d)*0.98), -50%); }
  .noivos-ala .ala-tit{ font-size:calc(var(--d)*0.13); text-transform:uppercase; letter-spacing:.5px;
    color:#8a6a2f; text-align:center; font-weight:700; }
  .noivos-ala .ala-p{ background:#fff; border:1px solid #d8c193; border-radius:50px;
    font-size:calc(var(--d)*0.14); padding:calc(var(--d)*0.05) calc(var(--d)*0.15); line-height:1.15;
    text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#5c4321;
    user-select:none; box-shadow:0 2px 6px rgba(180,134,74,.18); }
  /* Cores (paleta) */
  .cor-neutra{ background:var(--cream); border-color:var(--gold-soft); color:var(--ink); }
  .cor-verde{ background:#e4f0e8; border-color:#2C4536; color:#17311f; }
  .cor-ouro{ background:#f6ecd6; border-color:#B4864A; color:#5c4321; }
  .cor-terracota{ background:#f5e2d9; border-color:#b5673f; color:#6e3a25; }
  .cor-azul{ background:#dfe9ed; border-color:#4a6b7a; color:#2e4650; }
  .cor-ameixa{ background:#ece1ea; border-color:#7a4a6b; color:#4e2f45; }
  .cor-rosa{ background:#f5e3e6; border-color:#b56b78; color:#6e3844; }
  .cor-salva{ background:#e8ede1; border-color:#6b7a53; color:#3f4a30; }

  .mesa-node.a-arrastar{ cursor:grabbing; box-shadow:0 10px 26px rgba(22,38,30,.28); z-index:20; }
  .mesa-node.sel{ outline:3px solid var(--forest); outline-offset:3px; z-index:15; }
  .mesa-node .mn-nome{ font-family:var(--serif); font-weight:600; font-size:calc(var(--d)*0.165); line-height:1.05; flex:none;
    max-width:92%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .mesa-node .mn-ocup{ font-size:calc(var(--d)*0.15); margin-top:.1rem; font-variant-numeric:tabular-nums; flex:none; }
  .mesa-node .mn-dot{ position:absolute; top:6px; right:8px; width:9px; height:9px; border-radius:50%; box-shadow:0 0 0 2px rgba(255,255,255,.75); }
  .dot-vazia{ background:#b7bbb5; } .dot-parcial{ background:var(--gold); } .dot-cheia{ background:#1f7a3d; } .dot-excede{ background:var(--danger); }
  .mesa-node.drop-alvo{ outline:3px dashed var(--gold); outline-offset:4px; box-shadow:0 0 0 6px rgba(180,134,74,.18); z-index:18; }

  /* Pastilhas de pessoas (mesa selecionada) — arrastáveis entre mesas */
  .mesa-membros{ position:absolute; --d:calc(var(--dbase,80px)*var(--z,1)); transform:translate(-50%, calc(var(--d)/2 + 8px)); z-index:17;
    display:flex; flex-wrap:wrap; gap:.25rem; justify-content:center; width:calc(var(--d)*2.1); pointer-events:none; }
  .mesa-membros.acima{ transform:translate(-50%, calc(-100% - var(--d)/2 - 8px)); }
  .mesa-membros .mp{ pointer-events:auto; background:var(--forest); color:#fff; font-size:calc(var(--d)*0.145); line-height:1.1;
    padding:.18rem .55rem; border-radius:50px; cursor:grab; touch-action:none; user-select:none; white-space:nowrap;
    box-shadow:0 2px 6px rgba(22,38,30,.25); max-width:calc(var(--d)*1.6); overflow:hidden; text-overflow:ellipsis; }
  .mesa-membros .mp:hover{ background:var(--forest-deep); }
  body.a-arrastar-item .mp{ pointer-events:none; }

  /* Painel direito: conjunto de abas */
  .painel-mesas{ display:flex; flex-direction:column; gap:1rem; position:sticky; top:1rem; }
  .tabset{ background:#fff; border:1px solid var(--line); border-radius:16px; padding:.8rem .9rem 1rem; display:flex; flex-direction:column; min-height:0; }
  .tabset-tabs{ display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .tabset-tabs .rt{ border:1px solid var(--line); background:#fff; color:var(--text); font-family:inherit; font-size:.85rem;
    padding:.4rem .7rem; border-radius:50px; cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; }
  .tabset-tabs .rt.on{ background:var(--forest); color:#fff; border-color:var(--forest); }
  .tabset-tabs .rt-n{ background:var(--cream); color:var(--forest); border-radius:50px; padding:0 .4rem; font-size:.72rem; min-width:1.1rem; text-align:center; }
  .tabset-tabs .rt.on .rt-n{ background:rgba(255,255,255,.22); color:#fff; }
  .tabset-tabs .rt-mesa{ border-color:var(--gold-soft); }
  .tab-body{ overflow:auto; max-height:60vh; }
  @media (max-width:900px){ .tab-body{ max-height:none; } .painel-mesas{ position:static; } }

  .filtros-tab{ display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:.7rem; }
  .filtros-tab input[type=search]{ flex:1; min-width:130px; }
  .filtros-tab select{ min-width:130px; }
  .lista-tab{ display:flex; flex-wrap:wrap; gap:.5rem; }
  .roster-conta{ font-size:.76rem; color:#9aa09a; margin-top:.6rem; }
  .chip-drag{ display:inline-flex; flex-direction:column; gap:.05rem; background:var(--cream); border:1px solid var(--line);
    border-radius:12px; padding:.45rem .7rem; cursor:grab; touch-action:none; user-select:none; max-width:100%; }
  .chip-drag:hover{ border-color:var(--gold-soft); }
  .chip-drag.arrastando{ opacity:.4; }
  .chip-drag .cd-nome{ font-size:.9rem; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:230px; }
  .chip-drag .cd-meta{ font-size:.72rem; color:#8a8f88; }
  .chip-drag.sem-mesa{ border-style:dashed; border-color:var(--gold-soft); background:#fff; }
  .roster-vazio{ color:#9aa09a; font-size:.86rem; padding:.4rem 0; }
  .ghost-drag{ position:fixed; z-index:1000; transform:translate(-50%,-50%); pointer-events:none;
    background:var(--forest); color:#fff; font-size:.85rem; padding:.4rem .7rem; border-radius:10px;
    box-shadow:0 10px 26px rgba(0,0,0,.3); white-space:nowrap; }

  /* Formulário de edição (compacto, in-line) */
  .mesa-form{ display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
  .mesa-form input[type=text]{ flex:1 1 120px; min-width:0; }
  .mesa-form input[type=number]{ flex:0 1 80px; min-width:0; }
  .barra-ocup{ height:8px; background:var(--cream); border-radius:50px; overflow:hidden; margin:.5rem 0; }
  .barra-ocup span{ display:block; height:100%; background:var(--gold); }
  .barra-ocup span.cheio{ background:#1f7a3d; } .barra-ocup span.excede{ background:var(--danger); }
  .lista-sentados{ display:flex; flex-direction:column; gap:.4rem; margin:.4rem 0 .2rem; }
  .sentado{ display:flex; align-items:center; gap:.5rem; border:1px solid var(--line); border-radius:10px; padding:.4rem .6rem; font-size:.9rem; }
  .sentado .nm{ flex:1; line-height:1.2; }
  .sel-mini{ flex:none; max-width:52%; font-size:.8rem; padding:.35rem .4rem; }
  .vazio-mini{ color:#9aa09a; font-size:.86rem; padding:.3rem 0; }
  .acoes-bloco{ display:flex; gap:.5rem; margin-top:.8rem; }
  .semmesa-chip{ display:inline-flex; align-items:center; gap:.35rem; background:var(--cream); border:1px solid var(--line);
    border-radius:50px; padding:.25rem .6rem; font-size:.8rem; margin:.15rem .2rem 0 0; }
  .rot{ font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#9aa09a; margin:.9rem 0 .3rem; }

  /* Ícones de género / brinde nas pastilhas com nomes */
  .gi{ font-weight:700; line-height:1; }
  .gi-m{ color:#4a6b7a; } .gi-f{ color:#b56b78; } .gi-b{ font-weight:400; }
  .mp .gi-m, .mp .gi-f{ color:#eef3ee; } /* pastilhas verdes: ícone claro (a forma ♂/♀ distingue) */

  /* Dropdown de pesquisa (substitui os <select> de listas longas) */
  .combo{ position:relative; display:block; width:100%; }
  .combo-btn{ width:100%; display:flex; align-items:center; gap:.4rem; text-align:left; cursor:pointer;
    border:1px solid var(--line); background:#fff; color:var(--text); font-family:inherit; font-size:.9rem;
    padding:.5rem .6rem; border-radius:10px; line-height:1.2; }
  .combo-btn:hover{ border-color:var(--gold-soft); }
  .combo-btn .combo-txt{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .combo-btn .combo-cx{ margin-left:auto; color:#9aa09a; font-size:.7rem; flex:none; }
  .combo.combo-inline{ flex:none; max-width:56%; min-width:120px; }
  .combo.combo-inline .combo-btn{ font-size:.8rem; padding:.35rem .45rem; }
  .combo-pop{ position:fixed; z-index:1200; background:#fff; border:1px solid var(--line); border-radius:12px;
    box-shadow:0 12px 30px rgba(22,38,30,.20); padding:.4rem; min-width:220px; }
  .combo-pop[hidden]{ display:none; }
  .combo-search{ width:100%; margin-bottom:.35rem; box-sizing:border-box; }
  .combo-list{ max-height:244px; overflow:auto; display:flex; flex-direction:column; gap:.1rem; }
  .combo-opt{ text-align:left; border:0; background:transparent; font-family:inherit; font-size:.86rem; color:var(--ink);
    padding:.4rem .5rem; border-radius:8px; cursor:pointer; display:flex; flex-direction:column; gap:.05rem; width:100%; }
  .combo-opt:hover, .combo-opt.ativo{ background:var(--cream); }
  .combo-opt .combo-sub{ font-size:.72rem; color:#8a8f88; }
  .combo-vazio{ color:#9aa09a; font-size:.82rem; padding:.4rem .5rem; }
</style>
<script src="<?= asset('assets/api.js') ?>"></script>
</head>
<body>
<?php cabecalho('Planta de Mesas', $CAS['casal'].' · posição, capacidade e ocupação', 'mesas'); ?>

<div class="container">
  <div class="stats-mesa" id="stats"></div>

  <!-- ADICIONAR MESA (acima do canvas) -->
  <div class="barra-add">
    <input type="text" id="nova-nome" placeholder="Nova mesa (nome)">
    <input type="number" id="nova-cap" min="1" placeholder="Lugares">
    <div class="grp"><span class="lbl">Forma</span><div class="formas" id="nova-forma"></div></div>
    <div class="grp"><span class="lbl">Cor</span><div class="cores" id="nova-cor"></div></div>
    <button class="btn btn-ouro btn-sm" onclick="adicionarMesa()">+ Mesa</button>
    <button class="btn btn-fantasma btn-sm" id="btn-noivos" style="display:none" onclick="adicionarNoivos()" title="Repor a mesa de honra dos noivos">⚭ Mesa dos noivos</button>
  </div>

  <div class="layout">
    <!-- PLANTA -->
    <div class="planta-cartao">
      <div class="planta-topo">
        <span class="titulo">Disposição do salão</span>
        <div class="planta-ctrls">
          <div class="bloqueios">
            <label title="Impede arrastar as mesas (continua a poder selecioná-las)">
              <input type="checkbox" id="bloq-mesas" onchange="guardarBloqueio()"> Fixar mesas
            </label>
            <label title="Impede redimensionar o canvas pelas bordas">
              <input type="checkbox" id="bloq-canvas" onchange="guardarBloqueio()"> Fixar canvas
            </label>
          </div>
          <div class="zoombar" id="zoombar" title="Nível de zoom">
            <button data-zoom="0.5" title="50% · vista ampla">50%</button>
            <button data-zoom="1" class="on" title="100% · vista panorâmica (canvas completo)">100%</button>
            <button data-zoom="1.5" title="150% · vista de área">150%</button>
          </div>
          <button class="icon-btn" id="btn-max" onclick="toggleMax()" title="Maximizar a planta" aria-label="Maximizar a planta">
            <svg class="i-exp" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
            <svg class="i-comp" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>
          </button>
        </div>
      </div>
      <div class="legenda" style="margin-bottom:.7rem">
        <span class="lg-vazia"><i></i>Vazia</span>
        <span class="lg-parcial"><i></i>A encher</span>
        <span class="lg-cheia"><i></i>Completa</span>
        <span class="lg-excede"><i></i>Excede</span>
      </div>
      <div class="canvas-wrap" id="canvas-wrap">
        <div class="planta-viewport" id="planta-viewport">
          <div class="planta" id="planta">
            <div class="guia gv" id="guia-v"></div>
            <div class="guia gh" id="guia-h"></div>
            <div class="dica-vazia" id="dica-vazia">Ainda não há mesas. Crie a primeira acima e arraste-a para a posição.</div>
          </div>
        </div>
        <div class="rz rz-e"  data-dir="e"  title="Arraste para ajustar a largura do canvas"></div>
        <div class="rz rz-s"  data-dir="s"  title="Arraste para ajustar a altura do canvas"></div>
        <div class="rz rz-se" data-dir="se" title="Arraste para redimensionar o canvas"></div>
      </div>
      <p id="dica-planta" style="font-size:.78rem;color:#9aa09a;margin:.6rem 0 0"></p>
    </div>

    <!-- PAINEL DIREITO -->
    <div class="painel-mesas">
      <div class="tabset">
        <div class="tabset-tabs" id="tabset-tabs"></div>
        <div class="tab-body" id="tab-body"></div>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
</script>
<script src="<?= asset('assets/mesas.js') ?>"></script>
</body>
</html>
