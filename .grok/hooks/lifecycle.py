#!/usr/bin/env python3
import argparse, datetime, importlib.util, json, os, re, shlex, shutil, subprocess, sys
from pathlib import Path

_STATE_DIR = '.grok'
_PROVIDER_ID = 'grok'
_SURFACE_EVENTS = {
    "context.observer": "SessionStart",
    "lifecycle.command-log": "PreToolUse",
    "lifecycle.tool-telemetry": "PreToolUse",
    "lifecycle.run-report": "SessionEnd",
    "lifecycle.router": "UserPromptSubmit",
    "lifecycle.memory": "SessionStart",
    "lifecycle.pack-guard": "PreToolUse",
    "lifecycle.changelog-gate": "PreToolUse",
    "lifecycle.review-gate": "SubagentStop",
    "security.safe-dirs": "PreToolUse",
    "lifecycle.daemon-safety": "PreToolUse",
    "lifecycle.git-guardrails": "PreToolUse",
    "lifecycle.terminal-admission": "Stop",
}
_EVENT_FIELDS = {
    "SessionStart": ("source",),
    "PreToolUse": ("tool_name", "tool_input"),
    "SessionEnd": ("reason",),
    "UserPromptSubmit": ("prompt",),
    "SubagentStop": ("agent_type",),
    "Stop": ("stop_hook_active", "last_assistant_message"),
}
_GATE_MODES = ("off", "warn", "strict")


def _adapter_log(message):
    try:
        sys.stderr.write("[harness lifecycle] " + str(message) + "\n")
    except Exception:
        pass


def _canonical_event(value):
    if not isinstance(value, str):
        return ""
    compact = value.replace("_", "").replace("-", "").lower()
    return {
        "pretooluse": "PreToolUse",
        "sessionstart": "SessionStart",
        "sessionend": "SessionEnd",
        "userpromptsubmit": "UserPromptSubmit",
        "subagentstop": "SubagentStop",
        "stop": "Stop",
    }.get(compact, value)


def _normalize_payload(raw, expected_event):
    """Accept Claude snake_case and Grok camelCase hook envelopes.

    Grok supplies ``hookEventName``, ``toolName``, ``toolInput`` and ``sessionId``;
    Claude-shaped providers use their snake_case counterparts.  Missing optional
    fields are normalized to benign defaults.  An incompatible envelope returns
    ``None`` so the caller can log and fail open rather than exit 2.
    """
    if not isinstance(raw, dict):
        return None
    payload = dict(raw)
    event = _canonical_event(raw.get("hook_event_name") or raw.get("hookEventName"))
    if event != expected_event:
        return None
    payload["hook_event_name"] = event
    payload["tool_name"] = raw.get("tool_name") or raw.get("toolName")
    tool_input = raw.get("tool_input")
    if tool_input is None:
        tool_input = raw.get("toolInput")
    if expected_event == "PreToolUse" and not isinstance(tool_input, dict):
        return None
    payload["tool_input"] = tool_input if isinstance(tool_input, dict) else {}
    payload["session_id"] = (raw.get("session_id") or raw.get("sessionId")
                             or os.environ.get("GROK_SESSION_ID", ""))
    payload["cwd"] = (raw.get("cwd") or raw.get("workspaceRoot")
                      or os.environ.get("GROK_WORKSPACE_ROOT", "") or os.getcwd())
    payload["permission_mode"] = raw.get("permission_mode") or raw.get("permissionMode") or ""
    payload["model"] = raw.get("model") or ""
    return payload


_MAIND_HEALTH_COMMAND_RE = re.compile(
    r"^\s*maind\s+health\s+--cwd(?:\s+--fix)?\s*(?:;\s*echo\s+EXIT:\$\?\s*)?$")
_GROK_UNCONDITIONAL_ALLOW = frozenset(("web_search", "open_page"))
_GROK_KNOWN_TOOL_NAMES = frozenset((
    "run_terminal_command", "read_file", "grep", "list_dir", "search_replace",
    "hashline_edit", "web_search", "open_page", "web_fetch", "search_tool", "use_tool",
    "spawn_subagent", "todo_write", "monitor", "workflow", "enter_plan_mode",
    "ask_user_question",
))


