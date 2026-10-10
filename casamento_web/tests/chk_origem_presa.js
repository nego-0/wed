// A peça de origem do casal prende-se quando ele pede a licença.
//
// O casal escolhe o plano a olhar para as capturas de um modelo — é aquilo que
// ele está a comprar. Se o admin passar depois outro modelo a peça de origem
// da casa, o convite dele mudava-se por baixo, sem ninguém lhe dizer nada:
// abria o editor e encontrava outro desenho, outros textos, outras fotografias.
//
// Passa a ficar preso o modelo que a casa tinha NO MOMENTO DO PEDIDO. A escolha
// nova do admin serve quem vier a seguir. Prova-se aqui, com dois casais
// inscritos de um lado e do outro da mudança.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const SENHA = 'Prova-Origem-2026';

const comApi = (p) => {
  p._api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: Object.assign({ 'X-CSRF-Token': window.CSRF || '' },
                             c ? { 'Content-Type': 'application/json' } : {}),
      body: c ? JSON.stringify(c) : undefined });
    try { return await r.json(); } catch (e) { return { success: false, message: 'não-JSON' }; }
  }, { a, c });
  return p;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = String(Date.now()).slice(-6);

  const admin = comApi(await (await b.newContext()).newPage());
  await admin.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await admin.fill('input[name=utilizador]', 'admin'); await admin.fill('input[name=senha]', 'noivos2026');
  await admin.click('button[type=submit]'); await admin.waitForLoadState('networkidle');
  const api = (a, c) => admin._api(a, c);

  /** Inscreve um casal e devolve o id do casamento que nasceu. */
  async function inscrever(nome) {
    const anon = await (await b.newContext()).newPage();
    await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
    await anon.waitForTimeout(1500);
    const email = 'origem-' + nome + marca + '@prova.local';
    const d = await anon.evaluate(async ({ email, SENHA, nome, marca }) => {
      const cat = await (await fetch('api.php?action=lic_catalogo')).json();
      // Um plano à peça com o convite digital — e a lista de convidados, que
      // nenhum plano dispensa. É o digital que faz a montra mostrar o modelo,
      // e é por causa dele que a peça de origem interessa.
      const escs = [];
      for (const m of cat.catalogo.modulos) {
        if (m.chave !== 'digital' && m.chave !== 'convidados') continue;
        const e = m.escaloes.find(x => x.ativo);
        if (e) escs.push(e.id);
      }
      const r = await fetch('api.php?action=registo_publico', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ noiva: nome + marca, noivo: 'Par' + marca, email, senha: SENHA,
          data: '2027-11-20',
          licenca: { pacote: 0, escaloes: escs, meses: 12, aceito: true } })
      });
      return await r.json();
    }, { email, SENHA, nome, marca });
    await anon.close();
    return { id: d && d.casamento, email, pedido: d && d.pedido };
  }

  /** Qual é a peça de origem que ESTE casamento vê. */
  async function origemDe(cid) {
    await api('casamento_abrir&id=' + cid, {});
    const d = await api('versao_lista&ambito=digital');
    const padrao = (d.versoes || []).find(v => v.id === 0);
    return padrao ? padrao.nome : null;
  }

  // ============ o cenário ============
  const mods = await api('modelo_lista&ambito=digital');
  const digitais = (mods.modelos || []).filter(m => m.ambito === 'digital');
  const origemInicial = digitais.find(m => m.de_origem) || digitais[0];
  ok(!!origemInicial, 'a casa tem uma peça de origem para o convite digital: '
     + (origemInicial && origemInicial.nome));

  // Um segundo modelo publicado e para todos, que possa passar a ser a origem.
  let outro = digitais.find(m => m.id !== origemInicial.id && m.visivel && m.alcance === 'todos');
  let criado = 0;
  if (!outro) {
    const c = await api('modelo_criar', { nome: 'ZZ origem alternativa ' + marca,
                                          ambito: 'digital', visivel: true, alcance: 'todos' });
    ok(c.success, 'cria-se um segundo modelo publicado para servir de alternativa');
    criado = c.id;
    const m2 = await api('modelo_lista&ambito=digital');
    outro = (m2.modelos || []).find(x => x.id === criado);
  }
  ok(!!outro && outro.id !== origemInicial.id,
     'há duas peças de origem possíveis: «' + origemInicial.nome + '» e «' + outro.nome + '»');

  // ---- 1. a casa passa «outro» a peça de origem, e o casal A inscreve-se ----
  // Escolhe-se de propósito um modelo que NÃO é o de fábrica: assim, o que o
  // casal A leva só está protegido por ser dele — e é isso que se quer provar.
  const posto = await api('modelo_pecaorigem&ambito=digital&id=' + outro.id, {});
  ok(posto.success, 'a casa passa «' + outro.nome + '» a peça de origem');

  const A = await inscrever('a');
  ok(A.id > 0 && A.pedido > 0,
     'o casal A inscreve-se com um pedido de licença (casamento #' + A.id
       + ', pedido #' + A.pedido + ')');
  ok(await origemDe(A.id) === outro.nome,
     'e a peça de origem dele é a que a casa tinha: «' + outro.nome + '»');

  // ---- 2. o admin volta a mudar a peça de origem da casa ----
  await api('casamento_fechar', {});
  const mudou = await api('modelo_pecaorigem&ambito=digital&id=' + origemInicial.id, {});
  ok(mudou.success && mudou.nome === origemInicial.nome,
     'o admin passa «' + origemInicial.nome + '» a peça de origem da casa');
  ok(mudou.presos >= 1,
     'e a resposta diz quantos casamentos ficam com o que tinham (' + mudou.presos + ')');

  // ---- 3. o casal A continua com o dele ----
  ok(await origemDe(A.id) === outro.nome,
     'o casal A continua com «' + outro.nome + '» — o convite que comprou');

  // ---- 4. o casal B, inscrito depois, leva o novo ----
  await api('casamento_fechar', {});
  const B = await inscrever('b');
  ok(B.id > 0, 'o casal B inscreve-se depois da mudança (casamento #' + B.id + ')');
  ok(await origemDe(B.id) === origemInicial.nome,
     'e esse leva a peça de origem nova: «' + origemInicial.nome + '»');

  // ---- 5. o modelo que um casal leva não se apaga por engano ----
  // «outro» já não é a peça de origem da casa nem o modelo de fábrica: a única
  // coisa que o segura é o casal A.
  await api('casamento_fechar', {});
  const apagar = await api('modelo_apagar&id=' + outro.id, {});
  ok(!apagar.success && /peça de origem de \d+ casamento/i.test(apagar.message || ''),
     'e o modelo que o casal A leva não se pode apagar: ' + apagar.message);

  // ---- 6. arrumar ----
  // Apagar exige arquivar primeiro — e é bom que exija.
  for (const c of [A, B]) if (c.id) {
    await api('casamento_estado&id=' + c.id + '&estado=arquivado', {});
    await api('casamento_apagar&id=' + c.id, {});
  }
  await api('modelo_pecaorigem&ambito=digital&id=' + origemInicial.id, {});
  if (criado) await api('modelo_apagar&id=' + criado, {});
  const sobra = await api('modelo_lista&ambito=digital');
  ok(!(sobra.modelos || []).some(m => m.id === criado && criado),
     'a prova arruma o que criou');

  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
