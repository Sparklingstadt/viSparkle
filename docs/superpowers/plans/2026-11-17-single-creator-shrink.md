# クリエイター一人・中核機能だけへの縮小 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** viSparkle を「クリエイターは持ち主一人、機能は月額支援 → 限定投稿の閲覧だけ」に縮小する。

**Architecture:** 外側の機能（管理画面 → ショップ → 投稿の付加機能 → Stripe Connect）から順に、コード・画面・テスト・マイグレーションをまとめて削除する。各タスクの終わりにテストが通る状態を保つ。最後に `creators` テーブルをなくして一人化し、URL を `/` 起点に変える。マイグレーションは削除・編集して最終形を直接作る形にする（本番データなし、確認済み）。

**Tech Stack:** Laravel 13 / PHP 8.4、Inertia + React + TypeScript、Wayfinder、Pest、MySQL、Stripe（stripe-php）

**Spec:** `docs/superpowers/specs/2026-11-17-single-creator-shrink-design.md`

## Global Constraints

- 外した機能のコードを、ルート・画面・モデル・列・設定・テスト・シード・Wayfinder 生成物のどこにも残さない。
- 安全策は弱めない: 署名付き URL、EXIF 除去、Tiptap の許可リスト検証、Webhook の署名検証と冪等処理、有効な支援はユーザーごとに一つ（`supports.current_key` の一意制約）。
- デザイン（#50〜#51）は変えない。残る画面はそのまま使う。
- 各タスクの終わりに、次の 4 つがすべて通ること（以下「全チェック」）。
  - `php artisan test`
  - `npm run types:check`
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/pint --test`
- コマンドの実行場所: Sail のコンテナは本体リポジトリをマウントしている。そのため、このワークツリーを `/var/www/html` にマウントした使い捨てコンテナで実行する（`docker run --rm -v "$PWD":/var/www/html --network <sail のネットワーク> sail-8.4/app ...`。`.env` の `DB_HOST=mysql`、テスト用 DB は `testing`）。
- コミットメッセージは日本語、`feat:` / `refactor:` / `docs:` などの接頭辞をつけ、末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` を入れる。
- Wayfinder の生成物（`resources/js/actions`、`resources/js/routes`、`resources/js/wayfinder`）は Vite のビルド時に作り直される。ルートを消したら `php artisan wayfinder:generate` で作り直し、git の管理対象ならコミットに含める。

## Review Focus

1. **未ログインのファンが、有料投稿を直接 URL で開く** → 本文・画像・添付が漏れない。
   - Task 6 の `LeakageTest` を `/posts/{post}` 版に書き換えて確認する。
2. **支援中のファンが、もう一度別プランで支援を始めようとする** → 二重のサブスクリプションができない。
   - Task 6 の `SupportTest` に、`current_key` の一意制約と「支援中なら確認画面がプラン変更へ案内する」テストを残す。
3. **`is_creator` のユーザーがいない状態でトップを開く** → 500 にならず、紹介文とプラン一覧（空）を表示する。
   - Task 6 の `HomeTest` に入れる。
4. **Connect 時代のイベント（`account.updated` など）や、`creator_id` の入ったメタデータの Webhook が届く** → 例外にならず、処理済みとして記録して無視する。
   - Task 5 の `WebhookTest` に入れる。
5. **持ち主が自分の有料投稿を見る** → 支援していなくても全文が見える。持ち主以外のログインユーザーには `/creator/*` が 403 になる。
   - Task 6 の `EntitlementMatrixTest` と `CreatorAccessTest` に入れる。

---

### Task 1: 縮小前の目印を付ける

**Files:** なし（git のみ）

- [ ] **Step 1:** `git tag before-shrink 0f709a0` でローカルにタグを付ける。GitHub への push は、PR を出す前に持ち主へ確認してから行う。
- [ ] **Step 2:** 全チェックを一度流し、縮小前の基準として結果を記録する（ここで落ちるテストがあれば、縮小とは無関係なので持ち主に報告する）。

---

### Task 2: 管理画面・通報・審査・停止・規約同意の記録を外す

