// Records how the pinned mermaid 12.1.0 build lays out Markdown string labels: each label's
// visual lines as runs of text with their weight and style, measured in the browser.
// Usage: node markdown-oracle.mjs <porta-cli-browser dir> > markdown-reference.json
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(process.argv[2]);
const server = http.createServer(async (req, res) => {
  try {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><body></body>'); return; }
    const file = path.resolve(root, '.' + decodeURIComponent(req.url.split('?')[0]));
    res.setHeader('Content-Type', file.endsWith('.mjs') || file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
    res.end(await fs.readFile(file));
  } catch { res.writeHead(404); res.end(); }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const labels = [
  '**Bold** and *it* text',
  '***both*** plain',
  '__strong__ and _em_',
  '**bold *nested* bold**',
  'a very long markdown string that should wrap automatically when it exceeds the width',
  'line one\nline two',
  'with<br/>break',
  'a \\*literal\\* star',
  'plain words only',
  'supercalifragilisticexpialidociouslylongword and more',
];
const variants = {
  html: '',
  svg: '---\nconfig:\n  htmlLabels: false\n---\n',
  nowrap: '---\nconfig:\n  markdownAutoWrap: false\n---\n',
};
const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
const cases = [];
try {
  const page = await browser.newPage({ viewport: { width: 2048, height: 1600 } });
  await page.goto('http://127.0.0.1:' + server.address().port + '/');
  await page.evaluate(async () => {
    window.mermaid = (await import('/package/dist/mermaid.esm.min.mjs')).default;
    window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', deterministicIds: true });
  });
  for (const [variant, prefix] of Object.entries(variants)) {
    for (const [index, label] of labels.entries()) {
      const source = prefix + 'flowchart LR\nA["`' + label + '`"]';
      const result = await page.evaluate(async ({ source, id }) => {
        const { svg } = await window.mermaid.render(id, source);
        document.body.innerHTML = svg;
        const node = document.querySelector('g.node');
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
        const runs = [];
        for (let text = walker.nextNode(); text; text = walker.nextNode()) {
          if (!text.textContent.trim()) continue;
          const element = text.parentElement;
          const style = getComputedStyle(element);
          const range = document.createRange();
          for (let offset = 0; offset < text.textContent.length; offset++) {
            range.setStart(text, offset); range.setEnd(text, offset + 1);
            const rect = range.getClientRects()[0];
            if (!rect) continue;
            runs.push({ c: text.textContent[offset], top: Math.round(rect.top), bold: Number(style.fontWeight) >= 600, italic: style.fontStyle === 'italic' });
          }
        }
        const label = node.querySelector('.label');
        const box = label.getBoundingClientRect();
        return { runs, width: Math.round(box.width), height: Math.round(box.height) };
      }, { source, id: 'md-' + variant + '-' + index });
      // Group characters into visual lines by their top edge, then into styled runs.
      const tops = [...new Set(result.runs.map((run) => run.top))].sort((a, b) => a - b);
      const lines = tops.map((top) => {
        const line = [];
        for (const run of result.runs.filter((item) => item.top === top)) {
          const last = line[line.length - 1];
          if (last && last.bold === run.bold && last.italic === run.italic) last.text += run.c;
          else line.push({ text: run.c, bold: run.bold, italic: run.italic });
        }
        return line;
      });
      cases.push({ variant, label, lines, width: result.width, height: result.height });
    }
  }
} finally {
  await browser.close();
  server.close();
}
process.stdout.write(JSON.stringify({ mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', cases }, null, 1));
