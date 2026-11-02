# ショップ（物のグッズ） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** クリエイターが物のグッズを登録して公開し、ファンがカートからクリエイターごとに Stripe Checkout で購入でき、クリエイターが発送・取り消し（返金）できるようにする。

**Architecture:** 新しいテーブル `products` / `product_images` / `cart_items` / `orders` / `order_items` を足す。注文の状態の変更と在庫の増減は、すべて `App\Services\Shop\Orders` を通す。Stripe との状態の同期は `OrderSync::syncSession()` に集める。この関数は Checkout セッションを取り直して注文に反映する冪等な処理で、Webhook・完了の戻り先・毎時の照合ジョブの3か所から同じものを呼ぶ。Stripe の呼び出しは既存の `PaymentGateway` に2つのメソッドを足して行う（`StripeGateway` と `FakeGateway` の両方）。

**Tech Stack:** Laravel 13 / stripe-php ^22 / Pest 4 / Inertia v3 + React 19 + TypeScript / Wayfinder（`@/actions`・`@/routes`）/ MySQL 8（テストも MySQL、`RefreshDatabase`）

**Spec:** `docs/superpowers/specs/2026-11-02-shop-goods-design.md`（先に読むこと。以下の「§」は設計書の節番号）

## Global Constraints

- コマンドはすべて Sail のコンテナで動かす: `./vendor/bin/sail artisan test <file>`、`./vendor/bin/sail composer ci:check`（Pint・PHPStan level 7・Pest・ESLint/Prettier/tsc）。ホストの `npm` は使わない
- コミットの前に `./vendor/bin/sail composer ci:check` を通す。作業中は対象のテストファイルだけを先に動かす
- コミットメッセージは日本語の Conventional Commits（`feat: ...`）。末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- ブランチ: `feat/shop-goods`（設計書と計画を含む `docs/shop-goods-design` から切る）
- 金額は円の整数（JPY は小数なし）。運営手数料は `floor(total * fee_percent / 100)`。`fee_percent` は注文作成時の `config('fanbox.billing.platform_fee_percent')`
- 商品: 名前 100 文字まで、説明 5000 文字まで、価格 50〜1,000,000、在庫 0〜99,999、画像は1商品 10 枚まで（JPEG / PNG / WebP、`config('fanbox.image_max_kb')`）。送料 0〜100,000
- Checkout の有効期限は既存と同じ `now()->addMinutes(config('fanbox.billing.checkout_expires_minutes') + 1)`
- 冪等キー: Checkout は `order-{id}-checkout`、返金は `order-{id}-refund`（既存の `StripeGateway::idempotencyKey()` で接頭辞を付ける）
- メタデータ: 既存と同じ `app`（`config('fanbox.billing.idempotency_prefix')`）、`user_id`、`creator_id` に加え `order_id`。`app` が違うセッションは無視する
- 状態は前にしか進めない（§6.1）。例外は `canceled → refunded`（取り消したあとに支払い完了が届いた場合）だけ
- 決済の失敗は `BillingException`。画面ではトースト「決済サービスに接続できませんでした。時間をおいてお試しください。」を出して `back()` する（既存と同じ）
- カートと購入手続きのバリデーションエラーのキーは `cart`
- 画面の文言（そのまま使う）: 「ショップ」「カートに入れる」「売り切れ」「現在購入できません」「在庫が足りません」「この商品を購入」「支払いへ進む」「決済の準備に失敗しました」「お支払い待ちの注文があります」「ご注文ありがとうございました」「お支払いをお待ちしています」「発送済みにする」「取り消して返金」「カート」「注文履歴」「商品管理」「注文管理」「ショップの売上」
- 状態の表示名: pending「支払い待ち」、paid「支払い済み」、shipped「発送済み」、canceled「取り消し」、refunded「返金済み」
- 既存のやり方に合わせる: モデルは `#[Fillable]`、新しいカラムには `@property` を付ける、`Inertia::flash('toast', [...])`、ルートには `throttle`、権限は Policy

## Review Focus

1. **最後の1個を2人が同時に買う** → 注文は1件だけ。在庫は `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?` の影響行数で減らすので、ロックの取り方に関係なく負にならない。1プロセスのテストでは本当の同時実行は再現できないので、「在庫が読み込み後に減っていた」状況で確かめる → Task 5 `refuses the second buyer of the last item`
2. **Webhook と完了の戻り先の両方が同じセッションを処理する、または同じイベントが2回届く** → 注文は1件のまま、在庫は1回だけ戻る・減る → Task 5 `syncs the same session twice without double effects`、Task 7 `ignores a replayed order event`
3. **期限切れで取り消した注文に、あとから支払い完了が届く** → 自動で全額返金して `refunded`、ログ `shop.paid_after_cancel` → Task 5 `refunds a payment that arrives after cancellation`
4. **Checkout の途中で「戻る」を押して、すぐにもう一度購入手続きを始める** → 前の注文は `abandoned` で取り消され、在庫は二重に確保されない → Task 6 `replaces an unfinished checkout of the same creator`
5. **他人の注文 ID や他人のセッション ID を URL に入れる** → 404。購入者・クリエイターとも自分の注文しか見られない → Task 6 `rejects completing someone else's session`、Task 8 `hides other users' orders`

