# 商品カテゴリ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 運営が `/admin/categories` で管理するカテゴリから、クリエイターが商品ごとに0〜1個選べるようにし、商品ページ・カテゴリページ・検索・ホームでカテゴリから商品を探せるようにする。

**Architecture:** 新テーブル `product_categories` と `ProductCategory` モデル、`products.product_category_id`（nullable・`nullOnDelete`）。「有効」は `archived_at IS NULL`。公開側は有効なカテゴリだけを扱い、アーカイブ済みのカテゴリが付いた商品は「未設定」として見せる。運営の操作は既存の `AdminAction::record` で記録する。

**Tech Stack:** Laravel 13 / Pest / Inertia v3 + React + TypeScript / Wayfinder（`@/actions`・`@/routes`）/ MySQL（テストも MySQL、`RefreshDatabase`）

**Spec:** `docs/superpowers/specs/2026-11-04-product-categories-design.md`

## Global Constraints

- テスト: `php artisan test --filter=...`（Sail を使う環境では `./vendor/bin/sail pest ...`）。Pint: `vendor/bin/pint --test app tests database routes`。PHPStan: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`。型: `npm run types:check`。フロントの整形・lint: `npm run check`
- カテゴリ名: `required|string|max:30`、一意。slug: `required|string|max:40|regex:/^[a-z0-9]+(-[a-z0-9]+)*$/`、一意
- 並び順: `sort_order` 昇順、同じなら `id` 昇順。新規追加は末尾（現在の最大 `sort_order` + 1）
- 公開側の商品の条件は既存と同じ: `Product::listed()` かつ `whereHas('creator', fn ($q) => $q->open())`
- カテゴリページは新着順（`created_at` 降順、`id` 降順）・24件ずつ
- 画面の文言（そのまま使う）: 「カテゴリ」「未設定」「（受付終了）」「カテゴリが正しくありません」「カテゴリを追加しました。」「カテゴリを更新しました。」「カテゴリをアーカイブしました。」「カテゴリを再開しました。」「カテゴリを削除しました。」「商品が付いているカテゴリは削除できません。アーカイブしてください。」「すべて」「このカテゴリの商品はまだありません。」
- `AdminAction` の action 名: `category.created` / `category.updated` / `category.moved` / `category.archived` / `category.unarchived` / `category.deleted`
- コミットメッセージは日本語の Conventional Commits（`feat: ...`）。末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- ブランチ: `claude/admiring-hopper-ar44zy`（設計書のコミットの上に積む）

## Review Focus

1. 編集中にカテゴリがアーカイブされても、その商品は選び直さずに保存できる（他の項目だけ変えても弾かれない）→ Task 3 のテスト `keeps an archived category the product already has`
2. 他のクリエイターや別商品のアーカイブ済みカテゴリの ID を送っても通らない → Task 3 のテスト `rejects an archived category the product does not have`
3. カテゴリページに、非公開・運営による非表示・削除済みの商品と、活動終了・利用停止クリエイターの商品が出ない → Task 4 のテスト `lists only listed products of open creators`
4. 検索で `category` に変な値（存在しない slug、アーカイブ済み、配列）を入れても 500 にならず、絞り込みなしになる → Task 5 のテスト `ignores an unknown or archived category`
5. 先頭を上へ・末尾を下へ動かしても何も壊れない（順番がそのまま）→ Task 2 のテスト `leaves the order alone at the ends`

---

### Task 1: `product_categories` テーブルとモデル

**Files:**

- Create: `database/migrations/2026_11_04_000002_create_product_categories_table.php`（テーブル作成と `products.product_category_id` の追加を1つのマイグレーションで）
- Create: `app/Models/ProductCategory.php`
- Create: `database/factories/ProductCategoryFactory.php`
- Modify: `app/Models/Product.php`
- Test: `tests/Feature/Models/ProductCategoryTest.php`

**Interfaces:**

- Produces:
  - `ProductCategory` — `#[Fillable(['name', 'slug', 'sort_order'])]`、casts `sort_order: integer`, `archived_at: datetime`
  - スコープ `active()`（`whereNull('archived_at')`）、`ordered()`（`orderBy('sort_order')->orderBy('id')`）
  - `isArchived(): bool`、`toSummary(): array{id: int, name: string, slug: string}`、`products(): HasMany<Product>`
  - `static nextSortOrder(): int` — 最大 `sort_order` + 1（0件なら 0）
  - `ProductCategory::activeSummaries(): list<array{id,name,slug}>` — `active()->ordered()->get()->map->toSummary()->all()`（ホーム・検索・カテゴリページで共用）
  - `Product::category(): BelongsTo<ProductCategory>`（外部キー `product_category_id`）、`Product::publicCategory(): ?ProductCategory`（読み込み済みのカテゴリが有効なときだけ返す）、スコープ `Product::inCategory(ProductCategory $category)`
  - `Product` の fillable に `product_category_id` を追加、PHPDoc に `@property int|null $product_category_id`
  - ファクトリ: `ProductCategoryFactory` の state `archived()`

