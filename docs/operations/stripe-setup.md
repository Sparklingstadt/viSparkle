# Stripe の設定手順

viSparkle の課金は Stripe（プラットフォーム＋Connect Express）で動く。本番で課金を始める前に、この手順で Stripe 側とアプリ側を設定する。設計の根拠は `docs/superpowers/specs/2026-11-01-fanbox-payments-design.md` を参照。

> **本番で実際に課金を始める前に必須**: 特定商取引法に基づく表記と利用規約・プライバシーポリシーへの同意は実装済み。残っているのは、運営者情報（`LEGAL_*`）の入力と、規約類の専門家による確認（§7 のチェックリスト）。

## 1. 前提

- 構成は、運営が持つ Stripe のプラットフォームアカウント（日本）と、クリエイターごとの Connect Express アカウント（日本）。
- 課金は、プラットフォーム上のサブスクリプションによる destination charge。送金先はクリエイターの Connect アカウントで、運営手数料は `application_fee_percent` で差し引く。
- 通貨は JPY、価格は税込（`tax_behavior=inclusive`）。決済手段はアプリでは指定せず、Stripe ダッシュボードの設定で決める（§2.6）。
- 金額と状態の正は常に Stripe。アプリの DB は Stripe から取得した値でのみ更新する。

## 2. Stripe アカウント側の設定

最初はテストモード（サンドボックス）で一通り設定し、動作を確認してから同じ設定を本番モードで繰り返す。キー・Webhook・ポータル設定はモードごとに別なので、本番モードでもやり直すこと。

### 2.1 Connect の有効化とプラットフォームのプロフィール

1. ダッシュボードで **Connect** を有効化する。
2. アカウントタイプは **Express**、クリエイターの国は **日本（JP）** とする。アプリはクリエイターごとに次の設定で Express アカウントを作る。
    - Accounts v2（`POST /v2/core/accounts`）で作成する。Stripe は新しい Connect 連携での v1 アカウント作成を受け付けない
    - `dashboard=express` / `identity.country=jp`
    - 設定は受取人（`configuration.recipient`）で、要求する機能は `stripe_balance.stripe_transfers` のみ
    - 手数料・損失の責任は運営（`fees_collector` / `losses_collector` とも `application`）。Connect の設定画面の「返金とチャージバックの責任：運営」と一致させる
    - 口座状態の取得と Express ダッシュボードへのリンクは v1 API（`/v1/accounts/{id}`、ログインリンク）のまま使える
3. Connect の **プラットフォームのプロフィール**（プラットフォームの責任、損失の負担などの確認事項と、事業者情報・ブランディング）を入力する。未入力だと Express アカウントの作成やオンボーディングが進まないことがある。
4. Express のオンボーディング画面やダッシュボードに表示されるブランド（アイコン、ロゴ、色、サービス名）を設定する。

### 2.2 API キー

1. **開発者 → API キー** のシークレットキー（`sk_live_...`、テストモードなら `sk_test_...`）を控える。
2. 本番環境の `.env` に設定する（§3）。

制限付きキー（Restricted key）を使う場合は、Checkout・サブスクリプション・請求書・顧客・商品/価格・カスタマーポータル・Connect（アカウント、アカウントリンク）・返金の書き込み権限と、チャージ・請求書の支払い（Invoice Payments）の読み取り権限が要る。迷うなら標準のシークレットキーを使う。キーはリポジトリやチャットに貼らない。

### 2.3 Webhook の宛先（2つ）

**開発者 → Webhook** で宛先を2つ作る。宛先ごとに署名シークレット（`whsec_...`）が別になる。

| 項目                     | プラットフォーム                                                                                                                                                                                                                                                                                                                                            | Connect                                                                  |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| URL                      | `https://（本番のドメイン）/stripe/webhook`                                                                                                                                                                                                                                                                                                                 | `https://（本番のドメイン）/stripe/connect-webhook`                      |
| イベントの受信元         | 「自分のアカウント」                                                                                                                                                                                                                                                                                                                                        | 「**接続されたアカウント上のイベント**（Events on Connected accounts）」 |
| 購読するイベント         | `checkout.session.completed`<br>`customer.subscription.created`<br>`customer.subscription.updated`<br>`customer.subscription.deleted`<br>`invoice.paid`<br>`invoice.payment_failed`<br>`charge.refunded`<br>`charge.dispute.created`<br>`checkout.session.expired`<br>`checkout.session.async_payment_succeeded`<br>`checkout.session.async_payment_failed` | `account.updated`<br>`account.application.deauthorized`                  |
| 署名シークレットの設定先 | `STRIPE_WEBHOOK_SECRET`                                                                                                                                                                                                                                                                                                                                     | `STRIPE_CONNECT_WEBHOOK_SECRET`                                          |

