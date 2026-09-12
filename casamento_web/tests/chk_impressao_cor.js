// A cor tem de sobreviver à impressão.
//
// Os navegadores descartam fundos ao imprimir, a não ser que quem imprime se
// lembre de ligar "Gráficos de fundo". Nas amostras de cor do manual e nos
// próprios cartões, o fundo NÃO é enfeite: é a informação que a gráfica
// precisa. Sem isso, o manual saía com cinco quadrados brancos e os cartões
// sem cor nenhuma.
//
// Esta prova gera o PDF como o navegador o faz por omissão (printBackground
// falso) e procura lá dentro os tons da paleta. Um screenshot não serviria:
// o descarte acontece só no caminho da impressão, não no ecrã.
const { chromium } = require('playwright-core');
const zlib = require('zlib');
const fs = require('fs');
const os = require('os');
const path = require('path');

const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const TMP = process.env.TEST_OUT || os.tmpdir();

/** Todos os operadores de cor de preenchimento de um PDF, como "r g b". */
function coresDoPdf(ficheiro) {
  const d = fs.readFileSync(ficheiro);
  const partes = [];
  const re = /stream\r?\n/g; let m;
  while ((m = re.exec(d.toString('latin1'))) !== null) {
    const ini = m.index + m[0].length;
    const fim = d.toString('latin1').indexOf('endstream', ini);
    if (fim < 0) continue;
    try { partes.push(zlib.inflateSync(d.subarray(ini, fim)).toString('latin1')); } catch (e) { /* não comprimido */ }
  }
  const txt = partes.join('\n');
  return new Set((txt.match(/[\d.]+ [\d.]+ [\d.]+ rg/g) || []).map(s => s.replace(' rg', '')));
}

/** #RRGGBB para a forma como o PDF a escreve (0-1, até 4 casas, sem zero à esquerda). */
function hexParaPdf(hex) {
  const n = i => {
    const v = parseInt(hex.substr(i, 2), 16) / 255;
    return String(Math.round(v * 10000) / 10000).replace(/^0\./, '.');
  };
  return n(1) + ' ' + n(3) + ' ' + n(5);
}

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1200, height: 1200 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  // O admin entra sem casamento aberto (é da plataforma, não de um casal):
  // escolhe-se o nº1, que é onde estas provas trabalham.
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  // Entrar deixou de aterrar no painel de um casal: vai-se lá de propósito.
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });


  // A paleta que o manual mostra, lida da própria página (não fixada aqui).
  await p.goto(BASE + '/manual.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  const paleta = await p.evaluate(() =>
    [...document.querySelectorAll('.cor')].map(c => ({
      rot: (c.querySelector('b') || {}).textContent || '',
      hex: ((c.querySelector('.hex') || {}).textContent || '').trim()
    })).filter(x => /^#[0-9A-Fa-f]{6}$/.test(x.hex)));
  console.log('   paleta no manual:', JSON.stringify(paleta.map(x => x.rot + ' ' + x.hex)));
  ok(paleta.length >= 5, 'o manual mostra as amostras da paleta do cartão');

  // ---- o manual, impresso como sai por omissão ----
  const pdfManual = path.join(TMP, 'prova_manual_sem_fundos.pdf');
  await p.pdf({ path: pdfManual, format: 'A4', printBackground: false });
  const cores = coresDoPdf(pdfManual);
  const faltam = paleta.filter(x => !cores.has(hexParaPdf(x.hex)));
  console.log('   tons em falta no PDF:', JSON.stringify(faltam.map(x => x.rot + ' ' + x.hex)));
  ok(faltam.length === 0,
     'as amostras de cor saem impressas, mesmo sem "gráficos de fundo" ligado');

  // ---- os cartões, que são o que a gráfica imprime ----
  await p.goto(BASE + '/cartoes.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const acento = await p.evaluate(() => {
    const c = document.querySelector('.cartao');
    return c ? getComputedStyle(c).getPropertyValue('--ct-accent').trim() : '';
  });
  console.log('   tom de acento do cartão:', acento);
  ok(/^#[0-9A-Fa-f]{6}$/.test(acento), 'o cartão traz o seu tom de acento');

  const pdfCartoes = path.join(TMP, 'prova_cartoes_sem_fundos.pdf');
  await p.pdf({ path: pdfCartoes, format: 'A4', printBackground: false });
  const coresC = coresDoPdf(pdfCartoes);
  ok(coresC.has(hexParaPdf(acento)),
     'o cartão sai impresso com a sua cor, sem depender das opções de impressão');

  // ---- a regra existe onde tem de existir ----
  const css = await p.evaluate(async () => {
    const r = await fetch('assets/pecas.css'); return r.text();
  });
  ok(/print-color-adjust\s*:\s*exact/.test(css),
     'a folha das peças pede que a cor do cartão seja impressa');

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
