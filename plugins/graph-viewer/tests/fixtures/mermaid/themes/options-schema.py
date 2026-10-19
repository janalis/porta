"""Writes resources/mermaid-options.json: every pinned option per family, its kind and default.

Sources, both from mermaid 12.1.0 (commit 21f72f07): dist/config.type.d.ts (the generated
config interfaces) and default-config.json (defaultConfig as the pinned build exports it).
Usage: python3 options-schema.py config.type.d.ts default-config.json > ../../../../resources/mermaid-options.json
Kinds: boolean, number, size (number or CSS text), string, list, map, enum values, or any.
"""
import json
import re
import sys

source = open(sys.argv[1]).read()
defaults = json.load(open(sys.argv[2]))
aliases = {m.group(1): m.group(2).strip() for m in re.finditer(r"export type (\w+) = ([^;]+);", source)}
interfaces = {}
for match in re.finditer(r"export interface (\w+)(?: extends ([\w, ]+))? \{(.*?)\n\}", source, re.S):
    properties = {p.group(1): re.sub(r"\s+", " ", p.group(2)).strip() for p in re.finditer(r"\n    (\w+)\??: ([^;]+);", match.group(3))}
    interfaces[match.group(1)] = (properties, [e.strip() for e in (match.group(2) or '').split(',') if e.strip()])


def flatten(name):
    properties, parents = interfaces[name]
    merged = {}
    for parent in parents:
        merged.update(flatten(parent) if parent in interfaces else {})
    merged.update(properties)
    return merged


def kind(text):
    text = aliases.get(text, text).replace('| undefined', '').strip()
    if re.fullmatch(r"('[^']*'\s*\|?\s*)+", text):
        return re.findall(r"'([^']*)'", text)
    if text in ('boolean', 'number', 'string'):
        return text
    if text in ('string | number', 'number | string'):
        return 'size'
    if text.endswith('[]'):
        return 'list'
    if text in interfaces:
        return {key: kind(value) for key, value in flatten(text).items()}
    if text.startswith('{ [k: string]'):
        return 'map'
    return 'any'


def inferred(value):
    return {bool: 'boolean', int: 'number', float: 'number', str: 'string', list: 'list'}.get(type(value), 'any')


families = {}
root = {}
for key, text in flatten('MermaidConfig').items():
    base = aliases.get(text, text).replace('| undefined', '').strip()
    if base in interfaces and 'BaseDiagramConfig' in interfaces[base][1]:
        families[key] = {name: {'kind': kind(value)} for name, value in flatten(base).items()}
    else:
        root[key] = {'kind': kind(text)}
# The pinned treemap options exist only as defaults; their kinds follow the default values.
for family, values in defaults.items():
    if isinstance(values, dict) and family not in families and family not in ('elk',):
        families[family] = {name: {'kind': inferred(value)} for name, value in values.items()}
for family, options in families.items():
    for name, option in options.items():
        if name in (defaults.get(family) or {}):
            option['default'] = defaults[family][name]
for name, option in root.items():
    if name in defaults and not isinstance(defaults[name], dict):
        option['default'] = defaults[name]
json.dump({'mermaidCommit': '21f72f07ea22c0af48a3149c550654e80d8e40cb', 'root': root, 'families': families}, sys.stdout, sort_keys=True, separators=(',', ':'))