**Files:**
- Delete:
  - `app/Http/Controllers/Admin/*`
  - `app/Http/Controllers/ReportController.php`
  - `app/Http/Requests/Admin/*`
  - `app/Http/Requests/StoreReportRequest.php`
  - `app/Http/Middleware/{EnsureUserIsAdmin,EnsureTermsAccepted,LogoutSuspendedUsers}.php`
  - `app/Http/Controllers/Legal/AcceptTermsController.php`
  - `app/Models/{AdminAction,Report}.php`
  - `app/Enums/{ReportReason,ReportStatus,ReportTarget}.php`
  - `app/Services/Billing/CreatorSuspension.php`
  - `app/Console/Commands/ManageAdmin.php`
  - `routes/admin.php`
  - `resources/js/pages/admin/*`
  - `resources/js/layouts/admin/layout.tsx`
  - `resources/js/pages/legal/accept.tsx`
  - `resources/js/components/report-dialog.tsx`
  - マイグレーション `2026_11_02_000002_create_reports_table.php`、`2026_11_02_000003_create_admin_actions_table.php`
  - `tests/Feature/Admin/*`、`tests/Feature/ReportTest.php`、`tests/Feature/Legal/TermsAcceptanceTest.php`、`tests/Feature/Console/ManageAdminTest.php`
- Modify:
  - `routes/web.php`（reports と legal/accept を削除）
  - `bootstrap/app.php`（ミドルウェアの別名と登録）
  - `app/Models/User.php`
  - `app/Actions/Fortify/CreateNewUser.php`（`terms` は `accepted` の検証だけ残し、保存しない）
  - `app/Services/Legal/LegalDocuments.php`（版の記録に使う部分を削除）
  - `2026_11_02_000001_add_ops_columns.php`（users の `is_admin`・`suspended_*`・`terms_*`、posts の `hidden_*`、creators の `closed_at` を除く。payments の返金列は Task 5 で扱う）
  - `app/Models/Post.php`（`hidden_at` の参照を削除）
  - `app/Services/Entitlement.php`（非公開化の判定を削除）
  - 通報ボタン・非公開表示を使っている画面と、上部バー・サイドメニューの管理リンク

**Interfaces:**
- Produces: `users.is_admin` はこの時点ではまだ残す（Task 6 で `is_creator` に置き換える）。`admin` ミドルウェアと `ManageAdmin` はここで消す。

- [ ] **Step 1:** `tests/Feature/Auth/RegistrationTest.php` に、次のテストを追加して失敗を確認する。
  - `test('registration still requires accepting the terms')`: `terms` なしで登録すると `terms` の検証エラーになる。
  - `test('registration does not record a terms version')`: 登録後の users 行に `terms_version` 列が存在しない（`Schema::hasColumn('users','terms_version')` が false）。
- [ ] **Step 2:** 上記のファイルを削除・修正する。
- [ ] **Step 3:** `grep -rniE "admin|report|suspend|terms_version|hidden_at" app routes resources/js database tests config` を流し、残っているのが Fortify・Laravel 標準のものと、Task 6 で消す `is_admin` だけであることを確かめる。
- [ ] **Step 4:** 全チェックを通す。
- [ ] **Step 5:** コミットする（`refactor: 管理画面・通報・審査・利用停止・規約同意の記録を外す`）。

---

### Task 3: ショップ一式を外す

