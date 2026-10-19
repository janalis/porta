// Computed paint of every drawn element, per family and theme, from the pinned Mermaid 12.1.0
// build in Chrome. Native palettes are checked against these colors, not against guesses.
// Usage: node census-oracle.mjs census-reference.json (same browser setup as oracle.mjs)
import { chromium } from '/private/tmp/porta-cli-browser/node_modules/playwright/index.mjs';
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = '/private/tmp/porta-cli-browser';
const families = {
  flowchart: 'flowchart TD\nA[Start]-->B{Choice}\nB-->|yes|C(Round)\nsubgraph S[Group]\nC-->D[(Store)]\nend\nB-.->E((Circle))',
  sequence: 'sequenceDiagram\nparticipant A\nactor B\nA->>B: Hello\nNote right of B: Think\nloop Every\nB-->>A: Reply\nend\nactivate A\nA->>A: Self\ndeactivate A',
  class: 'classDiagram\nclass Animal{\n+name\n+eat()\n}\nnamespace Zoo {\nclass Cage\n}\nAnimal <|-- Dog\nAnimal *-- Cage\nnote for Dog "Woof"',
  state: 'stateDiagram-v2\n[*] --> Still\nstate Moving {\n[*] --> Slow\nSlow --> Fast\n}\nStill --> Moving\nstate choice <<choice>>\nMoving --> choice\nchoice --> [*]\nnote right of Still: Idle',
  er: 'erDiagram\nCUSTOMER ||--o{ ORDER : places\nCUSTOMER {\nstring name PK\nint age\n}',
  journey: 'journey\ntitle Day\nsection Work\nCode: 5: Me\nTest: 3: Me, You\nsection Home\nRest: 1: Me',
  gantt: 'gantt\ndateFormat YYYY-MM-DD\nsection A\nTask one :done, t1, 2024-01-01, 3d\nTask two :active, t2, after t1, 2d\nsection B\nMilestone :milestone, m1, 2024-01-06, 0d\nCritical :crit, t3, 2024-01-04, 2d',
  pie: 'pie title Pets\n"Dogs": 40\n"Cats": 30\n"Fish": 20\n"Birds": 10',
  quadrant: 'quadrantChart\ntitle Reach\nx-axis Low --> High\ny-axis Low --> High\nquadrant-1 Expand\nquadrant-2 Promote\nquadrant-3 Re-evaluate\nquadrant-4 Improve\nA: [0.3, 0.6]\nB: [0.7, 0.2]',
  requirement: 'requirementDiagram\nrequirement r1 {\nid: 1\ntext: Must work\nrisk: high\nverifymethod: test\n}\nelement e1 {\ntype: sim\n}\ne1 - satisfies -> r1',
  git: 'gitGraph\ncommit\nbranch dev\ncheckout dev\ncommit tag: "v1"\ncheckout main\nmerge dev\ncommit type: HIGHLIGHT',
  c4: 'C4Context\nPerson(a, "User")\nSystem(s, "System")\nSystem_Ext(x, "External")\nRel(a, s, "Uses")',
  mindmap: 'mindmap\nroot((Root))\n  A[Square]\n    A1\n  B(Rounded)\n  C{{Hex}}',
  timeline: 'timeline\ntitle History\nsection Old\n2020 : A : B\n2021 : C\nsection New\n2022 : D',
  sankey: 'sankey-beta\nA,B,10\nA,C,5\nB,D,7',
  xy: 'xychart-beta\ntitle "Sales"\nx-axis [a, b, c]\ny-axis "Units" 0 --> 10\nbar [3, 5, 7]\nline [2, 6, 4]',
  block: 'block-beta\ncolumns 2\nA B\nC:2\nA --> B',
  packet: 'packet-beta\n0-7: "Source"\n8-15: "Dest"\n16-31: "Data"',
  kanban: 'kanban\nTodo\n  t1[Write]\nDone\n  t2[Ship]',
  architecture: 'architecture-beta\ngroup g(cloud)[Cloud]\nservice db(database)[DB] in g\nservice api(server)[API] in g\napi:R -- L:db',
  radar: 'radar-beta\naxis A, B, C\ncurve S1{1,2,3}\ncurve S2{3,2,1}',
  treemap: 'treemap-beta\n"Root"\n  "A": 10\n  "B": 5',
  venn: 'venn-beta\nset A\nset B\nunion A,B',
  ishikawa: 'ishikawa\nEffect\n  Cause\n    Sub',
  treeView: 'treeView-beta\nroot\n  child\n    leaf',
  cynefin: 'cynefin-beta\nclear\n  "Known"\ncomplex\n  "Probe"',
  info: 'info',
  railroad: 'railroad-ebnf-beta\nletter = "a" | "b" ;\nidentifier = letter , { letter } ;',
  usecase: "usecase-beta\n    direction LR\n    actor Customer(\"Customer\")\n    actor Admin(\"Administrator\")\n    Browse(\"Browse catalog\")\n    Checkout(\"Place order\")\n    Manage[Manage catalog]\n    Customer --> Browse\n    Customer --> Checkout\n    Admin --> Manage",
  eventmodeling: 'eventmodeling\ntf 01 ui Screen\ntf 02 cmd Do\ntf 03 evt Done',
  wardley: 'wardley-beta\ntitle Map\ncomponent A [0.8, 0.3]\ncomponent B [0.4, 0.6]\nA -> B',
  swimlane: 'swimlane-beta LR\nsubgraph One\nA-->B\nend\nsubgraph Two\nC\nend\nB-->C',
  agentflow: 'agentflow-beta\nflow F\nA-->B\nend',
};
const variants = {
  unconfigured: '',
  ...Object.fromEntries(['default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark', 'redux', 'redux-dark', 'redux-color', 'redux-dark-color', 'null'].map((t) => [t, `---\nconfig:\n  theme: "${t}"\n---\n`])),
  override: '---\nconfig:\n  theme: base\n  themeVariables:\n    primaryColor: "#ff0000"\n    lineColor: "#00aa00"\n---\n',
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
  const census = await page.evaluate(async ({ families, variants }) => {
    const m = (await import('/package/dist/mermaid.esm.min.mjs')).default;
    m.initialize({ startOnLoad: false });
    const result = {};
    let n = 0;
    const chain = (el, svg) => {
      const parts = [];
      for (let e = el; e && e !== svg && parts.length < 5; e = e.parentElement) {
        const cls = (e.getAttribute('class') || '').trim().split(/\s+/).filter((c) => c && !/^(?:edge-|flowchart-link|LS-|LE-|mermaid-|id-)/.test(c) && !/\d{2,}/.test(c)).join('.');
        parts.push(e.tagName.toLowerCase() + (cls ? '.' + cls : ''));
      }
      return parts.join(' < ');
    };
    for (const [family, body] of Object.entries(families)) {
      result[family] = {};
      for (const [variant, prefix] of Object.entries(variants)) {
        const host = document.createElement('div');
        document.body.appendChild(host);
        try {
          const { svg } = await m.render('c' + n++, prefix + body);
          host.innerHTML = svg;
          const root = host.querySelector('svg');
          const seen = new Map();
          for (const el of root.querySelectorAll('rect, path, circle, ellipse, polygon, polyline, line, text, tspan, span, p')) {
            const cs = getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            const paint = { fill: cs.fill, stroke: cs.stroke, strokeWidth: cs.strokeWidth, color: cs.color, opacity: cs.opacity, fillOpacity: cs.fillOpacity, filter: cs.filter, look: el.closest('[data-look]')?.getAttribute('data-look') ?? null, colorId: el.closest('[data-color-id]')?.getAttribute('data-color-id') ?? null };
            const key = chain(el, root) + ' ' + JSON.stringify(paint);
            if (!seen.has(key)) seen.set(key, { element: chain(el, root), ...paint, count: 0 });
            seen.get(key).count++;
          }
          result[family][variant] = { background: getComputedStyle(root).backgroundColor, elements: [...seen.values()] };
        } catch (error) {
          result[family][variant] = { error: String(error.message) };
        } finally {
          host.remove();
        }
      }
    }
    return result;
  }, { families, variants });
  await fs.writeFile(process.argv[2], JSON.stringify({ mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', families, variants, census }) + '\n');
  console.log(Object.keys(census).length + ' families');
} finally {
  await browser.close();
  await new Promise((r) => server.close(r));
}
