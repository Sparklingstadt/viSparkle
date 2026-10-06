# クリエイター支援サービス — サブプロジェクト1「コア」設計書

- 作成日: 2026-10-06
- ステータス: 承認済み（ユーザーが仕様・提案を事前承認。最終レビューはユーザーが実施）

## 1. 目的と前提

Fanbox のような、ファンがクリエイターを月額で支援し、支援額に応じた限定投稿を閲覧できるサービスを Laravel で構築し、**実際に公開・運営する**。

### 確定した前提（ユーザー回答）

- 実運用前提（本物のクリエイター・ファン・お金が動く）
- マーケットプレイス型: 誰でもクリエイター登録でき、運営が手数料を引いて各クリエイターへ支払う
- 全年齢コンテンツのみ（R-18 は扱わない）

### 仮定

- 対象は日本国内、通貨は JPY
- 決済・送金は Stripe（Billing + Connect Express）を採用する（サブプロジェクト2で実装）
- 開発環境は Docker（Laravel Sail）。ホストに PHP は不要

## 2. 全体分割

| # | サブプロジェクト | 概要 |
|---|---|---|
| **1** | **コア（本書）** | 会員・クリエイターページ・プラン・投稿・権限制御・ファイル配信。課金はダミー |
| 2 | 決済 | Stripe Billing + Connect、Webhook、解約・プラン変更、手数料、口座登録 |
| 3 | 運営・法対応 | 管理画面、通報・非公開化、特商法表記、規約同意、退会、本番デプロイ |
| 4 | ソーシャル | いいね、コメント、通知、フォロー、メール配信 |

各サブプロジェクトは個別に 設計 → 計画 → 実装 のサイクルを回す。本書はサブプロジェクト1のみを対象とする。

## 3. 成功基準

1. ユーザーが登録し、クリエイター登録・プラン作成・投稿公開までできる
2. ファンが（ダミー）支援すると、支援額以下の `required_price` の投稿（過去分含む）が閲覧できる
3. 閲覧権限のないユーザーへのレスポンスに、本文・添付ファイル・ファイルURL（カバー縮小版を除く）が**一切含まれない**（テストで保証）
4. ファイルは非公開ストレージに保存され、権限者にのみ短命な署名付きURLで配信される
5. 権限マトリクステストがすべて通る

## 4. 技術スタック

- Laravel（実装開始時点の最新安定版）、PHP 8.4
- Inertia.js + React + TypeScript（Laravel 公式 React スターターキット）
- 開発: Laravel Sail（MySQL 8 / Redis / MinIO）
- 本番ストレージ: S3 互換（AWS S3 または Cloudflare R2）。ドライバ設定のみで切替
- リッチエディタ: Tiptap
- 画像処理: Intervention Image
- テスト: Pest

## 5. データモデル

### users
スターターキット標準（name, email, password, email_verified_at 等）。全員がまずファンとして登録する。

### creators
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint | |
| user_id | FK users, unique | 1ユーザー1クリエイター |
| slug | string, unique | `^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$`、予約語禁止、**変更不可** |
| display_name | string(50) | |
| bio | text, nullable | 最大 2000 文字 |
| avatar_path | string, nullable | 公開ディスク |
| header_path | string, nullable | 公開ディスク |
| timestamps | | |

予約語（例）: `admin, api, creator, creators, login, logout, register, feed, supporting, settings, dashboard, posts, plans, storage, assets, help, terms, privacy`。設定ファイルで管理する。

### plans
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint | |
| creator_id | FK creators | |
| name | string(50) | 編集可 |
| description | text, nullable | 編集可、最大 1000 文字 |
| price | unsigned int | 円。100〜50000。**作成後変更不可**。(creator_id, price) unique |
| cover_path | string, nullable | 公開ディスク |
| is_accepting | bool, default true | false で新規受付停止（既存支援は継続） |
| sort | unsigned smallint | |
| timestamps | | |

- 有効な支援が1件でもあるプランは削除不可（受付停止のみ）。終了済み支援の plan_id は削除時に null になる（price スナップショットは残る）。
- 1クリエイターあたりのプラン数上限: 10。

### supports
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint | |
| user_id | FK users | |
| creator_id | FK creators | |
| plan_id | FK plans, nullable, nullOnDelete | 終了済み支援しかないプランは削除可能 |
| price | unsigned int | **支援開始時点の金額スナップショット**。権限判定に使う |
| status | enum(active, ended) | |
| started_at | datetime | |
| ended_at | datetime, nullable | |
| timestamps | | |

