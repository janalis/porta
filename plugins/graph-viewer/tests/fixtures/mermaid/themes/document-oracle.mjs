// Document-level theme resolution in the pinned Mermaid 12.1.0 build: front matter and init
// directives through mermaid.render, plus the flat configKeys set sanitizeDirective consults.
// Usage: node document-oracle.mjs document-reference.json (same browser setup as oracle.mjs)
import { chromium } from '/private/tmp/porta-cli-browser/node_modules/playwright/index.mjs';
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = '/private/tmp/porta-cli-browser';
const flow = 'flowchart TD\nA-->B';
const documents = {
  plain: flow,
  dark: `---\nconfig:\n  theme: dark\n---\n${flow}`,
  baseOverride: `---\nconfig:\n  theme: base\n  themeVariables:\n    primaryColor: "#ff0000"\n---\n${flow}`,
  variablesOnly: `---\nconfig:\n  themeVariables:\n    primaryColor: "#ff0000"\n---\n${flow}`,
  initAfterFrontMatter: `---\nconfig:\n  theme: dark\n---\n%%{init: {"theme": "forest"}}%%\n${flow}`,
  bogusLater: `---\nconfig:\n  theme: dark\n---\n%%{init: {"theme": "bogus"}}%%\n${flow}`,
  scoped: `---\nconfig:\n  flowchart:\n    theme: neutral\n---\n${flow}`,
  otherScope: `---\nconfig:\n  pie:\n    theme: dark\n---\n${flow}`,
  scopedOverGlobal: `---\nconfig:\n  theme: dark\n  flowchart:\n    theme: forest\n---\n${flow}`,
  nullTheme: `---\nconfig:\n  theme: "null"\n---\n${flow}`,
  emptyTheme: `---\nconfig:\n  theme: ""\n  themeVariables:\n    primaryColor: "#00ff00"\n---\n${flow}`,
  fontFamily: `%%{init: {"theme": "base", "fontFamily": "Courier"}}%%\n${flow}`,
  fontFamilyKept: `%%{init: {"theme": "base", "fontFamily": "Courier", "themeVariables": {"fontFamily": "Arial"}}}%%\n${flow}`,
  sanitized: `%%{init: {"theme": "base", "themeVariables": {"primaryColor": "url(data:x)", "secondaryColor": "#00f-", "tertiaryColor": "<b>", "__proto__": {"x": 1}, "nodeBorder": null, "unknownVariable": "#123456", "lineColor": "rgb(1, 2, 3)"}}}%%\n${flow}`,
  mergedInits: `%%{init: {"theme": "base", "themeVariables": {"primaryColor": "#ff0000", "xyChart": {"titleColor": "#111111"}}}}%%\n%%{init: {"themeVariables": {"secondaryColor": "#00ff00", "xyChart": {"plotColorPalette": "#222222"}}}}%%\n${flow}`,
  variablesOnlyDerived: `%%{init: {"themeVariables": {"background": "#000000", "git0": "#ff0000"}}}%%\n${flow}`,
  pieScoped: `---\nconfig:\n  pie:\n    theme: dark\n---\npie\n"A": 1`,
  stateScoped: `---\nconfig:\n  state:\n    theme: dark\n---\nstateDiagram-v2\nA-->B`,
  classScoped: `---\nconfig:\n  class:\n    theme: forest\n---\nclassDiagram\nA<|--B`,
};
const families = {
  flowchart: flow, graph: 'graph TD\nA-->B', sequence: 'sequenceDiagram\nA->>B: x', class: 'classDiagram\nA<|--B', state: 'stateDiagram-v2\nA-->B',
  stateV1: 'stateDiagram\nA-->B', er: 'erDiagram\nA ||--o{ B : x', journey: 'journey\nsection S\nT: 5: A', gantt: 'gantt\nsection S\nT :a, 2024-01-01, 1d',
  pie: 'pie\n"A": 1', quadrant: 'quadrantChart\nA: [0.5, 0.5]', requirement: 'requirementDiagram\nrequirement r {\nid: 1\n}', git: 'gitGraph\ncommit',
  c4: 'C4Context\nPerson(a, "A")', mindmap: 'mindmap\nroot', timeline: 'timeline\n2024 : A', sankey: 'sankey-beta\nA,B,1', xy: 'xychart-beta\nline [1, 2]',
  block: 'block-beta\nA', packet: 'packet-beta\n0-1: "A"', kanban: 'kanban\nTodo\n  t[Task]', architecture: 'architecture-beta\nservice a(server)[A]',
  radar: 'radar-beta\naxis A, B, C\ncurve S{1,2,3}', treemap: 'treemap-beta\n"A": 1', venn: 'venn-beta\nset A', ishikawa: 'ishikawa\nEffect\n  Cause',
  treeView: 'treeView-beta\nroot', cynefin: 'cynefin-beta\nclear\n  "A"', info: 'info', railroad: 'railroad-beta\nrule = "a" ;', usecase: 'usecase-beta\nactor A',
  eventmodeling: 'eventmodeling\ntf 01 rmo A', wardley: 'wardley-beta\ncomponent A [0.5, 0.5]', swimlane: 'swimlane-beta\nA-->B', agentflow: 'agentflow-beta\nA-->B',
};
const server = http.createServer(async (req, res) => {
  try {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><body></body>'); return; }
    const file = path.resolve(root, '.' + decodeURIComponent(req.url.split('?')[0]));
    if (!file.startsWith(root + '/')) { res.writeHead(403); res.end(); return; }
    res.setHeader('Content-Type', file.endsWith('.mjs') || file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
    res.end(await fs.readFile(file));
  } catch { res.writeHead(404); res.end(); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
try {
  const page = await browser.newPage();
  await page.goto('http://127.0.0.1:' + server.address().port + '/');
  const result = await page.evaluate(async ({ documents, families }) => {
    const m = (await import('/package/dist/mermaid.esm.min.mjs')).default;
    m.initialize({ startOnLoad: false });
    const defaults = m.mermaidAPI.defaultConfig;
    const keyify = (obj) => Object.keys(obj).flatMap((k) => Array.isArray(obj[k]) ? [] : (typeof obj[k] === 'object' && obj[k] !== null ? [k, ...keyify(obj[k])] : [k]));
    const configKeys = [...new Set(keyify(defaults))].sort();
    const sectionThemes = Object.fromEntries(Object.entries(defaults).filter(([, v]) => v && typeof v === 'object' && !Array.isArray(v) && ['theme', 'look', 'layout'].some((k) => k in v)).map(([k, v]) => [k, Object.fromEntries(['theme', 'look', 'layout'].filter((x) => v[x] != null).map((x) => [x, v[x]]))]));
    const out = { configKeys, appearance: { theme: defaults.theme, look: defaults.look, layout: defaults.layout }, sectionThemes, secure: defaults.secure, documents: {}, types: {} };
    let n = 0;
    for (const [name, text] of Object.entries(documents)) {
      const host = document.createElement('div');
      document.body.appendChild(host);
      try {
        const { svg } = await m.render('d' + n++, text);
        host.innerHTML = svg;
        const shape = host.querySelector('.node .label-container, .node rect, path.pieCircle, g.node rect');
        const paint = shape ? getComputedStyle(shape) : null;
        out.documents[name] = {
          fill: paint?.fill ?? null,
          stroke: paint?.stroke ?? null,
          look: shape?.closest('[data-look]')?.getAttribute('data-look') ?? null,
        };
      } catch (error) {
        out.documents[name] = { error: String(error.message) };
      } finally {
        host.remove();
      }
    }
    for (const [name, text] of Object.entries(families)) {
      try { out.types[name] = m.detectType(text); } catch (error) { out.types[name] = 'error: ' + error.message; }
    }
    return out;
  }, { documents, families });
  await fs.writeFile(process.argv[2], JSON.stringify({ mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', documentSources: documents, ...result }) + '\n');
  console.log(Object.keys(result.documents).length + ' documents, ' + result.configKeys.length + ' keys');
} finally {
  await browser.close();
  await new Promise((r) => server.close(r));
}
