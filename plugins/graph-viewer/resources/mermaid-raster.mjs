// The configured official CLI already supplies Puppeteer and an isolated browser.
// Render its SVG snapshot directly: no second Mermaid layout and no loss of HTML/math labels.
import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

let browser;
try {
  const [, , cliPath, input, rawScale, root, browserConfig] = process.argv;
  const cli = await fs.realpath(cliPath);
  let directory = path.dirname(cli);
  let found = false;
  for (let depth = 0; depth < 8; depth++) {
    try {
      const manifest = JSON.parse(await fs.readFile(path.join(directory, 'package.json'), 'utf8'));
      if (manifest.name === '@mermaid-js/mermaid-cli') {
        found = true;
        break;
      }
    } catch {
      // Executable shims need not have a package manifest beside them.
    }
    const parent = path.dirname(directory);
    if (parent === directory) break;
    directory = parent;
  }
  if (!found) throw new Error('The configured tool does not expose the official Mermaid CLI browser');
  const require = createRequire(pathToFileURL(cli));
  const module = require('puppeteer');
  const puppeteer = module.default ?? module;
  const scale = Number(rawScale);
  if (!Number.isFinite(scale) || scale <= 0) throw new Error('Invalid SVG raster scale');
  const svg = await fs.readFile(input, 'utf8');
  const base = pathToFileURL(root + path.sep).href.replaceAll('&', '&amp;').replaceAll('"', '&quot;');
  const html = path.join(path.dirname(input), 'diagram.html');
  await fs.writeFile(html, '<!doctype html><meta charset="utf-8"><base href="' + base + '"><style>html,body{margin:0;padding:0}</style>' + svg);
  // A project not trusted: its diagram's images are not fetched (see MermaidEngine::OFFLINE).
  const launch = browserConfig ? JSON.parse(await fs.readFile(browserConfig, 'utf8')) : {};
  browser = await puppeteer.launch({ headless: 'shell', ...launch });
  const page = await browser.newPage();
  await page.goto(pathToFileURL(html).href, { waitUntil: 'networkidle0' });
  const size = await page.evaluate(async () => {
    await document.fonts.ready;
    const svg = document.querySelector('svg');
    if (!svg) throw new Error('No SVG document');
    const box = svg.viewBox.baseVal;
    const fallback = svg.getBoundingClientRect();
    const width = Math.max(1, Math.ceil(box.width || fallback.width));
    const height = Math.max(1, Math.ceil(box.height || fallback.height));
    svg.style.width = width + 'px';
    svg.style.height = height + 'px';
    svg.style.maxWidth = 'none';
    svg.style.maxHeight = 'none';
    svg.style.display = 'block';
    return { width, height };
  });
  if (size.width * size.height * scale * scale > 16_000_000 || Math.max(size.width, size.height) * scale > 16_000) {
    throw new Error('SVG raster exceeds the image budget');
  }
  await page.setViewport({ ...size, deviceScaleFactor: scale });
  const element = await page.$('svg');
  const png = await element.screenshot({ type: 'png', omitBackground: false });
  await browser.close();
  browser = null;
  await new Promise(resolve => process.stdout.write(png, resolve));
} catch (error) {
  process.stderr.write(String(error?.message ?? error) + '\n');
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
}
