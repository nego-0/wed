// As fotografias do convite digital, escolhidas na inscrição.
//
// O convite digital tem quatro secções com fotografia, e até aqui o casal só
// podia escolher uma da galeria da casa. Duas coisas estavam erradas nisso:
//
//   1. As secções eram uma lista fixa, e não as da PEÇA DE ORIGEM — o desenho
//      com que o convite dele vai mesmo nascer.
//   2. Quem leva o escalão SEM edição escolhe as fotografias UMA vez na vida:
//      o convite fica assim para sempre. Dar-lhe só a galeria da casa era
//      entregar-lhe um convite feito com fotografias de outro casal, sem
//      remédio possível.
//
// Passa a poder mandar as suas: opcional com edição (troca-as quando quiser),
// obrigatório sem edição. O que volta não é o ficheiro — é uma PROVA com marca
// de água carimbada nos pixéis. E se a licença deixar de trazer o convite
// digital, as fotografias saem do disco: foram enviadas para um convite que
// não vai existir.
//
// O formulário do admin passa a ter a mesma montra que o casal vê: um
// casamento aberto lá dentro deixa de nascer com tudo por omissão.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { execSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
const SOCK = process.env.DB_SOCK || '/run/mysqld/mysqld.sock';
const DB   = process.env.DB_NAME || 'wedding_guests';
const sql  = q => execSync(`mysql -uroot --socket=${SOCK} --default-character-set=utf8mb4 ${DB} -N -e ${JSON.stringify(q)}`).toString().trim();
// Sem --raw, o cliente escapa \t e \n dentro dos valores: um JSON lido assim
// deixa de ser JSON. Serve para ler o desenho de um modelo, que é JSON puro.
const sqlRaw = q => execSync(`mysql -uroot --socket=${SOCK} --default-character-set=utf8mb4 --raw ${DB} -N -e ${JSON.stringify(q)}`).toString().replace(/\n$/, '');
const RAIZ = path.join(__dirname, '..');

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
  const marca = 'rf' + String(Date.now()).slice(-7);

  // Uma fotografia de prova, com tamanho bastante para o convite. Sai da
  // galeria da casa: é uma imagem real, e não um quadrado inventado que o
  // servidor recusaria por ser pequeno de mais.
  const daCasa = fs.readdirSync(path.join(RAIZ, 'assets/convite/galeria'))
                   .filter(n => /\.jpg$/i.test(n))[0];
  const FOTO = path.join(os.tmpdir(), 'chk-foto-' + process.pid + '.jpg');
  fs.copyFileSync(path.join(RAIZ, 'assets/convite/galeria', daCasa), FOTO);

  const enviarFoto = async (p, sec, raiz) => {
    // Uma secção que já tem fotografia mostra «Trocar», e não o botão de enviar.
    if (await p.$(`${raiz} .pl-sec[data-sec="${sec}"] .pl-btn-env`) === null) return;
    const [fc] = await Promise.all([
      p.waitForEvent('filechooser'),
      p.click(`${raiz} .pl-sec[data-sec="${sec}"] .pl-btn-env`),
    ]);
    await fc.setFiles(FOTO);
    await p.waitForFunction(
      s => (window.Planos.fotosProprias() || []).indexOf(s) >= 0, sec, { timeout: 15000 });
  };

  // ---------- 1. as secções são as da peça de origem ----------
  const pub = await (await b.newContext()).newPage();
  pub.on('pageerror', e => errs.push(e.message));
  // O preçário é público: pede-se de uma página pública, sem sessão nenhuma.
  await pub.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  const cat = await pub.evaluate(async () => {
    const r = await fetch('api.php?action=lic_catalogo');
    return r.json();
  });
  const nomeOrigem = sql("SELECT valor FROM cw_definicoes WHERE casamento_id=0 AND chave='modelo.pecaorigem.digital'")
    ? sql("SELECT nome FROM cw_modelos WHERE id=(SELECT valor FROM cw_definicoes WHERE casamento_id=0 AND chave='modelo.pecaorigem.digital')")
    : sql("SELECT nome FROM cw_modelos WHERE ambito='digital' AND criado_por='sistema' ORDER BY id LIMIT 1");
  ok(!!cat.success && (cat.peca_origem || '') === nomeOrigem,
     `o catálogo diz de que peça vêm as secções («${cat.peca_origem}»)`);
  const chaves = (cat.seccoes_foto || []).map(s => s.chave);
  ok(chaves.includes('media.hero') && chaves.includes('media.acesso'),
     'a capa e o acesso pedem-se sempre — são secções que o convite não esconde');
  ok((cat.seccoes_foto || []).every(s => s.origem),
     'e cada uma traz a fotografia com que nasce, para se ver o que se substitui');

  // Uma peça de origem sem interlúdio não pede fotografia de interlúdio: seria
  // pedir uma imagem para uma página que não existe — e, no escalão sem edição,
  // travar a inscrição por causa dela.
  const idOrigem = sql("SELECT id FROM cw_modelos WHERE ambito='digital' AND criado_por='sistema' ORDER BY id LIMIT 1");
  const defsAntes = sqlRaw(`SELECT defs FROM cw_modelos WHERE id=${idOrigem}`);
  const semInter = JSON.parse(defsAntes || '{}');
  semInter['interludio.visivel'] = '0';
  sql(`UPDATE cw_modelos SET defs=${JSON.stringify(JSON.stringify(semInter))} WHERE id=${idOrigem}`);
  sql(`INSERT INTO cw_definicoes (casamento_id,chave,valor) VALUES (0,'modelo.pecaorigem.digital','${idOrigem}')
       ON DUPLICATE KEY UPDATE valor=VALUES(valor)`);
  const cat2 = await pub.evaluate(async () => {
    const r = await fetch('api.php?action=lic_catalogo&x=1');
    return r.json();
  });
  ok(!(cat2.seccoes_foto || []).some(s => s.cat === 'interludio'),
     'uma peça de origem sem interlúdio não pede a fotografia do interlúdio');
  sql(`UPDATE cw_modelos SET defs=${JSON.stringify(defsAntes)} WHERE id=${idOrigem}`);
  sql("DELETE FROM cw_definicoes WHERE casamento_id=0 AND chave='modelo.pecaorigem.digital'");
  await pub.close();

  // ---------- 2. com edição: as fotografias são um adianto ----------
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await p.waitForSelector('#reg-planos .pl-pac', { timeout: 10000 });

  const escolherDigital = async (nome) => {
    const abrir = await p.$('#pl-abrir-medida');
    if (abrir) { await abrir.click(); await p.waitForTimeout(300); }
    await p.evaluate((n) => {
      const i = [...document.querySelectorAll('.pl-esc input')].find(x =>
        x.name === 'pl-digital' && x.closest('.pl-esc').textContent.indexOf(n) >= 0);
      if (i) { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); }
    }, nome);
    await p.waitForTimeout(500);
  };

  await escolherDigital('Padrão, com edição');
  ok(await p.$('.pl-fotos') !== null, 'com o convite digital no plano, pedem-se as fotografias');
  ok(await p.evaluate(() => Planos.faltamFotos()) === null,
     'com edição não falta nada: o que não vier fica com o desenho da casa');
  ok(await p.$$eval('.pl-fotos .pl-sec-tiras', e => e.length) > 0,
     'e a galeria da casa está lá, como alternativa');

  // ---------- 3. sem edição: a fotografia do casal é obrigatória ----------
  await escolherDigital('Modelo padrão');
  const falta = await p.evaluate(() => Planos.faltamFotos());
  ok(typeof falta === 'string' && /sem edição/.test(falta),
     'sem edição, faltam as fotografias — e diz-se porquê');
  ok(await p.$$eval('.pl-fotos .pl-sec-tiras', e => e.length) === 0,
     'e a galeria da casa desaparece: ali não há alternativa nenhuma a oferecer');
  ok(await p.$$eval('.pl-sec-falta', e => e.length) > 0,
     'cada secção por preencher diz que falta a do casal');

  // ---------- 4. a prova volta com marca de água, e não o ficheiro ----------
  await enviarFoto(p, 'media.hero', '');
  ok((await p.evaluate(() => Planos.fotosProprias())).includes('media.hero'),
     'a fotografia da capa foi enviada');
  const prova = await p.evaluate(async () => {
    const img = document.querySelector('.pl-minha img');
    const r = await fetch(img.getAttribute('src'));
    const buf = new Uint8Array(await r.arrayBuffer());
    return { tipo: r.headers.get('content-type'), bytes: buf.length,
             jpeg: buf[0] === 0xFF && buf[1] === 0xD8 };
  });
  const tamanhoOriginal = fs.statSync(FOTO).size;
  ok(prova.jpeg && /image\/jpeg/.test(prova.tipo || ''), 'a pré-visualização é uma imagem servida pelo servidor');
  ok(prova.bytes !== tamanhoOriginal,
     `e não é o ficheiro que se enviou (${prova.bytes} contra ${tamanhoOriginal} bytes) — vai carimbada`);
  await p.click('.pl-sec[data-sec="media.hero"] .pl-btn-ver');
  await p.waitForTimeout(400);
  ok(await p.$eval('#pl-prova', e => e.classList.contains('on')),
     '«ver maior» abre a prova, para se ver o enquadramento');
  await p.click('#pl-prova button'); await p.waitForTimeout(200);

  // ---------- 5. largar o convite digital larga as fotografias ----------
  // «Não levar» é a escolha que tira o módulo do plano — é o gesto que o casal
  // tem à mão, e é por ele que isto se prova.
  await p.evaluate(() => {
    const i = [...document.querySelectorAll('.pl-esc input[name="pl-digital"]')]
      .find(x => x.value === '0');
    if (i) { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); }
  });
  await p.waitForTimeout(1200);
  ok(await p.$('.pl-fotos') === null,
     'sem convite digital no plano, as fotografias deixam de se pedir');
  ok((await p.evaluate(() => Planos.fotosProprias())).length === 0,
     'tirar o convite digital do plano larga as fotografias que iam para ele');

  // ---------- 6. a inscrição não passa sem elas, e passa com elas ----------
  await escolherDigital('Modelo padrão');
  await p.fill('#noiva', 'Ana ' + marca); await p.fill('#noivo', 'Beto ' + marca);
  await p.fill('#email', marca + '@exemplo.ao');
  await p.fill('#senha', 'Segredo123!'); await p.fill('#confirmar', 'Segredo123!');
  await p.fill('#convidados', '120'); await p.fill('#whatsapp', '912345678');
  await p.evaluate(() => { document.getElementById('reg-aceite').checked = true; });
  await p.evaluate(() => enviar()); await p.waitForTimeout(600);
  ok(!(await p.locator('#obrigado').isVisible()),
     'sem as fotografias, a inscrição sem edição não avança');
  ok(/sem edição/.test(await p.textContent('#erro-fim')),
     'e o aviso diz que é o escalão que as exige');

  for (const sc of await p.$$eval('.pl-sec', e => e.map(x => x.dataset.sec))) {
    await enviarFoto(p, sc, '');
  }
  ok(await p.evaluate(() => Planos.faltamFotos()) === null, 'enviadas todas, já não falta nenhuma');
  await p.evaluate(() => { document.getElementById('reg-aceite').checked = true; });
  await p.evaluate(() => enviar());
  await p.waitForSelector('#obrigado:visible', { timeout: 15000 });
  ok(true, 'e a inscrição entra na fila');

  // ---------- 7. as fotografias estão no convite do casal ----------
  const cid = parseInt(sql(`SELECT id FROM cw_casamentos WHERE noiva='Ana ${marca}' LIMIT 1`), 10);
  ok(cid > 0, 'o casamento foi criado');
  const linhas = sql(`SELECT chave, valor FROM cw_definicoes WHERE casamento_id=${cid} AND chave LIKE 'media.%'`)
    .split('\n').filter(Boolean).map(l => l.split('\t'));
  const quantas = (cat.seccoes_foto || []).length;
  ok(linhas.length === quantas,
     `o convite do casal ficou com as ${quantas} fotografias dele (${linhas.length})`);
  ok(linhas.every(([, v]) => v.startsWith('assets/convite/licenca/')),
     'guardadas onde se sabe que vieram com a licença — e não misturadas com as do editor');
  ok(linhas.every(([, v]) => fs.existsSync(path.join(RAIZ, v))),
     'e os ficheiros estão mesmo no disco');
  // A área de espera ficou limpa: uma fotografia entregue não se entrega duas vezes.
  ok(!fs.readdirSync(path.join(RAIZ, 'assets/convite/espera'))
       .some(n => !n.startsWith('.') && fs.statSync(path.join(RAIZ, 'assets/convite/espera', n)).mtimeMs > Date.now() - 60000),
     'e a área de espera ficou sem nada desta inscrição');

  // ---------- 8. tirar o convite digital da licença apaga-as ----------
  const adm = await entrar(await b.newContext(), 'admin', 'noivos2026');
  adm.on('pageerror', e => errs.push(e.message));
  const catAdm = await adm._api('lic_catalogo');
  const soConvidados = catAdm.catalogo.modulos.find(m => m.chave === 'convidados').escaloes[0].id;
  const antesFich = linhas.map(([, v]) => path.join(RAIZ, v));
  await adm._api('lic_conceder', { casamento: cid, escaloes: [soConvidados], meses: 12 });
  await adm.waitForTimeout(400);
  ok(sql(`SELECT COUNT(*) FROM cw_definicoes WHERE casamento_id=${cid} AND chave LIKE 'media.%'`) === '0',
     'tirar o convite digital devolve as secções ao desenho de origem');
  ok(antesFich.every(f => !fs.existsSync(f)),
     'e as fotografias saem do disco — foram enviadas para um convite que já não existe');

  // ---------- 9. o formulário do admin tem a mesma montra ----------
  await adm.goto(BASE + '/plataforma.php', { waitUntil: 'networkidle' });
  await adm.click('.chip[data-vista="novo"]');
  await adm.waitForSelector('#n-planos .pl-conta', { timeout: 10000 });
  ok(await adm.$('#n-planos .pl-pac') !== null,
     'o formulário do admin mostra os mesmos pacotes que o casal vê');
  ok(await adm.$eval('#n-licenca-manual', e => e.style.display) === 'none',
     'e o prazo passa a ser o do plano, em vez de um select ao lado a dizer outra coisa');

  await adm.evaluate(() => {
    const a = document.querySelector('#n-planos #pl-abrir-medida'); if (a) a.click();
  });
  await adm.waitForTimeout(300);
  await adm.evaluate(() => {
    const i = [...document.querySelectorAll('#n-planos .pl-esc input')].find(x =>
      x.name === 'pl-digital' && x.closest('.pl-esc').textContent.indexOf('Padrão, com edição') >= 0);
    if (i) { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); }
  });
  await adm.waitForTimeout(500);
  ok(await adm.$('#n-planos .pl-fotos') !== null,
     'e pede as fotografias do convite digital, como o formulário público');
  ok(await adm.$eval('#vista-novo .bloco-porteiro', e => e.style.display) === 'none',
     'sem o «Controlo à porta» no plano, a conta de porteiro sai do formulário');

  await enviarFoto(adm, 'media.hero', '#n-planos');
  await adm.evaluate((n) => {
    document.getElementById('n-noiva').value = 'Carla ' + n;
    document.getElementById('n-noivo').value = 'Dinis ' + n;
  }, marca);
  await adm.evaluate(() => criar());
  await adm.waitForTimeout(2500);
  const cid2 = parseInt(sql(`SELECT id FROM cw_casamentos WHERE noiva='Carla ${marca}' LIMIT 1`), 10);
  ok(cid2 > 0, 'o casamento do admin foi criado');
  const mods = sql(`SELECT modulo_chave FROM cw_lic_concessoes WHERE casamento_id=${cid2} ORDER BY modulo_chave`)
    .split('\n').filter(Boolean);
  ok(mods.length === 2 && mods.includes('digital'),
     `nasce com o que se lhe escolheu, e não com tudo (${mods.join(', ')})`);
  const foto2 = sql(`SELECT valor FROM cw_definicoes WHERE casamento_id=${cid2} AND chave='media.hero'`);
  ok(foto2.startsWith('assets/convite/licenca/') && fs.existsSync(path.join(RAIZ, foto2)),
     'e a fotografia que o admin enviou está no convite dele');

  // ---------- limpeza ----------
  for (const c of [cid, cid2]) {
    for (const v of sql(`SELECT valor FROM cw_definicoes WHERE casamento_id=${c} AND chave LIKE 'media.%'`)
                    .split('\n').filter(Boolean)) {
      const alvo = path.join(RAIZ, v);
      if (v.startsWith('assets/convite/licenca/') && fs.existsSync(alvo)) fs.unlinkSync(alvo);
    }
    sql(`DELETE FROM cw_definicoes WHERE casamento_id=${c}`);
    sql(`DELETE FROM cw_lic_concessoes WHERE casamento_id=${c}`);
    sql(`DELETE FROM cw_lic_pedido_itens WHERE pedido_id IN (SELECT id FROM cw_lic_pedidos WHERE casamento_id=${c})`);
    sql(`DELETE FROM cw_lic_pedidos WHERE casamento_id=${c}`);
    sql(`DELETE FROM cw_orcamento_categorias WHERE casamento_id=${c}`);
    sql(`DELETE FROM cw_utilizadores WHERE id IN (SELECT utilizador_id FROM cw_acessos WHERE casamento_id=${c})`);
    sql(`DELETE FROM cw_acessos WHERE casamento_id=${c}`);
    sql(`DELETE FROM cw_casamentos WHERE id=${c}`);
  }
  fs.unlinkSync(FOTO);

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