---

### Task 1: テーブル・列挙型・モデル・ファクトリ

**Files:**

- Create: `database/migrations/2026_11_02_000008_add_shop_columns_to_creators.php`、`..._000009_create_products_table.php`、`..._000010_create_product_images_table.php`、`..._000011_create_cart_items_table.php`、`..._000012_create_orders_table.php`、`..._000013_create_order_items_table.php`
- Create: `app/Enums/OrderStatus.php`、`app/Enums/OrderCancelReason.php`
- Create: `app/Models/{Product,ProductImage,CartItem,Order,OrderItem}.php`、`database/factories/{Product,Order}Factory.php`
- Modify: `app/Models/Creator.php`、`app/Models/User.php`、`app/Enums/ReportTarget.php`、`config/fanbox.php`（`reserved_slugs` に `cart`・`orders`・`shop`）
- Test: `tests/Feature/Models/ShopSchemaTest.php`

**Interfaces:**

- Produces:
    - カラムは §5 のとおり。`orders.user_id` と `orders.creator_id` はどちらも `nullOnDelete`（アカウントを削除しても注文の記録を残すため）。`orders.status` は `OrderStatus` にキャストし、`cancel_reason` は `OrderCancelReason` にキャストする（null 可）
    - `enum OrderStatus: string { Pending='pending'; Paid='paid'; Shipped='shipped'; Canceled='canceled'; Refunded='refunded'; public function label(): string }`（表示名は Global Constraints のとおり）
    - `enum OrderCancelReason: string { Expired='expired'; Abandoned='abandoned'; PaymentFailed='payment_failed'; CheckoutError='checkout_error'; Creator='creator'; Admin='admin' }`
    - `ReportTarget::Product = 'product'`
    - `Product`（`SoftDeletes`）: `creator()`、`images()`（`sort_order` 順）、スコープ `listed()`（`is_published` かつ `hidden_at` が null。論理削除は SoftDeletes が除く）、`isListed(): bool`、`coverUrl(): ?string`（先頭の画像の公開 URL）
    - `ProductImage`: `product()`、`url(): string`（`Storage::disk('public')->url($this->path)`）
    - `CartItem`: `user()`、`product()`（`withTrashed()`）
    - `Order`: `user()`、`creator()`、`items()`、`isPending()` などは作らず `status` で比べる
    - `OrderItem`: `order()`、`product()`（`withTrashed()`）、`lineTotal(): int`
    - `Creator`: `products(): HasMany`、`orders(): HasMany`、`canSell(): bool`（`! isClosed()`、ユーザーが停止されていない、`canAcceptSupports()`）、`isShopVisible(): bool`（`! isClosed()` かつユーザーが停止されていない）。fillable に `shipping_fee`・`shop_notice`・`shop_contact_email` を足す
    - `User`: `cartItems(): HasMany`、`orders(): HasMany`
    - `ProductFactory`: 既定は公開・在庫 5・価格 1500。状態 `soldOut()`、`unpublished()`
    - `OrderFactory`: 既定は `pending`、小計 1500・送料 0。状態 `paid()`（`paid_at` と `stripe_payment_intent_id = 'pi_fake_'.Str::random(10)` を入れる）、`shipped()`。`withItem(Product $product, int $quantity = 1)` で明細を1件付ける

- [ ] **Step 1: 失敗するテストを書く** — `ShopSchemaTest.php`
    - `it keeps one cart row per user and product` — 同じ `(user_id, product_id)` の2件目で `UniqueConstraintViolationException`
    - `it hides unpublished, hidden and deleted products from listed()` — 4件のうち `listed()` は公開中の1件だけ
    - `it keeps orders when the buyer's account is deleted` — `User` を削除すると `orders.user_id` が null になり、注文は残る
    - `it tells whether a creator can sell` — 既定のクリエイターは false、`payable()` は true、`payable()` で `closed_at` があれば false、`payable()` でユーザーが停止されていれば false
