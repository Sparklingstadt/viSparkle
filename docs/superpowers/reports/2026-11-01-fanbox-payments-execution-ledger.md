# サブプロジェクト2（決済）実装時の判断記録（台帳）

subagent-driven-development の進行台帳をそのまま保存したもの。`Ruling:` 行が実装中に下した判断、`minor (deferred)` / `parked` 行が先送りした指摘。

Spec: docs/superpowers/specs/2026-11-01-fanbox-payments-design.md
Branch: feat/payments (spec 935c8d4, plan a24f017)
Blocked before Task 1: Bash sandbox denies the Docker socket (~/.docker/run/docker.sock), so Sail/tests cannot run. Waiting for the user to allow it.
Unblocked: user allowed the Docker socket; PR #5 (rename) merged, feat/payments rebased onto origin/main abd1437. Baseline 230 tests green.

## Pre-flight scan

| Pair / task    | Produces → consumes                                                                | Finding                                     |
| -------------- | ---------------------------------------------------------------------------------- | ------------------------------------------- |
| T1→T4/T5/T6/T8 | columns, canAcceptSupports, isBilling, factory states                              | consistent                                  |
| T2→T3          | interface + DTOs; T3 edits T2's provider                                           | consistent                                  |
| T2→T5          | FakeGateway checkout url uses route `dev.fake-checkout.show`, defined only in T5   | CONFLICT: route() throws in T2 tests        |
| T2→T8          | FakeGateway dashboard link uses route `creator.earnings.index`, defined only in T8 | CONFLICT: same                              |
| T4→T5/T6/T7    | SubscriptionSync::sync/apply, PaymentRecorder::record, AccountSync                 | consistent                                  |
| T5↔T6          | both edit SupportController, support-button.tsx, SupportTest                       | sequential, ok                              |
| T5↔T7          | both edit routes/billing.php, bootstrap/app.php                                    | sequential, ok                              |
| T1 self        | tests vs schema                                                                    | ok                                          |
| T2 self        | fake controls vs tests                                                             | ok (prod guard test uses detectEnvironment) |
| T3 self        | recording HTTP client signature left to installed version                          | ok                                          |
| T4 self        | duplicate test uses gateway directly (T5 would refuse)                             | ok                                          |
| T5 self        | test list vs files                                                                 | ok                                          |
| T6 self        | ok                                                                                 | ok                                          |
| T7 self        | ok                                                                                 | ok                                          |
| T8 self        | fake onboarding returns returnUrl → tests                                          | ok                                          |
| T9 self        | doc + manual check                                                                 | ok                                          |

