# クリエイター支援サービス — サブプロジェクト2「決済」設計書

- 作成日: 2026-11-01
- ステータス: 承認済み（ユーザーが仕様・提案を事前承認。最終レビューはユーザーが実施）
- 前提: サブプロジェクト1「コア」（`2026-10-06-fanbox-core-design.md`）が main にマージ済み

## 1. 目的

サブプロジェクト1のダミー支援（ボタンを押すと即 `active`）を、**Stripe による本物の月額課金**に置き換える。

- ファンはクレジットカードで月額支援でき、停止・再開・プラン変更ができる
- クリエイターは Stripe に口座を登録し、売上から運営手数料を引いた額を受け取る
- 支援の状態（閲覧権限）は Stripe のサブスクリプション状態に従う

## 2. 成功基準

1. 口座登録済みクリエイターのプランに、ファンが Stripe Checkout で支払うと支援が `active` になり、限定投稿が見える
2. 支援の状態は Webhook で Stripe と同期する。同じイベントが重複・順不同で届いても結果が変わらない（冪等）
3. 支援停止は「期間終了時に停止」。期間終了までは閲覧でき、期間中は再開できる
4. プラン変更（同一クリエイター内）ができる。アップグレードは差額を即時請求し、支払い成功時にのみ適用される
5. 支払いの都度、運営手数料（既定 10%）を差し引いた額がクリエイターの Stripe 口座へ送金される
6. 同一ユーザー×同一クリエイターの有効な支援は DB 制約で最大1件
7. Stripe のキーがない開発環境・テストでも、偽の決済ドライバで一連の流れを動かせる（本番では使用不可）

## 3. 方式の検討

| 案                                                           | 概要                                                                                                      | 評価                                                                                                                            |
| ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **A. stripe-php を直接使い、薄いゲートウェイで包む（採用）** | 支援テーブル自体をサブスクリプションの記録にする。Stripe 呼び出しは `PaymentGateway` インターフェース越し | 状態の持ち主が `supports` の1か所で済む。Connect の宛先指定・手数料を自由に渡せる。偽ドライバでテスト・ローカル確認ができる     |
| B. Laravel Cashier                                           | `subscriptions` テーブルを Cashier が管理                                                                 | 1ユーザーが複数クリエイターを支援するため `type` を `creator:{id}` にする工夫が要る。Cashier の表と `supports` の二重管理になる |
| C. Payment Links                                             | Stripe 側で作ったリンクを貼る                                                                             | プラン変更・権限同期・手数料の制御ができない                                                                                    |

## 4. Stripe の構成

- **アカウント構成**: 運営が Stripe のプラットフォームアカウント（日本）。クリエイターは **Connect Express** アカウント（日本）
- **課金方式**: プラットフォーム上のサブスクリプションで **destination charge**
    - `transfer_data.destination` = クリエイターの Connect アカウント
    - `application_fee_percent` = 運営手数料（`config('fanbox.billing.platform_fee_percent')`、既定 10）
    - `on_behalf_of` は指定しない（販売者は運営。Stripe 手数料は運営が負担し、運営手数料から賄う）
- **決済画面**: Stripe Checkout（ホスト型、`mode=subscription`、`locale=ja`、カードのみ）。カード情報は自サーバーを通らない。3D セキュアは Checkout が処理する
- **価格**: プラン1件につき Stripe の Product と月額 Price（JPY・税込）を1組。プラン金額は作成後に変更できないので Price も不変。初回の支援時に遅延作成し `plans` に ID を保存する（作成時は冪等キー `plan-{id}-price` を使う）
- **請求日**: 支援開始日を起点とする毎月請求（Stripe 既定）
- **顧客**: ユーザー1人につき Stripe Customer 1件。初回の支援時に遅延作成し `users.stripe_customer_id` に保存（冪等キー `user-{id}-customer`）
- **カード変更・請求履歴**: Stripe カスタマーポータル（ポータルで解約・プラン変更された場合も Webhook で同期されるので整合は崩れない）
- **未払い時**: Stripe 側の設定で「再試行がすべて失敗したらサブスクリプションをキャンセル」にする（運用手順書に記載）。再試行中（`past_due`）は閲覧を続けられる猶予期間とする

## 5. データモデルの変更

### users

| カラム             | 型                       | 備考 |
| ------------------ | ------------------------ | ---- |
| stripe_customer_id | string, nullable, unique |      |

### creators

| カラム            | 型                       | 備考                                                                                |
| ----------------- | ------------------------ | ----------------------------------------------------------------------------------- |
| stripe_account_id | string, nullable, unique | Connect Express アカウント                                                          |
| payouts_enabled   | bool, default false      | `account.updated` から同期。`transfers` capability が active かつ `payouts_enabled` |
| details_submitted | bool, default false      | 登録手続きを最後まで終えたか（表示用）                                              |

