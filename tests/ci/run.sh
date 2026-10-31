#!/usr/bin/env bash
# Runs every suite against the Joomla prepared by tests/ci/setup.sh and fails when any of them fails.
# Environment (see tests/e2e.py): FG_BASE FG_WWW FG_DB FG_MYSQL FG_MAILS FG_SITE FG_PKG
set -uo pipefail
cd "$(dirname "$0")/.."
python3 check_languages.py || exit 1

suites=(e2e.py audit.py release110.py release111.py release112.py release113.py release120.py release121.py
        release122.py release123.py release124.py release125.py release126.py release140.py release150.py
        browser_click.py browser_fallback.py)
failed=()
for s in "${suites[@]}"; do
  echo "### $s"
  if ! python3 "$s" > "/tmp/fg-suite-$s.log" 2>&1; then
    failed+=("$s")
  fi
  grep -E "^FAIL|passed|Traceback|Error" "/tmp/fg-suite-$s.log" | head -20
done
if [ ${#failed[@]} -gt 0 ]; then
  echo "FAILED suites: ${failed[*]}"
  exit 1
fi
echo "all suites passed"
