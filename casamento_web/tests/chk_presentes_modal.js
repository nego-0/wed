// Os modelos Kulemba também apresentam presentes numa janela, sem gravação.
const {chromium}=require('playwright-core'),assert=require('node:assert/strict');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8926';
(async()=>{const b=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{const c=await b.newContext(),p=await c.newPage();await p.goto(BASE+'/login.php');
await p.fill('[name=utilizador]',process.env.TEST_USER||'admin');await p.fill('[name=senha]',process.env.TEST_PASSWORD||'noivos2026');await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
const modelos=await p.evaluate(()=>fetch('api.php?action=modelo_lista&ambito=digital').then(r=>r.json()));
const m=modelos.modelos.find(x=>x.renderer_key==='kulemba-contemporaneo');assert(m);
for(const width of [1440,390,320])for(const modo of ['qr','metodos','texto']){
await p.setViewportSize({width,height:844});const url=BASE+'/convite-digital.php?demo=1&editor=1&modelo='+m.id;
const bloco={id:'bl1',tipo:'presentes',titulo:'Presentes para Clara e Miguel',texto:'Uma oferta com carinho.',modo,qr:'Referência personalizada do casal',itens:[{i:'banco',t:'Banco do Casal',x:'IBAN: AO06 1234 5678 9012 3456 7890 1'}]};
const response=await c.request.post(url,{form:{rascunho:JSON.stringify({'layout.blocos':JSON.stringify([bloco]),'geral.rolagem_auto':'0'})}});assert.equal(response.status(),200);await p.route(url,r=>r.fulfill({response}));await p.goto(url);await p.unroute(url);await p.locator('#cover').click();await p.locator('#cover').waitFor({state:'hidden'});
const btn=p.locator('.gift-open');await btn.scrollIntoViewIfNeeded();await btn.click();const dialog=p.locator('dialog.kulemba-presentes[open]');await dialog.waitFor();
assert((await dialog.textContent()).includes('Uma oferta com carinho.'));if(modo==='metodos')assert((await dialog.textContent()).includes('Banco do Casal'));
if(modo==='qr')assert(await dialog.locator('[data-presente-qr]').evaluate(e=>e.toDataURL().length>100));
const box=await dialog.boundingBox();assert(box.x>=0&&box.x+box.width<=width+1&&box.y>=0&&box.y+box.height<=845);
await dialog.locator('[data-fechar-presentes]').click();assert.equal(await dialog.count(),0);assert.equal(await btn.evaluate(e=>document.activeElement===e),true);
console.log('PASS presentes '+modo+' · '+width+' px');}
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});
