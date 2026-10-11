// Os onze modelos exactos podem ser postos em vigor sem herdarem secções do
// convite Kulemba. A prova usa um casamento descartável e não toca nos dados
// do casamento de trabalho.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8926';
const modelosExactos = {
  'Jasmim Branco': 'jasmine-white',
  'Real V2 Verde': 'royal-v2-green',
  'Jardim Primaveril Azul': 'spring-garden-blue',
  'Dupla Felicidade Verde': 'double-happiness-green',
  'Porcelana Castanha': 'porcelain-brown',
  'Real Azul': 'royal-blue',
  'Porcelana V2 Verde': 'porcelain-v2-green',
  'Flor Seca Laranja': 'hoa-kho-orange',
  'Mahal Dourado': 'mahal-gold',
  'Porcelana V2 Rosa': 'porcelain-v2-pink',
  'Lótus Rosa': 'lien-hoa-pink'
};

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const api = (acao, corpo) => page.evaluate(async ({ acao, corpo }) => {
    const r = await fetch('api.php?action=' + acao, {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: corpo ? JSON.stringify(corpo) : undefined
    });
    return r.json();
  }, { acao, corpo });
  let casamento = null;
  try {
    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await page.fill('input[name=utilizador]', 'admin');
    await page.fill('input[name=senha]', 'noivos2026');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    casamento = await api('casamento_criar', {
      nome: 'ZZ Prova onze modelos ' + Date.now(), noiva: 'Marta', noivo: 'Pedro'
    });
    if (!casamento.success) throw new Error('Não criou o casamento da prova: ' + JSON.stringify(casamento));
    await api('casamento_abrir&id=' + casamento.id, {});
    const lista = (await api('modelo_lista&ambito=digital')).modelos || [];
    for (const [nome, renderer] of Object.entries(modelosExactos)) {
      const modelo = lista.find(m => m.nome === nome);
      if (!modelo) throw new Error('Modelo ausente: ' + nome);
      const aplicado = await api('modelo_aplicar&id=' + modelo.id, {});
      if (!aplicado.success) throw new Error(nome + ' não foi aplicado: ' + JSON.stringify(aplicado));
      await page.goto(BASE + '/convite-digital.php?demo=1&atual=1', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => !!window.KulembaConvite);
      await page.waitForTimeout(250);
      const estado = await page.evaluate(() => ({
        estilo: document.body.dataset.estilo || '',
        herdadas: Array.from(document.querySelectorAll(
          '[data-sec="historia"],[data-sec="interludio"],[data-sec="manual"]'
        )).filter(e => e.getClientRects().length).map(e => e.dataset.sec)
      }));
      if (estado.estilo !== renderer) {
        throw new Error(nome + ' abriu com o renderizador ' + estado.estilo + ', esperado ' + renderer);
      }
      if (estado.herdadas.length) {
        throw new Error(nome + ' herdou secções que não pertencem ao original: ' + estado.herdadas.join(', '));
      }
      console.log('PASS — ' + nome + ' em vigor, sem secções herdadas.');
      await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    }
  } finally {
    if (casamento && casamento.id) {
      try {
        await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
        await api('lic_revogar', {
          casamento: casamento.id, motivo: 'Limpeza da prova automática dos modelos exactos.'
        });
        await api('casamento_estado&id=' + casamento.id + '&estado=arquivado', {});
        await api('casamento_apagar&id=' + casamento.id, {});
      } catch (_) {}
    }
    await browser.close();
  }
})().catch(e => { console.error(e); process.exit(1); });
