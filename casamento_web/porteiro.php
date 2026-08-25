<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';   // tiraSuporte()
exigirPorta();
$CAS = casalInfo(defsAtuais($conn));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Porta · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<?php include __DIR__ . '/parcial-tema.php'; ?>
<script src="<?= asset('assets/html5-qrcode.min.js') ?>"></script>
<style>
  /* Aviso de ligação (offline / a sincronizar) */
  .barra-offline{ position:fixed; left:0; right:0; bottom:0; z-index:900; padding:.55rem .9rem;
    text-align:center; font-size:.86rem; font-weight:500; letter-spacing:.01em; }
  .barra-offline.off{ background:#7a3b34; color:#ffe9e4; }
  .barra-offline.sync{ background:var(--gold); color:#1a1b17; }

  body{ background:linear-gradient(175deg,#16261E,#20342A); color:var(--ivory); min-height:100vh; }
  .porta-topo{ padding:1.1rem 1.25rem; display:flex; align-items:center; gap:.8rem; }
  .porta-topo .mono{ width:44px;height:44px;border:2px solid var(--gold-soft);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--gold-soft);font-family:var(--serif);font-weight:700; }
  .porta-topo h1{ color:var(--ivory); font-size:1.15rem; }
  .porta-topo .sub{ color:var(--gold-pale); font-family:var(--serif); font-style:italic; font-size:.9rem; }
  .porta-topo .nav{ margin-left:auto; display:flex; gap:.4rem; }
  .porta-topo .nav a{ color:var(--gold-pale); border:1px solid rgba(217,188,140,.3); border-radius:50px; padding:.35rem .8rem; font-size:.8rem; }
  .contentor{ max-width:560px; margin:0 auto; padding:1rem 1.1rem 3rem; }

  .contador-porta{ display:flex; gap:.7rem; margin-bottom:1.1rem; }
  .cp{ flex:1; background:rgba(255,255,255,.06); border:1px solid rgba(217,188,140,.2); border-radius:14px; padding:.9rem; text-align:center; }
  .cp .n{ font-family:var(--serif); font-size:1.9rem; font-weight:700; color:var(--gold-soft); line-height:1; }
  .cp .l{ font-size:.68rem; text-transform:uppercase; letter-spacing:1px; color:var(--gold-pale); margin-top:.3rem; }

  #leitor{ border-radius:16px; overflow:hidden; margin-bottom:1rem; display:none; background:#000; }
  #leitor.on{ display:block; }
  .btn-scan{ width:100%; justify-content:center; font-size:1.05rem; padding:1rem; margin-bottom:1rem; }

  .busca-manual{ display:flex; gap:.5rem; margin-bottom:1.2rem; }
  .busca-manual input{ background:rgba(255,255,255,.9); }

  .resultado{ display:none; }
  .resultado.on{ display:block; }
  .cartao-conv{ background:var(--ivory); color:var(--text); border-radius:18px; overflow:hidden; box-shadow:0 16px 40px rgba(0,0,0,.35); }
  .faixa{ padding:1.2rem 1.3rem; color:#fff; }
  .faixa.ok{ background:linear-gradient(135deg,#2f7d4f,#245e3b); }
  .faixa.aviso{ background:linear-gradient(135deg,#a8792f,#8A6031); }
  .faixa.rec{ background:linear-gradient(135deg,#a5473f,#7d332d); }
  .faixa .est{ font-size:.78rem; text-transform:uppercase; letter-spacing:2px; opacity:.9; }
  .faixa .nome{ font-family:var(--serif); font-size:1.7rem; font-weight:600; line-height:1.1; margin-top:.2rem; }
  .faixa .meta{ font-size:.9rem; opacity:.95; margin-top:.3rem; }
  .conteudo{ padding:1.2rem 1.3rem; }
  .lista-memb{ display:grid; gap:.5rem; margin-bottom:1rem; }
  .memb{ display:flex; align-items:center; gap:.7rem; border:1.5px solid var(--line); border-radius:12px; padding:.7rem .9rem; cursor:pointer; }
  .memb .est-p{ width:24px;height:24px;border-radius:50%;border:2px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:.8rem;flex:none; }
  .memb.presente{ background:var(--ok-bg); border-color:var(--ok); }
  .memb.presente .est-p{ background:var(--ok); border-color:var(--ok); color:#fff; }
  .memb.bloqueado{ opacity:.85; cursor:pointer; background:#f7eae8; border-color:#e6c3bf; }
  .memb.bloqueado .est-p{ border-color:#c98a86; color:#a5473f; }
  .memb.excecional{ background:#fbf3e3; border-color:#e0b96a; }
  .memb.excecional .est-p{ background:#c88a2a; border-color:#c88a2a; color:#fff; }
  .memb .nm{ flex:1; font-size:1rem; }
  .memb-mesa{ display:block; font-size:.75rem; color:var(--gold); margin-top:.1rem; }
  .mesas-dividido{ background:var(--gold-pale); color:#6b4e22; border:1px solid var(--gold-soft);
    border-radius:10px; padding:.55rem .8rem; font-size:.85rem; margin-bottom:1rem; }
  .acoes-porta{ display:flex; gap:.6rem; flex-wrap:wrap; }
  .acoes-porta .btn{ flex:1; justify-content:center; }
  .aviso-txt{ background:var(--warn-bg); color:var(--warn); border-radius:10px; padding:.6rem .8rem; font-size:.88rem; margin-bottom:1rem; }
  .varios{ display:grid; gap:.5rem; }
  .varios .opc{ background:var(--ivory); color:var(--text); border-radius:12px; padding:.8rem 1rem; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
  .varios .opc small{ color:#8a8f88; }
  .msg-vazia{ text-align:center; color:var(--gold-pale); padding:1.4rem; }

  .abas{ display:flex; gap:.4rem; margin-bottom:1.1rem; background:rgba(255,255,255,.05); border:1px solid rgba(217,188,140,.2); border-radius:50px; padding:.25rem; }
  .aba{ flex:1; border:none; background:transparent; color:var(--gold-pale); font-family:var(--sans); font-weight:500; font-size:.92rem; padding:.6rem; border-radius:50px; cursor:pointer; }
  .aba.on{ background:var(--gold); color:#20342A; }

  .entradas-topo{ text-align:center; color:var(--gold-pale); font-size:.9rem; margin-bottom:1rem; }
  .entradas-topo b{ color:var(--gold-soft); font-family:var(--serif); font-size:1.1rem; }
  .entrada-item{ background:rgba(255,255,255,.06); border:1px solid rgba(217,188,140,.2); border-left:3px solid #2f7d4f; border-radius:12px; padding:.8rem 1rem; margin-bottom:.6rem; }
  .ent-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; }
  .ent-nome{ font-family:var(--serif); font-size:1.2rem; font-weight:600; color:var(--ivory); }
  .ent-hora{ font-size:.8rem; color:var(--gold-pale); white-space:nowrap; }
  .ent-meta{ font-size:.82rem; color:var(--gold-pale); margin-top:.15rem; }
  .ent-pessoas{ font-size:.88rem; color:var(--ivory); margin-top:.4rem; opacity:.9; }
</style>
<!-- use-credentials: sem isto o navegador pede o manifesto sem a sessão, e a
     aplicação instalada ficava com o nome genérico em vez do casamento. -->
<link rel="manifest" href="manifest.php" crossorigin="use-credentials">
<meta name="theme-color" content="#16261E">
<script src="<?= asset('assets/api.js') ?>"></script>
</head>
<body>
<?php tiraSuporte(true); ?>
<div class="porta-topo">
  <div class="mono"><?= escP($CAS['mono']) ?></div>
  <div>
    <h1>Entrada do evento</h1>
    <div class="sub"><?= escP($CAS['casal']) ?></div>
  </div>
  <div class="nav">
    <?php if (ehAdmin()): ?><a href="index.php">Painel</a><a href="mesas.php">Mesas</a><?php endif; ?>
    <a href="logout.php">Sair</a>
  </div>
</div>

<div class="contentor">
  <div class="contador-porta" id="contador">
    <div class="cp"><div class="n" id="c-presentes">0</div><div class="l">Presentes</div></div>
    <div class="cp"><div class="n" id="c-confirm">0</div><div class="l">Confirmados</div></div>
    <div class="cp"><div class="n" id="c-convites">0</div><div class="l">Convites</div></div>
  </div>

  <div class="abas">
    <button class="aba on" id="tab-reg" onclick="mudarAba('reg')">Registar entrada</button>
    <button class="aba" id="tab-ent" onclick="mudarAba('ent')">Já entraram</button>
  </div>

  <div id="aba-reg">
    <button class="btn btn-ouro btn-scan" id="btn-scan" onclick="alternarScan()">Abrir câmara para ler QR</button>
    <div id="leitor"></div>

    <div class="busca-manual">
      <input type="search" id="q" placeholder="Código ou nome do convidado…" onkeydown="if(event.key==='Enter')buscar()">
      <button class="btn btn-linha" style="border-color:var(--gold-soft);color:var(--gold-soft)" onclick="buscar()">Procurar</button>
    </div>

    <div class="resultado" id="resultado"></div>
  </div>

  <div id="aba-ent" style="display:none">
    <div class="entradas-topo" id="entradas-topo"></div>
    <div id="lista-entradas"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
window.CSRF = <?= json_encode(csrfToken()) ?>;
const $=id=>document.getElementById(id);
const esc=s=>(s??'').toString().replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
function toast(m,e=false){const t=$('toast');t.textContent=m;t.className='toast mostrar'+(e?' erro':'');setTimeout(()=>t.className='toast',2400);}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
// api() vem de assets/api.js (trata sessão expirada, falha de rede e erros do servidor)

// contador
async function atualizarContador(){
  const d=await api('porta_stats',{silencioso:true});   // sondagem: não incomoda se falhar
  if(d.success){ $('c-presentes').textContent=d.presentes; $('c-confirm').textContent=d.lug_confirm; $('c-convites').textContent=d.convites; }
}
atualizarContador();

// ---------- leitor QR ----------
let leitor=null, ativo=false;
async function alternarScan(){
  if(ativo){ pararScan(); return; }
  const localHost = ['localhost','127.0.0.1'].includes(location.hostname);
  if(!window.isSecureContext && !localHost){
    toast('A câmara só funciona numa ligação segura. Abra o site com https:// no início do endereço e tente de novo.', true);
    return;
  }
  if(typeof Html5Qrcode==='undefined'){
    toast('O leitor de QR não carregou. Confirme a ligação à internet e recarregue a página.', true);
    return;
  }
  $('leitor').classList.add('on'); $('btn-scan').textContent='Fechar câmara';
  leitor=new Html5Qrcode('leitor');
  const onOk = txt=>{ pararScan(); buscar(txt); };
  const cfg = {fps:10, qrbox:{width:230,height:230}};
  try{
    await leitor.start({facingMode:'environment'}, cfg, onOk, ()=>{});
    ativo=true;
  }catch(e1){
    // 2ª tentativa: escolher explicitamente a câmara (traseira, se existir)
    try{
      const cams = await Html5Qrcode.getCameras();
      if(!cams || !cams.length) { const err=new Error('sem câmaras'); err.name='NotFoundError'; throw err; }
      const tras = cams.find(c=>/back|rear|tr[aá]s|environment/i.test(c.label)) || cams[cams.length-1];
      await leitor.start(tras.id, cfg, onOk, ()=>{});
      ativo=true;
    }catch(e2){
      toast(mensagemCamera(e2||e1), true);
      pararScan();
    }
  }
}
function mensagemCamera(e){
  const n=(e&&e.name)||''; const s=(''+((e&&e.message)||e)).toLowerCase();
  if(n==='NotAllowedError'||s.includes('permission')||s.includes('denied')||s.includes('negad'))
    return 'Permissão da câmara negada. Autorize o acesso à câmara nas definições do navegador e tente de novo.';
  if(n==='NotFoundError'||s.includes('sem câmaras')||s.includes('not found')||s.includes('no camera'))
    return 'Não foi encontrada nenhuma câmara neste dispositivo. Use a busca manual.';
  if(n==='NotReadableError'||s.includes('in use')||s.includes('could not start'))
    return 'A câmara está a ser usada por outra aplicação. Feche-a e tente novamente.';
  return 'Não foi possível aceder à câmara. Verifique as permissões do navegador, ou use a busca manual.';
}
function pararScan(){
  if(leitor&&ativo){ leitor.stop().then(()=>leitor.clear()).catch(()=>{}); }
  ativo=false; $('leitor').classList.remove('on'); $('btn-scan').textContent='Abrir câmara para ler QR';
}

// Service worker: permite abrir a página sem rede (só a "casca").
if('serviceWorker' in navigator && location.protocol !== 'file:'){
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(()=>{}));
}

// ---------- funcionamento sem internet ----------
// A entrada do evento não pode parar se a rede falhar. Guardamos uma cópia
// local da lista (para procurar) e uma fila dos check-ins feitos offline,
// que é enviada assim que houver ligação outra vez.
//
// As chaves levam o número do casamento. Quem trabalha em dois — o pessoal da
// casa, um porteiro contratado por dois casais — usava o mesmo telemóvel e o
// mesmo navegador: com uma chave só, chegava ao casamento de hoje e procurava
// na lista de ontem. E a fila de entradas por enviar é ainda mais delicada:
// enviada no casamento errado, dava entrada às pessoas erradas. Por isso cada
// casamento tem a sua, e só se envia a do que está aberto.
const CASAMENTO = <?= (int)casamentoAtual() ?>;
const LS_DADOS = 'porta.dados.' + CASAMENTO, LS_FILA = 'porta.fila.' + CASAMENTO;

function guardarLocal(chave, valor){ try{ localStorage.setItem(chave, JSON.stringify(valor)); }catch(e){} }
function lerLocal(chave, omissao){ try{ const v=localStorage.getItem(chave); return v?JSON.parse(v):omissao; }catch(e){ return omissao; } }

// Antes de haver vários casamentos as chaves não tinham número. Se ficou
// alguma coisa lá dentro — sobretudo entradas por enviar —, é do casamento nº1,
// o único que então existia: adota-se uma vez e apaga-se a antiga.
(function herdarChavesAntigas(){
  if (CASAMENTO !== 1) return;
  [['porta.dados', LS_DADOS], ['porta.fila', LS_FILA]].forEach(([velha, nova]) => {
    try {
      const v = localStorage.getItem(velha);
      if (v !== null && localStorage.getItem(nova) === null) localStorage.setItem(nova, v);
      if (v !== null) localStorage.removeItem(velha);
    } catch (e) {}
  });
})();

let COPIA = lerLocal(LS_DADOS, null);
let FILA  = lerLocal(LS_FILA, []);

function online(){ return navigator.onLine !== false; }
function marcarEstadoLigacao(){
  const off = !online(), n = FILA.length;
  let el = document.getElementById('barra-offline');
  if(!off && !n){ if(el) el.remove(); return; }
  if(!el){ el = document.createElement('div'); el.id='barra-offline'; el.className='barra-offline'; document.body.appendChild(el); }
  el.className = 'barra-offline' + (off ? ' off' : ' sync');
  el.textContent = off
    ? (n ? `Sem internet · a trabalhar offline · ${n} entrada(s) por enviar` : 'Sem internet · a trabalhar offline')
    : `A enviar ${n} entrada(s) registada(s) offline…`;
}

// Descarrega a cópia local (silenciosamente; se falhar, fica a anterior)
async function sincronizarCopia(){
  const d = await api('porta_dados', {silencioso:true});
  if(d && d.success && d.convites){
    COPIA = { convites: d.convites, em: d.gerado_em };
    guardarLocal(LS_DADOS, COPIA);
  }
}

// Procura na cópia local, com a mesma lógica do servidor (código ou nome)
function buscarLocal(termo){
  if(!COPIA || !COPIA.convites) return null;
  const m = termo.match(/[?&]c=([A-Z0-9]+)/i);
  const t = (m ? m[1] : termo).trim().toLowerCase();
  const porCodigo = COPIA.convites.find(c => (c.codigo||'').toLowerCase() === t);
  if(porCodigo) return { convite: porCodigo };
  const achados = COPIA.convites.filter(c =>
    (c.nome_exibicao||'').toLowerCase().includes(t) ||
    (c.membros||[]).some(g => (g.nome||'').toLowerCase().includes(t))).slice(0,12);
  if(!achados.length) return { erro: `Nenhum convite corresponde a "${termo}".` };
  return achados.length === 1 ? { convite: achados[0] } : { varios: achados };
}

// Aplica um check-in à cópia local, para o ecrã responder mesmo offline
function aplicarLocal(conviteId, modo, membroId){
  if(!COPIA) return null;
  const c = COPIA.convites.find(x => +x.id === +conviteId); if(!c) return null;
  if(modo === 'todos' || modo === 'anular'){
    const p = modo !== 'anular';
    (c.membros||[]).forEach(g => g.presente = p ? 1 : 0);
    c.checkin_presentes = p ? (c.membros||[]).length : 0;
    c.checkin_estado = p ? 'presente' : 'aguardando';
  } else if(modo === 'membro'){
    const g = (c.membros||[]).find(x => +x.id === +membroId);
    if(g) g.presente = 1;
    c.checkin_presentes = (c.membros||[]).filter(x => +x.presente).length;
    c.checkin_estado = c.checkin_presentes >= (c.membros||[]).length ? 'presente' : 'parcial';
  }
  guardarLocal(LS_DADOS, COPIA);
  return c;
}

// Envia a fila acumulada, pela ordem em que foi registada
let aEnviar = false;
async function enviarFila(){
  if(aEnviar || !FILA.length || !online()) return;
  aEnviar = true; marcarEstadoLigacao();
  const recusadas = [];
  while(FILA.length){
    const item = FILA[0];
    const d = await api('porta_checkin', {method:'POST', body:JSON.stringify(item), silencioso:true, semAviso:true});
    // Distingue-se falha de LIGAÇÃO de recusa do SERVIDOR. Se a rede caiu,
    // pára-se e tenta-se mais tarde. Mas se o servidor recusou (ex.: essa
    // pessoa afinal não confirmou presença), guardar a entrada na fila para
    // sempre entupia tudo o que vinha atrás — tira-se e avisa-se quem está à porta.
    if(!d || d._erro){ break; }
    if(!d.success){ recusadas.push(d.message || 'Entrada recusada pelo servidor.'); }
    FILA.shift(); guardarLocal(LS_FILA, FILA);
  }
  aEnviar = false;
  marcarEstadoLigacao();
  if(recusadas.length){
    toast(recusadas.length === 1 ? recusadas[0]
        : recusadas.length + ' entradas offline não foram aceites. Confirme-as à mão.', true);
  }
  if(!FILA.length){
    if(!recusadas.length) toast('Entradas offline sincronizadas.');
    atualizarContador(); sincronizarCopia();
  }
}
window.addEventListener('online',  () => { marcarEstadoLigacao(); enviarFila(); });
window.addEventListener('offline', marcarEstadoLigacao);

// ---------- buscar ----------
async function buscar(valor){
  const termo=(typeof valor==='string'?valor:$('q').value).trim();
  if(!termo) return;
  const box=$('resultado'); box.classList.add('on');
  let d;
  if(online()){
    d = await api('porta_buscar&q='+encodeURIComponent(termo), {silencioso:true, semAviso:true});
  }
  // Sem rede (ou a rede falhou): procura na cópia guardada no dispositivo.
  if(!d || d._erro){
    const loc = buscarLocal(termo);
    if(!loc){ box.innerHTML=`<div class="cartao-conv"><div class="faixa rec"><div class="nome">Sem ligação</div><div class="meta">Ainda não há cópia local da lista. Ligue-se à internet uma vez para a descarregar.</div></div></div>`; return; }
    if(loc.erro){ box.innerHTML=`<div class="cartao-conv"><div class="faixa rec"><div class="nome">Não encontrado</div><div class="meta">${esc(loc.erro)}</div></div></div>`; return; }
    if(loc.varios){ mostrarVarios(loc.varios); return; }
    mostrarConvite(loc.convite); return;
  }
  if(!d.success){ box.innerHTML=`<div class="cartao-conv"><div class="faixa rec"><div class="nome">Não encontrado</div><div class="meta">${esc(d.message||'')}</div></div></div>`; return; }
  if(d.varios){ mostrarVarios(d.varios); return; }
  mostrarConvite(d.convite);
}

let RESULTADOS=[];
function mostrarVarios(lista){
  RESULTADOS=lista;
  $('resultado').innerHTML=`<div class="cartao-conv"><div class="faixa aviso"><div class="est">Vários resultados</div><div class="nome">Escolha o convite</div></div>
    <div class="conteudo"><div class="varios">${lista.map((c,i)=>`
      <div class="opc" onclick="mostrarConvite(RESULTADOS[${i}])">
        <span>${esc(c.nome_final)}<br><small>${esc((c.membros||[]).map(m=>m.nome).join(', '))}</small></span>
        <span>${c.checkin_estado==='presente'?'✓':'›'}</span>
      </div>`).join('')}</div></div></div>`;
}

function mostrarConvite(c){
  const conf=(c.rsvp_estado==='confirmado'||c.rsvp_estado==='parcial');
  const presente=c.checkin_estado==='presente';
  const parcial=c.checkin_estado==='parcial';
  let faixa='aviso', est='Pendente de RSVP';
  if(presente){ faixa='ok'; est='Já deu entrada'; }
  else if(c.rsvp_estado==='recusado'){ faixa='rec'; est='Tinha recusado'; }
  else if(conf){ faixa='ok'; est='Presença confirmada'; }

  const temMemb=(c.membros&&c.membros.length>0);
  const mesaBadge=m=>m.mesa_efetiva?`<span class="memb-mesa">Mesa: ${esc(m.mesa_efetiva)}</span>`:'';
  // Assinala se o convite tem pessoas distribuídas por mais do que uma mesa
  const mesasDistintas=temMemb ? [...new Set(c.membros.map(m=>m.mesa_efetiva||'—'))].filter(x=>x!=='—') : [];
  const dividido=mesasDistintas.length>1;
  const membros = temMemb ? c.membros.map(m=>{
    const pres=+m.presente, conf=(m.rsvp==='confirmado');
    if(!conf && !pres){
      return `<div class="memb bloqueado" onclick="excecaoMembro(${c.id},${m.id})">
        <span class="est-p">✕</span>
        <span class="nm">${esc(m.nome)}${mesaBadge(m)}</span>
        <span style="font-size:.75rem;color:#c98a86">não confirmou · autorizar?</span>
      </div>`;
    }
    const nota = pres ? (conf?'presente':'presente (exceção)') : 'marcar';
    return `<div class="memb ${pres?'presente':''} ${(pres&&!conf)?'excecional':''}" onclick="checkin(${c.id},'membro',${m.id})">
      <span class="est-p">${pres?'✓':''}</span>
      <span class="nm">${esc(m.nome)}${mesaBadge(m)}</span>
      <span style="font-size:.75rem;color:#8a8f88">${nota}</span>
    </div>`;
  }).join('') : '';

  const confCount = temMemb
    ? c.membros.filter(m=>m.rsvp==='confirmado').length
    : ((c.rsvp_estado==='confirmado'||c.rsvp_estado==='parcial') ? (+c.rsvp_confirmados||+c.lugares) : 0);
  const podeEntrar = confCount>0;
  const todosConf = !temMemb || c.membros.every(m=>m.rsvp==='confirmado');
  const rotuloEntrada = temMemb ? (todosConf?' a todos':' aos confirmados') : '';

  let aviso='';
  if(c.rsvp_estado==='recusado') aviso=`<div class="aviso-txt">Este convite tinha indicado que não compareceria. Confirme antes de autorizar a entrada.</div>`;
  else if(c.rsvp_estado==='pendente') aviso=`<div class="aviso-txt">Sem confirmação prévia (RSVP pendente).</div>`;

  $('resultado').innerHTML=`<div class="cartao-conv">
    <div class="faixa ${faixa}">
      <div class="est">${est}</div>
      <div class="nome">${esc(c.nome_final)}</div>
      <div class="meta">${c.mesa_nome?('Mesa: '+esc(c.mesa_nome)+' · '):''}${c.lugares} lugar(es) · Cód. ${c.codigo}
        ${c.checkin_presentes>0?(' · '+c.checkin_presentes+' no local'):''}</div>
    </div>
    <div class="conteudo">
      ${aviso}
      ${dividido?`<div class="mesas-dividido">Convite dividido por mesas: <b>${mesasDistintas.map(esc).join(' · ')}</b></div>`:''}
      ${temMemb?`<div class="lista-memb">${membros}</div>`:''}
      <div class="acoes-porta">
        ${podeEntrar
          ? `<button class="btn btn-verde" onclick="checkin(${c.id},'todos')">Dar entrada${rotuloEntrada}</button>`
          : `<button class="btn btn-linha" style="flex:1;border-color:#c98a86;color:#e0b0ac" onclick="excecaoTodos(${c.id})">Autorizar entrada (exceção)</button>`}
        ${(presente||parcial)?`<button class="btn btn-fantasma" onclick="checkin(${c.id},'anular')">Anular entrada</button>`:''}
      </div>
      ${!podeEntrar?`<div class="aviso-txt" style="margin-top:.6rem">Sem presença confirmada. Autorize a entrada apenas em casos excecionais.</div>`:''}
      <button class="btn btn-linha" style="width:100%;margin-top:.7rem;border-color:var(--gold-soft);color:var(--gold-soft)" onclick="proximo()">Próximo</button>
    </div>
  </div>`;
}

async function checkin(id,modo,membroId=0,excecao=false){
  const pedido = {convite_id:id, modo, membro_id:membroId, excecao};
  const d = online() ? await api('porta_checkin',{method:'POST',body:JSON.stringify(pedido),silencioso:true,semAviso:true}) : null;

  // Sem rede: regista localmente e põe em fila para enviar depois.
  if(!d || d._erro){
    FILA.push(pedido); guardarLocal(LS_FILA, FILA);
    const c = aplicarLocal(id, modo, membroId);
    marcarEstadoLigacao();
    toast('Sem internet — entrada registada no dispositivo. Será enviada quando houver ligação.');
    if(c) mostrarConvite(c);
    return;
  }
  if(!d.success) return toast(d.message||'Erro.',true);
  toast(modo==='anular'?'Entrada anulada.':(excecao?'Entrada excecional registada.':'Entrada registada.'));
  mostrarConvite(d.convite); atualizarContador();
  aplicarLocal(id, modo, membroId);
}
function excecaoMembro(id,mid){
  if(confirm('Esta pessoa não confirmou presença.\n\nAutorizar a entrada em caráter excecional?')) checkin(id,'membro',mid,true);
}
function excecaoTodos(id){
  if(confirm('Este convite não tem presença confirmada.\n\nAutorizar a entrada em caráter excecional?')) checkin(id,'todos',0,true);
}

// ---------- próximo / abas / entradas ----------
function proximo(){
  $('resultado').classList.remove('on'); $('resultado').innerHTML='';
  $('q').value=''; $('q').focus();
}
function mudarAba(nome){
  const reg = nome==='reg';
  $('aba-reg').style.display = reg?'':'none';
  $('aba-ent').style.display = reg?'none':'';
  $('tab-reg').classList.toggle('on', reg);
  $('tab-ent').classList.toggle('on', !reg);
  if(!reg){ if(ativo) pararScan(); carregarEntradas(); }
}
function fmtHora(sql){
  if(!sql) return '';
  const d=new Date((''+sql).replace(' ','T'));
  return isNaN(d)?'':d.toLocaleTimeString('pt-PT',{hour:'2-digit',minute:'2-digit'});
}
async function carregarEntradas(){
  const box=$('lista-entradas'), topo=$('entradas-topo');
  box.innerHTML='<div class="msg-vazia">A carregar…</div>';
  const d=await api('porta_entradas');
  if(!d.success){ box.innerHTML='<div class="msg-vazia">Não foi possível carregar.</div>'; topo.innerHTML=''; return; }
  const es=d.entradas||[];
  topo.innerHTML=`<b>${d.presentes||0}</b> pessoas no local · ${es.length} convite(s) com entrada`;
  if(!es.length){ box.innerHTML='<div class="msg-vazia">Ainda ninguém deu entrada.</div>'; return; }
  box.innerHTML=es.map(c=>{
    const presentes=(c.membros||[]).filter(m=>+m.presente).map(m=>esc(m.nome));
    const hora=fmtHora(c.checkin_em);
    return `<div class="entrada-item">
      <div class="ent-topo"><span class="ent-nome">${esc(c.nome_final)}</span>${hora?`<span class="ent-hora">${hora}</span>`:''}</div>
      <div class="ent-meta">${c.checkin_presentes} de ${c.lugares} · ${c.mesa_nome?('Mesa '+esc(c.mesa_nome)+' · '):''}Cód. ${c.codigo}</div>
      ${presentes.length?`<div class="ent-pessoas">${presentes.join(', ')}</div>`:''}
    </div>`;
  }).join('');
}

// Arranque do modo offline (no fim: as variáveis do módulo já estão declaradas).
marcarEstadoLigacao();
sincronizarCopia();
enviarFila();
</script>
<?php include __DIR__ . '/parcial-seletor-tema.php'; ?>
</body>
</html>
