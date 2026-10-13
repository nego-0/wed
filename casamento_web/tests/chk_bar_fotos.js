// A fotografia de uma bebida cabe INTEIRA no cartão.
//
// Estava em `object-fit:cover`: a caixa ficava sempre cheia, é certo, mas à
// custa de cortar o que não coubesse. E o que se fotografa aqui são GARRAFAS,
// que são altas e estreitas: numa caixa 4/3 o `cover` come-lhes o gargalo e a
// base e deixa uma faixa do meio — fica-se com uma mancha de rótulo sem saber
// que bebida é. Quem manda uma fotografia de uma garrafa quer ver a garrafa.
//
// Isto prova-se pelo que APARECE, e não pela propriedade do CSS. A prova manda
// uma imagem alta com os quatro cantos de cores diferentes e vai LER OS PIXÉIS
// do que ficou desenhado: se o topo e o fundo lá estiverem, a fotografia coube
// inteira. Com o corte de antes, os dois desapareciam — e um teste que só
// perguntasse «o object-fit diz contain?» passaria à mesma se alguém pusesse
// um `height` que voltasse a cortar.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  // Uma bebida só desta prova, e uma imagem com feitio de garrafa: 200x600.
  // Verde no topo, vermelho no fundo, branco no meio — as três faixas dizem se
  // a imagem coube ou se lhe cortaram as pontas.
  const nova = await p.evaluate(async () => {
    const r = await fetch('api.php?action=bar_item_guardar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: 'ZZ Garrafa Alta', preco: 0, alcool: 0 }) });
    return r.json();
  });
  ok(nova && nova.success, 'criou a bebida de prova');
  const id = nova.id || (nova.item && nova.item.id);

  const png = await p.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 200; c.height = 600;
    const x = c.getContext('2d');
    x.fillStyle = '#ffffff'; x.fillRect(0, 0, 200, 600);
    x.fillStyle = '#00A000'; x.fillRect(0, 0, 200, 90);      // topo
    x.fillStyle = '#D00000'; x.fillRect(0, 510, 200, 90);    // fundo
    return c.toDataURL('image/png').split(',')[1];
  });
  const fich = require('path').join(require('os').tmpdir(), 'zz-garrafa.png');
  require('fs').writeFileSync(fich, Buffer.from(png, 'base64'));

  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2000);
  const seletor = p.waitForEvent('filechooser');
  await p.locator('.b-cart:has-text("ZZ Garrafa Alta") button[aria-label*="fotografia"]')
         .first().click();
  await (await seletor).setFiles(fich);
  await p.waitForTimeout(2600);

  const img = p.locator('.b-cart:has-text("ZZ Garrafa Alta") .capa img').first();
  ok(await img.count() > 0, 'a fotografia subiu e está no cartão');

  const medida = await img.evaluate(el => {
    const r = el.getBoundingClientRect();
    return { caixaL: Math.round(r.width), caixaA: Math.round(r.height),
             natL: el.naturalWidth, natA: el.naturalHeight,
             ajuste: getComputedStyle(el).objectFit };
  });
  console.log('   (caixa ' + medida.caixaL + 'x' + medida.caixaA
            + ', imagem ' + medida.natL + 'x' + medida.natA + ')');
  ok(medida.ajuste === 'contain', 'a caixa acomoda a imagem em vez de a cortar');

  // E agora o que interessa: os pixéis. Desenha-se o que está no ecrã num
  // canvas e procuram-se as três faixas da imagem original.
  const tiro = await p.screenshot({ type: 'png' });
  const caixa = await img.evaluate(el => {
    const r = el.getBoundingClientRect();
    return { x: Math.round(r.left), y: Math.round(r.top),
             w: Math.round(r.width), h: Math.round(r.height) };
  });
  const cores = await p.evaluate(async ({ dados, caixa }) => {
    const im = new Image();
    await new Promise(res => { im.onload = res; im.src = 'data:image/png;base64,' + dados; });
    const c = document.createElement('canvas');
    c.width = im.width; c.height = im.height;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.drawImage(im, 0, 0);
    const dpr = im.width / innerWidth;
    const d = x.getImageData(Math.round(caixa.x * dpr), Math.round(caixa.y * dpr),
                             Math.round(caixa.w * dpr), Math.round(caixa.h * dpr)).data;
    let verde = 0, vermelho = 0, branco = 0;
    for (let i = 0; i < d.length; i += 4) {
      const r = d[i], g = d[i + 1], b = d[i + 2];
      if (g > 120 && r < 90 && b < 90) verde++;
      else if (r > 150 && g < 80 && b < 80) vermelho++;
      else if (r > 240 && g > 240 && b > 240) branco++;
    }
    return { verde, vermelho, branco, total: d.length / 4 };
  }, { dados: tiro.toString('base64'), caixa });
  console.log('   (pixéis: verde ' + cores.verde + ', vermelho ' + cores.vermelho
            + ', branco ' + cores.branco + ' de ' + cores.total + ')');

  // Cada faixa é um sexto da imagem: à vista, cada uma tem de ocupar uma
  // fatia REAL da caixa. Um limiar de trinta pixéis não chegava — com o corte
  // sobravam 147 pixéis vermelhos de bordadura, e isso passava por «está lá».
  // Um por cento da caixa separa as duas situações sem margem para dúvida:
  // com o corte dá 0,3%; inteira, dá 3%.
  const chao = Math.round(cores.total * 0.01);
  ok(cores.verde > chao,
     'o TOPO da imagem está à vista — era o gargalo que o corte comia: '
     + cores.verde + ' pixéis (mínimo ' + chao + ')');
  ok(cores.vermelho > chao,
     'e o FUNDO também — era a base que o corte comia: '
     + cores.vermelho + ' pixéis');
  ok(cores.branco > chao, 'e o corpo da imagem, entre os dois');

  // ---- arrumar ----
  await p.evaluate(async i => {
    await fetch('api.php?action=bar_item_apagar&id=' + i,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, id);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
