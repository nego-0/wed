// Gera a grelha de revisão dos 42 alvos em desktop e mobile.
// Os ficheiros são temporários: HELP_STICKER_REVIEW indica a pasta de saída.
const { chromium } = require('playwright-core');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8920';
const USER=process.env.TEST_USER||'admin',PASSWORD=process.env.TEST_PASSWORD;
const OUT=process.env.HELP_STICKER_REVIEW;
const EXE=process.env.CHROMIUM||(process.platform==='win32'?'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe':'/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
if(!PASSWORD||!OUT)throw new Error('Defina TEST_PASSWORD e HELP_STICKER_REVIEW.');
fs.mkdirSync(OUT,{recursive:true});
(async()=>{
 const browser=await chromium.launch({executablePath:EXE,headless:true});
 const page=await browser.newPage({viewport:{width:1280,height:900}});
 await page.goto(BASE+'/login.php');await page.fill('[name=utilizador]',USER);await page.fill('[name=senha]',PASSWORD);await page.click('button[type=submit]');await page.waitForLoadState('networkidle');
 const abriu=await page.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}).then(r=>r.json()));assert(abriu.success);
 for(const [dispositivo,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
  await page.setViewportSize(viewport);await page.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'});
  const total=await page.locator('.aj-card').count(),contagem={};assert.equal(total,14);
  for(let i=0;i<total;i++){
   const card=page.locator('.aj-card').nth(i),modulo=await card.getAttribute('data-modulo');contagem[modulo]=(contagem[modulo]||0)+1;const topico=contagem[modulo];
   const raiz=card.locator(':scope > details');if(!(await raiz.getAttribute('open')!==null))await raiz.locator(':scope > summary').click();
   await card.scrollIntoViewIfNeeded();const guia=card.locator('.aj-guia'),passos=guia.locator('.aj-passo');assert.equal(await passos.count(),3);
   for(let passo=0;passo<3;passo++){
    const detalhe=passos.nth(passo);if(await detalhe.getAttribute('open')===null)await detalhe.locator(':scope > summary').click();
    const scroll=guia.locator(`[data-scroll-passo="${passo}"]`),temScroll=await scroll.count()&&await scroll.evaluate((e,d)=>e.classList.contains(d==='desktop'?'rolagem-d':'rolagem-m'),dispositivo);
    if(temScroll){await page.waitForTimeout(420);await guia.locator('.aj-demonstracao').screenshot({path:path.join(OUT,`${modulo}-${topico}-${passo+1}-${dispositivo}-rolagem.png`)});}
    await page.waitForTimeout(700);
    const alvo=guia.locator(`.aj-sticker-marca.ativo[data-sticker-passo="${passo}"]`),foto=guia.locator('.aj-demonstracao picture');
    const [a,f]=await Promise.all([alvo.boundingBox(),foto.boundingBox()]);assert(a&&f&&a.x>=f.x&&a.x<=f.x+f.width&&a.y>=f.y&&a.y<=f.y+f.height,`${modulo}/${topico}/${passo+1}/${dispositivo}: alvo fora da captura`);
    await guia.locator('.aj-demonstracao').screenshot({path:path.join(OUT,`${modulo}-${topico}-${passo+1}-${dispositivo}.png`)});
   }
  }
 }
 await browser.close();console.log('PASS: 84 alvos revistos e dentro das respectivas capturas.');
})().catch(e=>{console.error(e);process.exit(1)});
