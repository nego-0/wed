// Tela de posicionamento livre: arrastar blocos no cartão impresso e no
// convite digital, com alinhamento magnético, cadeado e reposição.
//
// O que aqui se prova só se prova mexendo: um bloco que SE MOVE mesmo quando
// arrastado, um que COLA ao centro da tela quando lá passa perto, um trancado
// que RESISTE, e o deslocamento a sobreviver à gravação e a chegar à peça que
// o convidado recebe.
const { chromium } = require('playwright-core');
const janela = require('./_janela');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
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
  // O admin entra sem casamento aberto: escolhe-se o nº1, onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  // Deixar a peça como o design a desenhou: a prova mede deslocamentos, e uma
  // corrida anterior deixava composição gravada por baixo dela.
  await api('defs_save', { defs: { 'cartao.posicoes': '', 'cartao.trancados': '',
                                   'layout.posicoes': '' } });

  /** Arrasta um elemento dx/dy pixels de ecrã, com passos (senão o pointermove não conta). */
  async function arrastar(sel, dx, dy, opc = {}) {
    const caixa = await p.locator(sel).first().boundingBox();
    if (!caixa) throw new Error('sem caixa: ' + sel);
    const x = caixa.x + caixa.width / 2, y = caixa.y + caixa.height / 2;
    if (opc.alt) await p.keyboard.down('Alt');
    await p.mouse.move(x, y);
    await p.mouse.down();
    if (opc.shift) await p.keyboard.down('Shift');
    for (let i = 1; i <= 8; i++) await p.mouse.move(x + dx * i / 8, y + dy * i / 8);
    await p.mouse.up();
    if (opc.shift) await p.keyboard.up('Shift');
    if (opc.alt) await p.keyboard.up('Alt');
    await p.waitForTimeout(150);
  }
  const posDe = k => p.evaluate(k => {
    const el = document.querySelector(`#escala [data-camada="${k}"]`);
    return { px: el.style.getPropertyValue('--px'), py: el.style.getPropertyValue('--py'),
             pa: el.style.getPropertyValue('--pa'),
             est: JSON.parse(JSON.stringify(window.est ? est.pos : {})) };
  }, k);

  // ============ 1. o cartão impresso ============
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  ok(await p.locator('#guias').count() === 1, 'o cartão tem tela de guias');
  ok(await p.locator('#escala .cartao [data-camada]').count() > 8, 'e as camadas todas de pé');

  // Um bloco de texto do meio do cartão: longe das bordas, para o íman do
  // centro não o apanhar por acidente e a prova medir mesmo o arrasto.
  await p.evaluate(() => { zoom = .5; aplicarZoom(); });
  const antes = await p.evaluate(() => JSON.stringify(est.pos));
  ok(antes === '{}', 'nada movido à partida — a peça é a que o design desenhou');

  await arrastar('#escala [data-camada="fecho"]', 60, -90, { shift: true });
  const dep = await posDe('fecho');
  ok(dep.px !== '' && dep.py !== '', 'arrastar a frase final desloca-a mesmo');
  const nx = parseFloat(dep.px), ny = parseFloat(dep.py);
  // 60 px de ecrã a 50% de zoom = 120 px de cartão = 16,7% da largura (720).
  ok(Math.abs(nx - 16.7) < 2.5, `o deslocamento é em % do cartão, não em px do ecrã (${nx}%)`);
  ok(ny < -10, `e a vertical acompanha (${ny}%)`);
  ok(await p.locator('#escala [data-camada="fecho"].movida').count() === 1,
     'a camada movida fica assinalada na peça');
  ok(/movid/i.test(await p.locator('#camadas .camada[data-k=fecho]').innerText() + ' ' +
     await p.locator('#camadas .camada[data-k=fecho]').innerHTML()) ||
     await p.locator('#camadas .camada[data-k=fecho] .mini').count() > 1,
     'e ganha marca na lista de camadas');
  ok(/%/.test(await p.locator('#props .pos-linha .val').innerText()),
     'as propriedades mostram o deslocamento em percentagem');

  // ---- o íman: largar perto do centro cola ao centro ----
  await p.evaluate(() => { definirPos('fecho', 0, 0); selecionar('fecho'); });
  // A frase final está centrada em x: empurrá-la 3 px tira-a do centro, e o
  // íman tem de a trazer de volta a 0 exato.
  await arrastar('#escala [data-camada="fecho"]', 3, -40);
  const colado = await posDe('fecho');
  ok(parseFloat(colado.px || 0) === 0,
     `o bloco cola-se ao centro da tela quando passa perto (x=${colado.px || 0})`);
  ok(parseFloat(colado.py || 0) !== 0, 'sem prender o outro eixo');

  // ---- Shift desliga o íman ----
  await p.evaluate(() => definirPos('fecho', 0, 0));
  await arrastar('#escala [data-camada="fecho"]', 3, -40, { shift: true });
  const solto = await posDe('fecho');
  ok(parseFloat(solto.px || 0) !== 0, 'com Shift o íman não prende — o ajuste é fino');

  // ---- as setas afinam ----
  await p.evaluate(() => { definirPos('data', 0, 0); selecionar('data'); document.body.focus(); });
  await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowRight');
  const seta = await posDe('data');
  ok(Math.abs(parseFloat(seta.px || 0) - 0.5) < 0.001,
     `duas setas = meio ponto percentual (${seta.px})`);
  await p.keyboard.down('Shift'); await p.keyboard.press('ArrowDown'); await p.keyboard.up('Shift');
  ok(Math.abs(parseFloat((await posDe('data')).py || 0) - 2) < 0.001, 'com Shift, o passo é largo');

  // ---- trancar resiste ao arrasto ----
  await p.evaluate(() => { definirPos('abertura', 0, 0); alternarTranca('abertura'); });
  ok(await p.locator('#camadas .camada[data-k=abertura].trancada').count() === 1,
     'trancar a camada marca-a na lista');
  await arrastar('#escala [data-camada="abertura"]', 50, 50);
  ok(!(await posDe('abertura')).px, 'e a camada trancada NÃO se deixa arrastar');
  ok(await p.evaluate(() => { alternarCamada('abertura'); return est.camadas.abertura !== 0; }),
     'nem se deixa esconder');
  await p.evaluate(() => alternarTranca('abertura'));

  // ---- repor ----
  await p.evaluate(() => { definirPos('frase', 8, 8); reporPosicao('frase'); });
  ok(!(await posDe('frase')).px, 'repor devolve a camada ao sítio de origem');

  await p.screenshot({ path: OUT + '/tela-livre-cartao.png' });

  // ---- virar a camada (Alt + arrastar) ----
  await p.evaluate(() => { definirPos('nomes', 0, 0, 0); selecionar('nomes'); });
  await arrastar('#escala [data-camada="nomes"]', 70, 70, { alt: true });
  const virada = await posDe('nomes');
  ok(parseFloat(virada.pa || 0) !== 0, `Alt + arrastar vira a camada (${virada.pa || 0}°)`);
  ok(parseFloat(virada.pa) % 15 === 0, 'e a volta encosta de 15 em 15 graus');
  ok(parseFloat(virada.px || 0) === 0 && parseFloat(virada.py || 0) === 0,
     'sem a deslocar — virar é virar');
  ok(/rotate/.test(await p.evaluate(() =>
       getComputedStyle(document.querySelector('#escala [data-camada="nomes"]')).rotate ? 'rotate' : '')),
     'o navegador aplica mesmo a volta');
  ok(/°/.test(await p.locator('#props .pos-linha .val').innerText()),
     'e as propriedades dizem-na');

  // A barra do painel é o outro caminho para o mesmo sítio.
  await p.evaluate(() => mudarAngulo('nomes', -12));
  ok(Math.abs(parseFloat((await posDe('nomes')).pa) + 12) < 0.001, 'a barra do painel também vira');
  // Alt + setas: um grau de cada vez.
  await p.evaluate(() => { document.body.focus(); });
  await p.keyboard.down('Alt'); await p.keyboard.press('ArrowRight'); await p.keyboard.up('Alt');
  ok(Math.abs(parseFloat((await posDe('nomes')).pa) + 11) < 0.001, 'e Alt + seta afina um grau');
  await p.evaluate(() => definirPos('nomes', 0, 0, 0));

  // ---- gravar e reler ----
  await p.evaluate(() => { definirPos('fecho', -6.5, -12.25, 0); definirPos('data', 3, 0, -7.5);
                           est.trancados = ['moldura']; });
  ok(await p.evaluate(() => guardar()), 'a composição grava-se');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  const relido = await p.evaluate(() => ({ pos: est.pos, tr: est.trancados }));
  ok(relido.pos.fecho && relido.pos.fecho.x === -6.5 && relido.pos.fecho.y === -12.25,
     'e volta inteira ao reabrir o editor');
  ok(relido.tr.indexOf('moldura') >= 0, 'com as camadas trancadas incluídas');
  ok(relido.pos.data && relido.pos.data.a === -7.5, 'e a volta sobrevive à gravação');
  ok(relido.pos.fecho && !relido.pos.fecho.a,
     'um bloco só movido continua a gravar-se com dois números, como antes de haver volta');
  ok(await p.locator('#escala [data-camada="fecho"].movida').count() === 1,
     'o cartão nasce já com a camada no sítio novo');

  // ---- chega à peça que se imprime ----
  await p.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
  const naFolha = await p.evaluate(() => {
    const el = document.querySelector('.cartao [data-camada="fecho"]');
    if (!el) return null;
    return { px: el.style.getPropertyValue('--px'), tr: getComputedStyle(el).translate };
  });
  ok(naFolha && parseFloat(naFolha.px) === -6.5, 'a folha de impressão traz o deslocamento gravado');
  ok(naFolha && /-46\.8|-46,8/.test(naFolha.tr),
     `e o navegador traduz -6,5% em -46,8 px de cartão (${naFolha && naFolha.tr})`);

  // ============ 2. o convite digital ============
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  // A camada do envelope é a que tem a tela de tamanho fixo.
  await p.evaluate(() => irCamada('capa'));
  await p.waitForTimeout(1200);
  ok(await p.locator('#props .grupo h4').count() >= 1, 'o envelope traz painel de posição dos blocos');
  ok((await p.locator('#props .pos-linha').count()) >= 4, 'com um bloco por peça do envelope');

  const tela = p.frameLocator('#tela');
  ok(await tela.locator('#cover [data-livre="capa:nomes"]').count() === 1,
     'e a tela marca os blocos móveis');
  ok(await tela.locator('#cover .ed-guias').count() === 1, 'com guias próprias');

  // Arrastar os nomes dentro do envelope.
  // boundingBox() de um elemento dentro de um iframe já vem em coordenadas da
  // janela principal: somar a caixa do iframe punha o rato ao lado da peça.
  const alvo = await tela.locator('#cover .cover-names').boundingBox();
  const cx = alvo.x + alvo.width / 2, cy = alvo.y + alvo.height / 2;
  await p.mouse.move(cx, cy); await p.mouse.down();
  await p.keyboard.down('Shift');
  for (let i = 1; i <= 8; i++) await p.mouse.move(cx + 5 * i, cy - 90 * i / 8);
  await p.mouse.up(); await p.keyboard.up('Shift');
  await p.waitForTimeout(400);

  const gravadoJs = await p.evaluate(() => EST.pos['capa:nomes'] || '');
  ok(gravadoJs !== '', 'arrastar na tela chega ao editor: ' + (gravadoJs || '(nada)'));
  ok(parseFloat(gravadoJs.split(' ')[1]) < 0, 'com o sinal certo — para cima é negativo');
  ok(/%/.test(await p.locator('#props .pos-linha .val').first().innerText() +
              await p.locator('#props .pos-linha .val').nth(2).innerText()),
     'e o painel passa a mostrar o deslocamento');

  await p.screenshot({ path: OUT + '/tela-livre-digital.png' });

  // Gravar e confirmar que o convite do convidado o traz.
  ok(await p.evaluate(() => guardar()), 'a composição do envelope grava-se');
  const codigo = await p.evaluate(async () => {
    const r = await fetch('api.php?action=convite_list', { headers: { 'X-CSRF-Token': window.CSRF } });
    const d = await r.json();
    const l = d.convites || d.itens || d.dados || [];
    return l[0] && l[0].codigo;
  });
  await p.goto(BASE + '/convite-digital.php?c=' + codigo, { waitUntil: 'networkidle' });
  const noConvite = await p.evaluate(() => {
    const el = document.querySelector('#cover .cover-names');
    return el ? getComputedStyle(el).translate : null;
  });
  ok(noConvite && noConvite !== 'none' && !/^0px 0px$/.test(noConvite),
     `o convidado recebe a composição feita no editor (${noConvite})`);

  // ---- repor deixa a peça como o design a desenhou ----
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  await p.evaluate(() => { irCamada('capa'); reporLivre('capa:nomes'); });
  ok(await p.evaluate(() => !EST.pos['capa:nomes']), 'repor limpa o deslocamento');
  ok(await p.evaluate(() => guardar()), 'e grava-se de volta');
  await p.goto(BASE + '/convite-digital.php?c=' + codigo, { waitUntil: 'networkidle' });
  ok(await p.evaluate(() => {
       const t = getComputedStyle(document.querySelector('#cover .cover-names')).translate;
       return t === 'none' || /^0px( 0px)?$/.test(t);
     }), 'e o convite volta a ser exatamente o de origem');

  // ============ 3. as páginas do corpo (texto que corre) ============
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  await p.evaluate(() => irCamada('convite'));
  await p.waitForTimeout(900);
  ok(await p.locator('#props .grupo h4').count() >= 1,
     'a página do convite também traz painel de posição');
  ok(/largura/.test(await p.locator('#props .grupo').innerText()),
     'e avisa que aqui a medida é a largura, porque a página cresce com o texto');
  ok(await tela.locator('#convite [data-livre="convite:cartao"]').count() === 1,
     'o cartão do convidado é um bloco móvel');

  // A tela do editor mostra o conteúdo (a capa fica escondida): rolar até lá.
  await p.evaluate(() => enviarTela({ tipo:'irPara', sec:'convite' }));
  await p.waitForTimeout(900);
  const cartao = await tela.locator('#convite .guest-card').boundingBox();
  await p.mouse.move(cartao.x + cartao.width / 2, cartao.y + 30); await p.mouse.down();
  await p.keyboard.down('Shift');
  for (let i = 1; i <= 8; i++) await p.mouse.move(cartao.x + cartao.width / 2 + 4 * i, cartao.y + 30 + 6 * i);
  await p.mouse.up(); await p.keyboard.up('Shift');
  await p.waitForTimeout(400);
  const noCorpo = await p.evaluate(() => EST.pos['convite:cartao'] || '');
  ok(noCorpo !== '', 'arrastar um bloco numa página que corre também conta: ' + (noCorpo || '(nada)'));
  ok(parseFloat(noCorpo.split(' ')[1]) > 0, 'e para baixo é positivo');

  // Um bloco de uma secção que não existe (nem existiu) não passa a validação.
  await p.evaluate(() => { EST.pos['inventada:coisa'] = '5 5'; });
  ok(await p.evaluate(() => guardar()), 'grava-se com o intruso pelo meio');
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  ok(await p.evaluate(() => !EST.pos['inventada:coisa']), 'e o intruso não sobrevive');
  ok(await p.evaluate(() => !!EST.pos['convite:cartao']), 'mas o bloco a sério sim');

  await p.goto(BASE + '/convite-digital.php?c=' + codigo, { waitUntil: 'networkidle' });
  const corpoConvidado = await p.evaluate(() => ({
    t: getComputedStyle(document.querySelector('#convite .guest-card')).translate,
    // A unidade das páginas é a LARGURA: os dois eixos medem-se pela mesma régua.
    u: getComputedStyle(document.querySelector('#convite')).getPropertyValue('--uw').trim()
  }));
  ok(corpoConvidado.t !== 'none' && !/^0px/.test(corpoConvidado.t),
     `o convidado recebe o bloco onde ele ficou (${corpoConvidado.t})`);
  ok(corpoConvidado.u !== '', 'e a página traz a sua unidade de medida');

  // ---- uma secção livre, acabada de criar ----
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  const secId = await p.evaluate(() => { juntarBloco(); return SEC; });
  await p.waitForTimeout(2500);
  ok(/^bl/.test(secId), 'cria-se uma secção livre: ' + secId);
  ok(await p.locator('#props .grupo h4').count() >= 1,
     'uma secção acabada de criar já se deixa compor, sem recarregar a página');
  ok(await tela.locator(`#${secId} [data-livre="${secId}:titulo"]`).count() === 1,
     'e a tela marca-lhe os blocos');
  await p.evaluate(s => { moverLivre(s + ':titulo', 4, 3); }, secId);
  ok(await p.evaluate(s => !!EST.pos[s + ':titulo'], secId), 'os blocos dela movem-se');
  ok(await p.evaluate(() => guardar()), 'e a composição dela grava-se');
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  ok(await p.evaluate(s => !!EST.pos[s + ':titulo'], secId),
     'sobrevivendo à gravação (o padrão "bl…:elemento" passa a validação)');

  // ---- deixar a casa como se encontrou ----
  await p.evaluate(s => { EST.pos = {}; EST.blocos = EST.blocos.filter(b => b.id !== s);
                          EST.ordem = EST.ordem.filter(x => x !== s); }, secId);
  await p.evaluate(() => guardar());
  await p.waitForTimeout(600);

  // ---- o que o servidor recusa ----
  // ---- o que o servidor recusa ----
  // A página do convidado não tem CSRF: a gravação faz-se de dentro do editor.
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  const lixo = await api('defs_save', { defs: { 'layout.posicoes': '{"nao.existe":"5 5"}',
                                                'cartao.posicoes': '{"fecho":"999 -999"}' } });
  ok(lixo.success, 'gravar posições estranhas não estoira o servidor');
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const guardado = await p.evaluate(() => JSON.parse(JSON.stringify(est.pos)));
  ok(!guardado['nao.existe'], 'um bloco que não existe não fica guardado');
  ok(guardado.fecho && guardado.fecho.x === 60 && guardado.fecho.y === -60,
     `e o deslocamento é travado no limite (${JSON.stringify(guardado.fecho)})`);

  // Deixar a casa como se encontrou: a peça volta ao desenho de origem.
  await api('defs_save', { defs: { 'cartao.posicoes': '', 'cartao.trancados': '',
                                   'layout.posicoes': '' } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
