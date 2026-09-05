// O JavaScript que vive dentro das páginas tem de compilar.
//
// Esta prova nasceu de um erro concreto: numa função da Gestão passaram a
// existir dois `const r` no mesmo âmbito. É um erro de SINTAXE, e um erro de
// sintaxe não estraga só aquela função — o bloco <script> inteiro nunca chega
// a correr, e a página fica com todos os botões mortos de uma vez.
//
// O pior é como isso aparece: `php -l` passa (o PHP está bom), a página serve
// um 200, o HTML está todo lá, e só um teste que carregue nos botões dá pela
// coisa. Vários deram — mas cada um culpou a sua funcionalidade, e nenhum
// apontou a causa. Esta prova aponta: é barata, corre sem browser, e diz o
// ficheiro, o bloco e a linha.
//
// As ilhas de PHP (<?= ... ?>) trocam-se por um 0 antes de compilar: o que se
// verifica é o JavaScript à volta delas, que é onde se escreve.
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const RAIZ = path.join(__dirname, '..');
let f = 0; const ok = (c, m) => { console.log((c ? 'PASS' : 'FAIL') + ':', m); if (!c) f++; };

const paginas = fs.readdirSync(RAIZ).filter(n => n.endsWith('.php')).sort();
ok(paginas.length > 0, 'encontrou as páginas do sítio');

let blocos = 0;
for (const nome of paginas) {
  const txt = fs.readFileSync(path.join(RAIZ, nome), 'utf8');
  const re = /<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g;
  let m, n = 0, mau = null;
  while ((m = re.exec(txt))) {
    n++; blocos++;
    const js = m[1].replace(/<\?=[\s\S]*?\?>/g, '0').replace(/<\?php[\s\S]*?\?>/g, '');
    try { new vm.Script(js, { filename: nome }); }
    catch (e) { mau = `bloco ${n}: ${e.message}`; break; }
  }
  if (n) ok(!mau, `${nome} — os ${n} bloco(s) de script compilam` + (mau ? ` · ${mau}` : ''));
}
ok(blocos > 5, `verificou blocos a sério (${blocos})`);

// E os ficheiros de assets, que é onde vive o resto.
const ASSETS = path.join(RAIZ, 'assets');
const js = fs.readdirSync(ASSETS).filter(n => n.endsWith('.js')).sort();
for (const nome of js) {
  let mau = null;
  try { new vm.Script(fs.readFileSync(path.join(ASSETS, nome), 'utf8'), { filename: nome }); }
  catch (e) { mau = e.message; }
  ok(!mau, `assets/${nome} compila` + (mau ? ` · ${mau}` : ''));
}

console.log(f ? `\n${f} FALHA(S)` : '\nTUDO VERDE');
process.exit(f ? 1 : 0);
