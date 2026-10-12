// Garante que a versão activa conserva cada documento visual, usa apenas os
// dados Kulemba e nunca volta a inferir estrutura a partir de frases visíveis.
const {chromium}=require('playwright-core');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const BASE=process.env.BASE_URL||'http://127.0.0.1:8926';
const DIR=path.resolve(__dirname,'../assets/convite/modelos/chungdoi-exact/1.1.0');
const EXE=process.env.CHROMIUM||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MODELOS={
 'jasmine-white':['Pedro Boateng','Marta Asante','Golden Tulip Kumasi','Emeka Nwosu'],
 'royal-v2-green':['Lucía Montero','Álvaro Iglesias','Hacienda de Orán','Aaliyah Johnson'],
 'spring-garden-blue':['Andrew Marlowe','Hannah Marlowe','Christopher Kensington'],
 'double-happiness-green':['Aaron Lim','Hui Ling Lim','Marcus Tan','Shermaine Tan','Tanjong Beach Club'],
 'porcelain-brown':['Dana Al Khatib','Faris Haddad'],
 'royal-blue':['Hannah Hoffmann','Maximilian Weber','Elbspeicher'],
 'porcelain-v2-green':['Salma Benjelloun','Mehdi El Amrani','La Mamounia','Kofi Boateng'],
 'hoa-kho-orange':['Farida Hassan','Karim Abdelrahman','Casa Cook El Gouna'],
 'mahal-gold':['James Donovan','Olivia Donovan','Ethan Marlowe','Madison Marlowe','The Cordelle','Freya Lindqvist'],
 'porcelain-v2-pink':['Reem Al Qahtani','Abdullah Al Harbi'],
 'lien-hoa-pink':['Michael Whitaker','Emily Whitaker','Daniel Sullivan']
};
for(const key of Object.keys(MODELOS)){
 const html=fs.readFileSync(path.join(DIR,key+'.html'),'utf8');
 for(const tipo of ['cerimonia','recepcao','programa','mensagens'])assert(html.includes('data-exact-fragment="'+tipo+'"'),key+': âncora explícita '+tipo);
 assert(html.includes('chungdoi-exact/1.1.0/exact-runtime.js'),key+': runtime 1.1.0');
 assert(html.includes('chungdoi-exact/1.1.0/exact-components.js'),key+': componentes 1.1.0');
}
const runtime=fs.readFileSync(path.join(DIR,'exact-runtime.js'),'utf8');
for(const proibido of ['acharTexto','seccoesPorTitulo','normalizarEnvelopeCanonico','normalizarCapaInternaCanonica','canonizarEstrutura'])assert(!runtime.includes(proibido),'runtime sem heurística '+proibido);

(async()=>{const b=await chromium.launch({headless:true,executablePath:EXE,args:['--no-sandbox']});try{
 const p=await b.newPage({viewport:{width:390,height:844}});await p.goto(BASE+'/login.php');await p.fill('[name=utilizador]',process.env.TEST_USER||'admin');await p.fill('[name=senha]',process.env.TEST_PASSWORD||'noivos2026');await p.click('button[type=submit]');await p.waitForLoadState('networkidle');
 const r=await p.evaluate(()=>fetch('api.php?action=modelo_lista&ambito=digital').then(x=>x.json())),ids=new Map((r.modelos||[]).map(x=>[x.renderer_key,+x.id]));
 for(const [key,antigos] of Object.entries(MODELOS)){await p.goto(BASE+'/convite-digital.php?demo=1&modelo='+ids.get(key),{waitUntil:'domcontentloaded'});await p.waitForFunction(()=>window.KulembaConvite&&document.querySelector('[data-exact-added]'));const abrir=p.locator('#cover a,#cover button').filter({hasText:/Abrir/i}).first();await abrir.click();await p.locator('#cover').waitFor({state:'hidden',timeout:2500});
   const texto=(await p.locator('body').innerText()).replace(/\s+/g,' ');for(const antigo of antigos)assert(!texto.toLocaleLowerCase('pt-PT').includes(antigo.toLocaleLowerCase('pt-PT')),key+': não exibe dado de origem '+antigo);
   assert.equal(await p.locator('[data-kulemba-galeria="1"]:not(dialog *):visible').count(),4,key+': quatro fotografias do admin visíveis');
   assert.equal(await p.locator('[data-exact-fragment][hidden]:not(dialog *)').count()>0,true,key+': conteúdo de origem substituído por âncora');
   assert.equal(await p.locator('.exact-semantic-ceremony:visible,.exact-semantic-reception:visible,.exact-semantic-program:visible,[data-sec="acesso"]:visible').count(),4,key+': contrato comum visível');
   assert.equal(await p.evaluate(()=>{const c=document.querySelector('[data-sec="grande-dia"][data-exact-added]'),g=document.querySelector('[data-kulemba-galeria="1"]:not(dialog *)');return !!(c&&g&&(c.compareDocumentPosition(g)&Node.DOCUMENT_POSITION_FOLLOWING));}),true,key+': cerimónia antecede a galeria como na referência aprovada');
   console.log('PASS '+key);
 }
 console.log('PASS: 11 documentos preservados, dados antigos invisíveis e contrato comum activo');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exit(1)});
