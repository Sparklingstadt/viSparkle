# Laravel Cloud へのデプロイ手順

viSparkle は [Laravel Cloud](https://cloud.laravel.com) で動かす。Cloud の上に、アプリ（Web）、Laravel MySQL、オブジェクトストレージ（Cloudflare R2）のバケット2つ、キューワーカー、スケジューラを置く。Stripe 側の設定は `docs/operations/stripe-setup.md` を参照。

> **一般公開の前に必須**: 特定商取引法に基づく表記（`LEGAL_*`、§4）の入力と、利用規約・プライバシーポリシーの専門家による確認。実装は済んでいる。チェックリストは `stripe-setup.md` §7。

## 1. アプリと環境を作る

1. Cloud で **New application** を選び、GitHub の `Sparklingstadt/viSparkle` を接続する。
2. リージョンは **Tokyo（ap-northeast-1）** を選ぶ。利用者が日本にいる前提のため。
3. 最初の環境（例: `production`）に、デプロイするブランチを設定する。
4. **General settings → PHP version** で **8.4** を選ぶ。CI と同じバージョンにそろえる（`composer.json` の要求は `^8.3`）。

## 2. リソースを追加する（インフラのキャンバス）

### 2.1 データベース

**Add database → Laravel MySQL** を追加する。開発とテストが MySQL なので Postgres は使わない。接続情報（`DB_*`）は Cloud が環境変数として注入するため、自分で設定しない。

### 2.2 オブジェクトストレージ（バケット2つ）

アプリはディスク名 `private` と `public` を使う（`config/filesystems.php`）。**Add bucket → Laravel Object Storage** で、次の2つを作って接続する。

| ディスク名（Disk name） | 公開範囲（Visibility） | 既定のディスク | 中身                                    |
| ----------------------- | ---------------------- | -------------- | --------------------------------------- |
| `private`               | Private                | **はい**       | 投稿の画像・添付（署名付き URL で配信） |
| `public`                | Public                 | いいえ         | アイコン、ヘッダー、プランのカバー      |

- ロケーションヒントは **Asia-Pacific** にする（あとから変えられない）。
- バケットを接続すると、Cloud はそのディスク名の設定（キー、エンドポイント、公開 URL）を丸ごと差し替える。そのため、`AWS_*` や `AWS_PUBLIC_URL`、`AWS_SIGNING_ENDPOINT` は**設定しない**。
- Cloud の設定は `throw => false` になるが、`AppServiceProvider::configureStorageFailures()` で `true` に戻している。これで、保存に失敗したときは黙って続けずに例外になる。
- R2 はファイル単位の公開設定（ACL）に対応していない。公開範囲はバケット単位で決まる。
- `visparkle:init-storage` はローカル専用（本番では失敗する）。Cloud では実行しない。

### 2.3 キューワーカー

Stripe の Webhook は `ProcessStripeEvent` ジョブとしてキューで処理する。ワーカーが動いていないと、支払いが反映されない。

**Managed queue** を使う（Laravel Cloud の推奨。ワーカーの起動・台数・再起動を Cloud が管理する）。

1. キャンバスのツールバーで **Add compute → Managed queue** を選ぶ。
2. Queue name は `default`、Queue type は **Standard**、Compute class は **Flex**、Memory は **256 MiB**、Workers は 0〜3 程度にする。
3. 環境変数に `QUEUE_CONNECTION` を自分で設定しない（設定していたら消す）。Managed queue をデプロイすると、Cloud が `QUEUE_CONNECTION=cloud` を設定する。
4. デプロイする。**Monitoring → Queues** に `default` が出れば動いている。

- Flex のワーカーは、ジョブが届くと自動で起きる。App cluster の Scale to Zero と併用してよい（App cluster の中で `queue:work` を動かす方式だと、環境が眠っているあいだジョブが止まる）。
- Flex のジョブは90秒以内に終わる必要がある。`ProcessStripeEvent` は Stripe の取得と DB 更新だけなので、十分に収まる。
- 失敗したジョブは `failed_jobs` テーブルではなく **Monitoring → Queues** に表示され、そこから確認・再実行・削除する。`php artisan queue:failed` / `queue:retry` は Managed queue では使えない。
- `queue:restart` をデプロイコマンドに入れる必要はない。

### 2.4 スケジューラ

App cluster の設定で **Scheduler** を有効にする。`visparkle:prune-orphan-files`（毎日）が動くようになる（`routes/console.php`）。

## 3. ビルドとデプロイのコマンド

**Settings → Deployments** で次のとおり設定する。

ビルドコマンド:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

- `npm run build` の途中で Wayfinder が `php artisan` を呼ぶ。そのため、ビルド時点で環境変数（特に `BILLING_DRIVER`）がそろっている必要がある。
- Inertia SSR は使っていない（`npm run build:ssr` は不要）。

デプロイコマンド:

```bash
php artisan migrate --force
```

- `--seed` は付けない。デモデータ（パスワード `password` のアカウント）が本番に入ってしまう。
- `storage:link`、`optimize:clear`、`queue:restart` は入れない（Cloud では不要か、害がある）。

## 4. 環境変数

**Settings → Environment variables** に設定する。DB とバケットの値は Cloud が注入するので書かない。シークレットはリポジトリやチャットに貼らない。

```ini
APP_NAME=viSparkle
APP_ENV=production
APP_DEBUG=false
APP_URL=https://（Cloud のドメイン、または独自ドメイン）

APP_LOCALE=ja
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=ja_JP

# 複数のレプリカやデプロイのあいだでメンテナンスモードの状態を共有する
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database

SESSION_DRIVER=database
CACHE_STORE=database
# QUEUE_CONNECTION は設定しない（Managed queue をデプロイすると Cloud が cloud に設定する）

BILLING_DRIVER=stripe
BILLING_PLATFORM_FEE_PERCENT=10
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_...

# 特定商取引法に基づく表記（/legal/tokushoho に表示される運営者情報）
LEGAL_SELLER_NAME=（販売事業者の名称。個人なら本名または屋号）
LEGAL_RESPONSIBLE_PERSON=（運営責任者の氏名）
LEGAL_ADDRESS=（所在地）
LEGAL_PHONE=（電話番号）
LEGAL_EMAIL=（問い合わせ先のメールアドレス）
LEGAL_DISCLOSE_ON_REQUEST=false

# メール送信（Resend。§8）
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=no-reply@visparkle.sparklingstadt.com
MAIL_FROM_NAME="${APP_NAME}"
```

- `APP_KEY` は Cloud が生成する。生成されていなければ、ローカルで `php artisan key:generate --show` を実行し、出力を設定する。
- `BILLING_DRIVER=fake` は `APP_ENV=production` では例外になって使えない。Stripe のテストキーで動かすのが正しい形。
- メールは Resend の API で送る（`MAIL_MAILER=resend`）。`MAIL_MAILER=log` のままだとメール認証やパスワード再設定のメールが届かない（内容はログに出る）。設定は §8。
- 環境変数を変えたら、再デプロイして反映させる。
- `LEGAL_*` は `config/legal.php` が読む。`LEGAL_SELLER_NAME`・`LEGAL_RESPONSIBLE_PERSON`・`LEGAL_EMAIL` は必須。未入力の項目は `/legal/tokushoho` に「準備中」と表示される。`LEGAL_EMAIL` は利用規約・プライバシーポリシーの問い合わせ先（`{email}`）にも入る。
- 個人事業主は、住所と電話番号を公開しない選択ができる。`LEGAL_ADDRESS` と `LEGAL_PHONE` を空にして `LEGAL_DISCLOSE_ON_REQUEST=true` にすると、その2項目は「請求があれば遅滞なく開示します。」と表示される（開示請求に実際に応じられる体制が前提）。
- 本番キー（`sk_live_` / `rk_live_`）で、必須項目が未入力のままだと、支援の申し込み（チェックアウト）を断る（`legal.not_ready` をログに出す）。テストキーでは止まらない。

## 5. 初回デプロイと確認

1. **Deploy** を押す。成功すると `*.laravel.cloud` のドメインが割り当てられる。そのドメインを `APP_URL` に設定し、もう一度デプロイする。
2. Stripe のダッシュボードで Webhook の宛先2つを、このドメインで作る（`/stripe/webhook` と `/stripe/connect-webhook`。`stripe-setup.md` §2.3）。署名シークレットを §4 の環境変数に入れて、再デプロイする。
3. 動作を確認する。
    - [ ] トップページと `/login` が表示される
    - [ ] 会員登録し、認証メールが届く
    - [ ] クリエイターとしてアイコンを変えると表示される（`public` バケット）
    - [ ] 画像付きの投稿を作り、支援者として閲覧できる（`private` バケットと署名付き URL）
    - [ ] Stripe のテストカードで支援でき、支援状態が反映される（キューワーカーが動いている）
    - [ ] **Commands** タブで `php artisan schedule:list` を実行し、`visparkle:prune-orphan-files` が出る

## 6. 管理者と運営の手順

### 6.1 管理者

管理者は、会員のうち `is_admin` が真のアカウント。管理画面は `/admin`（ダッシュボード、通報、支払い、利用者）。管理者でない人（未ログインを含む）が開くと **404** になり、管理画面があること自体が分からない。

Cloud の **Commands** タブで、対象の会員（登録済みのメールアドレス）を指定して実行する。

```bash
php artisan visparkle:admin you@example.com            # 管理者にする
php artisan visparkle:admin you@example.com --revoke   # 管理者権限を外す
```

- そのメールアドレスの会員がいないときは、エラーを出して終わる。先に会員登録しておく。
- 管理画面を使うには、メール認証済みであることも要る。メール送信が未設定のうち（§8）は、ログから認証 URL を拾って開く。

### 6.2 運営の手順

操作は `admin_actions` に記録される（誰が・何を・いつ）。

- **通報の処理**: `/admin/reports` で未対応の通報を確認し、「対応済み」または「却下」にする（メモは任意）。
- **投稿の非公開**: 通報された投稿などは、投稿の管理ページ（`/admin/posts/{id}`）で理由を付けて非公開にする。非公開にした投稿は、作者には非公開の旨のバナーが出て、ほかの人には 404 になる。同じページで公開に戻せる。
- **利用停止**: `/admin/users` で会員を検索し、理由を付けて利用停止にする。解除もここでできる。停止すると、その会員はログインできなくなり、同時に次の処理が走る。
    - 停止したのが**クリエイター**: プランの募集を止め、支援者の支援を**即時に解約**し、各支援の**最新の支払い1件を返金**する（それが返金済みなら返金しない）。
    - 停止したのが**ファン（支援者）**: その人の支援を**即時に解約**する。**返金はしない**。
    - 利用停止を解除しても、解約した支援と返金は元に戻らない。
- **返金**: `/admin/payments` から行う。その支払いを**全額**返金し、クリエイターへの送金と運営手数料も取り消す（部分返金はない）。支援も同時に解約するかは、ダイアログで選ぶ。詳細は `stripe-setup.md` §2.8。
- **チャージバック（異議申し立て）**: アプリからは対応できない。支払い一覧の「異議申し立て」バッジを見て、Stripe ダッシュボードで対応する（`stripe-setup.md` §2.8）。
- **一括処理が一部失敗したとき**: 利用停止の完了メッセージに、解約・返金・失敗の件数が出る（例: 「利用停止にしました。（解約 3件・返金 2件・失敗 1件）」）。失敗の詳細は **Monitoring → Logs** の `billing.ops_failed`（`operation` と対象の ID 付き）に残る。同じ件数は `admin_actions` にも `user.suspension_billing` として残る。原因（Stripe の障害など）を直したら、`/admin/users` で利用停止中の会員の「**再実行**」を押す（同じ理由で利用停止をやり直す。停止日時は変わらない）。対象はまだ有効な支援だけで、各支援の最新の支払いを返金する（最新の支払いが返金済みなら、それより前の月は返金しない）。**解約に成功したあとで返金だけ失敗した支払いは、支援がもう有効でないため再実行の対象にならない。`/admin/payments` から個別に返金する。** 返金の失敗は Stripe の冪等性により24時間は同じ結果が再生されるため、`stripe-setup.md` §2.8 の注意を見る。

### 6.3 デモクリエイターを支援できるようにする（Stripe テストモードのときだけ）

デモクリエイター（`DemoCreatorsSeeder`）は、本番では入金口座がないので全員「準備中」になる。Stripe がテストモード（`sk_test_...`）のうちは、10人のうち8人に Stripe のテスト用 Connect 口座を作れば、テストカードで支援できるようになる（`kuroneko-manga` と `hiro-3d` は「準備中」のまま）。

1. Cloud の **Commands** タブで実行する。

    ```bash
    php artisan visparkle:demo-payout-accounts
    ```

    8人分の口座を作り、登録手続きが済んでいない人ごとに Stripe の登録リンクを出す。

2. リンクを1つずつ開き、Stripe の登録画面で手続きを済ませる。テストモードではテスト用の値で進められる。リンクは数分で切れるので、切れたらもう一度コマンドを実行して新しいリンクを出す。
3. もう一度コマンドを実行し、最後の行が `支援可 8/8` になっていることを確かめる。

- 何度実行してもよい。作った口座は使い回し、実行のたびに Stripe から口座の状態を読み直す。
- 本番キー（`_live_`）のときは、エラーを出して何もしない。
- **本番キーに切り替える前に**、次のコマンドでデモクリエイターの口座を外す。テスト用口座は本番の Stripe にないので、外さないと「支援可」のまま表示され、支援しようとするとチェックアウトが失敗する。外すと8人とも「準備中」に戻る（Stripe 側のテスト用口座はそのまま残るが、影響はない）。キーを切り替えたあとに実行してもよい。

    ```bash
    php artisan visparkle:demo-payout-accounts --detach
    ```

    外す前に、クリエイターごとの口座 ID（`sakura-illust: acct_...`）を一覧で出すので、控えておく。テストキーに戻して支援可に戻したくなったら、控えた ID を各クリエイターの `stripe_account_id` に書き戻し、`--detach` なしでコマンドを実行する（口座の状態を Stripe から読み直すので、手続き済みの人はそのまま支援可になる）。

### 6.4 デモクリエイターのショップに商品を並べる

デモクリエイター（`DemoCreatorsSeeder`）を入れると、最後に `DemoProductsSeeder` も流れて商品が並ぶ。ショップを作る前にデモクリエイターを入れた本番では商品がないので、Cloud の **Commands** タブで商品だけを入れる。

```bash
php artisan db:seed --class=DemoProductsSeeder --force
```

- 本番では `--force` を付けないと、確認で止まる。
- 対象は `demo-creator` とデモクリエイター。まだ本番にいなければ何も入らない。
- 何度実行してもよい。商品が1つでもあるクリエイター（削除済みの商品を含む）には、商品を足さない。
- 商品ごとに、クリエイターの作風のモチーフ（桜、猫、ドット絵など）と色で描いた画像を1枚、公開のバケットに入れる。画像のない商品にはそのたびに描くので、画像の機能より前に商品を入れた本番でも、もう一度実行すれば画像が付く。
- 商品はスラッグから決まる乱数で作るので、実行のたびに同じ品揃えになる。送料が0円のクリエイターには送料も設定する。
- デジタル作品（ラフ画集）のファイルを非公開のバケットに書き込むので、購入とダウンロードもそのまま試せる。
- クリエイターが販売できる状態でなくても、商品は公開で入る。

### 6.5 デモデータ一式を入れる（実ユーザーがいない環境だけ）

ローカルと同じ規模のデモ（クリエイター50人、ファン100人、いいね、今週の人気クリエイター）を入れるには、Cloud の **Commands** タブで次を実行する。

```bash
php artisan db:seed --class=DemoShowcaseSeeder --force
```

- 実ユーザーがいる環境では実行しない。架空の支援が支援者数や管理画面の数字に混ざる。
- デモアカウントのパスワードは誰も知らない値になる。ログインはできない。
- 支援には Stripe の定期購入がないので、課金は発生しない。
- 今週の人気クリエイターは直近7日間の支援だけを数えるので、実行から1週間ほどで順位から外れていく。
- 再実行しても、すでにあるデータは増えない。

## 7. 独自ドメイン（任意）

**Domains** でドメインを追加し、DNS を設定する。証明書は Cloud が発行する。ドメインを変えたら、次の3つを新しいドメインに合わせる。

- `APP_URL`
- Stripe の Webhook 宛先2つ
- 送信元メールのドメイン設定

## 8. メール送信（Resend）

送信サービスは **Resend** を使い、Laravel の `resend` メーラー（`resend/resend-php`。HTTP API で送る）でつなぐ。アプリから送るのは、会員登録時のメール認証とパスワード再設定のメール（Fortify の標準機能）。

環境変数が未設定のあいだは `MAIL_MAILER=log` になり、メールは届かない。その内容は **Monitoring → Logs** に出るので、テスト中は、ログから `verify-email` を含む URL を拾って開けば認証できる。一般公開の前に、必ずこの節を終わらせる。

### なぜ独自ドメインが要るか

Resend（ほかの配信サービスも同じ）は、迷惑メール対策として送信元ドメインの所有確認（SPF・DKIM の DNS レコード）を求める。`visparkle.laravel.cloud` は Laravel Cloud のドメインで DNS を設定できないため、送信元に使えない。

ドメインは `sparklingstadt.com`（Cloudflare Registrar で取得、DNS も Cloudflare）。ほかの用途と送信の評判を分けるため、送信用にサブドメイン `visparkle.sparklingstadt.com` を Resend に登録する。メールを送るだけなら、ドメインを Laravel Cloud につなぐ（§7）必要はない。

### 手順

1. **Resend の Domains で `visparkle.sparklingstadt.com` を追加する**（リージョンは Tokyo）。
2. **DNS レコードを Cloudflare に追加する。** Resend の画面の「Auto configure」（Cloudflare 連携）を使うか、表示された MX・TXT（SPF）・TXT（DKIM）を Cloudflare の DNS に手で追加する。Cloudflare 上ではプロキシをオフ（DNS only）にする。任意で DMARC（`_dmarc` の TXT）も追加する。Resend 上で Verified になるのを待つ。
3. **API キーを作る**（権限は Sending access、対象はそのドメイン）。
4. **（任意）問い合わせ用のアドレスを作る。** Cloudflare の Email Routing で `support@sparklingstadt.com` などを作り、個人のアドレスに転送する。`LEGAL_EMAIL` に使える。
5. **Laravel Cloud の環境変数に設定し、再デプロイする。** API キーはリポジトリやチャットに貼らない。

```ini
MAIL_MAILER=resend
RESEND_API_KEY=re_...           # Resend の API キー
MAIL_FROM_ADDRESS=no-reply@visparkle.sparklingstadt.com
MAIL_FROM_NAME="${APP_NAME}"
```

6. **確認する。**
    - [ ] 新規登録で認証メールが届き、リンクから認証できる
    - [ ] パスワード再設定のメールが届く
    - [ ] 迷惑メールフォルダに入らない（Gmail の「メッセージのソースを表示」で SPF・DKIM が PASS）

### メモ

- 無料枠は月 3,000 通・1 日 100 通（2026-11 時点）。会員が増えたら有料プランを検討する。
- 支払いの領収書は Stripe が送る（`stripe-setup.md` §2.7）。アプリから送るのは、認証・パスワード再設定などのアカウント関連だけ（独自の通知はサブプロジェクト4）。
