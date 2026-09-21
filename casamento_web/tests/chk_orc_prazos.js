// O orçamento responde às perguntas de quem paga a prestações.
//
// Eram quatro coisas, e todas da mesma família — o dinheiro que ainda não saiu:
//
//   1. A MARGEM não estava no cimo da página. Vivia numa legenda por baixo da
//      barra, em letra pequena, ao lado de «Pago» e «Por pagar». É a pergunta
//      com que se abre um orçamento — «ainda posso?» — e era a única que não
//      tinha cartão. O quarto cartão passa a mostrá-la; quando houver uma
//      parcela vencida, cede o lugar ao atraso, que é mais urgente do que uma
//      folga. E sai da legenda, porque o mesmo número dito duas vezes no mesmo
//      ecrã lê-se como dois números diferentes.
//
//   2. UMA PARCELA POR PAGAR lia-se igual a uma já paga: uma data e um valor,
//      na mesma cor. E a data sozinha era ambígua — podia ser a do pagamento,
//      que é o que ela significa nas que já saíram. Passa a dizer «data
//      limite», e a vermelho.
//
//   3. NÃO HAVIA AVISO DE PRAZO nenhum. Quem não abrisse a página não sabia
//      que o fotógrafo vencia na quinta-feira.
//
//   4. AS PARCELAS DO MESMO PRODUTO andavam soltas por três meses diferentes,
//      cada uma sem memória das outras. Quem paga a fotografia em três vezes
//      quer vê-las juntas: «1 de 3 pagas, falta 600 000».
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzo' + Math.floor(Math.random() * 1e6);
const dia = n => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');

  // Um casamento só desta prova: os números do orçamento têm de ser os DELE.
  const novo = await p.evaluate(async n => {
    const r = await fetch('api.php?action=casamento_criar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ nome: n, licenca: 12 }) });
    return r.json();
  }, 'ZZ Orc ' + marca);
  ok(novo.success, 'criou o casamento de prova');
  const cid = novo.id;
  await p.evaluate(async i => { await fetch('api.php?action=casamento_abrir&id=' + i,
    { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }); }, cid);

  const semear = (comAtraso) => p.evaluate(async ({ d, atraso }) => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    await post('orc_ajuste', { total: 5000000, moeda: 'Kz' });
    const fazer = async (nome, valor, parcelas) => {
      const de = await post('orc_despesa_guardar', { descricao: nome, valor, estado: 'previsto' });
      const id = de.id || (de.despesa && de.despesa.id);
      for (const q of parcelas) {
        await post('orc_pagamento_guardar',
          { despesa_id: id, valor: q.v, data_prevista: q.d, pago_em: q.pago || null });
      }
    };
    // A fotografia em três vezes: uma paga, uma a caminho (ou vencida), uma longe.
    await fazer('Fotografia', 900000, [
      { v: 300000, d: d.m40, pago: d.m40 },
      { v: 300000, d: atraso ? d.m3 : d.p7 },
      { v: 300000, d: d.p30 }]);
    await fazer('Catering', 2200000, [
      { v: 1100000, d: d.m60, pago: d.m58 },
      { v: 1100000, d: d.p7 }]);
    await fazer('Música', 400000, [{ v: 400000, d: d.p60 }]);
  }, { atraso: comAtraso,
       d: { m60: dia(-60), m58: dia(-58), m40: dia(-40), m3: dia(-3),
            p7: dia(7), p30: dia(30), p60: dia(60) } });

  const ler = async () => {
    await p.goto(BASE + '/orcamento.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(2200);
    return p.evaluate(() => ({
      kpis: [...document.querySelectorAll('.kpi')].map(k => ({
        l: (k.querySelector('.l') || {}).textContent,
        n: (k.querySelector('.n') || {}).textContent,
        morto: k.hasAttribute('disabled') })),
      legenda: (document.getElementById('o-legenda') || {}).textContent || '',
      prazos: (document.querySelector('.o-prazos') || {}).textContent || '',
      prazosMau: !!document.querySelector('.o-prazos.mau'),
      grupos: [...document.querySelectorAll('.o-grupo')].map(g =>
        g.textContent.replace(/\s+/g, ' ').trim()),
      datas: [...document.querySelectorAll('.pag .data')].map(d => ({
        txt: d.textContent.replace(/\s+/g, ' ').trim(),
        cls: d.className.replace('data', '').trim() })),
      numeros: [...document.querySelectorAll('.pag .o-np')].map(x => x.textContent),
      modo: [...document.querySelectorAll('.o-modo-bt')].map(x =>
        x.textContent + (x.classList.contains('on') ? '*' : '')),
    }));
  };

  // ================= 1. sem nada vencido: o cartão é a MARGEM =================
  await semear(false);
  let v = await ler();
  const margem = v.kpis.find(k => /margem/i.test(k.l || ''));
  ok(!!margem, 'o quarto cartão mostra a margem quando não há nada vencido');
  // 5 000 000 de teto, 1 400 000 pagos, 2 100 000 por pagar → 1 500 000 de folga.
  ok(margem && /1\s*500\s*000/.test(margem.n),
     'e é o que sobra do teto depois de tudo o que já está prometido: ' + (margem ? margem.n : '?'));
  ok(margem && margem.morto,
     'não é um filtro, e não finge que é: uma diferença entre dois números não '
     + 'tem lista por trás');
  ok(!/Margem/i.test(v.legenda),
     'e sai da legenda da barra — o mesmo número dito duas vezes no mesmo ecrã '
     + 'lê-se como dois: «' + v.legenda.replace(/\s+/g, ' ').trim() + '»');

  // ================= 2. o aviso de prazos =================
  ok(/pr[oó]ximos 14 dias/i.test(v.prazos),
     'avisa o que vence nos próximos dias, sem ser preciso ir procurar: «'
     + v.prazos.replace(/\s+/g, ' ').trim() + '»');
  ok(/Fotografia|Catering/.test(v.prazos),
     'e diz DE QUÊ — um aviso que não nomeia obriga a procurar na lista');
  ok(!v.prazosMau, 'e enquanto nada estiver vencido, avisa sem alarmar');

  // ================= 3. as parcelas por pagar =================
  const porPagar = v.datas.filter(d => /data limite/.test(d.txt));
  const pagas = v.datas.filter(d => /^pago /.test(d.txt));
  ok(porPagar.length === 4 && pagas.length === 2,
     'quatro parcelas por pagar e duas pagas: ' + porPagar.length + ' e ' + pagas.length);
  ok(porPagar.every(d => /data limite \d{4}-\d{2}-\d{2}/.test(d.txt)),
     'cada uma por pagar diz «data limite» e não uma data sozinha — sozinha podia '
     + 'ser a do pagamento, que é o que significa nas outras');
  ok(porPagar.every(d => /porpagar|venceu|perto/.test(d.cls)),
     'e nenhuma se lê igual a uma já paga: ' + porPagar.map(d => d.cls).join(', '));
  ok(porPagar.some(d => /daqui a|hoje|amanhã/.test(d.txt)),
     'com o prazo por extenso ao lado da data: «' + (porPagar[0] || {}).txt + '»');

  // ================= 4. as parcelas do mesmo produto, juntas =================
  ok(v.modo.join(' ').includes('Por produto*'),
     'a lista abre agrupada por produto: ' + v.modo.join(' · '));
  const foto = v.grupos.find(g => /Fotografia/.test(g));
  ok(!!foto, 'há um bloco por produto');
  ok(foto && /1 de 3 pagas/.test(foto),
     'que diz quantas já saíram das que há: «' + foto + '»');
  ok(foto && /falta/.test(foto),
     'e quanto falta — que é o que se vem perguntar a um pagamento faseado');
  ok(v.numeros.includes('1/3') && v.numeros.includes('3/3'),
     'e cada linha sabe o lugar que ocupa na série: ' + v.numeros.join(' '));

  // E o calendário continua a um toque, para quem quer a ordem do tempo.
  await p.evaluate(() => orcAgrupar('mes'));
  await p.waitForTimeout(700);
  const porMes = await p.evaluate(() => ({
    meses: document.querySelectorAll('.o-mes').length,
    grupos: document.querySelectorAll('.o-grupo').length }));
  ok(porMes.meses > 1 && porMes.grupos === 0,
     'e «Por mês» devolve o calendário: ' + porMes.meses + ' meses, sem blocos de produto');
  await p.evaluate(() => orcAgrupar('produto'));

  // ================= 5. com uma parcela vencida, o atraso manda =================
  const venceu = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=orc_estado')).json();
    const ps = d.pagamentos || [];
    // A do meio da fotografia passa para trás: fica vencida.
    const alvo = ps.filter(x => !x.pago_em && /Fotografia/.test(x.despesa))[0];
    if (!alvo) return { erro: 'não achei parcela por pagar', quantas: ps.length };
    const ontem = new Date(); ontem.setDate(ontem.getDate() - 3);
    const r = await fetch('api.php?action=orc_pagamento_guardar', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      // O guardar exige a despesa a que a parcela pertence: é por aí que
      // confere que ela é deste casamento.
      body: JSON.stringify({ id: alvo.id, despesa_id: alvo.despesa_id, valor: alvo.valor,
                             data_prevista: ontem.toISOString().slice(0, 10) }) });
    return r.json();
  });
  ok(venceu && venceu.success !== false,
     'atrasou-se uma parcela da fotografia: ' + JSON.stringify(venceu).slice(0, 90));
  v = await ler();
  const atraso = v.kpis.find(k => /atraso/i.test(k.l || ''));
  ok(!!atraso, 'assim que algo vence, o cartão passa a ser o do ATRASO — um atraso '
     + 'é mais urgente do que uma folga, e trocá-lo por um número bonito apagava '
     + 'a única coisa que obriga a agir hoje');
  ok(!v.kpis.some(k => /margem/i.test(k.l || '')),
     'e a margem cede-lhe o lugar, em vez de haver cinco cartões');
  ok(atraso && !atraso.morto, 'o do atraso volta a filtrar: há uma lista por trás dele');
  ok(v.prazosMau, 'e o aviso de prazos passa a alarmar');
  ok(v.datas.some(d => /venceu/.test(d.cls)),
     'a parcela vencida distingue-se das outras por pagar');
  const fotoMau = v.grupos.find(g => /Fotografia/.test(g));
  ok(!!fotoMau, 'e o bloco do produto continua a dizer as contas dele: «' + fotoMau + '»');

  // ---- arrumar ----
  await p.evaluate(async i => {
    const g = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).then(r => r.json()).catch(() => null);
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json()).catch(() => null);
    await post('lic_revogar', { casamento: i, motivo: 'Fim da prova automática' });
    await g('casamento_estado&id=' + i + '&estado=arquivado');
    await g('casamento_apagar&id=' + i);
  }, cid);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