注意:

- `charge.refunded` と `charge.dispute.created` は**プラットフォームの宛先**に購読する（destination charge のチャージはプラットフォーム上にある）。返金は管理画面からでも Stripe ダッシュボードからでも、このイベントで `payments.refunded_amount` に反映される。
- **Connect の宛先に、サブスクリプション・請求書系のイベントを購読しないこと。** それらは `connect-webhook` で受けても処理に失敗する（アプリはプラットフォームのキーで取得し直すため、接続アカウント側のオブジェクトは取得できない）。失敗したジョブは再試行のあと `failed_jobs` に溜まり続ける。Connect の宛先は上の2イベントだけにする。
- 逆に、プラットフォームの宛先に `account.*` は不要。
- 上記以外のイベントを受信しても、`stripe_events` に記録するだけで無視される（害はないが、不要なイベントは購読しない）。
- 2つのエンドポイントは CSRF の対象外で、認証は Stripe の署名だけ。署名シークレットが未設定のときは 500 を返し、署名が不正なときは 400 を返す。

### 2.4 失敗した支払いの扱い（Billing → サブスクリプション）

**設定 → Billing → サブスクリプションとメール**（Subscriptions and emails）の「支払い失敗の管理（Manage failed payments）」で、次のように設定する。

- 再試行（Smart Retries など）は有効のままでよい。
- **再試行がすべて失敗したら、サブスクリプションを「キャンセル」にする**（「未払いのままにする」や「一時停止」ではなくキャンセル）。

アプリの前提は次のとおり。

- 再試行中（`past_due`）は猶予期間として、支援者は閲覧を続けられる。
- 再試行が尽きて Stripe がサブスクリプションをキャンセルすると、`customer.subscription.deleted` が届き、支援が終了する。
- この設定が違うと、支払いが滞ったままの支援が永久に閲覧可能なままになる。必ず確認すること。

### 2.5 カスタマーポータル

**設定 → Billing → カスタマーポータル** を次のとおりにして、保存（本番モードでも別に保存）する。

| 項目                                                 | 設定     |
| ---------------------------------------------------- | -------- |
| 支払い方法の更新（Payment method update）            | **有効** |
| 請求履歴の表示（Invoice history）                    | **有効** |
| サブスクリプションのキャンセル                       | **無効** |
| サブスクリプションの更新（プラン切り替え・数量変更） | **無効** |

キャンセルとプラン変更は、アプリ内の操作（停止・再開、プラン変更）で行う。そちらは日割りの扱いや確認ダイアログをアプリが制御している。万一ポータルで解約・変更されても Webhook で同期されるので、整合は崩れないが、運用としては無効にする。

### 2.6 支払い方法（Checkout で使えるもの）

Stripe は、Checkout Session の作成時に `payment_method_types` を指定する方式を受け付けなくなった。Checkout に出る支払い方法は、**設定 → 支払い方法（Payment methods）** で決める（モードごとに別の設定）。

- **カード**は必ず有効にする。
- 月額の支援（サブスクリプション）で毎月自動で引き落とせない方法（コンビニ払い、銀行振込など）は**無効にする**。有効のままでも、Checkout はサブスクリプションに使えない方法を自動で表示しないが、明示的に外しておく。
- Link（Stripe のワンクリック決済）は、有効でも問題ない。

### 2.7 領収書メール

**設定 → 顧客向けメール（Customer emails）** で、**支払い成功時のメール（領収書）** を有効にする。アプリは支払い関連のメールを送らず、Stripe の領収書メールを使う設計になっている（独自通知はサブプロジェクト4）。

### 2.8 返金とチャージバック（異議申し立て）

**返金**は管理画面の「支払い」（`/admin/payments`）から行う。

- 「返金」は、その支払いの請求書（invoice）の支払いを**全額**返金する。`reverse_transfer=true` でクリエイターへの送金も取り消し、`refund_application_fee=true` で運営手数料も返す。部分返金はしない。
- ダイアログの「支援も解約する」を選ぶと、支援（サブスクリプション）も即時に解約する。選ばなければ支援は続く。
- 返金済みの支払いは再度返金できない（422）。返金の失敗はトーストで表示し、ログ（`billing.ops_failed`）と `admin_actions`（`payment.refund_failed`）に残る。
- Stripe ダッシュボードから返金した場合も、`charge.refunded` で返金額が取り込まれる。クリエイターの収益画面は返金後の額（`amount - refunded_amount`）で集計する。
- 返金の API 呼び出しには請求書ごとの冪等性キーを付けているため、失敗した返金を同じ請求書で再実行すると、Stripe が24時間は最初の結果をそのまま返す。失敗が続くときは、24時間たってからやり直すか、Stripe ダッシュボードから返金する（`charge.refunded` で取り込まれる）。
- ダッシュボードから返金するときは、**「送金を取り消す（Reverse transfer）」と「手数料を返金する（Refund application fee）」を必ず選ぶ**。選ばないと運営が返金額を負担することになる。

