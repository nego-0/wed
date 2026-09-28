// O orçamento estreito deixa de ser uma tabela larga encolhida: as despesas
// tornam-se cartões e as prestações conservam datas, prazos e acções inteiros.
const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const PASSWORD = process.env.TEST_PASSWORD;
const dia = n => { const d=new Date(); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10); };

(async()=>{
  if(!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
  const browser=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const ctx=await browser.newContext({viewport:{width:390,height:844}});
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let cid=0;
  try{
    await page.goto(BASE+'/login.php',{waitUntil:'networkidle'});
    await page.fill('[name=utilizador]','admin');await page.fill('[name=senha]',PASSWORD);
    await page.click('button[type=submit]');await page.waitForLoadState('networkidle');
    const resposta=await page.evaluate(async nome=>{const r=await fetch('api.php?action=casamento_criar',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({nome,licenca:12})});return {estado:r.status,texto:await r.text()};},'ZZ Orçamento mobile '+Date.now());
    let novo;try{novo=JSON.parse(resposta.texto);}catch(_){throw new Error('casamento_criar devolveu HTTP '+resposta.estado+': '+resposta.texto.slice(0,500));}
    assert.equal(novo.success,true);cid=novo.id;
    await page.evaluate(async id=>fetch('api.php?action=casamento_abrir&id='+id,{method:'POST',headers:{'X-CSRF-Token':window.CSRF}}),cid);
    await page.goto(BASE+'/orcamento.php',{waitUntil:'networkidle'});
    const semeado=await page.evaluate(async datas=>{
      const post=(a,c)=>fetch('api.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(c)}).then(r=>r.json());
      const d=await post('orc_despesa_guardar',{descricao:'Fotografia e vídeo documental com álbum completo',fornecedor:'Luz do Sul — fotografia e cinema',valor:850000,estado:'previsto'});
      const id=d.id||(d.despesa&&d.despesa.id);
      await post('orc_pagamento_guardar',{despesa_id:id,valor:300000,data_prevista:datas.atrasada,nota:'Primeira prestação em atraso'});
      await post('orc_pagamento_guardar',{despesa_id:id,valor:550000,data_prevista:datas.futura,nota:'Saldo antes da cerimónia'});
      return {success:!!id};
    },{atrasada:dia(-5),futura:dia(12)});
    assert.equal(semeado.success,true);await page.reload({waitUntil:'networkidle'});await page.waitForSelector('table.desp tbody tr');
    const estado=await page.evaluate(()=>{
      const vw=innerWidth,limpo=e=>{const r=e.getBoundingClientRect();return r.left>=-1&&r.right<=vw+1&&e.scrollWidth<=e.clientWidth+1;};
      const fat=document.querySelector('.fat-anexar'),linha=document.querySelector('table.desp tbody tr');
      return {
        pagina:document.documentElement.scrollWidth<=vw+1,
        tabela:limpo(document.querySelector('.tabela-scroll'))&&limpo(linha),
        fatura:!!fat&&limpo(fat)&&getComputedStyle(fat).whiteSpace==='nowrap',
        prazos:[...document.querySelectorAll('.o-prazos,.pag,.pag .data,.pag .desc,.pag-acoes')].every(limpo),
        cartao:getComputedStyle(linha).display==='grid',
        textos:[...document.querySelectorAll('.pag .data')].map(x=>x.textContent.replace(/\s+/g,' ').trim())
      };
    });
    assert.equal(estado.pagina,true,'a página não transborda');
    assert.equal(estado.tabela,true,'o cartão de despesa cabe no ecrã');
    assert.equal(estado.cartao,true,'a despesa usa a disposição mobile em cartão');
    assert.equal(estado.fatura,true,'+ fatura permanece inteiro e dentro do cartão');
    assert.equal(estado.prazos,true,'avisos, prestações e acções cabem no calendário');
    assert(estado.textos.some(x=>/há 5 dias/.test(x)),'o prazo em atraso continua legível');
    assert.deepEqual(errors,[],'sem erros JavaScript');
    if(process.env.SCREENSHOTS){fs.mkdirSync(process.env.SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.SCREENSHOTS,'orcamento-mobile.png'),fullPage:true});}
    console.log('PASS: orçamento mobile sem transbordo, botões partidos ou prazos cortados');
  } finally {
    if(cid) await page.evaluate(async id=>{const h={'X-CSRF-Token':window.CSRF};await fetch('api.php?action=lic_revogar',{method:'POST',headers:{...h,'Content-Type':'application/json'},body:JSON.stringify({casamento:id,motivo:'Fim da prova automática'})}).catch(()=>{});await fetch('api.php?action=casamento_estado&id='+id+'&estado=arquivado',{method:'POST',headers:h}).catch(()=>{});await fetch('api.php?action=casamento_apagar&id='+id,{method:'POST',headers:h}).catch(()=>{});},cid).catch(()=>{});
    await browser.close();
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
