const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
  const errs=[]; p.on('console',m=>{if(m.type()==='error')errs.push(m.text())}); p.on('pageerror',e=>errs.push('PE:'+e.message));
  const log=(...a)=>console.log('•',...a);
  await p.goto(BASE+'/login.php'); await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit],input[type=submit]'); await p.waitForLoadState('networkidle');
  await p.goto(BASE+'/index.php',{waitUntil:'networkidle'}); await p.waitForTimeout(700);
  // click the "Mesa A" filter chip
  const chip=p.locator('.chips-mesa, [id]').locator('text=Mesa A').first();
  // Fallback: find chip button containing "Mesa A"
  await p.locator('button:has-text("Mesa A"), .chip:has-text("Mesa A")').first().click();
  await p.waitForTimeout(700);
  const cards=await p.evaluate(()=> [...document.querySelectorAll('.convite-nome')].map(n=>n.textContent.trim()));
  const metas=await p.evaluate(()=> [...document.querySelectorAll('.convite-meta')].map(m=>m.textContent.replace(/\s+/g,' ').trim()));
  log('convite cards under "Mesa A" filter:', JSON.stringify(cards));
  log('first card meta:', metas[0]||'(none)');
  log('shows the dragged convite under Mesa A:', cards.some(c=>/Família Teste/.test(c)));
  await p.screenshot({path:OUT+'/panel_filter.png'});
  console.log('ERRORS:', errs.length?errs.join('\n'):'none');
  await b.close();
})().catch(e=>{console.error('FATAL',e);process.exit(1);});
