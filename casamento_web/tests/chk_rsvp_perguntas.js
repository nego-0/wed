// As perguntas da confirmação (docs/auditoria-ui-ux.md §25, RSVP-001).
//
// A resposta a um convite trazia três coisas: se vem, quantos, e um recado.
// O prato, as alergias, a boleia — tudo o que é preciso saber antes do dia —
// ficava para telefonemas um a um, ou para a caixa do recado, de onde ninguém
// tira uma conta. Numa festa de duzentas pessoas isso são duzentos telefonemas
// e nenhum número que se possa dar ao catering.
//
// O que aqui se defende:
//
//   1. O casal faz as SUAS perguntas. Uma casa que servisse a mesma lista a
//      toda a gente estaria a decidir o menu dos outros.
//   2. O prato é de CADA UM. Perguntá-lo uma vez a um convite de quatro dava
//      um prato para quatro pessoas, e é o contrário disso que serve a quem
//      cozinha. Quem não vem não responde: o bloco dele fecha.
//   3. Uma pergunta obrigatória por responder TRAVA o envio. Uma conta de
//      metade da lista entregue como se fosse toda é pior do que conta nenhuma.
//   4. O que vem de fora não escolhe o que se guarda: uma pergunta que não
//      existe, uma opção que não está na lista, ou a pessoa do convite de
//      outro — nada disso entra. O código é a única chave que a porta pública
//      pede, e quem souber o seu não pode responder pelos convidados alheios.
//   5. Uma recusa apaga as respostas. Quem não vem não tem prato, e deixar a
//      resposta antiga lá fazia-a entrar na conta.
//   6. O resumo soma-se sozinho, e diz quantos FALTAM.
//
// A prova faz o seu casamento e a sua gente, e leva-os embora no fim: uma
// pessoa a mais muda a lotação que as outras provas contam.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'pg' + String(Date.now()).slice(-6);

  const ad = await (await b.newContext()).newPage();
  ad.on('pageerror', e => errs.push('admin: ' + e.message));
  await ad.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await ad.fill('input[name=utilizador]', 'admin');
  await ad.fill('input[name=senha]', 'noivos2026');
  await ad.click('button[type=submit]');
  await ad.waitForLoadState('networkidle');
  const api = (a, c) => ad.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });

  // Um casamento só desta prova: as perguntas são do casamento, e escrevê-las
  // no de exemplo mudava o que as outras provas encontram lá.
  let d = await api('casamento_criar', { nome: 'Perguntas ' + marca, data: '2027-05-08',
                                         noivos_email: 'casal.' + marca + '@exemplo.ao',
                                         noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  await api('casamento_abrir&id=' + cid, {});

  // ---------- 1. o casal faz as suas perguntas ----------
  d = await api('rsvp_perguntas_guardar', { perguntas: [
    { chave: 'prato', rotulo: 'O que prefere comer?', tipo: 'escolha',
      opcoes: ['Carne', 'Peixe', 'Vegetariano'], obrigatoria: 1, por_pessoa: 1 },
    { chave: 'alergias', rotulo: 'Alguma alergia?', tipo: 'texto', por_pessoa: 1 },
    { chave: 'boleia', rotulo: 'Precisa de transporte?', tipo: 'sim_nao', por_pessoa: 0 },
    // Uma escolha sem opções não é escolha nenhuma: não deve nascer.
    { chave: 'vazia', rotulo: 'Escolha sem respostas', tipo: 'escolha', opcoes: [] },
  ]});
  ok(d && d.success, 'guardou as perguntas');
  ok(d.perguntas.length === 3,
     'e a «escolha» sem opções não nasceu: ' + d.perguntas.length + ' de 4');
  ok(d.perguntas[0].chave === 'prato' && d.perguntas[0].opcoes.length === 3,
     'a de escolha guarda as suas respostas possíveis');

  // ---------- 2. um convite com duas pessoas ----------
  const cv = await api('convite_save', { nome_exibicao: 'ZZ Casal ' + marca, tipo: 'digital',
    lado: 'noivo', membros: [{ nome: 'ZZ Ana' }, { nome: 'ZZ Bruno' }] });
  ok(cv && cv.success, 'criou o convite de prova');
  const cod = cv.convite.codigo;
  // `convite_save` devolve `membros`, e não `membros_det` — a primeira versão
  // desta prova lia o segundo, ficava com uma lista vazia, e as verificações
  // da adulteração lá em baixo passavam sem testar coisa nenhuma.
  const ids = (cv.convite.membros || []).map(m => m.id);
  ok(ids.length === 2, 'e a prova tem os ids das duas pessoas: ' + ids.join(', '));

  const g = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  g.on('pageerror', e => errs.push('convidado: ' + e.message));
  let aviso = '';
  g.on('dialog', async dl => { aviso = dl.message(); await dl.dismiss(); });
  await g.goto(BASE + '/convite.php?c=' + cod, { waitUntil: 'networkidle' });
  await g.waitForTimeout(700);

  // Estão no HTML, mas dentro do bloco que só abre com o «Vou comparecer» —
  // é a VISTA que conta, e não a presença na página.
  ok(await g.evaluate(() => [...document.querySelectorAll('.r-campo')]
                              .every(e => e.offsetParent === null)),
     'antes de dizer que vai, não se lhe pergunta mais nada');
  await g.click('#op-sim');
  await g.waitForTimeout(400);

  const form = await g.evaluate(() => ({
    pessoas: [...document.querySelectorAll('.r-pessoa .r-quem')].map(e => e.textContent.trim()),
    campos:  [...document.querySelectorAll('.r-campo')].map(e => e.dataset.chave + '@' + e.dataset.quem),
    transbordo: document.documentElement.scrollWidth - innerWidth,
  }));
  ok(form.pessoas.length === 2,
     'o prato pergunta-se a cada um, e cada grupo diz de quem é: ' + form.pessoas.join(' | '));
  ok(form.campos.filter(x => x.startsWith('prato@')).length === 2,
     'duas pessoas, duas perguntas do prato');
  ok(form.campos.filter(x => x === 'boleia@0').length === 1,
     'e a do convite todo pergunta-se uma vez só');
  ok(form.transbordo === 0, 'nada disto dá rolagem horizontal a 390px');

  // ---------- 3. o obrigatório trava ----------
  await g.click('#btn-enviar');
  await g.waitForTimeout(800);
  ok(await g.evaluate(() => !!document.querySelector('.r-campo.falta')),
     'uma pergunta obrigatória por responder trava o envio');
  ok(/Falta responder/.test(aviso), 'e diz qual: ' + aviso.slice(0, 60));
  const est0 = await api('convite_list&busca=ZZ%20Casal%20' + marca);
  ok((est0.convites[0] || {}).rsvp_estado === 'pendente',
     'e nada foi escrito: o convite continua pendente');

  // ---------- 4. quem não vem não responde ----------
  await g.evaluate(() => {
    const cx = document.querySelectorAll('#membros .membro input')[1];
    cx.checked = false; cx.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await g.waitForTimeout(300);
  ok(await g.evaluate(() => document.querySelectorAll('.r-pessoa.off').length === 1),
     'desmarcar alguém fecha-lhe o bloco: não se pede o prato a quem não vai');
  await g.evaluate(() => {
    const cx = document.querySelectorAll('#membros .membro input')[1];
    cx.checked = true; cx.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await g.waitForTimeout(300);

  // ---------- 5. responder e enviar ----------
  await g.evaluate(() => {
    const s = [...document.querySelectorAll('.r-campo[data-chave=prato] select')];
    s[0].value = 'Carne'; s[1].value = 'Peixe';
    const a = document.querySelectorAll('.r-campo[data-chave=alergias] input');
    a[0].value = 'Sem glúten';
    document.querySelector('.r-campo[data-chave=boleia] select').value = 'sim';
  });
  await g.click('#btn-enviar');
  await g.waitForTimeout(1800);
  ok(await g.evaluate(() => document.getElementById('concluido').classList.contains('on')),
     'respondido, o convite confirma-se');

  const res = await api('rsvp_resumo');
  const porChave = {};
  (res.perguntas || []).forEach(p => { porChave[p.chave] = p; });
  ok(res.confirmadas === 2, 'duas pessoas confirmadas: ' + res.confirmadas);
  const prato = porChave.prato || { linhas: [] };
  const conta = {};
  prato.linhas.forEach(l => { conta[l.valor] = l.n; });
  ok(conta.Carne === 1 && conta.Peixe === 1,
     'e o resumo soma-se sozinho: ' + prato.linhas.map(l => l.n + ' ' + l.valor).join(' · '));
  ok((porChave.alergias || {}).faltam === 1,
     'e diz quantos faltam — meia lista entregue como se fosse toda é pior do que nada: '
     + (porChave.alergias || {}).faltam);

  // ---------- 6. o que vem de fora não escolhe o que se guarda ----------
  // Uma opção que não está na lista, uma pergunta que não existe, e a pessoa
  // do convite de outro. Nada disto pode entrar.
  const outro = await api('convite_save', { nome_exibicao: 'ZZ Outro ' + marca,
    tipo: 'digital', lado: 'noiva', membros: [{ nome: 'ZZ Alheio' }] });
  const idAlheio = (outro.convite.membros || [])[0].id;
  ok(!!idAlheio, 'e há uma pessoa de outro convite para tentar responder por ela');
  await g.evaluate(async ({ cod, ids, idAlheio }) => {
    await fetch('api.php?action=rsvp_submit', { method: 'POST', body: JSON.stringify({
      codigo: cod, decisao: 'sim', confirmados: 2,
      membros: ids.map(id => ({ id: id, vai: true })),
      respostas: [
        { chave: 'prato',      convidado: ids[0], valor: 'Lagosta' },     // não está na lista
        { chave: 'inventada',  convidado: ids[0], valor: 'seja o que for' },
        { chave: 'prato',      convidado: idAlheio, valor: 'Carne' },     // gente de outro convite
      ] }) });
  }, { cod, ids, idAlheio });
  await g.waitForTimeout(600);

  const res2 = await api('rsvp_resumo');
  const p2 = (res2.perguntas || []).find(p => p.chave === 'prato') || { linhas: [] };
  const vals = p2.linhas.map(l => l.valor);
  ok(!vals.includes('Lagosta'),
     'uma opção que não está na lista não entra no resumo: ' + vals.join(', '));
  ok(!(res2.perguntas || []).some(p => p.chave === 'inventada'),
     'e uma pergunta que não existe também não');
  ok(p2.linhas.reduce((t, l) => t + l.n, 0) === 2,
     'a gente do convite de outro não recebe resposta pela mão deste: '
     + p2.linhas.reduce((t, l) => t + l.n, 0) + ' respostas para 2 pessoas');

  // ---------- 7. quem recusa não tem prato ----------
  await g.evaluate(async (cod) => {
    await fetch('api.php?action=rsvp_submit', { method: 'POST',
      body: JSON.stringify({ codigo: cod, decisao: 'nao', confirmados: 0, membros: [] }) });
  }, cod);
  await g.waitForTimeout(600);
  const res3 = await api('rsvp_resumo');
  const p3 = (res3.perguntas || []).find(p => p.chave === 'prato') || { linhas: [] };
  ok(p3.linhas.length === 0,
     'recusar apaga as respostas: quem não vem não entra na conta do catering');

  // ---------- arrumar ----------
  // O casamento inteiro sai daqui: as perguntas são dele, e deixá-lo ficar
  // mudava as contas que as outras provas encontram na plataforma. Arquiva-se
  // primeiro, que é a trava que a casa pôs — só se apaga o que já saiu das
  // listas de trabalho.
  // Primeiro a licença. Um casamento criado nasce com ela em vigor, e a casa
  // recusa-se a arquivar por cima disso — tirar ao casal o que ele pagou sem
  // lhe dizer porquê. É a trava certa, e uma prova não a contorna: cumpre-a.
  await api('lic_revogar', { casamento: cid, motivo: 'Casamento de prova automática.' });
  await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  const limpou = await api('casamento_apagar&id=' + cid, {});
  ok(limpou && limpou.success, 'e o casamento de prova sai daqui como entrou');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