**Files:**
- Delete:
  - Product/Order/Cart まわりの全モデル: `Product`、`ProductVariant`、`ProductImage`、`ProductFile`、`ProductLike`、`ProductCategory`、`CartItem`、`Order`、`OrderItem`、`app/Models/Concerns/IsCategory.php`（PostCategory も Task 4 で消すため、ここで両方の参照を確認しつつ、ファイル削除は Task 4）
  - `app/Enums/{OrderStatus,OrderCancelReason,ProductKind}.php`
  - `app/Services/Shop/*`
  - `app/Events/Shop/*`、`app/Listeners/Shop/*`、`app/Mail/Shop/*`、メールの Blade
  - `app/Policies/{ProductPolicy,OrderPolicy}.php`
  - `app/Presenters/ProductPresenter.php`
  - `app/Http/Controllers/Creator/{ShopSettings,Product,ProductImage,ProductFile,Order}Controller.php`
  - `app/Http/Controllers/Fan/{Cart,CartItem,OrderCheckout,Order,OrderFileDownload,ProductLike,LikedProduct}Controller.php`
  - `app/Http/Controllers/Public/{ProductPage,CategoryPage}Controller.php`
  - 対応する FormRequest（`ProductRules`、`Store/UpdateProductRequest`、`Reorder*`、`Store*ImageRequest`、`StoreProductFileRequest`、`UpdateShopSettingsRequest`、`StartOrderCheckoutRequest`）
  - `app/Console/Commands/ReconcileOrders.php`
  - `app/Billing/Data/{LineItem,PaymentCheckoutRequest,ShippingDetails}.php`
  - 商品・注文の画面とコンポーネント（`creator/{shop,products,orders}`、`fan/{cart,cart-confirm,orders,liked-products}`、`public/{product,category}`、`product-card`、`product-carousel`、`product-like-button`、`cart-link`、`order-*`、`category-links` のうち商品カテゴリ部分）
  - マイグレーション `2026_11_02_000008` 〜 `000013`、`2026_11_03_000001` 〜 `000005`、`2026_11_04_000001`、`000002`
  - シード `DemoProductsSeeder`、`DemoProductPainter`、`DemoCategoriesSeeder`、Product/Order 系の Factory
  - テスト `tests/Feature/Shop/*`、Fan の Cart・Order 系、Creator の Product・Shop・Order 系、`Public/{ShopPage,CartCount,CategoryPage,HomeCategories,NewProducts}Test`、`Models/{OrderFee,ProductCategory,ProductKind,ShopSchema}Test`、`Files/ProductFileStorerTest`、`Console/ReconcileOrdersTest`、`DemoCategoriesSeederTest`
- Modify:
  - `routes/{creator,fan,web,console}.php`
  - `app/Billing/{PaymentGateway,StripeGateway,FakeGateway}.php`（`createPaymentCheckoutSession`、`refundPaymentIntent`、`retrievePaymentIntentStatus`、`retrieveLatestChargeId` を削除）
  - `app/Jobs/ProcessStripeEvent.php`（`OrderSync` と注文用の分岐を削除。`checkout.session.completed` はサブスクリプション ID があるときだけ同期）
  - `app/Models/{Creator,User}.php`（商品・注文・カートのリレーション、ショップ列を削除）
  - `config/fanbox.php`（`product_files_max`、`support_*`、ショップ関連を削除）
  - `HandleInertiaRequests`（カート件数を削除）
  - ホームとクリエイターページ（商品の欄を削除）
  - `Fan/SupportingController` などで注文を参照している箇所

- [ ] **Step 1:** `tests/Feature/Billing/WebhookTest.php` に `test('a checkout.session.completed without a subscription is recorded and ignored')` を追加して失敗を確認する。Webhook を受けると 200 になり、`stripe_events.processed_at` が入り、例外が出ないこと。
- [ ] **Step 2:** 上記を削除・修正する。
- [ ] **Step 3:** `grep -rniE "product|order|cart|shop|shipping" app routes resources/js database tests config` を流し、残るのが無関係な語（CSS の `order-` クラスなど）だけであることを確かめる。
- [ ] **Step 4:** 全チェックを通す。
- [ ] **Step 5:** コミットする（`refactor: ショップ・カート・注文・デジタル商品を外す`）。

---

### Task 4: 投稿の付加機能（カテゴリ・いいね・フォロー・フィード・検索・人気）を外す

