// Levar os dados daqui, e trazê-los de volta.
//
// Os dados de um casamento são do casal. Uma exportação que perca metade pelo
// caminho é pior do que nenhuma, porque dá a ilusão de haver cópia. O que se
// prova aqui é a IDA E VOLTA: o que sai tem de voltar igual.
//
//   1. o casal leva os seus dados num ficheiro;
//   2. trazê-los de volta repõe convites, pessoas, mesas e desenho;
//   3. os códigos que já estão em uso são trocados, e diz-se quantos;
//   4. importar SUBSTITUI — o que lá estava sai;
//   5. o admin leva a casa inteira, e traz casamentos como novos;
//   6. as senhas só vão no ficheiro a pedido;
//   7. um casal não leva os dados de outro, nem a casa inteira.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

const entrar = async (ctx, u, p) => {
  const g = await ctx.newPage();
  await g.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await g.fill('input[name=utilizador]', u); await g.fill('input[name=senha]', p);
  await g.click('button[type=submit]'); await g.waitForLoadState('networkidle');
  g._api = (a, c) => g.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  // O ficheiro chega por navegação, como no navegador de quem carrega no botão.
  g._baixar = (q) => g.evaluate(async (q) => (await fetch('api.php?action=dados_exportar' + q)).json(), q);
  return g;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- um casamento com coisas lá dentro ----------
  const cas = await api('casamento_criar', { nome: 'ZZ Dados ' + marca, noiva: 'Dina', noivo: 'Dario' });
  await api('casamento_abrir&id=' + cas.id);
  await api('mesa_save', { id: 0, nome: 'Mesa das Flores', capacidade: 6, forma: 'redonda', cor: 'ouro' });
  await api('defs_save', { defs: { 'evento.local': 'Quinta ' + marca, 'textos.kicker': 'Marca ' + marca } });
  const c1 = await api('convite_save', { nome_exibicao: 'ZZ Família ' + marca, tipo: 'ambos', lado: 'noiva',
                                         telefone: '244900000001', observacoes: 'nota ' + marca,
                                         membros: ['Ana Um', 'Bruno Dois', 'Carla Três'] });
  const c2 = await api('convite_save', { nome_exibicao: 'ZZ Sozinho ' + marca, tipo: 'digital',
                                         lado: 'noivo', membros: ['Duarte Quatro'] });
  const codOriginal = c1.convite.codigo;
  await api('convite_mesa', { id: c1.convite.id, mesa_id: (await api('mesa_list')).mesas
                                .find(m => m.nome === 'Mesa das Flores').id });
  await api('versao_criar', { nome: 'Versão ' + marca, ambito: 'digital' });

  // ---------- 1. levar ----------
  const fich = await admin._baixar('&ambito=casamento');
  console.log('   ficheiro:', JSON.stringify(fich).length, 'bytes ·', fich.formato);
  ok(fich.formato === 'casamento-web/1', 'o ficheiro diz o que é');
  const r0 = fich.casamentos[0];
  ok(fich.casamentos.length === 1, 'e traz um casamento — o que estava aberto');
  ok(r0.ficha.noiva === 'Dina' && r0.ficha.noivo === 'Dario', 'com a ficha do casal');
  ok(r0.convites.length === 2, 'os dois convites');
  ok(r0.convites.reduce((s, c) => s + c.membros.length, 0) === 4, 'e as quatro pessoas');
  ok(r0.mesas.some(m => m.nome === 'Mesa das Flores'), 'a mesa que se criou');
  ok(r0.definicoes['evento.local'] === 'Quinta ' + marca, 'e o que se mudou no desenho');
  ok(r0.versoes.length === 1, 'a versão guardada também vai');
  // A mesa viaja pelo NOME: um número desta base não quer dizer nada noutra.
  const comMesa = r0.convites.find(c => c.nome_exibicao.includes('Família'));
  ok(comMesa.mesa === 'Mesa das Flores', 'o convite aponta para a mesa pelo nome, não pelo número');

  // ---------- 2 e 3. trazer de volta, como casamento novo ----------
  const trazido = await api('dados_importar', { modo: 'novo', ficheiro: fich });
  console.log('   importação:', JSON.stringify(trazido.resumo));
  ok(trazido && trazido.success, 'o ficheiro volta a entrar');
  const novo = trazido.resumo[0];
  ok(novo.convites === 2 && novo.pessoas === 4 && novo.mesas === 1 && novo.versoes === 1,
     'e traz tudo: convites, pessoas, mesas e versões');
  ok(novo.codigos_trocados === 2,
     'os códigos já em uso são trocados — e diz-se quantos, porque os QR antigos deixam de servir');

  await api('casamento_abrir&id=' + novo.id);
  const listaNova = await api('convite_list');
  const nomes = (listaNova.convites || []).map(c => c.nome_exibicao).sort();
  console.log('   no casamento novo:', JSON.stringify(nomes));
  ok(nomes.length === 2 && nomes[0].includes('Família'), 'os convites estão lá, com os nomes certos');
  const familia = listaNova.convites.find(c => c.nome_exibicao.includes('Família'));
  ok(familia.mesa_nome === 'Mesa das Flores', 'e sentados na mesa certa, remontada pelo nome');
  ok(familia.telefone === '244900000001', 'com o telefone');
  const defsNovas = await admin._baixar('&ambito=casamento');
  ok(defsNovas.casamentos[0].definicoes['evento.local'] === 'Quinta ' + marca,
     'e o desenho do convite veio com eles');

  // ---------- 4. importar substitui ----------
  await api('convite_save', { nome_exibicao: 'ZZ Escrito depois ' + marca, tipo: 'digital',
                              lado: 'ambos', membros: ['Alguém'] });
  ok((await api('convite_list')).convites.length === 3, 'acrescenta-se um convite ao casamento novo');
  const troca = await api('dados_importar', { modo: 'substituir', com_ficha: false, ficheiro: fich });
  ok(troca && troca.success, 'importa-se por cima');
  const depois = (await api('convite_list')).convites.map(c => c.nome_exibicao);
  console.log('   depois de substituir:', JSON.stringify(depois));
  ok(depois.length === 2 && !depois.some(n => n.includes('Escrito depois')),
     'substituir tira o que lá estava — não é uma junção');

  // ---------- 5. a casa inteira ----------
  const casa = await admin._baixar('&ambito=sistema');
  console.log('   casa:', casa.casamentos.length, 'casamento(s) ·', (casa.contas || []).length, 'conta(s)');
  ok(casa.ambito === 'sistema' && casa.casamentos.length >= 2, 'o admin leva todos os casamentos');
  ok(Array.isArray(casa.contas) && casa.contas.length >= 2, 'e a lista de contas');

  // ---------- 6. as senhas só a pedido ----------
  ok(!casa.contas.some(c => 'senha_hash' in c), 'sem pedir, as senhas NÃO vão no ficheiro');
  const casaSenhas = await admin._baixar('&ambito=sistema&senhas=1');
  ok(casaSenhas.contas.every(c => 'senha_hash' in c), 'a pedido, vão — é o que faz dela uma cópia de segurança');

  // ---------- 7. cada um leva só o que é seu ----------
  const emailA = 'dados.' + marca + '@exemplo.pt';
  await api('utilizador_criar', { email: emailA, nome: 'Noivos Dados', senha: 'segredo12345',
                                  casamento_id: cas.id, papel: 'noivos' });
  const casal = await entrar(await b.newContext(), emailA, 'segredo12345');
  const seu = await casal._baixar('&ambito=casamento');
  ok(seu.casamentos.length === 1 && seu.casamentos[0].ficha.noiva === 'Dina',
     'o casal leva o seu casamento');
  const tudo = await casal._baixar('&ambito=sistema');
  console.log('   casal a pedir a casa inteira:', JSON.stringify(tudo).slice(0, 90));
  ok(tudo && tudo.success === false, 'e não consegue levar a casa inteira');
  const tentaNovo = await casal._api('dados_importar', { modo: 'novo', ficheiro: fich });
  ok(tentaNovo && tentaNovo.success === false, 'nem criar casamentos a partir de um ficheiro');

  // ---------- limpeza ----------
  await api('casamento_abrir&id=1');
  for (const id of [cas.id, novo.id]) {
    // Apagar exige arquivar antes — o mesmo caminho que a página faz.
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }
  for (const c of (await api('utilizador_lista&q=' + marca)).contas || []) {
    await api('utilizador_apagar&id=' + c.id);
  }

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
