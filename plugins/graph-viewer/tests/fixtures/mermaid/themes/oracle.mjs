// Dumps every resolved themeVariables value from the pinned Mermaid 12.1.0 build in Chrome.
// Usage: node oracle.mjs theme-reference.json, with the npm mermaid@12.1.0 package unpacked
// under /private/tmp/porta-cli-browser/package and Playwright installed beside it.
import { chromium } from '/private/tmp/porta-cli-browser/node_modules/playwright/index.mjs';
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = '/private/tmp/porta-cli-browser';
const pinned = JSON.parse(await fs.readFile(root + '/package/package.json', 'utf8'));
if (pinned.version !== '12.1.0') throw new Error('Unexpected Mermaid ' + pinned.version);
const themes = ['default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark', 'redux', 'redux-dark', 'redux-color', 'redux-dark-color'];
const overrides = {
  none: null,
  primary: { primaryColor: '#ff0000' },
  dark: { darkMode: true, primaryColor: '#1f2020', background: '#333' },
  formats: { primaryColor: 'hsl(120, 50%, 40%)', lineColor: 'rgb(10, 20, 30)', fontSize: '20px', noteBkgColor: 'RebeccaPurple', secondaryColor: '#abc8' },
  nested: { xyChart: { titleColor: '#123456' }, pie1: '#abcdef', cScale0: '#00ff00', git0: '#ff00aa', radar: { axisColor: 'red' } },
  border: { nodeBorder: '#0000ff' },
  gradient: { nodeBorder: '#0000ff', useGradient: true },
  calculated: { secondBkg: 'calculated', scaleLabelColor: 'calculated', labelTextColor: 'calculated' },
  transparent: { primaryColor: 'transparent', tertiaryColor: 'hsla(30, 100%, 50%, 0.5)', background: '#000' },
  falsy: { primaryTextColor: '', lineColor: 0, mainBkg: false, textColor: null },
  scale: { cScale1: 'rgb(12.5%, 50%, 100%)', THEME_COLOR_LIMIT: 4, primaryColor: 'hsl(1.5turn, 20%, 90%)' },
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
  const cases = await page.evaluate(async ({ themes, overrides }) => {
    // The bundled theme registry (themes/index.js); its export name is the minifier's.
    const registry = (await import('/package/dist/chunks/mermaid.esm.min/chunk-VSAMGZVZ.mjs')).i;
    if (Object.keys(registry).join() !== themes.join() && Object.keys(registry).sort().join() !== [...themes].sort().join()) throw new Error('registry ' + Object.keys(registry));
    const result = [];
    for (const theme of themes) {
      for (const [name, themeVariables] of Object.entries(overrides)) {
        try {
          const variables = registry[theme].getThemeVariables(themeVariables === null ? undefined : structuredClone(themeVariables));
          result.push({ theme, overrides: name, variables: JSON.parse(JSON.stringify(variables)) });
        } catch (error) {
          result.push({ theme, overrides: name, error: String(error.message) });
        }
      }
    }
    return result;
  }, { themes, overrides });
  await fs.writeFile(process.argv[2], JSON.stringify({ mermaid: pinned.version, mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', overrides, cases }) + '\n');
  console.log(cases.length + ' cases');
} finally {
  await browser.close();
  await new Promise((r) => server.close(r));
}
