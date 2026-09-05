// O cabeçalho é o mesmo em toda a casa, e conta os dias que faltam.
//
// Duas coisas se provam aqui. A primeira: quem é o casal e quando é o dia
// aparecem no mesmo sítio em todas as páginas. Andavam misturados na linha de
// apoio de algumas (o painel, as mesas) e ausentes das outras — em metade da
// casa não se sabia de quem era a festa que se estava a mexer, e a porta e os
// editores tinham barras suas, parecidas mas não iguais.
//
// A segunda: a contagem decrescente. É a pergunta que o casal faz todos os
// dias, e que até aqui só o convite respondia. Conta no browser, porque uma
// contagem feita no servidor fica velha no instante em que é servida.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

// As páginas da casa que têm cabeçalho — as do casal, a da porta e os dois
// editores. Cada uma diz onde está o seu título.
const PAGINAS = [
  ['index.php',          '.topo h1',       'Gestão de Convidados'],
  ['mesas.php',          '.topo h1',       'Planta de Mesas'],
  ['orcamento.php',      '.topo h1',       'Orçamento'],
  ['digital.php',        '.topo h1',       'Convite digital'],
  ['graficas.php',       '.topo h1',       'Convite impresso'],
  ['gestao.php',         '.topo h1',       'Gestão'],
  ['licenca.php',        '.topo h1',       null],
  ['porteiro.php',       '.topo h1',       'Entrada do evento'],
  ['convite-editor.php', '.ed-menu .doc',  null],
  ['editor-cartao.php',  '.ed-menu .doc',  null],
];

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Quem é o casal e quando é o dia, lidos do próprio cabeçalho: é ele que os
  // tem de dizer, e é contra ele que as outras páginas se comparam.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  const casal = await p.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    const n = document.querySelector('.topo-casal');
    if (!c || !n) return null;
    return { nome: n.textContent.split('·')[0].trim(), data: c.dataset.dia };
  });
  ok(casal && casal.data, 'o casamento de prova tem casal e data: '
     + (casal ? casal.nome + ' · ' + casal.data : '—'));

  // ============ 1. o mesmo cabeçalho em toda a parte ============
  const faltam = [];
  for (const [pagina, sel, titulo] of PAGINAS) {
    await p.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    await p.waitForTimeout(900);
    const d = await p.evaluate(([sel]) => {
      const t = document.querySelector(sel);
      const c = document.getElementById('topo-contagem');
      const id = document.querySelector('.topo-casal, .ed-menu .doc');
      return { titulo: t ? t.textContent.replace(/\s+/g, ' ').trim() : null,
               contagem: c ? c.textContent.replace(/\s+/g, ' ').trim() : null,
               dia: c ? c.dataset.dia : null,
               identidade: id ? id.textContent.replace(/\s+/g, ' ').trim() : null };
    }, [sel]);
    const bom = d.titulo && d.contagem && d.dia === casal.data
             && (d.identidade || '').includes(casal.nome)
             && (!titulo || d.titulo === titulo);
    if (!bom) faltam.push(pagina + ' → ' + JSON.stringify(d));
  }
  ok(faltam.length === 0,
     `as ${PAGINAS.length} páginas com cabeçalho dizem o casal e trazem a contagem`
       + (faltam.length ? ':\n     ' + faltam.join('\n     ') : ''));

  // O nome do casal deixa de estar duplicado na linha de apoio: era esse o
  // remendo que se usava no painel e nas mesas, e que faltava nas outras.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const linhas = await p.evaluate(() =>
    [...document.querySelectorAll('.topo .sub')].map(e => e.textContent.trim()));
  ok(linhas.filter(l => l.includes('&')).length <= 2,
     'o nome do casal não se repete na linha de apoio: ' + JSON.stringify(linhas.slice(0, 3)));

  // ============ 2. a contagem conta mesmo ============
  const cg = () => p.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    return { n: c.querySelector('.cg-n').textContent.trim(),
             l: c.querySelector('.cg-l').textContent.trim(), cls: c.className };
  });
  const dias = await cg();
  ok(/^\d+ dias?$/.test(dias.n) && /para o grande dia/i.test(dias.l),
     'a contagem dá os dias que faltam: ' + dias.n + ' ' + dias.l);

  // Quantos são: a conta tem de bater com a data, e não ser um número qualquer.
  const conferida = await p.evaluate((data) => {
    const p = data.split('-');
    const alvo = new Date(+p[0], +p[1] - 1, +p[2]);
    const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    return Math.round((alvo - hoje) / 86400000);
  }, casal.data);
  ok(dias.n === conferida + (conferida === 1 ? ' dia' : ' dias'),
     'e são os dias certos até lá (' + conferida + ')');

  // ============ 3. o próprio dia, e o dia seguinte ============
  // Muda-se a data no atributo e volta-se a correr a contagem — é a forma de
  // ver o que só se veria uma vez, no dia do casamento de alguém.
  const comData = (quando) => p.evaluate((q) => {
    const cx = document.getElementById('topo-contagem');
    cx.dataset.dia = q; cx.className = 'contagem';
    // O guião do cabeçalho já correu; corre-se outra vez, agora com a data nova.
    const s = [...document.scripts].find(x => /topo-contagem/.test(x.textContent));
    (0, eval)(s.textContent);
    return { n: cx.querySelector('.cg-n').textContent.trim(),
             l: cx.querySelector('.cg-l').textContent.trim(), cls: cx.className };
  }, quando);
  const iso = (d) => new Date(Date.now() + d * 86400000).toISOString().slice(0, 10);

  const hoje = await comData(iso(0));
  ok(hoje.n === 'É HOJE' && /hoje/.test(hoje.cls),
     'no próprio dia deixa de ser um número: ' + hoje.n + ' · ' + hoje.l);

  const ontem = await comData(iso(-2));
  ok(/desde o grande dia/.test(ontem.l) && /passou/.test(ontem.cls),
     'e depois conta para a frente: ' + ontem.n + ' ' + ontem.l);

  const amanha = await comData(iso(1));
  ok(amanha.n === '1 dia', 'a véspera diz «1 dia», no singular: ' + amanha.n);

  // ============ 4. sem casamento aberto, não há contagem ============
  // Quem responde pela casa entra sem casamento nenhum, de propósito. Uma
  // contagem ali seria a contagem de quem?
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_fechar',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  const semCasal = await p.evaluate(() => ({
    contagem: !!document.getElementById('topo-contagem'),
    casal: !!document.querySelector('.topo-casal') }));
  ok(!semCasal.contagem && !semCasal.casal,
     'sem casamento aberto, o cabeçalho não conta os dias de ninguém');

  // ============ 5. no telemóvel ============
  const tel = await (await b.newContext({ viewport: { width: 390, height: 780 } })).newPage();
  await tel.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await tel.fill('input[name=utilizador]', 'admin'); await tel.fill('input[name=senha]', 'noivos2026');
  await tel.click('button[type=submit]'); await tel.waitForLoadState('networkidle');
  await tel.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await tel.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await tel.waitForTimeout(700);
  const mob = await tel.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    const t = document.querySelector('.topo h1');
    const rc = c.getBoundingClientRect(), rt = t.getBoundingClientRect();
    return { largura: Math.round(rc.width), janela: innerWidth,
             abaixo: rc.top > rt.bottom, texto: c.textContent.replace(/\s+/g, ' ').trim() };
  });
  ok(mob.abaixo && mob.largura > mob.janela * 0.8,
     'no telemóvel a contagem passa para baixo do título, a toda a largura ('
       + mob.largura + 'px em ' + mob.janela + ')');
  await tel.screenshot({ path: OUT + '/cabecalho-telemovel.png', clip: { x: 0, y: 0, width: 390, height: 260 } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
