# Account Management and Access Roles Design

Status: Approved by the owner on 2026-11-13 for implementation planning. Application implementation requires the subsequent execution handoff. Owner opted for written design without browser mockups.

## Intent and agreed scope

Allow administrators to manage WahaBuddy accounts without production Tinker commands. The owner requested Spatie roles and permissions and a Markdown implementation plan. Two roles are fixed: `admin` and `user`. Public registration remains disabled.

Agreed account creation modes:

- **Invitation:** admin supplies name, email, and role. One activation email lets the recipient choose a password and verifies that email when activation succeeds.
- **Direct activation:** admin supplies name, email, role, password, and confirmation. The account is immediately verified and usable; no activation email is sent and passwords are never emailed.

Agreed lifecycle: suspend, reactivate, and soft delete accounts. Preserve media and scheduled work. Administrators cannot suspend/delete themselves, and at least one usable administrator must remain.

Implementation decisions below were proposed as the designer's defaults and included in the approved document.

## Existing application and constraints

- Laravel 13, PHP 8.5, Fortify, Inertia v3, React 19, TypeScript, Wayfinder, PHPUnit, and existing UI components remain the foundation.
- `User` already supports verified email, hashed passwords, 2FA, and passkeys. Keep those flows working.
- `config/fortify.php` disables registration. `wahabuddy:admin` currently creates verified accounts but assigns no role.
- Routes currently share application-wide data and use `auth`/`verified`, with no role checks. This change adds authorization rather than per-user data ownership.
- `media_files.created_by` restricts deletion of its user; soft delete preserves the existing foreign key and assets.
- Read `.ai/rules/index.md` and relevant rules if that directory exists when implementing. Activate project authentication, Inertia, Wayfinder, and testing skills as relevant.
- Only the requested `spatie/laravel-permission` dependency may be added. Target compatible v8 (`^8.0`), confirm with Composer on the actual PHP runtime before relying on its APIs, and commit its lockfile. Do not upgrade unrelated packages.
- No PHP executable is currently available in the inspected shell. PHP checks, Artisan route discovery, dependency resolution, database inspection, and tests must run in a suitable PHP 8.5 environment during implementation; none are claimed to have run for this design.

## Approaches considered

1. **Selected: one dedicated activation email.** A one-use activation token allows password creation and email verification in the same submission, without logging in first. Requires a small dedicated flow but avoids two emails and does not change ordinary Fortify password reset semantics.
2. **Not selected: password reset followed by ordinary verification.** Reuses more existing endpoints, but requires creating a password, logging in, and then using a second verification email.

For deletion, permanent deletion would require changing media ownership or deleting data. The owner selected soft delete instead.

## Roles and permission boundaries

Spatie `HasRoles` on `User`; one role per account through application validation. Use guard `web`, without teams, wildcard grants, or direct user permissions.

| Capability | admin | user |
| --- | --- | --- |
| Dashboard, Stories, Broadcasts, Contacts, media operations | Yes | Yes |
| Own profile, password, 2FA, passkeys, appearance, logout | Yes | Yes |
| Application Settings, WAHA, storage, preferences, cleanup | Yes | No |
| Account list, create, assign role, resend invitation, suspend, reactivate, delete | Yes | No |
| Public account registration | No | No |

Seed fixed permissions `settings.manage` and `users.manage`, granted only to `admin`. No role/permission editor is included. Both roles use existing shared operational data; no tenancy or ownership isolation is introduced.

Enforce capabilities on every relevant server route, including direct HTTP mutations; hiding menu items is additional UX. Gate all routes currently declared under application `/settings` in `routes/web.php` by `settings.manage`. Personal settings in `routes/settings.php` remain accessible to active users. The existing scheduler action remains an operational capability available to both roles.

Share explicit capability booleans and role name with Inertia, without raw role pivots, tokens, or credentials. Settings and Accounts navigation is visible only when permitted. Do not leak application configuration to regular users via new account props.

## Account state and storage

Add nullable `suspended_at`, `deleted_at`, and `activated_at` timestamps to users; add `SoftDeletes`. Backfill `activated_at` for all pre-existing accounts, including those awaiting ordinary email verification. Invitations have `activated_at = null`; direct accounts set it immediately. Keep `email_verified_at` for its existing email verification meaning. Add hidden unsigned integer `auth_version`, default 1, for durable session revocation across all session drivers.

Precedence for displayed status: suspended, invited (`activated_at` null), awaiting verification, active. Deleted accounts are omitted. Suspending an invited account is allowed; it cannot activate while suspended. Reactivation only removes suspension; it does not verify email or activate an invitation.

Add nullable hidden fields `activation_token_hash` (64 hex characters), `activation_expires_at`, and `invitation_sent_at` on users. Store only a SHA-256 digest of a cryptographically random 64-character token. The plaintext token exists only while constructing/sending its email. No token in account list props, application log messages, or flash data.