**Files:**
- Delete:
  - `app/Models/{PostCategory,PostLike,Follow}.php`、`app/Models/Concerns/IsCategory.php`
  - `app/Http/Controllers/Fan/{Feed,Follow,PostLike}Controller.php`
  - `app/Http/Controllers/Public/{Search,PostCategoryPage}Controller.php`
  - `app/Console/Commands/RecountPostLikes.php`
  - `resources/js/pages/{fan/feed,public/search,public/post-category}.tsx`
  - `follow-button`、`like-button`、`site-search`、`category-links`、`creator-card`（人気・新着クリエイター用）
  - マイグレーション `2026_11_02_000004_add_view_count_to_posts`、`000006_create_post_likes_table`、`000007_create_follows_table`、`2026_11_04_000003_create_post_categories_table`
  - シード `DemoPostCategoriesSeeder`、`DemoPostLikesSeeder`、`DemoCrowdSeeder`
  - テスト `Fan/{Feed,Follow,PostLike}Test`、`FollowModelTest`、`Public/{Search,PostCategoryPage,CreatorPagePostCategory,HomePostCategories,HomeSort,WeeklyPopularCreators}Test`、`Models/PostCategoryTest`、`Creator/PostCategorySelectionTest`、`Demo{PostCategories,PostLikes,Crowd}SeederTest`
- Modify:
  - `routes/{web,fan}.php`（`dashboard` は `/` への転送に変える）
  - `Creator` モデル（`weeklyPopular`、`supportable`、`withMinPlanPrice` の各スコープと、follows のリレーションを削除）
  - `Post`、`PostPresenter`（いいね数・カテゴリ・閲覧数を削除）
  - `Entitlement::canLike` を削除
  - `HomeController`（新着順の全体公開投稿だけを出す。並び替えなし）
  - `Creator/PostController` と投稿編集画面（カテゴリ選択を削除）
  - `post-card`、`public/post.tsx`、`public/home.tsx`、上部バー、サイドメニュー（フィード・検索・いいねした商品を削除）

- [ ] **Step 1:** `tests/Feature/DashboardTest.php` を書き換える: `test('dashboard redirects to the home page')` で、ログイン済みの `/dashboard` が `/` へ転送されること。失敗を確認する。
- [ ] **Step 2:** 上記を削除・修正する。
- [ ] **Step 3:** `grep -rniE "like|follow|feed|search|categor|view_count|popular" app routes resources/js database tests config` を流し、残るのが無関係なもの（`like` を含む別の語など）だけであることを確かめる。
- [ ] **Step 4:** 全チェックを通す。
- [ ] **Step 5:** コミットする（`refactor: 投稿カテゴリ・いいね・フォロー・フィード・検索・人気表示を外す`）。

---

### Task 5: Stripe Connect・売上・返金・休止を外す

**Files:**
- Delete:
  - `app/Http/Controllers/Creator/{Earnings,Closure}Controller.php`
  - `app/Services/Billing/{AccountSync,Refunds,CreatorClosure}.php`
  - `app/Billing/Data/{AccountData,ChargeData,RefundData}.php`
  - `app/Console/Commands/DemoPayoutAccounts.php`
  - `resources/js/pages/creator/earnings.tsx`
  - テスト `Billing/{AccountSync,Refunds}Test`、`Creator/{Earnings,CreatorClosure}Test`、`Console/DemoPayoutAccountsTest`
- Modify:
  - `PaymentGateway` / `StripeGateway` / `FakeGateway`:
    - `createConnectAccount`、`createAccountOnboardingLink`、`createDashboardLoginLink`、`retrieveAccount`、`refundInvoice`、`retrieveCharge` を削除する。
    - `createCheckoutSession` から `transfer_data` と `application_fee_percent` を外す。
  - `CheckoutRequest`（`destinationAccountId`、`feePercent` を削除）
  - `CheckoutService`（口座の確認と手数料を削除。受け付け可否は `plan.is_accepting` だけで判断）
  - `PaymentRecorder` と `Payment` モデル（`fee_percent`、返金・異議申し立てを削除）
  - `ProcessStripeEvent`（`charge.*`、`account.*` の分岐を削除。`default => null` で無視する）
  - `StripeWebhookController` と `routes/billing.php`（`connect` を削除）
  - `config/fanbox.php`、`config/services.php`（`platform_fee_percent`、Connect 用 Webhook の秘密鍵を削除）
  - マイグレーション:
    - `add_billing_columns` から creators の Connect 列を除く。
    - `create_payments_table` から `fee_percent` を除く。
    - `add_ops_columns` から payments の返金列を除く。
  - `Creator` モデル（`stripe_account_id`、`payouts_enabled`、`details_submitted`、`closed_at`、`open` スコープ、`canAcceptSupports` 系を削除）
  - `.env.example`（Connect 関連の変数を削除）
  - クリエイター用サイドメニュー（売上・休止を削除）
  - `PlanController` / プラン画面で「口座未登録」を表示していた箇所

