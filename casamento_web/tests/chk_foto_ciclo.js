// O ciclo de vida das fotografias no editor do convite digital.
//
//   1. trocar uma foto tira a peça do estado «de origem» (a foto é uma saída
//      da origem, e passa a haver alterações por guardar);
//   2. sair sem guardar repõe a foto anterior e apaga o ficheiro novo;
//   3. guardar uma versão faz a foto ficar — sair já não a apaga;
//   4. apagar uma versão apaga a fotografia que só ela tinha anexada.
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const EXE  = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8920';
// A raiz do site, para confirmar no disco que os ficheiros nascem e morrem.
const ROOT = path.resolve(__dirname, '..') + '/';
const existe = f => { try { return fs.existsSync(ROOT + f); } catch (e) { return false; } };

(async () => {
  const b = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox'] });
  const p = await b.newContext().then(c => c.newPage());
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

  await p.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=utilizador]', 'admin'); await p.fill('input[name=senha]', 'noivos2026');
  await p.click('button[type=submit]'); await p.waitForLoadState('networkidle');
  const api = (a, c) => p.evaluate(async ({ a, c }) => {
    const r = await fetch('api.php?action=' + a, { method: c ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c ? JSON.stringify(c) : undefined });
    return r.json();
  }, { a, c });
  // Envia uma fotografia (um PNG mínimo) para a secção de capa (media.hero).
  const upload = () => p.evaluate(async () => {
    const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
    const bin = atob(b64); const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const fd = new FormData();
    fd.append('chave', 'media.hero');
    fd.append('ficheiro', new Blob([arr], { type: 'image/png' }), 'foto.png');
    const r = await fetch('api.php?action=def_upload', { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF }, body: fd });
    return (await r.json()).path;
  });
  const padraoEmVigor = async () =>
    ((await api('versao_lista&ambito=digital')).versoes.find(v => v.padrao) || {}).em_vigor === true;

  const w = await api('casamento_criar', { nome: 'ZZ Foto ' + Date.now(), noiva: 'Ana', noivo: 'Beto' }, 1);
  await api('casamento_abrir&id=' + w.id, {});

  // ---------- 1 + 2: trocar e sair sem guardar ----------
  const P1 = await upload();
  ok(existe(P1), 'a foto enviada nasce no disco');
  ok((await api('versao_lista&ambito=digital')).alguma_em_vigor === false,
     'trocar a foto deixa a peça com alterações por guardar (não «de origem»)');
  const dd = await api('media_descartar', {});
  ok(dd.success && dd.apagados === 1, 'sair sem guardar apaga a foto por confirmar');
  ok(!existe(P1), 'e o ficheiro sai do disco');
  ok(await padraoEmVigor(), 'a peça volta ao estado de origem');

  // ---------- 3: guardar mantém a foto ----------
  const P2 = await upload();
  ok(existe(P2), 'nova foto no disco');
  const v = await api('versao_criar', { nome: 'Com foto', ambito: 'digital' }, 1);
  ok(v.success, 'guarda uma versão com a foto');
  await api('media_descartar', {});                 // simula o «sair» — já nada há por confirmar
  ok(existe(P2), 'depois de guardar, sair já não apaga a foto');

  // ---------- 4: apagar a versão apaga a foto anexada ----------
  await api('versao_aplicar&id=0&ambito=digital', {});   // volta à origem: a foto sai da peça, fica na versão
  ok(existe(P2), 'a foto continua enquanto uma versão a guardar');
  const vs = (await api('versao_lista&ambito=digital')).versoes.filter(x => !x.padrao);
  const del = await api('versao_apagar&id=' + vs[0].id + '&ambito=digital', {});
  ok(del.success && del.fotos_apagadas === 1, 'apagar a versão apaga a foto anexada');
  ok(!existe(P2), 'e o ficheiro sai do disco');

  // limpeza
  await api('casamento_estado&id=' + w.id + '&estado=arquivado', {}); await api('casamento_apagar&id=' + w.id, {});

  console.log('erros JS:', errs.length ? errs.join(' | ') : 'nenhum');
  ok(errs.length === 0, 'nenhum erro de JavaScript');
  console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
  await b.close(); process.exit(f ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
