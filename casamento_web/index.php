<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);
$dataExt = dataExtensa($DEFS['evento.data']);
$temListaAntiga = listaAntigaExiste($conn);
$totalConvites  = (int)$conn->query("SELECT COUNT(*) FROM {$P}convites c WHERE ".soVivos($conn,'c')."")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  .barra-acoes{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; }
  .barra-acoes .cresce{ flex:1 1 200px; }
  .banner-import{ background:linear-gradient(135deg,var(--gold-pale),var(--sand)); border:1px solid var(--gold-soft);
    border-radius:14px; padding:1rem 1.25rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1.25rem; }
  .banner-import .txt{ flex:1 1 260px; }
  .banner-import h4{ margin-bottom:.2rem; }
  .banner-import p{ margin:0; font-size:.86rem; color:var(--text); }
  .membro-linha{ display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; flex-wrap:wrap; }
  .membro-linha input[type=text]{ flex:1 1 160px; min-width:0; }
  .membro-linha .m-mesa{ flex:0 1 auto; max-width:34%; font-size:.85rem; padding:.45rem .5rem; }
  .membro-linha .m-papel{ flex:0 1 auto; max-width:30%; font-size:.85rem; padding:.45rem .5rem; }
  .membro-linha .m-genero{ flex:0 1 auto; max-width:26%; font-size:.85rem; padding:.45rem .5rem; }
  .membro-linha .m-brinde{ display:inline-flex; align-items:center; gap:.25rem; font-size:.82rem; color:var(--text); white-space:nowrap; cursor:pointer; }
  .membro-linha .m-brinde input{ width:16px; height:16px; accent-color:var(--gold); cursor:pointer; }
  /* Ícones de género / brinde nas pastilhas */
  /* ♂ e ♀ são glifos finos: à medida do texto à volta ficavam quase invisíveis.
     Um pouco maiores, mais escuros e com uma fonte que os desenha bem. */
  .gi{ font-weight:700; line-height:1; font-size:1.15em; vertical-align:-.05em;
       font-family:'Segoe UI Symbol','Noto Sans Symbols 2','Noto Sans Symbols',
                   'DejaVu Sans',system-ui,sans-serif; }
  .gi-m{ color:#2f5568; } .gi-f{ color:#9c4256; } .gi-b{ font-weight:400; font-size:1em; }
  .sugestoes{ display:flex; gap:.4rem; flex-wrap:wrap; margin:.4rem 0 .2rem; }
  .sugestao{ background:var(--cream); border:1px solid var(--line); border-radius:50px; padding:.25rem .7rem; font-size:.8rem; cursor:pointer; }
  .sugestao:hover{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .previa{ background:var(--forest-deep); color:var(--gold-pale); font-family:var(--serif); font-size:1.15rem; padding:.7rem 1rem; border-radius:10px; text-align:center; margin:.3rem 0 1rem; }
  .link-box{ display:flex; gap:.4rem; align-items:center; background:var(--cream); border:1px solid var(--line); border-radius:10px; padding:.4rem .4rem .4rem .8rem; font-size:.82rem; }
  .link-box input{ border:none; background:transparent; padding:.2rem 0; font-size:.82rem; }
  .link-box input:focus{ box-shadow:none; }
  .qr-holder{ text-align:center; padding:1rem; }
  .qr-holder canvas{ border:8px solid #fff; border-radius:10px; box-shadow:var(--shadow); }
  .mesa-item{ display:flex; align-items:center; gap:.6rem; padding:.6rem .2rem; border-bottom:1px solid var(--line); }
  .mesa-item .info{ flex:1; }
  .mesa-item .ocup{ font-size:.78rem; color:#8a8f88; }
  .barra-ocup{ height:6px; background:var(--cream); border-radius:50px; overflow:hidden; margin-top:.3rem; }
  .barra-ocup span{ display:block; height:100%; background:var(--gold); }
  .barra-ocup span.cheio{ background:var(--danger); }
  svg.ic{ width:16px; height:16px; vertical-align:-2px; }

  /* Cartões de filtro com ícones (painel coeso: cada cartão filtra a lista) */
  .grelha-stats{ display:grid; grid-template-columns:repeat(auto-fit,minmax(112px,1fr)); gap:.7rem; }
  .stat-f{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:.85rem .6rem; cursor:pointer;
    display:flex; flex-direction:column; align-items:center; gap:.25rem; text-align:center; transition:.16s;
    font-family:inherit; color:var(--text); }
  .stat-f:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.12); transform:translateY(-2px); }
  .stat-f .si{ display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:50%;
    background:var(--cream); color:var(--forest); margin-bottom:.1rem; }
  .stat-f .si svg{ width:18px; height:18px; }
  .stat-f .sn{ font-family:var(--serif); font-size:1.6rem; font-weight:700; color:var(--ink); line-height:1; }
  .stat-f .sl{ font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#8a8f88; }
  .stat-f.ativo{ border-color:var(--forest); background:var(--forest); }
  .stat-f.ativo .sn{ color:#fff; } .stat-f.ativo .sl{ color:var(--gold-pale); }
  .stat-f.ativo .si{ background:rgba(255,255,255,.15); color:var(--gold-pale); }
  .stat-f.verde .si{ color:#1f7a3d; } .stat-f.ouro .si{ color:var(--gold); } .stat-f.rosa .si{ color:#a5473f; }
  /* Os cartões extra fazem parte da mesma grelha (display:contents) — assim
     alinham com os outros em vez de formarem uma segunda grelha desencontrada. */
  .stats-extra{ display:contents; }
  .btn-stats-mais{ display:none; }
  .esp-stats{ height:0; }   /* respiro entre os cartões e a barra de ações */

  /* Fim da lista: "mostrar mais" e a contagem do que já se vê */
  .btn-mais-lista{ display:flex; flex-direction:column; align-items:center; gap:.15rem; width:100%; margin-top:.4rem;
    background:#fff; border:1px solid var(--line); border-radius:14px; padding:.8rem; cursor:pointer;
    font-family:inherit; font-size:.95rem; color:var(--forest); transition:.16s; }
  .btn-mais-lista:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.12); }
  .btn-mais-lista small{ color:#9aa09a; font-size:.76rem; }
  .btn-mais-lista .conta-extra{ display:inline-block; min-width:18px; padding:0 .35rem; border-radius:50px;
    background:var(--cream); color:#8a8f88; font-size:.78rem; }
  .fim-lista{ text-align:center; color:#b0b4ab; font-size:.8rem; margin:.9rem 0 0; }
  @media (max-width:720px){
    /* Colunas fixas: com auto-fit os 4 cartões de base ficavam 3+1, com um
       cartão órfão na última linha. Assim formam um bloco certinho. */
    .grelha-stats{ grid-template-columns:repeat(2,1fr); gap:.5rem; }
    .stat-f{ padding:.6rem .4rem; }
    .stat-f .si{ width:28px; height:28px; }
    .stat-f .sn{ font-size:1.35rem; }
    .stats-extra:not(.aberto){ display:none; }
    .btn-stats-mais{ display:block; width:100%; margin:.6rem 0 0; background:#fff; border:1px solid var(--line);
      border-radius:12px; padding:.55rem; font-family:inherit; font-size:.85rem; color:var(--forest); cursor:pointer; }
    .btn-stats-mais:hover{ border-color:var(--gold-soft); }
    .conta-extra{ display:inline-block; min-width:18px; padding:0 .3rem; margin-left:.25rem; border-radius:50px;
      background:var(--cream); color:#8a8f88; font-size:.75rem; }
  }
  .stat-f.ativo.verde,.stat-f.ativo.rosa,.stat-f.ativo.ouro{ background:var(--forest); }

  /* Ícones na linha do convite */
  .selo-tipo svg{ width:18px; height:18px; }
  .lado-ic{ display:inline-flex; align-items:center; gap:.25rem; color:var(--gold); }
  .lado-ic svg{ width:15px; height:15px; }
  .stat-f .ss{ font-size:.66rem; color:#b0b4ab; margin-top:.1rem; }
  .stat-f .ss .gi{ font-size:.92rem; }
  .stat-f.ativo .ss{ color:rgba(239,227,203,.7); }
  /* Cartão selecionado (fundo verde): os símbolos ♂/♀ herdam o tom claro. */
  .stat-f.ativo .ss .gi{ color:inherit; }

  /* Barra de progresso de capacidade */
  .progresso-cap{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1rem 1.15rem; }
  .pc-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.6rem; flex-wrap:wrap; margin-bottom:.6rem; }
  .pc-tit{ font-family:var(--serif); font-size:1.1rem; font-weight:600; color:var(--ink); }
  .pc-nums{ font-size:.84rem; color:#7a8078; } .pc-nums b{ color:var(--ink); font-weight:600; } .pc-nums .v-conf{ color:#1f7a3d; }
  .pc-barra{ position:relative; height:14px; background:var(--cream); border-radius:50px; overflow:hidden; }
  .pc-conv{ position:absolute; left:0; top:0; height:100%; background:var(--gold-soft); border-radius:50px; transition:width .5s ease; }
  .pc-conf{ position:absolute; left:0; top:0; height:100%; background:linear-gradient(90deg,var(--forest),#1f7a3d); border-radius:50px; transition:width .5s ease; }

  /* Chips de mesa (filtro por ícones/etiquetas) */
  .chips-mesa{ display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }
  .chips-mesa:empty{ display:none; }
  .chips-lbl{ font-size:.72rem; text-transform:uppercase; letter-spacing:.6px; color:#9aa09a; margin-right:.2rem; }
  .chip-m{ display:inline-flex; align-items:center; gap:.35rem; background:#fff; border:1px solid var(--line); border-radius:50px; padding:.3rem .8rem; font-size:.82rem; cursor:pointer; color:var(--text); font-family:inherit; }
  .chip-m:hover{ border-color:var(--gold-soft); }
  .chip-m.on{ background:var(--forest); color:#fff; border-color:var(--forest); }
  .chip-n{ background:var(--cream); color:var(--forest); border-radius:50px; padding:0 .4rem; font-size:.72rem; }
  .chip-m.on .chip-n{ background:rgba(255,255,255,.2); color:#fff; }

  /* Seletores de ícones no modal (Tipo / Lado) */
  .picker{ display:flex; gap:.5rem; }
  .pk{ flex:1; display:flex; flex-direction:column; align-items:center; gap:.25rem; padding:.6rem .3rem; border:1.5px solid var(--line); border-radius:12px; background:#fff; cursor:pointer; font-size:.78rem; color:var(--text); font-family:inherit; }
  .pk:hover{ border-color:var(--gold-soft); }
  .pk .pk-ic{ display:inline-flex; color:var(--gold); } .pk .pk-ic svg{ width:20px; height:20px; }
  .pk.on{ border-color:var(--forest); background:var(--cream); color:var(--ink); font-weight:500; }
  .pk.on .pk-ic{ color:var(--forest); }

  /* Seleção múltipla e ações em massa */
  .sel-conv{ display:flex; align-items:center; padding-right:.2rem; cursor:pointer; }
  .sel-conv input{ width:17px; height:17px; accent-color:var(--forest); cursor:pointer; }
  .convite-row.selecionada{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .barra-selecao{ display:none; position:sticky; top:.5rem; z-index:30; gap:.45rem; flex-wrap:wrap;
    align-items:center; background:var(--forest); color:#fff; border-radius:12px;
    padding:.55rem .8rem; margin-bottom:.7rem; box-shadow:0 8px 24px rgba(32,52,42,.22); }
  .barra-selecao.on{ display:flex; }
  .barra-selecao .cont{ font-size:.88rem; margin-right:.3rem; }
  .barra-selecao .cont b{ font-family:var(--serif); font-size:1.05rem; }
  .barra-selecao .cresce{ flex:1; }
  .barra-selecao .btn-ico{ background:rgba(255,255,255,.12); border-color:rgba(255,255,255,.25); color:#fff; }
  .barra-selecao .btn-ico:hover{ background:rgba(255,255,255,.22); }

  /* Ação de WhatsApp e menu "mais ações" */
  .bt-wa{ display:inline-flex; align-items:center; gap:.3rem; }
  .bt-wa svg{ width:14px; height:14px; color:#25D366; }
  .bt-wa:hover{ border-color:#25D366; }
  .menu-mais{ display:inline-block; }
  .pop-mais{ position:fixed; z-index:70; width:190px; background:#fff; border:1px solid var(--line);
    border-radius:12px; box-shadow:0 12px 32px rgba(32,52,42,.18); padding:.3rem; }
  .pop-mais button{ display:block; width:100%; text-align:left; background:none; border:0; cursor:pointer;
    padding:.5rem .6rem; border-radius:8px; font-family:inherit; font-size:.86rem; color:var(--text); }
  .pop-mais button:hover{ background:var(--cream); }
  .pop-mais button.perigo{ color:var(--danger); }
  .pop-mais button.perigo:hover{ background:#fdecea; }
  /* Aberto para cima, a sombra vem de baixo. */
  .pop-mais.acima{ box-shadow:0 -12px 32px rgba(32,52,42,.18); }

  /* Indicador e lista de mensagens */
  .tem-msg{ display:inline-flex; align-items:center; color:var(--gold); cursor:pointer; }
  .tem-msg svg{ width:15px; height:15px; }
  .msg-conta{ font-size:.78rem; color:#9aa09a; text-transform:uppercase; letter-spacing:.5px; margin:0 0 .6rem; }
  .msg-item{ border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem; margin-bottom:.6rem; background:#fff; }
  .msg-topo{ display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem; }
  .msg-topo strong{ font-family:var(--serif); font-size:1.05rem; color:var(--ink); }
  .msg-txt{ margin:0; font-style:italic; color:var(--text); line-height:1.55; }
  .opcao-check{ display:flex; align-items:flex-start; gap:.6rem; margin-top:.8rem; cursor:pointer; font-size:.92rem; color:var(--text); }
  .opcao-check input{ width:18px; height:18px; margin-top:.1rem; accent-color:var(--forest); flex:none; }
  .opcao-check small{ color:#9aa09a; }
  .membro-linha .m-vai{ display:none; align-items:center; justify-content:center; flex:none; }
  #membros.parcial .membro-linha .m-vai{ display:inline-flex; }
  .membro-linha .m-vai input{ width:18px; height:18px; accent-color:#1f7a3d; cursor:pointer; }
  #membros.parcial .membro-linha{ background:#f4faf5; border-radius:10px; padding:.15rem .3rem; }
  .entradas-topo-dash{ text-align:center; color:#7a8078; font-size:.9rem; margin-bottom:.9rem; }
  .entradas-topo-dash b{ color:#1f7a3d; font-family:var(--serif); font-size:1.15rem; }
  .entrada-d{ border:1px solid var(--line); border-left:3px solid #1f7a3d; border-radius:12px; padding:.75rem 1rem; margin-bottom:.6rem; background:#fff; }
  .ent-d-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; }
  .ent-d-topo strong{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .ent-d-hora{ font-size:.8rem; color:#9aa09a; white-space:nowrap; }
  .ent-d-meta{ font-size:.82rem; color:#8a8f88; margin-top:.15rem; }
  .ent-d-pessoas{ font-size:.9rem; color:var(--text); margin-top:.35rem; }

  /* Histórico: reciclagem e registo de atividade */
  .abas-hist{ display:flex; gap:.4rem; border-bottom:1px solid var(--line); margin-bottom:.9rem; }
  .aba-h{ background:none; border:0; border-bottom:2px solid transparent; cursor:pointer; font-family:inherit;
    font-size:.9rem; color:#8a8f88; padding:.5rem .8rem; margin-bottom:-1px; }
  .aba-h.ativa{ color:var(--forest); border-bottom-color:var(--gold); font-weight:600; }
  .lixo-item{ display:flex; align-items:center; gap:.7rem; border:1px solid var(--line); border-radius:12px;
    padding:.7rem .9rem; margin-bottom:.55rem; background:#fff; }
  .lixo-item .cresce{ min-width:0; }
  .lixo-item strong{ font-family:var(--serif); font-size:1.05rem; color:var(--ink); display:block;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .lixo-item small{ color:#9aa09a; font-size:.8rem; }
  .reg-linha{ display:flex; gap:.6rem; align-items:baseline; padding:.45rem .2rem; border-bottom:1px solid var(--line); font-size:.86rem; }
  .reg-linha:last-child{ border-bottom:0; }
  .reg-quando{ color:#9aa09a; font-size:.78rem; white-space:nowrap; flex:none; width:92px; }
  .reg-quem{ color:var(--forest); font-weight:600; white-space:nowrap; flex:none; }
  .reg-que{ color:var(--text); min-width:0; overflow-wrap:anywhere; }
  .vazio-hist{ color:#9aa09a; text-align:center; padding:1.4rem; }
</style>
<script src="<?= asset('assets/api.js') ?>"></script>
</head>
<body>
<?php cabecalho('Gestão de Convidados', $CAS['casal'].' · '.$dataExt, 'painel'); ?>

<div class="container">

  <?php if ($temListaAntiga && $totalConvites === 0): ?>
  <div class="banner-import" id="banner-import">
    <div class="txt">
      <h4>Importar a sua lista atual</h4>
      <p>Encontrámos a lista de convidados existente. Quer trazer esses convidados para o novo sistema, organizados em convites?</p>
    </div>
    <button class="btn btn-ouro" onclick="importar(false)">Importar agora</button>
  </div>
  <?php endif; ?>

  <!-- PROGRESSO DE CAPACIDADE -->
  <div id="progresso" class="progresso-cap mb-4"></div>

  <!-- ESTATÍSTICAS -->
  <div class="grelha-stats" id="stats"></div>
  <button class="btn-stats-mais mb-4" id="stats-mais" onclick="alternarStats()">Mais filtros</button>
  <div class="esp-stats mb-4"></div>

  <!-- AÇÕES -->
  <div class="barra-acoes">
    <div class="cresce">
      <input type="search" id="busca" placeholder="Procurar convite, código ou pessoa…" oninput="debounceCarregar()">
    </div>
    <button class="btn btn-verde" onclick="abrirMesas()">Mesas</button>
    <button class="btn btn-fantasma" onclick="abrirMensagens()">Mensagens</button>
    <button class="btn btn-fantasma" onclick="abrirEntradas()">Entradas</button>
    <button class="btn btn-fantasma" onclick="abrirHistorico()">Histórico</button>
    <a class="btn btn-fantasma" href="api.php?action=export">Exportar CSV</a>
    <button class="btn btn-ouro" onclick="novoConvite()">+ Novo convite</button>
  </div>

  <!-- FILTRO DE MESAS (chips) -->
  <div id="filtro-mesas" class="chips-mesa mb-4"></div>

  <!-- LISTA -->
  <div class="barra-selecao" id="barra-selecao"></div>
  <div class="lista" id="lista"></div>
</div>

<!-- ===== MODAL CONVITE ===== -->
<div class="overlay" id="ov-convite">
  <div class="modal">
    <div class="modal-topo"><h3 id="modal-titulo">Novo convite</h3><button class="fechar" onclick="fechar('ov-convite')">&times;</button></div>
    <div class="modal-corpo">
      <input type="hidden" id="c-id">

      <label>Convidados (nomes reais)</label>
      <div id="membros"></div>
      <button class="btn btn-fantasma btn-sm" type="button" onclick="addMembro()">+ Adicionar pessoa</button>

      <div style="margin-top:1.1rem;">
        <label>Nome a exibir no convite</label>
        <div class="sugestoes" id="sugestoes"></div>
        <input type="text" id="c-nome" placeholder="Ex: Família Agostinho, Sr. João e Sra. Maria…" oninput="atualizarPrevia()">
      </div>

      <div class="linha-form" style="margin-top:1rem;">
        <div><label>Lugares</label><input type="number" id="c-lugares" min="1" value="1" oninput="atualizarPrevia()"></div>
        <div><label>Sufixo (opcional)</label><input type="text" id="c-sufixo" placeholder="ex: e acompanhante" oninput="atualizarPrevia()"></div>
      </div>

      <div class="previa" id="previa">Família Agostinho</div>

      <label class="opcao-check">
        <input type="checkbox" id="c-mostrar-numero" checked onchange="atualizarPrevia()">
        <span>Mostrar o número de lugares entre parênteses no convite <small>(e a nota que o explica)</small></span>
      </label>

      <label class="opcao-check">
        <input type="checkbox" id="c-mostrar-num-mesa" checked>
        <span>Mostrar o número de lugares por mesa no convite (digital e físico) <small>(ex.: Mesa: A (1 lugar) e B (4 lugares))</small></span>
      </label>

      <div class="linha-form">
        <div><label>Tipo de convite</label>
          <input type="hidden" id="c-tipo" value="digital">
          <div class="picker" data-target="c-tipo"></div></div>
        <div><label>Lado</label>
          <input type="hidden" id="c-lado" value="noivo">
          <div class="picker" data-target="c-lado"></div></div>
        <div><label>Mesa</label><input type="text" id="c-mesa" list="lista-mesas" placeholder="Nome da mesa"><datalist id="lista-mesas"></datalist></div>
        <div><label>Telefone</label><input type="text" id="c-telefone" placeholder="+244…"></div>
      </div>

      <div style="margin-top:1rem;">
        <label>Presença</label>
        <input type="hidden" id="c-presenca" value="pendente">
        <div class="picker" data-target="c-presenca"></div>
      </div>
      <div style="margin-top:1rem;"><label>Observações</label><textarea id="c-obs" rows="2"></textarea></div>
      <div style="margin-top:1rem;"><label>Mensagem pessoal no convite digital <span style="color:#aaa;font-weight:300">(opcional)</span></label><textarea id="c-msg" rows="2" placeholder="Ex: Mal podemos esperar por vos receber!"></textarea></div>

      <div id="bloco-link" style="display:none; margin-top:1.2rem;">
        <label>Link de confirmação / QR</label>
        <div class="link-box">
          <input type="text" id="c-link" readonly>
          <button class="btn-ico" title="Copiar" onclick="copiarLink()">Copiar</button>
          <button class="btn-ico" title="QR" onclick="mostrarQRatual()">QR</button>
        </div>
      </div>

      <div style="display:flex; gap:.6rem; margin-top:1.4rem; justify-content:flex-end;">
        <button class="btn btn-fantasma" onclick="fechar('ov-convite')">Cancelar</button>
        <button class="btn btn-ouro" onclick="guardarConvite()">Guardar convite</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL QR ===== -->
<div class="overlay" id="ov-qr">
  <div class="modal" style="max-width:380px;">
    <div class="modal-topo"><h3 id="qr-titulo">Código QR</h3><button class="fechar" onclick="fechar('ov-qr')">&times;</button></div>
    <div class="modal-corpo qr-holder">
      <canvas id="qr-canvas"></canvas>
      <p style="font-size:.85rem; color:#8a8f88; margin:.6rem 0;">Apresente este código à entrada do evento.</p>
      <button class="btn btn-ouro" onclick="descarregarQR()">Descarregar imagem</button>
    </div>
  </div>
</div>

<!-- ===== MODAL MESAS ===== -->
<div class="overlay" id="ov-mesas">
  <div class="modal">
    <div class="modal-topo"><h3>Mesas</h3><button class="fechar" onclick="fechar('ov-mesas')">&times;</button></div>
    <div class="modal-corpo">
      <div class="linha-form" style="align-items:end;">
        <div><label>Nome da mesa</label><input type="text" id="m-nome" placeholder="Ex: Mesa 1, Família, Honra…"></div>
        <div><label>Capacidade</label><input type="number" id="m-cap" min="1" placeholder="opcional"></div>
        <div><button class="btn btn-ouro" style="width:100%; justify-content:center;" onclick="guardarMesa()">Adicionar</button></div>
      </div>
      <input type="hidden" id="m-id">
      <div id="lista-mesas-gestao" style="margin-top:1rem;"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL MENSAGENS ===== -->
<div class="overlay" id="ov-mensagens">
  <div class="modal">
    <div class="modal-topo"><h3>Mensagens dos convidados</h3><button class="fechar" onclick="fechar('ov-mensagens')">&times;</button></div>
    <div class="modal-corpo">
      <div id="lista-mensagens"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL ENTRADAS (quem já deu entrada) ===== -->
<div class="overlay" id="ov-entradas">
  <div class="modal">
    <div class="modal-topo"><h3>Quem já deu entrada</h3><button class="fechar" onclick="fechar('ov-entradas')">&times;</button></div>
    <div class="modal-corpo">
      <div id="entradas-topo-dash" class="entradas-topo-dash"></div>
      <div id="lista-entradas-dash"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL HISTÓRICO (reciclagem + registo de atividade) ===== -->
<div class="overlay" id="ov-historico">
  <div class="modal">
    <div class="modal-topo"><h3>Histórico</h3><button class="fechar" onclick="fechar('ov-historico')">&times;</button></div>
    <div class="modal-corpo">
      <div class="abas-hist">
        <button class="aba-h ativa" id="aba-lixo" onclick="abaHistorico('lixo')">Reciclagem</button>
        <button class="aba-h" id="aba-registo" onclick="abaHistorico('registo')">Atividade</button>
      </div>
      <div id="hist-lixo"></div>
      <div id="hist-registo" hidden></div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(base_url()) ?>;
const CASAL = <?= json_encode($CAS['casal']) ?>;
const DATA_EXT = <?= json_encode($dataExt) ?>;
window.CSRF = <?= json_encode(csrfToken()) ?>;
const CAP = <?= (int)MAX_LUGARES_TOTAL ?>;
let CONVITES = [], MESAS = [], STATS = {}, timer = null;
let filtroTipo='', filtroLado='', filtroEstado='', filtroMesa='', filtroImpresso='', filtroGenero='', filtroBrinde='';
const SEM_MESA = '__SEM_MESA__'; // valor especial do filtro "sem mesa"

// ---------- utilidades ----------
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
let tToast=null;
function toast(msg, erro=false){ const t=$('toast'); clearTimeout(tToast);
  t.textContent=msg; t.className='toast mostrar'+(erro?' erro':''); tToast=setTimeout(()=>t.className='toast',2600); }
/** Toast com um botão "Anular" — dá tempo (7s) de desfazer a ação. */
function toastAnular(msg, aoAnular){
  const t=$('toast'); clearTimeout(tToast);
  t.innerHTML=`<span>${esc(msg)}</span><button type="button" class="anular">Anular</button>`;
  t.className='toast mostrar accao';
  t.querySelector('.anular').onclick=()=>{ clearTimeout(tToast); t.className='toast'; aoAnular(); };
  tToast=setTimeout(()=>t.className='toast',7000);
}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
// api() vem de assets/api.js (trata sessão expirada, falha de rede e erros do servidor)
function debounceCarregar(){ clearTimeout(timer); timer=setTimeout(carregar,300); }

// ---------- carregar ----------
// A lista vem por pedaços: mudar de filtro recomeça na primeira página,
// "Mostrar mais" acrescenta a seguinte ao que já está no ecrã.
let PAGINA = 1, TOTAL = 0, HA_MAIS = false;

async function carregar(mais=false){
  PAGINA = mais ? PAGINA + 1 : 1;
  const q = new URLSearchParams({
    tipo:filtroTipo, lado:filtroLado, estado:filtroEstado,
    mesa:filtroMesa, busca:$('busca').value, impresso:filtroImpresso,
    genero:filtroGenero, brinde:filtroBrinde, pagina:PAGINA
  });
  const d = await api('convite_list&'+q.toString());
  if(!d.success){ if(mais) PAGINA--; return toast('Erro ao carregar.', true); }
  CONVITES = mais ? CONVITES.concat(d.convites) : d.convites;
  MESAS=d.mesas; STATS=d.stats||{}; TOTAL=+d.total||CONVITES.length; HA_MAIS=!!d.ha_mais;
  renderStats(d.stats); renderConvites(); renderFiltroMesas(); renderDatalistMesas();
}

// Conjunto de ícones (SVG inline, traço fino)
const IC = {
  whatsapp:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.21 8.21 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.1-.23-.17-.48-.29z"/></svg>',
  todos:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
  telemovel:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>',
  envelope:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
  impressora:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>',
  check:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
  relogio:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  xis:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
  noivo:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8.6 14 L8 4.9 Q8 4.3 8.6 4.3 L15.4 4.3 Q16 4.3 16 4.9 L15.4 14"/><ellipse cx="12" cy="14" rx="8.6" ry="1.9"/><path d="M8.2 11.4 H15.8"/></svg>',
  noiva:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 16.4 Q12 13.4 20.5 16.4"/><path d="M4.8 16 L8 9.2 L10.4 13 L12 6.4 L13.6 13 L16 9.2 L19.2 16"/><circle cx="12" cy="5.6" r="1" fill="currentColor" stroke="none"/><circle cx="8" cy="8.4" r=".8" fill="currentColor" stroke="none"/><circle cx="16" cy="8.4" r=".8" fill="currentColor" stroke="none"/></svg>',
  balao:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/></svg>',
  meio:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg>',
  masculino:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="14" r="5"/><path d="M14.2 9.8 L20 4"/><path d="M15 4 H20 V9"/></svg>',
  feminino:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="5"/><path d="M12 14 V21"/><path d="M9 18 H15"/></svg>',
  brinde:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5.2 12 V20 H18.8 V12"/><path d="M12 8 V20"/><path d="M12 8 Q8 8 8 5.5 Q8 4 9.5 4 Q12 4.2 12 8 Q12 4.2 14.5 4 Q16 4 16 5.5 Q16 8 12 8"/></svg>'
};

// Todos os cartões dizem o mesmo: número grande = PESSOAS, linha de baixo = a
// quantos convites pertencem. Sem esta regra lia-se "6 · 2 convites" ao lado de
// "0 · convidados" e não se percebia o que cada número contava.
function statCard(ic,pessoas,convites,l,onclick,ativo,cls='',subHtml='',titulo=''){
  const p = (+pessoas===1?'1 pessoa':(+pessoas||0)+' pessoas');
  const c = (+convites===1?'1 convite':(+convites||0)+' convites');
  const sub = subHtml || c;
  const tit = titulo || `${l}: ${p} em ${c}`;
  return `<button class="stat-f ${cls}${ativo?' ativo':''}" onclick="${onclick}" title="${tit}">
    <span class="si">${ic}</span><span class="sn">${+pessoas||0}</span><span class="sl">${l}</span><span class="ss">${sub}</span></button>`;
}

// Sub-linha do cartão de brindes: quantos recebem por género (♂/♀).
function brindeSub(s){
  const m=+s.pes_brinde_m||0, f=+s.pes_brinde_f||0, sg=+s.pes_brinde_sg||0;
  return `<span class="gi gi-m">♂</span> ${m} · <span class="gi gi-f">♀</span> ${f}`
       + (sg?` · ${sg}?`:'');
}
function brindeTitulo(s){
  const m=+s.pes_brinde_m||0, f=+s.pes_brinde_f||0, sg=+s.pes_brinde_sg||0;
  return `Brindes: ${m} a homens · ${f} a mulheres`
       + (sg?` · ${sg} sem género definido`:'') + ` (${(+s.pes_brinde||0)} no total)`;
}

// Os quatro primeiros são o essencial; no telemóvel os restantes ficam
// escondidos atrás de "Mais filtros" para a lista não fugir do ecrã.
const CARTOES_BASE = 4;

function renderStats(s){
  renderProgresso(s);
  const e=filtroEstado, t=filtroTipo, l=filtroLado;
  const limpo = !e && !t && !l && !filtroImpresso && !filtroMesa && !filtroGenero && !filtroBrinde && !$('busca').value;
  const cartoes = [
    statCard(IC.todos, s.lugares, s.convites, 'Todos', "limparFiltros()", limpo),
    statCard(IC.check, s.pes_confirmados, s.confirmados, 'Confirmados', "filtrarEstado('confirmado')", e==='confirmado','verde'),
    statCard(IC.relogio, s.pes_pendentes, s.pendentes, 'Pendentes', "filtrarEstado('pendente')", e==='pendente','ouro'),
    statCard(IC.xis, s.pes_recusados, s.recusados, 'Recusados', "filtrarEstado('recusado')", e==='recusado','rosa'),
    statCard(IC.telemovel, s.pes_digitais, s.digitais, 'Digitais', "filtrarTipo('digital')", t==='digital'),
    statCard(IC.envelope, s.pes_fisicos, s.fisicos, 'Físicos', "filtrarTipo('fisico')", t==='fisico'),
    statCard(IC.impressora, s.pes_impressos, s.impressos, 'Impressos', "filtrarImpresso()", filtroImpresso==='1'),
    statCard(IC.noivo, s.pes_noivos, s.noivos, 'Noivo', "filtrarLado('noivo')", l==='noivo'),
    statCard(IC.noiva, s.pes_noivas, s.noivas, 'Noiva', "filtrarLado('noiva')", l==='noiva'),
    statCard(IC.masculino, s.pes_masculino, s.conv_masculino, 'Masculino', "filtrarGenero('m')", filtroGenero==='m'),
    statCard(IC.feminino, s.pes_feminino, s.conv_feminino, 'Feminino', "filtrarGenero('f')", filtroGenero==='f','rosa'),
    statCard(IC.brinde, s.pes_brinde, s.conv_brinde, 'Brindes', "filtrarBrinde()", filtroBrinde==='1','ouro', brindeSub(s), brindeTitulo(s)),
  ];
  // Um filtro ativo entre os "extra" obriga a mostrá-los: senão o painel diria
  // que está filtrado sem se ver por quê.
  if (filtroTipo || filtroImpresso || filtroLado || filtroGenero || filtroBrinde) STATS_ABERTO = true;
  $('stats').innerHTML =
    cartoes.slice(0, CARTOES_BASE).join('') +
    `<div class="stats-extra${STATS_ABERTO?' aberto':''}">${cartoes.slice(CARTOES_BASE).join('')}</div>`;
  $('stats-mais').innerHTML = STATS_ABERTO
    ? 'Menos filtros'
    : `Mais filtros <span class="conta-extra">${cartoes.length-CARTOES_BASE}</span>`;
}
let STATS_ABERTO = false;
function alternarStats(){ STATS_ABERTO = !STATS_ABERTO; carregar(); }

// Barra de progresso: preenchimento do número de convidados face à capacidade
function renderProgresso(s){
  const cap=+s.capacidade||CAP||0;
  const conv=+s.lugares||0, conf=+s.pes_confirmados||0;
  const pConv=cap?Math.min(100,Math.round(conv/cap*100)):0;
  const pConf=cap?Math.min(100,Math.round(conf/cap*100)):0;
  $('progresso').innerHTML = `
    <div class="pc-topo">
      <span class="pc-tit">Convidados</span>
      <span class="pc-nums"><b>${conv}</b> convidados · <b class="v-conf">${conf}</b> confirmados · capacidade <b>${cap}</b></span>
    </div>
    <div class="pc-barra" title="${conv} de ${cap} lugares (${pConv}%)">
      <div class="pc-conv" style="width:${pConv}%"></div>
      <div class="pc-conf" style="width:${pConf}%"></div>
    </div>`;
}

// Filtros a partir dos cartões (alternam ligado/desligado e recarregam)
function filtrarEstado(v){ filtroEstado = (filtroEstado===v?'':v); carregar(); }
function filtrarTipo(v){ if(filtroImpresso && v==='digital') filtroImpresso=''; filtroTipo = (filtroTipo===v?'':v); carregar(); }
function filtrarLado(v){ filtroLado = (filtroLado===v?'':v); carregar(); }
function filtrarImpresso(){ filtroImpresso = filtroImpresso==='1'?'':'1'; if(filtroImpresso==='1') filtroTipo=''; carregar(); }
function filtrarMesa(v){ filtroMesa = (filtroMesa===v?'':v); carregar(); }
function filtrarGenero(v){ filtroGenero = (filtroGenero===v?'':v); carregar(); }
function filtrarBrinde(){ filtroBrinde = filtroBrinde==='1'?'':'1'; carregar(); }
function limparFiltros(){ filtroTipo=''; filtroLado=''; filtroEstado=''; filtroMesa=''; filtroImpresso=''; filtroGenero=''; filtroBrinde=''; $('busca').value=''; carregar(); }

// Seletores de ícones do modal (substituem os selects)
const PICK = {
  'c-tipo':[['digital','Digital',IC.telemovel],['fisico','Físico',IC.envelope],['ambos','Ambos',IC.telemovel+IC.envelope]],
  'c-lado':[['noivo','Noivo',IC.noivo],['noiva','Noiva',IC.noiva],['ambos','Ambos',IC.noivo+IC.noiva]],
  'c-presenca':[['pendente','Pendente',IC.relogio],['confirmado','Confirmado',IC.check],['parcial','Parcial',IC.meio],['recusado','Recusado',IC.xis]]
};
function montarPickers(){
  document.querySelectorAll('.picker').forEach(box=>{
    const tid=box.dataset.target, opts=PICK[tid]||[];
    box.innerHTML=opts.map(([v,l,ic])=>`<button type="button" class="pk" data-v="${v}" onclick="pickVal('${tid}','${v}')"><span class="pk-ic">${ic}</span>${l}</button>`).join('');
  });
}
function pickVal(tid,v){
  $(tid).value=v;
  document.querySelectorAll('.picker[data-target="'+tid+'"] .pk').forEach(b=>b.classList.toggle('on', b.dataset.v===v));
  if(tid==='c-presenca') sincroPresencaMembros(v);
}

function tagEstado(e){
  const map={confirmado:['ok','Confirmado'],pendente:['pend','Pendente'],recusado:['rec','Recusado'],parcial:['parc','Parcial']};
  const [c,t]=map[e]||['neutra',e]; return `<span class="tag ${c}">${t}</span>`;
}
function iconeTipo(t){ return t==='fisico'?IC.envelope:(t==='ambos'?(IC.telemovel+IC.envelope):IC.telemovel); }
function iconeLado(l){ return l==='noiva'?IC.noiva:(l==='ambos'?(IC.noivo+IC.noiva):IC.noivo); }
// Ícones sugestivos de género (e brinde) para as pastilhas com nomes.
const genIco=g=> g==='m'?'<span class="gi gi-m" title="Masculino">♂</span> ':g==='f'?'<span class="gi gi-f" title="Feminino">♀</span> ':'';
const brindeIco=b=> +b?' <span class="gi gi-b" title="Recebe brinde">🎁</span>':'';

// ---------- seleção múltipla / ações em massa ----------
const SELEC = new Set();
function alternarSelecao(id, on){ on ? SELEC.add(id) : SELEC.delete(id); renderBarraSelecao(); pintarSelecao(); }
function pintarSelecao(){
  document.querySelectorAll('.convite-row').forEach((row,i)=>{
    const c = CONVITES[i]; if(!c) return;
    row.classList.toggle('selecionada', SELEC.has(c.id));
    const cx = row.querySelector('.sel-conv input'); if(cx) cx.checked = SELEC.has(c.id);
  });
}
function selecionarTodos(on){
  SELEC.clear();
  if(on) CONVITES.forEach(c=>SELEC.add(c.id));
  renderBarraSelecao(); pintarSelecao();
}
function limparSelecao(){ SELEC.clear(); renderBarraSelecao(); pintarSelecao(); }
function renderBarraSelecao(){
  const b = $('barra-selecao'); if(!b) return;
  const n = SELEC.size;
  b.classList.toggle('on', n > 0);
  if(!n) return;
  b.innerHTML = `<span class="cont"><b>${n}</b> ${n===1?'convite selecionado':'convites selecionados'}</span>
    <button class="btn-ico" onclick="massaFlag('impresso',1)">Marcar impressos</button>
    <button class="btn-ico" onclick="massaFlag('impresso',0)">Desmarcar impressos</button>
    <button class="btn-ico" onclick="massaFlag('enviado',1)">Marcar enviados</button>
    <button class="btn-ico" onclick="massaFlag('enviado',0)">Desmarcar enviados</button>
    <button class="btn-ico" onclick="massaMesa()">Atribuir mesa…</button>
    <div class="cresce"></div>
    <button class="btn-ico" onclick="selecionarTodos(true)" title="${HA_MAIS?'Só os que já estão na lista; use "Mostrar mais" para trazer os restantes':''}">Selecionar ${HA_MAIS?'os visíveis':'todos'} (${CONVITES.length})</button>
    <button class="btn-ico" onclick="limparSelecao()">Limpar</button>`;
}
// Aplica uma marcação a todos os selecionados, um pedido de cada vez.
async function massaFlag(campo, valor){
  const ids = [...SELEC]; if(!ids.length) return;
  let feitos = 0;
  for(const id of ids){
    const d = await api(`convite_flag&id=${id}&campo=${campo}&valor=${valor}`, {silencioso:true});
    if(d && d.success) feitos++;
  }
  toast(`${feitos} de ${ids.length} convite(s) atualizados.`);
  limparSelecao(); carregar();
}
async function massaMesa(){
  const ids = [...SELEC]; if(!ids.length) return;
  const nomes = (MESAS||[]).filter(m=>m.especial!=='noivos').map(m=>m.nome);
  if(!nomes.length) return toast('Ainda não há mesas criadas.', true);
  const escolha = prompt('Atribuir estes convites a que mesa?\n\nMesas: ' + nomes.join(', ') + '\n\n(deixe vazio para retirar da mesa)');
  if(escolha === null) return;
  const mesa = (MESAS||[]).find(m=>m.nome.toLowerCase() === escolha.trim().toLowerCase());
  if(escolha.trim() !== '' && !mesa) return toast('Não existe uma mesa com esse nome.', true);
  let feitos = 0;
  for(const id of ids){
    const d = await api('convite_mesa', {method:'POST', silencioso:true,
      body: JSON.stringify({id, mesa_id: mesa ? mesa.id : ''})});
    if(d && d.success) feitos++;
  }
  toast(`${feitos} de ${ids.length} convite(s) ${mesa ? 'atribuídos a '+mesa.nome : 'retirados da mesa'}.`);
  limparSelecao(); carregar();
}

function renderConvites(){
  const el=$('lista');
  if(!CONVITES.length){ el.innerHTML=`<div class="vazio"><div class="ico">✦</div><p>Ainda não há convites. Crie o primeiro ou importe a sua lista.</p></div>`; return; }
  // Convites que saíram da lista (por filtro) deixam de contar para a seleção
  [...SELEC].forEach(id => { if(!CONVITES.some(c=>c.id==id)) SELEC.delete(id); });
  renderBarraSelecao();
  el.innerHTML = CONVITES.map(c=>{
    const membros = (c.membros_det&&c.membros_det.length ? c.membros_det : (c.membros||[]).map(n=>({nome:n,genero:'',brinde:0})))
      .map(m=>`<span class="membro-chip">${genIco(m.genero)}${esc(m.nome)}${brindeIco(m.brinde)}</span>`).join('');
    const confTxt = c.rsvp_confirmados!=null && c.rsvp_estado!=='pendente' ? ` · ${c.rsvp_confirmados}/${c.lugares} confirmados` : '';
    const presTxt = c.checkin_presentes>0 ? ` · <span style="color:var(--ok)">${c.checkin_presentes} no local</span>` : '';
    return `<div class="convite-row${SELEC.has(c.id)?' selecionada':''}">
      <label class="sel-conv" title="Selecionar para ações em massa">
        <input type="checkbox" ${SELEC.has(c.id)?'checked':''} onchange="alternarSelecao(${c.id},this.checked)">
      </label>
      <div class="selo-tipo ${c.tipo}" title="${c.tipo}">${iconeTipo(c.tipo)}</div>
      <div class="convite-corpo">
        <div class="convite-nome">${esc(c.nome_final)}</div>
        <div class="convite-meta">
          <span>${tagEstado(c.rsvp_estado)}</span>
          <span>${(+c.mesas_distintas>1)?('Dividido · '+c.mesas_distintas+' mesas'):(c.mesa_efetiva_nome?('Mesa: '+esc(c.mesa_efetiva_nome)):'Sem mesa')}</span>
          <span>Cód: <strong>${c.codigo}</strong></span>
          ${c.telefone?`<span>${esc(c.telefone)}</span>`:''}
          ${(c.rsvp_mensagem&&c.rsvp_mensagem.trim())?`<span class="tem-msg" title="Mensagem: ${esc(c.rsvp_mensagem)}" onclick="abrirMensagens()">${IC.balao}</span>`:''}
          <span class="lado-ic" title="Lado: ${c.lado}">${iconeLado(c.lado)}</span>
          <span style="color:#b7bbb5">${(confTxt+presTxt).replace(/^ · /,'')}</span>
        </div>
        <div class="membros-mini">${membros}</div>
      </div>
      <div class="acoes">
        ${c.telefone
          ? `<button class="btn-ico bt-wa" title="Enviar o convite por WhatsApp para ${esc(c.telefone)}" onclick="enviarWhatsApp(${c.id})">${IC.whatsapp} Enviar</button>`
          : `<button class="btn-ico" title="Sem telefone — adicione-o para poder enviar" onclick="editar(${c.id})" style="opacity:.55">${IC.whatsapp} Sem nº</button>`}
        <button class="btn-ico" title="Editar" onclick="editar(${c.id})">Editar</button>
        ${c.tipo!=='fisico'?`<button class="btn-ico" title="Marcar como enviado" onclick="flag(${c.id},'enviado',${c.enviado?0:1})" style="${c.enviado?'background:var(--ok-bg);color:var(--ok)':''}">${c.enviado?'Enviado ✓':'Enviado'}</button>`:''}
        ${c.tipo!=='digital'?`<button class="btn-ico" title="Marcar como impresso" onclick="flag(${c.id},'impresso',${c.impresso?0:1})" style="${c.impresso?'background:var(--ok-bg);color:var(--ok)':''}">${c.impresso?'Impresso ✓':'Impresso'}</button>`:''}
        <div class="menu-mais">
          <button class="btn-ico" title="Mais ações" aria-haspopup="true" onclick="abrirMais(event,${c.id})">⋯</button>
        </div>
      </div>
    </div>`;
  }).join('') + rodapeLista();
}

/** Rodapé da lista: quantos se veem, quantos há e o botão para trazer mais. */
function rodapeLista(){
  if(!HA_MAIS) return TOTAL > CONVITES.length ? '' :
    (TOTAL > 10 ? `<p class="fim-lista">${TOTAL} convite(s) — está tudo aqui.</p>` : '');
  const faltam = TOTAL - CONVITES.length;
  return `<button class="btn-mais-lista" onclick="carregar(true)">
      Mostrar mais <span class="conta-extra">${faltam}</span>
      <small>a ver ${CONVITES.length} de ${TOTAL}</small>
    </button>`;
}

function renderFiltroMesas(){
  const box=$('filtro-mesas'); if(!box) return;
  if(!MESAS.length){ box.innerHTML=''; return; }
  const chip=(v,l,on)=>`<button class="chip-m${on?' on':''}" onclick="filtrarMesa('${esc(v)}')">${l}</button>`;
  // Pessoas por sentar = total de lugares menos os já sentados nas mesas
  const sentados=MESAS.reduce((s,m)=>s+(+m.ocupacao||0),0);
  const semMesa=Math.max(0,(+STATS.lugares||0)-sentados);
  box.innerHTML = `<span class="chips-lbl">Mesa</span>`
    + chip('', 'Todas', !filtroMesa)
    + chip(SEM_MESA, 'Sem mesa'+`<span class="chip-n">${semMesa}</span>`, filtroMesa===SEM_MESA)
    + MESAS.map(m=>chip(m.nome, esc(m.nome)+`<span class="chip-n">${m.ocupacao||0}</span>`, filtroMesa===m.nome)).join('');
}
function renderDatalistMesas(){ $('lista-mesas').innerHTML=MESAS.map(m=>`<option value="${esc(m.nome)}">`).join(''); }

// ---------- modal convite ----------
function novoConvite(){ abrirConvite(null); }
async function editar(id){ const d=await api('convite_get&id='+id); if(d.success) abrirConvite(d.convite); }

function abrirConvite(c){
  $('modal-titulo').textContent = c?'Editar convite':'Novo convite';
  $('c-id').value = c?c.id:'';
  $('c-nome').value = c?c.nome_exibicao:'';
  $('c-sufixo').value = c?(c.sufixo||''):'';
  $('c-mostrar-numero').checked = c ? (String(c.mostrar_numero)!=='0') : true;
  $('c-mostrar-num-mesa').checked = c ? (String(c.mostrar_num_mesa)!=='0') : true;
  $('c-lugares').value = c?c.lugares:1;
  pickVal('c-tipo', c?c.tipo:'digital');
  pickVal('c-lado', c?c.lado:'noivo');
  pickVal('c-presenca', c?(c.rsvp_estado||'pendente'):'pendente');
  $('c-mesa').value = c?(c.mesa_nome||''):'';
  $('c-telefone').value = c?(c.telefone||''):'';
  $('c-obs').value = c?(c.observacoes||''):'';
  $('c-msg').value = c?(c.msg_pessoal||''):'';
  $('membros').innerHTML='';
  const ms = c&&c.membros&&c.membros.length ? c.membros : [{nome:'',rsvp:'confirmado'}];
  ms.forEach(m=>addMembro(m.nome||'', m.rsvp ? m.rsvp==='confirmado' : true, m.mesa_id||'', m.papel||'', m.genero||'', !!(+m.brinde)));
  sincroPresencaMembros($('c-presenca').value);
  if(c){ $('bloco-link').style.display='block'; $('c-link').value=BASE+'/convite-digital.php?c='+c.codigo; $('c-link').dataset.codigo=c.codigo; }
  else { $('bloco-link').style.display='none'; }
  atualizarPrevia(); renderSugestoes();
  abrir('ov-convite');
}
function opcoesMesaMembro(selId){
  let o='<option value="">Mesa do convite</option>';
  // A mesa dos noivos não é selecionável: só a integram padrinhos e madrinhas (pelo papel).
  (MESAS||[]).filter(m=>m.especial!=='noivos').forEach(m=>{ o+=`<option value="${m.id}" ${String(selId)===String(m.id)?'selected':''}>${esc(m.nome)}</option>`; });
  return o;
}
function opcoesPapelMembro(sel){
  return ['','Convidado(a)','padrinho','Padrinho','madrinha','Madrinha']
    .reduce((o,v,i,a)=>i%2?o:o+`<option value="${v}" ${sel===v?'selected':''}>${a[i+1]}</option>`,'');
}
function opcoesGeneroMembro(sel){
  return ['','Género','m','♂ Masculino','f','♀ Feminino']
    .reduce((o,v,i,a)=>i%2?o:o+`<option value="${v}" ${sel===v?'selected':''}>${a[i+1]}</option>`,'');
}
function addMembro(valor='', vai=true, mesaId='', papel='', genero='', brinde=false){
  const div=document.createElement('div'); div.className='membro-linha';
  div.innerHTML=`<label class="m-vai" title="Esta pessoa confirma presença"><input type="checkbox" ${vai?'checked':''}></label>
    <input type="text" placeholder="Nome completo" value="${esc(valor)}" oninput="renderSugestoes()">
    <select class="m-genero" title="Género do convidado">${opcoesGeneroMembro(genero)}</select>
    <select class="m-mesa" title="Mesa desta pessoa (por omissão, a do convite)">${opcoesMesaMembro(mesaId)}</select>
    <select class="m-papel" title="Papel: padrinho/madrinha entram nas alas da mesa dos noivos" onchange="sincroMesaPapel(this.closest('.membro-linha'))">${opcoesPapelMembro(papel)}</select>
    <label class="m-brinde" title="Recebe brinde"><input type="checkbox" ${brinde?'checked':''}> 🎁</label>
    <button class="btn-ico" type="button" onclick="this.parentElement.remove();renderSugestoes();atualizarPrevia()">✕</button>`;
  $('membros').appendChild(div);
  sincroMesaPapel(div);
}
// Padrinhos/madrinhas pertencem à mesa dos noivos (pelo papel); nesse caso a mesa
// individual não se aplica — mostra "Mesa dos noivos" e desativa o seletor.
function sincroMesaPapel(row){
  if(!row) return;
  const papelSel=row.querySelector('.m-papel'); const mesaSel=row.querySelector('.m-mesa');
  if(!papelSel||!mesaSel) return;
  const ehPapel = papelSel.value==='padrinho' || papelSel.value==='madrinha';
  let opt=mesaSel.querySelector('option[data-noivos]');
  if(ehPapel){
    if(!opt){ opt=document.createElement('option'); opt.dataset.noivos='1'; opt.value=''; opt.textContent='Mesa dos noivos'; mesaSel.insertBefore(opt, mesaSel.firstChild); }
    mesaSel.value=''; mesaSel.selectedIndex=[...mesaSel.options].indexOf(opt);
    mesaSel.disabled=true;
  } else {
    if(opt) opt.remove();
    mesaSel.disabled=false;
  }
}
function nomesMembros(){ return [...$('membros').querySelectorAll('input[type=text]')].map(i=>i.value.trim()).filter(Boolean); }
function membrosComPresenca(){
  return [...$('membros').querySelectorAll('.membro-linha')].map(row=>({
    nome: row.querySelector('input[type=text]').value.trim(),
    vai:  row.querySelector('.m-vai input')?.checked ?? true,
    mesa_id: row.querySelector('.m-mesa') ? row.querySelector('.m-mesa').value : '',
    papel: row.querySelector('.m-papel') ? row.querySelector('.m-papel').value : '',
    genero: row.querySelector('.m-genero') ? row.querySelector('.m-genero').value : '',
    brinde: row.querySelector('.m-brinde input')?.checked ? 1 : 0
  })).filter(m=>m.nome);
}
// Mostra/oculta as marcações por pessoa conforme a presença escolhida
function sincroPresencaMembros(v){
  const box=$('membros'); if(!box) return;
  box.classList.toggle('parcial', v==='parcial');
  const checks=box.querySelectorAll('.m-vai input');
  if(v==='confirmado') checks.forEach(c=>c.checked=true);
  else if(v==='recusado') checks.forEach(c=>c.checked=false);
  // parcial e pendente: mantêm as marcações atuais (editáveis quando parcial)
}

function renderSugestoes(){
  const nomes=nomesMembros(); const box=$('sugestoes'); const sug=new Set();
  if(nomes.length===1){ sug.add(nomes[0]); const p=nomes[0].split(' '); if(p.length>1) sug.add('Sr./Sra. '+p[p.length-1]); }
  if(nomes.length>=2){
    const apel=nomes.map(n=>n.trim().split(' ').pop());
    const comum=apel.every(a=>a.toLowerCase()===apel[0].toLowerCase());
    if(comum) sug.add('Família '+apel[0]);
    const primeiros=nomes.map(n=>n.split(' ')[0]);
    if(primeiros.length===2) sug.add(primeiros[0]+' e '+primeiros[1]);
    else if(primeiros.length>2) sug.add(primeiros.slice(0,-1).join(', ')+' e '+primeiros.slice(-1));
  }
  box.innerHTML=[...sug].map(s=>`<span class="sugestao" onclick="$('c-nome').value=this.textContent;atualizarPrevia()">${esc(s)}</span>`).join('');
  // ajustar lugares automaticamente ao nº de pessoas, se ainda em branco/1
  if(nomes.length>1 && (+$('c-lugares').value)<=1) $('c-lugares').value=nomes.length;
}
function atualizarPrevia(){
  const nome=$('c-nome').value.trim()||'(nome do convite)';
  const lug=+$('c-lugares').value||1; const suf=$('c-sufixo').value.trim();
  const mostrar=$('c-mostrar-numero').checked;
  let out=nome;
  if(suf) out=`${nome} (${suf})`; else if(mostrar && lug>1) out=`${nome} (${lug})`;
  $('previa').textContent=out;
}

async function guardarConvite(){
  const nome=$('c-nome').value.trim();
  if(!nome) return toast('Indique o nome a exibir no convite.', true);
  const payload={
    id:$('c-id').value||0, nome_exibicao:nome, sufixo:$('c-sufixo').value,
    mostrar_numero:$('c-mostrar-numero').checked?1:0,
    mostrar_num_mesa:$('c-mostrar-num-mesa').checked?1:0,
    tipo:$('c-tipo').value, lado:$('c-lado').value, lugares:$('c-lugares').value,
    mesa:$('c-mesa').value, telefone:$('c-telefone').value, observacoes:$('c-obs').value,
    msg_pessoal:$('c-msg').value,
    presenca:$('c-presenca').value,
    membros:membrosComPresenca()
  };
  const d=await api('convite_save',{method:'POST',body:JSON.stringify(payload)});
  if(!d.success) return toast(d.message||'Erro ao guardar.', true);
  toast('Convite guardado.'); fechar('ov-convite'); carregar();
}

// ---------- ações de linha ----------
async function flag(id,campo,valor){ const d=await api(`convite_flag&id=${id}&campo=${campo}&valor=${valor}`); if(d.success){toast('Atualizado.');carregar();} }
async function eliminar(id){ const c=CONVITES.find(x=>x.id==id); const nome=c?c.nome_final:'este convite';
  if(!confirm(`Eliminar o convite "${nome}"?\n\nVai para a reciclagem — pode repô-lo em Histórico.`))return;
  const d=await api('convite_delete&id='+id);
  if(d.success){ toastAnular(`"${nome}" foi para a reciclagem.`, ()=>repor(id)); carregar(); } }

/** Repõe um convite que estava na reciclagem. */
async function repor(id){
  const d=await api('convite_restaurar&id='+id);
  if(d.success){ toast('Convite reposto.'); carregar(); if($('ov-historico').classList.contains('aberto')) abaHistorico('lixo'); }
}

function linkConvite(codigo){ return BASE+'/convite-digital.php?c='+codigo; }

// ---------- enviar por WhatsApp ----------
// Abre a conversa com o convidado, já com a mensagem e o link do convite.
function telefoneWa(t){
  const d = (t||'').replace(/\D/g,'');
  if(!d) return '';
  // Sem indicativo: assume Angola (244). Com 9 dígitos a começar por 9.
  if(d.length === 9 && d[0] === '9') return '244'+d;
  return d;
}
function mensagemWhatsApp(c){
  const nome = (c.nome_final || c.nome_exibicao || '').trim();
  const l = linkConvite(c.codigo);
  return `Olá ${nome}! 💛\n\n${CASAL} têm o prazer de vos convidar para o seu casamento, no dia ${DATA_EXT}.\n\nO convite está aqui:\n${l}\n\nAgradecemos que confirme a presença por esse link. Até lá!`;
}
function enviarWhatsApp(id){
  const c = CONVITES.find(x=>x.id==id); if(!c) return;
  const tel = telefoneWa(c.telefone);
  if(!tel) return toast('Este convite não tem telefone. Edite-o para o adicionar.', true);
  window.open('https://wa.me/'+tel+'?text='+encodeURIComponent(mensagemWhatsApp(c)), '_blank', 'noopener');
  // Marca como enviado, se ainda não estiver (é o objetivo da ação).
  if(!(+c.enviado)) flag(id,'enviado',1);
}

// ---------- menu "mais ações" ----------
function fecharMais(){ const m=document.getElementById('pop-mais'); if(m) m.remove(); }
function abrirMais(ev, id){
  ev.stopPropagation(); fecharMais();
  const c = CONVITES.find(x=>x.id==id); if(!c) return;
  const itens = [
    ['Copiar link',              `copiarLinkDireto('${c.codigo}')`],
    ['Ver convite digital',      `verConvite('${c.codigo}')`],
    ['Descarregar (offline)',    `baixarConvite('${c.codigo}')`],
    ['Mostrar QR',               `mostrarQR('${c.codigo}')`],
    ['Eliminar convite',         `eliminar(${c.id})`, 'perigo'],
  ];
  const pop = document.createElement('div');
  pop.id = 'pop-mais'; pop.className = 'pop-mais';
  pop.innerHTML = itens.map(([r,acao,cls]) =>
    `<button class="${cls||''}" onclick="fecharMais();${acao}">${r}</button>`).join('');
  document.body.appendChild(pop);
  const r = ev.currentTarget.getBoundingClientRect();
  const larg = 190;
  pop.style.left = Math.max(8, Math.min(window.innerWidth - larg - 8, r.right - larg)) + 'px';
  // Abre para baixo, mas se não couber abre para cima: nos últimos convites da
  // lista o menu ficava cortado pela borda de baixo e as ações lá do fundo —
  // eliminar, entre elas — nem se viam.
  const alt = pop.offsetHeight;
  const folgaBaixo = window.innerHeight - r.bottom - 6;
  const paraCima = folgaBaixo < alt && r.top - 6 > folgaBaixo;
  pop.classList.toggle('acima', paraCima);
  pop.style.top = paraCima
    ? Math.max(8, r.top - alt - 6) + 'px'
    : Math.min(r.bottom + 6, window.innerHeight - alt - 8) + 'px';
  setTimeout(()=>document.addEventListener('click', fecharMais, {once:true}), 0);
}
document.addEventListener('keydown', e => { if(e.key==='Escape') fecharMais(); });
function copiarLinkDireto(codigo){ copiarTexto(linkConvite(codigo)); }
function copiarLink(){ copiarTexto($('c-link').value); }
function verConvite(codigo){ window.open(linkConvite(codigo),'_blank','noopener'); }
function baixarConvite(codigo){ window.location.href = linkConvite(codigo)+'&download=1'; }
function copiarTexto(t){
  const feito=()=>toast('Link do convite copiado.');
  if(navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(t).then(feito).catch(()=>copiaFallback(t,feito));
  } else { copiaFallback(t,feito); }
}
function copiaFallback(t,feito){
  const ta=document.createElement('textarea'); ta.value=t;
  ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta);
  ta.focus(); ta.select();
  let ok=false; try{ ok=document.execCommand('copy'); }catch(e){}
  document.body.removeChild(ta);
  ok?feito():toast('Copie manualmente: '+t, true);
}

// ---------- QR ----------
let qrAtual=null;
function desenharQR(codigo, titulo){
  qrAtual={codigo,titulo};
  $('qr-titulo').textContent=titulo||'Código QR';
  new QRious({ element:$('qr-canvas'), value:BASE+'/convite.php?c='+codigo, size:280, level:'M',
               foreground:'#20342A', background:'#ffffff' });
  abrir('ov-qr');
}
function mostrarQR(codigo){ const c=CONVITES.find(x=>x.codigo===codigo); desenharQR(codigo, c?c.nome_final:'Código QR'); }
function mostrarQRatual(){ desenharQR($('c-link').dataset.codigo, $('c-nome').value); }
function descarregarQR(){
  const a=document.createElement('a'); a.download='qr_'+(qrAtual?qrAtual.codigo:'convite')+'.png';
  a.href=$('qr-canvas').toDataURL('image/png'); a.click();
}

// ---------- mesas ----------
async function abrirMesas(){ await renderMesasGestao(); abrir('ov-mesas'); }
async function renderMesasGestao(){
  const d=await api('mesa_list'); MESAS=d.mesas;
  $('lista-mesas-gestao').innerHTML = MESAS.length? MESAS.map(m=>{
    const cap=m.capacidade||0; const perc=cap?Math.min(100,Math.round(m.ocupacao/cap*100)):0;
    return `<div class="mesa-item">
      <div class="info"><strong>${esc(m.nome)}</strong>
        <div class="ocup">${m.ocupacao} lugar(es)${cap?(' de '+cap):''} · ${m.convites} convite(s)</div>
        ${cap?`<div class="barra-ocup"><span class="${perc>=100?'cheio':''}" style="width:${perc}%"></span></div>`:''}
      </div>
      <button class="btn-ico" onclick="editarMesa(${m.id})">Editar</button>
      <button class="btn-ico" onclick="eliminarMesa(${m.id})">✕</button>
    </div>`;
  }).join('') : '<p style="color:#9aa09a">Ainda não há mesas.</p>';
}
function editarMesa(id){ const m=MESAS.find(x=>x.id==id); if(!m)return; $('m-id').value=m.id; $('m-nome').value=m.nome; $('m-cap').value=m.capacidade||''; }
async function guardarMesa(){
  const nome=$('m-nome').value.trim(); if(!nome)return toast('Indique o nome da mesa.',true);
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:$('m-id').value||0,nome,capacidade:$('m-cap').value})});
  if(!d.success)return toast(d.message,true);
  $('m-id').value='';$('m-nome').value='';$('m-cap').value=''; MESAS=d.mesas; renderMesasGestao(); renderFiltroMesas(); renderDatalistMesas(); toast('Mesa guardada.');
}
async function eliminarMesa(id){ const m=MESAS.find(x=>x.id==id); const nome=m?m.nome:'esta mesa';
  if(!confirm(`Eliminar a mesa "${nome}"? Os convites ficam sem mesa.`))return;
  const d=await api('mesa_delete&id='+id); if(d.success){MESAS=d.mesas;renderMesasGestao();renderFiltroMesas();carregar();toast('Mesa eliminada.');} }

// ---------- importar ----------
async function importar(forcar){
  if(!forcar && !confirm('Importar os convidados da lista atual para o novo sistema?'))return;
  toast('A importar…');
  const d=await api('importar'+(forcar?'&forcar=1':''));
  if(!d.success){
    if(d.message && d.message.includes('Já existem') && confirm('Já existem convites. Substituir tudo pela lista antiga?')) return importar(true);
    return toast(d.message,true);
  }
  const b=$('banner-import'); if(b)b.remove();
  toast(`Importados ${d.convites} convites e ${d.convidados} convidados.`); carregar();
}

// ---------- modais base ----------
function abrir(id){ $(id).classList.add('aberto'); }
function fechar(id){ $(id).classList.remove('aberto'); }
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{ if(e.target===o)o.classList.remove('aberto'); }));

async function abrirMensagens(){
  const d=await api('convite_list'); // sem filtros: todas as mensagens
  const com=(d.convites||[]).filter(c=>c.rsvp_mensagem && c.rsvp_mensagem.trim());
  const el=$('lista-mensagens');
  if(!com.length){
    el.innerHTML='<p style="color:#9aa09a;text-align:center;padding:1.4rem">Ainda não há mensagens deixadas pelos convidados.</p>';
  } else {
    el.innerHTML = `<p class="msg-conta">${com.length} mensagem${com.length>1?'s':''}</p>` + com.map(c=>`
      <div class="msg-item">
        <div class="msg-topo"><strong>${esc(c.nome_final)}</strong> ${tagEstado(c.rsvp_estado)}</div>
        <p class="msg-txt">“${esc(c.rsvp_mensagem)}”</p>
      </div>`).join('');
  }
  abrir('ov-mensagens');
}

function fmtHora(sql){
  if(!sql) return '';
  const d=new Date((''+sql).replace(' ','T'));
  return isNaN(d)?'':d.toLocaleString('pt-PT',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
}
async function abrirEntradas(){
  const el=$('lista-entradas-dash'), topo=$('entradas-topo-dash');
  el.innerHTML='<p style="color:#9aa09a;text-align:center;padding:1rem">A carregar…</p>'; topo.innerHTML='';
  const d=await api('porta_entradas');
  const es=(d && d.entradas)||[];
  topo.innerHTML=`<b>${(d&&d.presentes)||0}</b> pessoas no local · ${es.length} convite(s) com entrada`;
  if(!es.length){
    el.innerHTML='<p style="color:#9aa09a;text-align:center;padding:1.2rem">Ainda ninguém deu entrada.</p>';
  } else {
    el.innerHTML=es.map(c=>{
      const presentes=(c.membros||[]).filter(m=>+m.presente).map(m=>esc(m.nome));
      const hora=fmtHora(c.checkin_em);
      return `<div class="entrada-d">
        <div class="ent-d-topo"><strong>${esc(c.nome_final)}</strong>${hora?`<span class="ent-d-hora">${hora}</span>`:''}</div>
        <div class="ent-d-meta">${c.checkin_presentes} de ${c.lugares} · ${c.mesa_nome?('Mesa '+esc(c.mesa_nome)+' · '):''}Cód. ${c.codigo}</div>
        ${presentes.length?`<div class="ent-d-pessoas">${presentes.join(', ')}</div>`:''}
      </div>`;
    }).join('');
  }
  abrir('ov-entradas');
}

// ---------- histórico: reciclagem + registo de atividade ----------
function abrirHistorico(){ abrir('ov-historico'); abaHistorico('lixo'); }

async function abaHistorico(qual){
  const lixo = qual==='lixo';
  $('aba-lixo').classList.toggle('ativa', lixo);
  $('aba-registo').classList.toggle('ativa', !lixo);
  $('hist-lixo').hidden = !lixo;
  $('hist-registo').hidden = lixo;
  lixo ? carregarLixo() : carregarRegisto();
}

async function carregarLixo(){
  const el=$('hist-lixo');
  el.innerHTML='<p class="vazio-hist">A carregar…</p>';
  const d=await api('reciclagem');
  const cs=(d && d.convites)||[];
  const dias=(d && d.dias)||30;
  if(!cs.length){ el.innerHTML=`<p class="vazio-hist">A reciclagem está vazia.<br><small>Os convites eliminados ficam aqui ${dias} dias antes de desaparecerem.</small></p>`; return; }
  el.innerHTML=`<p class="msg-conta">${cs.length} convite(s) na reciclagem · apagam-se sozinhos ao fim de ${dias} dias</p>`
    + cs.map(c=>`<div class="lixo-item">
      <div class="cresce">
        <strong>${esc(c.nome_exibicao)}</strong>
        <small>${c.lugares} lugar(es) · Cód. ${esc(c.codigo)} · eliminado ${fmtHora(c.eliminado_em)}</small>
      </div>
      <button class="btn btn-fantasma" onclick="repor(${c.id})">Repor</button>
      <button class="btn-ico" title="Apagar definitivamente" onclick="apagarDeVez(${c.id}, ${JSON.stringify(c.nome_exibicao)})">&times;</button>
    </div>`).join('');
}

async function apagarDeVez(id, nome){
  if(!confirm(`Apagar "${nome}" DEFINITIVAMENTE?\n\nJá não poderá ser reposto.`)) return;
  const d=await api('convite_delete&definitivo=1&id='+id);
  if(d.success){ toast('Convite apagado definitivamente.'); carregarLixo(); carregar(); }
}

const ACCOES = {
  convite_criado:'criou o convite',      convite_editado:'editou o convite',
  convite_eliminado:'enviou para a reciclagem', convite_reposto:'repôs o convite',
  convite_apagado:'apagou definitivamente',     mesa_eliminada:'eliminou a mesa',
  checkin:'registou entrada',            rsvp_manual:'alterou a presença',
  impresso_sim:'marcou como impresso',   impresso_nao:'desmarcou impresso',
  enviado_sim:'marcou como enviado',     enviado_nao:'desmarcou enviado',
};

let REGISTOS=[], REG_PAGINA=1, REG_MAIS=false, REG_TOTAL=0;

async function carregarRegisto(mais=false){
  const el=$('hist-registo');
  REG_PAGINA = mais ? REG_PAGINA+1 : 1;
  if(!mais) el.innerHTML='<p class="vazio-hist">A carregar…</p>';
  const d=await api('registo_lista&pagina='+REG_PAGINA);
  if(!d.success){ if(mais) REG_PAGINA--; return; }
  REGISTOS = mais ? REGISTOS.concat(d.registos||[]) : (d.registos||[]);
  REG_MAIS = !!d.ha_mais; REG_TOTAL = +d.total||REGISTOS.length;
  const rs=REGISTOS;
  if(!rs.length){ el.innerHTML='<p class="vazio-hist">Ainda não há atividade registada.</p>'; return; }
  el.innerHTML=`<p class="msg-conta">${REG_TOTAL} ação(ões) registadas · a ver as ${rs.length} mais recentes</p>`
    + rs.map(r=>{
    const que=ACCOES[r.accao]||esc(r.accao);
    const alvo=r.alvo?` <b>${esc(r.alvo)}</b>`:'';
    const det=r.detalhe?` <span style="color:#9aa09a">· ${esc(r.detalhe)}</span>`:'';
    return `<div class="reg-linha">
      <span class="reg-quando">${fmtHora(r.criado_em)}</span>
      <span class="reg-quem">${esc(r.utilizador||'—')}</span>
      <span class="reg-que">${que}${alvo}${det}</span>
    </div>`;
  }).join('')
    + (REG_MAIS ? `<button class="btn-mais-lista" onclick="carregarRegisto(true)">
        Mostrar mais <span class="conta-extra">${REG_TOTAL-rs.length}</span></button>` : '');
}

montarPickers();
carregar();
</script>
</body>
</html>
