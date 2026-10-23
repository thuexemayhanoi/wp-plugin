import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
const root = path.resolve('web');
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript', '.wasm': 'application/wasm' };
http.createServer(async (req, res) => {
  const p = path.join(root, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!p.startsWith(root)) { res.writeHead(403); return res.end(); }
  try {
    const body = await readFile(p.endsWith('/') ? p + 'index.html' : p);
    const headers = { 'Content-Type': types[path.extname(p)] || 'application/octet-stream' };
    if (process.env.ISOLATE === '1') { headers['Cross-Origin-Opener-Policy'] = 'same-origin'; headers['Cross-Origin-Embedder-Policy'] = 'credentialless'; }
    res.writeHead(200, headers); res.end(body);
  } catch { res.writeHead(404); res.end(); }
}).listen(Number(process.env.PORT || 8790));
