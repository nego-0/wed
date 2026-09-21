// O nome que a casa mostra é o da FICHA do casamento.
//
// `casal.noiva` e `casal.noivo` são campos do EDITOR DO CONVITE: o casal
// escreve-os lá e é o que sai impresso na peça. A ficha do casamento é outra
// coisa — é quem a festa É, o nome com que ela foi aberta e por onde é gerida.
//
// Enquanto ninguém mexe no convite, as duas dizem o mesmo, e por isso isto
// passou despercebido durante muito tempo. Assim que alguém guarda o editor,
// o valor guardado passa a ganhar em defsAtuais() — e TODA a aplicação passa
// a dizer o que está escrito no convite. Um nome experimentado no editor, ou
// um nome de exemplo deixado lá dentro, aparecia no topo de todas as páginas,
// todos os dias, sem ninguém perceber de onde vinha.
//
// Esta prova faz exactamente isso: escreve no convite um nome DIFERENTE do da
// ficha, e depois percorre a casa inteira a perguntar qual é que aparece.
//
// As peças do convite ficam de fora, e de propósito: convite.php,
// convite-digital.php, os cartões e os dois editores desenham o que o casal
// escreveu. Impor-lhes a ficha era reescrever-lhes a peça por cima.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.SHOTS || '';
const marca = Math.floor(Math.random() * 1e5);
const DO_CONVITE = { noiva: 'ZZFalsa' + marca, noivo: 'ZZErrado' + marca };

// As páginas da APLICAÇÃO. Em todas, o nome vem da ficha.
const PAGINAS = [
  ['index.php', 'Painel'], ['mesas.php', 'Mesas'], ['orcamento.php', 'Orçamento'],
  ['bar.php', 'Bar'], ['copa.php', 'Copa'], ['entregas.php', 'Entregas'],
  ['porteiro.php', 'Porta'], ['gestao.php', 'Gestão'], ['licenca.php', 'Licença'],
  ['impressos.php', 'Impressos'], ['digital.php', 'Digital'], ['graficas.php', 'Gráficas'],
  ['modelos.php', 'Modelos'], ['registo.php', 'Registo'], ['manual.php', 'Manual'],
  ['versao.php', 'Versão'], ['plataforma.php', 'Plataforma'],
];

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const p = await (await b.newContext({ viewport: { width: 1280, height: 950 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  // A ficha, como ela está.
  const ficha = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=casamento_atual')).json().catch(() => ({}));
    return d || {};
  });

  // O convite passa a dizer outra coisa. É o caso real: alguém guardou o
  // editor com um nome diferente.
  const guardou = await p.evaluate(async v => {
    const r = await fetch('api.php?action=defs_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ defs: { 'casal.noiva': v.noiva, 'casal.noivo': v.noivo } }) });
    return (await r.json().catch(() => ({}))).success === true;
  }, DO_CONVITE);
  ok(guardou, 'escreveu-se no convite um nome diferente do da ficha: «'
     + DO_CONVITE.noiva + ' & ' + DO_CONVITE.noivo + '»');

  // Lê-se no EDITOR do convite, que é onde o valor guardado vive de facto.
  // Sem esta confirmação, a prova podia correr toda a verde por o convite
  // nunca ter chegado a dizer nada de diferente — e então não provava nada.
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  const noConvite = await p.evaluate(() => (document.body.innerText || ''));
  ok(noConvite.includes(DO_CONVITE.noiva),
     'e o convite guardou-o mesmo — senão esta prova não provava nada, e uma '
     + 'prova que não prova nada passa sempre');

  // ---- e agora, a casa inteira ----
  const maus = [];
  for (const [pag, rot] of PAGINAS) {
    const r = await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' }).catch(() => null);
    if (!r || r.status() >= 400) { console.log('   ' + rot.padEnd(12) + '(não abre)'); continue; }
    await p.waitForTimeout(900);
    const lido = await p.evaluate(() => {
      const t = (document.querySelector('.topo h1, .topo .casal, .topo .sub') || {}).textContent || '';
      const mono = (document.querySelector('.topo .monograma, .monograma') || {}).textContent || '';
      // O cabeçalho da casa põe os nomes no subtítulo; o monograma é o selo.
      const todo = (document.querySelector('.topo') || document.body).innerText || '';
      return { titulo: document.title, mono: mono.trim(),
               topo: todo.replace(/\s+/g, ' ').slice(0, 220) };
    });
    const temFalso = (lido.titulo + ' ' + lido.topo + ' ' + lido.mono).includes('ZZFalsa')
                  || (lido.titulo + ' ' + lido.topo).includes('ZZErrado');
    console.log('   ' + rot.padEnd(12) + (temFalso ? '✗ diz o do CONVITE' : '✓ diz o da ficha')
                + '  · mono «' + lido.mono + '»');
    if (temFalso) maus.push(rot);
    if (OUT) await p.screenshot({ path: OUT + '/ficha-' + pag.replace('.php', '') + '.png' });
  }
  ok(maus.length === 0,
     'em todas as páginas da aplicação manda a ficha, e não o que está escrito '
     + 'no convite' + (maus.length ? ' — falham: ' + maus.join(', ') : ''));

  // ---- e a página do convidado, que é pública ----
  const slug = await p.evaluate(async () => {
    const r = await fetch('api.php?action=bar_estado');
    return 1;
  });
  const g = await (await b.newContext({ viewport: { width: 390, height: 844 },
                                        isMobile: true, hasTouch: true })).newPage();
  const rb = await g.goto(BASE + '/bebidas-2026-12-19-ia.php', { waitUntil: 'networkidle' })
                   .catch(() => null);
  if (rb && rb.status() < 400) {
    const noBar = await g.evaluate(() => ({
      titulo: document.title,
      casal: ((document.querySelector('.b-festa-topo-casal, .b-cabeca .casal, h1') || {})
                .textContent || '').trim(),
      corpo: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 200),
    }));
    const falso = (noBar.titulo + ' ' + noBar.casal + ' ' + noBar.corpo).includes('ZZFalsa');
    ok(!falso, 'e na carta do convidado também — é a página que a festa inteira '
       + 'abre: «' + noBar.titulo + '»');
    if (OUT) await g.screenshot({ path: OUT + '/ficha-bebidas.png' });
  } else {
    ok(false, 'a carta do convidado não abriu pelo endereço da festa');
  }

  // ---- mas a PEÇA do convite continua a ser do casal ----
  const rc = await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' })
                    .catch(() => null);
  if (rc && rc.status() < 400) {
    await p.waitForTimeout(1200);
    const noEditor = await p.evaluate(() => (document.body.innerText || ''));
    ok(noEditor.includes('ZZFalsa'),
       'e o editor do convite continua a mostrar o que o casal lá escreveu — '
       + 'impor-lhe a ficha era reescrever-lhe a peça por cima');
    if (OUT) await p.screenshot({ path: OUT + '/ficha-convite-editor.png' });
  }

  // ---- arrumar: o convite volta ao que era ----
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.evaluate(async v => {
    await fetch('api.php?action=defs_save', { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: JSON.stringify({ defs: { 'casal.noiva': v.noiva, 'casal.noivo': v.noivo } }) });
  }, { noiva: (ficha.noiva || 'Isabel'), noivo: (ficha.noivo || 'Abednego') });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
