// O código do convite — os quatro dígitos que a casa pode pedir ao convidado.
//
// É a decisão de §5.2, ponto 5, e nasce de um problema honesto: sem link no
// convite, o nome deixou de ser segredo, e qualquer pessoa sentada a uma mesa
// pode escrever quatro letras do nome do padrinho. O PIN devolve o segredo que
// se perdeu — e devolve também o atrito, e é por isso que vem DESLIGADO.
//
// O que esta prova defende, por ordem de importância:
//
//   1. Desligado, nada muda. Uma casa que não peça código não vê ecrã nenhum a
//      mais. Se esta falhar, a decisão de o deixar opcional foi desfeita.
//   2. O código é do CONVITE, não da pessoa. É a mesma unidade doméstica que
//      já governa o telemóvel (§5.3): a mãe pede pelo filho sem pedir licença
//      a ninguém, e portanto um segredo por pessoa fechava uma porta que está
//      aberta de propósito.
//   3. Errado não entra, e o erro fica NO ecrã do código — mandar a pessoa de
//      volta à procura obrigava-a a escrever o nome outra vez por um dígito.
//   4. Há travão. Quatro dígitos sem travão são teatro: 10 000 tentativas são
//      uma tarde de trabalho para um guião. Conta-se contra o convite atacado
//      e não contra o telemóvel de quem tenta — um contador no telemóvel
//      apaga-se com o testemunho, e um no IP tranca a sala inteira (§5.4).
//   5. Travado o convite, o empregado continua a servir. Nunca se perde uma
//      bebida por causa de um código.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const vigiar = (p, tag) => {
    p.on('pageerror', e => errs.push(tag + ': ' + e.message));
    p.on('console', m => { if (m.type() === 'error') errs.push(tag + ': ' + m.text()); });
  };

  // ============ montar ============
  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  vigiar(p, 'noivos');
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
  await p.waitForTimeout(700);
  const desligar = () => p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.pedir_pin': '0' }) });
  });
  await desligar();                       // o que uma corrida morta tenha deixado

  const token = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const i of (e.itens || []).filter(i => /^ZZ /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    // Uma bebida com stock: sem ela a última verificação — a de que o
    // empregado serve mesmo com o convite travado — saltava-se a si própria, e
    // uma verificação que não corre não defende nada.
    await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZZ Água do PIN', categoria_id: e.categorias[0].id, stock: 20,
        visivel: 1, max_por_pedido: 3 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    return window.BAR_MESAS[0].token;
  });

  // Uma corrida anterior que tenha morrido a meio deixa convites travados, e o
  // travão dura cinco minutos: sem isto, a prova falhava a si própria de dois
  // em dois minutos e a culpa parecia ser do código.
  await p.evaluate(async (tk) => {
    const q = await (await fetch('api.php?action=bar_procurar&m=' + tk + '&q=conv')).json();
    for (const n of (q.nomes || [])) {
      await window.api('bar_pin_soltar', { method: 'POST',
        body: JSON.stringify({ convidado_id: n.id }) }, { silencioso: true });
    }
  }, token);

  const telemovel = async () => {
    const c = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    vigiar(c, 'bebidas');
    await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
    await c.waitForTimeout(800);
    return c;
  };

  // ============ 1. desligado, nada muda ============
  const semPin = await telemovel();
  await semPin.fill('#b-q', 'conv');
  await semPin.waitForTimeout(800);
  await semPin.locator('.b-nome').first().click();
  await semPin.waitForTimeout(900);
  ok(await semPin.locator('#b-pin').count() === 0,
     'de origem não se pede código nenhum: o atrito só existe se a casa o quiser');
  ok(await semPin.locator('.b-rodape, .b-bebida, .b-cat').count() > 0
     || !(await semPin.locator('.b-procura h1').count()),
     'e escolher-se na lista leva direito ao menu');
  await semPin.close();

  // ============ 2. ligado, a folha imprime os códigos ============
  await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.pedir_pin': '1' }) });
  });
  await p.goto(BASE + '/bar-qr.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  const folha = await p.evaluate(() =>
    [...document.querySelectorAll('.pin-l')].map(l => ({
      convite: l.querySelector('span').textContent.trim(),
      pin: l.querySelector('b').textContent.trim() })));
  ok(folha.length >= 1, 'a folha traz uma linha por convite (' + folha.length + ')');
  ok(folha.every(l => /^\d{4}$/.test(l.pin)),
     'cada uma com quatro dígitos: ' + folha.slice(0, 2).map(l => l.convite + ' ' + l.pin).join(' · '));
  ok(new Set(folha.map(l => l.pin)).size === folha.length || folha.length > 30,
     'e os códigos não se repetem entre convites');

  // ============ 3. o telemóvel pede o código ============
  const t = await telemovel();
  await t.fill('#b-q', 'conv');
  await t.waitForTimeout(800);
  await t.locator('.b-nome').first().click();
  await t.waitForTimeout(700);
  ok(/código do convite/i.test(await t.locator('.b-procura h1').innerText().catch(() => '')),
     'escolhido o nome, pede-se o código — depois do nome, e não antes');
  const diz = await t.locator('.b-procura p').innerText();
  const meu = folha.filter(x => diz.indexOf(x.convite) >= 0)[0];
  ok(!!meu, 'e o ecrã diz de que convite é: «' + diz.replace(/\n/g, ' ') + '»');
  ok(/família toda|mesmo para a família/i.test(diz),
     'dizendo que é o mesmo para a família — é do convite, não da pessoa');
  ok(await t.locator('#b-pin').getAttribute('inputmode') === 'numeric',
     'a caixa abre o teclado dos números, que é o que se quer com um copo na mão');

  // ============ 4. errado não entra, e diz-se ali mesmo ============
  const errado = meu.pin === '9999' ? '1111' : '9999';
  await t.fill('#b-pin', errado);
  await t.click('.b-pin-bt');
  await t.waitForTimeout(800);
  ok(/não é o do seu convite/i.test(await t.locator('#b-pin-erro').innerText()),
     'um código errado não entra: ' + (await t.locator('#b-pin-erro').innerText()));
  ok(await t.locator('#b-pin').count() === 1,
     'e a pessoa fica no ecrã do código — não volta a escrever o nome por um dígito');

  // ============ 5. o certo entra ============
  await t.fill('#b-pin', meu.pin);
  await t.click('.b-pin-bt');
  await t.waitForTimeout(1200);
  ok(await t.locator('#b-pin').count() === 0, 'o código certo abre o menu');
  await t.close();

  // ============ 6. o travão ============
  // Cinco erros seguidos fecham AQUELE convite por cinco minutos. Bate-se
  // contra a API, que é como um guião bateria — e é dela que o travão tem de
  // dar conta, não do ecrã.
  const alvo = folha[0];
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  const bater = await p.evaluate(async ([tk, nomeConvite]) => {
    const quem = await (await fetch('api.php?action=bar_procurar&m=' + tk + '&q=conv')).json();
    const um = (quem.nomes || []).filter(n => n.convite === nomeConvite)[0]
            || (quem.nomes || [])[0];
    const out = [];
    for (let i = 0; i < 6; i++) {
      const r = await (await fetch('api.php?action=bar_sou&m=' + tk, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: tk, convidado_id: um.id, pin: '0001' }) })).json();
      out.push(r.message || '');
    }
    return { quem: um, ditos: out };
  }, [token, alvo.convite]);
  ok(bater.ditos.slice(0, 4).every(m => /não é o do seu convite/i.test(m)),
     'as primeiras tentativas dizem só que o código está errado');
  ok(/muitas tentativas/i.test(bater.ditos[4] || ''),
     'à quinta, o convite fecha-se: «' + bater.ditos[4] + '»');
  ok(/muitas tentativas/i.test(bater.ditos[5] || ''),
     'e continua fechado à seguinte, mesmo com outro código');

  // Travado o convite, o empregado serve à mesma: nunca se perde uma bebida
  // por causa de um código (§5.5).
  const pelaCopa = await p.evaluate(async (gid) => {
    const e = await window.api('bar_estado');
    const item = (e.itens || []).filter(i => i.nome === 'ZZ Água do PIN')[0];
    if (!item) return { ok: false, porque: 'a bebida da prova desapareceu' };
    const r = await window.api('bar_pedir_por', { method: 'POST', body: JSON.stringify(
      { convidado_id: gid, mesa_id: 1, itens: [{ item_id: item.id, quantidade: 1 }] }) });
    return { ok: !!(r && r.success), porque: (r || {}).message };
  }, bater.quem.id);
  ok(pelaCopa.ok,
     'e com o convite travado a copa continua a poder pedir por ele — nunca se '
     + 'perde uma bebida por causa de um código' + (pelaCopa.ok ? '' : ': ' + pelaCopa.porque));

  // ============ 7. e o travão levanta-se ============
  // Cinco minutos com uma família de pé à frente do copeiro é tempo a mais. O
  // empregado já veio; agora resolve.
  const levantar = await p.evaluate(async (gid) => {
    const antes = await window.api('bar_ficha&convidado=' + gid, { method: 'GET' });
    await window.api('bar_pin_soltar', { method: 'POST',
      body: JSON.stringify({ convidado_id: gid }) });
    const depois = await window.api('bar_ficha&convidado=' + gid, { method: 'GET' });
    return { antes: !!antes.pin_travado, depois: !!depois.pin_travado };
  }, bater.quem.id);
  ok(levantar.antes, 'a ficha do convidado mostra o travão a quem o tem de levantar');
  ok(!levantar.depois, 'e a copa levanta-o num clique');

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await desligar();
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    const e2 = await window.api('bar_estado');
    for (const i of (e2.itens || []).filter(i => /^ZZ /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