def _is_maind_health_command(command):
    return isinstance(command, str) and bool(_MAIND_HEALTH_COMMAND_RE.fullmatch(command))


def _read_path(tool_input):
    if not isinstance(tool_input, dict):
        return None
    for name in ("path", "file_path", "filePath", "filename"):
        value = tool_input.get(name)
        if isinstance(value, str) and value:
            return value
    return None


def _bootstrap_read_allowed(cwd, tool_input):
    value = _read_path(tool_input)
    if not isinstance(cwd, str) or not cwd or not value:
        return False
    try:
        path = Path(value).expanduser()
        if not path.is_absolute():
            path = Path(cwd) / path
        path = path.resolve()
        root = Path(cwd).resolve()
        relative = path.relative_to(root).as_posix()
    except (OSError, RuntimeError, ValueError):
        return False
    exact = {
        ".grok/hooks/lifecycle.py", ".grok/hooks/swissknifeman.json",
        ".claude/settings.json", ".claude/settings.local.json", "CLAUDE.md", "AGENTS.md",
        ".ai/guidelines/agent-entrypoint.md",
    }
    if relative in exact:
        return True
    parts = relative.split("/")
    return (len(parts) >= 3 and parts[0] in {".agents", ".claude", ".grok"}
            and parts[1] in {"skills", "commands"}
            and parts[-1] in {"SKILL.md", "command.md"})


def _maind_health_state(cwd):
    """Return True/False for a completed health probe, otherwise None (fail-open)."""
    if not isinstance(cwd, str) or not cwd:
        return None
    try:
        result = subprocess.run(["maind", "health", "--cwd", "--json"], cwd=cwd,
                                capture_output=True, text=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if result.returncode != 0:
        return None
    try:
        report = json.loads(result.stdout)
    except (TypeError, ValueError):
        return None
    status = report.get("status") if isinstance(report, dict) else None
    return status == "connected" if isinstance(status, str) else None


def _deny_maind_connection():
    reason = ("project is not connected to mAInd; run `maind health --cwd`, and if its status is "
              "not connected run `maind health --cwd --fix`; repeat this tool call after connected.")
    decision = {
        "decision": "deny",
        "reason": reason,
        "hookSpecificOutput": {
            "permissionDecision": "deny",
            "permissionDecisionReason": reason,
        },
    }
    print(json.dumps(decision, ensure_ascii=False))
    _adapter_log(reason)
    raise SystemExit(2)


def _grok_maind_gate(payload):
    """Keep the existing all-tool telemetry matcher as the mAInd admission point."""
    if _PROVIDER_ID != "grok":
        return
    tool_name = payload.get("tool_name")
    tool_input = payload.get("tool_input") or {}
    if (not isinstance(tool_name, str) or not tool_name
            or tool_name not in _GROK_KNOWN_TOOL_NAMES and not tool_name.startswith("mcp__")):
        _adapter_log("unrecognized Grok tool name; allowing without mAInd admission")
        return
    if tool_name in _GROK_UNCONDITIONAL_ALLOW:
        return
    if tool_name == "run_terminal_command" and _is_maind_health_command(tool_input.get("command")):
        return
    if tool_name == "read_file" and _bootstrap_read_allowed(payload.get("cwd"), tool_input):
        return
    connected = _maind_health_state(payload.get("cwd"))
    if connected is None:
        _adapter_log("mAInd health probe unavailable; allowing this Grok tool call")
        return
    if not connected:
        _deny_maind_connection()


def _read_mode(cwd, filename):
    """MODE from <cwd>/<_STATE_DIR>/<filename> (harness hooks writes it).
    Missing/unreadable/unknown value -> off (safe default, matches the Claude-side
    gates and the phase's explicit "inactive by default" requirement)."""
    if not cwd:
        return "off"
    f = Path(cwd) / _STATE_DIR / filename
    try:
        text = f.read_text(encoding="utf-8")
    except OSError:
        return "off"
    mode = "off"
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = (part.strip() for part in line.split("=", 1))
        if key == "MODE":
            value = value.lower()
            allowed = ("on", "off") if filename == "terminal-admission.env.ini" else _GATE_MODES
            mode = value if value in allowed else "off"
    return mode
_ROUTER_CATALOG = []
_ROUTER_HINT_TEMPLATE = '[intent-router] bias (non-authoritative): top={name} score={score}{runners}'
_ROUTER_THRESHOLD = 2


def _observer(payload):
    print(json.dumps({"systemMessage": "[harness observer] read-only policy active"}))


_SYMBOL_TOOLS = frozenset({
    "find_symbol", "find_referencing_symbols", "get_symbols_overview",
    "find_implementations", "find_declaration", "replace_symbol_body",
    "insert_after_symbol", "insert_before_symbol", "rename_symbol",
    "safe_delete_symbol", "replace_content", "replace_in_files",
    "get_diagnostics_for_file",
})
_CODEGRAPH_SERVERS = (
    "codegraph", "serena", "plugin_core_codegraph", "plugin-core-codegraph",
    "laravel-boost", "laravel_boost",
)


def _codegraph_server_match(server):
    norm = server.lower().replace("-", "_")
    return any(marker.replace("-", "_") in norm for marker in _CODEGRAPH_SERVERS)


def _codegraph_tool(name):
    if not isinstance(name, str) or not name:
        return None
    if name in _SYMBOL_TOOLS:
        return name
    low = name.lower()
    if low in _SYMBOL_TOOLS:
        return low
    if low.startswith("mcp:"):
        parts = name.split(":", 2)
        if len(parts) >= 3 and _codegraph_server_match(parts[1]):
            return parts[2]
        return None
    if not name.startswith("mcp__"):
        return None
    tail = name.rsplit("__", 1)[-1]
    server = name[len("mcp__"):-(len(tail) + 2)]
    if _codegraph_server_match(server):
        return tail
    return None


def _append_jsonl(path, payload):
    line = json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(",", ":")) + "\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(str(path), os.O_APPEND | os.O_CREAT | os.O_WRONLY, 0o600)
    try:
        os.write(fd, line.encode("utf-8"))
    finally:
        os.close(fd)


