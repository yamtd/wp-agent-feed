# WP Agent Feed

AIエージェントが `Accept: text/markdown` ヘッダー付きでアクセスしてきた際に、
投稿コンテンツをMarkdownで返すWordPressプラグイン。

## パフォーマンス設計

- **保存時にMarkdownを静的ファイルとして生成**（`wp-content/cache/markdown/`）
- リクエスト時はファイルを読んで返すだけ（変換処理なし）
- 外部ライブラリ依存なし（実行時）

## セットアップ

### インストール

1. [GitHub Releases](https://github.com/yamtd/wp-agent-feed/releases) から最新の `wp-agent-feed.zip` をダウンロード
2. WordPress 管理画面 > プラグイン > 新規プラグインを追加 > **プラグインのアップロード** から ZIP をインストール
3. 「WP Agent Feed」を有効化

初回インストール後は、WordPress の更新画面から自動で新バージョンを検出・更新できます（WordPress 5.8 以上）。

### キャッシュディレクトリの保護

- **Apache**: `.htaccess` で直接アクセスを自動ブロック（プラグインが生成）
- **クローラー**: `robots.txt` に `Disallow` ルールを自動追加（SEO 重複コンテンツ対策）
- **Nginx**: `robots.txt` による保護は自動適用される。直接アクセスもブロックしたい場合は以下を任意で追加:

  ```nginx
  location ~* ^/wp-content/cache/markdown/ {
      deny all;
      return 403;
  }
  ```

> **注意**: キャッシュファイルの内容は公開済み投稿の Markdown 変換です。
> `robots.txt` は主要検索エンジンのクローラーに対して有効ですが、直接アクセスをブロックするものではありません。

## 動作確認

```powershell
# Windows PowerShell
curl.exe https://your-site.com/your-post-slug/ -H "Accept: text/markdown"

# Linux / macOS
curl https://your-site.com/your-post-slug/ -H "Accept: text/markdown"
```

## レスポンスヘッダー例

```
Content-Type: text/markdown; charset=utf-8
Vary: Accept
X-Markdown-Tokens: 725
Content-Signal: ai-train=no, search=yes, ai-input=yes
Cache-Control: public, max-age=3600
```

## 設定のカスタマイズ

管理画面 **Settings > WP Agent Feed** から以下を設定できる:

- **Content-Signal** — レスポンスに付与する `Content-Signal` ヘッダー値
- **Cache-Control** — レスポンスに付与する `Cache-Control` ヘッダー値（空にするとヘッダーを送信しない）
- **Post Types** — Markdown 配信対象の投稿タイプ（チェックボックスで選択）

### wp-config.php による上書き

`wp-config.php` で定数を定義すると管理画面の設定より優先される。
定数が定義されている項目は管理画面上で読み取り専用になる。

設定の優先順位: **wp-config.php 定数 > DB オプション（管理画面） > デフォルト値**

```php
// wp-config.php に追記
define( 'WpAgentFeed\CACHE_DIR', WP_CONTENT_DIR . '/cache/markdown/' );  // キャッシュ保存先
define( 'WpAgentFeed\POST_TYPES', [ 'post', 'page', 'custom_type' ] );   // 対象の投稿タイプ
define( 'WpAgentFeed\CONTENT_SIGNAL', 'ai-train=yes, search=yes, ai-input=yes' ); // Content-Signal
define( 'WpAgentFeed\CACHE_CONTROL', 'public, max-age=3600' );                   // Cache-Control
```

| 定数 | 説明 | デフォルト |
|---|---|---|
| `WpAgentFeed\CACHE_DIR` | キャッシュ保存先 | `wp-content/cache/markdown/` |
| `WpAgentFeed\POST_TYPES` | 対象の投稿タイプ | `['post', 'page']` |
| `WpAgentFeed\CONTENT_SIGNAL` | Content-Signal ヘッダー値 | `ai-train=no, search=yes, ai-input=yes` |
| `WpAgentFeed\CACHE_CONTROL` | Cache-Control ヘッダー値 | `public, max-age=3600` |

## キャッシュ管理

管理画面の設定ページ下部からキャッシュを管理できる:

- **Regenerate All Cache** — 全対象投稿のキャッシュを一括再生成（50件ずつバッチ処理）
- **Clear All Cache** — 全キャッシュファイルを削除

### WP-CLI

```bash
wp markdown-cache regenerate
```

## Content-Signal について

`Content-Signal` は [Cloudflare が提案した HTTP ヘッダー](https://blog.cloudflare.com/content-signals-policy/)で、
AI エージェントに対してコンテンツの利用に関する希望を伝えるものです。
IETF の [AIPREF ワーキンググループ](https://datatracker.ietf.org/doc/draft-ietf-aipref-attach/)で標準化が進められています。

| 値 | 意味 |
|---|---|
| `ai-train=no` | モデルの学習に使用しないよう要請 |
| `search=yes` | 検索スニペットへの利用を許容 |
| `ai-input=yes` | AI 生成回答での利用を許容 |

> **重要**: これは**意思表示（preference）**であり、技術的な強制力はありません。
> 準拠するかどうかは各サービスの実装に依存します。

参考:
- [Content Signals Policy — Cloudflare Blog](https://blog.cloudflare.com/content-signals-policy/)
- [contentsignals.org](https://contentsignals.org/)
- [draft-ietf-aipref-attach — IETF Datatracker](https://datatracker.ietf.org/doc/draft-ietf-aipref-attach/)

## 注意事項

- **SEO メタディスクリプション対応（Beta）** — フロントマターの `description` は SEO プラグインのメタディスクリプションを優先的に使用する。
  対応プラグイン: SEO SIMPLE PACK / Yoast SEO / All in One SEO / SEOPress / Rank Math。
  未対応プラグインは `wp_agent_feed_description` フィルターでカスタマイズ可能。
  対応プラグインの一覧は今後変更される可能性がある。
- HTML→Markdown変換はWordPressの `the_content` 出力（標準的なブロック要素）に最適化。
  カスタムショートコードや複雑なネストされたテーブルは完全に変換されない場合がある。
- ネストされたリスト（`<ul>` / `<ol>` の入れ子）やネストされた `<blockquote>` は
  正しく階層化されない場合がある。単一階層のリスト・引用は正常に変換される。
- `Content-Signal` の初期値は `ai-train=no`（AI 学習への利用を望まないことを通知）。
  ポリシーに合わせて変更のこと。
- プラグイン**無効化**時、キャッシュディレクトリは削除されない。
  管理画面から**削除**した場合はキャッシュファイル・DB オプションが自動クリーンアップされる。

## 多言語対応

日本語翻訳ファイル同梱（`languages/`）。WordPress の言語設定に応じて自動適用される。

## Development

### 必要な環境

- PHP 8.2 以上（開発ツール用。プラグイン自体は PHP 7.4 以上で動作）
- Composer

### インストールと確認

```bash
composer install        # 依存インストール
composer check          # lint + test を一括実行
```

### 個別コマンド

```bash
composer lint           # PHPCS のみ
composer lint:fix       # PHPCBF で自動修正
composer test           # PHPUnit のみ
```

### ローカル WordPress 環境（任意・Docker 必要）

```bash
npx @wordpress/env start    # WordPress + プラグイン有効化済み環境を起動
npx @wordpress/env stop     # 環境停止
```
