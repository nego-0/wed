// O ícone de uma mesa tem de dizer o que a mesa é.
//
// Era um rectângulo de cor por forma: para saber se aquele era o «comprido» ou
// o «retangular», comparavam-se dois quadrados quase iguais. E em lado nenhum
// se via quantos lugares a mesa tinha, ou quantos já estavam ocupados — a
// informação existia na linha ao lado, em texto, e o desenho não a usava.
//
// Passa a ser o desenho por que as plantas de sala se dizem em toda a parte: a
// mesa vista de cima, uma cadeira por lugar, e o número lá dentro. Prova-se
// pelo que o desenho CONTÉM — cadeiras a contar, número certo, estado cheio —
// e não pelo aspecto.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, user, pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', user); await p.fill('input[name=senha]', pass);
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await entrar(await b.newContext(), 'admin', 'noivos2026');
  p.on('pageerror', e => errs.push(e.message));
  const api = p._api;
  await api('casamento_abrir&id=1');
  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);

  // Lê o ícone como um humano o lê: quantas cadeiras, que número, que estado.
  const ler = (m) => p.evaluate((mm) => {
    const d = document.createElement('div');
    d.innerHTML = mesaIcone(mm, { tam: 60 });
    const svg = d.firstChild;
    return { cadeiras: svg.querySelectorAll('.mi-c').length,
             numero: (svg.querySelector('.mi-n') || {}).textContent || '',
             classes: svg.getAttribute('class'),
             titulo: (svg.querySelector('title') || {}).textContent || '' };
  }, m);

  ok(await p.evaluate(() => typeof mesaIcone === 'function'),
     'a página das mesas traz o desenhador de ícones');

  // ---------- uma cadeira por lugar ----------
  let i = await ler({ forma:'redonda', capacidade:10, ocupacao:7, nome:'Mesa 1' });
  ok(i.cadeiras === 10, `uma mesa de 10 lugares desenha 10 cadeiras (${i.cadeiras})`);
  ok(i.numero === '7/10', `e o número é a lotação: ocupados de capacidade (${i.numero})`);
  ok(/Mesa 1/.test(i.titulo) && /7 de 10/.test(i.titulo),
     'o título diz o mesmo por palavras, para quem lê com leitor de ecrã');

  i = await ler({ forma:'comprida', capacidade:14, ocupacao:0 });
  ok(i.cadeiras === 14, `uma comprida de 14 desenha 14 cadeiras (${i.cadeiras})`);
  ok(i.numero === '14', 'sem ninguém sentado, o número é a capacidade — e não «0/14»');
  ok(/vazia/.test(i.classes), 'e a mesa marca-se como vazia, de traço leve');

  // ---------- cheia ----------
  i = await ler({ forma:'quadrada', capacidade:8, ocupacao:8 });
  ok(i.numero === '8/8', 'uma mesa cheia diz 8/8');
  ok(/cheia/.test(i.classes), 'e marca-se como cheia — é o que faz mudar de plano');
  i = await ler({ forma:'redonda', capacidade:8, ocupacao:9 });
  ok(/cheia/.test(i.classes), 'passar da capacidade também conta como cheia');

  // ---------- sem capacidade definida ----------
  i = await ler({ forma:'redonda', capacidade:0, ocupacao:0 });
  ok(i.numero === '', 'sem capacidade e sem ninguém, não se inventa número nenhum');
  ok(i.cadeiras === 4, 'mas desenha-se uma silhueta, para a mesa parecer uma mesa');
  i = await ler({ forma:'redonda', capacidade:0, ocupacao:5 });
  ok(i.numero === '5', 'sem capacidade mas com gente sentada, mostra quantos são');

  // ---------- o desenho não rebenta com números grandes ----------
  i = await ler({ forma:'oval', capacidade:40, ocupacao:33 });
  ok(i.cadeiras <= 14, `acima de 14 lugares o desenho pára de encher (${i.cadeiras} cadeiras)`);
  ok(i.numero === '33/40', 'e o número continua a dizer a verdade toda');

  // ---------- cada forma tem o seu desenho ----------
  const formas = ['redonda','oval','quadrada','retangular','comprida','ferradura'];
  const caminhos = await p.evaluate((fs) => fs.map(fo => {
    const d = document.createElement('div');
    d.innerHTML = mesaIcone({ forma: fo, capacidade: 8 }, { tam: 60 });
    const t = d.firstChild.querySelector('.mi-t');
    return t.tagName + '|' + (t.getAttribute('d') || t.getAttribute('rx') || '')
         + '|' + (t.getAttribute('width') || '');
  }), formas);
  ok(new Set(caminhos).size === formas.length,
     'as seis formas desenham seis mesas diferentes — já não são o mesmo quadrado');

  // ---------- o escolhedor de forma mostra mesas ----------
  const nasBotoes = await p.evaluate(() =>
    document.querySelectorAll('#nova-forma button svg.mesa-ico').length);
  ok(nasBotoes === 6, `o escolhedor de forma mostra as 6 mesas desenhadas (${nasBotoes})`);
  const semNumero = await p.evaluate(() =>
    document.querySelectorAll('#nova-forma button svg .mi-n').length);
  ok(semNumero === 0, 'e sem números: ali escolhe-se a forma, não a lotação');

  // ---------- na PLANTA, que é onde se trabalha ----------
  // O ícone chegou à lista e ao escolhedor e ficou-se por aí: a planta continuou
  // a desenhar a mesa à sua maneira, com border-radius e clip-path, e essa não
  // sabia dizer quantos lugares havia. Eram dois desenhos da mesma mesa, e só um
  // deles respondia à pergunta.
  const nova = await api('mesa_save', { id:0, nome:'ZZ Planta', capacidade:9, forma:'oval' });
  await p.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const naPlanta = await p.evaluate((id) => {
    const n = document.querySelector('.mesa-node[data-id="' + id + '"]');
    if (!n) return null;
    const svg = n.querySelector('svg.mesa-ico');
    return { temSvg: !!svg,
             cadeiras: svg ? svg.querySelectorAll('.mi-c').length : 0,
             numero: svg ? (svg.querySelector('.mi-n') || {}).textContent : '',
             // O nome vive na camada de rótulos, por cima de todas as mesas:
             // dentro do nó, ficava tapado pela mesa desenhada a seguir.
             nome: (document.querySelector('#rotulos .mn-nome[data-id="' + id + '"]') || {}).textContent || '',
             nos: document.querySelectorAll('.mesa-node').length,
             comIcone: document.querySelectorAll('.mesa-node > svg.mesa-ico').length };
  }, nova.mesa ? nova.mesa.id : nova.id);
  ok(naPlanta && naPlanta.temSvg, 'a mesa na planta é desenhada pelo mesmo ícone');
  ok(naPlanta && naPlanta.cadeiras === 9,
     `com uma cadeira por lugar (${naPlanta && naPlanta.cadeiras})`);
  ok(naPlanta && naPlanta.numero === '9',
     `e a capacidade lá dentro, que a planta antes não dizia (${naPlanta && naPlanta.numero})`);
  ok(naPlanta && naPlanta.nome.includes('ZZ Planta'),
     'o nome fica ao pé dela, na camada que nenhuma mesa tapa');
  ok(naPlanta && naPlanta.nos === naPlanta.comIcone,
     `nenhuma mesa da planta ficou por converter (${naPlanta && naPlanta.comIcone}/${naPlanta && naPlanta.nos})`);
  // A mesa dos noivos também: é a que tem desenho próprio, e por isso era a que
  // mais facilmente ficava para trás.
  const noivos = await p.evaluate(() => {
    const n = document.querySelector('.mesa-node.forma-noivos');
    return n ? { temSvg: !!n.querySelector('svg.mesa-ico.f-noivos'),
                 semNumero: !n.querySelector('.mi-n') } : null;
  });
  ok(noivos && noivos.temSvg, 'a mesa dos noivos também, com o desenho que é dela');
  ok(noivos && noivos.semNumero, 'e sem lotação: ali não se conta, reserva-se');

  const alvoP = (await api('mesa_list')).mesas.find(m => m.nome === 'ZZ Planta');
  if (alvoP) await api('mesa_delete&id=' + alvoP.id);

  // ---------- na lista de mesas do painel ----------
  await api('mesa_save', { id:0, nome:'ZZ Ícone', capacidade:8, forma:'retangular' });
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  await p.evaluate(() => abrirMesas());
  await p.waitForTimeout(700);
  const naLista = await p.evaluate(() =>
    document.querySelectorAll('#lista-mesas-gestao .mesa-ico').length);
  ok(naLista > 0, `a lista de mesas do painel mostra o ícone de cada mesa (${naLista})`);

  // ---------- limpeza ----------
  const ms = await api('mesa_list');
  const alvo = (ms.mesas || []).find(m => m.nome === 'ZZ Ícone');
  if (alvo) await api('mesa_delete&id=' + alvo.id);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
