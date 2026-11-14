# Account Management and Access Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add administrator-managed accounts with invitations, direct activation, admin/user roles, suspension, reactivation, and soft deletion while keeping registration closed.

**Architecture:** Use Spatie permissions for administrative boundaries and the existing Fortify authentication stack. Focused account actions share a transactional administrator guard; a dedicated activation token flow verifies email and sets the password in one submission. A session-version middleware makes suspension durable across session drivers, while existing operational data and sending jobs stay shared.

**Tech Stack:** PHP 8.5; Laravel 13 (installed metadata: 13.39.0); Fortify 1.41.0; Inertia Laravel 3.6.0 / React v3; React 19; TypeScript; Wayfinder; Spatie Laravel Permission `^8.0` (new); PHPUnit 12.5.42; MySQL 8.4; existing Radix UI components.

**Spec:** `docs/superpowers/specs/2026-11-13-account-management-design.md` (owner approved).

## Global Constraints

- Two roles are fixed: `admin` and `user`. Public registration remains disabled.
- Use guard `web`, without teams, wildcard grants, or direct user permissions.
- Seed fixed permissions `settings.manage` and `users.manage`, granted only to `admin`.
- Both roles use existing shared operational data; no tenancy or ownership isolation is introduced.
- Activation lifetime is **24 hours**: `accounts.activation_expire_minutes = 1440` in `config/wahabuddy.php`.
- Suspend, reactivate, and soft delete accounts. Preserve media and scheduled work.
- Administrators cannot suspend/delete themselves, and at least one usable administrator must remain. Also reject self-demotion.
- Personal profile, security, 2FA, passkeys, appearance, and logout remain available to active ordinary users.
- Only the requested `spatie/laravel-permission` dependency may be added. Do not upgrade unrelated packages or add testing dependencies.
- Use named routes/Wayfinder, existing directory conventions, English product copy, existing dark mode and accessible components.
- Read project `.ai/rules` if present and activate project skills for Laravel/Fortify, Inertia React, Wayfinder and testing as applicable. Create PHP files through Artisan with `--no-interaction`; tests through `make:test --phpunit`, without `Feature/` in the name.
- For any changed PHP, run `vendor/bin/pint --dirty --format agent` before finalizing.
- PHP is unavailable in the planning shell. No dependency installation, migrations, application changes, runtime tests, or production operations were performed when writing this plan.

## Review Focus

- **Login completion after middleware entry:** passkey, 2FA, or remembered authentication must not release a protected response for a pending/suspended/deleted account. Task 4 tests both middleware phases and real Fortify routes.
- **Stale sessions after reactivation:** database and cookie sessions must retain their revoked version and remain unusable. Task 4 tests both drivers and a session lacking a version.
- **Concurrent administrators:** mutual suspension/demotion, personal email changes, CLI promotion and activation must not leave zero usable admins. Tasks 1–3 use a shared lock; Task 2 tests conflicting mutations against MySQL.
- **Delivery failure or overlapping resends:** retain one pending account; never report success for an obsolete token or reactivate an expired link. Task 3 covers mail failures, token rotation, scanner GETs, and competing activations.
- **Soft-deleted email/creator:** creation must reject reuse of the email while media attribution and scheduled sending remain intact. Tasks 2–3 test uniqueness, relationships, and existing scheduler behavior.

---

## Preparation and file boundaries

Run execution in an isolated checkout/worktree following `superpowers:using-git-worktrees`. The account lifecycle, invitations, and authorization are one cohesive feature: execute tasks in order and release the complete set together.

Confirm actual versions with `composer show --direct` and package-specific `composer show`, `package.json`, and `php artisan route:list --except-vendor` / `--only-vendor`. Use Boost `search-docs` and `database-schema` if available; otherwise read installed framework/package code and official version-matched docs/schema before API-dependent changes. Known planning references are listed in the approved spec. Confirm PHP 8.5 and MySQL test access; do not silently skip race coverage. Also keep compatibility with the repository's PHP 8.4 CI job when resolving the requested dependency.

The following files are the ownership map. New subdirectories stay inside existing `app`, `resources/js`, `database`, and `tests` roots:

