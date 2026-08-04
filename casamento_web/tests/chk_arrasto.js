// Arrastar mesmo com o rato: faixas de tamanho e seletores de cor.
const { chromium } = require('playwright-core');
const EXE=process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', BASE=process.env.BASE_URL || 'http://127.0.0.1:8920';
(async()=>{
  const b=await chromium.launch({executablePath:EXE,args:['--no-sandbox']});
  const p=await (await b.newContext({viewport:{width:1440,height:950}})).newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  p.on('console',m=>{if(m.type()==='error')errs.push(m.text())});
  p.on('dialog',d=>d.accept());
  let f=0; const ok=(c,m)=>{console.log((c?'PASS':'FAIL')+':',m); if(!c)f++;};
  await p.goto(BASE+'/login.php',{waitUntil:'networkidle'});
  await p.fill('input[name=utilizador]','admin'); await p.fill('input[name=senha]','noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  /** Arrasta uma faixa de ponta a ponta com o rato e devolve o valor final. */
  async function arrastar(sel){
    const el = p.locator(sel).first();
    await el.scrollIntoViewIfNeeded();
    await p.waitForTimeout(200);
    const bb = await el.boundingBox();
    await p.mouse.move(bb.x+bb.width*0.5, bb.y+bb.height/2);
    await p.mouse.down();
    // várias mexidas: se o painel se redesenhar, o alvo desaparece e o valor
    // deixa de acompanhar a partir da primeira
    for (const t of [0.6,0.7,0.8,0.9,1.0]) {
      await p.mouse.move(bb.x+bb.width*t, bb.y+bb.height/2, {steps:3});
      await p.waitForTimeout(60);
    }
    await p.mouse.up();
    await p.waitForTimeout(250);
    return { valor: await el.inputValue().catch(()=>null),
             max:   await el.getAttribute('max'),
             viva:  await p.evaluate(s=>!!document.querySelector(s), sel) };
  }

  // ---------- editor do cartão ----------
  await p.goto(BASE+'/editor-cartao.php',{waitUntil:'networkidle'}); await p.waitForTimeout(1500);

  // faixa: tamanho do texto (Tipografia)
  await p.click('#p-tipografia h3'); await p.waitForTimeout(400);
  const escAntes = await p.evaluate(()=>est.escala);
  let r = await arrastar('#tipografia input[type=range]');
  console.log('  escala:', escAntes, '->', r.valor, '| est =', await p.evaluate(()=>est.escala));
  ok(r.viva, 'a faixa do tamanho sobrevive ao arrasto');
  ok(String(await p.evaluate(()=>est.escala)) === String(r.valor), 'o estado acompanha o arrasto até ao fim');
  ok(r.valor === r.max, `arrastar até ao fim chega ao máximo (${r.valor} de ${r.max})`);

  // faixa: tamanho de um ornamento (Propriedades da camada)
  await p.evaluate(()=>selecionar('volutas')); await p.waitForTimeout(400);
  r = await arrastar('#props input[type=range]');
  console.log('  volutas ->', r.valor, '| est =', await p.evaluate(()=>est.deco['cartao.volutas_escala']));
  ok(r.viva, 'a faixa do tamanho do ornamento sobrevive ao arrasto');
  ok(String(await p.evaluate(()=>est.deco['cartao.volutas_escala'])) === String(r.valor),
     'o tamanho do ornamento acompanha o arrasto');
  ok(r.valor === r.max, `o arrasto do ornamento vai até ao fim (${r.valor} de ${r.max})`);

  // faixa: margem da moldura
  await p.evaluate(()=>selecionar('moldura')); await p.waitForTimeout(400);
  r = await arrastar('#props input[type=range]');
  console.log('  margem ->', r.valor, '| est =', await p.evaluate(()=>est.deco['cartao.moldura_margem']));
  ok(r.viva, 'a faixa da margem sobrevive ao arrasto');
  ok(String(await p.evaluate(()=>est.deco['cartao.moldura_margem'])) === String(r.valor),
     'a margem da moldura acompanha o arrasto');
  ok(r.valor === r.max, `o arrasto da margem vai até ao fim (${r.valor} de ${r.max})`);

  // seletor de cor: várias mudanças seguidas não podem redesenhar o painel
  await p.click('#p-cores h3'); await p.waitForTimeout(400);
  const antesHTML = await p.evaluate(()=>document.getElementById('cores').innerHTML.length);
  await p.evaluate(()=>{
    const i=document.querySelector('#cores input[type=color]');
    ['#FF0000','#00FF00','#0000FF'].forEach(c=>{ i.value=c; i.dispatchEvent(new Event('input',{bubbles:true})); });
    window.__mesmoNo = document.querySelector('#cores input[type=color]') === i;
  });
  await p.waitForTimeout(300);
  ok(await p.evaluate(()=>window.__mesmoNo),
     'o seletor de cor continua a ser o mesmo nó depois de várias mudanças');
  ok(await p.evaluate(()=>est.cores.accent) === '#0000FF', 'a última cor escolhida é a que fica');

  // O painel de cores do navegador fecha-se se o elemento a que está preso
  // mudar de sítio ou de tamanho. Nada pode mexer na linha durante a escolha.
  const linha = await p.evaluate(()=>{
    // A última cor, que nenhuma prova anterior tocou: é nela que o ↺ ainda
    // não existiria se voltasse a nascer durante a escolha.
    const ins = document.querySelectorAll('#cores input[type=color]');
    const inp = ins[ins.length - 1];
    const lin = inp.closest('.cor-linha');
    const antes = { filhos: lin.children.length, larg: Math.round(lin.getBoundingClientRect().width),
                    x: Math.round(inp.getBoundingClientRect().left), tag: lin.tagName };
    ['#112233','#445566','#778899'].forEach(c=>{ inp.value=c; inp.dispatchEvent(new Event('input',{bubbles:true})); });
    const dep = { filhos: lin.children.length, larg: Math.round(lin.getBoundingClientRect().width),
                  x: Math.round(inp.getBoundingClientRect().left), tag: lin.tagName };
    return { antes, dep };
  });
  console.log('  linha da cor:', JSON.stringify(linha));
  ok(linha.antes.filhos === linha.dep.filhos, 'escolher a cor não faz nascer elementos na linha');
  ok(linha.antes.x === linha.dep.x, 'o seletor de cor não se move enquanto se escolhe');
  ok(linha.dep.tag !== 'LABEL', 'o seletor de cor não vive dentro de um <label> (que o reactivava)');

  // O guarda existe e adia redesenhos durante um gesto
  ok(await p.evaluate(()=>typeof window.adiavel === 'function'), 'o guarda que adia redesenhos está carregado');
  ok(await p.evaluate(()=>{
    let correu = 0;
    const f = window.adiavel('prova', ()=>correu++);
    const inp = document.querySelector('#cores input[type=color]');
    inp.dispatchEvent(new Event('input',{bubbles:true}));   // marca "cor aberta"
    f();                                                     // deve ficar adiado
    return correu === 0;
  }), 'um redesenho pedido durante a escolha da cor fica adiado');

  // ---------- editor do convite digital ----------
  await p.goto(BASE+'/convite-editor.php',{waitUntil:'networkidle'}); await p.waitForTimeout(2600);
  await p.evaluate(()=>document.querySelectorAll('.ed-painel').forEach(x=>{
    if(/Tipografia/.test(x.querySelector('h3').textContent)) x.classList.remove('fechado');}));
  await p.waitForTimeout(500);
  const dAntes = await p.evaluate(()=>EST.val['tipo.escala']);
  r = await arrastar('#tipografia input[type=range]');
  console.log('  digital escala:', dAntes, '->', r.valor, '| EST =', await p.evaluate(()=>EST.val['tipo.escala']));
  ok(r.viva, 'a faixa do tamanho do convite digital sobrevive ao arrasto');
  ok(String(await p.evaluate(()=>EST.val['tipo.escala'])) === String(r.valor),
     'o estado do convite digital acompanha o arrasto');
  ok(r.valor === r.max, `o arrasto no convite digital vai até ao fim (${r.valor} de ${r.max})`);

  await p.evaluate(()=>document.querySelectorAll('.ed-painel').forEach(x=>{
    if(/Cores/.test(x.querySelector('h3').textContent)) x.classList.remove('fechado');}));
  await p.waitForTimeout(400);
  await p.evaluate(()=>{
    const i=document.querySelector('#cores input[type=color]');
    ['#FF0000','#00FF00'].forEach(c=>{ i.value=c; i.dispatchEvent(new Event('input',{bubbles:true})); });
    window.__mesmoNo2 = document.querySelector('#cores input[type=color]') === i;
  });
  await p.waitForTimeout(300);
  ok(await p.evaluate(()=>window.__mesmoNo2), 'o seletor de cor do convite digital não se refaz a meio');

  console.log('erros JS:', errs.length?errs.join(' | '):'nenhum');
  ok(errs.length===0,'nenhum erro de JavaScript');
  console.log(f?`\n${f} FALHA(S)`:'\nTUDO VERDE');
  await b.close(); process.exit(f?1:0);
})();
