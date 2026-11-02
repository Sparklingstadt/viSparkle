# ショップ（デジタル作品） Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** クリエイターがファイル付きのデジタル作品をグッズと並べて売り、購入者が注文の画面からダウンロードできるようにする。

**Architecture:** `products.kind`（`physical` / `digital`）と `product_files` を足し、注文の明細に `product_kind` をコピーする。ダウンロードの権限は保存せず、`App\Services\Shop\DigitalAccess` が注文と作品から都度判定する（購入済みか・ダウンロードできない理由）。在庫・送料・返金・お届け先の扱いは、1段階目の `Orders` / `Cart` / `OrderCheckout` に種類の分岐を足して行う。ファイルは投稿の添付と同じ `private` ディスクに置き、5分の署名付きURLへ転送する（アプリは中身を中継しない）。

**Tech Stack:** Laravel 13 / stripe-php ^22 / Pest 4 / Inertia v3 + React 19 + TypeScript / Wayfinder（`@/actions`・`@/routes`）/ MySQL 8（テストも MySQL、`RefreshDatabase`）

**Spec:** `docs/superpowers/specs/2026-11-03-digital-goods-design.md`（先に読むこと。以下の「§」は設計書の節番号）。1段階目の `docs/superpowers/specs/2026-11-02-shop-goods-design.md` も前提として読む

## Global Constraints

- コマンドはすべて Sail のコンテナで動かす: `./vendor/bin/sail artisan test <file>`、`./vendor/bin/sail composer ci:check`（Pint・PHPStan level 7・Pest・ESLint/Prettier/tsc）。ホストの `npm` は使わない
- コミットの前に `./vendor/bin/sail composer ci:check` を通す。作業中は対象のテストファイルだけを先に動かす
- コミットメッセージは日本語の Conventional Commits（`feat: ...`）。末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- ブランチ: `feat/shop-digital`（設計書と計画を含むブランチから切る）
- デジタル作品: 在庫なし（`stock` は 0 のまま）、数量は常に 1、1作品のファイルは `config('fanbox.product_files_max')` = 10 件まで、1件 `config('fanbox.attachment_max_kb')`（102400）まで、種類は問わない
- 種類は作成時だけ決める。更新のリクエストで `kind` を受け取らない
- 送料は物の明細が1つ以上あるときだけ `creators.shipping_fee`。デジタルだけなら 0 で、Checkout にお届け先と電話番号を求めない
- ダウンロードできる条件（§6.5、すべて）: 自分の注文・`paid` か `shipped`・`disputed_at` が null／その作品が注文の明細にあり `product_kind = digital`／作品が公開中（`is_published`、`hidden_at` と `deleted_at` が null）／クリエイターが活動中（`closed_at` が null、クリエイターのユーザーが停止されていない）／購入者が停止されていない。一部返金では止めない
- 「購入済み」= その作品を含む自分の `paid` か `shipped` の注文がある
- デジタルの明細を含む注文: クリエイターは取り消し（返金）できない。デジタルだけの注文は発送できない
- 署名付きURLは `config('fanbox.signed_url_minutes')` 分、`ResponseContentDisposition` = `attachment; filename*=UTF-8''{rawurlencode(original_name)}`
- 保存先のパス: `creators/{creator_id}/products/{product_id}/{ulid}.{ext}`（`private` ディスク）
- 画面の文言（そのまま使う）: 「グッズ」「デジタル作品」「デジタル」「ダウンロード販売」「ダウンロード」「購入済み」「購入済みの作品です」「現在購入できません」「公開するにはファイルを1件以上アップロードしてください」「公開中の作品からすべてのファイルを消すことはできません。」「デジタル作品を含む注文は取り消せません。」「この注文は発送できません。」「デジタル作品は購入後に取り消せません」「作品が非公開になるとダウンロードできなくなるため、早めに保存してください」「注文の画面からダウンロードできます」
- ダウンロードできない理由の文言（`DigitalAccess::refusal` が返す）: pending「支払いの確認後にダウンロードできます」、refunded「返金済みのためダウンロードできません」、canceled「取り消された注文のためダウンロードできません」、disputed「お支払いに問題があったため、ダウンロードできません」、作品・クリエイター「この作品は現在公開されていないため、ダウンロードできません」、購入者の停止「現在ダウンロードできません」
- 既存のやり方に合わせる: モデルは `#[Fillable]`、新しいカラムには `@property`、`Inertia::flash('toast', [...])`、ルートには `throttle`、権限は Policy（`ProductPolicy::update`、`OrderPolicy::view`）