| Responsibility                          | Files                                                                                                                                                                                                                                                                                                                                                           |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dependency/schema/roles                 | `composer.json`, `composer.lock`, `config/permission.php`, `database/migrations/<generated>_create_permission_tables.php`, `database/migrations/<generated>_add_account_management_fields_to_users_table.php`, `database/seeders/RolesAndPermissionsSeeder.php`                                                                                                 |
| State, fixtures, shared lock, bootstrap | `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Services/Accounts/AdminAccountGuard.php`, `app/Console/Commands/BootstrapAccountAccess.php`, `app/Console/Commands/CreateAdmin.php`                                                                                                                                                           |
| Lifecycle and existing profile paths    | `app/Actions/Accounts/ManageAccount.php`, `app/Http/Controllers/UsersController.php`, `app/Http/Requests/UpdateUserRoleRequest.php`, `app/Http/Controllers/Settings/ProfileController.php`, `app/Models/MediaFile.php`                                                                                                                                          |
| Invitations and activation              | `app/Actions/Accounts/CreateAccount.php`, `app/Actions/Accounts/ActivateAccount.php`, `app/Services/Accounts/AccountInvitations.php`, `app/Notifications/AccountActivation.php`, `app/Http/Controllers/Auth/AccountActivationController.php`, `app/Http/Requests/StoreUserRequest.php`, `app/Http/Requests/ActivateAccountRequest.php`, `config/wahabuddy.php`  |
| Session enforcement                     | `app/Listeners/StampAuthenticationVersion.php`, `app/Http/Middleware/EnsureAccountIsActive.php`, `bootstrap/app.php`, `app/Actions/Fortify/ResetUserPassword.php`, `tests/TestCase.php`                                                                                                                                                                         |
| Route authorization/list data           | `routes/web.php`, `routes/settings.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `config/fortify.php`                                                                                                                                                                                                          |
| Account UI and typed navigation         | `resources/js/pages/users/index.tsx`, `resources/js/pages/users/components/create-user-dialog.tsx`, `resources/js/pages/users/components/user-actions.tsx`, `resources/js/pages/auth/activate-account.tsx`, `resources/js/types/accounts.ts`, `resources/js/types/auth.ts`, `resources/js/components/app-sidebar.tsx`, `resources/js/components/app-header.tsx` |
| Regression coverage                     | Exact test files are listed in each task below. Update existing tests only where the new roles/session contract affects their setup or assertions.                                                                                                                                                                                                              |

Use actual Artisan-generated migration timestamps rather than fixed dates. Never hand edit generated Wayfinder output.

### Task 1: Roles, account state, and deliberate administrator bootstrap

**Files:** Dependency/schema/roles and state/bootstrap rows above; tests `tests/Feature/AccountAccessBootstrapTest.php`, `tests/Feature/AccountStateTest.php`, existing `tests/Feature/AdminAccessTest.php`.

**Interfaces:**

- `User::isActivated(): bool`, `User::isSuspended(): bool`, `User::isUsableAdministrator(): bool`, `User::accountStatus(): string` (`suspended|invited|awaiting_verification|active`). Soft-deleted users remain excluded by the default query scope.
- `UserFactory::admin(): static`, `regularUser(): static`, `invited(): static`, `suspended(): static`. Default factory users are activated/verified, not administrators; explicit role states initialize role definitions and assign exactly one role after creation.
- `AdminAccountGuard::lock(): Role` locks the seeded `admin` row and **requires an open transaction**. All callers lock it first, then any affected user rows by ascending id. Refresh role relations after locking; do not trust request-bound user state.
- `BootstrapAccountAccess` command: `wahabuddy:access-bootstrap {--admin-email=}`; `handle(): int`.

- [ ] **Step 1: Install only the approved package and generate schema/test scaffolding.**

Run `composer require 'spatie/laravel-permission:^8.0' --no-interaction`, then `composer show spatie/laravel-permission`. Check the diff for unrelated updates. Publish config/migration with the installed provider's documented command and `--no-interaction`; inspect schema/index compatibility with MySQL. Generate the account-fields migration, seeder, classes, command, and PHPUnit feature tests using Artisan. Do not run against production.

- [ ] **Step 2: Write the failing state/bootstrap tests.**

Pin these contracts with named tests and behavioral assertions:

```php
// test_seed_is_idempotent_and_permissions_belong_only_to_admin
$this->seed(RolesAndPermissionsSeeder::class);
$this->seed(RolesAndPermissionsSeeder::class);
$this->assertSame(2, Role::where('guard_name', 'web')->count());
$this->assertTrue($admin->can('users.manage'));
$this->assertFalse($regular->can('settings.manage'));

