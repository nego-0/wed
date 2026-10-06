const fs = require('fs');
const path = require('path');
const raiz = path.join(__dirname, '..');
const digital = fs.readFileSync(path.join(raiz, 'convite-editor.php'), 'utf8');
const impresso = fs.readFileSync(path.join(raiz, 'editor-cartao.php'), 'utf8');
const hibrido = fs.readFileSync(path.join(raiz, 'assets', 'editor-hibrido.js'), 'utf8');
const versoes = fs.readFileSync(path.join(raiz, 'assets', 'versoes.js'), 'utf8');
const estilo = fs.readFileSync(path.join(raiz, 'assets', 'editor.css'), 'utf8');

let falhas = 0;
function ok(cond, msg) {
  console.log((cond ? 'PASS: ' : 'FAIL: ') + msg);
  if (!cond) falhas++;
}

ok(digital.includes('valor instanceof Set ? [...valor] : valor'),
  'o histórico digital serializa conjuntos como listas');
ok(digital.includes('EST.trancados = new Set('),
  'desfazer/refazer reconstrói as camadas trancadas como Set');
ok(!digital.includes('ligarRascunho') && !impresso.includes('ligarRascunho'),
  'nenhum editor liga a persistência de rascunhos');
ok(!hibrido.includes('localStorage.setItem'),
  'o núcleo dos editores não grava rascunhos no navegador');
ok(versoes.includes('.filter(function (v) { return !v.padrao; })'),
  'a lista mostra apenas versões guardadas pelo utilizador');
ok(versoes.includes("classe: 'vs-guardar-como'"),
  'Guardar como usa o modal compacto sem barra própria');
ok(estilo.includes('.vs-corpo{') && estilo.includes('overflow-y:auto') && estilo.includes('flex:1 1 auto'),
  'o corpo das versões rola sem mover o cabeçalho');
ok((digital.match(/class="bt-ico" data-ico="(desfazer|refazer)"/g) || []).length === 2 &&
   (impresso.match(/class="bt-ico" data-ico="(desfazer|refazer)"/g) || []).length === 2,
  'os dois editores exibem ícones próprios em Desfazer e Refazer');

process.exit(falhas ? 1 : 0);
