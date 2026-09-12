// O formulário de convites: secções, e cada pessoa em duas linhas de colunas
// alinhadas — nome, mesa e brindes em cima; género e papel em baixo, tudo à
// vista. Nenhum campo foi retirado: este teste preenche TODOS, grava, reabre e
// confirma que todos voltam com o que se escreveu. É o caminho que o
// utilizador percorre, não só a API.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
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

  await p.waitForTimeout(1000);

  const api = (accao, corpo) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, {
      method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a: accao, c: corpo });

  const MESA = 'Mesa Prova Formulario';
  await api('mesa_save', { nome: MESA, capacidade: 8 });
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(1000);

  // ---------- preencher todos os campos ----------
  await p.evaluate(() => abrirConvite());
  await p.waitForTimeout(300);
  await p.evaluate(() => {
    document.getElementById('membros').innerHTML = '';
    addMembro('Ana Prova'); addMembro('Rui Prova'); renderSugestoes();
  });
  await p.waitForTimeout(300);

  // O nome a exibir chega proposto a partir dos nomes das pessoas
  ok(await p.evaluate(() => document.getElementById('c-nome').value) === 'Família Prova',
     'o nome a exibir vem proposto a partir dos nomes das pessoas');
  ok(await p.evaluate(() => !document.getElementById('c-lugares') && !document.getElementById('c-sufixo')),
     'o formulário já não pede lugares nem sufixo');

  // Todos os campos de cada pessoa estão à vista — nada atrás de um "⋯".
  ok(await p.evaluate(() => !document.querySelector('#membros .m-mais')),
     'o "⋯" desapareceu: os campos de cada pessoa estão todos à vista');
  ok(await p.evaluate(() => {
    const r = document.querySelectorAll('#membros .membro-linha')[1];
    return getComputedStyle(r.querySelector('.m-extras')).display !== 'none'
        && ['.m-mesa', '.m-brinde', '.m-genero', '.m-papel']
             .every(s => r.querySelector(s).getBoundingClientRect().width > 0);
  }), 'mesa, brindes, género e papel veem-se sem abrir nada');

  // As proporções pedidas: nome 50%, mesa 25%, brindes 25%; e em baixo quatro
  // pastilhas de 25% cada, alinhadas por baixo dos campos de cima.
  const larg = await p.evaluate(() => {
    const r = document.querySelectorAll('#membros .membro-linha')[1];
    const L = s => r.querySelector(s).getBoundingClientRect();
    const linha1 = L('.m-brinde').right - L('input[type=text]').left;
    const pc = n => Math.round(n / linha1 * 1000) / 10;
    const bts = [...r.querySelectorAll('.m-extras .seg button')];
    return { nome: pc(L('input[type=text]').width), mesa: pc(L('.m-mesa').width),
             brinde: pc(L('.m-brinde').width),
             pastilhas: bts.map(b => pc(b.getBoundingClientRect().width)),
             // A segunda linha começa e acaba onde a primeira: é isso o alinhamento.
             esq: Math.abs(bts[0].getBoundingClientRect().left - L('input[type=text]').left) < 2,
             dir: Math.abs(bts[3].getBoundingClientRect().right - L('.m-brinde').right) < 2 };
  });
  console.log('   larguras:', JSON.stringify(larg));
  const perto = (v, alvo) => Math.abs(v - alvo) <= 3;
  ok(perto(larg.nome, 50),   `o nome ocupa metade da linha (${larg.nome}%)`);
  ok(perto(larg.mesa, 25),   `a mesa ocupa um quarto (${larg.mesa}%)`);
  ok(perto(larg.brinde, 25), `os brindes ocupam um quarto (${larg.brinde}%)`);
  ok(larg.pastilhas.length === 4 && larg.pastilhas.every(v => perto(v, 25)),
     `as quatro pastilhas ocupam um quarto cada (${larg.pastilhas.join('% · ')}%)`);
  ok(larg.esq && larg.dir, 'e a segunda linha começa e acaba onde a primeira');

  await p.evaluate((mesa) => {
    const r = document.querySelectorAll('#membros .membro-linha')[1];
    r.querySelector('.m-genero button[data-v=m]').click();
    const ms = r.querySelector('.m-mesa');
    const opt = [...ms.options].find(o => o.textContent.includes(mesa));
    if (opt) ms.value = opt.value;
    r.querySelector('.m-brinde input').checked = true;
  }, MESA);
  await p.waitForTimeout(200);

  await p.evaluate((mesa) => {
    document.getElementById('c-telefone').value = '+244912345678';
    document.getElementById('c-obs').value = 'Obs de prova';
    document.getElementById('c-msg').value = 'Mensagem de prova';
    document.getElementById('c-mesa').value = mesa;
    document.getElementById('c-mostrar-num-mesa').checked = false;
    pickVal('c-tipo', 'ambos'); pickVal('c-lado', 'noiva'); pickVal('c-presenca', 'confirmado');
    atualizarPrevia();
  }, MESA);
  await p.evaluate(() => guardarConvite());
  await p.waitForTimeout(1600);

  // ---------- reabrir e conferir que nada se perdeu ----------
  const id = await p.evaluate(() => {
    const c = (CONVITES || []).find(x => (x.nome_exibicao || '').indexOf('Família Prova') === 0);
    return c ? c.id : 0;
  });
  ok(!!id, 'o convite foi criado');
  if (!id) { console.log('\n1 FALHA(S)'); await b.close(); process.exit(1); }

  // Pelo caminho real da aplicação: editar() vai buscar o convite completo.
  await p.evaluate((i) => editar(i), id);
  await p.waitForTimeout(900);

  const v = await p.evaluate(() => {
    const g = i => document.getElementById(i).value;
    const rows = [...document.querySelectorAll('#membros .membro-linha')];
    const rui = rows.find(r => r.querySelector('input[type=text]').value === 'Rui Prova');
    return {
      nome: g('c-nome'),
      telefone: g('c-telefone'), obs: g('c-obs'), msg: g('c-msg'), mesa: g('c-mesa'),
      numMesa: document.getElementById('c-mostrar-num-mesa').checked,
      tipo: g('c-tipo'), lado: g('c-lado'), presenca: g('c-presenca'),
      pessoas: rows.length,
      ruiGenero: rui ? rui.querySelector('.m-genero').dataset.v : null,
      ruiBrinde: rui ? rui.querySelector('.m-brinde input').checked : null,
      ruiMesa: rui ? !!rui.querySelector('.m-mesa').value : null
    };
  });
  // Os lugares já não têm campo: lêem-se de onde passaram a viver.
  v.lugaresBD = (await api('convite_get&id=' + id)).convite.lugares;
  console.log('   reaberto:', JSON.stringify(v));

  ok(v.nome === 'Família Prova',          'nome a exibir volta certo');
  ok(+v.lugaresBD === 2,                  'os lugares ficam iguais ao número de pessoas (2)');
  ok(v.telefone === '+244912345678',      'telefone volta certo');
  ok(v.obs === 'Obs de prova',            'observações voltam certas');
  ok(v.msg === 'Mensagem de prova',       'mensagem pessoal volta certa');
  ok(v.mesa === MESA,                     'mesa do convite volta certa');
  ok(v.numMesa === false,                 '"mostrar lugares por mesa" volta desligado');
  ok(v.tipo === 'ambos',                  'tipo volta certo');
  ok(v.lado === 'noiva',                  'lado volta certo');
  ok(v.presenca === 'confirmado',         'presença volta certa');
  ok(v.pessoas === 2,                     'as duas pessoas voltam');
  ok(v.ruiGenero === 'm',                 'género da pessoa volta certo');
  ok(v.ruiBrinde === true,                'brinde da pessoa volta certo');
  ok(v.ruiMesa === true,                  'mesa individual da pessoa volta certa');

  // ---------- género e papel: duas pastilhas, e o papel segue o género ----------
  const seg = await p.evaluate(() => {
    const r = document.querySelectorAll('#membros .membro-linha')[0];
    const gs = r.querySelector('.m-genero'), ps = r.querySelector('.m-papel');
    const pm = () => ps.querySelectorAll('button')[1];
    const out = {};
    // De origem: Convidado aceso, e a segunda pastilha por escrever.
    out.convidadoPorPadrao = ps.querySelectorAll('button')[0].classList.contains('on') && !ps.dataset.v;
    out.pmSemGenero = { rot: pm().textContent.trim(), travada: pm().disabled };
    pm().click();                                   // não deve fazer nada
    out.semGeneroNaoPega = !ps.dataset.v;

    gs.querySelector('button[data-v=m]').click();   // Masculino
    out.rotMasculino = pm().textContent.trim();
    out.pmLivre = !pm().disabled;
    pm().click();
    out.papelMasculino = ps.dataset.v;

    gs.querySelector('button[data-v=f]').click();   // passa a Feminino
    out.rotFeminino = pm().textContent.trim();
    out.papelFeminino = ps.dataset.v;               // acompanha, sem ser preciso repetir

    gs.querySelector('button[data-v=f]').click();   // tira o género
    out.semGeneroVoltaConvidado = ps.dataset.v === '' &&
      ps.querySelectorAll('button')[0].classList.contains('on');

    // Deixar como estava para o resto da prova.
    gs.querySelector('button[data-v=f]').click(); pm().click();
    out.mesaTrancadaComPapel = r.querySelector('.m-mesa').disabled;
    return out;
  });
  console.log('   pastilhas:', JSON.stringify(seg));
  ok(seg.convidadoPorPadrao, '"Convidado" vem escolhido de origem');
  ok(seg.pmSemGenero.rot === 'Padrinho · Madrinha' && seg.pmSemGenero.travada,
     'sem género, a segunda pastilha anuncia as duas hipóteses e não se deixa carregar');
  ok(seg.semGeneroNaoPega, 'e carregar nela não escolhe nada');
  ok(seg.rotMasculino === 'Padrinho' && seg.pmLivre, 'com Masculino, passa a dizer "Padrinho"');
  ok(seg.papelMasculino === 'padrinho', 'e é isso que fica escolhido');
  ok(seg.rotFeminino === 'Madrinha', 'trocar para Feminino reescreve o rótulo');
  ok(seg.papelFeminino === 'madrinha', 'e o papel acompanha sozinho — não se repete a escolha');
  ok(seg.semGeneroVoltaConvidado,
     'tirar o género devolve o papel a Convidado, que sem género não tem nome');
  ok(seg.mesaTrancadaComPapel, 'e quem é padrinho/madrinha fica na mesa dos noivos');

  await p.evaluate(() => guardarConvite());
  await p.waitForTimeout(1500);
  await p.evaluate(i => editar(i), id);
  await p.waitForTimeout(900);
  const depois = await p.evaluate(() => {
    const r = [...document.querySelectorAll('#membros .membro-linha')]
      .find(x => x.querySelector('input[type=text]').value === 'Ana Prova');
    const ps = r.querySelector('.m-papel');
    return { papel: ps.dataset.v, rot: ps.querySelectorAll('button')[1].textContent.trim(),
             acesa: ps.querySelectorAll('button')[1].classList.contains('on') };
  });
  ok(depois.papel === 'madrinha', 'o papel grava-se e volta certo');
  ok(depois.rot === 'Madrinha' && depois.acesa,
     'e ao reabrir a pastilha já vem com a palavra certa, acesa');

  // ---------- os lugares seguem as pessoas ----------
  // É esta a razão de o campo ter saído: acrescentar ou tirar uma pessoa muda
  // os lugares sozinho, sem um campo à parte que pudesse discordar da lista.
  await p.evaluate(() => { addMembro('Terceira Prova'); renderSugestoes(); });
  await p.waitForTimeout(200);
  await p.evaluate(() => guardarConvite());
  await p.waitForTimeout(1500);
  let lug = (await api('convite_get&id=' + id)).convite.lugares;
  console.log('   com três pessoas → lugares:', lug);
  ok(+lug === 3, 'acrescentar uma pessoa acrescenta um lugar');

  await p.evaluate((i) => editar(i), id);
  await p.waitForTimeout(900);
  await p.evaluate(() => {
    const rows = [...document.querySelectorAll('#membros .membro-linha')];
    rows[rows.length - 1].querySelector('.btn-ico').click();   // retira a última
  });
  await p.waitForTimeout(200);
  await p.evaluate(() => guardarConvite());
  await p.waitForTimeout(1500);
  lug = (await api('convite_get&id=' + id)).convite.lugares;
  console.log('   com duas pessoas → lugares:', lug);
  ok(+lug === 2, 'retirar uma pessoa retira um lugar');

  // ---------- limpeza ----------
  await api('convite_delete&id=' + id + '&definitivo=1', {});
  const mesas = await api('mesa_list');
  const m = ((mesas && mesas.mesas) || []).find(x => x.nome === MESA);
  if (m) await api('mesa_delete&id=' + m.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
