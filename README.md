# viSparkle

一人のクリエイターを月額で支援し、支援額に応じた限定投稿を閲覧できるサービス。

トップページ（`/`）がクリエイターのページで、紹介・支援プラン・投稿が並ぶ。ファンはプランを選んで Stripe で支払い、支援額以上の限定投稿を読める。クリエイターは、プラン・投稿・支援者を管理する。

> いまのコードは、仕組みを読んで理解するために**縮小した版**です。外した機能は、下の「縮小前の機能と戻し方」から一つずつ戻していきます。

- 設計書: `docs/superpowers/specs/`
- 実装計画: `docs/superpowers/plans/`
- デプロイ（Laravel Cloud）: `docs/operations/laravel-cloud.md`
- Stripe の設定: `docs/operations/stripe-setup.md`

## 開発環境

ホストに PHP は不要です（Docker / Laravel Sail を使用）。

```bash
cp .env.example .env
docker run --rm -v "$PWD":/app -w /app composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan visparkle:init-storage   # RustFS(S3互換) にバケットを作成
./vendor/bin/sail artisan migrate --seed       # デモデータ投入
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

- アプリ: http://localhost
- メール（Mailpit）: http://localhost:8025
- ストレージ管理画面（RustFS）: http://localhost:9001

### デモアカウント（`--seed` 時、パスワードはどちらも `password`）

| 役割         | メール              |
| ------------ | ------------------- |
| クリエイター | creator@example.com |
| ファン       | fan@example.com     |

### クリエイターの設定

- トップに出す名前と紹介文は `.env` の `CREATOR_NAME` / `CREATOR_BIO` で決めます（`config/visparkle.php`）。
- プランと投稿を管理できるのは、`users.is_creator` が真のアカウントです。付け外しは次のコマンドで行います。

```bash
./vendor/bin/sail artisan visparkle:creator you@example.com            # クリエイターにする
./vendor/bin/sail artisan visparkle:creator you@example.com --revoke   # 外す
```

## テスト

```bash
./vendor/bin/sail pest
./vendor/bin/sail npm run types:check
./vendor/bin/sail bin pint --test
```

テストは本番と同じ MySQL（`testing` データベース）で実行します。

## 縮小前の機能と戻し方

縮小前のコードには、git のタグ `before-shrink` が付いています（`git show before-shrink:パス` や `git diff before-shrink -- パス` で見られます）。縮小の設計は `docs/superpowers/specs/2026-11-17-single-creator-shrink-design.md` にあります。

外した機能と、それぞれを作ったときの設計書・計画書は次のとおりです。

| 外した機能                                                         | 設計書（`docs/superpowers/specs/`）            | 計画書（`docs/superpowers/plans/`） |
| ------------------------------------------------------------------ | ---------------------------------------------- | ----------------------------------- |
| 複数クリエイター（登録・`/@slug` のページ・プロフィール編集）      | `2026-10-06-fanbox-core-design.md`             | `2026-10-06-fanbox-core.md`         |
| Stripe Connect（口座登録・送金・手数料）と売上画面                 | `2026-11-01-fanbox-payments-design.md`         | `2026-11-01-fanbox-payments.md`     |
| 管理画面・通報・審査・利用停止・クリエイターの休止・規約同意の記録 | `2026-11-01-visparkle-ops-legal-design.md`     | `2026-11-01-visparkle-ops-legal.md` |
| フォローとフィード                                                 | `2026-11-02-follows-design.md`                 | `2026-11-02-follows.md`             |
| 投稿のいいね                                                       | `2026-11-02-post-likes-design.md`              | —                                   |
| 週間人気クリエイター                                               | `2026-11-02-weekly-popular-creators-design.md` | —                                   |
| ショップ（グッズ・カート・注文・発送）                             | `2026-11-02-shop-goods-design.md`              | `2026-11-02-shop-goods.md`          |
| デジタル作品の販売                                                 | `2026-11-03-digital-goods-design.md`           | `2026-11-03-digital-goods.md`       |
| ショップ経由の上乗せ支援                                           | `2026-11-03-shop-support-design.md`            | `2026-11-03-shop-support.md`        |
| 投稿カテゴリ                                                       | `2026-11-04-post-categories-design.md`         | `2026-11-04-post-categories.md`     |
| 商品カテゴリ                                                       | `2026-11-04-product-categories-design.md`      | `2026-11-04-product-categories.md`  |

ほかに、検索、閲覧数と人気順、デモデータの大量投入（クリエイター50人・ファン100人）も外しています。検索と閲覧数・人気順は、上の設計書の一部（コア機能、運営・法対応、カテゴリ、週間人気、いいね）に書かれています。デモデータの大量投入には設計書がないので、`before-shrink` の `database/seeders/` を見てください。
