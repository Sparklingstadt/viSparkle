# ショップの購入時の応援 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 購入前の確認画面で応援額を選ぶと、注文に応援額が加わり、応援分には別の手数料率をかけて Stripe Checkout で一緒に支払えるようにする。

**Architecture:** `orders` に `support_amount` と `support_fee_percent`、`creators` に `accepts_support` を足す。手数料の計算は `Order::platformFeeAmount()` に1つにまとめ、Checkout（`OrderCheckout`）と売上の集計（`EarningsController::shopMonths`）の両方から使う。Checkout には応援を明細の1行として足すだけで、Webhook・照合ジョブ・返金は変えない。

**Tech Stack:** Laravel 13 / stripe-php ^22 / Pest 4 / Inertia v3 + React 19 + TypeScript / Wayfinder / MySQL 8（テストも MySQL、`RefreshDatabase`）

**Spec:** `docs/superpowers/specs/2026-11-03-shop-support-design.md`（先に読むこと。以下の「§」は設計書の節番号）。前提として `docs/superpowers/specs/2026-11-02-shop-goods-design.md` の §5・§6 も読む

## Global Constraints

- コマンドはすべて Sail のコンテナで動かす: `./vendor/bin/sail artisan test <file>`、`./vendor/bin/sail composer ci:check`（Pint・PHPStan level 7・Pest・ESLint/Prettier/tsc）。ホストの `npm` は使わない
- コミットの前に `./vendor/bin/sail composer ci:check` を通す。作業中は対象のテストファイルだけを先に動かす
- コミットメッセージは日本語の Conventional Commits（`feat: ...`）。末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- ブランチ: `feat/shop-digital`（このまま続ける）
- 応援額: 0、または `config('fanbox.billing.support_amount_min')` = 100 〜 `support_amount_max` = 50000 の整数（円・税込）
- 定額ボタン: `config('fanbox.billing.support_amount_presets')` = `[100, 300, 500, 1000, 3000]`
- 応援分の手数料率: `config('fanbox.billing.support_fee_percent')` = `(int) env('BILLING_SUPPORT_FEE_PERCENT', 5)`
- 手数料: `intdiv((subtotal + shipping_fee) * fee_percent, 100) + intdiv(support_amount * support_fee_percent, 100)`。どちらの率も注文に保存した値を使う
- `orders.total` = `subtotal + shipping_fee + support_amount`
- Checkout の明細行の名前: `クリエイターへの応援`（送料の行のあと。応援が 0 なら行を足さない）
- 画面の文言（そのまま使う）: 「クリエイターを応援する（任意）」「なし」「金額を入力」「応援」「応援あり」「購入時の応援を受け付ける」「応援額は全額返金の場合を除き返金されません。応援によって特典は付きません」
- エラーの文言: 範囲外「応援額は0円、または100円から50,000円で指定してください。」、受付オフ「このクリエイターは現在応援を受け付けていません」（どちらもキー `support_amount`）
- 既存のやり方に合わせる: モデルは `#[Fillable]`、新しいカラムには `@property` と `casts`、`Inertia::flash('toast', [...])`、FormRequest の `attributes()`

## Review Focus

1. 確認画面を開いたあとにクリエイターが受付をオフにした → 応援付きの送信は `support_amount` のエラーで確認画面に戻り、応援なしなら購入手続きに進める（Task 2）
2. 自由入力の境界と不正な値（`99`、`50001`、`-100`、`"300円"`、空文字）→ 空文字と未送信は 0 として扱い、ほかはエラー。`100` と `50000` は通る（Task 2）
3. 応援なし（0 円）の注文 → Checkout の明細と `application_fee_amount` が今までと完全に同じ（Task 2）
4. 端数: 商品 1,801 円・送料 0・応援 333 円・率 10% / 5% → 手数料は 180 + 16 = 196（合計 2,134 円に 10% をかけた 213 ではない）（Task 1）
5. 同じクリエイターの `pending` を置き換えたとき → 新しい注文は今回の応援額だけを持ち、前の応援額を引き継がない（Task 2）

---

### Task 1: 応援の列・設定・手数料の計算

**Files:**

