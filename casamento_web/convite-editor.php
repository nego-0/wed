<?php
// ============================================================
// convite-editor.php — Personalização do convite digital
// Editor por separadores com pré-visualização ao vivo.
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
<title>Convite digital · <?= escP($CAS['casal']) ?></title>
<link href="assets/fontes.css" rel="stylesheet">
<link href="assets/estilo.css" rel="stylesheet">
<style>
  .ed-layout{ display:grid; grid-template-columns:minmax(0,1fr) 400px; gap:1.1rem; align-items:start; }
  @media (max-width:980px){ .ed-layout{ grid-template-columns:1fr; } .prev-col{ display:none; } }

  .ed-cartao{ background:#fff; border:1px solid var(--line); border-radius:16px; padding:1rem 1.1rem 1.2rem; }
  .ed-topo{ display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:.9rem; }
  .ed-topo .dica{ font-size:.78rem; color:#9aa09a; flex:1; min-width:200px; }
  .ed-tabs{ display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:1rem; }
  .ed-tabs .et{ border:1px solid var(--line); background:#fff; color:var(--text); font-family:inherit; font-size:.84rem;
    padding:.4rem .75rem; border-radius:50px; cursor:pointer; }
  .ed-tabs .et.on{ background:var(--forest); color:#fff; border-color:var(--forest); }

  .campo-ed{ margin-bottom:.8rem; }
  .campo-ed label{ display:block; font-size:.8rem; color:#7a8078; margin-bottom:.25rem; }
  .campo-ed textarea{ min-height:70px; }
  .grelha2{ display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
  @media (max-width:640px){ .grelha2{ grid-template-columns:1fr; } }
  .sub-rot{ font-size:.72rem; text-transform:uppercase; letter-spacing:.6px; color:var(--gold); margin:1.2rem 0 .6rem; border-top:1px dashed var(--line); padding-top:1rem; }
  .cb-linha{ display:flex; align-items:center; gap:.5rem; font-size:.92rem; color:var(--text); cursor:pointer; margin-bottom:.9rem; }
  .cb-linha input{ width:18px; height:18px; accent-color:var(--forest); }

  .lista-ed{ display:flex; flex-direction:column; gap:.6rem; margin-bottom:.6rem; }
  .item-ed{ border:1px solid var(--line); border-radius:12px; padding:.7rem .8rem; background:var(--ivory); }
  .item-ed .linha{ display:flex; gap:.5rem; margin-bottom:.5rem; align-items:center; }
  .item-ed .linha:last-child{ margin-bottom:0; }
  .item-ed input, .item-ed textarea, .item-ed select{ font-size:.9rem; padding:.5rem .6rem; }
  .item-ed textarea{ min-height:52px; }
  .ico-prev{ width:30px; height:30px; flex:none; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--line); border-radius:8px; background:#fff; }
  .ico-prev svg{ width:19px; height:19px; fill:none; stroke:var(--forest); stroke-width:1.5; }

  .media-item{ display:flex; gap:.8rem; align-items:center; border:1px solid var(--line); border-radius:12px; padding:.7rem .8rem; margin-bottom:.7rem; background:var(--ivory); }
  .media-item img{ width:74px; height:52px; object-fit:cover; border-radius:8px; border:1px solid var(--line); }
  .media-item .mi-info{ flex:1; min-width:0; }
  .media-item .mi-nome{ font-size:.9rem; color:var(--ink); font-weight:500; }
  .media-item .mi-path{ font-size:.72rem; color:#9aa09a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .media-item input[type=file]{ display:none; }

  .presets{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
  .preset{ border:1.5px solid var(--line); border-radius:12px; padding:.5rem .7rem; background:#fff; cursor:pointer;
    display:flex; align-items:center; gap:.5rem; font-family:inherit; font-size:.82rem; color:var(--text); }
  .preset .cores-mini{ display:flex; }
  .preset .cores-mini i{ width:14px; height:14px; border-radius:50%; border:1px solid rgba(0,0,0,.15); margin-left:-4px; }
  .grelha-cores{ display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.6rem; }
  .cor-campo{ display:flex; align-items:center; gap:.5rem; border:1px solid var(--line); border-radius:10px; padding:.4rem .6rem; background:#fff; }
  .cor-campo input[type=color]{ width:34px; height:28px; border:none; background:none; padding:0; cursor:pointer; }
  .cor-campo span{ font-size:.78rem; color:#7a8078; }

  .prev-col{ position:sticky; top:1rem; }
  .prev-cartao{ background:#fff; border:1px solid var(--line); border-radius:16px; padding:.8rem; }
  .prev-topo{ display:flex; align-items:center; gap:.5rem; margin-bottom:.6rem; }
  .prev-topo strong{ font-family:var(--serif); color:var(--ink); flex:1; }
  #prev{ width:100%; height:78vh; border:1px solid var(--line); border-radius:12px; background:#16261E; }
  .barra-guardar{ display:flex; gap:.6rem; margin-top:1rem; }
</style>
</head>
<body>
<header class="topo">
  <div class="wrap">
    <div class="monograma"><?= escP($CAS['mono']) ?></div>
    <div>
      <h1>Convite Digital</h1>
      <div class="sub"><?= escP($CAS['casal']) ?> · personalização completa</div>
    </div>
    <nav class="nav">
      <a href="index.php">Painel</a>
      <a href="mesas.php">Mesas</a>
      <a href="graficas.php">Gráfica</a>
      <a href="convite-editor.php" class="ativo">Convite digital</a>
      <a href="logout.php">Sair</a>
    </nav>
  </div>
</header>

<div class="container">
  <div class="ed-layout">
    <div class="ed-cartao">
      <div class="ed-topo">
        <span class="dica">Tudo o que está no convite pode ser alterado aqui. Pode usar <b>{noiva}</b> e <b>{noivo}</b> nos textos, e **negrito** / *itálico*.</span>
        <button class="btn btn-ouro" onclick="guardar()">Guardar alterações</button>
      </div>
      <div class="ed-tabs" id="ed-tabs"></div>
      <div id="ed-corpo"></div>
      <div class="barra-guardar">
        <button class="btn btn-ouro" style="flex:1;justify-content:center" onclick="guardar()">Guardar alterações</button>
        <button class="btn btn-fantasma" onclick="reporTab()">Repor originais desta secção</button>
      </div>
    </div>

    <div class="prev-col">
      <div class="prev-cartao">
        <div class="prev-topo">
          <strong>Pré-visualização</strong>
          <a class="btn-ico" href="convite-digital.php?demo=1" target="_blank">Abrir</a>
          <button class="btn-ico" onclick="recarregarPrev()">Atualizar</button>
        </div>
        <iframe id="prev" src="convite-digital.php?demo=1"></iframe>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
const PADRAO = <?= json_encode(defsPadrao(), JSON_UNESCAPED_UNICODE) ?>;
const ATUAIS = <?= json_encode(defsAtuais($conn), JSON_UNESCAPED_UNICODE) ?>;
const ICONES = <?= json_encode(iconesConvite()) ?>;
const PRESETS = <?= json_encode(temasPredef()) ?>;
const TEMA_VARS = <?= json_encode(TEMA_VARS_EDITAVEIS) ?>;

const $=id=>document.getElementById(id);
const esc=s=>(s??'').toString().replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
function toast(m,e=false){const t=$('toast');t.textContent=m;t.className='toast mostrar'+(e?' erro':'');setTimeout(()=>t.className='toast',2600);}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
async function api(action, opts={}){
  if(opts.body && typeof opts.body==='string'){
    try{ const b=JSON.parse(opts.body); if(b&&typeof b==='object'&&!Array.isArray(b)){ b.ts=agora(); opts.body=JSON.stringify(b); } }catch(e){}
  }
  opts.headers=Object.assign({'X-CSRF-Token':CSRF},opts.headers||{});
  const r=await fetch('api.php?action='+action,opts); return r.json();
}

// ---------- estado ----------
let VAL={...ATUAIS};                                  // valores em edição
let LISTAS={ 'historia.capitulos':j('historia.capitulos'), 'cronograma.itens':j('cronograma.itens'), 'manual.itens':j('manual.itens') };
let PALETA=(()=>{ try{ return JSON.parse(VAL['tema.paleta']||'{}')||{}; }catch(e){ return {}; } })();
function j(k){ try{ return JSON.parse(VAL[k]||'[]')||[]; }catch(e){ return []; } }

// ---------- separadores ----------
const TABS=[
  ['casal','Casal & Data'],['textos','Textos'],['historia','História'],['interludio','Interlúdio'],
  ['cronograma','Cronograma'],['manual','Manual'],['media','Fotos & Música'],['tema','Cores & Efeitos']
];
let TAB='casal';
const CAMPOS={
  casal:[
    ['casal.noiva','Nome da noiva','text'],['casal.noivo','Nome do noivo','text'],
    ['evento.data','Data do evento','date'],['evento.hora','Hora','time'],
    ['evento.venue_titulo','Título do momento (ex.: Copo d’água)','text'],
    ['evento.local','Local','text'],['evento.cidade','Cidade / região','text'],
    ['evento.maps','Ligação do Google Maps','text'],['evento.whatsapp','WhatsApp de contacto (só dígitos)','text'],
  ],
  textos:[
    ['textos.kicker','Frase no topo do hero','text'],['textos.hero_sub','Subtítulo do hero','text'],
    ['textos.convite_eyebrow','Chamada da secção do convite','text'],
    ['textos.lead','Texto principal do convite','ta'],
    ['textos.guest_label','Rótulo do cartão do convidado','text'],
    ['textos.closing','Texto de fecho','ta'],
    ['textos.nota_parenteses','Nota do número entre parênteses','ta'],
    ['gd.eyebrow','Chamada de “Guarde esta data”','text'],
    ['@','Passe de entrada (QR)'],
    ['acesso.eyebrow','Chamada','text'],['acesso.titulo','Título','text'],
    ['acesso.instrucao','Instrução junto ao QR','ta'],['acesso.nota','Nota de rodapé do QR','ta'],
    ['@','Confirmação (RSVP) e rodapé'],
    ['rsvp.titulo','Título do RSVP','ta'],['rsvp.sub','Subtítulo do RSVP','ta'],
    ['rsvp.deadline','Prazo de confirmação','text'],
    ['footer.local','Localidade no rodapé','text'],['footer.quote','Citação do rodapé','ta'],
  ],
  historia:[
    ['historia.visivel','Mostrar a secção “História”','cb'],
    ['historia.eyebrow','Chamada','text'],['historia.titulo','Título','text'],
    ['historia.quote','Citação de abertura','ta'],['historia.autor','Autor da citação','text'],
  ],
  interludio:[
    ['interludio.visivel','Mostrar o interlúdio (foto + citação)','cb'],
    ['interludio.quote','Citação','ta'],['interludio.autor','Autor','text'],['interludio.fecho','Texto de fecho','ta'],
  ],
  cronograma:[
    ['cronograma.visivel','Mostrar o cronograma do dia','cb'],
    ['cronograma.titulo','Título','text'],
  ],
  manual:[
    ['manual.visivel','Mostrar o manual do convidado','cb'],
    ['manual.eyebrow','Chamada','text'],['manual.titulo','Título','text'],['manual.intro','Introdução','ta'],
  ],
};

function renderTabs(){
  $('ed-tabs').innerHTML=TABS.map(([k,l])=>`<button class="et ${TAB===k?'on':''}" onclick="irTab('${k}')">${l}</button>`).join('');
}
function irTab(k){ recolherCampos(); TAB=k; renderTabs(); renderCorpo(); }

function campoHTML([k,label,tipo]){
  if(k==='@') return `<div class="sub-rot">${esc(label)}</div>`;
  const v=VAL[k]??'';
  if(tipo==='cb') return `<label class="cb-linha"><input type="checkbox" data-k="${k}" ${v==='1'?'checked':''}> ${esc(label)}</label>`;
  if(tipo==='ta') return `<div class="campo-ed"><label>${esc(label)}</label><textarea data-k="${k}">${esc(v)}</textarea></div>`;
  return `<div class="campo-ed"><label>${esc(label)}</label><input type="${tipo}" data-k="${k}" value="${esc(v)}"></div>`;
}
function iconeSelect(sel){
  return '<select class="sel-ico">'+Object.keys(ICONES).map(n=>`<option value="${n}" ${n===sel?'selected':''}>${n}</option>`).join('')+'</select>';
}
function icoPrev(n){ return `<span class="ico-prev"><svg viewBox="0 0 24 24">${ICONES[n]||''}</svg></span>`; }

function renderCorpo(){
  const box=$('ed-corpo'); let html='';
  if(CAMPOS[TAB]) html+=CAMPOS[TAB].map(campoHTML).join('');

  if(TAB==='historia'){
    html+=`<div class="sub-rot">Capítulos da história</div><div class="lista-ed" id="lista-cap">`+
      LISTAS['historia.capitulos'].map((c,i)=>`
        <div class="item-ed" data-i="${i}">
          <div class="linha"><input type="text" class="c-t" placeholder="Título do capítulo" value="${esc(c.t||'')}" style="flex:1">
            <button class="btn-ico" onclick="rmItem('historia.capitulos',${i})">✕</button></div>
          <div class="linha"><textarea class="c-x" placeholder="Texto" style="flex:1">${esc(c.x||'')}</textarea></div>
        </div>`).join('')+
      `</div><button class="btn btn-fantasma btn-sm" onclick="addItem('historia.capitulos',{t:'',x:''})">+ Capítulo</button>`;
  }
  if(TAB==='cronograma'){
    html+=`<div class="sub-rot">Momentos do dia</div><div class="lista-ed" id="lista-crono">`+
      LISTAS['cronograma.itens'].map((c,i)=>`
        <div class="item-ed" data-i="${i}">
          <div class="linha">${icoPrev(c.i)}${iconeSelect(c.i)}
            <input type="text" class="c-h" placeholder="Hora (ex.: 20H30)" value="${esc(c.h||'')}" style="width:110px">
            <input type="text" class="c-p" placeholder="Período" value="${esc(c.p||'')}" style="width:90px">
            <button class="btn-ico" onclick="rmItem('cronograma.itens',${i})">✕</button></div>
          <div class="linha"><input type="text" class="c-t" placeholder="Título" value="${esc(c.t||'')}" style="flex:1">
            <input type="text" class="c-s" placeholder="Subtítulo" value="${esc(c.s||'')}" style="flex:1"></div>
        </div>`).join('')+
      `</div><button class="btn btn-fantasma btn-sm" onclick="addItem('cronograma.itens',{h:'',p:'Noite',t:'',s:'',i:'coracao'})">+ Momento</button>`;
  }
  if(TAB==='manual'){
    html+=`<div class="sub-rot">Regras / células do manual</div><div class="lista-ed" id="lista-man">`+
      LISTAS['manual.itens'].map((c,i)=>`
        <div class="item-ed" data-i="${i}">
          <div class="linha">${icoPrev(c.i)}${iconeSelect(c.i)}
            <textarea class="c-x" placeholder="Texto (use **negrito**)" style="flex:1">${esc(c.x||'')}</textarea>
            <button class="btn-ico" onclick="rmItem('manual.itens',${i})">✕</button></div>
        </div>`).join('')+
      `</div><button class="btn btn-fantasma btn-sm" onclick="addItem('manual.itens',{i:'coracao',x:''})">+ Célula</button>`;
  }
  if(TAB==='media'){
    const M=[['media.hero','Fotografia do hero (capa)'],['media.historia','Fotografia da história'],
             ['media.interludio','Fotografia do interlúdio'],['media.acesso','Fotografia do passe de entrada'],
             ['media.musica','Música de fundo (m4a/mp3)']];
    html+=M.map(([k,l])=>{
      const v=VAL[k]||''; const ehImg=k!=='media.musica';
      return `<div class="media-item">
        ${ehImg?`<img src="${esc(v)}?v=${Date.now()}" alt="">`:`<span class="ico-prev" style="width:74px;height:52px"><svg viewBox="0 0 24 24">${ICONES['musica']}</svg></span>`}
        <div class="mi-info"><div class="mi-nome">${l}</div><div class="mi-path">${esc(v)}</div></div>
        <label class="btn btn-fantasma btn-sm">Substituir<input type="file" accept="${ehImg?'image/*':'audio/*,.m4a,.mp3'}" onchange="upload('${k}',this)"></label>
      </div>`;
    }).join('')+`<p style="font-size:.78rem;color:#9aa09a">As imagens são comprimidas automaticamente antes do envio. A música deve ter no máximo 8 MB.</p>`;
  }
  if(TAB==='tema'){
    html+=`<div class="sub-rot">Paletas prontas</div><div class="presets">`+
      Object.entries(PRESETS).map(([k,p])=>`<button class="preset" onclick="aplicarPreset('${k}')">
        <span class="cores-mini"><i style="background:${p['forest']}"></i><i style="background:${p['gold']}"></i><i style="background:${p['cream']}"></i></span>${p.nome}</button>`).join('')+
      `<button class="preset" onclick="PALETA={};renderCorpo()">Repor original</button></div>
      <div class="sub-rot">Cores (ajuste fino)</div><div class="grelha-cores">`+
      TEMA_VARS.map(v=>{
        const cor=PALETA[v]||PRESETS['floresta'][v];
        return `<label class="cor-campo"><input type="color" data-cor="${v}" value="${cor}" onchange="PALETA['${v}']=this.value.toUpperCase()"><span>--${v}</span></label>`;
      }).join('')+
      `</div><div class="sub-rot">Efeitos</div>
      <label class="cb-linha"><input type="checkbox" data-k="fx.petalas" ${VAL['fx.petalas']==='1'?'checked':''}> Pétalas a cair</label>
      <label class="cb-linha"><input type="checkbox" data-k="fx.autoplay" ${VAL['fx.autoplay']==='1'?'checked':''}> Música arranca ao abrir o convite</label>
      <p style="font-size:.78rem;color:#9aa09a">As cores do código QR acompanham automaticamente a paleta.</p>`;
  }
  box.innerHTML=html;
}

// ---------- tema ----------
function aplicarPreset(k){
  const p=PRESETS[k]; if(!p) return;
  PALETA={}; TEMA_VARS.forEach(v=>{ if(p[v]) PALETA[v]=p[v].toUpperCase(); });
  renderCorpo();
}

// ---------- listas ----------
function addItem(k,item){ recolherCampos(); LISTAS[k].push(item); renderCorpo(); }
function rmItem(k,i){ recolherCampos(); LISTAS[k].splice(i,1); renderCorpo(); }

// ---------- recolher valores do DOM ----------
function recolherCampos(){
  document.querySelectorAll('#ed-corpo [data-k]').forEach(el=>{
    VAL[el.dataset.k]= el.type==='checkbox' ? (el.checked?'1':'0') : el.value;
  });
  const ler=(sel,campos)=>[...document.querySelectorAll(sel+' .item-ed')].map(it=>{
    const o={}; campos.forEach(c=>{ const el=it.querySelector('.c-'+c); if(el) o[c]=el.value; });
    const ico=it.querySelector('.sel-ico'); if(ico) o.i=ico.value; return o;
  });
  if($('lista-cap'))   LISTAS['historia.capitulos']=ler('#lista-cap',['t','x']);
  if($('lista-crono')) LISTAS['cronograma.itens']=ler('#lista-crono',['h','p','t','s']);
  if($('lista-man'))   LISTAS['manual.itens']=ler('#lista-man',['x']);
}

// ---------- guardar ----------
async function guardar(){
  recolherCampos();
  VAL['historia.capitulos']=JSON.stringify(LISTAS['historia.capitulos']);
  VAL['cronograma.itens']=JSON.stringify(LISTAS['cronograma.itens']);
  VAL['manual.itens']=JSON.stringify(LISTAS['manual.itens']);
  VAL['tema.paleta']=Object.keys(PALETA).length?JSON.stringify(PALETA):'';
  const defs={}; Object.keys(PADRAO).forEach(k=>{ if(!k.startsWith('media.')) defs[k]=String(VAL[k]??''); });
  const d=await api('defs_save',{method:'POST',body:JSON.stringify({defs})});
  if(!d.success) return toast(d.message||'Erro ao guardar.',true);
  const inv=(d.invalidas||[]).length?` (${d.invalidas.length} campo(s) inválido(s): ${d.invalidas.join(', ')})`:'';
  toast('Convite atualizado.'+inv, !!inv);
  recarregarPrev();
}

function reporTab(){
  if(!confirm('Repor os valores originais desta secção?')) return;
  const chavesTab=(CAMPOS[TAB]||[]).map(c=>c[0]).filter(k=>k!=='@');
  if(TAB==='historia') chavesTab.push('historia.capitulos');
  if(TAB==='cronograma') chavesTab.push('cronograma.itens');
  if(TAB==='manual') chavesTab.push('manual.itens');
  if(TAB==='tema'){ chavesTab.push('tema.paleta','fx.petalas','fx.autoplay'); PALETA={}; }
  chavesTab.forEach(k=>{ VAL[k]=PADRAO[k]; if(LISTAS[k]!==undefined){ try{ LISTAS[k]=JSON.parse(PADRAO[k]); }catch(e){} } });
  renderCorpo(); guardar();
}

// ---------- upload (com compressão de imagem no browser) ----------
async function comprimirImagem(file, maxLado=1600, q=0.82){
  try{
    const bmp=await createImageBitmap(file);
    const escala=Math.min(1, maxLado/Math.max(bmp.width,bmp.height));
    const w=Math.round(bmp.width*escala), h=Math.round(bmp.height*escala);
    const cv=document.createElement('canvas'); cv.width=w; cv.height=h;
    cv.getContext('2d').drawImage(bmp,0,0,w,h);
    const blob=await new Promise(r=>cv.toBlob(r,'image/jpeg',q));
    return blob||file;
  }catch(e){ return file; }
}
async function upload(chave, input){
  const f=input.files && input.files[0]; if(!f) return;
  const ehMusica=chave==='media.musica';
  if(ehMusica && f.size>8*1024*1024) return toast('A música excede 8 MB.',true);
  toast('A enviar…');
  const dados=ehMusica ? f : await comprimirImagem(f);
  const fd=new FormData();
  fd.append('chave',chave); fd.append('ts',agora());
  fd.append('ficheiro', dados, ehMusica ? f.name : 'foto.jpg');
  const r=await fetch('api.php?action=def_upload',{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd});
  const d=await r.json();
  if(!d.success) return toast(d.message||'Falha no envio.',true);
  VAL[chave]=d.path; ATUAIS[chave]=d.path;
  toast('Ficheiro atualizado.'); renderCorpo(); recarregarPrev();
}

function recarregarPrev(){ const p=$('prev'); if(p) p.src='convite-digital.php?demo=1&_='+Date.now(); }

renderTabs(); renderCorpo();
</script>
</body>
</html>