- [ ] **Step 2: 実行して失敗を確かめる** — `./vendor/bin/sail artisan test tests/Feature/Models/ShopSchemaTest.php`（テーブルがないので FAIL）
- [ ] **Step 3: 実装する** — 上の Files のとおり
- [ ] **Step 4: 実行して通ることを確かめる** — 同じファイルで PASS。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: ショップのテーブルとモデルを追加する`

---

### Task 2: ゲートウェイに決済用の Checkout と返金を足す（インターフェース・DTO・偽ドライバ）

**Files:**

- Create: `app/Billing/Data/PaymentCheckoutRequest.php`、`app/Billing/Data/LineItem.php`、`app/Billing/Data/ShippingDetails.php`
- Modify: `app/Billing/PaymentGateway.php`、`app/Billing/Data/CheckoutSessionData.php`、`app/Billing/Data/ChargeData.php`、`app/Billing/FakeGateway.php`、`app/Billing/StripeGateway.php`（ここでは `BillingException('not implemented')` を投げるだけにし、中身は Task 3 で作る）
- Test: `tests/Feature/Billing/FakeGatewayTest.php`（追記）

**Interfaces:**

- Produces:
    - `LineItem(string $name, int $unitAmount, int $quantity)`
    - `ShippingDetails(string $name, string $postalCode, string $address, ?string $phone)`。`$address` は都道府県・市区町村・番地・建物名を半角スペースでつないだもの
    - `PaymentCheckoutRequest(string $customerId, array $lineItems /* list<LineItem> */, string $destinationAccountId, int $applicationFeeAmount, array $metadata, string $successUrl, string $cancelUrl, CarbonImmutable $expiresAt, string $idempotencyKey)`
    - `CheckoutSessionData` の末尾に、既定値付きの引数を足す: `?string $paymentStatus = null`（`paid|unpaid|no_payment_required`）、`?string $paymentIntentId = null`、`?ShippingDetails $shipping = null`。既存の呼び出しは変えない
    - `ChargeData` の末尾に `?string $paymentIntentId = null`
    - `PaymentGateway::createPaymentCheckoutSession(PaymentCheckoutRequest $request): CheckoutSessionData`
    - `PaymentGateway::refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): RefundData` — 全額を返金し、送金と手数料も取り戻す。すでに返金済みなら `BillingException`
    - `FakeGateway` の決済用セッションは `mode = 'payment'` と `line_items`・`amount_total` を持つ。テスト・開発用の操作（インターフェースには載せない）:
        - `completePaymentCheckout(string $sessionId, bool $paid = true): void` — `complete` にし、PaymentIntent `pi_fake_*` と charge `ch_fake_*` を作る。お届け先は `ShippingDetails('テスト 太郎', '100-0001', '東京都 千代田区 千代田1-1', '09000000000')`。`$paid = false` なら `payment_status = 'unpaid'`（あとで支払う方法）
        - `settleAsyncPayment(string $sessionId, bool $succeeded): void` — `unpaid` のセッションを `paid` にする。失敗なら PaymentIntent を `canceled` にする（セッションは `complete` のまま `payment_status = 'unpaid'`）
        - `refundedPaymentIntents(): list<string>`
    - `describeSession()` の戻り値に `mode`（`subscription|payment`）と `order_id`（`?int`）を足す

- [ ] **Step 1: 失敗するテストを書く**
    - `it completes a payment checkout with shipping details` — `createPaymentCheckoutSession` → `completePaymentCheckout` → `retrieveCheckoutSession` の `status = 'complete'`、`paymentStatus = 'paid'`、`paymentIntentId` が `pi_fake_` で始まる、`shipping->postalCode = '100-0001'`、`metadata['order_id']` が保たれる
    - `it leaves a delayed payment unpaid until settled` — `completePaymentCheckout($id, paid: false)` で `unpaid`、`settleAsyncPayment($id, true)` で `paid`
    - `it refunds a payment intent once` — 1回目は `RefundData->amount` = セッションの合計、2回目は `BillingException`。`retrieveCharge` の `amountRefunded` = 合計、`paymentIntentId` が一致する
    - `it expires an open payment checkout` — `expireCheckoutSession` で `status = 'expired'`
- [ ] **Step 2: 実行して失敗を確かめる** — `./vendor/bin/sail artisan test tests/Feature/Billing/FakeGatewayTest.php`
- [ ] **Step 3: 実装する**
- [ ] **Step 4: 実行して通ることを確かめる**。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: 決済ゲートウェイに商品の決済と返金を足す`

---

### Task 3: StripeGateway の実装

**Files:**

- Modify: `app/Billing/StripeGateway.php`
- Test: `tests/Feature/Billing/StripeGatewayTest.php`（追記。既存のテストと同じく、Stripe クライアントへのリクエストを捕まえてパラメータを確かめる）

**Interfaces:**

- Consumes: Task 2 の DTO とメソッドのシグネチャ
- Produces:
    - `createPaymentCheckoutSession` が送るもの: `mode=payment`、`customer`、`locale=ja`、`line_items[].price_data = {currency: 'jpy', unit_amount, product_data: {name}}` と `quantity`、`shipping_address_collection.allowed_countries = ['JP']`、`phone_number_collection.enabled = true`、`expires_at`、`success_url`、`cancel_url`、`metadata`、`payment_intent_data = {application_fee_amount, transfer_data: {destination}, metadata}`。オプションは `idempotency_key = idempotencyKey($request->idempotencyKey)`
    - `sessionFrom()` が `payment_status`、`payment_intent`、`collected_information.shipping_details`（なければ `shipping_details`）を `ShippingDetails` に詰める。住所は `state city line1 line2` を空でないものだけ半角スペースでつなぐ。電話は `customer_details.phone`
    - `refundPaymentIntent` が送るもの: `payment_intent`、`reverse_transfer = true`、`refund_application_fee = true`。オプションは冪等キー
    - `retrieveCharge` が `payment_intent` を `ChargeData->paymentIntentId` に入れる

