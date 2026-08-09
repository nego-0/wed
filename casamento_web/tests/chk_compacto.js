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

  // ---- um convite cheio não pode ser mais alto que um vazio ----
  // Antes, as pastilhas dos convidados quebravam para uma segunda linha e um
  // convite de seis pessoas ocupava 104px contra 53px de um de duas. O teste
  // só passava porque os dados de então tinham poucos convidados — agora cria
  // o caso difícil em vez de esperar por ele.
  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  const d = await api('convite_save', {
    nome_exibicao: 'Família Nascimento Agostinho e Silva Pereira',
    tipo: 'ambos', lado: 'ambos', lugares: 10, telefone: '+244912000111',
    membros: ['Ana Maria Nascimento','Rui Miguel Agostinho','Marta Sofia Agostinho',
              'Tiago André Agostinho','Inês Nascimento','Pedro Nascimento',
              'Joana Maria do Nascimento','Carlos Alberto','Beatriz N.','Duarte N.'] });
  const idCheio = d && d.convite && d.convite.id;
  ok(!!idCheio, 'cria um convite com dez convidados e nome comprido');
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1800);

  const cheio = await p.evaluate(() => {
    const r = [...document.querySelectorAll('.convite-row')]
      .find(x => x.querySelector('.convite-nome').textContent.includes('Nascimento Agostinho'));
    if (!r) return null;
    const chips = [...r.querySelectorAll('.membro-chip')];
    return {
      alt: Math.round(r.getBoundingClientRect().height),
      chips: chips.length,
      // uma pastilha esmagada a "A…" não diz nada: nenhuma pode vir cortada
      esmagada: chips.some(c => c.scrollWidth > c.clientWidth + 1),
      temMais: chips.some(c => c.classList.contains('mais') && /^\+\d+$/.test(c.textContent.trim()))
    };
  });
  console.log('   convite cheio:', JSON.stringify(cheio));
  ok(cheio && cheio.alt <= 60, `um convite de dez pessoas cabe na mesma altura (${cheio && cheio.alt}px)`);
  ok(cheio && !cheio.esmagada, 'nenhuma pastilha de nome sai esmagada');
  ok(cheio && cheio.temMais, 'os convidados que não cabem são contados num "+N"');
  if (idCheio) await api('convite_delete&id=' + idCheio + '&definitivo=1', {});

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
