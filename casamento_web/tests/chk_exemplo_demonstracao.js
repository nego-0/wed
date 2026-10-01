const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8920';
const USER=process.env.TEST_USER||'admin',PASSWORD=process.env.TEST_PASSWORD;
const EXE=process.env.CHROMIUM||(process.platform==='win32'?'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe':'/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
if(!PASSWORD)throw new Error('Defina TEST_PASSWORD.');
(async()=>{const browser=await chromium.launch({executablePath:EXE});const p=await browser.newPage({viewport:{width:1280,height:850}});try{
  await p.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});
  assert.match(await p.locator('.mk-retrato').innerText(),/Marta\s*&\s*Pedro/,'a montra começa com o casal de exemplo de fábrica');
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});await p.fill('[name=utilizador]',USER);await p.fill('[name=senha]',PASSWORD);await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
  const api=async(acao,corpo)=>p.evaluate(async({acao,corpo})=>{const r=await fetch('api.php?action='+acao,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(corpo)});const t=await r.text();try{return JSON.parse(t)}catch(_){throw new Error(`HTTP ${r.status}: ${t.slice(0,600)}`)}},{acao,corpo});
  let r=await api('modelo_exemplo_guardar',{'casal.noiva':'Helena','casal.noivo':'Tomás'});assert.equal(r.success,true);
  for(const rota of ['/convite-digital.php?demo=1','/convite.php?demo=1']){
    await p.goto(BASE+rota,{waitUntil:'networkidle'});const texto=await p.locator('body').innerText();
    assert.match(texto,/Helena/);assert.match(texto,/Tomás/);assert.doesNotMatch(texto,/Isabel|Abednego|Marta|Pedro/);
  }
  await p.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});assert.match(await p.locator('.mk-retrato').innerText(),/Helena\s*&\s*Tomás/,'a montra acompanha a escolha do administrador');
  await p.goto(BASE+'/plataforma.php',{waitUntil:'networkidle'});r=await api('modelo_exemplo_guardar',{'casal.noiva':'Marta','casal.noivo':'Pedro'});assert.equal(r.success,true);
  console.log('PASS: montra, convite e confirmação usam os dados de exemplo do admin; fábrica Marta & Pedro');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
