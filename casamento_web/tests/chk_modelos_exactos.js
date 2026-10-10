const fs = require('fs');
const path = require('path');
const { chromium } = require('C:/Users/abedn/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const BASE = process.env.TEST_BASE || 'http://127.0.0.1:8926';
let modelos = [
  [52,'porcelain-v2-green'],[53,'porcelain-v2-pink'],[54,'jasmine-white'],
  [55,'royal-v2-green'],[56,'spring-garden-blue'],[57,'double-happiness-green'],
  [58,'porcelain-brown'],[59,'royal-blue'],[60,'hoa-kho-orange'],
  [61,'mahal-gold'],[62,'lien-hoa-pink']
];
if(process.env.TEST_MODEL) modelos=modelos.filter(x=>x[1]===process.env.TEST_MODEL);
const out = path.join(__dirname, 'output', 'modelos-exactos');
fs.mkdirSync(out, {recursive:true});
let falhas = 0;
function ok(cond, msg){ console.log((cond?'✓':'✗')+' '+msg); if(!cond) falhas++; }

(async()=>{
  const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  const context = await browser.newContext({viewport:{width:390,height:844}});
  const page = await context.newPage();
  await page.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await page.fill('[name=utilizador]','admin');
  await page.fill('[name=senha]','noivos2026');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  if(page.url().includes('login.php')) console.log('Login local:',(await page.locator('body').innerText()).slice(0,400));

  for(const [id,slug] of modelos){
    await page.setViewportSize({width:390,height:844});
    const erros=[]; const respostas=[];
    const onConsole=m=>{if(m.type()==='error'&&!/favicon/i.test(m.text()))erros.push(m.text())};
    const onResponse=r=>{if(r.status()>=400)respostas.push(`${r.status()} ${r.url()}`)};
    page.on('console',onConsole); page.on('response',onResponse);
    await page.goto(`${BASE}/convite-digital.php?demo=1&modelo=${id}`,{waitUntil:'networkidle'});
    await page.waitForTimeout(700);
    const estado=await page.evaluate(slug=>({
      estilo:document.body.dataset.estilo,
      // O data-testid original varia entre famílias (por exemplo,
      // jasmine-white usa mai-lan-white-template). O corpo identifica o pacote
      // Kulemba e o marcador original confirma que a árvore comparada foi usada.
      exacto:document.body.dataset.estilo===slug&&!!document.querySelector('[data-testid$="-template"]'),
      externo:[...document.querySelectorAll('img,audio,source,link[rel=stylesheet],script[src]')]
        .map(e=>e.currentSrc||e.href||e.src||'').filter(u=>/^https?:/.test(u)&&!u.startsWith(location.origin)),
      overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth,
      overflowAlvo:[...document.querySelectorAll('body *')].map(el=>({el,r:el.getBoundingClientRect()})).filter(x=>x.r.right>innerWidth+1).concat([...document.querySelectorAll('body *')].map(el=>({el,r:el.getBoundingClientRect()})).filter(x=>x.r.left<-1)).map(x=>`${x.el.tagName}.${String(x.el.className).split(/\s+/).slice(0,3).join('.')} (${Math.round(x.r.left)}–${Math.round(x.r.right)})`)[0]||'',
      capa:!!document.querySelector('#cover:not([hidden])'),
      pt:/Abrir|Reserve a data|Convida/.test(document.body.innerText),
      mensagens:[...document.querySelectorAll('button,a')].some(e=>/SEND WISHES|ENVOYER|GỬI LỜI CHÚC/i.test(e.textContent))||!!document.querySelector('form textarea'),
      confirmacao:!!document.querySelector('[data-kulemba="confirmacao"], [data-rsvp-trigger]'),
      semanticaLista:[['grande-dia','.exact-semantic-ceremony'],['recepcao','.exact-semantic-reception'],['cronograma','.exact-semantic-program'],['acesso','[data-sec="acesso"]']].filter(([,sel])=>!document.querySelector(sel)).map(([nome])=>nome),
      ficticios:/weddingdemo|venmo|banco de exemplo|00000000/i.test(document.body.innerText),
      galeriaExemplo:(()=>{const cfg=(window.KulembaConvite&&window.KulembaConvite.config&&window.KulembaConvite.config.exacto)||{},fontes=(cfg.galeria||[]).map(x=>new URL(x,location.href).href),principais=[...document.querySelectorAll('[data-kulemba-galeria="1"]')].filter(img=>!img.closest('dialog')),miniaturas=[...document.querySelectorAll('dialog button[aria-label^="Ver a imagem"] img')],pontos=[...document.querySelectorAll('button[aria-label^="Ir para a fotografia"],button[aria-label^="Go to photo"]')],ok=fontes.length<=4&&fontes.length>0&&principais.length===fontes.length&&principais.every((img,i)=>img.currentSrc===fontes[i])&&miniaturas.length===fontes.length&&miniaturas.every((img,i)=>img.currentSrc===fontes[i])&&pontos.length===fontes.length;return{ok,contagens:[principais.length,miniaturas.length,pontos.length],fontes:fontes.length};})()
    }),slug);
    ok(estado.estilo===slug&&estado.exacto,`${slug} usa o documento exacto`);
    ok(estado.externo.length===0,`${slug} usa somente recursos locais`);
    ok(estado.overflow<=1,`${slug} não transborda a 390 px${estado.overflow>1&&estado.overflowAlvo?`: ${estado.overflowAlvo}`:''}`);
    ok(estado.capa,`${slug} conserva a capa fechada`);
    ok(estado.pt,`${slug} apresenta a abertura em português`);
    ok(!estado.mensagens,`${slug} não apresenta o botão de enviar mensagem`);
    ok(estado.confirmacao,`${slug} liga a confirmação ao modal comum`);
    ok(estado.semanticaLista.length===0&&estado.confirmacao,`${slug} apresenta cerimónia, copo d'água, programa, confirmação e acesso${estado.semanticaLista.length?` (faltam: ${estado.semanticaLista.join(', ')})`:''}`);
    ok(!estado.ficticios,`${slug} não expõe dados bancários ou contactos fictícios`);
    ok(estado.galeriaExemplo.fontes===0 || estado.galeriaExemplo.ok,`${slug} usa no máximo as quatro fotografias semânticas do administrador (${estado.galeriaExemplo.contagens.join('/')} elementos)`);
    if(estado.capa){
      const abrir=page.locator('#cover a,#cover button').filter({hasText:/Abrir/i}).first();
      if(await abrir.count()) await abrir.click(); else await page.locator('#cover').click({position:{x:10,y:10}});
      await page.waitForTimeout(1400);
      ok(await page.locator('#cover[hidden]').count()===1,`${slug} abre o convite`);
    }else{
      console.log('  página:',page.url(),(await page.locator('body').innerText()).slice(0,500));
      ok(false,`${slug} abre o convite`);
    }
    if(estado.confirmacao){
      await page.locator('[data-kulemba="confirmacao"], [data-rsvp-trigger]').first().evaluate(e=>e.click());
      await page.waitForTimeout(250);
      ok(await page.locator('.kulemba-modal[aria-hidden="false"]').count()===1,`${slug} abre a confirmação em modal`);
      await page.locator('.kulemba-modal__fechar').evaluate(e=>e.click());
      await page.waitForTimeout(380);
      ok(await page.locator('.kulemba-modal').getAttribute('aria-hidden')==='true'
        && !(await page.locator('.kulemba-modal').isVisible()),`${slug} fecha a confirmação`);
    }
    const presente=page.locator('[aria-label="Abrir presentes"]').first();
    if(await presente.count()){
      await presente.evaluate(e=>e.click()); await page.waitForTimeout(80);
      const presenteAberto=page.locator('dialog[open]').filter({hasNot:page.locator('[aria-label="Imagem anterior"]')}).first();
      ok(await presenteAberto.count()===1,`${slug} abre a caixa de presentes`);
      if(await presenteAberto.count()) await presenteAberto.evaluate(e=>e.close?e.close():e.removeAttribute('open'));
    } else ok(true,`${slug} omite presentes quando o administrador não os configurou`);
    const seguinte=page.locator('button[aria-label="Fotografia seguinte"]').first();
    if(await seguinte.count()){
      const primeira=page.locator('img[alt^="Wedding photo"]').first().locator('..');
      const antes=await primeira.getAttribute('style'); await seguinte.evaluate(e=>e.click());
      const depois=await primeira.getAttribute('style');
      ok(antes!==depois,`${slug} navega na galeria`);
    }
    ok(erros.length===0,`${slug} abre sem erros de consola${erros.length?': '+erros[0]:''}`);
    ok(respostas.length===0,`${slug} carrega sem respostas HTTP inválidas${respostas.length?': '+respostas[0]:''}`);
    await page.screenshot({path:path.join(out,slug+'-mobile.png'),fullPage:false});
    await page.setViewportSize({width:1440,height:1000});
    await page.evaluate(()=>scrollTo({top:0,left:0,behavior:'instant'})); await page.waitForTimeout(120);
    const overflowDesktop=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
    ok(overflowDesktop<=1,`${slug} não transborda a 1440 px`);
    await page.screenshot({path:path.join(out,slug+'-desktop.png'),fullPage:false});
    page.off('console',onConsole); page.off('response',onResponse);
  }
  await browser.close();
  if(falhas){console.error(`\n${falhas} falha(s).`);process.exit(1)}
  console.log(`\n${modelos.length===11?'Os onze modelos exactos passaram.':modelos[0][1]+' passou.'}`);
})().catch(e=>{console.error(e);process.exit(1)});
