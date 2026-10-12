// Acrescenta âncoras estruturais aos HTML congelados. A correspondência por
// texto acontece apenas nesta migração de desenvolvimento; o runtime entregue
// ao browser usa exclusivamente data-exact-source e nunca interpreta frases.
const {chromium}=require('playwright-core');
const fs=require('node:fs');
const path=require('node:path');
const DIR=path.resolve(__dirname,'../assets/convite/modelos/chungdoi-exact/1.1.0');
const KEYS=['jasmine-white','royal-v2-green','spring-garden-blue','double-happiness-green','porcelain-brown','royal-blue','porcelain-v2-green','hoa-kho-orange','mahal-gold','porcelain-v2-pink','lien-hoa-pink'];
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 const context=await browser.newContext({javaScriptEnabled:false});
 const page=await context.newPage();
 for(const key of KEYS){
  const file=path.join(DIR,key+'.html'),source=fs.readFileSync(file,'utf8');
  await page.setContent(source,{waitUntil:'commit'});
  const report=await page.evaluate(()=>{
   const root=document.querySelector('[data-showcase-root]');if(!root)throw new Error('data-showcase-root ausente');
   root.querySelectorAll('[data-exact-source]').forEach(x=>x.removeAttribute('data-exact-source'));
   root.querySelectorAll('[data-exact-fragment]').forEach(x=>x.removeAttribute('data-exact-fragment'));
   const clean=s=>(s||'').replace(/\s+/g,' ').trim().toLocaleUpperCase('pt-PT');
   const kind=h=>{const s=clean(h.textContent);
    if(s.includes('INFORMAÇÕES DA CERIMÓNIA'))return 'cerimonia';
    if(s.includes('GALERIA DE FOTOGRAFIAS'))return 'galeria';
    if(s.includes('INFORMAÇÕES DA RECEPÇÃO'))return 'recepcao';
    if(s.includes('LOCAL DA RECEPÇÃO'))return 'recepcao';
    if(s.includes('PROGRAMA DO DIA'))return 'programa';
    if(/^MENSAGENS(?:MENSAGENS)?$/.test(s))return 'mensagens';
    if(/^PRESENTES(?:PRESENTES)?$/.test(s))return 'presentes';
    return '';
   };
   const headings=[...root.querySelectorAll('h1,h2,h3')],found=[];
   for(const h of headings){const k=kind(h);if(!k||k==='galeria')continue;
    let node=h,chosen=h;
    while(node.parentElement&&node.parentElement!==root){const parent=node.parentElement;if(parent.matches('dialog'))break;
      const kinds=[...new Set([...parent.querySelectorAll('h1,h2,h3')].map(kind).filter(Boolean))];
      if(kinds.some(x=>x!==k))break;chosen=parent;node=parent;
    }
    // Vários cabeçalhos duplicados do mesmo bloco convergem na mesma âncora.
    if(!chosen.closest('dialog')){chosen.setAttribute('data-exact-source',k);if(!found.some(x=>x.node===chosen))found.push({kind:k,node:chosen});}
   }
   // Marca intervalos estruturais completos entre cabeçalhos sem depender
   // desses textos em produção. Isto cobre modelos cujo conteúdo é composto
   // por vários irmãos, sem um contentor exclusivo para a secção.
   const marks=headings.map(h=>({h,k:kind(h)})).filter(x=>x.k&&!x.h.closest('dialog'));
   const unique=[];for(const m of marks){if(!unique.length||unique[unique.length-1].k!==m.k)unique.push(m);}
   const fragments=[];
   for(let i=0;i<unique.length;i++){const cur=unique[i],next=unique[i+1];if(!['cerimonia','recepcao','programa','mensagens'].includes(cur.k)||!next)continue;
    const candidates=[...root.querySelectorAll('*')].filter(node=>{
      if(node.closest('dialog'))return false;
      const after=node===cur.h||!!(cur.h.compareDocumentPosition(node)&Node.DOCUMENT_POSITION_FOLLOWING);
      const before=node!==next.h&&!node.contains(next.h)&&!!(node.compareDocumentPosition(next.h)&Node.DOCUMENT_POSITION_FOLLOWING);
      return after&&before;
    });
    const set=new Set(candidates),maximal=candidates.filter(node=>!set.has(node.parentElement));
    for(const node of maximal){const old=(node.getAttribute('data-exact-fragment')||'').split(/\s+/).filter(Boolean);if(!old.includes(cur.k))old.push(cur.k);node.setAttribute('data-exact-fragment',old.join(' '));fragments.push({kind:cur.k,node});}
   }
   // Os intervalos são as âncoras definitivas. Os marcadores preliminares
   // podem abranger contentores demasiado largos em algumas composições.
   root.querySelectorAll('[data-exact-source]').forEach(x=>x.removeAttribute('data-exact-source'));
   return {sources:found.map(x=>({kind:x.kind,tag:x.node.tagName,cls:String(x.node.className).slice(0,90),text:clean(x.node.textContent).slice(0,90)})),fragments:fragments.map(x=>x.kind+'<'+x.node.tagName.toLowerCase()+'>')};
  });
  const html='<!DOCTYPE html>\n'+await page.evaluate(()=>document.documentElement.outerHTML);
  fs.writeFileSync(file,html+'\n');console.log(key+': '+report.fragments.join(', '));
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
