const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const USER = process.env.TEST_USER || 'admin', PASSWORD = process.env.TEST_PASSWORD;
(async()=>{
  if(!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
  const browser=await chromium.launch({executablePath:EXE});
  const ctx=await browser.newContext({viewport:{width:1440,height:950},reducedMotion:'reduce'});
  const page=await ctx.newPage(), errors=[]; page.on('pageerror',e=>errors.push(e.message));
  const snap=async name=>{if(!process.env.SCREENSHOTS)return;fs.mkdirSync(process.env.SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.SCREENSHOTS,name+'.png'),fullPage:true});};
  try{
    for(const [url,selector] of [['/login.php','.entrada-visual>img'],['/registo.php','.reg-hero>img'],['/atendimento.php','.mk-cinema>img']]){
      await page.goto(BASE+url,{waitUntil:'networkidle'}); assert(await page.locator(selector).isVisible(),url+' mostra a imagem editorial');
      assert.equal(await page.locator(selector).evaluate(e=>e.complete&&e.naturalWidth>500),true,url+' carrega a imagem');
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,url+' sem transbordo');
      await snap(url.slice(1,-4));
    }
    console.log('PASS: entrada, registo e demonstração usam as novas imagens');
    await page.goto(BASE+'/login.php',{waitUntil:'networkidle'}); await page.fill('[name=utilizador]',USER); await page.fill('[name=senha]',PASSWORD); await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');
    await page.goto(BASE+'/plataforma.php?vista=atendimento',{waitUntil:'networkidle'});
    assert.equal(await page.locator('.admin-nav a').count(),9); assert(await page.locator('.admin-nav a.ativo').getAttribute('href').then(x=>x.includes('atendimento')));
    assert(await page.locator('#vista-atendimento').isVisible());
    await page.goto(BASE+'/modelos.php',{waitUntil:'domcontentloaded'}); await page.locator('.admin-nav').waitFor(); assert.equal(await page.locator('.admin-nav a').count(),9); assert.match(await page.locator('.admin-nav a.ativo').innerText(),/Modelos/);
    console.log('PASS: o menu administrativo é único e abre a vista pedida');
    const abriu=await page.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}).then(r=>r.json()));
    assert.equal(abriu.success,true,'abrir o casamento de teste');
    await page.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'}); assert.equal(await page.locator('.aj-card').count(),7); assert.equal(await page.locator('.aj-submenu button').count(),8);
    await page.fill('#aj-busca','convidado mesa'); assert((await page.locator('.aj-card:visible').count())>=1); assert.match(await page.locator('#aj-resultado').innerText(),/guia/);
    await page.fill('#aj-busca',''); await page.locator('[data-aj-modulo="bar"]').click(); assert.equal(await page.locator('.aj-card:visible').count(),1); assert.match(await page.locator('.aj-card:visible').textContent(),/ACOMPANHAR UM PEDIDO/);
    await page.setViewportSize({width:390,height:844}); await page.reload({waitUntil:'networkidle'}); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
    await snap('ajuda-mobile');
    console.log('PASS: ajuda pesquisa, filtra por módulo e cabe no telemóvel');
    assert.deepEqual(errors,[],'erros JavaScript');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
