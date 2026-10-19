// Writes d3-format-reference.json: d3-format as bundled in the pinned mermaid 12.1.0 build.
// Usage: node d3-format-oracle.mjs <mermaid package dir> > d3-format-reference.json
import { pathToFileURL } from 'node:url';
const chunk = await import(pathToFileURL(`${process.argv[2]}/dist/chunks/mermaid.esm/chunk-OHNBJXQ2.mjs`).href);
const specifiers = [',', '', '$', '$,', ',.2f', '.2f', '.0f', 'd', ',d', '.3s', 's', '.2s', '~s', '.1%', '%', '.2e', 'e', '.3r', 'r', '.3g', 'g', '~g', '.2~f', 'x', 'X', '#x', 'b', '#b', 'o', '+,', '(,.2f', ' ,', '08,d', '0>8', '*^9', '<9', '=+9,', '010,.2f', 'c', '.0%', 'p', '.2p', 'n', ',.0f'];
const values = [0, 1, -1, 0.5, 1234.5678, -1234.5678, 1e6, 0.000123, 42, 999.999, 1234567.891, 1e21, -0.0001, 12.345, 1e-30, 4.2e-27];
const cases = [];
for (const specifier of specifiers) {
  for (const value of values) {
    let output;
    try { output = chunk.format(specifier)(value); } catch (error) { output = { error: String(error.message) }; }
    cases.push({ specifier, value, output });
  }
}
for (const specifier of ['abc', '.2q']) {
  try { chunk.format(specifier); cases.push({ specifier, invalid: false }); } catch { cases.push({ specifier, invalid: true }); }
}
process.stdout.write(JSON.stringify({ mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', cases }, null, 1));