## Review Focus

1. **カートに入れたあと別の端末で同じ作品を買い、元の端末でカートから購入しようとする** → まとまりは購入できず「購入済みの作品です」、`reserve` も弾いて何も確保しない → Task 5 `refuses a cart group with an already purchased work`、Task 6 `refuses to reserve an already purchased work`
2. **ダッシュボードから一部だけ返金した注文** → ダウンロードは続けられる。全額返金で止まる → Task 4 `keeps access after a partial refund`、`refuses after a full refund`
3. **クリエイターが作品を非公開にしてから、また公開する** → 非公開のあいだは 404、公開し直すと購入者はまたダウンロードできる → Task 4 `restores access when the work is published again`
4. **デジタルだけの注文に「発送済みにする」を直接 POST する** → `order` のエラー「この注文は発送できません。」で状態は変わらない → Task 6 `refuses to ship a digital-only order`
5. **日本語や空白を含むファイル名のダウンロード** → `Content-Disposition` の `filename*` に URL エンコードされた名前が入り、ファイル名が化けない → Task 2 `signs a download with the encoded original name`

---

## File Structure

| ファイル | 役割 |
|---|---|
| `app/Enums/ProductKind.php`（新規） | `physical` / `digital` と表示名 |
| `database/migrations/2026_11_03_00000{1,2,3}_*.php`（新規） | `products.kind`、`product_files`、`order_items.product_kind` |
| `app/Models/ProductFile.php`、`database/factories/ProductFileFactory.php`（新規） | 作品のファイル |
| `app/Models/Product.php`、`OrderItem.php`、`Order.php`（変更） | 種類・ファイル・判定のメソッド |
| `app/Services/PrivateFileWriter.php`（新規） | `private` ディスクへのストリーム書き込み（`PostFileStorer` から切り出し） |
| `app/Services/PrivateFileUrls.php`（新規） | 署名付きURL（`PostFileUrls` から切り出し） |
| `app/Services/Shop/ProductFileStorer.php`（新規） | 作品のファイルの保存と削除 |
| `app/Services/Shop/DigitalAccess.php`（新規） | 購入済みの判定とダウンロードできない理由 |
| `app/Http/Controllers/Creator/ProductFileController.php` ＋ Request 2つ（新規） | ファイルの追加・並べ替え・削除・クリエイターのダウンロード |
| `app/Http/Controllers/Fan/OrderFileDownloadController.php`（新規） | 購入者のダウンロード |
| `app/Services/Shop/Cart.php`、`Orders.php`、`OrderCheckout.php`（変更） | 種類ごとの在庫・送料・返金・発送 |
| `app/Billing/Data/PaymentCheckoutRequest.php`、`StripeGateway.php`、`FakeGateway.php`（変更） | `collectShipping` |
| フロント: `resources/js/pages/creator/products/{edit,index}.tsx`、`creator/orders/show.tsx`、`public/{product,creator}.tsx`、`fan/{cart,cart-confirm}.tsx`、`fan/orders/{index,show}.tsx`、`legal/tokushoho.tsx`、`admin/payments.tsx` | 表示 |

---

### Task 1: 種類・ファイルのデータモデル

**Files:**
- Create: `app/Enums/ProductKind.php`、`app/Models/ProductFile.php`、`database/factories/ProductFileFactory.php`
- Create: `database/migrations/2026_11_03_000001_add_kind_to_products.php`、`2026_11_03_000002_create_product_files_table.php`、`2026_11_03_000003_add_product_kind_to_order_items.php`
- Modify: `app/Models/Product.php`、`app/Models/OrderItem.php`、`app/Models/Order.php`、`database/factories/ProductFactory.php`、`config/fanbox.php`
- Test: `tests/Feature/Models/ProductKindTest.php`

**Interfaces:**
- Produces:
  - `enum ProductKind: string { case Physical = 'physical'; case Digital = 'digital'; public function label(): string }`（「グッズ」「デジタル作品」）
  - `Product::$kind`（cast `ProductKind`）、`Product::files(): HasMany<ProductFile>`（`sort_order` 順）、`Product::isDigital(): bool`、`Product::isPurchasable(): bool`（物: `isListed() && stock > 0`、デジタル: `isListed() && files()->exists()`。`files` が読み込み済みならそれを使う）
  - `ProductFile`（`#[Fillable(['path', 'original_name', 'mime', 'size', 'sort_order'])]`、`product(): BelongsTo`）
  - `OrderItem::$product_kind`（cast `ProductKind`、Fillable に足す）
  - `Order::hasPhysical(): bool`、`Order::hasDigital(): bool`（明細の `product_kind` から。`items` が読み込み済みならそれを使う）
  - `ProductFactory::digital()`（`kind = digital`、`stock = 0`）、`ProductFileFactory`（`path = creators/0/products/0/{ulid}.zip`、`original_name = 'files.zip'`、`mime = 'application/zip'`、`size = 2048`）
  - `config('fanbox.product_files_max')` = 10

