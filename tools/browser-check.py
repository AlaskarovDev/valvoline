#!/usr/bin/env python3
"""
Valvo Analitika — real headless-Chrome yoxlaması (lokal və ya canlı).

Hər səhifə üçün: konsol xətaları, üfüqi daşma (masaüstü və telefon eni), ApexCharts qrafiklərinin
həqiqətən çəkilməsi, qaranlıq rejim. Skrinşotlar --shots qovluğuna yazılır.

İstifadə:  python tools/browser-check.py <base-url> <email> <parol> [--shots DIR]
Tələb:     pip install websocket-client ; Google Chrome
"""
from __future__ import annotations

import base64
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

args = [a for i, a in enumerate(sys.argv[1:], 1) if not a.startswith("--") and sys.argv[i - 1] not in ("--shots", "--locale")]
BASE = (args[0] if args else "http://127.0.0.1:8099").rstrip("/")
EMAIL = args[1] if len(args) > 1 else ""
PASSWORD = args[2] if len(args) > 2 else ""
SHOTS = sys.argv[sys.argv.index("--shots") + 1] if "--shots" in sys.argv else ""
LOCALE = sys.argv[sys.argv.index("--locale") + 1] if "--locale" in sys.argv else ""

# (ad, yol, gözlənilən minimum qrafik sayı)
PAGES = [
    ("idare-paneli", "/?preset=last_12", 5),
    ("sifarisler", "/sifarisler?preset=last_12", 0),
    ("satis", "/satis?preset=last_12", 1),
    ("marjinalliq", "/marjinalliq?preset=last_12", 1),
    ("qaytarmalar", "/qaytarmalar?preset=last_12", 2),
    ("musteriler", "/musteriler", 0),
    ("borclar", "/borclar", 1),
    ("odenisler", "/odenisler?preset=last_12", 1),
    ("anbar", "/anbar", 0),
    ("anbar-hereketleri", "/anbar-hereketleri?preset=last_month", 0),
    ("idxal", "/idxal", 1),
    ("xercler", "/xercler?preset=last_12", 1),
    ("pnl", "/menfeet-zerer?preset=last_12", 1),
    ("kassa", "/kassa?preset=all", 1),
    ("keyfiyyet", "/melumat-keyfiyyeti", 0),
    ("import", "/import", 0),
    ("uygunlasdirma", "/uygunlasdirma", 0),
    ("kateqoriyalar", "/xerc-kateqoriyalari", 0),
    ("istifadeciler", "/istifadeciler", 0),
    ("hesablar-plani", "/muhasibat/hesablar-plani", 0),
    ("balans", "/muhasibat/balans", 0),
    ("mz", "/muhasibat/menfeet-zerer", 0),
    ("cash-flow", "/muhasibat/cash-flow", 1),
]

PASS: list[str] = []
FAIL: list[str] = []


def ok(m: str) -> None:
    PASS.append(m)
    print(f"  ok   {m}")


def bad(m: str) -> None:
    FAIL.append(m)
    print(f"  FAIL {m}")


