# viSparkle — 投稿カテゴリ 設計書

- 作成日: 2026-11-04
- ステータス: 実装済み（#49）
- 前提: 商品カテゴリ（`docs/superpowers/specs/2026-11-04-product-categories-design.md`、#48）が main にマージ済み

## 1. 目的

運営があらかじめ用意した投稿用のカテゴリ（「イラスト」「漫画」など）から、クリエイターが投稿ごとに1つ選べるようにする。ファンは、投稿ページ・カテゴリ別の投稿一覧・検索の絞り込み・クリエイターページの絞り込みから、好みの種類の投稿を見つけやすくなる。商品カテゴリとは別の一覧にする（商品カテゴリはグッズの種類で、投稿には合わない）。

## 2. 成功基準

1. 運営は `/admin/post-categories` で投稿カテゴリを追加・変更・並び替え・アーカイブ／再開・削除（未使用時のみ）できる。`/admin/categories`（商品）との間を切り替えられる
2. クリエイターは投稿の作成・編集画面で、カテゴリを「未設定」か有効なカテゴリから1つ選べる。必須ではない
3. 投稿ページに有効なカテゴリ名が表示され、カテゴリ別の投稿一覧へリンクする
4. `/post-categories/{slug}` に、そのカテゴリの無料で公開中の投稿が新着順・20件ずつ表示される。有料投稿は出さない
5. 検索の投稿結果を `?post_category={slug}` で絞り込める
6. ホームの「人気の全体公開投稿」の上に、有効な投稿カテゴリへのリンクが1行で表示される（無いときは表示しない）
7. クリエイターページの投稿タブを `?post_category={slug}` で絞り込める。有料投稿も対象（今と同じくロック表示）。切り替えには、そのクリエイターの公開中の投稿で使われている有効なカテゴリだけを出す
8. アーカイブされたカテゴリは、公開ページ・選択肢・絞り込みのどこにも出ない

## 3. 方式

| 項目           | 採用                                                                   | 理由                                                                                         |
| -------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| 保存先         | `post_categories` テーブル＋`posts.post_category_id`                   | 商品カテゴリと独立に管理できる。既存の `product_categories` やそのルート・テストを変えない   |
| 付けられる数   | 0〜1個                                                                 | 商品カテゴリと同じ                                                                           |
| 運営画面       | 商品用と共通の親コントローラー・同じ画面コンポーネントを使い回す       | 同じ操作を二重に実装しない                                                                   |
| 公開一覧の範囲 | カテゴリ別一覧・検索・ホームは無料投稿のみ。クリエイターページは全投稿 | 有料投稿のタイトルを公開一覧に出さない既存の方針を守る。クリエイターページは今も全投稿を出す |
| URL            | `/post-categories/{slug}`                                              | `/categories/{slug}`（商品）と slug が重なっても衝突しない                                   |

## 4. データ

`post_categories`（`product_categories` と同じ構成）

| 列            | 型                 | 備考                                                       |
| ------------- | ------------------ | ---------------------------------------------------------- |
| `id`          | bigint             |                                                            |
| `name`        | varchar(30)        | 一意                                                       |
| `slug`        | varchar(40)        | 一意。小文字英数字とハイフン（`^[a-z0-9]+(-[a-z0-9]+)*$`） |
| `sort_order`  | unsigned int       | 小さい順に表示                                             |
| `archived_at` | datetime, nullable | 入っていればアーカイブ済み                                 |
| timestamps    |                    |                                                            |

`posts.post_category_id`: nullable の外部キー、`nullOnDelete`。

モデル `PostCategory`: `ProductCategory` と同じ公開メソッド（`active()`、`ordered()`、`isArchived()`、`toSummary(): {id, name, slug}`、`static nextSortOrder()`、`static activeSummaries()`、`posts()` HasMany）。

`Post`: `category()` BelongsTo、`publicCategory(): ?PostCategory`（有効なときだけ）、スコープ `inCategory(PostCategory $category)`。

共通部分（スコープ・`nextSortOrder` など）は、両モデルで使うトレイトにまとめてよい。`ProductCategory` の外から見た振る舞いは変えない。

## 5. 運営画面

- ルート `admin.post-categories.*`（`/admin/post-categories`、メソッドとパスは商品の `admin.categories.*` と同じ: index / store / update / move / archive / destroy）
- 商品の `Admin\CategoryController` の処理を親クラス（または共通のトレイト）に移し、商品用と投稿用の2つのコントローラーで使う。商品側のルート名・URL・挙動は変えない
- 削除: 投稿が1件でも付いていれば `category` キーの検証エラーで元の画面に戻す（商品と同じ）
- `AdminAction` の action 名は `post_category.created` / `updated` / `moved` / `archived` / `unarchived` / `deleted`
- 画面は `resources/js/pages/admin/categories.tsx` を使い回し、props で「商品 / 投稿」と送り先を切り替える。画面上部に「商品 / 投稿」の切り替えを置く。件数の表示は投稿用では「投稿 N 件」
- 管理メニューの「カテゴリ」は今のまま（`/admin/categories`）で、投稿カテゴリの画面でも選択中として表示する

## 6. クリエイターの投稿フォーム

