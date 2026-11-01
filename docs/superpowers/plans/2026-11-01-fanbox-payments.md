# Fanbox 決済（サブプロジェクト2） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the dummy "support" button with real Stripe monthly billing (Checkout + Billing + Connect Express destination charges) whose state is synced into `supports` by webhooks.

**Architecture:** All Stripe calls go through `App\Billing\PaymentGateway` (real `StripeGateway`, cache-backed `FakeGateway` for tests/local). `supports` is the subscription record; `SubscriptionSync` re-fetches the subscription from the gateway and upserts the row, so webhooks are idempotent and order-independent. Controllers never set `active` directly.

**Tech Stack:** Laravel 13, stripe/stripe-php ^22, Inertia 3 + React 19 + TS, Pest 4, MySQL 8.

**Spec:** `docs/superpowers/specs/2026-11-01-fanbox-payments-design.md` (read it first; section numbers below refer to it)

## Global Constraints

- Everything runs in Sail: `./vendor/bin/sail artisan test`, `./vendor/bin/sail composer ...`, `./vendor/bin/sail npm ...`. The host has no PHP; host `npm` fails (container-built node_modules).
- Before each commit: `./vendor/bin/sail composer ci:check` must pass (Pint, PHPStan level 7, Pest, ESLint/Prettier/tsc). Run the single test file first while iterating.
- User-facing copy is Japanese, exactly as written in this plan/spec. Prices shown with `formatYen` and the word「税込」.
- Currency JPY (zero-decimal: amounts are integer yen). Platform fee `config('fanbox.billing.platform_fee_percent')`, default `10`.
- Validation errors for support operations use the error key `plan` (the existing `SupportButton` toasts `errors.plan`).
- Never set `supports.status = active` from a controller; only `SubscriptionSync` writes billing state.
- Gateway failures throw `App\Billing\BillingException`; web requests render it as a toast「決済サービスに接続できませんでした。時間をおいてお試しください。」and `back()`.
- Follow existing patterns: final services in `app/Services`, `#[Fillable]` attributes on models, Wayfinder actions on the frontend (`@/actions/...`, `@/routes/...`), `Inertia::flash('toast', [...])`.
- Commit messages in Japanese, conventional prefix, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Webhook arrives before/after the checkout-complete redirect, or twice** → exactly one `supports` row, same final state. (Task 4 test `it converges regardless of call order`, Task 7 test `ignores a replayed event id`.)
2. **Fan opens Checkout in two tabs and pays both** → second subscription cancelled immediately, one active support, `billing.duplicate_subscription` logged. (Task 4 test `cancels a duplicate subscription for the same creator`; Task 5 test `expires the previous open checkout session`.)
3. **Upgrade payment declined** → plan and price unchanged, 422 on `plan`. (Task 6 test `keeps the old plan when the upgrade payment fails`.)
4. **Another user's checkout session id in the complete URL** → 404, nothing synced. (Task 5 test `rejects completing someone else's session`.)
5. **Creator not onboarded / onboarding revoked** → no new checkout possible; button shows「準備中」. (Task 5 test `refuses creators who cannot accept supports`, Task 7 test `deauthorization stops accepting supports`.)

---

### Task 1: Schema, config, models

**Files:**
- Modify: `composer.json` (add `stripe/stripe-php: ^22.1`)
- Create: `database/migrations/2026_11_01_000001_add_billing_columns.php`, `database/migrations/2026_11_01_000002_create_payments_table.php`, `database/migrations/2026_11_01_000003_create_stripe_events_table.php`
- Create: `app/Models/Payment.php`, `database/factories/PaymentFactory.php`
- Modify: `app/Models/User.php`, `app/Models/Creator.php`, `app/Models/Plan.php`, `app/Models/Support.php`, `database/factories/SupportFactory.php`, `database/factories/CreatorFactory.php`
- Modify: `config/fanbox.php` (add `billing` section), `.env.example`
- Test: `tests/Feature/Models/BillingSchemaTest.php`

**Interfaces:**
- Produces:
  - Columns exactly as spec §5 (`users.stripe_customer_id`; `creators.stripe_account_id`, `payouts_enabled`, `details_submitted`; `plans.stripe_product_id`, `stripe_price_id`; `supports.stripe_subscription_id`, `stripe_status`, `current_period_end`, `cancel_at_period_end`, generated `current_key`; tables `payments`, `stripe_events`).
  - `current_key`: `$table->string('current_key', 64)->nullable()->storedAs("CASE WHEN status = 'active' THEN CONCAT(user_id, ':', creator_id) END")->unique()`.
  - `config('fanbox.billing')` = `['driver' => env('BILLING_DRIVER', 'fake'), 'platform_fee_percent' => (int) env('BILLING_PLATFORM_FEE_PERCENT', 10), 'stripe' => ['secret' => env('STRIPE_SECRET'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'), 'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET')], 'checkout_expires_minutes' => 30]`.
  - `Creator::canAcceptSupports(): bool` (`stripe_account_id !== null && payouts_enabled`).
  - `Support::isBilling(): bool` (`stripe_subscription_id !== null`); casts `current_period_end` datetime, `cancel_at_period_end` bool.
  - `Payment` model (fillable: support_id, user_id, creator_id, stripe_invoice_id, amount, fee_percent, paid_at; casts paid_at datetime) with `creator()`, `support()` relations; `Creator::payments(): HasMany`.
  - Factory states: `CreatorFactory::payable()` (sets `stripe_account_id = 'acct_fake_'.Str::random(8)`, `payouts_enabled = true`, `details_submitted = true`); `SupportFactory::billing()` (sets `stripe_subscription_id = 'sub_fake_'.Str::random(10)`, `stripe_status = 'active'`, `current_period_end = now()->addMonth()`).
  - Add `@property` docblocks for every new column (PHPStan level 7).

- [ ] **Step 1: Install stripe-php** — `./vendor/bin/sail composer require stripe/stripe-php:^22.1`. Expected: `composer.lock` updated.
- [ ] **Step 2: Write the failing tests** in `BillingSchemaTest.php`:
  - `it rejects a second active support for the same user and creator` — create active support, then `Support::factory()->forPlan($otherPlanSameCreator)->create(['user_id' => same])` → `expect(...)->toThrow(UniqueConstraintViolationException::class)`.
  - `it allows many ended supports and one active` — 2 ended + 1 active for same pair succeeds; `Support::count() === 3`.
  - `it frees the slot when a support ends` — active → `update(['status' => Ended])` → new active succeeds.
  - `it reports whether a creator can accept supports` — default factory false; `payable()` true; `payable()` with `payouts_enabled=false` false.
- [ ] **Step 3: Run** `./vendor/bin/sail artisan test tests/Feature/Models/BillingSchemaTest.php` — Expected: FAIL (unknown columns / methods).
- [ ] **Step 4: Implement** migrations, config, models, factories, `.env.example` keys from spec §10 (`BILLING_DRIVER=fake`, empty Stripe keys).
- [ ] **Step 5: Run** the test file, then the full suite. Expected: PASS. (Existing `SupportTest` "keeps a single active support across repeated switches" still passes because `start()` ends before creating.)
- [ ] **Step 6: Commit** `feat: 決済用のカラム・テーブルと設定を追加`

---

### Task 2: Gateway interface, DTOs, FakeGateway, binding

**Files:**
- Create: `app/Billing/PaymentGateway.php`, `app/Billing/Data/{SubscriptionData,CheckoutSessionData,CheckoutRequest,AccountData,InvoiceData,PriceRef}.php`, `app/Billing/BillingException.php`, `app/Billing/PaymentFailedException.php`, `app/Billing/FakeGateway.php`
- Create: `app/Providers/BillingServiceProvider.php` (register in `bootstrap/providers.php`)
- Test: `tests/Feature/Billing/FakeGatewayTest.php`

**Interfaces:**
- Produces (all DTOs `final readonly class`, public promoted ctor props):
  - `SubscriptionData(string $id, string $customerId, string $status, ?string $itemId, ?string $priceId, ?int $unitAmount, ?CarbonImmutable $currentPeriodEnd, bool $cancelAtPeriodEnd, array $metadata)` — `$metadata` is `array<string, string>`.
  - `CheckoutSessionData(string $id, string $url, string $status /* open|complete|expired */, ?string $subscriptionId, ?string $customerId, array $metadata)`.
  - `CheckoutRequest(string $customerId, string $priceId, string $destinationAccountId, int $feePercent, array $metadata, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt)`.
  - `AccountData(string $id, bool $payoutsEnabled, bool $detailsSubmitted)`.
  - `InvoiceData(string $id, ?string $subscriptionId, string $customerId, string $status, int $amountPaid, ?CarbonImmutable $paidAt)`.
  - `PriceRef(string $productId, string $priceId)`.
  - `BillingException extends \RuntimeException`; `PaymentFailedException extends BillingException`.
  - `interface PaymentGateway`:
    ```php
    public function createCustomer(User $user): string;
    public function ensurePrice(Plan $plan): PriceRef;
    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData;
    public function retrieveCheckoutSession(string $id): CheckoutSessionData;
    public function expireCheckoutSession(string $id): void;
    public function retrieveSubscription(string $id): SubscriptionData;
    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData;
    /** Upgrade: charge proration now and apply only if paid (throws PaymentFailedException otherwise). Downgrade: no proration. */
    public function changeSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId, bool $chargeProrationNow): SubscriptionData;
    public function cancelSubscriptionNow(string $subscriptionId): void;
    public function createPortalSession(string $customerId, string $returnUrl): string;
    public function createConnectAccount(Creator $creator): string;
    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string;
    public function createDashboardLoginLink(string $accountId): string;
    public function retrieveAccount(string $accountId): AccountData;
    public function retrieveInvoice(string $id): InvoiceData;
    ```
  - `FakeGateway implements PaymentGateway`, constructed with `Illuminate\Contracts\Cache\Repository $cache`; whole state in one cache key `fake-billing:state` (array of `customers`, `prices`, `sessions`, `subscriptions`, `invoices`, `accounts`, `cancelledNow`). IDs prefixed `cus_fake_`, `prod_fake_`, `price_fake_`, `cs_fake_`, `sub_fake_`, `si_fake_`, `in_fake_`, `acct_fake_`. `ensurePrice` returns the same `PriceRef` for the same plan id. Checkout `url` = `route('dev.fake-checkout.show', $id)`. `createAccountOnboardingLink` marks the account payable + submitted and returns `$returnUrl`. `createPortalSession` returns `$returnUrl`. `createDashboardLoginLink` returns `route('creator.earnings.index')`. Unknown ids throw `BillingException`.
  - Test controls on `FakeGateway` (public, not on the interface):
    - `completeCheckout(string $sessionId): SubscriptionData` — creates an `active` subscription from the session (customer, price, metadata), period end `+1 month`, a paid invoice for the price amount; session becomes `complete`. Returns the subscription.
    - `failPayments(bool $fail = true): void` — while set, upgrades throw `PaymentFailedException` (subscription unchanged) and `advancePeriod` sets `past_due`.
    - `advancePeriod(string $subscriptionId): ?InvoiceData` — if `cancelAtPeriodEnd` → status `canceled`, returns null; elseif failing → `past_due`, returns null; else extends period one month and returns a new paid invoice.
    - `setSubscriptionStatus(string $subscriptionId, string $status): void`, `setAccount(string $accountId, bool $payoutsEnabled, bool $detailsSubmitted): void`.
    - `describeSession(string $id): ?array{amount: int, plan_id: int, status: string, success_url: string, cancel_url: string}` (used by the dev checkout page).
    - `latestInvoiceId(string $subscriptionId): ?string`, `cancelledNow(): list<string>`.
  - Upgrade in fake creates a paid invoice for `new - old` amount; downgrade creates none.
  - `BillingServiceProvider::register` binds `PaymentGateway` as a singleton: `'stripe'` → `StripeGateway` (Task 3; until then throw `BillingException('stripe driver not available')`), `'fake'` → `FakeGateway` using `Cache::store()`. Resolving `fake` when `app()->isProduction()` throws `\LogicException('The fake billing driver cannot be used in production.')`. Also bind `FakeGateway::class` to the same instance when the driver is fake.
  - Test helper in `tests/Pest.php`: `function fakeBilling(): FakeGateway` (sets driver `fake`, forgets the singleton, returns `app(PaymentGateway::class)`).

- [ ] **Step 1: Write failing tests** in `FakeGatewayTest.php`:
  - `it completes a checkout into an active subscription carrying metadata` — status `active`, `priceId` = ensured price, `metadata['user_id']` preserved, `currentPeriodEnd` ≈ now+1 month, `latestInvoiceId` invoice has `amountPaid` = plan price.
  - `it schedules and undoes cancellation` — `setCancelAtPeriodEnd(true)` then `advancePeriod` → `canceled`; separate sub: `true` then `false` then `advancePeriod` → still `active` with new invoice.
  - `it does not apply an upgrade when payment fails` — `failPayments()`, `changeSubscriptionPrice(..., true)` throws `PaymentFailedException`; `retrieveSubscription` still has old price.
  - `it returns the same price for the same plan`.
  - `it refuses the fake driver in production` — `app()->detectEnvironment(fn () => 'production')`, `app()->forgetInstance(PaymentGateway::class)`, expect `app(PaymentGateway::class)` throws `LogicException`.
- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Billing/FakeGatewayTest.php` — Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** the files above.
- [ ] **Step 4: Run** the file, then `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: 決済ゲートウェイのインターフェースと偽ドライバを追加`

---

### Task 3: StripeGateway

**Files:**
- Create: `app/Billing/StripeGateway.php`
- Modify: `app/Providers/BillingServiceProvider.php` (`'stripe'` → `new StripeGateway(new StripeClient(config('fanbox.billing.stripe.secret')))`)
- Test: `tests/Feature/Billing/StripeGatewayTest.php`, helper `tests/Support/RecordingStripeHttpClient.php` (autoload-dev `Tests\\` already maps `tests/`)

**Interfaces:**
- Consumes: Task 2 interface and DTOs.
- Produces: `StripeGateway implements PaymentGateway` with `__construct(StripeClient $client)`. Requirements per method:
  - Every Stripe call wrapped: catch `\Stripe\Exception\ApiErrorException` → `throw new BillingException($e->getMessage(), previous: $e)` (log at `error` with the request id).
  - `createCustomer`: `customers->create(['email', 'name', 'metadata' => ['user_id']], ['idempotency_key' => "user-{id}-customer"])`.
  - `ensurePrice`: if plan has both ids return them; else `products->create(['name' => "{creator display_name} {plan name}", 'metadata' => ['plan_id']], idem "plan-{id}-product")` and `prices->create(['product', 'currency' => 'jpy', 'unit_amount' => price, 'recurring' => ['interval' => 'month'], 'tax_behavior' => 'inclusive'], idem "plan-{id}-price")`, then save both ids on the plan.
  - `createCheckoutSession`: `checkout->sessions->create` with `mode=subscription`, `customer`, `line_items=[['price', 'quantity' => 1]]`, `payment_method_types=['card']`, `locale='ja'`, `expires_at`, `success_url`, `cancel_url`, `metadata`, `subscription_data=['application_fee_percent' => feePercent, 'transfer_data' => ['destination' => accountId], 'metadata' => metadata]`.
  - `changeSubscriptionPrice`: `subscriptions->update($id, ['items' => [['id' => itemId, 'price' => priceId]], 'proration_behavior' => $charge ? 'always_invoice' : 'none', 'payment_behavior' => $charge ? 'pending_if_incomplete' : 'allow_incomplete'])`; if the response has a non-null `pending_update` → throw `PaymentFailedException`.
  - `cancelSubscriptionNow`: `subscriptions->cancel($id)`.
  - `createConnectAccount`: `accounts->create(['type' => 'express', 'country' => 'JP', 'email' => user email, 'capabilities' => ['transfers' => ['requested' => true]], 'metadata' => ['creator_id']], idem "creator-{id}-account")`.
  - `createAccountOnboardingLink`: `accountLinks->create(['account', 'refresh_url', 'return_url', 'type' => 'account_onboarding'])->url`.
  - `createDashboardLoginLink`: `accounts->createLoginLink($id)->url`.
  - `retrieveAccount` → `AccountData(id, payouts_enabled && capabilities.transfers === 'active', details_submitted)`.
  - `retrieveSubscription` / mapping: `public static function subscriptionFrom(\Stripe\Subscription $s): SubscriptionData` — first item gives `itemId`, `priceId`, `unitAmount`; `currentPeriodEnd` from `items.data[0].current_period_end`, falling back to `$s->current_period_end`; `customer` may be an id or expanded object.
  - `retrieveInvoice` / `public static function invoiceFrom(\Stripe\Invoice $i): InvoiceData` — subscription id from `parent.subscription_details.subscription`, falling back to `$i->subscription`; `paidAt` from `status_transitions.paid_at`.
  - `createPortalSession`: `billingPortal->sessions->create(['customer', 'return_url'])->url`.
- `RecordingStripeHttpClient` implements the installed `\Stripe\HttpClient\ClientInterface` (check the exact `request()` signature in `vendor/stripe/stripe-php/lib/HttpClient/ClientInterface.php`), records `[method, url, params, headers]`, and returns queued JSON bodies with status 200. Install via `\Stripe\ApiRequestor::setHttpClient()`; reset to `null` in `afterEach`.

- [ ] **Step 1: Write failing tests**:
  - `it creates a destination-charge checkout session` — assert recorded POST `/v1/checkout/sessions` params: `mode=subscription`, `subscription_data.application_fee_percent=10`, `subscription_data.transfer_data.destination=acct_x`, `locale=ja`, `line_items.0.price=price_x`.
  - `it treats a pending update as a failed upgrade` — queued response contains `"pending_update": {...}` → `PaymentFailedException`.
  - `it reads the period end from the subscription item` — `subscriptionFrom(Subscription::constructFrom([... items.data[0].current_period_end => 1793836800]))` → `currentPeriodEnd->timestamp === 1793836800`.
  - `it reads the invoice subscription from the parent details` — `invoiceFrom(Invoice::constructFrom([...]))`.
  - `it wraps API errors` — queued 402/500 error body → `BillingException`.
  - `it creates the plan price once` — second `ensurePrice` on the same plan makes no HTTP call.
- [ ] **Step 2: Run** the file — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** file + `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: Stripe 本番ドライバを追加`

---

### Task 4: SubscriptionSync, PaymentRecorder, AccountSync

**Files:**
- Create: `app/Services/Billing/SubscriptionSync.php`, `app/Services/Billing/PaymentRecorder.php`, `app/Services/Billing/AccountSync.php`
- Test: `tests/Feature/Billing/SubscriptionSyncTest.php`, `tests/Feature/Billing/PaymentRecorderTest.php`

**Interfaces:**
- Consumes: `PaymentGateway`, DTOs (Task 2), schema (Task 1).
- Produces:
  - `SubscriptionSync::sync(string $subscriptionId): ?Support` — retrieves from the gateway, then `apply()`.
  - `SubscriptionSync::apply(SubscriptionData $data): ?Support` — the algorithm of spec §7.2 and the status table in §5. Specifics:
    - Status map: `active|trialing|past_due` → `Active`; `canceled|unpaid|incomplete_expired|paused` → `Ended`; `incomplete` → return existing row unchanged or null.
    - New row requires metadata `user_id`, `creator_id`, `plan_id` and `User::find(user_id)->stripe_customer_id === $data->customerId`; otherwise `Log::warning('billing.metadata_mismatch', [...])` and return null. Existing row requires `$support->user->stripe_customer_id === $data->customerId` (same log on mismatch).
    - `plan_id` from `Plan::where('stripe_price_id', $data->priceId)->where('creator_id', creator)`; if not found keep current `plan_id` (new rows: metadata `plan_id`). `price` = `$data->unitAmount`.
    - Sets `stripe_status`, `current_period_end`, `cancel_at_period_end`; `ended_at = now()` on transition to Ended, `null` on Active; `started_at = now()` on create.
    - Runs in `DB::transaction`, locking the user row (`lockForUpdate`) then the support row.
    - Duplicate (another Active support for the same user+creator, or `UniqueConstraintViolationException` on save): `gateway->cancelSubscriptionNow($data->id)`, `Log::error('billing.duplicate_subscription', ['subscription' => id, 'user_id', 'creator_id'])`, return the existing active support. Cancel outside the DB transaction (after it).
  - `PaymentRecorder::record(string $invoiceId): ?Payment` — retrieves invoice; ignores unless `status === 'paid'`, `amountPaid > 0`, `subscriptionId !== null`; calls `SubscriptionSync::sync`; `Payment::firstOrCreate(['stripe_invoice_id' => id], [support_id, user_id, creator_id, amount, fee_percent => config value, paid_at => paidAt ?? now()])`.
  - `AccountSync::sync(string $accountId): ?Creator` — retrieves account; updates `payouts_enabled`, `details_submitted` on the creator with that `stripe_account_id`.
  - `AccountSync::deauthorize(string $accountId): void` — sets `payouts_enabled = false`.

- [ ] **Step 1: Write failing tests** (use `fakeBilling()`; create user with `stripe_customer_id` via `createCustomer`, a payable creator, plans with `ensurePrice`, then build sessions with `createCheckoutSession` + `completeCheckout`):
  - `it creates an active support from a completed checkout` — plan_id, price, stripe_status `active`, current_period_end set.
  - `it maps stripe statuses` — dataset: `past_due`→Active, `canceled`→Ended (ended_at set), `unpaid`→Ended, `trialing`→Active.
  - `it reactivates when stripe moves back to active` — unpaid → active: status Active, ended_at null.
  - `it converges regardless of call order` — `sync` three times, and `setSubscriptionStatus('canceled')` then sync twice → 1 row, Ended.
  - `it ignores subscriptions whose metadata does not match the customer` — metadata user_id of another user → null, no rows, warning logged (`Log::spy()`).
  - `it cancels a duplicate subscription for the same creator` — two sessions completed for the same user+creator → second sync returns the first support, `cancelledNow()` contains the second sub id, `Support::count() === 1`, `Log::error` called with `billing.duplicate_subscription`.
  - `it follows a plan change made in stripe` — `changeSubscriptionPrice` to another plan's price then sync → plan_id/price updated, same row.
  - `it ignores incomplete subscriptions`.
  - PaymentRecorder: `it records a paid invoice once` (record twice → 1 payment, amount, fee_percent 10); `it skips zero-amount invoices`.
  - AccountSync: `it enables a creator when stripe reports payouts enabled`; `deauthorize` sets false.
- [ ] **Step 2: Run** both files — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** files + `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: Stripe のサブスクリプション・支払い・口座状態の同期を追加`

---

### Task 5: Starting a support via Checkout (+ dev fake checkout, creator page)

**Files:**
- Create: `app/Services/Billing/CheckoutService.php`, `app/Http/Controllers/Fan/CheckoutCompleteController.php`, `app/Http/Controllers/Dev/FakeCheckoutController.php`, `routes/billing.php`, `resources/js/pages/dev/fake-checkout.tsx`
- Modify: `app/Http/Controllers/Fan/SupportController.php` (`store`), `app/Services/SupportService.php` (remove `start()`), `routes/fan.php`, `routes/web.php` (require `billing.php`), `bootstrap/app.php` (render `BillingException`), `app/Http/Controllers/Public/CreatorPageController.php`, `resources/js/pages/public/creator.tsx`, `resources/js/components/support-button.tsx`, `database/seeders/DatabaseSeeder.php` (demo creator `payable()` using an `acct_fake_` id registered in the fake via `setAccount`)
- Modify tests: `tests/Feature/Fan/SupportTest.php` (rewrite start/switch tests for the new flow), `tests/Feature/SeederTest.php` if it asserts supports
- Test: `tests/Feature/Fan/CheckoutTest.php`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces:
  - `CheckoutService::begin(User $user, Plan $plan): string` (Checkout URL). Validation (`ValidationException` key `plan`, in this order): own creator「自分自身を支援することはできません。」; `! $plan->creator->canAcceptSupports()`「このクリエイターは現在支援を受け付けていません。」; `! is_accepting`「このプランは現在受付を停止しています。」; existing active support for the creator「すでに支援中です。プランの変更は「このプランに変更」から行ってください。」. Creates the customer if missing (lock the user row; save `stripe_customer_id`), `ensurePrice`, expires the previous open session stored in cache key `checkout:{user_id}:{creator_id}` (ignore `BillingException` from expiring), creates the session (`successUrl = route('supports.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}'`, `cancelUrl = route('creators.show', creator)`, metadata `user_id`, `creator_id`, `plan_id` as strings, expiry `now()->addMinutes(config('fanbox.billing.checkout_expires_minutes'))`), stores its id in that cache key for the same minutes.
  - `CheckoutService::complete(User $user, string $sessionId): ?Support` — retrieves the session; `abort(404)` unless `metadata['user_id'] === (string) $user->id`; if `status === 'complete'` and `subscriptionId` → `SubscriptionSync::sync`, forget the cache key; else null.
  - `SupportController::store` returns `Inertia::location($url)`.
  - Route `GET supports/checkout/complete` → `CheckoutCompleteController` name `supports.checkout.complete` (auth, verified). Redirects to `creators.show` of the session's creator with toast success「{display_name} さんの支援を開始しました。」or, when not complete, toast error「お支払いが完了していません。」.
  - `routes/billing.php` registers (only when `config('fanbox.billing.driver') === 'fake'`) `GET dev/fake-checkout/{session}` name `dev.fake-checkout.show` and `POST dev/fake-checkout/{session}` name `dev.fake-checkout.pay` (auth). `pay` calls `FakeGateway::completeCheckout` and redirects to `success_url` with `{CHECKOUT_SESSION_ID}` replaced; page has「支払う」and「キャンセル」(link to `cancel_url`) and shows amount + plan name, title「テスト決済（開発用）」, and a note「これは開発用の偽の決済画面です。実際の請求は発生しません。」.
  - `bootstrap/app.php`: `$exceptions->render(function (BillingException $e, Request $request) { ... })` — for non-JSON requests: `Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。'])` and `return back();`. `report` it.
  - Creator page props add `acceptsSupports: bool` and `viewerSupportCancelling: bool`. `SupportButton` gets `acceptsSupports` prop: when false and the viewer has no support → disabled「準備中」. Starting uses `router.post(...)` unchanged (Inertia follows the location response). Plan cards show「税込」after the price.

- [ ] **Step 1: Write failing tests** in `CheckoutTest.php`:
  - `it sends the fan to stripe checkout` — POST support → `assertStatus(409)` with `X-Inertia-Location` header when sent with `X-Inertia: true` (or `assertRedirect` to the fake URL without it); fan now has `stripe_customer_id`; no Support rows yet.
  - `it activates the support when the fan returns from checkout` — begin, `completeCheckout`, GET complete URL → redirect to creator page, support active, paid post visible via `Entitlement`.
  - `it rejects completing someone else's session` → 404, `Support::count() === 0`.
  - `it refuses creators who cannot accept supports` → `assertSessionHasErrors('plan')`.
  - `it refuses a second checkout while supporting` → errors `plan`.
  - `it expires the previous open checkout session` — begin twice → first session status `expired` in the fake.
  - `it refuses supporting yourself`, `it refuses a plan that is not accepting` (moved from SupportTest).
  - `it shows a toast when stripe is unreachable` — bind a gateway mock whose `createCheckoutSession` throws `BillingException` → redirect back, session flash toast type `error`.
  - `it completes the fake checkout page` — GET show 200 with amount; POST pay → redirect to the complete URL with the real session id. (The driver guard in `routes/billing.php` is checked in review, not by a test.)
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** backend, then frontend (`fake-checkout.tsx` uses `PublicLayout` or the app layout; Wayfinder actions for the pay form).
- [ ] **Step 4: Run** `./vendor/bin/sail artisan wayfinder:generate` if routes changed (it runs in the Vite build), file tests, then `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: Stripe Checkout で支援を開始する`

---

### Task 6: Cancel, resume, change plan, customer portal, supporting page

**Files:**
- Modify: `app/Services/SupportService.php` (methods below), `app/Http/Controllers/Fan/SupportController.php` (`update`, `destroy`, `resume`), `app/Http/Controllers/Fan/SupportingController.php`, `routes/fan.php`
- Create: `app/Http/Controllers/Fan/BillingPortalController.php`
- Modify: `resources/js/pages/fan/supporting.tsx`, `resources/js/components/support-button.tsx`
- Test: `tests/Feature/Fan/ManageSupportTest.php` (and remove superseded cases from `SupportTest.php`)

**Interfaces:**
- Consumes: `SubscriptionSync::apply`, gateway (Tasks 2–4).
- Produces:
  - `SupportService::cancel(Support $support): Support` — dummy (`! isBilling()`): `status = Ended`, `ended_at = now()`; billing: `apply(gateway->setCancelAtPeriodEnd(id, true))`. Ended support → 422「この支援はすでに終了しています。」.
  - `SupportService::resume(Support $support): Support` — requires billing, Active, `cancel_at_period_end` (else 422「再開できる支援がありません。」); `apply(setCancelAtPeriodEnd(id, false))`.
  - `SupportService::changePlan(Support $support, Plan $plan): Support` — 422 rules (key `plan`): plan of another creator → 404 (controller); same plan「すでにこのプランで支援中です。」; dummy「この支援はプラン変更に対応していません。停止してから支援し直してください。」; `cancel_at_period_end`「停止予約中のため変更できません。先に支援を再開してください。」; not accepting「このプランは現在受付を停止しています。」. Needs the current item id: `gateway->retrieveSubscription()`; `chargeProrationNow = $plan->price > $support->price`; `PaymentFailedException` → 422「お支払いに失敗しました。カード情報をご確認ください。」. Result through `apply()`.
  - Routes (auth, verified, throttle 10/min like the existing ones): `PUT supports/{support}` `supports.update` (body `plan_id`), `DELETE supports/{support}` `supports.destroy`, `POST supports/{support}/resume` `supports.resume`, `POST billing/portal` `billing.portal` (→ `Inertia::location(createPortalSession(customer, route('supporting.index')))`; no customer → toast error「お支払い情報がまだありません。」back). Support owned by another user → 404.
  - Toasts: cancel billing「支援の停止を予約しました。{Y年n月j日}まで閲覧できます。」(date from `current_period_end`), cancel dummy「支援を停止しました。」, resume「支援を再開しました。」, change「プランを変更しました。」.
  - `SupportingController` rows add `is_billing`, `stripe_status`, `current_period_end`, `cancel_at_period_end`; page prop `hasBillingAccount` (user has `stripe_customer_id`).
  - `supporting.tsx`: per row「次回のお支払い: {date}」, or when cancelling「{date}に終了」+「再開する」button instead of「支援を停止」; `stripe_status === 'past_due'` → red text「お支払いに失敗しています」+ button「お支払い方法を更新」(portal). Header button「お支払い方法・請求履歴」(portal) when `hasBillingAccount`. Stop confirm text:「支援を停止しますか？次回のお支払い日の前日まで閲覧できます。」for billing rows.
  - `support-button.tsx`: change uses `router.put(SupportController.update.url(viewerSupportId), { plan_id })`; confirm text upgrade「このプランに変更しますか？差額（日割り）がすぐに請求されます。」, downgrade「このプランに変更しますか？今月分の差額は返金されません。」; when `viewerSupportCancelling` other plans show disabled「停止予約中」.

- [ ] **Step 1: Write failing tests** in `ManageSupportTest.php` (helper that runs begin + completeCheckout + complete to get an active billing support):
  - `it schedules cancellation and keeps access until the period ends` — DELETE → `cancel_at_period_end` true, still Active, can view; `advancePeriod` + `sync` → Ended, cannot view.
  - `it resumes a scheduled cancellation`; `it refuses resuming a support that is not cancelling` (422).
  - `it upgrades and charges the difference` — PUT to higher plan → plan_id/price updated; fake has a paid invoice for the difference.
  - `it keeps the old plan when the upgrade payment fails` — `failPayments()` → errors `plan`, plan unchanged.
  - `it downgrades without charging`.
  - `it refuses changing while cancellation is scheduled`, `it refuses changing to a plan that is not accepting`, `it 404s on another creator's plan`, `it 404s on someone else's support`.
  - `it ends a dummy support immediately` (factory support without stripe id).
  - `it opens the customer portal` — POST → location header to the return URL (fake).
  - `it lists billing details on the supporting page` — Inertia assert props `supports.0.cancel_at_period_end`, `current_period_end`, `hasBillingAccount`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** backend then frontend.
- [ ] **Step 4: Run** file + `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: 支援の停止予約・再開・プラン変更とお支払い管理を追加`

---

### Task 7: Webhooks

**Files:**
- Create: `app/Http/Controllers/Billing/StripeWebhookController.php`, `app/Jobs/ProcessStripeEvent.php`, `app/Models/StripeEvent.php`
- Modify: `routes/billing.php`, `bootstrap/app.php` (`$middleware->validateCsrfTokens(except: ['stripe/*'])`)
- Test: `tests/Feature/Billing/WebhookTest.php`, helper `function stripeSignature(string $payload, string $secret): string` in `tests/Pest.php` (`t=time,v1=hash_hmac('sha256', "{t}.{payload}", secret)`)

**Interfaces:**
- Consumes: `SubscriptionSync`, `PaymentRecorder`, `AccountSync` (Task 4).
- Produces:
  - `POST stripe/webhook` → `StripeWebhookController@platform` (`stripe.webhook`), `POST stripe/connect-webhook` → `@connect` (`stripe.connect-webhook`). Registered regardless of driver; no auth.
  - Both verify with `\Stripe\Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret)` using `config('fanbox.billing.stripe.webhook_secret')` / `connect_webhook_secret`; `SignatureVerificationException` or `UnexpectedValueException` → 400. Missing secret → 500 + log.
  - `StripeEvent::query()->insertOrIgnore(['id', 'type', timestamps])`; 0 rows → return 200 without dispatching.
  - Dispatch `ProcessStripeEvent(string $eventId, string $type, ?string $objectId, ?string $subscriptionId, ?string $accountId)`; `objectId` = `data.object.id`, `subscriptionId` = `data.object.subscription` for `checkout.session.completed`, `accountId` = event `account`. Return 200 `''`.
  - `ProcessStripeEvent::handle(SubscriptionSync, PaymentRecorder, AccountSync, PaymentGateway)` routes per spec §7.6 table (`invoice.payment_failed` → `retrieveInvoice($objectId)->subscriptionId` then sync; `account.updated` → `AccountSync::sync($objectId)`; `account.application.deauthorized` → `deauthorize($accountId)`), then sets `processed_at`. `$tries = 5`, `backoff = [10, 60, 300, 900]`. Unknown types: just mark processed.

- [ ] **Step 1: Write failing tests**:
  - `it rejects an invalid signature` (400, no `stripe_events` row).
  - `it syncs a subscription on customer.subscription.updated` — complete a checkout in the fake (support row exists), then `setSubscriptionStatus('canceled')`, POST signed event → Ended, `processed_at` not null.
  - `it creates the support from checkout.session.completed` without visiting the complete URL.
  - `it records a payment on invoice.paid`.
  - `it ignores a replayed event id` — POST same event twice → `Payment::count() === 1` and the job ran once (`Queue::fake()` variant: second POST dispatches nothing).
  - `it enables a creator on account.updated` via the connect endpoint with the connect secret; the platform secret is rejected there.
  - `deauthorization stops accepting supports`.
  - `it acknowledges unknown events`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** file + `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: Stripe Webhook を受け取って状態を同期する`

---

### Task 8: Creator onboarding and earnings

**Files:**
- Create: `app/Http/Controllers/Creator/EarningsController.php`, `resources/js/pages/creator/earnings.tsx`
- Modify: `routes/creator.php`, `resources/js/components/app-sidebar.tsx` (item「収益」, icon `Wallet`, after「支援者」), `app/Http/Controllers/Creator/PlanController.php` (prop `acceptsSupports`), `resources/js/pages/creator/plans/index.tsx` (notice)
- Test: `tests/Feature/Creator/EarningsTest.php`

**Interfaces:**
- Consumes: gateway, `AccountSync` (Tasks 2, 4), `Payment` (Task 1).
- Produces:
  - Routes under `creator.` with `creator` middleware: `GET earnings` `creator.earnings.index`; `POST earnings/onboarding` `creator.earnings.onboarding`; `GET earnings/onboarding/return` `creator.earnings.onboarding.return`; `POST earnings/dashboard` `creator.earnings.dashboard`.
  - `onboarding`: create the Connect account if `stripe_account_id` is null (save it), then `Inertia::location(createAccountOnboardingLink(id, refresh = route('creator.earnings.index'), return = route('creator.earnings.onboarding.return')))`.
  - `return`: `AccountSync::sync`, redirect to index with toast success「口座の登録が完了しました。」when payable, otherwise info「登録はまだ完了していません。続きから再開できます。」.
  - `dashboard`: requires account (else 404) → `Inertia::location(createDashboardLoginLink(id))`.
  - `index` props: `account: { status: 'none' | 'incomplete' | 'ready' }` (`none` = no account id, `ready` = `canAcceptSupports()`), `feePercent: int`, `activeSupporters: int`, `months: list<{ month: 'YYYY-MM', gross: int, fee: int, net: int }>` — the 12 calendar months ending with the current one (Asia/Tokyo), newest first, zero-filled; `fee` = sum over payments of `intdiv(amount * fee_percent, 100)`; `net = gross - fee`.
  - Page copy: title「収益」; none「支援を受け付けるには、Stripe で受取口座を登録してください。」+ button「Stripe で口座を登録する」; incomplete「口座の登録が完了していません。」+「登録を続ける」; ready「口座は登録済みです。入金の履歴や振込先の変更は Stripe のダッシュボードで行えます。」+「Stripe ダッシュボードを開く」. Table columns「月」「売上（税込）」「運営手数料（目安）」「受取額（目安）」; note「手数料は概算です。正確な入金額は Stripe ダッシュボードでご確認ください。決済手数料は運営手数料に含まれます。」
  - Plans page notice when `! acceptsSupports`:「受取口座を登録するまで、プランへの支援を受け付けられません。」with link「収益ページへ」.

- [ ] **Step 1: Write failing tests**:
  - `it starts onboarding and stores the account` — POST → location to the return URL (fake), `stripe_account_id` saved; second POST reuses the same id.
  - `it marks the creator ready after returning from onboarding` — GET return → `payouts_enabled` true, redirect index.
  - `it shows twelve months of earnings` — payments in this month (1000 + 500) and 13 months ago → `months` length 12, `months.0` gross 1500 fee 150 net 1350, old payment excluded.
  - `it counts active supporters`.
  - `it hides the dashboard link without an account` (POST dashboard → 404).
  - `it is only for creators` (non-creator → existing creator-middleware behaviour).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** backend then frontend.
- [ ] **Step 4: Run** file + `composer ci:check`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: クリエイターの口座登録と収益画面を追加`

---

### Task 9: Ops doc, ledger, end-to-end check

**Files:**
- Create: `docs/operations/stripe-setup.md`
- Create: `docs/superpowers/reports/2026-11-01-fanbox-payments-execution-ledger.md`

- [ ] **Step 1: Write `stripe-setup.md`** (Japanese): enabling Connect (Express, JP) and platform profile; API keys into `.env`; two webhook endpoints (`/stripe/webhook` with events `checkout.session.completed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed`; `/stripe/connect-webhook` with "Events on Connected accounts" `account.updated, account.application.deauthorized`) and their secrets; Billing → subscriptions settings "cancel the subscription if all retries fail"; customer portal: allow payment-method update and invoice history, disable cancellation and plan switching; enable email receipts; local testing with Stripe CLI `stripe listen --forward-to localhost:8080/stripe/webhook` and `--forward-connect-to localhost:8080/stripe/connect-webhook`; running a queue worker (`sail artisan queue:work`); pre-launch requirements still open (特商法表記・規約同意 in sub-project 3).
- [ ] **Step 2: Browser check with the fake driver** (`BILLING_DRIVER=fake`, `sail artisan migrate:fresh --seed`): log in as fan@example.com → demo creator → support 500 plan → fake checkout →「支払う」→ toast and unlocked post → /supporting shows next payment → change to 1000 → stop (shows end date) → resume. Log in as creator@example.com → 収益 shows ready + this month's gross.
- [ ] **Step 3: Full** `./vendor/bin/sail composer ci:check`. Expected: PASS.
- [ ] **Step 4: Commit** `docs: Stripe の設定手順と実装記録を追加`
