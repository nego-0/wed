// A porta pública com vários casamentos.
//
// O convidado não tem sessão: chega com um código na mão e é esse código que
// diz de quem é o convite. Esta prova trata das quatro coisas que podem correr
// mal quando a casa serve mais do que um casal:
//
//   1. o convite público resolve o casamento certo (e veste-o com o desenho
//      desse casal, não com o do vizinho);
//   2. um casamento que não está ativo não serve convites a ninguém;
//   3. o porteiro de um casamento não lê o convite de outro, nem que lhe
//      passem o QR alheio pela câmara;
//   4. os QR levam o endereço público do casamento, e não o endereço por onde
//      quem os imprimiu entrou.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: 'POST', headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- dois casamentos, cada um com o seu convite e o seu nome ----------
  const casA = await api('casamento_criar', { nome: 'ZZ Público A ' + marca, noiva: 'Ana', noivo: 'Alberto' });
  const casB = await api('casamento_criar', { nome: 'ZZ Público B ' + marca, noiva: 'Bia', noivo: 'Bruno' });

  await api('casamento_abrir&id=' + casA.id);
  await api('defs_save', { defs: { 'casal.noiva': 'AnaMarca' + marca, 'casal.noivo': 'AlbertoMarca' + marca } });
  const cA = await api('convite_save', { nome_exibicao: 'ZZ Convidado de A', tipo: 'ambos',
                                         lado: 'ambos', membros: ['Alguém A'] });

  await api('casamento_abrir&id=' + casB.id);
  await api('defs_save', { defs: { 'casal.noiva': 'BiaMarca' + marca, 'casal.noivo': 'BrunoMarca' + marca } });
  const cB = await api('convite_save', { nome_exibicao: 'ZZ Convidado de B', tipo: 'ambos',
                                         lado: 'ambos', membros: ['Alguém B'] });

  const codA = cA.convite.codigo, codB = cB.convite.codigo;
  console.log('   códigos:', codA, '(A) ·', codB, '(B)');

  // ---------- 1. cada código traz o seu casamento inteiro ----------
  const publico = await (await b.newContext()).newPage();   // sem sessão nenhuma
  await publico.goto(BASE + '/convite.php?c=' + codA, { waitUntil: 'networkidle' });
  const txtA = await publico.locator('body').innerText();
  ok(txtA.includes('ZZ Convidado de A'), 'o convite público de A mostra o convidado de A');
  ok(txtA.includes('AnaMarca' + marca), 'e veste-o com os nomes do casal A');
  ok(!txtA.includes('BiaMarca' + marca), 'sem nada do casal B');

  await publico.goto(BASE + '/convite.php?c=' + codB, { waitUntil: 'networkidle' });
  const txtB = await publico.locator('body').innerText();
  ok(txtB.includes('ZZ Convidado de B') && txtB.includes('BiaMarca' + marca),
     'e o de B mostra o de B, com os nomes do casal B');

  // ---------- 2. casamento por aprovar (ou suspenso) não serve convites ----------
  await api('casamento_estado&id=' + casB.id + '&estado=pendente');
  const resp = await publico.goto(BASE + '/convite-digital.php?c=' + codB, { waitUntil: 'networkidle' });
  console.log('   convite de casamento pendente devolveu', resp.status());
  ok(resp.status() === 404, 'um casamento por aprovar não serve o convite digital');

  const rsvpFechado = await publico.evaluate(async (c) => {
    const r = await fetch('api.php?action=rsvp_submit', { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ codigo: c, decisao: 'sim', confirmados: 1 }) });
    return r.json();
  }, codB);
  console.log('   RSVP nesse convite:', JSON.stringify(rsvpFechado));
  ok(rsvpFechado && rsvpFechado.success === false, 'e também não aceita confirmações de presença');
  await api('casamento_estado&id=' + casB.id + '&estado=ativo');

  // Um código que não abre nada não pode servir de montra ao primeiro casal
  // da casa: sem saber de quem é o convite, não se nomeia ninguém.
  for (const pag of ['convite.php', 'convite-digital.php']) {
    await publico.goto(BASE + '/' + pag + '?c=NAOEXISTE', { waitUntil: 'networkidle' });
    const txt = await publico.locator('body').innerText();
    ok(!/AnaMarca|BiaMarca|Isabel|Abednego/.test(txt) && !txt.includes('wa.me'),
       pag + ' com um código inexistente não nomeia casal nenhum nem dá contactos');
  }

  // ---------- 3. a porta só conhece os convites da sua casa ----------
  await api('casamento_abrir&id=' + casA.id);
  const meu    = await api('porta_buscar&q=' + codA);
  const alheio = await api('porta_buscar&q=' + codB);
  console.log('   porta de A a ler o código de B:', JSON.stringify(alheio));
  ok(meu && meu.success && meu.convite && meu.convite.nome_exibicao === 'ZZ Convidado de A',
     'a porta de A lê o convite de A');
  ok(alheio && alheio.success === false, 'e recusa o código de B, mesmo lido pela câmara');
  ok(!JSON.stringify(alheio).includes('ZZ Convidado de B'),
     'sem sequer deixar escapar o nome de quem estava nesse convite');

  // O mesmo pelo link inteiro: o QR traz um endereço, não só o código.
  const porLink = await api('porta_buscar&q=' + encodeURIComponent(BASE + '/convite.php?c=' + codB));
  ok(porLink && porLink.success === false, 'nem quando lhe passam o endereço completo do QR alheio');

  // ---------- 4. o endereço público é de cada casamento ----------
  const mau = await api('casamento_endereco', { endereco: 'não é um endereço' });
  ok(mau && mau.success === false, 'um endereço mal escrito é recusado');

  const posto = await api('casamento_endereco', { endereco: 'https://casamento-a-' + marca + '.exemplo.pt/' });
  ok(posto && posto.success && posto.endereco === 'https://casamento-a-' + marca + '.exemplo.pt',
     'o endereço público guarda-se sem a barra final');

  await admin.goto(BASE + '/impressos.php', { waitUntil: 'networkidle' });
  const links = await admin.$$eval('canvas.qr', els => els.map(e => e.dataset.link));
  console.log('   QR impressos:', JSON.stringify(links));
  ok(links.length > 0 && links.every(l => l.startsWith('https://casamento-a-' + marca + '.exemplo.pt/')),
     'os QR das etiquetas levam o endereço fixado, não aquele por onde se entrou');

  // E o casamento do lado continua a deduzir o seu, sem herdar o de A.
  await api('casamento_abrir&id=' + casB.id);
  await admin.goto(BASE + '/impressos.php', { waitUntil: 'networkidle' });
  const linksB = await admin.$$eval('canvas.qr', els => els.map(e => e.dataset.link));
  ok(linksB.length > 0 && linksB.every(l => l.startsWith(BASE + '/')),
     'e o casamento ao lado não herda o endereço do vizinho');

  // As provas correm em 127.0.0.1: é exatamente o caso que a barra existe para
  // apanhar — imprimir cartões cujo QR só abre na máquina de quem os imprimiu.
  const barraB = await admin.locator('.end-barra').innerText();
  console.log('   barra em B:', barraB.replace(/\s+/g, ' ').slice(0, 120));
  ok(/só existe nesta máquina/.test(barraB),
     'a barra avisa, antes de imprimir, que o endereço só existe nesta máquina');

  // E, no casamento onde o endereço foi fixado, não há aviso nenhum a dar.
  await api('casamento_abrir&id=' + casA.id);
  await admin.goto(BASE + '/impressos.php', { waitUntil: 'networkidle' });
  ok(!(await admin.locator('.end-barra').getAttribute('class')).includes('aviso'),
     'e cala-se quando o endereço definitivo já está fixado');

  // ---------- o manifesto da porta diz de que casamento é ----------
  await api('casamento_abrir&id=' + casA.id);
  const man = await admin.evaluate(async () => (await fetch('manifest.php')).json());
  console.log('   manifesto:', man.name);
  ok((man.name || '').includes('AnaMarca' + marca),
     'o manifesto da aplicação da porta nomeia o casamento aberto');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1');
  for (const id of [casA.id, casB.id]) {
    // Apagar exige arquivar antes — o mesmo caminho que a página faz.
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
