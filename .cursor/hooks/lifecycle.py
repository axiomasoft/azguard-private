#!/usr/bin/env python3
import argparse, datetime, hashlib, importlib.util, json, os, re, shlex, subprocess, sys
from pathlib import Path

_RM_HEAD_RE = re.compile(r"\b(rm|rmdir)\b")
_EVENT_FIELDS = {
    "beforeShellExecution": ("command",),
    "afterShellExecution": ("command", "duration", "sandbox"),
    "preToolUse": ("tool_name", "tool_input"),
    "subagentStop": ("status", "loop_count"),
    "sessionStart": ("session_id",),
    "sessionEnd": ("reason",),
    "stop": ("status", "loop_count"),
}
_COMMON_FIELDS = ("hook_event_name", "conversation_id")
_REASON_TO_OUTCOME = {
    "completed": "ok",
    "aborted": "unknown",
    "error": "failed",
    "window_close": "unknown",
    "user_close": "unknown",
}
_GATE_MODES = ("off", "warn", "strict")
_MEMORY_MODES = ("maind", "file", "federation", "agentmemory", "off")
_CHANGELOG_SKIP_MARKER = "[changelog-skip]"
_CHANGELOG_NAME_RE = re.compile(r"changelog", re.IGNORECASE)
_CHANGELOG_SEGMENT_RE = re.compile(r"&&|\|\||;|\|")
_CURSOR_FOLLOWUP_LOOP_LIMIT = 3
_PERMISSION_EMITTED = False


def _emit_permission(permission, *, user_message=None, agent_message=None):
    global _PERMISSION_EMITTED
    if _PERMISSION_EMITTED:
        return
    out = {"permission": permission}
    if user_message:
        out["user_message"] = user_message
    if agent_message:
        out["agent_message"] = agent_message
    print(json.dumps(out, ensure_ascii=False))
    _PERMISSION_EMITTED = True


def _emit_shell_allow(*, agent_message=None):
    _emit_permission("allow", agent_message=agent_message)


def _emit_shell_deny(reason):
    _emit_permission("deny", user_message=reason)


def _emit_tool_allow(*, agent_message=None):
    _emit_permission("allow", agent_message=agent_message)


def _emit_tool_deny(reason):
    _emit_permission("deny", user_message=reason)


def _utc_now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def _conversation_id(payload):
    value = payload.get("conversation_id")
    if isinstance(value, str) and value:
        return value
    return None


def _payload_cwd(payload):
    cwd = payload.get("cwd")
    if isinstance(cwd, str) and cwd:
        return cwd
    env_root = os.environ.get("CURSOR_PROJECT_DIR")
    if env_root:
        return env_root
    roots = payload.get("workspace_roots")
    if isinstance(roots, list):
        for item in roots:
            if isinstance(item, str) and item:
                return item
    return os.getcwd()


def _read_mode(cwd, filename):
    if not cwd:
        return "off"
    env_file = Path(cwd) / ".cursor" / filename
    try:
        text = env_file.read_text(encoding="utf-8")
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


def _followup_state_path(cwd, filename):
    return Path(cwd) / ".cursor" / filename


def _load_followup_state(path):
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    return value if isinstance(value, dict) else {}


def _save_followup_state(path, state):
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(state, sort_keys=True), encoding="utf-8")
    except OSError:
        pass


def _clear_followup_state(path, key):
    state = _load_followup_state(path)
    if key not in state:
        return
    state.pop(key, None)
    _save_followup_state(path, state)


def _loop_count(payload):
    value = payload.get("loop_count")
    return value if isinstance(value, int) and not isinstance(value, bool) else None


def _read_memory_config(cwd):
    mode, brain = "off", "core"
    if not cwd:
        return mode, brain
    env_file = Path(cwd) / ".cursor" / "memory.env.ini"
    try:
        text = env_file.read_text(encoding="utf-8")
    except OSError:
        return mode, brain
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = (part.strip() for part in line.split("=", 1))
        if key == "MODE":
            lowered = value.lower()
            mode = lowered if lowered in _MEMORY_MODES else "off"
        elif key == "BRAIN" and value:
            brain = value
    return mode, brain