- [ ] **Step 1: Write the failing test** — `tests/Feature/Models/ProductKindTest.php`

```php
it('defaults products to physical', function () {
    expect(Product::factory()->create()->refresh()->kind)->toBe(ProductKind::Physical);
});

it('makes a digital work purchasable only with a file', function () {
    $work = Product::factory()->digital()->create();
    expect($work->isPurchasable())->toBeFalse();

    ProductFile::factory()->for($work)->create();
    expect($work->refresh()->isPurchasable())->toBeTrue()
        ->and($work->stock)->toBe(0);
});

it('makes a physical product purchasable only in stock', function () {
    expect(Product::factory()->create(['stock' => 1])->isPurchasable())->toBeTrue()
        ->and(Product::factory()->soldOut()->create()->isPurchasable())->toBeFalse();
});

it('tells which kinds an order holds', function () {
    $order = Order::factory()->create();
    $order->items()->create(['product_id' => null, 'product_name' => 'PDF', 'unit_price' => 500, 'quantity' => 1, 'product_kind' => ProductKind::Digital]);

    expect($order->refresh()->hasDigital())->toBeTrue()->and($order->hasPhysical())->toBeFalse();
});

it('keeps an order item kind physical by default', function () {
    $order = Order::factory()->create();
    $item = $order->items()->create(['product_id' => null, 'product_name' => 'マグ', 'unit_price' => 1800, 'quantity' => 1]);

    expect($item->refresh()->product_kind)->toBe(ProductKind::Physical);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test tests/Feature/Models/ProductKindTest.php`
Expected: FAIL（`ProductKind` がない）

- [ ] **Step 3: Implement** — マイグレーション3つ（§5 の表のとおり。`products.kind` は `string(16)->default('physical')->after('creator_id')`、`product_files` は `(product_id, sort_order)` のインデックス、`order_items.product_kind` は `string(16)->default('physical')->after('product_name')`）、enum、モデル、ファクトリ、config。`OrderFactory` が明細を作らない前提で `hasPhysical()` は明細が0件なら false

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail artisan test tests/Feature/Models/ProductKindTest.php`
Expected: PASS

- [ ] **Step 5: Commit** — `feat: 商品に種類（グッズ・デジタル作品）とファイルのテーブルを足す`

---

### Task 2: ファイルの保存・署名付きURLの共通化と作品のファイルの保存

**Files:**
- Create: `app/Services/PrivateFileWriter.php`、`app/Services/PrivateFileUrls.php`、`app/Services/Shop/ProductFileStorer.php`
- Modify: `app/Services/PostFileStorer.php`（`storeAttachment` と `put` を `PrivateFileWriter` に移す）、`app/Services/PostFileUrls.php`（`sign` を `PrivateFileUrls` に移す）、`app/Console/Commands/PruneOrphanFiles.php`
- Test: `tests/Feature/Files/ProductFileStorerTest.php`、`tests/Feature/Files/PrivateFileUrlsTest.php`、`tests/Feature/Console/PruneOrphanFilesTest.php`（追記）。既存の `PostFileStorerTest`、`PostFileUrlsTest` はそのまま通ること

**Interfaces:**
- Produces:
  - `PrivateFileWriter::writeUpload(string $base, UploadedFile $upload): array{path: string, mime: string, size: int}` — 拡張子の正規化（`/^[a-z0-9]{1,10}$/` 以外は `bin`）とストリームでの書き込み。失敗は `RuntimeException`（書いたファイルは消してから投げる）
  - `PrivateFileUrls::sign(string $path, array $options = []): string`、`PrivateFileUrls::download(string $path, string $originalName): string`（`ResponseContentDisposition` 付き）
  - `ProductFileStorer::store(Product $product, UploadedFile $upload): ProductFile`（`sort_order` は最大＋1、`original_name` は `Str::limit(..., 250, '')`）、`ProductFileStorer::delete(ProductFile $file): void`（行とファイル）

- [ ] **Step 1: Write the failing tests**

```php
// ProductFileStorerTest
beforeEach(fn () => fakePrivateDisk());

