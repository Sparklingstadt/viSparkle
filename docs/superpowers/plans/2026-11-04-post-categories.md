# 投稿カテゴリ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 運営が `/admin/post-categories` で管理する投稿カテゴリから、クリエイターが投稿ごとに0〜1個選べるようにし、投稿ページ・カテゴリ別の投稿一覧・検索・ホーム・クリエイターページでカテゴリから投稿を探せるようにする。

**Architecture:** 新テーブル `post_categories` と `PostCategory` モデル（`ProductCategory` と同じ公開メソッド。共通部分はトレイト `App\Models\Concerns\IsCategory` に移す）、`posts.post_category_id`（nullable・`nullOnDelete`）。運営画面は既存の `Admin\CategoryController` の処理を抽象クラス `Admin\CategoryAdminController` に移し、商品用・投稿用の2つの具象コントローラーと、props で切り替える1つの画面で賄う。公開一覧（カテゴリ別・検索・ホーム）は無料投稿だけ、クリエイターページは全投稿を対象にする。

**Tech Stack:** Laravel 13 / Pest / Inertia v3 + React + TypeScript / Wayfinder（`@/actions`・`@/routes`）/ MySQL（CI）

**Spec:** `docs/superpowers/specs/2026-11-04-post-categories-design.md`

## Global Constraints

- 手元のテスト環境: すべての php / artisan / vendor / npm コマンドの前に `export PATH=/opt/php85/bin:$PATH DB_CONNECTION=sqlite DB_DATABASE=:memory:;` を付ける（MySQL ドライバと GD が無い）。main で既に落ちるテスト（GD・SQLite 由来）は無視し、新たに落ちるものを出さない。CI（PHP 8.4 + MySQL 8.4）が最終判断。スキーマと SQL は両方で動く書き方にする
- ルート追加後は `php artisan wayfinder:generate --with-form` を実行する（生成物は git 管理外）
- チェック: `php artisan test`、`vendor/bin/pint --test app tests database routes`、`vendor/bin/phpstan analyse --no-progress --memory-limit=1G`、`npm run types:check`、`npm run check`
- カテゴリ名 `required|string|max:30` 一意、slug `required|string|max:40|regex:/^[a-z0-9]+(-[a-z0-9]+)*$/` 一意（`post_categories` 内で）。並び順は `sort_order` 昇順→`id` 昇順。新規は末尾
- 商品カテゴリの既存の URL・ルート名・挙動・テストは変えない（既存テストがそのまま通ること）
- 公開一覧（カテゴリ別一覧・検索・ホーム）の投稿条件はホームの全体公開投稿と同じ: `Post::published()->visible()->where('required_price', 0)->whereHas('creator.user', fn ($q) => $q->whereNull('suspended_at'))`
- カテゴリ別の投稿一覧は `published_at` 降順→`id` 降順・20件ずつ
- 画面の文言（そのまま使う）: 「カテゴリ」「未設定」「（受付終了）」「カテゴリが正しくありません」「商品」「投稿」「投稿 N 件」「すべて」「このカテゴリの投稿はまだありません。」「このカテゴリの投稿は見つかりませんでした。」「投稿が付いているカテゴリは削除できません。アーカイブしてください。」。追加・更新・アーカイブ・再開・削除のトーストは商品カテゴリと同じ文言
- `AdminAction` の action 名: `post_category.created` / `post_category.updated` / `post_category.moved` / `post_category.archived` / `post_category.unarchived` / `post_category.deleted`
- クエリ名: 検索とクリエイターページは `post_category`。商品の `category` とは独立
- コミットは日本語の Conventional Commits。末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` と `Claude-Session: https://claude.ai/code/session_01QKzq5SLCPyK84HCcTHy798`
- ブランチ: `claude/admiring-hopper-ar44zy`（設計書のコミットの上）

## Review Focus

1. カテゴリが付いた有料投稿が、カテゴリ別一覧・検索・ホームに一切出ない → Task 4 `lists only free published posts of active members`、Task 5 `never lists a paid post even when it matches the category`
2. クリエイターページの絞り込みで、有料投稿がロック表示のまま出る（本文が漏れない）→ Task 6 `filters paid posts too and keeps them locked`
3. クリエイターページの切り替えに、下書き・予約投稿にしか使われていないカテゴリが出ない → Task 6 `offers only categories used by published posts`
4. 投稿カテゴリ用の運営ルートに商品カテゴリの ID を渡しても商品カテゴリが変わらない（別モデルの束縛）→ Task 2 `does not touch product categories from the post routes`
5. 商品カテゴリ画面の既存の挙動が変わらない → Task 2 で既存の `CategoryAdminTest` を変更せずに通す

