// Contratos observáveis: interacções, estado partilhado, reposição e isolamento.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const base=process.env.BASE_URL||'http://127.0.0.1:8921';
(async()=>{const b=await chromium.launch({executablePath:process.env.CHROMIUM});try{
for(const width of [1440,390,320]){
const p=await b.newPage({viewport:{width,height:844},reducedMotion:'reduce'}),errors=[],writes=[],bad=[];
p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text());});p.on('request',r=>{if(r.method()!=='GET'||r.url().includes('api.php'))writes.push(r.url())});p.on('response',r=>{if(r.status()>=400)bad.push(r.url())});
await p.goto(base+'/atendimento.php',{waitUntil:'networkidle'});
assert.equal(await p.locator('[data-demo]').count(),7);assert.equal(await p.locator('.demo-watermark').count(),0);
const tab=async m=>{await p.locator('[data-demo='+m+']').click();await p.waitForTimeout(60);assert(await p.locator('[data-painel='+m+']').isVisible());assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'horizontal overflow '+m+' '+width);};
await p.locator('[data-confirmar="4"]').click();assert.equal(await p.locator('[data-confirmados]').textContent(),'5');
await p.fill('#demo-busca','Beatriz');assert.equal(await p.locator('#demo-convidados .convite-row').count(),1);assert((await p.locator('#demo-convidados').textContent()).includes('Confirmado'));
await tab('mesas');await p.selectOption('#demo-pessoa','4');await p.locator('[data-mesa="0"]').click();assert((await p.locator('[data-mesa="0"]').getAttribute('aria-label')).includes('3 de 8'));
await tab('porta');await p.fill('#demo-porta-busca','Beatriz');assert((await p.locator('#demo-porta').textContent()).includes('Acácia'));await p.locator('[data-entrada="4"]').click();assert.equal(await p.locator('[data-presentes]').textContent(),'1');
await tab('bar');await p.locator('[data-bebida="1"]').click();await p.waitForTimeout(1200);assert.equal(await p.locator('[data-bebida="1"]').getAttribute('aria-pressed'),'true');await p.click('#demo-pedir');assert((await p.locator('#demo-pedido').textContent()).includes('Recebido na copa'));await p.click('[data-avancar-pedido]');await p.click('[data-avancar-pedido]');assert((await p.locator('#demo-pedido').textContent()).includes('Entregue à mesa'));
await tab('orcamento');await p.locator('[data-pagar="1"]').click();assert((await p.locator('[data-pago]').textContent()).replace(/\s/g,'').includes('750000'));assert((await p.locator('[data-falta]').textContent()).replace(/\s/g,'').includes('100000'));
await tab('impresso');await p.selectOption('#demo-paleta','salvia');assert.equal(await p.locator('.demo-escala .cartao').evaluate(e=>e.style.getPropertyValue('--ct-accent')),'#7d8a6a');const card=await p.locator('.demo-escala .cartao').boundingBox();assert(card.width<=width&&card.height>200);
await tab('digital');const f=p.frameLocator('iframe');await f.locator('#cover').click();await f.locator('#demo-sim').click();assert((await f.locator('#demo-resposta').textContent()).includes('Guardámos o vosso sim'));assert(!(await f.locator('body').innerText()).includes('{{'));assert((await f.locator('body').innerText()).includes('Lia'));assert.equal(await f.locator('a').evaluateAll(xs=>xs.every(a=>a.getAttribute('href')==='#demo-rsvp')),true);
await p.click('#demo-repor');await tab('convidados');assert.equal(await p.locator('[data-confirmados]').textContent(),'4');assert.equal(await p.locator('#demo-busca').inputValue(),'');await tab('porta');assert.equal(await p.locator('[data-presentes]').textContent(),'0');await tab('orcamento');assert((await p.locator('[data-pago]').textContent()).replace(/\s/g,'').includes('500000'));await tab('bar');assert(await p.locator('#demo-pedir').isDisabled());
await p.reload({waitUntil:'networkidle'});assert.equal(await p.locator('[data-confirmados]').textContent(),'4');await p.locator('[data-demo=convidados]').focus();await p.keyboard.press('ArrowRight');assert.equal(await p.locator('[data-demo=mesas]').getAttribute('aria-selected'),'true');
assert.deepEqual(writes,[],'public demo must not call management APIs');assert.deepEqual(bad,[],'all assets load');assert.deepEqual(errors,[],'no browser errors');console.log('PASS '+width+'px: seven modules, shared state, reset, keyboard navigation, no overflow, API writes or JS errors');await p.close();
}
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});
