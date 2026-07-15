<?php
// ============================================================
// mesas.php — Planta de mesas (posição, capacidade e ocupação)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
exigirAdmin();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mesas · Isabel &amp; Abednego</title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<style>
  .layout{ display:grid; grid-template-columns:1fr 340px; gap:1.1rem; align-items:start; }
  @media (max-width:860px){ .layout{ grid-template-columns:1fr; } }

  /* Barra de estatísticas */
  .stats-mesa{ display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.7rem; margin-bottom:1.1rem; }
  .sm{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:.8rem .7rem; text-align:center; }
  .sm .n{ font-family:var(--serif); font-size:1.7rem; font-weight:700; color:var(--ink); line-height:1; }
  .sm .l{ font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#8a8f88; margin-top:.25rem; }
  .sm.ok .n{ color:#1f7a3d; } .sm.alerta .n{ color:var(--danger); }

  /* Planta */
  .planta-cartao{ background:#fff; border:1px solid var(--line); border-radius:16px; padding:1rem; }
  .planta-topo{ display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:.8rem; }
  .planta-topo .titulo{ font-family:var(--serif); font-size:1.2rem; color:var(--ink); font-weight:600; flex:1; }
  .legenda{ display:flex; gap:.8rem; flex-wrap:wrap; font-size:.76rem; color:#7a8078; }
  .legenda i{ width:12px; height:12px; border-radius:50%; display:inline-block; vertical-align:-1px; margin-right:.3rem; border:2px solid; }
  .lg-vazia i{ background:var(--cream); border-color:var(--gold-soft); }
  .lg-parcial i{ background:var(--gold-pale); border-color:var(--gold); }
  .lg-cheia i{ background:#e4f3e9; border-color:#1f7a3d; }
  .lg-excede i{ background:#f7e5e3; border-color:var(--danger); }

  .planta{ position:relative; width:100%; aspect-ratio:16/10; border-radius:14px; overflow:hidden;
    background:
      linear-gradient(var(--ivory),var(--ivory)),
      repeating-linear-gradient(0deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px),
      repeating-linear-gradient(90deg, transparent 0 39px, rgba(44,69,54,.05) 39px 40px);
    border:1px dashed var(--gold-soft); touch-action:none; user-select:none; }
  .planta .dica-vazia{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    color:#a7ad9f; font-size:.9rem; text-align:center; padding:1rem; }

  .mesa-node{ position:absolute; transform:translate(-50%,-50%); cursor:grab;
    display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
    border:3px solid var(--gold-soft); background:var(--cream); color:var(--ink);
    box-shadow:0 4px 12px rgba(22,38,30,.12); transition:box-shadow .15s, border-color .15s, background .15s; }
  .mesa-node.redonda{ width:var(--d); height:var(--d); border-radius:50%; }
  .mesa-node.retangular{ width:calc(var(--d) * 1.5); height:calc(var(--d) * .72); border-radius:12px; }
  .mesa-node.a-arrastar{ cursor:grabbing; box-shadow:0 10px 26px rgba(22,38,30,.28); z-index:20; }
  .mesa-node.sel{ outline:3px solid var(--forest); outline-offset:3px; z-index:15; }
  .mesa-node .mn-nome{ font-family:var(--serif); font-weight:600; font-size:.86rem; line-height:1.05;
    max-width:92%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .mesa-node .mn-ocup{ font-size:.78rem; margin-top:.1rem; font-variant-numeric:tabular-nums; }
  .mesa-node.parcial{ background:var(--gold-pale); border-color:var(--gold); }
  .mesa-node.cheia{ background:#e4f3e9; border-color:#1f7a3d; color:#17311f; }
  .mesa-node.excede{ background:#f7e5e3; border-color:var(--danger); color:#7d332d; }

  /* Painel lateral */
  .painel{ display:flex; flex-direction:column; gap:1rem; }
  .bloco{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1rem 1.1rem; }
  .bloco h3{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); margin:0 0 .7rem; }
  .campo-mesa{ margin-bottom:.7rem; }
  .campo-mesa label{ display:block; font-size:.82rem; color:#7a8078; margin-bottom:.25rem; }
  .forma-pick{ display:flex; gap:.5rem; }
  .forma-pick button{ flex:1; border:1.5px solid var(--line); background:#fff; border-radius:10px; padding:.5rem; cursor:pointer;
    font-family:inherit; font-size:.82rem; color:var(--text); display:flex; align-items:center; justify-content:center; gap:.4rem; }
  .forma-pick button .amostra{ background:var(--gold-soft); display:inline-block; width:20px; height:14px; }
  .forma-pick button .amostra.r{ border-radius:50%; width:16px; height:16px; }
  .forma-pick button.on{ border-color:var(--forest); background:var(--cream); color:var(--ink); font-weight:500; }
  .barra-ocup{ height:8px; background:var(--cream); border-radius:50px; overflow:hidden; margin:.5rem 0; }
  .barra-ocup span{ display:block; height:100%; background:var(--gold); }
  .barra-ocup span.cheio{ background:#1f7a3d; } .barra-ocup span.excede{ background:var(--danger); }
  .lista-sentados{ display:flex; flex-direction:column; gap:.4rem; margin:.4rem 0 .2rem; }
  .sentado{ display:flex; align-items:center; gap:.5rem; border:1px solid var(--line); border-radius:10px; padding:.4rem .6rem; font-size:.9rem; }
  .sentado .nm{ flex:1; }
  .sentado .lg{ font-size:.75rem; color:#8a8f88; }
  .vazio-mini{ color:#9aa09a; font-size:.86rem; padding:.3rem 0; }
  .painel-vazio{ color:#9aa09a; text-align:center; font-size:.9rem; padding:1.4rem .5rem; }
  select.sel-conv{ width:100%; }
  .acoes-bloco{ display:flex; gap:.5rem; margin-top:.8rem; }
  .semmesa-chip{ display:inline-flex; align-items:center; gap:.35rem; background:var(--cream); border:1px solid var(--line);
    border-radius:50px; padding:.25rem .6rem; font-size:.8rem; margin:.15rem .2rem 0 0; }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma">I&amp;A</div>
    <div>
      <h1>Planta de Mesas</h1>
      <div class="sub">Isabel &amp; Abednego · posição, capacidade e ocupação</div>
    </div>
    <nav class="nav">
      <a href="index.php">Painel</a>
      <a href="mesas.php" class="ativo">Mesas</a>
      <a href="impressos.php">Convites físicos</a>
      <a href="porteiro.php">Porta</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="stats-mesa" id="stats"></div>

  <div class="layout">
    <!-- PLANTA -->
    <div class="planta-cartao">
      <div class="planta-topo">
        <span class="titulo">Disposição do salão</span>
        <span class="legenda">
          <span class="lg-vazia"><i></i>Vazia</span>
          <span class="lg-parcial"><i></i>A encher</span>
          <span class="lg-cheia"><i></i>Completa</span>
          <span class="lg-excede"><i></i>Excede</span>
        </span>
      </div>
      <div class="planta" id="planta">
        <div class="dica-vazia" id="dica-vazia">Ainda não há mesas. Crie a primeira no painel à direita e arraste-a para a posição.</div>
      </div>
      <p style="font-size:.78rem;color:#9aa09a;margin:.6rem 0 0">Arraste as mesas para as posicionar. Toque numa mesa para ver e editar os detalhes.</p>
    </div>

    <!-- PAINEL -->
    <div class="painel">
      <!-- Adicionar -->
      <div class="bloco">
        <h3>Adicionar mesa</h3>
        <div class="campo-mesa">
          <label>Nome</label>
          <input type="text" id="nova-nome" placeholder="Ex: Mesa 1, Honra, Família…">
        </div>
        <div class="campo-mesa">
          <label>Capacidade (lugares)</label>
          <input type="number" id="nova-cap" min="1" placeholder="opcional">
        </div>
        <div class="campo-mesa">
          <label>Forma</label>
          <div class="forma-pick" id="nova-forma">
            <button type="button" data-forma="redonda" class="on"><span class="amostra r"></span>Redonda</button>
            <button type="button" data-forma="retangular"><span class="amostra"></span>Retangular</button>
          </div>
        </div>
        <button class="btn btn-ouro" style="width:100%;justify-content:center" onclick="adicionarMesa()">+ Adicionar mesa</button>
      </div>

      <!-- Detalhe da mesa selecionada -->
      <div class="bloco" id="detalhe">
        <div class="painel-vazio">Toque numa mesa da planta para ver quem está sentado, ajustar a capacidade ou removê-la.</div>
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

let MESAS=[], CONVITES=[], SEL=null, novaForma='redonda';

// ---------- carregar ----------
async function carregar(){
  const [dm,dc]=await Promise.all([api('mesa_list'), api('convite_list')]);
  if(!dm.success){ toast('Erro ao carregar mesas.',true); return; }
  MESAS=dm.mesas||[]; CONVITES=(dc&&dc.convites)||[];
  await autoPosicionar();
  renderTudo();
}

// Coloca em grelha as mesas ainda sem posição e persiste-as.
async function autoPosicionar(){
  const semPos=MESAS.filter(m=>m.pos_x===null||m.pos_x===undefined);
  if(!semPos.length) return;
  semPos.forEach((m,i)=>{
    const col=i%4, row=Math.floor(i/4);
    m.pos_x=18+col*22; m.pos_y=20+row*22;
    if(m.pos_y>92) m.pos_y=92;
  });
  await Promise.all(semPos.map(m=>salvarPos(m.id,m.pos_x,m.pos_y)));
}

function renderTudo(){ renderStats(); renderPlanta(); renderDetalhe(); }

// ---------- estatísticas ----------
function renderStats(){
  const nMesas=MESAS.length;
  const capTotal=MESAS.reduce((s,m)=>s+(+m.capacidade||0),0);
  const sentados=MESAS.reduce((s,m)=>s+(+m.ocupacao||0),0);
  const livres=Math.max(0,capTotal-sentados);
  const excede=MESAS.some(m=>m.capacidade && (+m.ocupacao> +m.capacidade));
  const semMesa=CONVITES.filter(c=>!c.mesa_id).reduce((s,c)=>s+(+c.lugares||0),0);
  const card=(n,l,cls='')=>`<div class="sm ${cls}"><div class="n">${n}</div><div class="l">${l}</div></div>`;
  $('stats').innerHTML =
    card(nMesas,'Mesas')+
    card(capTotal||'—','Capacidade')+
    card(sentados,'Sentados','ok')+
    card(livres,'Lugares livres')+
    card(semMesa,'Sem mesa', semMesa>0?'alerta':'');
}

// ---------- classe/cor por ocupação ----------
function classeOcup(m){
  const oc=+m.ocupacao||0, cap=+m.capacidade||0;
  if(cap>0 && oc>cap) return 'excede';
  if(cap>0 && oc===cap) return 'cheia';
  if(oc>0) return 'parcial';
  return '';
}

// ---------- planta ----------
function renderPlanta(){
  const planta=$('planta');
  planta.querySelectorAll('.mesa-node').forEach(n=>n.remove());
  $('dica-vazia').style.display = MESAS.length ? 'none' : 'flex';
  MESAS.forEach(m=>{
    const cap=+m.capacidade||0, oc=+m.ocupacao||0;
    const d=Math.max(58,Math.min(104, 58 + (cap||4)*3));
    const node=document.createElement('div');
    node.className='mesa-node '+(m.forma==='retangular'?'retangular':'redonda')+' '+classeOcup(m)+(SEL===m.id?' sel':'');
    node.dataset.id=m.id;
    node.style.setProperty('--d', d+'px');
    node.style.left=(+m.pos_x||50)+'%';
    node.style.top=(+m.pos_y||50)+'%';
    node.innerHTML=`<span class="mn-nome">${esc(m.nome)}</span>
      <span class="mn-ocup">${oc}${cap?'/'+cap:''}</span>`;
    planta.appendChild(node);
  });
}

// ---------- arrastar ----------
let drag=null;
$('planta').addEventListener('pointerdown', e=>{
  const node=e.target.closest('.mesa-node'); if(!node) return;
  const rect=$('planta').getBoundingClientRect();
  drag={id:+node.dataset.id, node, rect, moved:false, sx:e.clientX, sy:e.clientY,
        x:+node.style.left.replace('%',''), y:+node.style.top.replace('%','')};
  node.classList.add('a-arrastar');
  node.setPointerCapture?.(e.pointerId);
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp, {once:true});
});
function onMove(e){
  if(!drag) return;
  if(Math.abs(e.clientX-drag.sx)>3||Math.abs(e.clientY-drag.sy)>3) drag.moved=true;
  let x=(e.clientX-drag.rect.left)/drag.rect.width*100;
  let y=(e.clientY-drag.rect.top)/drag.rect.height*100;
  x=Math.max(6,Math.min(94,x)); y=Math.max(8,Math.min(92,y));
  drag.node.style.left=x+'%'; drag.node.style.top=y+'%'; drag.x=x; drag.y=y;
}
function onUp(){
  window.removeEventListener('pointermove', onMove);
  if(!drag) return;
  const d=drag; drag=null; d.node.classList.remove('a-arrastar');
  if(d.moved){
    const m=MESAS.find(x=>x.id===d.id); if(m){ m.pos_x=d.x; m.pos_y=d.y; }
    salvarPos(d.id, d.x, d.y);
  } else {
    selecionar(d.id);
  }
}
async function salvarPos(id,x,y,forma){
  const body={id, x:+x.toFixed(2), y:+y.toFixed(2)}; if(forma) body.forma=forma;
  await api('mesa_pos',{method:'POST',body:JSON.stringify(body)});
}

// ---------- seleção / detalhe ----------
function selecionar(id){ SEL=id; renderPlanta(); renderDetalhe(); }

function renderDetalhe(){
  const box=$('detalhe');
  const m=MESAS.find(x=>x.id===SEL);
  if(!m){ box.innerHTML='<div class="painel-vazio">Toque numa mesa da planta para ver quem está sentado, ajustar a capacidade ou removê-la.</div>'; return; }
  const cap=+m.capacidade||0, oc=+m.ocupacao||0;
  const perc=cap?Math.min(100,Math.round(oc/cap*100)):(oc?100:0);
  const barCls=cap&&oc>cap?'excede':(cap&&oc>=cap?'cheio':'');
  const sentados=CONVITES.filter(c=>+c.mesa_id===m.id);
  const disponiveis=CONVITES.filter(c=>+c.mesa_id!==m.id)
    .sort((a,b)=>(a.mesa_id?1:0)-(b.mesa_id?1:0) || (a.nome_final||'').localeCompare(b.nome_final||''));

  box.innerHTML=`
    <h3>${esc(m.nome)}</h3>
    <div class="campo-mesa"><label>Nome</label><input type="text" id="ed-nome" value="${esc(m.nome)}"></div>
    <div class="campo-mesa"><label>Capacidade (lugares)</label><input type="number" id="ed-cap" min="1" value="${cap||''}" placeholder="opcional"></div>
    <div class="campo-mesa"><label>Forma</label>
      <div class="forma-pick" id="ed-forma">
        <button type="button" data-forma="redonda" class="${m.forma!=='retangular'?'on':''}"><span class="amostra r"></span>Redonda</button>
        <button type="button" data-forma="retangular" class="${m.forma==='retangular'?'on':''}"><span class="amostra"></span>Retangular</button>
      </div>
    </div>
    <button class="btn btn-fantasma btn-sm" style="width:100%;justify-content:center" onclick="guardarMesaEd()">Guardar alterações</button>

    <div style="margin-top:1rem">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <strong style="font-family:var(--serif);color:var(--ink)">Ocupação</strong>
        <span style="font-size:.9rem;color:${oc>cap&&cap?'var(--danger)':'#7a8078'}">${oc}${cap?' / '+cap+' lugares':' lugares'}${cap&&oc>cap?' · excede!':''}</span>
      </div>
      <div class="barra-ocup"><span class="${barCls}" style="width:${perc}%"></span></div>
    </div>

    <div style="margin-top:.6rem">
      <label style="font-size:.82rem;color:#7a8078">Convites nesta mesa (${sentados.length})</label>
      <div class="lista-sentados">
        ${sentados.length ? sentados.map(c=>`
          <div class="sentado">
            <span class="nm">${esc(c.nome_final)}</span>
            <span class="lg">${c.lugares} lug.</span>
            <button class="btn-ico" title="Retirar desta mesa" onclick="retirar(${c.id})">✕</button>
          </div>`).join('') : '<div class="vazio-mini">Ainda ninguém sentado nesta mesa.</div>'}
      </div>
    </div>

    <div style="margin-top:.7rem">
      <label style="font-size:.82rem;color:#7a8078">Sentar convite nesta mesa</label>
      <select class="sel-conv" id="sel-conv" onchange="sentar(this.value)">
        <option value="">Escolher convite…</option>
        ${disponiveis.map(c=>`<option value="${c.id}">${esc(c.nome_final)} · ${c.lugares} lug.${c.mesa_id?(' (Mesa: '+esc(c.mesa_nome||'')+')'):' (sem mesa)'}</option>`).join('')}
      </select>
    </div>

    <div class="acoes-bloco">
      <button class="btn btn-fantasma btn-sm" style="flex:1;justify-content:center;color:var(--danger);border-color:#e6c3bf" onclick="eliminar(${m.id})">Eliminar mesa</button>
    </div>`;

  box.querySelectorAll('#ed-forma button').forEach(b=>b.addEventListener('click',()=>{
    box.querySelectorAll('#ed-forma button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
  }));
}

// ---------- ações ----------
$('nova-forma').querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
  $('nova-forma').querySelectorAll('button').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); novaForma=b.dataset.forma;
}));

async function adicionarMesa(){
  const nome=$('nova-nome').value.trim(); if(!nome) return toast('Indique o nome da mesa.',true);
  const cap=$('nova-cap').value;
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:0,nome,capacidade:cap,forma:novaForma})});
  if(!d.success) return toast(d.message||'Erro ao guardar.',true);
  $('nova-nome').value=''; $('nova-cap').value='';
  MESAS=d.mesas;
  // posiciona a nova mesa (sem posição) e seleciona-a
  await autoPosicionar();
  SEL=d.id||null;
  renderTudo();
  toast('Mesa criada. Arraste-a para a posição.');
}

async function guardarMesaEd(){
  const m=MESAS.find(x=>x.id===SEL); if(!m) return;
  const nome=$('ed-nome').value.trim(); if(!nome) return toast('Indique o nome da mesa.',true);
  const cap=$('ed-cap').value;
  const formaBtn=document.querySelector('#ed-forma button.on');
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:m.id,nome,capacidade:cap,forma:formaBtn?formaBtn.dataset.forma:'redonda'})});
  if(!d.success) return toast(d.message||'Erro ao guardar.',true);
  MESAS=d.mesas; renderTudo(); toast('Mesa atualizada.');
}

async function eliminar(id){
  const m=MESAS.find(x=>x.id===id); const nome=m?m.nome:'esta mesa';
  if(!confirm(`Eliminar a mesa "${nome}"? Os convites sentados ficam sem mesa.`)) return;
  const d=await api('mesa_delete&id='+id);
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=d.mesas; if(SEL===id) SEL=null;
  // reflete nos convites que ficaram sem mesa
  CONVITES.forEach(c=>{ if(+c.mesa_id===id){ c.mesa_id=null; c.mesa_nome=null; } });
  renderTudo(); toast('Mesa eliminada.');
}

async function sentar(conviteId){
  conviteId=+conviteId; if(!conviteId) return;
  const d=await api('convite_mesa',{method:'POST',body:JSON.stringify({id:conviteId,mesa_id:SEL})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=d.mesas;
  const c=CONVITES.find(x=>x.id===conviteId); const m=MESAS.find(x=>x.id===SEL);
  if(c){ c.mesa_id=SEL; c.mesa_nome=m?m.nome:null; }
  renderTudo(); toast('Convite sentado nesta mesa.');
}

async function retirar(conviteId){
  const d=await api('convite_mesa',{method:'POST',body:JSON.stringify({id:conviteId,mesa_id:''})});
  if(!d.success) return toast(d.message||'Erro.',true);
  MESAS=d.mesas;
  const c=CONVITES.find(x=>x.id===conviteId); if(c){ c.mesa_id=null; c.mesa_nome=null; }
  renderTudo(); toast('Convite retirado da mesa.');
}

carregar();
</script>
</body>
</html>
