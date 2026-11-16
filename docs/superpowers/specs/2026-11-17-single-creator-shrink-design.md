# クリエイター一人・中核機能だけへの縮小 設計書

## 目的と理由

今の viSparkle は、複数クリエイター、Stripe Connect による送金、ショップ、カート、デジタル商品、フォロー、いいね、カテゴリ、通報・審査などを持つ。規模が大きくなり、持ち主が全体の仕組みを読み切れなくなった。

そこで、**持ち主がコードを読んで仕組みを理解できる大きさまで一度縮小する**。理解できたら、機能を一つずつ元の規模へ戻していく。縮小の軸は二つある。

1. クリエイターは持ち主一人だけにする。
2. 残す機能は「月額で支援する → 支援額に応じて限定投稿が読める」という一本の流れだけにする。

本番（Laravel Cloud）にはまだ利用者もデータもないため、縮小版を `main` にしてよい（持ち主に確認済み）。

## 目標・非目標・やってはいけないこと

- **目標**
  - 支援の流れ（プラン選択 → Stripe Checkout → Webhook で支援が有効になる → 限定投稿が読める → 解約・再開・プラン変更）が、縮小後も今と同じように動く。
  - 外した機能のコードが、ルート・画面・モデル・列・設定・テスト・シードのどこにも残らない。
  - テスト（Pest）、型チェック（`types:check`）、PHPStan、Pint がすべて通る。
- **非目標**
  - 新しい機能の追加や、見た目の作り直し。今のデザイン（#50〜#51）はそのまま使う。
  - 外した機能を、設定で切り替えられる形で残すこと。
- **やってはいけないこと**
  - 使われないコードや列を「念のため」残すこと。読む量が減らなければ縮小の意味がない。
  - 支援・閲覧権限まわりの安全策（署名付き URL、EXIF 除去、Tiptap の許可リスト検証、Webhook の署名検証と冪等処理、同じユーザーの二重支援を防ぐ一意制約）を弱めること。

## 進め方の検討（Approaches）

| 案 | 内容 | 判断 |
| --- | --- | --- |
| 1. 最新コードから削る | 今のブランチで外す機能を削除し、残りを一人用に書き換える | **採用**。最近のデザイン・セキュリティ修正・リファクタリングが残り、戻すときに `main` の履歴と比べやすい |
| 2. 古い時点から分岐する | コア完成時点（`69d1210`）や決済導入時点（`25468a7`）から一人化する | 不採用。その後の 90 近いコミットぶんの修正が失われ、読みたい「今のコード」から離れる |
| 3. 作り直す | 新規に書き、元のコードは参考にする | 不採用。「今あるコードを読んで理解する」目的に合わない |

範囲は「B: 複数クリエイターの部分と、中核以外の機能を両方外す」を持ち主が選んだ。

## 縮小後の姿

### 残す機能

| 区分 | 機能 |
| --- | --- |
| 公開 | トップ（クリエイターの紹介 + プラン一覧 + 投稿一覧）、投稿詳細（閲覧制御つき）、投稿の添付ファイル取得、法的表記（特商法・利用規約・プライバシーポリシー） |
| ファン | 会員登録・ログイン（スターターキットのまま。パスキー・2 段階認証を含む）、アカウント設定、支援の開始（確認画面 → Checkout → 完了画面）、支援中の管理（プラン変更・解約・再開）、Stripe の請求ポータル |
| クリエイター（持ち主） | プランの作成・編集・削除、投稿の作成・編集・公開・削除と画像・添付のアップロード、支援者一覧 |
| 裏側 | Stripe Webhook（サブスクリプションと請求書）、開発用の偽決済（`BILLING_DRIVER=fake`）、孤児ファイルの掃除、ストレージ初期化 |

### 外す機能

