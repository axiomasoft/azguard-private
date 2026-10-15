#!/usr/bin/env python3
"""Validate the audit bundle; never run application probes or touch a database."""
import ast
import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import unquote, urlsplit
import xml.etree.ElementTree as ET


def main():
    directory = Path(__file__).resolve().parent
    expected_head = "2ce692f74c210e81426e9367303701516b1cc3f0"
    errors, counts = [], {}
    links = 0
    for path in directory.rglob("*.md"):
        content = re.sub(r"^```[^\n]*\n.*?^```[ \t]*$", "", path.read_text(), flags=re.M | re.S)
        for match in re.finditer(r"!?\[[^\]\n]*\]\(([^)\n]+)\)", content):
            target = match.group(1).strip().strip("<>")
            parsed = urlsplit(target)
            if parsed.scheme or target.startswith("#"):
                continue
            resolved = path.parent / unquote(parsed.path)
            links += 1
            if not resolved.exists():
                errors.append(f"Broken link in {path.relative_to(directory)}: {target}")
    counts["local_links"] = links
    for extension in ["json", "py", "svg", "xml"]:
        paths = list(directory.rglob(f"*.{extension}"))
        counts[extension] = len(paths)
        for path in paths:
            try:
                if extension == "json":
                    data = json.loads(path.read_text())
                    if isinstance(data, dict) and "command" in data and data.get("head") != expected_head:
                        errors.append(f"Unexpected evidence HEAD: {path.name}")
                elif extension == "py":
                    ast.parse(path.read_text(), filename=str(path))
                else:
                    root = ET.parse(path).getroot()
                    if extension == "svg":
                        if root.tag != "{http://www.w3.org/2000/svg}svg":
                            errors.append(f"Invalid SVG root: {path.name}")
                        for element in root.iter():
                            if element.tag.rsplit("}", 1)[-1] in {"script", "image", "foreignObject"}:
                                errors.append(f"Unexpected active/external SVG content: {path.name}")
            except (ValueError, SyntaxError, ET.ParseError) as error:
                errors.append(f"Invalid {path.relative_to(directory)}: {error}")
    php_paths = list(directory.rglob("*.php"))
    counts["php"] = len(php_paths)
    for path in php_paths:
        result = subprocess.run(["php", "-l", str(path)], text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if path == directory / "evidence/filament/generated-widget-permission.php":
            if result.returncode != 255 or "name is already in use" not in result.stdout:
                errors.append("R18 evidence no longer demonstrates the recorded duplicate import")
        elif result.returncode:
            errors.append(f"Invalid PHP probe {path.relative_to(directory)}: {result.stdout.strip()}")
    render = subprocess.run([sys.executable, str(directory / "diagrams/render.py"), "--check"], text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if render.returncode:
        errors.append(render.stdout.strip())
    if errors:
        print("\n".join(errors))
        return 1
    print(json.dumps({"result": "passed", "checks": counts, "diagrams": render.stdout.strip(),
                      "note": "Artifact validation only; R18 invalid generated PHP is verified as expected evidence."}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