it('stores a work file on the private disk', function () {
    $work = Product::factory()->digital()->create();

    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('画集 vol.1.zip', 2048, 'application/zip'));

    expect($file->path)->toStartWith("creators/{$work->creator_id}/products/{$work->id}/")->toEndWith('.zip')
        ->and($file->original_name)->toBe('画集 vol.1.zip')
        ->and($file->size)->toBe(2048 * 1024)
        ->and($file->sort_order)->toBe(1);
    Storage::disk('private')->assertExists($file->path);
});

it('deletes the row and the stored file', function () {
    $work = Product::factory()->digital()->create();
    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('a.pdf', 10));

    app(ProductFileStorer::class)->delete($file);

    expect(ProductFile::query()->count())->toBe(0);
    Storage::disk('private')->assertMissing($file->path);
});

// PrivateFileUrlsTest
it('signs a download with the encoded original name', function () {
    Storage::fake('private');
    Storage::disk('private')->buildTemporaryUrlsUsing(fn ($path, $exp, array $options) => 'https://signed.test/'.$path.'#'.($options['ResponseContentDisposition'] ?? ''));

    expect(app(PrivateFileUrls::class)->download('a/b.zip', '画集 vol.1.zip'))
        ->toBe("https://signed.test/a/b.zip#attachment; filename*=UTF-8''".rawurlencode('画集 vol.1.zip'));
});

// PruneOrphanFilesTest に追記
it('keeps product files and prunes orphans under products', function () { /* product_files の path は残り、行のない private の creators/1/products/1/x.zip（25時間前）は消える */ });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail artisan test tests/Feature/Files tests/Feature/Console/PruneOrphanFilesTest.php`
Expected: 新しいテストが FAIL、既存は PASS

- [ ] **Step 3: Implement** — 上の Interfaces のとおり。`PostFileStorer` と `PostFileUrls` は新しいクラスを注入して使い、振る舞いは変えない。`PruneOrphanFiles` の `private` の照合に `ProductFile::query()->whereIn('path', $paths)->pluck('path')` を merge する

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test tests/Feature/Files tests/Feature/Console/PruneOrphanFilesTest.php`
Expected: PASS

- [ ] **Step 5: Commit** — `refactor: 非公開ファイルの書き込みと署名付きURLを共通化し、作品のファイルを保存できるようにする`

---

### Task 3: クリエイターの商品管理（種類の選択とファイル）

**Files:**
- Create: `app/Http/Controllers/Creator/ProductFileController.php`、`app/Http/Requests/Creator/StoreProductFileRequest.php`、`app/Http/Requests/Creator/ReorderProductFilesRequest.php`
- Modify: `app/Http/Requests/Creator/ProductRules.php`、`StoreProductRequest.php`、`app/Http/Controllers/Creator/ProductController.php`、`routes/creator.php`、`resources/js/pages/creator/products/edit.tsx`、`resources/js/pages/creator/products/index.tsx`
- Test: `tests/Feature/Creator/ProductFileManagementTest.php`、`tests/Feature/Creator/ProductManagementTest.php`（追記）

**Interfaces:**
- Consumes: Task 1 の `ProductKind`、`Product::files()`、Task 2 の `ProductFileStorer`、`PrivateFileUrls::download`
- Produces: ルート（名前は既存の `creator.` 接頭辞）
  - `POST creator/products/{product}/files` → `creator.products.files.store`（`throttle:30,1`、フィールド `file`）
  - `PUT creator/products/{product}/files` → `creator.products.files.reorder`（`ids`）
  - `DELETE creator/products/{product}/files/{file}` → `creator.products.files.destroy`
  - `GET creator/products/{product}/files/{file}` → `creator.products.files.download`（署名付きURLへ転送）
  - `edit` の props の `product` に `kind`、`files: list<{id, original_name, size, mime}>` を足す。`index` の各行に `kind`、`files_count`

- [ ] **Step 1: Write the failing tests**

