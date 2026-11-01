# viSparkle 運営・法対応（サブプロジェクト3） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the legal pages and consent, the pre-checkout confirmation screen, self-service account deletion and creator closure, user reports, and an admin area (hide posts, suspend users, refunds) so real charging can start.

**Architecture:** Legal text is Markdown in `resources/legal/`. Operator details come from env via `config/legal.php`. Destructive billing operations live in small services (`AccountDeletion`, `CreatorClosure`, `CreatorSuspension`, `Refunds`) that call the existing `PaymentGateway` and `SubscriptionSync`. The admin area is Inertia pages under `/admin` behind an `admin` middleware, with every action logged to `admin_actions`.

**Tech Stack:** Laravel 13, Fortify, Inertia 3 + React 19 + TS, stripe/stripe-php 22 (API 2026-10-28.endive), Pest 4, MySQL 8, `league/commonmark` (already a Laravel dependency).

**Spec:** `docs/superpowers/specs/2026-11-01-visparkle-ops-legal-design.md` (read it first; § numbers below refer to it)

## Global Constraints

- Everything runs in Sail (`./vendor/bin/sail ...`). The host has no PHP. `./vendor/bin/sail composer ci:check` must pass before every commit.
- User-facing copy is Japanese and exactly as written in the spec/plan.
- Billing writes go only through `SubscriptionSync::apply()/sync()`, never inside a caller's DB transaction.
- New admin/suspension/hidden/closed/refund columns are NOT in `#[Fillable]`; set them with `forceFill`.
- Gateway failures surface as `App\Billing\BillingException`. Operations that touch many subscriptions continue past individual failures, log `billing.ops_failed`, and report success/failure counts.
- Follow existing patterns: services in `app/Services` (billing ones in `app/Services/Billing`), FormRequests, Wayfinder actions on the frontend, `Inertia::flash('toast', [...])`, Pest feature tests.
- Commit messages in Japanese with a conventional prefix and a `Co-Authored-By:` trailer naming the model that wrote the commit.
- A Stripe test-mode secret key is in the local `.env`. Implementers may run read/write probes against the sandbox (never live keys) to confirm API shapes. Probe scripts go in `storage/` and are deleted afterwards.

## Review Focus

1. **Stripe call fails mid-way through account deletion** → the user is NOT deleted and still sees an error. No orphaned charging subscription. (Task 5 test `aborts deletion when stripe fails`.)
2. **Refunding the same payment twice** (double click, or a dashboard refund already happened) → the second attempt is 422, and `refunded_amount` never exceeds `amount`. (Task 8 tests.)
3. **Suspended user with a live session** → their next request logs them out. They cannot log back in. (Task 9 test.)
4. **Hidden post via direct URL, feed, home, creator page or file download** → 404 or excluded for everyone but the author. (Task 9 tests.)
5. **Terms version bump** → logged-in users are redirected to re-accept, but webhooks, logout and the legal pages keep working. (Task 3 tests.)

---

### Task 1: Schema, models, admin command

**Files:**

- Create: `database/migrations/2026_11_02_000001_add_ops_columns.php`, `database/migrations/2026_11_02_000002_create_reports_table.php`, `database/migrations/2026_11_02_000003_create_admin_actions_table.php`
- Create: `app/Models/Report.php`, `app/Models/AdminAction.php`, `app/Enums/ReportReason.php`, `app/Enums/ReportStatus.php`, `app/Enums/ReportTarget.php`, `database/factories/ReportFactory.php`
- Create: `app/Console/Commands/ManageAdmin.php`
- Modify: `app/Models/User.php`, `Creator.php`, `Post.php`, `Payment.php` (casts, `@property`, helpers), factories (`UserFactory::admin()`, `UserFactory::suspended()`, `CreatorFactory::closed()`, `PostFactory::hidden()`)
- Test: `tests/Feature/Models/OpsSchemaTest.php`, `tests/Feature/Console/ManageAdminTest.php`

**Interfaces — produces:**

