# viSparkle

クリエイターを月額で支援し、支援額に応じた限定投稿を閲覧できるサービス。
クリエイターは自分のページの「ショップ」でグッズ（在庫・送料つき）も販売でき、ファンはカートに入れてまとめて購入できる。

- 設計書: `docs/superpowers/specs/`
- 実装計画: `docs/superpowers/plans/`
- デプロイ（Laravel Cloud）: `docs/operations/laravel-cloud.md`

## 開発環境

ホストに PHP は不要です（Docker / Laravel Sail を使用）。

```bash
cp .env.example .env
docker run --rm -v "$PWD":/app -w /app composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan fanbox:init-storage   # RustFS(S3互換) にバケットを作成
./vendor/bin/sail artisan migrate --seed       # デモデータ投入
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

- アプリ: http://localhost
- メール（Mailpit）: http://localhost:8025
- ストレージ管理画面（RustFS）: http://localhost:9001

### デモアカウント（`--seed` 時、パスワードはどちらも `password`）

| 役割                             | メール              |
| -------------------------------- | ------------------- |
| クリエイター（`/@demo-creator`） | creator@example.com |
| ファン                           | fan@example.com     |

## テスト

```bash
./vendor/bin/sail pest
./vendor/bin/sail npm run types:check
./vendor/bin/sail bin pint --test
```

テストは本番と同じ MySQL（`testing` データベース）で実行します。
