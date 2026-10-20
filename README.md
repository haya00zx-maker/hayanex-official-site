# ハヤネクエンタープライズパートナーズ公式サイト

HAYANEX ENTERPRISE PARTNERS の静的サイトです。HTML・CSS・JavaScriptのみで構成し、アプリの依存パッケージ、ビルド、API、データベースは使用しません。

## ブランチ・公開方針

- `develop`：制作・検証。現在は最終承認前の確認版です。
- `main`：現行公開用。代表者の最終承認までマージ・本番公開・GitHub Pages設定変更をしません。
- 目標公開日：2026年11月15日（日本時間）。

## 代表審査用ブラウザプレビュー（外部サービス・暫定）

[従来のHTMLプレビュー（CSS・画像の表示不具合を報告済み）](https://html-preview.github.io/?url=https://github.com/haya00zx-maker/hayanex-official-site/blob/develop/index.html)

- このリンクは公開用のGitHub Pagesではなく、外部のHTMLプレビューサービスを利用します。
- プレビューサービス側でGitHub上のHTML・CSS・JavaScript・画像を取得するため、実際のGitHub Pages配信とは動作条件が異なります。CSP等により一部の表示・操作が動作しない可能性があります。
- 個人情報・パスワードなどを入力しないでください。未承認の下書きは公開リポジトリから参照可能であり、このリンクはアクセス制限を提供しません。
- 表示に問題がある場合は、下記ローカルHTTPサーバーでの確認を優先してください。
- このリンクの追加はdevelop内の文書変更のみです。mainやGitHub Pagesの設定は変更しません。

外部共有URLは正常表示の検証が完了していません。ファイル一式の配信での検証結果、別の配信サービスの候補URL、必要なネットワーク設定は [プレビュー確認手順](docs/PREVIEW.md) を参照してください。

## ローカル確認

既存のチェックアウトを使用してください。通常のクラウド作業で新たなGit worktreeは不要です。

```sh
cd /workspace/hayanex-official-site
python3 -m http.server 8000 --bind 127.0.0.1 --directory .
```

ブラウザーまたはローカルHTTPリクエストで確認します。このサーバーは開発用です。本番配信に使用しません。

## 構成

- `index.html`：トップ要約、3事業・6プラン・6ステップ、詳細ページへの導線
- `business-web.html` / `business-system.html` / `business-support.html`：3事業の詳細
- `pricing.html`：6プラン・支払方針・未確定の契約事項
- `about.html` / `activities.html` / `faq.html`：会社・事務所、製品・関連活動、FAQ
- `contact.html`：架空データによる入力確認。通信・保存・実送信なし
- `privacy.html` / `legal.html`：代表確認用下書き（未施行）
- `assets/css/site.css`：共通デザイン、レスポンシブ、印刷、動きを減らす設定
- `assets/js/navigation-boot.js` / `site.js`：描画前のメニュー初期化と操作。無効・読込失敗時はリンクを表示
- `assets/js/contact-demo.js`：必須・メール・文字数の確認と入力消去
- `assets/images/`：既存ロゴのWebP表示用。PNG原本はそのまま保持
- `tests/`：全11ページのブラウザー回帰、ページ別性能、メニュー遅延・失敗の確認
- `docs/REVIEW.md`：公開候補・公開不可機能・未確定事項・承認と除外手順
- `docs/TEST-RESULTS.md` / `docs/WORKLOG.md`：検証結果と作業記録
- `docs/CONTACT-BACKEND-DRAFT.md`：本番受付の設計案・未承認事項

内部URLは相対パスで、GitHub Pagesのプロジェクト配下に対応する構成です。正式URLやドメインは推測して設定していません。全11ページは検索対象外の制作版です。noindexは閲覧制限ではありません。

## QAの再実行

Node.js・Chromium・Playwrightと、QA用axe-core/HTML Validateが必要です。ランタイム依存ではなく、サイトの配信にインストールは不要です。クラウド環境ではNode.js・Chromium・Playwrightが用意されています。QAパッケージはチェックアウト外へインストールします。

```sh
npm install --prefix /tmp/hayanex-qa --cache /tmp/hayanex-npm-cache --no-audit --no-fund --package-lock=false axe-core@4.10.3 html-validate@10.2.1
node --check assets/js/site.js
node --check assets/js/navigation-boot.js
node --check assets/js/contact-demo.js
/tmp/hayanex-qa/node_modules/.bin/html-validate "*.html"
AXE_CORE_PATH=/tmp/hayanex-qa/node_modules/axe-core/axe.min.js node tests/verify.cjs
node tests/performance.cjs
node tests/navigation-loading.cjs
# 親ディレクトリ配信サーバーを別途起動した場合
PROJECT_URL=http://127.0.0.1:8011/hayanex-official-site/ node tests/project-path.cjs
```

別の環境ではPlaywrightもQA用ディレクトリへインストールし、`NODE_PATH`をその`node_modules`へ設定します。Chromiumの場所は`CHROMIUM_PATH`で指定できます。ブラウザーのサンドボックスを利用できる通常の開発環境では、テスト内の`--no-sandbox`を外すことを検討してください。

`SITE_URL`で検証先、`QA_ARTIFACTS`で結果JSON・スクリーンショットの出力先を指定できます。デフォルト出力先は`/tmp/hayanex-review`です。テストは起動済みサーバーを必要とします。

## 未確定情報の取扱い

料金・契約・個人情報・決済・組織の位置づけ・連絡先は独断で確定しません。既存developにある関連活動・製品案は確認中として引き継いでいます。`docs/REVIEW.md`の承認一覧を確認してください。入力確認画面は検証用途のみで、本番送信・受付・契約・決済は実装していません。公開する際は検証画面を正式な受付案内に差し替えるか、ページと導線を除外します。
