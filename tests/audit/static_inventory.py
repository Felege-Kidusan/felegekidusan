#!/usr/bin/env python3
"""Reproducible full-repository inventory, not a claim of manual line review.

Run from any directory. Records every tracked file and newly added source,
function declarations, UI controls, literal PHP route references, and simple
bind_param arity errors. Dynamic paths/calls require runtime/manual review.
"""
import csv
import hashlib
import json
import re
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'docs/audits/production-2026-09-07'
SOURCE = {'.php', '.js', '.dart', '.py', '.sql', '.sh', '.html', '.css', '.htaccess'}


def group(path):
    if path.startswith('vendor/') or '/tcpdf/' in path or '/phpqrcode/' in path or path.endswith('chart.umd.min.js'):
        return 'third-party/bundled'
    if path.startswith('tests/'):
        return 'test/tooling'
    if path.startswith(('docs/', 'Mobile/wbws_flutter_app/docs/')) or Path(path).suffix in {'.md', '.rst'}:
        return 'documentation'
    if path.startswith('Mobile/wbws_flutter_app/') and not any(x in path for x in ('/lib/', '/test/', '/tool/')):
        return 'mobile-platform/assets'
    if Path(path).suffix in SOURCE or Path(path).name == '.htaccess':
        return 'application-source' if not path.startswith('tools/') else 'test/tooling'
    return 'asset/configuration'


def write_csv(name, rows, fields):
    with (OUT / name).open('w', newline='', encoding='utf-8') as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator='\n')
        writer.writeheader()
        writer.writerows(rows)


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    files = sorted(set(subprocess.check_output(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT
    ).decode().strip('\0').split('\0')))
    notes_path = OUT / 'review-notes.json'
    notes = json.loads(notes_path.read_text()) if notes_path.exists() else {}
    inventory, functions, controls, routes, bindings = [], [], [], [], []
    for rel in files:
        if rel.startswith('docs/audits/production-2026-09-07/'):
            continue  # generated audit evidence, not input code
        path = ROOT / rel
        if not path.is_file():
            continue
        data = path.read_bytes()
        try:
            text = data.decode('utf-8-sig')
            lines = len(text.splitlines())
        except UnicodeDecodeError:
            text, lines = '', ''
        category = group(rel)
        inventory.append({
            'file': rel, 'category': category, 'bytes': len(data), 'lines': lines,
            'sha256': hashlib.sha256(data).hexdigest(),
            'coverage': notes.get(rel, {}).get('coverage', 'inventoried; automated checks where applicable'),
            'notes': notes.get(rel, {}).get('notes', 'No individual manual sign-off; see audit limitations.'),
        })
        if category != 'application-source':
            continue
        for match in re.finditer(r'\b(?:function\s+&?\s*|(?:async\s+)?function\s+)([A-Za-z_$][\w$]*)\s*\(', text):
            functions.append({'file': rel, 'line': text.count('\n', 0, match.start()) + 1, 'function': match[1]})
        for match in re.finditer(r'<(?:button|form|a|input|select|textarea)\b[^>]*>', text, re.I):
            controls.append({'file': rel, 'line': text.count('\n', 0, match.start()) + 1,
                             'markup': re.sub(r'\s+', ' ', match[0])[:550],
                             'coverage': 'source-indexed; NOT individually click-certified'})
        patterns = [
            r'(?:href|action)\s*=\s*[\'"]([^\'"<>]+\.php(?:\?[^\'"<>]*)?)[\'"]',
            r'fetch\s*\(\s*[\'"]([^\'"<>]+\.php(?:\?[^\'"<>]*)?)[\'"]',
            r'Location:\s*([^\s\'"<>]+\.php(?:\?[^\s\'"<>]*)?)',
        ]
        for pattern in patterns:
            for match in re.finditer(pattern, text):
                url = match[1]
                if any(c in url for c in ('$','{','}')) or url.startswith(('http:', 'https:', '//')):
                    continue
                dest = urlsplit(url).path
                choices = [ROOT / dest.lstrip('/')] if dest.startswith('/') else [path.parent / dest]
                if rel.startswith('admin/dashboards/'):
                    choices.append(ROOT / 'admin' / dest)
                exists = any(choice.is_file() for choice in choices)
                routes.append({'file': rel, 'line': text.count('\n', 0, match.start()) + 1,
                               'literal': url, 'target_exists': exists,
                               'note': 'literal exists' if exists else 'REVIEW: may be retired, generated, or relative to an including route'})
        if rel.endswith('.php'):
            for match in re.finditer(r"->bind_param\(\s*(['\"])([idsb]+)\1\s*,\s*([^;]+)\);", text):
                args = [a.strip() for a in match[3].split(',')]
                if '...' in match[3] or '(' in match[3]:
                    continue
                if len(args) != len(match[2]):
                    bindings.append({'file': rel, 'line': text.count('\n', 0, match.start()) + 1,
                                     'types': match[2], 'arguments': match[3]})
    write_csv('file-inventory.csv', inventory, ['file', 'category', 'bytes', 'lines', 'sha256', 'coverage', 'notes'])
    write_csv('function-index.csv', functions, ['file', 'line', 'function'])
    write_csv('control-index.csv', controls, ['file', 'line', 'markup', 'coverage'])
    write_csv('literal-routes.csv', routes, ['file', 'line', 'literal', 'target_exists', 'note'])
    (OUT / 'bind-arity.json').write_text(json.dumps(bindings, indent=2) + '\n')
    summary = {'files': len(inventory), 'function_declarations_indexed': len(functions),
               'html_controls_indexed': len(controls), 'literal_routes': len(routes),
               'unresolved_literal_routes': sum(not r['target_exists'] for r in routes),
               'literal_bind_arity_errors': len(bindings)}
    (OUT / 'inventory-summary.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
