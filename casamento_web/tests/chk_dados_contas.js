// A importação de um casamento religa-lhe as contas dos noivos.
//
// O retrato de um casamento leva, por cada acesso, o email, o nome e o papel —
// as contas dos noivos, do porteiro, de quem serve o bar. E não levava mais
// nada: faltava quem, do outro lado, voltasse a ESCREVER esses acessos. O
// casamento entrava inteiro, mas nada o ligava às suas contas. O casal abria a
// sessão para um painel vazio, e o casamento importado ficava sem dono —
// ninguém o podia gerir.
//
// Duas situações, e as duas se medem aqui:
//
//   1. A CONTA JÁ EXISTE nesta casa (a mesma instalação, a reimportar). Religa-
//      se pelo email, que é a chave de uma pessoa em toda a casa. O número de
//      utilizador é desta base e não sobrevive a uma importação — como tudo o
//      resto do retrato, o laço faz-se pelo nome/email, nunca pelo id.
//
//   2. A CONTA NÃO EXISTE (uma restauração para uma instalação limpa, em que as
//      contas não foram trazidas à parte). Cria-se, com uma senha temporária —
//      a de origem não viaja, e ainda bem —, para a festa não ficar órfã.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const marca = 'zzk' + Math.floor(Math.random() * 1e5);
const emailCasal  = 'casal.'  + marca + '@exemplo.pt';
const emailFresco = 'fresco.' + marca + '@exemplo.pt';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push('admin: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json().catch(() => ({ success: false }));
  }, { a, c });
  const exportar = (id) => p.evaluate(async i => {
    const r = await fetch('api.php?action=dados_exportar&id=' + i);
    return r.json();
  }, id);
  const acessosDe = (dump) => (((dump.casamentos || [])[0] || {}).acessos || []);

  // ============ a casa de origem, com a conta do casal ============
  const A = await api('casamento_criar', { nome: 'ZZK Origem ' + marca,
    noiva: 'Ana', noivo: 'Bento',
    noivos_email: emailCasal, noivos_nome: 'Ana & Bento', noivos_senha: 'segredo123' });
  ok(!!(A.id && A.contas && A.contas.noivos && A.contas.noivos.email === emailCasal),
     'um casamento de origem, com a conta dos noivos ligada: ' + emailCasal);

  const dumpA = await exportar(A.id);
  const acA = acessosDe(dumpA);
  ok(acA.some(x => x.email === emailCasal && x.papel === 'noivos'),
     'o ficheiro leva o acesso do casal, pelo email e pelo papel: ' + JSON.stringify(acA));

  // ============ 1. a conta EXISTE — religa-se ============
  const impB = await api('dados_importar', { ficheiro: dumpA, modo: 'novo' });
  const B = (impB.resumo || [])[0] || {};
  ok(impB.success && B.id && B.id !== A.id,
     'importa como casamento novo: #' + B.id);
  ok(B.acessos === 1 && B.contas_criadas === 0,
     'religa a conta que já existe, sem criar nenhuma: acessos=' + B.acessos
     + ', contas_criadas=' + B.contas_criadas);

  // A prova a sério: re-exporta o casamento importado e vê se o acesso do casal
  // sai de lá. Se saiu, está mesmo escrito na base — e é a mesma conta, pelo
  // email, e não uma cópia solta.
  const dumpB = await exportar(B.id);
  const acB = acessosDe(dumpB);
  ok(acB.some(x => x.email === emailCasal && x.papel === 'noivos'),
     'e o casamento importado devolve o acesso do casal quando se re-exporta: '
     + JSON.stringify(acB));

  // ============ 2. a conta NÃO existe — cria-se ============
  // Troca-se o email do acesso por um que não existe nesta casa: é o caso de
  // uma restauração para uma instalação limpa.
  const dumpFresco = JSON.parse(JSON.stringify(dumpA));
  dumpFresco.casamentos[0].acessos = [{ email: emailFresco, nome: 'Casal Fresco', papel: 'noivos' }];
  const impC = await api('dados_importar', { ficheiro: dumpFresco, modo: 'novo' });
  const C = (impC.resumo || [])[0] || {};
  ok(impC.success && C.acessos === 1 && C.contas_criadas === 1,
     'uma conta que não existe cria-se, para a festa não ficar sem dono: '
     + 'acessos=' + C.acessos + ', contas_criadas=' + C.contas_criadas);

  const dumpC = await exportar(C.id);
  ok(acessosDe(dumpC).some(x => x.email === emailFresco && x.papel === 'noivos'),
     'e o acesso criado fica ligado ao casamento: ' + JSON.stringify(acessosDe(dumpC)));

  // E a conta criada é uma conta a sério — activa, e com o nome que veio no
  // ficheiro. A senha não se confere aqui (é temporária e não viaja); o que
  // importa é que a pessoa EXISTE e pode recuperá-la pela porta do costume.
  const contas = await p.evaluate(async () => {
    const r = await fetch('api.php?action=utilizador_lista&tipo=casamento&q=fresco');
    return r.json().catch(() => ({}));
  });
  const nova = ((contas.contas || [])).find(u => u.email === emailFresco);
  ok(!!(nova && nova.estado === 'ativo'),
     'a conta criada é uma conta activa, e não um registo morto: '
     + (nova ? nova.estado : 'não encontrada'));

  // ---- arrumar: os casamentos apagam-se, e com eles as contas que ficam
  //      órfãs (é o que apagarContasDoCasamento faz). Só se apaga um casamento
  //      ARQUIVADO, e só se arquiva um sem licença em vigor — por isso revoga-se
  //      a licença primeiro (o de origem nasce com uma; os importados não). É a
  //      mesma ordem que a página impõe a quem carrega no botão. ----
  for (const id of [A.id, B.id, C.id]) {
    if (!id) continue;
    await api('lic_revogar', { casamento: id, motivo: 'arrumar a prova' });
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }
  const sobrou = await p.evaluate(async () => {
    const d = await window.api('casamentos');
    return (d.casamentos || []).filter(x => String(x.nome).includes('ZZK ')).map(x => x.nome);
  });
  ok(sobrou.length === 0, 'a prova não deixa casamentos para trás: '
     + (sobrou.join(', ') || 'nenhum'));

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  await b.close();
  console.log(f ? '\n' + f + ' FALHA(S)' : '\nTUDO VERDE');
  process.exit(f ? 1 : 0);
})();
