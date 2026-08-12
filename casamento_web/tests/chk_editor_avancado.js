// O editor mais capaz: desenhar modelos sem casa emprestada, trancar camadas,
// e o ponto focal que se cola às guias.
//
// São três coisas que só se provam mexendo: um modelo que se abre e grava sem
// casamento nenhum aberto, uma camada trancada que RESISTE ao arrasto, e um
// ponto que salta para o terço quando lá passa perto.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  // ---------- 1. desenhar um modelo sem casamento aberto ----------
  ok((await api('casamento_lista')).aberto === 0, 'o admin entra sem casamento aberto');
  const mod = await api('modelo_criar', { nome: 'ZZ Modelo do editor ' + marca,
                                          ambito: 'digital', visivel: false });
  ok(mod && mod.success, 'e cria um modelo à mesma — nasce do desenho de origem');

  await p.goto(BASE + '/convite-editor.php?modelo=' + mod.id, { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  ok((await p.title()).startsWith('Modelo · '), 'o editor abre em modo de modelo');
  ok(/desenho da casa/.test(await p.locator('.tira-modelo').innerText()),
     'e diz que não é o convite de um casal');
  ok(await p.locator('#camadas .camada').count() > 5, 'com as camadas todas de pé');
  await p.screenshot({ path: OUT + '/editor-modelo.png' });

  // Gravar escreve no MODELO, e não nas definições de casamento nenhum.
  await p.evaluate(() => {
    EST.val['textos.kicker'] = 'Escrito no modelo';
    marcarSujo(true);
  });
  const gravou = await p.evaluate(() => guardar());
  ok(gravou !== false, 'guardar no editor grava o modelo');
  const guardado = (await api('modelo_lista')).modelos.find(m => m.id == mod.id);
  ok(!!guardado, 'o modelo continua lá');
  const dep = await p.evaluate(async (id) =>
    (await fetch('api.php?action=modelos_exportar')).json(), mod.id);
  const oNosso = dep.modelos.find(m => m.nome.includes(marca));
  console.log('   no modelo:', oNosso && oNosso.defs['textos.kicker']);
  ok(oNosso && oNosso.defs['textos.kicker'] === 'Escrito no modelo',
     'e o que se escreveu ficou no modelo');

  // ---------- 2. trancar uma camada ----------
  const alvo = '#camadas .camada:not(.fixa)';
  const idAlvo = await p.locator(alvo).first().getAttribute('data-id');
  console.log('   camada a trancar:', idAlvo);
  ok(await p.locator(alvo).first().getAttribute('draggable') === 'true',
     'uma camada normal arrasta-se');

  await p.locator(`#camadas .camada[data-id="${idAlvo}"] .cadeado`).click();
  await p.waitForTimeout(300);
  const dep2 = p.locator(`#camadas .camada[data-id="${idAlvo}"]`);
  ok((await dep2.getAttribute('class')).includes('trancada'), 'o cadeado tranca-a');
  ok(await dep2.getAttribute('draggable') === 'false', 'e trancada já NÃO se arrasta');

  // Esconder também não: o olho deixa de responder.
  const antesVis = await p.evaluate((id) => EST.val[VISIVEL[id]], idAlvo);
  await p.locator(`#camadas .camada[data-id="${idAlvo}"] .olho`).click();
  await p.waitForTimeout(250);
  const depoisVis = await p.evaluate((id) => EST.val[VISIVEL[id]], idAlvo);
  ok(String(antesVis) === String(depoisVis), 'nem se esconde enquanto estiver trancada');

  // A tranca viaja com o desenho.
  ok((await p.evaluate(() => serializar()['layout.trancados'])).includes(idAlvo),
     'e a tranca fica gravada com o resto do desenho');

  await p.locator(`#camadas .camada[data-id="${idAlvo}"] .cadeado`).click();
  await p.waitForTimeout(250);
  ok(await dep2.getAttribute('draggable') === 'true', 'destrancar devolve-lhe o arrasto');

  // ---------- 3. o ponto focal cola-se às guias ----------
  // O painel das fotografias abre-se: o enquadramento vive lá dentro.
  await p.locator('h3:has-text("Fotos e música")').click();
  await p.waitForTimeout(600);
  const temEnq = await p.locator('.enq-caixa').first().isVisible().catch(() => false);
  if (temEnq) {
    const caixa = p.locator('.enq-caixa').first();
    // A coluna dos painéis rola: sem trazer o enquadramento à vista, o arrasto
    // ia parar fora da janela e não mexia nada. É o que quem edita faz.
    await caixa.scrollIntoViewIfNeeded();
    await p.waitForTimeout(250);
    const bb = await caixa.boundingBox();
    // Larga-se a 1,5% do terço: perto, mas não em cima. Sem íman ficaria assim.
    const alvoX = bb.x + bb.width * 0.348, alvoY = bb.y + bb.height * 0.50;
    await p.mouse.move(alvoX, alvoY);
    await p.mouse.down();
    await p.mouse.move(alvoX, alvoY, { steps: 3 });
    await p.mouse.up();
    await p.waitForTimeout(300);
    const foco = await p.evaluate(() => {
      const id = Object.keys(FOTOS_POR_ID)[0];
      return EST.val[FOTOS_POR_ID[id].chave];
    });
    console.log('   enquadramento depois de largar perto do terço:', foco);
    const x = parseFloat(String(foco).split(' ')[0]);
    ok(Math.abs(x - 33.3) < 0.6, 'o ponto focal colou-se ao terço (' + x + '%)');

    // Com Shift não se cola: quem quer 47% tem direito a 47%.
    const livreX = bb.x + bb.width * 0.348;
    await p.keyboard.down('Shift');
    await p.mouse.move(livreX, alvoY);
    await p.mouse.down(); await p.mouse.move(livreX, alvoY, { steps: 3 }); await p.mouse.up();
    await p.keyboard.up('Shift');
    await p.waitForTimeout(300);
    const foco2 = await p.evaluate(() => {
      const id = Object.keys(FOTOS_POR_ID)[0];
      return EST.val[FOTOS_POR_ID[id].chave];
    });
    const x2 = parseFloat(String(foco2).split(' ')[0]);
    console.log('   com Shift:', foco2);
    ok(Math.abs(x2 - 33.3) > 0.4, 'e com Shift arrasta livre, sem se colar (' + x2 + '%)');
    await p.screenshot({ path: OUT + '/editor-focal.png' });
  } else {
    console.log('   (sem fotografias enquadráveis nesta instalação)');
  }

  // ---------- o editor do cartão também abre em modo de modelo ----------
  const modC = await api('modelo_criar', { nome: 'ZZ Cartão do editor ' + marca,
                                           ambito: 'impresso', visivel: false });
  await p.goto(BASE + '/editor-cartao.php?modelo=' + modC.id, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  ok(/desenho da casa/.test(await p.locator('.tira-modelo').innerText()),
     'o editor do cartão também abre em modo de modelo');
  ok(await p.locator('.ct-cartao, .cartao, [class*=ct-]').count() > 0, 'e desenha o cartão');
  await p.screenshot({ path: OUT + '/editor-modelo-cartao.png' });

  // ---------- limpeza ----------
  for (const m of (await api('modelo_lista')).modelos) {
    if (m.nome.includes(marca)) await api('modelo_apagar&id=' + m.id);
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log('capturas em', OUT);
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