- Create: `database/migrations/2026_11_03_000005_add_support_to_orders_and_creators.php`
- Modify: `app/Models/Order.php`、`app/Models/Creator.php`、`config/fanbox.php`（`billing`）、`.env.example`、`database/factories/OrderFactory.php`
- Test: `tests/Feature/Models/OrderFeeTest.php`

**Interfaces:**

- Produces:
    - `orders.support_amount`（unsigned int、default 0）、`orders.support_fee_percent`（unsigned int、default 0）。`Order` の `@property int`・`casts` `integer`・`#[Fillable]` に足す
    - `creators.accepts_support`（boolean、default true）。`Creator` の `@property bool`・`casts` `boolean`・`#[Fillable]` に足す
    - `Order::platformFeeAmount(): int` — Global Constraints の式
    - `OrderFactory::withSupport(int $amount = 500): static` — `support_amount`、`support_fee_percent`（設定値）、`total` に足した値を入れる
    - 設定: `fanbox.billing.support_fee_percent`、`support_amount_min`、`support_amount_max`、`support_amount_presets`

- [ ] **Step 1: Write the failing test** — `tests/Feature/Models/OrderFeeTest.php`

```php
it('charges the platform rate on goods and shipping and the support rate on support', function () {
    $order = Order::factory()->make([
        'subtotal' => 1801, 'shipping_fee' => 0, 'support_amount' => 333,
        'total' => 2134, 'fee_percent' => 10, 'support_fee_percent' => 5,
    ]);

    expect($order->platformFeeAmount())->toBe(180 + 16);
});

it('matches the old fee when there is no support', function () {
    $order = Order::factory()->make(['subtotal' => 3600, 'shipping_fee' => 600, 'total' => 4200, 'fee_percent' => 10]);

    expect($order->platformFeeAmount())->toBe(intdiv(4200 * 10, 100));
});

it('defaults new columns', function () {
    expect(Order::factory()->create()->fresh())
        ->support_amount->toBe(0)->support_fee_percent->toBe(0)
        ->and(Creator::factory()->create()->fresh()->accepts_support)->toBeTrue();
});
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Models/OrderFeeTest.php` — FAIL（`platformFeeAmount` が未定義、列がない）
- [ ] **Step 3: Implement** — マイグレーション（`down` で列を落とす）、モデルの変更、`platformFeeAmount()`、設定4つ、`.env.example` に `BILLING_SUPPORT_FEE_PERCENT=5`（`BILLING_PLATFORM_FEE_PERCENT` の次の行）、`OrderFactory::withSupport()`
- [ ] **Step 4: Run** 同じコマンド — PASS
- [ ] **Step 5: Commit** — `feat: 注文に応援額と応援分の手数料率を持たせる`

---

### Task 2: 購入手続きで応援額を受け取り、Checkout に載せる

**Files:**

- Create: `app/Http/Requests/Fan/StartOrderCheckoutRequest.php`
- Modify: `app/Http/Controllers/Fan/OrderCheckoutController.php`（`store`）、`app/Services/Shop/OrderCheckout.php`（`begin`、`beginLocked`、`lineItems`、`applicationFeeAmount`）、`app/Services/Shop/Orders.php`（`reserve`）、`tests/Pest.php`（`fakeOrderCheckout`）
- Test: `tests/Feature/Fan/OrderCheckoutTest.php`、`tests/Feature/Creator/OrderManagementTest.php`

**Interfaces:**

- Consumes: `Order::platformFeeAmount()`、`creators.accepts_support`、設定（Task 1）
- Produces:
    - `OrderCheckout::begin(User $buyer, Creator $creator, int $supportAmount = 0): string`
    - `Orders::reserve(User $buyer, Creator $creator, Collection $cartItems, int $supportAmount = 0): Order` — `support_amount`、`support_fee_percent`（その時点の設定値）を保存し、`total` に含める
    - `StartOrderCheckoutRequest::supportAmount(): int` — 空文字・未送信は 0
    - リクエストの項目: `support_amount`

- [ ] **Step 1: Write the failing tests** — `OrderCheckoutTest.php` に足す（`beginOrderCheckout` に `array $data = []` を足し、`post(route('cart.checkout', $creator), $data)` にする）

