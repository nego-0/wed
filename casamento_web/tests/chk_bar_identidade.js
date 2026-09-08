// Um telemóvel, uma pessoa.
//
// Sem link no convite, o nome deixou de ser segredo: qualquer pessoa sentada
// a uma mesa pode escrever quatro letras do nome do padrinho. Isso não tem
// remendo técnico — um nome, numa festa, é coisa pública —, e a decisão foi
// tomada de olhos abertos (docs/modulo-bar.md §5.2).
//
// O que fica de pé é o telemóvel: quem quiser pedir por outro tem de o fazer
// do SEU aparelho, o que deixa rasto. Esta prova defende as três regras dessa
// barreira — livre dentro do convite, assinalado entre convites, fechável de
// vez — e defende sobretudo a decisão sobre o IP: em `registo` e `aviso` ele
// NÃO tranca nada. Num salão com wi-fi partilhado, «um IP, um convidado»
// trancaria a festa ao primeiro que pedisse. A culpa não é da regra, é de NAT.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const casa = await b.newContext({ viewport: { width: 1280, height: 950 } });
  const p = await casa.newPage();
  p.on('pageerror', e => errs.push('copa: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await p.evaluate(async () => { await window.api('bar_abrir', { method: 'POST', body: '{}' }); });

  const token = await p.evaluate(() => window.BAR_MESAS[0].token);
  const defs = (o) => p.evaluate(async (o) =>
    await window.api('bar_defs', { method: 'POST', body: JSON.stringify(o) }), o);

  // A prova precisa de DOIS convites — o semeado tem um só, e sem o segundo
  // as regras que mais interessam (trocar entre convites) passavam ao lado
  // sem ninguém dar por isso. Constrói-se o mundo de que se precisa.
  await p.evaluate(async () => {
    const d = await window.api('convite_list&busca=ZZ%20Vizinhos', { silencioso: true });
    const ja = (d && d.convites || []).find(c => c.nome_exibicao === 'ZZ Vizinhos');
    if (ja) return;
    await window.api('convite_save', { method: 'POST', body: JSON.stringify({
      nome_exibicao: 'ZZ Vizinhos', tipo: 'digital', lado: 'noivo',
      membros: [{ nome: 'ZZ Vizinho Um' }] }) });
  });
  const pessoas = await p.evaluate(async () =>
    (await window.api('bar_procurar_pessoal&q=')).nomes);
  const doMesmo = pessoas.filter(x => x.convite === pessoas[0].convite);
  ok(doMesmo.length >= 2, 'a lista de prova tem duas pessoas do mesmo convite');
  const A = doMesmo[0], B = doMesmo[1];

  const tel = await b.newContext({ viewport: { width: 390, height: 844 } });
  const c = await tel.newPage();
  c.on('pageerror', e => errs.push('bebidas: ' + e.message));
  await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await c.waitForTimeout(600);
  const sou = (id) => c.evaluate(async ([t, id]) =>
    await (await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: id }) })).json(), [token, id]);

  // ============ 1. dentro do mesmo convite é livre ============
  ok((await sou(A.id)).success === true, 'escolher-se prende o telemóvel ao nome');
  const irmao = await sou(B.id);
  ok(irmao.success === true,
     'trocar para outro nome do MESMO convite é livre — o telemóvel da família é um só');

  // ============ 2. para outro convite, assinalado ============
  const doOutro = pessoas.filter(x => x.convite !== A.convite)[0];
  if (doOutro) {
    ok((await sou(doOutro.id)).success === true,
       'trocar para outro convite passa, com bar.trocar_nome ligado');
    const band = await p.evaluate(async () => (await window.api('bar_estado')).bandeiras);
    ok(band.some(x => x.tipo === 'trocas'),
       'mas fica assinalado à copa: ' + JSON.stringify(band.filter(x => x.tipo === 'trocas')[0]));

    // ============ 3. e pode fechar-se de vez ============
    await defs({ 'bar.trocar_nome': '0' });
    const barrado = await sou(A.id);
    ok(barrado.success === false, 'com bar.trocar_nome desligado, a troca é recusada');
    ok(/Chame um garçom/i.test(barrado.message || ''),
       'e manda chamar quem resolve: «' + barrado.message + '»');
    await defs({ 'bar.trocar_nome': '1' });
    ok((await sou(A.id)).success === true, 'religado, volta a passar');
  } else {
    console.log('(saltado: a lista de prova só tem um convite)');
  }

  // ============ 4. o IP não tranca nos modos brandos ============
  // Este é o coração da decisão de §5.4, e por isso prova-se com dois
  // telemóveis a sair do MESMO endereço — que é o que acontece a um salão
  // inteiro atrás de um router.
  await defs({ 'bar.ip_modo': 'registo' });
  const tel2 = await b.newContext({ viewport: { width: 390, height: 844 } });
  const c2 = await tel2.newPage();
  await c2.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await c2.waitForTimeout(500);
  const sou2 = (id) => c2.evaluate(async ([t, id]) =>
    await (await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: id }) })).json(), [token, id]);
  ok((await sou2(B.id)).success === true,
     'em «registo», dois telemóveis do mesmo IP servem dois nomes — é o caso comum');

  await defs({ 'bar.ip_modo': 'aviso' });
  ok((await sou2(B.id)).success === true, 'em «aviso» também passa: vigia, não trava');

  // ============ 5. e tranca no modo estrito ============
  await defs({ 'bar.ip_modo': 'estrito' });
  const tel3 = await b.newContext({ viewport: { width: 390, height: 844 } });
  const c3 = await tel3.newPage();
  await c3.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await c3.waitForTimeout(500);
  const terceiro = await c3.evaluate(async ([t, id]) =>
    await (await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: id }) })).json(),
    [token, doOutro ? doOutro.id : A.id]);
  ok(terceiro.success === false, 'em «estrito», o segundo nome do mesmo IP é recusado');
  ok(/garçom/i.test(terceiro.message || ''),
     'e a saída é humana: «' + terceiro.message + '»');
  await defs({ 'bar.ip_modo': 'registo' });

  // ============ 6. a ficha é sobre a BEBIDA, e não sobre o aparelho =====
  // Aqui provava-se o «Soltar»: a copa largava o telemóvel de uma pessoa, e a
  // lista da ficha encolhia. Saiu na terceira passagem — a ficha existe para
  // decidir o que alguém pode beber, e a manutenção de aparelhos só lhe
  // roubava espaço. O nó que ela desatava desata-se sozinho por
  // `bar.trocar_nome`, que já estava provado no bloco 2.
  //
  // O que fica é a guarda: a ficha não volta a trazer aparelhos, e a acção
  // não volta a existir sem alguém reparar.
  await sou(A.id);
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const ficha = await p.evaluate(async (id) =>
    await window.api('bar_ficha&convidado=' + id), A.id);
  ok(ficha && ficha.success === true && ficha.dispositivos === undefined,
     'a ficha traz o que se bebeu e as regras — e nenhuma lista de telemóveis');
  const morta = await p.evaluate(async () =>
    await window.api('bar_soltar', { method: 'POST', silencioso: true,
                                     body: JSON.stringify({ id: 1 }) }));
  ok(!morta || morta.success !== true,
     'e «soltar» já não é uma acção da API: uma porta que ninguém abre fecha-se');

  // ============ arrumar ============
  await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.ip_modo': 'registo', 'bar.trocar_nome': '1' }) });
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
    const d = await window.api('convite_list&busca=ZZ%20Vizinhos', { silencioso: true });
    for (const cv of ((d && d.convites) || [])) {
      if (cv.nome_exibicao === 'ZZ Vizinhos') {
        await window.api('convite_delete&definitivo=1&id=' + cv.id, { method: 'POST' });
      }
    }
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
