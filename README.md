# ハヤネクエンタープライズパートナーズ公式サイト

HAYANEX ENTERPRISE PARTNERS の静的サイトです。HTML・CSS・JavaScriptのみで構成し、アプリの依存パッケージ、ビルド、API、データベースは使用しません。

## ブランチ・公開方針

- `develop`：制作・検証。現在は最終承認前の確認版です。
- `main`：現行公開用。代表者の最終承認までマージ・本番公開・GitHub Pages設定変更をしません。
- 目標公開日：2026年11月15日（日本時間）。

## 代表審査用ブラウザプレビュー（外部サービス・暫定）

[develop版トップページをブラウザで開く](https://html-preview.github.io/?url=https://github.com/haya00zx-maker/hayanex-official-site/blob/develop/index.html)

- このリンクは公開用のGitHub Pagesではなく、外部のHTMLプレビューサービスを利用します。
- プレビューサービス側でGitHub上のHTML・CSS・JavaScript・画像を取得するため、実際のGitHub Pages配信とは動作条件が異なります。CSP等により一部の表示・操作が動作しない可能性があります。
- 個人情報・パスワードなどを入力しないでください。未承認の下書きは公開リポジトリから参照可能であり、このリンクはアクセス制限を提供しません。
- 表示に問題がある場合は、下記ローカルHTTPサーバーでの確認を優先してください。
- このリンクの追加はdevelop内の文書変更のみです。mainやGitHub Pagesの設定は変更しません。

## ローカル確認

既存のチェックアウトを使用してください。通常のクラウド作業で新たなGit worktreeは不要です。

```sh
cd /workspace/hayanex-official-site
python3 -m http.server 8000 --bind 127.0.0.1 --directory .
```

ブラウザーまたはローカルHTTPリクエストで確認します。このサーバーは開発用です。本番配信に使用しません。

## 構成

- `index.html`：トップ・全紹介セクション・FAQ・お問い合わせ案内
- `privacy.html` / `legal.html`：代表確認用下書き（未施行）
- `assets/css/site.css`：共通デザイン・レスポンシブ・印刷・動きを減らす設定
- `assets/js/site.js`：段階的なメニュー機能。JavaScript無効でもリンクを表示
- `assets/images/`：既存の正式ロゴから作成したWebP表示用画像
- `714E0BDF-C833-40D7-8F69-ECAA0CCF4B84.PNG`：ロゴ原本。削除・置換していません
- `tests/verify.cjs`：ブラウザーでの回帰確認
- `tests/performance.cjs`：ローカルでの描画・転送量・レイアウト移動確認
- `docs/REVIEW.md`：調査結果、承認事項、公開前チェック、公開手順
- `docs/TEST-RESULTS.md`：検証結果・制約

内部URLは相対パスです。GitHub Pagesのプロジェクト配下にも対応します。独自ドメインやPagesのURLは推測して設定しません。

## QAの再実行

Node.js・Chromium・Playwrightと、QA用axe-core/HTML Validateが必要です。ランタイム依存ではなく、サイトの配信にインストールは不要です。クラウド環境ではNode.js・Chromium・Playwrightが用意されています。QAパッケージはチェックアウト外へインストールします。

```sh
npm install --prefix /tmp/hayanex-qa --cache /tmp/hayanex-npm-cache --no-audit --no-fund --package-lock=false axe-core@4.10.3 html-validate@10.2.1
node --check assets/js/site.js
/tmp/hayanex-qa/node_modules/.bin/html-validate index.html privacy.html legal.html
AXE_CORE_PATH=/tmp/hayanex-qa/node_modules/axe-core/axe.min.js node tests/verify.cjs
node tests/performance.cjs
```

別の環境ではPlaywrightもQA用ディレクトリへインストールし、`NODE_PATH`をその`node_modules`へ設定します。Chromiumの場所は`CHROMIUM_PATH`で指定できます。ブラウザーのサンドボックスを利用できる通常の開発環境では、テスト内の`--no-sandbox`を外すことを検討してください。

`SITE_URL`で検証先、`QA_ARTIFACTS`で結果JSON・スクリーンショットの出力先を指定できます。デフォルト出力先は`/tmp/hayanex-review`です。テストは起動済みサーバーを必要とします。

## 未確定情報の取扱い

料金・契約・個人情報・決済・組織の位置づけ・連絡先は独断で確定しません。既存developにある関連活動・製品案は確認中として引き継いでいます。`docs/REVIEW.md`の承認一覧を確認してください。フォームは未設置で、受付・契約・決済ができるように見せる操作は実装していません。
