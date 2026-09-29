#!/usr/bin/env python3
"""Read-only source inventory for this audit; --check compares the committed evidence."""
import argparse
from collections import Counter
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[3]
OUT = Path(__file__).resolve().parent / "repository-inventory.json"


def inventory():
    types = {}
    files = []
    for package in ("core", "context", "filament"):
        for path in sorted((ROOT / "packages" / package / "src").rglob("*.php")):
            source = path.read_text()
            declaration = re.search(r"^(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)", source, re.M)
            namespace = re.search(r"^namespace\s+([^;]+);", source, re.M)
            if not declaration or not namespace:
                raise ValueError(f"Unsupported declaration in {path}")
            prefix = source[:declaration.start()]
            doc = re.search(r"(/\*\*[\s\S]*?\*/)(?:\s|#\[[^\]]*\])*$", prefix)
            tags = re.findall(r"@(api|internal|spi|experimental|deprecated)\b", doc.group(1) if doc else "")
            status = "+".join(sorted(set(tags))) if tags else "unclassified"
            name = namespace.group(1) + "\\" + declaration.group(2)
            record = {
                "package": package,
                "file": str(path.relative_to(ROOT)),
                "type": name,
                "kind": declaration.group(1),
                "line": source[:declaration.start()].count("\n") + 1,
                "status": status,
                "imports": [{"type": m.group(1), "line": source[:m.start()].count("\n") + 1}
                            for m in re.finditer(r"^use\s+(AzGuard\\[^;\s]+)(?:\s+as\s+\w+)?;", source, re.M)],
            }
            types[name] = record
            files.append(record)
    counts = {package: dict(sorted(Counter(f["status"] for f in files if f["package"] == package).items()))
              for package in ("core", "context", "filament")}
    by_status = {
        package: {
            status: sorted(f["type"] for f in files if f["package"] == package and f["status"] == status)
            for status in counts[package]
        }
        for package in counts
    }
    edges = []
    for record in files:
        for imported in record["imports"]:
            target = types.get(imported["type"])
            if target and target["package"] != record["package"]:
                edges.append({"from": record["file"], "line": imported["line"], "to": target["file"],
                              "type": target["type"], "target_status": target["status"]})
    return {
        "baseline_commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
        "scope": "Top-level named type declarations and direct use imports in packages/*/src; source scan, not full PHP AST or behavioral proof.",
        "limitations": "Does not enumerate fully-qualified inline references, phpdoc dependencies, inherited methods or dynamic/container references; PHP group imports require manual review.",
        "counts": counts, "types_by_status": by_status, "cross_package_imports": edges,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    result = inventory()
    if args.check:
        recorded = json.loads(OUT.read_text())
        # HEAD changes when the audit itself is committed; source scan remains authoritative.
        result["baseline_commit"] = recorded["baseline_commit"]
        if result != recorded:
            raise SystemExit("Inventory differs from source; regenerate and review evidence.")
    else:
        OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"counts": result["counts"], "cross_package_imports": len(result["cross_package_imports"]),
                      "check": args.check}, ensure_ascii=False))
