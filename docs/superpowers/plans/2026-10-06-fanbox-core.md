# コア（サブプロジェクト1）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** クリエイター登録・プラン・投稿・金額しきい値による閲覧制御・非公開ファイル配信・ダミー支援を備えた Laravel アプリを作る。

**Architecture:** Laravel 13 + Inertia 3 + React(TS) の公式スターターキット（Fortify / Wayfinder）をリポジトリ直下に展開する。権限判定は `App\Services\Entitlement` 1か所、投稿の props 生成は `App\Presenters\PostPresenter` 1か所に集約し、権限なしでは本文・ファイルURLを生成しない。ファイルは S3 互換（開発は MinIO）の非公開ディスクに置き、5分の署名付きURLで配信する。

**Tech Stack:** PHP 8.4（Sail）, Laravel 13.x, Inertia 3, React 19, TypeScript, Tailwind 4, Wayfinder, Pest 4, MySQL 8, Redis, MinIO, intervention/image v3（GD）, Tiptap v3

**Spec:** `docs/superpowers/specs/2026-10-06-fanbox-core-design.md`

## Global Constraints

- ホストに PHP は無い。PHP/Composer/Artisan/テストはすべて Sail 経由（`./vendor/bin/sail ...`）。Sail 起動前の初回のみ `docker run --rm -v "$PWD":/app -w /app composer:latest ...` を使う。
- UI 文言は日本語。`APP_LOCALE=ja`、`APP_TIMEZONE` 相当は `Asia/Tokyo`（`config/app.php` の `timezone`）。
- 通貨は JPY、金額は unsigned int（円）。
- 上限値はすべて `config/fanbox.php` に置く（画像 10MB / jpg,png,gif,webp、添付 100MB、添付 20 件/投稿、画像 100 枚/投稿、署名URL 5分、プラン 100〜50000 円、プラン 10 件/クリエイター、表示用長辺 2560px、縮小版長辺 640px、本文 JSON 512KB）。
- 予約語 slug: `admin, api, creator, creators, login, logout, register, feed, supporting, settings, dashboard, posts, plans, storage, assets, help, terms, privacy`（`config/fanbox.php` の `reserved_slugs`）。
- slug 正規表現: `^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$`
- 権限なしレスポンスには body / カバー縮小版以外のファイルURL / post_files パスを含めない。
- フロントは `dangerouslySetInnerHTML` を使わない。
- ルート参照はフロントでは Wayfinder（`@/routes/...` / `@/actions/...`）、PHP では `route()` 名を使う。
- 既存スターターキットのコードスタイル（Pint, `vp check`）に従う。

## Review Focus

1. **支援の二重作成（連打・並行リクエスト）** → active が2件にならない。Task 10 で `lockForUpdate` + テスト。
2. **他クリエイターの投稿に対するファイル操作・他投稿の fileId を本文に埋め込む** → 404 / 422。Task 7・8 でテスト。
3. **大文字や先頭ハイフン・予約語の slug、`/@Slug` の大文字アクセス** → 登録時 422、表示は小文字 slug のみ一致（大文字 URL は 404）。Task 4 でテスト。
4. **プランを全削除したあと既存投稿の required_price が存在しない金額を指す** → 閲覧判定は金額比較なので影響なし、編集画面では現在値を選択肢に残す。Task 8 でテスト。
5. **支援終了後にブラウザが保持していた署名URL** → 5分で失効（期限を `temporaryUrl` に渡していることをテスト）。Task 6 でテスト。

---

## File Structure

```
app/
  Enums/{SupportStatus,PostStatus,PostFileKind}.php
  Models/{Creator,Plan,Support,Post,PostFile}.php (+ User に関係追加)
  Services/
    Entitlement.php               # 閲覧権限の唯一の判定
    SupportService.php            # 支援の開始・終了（2で Stripe に差替え）
    PostFileStorer.php            # アップロード保存・EXIF除去・縮小
    PostFileUrls.php              # 署名付きURL発行
    Tiptap/TiptapDocumentValidator.php
  Presenters/PostPresenter.php    # 投稿 props 生成（権限で分岐）
  Policies/{PostPolicy,PlanPolicy}.php
  Rules/CreatorSlug.php
  Http/Middleware/EnsureUserIsCreator.php
  Http/Controllers/
    Creator/{CreatorRegistrationController,ProfileController,PlanController,PostController,PostFileController,SupporterController}.php
    Public/{HomeController,CreatorPageController,PostPageController}.php
    Fan/{SupportController,FeedController,SupportingController}.php
  Console/Commands/PruneOrphanFiles.php
config/fanbox.php
routes/{web.php,creator.php,fan.php}
resources/js/
  layouts/public-layout.tsx
  components/tiptap/{post-editor.tsx,post-renderer.tsx}
  components/{post-card.tsx,plan-card.tsx,locked-post.tsx}
  pages/public/{home,creator,post}.tsx
  pages/creator/{register,profile,supporters}.tsx
  pages/creator/plans/index.tsx
  pages/creator/posts/{index,edit}.tsx
  pages/fan/{feed,supporting}.tsx
  types/fanbox.ts
tests/Feature/{Creator,Public,Fan,Entitlement}/..., tests/Unit/...
```

---

### Task 1: プロジェクト基盤（スターターキット・Sail・Pest・ストレージ・設定）