def _memory(payload):
    cwd = _payload_cwd(payload)
    mode, brain = _read_memory_config(cwd)
    if mode == "off":
        return
    project_name = Path(cwd).resolve().name if cwd else "unknown"
    out = {
        "SWISSKNIFEMAN_MEMORY_PROVIDER": mode,
        "SWISSKNIFEMAN_MEMORY_BRAIN": brain,
        "SWISSKNIFEMAN_MEMORY_SCOPE": f"project:{project_name}",
    }
    print(json.dumps({"env": out}, ensure_ascii=False))


def _emit_followup_message(payload, *, cwd, state_filename, state_key, message):
    if not isinstance(message, str):
        return False
    text = message.strip()
    loop_count = _loop_count(payload)
    if not text or loop_count is None or loop_count >= _CURSOR_FOLLOWUP_LOOP_LIMIT:
        return False
    path = _followup_state_path(cwd, state_filename)
    state = _load_followup_state(path)
    digest = hashlib.sha256(text.encode("utf-8")).hexdigest()
    previous = state.get(state_key) if isinstance(state.get(state_key), dict) else {}
    if previous.get("message_sha256") == digest:
        state[state_key] = {"message_sha256": digest, "refused": True}
        _save_followup_state(path, state)
        sys.stderr.write("continuation refused: repeated followup without progress\n")
        return False
    state[state_key] = {"message_sha256": digest, "refused": False}
    _save_followup_state(path, state)
    print(json.dumps({"followup_message": text}))
    return True


def _iter_hook_commands(settings, event_name):
    hooks = settings.get("hooks")
    if not isinstance(hooks, dict):
        return
    entries = hooks.get(event_name)
    if not isinstance(entries, list):
        return
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        command = entry.get("command")
        if isinstance(command, str) and command.strip():
            yield command.strip()
        nested = entry.get("hooks")
        if not isinstance(nested, list):
            continue
        for hook in nested:
            command = hook.get("command") if isinstance(hook, dict) else None
            if isinstance(command, str) and command.strip():
                yield command.strip()


def _claude_compat_review_gate_present(cwd):
    paths = [Path.home() / ".claude" / "settings.json"]
    if cwd:
        paths.append(Path(cwd) / ".claude" / "settings.json")
        paths.append(Path(cwd) / ".claude" / "settings.local.json")
    for path in paths:
        try:
            settings = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        if not isinstance(settings, dict):
            continue
        for command in _iter_hook_commands(settings, "SubagentStop") or ():
            if "review-gate/review-gate.sh" in command.lower():
                return True
    return False


def _find_repo_root(payload):
    candidates = []
    env_root = os.environ.get("CURSOR_PROJECT_DIR")
    if env_root:
        candidates.append(env_root)
    roots = payload.get("workspace_roots")
    if isinstance(roots, list):
        candidates.extend(item for item in roots if isinstance(item, str) and item)
    cwd = payload.get("cwd")
    if isinstance(cwd, str) and cwd:
        candidates.append(cwd)
    for candidate in candidates:
        d = Path(candidate).resolve()
        for _ in range(6):
            marker = d / "packages" / "harness" / "configs" / "claude-code" / "hooks" / "auto-approve" / "lib" / "safedirs.py"
            if marker.is_file():
                return str(d)
            if d.parent == d:
                break
            d = d.parent
    for name in ("swissknifeman", "skiller", "harness", "maind"):
        import shutil
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
    command = payload.get("command")
    if not isinstance(command, str) or not _RM_HEAD_RE.search(command):
        return
    root = _find_repo_root(payload)
    if root is None:
        _emit_shell_deny("security.safe-dirs: refusing rm/rmdir - harness checkout unreachable")
        sys.exit(0)
    module_path = os.path.join(root, "packages", "harness", "configs", "claude-code",
                               "hooks", "auto-approve", "lib", "safedirs.py")
    try:
        spec = importlib.util.spec_from_file_location("cursor_safedirs", module_path)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        home = os.path.expanduser("~")
        cwd = _payload_cwd(payload)
        allow = mod.load_allowlist(home)
        safe = allow is not None and mod.command_is_safe(command, cwd, allow, home)
    except Exception:
        safe = False
    if not safe:
        _emit_shell_deny(
            f"security.safe-dirs: refusing rm/rmdir - '{command}' is not covered by the safe-dirs allowlist",
        )
        sys.exit(0)


