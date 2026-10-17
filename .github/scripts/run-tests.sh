#!/usr/bin/env bash
# Runs every tests/*.php file, reports each failing file as a GitHub annotation (visible without signing in) and
# fails at the end if any file failed. Usage: run-tests.sh [--skip-gd]
set -u
skip_gd=0
[ "${1:-}" = "--skip-gd" ] && skip_gd=1
failed=0
for t in tests/*.php; do
  if [ "$skip_gd" = 1 ] && grep -q 'imagecreate' "$t"; then
    echo "--- skipped (needs gd): $t"
    continue
  fi
  echo "=== $t"
  out=$(php -d memory_limit=1G "$t" 2>&1)
  code=$?
  echo "$out" | tail -n 3
  if [ $code -ne 0 ]; then
    failed=1
    # The first failing checks and PHP errors, joined into one annotation (%0A is a newline in workflow commands)
    detail=$(echo "$out" | grep -E 'FAIL|Fatal|Warning|Error|Exception|Deprecated' | head -n 12 | cut -c1-300 | sed 's/%/%25/g' | awk '{printf "%s%%0A", $0}')
    echo "::error title=${t} (exit ${code})::${detail}"
  fi
done
exit $failed