```php
// ProductManagementTest に追記
it('creates a digital work without stock', function () {
    $this->post(route('creator.products.store'), ['kind' => 'digital', 'name' => 'イラスト集', 'price' => 800, 'is_published' => false])
        ->assertRedirect();
    expect(Product::query()->sole())->kind->toBe(ProductKind::Digital)->stock->toBe(0);
});

it('does not change the kind on update', function () { /* update に kind=physical を送っても digital のまま */ });

it('refuses to publish a digital work without files', function () {
    // payable なクリエイターで is_published=true → errors に 'is_published' => '公開するにはファイルを1件以上アップロードしてください'
});

// ProductFileManagementTest（fakePrivateDisk、payable なクリエイターでログイン）
it('uploads files to a digital work', ...);              // 201 ではなく back()。product_files が1件
it('rejects the eleventh file', ...);                     // 10件あるときに 'file' のエラー
it('rejects a file over the size limit', ...);            // UploadedFile::fake()->create('big.zip', 102401) → 'file' のエラー
it('rejects files on a physical product', ...);           // 404
it('reorders files', ...);
it('refuses to delete the last file of a published work', ...); // 'file' のエラー「公開中の作品からすべてのファイルを消すことはできません。」、ファイルは残る
it('deletes a file of an unpublished work', ...);
it('lets the creator download their own file', function () {
    // GET → assertRedirect、Location が https://signed.test/{path} で始まり &cd=1 を含む
});
it('hides other creators\' files', ...);                  // store / destroy / download がすべて 403 か 404
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail artisan test tests/Feature/Creator/ProductFileManagementTest.php tests/Feature/Creator/ProductManagementTest.php`
Expected: 新しいテストが FAIL

- [ ] **Step 3: Implement**
  - `ProductRules`: `stock` を `required_if:kind,physical`（更新時は商品の種類で判断する。`UpdateProductRequest` では `$this->route('product')->isDigital()` なら `stock` の規則を外す）。`StoreProductRequest` だけ `kind` を `required|Rule::enum(ProductKind::class)`
  - `ProductController::store` は `kind` を保存し、デジタルなら `stock = 0`。`update` はデジタルなら在庫の差分の処理を飛ばす。`ensureCanPublish` にデジタルでファイル0件のとき `is_published` のエラーを足す
  - `ProductFileController` は `ProductImageController` と同じ形。物の商品・他人の作品・別の作品のファイルは 404。件数の上限は Request の `after` で `product_files_max` と比べる
  - 画面: 作成時に「グッズ」「デジタル作品」を選ぶ（作成後は表示だけ）。デジタルは在庫の欄の代わりにファイルの欄（`useForm` でのアップロード、並べ替え、削除、ダウンロードのリンク、大きさの表示）。ファイル0件なら「公開するにはファイルを1件以上アップロードしてください」。一覧に「デジタル」の印とファイル数

- [ ] **Step 4: Run tests to verify they pass** — 同じコマンドで PASS
- [ ] **Step 5: Commit** — `feat: クリエイターがデジタル作品を作り、ファイルを管理できるようにする`

---

### Task 4: 購入済みの判定とダウンロードの可否（DigitalAccess）

**Files:**
- Create: `app/Services/Shop/DigitalAccess.php`
- Test: `tests/Feature/Shop/DigitalAccessTest.php`

**Interfaces:**
- Consumes: Task 1 のモデル
- Produces:
  - `DigitalAccess::hasPurchased(User $user, Product $product): bool` — `order_items` の `product_id` と `product_kind = digital`、注文の `user_id` と `status in (paid, shipped)`
  - `DigitalAccess::refusal(User $buyer, Order $order, Product $product): ?string` — ダウンロードできれば null、できなければ Global Constraints の文言。判定の順: 購入者の停止 → 注文の状態（pending / canceled / refunded）→ `disputed_at` → 作品（`withTrashed` で読み、公開中か）とクリエイター（`isShopVisible()`）。注文の持ち主と明細の確認は呼ぶ側（Task 8）が 404 で行う

- [ ] **Step 1: Write the failing tests** — 1段階目の `tests/Feature/Shop/OrdersTest.php` の `shopPaidOrder` と同じ手順で、デジタル作品の `paid` の注文を作る補助関数 `digitalPaidOrder(Product $work): Order` をこのファイルに置く