def _daemon_safety(payload):
    command = payload.get("command")
    if not isinstance(command, str) or os.environ.get("PERPLEXITY_DAEMON_GUARD") == "off":
        return
    root = _find_repo_root(payload)
    if root is None:
        sys.stderr.write("lifecycle.daemon-safety: refusing command - harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_daemon_safety_core import daemon_safety_verdict
        gate = daemon_safety_verdict(command, mode="strict")
    except Exception:
        sys.stderr.write("lifecycle.daemon-safety: refusing command - policy core failed\n")
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
    command = payload.get("command")
    if not isinstance(command, str):
        return
    cwd = _payload_cwd(payload)
    mode = _read_mode(cwd, "git-guardrails.env.ini")
    if mode == "off":
        return
    root = _find_repo_root(payload)
    if root is None:
        sys.stderr.write("lifecycle.git-guardrails: refusing command - harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_git_guardrails_core import git_guardrails_verdict
        gate = git_guardrails_verdict(command, mode=mode)
    except Exception:
        sys.stderr.write("lifecycle.git-guardrails: refusing command - policy core failed\n")
        sys.exit(2)
    match = gate.match
    if match is None or gate.decision == "allow":
        return
    reason = ("git-guardrails: %r matched rule %s (%s)."
              % (command, match.id, match.pattern))
    if gate.decision == "deny":
        sys.stderr.write(reason + "\n")
        sys.exit(2)
    print(json.dumps({
        "permission": "allow",
        "agent_message": "[warn] " + reason,
    }))


def _commit_messages(command):
    if not isinstance(command, str) or not command.strip():
        return []
    for segment in _CHANGELOG_SEGMENT_RE.split(command):
        try:
            tokens = shlex.split(segment)
        except ValueError:
            continue
        for index in range(len(tokens) - 1):
            if tokens[index] == "git" and tokens[index + 1] == "commit":
                rest = tokens[index + 2:]
                return [rest[item + 1] for item in range(len(rest) - 1) if rest[item] == "-m"]
    return []


def _git_lines(cwd, *args):
    try:
        out = subprocess.run(
            ["git", "-C", cwd, *args],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=10,
            check=False,
        )
    except OSError:
        return None
    if out.returncode != 0:
        return None
    text = out.stdout.decode("utf-8", errors="replace")
    return [line for line in text.splitlines() if line]


def _changelog_gate(payload):
    command = payload.get("command")
    if not isinstance(command, str):
        return
    cwd = _payload_cwd(payload)
    mode = _read_mode(cwd, "changelog-gate.env.ini")
    if mode == "off":
        return
    messages = _commit_messages(command)
    if not messages:
        return
    if _CHANGELOG_SKIP_MARKER in "\n".join(messages):
        return
    root = _find_repo_root(payload)
    if root is None:
        sys.stderr.write("lifecycle.changelog-gate: refusing command - harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_changelog_gate_core import changelog_gate_verdict
        gate = changelog_gate_verdict(messages[0], mode=mode)
    except Exception:
        sys.stderr.write("lifecycle.changelog-gate: refusing command - policy core failed\n")
        sys.exit(2)
    if gate.match is None or gate.decision == "allow":
        return
    files = _git_lines(cwd, "ls-files")
    if not files or not any(_CHANGELOG_NAME_RE.search(path) for path in files):
        return
    staged = _git_lines(cwd, "diff", "--cached", "--name-only")
    if staged and any(_CHANGELOG_NAME_RE.search(path) for path in staged):
        return
    head = messages[0].split(":", 1)[0]
    reason = ("changelog-gate: commit %r matched rule %s but [Unreleased] is untouched. "
              "Stage a CHANGELOG entry or add [changelog-skip]."
              % (head, gate.match.id))
    if gate.decision == "deny":
        sys.stderr.write(reason + "\n")
        sys.exit(2)
    print(json.dumps({
        "permission": "allow",
        "agent_message": "[warn] " + reason,
    }))


def _managed_pack_files(cwd):
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
        for item in pack.get("files") or []:
            if isinstance(item, dict) and item.get("target") and not item.get("user_owned"):
                target = str(item["target"])
                result[target] = pack_name
                result[str(Path(cwd) / target)] = pack_name
    return result


def _pack_guard(payload):
    tool_name = payload.get("tool_name")
    if not isinstance(tool_name, str):
        return
    mode = _read_mode(_payload_cwd(payload), "pack-guard.env.ini")
    if mode == "off":
        return
    cwd = _payload_cwd(payload)
    managed = _managed_pack_files(cwd)
    if not managed:
        return
    tool_input = payload.get("tool_input")
    try:
        haystack = json.dumps({"tool_name": tool_name, "tool_input": tool_input},
                              ensure_ascii=False)
    except (TypeError, ValueError):
        return
    root = _find_repo_root(payload)
    if root is None:
        sys.stderr.write("lifecycle.pack-guard: refusing command - harness core unreachable\n")
        sys.exit(2)
    lib_root = os.path.join(root, "packages", "harness", "lib")
    try:
        if lib_root not in sys.path:
            sys.path.insert(0, lib_root)
        from harness.lifecycle_pack_guard_core import matched_target, pack_guard_verdict
        gate = pack_guard_verdict(haystack, mode=mode, managed_targets=tuple(managed))
    except Exception:
        sys.stderr.write("lifecycle.pack-guard: refusing command - policy core failed\n")
        sys.exit(2)
    match = gate.match
    if match is None or gate.decision == "allow":
        return
    hit = matched_target(match.id) or "<unknown>"
    pack = managed.get(hit, "unknown")
    reason = ("pack-guard: payload matched managed target %r from pack %r."
              % (hit, pack))
    if gate.decision == "deny":
        sys.stderr.write(reason + "\n")
        sys.exit(2)
    print(json.dumps({
        "permission": "allow",
        "agent_message": "[warn] " + reason,
    }))


def _review_gate(payload):
    cwd = _payload_cwd(payload)
    mode = _read_mode(cwd, "review-gate.env.ini")
    if mode == "off":
        return
    if payload.get("status") != "completed":
        return
    if _claude_compat_review_gate_present(cwd):
        sys.stderr.write("review-gate: skipped because Claude-compat SubagentStop hook is already registered\n")
        return
    config_path = Path.home() / ".claude" / "hooks" / "review-gate" / "config.json"
    try:
        check = json.loads(config_path.read_text(encoding="utf-8")).get("check_command") or ""
    except (OSError, ValueError):
        check = ""
    if not check:
        return
    try:
        result = subprocess.run(["bash", "-c", check], cwd=cwd, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, timeout=120, check=False)
    except OSError:
        return
    conversation = _conversation_id(payload)
    subagent_type = payload.get("subagent_type")
    if not isinstance(subagent_type, str):
        subagent_type = "subagent"
    state_key = f"{conversation or 'noconversation'}:{subagent_type}"
    state_path = _followup_state_path(cwd, "review-gate.state.json")
    if result.returncode == 0:
        _clear_followup_state(state_path, state_key)
        return
    if mode != "strict":
        return
    body = result.stdout.decode("utf-8", errors="replace").strip()
    evidence = body if body else "declared review check failed"
    followup = ("Review gate denied completion: fix the failing review check and rerun `%s`."
                "\nCheck output:\n%s" % (check, evidence))
    _emit_followup_message(payload, cwd=cwd, state_filename="review-gate.state.json",
                           state_key=state_key, message=followup)


def _terminal_admission_followup(reason):
    templates = {
        "TASK_OPEN_RUN_FINAL_FORBIDDEN": ("Terminal admission denied: an open Task run for this session is still in progress. "
                                          "Continue the active plan item and retry stop after it reaches a terminal state."),
        "TASK_WORKSET_INCOMPLETE": ("Terminal admission denied: the active Task workset is incomplete for this session. "
                                    "Finish the next required workset members, then retry stop."),
        "TASK_FINAL_STATE_UNAVAILABLE": ("Terminal admission denied: Task final-state evidence is unavailable. "
                                         "Restore plan journals/receipts and retry stop."),
        "TERMINAL_ADMISSION_CONTEXT_INVALID": ("Terminal admission denied: stop payload misses session context. "
                                               "Retry from the same Task session context."),
    }
    prefix = templates.get(reason, "Terminal admission denied: Task lease state is not ready for final completion.")
    return f"{prefix} (reason={reason})"


def _terminal_admission_decision(payload, cwd):
    conversation_id = _conversation_id(payload)
    if conversation_id is None:
        return {"final_allowed": False, "reason": "TERMINAL_ADMISSION_CONTEXT_INVALID"}
    root = _find_repo_root(payload)
    if root is None:
        return {"final_allowed": False, "reason": "TASK_FINAL_STATE_UNAVAILABLE"}
    task_lib = os.path.join(root, "packages", "task", "lib")
    try:
        if task_lib not in sys.path:
            sys.path.insert(0, task_lib)
        from task import terminal_admission
        terminal_admission.validate_contracts()
        return terminal_admission.pre_final_open_runs(Path(cwd), conversation_id)
    except Exception:
        return {"final_allowed": False, "reason": "TASK_FINAL_STATE_UNAVAILABLE"}


def _terminal_admission(payload):
    cwd = _payload_cwd(payload)
    if _read_mode(cwd, "terminal-admission.env.ini") != "on":
        return
    if payload.get("status") != "completed":
        return
    decision = _terminal_admission_decision(payload, cwd)
    state_key = _conversation_id(payload) or "noconversation"
    state_path = _followup_state_path(cwd, "terminal-admission.state.json")
    if decision.get("final_allowed"):
        _clear_followup_state(state_path, state_key)
        return
    reason = str(decision.get("reason") or "TERMINAL_ADMISSION_REFUSED")
    followup = _terminal_admission_followup(reason)
    _emit_followup_message(payload, cwd=cwd,
                           state_filename="terminal-admission.state.json",
                           state_key=state_key, message=followup)


def _first_command_token(command):
    if not isinstance(command, str) or not command.strip():
        return None
    try:
        tokens = shlex.split(command)
    except ValueError:
        return None
    if not tokens:
        return None
    index = 0
    if tokens[index] == "env":
        index += 1
        while index < len(tokens):
            token = tokens[index]
            if "=" in token and not token.startswith("-"):
                index += 1
                continue
            break
    while index < len(tokens) and tokens[index] in {"command", "builtin", "nohup", "time"}:
        index += 1
    if index >= len(tokens):
        return None
    return os.path.basename(tokens[index]) or None


def _append_jsonl(path, payload):
    line = json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(",", ":")) + "\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(str(path), os.O_APPEND | os.O_CREAT | os.O_WRONLY, 0o600)
    try:
        os.write(fd, line.encode("utf-8"))
    finally:
        os.close(fd)


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


def _tool_sidecar_path(cwd, conversation_id):
    return Path(cwd) / ".cursor" / "state" / (conversation_id + ".tools.jsonl")


def _tool_telemetry(payload):
    conversation_id = _conversation_id(payload)
    tool_name = payload.get("tool_name")
    if conversation_id is None or not isinstance(tool_name, str) or not tool_name:
        return
    cwd = _payload_cwd(payload)
    entry = {"tool": tool_name, "ts": _utc_now()}
    cg = _codegraph_tool(tool_name)
    if cg:
        entry["codegraph_op"] = cg
    try:
        path = _tool_sidecar_path(cwd, conversation_id)
        path.parent.mkdir(parents=True, exist_ok=True)
        _append_jsonl(path, entry)
    except OSError:
        pass


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


def _command_log(payload):
    command = payload.get("command")
    if not isinstance(command, str) or not command:
        return
    duration = payload.get("duration")
    sandbox = payload.get("sandbox")
    conversation_id = _conversation_id(payload)
    if (conversation_id is None
            or not isinstance(duration, (int, float)) or isinstance(duration, bool)
            or not isinstance(sandbox, bool)):
        return
    command_class = _first_command_token(command) or "unknown"
    entry = {
        "schema": 1,
        "provider_id": "cursor",
        "event_kind": "shell_execution",
        "session_id": conversation_id,
        "conversation_id": conversation_id,
        "occurred_at": _utc_now(),
        "command_sha256": "sha256:" + hashlib.sha256(command.encode("utf-8")).hexdigest(),
        "command_class": command_class,
        "duration": duration,
        "sandbox": sandbox,
        "outcome": "observed",
    }
    try:
        _append_jsonl(Path(_payload_cwd(payload)) / ".cursor" / "logs" / "commands.jsonl", entry)
    except OSError:
        pass


def _run_report(payload):
    reason = payload.get("reason")
    conversation_id = _conversation_id(payload)
    if reason not in _REASON_TO_OUTCOME or conversation_id is None:
        return
    cwd = _payload_cwd(payload)
    cursor = {"end_reason": reason}
    final_status = payload.get("final_status")
    if isinstance(final_status, str) and final_status:
        cursor["final_status"] = final_status
    native_session = payload.get("session_id")
    if isinstance(native_session, str) and native_session:
        cursor["native_session_id"] = native_session
    duration_ms = payload.get("duration_ms")
    if isinstance(duration_ms, (int, float)) and not isinstance(duration_ms, bool):
        cursor["duration_ms"] = duration_ms
    sidecar = _tool_sidecar_path(cwd, conversation_id)
    by_name, codegraph = _aggregate_tool_sidecar(sidecar)
    record = {
        "schema": 2,
        "provider_id": "cursor",
        "session_id": conversation_id,
        "conversation_id": conversation_id,
        "cwd": cwd,
        "occurred_at": _utc_now(),
        "event_kind": "run_summary",
        "outcome": _REASON_TO_OUTCOME[reason],
        "metrics": {},
        "cursor": cursor,
    }
    if by_name:
        record["tools"] = {"total": sum(by_name.values()), "by_name": by_name}
    if codegraph:
        record["codegraph"] = codegraph
    try:
        runs = Path(cwd) / ".cursor" / "runs"
        runs.mkdir(parents=True, exist_ok=True)
        gitignore = runs / ".gitignore"
        if not gitignore.exists():
            gitignore.write_text("*\n", encoding="utf-8")
        target = runs / (conversation_id + ".json")
        tmp = target.with_name(target.name + ".tmp")
        tmp.write_text(json.dumps(record, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                       encoding="utf-8")
        os.replace(tmp, target)
        if sidecar.is_file():
            sidecar.unlink()
    except OSError:
        pass


_HANDLERS = {
    "security.safe-dirs": _safe_dirs,
    "lifecycle.memory": _memory,
    "lifecycle.daemon-safety": _daemon_safety,
    "lifecycle.git-guardrails": _git_guardrails,
    "lifecycle.changelog-gate": _changelog_gate,
    "lifecycle.pack-guard": _pack_guard,
    "lifecycle.review-gate": _review_gate,
    "lifecycle.terminal-admission": _terminal_admission,
    "lifecycle.command-log": _command_log,
    "lifecycle.tool-telemetry": _tool_telemetry,
    "lifecycle.run-report": _run_report,
}
_SURFACE_EVENTS = {
    "security.safe-dirs": "beforeShellExecution",
    "lifecycle.memory": "sessionStart",
    "lifecycle.daemon-safety": "beforeShellExecution",
    "lifecycle.git-guardrails": "beforeShellExecution",
    "lifecycle.changelog-gate": "beforeShellExecution",
    "lifecycle.pack-guard": "preToolUse",
    "lifecycle.tool-telemetry": "preToolUse",
    "lifecycle.review-gate": "subagentStop",
    "lifecycle.terminal-admission": "stop",
    "lifecycle.command-log": "afterShellExecution",
    "lifecycle.run-report": "sessionEnd",
}

parser = argparse.ArgumentParser()
parser.add_argument("--surface", required=True)
args = parser.parse_args()
if args.surface not in _SURFACE_EVENTS:
    sys.exit(2)
try:
    payload = json.load(sys.stdin)
except (json.JSONDecodeError, TypeError):
    event = _SURFACE_EVENTS[args.surface]
    if event == "beforeShellExecution":
        _emit_shell_allow()
        sys.exit(0)
    if event == "preToolUse":
        _emit_tool_allow()
        sys.exit(0)
    sys.stderr.write(f"lifecycle: invalid JSON stdin for {args.surface}\n")
    sys.exit(2)
if not isinstance(payload, dict):
    event = _SURFACE_EVENTS[args.surface]
    if event == "beforeShellExecution":
        _emit_shell_allow()
        sys.exit(0)
    if event == "preToolUse":
        _emit_tool_allow()
        sys.exit(0)
    sys.stderr.write(f"lifecycle: non-object payload for {args.surface}\n")
    sys.exit(2)
event = _SURFACE_EVENTS[args.surface]
required = set(_COMMON_FIELDS)
required.update(_EVENT_FIELDS.get(event, ()))
optional = {"conversation_id"}
missing = required - set(payload.keys()) - optional
if missing or payload.get("hook_event_name") != event:
    if event == "beforeShellExecution":
        if isinstance(payload.get("command"), str):
            _HANDLERS[args.surface](payload)
        _emit_shell_allow()
        sys.exit(0)
    if event == "preToolUse":
        _emit_tool_allow()
        sys.exit(0)
    sys.stderr.write(
        f"lifecycle: invalid payload for {args.surface}: "
        f"missing={sorted(missing)} event={payload.get('hook_event_name')!r}\n",
    )
    sys.exit(2)
_HANDLERS[args.surface](payload)
if event == "beforeShellExecution":
    _emit_shell_allow()
elif event == "preToolUse":
    _emit_tool_allow()
