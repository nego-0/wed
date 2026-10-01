// O nome do modelo, e a versão que protege os desenhos da casa.
//
// Duas queixas, uma raiz: a peça dizia "Original · com alterações" mesmo depois
// de o casal escolher «Borgonha» — o estado só olhava para as versões guardadas
// e nunca para o modelo aplicado. E, a partir daí, qualquer retoque era gravado
// por cima de um desenho que é da casa e serve todos os casais.
//
// Aqui prova-se: a peça chama o modelo pelo nome (em vigor e com alterações), e
// alterá-lo pelo editor obriga a uma versão do casal, com nome, que mais
// ninguém vê.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext();
  const p = await ctx.newPage();          // a página de trabalho (tem o CSRF)
  const olho = await ctx.newPage();       // outra só para ler os rótulos
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
  // O rótulo de estado, tal como o casal o lê na entrada da peça.
  const rotulo = async (pagina) => {
    await olho.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    return (await olho.textContent('.estado-linha')).trim().split('\n')[0].trim();
  };

  const marca = 'zz' + Date.now().toString().slice(-6);
  const casal = await api('casamento_criar', { nome: 'ZZ Modelo+Versao ' + marca, noiva: 'Vera', noivo: 'Vasco' });
  await api('casamento_abrir&id=' + casal.id, {});

  // ---------- 0. na origem, a peça diz o nome do modelo de origem ----------
  // Nunca «Original», que não é modelo nenhum. Vale nas duas peças.
  const linhaImpresso = async () => {
    await olho.goto(BASE + '/graficas.php', { waitUntil: 'domcontentloaded' });
    return (await olho.textContent('.estado-peca')).replace(/\s+/g, ' ').trim();
  };
  const digOrigem = await rotulo("digital.php");
  const impOrigem = await linhaImpresso();
  console.log('   origem digital :', JSON.stringify(digOrigem.slice(0, 40)));
  console.log('   origem impresso:', JSON.stringify(impOrigem.slice(0, 40)));
  ok(/Isabel & Abednego/.test(digOrigem) && !/Original/.test(digOrigem),
     'na origem, o convite digital diz «Isabel & Abednego» — nunca «Original»');
  ok(/Isabel & Abednego/.test(impOrigem) && !/Original/.test(impOrigem),
     'na origem, o cartão impresso diz «Isabel & Abednego» — nunca «Original»');

  // ---------- 1. quem nunca escolheu modelo grava à vontade ----------
  // A peça de origem não é desenho de ninguém: quem começa do princípio não
  // está a mexer no modelo de outrem, e não tem de baptizar o primeiro retoque.
  const solto = await api('defs_save', { defs: { 'textos.kicker': 'Do princípio ' + marca },
                                         proteger_desenho: true });
  ok(solto && solto.success && !solto.precisa_versao,
     'sem modelo escolhido, o casal grava sem ter de dar nome a nada');
  // E depois de mexer, continua a derivar da origem — «Isabel & Abednego», não «Original».
  const soltoLinha = await rotulo("digital.php");
  ok(/Isabel & Abednego/.test(soltoLinha) && !/Original/.test(soltoLinha),
     'e a linha diz «Isabel & Abednego · com alterações» — não «Original»');

  // ---------- 2. aplicado um modelo, a peça diz o NOME dele ----------
  const borgonha = ((await api('modelo_lista&ambito=digital')).modelos || [])
                     .find(m => m.nome === 'Borgonha');
  ok(!!borgonha, 'a casa oferece o modelo «Borgonha»');
  await api('modelo_aplicar&id=' + borgonha.id, {});

  const emVigor = await rotulo('digital.php');
  console.log('   rótulo:', JSON.stringify(emVigor));
  ok(/Borgonha/.test(emVigor) && !/Original/.test(emVigor),
     'aplicado o modelo, a peça diz «Borgonha» — e não «Original»');

  // Mexer à mão não apaga de onde a peça veio: continua a ser «Borgonha»,
  // agora com alterações. Era aqui que aparecia o "Original · com alterações".
  await api('defs_save', { defs: { 'textos.kicker': 'Mexido à mão ' + marca } });
  const alterada = await rotulo('digital.php');
  console.log('   rótulo:', JSON.stringify(alterada));
  ok(/Borgonha/.test(alterada) && /com alterações/.test(alterada),
     'mexida à mão, a peça diz «Borgonha · com alterações» — e não «Original»');
  ok(!/Original/.test(alterada), 'e não há sinal de «Original» em lado nenhum da linha');

  // ---------- 3. alterar um modelo da casa obriga a uma versão com nome ----------
  const recusa = await api('defs_save', { defs: { 'textos.kicker': 'Outra frase ' + marca },
                                          proteger_desenho: true });
  ok(recusa && recusa.success === false && recusa.precisa_versao === true,
     'alterar o desenho de um modelo da casa não se grava por cima');
  ok(recusa && recusa.base === 'Borgonha',
     'e a recusa diz de que desenho se trata (' + (recusa && recusa.base) + ')');

  // Com nome, grava — e o que sai é uma versão do casal.
  const guardado = await api('defs_save', { defs: { 'textos.kicker': 'Outra frase ' + marca },
                                            proteger_desenho: true,
                                            versao_nome: 'A nossa ' + marca });
  ok(guardado && guardado.success && guardado.versao && guardado.versao.nome === 'A nossa ' + marca,
     'com um nome, grava e nasce a versão do casal');

  const naVersao = await rotulo('digital.php');
  console.log('   rótulo:', JSON.stringify(naVersao));
  ok(new RegExp('A nossa ' + marca).test(naVersao),
     'e passa a ser essa a versão em vigor');

  // ---------- 4. já numa versão sua, o casal grava à vontade ----------
  const livre = await api('defs_save', { defs: { 'textos.kicker': 'Mais um retoque ' + marca },
                                         proteger_desenho: true });
  ok(livre && livre.success && !livre.precisa_versao,
     'numa versão sua, o casal grava por cima sem pedir licença a ninguém');

  // ---------- 5. a versão é dele, e só dele ----------
  const outro = await api('casamento_criar', { nome: 'ZZ Outro ' + marca, noiva: 'Olga', noivo: 'Ovo' });
  await api('casamento_abrir&id=' + outro.id, {});
  const doOutro = ((await api('versao_lista&ambito=digital')).versoes || []).map(v => v.nome);
  console.log('   versões do outro casal:', JSON.stringify(doOutro));
  ok(!doOutro.some(n => n.includes(marca)),
     'a versão de um casal não aparece a outro — é dele, e só dele');

  // ---------- 6. o modelo da casa ficou intacto ----------
  await api('casamento_abrir&id=' + casal.id, {});
  const aindaLa = ((await api('modelo_lista&ambito=digital')).modelos || [])
                    .find(m => +m.id === +borgonha.id);
  ok(aindaLa && aindaLa.nome === 'Borgonha',
     'e o modelo da casa continua lá, com o nome de sempre — não foi reescrito');

  // ---------- 7. o mesmo no CARTÃO IMPRESSO ----------
  // O cartão tem as suas peças e os seus modelos, mas as regras são as mesmas:
  // a peça diz o nome do modelo, e alterá-lo obriga a uma versão do casal.
  const salvia = ((await api('modelo_lista&ambito=impresso')).modelos || [])
                   .find(m => m.nome === 'Sálvia');
  ok(!!salvia, 'a casa oferece o modelo impresso «Sálvia»');
  await api('modelo_aplicar&id=' + salvia.id, {});

  const rotuloCartao = async () => {
    await olho.goto(BASE + '/graficas.php', { waitUntil: 'domcontentloaded' });
    return (await olho.textContent('.estado-peca')).replace(/\s+/g, ' ').trim();
  };
  const cartaoVigor = await rotuloCartao();
  console.log('   cartão:', JSON.stringify(cartaoVigor.slice(0, 48)));
  ok(/Sálvia/.test(cartaoVigor) && !/Original/.test(cartaoVigor),
     'aplicado o modelo impresso, o cartão diz «Sálvia» — e não «Original»');

  await api('defs_save', { defs: { 'cartao.reservado': 'Reservado ' + marca } });
  const cartaoAlterado = await rotuloCartao();
  console.log('   cartão:', JSON.stringify(cartaoAlterado.slice(0, 48)));
  ok(/Sálvia/.test(cartaoAlterado) && /com alterações/.test(cartaoAlterado),
     'mexido à mão, o cartão diz «Sálvia · com alterações»');

  const recusaCartao = await api('defs_save', { defs: { 'cartao.reservado': 'Outro ' + marca },
                                                proteger_desenho: true });
  ok(recusaCartao && recusaCartao.success === false && recusaCartao.precisa_versao === true
     && recusaCartao.base === 'Sálvia',
     'alterar o desenho de um modelo impresso também obriga a uma versão com nome');

  const cartaoGuardado = await api('defs_save', { defs: { 'cartao.reservado': 'Outro ' + marca },
                                                  proteger_desenho: true,
                                                  versao_nome: 'O nosso cartão ' + marca });
  ok(cartaoGuardado && cartaoGuardado.success && cartaoGuardado.versao
     && cartaoGuardado.versao.ambito === 'impresso',
     'e a versão que nasce é do cartão, não do convite digital');

  // As duas peças têm versões próprias: mexer numa não mexe na outra.
  const vsDigital = ((await api('versao_lista&ambito=digital')).versoes || []).map(v => v.nome);
  const vsImpresso = ((await api('versao_lista&ambito=impresso')).versoes || []).map(v => v.nome);
  console.log('   versões digital :', JSON.stringify(vsDigital));
  console.log('   versões impresso:', JSON.stringify(vsImpresso));
  ok(vsDigital.some(n => n.startsWith('A nossa')) && !vsDigital.some(n => n.startsWith('O nosso cartão')),
     'a versão do convite digital fica na peça dela');
  ok(vsImpresso.some(n => n.startsWith('O nosso cartão')) && !vsImpresso.some(n => n.startsWith('A nossa')),
     'e a do cartão na dele — cada peça guarda as suas');

  // ---------- limpeza ----------
  for (const id of [casal.id, outro.id]) {
    await api('casamento_estado&id=' + id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + id, {});
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