**Files:**
- Create: リポジトリ直下にスターターキット一式、`config/fanbox.php`、`docker-compose.yml`（sail:install）、`tests/Pest.php`
- Modify: `.env.example`, `.env`, `config/filesystems.php`, `config/app.php`, `phpunit.xml`, `.gitignore`
- Test: `tests/Feature/InfrastructureTest.php`

**Interfaces:**
- Produces: ディスク `private`（s3, 非公開バケット `fanbox-private`）、`public`（s3, 公開バケット `fanbox-public`, `visibility=public`）、`config('fanbox.*')` キー: `image_max_kb=10240, image_mimes=['jpg','jpeg','png','gif','webp'], attachment_max_kb=102400, attachments_per_post=20, images_per_post=100, signed_url_minutes=5, plan_min_price=100, plan_max_price=50000, plans_per_creator=10, image_max_edge=2560, thumb_max_edge=640, body_max_bytes=524288, reserved_slugs=[...]`

- [ ] **Step 1:** `composer create-project laravel/react-starter-kit:dev-main` を一時ディレクトリに作り（composer:latest コンテナ、`--no-scripts --ignore-platform-reqs`）、`vendor` と `node_modules` を除いてリポジトリ直下へコピー。`.env.example` → `.env`。
- [ ] **Step 2:** `docker run ... composer:latest php artisan sail:install --with=mysql,redis,minio,mailpit --php=8.4`。`./vendor/bin/sail up -d`、`sail composer install`、`sail artisan key:generate`、`sail npm install`。
- [ ] **Step 3:** Pest 導入: `sail composer require pestphp/pest pestphp/pest-plugin-laravel --dev -W`、`sail pest --init`。既存 PHPUnit クラス形式テストはそのまま残す。`tests/Pest.php` で Feature/Unit に `TestCase` と `RefreshDatabase` を適用。
- [ ] **Step 4:** `sail composer require laravel-lang/common --dev` → `sail artisan lang:add ja` で `lang/ja` を生成。`.env`/`.env.example`: `APP_LOCALE=ja`, `APP_FALLBACK_LOCALE=en`, `APP_NAME=Fanbox`（仮称）, `DB_*` は Sail mysql, `FILESYSTEM_DISK=private`, `AWS_*` は MinIO（`AWS_ENDPOINT=http://minio:9000`, `AWS_URL=http://localhost:9000`, `AWS_USE_PATH_STYLE_ENDPOINT=true`）, `AWS_PRIVATE_BUCKET=fanbox-private`, `AWS_PUBLIC_BUCKET=fanbox-public`。`config/app.php` timezone を `Asia/Tokyo`。
- [ ] **Step 5:** `sail composer require league/flysystem-aws-s3-v3 intervention/image`。`config/filesystems.php` に `private` / `public`（s3）ディスク追加（既存 `public` local は置き換え）。MinIO のバケット作成と公開ポリシー設定を `docker/minio-init.sh`（`mc mb` / `mc anonymous set download`）に書き、README に手順を記載。署名URLはブラウザから届く必要があるため、`private` ディスクは `temporary_url` 用に `AWS_URL` 系のホストを使う設定（`'temporary_url' => env('AWS_URL')`）。
- [ ] **Step 6: 失敗するテストを書く** — `tests/Feature/InfrastructureTest.php`

```php
it('configures private and public disks on s3', function () {
    expect(config('filesystems.disks.private.driver'))->toBe('s3')
        ->and(config('filesystems.disks.public.driver'))->toBe('s3')
        ->and(config('filesystems.disks.public.visibility'))->toBe('public');
});

it('exposes fanbox limits', function () {
    expect(config('fanbox.image_max_kb'))->toBe(10240)
        ->and(config('fanbox.signed_url_minutes'))->toBe(5)
        ->and(config('fanbox.reserved_slugs'))->toContain('admin', 'feed', 'creator');
});

it('uses japanese locale and tokyo timezone', function () {
    expect(app()->getLocale())->toBe('ja')->and(config('app.timezone'))->toBe('Asia/Tokyo');
});
```

- [ ] **Step 7:** `sail pest tests/Feature/InfrastructureTest.php` → FAIL（fanbox config 無し）。
- [ ] **Step 8:** `config/fanbox.php` を Global Constraints の値で作成。`phpunit.xml` に `APP_LOCALE=ja` を追加し、テスト DB を本番と同じ MySQL にする（`DB_CONNECTION=mysql`, `DB_DATABASE=testing` — Sail が `testing` DB を自動作成。照合順序・ロック・enum の差異をテストで拾うため）。
- [ ] **Step 9:** `sail pest` 全体 PASS、`sail npm run build` 成功、`sail npm run types:check` 成功。
- [ ] **Step 10:** `.gitignore` 確認（vendor, node_modules, .env）→ コミット `chore: スターターキット・Sail・Pest・ストレージ基盤を導入`

---

### Task 2: ドメインモデルとマイグレーション

**Files:**
- Create: `app/Enums/{SupportStatus,PostStatus,PostFileKind}.php`, `app/Models/{Creator,Plan,Support,Post,PostFile}.php`, マイグレーション5本、`database/factories/{Creator,Plan,Support,Post,PostFile}Factory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Models/DomainModelTest.php`

