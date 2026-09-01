/* ============================================================
   mesas.js — Planta de mesas: desenho, arrastar, listas e combos
   Vivia dentro de mesas.php (mais de 700 linhas de <script>), o que
   impedia o navegador de o guardar em cache e tornava o ficheiro da
   página difícil de ler. O token CSRF continua a ser escrito pelo PHP
   (window.CSRF), porque muda a cada sessão.
   ============================================================ */
const $=id=>document.getElementById(id);
const esc=s=>(s??'').toString().replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
function toast(m,e=false){const t=$('toast');t.textContent=m;t.className='toast mostrar'+(e?' erro':'');setTimeout(()=>t.className='toast',2400);}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
// api() vem de assets/api.js (trata sessão expirada, falha de rede e erros do servidor)

let MESAS=[], CONVITES=[], CONVIDADOS=[], SEL=null, novaForma='redonda', novaCor='neutra', activeTab='pessoas';
// Nível de zoom (fator aplicado ao canvas). A vista 100% usa todo o espaço do canvas (fator 1).
let zoom=1;
// Dimensões guardadas do canvas (px). null = automático.
let CANVAS={largura:null, altura:null};
// Travas contra arrastos acidentais (guardadas na base de dados).
let BLOQ={mesas:false, canvas:false, scroll:false};

// ---------- visita de suporte com código de leitura ----------
// Aqui quase nada se faz por botões: arrasta-se uma mesa, larga-se uma pastilha
// noutra, escolhe-se numa lista que se abre. Esses gestos vivem em
// addEventListener, que o so-ver.js não consegue ler para os apagar — e um
// gesto que se completa e depois desfaz sozinho é pior do que um gesto que não
// arranca. Por isso é esta página, que sabe quais deles escrevem, a dizê-lo.
const SO_VER = !!window.SO_VER;
function travaLeitura(){
  if(!SO_VER) return false;
  if(typeof window.soVerAviso === 'function') window.soVerAviso();
  return true;
}
// Uma visita de leitura fixa as mesas e o canvas — mas sem mexer no BLOQ, que
// é o que o CASAL configurou e o que as caixas mostram. Confundir as duas
// coisas dava a quem vem ajudar uma leitura errada da planta do casal.
const mesasFixas = () => BLOQ.mesas  || SO_VER;
const canvasFixo = () => BLOQ.canvas || SO_VER;
// A vista, essa, NÃO se fecha a quem vem ajudar: deslocar o olhar não muda
// nada na planta do casal, e sem isso quem visita via só o pedaço que coubesse.
const vistaFixa  = () => BLOQ.scroll;
function aplicarBloqueios(){
  document.body.classList.toggle('bloq-mesas',  mesasFixas());
  document.body.classList.toggle('bloq-canvas', canvasFixo());
  document.body.classList.toggle('bloq-scroll', vistaFixa());
  const a=$('bloq-mesas'), b=$('bloq-canvas'), c=$('bloq-scroll');
  if(a) a.checked=BLOQ.mesas;
  if(b) b.checked=BLOQ.canvas;
  if(c) c.checked=BLOQ.scroll;
  ajustarScrollCanvas();
  // A nota de ajuda acompanha o que está (ou não) bloqueado.
  const dica=$('dica-planta'); if(!dica) return;
  const partes=[];
  if(SO_VER){
    dica.innerHTML='Visita de <b>leitura</b>: a planta mostra-se toda — toque numa mesa para ver '
                 + 'os detalhes. Mover mesas, sentar pessoas e redimensionar o canvas ficam de fora.';
    return;
  }
  partes.push(BLOQ.mesas
    ? 'As mesas estão <b>fixas</b>: toque numa mesa para ver os detalhes, sem risco de a arrastar.'
    : 'Arraste as mesas para as posicionar (alinham-se com linhas-guia). Toque numa mesa para ver os detalhes.');
  partes.push(BLOQ.canvas
    ? 'O canvas está <b>fixo</b>.'
    : 'Arraste as <b>bordas do canvas</b> para o redimensionar.');
  partes.push(BLOQ.scroll
    ? 'A <b>vista está fixa</b>: a planta não se desloca.'
    : 'Se o salão não couber, <b>desloque a vista</b> — as barras aparecem do lado que precisar.');
  dica.innerHTML=partes.join(' ');
}
async function guardarBloqueio(){
  BLOQ.mesas  = $('bloq-mesas').checked;
  BLOQ.canvas = $('bloq-canvas').checked;
  BLOQ.scroll = $('bloq-scroll').checked;
  aplicarBloqueios();
  const d=await api('planta_bloqueio',{method:'POST',body:JSON.stringify({
    bloq_mesas:BLOQ.mesas?1:0, bloq_canvas:BLOQ.canvas?1:0, bloq_scroll:BLOQ.scroll?1:0})});
  if(!d||!d.success) return toast((d&&d.message)||'Erro ao guardar.',true);
  toast(BLOQ.mesas||BLOQ.canvas||BLOQ.scroll ? 'Bloqueio guardado.' : 'Bloqueios removidos.');
}
let maximizado=false;

