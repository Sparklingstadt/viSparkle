# viSparkle — ショップ（物のグッズ）設計書

- 作成日: 2026-11-02
- ステータス: 承認済み（2026-11-02、節ごとにユーザーが承認。書面の最終レビューはユーザーが実施）
- 前提: コア・決済・運営・いいね・フォロー（#32）・お支払い方法のサイドメニュー（#34）が main にマージ済み

## 1. 目的

viSparkle と candy-rain-stores を1つのサービスにする。そのために candy-rain-stores のストア機能を viSparkle（Laravel + Inertia/React）の中に作り直し、**クリエイターが支援プランと並べてグッズを売れる**ようにする。

統合は2段階で進める。この設計書は1段階目を扱う。

1. **ショップ（物のグッズ）** ← この設計書
2. デジタル作品の販売（1段階目の注文・決済の上に、ファイルとダウンロードの権限を足す。別の設計書にする）

2段階目が終わっても、candy-rain-stores のリポジトリはアーカイブしない。candy-rain-stores に引き継ぐデータはないので、データの移行は行わない。

### 決まっていること（ユーザーの回答）

- ストアを持てるのは viSparkle のクリエイターだけ。ストアはクリエイターページの一部にする
- 支払いは Stripe で決済する（candy-rain-stores の「サービス内では決済しない」「7日たっても支払確認されない注文を自動で取り消す」は引き継がない）
- ログインは viSparkle のまま（Fortify のメールとパスワード）。Candy Rain ID は使わない
- 方式は「viSparkle の中に作り直す」（Next.js へ移す案、2つのアプリを裏でつなぐ案は採らない）

## 2. 成功基準

1. 口座の登録が済んだクリエイター（`payouts_enabled`）が商品を登録して公開すると、`/@{creator}` の「ショップ」タブと商品ページに表示される
2. ログインしたファンが商品をカートに入れ、クリエイターごとに Stripe Checkout で支払うと、注文が「支払い済み」になる。売上から運営手数料（既定 10%）を引いた額がクリエイターの Connect 口座に送金される
3. 在庫は「支払いへ進む」を押した時点で確保される。決済されなければ（期限切れ・中断・支払い失敗）在庫が戻る。最後の1個を2人が同時に買おうとしても、注文は1件だけになる
4. 同じ Webhook が重複して届いても、順番が入れ替わって届いても、注文の状態と在庫は正しく保たれる（冪等）
5. クリエイターは注文を発送済みにでき、発送前なら取り消して全額返金できる（在庫は戻る）。運営は発送後の注文も含めて返金できる
6. 購入者は自分の注文だけを、クリエイターは自分の注文だけを見られる
7. Stripe のキーがない開発環境とテストでも、偽の決済ドライバで一連の流れを動かせる

## 3. 範囲外

デジタル作品（2段階目）、支援者限定の商品、クーポン、地域ごとの送料・商品ごとの送料、海外発送、購入者による取り消し、レビュー。

## 4. Stripe の構成

既存の支援（`2026-11-01-fanbox-payments-design.md`）と同じプラットフォームアカウントと Connect Express アカウントを使う。

- **課金方式**: Stripe Checkout（`mode=payment`、`locale=ja`）の **destination charge**
    - `payment_intent_data.transfer_data.destination` = クリエイターの Connect アカウント
    - `payment_intent_data.application_fee_amount` = `floor(合計 × platform_fee_percent / 100)`（合計は送料を含む。手数料率は `config('fanbox.billing.platform_fee_percent')`）
    - `on_behalf_of` は指定しない（販売者は運営）