Email addresses are trimmed and lowercased on admin creation and checked against all user rows, including soft-deleted rows; retain the existing unique index. A deleted email cannot be reused through this feature. Do not introduce admin email/name editing, arbitrary password resets of existing accounts, restore, force delete, audit-log UI, or bulk account mutations in this scope. Role can be changed for existing accounts.

## Invitation and activation

Designer default: activation lifetime is **24 hours**, represented by `config/wahabuddy.php` key `accounts.activation_expire_minutes = 1440`. Use a named activation route, actual `APP_URL`, and HTTPS in production.

Admin creation is transactional: validate input, persist user with an unguessable hashed placeholder password and `activated_at = null`, assign exactly the selected role, generate activation fields, then send an activation notification after commit using the configured mailer. No `Registered` event that also sends a second verification email.

Activation GET renders a dedicated Inertia page containing the invited email as read-only text and password/confirmation fields. It never activates, verifies, logs in, or consumes a token; mail scanner GETs must be harmless. The email and role are derived from the identified account, never from submitted replacement values.

Activation POST validates the existing `PasswordValidationRules`, then locks the user row and atomically rechecks the token hash, expiry, pending state, suspension, and deletion. On success: store hashed password, set `activated_at` and `email_verified_at`, clear activation fields and remember token, invalidate existing password reset tokens, and dispatch `Verified` exactly once after commit. Redirect to login with a success message; no automatic login, preserving ordinary 2FA/passkey behavior.

Expired, tampered, already consumed, superseded, suspended, or deleted invitations produce the same actionable error page/message without changing any account. Concurrent submissions must have one winner. Link GET/POST are available without an authenticated session, with CSRF on POST, and are outside login/suspension middleware so unrelated signed-in users can open an invitation without changing their own session.

Admin may resend invitations for pending, non-suspended, non-deleted accounts. Resend rotates the token, invalidates the previous link, and resets the 24-hour expiry. Throttle resend to one per target per 60 seconds plus five attempts per admin per minute; activation POST to ten attempts per IP per minute; admin mutations to twenty per admin per minute.

Mail delivery is synchronous after commit for this first version, avoiding a new worker dependency. Clear `invitation_sent_at` whenever rotating a token. A mailer exception preserves the pending account, records no successful `invitation_sent_at`, and returns a clear warning plus resend action. Rotation remains effective if delivery fails. When the mail transport accepts a notification, set `invitation_sent_at` only if the token hash still matches that notification; this indicates acceptance, not confirmed inbox delivery. Production mailer and sender must actually deliver mail; the repository default `log` mailer cannot do that.

Direct activation validates password confirmation and password policy, creates an already verified/activated account with its selected role, and leaves no invitation token. Role or permission input outside `admin`/`user` is rejected.

## Suspend, delete, and authentication

Suspend and delete increment `auth_version` transactionally and invalidate remember tokens, pending activation tokens, and password reset tokens. Delete session rows as an additional cleanup only when the configured session driver is database. An authentication Login event listener stores the user's current `auth_version` in the session as `auth_version`; middleware compares it with the current database value on every authenticated request, both before and after the downstream response. A missing or mismatched version logs the user out, so pre-deployment sessions must log in again once. This also prevents old Redis/file/cookie sessions from becoming usable after reactivation. Suspension preserves role, password, 2FA, passkeys, and application data. Deletion additionally soft deletes the user. Reactivation never revives previously invalidated tokens or cookies; a formerly pending user needs a new invitation.

An application web middleware checks the current database account state on every request, including personal settings and Fortify vendor endpoints. Block pending activation, suspended, and deleted users regardless of password, 2FA completion, passkey login, remember-me cookies, or an already authenticated session. A successful authentication that occurs inside a controller must also be checked before its response leaves the middleware, so a passkey or 2FA completion cannot bypass the rule. Log out the web guard, invalidate the session and rotate its CSRF token, then return an appropriate login/error response without passing protected response content through. Guests can still access login, recovery and activation. Logout remains usable. Follow normal Inertia/XHR response conventions.

Password recovery must not activate pending accounts, unsuspend users, or restore deleted accounts. Retain ordinary verified-email flows for already activated users who change their own email; that path does not use invitation tokens. Role checks are performed fresh per request so demotion removes admin access on the next request.

## Administrator and data preservation invariants

Reject self-suspend, self-delete, and self-demotion with a validation error. Reject suspension, deletion, or demotion of the last usable admin, defined as not deleted, not suspended, activated, verified, and assigned `admin`. A pending admin invitation does not count.