- [ ] **Step 1: 失敗するテストを書く**

```php
it('lists active categories in order', function () {
    $b = ProductCategory::factory()->create(['name' => 'B', 'sort_order' => 1]);
    $a = ProductCategory::factory()->create(['name' => 'A', 'sort_order' => 0]);
    ProductCategory::factory()->archived()->create(['sort_order' => 2]);

    expect(ProductCategory::activeSummaries())->toBe([$a->toSummary(), $b->toSummary()]);
});

it('shows only an active category publicly', function () {
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create();
    expect($product->publicCategory()?->id)->toBe($product->product_category_id);

    $product->category->forceFill(['archived_at' => now()])->save();
    expect($product->fresh()->publicCategory())->toBeNull();
});

it('unsets the category when the category is deleted', function () {
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create();
    $product->category->delete();
    expect($product->fresh()->product_category_id)->toBeNull();
});

it('puts a new category last', function () {
    expect(ProductCategory::nextSortOrder())->toBe(0);
    ProductCategory::factory()->create(['sort_order' => 4]);
    expect(ProductCategory::nextSortOrder())->toBe(5);
});
```

- [ ] **Step 2: 実行して失敗を確認** — `php artisan test --filter=ProductCategoryTest` → クラスが無いエラー
- [ ] **Step 3: マイグレーション・モデル・ファクトリ・`Product` の追加を実装**。列は設計書 §4 のとおり（`name` varchar(30) unique、`slug` varchar(40) unique、`sort_order` unsigned int default 0、`archived_at` nullable datetime）。`products.product_category_id` は `foreignId(...)->nullable()->after('creator_id')->constrained('product_categories')->nullOnDelete()`
- [ ] **Step 4: テストが通ることを確認** — 同じコマンドで PASS
- [ ] **Step 5: コミット** — `feat: 商品カテゴリのテーブルとモデルを追加する`

### Task 2: 運営のカテゴリ管理画面

**Files:**

- Create: `app/Http/Controllers/Admin/CategoryController.php`
- Create: `app/Http/Requests/Admin/CategoryRequest.php`（追加・変更で共用。`unique` は更新時に自分を除く）
- Modify: `routes/admin.php`
- Create: `resources/js/pages/admin/categories.tsx`
- Modify: `resources/js/layouts/admin/layout.tsx`（`adminNavItems` の「会員」の後に `{ title: 'カテゴリ', href: categories() }`）
- Test: `tests/Feature/Admin/CategoryAdminTest.php`

**Interfaces:**

- Consumes: Task 1 の `ProductCategory`
- Produces: ルート `admin.categories.index|store|update|move|archive|destroy`（設計書 §5 のメソッドとパス。ルートパラメータ名は `category`、ID で解決）
  - `index` → Inertia `admin/categories`、props `categories: list<{id, name, slug, archived: bool, products_count: int}>`（`ordered()`、`withCount('products')` は soft delete 済みを含まない）
  - `move` は `direction: in:up,down` を受け、`ordered()` で隣のカテゴリと `sort_order` を入れ替える（アーカイブ済みも並びに含める）。端では何もしない。入れ替えは `DB::transaction` 内で、並びを `sort_order` を 0 から振り直してから入れ替えると同値の `sort_order` でも確実に動く
  - `archive` は `archived: boolean` を受ける
  - `destroy` は商品（削除済みを除く）が1件でもあれば `ValidationException`（キー `category`、文言は Global Constraints）。削除済み商品は外部キーで「未設定」になる
  - すべて成功時に `Inertia::flash('toast', ['type' => 'success', 'message' => ...])` を出し `back()`、`AdminAction::record($admin, 'category.*', $category)` を記録

- [ ] **Step 1: 失敗するテストを書く**（`beforeEach` で `User::factory()->admin()->create()`）

