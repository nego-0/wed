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

  // ---------- a planta das mesas: gestos, não botões ----------
  // Aqui quase nada se faz por botões — arrasta-se. Um gesto não tem botão que
  // se apague, por isso o que se prova é que ele não chega a acontecer.
  await sup.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await sup.waitForTimeout(1600);

  const mesa = sup.locator('.mesa-node').first();
  const antesPos = await mesa.evaluate(e => e.style.left + '|' + e.style.top);
  const cx = await mesa.boundingBox();
  await sup.mouse.move(cx.x + cx.width / 2, cx.y + cx.height / 2);
  await sup.mouse.down();
  await sup.mouse.move(cx.x + cx.width / 2 + 120, cx.y + cx.height / 2 + 70, { steps: 12 });
  await sup.mouse.up();
  await sup.waitForTimeout(800);
  const depoisPos = await mesa.evaluate(e => e.style.left + '|' + e.style.top);
  console.log('   mesa antes/depois do arrasto:', antesPos, '→', depoisPos);
  ok(antesPos === depoisPos, 'arrastar uma mesa não a move');

  // E o servidor não recebeu posição nenhuma.
  const posGuardada = await admin._api('mesa_list');
  ok(!!posGuardada.success, 'a planta continua a ler-se');

  ok(await sup.evaluate(() => document.body.classList.contains('bloq-mesas')),
     'a planta apresenta-se fixa, como quando o casal tranca as mesas');
  ok(/leitura/i.test(await sup.locator('#dica-planta').innerText()),
     'e a nota da planta explica que é uma visita de leitura');

  // As caixas do bloqueio mostram o que o CASAL configurou, não a nossa trava:
  // quem vem ajudar não pode ficar com uma leitura errada da planta alheia.
  const caixas = await sup.evaluate(() => ({
    mesas: document.getElementById('bloq-mesas').checked,
    apagada: document.getElementById('bloq-mesas').classList.contains('so-ver-off'),
  }));
  console.log('   caixa de bloquear mesas:', JSON.stringify(caixas));
  ok(caixas.mesas === false, 'a caixa "mesas fixas" continua a mostrar a escolha do casal');
  ok(caixas.apagada, 'mas está apagada — mudá-la seria escrever');

  // Largar uma pastilha numa mesa: o gesto confirma-se e é travado com aviso.
  const chip = sup.locator('.chip-drag').first();
  if (await chip.count()) {
    const cb = await chip.boundingBox(), mb = await mesa.boundingBox();
    await sup.mouse.move(cb.x + cb.width / 2, cb.y + cb.height / 2);
    await sup.mouse.down();
    await sup.mouse.move(mb.x + mb.width / 2, mb.y + mb.height / 2, { steps: 14 });
    await sup.mouse.up();
    await sup.waitForTimeout(700);
    ok(await sup.evaluate(() => !document.querySelector('.ghost-drag')),
       'largar uma pastilha numa mesa não chega a arrastar nada');
  }

  // ---------- controlo negativo: com permissão, o mesmo gesto funciona ----------
  // Sem isto, esta prova passaria na mesma se eu tivesse partido o arrastar
  // para toda a gente. É o mesmo gesto, na mesma mesa, com o outro código.
  const cod2 = await admin._api('suporte_codigo_criar', { pode_corrigir: true, dias: 1 });
  await sup._api('suporte_entrar', { codigo: cod2.codigo });
  await sup.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await sup.waitForTimeout(1600);
  const m2 = sup.locator('.mesa-node').first();
  const posA = await m2.evaluate(e => e.style.left + '|' + e.style.top);
  const bb = await m2.boundingBox();
  await sup.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2);
  await sup.mouse.down();
  await sup.mouse.move(bb.x + bb.width / 2 + 110, bb.y + bb.height / 2 + 60, { steps: 12 });
  await sup.mouse.up();
  await sup.waitForTimeout(900);
  const posB = await m2.evaluate(e => e.style.left + '|' + e.style.top);
  console.log('   com permissão de correção:', posA, '→', posB);
  ok(posA !== posB, 'com um código de correção, arrastar a mesa move-a mesmo');
  ok(!(await sup.evaluate(() => document.body.classList.contains('bloq-mesas'))),
     'e a planta não se apresenta fixa');
  // Repõe-se onde estava, para a prova não deixar a planta mexida.
  const [lx, ly] = posA.replace(/%/g, '').split('|').map(Number);
  const idMesa = await m2.evaluate(e => +e.dataset.id);
  await sup.evaluate(({ id, x, y }) => window.api('mesa_pos', { method: 'POST',
    body: JSON.stringify({ id, x, y }) }), { id: idMesa, x: lx, y: ly });
  await admin._api('suporte_codigo_revogar&id=' + cod2.id);

  // Volta-se ao código de leitura para o resto da prova — revogar o de correção
  // fechou a visita, e sem visita não há modo de leitura nenhum a verificar.
  await sup.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const revolta = await sup._api('suporte_entrar', { codigo: cod.codigo });
  ok(revolta && revolta.success, 'o código de leitura, esse, continua a servir');
  await sup.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await sup.waitForTimeout(900);

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
