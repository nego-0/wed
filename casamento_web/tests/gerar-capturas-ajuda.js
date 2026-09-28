// Recria as capturas responsivas da Ajuda a partir das páginas reais.
// Requer uma instalação local com dados de demonstração e uma conta admin.
// BASE_URL, TEST_USER, TEST_PASSWORD e CHROMIUM podem ser definidos no ambiente.
const { chromium } = require('playwright-core');
const fs = require('node:fs'), path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const USER = process.env.TEST_USER || 'admin';
const PASSWORD = process.env.TEST_PASSWORD;
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const OUT = process.env.HELP_CAPTURE_OUT || path.join(__dirname,'..','assets','ajuda','capturas');
const SO_CENAS = process.env.HELP_CAPTURE_SCENES_ONLY === '1' ||
  process.env.HELP_CAPTURE_MODAL_ONLY === '1'; // nome antigo, mantido por compatibilidade
if(!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
fs.mkdirSync(OUT,{recursive:true});
const alvosGerados=[];

const modulos = [
  ['convidados',[
    ['/index.php',async p=>p.evaluate(()=>novoConvite())], ['/index.php',null]
  ]],
  ['mesas',[
    ['/mesas.php',async p=>p.evaluate(()=>{const d=document.getElementById('barra-add-dobra');if(d)d.open=true;})], ['/mesas.php',null]
  ]],
  ['impresso',[
    ['/editor-cartao.php',null], ['/manual.php?peca=cartao',null]
  ]],
  ['digital',[
    ['/convite-editor.php',null], ['/convite-digital.php?demo=1',null]
  ]],
  ['porta',[
    ['/porteiro.php',null], ['/porteiro.php',async p=>p.locator('#tab-ent').click()]
  ]],
  ['bar',[
    ['/bar.php',null], ['/copa.php',null]
  ]],
  ['orcamento',[
    ['/orcamento.php',async p=>p.evaluate(()=>abrirDespesa())], ['/orcamento.php',null]
  ]],
];

async function continuarEditor(p){
  const aviso=p.locator('.esp-ok:visible');
  if(await aviso.count()) await aviso.first().click();
  await p.locator('.esp-aviso.on').waitFor({state:'hidden'}).catch(()=>{});
}
async function centrarAlvo(p,selector){
  const alvo=p.locator(selector+':visible').first();
  await alvo.waitFor();
  await alvo.evaluate(e=>e.scrollIntoView({block:'center',inline:'nearest'}));
}

// Uma única fotografia não pode explicar passos que mudam o estado da página:
// antes/depois de abrir um modal, um formulário dobrável ou o próprio editor.
// Cada alvo abaixo tem de estar realmente visível quando a cena é capturada.
const sequenciasComCenas = [
  {nome:'convidados',topico:1,url:'/index.php',passos:[
    {alvo:'button[onclick="novoConvite()"]',fazer:async p=>p.locator('button[onclick="novoConvite()"]:visible').first().scrollIntoViewIfNeeded()},
    {alvo:'#c-nome',fazer:async p=>{await p.locator('button[onclick="novoConvite()"]:visible').first().click();await p.locator('#ov-convite.aberto').waitFor();await p.locator('#ov-convite .modal').evaluate(e=>e.scrollTop=0);}},
    {alvo:'#ov-convite button[onclick="guardarConvite()"]',fazer:async p=>p.locator('#ov-convite .modal').evaluate(e=>e.scrollTop=e.scrollHeight)},
  ]},
  {nome:'orcamento',topico:1,url:'/orcamento.php',passos:[
    {alvo:'button[onclick="abrirDespesa()"]',fazer:async p=>p.locator('button[onclick="abrirDespesa()"]:visible').first().scrollIntoViewIfNeeded()},
    {alvo:'#md-desc',fazer:async p=>{await p.locator('button[onclick="abrirDespesa()"]:visible').first().click();await p.locator('#m-desp.aberto').waitFor();await p.locator('#m-desp .modal').evaluate(e=>e.scrollTop=0);}},
    {alvo:'#m-desp button[onclick="guardarDespesa()"]',fazer:async p=>p.locator('#m-desp .modal').evaluate(e=>e.scrollTop=e.scrollHeight)},
  ]},
  {nome:'mesas',topico:1,url:'/mesas.php',passos:[
    {alvo:{desktop:'#nova-nome',mobile:'#barra-add-dobra > summary'},fazer:async p=>{const mov=p.viewportSize().width<=760;await p.locator('#barra-add-dobra').evaluate((e,fechar)=>e.open=!fechar,mov);await p.locator(mov?'#barra-add-dobra > summary':'#nova-nome').scrollIntoViewIfNeeded();}},
    {alvo:'#nova-nome',fazer:async p=>{await p.locator('#barra-add-dobra').evaluate(e=>e.open=true);await p.locator('#nova-nome').scrollIntoViewIfNeeded();}},
    {alvo:'.mesa-node',fazer:async p=>{await p.locator('#barra-add-dobra').evaluate(e=>e.open=false);await p.locator('.mesa-node').first().scrollIntoViewIfNeeded();}},
  ]},
  {nome:'impresso',topico:1,url:'/graficas.php',passos:[
    {alvo:'a[href="editor-cartao.php"]',fazer:async p=>centrarAlvo(p,'a[href="editor-cartao.php"]')},
    {alvo:'#camadas .camada',fazer:async p=>{await p.goto(BASE+'/editor-cartao.php',{waitUntil:'networkidle'});await continuarEditor(p);await centrarAlvo(p,'#camadas .camada');}},
    {alvo:'.vs-fim button[data-ac="nova"]',fazer:async p=>{await p.locator('#bt-versao').click();await p.locator('.vs-jan.aberta').waitFor();await p.waitForTimeout(900);await p.locator('.vs-fim button[data-ac="nova"]').scrollIntoViewIfNeeded();}},
  ]},
  {nome:'digital',topico:1,url:'/digital.php',passos:[
    {alvo:'a[href="convite-editor.php"]',fazer:async p=>centrarAlvo(p,'a[href="convite-editor.php"]')},
    {alvo:'#camadas .camada',fazer:async p=>{await p.goto(BASE+'/convite-editor.php',{waitUntil:'networkidle'});await continuarEditor(p);await centrarAlvo(p,'#camadas .camada');}},
    {alvo:'.vs-fim button[data-ac="nova"]',fazer:async p=>{await p.locator('#bt-versao').click();await p.locator('.vs-jan.aberta').waitFor();await p.waitForTimeout(900);await p.locator('.vs-fim button[data-ac="nova"]').scrollIntoViewIfNeeded();}},
  ]},
];

async function enquadrar(p){
  await p.waitForTimeout(500);
  await p.evaluate(()=>{const m=document.querySelector('main');if(m)scrollTo({top:Math.max(0,m.offsetTop-8),behavior:'instant'});});
  await p.waitForTimeout(250);
}
async function guardar(p,nome,topico,dispositivo){
  await enquadrar(p);
  await p.screenshot({path:path.join(OUT,`${nome}-${topico}-${dispositivo}.jpg`),type:'jpeg',quality:84,fullPage:false});
}
async function guardarCena(p,cena,passo,dispositivo){
  const selector=typeof cena.alvo==='string'?cena.alvo:cena.alvo[dispositivo];
  const alvo=p.locator(selector+':visible').first();await alvo.waitFor();await p.waitForTimeout(260);
  const b=await alvo.boundingBox(),v=p.viewportSize();
  if(!b||b.x<0||b.y<0||b.x+b.width>v.width||b.y+b.height>v.height)
    throw new Error(`${cena.nome||''}/${passo}/${dispositivo}: alvo fora do enquadramento`);
  await p.screenshot({path:path.join(OUT,`${cena.modulo}-${cena.topico}-${passo}-${dispositivo}.jpg`),type:'jpeg',quality:88,fullPage:false});
  return {x:+((b.x+b.width/2)/v.width*100).toFixed(1),y:+((b.y+b.height/2)/v.height*100).toFixed(1)};
}
(async()=>{
  const browser=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const ctx=await browser.newContext({viewport:{width:1280,height:800},deviceScaleFactor:1,reducedMotion:'reduce'});
  const p=await ctx.newPage();
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('[name=utilizador]',USER); await p.fill('[name=senha]',PASSWORD);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const resposta=await p.evaluate(async()=>{const r=await fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}});return {estado:r.status,texto:await r.text()};});
  let abriu;try{abriu=JSON.parse(resposta.texto);}catch(_){throw new Error(`Abrir casamento devolveu HTTP ${resposta.estado}: ${resposta.texto}`);}
  if(!abriu.success)throw new Error('Não foi possível abrir o casamento de demonstração: '+(abriu.error||'erro desconhecido'));
  for(const [dispositivo,viewport] of [['desktop',{width:1280,height:800}],['mobile',{width:390,height:780}]]){
    await p.setViewportSize(viewport);
    if(!SO_CENAS)for(const [nome,topicos] of modulos){
      for(let i=0;i<topicos.length;i++){
        const [url,preparar]=topicos[i]; await p.goto(BASE+url,{waitUntil:'networkidle'});
        try{if(preparar)await preparar(p);}catch(e){console.warn(`${nome}/${i+1}: ${e.message}`);}
        await guardar(p,nome,i+1,dispositivo);
      }
      console.log(`CAPTURA ${nome} · ${dispositivo}`);
    }
    for(const fluxo of sequenciasComCenas){
      await p.goto(BASE+fluxo.url,{waitUntil:'networkidle'});
      for(let i=0;i<fluxo.passos.length;i++){
        const cena={...fluxo.passos[i],modulo:fluxo.nome,topico:fluxo.topico};await cena.fazer(p);
        const ponto=await guardarCena(p,cena,i+1,dispositivo);
        alvosGerados.push({modulo:fluxo.nome,topico:fluxo.topico,passo:i+1,dispositivo,...ponto});
        console.log(`ALVO ${fluxo.nome}/${fluxo.topico}/${i+1} · ${dispositivo}: ${ponto.x},${ponto.y}`);
      }
    }
  }
  fs.writeFileSync(path.join(OUT,'alvos-cenas.json'),JSON.stringify(alvosGerados,null,2));
  await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