```php
it('forbids non-admins', fn () => $this->actingAs(User::factory()->create())
    ->get(route('admin.categories.index'))->assertForbidden());

it('lists categories with their product counts', ...);          // products_count が削除済み商品を数えない
it('adds a category at the end', ...);                           // sort_order = 既存最大 + 1、トースト「カテゴリを追加しました。」、AdminAction category.created
it('validates name and slug', function (array $input, string $field) { ... })->with([
    'blank name' => [['name' => '', 'slug' => 'ok'], 'name'],
    'long name' => [['name' => str_repeat('あ', 31), 'slug' => 'ok'], 'name'],
    'bad slug' => [['name' => 'OK', 'slug' => 'Bad_Slug'], 'slug'],
    'duplicate slug' => [['name' => '別名', 'slug' => 'taken'], 'slug'],   // 既存 slug 'taken'
    'duplicate name' => [['name' => '既存', 'slug' => 'new'], 'name'],     // 既存 name '既存'
]);
it('renames a category keeping its own slug', ...);              // 自分の name/slug のままの更新は unique に引っかからない
it('moves a category up and down', ...);                         // [A,B,C] で B を up → [B,A,C]、down → [A,C,B]
it('leaves the order alone at the ends', ...);                   // 先頭 up / 末尾 down で順番不変
it('archives and unarchives a category', ...);                   // archived_at の set/null、AdminAction 2件
it('deletes an unused category', ...);                           // 削除済み商品だけ付いていれば削除できる
it('refuses to delete a category in use', ...);                  // assertSessionHasErrors('category')、行は残る
```

- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=CategoryAdminTest`
- [ ] **Step 3: コントローラ・リクエスト・ルートを実装**
- [ ] **Step 4: テストが通ることを確認**
- [ ] **Step 5: 画面 `admin/categories.tsx` を作る**。既存の `admin/users.tsx` と同じ `AdminLayout` とカード/表の見た目に合わせる。上部に追加フォーム（名前・slug）、下に一覧。各行: 名前・slug の入力と「保存」、商品数、「↑」「↓」（先頭・末尾では無効）、「アーカイブ」/「再開」、「削除」（商品数が 0 のときだけ有効、`confirm` で確認）。アーカイブ済みの行は薄く表示し「アーカイブ済み」のバッジ。フォームは Wayfinder の `@/actions/App/Http/Controllers/Admin/CategoryController` を使う
- [ ] **Step 6: `npm run types:check` と `npm run check` が通ることを確認**
- [ ] **Step 7: コミット** — `feat: 運営がカテゴリを管理できるようにする`

### Task 3: クリエイターの商品フォームでカテゴリを選ぶ

**Files:**

- Modify: `app/Http/Requests/Creator/ProductRules.php`（`product_category_id` のルールと属性名「カテゴリ」）
- Modify: `app/Http/Requests/Creator/StoreProductRequest.php`, `UpdateProductRequest.php`（「今付いているカテゴリ」の ID を返す `protected function currentCategoryId(): ?int` — Store は `null`、Update はルートの商品の `product_category_id`）
- Modify: `app/Http/Controllers/Creator/ProductController.php`（`create`/`edit` に `categories`、`store`/`update` で保存）
- Modify: `resources/js/pages/creator/products/edit.tsx`
- Test: `tests/Feature/Creator/ProductCategorySelectionTest.php`

**Interfaces:**

- Consumes: Task 1 の `ProductCategory::active()`, `ordered()`
- Produces:
  - ルール: `'product_category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->whereNull('archived_at')->when($this->currentCategoryId(), fn ($q, $id) => $q->orWhere('id', $id)))]`（`orWhere` は `whereNull` と括弧でまとめること）。エラー文言は `カテゴリが正しくありません`（`messages()` で `product_category_id.exists` と `.integer` に設定）
  - 画面 props `categories: list<{id, name, archived: bool}>` — 有効なものを `ordered()` で。編集時、商品のカテゴリがアーカイブ済みならその1件を `archived: true` で末尾に足す
  - 画面 props `product.category_id: number | null`
  - 保存: 空文字・未送信は `null`

- [ ] **Step 1: 失敗するテストを書く**（既存 `tests/Feature/Creator/ProductManagementTest.php` の作成・更新リクエストの形に合わせる）

