// roughjs 4.6.6 as bundled by the pinned Mermaid 12.1.0 build: the drawing operations its
// generator returns for the options Mermaid's hand-drawn look passes, per shape and seed.
// Usage, from /private/tmp/porta-cli-browser: node rough-oracle.mjs rough-reference.json
import fs from 'node:fs/promises';
import { at as rough } from '/private/tmp/porta-cli-browser/package/dist/chunks/mermaid.esm/chunk-AWAWVJFC.mjs';

const node = { roughness: 0.7, fill: '#ffffff', fillStyle: 'hachure', fillWeight: 1.5, hachureGap: 1.5, stroke: '#333333', strokeWidth: 1.3 };
const shapes = {
  rect: [[-60, -20], [60, -20], [60, 20], [-60, 20]],
  diamond: [[0, -50], [50, 0], [0, 50], [-50, 0]],
  hexagon: [[10, 0], [90, 0], [100, 15], [90, 30], [10, 30], [0, 15]],
  wide: [[0, 0], [320, 0], [320, 12], [0, 12]],
  huge: [[0, 0], [640, 0], [640, 8], [0, 8]],
  sliver: [[0, 0], [6, 0], [6, 3], [0, 3]],
  concave: [[0, 0], [80, 0], [80, 60], [40, 20], [0, 60]],
};
const paths = {
  edge: [[10, 10], [10, 80], [120, 80]],
  segment: [[0, 0], [250, 40]],
  tiny: [[0, 0], [3, 4]],
  damped: [[0, 0], [200, 0], [200, 450], [500, 850]],
};
const generator = rough.generator();
const sets = (drawable) => drawable.sets.map((set) => ({ type: set.type, ops: set.ops.map((op) => [op.op, ...op.data]) }));
const cases = [];
for (const seed of [1, 42, 123456789, -7]) {
  for (const [name, points] of Object.entries(seed === 1 || seed === -7 ? shapes : {})) {
    cases.push({ kind: 'polygon', name, seed, points, sets: sets(generator.polygon(points.map((p) => [...p]), { ...node, seed })) });
  }
  for (const [name, points] of Object.entries(paths)) {
    cases.push({ kind: 'line', name, seed, roughness: 0.3, points, sets: sets(generator.linearPath(points.map((p) => [...p]), { roughness: 0.3, seed })) });
    cases.push({ kind: 'line', name, seed, roughness: 0.7, points, sets: sets(generator.linearPath(points.map((p) => [...p]), { ...node, fill: undefined, seed })) });
  }
}
await fs.writeFile(process.argv[2], JSON.stringify({ roughjs: '4.6.6', mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', node, cases }) + '\n');
console.log(cases.length + ' cases');
