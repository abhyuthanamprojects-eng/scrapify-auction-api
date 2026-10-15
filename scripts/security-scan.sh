#!/usr/bin/env bash
set -euo pipefail

patterns=(
  "global\\[[[:space:]]*['\"]!['\"]\\]"
  "_0x[0-9a-fA-F]{4,}"
  "while[[:space:]]*\\([[:space:]]*!*!+[[:space:]]*\\[\\]"
  "temp_(auto|interactive)_push\\.bat"
  "node[[:space:]]+api\\.js[[:space:]]*&&"
)

failed=0
for pattern in "${patterns[@]}"; do
  if git grep --line-number --extended-regexp --ignore-case "$pattern" -- \
      ':(exclude)scripts/security-scan.sh' \
      ':(exclude)composer.lock' \
      ':(exclude)package-lock.json'; then
    echo "Security scan found a suspicious pattern: $pattern" >&2
    failed=1
  fi
done

if [[ "$failed" -ne 0 ]]; then
  echo "Security scan failed. Review the matching files before merging." >&2
  exit 1
fi

echo "Security scan passed."

# On pull requests and pushes, also inspect only newly added lines. This
# catches payload reinjection even when the current tree was cleaned up.
base_sha="${BASE_SHA:-}"
if [[ -z "$base_sha" && -n "${BASE_REF:-}" ]]; then
  base_sha="$(git merge-base HEAD "origin/${BASE_REF}")"
fi
if [[ -n "$base_sha" && "$base_sha" != "0000000000000000000000000000000000000000" ]]; then
  added_lines="$(git diff --unified=0 "$base_sha" HEAD -- . ':!scripts/security-scan.sh' ':!composer.lock' ':!package-lock.json' | sed -n 's/^+[^+].*/&/p')"
  for pattern in "${patterns[@]}"; do
    if printf '%s\n' "$added_lines" | rg --line-number --ignore-case "$pattern"; then
      echo "Security scan found a suspicious pattern in newly added lines: $pattern" >&2
      exit 1
    fi
  done
fi
