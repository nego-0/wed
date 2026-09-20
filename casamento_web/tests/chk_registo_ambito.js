// O histórico de cada casamento é o DELE. E lê-se num telemóvel.
//
// registar() escrevia sempre no casamento ABERTO. Parece inofensivo e errava
// dos dois lados ao mesmo tempo:
//
//   • PARA DENTRO. O admin tinha o casamento 1 aberto e foi apagar o casamento
//     17. A linha ficou no histórico do 1. O casal do 1 lia, na sua página,
//     «apagou um casamento — ZZ Casamento A · 1 convites · 1 pessoas · 2
//     contas»: o nome e o tamanho da festa de gente que não conhece. Eram 40
//     linhas assim, num só casamento, no arquivo de trabalho.
//
//   • PARA FORA. Uma licença revogada decide-se na plataforma, onde não há
//     casamento aberto — logo ia para o zero, que o casal não vê. A licença
//     dizia-lhe «revogada» num canto do painel e o histórico dele não tinha
//     linha nenhuma a dizer quem, quando, nem porquê.
//
// Esta prova faz as duas coisas de verdade, pela API, e vai VER o que o casal
// vê: cria dois casamentos, revoga a licença de um deles a partir da
// plataforma, apaga um modelo da casa, e depois pergunta ao histórico de cada
// um o que lá está. O que não é de um casamento não pode aparecer no dele.
//
// E mede o que não cabia: a tabela do admin media 634px dentro de uma caixa de
// 310 — metade de cada linha fora do ecrã —, e cada linha do histórico do casal
// gastava 190px de altura para dizer uma frase, porque quatro colunas a
// disputar 313px partem a frase em seis linhas.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzreg' + Math.floor(Math.random() * 1e6);

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');

  const api = (acao, corpo) => p.evaluate(async ({ a, c }) => {
    const o = { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } };
    if (c) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(c); }
    const r = await fetch('api.php?action=' + a, o);
    return r.json().catch(() => ({ success: false }));
  }, { a: acao, c: corpo || null });

  // ---- dois casamentos de prova ----
  const A = await api('casamento_criar', { nome: 'ZZ Alfa ' + marca, licenca: 12 });
  const B = await api('casamento_criar', { nome: 'ZZ Beta ' + marca, licenca: 12 });
  ok(A.success && B.success, 'criou dois casamentos de prova');
  const idA = A.id, idB = B.id;

  // ---- uma decisão SOBRE o casamento A, tomada de fora dele ----
  // Fecha-se o A de propósito: é assim que acontece na vida real, o admin está
  // na plataforma e não tem casamento nenhum aberto.
  await api('casamento_abrir&id=' + idB);
  const motivo = 'Incumprimento das políticas ' + marca;
  const rev = await api('lic_revogar', { casamento: idA, motivo });
  ok(rev.success, 'revogou a licença do casamento A a partir da plataforma');

  // ---- e uma coisa DA CASA, com o casamento B aberto ----
  const mod = await api('modelo_criar', { nome: 'ZZ Modelo ' + marca, ambito: 'digital' });
  const apg = mod.id ? await api('modelo_apagar&id=' + mod.id) : { success: false };
  ok(apg.success, 'criou e apagou um modelo da casa, com o casamento B aberto');

  // ---- o que o casal de A vê ----
  await api('casamento_abrir&id=' + idA);
  const regA = await api('registo_lista&por_pagina=200');
  const linhasA = regA.registos || [];
  const temRevoga = linhasA.some(r => r.accao === 'licenca_revogar' && (r.detalhe || '').includes(marca));
  ok(temRevoga,
     'o casal de A vê no SEU histórico que lhe revogaram a licença — quem, quando e porquê. '
     + 'Antes, essa linha ia para o zero e ele nunca a via');
  const quem = (linhasA.find(r => r.accao === 'licenca_revogar') || {}).utilizador;
  ok(quem === 'admin', 'e a linha diz a conta que o decidiu: ' + quem);

  const falaDoB = linhasA.filter(r => (r.alvo || '').includes('ZZ Beta ' + marca));
  ok(falaDoB.length === 0,
     'e não vê linha nenhuma sobre o casamento B: ' + falaDoB.length + ' encontradas');
  const casaEmA = linhasA.filter(r => r.accao === 'modelo_apagado' || r.accao === 'modelo_criado');
  ok(casaEmA.length === 0,
     'nem o que se fez aos modelos DA CASA — não é dele, e caía-lhe no histórico '
     + 'só porque o admin tinha um casamento aberto: ' + casaEmA.length + ' encontradas');

  // ---- o que o casal de B vê ----
  await api('casamento_abrir&id=' + idB);
  const regB = await api('registo_lista&por_pagina=200');
  const linhasB = regB.registos || [];
  const bViuRevoga = linhasB.filter(r => r.accao === 'licenca_revogar');
  ok(bViuRevoga.length === 0,
     'o casal de B não vê a licença revogada ao A, ainda que o B estivesse aberto '
     + 'quando ela foi revogada: ' + bViuRevoga.length + ' encontradas');
  const bViuModelo = linhasB.filter(r => r.accao === 'modelo_apagado');
  ok(bViuModelo.length === 0,
     'nem o modelo da casa que foi apagado com ele aberto: ' + bViuModelo.length);

  // ---- o admin vê tudo ----
  const aud = await api('registo_auditoria&por_pagina=200&q=' + marca);
  const todas = aud.registos || [];
  const vistas = new Set(todas.map(r => r.accao));
  ok(vistas.has('licenca_revogar') && vistas.has('modelo_apagado') && vistas.has('casamento_criado'),
     'o admin, na auditoria, vê as três — a do casamento, a da casa e a criação: '
     + [...vistas].join(', '));
  const daCasa = todas.filter(r => r.accao === 'modelo_apagado');
  ok(daCasa.length > 0 && daCasa.every(r => +r.casamento_id === 0),
     'e a do modelo aparece-lhe como «Plataforma», que é de quem ela é');

  // ---- entrar no mesmo casamento não enche o histórico ----
  // Era isto que fazia 132 linhas iguais num só casal: cada recarregamento da
  // página reabre o casamento que já estava aberto, e cada um escrevia linha.
  const antes = (await api('registo_lista&por_pagina=1')).total;
  for (let i = 0; i < 4; i++) await api('casamento_abrir&id=' + idB);
  const depois = (await api('registo_lista&por_pagina=1')).total;
  ok(depois === antes,
     'reabrir o casamento que já estava aberto não escreve linha nenhuma: '
     + antes + ' → ' + depois);
  await api('casamento_abrir&id=' + idA);
  await api('casamento_abrir&id=' + idB);
  const trocou = (await api('registo_lista&por_pagina=1')).total;
  ok(trocou > depois, 'mas MUDAR de casamento escreve — é quem entrou na casa de quem');

  // ---- e agora o que se vê, num telemóvel ----
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await m.fill('input[name=utilizador]', 'admin');
  await m.fill('input[name=senha]', 'noivos2026');
  await m.click('button[type=submit]');
  await m.waitForLoadState('networkidle');
  // Esta janela tem sessão própria: sem abrir casamento, o painel manda-a
  // escolher um e não há histórico nenhum para medir.
  await m.evaluate(async i => {
    await fetch('api.php?action=casamento_abrir&id=' + i,
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }, idB);

  await m.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await m.evaluate(() => verVista('registo'));
  await m.waitForTimeout(2600);
  const tab = await m.evaluate(() => {
    const t = document.getElementById('aud-tabela');
    if (!t) return null;
    const cx = t.closest('div');
    return { tabela: Math.round(t.getBoundingClientRect().width),
             caixa: Math.round(cx.getBoundingClientRect().width),
             pagina: document.documentElement.scrollWidth - innerWidth };
  });
  ok(!!tab && tab.tabela <= tab.caixa + 1,
     'o quadro do admin cabe na caixa em vez de transbordar: '
     + (tab ? tab.tabela + 'px em ' + tab.caixa + 'px (era 634 em 310)' : 'sem tabela'));
  ok(!!tab && tab.pagina === 0, 'e a página não rola de lado');

  await m.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await m.waitForTimeout(1400);
  await m.evaluate(() => { abrirHistorico(); abaHistorico('registo'); });
  await m.waitForTimeout(2400);
  const alt = await m.evaluate(() => {
    const ls = [...document.querySelectorAll('.reg-linha')].slice(0, 12);
    if (!ls.length) return null;
    const hs = ls.map(l => l.querySelector('summary').getBoundingClientRect().height);
    return { pior: Math.round(Math.max(...hs)), n: ls.length,
             // A conta que fez a coisa tem de estar DENTRO da frase, e não
             // numa coluna que no telemóvel esmaga tudo o resto.
             quemNaFrase: !!ls[0].querySelector('.reg-que .reg-quem') };
  });
  ok(!!alt && alt.pior < 90,
     'e cada ação do casal cabe numa linha ou duas em vez de seis: '
     + (alt ? alt.pior + 'px de altura (eram 190)' : 'sem linhas'));
  ok(!!alt && alt.quemNaFrase,
     'com a conta dentro da frase — «admin entrou no casamento» — e não numa coluna à parte');

  // ---- arrumar ----
  await api('casamento_abrir&id=' + idB);
  await api('casamento_apagar&id=' + idA, { confirmar: 'ZZ Alfa ' + marca });
  await api('casamento_apagar&id=' + idB, { confirmar: 'ZZ Beta ' + marca });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
