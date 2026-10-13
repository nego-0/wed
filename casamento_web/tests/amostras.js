// Não é uma prova: é o contacto das alternativas de ornamento, para se poder
// olhar para elas lado a lado e decidir se são bonitas. Escreve PNGs em
// TEST_OUT (por omissão, a pasta temporária).
//
//   node tests/amostras.js
//
// Desenha cada feitio num cartão a sério — o mesmo que sai impresso — e não
// numa amostra à parte, porque o que interessa é se assenta no resto.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();
const path = require('path');

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1500, height: 1100 },
                                        deviceScaleFactor: 2 })).newPage();

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const guardar = defs => p.evaluate(async defs => {
    const r = await fetch('api.php?action=defs_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ defs }) });
    return r.json();
  }, defs);

  // O estado a que se volta no fim, para o contacto não deixar o cartão
  // trocado a quem o estiver a usar.
  const origem = {
    'cartao.floreado': 'classico', 'cartao.voluta': 'caracol', 'cartao.elo': 'coracao',
    'cartao.moldura_estilo': 'simples', 'cartao.posicoes': '',
  };

  // Para cada família, o cartão inteiro e ainda um recorte de perto: um
  // ornamento de 4 mm não se julga num cartão reduzido a 250 px.
  const series = [
    ['floreados', 'cartao.floreado',       ['classico','voluta','ramo','filete','gota'],       '.ct-nomes'],
    ['volutas',   'cartao.voluta',         ['caracol','folha','arco','esquadria','leque'],     '.ct-voluta'],
    ['molduras',  'cartao.moldura_estilo', ['simples','dupla','tripla','fina','pontilhada','arredondada','cantos'], '.ct-moldura'],
    ['elos',      'cartao.elo',            ['coracao','comercial','letra','losango','filete','nada'], '.ct-nomes'],
  ];

  for (const [nome, chave, feitios, perto] of series) {
    for (const f of feitios) {
      await guardar({ ...origem, [chave]: f });
      await p.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
      await p.waitForTimeout(500);
      // Um cartão só, e sem o encolhimento da folha, para se ver o traço.
      await p.evaluate(() => {
        const g = document.querySelector('.grelha-cartoes');
        if (!g) return;
        g.classList.add('unica');
        [...g.children].slice(1).forEach(n => n.remove());
        g.style.setProperty('--esc', '1');
      });
      await p.waitForTimeout(300);
      const alvo = await p.$('.grelha-cartoes .folha') || await p.$('.cartao');
      await alvo.screenshot({ path: path.join(OUT, `amostra-${nome}-${f}.png`) });

      // O recorte, com uma folga à volta para o ornamento não sair colado.
      const cx = await p.$(perto);
      if (cx) {
        const c = await p.$('.cartao');
        const cr = await c.boundingBox(), r = await cx.boundingBox();
        const folga = 26;
        const caixa = {
          x: Math.max(cr.x, r.x - folga), y: Math.max(cr.y, r.y - folga),
          width:  Math.min(cr.x + cr.width,  r.x + r.width  + folga) - Math.max(cr.x, r.x - folga),
          height: Math.min(cr.y + cr.height, r.y + r.height + folga) - Math.max(cr.y, r.y - folga),
        };
        if (caixa.width > 4 && caixa.height > 4)
          await p.screenshot({ path: path.join(OUT, `perto-${nome}-${f}.png`), clip: caixa });
      }
      console.log(`  ${nome}/${f}`);
    }
  }

  await guardar(origem);
  console.log('\nO cartão ficou como estava.');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
