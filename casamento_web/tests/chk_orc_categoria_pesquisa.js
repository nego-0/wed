// O nome da categoria escreve-se no próprio campo da despesa. Se não existir,
// aparece apenas a paleta e a categoria nasce juntamente com a despesa. A
// mesma prova confirma os valores compactos nas fatias estreitas da barra e
// que eliminar a categoria filtrada conserva as despesas sem categoria.
const { chromium } = require('playwright-core');
const { confirmar } = require('./_janela');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const USER = process.env.TEST_USER || 'admin';
const PASS = process.env.TEST_PASSWORD || 'noivos2026';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  let casamentoId = 0, api = null, falhas = 0;
  const ok = (condicao, mensagem) => {
    console.log((condicao ? 'PASS' : 'FAIL') + ':', mensagem);
    if (!condicao) falhas++;
  };

  try {
    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await page.fill('input[name=utilizador]', USER);
    await page.fill('input[name=senha]', PASS);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');

    api = (accao, corpo) => page.evaluate(async ({ accao, corpo }) => {
      const resposta = await fetch('api.php?action=' + accao, {
        method: corpo ? 'POST' : 'GET',
        headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
        body: corpo ? JSON.stringify(corpo) : undefined,
      });
      return resposta.json();
    }, { accao, corpo });

    const marca = Date.now().toString().slice(-7);
    const nomeCategoria = 'Logística especial ' + marca;
    const criado = await api('casamento_criar', {
      nome: 'ZZ Orçamento ' + marca, noiva: 'Vera', noivo: 'Vasco', convidados: 120,
    });
    casamentoId = +criado.id;
    await api('casamento_abrir&id=' + casamentoId, {});
    await api('orc_ajuste', { total: '7500000', moeda: 'Kz' });

    await page.goto(BASE + '/orcamento.php', { waitUntil: 'networkidle' });
    await page.click('button:has-text("+ Despesa")');
    await page.fill('#md-desc', 'Transporte dos convidados');
    await page.fill('#md-valor', '1550000');
    const selectorCategoria = page.locator('#md-categoria').locator('xpath=ancestor::div[contains(@class,"lic-sel")][1]');
    await selectorCategoria.locator('.lic-sel-bt').click();
    await selectorCategoria.locator('.lic-sel-q input').fill(nomeCategoria);
    await selectorCategoria.locator('.lic-sel-novo').click();

    ok(await page.locator('#md-cat-inline').isVisible(),
       'um nome novo revela imediatamente a escolha da cor');
    ok(await selectorCategoria.isVisible(),
       'a categoria usa o mesmo selector pesquisável das mesas dos convidados');
    ok(await page.locator('#md-cat-cores').isVisible()
       && await page.locator('#md-cat-inline .cat-inline-lin').isHidden(),
       'na criação aparece apenas a paleta, sem repetir o nome e os botões antigos');
    await page.locator('#md-cat-cores [data-cor="#2E86C8"]').click();
    await page.click('#m-desp .fim .btn-ouro');
    await page.waitForFunction(nome => {
      const opcoes = [...document.querySelectorAll('#md-categoria option')];
      return opcoes.some(o => o.textContent === nome) && document.querySelector('#md-categoria-id').value;
    }, nomeCategoria);

    const estado = await api('orc_estado');
    const categoria = (estado.categorias || []).find(c => c.nome === nomeCategoria);
    const despesa = (estado.despesas || []).find(d => d.descricao === 'Transporte dos convidados');
    ok(!!categoria && String(categoria.cor).toLowerCase() === '#2e86c8',
       'a categoria é criada com a cor escolhida');
    ok(!!despesa && +despesa.categoria_id === +categoria.id,
       'a nova despesa fica ligada à categoria acabada de criar');

    await page.evaluate(() => fechar('m-desp'));
    await page.click('button:has-text("+ Despesa")');
    await selectorCategoria.locator('.lic-sel-bt').click();
    await selectorCategoria.locator('.lic-sel-q input').fill(nomeCategoria.toLocaleLowerCase('pt-PT'));
    await selectorCategoria.locator('.lic-sel-op', { hasText: nomeCategoria }).click();
    ok(await page.locator('#md-cat-inline').isHidden()
       && await page.locator('#md-cat-editar').isVisible(),
       'a pesquisa reconhece uma categoria existente sem distinguir maiúsculas');
    await page.evaluate(() => fechar('m-desp'));

    await api('orc_despesa_guardar', {
      descricao: 'Espaço e catering', valor: '5950000', estado: 'pago', categoria_id: categoria.id,
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelector('.g-prev.compacto .o-barra-valor'));
    const curto = (await page.locator('.g-prev .o-barra-valor').innerText()).trim();
    const titulo = await page.locator('.g-prev').getAttribute('title');
    ok(curto === '1.55M Kz', 'a fatia estreita mostra 1.55M Kz');
    ok(/1[ .\u00a0]550[ .\u00a0]000 Kz/.test(titulo || ''),
       'o valor completo continua disponível no título da barra');

    await page.locator('#o-chips .chip-cat', { hasText: nomeCategoria }).click();
    await page.waitForSelector('#lista-despesas .o-filtro-apagar');
    ok(await page.locator('#lista-despesas .o-filtro-apagar').isVisible(),
       'ao filtrar uma categoria aparece a opção de a eliminar');
    ok(await page.locator('#lista-pagamentos .o-filtro-apagar').count() === 0,
       'a acção aparece uma só vez, junto das despesas filtradas');

    await page.click('#lista-despesas .o-filtro-apagar');
    await confirmar(page);
    await page.waitForFunction(nome => ![...document.querySelectorAll('#o-chips .chip-cat')]
      .some(chip => chip.textContent.includes(nome)), nomeCategoria);
    const semCategoria = await api('orc_estado');
    ok(!(semCategoria.categorias || []).some(c => +c.id === +categoria.id),
       'a categoria escolhida é eliminada');
    const antigas = (semCategoria.despesas || []).filter(d =>
      d.descricao === 'Transporte dos convidados' || d.descricao === 'Espaço e catering');
    ok(antigas.length === 2 && antigas.every(d => d.categoria_id == null),
       'as despesas associadas permanecem e passam para Sem categoria');
    ok(await page.locator('#lista-despesas .o-filtro').count() === 0,
       'depois de eliminar a categoria a lista deixa o filtro extinto');
  } finally {
    if (casamentoId && api) {
      await api('casamento_estado&id=' + casamentoId + '&estado=arquivado', {}).catch(() => {});
      await api('casamento_apagar&id=' + casamentoId, {}).catch(() => {});
    }
    await browser.close();
  }

  console.log(falhas ? `\n${falhas} FALHA(S)` : '\nTUDO VERDE');
  process.exit(falhas ? 1 : 0);
})().catch(erro => { console.error('FATAL', erro); process.exit(1); });
