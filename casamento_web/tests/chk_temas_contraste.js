// O texto lê-se nos QUATRO temas, em todas as páginas.
//
// O tema escuro estava a esconder 273 pedaços de texto, e o cabeçalho — o
// subtítulo, o nome do casal, a contagem e o monograma — estava ilegível nas
// treze páginas de uma vez. Três defeitos de raiz, e vale a pena tê-los
// escritos porque se repetem:
//
//   1. UM TOKEN COM DOIS PAPÉIS. `--gold-pale` é um creme nos temas claros e
//      uma SUPERFÍCIE escura no tema escuro. Quem o usou como cor de texto
//      sobre o cabeçalho ficou com tinta escura sobre fundo escuro (1,24:1).
//      O cabeçalho é escuro nos quatro temas — é a única superfície da casa
//      que não vira —, por isso passou a ter tokens só dele: --topo-txt e
//      --topo-acento.
//   2. COR CRAVADA À MÃO. 73 fundos brancos e uma mão-cheia de cinzentos
//      escritos directamente nas folhas de cada página. Num tema claro não se
//      dá por eles; no escuro são ilhas brancas com texto claro por cima.
//   3. --ivory E --forest-deep NÃO VIRAM. São a tinta sempre clara e a tinta
//      sempre escura, e só podem ser usadas UMA CONTRA A OUTRA. Emparelhar
//      --ivory (fundo) com --ink (texto) dava 1,02:1 no escuro — era o nome
//      da mesa na planta.
//
// Esta prova mede PIXÉIS PINTADOS, e não `background-color`: a casa pinta com
// gradientes, e um elemento sem cor própria tem `transparent` ali. E quando o
// texto e o fundo são quase iguais — que é o pior caso — não desiste: conta
// os pixéis todos, porque foi a desistir que a primeira versão desta sonda
// ficou cega ao defeito do cabeçalho.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const TEMAS = ['niras', 'classico', 'azul', 'escuro'];

