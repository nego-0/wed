// O admin designa qual modelo é a «peça de origem».
//
// Duas mecânicas convivem sem confusão: a peça de origem (o ponto de regresso,
// id 0 nas versões) e a lista de modelos da casa. O admin escolhe qual modelo
// CONSTITUI a peça de origem — e é o nome DELE que a peça passa a dar, e o
// desenho DELE que um «voltar à origem» repõe. Só um modelo publicado e de
// todos pode sê-la; e há sempre volta ao automático (o de fábrica, pelo desenho).
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  const olho = await ctx.newPage();
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
  const rotulo = async (pagina) => {
    await olho.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    return (await olho.textContent('.estado-linha')).replace(/\s+/g, ' ').trim();
  };

  const marca = 'zz' + Date.now().toString().slice(-6);
  const casal = await api('casamento_criar', { nome: 'ZZ Origem ' + marca, noiva: 'Iris', noivo: 'Ivo' });
  await api('casamento_abrir&id=' + casal.id, {});

  const listaDig = () => api('modelo_lista&ambito=digital').then(d => d.modelos || []);
  const nomeOrigem = () => api('versao_lista&ambito=digital').then(d =>
    (d.versoes || []).find(v => v.padrao) || {});

  // ---------- 0. de origem, o Isabel & Abednego é a peça de origem ----------
  let mods = await listaDig();
  const isabel = mods.find(m => m.nome === 'Isabel & Abednego');
  ok(isabel && isabel.de_origem === true,
     'à partida, «Isabel & Abednego» está assinalado como peça de origem');
  ok(mods.filter(m => m.de_origem).length === 1,
     'e é o único — uma só peça de origem por peça');
  ok((await nomeOrigem()).nome === 'Isabel & Abednego',
     'a linha de origem das versões dá o nome desse modelo');

  // ---------- 1. o admin passa a origem para outro modelo ----------
  const borgonha = mods.find(m => m.nome === 'Borgonha');
  ok(!!borgonha, 'a casa tem o modelo «Borgonha» (publicado, de todos)');
  const def = await api('modelo_pecaorigem&ambito=digital&id=' + borgonha.id, {});
  ok(def && def.success && def.nome === 'Borgonha',
     'o admin designa «Borgonha» como peça de origem');

  mods = await listaDig();
  ok(mods.find(m => m.id === borgonha.id).de_origem === true,
     'agora é «Borgonha» que aparece assinalado');
  ok(mods.find(m => m.id === isabel.id).de_origem === false,
     'e o «Isabel & Abednego» deixa de o estar');
  ok(mods.filter(m => m.de_origem).length === 1, 'continua a ser um só');
  ok((await nomeOrigem()).nome === 'Borgonha',
     'a linha de origem das versões passa a dar «Borgonha»');

  // ---------- 2. «voltar à origem» repõe o desenho de «Borgonha» ----------
  await api('versao_aplicar&ambito=digital&id=0', {});
  const linha = await rotulo('digital.php');
  console.log('   linha após voltar à origem:', JSON.stringify(linha.slice(0, 48)));
  ok(/Borgonha/.test(linha) && !/Original/.test(linha),
     'voltar à origem deixa a peça em «Borgonha» — o modelo de origem designado');
  const vl = await api('versao_lista&ambito=digital');
  const padr = (vl.versoes || []).find(v => v.padrao);
  ok(padr && padr.em_vigor === true,
     'e a linha de origem consta como em vigor');
  ok(mods && (await listaDig()).find(m => m.id === borgonha.id).em_vigor === true,
     'nos modelos, «Borgonha» consta como o modelo em vigor');

  // ---------- 3. não se pode designar um modelo não publicado/de-alguns ----------
  const priv = await api('modelo_criar', { nome: 'Reservado ' + marca,
                         descricao: 'só para alguns', ambito: 'digital', visivel: false });
  const recusa = await api('modelo_pecaorigem&ambito=digital&id=' + priv.id, {});
  ok(recusa && recusa.success === false,
     'um modelo por publicar não pode ser peça de origem');

  // ---------- 4. volta ao automático (id=0) ----------
  const limpar = await api('modelo_pecaorigem&ambito=digital&id=0', {});
  ok(limpar && limpar.success && limpar.id === 0,
     'o admin devolve a designação ao automático');
  ok(limpar.nome === 'Isabel & Abednego',
     'e o automático volta a achar «Isabel & Abednego» pelo desenho de fábrica');
  const mods2 = await listaDig();
  ok(mods2.find(m => m.id === isabel.id).de_origem === true,
     'que volta a aparecer assinalado como peça de origem');

  // ---------- limpeza: repor a designação semeada e apagar o casal ----------
  await api('modelo_pecaorigem&ambito=digital&id=' + isabel.id, {});   // repõe o estado semeado
  await api('modelo_apagar&id=' + priv.id, {});
  await api('casamento_estado&id=' + casal.id + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + casal.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
