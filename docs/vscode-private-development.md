# Visual Studio Codeでの非公開開発（ハヤネク公式サイト）

このリポジトリでは、**`develop` で開発し、`main` は代表承認なしに変更しません**。

## ブラウザ・VS Codeだけで進める方法

1. VS CodeでGitHubリポジトリ `haya00zx-maker/hayanex-official-site` を開きます（ローカルGitまたはGitHub Repositories拡張機能）。
2. 作業ブランチが必ず `develop` であることを確認します。既に変更済みのファイルは、VS Codeのソース管理からプル／同期します。
3. ローカルにPHP CLIがある場合は、**ターミナル > タスクの実行** から `HAYANEX: PHP offline tests` を選択します。タスクはWindows PowerShellコマンドを直接書かず、PHPプロセスを起動します。
4. `php` コマンドが見つからない場合、ローカルPHP CLIの導入・PATH設定が必要です。GitHub上のファイル編集だけならPHPは不要です。
5. 結果に `FAIL` または `INCOMPLETE` があれば、合格扱いにしません。PHP拡張 `mbstring` が不足していると検査が不完全になります。

## 現在のログイン認証開発について

`StaffCredentialRepository.php` はパスワード検証、`StaffLoginFlow.php` はパスワードからMFAへの遷移、`StaffTotp.php` と `StaffMfaCrypto.php` はMFA処理、`StaffSessionPolicy.php` はサーバーセッション判定の部品です。

**現時点ではこれらを公開HTTPエンドポイントに接続していません。** 実運用にはCSRF対策、HTTPS強制、Cookie属性・セッションID再発行、認証試行回数制限、MFA暗号鍵のサーバー側安全管理、DB上の認証カウンター原子的更新、ログアウト、監査ログ、実ブラウザの総合検証が必要です。

`dev-only` のテストコードや秘密情報を公開サーバーのWebルートに置かないでください。実在のパスワード・DB接続情報・MFA秘密鍵をGitHubへコミットしないでください。

## XServerを使わずにできること

- HTML・CSS・JavaScript・PHPの編集とGitHub保存
- ローカルPHP構文検査、オフラインテスト（PHP実行環境がある場合）
- 画面デザイン、アクセシビリティ、レビュー資料作成

## XServer側が必要なこと

- XServer上のPHP・DB・メール・Cookie・HTTPSの実環境テスト
- サーバーの非公開設定と動作確認
- 代表承認後の本番反映（それまでは禁止）
