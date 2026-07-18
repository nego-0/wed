<?php
// ============================================================
// mesas.php — Planta de mesas (posição, capacidade e ocupação)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mesas · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
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

  /* Viewport com scroll (para o zoom) + planta que cresce dentro dela */
  .planta-viewport{ position:relative; overflow:auto; max-height:72vh; padding:18px; border-radius:14px;
    background:var(--ivory); border:1px solid var(--line); }
  .planta{ position:relative; --z:0.8; width:calc(var(--z)*100%); aspect-ratio:16/10;
    border-radius:14px; overflow:visible; transition:width .18s ease;
    background:
      linear-gradient(var(--ivory),var(--ivory)),
      repeating-linear-gradient(0deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px),
      repeating-linear-gradient(90deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px);
    background-clip:padding-box; border:1px dashed var(--gold-soft); touch-action:none; user-select:none; }

  /* Barra de zoom */
  .planta-ctrls{ display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
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
  .forma-noivos{ width:calc(var(--d)*1.22); height:calc(var(--d)*1.02); border-radius:16px;
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
  select.sel-conv{ width:100%; }
  .acoes-bloco{ display:flex; gap:.5rem; margin-top:.8rem; }
  .semmesa-chip{ display:inline-flex; align-items:center; gap:.35rem; background:var(--cream); border:1px solid var(--line);
    border-radius:50px; padding:.25rem .6rem; font-size:.8rem; margin:.15rem .2rem 0 0; }
  .rot{ font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#9aa09a; margin:.9rem 0 .3rem; }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div>
      <h1>Planta de Mesas</h1>
      <div class="sub"><?= escP($CAS['casal']) ?> · posição, capacidade e ocupação</div>
    </div>
    <nav class="nav">
      <a href="index.php">Painel</a>
      <a href="mesas.php" class="ativo">Mesas</a>
      <a href="impressos.php">Convites físicos</a>
      <a href="convite-editor.php">Convite digital</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="stats-mesa" id="stats"></div>

  <!-- ADICIONAR MESA (acima do canvas) -->
  <div class="barra-add">
    <input type="text" id="nova-nome" placeholder="Nova mesa (nome)">
    <input type="number" id="nova-cap" min="1" placeholder="Lugares">
    <div class="grp"><span class="lbl">Forma</span><div class="formas" id="nova-forma"></div></div>
    <div class="grp"><span class="lbl">Cor</span><div class="cores" id="nova-cor"></div></div>
    <button class="btn btn-ouro btn-sm" onclick="adicionarMesa()">+ Mesa</button>
  </div>

  <div class="layout">
    <!-- PLANTA -->
    <div class="planta-cartao">
      <div class="planta-topo">
        <span class="titulo">Disposição do salão</span>
        <div class="planta-ctrls">
          <div class="zoombar" id="zoombar" title="Nível de zoom">
            <button data-zoom="0.4" title="50% · vista ampla">50%</button>
            <button data-zoom="0.8" class="on" title="100% · vista panorâmica">100%</button>
            <button data-zoom="1.2" title="150% · vista de área">150%</button>
          </div>
        </div>
      </div>
      <div class="legenda" style="margin-bottom:.7rem">
        <span class="lg-vazia"><i></i>Vazia</span>
        <span class="lg-parcial"><i></i>A encher</span>
        <span class="lg-cheia"><i></i>Completa</span>
        <span class="lg-excede"><i></i>Excede</span>
      </div>
      <div class="planta-viewport" id="planta-viewport">
        <div class="planta" id="planta">
          <div class="guia gv" id="guia-v"></div>
          <div class="guia gh" id="guia-h"></div>
          <div class="dica-vazia" id="dica-vazia">Ainda não há mesas. Crie a primeira acima e arraste-a para a posição.</div>
        </div>
      </div>
      <p style="font-size:.78rem;color:#9aa09a;margin:.6rem 0 0">Arraste as mesas para as posicionar (alinham-se com linhas-guia). Toque numa mesa para ver os detalhes.</p>
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
const CSRF = <?= json_encode(csrfToken()) ?>;
const $=id=>document.getElementById(id);
const esc=s=>(s??'').toString().replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
function toast(m,e=false){const t=$('toast');t.textContent=m;t.className='toast mostrar'+(e?' erro':'');setTimeout(()=>t.className='toast',2400);}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
async function api(action, opts={}){
  if(opts.body && typeof opts.body==='string'){
    try{ const b=JSON.parse(opts.body); if(b&&typeof b==='object'&&!Array.isArray(b)){ b.ts=agora(); opts.body=JSON.stringify(b); } }catch(e){}
  }
  opts.headers=Object.assign({'X-CSRF-Token':CSRF},opts.headers||{});
  const r=await fetch('api.php?action='+action,opts); return r.json();
}

let MESAS=[], CONVITES=[], CONVIDADOS=[], SEL=null, novaForma='redonda', novaCor='neutra', activeTab='pessoas';
// Nível de zoom (fator aplicado ao canvas). A vista padrão (100%) foi reduzida em 20% -> 0.8.
let zoom=0.8;

function aplicarCanvas(){ $('planta').style.setProperty('--z', zoom); }
function setZoom(z){
  zoom=+z;
  document.querySelectorAll('#zoombar button').forEach(b=>b.classList.toggle('on', +b.dataset.zoom===zoom));
  aplicarCanvas();
}

const FORMAS=[['redonda','Redonda'],['oval','Oval'],['quadrada','Quadrada'],['retangular','Retangular'],['comprida','Comprida'],['ferradura','Ferradura']];
const CORES=[['neutra','Marfim'],['verde','Verde'],['ouro','Ouro'],['terracota','Terracota'],['azul','Azul'],['ameixa','Ameixa'],['rosa','Rosa'],['salva','Salva']];
const ESTADOS={
  pessoas:[['','Todos os estados'],['confirmado','Confirmados'],['pendente','Pendentes'],['recusado','Recusados']],
  convites:[['','Todos os estados'],['confirmado','Confirmados'],['parcial','Parciais'],['pendente','Pendentes'],['recusado','Recusados']]
};
const htmlFormas=sel=>FORMAS.map(([v,l])=>`<button type="button" data-forma="${v}" class="${v===sel?'on':''}" title="${l}"><span class="fsw fsw-${v}"></span></button>`).join('');
const htmlCores=sel=>CORES.map(([v,l])=>`<button type="button" data-cor="${v}" class="${v===sel?'on':''}" title="${l}"><span class="csw csw-${v}"></span></button>`).join('');
function ligarPicker(container, attr, cb){
  container.addEventListener('click', e=>{ const b=e.target.closest('button'); if(!b) return;
    container.querySelectorAll('button').forEach(x=>x.classList.remove('on')); b.classList.add('on'); if(cb) cb(b.dataset[attr]); });
}

// Normalização de ids (o MySQL devolve-os como texto)
const numOuNull=v=>(v===null||v===undefined||v==='')?null:+v;
const normMesas=a=>(a||[]).map(m=>({...m, id:+m.id, capacidade:numOuNull(m.capacidade),
  pos_x:numOuNull(m.pos_x), pos_y:numOuNull(m.pos_y), ocupacao:+m.ocupacao||0, convites:+m.convites||0,
  forma:m.forma||'redonda', cor:m.cor||'neutra', especial:m.especial||null, tamanho:m.tamanho||null}));
const normConvites=a=>(a||[]).map(c=>({...c, id:+c.id, mesa_id:numOuNull(c.mesa_id), lugares:+c.lugares||0}));
const normConvidados=a=>(a||[]).map(g=>({...g, id:+g.id, convite_id:+g.convite_id,
  mesa_pessoa:numOuNull(g.mesa_pessoa), mesa_convite:numOuNull(g.mesa_convite), mesa_efetiva_id:numOuNull(g.mesa_efetiva_id),
  papel:g.papel||null, mesa_efetiva_esp:g.mesa_efetiva_esp||null}));
const ehNoivos=m=>m&&m.especial==='noivos';
// Dimensão base (px) do nó da mesa: tamanho manual sobrepõe-se ao automático.
// A mesa dos noivos é propositadamente um pouco menor que as dos convidados.
function baseMesa(m){
  if(ehNoivos(m)) return 62;
  const t=m.tamanho;
  if(t==='p') return 62; if(t==='m') return 84; if(t==='g') return 108;
  const cap=+m.capacidade||4; return Math.max(58,Math.min(104, 58 + cap*3));
}

// ---------- carregar ----------
async function carregar(){
  const [dm,dc,dg]=await Promise.all([api('mesa_list'), api('convite_list'), api('convidado_list')]);
  if(!dm.success){ toast('Erro ao carregar mesas.',true); return; }
  MESAS=normMesas(dm.mesas); CONVITES=normConvites(dc&&dc.convites); CONVIDADOS=normConvidados(dg&&dg.convidados);
  aplicarCanvas();
  await autoPosicionar();
  renderTudo();
}
async function recarregarDados(){
  const [dc,dg]=await Promise.all([api('convite_list'), api('convidado_list')]);
  CONVITES=normConvites(dc&&dc.convites); CONVIDADOS=normConvidados(dg&&dg.convidados);
}
async function autoPosicionar(){
  const semPos=MESAS.filter(m=>m.pos_x===null||m.pos_x===undefined);
  if(!semPos.length) return;
  semPos.forEach((m,i)=>{ const col=i%4, row=Math.floor(i/4);
    m.pos_x=18+col*22; m.pos_y=20+row*22; if(m.pos_y>92) m.pos_y=92; });
  await Promise.all(semPos.map(m=>salvarPos(m.id,m.pos_x,m.pos_y)));
}

function renderTudo(){ renderStats(); renderPlanta(); renderTabs(); renderTabBody(); }

function renderStats(){
  const nMesas=MESAS.length;
  const capTotal=MESAS.reduce((s,m)=>s+(+m.capacidade||0),0);
  const sentados=MESAS.reduce((s,m)=>s+(+m.ocupacao||0),0);
  const livres=Math.max(0,capTotal-sentados);
  const totalLugares=CONVITES.reduce((s,c)=>s+(+c.lugares||0),0);
  const semMesa=Math.max(0, totalLugares-sentados);
  const card=(n,l,cls='')=>`<div class="sm ${cls}"><div class="n">${n}</div><div class="l">${l}</div></div>`;
  $('stats').innerHTML =
    card(nMesas,'Mesas')+card(capTotal||'—','Capacidade')+card(sentados,'Sentados','ok')+
    card(livres,'Lugares livres')+card(semMesa,'Sem mesa', semMesa>0?'alerta':'');
}

function estadoOcup(m){
  const oc=+m.ocupacao||0, cap=+m.capacidade||0;
  if(ehNoivos(m)) return oc>0?'cheia':'vazia'; // mesa de honra: sem alerta de "excede"
  if(cap>0 && oc>cap) return 'excede';
  if(cap>0 && oc===cap) return 'cheia';
  if(oc>0) return 'parcial';
  return 'vazia';
}

// ---------- planta ----------
function renderPlanta(){
  const planta=$('planta');
  planta.querySelectorAll('.mesa-node, .mesa-membros, .noivos-ala').forEach(n=>n.remove());
  $('dica-vazia').style.display = MESAS.length ? 'none' : 'flex';
  MESAS.forEach(m=>{
    const cap=+m.capacidade||0, oc=+m.ocupacao||0, st=estadoOcup(m);
    const noivos=ehNoivos(m);
    const d=baseMesa(m);
    const px=(+m.pos_x||50), py=(+m.pos_y||50);
    const node=document.createElement('div');
    const forma = noivos ? 'noivos' : (m.forma||'redonda');
    node.className='mesa-node forma-'+forma+' cor-'+(noivos?'noivos':(m.cor||'neutra'))+(SEL===m.id?' sel':'');
    node.dataset.id=m.id; node.style.setProperty('--dbase', d+'px');
    node.style.left=px+'%'; node.style.top=py+'%';
    if(noivos){
      node.innerHTML=`<span class="mn-dot dot-${st}"></span><span class="noivos-rings"><i></i><i></i></span>`+
        `<span class="mn-nome">${esc(m.nome)}</span><span class="mn-ocup">${oc||''}</span>`;
    } else {
      node.innerHTML=`<span class="mn-dot dot-${st}"></span><span class="mn-nome">${esc(m.nome)}</span><span class="mn-ocup">${oc}${cap?'/'+cap:''}</span>`;
    }
    planta.appendChild(node);

    if(noivos){
      // Alas de padrinhos (esquerda) e madrinhas (direita) — detetadas pelo papel do convidado.
      [['esq','Padrinhos','padrinho'],['dir','Madrinhas','madrinha']].forEach(([lado,tit,pap])=>{
        const gente=CONVIDADOS.filter(g=>g.papel===pap);
        if(!gente.length) return;
        const ala=document.createElement('div');
        ala.className='noivos-ala '+lado; ala.style.setProperty('--dbase', d+'px');
        ala.style.left=px+'%'; ala.style.top=py+'%';
        ala.innerHTML=`<div class="ala-tit">${tit}</div>`+gente.map(g=>{
          const prim=(g.nome||'').split(' ')[0];
          return `<div class="ala-p" data-id="${g.id}" title="${esc(g.nome)} — ${tit}">${esc(prim)}</div>`;
        }).join('');
        planta.appendChild(ala);
      });
      return; // a mesa dos noivos só tem padrinhos/madrinhas, nas alas
    }

    // Pastilhas de integrantes da mesa selecionada.
    if(SEL===m.id){
      const pessoas=CONVIDADOS.filter(g=>g.mesa_efetiva_id===m.id);
      if(pessoas.length){
        const cl=document.createElement('div');
        cl.className='mesa-membros'+(py>62?' acima':'');
        cl.style.setProperty('--dbase', d+'px'); cl.style.left=px+'%'; cl.style.top=py+'%';
        cl.innerHTML=pessoas.map(g=>{ const prim=(g.nome||'').split(' ')[0];
          return `<span class="mp" data-tipo="pessoa" data-id="${g.id}" data-label="${esc(g.nome)}" title="${esc(g.nome)} — arraste para outra mesa">${esc(prim)}</span>`;
        }).join('');
        planta.appendChild(cl);
      }
    }
  });
}

// ---------- arrastar mesas + linhas-guia magnéticas ----------
let drag=null;
$('planta').addEventListener('pointerdown', e=>{
  const node=e.target.closest('.mesa-node');
  if(!node){
    // Clique no fundo do canvas (nem mesa nem pastilha): sem arrastar, limpa a seleção.
    if(e.target.closest('.mp')) return;
    const sx=e.clientX, sy=e.clientY;
    window.addEventListener('pointerup', ev=>{
      if(Math.abs(ev.clientX-sx)<4 && Math.abs(ev.clientY-sy)<4) desselecionar();
    }, {once:true});
    return;
  }
  const rect=$('planta').getBoundingClientRect();
  drag={id:+node.dataset.id, node, rect, moved:false, sx:e.clientX, sy:e.clientY,
        x:+node.style.left.replace('%',''), y:+node.style.top.replace('%','')};
  node.classList.add('a-arrastar'); node.setPointerCapture?.(e.pointerId);
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp, {once:true});
});
function onMove(e){
  if(!drag) return;
  if(Math.abs(e.clientX-drag.sx)>3||Math.abs(e.clientY-drag.sy)>3) drag.moved=true;
  let x=(e.clientX-drag.rect.left)/drag.rect.width*100;
  let y=(e.clientY-drag.rect.top)/drag.rect.height*100;
  x=Math.max(6,Math.min(94,x)); y=Math.max(8,Math.min(92,y));
  const SNAP=1.6; let snapX=null, snapY=null;
  const alvosX=[50], alvosY=[50];
  MESAS.forEach(o=>{ if(o.id!==drag.id){ if(o.pos_x!=null)alvosX.push(o.pos_x); if(o.pos_y!=null)alvosY.push(o.pos_y); } });
  alvosX.forEach(vx=>{ if(Math.abs(vx-x)<SNAP && (snapX===null||Math.abs(vx-x)<Math.abs(snapX-x))) snapX=vx; });
  alvosY.forEach(vy=>{ if(Math.abs(vy-y)<SNAP && (snapY===null||Math.abs(vy-y)<Math.abs(snapY-y))) snapY=vy; });
  if(snapX!==null) x=snapX; if(snapY!==null) y=snapY;
  const gv=$('guia-v'), gh=$('guia-h');
  if(snapX!==null){ gv.style.left=snapX+'%'; gv.classList.add('on'); } else gv.classList.remove('on');
  if(snapY!==null){ gh.style.top=snapY+'%'; gh.classList.add('on'); } else gh.classList.remove('on');
  drag.node.style.left=x+'%'; drag.node.style.top=y+'%'; drag.x=x; drag.y=y;
}
function onUp(){
  window.removeEventListener('pointermove', onMove);
  $('guia-v').classList.remove('on'); $('guia-h').classList.remove('on');
  if(!drag) return;
  const d=drag; drag=null; d.node.classList.remove('a-arrastar');
  if(d.moved){
    const m=MESAS.find(x=>x.id===d.id); if(m){ m.pos_x=d.x; m.pos_y=d.y; }
    salvarPos(d.id, d.x, d.y); renderPlanta();
  } else { selecionar(d.id); }
}
async function salvarPos(id,x,y,forma){
  const body={id, x:+x.toFixed(2), y:+y.toFixed(2)}; if(forma) body.forma=forma;
  await api('mesa_pos',{method:'POST',body:JSON.stringify(body)});
}

// ---------- abas ----------
function selecionar(id){ SEL=id; activeTab='mesa'; renderPlanta(); renderTabs(); renderTabBody(); }
function desselecionar(){ if(SEL===null) return; SEL=null; if(activeTab==='mesa') activeTab='pessoas'; renderPlanta(); renderTabs(); renderTabBody(); }
function irTab(k){ if(k==='mesa'&&!SEL) return; activeTab=k; renderTabs(); renderTabBody(); }

function renderTabs(){
  const semMesaN=CONVIDADOS.filter(g=>g.mesa_efetiva_id==null).length;
  const selM=MESAS.find(x=>x.id===SEL);
  const abas=[['pessoas','Pessoas',CONVIDADOS.length],['convites','Convites',CONVITES.length],['semmesa','Sem mesa',semMesaN]];
  if(selM) abas.push(['mesa', selM.nome, selM.ocupacao]);
  else if(activeTab==='mesa') activeTab='pessoas';
  $('tabset-tabs').innerHTML = abas.map(([k,l,n])=>
    `<button class="rt ${activeTab===k?'on':''} ${k==='mesa'?'rt-mesa':''}" onclick="irTab('${k}')">${esc(l)}<span class="rt-n">${n}</span></button>`).join('');
}

function renderTabBody(){
  const body=$('tab-body');
  if(activeTab==='mesa' && SEL){ body.innerHTML=detalheHTML(); ligarDetalhe(body); return; }
  const comEstado=(activeTab==='pessoas'||activeTab==='convites');
  const ph = activeTab==='convites' ? 'Procurar convite, código…' : 'Procurar por nome…';
  body.innerHTML=`
    <div class="filtros-tab">
      <input type="search" id="busca-tab" placeholder="${ph}" oninput="renderLista()">
      ${comEstado?`<select id="estado-tab" onchange="renderLista()">${ESTADOS[activeTab].map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}</select>`:''}
    </div>
    <div class="lista-tab" id="lista-tab"></div>
    <div class="roster-conta" id="roster-conta"></div>
    <p style="font-size:.76rem;color:#9aa09a;margin:.6rem 0 0">Arraste um cartão para cima de uma mesa na planta.</p>`;
  renderLista();
}

function renderLista(){
  const box=$('lista-tab'); if(!box) return;
  const q=($('busca-tab')?$('busca-tab').value:'').trim().toLowerCase();
  const est=$('estado-tab')?$('estado-tab').value:'';
  let html='', total=0, unidade='';
  if(activeTab==='convites'){
    let itens=CONVITES.slice().sort((a,b)=>((a.mesa_id?1:0)-(b.mesa_id?1:0))||(a.nome_final||'').localeCompare(b.nome_final||''));
    itens=itens.filter(c=>(!est||c.rsvp_estado===est)
      && (!q || (c.nome_final||'').toLowerCase().includes(q) || (c.codigo||'').toLowerCase().includes(q)
             || (c.membros||[]).some(n=>(n||'').toLowerCase().includes(q))));
    total=itens.length; unidade=total===1?' convite':' convites';
    html=itens.map(c=>{
      const sem=c.mesa_id==null, div=(+c.mesas_distintas>1);
      const meta=div?('Dividido · '+c.mesas_distintas+' mesas'):(c.mesa_nome?('Mesa: '+esc(c.mesa_nome)):'sem mesa');
      return `<div class="chip-drag ${sem?'sem-mesa':''}" data-tipo="convite" data-id="${c.id}" data-label="${esc(c.nome_final)}">
        <span class="cd-nome">${esc(c.nome_final)}</span><span class="cd-meta">${c.lugares} lug. · ${meta}</span></div>`;
    }).join('');
  } else {
    let itens=CONVIDADOS.slice().sort((a,b)=>((a.mesa_efetiva_id?1:0)-(b.mesa_efetiva_id?1:0))||(a.nome||'').localeCompare(b.nome||''));
    if(activeTab==='semmesa') itens=itens.filter(g=>g.mesa_efetiva_id==null);
    itens=itens.filter(g=>(!est||g.rsvp===est)
      && (!q || (g.nome||'').toLowerCase().includes(q) || (g.convite_nome||'').toLowerCase().includes(q)));
    total=itens.length; unidade=total===1?' pessoa':' pessoas';
    html=itens.map(g=>{
      const sem=g.mesa_efetiva_id==null;
      const meta=g.mesa_efetiva_nome?('em '+esc(g.mesa_efetiva_nome)):'sem mesa';
      return `<div class="chip-drag ${sem?'sem-mesa':''}" data-tipo="pessoa" data-id="${g.id}" data-label="${esc(g.nome)}">
        <span class="cd-nome">${esc(g.nome)}</span><span class="cd-meta">${esc(g.convite_nome)} · ${meta}</span></div>`;
    }).join('');
  }
  box.innerHTML = html || `<div class="roster-vazio">${activeTab==='semmesa'?'Está tudo sentado. 🎉':'Nada corresponde aos filtros.'}</div>`;
  const cont=$('roster-conta'); if(cont) cont.textContent = total+unidade;
}

// ---------- detalhe da mesa (compacto, in-line) ----------
function detalheHTML(){
  const m=MESAS.find(x=>x.id===SEL); if(!m) return '';
  const cap=+m.capacidade||0, oc=+m.ocupacao||0;
  const perc=cap?Math.min(100,Math.round(oc/cap*100)):(oc?100:0);
  const barCls=cap&&oc>cap?'excede':(cap&&oc>=cap?'cheio':'');
  const pessoas=CONVIDADOS.filter(g=>g.mesa_efetiva_id===m.id);
  const notas=CONVITES.filter(c=>+c.mesa_id===m.id).map(c=>{
    const nomeados=CONVIDADOS.filter(g=>g.convite_id===c.id).length;
    const extra=Math.max(0,(+c.lugares||0)-nomeados);
    return extra>0?{nome:c.nome_final,extra}:null;
  }).filter(Boolean);
  const outras=CONVIDADOS.filter(g=>g.mesa_efetiva_id!==m.id)
    .sort((a,b)=>((a.mesa_efetiva_id?1:0)-(b.mesa_efetiva_id?1:0))||(a.convite_nome||'').localeCompare(b.convite_nome||''));
  const convFora=CONVITES.filter(c=>+c.mesa_id!==m.id)
    .sort((a,b)=>((a.mesa_id?1:0)-(b.mesa_id?1:0))||(a.nome_final||'').localeCompare(b.nome_final||''));
  const convAqui=CONVITES.filter(c=>+c.mesa_id===m.id);
  const optOutrasMesas=g=>MESAS.filter(x=>!ehNoivos(x)).map(x=>`<option value="${x.id}" ${String(g.mesa_pessoa)===String(x.id)?'selected':''}>${esc(x.nome)}</option>`).join('');
  const optTam=t=>['','Automático','p','Pequena','m','Média','g','Grande'].reduce((o,v,i,a)=>i%2?o:o+`<option value="${v}" ${(m.tamanho||'')===v?'selected':''}>${a[i+1]}</option>`,'');

  if(ehNoivos(m)) return detalheNoivos(m, cap, oc, perc, barCls, pessoas, notas, outras);

  return `
    <div class="mesa-form">
      <input type="text" id="ed-nome" value="${esc(m.nome)}" placeholder="Nome da mesa">
      <input type="number" id="ed-cap" min="1" value="${cap||''}" placeholder="Lug.">
      <button class="btn btn-fantasma btn-sm" onclick="guardarMesaEd()">Guardar</button>
    </div>
    <div class="mesa-form" style="margin-top:.55rem">
      <div class="grp" style="display:flex;align-items:center;gap:.4rem"><span class="rot" style="margin:0">Forma</span><div class="formas" id="ed-forma">${htmlFormas(m.forma)}</div></div>
      <div class="grp" style="display:flex;align-items:center;gap:.4rem"><span class="rot" style="margin:0">Cor</span><div class="cores" id="ed-cor">${htmlCores(m.cor||'neutra')}</div></div>
      <div class="grp" style="display:flex;align-items:center;gap:.4rem"><span class="rot" style="margin:0">Dimensão</span>
        <select id="ed-tam" class="sel-mini" title="Dimensão da mesa" onchange="guardarMesaEd()">${optTam(m.tamanho)}</select></div>
    </div>

    <div style="margin-top:1rem">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <strong style="font-family:var(--serif);color:var(--ink)">Ocupação</strong>
        <span style="font-size:.9rem;color:${oc>cap&&cap?'var(--danger)':'#7a8078'}">${oc}${cap?' / '+cap+' lugares':' lugares'}${cap&&oc>cap?' · excede!':''}</span>
      </div>
      <div class="barra-ocup"><span class="${barCls}" style="width:${perc}%"></span></div>
    </div>

    <div class="rot">Pessoas nesta mesa (${pessoas.length})</div>
    <div class="lista-sentados">
      ${pessoas.length ? pessoas.map(g=>`
        <div class="sentado">
          <span class="nm">${esc(g.nome)}<br><small style="color:#9aa09a">${esc(g.convite_nome)}</small></span>
          <select class="sel-mini" title="Mudar de mesa" onchange="moverPessoa(${g.id}, this.value)">
            <option value="" ${g.mesa_pessoa==null?'selected':''}>${g.mesa_convite_nome?('Segue o convite ('+esc(g.mesa_convite_nome)+')'):'Sem mesa'}</option>
            ${optOutrasMesas(g)}
          </select>
        </div>`).join('') : '<div class="vazio-mini">Ainda ninguém sentado nesta mesa.</div>'}
      ${notas.map(n=>`<div class="vazio-mini">+ ${n.extra} lugar(es) sem nome · ${esc(n.nome)}</div>`).join('')}
    </div>

    <div class="rot">Trazer uma pessoa para esta mesa</div>
    <select class="sel-conv" onchange="trazerPessoa(this.value); this.value=''">
      <option value="">Escolher pessoa…</option>
      ${outras.map(g=>`<option value="${g.id}">${esc(g.nome)} · ${esc(g.convite_nome)}${g.mesa_efetiva_nome?(' (em '+esc(g.mesa_efetiva_nome)+')'):' (sem mesa)'}</option>`).join('')}
    </select>

    <div class="rot">Sentar convite inteiro nesta mesa</div>
    <select class="sel-conv" onchange="sentar(this.value); this.value=''">
      <option value="">Escolher convite…</option>
      ${convFora.map(c=>`<option value="${c.id}">${esc(c.nome_final)} · ${c.lugares} lug.${c.mesa_id?(' (Mesa: '+esc(c.mesa_nome||'')+')'):' (sem mesa)'}</option>`).join('')}
    </select>
    ${convAqui.length?`<div style="margin-top:.4rem">${convAqui.map(c=>`<span class="semmesa-chip">${esc(c.nome_final)}<button class="btn-ico" title="Retirar convite da mesa" onclick="retirarConvite(${c.id})">✕</button></span>`).join('')}</div>`:''}

    <div class="acoes-bloco">
      <button class="btn btn-fantasma btn-sm" style="flex:1;justify-content:center;color:var(--danger);border-color:#e6c3bf" onclick="eliminar(${m.id})">Eliminar mesa</button>
    </div>`;
}
// Painel da mesa dos noivos: alas detetadas pelo papel (padrinho/madrinha).
function detalheNoivos(m, cap, oc, perc, barCls, pessoas, notas, outras){
  const padrinhos=CONVIDADOS.filter(g=>g.papel==='padrinho');
  const madrinhas=CONVIDADOS.filter(g=>g.papel==='madrinha');
  const linhaPapel=g=>`
    <div class="sentado">
      <span class="nm">${esc(g.nome)}<br><small style="color:#9aa09a">${esc(g.convite_nome)}</small></span>
      <select class="sel-mini" title="Papel do convidado" onchange="definirPapel(${g.id}, this.value)">
        <option value="padrinho" ${g.papel==='padrinho'?'selected':''}>Padrinho (esq.)</option>
        <option value="madrinha" ${g.papel==='madrinha'?'selected':''}>Madrinha (dir.)</option>
        <option value="">Remover papel</option>
      </select>
    </div>`;
  const bloco=(tit,arr)=>`<div class="rot">${tit} (${arr.length})</div>
    <div class="lista-sentados">${arr.length?arr.map(linhaPapel).join(''):'<div class="vazio-mini">Ainda ninguém.</div>'}</div>`;
  const candP=CONVIDADOS.filter(g=>g.papel!=='padrinho');
  const candM=CONVIDADOS.filter(g=>g.papel!=='madrinha');
  const optCand=arr=>arr.map(g=>`<option value="${g.id}">${esc(g.nome)} · ${esc(g.convite_nome)}</option>`).join('');
  return `
    <div class="mesa-form">
      <input type="text" id="ed-nome" value="${esc(m.nome)}" placeholder="Nome">
      <button class="btn btn-fantasma btn-sm" onclick="guardarMesaEd()">Guardar</button>
    </div>
    <p style="font-size:.8rem;color:#7a8078;margin:.55rem 0 .2rem">Mesa de honra dos noivos ⚭. Só entram <b>padrinhos</b> (ala esquerda) e <b>madrinhas</b> (ala direita), detetados automaticamente pelo <b>papel</b> de cada convidado. O papel também se define no editor do convite.</p>
    <div class="barra-ocup" style="margin:.5rem 0"><span class="${barCls}" style="width:${perc}%"></span></div>
    ${bloco('Padrinhos · ala esquerda', padrinhos)}
    ${bloco('Madrinhas · ala direita', madrinhas)}

    <div class="rot">Nomear padrinho / madrinha</div>
    <div class="mesa-form">
      <select class="sel-conv" style="flex:1 1 120px" onchange="definirPapel(this.value,'padrinho'); this.value=''">
        <option value="">+ Padrinho…</option>${optCand(candP)}</select>
      <select class="sel-conv" style="flex:1 1 120px" onchange="definirPapel(this.value,'madrinha'); this.value=''">
        <option value="">+ Madrinha…</option>${optCand(candM)}</select>
    </div>`;
}
async function definirPapel(gid, papel){
  gid=+gid; if(!gid) return;
  const d=await api('convidado_papel',{method:'POST',body:JSON.stringify({id:gid, papel})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo();
  toast(papel==='padrinho'?'Padrinho (ala esquerda).':papel==='madrinha'?'Madrinha (ala direita).':'Papel removido.');
}
function ligarDetalhe(box){
  const f=box.querySelector('#ed-forma'), c=box.querySelector('#ed-cor');
  if(f) ligarPicker(f,'forma');
  if(c) ligarPicker(c,'cor');
}

// ---------- ações ----------
async function adicionarMesa(){
  const nome=$('nova-nome').value.trim(); if(!nome) return toast('Indique o nome da mesa.',true);
  const cap=$('nova-cap').value;
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:0,nome,capacidade:cap,forma:novaForma,cor:novaCor})});
  if(!d.success) return toast(d.message||'Erro ao guardar.',true);
  $('nova-nome').value=''; $('nova-cap').value='';
  MESAS=normMesas(d.mesas);
  await autoPosicionar();
  SEL=d.id||null; activeTab='mesa';
  renderTudo();
  toast('Mesa criada. Arraste-a para a posição.');
}
async function guardarMesaEd(){
  const m=MESAS.find(x=>x.id===SEL); if(!m) return;
  const nome=$('ed-nome').value.trim(); if(!nome) return toast('Indique o nome da mesa.',true);
  const cap=$('ed-cap').value;
  const fb=document.querySelector('#ed-forma button.on'), cb=document.querySelector('#ed-cor button.on');
  const forma=fb?fb.dataset.forma:'redonda', cor=cb?cb.dataset.cor:'neutra';
  const tamanho=$('ed-tam')?$('ed-tam').value:'';
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:m.id,nome,capacidade:cap,forma,cor,tamanho})});
  if(!d.success) return toast(d.message||'Erro ao guardar.',true);
  MESAS=normMesas(d.mesas); renderTudo(); toast('Mesa atualizada.');
}
async function eliminar(id){
  const m=MESAS.find(x=>x.id===id); const nome=m?m.nome:'esta mesa';
  if(!confirm(`Eliminar a mesa "${nome}"? Os convites e pessoas sentados ficam sem mesa.`)) return;
  const d=await api('mesa_delete&id='+id);
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); if(SEL===id){ SEL=null; activeTab='pessoas'; }
  await recarregarDados(); renderTudo(); toast('Mesa eliminada.');
}
async function sentar(conviteId){
  conviteId=+conviteId; if(!conviteId||!SEL) return;
  const d=await api('convite_mesa',{method:'POST',body:JSON.stringify({id:conviteId,mesa_id:SEL})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo(); toast('Convite sentado nesta mesa.');
}
async function retirarConvite(conviteId){
  const d=await api('convite_mesa',{method:'POST',body:JSON.stringify({id:conviteId,mesa_id:''})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo(); toast('Convite retirado da mesa.');
}
async function moverPessoa(gid, val){
  const d=await api('convidado_mesa',{method:'POST',body:JSON.stringify({id:+gid, mesa_id: val===''?'':+val})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo(); toast('Lugar atualizado.');
}
async function trazerPessoa(gid){
  gid=+gid; if(!gid||!SEL) return;
  const d=await api('convidado_mesa',{method:'POST',body:JSON.stringify({id:gid, mesa_id:SEL})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo(); toast('Pessoa trazida para esta mesa.');
}

// ---------- arrastar cartões/pastilhas para uma mesa ----------
let pend=null, ghost=null, arrItem=null;
function iniciarArrasteDe(e, el){
  pend={tipo:el.dataset.tipo, id:+el.dataset.id, label:el.dataset.label, chip:el, sx:e.clientX, sy:e.clientY};
  window.addEventListener('pointermove', talvezArrastar);
  window.addEventListener('pointerup', cancelarArme, {once:true});
}
$('tab-body').addEventListener('pointerdown', e=>{ const chip=e.target.closest('.chip-drag'); if(chip) iniciarArrasteDe(e, chip); });
$('planta').addEventListener('pointerdown', e=>{ const mp=e.target.closest('.mp'); if(mp) iniciarArrasteDe(e, mp); });
function talvezArrastar(e){
  if(!pend) return;
  if(Math.abs(e.clientX-pend.sx)>5 || Math.abs(e.clientY-pend.sy)>5){
    window.removeEventListener('pointermove', talvezArrastar);
    window.removeEventListener('pointerup', cancelarArme);
    comecarArraste(e);
  }
}
function cancelarArme(){ pend=null; window.removeEventListener('pointermove', talvezArrastar); }
function comecarArraste(e){
  arrItem={tipo:pend.tipo, id:pend.id}; pend.chip.classList.add('arrastando');
  document.body.classList.add('a-arrastar-item');
  ghost=document.createElement('div'); ghost.className='ghost-drag'; ghost.textContent=pend.label;
  document.body.appendChild(ghost); posGhost(e);
  window.addEventListener('pointermove', moverArraste);
  window.addEventListener('pointerup', largarArraste, {once:true});
  pend=null;
}
function posGhost(e){ if(ghost){ ghost.style.left=e.clientX+'px'; ghost.style.top=e.clientY+'px'; } }
function mesaSob(e){ const el=document.elementFromPoint(e.clientX,e.clientY); return el&&el.closest('.mesa-node'); }
function moverArraste(e){
  posGhost(e);
  let node=mesaSob(e);
  if(node && ehNoivos(MESAS.find(x=>x.id===+node.dataset.id))) node=null; // noivos não aceita arrasto
  document.querySelectorAll('.mesa-node.drop-alvo').forEach(n=>{ if(n!==node) n.classList.remove('drop-alvo'); });
  if(node) node.classList.add('drop-alvo');
}
async function largarArraste(e){
  window.removeEventListener('pointermove', moverArraste);
  document.body.classList.remove('a-arrastar-item');
  const node=mesaSob(e);
  document.querySelectorAll('.mesa-node.drop-alvo').forEach(n=>n.classList.remove('drop-alvo'));
  document.querySelectorAll('.chip-drag.arrastando, .mp.arrastando').forEach(c=>c.classList.remove('arrastando'));
  if(ghost){ ghost.remove(); ghost=null; }
  const item=arrItem; arrItem=null;
  if(!node || !item) return;
  const mid=+node.dataset.id;
  const alvo=MESAS.find(x=>x.id===mid);
  if(ehNoivos(alvo)) return toast('Na mesa dos noivos só entram padrinhos e madrinhas (pelo papel).',true);
  const acao = item.tipo==='pessoa' ? 'convidado_mesa' : 'convite_mesa';
  const d=await api(acao,{method:'POST',body:JSON.stringify({id:item.id, mesa_id:mid})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=normMesas(d.mesas); await recarregarDados(); renderTudo();
  const m=MESAS.find(x=>x.id===mid);
  toast((item.tipo==='pessoa'?'Pessoa':'Convite')+' → '+(m?m.nome:'mesa'));
}

// Inicializar seletores da barra de adicionar
$('nova-forma').innerHTML=htmlFormas('redonda');
$('nova-cor').innerHTML=htmlCores('neutra');
ligarPicker($('nova-forma'),'forma',v=>novaForma=v);
ligarPicker($('nova-cor'),'cor',v=>novaCor=v);
$('zoombar').addEventListener('click', e=>{ const b=e.target.closest('button'); if(b) setZoom(b.dataset.zoom); });
aplicarCanvas();

carregar();
</script>
</body>
</html>
