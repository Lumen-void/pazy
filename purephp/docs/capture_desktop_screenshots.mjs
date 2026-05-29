import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const outDir = '/Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/docs/screenshots';
const port = 9333 + Math.floor(Math.random() * 1000);
const profile = `/tmp/pazy-guide-chrome-${Date.now()}`;
const base = 'http://localhost/pazy/purephp/public/';
await mkdir(outDir, { recursive: true });

const proc = spawn(chrome, [
  '--headless=new',
  '--disable-gpu',
  '--no-sandbox',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  '--window-size=1440,1000',
  'about:blank'
], { stdio: 'ignore' });

async function getJson(url, opts = {}) {
  const res = await fetch(url, opts);
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  return res.json();
}

async function waitForChrome() {
  for (let i = 0; i < 80; i++) {
    try { return await getJson(`http://127.0.0.1:${port}/json/version`); } catch {}
    await delay(100);
  }
  throw new Error('Chrome did not start');
}

class CDP {
  constructor(wsUrl) {
    this.ws = new WebSocket(wsUrl);
    this.id = 0;
    this.pending = new Map();
    this.events = [];
    this.ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result || {});
      } else if (msg.method) {
        this.events.push(msg);
      }
    };
  }
  async open() {
    if (this.ws.readyState === WebSocket.OPEN) return;
    await new Promise((resolve, reject) => {
      this.ws.onopen = resolve;
      this.ws.onerror = reject;
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
  close() { this.ws.close(); }
}

async function waitForLoad(cdp) {
  for (let i = 0; i < 100; i++) {
    const state = await cdp.send('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true });
    if (state.result?.value === 'complete') break;
    await delay(100);
  }
  await delay(500);
}

async function navigate(cdp, url) {
  await cdp.send('Page.navigate', { url });
  await waitForLoad(cdp);
}

async function evalJs(cdp, expression) {
  return cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
}

async function screenshot(cdp, filename) {
  const metrics = await cdp.send('Page.getLayoutMetrics');
  const width = Math.ceil(metrics.contentSize.width || 1440);
  const height = Math.min(Math.ceil(metrics.contentSize.height || 1000), 2400);
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: Math.max(1000, height), deviceScaleFactor: 1, mobile: false });
  await delay(200);
  const shot = await cdp.send('Page.captureScreenshot', {
    format: 'jpeg',
    quality: 88,
    clip: { x: 0, y: 0, width, height, scale: 1 },
    captureBeyondViewport: true
  });
  const jpgPath = join(outDir, filename.replace(/\.png$/, '.jpg'));
  const pngPath = join(outDir, filename);
  const buf = Buffer.from(shot.data, 'base64');
  await writeFile(jpgPath, buf);
  await writeFile(pngPath, buf);
}

try {
  await waitForChrome();
  const targets = await getJson(`http://127.0.0.1:${port}/json/list`);
  const target = targets.find(t => t.type === 'page') || targets[0];
  const cdp = new CDP(target.webSocketDebuggerUrl);
  await cdp.open();
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Network.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

  await navigate(cdp, base);
  await screenshot(cdp, '01-public-home.png');

  await navigate(cdp, `${base}index.php?page=login`);
  await screenshot(cdp, '02-login.png');
  await evalJs(cdp, `(() => {
    document.querySelector('input[name="email"], input[type="email"]').value = 'admin@pazy.local';
    document.querySelector('input[name="password"], input[type="password"]').value = 'password1234';
    document.querySelector('form').submit();
  })()`);
  await waitForLoad(cdp);
  await screenshot(cdp, '03-dashboard.png');

  const pages = [
    ['04-integrations-marketplace.png', 'integrations'],
    ['05-invoices-ap.png', 'invoices'],
    ['06-procurement.png', 'procurement'],
    ['07-payments.png', 'payments'],
    ['08-reimbursements.png', 'expenses'],
    ['09-reports-audit.png', 'reports']
  ];
  for (const [file, page] of pages) {
    await navigate(cdp, `${base}index.php?page=${page}`);
    await screenshot(cdp, file);
  }
  cdp.close();
  console.log(`desktop screenshots saved to ${outDir}`);
} finally {
  proc.kill('SIGTERM');
}
