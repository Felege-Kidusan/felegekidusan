#!/usr/bin/env python3
"""const-gate: catch `const X(...)` where X's constructor is not const.

Both mobile release-build breakages of the P74 era (const ListView,
const Semantics) were const usages that appeared NOWHERE in the
pre-existing compiled corpus. This gate flags exactly that class:
any const constructor in the changed files that neither (a) already
appears as const in the compiled corpus at HEAD, (b) is declared
`const X(` somewhere in this repository, nor (c) is on a small
framework-guaranteed whitelist.

Run from the app root:  python3 tool/const_gate.py [files...]
(no args = the three comm screens; exit 1 = suspect found)
"""
import re, subprocess, sys, collections, os

KNOWN_CONST = {
    'SizedBox', 'Row', 'Column', 'Text', 'Icon', 'Padding', 'Center',
    'Expanded', 'Flexible', 'BoxDecoration', 'BoxConstraints',
    'EdgeInsets', 'TextStyle', 'IconTheme', 'DecoratedBox',
    'CircleAvatar', 'Scaffold', 'AppBar', 'InkWell', 'Material',
    'BorderRadius', 'Colors', 'Key', 'ValueKey',
}

DEFAULT_FILES = [
    'lib/screens/notifications/messages_screen.dart',
    'lib/screens/notifications/notification_center_screen.dart',
    'lib/widgets/notification_bell_button.dart',
]

def main() -> int:
    changed = sys.argv[1:] or DEFAULT_FILES
    changed = [os.path.relpath(f) for f in changed]

    files = subprocess.run(
        ['git', 'ls-tree', '-r', '--name-only', 'HEAD', '--', 'lib/', 'test/'],
        capture_output=True, text=True).stdout.split()
    others = [f for f in files if f not in changed]
    corpus = ''
    for f in others:
        corpus += subprocess.run(
            ['git', 'show', f'HEAD:./{f}'],
            capture_output=True, text=True).stdout
    pre_names = set(re.findall(r'const ([A-Z][A-Za-z]*)\(', corpus))

    # constructors DECLARED const in the repo. Declaration sites are
    # `const X({` (parameter list opens immediately) — usage sites
    # `const X(<arg>` are deliberately NOT matched, otherwise a bad
    # usage in a changed file would whitelist itself.
    DECL = r'const ([A-Z][A-Za-z]*)\(\{'
    declared = set()
    for f in files:
        blob = subprocess.run(['git', 'show', f'HEAD:./{f}'],
                              capture_output=True, text=True).stdout
        declared |= set(re.findall(DECL, blob))
    for root, _, names in os.walk('lib'):
        for n in names:
            if n.endswith('.dart'):
                try:
                    declared |= set(re.findall(
                        DECL, open(os.path.join(root, n), encoding='utf-8').read()))
                except OSError:
                    pass

    problems = []
    for f in changed:
        cur = open(f, encoding='utf-8').read()
        for name in collections.Counter(re.findall(r'const ([A-Z][A-Za-z]*)\(', cur)):
            if name not in pre_names and name not in declared and name not in KNOWN_CONST:
                problems.append((f, name))
    if problems:
        print('SUSPECT const constructors (verify before building):')
        for f, n in problems:
            print(f'  {f}: const {n}(')
        return 1
    print('const-gate: PASS')
    return 0

if __name__ == '__main__':
    sys.exit(main())
