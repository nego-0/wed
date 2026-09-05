// A montra da inscrição: as capturas, os planos e os preços do admin.
//
// O que aqui se prova é o que a montra promete e o que dela resulta:
//
//   1. cada módulo mostra-se em imagem, e a imagem é servida mesmo;
//   2. a montra vende planos e mais nada — as fotografias do convite deixaram
//      de se pedir aqui (carregam-se depois; ver chk_convite_fotos);
//   3. a inscrição passa e o pedido chega à administração;
//   4. o casal entra e vê a licença que pediu;
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
  // Uma janela nativa é um beco: não se estiliza, não se valida e um Cancelar a
  // meio deita fora o que já se escreveu. Conta-se quantas aparecem — devem ser
  // zero em toda a área das licenças.
  let nativos = 0;
  admin.on('dialog', d => { nativos++; d.dismiss(); });

  // ---------- 1. as capturas de cada módulo ----------
  const anon = await b.newContext({ viewport: { width: 1280, height: 1000 } })
                      .then(c => c.newPage());
  const errs = []; anon.on('pageerror', e => errs.push(e.message));
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await anon.waitForSelector('#reg-planos .pl-pac', { timeout: 10000 });

  // A página tem de caber numa leitura. Com as cinco capturas de enfiada e os
  // módulos todos abertos chegava aos 5500px — e o botão de submeter perdia-se
  // no fundo, o que fazia a inscrição parecer avariada.
  const altura = await anon.evaluate(() => document.body.scrollHeight);
  ok(altura < 3600, `a montra fechada cabe numa leitura (${altura}px)`);
  ok(await anon.$$eval('.pl-mod', e => e.length) === 0,
     'os módulos avulso não se mostram à partida');
  ok(await anon.locator('.pl-medida-porta').isVisible(),
     'e há uma porta visível para quem quer montar o seu pacote');

  // As capturas estão atrás de «Ver exemplo» — e a galeria de um pacote mostra
  // uma imagem por módulo incluído.
  ok(await anon.$$eval('[data-exemplo-pac]', e => e.length) > 0,
     'cada pacote tem o seu «Ver exemplo»');
  await anon.click('[data-exemplo-pac]');
  await anon.waitForTimeout(700);
  ok(await anon.locator('#pl-lupa.on').isVisible(), 'que abre a galeria do pacote');
  const larg1 = await anon.$eval('#pl-lupa img', i => i.naturalWidth || 0);
  ok(larg1 > 0, `e a imagem carrega mesmo (${larg1}px)`);
  const n1 = await anon.$$eval('.pl-lupa-nav button', e => e.length);
  ok(n1 === 2, 'com navegação entre os módulos do pacote');
  const antes = await anon.$eval('#pl-lupa img', i => i.getAttribute('src'));
  await anon.click('#pl-lupa-seg'); await anon.waitForTimeout(400);
  ok(antes !== await anon.$eval('#pl-lupa img', i => i.getAttribute('src')),
     'e «seguinte» muda mesmo de imagem');
  await anon.keyboard.press('Escape'); await anon.waitForTimeout(300);
  ok(!(await anon.locator('#pl-lupa.on').isVisible()), 'Escape fecha a galeria');
  // Ver o exemplo não pode escolher o pacote por engano.
  ok(await anon.evaluate(() => Planos.escolha().pacote) !== 0,
     'e ver o exemplo não mexeu na escolha');

  // Abre-se o plano à medida: é aí que vivem os módulos.
  await anon.click('#pl-abrir-medida'); await anon.waitForTimeout(800);
  ok(await anon.$$eval('.pl-mod', e => e.length) >= 3, 'aberta a porta, aparecem os módulos');
  ok(await anon.$$eval('.pl-exemplo', e => e.length) > 0,
     'cada módulo com o seu botão «Ver exemplo»');
  await anon.click('.pl-mod[data-chave="convidados"] .pl-exemplo');
  await anon.waitForTimeout(600);
  ok(await anon.locator('#pl-lupa.on').isVisible(), 'que abre a captura desse módulo');
  await anon.keyboard.press('Escape'); await anon.waitForTimeout(300);

  // E os preços mostram o desconto do prazo, com o proporcional riscado.
  const riscados = await anon.$$eval('.pl-riscado', e => e.length);
  ok(riscados > 0, `os preços mostram o valor padrão riscado (${riscados})`);
  const par = await anon.$eval('.pl-mod[data-chave="convidados"] .pl-esc:not(.on) .pl-riscado',
    el => ({ risc: el.textContent, agora: el.nextElementSibling.textContent }));
  const num = t => parseFloat(String(t).replace(/[^\d,]/g, '').replace(',', '.'));
  ok(num(par.risc) > num(par.agora),
     `e o riscado é MAIOR do que o que se paga (${par.risc.trim()} → ${par.agora.trim()})`);

  // ---------- 2 e 3. a montra vende planos, e mais nada ----------
  // Houve aqui um bloco de fotografias: o escalão sem edição fixava-as no acto
  // da compra, e por isso pedia-as antes de haver casamento. As fotografias
  // passaram a carregar-se na página do convite digital, quando o casal quiser
  // e as vezes que quiser — ver chk_convite_fotos. A montra não lhes toca.
  const escolher = (mod, rx) => anon.evaluate(({ mod, rx }) => {
    const l = [...document.querySelectorAll('.pl-mod[data-chave="' + mod + '"] .pl-esc')]
              .find(x => new RegExp(rx).test(x.textContent));
    if (l) { l.querySelector('input').click(); return true; }
    return false;
  }, { mod, rx });

  await escolher('digital', 'com edição');
  await anon.waitForTimeout(600);
  ok(await anon.$$eval('.pl-fotos, .pl-sec, .pl-ft', e => e.length) === 0,
     'escolhido o convite digital, a montra não pede fotografia nenhuma');

  await escolher('convidados', 'Até 80');
  await anon.waitForTimeout(500);

  // ---------- 4. a inscrição passa, e o pedido chega ----------
  await anon.fill('#noiva', 'Nara' + marca); await anon.fill('#noivo', 'Kito' + marca);
  await anon.fill('#email', email);
  await anon.fill('#senha', SENHA); await anon.fill('#confirmar', SENHA);
  await anon.fill('#data', '2027-10-09');

  await anon.check('#reg-aceite');
  await anon.click('#btn'); await anon.waitForTimeout(1800);
  ok(await anon.locator('#obrigado').isVisible(), 'a inscrição passa');

  const peds = await api('lic_pedidos&estado=pendente');
  const ped = (peds.pedidos || []).find(x => (x.casamento_nome || '').includes(marca));
  ok(!!ped, 'o pedido chega à administração');
  const cid = ped.casamento_id;

  const casal = await entrar(await b.newContext(), email, SENHA);
  const defs = await casal._api('lic_estado');
  ok(defs.success, 'o casal entra e vê a sua licença');

  // ---------- 5. o admin mexe nos preços ao montar um pacote ----------
  await admin.evaluate(() => verVista('licencas')); await admin.waitForTimeout(1400);
  await admin.evaluate(() => licVista('pacotes')); await admin.waitForTimeout(1200);
  const pid = await admin.evaluate(() => LIC_CAT.pacotes[0].id);
  await admin.evaluate(id => licPacoteItens(id), pid);
  await admin.waitForTimeout(800);
  ok(nativos === 0, 'abrir o pacote não usa janelas nativas');
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

  // ---------- 9. as janelas do admin: formulários, e não prompt() ----------
  await admin.evaluate(() => verVista('licencas')); await admin.waitForTimeout(1200);
  await admin.evaluate(() => licVista('prazos'));   await admin.waitForTimeout(1200);
  ok(await admin.$$eval('.lic-pz', e => e.length) >= 2,
     'os prazos mostram-se em cartões, com o efeito do factor');
  ok(await admin.$$eval('.lic-pz-barra i', e => e.length) >= 2,
     'e cada um com a barra do preço POR MÊS — que é o que mostra se compensa');

  const pzId = await admin.evaluate(() => LIC_CAT.prazos[1].id);
  await admin.evaluate(id => licPrazoEditar(id), pzId);
  await admin.waitForTimeout(700);
  ok(await admin.locator('#lic-janela.on').isVisible(), 'editar um prazo abre um formulário');
  ok(await admin.$$eval('.lic-form .lic-f-c', e => e.length) >= 5,
     'com todos os campos à vista de uma vez');
  const provaAntes = await admin.$eval('#lic-fator-prova', e => e.textContent);
  ok(/factor/i.test(provaAntes), 'e a prova em números do que o factor faz');
  await admin.fill('#lf-fator', '3.0'); await admin.waitForTimeout(400);
  ok(provaAntes !== await admin.$eval('#lic-fator-prova', e => e.textContent),
     'que acompanha o que se escreve');
  ok(/mau/.test(await admin.$eval('.lic-fp-veredito', e => e.className)),
     'e avisa quando o factor torna o prazo longo mais caro por mês');
  await admin.keyboard.press('Escape'); await admin.waitForTimeout(400);
  ok(!(await admin.locator('#lic-janela.on').isVisible()), 'Escape fecha sem guardar');

  // Revogar exige um motivo, e di-lo dentro da própria janela.
  await admin.evaluate(() => verVista('casamentos')); await admin.waitForTimeout(800);
  await admin.evaluate(() => filtrarCasamentos('todos', 1)); await admin.waitForTimeout(1500);
  await admin.evaluate(id => { licRevogarDe(id, 'Prova'); }, cid);
  await admin.waitForTimeout(700);
  ok(await admin.locator('#lf-motivo').isVisible(), 'revogar pede o motivo num campo próprio');
  await admin.click('#lic-jo'); await admin.waitForTimeout(600);
  ok(await admin.locator('#lic-janela.on').isVisible(),
     'e sem motivo não fecha — corrige-se ali mesmo');
  ok(/motivo/i.test(await admin.$eval('#lic-jerro', e => e.textContent)),
     'com o aviso dentro da janela: '
     + (await admin.$eval('#lic-jerro', e => e.textContent)).trim().slice(0, 50));
  await admin.keyboard.press('Escape'); await admin.waitForTimeout(400);

  ok(nativos === 0, `nenhuma janela nativa em toda a área das licenças (${nativos})`);
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
