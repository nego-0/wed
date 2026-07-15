<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
exigirAdmin();
$temListaAntiga = listaAntigaExiste($conn);
$totalConvites  = (int)$conn->query("SELECT COUNT(*) FROM {$P}convites")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel · Isabel &amp; Abednego</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Jost:wght@300;400;500;600&family=Pinyon+Script&display=swap" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<style>
  .barra-acoes{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; }
  .barra-acoes .cresce{ flex:1 1 200px; }
  .banner-import{ background:linear-gradient(135deg,var(--gold-pale),var(--sand)); border:1px solid var(--gold-soft);
    border-radius:14px; padding:1rem 1.25rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1.25rem; }
  .banner-import .txt{ flex:1 1 260px; }
  .banner-import h4{ margin-bottom:.2rem; }
  .banner-import p{ margin:0; font-size:.86rem; color:var(--text); }
  .membro-linha{ display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; }
  .membro-linha input{ flex:1; }
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
  .stat-f.ativo.verde,.stat-f.ativo.rosa,.stat-f.ativo.ouro{ background:var(--forest); }

  /* Ícones na linha do convite */
  .selo-tipo svg{ width:18px; height:18px; }
  .lado-ic{ display:inline-flex; align-items:center; gap:.25rem; color:var(--gold); }
  .lado-ic svg{ width:15px; height:15px; }
  .stat-f .ss{ font-size:.66rem; color:#b0b4ab; margin-top:.1rem; }
  .stat-f.ativo .ss{ color:rgba(239,227,203,.7); }

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
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma">I&amp;A</div>
    <div>
      <h1>Gestão de Convidados</h1>
      <div class="sub">Isabel &amp; Abednego · <?= EVENTO['data_ext'] ?></div>
    </div>
    <nav class="nav">
      <a href="index.php" class="ativo">Painel</a>
      <a href="impressos.php">Convites físicos</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

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
  <div class="grelha-stats mb-4" id="stats"></div>

  <!-- AÇÕES -->
  <div class="barra-acoes">
    <div class="cresce">
      <input type="search" id="busca" placeholder="Procurar convite, código ou pessoa…" oninput="debounceCarregar()">
    </div>
    <button class="btn btn-verde" onclick="abrirMesas()">Mesas</button>
    <button class="btn btn-fantasma" onclick="abrirMensagens()">Mensagens</button>
    <button class="btn btn-fantasma" onclick="abrirEntradas()">Entradas</button>
    <a class="btn btn-fantasma" href="api.php?action=export">Exportar CSV</a>
    <button class="btn btn-ouro" onclick="novoConvite()">+ Novo convite</button>
  </div>

  <!-- FILTRO DE MESAS (chips) -->
  <div id="filtro-mesas" class="chips-mesa mb-4"></div>

  <!-- LISTA -->
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
        <span>Mostrar o número de pessoas entre parênteses no convite <small>(e a nota que o explica)</small></span>
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

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(base_url()) ?>;
const CAP = <?= (int)MAX_LUGARES_TOTAL ?>;
let CONVITES = [], MESAS = [], timer = null;
let filtroTipo='', filtroLado='', filtroEstado='', filtroMesa='', filtroImpresso='';

// ---------- utilidades ----------
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
function toast(msg, erro=false){ const t=$('toast'); t.textContent=msg; t.className='toast mostrar'+(erro?' erro':''); setTimeout(()=>t.className='toast',2600); }
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
async function api(action, opts={}){
  if(opts.body && typeof opts.body==='string'){
    try{ const b=JSON.parse(opts.body); if(b && typeof b==='object' && !Array.isArray(b)){ b.ts=agora(); opts.body=JSON.stringify(b); } }catch(e){}
  }
  const r=await fetch('api.php?action='+action, opts); return r.json();
}
function debounceCarregar(){ clearTimeout(timer); timer=setTimeout(carregar,300); }

// ---------- carregar ----------
async function carregar(){
  const q = new URLSearchParams({
    tipo:filtroTipo, lado:filtroLado, estado:filtroEstado,
    mesa:filtroMesa, busca:$('busca').value, impresso:filtroImpresso
  });
  const d = await api('convite_list&'+q.toString());
  if(!d.success) return toast('Erro ao carregar.', true);
  CONVITES=d.convites; MESAS=d.mesas;
  renderStats(d.stats); renderConvites(); renderFiltroMesas(); renderDatalistMesas();
}

// Conjunto de ícones (SVG inline, traço fino)
const IC = {
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
  meio:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg>'
};

function statCard(ic,n,sub,l,onclick,ativo,cls=''){
  return `<button class="stat-f ${cls}${ativo?' ativo':''}" onclick="${onclick}">
    <span class="si">${ic}</span><span class="sn">${n}</span><span class="sl">${l}</span><span class="ss">${sub}</span></button>`;
}
function renderStats(s){
  renderProgresso(s);
  const e=filtroEstado, t=filtroTipo, l=filtroLado;
  const limpo = !e && !t && !l && !filtroImpresso && !filtroMesa && !$('busca').value;
  const nc = n => n+(n===1?' convite':' convites');
  $('stats').innerHTML =
    statCard(IC.todos, s.lugares, nc(s.convites), 'Todos', "limparFiltros()", limpo)+
    statCard(IC.check, s.pes_confirmados, nc(s.confirmados), 'Confirmados', "filtrarEstado('confirmado')", e==='confirmado','verde')+
    statCard(IC.relogio, s.pes_pendentes, nc(s.pendentes), 'Pendentes', "filtrarEstado('pendente')", e==='pendente','ouro')+
    statCard(IC.xis, s.pes_recusados, nc(s.recusados), 'Recusados', "filtrarEstado('recusado')", e==='recusado','rosa')+
    statCard(IC.telemovel, s.pes_digitais, nc(s.digitais), 'Digitais', "filtrarTipo('digital')", t==='digital')+
    statCard(IC.envelope, s.pes_fisicos, nc(s.fisicos), 'Físicos', "filtrarTipo('fisico')", t==='fisico')+
    statCard(IC.impressora, s.pes_impressos, nc(s.impressos), 'Impressos', "filtrarImpresso()", filtroImpresso==='1')+
    statCard(IC.noivo, s.pes_noivos, nc(s.noivos), 'Noivo', "filtrarLado('noivo')", l==='noivo')+
    statCard(IC.noiva, s.pes_noivas, nc(s.noivas), 'Noiva', "filtrarLado('noiva')", l==='noiva');
}

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
function limparFiltros(){ filtroTipo=''; filtroLado=''; filtroEstado=''; filtroMesa=''; filtroImpresso=''; $('busca').value=''; carregar(); }

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

function renderConvites(){
  const el=$('lista');
  if(!CONVITES.length){ el.innerHTML=`<div class="vazio"><div class="ico">✦</div><p>Ainda não há convites. Crie o primeiro ou importe a sua lista.</p></div>`; return; }
  el.innerHTML = CONVITES.map(c=>{
    const membros = (c.membros||[]).map(m=>`<span class="membro-chip">${esc(m)}</span>`).join('');
    const confTxt = c.rsvp_confirmados!=null && c.rsvp_estado!=='pendente' ? ` · ${c.rsvp_confirmados}/${c.lugares} confirmados` : '';
    const presTxt = c.checkin_presentes>0 ? ` · <span style="color:var(--ok)">${c.checkin_presentes} no local</span>` : '';
    return `<div class="convite-row">
      <div class="selo-tipo ${c.tipo}" title="${c.tipo}">${iconeTipo(c.tipo)}</div>
      <div>
        <div class="convite-nome">${esc(c.nome_final)}</div>
        <div class="convite-meta">
          <span>${tagEstado(c.rsvp_estado)}</span>
          <span>${c.mesa_nome?('Mesa: '+esc(c.mesa_nome)):'Sem mesa'}</span>
          <span>Cód: <strong>${c.codigo}</strong></span>
          ${c.telefone?`<span>${esc(c.telefone)}</span>`:''}
          ${(c.rsvp_mensagem&&c.rsvp_mensagem.trim())?`<span class="tem-msg" title="Mensagem: ${esc(c.rsvp_mensagem)}" onclick="abrirMensagens()">${IC.balao}</span>`:''}
          <span class="lado-ic" title="Lado: ${c.lado}">${iconeLado(c.lado)}</span>
          <span style="color:#b7bbb5">${(confTxt+presTxt).replace(/^ · /,'')}</span>
        </div>
        <div class="membros-mini">${membros}</div>
      </div>
      <div class="acoes">
        <button class="btn-ico" title="Copiar link do convite digital" onclick="copiarLinkDireto('${c.codigo}')">Link</button>
        <button class="btn-ico" title="Ver convite digital" onclick="verConvite('${c.codigo}')">Ver</button>
        <button class="btn-ico" title="Descarregar convite (ver offline)" onclick="baixarConvite('${c.codigo}')">Baixar</button>
        <button class="btn-ico" title="QR" onclick="mostrarQR('${c.codigo}')">QR</button>
        ${c.tipo!=='digital'?`<button class="btn-ico" title="Marcar impresso" onclick="flag(${c.id},'impresso',${c.impresso?0:1})" style="${c.impresso?'background:var(--ok-bg);color:var(--ok)':''}">${c.impresso?'Impresso ✓':'Impresso'}</button>`:''}
        ${c.tipo!=='fisico'?`<button class="btn-ico" title="Marcar enviado" onclick="flag(${c.id},'enviado',${c.enviado?0:1})" style="${c.enviado?'background:var(--ok-bg);color:var(--ok)':''}">${c.enviado?'Enviado ✓':'Enviado'}</button>`:''}
        <button class="btn-ico" title="Editar" onclick="editar(${c.id})">Editar</button>
        <button class="btn-ico" title="Eliminar" onclick="eliminar(${c.id})">✕</button>
      </div>
    </div>`;
  }).join('');
}

function renderFiltroMesas(){
  const box=$('filtro-mesas'); if(!box) return;
  if(!MESAS.length){ box.innerHTML=''; return; }
  const chip=(v,l,on)=>`<button class="chip-m${on?' on':''}" onclick="filtrarMesa('${esc(v)}')">${l}</button>`;
  box.innerHTML = `<span class="chips-lbl">Mesa</span>`
    + chip('', 'Todas', !filtroMesa)
    + MESAS.map(m=>chip(m.nome, esc(m.nome)+`<span class="chip-n">${m.convites||0}</span>`, filtroMesa===m.nome)).join('');
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
  $('c-lugares').value = c?c.lugares:1;
  pickVal('c-tipo', c?c.tipo:'digital');
  pickVal('c-lado', c?c.lado:'noivo');
  pickVal('c-presenca', c?(c.rsvp_estado||'pendente'):'pendente');
  $('c-mesa').value = c?(c.mesa_nome||''):'';
  $('c-telefone').value = c?(c.telefone||''):'';
  $('c-obs').value = c?(c.observacoes||''):'';
  $('membros').innerHTML='';
  const ms = c&&c.membros&&c.membros.length ? c.membros : [{nome:'',rsvp:'confirmado'}];
  ms.forEach(m=>addMembro(m.nome||'', m.rsvp ? m.rsvp==='confirmado' : true));
  sincroPresencaMembros($('c-presenca').value);
  if(c){ $('bloco-link').style.display='block'; $('c-link').value=BASE+'/convite-digital.php?c='+c.codigo; $('c-link').dataset.codigo=c.codigo; }
  else { $('bloco-link').style.display='none'; }
  atualizarPrevia(); renderSugestoes();
  abrir('ov-convite');
}
function addMembro(valor='', vai=true){
  const div=document.createElement('div'); div.className='membro-linha';
  div.innerHTML=`<label class="m-vai" title="Esta pessoa confirma presença"><input type="checkbox" ${vai?'checked':''}></label>
    <input type="text" placeholder="Nome completo" value="${esc(valor)}" oninput="renderSugestoes()">
    <button class="btn-ico" type="button" onclick="this.parentElement.remove();renderSugestoes();atualizarPrevia()">✕</button>`;
  $('membros').appendChild(div);
}
function nomesMembros(){ return [...$('membros').querySelectorAll('input[type=text]')].map(i=>i.value.trim()).filter(Boolean); }
function membrosComPresenca(){
  return [...$('membros').querySelectorAll('.membro-linha')].map(row=>({
    nome: row.querySelector('input[type=text]').value.trim(),
    vai:  row.querySelector('.m-vai input')?.checked ?? true
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
    tipo:$('c-tipo').value, lado:$('c-lado').value, lugares:$('c-lugares').value,
    mesa:$('c-mesa').value, telefone:$('c-telefone').value, observacoes:$('c-obs').value,
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
  if(!confirm(`Eliminar o convite "${nome}"? Esta ação não pode ser anulada.`))return;
  const d=await api('convite_delete&id='+id); if(d.success){toast('Convite eliminado.');carregar();} }

function linkConvite(codigo){ return BASE+'/convite-digital.php?c='+codigo; }
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

montarPickers();
carregar();
</script>
</body>
</html>
