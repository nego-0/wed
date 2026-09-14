// O resumo que acompanha quem está a escolher a licença
// (docs/auditoria-ui-ux.md §25, CONV-001 e CONV-002).
//
// O que aqui se defende:
//
//   1. A conta não fica por baixo da barra de navegação. Esta é uma avaria que
//      esta casa fabricou: a conta cola-se ao fundo do ecrã desde sempre, e a
//      barra de baixo (NAV-001) passou a ocupar esse fundo. Ficavam 57 dos
//      seus 111px enterrados — o total e a linha que diz o que se leva. Não se
//      via em fotografia nenhuma: só a MEIO da rolagem, que é onde a conta
//      está agarrada. No fim da página ela solta-se e sobe, e aí parece bem.
//      Por isso mede-se a meio, e não onde já estava certo.
//   2. A conta diz o que se está a levar PELO NOME. Dizia «3 módulo(s)»; três
//      quais? Um número não se confere, e quem não consegue conferir o que
//      escolheu não submete.
//   3. O gesto está onde a decisão se toma. A página tem mais de seis mil
//      pixéis num telemóvel: quem decidia a meio da lista tinha de rolar até
//      ao fim para encontrar o botão.
//   4. E a promessa de que há volta atrás está à vista. É verdade e o código
//      cumpre-a — um pedido vai a decisão e pode ser cancelado enquanto
//      espera. O que trava um funil destes não é o preço; é não se saber se
//      há como desfazer.
//
// A casa de exemplo tem tudo, e num casamento que já tem tudo não há nada
// para pedir — é por isso que a prova faz o seu, com a licença mais baixa.
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
  const marca = 'fn' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push('admin: ' + e.message));
  const api = admin._api;

  const cat = (await api('lic_catalogo')).catalogo;
  const escal = (ch, ec) => {
    const m = cat.modulos.find(x => x.chave === ch);
    return m && m.escaloes.find(e => e.chave === ec);
  };
  const c80 = escal('convidados', 'convidados_80');
  ok(!!c80, 'o preçário traz o escalão de baixo dos convidados');

  const email = 'casal.' + marca + '@exemplo.ao';
  let d = await api('casamento_criar', { nome: 'Funil ' + marca, data: '2027-06-12',
                                         noivos_email: email, noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  d = await api('lic_conceder', { casamento: d.id, escaloes: [c80.id], meses: 12 });
  ok(d && d.success, 'e deu-lhe só o degrau de baixo, para haver o que pedir');

  // ============ no telemóvel ============
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 },
                                   isMobile: true, hasTouch: true });
  const casal = await entrar(ctx, email, 'senhaforte123');
  casal.on('pageerror', e => errs.push('casal: ' + e.message));
  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForTimeout(2200);

  const medida = await casal.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--nav-baixo-alt').trim());
  ok(/^\d+px$/.test(medida) && parseInt(medida, 10) > 40,
     'a altura da barra de baixo é medida, e não assumida: ' + medida);

  // Escolher alguma coisa, para haver conta e haver gesto.
  const escolha = await casal.evaluate(() => {
    const l = [...document.querySelectorAll('.pl-esc')]
      .filter(e => !e.classList.contains('tem') && !/Deixar como está/.test(e.textContent));
    if (!l.length) return null;
    l[0].click();
    return l[0].querySelector('.pl-esc-nome')
         ? l[0].querySelector('.pl-esc-nome').textContent.trim() : '(sem nome)';
  });
  ok(!!escolha, 'há módulos por levar, que é o que faz deste ecrã um funil: ' + escolha);
  await casal.waitForTimeout(700);

  // ---- a meio da rolagem, que é onde a conta está agarrada ----
  const alt = await casal.evaluate(() => document.body.scrollHeight);
  console.log('  (a página tem ' + alt + 'px num ecrã de 844px)');
  let pior = null;
  for (const frac of [0.2, 0.3, 0.4, 0.5, 0.6]) {
    await casal.evaluate(y => scrollTo(0, y), Math.round(alt * frac));
    await casal.waitForTimeout(320);
    const m = await casal.evaluate(() => {
      const c = document.querySelector('.pl-conta');
      const nb = document.querySelector('.nav-baixo');
      if (!c || !nb) return null;
      const r = c.getBoundingClientRect(), n = nb.getBoundingClientRect();
      if (r.bottom <= 0 || r.top >= innerHeight) return { fora: true, tapada: 0 };
      return { tapada: Math.max(0, Math.round(r.bottom - n.top)) };
    });
    if (m && (!pior || m.tapada > pior)) pior = m.tapada;
  }
  ok(pior === 0,
     'a meio do funil, a conta não fica por baixo da barra de baixo: '
     + pior + 'px enterrados');

  const conta = await casal.evaluate(() => {
    const c = document.querySelector('.pl-conta');
    const bt = document.getElementById('lic-pedir-conta');
    const pr = c.querySelector('.pl-conta-promessa');
    const toca = e => {
      if (!e) return false;
      const q = e.getBoundingClientRect();
      const x = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
      return !!x && (x === e || e.contains(x));
    };
    return {
      total:   (c.querySelector('.pl-conta-val') || {}).textContent || '',
      detalhe: (c.querySelector('.pl-conta-det') || {}).textContent || '',
      botao:   bt ? bt.textContent.trim() : '',
      tocaBotao: toca(bt),
      botaoAlt: bt ? Math.round(bt.getBoundingClientRect().height) : 0,
      promessa: pr ? pr.textContent.replace(/\s+/g, ' ').trim() : '',
      transbordo: document.documentElement.scrollWidth - innerWidth,
    };
  });
  ok(/\d/.test(conta.total) && !/^0\b/.test(conta.total.trim()),
     'a conta mostra um total a pagar: ' + conta.total.trim());
  // O nome do módulo sozinho esconde o degrau, e é o degrau que custa dinheiro;
  // o nome do degrau sozinho é ambíguo («Modelo padrão» é o do digital e o do
  // impresso). Exige-se os dois.
  ok(conta.detalhe.includes(escolha),
     'e diz o DEGRAU que se está a levar — é ele que custa dinheiro: '
     + conta.detalhe.slice(0, 80));
  ok(/convidados/i.test(conta.detalhe),
     'com o módulo a que ele pertence — «Modelo padrão» sozinho é do digital e do impresso');
  ok(/Pedir/.test(conta.botao),
     'o gesto está na conta, onde a decisão se toma: «' + conta.botao + '»');
  ok(conta.tocaBotao, 'e um dedo chega-lhe, sem nada por cima');
  ok(conta.botaoAlt >= 44,
     'com 44px, que é o mínimo com que um dedo acerta: ' + conta.botaoAlt + 'px');
  ok(/cancelad/i.test(conta.promessa) && /pedido/i.test(conta.promessa),
     'e está à vista que há volta atrás: ' + conta.promessa.slice(0, 80));
  ok(conta.transbordo === 0, 'nada disto dá rolagem horizontal à página');

  // ---- o botão da conta leva mesmo ao pedido ----
  // Sem a caixa de aceitação marcada não pode submeter, e é isso que se prova:
  // não falha em silêncio nem submete às escondidas — leva a pessoa à caixa.
  await casal.click('#lic-pedir-conta');
  await casal.waitForTimeout(900);
  const travou = await casal.evaluate(() => {
    const cx = document.getElementById('lic-aceite-cx');
    const r = cx.getBoundingClientRect();
    return { marcado: cx.classList.contains('mau'),
             aVista: r.top > -50 && r.top < innerHeight };
  });
  ok(travou.marcado, 'sem aceitar as políticas, o pedido não vai — e a caixa acende');
  ok(travou.aVista, 'e a página leva a pessoa até ela, em vez de a deixar à procura');

  await casal.evaluate(() => { document.getElementById('lic-aceite').checked = true; });
  await casal.click('#lic-pedir-conta');
  await casal.waitForTimeout(1600);
  const est = await casal._api('lic_estado');
  ok(!!(est && est.licenca && est.licenca.pendente),
     'aceite, o botão da conta submete mesmo o pedido');

  // ============ no ecrã largo ============
  const d2 = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const pc = await entrar(d2, email, 'senhaforte123');
  pc.on('pageerror', e => errs.push('desktop: ' + e.message));
  await pc.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await pc.waitForTimeout(2000);
  const largo = await pc.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--nav-baixo-alt').trim());
  ok(largo === '0px',
     'no ecrã largo não há barra de baixo, e a conta não se levanta à toa: ' + largo);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
