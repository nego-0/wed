const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1400,height:950}})).newPage();
  const errs=[]; p.on('console',m=>{if(m.type()==='error')errs.push(m.text())}); p.on('pageerror',e=>errs.push('PE:'+e.message));
  const log=(...a)=>console.log('•',...a);
  await p.goto(BASE+'/login.php'); await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit],input[type=submit]'); await p.waitForLoadState('networkidle');
  await p.goto(BASE+'/index.php',{waitUntil:'networkidle'}); await p.waitForTimeout(700);

  const cards=await p.locator('#stats .stat-f').evaluateAll(els=>els.map(e=>({label:e.querySelector('.sl')?.textContent, n:e.querySelector('.sn')?.textContent})));
  log('stat cards:', JSON.stringify(cards));
  const stats=await p.evaluate(()=>STATS);
  log('stats gender/brinde:', JSON.stringify({m:stats.pes_masculino, f:stats.pes_feminino, brinde:stats.pes_brinde}));

  // click Feminino card -> filters
  await p.locator('#stats .stat-f', {hasText:'Feminino'}).click(); await p.waitForTimeout(500);
  const activeF=await p.locator('#stats .stat-f.ativo .sl').textContent();
  const convF=await p.evaluate(()=>CONVITES.length);
  log('after clicking Feminino card -> active card:', activeF, '| convites:', convF);
  // click Brindes card
  await p.locator('#stats .stat-f', {hasText:'Todos'}).click(); await p.waitForTimeout(300);
  await p.locator('#stats .stat-f', {hasText:'Brindes'}).click(); await p.waitForTimeout(500);
  const activeB=await p.locator('#stats .stat-f.ativo .sl').textContent();
  log('after clicking Brindes card -> active card:', activeB);
  await p.locator('#stats .stat-f', {hasText:'Todos'}).click(); await p.waitForTimeout(300);

  await p.screenshot({path:OUT+'/stat_cards.png'});
  console.log('\nERRORS:', errs.length?errs.join('\n'):'none');
  await b.close();
})().catch(e=>{console.error('FATAL',e);process.exit(1);});