- Columns exactly as spec §8 (and §6 for `reports`, §7 for `admin_actions`: `admin_id` FK users nullOnDelete, `action` string(64), `target_type` string(32), `target_id` unsigned bigint, `note` text null, timestamps).
- Enums (string-backed): `ReportReason` (`inappropriate, rights, adult, spam, other`) with `label(): string` → 「不適切な内容」「権利侵害」「成人向けの内容」「スパム」「その他」. `ReportStatus` (`open, resolved, dismissed`). `ReportTarget` (`post, creator`).
- `User::isAdmin(): bool`, `User::isSuspended(): bool`. `Creator::isClosed(): bool`. `Post::isHidden(): bool`. Scope `Post::visible()` → `whereNull('hidden_at')`. Scope `Creator::open()` → `whereNull('closed_at')` and the owner not suspended (`whereHas('user', fn ($q) => $q->whereNull('suspended_at'))`).
- `Payment::netAmount(): int` = `amount - refunded_amount`.
- `AdminAction::record(User $admin, string $action, Model $target, ?string $note = null): self`. `target_type` is the model's morph-less short name: `user`, `creator`, `post`, `payment`, `report`, `support`.
- Artisan `fanbox:admin {email} {--revoke}` → sets/clears `is_admin`. Outputs「{email} を管理者にしました。」/「{email} の管理者権限を外しました。」. Unknown email → error and exit code 1.

- [ ] **Step 1: Failing tests** — `OpsSchemaTest`: helpers return correct bools; `Post::visible()` excludes hidden; `Creator::open()` excludes closed and suspended-owner creators; `netAmount()`; `AdminAction::record` stores fields. `ManageAdminTest`: grant, revoke, unknown email exits 1.
- [ ] **Step 2: Run** — `./vendor/bin/sail artisan test tests/Feature/Models/OpsSchemaTest.php tests/Feature/Console/ManageAdminTest.php` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the files, then `composer ci:check` → PASS.
- [ ] **Step 5: Commit** `feat: 運営機能用のカラム・テーブルと管理者コマンドを追加`

---

### Task 2: Legal pages, footer, operator config, production guard

**Files:**

- Create: `config/legal.php`, `resources/legal/terms.md`, `resources/legal/privacy.md`, `app/Services/Legal/LegalDocuments.php`, `app/Http/Controllers/Public/LegalController.php`, `resources/js/pages/legal/document.tsx`, `resources/js/pages/legal/tokushoho.tsx`, `resources/js/components/site-footer.tsx`
- Modify: `routes/web.php`, `resources/js/layouts/public-layout.tsx` and the app layout (add the footer), `app/Services/Billing/CheckoutService.php` (guard), `.env.example`
- Test: `tests/Feature/Public/LegalPagesTest.php`, add a case to `tests/Feature/Fan/CheckoutTest.php`

**Interfaces — produces:**