- [ ] **Step 1: 失敗するテストを書く**
    - `it creates a payment checkout with destination, fee and shipping collection` — 上の「送るもの」をすべて確かめる（`application_fee_amount = 300`、`allowed_countries = ['JP']`、`idempotency_key` に `order-7-checkout` が含まれる）
    - `it reads shipping details and payment status from a session` — Stripe 形式の JSON を返させて `ShippingDetails('山田 花子', '150-0001', '東京都 渋谷区 神宮前1-2-3 ハイツ101', '+819012345678')` と `paymentStatus = 'paid'` を確かめる
    - `it refunds a payment intent with transfer reversal and fee refund`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**（`./vendor/bin/sail artisan test tests/Feature/Billing/StripeGatewayTest.php`）。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: StripeGateway で商品の決済と返金を行う`

---

### Task 4: クリエイターの商品管理とショップ設定

**Files:**

- Create: `app/Http/Controllers/Creator/ProductController.php`、`app/Http/Controllers/Creator/ProductImageController.php`、`app/Http/Controllers/Creator/ShopSettingsController.php`
- Create: `app/Http/Requests/Creator/{StoreProductRequest,UpdateProductRequest,StoreProductImageRequest,ReorderProductImagesRequest,UpdateShopSettingsRequest}.php`
- Create: `app/Policies/ProductPolicy.php`
- Create: `resources/js/pages/creator/products/index.tsx`、`resources/js/pages/creator/products/edit.tsx`（作成と編集で同じページ）、`resources/js/pages/creator/shop.tsx`
- Modify: `routes/creator.php`、`resources/js/components/app-sidebar.tsx`（「クリエイター管理」に「商品管理」）、`app/Console/Commands/PruneOrphanFiles.php`（`ProductImage::path` も参照として数える）
- Test: `tests/Feature/Creator/ProductManagementTest.php`、`tests/Feature/Creator/ShopSettingsTest.php`、`tests/Feature/Console/PruneOrphanFilesTest.php`（追記）

**Interfaces:**

- Produces（すべて `auth`・`verified`・`creator` の中）:
    - `creator.products.index` GET `/creator/products`、`creator.products.create` GET `/creator/products/create`、`creator.products.store` POST `/creator/products`、`creator.products.edit` GET `/creator/products/{product}/edit`、`creator.products.update` PUT `/creator/products/{product}`、`creator.products.destroy` DELETE `/creator/products/{product}`（論理削除）
    - `creator.products.images.store` POST `/creator/products/{product}/images`（`throttle:60,1`）、`creator.products.images.destroy` DELETE `.../images/{image}`、`creator.products.images.reorder` PUT `.../images`（`ids: list<int>`）
    - `creator.shop.edit` GET `/creator/shop`、`creator.shop.update` PUT `/creator/shop`（`shipping_fee`、`shop_notice` 2000 文字まで、`shop_contact_email` 任意の email）
    - 画像の保存は `ImageSanitizer::storePublic($upload, "creators/{$creator->id}/products", 'image')`。画像を削除したらファイルも消す
    - 公開（`is_published = true`）は `Creator::canAcceptSupports()` のときだけできる。できなければ `is_published` のエラー「口座の登録が済むと公開できます。」を出す。一覧ページの props `can_publish: bool` で、理由と `creator.earnings.index` へのリンクを出す
    - `ProductPolicy::update(User, Product)` は `$product->creator->user_id === $user->id`

- [ ] **Step 1: 失敗するテストを書く**
    - `it creates, updates and soft-deletes a product` — 削除後は `Product::withTrashed()` に残り、一覧に出ない
    - `it validates price, stock and name limits` — 価格 49 と 1,000,001、在庫 -1、名前 101 文字はそれぞれエラー
    - `it refuses to publish before payouts are enabled` — `payable()` でないクリエイターの `is_published = true` はエラー。`payable()` なら公開される
    - `it limits images to ten and reorders them` — 11 枚目はエラー。`reorder` で `sort_order` が送った順になる
    - `it forbids editing another creator's product` — 403
    - `it saves shop settings` — `shipping_fee = 600`、お知らせ、連絡先が保存される。`shipping_fee = 100001` はエラー
    - （PruneOrphanFiles）`it keeps product images` — 商品の画像は24時間たっても消えない
- [ ] **Step 2〜4: 実行 → 実装（コントローラー・リクエスト・ページ）→ 実行**。画面は既存の `creator/plans/index.tsx` と `creator/posts/edit.tsx` のコンポーネントと書き方に合わせる
- [ ] **Step 5: `composer ci:check` を通してコミット** `feat: クリエイターが商品とショップを管理できるようにする`

---

### Task 5: 注文の状態と在庫（Orders）と Stripe との同期（OrderSync）

**Files:**

- Create: `app/Services/Shop/Orders.php`、`app/Services/Shop/OrderSync.php`
- Test: `tests/Feature/Shop/OrdersTest.php`、`tests/Feature/Shop/OrderSyncTest.php`

**Interfaces:**