- [ ] **Step 1:** `tests/Feature/Billing/WebhookTest.php` に `test('connect-era events are recorded and ignored')` を追加する。`account.updated` と `charge.refunded` を受けると、どちらも 200 になり `processed_at` が入ること。あわせて `test('the connect webhook endpoint is gone')` を追加し、`POST /stripe/connect-webhook` が 404 になること。失敗を確認する。
- [ ] **Step 2:** `tests/Feature/Billing/StripeGatewayTest.php` の Checkout のテストを、送信本文に `transfer_data` と `application_fee_percent` が**含まれない**ことを確かめる形に書き換える（`RecordingStripeHttpClient` で記録した本文を見る）。失敗を確認する。
- [ ] **Step 3:** 上記を削除・修正する。
- [ ] **Step 4:** `grep -rniE "connect|payout|application_fee|transfer_data|fee_percent|refund|dispute|closed_at|closure|earnings" app routes resources/js database tests config .env.example` を流し、残るのが無関係なもの（DB の connection、Inertia の connect など）だけであることを確かめる。
- [ ] **Step 5:** 全チェックを通す。
- [ ] **Step 6:** コミットする（`refactor: Stripe Connect をやめ、売上・返金・休止を外す`）。

---

### Task 6: クリエイターを持ち主一人にする

**Files:**
- Delete:
  - `app/Models/Creator.php`、`database/factories/CreatorFactory.php`
  - `app/Rules/CreatorSlug.php`
  - `app/Http/Controllers/Creator/{CreatorRegistration,Profile}Controller.php`
  - `app/Http/Requests/Creator/{RegisterCreator,UpdateCreatorProfile}Request.php`
  - `app/Http/Controllers/Public/CreatorPageController.php`
  - `resources/js/pages/creator/{register,profile}.tsx`、`resources/js/pages/public/creator.tsx`
  - マイグレーション `2026_10_06_000001_create_creators_table.php`
  - シード `DemoCreatorsSeeder`、`DemoShowcaseSeeder`、`DemoSupportsSeeder`（必要な部分は `DatabaseSeeder` に統合）
  - テスト `Creator/{CreatorRegistration,CreatorProfile}Test`、`Public/CreatorPageTest`、`Demo{Showcase,Supports}SeederTest`
- Create:
  - `config/visparkle.php`: `creator.name`（`env('CREATOR_NAME', 'viSparkle')`）、`creator.bio`（`env('CREATOR_BIO')`）、`creator.avatar`（`'/icon-512.png'`）、`creator.header`（今ある OGP 画像のパス）
  - `app/Console/Commands/ManageCreator.php`: シグネチャ `visparkle:creator {email} {--revoke}`
  - `tests/Feature/Console/ManageCreatorTest.php`
  - `tests/Feature/Public/HomeTest.php`
  - `tests/Feature/Creator/CreatorAccessTest.php`