def main() -> int:
    chrome = os.environ.get("CHROME_PATH") or r"C:\Program Files\Google\Chrome\Application\chrome.exe"
    if not os.path.exists(chrome):
        chrome = shutil.which("google-chrome") or shutil.which("chromium") or ""
    if not chrome:
        sys.exit("Chrome tapılmadı")
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)

    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    profile = tempfile.mkdtemp(prefix="valvo-check-")
    proc = subprocess.Popen(
        [chrome, "--headless=new", f"--remote-debugging-port={port}", f"--user-data-dir={profile}",
         "--no-first-run", "--disable-gpu", "--hide-scrollbars", "--remote-allow-origins=*", "about:blank"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    ws_url = ""
    for _ in range(80):
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/json/version", timeout=1) as r:
                ws_url = json.load(r)["webSocketDebuggerUrl"]
            break
        except Exception:
            time.sleep(0.25)
    if not ws_url:
        proc.kill()
        sys.exit("DevTools açılmadı")

    ws = websocket.create_connection(ws_url, timeout=90)
    state = {"id": 0, "events": []}

    def send(method, params=None):
        state["id"] += 1
        ws.send(json.dumps({"id": state["id"], "method": method, "params": params or {}}))
        while True:
            message = json.loads(ws.recv())
            if message.get("id") == state["id"]:
                return message.get("result", {})
            if "method" in message:
                state["events"].append(message)

    target = send("Target.createTarget", {"url": "about:blank"})["targetId"]
    ws.close()
    ws = websocket.create_connection(f"ws://127.0.0.1:{port}/devtools/page/{target}", timeout=90)
    state["id"] = 0

    def js(expression):
        result = send("Runtime.evaluate", {"expression": expression, "returnByValue": True})
        if result.get("exceptionDetails"):
            raise RuntimeError(result["exceptionDetails"].get("text", "JS error"))
        return result.get("result", {}).get("value")

    def pump(rounds=4):
        for _ in range(rounds):
            send("Runtime.evaluate", {"expression": "1", "returnByValue": True})
            time.sleep(0.2)

    def errors():
        out = []
        for e in state["events"]:
            if e.get("method") == "Log.entryAdded":
                entry = e["params"].get("entry", {})
                if entry.get("level") == "error":
                    out.append(entry.get("text", "")[:200])
            elif e.get("method") == "Runtime.exceptionThrown":
                d = e["params"].get("exceptionDetails", {})
                out.append((d.get("exception", {}).get("description") or d.get("text", "exception"))[:200])
            elif e.get("method") == "Runtime.consoleAPICalled" and e["params"].get("type") == "error":
                out.append(" ".join(str(a.get("value", a.get("description", ""))) for a in e["params"].get("args", []))[:200])
        return out

    def wait_for(expr, seconds=8.0):
        end = time.time() + seconds
        while time.time() < end:
            try:
                if js(expr):
                    return True
            except RuntimeError:
                pass
            time.sleep(0.25)
        return False

    def shot(name, full=False):
        if not SHOTS:
            return
        params = {"format": "png", "captureBeyondViewport": full}
        if full:
            h = js("Math.min(document.documentElement.scrollHeight, 4200)")
            w = js("document.documentElement.clientWidth")
            params["clip"] = {"x": 0, "y": 0, "width": w, "height": h, "scale": 1}
        data = send("Page.captureScreenshot", params)["data"]
        with open(os.path.join(SHOTS, name + ".png"), "wb") as f:
            f.write(base64.b64decode(data))

    def viewport(w, h, mobile=False):
        send("Emulation.setDeviceMetricsOverride", {"width": w, "height": h, "deviceScaleFactor": 1, "mobile": mobile})

    def visit(path):
        state["events"].clear()
        send("Page.navigate", {"url": BASE + path})
        wait_for("document.readyState === 'complete'", 15)
        time.sleep(0.6)
        pump()

    try:
        send("Page.enable")
        send("Runtime.enable")
        send("Log.enable")
        viewport(1440, 960)
        send("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-color-scheme", "value": "light"}]})
        print(f"\nValvo Analitika brauzer yoxlaması: {BASE}\n")

        visit("/login")
        font = js("getComputedStyle(document.body).fontFamily")
        ok(f"giriş səhifəsi stilləndi ({font.split(',')[0]})") if font and "Inter" in font else bad(f"giriş şrifti: {font}")
        errs = errors()
        ok("giriş səhifəsində konsol təmizdir") if not errs else bad(f"giriş konsol: {errs[0]}")
        shot("00-login")

        js(f"""(() => {{ const f = document.querySelector('input[name=email]').form; f.email.value = {json.dumps(EMAIL)};
                 f.password.value = {json.dumps(PASSWORD)}; f.submit(); }})()""")
        if not wait_for("location.pathname === '/' && !!document.querySelector('main')", 20):
            bad("giriş alınmadı")
            return 1
        ok("giriş edildi")
        ok("Alpine (Livewire) işə düşdü") if wait_for("!!window.Alpine && !!window.Livewire") else bad("Alpine/Livewire yoxdur")
        if LOCALE:
            visit("/dil/" + LOCALE)
            lang = js("document.documentElement.lang")
            ok(f"dil: {lang}") if lang == LOCALE else bad(f"dil dəyişmədi: {lang}")

        for name, path, charts in PAGES:
            visit(path)
            if charts:
                wait_for(f"document.querySelectorAll('.apexcharts-canvas').length >= {charts}", 10)
            time.sleep(0.4)
            errs = [e for e in errors() if "favicon" not in e]
            overflow = js("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            drawn = js("document.querySelectorAll('.apexcharts-canvas').length")
            status = js("document.title")
            problems = []
            if errs:
                problems.append(f"konsol: {errs[0]}")
            if isinstance(overflow, (int, float)) and overflow > 2:
                problems.append(f"{int(overflow)}px üfüqi daşma")
            if drawn < charts:
                problems.append(f"qrafik {drawn}/{charts}")
            if "Error" in (status or "") or "Server Error" in (js("document.body.innerText.slice(0, 200)") or ""):
                problems.append("server xətası")
            bad(f"{name}: " + "; ".join(problems)) if problems else ok(f"{name}: təmiz ({drawn} qrafik)")
            shot("10-" + name, full=True)

        # detal səhifələri: siyahıdakı ilk keçidi aç
        for name, path, pattern in [("sifariş", "/sifarisler?preset=last_12", r"/sifarisler/\d+$"),
                                    ("müştəri kartı", "/musteriler", r"/musteriler/\d+$"),
                                    ("mal kartı", "/anbar", r"/anbar-hereketleri/mal/\d+$"),
                                    ("partiya", "/idxal", r"/idxal/\d+$")]:
            visit(path)
            href = js(f"[...document.querySelectorAll('main a')].map(a => a.href).find(h => new RegExp({json.dumps(pattern)}).test(new URL(h).pathname)) || ''")
            if not href:
                bad(f"{name}: siyahıda keçid tapılmadı")
                continue
            state["events"].clear()
            send("Page.navigate", {"url": href})
            wait_for("document.readyState === 'complete'", 15)
            time.sleep(1.2)
            pump()
            errs = errors()
            overflow = js("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            if errs:
                bad(f"{name}: konsol {errs[0]}")
            elif overflow > 2:
                bad(f"{name}: {overflow}px daşma")
            else:
                ok(f"{name} səhifəsi açılır")
            shot("20-" + name.replace(" ", "-"), full=True)


        # xərc xanasına klik → popup əməliyyatları göstərir və cəm xananın rəqəmi ilə eynidir
        def popup_check(label):
            visit("/xercler?preset=last_12")
            cell = js("(() => { const a = [...document.querySelectorAll('a[data-modal]')].find(x => x.offsetParent !== null); if (!a) return null; a.click(); return a.textContent.trim(); })()")
            if not cell:
                bad(f"{label}: klik ediləcək xana tapılmadı")
                return
            loaded = wait_for("document.getElementById('detail-modal').open && !!document.querySelector('[data-modal-body] [data-modal-title]')", 10)
            popup_total = js("(document.querySelector('[data-modal-body] .text-lg') || {}).textContent || ''").strip()
            if loaded and popup_total.replace(' ₼', '').replace(' ₼', '').strip() == cell.replace(' ₼', '').replace(' ₼', '').strip():
                ok(f"{label}: popup açıldı, cəm xana ilə eynidir ({cell})")
            else:
                bad(f"{label}: popup — xana {cell!r}, popup {popup_total!r}, yükləndi={loaded}")
            time.sleep(0.4)
            shot(f"50-popup-{label}")
            js("document.querySelector('[data-modal-close]').click()")
            ok(f"{label}: popup bağlanır") if wait_for("!document.getElementById('detail-modal').open", 3) else bad(f"{label}: popup bağlanmadı")

        popup_check("masaustu")

        # qaranlıq rejim
        visit("/?preset=last_12")
        was_dark = js("document.documentElement.classList.contains('dark')")
        before = js("getComputedStyle(document.body).backgroundColor")
        js("toggleTheme()")
        time.sleep(1.2)
        after = js("getComputedStyle(document.body).backgroundColor")
        flipped = js("document.documentElement.classList.contains('dark')") != was_dark and before != after
        ok(f"tema dəyişir ({before} → {after})") if flipped else bad(f"tema dəyişmədi: {before} → {after}")
        if not js("document.documentElement.classList.contains('dark')"):
            js("toggleTheme()")
            time.sleep(1.0)
        shot("30-dashboard-dark", full=True)
        visit("/menfeet-zerer?preset=last_12")
        time.sleep(0.8)
        shot("31-pnl-dark", full=True)
        js("try { localStorage.setItem('theme', 'light') } catch (e) {}")

        # yoxlayıcının özünü yoxla
        state["events"].clear()
        js("console.error('valvo-selftest-error')")
        pump()
        ok("yoxlayıcı konsol xətasını tutur") if any("valvo-selftest" in e for e in errors()) else bad("yoxlayıcı konsol xətasını görmədi — etibarsızdır")

        # telefon eni
        viewport(390, 844, True)
        for name, path, _charts in PAGES:
            visit(path)
            time.sleep(1.0)
            overflow = js("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            ok(f"mobil {name}: daşma yoxdur") if isinstance(overflow, (int, float)) and overflow <= 2 else bad(f"mobil {name}: {overflow}px daşma")
            shot("40-mobil-" + name, full=True)
        popup_check("telefon")
        js("document.querySelector('[data-nav-open]').click()")
        time.sleep(0.6)
        nav_open = js("document.querySelector('aside').getBoundingClientRect().left >= 0")
        ok("mobil menyu açılır") if nav_open else bad("mobil menyu açılmadı")
        shot("41-mobil-menyu")
    finally:
        try:
            proc.kill()
        except Exception:
            pass
        shutil.rmtree(profile, ignore_errors=True)

    print(f"\n{len(PASS)} uğurlu, {len(FAIL)} problem")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
