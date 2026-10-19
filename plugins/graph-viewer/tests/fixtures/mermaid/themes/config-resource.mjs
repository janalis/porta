// Writes resources/mermaid-config.json from document-reference.json (document-oracle.mjs output).
// Usage: node config-resource.mjs document-reference.json > ../../../../resources/mermaid-config.json
import fs from 'node:fs';
const reference = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify({
  mermaidCommit: reference.mermaidCommit,
  appearance: reference.appearance,
  sections: reference.sectionThemes,
  secure: reference.secure,
  configKeys: reference.configKeys,
  diagramTypes: reference.types,
}));
