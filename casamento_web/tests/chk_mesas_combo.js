// A lista do combo das mesas não se fecha debaixo do dedo (docs/modulo-bar.md §32.6).
//
// O combo de mesas.php tem `max-height:244px` e `overflow:auto` — rola,
// portanto, mal o salão tenha meia centena de nomes. Os dois ouvintes de
// `scroll` que o fecham são em CAPTURA, e têm de ser: o scroll de um elemento
// não borbulha, e sem captura não se saberia que o painel das abas tinha
// rolado. Só que, em captura, chega-lhes TUDO o que rola — a própria lista
// incluída. O resultado era pior do que um tremor: a pessoa rolava a lista
// para procurar um nome e a lista desaparecia-lhe, à primeira volta da roda.
//
// Fechar ao rolar por FORA continua certo: a caixa é `position:fixed` e
// posicionada à mão, e ficaria descolada do botão que a abriu. O que faltava
// era perguntar de onde veio o scroll. É o mesmo defeito que a escolha das
// janelas tinha com outro sintoma (chk_bar_quarta.js).
//
// O salão de exemplo tem três pessoas, e uma lista de três linhas cabe inteira
// — é por isso que ninguém tinha dado pela coisa. A prova enche-o com gente
// só dela («ZX Enchimento»), e leva-a embora no fim: uma pessoa a mais no
// salão muda a lotação que as outras provas contam.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  // A gente desta prova sai daqui por todos os caminhos — o do fim e o da
  // desistência. Deixá-la ficar mudava a lotação que as outras provas contam.
  const arrumar = () => p.evaluate(async () => {
    const cv = await window.api('convite_list&busca=ZX%20Enchimento', { silencioso: true });
    for (const c of ((cv && cv.convites) || [])) {
      if (/^ZX Enchimento /.test(c.nome_exibicao || '')) {
        await window.api('convite_delete&definitivo=1&id=' + c.id,
                         { method: 'POST', silencioso: true });
      }
    }
  }).catch(() => {});
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  await p.evaluate(async () => {
    for (let i = 1; i <= 16; i++) {
      await window.api('convite_save', { method: 'POST', silencioso: true,
        body: JSON.stringify({ nome_exibicao: 'ZX Enchimento ' + i, tipo: 'digital',
                               lado: 'noivo', membros: [{ nome: 'ZX Pessoa ' + i }] }) });
    }
  });
  // A página monta os combos a partir do que já tinha lido: sem voltar a ler,
  // a lista abria com as três linhas de sempre e não havia nada a rolar.
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(2000);
  const mesa = p.locator('.mesa-node').first();
  ok(await mesa.count() > 0, 'há um salão com mesas para abrir');
  await mesa.click();
  await p.waitForTimeout(900);
  ok(await p.locator('.combo').count() > 0,
     'e a mesa escolhida traz os combos do painel');

  // A lista mais comprida do painel é a que interessa: só uma lista que rola
  // pode fechar-se a rolar.
  const qual = await p.evaluate(() => {
    let melhor = null;
    document.querySelectorAll('.combo').forEach((c, i) => {
      const n = (window.comboOpcoes(c.dataset.kind, c.dataset.arg) || []).length;
      if (!melhor || n > melhor.n) melhor = { i: i, n: n, kind: c.dataset.kind };
    });
    return melhor;
  });
  console.log('  (o combo mais comprido: «' + qual.kind + '», ' + qual.n + ' linhas)');

  await p.evaluate((i) => window.abrirCombo(document.querySelectorAll('.combo')[i]), qual.i);
  await p.waitForTimeout(350);
  const aberta = await p.evaluate(() => {
    const pop = document.querySelector('.combo-pop:not([hidden])');
    const l = pop && pop.querySelector('.combo-list');
    return { aberta: !!pop, linhas: l ? l.querySelectorAll('.combo-opt').length : 0,
             janela: l ? l.clientHeight : 0, conteudo: l ? l.scrollHeight : 0 };
  });
  ok(aberta.aberta, 'a lista abre-se (' + aberta.linhas + ' linhas)');
  ok(aberta.conteudo > aberta.janela,
     'e não cabe inteira, que é quando isto se pode provar: '
     + aberta.conteudo + 'px dentro de ' + aberta.janela + 'px');
  if (!aberta.aberta || aberta.conteudo <= aberta.janela) {
    await arrumar();
    console.log('\n' + (f || 1) + ' verificação(ões) falharam');
    await b.close(); process.exit(1);
  }

  // Rolar POR DENTRO: a lista fica, e rola.
  const cx = await p.locator('.combo-pop:not([hidden]) .combo-list').boundingBox();
  await p.mouse.move(cx.x + cx.width / 2, cx.y + cx.height / 2);
  for (let i = 0; i < 5; i++) { await p.mouse.wheel(0, 80); await p.waitForTimeout(60); }
  await p.waitForTimeout(400);
  const dentro = await p.evaluate(() => {
    const pop = document.querySelector('.combo-pop:not([hidden])');
    const l = pop && pop.querySelector('.combo-list');
    return { aberta: !!pop, rolou: l ? l.scrollTop : -1 };
  });
  ok(dentro.aberta, 'rolar por dentro não a fecha debaixo do dedo');
  ok(dentro.rolou > 0,
     'e a lista rolou mesmo (' + dentro.rolou + 'px) — senão isto não provava nada');

  // Rolar POR FORA: continua a fechar-se, que é o que sempre esteve certo.
  // A caixa é `position:fixed` e posta à mão; se ficasse aberta apareceria
  // descolada do botão que a abriu.
  const rolavel = await p.evaluate(() => {
    const tb = document.getElementById('tab-body');
    return !!tb && tb.scrollHeight > tb.clientHeight;
  });
  if (rolavel) {
    await p.evaluate(() => { document.getElementById('tab-body').scrollTop += 120; });
    await p.waitForTimeout(300);
    ok(await p.locator('.combo-pop:not([hidden])').count() === 0,
       'mas rolar o painel POR BAIXO fecha-a, como sempre fechou');
  } else {
    // Sem nada para rolar por baixo, prova-se o ouvinte pelo que ele lê.
    const fechou = await p.evaluate(() => {
      const tb = document.getElementById('tab-body');
      tb.dispatchEvent(new Event('scroll', { bubbles: false }));
      return document.querySelectorAll('.combo-pop:not([hidden])').length === 0;
    });
    ok(fechou, 'mas um scroll vindo de fora da lista fecha-a, como sempre fechou');
  }

  await arrumar();
  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