// test_bootstrap_promotes_only_selected_account_without_changing_password
$hash = $selected->password;
$this->artisan('wahabuddy:access-bootstrap', ['--admin-email' => $selected->email])->assertSuccessful();
$this->assertTrue($selected->fresh()->hasExactRoles(['admin']));
$this->assertTrue($other->fresh()->hasExactRoles(['user']));
$this->assertSame($hash, $selected->fresh()->password);
```

Construct fixtures before the assertions. Also test reruns preserving other admins, missing/unknown/pending/suspended/deleted/unverified bootstrap targets failing without partial grants, status precedence, and `User::toArray()` excluding activation secrets/auth version. Extend `AdminAccessTest` to assert the existing admin CLI assigns admin without resetting passwords, creates activated/verified accounts, and rejects every ineligible existing state even with `--reset-password`. Add migration coverage using a pre-migration user to prove `activated_at` backfill includes unverified legacy accounts.

- [ ] **Step 3: Run the new tests and confirm behavior fails.**

Run `php artisan test --compact tests/Feature/AccountAccessBootstrapTest.php tests/Feature/AccountStateTest.php tests/Feature/AdminAccessTest.php`. Expected failures: missing role/state/bootstrap behavior, not broken test syntax.

- [ ] **Step 4: Implement the schema, state, seed, and bootstrap interfaces.**

Add `HasRoles` and `SoftDeletes`. Schema: nullable `suspended_at`, `deleted_at`, `activated_at`, `activation_expires_at`, `invitation_sent_at`; nullable string `activation_token_hash` length 64; unsigned integer `auth_version` default 1. Cast timestamps/version, hide token hash and auth version, and retain existing hidden credentials. Backfill legacy `activated_at` using an existing timestamp or migration time without verifying email.

Seed `admin`, `user`, `settings.manage`, `users.manage` with guard `web`; synchronize the exact admin permissions and no permissions for user, using package methods that reset cache. Do not assign roles in migrations. Bootstrap validates target eligibility first, then under the common lock fills only role-less live users with `user` and synchronizes the selected account to `admin`. Preserve all other roles/passwords/verification. Keep the admin CLI's prompts and password contract, adding the same locked promotion and eligibility checks. Public registration stays disabled.

- [ ] **Step 5: Rerun Step 3; all tests must pass. Review and commit.**

Run Pint on dirty PHP. Stage only this task's files and commit `feat: add account roles and administrator bootstrap`.

### Task 2: Account lifecycle, preserved data, and administrator invariants

**Files:** Lifecycle row above; `routes/web.php` (lifecycle routes), `routes/settings.php` if needed; tests `tests/Feature/UserLifecycleTest.php`, `tests/Integration/AccountConcurrencyTest.php`, existing `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/MediaTest.php`.

**Interfaces:**

- Consumes Task 1 state, factory roles, `AdminAccountGuard::lock()`.
- Guard additions: `assertAdministrator(User $actor): void`, `assertMayDeactivate(User $actor, User $target): void`, `assertMayChangeEmail(User $user, string $email): void`; reject unauthorized actors with 403, protected operations with a `ValidationException` keyed `account` (or `email` for profile email changes).
- `ManageAccount::suspend(User $actor, User $target): void`, `reactivate(User $actor, User $target): void`, `changeRole(User $actor, User $target, string $role): void`, `delete(User $actor, User $target): void`, `deleteOwnAccount(User $user): void`, `updateOwnProfile(User $user, array $attributes): void`. PHPDoc profile array shape is `{name: string, email: string}`.
- `UsersController::suspend(Request $request, User $user): RedirectResponse`, `reactivate(...)`, `destroy(...)`, `updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse`.
- Named endpoints: `PATCH /users/{user}/role` → `users.role.update`, `POST /users/{user}/suspend` → `users.suspend`, `POST /users/{user}/reactivate` → `users.reactivate`, `DELETE /users/{user}` → `users.destroy`; `auth`, `verified`, `can:users.manage`, and mutation throttling. No binding to trashed users.

- [ ] **Step 1: Write lifecycle and personal-profile failing tests.**

```php
// test_deleting_another_user_preserves_media_and_attribution
$this->actingAs($admin)->delete(route('users.destroy', $target))->assertRedirect();
$this->assertSoftDeleted('users', ['id' => $target->id]);
$this->assertDatabaseHas('media_files', ['id' => $media->id, 'created_by' => $target->id]);
$this->assertSame($target->id, $media->fresh()->uploader->id);