**Interfaces:**
- Produces:
  - `enum SupportStatus: string { Active='active'; Ended='ended'; }`、`enum PostStatus: string { Draft='draft'; Published='published'; }`、`enum PostFileKind: string { Image='image'; Attachment='attachment'; }`
  - `User::creator(): HasOne`、`User::supports(): HasMany`
  - `Creator::user()`, `plans()`（sort, price 順）, `posts()`, `supports()`, `getRouteKeyName(): 'slug'`, `resolveRouteBinding($value, $field)` は取得後 `$creator->slug !== $value` なら null（MySQL の大文字小文字を区別しない照合順序でも `/@Alice` を 404 にする）
  - `Plan::creator()`, `supports()`, `activeSupports()`
  - `Support::user()`, `creator()`, `plan()`、scope `active()`、casts status→SupportStatus, started_at/ended_at→datetime
  - `Post::creator()`, `files()`, `images()`, `attachments()`, `cover(): BelongsTo(PostFile, 'cover_file_id')`、scope `published()`（status=published かつ published_at not null、published_at desc）、`isPublished(): bool`、casts body→array
  - `PostFile::post()`、casts kind→PostFileKind
  - Factories: `Creator::factory()`（user 付き、slug は `fake()->unique()->regexify('[a-z][a-z0-9]{5,10}')`）、`Plan::factory()->for($creator)->price(500)`、`Post::factory()->published()->requiring(500)`、`Post::factory()->draft()`、`Support::factory()->active()->for($user)->forPlan($plan)`（creator_id・price を plan から設定）、`Support::factory()->ended()`
- 制約: creators.user_id unique, creators.slug unique, plans (creator_id, price) unique, supports.plan_id nullable nullOnDelete, posts.cover_file_id nullable nullOnDelete（post_files 作成後の別マイグレーションで FK 追加）, post_files.post_id cascadeOnDelete, index posts(creator_id, status, published_at), index supports(user_id, creator_id, status)

- [ ] **Step 1: 失敗するテストを書く**

```php
it('enforces one creator per user', function () {
    $creator = Creator::factory()->create();
    expect(fn () => Creator::factory()->for($creator->user)->create())->toThrow(QueryException::class);
});

it('enforces unique plan price per creator', function () {
    $creator = Creator::factory()->create();
    Plan::factory()->for($creator)->price(500)->create();
    expect(fn () => Plan::factory()->for($creator)->price(500)->create())->toThrow(QueryException::class);
});

it('nulls support plan_id when plan is deleted', function () {
    $support = Support::factory()->ended()->create();
    $support->plan->delete();
    expect($support->fresh()->plan_id)->toBeNull()->and($support->fresh()->price)->toBeGreaterThan(0);
});

it('scopes published posts newest first', function () {
    $creator = Creator::factory()->create();
    $old = Post::factory()->for($creator)->published()->create(['published_at' => now()->subDay()]);
    $new = Post::factory()->for($creator)->published()->create(['published_at' => now()]);
    Post::factory()->for($creator)->draft()->create();
    expect(Post::published()->pluck('id')->all())->toBe([$new->id, $old->id]);
});

it('copies plan price and creator into support factory', function () {
    $plan = Plan::factory()->price(1000)->create();
    $support = Support::factory()->active()->forPlan($plan)->create();
    expect($support->price)->toBe(1000)->and($support->creator_id)->toBe($plan->creator_id)
        ->and($support->status)->toBe(SupportStatus::Active);
});

it('deletes files when post is deleted', function () {
    $file = PostFile::factory()->create();
    $file->post->delete();
    expect(PostFile::count())->toBe(0);
});
```

- [ ] **Step 2:** `sail pest tests/Feature/Models` → FAIL
- [ ] **Step 3:** Enum・マイグレーション・モデル・ファクトリを Interfaces 通りに実装（カラムは spec §5）。
- [ ] **Step 4:** `sail pest tests/Feature/Models` → PASS
- [ ] **Step 5:** コミット `feat: クリエイター・プラン・支援・投稿のモデルを追加`

---

### Task 3: Entitlement と PostPolicy（権限マトリクス）

**Files:**
- Create: `app/Services/Entitlement.php`, `app/Policies/PostPolicy.php`
- Test: `tests/Feature/Entitlement/EntitlementMatrixTest.php`

**Interfaces:**
- Consumes: Task 2 のモデル
- Produces:
  - `Entitlement::for(?User $viewer): Entitlement` — 生成時に viewer の active 支援を **1クエリ** で `array<int creatorId, int price>` に読み込む。viewer の creator id も保持。
  - `Entitlement::canView(Post $post): bool` — spec §6 の擬似コード通り（投稿者本人 → true / 非公開 → false / 0円 → true / 未ログイン → false / support.price >= required_price）
  - `Entitlement::supportPriceFor(int $creatorId): ?int`
  - `PostPolicy::view(?User $user, Post $post): bool` → `Entitlement::for($user)->canView($post)`。`PostPolicy::manage(User $user, Post $post): bool` → 投稿者本人のみ。
  - Entitlement はリクエスト内で使い回せるよう `app()->scoped()` ではなく呼び出し側が保持する（Presenter に渡す）。

- [ ] **Step 1: 失敗するテストを書く** — dataset で 8 閲覧者 × 3 投稿 = 24 ケース

