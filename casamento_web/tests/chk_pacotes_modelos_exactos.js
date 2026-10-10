const fs=require('fs');
const path=require('path');

const web=path.join(__dirname,'..');
const base=path.join(web,'assets','convite','modelos','chungdoi-exact','1.0.0');
const modelos=['porcelain-v2-green','porcelain-v2-pink','jasmine-white','royal-v2-green','spring-garden-blue','double-happiness-green','porcelain-brown','royal-blue','hoa-kho-orange','mahal-gold','lien-hoa-pink'];
let falhas=0;
function ok(cond,msg){console.log(`${cond?'✓':'✗'} ${msg}`);if(!cond)falhas++;}

for(const slug of modelos){
  const manifesto=JSON.parse(fs.readFileSync(path.join(base,`${slug}.json`),'utf8'));
  const html=fs.readFileSync(path.join(base,`${slug}.html`),'utf8');
  ok(!Object.hasOwn(manifesto,'fotos')&&!Object.hasOwn(manifesto,'musica'),`${slug} não incorpora fotografias nem música`);
  ok(Array.isArray(manifesto.editor?.seccoes)&&Array.isArray(manifesto.editor?.campos_editaveis)&&Array.isArray(manifesto.editor?.media),`${slug} declara capacidades do editor`);
  ok(manifesto.runtime?.adaptador==='exact-runtime.js'&&manifesto.runtime?.dados==='semanticos',`${slug} declara o adaptador semântico`);
  ok(!/weddingdemo|venmo\.com\/wedding-demo|banco de exemplo|00000000/i.test(html),`${slug} não conserva dados fictícios de presentes`);
  ok(!/assets\/convite\/modelos\/chungdoi-exact\/1\.0\.0\/assets\/[^"')]+\.mp3/i.test(html),`${slug} não referencia música do pacote`);
}

const ficheiros=[];
function percorrer(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){const p=path.join(dir,entry.name);if(entry.isDirectory())percorrer(p);else ficheiros.push(p);}}
percorrer(base);
const fontes=ficheiros.filter(p=>/\.(?:html|css|js|json)$/i.test(p));
const ausentes=[];
for(const p of fontes){
  const src=fs.readFileSync(p,'utf8');
  for(const m of src.matchAll(/assets\/convite\/[a-z0-9_./-]+\.(?:webp|png|jpe?g|svg|woff2?|ttf|mp3)/gi)){
    const alvo=path.join(web,...m[0].split('/'));
    if(!fs.existsSync(alvo))ausentes.push(`${path.basename(p)} → ${m[0]}`);
  }
  for(const m of src.matchAll(/url\(["']?(assets\/[a-z0-9_.-]+\.(?:webp|png|jpe?g|svg|woff2?|ttf))["']?\)/gi)){
    const alvo=path.join(base,...m[1].split('/'));
    if(!fs.existsSync(alvo))ausentes.push(`${path.basename(p)} → ${m[1]}`);
  }
}
ok(ausentes.length===0,`todos os recursos referidos existem${ausentes.length?`: ${ausentes[0]}`:''}`);
ok(!ficheiros.some(p=>p.toLowerCase().endsWith('.mp3')),'o pacote não contém ficheiros de música');
const faces=(fs.readFileSync(path.join(base,'exact-base.css'),'utf8').match(/@font-face/g)||[]).length;
ok(faces<=100,`as fontes foram reduzidas às famílias utilizadas (${faces} faces)`);
const bytes=ficheiros.reduce((n,p)=>n+fs.statSync(p).size,0);
ok(bytes<20*1024*1024,`o pacote completo fica abaixo de 20 MB (${(bytes/1048576).toFixed(2)} MB)`);

if(falhas){console.error(`\n${falhas} falha(s).`);process.exit(1);}
console.log('\nOs onze pacotes exactos passaram na validação estrutural.');
