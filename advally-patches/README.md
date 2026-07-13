# Advally FreeScout patches (upgrade-safety)

Tracked patches that carry Advally's first-party hardening of **upstream
FreeScout `app/*.php` files** across FreeScout version upgrades.

## Why this exists

Advally code changes deploy to prod (`/var/www/service.advally.com`) via **git**
on the `advally-prod` branch of this fork (`MonsieurBishop/freescout`) — see the
LT-457467 delivery (merge fork PR → `git pull` on prod). That path is durable.

But a **FreeScout version upgrade** does NOT go through git. It runs the in-app
self-updater (`codedge/laravel-selfupdater`, `GithubRepositoryType`), which
downloads a release archive and **extracts it over `app/`**, silently reverting
any hardening we carry in first-party files. `App\*` classes cannot use the
`overrides/` mechanism (that only shadows composer *vendor packages*, per Mack's
prod check on LT-461315), so the guard has to be re-applied post-upgrade.

## What's here

- `0001-movetomailbox-null-guard.patch` — restores the `Conversation::moveToMailbox()`
  null guard (LT-461315 / bug #181). Without it, moving an **owner-less**
  conversation to another mailbox derefs a null assignee
  (`$conv_user->can(...)`) → `FatalThrowableError` → FreeScout 500, surfaced by
  the reporting app as a **502 `mailbox_change_failed`** so the staff reply never
  sends (~95% of tickets are owner-less in our team/mailbox model).
- `reapply.sh` — re-applies every `*.patch` here and **exits non-zero** if any
  patch neither is already applied nor applies cleanly (a clean upgrade leaves
  the guard in place → "already applied, skip"; an upstream rewrite of the
  patched code → **hard fail**, so a human re-verifies before the box is trusted).

## Runbook — run after every FreeScout self-update

From the FreeScout app root on prod (as the `freescout` user, sanctioned
non-root login — never `ssh root@`):

```bash
cd /var/www/service.advally.com
bash advally-patches/reapply.sh   # exit 0 = safe; exit 1 = STOP, re-verify by hand
php artisan queue:restart               # workers pick up the re-applied code
```

If `reapply.sh` exits non-zero, an upstream upgrade changed the patched code:
regenerate the patch against the new upstream (`git diff` the guard) and re-verify
the null-safety by hand before considering the box healthy.

## Wiring it to run automatically (one open prod fact)

The self-updater emits `Codedge\Updater\Events\UpdateSucceeded` and honours
`config('self-update.exclude_folders')`. To make re-apply automatic rather than a
manual runbook step, the runner must live in a folder the self-updater
**excludes** (or it too gets overwritten). Two options, decided by what
`exclude_folders` actually contains on prod:

- **(a)** an `UpdateSucceeded` listener that shells `reapply.sh`, housed in an
  excluded FreeScout **Module** (Modules are excluded by default), or
- **(b)** keep it a **manual post-update step** (the runbook above) invoked by
  whoever runs the upgrade.

Ship as-is: the patch + `reapply.sh` are mechanism-agnostic and work under either
wiring. Confirming `exclude_folders` on prod (Mack has non-root access) picks (a)
vs (b); until then, the manual runbook step is the safe default.
