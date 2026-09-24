/* ============================================================
   escolhas.js — escolher numa lista da casa, dentro de uma prova

   Desde que a escolha com procura passou a vestir TODOS os <select> do
   sistema, o <select> nativo continua lá — é ele que guarda o valor e é ele
   que as provas continuam a ler — mas está escondido por baixo do componente.
   Uma prova não pode carregar no que não se vê, tal como ninguém pode.

   Escolhe-se aqui como uma pessoa escolhe: abre-se a lista e carrega-se na
   linha. Quem só quiser LER o valor continua a lê-lo do <select> de sempre.
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

async function abrirEEscolher(p, cx, valor) {
  await cx.locator('.lic-sel-bt').click();
  await p.waitForTimeout(250);
  await cx.locator('.lic-sel-op[data-v="' + valor + '"]').first().click();
  await p.waitForTimeout(250);
}

/**
 * @param {import('playwright-core').Page} p
 * @param {string} sel   o campo, como sempre se escreveu: '#a-papel', '#lf-modo'
 * @param {string} valor o valor da opção (o `value` do <option>)
 */
async function escolher(p, sel, valor) {
  await abrirEEscolher(p, caixaDe(p.locator(sel).first()), valor);
}

/** O mesmo, quando o <select> já vem num locator (filtrado por texto, p. ex.). */
async function escolherNo(p, loc, valor) {
  await abrirEEscolher(p, caixaDe(loc.first()), valor);
}

/** Pelo texto da linha, quando o valor não se sabe de fora. */
async function escolherTexto(p, sel, texto) {
  const cx = caixaDe(p.locator(sel).first());
  await cx.locator('.lic-sel-bt').click();
  await p.waitForTimeout(250);
  await cx.locator('.lic-sel-op', { hasText: texto }).first().click();
  await p.waitForTimeout(250);
}

module.exports = { escolher, escolherNo, escolherTexto };
