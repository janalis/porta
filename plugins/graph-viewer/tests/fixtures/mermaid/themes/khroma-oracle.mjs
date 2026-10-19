// khroma 2.1.0 and ECMAScript Number#toString reference cases for Porta's PHP port.
// Usage: npm install khroma@2.1.0 in a scratch directory, then
//   node khroma-oracle.mjs <khroma dist/index.js> > khroma-reference.json
const khroma = await import(process.argv[2]);
const colors = [
  '#abc', '#abcd', '#a1b2c3', '#a1b2c380', '#FFF', '#000000', '#ffffff00', '#7f7f7f',
  'rgb(10, 20, 30)', 'RGB(10 20 30)', 'rgba(10, 20, 30, 0.5)', 'rgba(10, 20, 30, 50%)', 'rgb(12.5%, 50%, 100%)', 'rgb(300, -5, 1e2)', 'rgb(1.5, 2.25, 3)',
  'hsl(120, 50%, 40%)', 'hsla(120, 50%, 40%, 0.25)', 'hsl(1.5turn, 20%, 90%)', 'hsl(100grad, 20%, 90%)', 'hsl(1rad, 20%, 90%)', 'hsl(90DEG, 20%, 90%)', 'hsl(90deg 20% 90% / 40%)', 'hsl(-30, 150%, -5%)', 'HSL(400, 10%, 10%)', 'hsl(0, 0%, 50%)',
  'white', 'RebeccaPurple', 'transparent', 'cyanaqua', 'lightgoldenrodyellow', 'black',
];
const invalid = ['tomato', 'cyan', 'steelblue', '', '#ab', 'hsl(1, 2, 3)', 'rgb(1,2)', 'nope'];
const run = (f) => { try { return { value: f() }; } catch (error) { return { error: String(error.message) }; } };
const cases = [];
for (const color of [...colors, ...invalid]) {
  cases.push({ color, op: 'parse', ...run(() => { const c = khroma.toRgba(color); return c; }) });
  for (const [op, f] of Object.entries({
    darken10: () => khroma.darken(color, 10),
    lighten25: () => khroma.lighten(color, 25),
    lighten0: () => khroma.lighten(color, 0),
    invert: () => khroma.invert(color),
    isDark: () => khroma.isDark(color),
    adjustHue: () => khroma.adjust(color, { h: -120 }),
    adjustAll: () => khroma.adjust(color, { h: 180, s: -15, l: 5 }),
    adjustZero: () => khroma.adjust(color, { h: 0, s: 0 }),
    mkBorderLight: () => khroma.adjust(color, { s: -40, l: -10 }),
    mkBorderDark: () => khroma.adjust(color, { s: -40, l: 10 }),
    adjustRgb: () => khroma.adjust(color, { r: 10, g: -300, b: 0.5 }),
    alpha: () => khroma.rgba(color, 0.5),
    alphaOver: () => khroma.rgba(color, 3),
  })) {
    cases.push({ color, op, ...run(f) });
  }
}
const rgba = [[0, 0, 0, 0.25], [102, 102, 255, 0.49], [185, 185, 185, 1], [255, 255, 255, 50], [12.4, 300, -2, 1], [12.5, 0, 0, 1], [1, 2, 3, -1]];
for (const args of rgba) cases.push({ op: 'rgba', args, ...run(() => khroma.rgba(...args)) });
const numbers = [0, -0, 1, -1, 46.2745098039, 0.1, 1e-7, 1.5e-7, 0.000001, 0.0000015, 123456789012345680000, 1e21, 1.25e21, -3.5e-9, 2.55, 19.0000000001, 1 / 3, 12, 1e300, 255.99999999999997];
const strings = numbers.map((n) => ({ number: n, text: String(n) }));
process.stdout.write(JSON.stringify({ khroma: '2.1.0', cases, numbers: strings }) + '\n');