```php
dataset('viewers', [
    'author'          => ['author',   [true,  true,  true ]],
    'guest'           => ['guest',    [true,  false, false]],
    'no support'      => ['fan',      [true,  false, false]],
    'lower support'   => ['low',      [true,  false, false]],
    'equal support'   => ['equal',    [true,  true,  false]],
    'higher support'  => ['high',     [true,  true,  false]],
    'ended support'   => ['ended',    [true,  false, false]],
    'other creator'   => ['other',    [true,  false, false]],
]); // 期待値の並び: [0円公開, 500円公開, 下書き(500円)]

it('decides visibility', function (string $who, array $expected) {
    // 投稿者 creator に 300/500/1000 円プラン、投稿 [free(0), paid(500), draft(500)]
    // low=300円支援, equal=500, high=1000, ended=1000(ended), other=別クリエイター1000
    $viewer = makeViewer($who, $creator); // テスト内ヘルパ
    $ent = Entitlement::for($viewer);
    expect([$ent->canView($free), $ent->canView($paid), $ent->canView($draft)])->toBe($expected);
})->with('viewers');

it('uses the support price snapshot, not current plan price', function () {
    // support.price=500 で作成後、plan を DB 直接 update で price=100 にしても 500円投稿が見える
});

it('loads supports with a single query', function () {
    // 3 クリエイターに支援 → DB::enableQueryLog(); Entitlement::for($user); 10 投稿 canView → クエリ 1 件
});

it('authorizes via policy', function () {
    expect(Gate::forUser(null)->allows('view', $paid))->toBeFalse();
});
```

- [ ] **Step 2:** `sail pest tests/Feature/Entitlement` → FAIL
- [ ] **Step 3:** 実装（Policy は Laravel の自動検出に任せる）
- [ ] **Step 4:** PASS 確認
- [ ] **Step 5:** コミット `feat: 金額しきい値による閲覧権限判定を追加`

---

### Task 4: クリエイター登録・プロフィール・公開レイアウト

**Files:**
- Create: `app/Rules/CreatorSlug.php`, `app/Http/Middleware/EnsureUserIsCreator.php`, `app/Http/Controllers/Creator/{CreatorRegistrationController,ProfileController}.php`, `app/Http/Requests/Creator/{RegisterCreatorRequest,UpdateCreatorProfileRequest}.php`, `routes/creator.php`, `resources/js/pages/creator/{register,profile}.tsx`, `resources/js/layouts/public-layout.tsx`, `resources/js/types/fanbox.ts`
- Modify: `routes/web.php`（`require creator.php`）, `bootstrap/app.php`（middleware alias `creator`）, `resources/js/app.tsx`（`public/` → PublicLayout）, `resources/js/components/app-sidebar.tsx`（ナビ: フィード / 支援中 / クリエイター管理）, `app/Http/Middleware/HandleInertiaRequests.php`（`auth.creator` に `{slug, display_name, avatar_url}|null` を共有）
- Test: `tests/Feature/Creator/CreatorRegistrationTest.php`, `tests/Feature/Creator/CreatorProfileTest.php`

**Interfaces:**
- Produces:
  - ルート名: `creator.register`（GET/POST `/creator/register`）、`creator.profile.edit`（GET `/creator/profile`）、`creator.profile.update`（POST `/creator/profile`、multipart のため POST）
  - middleware alias `creator`: クリエイター未登録なら `creator.register` へリダイレクト。`$request->user()->creator` を前提にできる。
  - `Creator::avatarUrl(): ?string` / `headerUrl(): ?string`（`Storage::disk('public')->url()`）
  - TS 型 `CreatorSummary { slug: string; display_name: string; avatar_url: string | null }`（`types/fanbox.ts`）
  - `PublicLayout`: ヘッダー（ロゴ、未ログインならログイン/新規登録、ログイン済みならユーザーメニュー）＋中央寄せコンテナ
- 振る舞い: 登録済みユーザーが `/creator/register` に来たら `creator.profile.edit` へリダイレクト。slug は登録時に `strtolower` しない（大文字は 422）。アイコン/ヘッダーは image, max 10MB、`creators/{id}/avatar/{ulid}.{ext}` に保存し、旧ファイル削除。表示名 max 50、bio max 2000。すべて `auth`, `verified` 配下。

- [ ] **Step 1: 失敗するテストを書く**

```php
it('registers a creator', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('creator.register'), ['slug' => 'alice-art', 'display_name' => 'Alice'])
        ->assertRedirect(route('creator.profile.edit'));
    expect($user->fresh()->creator->slug)->toBe('alice-art');
});

it('rejects invalid slugs', function (string $slug) {
    $this->actingAs(User::factory()->create())
        ->post(route('creator.register'), ['slug' => $slug, 'display_name' => 'A'])
        ->assertSessionHasErrors('slug');
})->with(['Alice', '-alice', 'alice-', 'a', 'admin', 'feed', 'al ice', str_repeat('a', 33)]);

it('rejects duplicate slug', ...);
it('redirects non-creators from creator area', fn () => /* GET creator.profile.edit → redirect creator.register */);
it('redirects already-registered user away from register', ...);
it('updates profile and replaces avatar', function () {
    Storage::fake('public');
    // 1回目 avatar アップロード → 2回目で旧ファイルが消え新ファイルが存在
});
it('does not allow changing slug', fn () => /* update に slug を送っても変わらない */);
```

- [ ] **Step 2:** FAIL 確認
- [ ] **Step 3:** 実装。ページは `assertInertia(fn ($p) => $p->component('creator/register'))` が通るよう作成。UI 文言は日本語。
- [ ] **Step 4:** `sail pest tests/Feature/Creator` PASS、`sail npm run types:check` PASS
- [ ] **Step 5:** コミット `feat: クリエイター登録とプロフィール編集を追加`

