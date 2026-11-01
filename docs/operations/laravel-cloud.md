# Laravel Cloud へのデプロイ手順

viSparkle は [Laravel Cloud](https://cloud.laravel.com) で動かす。Cloud の上に、アプリ（Web）、Laravel MySQL、オブジェクトストレージ（Cloudflare R2）のバケット2つ、キューワーカー、スケジューラを置く。Stripe 側の設定は `docs/operations/stripe-setup.md` を参照。

> **一般公開の前に必須**: 特定商取引法に基づく表記と利用規約への同意は、まだ実装されていない（`stripe-setup.md` 冒頭を参照）。それまでは Stripe のテストキーで動かし、本番キーで一般のユーザーから課金しない。

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
- `fanbox:init-storage` はローカル専用（本番では失敗する）。Cloud では実行しない。

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

App cluster の設定で **Scheduler** を有効にする。`fanbox:prune-orphan-files`（毎日）が動くようになる（`routes/console.php`）。

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

# 本物のメール送信サービスを使う（例: Resend）
MAIL_MAILER=resend
RESEND_API_KEY=...
MAIL_FROM_ADDRESS=no-reply@（送信元ドメイン）
MAIL_FROM_NAME="${APP_NAME}"
```

- `APP_KEY` は Cloud が生成する。生成されていなければ、ローカルで `php artisan key:generate --show` を実行し、出力を設定する。
- `BILLING_DRIVER=fake` は `APP_ENV=production` では例外になって使えない。Stripe のテストキーで動かすのが正しい形。
- メールは、`MAIL_MAILER=log` のままだとメール認証やパスワード再設定のメールが届かない。送信サービス（Resend / Postmark / SES など）でドメインを認証してから設定する。
- 環境変数を変えたら、再デプロイして反映させる。

## 5. 初回デプロイと確認

1. **Deploy** を押す。成功すると `*.laravel.cloud` のドメインが割り当てられる。そのドメインを `APP_URL` に設定し、もう一度デプロイする。
2. Stripe のダッシュボードで Webhook の宛先2つを、このドメインで作る（`/stripe/webhook` と `/stripe/connect-webhook`。`stripe-setup.md` §2.3）。署名シークレットを §4 の環境変数に入れて、再デプロイする。
3. 動作を確認する。
    - [ ] トップページと `/login` が表示される
    - [ ] 会員登録し、認証メールが届く
    - [ ] クリエイターとしてアイコンを変えると表示される（`public` バケット）
    - [ ] 画像付きの投稿を作り、支援者として閲覧できる（`private` バケットと署名付き URL）
    - [ ] Stripe のテストカードで支援でき、支援状態が反映される（キューワーカーが動いている）
    - [ ] **Commands** タブで `php artisan schedule:list` を実行し、`fanbox:prune-orphan-files` が出る

## 6. 独自ドメイン（任意）

**Domains** でドメインを追加し、DNS を設定する。証明書は Cloud が発行する。ドメインを変えたら、次の3つを新しいドメインに合わせる。

- `APP_URL`
- Stripe の Webhook 宛先2つ
- 送信元メールのドメイン設定