Ruling: FakeGateway builds URLs with url('/dev/fake-checkout/{id}') and url('/creator/earnings') instead of route names — route names are defined in later tasks (T5, T8) — if wrong, only a cosmetic difference (same paths).
Task 1: dispatched (BASE 8f8de41)
Ruling: supports.current_key is a VIRTUAL generated column (not stored) — MySQL 8 forbids stored generated columns over FK-cascade columns (err 1215); virtual + unique index enforces the same rule — if wrong, nothing functional is lost (index enforces uniqueness either way). Spec/plan text updated in a docs commit.
Ruling: Task 1 extras (payments (creator_id, paid_at) index, isBilling and cascade tests) accepted — needed by Task 8 monthly queries; cheap.
Task 1: complete (commits 8f8de41..06e360a, review clean; docs fix 63d10b8)
Task 1: minor (deferred): Creator::canAcceptSupports() returns null→TypeError on a fresh model with stripe_account_id set but payouts_enabled unloaded — carry fix `(bool)` into Task 5 dispatch
Task 1: minor (deferred): PaymentFactory forCreator() unrequested/inconsistent; findOrFail x3; down() split dropColumn; composer plugin-api-version noise; unique virtual index fails on pre-existing duplicate active rows (deploy note); no relation tests
Task 2: dispatched (BASE 63d10b8)
Ruling: both gateway drivers' ensurePrice persist stripe_product_id/stripe_price_id on the plan — SubscriptionSync maps price→plan via plans.stripe_price_id — if wrong, fake-driver plan changes would not update plan_id. FakeGateway builds /dev/fake-checkout/{id} and /creator/earnings by path (routes come later).
Task 2: complete (commits 63d10b8..bf88895, review clean)
Task 2: minor (deferred): fakeBilling() doc says clean state but doesn't flush cache; fake ignores itemId / expires_at; fake state RMW has no lock (dev only); fake keeps old amount if plan price changed (plan prices are immutable, so moot)
Task 3: dispatched (BASE bf88895)
Task 3: review — Important: gateways return UTC CarbonImmutable while app tz is Asia/Tokyo (both drivers). Fix round 1 dispatched with it.
Ruling: CardException on upgrade maps to PaymentFailedException; missing Stripe secret → BillingException('Stripe secret is not configured.') — keeps spec §7.4/§11 messages correct if Stripe answers 402 — if wrong, only a different error message.
Task 3: minor (deferred): half-saved price (only one id stored) recreates both objects; manual Stripe test-mode checks needed (declined upgrade returns 200+pending_update vs 402; fate of the leftover open proration invoice after a failed upgrade; JP Express with transfers-only capability) — put in Task 9 ops doc
Task 3: fix round 1/5 (4 addressed, 0 open; commits f38fd2f..2219c9f)
Task 3: complete (commits bf88895..2219c9f, review clean)
Task 3: minor (deferred): FakeGateway advancePeriod computes next period end from a UTC instance (fake realism only)
Task 4: dispatched (BASE 2219c9f)
Ruling: a subscription first seen in an ended-type status creates an Ended support row (spec §5 only forbids rows for `incomplete`) — lets invoice.paid of such subscriptions still be attributed to a creator — if wrong, a few extra ended rows in history.
Carry to Tasks 5–7: never wrap SubscriptionSync::apply()/sync() in a caller transaction (duplicate cancel must run after commit).
Task 4: review — Important: (1) RR snapshot taken before user lock → duplicate pre-check misses concurrent rows; (2) gap-lock deadlock on first sync, no retry. Fix round 1 dispatched with both + rulings below.
Ruling: metadata plan_id of another creator → billing.metadata_mismatch, no row; paid invoice with no attributable support → Log::warning('billing.payment_unattributed') — money paths must never fail silently — if wrong, only extra log lines / a refused malformed row.
Task 4: minor (deferred): a duplicate subscription's invoice.paid may attach to the original support (event-order dependent) — note for sub-project 3 refund tooling
Ruling: metadata plan deleted after checkout → row still created with plan_id null — the fan has paid, the support must exist — if wrong, a support row without plan (UI already shows （削除されたプラン）).
Task 4: fix round 1/5 (4 addressed, 0 open; commits e3c8cfb..db0f900)
Task 4: complete (commits 2219c9f..db0f900, review clean)
Task 4: minor (deferred): strict int compare of plan creator_id relies on native-int fetch (Plan has no creator_id cast)
Task 5: dispatched (BASE db0f900)
Ruling: FakeGateway::setAccount upserts unknown accounts (needed by the seeder) — dev/test double only — if wrong, tests can't detect unknown-account bugs via setAccount.
Task 5: complete (commits db0f900..79b1904, review clean)
Ruling: commit trailers naming the subagent's actual model (Sonnet 5.5) are kept — accurate attribution of who wrote the commit — if wrong, cosmetic history only.
Task 5: minor (deferred): fake pay/show don't check session owner (dev only); fake-only seeder writes acct_fake_demo regardless of driver; complete() unused; (bool) test doesn't exercise cast; BillingException renderer predicate differs from shouldRenderJsonWhen; forged session_id shows "unreachable" toast
Ruling: add throttle to supports.checkout.complete in Task 6 (it touches routes/fan.php) — spec §9 rate limit for support operations — cost: none.
Task 6: dispatched (BASE 79b1904)
Task 6: complete (commits 79b1904..b461076, review clean)
Task 6: minor (deferred): supporting.tsx resume/stop buttons lack onError + processing state (422 silently dropped, double click) — user-facing, triage at final
Task 6: minor (deferred): throttle:10,1 shared bucket across store/complete/update/destroy/resume/portal — could 429 the post-payment return page — triage at final
Task 6: minor (deferred): no tests for resume/changePlan on Ended supports; BillingException path only tested for cancel; portal tests don't assert customer id
Task 7: dispatched (BASE b461076)
Task 7: review — Important: event row insert + dispatch not atomic → failed dispatch makes redeliveries look like replays (event lost).
Ruling: fix by re-dispatching on redelivery when the existing stripe_events row has processed_at null (handlers idempotent), not by a DB transaction — a transaction would wrap SubscriptionSync under the sync queue, violating Task 4's rule — if wrong, an unprocessed event may be dispatched twice (harmless: idempotent).
Ruling: BillingException renderer returns null for stripe/* (Task 7 extra) accepted — Stripe must get a 5xx, not a redirect — cost: none.
Task 7: minor (deferred): job doesn't check which endpoint an event came from — document exact event sets per endpoint in Task 9 ops doc
Task 7: fix round 1/5 (2 addressed, 0 open; commits fe8c0f9..3aa2bfe)
Task 7: complete (commits b461076..3aa2bfe, review clean)
Task 8: dispatched (BASE 3aa2bfe)
Task 8: complete (commits 3aa2bfe..49af3a5, review clean)
Ruling: keep the extra earnings heading「運営手数料は売上の N% です。」 — fee transparency for creators, consistent with spec §7.5 intent — if wrong, remove one line.
Task 8: minor (deferred): duplicated BillingException try/catch in onboarding/dashboard (global renderer already does it); no test for one-account-per-creator idempotency or gateway error paths
Task 9: dispatched (BASE 49af3a5) — doc by subagent; browser e2e by controller
Task 9: browser e2e (fake driver) by controller: support 500 via fake checkout → toast + ¥300 post unlocked; upgrade to 1000 (confirm text ok, toast プランを変更しました。); /supporting next payment date, stop → 「2026年12月1日に終了」+ toast, resume ok; creator /creator/earnings ready, 1 active supporter, 12 months — gross ¥0 because locally no invoice.paid webhook records payments.
Task 9: minor (final-fix candidate): stop confirm copy「次回のお支払い日の前日まで閲覧できます。」contradicts toast/UI「12月1日まで」 — plan copy error; change to「次回のお支払い日まで閲覧できます。」
Task 9: minor (final-fix candidate): fake checkout pay doesn't record the payment (no webhook locally) → earnings stays ¥0 in dev; have FakeCheckoutController record the latest invoice via PaymentRecorder
Task 9: minor (final-fix candidate): stripe-setup.md §5.2 claims Sail default port 8080 (default is 80; 8080 needs APP_PORT); §6.3 says「受け取り可能」but UI says「口座は登録済みです。」
Task 9: complete (commits 49af3a5..a99082f, review clean; e2e done by controller)
Final review: With fixes — Critical: account deletion leaves Stripe subscriptions charging (fan and creator); Important: checkout expires_at exactly at Stripe's 30-min minimum; fake-driver production guard only at first resolve (spec says at boot) and dev routes registered in prod. Must-fix minors: stop-confirm copy; ops doc port/wording. Final fix wave dispatched (FIX_BASE a99082f).
Ruling: account deletion is refused (not auto-cancelled) while the user has an active billing support without cancel scheduled, or while the creator has such supporters — refusing is reversible and keeps money decisions with the user/operator; auto-cancel+delete is SP3 (退会) — if wrong, users must stop supports before deleting.
Ruling: checkout complete route moves to its own limiter (throttle:30,1 with prefix) — reviewer: a 429 right after payment is the worst place — cost: none.
Ruling: Stripe expires_at sent as now+31min; cache TTL for the open-session key stays 30 — Stripe minimum is 30 min from its own clock — cost: none.
Final fix wave: re-review all 9 addressed (commits a99082f..403a9de); full ci:check 376/376.
Final: parked — shared processingId in supporting.tsx re-enables another row early — Ruling: cosmetic; server throttle and 422 rules still guard — fix in a later UI pass.
Final: parked — live-key switch note omits supports.stripe_subscription_id / test-mode active supports — Ruling: doc recommends a fresh DB, which covers it — add the column to the list later.
Final: parked — 1-minute window (Stripe session 31 min vs cache 30) lets a second checkout start while the first is still payable — Ruling: accepted trade-off; SubscriptionSync cancels the duplicate, refund is manual (SP3).