**チャージバック**（カード会社への異議申し立て）は次のとおり扱う。

- `charge.dispute.created` を受けると、該当の支払いに `disputed_at` が記録され、管理画面の支払い一覧に「異議申し立て」のバッジが出る。
- 対応（証拠の提出、受け入れ）は **Stripe ダッシュボードの「支払い → 不審請求の申し立て（Disputes）」** で行う。期限（`evidence_details.due_by`）までに、支援の内容（プラン、閲覧できた投稿）、支払い日時、利用規約への同意日時（`users.terms_accepted_at`）、ログイン履歴などを提出する。
- 損失の責任は運営（§2.1）なので、敗訴した金額と申し立て手数料は運営の残高から引かれる。必要ならクリエイターと別途調整する。
- 申し立てが続く支援者・クリエイターは、管理画面で利用停止を検討する。

## 3. アプリ側の設定（`.env`）

```
BILLING_DRIVER=stripe
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...            # プラットフォームの宛先のシークレット
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_...    # Connect の宛先のシークレット
BILLING_PLATFORM_FEE_PERCENT=10            # 運営手数料（%）。省略すると 10
```

- `BILLING_DRIVER` を省略すると、`APP_ENV=production` なら `stripe`、それ以外は `fake` になる。本番でも明示的に `stripe` を設定しておくこと（`fake` を指定すると起動時に例外）。ビルド時に環境変数が渡らないホスティングでも、`APP_ENV=production` さえあればビルドが止まらない。
- `BILLING_DRIVER=stripe` で動かすには `STRIPE_SECRET` が必須。空だと、決済サービスを使う最初の処理で `Stripe secret is not configured.` という例外になる。
- `fake` ドライバは**本番環境（`APP_ENV=production`）では動かない**（`The fake billing driver cannot be used in production.` の例外）。ローカル確認・テスト専用。
- Webhook のシークレットが片方でも空だと、その宛先は常に 500 を返す。Stripe 側で再送が続くので、設定漏れに注意する。
- `.env` を書き換えたら、設定キャッシュを使っている環境では `php artisan config:cache` をやり直し、キューワーカーも再起動する。
- `STRIPE_SECRET` と Webhook シークレットは秘密情報。リポジトリに入れない。

## 4. キューワーカー

Webhook の受信は署名の検証とイベント ID の記録だけで、実際の処理は `ProcessStripeEvent` ジョブ（キュー）が行う。**キューワーカーが動いていないと、支援の開始・更新・売上の記録が一切反映されない。**

```
php artisan queue:work          # ローカル（Sail）なら ./vendor/bin/sail artisan queue:work
```

- 本番（Laravel Cloud）では Managed queue がワーカーを動かす。設定は `docs/operations/laravel-cloud.md` §2.3 を参照。
- ローカルや、Laravel Cloud 以外で `QUEUE_CONNECTION=database` を使う場合は、ワーカーを常駐させる（Supervisor / systemd など）。コードを更新したらワーカーを再起動する。
- ジョブは最大5回まで再試行する（待ち時間は 10 秒、1 分、5 分、15 分）。処理が成功した時点でだけ `stripe_events.processed_at` が埋まる。

### Webhook の再送と失敗したジョブ

- Stripe は Webhook に 2xx が返らないと再送する。アプリは同じイベント ID の再送を受けたとき、**すでに処理済み（`processed_at` あり）なら何もせず 200 を返し、処理が完了していなければジョブを再投入する**。たとえばキューへの投入に失敗した場合でも、Stripe の再送で処理がやり直される。各処理は Stripe の現在の状態を取り直す方式なので、複数回走っても害はない。
- 再試行を使い切って失敗したジョブは、原因（Stripe の障害、設定ミスなど）を直したあとで再実行する。
    - **本番（Managed queue）**: Laravel Cloud の **Monitoring → Queues** で失敗したジョブを確認し、そこから再実行する。`queue:failed` / `queue:retry` は使えない。
    - **ローカル（`database` キュー）**: `failed_jobs` テーブルに残るので、次のコマンドで再実行する。

```
php artisan queue:failed                # 失敗したジョブの一覧
php artisan queue:retry all             # すべて再実行（個別なら ID を指定）
```

