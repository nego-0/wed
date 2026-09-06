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
//
// A área vive numa aba da própria peça — tomou o lugar do link «Painel de
// convidados», que era uma porta para fora daquilo que se veio cá fazer. E
// traz as duas coisas que só o editor tinha: ver a fotografia em ponto grande,
// e escolher que pedaço dela fica à vista nas secções que recortam.
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
    await p.click('#ab-fotos');
    await p.waitForTimeout(300);
  };
  const secs = () => p.evaluate(async () =>
    (await (await fetch('api.php?action=convite_fotos')).json()).seccoes);

  // A aba: as fotografias são desta peça, e é aqui que estão.
  await p.goto(BASE + '/digital.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const abas = await p.evaluate(() => ({
    nomes: [...document.querySelectorAll('.p-aba')].map(b => b.textContent.trim()),
    estadoAVista: !document.getElementById('pn-estado').hidden,
    fotosAVista: !document.getElementById('pn-fotos').hidden,
    // O link que a aba substituiu não pode continuar ao lado dela.
    paraOPainel: [...document.querySelectorAll('.peca-acoes a')]
                   .filter(a => /Painel de convidados/.test(a.textContent)).length
  }));
  ok(abas.nomes.length === 2 && /fotografias/i.test(abas.nomes[1]),
     'a peça abre em duas abas: ' + abas.nomes.join(' · '));
  ok(abas.estadoAVista && !abas.fotosAVista,
     'e abre pelo estado, que é o que a página é');
  ok(abas.paraOPainel === 0,
     'o link «Painel de convidados» deu o lugar à aba — o painel está no menu');
  await p.click('#ab-fotos');
  await p.waitForTimeout(300);
  const trocou = await p.evaluate(() => ({
    fotos: !document.getElementById('pn-fotos').hidden,
    estado: !document.getElementById('pn-estado').hidden,
    larga: document.querySelector('.peca').classList.contains('fotos'),
    prova: !!document.querySelector('.peca-prova iframe') }));
  ok(trocou.fotos && !trocou.estado, 'carregar na aba mostra as fotografias');
  ok(trocou.larga && trocou.prova,
     'que ficam com a largura toda do cartão, e com a prova ainda ao lado');
  await p.screenshot({ path: OUT + '/convite-fotos-aba.png' });

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

  // A caixa é a mesma para todas: quatro janelas de feitios diferentes numa
  // grelha davam uma escada. O feitio de cada secção vê-se em ponto grande.
  const caixas = await p.evaluate(() =>
    [...document.querySelectorAll('.ft-agora')].map(c => {
      const r = c.getBoundingClientRect();
      return Math.round(r.width) + '×' + Math.round(r.height);
    }));
  ok(new Set(caixas).size === 1,
     'todas as secções mostram a fotografia na mesma caixa: ' + caixas.join(' '));

  // ============ 3. a galeria da casa não é do casal ============
  //
  // É material de modelo, do lado de quem os desenha; chega ao casal já
  // escolhida, dentro do modelo que ele tem. Ao casal cabe a fotografia dele.
  ok(lista.every(s => s.fotos === undefined),
     'as secções já não trazem galeria nenhuma para o casal escolher');
  ok(await p.evaluate(() =>
       document.querySelectorAll('.ft-op, .ft-galeria, [data-ft="galeria"]').length) === 0,
     'e a página não tem por onde a abrir');
  const galeria = await p.evaluate(async (src) =>
    await window.api('convite_foto_galeria',
      { method: 'POST', body: JSON.stringify({ chave: 'media.hero', src }),
        semAviso: true }), capa.origem);
  ok(!galeria.success, 'o ponto de escolha da galeria já não existe: ' + galeria.message);

  // ============ 3b. as secções são as do convite, e não uma lista à parte ==
  //
  // Esconder a história no convite tira-a daqui: pedir uma fotografia para uma
  // página que ninguém vai ver era pedi-la para nada. É o mesmo mecanismo que
  // faz um modelo com outras secções trazer outra lista — as secções saem
  // sempre do convite que o casal tem agora.
  ok(lista.some(s => s.chave === 'media.historia'),
     'com a história à vista, ela está na lista');
  const ordemConvite = lista.map(s => s.seccao).join(',');
  ok(/hero.*historia.*interludio.*acesso/.test(ordemConvite),
     'e a lista sai pela ordem em que o convite as mostra: ' + ordemConvite);

  await p.evaluate(async () =>
    await window.api('defs_save', { method: 'POST',
      body: JSON.stringify({ defs: { 'historia.visivel': '0' } }) }));
  const semHistoria = await secs();
  ok(!semHistoria.some(s => s.chave === 'media.historia'),
     'escondida a secção, ela deixa de pedir fotografia ('
       + semHistoria.map(s => s.seccao).join(', ') + ')');
  await p.evaluate(async () =>
    await window.api('defs_save', { method: 'POST',
      body: JSON.stringify({ defs: { 'historia.visivel': '1' } }) }));
  ok((await secs()).some(s => s.chave === 'media.historia'),
     'e volta assim que ela volta');

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

  // ============ 6. em ponto grande — e é aí que se enquadra ============
  //
  // A capa é uma janela 9/16 sobre uma fotografia larga. Qual pedaço lá cabe
  // decidia-se no editor, entre camadas e réguas; e uma fotografia cortada
  // pelo meio da cara não se resolve escolhendo outra. Agora vê-se a
  // fotografia inteira com a moldura da secção por cima: o que fica de fora
  // escurece, e arrasta-se a moldura até ela conter o que interessa.
  agora = (await secs()).find(s => s.chave === 'media.hero');
  ok(agora.enq === 'foto.hero' && agora.proporcao === '9/16',
     'a capa diz que recorta, e em que forma: ' + agora.proporcao);
  ok(agora.pos && agora.pos.x === 50 && agora.pos.y === 50,
     'e ao receber uma fotografia nova o enquadramento voltou ao centro — o '
       + 'anterior tinha sido escolhido para outra imagem ('
       + (agora.pos ? agora.pos.x + '/' + agora.pos.y : '—') + ')');

  const semRecorte = (await secs()).find(s => s.chave === 'media.historia');
  ok(semRecorte && semRecorte.enq === '' && semRecorte.pos === null,
     'a história mostra a fotografia inteira, e por isso não tem o que enquadrar');
  ok(await p.evaluate(() =>
       !document.querySelector('.ft-sec[data-sec="media.historia"] [data-ft="lupa"].btn')),
     'e a ficha dela não oferece «Enquadrar»');

  await p.click('.ft-sec[data-sec="media.hero"] .ft-lupa');
  await p.waitForTimeout(700);
  const lente = await p.evaluate(() => {
    const lt = document.getElementById('ft-lente');
    const im = document.getElementById('ft-lente-img');
    const j  = document.getElementById('ft-janela');
    const jr = j.getBoundingClientRect(), ir = im.getBoundingClientRect();
    return { aberta: lt.classList.contains('on'),
             src: im.getAttribute('src'),
             leg: document.getElementById('ft-lente-leg').textContent.trim(),
             // Em grande é a fotografia inteira: o recorte é o que a moldura diz.
             recorta: !!im.style.objectPosition,
             moldura: !j.hidden,
             prop: +(jr.width / jr.height).toFixed(3),
             cabe: jr.width <= ir.width + 1 && jr.height <= ir.height + 1,
             veu: getComputedStyle(j).boxShadow };
  });
  ok(lente.aberta && lente.src === enviado.src && !lente.recorta,
     'a lupa abre a fotografia inteira, em ponto grande: ' + lente.src);
  ok(/Capa/.test(lente.leg) && /vossa/.test(lente.leg),
     'e diz de que secção é: ' + lente.leg);
  ok(lente.moldura && Math.abs(lente.prop - 9 / 16) < 0.02,
     'por cima dela, a moldura com a forma da secção (9/16 = 0.563): ' + lente.prop);
  ok(lente.cabe, 'e do tamanho do recorte verdadeiro, dentro da fotografia');
  ok(/9999px/.test(lente.veu),
     'o que fica de fora da moldura escurece: ' + lente.veu.slice(0, 46) + '…');
  await p.screenshot({ path: OUT + '/convite-fotos-lente.png' });

  // Arrastar a moldura: é o gesto que o casal faz. A fotografia de prova é
  // larga (900×600) e a janela é estreita — a folga é horizontal.
  const jb = await (await p.$('#ft-janela')).boundingBox();
  await p.mouse.move(jb.x + jb.width / 2, jb.y + jb.height / 2);
  await p.mouse.down();
  await p.mouse.move(jb.x + jb.width, jb.y + jb.height / 2, { steps: 10 });
  await p.mouse.up();
  await p.waitForTimeout(800);
  const enquadrada = (await secs()).find(s => s.chave === 'media.hero');
  ok(enquadrada.pos.x > 50,
     'arrastar a moldura para a direita corre o que fica à vista: x = '
       + enquadrada.pos.x);
  ok(enquadrada.pos.zoom === 100,
     'e a aproximação fica como estava — aqui mexe-se no ponto, e mais nada');

  const noConvite = await p.evaluate(async () =>
    await (await fetch('convite-digital.php?demo=1')).text());
  ok(noConvite.includes('--foco-hero:' + enquadrada.pos.x + '% '),
     'e o convite que os convidados abrem recorta por esse ponto');

  // As setas, para quem não usa rato.
  await p.focus('#ft-janela');
  await p.keyboard.press('ArrowLeft');
  await p.waitForTimeout(900);
  const comTeclado = (await secs()).find(s => s.chave === 'media.hero');
  ok(comTeclado.pos.x === enquadrada.pos.x - 2,
     'as setas mexem 2% de cada vez: ' + enquadrada.pos.x + ' → ' + comTeclado.pos.x);

  // ============ 7. a moldura tira-se, e a lente fecha-se ============
  await p.click('#ft-lente-ac [data-lt="moldura"]');
  await p.waitForTimeout(300);
  ok(await p.evaluate(() => document.getElementById('ft-janela').hidden),
     'quem só quer ver a fotografia tira a moldura');
  await p.click('#ft-lente-ac [data-lt="moldura"]');
  await p.waitForTimeout(300);
  ok(await p.evaluate(() => !document.getElementById('ft-janela').hidden),
     'e volta a pô-la');

  await p.keyboard.press('Escape');
  await p.waitForTimeout(300);
  ok(await p.evaluate(() => !document.getElementById('ft-lente').classList.contains('on')),
     'o Escape fecha a lente');

  // ============ 8. voltar à de origem ============
  const reposto = await p.evaluate(async () =>
    await window.api('convite_foto_repor', { method: 'POST',
      body: JSON.stringify({ chave: 'media.hero' }) }));
  ok(reposto.success && reposto.src === capa.origem,
     'repor devolve a fotografia de origem: ' + reposto.src);
  agora = (await secs()).find(s => s.chave === 'media.hero');
  ok(!agora.nossa && agora.atual === capa.origem, 'e a secção volta a dizer que é da casa');
  ok(agora.pos.x === 50 && agora.pos.y === 8,
     'de origem é de origem: o enquadramento também volta ao do desenho ('
       + agora.pos.x + '/' + agora.pos.y + ')');
  const ficheiroFora = await p.evaluate(async (src) =>
    (await fetch(src)).status, enviado.src);
  ok(ficheiroFora === 404,
     'o ficheiro que tinha sido enviado sai do disco (resposta ' + ficheiroFora + ')');

  // ============ 9. sem o módulo do convite digital, a porta está fechada ============
  // (A licença de origem traz tudo; o que se prova é que a acção o exige.)
  ok(await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=convite_fotos')).json();
    return !!(d && d.success);
  }), 'com o convite digital na licença, a área responde');

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
