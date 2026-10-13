#!/usr/bin/env python3
"""
Bir dəfəlik köməkçi: Blade və PHP fayllarındakı Azərbaycanca UI mətnlərini __('…') ilə bağlayır.
Yalnız etibarlı nümunələr avtomatik dəyişdirilir; qalanları (ifadə içində qarışıq mətn) siyahı kimi
çap olunur və əl ilə düzəldilir.

İstifadə: python tools/i18n_wrap.py [--dry]
"""
from __future__ import annotations

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DRY = '--dry' in sys.argv
LETTER = re.compile(r'[A-Za-zƏəĞğİıÖöŞşÜüÇç]')
AZ = re.compile(r'[ƏəĞğİıÖöŞşÜüÇç]')


def q(text: str) -> str:
    return text.replace('\\', '\\\\').replace("'", "\\'")


# ---------- Blade ----------
TEXT_NODE = re.compile(r'(?<![-=?])>([^<>]*?)<(?=[/a-zA-Z!])')
ATTR = re.compile(r'(?<![:\w-])(label|sub|title|meta|placeholder|aria-label|alt)="([^"{}$]*?)"')


def wrap_text_node(m: re.Match) -> str:
    raw = m.group(1)
    text = raw.strip()
    if len(text) < 2 or not LETTER.search(text) or any(t in raw for t in ('{{', '{!!', '@', '}}', '$', '=>', '::', '(', ')', '&&', '||', '!=', '===')):
        return m.group(0)
    lead = raw[: len(raw) - len(raw.lstrip())]
    trail = raw[len(raw.rstrip()):]
    return '>' + lead + "{{ __('" + q(text) + "') }}" + trail + '<'


def blade(path: pathlib.Path) -> list[str]:
    src = path.read_text(encoding='utf-8')
    out_lines = []
    leftovers = []
    in_php = False
    for no, line in enumerate(src.split('\n'), 1):
        stripped = line.strip()
        if stripped.startswith('@php') and '@endphp' not in stripped:
            in_php = True
        if in_php or stripped.startswith(('{{--', '<script', '<style')) or stripped.startswith('@php'):
            out_lines.append(line)
            if '@endphp' in stripped:
                in_php = False
            continue
        new = TEXT_NODE.sub(wrap_text_node, line)

        def attr(m: re.Match) -> str:
            name, value = m.group(1), m.group(2)
            if not LETTER.search(value) or value.startswith(('http', '#', '/')):
                return m.group(0)
            tag_start = new.rfind('<', 0, m.start())
            is_component = tag_start != -1 and new[tag_start:tag_start + 3] == '<x-'
            if is_component:
                return f":{name}=\"__('{q(value)}')\""
            return f'{name}="{{{{ __(\'{q(value)}\') }}}}"'

        new = ATTR.sub(attr, new)
        out_lines.append(new)
        # qalıqlar: __( xaricində Azərbaycan hərfi olan sətirlər
        probe = re.sub(r"__\('(?:[^'\\]|\\.)*'(?:,[^)]*)?\)", '', new)
        probe = re.sub(r'\{\{--.*?--\}\}', '', probe)
        if AZ.search(probe):
            leftovers.append(f'{path.relative_to(ROOT)}:{no}: {stripped[:140]}')
    result = '\n'.join(out_lines)
    if result != src and not DRY:
        path.write_text(result, encoding='utf-8', newline='')
    return leftovers


# ---------- PHP ----------
PHP_PATTERNS = [
    # ->column('key', 'Etiket'
    (re.compile(r"(->column\('[^']+',\s*)'((?:[^'\\]|\\.)+)'"), r"\1__('\2')"),
    # Table::make('ad', 'Başlıq')
    (re.compile(r"(Table::make\('[^']+',\s*)'((?:[^'\\]|\\.)+)'"), r"\1__('\2')"),
    # ->subtitle('…')
    (re.compile(r"(->subtitle\()'((?:[^'\\]|\\.)+)'"), r"\1__('\2')"),
    # 'title' / 'label' / 'name' / 'totalLabel' / 'xTitle' / 'yTitle' => '…'
    (re.compile(r"('(?:title|label|name|totalLabel|xTitle|yTitle|step)'\s*=>\s*)'((?:[^'\\]|\\.)*[A-Za-zƏəĞğİıÖöŞşÜüÇç](?:[^'\\]|\\.)*)'"), r"\1__('\2')"),
    # ->with('status' | 'error', '…')
    (re.compile(r"(->with\('(?:status|error)',\s*)'((?:[^'\\]|\\.)+)'"), r"\1__('\2')"),
]


def php(path: pathlib.Path) -> list[str]:
    src = path.read_text(encoding='utf-8')
    new = src
    for pattern, repl in PHP_PATTERNS:
        new = pattern.sub(repl, new)
    leftovers = []
    in_doc = False
    for no, line in enumerate(new.split('\n'), 1):
        s = line.strip()
        if s.startswith('/*'):
            in_doc = True
        if in_doc or s.startswith(('//', '*', '#')):
            if '*/' in s:
                in_doc = False
            continue
        probe = re.sub(r"__\('(?:[^'\\]|\\.)*'(?:,[^)]*)?\)", '', line)
        probe = re.sub(r'//.*$', '', probe)
        if AZ.search(probe):
            leftovers.append(f'{path.relative_to(ROOT)}:{no}: {s[:140]}')
    if new != src and not DRY:
        path.write_text(new, encoding='utf-8', newline='')
    return leftovers


def main() -> None:
    left = []
    for p in sorted((ROOT / 'resources/views').rglob('*.blade.php')):
        left += blade(p)
    for d in ('app/Http', 'app/Reports', 'app/Livewire', 'app/Services', 'app/Support', 'app/Imports', 'app/Models'):
        for p in sorted((ROOT / d).rglob('*.php')):
            left += php(p)
    sys.stdout.reconfigure(encoding='utf-8')
    print(f'{len(left)} sətir əl ilə baxılmalıdır:')
    print('\n'.join(left))


if __name__ == '__main__':
    main()
