// A carta do convidado veste o tema que o convidado escolher.
//
// O botão do tema existe nesta página — e é aqui que ele faz mais falta que
// em qualquer outra: um telemóvel na mão, de noite, no meio de uma festa. Só
// que não mexia em nada. O corpo é pintado por uma paleta própria (--c-*),
// que sai do convite do casal e estava escrita em pedra; o `data-tema` só
// chegava às JANELAS, que são cartões da casa.
//
// O resultado era uma página clara com janelas escuras por dentro — e, pior,
// as peças da casa que vivem no corpo ficavam com a tinta do tema escolhido
// sobre o fundo claro do convite. O nome da mesa, que é uma dessas peças,
// media 2,2:1. O utilizador viu-o antes de qualquer prova o ver.
//
// Enquanto ninguém escolhe nada, não há `data-tema` nenhum e a página é a
// peça do casal — que é o que ela deve ser por omissão. Escolhido um tema, a
// escolha é de quem está a olhar para o ecrã.
//
// E havia uma armadilha por baixo disto, que só aparece quando o fundo pode
// escurecer: a paleta do casal junta num token só dois papéis — a TINTA dos
// nomes e o ENCHIMENTO dos botões. Num convite claro a mesma cor serve para
// as duas coisas, e ninguém tinha reparado. Num fundo escuro não podem ser a
// mesma: uma tinta clareia quando o fundo escurece, um enchimento continua
// escuro para o que está por cima dele se ler.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const { limparBar } = require('./limpar-bar');
const marca = 'zzv' + Math.floor(Math.random() * 1e5);

const lum = c => { const f = v => { v /= 255;
    return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); };
  return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); };