- Modify:
  - マイグレーション:
    - users: 標準の users マイグレーションに `is_creator`（default false）と `stripe_customer_id`（nullable、unique）を直接書く。`add_billing_columns` と `add_ops_columns` は、中身がなくなるので削除する。
    - plans・supports・posts・payments: `creator_id` を外す。`plans.price` は単独で一意にする。`supports.current_key` は `CASE WHEN status = 'active' THEN user_id END` の仮想列にする。billing 列は各 create マイグレーションに直接書く。
    - `2026_11_02_000005_add_started_at_index_to_supports` は supports の作成に統合する。
  - `User`: `creator()` を削除し、`isCreator(): bool` を追加する。`is_admin` を `is_creator` に置き換える。
  - `EnsureUserIsCreator`: `$user->isCreator()` でなければ 403。
  - `Plan`、`Post`、`Support`、`Payment`: `creator` リレーションと `creator_id` を削除する。
  - `PlanPolicy`、`PostPolicy`: 所有者の確認を `$user->isCreator()` に変える。
  - `Entitlement`:
    - `for(?User $viewer): self` は、閲覧者の有効な支援の最高額（`?int`）と `isCreator` を持つ。
    - `canView(Post $post): bool`
    - `isAuthor(): bool`（旧 `isAuthorOf`）
    - `supportPrice(): ?int`（旧 `supportPriceFor`）
  - `CheckoutService`、`SupportService`、`SubscriptionSync`、`PaymentRecorder`: `creator_id` とメタデータの `creator_id` を削除する。キャッシュキーは `user_id` だけにする。
  - `SubscriptionSync`: メタデータに `creator_id` が付いた古いサブスクリプションも、`user_id` と `plan_id` で処理できるようにする。
  - ルート:
    - `web.php`: `/` は `HomeController`、`/posts/{post}` と `/posts/{post}/files/{file}` は `/@{creator}` なし。
    - `fan.php`: `/plans/{plan}/confirm`、`POST /plans/{plan}/support`。
    - `creator.php`: plans・posts・supporters だけを残す。
  - `HomeController`: クリエイターの紹介（`config('visparkle.creator')`）、受け付け中のプラン、閲覧者の支援状況、投稿一覧（新着順、ページ送り）を返す。中身は今の `CreatorPageController` から移す。
  - `PostPageController`、`PostFileDownloadController`、`CheckoutConfirmController`、`SupportController`、`SupportingController`、`SupporterController`、`AccountDeletion`、`PruneOrphanFiles`、`HandleInertiaRequests`（`auth.creator` を `auth.isCreator: boolean` に置き換える）
  - `PostPresenter`: クリエイター情報を外す。
  - フロント:
    - `public/home.tsx`: 旧 `creator.tsx` の内容を移す。
    - `public/post.tsx`、`fan/checkout-confirm.tsx`、`fan/supporting.tsx`、`creator/*`、`creator-header.tsx`（設定の値を表示）
    - 上部バー、サイドメニュー、`types/fanbox.ts`、`types/auth.ts`
  - `DatabaseSeeder`: 持ち主 `creator@example.com`（`is_creator` true）、ファン `fan@example.com`、プラン 3 件、無料・有料の投稿数件（`DemoPostCoversSeeder` のカバー付き）。パスワードはどちらも `password`。
  - `.env.example`: `CREATOR_NAME`、`CREATOR_BIO` を追加する。

**Interfaces:**
- Produces:
  - `User::isCreator(): bool`
  - `Entitlement::for(?User): self`、`canView(Post): bool`、`isAuthor(): bool`、`supportPrice(): ?int`
  - ルート名: `home`、`posts.show`、`posts.files.show`、`supports.confirm`、`supports.store`、`supports.checkout.complete`、`supports.update`、`supports.destroy`、`supports.resume`、`supporting.index`、`billing.portal`、`creator.plans.*`、`creator.posts.*`、`creator.posts.files.*`、`creator.supporters.index`、`legal.*`、`stripe.webhook`
  - Inertia の共有値: `auth.isCreator: boolean`

