// Alternativas das camadas decorativas do cartão: feitios da moldura, margem,
// tamanho dos ornamentos, folhagem na própria camada — e tudo isso a chegar às
// páginas de impressão. Ver tests/LEIA-ME.md.
const { chromium } = require('playwright-core');
const janela = require('./_janela');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  // As janelas dos editores já não são do browser: são as da casa. O
  // auto-responder faz o que o on('dialog') fazia — responde-lhes sozinho,
  // por dentro da página, para esta prova poder continuar a olhar só para
  // aquilo que veio provar. (Ver tests/_janela.js.)
  await janela.autoResponder(p, 'Prova');
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);

  // A espessura lê-se na variável: o Chrome arredonda border-width a píxeis
  // inteiros e 1.4px e 0.7px sairiam ambos como "1px".
  const mold = () => p.evaluate(() => {
    const m = document.querySelector('#escala .ct-moldura'), cs = getComputedStyle(m);
    const c = getComputedStyle(document.querySelector('#escala .cartao'));
    // Os anéis de dentro e de fora são os <i> 3 e 4 — elementos, não sombras.
    // Conta-se quantos estão mesmo visíveis e a que distância da borda ficam.
    const is = [...m.querySelectorAll('i')];
    const caixa = m.getBoundingClientRect();
    const aneis = is.slice(2)
      .filter(i => getComputedStyle(i).display !== 'none')
      .map(i => Math.round(i.getBoundingClientRect().top - caixa.top));
    return { linha: c.getPropertyValue('--ct-mold-linha').trim(),
             larg:  c.getPropertyValue('--ct-mold-larg').trim(),
             // O que interessa é a esquadria estar à vista, e não a variável
             // dizer "none": um feitio que não precise dela apaga-a, e apagada
             // vale o valor de origem da folha de estilo — que é esconder.
             cantos: getComputedStyle(is[0]).display,
             margem: c.getPropertyValue('--ct-mold-margem').trim(),
             aneis, bordaReal: cs.borderTopWidth };
  });

  // ---- moldura: as quatro alternativas ----
  await p.evaluate(() => selecionar('moldura')); await p.waitForTimeout(400);
  const txt = (await p.locator('#props').innerText()).replace(/\s+/g, ' ');
  ok(!/não tem texto para editar/.test(txt), 'a moldura deixou de dizer só "não tem texto para editar"');
  ok(await p.locator('#props select').count() === 1, 'a moldura oferece um feitio à escolha');
  // Contar barras deixou de servir: desde a tela de posicionamento livre, cada
  // camada tem também a sua barra de volta. Procura-se a que interessa.
  ok(await p.locator('#props input[type=range][oninput*="mudarMolduraMargem"]').count() === 1,
     'a moldura oferece a distância à borda');
  ok(await p.locator('#props input[type=range][oninput*="mudarAngulo"]').count() === 1,
     'e a volta, como qualquer outra camada');

  for (const [id, esperado] of [['fina','.7px'], ['simples','1.4px'], ['cantos','0'],
                                ['dupla','1.4px'], ['tripla','1.6px'],
                                ['pontilhada','2px'], ['arredondada','1.4px']]) {
    await p.selectOption('#props select', id); await p.waitForTimeout(350);
    const m = await mold();
    console.log(`  ${id.padEnd(11)} linha=${m.linha} (real ${m.bordaReal}) cantos=${m.cantos} anéis=[${m.aneis}]`);
    ok(m.larg === esperado, `feitio "${id}" põe a espessura em ${esperado}`);
    if (id === 'cantos') ok(m.cantos === 'block' && m.bordaReal === '0px', 'o feitio "só os cantos" troca a linha pelas esquadrias');
    // A "linha dupla" era uma sombra `inset ... transparent` sobre outra: uma
    // sombra transparente não apaga o que tem por baixo, e o que se via era
    // uma banda maciça. Agora cada linha é um anel seu, e vê-se o intervalo.
    if (id === 'dupla')  ok(m.aneis.length === 1 && m.aneis[0] >= 4,
                            `o feitio "linha dupla" desenha mesmo a segunda linha, afastada (${m.aneis[0]}px)`);
    if (id === 'tripla') ok(m.aneis.length === 2 && m.aneis[0] > 0 && m.aneis[1] < 0,
                            `"três linhas" põe uma por dentro e outra por fora ([${m.aneis}])`);
    if (id === 'simples') ok(m.aneis.length === 0 && m.cantos === 'none', 'o feitio simples não deixa restos dos outros');
    if (id === 'pontilhada') ok(await p.evaluate(() =>
        getComputedStyle(document.querySelector('#escala .ct-moldura')).borderTopStyle === 'dotted'),
        'a "pontilhada" é mesmo uma linha de pontos');
    if (id === 'arredondada') ok(await p.evaluate(() =>
        parseFloat(getComputedStyle(document.querySelector('#escala .ct-moldura')).borderTopLeftRadius) > 10,
        ), 'os "cantos redondos" arredondam mesmo as esquinas');
  }
  await p.selectOption('#props select', 'cantos'); await p.waitForTimeout(300);
  // Só os dois primeiros <i> são esquadrias (os outros dois são os anéis); as
  // outras duas esquadrias são o ::before e o ::after.
  ok(await p.evaluate(() => [...document.querySelectorAll('#escala .ct-moldura i')].slice(0, 2)
       .every(i => getComputedStyle(i).display === 'block')
     && ['::before','::after'].every(q =>
          getComputedStyle(document.querySelector('#escala .ct-moldura'), q).display === 'block')),
     'as quatro esquadrias aparecem');
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
      const m = c.querySelector('.ct-moldura');
      const aneis = m ? [...m.querySelectorAll('i')].slice(2)
                          .filter(i => getComputedStyle(i).display !== 'none').length : 0;
      return { margem: cs.getPropertyValue('--ct-mold-margem').trim(),
               aneis,
               vol:    cs.getPropertyValue('--ct-esc-volutas').trim() };
    });
    console.log(`  ${nome}:`, JSON.stringify(st));
    ok(st && st.margem === '36px', nome + ' respeita a margem da moldura');
    ok(st && st.vol === '1.2', nome + ' respeita o tamanho das volutas');
    ok(st && st.aneis === 1, nome + ' desenha a segunda linha da moldura dupla');
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
