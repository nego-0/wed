// Recria as capturas responsivas da Ajuda a partir das páginas reais.
// Requer uma instalação local com dados de demonstração e uma conta admin.
// BASE_URL, TEST_USER, TEST_PASSWORD e CHROMIUM podem ser definidos no ambiente.
const { chromium } = require('playwright-core');
const fs = require('node:fs'), path = require('node:path'), { spawnSync } = require('node:child_process');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const USER = process.env.TEST_USER || 'admin';
const PASSWORD = process.env.TEST_PASSWORD;
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const OUT = process.env.HELP_CAPTURE_OUT || path.join(__dirname,'..','assets','ajuda','capturas');
if(!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
fs.mkdirSync(OUT,{recursive:true});

const modulos = [
  ['convidados',[
    ['/index.php',async p=>p.evaluate(()=>novoConvite())], ['/index.php',null]
  ]],
  ['mesas',[
    ['/mesas.php',async p=>p.evaluate(()=>{const d=document.getElementById('barra-add-dobra');if(d)d.open=true;})], ['/mesas.php',null]
  ]],
  ['impresso',[
    ['/editor-cartao.php',null], ['/manual.php?peca=cartao',null]
  ]],
  ['digital',[
    ['/convite-editor.php',null], ['/convite-digital.php?demo=1',null]
  ]],
  ['porta',[
    ['/porteiro.php',null], ['/porteiro.php',async p=>p.locator('#tab-ent').click()]
  ]],
  ['bar',[
    ['/bar.php',null], ['/copa.php',null]
  ]],
  ['orcamento',[
    ['/orcamento.php',async p=>p.evaluate(()=>abrirDespesa())], ['/orcamento.php',null]
  ]],
];

async function enquadrar(p){
  await p.waitForTimeout(500);
  await p.evaluate(()=>{const m=document.querySelector('main');if(m)scrollTo({top:Math.max(0,m.offsetTop-8),behavior:'instant'});});
  await p.waitForTimeout(250);
}
async function guardar(p,nome,topico,dispositivo){
  await enquadrar(p);
  await p.screenshot({path:path.join(OUT,`${nome}-${topico}-${dispositivo}.jpg`),type:'jpeg',quality:84,fullPage:false});
}
(async()=>{
  const browser=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const ctx=await browser.newContext({viewport:{width:1280,height:800},deviceScaleFactor:1,reducedMotion:'reduce'});
  const p=await ctx.newPage();
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('[name=utilizador]',USER); await p.fill('[name=senha]',PASSWORD);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async()=>fetch('api.php?action=casamento_abrir&id=1',{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}));
  for(const [dispositivo,viewport] of [['desktop',{width:1280,height:800}],['mobile',{width:390,height:780}]]){
    await p.setViewportSize(viewport);
    for(const [nome,topicos] of modulos){
      for(let i=0;i<topicos.length;i++){
        const [url,preparar]=topicos[i]; await p.goto(BASE+url,{waitUntil:'networkidle'});
        try{if(preparar)await preparar(p);}catch(e){console.warn(`${nome}/${i+1}: ${e.message}`);}
        await guardar(p,nome,i+1,dispositivo);
      }
      console.log(`CAPTURA ${nome} · ${dispositivo}`);
    }
  }
  await browser.close();
  const PYTHON=process.env.PYTHON || (process.platform==='win32'?'python':'python3');
  const gifs=spawnSync(PYTHON,[path.join(__dirname,'gerar-gifs-ajuda.py')],{stdio:'inherit',env:process.env});
  if(gifs.status!==0)throw new Error('Não foi possível gerar os GIFs dos passos. Defina PYTHON com o executável correcto.');
})().catch(e=>{console.error(e);process.exit(1)});
