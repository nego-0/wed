// O detalhe de uma mesa surge com movimento leve e rolagem suave tanto a
// partir da lista como da planta, no monitor e no telemóvel.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let falhas = 0;
  const ok = (condicao, mensagem) => {
    console.log((condicao ? 'PASS' : 'FAIL') + ': ' + mensagem);
    if (!condicao) falhas++;
  };

  for (const largura of [1440, 390]) {
    const mobile = largura < 700;
    const context = await browser.newContext({
      viewport: { width: largura, height: mobile ? 844 : 900 },
      isMobile: mobile,
      hasTouch: mobile,
    });
    const page = await context.newPage();
    const erros = [];
    page.on('pageerror', erro => erros.push(erro.message));

    await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await page.fill('[name=utilizador]', 'admin');
    await page.fill('[name=senha]', 'noivos2026');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    await page.evaluate(async () => {
      await fetch('api.php?action=casamento_abrir&id=1', {
        method: 'POST', headers: { 'X-CSRF-Token': window.CSRF }
      });
    });
    await page.goto(BASE + '/mesas.php', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);

    const criado = await page.evaluate(async () => {
      const r = await fetch('api.php?action=mesa_save', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
        body: JSON.stringify({ nome: 'ZZ detalhe animado', capacidade: 6,
                               forma: 'redonda', cor: 'ouro' })
      });
      return r.json();
    });
    const id = +criado.id;
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);

    await page.evaluate(() => {
      window.__rolagensDetalhe = [];
      const scrollToOriginal = Element.prototype.scrollTo;
      Element.prototype.scrollTo = function (opcoes) {
        if (this.id === 'tab-body') window.__rolagensDetalhe.push({ tipo: 'painel', opcoes });
        return scrollToOriginal.call(this, opcoes);
      };
      const scrollIntoOriginal = Element.prototype.scrollIntoView;
      Element.prototype.scrollIntoView = function (opcoes) {
        if (this.classList && this.classList.contains('mesa-detalhe')) {
          window.__rolagensDetalhe.push({ tipo: 'pagina', opcoes });
        }
        return scrollIntoOriginal.call(this, opcoes);
      };
    });

    const linha = page.locator('.lm-linha[data-id="' + id + '"]');
    await linha.scrollIntoViewIfNeeded();
    await linha.click();
    await page.waitForTimeout(60);
    let estado = await page.evaluate(i => ({
      abriu: !!document.querySelector('.mesa-detalhe[data-mesa-id="' + i + '"]'),
      animou: !!document.querySelector('.mesa-detalhe.a-surgir'),
      duracao: parseFloat(getComputedStyle(document.querySelector('.mesa-detalhe')).animationDuration) * 1000,
      suave: window.__rolagensDetalhe.some(x => x.opcoes && x.opcoes.behavior === 'smooth')
    }), id);
    ok(estado.abriu && estado.animou,
       `${largura}px: a lista abre o detalhe com a animação leve`);
    ok(estado.suave, `${largura}px: a lista usa rolagem suave`);
    ok(estado.duracao >= 1000,
       `${largura}px: o fading do detalhe dura pelo menos um segundo`);

    // Ao abrir a mesa vê-se primeiro quem lá está sentado: as opções de edição
    // dobram-se atrás do cabeçalho «Editar mesa» (fechado de início) e a lista
    // de sentados fica logo por baixo.
    const dobra = await page.evaluate(() => {
      const d = document.getElementById('editar-mesa-dobra');
      const corpo = d && d.querySelector('.editar-mesa-corpo');
      const edNome = document.getElementById('ed-nome');
      const sum = d && d.querySelector('.editar-mesa-sum');
      const rotLista = [...document.querySelectorAll('.mesa-detalhe .rot')]
          .find(r => /Pessoas nesta mesa/i.test(r.textContent));
      const listaDepois = d && rotLista
          ? (d.compareDocumentPosition(rotLista) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0 : false;
      return { tem: !!d, fechado: d ? !d.open : null,
               rotulo: sum ? sum.textContent.trim() : '',
               editaDentro: !!(corpo && edNome && corpo.contains(edNome)),
               listaDepois };
    });
    ok(dobra.tem && dobra.fechado,
       `${largura}px: a mesa abre com «Editar mesa» dobrado, não com os campos à mostra`);
    ok(/editar mesa/i.test(dobra.rotulo),
       `${largura}px: o cabeçalho que dobra diz «Editar mesa» («${dobra.rotulo}»)`);
    ok(dobra.editaDentro,
       `${largura}px: os campos de edição vivem dentro da dobra`);
    ok(dobra.listaDepois,
       `${largura}px: a lista de sentados fica por baixo da dobra, como já estava`);

    // Fecha pelo próprio desenho e volta a abrir a partir da planta.
    const no = page.locator('.mesa-node[data-id="' + id + '"]');
    await no.scrollIntoViewIfNeeded();
    await no.click({ position: { x: 6, y: 6 } });
    await page.waitForTimeout(80);
    await page.evaluate(() => { window.__rolagensDetalhe = []; });
    await no.click({ position: { x: 6, y: 6 } });
    await page.waitForTimeout(80);
    estado = await page.evaluate(i => ({
      abriu: !!document.querySelector('.mesa-detalhe[data-mesa-id="' + i + '"]'),
      animou: !!document.querySelector('.mesa-detalhe.a-surgir'),
      duracao: parseFloat(getComputedStyle(document.querySelector('.mesa-detalhe')).animationDuration) * 1000,
      suave: window.__rolagensDetalhe.some(x => x.opcoes && x.opcoes.behavior === 'smooth'),
      pagina: window.__rolagensDetalhe.some(x => x.tipo === 'pagina')
    }), id);
    ok(estado.abriu && estado.animou,
       `${largura}px: a planta abre o mesmo detalhe com a animação`);
    ok(estado.suave, `${largura}px: a planta usa rolagem suave`);
    ok(estado.duracao >= 1000,
       `${largura}px: a planta mantém o fading de pelo menos um segundo`);
    if (mobile) ok(estado.pagina, '390px: a planta traz o detalhe à área visível');

    await page.evaluate(async i => {
      await fetch('api.php?action=mesa_delete&id=' + i, {
        method: 'POST', headers: { 'X-CSRF-Token': window.CSRF }
      });
    }, id);
    ok(erros.length === 0, `${largura}px: nenhum erro de JavaScript`);
    await context.close();
  }

  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(erro => { console.error(erro); process.exit(1); });
