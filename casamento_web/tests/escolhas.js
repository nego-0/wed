/* ============================================================
   escolhas.js — escolher numa lista da casa, dentro de uma prova

   O motor das listas é o Select2 (ver assets/janela.js e
   docs/bar-motor-assistido.md §5.1). O <select> continua lá — é ele que guarda
   o valor e é ele que as provas continuam a LER —, mas está escondido por
   baixo do componente. Uma prova não pode carregar no que não se vê, tal como
   ninguém pode.

   Escolhe-se aqui como uma pessoa escolhe: abre-se a lista e carrega-se na
   linha. Quem só quiser ler o valor continua a lê-lo do <select> de sempre
   (`page.inputValue('#lf-x')`), que é o que interessa provar.

   DUAS COISAS QUE CUSTAM TEMPO A DESCOBRIR, e por isso ficam escritas:

   1. A lista NÃO vive dentro da caixa do campo. O Select2 pendura-a no <body>
      (ou no modal, quando o campo está numa janela), e por isso um
      `caixa.locator('.select2-results__option')` nunca encontra nada. A lista
      aberta é global — só há uma de cada vez — e é assim que se lhe chega.

   2. Não há um `data-valor` nas linhas. O Select2 identifica-as por um id
      gerado, que não se pode escrever à mão numa prova. O caminho honesto é o
      que uma pessoa faz: descobrir o TEXTO daquele valor (lê-se do <option>,
      que está lá) e carregar na linha que o mostra.
   ============================================================ */

/**
 * A caixa que veste um campo, a partir do próprio campo.
 *
 * Pelo antepassado, e não por `.lic-sel:has(#x)`: o `:has()` do Playwright lê o
 * que está lá dentro COMO RELATIVO à caixa, e por isso um selector com id
 * («#props select») nunca casa — o #props está por fora dela. Custou uma
 * corrida inteira a perceber.
 */
function caixaDe(loc) {
  return loc.locator('xpath=ancestor::div[contains(@class,"lic-sel")][1]');
}

/** O campo está vestido pelo Select2, ou ficou o <select> do sistema? */
async function estaVestido(loc) {
  return await loc.evaluate(el => el.classList.contains('select2-hidden-accessible'))
    .catch(() => false);
}

/** Abrir a lista de um campo e esperar que ela esteja mesmo aberta. */
async function abrir(p, loc) {
  await caixaDe(loc).locator('.select2-selection').first().click();
  await p.locator('.select2-container--open .select2-results__option').first()
         .waitFor({ state: 'visible', timeout: 5000 });
}

/** Carregar na linha com este texto exacto. */
async function carregarEm(p, texto) {
  const linha = p.locator('.select2-container--open .select2-results__option')
                 .filter({ hasText: texto }).first();
  await linha.waitFor({ state: 'visible', timeout: 5000 });
  await linha.click();
  await p.waitForTimeout(250);
}

async function escolherNoLocator(p, loc, valor) {
  // Um campo que não foi vestido continua a ser um <select> a sério, e a esse
  // chega-se pelo caminho de sempre. Acontece nas páginas que não carregam o
  // Select2 — e é bom que a prova passe nas duas.
  if (!(await estaVestido(loc))) { await loc.selectOption(String(valor)); return; }
  const texto = (await loc.locator('option[value="' + valor + '"]').first()
                          .textContent()).trim();
  await abrir(p, loc);
  await carregarEm(p, texto);
}

/**
 * @param {import('playwright-core').Page} p
 * @param {string} sel   o campo, como sempre se escreveu: '#a-papel', '#lf-modo'
 * @param {string} valor o valor da opção (o `value` do <option>)
 */
async function escolher(p, sel, valor) {
  await escolherNoLocator(p, p.locator(sel).first(), valor);
}

/** O mesmo, quando o <select> já vem num locator (filtrado por texto, p. ex.). */
async function escolherNo(p, loc, valor) {
  await escolherNoLocator(p, loc.first(), valor);
}

/** Pelo texto da linha, quando o valor não se sabe de fora. */
async function escolherTexto(p, sel, texto) {
  const loc = p.locator(sel).first();
  if (!(await estaVestido(loc))) {
    await loc.selectOption({ label: texto });
    return;
  }
  await abrir(p, loc);
  await carregarEm(p, texto);
}

/**
 * Escrever na procura da lista aberta, como quem procura de pé.
 *
 * Devolve quantas linhas sobraram — é isso que as provas do bar querem saber:
 * que escrever três letras corta a lista, e que corta sem olhar a acentos.
 */
async function procurarNaLista(p, loc, termo) {
  await abrir(p, loc);
  const campo = p.locator('.select2-container--open .select2-search__field');
  await campo.fill(termo);
  await p.waitForTimeout(400);
  return await p.locator('.select2-container--open .select2-results__option').count();
}

/** As linhas visíveis na lista aberta, em texto. */
async function linhasDaLista(p) {
  return await p.$$eval('.select2-container--open .select2-results__option',
                        ns => ns.map(n => n.textContent.trim()));
}

/** O que a caixa fechada mostra — o rótulo da opção escolhida. */
async function rotuloDe(p, loc) {
  const cx = caixaDe(loc.first ? loc.first() : loc);
  const r = cx.locator('.select2-selection__rendered').first();
  if (await r.count()) return (await r.innerText()).trim();
  // Sem Select2, o rótulo é o da opção escolhida no <select>.
  return await (loc.first ? loc.first() : loc)
    .evaluate(el => (el.options[el.selectedIndex] || {}).textContent || '');
}

/** Fechar a lista aberta sem escolher nada. */
async function fecharLista(p) {
  await p.keyboard.press('Escape');
  await p.waitForTimeout(250);
}

module.exports = { escolher, escolherNo, escolherTexto, caixaDe,
                   procurarNaLista, linhasDaLista, rotuloDe, fecharLista, abrir };
