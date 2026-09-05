// Prova focada: as fotografias que o admin define como dados de exemplo do
// modelo TÊM de chegar ao casal que o aplica — mas só às secções cuja foto o
// casal ainda não mexeu. Uma foto que o casal trocou fica intocada.
// Corre só isto, não a suite toda.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const FOTO_EXEMPLO = 'assets/convite/galeria/capa-34371787.jpg';   // capa (hero) do exemplo
const FOTO_HIST    = 'assets/convite/galeria/historia-18706408.jpg';
const FOTO_CASAL   = 'assets/convite/galeria/capa-35069916.jpg';   // a que o "casal" já pôs

const entrar = async (ctx, u, s) => {
  const g = await ctx.newPage();
  await g.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await g.fill('input[name=utilizador]', u); await g.fill('input[name=senha]', s);
  await g.click('button[type=submit]'); await g.waitForLoadState('networkidle');
  g._api = (a, c) => g.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return g;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // O admin define as fotos de exemplo dos modelos (os "dados padrão").
  const gse = await api('modelo_exemplo_guardar',
    { 'media.hero': FOTO_EXEMPLO, 'media.historia': FOTO_HIST });
  ok(gse && gse.exemplo && gse.exemplo['media.hero'] === FOTO_EXEMPLO,
     'o admin define fotos de exemplo para os modelos');

  // Um modelo nasce agora — leva as fotos de exemplo que o admin definiu.
  const of = await api('casamento_criar', { nome: 'ZZ FotoOf ' + marca, noiva: 'Ana', noivo: 'Aldo' });
  await api('casamento_abrir&id=' + of.id);
  const mod = await api('modelo_criar', { nome: 'Modelo Fotos ' + marca,
                        descricao: 'com fotos de exemplo', ambito: 'digital', visivel: true });
  ok(mod && mod.success, 'fez-se um modelo — nasce com as fotos de exemplo');

  const defs = async (cid) => {
    await api('casamento_abrir&id=' + cid);
    const j = await admin.evaluate(async () =>
      (await fetch('api.php?action=dados_exportar&ambito=casamento')).json());
    return (j.casamentos[0] || {}).definicoes || {};
  };

  // ---- caso 1: casal SEM fotos suas (tudo na origem) ----
  const c1 = await api('casamento_criar', { nome: 'ZZ FotoCasal1 ' + marca, noiva: 'Bea', noivo: 'Bras' });
  const e1 = 'foto1' + marca + '@ex.pt';
  await api('utilizador_criar', { email: e1, nome: 'Casal Um', senha: 'segredo12345',
                                  casamento_id: c1.id, papel: 'noivos' });
  const n1 = await entrar(await b.newContext(), e1, 'segredo12345');

  const antes1 = await defs(c1.id);
  ok(!antes1['media.hero'] || antes1['media.hero'] === 'assets/convite/galeria/capa-isabel-abednego.jpg',
     'o casal 1 começa na foto de origem');

  const ap1 = await n1._api('modelo_aplicar&id=' + mod.id);
  ok(ap1 && ap1.success, 'o casal 1 aplica o modelo');

  const dep1 = await defs(c1.id);
  console.log('   casal1 media.hero     =', JSON.stringify(dep1['media.hero']));
  console.log('   casal1 media.historia =', JSON.stringify(dep1['media.historia']));
  ok(dep1['media.hero'] === FOTO_EXEMPLO, 'a capa do modelo carregou para o casal 1');
  ok(dep1['media.historia'] === FOTO_HIST, 'e a foto da história também');

  await n1.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await n1.waitForLoadState('networkidle');
  await n1.screenshot({ path: 'tests/_fotos_casal1.png' });
  console.log('   screenshot: tests/_fotos_casal1.png');

  // ---- caso 2: casal que JÁ pôs a sua capa — essa fica, o resto vem do modelo ----
  const c2 = await api('casamento_criar', { nome: 'ZZ FotoCasal2 ' + marca, noiva: 'Cid', noivo: 'Cás' });
  const e2 = 'foto2' + marca + '@ex.pt';
  await api('utilizador_criar', { email: e2, nome: 'Casal Dois', senha: 'segredo12345',
                                  casamento_id: c2.id, papel: 'noivos' });
  const n2 = await entrar(await b.newContext(), e2, 'segredo12345');
  // o casal 2 troca a SUA capa (mas deixa a história na origem)
  await n2._api('defs_save', { defs: { 'media.hero': FOTO_CASAL } });

  const ap2 = await n2._api('modelo_aplicar&id=' + mod.id);
  ok(ap2 && ap2.success, 'o casal 2 aplica o mesmo modelo');

  const dep2 = await defs(c2.id);
  console.log('   casal2 media.hero     =', JSON.stringify(dep2['media.hero']));
  console.log('   casal2 media.historia =', JSON.stringify(dep2['media.historia']));
  ok(dep2['media.hero'] === FOTO_CASAL, 'a capa que o casal 2 pôs NÃO foi apagada pelo modelo');
  ok(dep2['media.historia'] === FOTO_HIST, 'mas a história, que ele não mexeu, veio do modelo');

  console.log(f ? ('FALHAS: ' + f) : 'TUDO OK');
  await b.close();
  process.exit(f ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