```php
it('creates a product with a category', ...);                    // product_category_id が保存される（グッズ・デジタル両方を dataset で）
it('changes and clears the category', ...);                      // 別カテゴリ → '' で null
it('offers active categories in order on the form', ...);        // create 画面の categories がアーカイブ済みを含まない
it('rejects an archived or missing category', ...);              // store でアーカイブ済み ID / 999999 → assertSessionHasErrors(['product_category_id' => 'カテゴリが正しくありません'])
it('keeps an archived category the product already has', ...);   // 付いた後にアーカイブ → 名前だけ変えて update が成功、カテゴリはそのまま。edit 画面の categories に archived: true で含まれる
it('rejects an archived category the product does not have', ...); // 別のアーカイブ済み ID への変更は拒否
```

- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=ProductCategorySelectionTest`
- [ ] **Step 3: ルール・コントローラを実装**
- [ ] **Step 4: テストと既存の `ProductManagementTest` が通ることを確認**
- [ ] **Step 5: `edit.tsx` に選択欄を追加**。価格の欄の近くに `<select name="product_category_id">`（先頭 `<option value="">未設定</option>`、アーカイブ済みは名前の後に「（受付終了）」）。ラベル「カテゴリ」、`<InputError message={errors.product_category_id} />`。既存の `Form` の `name` 属性で送る方式に合わせる。`categories` が空なら欄ごと出さない
- [ ] **Step 6: `npm run types:check` / `npm run check`**
- [ ] **Step 7: コミット** — `feat: 商品にカテゴリを選べるようにする`

### Task 4: 商品ページの表示とカテゴリページ

**Files:**

- Modify: `app/Http/Controllers/Public/ProductPageController.php`（`with` に `category`、props `product.category`）
- Create: `app/Http/Controllers/Public/CategoryPageController.php`
- Modify: `routes/web.php`（`Route::get('categories/{category:slug}', CategoryPageController::class)->name('categories.show')`、`/@{creator}` 系より前）
- Create: `resources/js/pages/public/category.tsx`
- Create: `resources/js/components/category-links.tsx`（カテゴリのリンクの1行。Task 5・6 でも使う）
- Modify: `resources/js/pages/public/product.tsx`（`<h1>` の上にバッジ）
- Modify: `resources/js/types/*`（`CategorySummary = { id: number; name: string; slug: string }`）
- Test: `tests/Feature/Public/CategoryPageTest.php`

**Interfaces:**

- Consumes: Task 1 の `ProductCategory::activeSummaries()`, `Product::inCategory()`, `publicCategory()`
- Produces:
  - 商品ページ props `product.category: CategorySummary | null`（`publicCategory()?->toSummary()`）
  - カテゴリページ: アーカイブ済みは `abort(404)`。props `category: CategorySummary`、`categories: CategorySummary[]`（有効な一覧）、`products`（`paginate(24)->withQueryString()` を `through` でホームと同じ形 `[...toSummary(), creator, liked, can_like]` に。`liked` は `ProductLike::idsLikedBy`）
  - `CategoryLinks({ categories, current?: string | null, href: (slug: string | null) => string, allLabel?: string })` — `allLabel` を渡したときだけ先頭に「すべて」を出す。選択中は強調

- [ ] **Step 1: 失敗するテストを書く**

```php
it('shows the active category on the product page', ...);        // product.category.slug、アーカイブ後は null
it('lists only listed products of open creators', ...);          // 非公開・hidden_at・soft delete・活動終了クリエイター・利用停止ユーザーの商品と、別カテゴリの商品が出ない（has('products.data', 1)）
it('orders products newest first and pages by 24', ...);         // 25件 → data 24件、2ページ目 1件
it('returns 404 for an archived or unknown category', ...);
```

- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=CategoryPageTest`
- [ ] **Step 3: コントローラ・ルートを実装**し、テストを通す
- [ ] **Step 4: 画面を作る**。`category.tsx`: 見出しにカテゴリ名、その下に `CategoryLinks`（`current` に今の slug）、`ProductCard` のグリッド（`search.tsx` と同じ `grid gap-4 sm:grid-cols-2 lg:grid-cols-4`）、0件なら「このカテゴリの商品はまだありません。」、既存のページ送りコンポーネントがあればそれを使う。商品ページのバッジは `categories.show` へのリンク
- [ ] **Step 5: `npm run types:check` / `npm run check`**
- [ ] **Step 6: コミット** — `feat: カテゴリページと商品ページのカテゴリ表示を追加する`

### Task 5: 検索のカテゴリ絞り込み

**Files:**

- Modify: `app/Http/Controllers/Public/SearchController.php`
- Modify: `resources/js/pages/public/search.tsx`
- Test: `tests/Feature/Public/SearchTest.php`（追記）

**Interfaces:**

- Consumes: Task 1 の `activeSummaries()`, `inCategory()`、Task 4 の `CategoryLinks`
- Produces: props `category: string | null`（有効な slug が指定されたときだけその slug）、`categories: CategorySummary[]`。`products($q, ?ProductCategory $category)` で絞る。`category` が文字列でない・見つからない・アーカイブ済みなら `null` 扱い

- [ ] **Step 1: 失敗するテストを書く**

```php
it('narrows products by category', ...);                         // 同じキーワードで 2商品、片方だけカテゴリ → has('products', 1)、creators・posts は影響なし、where('category', slug)
it('ignores an unknown or archived category', function ($value) { ... })->with([
    'unknown' => 'nope', 'archived' => fn () => ProductCategory::factory()->archived()->create()->slug, 'array' => [['x']],
]);                                                              // assertOk、products 2件、category null
```

- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=SearchTest`
- [ ] **Step 3: 実装してテストを通す**
- [ ] **Step 4: 画面**: 商品欄の見出しの下（キーワードがあり、種類が `all` か `products` のとき）に `CategoryLinks`（`allLabel="すべて"`、リンクは `q`・`type` を保って `category` だけ差し替え）。絞り込み中に商品が0件でも、切り替えが消えないよう商品欄の見出しと切り替えは出す
- [ ] **Step 5: `npm run types:check` / `npm run check`**
- [ ] **Step 6: コミット** — `feat: 検索結果の商品をカテゴリで絞り込めるようにする`

### Task 6: ホームのカテゴリリンクとデモデータ

**Files:**

- Modify: `app/Http/Controllers/Public/HomeController.php`（props `categories`）
- Modify: `resources/js/pages/public/home.tsx`（「おすすめ商品」のカルーセルの上に `CategoryLinks`、空なら出さない）
- Create: `database/seeders/DemoCategoriesSeeder.php`
- Modify: `database/seeders/DemoCreatorsSeeder.php`（`DemoProductsSeeder` の後に `$this->call(DemoCategoriesSeeder::class)`）
- Test: `tests/Feature/Public/HomeCategoriesTest.php`、`tests/Feature/SeederTest.php`（追記）

**Interfaces:**

- Consumes: Task 1, Task 4 の `CategoryLinks`
- Produces: `DemoCategoriesSeeder` — slug で `updateOrCreate` する固定の一覧と、カテゴリ未設定のデモ商品への割り当て（商品名の前方一致）:

| slug           | name             | 割り当てる商品名                          |
| -------------- | ---------------- | ----------------------------------------- |
| `acrylic`      | アクリルグッズ   | `アクリルスタンド*`, `アクリルチャーム`   |
| `badges-cheki` | 缶バッジ・チェキ | `缶バッジ`, `ランダムチェキ*`             |
| `apparel`      | アパレル・布もの | `Tシャツ`, `タペストリー`, `パスケース`   |
| `illustration` | イラスト集       | `ラフ画集`                                |
| `music`        | 音楽             | （なし）                                  |
| `books`        | 本・同人誌       | （なし）                                  |

  ファクトリを使わない（本番でも動く）。既にカテゴリが付いた商品は変えない。

- [ ] **Step 1: 失敗するテストを書く**

```php
it('passes the active categories to the home page', ...);        // アーカイブ済みを含まない、並び順
it('seeds demo categories once and assigns them', ...);          // SeederTest: 2回 seed しても 6件、アクリルスタンドA が acrylic、ラフ画集が illustration、手で変えたカテゴリは上書きされない
```

- [ ] **Step 2: 失敗を確認**
- [ ] **Step 3: 実装してテストを通す**
- [ ] **Step 4: `home.tsx` に表示を追加し、`npm run types:check` / `npm run check`**
- [ ] **Step 5: コミット** — `feat: ホームにカテゴリを表示しデモカテゴリを追加する`

### Task 7: 全体の確認

- [ ] **Step 1:** `php artisan test` 全体、`vendor/bin/pint --test app tests database routes`、`vendor/bin/phpstan analyse --no-progress --memory-limit=1G`、`npm run types:check`、`npm run check` がすべて通る
- [ ] **Step 2:** 画面の確認（`run` スキルでアプリを起動できれば）: 運営で追加・並び替え・アーカイブ → クリエイターの商品で選択 → 商品ページのバッジ → カテゴリページ → 検索の絞り込み → ホームのリンク
- [ ] **Step 3:** 設計書のステータスを「実装済み」に更新してコミット・プッシュ