// test_suspend_revokes_credentials_without_removing_account_data
$this->actingAs($admin)->post(route('users.suspend', $target))->assertRedirect();
$this->assertSame($oldVersion + 1, $target->fresh()->auth_version);
$this->assertNull($target->fresh()->remember_token);
$this->assertNull($target->fresh()->activation_token_hash);
```

Add assertions for password reset token deletion, database-session cleanup (only for database driver), and preserved password hash/role/passkeys/2FA. Test suspension/reactivation of a pending account (no activation or verification), all self operations including self-demotion, last usable admin versus pending/suspended/unverified admins, ordinary user/guest denial, invalid/multiple roles, deleted targets, and the personal deletion route. Update personal deletion assertions to `assertSoftDeleted` rather than expecting Eloquent `fresh()` to return null for an existing model. Verify last-admin email changes fail, another usable admin permits the change and clears verification, and normalized unchanged email preserves verification.

Build committed MySQL fixtures in `AccountConcurrencyTest` using the existing `DatabaseMigrations` and Symfony Process pattern. Two workers acting as A/B attempt to deactivate or demote each other; only one succeeds and at least one verified/activated/non-suspended admin remains. Cover conflicting personal email changes as well. Use a barrier (locked row/start signal), not arbitrary sleeps as proof of overlap; workers call real actions via `php -r` with bootstrap code passed as a Process argument. No new public test-only Artisan command or production endpoint.

- [ ] **Step 2: Run the failing tests.**

Run `php artisan test --compact tests/Feature/UserLifecycleTest.php tests/Feature/Settings/ProfileUpdateTest.php tests/Integration/AccountConcurrencyTest.php`. Expected failure: lifecycle endpoints/actions/protection absent. The integration suite must actually use MySQL and report passing assertions, not skips.

- [ ] **Step 3: Implement lifecycle and profile safeguards.**

Each action opens a transaction, acquires the common role lock, locks/reloads actor and target in id order, rechecks actor capability/state and target protection, then mutates. A usable admin is activated, verified, unsuspended, nondeleted and assigned admin. Self-protection applies even if other admins exist. Role updates synchronize one allow-listed role. Repeated suspend/reactivate requests are harmless; only state transitions revoke credentials/increment version. Ordinary self-delete is allowed after existing password validation; admin self-delete remains denied.

Share one revocation routine inside `ManageAccount` for suspend/delete. Rotate or clear remember token, clear activation fields, clear broker reset tokens, increment auth version, and conditionally delete configured database session rows. Do not remove passkeys, 2FA, media, jobs or operational records. `MediaFile::uploader()` resolves `withTrashed()`. Route profile updates/deletions through the same lock/protection. Preserve existing logout/redirect behavior after ordinary self-delete.

Define `account-mutations` limiter (20/minute per actor) in `AppServiceProvider` now so new routes do not depend on an undefined limiter. Use the existing flash toast convention and redirect back to the originating list URL/query after success. Match existing profile password validation.

- [ ] **Step 4: Rerun Step 2 plus relevant media/scheduler regression coverage.**

Run the targeted preservation tests in `MediaTest.php` and `StorySchedulerTest.php`; existing queued work must still send after deleting its media uploader, with outbound WAHA faked. Add this distinct case to `UserLifecycleTest` if existing fixtures do not cover it. Run Pint and commit `feat: manage account lifecycle and protect administrators`.

### Task 3: Creation modes and single-use email activation

**Files:** Invitations/activation row above; `UsersController` create/resend methods; `routes/web.php`; extend `AccountConcurrencyTest`; tests `tests/Feature/UserCreationTest.php`, `tests/Feature/AccountActivationTest.php`, `tests/Feature/InvitationDeliveryTest.php`.

**Interfaces:**

- `CreateAccount::handle(User $actor, array $data): array` returns PHPDoc shape `{user: User, invitation_sent: bool|null}`; input shape `{name: string, email: string, role: 'admin'|'user', mode: 'invite'|'direct', password?: string, password_confirmation?: string}`.
- `AccountInvitations::prepareLocked(User $user): string` rotates fields and returns plaintext token; requires the common lock and a locked pending user inside a transaction. `deliver(User $user, string $token): bool` sends after commit and conditionally records acceptance for that hash. `resend(User $actor, User $target): bool` wraps authorization, locks, rotation/commit and delivery. `findValid(int $userId, string $token): ?User` is a read-only eligibility check for GET.
- `AccountActivation::__construct(string $activationUrl)` and `toMail(object $notifiable): MailMessage`, delivered synchronously; no `ShouldQueue`. Never include password or token hash.
- `ActivateAccount::handle(int $userId, string $token, string $password): void` rechecks under locks, verifies/activates and consumes once. Throws one generic invitation validation error keyed `invitation`.
- `UsersController::store(StoreUserRequest $request): RedirectResponse`, `resendInvitation(Request $request, User $user): RedirectResponse`.
- `AccountActivationController::show(Request $request, int $user, string $token): Response`, `store(ActivateAccountRequest $request, int $user, string $token): RedirectResponse`.
- Routes: `POST /users` → `users.store`; `POST /users/{user}/invitation` → `users.invitation.resend` (admin only). Public `GET /activate-account/{user}/{token}` → `account.activation.show`; `POST` same path → `account.activation.store`. Use numeric id parameters, no automatic model binding for activation, and **no guest-only middleware**, so a different signed-in account can open it safely.

- [ ] **Step 1: Write creation, notification and activation failing tests.**

```php
// test_invitation_sends_one_email_and_stores_only_digest
Notification::fake();
$this->actingAs($admin)->post(route('users.store'), $inviteInput)->assertRedirect();
$user = User::where('email', 'invitee@example.com')->firstOrFail();
$this->assertNull($user->activated_at);
$this->assertNull($user->email_verified_at);
$this->assertSame(64, strlen($user->activation_token_hash));
Notification::assertSentToTimes($user, AccountActivation::class, 1);
Notification::assertNotSentTo($user, VerifyEmail::class);