def _tool_sidecar_path(cwd, session_id):
    return Path(cwd) / _STATE_DIR / "state" / (str(session_id) + ".tools.jsonl")


def _aggregate_tool_sidecar(path):
    counts = {}
    codegraph = {}
    if not path.is_file():
        return counts, codegraph
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return counts, codegraph
    for line in lines:
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except ValueError:
            continue
        if not isinstance(row, dict):
            continue
        tool = row.get("tool")
        if isinstance(tool, str) and tool:
            counts[tool] = counts.get(tool, 0) + 1
        op = row.get("codegraph_op")
        if isinstance(op, str) and op:
            rec = codegraph.setdefault(op, {"calls": 0})
            rec["calls"] += 1
    return counts, codegraph


def _run_report_lib_path(cwd):
    candidates = []
    try:
        proc = subprocess.run(
            ["git", "-C", str(cwd), "rev-parse", "--show-toplevel"],
            capture_output=True, text=True, timeout=2, check=False)
        root = proc.stdout.strip() if proc.returncode == 0 else ""
        if root:
            candidates.append(Path(root) / "packages/harness/configs/claude-code/hooks/run-report/_lib.py")
    except (OSError, subprocess.SubprocessError):
        pass
    candidates.append(Path.home() / ".claude/hooks/run-report/_lib.py")
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    return None


def _parse_transcript_report(cwd, tpath):
    lib = _run_report_lib_path(cwd)
    if lib is None:
        return None
    try:
        spec = importlib.util.spec_from_file_location("run_report_lib", lib)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        with open(tpath, encoding="utf-8", errors="replace") as handle:
            return mod.parse_events(handle, transcript_path=tpath, cwd=cwd)
    except Exception:
        return None


