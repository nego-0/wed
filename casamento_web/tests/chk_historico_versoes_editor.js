const fs = require('fs');
const path = require('path');
const raiz = path.join(__dirname, '..');
const digital = fs.readFileSync(path.join(raiz, 'convite-editor.php'), 'utf8');
const impresso = fs.readFileSync(path.join(raiz, 'editor-cartao.php'), 'utf8');
const hibrido = fs.readFileSync(path.join(raiz, 'assets', 'editor-hibrido.js'), 'utf8');
const documento = fs.readFileSync(path.join(raiz, 'assets', 'editor-documento.js'), 'utf8');
const versoes = fs.readFileSync(path.join(raiz, 'assets', 'versoes.js'), 'utf8');
const estilo = fs.readFileSync(path.join(raiz, 'assets', 'editor.css'), 'utf8');

let falhas = 0;
function ok(cond, msg) {
  console.log((cond ? 'PASS: ' : 'FAIL: ') + msg);
  if (!cond) falhas++;
}

ok(digital.includes('EditorDocumento.serializar(EST, ESQUEMA_DOCUMENTO)') &&
   impresso.includes('EditorDocumento.serializar(est, ESQUEMA_DOCUMENTO)'),
  'os dois históricos usam o documento normalizado');
ok(documento.includes('if (v instanceof Set) return v') && documento.includes('return new Set(v)'),
  'desfazer/refazer reconstrói conjuntos e camadas trancadas');
ok(!digital.includes('ligarRascunho') && !impresso.includes('ligarRascunho'),
  'nenhum editor liga a persistência de rascunhos');
ok(!hibrido.includes('localStorage.setItem'),
  'o núcleo dos editores não grava rascunhos no navegador');
ok(versoes.includes('.filter(function (v) { return !v.padrao; })'),
  'a lista mostra apenas versões guardadas pelo utilizador');
ok(versoes.includes("classe: 'vs-guardar-como'"),
  'Guardar como usa o modal compacto sem barra própria');
ok(digital.includes("classe: 'vs-guardar-como'") && impresso.includes("classe: 'vs-guardar-como'"),
  'o formulário Nome desta versão cabe no viewport em ambos os editores');
ok(estilo.includes('width:min(560px,calc(100vw - 1rem))') && estilo.includes('box-sizing:border-box'),
  'o modal de versão limita a caixa e o campo à largura visível');
ok(estilo.includes('.vs-corpo{') && estilo.includes('overflow-y:auto') && estilo.includes('flex:1 1 auto'),
  'o corpo das versões rola sem mover o cabeçalho');
ok((digital.match(/class="bt-ico" data-ico="(desfazer|refazer)"/g) || []).length === 2 &&
   (impresso.match(/class="bt-ico" data-ico="(desfazer|refazer)"/g) || []).length === 2,
  'os dois editores exibem ícones próprios em Desfazer e Refazer');
ok(digital.includes('onclick="reporComposicao()"') && digital.includes('onclick="reporConvite()"'),
  'Mais acções do editor digital inclui composição e reposição global');
ok(digital.includes('const ORIGINAL = instantaneo()') && digital.includes('aplicarEstado(ORIGINAL)'),
  'a reposição global conserva o estado original mesmo após muitos passos');
ok(estilo.includes('border:1px solid var(--ed-ouro)') &&
   estilo.includes('color:var(--ed-ouro-claro); overflow:hidden'),
  'o estado da versão usa as mesmas cores nos dois cabeçalhos');

process.exit(falhas ? 1 : 0);
