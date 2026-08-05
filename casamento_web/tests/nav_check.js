const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // Os editores (convite-editor.php, editor-cartao.php) não têm o cabeçalho
  // partilhado: ocupam o ecrã inteiro, como um editor de imagem.
  const paginas=['index.php','mesas.php','graficas.php','digital.php','cartoes.php','manual.php','impressos.php'];
  let fails=0;
  for(const f of paginas){
    const r=await p.goto(BASE+'/'+f,{waitUntil:'domcontentloaded'});
    await p.waitForTimeout(400);
    const d=await p.evaluate(()=>{
      const n=document.querySelector('header.topo nav.nav');
      return { status:1, links:n?[...n.querySelectorAll('a')].map(a=>a.textContent.trim()):null,
        ativo:n?(n.querySelector('.ativo')||{}).textContent:null,
        mono:(document.querySelector('.monograma')||{}).textContent,
        h1:(document.querySelector('header.topo h1')||{}).textContent,
        sub:(document.querySelector('header.topo .sub')||{}).textContent };
    });
    const bom = d.links && d.links.length>=5 && d.ativo && d.h1 && (r.status()===200);
    if(!bom) fails++;
    console.log((bom?'PASS':'FAIL')+'  '+f.padEnd(20)+' ['+r.status()+'] h1="'+d.h1+'" ativo="'+(d.ativo||'').trim()+'" menu='+JSON.stringify(d.links));
  }
  console.log('\n==== '+(fails===0&&errs.length===0?'ALL PASS':fails+' FAIL(S)')+' ====');
  console.log('ERRORS:', errs.length?errs.join('\n'):'none');
  await b.close(); process.exit(fails===0&&errs.length===0?0:1);
})().catch(e=>{console.error('FATAL',e);process.exit(1)});
