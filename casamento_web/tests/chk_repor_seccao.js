// «Repor Secção (Nome)» no editor do convite digital.
//
// O botão diz sempre que secção repõe, e repor devolve a secção INTEIRA ao
// modelo de origem — todos os itens do seu namespace (textos, estilos,
// visibilidade), a composição (posição dos blocos) e a fotografia. A foto que o
// casal tenha enviado à mão é mesmo APAGADA do servidor.
const { chromium } = require('playwright-core');
const janela = require('./_janela');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  // As janelas dos editores já não são do browser: são as da casa. O
  // auto-responder faz o que o on('dialog') fazia — responde-lhes sozinho,
  // por dentro da página, para esta prova poder continuar a olhar só para
  // aquilo que veio provar. (Ver tests/_janela.js.)
  await janela.autoResponder(p, 'Prova');
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  const casal = await p.evaluate(async () => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: 'ZZ Repor ' + Date.now(), noiva: 'Rita', noivo: 'Rui' }) });
    return r.json();
  });
  const cid = casal.id;
  await p.evaluate(async (id) => { await fetch('api.php?action=casamento_abrir&id=' + id,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);

  // Retoques manuais na secção «Capa» (hero): texto, um estilo/visibilidade
  // vizinho, e uma FOTO enviada de verdade (para haver ficheiro a apagar).
  const foto = await p.evaluate(async () => {
    const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
    const bin = atob(b64); const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const fd = new FormData();
    fd.append('chave', 'media.hero'); fd.append('ficheiro', new File([arr], 'foto.png', { type: 'image/png' }));
    const r = await fetch('api.php?action=def_upload', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF }, body: fd });
    return r.json();
  });
  ok(foto && foto.success && /assets\/convite\/custom\//.test(foto.path || ''),
     'a foto manual entrou em ' + (foto && foto.path));
  const fotoUrl = BASE + '/' + foto.path;
  ok((await p.request.get(fotoUrl)).status() === 200, 'e o ficheiro existe no servidor');
  await p.evaluate(async () => { await fetch('api.php?action=defs_save', { method: 'POST',
    headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
    body: JSON.stringify({ defs: { 'textos.kicker': 'KICKER MANUAL', 'textos.hero_sub': 'SUB MANUAL' } }) }); });

  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  // ---------- 1. o botão diz a secção ----------
  ok((await p.textContent('#bt-repor') || '').trim() === 'Repor Secção (Envelope)',
     'no envelope, o botão diz «Repor Secção (Envelope)»');
  await p.evaluate(() => irCamada('hero')); await p.waitForTimeout(300);
  ok((await p.textContent('#bt-repor') || '').trim() === 'Repor Secção (Capa)',
     'na Capa, diz «Repor Secção (Capa)»');

  // ---------- 2. repor devolve TODOS os itens da secção à origem ----------
  const padraoHero = await p.evaluate(() => PADRAO['media.hero']);
  await p.evaluate(async () => { await reporSeccao(); });
  await p.waitForTimeout(600);
  ok(await p.evaluate(() => EST.val['media.hero']) === padraoHero, 'a foto da Capa volta à de origem');
  ok(await p.evaluate(() => EST.val['textos.kicker'] === PADRAO['textos.kicker']), 'o kicker volta ao de origem');
  ok(await p.evaluate(() => EST.val['textos.hero_sub'] === PADRAO['textos.hero_sub']), 'e o subtítulo também');

  // ---------- 3. a foto enviada à mão foi mesmo APAGADA do servidor ----------
  ok((await p.request.get(fotoUrl)).status() !== 200,
     'o ficheiro que o casal tinha enviado deixou de existir no servidor');
  const serv = await p.evaluate(async (id) => {
    const r = await fetch('api.php?action=dados_exportar&ambito=casamento&id=' + id);
    return ((await r.json()).casamentos[0].definicoes || {})['media.hero'];
  }, cid);
  ok(serv === undefined || serv === padraoHero, 'e o efetivo passa a ser a foto de origem');

  // ---------- 4. repor uma secção com listas e visibilidade ----------
  // Na História, esconde-se a secção e muda-se um texto; repor traz tudo de volta.
  await p.evaluate(async () => { await fetch('api.php?action=defs_save', { method: 'POST',
    headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
    body: JSON.stringify({ defs: { 'historia.visivel': '0', 'historia.titulo': 'TITULO MANUAL' } }) }); });
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1000);
  await p.evaluate(() => irCamada('historia')); await p.waitForTimeout(200);
  await p.evaluate(async () => { await reporSeccao(); });
  await p.waitForTimeout(400);
  ok(await p.evaluate(() => EST.val['historia.titulo'] === PADRAO['historia.titulo']),
     'na História, o título volta ao de origem');
  ok(await p.evaluate(() => EST.val['historia.visivel'] === PADRAO['historia.visivel']),
     'e a visibilidade da secção também (um item que não era campo de texto)');

  // limpeza
  await p.evaluate(async (id) => {
    await fetch('api.php?action=casamento_estado&id=' + id + '&estado=arquivado', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
    await fetch('api.php?action=casamento_apagar&id=' + id, { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, cid);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