- 同一 (user_id, creator_id) で `active` は最大1件（アプリ層＋トランザクション内のロックで保証）。
- 自分自身（自分のクリエイター）への支援は不可。
- サブプロジェクト1では「支援する」操作で即 `active` を作成する（ダミー）。サブプロジェクト2で Stripe サブスクリプションの状態に置き換える。そのため支援の作成・終了は `SupportService` に集約し、呼び出し元を差し替えやすくする。

### posts
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint | 公開URLに使う |
| creator_id | FK creators | |
| title | string(100) | |
| body | json, nullable | Tiptap JSON |
| excerpt | string(300), nullable | 権限なしでも表示される公開部分 |
| cover_file_id | FK post_files, nullable, nullOnDelete | カバー画像（権限なしでも縮小版の署名付きURLのみ発行） |
| required_price | unsigned int, default 0 | 0 = 全体公開。それ以外は自分のプラン金額のいずれか |
| status | enum(draft, published) | |
| published_at | datetime, nullable | |
| timestamps | | |

インデックス: (creator_id, status, published_at)。

### post_files
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint | |
| post_id | FK posts, cascade | |
| kind | enum(image, attachment) | |
| path | string | 非公開ディスク上のパス |
| thumb_path | string, nullable | 画像の表示用縮小版 |
| original_name | string | |
| mime | string | |
| size | unsigned bigint | bytes |
| width / height | unsigned int, nullable | 画像のみ |
| sort | unsigned smallint | 添付ファイルの並び順 |
| timestamps | | |

## 6. 閲覧権限ルール

判定は `App\Domain\Entitlement`（仮称）1か所に集約する。

```
canView(?User viewer, Post post):
  if viewer is post.creator.user            → true
  if post.status != published               → false
  if post.required_price == 0               → true
  if viewer is null                         → false
  support = viewer の post.creator への active な support
  return support != null && support.price >= post.required_price
```

- プランIDではなく**金額しきい値**で判定する。上位プラン支援者は下位向け投稿も閲覧可能。
- 判定にはスナップショット金額 `supports.price` を用いる。
- 支援開始で過去の投稿も閲覧可能、支援終了で閲覧不可になる。
- 一覧表示では閲覧者の active な支援を1クエリで取得し（creator_id → price のマップ）、N+1 を避ける。

### 権限なし時の表示
- 表示する: タイトル、カバー画像（縮小版）、excerpt、必要金額、クリエイター情報、公開日時
- 送らない: body、post_files（カバー以外）、カバー縮小版以外のファイルURL
- 本文の除外は**サーバー側**で行う。`PostResource` が閲覧者を受け取り、権限がなければ body / files / 署名付きURL を生成しない。

### HTTP ステータス
- 権限なしの公開投稿: 200（ロック画面）
- 他人の下書き・存在しない投稿: 404
- クリエイター管理画面で他人のリソース操作: 404（存在を秘匿）

## 7. 画面とルーティング

### 公開
| URL | 内容 |
|---|---|
| `/` | 新着の全体公開投稿・クリエイター一覧（最小限） |
| `/@{slug}` | クリエイターページ: プロフィール、プラン一覧、投稿一覧（ロック表示に必要金額） |
| `/@{slug}/posts/{post}` | 投稿詳細（`post.creator.slug` 不一致は 404） |

### ファン（要ログイン・メール認証済み）
| URL | 内容 |
|---|---|
| `/feed` | 支援中クリエイターの新着投稿 |
| `/supporting` | 支援中一覧と支援停止 |
| `POST /@{slug}/plans/{plan}/support` | 支援開始（ダミー）。既存 active があれば終了して新規作成（プラン変更） |
| `DELETE /supports/{support}` | 支援停止（ダミー）。即時 `ended` |

認証・メール認証・パスワードリセットはスターターキット標準を使用する。

### クリエイター管理 `/creator`
| URL | 内容 |
|---|---|
| `/creator/register` | クリエイター登録（slug・表示名） |
| `/creator/profile` | プロフィール編集（表示名・自己紹介・アイコン・ヘッダー） |
| `/creator/plans` | プランの作成・編集・受付停止・削除 |
| `/creator/posts` | 投稿一覧（下書き・公開） |
| `POST /creator/posts` | 下書きを作成してエディタへリダイレクト |
| `/creator/posts/{post}/edit` | エディタ（Tiptap） |
| `POST /creator/posts/{post}/files` | 画像・添付の即時アップロード |
| `DELETE /creator/posts/{post}/files/{file}` | 添付削除 |
| `PUT /creator/posts/{post}` | 保存（下書き保存 / 公開 / 公開取り消し） |
| `DELETE /creator/posts/{post}` | 投稿削除（ファイルも削除） |
| `/creator/supporters` | プラン別人数・支援者表示名一覧 |