---

### Task 1: `post_categories` テーブルとモデル

**Files:**

- Create: `database/migrations/2026_11_04_000003_create_post_categories_table.php`（テーブル作成と `posts.post_category_id` 追加。列は設計書 §4）
- Create: `app/Models/Concerns/IsCategory.php`（`active()`・`ordered()` スコープ、`isArchived()`、`nextSortOrder()`、`activeSummaries()`、`toSummary()`、casts の共通部分）
- Modify: `app/Models/ProductCategory.php`（トレイトを使う形に置き換え。外から見た振る舞いは変えない）
- Create: `app/Models/PostCategory.php`、`database/factories/PostCategoryFactory.php`（state `archived()`）
- Modify: `app/Models/Post.php`（`#[Fillable]` に `post_category_id` を足す。`@property int|null $post_category_id`）
- Test: `tests/Feature/Models/PostCategoryTest.php`

**Interfaces:**

- Produces: `PostCategory`（`#[Fillable(['name', 'slug', 'sort_order'])]`、`active()`、`ordered()`、`isArchived()`、`toSummary(): array{id:int,name:string,slug:string}`、`static nextSortOrder(): int`、`static activeSummaries(): list<array{id:int,name:string,slug:string}>`、`posts(): HasMany<Post>`）
- Produces: `Post::category(): BelongsTo<PostCategory>`（外部キー `post_category_id`）、`Post::publicCategory(): ?PostCategory`、スコープ `Post::inCategory(PostCategory $category)`

- [ ] **Step 1: 失敗するテストを書く** — `tests/Feature/Models/ProductCategoryTest.php` と同じ4本を `PostCategory`／`Post` 版で: `lists active categories in order`、`shows only an active category publicly`、`unsets the category when the category is deleted`、`puts a new category last`
- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=PostCategoryTest`
- [ ] **Step 3: 実装**（トレイトへの移動を含む）
- [ ] **Step 4: `PostCategoryTest` と既存の `ProductCategoryTest` が通る**
- [ ] **Step 5: コミット** — `feat: 投稿カテゴリのテーブルとモデルを追加する`

### Task 2: 運営の投稿カテゴリ管理画面

**Files:**

- Create: `app/Http/Controllers/Admin/CategoryAdminController.php`（abstract。既存 `CategoryController` の index / store / update / move / archive / destroy の処理を移す）
- Modify: `app/Http/Controllers/Admin/CategoryController.php`（商品用の具象クラスに）
- Create: `app/Http/Controllers/Admin/PostCategoryController.php`
- Modify: `app/Http/Requests/Admin/CategoryRequest.php`（ルートの束縛値のテーブルで unique を判定。商品・投稿の両方で使う）
- Modify: `routes/admin.php`（`admin.post-categories.*`、パスは `post-categories`、メソッドは商品と同じ）
- Modify: `resources/js/pages/admin/categories.tsx`（props で種類を切り替え）
- Modify: `resources/js/layouts/admin/layout.tsx`（「カテゴリ」を `/admin/post-categories` でも選択中に）
- Modify: `tests/Feature/Admin/AdminAccessTest.php`（データセットに6ルート追加）
- Test: `tests/Feature/Admin/PostCategoryAdminTest.php`

**Interfaces:**

- 抽象メソッド: `abstract protected function model(): string`（クラス名）、`abstract protected function kind(): string`（`'products'|'posts'`）、`abstract protected function actionPrefix(): string`（`'category'|'post_category'`）、`abstract protected function inUseMessage(): string`、`abstract protected function usageRelation(): string`（`'products'|'posts'`）。ルートの束縛はそれぞれの具象メソッドの型（`ProductCategory $category` / `PostCategory $category`）で行う
- 画面 props: `kind: 'products' | 'posts'`、`categories: list<{id, name, slug, archived: bool, usage_count: int}>`（既存の `products_count` は `usage_count` に名前を変える。画面だけが使う）。上部に「商品 / 投稿」の切り替え（`admin.categories.index` / `admin.post-categories.index` へのリンク）。件数の表示は `kind` に応じて「商品 N 件」「投稿 N 件」。フォームの送り先は `kind` に応じて Wayfinder の `CategoryController` / `PostCategoryController` を使い分ける

- [ ] **Step 1: 失敗するテストを書く** — `tests/Feature/Admin/CategoryAdminTest.php` の各テストを投稿版で（`posts` を付けて件数・削除拒否を確かめる。`Post` は SoftDeletes を使っていないので、削除済みの扱いのテストは要らない）。加えて:

```php
it('does not touch product categories from the post routes', function () {
    $product = ProductCategory::factory()->create(['name' => '商品側']);
    $this->actingAs($this->admin)->put(route('admin.post-categories.update', $product->id), ['name' => '変更', 'slug' => 'changed'])
        ->assertNotFound();
    expect($product->fresh()->name)->toBe('商品側');
});
```

（`PostCategory` が同じ ID で存在しないことを前提にする。存在するなら ID をずらす）

- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=PostCategoryAdminTest`
- [ ] **Step 3: 実装**
- [ ] **Step 4: `PostCategoryAdminTest`・既存の `CategoryAdminTest`（変更しない）・`AdminAccessTest` が通る**
- [ ] **Step 5: 画面を更新し、`npm run types:check` / `npm run check`**
- [ ] **Step 6: コミット** — `feat: 運営が投稿カテゴリを管理できるようにする`

