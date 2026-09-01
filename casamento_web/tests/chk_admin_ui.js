// O painel da administração e a gestão de modelos, do lado de quem os usa.
//
// Três coisas que se provam olhando: o que se faz todos os dias tem de estar
// à mão (e não debaixo de um formulário que se usa uma vez por mês), o que
// estraga não pode estar ao mesmo peso do que não estraga, e uma página sobre
// DESENHOS tem de mostrar os desenhos.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  // ============ 1. o painel da administração ============
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  // A página abre nos casamentos, e criar/contas são pastilhas à parte — o
  // trabalho de todos os dias não fica soterrado por formulários de uso raro.
  const chips = await p.evaluate(() =>
    [...document.querySelectorAll('#vista-chips .chip')].map(c => c.dataset.vista));
  ok(chips.includes('casamentos') && chips.includes('novo') && chips.includes('contas'),
     'a barra tem as pastilhas Casamentos, Novo casamento e Contas administrativas');
  ok(await p.locator('#vista-casamentos').isVisible()
     && !(await p.locator('#vista-novo').isVisible()),
     'abre nos casamentos, com o novo formulário guardado noutra pastilha');

  // O trabalho de todos os dias tem de estar acima da dobra.
  const alturas = await p.evaluate(() => ({
    lista: document.getElementById('lista-casamentos').getBoundingClientRect().top + scrollY,
    pagina: document.body.scrollHeight
  }));
  console.log('   lista a', Math.round(alturas.lista), 'px · página', alturas.pagina, 'px');
  ok(alturas.lista < 700, `a lista de casamentos aparece sem rolar muito (${Math.round(alturas.lista)}px)`);

  // A pastilha "Novo casamento" revela o formulário; voltar a "Casamentos" esconde-o.
  await p.click('#vista-chips [data-vista="novo"]'); await p.waitForTimeout(250);
  ok(await p.locator('#vista-novo').isVisible() && await p.locator('#n-nome').isVisible(),
     'a pastilha "Novo casamento" mostra o formulário de criar');
  await p.click('#vista-chips [data-vista="contas"]'); await p.waitForTimeout(200);
  ok(await p.locator('#vista-contas').isVisible()
     && await p.evaluate(() => { const d = document.getElementById('d-conta'); return d && !d.open; }),
     'a pastilha "Contas administrativas" abre com a "Nova conta" dobrada');
  await p.click('#vista-chips [data-vista="casamentos"]'); await p.waitForTimeout(200);

  // A linha do casamento diz quando é, quanto falta e quantos confirmaram.
  const linha = await p.evaluate(() => {
    const r = document.querySelector('#lista-casamentos .cas');
    return r ? { txt: r.innerText.replace(/\s+/g, ' '),
                 barra: !!r.querySelector('.barra i'),
                 principais: [...r.querySelectorAll('.ac > .btn')].map(x => x.textContent.trim()),
                 escondidas: [...r.querySelectorAll('.mm-pop button')].map(x => x.textContent.trim()) } : null;
  });
  console.log('   linha:', JSON.stringify(linha));
  ok(linha && /\d{2}\/\d{2}\/\d{4}|\d{4}|\w{3}\./.test(linha.txt), 'a linha traz a data do casamento');
  ok(linha && /falta|é hoje|é amanhã|há \d+ dias|sem data/.test(linha.txt),
     'e diz quanto falta, em vez de obrigar a contar');
  ok(linha && /confirmaram/.test(linha.txt), 'e quantos já disseram que vêm');
  ok(linha && linha.barra, 'com a barra de confirmações a dar a proporção de relance');
  ok(linha && linha.principais.length === 1 && /Abrir|Continuar/.test(linha.principais[0]),
     `só a ação principal está à vista (${(linha && linha.principais).join(', ')})`);
  // O «⋯» guarda o que não é do dia-a-dia. Fechar a casa só lá está quando
  // PODE lá estar: um casamento com licença em vigor não se suspende nem
  // arquiva — decide-se a licença primeiro (ver chk_lic_travao.js). Nesse caso
  // fica no lugar uma nota a dizer porquê, que é o que a prova aqui procura.
  const fecharAtras = linha && linha.escondidas.some(x => /Arquivar|Suspender/.test(x));
  const notaTrava   = await p.evaluate(() =>
    !!document.querySelector('#lista-casamentos .cas .mm-pop .mm-nota'));
  ok(fecharAtras || notaTrava,
     'e o que fecha a casa está atrás do "⋯" — ou explicado, quando a licença o trava');
  ok(linha && linha.escondidas.some(x => /Editar todos os dados/.test(x)),
     'com as ações menos usadas todas lá dentro');

  // O botão dos três pontos leva um SVG centrado, e não o glifo "⋯" — esse
  // desenhava-se caído para o fundo do botão redondo, seja qual for o tipo de
  // letra. Confirma-se que é um ícone e que o seu centro coincide com o do botão.
  const ico = await p.evaluate(() => {
    const btn = document.querySelector('#lista-casamentos .mm > button'); if (!btn) return null;
    const svg = btn.querySelector('svg.ico-mais'); if (!svg) return { semSvg: true, txt: btn.textContent.trim() };
    const bb = btn.getBoundingClientRect(), ib = svg.getBoundingClientRect();
    return { pontos: svg.querySelectorAll('circle').length,
             desvioV: Math.round((ib.top + ib.height / 2) - (bb.top + bb.height / 2)),
             desvioH: Math.round((ib.left + ib.width / 2) - (bb.left + bb.width / 2)),
             glifo: /⋯/.test(btn.textContent) };
  });
  ok(ico && !ico.semSvg && ico.pontos === 3,
     'o botão "mais ações" desenha três pontos em SVG' + (ico && ico.semSvg ? ` (ainda é texto "${ico.txt}")` : ''));
  ok(ico && !ico.glifo, 'e já não usa o glifo "⋯", que ficava caído');
  ok(ico && Math.abs(ico.desvioV) <= 1 && Math.abs(ico.desvioH) <= 1,
     `os pontos ficam centrados no botão (desvio ${ico && ico.desvioH}/${ico && ico.desvioV}px)`);

  // O "⋯" abre, e fecha-se ao clicar fora.
  await p.click('#lista-casamentos .mm > button'); await p.waitForTimeout(200);
  ok(await p.evaluate(() => [...document.querySelectorAll('.mm-pop')].some(x => x.style.display !== 'none')),
     'o "⋯" abre as outras ações');
  await p.click('main'); await p.waitForTimeout(200);
  ok(await p.evaluate(() => [...document.querySelectorAll('.mm-pop')].every(x => x.style.display === 'none')),
     'e fecha-se ao clicar fora');

  // Os números levam a algum lado — e levam mesmo.
  ok(await p.locator('.numeros button.n').count() >= 1, 'os números que levam a algum lado são botões');
  await p.click('.numeros button.n'); await p.waitForTimeout(700);
  ok(await p.evaluate(() =>
      document.querySelector('#filtros-cas .chip.on').dataset.estado === 'ativo'),
     'clicar em "casamentos ativos" põe o filtro nos ativos');

  await p.screenshot({ path: OUT + '/admin-painel.png', fullPage: true });

  // ============ 2. os modelos ============
  const antes = await api('modelo_lista');
  const marca = 'zz' + String(Date.now()).slice(-6);
  const mD = await api('modelo_criar', { nome: 'ZZ Digital ' + marca, descricao: 'prova',
                                         ambito: 'digital', visivel: true });
  const mI = await api('modelo_criar', { nome: 'ZZ Impresso ' + marca, descricao: 'prova',
                                         ambito: 'impresso', visivel: false });
  ok(mD.success && mI.success, 'criam-se dois modelos de prova');

  await p.goto(BASE + '/modelos.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3500);
  ok(await p.evaluate(() =>
       [...document.querySelectorAll('#filtros .chip')].some(c => c.dataset.vista === 'novo')),
     '"Novo modelo" é uma pastilha na barra dos modelos');

  // Nos dados de exemplo: escolher uma foto da galeria tem de funcionar (a
  // função chegou a desaparecer), e tocar numa imagem abre-a em grande.
  await p.click('#filtros [data-vista="exemplo"]'); await p.waitForTimeout(1500);
  ok(await p.evaluate(() => typeof escolherDaGaleria === 'function' && typeof maximizar === 'function'),
     'as funções de escolher da galeria e de maximizar existem');
  const nImg = await p.locator('#ex-corpo .ex .moldura img').count();
  ok(nImg >= 1, 'os dados de exemplo mostram as imagens das secções (' + nImg + ')');
  await p.locator('#ex-corpo .ex .moldura img').first().click({ force: true }); await p.waitForTimeout(250);
  ok(await p.locator('#lightbox.on').count() === 1, 'tocar numa imagem abre-a em grande');
  await p.locator('#lightbox').click(); await p.waitForTimeout(150);
  await p.locator('#ex-corpo .ex .btn-gal').first().click(); await p.waitForTimeout(600);
  ok(await p.locator('.gal .gal-i .gal-esc').count() >= 1, 'a galeria abre com fotografias para escolher');
  await p.locator('.gal .gal-i .gal-esc').first().click(); await p.waitForTimeout(700);
  ok(await p.locator('#ov-modelo.aberto').count() === 0,
     'escolher uma fotografia aplica-a e fecha a galeria');
  // Escolher aqui uma imagem da galeria muda os dados de exemplo, que são
  // globais (linha 0). Repõe-se o de fábrica para não deixar essa escolha a
  // pesar noutras provas — a de exemplo passava a ser a de origem, e um modelo
  // criado a seguir nascia com ela.
  const exFab = (await api('modelo_exemplo')).fabrica || {};
  if (Object.keys(exFab).length) await api('modelo_exemplo_guardar', exFab);
  await p.click('#filtros [data-vista="digital"][data-ambito=""]'); await p.waitForTimeout(3000);
  ok(await p.locator('#lista .grelha .mod').count() >= 2, 'os modelos vêm numa grelha de cartões');

  const caras = await p.evaluate(() => [...document.querySelectorAll('#lista .mod')].map(m => ({
    nome: m.querySelector('.nm').textContent.trim(),
    src: m.querySelector('.cara iframe')?.getAttribute('src') || '',
    // A escala é calculada da largura real da moldura, não fixa.
    escala: m.querySelector('.cara iframe')?.style.getPropertyValue('--e') || '',
    porPublicar: m.classList.contains('por-publicar'),
    principais: [...m.querySelectorAll('.ac > .btn, .ac > a.btn')].map(x => x.textContent.trim())
  })));
  console.log('   caras:', JSON.stringify(caras.filter(c => /ZZ /.test(c.nome))));
  const cD = caras.find(c => c.nome.startsWith('ZZ Digital'));
  const cI = caras.find(c => c.nome.startsWith('ZZ Impresso'));
  ok(cD && /convite-digital\.php.*modelo=/.test(cD.src),
     'o modelo digital mostra a cara desenhada pelo próprio convite');
  ok(cI && /modelo-prova\.php\?modelo=/.test(cI.src),
     'e o impresso mostra o cartão desenhado a sério');
  ok(cD && parseFloat(cD.escala) > 0 && parseFloat(cD.escala) < 1,
     `a prova é encolhida para caber na moldura (escala ${cD && cD.escala})`);
  ok(cI && cI.porPublicar && cD && !cD.porPublicar,
     'um modelo por publicar distingue-se à vista do que já está publicado');
  ok(cD && cD.principais.length === 2 && /Desenhar/.test(cD.principais[0]),
     `as ações de todos os dias cabem numa linha (${(cD && cD.principais).join(', ')})`);

  // A prova do cartão é mesmo o desenho DAQUELE modelo, e não do casamento aberto.
  await api('modelo_defs&id=' + mI.id, { defs: { 'cartao.paleta': 'rosa' } });
  const r = await p.evaluate(async id => {
    const t = await (await fetch('modelo-prova.php?modelo=' + id)).text();
    return { ok: t.indexOf('class="cartao"') > 0, rosa: t.indexOf('#a67480') > 0 };
  }, mI.id);
  ok(r.ok, 'modelo-prova.php desenha mesmo o cartão');
  ok(r.rosa, 'e com a paleta daquele modelo, não com a do casamento aberto');

  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(3000);
  await p.screenshot({ path: OUT + '/admin-modelos.png', fullPage: true });

  // ---- estado vazio: já não manda abrir um casamento à toa ----
  const vazio = await p.evaluate(() => {
    const el = document.getElementById('lista');
    const antes = el.innerHTML;
    el.innerHTML = '';
    return { antes };
  });
  void vazio;
  await api('modelo_apagar&id=' + mD.id);
  await api('modelo_apagar&id=' + mI.id);
  if (!(antes.modelos || []).length) {
    await p.goto(BASE + '/modelos.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1200);
    const txt = await p.locator('#lista').innerText();
    ok(/Novo modelo/.test(txt) && !/casamento aberto/.test(txt),
       'sem modelos, a página diz onde se faz o primeiro — e não manda abrir um casamento à toa');
  } else {
    console.log('   (havia modelos a sério; o estado vazio não se provou)');
  }

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
