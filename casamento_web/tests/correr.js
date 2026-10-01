#!/usr/bin/env node
// Corre todas as provas por ordem e resume no fim.
// Uso: node correr.js [nome-parcial]
const { execFileSync } = require('child_process');
const fs = require('fs'), path = require('path');

const filtro = process.argv[2] || '';
const provas = fs.readdirSync(__dirname)
  .filter(f => /^(chk_|e2e_|nav_)/.test(f) && f.endsWith('.js'))
  .filter(f => !filtro || f.includes(filtro))
  .sort();

if (!provas.length) { console.error('Nenhuma prova encontrada' + (filtro ? ` para "${filtro}"` : '')); process.exit(1); }

const res = [];
for (const f of provas) {
  process.stdout.write(f.padEnd(24));
  const t0 = Date.now();
  try {
    const saida = execFileSync(process.execPath, [path.join(__dirname, f)],
      { encoding: 'utf8', timeout: 300000, stdio: ['ignore', 'pipe', 'pipe'] });
    const falhas = (saida.match(/^(• )?FAIL/gm) || []).length;
    console.log(`ok   ${((Date.now()-t0)/1000).toFixed(0)}s`);
    res.push({ f, ok: true, falhas, saida });
  } catch (e) {
    const saida = (e.stdout || '') + (e.stderr || '');
    const falhas = (saida.match(/^(• )?FAIL/gm) || []).length;
    console.log(`FALHA ${((Date.now()-t0)/1000).toFixed(0)}s  (${falhas || '?'} verificação(ões))`);
    res.push({ f, ok: false, falhas, saida });
  }
}

const maus = res.filter(r => !r.ok);
console.log('\n' + '-'.repeat(52));
if (!maus.length) { console.log(`TUDO VERDE — ${res.length} provas`); process.exit(0); }
console.log(`${maus.length} de ${res.length} provas falharam:\n`);
for (const m of maus) {
  console.log('=== ' + m.f + ' ===');
  console.log(m.saida.split('\n').filter(l => /^(• )?FAIL|FATAL|Error/.test(l)).slice(0, 8).join('\n') || '(sem linhas de falha)');
  console.log();
}
process.exit(1);
