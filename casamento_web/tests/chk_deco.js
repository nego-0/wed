// Alternativas das camadas decorativas do cartão: feitios da moldura, margem,
// tamanho dos ornamentos, folhagem na própria camada — e tudo isso a chegar às
// páginas de impressão. Ver tests/LEIA-ME.md.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  p.on('dialog', d => d.accept());
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);

  // A espessura lê-se na variável: o Chrome arredonda border-width a píxeis
  // inteiros e 1.4px e 0.7px sairiam ambos como "1px".
  const mold = () => p.evaluate(() => {
    const m = document.querySelector('#escala .ct-moldura'), cs = getComputedStyle(m);
    const c = getComputedStyle(document.querySelector('#escala .cartao'));
    return { linha: c.getPropertyValue('--ct-mold-linha').trim(),
             larg:  c.getPropertyValue('--ct-mold-larg').trim(),
             cantos: c.getPropertyValue('--ct-mold-cantos').trim(),
             margem: c.getPropertyValue('--ct-mold-margem').trim(),
             sombra: cs.boxShadow, bordaReal: cs.borderTopWidth };
  });

  // ---- moldura: as quatro alternativas ----
  await p.evaluate(() => selecionar('moldura')); await p.waitForTimeout(400);
  const txt = (await p.locator('#props').innerText()).replace(/\s+/g, ' ');
  ok(!/não tem texto para editar/.test(txt), 'a moldura deixou de dizer só "não tem texto para editar"');
  ok(await p.locator('#props select').count() === 1, 'a moldura oferece um feitio à escolha');
  ok(await p.locator('#props input[type=range]').count() === 1, 'a moldura oferece a distância à borda');

  for (const [id, esperado] of [['fina','.7px'], ['simples','1.4px'], ['cantos','0'], ['dupla','1.4px']]) {
    await p.selectOption('#props select', id); await p.waitForTimeout(350);
    const m = await mold();
    console.log(`  ${id.padEnd(8)} linha=${m.linha} (real ${m.bordaReal}) cantos=${m.cantos}`);
    ok(m.larg === esperado, `feitio "${id}" põe a espessura em ${esperado}`);
    if (id === 'cantos') ok(m.cantos === 'block' && m.bordaReal === '0px', 'o feitio "só os cantos" troca a linha pelas esquadrias');
    if (id === 'dupla')  ok(/inset/.test(m.sombra), 'o feitio "linha dupla" desenha a segunda linha');
    if (id === 'simples') ok(!/inset/.test(m.sombra) && m.cantos === 'none', 'o feitio simples não deixa restos dos outros');
  }
  await p.selectOption('#props select', 'cantos'); await p.waitForTimeout(300);
  ok(await p.evaluate(() => [...document.querySelectorAll('#escala .ct-moldura i')]
       .every(i => getComputedStyle(i).display === 'block')), 'as quatro esquadrias aparecem');
  await p.selectOption('#props select', 'simples'); await p.waitForTimeout(300);

  // A moldura tem de ficar simétrica dentro do cartão — já esteve com altura
  // zero, por uma regra do editor que lhe punha position:relative.
  const geo = await p.evaluate(() => {
    const m = document.querySelector('#escala .ct-moldura').getBoundingClientRect();
    const c = document.querySelector('#escala .cartao').getBoundingClientRect();
    const z = zoom;
    return { esq: Math.round((m.left-c.left)/z), cima: Math.round((m.top-c.top)/z),
             dir: Math.round((c.right-m.right)/z), baixo: Math.round((c.bottom-m.bottom)/z) };
  });
  console.log('  margens da moldura:', JSON.stringify(geo));
  ok(geo.esq === geo.dir && geo.cima === geo.baixo && geo.esq === geo.cima,
     'a moldura fica à mesma distância dos quatro lados');
  ok(geo.cima === 28, 'e a essa distância é a que está definida (28px)');

  // ---- margem ----
  await p.evaluate(() => mudarMolduraMargem(44)); await p.waitForTimeout(350);
  ok((await mold()).margem === '44px', 'a distância à borda chega ao cartão');
  await p.evaluate(() => mudarMolduraMargem(28)); await p.waitForTimeout(300);

  // ---- ornamentos: tamanho ----
  for (const [k, sel] of [['ramos','.ct-ramo'], ['volutas','.ct-voluta'], ['floreados','.ct-floreado']]) {
    await p.evaluate(x => selecionar(x), k); await p.waitForTimeout(350);
    const antes = await p.evaluate(s => document.querySelector('#escala ' + s).getBoundingClientRect().width, sel);
    await p.evaluate(x => mudarOrnamento(x, 140), k); await p.waitForTimeout(350);
    const dep = await p.evaluate(s => document.querySelector('#escala ' + s).getBoundingClientRect().width, sel);
    ok(dep > antes * 1.2, `o tamanho de "${k}" muda o ornamento no cartão (${antes.toFixed(0)} → ${dep.toFixed(0)})`);
    await p.evaluate(x => mudarOrnamento(x, 100), k); await p.waitForTimeout(250);
  }

  // ---- folhagem dentro da camada das trepadeiras ----
  await p.evaluate(() => selecionar('ramos')); await p.waitForTimeout(350);
  ok(await p.locator('#props select').count() === 1, 'as trepadeiras escolhem a folhagem na própria camada');
  await p.selectOption('#props select', 'feto'); await p.waitForTimeout(400);
  ok(await p.evaluate(() => est.folhagem) === 'feto', 'escolher a folhagem na camada muda o estado');
  ok(await p.evaluate(() => $('folhagem').value) === 'feto', 'a barra de cima acompanha');
  await p.selectOption('#props select', 'eucalipto'); await p.waitForTimeout(300);

  // ---- camada das mesas: explicação honesta ----
  await p.evaluate(() => selecionar('mesas')); await p.waitForTimeout(350);
  const tm = (await p.locator('#props').innerText()).replace(/\s+/g, ' ');
  ok(!/decorativa/.test(tm), 'a camada das mesas deixou de ser chamada decorativa');
  ok(/Planta de Mesas/.test(tm), 'a camada das mesas diz onde se altera');

  // ---- gravar e persistir ----
  await p.evaluate(() => { selecionar('moldura'); mudarMoldura('dupla'); mudarMolduraMargem(36);
                           selecionar('volutas'); mudarOrnamento('volutas', 120); });
  await p.waitForTimeout(600);
  await p.evaluate(() => guardar()); await p.waitForTimeout(2200);
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1400);
  const d = await p.evaluate(() => est.deco);
  console.log('  gravado:', JSON.stringify(d));
  ok(d['cartao.moldura_estilo'] === 'dupla', 'o feitio da moldura fica gravado');
  ok(d['cartao.moldura_margem'] === '36', 'a margem fica gravada');
  ok(d['cartao.volutas_escala'] === '120', 'o tamanho das volutas fica gravado');
  ok((await mold()).margem === '36px', 'ao reabrir, o cartão já vem com o feitio guardado');

  // ---- chega às páginas de impressão ----
  const orig = await p.evaluate(async () => {
    const dd = await (await fetch('api.php?action=convite_list')).json();
    const c = (dd.convites || [])[0];
    if (!c) return null;
    if (c.tipo === 'fisico' || c.tipo === 'ambos') return { id:c.id, tipo:c.tipo, mudou:false };
    await fetch('api.php?action=convite_save', { method:'POST', headers:{'X-CSRF-Token':window.CSRF},
      body: JSON.stringify({ id:c.id, nome_exibicao:c.nome_exibicao, sufixo:c.sufixo, tipo:'ambos',
                             lado:c.lado, lugares:c.lugares, mesa:c.mesa_nome || '' }) });
    return { id:c.id, tipo:c.tipo, mudou:true };
  });

  for (const [url, nome] of [['/cartoes.php','a folha de cartões'], ['/manual.php?peca=cartao','o manual']]) {
    await p.goto(BASE + url, { waitUntil: 'networkidle' }); await p.waitForTimeout(700);
    const st = await p.evaluate(() => {
      const c = document.querySelector('.cartao'); if (!c) return null;
      const cs = getComputedStyle(c);
      return { margem: cs.getPropertyValue('--ct-mold-margem').trim(),
               sombra: cs.getPropertyValue('--ct-mold-sombra').trim().slice(0, 10),
               vol:    cs.getPropertyValue('--ct-esc-volutas').trim() };
    });
    console.log(`  ${nome}:`, JSON.stringify(st));
    ok(st && st.margem === '36px', nome + ' respeita a margem da moldura');
    ok(st && st.vol === '1.2', nome + ' respeita o tamanho das volutas');
    ok(st && /inset/.test(st.sombra), nome + ' desenha a moldura dupla');
  }

  // ---- limpeza ----
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
  await p.evaluate(async o => {
    await api('defs_save', { method:'POST', body: JSON.stringify({ defs: {
      'cartao.moldura_estilo':'simples', 'cartao.moldura_margem':'28', 'cartao.volutas_escala':'100' } }) });
    if (o && o.mudou) {
      const dd = await (await fetch('api.php?action=convite_list')).json();
      const c = (dd.convites || []).find(x => x.id === o.id);
      if (c) await fetch('api.php?action=convite_save', { method:'POST', headers:{'X-CSRF-Token':window.CSRF},
        body: JSON.stringify({ id:c.id, nome_exibicao:c.nome_exibicao, sufixo:c.sufixo, tipo:o.tipo,
                               lado:c.lado, lugares:c.lugares, mesa:c.mesa_nome || '' }) });
    }
  }, orig);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
