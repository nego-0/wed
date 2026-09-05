// Cerimónias opcionais, cronograma que se rearranja, e três defeitos que
// estavam à vista de toda a gente.
//
// O pior deles: horaTexto('') dava "0h", e por isso TODOS os cartões
// anunciavam uma "Cerimónia Religiosa às 0h" que ninguém marcou — e o teste
// que a devia esconder ("sem hora não se anuncia") nunca era verdade.
const { chromium } = require('playwright-core');
const janela = require('./_janela');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  // As janelas dos editores já não são do browser: são as da casa. O
  // auto-responder faz o que o on('dialog') fazia — responde-lhes sozinho,
  // por dentro da página, para esta prova poder continuar a olhar só para
  // aquilo que veio provar. (Ver tests/_janela.js.)
  await janela.autoResponder(p, 'Prova');
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  // Ponto de partida conhecido: civil marcada, religiosa não.
  // Também os títulos: a prova reescreve-os mais abaixo, e sem os repor a
  // segunda corrida começava com o estado que a primeira deixou.
  await api('defs_save', { defs: { 'evento.civil_titulo': 'Cerimónia Civil',
                                   'evento.civil_hora': '10:30', 'evento.civil_local': '',
                                   'evento.religiosa_titulo': 'Cerimónia Religiosa',
                                   'evento.religiosa_hora': '', 'evento.religiosa_local': '' } });

  const logistica = () => p.evaluate(() =>
    document.querySelector('#escala .ct-logistica').innerText.replace(/\s+/g, ' ').trim());

  // ============ 1. uma cerimónia sem hora não se anuncia ============
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1400);
  let t = await logistica();
  console.log('   logística:', t);
  ok(!/0H\b/i.test(t), 'sem hora marcada, a religiosa não aparece — nem "às 0h"');
  ok(/CERIM[ÓO]NIA CIVIL/i.test(t), 'a civil, essa, anuncia-se');

  // ============ 2. acrescentar e remover, no cartão ============
  await p.evaluate(() => selecionar('logistica'));
  await p.waitForTimeout(300);

  // A camada onde as cerimónias se metem tinha um nome — "Logística" — que
  // ninguém associa a marcar a hora e o sítio de uma cerimónia. Passou a
  // chamar-se pelo que lá se faz, e as cerimónias vêm à cabeça do painel,
  // antes da receção: é o que a maioria vem cá procurar.
  const rotulo = await p.evaluate(() =>
    [...document.querySelectorAll('[onclick^="selecionar"]')]
      .map(e => e.textContent.trim().split('\n')[0]).find(t => /Cerim|Log[íi]st/.test(t)));
  ok(/Cerim[óo]nias/i.test(rotulo || ''),
     `a camada anuncia-se pelas cerimónias na lista ("${rotulo}")`);
  ok(/Cerim[óo]nias/i.test(await p.evaluate(() =>
        document.querySelector('#props .vazio-painel b').textContent)),
     'e o painel abre com esse nome');
  const ordemPainel = await p.evaluate(() => {
    const txt = document.querySelector('#props').innerText;
    return { cer: txt.search(/CERIM[ÓO]NIA/i), rec: txt.search(/RECE[ÇC][ÃA]O/i) };
  });
  ok(ordemPainel.cer >= 0 && ordemPainel.rec >= 0 && ordemPainel.cer < ordemPainel.rec,
     'as cerimónias vêm antes da receção no painel');

  ok(await p.locator('#props .cer-dentro').count() === 1, 'a civil aparece no painel como existente');
  ok(await p.locator('#props .cer-fora .bt').count() === 1, 'e a religiosa como um convite a acrescentá-la');

  await p.click('#props .cer-fora .bt'); await p.waitForTimeout(350);
  t = await logistica();
  ok(/RELIGIOSA/i.test(t), 'acrescentar põe a religiosa no cartão na hora: ' + t);
  ok(await p.locator('#props .cer-dentro').count() === 2, 'e o painel passa a mostrar as duas');

  // A ordem e as margens: o primeiro título não leva margem de cima.
  const margens = await p.evaluate(() => [...document.querySelectorAll('#escala .ct-logistica .ct-seccao')]
    .map(x => ({ txt: x.textContent.trim(), dois: x.classList.contains('ct-seccao-2') })));
  ok(margens[0] && !margens[0].dois && margens.slice(1).every(x => x.dois),
     'só o primeiro título fica sem a margem de cima: ' + JSON.stringify(margens.map(m => m.txt)));

  await p.evaluate(() => { est.textos['evento.religiosa_local'] = 'Igreja da Sé'; pintarLogistica(); renderProps(); });
  await p.waitForTimeout(250);
  ok(/Igreja da S[ée]/i.test(await logistica()), 'o local entra por baixo da hora');

  // Remover volta atrás (com confirmação — o handler global aceita-a).
  await p.evaluate(() => removerCerimonia('civil', 'Cerimónia civil'));
  await p.waitForTimeout(300);
  t = await logistica();
  ok(!/CIVIL/i.test(t) && /RELIGIOSA/i.test(t), 'remover a civil tira-a e deixa a outra: ' + t);
  const m2 = await p.evaluate(() => [...document.querySelectorAll('#escala .ct-logistica .ct-seccao')]
    .map(x => x.classList.contains('ct-seccao-2')));
  ok(m2[0] === false, 'e a margem de cima salta para o título que ficou em primeiro');

  // ============ 3. grava, relê, e chega ao que se imprime ============
  await p.evaluate(() => { est.textos['evento.civil_hora'] = '11:00';
                           est.textos['evento.civil_titulo'] = 'Registo Civil';
                           pintarLogistica(); });
  ok(await p.evaluate(() => guardar()), 'a composição das cerimónias grava-se');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  t = await logistica();
  ok(/REGISTO CIVIL/i.test(t) && /11H/i.test(t), 'e volta ao reabrir o editor: ' + t);

  await p.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
  const naFolha = await p.evaluate(() =>
    document.querySelector('.cartao .ct-logistica').innerText.replace(/\s+/g, ' ').trim());
  ok(/REGISTO CIVIL/i.test(naFolha) && /IGREJA DA S/i.test(naFolha),
     'a folha de impressão traz as duas: ' + naFolha);
  ok(!/0H\b/i.test(naFolha), 'e nenhuma hora inventada');

  // ============ 4. o convite digital também as mostra ============
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3200);
  await p.evaluate(() => irCamada('grande-dia')); await p.waitForTimeout(900);
  ok(await p.locator('#props .cer-dentro').count() === 2,
     'as mesmas duas cerimónias aparecem no editor digital');
  // A ligação do Google Maps NÃO se edita no editor: é dado do evento, muda-se
  // na gestão dos noivos. O local e a cidade, esses, editam-se aqui.
  const campos = await p.evaluate(() =>
    [...document.querySelectorAll('#props [data-chave]')].map(e => e.dataset.chave));
  ok(!campos.includes('evento.maps'),
     'o editor digital já não deixa mudar a ligação do Google Maps');
  ok(campos.includes('evento.local') && campos.includes('evento.cidade'),
     'mas continua a editar o local e a cidade do evento');
  // Cada cerimónia é um CARTÃO: emblema em cima, moldura desenhada à volta, e
  // por dentro o que é, a que horas e onde. Eram três linhas de texto.
  const tela = p.frameLocator('#tela');
  ok(await tela.locator('#grande-dia .cerimonias .cer-item:not(.wide)').count() === 2,
     'e o convite passa a anunciá-las em cartões — antes só existiam no papel');
  const txtTela = await tela.locator('#grande-dia .cerimonias').innerText();
  ok(/Registo Civil/i.test(txtTela) && /Igreja da S/i.test(txtTela),
     'com o nome e o local que se escreveu: ' + txtTela.replace(/\n/g, ' · '));
  ok(/\d{1,2}H\d{2}/.test(txtTela),
     'e a hora à maneira do cartão: ' + (txtTela.match(/\d{1,2}H\d{2}/) || [''])[0]);
  // O copo d'água entra no mesmo conjunto, em cartão largo: era um bloco à
  // parte, a dizer a mesma coisa por outras palavras.
  ok(await tela.locator('#grande-dia .cerimonias .cer-item.wide').count() === 1,
     'e o copo d’água fecha o conjunto, num cartão largo');
  ok(await tela.locator('#grande-dia .venue').count() === 0,
     'já não há bloco de receção à parte');
  ok(/e depois, o copo/i.test(txtTela),
     'com a linha que costura as cerimónias à festa: ' + (txtTela.match(/e depois[^\n]*/i) || [''])[0]);

  await p.evaluate(() => removerCerimonia('religiosa', 'Cerimónia religiosa'));
  await p.waitForTimeout(3000);
  ok(await tela.locator('#grande-dia .cerimonias .cer-item:not(.wide)').count() === 1,
     'remover no digital tira-a da tela');

  // ============ 4b. o local com Google Maps vira botão no cartão ============
  // A ligação do mapa marca-se nos formulários do casamento (gestão e registo),
  // não no editor; o convite lê-a e põe no cartão o botão «Ver no mapa».
  await api('defs_save', { defs: {
    'evento.civil_hora': '10:30', 'evento.civil_local': 'Conservatória do Namibe',
    'evento.civil_maps': 'https://maps.app.goo.gl/provaCer' } });
  const htmlConvite = await p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  ok(/<p class="cer-place">Conservatória do Namibe<\/p>/.test(htmlConvite),
     'o cartão mostra o local da cerimónia');
  ok(/<a class="cer-map" href="https:\/\/maps\.app\.goo\.gl\/provaCer"[^]*?<span>Ver no mapa<\/span>/.test(htmlConvite),
     'e, com ligação, o botão «Ver no mapa»');
  // O copo d'água tem sempre o seu botão; com a ligação da cerimónia passam a
  // ser dois. É assim que se conta, e não pela ausência da classe.
  ok((htmlConvite.match(/class="cer-map"/g) || []).length === 2,
     'a cerimónia com mapa acrescenta o seu botão ao do copo d’água: '
       + (htmlConvite.match(/class="cer-map"/g) || []).length + ' botão(ões)');
  await api('defs_save', { defs: { 'evento.civil_maps': '' } });
  const semElo = await p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  ok(/<p class="cer-place">Conservatória do Namibe<\/p>/.test(semElo)
     && (semElo.match(/class="cer-map"/g) || []).length === 1,
     'e sem mapa fica o local sozinho, sem botão');
  // O cronograma abre com as cerimónias, com o selo do cartão em vez do ícone.
  ok(/<div class="node cer"><img src="assets\/convite\/selo-civil\.png"/.test(semElo),
     'o cronograma abre com a cerimónia, e traz o selo dela');
  // O título vai por extenso — e o que o casal escreveu manda: «Registo Civil»
  // não vira «Cerimónia registo civil».
  ok(/<div class="tt">Registo Civil<em>O sim perante a lei<\/em><span class="loc">Conservatória do Namibe<\/span>/
       .test(semElo),
     'com o que ela é, e onde — pelo nome que o casal lhe deu');

  // ============ 5. o cronograma rearranja-se ============
  const ordem = () => p.evaluate(() => EST.listas['cronograma.itens'].map(x => x.t));
  const antes = await ordem();
  ok(antes.length > 1, 'há cronograma com que trabalhar: ' + antes.join(' → '));
  ok(await p.locator('#props .it-topo button[title=Subir]').count() === antes.length,
     'cada momento tem setas para subir e descer');
  ok(await p.evaluate(() => document.querySelector('#props .it-topo button[title=Subir]').disabled),
     'a seta de subir do primeiro está travada');

  await p.evaluate(() => moverItem('cronograma.itens', 0, 1));
  await p.waitForTimeout(2600);
  const depois = await ordem();
  ok(depois[0] === antes[1] && depois[1] === antes[0],
     'descer o primeiro troca-o com o segundo: ' + depois.join(' → '));
  const naTela = await tela.locator('#grande-dia .timeline').innerText();
  ok(naTela.indexOf(depois[0]) < naTela.indexOf(depois[1]),
     'e a tela mostra a ordem nova, composta pelo servidor');

  ok(await p.evaluate(() => guardar()), 'a ordem grava-se');
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  ok((await ordem())[0] === depois[0], 'e volta certa ao reabrir');
  await p.evaluate(() => moverItem('cronograma.itens', 1, -1));
  await p.waitForTimeout(2600);
  await p.evaluate(() => guardar());   // deixar como estava

  // ============ 6. o emblema, os ramos, a moldura e o tamanho ============
  // O casal escolhe o que encima cada cartão, se o emblema leva ramos à volta,
  // se os cartões levam moldura, e o tamanho do conjunto. Antes não havia
  // escolha nenhuma: o desenho era o que era.
  await api('defs_save', { defs: {
    'evento.civil_hora': '10:30', 'evento.civil_titulo': 'Cerimónia Civil',
    'evento.civil_local': 'Conservatória do Namibe',
    'evento.religiosa_hora': '16:00', 'evento.religiosa_titulo': 'Cerimónia Religiosa',
    'evento.religiosa_local': 'Igreja da Sé' } });
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3200);
  await p.evaluate(() => irCamada('grande-dia')); await p.waitForTimeout(900);

  const painel = await p.evaluate(() => {
    const g = [...document.querySelectorAll('#props .grupo')]
      .find(x => /Emblemas e molduras/i.test(x.querySelector('h4') ? x.querySelector('h4').textContent : ''));
    if (!g) return null;
    const s = [...g.querySelectorAll('select')];
    return { selects: s.length, caixas: g.querySelectorAll('input[type=checkbox]').length,
             cursor: g.querySelectorAll('input[type=range]').length,
             opcoes: s[0] ? [...s[0].options].map(o => o.value) : [] };
  });
  ok(painel !== null, 'o editor do casal ganha o painel «Emblemas e molduras»');
  ok(painel && painel.selects === 3,
     'com um emblema à escolha para a civil, a religiosa e o copo d’água');
  ok(painel && painel.opcoes[0] === 'original' && painel.opcoes.length > 5,
     'a primeira opção é o desenho da casa, e há alternativas a seguir: '
       + (painel ? painel.opcoes.slice(0, 4).join(', ') : ''));
  ok(painel && painel.caixas === 2, 'e as duas escolhas de sim ou não: ramos e molduras');
  ok(painel && painel.cursor === 1, 'e o cursor do tamanho');

  // A tela do editor mostra o rascunho; o convite enviado mostra o que está
  // gravado. As duas coisas provam-se, e por isso cada escolha se grava antes
  // de se ir buscar o convite.
  const convite = () => p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  const gravar = async () => { await p.evaluate(() => guardar()); await p.waitForTimeout(900); };
  let h = await convite();
  ok(/<img src="assets\/convite\/emblema-civil\.png"/.test(h),
     'de origem, o cartão traz o emblema da casa, com ramos');

  // Trocar o emblema da civil por um símbolo desenhado.
  await p.evaluate(() => mudarEmblema('civil', 'aneis')); await p.waitForTimeout(2600);
  ok(await tela.locator('#grande-dia .cerimonias .cer-item svg.cer-emb').count() === 1,
     'escolher outro emblema desenha-o já na tela, no lugar do da casa');
  await gravar();
  h = await convite();
  ok(/<svg class="cer-emb"[^]*?class="ramo"/.test(h),
     'e o convite gravado passa a trazê-lo, com os seus ramos');
  ok(!/<img src="assets\/convite\/emblema-civil\.png"/.test(h),
     'e o emblema da casa sai desse cartão');

  // Sem ramos: o desenhado perde-os, e o da casa troca-se pela versão em anel.
  await p.evaluate(() => alternarCer('cer.ramos')); await p.waitForTimeout(2600);
  await gravar();
  h = await convite();
  ok(!/class="ramo"/.test(h), 'desligar os ramos deixa o emblema desenhado só com o anel');
  ok(/<img src="assets\/convite\/selo-religiosa\.png"/.test(h),
     'e o emblema da casa passa à sua versão em anel, que é a mesma peça mais discreta');
  await p.evaluate(() => alternarCer('cer.ramos')); await p.waitForTimeout(2600);
  await gravar();

  // Sem moldura: sai o desenho à volta, e o selo que pousava nele.
  ok(((await convite()).match(/class="cf"/g) || []).length === 3,
     'com moldura, cada cartão tem a sua — as duas cerimónias e o copo d’água');
  await p.evaluate(() => alternarCer('cer.moldura')); await p.waitForTimeout(2600);
  ok(await tela.locator('#grande-dia .cerimonias .cf').count() === 0,
     'desligar as molduras tira-as logo da tela do editor');
  await gravar();
  ok(!/class="cf"/.test(await convite()), 'e do convite, em todos os cartões');
  await p.evaluate(() => alternarCer('cer.moldura')); await p.waitForTimeout(2600);
  await gravar();

  // O tamanho é um só, para o conjunto todo — vai em --cer-tam.
  await p.evaluate(() => mudarTamanhoEmblema(140)); await p.waitForTimeout(2800);
  ok(await tela.locator('#grande-dia .cerimonias[style*="1.40"]').count() === 1,
     'o cursor do tamanho chega à tela do editor');
  await gravar();
  ok(/--cer-tam:1\.40/.test(await convite()), 'e ao convite');
  await p.evaluate(() => mudarTamanhoEmblema(100)); await p.waitForTimeout(2800);
  await gravar();

  // ============ 6b. as cerimónias no cronograma, e o ícone delas ============
  const fixas = await p.evaluate(() =>
    [...document.querySelectorAll('#props .it-fixo')].map(e => ({
      txt: e.innerText.replace(/\s+/g, ' ').trim(),
      op1: e.querySelector('select') ? e.querySelector('select').options[0].value : null })));
  ok(fixas.length === 2, 'o cronograma do editor abre com as duas cerimónias');
  ok(/10H30/.test(fixas[0].txt) && /16H00/.test(fixas[1].txt),
     'pela ordem das horas: '
       + fixas.map(x => (x.txt.match(/\d{1,2}H\d{2}[^—]*/) || [x.txt])[0].trim()).join(' | '));
  ok(fixas.every(x => /a hora e o nome mudam-se em Cerimónias/i.test(x.txt)),
     'e dizem onde se mudam — não se editam duas vezes');
  ok(fixas.every(x => x.op1 === 'selo'),
     'cada uma escolhe o seu ícone, começando pelo emblema do cartão');

  ok(((await convite()).match(/class="node cer/g) || []).length === 2,
     'no cronograma, cada cerimónia abre com o emblema do seu cartão');
  await p.evaluate(() => mudarIconeCerimonia('religiosa', 'coracao'));
  await p.waitForTimeout(2600);
  await gravar();
  h = await convite();
  ok(!/<div class="node cer"><img src="assets\/convite\/selo-religiosa\.png"/.test(h),
     'escolher um ícone tira o selo daquele momento do cronograma');
  ok((h.match(/class="node cer/g) || []).length === 1,
     'e a outra cerimónia fica com o seu — a escolha é de cada uma');
  await p.evaluate(() => mudarIconeCerimonia('religiosa', 'selo'));
  await p.waitForTimeout(2600);

  // ============ 6c. as escolhas viajam no modelo e na versão ============
  ok(await p.evaluate(() => guardar()), 'as escolhas gravam-se');
  const v = await api('versao_criar&ambito=digital', { nome: 'Prova dos emblemas' });
  ok(v && v.id > 0, 'e guardam-se numa versão');
  // Desfazer no convite e voltar pela versão: se a escolha não viajasse com o
  // desenho, voltaria o emblema da casa.
  await api('defs_save', { defs: { 'cer.emblema_civil': 'original' } });
  ok(/<img src="assets\/convite\/emblema-civil\.png"/.test(await convite()),
     'desfazer a escolha devolve o emblema da casa');
  await api('versao_aplicar&ambito=digital&id=' + v.id);
  ok(/<svg class="cer-emb"/.test(await convite()),
     'e aplicar a versão traz a escolha de volta — viaja com o desenho');

  // O admin desenha o mesmo no modelo, e o modelo leva-o consigo. O modelo é
  // um de propósito para a prova: desenhar por cima do modelo de origem da
  // casa deixava-o mudado, e as provas que o vigiam davam-no por adulterado.
  const md = await api('modelo_criar',
    { nome: 'ZZ prova dos emblemas', ambito: 'digital', visivel: false });
  if (md && md.id) {
    await p.goto(BASE + '/convite-editor.php?modelo=' + md.id, { waitUntil: 'networkidle' });
    await p.waitForTimeout(3200);
    await p.evaluate(() => irCamada('grande-dia')); await p.waitForTimeout(900);
    ok(await p.evaluate(() => !!document.querySelector('#props .grupo h4') &&
         [...document.querySelectorAll('#props .grupo h4')].some(x => /Emblemas e molduras/i.test(x.textContent))),
       'o editor do admin traz o mesmo painel — as escolhas são as mesmas dos dois lados');
    ok(await p.locator('#props .it-fixo').count() >= 1,
       'e o cronograma do modelo abre também com as cerimónias');
    await p.evaluate(() => { mudarEmblema('religiosa', 'bolo'); alternarCer('cer.moldura'); });
    await p.waitForTimeout(1200);
    ok(await p.evaluate(() => guardar()), 'o admin grava o desenho no modelo');
    // Reabrir é a prova: o modelo guardou-as, e leva-as a quem o escolher.
    await p.goto(BASE + '/convite-editor.php?modelo=' + md.id, { waitUntil: 'networkidle' });
    await p.waitForTimeout(3200);
    const noModelo = await p.evaluate(() =>
      ({ emb: EST.val['cer.emblema_religiosa'], mol: EST.val['cer.moldura'] }));
    ok(noModelo.emb === 'bolo' && noModelo.mol === '0',
       'e o modelo guarda-as: reabre com o emblema e a moldura que o admin escolheu ('
         + noModelo.emb + ', moldura=' + noModelo.mol + ')');
  }
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  if (md && md.id) await api('modelo_apagar&id=' + md.id);   // o modelo era só para isto
  await api('defs_save', { defs: { 'cer.emblema_civil': 'original', 'cer.emblema_religiosa': 'original',
                                   'cer.emblema_copo': 'original', 'cer.ramos': '1',
                                   'cer.moldura': '1', 'cer.tamanho': '100',
                                   'cronograma.icone_civil': 'selo', 'cronograma.icone_religiosa': 'selo' } });

  // ============ 7. o menu "⋯" dos modelos não fica cortado ============
  const L = await api('modelo_lista');
  if (!(L.modelos || []).length) await api('modelo_criar', { nome: 'ZZ prova', ambito: 'impresso', visivel: true });
  await p.goto(BASE + '/modelos.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  await p.click('#lista .mod .mm > button'); await p.waitForTimeout(300);
  const pop = await p.evaluate(() => {
    const el = document.querySelector('.mm-pop'); if (!el) return null;
    const r = el.getBoundingClientRect();
    // Estava a ser cortado pelo overflow:hidden do cartão: abria, mas ficava
    // recortado pela borda e parecia não fazer nada.
    const topo = document.elementFromPoint(r.left + r.width / 2, r.top + 10);
    return { alt: Math.round(r.height), clicavel: !!(topo && topo.closest('.mm-pop')) };
  });
  ok(pop && pop.alt > 40, `o menu abre com altura a sério (${pop && pop.alt}px)`);
  ok(pop && pop.clicavel, 'e não fica cortado pela borda do cartão — clica-se nele');

  // ============ 8. o modelo do cartão, no editor ============
  const imp = (await api('modelo_lista')).modelos.find(m => m.ambito === 'impresso');
  if (imp) {
    await p.goto(BASE + '/editor-cartao.php?modelo=' + imp.id, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1500);
    t = await logistica();
    ok(!/0H\b/i.test(t), 'o modelo do cartão já não anuncia uma cerimónia às 0h: ' + t);
    ok(await p.locator('#sel-versao').count() === 0,
       'e o seletor de versões, que num modelo saía vazio, deixou de estar lá');

    // No modelo, o admin marca cerimónias na mesma — são de exemplo, para o
    // desenho ficar realista. Antes o painel só mostrava um aviso de que "não
    // se governa"; agora tem os mesmos controlos, com a ajuda a dizer que são
    // de exemplo e não passam para o casal.
    await p.evaluate(() => selecionar('logistica')); await p.waitForTimeout(400);
    ok(await p.locator('#props .cer-dentro, #props .cer-fora').count() >= 1,
       'o modelo do cartão traz os controlos de cerimónia, como o editor do casal');
    ok(/exemplo/i.test(await p.locator('#props').innerText()),
       'e a ajuda diz que as cerimónias do modelo são de exemplo');
    await p.screenshot({ path: OUT + '/cerimonias-modelo.png' });
  }

  // Deixar a casa como se encontrou.
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  if (v && v.id) await api('versao_apagar&ambito=digital&id=' + v.id);
  await api('defs_save', { defs: { 'evento.civil_titulo': 'Cerimónia Civil',
                                   'evento.civil_hora': '10:30', 'evento.civil_local': '', 'evento.civil_maps': '',
                                   'evento.religiosa_titulo': 'Cerimónia Religiosa',
                                   'evento.religiosa_hora': '', 'evento.religiosa_local': '', 'evento.religiosa_maps': '' } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
