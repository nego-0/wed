#!/usr/bin/env node
/*
 * Regrava as animações da Ajuda a partir da interface real, sem alterar dados.
 *
 * BASE_URL=http://127.0.0.1:8920 TEST_USER=admin TEST_PASSWORD=<senha> node gerar-ajuda.js
 * AJUDA_MODULOS=mesas,digital limita a execução. O WEBM e a capa finais
 * substituem assets/ajuda/<modulo>.* apenas depois de cada gravação terminar.
 */
const { chromium } = require('playwright-core');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const EXE = process.env.CHROMIUM || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  : '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
const USER = process.env.TEST_USER || 'admin';
const PASSWORD = process.env.TEST_PASSWORD;
const CASAMENTO = process.env.TEST_CASAMENTO || '1';
const OUT = path.resolve(__dirname, '..', 'assets', 'ajuda');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'kulemba-ajuda-'));
const requested = new Set((process.env.AJUDA_MODULOS || '').split(',').map(x => x.trim()).filter(Boolean));

const guides = {
  convidados: { url: '/index.php', steps: [
    ['#busca, input[type=search]', 'Pesquisar uma família ou convidado'],
    ['.stats .stat, .stat', 'Filtrar pelas confirmações'],
    ['.convite-row, [data-convite]', 'Abrir e editar um convite'],
  ]},
  mesas: { url: '/mesas.php', steps: [
    ['.mesa, [data-mesa], #planta button', 'Escolher uma mesa'],
    ['select, [role=combobox]', 'Atribuir uma pessoa'],
    ['#planta, .planta', 'Rever a distribuição da sala'],
  ]},
  impresso: { url: '/graficas.php', steps: [
    ['.painel, .card', 'Escolher a peça impressa'],
    ['a[href*="cartoes"], a[href*="impressos"]', 'Abrir a pré-visualização'],
    ['a[href*="manual"]', 'Entregar o manual à gráfica'],
  ]},
  digital: { url: '/digital.php', steps: [
    ['.painel, .card', 'Ver o convite em vigor'],
    ['a[href*="convite-editor"], .btn-ouro', 'Abrir o editor'],
    ['.convite-row, .versao', 'Gerir versões do convite'],
  ]},
  porta: { url: '/porteiro.php', steps: [
    ['input[type=search], input[type=text]', 'Pesquisar pelo nome'],
    ['.stat, .stats > *', 'Acompanhar as entradas'],
    ['.convite-row, .pessoa, table tbody tr', 'Confirmar quem chegou'],
  ]},
  bar: { url: '/bar.php', steps: [
    ['[role=tab], .b-aba', 'Escolher a área do bar'],
    ['.b-cartao, .painel', 'Organizar menu e stock'],
    ['a[href*="copa"], a[href*="entregas"]', 'Abrir os postos da equipa'],
  ]},
  orcamento: { url: '/orcamento.php', steps: [
    ['.stats .stat, .resumo > *', 'Ler o planeado e o pago'],
    ['button, .btn', 'Registar uma despesa'],
    ['table, .lista', 'Acompanhar os pagamentos'],
  ]},
};

async function pausa(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function apontar(page, selector, texto) {
  const alvo = page.locator(selector).first();
  if (!await alvo.count() || !await alvo.isVisible().catch(() => false)) return;
  await alvo.scrollIntoViewIfNeeded();
  const box = await alvo.boundingBox(); if (!box) return;
  await page.mouse.move(box.x + Math.min(box.width / 2, 140), box.y + Math.min(box.height / 2, 45), { steps: 18 });
  await page.evaluate(({ selector, texto }) => {
    document.getElementById('ajuda-gravador')?.remove();
    const el = document.querySelector(selector); if (!el) return;
    const r = el.getBoundingClientRect(), o = document.createElement('div');
    o.id = 'ajuda-gravador'; o.innerHTML = '<span></span><b></b>';
    Object.assign(o.style,{position:'fixed',inset:'0',zIndex:'2147483646',pointerEvents:'none'});
    const ring=o.firstElementChild,label=o.lastElementChild;
    Object.assign(ring.style,{position:'absolute',left:(r.left-7)+'px',top:(r.top-7)+'px',width:(r.width+14)+'px',height:(r.height+14)+'px',border:'3px solid #d3a85c',borderRadius:'14px',boxShadow:'0 0 0 9999px rgba(8,28,21,.18)',transition:'all .35s'});
    label.textContent=texto; Object.assign(label.style,{position:'absolute',left:Math.max(14,Math.min(innerWidth-300,r.left))+'px',top:Math.max(14,r.top-48)+'px',maxWidth:'280px',padding:'8px 12px',borderRadius:'9px',background:'#10271d',color:'#fff',font:'600 13px system-ui',boxShadow:'0 8px 25px #0003'});
    document.body.appendChild(o);
  }, { selector: selector.split(',')[0], texto });
  await pausa(1450);
  await page.evaluate(() => document.getElementById('ajuda-gravador')?.remove());
  await pausa(300);
}

(async () => {
  if (!PASSWORD) throw new Error('Defina TEST_PASSWORD com a senha da conta de testes.');
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE, headless: true });
  try {
    const auth = await browser.newContext({ viewport: { width: 1280, height: 720 } });
    const login = await auth.newPage();
    await login.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
    await login.fill('[name=utilizador]', USER); await login.fill('[name=senha]', PASSWORD);
    await login.click('button[type=submit]'); await login.waitForLoadState('networkidle');
    if (/login\.php/.test(login.url())) throw new Error('A conta de teste não entrou.');
    if (CASAMENTO) {
      const aberto = await login.evaluate(async id => fetch('api.php?action=casamento_abrir&id=' + encodeURIComponent(id), {
        method:'POST', headers:{'X-CSRF-Token':window.CSRF}
      }).then(r => r.json()), CASAMENTO);
      if (!aberto.success) throw new Error('Não foi possível abrir o casamento de teste ' + CASAMENTO + '.');
    }
    const state = await auth.storageState(); await auth.close();

    for (const [modulo, guide] of Object.entries(guides)) {
      if (requested.size && !requested.has(modulo)) continue;
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 }, storageState: state,
        recordVideo: { dir: TMP, size: { width: 1280, height: 720 } } });
      const page = await ctx.newPage(); const video = page.video();
      await page.goto(BASE + guide.url, { waitUntil: 'networkidle' }); await pausa(900);
      for (const [selector, texto] of guide.steps) await apontar(page, selector, texto);
      await page.evaluate(() => scrollTo({ top: 0, behavior: 'smooth' })); await pausa(700);
      const posterTmp = path.join(TMP, modulo + '-poster.png');
      await page.screenshot({ path: posterTmp, animations: 'disabled' });
      await ctx.close();
      const source = await video.path(), target = path.join(OUT, modulo + '.webm');
      const poster = path.join(OUT, modulo + '-poster.png');
      fs.copyFileSync(source, target); fs.copyFileSync(posterTmp, poster);
      console.log('OK ' + modulo + ' → ' + target + ' + ' + poster);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
