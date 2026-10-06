#!/usr/bin/env bash
set -euo pipefail

patterns=(
  "global\\[[[:space:]]*['\"]!['\"]\\]"
  "_0x[0-9a-fA-F]{4,}"
  "while[[:space:]]*\\([[:space:]]*!*!+[[:space:]]*\\[\\]"
  "temp_(auto|interactive)_push\\.bat"
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
