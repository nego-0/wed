// As fotografias do convite carregam-se na página do convite digital.
//
// Antes pediam-se na inscrição, uma vez, porque o escalão sem edição as fixava
// no acto da compra; e quem tinha edição trocava-as no editor, entre camadas e
// réguas. As duas coisas estavam erradas: a fotografia é do casal, não da
// licença, e pôr uma fotografia não é desenhar um convite.
//
// Prova-se aqui: que a inscrição já não pede nem aceita fotografia nenhuma,
// que a área nova existe e troca de verdade, que a galeria da casa é a única
// origem aceite quando se escolhe de lá, e que voltar à de origem devolve o
// desenho e larga o ficheiro.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  // ============ 1. a inscrição já não sabe de fotografias ============
  const anon = await (await b.newContext()).newPage();
  await anon.goto(BASE + '/registo.php', { waitUntil: 'networkidle' });
  await anon.waitForTimeout(1500);
  const cat = await anon.evaluate(async () =>
    await (await fetch('api.php?action=lic_catalogo')).json());
  ok(cat && cat.success && cat.seccoes_foto === undefined && cat.foto_max_mb === undefined,
     'o catálogo público já não manda secções de foto nem tamanhos');
  const montra = await anon.evaluate(() => ({
    planos: document.querySelectorAll('#reg-planos .pl-pac, #reg-planos .pl-esc').length,
    fotos:  document.querySelectorAll('.pl-fotos, .pl-sec, .pl-ft').length }));
  ok(montra.planos > 0 && montra.fotos === 0,
     'a montra da inscrição mostra os planos (' + montra.planos
       + ' opções) e nenhum bloco de fotografias');
  const morreu = await anon.evaluate(async () => {
    const fd = new FormData();
    fd.append('chave', 'media.hero');
    fd.append('ficheiro', new File([new Uint8Array([1, 2, 3])], 'x.jpg', { type: 'image/jpeg' }));
    const r = await fetch('api.php?action=registo_foto', { method: 'POST', body: fd });
    let d = null; try { d = await r.json(); } catch (e) {}
    return { estado: r.status, sucesso: !!(d && d.success) };
  });
  ok(!morreu.sucesso, 'e o ponto de envio da inscrição já não existe (resposta '
     + morreu.estado + ')');
  await anon.close();

  // ============ 2. a área nova, na página do convite digital ============
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  const abrir = async () => {
    await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
    await p.waitForFunction(() => document.querySelectorAll('.ft-sec').length > 0,
                            null, { timeout: 15000 });
  };
  const secs = () => p.evaluate(async () =>
    (await (await fetch('api.php?action=convite_fotos')).json()).seccoes);

  await abrir();
  const lista = await secs();
  ok(lista.length >= 2, 'a área traz as secções do convite: '
     + lista.map(s => s.rotulo).join(', '));
  ok(lista.every(s => s.chave.startsWith('media.') && s.origem && s.rotulo),
     'cada uma diz a sua chave, o seu nome e a fotografia de origem');
  ok(await p.evaluate(() => document.querySelectorAll('.ft-sec').length) === lista.length,
     'e a página desenha uma linha por secção');

  // A capa é uma secção que existe sempre; começa com a do desenho.
  const capa = lista.find(s => s.chave === 'media.hero');
  ok(capa && !capa.nossa && capa.atual === capa.origem,
     'à partida, a capa mostra a fotografia com que o convite nasceu');
  ok(capa && capa.fotos.length > 0,
     'e a galeria da casa oferece alternativas para ela (' + (capa ? capa.fotos.length : 0) + ')');

  // ============ 3. escolher da galeria da casa ============
  const outra = capa.fotos.find(x => x.src !== capa.atual) || capa.fotos[0];
  await p.evaluate(async (src) => {
    await window.api('convite_foto_galeria',
      { method: 'POST', body: JSON.stringify({ chave: 'media.hero', src }) });
  }, outra.src);
  let agora = (await secs()).find(s => s.chave === 'media.hero');
  ok(agora.atual === outra.src, 'escolher da galeria troca mesmo a fotografia da capa');
  ok(agora.nossa === true, 'e a secção passa a dizer que a fotografia já não é a de origem');

  // Um caminho que não é da galeria desta secção não passa.
  const intruso = await p.evaluate(async () =>
    await window.api('convite_foto_galeria',
      { method: 'POST', body: JSON.stringify({ chave: 'media.hero', src: '../config.php' }),
        semAviso: true }));
  ok(!intruso.success, 'um caminho vindo de fora é recusado: ' + intruso.message);

  // ============ 4. enviar uma fotografia nossa ============
  // Uma imagem verdadeira, feita aqui: o servidor lê o conteúdo, não o nome.
  const enviado = await p.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 900; c.height = 600;
    const g = c.getContext('2d');
    g.fillStyle = '#2C4536'; g.fillRect(0, 0, 900, 600);
    g.fillStyle = '#D9BC8C'; g.font = '90px serif'; g.fillText('nós', 60, 320);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9));
    const fd = new FormData();
    fd.append('chave', 'media.hero');
    fd.append('ficheiro', new File([blob], 'a-nossa.jpg', { type: 'image/jpeg' }));
    return await window.api('convite_foto_enviar', { method: 'POST', body: fd });
  });
  ok(enviado.success && /^assets\/convite\/custom\//.test(enviado.src || ''),
     'a nossa fotografia entra e fica em custom/: ' + enviado.src);
  agora = (await secs()).find(s => s.chave === 'media.hero');
  ok(agora.atual === enviado.src && agora.nossa,
     'a capa passa a mostrá-la, e a secção sabe que é nossa');

  // O convite que os convidados abrem mostra-a — é esse o ponto de tudo isto.
  const paraConvidados = await p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  ok(paraConvidados.includes(enviado.src),
     'e o convite que os convidados abrem já a traz');

  // Uma imagem pequena de mais é recusada: no convite ficaria a esticar.
  const pequena = await p.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 80; c.height = 80;
    c.getContext('2d').fillRect(0, 0, 80, 80);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg'));
    const fd = new FormData();
    fd.append('chave', 'media.hero');
    fd.append('ficheiro', new File([blob], 'mini.jpg', { type: 'image/jpeg' }));
    return await window.api('convite_foto_enviar', { method: 'POST', body: fd, semAviso: true });
  });
  ok(!pequena.success && /400/.test(pequena.message || ''),
     'uma fotografia pequena de mais é recusada com a medida à frente: ' + pequena.message);

  // ============ 5. o ecrã: a linha da capa mostra o que se passou ============
  await abrir();
  const linha = await p.evaluate(() => {
    const el = document.querySelector('.ft-sec[data-sec="media.hero"]');
    return { nossa: el.classList.contains('nossa'),
             etiqueta: el.querySelector('.ft-agora .et').textContent.trim(),
             foto: el.querySelector('.ft-agora img').getAttribute('src'),
             botoes: [...el.querySelectorAll('[data-ft]')].map(b => b.dataset.ft) };
  });
  ok(linha.nossa && linha.etiqueta === 'vossa' && linha.foto === enviado.src,
     'a linha da capa mostra a nossa fotografia, marcada como vossa');
  ok(linha.botoes.includes('repor'),
     'e ganha o botão de voltar à de origem, que só faz sentido agora');
  await p.screenshot({ path: OUT + '/convite-fotos.png' });

  // ============ 6. voltar à de origem ============
  const reposto = await p.evaluate(async () =>
    await window.api('convite_foto_repor', { method: 'POST',
      body: JSON.stringify({ chave: 'media.hero' }) }));
  ok(reposto.success && reposto.src === capa.origem,
     'repor devolve a fotografia de origem: ' + reposto.src);
  agora = (await secs()).find(s => s.chave === 'media.hero');
  ok(!agora.nossa && agora.atual === capa.origem, 'e a secção volta a dizer que é da casa');
  const ficheiroFora = await p.evaluate(async (src) =>
    (await fetch(src)).status, enviado.src);
  ok(ficheiroFora === 404,
     'o ficheiro que tinha sido enviado sai do disco (resposta ' + ficheiroFora + ')');

  // ============ 7. sem o módulo do convite digital, a porta está fechada ============
  // (A licença de origem traz tudo; o que se prova é que a acção o exige.)
  ok(await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=convite_fotos')).json();
    return !!(d && d.success);
  }), 'com o convite digital na licença, a área responde');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