- [ ] **Step 1:** 次のテストを書き、失敗を確認する。
  - `EntitlementMatrixTest` を一人用に書き換える。
    - 未ログインの閲覧者は、無料の公開投稿だけ見える。
    - 500 円の支援者は、`required_price` が 0 と 500 の投稿が見え、1000 の投稿は見えない。
    - 解約済み（`status` が active でない）の支援者は、無料の投稿だけ見える。
    - 持ち主は、下書きも有料投稿も見える。
    - 下書きは持ち主以外には見えない。
  - `HomeTest`
    - `test('home shows the creator profile from config')`: `config(['visparkle.creator.name' => 'テスト'])` のとき、props の `creator.name` が `'テスト'`。
    - `test('home works when no creator account exists')`: users が空でも 200 になり、`plans` は空配列。
    - `test('home lists only accepting plans and published posts')`
  - `CreatorAccessTest`
    - `test('non-creator users get 403 on creator pages')`: `/creator/plans`、`/creator/posts`、`/creator/supporters`。
    - `test('the creator can open creator pages')`
  - `ManageCreatorTest`
    - `visparkle:creator fan@example.com` で `is_creator` が true になる。
    - `--revoke` で false に戻る。
    - 存在しないメールアドレスなら終了コード 1。
  - `SupportTest` に `test('a fan cannot hold two active supports')` を追加する: 同じ user で active の supports を 2 行入れると `QueryException`。
  - `LeakageTest` と `PostPageTest`、`AttachmentDownloadTest` を、`/posts/{post}` の URL に書き換える。
- [ ] **Step 2:** 上記を削除・作成・修正する。
- [ ] **Step 3:** 残りのテストのうち、`Creator::factory()` や `/@` の URL を使っているものを一人用に直す。
- [ ] **Step 4:** `grep -rnE "creator_id|Creator::|->creator\b|/@|slug|is_admin|reserved_slugs" app routes resources/js database tests config` を流し、何も出ないことを確かめる（`creator.` で始まるルート名と `is_creator` は対象外）。
- [ ] **Step 5:** `php artisan migrate:fresh --seed` が通り、`creator@example.com` でログインしてプラン作成・投稿作成ができ、`fan@example.com` で偽決済の支援を始めると限定投稿が読めることを、ブラウザで確かめる。
- [ ] **Step 6:** 全チェックを通す。
- [ ] **Step 7:** コミットする（`refactor: クリエイターを持ち主一人にし、creators テーブルをなくす`）。

---

### Task 7: 法的文面・ドキュメントを一人用にする

**Files:**
- Modify:
  - `resources/legal/terms.md`、`resources/legal/privacy.md`: 複数クリエイター・クリエイター登録・Stripe Connect・送金・手数料・ショップ・通報に触れる条文を削除または書き換える。変更箇所は PR 本文に列挙し、持ち主の確認を求める。
  - `app/Services/Legal/LegalDocuments.php`、`resources/js/pages/legal/tokushoho.tsx`: ショップ・送料などの項目を削除する。
  - `README.md`:
    - 縮小後の説明と、デモアカウント表（持ち主・ファン）。
    - `visparkle:creator` の使い方。
    - 「外した機能と、その設計書・計画書」の一覧（`docs/superpowers/specs/` と `plans/` の各ファイルへのリンク）と、タグ `before-shrink` の説明。
  - `docs/operations/stripe-setup.md`、`docs/operations/laravel-cloud.md`: Connect・Connect 用 Webhook・手数料・ショップ・`ReconcileOrders` のスケジュールの手順を削除する。
- Test: `tests/Feature/Public/LegalPagesTest.php`
  - 3 ページが 200 になる。
  - 本文に「Connect」「送金」「ショップ」を含まない。

- [ ] **Step 1:** `LegalPagesTest` に、上の「含まない」テストを追加して失敗を確認する。
- [ ] **Step 2:** 文面とドキュメントを書き換える。
- [ ] **Step 3:** 全チェックを通す。
- [ ] **Step 4:** コミットする（`docs: 利用規約・プライバシーポリシー・手順書を一人用の内容にする`）。

---

### Task 8: 最終確認と PR

- [ ] **Step 1:** 設計書の「外す機能」の各項目について、`grep` で名前が残っていないことを確かめる。
- [ ] **Step 2:** `npm run build` が通ることを確かめる。
- [ ] **Step 3:** 全体の行数を縮小前と比べ、PR 本文に載せる（`git diff --stat before-shrink`）。
- [ ] **Step 4:** タグ `before-shrink` の push と、PR の作成について持ち主に確認してから行う。