```php
it('adds the support to the order and to checkout with its own fee', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $sessionId = beginOrderCheckout($this->buyer, $this->creator, ['support_amount' => 500]);

    $order = Order::sole();
    $raw = Cache::get('fake-billing:state')['sessions'][$sessionId];
    expect($order)->support_amount->toBe(500)->support_fee_percent->toBe(5)->total->toBe(4200 + 500)
        ->and(end($raw['line_items']))->toMatchArray(['name' => 'クリエイターへの応援', 'unit_amount' => 500, 'quantity' => 1])
        ->and($raw['application_fee_amount'])->toBe(intdiv(4200 * 10, 100) + intdiv(500 * 5, 100));
});

it('keeps checkout unchanged without support', function () { /* 空文字と未送信の両方: support_amount 0、明細は商品＋送料だけ、手数料は intdiv(4200 * 10, 100) */ });

it('rejects support out of range', function (mixed $amount) {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->from(route('cart.confirm', $this->creator))
        ->post(route('cart.checkout', $this->creator), ['support_amount' => $amount])
        ->assertRedirect(route('cart.confirm', $this->creator))
        ->assertSessionHasErrors(['support_amount' => '応援額は0円、または100円から50,000円で指定してください。']);
    expect(Order::count())->toBe(0);
})->with([99, 50001, -100, '300円']);

it('accepts the bounds', function (int $amount) { /* 100 と 50000 で Order の support_amount がその値 */ })->with([100, 50000]);

it('refuses support when the creator does not accept it, but still sells', function () {
    $this->creator->update(['accepts_support' => false]);
    // support_amount 300 → エラー「このクリエイターは現在応援を受け付けていません」、注文なし
    // support_amount 0 → beginOrderCheckout が通り、support_amount 0
});

it('does not carry the support over when replacing a pending order', function () {
    // 1回目 support_amount 1000 → 2回目 support_amount 0 で開始
    // 1回目の注文は canceled（abandoned）、2回目の注文の support_amount は 0、total は 4200
});
```

`OrderManagementTest.php` に足す:

```php
it('refunds the support along with the goods when the creator cancels', function () {
    // Order::factory()->withSupport(500)->paid()->withItem(...) を fakeOrderCheckout で決済し、クリエイターが取り消す
    // refunded_amount が total（商品＋送料＋500）と同じ、状態は refunded
});
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Fan/OrderCheckoutTest.php tests/Feature/Creator/OrderManagementTest.php` — 新しいテストが FAIL
- [ ] **Step 3: Implement `StartOrderCheckoutRequest`** — ルール: `['nullable', 'integer', 'min:0', 'max:{max}', クロージャ]`。クロージャは 1〜(min−1) を範囲外の文言で弾き、0 より大きいのに `route('creator')->accepts_support` が false なら受付オフの文言で弾く。`integer`・`min`・`max` の失敗も範囲外の文言にする（`messages()`）。`attributes()` は `['support_amount' => '応援額']`
- [ ] **Step 4: Implement** — コントローラは `begin($request->user(), $creator, $request->supportAmount())`。`reserve` に `$supportAmount` を渡す。`lineItems` の最後に応援の行。`applicationFeeAmount: $order->platformFeeAmount()`。`tests/Pest.php` の `fakeOrderCheckout` も同じ明細と `platformFeeAmount()` に揃える
- [ ] **Step 5: Run** Step 2 のコマンドと `./vendor/bin/sail artisan test tests/Feature/Shop tests/Feature/Billing tests/Feature/Console/ReconcileOrdersTest.php` — PASS（既存の注文まわりが壊れていないこと）
- [ ] **Step 6: Commit** — `feat: 購入手続きで応援額を受け取り、Checkout の明細と手数料に反映する`

---

### Task 3: ショップ設定で応援の受付をオン・オフする

**Files:**

- Modify: `app/Http/Controllers/Creator/ShopSettingsController.php`、`app/Http/Requests/Creator/UpdateShopSettingsRequest.php`、`resources/js/pages/creator/shop.tsx`
- Test: `tests/Feature/Creator/ShopSettingsTest.php`

