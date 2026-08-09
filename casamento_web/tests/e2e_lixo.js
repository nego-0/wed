// Reciclagem (eliminar reversível) + registo de atividade
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', e => errs.push('PAGEERROR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE: ' + m.text()); });
  const log = (...a) => console.log('•', ...a);
  let fails = 0; const ok = (c, m) => { log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) fails++; };

  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.fill('input[name=utilizador]', 'admin'); await page.fill('input[name=senha]', 'noivos2026');
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');
  await page.waitForTimeout(900);

  // usa o api() da própria página (mesmo caminho que a interface percorre)
  const api = (q, o) => page.evaluate(([q, o]) => window.api(q, o
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(o) }
    : undefined), [q, o]);

  // ---------- reciclagem vazia à partida ----------
  // Adiante conta-se exatamente 1 item na reciclagem, por isso qualquer convite
  // eliminado por uma prova anterior fazia esta falhar sem nada de errado no
  // código. A prova passa a preparar o seu próprio terreno.
  const lixoAntes = await api('reciclagem');
  for (const c of (lixoAntes.convites || [])) await api('convite_delete&id=' + c.id + '&definitivo=1', {});
  if ((lixoAntes.convites || []).length) log('reciclagem limpa:', lixoAntes.convites.length, 'resto(s) de provas anteriores');

  // ---------- estado inicial ----------
  const ini = await api('convite_list');
  const nIni = ini.convites.length, statsIni = ini.stats.convites;
  log('convites:', nIni, '· stats.convites:', statsIni);
  ok(nIni === statsIni, 'lista e estatísticas concordam antes de eliminar');

  // cria um convite descartável para o teste (não mexe nos dados reais)
  const criado = await api('convite_save', {
    nome_exibicao: 'Teste Reciclagem', tipo: 'fisico', lado: 'ambos', lugares: 2,
    membros: [{ nome: 'Pessoa Teste' }], ts: '2026-08-03 10:00:00',
  });
  ok(criado.success, 'convite de teste criado');
  const id = criado.convite.id;
  log('id do convite de teste:', id);

  const dep = await api('convite_list');
  ok(dep.convites.length === nIni + 1, 'aparece na lista depois de criado');

  // ---------- eliminar (reversível) ----------
  const del = await api('convite_delete&id=' + id);
  ok(del.success && del.reversivel === true, 'eliminação devolve "reversível"');

  const semEle = await api('convite_list');
  ok(!semEle.convites.some(c => c.id == id), 'sai da lista do painel');
  ok(semEle.convites.length === nIni, 'a contagem da lista volta ao inicial');
  ok(semEle.stats.convites === statsIni, 'as estatísticas ignoram o eliminado');

  // continua a existir na base de dados
  const lixo = await api('reciclagem');
  const naLixeira = (lixo.convites || []).find(c => c.id == id);
  ok(!!naLixeira, 'está na reciclagem (não foi perdido)');
  ok(naLixeira && naLixeira.nome_exibicao === 'Teste Reciclagem', 'guarda o nome para se reconhecer');

  // não aparece à porta nem no CSV
  const porta = await api('porta_dados');
  ok(!porta.convites.some(c => c.id == id), 'não aparece na cópia offline do porteiro');
  const csv = await page.evaluate(() => fetch('api.php?action=export').then(r => r.text()));
  ok(!csv.includes('Teste Reciclagem'), 'não aparece na exportação CSV');
  const conv = await api('convidado_list');
  ok(!conv.convidados.some(g => g.convite_id == id), 'os seus membros saem da lista de convidados');

  // ---------- repor ----------
  const rep = await api('convite_restaurar&id=' + id);
  ok(rep.success, 'repor devolve sucesso');
  const volta = await api('convite_list');
  ok(volta.convites.some(c => c.id == id), 'volta à lista depois de reposto');
  ok(volta.stats.convites === statsIni + 1, 'volta a contar nas estatísticas');

  // ---------- registo de atividade ----------
  const reg = await api('registo_lista');
  const rs = reg.registos || [];
  log('registos:', rs.length, '· últimos:', rs.slice(0, 4).map(r => r.accao).join(', '));
  ok(rs.length >= 3, 'o registo de atividade tem entradas');
  const accoes = rs.map(r => r.accao);
  ok(accoes.includes('convite_criado'), 'regista a criação');
  ok(accoes.includes('convite_eliminado'), 'regista a eliminação');
  ok(accoes.includes('convite_reposto'), 'regista a reposição');
  // Só as ações desta prova: o registo é de todo o casamento, e desde que há
  // contas a sério passam por lá outras pessoas (uma visita de suporte, um
  // porteiro). Exigir que TUDO fosse do admin era uma prova a falar de si
  // própria e não do que se quer garantir — que a ação fica com o nome de quem
  // a fez.
  const meus = rs.filter(r => ['convite_criado','convite_eliminado','convite_reposto'].includes(r.accao));
  ok(meus.length > 0 && meus.every(r => r.utilizador === 'admin'), 'guarda QUEM fez a ação');

  // ---------- apagar de vez ----------
  await api('convite_delete&id=' + id);
  const hard = await api('convite_delete&definitivo=1&id=' + id);
  ok(hard.success && hard.reversivel === false, 'apagar definitivamente não é reversível');
  const fim = await api('reciclagem');
  ok(!(fim.convites || []).some(c => c.id == id), 'sai da reciclagem quando apagado de vez');
  const fimL = await api('convite_list');
  ok(fimL.convites.length === nIni && fimL.stats.convites === statsIni, 'sistema volta exatamente ao estado inicial');

  // ---------- interface ----------
  // põe um convite na reciclagem para a janela ter o que mostrar
  const cUI = await api('convite_save', { nome_exibicao: 'Teste Janela', tipo: 'digital', lado: 'ambos', membros: ['Um','Dois','Três'], ts: '2026-08-03 10:00:00' });
  await api('convite_delete&id=' + cUI.convite.id);

  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.click('button:has-text("Histórico")');
  await page.waitForTimeout(700);
  const modal = await page.evaluate(() => {
    const o = document.getElementById('ov-historico');
    return {
      aberto: o.classList.contains('aberto'),
      itens: document.querySelectorAll('#hist-lixo .lixo-item').length,
      texto: document.getElementById('hist-lixo').textContent.replace(/\s+/g, ' ').trim().slice(0, 110),
      temRepor: !!document.querySelector('#hist-lixo .lixo-item .btn'),
    };
  });
  log('modal:', JSON.stringify(modal));
  ok(modal.aberto, 'o botão Histórico abre a janela');
  ok(modal.itens === 1, 'a reciclagem lista o convite eliminado');
  ok(/Teste Janela/.test(modal.texto) && /3 lugar/.test(modal.texto), 'mostra nome e lugares do que foi eliminado');
  ok(modal.temRepor, 'oferece o botão Repor');
  await page.screenshot({ path: OUT + '/hist_lixo.png' });

  // repor a partir da janela
  await page.click('#hist-lixo .lixo-item .btn');
  await page.waitForTimeout(900);
  const vazia = await page.evaluate(() => document.getElementById('hist-lixo').textContent);
  ok(/vazia/.test(vazia), 'a reciclagem fica vazia depois de repor pela janela');
  const reposto = await api('convite_list');
  ok(reposto.convites.some(c => c.id == cUI.convite.id), 'o convite reposto pela janela volta ao painel');
  await api('convite_delete&id=' + cUI.convite.id);
  await api('convite_delete&definitivo=1&id=' + cUI.convite.id);

  await page.click('#aba-registo');
  await page.waitForTimeout(700);
  const regUI = await page.evaluate(() => {
    const l = document.querySelectorAll('#hist-registo .reg-linha');
    return { n: l.length, primeiro: l[0] ? l[0].textContent.replace(/\s+/g, ' ').trim() : '' };
  });
  log('registo na UI:', JSON.stringify(regUI));
  ok(regUI.n > 0, 'a aba Atividade lista o histórico');
  ok(/admin/.test(regUI.primeiro), 'mostra quem fez a ação');
  await page.screenshot({ path: OUT + '/hist_registo.png' });

  // ---------- eliminar pela UI, com "Anular" ----------
  const c2 = await api('convite_save', { nome_exibicao: 'Teste Anular', tipo: 'digital', lado: 'ambos', lugares: 1, membros: [], ts: '2026-08-03 10:00:00' });
  await page.reload({ waitUntil: 'networkidle' }); await page.waitForTimeout(900);
  page.on('dialog', d => d.accept());
  await page.evaluate(id => eliminar(id), c2.convite.id);
  await page.waitForTimeout(900);
  const t = await page.evaluate(() => {
    const el = document.getElementById('toast');
    return { classe: el.className, texto: el.textContent.trim(), temBotao: !!el.querySelector('.anular') };
  });
  log('toast:', JSON.stringify(t));
  ok(t.temBotao, 'o aviso oferece "Anular"');
  ok(/reciclagem/.test(t.texto), 'o aviso explica para onde foi');
  await page.screenshot({ path: OUT + '/toast_anular.png' });

  await page.click('#toast .anular');
  await page.waitForTimeout(900);
  const voltou = await api('convite_list');
  ok(voltou.convites.some(c => c.id == c2.convite.id), 'o botão Anular repõe o convite');

  // limpeza
  await api('convite_delete&id=' + c2.convite.id);
  await api('convite_delete&definitivo=1&id=' + c2.convite.id);

  console.log('\n==== ' + (fails === 0 ? 'ALL PASS' : fails + ' FAIL(S)') + ' ====');
  console.log('ERRORS:', errs.length ? errs.join('\n') : 'none');
  await browser.close();
  process.exit(fails === 0 ? 0 : 1);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
