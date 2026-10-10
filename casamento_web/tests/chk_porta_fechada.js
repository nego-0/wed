// A página que se vê quando uma porta se fecha tem de dizer a verdade.
//
// Dizia sempre a mesma coisa — «X não faz parte da sua licença» — e isso era
// FALSO no caso mais comum de todos: o casal TEM o convite impresso, mas num
// escalão sem edição. Ia ao editor, era mandado para aqui, e lia que não tinha
// um módulo que a mesma página, três dedos abaixo, mostrava como seu.
//
// São três situações diferentes, e cada uma tem outra saída:
//   • não tem o módulo          → escolher um plano que o inclua
//   • tem, sem edição           → subir o escalão do módulo (paga a diferença)
//   • tem, só o modelo padrão   → subir para o escalão com a galeria toda
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'pf' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  const cat = (await api('lic_catalogo')).catalogo;
  const esc = (m, e) => cat.modulos.find(x => x.chave === m).escaloes.find(x => x.chave === e);
  const conv80   = esc('convidados', 'convidados_80');
  const impPad   = esc('impresso', 'impresso_padrao');   // sem edição
  const impEdi   = esc('impresso', 'impresso_edicao');   // com edição, só o padrão
  const digPad   = esc('digital', 'digital_padrao');

  const email = 'casal.' + marca + '@exemplo.ao';
  let d = await api('casamento_criar', { nome: 'Porta ' + marca, data: '2027-11-06',
                                         noivos_email: email, noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;

  const casal = await entrar(await b.newContext(), email, 'senhaforte123');
  casal.on('pageerror', e => errs.push('licenca: ' + e.message));
  const texto = async (url) => {
    await casal.goto(BASE + url, { waitUntil: 'networkidle' });
    await casal.waitForTimeout(500);
    const el = await casal.$('.pl-porta');
    return el ? (await el.innerText()) : '';
  };

  // ---------- 1. não tem o módulo ----------
  await api('lic_conceder', { casamento: cid, escaloes: [conv80.id], meses: 12 });
  let t = await texto('/licenca.php?quero=impresso&preciso=editar');
  console.log('   sem o módulo:', JSON.stringify(t.split('\n')[1] || t.slice(0, 80)));
  ok(/não faz parte da sua licen/i.test(t),
     'sem o módulo, diz que ele não faz parte da licença');

  // E o editor manda mesmo para cá.
  await casal.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  ok(/licenca\.php/.test(casal.url()), 'o editor do cartão fecha a porta e manda para a licença');

  // ---------- 2. tem o módulo, sem edição ----------
  // É aqui que estava a mentira.
  await api('lic_conceder', { casamento: cid, escaloes: [conv80.id, impPad.id], meses: 12 });
  t = await texto('/licenca.php?quero=impresso&preciso=editar');
  console.log('   sem edição:', JSON.stringify(t.split('\n')[1] || t.slice(0, 80)));
  ok(!/não faz parte da sua licen/i.test(t),
     'com o módulo na licença, JÁ NÃO diz que ele não faz parte dela');
  ok(/editor/i.test(t), 'diz que o que falta é o editor');
  ok(/escalão|escalao/i.test(t) && /Modelo padrão/i.test(t),
     'e nomeia o escalão que o casal tem');
  ok(/diferen/i.test(t), 'e diz que paga só a diferença para subir');

  // O editor continua fechado — a mensagem mudou, a porta não.
  await casal.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  ok(/licenca\.php/.test(casal.url()), 'e o editor continua fechado, como deve');

  // ---------- 3. tem edição, mas só o modelo padrão ----------
  await api('lic_conceder', { casamento: cid, escaloes: [conv80.id, impEdi.id], meses: 12 });
  t = await texto('/licenca.php?quero=impresso&preciso=modelos');
  console.log('   sem galeria:', JSON.stringify(t.split('\n')[1] || t.slice(0, 80)));
  ok(/modelo padr/i.test(t) && !/não faz parte/i.test(t),
     'sem a galeria, fala do modelo padrão e não de módulo em falta');
  ok(/todos os modelos/i.test(t), 'e diz o que ganha com a subida');

  // E com edição concedida, o editor abre.
  await casal.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  ok(!/licenca\.php/.test(casal.url()), 'com edição na licença, o editor abre');

  // ---------- 4. nada em falta: não se inventa um problema ----------
  t = await texto('/licenca.php?quero=impresso&preciso=editar');
  ok(t === '', 'sem nada em falta, a página não abre um aviso de porta fechada');

  // ---------- limpeza ----------
  await api('lic_conceder', { casamento: cid, escaloes: [], meses: 0 });
  await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + cid, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