- **金額**: 商品と送料は `line_items[].price_data`（JPY・税込）で渡す。商品ごとに Stripe の Product と Price は作らない
- **お届け先**: `shipping_address_collection.allowed_countries = ['JP']` と `phone_number_collection.enabled = true`。住所の入力フォームは自前で作らない
- **有効期限**: `expires_at` = 作成から 30 分
- **顧客**: 既存の `users.stripe_customer_id` を使う（なければ既存の `createCustomer` で作る）
- **支払い方法**: 支援と同じく Stripe ダッシュボードの設定で決める。あとで支払う方法（コンビニ払いなど）が有効な場合にも対応する（6.2）
- **冪等キー**: Checkout の作成は `order-{id}-checkout`、返金は `order-{id}-refund`
- **メタデータ**: Checkout セッションと PaymentIntent に `order_id` を入れる

## 5. データモデル

### creators（変更）

| カラム             | 型                      | 備考                                         |
| ------------------ | ----------------------- | -------------------------------------------- |
| shipping_fee       | unsigned int, default 0 | 送料（一律・円・税込）。0 なら送料無料       |
| shop_notice        | text, nullable          | ストアからのお知らせ（ショップのタブに表示） |
| shop_contact_email | string(255), nullable   | 購入者の注文詳細に表示する連絡先             |

### products（新規）

| カラム                    | 型                               | 備考                                         |
| ------------------------- | -------------------------------- | -------------------------------------------- |
| id                        | bigint                           |                                              |
| creator_id                | FK creators, cascade             |                                              |
| name                      | string(100)                      |                                              |
| description               | text, nullable                   | 最大 5000 文字                               |
| price                     | unsigned int                     | 円・税込。50〜1,000,000                      |
| stock                     | unsigned int                     | 0 なら売り切れ                               |
| is_published              | bool, default false              |                                              |
| sort_order                | unsigned int, default 0          |                                              |
| hidden_at / hidden_reason | datetime / string(500), nullable | 運営が非公開にしたとき（投稿と同じ）         |
| deleted_at                | datetime, nullable               | 論理削除（注文で使われていても消せるように） |
| timestamps                |                                  |                                              |

インデックス: `(creator_id, is_published, sort_order)`。

### product_images（新規）

| カラム     | 型                   | 備考                     |
| ---------- | -------------------- | ------------------------ |
| id         | bigint               |                          |
| product_id | FK products, cascade |                          |
| path       | string               | 既存の S3 互換ストレージ |
| sort_order | unsigned int         |                          |
| timestamps |                      |                          |

商品1件につき最大 10 枚。JPEG / PNG / WebP、1枚 10MB まで。

### cart_items（新規）

| カラム     | 型                   | 備考                         |
| ---------- | -------------------- | ---------------------------- |
| id         | bigint               |                              |
| user_id    | FK users, cascade    |                              |
| product_id | FK products, cascade |                              |
| quantity   | unsigned int         | 1 以上、その時点の在庫数まで |
| timestamps |                      |                              |

一意制約: `(user_id, product_id)`。

### orders（新規）

| カラム                                                                   | 型                       | 備考                                                                                |
| ------------------------------------------------------------------------ | ------------------------ | ----------------------------------------------------------------------------------- |
| id                                                                       | bigint                   |                                                                                     |
| user_id                                                                  | FK users, nullOnDelete   | 購入者                                                                              |
| creator_id                                                               | FK creators              |                                                                                     |
| status                                                                   | string(32)               | `OrderStatus` enum（6.1）                                                           |
| subtotal / shipping_fee / total                                          | unsigned int             | 円。注文を作った時点の値                                                            |
| fee_percent                                                              | unsigned int             | 注文を作った時点の手数料率                                                          |
| shipping_name / shipping_postal_code / shipping_address / shipping_phone | string, nullable         | 支払い完了時に Checkout から保存                                                    |
| stripe_checkout_session_id                                               | string, nullable, unique |                                                                                     |
| stripe_payment_intent_id                                                 | string, nullable, unique |                                                                                     |
| tracking_number                                                          | string(100), nullable    | 発送時に任意で入力                                                                  |
| paid_at / shipped_at / canceled_at / refunded_at / disputed_at           | datetime, nullable       |                                                                                     |
| refunded_amount                                                          | unsigned int, default 0  |                                                                                     |
| cancel_reason                                                            | string(32), nullable     | `expired` / `abandoned` / `payment_failed` / `checkout_error` / `creator` / `admin` |
| timestamps                                                               |                          |                                                                                     |