`/creator/*`（登録以外）はクリエイター登録済みユーザーのみ。

### 投稿エディタの挙動
- 「新規作成」で下書きを即作成し、以降のアップロードはその投稿に紐づく。
- `required_price` の選択肢は「0円（全体公開）」＋自分のプラン金額。保存時に検証する。
- 公開時に `published_at` を記録（初回公開時のみ）。公開取り消しで `draft` に戻す。
- 保存時、本文から参照されなくなった `kind=image` の post_files を削除する（カバー画像は除く）。

## 8. ファイル保存と配信

- ディスク構成:
  - `private`（S3/MinIO 非公開バケット）: post_files
  - `public`（S3/MinIO 公開バケット）: アバター・ヘッダー・プランカバー
- パス: `creators/{creator_id}/posts/{post_id}/{ulid}.{ext}`（推測不能）
- 配信: `Storage::temporaryUrl()` による署名付きURL、有効期限 5 分。アプリサーバーを経由しない。
- 画像処理（アップロード時・同期）:
  - EXIF を除去して再エンコード（位置情報漏えい防止）
  - 長辺 2560px の表示用を `path`、長辺 640px の縮小版を `thumb_path` として保存
  - GIF はアニメーション維持のため再エンコードせず、EXIF を持たないことを確認して保存
- 上限（`config/fanbox.php` で変更可能）:
  - 画像: jpg / png / gif / webp、1枚 10MB
  - 添付: 1ファイル 100MB、1投稿 20 ファイルまで
  - 画像: 1投稿 100 枚まで
- 添付ファイルのダウンロードは `Content-Disposition: attachment` を付けた署名付きURLで配信する。

## 9. 本文（Tiptap JSON）の安全性

- 保存時にサーバー側で検証する `TiptapDocumentValidator`:
  - 許可ノード: doc, paragraph, heading(level 2–3), text, bulletList, orderedList, listItem, blockquote, horizontalRule, hardBreak, image
  - 許可マーク: bold, italic, strike, link
  - link の href は `http` / `https` のみ
  - image の attrs は `fileId` のみ。その投稿の `kind=image` の post_files に存在すること
  - 未知のノード・属性があれば 422
  - サイズ上限: JSON 512KB
- フロントは JSON から React で描画し、`dangerouslySetInnerHTML` を使わない。
- 表示時、image ノードの `fileId` を署名付きURLに解決して渡す（権限者のみ）。

## 10. エラー処理

- アップロードのバリデーションエラー: 422、エディタ上にインライン表示
- ストレージ書き込み失敗: DB 登録をロールバックし、エラーログを出して 500（ユーザーには再試行を促す表示）
- DB 削除後のストレージ削除失敗: ログに記録（孤児ファイルは後続の掃除コマンドで回収）
- 孤児ファイル掃除: `fanbox:prune-orphan-files` Artisan コマンド（DB に存在しないパスを削除）。スケジューラで日次実行
- レートリミット: アップロード 60回/分/ユーザー、支援操作 10回/分/ユーザー

## 11. テスト方針（Pest）

### 権限マトリクス（中心）
閲覧者 × 投稿状態 の全組み合わせを dataset で検証する。

- 閲覧者: 投稿者本人 / 未ログイン / 支援なし / 低額支援（< required_price） / 同額支援 / 高額支援 / 終了済み支援 / 別クリエイターへの支援
- 投稿: 0円公開 / 有料公開 / 下書き

### 漏えい防止
- 権限なしレスポンス（Inertia props）に body の文字列、post_files のパス、署名付きURLが含まれないこと
- 一覧ページでも同様

### その他
- slug 規則・予約語・変更不可
- プラン: 金額変更不可、支援者ありで削除不可、(creator, price) 重複不可、上限10
- 支援: active 1件制約、プラン変更、自分への支援不可、受付停止プランへの新規支援不可
- 投稿: required_price が自分のプラン金額以外なら 422、公開・取り消し
- アップロード: MIME・サイズ・件数上限、EXIF 除去、縮小版生成
- Tiptap 検証: 不正ノード・javascript: リンク・他投稿の fileId を拒否
- 未参照画像の削除、投稿削除時のファイル削除
- N+1 防止（一覧でのクエリ数アサーション）

## 12. スコープ外（YAGNI）

予約投稿、タグ・検索、コメント・いいね・通知（4）、単品販売・ショップ、実決済（2）、管理画面・通報・特商法・退会・本番デプロイ（3）、多言語化、スマホアプリ。
