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
//
// E conta ao segundo, no lugar onde estava a data. A data lê-se uma vez e
// nunca mais muda — quem trabalha aqui já a sabe de cor; fica no title, para
// quem a for procurar. O que se quer ao abrir a página é quanto falta.
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
    const l = await (await fetch('api.php?action=casamento_lista&estado=ativo',
      { headers: { 'X-CSRF-Token': window.CSRF } })).json();
    const c = (l.casamentos || [])[0];
    await fetch('api.php?action=casamento_abrir&id=' + c.id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // A data do casamento continua no title da contagem, embora o cabeçalho já
  // não repita o nome dos noivos nem a data por extenso.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  const casal = await p.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    if (!c) return null;
    return { data: c.dataset.dia };
  });
  ok(casal && casal.data, 'o casamento de prova tem data: ' + (casal ? casal.data : '—'));

  // ============ 1. o mesmo cabeçalho em toda a parte ============
  const faltam = [];
  for (const [pagina, sel, titulo] of PAGINAS) {
    await p.goto(BASE + '/' + pagina, { waitUntil: 'networkidle' });
    await p.waitForTimeout(900);
    const d = await p.evaluate(([sel]) => {
      const t = document.querySelector(sel);
      const c = document.getElementById('topo-contagem');
      const desc = document.querySelector('.pagina-descricao p');
      return { titulo: t ? t.textContent.replace(/\s+/g, ' ').trim() : null,
               contagem: c ? c.textContent.replace(/\s+/g, ' ').trim() : null,
               dia: c ? c.dataset.dia : null,
               descricao: desc ? desc.textContent.replace(/\s+/g, ' ').trim() : null };
    }, [sel]);
    const bom = d.titulo && d.contagem && d.dia === casal.data
             && (!titulo || d.titulo === titulo);
    if (!bom) faltam.push(pagina + ' → ' + JSON.stringify(d));
  }
  ok(faltam.length === 0,
     `as ${PAGINAS.length} páginas com cabeçalho trazem a contagem`
       + (faltam.length ? ':\n     ' + faltam.join('\n     ') : ''));

  // A descrição está no corpo; nome dos noivos e licença saíram do cabeçalho.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const cab = await p.evaluate(() => ({
    nome: !!document.querySelector('.topo .tc-nome, .topo .topo-casal'),
    licenca: !!document.querySelector('.topo .licenca-restante'),
    descricao: document.querySelector('.pagina-descricao p')?.textContent.trim() || '',
    descNoTopo: !!document.querySelector('.topo .pagina-descricao') }));
  ok(!cab.nome && !cab.licenca, 'o cabeçalho não repete os noivos nem a licença');
  ok(cab.descricao && !cab.descNoTopo, 'a descrição da página começa o corpo: ' + cab.descricao);

  // ============ 2. a contagem conta mesmo — em dias ============
  //
  // Esta prova exigia aqui o cronómetro ao segundo, e passou a falhar quando a
  // contagem virou marco (docs/auditoria-ui-ux.md, EMO-001). Não é uma
  // regressão: é a regra nova. A trezentos dias de distância um cronómetro não
  // é uma contagem, é um relógio de bomba no canto do cabeçalho — e a segunda
  // que passou não muda decisão nenhuma. Na semana da festa volta a aparecer,
  // e é a chk_tom.js que guarda esse outro lado, com a data mexida de propósito.
  //
  // O que se defende AQUI é o que vale todo o ano: os dias estão certos, e o
  // dia e a hora inteiros continuam à mão de quem passe o rato por cima.
  const cg = () => p.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    return { n: c.querySelector('.cg-n').textContent.trim(),
             l: c.querySelector('.cg-l').textContent.trim(),
             t: c.querySelector('.cg-t').textContent.trim(),
             titulo: c.getAttribute('title') || '', cls: c.className };
  });
  const dias = await cg();
  ok(/^\d+ Dias?$/.test(dias.n) && dias.l === 'Até ao “Sim, Aceito”',
     'a contagem diz «N Dias Até ao “Sim, Aceito”»: ' + dias.n + ' ' + dias.l);
  const longe = parseInt(dias.n, 10) >= 7;
  ok(!longe || dias.t === '',
     'e longe da festa não traz cronómetro: a esta distância planeia-se em '
     + 'semanas, e um relógio a andar por cima do trabalho não ajuda nisso');

  // E o cabeçalho fica MESMO quieto: era uma escrita por segundo, todo o ano,
  // para mostrar um número que só muda à meia-noite. Conta-se o que ele
  // escreve, e não o que lá está — é a diferença entre parecer parado e estar.
  const escritas = await p.evaluate(() => new Promise(res => {
    let n = 0;
    const o = new MutationObserver(ms => { n += ms.length; });
    o.observe(document.getElementById('topo-contagem'),
              { subtree: true, characterData: true, childList: true });
    setTimeout(() => { o.disconnect(); res(n); }, 4200);
  }));
  ok(!longe || escritas === 0,
     'e não escreve nada durante quatro segundos: ' + escritas + ' escritas');

  // A contagem está no lugar da data — e a data continua à mão, no title.
  const linha = await p.evaluate(() =>
    document.querySelector('.topo-contagem-linha').textContent.replace(/\s+/g, ' ').trim());
  ok(!/de janeiro|de fevereiro|de março|de abril|de maio|de junho|de julho|de agosto|de setembro|de outubro|de novembro|de dezembro/i
       .test(linha),
     'a data por extenso saiu da linha, que agora é do casal e da contagem: ' + linha);
  ok(/de \w+ de \d{4}/i.test(dias.titulo),
     'e lê-se ao passar o rato pela contagem: «' + dias.titulo + '»');

  // Quantos são: a conta tem de bater com a data, e não ser um número qualquer.
  //
  // A contagem é uma DURAÇÃO, não um número de folhas de calendário: os dias
  // que mostra são os que sobram depois de descontado o hh:mm:ss que está ao
  // lado. Contar de meia-noite a meia-noite dava um a mais sempre que a hora
  // do dia já passasse a hora do casamento — e o par «104 dias 22:26:22»
  // estaria a prometer um dia que não existe.
  const conferida = await p.evaluate(() => {
    const cx = document.getElementById('topo-contagem');
    const d = (cx.dataset.dia || '').split('-'), h = (cx.dataset.hora || '00:00').split(':');
    const alvo = new Date(+d[0], +d[1] - 1, +d[2], +h[0] || 0, +h[1] || 0, 0, 0);
    return Math.floor((alvo - new Date()) / 86400000);
  });
  ok(dias.n === conferida + (conferida === 1 ? ' Dia' : ' Dias'),
     'e são os dias certos até lá (' + conferida + ')');

  // ============ 3. o próprio dia, e o dia seguinte ============
  // Muda-se a data no atributo e volta-se a correr a contagem — é a forma de
  // ver o que só se veria uma vez, no dia do casamento de alguém.
  const comData = (quando, hora) => p.evaluate(([q, h]) => {
    const cx = document.getElementById('topo-contagem');
    cx.dataset.dia = q; cx.className = 'contagem contagem-sim';
    if (h) cx.dataset.hora = h;
    // O guião do cabeçalho já correu; corre-se outra vez, agora com a data nova.
    const s = [...document.scripts].find(x => /\.contagem\[data-dia\]/.test(x.textContent));
    (0, eval)(s.textContent);
    return { n: cx.querySelector('.cg-n').textContent.trim(),
             l: cx.querySelector('.cg-l').textContent.trim(),
             t: cx.querySelector('.cg-t').textContent.trim(), cls: cx.className };
  }, [quando, hora]);
  // Data LOCAL: toISOString() passa por UTC, e à noite num fuso a leste isso
  // devolve o dia seguinte — «hoje» deixava de ser hoje.
  const isoDe = (x) =>
    x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0')
    + '-' + String(x.getDate()).padStart(2, '0');
  const iso = (d) => isoDe(new Date(Date.now() + d * 86400000));
  const hhmm = (x) => String(x.getHours()).padStart(2, '0') + ':'
                    + String(x.getMinutes()).padStart(2, '0');

  const hoje = await comData(iso(0));
  ok(hoje.n === 'Hoje' && hoje.l === 'é o “Sim, Aceito”' && /hoje/.test(hoje.cls),
     'no próprio dia deixa de ser um número: ' + hoje.n + ' · ' + hoje.t);

  const ontem = await comData(iso(-2));
  ok(ontem.l === 'Desde o “Sim, Aceito”' && /passou/.test(ontem.cls) && ontem.t === '',
     'e depois conta para a frente, sem relógio: ' + ontem.l + ' ' + ontem.n);

  // A véspera, com o casamento a vinte e tal horas de distância: é aí que falta
  // mesmo um dia. A menos de 24 horas o que se quer ver é o relógio, não um
  // «1 dia» que estaria a arredondar para cima.
  //
  // O dia sai do próprio instante, e não de um iso(1) fixo: às onze da noite,
  // daqui a 25 horas já é depois de amanhã, e casar essa hora com a data de
  // amanhã dava um alvo a meia hora de distância — a página fazia bem em
  // mostrar só o relógio, e era a prova que estava errada. Cortadas as horas
  // à hora certa, o alvo fica sempre entre as 24 e as 25 horas.
  const daquiA25h = new Date(Date.now() + 25 * 3600000);
  const amanha = await comData(isoDe(daquiA25h),
                               String(daquiA25h.getHours()).padStart(2, '0') + ':00');
  ok(amanha.n === '1 Dia' && amanha.l === 'Até ao “Sim, Aceito”',
     'a véspera diz «1 Dia Até ao “Sim, Aceito”»: ' + amanha.n + ' ' + amanha.l);

  // E dentro das últimas 24 horas o número desaparece: fica só o relógio, que
  // é a verdade — «1 dia 16:56:12» seria um dia a mais.
  //
  // O alvo tem de estar a menos de 24 horas E já noutro dia do calendário.
  // Vinte e três horas a partir de agora servem sempre, excepto na primeira
  // hora da madrugada, em que ainda caem hoje — e aí é o caso «É HOJE», que
  // já está provado acima.
  const daqui23h = new Date(Date.now() + 23 * 3600000);
  if (daqui23h.getDate() !== new Date().getDate()) {
    const ultimas = await comData(isoDe(daqui23h), hhmm(daqui23h));
    ok(ultimas.n === '0 Dias' && ultimas.l === 'Até ao “Sim, Aceito”'
       && /^\d\d:\d\d:\d\d$/.test(ultimas.t),
       'e nas últimas horas diz «0 Dias» com o relógio: ' + ultimas.t);
  } else {
    console.log('(saltado: à uma da manhã as 23 horas seguintes ainda são hoje)');
  }

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
    casal: !!document.querySelector('.topo-contagem-linha') }));
  ok(!semCasal.contagem && !semCasal.casal,
     'sem casamento aberto, o cabeçalho não conta os dias de ninguém');

  // ============ 5. no telemóvel ============
  const tel = await (await b.newContext({ viewport: { width: 390, height: 780 } })).newPage();
  await tel.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await tel.fill('input[name=utilizador]', 'admin'); await tel.fill('input[name=senha]', 'noivos2026');
  await tel.click('button[type=submit]'); await tel.waitForLoadState('networkidle');
  await tel.evaluate(async () => {
    const l = await (await fetch('api.php?action=casamento_lista&estado=ativo',
      { headers: { 'X-CSRF-Token': window.CSRF } })).json();
    const c = (l.casamentos || [])[0];
    await fetch('api.php?action=casamento_abrir&id=' + c.id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await tel.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await tel.waitForTimeout(700);
  const mob = await tel.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    const t = document.querySelector('.topo h1');
    const rc = c.getBoundingClientRect(), rt = t.getBoundingClientRect();
    return { direita: Math.round(rc.right), janela: innerWidth,
             naLinha: !!c.closest('.topo-contagem-linha'),
             abaixo: rc.top > rt.bottom, texto: c.textContent.replace(/\s+/g, ' ').trim() };
  });
  ok(mob.naLinha && mob.abaixo && mob.direita <= mob.janela,
     'no telemóvel a contagem segue na linha do casal, sem transbordar ('
       + mob.direita + 'px em ' + mob.janela + '): ' + mob.texto);
  await tel.screenshot({ path: OUT + '/cabecalho-telemovel.png', clip: { x: 0, y: 0, width: 390, height: 260 } });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
