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
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se um casamento ativo, sem depender do número desta instalação.
  await p.evaluate(async () => {
    const l = await (await fetch('api.php?action=casamento_lista&estado=ativo',
      { headers: { 'X-CSRF-Token': window.CSRF } })).json();
    const c = (l.casamentos || [])[0];
    await fetch('api.php?action=casamento_abrir&id=' + c.id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

  await p.goto(BASE+'/index.php',{waitUntil:'networkidle'}); await p.waitForTimeout(900);

  const medir = () => p.evaluate(()=>{
    const vis = el => el && el.offsetParent!==null;
    const cards=[...document.querySelectorAll('.stat-f')].filter(vis);
    const busca=document.getElementById('busca');
    // A tira amarela «está a ver como administração da plataforma» é do ecrã
    // desta PROVA e não do ecrã dos noivos: quem entra na sua própria festa
    // nunca a vê. Ela pesa ~75px no telemóvel, e contá-la era medir a página
    // do casal com um aviso que só existe porque a prova entra por admin.
    const tira=document.querySelector('.tira-suporte');
    const suporte=tira?Math.round(tira.getBoundingClientRect().height):0;
    return {
      visiveis:cards.length, total:document.querySelectorAll('.stat-f').length,
      suporte,
      buscaTopo:Math.round(busca.getBoundingClientRect().top+window.scrollY)-suporte,
      botao:(document.getElementById('stats-mais')||{}).textContent,
      botaoVisivel:vis(document.getElementById('stats-mais')),
      scrollH:document.documentElement.scrollWidth>document.documentElement.clientWidth,
    };
  });

  const antes = await medir();
  log('fechado:', JSON.stringify(antes));
  ok(antes.visiveis===4, 'no telemóvel só se veem os 4 cartões essenciais');
  // Eram 12; com a tira de módulos junta aos filtros são mais. O que importa
  // é que os escondidos CONTINUAM na página, e não que sejam um número certo.
  ok(antes.total>antes.visiveis, 'os restantes continuam na página (só escondidos): '
     + antes.visiveis + ' à vista de ' + antes.total);
  ok(antes.buscaTopo < 844, 'a caixa de procura cabe no primeiro ecrã do casal '
     + '(antes começava a 1228px; agora a ' + antes.buscaTopo + ', sem a tira de suporte)');
  ok(antes.botaoVisivel && /Mais filtros/.test(antes.botao), 'há um botão para ver os restantes');
  ok(!antes.scrollH, 'a página não anda para o lado');
  await p.screenshot({path:OUT+'/mob_fechado.png'});

  await p.click('#stats-mais'); await p.waitForTimeout(800);
  const depois = await medir();
  log('aberto:', JSON.stringify(depois));
  ok(depois.visiveis===antes.total, 'o botão mostra todos os cartões: ' + depois.visiveis);
  ok(/Menos filtros/.test(depois.botao), 'o botão passa a "Menos filtros"');
  await p.screenshot({path:OUT+'/mob_aberto.png',fullPage:false});

  await p.click('#stats-mais'); await p.waitForTimeout(700);
  // um filtro dos "extra" força a mostrar os cartões
  await p.evaluate(()=>filtrarGenero('m')); await p.waitForTimeout(900);
  const comFiltro = await medir();
  log('com filtro de género:', JSON.stringify(comFiltro));
  ok(comFiltro.visiveis===antes.total, 'filtrar por um cartão escondido volta a mostrá-los');
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
  // A linha de baixo dizia sempre «N convites». Passou a dizer a UNIDADE do que
  // conta — «5 de 13 impressos», «16 de 20 responderam», «sem despesas» —,
  // porque era a falta disso que punha «Impressos 7» e «Impressos 5 de 13» no
  // mesmo ecrã a parecerem contradizer-se. O que se guarda é que NENHUMA linha
  // de baixo fica muda, e que todas nomeiam aquilo que contam.
  const semUnidade = resto.filter(x=>!/[a-zà-ú]{3}/i.test(x.s||''));
  ok(semUnidade.length===0,
     'cada linha de baixo diz o que conta, em vez de deixar adivinhar: '
     + (semUnidade.map(x=>x.l).join(', ') || 'todas dizem'));
  // Nos cartões ainda sem conta, o título é a DICA («Ainda não há despesas
  // lançadas.») em vez do rótulo repetido — explica melhor, e é para isso que
  // ele serve. O que se exige é que explique, não que se repita.
  const semTitulo = resto.filter(x=>(x.t||'').trim().length<10);
  ok(semTitulo.length===0,
     'e o título explica o que é cada número: '
     + (semTitulo.map(x=>x.l+' «'+x.t+'»').join(' | ') || 'todos explicam'));
  ok(brindes.length === 1, 'há um cartão de brindes');
  // Os sinais de género são DESENHADOS (assets/icones.js) e já não os
  // caracteres ♂ ♀: o innerText não os traz, e por isso o que se lê aqui são
  // os dois números com o ponto do meio. Que os sinais lá estão prova-se
  // abaixo, pelos elementos.
  ok(brindes.every(x=>/^\s*\d+\s*·\s*\d+/.test(x.s)), 'o cartão dos brindes reparte-os por género');
  const sinaisBrinde = await p.$$eval('.stat-f .ss [data-ico]', ns => ns.map(n => n.dataset.ico));
  ok(sinaisBrinde.includes('homem') && sinaisBrinde.includes('mulher'),
     'e a repartição traz os dois sinais desenhados: ' + sinaisBrinde.join(', '));
  ok(brindes.every(x=>/\d+ a homens.*\d+ a mulheres/.test(x.t)), 'o título dos brindes explica a repartição');

  console.log('\n==== '+(fails===0&&errs.length===0?'ALL PASS':(fails||errs.length)+' FAIL(S)')+' ====');
  console.log('ERRORS:', errs.length?errs.join('\n'):'none');
  await b.close(); process.exit(fails===0&&errs.length===0?0:1);
})().catch(e=>{console.error('FATAL',e);process.exit(1)});
