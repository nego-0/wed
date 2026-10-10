// Prova visual do runtime comum em desktop e telemóvel. Não cria dados.
const { chromium } = require('playwright-core');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');

(async()=>{
  const browser=await chromium.launch({executablePath:EXE,headless:true});
  let falhas=0;
  const ok=(v,m)=>{console.log((v?'PASS':'FAIL')+': '+m);if(!v)falhas++;};
  for(const viewport of [{width:1280,height:800},{width:390,height:844}]){
    const page=await browser.newPage({viewport});
    const erros=[];page.on('pageerror',e=>erros.push(e.message));page.on('console',m=>{if(m.type()==='error')erros.push(m.text());});
    await page.goto(BASE+'/demonstracao-convite.php',{waitUntil:'networkidle'});
    await page.click('#cover');
    await page.locator('[data-kulemba="confirmacao"]').scrollIntoViewIfNeeded();
    await page.click('[data-kulemba="confirmacao"]');
    await page.waitForTimeout(350);
    const estado=await page.evaluate(()=>{
      const modal=document.querySelector('.kulemba-modal');
      const painel=modal.querySelector('.kulemba-modal__painel').getBoundingClientRect();
      return {runtime:!!window.KulembaConvite,aberto:modal.getAttribute('aria-hidden')==='false',
        alvo:!document.getElementById('demo-rsvp').hidden,hash:location.hash,
        dentro:painel.top>=0&&painel.bottom<=innerHeight+.5&&painel.left>=0&&painel.right<=innerWidth+.5,
        overflow:document.documentElement.scrollWidth-innerWidth};
    });
    ok(estado.runtime,'o mesmo runtime arrancou a '+viewport.width+' px');
    ok(estado.aberto&&estado.alvo&&estado.hash==='#confirmar','o modal comum abriu pelo ponto de montagem');
    ok(estado.dentro&&estado.overflow===0,'o modal cabe totalmente no viewport');
    ok(erros.length===0,'sem erros no navegador: '+erros.join(' | '));
    await page.click('.kulemba-modal__fechar');
    const fechado=await page.evaluate(()=>({oculto:document.querySelector('.kulemba-modal').getAttribute('aria-hidden')==='true',hash:location.hash,foco:document.activeElement&&document.activeElement.dataset.kulemba}));
    ok(fechado.oculto&&!fechado.hash&&fechado.foco==='confirmacao','fechar devolve o foco ao botão do convite');
    await page.goto(BASE+'/demonstracao-convite.php#confirmar',{waitUntil:'networkidle'});
    await page.waitForTimeout(250);
    ok(await page.getAttribute('.kulemba-modal','aria-hidden')==='false','#confirmar abre directamente a confirmação');
    await page.close();
  }
  await browser.close();process.exit(falhas?1:0);
})().catch(e=>{console.error(e);process.exit(1);});