インデックス: `(user_id, created_at)`、`(creator_id, status, created_at)`、`(status, created_at)`（照合ジョブ用）。

### order_items（新規）

| カラム       | 型                        | 備考                           |
| ------------ | ------------------------- | ------------------------------ |
| id           | bigint                    |                                |
| order_id     | FK orders, cascade        |                                |
| product_id   | FK products, nullOnDelete | 商品は論理削除なので通常は残る |
| product_name | string(100)               | 購入時のコピー                 |
| unit_price   | unsigned int              | 購入時のコピー                 |
| quantity     | unsigned int              |                                |
| timestamps   |                           |                                |

### その他

- `ReportTarget` に `Product` を足す
- `config('fanbox.reserved_slugs')` に `cart`、`orders`、`shop` を足す（今のルートとは衝突しないが、ほかの予約語と揃えるため）

## 6. 購入と決済の流れ

### 6.1 注文の状態

```
pending（支払い待ち） ─(決済完了)→ paid（支払い済み） ─(発送)→ shipped（発送済み）
   │                                  │
   │(期限切れ・中断・支払い失敗)        └(発送前に取り消し)→ refunded（返金済み）
   └→ canceled（取り消し）
```

- 状態は前にしか進めない。`canceled` と `refunded` は終わりの状態。例外は「取り消したあとに支払い完了が届いた場合」の `canceled → refunded` だけ（6.2 ②）
- `shipped` の注文も運営は返金できる（`refunded` になる。在庫は戻さない）
- 遷移はすべて `OrderService`（仮称）を通し、注文の行をロックして行う

### 6.2 流れ

**① 購入手続きの開始**（`POST /cart/{creator}/checkout`）

1. トランザクションの中で、対象のカートの商品を `SELECT ... FOR UPDATE` でロックし、次を確かめる
    - クリエイターが活動中（`closed_at` が null、ユーザーが停止されていない）で `payouts_enabled`
    - 購入者が停止されていない
    - 商品が公開中（`is_published`、`hidden_at` と `deleted_at` が null）で、在庫が数量以上
2. 問題なければ在庫を減らし、`pending` の注文と注文の明細を作る（商品名と単価はコピー）
3. 1人が同時に持てる `pending` はクリエイターごとに1件まで。同じ購入者×同じクリエイターに `pending` の注文がすでにあるとき:
    - Checkout をまだ終えていない（PaymentIntent ID がない）なら、その Checkout を失効させて `canceled`（`abandoned`）にし、在庫を戻してから新しい注文を作る
    - あとで支払う方法の支払い待ち（PaymentIntent ID がある）なら、新しい購入手続きは始めず「お支払い待ちの注文があります」と表示して、その注文の詳細へ案内する
4. トランザクションを確定したあと、Checkout セッションを作り、ID を注文に保存して Stripe へリダイレクトする
5. Stripe の呼び出しが失敗したら、注文を `canceled`（`checkout_error`）にして在庫を戻し、カートに「決済の準備に失敗しました」と表示する

**② 支払い**（Webhook。既存の `stripe_events` と `ProcessStripeEvent` に足す。注文に関わるイベントは、メタデータの `order_id` があるかで支援と見分ける）