**Interfaces:**

- Consumes: `creators.accepts_support`、`fanbox.billing.support_fee_percent`（Task 1）
- Produces: ページの props `shop.accepts_support: boolean`、`support_fee_percent: number`。リクエストの項目 `accepts_support`（`required`、`boolean`、属性名「応援の受付」）

- [ ] **Step 1: Write the failing tests** — 表示（`shop.accepts_support` が true、`support_fee_percent` が 5）、`accepts_support: false` を保存できること。既存の `saves shop settings` と `allows empty optional fields` の送信に `'accepts_support' => true` を足す
- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Creator/ShopSettingsTest.php` — FAIL
- [ ] **Step 3: Implement** — コントローラとリクエスト。`shop.tsx` に「購入時の応援を受け付ける」のチェックボックス（`components/ui/checkbox.tsx`）と説明「応援分の手数料は{support_fee_percent}%です。オフにしても、作成済みの注文は変わりません。」
- [ ] **Step 4: Run** 同じコマンド — PASS
- [ ] **Step 5: Commit** — `feat: ショップ設定で購入時の応援の受付をオン・オフできるようにする`

---

### Task 4: 確認画面で応援額を選ぶ・特定商取引法ページ

**Files:**

- Modify: `app/Http/Controllers/Fan/CartController.php`（`confirm`）、`resources/js/pages/fan/cart-confirm.tsx`、`resources/js/pages/legal/tokushoho.tsx`
- Test: `tests/Feature/Fan/OrderCheckoutTest.php`

**Interfaces:**

- Consumes: `creators.accepts_support`、設定（Task 1）、リクエストの項目 `support_amount`（Task 2）
- Produces: `fan/cart-confirm` の props `support: { presets: number[], min: number, max: number } | null`（受付オフなら `null`）

- [ ] **Step 1: Write the failing tests** — 既存の `shows the confirm page` に `->where('support.presets', [100, 300, 500, 1000, 3000])->where('support.min', 100)->where('support.max', 50000)` を足す。受付オフのクリエイターでは `->where('support', null)`
- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Fan/OrderCheckoutTest.php` — FAIL
- [ ] **Step 3: Implement `confirm`** の props
- [ ] **Step 4: Implement `cart-confirm.tsx`** — `support` があるときだけ「クリエイターを応援する（任意）」欄: 「なし」＋定額ボタン（`components/ui/toggle-group.tsx` の単一選択）＋「金額を入力」（`type="number"`、`min`/`max`/`step=1`）。既定は「なし」。内訳に「応援」の行（0 なら出さない）を足し、合計を `group.total + 応援額` にする。注記の文言を欄の下に。送信は Inertia の `useForm` で `support_amount`（なしは 0）を送り、`errors.support_amount` を欄の下に出す
- [ ] **Step 5: Implement `tokushoho.tsx`** — 「商品代金以外の必要料金」の本文の末尾に「グッズ・デジタル作品の購入時に、任意でクリエイターへの応援額を追加できます。追加した金額は購入前の確認画面に表示し、商品代金と一緒にお支払いいただきます。」を足す
- [ ] **Step 6: Run** Step 2 のコマンド — PASS。`./vendor/bin/sail npm run types:check` — エラーなし
- [ ] **Step 7: 画面の確認** — 開発サーバーで確認画面を開き、ボタンと自由入力で合計が変わること、受付オフで欄が出ないこと、範囲外の入力でエラーが欄の下に出ることを確かめる
- [ ] **Step 8: Commit** — `feat: 購入前の確認画面で応援額を選べるようにする`

---

### Task 5: 注文の詳細・一覧・管理画面・メールに応援を出す

**Files:**

- Modify: `app/Http/Controllers/Fan/OrderController.php`（`show`）、`app/Http/Controllers/Creator/OrderController.php`（`index`、`show`）、`app/Http/Controllers/Admin/PaymentController.php`（注文の一覧）、`resources/js/components/order-totals.tsx`、`resources/js/pages/fan/orders/show.tsx`、`resources/js/pages/creator/orders/show.tsx`、`resources/js/pages/creator/orders/index.tsx`、`resources/js/pages/admin/payments.tsx`、`resources/views/mail/shop/_summary.blade.php`
- Test: `tests/Feature/Fan/OrdersTest.php`、`tests/Feature/Creator/OrderManagementTest.php`、`tests/Feature/Admin/PaymentAdminTest.php`、`tests/Feature/Shop/OrderMailTest.php`

