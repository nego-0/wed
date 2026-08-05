// Cada página chega ao fim sem rebentar.
//
// Nasceu de um defeito real: versao.php usava escP() sem requerer o ficheiro
// onde essa função vive. Num servidor com os erros escondidos a página saía
// truncada a meio, sem aviso nenhum; num com os erros à vista saía um "Fatal
// error" por cima do conteúdo. E a prova que havia passava à mesma, porque só
// olhava para partes que eram desenhadas ANTES do ponto onde rebentava.
//
// Por isso esta prova não procura conteúdo: verifica que a resposta está
// inteira e sem rasto de erro do PHP.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

// Páginas de administração que devolvem HTML completo.
const PAGINAS = [
  'index.php', 'mesas.php', 'graficas.php', 'digital.php', 'cartoes.php',
  'manual.php', 'impressos.php', 'porteiro.php', 'versao.php',
  'editor-cartao.php', 'convite-editor.php',
  'convite-digital.php?demo=1', 'convite-digital.php?demo=1&prova=1',
  'digital.php?aba=versoes', 'graficas.php?aba=manuais', 'editor-cartao.php?diag=1',
];

// O que denuncia um erro do PHP no meio do HTML.
const RASTOS = [
  /Fatal error/i, /Parse error/i, /Warning<\/b>/i, /Notice<\/b>/i,
  /Uncaught\s+\w*Error/i, /call to undefined/i, /Undefined variable/i,
  /Deprecated:/i, /on line <b>\d+/i,
];

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');

  for (const pag of PAGINAS) {
    const r = await p.goto(BASE + '/' + pag, { waitUntil: 'domcontentloaded' });
    const html = await p.content();
    const estado = r.status();

    const rasto = RASTOS.find(re => re.test(html));
    // Um documento inteiro fecha o </html>. Truncado a meio de um fatal, não fecha.
    const inteiro = /<\/html>\s*$/i.test(html.trim());

    const nome = pag.padEnd(38);
    if (estado !== 200)      ok(false, `${nome} devolveu ${estado}`);
    else if (rasto)          ok(false, `${nome} traz um erro do PHP: ${rasto}`);
    else if (!inteiro)       ok(false, `${nome} sai truncada — rebentou a meio`);
    else                     ok(true,  `${nome} chega ao fim, inteira`);
  }

  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
