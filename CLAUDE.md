# WP Agent Feed — 開発ルール

## プロジェクト概要

単一ファイル WordPress プラグイン（`wp-agent-feed.php`）。
`Accept: text/markdown` ヘッダー付きリクエストに対して投稿を Markdown で返す。

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

- 関数名: `waf_` prefix
- 定数名: `WAF_` prefix
- コーディングスタイル: WordPress Coding Standards（PHPCS で自動検証）

## レスポンスヘッダー契約

以下のヘッダーを壊さないこと:

- `Content-Type: text/markdown; charset=utf-8`
- `Vary: Accept`
- `X-Markdown-Tokens: {数値}`
- `Content-Signal: {WAF_CONTENT_SIGNAL}`

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

主要関数（`wp-agent-feed.php`）:

| 関数 | 役割 | WP依存 |
|------|------|--------|
| `waf_serve_markdown()` | Accept ヘッダー確認 → キャッシュ配信 | Yes |
| `waf_on_save_post()` | 保存時キャッシュ生成/削除 | Yes |
| `waf_generate_cache()` | Markdown ファイル生成 | Yes |
| `waf_get_rendered_content()` | the_content フィルター適用 | Yes |
| `waf_build_frontmatter()` | YAML フロントマター生成 | Yes |
| `waf_html_to_markdown()` | HTML → Markdown 変換 | No |
| `waf_convert_table()` | HTML table → Markdown table | No |
| `waf_cache_path()` | キャッシュファイルパス生成 | No |
| `waf_delete_cache()` | キャッシュファイル削除 | No |
| `waf_escape_yaml()` | YAML 文字列エスケープ | No |
| `waf_estimate_tokens()` | トークン数推定 | No |
