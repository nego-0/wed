// Cerimónias opcionais, cronograma que se rearranja, e três defeitos que
// estavam à vista de toda a gente.
//
// O pior deles: horaTexto('') dava "0h", e por isso TODOS os cartões
// anunciavam uma "Cerimónia Religiosa às 0h" que ninguém marcou — e o teste
// que a devia esconder ("sem hora não se anuncia") nunca era verdade.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
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

  // Remover volta atrás (com confirmação).
  p.once('dialog', d => d.accept());
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
  const tela = p.frameLocator('#tela');
  ok(await tela.locator('#grande-dia .cerimonias .cer').count() === 2,
     'e o convite passa a anunciá-las — antes só existiam no papel');
  const txtTela = await tela.locator('#grande-dia .cerimonias').innerText();
  ok(/Registo Civil/i.test(txtTela) && /Igreja da S/i.test(txtTela),
     'com o nome e o local que se escreveu: ' + txtTela.replace(/\n/g, ' · '));
  ok(/Às /.test(txtTela) && !/Ás /.test(txtTela),
     'e "Às", com o acento certo — era "Ás" à vista dos convidados');

  p.once('dialog', d => d.accept());
  await p.evaluate(() => removerCerimonia('religiosa', 'Cerimónia religiosa'));
  await p.waitForTimeout(3000);
  ok(await tela.locator('#grande-dia .cerimonias .cer').count() === 1,
     'remover no digital tira-a da tela');

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

  // ============ 6. o menu "⋯" dos modelos não fica cortado ============
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

  // ============ 7. o modelo do cartão, no editor ============
  const imp = (await api('modelo_lista')).modelos.find(m => m.ambito === 'impresso');
  if (imp) {
    await p.goto(BASE + '/editor-cartao.php?modelo=' + imp.id, { waitUntil: 'networkidle' });
    await p.waitForTimeout(1500);
    t = await logistica();
    ok(!/0H\b/i.test(t), 'o modelo do cartão já não anuncia uma cerimónia às 0h: ' + t);
    ok(await p.locator('#sel-versao').count() === 0,
       'e o seletor de versões, que num modelo saía vazio, deixou de estar lá');
    await p.screenshot({ path: OUT + '/cerimonias-modelo.png' });
  }

  // Deixar a casa como se encontrou.
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await api('defs_save', { defs: { 'evento.civil_titulo': 'Cerimónia Civil',
                                   'evento.civil_hora': '10:30', 'evento.civil_local': '',
                                   'evento.religiosa_titulo': 'Cerimónia Religiosa',
                                   'evento.religiosa_hora': '', 'evento.religiosa_local': '' } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
