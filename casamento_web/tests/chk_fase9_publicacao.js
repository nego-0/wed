// Matriz final dos onze modelos digitais: comportamento real, acessibilidade,
// responsividade, movimento reduzido e custo de execução.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8926';
const EXE=process.env.CHROMIUM||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
let CHAVES=['porcelain-v2-green','porcelain-v2-pink','jasmine-white','royal-v2-green','spring-garden-blue','double-happiness-green','porcelain-brown','royal-blue','hoa-kho-orange','mahal-gold','lien-hoa-pink'];
if(process.env.TEST_MODEL)CHAVES=CHAVES.filter(x=>x===process.env.TEST_MODEL);

async function abrirConta(p){
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('[name=utilizador]',process.env.TEST_USER||'admin');
  await p.fill('[name=senha]',process.env.TEST_PASSWORD||'noivos2026');
  await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
  assert(!p.url().includes('login.php'),'a conta de prova inicia sessão');
}
async function modelosInstalados(p){
  const r=await p.evaluate(()=>fetch('api.php?action=modelo_lista&ambito=digital').then(x=>x.json()));
  const mapa=new Map((r.modelos||[]).map(m=>[m.renderer_key,+m.id]));
  for(const chave of CHAVES)assert(mapa.get(chave)>0,'modelo instalado: '+chave);
  return CHAVES.map(chave=>[mapa.get(chave),chave]);
}
async function abrirCapaPorTeclado(p){
  const abrir=p.locator('#cover a,#cover button,[data-kulemba="capa"] a,[data-kulemba="capa"] button').filter({hasText:/Abrir/i}).first();
  await abrir.waitFor();assert(await abrir.evaluate(e=>e.tabIndex>=0),'o comando Abrir recebe foco');
  await abrir.focus();await p.keyboard.press('Enter');
  await p.locator('#cover,[data-kulemba="capa"]').first().waitFor({state:'hidden',timeout:2500});
}

