// Os formulários do administrador devem rolar como os do Orçamento: o cartão
// inteiro move-se, incluindo o título e os botões, sem faixas presas ao ecrã.
const { chromium } = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const RAIZ = path.join(__dirname, '..');
const css = ['assets/modais.css', 'assets/estilo.css', 'assets/janela.css']
  .map(f => fs.readFileSync(path.join(RAIZ, f), 'utf8')).join('\n');

const miolo = Array.from({ length: 18 }, (_, i) =>
  `<label>Campo ${i + 1}<input value="Valor ${i + 1}"></label>`).join('');

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, headless: true });
  let falhas = 0;
  const ok = (v, m) => { console.log((v ? 'PASS' : 'FAIL') + ': ' + m); if (!v) falhas++; };

  for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 660 }]) {
    const page = await browser.newPage({ viewport });
    await page.setContent(`<style>${css}</style><body class="admin-plataforma">
      <div class="overlay aberto" id="legado"><div class="modal">
        <div class="modal-topo"><h3>Editar casamento</h3><button class="fechar">×</button></div>
        <div class="modal-corpo">${miolo}<div class="fim"><button>Fechar</button><button>Guardar</button></div></div>
      </div></div>
      <div class="pl-modal" id="novo"><div class="pl-modal-cx">
        <div class="pl-modal-cab"><h3>Definições</h3><button class="pl-modal-x">×</button></div>
        <div class="pl-modal-corpo">${miolo}</div>
        <div class="pl-modal-rodape"><button class="j-bt">Cancelar</button><button class="j-bt">Guardar</button></div>
      </div></div></body>`);

    const largura = viewport.width < 500 ? 'mobile' : 'desktop';
    const legado = await page.evaluate(() => {
      const cx = document.querySelector('#legado .modal');
      const cab = cx.querySelector('.modal-topo');
      const antes = cab.getBoundingClientRect().top;
      const pos = getComputedStyle(cab).position;
      cx.scrollTop = Math.floor(cx.scrollHeight / 2);
      const depois = cab.getBoundingClientRect().top;
      cx.scrollTop = cx.scrollHeight;
      const fim = cx.querySelector('.fim').getBoundingClientRect();
      const caixa = cx.getBoundingClientRect();
      return { pos, antes, depois, rola: cx.scrollHeight > cx.clientHeight,
        fimVisivel: fim.bottom <= caixa.bottom + 1 && fim.top >= caixa.top - 1,
        cabe: caixa.top >= 0 && caixa.bottom <= innerHeight + 1 };
    });
    ok(legado.pos !== 'sticky' && legado.depois < legado.antes,
      `${largura}: o cabeçalho do formulário antigo acompanha a rolagem`);
    ok(legado.rola && legado.fimVisivel && legado.cabe,
      `${largura}: o formulário antigo rola por inteiro e chega aos botões`);

    await page.evaluate(() => {
      document.getElementById('legado').classList.remove('aberto');
      document.getElementById('novo').classList.add('on');
    });
    const novo = await page.evaluate(() => {
      const cx = document.querySelector('#novo .pl-modal-cx');
      const cab = cx.querySelector('.pl-modal-cab');
      const corpo = cx.querySelector('.pl-modal-corpo');
      const antes = cab.getBoundingClientRect().top;
      cx.scrollTop = Math.floor(cx.scrollHeight / 2);
      const depois = cab.getBoundingClientRect().top;
      cx.scrollTop = cx.scrollHeight;
      const fim = cx.querySelector('.pl-modal-rodape').getBoundingClientRect();
      const caixa = cx.getBoundingClientRect();
      return { display: getComputedStyle(cx).display, overflow: getComputedStyle(cx).overflowY,
        corpoOverflow: getComputedStyle(corpo).overflowY, antes, depois,
        rola: cx.scrollHeight > cx.clientHeight,
        fimVisivel: fim.bottom <= caixa.bottom + 1 && fim.top >= caixa.top - 1,
        cabe: caixa.top >= 0 && caixa.bottom <= innerHeight + 1 };
    });
    ok(novo.display === 'block' && novo.corpoOverflow === 'visible',
      `${largura}: o formulário novo usa um único fluxo vertical`);
    ok(novo.overflow !== 'visible' && novo.rola && novo.depois < novo.antes,
      `${largura}: título e conteúdo do formulário novo rolam juntos`);
    ok(novo.fimVisivel && novo.cabe,
      `${largura}: o rodapé novo acompanha o formulário e permanece alcançável`);
    await page.close();
  }

  await browser.close();
  process.exit(falhas ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