- `config('legal')` = `seller_name`, `responsible_person`, `address`, `phone`, `email`, `disclose_on_request` (bool), all from the env names in spec §4.2.
- `LegalDocuments::version(string $name): string` reads the `version:` frontmatter of `resources/legal/{name}.md` (cached forever per file mtime). `LegalDocuments::html(string $name): string` renders the body with CommonMark (`html_input => 'strip'`, `allow_unsafe_links => false`). `LegalDocuments::termsVersion(): string` = `version('terms')`.
- `LegalDocuments::ready(): bool` → seller_name, responsible_person and email are non-empty, and address and phone are either set or `disclose_on_request` is true.
- Routes `GET /legal/tokushoho` (`legal.tokushoho`), `GET /legal/terms` (`legal.terms`), `GET /legal/privacy` (`legal.privacy`). Public, no auth.
- Tokushoho page: a table with the rows and fixed copy of spec §4.2. Address/phone render「請求があれば遅滞なく開示します。」when empty and `disclose_on_request`. When not `ready()`, show a notice「事業者情報は準備中です。」at the top (still 200).
- Footer links「特定商取引法に基づく表記」「利用規約」「プライバシーポリシー」, plus `© {year} viSparkle`.
- `terms.md` / `privacy.md`: Japanese template texts, `version: 2026-11-01`. Each starts with an HTML comment「公開前に専門家の確認を受けること」. The privacy policy must cover: collected data (account, payment via Stripe, uploads, access logs), purposes, third parties (Stripe, Laravel Cloud, Cloudflare R2, the planned mail provider Resend), retention, disclosure requests, contact (`LEGAL_EMAIL`). The terms must cover: membership, the supporting plan (monthly auto-renewal, cancellation, no refunds except where the operator approves), creator obligations (all-ages only, rights), prohibited acts, platform fee, suspension, disclaimer, governing law (Japan), and court (the court with jurisdiction over the operator's location).
- CheckoutService guard (spec §4.2): before creating a session, if `app()->isProduction()`, the Stripe secret starts with `sk_live_`, and `! LegalDocuments::ready()` → `Log::error('legal.not_ready')` and a ValidationException on `plan`:「現在お申し込みを受け付けていません。」.

- [ ] **Step 1: Failing tests** — pages 200 with expected strings (seller name from config, 開示 wording, footer links); terms/privacy show their version; `ready()` truth table (dataset); the checkout guard rejects production+live+not-ready, allows a test key, allows ready.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** + `ci:check` → PASS.
- [ ] **Step 5: Commit** `feat: 特商法表記・利用規約・プライバシーポリシーのページを追加`

---

### Task 3: Terms consent at registration and re-acceptance

**Files:**

- Modify: `app/Actions/Fortify/CreateNewUser.php`, `resources/js/pages/auth/register.tsx`, `bootstrap/app.php` (append middleware to the web group)
- Create: `app/Http/Middleware/EnsureTermsAccepted.php`, `app/Http/Controllers/Legal/AcceptTermsController.php`, `resources/js/pages/legal/accept.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/RegistrationTest.php` (extend), `tests/Feature/Legal/TermsAcceptanceTest.php`

**Interfaces:**

- Consumes: `LegalDocuments::termsVersion()`.
- Registration requires `terms` accepted (`accepted` rule, message「利用規約とプライバシーポリシーへの同意が必要です。」). On success it stores `terms_version` and `terms_accepted_at` (forceFill). Checkbox label:「<利用規約>と<プライバシーポリシー>に同意します」, with links opening in a new tab.
- `GET /legal/accept` (`legal.accept`, auth) shows the version and links. `POST /legal/accept` (`legal.accept.store`) records the version and redirects to `intended` or `/feed`.
- `EnsureTermsAccepted`: for authenticated users whose `terms_version !== termsVersion()`, redirect to `legal.accept`. Skip for: route names starting `legal.`, `logout`, `stripe.*`, `verification.*`, `dev.*`, non-GET requests other than Inertia visits — simplest rule: only redirect GET/HEAD requests; other methods pass. Skip when the request expects JSON.
- Register it in the web middleware group after `HandleInertiaRequests`.

- [ ] **Step 1: Failing tests** — registration without `terms` → error; with it → version stored; user with an old version GET `/feed` → redirect to accept; POST accept → stored and redirected; legal pages, logout and the webhook are not redirected; a POST by a stale user is not blocked.
- [ ] **Step 2–4** as usual (update existing auth/registration tests that post the form to include `terms => '1'`, and existing user factories to default `terms_version` to the current version so other tests aren't redirected).
- [ ] **Step 5: Commit** `feat: 会員登録時の規約同意と、改定時の再同意を追加`

---

### Task 4: Pre-checkout confirmation screen

**Files:**

- Create: `app/Http/Controllers/Fan/CheckoutConfirmController.php`, `resources/js/pages/fan/checkout-confirm.tsx`
- Modify: `routes/fan.php`, `app/Services/Billing/CheckoutService.php` (extract the validation into `assertCanBegin(User, Plan): void`, used by both `begin()` and the confirm page), `resources/js/components/support-button.tsx`
- Test: `tests/Feature/Fan/CheckoutConfirmTest.php`

**Interfaces:**

- `GET /@{creator}/plans/{plan}/confirm` → `supports.confirm` (auth, verified). The plan must belong to the creator (else 404). If `assertCanBegin` throws → redirect to `creators.show` with an error toast of the first message. Else render props: `creator` (summary), `plan` (id, name, price, description), `renewalDay` (today's day of month in Asia/Tokyo; if 29–31 append「（月末日がない月は月末）」), links.
- Page copy exactly as spec §4.4. The button「お支払いへ進む（Stripe）」posts to the existing `supports.store`. Below it,「戻る」links back to the creator page.
- `support-button.tsx`: for a viewer with no support, the plan button navigates (Link) to `supports.confirm` instead of posting. Plan changes stay as they are.

- [ ] **Step 1: Failing tests** — renders the price, the auto-renewal, cancellation and no-refund copy; redirects with a toast for own creator / not accepting / not payable / already supporting; 404 for another creator's plan; guest → login.
- [ ] **Steps 2–5.** Commit `feat: 支援の申込内容の確認画面（最終確認画面）を追加`

---

### Task 5: Account deletion with cancellation, and creator closure

**Files:**

- Create: `app/Services/Billing/AccountDeletion.php`, `app/Services/Billing/CreatorClosure.php`, `app/Http/Controllers/Creator/ClosureController.php`
- Modify: `app/Http/Controllers/Settings/ProfileController.php` (`edit` passes `billingSupports`; `destroy` uses `AccountDeletion`), `app/Http/Requests/Settings/ProfileDeleteRequest.php` (fan-side guard removed; creator guard = any `active` support), `resources/js/components/delete-user.tsx`, `resources/js/pages/creator/profile.tsx` (closure section), `app/Http/Controllers/Public/CreatorPageController.php` + `resources/js/pages/public/creator.tsx` (closed notice), `app/Http/Controllers/Public/HomeController.php` (exclude closed creators), `app/Services/Billing/CheckoutService.php` (`assertCanBegin` refuses closed creators:「このクリエイターは活動を終了しました。」), `routes/creator.php`
- Test: `tests/Feature/Settings/AccountDeletionTest.php`, `tests/Feature/Creator/CreatorClosureTest.php` (update `ProfileUpdateTest` deletion cases that change)

**Interfaces:**

- `AccountDeletion::delete(User $user): void` — for each billing support (`active`, stripe id set) call `gateway->cancelSubscriptionNow($id)` then `SubscriptionSync::sync($id)`. Any `BillingException` → rethrow (nothing deleted yet beyond cancelled subs). Then delete the user. The controller catches `BillingException` → back with error toast「解約の処理に失敗したため、アカウントを削除できませんでした。時間をおいてお試しください。」(the user stays logged in).
- `ProfileController::edit` adds prop `billingSupports: list<{creator: string, price: int}>`. `delete-user.tsx` lists them with「削除すると、これらの支援は直ちに解約されます。今月分の返金はありません。」.
- Creator guard message (ProfileDeleteRequest):「有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。」. The condition is any `active` support for the creator.
- `CreatorClosure::close(Creator $creator): array{cancelled: int, failed: int}` — set `closed_at`, set all plans `is_accepting=false`, then for each billing support with `cancel_at_period_end=false` call `apply(gateway->setCancelAtPeriodEnd(id, true))`. Count failures, and log `billing.ops_failed` with the support id. Dummy supports (no stripe id) end immediately. `CreatorClosure::reopen(Creator $creator): void` clears `closed_at`.
- Routes: `POST creator/closure` (`creator.closure.store`), `DELETE creator/closure` (`creator.closure.destroy`). Toasts:「活動を終了しました。{n}件の支援を期間の終わりで停止します。」(append「（{f}件は失敗しました。もう一度お試しください）」when f>0) /「活動を再開しました。プランの受付を再開してください。」.
- Creator page prop `closed: bool` → banner「このクリエイターは活動を終了しました。」and no support buttons for non-supporters.

- [ ] **Step 1: Failing tests** — deletion cancels billing supports (`cancelledNow` in the fake contains them) and deletes the user; `aborts deletion when stripe fails` (the gateway mock throws → user still exists, error toast); dummy-only user deletes fine; creator with an active (even cancel-scheduled) support is refused; closure stops plans, schedules cancellations, counts failures, hides the creator from home, blocks new checkout; reopen.
- [ ] **Steps 2–5.** Commit `feat: 退会時の自動解約と、クリエイターの活動終了を追加`

---

### Task 6: Reports (user side)

**Files:**

- Create: `app/Http/Controllers/ReportController.php`, `app/Http/Requests/StoreReportRequest.php`, `resources/js/components/report-dialog.tsx`
- Modify: `routes/web.php`, `resources/js/pages/public/post.tsx`, `resources/js/pages/public/creator.tsx`
- Test: `tests/Feature/ReportTest.php`

**Interfaces:**

- `POST /reports` (`reports.store`, auth, verified, `throttle:10,60`). Body: `target_type` (post|creator), `target_id` (must exist; a post must be published and not hidden), `reason` (ReportReason), `body` (nullable, max 1000). Users cannot report their own creator or posts (422「自分の投稿やページは通報できません。」).
- An open report by the same reporter on the same target is updated instead of creating a new one.
- Toast「通報を受け付けました。ご協力ありがとうございます。」. The dialog lists the reason labels as radio buttons plus a textarea, and the button is「通報する」. It shows for logged-in non-owners only.

- [ ] **Step 1: Failing tests** — creates; dedupes; own target 422; invalid target 422; guest redirected to login; throttle (11th → 429).
- [ ] **Steps 2–5.** Commit `feat: 投稿とクリエイターの通報を追加`

---

### Task 7: Admin foundation, dashboard, report handling

**Files:**

- Create: `app/Http/Middleware/EnsureUserIsAdmin.php` (alias `admin`), `routes/admin.php`, `app/Http/Controllers/Admin/{DashboardController,ReportController}.php`, `resources/js/pages/admin/{dashboard,reports}.tsx`, `resources/js/layouts/admin-layout.tsx` (or reuse the app layout with an admin nav)
- Modify: `bootstrap/app.php` (alias), `routes/web.php` (require admin.php), `resources/js/components/app-sidebar.tsx` (「管理」item when `auth.user.is_admin`), `app/Http/Middleware/HandleInertiaRequests.php` (share `is_admin`)
- Test: `tests/Feature/Admin/AdminAccessTest.php`, `tests/Feature/Admin/ReportHandlingTest.php`

**Interfaces:**

- All admin routes: prefix `admin`, name `admin.`, middleware `auth, verified, admin`. Non-admins get 404.
- `GET /admin` (`admin.dashboard`) props: `openReports: int`, `sales30d: int` (sum of `amount`), `refunds30d: int` (sum of `refunded_amount` with `refunded_at` in the window), `activeSupports: int`.
- `GET /admin/reports` (`admin.reports.index`) — paginated 50, open first then newest; each row has reporter name, target label + link (post title → `posts.show`; creator → `creators.show`; deleted target →「削除済み」), reason label, body, status, created_at, and resolution info. Filter `?status=open|resolved|dismissed`.
- `PATCH /admin/reports/{report}` (`admin.reports.update`) body `status` (resolved|dismissed), `note` (nullable, max 1000). Sets resolved_by/at and records `AdminAction` (`report.resolved` / `report.dismissed`).

- [ ] **Step 1: Failing tests** — non-admin 404 on every admin route (dataset); admin sees the dashboard numbers; reports listing order and filter; resolve/dismiss records the action.
- [ ] **Steps 2–5.** Commit `feat: 管理画面の土台とダッシュボード・通報の処理を追加`

---

### Task 8: Refunds and disputes

**Files:**

- Create: `app/Billing/Data/ChargeData.php`, `app/Billing/Data/RefundData.php`, `app/Services/Billing/Refunds.php`, `app/Http/Controllers/Admin/PaymentController.php`, `resources/js/pages/admin/payments.tsx`
- Modify: `app/Billing/PaymentGateway.php`, `StripeGateway.php`, `FakeGateway.php`, `app/Jobs/ProcessStripeEvent.php`, `app/Http/Controllers/Billing/StripeWebhookController.php` (pass `charge` ids), `app/Http/Controllers/Creator/EarningsController.php` (net of refunds), `routes/admin.php`, `docs/operations/stripe-setup.md` (subscribe `charge.refunded`, `charge.dispute.created` on the platform endpoint; dispute handling)
- Test: `tests/Feature/Billing/RefundsTest.php`, extend `StripeGatewayTest`, `WebhookTest`, `EarningsTest`, `tests/Feature/Admin/PaymentAdminTest.php`

**Interfaces:**

- `RefundData(string $id, int $amount)`. `ChargeData(string $id, ?string $invoiceId, int $amountRefunded, bool $disputed)`.
- Gateway: `refundInvoice(string $invoiceId): RefundData` — full refund of the invoice's payment with `reverse_transfer=true`, `refund_application_fee=true`, idempotency key prefixed as existing keys (`"refund-invoice-{$invoiceId}"`). `retrieveCharge(string $chargeId): ChargeData`. **Verify in the sandbox** how API 2026-10-28.endive links invoice ↔ PaymentIntent ↔ charge (e.g. `invoicePayments->all(['invoice' => …])` and a charge's `payment_intent` → invoice). Keep the mapping inside `StripeGateway` with fallbacks, as was done for subscriptions.
- FakeGateway: `refundInvoice` marks the invoice refunded (throws BillingException if already refunded). `retrieveCharge` works on fake charges created per paid invoice (`ch_fake_…`). Test controls: `chargeIdForInvoice(string $invoiceId): string`, `markDisputed(string $chargeId)`.
- `Refunds::refund(Payment $payment, User $admin, bool $cancelSupport): void` — 422 (ValidationException key `payment`,「この支払いはすでに返金済みです。」) if `refunded_amount >= amount`. Calls the gateway and sets `refunded_amount = amount`, `refunded_at = now()`. When `$cancelSupport` and the support is billing+active → `cancelSubscriptionNow` + `sync`. Records `AdminAction` `payment.refunded` (note includes whether the support was cancelled).
- `Refunds::syncCharge(string $chargeId): ?Payment` — `retrieveCharge`, find the payment by `stripe_invoice_id`, set `refunded_amount = min(amountRefunded, amount)`, `refunded_at` when >0 and null, `disputed_at` when disputed and null.
- Webhook: the platform endpoint handles `charge.refunded` and `charge.dispute.created` → `Refunds::syncCharge($chargeId)`. For `charge.dispute.created` the object is a dispute: use its `charge` field.
- `GET /admin/payments` (`admin.payments.index`) paginated 50, filters `creator` (slug) and `user` (email). Rows: paid_at, creator, supporter, amount, refunded, badges「返金済み」「異議申し立て」, and a「返金」button (dialog with the「支援も解約する」checkbox). `POST /admin/payments/{payment}/refund` (`admin.payments.refund`) body `cancel_support` bool. Toast「返金しました。」.
- Earnings: gross = sum of `amount - refunded_amount`; the fee estimate uses the net amount.

- [ ] **Step 1: Failing tests** — StripeGateway refund request params (`reverse_transfer`, `refund_application_fee`, path, idempotency key) and charge mapping; Refunds: refund, double refund 422, cancel option, syncCharge from a dashboard refund, dispute; webhook routes; earnings net of refunds; admin payments listing, filters, refund endpoint, non-admin 404.
- [ ] **Steps 2–5.** Commit `feat: 返金とチャージバックの記録、管理画面の支払い一覧を追加`

---

### Task 9: Hiding posts and suspending users

**Files:**

- Create: `app/Services/Billing/CreatorSuspension.php`, `app/Http/Middleware/LogoutSuspendedUsers.php`, `app/Http/Controllers/Admin/{PostModerationController,UserController}.php`, `resources/js/pages/admin/users.tsx`, `resources/js/pages/admin/post.tsx`
- Modify: `app/Providers/FortifyServiceProvider.php` (`Fortify::authenticateUsing` rejecting suspended users), `bootstrap/app.php`, `app/Services/Entitlement.php`, every public post query (`HomeController`, `CreatorPageController`, `PostPageController`, `PostFileDownloadController`, `FeedController`) to use `visible()` / 404 on hidden for non-authors; route binding for `{creator}` → 404 when the owner is suspended; creator-side post list/editor shows the hidden notice; `routes/admin.php`
- Test: `tests/Feature/Admin/PostModerationTest.php`, `tests/Feature/Admin/UserSuspensionTest.php`

**Interfaces:**

- `PATCH /admin/posts/{post}` (`admin.posts.update`) body `hidden` bool, `reason` (required when hidden, max 500). Sets/clears `hidden_at`/`hidden_reason`. AdminAction `post.hidden` / `post.unhidden`. `GET /admin/posts/{post}` (`admin.posts.show`) shows the post summary, its reports, and the form.
- Hidden posts: 404 for non-authors on the post page and file downloads; excluded from home, the creator page, and the feed; `Entitlement::canView` false for non-authors. The author sees the banner「運営により非公開になっています（理由: {reason}）」 on the post page and the editor, and a「非公開（運営）」 badge in the post list.
- `GET /admin/users` (`admin.users.index`) with search `q` (name/email/slug), paginated 50. `PATCH /admin/users/{user}` (`admin.users.update`) body `suspended` bool, `reason` (required when suspending). Admins cannot suspend themselves (422). Suspending → `forceFill` suspended_at/reason, then:
    - fan side: every billing support of the user → `cancelSubscriptionNow` + `sync` (no refund);
    - creator side: `CreatorSuspension::suspend(Creator)` → for each active billing support: `cancelSubscriptionNow` + `sync`, then `Refunds`-style refund of that support's latest unrefunded payment (reuse `Refunds`, with the admin as actor). Returns `{cancelled, refunded, failed}`.
    - Toast「利用停止にしました。（解約 {c}件・返金 {r}件・失敗 {f}件）」. AdminAction `user.suspended` / `user.unsuspended`.
- Login: suspended users get the `email` error「このアカウントは利用停止中です。」 (keep Fortify's default credential check and throttling). `LogoutSuspendedUsers` (web group): an authenticated suspended user is logged out, the session is invalidated, and they are redirected to `login` with that message.
- Suspended creator: `/@slug` and their posts → 404; excluded from home; `assertCanBegin` refuses.

- [ ] **Step 1: Failing tests** — hidden post 404/excluded across all five surfaces for others, visible to the author with the banner; unhide restores; suspension blocks login, kicks a live session, cancels the fan's subscriptions, and for a creator cancels + refunds every supporter (check fake `cancelledNow` and payments `refunded_amount`); self-suspension 422; unsuspend restores login.
- [ ] **Steps 2–5.** Commit `feat: 投稿の非公開化と会員の利用停止を追加`

---

### Task 10: Ops docs, ledger, end-to-end check

**Files:**

- Modify: `docs/operations/laravel-cloud.md` (env vars `LEGAL_*`; running `php artisan fanbox:admin you@example.com` from the Cloud Commands tab), `docs/operations/stripe-setup.md` (§7 checklist: legal pages filled, `legal.ready`)
- Create: `docs/superpowers/reports/2026-11-01-visparkle-ops-legal-execution-ledger.md`

- [ ] **Step 1:** Update the docs.
- [ ] **Step 2: Browser check (local, fake driver)** — footer links; registration with consent; confirm screen → fake checkout; report a post; admin: make admin via the command, resolve the report, hide the post (author banner, 404 for others), refund a payment, suspend a creator (supporters cancelled + refunded); delete a fan account with a support.
- [ ] **Step 3:** `composer ci:check` → PASS.
- [ ] **Step 4: Commit** `docs: 運営・法対応の手順と実装記録を追加`
