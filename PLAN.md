# WP Agent Feed — 不具合修正 & リファクタリング計画

## 不具合修正（Bug Fixes）

### Bug 1: `waf_escape_yaml()` がバックスラッシュをエスケープしていない
- **場所**: `wp-agent-feed.php:357-359`
- **問題**: YAML のダブルクォート文字列内ではバックスラッシュ `\` がエスケープ文字として解釈される。現状では `"` のみエスケープしており、入力にバックスラッシュが含まれると不正な YAML が生成される
- **例**: タイトルが `C:\Users\test` → YAML で `"C:\Users\test"` → `\U` と `\t` がエスケープシーケンスとして解釈される
- **修正**: バックスラッシュのエスケープを追加（`\` → `\\`、`"` より先に処理）

### Bug 2: `<ol start="N">` の `start` 属性が無視される
- **場所**: `wp-agent-feed.php:229-243`
- **問題**: 順序付きリストは常に 1 から番号付けされる。HTML の `<ol start="5">` を指定しても無視される
- **修正**: `start` 属性を正規表現で取得し、カウンタの初期値として使用

### Bug 3: ファイル I/O のエラーが無視されている
- **場所**: `wp-agent-feed.php:107-108, 111`
- **問題**: `file_put_contents()` の戻り値を確認していない。ディスクフル・パーミッションエラー等で失敗しても何も起きない
- **修正**: `waf_generate_cache()` で `file_put_contents` の失敗時に `error_log()` でエラーを記録

### Bug 4: トークン数がリクエスト毎に再計算される
- **場所**: `wp-agent-feed.php:54`
- **問題**: キャッシュ生成時にトークン数を計算・保存すれば、リクエスト時の計算を省略できる。現状は毎リクエストで `waf_estimate_tokens()` を呼んでいる
- **修正**: キャッシュ生成時にトークン数を `.tokens` ファイルに保存し、配信時はそれを読む

## リファクタリング（Refactoring）

### Refactor 1: `waf_html_to_markdown()` を機能別に分割
- **場所**: `wp-agent-feed.php:167-301`（134行、29個の正規表現）
- **問題**: 1つの関数に全変換ロジックが詰め込まれており、保守性が低い
- **方針**: 以下の関数に分割
  - `waf_convert_headings($md)` — 見出し h1-h6
  - `waf_convert_code_blocks($md)` — `<pre><code>` と `<pre>` 単体
  - `waf_convert_blockquotes($md)` — blockquote
  - `waf_convert_lists($md)` — ul / ol（Bug 2 の修正もここに含む）
  - `waf_convert_media($md)` — img / figure / figcaption
  - `waf_convert_links($md)` — a タグ
  - `waf_convert_inline($md)` — strong, em, code, del 等
  - `waf_cleanup_markdown($md)` — 残タグ除去、エンティティデコード、空行正規化
- `waf_html_to_markdown()` はこれらを順番に呼ぶオーケストレーターになる

### Refactor 2: インライン要素の変換をデータ駆動型にする
- **場所**: `wp-agent-feed.php:270-275`
- **問題**: 同じパターンの正規表現が6回繰り返されている
- **方針**: タグ名とMarkdown記号のマッピング配列を使ったループに変更
  ```php
  $map = [ 'strong' => '**', 'b' => '**', 'em' => '*', 'i' => '*', 'code' => '`', 'del' => '~~' ];
  foreach ( $map as $tag => $wrap ) {
      $md = preg_replace( "/<{$tag}[^>]*>(.*?)<\/{$tag}>/si", "{$wrap}\$1{$wrap}", $md );
  }
  ```

## 対象外（今回は修正しない）

- ネストされたリスト/blockquote の完全対応（正規表現ベースの限界）
- クラスベースのアーキテクチャへの移行（単一ファイルプラグインとしての設計意図を維持）
- テストスイートの追加（WordPress テスト環境が必要）
- `$_SERVER['HTTP_ACCEPT']` のサニタイズ（`strpos` 比較のみなので実害なし）

## 実装順序

1. Bug 1（YAML エスケープ） — 1箇所の修正
2. Bug 2（ol start 属性） — リスト変換の修正
3. Refactor 1（関数分割） — `waf_html_to_markdown()` のリファクタリング
4. Refactor 2（インライン要素） — Refactor 1 の一部として実施
5. Bug 3（エラーハンドリング） — ファイル I/O の改善
6. Bug 4（トークンキャッシュ） — キャッシュ構造の変更