- Consumes: Task 1 のモデル、Task 2 の `CheckoutSessionData`（`paymentStatus`・`paymentIntentId`・`shipping`）と `refundPaymentIntent`
- Produces:
    - `Orders::reserve(User $buyer, Creator $creator, Collection $cartItems): Order` — トランザクションの中で、商品ごとに `Product::query()->whereKey($id)->listed()->where('stock', '>=', $qty)->decrement('stock', $qty)` を行う。影響行数が 0 なら例外を投げてロールバックする（`ValidationException`、キー `cart`、「在庫が足りません」）。`pending` の注文と明細（名前・単価のコピー）を作る。小計・送料（`$creator->shipping_fee`）・合計・`fee_percent` を保存する
    - `Orders::markPaid(Order $order, CheckoutSessionData $session): Order` — `pending` のときだけ `paid` にする。`paid_at`、`stripe_payment_intent_id`、お届け先を保存し、その購入者のカートから注文の商品を消し、メールを送る（Task 8 で足す。ここでは `OrderPaid` イベントを dispatch するだけ）
    - `Orders::recordAwaitingPayment(Order $order, CheckoutSessionData $session): Order` — `pending` のまま、PaymentIntent ID とお届け先だけを保存する
    - `Orders::cancel(Order $order, OrderCancelReason $reason): Order` — `pending` のときだけ `canceled` にし、在庫を戻す（`increment`）。それ以外の状態なら何もしない
    - `Orders::ship(Order $order, ?string $trackingNumber): Order` — `paid` のときだけ。それ以外は `ValidationException`（キー `order`）。`OrderShipped` イベントを dispatch する
    - `Orders::refund(Order $order, OrderCancelReason $by, bool $allowCanceled = false): Order` — 対象は `paid` か `shipped`。`$allowCanceled` のときだけ `canceled` も対象にする（`OrderSync` が「取り消したあとに支払い完了が届いた」ときにだけ使う）。それ以外の状態なら `ValidationException`（キー `order`）。`gateway->refundPaymentIntent($pi, "order-{$id}-refund")` が成功してから、`refunded`・`refunded_amount = total`・`refunded_at`・`cancel_reason = $by` を保存する。在庫を戻すのは `paid` からのときだけ（`shipped` は発送済み、`canceled` は取り消しの時点で戻し済み）。`OrderRefunded` イベントを dispatch する。API が失敗したら `BillingException` を投げ、何も変えない
    - `Orders::syncRefund(Order $order, ChargeData $charge): Order` — `refunded_amount = min(amountRefunded, total)`。全額なら `refunded` にする（在庫の扱いは `refund` と同じ）。`disputed` なら `disputed_at` を記録する
    - すべてのメソッドは、最初に `Order::query()->lockForUpdate()->find($order->id)` で取り直してから状態を見る
    - イベント: `App\Events\Shop\{OrderPaid,OrderShipped,OrderRefunded}`（プロパティは `public Order $order` だけ）
    - `OrderSync::syncSession(string $sessionId): ?Order` — セッションを取り直し、`metadata.app` が違うか `order_id` がなければ null を返す。それ以外は §6.2 ②の表に従う:
        - `complete` かつ `paid` → `markPaid`。ただし注文が `canceled` なら、セッションの `paymentIntentId` を注文に保存してから `refund($order, $order->cancel_reason, allowCanceled: true)` を呼び、`Log::error('shop.paid_after_cancel', ['order_id' => ...])` を出す（すでに `refunded` なら何もしない）
        - `complete` かつ `unpaid` → `recordAwaitingPayment`
        - `expired` → `cancel(Expired)`
    - `OrderSync::syncAsyncFailure(string $sessionId): ?Order` → `cancel(PaymentFailed)`

