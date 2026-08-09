// A versão em vigor é a que os convidados recebem — e o manual retrata-a.
// Prova a cadeia inteira: guardar, mudar, aplicar, ver o que sai.
const { chromium } = require('playwright-core');
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

  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  // base limpa
  for (const amb of ['digital', 'impresso']) {
    const d = await api('versao_lista&ambito=' + amb);
    for (const v of (d.versoes || [])) await api('versao_apagar&id=' + v.id, {});
  }

  // ---------- convite digital ----------
  await api('defs_save', { defs: { 'textos.kicker': 'FRASE DA VERSAO A' } });
  await api('versao_criar&ambito=digital', { nome: 'A' });
  await api('defs_save', { defs: { 'textos.kicker': 'FRASE DA VERSAO B' } });
  await api('versao_criar&ambito=digital', { nome: 'B' });

  let d = await api('versao_lista&ambito=digital');
  console.log('  ', d.versoes.map(v => `${v.nome}: em_vigor=${v.em_vigor}`).join(' | '));
  const emVigor = d.versoes.filter(v => v.em_vigor);
  ok(emVigor.length === 1 && emVigor[0].nome === 'B',
     'a versão acabada de guardar é a que fica em vigor');
  ok(d.alguma_em_vigor === true, 'a lista diz que há uma versão em vigor');

  // O que o convidado recebe tem de ser o da versão em vigor
  const noConvite = async () => p.evaluate(async () => {
    const t = await (await fetch('convite-digital.php?demo=1')).text();
    const m = t.match(/FRASE DA VERSAO [AB]/);
    return m ? m[0] : '(nenhuma)';
  });
  console.log('   convite do convidado:', await noConvite());
  ok(await noConvite() === 'FRASE DA VERSAO B', 'o convite mostra o conteúdo da versão em vigor');

  // Aplicar A: passa a ser A, no painel e no convite
  const idA = d.versoes.find(v => v.nome === 'A').id;
  const r = await api('versao_aplicar&id=' + idA);
  ok(r.success === true, 'aplicar a versão A responde bem');
  console.log('   convite depois de aplicar A:', await noConvite());
  ok(await noConvite() === 'FRASE DA VERSAO A', 'aplicar uma versão muda o que o convidado recebe');

  d = await api('versao_lista&ambito=digital');
  ok(d.versoes.filter(v => v.em_vigor).length === 1 &&
     d.versoes.find(v => v.em_vigor).nome === 'A', 'o painel passa a marcar A como em vigor');
  ok(d.versoes[0].nome === 'A', 'a versão em vigor vem à cabeça da lista');

  // Editar fora de qualquer versão: nenhuma fica em vigor, e diz-se
  await api('defs_save', { defs: { 'textos.kicker': 'FRASE SEM VERSAO' } });
  d = await api('versao_lista&ambito=digital');
  console.log('   depois de editar:', d.versoes.map(v => `${v.nome}:${v.em_vigor}`).join(' '),
              '| alguma =', d.alguma_em_vigor);
  ok(d.versoes.every(v => !v.em_vigor), 'depois de editar, nenhuma versão está em vigor');
  ok(d.alguma_em_vigor === false, 'a lista assinala que a peça saiu de todas as versões');
  ok(d.versoes.find(v => v.nome === 'A').escolhida === 1,
     'mas continua a saber-se qual foi a última aplicada');

  // ---------- entrada e painel contam o MESMO estado ----------
  // A peça derivou da última aplicada (A). A entrada tem de a nomear, como o
  // painel faz — não atirar um seco "Fora de qualquer versão" que a contradiz.
  await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
  const banda = (await p.locator('.estado-linha').first().textContent()).replace(/\s+/g, ' ').trim();
  console.log('   banda da entrada:', banda);
  ok(/\bA\b/.test(banda) && /altera/i.test(banda),
     'a entrada nomeia a última versão aplicada e diz que a peça tem alterações');
  ok(!/Fora de qualquer vers/i.test(banda),
     'a entrada já não usa o aviso inconsistente "Fora de qualquer versão"');

  // ---------- o seletor da barra conta o mesmo estado ----------
  // A versão vive agora num <select> na barra de cima. Fora de versão, a opção
  // escolhida di-lo, e a última aplicada (A) aparece assinalada na lista.
  const opcoesSel = () => p.evaluate(() =>
    [...document.querySelectorAll('#sel-versao option')].map(o => o.textContent.trim()));
  const escolhidaSel = () => p.evaluate(() => {
    const s = document.getElementById('sel-versao');
    return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].textContent.trim() : '';
  });

  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  // Espera-se pelas duas versões guardadas (A e B). O seletor traz também a
  // padrão "Original", que existe sempre — daí contar pelos nomes, não pelo
  // total de opções.
  await p.waitForFunction(() => {
    const t = [...document.querySelectorAll('#sel-versao option')].map(o => o.textContent.trim());
    return t.some(x => /^A\b/.test(x)) && t.some(x => /^B\b/.test(x));
  }, null, { timeout: 9000 });
  console.log('   opções do seletor (fora de versão):', JSON.stringify(await opcoesSel()));
  ok(/Alterado/i.test(await escolhidaSel()),
     'o seletor mostra que a peça está fora das versões guardadas');
  ok((await opcoesSel()).some(o => /^A\b/.test(o) && /última aplicada/i.test(o)),
     'o seletor assinala A como a última aplicada');
  ok(!(await opcoesSel()).some(o => /em vigor/i.test(o)),
     'nenhuma versão se diz em vigor quando nenhuma está');

  // Voltar a A e confirmar que o seletor passa a mostrá-la em vigor
  await api('versao_aplicar&id=' + idA);
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForFunction(() =>
    [...document.querySelectorAll('#sel-versao option')].some(o => /em vigor/i.test(o.textContent)),
    null, { timeout: 9000 });
  const esc2 = await escolhidaSel();
  console.log('   escolhida no seletor:', esc2);
  ok(/^A\b/.test(esc2) && /em vigor/i.test(esc2),
     'reposta a versão, o seletor mostra A em vigor e escolhida');

  // ---------- convite impresso: o manual segue a versão ----------
  await api('defs_save', { defs: { 'cartao.reservado': 'RESERVADO VERSAO P1' } });
  await api('versao_criar&ambito=impresso', { nome: 'P1' });
  const noManual = () => p.evaluate(async () => {
    const t = await (await fetch('manual.php')).text();
    const m = t.replace(/\s+/g, ' ').match(/Versão do cartão:.{0,160}/);
    return m ? m[0].replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim() : '(não encontrado)';
  });
  let man = await noManual();
  console.log('   manual:', man);
  ok(/P1/.test(man) && /em vigor/.test(man), 'o manual diz de que versão do cartão foi gerado');
  ok(await p.evaluate(async () => (await (await fetch('manual.php')).text()).includes('RESERVADO VERSAO P1')),
     'e mostra o conteúdo dessa versão');

  // Mudar o cartão fora da versão: o manual assume-o
  await api('defs_save', { defs: { 'cartao.reservado': 'RESERVADO FORA DE VERSAO' } });
  man = await noManual();
  console.log('   manual depois de editar:', man);
  ok(/com alterações/.test(man), 'sem versão a bater certo, o manual di-lo em vez de mentir');

  // ---------- limpeza ----------
  for (const amb of ['digital', 'impresso']) {
    const dd = await api('versao_lista&ambito=' + amb);
    for (const v of (dd.versoes || [])) await api('versao_apagar&id=' + v.id, {});
  }
  await api('defs_save', { defs: { 'textos.kicker': '', 'cartao.reservado': '' } });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