ショップ（グッズ・デジタル商品・SKU・商品画像）、カート、注文と発送、ショップ経由の上乗せ支援、商品・投稿のカテゴリ、投稿・商品のいいね、フォロー、フィード、検索、週間人気クリエイター・新着クリエイター・人気順、閲覧数、クリエイター登録、クリエイターのプロフィール編集、ショップ設定、売上画面と Stripe Connect（口座登録・送金・手数料）、クリエイターの休止、管理画面一式（ダッシュボード、通報、投稿・商品の審査と非公開化、ユーザーの停止、支払いの返金、カテゴリ管理）、利用規約への同意の記録、注文メール、関連する Artisan コマンド（`DemoPayoutAccounts`、`ReconcileOrders`、`RecountPostLikes`、`ManageAdmin`）。

### 「クリエイター」の表し方

- `creators` テーブルはなくす。プラン・投稿・支援・支払いから `creator_id` を外す。クリエイターは一人しかいないので、「誰の」を記録する必要がない。
- 持ち主のアカウントは `users.is_creator`（真偽値）で見分ける。今の `is_admin` は置き換える。管理画面がなくなり、持ち主＝クリエイターになるため。
  - 付け外しは Artisan コマンド `visparkle:creator {email} [--revoke]` で行う（今の `ManageAdmin` の置き換え）。
  - `/creator/*` のルートは `creator` ミドルウェア（`EnsureUserIsCreator`）で守る。中身は `is_creator` の確認に変える。
- トップに出す名前・紹介文は設定ファイル（`config/visparkle.php` の `creator.name` と `creator.bio`、`.env` から読む）に置く。アイコンとヘッダー画像は `public/` の固定ファイルにする。プロフィール編集画面は作らない。

### URL

| URL | 中身 |
| --- | --- |
| `/` | トップ（今の `/@slug` のクリエイターページに当たる） |
| `/posts/{post}` | 投稿詳細 |
| `/posts/{post}/files/{file}` | 添付ファイル（署名付き URL へ転送） |
| `/plans/{plan}/confirm`、`POST /plans/{plan}/support` | 支援の確認と開始 |
| `/supports/checkout/complete`、`PUT/DELETE /supports/{support}`、`POST /supports/{support}/resume` | 完了画面と支援の操作（今と同じ） |
| `/supporting` | 支援中の管理（今と同じ） |
| `POST /billing/portal` | 請求ポータル（今と同じ） |
| `/creator/plans`、`/creator/posts`、`/creator/supporters` | 持ち主用の画面 |
| `/legal/*` | 法的表記 |
| `POST /stripe/webhook` | Webhook（Connect 用の `/stripe/connect-webhook` はなくす） |

`/dashboard` は `/` へ転送する（フィードがなくなるため）。

### データベース

既存のマイグレーションは、縮小後の最終形を直接作るように**書き直す**。テーブルごとに 1 ファイルにまとめ、「作ってから列を足す」履歴は残さない。Laravel 標準のもの（users、cache、jobs、passkeys、2 段階認証の列）はそのまま残し、`users` への追加列は users のマイグレーションに直接書く。

| テーブル | 列（id・timestamps は省略） |
| --- | --- |
| `users` | 標準の列 + `is_creator`、`stripe_customer_id`（一意） |
| `plans` | `name`、`description`、`price`（一意）、`cover_path`、`is_accepting`、`sort`、`stripe_product_id`、`stripe_price_id`（一意） |
| `supports` | `user_id`、`plan_id`（null 可）、`price`、`status`、`started_at`、`ended_at`、`stripe_subscription_id`（一意）、`stripe_status`、`current_period_end`、`cancel_at_period_end`、`current_key`（有効な支援はユーザーごとに一つ。`status = 'active'` のときだけ `user_id` を入れる仮想列に一意制約） |
| `posts` | `title`、`body`、`excerpt`、`cover_file_id`、`required_price`、`status`、`published_at` |
| `post_files` | 今と同じ |
| `payments` | `support_id`、`user_id`、`stripe_invoice_id`（一意）、`amount`、`paid_at` |
| `stripe_events` | 今と同じ |

外す列: `posts.view_count`、`posts.hidden_at`、`posts.hidden_reason`、カテゴリ、`payments.creator_id`、`payments.fee_percent`、返金・異議申し立ての列、`users.is_admin`、`users.suspended_*`、`users.terms_*`。

### 決済（Stripe Connect をやめる）

