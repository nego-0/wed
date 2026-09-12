// Uma versão tem de fotografar o convite TAL COMO ESTÁ no ecrã, mesmo com
// alterações por gravar: guardar/atualizar uma versão grava-as primeiro. Sem
// isto, a versão saía sem as últimas edições — "as alterações nas versões não
// estão a ser guardadas".
const { chromium } = require('playwright-core');
const janela = require('./_janela');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });


  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  // base limpa
  let d = await api('versao_lista&ambito=digital');
  for (const v of (d.versoes || [])) await api('versao_apagar&id=' + v.id, {});

  // aceitar sempre os diálogos (prompt do nome, confirmações)
  let nomeDlg = 'Versão com edição';
  await janela.autoResponder(p, nomeDlg);

  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);

  // abrir um campo de texto e editá-lo SEM clicar em "Guardar"
  for (const el of await p.$$('#camadas [onclick], #camadas button, #camadas div')) {
    await el.click().catch(() => {}); await p.waitForTimeout(150);
    if (await p.evaluate(() => !!document.querySelector('#props input[type=text], #props textarea'))) break;
  }
  // curto de propósito: o primeiro campo (monograma do selo) só aceita 12 chars
  const marca = 'MG' + String(Date.now()).slice(-6);
  const chave = await p.evaluate((novo) => {
    const i = document.querySelector('#props input[type=text], #props textarea');
    i.value = novo; i.dispatchEvent(new Event('input', { bubbles: true }));
    i.dispatchEvent(new Event('change', { bubbles: true }));
    const dc = i.closest('[data-chave]'); return dc ? dc.getAttribute('data-chave') : (i.getAttribute('data-chave') || '');
  }, marca);
  console.log('   editou', chave, '=', marca);
  ok(/on/.test(await p.evaluate(() => document.getElementById('marca-sujo').className)),
     'o editor assinala que há alterações por gravar');

  // Guardar como nova versão — sem ter clicado no "Guardar" principal.
  // Agora é um botão dentro do painel, e não uma opção do <select>.
  await p.click('#bt-versao');
  await p.waitForSelector('[data-ac=nova]', { timeout: 9000 });
  await p.click('[data-ac=nova]');
  await p.waitForFunction(() =>
    !/\bon\b/.test(document.getElementById('marca-sujo').className), null, { timeout: 9000 });
  ok(true, 'guardar a versão gravou as alterações pendentes (o aviso desapareceu)');

  d = await api('versao_lista&ambito=digital');
  const v = d.versoes[0];
  console.log('   versão:', v && v.nome, '| em_vigor:', v && v.em_vigor);
  ok(v && v.em_vigor === true,
     'a versão fica em vigor — o seu conteúdo é o que a peça mostra agora');

  // A edição ficou mesmo GRAVADA (não se perdeu): a definição serve o valor novo.
  // Lê-se do HTML servido (const ATUAIS = {...}), navegando de novo à página.
  const lerDef = async (k) => {
    await p.goto(BASE + '/convite-editor.php', { waitUntil: 'domcontentloaded' });
    // page.content() codifica as aspas do script como &quot; — descodifica-se.
    // Lê-se do objeto ATUAIS: a página traz também o PADRAO, e procurar a chave
    // à solta podia devolver o valor de origem em vez do que está gravado.
    const html = (await p.content()).replace(/&quot;/g, '"');
    const m = html.match(/const ATUAIS\s*=\s*(\{.*?\});/s);
    if (!m) return null;
    try { return JSON.parse(m[1])[k]; } catch (e) { return null; }
  };
  ok(await lerDef(chave) === marca, 'a alteração ficou gravada na definição, não só no ecrã');

  // E a versão leva mesmo a edição: muda-se a peça e aplica-se a versão — se ela
  // a tivesse fotografado sem a edição, aplicar traria o valor antigo de volta.
  await api('defs_save', { defs: { [chave]: 'OUTRO_VALOR' } });
  ok(await lerDef(chave) === 'OUTRO_VALOR', 'a peça mudou para outro valor');
  await api('versao_aplicar&id=' + v.id);
  ok(await lerDef(chave) === marca,
     'aplicar a versão repõe a edição — a versão guardou-a de facto');

  // limpeza
  d = await api('versao_lista&ambito=digital');
  for (const x of (d.versoes || [])) await api('versao_apagar&id=' + x.id, {});
  await api('defs_save', { defs: { [chave]: '' } });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
