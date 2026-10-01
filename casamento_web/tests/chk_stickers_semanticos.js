// Cada descrição da Ajuda recebe uma ilustração animada para a sua acção.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8920';
const EXE=process.env.CHROMIUM||(process.platform==='win32'?'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe':'/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const AJUDA=[
  'familia','configurar','enviar','pesquisar','estados','resumo',
  'adicionar','configurar','arrastar','sentar','selecionar','lotacao',
  'modelos','editar','versoes','publicar','rever','descarregar',
  'convite','historia','presenca','telemovel','rolar','confirmar',
  'qr','presenca','contador','corrigir','desmarcar','ausentes',
  'categorias','stock','activar','pedido','preparar','entregar',
  'despesa','configurar','parcelas','valores','filtrar','prazos',
];
const DEMONSTRACAO=[
  'familia','proteger','contador','pesquisar','estados','resumo',
  'planta','configurar','arrastar','sentar','lotacao','mover',
  'modelos','editar','versoes','rever','paleta','descarregar',
  'convite','historia','confirmar','telemovel','publicar','whatsapp',
  'qr','presenca','contador','corrigir','ausentes','proteger',
  'categorias','stock','activar','pedido','preparar','entregar',
  'despesa','parcelas','factura','valores','filtrar','prazos',
];
(async()=>{
  const pasta=path.join(__dirname,'..','assets','ajuda','stickers');
  const capturas=path.join(__dirname,'..','assets','ajuda','capturas');
  const alvosDemo=JSON.parse(fs.readFileSync(path.join(capturas,'alvos-demonstracao.json'),'utf8'));
  assert.deepEqual(alvosDemo.map(x=>[x.modulo,x.topico,x.passo,x.dispositivo,x.tipo]),[
    ['digital',2,3,'desktop','whatsapp'],['digital',2,3,'mobile','whatsapp']
  ],'a partilha tem alvos próprios no botão WhatsApp em desktop e mobile');
  for(const dispositivo of ['desktop','mobile'])assert.equal(fs.existsSync(path.join(capturas,'demonstracao',`digital-2-3-${dispositivo}.jpg`)),true,'falta a captura de partilha em '+dispositivo);
  for(const foto of ['coca-cola.jpg','cuca.jpg','vinho-tinto.jpg'])assert.equal(fs.existsSync(path.join(__dirname,'..','assets','ajuda','exemplos',foto)),true,'falta a fotografia de exemplo '+foto);
  assert.match(fs.readFileSync(path.join(__dirname,'gerar-capturas-ajuda.js'),'utf8'),/nome:'Coca-Cola'[\s\S]*?servir:'garrafa'/,'a Coca-Cola de exemplo só pode ser pedida à garrafa');
  for(const tipo of new Set([...AJUDA,...DEMONSTRACAO])){
    const svg=fs.readFileSync(path.join(pasta,tipo+'.svg'),'utf8');
    assert.match(svg,/@keyframes|<svg[^>]*>/,tipo+' é um SVG válido');
    if(!['arrastar','rolar','confirmar'].includes(tipo)){
      assert.match(svg,/@keyframes/,tipo+' tem animação própria');
      assert.match(svg,/prefers-reduced-motion:reduce/,tipo+' respeita movimento reduzido');
    }
  }
  const php=process.env.PHP_BIN||(process.platform==='win32'?'C:\\wamp64\\bin\\php\\php8.3.28\\php.exe':'php');
  const parcial=path.join(__dirname,'..','parcial-ajuda.php').replaceAll('\\','/').replaceAll("'","\\'");
  const codigo=`require '${parcial}';$mods=['convidados','mesas','impresso','digital','porta','bar','orcamento'];$r=[];foreach($mods as $m)foreach([0,1] as $t)foreach([0,1,2] as $p)$r[]=tipoStickerAjuda($m,$t,$p,'ajuda','','tocar');echo json_encode($r);`;
  const phpRun=spawnSync(php,['-r',codigo],{encoding:'utf8'});
  assert.equal(phpRun.status,0,phpRun.stderr||'o mapa PHP deve ser legível');
  assert.deepEqual(JSON.parse(phpRun.stdout),AJUDA,'a Ajuda conserva os gestos operacionais de cada passo');
  const browser=await chromium.launch({executablePath:EXE});
  try{
    const page=await browser.newPage({viewport:{width:1440,height:950}}),erros=[];page.on('pageerror',e=>erros.push(e.message));
    await page.goto(BASE+'/atendimento.php#demonstracao',{waitUntil:'networkidle'});
    const tipos=await page.locator('#demonstracao .aj-sticker-marca').evaluateAll(xs=>xs.map(x=>x.dataset.stickerTipo));
    assert.deepEqual(tipos,DEMONSTRACAO,'os 42 passos usam o sticker correspondente às descrições comerciais actuais');
    assert.equal(await page.locator('#demonstracao .aj-sticker').evaluateAll(async xs=>(await Promise.all(xs.map(x=>fetch(x.src)))).every(r=>r.ok&&(r.headers.get('content-type')||'').includes('image/svg+xml'))),true,'todos os stickers carregam como SVG');
    const primeiro=page.locator('#demonstracao .aj-sticker-marca.ativo .aj-sticker').first();
    assert.notEqual(await primeiro.evaluate(e=>getComputedStyle(e).animationName),'none','a animação externa está activa');
    const marcas=await page.locator('#demonstracao .aj-sticker-marca').evaluateAll(xs=>xs.map(x=>({p:parseFloat(getComputedStyle(x,'::before').width),icone:getComputedStyle(x.querySelector('.aj-sticker')).display})));
    assert.equal(marcas.every(x=>x.p<=12.1&&x.icone==='none'),true,'a captura conserva apenas pontos compactos, sem pictogramas sobre o conteúdo');
    assert.equal(await page.locator('#demonstracao [data-aj-sticker-legenda][src]').count(),14,'cada demonstração leva o pictograma semântico para a legenda');
    await page.setViewportSize({width:390,height:844});await page.reload({waitUntil:'networkidle'});
    assert.deepEqual(await page.locator('#demonstracao .aj-sticker-marca').evaluateAll(xs=>xs.map(x=>x.dataset.stickerTipo)),DEMONSTRACAO,'o telemóvel conserva a relação entre texto e sticker');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'os novos stickers não criam transbordo');
    await page.goto(BASE+'/login.php',{waitUntil:'networkidle'});await page.fill('input[name=utilizador]','admin');await page.fill('input[name=senha]','noivos2026');await page.click('button[type=submit]');await page.waitForLoadState('networkidle');
    await page.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}));
    await page.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'});
    assert.deepEqual(await page.locator('.aj-sticker-marca').evaluateAll(xs=>xs.map(x=>x.dataset.stickerTipo)),AJUDA,'os 42 passos da Ajuda usam os pictogramas contextuais aprovados');
    const marcasAjuda=await page.locator('.aj-sticker-marca').evaluateAll(xs=>xs.map(x=>({p:parseFloat(getComputedStyle(x,'::before').width),icone:getComputedStyle(x.querySelector('.aj-sticker')).display})));
    assert.equal(marcasAjuda.every(x=>x.p<=12.1&&x.icone==='none'),true,'a Ajuda usa os mesmos pontos compactos da Demonstração');
    assert.equal(await page.locator('[data-aj-sticker-legenda][src]').count(),14,'a Ajuda coloca os pictogramas contextuais nas legendas');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'os stickers da Ajuda não criam transbordo no telemóvel');
    assert.deepEqual(erros,[],'sem erros JavaScript');
    console.log('PASS: 84 passos com pictogramas contextuais e marcadores discretos, acompanhados por exemplos fotográficos do Bar');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