| イベント                                                | 処理                                                                                                  |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `checkout.session.completed`（`payment_status=paid`）   | `paid` にし、お届け先・PaymentIntent ID・`paid_at` を保存。カートからその商品を消す。確定メールを送る |
| `checkout.session.completed`（`payment_status=unpaid`） | `pending` のまま（在庫は確保したまま）。PaymentIntent ID とお届け先は保存する                         |
| `checkout.session.async_payment_succeeded`              | `paid` にする（上と同じ処理）                                                                         |
| `checkout.session.async_payment_failed`                 | `canceled`（`payment_failed`）にして在庫を戻す                                                        |
| `checkout.session.expired`                              | `canceled`（`expired`）にして在庫を戻す                                                               |
| `charge.refunded`                                       | 返金額を同期する。全額なら `refunded`                                                                 |
| `charge.dispute.created`                                | `disputed_at` を記録する（既存の支援と同じ扱い）                                                      |

- 支払い完了の戻り先（`/orders/checkout/complete?session_id=...`）では、既存の支援と同じく Webhook を待たずにその場で Checkout セッションを取得し、上の表と同じ処理で状態を揃えてから注文の詳細へリダイレクトする（`paid` なら「ご注文ありがとうございました」、あとで支払う方法なら「お支払いをお待ちしています」）
- Checkout から「戻る」で `cancel_url`（`/cart?canceled={order}`）に来たら、その注文の Checkout を失効させ、`canceled`（`abandoned`）にして在庫をすぐ戻す
- **照合ジョブ**: 毎時、作成から1時間以上たった `pending` の注文について Checkout セッションを取得し、上の表と同じ処理で状態を揃える（Webhook の取りこぼし対策。あとで支払う方法で支払い期限内のものは `pending` のまま）
- **取り消したあとに支払い完了が届いた場合**（ごくまれ）: 在庫が戻っている可能性があるため、自動で全額返金して `refunded` にし、エラーログ `shop.paid_after_cancel` を出す

**③ 発送**: クリエイターは `paid` の注文を `shipped` にできる（`tracking_number` は任意）。発送メールを送る。

**④ 取り消しと返金**

- 購入者は取り消せない
- クリエイターは `paid`（発送前）の注文を取り消せる。全額を返金し（`reverse_transfer=true`、`refund_application_fee=true`）、在庫を戻し、`refunded`（`creator`）にする
- 運営は管理画面から `paid` と `shipped` の注文を全額返金できる。`paid` なら在庫を戻す。`AdminAction` に記録する
- 返金の API が失敗したら状態は変えず、エラーを表示する（もう一度押せる。冪等キーで二重返金を防ぐ）

**⑤ メール**（キューで送る）: 注文の確定（購入者とクリエイター）、発送（購入者）、取り消しと返金（購入者）。

**⑥ 売上**: `/creator/earnings` に「ショップの売上」（期間ごとの件数・売上・返金・手数料を引いた額）を足す。支援の `payments` とは混ぜず、`orders` から集計する。

### 6.3 PaymentGateway に足すメソッド

- `createPaymentCheckoutSession(OrderCheckoutRequest $request): CheckoutSessionData`
- `refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): RefundData`（全額。送金と手数料も取り戻す）
- 既存の `retrieveCheckoutSession` と `expireCheckoutSession` は注文でも使う。`CheckoutSessionData` に `paymentStatus`、`paymentIntentId`、お届け先、`metadata` を足す

`StripeGateway` と `FakeGateway` の両方に実装する。`FakeGateway` は既存の `dev/fake-checkout/{session}` 画面で「支払う」「あとで支払う」「期限切れにする」を押せるようにし、それぞれ対応するイベントを流す。

## 7. 画面

既存のサイドメニューとクリエイターページに足す。

### 誰でも見られる

