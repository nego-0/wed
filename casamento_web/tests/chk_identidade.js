// Os dados dos noivos chegam sozinhos a todo o lado.
//
// Um casal inscreve-se, ou o admin cria-lhe o casamento, e escreve os seus
// nomes e a sua data. Até aqui isso ficava guardado numa ficha e mais nada: as
// peças todas — convite, cartão, monograma, cabeçalho, manifesto — saíam de um
// casal só, o que está no config.php. Um casal novo abria o seu convite e via
// lá o nome de outras pessoas.
//
// O que se prova aqui:
//   1. um casamento acabado de criar já se vê com os SEUS nomes, em toda a parte;
//   2. mudar a ficha na página de gestão muda tudo, sem se ir ao editor;
//   3. quem escreveu um nome diferente NO CONVITE fica a saber, e ao guardar a
//      ficha essa cópia é retirada;
//   4. os dados do evento editam-se na gestão, sem passar pelo editor;
//   5. um casamento não vê nem toca na ficha do outro.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, u, p) => {
  const g = await ctx.newPage();
  await g.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await g.fill('input[name=utilizador]', u); await g.fill('input[name=senha]', p);
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
  const NOIVA = 'Rita' + marca, NOIVO = 'Rui' + marca;

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. nasce com os seus nomes, sem ninguém escrever nada ----------
  const cas = await api('casamento_criar', { nome: 'ZZ ' + NOIVA + ' & ' + NOIVO,
                                             noiva: NOIVA, noivo: NOIVO });
  await api('casamento_abrir&id=' + cas.id);
  const conv = await api('convite_save', { nome_exibicao: 'ZZ Convidado ' + marca,
                                           tipo: 'ambos', lado: 'ambos', membros: ['Alguém'] });
  const codigo = conv.convite.codigo;

  const mono = 'R&R';   // as iniciais dos dois nomes
  const paginas = [
    ['index.php',          'o painel'],
    ['graficas.php',       'a lista da gráfica'],
    ['impressos.php',      'as etiquetas dos convites físicos'],
    ['gestao.php',         'a página de gestão'],
  ];
  for (const [pag, quem] of paginas) {
    await admin.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    const txt = await admin.locator('body').innerText();
    ok(txt.includes(NOIVA) && txt.includes(NOIVO), quem + ' mostra os nomes deste casal');
    // A linha de estado nomeia o DESENHO — o modelo de origem «Isabel &
    // Abednego» —, que por acaso tem os nomes do casal semente. Isso não é a
    // identidade a fugir para a peça deste casal: tira-se antes de a procurar.
    const estado = await admin.locator('.estado-peca, .estado-linha').allInnerTexts().catch(() => []);
    let corpo = txt;
    for (const e of estado) corpo = corpo.split(e).join('');
    ok(!/Isabel|Abednego/.test(corpo), 'e não mostra os do casal do config.php — ' + quem);
  }

  // O monograma sai das iniciais, sem ninguém o escrever.
  await admin.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok((await admin.locator('.topo .monograma').innerText()).trim() === mono,
     'o monograma do cabeçalho faz-se sozinho das iniciais (' + mono + ')');

  // O convite que o convidado abre, sem sessão nenhuma.
  const publico = await (await b.newContext()).newPage();
  await publico.goto(BASE + '/convite.php?c=' + codigo, { waitUntil: 'networkidle' });
  const txtPub = await publico.locator('body').innerText();
  ok(txtPub.includes(NOIVA) && txtPub.includes(NOIVO),
     'o convite que o convidado abre está no nome deste casal');
  ok(!/Isabel|Abednego/.test(txtPub), 'e não no de outro');

  // O manifesto da aplicação da porta, e a versão "Original" do convite.
  const man = await admin.evaluate(async () => (await fetch('manifest.php')).json());
  ok((man.name || '').includes(NOIVA), 'o manifesto da porta também');

  // ---------- 2. mudar a ficha muda tudo ----------
  const NOVA = 'Rosa' + marca;
  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await admin.fill('#f-noiva', NOVA);
  await admin.fill('#f-data', '2027-05-08');
  await admin.click('button:has-text("Guardar")');
  await admin.waitForTimeout(1400);

  await admin.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const depois = await admin.locator('.topo').innerText();
  console.log('   cabeçalho depois de mudar a ficha:', depois.replace(/\s+/g, ' ').slice(0, 80));
  ok(depois.includes(NOVA), 'mudar o nome na gestão muda o cabeçalho');
  ok(depois.includes('8 de Maio de 2027'), 'e mudar a data muda a data por extenso');

  await publico.goto(BASE + '/convite.php?c=' + codigo, { waitUntil: 'networkidle' });
  ok((await publico.locator('body').innerText()).includes(NOVA),
     'e o convite do convidado acompanha, sem se ir ao editor');

  // ---------- 3. um nome escrito por cima, no editor ----------
  const PORCIMA = 'Nome de convite ' + marca;
  await api('defs_save', { defs: { 'casal.noiva': PORCIMA } });
  await publico.goto(BASE + '/convite.php?c=' + codigo, { waitUntil: 'networkidle' });
  ok((await publico.locator('body').innerText()).includes(PORCIMA),
     'o que se escreve no editor ganha à ficha — é isso que o editor serve');

  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  const aviso = await admin.locator('.porcima').innerText().catch(() => '');
  console.log('   aviso na gestão:', aviso.replace(/\s+/g, ' ').slice(0, 90));
  ok(/escritos por cima/.test(aviso), 'a gestão avisa que o convite tem o nome escrito por cima');

  await admin.click('button:has-text("Guardar")');
  await admin.waitForTimeout(1400);
  await publico.goto(BASE + '/convite.php?c=' + codigo, { waitUntil: 'networkidle' });
  const txtFim = await publico.locator('body').innerText();
  ok(txtFim.includes(NOVA) && !txtFim.includes(PORCIMA),
     'e guardar a ficha retira essa cópia, como a página diz que faz');

  // A versão "Original" de cada casamento é a DELE. Prova-se pelo que ela faz:
  // escreve-se outro nome no convite, repõe-se o original, e o que volta é o
  // nome da ficha deste casal — não o do config.php.
  await api('defs_save', { defs: { 'casal.noiva': 'Provisorio' + marca } });
  const rep = await api('versao_aplicar&id=0&ambito=digital');
  ok(rep && rep.success, 'repor a versão "Original" do convite digital');
  await publico.goto(BASE + '/convite.php?c=' + codigo, { waitUntil: 'networkidle' });
  const txtRep = await publico.locator('body').innerText();
  ok(txtRep.includes(NOVA) && !/Isabel|Provisorio/.test(txtRep),
     'e o que volta é o nome deste casal, não o do config.php');

  // ---------- 4. os dados do evento, sem passar pelo editor ----------
  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await admin.fill('#d-evento-local', 'Quinta ' + marca);
  await admin.fill('#d-evento-cidade', 'Lobito');
  await admin.locator('button:has-text("Guardar")').nth(1).click();
  await admin.waitForTimeout(1000);
  await publico.goto(BASE + '/convite-digital.php?c=' + codigo, { waitUntil: 'networkidle' });
  const txtDig = await publico.locator('body').innerText();
  ok(txtDig.includes('Quinta ' + marca) && txtDig.includes('Lobito'),
     'o local e a cidade escritos na gestão aparecem no convite digital');

  // ---------- 4b. os dados do evento entram no primeiro registo ----------
  // Um casamento que nasce sem os seus dados fica com os do config.php à
  // espera de que alguém se lembre — e o casal manda convites com a morada de
  // outra pessoa. Perguntar tudo à nascença é o que evita isso.
  const nascido = await api('casamento_criar', {
    nome: 'ZZ Completo ' + marca, noiva: 'Sara', noivo: 'Simão', data: '2027-09-11',
    hora: '19:00', local: 'Salão ' + marca, cidade: 'Benguela', convidados: '240',
    whatsapp: '244911222333',
    civil_hora: '09:30', civil_local: 'Conservatória ' + marca,
    religiosa_hora: '16:00', religiosa_local: 'Igreja ' + marca,
  });
  console.log('   criado com dados:', JSON.stringify(nascido));
  ok(nascido.dados_do_evento >= 8, 'criar um casamento guarda logo os dados do evento');

  await api('casamento_abrir&id=' + nascido.id);
  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  const campos = await admin.evaluate(() => {
    const v = {};
    document.querySelectorAll('[data-chave]').forEach(e => { v[e.dataset.chave] = e.value; });
    return v;
  });
  console.log('   na gestão:', JSON.stringify(campos));
  ok(campos['evento.local'] === 'Salão ' + marca && campos['evento.cidade'] === 'Benguela',
     'o local e a cidade ficaram gravados');
  ok(campos['evento.convidados'] === '240', 'e o número de convidados que se espera');
  ok(campos['evento.civil_hora'] === '09:30' && campos['evento.civil_local'].includes('Conservatória'),
     'a cerimónia civil, com hora e local');
  ok(campos['evento.religiosa_hora'] === '16:00' && campos['evento.religiosa_local'].includes('Igreja'),
     'e a religiosa também');

  // O número de convidados é o teto da barra do painel — era 150 para todos.
  const st = await api('convite_list');
  ok(st.stats.capacidade === 240, 'o teto do painel passa a ser o deste casal (' + st.stats.capacidade + ')');

  // As cerimónias são opcionais: um casamento sem elas não as anuncia.
  const semCerimonia = await api('casamento_criar', { nome: 'ZZ Sem cerimónias ' + marca,
                                                      noiva: 'Tina', noivo: 'Tó' });
  await api('casamento_abrir&id=' + semCerimonia.id);
  const defsSem = (await admin.evaluate(async () =>
    (await fetch('api.php?action=dados_exportar&ambito=casamento')).json())).casamentos[0].definicoes;
  ok(!defsSem['evento.religiosa_hora'], 'sem cerimónia religiosa, não fica hora nenhuma escrita');

  // ---------- 5. cada casamento com a sua ficha ----------
  const outro = await api('casamento_criar', { nome: 'ZZ Outro ' + marca, noiva: 'Vera', noivo: 'Vasco' });
  await api('casamento_abrir&id=' + outro.id);
  await admin.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  const fichaOutro = await admin.evaluate(() => ({
    noiva: document.getElementById('f-noiva').value,
    noivo: document.getElementById('f-noivo').value,
  }));
  console.log('   ficha do casamento vizinho:', JSON.stringify(fichaOutro));
  ok(fichaOutro.noiva === 'Vera' && fichaOutro.noivo === 'Vasco',
     'o casamento do lado tem a sua própria ficha');
  ok((await admin.locator('.topo .monograma').innerText()).trim() === 'V&V',
     'e o seu próprio monograma');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1');
  for (const id of [cas.id, outro.id, nascido.id, semCerimonia.id]) {
    // Apagar exige arquivar antes — o mesmo caminho que a página faz.
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }
  await admin.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  ok((await admin.locator('.topo').innerText()).includes('Isabel'),
     'e o casamento de sempre continua a ser o que era');

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
