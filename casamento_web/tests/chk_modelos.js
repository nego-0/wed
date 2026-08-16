// Modelos de convite: os desenhos que a casa oferece a todos.
//
// Um modelo é uma FOTOGRAFIA de um convite a sério — abre-se um casamento,
// desenha-se lá, e guarda-se. Aplicá-lo COPIA-O para o casamento do casal, e é
// aí que está o que interessa provar:
//
//   1. o modelo nasce do que o casamento aberto mostra agora;
//   2. um casal aplica-o e o seu convite passa a ser assim;
//   3. depois disso o desenho é DELE — mexer no modelo não lhe toca, e apagar
//      o modelo também não;
//   4. um modelo do cartão não mexe no convite digital, nem o contrário;
//   5. por publicar, não se vê nem se aplica;
//   6. os modelos levam-se e trazem-se num ficheiro;
//   7. só quem responde pela casa os faz.
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
  g._baixar = (q) => g.evaluate(async (q) => (await fetch('api.php?action=' + q)).json(), q);
  return g;
};

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const marca = 'zz' + String(Date.now()).slice(-6);

  const admin = await entrar(await b.newContext(), 'admin', 'noivos2026');
  const api = admin._api;

  // ---------- 1. o modelo faz-se de um convite a sério ----------
  const oficina = await api('casamento_criar', { nome: 'ZZ Oficina ' + marca, noiva: 'Olga', noivo: 'Otto' });
  await api('casamento_abrir&id=' + oficina.id);
  await api('defs_save', { defs: { 'textos.kicker': 'Marca do modelo ' + marca,
                                   'textos.hero_sub': 'Sub do modelo ' + marca,
                                   'cartao.abertura': 'Cartao do modelo ' + marca } });

  const mod = await api('modelo_criar', { nome: 'ZZ Modelo digital ' + marca,
                                          descricao: 'Feito na prova', ambito: 'digital', visivel: true });
  console.log('   modelo:', JSON.stringify(mod));
  ok(mod && mod.success && mod.definicoes > 10,
     'o modelo nasce do convite do casamento aberto, com as suas definições');

  const modCartao = await api('modelo_criar', { nome: 'ZZ Modelo impresso ' + marca,
                                                ambito: 'impresso', visivel: true });
  ok(modCartao && modCartao.success, 'e faz-se o mesmo para o convite impresso');

  // ---------- 2. um casal aplica-o ----------
  const casal = await api('casamento_criar', { nome: 'ZZ Casal ' + marca, noiva: 'Pia', noivo: 'Pedro' });
  await api('casamento_abrir&id=' + casal.id);
  const antes = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(!antes['textos.kicker'], 'o casal começa sem desenho próprio nenhum');

  const aplicou = await api('modelo_aplicar&id=' + mod.id);
  console.log('   aplicar:', JSON.stringify(aplicou).slice(0, 120));
  ok(aplicou && aplicou.success, 'o casal aplica o modelo');

  // ---------- 11. o modelo é o DESENHO, não a identidade de quem o compôs ----
  // A oficina chama-se Olga & Otto. Um modelo feito lá levava o nome deles, a
  // data, a morada e as fotografias — e aplicá-lo rebatizava o casal que o
  // usasse. Era por isso que os modelos da casa não serviam a ninguém.
  const semDono = ((await admin._baixar('modelos_exportar')).modelos || [])
                     .find(x => x.nome === 'ZZ Modelo digital ' + marca) || { defs: {} };
  ok(semDono.defs['casal.noiva'] !== 'Olga' && semDono.defs['casal.noivo'] !== 'Otto',
     `o modelo não guarda o nome do casal onde foi composto (${semDono.defs['casal.noiva']} & ${semDono.defs['casal.noivo']})`);
  ok(/generico-hero/.test(String(semDono.defs['media.hero'] || '')),
     `nem as fotografias dele: nasce com a imagem de exemplo (${semDono.defs['media.hero']})`);
  // O mesmo para o cartão: o casal e a data são o corpo dele, e sem os guardar
  // a sua prova caía no casal de origem.
  const semDonoCartao = ((await admin._baixar('modelos_exportar')).modelos || [])
                     .find(x => x.nome === 'ZZ Modelo impresso ' + marca) || { defs: {} };
  ok(semDonoCartao.defs['casal.noiva'] === 'Ana' && semDonoCartao.defs['casal.noivo'] === 'Bruno',
     `e o modelo do cartão também nasce com o casal de exemplo (${semDonoCartao.defs['casal.noiva']} & ${semDonoCartao.defs['casal.noivo']})`);

  const idApos = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(!idApos['casal.noiva'] && !idApos['casal.noivo'],
     'e aplicá-lo não escreve nome nenhum no casal que o usou');
  ok(!idApos['media.hero'], 'nem lhe troca as fotografias');

  // A prova do modelo mostra um casal de exemplo, e não o da oficina.
  const provaHtml = await admin.evaluate(async (id) =>
    await (await fetch('convite-digital.php?c=EXEMPLO&demo=1&prova=1&modelo=' + id)).text(), mod.id);
  ok(!/Olga/.test(provaHtml) && /Ana/.test(provaHtml),
     'a prova do modelo mostra o casal de exemplo, não o da oficina');
  ok(/generico-hero/.test(provaHtml) && !/convite\/hero\.jpg/.test(provaHtml),
     'e as imagens dela são as de exemplo, não fotografias de ninguém');

  const depois = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(depois['textos.kicker'] === 'Marca do modelo ' + marca,
     'e o seu convite passa a ser o do modelo');

  // ---------- 4. cada peça no seu lugar ----------
  ok(!depois['cartao.abertura'],
     'um modelo do convite digital não mexe no cartão impresso');
  await api('modelo_aplicar&id=' + modCartao.id);
  const comCartao = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(comCartao['cartao.abertura'] === 'Cartao do modelo ' + marca, 'e o do cartão mexe no cartão');

  // ---------- 8. cerimónias no modelo do cartão: só para desenhar ----------
  // O admin pode marcar cerimónias no modelo do cartão (é o que dá corpo à
  // prova), mas são de EXEMPLO: aplicar o modelo aplica o desenho e NÃO reescreve
  // as cerimónias que o casal já tenha. É o pedido — o recurso em todos os
  // editores, incluindo o do admin —, sem apagar dados de ninguém.
  // modelo_defs GRAVA o conjunto que recebe: mantém-se o desenho do cartão
  // (cartao.abertura) além das cerimónias, senão apagava-se o que a secção 1
  // lhe pôs — e a secção 6 conta com ele no ficheiro exportado.
  await api('modelo_defs&id=' + modCartao.id, { defs: {
    'cartao.abertura': 'Cartao do modelo ' + marca,
    'evento.civil_hora': '11:45', 'evento.civil_local': 'Sala do Modelo',
    'evento.religiosa_hora': '16:00', 'evento.religiosa_local': 'Igreja do Modelo' } });
  const fichCer = await admin._baixar('modelos_exportar');
  const guardado = (fichCer.modelos || []).find(m => m.nome === 'ZZ Modelo impresso ' + marca);
  ok(guardado && guardado.defs && guardado.defs['evento.civil_hora'] === '11:45'
       && guardado.defs['evento.religiosa_local'] === 'Igreja do Modelo',
     'o modelo do cartão guarda mesmo as cerimónias que o admin lhe põe');

  await api('casamento_abrir&id=' + casal.id);
  await api('defs_save', { defs: { 'evento.civil_hora': '09:15', 'evento.civil_local': 'Casa do Casal' } });
  await api('modelo_aplicar&id=' + modCartao.id);
  const cerCasal = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  console.log('   cerimónias do casal após aplicar o modelo:',
    cerCasal['evento.civil_hora'], '/', cerCasal['evento.civil_local']);
  ok(cerCasal['evento.civil_hora'] === '09:15' && cerCasal['evento.civil_local'] === 'Casa do Casal',
     'aplicar o modelo NÃO reescreve as cerimónias do casal — o desenho aplica-se, a festa é dele');
  ok(cerCasal['evento.religiosa_local'] !== 'Igreja do Modelo',
     'e a cerimónia de exemplo do modelo não passou para o casal');

  // ---------- 3. depois de aplicado, o desenho é do casal ----------
  await api('casamento_abrir&id=' + oficina.id);
  await api('defs_save', { defs: { 'textos.kicker': 'MUDOU na oficina ' + marca } });
  await api('modelo_editar', { id: mod.id, nome: 'ZZ Modelo digital ' + marca, recapturar: true });
  await api('casamento_abrir&id=' + casal.id);
  const intocado = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  console.log('   no casal depois de mexer no modelo:', intocado['textos.kicker']);
  ok(intocado['textos.kicker'] === 'Marca do modelo ' + marca,
     'mexer no modelo NÃO toca em quem já o aplicou — o desenho passou a ser dele');

  await api('modelo_apagar&id=' + mod.id);
  const semModelo = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(semModelo['textos.kicker'] === 'Marca do modelo ' + marca,
     'e apagar o modelo também não — quem o usou fica como está');

  // ---------- 5. por publicar, não se vê nem se aplica ----------
  await api('casamento_abrir&id=' + oficina.id);
  const rascunho = await api('modelo_criar', { nome: 'ZZ Rascunho ' + marca,
                                               ambito: 'digital', visivel: false });
  const emailC = 'modelos.' + marca + '@exemplo.pt';
  await api('utilizador_criar', { email: emailC, nome: 'Casal Modelos', senha: 'segredo12345',
                                  casamento_id: casal.id, papel: 'noivos' });
  const noivos = await entrar(await b.newContext(), emailC, 'segredo12345');
  const vistos = (await noivos._api('modelo_lista&ambito=digital')).modelos || [];
  const nomes = vistos.map(m => m.nome);
  console.log('   modelos que o casal vê:', JSON.stringify(nomes));
  ok(!nomes.includes('ZZ Rascunho ' + marca), 'um modelo por publicar não aparece ao casal');
  const tentaRascunho = await noivos._api('modelo_aplicar&id=' + rascunho.id);
  ok(tentaRascunho && tentaRascunho.success === false, 'nem se aplica escrevendo-lhe o número');

  // ---------- 7. só a casa faz modelos ----------
  const tentaCriar = await noivos._api('modelo_criar', { nome: 'ZZ Do casal', ambito: 'digital' });
  ok(tentaCriar && tentaCriar.success === false, 'um casal não faz modelos');
  const tentaApagar = await noivos._api('modelo_apagar&id=' + modCartao.id);
  ok(tentaApagar && tentaApagar.success === false, 'nem os apaga');

  // Mas usa os publicados — que é a razão de existirem.
  const usa = await noivos._api('modelo_aplicar&id=' + modCartao.id);
  ok(usa && usa.success, 'e usa os que estão publicados');

  // ---------- 10. um modelo pode ver-se só em certos casamentos ----------
  // Restrito a OUTRO casamento: este casal deixa de o ver e de o aplicar.
  await api('modelo_visibilidade', { id: modCartao.id, alcance: 'selecionados', casamentos: [oficina.id] });
  const soOutro = ((await noivos._api('modelo_lista&ambito=impresso')).modelos || []).map(m => +m.id);
  ok(!soOutro.includes(+modCartao.id),
     'um modelo destinado a outro casamento não aparece a este casal');
  const negado = await noivos._api('modelo_aplicar&id=' + modCartao.id);
  ok(negado && negado.success === false,
     'e o casal não o aplica, mesmo escrevendo-lhe o número');

  // Destinado a ELE: volta a vê-lo e a poder aplicá-lo.
  await api('modelo_visibilidade', { id: modCartao.id, alcance: 'selecionados', casamentos: [casal.id] });
  const comEle = ((await noivos._api('modelo_lista&ambito=impresso')).modelos || []).map(m => +m.id);
  ok(comEle.includes(+modCartao.id), 'destinado a este casamento, o casal já o vê');
  ok((await noivos._api('modelo_aplicar&id=' + modCartao.id)).success, 'e aplica-o');

  // Escolhidos sem escolher ninguém não faz sentido: normaliza-se para "todos".
  const semNinguem = await api('modelo_visibilidade', { id: modCartao.id, alcance: 'selecionados', casamentos: [] });
  ok(semNinguem && semNinguem.alcance === 'todos',
     'escolhidos sem ninguém escolhido volta a ser "todos"');

  // ---------- 6. levar e trazer ----------
  const fich = await admin._baixar('modelos_exportar');
  console.log('   ficheiro de modelos:', fich.formato, '·', (fich.modelos || []).length, 'modelo(s)');
  ok(fich.formato === 'casamento-web/modelos/1' && fich.modelos.length >= 2,
     'os modelos levam-se num ficheiro');
  ok(fich.modelos.some(m => m.defs && m.defs['cartao.abertura']),
     'e o ficheiro leva mesmo os desenhos, não só os nomes');

  const trazidos = await api('modelos_importar', { ficheiro: fich });
  console.log('   importação:', JSON.stringify(trazidos));
  ok(trazidos && trazidos.success && trazidos.entraram >= 2, 'e trazem-se de volta');
  const mau = await api('modelos_importar', { ficheiro: { formato: 'outra-coisa' } });
  ok(mau && mau.success === false, 'um ficheiro que não é de modelos é recusado');

  // ---------- a página do admin ----------
  await admin.goto(BASE + '/modelos.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(900);
  const txt = await admin.locator('#lista').innerText();
  ok(txt.includes('ZZ Modelo impresso ' + marca), 'a página lista os modelos');
  // innerText devolve o texto RENDERIZADO, e as etiquetas são maiúsculas por CSS.
  ok(/por publicar/i.test(txt), 'e distingue os que ainda não estão publicados');

  // As opções de um modelo abrem numa JANELA, e não dentro do cartão: um cartão
  // da grelha tem ~260px, e a lista de casamentos espremida nessa coluna era
  // ilegível — e esticava a linha inteira, desalinhando os cartões vizinhos.
  const antesAlt = await admin.evaluate(() => document.documentElement.scrollHeight);
  const algum = await admin.evaluate(() => +Object.keys(MODELOS)[0]);
  await admin.evaluate((id) => quemVe(id), algum);
  await admin.waitForTimeout(800);
  const jan = await admin.evaluate(() => {
    const o = document.getElementById('ov-modelo');
    const corpo = document.getElementById('ov-corpo');
    return { aberta: o.classList.contains('aberto'),
             largura: Math.round(corpo.getBoundingClientRect().width),
             alt: document.documentElement.scrollHeight };
  });
  ok(jan.aberta, 'as opções do modelo abrem numa janela própria');
  ok(jan.largura > 400, `com largura para se ler (${jan.largura}px, e não a coluna do cartão)`);
  ok(jan.alt === antesAlt, 'e a grelha dos modelos não se mexe por baixo dela');
  await admin.evaluate(() => fechar('ov-modelo'));

  // ---------- 9. os modelos de origem da casa constam da lista ----------
  // O desenho que o sistema traz — o impresso e o digital — está na lista desde
  // o início, um por peça, sem o admin ter de o criar. (No fim, porque aplicar
  // o de origem mexe no casal, e as secções acima contam com o que ele tinha.)
  const listaCasa = (await api('modelo_lista')).modelos || [];
  const daCasa = listaCasa.filter(m => m.criado_por === 'sistema');
  ok(daCasa.some(m => m.ambito === 'impresso') && daCasa.some(m => m.ambito === 'digital'),
     'a lista traz o modelo de origem do impresso e do digital (' + daCasa.map(m => m.ambito).join(', ') + ')');
  ok(daCasa.every(m => +m.visivel === 1), 'e vêm publicados, prontos a usar');
  const origDig = daCasa.find(m => m.ambito === 'digital');
  await api('casamento_abrir&id=' + casal.id);
  await api('defs_save', { defs: { 'textos.kicker': 'CUSTOM ' + marca } });
  await api('modelo_aplicar&id=' + origDig.id);
  const voltou = (await admin._baixar('dados_exportar&ambito=casamento')).casamentos[0].definicoes;
  ok(!voltou['textos.kicker'],
     'aplicar o modelo de origem devolve a peça ao desenho da casa, mesmo já customizada');

  // ---------- 12. os dados de exemplo dos modelos novos ----------
  // O admin escolhe com que casal e que imagens um modelo NOVO nasce. Mexer
  // neles não pode tocar num modelo já feito — nem no convite de origem, que é
  // o produto e não um exemplo.
  const exAntes = await api('modelo_exemplo');
  ok(exAntes && exAntes.success && exAntes.exemplo['casal.noiva'],
     `os dados de exemplo leem-se (${exAntes.exemplo['casal.noiva']} & ${exAntes.exemplo['casal.noivo']})`);
  ok(/generico-/.test(exAntes.fabrica['media.hero']),
     'e de fábrica as imagens são desenho da casa, não fotografias');

  // A identidade INTEIRA está lá para preencher. Metade dos campos faltava.
  const faltam = ['casal.noiva','casal.noivo','evento.data','evento.hora','evento.convidados',
                  'evento.whatsapp','evento.venue_titulo','evento.local','evento.cidade','evento.maps',
                  'evento.civil_titulo','evento.civil_hora','evento.civil_local','evento.civil_maps',
                  'evento.religiosa_titulo','evento.religiosa_hora','evento.religiosa_local',
                  'evento.religiosa_maps','media.hero','media.historia','media.interludio',
                  'media.acesso','media.musica','foto.hero','foto.interludio','foto.acesso']
                 .filter(k => !(exAntes.chaves || []).includes(k));
  ok(!faltam.length, 'e são a identidade inteira do convite' + (faltam.length ? ' — falta ' + faltam.join(', ') : ''));

  // A página mostra um campo por cada uma delas — não bastam existir no servidor.
  await admin.goto(BASE + '/modelos.php', { waitUntil: 'networkidle' });
  await admin.waitForTimeout(1200);
  const semCampo = await admin.evaluate(() => EX_CHAVES.filter(k =>
    k.startsWith('media.') ? !document.getElementById('ex-img-' + k)
    : k.startsWith('foto.') ? !document.getElementById('ex-' + k + '-x')
    : !document.getElementById('ex-' + k)));
  ok(Array.isArray(semCampo) && !semCampo.length,
     'e o painel tem um campo para cada uma' + (semCampo && semCampo.length ? ' — falta ' + semCampo.join(', ') : ''));

  // Guardam-se e voltam: as horas, os mapas, o contacto e o enquadramento.
  const cheio = { 'evento.civil_local':'Conservatória de Exemplo',
                  'evento.civil_maps':'https://maps.app.goo.gl/exemplo',
                  'evento.religiosa_hora':'16:00', 'evento.whatsapp':'244 900 000 000',
                  'foto.hero':'40 30 120' };
  await api('modelo_exemplo_guardar', cheio);
  const lido = (await api('modelo_exemplo')).exemplo || {};
  ok(lido['evento.civil_local'] === cheio['evento.civil_local']
     && lido['evento.civil_maps'] === cheio['evento.civil_maps']
     && lido['evento.religiosa_hora'] === '16:00' && lido['foto.hero'] === '40 30 120',
     'e guardam-se todas, enquadramento incluído (' + lido['foto.hero'] + ')');
  ok(lido['evento.whatsapp'] === '244900000000',
     'com a mesma limpeza de sempre — o WhatsApp fica só em dígitos');
  const mapaMau = await api('modelo_exemplo_guardar', { 'evento.civil_maps': 'javascript:alert(1)' });
  ok(mapaMau && mapaMau.success === false, 'e uma ligação que não é https é recusada');
  // Em branco onde branco não é resposta volta ao de fábrica, e não dá erro.
  await api('modelo_exemplo_guardar', { 'casal.noiva': '', 'evento.data': '' });
  const reposto = (await api('modelo_exemplo')).exemplo || {};
  ok(reposto['casal.noiva'] === exAntes.fabrica['casal.noiva']
     && reposto['evento.data'] === exAntes.fabrica['evento.data'],
     'um campo obrigatório deixado em branco volta ao de fábrica');

  // Um modelo feito ANTES da mudança e outro DEPOIS: só o segundo a apanha.
  await api('casamento_abrir&id=' + oficina.id);
  const exAntesMod = await api('modelo_criar', { nome: 'ZZ Exemplo antes ' + marca, ambito: 'digital' });
  await api('modelo_exemplo_guardar', { 'casal.noiva': 'Zita ' + marca, 'casal.noivo': 'Zeca',
                                        'evento.local': 'Salão ' + marca });
  const exDepoisMod = await api('modelo_criar', { nome: 'ZZ Exemplo depois ' + marca, ambito: 'digital' });

  const fichEx = (await admin._baixar('modelos_exportar')).modelos || [];
  const nascido = fichEx.find(x => x.nome === 'ZZ Exemplo depois ' + marca) || { defs: {} };
  ok(nascido.defs['casal.noiva'] === 'Zita ' + marca && nascido.defs['evento.local'] === 'Salão ' + marca,
     `um modelo criado agora nasce com os dados de exemplo em vigor (${nascido.defs['casal.noiva']})`);

  // Do ZERO, e nao do convite do casamento aberto: o desenho de origem e o do
  // primeiro casal, e sem a troca o modelo nascia com o nome e as fotos dele.
  const doZero = await api('modelo_criar', { nome: 'ZZ Exemplo do zero ' + marca,
                                             ambito: 'digital', do_zero: true });
  const nascidoZero = ((await admin._baixar('modelos_exportar')).modelos || [])
                        .find(x => x.nome === 'ZZ Exemplo do zero ' + marca) || { defs: {} };
  ok(nascidoZero.defs['casal.noiva'] === 'Zita ' + marca
     && /generico-hero/.test(String(nascidoZero.defs['media.hero'] || '')),
     `um modelo feito do zero tambem (${nascidoZero.defs['casal.noiva']}, ${nascidoZero.defs['media.hero']})`);
  await api('modelo_apagar&id=' + doZero.id);

  const jaFeito = fichEx.find(x => x.nome === 'ZZ Exemplo antes ' + marca) || { defs: {} };
  ok(jaFeito.defs['casal.noiva'] === exAntes.exemplo['casal.noiva'],
     `e o modelo feito ANTES fica exatamente como estava (${jaFeito.defs['casal.noiva']})`);
  const provaAntes = await admin.evaluate(async (id) =>
    await (await fetch('convite-digital.php?c=EXEMPLO&demo=1&prova=1&modelo=' + id)).text(), exAntesMod.id);
  ok(!new RegExp('Zita ' + marca).test(provaAntes),
     'e a prova dele também — um modelo já feito não se reescreve por baixo de quem o desenhou');

  // O convite de origem é o produto, não um exemplo: continua com as suas imagens.
  const origem = await admin.evaluate(async () =>
    await (await fetch('convite-digital.php?c=EXEMPLO&demo=1&prova=1')).text());
  ok(/convite\/hero\.jpg/.test(origem),
     'e o convite de origem mantém as imagens de sempre — mexeu-se nos modelos, não no produto');

  await api('modelo_exemplo_guardar', exAntes.fabrica);
  const exReposto = await api('modelo_exemplo');
  ok(exReposto.exemplo['casal.noiva'] === exAntes.fabrica['casal.noiva'],
     'e repõem-se os de fábrica');
  for (const m of [exAntesMod, exDepoisMod]) await api('modelo_apagar&id=' + m.id);

  // ---------- limpeza ----------
  const todos = (await api('modelo_lista')).modelos || [];
  for (const m of todos) if (m.nome.includes(marca)) await api('modelo_apagar&id=' + m.id);
  await api('casamento_abrir&id=1');
  for (const id of [oficina.id, casal.id]) {
    await api('casamento_estado&id=' + id + '&estado=arquivado');
    await api('casamento_apagar&id=' + id);
  }
  for (const c of (await api('utilizador_lista&q=' + marca)).contas || []) {
    await api('utilizador_apagar&id=' + c.id);
  }
  ok(((await api('modelo_lista')).modelos || []).filter(m => m.nome.includes(marca)).length === 0,
     'a prova não deixa modelos de mentira na base');

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
