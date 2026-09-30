// A demonstração pública usa exactamente a mesma central visual da Ajuda.
const { chromium } = require('playwright-core');
const fs=require('node:fs'),path=require('node:path');
const EXE=process.env.CHROMIUM; const BASE=process.env.BASE_URL||'http://127.0.0.1:8921';
const USER=process.env.TEST_USER||'admin', PASSWORD=process.env.TEST_PASSWORD||'noivos2026';
const ALVOS=JSON.parse(fs.readFileSync(path.join(__dirname,'..','assets','ajuda','capturas','alvos-cenas.json'),'utf8'));
const ALVOS_DEMO=JSON.parse(fs.readFileSync(path.join(__dirname,'..','assets','ajuda','capturas','alvos-demonstracao.json'),'utf8'));
const demoPorChave=new Map(ALVOS_DEMO.map(x=>[`${x.modulo}-${x.topico}-${x.passo}-${x.dispositivo}`,x]));
const PASSOS_COM_ROLAGEM=new Set(ALVOS.filter(x=>{
 const chave=`${x.modulo}-${x.topico}-${x.passo}-${x.dispositivo}`;
 return (demoPorChave.get(chave)||x).rolar;
}).map(x=>`${x.modulo}-${x.topico}-${x.passo}`)).size;

(async()=>{
 const b=await chromium.launch({executablePath:EXE,headless:true});let falhas=0;
 const ok=(v,m)=>{console.log((v?'PASS':'FAIL')+': '+m);if(!v)falhas++;};
 const pub=await b.newPage({viewport:{width:1440,height:950}}),erros=[];
 pub.on('pageerror',e=>erros.push(e.message));
 await pub.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});
 const demo=pub.locator('#demonstracao');
 ok(await demo.locator('.aj-card').count()===14,'a demonstração apresenta os mesmos catorze tópicos da Ajuda');
 ok(await demo.locator('.aj-passo').count()===42,'os mesmos 42 passos aparecem na demonstração');
 ok(await demo.locator('[data-demo],[data-painel],.demo-app-topo,.demo-conteudo').count()===0,'a estrutura antiga da demonstração foi eliminada');
 ok(await demo.locator('.aj-demonstracao picture').evaluateAll(async xs=>{const urls=xs.flatMap(x=>[x.querySelector('img')?.src,x.querySelector('source')?.srcset]).filter(Boolean);return urls.length===84&&(await Promise.all(urls.map(x=>fetch(x)))).every(r=>r.ok&&(r.headers.get('content-type')||'').includes('image/jpeg'));}),'as 84 capturas desktop e mobile carregam na demonstração');
 ok(await demo.locator('.aj-sticker').evaluateAll(async xs=>{const urls=xs.map(x=>x.src);return urls.length===42&&(await Promise.all(urls.map(x=>fetch(x)))).every(r=>r.ok&&(r.headers.get('content-type')||'').includes('image/svg+xml'));}),'os 42 stickers vectoriais carregam na demonstração');
 ok(await demo.locator('.aj-scroll-marca').count()===PASSOS_COM_ROLAGEM,'a demonstração preserva os percursos de rolagem da Ajuda');
 await demo.locator('#aj-busca').fill('convidado mesa');
 ok(await demo.locator('.aj-card:visible').count()>=1,'a pesquisa funciona dentro da demonstração');
 await demo.locator('#aj-busca').fill(''); await demo.locator('[data-aj-modulo="bar"]').click();
 ok(await demo.locator('.aj-card:visible').count()===2,'o filtro por módulo funciona dentro da demonstração');
 await pub.waitForTimeout(100);
 let duracao=await demo.locator('.aj-card[data-modulo="bar"]:visible').first().evaluate(e=>parseFloat(getComputedStyle(e).animationDuration)*1000);
 ok(duracao>=1000,'a mudança de categoria mantém o fading visível por pelo menos um segundo');
 await pub.waitForTimeout(1150);
 let topo=await demo.locator('.aj-card[data-modulo="bar"]:visible').first().evaluate(e=>e.getBoundingClientRect().top);
 ok(topo>=65&&topo<=130,'a categoria alinha suavemente o primeiro recurso no desktop');
 await pub.setViewportSize({width:390,height:844}); await pub.reload({waitUntil:'networkidle'});
 ok(await pub.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'a página pública não transborda no telemóvel');
 await pub.locator('#demonstracao [data-aj-modulo="orcamento"]').click();await pub.waitForTimeout(100);
 duracao=await pub.locator('#demonstracao .aj-card[data-modulo="orcamento"]:visible').first().evaluate(e=>parseFloat(getComputedStyle(e).animationDuration)*1000);
 ok(duracao>=1000,'o fading de categoria conserva a duração no telemóvel');
 await pub.waitForTimeout(1150);
 topo=await pub.locator('#demonstracao .aj-card[data-modulo="orcamento"]:visible').first().evaluate(e=>e.getBoundingClientRect().top);
 ok(topo>=55&&topo<=110,'a categoria alinha suavemente o primeiro recurso no telemóvel');

 const ctx=await b.newContext(),p=await ctx.newPage();
 await p.goto(BASE+'/login.php');await p.fill('input[name=utilizador]',USER);await p.fill('input[name=senha]',PASSWORD);await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
 const ler=()=>p.evaluate(async()=>await(await fetch('api.php?action=atendimento_ler')).json());let d=await ler();
 ok(d.success&&d.conteudos.filter(x=>x.tipo==='ajuda').length===7&&d.conteudos.filter(x=>x.tipo==='demo').length===7,'o admin recebe os sete materiais de cada área');
 await p.goto(BASE+'/plataforma.php?vista=atendimento',{waitUntil:'networkidle'});
 ok(await p.locator('#at-ajudas .at-conteudo').count()===7,'o admin gere os sete materiais da Ajuda');
 ok(await p.locator('#at-demos .at-conteudo').count()===7,'o admin gere os sete materiais da Demonstração');

 d=await ler(); const x=d.conteudos.find(x=>x.tipo==='demo'&&x.modulo==='convidados'); const marca=' Prova da montra';
 const gravar=v=>p.evaluate(async v=>await(await fetch('api.php?action=atendimento_conteudo_guardar',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(v)})).json(),v);
 let g=await gravar({...x,resumo:x.resumo+marca});ok(g.success,'o admin edita o material da Demonstração');
 await pub.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});ok((await pub.locator('#demonstracao').innerText()).includes(marca),'a edição aparece na demonstração pública');
 await gravar(x);

 await p.goto(BASE+'/plataforma.php');await p.waitForSelector('#lista-casamentos .casamento, #lista-casamentos button');
 const abrir=p.getByRole('button',{name:'Abrir'}).first();if(await abrir.count()){await abrir.click();await p.waitForLoadState('networkidle');}
 await p.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'});
 const modulosAjuda=await p.locator('.aj-card').evaluateAll(xs=>xs.map(x=>x.dataset.modulo));
 await pub.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});
 const modulosDemo=await pub.locator('#demonstracao .aj-card').evaluateAll(xs=>xs.map(x=>x.dataset.modulo));
 ok(JSON.stringify(modulosDemo)===JSON.stringify(modulosAjuda),'a Demonstração e a Ajuda cobrem os mesmos módulos e percursos');
 ok(erros.length===0,'a montra não produz erros JavaScript');
 await b.close();process.exit(falhas?1:0);
})().catch(e=>{console.error(e);process.exit(1)});