### Task 3: クリエイターの投稿フォームでカテゴリを選ぶ

**Files:**

- Modify: `app/Http/Requests/Creator/UpdatePostRequest.php`（`post_category_id`: `nullable|integer` ＋ 有効なカテゴリ、またはその投稿に今付いているカテゴリのみ。`orWhere` は内側の `where(fn)` で括る。文言「カテゴリが正しくありません」、属性名「カテゴリ」）
- Modify: `app/Http/Controllers/Creator/PostController.php`（`edit` に `categories`、`post.category_id`。`update` で保存）
- Modify: `resources/js/pages/creator/posts/edit.tsx`（`useForm` の `FormData` に `post_category_id: number | ''` を足し、タイトルの下あたりに選択欄）
- Test: `tests/Feature/Creator/PostCategorySelectionTest.php`

**Interfaces:**

- Consumes: Task 1 の `PostCategory::active()`, `ordered()`
- Produces: 画面 props `categories: list<{id, name, archived: bool}>`（有効なものを並び順で。投稿のカテゴリがアーカイブ済みならその1件を `archived: true` で末尾に）、`post.category_id: number | null`。空文字・未送信は `null` で保存

- [ ] **Step 1: 失敗するテストを書く**（既存 `tests/Feature/Creator/PostManagementTest.php` の更新リクエストの形に合わせる）: `saves a category on the post`、`clears the category`、`offers active categories in order on the form`、`rejects an archived or missing category`、`keeps an archived category the post already has`（付いた後にアーカイブ → タイトルだけ変えて保存できる。edit の `categories` に `archived: true` で含まれる）、`rejects an archived category the post does not have`（その投稿に別のカテゴリが付いている状態で試す）
- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=PostCategorySelectionTest`
- [ ] **Step 3: 実装してテストと既存の `PostManagementTest` を通す**
- [ ] **Step 4: 画面: ラベル「カテゴリ」、`<select>`（先頭「未設定」、アーカイブ済みは名前の後に「（受付終了）」）、`InputError`。`categories` が空なら出さない。`npm run types:check` / `npm run check`**
- [ ] **Step 5: コミット** — `feat: 投稿にカテゴリを選べるようにする`

### Task 4: 投稿ページの表示とカテゴリ別の投稿一覧

**Files:**

- Modify: `app/Http/Controllers/Public/PostPageController.php`（`category` を eager load、props `post.category`）
- Create: `app/Http/Controllers/Public/PostCategoryPageController.php`
- Modify: `routes/web.php`（`Route::get('post-categories/{category:slug}', PostCategoryPageController::class)->name('post-categories.show')`、`/@{creator}` 系より前）
- Create: `resources/js/pages/public/post-category.tsx`
- Modify: `resources/js/pages/public/post.tsx`（タイトルの上にバッジ。商品ページのバッジと同じ見た目）
- Test: `tests/Feature/Public/PostCategoryPageTest.php`

**Interfaces:**

- Consumes: Task 1、既存の `PostPresenter::summary($post, $entitlement, $liked)`、`PostLike::idsLikedBy`、`CategoryLinks`（`@/components/category-links`）、`CategorySummary` 型
- Produces: 投稿ページ props `post.category: CategorySummary | null`。一覧ページ props: `category: CategorySummary`、`categories: CategorySummary[]`（有効な投稿カテゴリ）、`posts`（`paginate(20)->withQueryString()` を `through` で `PostPresenter::summary` に）

- [ ] **Step 1: 失敗するテストを書く**: `shows the active category on the post page`（アーカイブ後は null）、`lists only free published posts of active members`（有料・下書き・`published_at` が未来・非表示（`visible()` で外れるもの）・作者の利用停止・別カテゴリ・未設定が出ない。`has('posts.data', 1)`）、`orders posts newest first and pages by 20`（21件 → 20 と 1）、`returns 404 for an archived or unknown category`
- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=PostCategoryPageTest`
- [ ] **Step 3: 実装してテストを通す**
- [ ] **Step 4: 画面**: 見出しにカテゴリ名、`CategoryLinks`（`current` に slug、リンクは `post-categories.show`）、`PostCard`（`showCreator`）のグリッド（ホームと同じ列数）、0件なら「このカテゴリの投稿はまだありません。」、ページ送りは `category.tsx` と同じ作り。`npm run types:check` / `npm run check`
- [ ] **Step 5: コミット** — `feat: 投稿ページのカテゴリ表示とカテゴリ別の投稿一覧を追加する`

