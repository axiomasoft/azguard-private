#!/usr/bin/env bash
# Pull-request guard of the compatibility baseline pointer (I18, D26).
#
#   bash bin/compat-baseline-guard.sh <base-ref> <head-ref> <labels-json>
#
# A pull request that changes compatibility.json replaces the baseline the comparison runs against. That is a release
# decision, made explicit by the label `compat-baseline`. <labels-json> is the JSON array of the pull request's label
# names (`toJSON(github.event.pull_request.labels.*.name)`).
#
# Exit codes: 0 pointer unchanged or labelled, 1 pointer changed without the label, 2 usage error (arguments, refs,
# malformed labels).
set -euo pipefail

if (($# != 3)); then
    echo "usage: compat-baseline-guard.sh <base-ref> <head-ref> <labels-json>" >&2
    exit 2
fi

base="$1"
head="$2"
labels="$3"

if ! changed="$(git diff --name-only "${base}...${head}" -- 2>/dev/null)"; then
    echo "error: cannot compare ${base}...${head}" >&2
    exit 2
fi

set +e
php -r '
    $labels = json_decode($argv[1], true);
    if (! is_array($labels) || ! array_is_list($labels) || array_filter($labels, is_string(...)) !== $labels) {
        exit(2);
    }
    exit(in_array("compat-baseline", $labels, true) ? 0 : 1);
' "${labels}"
labelled=$?
set -e

if ((labelled == 2)); then
    echo "error: the labels are not a JSON array of names: ${labels}" >&2
    exit 2
fi

if ! grep -qx 'compatibility.json' <<<"${changed}"; then
    echo "compatibility.json unchanged: the baseline stays"
    exit 0
fi

if ((labelled == 0)); then
    echo "compatibility.json changed with the label compat-baseline: the new baseline is a release decision"
    exit 0
fi

echo "::error::compatibility.json changes the compatibility baseline; add the label compat-baseline to make that release decision explicit."
exit 1
