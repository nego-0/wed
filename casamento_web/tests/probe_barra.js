// Prova focada: um modelo cujo desenho é o de origem, posto em vigor, tem de
// mandar na BARRA e no ESTADO — não pode aparecer «Original em vigor».
// Reproduz o que o casal via nas fotos: barra dizia «Original», e a aba dos
// modelos dizia o modelo. Corre só isto, não a suite toda.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

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

  // Casamento-oficina LIMPO, no desenho de origem: um modelo daqui tem o mesmo
  // desenho que a peça de origem — é o caso exato das fotos.
  const of = await api('casamento_criar', { nome: 'ZZ BarraOf ' + marca, noiva: 'Ana', noivo: 'Aldo' });
  await api('casamento_abrir&id=' + of.id);
  const mod = await api('modelo_criar', { nome: 'Teste Barra ' + marca,
                        descricao: 'igual à origem', ambito: 'digital', visivel: true });
  ok(mod && mod.success, 'fez-se um modelo cujo desenho é o de origem');

  // Um casal também LIMPO (fica no desenho de origem).
  const casal = await api('casamento_criar', { nome: 'ZZ BarraCasal ' + marca, noiva: 'Bea', noivo: 'Bras' });
  const emailC = 'barra' + marca + '@ex.pt';
  await api('utilizador_criar', { email: emailC, nome: 'Casal Barra',
                                  senha: 'segredo12345', casamento_id: casal.id, papel: 'noivos' });

  const noivos = await entrar(await b.newContext(), emailC, 'segredo12345');

  // Antes de aplicar: a barra diz «Original em vigor» (a peça é a de origem).
  await noivos.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await noivos.waitForFunction(() => {
    const el = document.getElementById('bt-versao');
    return el && !/A carregar|…/.test(el.textContent) && el.textContent.trim().length > 3;
  }, { timeout: 8000 }).catch(() => {});
  const barraAntes = (await noivos.textContent('#bt-versao') || '').replace(/\s+/g, ' ').trim();
  console.log('   barra antes de aplicar:', JSON.stringify(barraAntes));
  ok(/Original/i.test(barraAntes), 'sem escolha própria, a barra mostra a Original de origem');

  // O casal põe o modelo em vigor.
  const posto = await noivos._api('modelo_aplicar&id=' + mod.id);
  console.log('   aplicar:', JSON.stringify(posto).slice(0, 120));
  ok(posto && posto.success, 'o casal põe o modelo em vigor');

  // Recarrega o editor e lê a barra outra vez.
  await noivos.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await noivos.waitForFunction(() => {
    const el = document.getElementById('bt-versao');
    return el && !/A carregar|…/.test(el.textContent) && el.textContent.trim().length > 3;
  }, { timeout: 8000 }).catch(() => {});
  const barra = (await noivos.textContent('#bt-versao') || '').replace(/\s+/g, ' ').trim();
  console.log('   barra depois de aplicar:', JSON.stringify(barra));
  ok(barra.includes('Teste Barra'), 'a barra nomeia o modelo posto em vigor');
  ok(/modelo em vigor/i.test(barra), 'e di-lo como «modelo em vigor»');
  ok(!/Original/i.test(barra), 'a barra já NÃO diz «Original»');

  // Abre o painel e lê o estado da aba das versões.
  await noivos.click('#bt-versao');
  await noivos.waitForSelector('.vs-jan.aberta .vs-estado', { timeout: 8000 });
  const estado = (await noivos.textContent('.vs-jan.aberta .vs-estado') || '').replace(/\s+/g, ' ').trim();
  console.log('   estado no painel:', JSON.stringify(estado));
  ok(estado.includes('Teste Barra'), 'o estado do painel também nomeia o modelo');
  ok(!/Em vigor:\s*Original/i.test(estado), 'o estado já NÃO diz «Em vigor: Original»');

  await noivos.screenshot({ path: 'tests/_barra_modelo.png' });
  console.log('   screenshot: tests/_barra_modelo.png');

  // ---- e um modelo COM desenho próprio: o casal vê-o carregado ----
  // O «Teste» acima não tinha desenho distinto (nasceu da origem), por isso
  // nada de visível mudava. Um modelo com marca própria tem de carregar.
  await api('casamento_abrir&id=' + of.id);
  await api('defs_save', { defs: { 'textos.kicker': 'MARCA VISIVEL ' + marca,
                                   'textos.hero_sub': 'SUB VISIVEL ' + marca } });
  const modC = await api('modelo_criar', { nome: 'Com Desenho ' + marca,
                        descricao: 'tem cara própria', ambito: 'digital', visivel: true });
  ok(modC && modC.success, 'fez-se um modelo COM desenho próprio');

  const posto2 = await noivos._api('modelo_aplicar&id=' + modC.id);
  ok(posto2 && posto2.success && posto2.mudou === true,
     'ao aplicá-lo, o desenho MUDA (mudou=true) — não é igual ao que tinha');

  // Lê o desenho MESMO guardado no casal (o modelo copia design, não conteúdo).
  await api('casamento_abrir&id=' + casal.id);
  const dCasal = (await admin.evaluate(async () =>
    (await fetch('api.php?action=dados_exportar&ambito=casamento')).json()))
    .casamentos[0].definicoes;
  console.log('   casal tema.nome:', JSON.stringify(dCasal['tema.nome']),
              '| kicker:', JSON.stringify(dCasal['textos.kicker']));
  ok(dCasal['textos.kicker'] === 'MARCA VISIVEL ' + marca,
     'o desenho do modelo (kicker) carregou para o casal');
  ok(dCasal['textos.hero_sub'] === 'SUB VISIVEL ' + marca,
     'e o resto do desenho também — é o que o casal passa a ver no editor');

  console.log(f ? ('FALHAS: ' + f) : 'TUDO OK');
  await b.close();
  process.exit(f ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
