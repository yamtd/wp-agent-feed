# WP Agent Feed — 開発ルール

## プロジェクト概要

WordPress プラグイン。3ファイル構成。
`Accept: text/markdown` ヘッダー付きリクエストに対して投稿を Markdown で返す。

```
wp-agent-feed.php          エントリポイント・コア機能
includes/markdown.php      HTML→Markdown変換（純粋関数）
includes/admin.php         設定画面・AJAX・診断（is_admin() 時のみロード）
```

## 検証コマンド

変更後は必ず実行:

```bash
composer check          # lint + test を一括実行
```

個別実行:

```bash
composer lint           # PHPCS（WordPress-Extra + PHPCompatibilityWP）
composer lint:fix       # PHPCBF で自動修正
composer test           # PHPUnit
```

手動確認（Docker 必要）:

```bash
npx @wordpress/env start
curl -H "Accept: text/markdown" http://localhost:8888/?p=1
npx @wordpress/env stop
```

## コーディング規約

- 名前空間: `WpAgentFeed`（全関数・定数はこの名前空間内）
- コーディングスタイル: WordPress Coding Standards（PHPCS で自動検証）

## レスポンスヘッダー契約

以下のヘッダーを壊さないこと:

- `Content-Type: text/markdown; charset=utf-8`
- `Vary: Accept`
- `X-Markdown-Tokens: {数値}`
- `Content-Signal: {WpAgentFeed\CONTENT_SIGNAL}`

## PHP バージョン方針

- **開発ツール**: PHP 8.2+（PHPUnit 11 の要件）
- **プラグイン互換性**: PHP 7.4+（`Requires PHP: 7.4`）
- **自動検証**: PHPCompatibilityWP が PHPCS で PHP 7.4 互換性をチェック
- **禁止構文**: PHP 8.0+ 専用構文は使用禁止
  - `enum`, `readonly`, `named arguments`, `match`, `union types`
  - `intersection types`, `fibers`, `#[Attribute]`（テストファイルは例外）

## テスト方針

- 純粋関数（WordPress API を呼ばないもの）は unit test を書く
- WordPress 依存の関数は将来対応
- テストファイル: `tests/unit/`

## アーキテクチャ

主要関数（名前空間 `WpAgentFeed`）:

| 関数 | ファイル | 役割 | WP依存 |
|------|---------|------|--------|
| `serve_markdown()` | `wp-agent-feed.php` | Accept ヘッダー確認 → キャッシュ配信 | Yes |
| `on_save_post()` | `wp-agent-feed.php` | 保存時キャッシュ生成/削除 | Yes |
| `generate_cache()` | `wp-agent-feed.php` | Markdown ファイル生成 | Yes |
| `get_rendered_content()` | `wp-agent-feed.php` | the_content フィルター適用 | Yes |
| `build_frontmatter()` | `wp-agent-feed.php` | YAML フロントマター生成 | Yes |
| `get_description()` | `wp-agent-feed.php` | SEO メタ description 取得（フォールバック: 抜粋） | Yes |
| `is_overridden()` | `wp-agent-feed.php` | wp-config.php オーバーライド検出 | No |
| `cache_path()` | `wp-agent-feed.php` | キャッシュファイルパス生成 | No |
| `delete_cache()` | `wp-agent-feed.php` | キャッシュファイル削除 | No |
| `clear_all_cache()` | `wp-agent-feed.php` | 全キャッシュ .md ファイル削除 | No |
| `get_cache_stats()` | `wp-agent-feed.php` | キャッシュファイル数カウント | No |
| `validate_markdown_output()` | `wp-agent-feed.php` | Markdown 出力検証 | No |
| `check_github_update()` | `wp-agent-feed.php` | GitHub 自動更新チェック | Yes |
| `plugin_info()` | `wp-agent-feed.php` | プラグイン詳細モーダル情報提供 | Yes |
| `uninstall()` | `wp-agent-feed.php` | プラグイン削除時クリーンアップ | Yes |
| `html_to_markdown()` | `includes/markdown.php` | HTML → Markdown 変換 | No |
| `convert_table()` | `includes/markdown.php` | HTML table → Markdown table | No |
| `escape_yaml()` | `includes/markdown.php` | YAML 文字列エスケープ | No |
| `estimate_tokens()` | `includes/markdown.php` | トークン数推定 | No |
| `add_admin_menu()` | `includes/admin.php` | Settings メニューにページ追加 | Yes |
| `register_settings()` | `includes/admin.php` | Settings API 登録 | Yes |
| `sanitize_content_signal()` | `includes/admin.php` | Content-Signal サニタイズ | Yes |
| `sanitize_cache_control()` | `includes/admin.php` | Cache-Control サニタイズ | Yes |
| `sanitize_post_types()` | `includes/admin.php` | Post Types サニタイズ | Yes |
| `get_diagnostics_data()` | `includes/admin.php` | ステータス診断データ収集 | Yes |
| `render_status_panel()` | `includes/admin.php` | ステータスパネル描画 | Yes |
| `render_settings_page()` | `includes/admin.php` | 設定ページ全体描画 | Yes |
| `ajax_regenerate()` | `includes/admin.php` | AJAX キャッシュ一括再生成 | Yes |
| `ajax_clear()` | `includes/admin.php` | AJAX キャッシュ全削除 | Yes |
| `ajax_live_test()` | `includes/admin.php` | AJAX 出力検証テスト | Yes |
| `ajax_check_headers()` | `includes/admin.php` | AJAX HTTP ヘッダー確認 | Yes |

## 配布方式
- GitHub
タグをバージョンに対応させるためバージョン番号の変え忘れに注意。
