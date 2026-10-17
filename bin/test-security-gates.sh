#!/usr/bin/env bash
# Negative proof of the secret gate: a synthetic key outside the repository must be detected (gitleaks exit 1).
# The key is generated at runtime and never written inside the repository.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

suffix="$(python3 -c 'import random, string; print("".join(random.SystemRandom().choices(string.ascii_uppercase + "234567", k=16)))')"
secret="$(python3 -c 'import random, string; print("".join(random.SystemRandom().choices(string.ascii_letters + string.digits, k=40)))')"
printf 'aws_access_key_id = AKIA%s\naws_secret_access_key = %s\n' "$suffix" "$secret" > "$tmp/credentials"

status=0
(cd "$root" && bash bin/security-tools.sh gitleaks -- dir --no-banner --redact "$tmp" > /dev/null 2>&1) || status=$?

if [ "$status" -ne 1 ]; then
    echo "test-security-gates: expected gitleaks exit 1 for a synthetic key, got $status" >&2
    exit 1
fi

echo "test-security-gates: synthetic key detected (exit 1)"