// test_valid_invitation_sets_password_and_verifies_once
$this->post($url, ['password' => $password, 'password_confirmation' => $password])->assertRedirect(route('login'));
$this->assertTrue(Hash::check($password, $user->fresh()->password));
$this->assertNotNull($user->fresh()->activated_at);
$this->assertTrue($user->fresh()->hasVerifiedEmail());
$this->assertNull($user->fresh()->activation_token_hash);
$this->assertGuest();
Event::assertDispatchedTimes(Verified::class, 1);
```

Capture the raw token from the faked activation notification URL, assert digest equality and exact 1440-minute expiry with frozen time. GET must not change user state; GET then POST must work without login. Test malformed, missing-user, expired (including exact expiry boundary), reused, rotated, suspended, deleted and active-account tokens returning the same invalid-link outcome; password/confirmation failures leave token usable. At `now() >= activation_expires_at`, reject. Submit altered email/role fields and assert stored identity/role wins. An unrelated logged-in user's session/identity must be preserved.

Direct mode tests assert hashed password, verification/activation timestamps, one selected role, no activation fields, no notifications. Validate required/confirmed password via existing policy, duplicate email including soft-deleted and case/whitespace variants, invalid/multiple role/mode values, unexpected permission payload not granting anything, and invite mode rejecting supplied password fields. Add competing creates for the same normalized email to the MySQL integration tests: one account/notification only; the loser receives an email validation error rather than a SQL exception.

Delivery tests throw a transport exception and assert the pending account remains, `invitation_sent_at` is null, warning toast is shown, and resend succeeds after cooldown. Freeze/travel time for per-target 60-second cooldown, per-admin five/minute resend limit and twenty/minute mutation limit. A stale delivery must not mark acceptance after a newer token rotation. Add two competing activation workers to the MySQL test: one wins, one is rejected, final password matches winner, one verification event is observed by a test-only listener in worker output.

- [ ] **Step 2: Run the tests and confirm new behavior fails.**

Run `php artisan test --compact tests/Feature/UserCreationTest.php tests/Feature/AccountActivationTest.php tests/Feature/InvitationDeliveryTest.php tests/Integration/AccountConcurrencyTest.php`.

- [ ] **Step 3: Implement creation, delivery and activation interfaces.**

Normalize email before validation; uniqueness includes trashed users. Use existing profile/password rule concerns, explicit validated keys, and conditional required/prohibited rules for mode-specific password fields. Create both modes under the common lock; invitation creates hashed random placeholder password plus token digest in the same transaction. Commit before mail. Direct mode sets verification/activation and skips sending. Do not dispatch `Registered` to avoid a second email.

Generate token with cryptographic randomness; compare `hash('sha256', $token)` using `hash_equals`. Send a named-route URL via the existing configured notification mailer after commit. Catch delivery failure without undoing account creation, return a clear warning and permit subsequent resend. Both success timestamp updates and resend rotation must respect the current token hash. Resend authorization/state check and rotation occur under the shared lock and target-row lock. Define `account-invitations` limits: 1/60s per target plus 5/minute per actor. Apply both resend and `account-mutations` limits. Define activation POST limit 10/minute per IP.

Activation GET returns `auth/activate-account` props `{ valid: boolean, email: string|null, passwordRules: string|null, activation: {user: number, token: string}|null }`; return no email or activation parameters for invalid links. The valid token is already in this page's URL and is used only in this page's generated form action, never global/list props. Activation POST uses existing password validation, locks common role row then user, rechecks all eligibility/expiry/hash conditions, updates password/activated/email-verified timestamps, clears activation and remember/reset tokens, and emits `Verified` once after commit. Persist all changes atomically; generic validation failure leaves state unchanged. Register activation routes outside Task 4's state middleware exceptions while retaining the web stack/CSRF. Clear expired/consumed invitation data from responses and flash inputs: password fields must not be flashed.

- [ ] **Step 4: Rerun Step 2, run Pint, and commit.**

Expected: all feature cases and actual MySQL competing activation checks pass. Commit `feat: invite and activate managed accounts`.

### Task 4: Enforce account state across sessions and Fortify entry points

**Files:** Session enforcement row above; existing `tests/Feature/Auth/AuthenticationTest.php`, `TwoFactorChallengeTest.php`, `PasswordResetTest.php`; new `tests/Feature/AccountSessionAccessTest.php`, `tests/Feature/PasskeyAccountAccessTest.php`.

**Interfaces:**

- `StampAuthenticationVersion::handle(Login $event): void` stamps session `auth_version` from the authenticated model's version (not a later unconditionally refreshed version that could bless an earlier login racing revocation).
- `EnsureAccountIsActive::handle(Request $request, Closure $next): Symfony\Component\HttpFoundation\Response` checks fresh DB state/version before downstream handling and again after it returns. Valid means nondeleted, unsuspended, activated and matching session version; ordinary email verification remains separate.
- Test helper `Tests\TestCase::actingAsActiveAccount(User $user): static` sets the real session version then uses normal authentication. Do not override `actingAs`, disable middleware, auto-stamp missing versions in production, or auto-make fixtures admin.

- [ ] **Step 1: Write account state/session failing tests against real routes.**

```php
// test_saved_cookie_session_cannot_revive_after_suspend_and_reactivate
$oldVersion = $user->auth_version;
$manage->suspend($admin, $user);
$manage->reactivate($admin, $user->fresh());
$this->actingAs($user->fresh())->withSession(['auth_version' => $oldVersion])
    ->get(route('dashboard'))->assertRedirect(route('login'));
