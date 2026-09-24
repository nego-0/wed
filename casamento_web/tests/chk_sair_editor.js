// A saída tem de estar sempre visível nos dois editores e levar ao contexto
// que os abriu: a área da peça para um casal, ou os modelos para um modelo.
const fs = require('fs');
const path = require('path');
const raiz = path.join(__dirname, '..');

let falhas = 0;
const ok = (cond, msg) => {
  console.log((cond ? 'PASS' : 'FAIL') + ': ' + msg);
  if (!cond) falhas++;
};
const ler = nome => fs.readFileSync(path.join(raiz, nome), 'utf8');

const digital = ler('convite-editor.php');
const impresso = ler('editor-cartao.php');
const espaco = ler('assets/editor-espaco.js');

for (const [nome, fonte, regresso] of [
  ['digital', digital, 'digital.php'],
  ['impresso', impresso, 'graficas.php'],
]) {
  ok(fonte.includes("$SAIR_EDITOR = $MODELO ? 'modelos.php' : '" + regresso + "';"),
     `o editor ${nome} regressa a ${regresso}, ou aos modelos quando desenha um modelo`);
  ok(/class="sair-editor"[^>]*>.*Sair do Editor<\/a>/.test(fonte),
     `o editor ${nome} mostra a opção «Sair do Editor»`);
  ok(/EDITOR_MIN\s*=\s*\{[^}]*sair:/.test(fonte),
     `o editor ${nome} entrega o destino ao aviso de ecrã pequeno`);
  ok(/beforeunload/.test(fonte),
     `o editor ${nome} protege alterações por guardar ao sair`);
}

ok(espaco.includes('>Sair do Editor</a>') && /M\.sair\s*\|\|\s*'index\.php'/.test(espaco),
   'o aviso de ecrã pequeno oferece a mesma saída e usa o destino do editor');

process.exit(falhas ? 1 : 0);
