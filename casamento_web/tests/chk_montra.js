// A montra da inscrição: as capturas, as fotos do convite e os preços do admin.
//
// O que aqui se prova é o que a montra promete e o que dela resulta:
//
//   1. cada módulo mostra-se em imagem, e a imagem é servida mesmo;
//   2. escolher o convite digital abre a escolha das fotografias de cada secção;
//   3. o escalão SEM edição avisa que as fotos ficam fixas — e o COM edição não;
//   4. as fotografias escolhidas ficam mesmo no convite do casal;
//   5. o admin corrige os preços dos módulos ao montar um pacote, e a poupança
//      que o pacote anuncia acompanha o que ele mexe;
//   6. os pedidos chegam-lhe separados: os novos e as actualizações;
//   7. o casal com tecto vê quantos lugares lhe restam, e o botão de novo
//      convite fecha-se quando eles acabam.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const comApi = (p) => {
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};
const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  return comApi(p);
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'mt' + String(Date.now()).slice(-6);
  const SENHA = 'segredo12345';
  const email = 'casal.' + marca + '@exemplo.ao';

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. as capturas de cada módulo ----------
  const anon = await b.newContext({ viewport: { width: 1280, height: 1000 } })
                      .then(c => c.newPage());
  const errs = []; anon.on('pageerror', e => errs.push(e.message));
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await anon.waitForSelector('#reg-planos .pl-pac', { timeout: 10000 });

  const retratos = await anon.$$eval('.pl-retrato img', e => e.length);
  ok(retratos >= 3, `a montra mostra os módulos em imagem (${retratos})`);

  // Percorre-se a página para o lazy-load ir buscar tudo. Uma imagem que não
  // carrega é pior do que imagem nenhuma: fica um buraco cinzento na montra.
  await anon.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 450) {
      window.scrollTo(0, y); await new Promise(r => setTimeout(r, 110));
    }
    window.scrollTo(0, 0);
  });
  await anon.waitForTimeout(1600);
  const largs = await anon.$$eval('.pl-retrato img', e => e.map(i => i.naturalWidth || 0));
  ok(largs.every(w => w > 0), 'e todas carregam mesmo (' + largs.join(', ') + ')');

  // A lupa abre a imagem em grande.
  await anon.click('.pl-mod[data-chave="convidados"] .pl-retrato');
  await anon.waitForTimeout(500);
  ok(await anon.locator('#pl-lupa.on').isVisible(), 'e clicar numa amplia-a');
  await anon.keyboard.press('Escape'); await anon.waitForTimeout(300);
  ok(!(await anon.locator('#pl-lupa.on').isVisible()), 'e Escape fecha-a');

  // ---------- 2 e 3. as fotos do convite, e o aviso ----------
  const escolher = (mod, rx) => anon.evaluate(({ mod, rx }) => {
    const l = [...document.querySelectorAll('.pl-mod[data-chave="' + mod + '"] .pl-esc')]
              .find(x => new RegExp(rx).test(x.textContent));
    if (l) { l.querySelector('input').click(); return true; }
    return false;
  }, { mod, rx });

  ok(!(await anon.locator('.pl-fotos').isVisible()),
     'sem o convite digital escolhido, não se pedem fotografias');

  await escolher('digital', 'Modelo padrão');
  await anon.waitForTimeout(600);
  ok(await anon.locator('.pl-fotos').isVisible(),
     'escolhido o convite digital, aparece a escolha das fotografias');
  const secs = await anon.$$eval('.pl-sec', e => e.length);
  ok(secs >= 3, `uma secção do convite de cada vez (${secs})`);
  ok(await anon.$$eval('.pl-ft', e => e.length) > 0, 'e cada uma com as suas miniaturas');

  const aviso = await anon.$eval('.pl-fotos-nota', e => ({ c: e.className, t: e.textContent }));
  ok(/aviso/.test(aviso.c), 'no escalão SEM edição, o aviso é de aviso');
  ok(/não poder(ão|á) ser alterada/i.test(aviso.t),
     'e diz, por palavras, que as fotos não poderão ser alteradas');

  await escolher('digital', 'com edição');
  await anon.waitForTimeout(600);
  ok(/boa/.test(await anon.$eval('.pl-fotos-nota', e => e.className)),
     'com edição, o aviso passa a nota tranquila');
  ok(/trocá-las|sempre que quiserem/i.test(await anon.$eval('.pl-fotos-nota', e => e.textContent)),
     'e diz que as podem trocar quando quiserem');

  // Volta ao SEM edição: é o caso que interessa provar até ao fim.
  await escolher('digital', 'Modelo padrão');
  await escolher('convidados', 'Até 80');
  await anon.waitForTimeout(500);

  // ---------- 4. as fotos escolhidas ficam no convite ----------
  await anon.fill('#noiva', 'Nara' + marca); await anon.fill('#noivo', 'Kito' + marca);
  await anon.fill('#email', email);
  await anon.fill('#senha', SENHA); await anon.fill('#confirmar', SENHA);
  await anon.fill('#data', '2027-10-09');

  // A terceira fotografia de cada secção — para não ser a que já vinha marcada.
  const escolhidas = await anon.evaluate(() => {
    const out = {};
    document.querySelectorAll('.pl-sec').forEach(sc => {
      const fs = [...sc.querySelectorAll('.pl-ft input')];
      const alvo = fs[2] || fs[fs.length - 1];
      alvo.click(); out[alvo.name.replace(/^ft-/, '')] = alvo.value;
    });
    return out;
  });
  ok(Object.keys(escolhidas).length >= 3, 'escolhem-se fotografias diferentes das sugeridas');

  await anon.check('#reg-aceite');
  await anon.click('#btn'); await anon.waitForTimeout(1800);
  ok(await anon.locator('#obrigado').isVisible(), 'a inscrição passa com as fotografias escolhidas');

  const peds = await api('lic_pedidos&estado=pendente');
  const ped = (peds.pedidos || []).find(x => (x.casamento_nome || '').includes(marca));
  ok(!!ped, 'o pedido chega à administração');
  const cid = ped.casamento_id;

  // As fotografias já estão no convite do casal — antes mesmo de a licença ser
  // concedida. Foi ele que as escolheu; são dele.
  const ficha = await api('casamento_ficha&id=' + cid);
  const casal = await entrar(await b.newContext(), email, SENHA);
  const defs = await casal._api('lic_estado');
  ok(defs.success, 'o casal entra e vê a sua licença');

  const noConvite = await api('dados_exportar&ambito=casamento&id=' + cid);
  const gravadas = JSON.stringify(noConvite || {});
  let batem = 0;
  Object.values(escolhidas).forEach(src => { if (gravadas.includes(src)) batem++; });
  ok(batem === Object.keys(escolhidas).length,
     `as ${Object.keys(escolhidas).length} fotografias escolhidas estão no convite (${batem})`);

  // ---------- 5. o admin mexe nos preços ao montar um pacote ----------
  await admin.evaluate(() => verVista('licencas')); await admin.waitForTimeout(1400);
  await admin.evaluate(() => licVista('pacotes')); await admin.waitForTimeout(1200);
  const pid = await admin.evaluate(() => LIC_CAT.pacotes[0].id);
  await admin.evaluate(id => licPacoteItens(id), pid);
  await admin.waitForTimeout(800);
  ok(await admin.locator('#lic-janela.on').isVisible(), 'a janela do pacote abre');
  const campos = await admin.$$eval('#lic-pi-lista input[data-preco]', e => e.length);
  ok(campos > 0, `e traz o preço de cada escalão, editável ali mesmo (${campos})`);

  const contaAntes = await admin.$eval('#lic-pi-conta', e => e.textContent);
  await admin.evaluate(() => {
    const i = document.querySelector('#lic-pi-lista input[type=checkbox]:checked');
    const pr = document.querySelector('#lic-pi-lista input[data-preco="' + i.value + '"]');
    pr.value = String((+pr.value) + 7000);
    pr.dispatchEvent(new Event('input'));
  });
  await admin.waitForTimeout(400);
  ok(contaAntes !== await admin.$eval('#lic-pi-conta', e => e.textContent),
     'e a conta da poupança acompanha o que se mexe');

  // Guardar leva o preço novo para todo o preçário.
  const alvo = await admin.evaluate(() => {
    const i = document.querySelector('#lic-pi-lista input[type=checkbox]:checked');
    const pr = document.querySelector('#lic-pi-lista input[data-preco="' + i.value + '"]');
    return { id: +i.value, preco: parseFloat(pr.value) };
  });
  await admin.evaluate(() => document.getElementById('lic-jo').click());
  await admin.waitForTimeout(1400);
  const cat = await api('lic_catalogo');
  let achado = null;
  cat.catalogo.modulos.forEach(m => m.escaloes.forEach(e => { if (e.id === alvo.id) achado = e; }));
  ok(achado && Math.abs(achado.preco - alvo.preco) < 0.01,
     `o preço novo fica no preçário (${achado && achado.preco} = ${alvo.preco})`);

  // ---------- 6. os pedidos chegam separados ----------
  // Um reforço só é reforço depois de haver licença de pé: aprova-se o pedido
  // inicial primeiro, e SÓ ENTÃO o casal pede mais. (Antes disso, mexer no
  // pedido é alterá-lo, não reforçá-lo — e é essa a diferença que se prova.)
  const apr = await api('lic_decidir', { id: ped.id, decisao: 'aprovar', nota: '' });
  ok(apr.success, 'a administração aprova o pedido inicial');

  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  const eMesas = cat.catalogo.modulos.find(m => m.chave === 'mesas').escaloes[0].id;
  const ref = await casal._api('lic_pedir',
    { pacote: 0, escaloes: [eMesas], meses: 12, aceito: true });
  ok(ref.success && ref.pedido.tipo === 'upgrade',
     'e o pedido seguinte do casal já é um reforço');

  await admin.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await admin.evaluate(() => verVista('licencas')); await admin.waitForTimeout(1800);
  const grupos = await admin.$$eval('.lic-grupo',
    e => e.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
  ok(grupos.some(g => /Actualiza/i.test(g)),
     'a administração vê o reforço no grupo das actualizações: ' + grupos.join(' | '));
  ok(!grupos.some(g => /Novos pedidos/i.test(g)),
     'e o grupo dos novos pedidos não aparece quando não há nenhum');

  // ---------- 7. o modal da licença é um só ----------
  await admin.evaluate(() => verVista('casamentos')); await admin.waitForTimeout(800);
  // A lista chega filtrada por estado; pede-se «todos» para o casamento da
  // prova estar lá antes de se lhe abrir a licença.
  await admin.evaluate(() => filtrarCasamentos('todos', 1));
  await admin.waitForTimeout(1600);
  ok(await admin.evaluate(id => !!CASAMENTOS[id], cid), 'o casamento está na lista do admin');
  await admin.evaluate(id => gerirLicenca(id), cid); await admin.waitForTimeout(1800);
  ok(await admin.locator('#ov-licenca').isVisible(), 'o modal da licença abre');
  ok(await admin.$$eval('#lic-mods .lic-pi-mod', e => e.length) > 0,
     'e traz os módulos…');
  ok(await admin.locator('#lic-periodo').isVisible(),
     '…e o prazo, no mesmo sítio (era em dois)');

  // ---------- 8. o tecto de convidados, do lado do casal ----------
  // A licença deste casal é «até 80». Enche-se até ao fim e vê-se o que o
  // painel diz e o que o botão faz — que é o que o casal experimenta.
  const lim = 80;
  const nomes = n => Array.from({ length: n }, (_, i) => ({ nome: 'Pessoa ' + (i + 1) }));

  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(1200);
  const comFolga = await casal.$eval('.pc-nums', e => e.textContent.replace(/\s+/g, ' '));
  ok(/de\s*80\s*convidados da licença/.test(comFolga),
     'o painel conta os convidados contra o tecto da licença: ' + comFolga.trim());
  ok(!(await casal.$eval('[onclick="novoConvite()"]', e => e.disabled)),
     'e com lugares livres o botão de novo convite está aberto');
  ok(await casal.$$eval('.pc-aviso', e => e.length) === 0, 'sem aviso enquanto há folga');

  // A cinco lugares do fim, avisa.
  await casal._api('convite_save',
    { nome_exibicao: 'Grupo grande', tipo: 'ambos', lado: 'noiva', membros: nomes(lim - 3) });
  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(1200);
  ok(await casal.$$eval('.pc-aviso', e => e.length) > 0, 'perto do fim, avisa antes de travar');
  ok(/3 por usar/.test(await casal.$eval('.pc-nums', e => e.textContent.replace(/\s+/g, ' '))),
     'e diz quantos lugares faltam');

  // Cheio: o botão fecha-se, e mostra por onde se resolve.
  await casal._api('convite_save',
    { nome_exibicao: 'Os últimos', tipo: 'ambos', lado: 'noivo', membros: nomes(3) });
  await casal.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(1200);
  ok(/sem lugares livres/.test(await casal.$eval('.pc-nums', e => e.textContent)),
     'cheio, o painel di-lo por palavras');
  ok(await casal.$eval('[onclick="novoConvite()"]', e => e.disabled),
     'e o botão de novo convite fecha-se');
  ok(await casal.$$eval('.pc-aviso a[href*="licenca"]', e => e.length) > 0,
     'com o caminho para o reforço à vista');

  // E a função também recusa: o botão desactivado contorna-se, ela não.
  await casal.evaluate(() => novoConvite());
  await casal.waitForTimeout(500);
  ok(!(await casal.locator('#ov-convite').isVisible()),
     'chamar novoConvite() à mão não abre o formulário — a segunda fechadura');

  ok(errs.length === 0, 'sem erros de JavaScript na inscrição: ' + (errs.join(' | ') || 'nenhum'));

  // ---------- limpeza ----------
  await api('casamento_estado&id=' + cid + '&estado=arquivado');
  await api('casamento_apagar&id=' + cid);
  const conta = ((await api('utilizador_lista&q=' + encodeURIComponent(email))).contas || [])
                .find(u => u.email === email);
  if (conta) await api('utilizador_apagar&id=' + conta.id);

  await b.close();
  console.log(f ? `\n${f} falha(s).` : '\nTudo certo.');
  process.exit(f ? 1 : 0);
})();