- 失敗したジョブが溜まっていないか、定期的に確認する。特に `invoice.paid` が失敗したままだと売上（`payments`）が記録されない。

## 5. ローカルでの動作確認

### 5.1 fake ドライバ（Stripe 不要）

`BILLING_DRIVER=fake`（`.env.example` の既定）なら、Stripe の代わりに擬似の決済画面（`/dev/fake-checkout/...`）で支援の開始から停止まで試せる。ローカル専用。

### 5.2 Stripe CLI とテストモード

Stripe のテストモードのキーを使い、Stripe CLI で Webhook をローカルに転送する。

```
stripe listen \
  --forward-to localhost:8080/stripe/webhook \
  --forward-connect-to localhost:8080/stripe/connect-webhook
```

- Sail の既定ポートは 80（`compose.yaml` の `${APP_PORT:-80}`）。上のコマンドの 8080 は `.env` で `APP_PORT=8080` にしている前提なので、`APP_PORT` の値に合わせて変える（未設定なら `localhost/stripe/webhook` のようにポートを省く）。
- 起動時に表示される `whsec_...` を `STRIPE_WEBHOOK_SECRET` と `STRIPE_CONNECT_WEBHOOK_SECRET` の**両方**に設定する。1つの `stripe listen` は署名シークレットが1つなので、2つの宛先に同じ値を使う。`.env` を変えたあと、反映されないときは `./vendor/bin/sail artisan config:clear` を実行し、キューワーカーも再起動する。
- `.env` は `BILLING_DRIVER=stripe` と `STRIPE_SECRET=sk_test_...` にする。
- 別のターミナルで `./vendor/bin/sail artisan queue:work` を動かす（動かさないと何も反映されない）。
- テストカード: 成功 `4242 4242 4242 4242`、決済拒否 `4000 0000 0000 0002`、登録はできるが請求が拒否される `4000 0000 0000 0341`、3D セキュア必須 `4000 0025 0000 3155`（有効期限は未来の任意の日付、CVC は任意の3桁）。

## 6. 本番前に Stripe テストモードで確認すること

`StripeGateway` はテストでは実物の Stripe を叩かない（偽ドライバで検証している）。そのため、本番前に**テストモードで次を手動で確認する**。ここで想定外の挙動があれば、本番を始めずに修正する。

1. **プラン変更（アップグレード）で決済が拒否されるカードを使う。** 成功するカード（`4242 4242 4242 4242`）で支援を始め、カスタマーポータルで「登録はできるが請求が拒否される」カード（`4000 0000 0000 0341`）に差し替えてから、上位プランへ変更する。画面に「お支払いに失敗しました。カード情報をご確認ください」と表示され、**プランが変わっていない**（支援のプランも Stripe のサブスクリプションの価格も元のまま）ことを確認する。
2. **失敗したアップグレードの日割り請求書の行方を確認する。** アップグレードは `proration_behavior=always_invoice` と `payment_behavior=pending_if_incomplete` で差額を即時請求する。支払いに失敗したあと、Stripe に未払いの日割り請求書（open の invoice）が残るかを確認する。**その請求書が後から再試行や手動で支払われ、プランが変わらないまま（または意図しないタイミングで）請求だけ確定してしまわないか**を見る。そうなる場合は、その請求書を無効化（void）する運用（ダッシュボードで手動、または自動化）を決めてから本番を始める。
3. **JP の受取人アカウント（Accounts v2、`stripe_transfers` のみ）で、オンボーディングが完了し、送金を受け取れること。** クリエイターで「Stripe で口座を登録する」からテストモードのオンボーディングを最後まで進め、`account.updated` が届いて収益画面に「口座は登録済みです。」と表示されること、その後の支援の売上が接続アカウントに送金（transfer）されることを、ダッシュボードで確認する。`transfers` だけで（`card_payments` なしに）日本のアカウントが完了できるかが要点。
4. **支援開始から売上までの一連。** 支援者として Checkout を完了する → `checkout.session.completed` と `invoice.paid` が届き、キューが処理する → 支援が `active` になり投稿が読める → `payments` に行が1件でき（金額・支払日時が請求書と一致）、クリエイターの収益画面に今月の売上と受取額の目安が出る。同じイベントを再送（ダッシュボードの「再送」）しても `payments` が二重にならないこと。
5. **期間末の停止から終了まで。** 支援を「停止」（期間終了時にキャンセル）すると終了日が表示され、期間内は閲覧できる。テストクロックなどで期間末まで進めると `customer.subscription.deleted` が届き、支援が `ended` になって閲覧できなくなる。