function aplicarCanvas(){ $('planta').style.setProperty('--z', zoom); }
function setZoom(z){
  zoom=+z;
  document.querySelectorAll('#zoombar button').forEach(b=>b.classList.toggle('on', +b.dataset.zoom===zoom));
  aplicarCanvas(); ajustarScrollCanvas();
}
// A vista desloca-se nos dois eixos sempre que o mundo for maior do que o
// espaço visível — com o zoom em cima, ou com um canvas mais estreito do que o
// salão. «auto» mostra a barra só do lado que precisa dela.
//
// Travada, fica 'hidden': a planta não foge do sítio a quem lhe pousar o dedo
// em cima. O CSS repete-o em body.bloq-scroll, para a trava valer mesmo que
// alguém mexa neste estilo pelo caminho.
function ajustarScrollCanvas(){
  $('planta-viewport').style.overflow = vistaFixa() ? 'hidden' : 'auto';
}

// Largura de conteúdo disponível no cartão (limite máximo do canvas).
function larguraDisponivel(){
  const cartao=$('planta-viewport').closest('.planta-cartao');
  const cs=getComputedStyle(cartao);
  return Math.floor(cartao.clientWidth - parseFloat(cs.paddingLeft||0) - parseFloat(cs.paddingRight||0));
}
// Aplica as dimensões guardadas (ou o automático) ao canvas.
function aplicarTamanhoCanvas(){
  const vp=$('planta-viewport');
  // Liberta a largura para medir o espaço real da coluna (senão um canvas largo
  // "estica" a coluna e a medição fica presa no valor anterior).
  vp.style.width='0px';
  const maxW=larguraDisponivel();
  let w, h;
  if(maximizado){
    // Preenche o espaço disponível (largura do cartão, altura até ao fundo do ecrã).
    w=maxW; vp.style.width=w+'px';
    const top=vp.getBoundingClientRect().top;
    h=Math.max(240, Math.round(window.innerHeight - top - 24));
  } else {
    w = CANVAS.largura ? Math.min(CANVAS.largura, maxW) : maxW;
    w = Math.max(280, w);
    h = CANVAS.altura ? CANVAS.altura : Math.round(w*10/16);
    h = Math.max(200, Math.min(2000, h));
  }
  vp.style.width=w+'px'; vp.style.height=h+'px';
}
// Maximizar/restaurar a tela de disposições, mantendo as abas ao lado.
function toggleMax(){
  maximizado=!maximizado;
  document.body.classList.toggle('mesas-max', maximizado);
  $('btn-max').title = maximizado ? 'Restaurar a planta' : 'Maximizar a planta';
  aplicarTamanhoCanvas(); ajustarScrollCanvas(); renderPlanta();
}
// Redimensionar arrastando as bordas do canvas.
let rz=null;
function iniciarRz(e, dir){
  if(canvasFixo()) return;   // canvas fixo: as bordas não redimensionam
  e.preventDefault();
  const vp=$('planta-viewport');
  rz={dir, sx:e.clientX, sy:e.clientY, w:vp.offsetWidth, h:vp.offsetHeight, maxW:larguraDisponivel()};
  document.body.classList.add('a-redimensionar');
  window.addEventListener('pointermove', rzMove);
  window.addEventListener('pointerup', rzUp, {once:true});
}
function rzMove(e){
  if(!rz) return;
  const vp=$('planta-viewport');
  if(rz.dir.includes('e')){ vp.style.width =Math.max(280, Math.min(rz.maxW, rz.w+(e.clientX-rz.sx)))+'px'; }
  if(rz.dir.includes('s')){ vp.style.height=Math.max(200, Math.min(2000,   rz.h+(e.clientY-rz.sy)))+'px'; }
}
async function rzUp(){
  window.removeEventListener('pointermove', rzMove);
  document.body.classList.remove('a-redimensionar');
  if(!rz) return; rz=null;
  const vp=$('planta-viewport');
  CANVAS.largura=Math.round(vp.offsetWidth); CANVAS.altura=Math.round(vp.offsetHeight);
  const d=await api('planta_size',{method:'POST',body:JSON.stringify({largura:CANVAS.largura, altura:CANVAS.altura})});
  if(!d||!d.success) return toast((d&&d.message)||'Erro ao guardar as dimensões.',true);
  if(d.canvas){ CANVAS={largura:numOuNull(d.canvas.largura), altura:numOuNull(d.canvas.altura)}; }
  toast('Dimensões do canvas guardadas.');
}