```php
it('allows a paid order', fn () => expect(app(DigitalAccess::class)->refusal($this->buyer, $order, $work))->toBeNull());
it('tells a pending order to wait', ...);                 // '支払いの確認後にダウンロードできます'
it('refuses after a full refund', ...);                   // gateway->refundPaymentIntent → OrderSync で refunded → '返金済みのためダウンロードできません'
it('keeps access after a partial refund', ...);           // Orders::syncRefund(ChargeData amountRefunded=100) → null
it('refuses a disputed order', ...);                      // 'お支払いに問題があったため、ダウンロードできません'
it('refuses when the work is unpublished, hidden or deleted', ...); // dataset 3通り → 'この作品は現在公開されていないため、ダウンロードできません'
it('restores access when the work is published again', ...);
it('refuses when the creator closed or is suspended', ...);
it('refuses a suspended buyer', ...);                     // '現在ダウンロードできません'
it('knows what the buyer has purchased', function () {
    // paid と shipped は true、pending・canceled・refunded・他人の注文は false
});
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Shop/DigitalAccessTest.php` — FAIL
- [ ] **Step 3: Implement** `DigitalAccess`（状態を持たないサービス。クエリは1〜2本）
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: デジタル作品の購入済みとダウンロードの可否を判定する`

---

### Task 5: カートでの扱い

**Files:**
- Modify: `app/Services/Shop/Cart.php`、`app/Http/Controllers/Fan/CartController.php`（props に `kind`）、`resources/js/pages/fan/cart.tsx`
- Test: `tests/Feature/Fan/CartTest.php`（追記）

**Interfaces:**
- Consumes: `Product::isDigital()`、`Product::isPurchasable()`、`DigitalAccess::hasPurchased`
- Produces: `Group` の `shipping_fee` は物の明細があるときだけ。`Cart::refusal` は購入済みのデジタルを含むとき「購入済みの作品です」を返す（ほかは今のまま）

- [ ] **Step 1: Write the failing tests**

```php
it('keeps a digital work at quantity one', ...);           // add(…, 3) → quantity 1。2回 add しても 1。setQuantity(2) は 'cart' のエラー
it('refuses to add a purchased work', ...);               // 'cart' => '購入済みの作品です'
it('refuses to add a work without files', ...);           // 'cart' => '現在購入できません'
it('charges no shipping for a digital-only group', ...);  // shipping_fee 0、total = subtotal
it('charges shipping when a group mixes kinds', ...);     // shipping_fee = creator の shipping_fee
it('refuses a cart group with an already purchased work', function () {
    // カートに入れたあとで同じ作品の paid の注文を作る → purchasable false、refusal は '購入済みの作品です'
});
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Fan/CartTest.php` — FAIL
- [ ] **Step 3: Implement** — `add` は デジタルなら数量を 1 に固定し、`hasPurchased` とファイル0件を弾く（在庫の判定は物だけ）。`build` の `purchasable` は各明細 `isPurchasable()`（物は加えて `stock >= quantity`）かつ購入済みのデジタルがないこと。画面はデジタルの数量の欄を出さず「デジタル」の印
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: デジタル作品をカートに数量1で入れられるようにする`

---

### Task 6: 注文の確保・在庫・発送・返金

**Files:**
- Modify: `app/Services/Shop/Orders.php`
- Test: `tests/Feature/Shop/OrdersTest.php`（追記）

**Interfaces:**
- Consumes: `DigitalAccess::hasPurchased`、`Order::hasPhysical()` / `hasDigital()`
- Produces: `reserve` が明細に `product_kind` をコピーし、送料を物があるときだけ足す。`ship` はデジタルだけの注文を弾く。`refund` は `$by === Creator` でデジタルを含む注文を弾く。`restoreStock` は `product_kind = physical` の明細だけ

- [ ] **Step 1: Write the failing tests**

```php
it('reserves a digital work without touching stock', ...);   // stock 0 のまま、明細 product_kind digital、shipping_fee 0、total = price
it('adds shipping when the order has goods', ...);           // 混在で shipping_fee 500
it('refuses to reserve an already purchased work', ...);     // ValidationException 'cart'、注文は増えない、物の在庫も減らない
it('refuses to reserve a work without files', ...);
it('refuses to ship a digital-only order', ...);             // ValidationException 'order' => 'この注文は発送できません。'、状態は paid のまま
it('ships an order that mixes kinds', ...);
it('refuses a creator refund of an order with a digital work', ...); // 'order' => 'デジタル作品を含む注文は取り消せません。'、gateway の返金なし
it('lets an admin refund an order with a digital work and restocks only goods', ...);
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Shop/OrdersTest.php` — FAIL
- [ ] **Step 3: Implement** — `reserve` のループで種類を分ける。デジタルは `Product::query()->whereKey(...)->where('creator_id', ...)->listed()->lockForUpdate()->first()` で読み、ファイルの有無と `hasPurchased` を確かめる（どちらかがだめなら物と同じ `cart` のエラー）。送料は `$lines` に物があるときだけ
- [ ] **Step 4: Run** — PASS（既存の OrdersTest もすべて）
- [ ] **Step 5: Commit** — `feat: デジタル作品の注文で在庫・送料・発送・返金を種類ごとに扱う`

---

### Task 7: Checkout でお届け先を求めるかどうか

