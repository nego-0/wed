// A página de entrada do convite digital, e o menu "⋯" da lista de convidados
// a abrir para cima quando não cabe para baixo.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 760 } })).newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  // ---------- 1. o menu "Convite digital" não abre o editor ----------
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  const href = await p.locator('.topo .nav a', { hasText: 'Convite digital' }).first().getAttribute('href');
  console.log('   menu aponta para:', href);
  ok(href === 'digital.php', 'o menu "Convite digital" abre a página de entrada, não o editor');

  await p.click('.topo .nav a:has-text("Convite digital")');
  await p.waitForLoadState('networkidle');
  await p.waitForTimeout(1200);
  ok(!/editor/.test(p.url()), 'e a navegação leva à página de entrada');
  ok(await p.locator('.peca-prova iframe').count() === 1, 'a entrada mostra uma prova do convite');
  ok(await p.locator('a.btn:has-text("Editar o convite")').count() === 1, 'com um botão para o editor');
  ok(await p.locator('.abas').count() === 0, 'sem abas: tudo o que a página tem está à vista');
  ok(await p.locator('#prod').count() === 1, 'a lista de convites aparece logo, sem ter de escolher aba');
  ok(await p.locator('.selo-v').count() >= 1, 'diz qual a versão em vigor (ou que não há nenhuma)');
  // o mesmo item do menu fica marcado
  ok(await p.locator('.topo .nav a.ativo:has-text("Convite digital")').count() === 1,
     'o menu marca "Convite digital" como página atual');
  await p.screenshot({ path: OUT + '/digital_entrada.png' });


  // ---------- 1b. o cartão de estado é compacto e sem vãos ----------
  await p.setViewportSize({ width: 1885, height: 950 });
  await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  const cx = await p.locator('.peca').boundingBox();
  console.log('   cartão de estado:', Math.round(cx.width) + 'x' + Math.round(cx.height));
  ok(cx.height <= 400, `o cartão de estado cabe em 400px de altura (${Math.round(cx.height)})`);
  ok(cx.width <= 1200, 'e não se estica por todo o ecrã em monitores largos');
  ok(await p.locator('.peca-vs').count() === 1, 'a terceira coluna mostra as versões guardadas');
  // Os botões ficam à esquerda: o .acoes global empurrava-os para a direita.
  const bt = await p.locator('.peca-acoes .btn').first().boundingBox();
  const info = await p.locator('.peca > div').nth(1).boundingBox();
  ok(bt.x - info.x < 40, 'os botões ficam encostados ao texto, não atirados para a direita');

  // Nenhuma largura pode fazer a página transbordar para o lado.
  for (const larg of [1885, 1280, 900, 430]) {
    await p.setViewportSize({ width: larg, height: 900 });
    await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(900);
    const t = await p.evaluate(() => ({
      transborda: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      // A tabela desliza dentro da sua caixa; o resto não pode sair da página.
      fora: [...document.querySelectorAll('main > *, .peca > *')].filter(el => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && (r.right > document.documentElement.clientWidth + 1 || r.left < -1);
      }).length,
    }));
    ok(!t.transborda && t.fora === 0, `a ${larg}px a página não transborda para o lado`);
  }
  await p.setViewportSize({ width: 1885, height: 950 });

  // ---------- 1c. o convite impresso também diz qual a versão em vigor ----------
  await p.goto(BASE + '/graficas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  ok(await p.locator('.estado-peca').count() === 1, 'a entrada do convite impresso mostra o estado da peça');
  ok(await p.locator('.estado-peca .selo-v').count() === 1, 'com o selo da versão em vigor');
  const txt = (await p.locator('.estado-peca').innerText()).replace(/\s+/g, ' ');
  console.log('   impresso:', txt.slice(0, 110));
  ok(/Em vigor|Fora de qualquer versão|Sem versões guardadas/.test(txt),
     'e diz em palavras qual é o estado');
  ok(await p.locator('.estado-peca a[href="editor-cartao.php"]').count() === 1,
     'com o caminho para o editor do cartão');
  await p.setViewportSize({ width: 1440, height: 760 });

  // ---------- 2. o menu "⋯" abre para cima quando não cabe ----------
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const linhas = await p.locator('.convite-row').count();
  console.log('   convites na lista:', linhas);
  ok(linhas > 0, 'há convites na lista para provar o menu');

  // Rola até ao fim: a última linha fica junto à borda de baixo
  await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await p.waitForTimeout(400);
  const ultimo = p.locator('.convite-row .menu-mais button').last();
  const bb = await ultimo.boundingBox();
  await ultimo.click();
  await p.waitForTimeout(300);
  const m = await p.evaluate(() => {
    const pop = document.getElementById('pop-mais');
    if (!pop) return null;
    const r = pop.getBoundingClientRect();
    return { topo: Math.round(r.top), fundo: Math.round(r.bottom), alt: Math.round(r.height),
             acima: pop.classList.contains('acima'), janela: window.innerHeight };
  });
  console.log('   menu:', JSON.stringify(m), '| botão em y=' + Math.round(bb.y));
  ok(m !== null, 'o menu abre');
  ok(m.fundo <= m.janela, `o menu cabe todo no ecrã (fundo ${m.fundo} <= ${m.janela})`);
  ok(m.topo >= 0, 'e não sai por cima');
  const itens = await p.locator('#pop-mais button').count();
  ok(itens === 5, 'as cinco ações estão todas alcançáveis');
  // a última ação (eliminar) tem de estar visível dentro do ecrã
  const ultAcao = await p.locator('#pop-mais button').last().boundingBox();
  ok(ultAcao.y + ultAcao.height <= m.janela, 'a última ação do menu não fica cortada');
  await p.screenshot({ path: OUT + '/menu_acima.png' });
  await p.keyboard.press('Escape');

  // Com espaço de sobra em baixo, continua a abrir para baixo. Precisa de uma
  // janela alta: com poucos convites, mesmo o primeiro fica perto do fundo.
  await p.setViewportSize({ width: 1440, height: 1400 });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  await p.evaluate(() => window.scrollTo(0, 0));
  await p.waitForTimeout(400);
  const primeiro = p.locator('.convite-row .menu-mais button').first();
  const bb2 = await primeiro.boundingBox();
  await primeiro.click();
  await p.waitForTimeout(300);
  const m2 = await p.evaluate(() => {
    const pop = document.getElementById('pop-mais');
    return pop ? { topo: Math.round(pop.getBoundingClientRect().top),
                   acima: pop.classList.contains('acima') } : null;
  });
  console.log('   no topo:', JSON.stringify(m2), '| botão em y=' + Math.round(bb2.y));
  ok(m2 && !m2.acima && m2.topo > bb2.y, 'havendo espaço, o menu abre para baixo como antes');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
