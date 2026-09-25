// A montra só usa ficção, e o admin manda no que ela diz.
const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM; const BASE=process.env.BASE_URL||'http://127.0.0.1:8921';
(async()=>{const b=await chromium.launch({executablePath:EXE,headless:true});let falhas=0;const ok=(v,m)=>{console.log((v?'PASS':'FAIL')+': '+m);if(!v)falhas++;};
 const pub=await b.newPage();const erros=[];pub.on('pageerror',e=>erros.push(e.message));await pub.goto(BASE+'/atendimento.php',{waitUntil:'networkidle'});
 ok(await pub.locator('[data-demo]').count()===7,'a montra apresenta os sete módulos');
 ok(await pub.locator('[data-painel]').count()===7,'cada módulo tem uma vista própria');
 ok((await pub.textContent('body')).includes('DADOS FICTÍCIOS'),'a demonstração declara que os dados são fictícios');
 await pub.locator('[data-demo="bar"]').click();
 ok(await pub.locator('[data-painel="bar"]').isVisible(),'é possível mudar de módulo sem sair da visão geral');
 ok((await pub.locator('[data-painel="bar"] .demo-metricas').textContent()).includes('Na copa'),'o módulo usa valores de demonstração, não dados da base');
 const ctx=await b.newContext();const p=await ctx.newPage();await p.goto(BASE+'/login.php');await p.fill('input[name=utilizador]','admin@local');await p.fill('input[name=senha]','noivos2026');await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
 const ler=()=>p.evaluate(async()=>await(await fetch('api.php?action=atendimento_ler')).json());let d=await ler();
 ok(d.success&&d.conteudos.filter(x=>x.tipo==='demo').length===7,'o admin recebe as sete demonstrações para editar');
 ok(d.conteudos.filter(x=>x.tipo==='ajuda').length===7,'e os sete materiais de ajuda');
 const x=d.conteudos.find(x=>x.tipo==='demo'&&x.modulo==='convidados');const marca=' [prova]';
 const gravar=v=>p.evaluate(async v=>await(await fetch('api.php?action=atendimento_conteudo_guardar',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(v)})).json(),v);
 let g=await gravar({...x,titulo:x.titulo+marca});ok(g.success,'o admin edita o conteúdo da demonstração');
 let pubApi=await p.evaluate(async()=>await(await fetch('api.php?action=atendimento_publico')).json());ok(pubApi.demonstracoes.some(y=>y.titulo.endsWith(marca)),'a edição chega à montra pública');
 await gravar(x);
 await p.goto(BASE+'/plataforma.php');await p.waitForSelector('#lista-casamentos .casamento, #lista-casamentos button');
 const abrir=p.getByRole('button',{name:'Abrir'}).first();if(await abrir.count()){await abrir.click();await p.waitForLoadState('networkidle');}
 await p.goto(BASE+'/ajuda.php',{waitUntil:'networkidle'});ok(await p.locator('.aj-card').count()===7,'a licença de demonstração abre os sete guias');
 ok(await p.locator('.aj-card img').evaluateAll(async xs=>(await Promise.all(xs.map(x=>fetch(x.src)))).every(r=>r.ok&&/^image\//.test(r.headers.get('content-type')||''))),'os GIFs dos guias carregam');
 ok(erros.length===0,'a montra não produz erros JavaScript');await b.close();process.exit(falhas?1:0);
})().catch(e=>{console.error(e);process.exit(1)});