$this->assertGuest();

// test_missing_version_is_not_silently_trusted
$this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));
$this->assertGuest();
```

Parameterize protected requests over pending/suspended/deleted states and dashboard, profile, personal security, settings, and representative Fortify mutation endpoints. Test real password login and 2FA POST denial, passkey completion denial, and remembered login denial. Use actual signed WebAuthn fixtures or mock only the credential-verification boundary; exercise Fortify's real route/controller/session event path. Keep challenge/credential data valid so account state is the cause of denial, not an unrelated validation failure.

Test a controller that authenticates during downstream handling to prove the second middleware phase rejects before releasing content; register that route only inside the test. An already blocked session must not execute a protected mutation. Driver coverage uses database and cookie, preserving old cookies/session payload across suspend/reactivate; explicitly assert the configured driver. Cover an account-version increment during downstream handling, a legacy session without version, valid login stamping version, and fresh login after reactivation succeeding. Reset password may change only password, never `activated_at`, `email_verified_at`, `suspended_at`, or deletion state; pending accounts remain unable to log in after reset.

- [ ] **Step 2: Run focused tests and confirm the state checks fail.**

Run `php artisan test --compact tests/Feature/AccountSessionAccessTest.php tests/Feature/PasskeyAccountAccessTest.php tests/Feature/Auth`.

- [ ] **Step 3: Implement the listener and middleware.**

Use Laravel's existing event discovery for `app/Listeners`; confirm listener registration with `php artisan event:list --no-interaction`. Append the account-state middleware in `bootstrap/app.php` after session/authentication are available and inside the Inertia response stack. Explicitly exclude the two named activation routes, not a broad auth URL prefix; allow guests and logout. Re-read the DB user (including deleted state) and permissions rather than a stale guard instance. For invalid state/version log out `web`, invalidate session, regenerate CSRF token, and return the normal HTML/Inertia login redirect; JSON requests receive 403 with a generic account-unavailable message, never the original protected response.

The listener stamps the authenticated model's version at Login events for password, 2FA, passkeys and remembered auth; middleware compares against current DB state after authentication to catch races. Do not recast ordinary unverified-but-activated users as invited; their existing verification/profile routes still work. Preserve activation exceptions and unrelated logged-in identity from Task 3.

Update existing authenticated feature fixtures to use `actingAsActiveAccount` where they represent a previously completed login. Authentication tests exercising real login must not pre-stamp. Keep Task 4 missing/mismatched-version tests on raw `actingAs`. Existing factories default `activated_at` to now so existing non-invitation cases retain meaning.

- [ ] **Step 4: Rerun Step 2 and affected lifecycle/activation tests.**

If any Fortify login path bypasses Login events, adapt that supported contract explicitly and add its test; never relax the middleware to trust missing stamps. Run Pint and commit `feat: enforce suspended and pending account access`.

### Task 5: Complete authorization, account list, and safe shared props

**Files:** Route authorization/list data row; `UsersController::index`; `tests/Feature/UserManagementAccessTest.php`, `tests/Feature/UserListTest.php`, existing `SettingsTest.php`, `StorageSettingsTest.php`, `AdminAccessTest.php` and any other tests calling settings endpoints.

**Interfaces:**

- `UsersController::index(Request $request): Inertia\Response` returns `users/index`.
- `GET /users` → `users.index` guarded by `auth`, `verified`, `can:users.manage`.
- Inertia shared `auth.role: 'admin'|'user'|null`; `auth.capabilities: { manageSettings: boolean, manageUsers: boolean }`. Preserve existing `auth.user` shape as needed without returning loaded roles/pivots or activation/session secrets.
- List props `{ users: { data: AccountRow[], links: PaginationLink[], ...existingPaginationMetadata }, filters: {search: string, role: string, status: string}, roles: ['admin','user'], passwordRules: string|null }`; rows contain only `id,name,email,role,status,created_at,invitation_sent_at,activation_expires_at,actions`. `actions` gives each lifecycle/role/resend boolean and a user-facing disabled reason.

- [ ] **Step 1: Write endpoint-matrix and list-prop failing tests.**

Read all `/settings` application routes from `route:list`; put every route/method plus every `/users` mutation in a data provider, supplying valid payloads/target fixtures. Assert ordinary verified users get 403 before side effects; guests redirect to login; admins pass authorization and hit the expected behavior. Assert own profile/security/appearance and operational routes remain usable for ordinary users. Keep explicit GET/POST `/register` 404 assertions active (the existing conditional RegistrationTest skips when disabled).

```php
// test_account_listing_is_paginated_filtered_and_sanitized
$this->actingAsActiveAccount($admin)->get(route('users.index', ['role' => 'user']))
    ->assertInertia(fn ($page) => $page->component('users/index')
        ->where('users.per_page', 25)
        ->where('filters.role', 'user')
        ->missing('users.data.0.password')
        ->missing('users.data.0.activation_token_hash')
        ->missing('users.data.0.auth_version')
        ->missing('users.data.0.roles'));
