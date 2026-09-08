// A prova da terceira passagem do bar (docs/modulo-bar.md §28).
//
// Oito coisas mudaram, e o que aqui se defende é o que se parte se alguma
// delas for desfeita sem se dar por isso:
//
//   1. O módulo obedece ao tema. Era escuro por decreto e deixou de ser; se
//      alguém repuser o decreto, a copa volta a ignorar a escolha do casal.
//      (O contraste nos quatro temas está em chk_bar.js §8b.)
//   2. As Regras do Bar são uma ABA da copa, e não um link para bar.php — a
//      página dos noivos, que ao copeiro responde com a tela de entrada.
//   3. O garçom lança pedidos. Não lançava: o ecrã pedia a lista de bebidas a
//      uma acção que é só da copa, e recebia um 403 disfarçado de lista vazia.
//   4. Uma regra da casa contada em PEDIDOS trava o acto de pedir, e diz-se na
//      faixa da página — em vez de fechar as bebidas uma a uma com a mensagem
//      errada, que era como as duas telas discordavam.
//   5. A ficha de uma pessoa é sobre bebida, e não sobre aparelhos.
//   6. Os selects com muitas opções têm procura por dentro.
//   7. Uma regra de pessoa é a MESMA janela das regras da casa, com o «a quem»
//      preenchido — e não uma segunda gramática.
//   8. A página do convidado abre e fecha como a página de um casamento.
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
  const casa = await b.newContext({ viewport: { width: 1280, height: 980 } });
  const p = await casa.newPage();
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
  await p.waitForTimeout(800);

  const cenario = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const i of (e.itens || []).filter(i => /^ZT /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    for (const r of (e.regras || []).filter(r => /^ZT /.test(r.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZT Tinto', categoria_id: e.categorias[0].id, stock: 40,
        visivel: 1, max_por_pedido: 4 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    const d = await window.api('bar_procurar_pessoal&limite=500', { method: 'GET' });
    return { token: window.BAR_MESAS[0].token,
             quantos: (d.nomes || []).length,
             pessoa: (d.nomes || [])[0] };
  });
  const token = cenario.token;

  // ============ 1. o módulo obedece ao tema ============
  // A copa era escura em qualquer tema, por uma família de tokens que tema
  // nenhum redefinia (os --sala-*). Saíram. O que se defende aqui é que não
  // voltam: um token de paleta paralela é o princípio de um módulo que se
  // desliga outra vez do sistema.
  const folha = await p.evaluate(async () => await (await fetch('assets/estilo.css')).text());
  ok(!/--sala-[a-z]+\s*:/.test(folha),
     'não há paleta paralela: o bar veste-se pelos tokens do tema, como o resto da casa');
  const barCss = await p.evaluate(async () => await (await fetch('assets/bar.css')).text());
  ok(!/b-noite/.test(barCss),
     'e a classe do «salão escuro» desapareceu da folha do módulo');

  // ============ 2. as Regras do Bar são uma aba da copa ============
  const copa = await casa.newPage();
  vigiar(copa, 'copa');
  await copa.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await copa.waitForTimeout(1200);
  const pastilhas = await copa.locator('.b-pastilhas .b-pilula').allInnerTexts();
  ok(/Regras do Bar/.test(pastilhas.join('|')),
     'a copa tem a aba «Regras do Bar»: ' + pastilhas.map(t => t.split('\n')[0]).join(' · '));
  // E vem DEPOIS de «Os números», que é onde foi pedida.
  const iNum = pastilhas.findIndex(t => /Os números/.test(t));
  const iReg = pastilhas.findIndex(t => /Regras do Bar/.test(t));
  ok(iNum >= 0 && iReg === iNum + 1, 'e vem logo a seguir a «Os números»');

  await copa.locator('.b-pilula:has-text("Regras do Bar")').click();
  await copa.waitForTimeout(900);
  ok(/copa\.php/.test(copa.url()),
     'e abre NA copa — não manda ninguém para bar.php, que ao copeiro é a porta da rua');
  const painel = await copa.locator('#pn-regras-cx').innerText();
  ok(/Como o bar se porta/.test(painel) && /Limites e intervalos/.test(painel)
     && /Motivos de recusa/.test(painel),
     'com as três secções: as definições, os limites, e os motivos');

  // ============ 3. o garçom lança pedidos ============
  // A lista de bebidas para «pedir por alguém» vinha de bar_estado, que é só
  // da copa. Agora tem porta própria, aberta aos dois postos.
  const ges = await casa.newPage();
  vigiar(ges, 'gestao');
  await ges.goto(BASE + '/gestao.php', { waitUntil: 'networkidle' });
  await ges.waitForTimeout(900);
  // A conta de uma corrida anterior fica na base. Um email por conta: convidar
  // outra vez não passa, a senha nova nunca sai, e a prova seguia em frente a
  // pedir «window.api» numa página de login. Tira-se primeiro.
  await ges.evaluate(async () => {
    const d = await window.api('acesso_lista', { method: 'GET', silencioso: true });
    for (const a of ((d && d.acessos) || [])) {
      if (a.email === 'zt.garcom@exemplo.pt') {
        await window.api('conta_apagar_do_casamento&utilizador=' + a.utilizador_id,
                         { method: 'POST', silencioso: true });
      }
    }
  });
  await ges.selectOption('#a-papel', 'entregador');
  await ges.fill('#a-email', 'zt.garcom@exemplo.pt');
  await ges.fill('#a-nome', 'ZT Garçom');
  await ges.click('button:has-text("Convidar")');
  await ges.waitForTimeout(1600);
  const senha = await ges.locator('#senha-nova .cod').innerText().catch(() => '');
  ok(!!senha, 'cria-se uma conta de garçom para provar isto');

  const dele = await b.newContext({ viewport: { width: 390, height: 844 } });
  const gar = await dele.newPage();
  vigiar(gar, 'entregas (garçom)');
  await gar.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await gar.fill('input[name=utilizador]', 'zt.garcom@exemplo.pt');
  await gar.fill('input[name=senha]', senha);
  await gar.click('button[type=submit]');
  await gar.waitForLoadState('networkidle');
  await gar.waitForTimeout(1200);
  ok(/entregas\.php/.test(gar.url()), 'o garçom entra e aterra nas entregas');

  // A leitura que o ecrã faz ao abrir a janela: tem de trazer bebidas.
  const paraPedir = await gar.evaluate(async () =>
    await window.api('bar_itens_pedir', { method: 'GET' }));
  ok(paraPedir && paraPedir.success === true,
     'e a lista de bebidas para pedir abre-se-lhe — era aqui que vinha «Só a copa.»');
  ok((paraPedir.itens || []).some(i => i.nome === 'ZT Tinto'),
     'com as bebidas lá dentro (' + (paraPedir.itens || []).length + ')');

  // E o pedido segue mesmo.
  const lancado = await gar.evaluate(async ([pid, iid]) =>
    await window.api('bar_pedir_por', { method: 'POST', body: JSON.stringify(
      { convidado_id: pid, itens: [{ item_id: iid, quantidade: 1 }] }) }),
    [cenario.pessoa.id, paraPedir.itens.filter(i => i.nome === 'ZT Tinto')[0].id]);
  ok(lancado && lancado.success === true,
     'e o pedido lançado por ele entra mesmo: ' + ((lancado.pedido || {}).codigo || lancado.message));

  // ============ 4. uma regra da casa em PEDIDOS trava o acto de pedir ======
  // A mesma linha era lida de duas maneiras: barRitmoDaCasa fechava todas as
  // bebidas com a mensagem do caudal, e barVeredictoPedido ignorava-a por não
  // ser «de convidado». Agora tem um dono só.
  await p.evaluate(async () => {
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'casa', unidade: 'pedidos', quantidade: 1,
        janela_min: 60, nota: 'ZT trava o gesto' }) });
  });
  const conv = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  vigiar(conv, 'convidado');
  await conv.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await conv.waitForTimeout(900);
  const menu = await conv.evaluate(async ([t, id]) => {
    await (await fetch('api.php?action=bar_sou&m=' + t, { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ m: t, convidado_id: id }) })).json();
    return await (await fetch('api.php?action=bar_menu&m=' + t)).json();
  }, [token, cenario.pessoa.id]);
  ok(!!menu.pedido, 'a regra da casa contada em pedidos trava o ACTO de pedir');
  ok(!menu.ritmo,
     'e não se disfarça de caudal de bebidas — que era a leitura a mais');
  ok(/copa está a dar vazão/i.test((menu.pedido || {}).texto || ''),
     'com o tom certo: a copa está cheia, não foi a pessoa que pediu de mais — «'
     + ((menu.pedido || {}).texto || '') + '»');
  ok((menu.itens || []).every(i => i.travao !== 'casa'),
     'e as bebidas não ficam todas fechadas uma a uma pela mesma regra');
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const r of (e.regras || []).filter(r => /^ZT /.test(r.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
  });

  // ============ 5. a ficha é sobre bebida ============
  const ficha = await copa.evaluate(async (id) =>
    await window.api('bar_ficha&convidado=' + id), cenario.pessoa.id);
  ok(ficha.dispositivos === undefined,
     'a ficha de uma pessoa já não traz a lista de telemóveis dela');

  // ============ 6. os selects grandes têm procura ============
  // A lista de convidados de um casamento não cabe num <select>: rola-se à
  // procura de um nome e passa-se ao lado. A partir de uma dúzia, a escolha
  // abre-se com uma caixa de procura por dentro.
  await copa.locator('button:has-text("Regra nova")').click();
  await copa.waitForTimeout(1500);
  const comProcura = await copa.locator('#lic-janela .lic-sel').count();
  ok(comProcura >= 2,
     'a janela de uma regra tem escolhas com procura por dentro (' + comProcura + ')');
  // E a procura procura — sem acentos, que é como se escreve de pé.
  // Pelo NOME do campo e não pela posição: a ordem dos campos mudou na quarta
  // passagem (§29.4) e um índice fixo apanhou a caixa errada — a de «qual
  // pessoa», que está escondida enquanto a regra não for de ninguém.
  const sobre = copa.locator('#lic-janela .lic-sel[data-sel="sobre"]');
  await sobre.locator('.lic-sel-bt').click();
  await copa.waitForTimeout(300);
  const antes = await sobre.locator('.lic-sel-op:visible').count();
  await sobre.locator('.lic-sel-q input').fill('zt tinto');
  await copa.waitForTimeout(400);
  const depois = await sobre.locator('.lic-sel-op:visible').count();
  ok(antes > depois && depois === 1,
     'e escrever filtra a lista: ' + antes + ' → ' + depois);
  // O rótulo fica limpo: houve um dia em que saía «ZT Tinto NaNNaNNaN».
  const rotulo = await sobre.locator('.lic-sel-op:visible').first().innerText();
  ok(!/NaN/.test(rotulo), 'e o rótulo é o nome, e não uma conta falhada: «' + rotulo.trim() + '»');
  await sobre.locator('.lic-sel-op:visible').first().click();
  await copa.waitForTimeout(300);
  ok(/ZT Tinto/.test(await sobre.locator('.lic-sel-bt .txt').innerText()),
     'escolher fecha a lista e escreve o nome no botão');

  // ============ 7. a regra de pessoa é a mesma janela ============
  await copa.locator('.j-x, .lic-x, [aria-label*="Fechar"]').first().click().catch(() => {});
  await copa.keyboard.press('Escape');
  await copa.waitForTimeout(600);
  // A janela das regras aceita o «a quem» já preenchido: é o que a ficha usa.
  const daPessoa = await copa.evaluate(async (id) => {
    window.barRegraNova({ convidado_id: id, nome: 'ZT alguém' });
    await new Promise(r => setTimeout(r, 1400));
    const t = document.querySelector('#lic-janela h3, #lic-janela .lic-tit');
    // O campo chamava-se «quem» e passou a «alcance» na quarta passagem
    // (§29.6), quando a janela ganhou a pergunta da família por cima.
    const quem = document.getElementById('lf-alcance');
    const pessoa = document.getElementById('lf-pessoa');
    return { titulo: t ? t.textContent : '', quem: quem ? quem.value : '',
             pessoa: pessoa ? pessoa.value : '' };
  }, cenario.pessoa.id);
  ok(daPessoa.quem === 'pessoa',
     'a regra de uma pessoa abre a MESMA janela, com o «a quem» já respondido');
  ok(String(daPessoa.pessoa) === String(cenario.pessoa.id),
     'e com a pessoa certa escolhida');
  await copa.keyboard.press('Escape');
  await copa.waitForTimeout(400);

  // ============ 8. a página do convidado abre e fecha ============
  await conv.reload({ waitUntil: 'networkidle' });
  await conv.waitForTimeout(1300);
  const capa = await conv.locator('.b-festa-capa').innerText().catch(() => '');
  ok(/bar da festa/i.test(capa),
     'a página do convidado abre com o nome da casa, e não com um formulário');
  ok((await conv.locator('.b-festa-capa .filete').count()) === 1,
     'com o filete desenhado — sem imagem para servir, e sem um carácter que não exista');
  ok((await conv.locator('.b-festa-pe').count()) === 1,
     'e fecha a dizer o que acontece a seguir, em vez de acabar no ar');
  // A abertura NÃO é colante: seguir a pessoa página abaixo era roubar-lhe o ecrã.
  const colante = await conv.evaluate(() =>
    getComputedStyle(document.querySelector('.b-festa-capa')).position);
  ok(colante !== 'sticky', 'a abertura sai da frente ao rolar (position: ' + colante + ')');

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const r of (e.regras || []).filter(r => /^ZT /.test(r.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: r.id }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZT /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
    const d = await window.api('acesso_lista', { silencioso: true });
    for (const a of ((d && d.acessos) || [])) {
      if (a.email === 'zt.garcom@exemplo.pt') {
        await window.api('acesso_tirar', { method: 'POST', body: JSON.stringify({ id: a.id }) });
      }
    }
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
