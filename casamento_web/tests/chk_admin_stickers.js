// Valida a gestão e a geração de stickers pelo admin da plataforma.
// BASE_URL, TEST_USER, TEST_PASSWORD e CHROMIUM podem ser definidos no ambiente.
const {chromium}=require('playwright-core');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8920';
const USER=process.env.TEST_USER||'admin',PASSWORD=process.env.TEST_PASSWORD;
const EXE=process.env.CHROMIUM||(process.platform==='win32'?'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe':'/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
if(!PASSWORD)throw new Error('Defina TEST_PASSWORD.');
const assert=(ok,msg)=>{if(!ok)throw new Error(msg);};
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await b.newPage({viewport:{width:1280,height:800}});
  p.on('pageerror',e=>console.error('PAGE:',e.message));
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('[name=utilizador]',USER);await p.fill('[name=senha]',PASSWORD);await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
  const api=(acao,body)=>p.evaluate(async({acao,body})=>{const o={headers:{'X-CSRF-Token':window.CSRF}};if(body){o.method='POST';o.headers['Content-Type']='application/json';o.body=JSON.stringify(body);}return fetch('api.php?action='+acao,o).then(r=>r.json());},{acao,body});
  await api('casamento_abrir',{id:1});
  const estado=await api('atendimento_ler');
  const ajuda=(estado.conteudos||[]).find(x=>x.tipo==='ajuda'&&x.modulo==='digital');
  const demo=(estado.conteudos||[]).find(x=>x.tipo==='demo'&&x.modulo==='digital');
  assert(ajuda&&demo,'faltam os materiais digitais da Ajuda ou da Demonstração');
  for(const x of [ajuda,demo]){
    const r=await api('atendimento_stickers_gerar',{id:+x.id});assert(r.success,'falhou a geração de '+x.tipo);
    const salvo=(r.conteudos||[]).find(y=>+y.id===+x.id),d=JSON.parse(salvo.stickers||'{}');
    assert(Array.isArray(d.stickers)&&d.stickers.length,'a geração não guardou stickers em '+x.tipo);
  }
  let atual=await api('atendimento_ler');
  const h=(atual.conteudos||[]).find(x=>+x.id===+ajuda.id),cfg=JSON.parse(h.stickers).stickers;
  cfg[0][0]=['abrir',41,42,43,44,1,1,41,42,43,44];
  const guardado=await api('atendimento_stickers_guardar',{id:+ajuda.id,stickers:cfg});assert(guardado.success,'falhou a gestão manual');
  const g=(guardado.conteudos||[]).find(x=>+x.id===+ajuda.id),gravado=JSON.parse(g.stickers).stickers[0][0];
  assert(gravado[1]===41&&gravado[4]===44&&gravado[5]===1,'as posições e a rolagem geridas não foram guardadas');
  await api('atendimento_stickers_gerar',{id:+ajuda.id});
  await p.goto(BASE+'/plataforma.php',{waitUntil:'networkidle'});
  await p.evaluate(()=>verVista('atendimento'));
  await p.evaluate(()=>atCarregar());
  assert(await p.getByRole('heading',{name:/Montra pública/}).count(),'falta a área de Demonstração');
  assert(await p.getByRole('button',{name:/Gerar stickers da/}).count()===2,'faltam os comandos de geração por área');
  await p.evaluate(id=>atGerirStickers(id),+demo.id);
  await p.locator('#lic-janela .at-sticker-passo').first().waitFor();
  assert(await p.locator('#lic-janela .at-sticker-passo').count()>=1,'o gestor não abriu os passos da demonstração');
  if(process.env.ADMIN_STICKER_SCREENSHOT)await p.screenshot({path:process.env.ADMIN_STICKER_SCREENSHOT,fullPage:true});
  console.log('PASS: admin gera e gere stickers independentes para Ajuda e Demonstração');
  await b.close();
})().catch(e=>{console.error(e);process.exit(1)});
