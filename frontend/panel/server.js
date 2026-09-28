// Servidor estatico minimo para Render (web_service node): sirve dist/.
// Sin dependencias — el repo corre `node server.js` tras `npm run build`.
// Toda ruta que no sea un archivo real devuelve index.html (SPA).
import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const dist = path.join(path.dirname(fileURLToPath(import.meta.url)), 'dist');

const TIPOS = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.json': 'application/json',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
};

function servir(res, archivo) {
  fs.readFile(archivo, (err, data) => {
    if (err) {
      res.writeHead(404);
      res.end('not found');
      return;
    }
    res.writeHead(200, { 'Content-Type': TIPOS[path.extname(archivo)] || 'application/octet-stream' });
    res.end(data);
  });
}

http.createServer((req, res) => {
  const url = decodeURIComponent((req.url || '/').split('?')[0]);
  const archivo = path.join(dist, url === '/' ? 'index.html' : url);

  // Nunca servir nada fuera de dist/.
  if (!archivo.startsWith(dist)) {
    res.writeHead(403);
    res.end();
    return;
  }

  fs.stat(archivo, (err, st) => {
    if (err || !st.isFile()) {
      servir(res, path.join(dist, 'index.html'));
      return;
    }
    servir(res, archivo);
  });
}).listen(process.env.PORT || 10000, '0.0.0.0');
