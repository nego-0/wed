// Executar numa base de testes: a prova guarda um texto temporário no modelo
// e repõe o desenho e a escolha de origem mesmo quando uma asserção falha.
const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const pass = message => console.log('PASS: ' + message);

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  const errors = [], cspErrors = [];
  const observe = p => {
    p.on('pageerror', e => errors.push(e.message));
    p.on('console', message => {
      if (/content security policy|violates.+directive|refused to.+(?:font|style|script)/i.test(message.text())) {
        cspErrors.push(message.text());
      }
    });
  };
  ctx.on('page', observe);
  observe(page);
  page.on('dialog', d => d.accept());
  let originalDefs, originalModelId, modelId, modified = false, originChanged = false;
  const api = (action, body) => page.evaluate(async ({ action, body }) => {
    const response = await fetch('api.php?action=' + action, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) throw new Error('API ' + action + ': HTTP ' + response.status);
    return response.json();
  }, { action, body });
  const previewReady = style => page.waitForFunction(style => {
    const doc = document.getElementById('tela')?.contentDocument;
    return telaPronta && doc?.body?.dataset.estilo === style;
  }, style);
  const preview = () => page.frameLocator('#tela');
  const screenshot = async (p, name) => {
    if (!process.env.SCREENSHOTS) return;
    fs.mkdirSync(process.env.SCREENSHOTS, { recursive: true });
    await p.screenshot({ path: path.join(process.env.SCREENSHOTS, name + '.png'), fullPage: true });
  };
  try {
    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await page.fill('[name=utilizador]', process.env.TEST_USER || 'admin');
    await page.fill('[name=senha]', process.env.TEST_PASSWORD || 'noivos2026');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    const models = (await api('modelo_lista&ambito=digital')).modelos;
    assert(Array.isArray(models), 'A sessão de administrador deve permitir listar modelos');
    const model = models.find(m => m.nome === 'Kulemba Contemporâneo');
    assert(model, 'Modelo Kulemba presente');
    modelId = model.id;
    originalModelId = models.find(m => m.de_origem)?.id || 0;
    const exported = await api('modelos_exportar');
    originalDefs = exported.modelos.find(m => m.nome === model.nome && m.ambito === 'digital').defs;
    assert.equal(originalDefs['digital.estilo'], 'kulemba');

    await page.goto(BASE + '/convite-editor.php?modelo=' + modelId, { waitUntil: 'networkidle' });
    await previewReady('kulemba');
    assert((await page.title()).startsWith('Modelo · '));
    await page.evaluate(() => { renderEfeitos(); renderMedia(); });
    assert.match(await page.locator('#efeitos').innerText(), /Pontos de luz suaves/);
    const aspects = await page.locator('.enq').evaluateAll(items => items.map(e => ({
      id: e.dataset.foto, aspect: getComputedStyle(e.querySelector('.enq-caixa')).aspectRatio,
    })));
    assert.equal(aspects.length, 4);
    for (const item of aspects) {
      const [w, h] = item.aspect.split('/').map(Number);
      assert.equal(w / h, item.id === 'hero' ? 4 / 5 : 3 / 2, 'Enquadramento ' + item.id);
    }
    pass('editor abre o modelo, mostra luzes e usa as quatro proporções correctas');

    await page.evaluate(() => mudarEstiloDigital('classico'));
    await previewReady('classico');
    assert.match(await page.locator('#efeitos').innerText(), /Pétalas a cair/);
    await page.evaluate(() => mudarEstiloDigital('kulemba'));
    await previewReady('kulemba');
    assert.match(await page.locator('#efeitos').innerText(), /Pontos de luz suaves/);
    const upright = await preview().locator('.mcell svg,.t-item .node svg').evaluateAll(items =>
      items.length > 0 && items.every(e => {
        const style = getComputedStyle(e);
        const transform = style.transform;
        return transform === 'none' || (new DOMMatrix(transform).b === 0 && new DOMMatrix(transform).c === 0);
      }));
    assert(upright, 'Ícones compostos devem continuar direitos');
    assert.equal(await preview().locator('.mcell svg > g').first().evaluate(e => getComputedStyle(e).transform), 'none');
    pass('mudança de linguagem actualiza a prévia e mantém os ícones direitos');

    const frameSetting = await page.evaluate(() => EST.val['cer.moldura']);
    await page.evaluate(() => { EST.val['cer.moldura'] = '0'; recarregarTela(); });
    await page.waitForFunction(() => telaPronta &&
      document.getElementById('tela')?.contentDocument?.querySelector('.cer-card') &&
      !document.getElementById('tela').contentDocument.querySelector('.cer-card .cf')).catch(async error => {
        console.error('Estado da prévia sem molduras:', await page.evaluate(() => ({
          ready: telaPronta, value: EST.val['cer.moldura'], draft: JSON.parse(document.getElementById('rascunho').value)['cer.moldura'],
          frames: Array.from(document.querySelectorAll('iframe.tela')).map(f => ({
            id: f.id, body: f.contentDocument?.body?.dataset.estilo,
            cards: f.contentDocument?.querySelectorAll('.cer-card').length,
            borders: f.contentDocument?.querySelectorAll('.cer-card .cf').length,
          })),
        })));
        throw error;
      });
    const unframed = await preview().locator('.cer-card').evaluateAll(items => items.map(e => {
      const style = getComputedStyle(e), before = getComputedStyle(e, '::before'), after = getComputedStyle(e, '::after');
      return {
        borders: [style.borderTopWidth, style.borderRightWidth, style.borderBottomWidth, style.borderLeftWidth],
        shadow: style.boxShadow, background: style.backgroundImage,
        before: before.content, after: after.content,
      };
    }));
    assert(unframed.length > 0);
    for (const style of unframed) {
      assert(style.borders.every(v => v === '0px'), JSON.stringify(style));
      assert.equal(style.shadow, 'none');
      assert.equal(style.background, 'none');
      assert(['none', 'normal'].includes(style.before));
      assert(['none', 'normal'].includes(style.after));
    }
    await page.evaluate(value => { EST.val['cer.moldura'] = value; recarregarTela(); }, frameSetting);
    await previewReady('kulemba');
    pass('desligar molduras retira bordas, sombras e pseudo-elementos');

    const beforeSave = await page.evaluate(() => serializarTudo());
    const marker = 'Um dia para celebrar juntos · prova ' + Date.now();
    modified = true;
    const saved = await page.evaluate(async marker => {
      EST.val['textos.hero_sub'] = marker;
      marcarSujo(true);
      return guardar();
    }, marker);
    assert.equal(saved, true, 'Guardar modelo');
    await page.reload({ waitUntil: 'networkidle' });
    await previewReady('kulemba');
    const afterSave = await page.evaluate(() => serializarTudo());
    assert.equal(afterSave['textos.hero_sub'], marker);
    for (const key of ['digital.estilo', 'cer.moldura', 'cer.ramos', 'cer.tamanho', 'capa.selo',
      'capa.abertura', 'tipo.serif', 'tipo.script', 'tipo.sans', 'fx.petalas', 'foto.hero',
      'foto.historia', 'foto.interludio', 'foto.acesso', 'manual.itens', 'cronograma.itens']) {
      assert.equal(afterSave[key], beforeSave[key], 'Guardar deve preservar ' + key);
    }
    pass('texto guardado e recarregado com estilo, ícones, efeitos e enquadramentos preservados');

    // As faces opcionais chegam pelos placeholders do modelo, depois das
    // fontes de base. Ambas têm de ser embebidas para o iframe com CSP.
    const savedExport = await api('modelos_exportar');
    const savedDefs = savedExport.modelos.find(m => m.nome === model.nome && m.ambito === 'digital').defs;
    assert((await api('modelo_defs&id=' + modelId, { defs: {
      ...savedDefs, 'tipo.script': 'alexbrush', 'tipo.sans': 'montserrat',
    } })).success);

    originChanged = true;
    assert((await api('modelo_pecaorigem&ambito=digital&id=' + modelId, {})).success);
    const demo = await ctx.newPage();
    await demo.goto(BASE + '/demonstracao-convite.php', { waitUntil: 'networkidle' });
    assert.equal(await demo.locator('body').getAttribute('data-estilo'), 'kulemba');
    const classicModel = models.find(m => m.nome === 'Borgonha');
    assert(classicModel, 'Modelo clássico disponível para verificar a escolha administrativa');
    assert((await api('modelo_pecaorigem&ambito=digital&id=' + classicModel.id, {})).success);
    await demo.reload({ waitUntil: 'networkidle' });
    assert.equal(await demo.locator('body').getAttribute('data-estilo'), 'classico');
    assert((await api('modelo_pecaorigem&ambito=digital&id=' + modelId, {})).success);
    await demo.reload({ waitUntil: 'networkidle' });
    assert.equal(await demo.locator('body').getAttribute('data-estilo'), 'kulemba');
    assert(!/url\(["']?assets\/convite\/fonts\/[^)]*\.woff2?/i.test(await demo.content()),
      'Todas as fontes da demonstração devem estar embebidas, incluindo as escolhidas no editor');
    const fontsLoaded = await demo.evaluate(async () => {
      const loaded = await Promise.all([
        document.fonts.load('16px "Alex Brush"'), document.fonts.load('16px "Montserrat"'),
      ]);
      return loaded.every(faces => faces.length > 0 && faces.every(face => face.status === 'loaded'));
    });
    assert(fontsLoaded, 'Fontes opcionais devem carregar na demonstração');
    pass('demonstração acompanha a escolha administrativa entre os dois modelos');
    pass('fontes de base e opcionais embebidas, com Alex Brush e Montserrat carregadas');

    for (const width of [320, 390, 1440]) {
      for (const [label, url] of [
        ['convite', '/convite-digital.php?demo=1&modelo=' + modelId],
        ['demonstracao', '/demonstracao-convite.php'],
      ]) {
        await demo.setViewportSize({ width, height: 950 });
        await demo.goto(BASE + url, { waitUntil: 'networkidle' });
        assert.equal(await demo.locator('body').getAttribute('data-estilo'), 'kulemba');
        await demo.locator('#cover').click();
        await demo.locator('#hero').scrollIntoViewIfNeeded();
        assert(await demo.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), label + ' overflow ' + width);
        assert.equal(await demo.locator('.petal').count(), 0, 'Movimento reduzido não cria partículas');
        for (const selector of ['.hero-photo', '.story-photo', '.inter-photo', '.acesso-image']) {
          const ratio = await demo.locator(selector).evaluate(e => {
            const rect = e.getBoundingClientRect();
            return rect.width / rect.height;
          });
          assert(Math.abs(ratio - (selector === '.hero-photo' ? 4 / 5 : 3 / 2)) < 0.02,
            label + ' proporção ' + selector + ': ' + ratio);
        }
        await screenshot(demo, 'kulemba-' + label + '-' + width);
      }
      pass('convite e demonstração sem transbordar a ' + width + ' px, com fotografias e movimento reduzido correctos');
    }
    assert.deepEqual(errors, [], 'Erros de JavaScript');
    assert.deepEqual(cspErrors, [], 'Mensagens de bloqueio CSP');
    pass('nenhum erro de JavaScript');
    pass('nenhum bloqueio de fontes ou outros recursos pela CSP');
  } finally {
    try {
      if (modified && originalDefs) {
        assert((await api('modelo_defs&id=' + modelId, { defs: originalDefs })).success, 'Repor desenho original');
      }
      if (originChanged) {
        assert((await api('modelo_pecaorigem&ambito=digital&id=' + originalModelId, {})).success, 'Repor modelo de origem');
      }
    } finally {
      await browser.close();
    }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