---

### Task 5: プラン管理

**Files:**
- Create: `app/Http/Controllers/Creator/PlanController.php`, `app/Http/Requests/Creator/{StorePlanRequest,UpdatePlanRequest}.php`, `app/Policies/PlanPolicy.php`, `resources/js/pages/creator/plans/index.tsx`, `resources/js/components/plan-card.tsx`
- Modify: `routes/creator.php`
- Test: `tests/Feature/Creator/PlanManagementTest.php`

**Interfaces:**
- Produces:
  - ルート: `creator.plans.index`（GET）、`creator.plans.store`（POST）、`creator.plans.update`（POST `/creator/plans/{plan}`, multipart）、`creator.plans.destroy`（DELETE）
  - `PlanPolicy::manage(User, Plan)` 他人のプランは 404（コントローラで `abort_unless(..., 404)`）
  - TS 型 `PlanData { id; name; description: string|null; price: number; cover_url: string|null; is_accepting: boolean; active_supporters_count: number }`
  - `plan-card.tsx` は Task 9 の公開ページでも使う（props: `plan: PlanData`, `action?: ReactNode`）
- 振る舞い: price は 100〜50000 の整数、(creator, price) 重複 422、11 件目 422。update で price は受け付けない（送られても無視）。`is_accepting` 切替可。active 支援があるプランの削除は 422（エラーメッセージ「支援者がいるプランは削除できません。受付を停止してください。」）。

- [ ] **Step 1: 失敗するテスト**

