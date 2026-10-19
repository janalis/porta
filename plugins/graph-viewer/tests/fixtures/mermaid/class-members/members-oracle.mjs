// Pinned Mermaid 12.1.0 `ClassMember` and `parseGenericTypes`, copied verbatim from the
// build's own chunks (sanitizeText is the identity for these plain inputs), applied to the
// cases below. Usage: node members-oracle.mjs members-reference.json
import fs from 'node:fs/promises';
const __name = (f) => f;
const sanitizeText = (t) => t;
const getConfig = () => ({});
var parseGenericTypes = /* @__PURE__ */ __name(function(input) {
  const inputSets = input.split(/(,)/);
  const output = [];
  for (let i = 0; i < inputSets.length; i++) {
    let thisSet = inputSets[i];
    if (thisSet === "," && i > 0 && i + 1 < inputSets.length) {
      const previousSet = inputSets[i - 1];
      const nextSet = inputSets[i + 1];
      if (shouldCombineSets(previousSet, nextSet)) {
        thisSet = previousSet + "," + nextSet;
        i++;
        output.pop();
      }
    }
    output.push(processSet(thisSet));
  }
  return output.join("");
}, "parseGenericTypes");
var countOccurrence = /* @__PURE__ */ __name((string, substring) => {
  return Math.max(0, string.split(substring).length - 1);
}, "countOccurrence");
var shouldCombineSets = /* @__PURE__ */ __name((previousSet, nextSet) => {
  const prevCount = countOccurrence(previousSet, "~");
  const nextCount = countOccurrence(nextSet, "~");
  return prevCount === 1 && nextCount === 1;
}, "shouldCombineSets");
var processSet = /* @__PURE__ */ __name((input) => {
  const tildeCount = countOccurrence(input, "~");
  let hasStartingTilde = false;
  if (tildeCount <= 1) {
    return input;
  }
  if (tildeCount % 2 !== 0 && input.startsWith("~")) {
    input = input.substring(1);
    hasStartingTilde = true;
  }
  const chars = [...input];
  let first = chars.indexOf("~");
  let last = chars.lastIndexOf("~");
  while (first !== -1 && last !== -1 && first !== last) {
    chars[first] = "<";
    chars[last] = ">";
    first = chars.indexOf("~");
    last = chars.lastIndexOf("~");
  }
  if (hasStartingTilde) {
    chars.unshift("~");
  }
  return chars.join("");
}, "processSet");

var visibilityValues = ["#", "+", "~", "-", ""];
var ClassMember = class {
  static {
    __name(this, "ClassMember");
  }
  constructor(input, memberType) {
    this.memberType = memberType;
    this.visibility = "";
    this.classifier = "";
    this.text = "";
    const sanitizedInput = sanitizeText(input, getConfig());
    this.parseMember(sanitizedInput);
  }
  getDisplayDetails() {
    let displayText = this.visibility + parseGenericTypes(this.id);
    if (this.memberType === "method") {
      displayText += `(${parseGenericTypes(this.parameters.trim())})`;
      if (this.returnType) {
        displayText += " : " + parseGenericTypes(this.returnType);
      }
    }
    displayText = displayText.trim();
    const cssStyle = this.parseClassifier();
    return {
      displayText,
      cssStyle
    };
  }
  parseMember(input) {
    let potentialClassifier = "";
    if (this.memberType === "method") {
      const methodRegEx = /([#+~-])?(.+)\((.*)\)([\s$*])?(.*)([$*])?/;
      const match = methodRegEx.exec(input);
      if (match) {
        const detectedVisibility = match[1] ? match[1].trim() : "";
        if (visibilityValues.includes(detectedVisibility)) {
          this.visibility = detectedVisibility;
        }
        this.id = match[2];
        this.parameters = match[3] ? match[3].trim() : "";
        potentialClassifier = match[4] ? match[4].trim() : "";
        this.returnType = match[5] ? match[5].trim() : "";
        if (potentialClassifier === "") {
          const lastChar = this.returnType.substring(this.returnType.length - 1);
          if (/[$*]/.exec(lastChar)) {
            potentialClassifier = lastChar;
            this.returnType = this.returnType.substring(0, this.returnType.length - 1);
          }
        }
      }
    } else {
      const length = input.length;
      const firstChar = input.substring(0, 1);
      const lastChar = input.substring(length - 1);
      if (visibilityValues.includes(firstChar)) {
        this.visibility = firstChar;
      }
      if (/[$*]/.exec(lastChar)) {
        potentialClassifier = lastChar;
      }
      this.id = input.substring(
        this.visibility === "" ? 0 : 1,
        potentialClassifier === "" ? length : length - 1
      );
    }
    this.classifier = potentialClassifier;
    this.id = this.id.startsWith(" ") ? " " + this.id.trim() : this.id.trim();
    const combinedText = `${this.visibility ? "\\" + this.visibility : ""}${parseGenericTypes(this.id)}${this.memberType === "method" ? `(${parseGenericTypes(this.parameters)})${this.returnType ? " : " + parseGenericTypes(this.returnType) : ""}` : ""}`;
    this.text = combinedText.replaceAll("<", "&lt;").replaceAll(">", "&gt;");
    if (this.text.startsWith("\\&lt;")) {
      this.text = this.text.replace("\\&lt;", "~");
    }
  }
  parseClassifier() {
    switch (this.classifier) {
      case "*":
        return "font-style:italic;";
      case "$":
        return "text-decoration:underline;";
      default:
        return "";
    }
  }
};


const cases = [
  ['+int age', 'attribute'], ['+String gender', 'attribute'], ['-List~int~ ids', 'attribute'], ['+isMammal()$', 'method'],
  ['+mate()*', 'method'], ['+quack() bool', 'method'], ['+quack()$ bool', 'method'], ['+quack() bool*', 'method'],
  ['#run(List~T~ items, Map~K,V~ map) List~List~T~~', 'method'], ['~internal', 'attribute'], ['size$', 'attribute'],
  ['shape*', 'attribute'], ['+ spaced', 'attribute'], ['+ spaced()', 'method'], ['~T~ value', 'attribute'],
  ['Map~K, V~ pairs', 'attribute'], ['a~b~c~d', 'attribute'], ['~lone~tilde~', 'attribute'], ['compute(int a, int b)$ int', 'method'],
  ['nested(Map~String,List~Int~~)', 'method'], ['-x()*  void', 'method'], ['$', 'attribute'], ['run(~T~ x)', 'method'],
];
const out = cases.map(([input, kind]) => { const m = new ClassMember(input, kind); const d = m.getDisplayDetails(); return { input, kind, text: d.displayText, style: d.cssStyle }; });
await fs.writeFile(process.argv[2], JSON.stringify({ mermaid: '12.1.0', mermaidCommit: '21f72f07ea22c0af48a3149c550654e80d8e40cb', cases: out }, null, 1) + '\n');
console.log(out.length + ' cases');
