// O tom: a contagem como marco, e o momento em que a lista fecha
// (docs/auditoria-ui-ux.md §25, EMO-001 e EMO-002).
//
// EMO-001 — a trezentos dias de distância, um cronómetro ao segundo não é uma
// contagem: é um relógio de bomba no canto do cabeçalho, a mexer-se o dia
// inteiro por cima do trabalho. E a segunda que passou não muda decisão
// nenhuma — a esta distância planeia-se em semanas. Na semana da festa muda
// tudo: aí os segundos são a festa a chegar. É o mesmo número com dois
// significados, e o que faz a diferença é a distância.
//
// EMO-002 — meses de trabalho (escrever a lista, mandar os convites, lembrar
// quem não respondeu) acabavam com um contador de pendentes a passar de 1 para
// 0. Sem nada. Este sítio é uma ferramenta de trabalho e faz bem em sê-lo, mas
// há um punhado de momentos num casamento que merecem ser ditos, e este é o
// maior deles: a lista está fechada.
//
// A prova faz o seu casamento — mexer na data e no estado do de exemplo mudava
// o que as outras provas lá encontram — e leva-o embora no fim.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
                 + '-' + String(d.getDate()).padStart(2, '0');

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'tm' + String(Date.now()).slice(-6);

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  const longe = new Date(Date.now() + 300 * 86400000);
  let d = await api('casamento_criar', { nome: 'Tom ' + marca, data: iso(longe),
                                         noivos_email: 'casal.' + marca + '@exemplo.ao',
                                         noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  await api('casamento_abrir&id=' + cid, {});

  // ============ EMO-001: a contagem ============
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  const ler = () => p.evaluate(() => {
    const c = document.getElementById('topo-contagem');
    if (!c) return null;
    return { texto: c.textContent.replace(/\s+/g, ' ').trim(),
             relogio: (c.querySelector('.cg-t') || {}).textContent || '',
             dias: (c.querySelector('.cg-n') || {}).textContent || '' };
  });
  const far = await ler();
  ok(!!far, 'o cabeçalho traz a contagem');
  ok(/\d+ dias/i.test(far.dias),
     'a trezentos dias conta em DIAS: «' + far.texto + '»');
  ok(far.relogio === '',
     'e sem cronómetro ao segundo — a esta distância planeia-se em semanas, '
     + 'e um relógio de bomba no cabeçalho não ajuda a planear nada');

  // E a página deixa mesmo de escrever: era uma escrita por segundo, todo o
  // ano, para mostrar um número que só muda à meia-noite.
  const escritas = await p.evaluate(() => new Promise(res => {
    let n = 0;
    const o = new MutationObserver(ms => { n += ms.length; });
    o.observe(document.getElementById('topo-contagem'),
              { subtree: true, characterData: true, childList: true });
    setTimeout(() => { o.disconnect(); res(n); }, 4200);
  }));
  ok(escritas === 0,
     'e o cabeçalho fica quieto: ' + escritas + ' escritas em 4 segundos (eram 4)');

  // ---- a semana da festa ----
  const perto = new Date(Date.now() + 3 * 86400000);
  d = await api('defs_save', { defs: { 'evento.data': iso(perto) } });
  ok(d && d.success, 'mudou a data para daqui a três dias');
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  const semana = await ler();
  ok(/\d+ dias?/i.test(semana.dias), 'na semana da festa continua a dizer os dias');
  ok(/^\d\d:\d\d:\d\d$/.test(semana.relogio),
     'e AGORA aparece o relógio — aqui os segundos são a festa a chegar: '
     + semana.relogio);
  const escritasPerto = await p.evaluate(() => new Promise(res => {
    let n = 0;
    const o = new MutationObserver(ms => { n += ms.length; });
    o.observe(document.getElementById('topo-contagem'),
              { subtree: true, characterData: true, childList: true });
    setTimeout(() => { o.disconnect(); res(n); }, 3200);
  }));
  ok(escritasPerto >= 2,
     'e volta a andar ao segundo, que é o que aqui se quer: ' + escritasPerto + ' escritas em 3s');

  // ============ EMO-002: a tira do dia ============
  //
  // Isto guardava «quando o último convite responde, a lista diz que está
  // fechada» — e a tira aparecia nesse instante, fosse Março ou Dezembro. Para
  // um casamento a 300 dias, era uma tira no cimo do painel durante dez meses
  // a dar a notícia do dia em que foi dada. A tira passou a ser do DIA DA
  // FESTA; o que ela defende continua igual, e há mais uma coisa a defender:
  // fora da hora, não aparece de todo.
  const cv = [];
  for (const nome of ['ZZ Um ' + marca, 'ZZ Dois ' + marca]) {
    const r = await api('convite_save', { nome_exibicao: nome, tipo: 'digital',
      lado: 'noivo', membros: [{ nome: nome + ' pessoa' }] });
    cv.push(r.convite);
  }
  ok(cv.length === 2 && cv.every(c => c && c.codigo), 'criou dois convites de prova');

  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  ok(await p.evaluate(() => document.getElementById('chegada').hidden),
     'com convites por responder, não se anuncia nada — seria uma mentira simpática');

  // Respondem os dois: a lista fecha-se. E mesmo assim a tira NÃO aparece,
  // porque o casamento é daqui a 300 dias.
  for (const c of cv) {
    await p.evaluate(async (x) => {
      await fetch('api.php?action=rsvp_submit', { method: 'POST', body: JSON.stringify({
        codigo: x.codigo, decisao: 'sim', confirmados: 1,
        membros: (x.membros || []).map(m => ({ id: m.id, vai: true })) }) });
    }, c);
  }
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1900);
  ok(await p.evaluate(() => document.getElementById('chegada').hidden),
     'com a lista toda fechada e a festa a 300 dias, continua calada — era aqui '
     + 'que ela ficava dez meses a dizer a mesma coisa');

  // Agora é hoje, e a hora já passou: é a festa.
  const hoje = iso(new Date());
  await api('defs_save', { defs: { 'evento.data': hoje, 'evento.hora': '00:01' } });
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1900);
  const chegou = await p.evaluate(() => {
    const e = document.getElementById('chegada');
    return { escondido: e.hidden, festa: e.classList.contains('festa'),
             texto: e.textContent.replace(/\s+/g, ' ').trim(),
             desenho: !!e.querySelector('svg'),
             vivo: (document.getElementById('avisos-vivos') || {}).textContent.trim() };
  });
  ok(!chegou.escondido, 'chegado o dia, a tira aparece');
  ok(/pessoas?|chegaram|hoje/i.test(chegou.texto),
     'e diz o que se passa em gente, e não em convites: «' + chegou.texto.slice(0, 80) + '»');
  ok(chegou.desenho, 'com um sinal desenhado, e não um emoji');
  ok(chegou.festa, 'a primeira vez, entra com festa');
  ok(chegou.vivo.length > 0 && /pessoas?|chegaram|hoje/i.test(chegou.vivo),
     'e quem não vê a animação ouve a notícia — é a notícia que importa: «'
     + chegou.vivo.slice(0, 60) + '»');

  // A festa é uma vez: repetida a cada visita deixava de ser um momento.
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const segunda = await p.evaluate(() => {
    const e = document.getElementById('chegada');
    return { escondido: e.hidden, festa: e.classList.contains('festa') };
  });
  ok(!segunda.escondido,
     'a tira fica enquanto for verdade: é o estado, e um estado que desaparece '
     + 'obriga a ir confirmá-lo a outro lado');
  ok(!segunda.festa, 'mas a festa não se repete — repetida, era um enfeite');

  // ---- arrumar ----
  await api('lic_revogar', { casamento: cid, motivo: 'Casamento de prova automática.' });
  await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  const limpou = await api('casamento_apagar&id=' + cid, {});
  ok(limpou && limpou.success, 'e o casamento de prova sai daqui como entrou');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