- `creator/posts/edit` に `categories`（有効なカテゴリ、並び順）を渡す。編集時に投稿のカテゴリがアーカイブ済みなら、その1件も `archived: true` で末尾に足す
- 選択欄は「未設定」＋カテゴリ。アーカイブ済みは「（受付終了）」付き。カテゴリが無ければ欄ごと出さない
- 入力 `post_category_id`: 有効なカテゴリ、または（編集時）その投稿に今付いているカテゴリのみ通す。それ以外は「カテゴリが正しくありません」（商品と同じ、`orWhere` は括弧でまとめる）

## 7. 公開側

### 7.1 投稿ページ

`PostPageController` で `category` を eager load し、`post.category` に `publicCategory()?->toSummary()` を渡す。タイトルの上にバッジとして表示し、カテゴリ別一覧へリンクする。有料でまだ見られない投稿でもカテゴリは表示してよい（タイトルと同じく投稿ページでは既に出ている情報の範囲）。

### 7.2 カテゴリ別の投稿一覧

`GET /post-categories/{slug}`（`post-categories.show`）。`Public/PostCategoryPageController`。

- 有効なカテゴリだけ。アーカイブ済み・存在しない slug は 404
- 投稿の条件はホームの全体公開投稿と同じ: `published()->visible()`、`required_price = 0`、作者の会員が利用停止でない
- 新着順（`published_at` 降順、`id` 降順）、20件ずつのページ送り
- 表示は既存の `PostPresenter::summary` と `PostCard`（`showCreator`）
- 上部に他の有効な投稿カテゴリへのリンク（`CategoryLinks`）。0件なら「このカテゴリの投稿はまだありません。」

### 7.3 検索

- `post_category` クエリが有効な投稿カテゴリの slug なら、投稿の結果を `inCategory` で絞る。無効な値は無視する
- 商品の `category` 絞り込みとは独立。両方指定されたら両方効く
- 画面に `postCategories`（有効な一覧、キーワードがあるときだけ）と `postCategory`（選択中の slug か null）を渡し、投稿欄の上に「すべて」＋各カテゴリの切り替えを置く。リンクは `q`・`type`・`category` を保つ
- 投稿欄を出す条件は商品と同じ考え方（投稿がある、または投稿カテゴリで絞り込み中）。絞り込み中で0件なら「このカテゴリの投稿は見つかりませんでした。」

### 7.4 ホーム

`HomeController` が `postCategories`（有効な一覧）を渡し、「人気の全体公開投稿」の見出しの下・投稿一覧の上に `CategoryLinks` を並べる（リンク先は `/post-categories/{slug}`）。空なら描画しない。

### 7.5 クリエイターページ

- 投稿タブ（`tab=posts`）で `post_category` クエリを受け、有効な投稿カテゴリの slug なら投稿一覧を `inCategory` で絞る。無効な値は無視する
- 切り替えに出すカテゴリ（`postCategories`）は、そのクリエイターの `published()->visible()` な投稿に付いている有効なカテゴリだけ（並び順どおり）。1つも無ければ切り替えを出さない
- 絞り込みは有料投稿も対象。表示は今と同じ `PostPresenter::summary`（見られない投稿はロック表示）
- ページ送りのリンクは `post_category` を保つ（`withQueryString()`）
- ショップタブには影響しない

## 8. デモデータ

`DemoPostCategoriesSeeder` を追加し、`DemoCreatorsSeeder` から `DemoCategoriesSeeder` の後に呼ぶ。

- 定義（slug・名前）: `illustration` イラスト／`manga` 漫画／`diary` 日記・お知らせ／`making` メイキング
- 足りないものだけ追加する（slug で見つかればそのまま使う、なければ名前で探してそのまま使う、なければ末尾に作る）。既存のカテゴリは書き換えない
- 割り当てはデモ用クリエイター（`DemoProductsSeeder::creatorSlugs()`）のカテゴリ未設定の投稿だけ。どの投稿に何を付けるかは、デモ投稿のタイトルから決める（実装計画で表にする）
- 運用ドキュメント `docs/operations/laravel-cloud.md` §6.4 に実行手順を追記する

## 9. テスト

Pest の機能テスト:

- モデル: 有効なものだけ・並び順、`publicCategory`、削除で未設定に戻る
- 運営: 管理者以外は 404（既存の運営画面と同じ）。追加・変更（一意・slug 形式）・並び替え・アーカイブ／再開・削除（使用中は拒否）。商品カテゴリの既存テストがそのまま通る
- クリエイター: カテゴリ付きで作成・変更・未設定に戻す。アーカイブ済みは新しく選べないが、付いたままなら保存できる。別のアーカイブ済み ID は拒否
- 投稿ページ: 有効なカテゴリが出る。アーカイブ済みは出ない
- カテゴリ別一覧: 無料で公開中の投稿だけ（有料・下書き・予約・非表示・利用停止の作者は出ない）。アーカイブ済み・存在しない slug は 404。ページ送り
- 検索: 投稿の絞り込みが効く。無効な値は無視。商品の絞り込みと独立
- ホーム: 有効な投稿カテゴリだけが渡される
- クリエイターページ: 有料投稿も含めて絞り込める。切り替えには使われている有効なカテゴリだけ。ページ送りで `post_category` が保たれる
- シーダー: デモ用クリエイターの投稿だけに付く。運営の編集を上書きしない。名前の重複で止まらない

## 10. やらないこと

- カテゴリ別ランキング
- 複数カテゴリ、タグ
- フィード（`/feed`）の絞り込み
- 商品カテゴリと投稿カテゴリをまとめたページ
