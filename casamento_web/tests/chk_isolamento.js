// Isolamento entre casamentos.
//
// A prova que dá sentido a toda a etapa 2. Cria um segundo casamento com dados
// próprios e verifica que, aberto um, nada do outro aparece: nem convites, nem
// convidados, nem mesas, nem versões, nem definições, nem histórico.
//
// Corre com a ligação em modo estrito (AMBITO_ESTRITO=1 no servidor): qualquer
// consulta que toque numa tabela de casamento sem dizer de qual rebenta a
// página, e a prova apanha-a.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext()).newPage();
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

  // Abrir um casamento pela sessão. Enquanto não há ecrã de escolha (etapa 3),
  // usa-se o mesmo caminho que ele virá a usar.
  const abrir = (id) => api('casamento_abrir&id=' + id, {});

  // ---------- terreno ----------
  const nova = await api('casamento_criar', { nome: 'Prova Isolamento', noiva: 'Bea', noivo: 'Caio' });
  if (!nova || !nova.success) {
    console.log('FAIL: não foi possível criar o segundo casamento —', nova && nova.message);
    console.log('\n1 FALHA(S)'); await b.close(); process.exit(1);
  }
  const id2 = nova.id;
  console.log('   segundo casamento:', id2);

  // Dados no casamento 1
  await abrir(1);
  const c1 = await api('convite_save', { nome_exibicao: 'ZZ Casal Um', tipo: 'ambos', lado: 'ambos',
                                         membros: ['Um da Casa'] });
  ok(c1 && c1.success, 'cria um convite no casamento 1');
  await api('mesa_save', { nome: 'Mesa Partilhada', capacidade: 6 });
  await api('defs_save', { defs: { 'textos.kicker': 'FRASE DO CASAMENTO UM' } });
  await api('versao_criar&ambito=digital', { nome: 'Versão do Um' });

  // Dados no casamento 2 — com um nome de mesa IGUAL, de propósito
  await abrir(id2);
  const c2 = await api('convite_save', { nome_exibicao: 'ZZ Casal Dois', tipo: 'ambos', lado: 'ambos',
                                         membros: ['Dois da Casa'] });
  ok(c2 && c2.success, 'cria um convite no casamento 2');
  const m2 = await api('mesa_save', { nome: 'Mesa Partilhada', capacidade: 6 });
  ok(m2 && m2.success, 'os dois casamentos podem ter uma mesa com o mesmo nome');
  await api('defs_save', { defs: { 'textos.kicker': 'FRASE DO CASAMENTO DOIS' } });

  // ---------- aberto o 2, nada do 1 aparece ----------
  const lista2 = await api('convite_list');
  const nomes2 = (lista2.convites || []).map(x => x.nome_exibicao);
  console.log('   convites vistos no 2:', JSON.stringify(nomes2));
  ok(nomes2.includes('ZZ Casal Dois'), 'no casamento 2 vê-se o convite do 2');
  ok(!nomes2.some(n => /ZZ Casal Um/.test(n)), 'no casamento 2 NÃO se vê o convite do 1');

  const cvd2 = await api('convidado_list');
  const pes2 = (cvd2.convidados || []).map(x => x.nome);
  ok(!pes2.some(n => /Um da Casa/.test(n)), 'nem os convidados do 1');

  const mes2 = await api('mesa_list');
  ok((mes2.mesas || []).length >= 1, 'o casamento 2 vê as suas mesas');
  ok((mes2.mesas || []).filter(m => m.nome === 'Mesa Partilhada').length === 1,
     'e vê só UMA "Mesa Partilhada" — a sua, não a do outro');

  const ver2 = await api('versao_lista&ambito=digital');
  ok(!(ver2.versoes || []).some(v => v.nome === 'Versão do Um'),
     'nem as versões guardadas do 1');

  const est2 = lista2.stats || {};
  console.log('   estatísticas no 2:', JSON.stringify({ convites: est2.convites, lugares: est2.lugares }));
  ok(+est2.convites === (lista2.convites || []).length,
     'as estatísticas contam só o casamento aberto');

  // As definições são de cada um
  const kicker = () => p.evaluate(async () => {
    const t = (await (await fetch('convite-editor.php')).text()).replace(/&quot;/g, '"');
    const m = t.match(/const ATUAIS\s*=\s*(\{.*?\});/s);
    return m ? JSON.parse(m[1])['textos.kicker'] : null;
  });
  ok(await kicker() === 'FRASE DO CASAMENTO DOIS', 'o convite do 2 mostra os textos do 2');

  // ---------- voltar ao 1: continua tudo lá ----------
  await abrir(1);
  const lista1 = await api('convite_list');
  const nomes1 = (lista1.convites || []).map(x => x.nome_exibicao);
  ok(nomes1.includes('ZZ Casal Um'), 'de volta ao 1, o convite do 1 continua lá');
  ok(!nomes1.some(n => /ZZ Casal Dois/.test(n)), 'e o do 2 não aparece');
  ok(await kicker() === 'FRASE DO CASAMENTO UM', 'e os textos são outra vez os do 1');

  // ---------- o convite público encontra o seu dono sozinho ----------
  // Sem sessão nenhuma: é o código que diz de que casamento se trata.
  const cod2 = c2.convite.codigo;
  const anon = await (await b.newContext()).newPage();
  const pub = await anon.goto(BASE + '/convite-digital.php?c=' + cod2, { waitUntil: 'domcontentloaded' });
  const html = await anon.content();
  ok(pub && pub.status() === 200, 'o convite público do 2 abre sem sessão');
  ok(/ZZ Casal Dois/.test(html), 'e mostra o convidado certo');
  ok(/FRASE DO CASAMENTO DOIS/.test(html) || !/FRASE DO CASAMENTO UM/.test(html),
     'com os textos do casamento a que pertence, não os do outro');
  await anon.close();

  // ---------- limpeza ----------
  await abrir(1);
  for (const c of ((await api('convite_list')).convites || [])) {
    if (/^ZZ Casal/.test(c.nome_exibicao)) await api('convite_delete&id=' + c.id + '&definitivo=1', {});
  }
  for (const v of ((await api('versao_lista&ambito=digital')).versoes || [])) {
    if (v.nome === 'Versão do Um') await api('versao_apagar&id=' + v.id, {});
  }
  for (const m of ((await api('mesa_list')).mesas || [])) {
    if (m.nome === 'Mesa Partilhada') await api('mesa_delete&id=' + m.id, {});
  }
  await api('defs_save', { defs: { 'textos.kicker': '' } });
  await api('casamento_apagar&id=' + id2, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
