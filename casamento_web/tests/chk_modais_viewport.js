const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROMIUM});
try {const page=await browser.newPage({viewport:{width:390,height:660}}); const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base=process.env.BASE_URL||'http://127.0.0.1:8921';
await page.goto(base+'/login.php');await page.fill('[name=utilizador]','admin@local');await page.fill('[name=senha]','noivos2026');await page.click('button[type=submit]');await page.waitForLoadState('networkidle');
await page.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}));
for(const [url,ids] of [['index.php',['ov-convite','ov-qr','ov-mesas','ov-mensagens','ov-entradas','ov-lembretes','ov-perguntas','ov-reciclagem','ov-historico']],['orcamento.php',['m-desp','m-pag']],['plataforma.php',['ov-licenca','ov-editar']]]){
await page.goto(base+'/'+url,{waitUntil:'networkidle'});
if(url==='plataforma.php')assert(await page.locator('body').evaluate(el=>el.classList.contains('admin-plataforma')));
for(const height of [660,320]){await page.setViewportSize({width:390,height});
for(const id of ids){await page.evaluate(id=>{const el=document.getElementById(id);el.classList.add('aberto');},id);await page.waitForTimeout(80);
const state=await page.evaluate(id=>{const o=document.getElementById(id),m=o.querySelector('.modal'),r=m.getBoundingClientRect(),v=visualViewport; const y=Math.max(2,r.top+4),hit=document.elementFromPoint(r.left+r.width/2,y); m.scrollTop=m.scrollHeight;const topo=m.querySelector('.modal-topo');return {top:r.top,bottom:r.bottom,h:v.height,front:m.contains(hit),end:m.scrollTop+m.clientHeight>=m.scrollHeight-2,topo:topo?getComputedStyle(topo).position:''};},id);
assert(state.top>=0&&state.bottom<=state.h+1,JSON.stringify({id,height,state}));assert(state.front,id+' covered');assert(state.end,id+' bottom inaccessible');
if(url==='plataforma.php')assert.notEqual(state.topo,'sticky',id+' header fixed');
await page.evaluate(id=>document.getElementById(id).classList.remove('aberto'),id);
} } console.log('PASS '+url+' all modal bounds, front layer, bottom reachable');}
await page.evaluate(()=>licFormulario({titulo:'Longo',campos:[{id:'texto',rot:'Texto',tipo:'area',linhas:30}],aoGuardar:()=>true}));await page.waitForTimeout(100);
assert(await page.locator('#lic-jo').isVisible());const fluxo=await page.evaluate(()=>({cx:getComputedStyle(document.querySelector('.pl-modal-cx')).overflowY,corpo:getComputedStyle(document.querySelector('.pl-modal-corpo')).overflowY}));assert.notEqual(fluxo.cx,'visible');assert.equal(fluxo.corpo,'visible');await page.locator('.pl-modal-cx').evaluate(el=>el.scrollTop=el.scrollHeight);await page.locator('#lic-jc').click();
assert.equal(errors.length,0,errors.join('\n'));console.log('PASS shared dialog and no JS errors');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
