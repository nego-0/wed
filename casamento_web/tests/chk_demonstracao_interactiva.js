// Contratos observáveis da montra: os mesmos guias visuais da Ajuda, sem escrita.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const base=process.env.BASE_URL||'http://127.0.0.1:8921';
(async()=>{const b=await chromium.launch({executablePath:process.env.CHROMIUM});try{
for(const width of [1440,390,320]){
  const p=await b.newPage({viewport:{width,height:844},reducedMotion:'reduce'}),errors=[],writes=[],bad=[];
  p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
  p.on('request',r=>{if(r.method()!=='GET'||r.url().includes('api.php'))writes.push(r.url())});
  p.on('response',r=>{if(r.status()>=400)bad.push(r.url())});
  await p.goto(base+'/atendimento.php#demonstracao',{waitUntil:'networkidle'});
  assert.equal(await p.locator('#demonstracao .aj-submenu button[data-aj-modulo]:not([data-aj-modulo="todos"])').count(),7);
  assert.equal(await p.locator('#demonstracao .aj-card').count(),14);
  assert.equal(await p.locator('.demo-watermark').count(),0);
  assert(await p.locator('#demonstracao').getByText('Tudo o que o vosso casamento ganha').count());
  await p.locator('#demonstracao [data-aj-modulo="digital"]').click();
  assert.equal(await p.locator('#demonstracao .aj-card:not([hidden])').count(),2);
  const digital=p.locator('#demonstracao .aj-card[data-modulo="digital"]').first();
  if(!(await digital.locator('details').first().getAttribute('open')))await digital.locator('summary').first().click();
  const imagens=digital.locator('.aj-cena img');
  assert.equal(await imagens.count(),3);
  const origens=await imagens.evaluateAll(xs=>xs.map(x=>x.currentSrc||x.src));
  assert(origens.some(x=>x.includes('digital-1-1-'))&&origens.some(x=>x.includes('digital-1-3-')),'usa imagens do convite e da confirmação');
  await digital.locator('.aj-passo').nth(2).locator('summary').click();
  assert(await digital.locator('.aj-cena[data-cena-passo="2"]').evaluate(e=>e.classList.contains('ativo')),'o passo muda a imagem mostrada');
  assert(await digital.locator('.aj-sticker-marca[data-sticker-passo="2"]').evaluate(e=>e.classList.contains('ativo')),'o passo muda o sticker');
  assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'horizontal overflow '+width);
  assert.deepEqual(writes,[],'a demonstração pública não escreve em APIs');
  assert.deepEqual(bad,[],'todos os recursos carregam');assert.deepEqual(errors,[],'sem erros no browser');
  console.log('PASS '+width+'px: sete módulos, guias interactivos, convite e confirmação, sem escritas ou transbordo');await p.close();
}
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});
