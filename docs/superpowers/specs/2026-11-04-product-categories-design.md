# viSparkle — 商品カテゴリ 設計書

- 作成日: 2026-11-04
- ステータス: 提案（PR でユーザーがレビュー）
- 前提: ショップ（グッズ・デジタル作品）、商品のいいね、検索が main にマージ済み

## 1. 目的

運営があらかじめ用意したカテゴリから、クリエイターが商品ごとに1つ選べるようにする。ファンは商品ページ・カテゴリページ・検索の絞り込みで、同じ種類の商品を見つけやすくなる。将来のカテゴリ別ランキングの土台にもなる。

## 2. 成功基準

1. 運営は `/admin/categories` でカテゴリの追加・名前と slug の変更・並び替え・アーカイブと再開・削除（未使用時のみ）ができる
2. クリエイターは商品の作成・編集画面で、カテゴリを「未設定」か有効なカテゴリから1つ選べる（グッズ・デジタル作品とも）。必須ではない
3. 商品ページに有効なカテゴリ名が表示され、カテゴリページへリンクする
4. `/categories/{slug}` に、そのカテゴリの公開中の商品が新着順・24件ずつ表示される
5. 検索の商品結果を `?category={slug}` で絞り込める
6. ホームに有効なカテゴリへのリンクが1行で表示される（有効なカテゴリが無いときは表示しない）
7. アーカイブされたカテゴリは、公開ページ・選択肢・絞り込みのどこにも出ない

## 3. 方式

| 項目           | 採用                                                          | 理由                                                     |
| -------------- | ------------------------------------------------------------- | -------------------------------------------------------- |
| 保存先         | `product_categories` テーブル＋`products.product_category_id` | 運営が画面から編集でき、1商品1カテゴリにそのまま対応する |
| 付けられる数   | 0〜1個                                                        | 入力の手間が少なく、既存の商品は未設定のまま動く         |
| 階層           | なし（一段）                                                  | YAGNI。必要になったら親カテゴリを足す                    |
| 使わなくなった | アーカイブ（`archived_at`）。削除は商品が0件のときだけ        | 付いている商品のデータを消さずに、表から外せる           |
| 種類との関係   | グッズ・デジタル作品で共通の一覧                              | 一覧を分ける要望はない。分けるなら後で `kind` 列を足す   |

## 4. データ

`product_categories`

| 列            | 型                 | 備考                                                       |
| ------------- | ------------------ | ---------------------------------------------------------- |
| `id`          | bigint             |                                                            |
| `name`        | varchar(30)        | 一意                                                       |
| `slug`        | varchar(40)        | 一意。小文字英数字とハイフン（`^[a-z0-9]+(-[a-z0-9]+)*$`） |
| `sort_order`  | unsigned int       | 小さい順に表示                                             |
| `archived_at` | datetime, nullable | 入っていればアーカイブ済み                                 |
| timestamps    |                    |                                                            |

`products.product_category_id`: nullable の外部キー。`nullOnDelete`。索引は外部キーの索引で足りる。

モデル `ProductCategory`:

- スコープ `active()`（`archived_at` が null）、`ordered()`（`sort_order`, `id` 順）
- `isArchived()`、`toSummary(): {id, name, slug}`
- `products()` HasMany

`Product`:

- `category()` BelongsTo（`product_category_id`）
- スコープ `inCategory(ProductCategory $category)`
- `publicCategory(): ?ProductCategory` — 有効なときだけカテゴリを返す（アーカイブ済みは null）

## 5. 運営画面

ルート（`admin` ミドルウェア下、`admin.categories.*`）:

| メソッド | パス                                   | 内容                                   |
| -------- | -------------------------------------- | -------------------------------------- |
| GET      | `/admin/categories`                    | 一覧（商品数つき）＋追加フォーム       |
| POST     | `/admin/categories`                    | 追加（末尾に並ぶ）                     |
| PUT      | `/admin/categories/{category}`         | 名前・slug の変更                      |
| POST     | `/admin/categories/{category}/move`    | `direction=up                          | down` で隣と入れ替え      |
| PATCH    | `/admin/categories/{category}/archive` | `archived=true                         | false` でアーカイブ・再開 |
| DELETE   | `/admin/categories/{category}`         | 商品が1件でも付いていれば 422 のエラー |

