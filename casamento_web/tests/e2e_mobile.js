const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const ctx=await b.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true});
  const p=await ctx.newPage(); const errs=[];
  p.on('pageerror',e=>errs.push(e.message));
  p.on('console',m=>{if(m.type()==='error')errs.push('CONSOLE: '+m.text());});
  let fails=0; const ok=(c,m)=>{console.log('• '+(c?'PASS':'FAIL')+': '+m); if(!c)fails++;};
  const log=(...a)=>console.log('•',...a);

  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.goto(BASE+'/index.php',{waitUntil:'networkidle'}); await p.waitForTimeout(900);

  const medir = () => p.evaluate(()=>{
    const vis = el => el && el.offsetParent!==null;
    const cards=[...document.querySelectorAll('.stat-f')].filter(vis);
    const busca=document.getElementById('busca');
    return {
      visiveis:cards.length, total:document.querySelectorAll('.stat-f').length,
      buscaTopo:Math.round(busca.getBoundingClientRect().top+window.scrollY),
      botao:(document.getElementById('stats-mais')||{}).textContent,
      botaoVisivel:vis(document.getElementById('stats-mais')),
      scrollH:document.documentElement.scrollWidth>document.documentElement.clientWidth,
    };
  });

  const antes = await medir();
  log('fechado:', JSON.stringify(antes));
  ok(antes.visiveis===4, 'no telemóvel só se veem os 4 cartões essenciais');
  ok(antes.total===12, 'os 12 continuam na página (só escondidos)');
  ok(antes.buscaTopo < 844, 'a caixa de procura cabe no primeiro ecrã (antes começava a 1228px)');
  ok(antes.botaoVisivel && /Mais filtros/.test(antes.botao), 'há um botão para ver os restantes');
  ok(!antes.scrollH, 'a página não anda para o lado');
  await p.screenshot({path:OUT+'/mob_fechado.png'});

  await p.click('#stats-mais'); await p.waitForTimeout(800);
  const depois = await medir();
  log('aberto:', JSON.stringify(depois));
  ok(depois.visiveis===12, 'o botão mostra todos os cartões');
  ok(/Menos filtros/.test(depois.botao), 'o botão passa a "Menos filtros"');
  await p.screenshot({path:OUT+'/mob_aberto.png',fullPage:false});

  await p.click('#stats-mais'); await p.waitForTimeout(700);
  // um filtro dos "extra" força a mostrar os cartões
  await p.evaluate(()=>filtrarGenero('m')); await p.waitForTimeout(900);
  const comFiltro = await medir();
  log('com filtro de género:', JSON.stringify(comFiltro));
  ok(comFiltro.visiveis===12, 'filtrar por um cartão escondido volta a mostrá-los');
  await p.evaluate(()=>limparFiltros()); await p.waitForTimeout(800);

  // unidades coerentes
  const textos = await p.evaluate(()=>{
    document.getElementById('stats-mais').click();
    return null;
  });
  await p.waitForTimeout(800);
  const subs = await p.evaluate(()=>[...document.querySelectorAll('.stat-f')].map(c=>({
    l:c.querySelector('.sl').textContent, n:c.querySelector('.sn').textContent, s:c.querySelector('.ss').textContent, t:c.title })));
  console.log(subs.map(x=>`   ${x.l.padEnd(12)} ${x.n.padStart(3)}  ${x.s}`).join('\n'));
  // O cartão dos brindes conta noutra unidade: mostra a repartição por género.
  const brindes = subs.filter(x=>/Brindes/i.test(x.l));
  const resto   = subs.filter(x=>!/Brindes/i.test(x.l));
  ok(resto.every(x=>/^\d+ convites?$/.test(x.s)), 'os cartões de pessoas contam todos em convites');
  ok(resto.every(x=>/pessoas?\b.*\bem\b.*convites?/.test(x.t)), 'o título explica o que é cada número');
  ok(brindes.length === 1, 'há um cartão de brindes');
  ok(brindes.every(x=>/♂\s*\d+\s*·\s*♀\s*\d+/.test(x.s)), 'o cartão dos brindes reparte-os por género');
  ok(brindes.every(x=>/\d+ a homens.*\d+ a mulheres/.test(x.t)), 'o título dos brindes explica a repartição');

  console.log('\n==== '+(fails===0&&errs.length===0?'ALL PASS':(fails||errs.length)+' FAIL(S)')+' ====');
  console.log('ERRORS:', errs.length?errs.join('\n'):'none');
  await b.close(); process.exit(fails===0&&errs.length===0?0:1);
})().catch(e=>{console.error('FATAL',e);process.exit(1)});