### Task 5: 検索の投稿カテゴリ絞り込み

**Files:**

- Modify: `app/Http/Controllers/Public/SearchController.php`
- Modify: `resources/js/pages/public/search.tsx`
- Test: `tests/Feature/Public/SearchTest.php`（追記）

**Interfaces:**

- Produces: props `postCategory: string | null`（有効な slug が指定されキーワードがあるときだけ）、`postCategories: CategorySummary[]`（キーワードがあるときだけ。無ければ `[]`）。`posts($request, $presenter, $q, ?PostCategory $postCategory)`。`post_category` が文字列でない・見つからない・アーカイブ済みなら `null`

- [ ] **Step 1: 失敗するテストを書く**: `narrows posts by post category`（同じキーワードの無料投稿2件、片方だけカテゴリ → `has('posts', 1)`、creators・products は影響なし、`where('postCategory', slug)`）、`never lists a paid post even when it matches the category`、`ignores an unknown or archived post category`（dataset: 未知・アーカイブ済み・配列）、`combines the product and post category filters`（`category` と `post_category` を両方指定し、商品と投稿がそれぞれ絞られる）
- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=SearchTest`
- [ ] **Step 3: 実装してテストを通す**
- [ ] **Step 4: 画面**: 投稿欄を出す条件は「投稿がある、または `postCategory` が指定されている」（キーワードあり・`type` が `all`/`posts`）。見出しの下に `CategoryLinks`（`allLabel="すべて"`、`postCategories` が空なら出さない、リンクは `q`・`type`・`category` を保って `post_category` だけ差し替え）。絞り込み中で0件なら「このカテゴリの投稿は見つかりませんでした。」。全体の「見つかりませんでした。」は、商品・投稿どちらの絞り込みも無いときだけ。`npm run types:check` / `npm run check`
- [ ] **Step 5: コミット** — `feat: 検索結果の投稿をカテゴリで絞り込めるようにする`

### Task 6: クリエイターページの投稿カテゴリ絞り込み

**Files:**

- Modify: `app/Http/Controllers/Public/CreatorPageController.php`
- Modify: `resources/js/pages/public/creator.tsx`
- Test: `tests/Feature/Public/CreatorPagePostCategoryTest.php`

**Interfaces:**

- Produces: props `postCategories: CategorySummary[]`（そのクリエイターの `published()->visible()` な投稿に付いている有効なカテゴリ、並び順どおり。`PostCategory::active()->ordered()->whereHas('posts', fn ($q) => $q->where('creator_id', ...)->published()->visible())` の形）、`postCategory: string | null`（`postCategories` の中にある slug のときだけ）。投稿一覧は `postCategory` があれば `inCategory`。`paginate(20)->withQueryString()`

- [ ] **Step 1: 失敗するテストを書く**: `filters paid posts too and keeps them locked`（有料投稿を絞り込みで表示し、閲覧権の無い閲覧者には既存の `PostPresenter` のロック表示のまま。既存 `CreatorPageTest` のロック判定と同じ props を確かめる）、`offers only categories used by published posts`（下書き・予約投稿にしか使われていないカテゴリ、アーカイブ済み、他のクリエイターだけが使うカテゴリは出ない）、`ignores an unknown post category`、`keeps the post category in pagination links`（21件 → `posts.next_page_url` に `post_category=` が含まれる）、`leaves the shop tab alone`
- [ ] **Step 2: 失敗を確認** — `php artisan test --filter=CreatorPagePostCategoryTest`
- [ ] **Step 3: 実装してテストと既存の `CreatorPageTest` を通す**
- [ ] **Step 4: 画面**: 投稿タブの一覧の上に `CategoryLinks`（`allLabel="すべて"`、`postCategories` が空なら出さない、リンクはクリエイターページの URL に `post_category` を付けたもの。`tab` は付けない）。`npm run types:check` / `npm run check`
- [ ] **Step 5: コミット** — `feat: クリエイターページの投稿をカテゴリで絞り込めるようにする`

### Task 7: ホームのリンクとデモデータ

**Files:**

- Modify: `app/Http/Controllers/Public/HomeController.php`（props `postCategories`）
- Modify: `resources/js/pages/public/home.tsx`（「人気の全体公開投稿」の見出しの下・投稿一覧の上に `CategoryLinks`、リンク先 `post-categories.show`、空なら出さない）
- Create: `database/seeders/DemoPostCategoriesSeeder.php`
- Modify: `database/seeders/DemoCreatorsSeeder.php`（`DemoCategoriesSeeder` の後に呼ぶ）
- Modify: `docs/operations/laravel-cloud.md` §6.4（`php artisan db:seed --class=DemoPostCategoriesSeeder --force` の手順）
- Test: `tests/Feature/Public/HomePostCategoriesTest.php`、`tests/Feature/DemoPostCategoriesSeederTest.php`

**Interfaces:**

- `DemoPostCategoriesSeeder` は `DemoCategoriesSeeder` と同じ方式（slug→名前の順で既存を探してそのまま使う、無ければ `nextSortOrder()` で作る。割り当ては `DemoProductsSeeder::creatorSlugs()` のクリエイターの、カテゴリ未設定の投稿だけ。ファクトリを使わない）
- 定義と割り当て（タイトルに含まれる文字列。上から順に最初に当たったもの）:

| slug           | name           | タイトルに含む                                                                   |
| -------------- | -------------- | -------------------------------------------------------------------------------- |
| `making`       | メイキング     | `メイキング`, `線画から仕上げまで`, `制作日記`, `開発日誌`, `動画で解説`         |
| `manga`        | 漫画           | `話 `（例: `第1話 はじめまして`）                                                |
| `illustration` | イラスト       | `ラフ`, `カット`, `壁紙`, `冬の灯り`, `夕暮れ`, `設定資料`                       |
| `diary`        | 日記・お知らせ | `ご挨拶`, `ごあいさつ`, `お知らせ`, `始めました`, `更新しました`, `公開しました` |

- [ ] **Step 1: 失敗するテストを書く**: `passes the active post categories to the home page`（アーカイブ済みを含まない、並び順）。シーダー: `demo-creator` の投稿（`メイキング`、`第1話 はじめまして`、`今月のラフ`、`ご挨拶`、当たらないタイトル）に正しく付く／非デモのクリエイターの同名投稿は null／2回流しても4件・重複なし／運営の名前変更・並び替えを上書きしない／同じ名前で別 slug のカテゴリがあっても止まらない／手で付けたカテゴリは上書きしない
- [ ] **Step 2: 失敗を確認**
- [ ] **Step 3: 実装してテストを通す**
- [ ] **Step 4: ホームの画面と運用ドキュメントを更新。`npm run types:check` / `npm run check`**
- [ ] **Step 5: コミット** — `feat: ホームに投稿カテゴリを表示しデモの投稿カテゴリを追加する`

### Task 8: 全体の確認

- [ ] **Step 1:** 全チェック（Global Constraints）が通り、`php artisan test` の失敗が main と同じものだけ
- [ ] **Step 2:** 画面の確認（手元のサーバー）: 運営の切り替えと投稿カテゴリ管理 → 投稿編集の選択欄 → 投稿ページのバッジ → カテゴリ別一覧 → 検索の絞り込み → ホームのリンク → クリエイターページの絞り込み
- [ ] **Step 3:** 設計書のステータスを更新してコミット・プッシュ