`payouts_enabled = false` のクリエイターのプランには新規支援できない（ボタンは「準備中」）。

### plans

| カラム            | 型                       | 備考 |
| ----------------- | ------------------------ | ---- |
| stripe_product_id | string, nullable         |      |
| stripe_price_id   | string, nullable, unique |      |

### supports（変更）

支援1件 = Stripe サブスクリプション1件。プラン変更では行を作り直さず、`plan_id` と `price` を更新する。

| カラム                 | 型                        | 備考                                                                                                    |
| ---------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------- |
| stripe_subscription_id | string, nullable, unique  | サブプロジェクト1のダミー支援は null                                                                    |
| stripe_status          | string(32), nullable      | Stripe の生の状態（`active` / `past_due` など）。表示用                                                 |
| current_period_end     | datetime, nullable        | 次回請求日（＝停止予約時の終了日）                                                                      |
| cancel_at_period_end   | bool, default false       | 停止予約中か                                                                                            |
| current_key            | 生成列（virtual）, unique | `status = 'active'` のとき `"{user_id}:{creator_id}"`、それ以外は NULL。有効な支援を DB で1件に制限する |

`status`（`active` / `ended`）の意味は変えない。閲覧権限（`Entitlement`）は引き続き `status = active` だけを見る。

Stripe の状態との対応:

| Stripe                                               | `status`                                   |
| ---------------------------------------------------- | ------------------------------------------ |
| `active`, `trialing`, `past_due`                     | `active`                                   |
| `canceled`, `unpaid`, `incomplete_expired`, `paused` | `ended`（`ended_at` を記録）               |
| `incomplete`                                         | 支援を作らない（既存行があれば変更しない） |

`price` は Stripe の Price の金額（＝支払った額）、`plan_id` は `plans.stripe_price_id` から引く。

### payments（新規）

支払い成功（`invoice.paid`）の記録。クリエイターの売上表示に使う。

| カラム            | 型                                  | 備考                     |
| ----------------- | ----------------------------------- | ------------------------ |
| id                | bigint                              |                          |
| support_id        | FK supports, nullable, nullOnDelete |                          |
| user_id           | FK users, nullable, nullOnDelete    |                          |
| creator_id        | FK creators, cascade                |                          |
| stripe_invoice_id | string, unique                      | 冪等性の鍵               |
| amount            | unsigned int                        | 円。請求額（税込）       |
| fee_percent       | unsigned tinyint                    | 支払い時点の運営手数料率 |
| paid_at           | datetime                            |                          |
| timestamps        |                                     |                          |

0円の請求（ダウングレード直後など）は記録しない。

### stripe_events（新規）

| カラム       | 型                 | 備考                 |
| ------------ | ------------------ | -------------------- |
| id           | string, primary    | Stripe のイベント ID |
| type         | string             |                      |
| processed_at | datetime, nullable |                      |
| timestamps   |                    |                      |

## 6. ゲートウェイ

`App\Billing\PaymentGateway`（インターフェース）に Stripe 呼び出しを集約する。戻り値は Stripe のオブジェクトではなく自前の読み取り専用 DTO（`SubscriptionData` など）にする。

- `StripeGateway`: stripe-php の `StripeClient` を使う本番実装
- `FakeGateway`: 状態をキャッシュに保存する偽実装。テスト（array キャッシュ）とローカル開発（Redis）で使う。偽の Checkout 画面（`/dev/fake-checkout/{session}`）を持ち、「支払う」で完了扱いにする
- 切り替え: `config('fanbox.billing.driver')`（`stripe` / `fake`）。**`APP_ENV=production` で `fake` を選ぶと起動時に例外**

主なメソッド: `createCustomer`, `ensurePrice`, `createCheckoutSession`, `retrieveCheckoutSession`, `retrieveSubscription`, `setCancelAtPeriodEnd`, `changeSubscriptionPrice`, `createPortalSession`, `createConnectAccount`, `createAccountOnboardingLink`, `createDashboardLoginLink`, `retrieveAccount`, `retrieveInvoice`。

Stripe API のバージョン差（`current_period_end` がサブスクリプション項目側にある等）は `StripeGateway` の中だけで吸収する。

## 7. 処理の流れ

### 7.1 支援を始める

