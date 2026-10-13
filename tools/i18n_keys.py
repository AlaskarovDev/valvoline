#!/usr/bin/env python3
"""
Koddakı bütün __('…') açarlarını toplayır və lang/ru.json, lang/en.json-da çatışmayanları göstərir.

İstifadə: python tools/i18n_keys.py            → çatışmayanların siyahısı (çıxış kodu 1, əgər varsa)
          python tools/i18n_keys.py --dump     → bütün açarlar (JSON massiv)
"""
from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
KEY = re.compile(r"""__\(\s*'((?:[^'\\]|\\.)*)'""")


def keys() -> list[str]:
    found: set[str] = set()
    files = list((ROOT / 'app').rglob('*.php')) + list((ROOT / 'resources/views').rglob('*.blade.php'))
    for path in files:
        if 'DemoWorkbooks' in path.name:
            continue
        for match in KEY.finditer(path.read_text(encoding='utf-8')):
            found.add(match.group(1).replace("\\'", "'").replace('\\\\', '\\'))
    return sorted(found)


def main() -> int:
    sys.stdout.reconfigure(encoding='utf-8')
    all_keys = keys()
    if '--dump' in sys.argv:
        print(json.dumps(all_keys, ensure_ascii=False, indent=1))
        return 0
    missing_any = False
    for locale in ('ru', 'en'):
        path = ROOT / 'lang' / f'{locale}.json'
        data = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}
        missing = [k for k in all_keys if not data.get(k)]
        unused = [k for k in data if k not in all_keys]
        print(f'{locale}: {len(all_keys)} açar, {len(missing)} çatışmır, {len(unused)} istifadəsiz')
        for k in missing:
            print('   - ' + k)
        missing_any = missing_any or bool(missing)
    return 1 if missing_any else 0


if __name__ == '__main__':
    sys.exit(main())
