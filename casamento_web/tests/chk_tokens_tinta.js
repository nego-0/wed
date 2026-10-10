// Nenhum token de ENCHIMENTO serve de tinta.
//
// A casa tem duas regras escritas há muito, e que ninguém conseguia fazer
// cumprir:
//
//     --forest e --gold são ENCHIMENTOS, não tintas.
//     A tinta de acento é --gold-texto; a de texto é --ink / --text.
//
// A razão é que eles não viram com o tema da mesma maneira que o fundo: no
// tema escuro, --forest é #0E1B25 (quase preto) e --cream é #1E2A33 (quase
// preto também). Escrever a primeira sobre a segunda dá 1,2:1 — o texto está
// lá, e não se vê. Quem o leu, leu-o por saber o que lá estava escrito.
//
// Isto já foi corrigido três vezes, e três vezes voltou noutro sítio. A prova
// dos temas mede PIXÉIS PINTADOS, que é a medida certa mas tem um limite
// fatal para esta classe de defeito: só vê o que está desenhado no ecrã
// naquele instante. Um botão dentro de uma janela fechada, uma pastilha que só
// existe com dados, um ecrã atrás de um «Mais filtros» — nada disso é medido,
// e foi exactamente aí que os defeitos se foram escondendo.
//
// Esta prova é outra coisa, e é por isso que vale a pena tê-la ao lado: LÊ AS
// FOLHAS, e não o ecrã. Não sabe se o texto se vê — sabe que a regra da casa
// foi quebrada, esteja o elemento à vista ou enterrado numa janela que só abre
// à terça-feira. As duas juntas cobrem o que nenhuma cobre sozinha.
const fs = require('fs');
const path = require('path');
const RAIZ = path.join(__dirname, '..');