1. ファンがプランの「このプランで支援する」を押す → `POST /@{slug}/plans/{plan}/support`
2. サーバーで検証: 自分自身でない・プランが受付中・クリエイターが `payouts_enabled`・そのクリエイターへの有効な支援がない（あればプラン変更へ誘導して 422）
3. Customer と Price を用意し、Checkout Session を作成。`subscription_data` に送金先・手数料率・`metadata`（`user_id`, `creator_id`, `plan_id`）を付ける。セッションの有効期限は 30 分
4. 同じユーザー×クリエイターで開きっぱなしの古いセッションがあれば失効させる（二重のサブスクリプション作成を防ぐ）
5. `Inertia::location()` で Checkout へ遷移
6. 支払い完了後 `/supports/checkout/complete?session_id=...` に戻る。サーバーはセッションを取得し、サブスクリプションを同期してからクリエイターページへ（トースト「支援を開始しました」）。Webhook の到着を待たずに閲覧できる
7. キャンセル時はクリエイターページへ戻る

### 7.2 同期（`SubscriptionSync`）

Webhook・完了画面のどちらからも `SubscriptionSync::sync(サブスクリプション ID)` を呼ぶ。

- **イベントの中身は信用せず、毎回 Stripe から最新のサブスクリプションを取得**して反映する。これで重複・順不同のイベントでも最終状態が一致する
- `stripe_subscription_id` で行を探し、なければ `metadata` のユーザー・クリエイターで作成
- `metadata` の `user_id` / `creator_id` と、Customer の持ち主が一致しなければ記録して無視する
- 新しく `active` にしようとして、同じユーザー×クリエイターに別の有効な支援がある場合（二重決済）: 新しいほうのサブスクリプションを即時キャンセルし、エラーログ（`billing.duplicate_subscription`）を出す。返金は運営が手動で行う（サブプロジェクト3で管理画面化）
- 行単位でロックし、同じ支援の同時同期を直列化する

### 7.3 支援を停止する・再開する

- 停止: `DELETE /supports/{support}` → `cancel_at_period_end = true` を Stripe に送り、戻り値で同期。期間終了まで閲覧でき、終了時に `customer.subscription.deleted` で `ended` になる
- 再開: `POST /supports/{support}/resume` → `cancel_at_period_end = false`
- ダミー支援（`stripe_subscription_id` が null）の停止は従来どおり即時 `ended`

### 7.4 プラン変更（同一クリエイター内）

`PUT /supports/{support}` に `plan_id`。

- アップグレード（高い金額へ）: `proration_behavior=always_invoice`、`payment_behavior=pending_if_incomplete`。差額を即時請求し、**支払いが成功した場合だけ**新しいプランになる。失敗したら 422「お支払いに失敗しました。カード情報をご確認ください」
- ダウングレード（安い金額へ）: `proration_behavior=none`。即時に新プランへ切り替わり、次回から新しい金額。今期の差額は返金しない（確認ダイアログで明示）
- 変更先プランは受付中であること。停止予約中の支援は先に再開が必要（422）
- ダミー支援は対象外（停止してから新規に支援）

### 7.5 クリエイターの口座登録

- `/creator/earnings`（収益）画面
    - 未登録: 「Stripe で口座を登録する」→ Express アカウントを作成（国 JP、capability `transfers`）し、オンボーディングのリンクへ遷移
    - 登録途中: 「登録を続ける」（新しいオンボーディングのリンク）
    - 登録済み: 「Stripe ダッシュボードを開く」（入金履歴・振込先の変更は Stripe 側）
    - 戻り先（return / refresh URL）では Stripe からアカウントを取得して状態を同期する
- 売上: 直近 12 か月の月別「売上（税込）」「運営手数料（目安）」「受取額（目安）」と、有効な支援者数。手数料は `floor(amount × fee_percent / 100)` で概算し、正確な額は Stripe ダッシュボードを参照するよう注記する
- プラン管理画面: 口座未登録なら「口座を登録するまで支援を受け付けられません」と案内

### 7.6 Webhook

- `POST /stripe/webhook`（プラットフォームのイベント）と `POST /stripe/connect-webhook`（Connect アカウントのイベント）。それぞれ別の署名シークレットで検証する。CSRF 対象外
- 署名が不正なら 400。`stripe_events` に ID を挿入できなければ（重複）200 で即終了
- 処理はキュージョブ（`ProcessStripeEvent`）。失敗時はキューが再試行し、`processed_at` が null のまま残る
- 扱うイベント:

| イベント                                                | 処理                                                          |
| ------------------------------------------------------- | ------------------------------------------------------------- |
| `checkout.session.completed`                            | セッションのサブスクリプションを同期                          |
| `customer.subscription.created` / `updated` / `deleted` | 同期                                                          |
| `invoice.paid`                                          | サブスクリプションを同期し、`payments` に記録                 |
| `invoice.payment_failed`                                | 同期（`stripe_status` が `past_due` になる）                  |
| `account.updated`（Connect）                            | クリエイターの `payouts_enabled` / `details_submitted` を更新 |
| `account.application.deauthorized`（Connect）           | `payouts_enabled = false`                                     |