const razao = (a, c) => { const l1 = lum(a), l2 = lum(c);
  return (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05); };

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push('noivos: ' + e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  const post = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c || {}) });
    return r.json().catch(() => ({ success: false }));
  }, { a, c });

  const antes = await p.evaluate(async () =>
    (await (await fetch('api.php?action=bar_estado')).json()));
  const estavaAberto = !!(antes.estado && antes.estado.aberto);

  const it = await post('bar_item_guardar',
    { nome: 'ZZV Tinto ' + marca, servir: 'ambos', doses_garrafa: 6, max_por_pedido: 5 });
  await post('bar_stock_repor', { item_id: it.id, quantidade: 60, nota: 'prova' });
  if (!estavaAberto) await post('bar_abrir');
  const cena = await p.evaluate(async m => {
    const po = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify(c) }).then(r => r.json());
    const dm = await po('mesa_save', { nome: 'ZZV Mesa dos Padrinhos ' + m,
      capacidade: 6, forma: 'redonda', cor: 'neutra' });
    const mesa = (dm.mesas || []).filter(x => x.nome === 'ZZV Mesa dos Padrinhos ' + m).pop();
    const dc = await po('convite_save', { nome_exibicao: 'ZZV Provador ' + m,
      mesa: mesa ? String(mesa.id) : '', membros: [{ nome: 'ZZV Provador ' + m }] });
    return { mesa: mesa ? +mesa.id : 0, convite: dc.convite ? +dc.convite.id : 0 };
  }, marca);
  ok(cena.mesa && cena.convite && it.id, 'uma mesa com nome, alguém a pedir, e uma bebida');

  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const token = await p.evaluate(i => ((window.BAR_MESAS || [])
    .find(x => +x.id === +i) || {}).token, cena.mesa);

  const g = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  g.on('pageerror', e => errs.push('convidado: ' + e.message));
  await g.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await g.waitForTimeout(1300);
  await g.fill('#b-q', 'ZZV Provador');
  await g.waitForTimeout(1200);
  await g.locator('.b-nome').first().click();
  await g.waitForTimeout(2200);

  // ---- 1. por omissão, a página é a peça do casal ----
  const semTema = await g.evaluate(() => ({
    atributo: document.documentElement.getAttribute('data-tema'),
    fundo: getComputedStyle(document.body).backgroundColor,
  }));
  ok(semTema.atributo === null,
     'enquanto ninguém escolhe, não há tema nenhum posto — a página é o convite '
     + 'do casal, que é o que ela deve ser por omissão');

  // ---- 2. e escolhido um tema, ela responde ----
  const medir = tema => g.evaluate(async t => {
    if (t) document.documentElement.setAttribute('data-tema', t);
    else document.documentElement.removeAttribute('data-tema');
    await new Promise(r => setTimeout(r, 450));
    const n = s => (s.match(/[\d.]+/g) || []).map(Number);
    // Um fundo translúcido não é a sua cor: compõe-se camada a camada.
    const camadas = el => { const out = []; let x = el;
      while (x) { const v = n(getComputedStyle(x).backgroundColor);
        const a = v.length > 3 ? v[3] : 1;
        if (a > 0) out.push({ c: v.slice(0, 3), a: a });
        if (a >= 1) break; x = x.parentElement; }
      return out; };
    const fundoDe = el => { const cs = camadas(el);
      let r = cs.length ? cs[cs.length - 1].c : [255, 255, 255];
      for (let i = cs.length - 2; i >= 0; i--) { const { c, a } = cs[i];
        r = [0, 1, 2].map(j => c[j] * a + r[j] * (1 - a)); }
      return r; };
    // O fundo mede-se A PARTIR DO PRÓPRIO elemento: um botão com enchimento
    // tem o seu, e olhar para o do pai media a tinta contra a superfície
    // errada — dava 1,15:1 num botão que mede 10:1.
    const par = sel => { const el = document.querySelector(sel);
      return el ? { t: n(getComputedStyle(el).color).slice(0, 3),
                    f: fundoDe(el) } : null; };
    return {
      fundo: n(getComputedStyle(document.body).backgroundColor).slice(0, 3),
      corpo: { t: n(getComputedStyle(document.body).color).slice(0, 3),
               f: n(getComputedStyle(document.body).backgroundColor).slice(0, 3) },
      // O NOME DA MESA. É uma peça da casa (a lista com procura) a viver no
      // corpo da página: quando os dois discordavam, era ela que ficava
      // ilegível, e foi por ela que isto se descobriu.
      mesa: par('#b-mesa .lic-sel-bt .txt'),
      nome: par('.b-bebida .nm'),
      ajuda: par('.b-ajuda') || par('.b-bebida .ds'),
      // O enchimento e o que se escreve por cima dele.
      escolhida: par('.b-un-bt.on'),
    };
  }, tema);

  const fundos = {};
  for (const tema of ['niras', 'classico', 'azul', 'escuro']) {
    const m = await medir(tema);
    fundos[tema] = m.fundo.join(',');
    const alvos = Object.entries(m).filter(([k, v]) => k !== 'fundo' && v);
    const linha = alvos.map(([k, v]) => k + ' ' + razao(v.t, v.f).toFixed(2) + ':1').join(' · ');
    console.log('   ' + tema.padEnd(9) + 'fundo rgb(' + m.fundo.join(',') + ')  ' + linha);
    const maus = alvos.filter(([, v]) => razao(v.t, v.f) < 4.5).map(([k]) => k);
    ok(maus.length === 0,
       'no tema ' + tema + ' lê-se tudo o que há para ler'
       + (maus.length ? ' — falham: ' + maus.join(', ') : ''));
  }

  ok(fundos.escuro !== fundos.niras,
     'e o tema escuro escurece MESMO a página: ' + fundos.niras + ' → ' + fundos.escuro);
  ok(new Set(Object.values(fundos)).size >= 3,
     'com os quatro temas a dar fundos diferentes, e não quatro vezes o mesmo: '
     + new Set(Object.values(fundos)).size + ' fundos distintos');

  // ---- 3. e o tema não apaga a identidade do casal ----
  await medir(null);
  const casal = await g.evaluate(() => {
    const cs = getComputedStyle(document.documentElement);
    return { acento: cs.getPropertyValue('--c-acento').trim(),
             verde: cs.getPropertyValue('--c-verde').trim() };
  });
  ok(casal.acento !== '' && casal.acento === casal.verde,
     'sem tema, a tinta e o enchimento são os dois o verde do convite — como '
     + 'sempre foram: ' + casal.acento);

  await medir('escuro');
  const noEscuro = await g.evaluate(() => {
    const cs = getComputedStyle(document.documentElement);
    return { acento: cs.getPropertyValue('--c-acento').trim(),
             verde: cs.getPropertyValue('--c-verde').trim() };
  });
  ok(noEscuro.acento !== noEscuro.verde,
     'e no escuro separam-se: uma tinta tem de clarear quando o fundo escurece, '
     + 'um enchimento não (' + noEscuro.acento + ' vs ' + noEscuro.verde + ')');

  // ---- arrumar ----
  const ficaram = await limparBar(p, { itens: [it.id], marca });
  ok(ficaram.length === 0, 'a prova não deixa bebidas atrás de si: '
     + (ficaram.join(' | ') || 'nada'));
  await p.evaluate(async x => {
    const q = a => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF } }).catch(() => {});
    if (x.convite) await q('convite_delete&id=' + x.convite + '&definitivo=1');
    if (x.mesa) await q('mesa_delete&id=' + x.mesa);
  }, cena);
  if (!estavaAberto) await post('bar_fechar');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
