// O registo de ações conta tudo, e cada linha abre.
//
// O histórico dizia três coisas — quando, quem, e uma frase — e escondia o
// resto. A frase, pior ainda, era a chave de programador: «lic_escalao_guardar»,
// «def_media_repor», «peca_origem_definida». Uma dúzia delas tinha tradução no
// painel dos noivos; as outras sessenta e tal apareciam em cru, e a auditoria
// do admin mostrava a chave sempre.
//
// Agora cada ação tem nome por extenso e família (vêm do servidor, uma lista
// só), e a linha abre: quem, com que papel, o que fez ao certo, sobre o quê, o
// detalhe, quando (por inteiro) e DE ONDE — o endereço, que é a pergunta que
// se faz quando uma ação não se explica.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1300, height: 1000 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  // Uma ação fresca, para haver o que ler.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const feito = await p.evaluate(async () => await window.api('convite_save',
    { method: 'POST', body: JSON.stringify(
        { nome_exibicao: 'ZZ Registo Prova', tipo: 'digital', lugares: 2 }) }));
  ok(feito.success, 'lança-se um convite, para o registo ter o que contar');

  // ============ 1. o que o servidor manda ============
  const linha = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=registo_lista&por_pagina=10')).json();
    return (d.registos || [])[0];
  });
  ok(linha && linha.accao === 'convite_criado',
     'a ação mais recente é a que acabou de se fazer: ' + (linha && linha.accao));
  ok(linha.frase === 'criou um convite',
     'e vem com o nome por extenso: «' + linha.frase + '»');
  ok(linha.familia === 'convites', 'e com a família a que pertence: ' + linha.familia);
  ok(/ZZ Registo Prova/.test(linha.alvo || ''), 'o alvo diz sobre o quê: ' + linha.alvo);
  ok(!!linha.ip, 'e o registo passa a guardar de onde partiu: ' + linha.ip);
  ok(linha.id > 0 && linha.papel && linha.utilizador && linha.criado_em,
     'com número, quem, papel e quando: #' + linha.id + ' · '
       + linha.utilizador + ' (' + linha.papel + ') · ' + linha.criado_em);

  // Nenhuma chave fica por traduzir: se alguém acrescentar uma ação e se
  // esquecer da lista, é aqui que se dá por isso.
  const cruas = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=registo_lista&por_pagina=500')).json();
    return [...new Set((d.registos || [])
      .filter(r => r.familia === 'outra').map(r => r.accao))];
  });
  ok(cruas.length === 0,
     'todas as ações registadas têm nome por extenso'
       + (cruas.length ? ' — falta(m): ' + cruas.join(', ') : ''));

  // ============ 2. o painel dos noivos: a linha abre ============
  await p.evaluate(() => { abrirHistorico(); abaHistorico('registo'); });
  await p.waitForFunction(() => document.querySelectorAll('.reg-linha').length > 0,
                          null, { timeout: 10000 });
  const fechada = await p.evaluate(() => {
    const d = document.querySelector('.reg-linha');
    return { aberta: d.open, resumo: d.querySelector('summary').textContent.replace(/\s+/g, ' ').trim(),
             // checkVisibility() e não a altura: um <details> fechado esconde o
             // miolo com content-visibility, e a caixa continua a medir.
             detalheAVista: d.querySelector('.reg-detalhe').checkVisibility() };
  });
  ok(!fechada.aberta && !fechada.detalheAVista,
     'as linhas começam fechadas — o histórico continua a ler-se de relance');
  ok(/criou um convite/.test(fechada.resumo) && /convites/.test(fechada.resumo),
     'e a linha fechada já diz a ação por extenso e a família: ' + fechada.resumo);
  ok(!/convite_criado/.test(fechada.resumo),
     'a chave de programador não aparece na linha fechada');

  const aberta = await p.evaluate(() => {
    const d = document.querySelector('.reg-linha');
    d.querySelector('summary').click();
    const dl = d.querySelector('.reg-detalhe dl');
    const rot = [...dl.querySelectorAll('dt')].map(e => e.textContent.trim());
    return { rotulos: rot, texto: dl.textContent.replace(/\s+/g, ' ').trim(),
             aVista: d.querySelector('.reg-detalhe').checkVisibility() };
  });
  ok(aberta.aVista, 'carregar na linha abre-a');
  ['Quem', 'O que fez', 'Sobre', 'Quando', 'De onde'].forEach(r => {
    ok(aberta.rotulos.includes(r), 'aberta, a linha diz «' + r + '»');
  });
  ok(/convite_criado/.test(aberta.texto),
     'e mostra a chave técnica ao lado da frase, para quem precisa dela');
  ok(/127\.0\.0\.1|::1/.test(aberta.texto), 'e o endereço de onde a ação partiu');
  ok(/de setembro|de janeiro|de fevereiro|de março|de abril|de maio|de junho|de julho|de agosto|de outubro|de novembro|de dezembro/i
       .test(aberta.texto),
     'e a data por inteiro, e não só o dia e a hora');
  await p.screenshot({ path: OUT + '/registo-casal.png' });

  // ============ 3. a auditoria do admin: o mesmo, e o casamento ============
  await p.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await p.evaluate(() => verVista('registo'));
  await p.waitForFunction(() => document.querySelectorAll('#aud-tabela .a-linha').length > 0,
                          null, { timeout: 12000 });
  const acoes = await p.evaluate(() =>
    [...document.querySelectorAll('#aud-accao option')].map(o => o.textContent));
  ok(acoes.length > 1 && acoes.includes('criou um convite'),
     'o filtro de ações oferece os nomes por extenso: ' + acoes.slice(0, 4).join(', '));

  const audFechada = await p.evaluate(() => ({
    accao: document.querySelector('#aud-tabela .a-linha .a-accao').textContent.trim(),
    detalhe: !!document.querySelector('#aud-tabela .a-detalhe:not([hidden])')
  }));
  ok(!audFechada.detalhe, 'também aqui as linhas começam fechadas');
  ok(!/_/.test(audFechada.accao), 'e a coluna da ação já não mostra a chave: '
     + audFechada.accao);

  const audAberta = await p.evaluate(() => {
    audAbrir(0);
    const d = document.getElementById('aud-det-0');
    return { visivel: !d.hidden,
             rotulos: [...d.querySelectorAll('dt')].map(e => e.textContent.trim()),
             texto: d.textContent.replace(/\s+/g, ' ').trim() };
  });
  ok(audAberta.visivel, 'carregar numa linha abre-a');
  ['Quem', 'O que fez', 'Casamento', 'Quando', 'De onde', 'Nº de registo'].forEach(r => {
    ok(audAberta.rotulos.includes(r), 'e a auditoria diz «' + r + '»');
  });
  ok(/#\d+/.test(audAberta.texto),
     'com o número da linha, que é o que se cita quando se fala dela');
  await p.screenshot({ path: OUT + '/registo-admin.png' });

  await p.evaluate(() => audAbrir(0));
  await p.waitForTimeout(200);
  ok(await p.evaluate(() => document.getElementById('aud-det-0').hidden),
     'e carregar outra vez fecha-a');

  // ============ 4. arrumar ============
  await p.evaluate(async (id) => {
    await window.api('convite_delete&definitivo=1&id=' + id, { method: 'POST' });
  }, feito.id);

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