| URL                          | 内容                                                                                                                                                            |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/@{creator}`                | 「投稿」「ショップ」のタブを足す。ショップのタブには公開中の商品（画像・名前・価格・売り切れ）とストアからのお知らせ。公開中の商品が1件もなければタブを出さない |
| `/@{creator}/shop/{product}` | 商品の詳細。画像、説明、価格、送料、在庫、「カートに入れる」と数量。未ログインならログインへ案内。購入できない状態（6.2 ①の条件）なら「現在購入できません」     |

### 購入者（サイドメニューの「フィード」「支援中」の下に「カート」「注文履歴」）

| URL                          | 内容                                                                                                                                                                 |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/cart`                      | クリエイターごとにまとめて表示。数量の変更と削除。まとまりごとに小計・送料・合計と「この商品を購入」。売り切れ・非公開の商品には印を付け、そのまとまりは購入できない |
| `/cart/{creator}/confirm`    | 購入前の確認。商品・金額、お届けは国内のみであること、確定後は購入者から取り消せないこと、返品の案内（特定商取引法ページへのリンク）。「支払いへ進む」で Stripe へ   |
| `/orders/checkout/complete`  | 画面は持たない。状態を揃えて注文の詳細へリダイレクトし、トーストで知らせる（6.2 ②）                                                                                  |
| `/orders`、`/orders/{order}` | 注文履歴と詳細。状態、明細、お届け先、お問い合わせ番号、クリエイターの連絡先。他人の注文は 404                                                                       |

### クリエイター（サイドメニューの「クリエイター管理」に「商品管理」「注文管理」）

| URL                                          | 内容                                                                                                                                                                                 |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `/creator/products`                          | 商品の一覧・作成・編集・削除。名前、説明、価格、在庫、画像（並べ替え）、公開／非公開。口座の登録が済んでいなければ、公開できない理由と「収益」ページへのリンク                       |
| `/creator/shop`                              | ショップの設定。送料、ストアからのお知らせ、連絡先のメールアドレス                                                                                                                   |
| `/creator/orders`、`/creator/orders/{order}` | 注文の一覧（状態で絞り込み）と詳細。「発送済みにする」（お問い合わせ番号は任意）、発送前の「取り消して返金」（確認ダイアログ付き）。`pending` と `canceled` は既定では一覧に出さない |
| `/creator/earnings`                          | 「ショップの売上」を足す                                                                                                                                                             |

### 運営

- 管理画面の「支払い」にショップの注文を並べ、返金できるようにする
- 通報の対象に商品を足し、管理画面から非公開（`hidden_at`）にできるようにする（投稿と同じ）

### 特定商取引法のページ

`legal/tokushoho` にグッズの販売の項目を足す。下書き（最終的な文言は運営が決める）:

- **販売価格**: 各商品ページに表示する価格（税込）
- **商品代金以外の必要料金**: 送料（各クリエイターが定める一律の金額。商品ページと購入前の確認画面に表示）
- **引き渡し時期**: 支払いの確認後、各クリエイターが発送する（目安は商品ページやストアからのお知らせに記載）
- **返品・交換**: お客様都合による返品・交換は受け付けない。不良品・誤配送の場合は、到着後 7 日以内にクリエイターの連絡先または運営に連絡する

## 8. エラー時の扱い

| 状況                                               | 扱い                                                                                                     |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| クリエイターが活動を終了した（`closed_at`）        | ショップのタブと商品ページは非公開。新しい購入はできない。`paid` の注文は発送・取り消しができる          |
| クリエイターの口座が使えなくなった                 | 商品は表示するが「現在購入できません」。購入手続きは始められない                                         |
| 運営がユーザーを停止した                           | 購入者なら購入できない。クリエイターなら活動終了と同じ扱い                                               |
| 手続きの途中で商品が非公開になった・価格が変わった | Checkout は注文に保存した金額のまま進む。確保した在庫と注文はそのまま                                    |
| 商品の削除                                         | 論理削除（`deleted_at`）。注文の明細はコピーなので表示は変わらない                                       |
| Webhook の重複・順番の入れ替わり                   | 既存の `stripe_events` で重複を弾き、状態は前にしか進めない                                              |
| 在庫が足りない                                     | 購入手続きの開始時にロックして確かめ、足りなければカートに戻して「在庫が足りません」                     |
| 商品画像のアップロード失敗                         | 既存の投稿ファイルと同じ扱い（孤立したファイルは既存の `PruneOrphanFiles` で消す。対象に商品画像を足す） |