// Onde é que uma cor é TINTA. `background`, `border-color` e `accent-color`
// não são: aí o --forest está no seu lugar, que é o de encher.
//
// E os dois tokens falham por razões OPOSTAS, o que muda o que se exige de
// cada um:
//
//   --forest é escuro em todos os temas, e no escuro o FUNDO também é. É
//   sempre mau como tinta, sem excepção — 1,2:1 sobre --cream.
//
//   --gold vira: é claro no tema escuro (onde funciona bem) e médio nos
//   claros, onde mede ~3,3:1 sobre branco. Isso chega para um ÍCONE (o mínimo
//   de um desenho é 3:1) e não chega para TEXTO (4,5:1). Por isso o --gold só
//   se assinala quando a regra é de texto — quando traz font-size, font-weight,
//   letter-spacing ou text-transform ao lado.
const FOREST = /(^|[;{\s])color\s*:\s*var\(\s*--forest\s*\)/;
const OURO   = /(^|[;{\s])color\s*:\s*var\(\s*--gold\s*\)/;
const DE_TEXTO = /font-size|font-weight|letter-spacing|text-transform|text-decoration/;
// Texto GRANDE e ícones pedem 3:1 e não 4,5 — e --gold dá 3,3. Os tamanhos
// grandes da casa são estes três, e um seletor com «ico» no nome é um desenho.
const GRANDE = /--t-titulo|--t-seccao|--t-display|\bico\b|-ico|\.ico|monograma/;
const TINTA = l => FOREST.test(l) || (OURO.test(l) && DE_TEXTO.test(l) && !GRANDE.test(l));

// As folhas do produto. O convite fica de fora e com razão: é a PEÇA do casal,
// tem a sua própria paleta, não veste os temas da casa (não há data-tema
// nenhum lá dentro) e o fundo dele é sempre claro. Aplicar-lhe esta regra era
// corrigir um problema que ele não tem.
const FORA = new Set(['convite.php']);

const ficheiros = [];
(function varrer(dir) {
  for (const f of fs.readdirSync(dir)) {
    if (f === 'node_modules' || f === '.git' || f === 'tests' || f === 'media') continue;
    const p = path.join(dir, f);
    const st = fs.statSync(p);
    if (st.isDirectory()) { varrer(p); continue; }
    if (!/\.(php|css)$/.test(f)) continue;
    if (FORA.has(path.relative(RAIZ, p))) continue;
    ficheiros.push(p);
  }
})(RAIZ);

let f = 0;
const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

ok(ficheiros.length > 10, 'há folhas para ler: ' + ficheiros.length + ' ficheiro(s)');

const maus = [];
for (const p of ficheiros) {
  const linhas = fs.readFileSync(p, 'utf8').split('\n');
  let emPrint = 0;
  linhas.forEach((l, i) => {
    // O bloco de impressão é outro mundo: o papel é branco em todos os temas,
    // e uma tinta escura ali é o que se quer.
    if (/@media\s+print/.test(l)) emPrint = 1;
    if (emPrint) {
      emPrint += (l.match(/\{/g) || []).length - (l.match(/\}/g) || []).length;
      if (emPrint <= 0) { emPrint = 0; return; }
      return;
    }
    if (!TINTA(l)) return;
    maus.push(path.relative(RAIZ, p) + ':' + (i + 1) + '  ' + l.trim().slice(0, 78));
  });
}

ok(maus.length === 0,
   'nenhum enchimento usado como tinta (--forest sempre, --gold em texto)'
   + (maus.length ? ':\n   ' + maus.slice(0, 15).join('\n   ')
                    + (maus.length > 15 ? '\n   …e mais ' + (maus.length - 15) : '') : ''));

// E o inverso, que é o mesmo erro visto do outro lado: uma tinta de acento a
// servir de fundo. --gold-texto é escuro nos temas claros e CLARO no escuro;
// como fundo, leva atrás o texto que estiver por cima.
const FUNDO = /(^|[;{\s])background(-color)?\s*:\s*var\(\s*--gold-texto\s*\)/;
const fundos = [];
for (const p of ficheiros) {
  fs.readFileSync(p, 'utf8').split('\n').forEach((l, i) => {
    if (FUNDO.test(l)) fundos.push(path.relative(RAIZ, p) + ':' + (i + 1));
  });
}
ok(fundos.length === 0,
   'nem a tinta de acento usada como fundo' + (fundos.length ? ': ' + fundos.join(', ') : ''));

// Cores cravadas à mão nas folhas das páginas com tema. Um #fff escrito à mão
// é branco nos quatro temas — e no escuro é uma ilha acesa no meio da noite.
// Os rgba() de véu e de sombra não contam (não são cor, são transparência), e
// o valor de recurso de um var(--x, #y) é a rede por baixo de uma variável.
const CRAVADA = /(^|[;{\s])(color|background|background-color)\s*:\s*#[0-9a-fA-F]{3,8}\s*(;|\}|$)/;
const cravadas = [];
for (const p of ficheiros) {
  const linhas = fs.readFileSync(p, 'utf8').split('\n');
  let emPrint = 0;
  linhas.forEach((l, i) => {
    if (/@media\s+print/.test(l)) emPrint = 1;
    if (emPrint) {
      emPrint += (l.match(/\{/g) || []).length - (l.match(/\}/g) || []).length;
      if (emPrint <= 0) emPrint = 0;
      return;
    }
    if (/:root|data-tema|var\(--/.test(l)) return;   // é onde os tokens se definem
    if (!CRAVADA.test(l)) return;
    cravadas.push(path.relative(RAIZ, p) + ':' + (i + 1) + '  ' + l.trim().slice(0, 70));
  });
}
// Esta é uma CONTAGEM e não uma porta fechada: há cores cravadas legítimas
// (o preto de um QR, o branco de uma folha de impressão que não está num
// @media print). O que não se quer é que o número CRESÇA sem ninguém reparar.
const TECTO = 60;
ok(cravadas.length <= TECTO,
   'as cores cravadas à mão não crescem: ' + cravadas.length + ' (tecto ' + TECTO + ')'
   + (cravadas.length > TECTO ? '\n   ' + cravadas.slice(0, 10).join('\n   ') : ''));

console.log(f ? `\n${f} verificação(ões) falharam` : '\nTudo certo.');
process.exit(f ? 1 : 0);