その他のイベントは記録だけして無視する。

## 8. 画面の変更

| 画面                                             | 変更                                                                                                                                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| クリエイターページのプラン                       | 未ログイン「ログインして支援する」/ 口座未登録「準備中」/ 支援中のプラン「支援中」/ 他のプラン「このプランに変更」（差額の説明つき確認）/ 受付停止                                    |
| `/supporting`                                    | 次回請求日、停止予約中なら「◯月◯日に終了」と「再開する」、`past_due` なら「お支払いに失敗しています」と「お支払い方法を更新」。上部に「お支払い方法・請求履歴」（カスタマーポータル） |
| `/creator/earnings`（新規）                      | 7.5 のとおり。サイドバーに「収益」                                                                                                                                                    |
| `/creator/plans`                                 | 口座未登録の案内                                                                                                                                                                      |
| `/dev/fake-checkout/{session}`（偽ドライバのみ） | 金額と「支払う」「キャンセル」                                                                                                                                                        |

価格表示は「税込」と明記する。

## 9. ルーティング（追加・変更）

| メソッド・URL                                          | 内容                                          |
| ------------------------------------------------------ | --------------------------------------------- |
| `POST /@{slug}/plans/{plan}/support`                   | Checkout を開始（変更）                       |
| `GET /supports/checkout/complete`                      | Checkout 完了の戻り先                         |
| `PUT /supports/{support}`                              | プラン変更                                    |
| `DELETE /supports/{support}`                           | 停止予約（変更）                              |
| `POST /supports/{support}/resume`                      | 再開                                          |
| `POST /billing/portal`                                 | カスタマーポータルへ                          |
| `GET /creator/earnings`                                | 収益画面                                      |
| `POST /creator/earnings/onboarding`                    | 口座登録・続行                                |
| `GET /creator/earnings/onboarding/return`              | 登録からの戻り先（状態を同期）                |
| `POST /creator/earnings/dashboard`                     | Stripe ダッシュボードへ                       |
| `POST /stripe/webhook`, `POST /stripe/connect-webhook` | Webhook                                       |
| `GET/POST /dev/fake-checkout/{session}`                | 偽ドライバの決済画面（`fake` のときだけ登録） |

支援操作は従来どおり 10 回/分/ユーザーのレート制限。

## 10. 設定

`.env`:

```
BILLING_DRIVER=fake            # stripe | fake
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_...
BILLING_PLATFORM_FEE_PERCENT=10
```

`config/fanbox.php` の `billing` に集約する。Stripe 側の設定（カスタマーポータル、未払い時のキャンセル、領収書メール、Webhook の宛先）は `docs/operations/stripe-setup.md` に手順として残す。

## 11. エラー処理

- Stripe API の失敗（通信・レート制限など）: ログに記録し、ユーザーには「決済サービスに接続できませんでした。時間をおいてお試しください」（503 ではなく画面上のトースト）
- Checkout 作成時の検証失敗: 422（トースト）
- Webhook の処理失敗: ジョブが再試行。最終的に失敗したものは `failed_jobs` に残る
- 金額・状態の正は常に Stripe。自前の DB は Stripe から取得した値でのみ更新する（画面の操作で直接 `active` にしない）

## 12. テスト方針

- `FakeGateway` を使い、HTTP 層から支援開始→完了→閲覧→停止予約→期間終了→閲覧不可までを通す
- 同期: Stripe の状態ごとの対応表、重複・順不同のイベント、metadata の不一致、二重決済時の即時キャンセル
- Webhook: 正しい署名で 200・不正な署名で 400・同じイベント ID の2回目は処理しない（`stripe-php` の署名検証を実際に通す）
- プラン変更: アップグレード成功・失敗（未適用で 422）、ダウングレード、受付停止プラン・停止予約中の拒否
- 口座: 未登録クリエイターへの支援拒否、`account.updated` で受付開始
- 売上: `invoice.paid` の記録と冪等性、月別集計
- DB 制約: 有効な支援の重複挿入が失敗する
- 本番で `fake` ドライバを選ぶと例外
- `StripeGateway` そのものは Stripe のテストモードでの手動確認とする（キーはユーザーが用意）

## 13. スコープ外

- 返金・チャージバック対応の画面（サブプロジェクト3の管理画面）
- 特商法表記・利用規約への同意（サブプロジェクト3。**本番で課金を始める前に必須**）
- 支払い関連のメール通知（Stripe の領収書メールを使う。独自通知はサブプロジェクト4）
- 単品販売、年額プラン、無料体験、クーポン
- インボイス制度対応の適格請求書（運営の登録状況が決まってから）
- プラン名変更の Stripe Product への反映、プラン削除時の Price のアーカイブ
