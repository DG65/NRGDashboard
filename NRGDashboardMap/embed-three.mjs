import fs from 'fs';

const htmlPath = './module.html';
const threePath = './three.min.js';

// Lese beide Dateien
const html = fs.readFileSync(htmlPath, 'utf8');
const three = fs.readFileSync(threePath, 'utf8');

// Ersetze <script src="three.min.js"></script> mit inline script
const modified = html.replace(
  /<script src="three\.min\.js"><\/script>/,
  `<script>\n${three}\n</script>`
);

// Schreibe zurück
fs.writeFileSync(htmlPath, modified);

console.log('✅ three.min.js in module.html eingebettet');
console.log('Größe:', (modified.length / 1024 / 1024).toFixed(1), 'MB');
