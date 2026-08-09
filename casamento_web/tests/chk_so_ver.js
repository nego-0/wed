// O ecrã em modo de leitura.
//
// A fechadura é do servidor e está provada em chk_contas.js. Aqui prova-se a
// outra metade: que o ecrã não convida a pessoa a trabalhar em vão.
//
//   • os controlos que escrevem aparecem apagados e não respondem;
//   • os que só mostram — filtros, procura, abrir para ver — continuam vivos;
//   • as listas desenhadas em JavaScript depois da página também são tratadas;
//   • nenhum pedido de escrita chega sequer a sair do navegador.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, u, p) => {
  const g = await ctx.newPage();
  await g.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await g.fill('input[name=utilizador]', u); await g.fill('input[name=senha]', p);
  await g.click('button[type=submit]'); await g.waitForLoadState('networkidle');
  g._api = (a, c) => g.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return g;
};
const rotulos = (p, sel) => p.$$eval(sel, els => els.map(e =>
  (e.getAttribute('onclick') || '') + ' :: ' + (e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 30)));

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const email = 'leitura.' + marca + '@exemplo.pt';
  await admin._api('utilizador_criar', { email, nome: 'Leitura', senha: 'segredo12345',
                                         papel_plataforma: 'suporte' });
  const cod = await admin._api('suporte_codigo_criar', { pode_corrigir: false, dias: 1 });

  const sup = await entrar(await b.newContext(), email, 'segredo12345');
  await sup._api('suporte_entrar', { codigo: cod.codigo });

  // ---------- o painel ----------
  await sup.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await sup.waitForTimeout(1200);
  ok(await sup.locator('.tira-suporte').isVisible(), 'a tira diz que é uma visita de leitura');

  const mortos = await rotulos(sup, '.so-ver-off');
  const vivos  = await rotulos(sup, '[data-so-ver=ok]');
  console.log('   apagados no painel:', mortos.length, '| vivos:', vivos.length);
  const ha = (lista, t) => lista.some(x => x.includes(t));

  ok(ha(mortos, 'novoConvite'), 'criar um convite aparece apagado');
  ok(ha(mortos, 'flag('),       'marcar como enviado/impresso também');
  ok(ha(mortos, 'guardarConvite'), 'e o Guardar da janela do convite');

  // O que serve para VER não pode ficar apagado: é a isso que a visita vem.
  ok(!ha(mortos, 'filtrar') && ha(vivos, 'filtrar'), 'os filtros continuam vivos');
  ok(!ha(mortos, 'editar(')  && ha(vivos, 'editar('),  'abrir um convite para o ver, também');
  ok(!ha(mortos, 'abrirMais') && ha(vivos, 'abrirMais'),
     'e o menu "…" abre — é por lá que se vê o convite e o QR');
  ok(!ha(mortos, 'abrirHistorico'), 'o histórico não é escrita nenhuma');

  // As linhas da lista nascem em JavaScript: o que lá vem também é tratado.
  ok(mortos.some(x => /flag\(\d+/.test(x)),
     'os botões das linhas, desenhadas depois da página, também ficam apagados');

  // ---------- clicar num apagado não faz nada ----------
  const antes = await admin._api('convite_list');
  const idAntes = (antes.convites || []).map(c => c.id + ':' + c.enviado).join(',');
  await sup.evaluate(() => {
    const b = [...document.querySelectorAll('.so-ver-off')].find(e => (e.getAttribute('onclick')||'').includes('flag('));
    if (b) b.click();
  });
  await sup.waitForTimeout(600);
  const depois = await admin._api('convite_list');
  ok((depois.convites || []).map(c => c.id + ':' + c.enviado).join(',') === idAntes,
     'clicar num controlo apagado não muda nada na base');

  // ---------- o editor ----------
  await sup.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await sup.waitForTimeout(1500);
  ok(await sup.locator('.tira-suporte').isVisible(), 'o editor, que tem barra própria, também avisa');
  const mortosEd = await rotulos(sup, '.so-ver-off');
  ok(mortosEd.some(x => x.includes('guardar()')), 'no editor, o Guardar está apagado');
  const vivosEd = await rotulos(sup, '[data-so-ver=ok]');
  ok(vivosEd.some(x => x.includes('aplicarTema')),
     'mas experimentar temas e vistas continua a poder fazer-se — não sai daqui');

  // ---------- a porta ----------
  const alvo = (await admin._api('convite_list')).convites[0];
  await sup.goto(BASE + '/porteiro.php', { waitUntil: 'networkidle' });
  await sup.fill('#q', alvo.codigo);
  await sup.click('.busca-manual button');
  await sup.waitForTimeout(1200);
  const mortosPorta = await rotulos(sup, '.so-ver-off');
  console.log('   apagados na porta:', JSON.stringify(mortosPorta).slice(0, 120));
  ok(mortosPorta.length > 0 && mortosPorta.every(x => /checkin|excecao/.test(x)),
     'na porta, o que dá entrada está apagado');
  const vivosPorta = await rotulos(sup, '[data-so-ver=ok]');
  ok(vivosPorta.some(x => x.includes('buscar')), 'procurar um convidado, isso pode');

  // ---------- nada de escrita sai do navegador ----------
  const pedidos = [];
  sup.on('request', r => { if (r.url().includes('api.php')) pedidos.push(r.url()); });
  await sup.evaluate(() => window.api('convite_flag&id=1&campo=enviado&valor=1', { method: 'POST' }));
  await sup.waitForTimeout(400);
  ok(!pedidos.some(u => u.includes('convite_flag')),
     'e um pedido de escrita nem chega a sair do navegador');

  // ---------- limpeza ----------
  await admin._api('suporte_codigo_revogar&id=' + cod.id);
  for (const c of (await admin._api('utilizador_lista&q=' + marca)).contas || []) {
    await admin._api('utilizador_apagar&id=' + c.id);
  }

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
