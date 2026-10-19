// A tiny Puppeteer protocol peer lets the bundled program run without downloading a browser in CI.
const fs = require('node:fs');
const path = require('node:path');
const { fileURLToPath } = require('node:url');
const record = { calls: [] };
let html = '';
const save = () => fs.writeFileSync('browser-protocol.json', JSON.stringify(record));
exports.launch = async options => {
  record.launch = options;
  record.calls.push('launch');
  save();
  return {
    newPage: async () => ({
      goto: async url => {
        html = fs.readFileSync(fileURLToPath(url), 'utf8');
        record.html = html;
        record.calls.push('goto');
      },
      evaluate: async fn => {
        record.calls.push('evaluate');
        const viewBox = html.match(/<svg[^>]*viewBox="([^"]+)"/);
        const numbers = viewBox ? viewBox[1].trim().split(/[\s,]+/).map(Number) : [0, 0, 0, 0];
        let fallback = { width: 100, height: 50 };
        if (html.includes('huge-browser')) fallback = { width: 5_000, height: 5_000 };
        if (html.includes('wide-browser')) fallback = { width: 20_000, height: 1 };
        const svg = /<svg(?:\s|>)/.test(html) ? {
          viewBox: { baseVal: { width: numbers[2], height: numbers[3] } },
          style: {},
          getBoundingClientRect: () => fallback,
        } : null;
        globalThis.document = { fonts: { ready: Promise.resolve() }, querySelector: () => svg };
        try {
          const size = await fn();
          record.svgStyle = svg.style;
          return size;
        } finally {
          delete globalThis.document;
        }
      },
      setViewport: async viewport => {
        record.viewport = viewport;
        record.calls.push('viewport');
      },
      $: async selector => ({
        screenshot: async options => {
          record.selector = selector;
          record.screenshot = options;
          record.calls.push('screenshot');
          return fs.readFileSync(path.join(process.cwd(), 'fixture.png'));
        },
      }),
    }),
    close: async () => {
      record.calls.push('close');
      save();
    },
  };
};