**Files:**
- Modify: `app/Billing/Data/PaymentCheckoutRequest.php`、`app/Billing/StripeGateway.php`、`app/Billing/FakeGateway.php`、`app/Services/Shop/OrderCheckout.php`、`resources/js/pages/fan/cart-confirm.tsx`、`app/Http/Controllers/Fan/CartController.php`（`confirm` の props に `has_physical`・`has_digital`）
- Test: `tests/Feature/Billing/StripeGatewayTest.php`、`tests/Feature/Fan/OrderCheckoutTest.php`、`tests/Feature/Fan/CheckoutConfirmTest.php`（追記）

**Interfaces:**
- Produces: `PaymentCheckoutRequest` の最後の引数に `public bool $collectShipping = true`。`StripeGateway` は false のとき `shipping_address_collection` と `phone_number_collection` を送らない。`FakeGateway` はセッションに `collect_shipping` を保存し、false なら `completePaymentCheckout` で `shipping` を null にする。`OrderCheckout::begin` は `$order->hasPhysical()` を渡す

- [ ] **Step 1: Write the failing tests**

```php
// StripeGatewayTest
it('omits shipping collection for a digital-only checkout', function () {
    // collectShipping: false → $request['params'] に 'shipping_address_collection' と 'phone_number_collection' のキーがない
});

// OrderCheckoutTest
it('checks out a digital-only cart without an address', function () {
    // begin → completePaymentCheckout → complete: 注文は paid、shipping_name は null、shipping_fee 0
});

// CheckoutConfirmTest
it('warns that digital works cannot be canceled', ...);  // has_digital true、has_physical false
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Billing/StripeGatewayTest.php tests/Feature/Fan/OrderCheckoutTest.php tests/Feature/Fan/CheckoutConfirmTest.php` — FAIL
- [ ] **Step 3: Implement** — 上のとおり。確認画面は `has_digital` なら「デジタル作品は購入後に取り消せません」と「作品が非公開になるとダウンロードできなくなるため、早めに保存してください」、`has_physical` が false ならお届けの案内を出さない
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: デジタル作品だけの購入ではお届け先と送料を求めない`

---

### Task 8: 購入者のダウンロードと注文の画面

**Files:**
- Create: `app/Http/Controllers/Fan/OrderFileDownloadController.php`
- Modify: `routes/fan.php`、`app/Http/Controllers/Fan/OrderController.php`、`resources/js/pages/fan/orders/show.tsx`、`resources/js/pages/fan/orders/index.tsx`
- Test: `tests/Feature/Fan/OrderFileDownloadTest.php`、`tests/Feature/Fan/OrdersTest.php`（追記）

**Interfaces:**
- Consumes: `DigitalAccess::refusal`、`PrivateFileUrls::download`
- Produces:
  - `GET orders/{order}/files/{file}` → `orders.files.download`（`whereNumber(['order', 'file'])`、`throttle:30,1`）。注文が自分のものでない・ファイルの作品がその注文のデジタルの明細にない・`refusal` が null でない → 404。そうでなければ `redirect()->away(...)`
  - `show` の各明細に `product_kind`、デジタルなら `downloads: {refusal: ?string, files: list<{id, original_name, size, url}>}`（`refusal` があるときは `files` は空）。`index` はデジタルだけで `paid` の注文の `status_label` を「購入済み」に

- [ ] **Step 1: Write the failing tests**

```php
it('redirects the buyer to a signed download url', ...);  // Location が https://signed.test/{path} で始まり &cd=1
it('returns 404 for someone else\'s order', ...);
it('returns 404 for a file of another work', ...);        // 同じ注文の別の作品・他人の作品のファイル
it('returns 404 when access is refused', ...);            // pending と refunded と非公開の3通りの dataset
it('lists the files on the order page', ...);             // assertInertia: items.0.downloads.files に1件、refusal null
it('shows why the files cannot be downloaded', ...);      // 返金後: refusal '返金済みのためダウンロードできません'、files 空
it('labels a paid digital-only order as purchased', ...); // index の status_label '購入済み'
```

- [ ] **Step 2: Run** `./vendor/bin/sail artisan test tests/Feature/Fan/OrderFileDownloadTest.php tests/Feature/Fan/OrdersTest.php` — FAIL
- [ ] **Step 3: Implement** — コントローラは `PostFileDownloadController` と同じ形。画面はデジタルの明細の下にファイル名・大きさ・「ダウンロード」、または理由。デジタルだけの注文はお届け先の欄を出さない
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: 購入者が注文の画面からデジタル作品をダウンロードできるようにする`

---

