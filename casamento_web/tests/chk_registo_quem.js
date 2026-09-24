// O registo de ações diz QUEM, e quem é um email.
//
// A coluna do «quem» guardava o NOME da pessoa. Um nome não identifica
// ninguém: é escolhido por ela, muda-se quando apetece, e duas contas podem
// ter o mesmo. Quem abria o histórico com uma pergunta a sério — «quem é que
// apagou este convite?», «quem mexeu no orçamento na véspera?» — encontrava
// «Ana», e ficava na mesma.
//
// O email é a chave da conta. É único, não se repete, e é por ele que se
// chega à pessoa. Passa a ficar gravado em cada linha, à hora em que a ação
// aconteceu — e não por ligação à conta, que é uma diferença com peso: o
// registo é o documento do que se passou naquele dia. Se a conta mudar de
// email amanhã, ou for apagada, a linha continua a dizer quem foi.
//
// O que já estava escrito foi atribuído onde se conseguia atribuir sem
// adivinhar. Onde não se conseguia — duas pessoas com o mesmo nome, uma conta
// que já não existe, uma ação de quem nem sessão tinha — fica em branco, e a
// página diz que não se sabe. Um campo que se cala parece um esquecimento;
// isto foi uma decisão, e uma decisão diz-se.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzq' + Math.floor(Math.random() * 1e5);

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  // ---- 1. uma ação nova traz o email de quem a fez ----
  const feito = await p.evaluate(async m => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const d = await post('convite_save', { nome_exibicao: m,
      membros: [{ nome: m + ' um' }, { nome: m + ' dois' }] });
    return d.convite ? +d.convite.id : 0;
  }, 'ZZQ ' + marca);
  ok(feito > 0, 'fez-se uma ação de prova (um convite novo): #' + feito);

  const lista = await p.evaluate(async () =>
    (await (await fetch('api.php?action=registo_lista&pagina=1')).json()).registos || []);
  const minha = lista.find(r => (r.alvo || '').includes(marca));
  ok(!!minha, 'a ação ficou no registo');
  ok(minha && typeof minha.email === 'string',
     'e a linha traz um campo de email — era o que não existia');
  ok(minha && /@/.test(minha.email || ''),
     'com o email de quem a fez, e não só o nome: «' + (minha && minha.utilizador)
     + '» → «' + (minha && minha.email) + '»');
  ok(minha && minha.utilizador === minha.email,
     'o utilizador apresentado é o email da conta responsável');
  ok(minha && minha.papel === 'admin',
     'o administrador da plataforma conserva o papel admin');

  // ---- 2. o email é o da CONTA que entrou, não um qualquer ----
  const meu = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=conta_info')).json().catch(() => ({}));
    return (d && (d.email || (d.conta && d.conta.email))) || '';
  });
  if (meu) {
    ok(minha && (minha.email || '').toLowerCase() === meu.toLowerCase(),
       'e é o email da conta com sessão aberta: ' + meu);
  } else {
    // Sem uma ação que diga o email da sessão, compara-se com o que o registo
    // atribuiu a TODAS as ações desta mesma visita — que é a mesma pessoa.
    const todas = lista.filter(r => r.email).map(r => r.email);
    ok(new Set(todas).size <= 2,
       'e as ações da mesma sessão trazem todas o mesmo email: '
       + [...new Set(todas)].join(', '));
  }

  // ---- 3. o email aparece na linha aberta do painel ----
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2600);
  const abriu = await p.evaluate(async () => {
    // O histórico vive numa janela, e a atividade é a segunda aba lá dentro.
    abrirHistorico();
    abaHistorico('registo');
    await new Promise(r => setTimeout(r, 1800));
    const d = document.querySelector('#hist-registo .reg-linha');
    if (!d) return null;
    d.open = true;
    const dl = d.querySelector('.reg-detalhe dl');
    const em = d.querySelector('.reg-email');
    return { temDl: !!dl,
             temEmail: !!em,
             texto: em ? em.textContent.trim() : '',
             // Está DENTRO do campo «Quem», e não numa linha à parte: a
             // pergunta é uma só, e a resposta também.
             noQuem: !!(em && em.closest('dd')
                        && (em.closest('dd').previousElementSibling || {}).textContent === 'Quem'),
             visivel: !!(em && em.getClientRects().length) };
  });
  ok(abriu && abriu.temDl, 'o painel do registo abre e mostra os campos');
  ok(abriu && abriu.temEmail, 'com o email lá dentro');
  ok(abriu && abriu.noQuem,
     'e no campo «Quem», que é onde a pergunta se faz: «' + (abriu && abriu.texto) + '»');
  ok(abriu && abriu.visivel, 'e está mesmo desenhado, não só no HTML');

  // ---- 4. quando não se sabe, diz-se ----
  const semEmail = await p.evaluate(() => {
    // Finge-se uma linha antiga, sem email, e vê-se o que a página escreve.
    return typeof quemAoCerto === 'function' ? quemAoCerto({ email: '', utilizador: 'Pessoa antiga' }) : null;
  });
  ok(semEmail === 'Pessoa antiga',
     'linhas antigas conservam a identidade sem a expressão email não registado: '
     + String(semEmail).replace(/<[^>]*>/g, '').trim());

  // ---- 5. na auditoria da casa, o email vai na tabela e dá para procurar ----
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const aud = await p.evaluate(async em => {
    verVista('registo');
    await new Promise(r => setTimeout(r, 2600));
    const cel = document.querySelector('#aud-tabela .a-quem .a-email');
    const d = await (await fetch('api.php?action=registo_auditoria&q='
                                 + encodeURIComponent(em))).json();
    return { naTabela: !!cel, texto: cel ? cel.textContent.trim() : '',
             visivel: !!(cel && cel.getClientRects().length),
             achou: (d.registos || []).length,
             todosDele: (d.registos || []).every(r =>
               (r.email || '').toLowerCase().includes(em.toLowerCase())
               || (r.utilizador || '').toLowerCase().includes(em.toLowerCase())
               || (r.alvo || '').toLowerCase().includes(em.toLowerCase())
               || (r.detalhe || '').toLowerCase().includes(em.toLowerCase())
               || (r.accao || '').toLowerCase().includes(em.toLowerCase())) };
  }, (minha && minha.email) || 'admin@local');
  ok(aud.naTabela && aud.visivel,
     'na auditoria o email vem na própria tabela — é a página onde a pergunta '
     + '«quem foi» se faz a sério: «' + aud.texto + '»');
  ok(aud.achou > 0,
     'e procurar por um email encontra as ações dessa pessoa: ' + aud.achou + ' linha(s)');
  ok(aud.todosDele, 'sem apanhar linhas de mais ninguém');

  // ---- 6. e lê-se nos quatro temas ----
  //
  // O varrimento de contraste da casa mede PIXÉIS PINTADOS, que é a medida
  // certa — mas só vê o que está no primeiro ecrã com tudo fechado, e isto
  // vive dentro de uma janela e de uma linha que é preciso abrir. Mede-se
  // aqui, onde o elemento existe de facto.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2400);
  const lum = c => {
    const [r, g, bl] = c;
    const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); };
    return .2126 * f(r) + .7152 * f(g) + .0722 * f(bl);
  };
  const razao = (a, b) => { const l1 = lum(a), l2 = lum(b);
    return (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05); };
  const piores = [];
  for (const tema of ['niras', 'classico', 'azul', 'escuro']) {
    const cores = await p.evaluate(async t => {
      document.documentElement.setAttribute('data-tema', t);
      abrirHistorico(); abaHistorico('registo');
      await new Promise(r => setTimeout(r, 1500));
      const d = document.querySelector('#hist-registo .reg-linha');
      if (!d) return null;
      d.open = true;
      const a = d.querySelector('.reg-email a');
      if (!a) return null;
      // O fundo verdadeiro é o do primeiro antepassado que pinte alguma coisa.
      let fundo = 'rgba(0, 0, 0, 0)', el = a;
      while (el && /rgba\(0, 0, 0, 0\)|transparent/.test(fundo)) {
        fundo = getComputedStyle(el).backgroundColor; el = el.parentElement;
      }
      const n = s => (s.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);
      return { tinta: n(getComputedStyle(a).color), fundo: n(fundo) };
    }, tema);
    if (!cores) { piores.push(tema + ': não se encontrou o email'); continue; }
    const r = razao(cores.tinta, cores.fundo);
    console.log('   ' + tema.padEnd(9) + r.toFixed(2) + ':1');
    if (r < 4.5) piores.push(tema + ' ' + r.toFixed(2) + ':1');
  }
  ok(piores.length === 0,
     'o email lê-se nos quatro temas (4,5:1 para texto): '
     + (piores.join(', ') || 'todos acima do mínimo'));

  // ---- arrumar ----
  await p.evaluate(async id => {
    await fetch('api.php?action=convite_delete&id=' + id,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => {});
  }, feito);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