**連打と乱用への対策**: カートの操作は `throttle:60,1`、購入手続きの開始は `throttle:10,1`、完了画面は既存の支援と同じく専用の枠。

## 9. テスト

既存と同じく Pest と、本番と同じ MySQL（`testing` データベース）を使う。

- **商品**: 作成・編集・削除、公開できる条件（`payouts_enabled`）、画像の枚数と並べ替え、他人の商品を編集できないこと
- **カート**: 追加、数量の上限（在庫まで）、売り切れ・非公開の商品の扱い
- **購入手続きの開始**: 在庫の確保、在庫が足りないとき、口座がないとき・活動終了・停止のとき、同じクリエイターの `pending` が置き換わること、Stripe の失敗時に在庫が戻ること
- **同時購入**: 最後の1個を2つの接続から同時に買おうとしたとき、注文が1件だけになること
- **Webhook**: 6.2 ②の表の各イベントによる状態の変化、重複と順番の入れ替わり、取り消し後の支払い完了で自動返金されること
- **照合ジョブ**: 取りこぼした `paid` と `expired` を揃えること
- **発送・取り消し・返金**: クリエイターの取り消しで在庫が戻ること、発送後はクリエイターが取り消せないこと、運営の返金、返金 API の失敗時に状態が変わらないこと
- **権限**: 他人の注文が見えないこと（Policy）
- **StripeGateway**: Stripe に送るパラメータ（`transfer_data`、`application_fee_amount`、`shipping_address_collection`、`expires_at`、冪等キー、メタデータ）
- **メール**: 各状態の変化で送られること
- `types:check` と `pint` を通す

## 10. 運用

- `docs/operations/stripe-setup.md` に、プラットフォームの Webhook に足すイベント（`checkout.session.expired`、`checkout.session.async_payment_succeeded`、`checkout.session.async_payment_failed`、`charge.refunded`）を書き足す
- 照合ジョブをスケジューラ（`routes/console.php`）に毎時で登録する
- デモデータ（`--seed`）に、デモクリエイターの商品を足す（§11 の `DemoProductsSeeder`）

## 11. 追記：商品の種類（SKU）

1段階目のあとに、サイズやデザインごとに在庫を分けられるようにした。

- `product_variants`（`product_id`、`name`（50字まで、空可）、`stock`、`sort_order`、論理削除）を足し、在庫を商品から種類へ移した。商品は必ず1つ以上の種類を持つ。種類のない商品は名前が空の種類を1つ持つので、在庫の処理は1本のまま
- 価格は商品ごと（種類で変えない）
- カートの行は種類を指す（一意キーは利用者と種類）。注文明細は種類の ID と「商品名（種類）」の名前を写す（`product_name` を160字に広げた）。在庫の確保と戻しは種類の在庫で行う
- 商品の在庫（`Product::$stock`）は、一覧の売り切れ表示などのための種類の在庫の合計
- 編集画面では種類を足し・消し・並べられる。2つ以上のときはそれぞれに重ならない名前が要る。在庫は種類ごとに「開いたときの在庫」との差分で反映する。フォームから消えた種類は論理削除し、それを含むカートの行は「現在購入できません」と出す
- 商品ページでは種類が2つ以上のときだけ種類を選ぶ。最初は在庫のある最初の種類を選んでおく
- デモデータ：`DemoProductsSeeder` が全デモクリエイターに、アクリルスタンドA・B、ランダムチェキ（全3〜6種、種類は1つ）、缶バッジ（デザイン2〜5種）を出品させる。アクリルチャーム・パスケース・タペストリー・Tシャツ（S/M/L/フリー）はそれぞれ50%で出る。乱数はスラッグから決まるので、何度流しても同じになる。常設ボイス（デジタル）はデジタル作品の販売を作るまで入れない
