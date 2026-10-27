# お問い合わせ受付管理システム：開発・検証チェックリスト

状態：非公開開発中。本番公開は代表最終承認まで禁止。

## コード配置の重要な注意

- `dev-only/inquiry/` は**公開Webルートにアップロードしない**。PHPライブラリはドキュメントルート外に配置する。
- `docs/inquiry-dev-schema.sql` は設計ファイルであり、2026-10-27時点で非公開の開発DB `hayanex_inquirydev` には必要9テーブルの存在を確認済み。本番DBには適用しない。
- `contact.html` は試作画面。送信・メール・実DBの接続は一切行わない。
- GitHubリポジトリは公開アクセスされ得る。認証情報、実在顧客データ、鍵、.env、実バックアップを絶対にコミットしない。
- 開発用DBと本番DBを分離する。架空データのみ使用する。
- XServerに適用する前に、PHPバージョン・mbstring・pdo_mysql・MySQL/MariaDB利用可否を確認する。

## 単体試験コマンド（PHP CLI）

```sh
php -l dev-only/inquiry/InquiryValidator.php
php -l dev-only/inquiry/InquiryRepository.php
php -l dev-only/inquiry/InquiryRequestGuard.php
php -l dev-only/inquiry/InquiryRateLimiter.php
php dev-only/inquiry/tests/validator_test.php
php dev-only/inquiry/tests/request_guard_test.php
```

## DB統合試験で確認する事項

1. テストDBへの接続に `PDO::ERRMODE_EXCEPTION` を設定し、コードとスキーマを照合する。
2. テスト用新規受付：DB、採番、Outbox2件が同時コミットされること。
3. 同じ送信キー・同じ内容：同一受付番号を返し、二重登録しないこと。
4. 同じ送信キー・異なる内容：拒否すること。
5. 並列受付時に重複採番しないことと、トランザクションのロールバック。
6. 送信回数制限が上限を超えた要求を拒否し、DB障害時も受付を許可しないこと。
7. CSRFトークンとHMAC鍵をHTTP境界で管理し、秘密鍵をリポジトリに置かないこと。
8. メール・添付・スタッフ認証・権限制御・監査・バックアップ復元は未完成として公開禁止。

## 現在の未完了事項

- PHP validator単体試験：mbstringを利用できるPHP環境で実行必要。
- DB統合試験：開発DB接続および9テーブルの存在確認は完了。書込み・冪等性・並列処理・権限等の結合試験は未実施。
- 管理画面・多要素認証・通知送信・添付スキャン・復元テストは未実装。
- 個人情報取扱いの正式文面・代表最終公開承認が必要。

実施したテストと未実施のテストを混同しない。日次作業報告で根拠を追跡する。

## 2026-10-27 XServer実機確認記録（画面表示による確認）

- SSHログイン：`hayanex@sv17285.xserver.jp` / ポート `10022` の接続に成功し、作業後にログアウト済み。
- Web公開用PHPの設定変更は行っていない。CLIのデフォルト `php` は8.0.30だが、`/usr/bin/php8.3 -v` は **PHP 8.3.36** を返した。以下の検査コマンドでは必ずPHP 8.3を明示する。
- PHP 8.3 CLIで `pdo_mysql`・`mbstring`・`sodium`・`json` がすべて `OK`。
- 開発DB：`hayanex_inquirydev`、DBユーザー：`hayanex_0512`。MariaDB CLIログインに成功し、`SHOW TABLES;` で必要9テーブルの存在を確認。
- PHP 8.3 CLIからPDOによる開発DB接続確認が `PHP DB CONNECTION: OK`。接続確認のみで、書き込み試験は実行していない。
- SSH用秘密鍵・DBパスワードはGitHubに記録しない。ユーザー方針に従い、秘密鍵は暗号化されたバックアップをGoogleドライブで保管、SSH作業時のみ端末上に一時展開する。端末の一時展開物の削除状況は別途確認が必要。

### 単体試験手順（2026-10-27実施済み、再実行用）

**非公開の開発用実行環境のみ**で、`dev-only/inquiry/` 全体を公開Webルートの外に配置し、実行前にコードが既存状態と一致することを確認する。

```sh
/usr/bin/php8.3 -l dev-only/inquiry/InquiryValidator.php
/usr/bin/php8.3 -l dev-only/inquiry/InquiryRequestGuard.php
/usr/bin/php8.3 dev-only/inquiry/tests/validator_test.php
/usr/bin/php8.3 dev-only/inquiry/tests/request_guard_test.php
```

各テスト結果の `PASS/FAIL` を記録する。各個別コマンドの実行結果は別途記録する。一括テストは以下の通り実施済み。書き込みを伴うDB統合試験は別の事前承認・安全設計を経て実施する。現行の公開サイト、`main`、DNS、実際の問い合わせ受付を変更しない。

## 2026-10-27 オフライン単体試験実行結果（SSH出力で確認）

- 実行環境：XServer `sv17285`、非公開フォルダ `/home/hayanex/hayanex-dev-tests/source`、PHP CLI `/usr/bin/php8.3`。
- 実行コマンド：`/usr/bin/php8.3 /home/hayanex/hayanex-dev-tests/source/dev-only/inquiry/tests/run_offline_tests.php`
- 結果：`validator_test.php`、`request_guard_test.php`、`staff_permissions_test.php`、`staff_auth_test.php`、`staff_totp_test.php`、`staff_inquiry_status_test.php` の **6スイートすべて PASS**。
- 最終出力：`PASS: all offline suites passed. DB and HTTP integration remain untested.`
- この結果はユーザー提示のSSH端末出力で確認。ファイル変更・受付送信・DB書込み試験は行っていない。
- 残作業：DB統合（書込み・トランザクション・二重送信・並列性）、HTTP実接続、管理UI、認可/MFA結合、メール・添付・復元・セキュリティテスト。代表承認まで公開禁止。

## 2026-10-27 読み取り専用DB事前診断（実機結果）

- 実行：`/usr/bin/php8.3 /home/hayanex/hayanex-dev-tests/source/dev-only/inquiry/tests/db_preflight.php`、環境変数は実行時のみ設定、パスワード変数は実行後にunset。
- 結果：`PASS: isolated development database connection and expected table names`。
- 注記：`NOTE: this is a read-only preflight, not a CRUD, auth, MFA, or concurrency test`。
- 開発用DB接続と9テーブル存在の確認に限る。実際の受付INSERT、二重登録、採番、同時実行、通知送信は未試験。
- 次工程：既存実装・SQLスキーマのレビューを済ませ、テストデータのみの隔離DB統合テストを準備する。無断で本番反映しない。