def _tool_telemetry(payload):
    session_id = payload.get("session_id")
    tool_name = payload.get("tool_name")
    cwd = payload.get("cwd")
    if not isinstance(cwd, str) or not cwd or not isinstance(session_id, str) or not session_id:
        return
    if not isinstance(tool_name, str) or not tool_name:
        return
    _grok_maind_gate(payload)
    entry = {"tool": tool_name, "ts": datetime.datetime.now(datetime.timezone.utc).isoformat()}
    cg = _codegraph_tool(tool_name)
    if cg:
        entry["codegraph_op"] = cg
    try:
        _append_jsonl(_tool_sidecar_path(cwd, session_id), entry)
    except OSError:
        pass


def _command_log(payload):
    log_dir = Path.home() / _STATE_DIR / "logs"
    try:
        log_dir.mkdir(parents=True, exist_ok=True)
        tool_input = payload.get("tool_input") or {}
        entry = {"ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                 "cwd": payload.get("cwd", ""), "session": payload.get("session_id", ""),
                 "command": tool_input.get("command"), "description": tool_input.get("description")}
        with (log_dir / "bash-commands.jsonl").open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except OSError:
        pass


def _run_report(payload):
    cwd = payload.get("cwd")
    if not cwd:
        return
    sid = payload.get("session_id") or "session"
    sid = "".join(c for c in str(sid) if c.isalnum() or c in "-_") or "session"
    tpath = payload.get("transcript_path")
    record = None
    if isinstance(tpath, str) and tpath:
        tpath = os.path.expanduser(tpath)
        if os.path.isfile(tpath):
            record = _parse_transcript_report(cwd, tpath)
    sidecar = _tool_sidecar_path(cwd, sid)
    by_name, codegraph = _aggregate_tool_sidecar(sidecar)
    if record is None:
        record = {"schema": 1, "session_id": sid, "cwd": cwd,
                   "reason": payload.get("reason", ""),
                   "transcript_path": tpath if isinstance(tpath, str) else None}
    else:
        record["schema"] = 2
        record["provider_id"] = _PROVIDER_ID
        record["session_id"] = sid
        record["cwd"] = cwd
        record["reason"] = payload.get("reason") or ""
        if isinstance(tpath, str):
            record["transcript_path"] = tpath
        try:
            record["generated_at"] = datetime.datetime.now(datetime.timezone.utc).isoformat()
        except Exception:
            pass
    if by_name:
        record["tools"] = {"total": sum(by_name.values()), "by_name": by_name}
    if codegraph:
        existing = record.get("codegraph") if isinstance(record.get("codegraph"), dict) else {}
        for op, rec in codegraph.items():
            cur = existing.setdefault(op, {"calls": 0})
            cur["calls"] += rec.get("calls", 0)
        record["codegraph"] = existing
    runs_dir = Path(cwd) / _STATE_DIR / "runs"
    try:
        runs_dir.mkdir(parents=True, exist_ok=True)
        gitignore = runs_dir / ".gitignore"
        if not gitignore.exists():
            gitignore.write_text("*\n", encoding="utf-8")
        target = runs_dir / (sid + ".json")
        tmp = target.with_name(target.name + ".tmp")
        tmp.write_text(json.dumps(record, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                       encoding="utf-8")
        os.replace(tmp, target)
        if sidecar.is_file():
            sidecar.unlink()
    except OSError:
        pass


def _router(payload):
    text = payload.get("prompt") or ""
    if not isinstance(text, str) or not text.strip():
        return
    low = text.lower()
    scored = []
    for entry in _ROUTER_CATALOG:
        score = sum(entry.get("weight", 1) for keyword in entry["keywords"] if keyword in low)
        if score > 0:
            scored.append((entry["name"], score))
    scored.sort(key=lambda item: item[1], reverse=True)
    if not scored or scored[0][1] < _ROUTER_THRESHOLD:
        return
    top_name, top_score = scored[0]
    runners = ", ".join(f"{name} ({score})" for name, score in scored[1:3])
    try:
        hint = _ROUTER_HINT_TEMPLATE.format(
            name=top_name, score=top_score,
            runners=("; runners-up: " + runners) if runners else "")
    except (KeyError, IndexError):
        return
    print(json.dumps({"systemMessage": hint}))


def _memory(payload):
    cwd = payload.get("cwd")
    mode, brain = "maind", "core"
    if cwd:
        ini = Path(cwd) / _STATE_DIR / "memory.env.ini"
        try:
            for line in ini.read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = (part.strip() for part in line.split("=", 1))
                if key == "MODE":
                    mode = value
                elif key == "BRAIN":
                    brain = value
        except OSError:
            pass
    if mode != "maind":
        return
    try:
        import subprocess
        result = subprocess.run(["maind", "memory", "status", "--brain", brain],
                                capture_output=True, text=True, timeout=5, check=False)
        if result.returncode == 0 and result.stdout.strip():
            print(json.dumps({"systemMessage": "[harness memory] " + result.stdout.strip()}))
    except Exception:
        pass


def _managed_pack_files(cwd):
    """target (project-relative) -> pack name, from .claude/agent-pack.lock.json — the
    SAME lock a Codex session sees when it edits a Claude-onboarded project's managed
    assets (codex-parity-verdicts-v1.md §3.5). Without user_owned entries."""
    result = {}
    try:
        lock = json.loads((Path(cwd) / ".claude" / "agent-pack.lock.json").read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return result
    packs = lock.get("packs") if isinstance(lock, dict) else None
    if not isinstance(packs, dict):
        return result
    for pack_name, pack in packs.items():
        if not isinstance(pack, dict):
            continue
        for f in pack.get("files") or []:
            if isinstance(f, dict) and f.get("target") and not f.get("user_owned"):
                result[f["target"]] = pack_name
    return result


def _pack_guard(payload):
    cwd = payload.get("cwd")
    mode = _read_mode(cwd, "pack-guard.env.ini")
    if mode == "off" or not cwd:
        return
    managed = _managed_pack_files(cwd)
    if not managed:
        return
    tool_input = payload.get("tool_input") or {}
    try:
        haystack = json.dumps(tool_input, ensure_ascii=False)
    except (TypeError, ValueError):
        return
    root = _find_repo_root()
    if root is None:
        sys.stderr.write("lifecycle.pack-guard: refusing command — harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_pack_guard_core import matched_target, pack_guard_verdict
        gate = pack_guard_verdict(haystack, mode=mode, managed_targets=tuple(managed))
    except Exception:
        sys.stderr.write("lifecycle.pack-guard: refusing command — policy core failed\n")
        sys.exit(2)
    match = gate.match
    if match is None or gate.decision == "allow":
        return
    hit = matched_target(match.id) or "<unknown>"
    pack = managed.get(hit, "unknown")
    msg = (f"pack-guard: command payload matched managed target '{hit}' from pack '{pack}' "
          f"(swissknifeman:generated) — edit the pack overlay or accept the change "
          f"through 'harness agent-packs upgrade': {hit}")
    if gate.decision == "deny":
        sys.stderr.write(msg + "\n")
        sys.exit(2)
    print(json.dumps({"systemMessage": "[warn] " + msg}))


_CHANGELOG_NAME_RE = re.compile(r"changelog", re.IGNORECASE)
_CHANGELOG_SEGMENT_RE = re.compile(r"&&|\|\||;|\|")


def _commit_messages(command):
    if not isinstance(command, str) or not command.strip():
        return []
    for segment in _CHANGELOG_SEGMENT_RE.split(command):
        try:
            tokens = shlex.split(segment)
        except ValueError:
            continue
        for i in range(len(tokens) - 1):
            if tokens[i] == "git" and tokens[i + 1] == "commit":
                rest = tokens[i + 2:]
                return [rest[j + 1] for j in range(len(rest) - 1) if rest[j] == "-m"]
    return []


def _git_lines(cwd, *args):
    try:
        out = subprocess.run(["git", "-C", cwd, *args], stdout=subprocess.PIPE,
                             stderr=subprocess.DEVNULL, timeout=10, check=False)
    except OSError:
        return None
    if out.returncode != 0:
        return None
    return [line for line in out.stdout.decode("utf-8", errors="replace").splitlines() if line]


def _changelog_gate(payload):
    if payload.get("tool_name") != "Bash":
        return
    cwd = payload.get("cwd")
    mode = _read_mode(cwd, "changelog-gate.env.ini")
    if mode == "off" or not cwd:
        return
    tool_input = payload.get("tool_input") or {}
    messages = _commit_messages(tool_input.get("command"))
    if not messages:
        return
    if "[changelog-skip]" in "\n".join(messages):
        return
    root = _find_repo_root()
    if root is None:
        sys.stderr.write("lifecycle.changelog-gate: refusing command — harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_changelog_gate_core import changelog_gate_verdict
        gate = changelog_gate_verdict(messages[0], mode=mode)
    except Exception:
        sys.stderr.write("lifecycle.changelog-gate: refusing command — policy core failed\n")
        sys.exit(2)
    if gate.match is None or gate.decision == "allow":
        return
    files = _git_lines(cwd, "ls-files")
    if not files or not any(_CHANGELOG_NAME_RE.search(f) for f in files):
        return
    staged = _git_lines(cwd, "diff", "--cached", "--name-only")
    if staged and any(_CHANGELOG_NAME_RE.search(f) for f in staged):
        return
    head = messages[0].split(":", 1)[0]
    reason = ("changelog-gate: commit %r matched rule %s but [Unreleased] is untouched. "
              "Stage a CHANGELOG entry or add [changelog-skip]."
              % (head, gate.match.id))
    if gate.decision == "deny":
        sys.stderr.write(reason + "\n")
        sys.exit(2)
    print(json.dumps({"systemMessage": "[warn] " + reason}))


_REVIEW_GATE_MAX_ATTEMPTS = 3


def _review_gate(payload):
    cwd = payload.get("cwd")
    mode = _read_mode(cwd, "review-gate.env.ini")
    if mode == "off" or not cwd:
        return
    config_path = Path.home() / ".claude" / "hooks" / "review-gate" / "config.json"
    try:
        check = json.loads(config_path.read_text(encoding="utf-8")).get("check_command") or ""
    except (OSError, ValueError):
        check = ""
    if not check:
        return
    key = (payload.get("session_id") or "nosession") + ":" + (payload.get("agent_id") or "noagent")
    state_path = Path(cwd) / _STATE_DIR / "review-gate.state.json"
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        state = {}
    attempts = int(state.get(key, 0)) if isinstance(state, dict) else 0
    try:
        result = subprocess.run(["bash", "-c", check], cwd=cwd, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, timeout=120)
    except OSError:
        return
    if result.returncode == 0:
        if isinstance(state, dict) and key in state:
            del state[key]
            try:
                state_path.parent.mkdir(parents=True, exist_ok=True)
                state_path.write_text(json.dumps(state), encoding="utf-8")
            except OSError:
                pass
        return
    body = result.stdout.decode("utf-8", errors="replace")
    reason = f"review-gate: check failed after subagent stop (mode={mode}).\n{body}"
    if mode == "strict" and attempts < _REVIEW_GATE_MAX_ATTEMPTS:
        if not isinstance(state, dict):
            state = {}
        state[key] = attempts + 1
        try:
            state_path.parent.mkdir(parents=True, exist_ok=True)
            state_path.write_text(json.dumps(state), encoding="utf-8")
        except OSError:
            pass
        sys.stderr.write(reason + "\n")
        sys.exit(2)   # forces the subagent to continue — does not cancel it (D88)
    print(json.dumps({"systemMessage": reason}))


_RM_HEAD_RE = re.compile(r"\b(rm|rmdir)\b")


def _find_repo_root():
    for name in ("swissknifeman", "skiller", "harness", "maind"):
        exe = shutil.which(name)
        if not exe:
            continue
        d = os.path.dirname(os.path.realpath(exe))
        for _ in range(6):
            if os.path.isfile(os.path.join(d, ".claude-plugin", "marketplace.json")):
                return d
            parent = os.path.dirname(d)
            if parent == d:
                break
            d = parent
    return None


def _safe_dirs(payload):
    """PreToolUse deny for rm/rmdir — CALLS the single allowlist verdict core
    (packages/harness/configs/claude-code/hooks/auto-approve/lib/safedirs.py) rather
    than copying its rules (codex-parity-verdicts-v1.md §3.8). Unreachable checkout is
    a refusal, not a pass."""
    if payload.get("tool_name") != "Bash":
        return
    tool_input = payload.get("tool_input") or {}
    command = tool_input.get("command")
    if not isinstance(command, str) or not _RM_HEAD_RE.search(command):
        return
    root = _find_repo_root()
    if root is None:
        sys.stderr.write("security.safe-dirs: refusing rm/rmdir — harness checkout "
                         "unreachable from this session\n")
        sys.exit(2)
    module_path = os.path.join(root, "packages", "harness", "configs", "claude-code",
                               "hooks", "auto-approve", "lib", "safedirs.py")
    try:
        spec = importlib.util.spec_from_file_location("codex_safedirs", module_path)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        home = os.path.expanduser("~")
        cwd = payload.get("cwd") or os.getcwd()
        allow = mod.load_allowlist(home)
        safe = allow is not None and mod.command_is_safe(command, cwd, allow, home)
    except Exception:
        safe = False
    if not safe:
        sys.stderr.write(f"security.safe-dirs: refusing rm/rmdir — '{command}' is not "
                         f"covered by the safe-dirs allowlist\n")
        sys.exit(2)


def _daemon_safety(payload):
    if payload.get("tool_name") != "Bash" or os.environ.get("PERPLEXITY_DAEMON_GUARD") == "off":
        return
    tool_input = payload.get("tool_input") or {}
    command = tool_input.get("command")
    if not isinstance(command, str):
        return
    root = _find_repo_root()
    if root is None:
        sys.stderr.write("lifecycle.daemon-safety: refusing command — harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_daemon_safety_core import daemon_safety_verdict
        gate = daemon_safety_verdict(command, mode="strict")
    except Exception:
        sys.stderr.write("lifecycle.daemon-safety: refusing command — policy core failed\n")
        sys.exit(2)
    match = gate.match
    if gate.decision != "deny" or match is None:
        return
    sys.stderr.write(
        "BLOCKED (daemon-safety): %r matched rule %s (%s). One-time bypass: "
        "PERPLEXITY_DAEMON_GUARD=off.\n"
        % (command, match.id, match.pattern)
    )
    sys.exit(2)


def _git_guardrails(payload):
    if payload.get("tool_name") != "Bash":
        return
    cwd = payload.get("cwd")
    mode = _read_mode(cwd, "git-guardrails.env.ini")
    if mode == "off" or not cwd:
        return
    tool_input = payload.get("tool_input") or {}
    command = tool_input.get("command")
    if not isinstance(command, str):
        return
    root = _find_repo_root()
    if root is None:
        sys.stderr.write("lifecycle.git-guardrails: refusing command — harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_git_guardrails_core import git_guardrails_verdict
        gate = git_guardrails_verdict(command, mode=mode)
    except Exception:
        sys.stderr.write("lifecycle.git-guardrails: refusing command — policy core failed\n")
        sys.exit(2)
    match = gate.match
    if match is None or gate.decision == "allow":
        return
    reason = "git-guardrails: %r matched rule %s (%s)." % (command, match.id, match.pattern)
    if gate.decision == "deny":
        sys.stderr.write(reason + "\n")
        sys.exit(2)
    print(json.dumps({"systemMessage": "[warn] " + reason}))


def _terminal_admission(payload):
    """Thin Stop adapter: P4.1 remains the only terminal-policy owner."""
    cwd = payload.get("cwd")
    if _read_mode(cwd, "terminal-admission.env.ini") != "on" or not cwd:
        return
    cli = Path(cwd) / "packages/task/scripts/plan-terminal-admission.py"
    if cli.is_file():
        try:
            result = subprocess.run([sys.executable, str(cli), "check-open", "--project-root", cwd,
                                     "--provider-session", str(payload.get("session_id") or "")],
                                    cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10)
            verdict = json.loads(result.stdout)
            if not isinstance(verdict, dict) or result.returncode not in (0, 1):
                raise ValueError("invalid pre-final result")
        except (OSError, subprocess.TimeoutExpired, TypeError, ValueError):
            verdict = {"final_allowed": False, "reason": "TASK_FINAL_STATE_UNAVAILABLE"}
        if not verdict.get("final_allowed"):
            print(json.dumps({"decision": "block", "reason": verdict.get("reason")})); return
    request = payload.get("terminal_admission")
    if not isinstance(request, dict) or not request.get("applicable"):
        return
    required = ("plan", "subject", "command", "run_id", "task_session", "continuity_json")
    if any(not isinstance(request.get(key), str) or not request[key] for key in required):
        print(json.dumps({"decision": "block", "reason": "TERMINAL_ADMISSION_CONTEXT_INVALID"})); return
    cli = Path(cwd) / "packages/task/scripts/plan-terminal-admission.py"
    if not cli.is_file():
        print(json.dumps({"decision": "block", "reason": "TASK_TERMINAL_ADMISSION_UNAVAILABLE"})); return
    message = payload.get("last_assistant_message")
    if not isinstance(message, str):
        print(json.dumps({"decision": "block", "reason": "TERMINAL_MESSAGE_UNAVAILABLE"})); return
    command = [sys.executable, str(cli), "check", "--state-dir", str(Path(cwd) / ".swissknifeman/terminal-admission"),
               "--plan", request["plan"], "--subject", request["subject"], "--command", request["command"],
               "--run-id", request["run_id"], "--task-session", request["task_session"], "--provider", _PROVIDER_ID,
               "--provider-session", payload.get("session_id", ""), "--turn-id", payload.get("turn_id", ""),
               "--continuity-json", request["continuity_json"], "--message", message,
               "--plan-dir", str(Path(cwd) / "plans" / request["plan"])]
    result = subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10)
    try:
        verdict = json.loads(result.stdout)
    except (TypeError, ValueError):
        verdict = {"final_allowed": False, "reason": "TERMINAL_ADMISSION_VERIFIER_FAILED"}
    if not verdict.get("final_allowed"):
        print(json.dumps({"decision": "block", "reason": str(verdict.get("reason") or "TERMINAL_ADMISSION_REFUSED")}))


_HANDLERS = {
    "context.observer": _observer,
    "lifecycle.command-log": _command_log,
    "lifecycle.tool-telemetry": _tool_telemetry,
    "lifecycle.run-report": _run_report,
    "lifecycle.router": _router,
    "lifecycle.memory": _memory,
    "lifecycle.pack-guard": _pack_guard,
    "lifecycle.changelog-gate": _changelog_gate,
    "lifecycle.review-gate": _review_gate,
    "security.safe-dirs": _safe_dirs,
    "lifecycle.daemon-safety": _daemon_safety,
    "lifecycle.git-guardrails": _git_guardrails,
    "lifecycle.terminal-admission": _terminal_admission,
}

parser = argparse.ArgumentParser()
parser.add_argument("--surface", required=True)
args = parser.parse_args()
if args.surface not in _SURFACE_EVENTS:
    if _PROVIDER_ID == "grok":
        _adapter_log("unknown lifecycle surface; allowing without dispatch")
        sys.exit(0)
    sys.exit(2)
try:
    raw_payload = json.load(sys.stdin)
except (json.JSONDecodeError, TypeError, ValueError):
    if _PROVIDER_ID == "grok":
        _adapter_log("payload was not parseable; allowing without dispatch")
        sys.exit(0)
    sys.exit(2)
event = _SURFACE_EVENTS[args.surface]
payload = _normalize_payload(raw_payload, event)
if payload is None:
    if _PROVIDER_ID == "grok":
        _adapter_log("payload did not match the lifecycle event; allowing without dispatch")
        sys.exit(0)
    sys.exit(2)
if _PROVIDER_ID != "grok":
    required = {"session_id", "cwd", "hook_event_name", "model"}
    if event == "SessionStart":
        required.add("permission_mode")
    required.update(_EVENT_FIELDS.get(event, ()))
    if not required.issubset(payload):
        sys.exit(2)
try:
    _HANDLERS[args.surface](payload)
except SystemExit:
    raise
except Exception as error:
    _adapter_log("lifecycle handler failed; allowing this tool call: " + type(error).__name__)
