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
    await page.goto(BASE+'/registo.php',{waitUntil:'networkidle'}); assert.equal(await page.locator('[data-exemplo],[data-exemplo-pac]').count(),0,'o registo não mostra exemplos dos módulos');
    await page.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'}); assert.equal(await page.locator('.demo-app-topo').count(),7,'cada módulo reproduz o cabeçalho da aplicação');
    await page.goto(BASE+'/login.php',{waitUntil:'networkidle'}); await page.fill('[name=utilizador]',USER); await page.fill('[name=senha]',PASSWORD); await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');
    await page.goto(BASE+'/plataforma.php?vista=atendimento',{waitUntil:'networkidle'});
    assert.equal(await page.locator('.topo .nav a:not([href="logout.php"])').count(),9); assert(await page.locator('.topo .nav a.ativo').getAttribute('href').then(x=>x.includes('atendimento')));
    assert(await page.locator('#vista-atendimento').isVisible());
    await page.goto(BASE+'/modelos.php',{waitUntil:'domcontentloaded'}); await page.locator('.topo .nav').waitFor(); assert.equal(await page.locator('.topo .nav a:not([href="logout.php"])').count(),9); assert.match(await page.locator('.topo .nav a.ativo').innerText(),/Modelos/);
    console.log('PASS: o menu administrativo é único e abre a vista pedida');
    const abriu=await page.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}).then(r=>r.json()));
    assert.equal(abriu.success,true,'abrir o casamento de teste');
    await page.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'}); assert.equal(await page.locator('.aj-card').count(),14); assert.equal(await page.locator('.aj-submenu button').count(),8); assert.equal(await page.locator('.aj-passo').count(),42); assert.equal(await page.locator('.aj-demonstracao picture').count(),42); assert.equal(await page.locator('.aj-demonstracao source[media]').count(),42); assert.equal(await page.locator('.aj-cena.ativo').count(),14); assert.equal(await page.locator('.aj-imagem').count(),14); assert.equal(await page.locator('.aj-sticker').count(),42); assert.equal(await page.locator('.aj-scroll-marca').count(),16); assert.equal(await page.locator('.aj-sticker-marca').evaluateAll(xs=>xs.every(x=>['--dx','--dy','--mx','--my'].every(v=>{const n=parseFloat(x.style.getPropertyValue(v));return n>=0&&n<=100;}))),true,'os 84 pontos estão calibrados dentro das capturas'); assert.equal(await page.locator('video').count(),0);
    const fluxoConvite=page.locator('.aj-card[data-modulo="convidados"][data-topico="0"]');assert.equal(await fluxoConvite.locator('.aj-cena').evaluateAll(xs=>xs.every((x,i)=>x.querySelector('img').src.includes(`convidados-1-${i+1}-desktop.jpg`)&&x.querySelector('source').srcset.includes(`convidados-1-${i+1}-mobile.jpg`))),true,'Novo convite, formulário e Guardar usam cenas próprias');
    const fluxoDespesa=page.locator('.aj-card[data-modulo="orcamento"][data-topico="0"]');assert.equal(await fluxoDespesa.locator('.aj-cena').evaluateAll(xs=>xs.every((x,i)=>x.querySelector('img').src.includes(`orcamento-1-${i+1}-desktop.jpg`)&&x.querySelector('source').srcset.includes(`orcamento-1-${i+1}-mobile.jpg`))),true,'Nova despesa também usa uma cena visível por passo');
    for(const modulo of ['mesas','impresso','digital']){const fluxo=page.locator(`.aj-card[data-modulo="${modulo}"][data-topico="0"]`);assert.equal(await fluxo.locator('.aj-cena').evaluateAll((xs,m)=>xs.every((x,i)=>x.querySelector('img').src.includes(`${m}-1-${i+1}-desktop.jpg`)&&x.querySelector('source').srcset.includes(`${m}-1-${i+1}-mobile.jpg`)),modulo),true,`${modulo} mostra cada controlo no estado real`);}await snap('ajuda-desktop');
    await page.fill('#aj-busca','convidado mesa'); assert((await page.locator('.aj-card:visible').count())>=1); assert.match(await page.locator('#aj-resultado').innerText(),/tópico/);
    await page.fill('#aj-busca',''); await page.locator('[data-aj-modulo="bar"]').click(); assert.equal(await page.locator('.aj-card:visible').count(),2); assert.match(await page.locator('.aj-card:visible').last().textContent(),/Acompanhar Um Pedido/i);
    await page.setViewportSize({width:390,height:844}); await page.reload({waitUntil:'networkidle'}); const larguraAjuda=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,janela:innerWidth})); console.log('Ajuda mobile:',larguraAjuda); assert.equal(larguraAjuda.scroll<=larguraAjuda.janela+1,true);
    await snap('ajuda-mobile');
    const guia=page.locator('.aj-card:visible .aj-guia').first(),alturaImagem=await guia.locator('.aj-demonstracao').evaluate(e=>e.getBoundingClientRect().height),segundo=guia.locator('.aj-passo').nth(1);await segundo.locator('summary').click();assert.equal(await segundo.getAttribute('open'),'');assert.equal(await segundo.locator('.aj-passo-detalhe p').isVisible(),true);assert.equal(await guia.locator('.aj-sticker-marca.ativo[data-sticker-passo="1"]').count(),1);assert(Math.abs((await guia.locator('.aj-demonstracao').evaluate(e=>e.getBoundingClientRect().height))-alturaImagem)<2);
    const terceiro=guia.locator('.aj-passo').nth(2);await terceiro.locator('summary').click();assert.equal(await guia.locator('.aj-scroll-marca.ativo[data-scroll-passo="2"]').count(),1,'a mudança distante mostra a rolagem antes do alvo');
    assert.match(await guia.locator('.aj-cena.ativo img').getAttribute('src'),/assets\/ajuda\/capturas\/.+\.jpg/);assert.equal(await guia.locator('.aj-cena.ativo img').evaluate(e=>e.complete&&e.naturalWidth>=300),true);
    console.log('PASS: fórum de ajuda mantém a captura ao lado e expande só a narração do passo');
    assert.deepEqual(errors,[],'erros JavaScript');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
