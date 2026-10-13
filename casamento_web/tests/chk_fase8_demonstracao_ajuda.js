// Fase 8: a montra pública deve usar a peça real escolhida pelo administrador,
// os dados de exemplo comuns e uma confirmação completa que nunca escreve.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8926';
const EXE=process.env.CHROMIUM||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:EXE});
  try{
    const contexto=await browser.newContext({viewport:{width:390,height:844}});
    const pagina=await contexto.newPage(),erros=[],escritas=[];
    pagina.on('pageerror',e=>erros.push(e.message));
    pagina.on('console',m=>{if(m.type()==='error'&&!/favicon/i.test(m.text()))erros.push(m.text())});
    pagina.on('request',r=>{if(r.method()!=='GET')escritas.push(`${r.method()} ${r.url()}`)});
    const resposta=await pagina.goto(BASE+'/demonstracao-convite.php?modelo=54&atual=1&editor=1',{waitUntil:'networkidle'});
    assert.equal(resposta.status(),200,'a prova pública abre sem sessão');
    assert(!/Apenas administração/i.test(await pagina.locator('body').innerText()));
    assert(await pagina.locator('[data-testid$="-template"],#hero').count(),'a prova usa um documento de convite real');
    assert.equal(await pagina.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'a prova pública cabe no telemóvel');

    const capa=pagina.locator('#cover,[data-kulemba="capa"]').first();
    if(await capa.count())await capa.evaluate(e=>e.click());
    await pagina.waitForTimeout(900);
    const confirmar=pagina.locator('[data-kulemba="confirmacao"]').first();
    assert(await confirmar.count(),'a peça pública conserva a confirmação do modelo');
    await confirmar.evaluate(e=>e.click());
    const frame=pagina.frameLocator('.kulemba-modal iframe');
    await frame.locator('#form-rsvp').waitFor();
    assert(await frame.locator('#membros .membro').count()>=1,'o formulário mostra os convidados de exemplo definidos pelo admin');
    await frame.locator('#op-sim').click();
    await frame.locator('#btn-enviar').click();
    await frame.locator('#concluido.on').waitFor();
    assert.equal(escritas.length,0,'a experiência pública não escreve dados');
    assert.deepEqual(erros,[],'a prova pública termina sem erros no navegador');
    console.log('PASS: a Demonstração usa a peça real, a confirmação real e dados fictícios sem escritas');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
