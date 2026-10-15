#!/usr/bin/env python3
"""Render audit graphs from one source to Mermaid, DOT and standalone SVG."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET


def quote(value):
    return json.dumps(value, ensure_ascii=False)


def outputs(name, graph):
    nodes = graph["nodes"]
    direction = graph["direction"]
    if direction not in {"LR", "TB"} or not nodes:
        raise ValueError(f"Invalid graph: {name}")
    for node, label in nodes.items():
        if not re.fullmatch(r"[a-z][a-z0-9_]*", node):
            raise ValueError(f"Invalid node id: {node}")
        if any(c in label for c in ['"', "\n", "|", "<", ">"]):
            raise ValueError(f"Unsupported Mermaid label: {label}")
    dot = [
        f"digraph {name.replace('-', '_')} {{",
        f"  rankdir={direction};",
        '  graph [bgcolor="white", pad="0.25", nodesep="0.3", ranksep="0.45"];',
        '  node [shape=box, style="rounded,filled", fillcolor="#eef4fb", color="#5f7898", fontname="DejaVu Sans", fontsize=11, margin="0.15,0.10"];',
        '  edge [color="#64748b", fontname="DejaVu Sans", fontsize=9];',
    ]
    mermaid = [f"flowchart {direction}"]
    for node, label in nodes.items():
        # Graphviz wraps only at explicit newlines; keep wide labels readable.
        words, lines, current = label.split(), [], ""
        for word in words:
            if current and len(current) + len(word) + 1 > 38:
                lines.append(current)
                current = word
            else:
                current = f"{current} {word}".strip()
        lines.append(current)
        dot.append(f"  {node} [label={quote(chr(10).join(lines))}];")
        mermaid.append(f"  {node}[{quote(label)}]")
    for origin, target, label in graph["edges"]:
        if origin not in nodes or target not in nodes:
            raise ValueError(f"Unknown endpoint in {name}: {origin}->{target}")
        if any(c in label for c in ['"', "\n", "|"]):
            raise ValueError(f"Unsupported edge label: {label}")
        dot.append(f"  {origin} -> {target} [label={quote(label)}];")
        mermaid.append(f"  {origin} -->|{quote(label)}| {target}" if label else f"  {origin} --> {target}")
    dot.append("}")
    dot_text = "\n".join(dot) + "\n"
    svg = subprocess.check_output(["dot", "-Tsvg"], input=dot_text.encode())
    tree = ET.fromstring(svg)
    if tree.tag != "{http://www.w3.org/2000/svg}svg":
        raise ValueError(f"Invalid SVG: {name}")
    return {"dot": dot_text.encode(), "mmd": ("\n".join(mermaid) + "\n").encode(), "svg": svg}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    directory = Path(__file__).resolve().parent
    graphs = json.loads((directory / "graphs.json").read_text())
    mismatches = []
    for name, graph in graphs.items():
        if not re.fullmatch(r"[a-z][a-z0-9-]*", name):
            raise ValueError(f"Invalid graph name: {name}")
        for extension, content in outputs(name, graph).items():
            path = directory / f"{name}.{extension}"
            if args.check:
                if not path.exists() or path.read_bytes() != content:
                    mismatches.append(path.name)
            else:
                path.write_bytes(content)
    if mismatches:
        raise SystemExit("Stale diagram artifacts: " + ", ".join(mismatches))
    print(f"{'Checked' if args.check else 'Rendered'} {len(graphs)} graphs: Mermaid, DOT, SVG")


if __name__ == "__main__":
    main()