- [ ] **Step 1: 失敗するテストを書く**
    - Orders: `reserves stock and copies names and prices`、`refuses the second buyer of the last item`（在庫 1 の商品で1人目を reserve したあと、2人目の reserve は `ValidationException`、在庫は 0 のまま、注文は1件）、`cancels a pending order once and restores stock once`（2回呼んでも在庫は1回だけ戻る）、`does not ship an unpaid order`、`refunds a paid order and restores stock`、`refunds a shipped order without restoring stock`、`changes nothing when the refund call fails`（`FakeGateway` に返金済みの PI を渡して `BillingException`。状態は `paid` のまま）
    - OrderSync: `marks a paid session's order as paid with shipping details`、`keeps a delayed payment pending with its payment intent`、`cancels an expired session's order`、`syncs the same session twice without double effects`（在庫・状態・カートが1回分だけ変わる）、`refunds a payment that arrives after cancellation`（`refundedPaymentIntents()` に含まれ、`refunded`、ログが出る。在庫は取り消しのときの1回分しか戻っていない）、`ignores sessions from another app or without an order`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**（`./vendor/bin/sail artisan test tests/Feature/Shop`）。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: 注文の状態と在庫を管理し、Stripe の状態と揃える`

---

### Task 6: 公開ページ・カート・購入手続き

**Files:**

- Create: `app/Services/Shop/Cart.php`、`app/Services/Shop/OrderCheckout.php`
- Create: `app/Http/Controllers/Public/ProductPageController.php`、`app/Http/Controllers/Fan/CartController.php`、`app/Http/Controllers/Fan/CartItemController.php`、`app/Http/Controllers/Fan/OrderCheckoutController.php`
- Create: `resources/js/pages/public/product.tsx`、`resources/js/pages/fan/cart.tsx`、`resources/js/pages/fan/cart-confirm.tsx`
- Modify: `app/Http/Controllers/Public/CreatorPageController.php` と `resources/js/pages/public/creator.tsx`（「投稿」「ショップ」のタブ。`?tab=shop`）、`routes/web.php`、`routes/fan.php`、`app/Http/Controllers/Dev/FakeCheckoutController.php` と `resources/js/pages/dev/fake-checkout.tsx`（`mode = payment` のとき、商品の明細と「支払う」「あとで支払う」「期限切れにする」）、`resources/js/components/app-sidebar.tsx`（「カート」）
- Test: `tests/Feature/Public/ShopPageTest.php`、`tests/Feature/Fan/CartTest.php`、`tests/Feature/Fan/OrderCheckoutTest.php`

**Interfaces:**

- Consumes: Task 5 の `Orders::reserve`・`Orders::cancel`・`OrderSync::syncSession`、Task 2 の `createPaymentCheckoutSession`
- Produces:
    - ルート: `shop.products.show` GET `/@{creator}/shop/{product}`（`whereNumber`。`routes/web.php` の `/@{creator}` の近く）。`cart.index` GET `/cart`、`cart.items.store` POST `/cart/items`（`product_id`・`quantity`）、`cart.items.update` PUT `/cart/items/{cartItem}`、`cart.items.destroy` DELETE `/cart/items/{cartItem}`（ここまで `throttle:60,1,cart`）、`cart.confirm` GET `/cart/{creator}/confirm`、`cart.checkout` POST `/cart/{creator}/checkout`（`throttle:10,1`）、`orders.checkout.complete` GET `/orders/checkout/complete`（`throttle:30,1,order-checkout-complete`）
    - 公開ページ: クリエイターが `isShopVisible()` でないか、商品が `listed()` でなければ 404。ショップのタブは `isShopVisible()` かつ `listed()` の商品が1件以上あるときだけ出す。props `can_buy: bool` = `canSell()`
    - `Cart::add(User $user, Product $product, int $quantity): void` — 既存の行があれば足す。合計が `stock` を超えるか、商品が `listed()` でなければ `ValidationException`（キー `cart`）。自分の商品は入れられない（「自分の商品は購入できません。」）
    - `Cart::groups(User $user): list<array{creator: Creator, items: Collection<CartItem>, subtotal: int, shipping_fee: int, total: int, purchasable: bool}>` — `purchasable` は `canSell()` かつ、すべての行が `listed()` で在庫が足りること
    - `OrderCheckout::begin(User $buyer, Creator $creator): string`（Checkout の URL を返す）:
        1. 購入者が停止されていない、`canSell()`、グループが `purchasable`。満たさなければ `ValidationException`（キー `cart`、「現在購入できません」）
        2. 同じ購入者×クリエイターの `pending` の注文: `stripe_payment_intent_id` があれば `ValidationException`（「お支払い待ちの注文があります」）。なければ `expireCheckoutSession`（`BillingException` は無視）してから `Orders::cancel(Abandoned)`
        3. `Orders::reserve` → `createPaymentCheckoutSession`（`successUrl = route('orders.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}'`、`cancelUrl = route('cart.index', ['canceled' => $order->id])`、送料が 0 より大きければ `LineItem('送料', fee, 1)` を足す）→ `stripe_checkout_session_id` を保存
        4. `BillingException` なら `Orders::cancel(CheckoutError)` してから、`ValidationException`（「決済の準備に失敗しました」）を投げる
    - `OrderCheckout::abandon(User $buyer, int $orderId): void` — 自分の `pending` で PaymentIntent のない注文なら、セッションを失効させて `cancel(Abandoned)`。`CartController@index` が `?canceled=` を受けたときに呼ぶ
    - `OrderCheckout::complete(User $buyer, string $sessionId): Order` — セッションの `metadata.user_id` が本人でなければ 404。`OrderSync::syncSession` を呼んで注文を返す。`OrderCheckoutController@complete` がそれを受けて注文の詳細へリダイレクトし、トーストを出す（`paid` なら「ご注文ありがとうございました」、`pending` なら「お支払いをお待ちしています」）。注文の詳細ページは Task 8 で作るので、それまではリダイレクト先を `cart.index` にしておき、Task 8 で `orders.show` に変える

- [ ] **Step 1: 失敗するテストを書く**
    - ShopPage: `shows listed products in the shop tab`、`hides the shop of a closed or suspended creator`（タブなし、商品ページ 404）、`shows products of a creator without payouts as not purchasable`（`can_buy = false`）、`404s an unpublished, hidden or deleted product`
    - Cart: `adds up quantities up to the stock`、`refuses more than the stock`、`refuses own products`、`groups items by creator with shipping fee`（送料 600 のクリエイターと 0 のクリエイター）、`marks a group with a sold-out item as not purchasable`
    - OrderCheckout: `starts a checkout and reserves stock`（Stripe 側の `amount_total` = 小計 + 送料、手数料 = `floor(total * 10 / 100)`）、`refuses when the creator cannot sell`、`replaces an unfinished checkout of the same creator`（前の注文は `canceled`・`abandoned`、在庫は新しい注文の分だけ減っている）、`refuses while a delayed payment is pending`、`cancels the order when Stripe fails`（`FakeGateway` に失敗させて在庫が戻る）、`abandons the order when coming back from checkout`、`completes a paid session on return`、`rejects completing someone else's session`（404、注文は変わらない）
- [ ] **Step 2〜4: 実行 → 実装 → 実行**。画面は既存の `public/creator.tsx` と `fan/checkout-confirm.tsx` に合わせる。確認画面には §7 の案内（国内のみ、購入者から取り消せない、返品は特定商取引法のページ `legal.tokushoho` へのリンク）を出す
- [ ] **Step 5: `composer ci:check` を通してコミット** `feat: ショップの公開ページ・カート・購入手続き`

---

### Task 7: Webhook と照合ジョブ

**Files:**

- Create: `app/Console/Commands/ReconcileOrders.php`（`shop:reconcile-orders`）
- Modify: `app/Http/Controllers/Billing/StripeWebhookController.php`、`app/Jobs/ProcessStripeEvent.php`、`app/Services/Billing/Refunds.php`、`routes/console.php`（`Schedule::command('shop:reconcile-orders')->hourly()`）、`docs/operations/stripe-setup.md`（§10 の4イベントを足す）
- Test: `tests/Feature/Billing/WebhookTest.php`（追記）、`tests/Feature/Console/ReconcileOrdersTest.php`

**Interfaces:**

- Consumes: Task 5 の `OrderSync::syncSession`・`syncAsyncFailure`・`Orders::syncRefund`
- Produces:
    - `ProcessStripeEvent::handle()` に `OrderSync $orders` を足す。`checkout.session.completed` は `subscriptionId` があれば既存どおり、なければ `$orders->syncSession($objectId)`。`checkout.session.expired`・`checkout.session.async_payment_succeeded` → `syncSession`。`checkout.session.async_payment_failed` → `syncAsyncFailure`
    - `Refunds::syncCharge()`: `invoiceId` が null で `paymentIntentId` があれば、その PI の注文に `Orders::syncRefund` を呼ぶ（戻り値は `?Payment` のまま null）。注文もなければ既存どおり `billing.charge_unattributed` の警告
    - `ReconcileOrders`: `pending` で `created_at` が1時間より前の注文ごとに、セッション ID があれば `syncSession`。そのあともセッションが `open` なら失効させて、もう一度 `syncSession` する。セッション ID がなければ `Orders::cancel(CheckoutError)`。1件の失敗（`BillingException`）は `report()` して次へ進む

- [ ] **Step 1: 失敗するテストを書く**
    - Webhook: `marks an order paid on checkout.session.completed`、`cancels an order on checkout.session.expired`、`settles an order on async_payment_succeeded and cancels on async_payment_failed`、`ignores a replayed order event`、`syncs a refund made in the Stripe dashboard`（`charge.refunded` で `refunded`、在庫が戻る）。既存の `postStripeEvent()` を使う。キューは同期で動かす
    - Reconcile: `catches up a paid order whose webhook was missed`、`expires and cancels a stale open checkout`、`cancels a pending order without a session`、`leaves recent pending orders alone`、`keeps going after one order fails`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: 注文の Webhook と毎時の照合ジョブ`

---

### Task 8: 注文の画面（購入者とクリエイター）とメール

**Files:**

- Create: `app/Http/Controllers/Fan/OrderController.php`、`app/Http/Controllers/Creator/OrderController.php`、`app/Policies/OrderPolicy.php`
- Create: `app/Mail/Shop/{OrderConfirmed,OrderReceived,OrderShipped,OrderRefunded}.php`（`ShouldQueue`、markdown）、`resources/views/mail/shop/*.blade.php`、`app/Listeners/Shop/SendOrderMail.php`
- Create: `resources/js/pages/fan/orders/{index,show}.tsx`、`resources/js/pages/creator/orders/{index,show}.tsx`
- Modify: `routes/fan.php`、`routes/creator.php`、`app/Services/Shop/OrderCheckout.php`（リダイレクト先を `orders.show` にする）、`resources/js/components/app-sidebar.tsx`（「注文履歴」と「注文管理」）
- Test: `tests/Feature/Fan/OrdersTest.php`、`tests/Feature/Creator/OrderManagementTest.php`、`tests/Feature/Shop/OrderMailTest.php`

**Interfaces:**

- Consumes: Task 5 の `Orders::ship`・`Orders::refund` とイベント
- Produces:
    - `orders.index` GET `/orders`、`orders.show` GET `/orders/{order}`（`whereNumber`。`orders.checkout.complete` より後に登録する）
    - `creator.orders.index` GET `/creator/orders`（`?status=`。既定は `paid`・`shipped`・`refunded`）、`creator.orders.show` GET `/creator/orders/{order}`、`creator.orders.ship` POST `/creator/orders/{order}/ship`（`tracking_number` は任意で 100 文字まで）、`creator.orders.refund` POST `/creator/orders/{order}/refund`（`paid` のときだけ。`Orders::refund($order, OrderCancelReason::Creator)`）。どちらも `throttle:10,1`
    - `OrderPolicy::view(User, Order)` は購入者本人、`OrderPolicy::manage(User, Order)` は注文のクリエイター本人。違えば 404（`abort_unless(..., 404)`。存在を漏らさない）
    - 購入者の詳細に出すもの: 状態、明細、小計・送料・合計、お届け先、お問い合わせ番号、クリエイターの `shop_contact_email`
    - メール: `OrderPaid` → 購入者に `OrderConfirmed`、クリエイターに `OrderReceived`。`OrderShipped` → 購入者に `OrderShipped`。`OrderRefunded` → 購入者に `OrderRefunded`。購入者のアカウントがなければ（`user_id` が null）送らない

- [ ] **Step 1: 失敗するテストを書く**
    - Fan: `lists own orders newest first`、`hides other users' orders`（404）、`shows the creator's contact and tracking number`
    - Creator: `ships a paid order with a tracking number`、`refunds a paid order and restores stock`、`cannot refund a shipped order`（エラー。状態は `shipped` のまま）、`hides other creators' orders`（404）、`filters orders by status`
    - Mail（`Mail::fake()`）: `sends confirmation to buyer and creator when paid`、`sends shipping and refund mails to the buyer`、`skips mail when the buyer account is gone`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**。取り消しのボタンには、既存の確認ダイアログのコンポーネントを使う
- [ ] **Step 5: `composer ci:check` を通してコミット** `feat: 注文履歴・注文管理と注文のメール`

---

### Task 9: 運営の画面（注文の返金・商品の通報と非公開）

**Files:**

- Create: `app/Http/Controllers/Admin/OrderController.php`、`app/Http/Controllers/Admin/ProductModerationController.php`、`resources/js/pages/admin/product.tsx`
- Modify: `routes/admin.php`、`app/Http/Controllers/Admin/PaymentController.php` と `resources/js/pages/admin/payments.tsx`（「ショップの注文」の欄）、`app/Http/Requests/StoreReportRequest.php` と通報まわり（`ReportTarget::Product`）、`resources/js/pages/public/product.tsx`（通報ボタン。投稿と同じ部品）、`app/Http/Controllers/Admin/ReportController.php`（商品の通報から `admin.products.show` へ）
- Test: `tests/Feature/Admin/OrderAdminTest.php`、`tests/Feature/Admin/ProductModerationTest.php`、`tests/Feature/ReportTest.php`（追記）

**Interfaces:**

- Consumes: Task 5 の `Orders::refund`
- Produces:
    - `admin.orders.refund` POST `/admin/orders/{order}/refund` — `paid` か `shipped` のとき `Orders::refund($order, OrderCancelReason::Admin)`。そのあと `AdminAction::record($admin, 'order.refunded', $order, null)`
    - `admin.products.show` GET `/admin/products/{product}`、`admin.products.update` PUT（`hidden: bool`、`reason`）。投稿の `PostModerationController` と同じ形で `hidden_at`・`hidden_reason` を書き、`AdminAction` に `product.hidden` / `product.unhidden` を記録する
- [ ] **Step 1: 失敗するテストを書く** — `refunds a shipped order as admin and records the action`、`forbids non-admins`、`hides a reported product from the shop`、`accepts a report on a product`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**。そのあと `composer ci:check`
- [ ] **Step 5: コミット** `feat: 運営が注文を返金し、商品を非公開にできるようにする`

---

### Task 10: 収益・特定商取引法・デモデータ・通しの確認

**Files:**

- Modify: `app/Http/Controllers/Creator/EarningsController.php` と `resources/js/pages/creator/earnings.tsx`（「ショップの売上」）、`resources/js/pages/legal/tokushoho.tsx`（§7 の4項目を足す）、`database/seeders/DatabaseSeeder.php`（デモクリエイターの商品を3件。うち1件は売り切れ。送料 500）、`README.md`（ショップの一文）
- Test: `tests/Feature/Creator/EarningsTest.php`（追記）、`tests/Feature/Public/LegalPagesTest.php`（追記）、`tests/Feature/SeederTest.php`（追記）

**Interfaces:**

- Produces: 収益ページの props `shop_months: list<array{month: string /* Y-m */, orders: int, sales: int, refunded: int, net: int}>`。`paid_at` の月で集計する。`sales` は `total`、`net = sales - refunded - floor(sales * fee_percent / 100)`。ただし全額返金の注文の手数料は 0 として数える。対象は `paid_at` が null でない注文
- [ ] **Step 1: 失敗するテストを書く** — `shows monthly shop sales net of refunds and fees`（2件の注文のうち1件を全額返金した月の `net`）、`lists goods sales terms on the tokushoho page`（「商品代金以外の必要料金」が含まれる）、`seeds demo products`
- [ ] **Step 2〜4: 実行 → 実装 → 実行**
- [ ] **Step 5: 通しの確認** — `./vendor/bin/sail artisan migrate:fresh --seed` のあと、偽ドライバでブラウザから次を通す: デモのファンでログイン → `/@demo-creator?tab=shop` → カートに2点 → 確認画面 → 偽の Checkout で「支払う」→ 注文の詳細が「支払い済み」→ クリエイターで「発送済みにする」→ ファンの注文が「発送済み」。もう1件は偽の Checkout で「期限切れにする」→ 在庫が戻っている。Mailpit（http://localhost:8025）に確定・発送のメールが届いている
- [ ] **Step 6: `composer ci:check` を通してコミット** `feat: ショップの売上・特商法の項目・デモデータ`
