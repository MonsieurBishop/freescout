#!/usr/bin/env bash
#
# Advally FreeScout post-upgrade patch re-applier  (LT-461315)
# ---------------------------------------------------------------------------
# FreeScout's in-app self-updater (codedge/laravel-selfupdater) extracts a
# release archive OVER app/ on every version upgrade, silently reverting any
# first-party hardening we carry in app/*.php (e.g. the moveToMailbox() null
# guard that stops the 502 mailbox_change_failed / FreeScout 500 crash).
#
# This script re-applies every tracked patch in this directory and FAILS LOUD
# (non-zero exit) if any patch neither is already applied NOR applies cleanly.
# A hard failure here means an upstream upgrade rewrote the patched code and a
# human must re-verify the guard before the box is considered safe -- exactly
# the "fail the deploy if it doesn't apply cleanly" contract from LT-461315.
#
# Run it AFTER a FreeScout self-update, from the FreeScout app root:
#     bash advally-patches/reapply.sh
# Exit codes: 0 = all patches present/applied cleanly; 1 = a patch failed.
#
# It is idempotent: patches already present are detected and skipped, so it is
# safe to run on every deploy.
set -euo pipefail

# Resolve the app root = one level up from this script (advally-patches/).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$APP_ROOT"

shopt -s nullglob
patches=("$SCRIPT_DIR"/*.patch)
if [ ${#patches[@]} -eq 0 ]; then
    echo "[advally-patches] no .patch files found in $SCRIPT_DIR — nothing to do."
    exit 0
fi

fail=0
for p in "${patches[@]}"; do
    name="$(basename "$p")"
    if git apply --reverse --check "$p" >/dev/null 2>&1; then
        echo "[advally-patches] OK   $name — already applied, skipping."
    elif git apply --check "$p" >/dev/null 2>&1; then
        git apply "$p"
        echo "[advally-patches] APPLIED $name."
    else
        echo "[advally-patches] FAIL $name — does NOT apply cleanly and is NOT already applied." >&2
        echo "[advally-patches]      An upstream upgrade likely rewrote the target code." >&2
        echo "[advally-patches]      Re-verify the guard BY HAND before trusting this box." >&2
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "[advally-patches] one or more patches failed — DEPLOY SHOULD FAIL." >&2
    exit 1
fi
echo "[advally-patches] all patches present/applied cleanly."
exit 0