```php
it('creates a plan', ...);                       // price 500 で作成、一覧に表示
it('validates price range', fn ...)->with([99, 50001, 'abc', 500.5]);
it('rejects duplicate price', ...);
it('limits plans to 10', ...);
it('ignores price on update', ...);              // update に price=999 → 変わらない、name は変わる
it('stops accepting', ...);
it('refuses to delete plan with active supporters', ...);
it('deletes plan with only ended supports', ...); // support.plan_id null に
it('returns 404 for another creators plan', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** 実装 → **Step 4:** PASS（`sail pest tests/Feature/Creator/PlanManagementTest.php`）
- [ ] **Step 5:** コミット `feat: 支援プラン管理を追加`

---

### Task 6: ファイル保存と署名付きURL

**Files:**
- Create: `app/Services/PostFileStorer.php`, `app/Services/PostFileUrls.php`, `tests/Fixtures/`（テスト内で生成するため空でも可）
- Test: `tests/Feature/Files/PostFileStorerTest.php`, `tests/Feature/Files/PostFileUrlsTest.php`

**Interfaces:**
- Produces:
  - `PostFileStorer::store(Post $post, UploadedFile $file, PostFileKind $kind): PostFile`
    - パス `creators/{creator_id}/posts/{post_id}/{ulid}.{ext}`（縮小版は `{ulid}_thumb.{ext}`）、ディスク `private`
    - 画像（gif 以外）: Intervention（GD）で読み込み→ `scaleDown(2560)` → 同形式で再エンコード（EXIF は再エンコードで消える）。縮小版 `scaleDown(640)`。width/height を保存。
    - gif: 原本をそのまま保存、縮小版は先頭フレームを 640 に縮小し保存。
    - 添付: そのまま保存、thumb なし。
    - 保存は DB トランザクション内。ストレージ書き込み失敗時は例外を投げて DB をロールバックし、書き込み済みファイルを削除。
  - `PostFileStorer::delete(PostFile $file): void` — DB 削除後に path / thumb_path を削除（失敗はログのみ）
  - `PostFileUrls::url(PostFile $file): string` / `thumbUrl(PostFile $file): string`（thumb 無しなら url） — `Storage::disk('private')->temporaryUrl($path, now()->addMinutes(config('fanbox.signed_url_minutes')), $options)`。添付は `$options = ['ResponseContentDisposition' => 'attachment; filename*=UTF-8\'\''.rawurlencode($original_name)]`。
- テスト補助: `tests/Pest.php` に `fakePrivateDisk(): void` — `Storage::fake('private')` の後 `Storage::disk('private')->buildTemporaryUrlsUsing(fn ($path, $exp, $opt) => "https://signed.test/{$path}?expires={$exp->timestamp}".(isset($opt['ResponseContentDisposition']) ? '&cd=1' : ''))`。

- [ ] **Step 1: 失敗するテスト**

```php
it('strips exif from jpeg', function () {
    fakePrivateDisk();
    $upload = jpegWithExif(); // tests/Pest.php: GD で 3000x2000 JPEG を作り、SOI 直後に "Exif\0\0" を含む APP1 セグメントを挿入した UploadedFile
    $file = app(PostFileStorer::class)->store(Post::factory()->create(), $upload, PostFileKind::Image);
    $bytes = Storage::disk('private')->get($file->path);
    expect($bytes)->not->toContain('Exif')
        ->and($file->width)->toBe(2560)->and($file->height)->toBe(1707)
        ->and(Storage::disk('private')->exists($file->thumb_path))->toBeTrue()
        ->and($file->path)->toStartWith("creators/{$file->post->creator_id}/posts/{$file->post_id}/");
});
it('stores gif original untouched with a thumbnail', ...);
it('stores attachments without thumbnail', ...);
it('rolls back db row when storage write fails', function () {
    // Storage::shouldReceive / disk を put が false を返すモックに差し替え → 例外 & PostFile::count() === 0
});
it('issues a 5 minute signed url', function () {
    fakePrivateDisk(); $this->travelTo(now());
    expect(app(PostFileUrls::class)->url($file))->toContain('expires='.now()->addMinutes(5)->timestamp);
});
it('adds attachment disposition for attachments', ...); // '&cd=1'
it('deletes stored files', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** 実装 → **Step 4:** PASS
- [ ] **Step 5:** コミット `feat: 投稿ファイルの保存（EXIF除去・縮小）と署名付きURLを追加`

---

### Task 7: Tiptap 本文バリデータ

**Files:**
- Create: `app/Services/Tiptap/TiptapDocumentValidator.php`
- Test: `tests/Unit/TiptapDocumentValidatorTest.php`

**Interfaces:**
- Produces:
  - `TiptapDocumentValidator::validate(array $doc, array $allowedImageIds): void` — 違反時 `ValidationException::withMessages(['body' => '...'])`
    - 許可ノード: doc, paragraph, heading(attrs.level ∈ {2,3}), text, bulletList, orderedList, listItem, blockquote, horizontalRule, hardBreak, image(attrs = {fileId:int} のみ)
    - 許可マーク: bold, italic, strike, link(attrs.href は `http://` or `https://` 始まり。attrs キーは href, target, rel のみ)
    - ノードの許可キー: type, content, text, marks, attrs。未知キー・未知 attrs は拒否
    - `json_encode($doc)` が `config('fanbox.body_max_bytes')` 超で拒否
    - 深さ 20 超で拒否
  - `TiptapDocumentValidator::imageIds(array $doc): array<int>` — 本文中の image fileId 一覧（重複除去）

- [ ] **Step 1: 失敗するテスト**（有効文書は PASS、各違反で例外）

```php
it('accepts a valid document', fn () => expect(fn () => validator()->validate(validDoc(fileId: 5), [5]))->not->toThrow(ValidationException::class));
it('rejects', function (array $doc) {
    expect(fn () => validator()->validate($doc, [5]))->toThrow(ValidationException::class);
})->with([
    'unknown node'       => [doc(['type' => 'iframe'])],
    'script mark'        => [doc(text('x', [['type' => 'code']]))],
    'javascript link'    => [doc(text('x', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]))],
    'h1'                 => [doc(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [text('x')]])],
    'image src attr'     => [doc(['type' => 'image', 'attrs' => ['fileId' => 5, 'src' => 'https://evil']])],
    'foreign image'      => [doc(['type' => 'image', 'attrs' => ['fileId' => 99]])],
    'unknown key'        => [doc(['type' => 'paragraph', 'onclick' => 'x'])],
    'too deep'           => [nested(21)],
]);
it('rejects oversized body', ...);
it('extracts image ids', fn () => expect(validator()->imageIds(docWithImages([3, 5, 3])))->toBe([3, 5]));
```

- [ ] **Step 2:** FAIL → **Step 3:** 実装（再帰で検査）→ **Step 4:** PASS
- [ ] **Step 5:** コミット `feat: Tiptap本文の許可リスト検証を追加`

---

### Task 8: 投稿管理（クリエイター）とエディタ

**Files:**
- Create: `app/Http/Controllers/Creator/{PostController,PostFileController}.php`, `app/Http/Requests/Creator/{UpdatePostRequest,StorePostFileRequest}.php`, `resources/js/pages/creator/posts/{index,edit}.tsx`, `resources/js/components/tiptap/post-editor.tsx`
- Modify: `routes/creator.php`, `package.json`（`@tiptap/react @tiptap/pm @tiptap/starter-kit @tiptap/extension-image`）
- Test: `tests/Feature/Creator/PostManagementTest.php`, `tests/Feature/Creator/PostFileUploadTest.php`

**Interfaces:**
- Consumes: `PostFileStorer`, `PostFileUrls`, `TiptapDocumentValidator`, `PostPolicy::manage`
- Produces:
  - ルート: `creator.posts.index`, `creator.posts.store`（POST → 空の下書き作成 → `creator.posts.edit` へ）, `creator.posts.edit`, `creator.posts.update`（PUT, `action` ∈ {save, publish, unpublish}）, `creator.posts.destroy`, `creator.posts.files.store`（POST, `throttle:60,1`, JSON 応答 `{id, kind, url, thumb_url, original_name, size}`）, `creator.posts.files.destroy`
  - 他人の投稿は全ルート 404。
  - update 入力: `title` (required on publish, max 100; 下書き保存時は nullable で空なら「無題」), `excerpt` (nullable max 300), `body` (nullable array), `required_price` (int; 0 または自分のプラン金額、または**既存値と同じ値**), `cover_file_id` (nullable, この投稿の image)
  - publish: status=published、`published_at` は null のときのみ now()。unpublish: status=draft（published_at は保持）。
  - 保存後、本文 `imageIds` と cover 以外の image を `PostFileStorer::delete`。
  - destroy: 全ファイルを `PostFileStorer::delete` → post 削除。
  - ファイル上限: 画像 `mimes:jpg,jpeg,png,gif,webp|max:10240`、添付 `max:102400`、件数上限超過で 422。
  - エディタ画面 props: `post`（本文含む・全ファイルの URL 解決済み）、`priceOptions: number[]`（0 + プラン金額 + 現在値, 昇順・重複除去）
  - `post-editor.tsx`: Tiptap（StarterKit: heading levels [2,3], code/codeBlock 無効; Image 拡張は `fileId` 属性を持ち、src は表示時に `urls[fileId]` から解決）。画像ボタン/ドロップ → `files.store` に fetch（XSRF ヘッダ）→ 挿入。添付リスト・カバー選択・必要金額セレクト・「下書き保存」「公開」「公開取り消し」。
- [ ] **Step 1: 失敗するテスト**

```php
it('creates an empty draft and redirects to editor', ...);
it('publishes with timestamp once', ...);      // publish → unpublish → publish で published_at 不変
it('requires title to publish', ...);
it('validates required_price against own plans', function () {
    // プラン 500 のみ → 300 は 422, 0 と 500 は OK
});
it('keeps an orphaned existing required_price selectable', ...); // プラン削除後も既存 required_price=500 で保存可
it('rejects body referencing another posts image', ...);   // 422
it('prunes images no longer referenced', ...);              // cover は残る
it('deletes post with files', ...);
it('returns 404 for another creators post', fn ...)->with(['edit', 'update', 'destroy', 'files.store']);
it('uploads an image and returns signed urls', ...);
it('rejects disallowed mime and oversize', ...)->with([...]);
it('limits attachments per post', ...);         // 20 件後 422
it('rejects deleting a file of another post', ...); // 404
```

- [ ] **Step 2:** FAIL → **Step 3:** バックエンド実装 → **Step 4:** PASS
- [ ] **Step 5:** フロント（一覧・エディタ）実装、`sail npm run types:check` と `sail npm run check` PASS
- [ ] **Step 6:** コミット `feat: 投稿の作成・編集・公開とファイルアップロードを追加`

---

### Task 9: PostPresenter と公開ページ

**Files:**
- Create: `app/Presenters/PostPresenter.php`, `app/Http/Controllers/Public/{HomeController,CreatorPageController,PostPageController}.php`, `resources/js/pages/public/{home,creator,post}.tsx`, `resources/js/components/{post-card,locked-post}.tsx`, `resources/js/components/tiptap/post-renderer.tsx`
- Modify: `routes/web.php`（`/` を HomeController に、`/@{creator:slug}`、`/@{creator:slug}/posts/{post}`）, `resources/js/types/fanbox.ts`
- Test: `tests/Feature/Public/CreatorPageTest.php`, `tests/Feature/Public/PostPageTest.php`, `tests/Feature/Public/LeakageTest.php`

**Interfaces:**
- Consumes: `Entitlement`, `PostFileUrls`, `TiptapDocumentValidator::imageIds`
- Produces:
  - `PostPresenter::summary(Post $post, Entitlement $ent): array` → `{id, title, excerpt, required_price, published_at, cover_thumb_url|null, locked: bool, creator: CreatorSummary}`
  - `PostPresenter::detail(Post $post, Entitlement $ent): array` → summary + 権限ありなら `{body, image_urls: Record<fileId, url>, attachments: [{id, original_name, size, url}]}`、権限なしなら summary のみ（キー自体を含めない）
  - 呼び出し前に `creator` と `cover` を eager load する前提（Presenter 内でクエリを発生させない）
  - ルート名: `home`, `creators.show`, `posts.show`。`posts.show` は `$post->creator_id !== $creator->id` で 404、`! $post->isPublished() && 本人以外` で 404。
  - creators.show props: `creator`（profile + header_url + bio）, `plans: PlanData[]`（受付中のみ、本人なら全件）, `posts`（`summary` の paginate 20）, `viewerSupportPrice: number|null`
  - home props: `posts`（全クリエイターの 0円公開の新着 20、summary）, `creators`（新着 12）
  - TS: `PostSummary`, `PostDetail`, `TiptapNode`
  - `post-renderer.tsx`: TiptapNode を再帰で React 要素に変換（許可ノードのみ、未知は無視）。link は `rel="noopener noreferrer nofollow" target="_blank"`。image は `image_urls[fileId]`。
  - `locked-post.tsx`: 🔒、必要金額「月額 ¥{n} 以上のプランで閲覧できます」、プラン一覧へのリンク
- [ ] **Step 1: 失敗するテスト**

```php
it('shows locked post to non-supporters without body or file urls', function () {
    fakePrivateDisk();
    // 500円投稿: body に "SECRET-BODY-TEXT"、本文画像と添付あり、カバーあり
    $res = $this->get(route('posts.show', [$creator, $post]))->assertOk();
    $json = json_encode($res->viewData('page')['props']);
    expect($json)->not->toContain('SECRET-BODY-TEXT')
        ->not->toContain($image->path)->not->toContain($attachment->path)
        ->toContain($cover->thumb_path) // カバー縮小版のみ許可
        ->not->toContain($cover->path.'?'); // 原寸カバーは含まない
    $res->assertInertia(fn ($p) => $p->component('public/post')->where('post.locked', true)->missing('post.body'));
});
it('shows full post to qualifying supporter', ...);   // body, image_urls, attachments あり
it('applies the same leakage rule on creator page list', ...);
it('404s drafts for others and shows them to the author', ...);
it('404s post under the wrong creator slug', ...);
it('404s uppercase slug', fn () => $this->get('/@'.strtoupper($creator->slug))->assertNotFound());
it('lists only accepting plans to visitors', ...);
it('renders creator page with constant query count', function () {
    // 投稿 5 件と 20 件でクエリ数が同じ
});
```

- [ ] **Step 2:** FAIL → **Step 3:** 実装 → **Step 4:** PASS
- [ ] **Step 5:** フロント実装、types:check / check PASS
- [ ] **Step 6:** コミット `feat: クリエイターページと投稿詳細（閲覧制御付き）を追加`

---

### Task 10: ダミー支援・支援中一覧・フィード・支援者一覧

**Files:**
- Create: `app/Services/SupportService.php`, `app/Http/Controllers/Fan/{SupportController,SupportingController,FeedController}.php`, `app/Http/Controllers/Creator/SupporterController.php`, `routes/fan.php`, `resources/js/pages/fan/{feed,supporting}.tsx`, `resources/js/pages/creator/supporters.tsx`
- Modify: `routes/web.php`, `routes/creator.php`, `resources/js/pages/public/creator.tsx`（プランに「支援する」/「支援中」/「このプランに変更」ボタン）
- Test: `tests/Feature/Fan/SupportTest.php`, `tests/Feature/Fan/FeedTest.php`, `tests/Feature/Creator/SupportersTest.php`

**Interfaces:**
- Produces:
  - `SupportService::start(User $user, Plan $plan): Support` — トランザクション内で `Support::where(user, creator)->active()->lockForUpdate()` → 既存 active を ended（ended_at=now）→ 新規 active（price=plan.price, started_at=now）。自分のクリエイター → `DomainException`→ 422、`! is_accepting` → 422、同一プランで既に active → 既存を返す。
  - `SupportService::end(Support $support): void` — status=ended, ended_at=now
  - ルート: `supports.store`（POST `/@{creator:slug}/plans/{plan}/support`, `throttle:10,1`, auth+verified）、`supports.destroy`（DELETE `/supports/{support}`, 本人以外 404）、`supporting.index`（GET `/supporting`）、`feed`（GET `/feed`, 支援中クリエイターの公開投稿 summary paginate 20）、`creator.supporters.index`（プラン別 active 人数 + 支援者 display name/name の一覧 paginate 50）
  - `dashboard` ルートは `feed` へリダイレクトに変更し、ログイン後の遷移先を `feed` にする（`config/fortify.php` の `home`）。
- [ ] **Step 1: 失敗するテスト**

```php
it('starts a support and unlocks posts', ...);
it('switches plan by ending the previous support', ...);   // active 1件・ended 1件
it('is idempotent for the same plan', ...);
it('keeps a single active support under repeated requests', function () {
    // 同じユーザーで 2 プランに start を連続実行 → active は1件
});
it('refuses supporting yourself', ...);
it('refuses a plan not accepting', ...);
it('requires verified email', ...);
it('ends a support and relocks', ...);
it('404s ending someone elses support', ...);
it('shows feed of supported creators only', ...);
it('lists supporters per plan for the creator', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** 実装 → **Step 4:** PASS → **Step 5:** フロント実装、types:check / check PASS
- [ ] **Step 6:** コミット `feat: ダミー支援・フィード・支援者一覧を追加`

---

### Task 11: 孤児ファイル掃除コマンド

**Files:**
- Create: `app/Console/Commands/PruneOrphanFiles.php`
- Modify: `routes/console.php`（`Schedule::command('fanbox:prune-orphan-files')->daily()`）
- Test: `tests/Feature/Console/PruneOrphanFilesTest.php`

**Interfaces:**
- Produces: `fanbox:prune-orphan-files {--dry-run}` — `private` ディスクの `creators/` 配下を走査し、`post_files.path` / `thumb_path` にも `creators.avatar_path` 等にも無く、**最終更新が 24 時間より前**のファイルを削除（アップロード処理中のファイルを消さないため）。`public` ディスクは `creators.avatar_path / header_path / plans.cover_path` と照合。削除件数を出力。

- [ ] **Step 1: 失敗するテスト** — 参照ありは残る / 参照なし・古いは消える / 参照なし・新しいは残る / `--dry-run` は消さない
- [ ] **Step 2:** FAIL → **Step 3:** 実装 → **Step 4:** PASS
- [ ] **Step 5:** コミット `feat: 孤児ファイル掃除コマンドを追加`

---

### Task 12: デモデータと最終検証

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`, `README.md`
- Test: 既存全テスト

- [ ] **Step 1:** Seeder: テストユーザー（`fan@example.com` / `creator@example.com`、パスワードは `password`、メール認証済み）、クリエイター1名・プラン3つ（300/500/1000）・投稿（0/300/1000 円）。README に起動手順（`sail up -d`, `docker/minio-init.sh`, `sail artisan migrate --seed`, `sail npm run dev`）とテスト実行方法を記載。
- [ ] **Step 2:** `sail pest` 全件 PASS、`sail bin pint --test` PASS、`sail npm run types:check` / `check` / `build` PASS
- [ ] **Step 3:** 実ブラウザで確認: 登録 → クリエイター登録 → プラン作成 → 画像付き投稿公開 → 別ユーザーでロック表示 → 支援 → 閲覧可 → 支援停止 → 再ロック。MinIO 上の画像に EXIF が無いこと、署名URLが5分で失効する設定であること。
- [ ] **Step 4:** コミット `chore: デモデータと起動手順を追加`
