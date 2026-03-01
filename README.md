# WP Agent Feed

AIエージェントが `Accept: text/markdown` ヘッダー付きでアクセスしてきた際に、
投稿コンテンツをMarkdownで返すWordPressプラグイン。

## パフォーマンス設計

- **保存時にMarkdownを静的ファイルとして生成**（`wp-content/cache/markdown/`）
- リクエスト時はファイルを読んで返すだけ（変換処理なし）
- 外部ライブラリ依存なし（Composerなし）

## セットアップ

```
wp-content/plugins/wp-agent-feed/wp-agent-feed.php
```

に配置し、管理画面 > プラグインから「WP Agent Feed」を有効化。

### Nginx の場合

Apache では .htaccess で自動保護されるが、Nginx の場合はキャッシュディレクトリへの
直接アクセスをブロックする設定を追加する:

```nginx
location ~* ^/wp-content/cache/markdown/ {
    deny all;
    return 403;
}
```

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
```

| 定数 | 説明 | デフォルト |
|---|---|---|
| `WpAgentFeed\CACHE_DIR` | キャッシュ保存先 | `wp-content/cache/markdown/` |
| `WpAgentFeed\POST_TYPES` | 対象の投稿タイプ | `['post', 'page']` |
| `WpAgentFeed\CONTENT_SIGNAL` | Content-Signal ヘッダー値 | `ai-train=no, search=yes, ai-input=yes` |

## キャッシュ管理

管理画面の設定ページ下部からキャッシュを管理できる:

- **Regenerate All Cache** — 全対象投稿のキャッシュを一括再生成（50件ずつバッチ処理）
- **Clear All Cache** — 全キャッシュファイルを削除

### WP-CLI

```bash
wp markdown-cache regenerate
```

## 注意事項

- HTML→Markdown変換はWordPressの `the_content` 出力（標準的なブロック要素）に最適化。
  カスタムショートコードや複雑なネストされたテーブルは完全に変換されない場合がある。
- ネストされたリスト（`<ul>` / `<ol>` の入れ子）やネストされた `<blockquote>` は
  正しく階層化されない場合がある。単一階層のリスト・引用は正常に変換される。
- `Content-Signal` のデフォルトは `ai-train=no`（トレーニング不許可）。
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
