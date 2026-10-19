// Turns the pinned Mermaid theme classes into the compact programs Porta's PHP interpreter runs.
// Usage: node extract.mjs <acorn 8 dist/acorn.mjs> <themes dir> > resources/mermaid-themes.json
// The themes dir holds packages/mermaid/src/themes/ from commit 21f72f07ea22c0af48a3149c550654e80d8e40cb.
import fs from 'node:fs';
import crypto from 'node:crypto';

const acorn = await import(process.argv[2]);
const dir = process.argv[3].replace(/\/?$/, '/');
const constants = {};
for (const [, name, value] of fs.readFileSync(dir + 'erDiagram-oldHardcodedValues.ts', 'utf8').matchAll(/export const (\w+) = '([^']*)';/g)) {
  constants[name] = value;
}
const functions = new Set(['adjust', 'darken', 'lighten', 'invert', 'isDark', 'rgba', 'mkBorder']);
const calculations = {
  plain: null,
  calculated: null,
  gradient: null,
};

const fail = (node, message) => {
  throw new Error(message + ' at ' + node.start + ': ' + node.type);
};

function key(node) {
  if (node.computed) return expr(node.property);
  if (node.property.type !== 'Identifier') fail(node, 'property');
  return ['lit', node.property.name];
}

function expr(node) {
  switch (node.type) {
    case 'Literal':
      if (node.regex) fail(node, 'regex');
      return ['lit', node.value];
    case 'TemplateLiteral':
      if (node.expressions.length) fail(node, 'template');
      return ['lit', node.quasis[0].value.cooked];
    case 'ThisExpression':
      return ['this'];
    case 'Identifier':
      if (node.name in constants) return ['lit', constants[node.name]];
      if (node.name === 'undefined') return ['lit', null];
      return ['id', node.name];
    case 'ChainExpression':
      return expr(node.expression);
    case 'MemberExpression':
      if (!node.computed && node.property.name === 'length') return ['length', expr(node.object)];
      return ['get', expr(node.object), key(node), node.optional ? 1 : 0];
    case 'BinaryExpression':
      if (!['+', '-', '*', '/', '%', '<', '>', '<=', '>=', '===', '!=='].includes(node.operator)) fail(node, node.operator);
      return ['bin', node.operator, expr(node.left), expr(node.right)];
    case 'LogicalExpression':
      return [{'||': 'or', '&&': 'and', '??': 'nullish'}[node.operator], expr(node.left), expr(node.right)];
    case 'ConditionalExpression':
      return ['cond', expr(node.test), expr(node.consequent), expr(node.alternate)];
    case 'UnaryExpression':
      if (!['-', '+', '!'].includes(node.operator)) fail(node, node.operator);
      return ['unary', node.operator, expr(node.argument)];
    case 'CallExpression':
      if (node.callee.type !== 'Identifier' || !functions.has(node.callee.name)) fail(node, 'call');
      return ['call', node.callee.name, node.arguments.map(expr)];
    case 'ObjectExpression':
      return ['object', node.properties.map((p) => {
        if (p.type !== 'Property' || p.kind !== 'init' || p.computed || p.method) fail(p, 'property');
        return [p.key.type === 'Identifier' ? p.key.name : String(p.key.value), expr(p.value)];
      })];
    case 'ArrayExpression':
      return ['array', node.elements.map(expr)];
    default:
      fail(node, 'expression');
  }
}

function target(node) {
  if (node.type === 'Identifier') return ['id', node.name];
  if (node.type === 'MemberExpression') return ['get', expr(node.object), key(node), 0];
  fail(node, 'target');
}

function statement(node) {
  switch (node.type) {
    case 'BlockStatement':
      return ['block', node.body.map(statement)];
    case 'VariableDeclaration':
      if (node.declarations.length !== 1) fail(node, 'declarations');
      return ['let', node.declarations[0].id.name, expr(node.declarations[0].init)];
    case 'IfStatement':
      return ['if', expr(node.test), statement(node.consequent), node.alternate ? statement(node.alternate) : null];
    case 'ForStatement':
      if (node.update.type !== 'UpdateExpression' || node.update.operator !== '++') fail(node, 'update');
      return ['for', statement(node.init), expr(node.test), node.update.argument.name, statement(node.body)];
    case 'ExpressionStatement': {
      const e = node.expression;
      if (e.type === 'AssignmentExpression') {
        if (e.operator !== '=') fail(e, e.operator);
        return ['set', target(e.left), expr(e.right)];
      }
      if (e.type === 'CallExpression' && e.callee.type === 'MemberExpression' && e.callee.object.type === 'ThisExpression' && e.callee.property.name === 'updateColors') {
        return ['updateColors'];
      }
      if (e.type === 'CallExpression' && e.callee.type === 'MemberExpression' && e.callee.property.name === 'forEach') {
        const callback = e.arguments[0];
        if (callback.type !== 'ArrowFunctionExpression' || callback.params.length !== 2) fail(callback, 'forEach');
        return ['each', expr(e.callee.object), callback.params[0].name, callback.params[1].name, statement(callback.body)];
      }
      fail(e, 'statement');
    }
    default:
      fail(node, 'statement');
  }
}

const hash = (text) => crypto.createHash('sha256').update(text).digest('hex');
const themes = {};
const sources = {};
const names = {
  base: 'theme-base.js', dark: 'theme-dark.js', default: 'theme-default.js', forest: 'theme-forest.js', neutral: 'theme-neutral.js',
  neo: 'theme-neo.js', 'neo-dark': 'theme-neo-dark.js', redux: 'theme-redux.js', 'redux-dark': 'theme-redux-dark.js',
  'redux-color': 'theme-redux-color.js', 'redux-dark-color': 'theme-redux-dark-color.js',
};
const index = fs.readFileSync(dir + 'index.js', 'utf8');
for (const [name, file] of Object.entries(names)) {
  if (!index.includes(`'./${file}'`)) throw new Error('index does not register ' + file);
  const text = fs.readFileSync(dir + file, 'utf8');
  sources[file] = hash(text);
  const ast = acorn.parse(text, {ecmaVersion: 2023, sourceType: 'module'});
  const cls = ast.body.find((n) => n.type === 'ClassDeclaration');
  const methods = Object.fromEntries(cls.body.body.map((m) => [m.key.name, m]));
  if (Object.keys(methods).sort().join() !== 'calculate,constructor,updateColors') throw new Error(file + ' methods');
  const calculate = text.slice(methods.calculate.start, methods.calculate.end);
  const kind = calculate.includes("=== 'calculated'") ? 'calculated' : (calculate.includes("'useGradient'") ? 'gradient' : 'plain');
  calculations[kind] ??= hash(calculate);
  if (calculations[kind] !== hash(calculate)) throw new Error(file + ' has an unknown calculate variant');
  themes[name] = {
    calculate: kind,
    constructor: methods.constructor.value.body.body.map(statement),
    updateColors: methods.updateColors.value.body.body.map(statement),
  };
}
for (const file of ['theme-helpers.js', 'erDiagram-oldHardcodedValues.ts', 'index.js']) {
  sources[file] = hash(fs.readFileSync(dir + file, 'utf8'));
}
const helpers = fs.readFileSync(dir + 'theme-helpers.js', 'utf8');
if (!helpers.includes("darkMode ? adjust(col, { s: -40, l: 10 }) : adjust(col, { s: -40, l: -10 })")) throw new Error('mkBorder changed');
process.stdout.write(JSON.stringify({
  mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb',
  sources,
  calculations,
  themes,
}) + '\n');
