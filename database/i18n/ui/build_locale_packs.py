#!/usr/bin/env python3
from __future__ import annotations
import json, re, time, urllib.parse, urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor, as_completed, TimeoutError as FuturesTimeout
from pathlib import Path

ROOT = Path(__file__).resolve().parent
LOCALES = ["fr", "es", "it", "ar", "hi"]
WORKERS = 12
REQ_TIMEOUT = 12
MAX_RETRIES = 2
SKIP_RE = re.compile(
    r"^(?:https?://|\{\{?[\w.]+\}\}?|\$\{[\w.]+\}|[A-Z0-9_+./#%-]{1,12}|WentoCart|WENTO|SKU|CSV|PDF|POS|API|URL|ID)$"
)

def should_keep_english(text: str) -> bool:
    t = text.strip()
    if t == "":
        return True
    if SKIP_RE.match(t):
        return True
    if re.fullmatch(r"[\d\s.,:%$€£₹+\-_/]+", t):
        return True
    return False

def gtx(text: str, tl: str) -> str:
    q = urllib.parse.quote(text[:4500])
    url = f"https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl={tl}&dt=t&q={q}"
    last = None
    for attempt in range(MAX_RETRIES):
        try:
            with urllib.request.urlopen(url, timeout=REQ_TIMEOUT) as response:
                data = json.loads(response.read().decode("utf-8"))
            parts = [row[0] for row in (data[0] or []) if row and row[0]]
            out = "".join(parts).strip()
            return out or text
        except Exception as exc:
            last = exc
            time.sleep(0.25 * (attempt + 1))
    # Fallback: keep English rather than hang the whole pack.
    print(f"WARN keep-en locale={tl} err={last!r} text={text[:60]!r}")
    return text

def load_json(path: Path, default):
    if path.exists():
        return json.loads(path.read_text(encoding="utf-8"))
    return default

def main() -> None:
    en_source = load_json(ROOT / "en-source.json", {})
    unique_sources = sorted({row["source"] for row in en_source.values() if row.get("source")})
    cache_path = ROOT / "source-translations-cache.json"
    cache = load_json(cache_path, {})
    pending = []
    for source in unique_sources:
        cache.setdefault(source, {})
        if should_keep_english(source):
            for locale in LOCALES:
                cache[source][locale] = source
            continue
        for locale in LOCALES:
            if not str(cache[source].get(locale, "")).strip():
                pending.append((source, locale))
    print(f"sources={len(unique_sources)} pending={len(pending)}", flush=True)

    def work(item):
        source, locale = item
        return source, locale, gtx(source, locale)

    done = 0
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        futures = {pool.submit(work, item): item for item in pending}
        for future in as_completed(futures):
            try:
                source, locale, translated = future.result(timeout=REQ_TIMEOUT + 5)
            except Exception as exc:
                source, locale = futures[future]
                print(f"WARN future locale={locale} err={exc!r}", flush=True)
                translated = source
            cache[source][locale] = translated
            done += 1
            if done % 200 == 0 or done == len(pending):
                cache_path.write_text(json.dumps(cache, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
                print(f"progress {done}/{len(pending)}", flush=True)

    cache_path.write_text(json.dumps(cache, ensure_ascii=False, indent=1), encoding="utf-8")
    overrides = load_json(ROOT / "chrome-overrides.json", {})
    for locale in LOCALES:
        pack = {}
        for key, row in en_source.items():
            source = row.get("source") or ""
            text = (
                overrides.get(key, {}).get(locale)
                or overrides.get(source, {}).get(locale)
                or cache.get(source, {}).get(locale)
                or source
            )
            pack[key] = text
        out = ROOT / f"{locale}.json"
        out.write_text(json.dumps(pack, ensure_ascii=False, indent=1, sort_keys=True) + "\n", encoding="utf-8")
        print(f"wrote {out.name} keys={len(pack)}", flush=True)
    print("done", flush=True)

if __name__ == "__main__":
    main()
