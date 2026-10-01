// A janela que substitui o window.confirm() veste-se sozinha.
//
// Nos dois editores não há estilo.css: nem as variáveis do tema, nem as
// classes dos botões. A janela aparecia sem fundo nenhum — via-se o convite
// através dela —, com o campo de texto invisível e os botões cinzentos de
// fábrica do browser. Aqui prova-se que já não: que tem corpo opaco, que os
// botões são os dela, e que a mesma janela sabe vestir-se de claro numa
// página normal e de escuro dentro do editor.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
const OUT  = process.env.TEST_OUT || require('os').tmpdir();

/** A cor de um elemento, em [r,g,b] — e o alfa à parte, que é o que interessa. */
function corPara(txt){
  const m = String(txt || '').match(/[\d.]+/g) || [];
  return { r: +m[0] || 0, g: +m[1] || 0, b: +m[2] || 0, a: m.length > 3 ? +m[3] : 1 };
}
const luz = c => 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });

  /** Abre uma pergunta de sim/não e mede-a. */
  const medir = () => p.evaluate(() => {
    const cx = document.querySelector('#lic-janela.on .pl-modal-cx');
    if (!cx) return null;
    const e = getComputedStyle(cx);
    const ok = document.getElementById('lic-jo'), nao = document.getElementById('lic-jc');
    const eo = ok ? getComputedStyle(ok) : null;
    const ico = document.querySelector('#lic-janela .lic-conf-ico');
    return {
      fundo: e.backgroundColor, raio: parseFloat(e.borderTopLeftRadius) || 0,
      sombra: e.boxShadow !== 'none', larg: Math.round(cx.getBoundingClientRect().width),
      tituloSerif: getComputedStyle(cx.querySelector('h3')).fontFamily,
      okClasses: ok ? ok.className : '', naoClasses: nao ? nao.className : '',
      okFundo: eo ? eo.backgroundColor : '', okRaio: eo ? parseFloat(eo.borderTopLeftRadius) || 0 : 0,
      okCor: eo ? eo.color : '',
      icoFundo: ico ? getComputedStyle(ico).backgroundColor : '',
      rolaAtras: getComputedStyle(document.documentElement).overflow
    };
  });
  const perguntar = async (extra) => {
    await p.evaluate(cfg => { licConfirmar(Object.assign({
      titulo: 'Apagar a secção «Recados»?', icone: '🗑️', perigo: true, confirmar: 'Apagar secção',
      texto: 'A secção sai do convite, com o que tem lá dentro.' }, cfg)); }, extra || {});
    await p.waitForTimeout(400);
  };
  const fechar = async () => {
    await p.evaluate(() => licFecharJanela()); await p.waitForTimeout(250);
  };

  // ============ 1. no editor do convite: tem corpo, e é escuro ============
  await p.goto(BASE + '/convite-editor.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(3000);
  await perguntar();
  const ed = await medir();
  ok(ed !== null, 'a janela abre dentro do editor do convite');
  ok(ed && corPara(ed.fundo).a === 1 && luz(corPara(ed.fundo)) > 0,
     'o corpo é opaco — não se vê o convite através dele: ' + (ed && ed.fundo));
  ok(ed && luz(corPara(ed.fundo)) < 120,
     'e é escuro, como o editor à volta (luminância ' + (ed ? Math.round(luz(corPara(ed.fundo))) : '?') + ')');
  ok(ed && ed.raio >= 8 && ed.sombra, 'tem cantos redondos e sombra: raio '
     + (ed && ed.raio) + 'px, sombra ' + (ed && ed.sombra));
  ok(ed && /Cormorant|Garamond|serif/i.test(ed.tituloSerif),
     'o título vem com a letra da casa, e não a de fábrica: ' + (ed && ed.tituloSerif));

  // Os botões são os da janela — as classes .btn de estilo.css não existem aqui.
  ok(ed && /\bj-bt\b/.test(ed.okClasses) && /\bj-bt\b/.test(ed.naoClasses),
     'os botões são os da janela (j-bt), e não os da página');
  ok(ed && corPara(ed.okFundo).a === 1 && ed.okRaio >= 6,
     'o botão que confirma está mesmo pintado: ' + (ed && ed.okFundo) + ', raio ' + (ed && ed.okRaio) + 'px');
  ok(ed && corPara(ed.okFundo).r > corPara(ed.okFundo).g + 25,
     'e num perigo é vermelho, para não se carregar nele por engano');
  ok(ed && corPara(ed.icoFundo).a > 0, 'o ícone tem a sua pastilha por trás');
  ok(ed && ed.rolaAtras === 'hidden', 'a página por trás não rola enquanto a janela está aberta');
  await p.screenshot({ path: OUT + '/janela-editor.png' });

  // O campo de texto também tinha de se ver: era o pior de todos, invisível.
  await fechar();
  await p.evaluate(() => { licFormulario({
    titulo: 'Guardar como uma versão vossa',
    dica: 'Fica só para o vosso casamento.',
    campos: [{ id: 'nome', rot: 'Nome desta versão', largura: 3 }],
    aoGuardar: async () => false }); });
  await p.waitForTimeout(400);
  const campo = await p.evaluate(() => {
    const el = document.getElementById('lf-nome'); if (!el) return null;
    const c = getComputedStyle(el);
    return { fundo: c.backgroundColor, borda: c.borderTopWidth, cor: c.color,
             bordaCor: c.borderTopColor };
  });
  ok(campo && corPara(campo.fundo).a === 1 && parseFloat(campo.borda) >= 1,
     'o campo de escrever vê-se: fundo ' + (campo && campo.fundo)
       + ', borda ' + (campo && campo.borda));
  ok(campo && Math.abs(luz(corPara(campo.cor)) - luz(corPara(campo.fundo))) > 60,
     'e o que se escreve lá dentro lê-se contra o fundo dele');
  await p.screenshot({ path: OUT + '/janela-editor-form.png' });
  await fechar();

  // ============ 2. no editor do cartão: a mesma janela ============
  await p.goto(BASE + '/editor-cartao.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  await perguntar({ perigo: false, icone: '↩️', titulo: 'Repor todas as camadas?',
                    confirmar: 'Repor composição' });
  const ct = await medir();
  ok(ct && ct.fundo === ed.fundo,
     'o editor do cartão veste a janela exactamente como o do convite');
  // O ouro do editor também é quente; o que tem de se ver é que NÃO é a mesma
  // cor do perigo — senão «Repor» e «Apagar» pediam-se com o mesmo botão.
  ok(ct && ct.okFundo !== ed.okFundo && corPara(ct.okFundo).a === 1,
     'e fora de um perigo o botão tem outra cor que não a do perigo: '
       + (ct && ct.okFundo) + ' contra ' + (ed && ed.okFundo));
  await fechar();

  // ============ 3. numa página normal: clara, e a mesma forma ============
  await p.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  await perguntar();
  const pg = await medir();
  ok(pg && luz(corPara(pg.fundo)) > 160,
     'numa página com tema, a janela é clara: ' + (pg && pg.fundo));
  ok(pg && pg.raio >= 8 && pg.sombra && /\bj-bt\b/.test(pg.okClasses),
     'com a mesma forma e os mesmos botões — uma janela só, para toda a casa');
  ok(pg && corPara(pg.okFundo).r > corPara(pg.okFundo).g + 25,
     'e o perigo continua a ser vermelho aqui: ' + (pg && pg.okFundo));

  // Fechar devolve o que se tinha tirado.
  await fechar();
  ok(await p.evaluate(() => getComputedStyle(document.documentElement).overflow) !== 'hidden',
     'e ao fechar a página volta a rolar');

  // ============ 4. no telemóvel, sobe do fundo e os botões alargam ============
  const tel = await (await b.newContext({ viewport: { width: 390, height: 780 } })).newPage();
  await tel.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await tel.fill('input[name=utilizador]', 'admin'); await tel.fill('input[name=senha]', 'noivos2026');
  await tel.click('button[type=submit]'); await tel.waitForLoadState('networkidle');
  await tel.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
  await tel.waitForTimeout(900);
  await tel.evaluate(() => { licConfirmar({ titulo: 'Apagar?', icone: '🗑️', perigo: true,
    confirmar: 'Apagar', texto: 'Sai do convite.' }); });
  await tel.waitForTimeout(400);
  const mob = await tel.evaluate(() => {
    const cx = document.querySelector('#lic-janela.on .pl-modal-cx');
    const ok = document.getElementById('lic-jo');
    const r = cx.getBoundingClientRect(), ro = ok.getBoundingClientRect();
    return { larg: Math.round(r.width), baixo: Math.round(innerHeight - r.bottom),
             botao: Math.round(ro.width), janela: innerWidth };
  });
  ok(mob.larg >= mob.janela - 2 && mob.baixo <= 2,
     'no telemóvel ocupa a largura toda e encosta em baixo, onde o polegar chega');
  ok(mob.botao > mob.janela * 0.7,
     'e o botão alarga-se para se acertar nele: ' + mob.botao + 'px em ' + mob.janela);
  await tel.screenshot({ path: OUT + '/janela-telemovel.png' });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