### Task 9: ショップの表示・クリエイターの注文・メール

**Files:**
- Modify: `app/Http/Controllers/Public/ProductPageController.php`、`app/Http/Controllers/Public/CreatorPageController.php`、`resources/js/pages/public/product.tsx`、`resources/js/pages/public/creator.tsx`、`app/Http/Controllers/Creator/OrderController.php`、`resources/js/pages/creator/orders/show.tsx`、`app/Mail/Shop/OrderConfirmed.php` とそのビュー
- Test: `tests/Feature/Public/ShopPageTest.php`、`tests/Feature/Creator/OrderManagementTest.php`、`tests/Feature/Shop/OrderMailTest.php`（追記）

**Interfaces:**
- Consumes: `DigitalAccess::hasPurchased`、`Order::hasPhysical()` / `hasDigital()`
- Produces:
  - 商品ページの props に `kind`、デジタルなら `files: list<{extension, size}>`（名前は出さない）、`files_total_size`、`purchased_order_id: ?int`（ログイン中の購入者の、その作品を含む最新の `paid` か `shipped` の注文）
  - ショップのタブの各商品に `kind`（売り切れの印は物だけ）
  - クリエイターの注文の詳細に `can_ship`（`paid` かつ `hasPhysical()`）と `can_refund`（`paid` かつ `!hasDigital()`）、明細に `product_kind`
  - `OrderConfirmed`（購入者あて）にデジタルを含むとき「注文の画面からダウンロードできます」と注文のURL

- [ ] **Step 1: Write the failing tests**

```php
it('shows a digital work with its files and no stock', ...);   // kind digital、files の extension 'zip'、files_total_size
it('links a purchased work to its order', ...);                // purchased_order_id = 注文の id
it('hides ship and refund for a digital-only order', ...);     // can_ship false、can_refund false
it('offers only shipping for a mixed order', ...);             // can_ship true、can_refund false
it('mentions downloads in the confirmation mail', ...);        // assertSeeInHtml('注文の画面からダウンロードできます')
```

- [ ] **Step 2: Run** 上の3ファイル — FAIL
- [ ] **Step 3: Implement** — 商品ページはデジタルなら「ダウンロード販売」、ファイルの数と合計の大きさ、拡張子の一覧、数量の欄なし。購入済みなら「購入済み」と注文へのリンク。ショップのタブに「デジタル」の印。クリエイターの注文の画面はボタンを `can_ship` / `can_refund` で出し分け、明細に「デジタル」の印
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: ショップとクリエイターの注文の画面でデジタル作品を表示する`

---

### Task 10: 特定商取引法・運営の画面・デモデータ

**Files:**
- Modify: `resources/js/pages/legal/tokushoho.tsx`、`app/Http/Controllers/Admin/PaymentController.php`（ショップの注文を並べている箇所）、`resources/js/pages/admin/payments.tsx`、`database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Public/LegalPagesTest.php`、`tests/Feature/SeederTest.php`（追記）

- [ ] **Step 1: Write the failing tests**

```php
it('describes digital sales on the tokushoho page', ...);  // ページの文言に「引き渡し時期（デジタル作品）」と「作品が公開されているあいだ」
it('seeds a digital work with a file', ...);              // Product::where('kind', 'digital')->has('files')->exists()
```

- [ ] **Step 2: Run** 対象のテスト — FAIL
- [ ] **Step 3: Implement** — 特商法は §7.3 の6項目を、既存の「（グッズ）」の項目と並べて「（デジタル作品）」として足す。運営の注文の一覧・詳細の明細に種類の印。デモデータはデモクリエイターにデジタル作品を1件（`private` ディスクに小さな PDF かテキストの ZIP を書き、`product_files` を1件）
- [ ] **Step 4: Run** — PASS
- [ ] **Step 5: Commit** — `feat: 特定商取引法のページにデジタル作品の項目を足し、デモデータを用意する`

---

### Task 11: 全体の確認

- [ ] **Step 1:** `./vendor/bin/sail composer ci:check` — すべて PASS
- [ ] **Step 2:** `./vendor/bin/sail artisan migrate:fresh --seed` のあと、ブラウザでデモのファンとして、デジタル作品だけ（偽の決済）と、物とデジタルの混在をそれぞれ購入し、注文の画面からダウンロードできること、デジタルだけの Checkout でお届け先を聞かれないこと、クリエイターの注文の画面にボタンが出ないことを確かめる
- [ ] **Step 3:** 作品を非公開にするとダウンロードが 404 になり、注文の画面に理由が出ることを確かめる