あわせて、次も確認するとよい。

- 2.4 の「再試行がすべて失敗したらキャンセル」を、テストクロックで支払い失敗を進めて確認する（`past_due` の間は閲覧可、最終的にキャンセル→終了）。
- Webhook の署名シークレットを故意に間違えると 400 になる。
- 2つの宛先の購読イベントが、§2.3 の表どおりになっている。
- **クリエイターの Connect アカウントが制限（restricted）になったときの更新時の挙動。** テストモードでクリエイターの接続アカウントに追加の確認書類を要求される状態などを作り、支援の更新（期間末の請求）がどうなるかを確認する。送金先が受け取れない状態だと更新の決済が失敗し、支援者には「お支払いに失敗しています」と表示される可能性がある。そうなる場合の運用（クリエイターへの連絡、支援者への案内）を決めておく。
- **アカウント削除と支援。** 支援者が退会すると、その人の有料の支援は**即時に解約**される（返金はしない）。解約の処理に失敗したときは「解約の処理に失敗したため、アカウントを削除できませんでした。…」と表示され、削除されない。クリエイターは、有効な支援者が1人でもいるあいだは「有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。」と表示されて削除できない。「活動を終了する」で支援を期間の終わりで停止し、すべて終わってから削除する。テストモードでこの両方を確認する。

## 7. 本番へ切り替えるときのチェックリスト

- [ ] 本番モードで §2 をすべてやり直した（Connect、Webhook 2件、失敗時のキャンセル、ポータル、領収書メール）
- [ ] `.env` が `BILLING_DRIVER=stripe` と本番のキー・シークレット3つを持っている
- [ ] キューワーカーが動いている（Laravel Cloud では Managed queue `default` が Monitoring → Queues に出ている）
- [ ] §6 の確認がテストモードですべて通った
- [ ] 特商法の事業者情報（`LEGAL_*`。`docs/operations/laravel-cloud.md` §4）を設定し、`/legal/tokushoho` に「準備中」が出ない（個人で住所・電話を伏せるときは `LEGAL_DISCLOSE_ON_REQUEST=true`）。**本番キー（`sk_live_` / `rk_live_`）のまま未設定だと、支援の申し込みは断られる**（`legal.not_ready`）
- [ ] 利用規約・プライバシーポリシー（`resources/legal/terms.md` / `privacy.md`）を専門家に確認してもらい、直したうえで直した場合は `version:` を上げた。**利用規約（`terms.md`）の `version:` を上げると、全会員に再同意を求める**（同意画面が出る）。プライバシーポリシーの `version:` は表示用で、再同意は求めない。確認前のひな形のまま公開しない
- [ ] 管理者を作った（`php artisan visparkle:admin you@example.com`。`laravel-cloud.md` §6）
- [ ] 失敗したジョブの確認・再実行の方法（本番は Monitoring → Queues）を運用担当が把握している
- [ ] **テストキーから本番キーに切り替えるとき、DB を作り直したか、テストモードで保存された Stripe の ID を消した。** テストモードの ID は本番モードでは無効なので、そのまま残すと顧客・価格・接続アカウントの取得に失敗する。作り直さない場合は、`users.stripe_customer_id`、`plans.stripe_product_id` / `plans.stripe_price_id`、`creators.stripe_account_id` / `creators.payouts_enabled` / `creators.details_submitted` をクリアする（クリエイターは口座登録をやり直す）。デモクリエイターの口座だけなら `php artisan visparkle:demo-payout-accounts --detach` で外せる（`laravel-cloud.md` §6.3）
- [ ] **既存の DB にマイグレーションを流す前に、同じ支援者・クリエイターの組（`supports` の `user_id` と `creator_id`）で `active` の支援が重複していないことを確認した。** 重複があると一意インデックスの作成に失敗する。たとえば次の SQL が0件であること:

    ```sql
    SELECT user_id, creator_id, COUNT(*) FROM supports
    WHERE status = 'active' GROUP BY user_id, creator_id HAVING COUNT(*) > 1;
    ```

- [ ] 支援者の退会では有料の支援が即時に解約され（返金なし）、クリエイターは有効な支援者がいるあいだ削除できず「活動を終了する」を使うことを、運用担当が把握している

## 8. 範囲外（この手順書では扱わない）

- 部分返金（全額返金のみ。部分的に返す必要があれば Stripe ダッシュボードで行い、`charge.refunded` で取り込む）
- チャージバックの証拠提出の自動化（Stripe ダッシュボードで行う）
- 適格請求書（インボイス制度）の対応（運営の登録状況が決まってから）
- 独自の支払い通知メール（サブプロジェクト4）