**Interfaces:**

- Consumes: `orders.support_amount`、`OrderFactory::withSupport()`（Task 1）
- Produces: 注文の props に `support_amount: number`（購入者の詳細・クリエイターの一覧と詳細・管理画面の注文の一覧）。`OrderTotals` に `supportAmount?: number`

- [ ] **Step 1: Write the failing tests** — `withSupport(500)` の注文で: 購入者の詳細とクリエイターの詳細・一覧の props に `support_amount` 500、管理画面の注文の一覧に 500。`OrderMailTest` で注文確定メール（購入者・クリエイター。`_summary` を使う発送・返金のメールにも同じ行が出る）の本文に「応援: 500円」が入り、応援なしの注文には「応援」が入らないこと
- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Fan/OrdersTest.php tests/Feature/Creator/OrderManagementTest.php tests/Feature/Admin/PaymentAdminTest.php tests/Feature/Shop/OrderMailTest.php` — FAIL
- [ ] **Step 3: Implement** — コントローラの props。`OrderTotals` は送料の次に「応援」の行（`supportAmount` が 0 より大きいときだけ）。クリエイターの注文一覧で `support_amount > 0` の行に「応援あり」のバッジ（既存の Badge 部品）。管理画面の注文の一覧は合計の横に「（うち応援 {金額}）」。`_summary.blade.php` の送料の行の次に `@if ($order->support_amount > 0)応援: {{ number_format($order->support_amount) }}円  @endif`
- [ ] **Step 4: Run** Step 2 のコマンド — PASS
- [ ] **Step 5: Commit** — `feat: 注文の詳細・一覧・メールに応援額を表示する`

---

### Task 6: 売上に応援を足す

**Files:**

- Modify: `app/Http/Controllers/Creator/EarningsController.php`（`shopMonths`）、`resources/js/pages/creator/earnings.tsx`
- Test: `tests/Feature/Creator/EarningsTest.php`

**Interfaces:**

- Consumes: `Order::platformFeeAmount()`、`OrderFactory::withSupport()`（Task 1）
- Produces: `shop_months` の各要素に `supports: int`（応援付きの注文数）と `support_amount: int` を足す。型は `list<array{month: string, orders: int, sales: int, refunded: int, net: int, supports: int, support_amount: int}>`

- [ ] **Step 1: Write the failing test**

```php
it('counts support in shop sales and charges its own fee', function () {
    // 今月に paid の注文2件: 商品 3000・送料 0・率 10%（応援なし）と、商品 3000・応援 1000・率 10% / 5%
    // shop_months.0: orders 2, sales 7000, supports 1, support_amount 1000,
    //   net = 7000 - (300) - (300 + 50) = 6350
    // 全額返金した応援付きの注文は net に手数料を引かず（今と同じ）、supports と support_amount には数える
});
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Creator/EarningsTest.php` — FAIL
- [ ] **Step 3: Implement** — `get([...])` に `subtotal`、`shipping_fee`、`support_amount`、`support_fee_percent` を足し、手数料を `$order->platformFeeAmount()` にする（全額返金なら 0 のまま）。`earnings.tsx` の「ショップの売上」の表に「うち応援」列（`{supports}件 / {support_amount}円`）
- [ ] **Step 4: Run** 同じコマンド — PASS
- [ ] **Step 5: 全体の確認** — `./vendor/bin/sail composer ci:check` — すべて通る
- [ ] **Step 6: Commit** — `feat: ショップの売上に応援の件数と金額を足す`

---

## 設計書との差分（実装時の判断）

- §9「デモデータの注文の一部に応援額を付ける」: 今のシードには注文がない（`DemoProductsSeeder` は商品だけ）ので、この計画では行わない
- 公開前に、消費税と特定商取引法上の扱いを運営が確認する（§6「要確認」）。これは計画のタスクではない