- Checkout は持ち主の Stripe アカウントで直接サブスクリプションを作る。`transfer_data`、`application_fee_percent`、`application_fee_amount`、`on_behalf_of` は送らない。
- 外すもの: `createConnectAccount` などの口座まわり、`AccountSync`、Connect 用 Webhook、`account.*` イベント、`Refunds`、`CreatorClosure`、`CreatorSuspension`、手数料と上乗せ支援の設定。
- 返金と異議申し立ては、当面 Stripe のダッシュボードで扱う。アプリ側では記録しない。
- 支払いを受け付けられるかどうかは、プランの `is_accepting` だけで決まる（口座の状態を見なくなる）。
- `PaymentGateway` のインターフェースと `FakeGateway` は、残るメソッドだけに絞る。

### 閲覧権限（`Entitlement`）

「投稿が見えるのは、持ち主本人か、公開済みで、無料または閲覧者の有効な支援の価格が `required_price` 以上のとき」。クリエイターごとの価格表はいらなくなるので、「閲覧者の有効な支援の価格（なければ 0）」と「閲覧者が持ち主かどうか」の二つを持つだけになる。

### 画面（フロントエンド）

- 外した機能のページ・コンポーネント・型・ルート関数（Wayfinder の生成物を含む）を削除する。
- 上部バーとサイドメニューを、残る画面だけに減らす。
- 今の `public/creator.tsx` の中身を `public/home.tsx` に移し、トップにする。

### シード

持ち主 1 人（`creator@example.com`）、ファン 1 人（`fan@example.com`）、プラン数件、無料・有料の投稿数件（カバー画像つき）。パスワードはどちらも `password`。複数クリエイターと商品のシードは削除する。

### ドキュメント

- `README.md` を縮小後の内容に書き直す。「外した機能と、その設計書・計画書の場所」の一覧を載せ、戻すときの道しるべにする。
- `docs/operations/stripe-setup.md` と `laravel-cloud.md` から、Connect・ショップ・手数料の手順を外す。
- `docs/superpowers/` の既存の設計書・計画書・台帳は、戻すときの参考としてそのまま残す。
- 縮小前の `main`（`0f709a0`）に `before-shrink` というタグを付けて GitHub へ送る案がある。外部に送る操作なので、送る前に持ち主に確認する。

## 決まっていること（持ち主が決めた）

- 縮小の目的は学習で、あとで元の規模に戻す。
- 範囲は B（複数クリエイターの部分と、中核以外の機能を両方外す）。
- 進め方は「最新コードから削る」。
- 縮小版を `main` にする。本番のデータベースは作り直してよい。

## 設計者の判断（持ち主の確認待ち）

- `creators` テーブルをなくし、`users.is_creator` と設定ファイルで表す。
- 管理画面をなくし、持ち主の画面は `/creator/*` に置く。
- 法的表記のページは残す（有料の支援を売る以上、特商法の表記は必要なため）。同意の記録はなくす。
- 返金・異議申し立てはアプリで扱わない。
- マイグレーションは書き直す。
- 会員登録時の「規約に同意する」チェックボックスは残す。検証はするが、記録はしない。`EnsureTermsAccepted` と `legal/accept.tsx` はなくす。
- Stripe は今のプラットフォームアカウント（`STRIPE_SECRET` のもの）をそのまま使う。持ち主が運営者でありクリエイターでもあるため。
- トップのアイコンとヘッダー画像は、当面は今ある viSparkle のアイコンと OGP 画像を流用する。差し替えは画像ファイルを置き換えるだけで済む。
- 利用規約とプライバシーポリシーの本文（`resources/legal/terms.md`、`privacy.md`）は、複数クリエイター・Stripe Connect・送金に触れている条文を削るか書き換える。法的な文面なので、変更箇所は PR で持ち主が確認する。公開前に専門家の確認が要ることは今と変わらない。

## 実装者に任せること

- コミットの分け方と順番。テストが通る状態を保ちながら削る。
- 削除によって動かなくなった箇所の直し方。
- 残る `config/fanbox.php` の項目の整理。使わなくなった項目は消す。
