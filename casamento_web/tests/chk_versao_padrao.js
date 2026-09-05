// A versão padrão — a peça como o sistema a traz de origem. Existe sempre,
// aparece no seletor, aplica-se para voltar atrás, e não se apaga, não se
// renomeia nem se reescreve: quem a edita guarda com outro nome. Dá-se a
// conhecer pelo nome do MODELO de origem da casa (Isabel & Abednego), e não
// por «Original», que não é modelo nenhum.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const ORIGEM = 'Isabel & Abednego';   // o nome por que a peça de origem se dá a conhecer

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1500, height: 950 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });


  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  const limpar = async (amb) => {
    const d = await api('versao_lista&ambito=' + amb);
    for (const v of (d.versoes || [])) if (!v.padrao) await api('versao_apagar&id=' + v.id, {});
  };
  await limpar('digital'); await limpar('impresso');
  await api('versao_aplicar&id=0&ambito=digital');

  // ---------- a padrão existe sempre ----------
  let d = await api('versao_lista&ambito=digital');
  const padrao = (d.versoes || []).filter(v => v.padrao)[0];
  ok(!!padrao, 'a versão padrão aparece na lista mesmo sem nada guardado');
  ok(padrao && padrao.nome === ORIGEM, 'chama-se «' + ORIGEM + '» — o modelo de origem, não «Original»');
  ok(padrao && padrao.em_vigor === true, 'com a peça de origem, é ela que está em vigor');
  ok(d.alguma_em_vigor === true, 'a peça nunca fica "fora de qualquer versão"');

  // ---------- não se apaga, não se renomeia, não se reescreve ----------
  for (const [accao, corpo, rot] of [
    ['versao_apagar&id=0&ambito=digital',    null,               'apagar'],
    ['versao_atualizar&id=0&ambito=digital', null,               'reescrever'],
    ['versao_renomear&id=0&ambito=digital',  { nome: 'Outro' },  'renomear']]) {
    const r = await api(accao, corpo);
    ok(r && r.success === false, 'o servidor recusa ' + rot + ' a versão padrão');
    if (r && r.message) console.log('   →', rot + ':', r.message);
  }
  d = await api('versao_lista&ambito=digital');
  ok((d.versoes || []).some(v => v.padrao && v.nome === ORIGEM),
     'e a padrão continua lá, intacta, depois das tentativas');

  // ---------- o painel de versões, em cada estado ----------
  // O <select> de tudo-misturado deu lugar a um botão de estado e a uma janela
  // com duas abas. Lê-se o botão (o estado) e o corpo da aba das versões.
  const painel = async () => {
    await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1700);
    await p.click('#bt-versao');
    await p.waitForTimeout(900);
    return p.evaluate(() => ({
      botao: (document.getElementById('bt-versao') || {}).textContent || '',
      estado: (document.querySelector('.vs-estado') || {}).textContent || '',
      itens: [...document.querySelectorAll('.vs-it')].map(i => ({
        nome: (i.querySelector('.vs-it-nm') || {}).textContent.trim(),
        acoes: [...i.querySelectorAll('[data-ac]')].map(b => b.dataset.ac)
      })),
      temNova: !!document.querySelector('[data-ac=nova]')
    }));
  };
  const gerirDe = (o, nome) => {
    const it = o.itens.filter(i => i.nome.indexOf(nome) === 0)[0];
    return it ? it.acoes : [];
  };

  let o = await painel();
  ok(o.botao.includes(ORIGEM) && /em vigor/.test(o.botao),
     'o botão da barra diz «' + ORIGEM + ' — em vigor»');
  ok(/Em vigor/.test(o.estado) && o.estado.includes(ORIGEM),
     'e o painel repete-o, com o que isso quer dizer');
  ok(gerirDe(o, ORIGEM).indexOf('apagar') < 0
     && gerirDe(o, ORIGEM).indexOf('renomear') < 0,
     'na origem não se oferece renomear, atualizar nem apagar');

  // Editar a partir do Original: a única saída é guardar com outro nome
  await api('defs_save', { defs: { 'textos.kicker': 'SAIU DO ORIGINAL' } });
  o = await painel();
  console.log('   editado a partir do Original:', o.botao.replace(/\s+/g, ' '));
  ok(/Alterado/.test(o.botao), 'o botão passa a dizer «Alterado»');
  ok(/alterações por versionar/i.test(o.estado) && o.estado.includes(ORIGEM),
     'e o painel diz de onde a peça veio');
  ok(o.temNova, 'oferece guardar como versão nova');

  // ---------- guardar com um nome: é esse nome que aparece ----------
  await api('versao_criar&ambito=digital', { nome: 'Clássica dourada' });
  o = await painel();
  console.log('   depois de guardar:', JSON.stringify(o.itens.map(i => i.nome)));
  ok(/Clássica dourada/.test(o.botao) && /em vigor/.test(o.botao),
     'a versão guardada aparece em vigor, com o nome escolhido');
  ok(o.itens.some(i => i.nome.indexOf(ORIGEM) === 0), 'a origem continua na lista');
  const g = gerirDe(o, 'Clássica dourada');
  ok(g.indexOf('renomear') >= 0 && g.indexOf('atualizar') >= 0 && g.indexOf('apagar') >= 0,
     'numa versão guardada já se pode renomear, atualizar e apagar');
  ok(gerirDe(o, ORIGEM).indexOf('apagar') < 0,
     'e a origem continua sem essas ações — cada linha manda em si');

  // ---------- voltar ao Original repõe mesmo a peça ----------
  // Lê-se do que o servidor entrega. Tem de ser o objeto ATUAIS: a página traz
  // também o PADRAO, e apanhar a primeira ocorrência da chave dava o valor de
  // origem — que é justamente o que este teste quer distinguir.
  const kicker = () => p.evaluate(async () => {
    const t = (await (await fetch('convite-editor.php')).text()).replace(/&quot;/g, '"');
    const m = t.match(/const ATUAIS\s*=\s*(\{.*?\});/s);
    if (!m) return null;
    try { return JSON.parse(m[1])['textos.kicker']; } catch (e) { return null; }
  });
  await api('defs_save', { defs: { 'textos.kicker': 'ALGO BEM DIFERENTE' } });
  ok(await kicker() === 'ALGO BEM DIFERENTE', 'a peça foi mesmo alterada');
  const ap = await api('versao_aplicar&id=0&ambito=digital');
  ok(ap && ap.success === true, 'aplicar o Original responde bem');
  const dep = await kicker();
  console.log('   kicker depois de voltar ao Original:', JSON.stringify(dep));
  ok(dep !== 'ALGO BEM DIFERENTE', 'voltar ao Original repõe a peça de origem');
  d = await api('versao_lista&ambito=digital');
  ok((d.versoes || []).filter(v => v.padrao)[0].em_vigor === true,
     'e a origem volta a constar como em vigor');
  ok((d.versoes || []).some(v => !v.padrao && v.nome === 'Clássica dourada'),
     'voltar ao Original não apaga as versões guardadas');

  // ---------- o mesmo vale para o convite impresso ----------
  const di = await api('versao_lista&ambito=impresso');
  ok((di.versoes || []).some(v => v.padrao && v.nome === ORIGEM),
     'o convite impresso também tem a sua versão padrão');
  const ri = await api('versao_apagar&id=0&ambito=impresso');
  ok(ri && ri.success === false, 'e também lá se recusa apagá-la');

  // ---------- limpeza ----------
  await limpar('digital');
  await api('versao_aplicar&id=0&ambito=digital');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
