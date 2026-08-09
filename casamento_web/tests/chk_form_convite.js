// O formulário de convites foi reorganizado (secções, uma linha por pessoa e
// os pormenores de cada pessoa atrás do "⋯"). Nenhum campo foi retirado — este
// teste preenche TODOS, grava, reabre e confirma que todos voltam com o que se
// escreveu. É o caminho que o utilizador percorre, não só a API.
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

  // Uma pessoa por linha: os pormenores só aparecem ao abrir o "⋯"
  ok(await p.evaluate(() => {
    const r = document.querySelectorAll('#membros .membro-linha')[1];
    return getComputedStyle(r.querySelector('.m-extras')).display === 'none';
  }), 'os pormenores de cada pessoa começam fechados');

  await p.evaluate((mesa) => {
    const r = document.querySelectorAll('#membros .membro-linha')[1];
    r.querySelector('.m-mais').click();
    r.querySelector('.m-genero').value = 'm';
    const ms = r.querySelector('.m-mesa');
    const opt = [...ms.options].find(o => o.textContent.includes(mesa));
    if (opt) ms.value = opt.value;
    r.querySelector('.m-brinde input').checked = true;
    r.querySelector('.m-extras').dispatchEvent(new Event('change', { bubbles: true }));
  }, MESA);
  await p.waitForTimeout(200);
  ok(await p.evaluate(() => document.querySelectorAll('#membros .membro-linha')[1].classList.contains('aberta')),
     'o "⋯" abre os pormenores dessa pessoa');
  ok(await p.evaluate(() => document.querySelectorAll('#membros .membro-linha')[1].querySelector('.m-mais').classList.contains('tem')),
     'o "⋯" assinala que a pessoa tem pormenores preenchidos');

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
      ruiGenero: rui ? rui.querySelector('.m-genero').value : null,
      ruiBrinde: rui ? rui.querySelector('.m-brinde input').checked : null,
      ruiMesa: rui ? !!rui.querySelector('.m-mesa').value : null,
      ruiMarcado: rui ? rui.querySelector('.m-mais').classList.contains('tem') : null
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
  ok(v.ruiMarcado === true,               'ao reabrir, o "⋯" mostra que essa pessoa tem pormenores');

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
