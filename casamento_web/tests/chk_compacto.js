const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1456,height:900}})).newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  p.on('console',m=>{if(m.type()==='error')errs.push(m.text())});
  let f=0; const ok=(c,m)=>{console.log((c?'PASS':'FAIL')+':',m); if(!c)f++;};
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.waitForTimeout(1800);

  const linhas = await p.locator('.convite-row').count();
  console.log('linhas:', linhas);
  const m = await p.evaluate(()=>{
    const rs=[...document.querySelectorAll('.convite-row')];
    return rs.map(r=>{
      const b=r.getBoundingClientRect();
      const nome=r.querySelector('.convite-nome').getBoundingClientRect();
      const ac=r.querySelector('.acoes').getBoundingClientRect();
      return { alt:Math.round(b.height),
               // ações à direita e na mesma faixa vertical do nome = uma linha só
               umaLinha: ac.left > nome.right && Math.abs(ac.top-nome.top) < b.height,
               nomeEsq: Math.round(nome.left-b.left) };
    });
  });
  console.log('  ', JSON.stringify(m));
  ok(m.every(x=>x.umaLinha), 'as ações ficam à direita, na mesma linha do nome');
  ok(m.every(x=>x.alt <= 60), `cada convite cabe em 60px de altura (${m[0].alt}px)`);
  ok(m.every(x=>x.nomeEsq < 140), 'o nome fica encostado ao selo, sem vão a meio');

  // o CSS chega com marca de versão
  const links = await p.evaluate(()=>[...document.querySelectorAll('link[rel=stylesheet]')].map(l=>l.getAttribute('href')));
  console.log('  css:', links.join(' '));
  ok(links.every(h=>/\?v=\d+/.test(h)), 'as folhas de estilo levam marca de versão');
  const scripts = await p.evaluate(()=>[...document.querySelectorAll('script[src]')].map(s=>s.getAttribute('src')));
  ok(scripts.every(h=>/\?v=\d+/.test(h)), 'os scripts levam marca de versão');

  await p.screenshot({path:OUT+'/lista_compacta.png'});
  console.log('erros JS:', errs.length?errs.join(' | '):'nenhum');
  ok(errs.length===0,'nenhum erro de JavaScript');
  console.log(f?`\n${f} FALHA(S)`:'\nTUDO VERDE');
  await b.close(); process.exit(f?1:0);
})();
