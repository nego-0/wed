// O "(N)" de lugares foi retirado do NOME do convidado, em todo o lado.
// O sufixo escrito (ex.: "e acompanhante") mantém-se; o "(N lugares)" por
// mesa também. Prova que o nome já não ganha o número, e que os controlos
// que o governavam desapareceram das duas páginas de edição.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  // ---- um convite de 4 lugares, sem sufixo ----
  let d = await api('convite_save', {
    nome_exibicao: 'Casa Teste Numero', tipo: 'ambos', lado: 'ambos',
    lugares: 4, sufixo: '', membros: ['Ana', 'Rui'] });
  ok(d.success, 'cria um convite de teste com 4 lugares');
  const cod = d.convite && d.convite.codigo;
  const id  = d.convite && d.convite.id;

  const nomeNoConvite = async () => p.evaluate(async (c) => {
    const t = await (await fetch('convite-digital.php?c=' + c)).text();
    const m = t.match(/class="guest-name"[^>]*>([^<]*)</);
    return m ? m[1].trim() : '(não encontrado)';
  }, cod);

  let nome = await nomeNoConvite();
  console.log('   nome no convite (sem sufixo):', JSON.stringify(nome));
  ok(nome === 'Casa Teste Numero', 'o nome sai sem o "(4)" de lugares');
  ok(!/\(\d+\)/.test(nome), 'não há número nenhum entre parênteses no nome');

  // ---- o mesmo convite, agora com sufixo escrito ----
  d = await api('convite_save', {
    id: id, nome_exibicao: 'Casa Teste Numero', tipo: 'ambos', lado: 'ambos',
    lugares: 4, sufixo: 'e acompanhante', membros: ['Ana', 'Rui'] });
  ok(d.success, 'guarda o convite com um sufixo escrito');
  nome = await nomeNoConvite();
  console.log('   nome no convite (com sufixo):', JSON.stringify(nome));
  ok(nome === 'Casa Teste Numero (e acompanhante)',
     'o sufixo escrito continua a aparecer entre parênteses');

  // ---- os controlos do "(N)" desapareceram das páginas de edição ----
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok(await p.locator('#c-mostrar-numero').count() === 0,
     'o painel de convidados já não tem o interruptor "mostrar o número entre parênteses"');
  const previa = await p.evaluate(() => {
    const el = document.getElementById('c-nome'); if (!el) return '';
    el.value = 'Só Nome'; el.dispatchEvent(new Event('input'));
    return document.getElementById('previa').textContent.trim();
  });
  ok(previa === 'Só Nome', 'a pré-visualização não acrescenta número ao nome');

  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  const temToggleCartao = await p.evaluate(() =>
    document.body.innerHTML.includes('cartao.numero_no_nome'));
  ok(!temToggleCartao, 'o editor do cartão já não tem o interruptor do "(N)" no nome');

  // ---- limpeza: apaga de vez, sem passar pela reciclagem ----
  if (id) await api('convite_delete&id=' + id + '&definitivo=1', {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
