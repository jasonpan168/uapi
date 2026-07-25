/* global ethers */
importScripts('https://cdn.jsdelivr.net/npm/ethers@6.13.5/dist/ethers.umd.min.js');

let running = false;
let cfg = null;

function bytesToHex(bytes) {
  const hex = [];
  for (let i = 0; i < bytes.length; i++) {
    const v = bytes[i].toString(16);
    hex.push(v.length === 1 ? '0' + v : v);
  }
  return hex.join('');
}

function randomPrivateKeyHex() {
  const bytes = new Uint8Array(32);
  self.crypto.getRandomValues(bytes);
  // Avoid zero key; computeAddress will still validate range.
  let allZero = true;
  for (let i = 0; i < bytes.length; i++) {
    if (bytes[i] !== 0) {
      allZero = false;
      break;
    }
  }
  if (allZero) bytes[31] = 1;
  return '0x' + bytesToHex(bytes);
}

function tailRepeatAnyMatch(s, repeatLen) {
  if (repeatLen <= 1) return s.length >= repeatLen;
  if (s.length < repeatLen) return false;
  const c = s[s.length - 1];
  for (let i = 2; i <= repeatLen; i++) {
    if (s[s.length - i] !== c) return false;
  }
  return true;
}

function matches(addrNoPrefix) {
  if (!cfg) return false;

  if (cfg.prefix && !addrNoPrefix.startsWith(cfg.prefix)) return false;

  let okByCustomSuffix = false;
  let okByRepeat = false;
  const hasSuffixRule = !!(cfg.suffixes && cfg.suffixes.length > 0);
  const hasRepeatRule = !!(cfg.repeatEnabled && cfg.repeatLen > 0);

  if (hasSuffixRule) {
    for (let i = 0; i < cfg.suffixes.length; i++) {
      if (addrNoPrefix.endsWith(cfg.suffixes[i])) {
        okByCustomSuffix = true;
        break;
      }
    }
  }

  if (hasRepeatRule) {
    if (cfg.repeatChar) {
      okByRepeat = addrNoPrefix.endsWith(cfg.repeatChar.repeat(cfg.repeatLen));
    } else {
      okByRepeat = tailRepeatAnyMatch(addrNoPrefix, cfg.repeatLen);
    }
  }

  if (!hasSuffixRule && !hasRepeatRule) return false;
  if (hasSuffixRule && hasRepeatRule) {
    return cfg.combineAny ? (okByCustomSuffix || okByRepeat) : okByCustomSuffix;
  }
  return hasSuffixRule ? okByCustomSuffix : okByRepeat;
}

onmessage = (event) => {
  const data = event.data || {};

  if (data.type === 'start') {
    cfg = {
      prefix: String(data.prefix || '').toLowerCase(),
      suffixes: Array.isArray(data.suffixes) ? data.suffixes.map((s) => String(s).toLowerCase()) : [],
      repeatEnabled: !!data.repeatEnabled,
      repeatLen: Math.max(0, Number(data.repeatLen || 0)),
      repeatChar: String(data.repeatChar || '').toLowerCase(),
      combineAny: !!data.combineAny,
      batchSize: Math.max(100, Math.min(5000, Number(data.batchSize || 500))),
    };

    running = true;
    const startedAt = Date.now();
    let triesSinceReport = 0;
    let lastReportAt = startedAt;

    while (running) {
      for (let i = 0; i < cfg.batchSize; i++) {
        if (!running) break;

        const pk = randomPrivateKeyHex();
        let addr;
        try {
          addr = ethers.computeAddress(pk);
        } catch (e) {
          continue;
        }

        triesSinceReport += 1;
        const raw = addr.slice(2).toLowerCase();
        if (!matches(raw)) continue;

        self.postMessage({
          type: 'found',
          privateKey: pk,
          address: addr,
          addressLower: '0x' + raw,
          ts: Date.now(),
          triesDelta: triesSinceReport,
        });
        triesSinceReport = 0;
      }

      const now = Date.now();
      if (now - lastReportAt >= 250) {
        self.postMessage({
          type: 'progress',
          triesDelta: triesSinceReport,
          elapsedMs: now - startedAt,
        });
        triesSinceReport = 0;
        lastReportAt = now;
      }
    }

    self.postMessage({ type: 'stopped' });
  }

  if (data.type === 'stop') {
    running = false;
  }
};
