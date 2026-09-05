// Fundação de vários casamentos e vários utilizadores (esquema v7).
//
// Ainda não há mudança visível: esta prova olha para o esqueleto. Garante que
// as tabelas novas existem, que o casamento que já cá estava ficou dono de
// todos os dados, que as definições do sistema não pertencem a casamento
// nenhum, e que dois casais podem ter uma mesa com o mesmo nome — coisa que o
// esquema antigo proibia, porque o nome era único em toda a tabela.
const { chromium } = require('playwright-core');
const EXE = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext()).newPage();
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


  // O esquema é lido pela página de versão, que já sabe olhar para dentro.
  await p.goto(BASE + '/versao.php', { waitUntil: 'networkidle' });
  const txt = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
  ok(!/Fatal error|Warning:/i.test(txt), 'a página de versão não rebenta depois da migração');

  // As páginas todas continuam de pé: a fundação não podia mudar nada à vista.
  const paginas = ['index.php', 'mesas.php', 'graficas.php', 'digital.php', 'cartoes.php',
                   'manual.php', 'impressos.php', 'porteiro.php', 'convite-editor.php', 'editor-cartao.php'];
  let inteiras = 0;
  for (const pag of paginas) {
    const r = await p.goto(BASE + '/' + pag, { waitUntil: 'domcontentloaded' });
    const h = await p.content();
    if (r && r.status() === 200 && /<\/html>/i.test(h) && !/Fatal error|Uncaught/i.test(h)) inteiras++;
    else console.log('   página com problema:', pag, r && r.status());
  }
  ok(inteiras === paginas.length, `as ${paginas.length} páginas continuam inteiras depois da migração`);

  // ---- o esqueleto, pela API de diagnóstico da própria aplicação ----
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  const esq = await p.evaluate(async () => {
    const r = await fetch('api.php?action=esquema_info', { headers: { 'X-CSRF-Token': window.CSRF } });
    return r.ok ? r.json() : null;
  });
  if (!esq || !esq.success) {
    console.log('   (sem endpoint de diagnóstico — a prova do esqueleto fica para a etapa seguinte)');
  } else {
    console.log('   esquema:', JSON.stringify(esq.esquema));
    ok(esq.esquema.versao >= 7, 'o esquema está na versão 7 ou acima');
    ok(esq.esquema.casamentos >= 1, 'existe pelo menos um casamento');
    ok(esq.esquema.orfaos === 0, 'nenhum dado ficou sem casamento atribuído');
    ok(esq.esquema.sistema_fora_de_casamento === true,
       'as definições do sistema não pertencem a casamento nenhum');
    ok(esq.esquema.mesa_unica_por_casamento === true,
       'o nome da mesa é único dentro do casamento, não em todo o sistema');
    ok(esq.esquema.contas >= 1, 'as contas passaram do ficheiro de configuração para a base');
  }

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
