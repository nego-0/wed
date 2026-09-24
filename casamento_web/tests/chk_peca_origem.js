// O admin designa qual modelo é a «peça de origem».
//
// Duas mecânicas convivem sem confusão: a peça de origem (o ponto de regresso,
// id 0 nas versões) e a lista de modelos da casa. O admin escolhe qual modelo
// CONSTITUI a peça de origem — e é o nome DELE que a peça passa a dar, e o
// desenho DELE que um «voltar à origem» repõe. Só um modelo publicado e de
// todos pode sê-la; e há sempre volta ao automático (o de fábrica, pelo desenho).
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext();
  const p = await ctx.newPage();
  const olho = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });
  const rotulo = async (pagina) => {
    await olho.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    return (await olho.textContent('.estado-linha')).replace(/\s+/g, ' ').trim();
  };

  const marca = 'zz' + Date.now().toString().slice(-6);
  // Um casamento aberto abre-se AQUI, e outro mais à frente. A razão é o que
  // a prova tem para dizer: a peça de origem de um casal é a que a casa tinha
  // quando ele nasceu, e não muda quando o admin designa outra. Para ver a
  // designação nova a valer, é preciso um casal nascido depois dela.
  const casais = [];
  async function abrirCasalNovo(sufixo) {
    const c = await api('casamento_criar',
                        { nome: 'ZZ Origem ' + marca + sufixo, noiva: 'Iris', noivo: 'Ivo' });
    casais.push(c.id);
    await api('casamento_abrir&id=' + c.id, {});
    return c.id;
  }
  await abrirCasalNovo('a');

  const listaDig = () => api('modelo_lista&ambito=digital').then(d => d.modelos || []);
  const nomeOrigem = () => api('versao_lista&ambito=digital').then(d =>
    (d.versoes || []).find(v => v.padrao) || {});

  // ---------- 0. de origem, o Isabel & Abednego é a peça de origem ----------
  let mods = await listaDig();
  const isabel = mods.find(m => m.nome === 'Isabel & Abednego');
  ok(isabel && isabel.de_origem === true,
     'à partida, «Isabel & Abednego» está assinalado como peça de origem');
  ok(mods.filter(m => m.de_origem).length === 1,
     'e é o único — uma só peça de origem por peça');
  ok((await nomeOrigem()).nome === 'Isabel & Abednego',
     'a linha de origem das versões dá o nome desse modelo');
  ok(isabel && isabel.de_fabrica === true && isabel.protegido === true,
     'o ficheiro de fábrica está assinalado e protegido de apagar');

  // ---------- 0b. o ficheiro de origem de fábrica NÃO se apaga ----------
  const apFab = await api('modelo_apagar&id=' + isabel.id, {});
  ok(apFab && apFab.success === false && /origem de fábrica/.test(apFab.message || ''),
     'apagar o ficheiro de origem de fábrica é recusado');

  // ---------- 1. o admin passa a origem para outro modelo ----------
  const borgonha = mods.find(m => m.nome === 'Borgonha');
  ok(!!borgonha, 'a casa tem o modelo «Borgonha» (publicado, de todos)');
  const def = await api('modelo_pecaorigem&ambito=digital&id=' + borgonha.id, {});
  ok(def && def.success && def.nome === 'Borgonha',
     'o admin designa «Borgonha» como peça de origem');

  mods = await listaDig();
  ok(mods.find(m => m.id === borgonha.id).de_origem === true,
     'agora é «Borgonha» que aparece assinalado');
  ok(mods.find(m => m.id === isabel.id).de_origem === false,
     'e o «Isabel & Abednego» deixa de o estar');
  ok(mods.filter(m => m.de_origem).length === 1, 'continua a ser um só');

  // O casal que já cá estava fica com o que tinha: foi esse convite que ele
  // viu, e é esse que compra. A designação nova é para quem vier a seguir.
  ok((await nomeOrigem()).nome === 'Isabel & Abednego',
     'o casal que já cá estava continua com «Isabel & Abednego» — o que viu ao nascer');
  ok(mods.find(m => m.id === isabel.id).de_origem_minha === true,
     'e a lista assinala-lhe essa como a SUA peça de origem');

  // Um casal nascido depois leva a designação nova.
  await abrirCasalNovo('b');
  ok((await nomeOrigem()).nome === 'Borgonha',
     'um casal nascido depois leva «Borgonha» — a linha de origem passa a dá-la');
  // Designado, «Borgonha» fica protegido; e o ficheiro de fábrica continua protegido.
  const bMarc = mods.find(m => m.id === borgonha.id);
  const iMarc = mods.find(m => m.id === isabel.id);
  ok(bMarc && bMarc.protegido === true, 'o modelo designado fica protegido de apagar');
  ok(iMarc && iMarc.de_fabrica === true && iMarc.protegido === true,
     'e o ficheiro de fábrica continua protegido, ainda que outro seja a origem');
  const apDes = await api('modelo_apagar&id=' + borgonha.id, {});
  ok(apDes && apDes.success === false && /definido como peça de origem/.test(apDes.message || ''),
     'apagar o modelo designado como origem é recusado');
  const apFab2 = await api('modelo_apagar&id=' + isabel.id, {});
  ok(apFab2 && apFab2.success === false,
     'e o ficheiro de fábrica continua sem se poder apagar');

  // ---------- 2. «voltar à origem» repõe o desenho de «Borgonha» ----------
  await api('versao_aplicar&ambito=digital&id=0', {});
  const linha = await rotulo('digital.php');
  console.log('   linha após voltar à origem:', JSON.stringify(linha.slice(0, 48)));
  ok(/Borgonha/.test(linha) && !/Original/.test(linha),
     'voltar à origem deixa a peça em «Borgonha» — o modelo de origem designado');
  const vl = await api('versao_lista&ambito=digital');
  const padr = (vl.versoes || []).find(v => v.padrao);
  ok(padr && padr.em_vigor === true,
     'e a linha de origem consta como em vigor');
  ok(mods && (await listaDig()).find(m => m.id === borgonha.id).em_vigor === true,
     'nos modelos, «Borgonha» consta como o modelo em vigor');

  // ---------- 3. não se pode designar um modelo não publicado/de-alguns ----------
  const priv = await api('modelo_criar', { nome: 'Reservado ' + marca,
                         descricao: 'só para alguns', ambito: 'digital', visivel: false });
  const recusa = await api('modelo_pecaorigem&ambito=digital&id=' + priv.id, {});
  ok(recusa && recusa.success === false,
     'um modelo por publicar não pode ser peça de origem');

  // ---------- 4. volta ao automático (id=0) ----------
  const limpar = await api('modelo_pecaorigem&ambito=digital&id=0', {});
  ok(limpar && limpar.success && limpar.id === 0,
     'o admin devolve a designação ao automático');
  ok(limpar.nome === 'Isabel & Abednego',
     'e o automático volta a achar «Isabel & Abednego» pelo desenho de fábrica');
  const mods2 = await listaDig();
  ok(mods2.find(m => m.id === isabel.id).de_origem === true,
     'que volta a aparecer assinalado como peça de origem');
  ok(mods2.find(m => m.id === borgonha.id).protegido === false,
     'e «Borgonha», já sem a designação, volta a poder apagar-se');

  // ---------- limpeza: repor a designação semeada e apagar o casal ----------
  await api('modelo_pecaorigem&ambito=digital&id=' + isabel.id, {});   // repõe o estado semeado
  await api('modelo_apagar&id=' + priv.id, {});
  // Um casamento criado pela casa nasce com licença: revoga-se primeiro, que é
  // a ordem que o sistema exige, e só depois se arquiva e se apaga. Sem isto os
  // casais da prova ficavam para trás — e um casamento que fica agarra a peça
  // de origem que levou, e ela deixa de se poder apagar.
  for (const id of casais) {
    await api('lic_revogar', { casamento: id, motivo: 'fim da prova automática' });
    await api('casamento_estado&id=' + id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + id, {});
  }
  const sobrou = (await api('casamento_lista&estado=todos')).casamentos || [];
  ok(!sobrou.some(c => casais.includes(c.id)),
     'a prova leva consigo os casamentos que abriu');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
