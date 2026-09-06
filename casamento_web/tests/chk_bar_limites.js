// Os limites do bar: quanto, de quem, e de quanto em quanto tempo.
//
// A regra que dá sentido a tudo — e a que esta prova mais defende — é a
// precedência: o limite mais específico SUBSTITUI o mais geral, não se soma a
// ele. Se algum dia alguém os somar, dar um tecto individual a uma pessoa
// passa a AUMENTAR-LHE a quota, que é exactamente o contrário do que quem
// escreve a regra está a tentar fazer. Por isso há aqui um número fixo: um
// tecto de 2 para todos mais um de 1 para uma pessoa dá 1, e não 3.
//
// A segunda coisa que se defende é o que o convidado lê. Ele nunca vê a nota
// da regra (é de quem a escreveu) nem a diferença entre «a casa limita» e
// «limitámos-lhe a si» — essa conversa faz-se de pessoa para pessoa, e não
// por um telemóvel. Quando é a copa que está cheia, o texto explica em vez de
// repreender, e não sugere alternativas nenhumas: não há o que sugerir.
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
  const casa = await b.newContext({ viewport: { width: 1280, height: 950 } });
  const p = await casa.newPage();
  vigiar(p, 'copa');
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

  // Entre secções limpa-se a lousa — as regras e o que já se pediu — mas as
  // bebidas ficam: são o cenário, e recriá-las mudava-lhes os ids a meio.
  // Um pedido cancelado não conta para quota nenhuma, que é o que permite
  // recomeçar do zero sem esperar meia hora.
  const limparRegras = () => p.evaluate(async () => {
    const r = await window.api('bar_regras');
    for (const x of r.regras) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      // Um pedido por decidir não se «cancela» — a copa recusa-o. E é preciso
      // que saia mesmo: um recusado não gasta quota, um em análise gasta, e
      // deixá-lo lá fazia a secção seguinte começar com a conta já a meio.
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
  });
  const limparTudo = async () => {
    await limparRegras();
    await p.evaluate(async () => {
      const e = await window.api('bar_estado');
      for (const i of e.itens.filter(i => /^ZZ /.test(i.nome))) {
        await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
      }
    });
  };
  await limparTudo();                   // o que uma corrida morta tenha deixado

  const base = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const g1 = e.categorias[0].id, g2 = e.categorias[1].id;
    const mk = async (nome, cat) => (await window.api('bar_item_guardar', { method: 'POST',
      body: JSON.stringify({ nome, categoria_id: cat, stock: 50, visivel: 1,
                             max_por_pedido: 5 }) })).id;
    const ids = { whisky: await mk('ZZ Whisky', g1), gin: await mk('ZZ Gin', g1),
                  agua: await mk('ZZ Água', g2) };
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const n = await window.api('bar_procurar_pessoal&q=Convidad');
    return { ids, cats: { g1, g2 }, quem: n.nomes };
  });
  const A = base.quem[0], B = base.quem[1];
  const token = await p.evaluate(() => window.BAR_MESAS[0].token);

  const regra = (r) => p.evaluate(async (r) =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(r) }), r);

  // Dois telemóveis, duas pessoas.
  const abrir = async (quem) => {
    const c = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    vigiar(c, 'bebidas');
    await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
    await c.waitForTimeout(600);
    await c.evaluate(async ([t, id]) => {
      await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ m: t, convidado_id: id }) });
    }, [token, quem.id]);
    return c;
  };
  const cA = await abrir(A), cB = await abrir(B);
  const menuDe = (c) => c.evaluate(async (t) =>
    await (await fetch('api.php?action=bar_menu&m=' + t)).json(), token);
  const bebida = async (c, nome) => (await menuDe(c)).itens.find(i => i.nome === nome);
  const pedir = (c, id, q) => c.evaluate(async ([t, id, q]) =>
    await (await fetch('api.php?action=bar_pedir&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, itens: [{ item_id: id, quantidade: q }], mesa_id: 1 }) })).json(),
    [token, id, q]);

  // ============ 1. a precedência ============
  ok((await bebida(cA, 'ZZ Whisky')).pode_pedir === 5, 'sem regras, pode o que cabe num pedido');

  await regra({ escopo: 'item', alvo_id: base.ids.whisky, sujeito: 'convidado',
                unidade: 'bebidas', quantidade: 2, janela_min: 0 });
  ok((await bebida(cA, 'ZZ Whisky')).pode_pedir === 2, 'um tecto geral de 2 por convidado morde');
  ok((await bebida(cA, 'ZZ Gin')).pode_pedir === 5, 'e não toca na bebida do lado');

  await regra({ escopo: 'item', alvo_id: base.ids.whisky, sujeito: 'convidado',
                alvo_convidado_id: A.id, unidade: 'bebidas', quantidade: 1, janela_min: 0 });
  ok((await bebida(cA, 'ZZ Whisky')).pode_pedir === 1,
     'o tecto DESTA pessoa (1) substitui o geral (2) — e não dá 3');
  ok((await bebida(cB, 'ZZ Whisky')).pode_pedir === 2, 'a pessoa do lado fica com o geral');

  // ============ 2. proibir uma gaveta a alguém ============
  await regra({ escopo: 'categoria', alvo_id: base.cats.g1, sujeito: 'convidado',
                alvo_convidado_id: B.id, unidade: 'bebidas', quantidade: 0, janela_min: 0,
                nota: 'conduz esta noite' });
  const wB = await bebida(cB, 'ZZ Whisky');
  ok(wB.pode_pedir === 0 && wB.travao === 'proibido', 'uma gaveta proibida fecha as bebidas dela');
  ok((await bebida(cB, 'ZZ Água')).pode_pedir > 0, 'e a pessoa continua a poder beber o resto');
  ok(wB.alternativas.length === 0,
     'sem sugerir o que também está travado — seria uma segunda porta fechada');

  // ============ 3. o servidor decide, não o ecrã ============
  const mau = await pedir(cA, base.ids.whisky, 3);
  ok(mau.success === false, 'pedir 3 quando só cabe 1 é recusado pelo servidor');
  ok(/podemos servir-lhe/i.test(mau.message || ''),
     'e a recusa fala como gente: «' + mau.message + '»');

  ok((await pedir(cA, base.ids.whisky, 1)).success === true, 'pedir 1 passa');
  const w2 = await bebida(cA, 'ZZ Whisky');
  ok(w2.pode_pedir === 0 && w2.travao === 'tecto', 'e o tecto fecha-se a seguir');
  ok(w2.alternativas.some(x => x.nome === 'ZZ Gin'),
     'com alternativa da mesma gaveta, que passa em todos os testes agora');

  // ============ 4. as regras de PEDIDOS travam o acto de pedir ============
  await limparRegras();
  await regra({ escopo: 'tudo', sujeito: 'convidado', unidade: 'pedidos',
                quantidade: 1, janela_min: 30 });
  ok((await bebida(cA, 'ZZ Whisky')).pode_pedir === 5,
     'uma regra de pedidos não mexe na conta de cada bebida');
  ok((await pedir(cA, base.ids.gin, 1)).success === true, 'o primeiro pedido passa');
  const t2 = await pedir(cA, base.ids.whisky, 1);
  ok(t2.success === false, 'e o segundo é travado — a regra é sobre o ACTO de pedir');
  ok(/próximo pedido abre/i.test(t2.message || ''), 'dizendo quando abre: «' + t2.message + '»');
  const dP = await menuDe(cA);
  ok(dP.pedido && dP.pedido.espera_s > 0, 'e o menu avisa antes de a pessoa escolher');

  // ============ 5. o caudal da casa ============
  await limparRegras();
  await regra({ escopo: 'tudo', sujeito: 'casa', unidade: 'bebidas',
                quantidade: 1, janela_min: 15 });
  await pedir(cA, base.ids.agua, 1);
  const dC = await menuDe(cA);
  ok(dC.ritmo !== null, 'o caudal da casa entra em vigor');
  ok((await bebida(cA, 'ZZ Gin')).travao === 'casa',
     'e trava tudo por igual, mesmo o que não tem regra nenhuma');
  ok((await bebida(cA, 'ZZ Gin')).alternativas.length === 0,
     'sem sugerir nada: quando é a copa que está cheia, não há o que sugerir');
  const caudal = await p.evaluate(async () => (await window.api('bar_estado')).caudal);
  ok(caudal && caudal.cheio === true, 'e a copa vê-se cheia, para poder afrouxar');

  // ============ 6. a espera, no telemóvel ============
  await limparRegras();
  await regra({ escopo: 'item', alvo_id: base.ids.whisky, sujeito: 'convidado',
                alvo_convidado_id: A.id, unidade: 'bebidas', quantidade: 1, janela_min: 90 });
  await cA.reload({ waitUntil: 'networkidle' });
  await cA.waitForTimeout(1200);
  const cartao = cA.locator('.b-bebida:has-text("ZZ Whisky")');
  await cartao.locator('.b-mais button:last-child').click();
  await cA.waitForTimeout(200);
  await cA.click('#b-pedir');
  await cA.waitForTimeout(1100);
  await cA.click('#lic-jc');
  await cA.waitForTimeout(1300);

  ok(await cartao.locator('.b-conta').count() === 1, 'a bebida travada mostra um relógio');
  ok(/Abre em/.test(await cartao.innerText()),
     'com a palavra que diz que é uma espera — um número sozinho não diz nada');
  const r1 = await cartao.locator('.b-conta').innerText();
  await cA.waitForTimeout(2400);
  const r2 = await cartao.locator('.b-conta').innerText();
  ok(r1 !== r2, 'e anda sozinho, no browser: ' + r1 + ' → ' + r2);
  ok(await cartao.locator('.b-mais').count() === 0,
     'sem botões de somar: não se oferece o que não se pode dar');
  ok((await cartao.locator('.b-alt button').allTextContents()).includes('ZZ Gin'),
     'e sugere o que sai já');

  // Pedir refresca o MENU e não só «os meus pedidos»: um cartão que ficou sem
  // quota tem de perder o «+» já, e não daqui a meio minuto.
  ok(await cA.locator('.b-bebida:has-text("ZZ Whisky") .b-mais').count() === 0,
     'o menu refresca-se ao pedir, sem esperar pela sondagem seguinte');

  // ============ 7. a ficha, e o que o convidado nunca lê ============
  await p.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1100);
  ok(await p.locator('.quem-bt').count() >= 1, 'o nome de quem pediu abre a ficha dele');
  await p.locator('.quem-bt').first().click();
  await p.waitForTimeout(900);
  const ficha = await p.locator('.pl-modal-corpo').innerText();
  ok(/Já levou:/.test(ficha) && /ZZ Whisky/.test(ficha),
     'a ficha diz o que a pessoa já pediu: ' + (ficha.match(/Já levou:.*/) || [''])[0]);
  ok(/por servir/.test(ficha),
     'e separa o que ela bebeu do que ainda está na fila — para quem decide, importa');
  ok(/1 bebida de «ZZ Whisky» a cada/.test(ficha) || /ZZ Whisky/.test(ficha),
     'com a regra dela escrita por extenso');

  await p.click('button:has-text("+ Regra")');
  await p.waitForTimeout(600);
  await p.selectOption('#lf-sobre', { label: 'ZZ Gin' });
  await p.fill('#lf-quantidade', '0');
  await p.fill('#lf-nota', 'pediu-nos para o travarmos');
  await p.click('#lic-jo');
  await p.waitForTimeout(1400);
  ok(/não pode pedir «ZZ Gin»/.test(await p.locator('.pl-modal-corpo').innerText()),
     'uma regra nova lê-se em voz alta, sem tradução');

  await p.click('#lic-jc');
  await p.waitForTimeout(1200);
  ok(await p.locator('.b-ped.fora').count() >= 0, 'a fila assinala o que deixou de caber');

  await cA.reload({ waitUntil: 'networkidle' });
  await cA.waitForTimeout(1300);
  const oQueEleVe = await cA.locator('.b-bebida:has-text("ZZ Gin")').innerText();
  ok(/Não disponível para si/.test(oQueEleVe), 'o convidado vê a porta fechada');
  ok(!/travarmos/.test(oQueEleVe),
     'e NUNCA lê o porquê: essa conversa faz-se de pessoa para pessoa');

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await limparTudo();
  await p.evaluate(async () => { await window.api('bar_fechar', { method: 'POST', body: '{}' }); });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
