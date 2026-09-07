// A prova do desenho do bar (docs/modulo-bar.md §25.14).
//
// O desenho verifica-se como o resto. Não porque uma folha de estilo se possa
// provar bonita — não se pode —, mas porque as coisas que a fazem funcionar
// para quem a usa SÃO mensuráveis: o dedo alcança o botão, a página não foge
// de lado, o número não dança, o anel de foco vê-se, e uma cor inventada num
// tema não sobrevive aos outros quatro.
//
// Os quatro temas ficam de fora daqui de propósito: já estão medidos em
// chk_bar.js §8b, onde vivem os --sala-*, e uma verificação em dois sítios é
// uma verificação que se corrige num só.
//
// O que cada bloco defende:
//
//   1. Nenhuma cor inventada. Um #8a8f88 solto numa folha é a mesma cor nos
//      quatro temas — e no escuro desaparece. As excepções são as de §25.3:
//      os rgba() de véu e sombra (que não são cor) e o valor de recurso de um
//      var(--x, #y), que é a rede por baixo de uma variável que a própria
//      página emite.
//   2. O dedo. 56px nas acções principais, 48 nas outras, medido na caixa
//      clicável e não no ícone. Ninguém usa isto com um rato.
//   3. Sem fuga lateral em 360, 390 e 430 — os três telemóveis que existem.
//   4. Esqueleto e vazio. O terceiro estado é o que separa um produto de um
//      protótipo: uma lista vazia sem uma frase é uma avaria silenciosa.
//   5. O anel de foco. A copa é usada com um teclado à frente.
//   6. Números tabulares onde há contagem: sem eles o relógio salta de pixel
//      a cada segundo, e um número que dança lê-se pior do que um parado.
//   7. A página do convidado veste o casal: muda-se a cor do convite e o menu
//      muda com ela.
//   8. Movimento reduzido: com prefers-reduced-motion, nada acima de 0 ms.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const vigiar = (p, tag) => {
    p.on('pageerror', e => errs.push(tag + ': ' + e.message));
    p.on('console', m => { if (m.type() === 'error') errs.push(tag + ': ' + m.text()); });
  };

  // ============ montar ============
  const casa = await b.newContext({ viewport: { width: 1280, height: 950 } });
  const p = await casa.newPage();
  vigiar(p, 'noivos');
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);

  const cenario = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const i of (e.itens || []).filter(i => /^ZZD /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    // Duas bebidas, e não uma: com uma só no armazém, uma procura que não
    // filtrasse nada dava o mesmo resultado que uma que filtrasse bem — e o
    // teste passava sem provar coisa nenhuma.
    await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZZD Cerveja', categoria_id: e.categorias[0].id, stock: 40,
        visivel: 1, max_por_pedido: 3 }) });
    await window.api('bar_item_guardar', { method: 'POST', body: JSON.stringify(
      { nome: 'ZZD Água com acento', categoria_id: e.categorias[0].id, stock: 25,
        visivel: 1, max_por_pedido: 3 }) });
    await window.api('bar_abrir', { method: 'POST', body: '{}' });
    return { token: window.BAR_MESAS[0].token };
  });
  const token = cenario.token;

  // ============ 1. nenhuma cor inventada ============
  // Lê-se a folha como o browser a recebe, e não do disco: é a folha servida
  // que pinta a festa.
  const folhas = { 'assets/bar.css': null };
  for (const nome of Object.keys(folhas)) {
    folhas[nome] = await p.evaluate(async (n) => await (await fetch(n)).text(), nome);
  }
  // Um hexadecimal só passa se for o valor de recurso de um var(--x, #y).
  const soltos = (css) => css
    .replace(/var\(\s*--[A-Za-z0-9-]+\s*,\s*#[0-9a-fA-F]{3,8}\s*\)/g, '')
    .match(/#[0-9a-fA-F]{3,8}\b/g) || [];
  ok(soltos(folhas['assets/bar.css']).length === 0,
     'bar.css não inventa cor nenhuma: tudo sai dos tokens'
     + (soltos(folhas['assets/bar.css']).length
        ? ' — soltos: ' + soltos(folhas['assets/bar.css']).join(', ') : ''));

  // E os <style> das quatro páginas, que é onde a tentação é maior.
  for (const pag of ['copa.php', 'entregas.php', 'bebidas.php?m=' + token]) {
    const fonte = await p.evaluate(async (u) => await (await fetch(u)).text(), pag);
    const estilos = (fonte.match(/<style[\s\S]*?<\/style>/gi) || []).join('\n');
    // bebidas.php emite a paleta do casal em hexadecimal, e é o que tem a
    // fazer: são as cores DAQUELE convite, vindas da base de dados, e não
    // cores escolhidas por quem escreveu a folha. Só essas linhas passam.
    const semPaleta = estilos.replace(/--c-[a-z-]+:\s*#[0-9a-fA-F]{3,8};/g, '');
    const maus = soltos(semPaleta);
    ok(maus.length === 0,
       'o <style> de ' + pag.split('?')[0] + ' também não'
       + (maus.length ? ' — soltos: ' + maus.slice(0, 6).join(', ') : ''));
  }

  // ============ 2. o dedo, a 390 px ============
  // 56 px nas acções principais (pedir, aprovar, entregar) e 48 nas outras,
  // medido na caixa clicável. Os botões dentro de uma linha de texto — o ✕ de
  // uma regra — vivem em janelas e não nesta conta.
  // A copa e as entregas exigem sessão: um contexto novo não a tem, e uma
  // medição feita em login.php mede o login. Só bebidas.php é pública.
  const medirAlvos = async (pag, tag, publica) => {
    const c = await (publica ? await b.newContext({ viewport: { width: 390, height: 844 } })
                             : casa).newPage();
    await c.setViewportSize({ width: 390, height: 844 });
    vigiar(c, tag);
    await c.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
    await c.waitForTimeout(1400);
    const curtos = await c.evaluate(() => {
      const maus = [];
      document.querySelectorAll('button, a.btn, [role=button]').forEach(el => {
        const r = el.getBoundingClientRect();
        if (!r.width || !r.height) return;                 // escondido
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') return;
        const principal = el.classList.contains('b-bt-grande')
                       || el.classList.contains('b-pin-bt')
                       || el.id === 'b-pedir';
        const min = principal ? 56 : 48;
        if (r.height < min - 0.5) {
          maus.push((el.id || el.className || el.tagName) + ' ' + Math.round(r.height)
                    + '<' + min);
        }
      });
      return maus;
    });
    await c.close();
    return curtos;
  };
  for (const [pag, tag, pub] of [['copa.php', 'copa', false], ['entregas.php', 'entregas', false],
                                 ['bebidas.php?m=' + token, 'bebidas', true]]) {
    const maus = await medirAlvos(pag, tag, pub);
    ok(maus.length === 0, 'em ' + pag.split('?')[0] + ' nenhum alvo é pequeno de mais'
       + (maus.length ? ': ' + maus.slice(0, 4).join(' · ') : ''));
  }

  // ============ 3. sem fuga lateral ============
  // Os três telemóveis que existem. Uma página que foge de lado num destes
  // esconde metade do menu a quem tem o telefone pequeno.
  for (const larg of [360, 390, 430]) {
    const c = await casa.newPage();          // com sessão: as duas páginas do pessoal pedem-na
    await c.setViewportSize({ width: larg, height: 780 });
    vigiar(c, 'largura ' + larg);
    const fugas = [];
    for (const pag of ['copa.php', 'entregas.php', 'bebidas.php?m=' + token]) {
      await c.goto(BASE + '/' + pag, { waitUntil: 'networkidle' });
      await c.waitForTimeout(1200);
      const fuga = await c.evaluate(() =>
        document.documentElement.scrollWidth - window.innerWidth);
      if (fuga > 1) fugas.push(pag.split('?')[0] + ' +' + fuga + 'px');
    }
    await c.close();
    ok(fugas.length === 0, 'a ' + larg + 'px nada transborda de lado'
       + (fugas.length ? ': ' + fugas.join(', ') : ''));
  }

  // ============ 4. esqueleto e vazio ============
  // O esqueleto tem de estar no HTML e não só no guião: se esperar pelo JS,
  // a pessoa vê um ecrã branco exactamente no momento em que a rede do salão
  // está pior.
  for (const [pag, nome] of [['entregas.php', 'entregas'], ['bebidas.php?m=' + token, 'bebidas']]) {
    const fonte = await p.evaluate(async (u) => await (await fetch(u)).text(), pag);
    ok(/class="[^"]*b-esq/.test(fonte),
       'o esqueleto de ' + nome + ' vem no HTML, antes de o guião correr');
  }
  ok(/b-esq/.test(await p.evaluate(async () =>
       await (await fetch('assets/bar-copa.js')).text())),
     'e a copa desenha o seu antes da primeira resposta');

  // O vazio diz porquê, e não fica em branco. Fecha-se o menu à vista do
  // convidado escondendo a única bebida.
  // «oculto», e não «visivel:0»: é o nome que o servidor conhece, e um campo
  // que ele não conhece passa em silêncio — o menu ficava cheio e a prova
  // acusava a página de não mostrar o vazio que nunca chegou a existir.
  const escondido = await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    const antes = [];
    for (const i of (e.itens || []).filter(i => i.estado !== 'oculto')) {
      antes.push(i.id);
      await window.api('bar_item_guardar', { method: 'POST',
        body: JSON.stringify({ id: i.id, nome: i.nome, categoria_id: i.categoria_id,
                               max_por_pedido: i.max_por_pedido, estado: 'oculto' }) });
    }
    return antes;
  });
  const vazio = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  vigiar(vazio, 'vazio');
  await vazio.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
  await vazio.waitForTimeout(900);
  await vazio.fill('#b-q', 'conv');
  await vazio.waitForTimeout(800);
  await vazio.locator('.b-nome').first().click();
  await vazio.waitForTimeout(1400);
  const frase = await vazio.locator('.b-vazio').innerText().catch(() => '');
  ok(frase.trim().length > 10,
     'um menu vazio explica-se por palavras, e não fica em branco: «' + frase.replace(/\n/g, ' ') + '»');
  await vazio.close();
  // Repor o que se escondeu, antes de tudo o resto.
  await p.evaluate(async (ids) => {
    const e = await window.api('bar_estado');
    for (const i of (e.itens || []).filter(i => ids.indexOf(i.id) >= 0)) {
      await window.api('bar_item_guardar', { method: 'POST',
        body: JSON.stringify({ id: i.id, nome: i.nome, categoria_id: i.categoria_id,
                               max_por_pedido: i.max_por_pedido, estado: 'ativo' }) });
    }
  }, escondido);

  // ============ 5. o anel de foco ============
  const foco = await casa.newPage();
  await foco.setViewportSize({ width: 1100, height: 860 });
  vigiar(foco, 'foco');
  await foco.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await foco.waitForTimeout(1300);
  // Tabula-se a sério, com o teclado: :focus-visible só acende quando o foco
  // veio do teclado, e um el.focus() do guião não o acorda. Uma verificação
  // com element.focus() dava sempre «sem anel», e a culpa não era da folha.
  const semAnel = [];
  await foco.locator('body').click({ position: { x: 2, y: 2 } });
  for (let i = 0; i < 14; i++) {
    await foco.keyboard.press('Tab');
    // Uma pausa antes de medir: os .btn da casa têm transition:.18s, que vale
    // para TODAS as propriedades — o anel cresce de 0 e a cor vem da anterior.
    // Ler no instante do Tab dava sempre «sem anel», e a culpa era do relógio.
    await foco.waitForTimeout(260);
    const parada = await foco.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      if (!el.getBoundingClientRect().height) return null;
      const cs = getComputedStyle(el);
      // Ou um contorno, ou uma sombra que faça de anel. Uma das duas basta;
      // nenhuma é uma paragem cega para quem anda de tabulação.
      const temContorno = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
      const temSombra = cs.boxShadow && cs.boxShadow !== 'none';
      return (temContorno || temSombra) ? null : (el.id || el.className || el.tagName);
    });
    if (parada) semAnel.push(parada);
  }
  ok(semAnel.length === 0, 'cada paragem da tabulação na copa mostra-se'
     + (semAnel.length ? ': ' + semAnel.slice(0, 4).join(' · ') : ''));

  // ============ 6. números tabulares ============
  const dancam = await foco.evaluate(() => {
    const maus = [];
    document.querySelectorAll('.b-barra .n, .b-conta, .b-tempos b, .b-est').forEach(el => {
      if (!el.getBoundingClientRect().height) return;
      if (!/tabular-nums/.test(getComputedStyle(el).fontVariantNumeric)) {
        maus.push(el.className || el.tagName);
      }
    });
    return maus;
  });
  ok(dancam.length === 0, 'os números que contam não dançam de segundo a segundo'
     + (dancam.length ? ': ' + [...new Set(dancam)].slice(0, 4).join(' · ') : ''));
  await foco.close();

  // ============ 7. o convidado veste o casal ============
  // Muda-se a cor do convite e o menu tem de mudar com ela. Se falhar, a
  // página do bar deixou de ser do casamento e passou a ser da aplicação.
  const antesPaleta = await p.evaluate(async () => {
    const r = await fetch('api.php?action=dados_exportar&ambito=casamento',
                          { headers: { 'X-CSRF-Token': window.CSRF } });
    const d = await r.json();
    return ((d.casamentos || [])[0] || {}).definicoes
      ? (d.casamentos[0].definicoes['tema.paleta'] || '') : '';
  });
  const corDe = async () => {
    const c = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await c.goto(BASE + '/bebidas.php?m=' + token, { waitUntil: 'networkidle' });
    await c.waitForTimeout(700);
    const v = await c.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--c-ouro').trim());
    await c.close();
    return v;
  };
  const ouroAntes = await corDe();
  await p.evaluate(async () => {
    await window.api('defs_save', { method: 'POST', body: JSON.stringify(
      { defs: { 'tema.paleta': JSON.stringify({ gold: '#7A1FA2' }) } }) });
  });
  const ouroDepois = await corDe();
  ok(ouroAntes !== ouroDepois && /7A1FA2/i.test(ouroDepois),
     'muda-se a cor do convite e o menu do bar muda com ela: '
     + ouroAntes + ' → ' + ouroDepois);
  await p.evaluate(async (v) => {
    await window.api('defs_save', { method: 'POST',
      body: JSON.stringify({ defs: { 'tema.paleta': v } }) });
  }, antesPaleta);
  ok(await corDe() === ouroAntes, 'e volta ao que era quando se repõe a paleta');

  // ============ 8. os quatro ecrãs falam a mesma língua ============
  // O módulo tem quatro páginas e uma só caixa de ferramentas (assets/
  // bar-pecas.js). Antes, cada página trazia a sua cópia de esc(), toast() e
  // da procura — e as cópias divergiam: uma ignorava acentos, a outra não.
  // Isto prende as quatro ao mesmo módulo, para a divergência não voltar.
  for (const [pag, quem] of [['/bar.php', 'a montagem'], ['/copa.php', 'a copa'],
                             ['/entregas.php', 'as entregas'],
                             ['/bebidas.php?m=' + token, 'o menu do convidado']]) {
    const q = await casa.newPage();
    vigiar(q, 'peças ' + pag);
    await q.goto(BASE + pag, { waitUntil: 'networkidle' });
    await q.waitForTimeout(900);
    const tem = await q.evaluate(() => ({
      ico: !!(window.ICO && window.ICO.ico && window.ICO.copo),
      bp:  !!(window.BP && window.BP.campoBusca && window.BP.chave && window.BP.foto)
    }));
    ok(tem.ico && tem.bp, quem + ' desenha-se com os ícones e as peças da casa');
    await q.close();
  }

  // ============ 9. nem um emoji ============
  // Um emoji é o desenho de OUTRA gente: muda de forma em cada sistema, sai a
  // cores no meio de uma página a traço, e nas fontes que não o têm sai o
  // quadrado do «não sei desenhar isto». Num ecrã de serviço isso é pior do
  // que não ter sinal nenhum — e por isso o módulo não tem nenhum.
  const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE0F}]/u;
  for (const [pag, quem] of [['/bar.php', 'a montagem'], ['/copa.php', 'a copa'],
                             ['/entregas.php', 'as entregas'],
                             ['/bebidas.php?m=' + token, 'o menu do convidado'],
                             ['/bebidas.php?m=NAO-EXISTE', 'a página do código errado']]) {
    const q = await casa.newPage();
    await q.goto(BASE + pag, { waitUntil: 'networkidle' });
    await q.waitForTimeout(900);
    const achados = await q.evaluate((re) => {
      const rx = new RegExp(re, 'u');
      const maus = [];
      const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = w.nextNode())) {
        const t = n.nodeValue || '';
        if (rx.test(t)) maus.push(t.trim().slice(0, 40));
      }
      return maus;
    }, EMOJI.source);
    ok(achados.length === 0, quem + ' não usa um único emoji'
       + (achados.length ? ': ' + achados.slice(0, 3).join(' · ') : ''));
    await q.close();
  }

  // ============ 10. um botão de ícone tem nome ============
  // O desenho reconhece-se de relance, mas um ícone sozinho é mudo para quem
  // ouve a página em vez de a ver — e para quem pára o rato à espera da
  // palavra. Todo o botão sem texto tem de ter aria-label ou title.
  for (const [pag, quem] of [['/bar.php', 'a montagem'], ['/copa.php', 'a copa'],
                             ['/entregas.php', 'as entregas']]) {
    const q = await casa.newPage();
    await q.goto(BASE + pag, { waitUntil: 'networkidle' });
    await q.waitForTimeout(1100);
    const mudos = await q.evaluate(() => {
      const maus = [];
      document.querySelectorAll('button, [role=button]').forEach(el => {
        if (el.offsetParent === null) return;              // escondido, não conta
        if ((el.textContent || '').trim()) return;         // tem palavra
        if (el.getAttribute('aria-label') || el.getAttribute('title')) return;
        maus.push(el.className || el.tagName);
      });
      return maus;
    });
    ok(mudos.length === 0, 'em ' + quem + ', nenhum botão de ícone é mudo'
       + (mudos.length ? ': ' + [...new Set(mudos)].slice(0, 3).join(' · ') : ''));
    await q.close();
  }

  // ============ 11. a procura procura ============
  // Uma caixa de procura que não filtra é um enfeite. Escreve-se um nome que
  // só uma bebida tem, e a lista tem de encolher — e sem acentos, porque quem
  // escreve de pé num teclado de telemóvel não os põe.
  const proc = await casa.newPage();
  vigiar(proc, 'procura');
  await proc.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await proc.waitForTimeout(1200);
  const stock = await proc.evaluate(() => document.querySelectorAll('#b-stock .b-item').length);
  // «agua» sem acento tem de achar «Água»: quem escreve de pé, num teclado de
  // telemóvel e com um copo na outra mão, não põe acentos nenhuns.
  await proc.fill('#q-stock', 'agua com acento');
  await proc.waitForTimeout(500);
  const depois = await proc.evaluate(() => document.querySelectorAll('#b-stock .b-item').length);
  ok(stock > depois && depois === 1,
     'a procura do stock corta a lista, e sem acentos: ' + stock + ' → ' + depois);
  await proc.fill('#q-stock', 'zzzznaoexiste');
  await proc.waitForTimeout(500);
  const nada = await proc.evaluate(() =>
    (document.querySelector('#b-stock .b-vazio b') || {}).textContent || '');
  ok(/nada com esse nome/i.test(nada),
     'e quando não acha nada di-lo por palavras: «' + nada + '»');
  await proc.close();

  // ============ 12. movimento reduzido ============
  // Quem pediu ao sistema para parar o movimento pediu-o a sério: numa festa
  // com luzes a piscar, uma pastilha a pulsar é o que faltava.
  const parado = await casa.newPage();     // com sessão, e com o movimento cortado
  await parado.setViewportSize({ width: 390, height: 844 });
  await parado.emulateMedia({ reducedMotion: 'reduce' });
  vigiar(parado, 'movimento');
  await parado.goto(BASE + '/copa.php', { waitUntil: 'networkidle' });
  await parado.waitForTimeout(1200);
  const mexem = await parado.evaluate(() => {
    const maus = [];
    document.querySelectorAll('*').forEach(el => {
      const cs = getComputedStyle(el);
      const dur = (s) => Math.max(...String(s).split(',').map(x => parseFloat(x) || 0));
      if (dur(cs.animationDuration) > 0 || dur(cs.transitionDuration) > 0) {
        maus.push(el.className || el.tagName);
      }
    });
    return maus;
  });
  ok(mexem.length === 0, 'com o movimento reduzido, nada se move'
     + (mexem.length ? ': ' + [...new Set(mexem)].slice(0, 4).join(' · ') : ''));
  await parado.close();

  // ============ arrumar ============
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of e.fila) {
      await window.api(x.estado === 'em_analise' ? 'bar_decidir' : 'bar_cancelar_copa',
        { method: 'POST', body: JSON.stringify({ id: x.id, decisao: 'recusar',
                                                 motivo_texto: 'arrumar a prova' }) });
    }
    for (const i of (e.itens || []).filter(i => /^ZZD /.test(i.nome))) {
      await window.api('bar_item_apagar', { method: 'POST', body: JSON.stringify({ id: i.id }) });
    }
    await window.api('bar_fechar', { method: 'POST', body: '{}' });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
