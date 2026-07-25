import http from 'node:http';
import { URL } from 'node:url';
import { ethers } from 'ethers';

const HOST = process.env.HOST || '127.0.0.1';
const PORT = Number(process.env.PORT || 8787);
const API_KEY = String(process.env.DERIVED_ADDR_SERVICE_TOKEN || '').trim();
const MAX_BODY_BYTES = 32 * 1024;

function writeJson(res, code, payload) {
  res.statusCode = code;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(payload));
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error('payload too large'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8').trim();
      if (!raw) return resolve({});
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(new Error('invalid json'));
      }
    });
    req.on('error', reject);
  });
}

function normalizePathPrefix(pathPrefix) {
  let p = String(pathPrefix || "").trim();
  if (p === '') p = "m/44'/60'/0'/0";
  p = p.replace(/\/+$/g, '');
  if (!p.startsWith('m/')) throw new Error('invalid path_prefix');
  return p;
}

function deriveAddressFromXpub(xpub, index) {
  let node;
  try {
    if (ethers.HDNodeVoidWallet && ethers.HDNodeVoidWallet.fromExtendedKey) {
      node = ethers.HDNodeVoidWallet.fromExtendedKey(xpub);
    } else {
      node = ethers.HDNodeWallet.fromExtendedKey(xpub);
    }
  } catch {
    throw new Error('invalid xpub');
  }
  const child = node.deriveChild(index);
  return String(child.address || '').toLowerCase();
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
    if (req.method === 'GET' && url.pathname === '/healthz') {
      return writeJson(res, 200, { ok: true, service: 'uapi-derived-address', ts: Date.now() });
    }

    if (req.method === 'POST' && url.pathname === '/v1/derive') {
      if (API_KEY !== '') {
        const incoming = String(req.headers['x-api-key'] || '').trim();
        if (incoming === '' || incoming !== API_KEY) {
          return writeJson(res, 401, { ok: false, message: 'unauthorized' });
        }
      }

      const body = await parseBody(req);
      const xpub = String(body.xpub || '').trim();
      const index = Number(body.index);
      const chain = String(body.chain || '').trim().toLowerCase();
      const pathPrefix = normalizePathPrefix(body.path_prefix);

      if (!xpub) return writeJson(res, 400, { ok: false, message: 'xpub required' });
      if (!Number.isInteger(index) || index < 0 || index > 2147483647) {
        return writeJson(res, 400, { ok: false, message: 'invalid index' });
      }
      if (!pathPrefix.includes("44'") || !pathPrefix.includes("60'")) {
        return writeJson(res, 400, { ok: false, message: 'path_prefix must be EVM style' });
      }

      const address = deriveAddressFromXpub(xpub, index);
      if (!/^0x[a-f0-9]{40}$/.test(address)) {
        return writeJson(res, 500, { ok: false, message: 'derive failed' });
      }

      return writeJson(res, 200, {
        ok: true,
        chain,
        index,
        address,
        path: `${pathPrefix}/${index}`
      });
    }

    return writeJson(res, 404, { ok: false, message: 'not found' });
  } catch (e) {
    return writeJson(res, 500, { ok: false, message: e?.message || 'internal error' });
  }
});

server.listen(PORT, HOST, () => {
  console.log(`[derived-address-service] listening on http://${HOST}:${PORT}`);
});
