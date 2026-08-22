// A capa que abre (o envelope com o selo/monograma) é uma camada editável no
// editor do convite digital: mostra-se, edita-se o monograma e a dica, e o que
// se grava chega ao convite dos convidados.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 950 } })).newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  // Guardar num casamento sem versão sua é agora «Guardar Como»: pede um nome.
  p.on('dialog', d => d.accept(d.type() === 'prompt' ? 'Prova capa' : undefined));
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


  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  // base limpa: sem versões próprias (para o estado ser determinista — a peça
  // fica na origem, e guardar é «Guardar Como»), e a capa no valor de origem.
  const limparVersoes = async () => {
    const vs = (await api('versao_lista&ambito=digital')).versoes || [];
    for (const v of vs) if (!v.padrao) await api('versao_apagar&ambito=digital&id=' + v.id, {});
  };
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await limparVersoes();
  await api('defs_save', { defs: { 'capa.monograma': '', 'capa.dica': 'Toque para abrir' } });

  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2600);
  const tela = () => p.frameLocator('#tela');

  // ---------- 1. o envelope é a primeira camada ----------
  const camadas = await p.locator('#camadas .camada .nome').allTextContents();
  console.log('   camadas:', JSON.stringify(camadas));
  ok(camadas[0] === 'Envelope', 'a camada "Envelope" aparece à cabeça da lista');
  ok(camadas.includes('Capa'), 'e a "Capa" (rosto) continua lá, distinta');
  // fixa, não arrastável
  ok(await p.locator('#camadas .camada').first().getAttribute('draggable') === 'false',
     'o envelope é fixo — não se arrasta nem se esconde');

  // ---------- 2. o editor abre no envelope: a capa aparece primeiro ----------
  ok(await tela().locator('#cover').isVisible(), 'o editor abre no Envelope — a capa selada aparece primeiro');
  await p.locator('#camadas .camada:has-text("Envelope")').click();   // garante a seleção
  await p.waitForTimeout(600);
  ok(await tela().locator('#cover').isVisible(), 'e a capa continua à vista no Envelope');
  ok(await tela().locator('#cover .seal [data-def="capa.monograma"]').isVisible(), 'com o selo do monograma à vista');

  // ---------- 2b. os selos do monograma (não animam: seguros de intercalar) ----------
  const seloSel = p.locator('#props select').filter({ hasText: 'Camafeu' });
  ok(await seloSel.count() > 0, 'o Envelope tem o seletor de selo do monograma');
  await seloSel.selectOption('camafeu');
  await p.waitForTimeout(300);
  ok(await tela().locator('#cover').getAttribute('data-selo') === 'camafeu',
     'escolher um selo muda o feitio do monograma na tela');
  await seloSel.selectOption('cera');   // volta ao de origem
  await p.waitForTimeout(200);

  // ---------- 3. o painel oferece monograma e dica ----------
  ok(await p.locator('#props [data-chave="capa.monograma"]').count() === 1, 'o painel tem o campo do monograma');
  ok(await p.locator('#props [data-chave="capa.dica"]').count() === 1, 'e o campo da dica de abertura');
  const dicaVazia = (await p.locator('#props').innerText()).includes('iniciais dos nomes');
  ok(dicaVazia, 'e explica que o monograma vazio usa as iniciais dos nomes');

  // o selo mostra as iniciais automáticas (I&A)
  const seloAuto = (await tela().locator('#cover .seal [data-def="capa.monograma"]').innerText()).trim();
  console.log('   selo automático:', seloAuto);
  ok(/^[A-Z]&[A-Z]$/.test(seloAuto), 'por omissão o selo mostra as iniciais do casal');

  // ---------- 4. editar o monograma pinta ao vivo, nos três sítios ----------
  await p.fill('#props [data-chave="capa.monograma"]', 'I♥A');
  await p.waitForTimeout(500);
  ok((await tela().locator('#cover .seal [data-def="capa.monograma"]').innerText()).trim() === 'I♥A', 'escrever o monograma muda o selo na hora');
  // aparece também no separador do convite e no rodapé
  const monos = await tela().locator('[data-def="capa.monograma"]').allInnerTexts();
  console.log('   ocorrências do monograma:', JSON.stringify(monos));
  ok(monos.length >= 3 && monos.every(m => m.trim() === 'I♥A'),
     'o monograma muda no selo, no separador e no rodapé de uma só vez');

  // limpar volta às iniciais
  await p.fill('#props [data-chave="capa.monograma"]', '');
  await p.waitForTimeout(500);
  ok(/^[A-Z]&[A-Z]$/.test((await tela().locator('#cover .seal [data-def="capa.monograma"]').innerText()).trim()),
     'apagar o monograma volta às iniciais automáticas');

  // ---------- 5b. as aberturas do envelope (a pré-visualização anima a capa) ----------
  // Fica para o fim das leituras do selo: jogar a animação esconde a capa ~2s
  // (o #cover atrasa a volta à vista), e uma leitura a meio sairia vazia.
  const abreSel = p.locator('#props select').filter({ hasText: 'Portas ao meio' });
  ok(await abreSel.count() > 0, 'o Envelope tem o seletor de abertura');
  await abreSel.selectOption('cruzado');
  await p.waitForTimeout(300);
  ok(await tela().locator('#cover').getAttribute('data-abre') === 'cruzado',
     'escolher uma abertura muda o modo do envelope na tela');
  await abreSel.selectOption('portas');   // volta ao de origem

  // ---------- 5. editar a dica ----------
  await p.fill('#props [data-chave="capa.monograma"]', 'I♥A');   // deixa um monograma para gravar
  await p.fill('#props [data-chave="capa.dica"]', 'Deslize para revelar');
  await p.waitForTimeout(500);
  // innerText vem em maiúsculas (text-transform da capa); comparo o texto real.
  const hint = (await tela().locator('#cover .cover-hint').textContent()).trim();
  ok(hint === 'Deslize para revelar', 'escrever a dica muda o texto "toque para abrir" na hora');

  await p.screenshot({ path: OUT + '/capa_editor.png' });

  // ---------- 6. mudar de camada esconde a capa outra vez ----------
  await p.locator('#camadas .camada:has-text("O convite")').first().click();
  await p.waitForTimeout(500);
  ok(!(await tela().locator('#cover').isVisible()), 'escolher outra camada esconde a capa e volta ao conteúdo');

  // ---------- 7. gravar e ver no convite do convidado ----------
  await p.evaluate(() => guardar());
  await p.waitForTimeout(2200);
  const gravado = await api('convite_defs').catch(() => null);
  // vai buscar o convite tal como um convidado o recebe
  const html = await p.evaluate(async () => (await (await fetch('convite-digital.php?demo=1')).text()));
  ok(/I♥A/.test(html), 'o monograma gravado sai no convite dos convidados');
  ok(/Deslize para revelar/.test(html), 'e a dica gravada também');
  // a capa continua fechada e por abrir para o convidado
  ok(/id="cover"(?![^>]*\bopen\b)/.test(html) && !/id="cover"[^>]*style="[^"]*display:\s*none/.test(html),
     'o convidado recebe a capa fechada, por abrir');

  // ---------- limpeza ----------
  await limparVersoes();
  await api('defs_save', { defs: { 'capa.monograma': '', 'capa.dica': 'Toque para abrir' } });

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.filter(e => !/favicon/.test(e)).length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