(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:EXE,args:['--no-sandbox']});
  try{
    const ctx=await browser.newContext({viewport:{width:390,height:844}}),p=await ctx.newPage();
    await abrirConta(p);const modelos=await modelosInstalados(p);
    for(const [id,chave] of modelos){
      const erros=[],invalidas=[],escritas=[];
      const onErro=e=>erros.push(e.message),onConsola=m=>{if(m.type()==='error'&&!/favicon/i.test(m.text()))erros.push(m.text())};
      const onResposta=r=>{if(r.status()>=400&&!/google\.com\/maps/i.test(r.url()))invalidas.push(`${r.status()} ${r.url()}`)};
      const onPedido=r=>{if(r.method()!=='GET'&&r.url().includes('/api.php'))escritas.push(`${r.method()} ${r.url()}`)};
      p.on('pageerror',onErro);p.on('console',onConsola);p.on('response',onResposta);p.on('request',onPedido);
      const inicio=Date.now();await p.goto(`${BASE}/convite-digital.php?demo=1&modelo=${id}`,{waitUntil:'domcontentloaded'});await p.waitForFunction(()=>!!window.KulembaConvite);const carga=Date.now()-inicio;
      assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,chave+': sem transbordo mobile');
      assert.equal(await p.locator('.exact-semantic-ceremony,.exact-semantic-reception,.exact-semantic-program,[data-sec="acesso"]').count()>=4,true,chave+': secções funcionais completas');
      assert(carga<6000,chave+`: interface pronta em ${carga} ms no servidor local`);
      assert(await p.locator('body *').count()<6000,chave+': árvore visual controlada');
      await abrirCapaPorTeclado(p);

      const mapa=p.locator('.exact-map-shell iframe').first(),mapLink=p.locator('[data-kulemba="mapa"]').first();
      assert(await mapa.count()&&/^Mapa de /i.test(await mapa.getAttribute('title')||''),chave+': mapa real tem título acessível');
      assert(await mapLink.count()&&/^https:\/\//.test(await mapLink.getAttribute('href')||''),chave+': ligação segura para o mapa');
      const qr=p.locator('canvas[data-kulemba-qr],canvas[aria-label="Código QR de acesso ao evento"]').first();
      assert(await qr.count(),chave+': passe de acesso inclui QR');
      assert(await qr.evaluate(c=>c.width>0&&c.height>0&&c.toDataURL().length>100),chave+': QR foi desenhado');

      const foto=p.locator('[data-kulemba-galeria="1"]').first(),cartao=foto.locator('..');
      assert.equal(await foto.count(),1,chave+': galeria usa fotografias do admin');
      assert.equal(await cartao.getAttribute('tabindex'),'0',chave+': fotografia pode ser aberta pelo teclado');
      await cartao.focus();await p.keyboard.press('Enter');
      const lightbox=p.locator('dialog[open]').filter({has:p.locator('[aria-label="Imagem seguinte"]')}).first();
      await lightbox.waitFor();assert(await lightbox.locator('[aria-label="Fechar galeria"]').count(),chave+': galeria tem fecho identificado');
      assert(await lightbox.getAttribute('aria-label'),chave+': galeria anuncia o seu propósito');
      const grande=lightbox.locator('[data-exact-lightbox-image],img.max-h-full').first(),antes=await grande.getAttribute('src');
      await lightbox.locator('[aria-label="Imagem seguinte"]').click();
      assert.notEqual(await grande.getAttribute('src'),antes,chave+': galeria navega entre fotografias');
      await lightbox.locator('[aria-label="Fechar galeria"]').click();

      const audio=p.locator('#bgAudio'),audioBt=p.locator('#audioBtn');
      if(await audio.count()){
        assert.equal(await audioBt.count(),1,chave+': música configurada tem botão');
        assert(/^Reproduzir música|Pausar música$/.test(await audioBt.getAttribute('aria-label')||''),chave+': estado da música é anunciado');
        await audio.evaluate(a=>a.pause());assert.equal(await audioBt.getAttribute('aria-label'),'Reproduzir música');
        await audioBt.click();await p.waitForTimeout(80);assert.equal(await audioBt.getAttribute('aria-label'),'Pausar música');
        await audioBt.click();await p.waitForTimeout(40);assert.equal(await audioBt.getAttribute('aria-label'),'Reproduzir música');
      }else assert.equal(await audioBt.count(),0,chave+': sem música não fica um botão vazio');

      const rolou=await p.evaluate(async()=>{
        const eventos=[];document.addEventListener('kulemba:rolagem-retomar',()=>eventos.push('retomar'),{once:true});
        document.addEventListener('kulemba:rolagem-pausar',()=>eventos.push('pausar'),{once:true});
        KulembaConvite.config.rolagem.activa=true;KulembaConvite.pausarRolagem('preparar-teste');KulembaConvite.retomarRolagem();
        await new Promise(r=>setTimeout(r,300));
        const raiz=document.querySelector('[data-showcase-root]'),emCurso=scrollY>0||!!(raiz&&raiz.style.transform);
        KulembaConvite.pausarRolagem('teste');await new Promise(r=>setTimeout(r,30));
        return {emCurso,eventos};
      });
      assert(rolou.emCurso&&rolou.eventos.includes('retomar')&&rolou.eventos.includes('pausar'),chave+': rolagem automática inicia e pausa suavemente');

      const confirmar=p.locator('[data-kulemba="confirmacao"]:visible').first();await confirmar.waitFor();await confirmar.focus();await confirmar.press('Enter');
      const modal=p.locator('.kulemba-modal[aria-hidden="false"]');await modal.waitFor();
      assert.equal(await modal.locator('[role="dialog"][aria-modal="true"]').count(),1,chave+': confirmação é modal acessível');
      const frame=p.frameLocator('.kulemba-modal iframe');await frame.locator('#form-rsvp').waitFor();
      const membros=frame.locator('#membros .membro input');assert(await membros.count()>=2,chave+': confirmação usa lista nominal');
      await frame.locator('#op-sim').click();await membros.last().uncheck();
      assert.equal(await membros.evaluateAll(xs=>xs.filter(x=>x.checked).length),(await membros.count())-1,chave+': presença parcial é interactiva');
      await frame.locator('#btn-enviar').click();await frame.locator('#concluido.on').waitFor();
      await modal.locator('.kulemba-modal__fechar').click();
      assert.equal(escritas.length,0,chave+': prova e confirmação não escrevem na API');
      assert.deepEqual(invalidas,[],chave+': sem respostas HTTP inválidas');assert.deepEqual(erros,[],chave+': sem erros JavaScript');
      p.off('pageerror',onErro);p.off('console',onConsola);p.off('response',onResposta);p.off('request',onPedido);
      console.log(`PASS mobile ${chave} · ${carga} ms`);
    }
    await ctx.close();

    // Segunda passagem: secretária, preferência escura e movimento reduzido.
    const ctxReduzido=await browser.newContext({viewport:{width:1440,height:1000},colorScheme:'dark',reducedMotion:'reduce'}),r=await ctxReduzido.newPage();
    await abrirConta(r);const modelosReduzidos=await modelosInstalados(r);
    for(const [id,chave] of modelosReduzidos){
      await r.goto(`${BASE}/convite-digital.php?demo=1&modelo=${id}`,{waitUntil:'domcontentloaded'});await r.waitForFunction(()=>!!window.KulembaConvite);
      assert.equal(await r.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,chave+': sem transbordo desktop');
      const abrir=r.locator('#cover a,#cover button').filter({hasText:/Abrir/i}).first();await abrir.click();
      await r.locator('#cover').waitFor({state:'hidden',timeout:700});
      const fotos=r.locator('[data-kulemba-galeria="1"]');
      const antes=await fotos.evaluateAll(xs=>xs.map(x=>x.parentElement.getAttribute('style')));await r.waitForTimeout(3950);
      const depois=await fotos.evaluateAll(xs=>xs.map(x=>x.parentElement.getAttribute('style')));
      assert.deepEqual(depois,antes,chave+': movimento reduzido desliga a rotação automática');
      console.log('PASS desktop reduzido '+chave);
    }
    await ctxReduzido.close();
    console.log('PASS: matriz final dos onze modelos em mobile e desktop');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
