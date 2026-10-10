const fs = require('fs');
const vm = require('vm');
const codigo = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'editor-documento.js'), 'utf8');
const contexto = { window: {} };
vm.createContext(contexto); vm.runInContext(codigo, contexto);
const D = contexto.window.EditorDocumento;
function ok(c, m){ if(!c){ console.error('FALHOU: '+m); process.exit(1); } }
const esquema = { sets:['trancados'], listas:['ordem'], mapas:['val','pos'] };
const a = vm.runInContext("({val:{b:'2',a:'1'},trancados:new Set(['z','a']),ordem:['hero'],pos:null})", contexto);
const b = { ordem:['hero'], trancados:['a','z'], val:{a:'1',b:'2'}, pos:{} };
ok(D.serializar(a, esquema) === D.serializar(b, esquema), 'a fotografia deve ser estável');
const r = D.hidratar(D.serializar(a, esquema), esquema);
ok(r.trancados && typeof r.trancados.has === 'function' && r.trancados.has('z'), 'Set deve sobreviver ao histórico');
ok(Array.isArray(r.ordem) && r.pos && !Array.isArray(r.pos), 'listas e mapas devem ser normalizados');
console.log('OK — documento normalizado e Set preservado.');
