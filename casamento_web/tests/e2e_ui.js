// O painel filtra os convites por mesa: escolhida uma mesa nas pastilhas, a
// lista passa a mostrar só os convites sentados nela.
//
// A prova monta o seu próprio cenário — uma mesa e um convite lá sentado — para
// não depender dos dados que por acaso existam, e arruma tudo no fim.
const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
  const errs=[]; p.on('console',m=>{if(m.type()==='error')errs.push(m.text())}); p.on('pageerror',e=>errs.push('PE:'+e.message));
  let f=0; const ok=(c,m)=>{console.log((c?'PASS':'FAIL')+':',m); if(!c)f++;};
  const log=(...a)=>console.log('•',...a);
  await p.goto(BASE+'/login.php'); await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit],input[type=submit]'); await p.waitForLoadState('networkidle');
  const api=(a,c)=>p.evaluate(async({a,c})=>{
    const r=await fetch('api.php?action='+a,{method:'POST',
      headers:{'X-CSRF-Token':window.CSRF,'Content-Type':'application/json'},
      body:c?JSON.stringify(c):undefined});
    return r.json();
  },{a,c});

  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde esta prova trabalha.
  await api('casamento_abrir&id=1');

  // Monta-se o cenário: um convite sentado numa mesa com nome próprio. A mesa
  // nasce sozinha ao atribuí-la (resolverMesa cria-a se não existir).
  const MESA='Mesa Prova UI', FAM='Família Prova UI';
  const feito=await api('convite_save',{ nome_exibicao:FAM, tipo:'digital', lado:'noivo',
                                          mesa:MESA, membros:[{nome:'Convidado Um'},{nome:'Convidada Dois'}] });
  ok(feito && feito.success!==false, 'cria-se um convite sentado numa mesa própria');
  const mesas=(await api('mesa_list')).mesas||[];
  const aMesa=mesas.find(m=>m.nome===MESA);
  ok(!!aMesa, 'a mesa fica criada ao sentar lá o convite');

  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE+'/index.php',{waitUntil:'networkidle'}); await p.waitForTimeout(700);

  // Clica-se a pastilha da mesa e a lista passa a mostrar só o que lá está.
  await p.locator('#filtro-mesas .chip-m', { hasText: MESA }).first().click();
  await p.waitForTimeout(700);
  const cards=await p.evaluate(()=> [...document.querySelectorAll('.convite-nome')].map(n=>n.textContent.trim()));
  const metas=await p.evaluate(()=> [...document.querySelectorAll('.convite-meta')].map(m=>m.textContent.replace(/\s+/g,' ').trim()));
  log('convites sob o filtro da mesa:', JSON.stringify(cards));
  log('meta do primeiro:', metas[0]||'(nenhuma)');
  ok(cards.includes(FAM), 'a lista mostra o convite sentado nessa mesa');
  ok(cards.length===1, 'e só esse — o filtro deixa de fora os das outras mesas ('+cards.length+')');
  await p.screenshot({path:OUT+'/panel_filter.png'});

  // Arruma-se o cenário: o convite e a mesa criados por esta prova.
  const cid = feito && feito.convite && feito.convite.id ? feito.convite.id : (feito && feito.id);
  if (cid) await api('convite_delete&id='+cid+'&definitivo=1');
  if (aMesa) await api('mesa_delete&id='+aMesa.id);

  console.log('ERRORS:', errs.length?errs.join('\n'):'none');
  console.log(f?`\n${f} FALHA(S)`:'\nTUDO VERDE');
  await b.close(); process.exit(f?1:0);
})().catch(e=>{console.error('FATAL',e);process.exit(1);});
