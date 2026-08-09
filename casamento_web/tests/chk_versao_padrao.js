// A versão padrão ("Original") — a peça como o sistema a traz de origem.
// Existe sempre, aparece no seletor, aplica-se para voltar atrás, e não se
// apaga, não se renomeia nem se reescreve: quem a edita guarda com outro nome.
// As versões guardadas aparecem no seletor com o nome que lhes deram.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1500, height: 950 } })).newPage();
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

  const limpar = async (amb) => {
    const d = await api('versao_lista&ambito=' + amb);
    for (const v of (d.versoes || [])) if (!v.padrao) await api('versao_apagar&id=' + v.id, {});
  };
  await limpar('digital'); await limpar('impresso');
  await api('versao_aplicar&id=0&ambito=digital');

  // ---------- a padrão existe sempre ----------
  let d = await api('versao_lista&ambito=digital');
  const padrao = (d.versoes || []).filter(v => v.padrao)[0];
  ok(!!padrao, 'a versão padrão aparece na lista mesmo sem nada guardado');
  ok(padrao && padrao.nome === 'Original', 'chama-se "Original"');
  ok(padrao && padrao.em_vigor === true, 'com a peça de origem, é ela que está em vigor');
  ok(d.alguma_em_vigor === true, 'a peça nunca fica "fora de qualquer versão"');

  // ---------- não se apaga, não se renomeia, não se reescreve ----------
  for (const [accao, corpo, rot] of [
    ['versao_apagar&id=0&ambito=digital',    null,               'apagar'],
    ['versao_atualizar&id=0&ambito=digital', null,               'reescrever'],
    ['versao_renomear&id=0&ambito=digital',  { nome: 'Outro' },  'renomear']]) {
    const r = await api(accao, corpo);
    ok(r && r.success === false, 'o servidor recusa ' + rot + ' a versão padrão');
    if (r && r.message) console.log('   →', rot + ':', r.message);
  }
  d = await api('versao_lista&ambito=digital');
  ok((d.versoes || []).some(v => v.padrao && v.nome === 'Original'),
     'e a padrão continua lá, intacta, depois das tentativas');

  // ---------- o seletor, em cada estado ----------
  const opcoes = async () => {
    await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1700);
    return p.evaluate(() => [...document.querySelectorAll('#sel-versao option')]
      .map(o => ({ txt: o.textContent.trim(), sel: o.selected, val: o.value })));
  };
  const escolhida = o => (o.filter(x => x.sel)[0] || {}).txt || '';
  const temGerir  = o => o.some(x => /^(Mudar o nome|Atualizar|Apagar)/.test(x.txt));

  let o = await opcoes();
  ok(/^Original/.test(escolhida(o)) && /em vigor/.test(escolhida(o)),
     'o seletor mostra "Original — em vigor"');
  ok(!temGerir(o), 'no Original não se oferece renomear, atualizar nem apagar');

  // Editar a partir do Original: a única saída é guardar com outro nome
  await api('defs_save', { defs: { 'textos.kicker': 'SAIU DO ORIGINAL' } });
  o = await opcoes();
  console.log('   editado a partir do Original:', JSON.stringify(o.map(x => x.txt)));
  ok(/Alterado/.test(escolhida(o)) && /Original/.test(escolhida(o)),
     'diz que a peça foi alterada a partir do Original');
  ok(!temGerir(o), 'editar o Original não oferece reescrever nada — só guardar como nova');
  ok(o.some(x => /Guardar como nova/.test(x.txt)), 'oferece guardar como versão nova');

  // ---------- guardar com um nome: é esse nome que aparece ----------
  await api('versao_criar&ambito=digital', { nome: 'Clássica dourada' });
  o = await opcoes();
  console.log('   depois de guardar:', JSON.stringify(o.map(x => x.txt)));
  ok(/^Clássica dourada/.test(escolhida(o)) && /em vigor/.test(escolhida(o)),
     'a versão guardada aparece em vigor, com o nome escolhido');
  ok(o.some(x => /^Original/.test(x.txt)), 'o Original continua na lista');
  ok(temGerir(o), 'numa versão guardada já se pode renomear, atualizar e apagar');
  ok(o.filter(x => /Clássica dourada/.test(x.txt)).length >= 2,
     'as ações de gerir apontam à versão guardada, pelo nome');

  // ---------- voltar ao Original repõe mesmo a peça ----------
  // Lê-se do que o servidor entrega. Tem de ser o objeto ATUAIS: a página traz
  // também o PADRAO, e apanhar a primeira ocorrência da chave dava o valor de
  // origem — que é justamente o que este teste quer distinguir.
  const kicker = () => p.evaluate(async () => {
    const t = (await (await fetch('convite-editor.php')).text()).replace(/&quot;/g, '"');
    const m = t.match(/const ATUAIS\s*=\s*(\{.*?\});/s);
    if (!m) return null;
    try { return JSON.parse(m[1])['textos.kicker']; } catch (e) { return null; }
  });
  await api('defs_save', { defs: { 'textos.kicker': 'ALGO BEM DIFERENTE' } });
  ok(await kicker() === 'ALGO BEM DIFERENTE', 'a peça foi mesmo alterada');
  const ap = await api('versao_aplicar&id=0&ambito=digital');
  ok(ap && ap.success === true, 'aplicar o Original responde bem');
  const dep = await kicker();
  console.log('   kicker depois de voltar ao Original:', JSON.stringify(dep));
  ok(dep !== 'ALGO BEM DIFERENTE', 'voltar ao Original repõe a peça de origem');
  d = await api('versao_lista&ambito=digital');
  ok((d.versoes || []).filter(v => v.padrao)[0].em_vigor === true,
     'e o Original volta a constar como em vigor');
  ok((d.versoes || []).some(v => !v.padrao && v.nome === 'Clássica dourada'),
     'voltar ao Original não apaga as versões guardadas');

  // ---------- o mesmo vale para o convite impresso ----------
  const di = await api('versao_lista&ambito=impresso');
  ok((di.versoes || []).some(v => v.padrao && v.nome === 'Original'),
     'o convite impresso também tem a sua versão padrão');
  const ri = await api('versao_apagar&id=0&ambito=impresso');
  ok(ri && ri.success === false, 'e também lá se recusa apagá-la');

  // ---------- limpeza ----------
  await limpar('digital');
  await api('versao_aplicar&id=0&ambito=digital');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
