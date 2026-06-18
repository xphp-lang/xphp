#!/usr/bin/env sh
#
# End-to-end self-test of the `xphp check` gate.
#
# Runs the REAL binary (not the in-process CommandTester) against the check
# fixtures and asserts the 0/1/2 exit contract plus that every renderer emits:
#   0 = clean   1 = at least one error   2 = operational failure
#
# Parameterized by the binary under test so the same assertions cover both the
# dev entrypoint and the released PHAR:
#   XPHP_BIN="php bin/xphp"        (default, CI self-test)
#   XPHP_BIN="php dist/xphp.phar"  (release.yml, post-build)
set -eu

XPHP="${XPHP_BIN:-php bin/xphp}"
FIX="test/fixture/check"

# check_exit <expected-code> <source-dir> <format>
# Captures stdout+stderr into the global OUT; fails loudly on a code mismatch.
check_exit() {
    set +e
    OUT=$($XPHP check "$2" --format="$3" 2>&1)
    code=$?
    set -e
    if [ "$code" != "$1" ]; then
        echo "FAIL: '$XPHP check $2 --format=$3' expected exit $1, got $code"
        echo "$OUT"
        exit 1
    fi
}

# Assert the captured OUT is well-formed JSON (php is always present; avoids a jq dep).
valid_json() {
    printf '%s' "$OUT" | php -r 'json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);' \
        || { echo "FAIL: output was not valid JSON"; echo "$OUT"; exit 1; }
}

# clean sources → exit 0, and all three renderers emit without error.
check_exit 0 "$FIX/clean/source" text
check_exit 0 "$FIX/clean/source" github
check_exit 0 "$FIX/clean/source" json; valid_json
echo "[ok] clean passes (exit 0) in text/json/github"

# known-bad sources → exit 1, a GitHub annotation is emitted, json stays well-formed.
check_exit 1 "$FIX/multi_error/source" github
printf '%s' "$OUT" | grep -q '^::error file=' || { echo "FAIL: no ::error annotation"; echo "$OUT"; exit 1; }
check_exit 1 "$FIX/multi_error/source" json; valid_json
echo "[ok] multi_error fails (exit 1) with ::error annotation + valid json"

# operational failure: a missing source dir is exit 2, distinct from a found-errors exit 1.
check_exit 2 "$FIX/does-not-exist" text
echo "[ok] missing source dir is operational failure (exit 2)"

echo "check smoke: PASS ($XPHP)"
