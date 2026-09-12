// A prova da sexta passagem do bar (docs/bar-motor-assistido.md).
//
// FASE 1 — o esquema v40. O motor ainda não propõe nada: o que aqui se
// defende é que o sítio onde ele vai escrever existe, está com âmbito, viaja
// no retrato, e — a parte que mais importa — que abrir o esquema **não mexeu
// em nada do que já lá estava**.
//
// Uma migração que muda o comportamento de uma regra já escrita é a pior
// espécie de migração: ninguém a vê acontecer, e o bar passa a fazer outra
// coisa a meio de uma festa.
const { chromium } = require('playwright-core');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const errs = [];
  let f = 0;
  const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  const casa = await b.newContext({ viewport: { width: 1280, height: 1000 } });
  const p = await casa.newPage();
  p.on('pageerror', e => errs.push('noivos: ' + e.message));
  p.on('console', m => { if (m.type() === 'error') errs.push('noivos: ' + m.text()); });

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin');
  await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]');
  await p.waitForLoadState('networkidle');
  await p.evaluate(async () => {
    await fetch('api.php?action=casamento_abrir&id=1',
      { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF } });
  });
  await p.goto(BASE + '/bar.php', { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);

  // O retrato do bar, lido como o ficheiro que se descarrega — é ele que diz
  // o que viaja e o que fica.
  const retratoDoBar = () => p.evaluate(async () => {
    const r = await fetch('api.php?action=dados_exportar&ambito=casamento&partes=bar',
                          { headers: { 'X-CSRF-Token': window.CSRF } });
    const d = await r.json();
    return { esquema: d.esquema, bar: ((d.casamentos || [])[0] || {}).bar || {} };
  });

  // ============ 1. a versão do esquema ============
  const primeiro = await retratoDoBar();
  ok(primeiro.esquema === 40,
     'o esquema anuncia-se na versão 40 (lido: ' + primeiro.esquema + ')');

  // ============ 2. o modo de cada regra ============
  // Uma regra escrita sem dizer o modo nasce a TRAVAR — que é o que todas as
  // regras deste módulo faziam antes de haver modos. É esta linha que garante
  // que abrir o v40 não mudou o comportamento de nada.
  const posta = await p.evaluate(async () =>
    await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify(
      { escopo: 'tudo', sujeito: 'convidado', unidade: 'bebidas', quantidade: 2,
        janela_min: 30, nota: 'ZS sem modo' }) }));
  const r = (posta.regras || []).filter(x => x.nota === 'ZS sem modo')[0];
  ok(!!r, 'escreve-se uma regra sem dizer o modo');
  const bar = (await retratoDoBar()).bar;
  const escrita = (bar.limites || []).filter(x => x.nota === 'ZS sem modo')[0];
  ok(escrita && escrita.modo === 'trava',
     'e nasce a TRAVAR, como todas as regras faziam antes do v40: «'
     + (escrita ? escrita.modo : '—') + '»');

  // ============ 3. o retrato leva o que é montagem, e deixa a noite ============
  ok(Array.isArray(bar.mensagens),
     'o retrato do bar traz as mensagens — é escrita do casal, e viaja com o menu');
  ok(bar.alertas === undefined,
     'e NÃO traz os alertas: são propostas sobre um momento, e um momento não '
     + 'se importa de outra base');
  ok((bar.itens || []).every(i => i.base_noite === undefined),
     'nem a base da noite de cada bebida — uma percentagem sobre uma noite que '
     + 'não começou é um número inventado');

  // ============ 4. as definições novas ============
  const defs = await p.evaluate(async () => (await window.api('bar_estado')).defs || {});
  ok(defs['bar.degraus_stock'] === '50,30,15,5',
     'os degraus da percentagem de stock vêm de fábrica em 50,30,15,5: '
     + defs['bar.degraus_stock']);
  ok(defs['bar.pausada_ate'] === '', 'a copa não nasce em pausa');
  ok(defs['bar.pausa_min'] === '10', 'e a pausa que se propõe é de 10 minutos');

  // Os degraus guardam-se sempre do maior para o menor. Escritos ao contrário,
  // o alerta de 15% nascia antes do de 30% e a copa via a bebida a ficar
  // «crítica» com metade do stock ainda na mão.
  const arrumados = await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.degraus_stock': '5, 40,15,40 ,90' }) });
    return (await window.api('bar_estado')).defs['bar.degraus_stock'];
  });
  ok(arrumados === '90,40,15,5',
     'e arrumam-se sozinhos, do maior para o menor e sem repetidos: ' + arrumados);

  const lixo = await p.evaluate(async () => {
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.pausada_ate': 'logo à noite' }) });
    return (await window.api('bar_estado')).defs['bar.pausada_ate'];
  });
  ok(lixo === '', 'a hora de fim da pausa só se guarda se for uma hora');

  // A vigia de âmbito das duas tabelas novas não se prova daqui: ela só
  // ACRESCENTA uma verificação, e por isso a sua falta não dá erro nenhum —
  // dá, um dia, uma consulta sem âmbito que ninguém apanhou. Fica pinada em
  // `versao.php`, que é a ferramenta desta casa para «esta linha tem de estar
  // neste ficheiro», e é `chk_versao.js` que a cobra.

  // ============ arrumar ============
  await p.evaluate(async () => {
    const e = await window.api('bar_estado');
    for (const x of (e.regras || []).filter(x => /^ZS /.test(x.nota || ''))) {
      await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: x.id }) });
    }
    await window.api('bar_defs', { method: 'POST',
      body: JSON.stringify({ 'bar.degraus_stock': '50,30,15,5' }) });
  });

  ok(errs.length === 0, 'nenhum erro de JavaScript: ' + errs.slice(0, 3).join(' | '));
  console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
  await b.close(); process.exit(f ? 1 : 0);
})();
