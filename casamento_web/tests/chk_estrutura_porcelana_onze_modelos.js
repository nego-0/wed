// Os onze documentos conservam a sua identidade visual, mas partilham a
// estrutura e o conteúdo semântico canónicos da Porcelana V2 Rosa.
const assert = require('node:assert/strict');
const { chromium } = require('playwright-core');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8926';
const EXE = process.env.CHROMIUM || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const CANONICO = 'porcelain-v2-pink';
const ESPERADOS = [
  'porcelain-v2-green', 'porcelain-v2-pink', 'jasmine-white',
  'royal-v2-green', 'spring-garden-blue', 'double-happiness-green',
  'porcelain-brown', 'royal-blue', 'hoa-kho-orange', 'mahal-gold',
  'lien-hoa-pink'
];

function limpar(texto) {
  return String(texto || '').replace(/\s+/g, ' ').trim();
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true });
  try {
    const contexto = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const pagina = await contexto.newPage();
    await pagina.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await pagina.fill('[name=utilizador]', process.env.TEST_USER || 'admin');
    await pagina.fill('[name=senha]', process.env.TEST_PASSWORD || 'noivos2026');
    await pagina.click('button[type=submit]');
    await pagina.waitForLoadState('networkidle');

    const resposta = await pagina.evaluate(() => fetch('api.php?action=modelo_lista&ambito=digital').then(r => r.json()));
    const modelos = (resposta.modelos || []).filter(m => ESPERADOS.includes(m.renderer_key));
    assert.equal(modelos.length, 11, 'o catálogo deve conter os onze modelos exactos');
    modelos.sort((a, b) => (a.renderer_key === CANONICO ? -1 : b.renderer_key === CANONICO ? 1 : 0));

    let referencia = null;
    const identidades = new Set();
    for (const modelo of modelos) {
      const erros = [];
      const aoErro = erro => erros.push(erro.message);
      pagina.on('pageerror', aoErro);
      await pagina.goto(`${BASE}/convite-digital.php?demo=1&modelo=${modelo.id}`, { waitUntil: 'domcontentloaded' });
      await pagina.waitForFunction(() => !!window.KulembaExactComponents && !!document.querySelector('[data-exact-canonical="1"]'));

      const prova = await pagina.evaluate(() => {
        const can = Array.from(document.querySelectorAll('[data-exact-canonical="1"]'));
        const pares = (base, selector) => Array.from(base.querySelectorAll(selector)).map(e => [
          e.getAttribute('data-def'), e.textContent.replace(/\s+/g, ' ').trim()
        ]);
        const secao = id => can.find(e => e.dataset.sec === id);
        const galeria = secao('galeria'), cerimonia = secao('grande-dia'), recepcao = secao('recepcao');
        const programa = secao('cronograma'), acesso = secao('acesso'), confirmacao = secao('confirmacao');
        const root = document.querySelector('[data-showcase-root]');
        const cover = document.querySelector('#cover'), textoCapa = cover.textContent.replace(/\s+/g, ' ').trim();
        return {
          estilo: document.body.dataset.estilo,
          testid: root && root.closest('[data-testid$="-template"]')?.getAttribute('data-testid'),
          identidade: root ? [getComputedStyle(root).backgroundColor, getComputedStyle(root).color].join('|') : '',
          sequencia: can.map(e => e.dataset.sec),
          tags: can.map(e => e.tagName),
          pais: new Set(can.map(e => e.parentElement)).size,
          envelope: {
            canonico: cover?.dataset.exactEnvelopeCanonical === '1',
            titulos: Array.from(cover.querySelectorAll('h1,h2,h3,p')).map(e => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
            data: (textoCapa.match(/\d{1,2}\s+de\s+[A-Za-zÀ-ÿ]+\s+de\s+\d{4}/) || [''])[0],
            accao: Array.from(cover.querySelectorAll('a,button')).map(e => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
            origem: /Família Fernandes|Há amores que, como o amanhecer/i.test(textoCapa)
          },
          capa: {
            canonica: document.querySelector('[data-exact-invitation-cover-canonical="1"]') !== null,
            semantica: document.querySelector('.exact-cover-semantic')?.textContent || '',
            partesVisiveis: ['reserva', 'data', 'noivo', 'noiva', 'verso'].every(parte => {
              const el = document.querySelector(`[data-exact-cover-part="${parte}"]`);
              return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
            }),
            origem: /É convidado para|Vamos nos casar|O amor nunca falha|\bNoivo\b|\bNoiva\b/i.test(document.querySelector('[data-exact-invitation-cover-canonical="1"]')?.textContent || '')
          },
          galeria: {
            titulos: Array.from(galeria.querySelectorAll(':scope > p,h1,h2,h3')).map(e => e.textContent.replace(/\s+/g, ' ').trim()),
            fotos: document.querySelectorAll('[data-kulemba-galeria="1"]:not(dialog *)').length,
            pontos: document.querySelectorAll('button[aria-label^="Ir para a fotografia"]').length,
            fotosFora: Array.from(document.querySelectorAll('[data-kulemba-galeria="1"]:not(dialog *)')).filter(img => !galeria.contains(img)).length
          },
          cerimonia: pares(cerimonia, '[data-def]').concat(Array.from(cerimonia.querySelectorAll('.kvp-cer-card')).map(e => ['cerimonia', e.textContent.replace(/\s+/g, ' ').trim()])),
          recepcao: pares(recepcao, '[data-def]'),
          programa: Array.from(programa.querySelectorAll('li')).map(e => e.textContent.replace(/\s+/g, ' ').trim()),
          acesso: pares(acesso, '[data-def]').concat([['convidado', acesso.querySelector(':scope > strong')?.textContent.replace(/\s+/g, ' ').trim() || '']]),
          confirmacao: Array.from(confirmacao.querySelectorAll('.exact-richtext,[data-kulemba="confirmacao"]')).map(e => e.textContent.replace(/\s+/g, ' ').trim()),
          unicas: {
            cerimonia: document.querySelectorAll('.exact-semantic-ceremony').length,
            recepcao: document.querySelectorAll('.exact-semantic-reception').length,
            programa: document.querySelectorAll('.exact-semantic-program').length
          },
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          conteudoOrigem: /Aaron Lim|Hui Ling|Hannah Hoffmann|Maximilian|Tanjong|Elbspeicher|Number of guests/i.test(document.body.innerText)
        };
      });

      assert.equal(prova.estilo, modelo.renderer_key, modelo.nome + ': conserva o renderer próprio');
      assert(prova.testid, modelo.nome + ': conserva o documento visual de origem');
      identidades.add(modelo.renderer_key + '|' + prova.identidade);
      assert.deepEqual(prova.sequencia, ['galeria', 'grande-dia', 'recepcao', 'cronograma', 'acesso', 'confirmacao'], modelo.nome + ': sequência canónica');
      assert.deepEqual(prova.tags, ['SECTION', 'SECTION', 'SECTION', 'SECTION', 'SECTION', 'SECTION'], modelo.nome + ': secções homogéneas');
      assert.equal(prova.pais, 1, modelo.nome + ': secções no mesmo fluxo visual');
      assert.equal(prova.envelope.canonico, true, modelo.nome + ': envelope marcado como canónico');
      assert.equal(prova.envelope.origem, false, modelo.nome + ': envelope sem conteúdo adicional do documento importado');
      assert.equal(prova.capa.canonica, true, modelo.nome + ': capa interna marcada como canónica');
      assert.equal(prova.capa.partesVisiveis, true, modelo.nome + ': reserva, data, casal e verso visíveis na capa interna');
      assert.equal(prova.capa.origem, false, modelo.nome + ': capa interna sem texto divergente do documento importado');
      assert.deepEqual(prova.unicas, { cerimonia: 1, recepcao: 1, programa: 1 }, modelo.nome + ': conteúdo sem duplicações');
      assert.equal(prova.galeria.fotos, 4, modelo.nome + ': quatro fotografias semânticas');
      assert.equal(prova.galeria.pontos, 4, modelo.nome + ': quatro controlos da galeria');
      assert.equal(prova.galeria.fotosFora, 0, modelo.nome + ': fotografias dentro da secção de galeria');
      assert.equal(prova.overflow <= 1, true, modelo.nome + ': sem transbordo mobile');
      assert.equal(prova.conteudoOrigem, false, modelo.nome + ': sem texto fictício do documento importado');

      const conteudo = {
        envelope: prova.envelope,
        capa: prova.capa.semantica,
        galeria: prova.galeria.titulos.map(limpar),
        cerimonia: prova.cerimonia,
        recepcao: prova.recepcao,
        programa: prova.programa,
        acesso: prova.acesso,
        confirmacao: prova.confirmacao
      };
      if (!referencia) referencia = conteudo;
      else assert.deepEqual(conteudo, referencia, modelo.nome + ': conteúdo igual à Porcelana V2 Rosa');

      await pagina.setViewportSize({ width: 1440, height: 1000 });
      assert.equal(await pagina.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), true, modelo.nome + ': sem transbordo desktop');
      await pagina.setViewportSize({ width: 390, height: 844 });
      assert.deepEqual(erros, [], modelo.nome + ': sem erros de JavaScript');
      pagina.off('pageerror', aoErro);
      console.log('PASS — ' + modelo.nome + ' partilha a estrutura e o conteúdo da Porcelana V2 Rosa.');
    }
    assert.equal(identidades.size, 11, 'os onze pacotes continuam identificáveis pelo seu estilo');
    console.log('\nOs onze modelos possuem a estrutura e o conteúdo canónicos da Porcelana V2 Rosa.');
  } finally {
    await browser.close();
  }
})().catch(erro => { console.error(erro); process.exit(1); });
