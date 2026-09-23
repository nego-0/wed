// Um casamento novo nasce com as fotografias de exemplo do admin.
//
// O convite digital de um casamento acabado de criar mostrava as fotografias do
// PRIMEIRO casal — o valor de fábrica de `media.*`, que é o retrato real dele —
// até o casal enviar as suas. Um casal a inscrever-se via, no seu convite, gente
// que não conhece. É o mesmo problema que os «dados de exemplo» resolvem nos
// modelos, e que ficava por resolver nos casamentos.
//
// A festa passa a nascer com o padrão que o admin escolheu (media/foto), semeado
// à criação como as gavetas do bar e do orçamento já eram. O nome e o evento vêm
// do registo — são do casal — e não se semeiam com um exemplo.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzp' + Math.floor(Math.random() * 1e5);

// Um default à escolha do admin, e o valor de fábrica ao qual se repõe no fim.
const DEFAULT_ADMIN = 'assets/convite/galeria/acesso-26711184.jpg';
const FABRICA_HERO  = 'assets/convite/galeria/capa-34371787.jpg';      // o exemplo neutro
const PRIMEIRO_CASAL = 'assets/convite/galeria/capa-isabel-abednego.jpg'; // o que NÃO deve aparecer

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push('admin: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json().catch(() => ({ success: false }));
  }, { a, c });
  // O media.hero com que o convite de um casamento fica — lido de onde o
  // convite o lê (as definições do casamento).
  const heroDe = (id) => p.evaluate(async i => {
    const r = await fetch('api.php?action=dados_exportar&id=' + i);
    const d = await r.json();
    return ((d.casamentos || [])[0] || {}).definicoes?.['media.hero'] || '(nada)';
  }, id);
  const arrumar = async (id) => {
    if (!id) return;
    await api('lic_revogar', { casamento: id, motivo: 'arrumar a prova' });
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  };

  // ============ 1. sem o admin mexer: o exemplo NEUTRO, nunca o 1.º casal ====
  const A = await api('casamento_criar', { nome: 'ZZP Fabrica ' + marca, noiva: 'Rita', noivo: 'Tó' });
  ok(!!A.id, 'um casamento novo, sem o admin ter mexido nos dados de exemplo');
  const heroA = await heroDe(A.id);
  ok(heroA !== PRIMEIRO_CASAL,
     'o seu convite NÃO mostra as fotografias do primeiro casal: ' + heroA);
  ok(heroA === FABRICA_HERO,
     'mostra o exemplo neutro da casa, que é para isso que ele existe: ' + heroA);

  // ============ 2. com o admin a definir um default: é ESSE que aparece ======
  const g = await api('modelo_exemplo_guardar', { 'media.hero': DEFAULT_ADMIN });
  ok(g && g.exemplo && g.exemplo['media.hero'] === DEFAULT_ADMIN,
     'o admin define uma fotografia como dado padrão: ' + (g.exemplo && g.exemplo['media.hero']));

  const B = await api('casamento_criar', { nome: 'ZZP Default ' + marca, noiva: 'Ana', noivo: 'Bento' });
  const heroB = await heroDe(B.id);
  ok(heroB === DEFAULT_ADMIN,
     'e o casamento criado A SEGUIR nasce com essa fotografia no convite: ' + heroB);

  // ============ 3. e um casamento ANTERIOR à mudança fica como estava ========
  // (a mesma regra dos modelos: o padrão vale para quem vem a seguir, e não
  //  reescreve por baixo de quem já cá estava.)
  const heroAdepois = await heroDe(A.id);
  ok(heroAdepois === heroA,
     'um casamento anterior à mudança não é reescrito por baixo: ' + heroAdepois);

  // ---- arrumar: repõe o exemplo de fábrica e apaga os casamentos ----
  // Repor = guardar o valor de fábrica, que a própria acção reconhece e apaga
  // a linha de override.
  await api('modelo_exemplo_guardar', { 'media.hero': FABRICA_HERO });
  await arrumar(A.id);
  await arrumar(B.id);
  const sobrou = await p.evaluate(async () => {
    const d = await window.api('casamentos');
    return (d.casamentos || []).filter(x => String(x.nome).includes('ZZP ')).map(x => x.nome);
  });
  ok(sobrou.length === 0, 'a prova não deixa casamentos para trás: ' + (sobrou.join(', ') || 'nenhum'));
  const exReposto = await api('modelo_exemplo');
  ok(exReposto.exemplo && exReposto.exemplo['media.hero'] === FABRICA_HERO,
     'e repõe o dado de exemplo de fábrica: ' + (exReposto.exemplo && exReposto.exemplo['media.hero']));

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  await b.close();
  console.log(f ? '\n' + f + ' FALHA(S)' : '\nTUDO VERDE');
  process.exit(f ? 1 : 0);
})();