const FORMAS=[['redonda','Redonda'],['oval','Oval'],['quadrada','Quadrada'],['retangular','Retangular'],['comprida','Comprida'],['ferradura','Ferradura']];
const CORES=[['neutra','Marfim'],['verde','Verde'],['ouro','Ouro'],['terracota','Terracota'],['azul','Azul'],['ameixa','Ameixa'],['rosa','Rosa'],['salva','Salva']];
const ESTADOS={
  pessoas:[['','Todos os estados'],['confirmado','Confirmados'],['pendente','Pendentes'],['recusado','Recusados']],
  convites:[['','Todos os estados'],['confirmado','Confirmados'],['parcial','Parciais'],['pendente','Pendentes'],['recusado','Recusados']]
};
// O escolhedor de forma mostra a MESA, e não um quadrado de cor: cada botão é
// o desenho da planta, com as cadeiras à volta. Escolhe-se pelo que se vê, e
// não por adivinhar qual dos rectângulos é a «comprida».
const htmlFormas=sel=>FORMAS.map(([v,l])=>`<button type="button" data-forma="${v}" class="${v===sel?'on':''}" title="${l}">`
  + mesaIcone({forma:v, capacidade:v==='comprida'?10:v==='ferradura'?12:v==='oval'?8:6,
               rotulo:l}, {tam:30, numero:false}) + `</button>`).join('');
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
  papel:g.papel||null, mesa_efetiva_esp:g.mesa_efetiva_esp||null, genero:g.genero||'', brinde:+g.brinde||0}));
// Ícones sugestivos de género (e brinde) para as pastilhas com nomes.
const genIco=g=> g==='m'?'<span class="gi gi-m" title="Masculino">♂</span> ':g==='f'?'<span class="gi gi-f" title="Feminino">♀</span> ':'';
const brindeIco=b=> +b?' <span class="gi gi-b" title="Recebe brinde">🎁</span>':'';
const ehNoivos=m=>m&&m.especial==='noivos';
// Dimensão base (px) do nó da mesa: tamanho manual sobrepõe-se ao automático.
// A mesa dos noivos tem a dimensão de uma mesa comum (tamanho geral).
// Quanto é que a caixa do nó é maior do que o tampo. O desenho da mesa põe as
// cadeiras à volta dela, e o tampo fica a ocupar cerca de dois terços do
// quadrado: 1.6 devolve à mesa, no ecrã, o tamanho que ela tinha antes.
const CAIXA = 1.6;
function baseMesa(m){
  if(ehNoivos(m)) return 78;
  const t=m.tamanho;
  if(t==='p') return 62; if(t==='m') return 84; if(t==='g') return 108;
  const cap=+m.capacidade||4; return Math.max(58,Math.min(104, 58 + cap*3));
}

