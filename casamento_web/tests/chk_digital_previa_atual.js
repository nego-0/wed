// A entrada do Convite digital identifica a peça em vigor e mostra uma
// miniatura dela. Esta prova impede que a miniatura volte a usar o casamento
// ou o modelo da demonstração comercial.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const USER = process.env.TEST_USER || 'admin';
const PASS = process.env.TEST_PASSWORD || 'noivos2026';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const page = await browser.newPage();
  let casamentoId = 0;
  let api = null;
  let falhas = 0;
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
    const criado = await api('casamento_criar', {
      nome: 'ZZ Prova convite ' + marca,
      noiva: 'Vera',
      noivo: 'Vasco',
    });
    casamentoId = +criado.id;
    await api('casamento_abrir&id=' + casamentoId, {});

    const modelos = (await api('modelo_lista&ambito=digital')).modelos || [];
    const borgonha = modelos.find(modelo => modelo.nome === 'Borgonha');
    ok(!!borgonha, 'o modelo Borgonha está disponível para a prova');
    if (!borgonha) throw new Error('Modelo Borgonha ausente');
    await api('modelo_aplicar&id=' + borgonha.id, {});

    await page.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
    const estado = (await page.locator('.estado-linha').innerText()).replace(/\s+/g, ' ');
    ok(/Borgonha/.test(estado), 'o estado da peça identifica Borgonha como modelo em vigor');

    const src = await page.locator('.peca-prova iframe').getAttribute('src');
    const abrir = await page.locator('.peca-prova .lupa').getAttribute('href');
    const convidado = await page.locator('.peca-acoes a:last-child').getAttribute('href');
    ok(/(?:\?|&)atual=1(?:&|$)/.test(src || ''),
       'a miniatura pede explicitamente a peça actual');
    ok(/(?:\?|&)atual=1(?:&|$)/.test(abrir || '')
       && /(?:\?|&)atual=1(?:&|$)/.test(convidado || ''),
       'os dois atalhos abrem a mesma peça actual');

    const convite = page.frameLocator('.peca-prova iframe');
    await convite.locator('body').waitFor();
    const texto = (await convite.locator('body').innerText()).replace(/\s+/g, ' ');
    ok(/Vera/.test(texto) && /Vasco/.test(texto),
       'a miniatura mostra os noivos do casamento aberto');
    ok(!(/Marta/.test(texto) && /Pedro/.test(texto)),
       'a miniatura não mostra o casal da demonstração comercial');

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
