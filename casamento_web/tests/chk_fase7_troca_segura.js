const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php');
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  const api = (action, body) => p.evaluate(async ({ action, body }) => {
    const r = await fetch('api.php?action=' + action, {
      method: body ? 'POST' : 'GET', headers: {
        'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json'
      }, body: body ? JSON.stringify(body) : undefined
    });
    return r.json();
  }, { action, body });

  const tag = 'Fase 7 ' + Date.now();
  const casamento = await api('casamento_criar', { nome: tag, noiva: 'Marta', noivo: 'Pedro' });
  await api('casamento_abrir&id=' + casamento.id, {});
  try {
    const modelos = (await api('modelo_lista&ambito=digital')).modelos || [];
    if (modelos.length < 2) throw new Error('São necessários dois modelos digitais para a prova.');
    const texto = 'Texto semântico conservado · ' + tag;
    let r = await api('defs_save', { defs: { 'textos.lead': texto } });
    if (!r.success) throw new Error('Não guardou conteúdo semântico: ' + JSON.stringify(r));
    r = await api('modelo_aplicar&id=' + modelos[1].id, {});
    if (!r.success) throw new Error('Não aplicou o modelo: ' + JSON.stringify(r));
    await p.goto(BASE + '/convite-digital.php?demo=1&atual=1', { waitUntil: 'networkidle' });
    if (!(await p.locator('body').innerText()).includes(texto)) throw new Error('A troca apagou o texto do casal.');

    await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    r = await api('defs_save', { defs: { 'fx.petalas': '0' }, proteger_desenho: true });
    if (!(r.success === false && r.precisa_versao)) throw new Error('O desenho da casa não foi protegido.');
    r = await api('defs_save', { defs: { 'fx.petalas': '0' }, proteger_desenho: true, versao_nome: tag });
    if (!(r.success && r.versao)) throw new Error('Não guardou a versão: ' + JSON.stringify(r));
    const versoes = (await api('versao_lista&ambito=digital')).versoes || [];
    if (!versoes.some(v => v.nome === tag)) throw new Error('A versão guardada não aparece na peça.');
    console.log('OK — conteúdo preservado e versão autónoma guardada.');
  } finally {
    await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    await api('casamento_estado&id=' + casamento.id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + casamento.id, {});
    await b.close();
  }
})().catch(e => { console.error(e); process.exit(1); });
