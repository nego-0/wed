// A referência aprovada é uma prova imutável. Alterá-la sem actualizar
// deliberadamente o manifesto faria os comparativos aceitarem uma regressão.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const raiz = path.join(__dirname, 'fixtures', 'convites', 'porcelana-v2-rosa');
const manifesto = JSON.parse(fs.readFileSync(path.join(raiz, 'manifesto.json'), 'utf8'));
const htmlPath = path.join(raiz, manifesto.ficheiro);
const html = fs.readFileSync(htmlPath, 'utf8');
// O Git conserva a referência com LF, mas uma cópia de trabalho Windows pode
// materializá-la com CRLF. O conteúdo visual é o mesmo; só normalizamos essa
// representação antes de calcular a assinatura canónica.
const canonico = html.replace(/\r\n/g, '\n');
const hash = crypto.createHash('sha256').update(canonico, 'utf8').digest('hex').toUpperCase();

if (hash !== manifesto.sha256) {
  throw new Error(`A referência Porcelana V2 Rosa foi alterada (${hash}).`);
}

const texto = canonico;
for (const marca of manifesto.marcasObrigatorias) {
  if (!texto.includes(marca)) throw new Error(`A referência perdeu a marca: ${marca}`);
}

console.log('OK: referência Porcelana V2 Rosa intacta', hash);