const lum = (r, g, b) => {
  const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
const razao = (a, b) => { const [x, y] = a > b ? [a, b] : [b, a]; return (x + 0.05) / (y + 0.05); };

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  }).catch(() => {});

  // A carta do bar abre-se PELO ENDEREÇO DA FESTA, com a mesa por cima: sem
  // ele dá o recado de «endereço não serve», e foi assim que ela escapou à
  // primeira passagem desta prova. Lida de uma coluna vazia ou de um código
  // que já não resolve, esta prova media as cores do RECADO e dizia que tinha
  // medido a carta.
  const bar = await p.evaluate(async () => {
    const d = await (await fetch('api.php?action=mesa_list')).json().catch(() => null);
    const m = (d && (d.mesas || [])).find(x => x.bar_token);
    return m ? m.bar_token : null;
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const linkFesta = await p.evaluate(() => window.BAR_LINK_FESTA || '');
  const mesa = bar && linkFesta
    ? linkFesta.replace(/^.*\//, '') + '&m=' + encodeURIComponent(bar) : null;
  // manual.php e versao.php faltavam a esta lista, e é por isso que nunca
  // ninguém mediu o que lá está escrito: «todas as páginas» quer dizer todas.
  const PAGINAS = ['index.php', 'mesas.php', 'digital.php', 'impressos.php', 'orcamento.php',
                   'bar.php', 'gestao.php', 'licenca.php', 'modelos.php', 'plataforma.php',
                   'graficas.php', 'cartoes.php', 'copa.php', 'entregas.php', 'porteiro.php',
                   'manual.php', 'versao.php']
                  .concat(mesa ? [mesa] : []);
  ok(!!mesa, 'há uma mesa com código de bar, para a carta do convidado entrar na prova: '
     + mesa);

  const falhas = [];
  for (const tema of TEMAS) {
    for (const pag of PAGINAS) {
      const r = await p.goto(BASE + '/' + pag, { waitUntil: 'networkidle' }).catch(() => null);
      if (!r || !r.ok()) { falhas.push({ tema, pag, nota: 'não abriu' }); continue; }
      await p.evaluate(t => {
        document.documentElement.setAttribute('data-tema', t);
        try { localStorage.setItem('tema', t); } catch (e) {}
      }, tema);
      await p.waitForTimeout(800);

      const alvos = await p.evaluate(() => {
        const out = [];
        const anda = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const vistos = new Set();
        let n;
        while ((n = anda.nextNode())) {
          const t = (n.textContent || '').trim();
          if (t.length < 2) continue;
          const el = n.parentElement;
          if (!el || vistos.has(el)) continue;
          vistos.add(el);
          if (el.closest('[hidden]')) continue;
          const cs = getComputedStyle(el);
          if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) continue;
          const r = el.getBoundingClientRect();
          if (r.width < 4 || r.height < 4) continue;
          if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) continue;
          // A TINTA DE UM <text> DE SVG É O `fill`, e não o `color`.
          //
          // Num SVG o `color` é só o valor a que `currentColor` se refere: ele
          // pode estar em branco e o desenho sair cinzento-escuro, porque o
          // que pinta é o `fill`. A planta do salão escreve os números das
          // mesas assim, e esta prova lia-lhes o `color` — herdado do tema,
          // claro no modo escuro — contra um fundo claro, e acusava 1.08:1
          // numa etiqueta que na verdade se lê a 8:1. Era um erro de medição,
          // e do pior género: um alarme falso repetido ensina a ignorar o
          // alarme.
          const ehSvg = el.ownerSVGElement || el.tagName === 'svg';
          const tinta = ehSvg && cs.fill && cs.fill !== 'none' ? cs.fill : cs.color;
          const m = String(tinta).match(/\d+/g);
          if (!m) continue;
          const px = parseFloat(cs.fontSize) || 16, peso = parseInt(cs.fontWeight, 10) || 400;
          out.push({ cor: [+m[0], +m[1], +m[2]], px, peso,
            grande: px >= 24 || (px >= 18.66 && peso >= 700),
            caixa: { x: Math.round(r.left), y: Math.round(r.top),
                     w: Math.round(Math.min(r.width, innerWidth - r.left)),
                     h: Math.round(Math.min(r.height, innerHeight - r.top)) },
            texto: t.slice(0, 34),
            onde: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '')
                + (el.className && typeof el.className === 'string'
                    ? '.' + el.className.trim().split(/\s+/)[0] : '') });
        }
        return out;
      });

      const tiro = await p.screenshot({ type: 'png' });
      const fundos = await p.evaluate(async ({ dados, alvos }) => {
        const img = new Image();
        await new Promise(res => { img.onload = res; img.src = 'data:image/png;base64,' + dados; });
        const c = document.createElement('canvas');
        c.width = img.width; c.height = img.height;
        const x = c.getContext('2d', { willReadFrequently: true });
        x.drawImage(img, 0, 0);
        const dpr = img.width / innerWidth;
        return alvos.map(a => {
          const bx = Math.max(0, Math.round(a.caixa.x * dpr)), by = Math.max(0, Math.round(a.caixa.y * dpr));
          const bw = Math.max(1, Math.min(Math.round(a.caixa.w * dpr), c.width - bx));
          const bh = Math.max(1, Math.min(Math.round(a.caixa.h * dpr), c.height - by));
          const d = x.getImageData(bx, by, bw, bh).data;
          const conta = (excluir) => {
            const baldes = new Map();
            for (let i = 0; i < d.length; i += 4) {
              const r = d[i], g = d[i + 1], bl = d[i + 2];
              if (excluir && Math.abs(r - a.cor[0]) + Math.abs(g - a.cor[1])
                           + Math.abs(bl - a.cor[2]) < 90) continue;
              const k = (r >> 3) + ',' + (g >> 3) + ',' + (bl >> 3);
              const v = baldes.get(k) || { n: 0, r: 0, g: 0, b: 0 };
              v.n++; v.r += r; v.g += g; v.b += bl;
              baldes.set(k, v);
            }
            let melhor = null;
            for (const v of baldes.values()) if (!melhor || v.n > melhor.n) melhor = v;
            return melhor;
          };
          // Se quase nada sobrou depois de excluir a cor do texto, é porque o
          // fundo TEM a cor do texto. Conta-se tudo: é esse o caso a apanhar.
          let melhor = conta(true);
          if (!melhor || melhor.n < (bw * bh) * 0.08) melhor = conta(false);
          if (!melhor || melhor.n < 4) return null;
          return [Math.round(melhor.r / melhor.n), Math.round(melhor.g / melhor.n),
                  Math.round(melhor.b / melhor.n)];
        });
      }, { dados: tiro.toString('base64'), alvos });

      alvos.forEach((a, i) => {
        const fu = fundos[i]; if (!fu) return;
        const rz = razao(lum(a.cor[0], a.cor[1], a.cor[2]), lum(fu[0], fu[1], fu[2]));
        const minimo = a.grande ? 3 : 4.5;
        if (rz < minimo) falhas.push({ tema, pag, onde: a.onde, texto: a.texto,
                                       razao: +rz.toFixed(2), minimo });
      });
    }
  }

  console.log('   (medidas ' + PAGINAS.length + ' páginas × ' + TEMAS.length + ' temas)');
  if (falhas.length) {
    falhas.slice(0, 12).forEach(x => console.log('   ' + (x.nota || x.razao + ':1')
      + '  ' + x.tema + ' · ' + x.pag + ' · ' + (x.onde || '') + ' «' + (x.texto || '') + '»'));
  }
  ok(falhas.length === 0,
     'todo o texto se lê nos quatro temas: ' + falhas.length + ' falha(s)');

  // E o cabeçalho, que era o pior sítio, com nome e número.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  for (const tema of TEMAS) {
    await p.evaluate(t => document.documentElement.setAttribute('data-tema', t), tema);
    await p.waitForTimeout(350);
    const m = await p.evaluate(() => {
      const raiz = getComputedStyle(document.documentElement);
      const hex = n => raiz.getPropertyValue(n).trim();
      const rgb = s => { const m = s.match(/\w\w/g); return m ? m.map(x => parseInt(x, 16)) : null; };
      return { txt: rgb(hex('--topo-txt')), ac: rgb(hex('--topo-acento')), forest: rgb(hex('--forest')) };
    });
    if (!m.txt || !m.forest) continue;
    const rTxt = razao(lum(...m.txt), lum(...m.forest));
    const rAc  = razao(lum(...m.ac), lum(...m.forest));
    ok(rTxt >= 4.5, `no tema ${tema}, o subtítulo lê-se sobre o cabeçalho: ${rTxt.toFixed(2)}:1`);
    ok(rAc >= 3, `e o acento (monograma, contagem) também: ${rAc.toFixed(2)}:1`);
  }

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 2).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
