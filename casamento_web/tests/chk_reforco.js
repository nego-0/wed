// O reforço de licença: quanto custa, e o que se pode desmarcar.
//
// Duas coisas que estavam mal, e que aqui se prendem por números:
//
//   1. A DIFERENÇA A PAGAR estava errada. Quem tinha «até 80 convidados» e
//      queria «até 200» era cobrado pelos 200 inteiros — o preço do escalão
//      novo, como se a lista não estivesse já paga. A página prometia, nessas
//      mesmas palavras, que «paga só a diferença»; a conta dizia outra coisa.
//      Passa a descontar-se o escalão em vigor.
//
//   2. NÃO SE PODIA DESMARCAR nada. O módulo obrigatório (a lista de
//      convidados) não tinha «Não levar» — o que faz sentido num pedido novo,
//      onde é mesmo obrigatório, mas não num reforço, onde o casal JÁ o tem.
//      Marcava-o por engano e ficava preso a pagar um degrau que não queria.
//
// Prova-se pelo dinheiro e pelo estado, e não pelo aspecto.
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
  const marca = 'rf' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  admin.on('pageerror', e => errs.push(e.message));
  const api = admin._api;

  // ---------- o preçário, para ter números a que agarrar ----------
  const cat = (await api('lic_catalogo')).catalogo;
  const mod = ch => cat.modulos.find(m => m.chave === ch);
  const escal = (ch, ec) => mod(ch).escaloes.find(e => e.chave === ec);

  const c80  = escal('convidados', 'convidados_80');
  const c200 = escal('convidados', 'convidados_200');
  const iPad = escal('impresso', 'impresso_padrao');
  const iEdi = escal('impresso', 'impresso_edicao');
  const porta = escal('porta', 'porta_sim');
  ok(!!(c80 && c200 && iPad && iEdi), 'o preçário traz os escalões de que a prova precisa');
  ok(!!porta, 'e traz o módulo da porta, que passou a ser um módulo à parte');

  // ---------- um casamento com a licença mais baixa ----------
  const email = 'casal.' + marca + '@exemplo.ao';
  let d = await api('casamento_criar', { nome: 'Reforço ' + marca, data: '2027-09-18',
                                         noivos_email: email, noivos_senha: 'senhaforte123' });
  ok(d && d.success, 'criou o casamento de prova');
  const cid = d.id;
  // Dá-se-lhe exactamente o degrau de baixo: 80 convidados e o impresso padrão.
  d = await api('lic_conceder', { casamento: cid, escaloes: [c80.id, iPad.id], meses: 12 });
  ok(d && d.success && d.modulos === 2, 'concedeu-lhe os dois escalões de baixo');

  // ---------- 1. a diferença é o degrau, e não o escalão inteiro ----------
  const casal = await entrar(await b.newContext(), email, 'senhaforte123');
  casal.on('pageerror', e => errs.push('licenca: ' + e.message));
  const apiC = casal._api;

  const est = await apiC('lic_estado');
  const tenho = est.licenca.modulos;
  ok(tenho.convidados.ativo && +tenho.convidados.limite === 80, 'o casal tem os 80 convidados');
  ok(Math.abs(+tenho.convidados.credito - +c80.preco) < 0.5,
     'e o que tem vale, para desconto, o preço do escalão que tem');

  // Pede-se a subida dos dois módulos, no prazo base (factor 1) para a conta
  // se poder verificar à mão.
  const prazoBase = (est.licenca.catalogo.prazos || []).find(p => Math.abs(+p.fator - 1) < 0.001);
  ok(!!prazoBase, 'há um prazo base, de factor 1, sobre o qual a conta se lê');

  d = await apiC('lic_pedir', { pacote: 0, escaloes: [c200.id, iEdi.id],
                                meses: prazoBase.meses, aceito: true, nota: 'subir os dois' });
  ok(d && d.success, 'o casal pede o reforço');

  const ped = d.licenca.pendente;
  const esperado = (+c200.preco - +c80.preco) + (+iEdi.preco - +iPad.preco);
  console.log('   escalões:', c80.preco, '->', c200.preco, '|', iPad.preco, '->', iEdi.preco);
  console.log('   total do pedido:', ped.total, '· esperado:', esperado,
              '· preço cheio seria:', +c200.preco + +iEdi.preco);
  ok(Math.abs(+ped.total - esperado) < 0.5,
     'a diferença a pagar é a soma dos degraus, e não o preço cheio dos escalões novos');
  ok(+ped.total < +c200.preco + +iEdi.preco,
     'e é mesmo menor do que comprar os escalões de novo');

  const itConv = ped.itens.find(i => i.modulo_chave === 'convidados');
  ok(Math.abs(+itConv.preco - (+c200.preco - +c80.preco)) < 0.5,
     'cada linha do pedido traz o seu degrau');
  ok(Math.abs(+itConv.credito - +c80.preco) < 0.5,
     'e guarda o que foi descontado, para o pedido se explicar sozinho');

  // Um módulo que ainda NÃO se tem continua a custar o preço inteiro: o
  // desconto é do que está pago, e não de tudo.
  await apiC('lic_pedido_cancelar', {});
  d = await apiC('lic_pedir', { pacote: 0, escaloes: [porta.id],
                                meses: prazoBase.meses, aceito: true });
  ok(d && d.success, 'pede a porta, que ainda não tem');
  ok(Math.abs(+d.licenca.pendente.total - +porta.preco) < 0.5,
     'um módulo novo custa o preço inteiro — o desconto é só do que já está pago');

  // ---------- 2. desmarcar, na montra, o que se marcou ----------
  await apiC('lic_pedido_cancelar', {});
  await casal.goto(BASE + '/licenca.php', { waitUntil: 'networkidle' });
  await casal.waitForSelector('#lic-planos .pl-esc', { timeout: 8000 });

  // A lista de convidados é módulo obrigatório. Num pedido novo não tem como
  // se dispensar; aqui, que o casal já a tem, tem de ter.
  const temNaoLevar = await casal.evaluate(() => {
    const r = document.querySelector('#lic-planos input[name="pl-convidados"][value="0"]');
    return !!r;
  });
  ok(temNaoLevar, 'no reforço, o módulo obrigatório também se pode deixar de fora');

  // Marca-se um escalão, e depois desmarca-se — e a conta tem de voltar a zero.
  await casal.evaluate((id) => {
    const r = document.querySelector('#lic-planos input[name="pl-convidados"][value="' + id + '"]');
    r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
  }, c200.id);
  await casal.waitForTimeout(300);
  const comEscolha = await casal.evaluate(() => Planos.escolha().total);
  ok(comEscolha > 0, 'marcar um escalão põe-lhe o degrau na conta');

  await casal.evaluate(() => {
    const r = document.querySelector('#lic-planos input[name="pl-convidados"][value="0"]');
    r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await casal.waitForTimeout(300);
  const dep = await casal.evaluate(() => ({ total: Planos.escolha().total,
                                            vazio: Planos.escolha().vazio }));
  ok(dep.total === 0 && dep.vazio,
     'e desmarcá-lo tira-o da conta — o casal não fica preso ao que marcou por engano');

  // O número que a montra mostra é o que o servidor cobra: se divergirem, o
  // casal está a decidir com um preço que não é o dele.
  await casal.evaluate((id) => {
    const r = document.querySelector('#lic-planos input[name="pl-convidados"][value="' + id + '"]');
    r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
  }, c200.id);
  await casal.waitForTimeout(300);
  const noEcra = await casal.evaluate(() => Planos.escolha());
  d = await apiC('lic_pedir', { pacote: 0, escaloes: noEcra.escaloes,
                                meses: noEcra.meses, aceito: true });
  console.log('   ecrã:', noEcra.total, '· servidor:', d.licenca.pendente.total);
  ok(Math.abs(+d.licenca.pendente.total - +noEcra.total) < 0.5,
     'o total da montra é o mesmo que o servidor regista');

  // ---------- limpeza ----------
  await api('casamento_estado&id=' + cid + '&estado=arquivado', {});
  await api('casamento_apagar&id=' + cid, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
