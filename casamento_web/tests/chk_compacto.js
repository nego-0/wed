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

  // Restos de uma corrida que tenha rebentado antes de limpar: sem isto, o
  // alvo deixa de ser único e a prova seguinte falha por arrasto.
  const MARCA = 'ZZ Prova Cartão Cheio';
  for (const c of ((await api('convite_list')).convites || [])) {
    if ((c.nome_exibicao || '').includes(MARCA)) await api('convite_delete&id=' + c.id + '&definitivo=1', {});
  }

  const d = await api('convite_save', {
    nome_exibicao: MARCA + ' Nascimento Agostinho e Silva Pereira',
    tipo: 'ambos', lado: 'ambos', lugares: 10, telefone: '+244912000111',
    membros: ['Ana Maria Nascimento','Rui Miguel Agostinho','Marta Sofia Agostinho',
              'Tiago André Agostinho','Inês Nascimento','Pedro Nascimento',
              'Joana Maria do Nascimento','Carlos Alberto','Beatriz N.','Duarte N.'] });
  const idCheio = d && d.convite && d.convite.id;
  ok(!!idCheio, 'cria um convite com dez convidados e nome comprido');
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1800);

  const cheio = await p.evaluate(() => {
    const r = [...document.querySelectorAll('.convite-row')]
      .find(x => x.querySelector('.convite-nome').textContent.includes("ZZ Prova Cartão Cheio"));
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

  // ---- clicar no "+N" abre a lista toda no próprio cartão ----
  const alvo = '.convite-row:has(.convite-nome[title*="ZZ Prova Cartão Cheio"])';
  const estado = () => p.evaluate((s) => {
    const r = document.querySelector(s);
    return { alt: Math.round(r.getBoundingClientRect().height),
             nomes: [...r.querySelectorAll('.membro-chip')].map(c => c.textContent.trim()),
             aberta: r.classList.contains('pessoas-abertas'),
             aria: r.querySelector('.membro-chip.mais')?.getAttribute('aria-expanded') };
  }, alvo);

  await p.locator(alvo + ' .membro-chip.mais').click();
  await p.waitForTimeout(300);
  const ab = await estado();
  console.log('   aberto:', JSON.stringify(ab.nomes));
  ok(ab.aberta && ab.aria === 'true', 'clicar no "+N" abre a lista de pessoas');
  ok(ab.nomes.filter(n => !/menos/.test(n)).length === 10, 'aparecem os dez convidados');
  ok(ab.nomes.some(n => /Ana Maria Nascimento/.test(n)),
     'os nomes vêm por inteiro, não só o primeiro nome');
  ok(ab.alt > cheio.alt, 'o cartão cresce só depois de o utilizador o pedir');

  await p.locator(alvo + ' .membro-chip.mais').click();
  await p.waitForTimeout(300);
  const fe = await estado();
  ok(!fe.aberta && fe.alt === cheio.alt, 'voltar a clicar fecha e devolve a altura de antes');

  // a lista é redesenhada por inteiro a cada ação: a abertura tem de sobreviver
  await p.locator(alvo + ' .membro-chip.mais').click();
  await p.waitForTimeout(300);
  await p.evaluate(() => renderConvites());
  await p.waitForTimeout(200);
  ok((await estado()).aberta, 'a lista aberta não se fecha sozinha ao redesenhar');

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