// ---------- carregar ----------
async function carregar(){
  const [dm,dc,dg]=await Promise.all([api('mesa_list'), api('convite_list'), api('convidado_list')]);
  if(!dm.success){ toast('Erro ao carregar mesas.',true); return; }
  MESAS=normMesas(dm.mesas); CONVITES=normConvites(dc&&dc.convites); CONVIDADOS=normConvidados(dg&&dg.convidados);
  if(dm.canvas){ CANVAS={largura:numOuNull(dm.canvas.largura), altura:numOuNull(dm.canvas.altura)};
    BLOQ={mesas:+dm.canvas.bloq_mesas===1, canvas:+dm.canvas.bloq_canvas===1,
          scroll:+dm.canvas.bloq_scroll===1}; aplicarBloqueios(); }
  aplicarCanvas(); aplicarTamanhoCanvas(); ajustarScrollCanvas();
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

function renderTudo(){ renderStats(); renderLegenda(); renderPlanta(); renderTabs(); renderTabBody(); atualizarBtnNoivos(); }

// A legenda diz o que o sinal quer dizer — e quantas mesas estão assim. Dizer
// só «Vazia» obriga a contar as bolinhas à mão para saber quantas faltam
// encher, que é a pergunta que se faz a seguir.
const LEGENDA=[
  ['vazia',   'Vazia',    'ninguém sentado'],
  ['parcial', 'A encher', 'já tem gente, ainda cabe'],
  ['cheia',   'Completa', 'não cabe mais ninguém'],
  ['excede',  'Excede',   'há mais gente do que lugares'],
];
function renderLegenda(){
  const cx=$('legenda'); if(!cx) return;
  const conta={};
  MESAS.forEach(m=>{ const e=estadoOcup(m); conta[e]=(conta[e]||0)+1; });
  cx.innerHTML=LEGENDA.map(([k,rot,expl])=>{
    const n=conta[k]||0;
    return `<span class="lg lg-${k}${n?'':' zero'}" title="${esc(rot)}: ${esc(expl)}">`
         + `<i></i><b>${esc(rot)}</b><span class="n">${n}</span></span>`;
  }).join('');
}
function atualizarBtnNoivos(){
  const b=$('btn-noivos'); if(!b) return;
  b.style.display = MESAS.some(ehNoivos) ? 'none' : ''; // só surge para repor, se tiver sido eliminada
}

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
    node.dataset.id=m.id;
    // A planta passa a desenhar a MESMA mesa que a lista e o escolhedor: o
    // tampo, uma cadeira por lugar e a lotação lá dentro. Antes eram dois
    // desenhos da mesma coisa — um rectângulo de cor aqui, o ícone ali — e só
    // um deles dizia quantos lugares havia.
    //
    // O ícone traz as cadeiras POR FORA do tampo, e por isso ocupa mais do que
    // o tampo ocupava: a caixa do nó cresce com ele (o CAIXA abaixo), para que
    // tudo o que se mede a partir dela — as pastilhas dos convidados, as alas
    // dos padrinhos — continue a bater certo.
    node.style.setProperty('--dbase', (d*CAIXA)+'px');
    node.style.left=px+'%'; node.style.top=py+'%';
    node.innerHTML=`<span class="mn-dot dot-${st}"></span>`
      + mesaIcone({ forma, capacidade:cap, ocupacao:oc, nome:m.nome },
                  { tam: Math.round(d*CAIXA) })
      + `<span class="mn-nome">${esc(m.nome)}</span>`
      + (noivos && oc ? `<span class="mn-ocup">${oc}</span>` : '');
    planta.appendChild(node);

    if(noivos){
      // Alas de padrinhos (esquerda) e madrinhas (direita) — detetadas pelo papel do convidado.
      [['esq','Padrinhos','padrinho'],['dir','Madrinhas','madrinha']].forEach(([lado,tit,pap])=>{
        const gente=CONVIDADOS.filter(g=>g.papel===pap);
        if(!gente.length) return;
        const ala=document.createElement('div');
        ala.className='noivos-ala '+lado; ala.style.setProperty('--dbase', (d*CAIXA)+'px');
        ala.style.left=px+'%'; ala.style.top=py+'%';
        ala.innerHTML=`<div class="ala-tit">${tit}</div>`+gente.map(g=>{
          const prim=(g.nome||'').split(' ')[0];
          return `<div class="ala-p" data-id="${g.id}" title="${esc(g.nome)} — ${tit}">${genIco(g.genero)}${esc(prim)}</div>`;
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
        cl.style.setProperty('--dbase', (d*CAIXA)+'px'); cl.style.left=px+'%'; cl.style.top=py+'%';
        cl.innerHTML=pessoas.map(g=>{ const prim=(g.nome||'').split(' ')[0];
          return `<span class="mp" data-tipo="pessoa" data-id="${g.id}" data-label="${esc(g.nome)}" title="${esc(g.nome)} — arraste para outra mesa">${genIco(g.genero)}${esc(prim)}</span>`;
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
  if(mesasFixas()){
    // Mesas fixas: não se arrastam, mas continuam selecionáveis ao toque.
    const sx=e.clientX, sy=e.clientY, id=+node.dataset.id;
    window.addEventListener('pointerup', ev=>{
      const parado = Math.abs(ev.clientX-sx)<4 && Math.abs(ev.clientY-sy)<4;
      if(parado) return alternar(id);
      // Foi mesmo uma tentativa de arrastar. Se as mesas estão fixas por ser
      // uma visita de leitura, diz-se — a trava da planta é do casal e essa
      // fala por si na nota, mas esta não.
      if(SO_VER) travaLeitura();
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
  } else { alternar(d.id); }
}
async function salvarPos(id,x,y,forma){
  const body={id, x:+x.toFixed(2), y:+y.toFixed(2)}; if(forma) body.forma=forma;
  await api('mesa_pos',{method:'POST',body:JSON.stringify(body)});
}

// ---------- abas ----------
// O id guarda-se sempre como número. Vindo de um data-attribute é texto, e
// «"29" === 29» é falso: bastava isso para a mesa escolhida deixar de se
// reconhecer a si própria e o segundo toque voltar a abri-la.
function selecionar(id){ SEL=+id||null; activeTab='mesa'; renderPlanta(); renderTabs(); renderTabBody(); }
// Tocar na mesa que já está escolhida fecha-a. Para largar a mesa era preciso
// acertar no fundo do canvas — e num salão cheio quase não há fundo por onde
// acertar. O gesto que abre é o mesmo que fecha.
function alternar(id){ if(SEL===(+id||null)) desselecionar(); else selecionar(id); }
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
        <span class="cd-nome">${genIco(g.genero)}${esc(g.nome)}${brindeIco(g.brinde)}</span><span class="cd-meta">${esc(g.convite_nome)} · ${meta}</span></div>`;
    }).join('');
  }
  box.innerHTML = html || `<div class="roster-vazio">${activeTab==='semmesa'?'Está tudo sentado. 🎉':'Nada corresponde aos filtros.'}</div>`;
  const cont=$('roster-conta'); if(cont) cont.textContent = total+unidade;
}

// ---------- dropdown de pesquisa (substitui os <select> longos) ----------
function comboHTML(kind, arg, placeholder, cls){
  return `<div class="combo ${cls||''}" data-kind="${kind}" data-arg="${arg??''}">
    <button type="button" class="combo-btn"><span class="combo-txt">${esc(placeholder)}</span><span class="combo-cx">▾</span></button>
    <div class="combo-pop" hidden>
      <input type="text" class="combo-search" placeholder="Procurar…" autocomplete="off">
      <div class="combo-list"></div>
    </div>
  </div>`;
}
function labelMesaPessoa(g){
  if(g.mesa_pessoa==null) return g.mesa_convite_nome ? 'Segue o convite' : 'Sem mesa';
  const m=MESAS.find(x=>x.id===g.mesa_pessoa); return m?m.nome:'Mesa';
}
// Opções de cada dropdown, conforme o seu tipo.
function comboOpcoes(kind, arg){
  if(kind==='mesa-pessoa'){
    const g=CONVIDADOS.find(x=>x.id==arg);
    const base={value:'', label:(g&&g.mesa_convite_nome)?('Segue o convite ('+g.mesa_convite_nome+')'):'Sem mesa'};
    return [base].concat(MESAS.filter(m=>!ehNoivos(m)).sort((a,b)=>(a.nome||'').localeCompare(b.nome||''))
      .map(m=>({value:String(m.id), label:m.nome, sub:(m.ocupacao||0)+(m.capacidade?('/'+m.capacidade):'')+' lug.'})));
  }
  if(kind==='trazer'){
    return CONVIDADOS.filter(g=>g.mesa_efetiva_id!==SEL)
      .sort((a,b)=>((a.mesa_efetiva_id?1:0)-(b.mesa_efetiva_id?1:0))||(a.nome||'').localeCompare(b.nome||''))
      .map(g=>({value:String(g.id), label:g.nome, sub:g.convite_nome+(g.mesa_efetiva_nome?(' · em '+g.mesa_efetiva_nome):' · sem mesa')}));
  }
  if(kind==='sentar'){
    return CONVITES.filter(c=>+c.mesa_id!==SEL)
      .sort((a,b)=>((a.mesa_id?1:0)-(b.mesa_id?1:0))||(a.nome_final||'').localeCompare(b.nome_final||''))
      .map(c=>({value:String(c.id), label:c.nome_final, sub:c.lugares+' lug.'+(c.mesa_id?(' · '+(c.mesa_nome||'mesa')):' · sem mesa')}));
  }
  if(kind==='papel-add'){
    return CONVIDADOS.filter(g=>g.papel!==arg).sort((a,b)=>(a.nome||'').localeCompare(b.nome||''))
      .map(g=>({value:String(g.id), label:g.nome, sub:g.convite_nome+(g.papel?(' · '+(g.papel==='padrinho'?'padrinho':'madrinha')):'')}));
  }
  return [];
}
function comboAcao(kind, arg, value){
  // Quatro escritas atrás de listas que se abrem: nenhuma tem botão que o
  // so-ver.js possa apagar, e todas passam por aqui.
  if(travaLeitura()) return;
  if(kind==='mesa-pessoa') return moverPessoa(+arg, value);
  if(kind==='trazer')      return trazerPessoa(value);
  if(kind==='sentar')      return sentar(value);
  if(kind==='papel-add')   return definirPapel(value, arg);
}
let comboAberto=null;
function fecharCombo(){ if(comboAberto){ const p=comboAberto.querySelector('.combo-pop'); if(p) p.hidden=true; comboAberto=null; } }
function abrirCombo(combo){
  if(comboAberto===combo){ fecharCombo(); return; }
  fecharCombo();
  const btn=combo.querySelector('.combo-btn'), pop=combo.querySelector('.combo-pop');
  const r=btn.getBoundingClientRect();
  pop.style.left =Math.round(r.left)+'px';
  pop.style.top  =Math.round(r.bottom+4)+'px';
  pop.style.width=Math.max(220, Math.round(r.width))+'px';
  pop.hidden=false; comboAberto=combo;
  renderComboLista(combo, '');
  const s=combo.querySelector('.combo-search'); s.value=''; setTimeout(()=>s.focus(), 0);
  const pr=pop.getBoundingClientRect();
  if(pr.bottom>window.innerHeight-8) pop.style.top=Math.max(8, Math.round(r.top-pr.height-4))+'px';
}
function renderComboLista(combo, q){
  const lista=combo.querySelector('.combo-list');
  q=(q||'').trim().toLowerCase();
  const ops=comboOpcoes(combo.dataset.kind, combo.dataset.arg)
    .filter(o=> !q || (o.label+' '+(o.sub||'')).toLowerCase().includes(q));
  lista.innerHTML = ops.length ? ops.map(o=>
    `<button type="button" class="combo-opt" data-value="${esc(o.value)}"><span>${esc(o.label)}</span>${o.sub?`<span class="combo-sub">${esc(o.sub)}</span>`:''}</button>`
  ).join('') : '<div class="combo-vazio">Nada corresponde.</div>';
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
          <span class="nm">${genIco(g.genero)}${esc(g.nome)}${brindeIco(g.brinde)}<br><small style="color:#9aa09a">${esc(g.convite_nome)}</small></span>
          ${comboHTML('mesa-pessoa', g.id, labelMesaPessoa(g), 'combo-inline')}
        </div>`).join('') : '<div class="vazio-mini">Ainda ninguém sentado nesta mesa.</div>'}
      ${notas.map(n=>`<div class="vazio-mini">+ ${n.extra} lugar(es) sem nome · ${esc(n.nome)}</div>`).join('')}
    </div>

    <div class="rot">Trazer uma pessoa para esta mesa</div>
    ${comboHTML('trazer', '', 'Escolher pessoa…')}

    <div class="rot">Sentar convite inteiro nesta mesa</div>
    ${comboHTML('sentar', '', 'Escolher convite…')}
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
      <span class="nm">${genIco(g.genero)}${esc(g.nome)}${brindeIco(g.brinde)}<br><small style="color:#9aa09a">${esc(g.convite_nome)}</small></span>
      <select class="sel-mini" title="Papel do convidado" onchange="definirPapel(${g.id}, this.value)">
        <option value="padrinho" ${g.papel==='padrinho'?'selected':''}>Padrinho (esq.)</option>
        <option value="madrinha" ${g.papel==='madrinha'?'selected':''}>Madrinha (dir.)</option>
        <option value="">Remover papel</option>
      </select>
    </div>`;
  const bloco=(tit,arr)=>`<div class="rot">${tit} (${arr.length})</div>
    <div class="lista-sentados">${arr.length?arr.map(linhaPapel).join(''):'<div class="vazio-mini">Ainda ninguém.</div>'}</div>`;
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
    <div class="mesa-form" style="gap:.5rem">
      <div style="flex:1 1 130px">${comboHTML('papel-add', 'padrinho', '+ Padrinho…')}</div>
      <div style="flex:1 1 130px">${comboHTML('papel-add', 'madrinha', '+ Madrinha…')}</div>
    </div>

    <div class="acoes-bloco">
      <button class="btn btn-fantasma btn-sm" style="flex:1;justify-content:center;color:var(--danger);border-color:#e6c3bf" onclick="eliminar(${m.id})">Eliminar mesa</button>
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
async function adicionarNoivos(){
  if(MESAS.some(ehNoivos)) return;
  const d=await api('mesa_noivos',{method:'POST',body:JSON.stringify({})});
  if(!d.success) return toast(d.message||'Erro ao repor a mesa dos noivos.',true);
  MESAS=normMesas(d.mesas);
  await recarregarDados(); // recalcula a mesa efetiva dos padrinhos/madrinhas
  await autoPosicionar();
  SEL=d.id||null; activeTab='mesa';
  renderTudo();
  toast('Mesa dos noivos reposta.');
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
  const r = await licConfirmar({
    titulo: 'Eliminar a mesa «' + licEsc(nome) + '»?',
    icone: '🪑', perigo: true, confirmar: 'Eliminar mesa',
    texto: 'Os <b>convites e pessoas sentados ficam sem mesa</b> — ninguém é apagado, só '
         + 'perdem o lugar.<br><br>A mesa sai da planta, e para a ter de volta cria-se outra.'
  });
  if (!r.sim) return;
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
    // Aqui, e não no pointerdown: assim um toque para ver não dá aviso nenhum,
    // e só quem tenta mesmo arrastar ouve a razão.
    if(travaLeitura()){ pend=null; return; }
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
  // A mesa dos noivos aceita PESSOAS (que se tornam padrinho/madrinha), mas não convites.
  if(node && ehNoivos(MESAS.find(x=>x.id===+node.dataset.id)) && (!arrItem || arrItem.tipo!=='pessoa')) node=null;
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
  if(ehNoivos(alvo)){
    // Largar na mesa dos noivos torna a pessoa padrinho/madrinha. Convites não entram.
    if(item.tipo!=='pessoa') return toast('Na mesa dos noivos só entram padrinhos e madrinhas.',true);
    const g=CONVIDADOS.find(x=>x.id===item.id);
    // Género define o papel (♂ padrinho, ♀ madrinha); sem género, decide o lado onde se largou.
    let papel = g&&g.genero==='m' ? 'padrinho' : g&&g.genero==='f' ? 'madrinha' : null;
    if(!papel){ const r=node.getBoundingClientRect(); papel = (e.clientX < r.left + r.width/2) ? 'padrinho' : 'madrinha'; }
    await definirPapel(item.id, papel);
    return;
  }
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
document.querySelectorAll('.rz').forEach(h=> h.addEventListener('pointerdown', e=> iniciarRz(e, h.dataset.dir)));
window.addEventListener('resize', ()=>{ fecharCombo(); aplicarTamanhoCanvas(); ajustarScrollCanvas(); });
window.addEventListener('keydown', e=>{ if(e.key==='Escape'){ if(comboAberto) fecharCombo(); else if(maximizado) toggleMax(); } });

// Dropdowns de pesquisa no painel de abas (delegação — o conteúdo é recriado a cada render).
$('tab-body').addEventListener('click', e=>{
  const opt=e.target.closest('.combo-opt');
  if(opt){ const combo=opt.closest('.combo'); const {kind,arg}=combo.dataset; const v=opt.dataset.value||''; fecharCombo(); comboAcao(kind, arg, v); return; }
  const btn=e.target.closest('.combo-btn');
  if(btn){ abrirCombo(btn.closest('.combo')); }
});
$('tab-body').addEventListener('input', e=>{ const s=e.target.closest('.combo-search'); if(s) renderComboLista(s.closest('.combo'), s.value); });
$('tab-body').addEventListener('scroll', fecharCombo, true);
document.addEventListener('pointerdown', e=>{ if(comboAberto && !e.target.closest('.combo')) fecharCombo(); }, true);
window.addEventListener('scroll', ()=>{ if(comboAberto) fecharCombo(); }, true);
aplicarCanvas();

carregar();