Serialize admin lifecycle/role operations using a transaction and a common lock on the seeded `admin` role row, followed by reloading actor and target and counting usable admins under that lock. All admin-affecting mutation paths, including CLI/bootstrap and personal self-delete, must participate. Use a consistent lock order (role mutex, then user rows by ascending id); cover the race in MySQL integration tests, not just SQLite.

The personal-profile delete route must also use the lifecycle protection; an admin cannot bypass protections there. Ordinary users retain password-confirmed personal deletion, implemented as soft delete. Block admin self-email changes that would leave no usable verified admin; allow them when another usable admin exists. Normal verification after an allowed email change remains required.

Existing stories/broadcasts/queued jobs continue after suspend/delete: the owner chose to preserve scheduled work, and these records belong to the shared installation. Media attribution resolves soft-deleted creators where needed without exposing their account to login/list operations. Do not remove media, queued jobs, 2FA keys, or passkeys as a side effect of suspension.

## Bootstrap and production transition

Seed role/permission definitions idempotently. Add a dedicated bootstrap command accepting `--admin-email` which explicitly selects an existing, active, verified account; backfill only role-less accounts as `user` and grant `admin` to the selected account. It must fail without partial changes for an unknown/ineligible email. Rerunning must not reset passwords, verify an unverified account, or demote other admins.

Do not promote every existing account automatically or embed the owner's email in migrations/seeds. Deployment operator supplies the actual email. Update `wahabuddy:admin` to assign the admin role while retaining its current password-preservation contract; reject existing suspended, deleted, pending-activation, or unverified matches with an actionable error, even with `--reset-password`, rather than implicitly restoring/activating/verifying them. New CLI-created accounts remain activated and verified. Document bootstrap and smoke-check steps in the requested implementation plan, without running production operations.

Deploy role schema, state schema, and role seeds before bootstrapping, then activate permission enforcement in the same maintenance/deployment window to avoid owner lockout. Permission enforcement denies role-less users administrative access. Account rollback cannot blindly drop soft-delete/token columns and thereby reactivate suspended/deleted/pending accounts; treat rollback as an explicit data-aware operation.

## User-facing contract

Designer proposal: Accounts is an admin-only navigation destination at `/users`, using the app layout and existing Table, Badge, Select, Dialog, AlertDialog, Input, Button and toast patterns. List name, email, role, status, created time, invitation delivery time and expiry for pending accounts; paginate 25 rows, search name/email, filter role/status, and keep filters during mutations.

Create supports invitation (default) or direct activation. Invite mode has no password field. Direct mode requires password and confirmation and clearly explains that email verification is being set by the admin. Existing account actions include changing role, resend where eligible, suspend/reactivate, and delete with confirmation; protected actions are disabled with a reason as well as validated server-side. No raw hashes/tokens/passwords are returned.

Activation uses the existing auth layout with invited email, password/confirmation, server validation, submitting state, expiry/invalid-link guidance, and a link back to login. Match existing English product copy, dark mode and accessible label/keyboard patterns. Detailed component styling is left to the implementer; mockups are optional and are not a prerequisite for backend design review.

## Verification and acceptance

Use existing PHPUnit feature-test conventions and factories; explicitly create `admin` or `user` fixtures rather than making every factory user an admin. Update affected Settings/Storage tests for actual roles without weakening user-denial assertions. No new testing dependencies.

Acceptance covers: admin/user/guest access for every settings/user route and HTTP method; both creation modes; notification contents/count; invalid/expired/reused/rotated tokens; concurrent activation; mail failure and resend; input validation and hidden props; normal user password resets and email changes; suspended/deleted/pending sessions, password, passkey and 2FA paths; remember cookies; retained stale sessions after suspension/reactivation using database and cookie drivers; pre-deployment sessions without a version; preserved media/jobs; self and last-admin protection including personal settings/CLI and MySQL races; bootstrap reruns and invalid emails; CLI rejection of all ineligible existing states; GET/POST registration remaining unavailable.

Run narrow affected tests, then related auth/settings/media suites; run MySQL race coverage, Pint on changed PHP, PHPStan, JS check/types and production build. A functional smoke check covers both invitation and direct modes with a test mailbox, accessible dialogs, mobile layout, and loss of access immediately after suspension/demotion.

## References checked

- [Spatie compatibility](https://spatie.be/docs/laravel-permission/v8/prerequisites): v7/v8 support Laravel 12/13 with PHP 8.3+.
- [Spatie installation](https://spatie.be/docs/laravel-permission/v8/installation-laravel): package setup, published config/schema, HasRoles.
- [Laravel 13 verification](https://laravel.com/framework/docs/13.x/verification): existing signed verification and Verified event semantics.
- [Laravel 13 Fortify](https://laravel.com/framework/docs/13.x/fortify): retain existing authentication and recovery flows.