```

Also assert missing 2FA/recovery/remember credentials, no deleted accounts, name/email search, allow-listed role/status filters including awaiting verification, deterministic id-desc ordering, status precedence, escaped SQL-like search input, and combined filter/pagination links. Assert fresh permission booleans for admin/user/role-less/guest and removal after demotion. Profile requests must not allow injection of roles, permission, activation, suspension or verification fields.

- [ ] **Step 2: Run the targeted failing tests.**

Run `php artisan test --compact tests/Feature/UserManagementAccessTest.php tests/Feature/UserListTest.php tests/Feature/SettingsTest.php tests/Feature/StorageSettingsTest.php tests/Feature/AdminAccessTest.php`.

- [ ] **Step 3: Implement list and settings boundaries.**

Group only application settings routes from `routes/web.php` behind `can:settings.manage`, including connection test/secret removal, preferences, storage test/secret removal and cleanup. Preserve personal settings groups in `routes/settings.php`. Keep scheduler as operational. Reuse permission middleware without introducing a super-admin Gate bypass.

Build account list with explicit projections and eager-loaded roles, one row per user, 25/page with retained validated query filters and a bound/grouped name-or-email search. Map status using the state precedence, and shape responses explicitly rather than serializing whole Users. Use fresh invariant calculations for action availability; backend actions remain authoritative if state changes after rendering. Share only declared role/capabilities and sanitized existing user props. Avoid lazy loading role pivots into `auth.user` serialization.

Update existing Settings/Storage test `admin()` helpers to create explicit `admin()` factory state and use the authenticated session helper. Do not assign admin in global test setup. Update the outdated single-admin comment in `config/fortify.php` while leaving registration disabled.

- [ ] **Step 4: Rerun Step 2; run related profile/auth tests and Pint.**

Expected: authorized behavior passes and ordinary users are denied on every administrative method. Commit `feat: authorize settings and expose account administration`.

### Task 6: Accounts page, activation form, and permission-aware navigation

**Files:** UI/types row in the file map; `resources/js/types/index.ts` for exports if used; extend `UserListTest` / `AccountActivationTest` only for additional page-prop contracts.

**Interfaces:**

- Consume Task 5 `AccountRow`, `Auth` capabilities and pagination props; define exported TypeScript types `AccountRole`, `AccountStatus`, `AccountRow`, `AccountIndexProps` in `types/accounts.ts` matching those exact server keys.
- `UsersIndex(props: AccountIndexProps)` default export at `pages/users/index.tsx`.
- `CreateUserDialog({ passwordRules }: { passwordRules: string|null })` and `UserActions({ user }: { user: AccountRow })` named exports from their matching components.
- `ActivateAccount({ valid, email, passwordRules, activation }: {valid: boolean; email: string|null; passwordRules: string|null; activation: {user: number; token: string}|null})` default export; activation parameters belong only to this page, never shared auth/list props.

- [ ] **Step 1: Pin any remaining server prop decisions in existing page tests.**

Assert valid/invalid activation props and no secret fields; assert action availability/reason for self, last admin, invited and suspended users. Run the affected test file immediately after editing it. Do not add frontend testing libraries or implementation-mirroring component tests.

- [ ] **Step 2: Generate typed route helpers.**

Run `php artisan wayfinder:generate --with-form --no-interaction`; confirm imports for `users` and `account.activation`. Use generated helpers for links and forms; the valid activation page submits through `account.activation.store.form(activation)` with its page-local numeric user id and token. Keep tokens out of logs and persistent client storage. Invalid pages render no activation form.

- [ ] **Step 3: Implement the account list and dialogs.**

Use AppLayout, existing Table/Badge/Select/Dialog/AlertDialog/Input/Button/toasts. Render search, role/status filters, pagination, loading and empty states with the exact 25-row server contract. Keep query filters during successful mutations. Do not create a new design system.

Create form defaults to `mode=invite`, `role=user`. Invite mode hides/clears passwords; direct mode requires both password fields, renders policy guidance and explains immediate email verification. Never send stale hidden passwords after switching modes. Reset password fields when the dialog closes and after success; disable repeat submissions. Show server validation and delivery-warning toasts; do not show a sent-success message on transport failure.

Actions support role change, resend for pending live/non-suspended accounts, suspend/reactivate, delete with destructive confirmation and disabled reasons. Role changes use `admin|user` only. Preserve the selected row on validation errors and avoid optimistic destructive changes. No name/email edit, existing-password edit, restore, force-delete or role-editor controls.

- [ ] **Step 4: Implement activation and navigation.**

Use AuthLayout and the existing password field pattern. Valid activation shows read-only email, password/confirmation and submission state; invalid links show “This activation link is invalid or has expired. Contact an administrator for a new invitation.” with a login link. Success goes through the server redirect to login. Avoid third-party assets/referrers on the activation page.

Apply capability filtering to **both** `app-sidebar.tsx` and `app-header.tsx` (including mobile navigation). Settings uses `manageSettings`; Accounts uses `manageUsers`; operational navigation and the personal user menu remain. Add no admin-only links to guest pages.

- [ ] **Step 5: Run verification and smoke checks, then commit.**

Run `npm run check`, `npm run types:check`, `npm run build`; then `php artisan test --compact tests/Feature/UserListTest.php tests/Feature/AccountActivationTest.php tests/Feature/UserManagementAccessTest.php`. All must pass. In a nonproduction app with a test mailbox, exercise invite/direct creation, invalid/expired link, resend, role change, suspend/reactivate/delete, desktop/mobile dialogs, keyboard focus, errors, and navigation for both roles. Verify prefilled secret fields never persist and suspended accounts lose access on next request.

Commit `feat: add account management and activation interfaces`.

## Integrated verification and production handoff

These are final checks for the complete feature, not permission to operate production. Reuse adequate checks already run; rerun affected checks after any subsequent changes.

- [ ] Run `vendor/bin/pint --dirty --format agent` and inspect the final diff for unrelated formatting.
- [ ] Run `composer types:check`, `npm run check`, `npm run types:check`, `npm run build`.
- [ ] Run affected Feature suites and `php artisan test --compact tests/Integration/AccountConcurrencyTest.php` on MySQL. Run the existing full `php artisan test --compact` once because the session helper, User schema and authentication middleware touch all feature fixtures. Keep WAHA/notification delivery faked in automated tests. No pending skips are acceptable as concurrency proof.
- [ ] Check `php artisan route:list --except-vendor`, `php artisan route:list --only-vendor`, and `php artisan event:list --no-interaction`; confirm no public registration and no unguarded settings/user mutation.
- [ ] Review that public/token GETs are side-effect-free; deleted/pending/suspended accounts cannot authenticate; all admin-affecting paths share the same lock; and migrations/schema agree with model casts.
- [ ] Use `superpowers:verification-before-completion` and perform the requested code review before declaring implementation ready. Do not claim CI green until the pushed revision's CI actually passes.

Production deployment checklist for the operator (not executed while planning):

1. Back up the database, confirm the selected owner account is activated/verified, and confirm a deliverable production mailer, sender and HTTPS `APP_URL`. Existing sessions will require a fresh login once.
2. Use the application's established deployment mechanism and maintenance window. Install committed dependencies/assets, then run `php artisan migrate --force --no-interaction` and `php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction` before exposing new permission enforcement.
3. Run `php artisan wahabuddy:access-bootstrap --admin-email='<existing-owner-email>' --no-interaction`. Supply the actual verified owner; no hardcoded production email/password belongs in a seed or migration. A failed bootstrap keeps the app in maintenance until resolved; do not promote all accounts as a workaround.
4. Run `php artisan permission:cache-reset --no-interaction`, refresh configuration caches through the existing deployment process, and restart any existing long-lived workers. Bring the app out of maintenance only after the explicit owner can authenticate and authorization smoke checks pass.
5. Smoke test using a controlled mailbox and regular account: invite/activate, direct creation, regular-user denial of settings/users, admin access, and suspend revocation. Check delivery failures and offer resend without creating duplicate accounts.
6. Prefer a forward fix if release problems arise. Do not blindly drop state columns/rollback code in a way that would reactivate soft-deleted/suspended/pending accounts or discard their state; a rollback needs a separately reviewed data-preservation procedure.

## Plan self-review

- Spec coverage mapped: roles/state/bootstrap → Task 1; lifecycle, personal paths, preservation and admin races → Task 2; both creation modes, delivery, activation races → Task 3; all auth/session paths → Task 4; settings/list/server props → Task 5; UI/navigation → Task 6; verification/release → final checklist.
- Review Focus cases each have explicit owning tests above. File paths, route names, state fields and method signatures are shared through Interfaces blocks.
- Package APIs must be checked against the actually installed v8 release during execution. Test commands are planned, not recorded pass results.
