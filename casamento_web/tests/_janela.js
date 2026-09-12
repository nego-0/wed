// Responder à janela da casa, nas provas.
//
// Os editores deixaram de usar confirm()/prompt(): as perguntas passaram a ser
// a janela de assets/janela.js. Uma prova que antes fazia
//
//     p.on('dialog', d => d.accept('Prova'))
//
// tem agora de esperar pela janela e carregar no botão. É sempre o mesmo gesto,
// e por isso vive aqui: espalhá-lo por dez ficheiros era pedir que dez ficheiros
// mudassem da próxima vez.
//
// A diferença que interessa: o confirm() do browser respondia SOZINHO, mesmo
// que a página não o esperasse. A janela é da página — se ela não abrir, a
// prova pára aqui, e é isso que se quer. Um `esperar: false` diz «pode não
// aparecer», para os sítios onde a pergunta depende do estado.

const SEL = '#lic-janela.on';

/**
 * Espera pela janela e confirma.
 *
 * @param page          a página do Playwright
 * @param opcoes.campos {id: valor} para preencher (ex.: { nome: 'Prova' })
 * @param opcoes.nome   atalho para o campo 'nome', que é o caso comum
 * @param opcoes.esperar  false = se não abrir janela nenhuma, segue em frente
 * @param opcoes.timeout  quanto esperar pela janela (por omissão 5s)
 * @returns true se respondeu a uma janela, false se não apareceu nenhuma
 */
async function confirmar(page, opcoes = {}) {
  const espera = opcoes.esperar !== false;
  try {
    await page.waitForSelector(SEL, { timeout: opcoes.timeout || 5000 });
  } catch (e) {
    if (espera) throw new Error('Esperava-se uma janela e não abriu nenhuma.');
    return false;
  }
  const campos = Object.assign({}, opcoes.campos);
  if (opcoes.nome !== undefined) campos.nome = opcoes.nome;
  for (const [id, valor] of Object.entries(campos)) {
    const sel = '#lf-' + id;
    if (await page.locator(sel).count()) await page.fill(sel, String(valor));
  }
  await page.click('#lic-jo');
  await page.waitForTimeout(opcoes.pausa || 400);
  return true;
}

/** Fecha a janela sem confirmar — a resposta «não». */
async function cancelar(page, opcoes = {}) {
  try {
    await page.waitForSelector(SEL, { timeout: opcoes.timeout || 5000 });
  } catch (e) {
    if (opcoes.esperar !== false) throw new Error('Esperava-se uma janela e não abriu nenhuma.');
    return false;
  }
  await page.click('#lic-jc');
  await page.waitForTimeout(200);
  return true;
}

/** Está uma janela aberta neste momento? */
function aberta(page) { return page.locator(SEL).isVisible(); }

/**
 * Conta as janelas NATIVAS que aparecerem daqui em diante.
 *
 * Serve para provar o que não deve acontecer: nos editores já convertidos, o
 * número tem de ficar em zero. Devolve uma função que lê o contador.
 */
function contarNativas(page) {
  const n = { v: 0 };
  page.on('dialog', d => { n.v++; d.dismiss().catch(() => {}); });
  return () => n.v;
}

/**
 * Responde SOZINHA a todas as janelas, como o `on('dialog')` fazia.
 *
 * É o substituto directo de
 *
 *     p.on('dialog', d => d.accept(d.type() === 'prompt' ? 'Prova' : undefined))
 *
 * nas provas dos editores, que não estão a estudar as janelas — estão a passar
 * por elas para chegar ao que querem provar. Vive DENTRO da página (um
 * observador no #lic-janela), e não do lado do Playwright, para que um
 * `evaluate(() => guardar())` continue a resolver: a janela é respondida no
 * mesmo tique em que abre, sem a prova ter de saber que ela existiu.
 *
 * Instala-se com addInitScript, e por isso sobrevive às navegações.
 *
 * @param page
 * @param nome  o que escrever nos campos de texto (o nome da versão, em regra)
 */
async function autoResponder(page, nome = 'Prova') {
  await page.addInitScript((txt) => {
    const responder = () => {
      const m = document.getElementById('lic-janela');
      if (!m || !m.classList.contains('on')) return;
      m.querySelectorAll('.pl-modal-corpo input[type=text], .pl-modal-corpo textarea')
       .forEach(el => { if (!el.value) el.value = txt; });
      const ok = document.getElementById('lic-jo');
      if (ok && !ok.disabled) ok.click();
    };
    // O #lic-janela nasce quando a primeira janela abre: observa-se o body até
    // ele existir, e depois observa-se o próprio elemento.
    const obsBody = new MutationObserver(() => {
      const m = document.getElementById('lic-janela');
      if (!m || m.__auto) return;
      m.__auto = true;
      new MutationObserver(responder)
        .observe(m, { attributes: true, attributeFilter: ['class'] });
      responder();
    });
    const arrancar = () => obsBody.observe(document.body, { childList: true });
    if (document.body) arrancar();
    else document.addEventListener('DOMContentLoaded', arrancar);
  }, nome);
}

module.exports = { confirmar, cancelar, aberta, contarNativas, autoResponder, SEL };