- 一覧の商品数は削除済み（soft delete）を除いた商品の件数
- 管理メニュー（`resources/js/layouts/admin/layout.tsx`）に「カテゴリ」を追加
- 画面は `resources/js/pages/admin/categories.tsx`。各行で名前・slug をその場で編集でき、上下ボタン・アーカイブ/再開・削除を置く

## 6. クリエイターの商品フォーム

- `creator/products/edit` に `categories`（有効なカテゴリ、並び順）を渡す。編集時、商品のカテゴリがアーカイブ済みなら、その1件も `archived: true` で含める
- 選択欄は「未設定」＋カテゴリ。アーカイブ済みは「（受付終了）」付きで表示する
- 入力 `product_category_id`: `nullable|integer`。有効なカテゴリ、または（編集時）その商品に今付いているカテゴリのみ通す。それ以外は「カテゴリが正しくありません」
- `ProductController@store/update` で保存。`Product` の fillable に `product_category_id` を加える

## 7. 公開側

### 7.1 商品ページ

`public/product` の `product.category` に `publicCategory()?->toSummary()` を渡す。商品名の上に小さなバッジとして表示し、カテゴリページへリンクする。

### 7.2 カテゴリページ

`GET /categories/{slug}`（`categories.show`）。`Public/CategoryPageController`。

- 有効なカテゴリだけ。アーカイブ済み・存在しない slug は 404
- 商品の条件はホームと同じ: `Product::listed()` かつ `creator` が `open()`
- 新着順（`created_at`, `id` の降順）、24件ずつのページ送り
- 各商品はホームと同じ形（`toSummary()`＋`creator`、`liked`、`can_like`）で、既存の `ProductCard` で表示
- 他の有効なカテゴリへのリンクも上部に並べる

### 7.3 検索

- `SearchController` が `category` クエリを受け取り、有効なカテゴリの slug なら商品の結果を `inCategory` で絞る。無効な値は無視する（絞り込みなし）
- クリエイター・投稿の結果には影響しない
- 画面に `categories`（有効な一覧）と `category`（選択中の slug か null）を渡す。検索結果ページの商品欄の上に「すべて」＋各カテゴリの切り替えを置き、キーワードと種類を保ったままリンクする
- キーワードが空のときは、今までどおり何も検索しない

### 7.4 ホーム

`HomeController` が `categories`（有効な一覧）を渡し、商品カルーセルの上にカテゴリページへのリンクを1行で並べる。空なら描画しない。

## 8. デモデータ

シーダーに「アクリルグッズ」「イラスト」「音楽」「本・同人誌」などを追加し、デモ商品の一部に割り当てる。何度実行しても増えないよう slug で `updateOrCreate` する。

## 9. テスト

Pest の機能テスト:

- 運営: 管理者以外は 403。追加・変更（一意・slug 形式の検証）・並び替え・アーカイブ/再開・削除（使用中は拒否）
- クリエイター: カテゴリ付きで作成・変更・未設定に戻す。アーカイブ済みは新しく選べないが、付いたままなら保存できる。存在しない ID は拒否
- 商品ページ: 有効なカテゴリが出る。アーカイブ済みは出ない
- カテゴリページ: 対象商品だけが出る（非公開・運営による非表示・削除済み・活動終了クリエイターは出ない）。アーカイブ済み・存在しない slug は 404。ページ送り
- 検索: 絞り込みが効く。無効な slug は無視される
- ホーム: 有効なカテゴリだけが渡される

## 10. やらないこと

- カテゴリ別ランキング
- 階層カテゴリ、複数カテゴリ、タグ
- 投稿へのカテゴリ付け
- カテゴリページの人気順の並び替え
