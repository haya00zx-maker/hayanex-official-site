# お問い合わせ受付管理システム：開発・検証チェックリスト

状態：非公開開発中。本番公開は代表最終承認まで禁止。

## コード配置の重要な注意

- `dev-only/inquiry/` は**公開Webルートにアップロードしない**。PHPライブラリはドキュメントルート外に配置する。
- `docs/inquiry-dev-schema.sql` は設計ファイルであり、DBにはまだ適用していない。
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
- DB統合試験：MySQL/MariaDB接続設定と非公開開発DBが必要。
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

### 次に実施する単体試験（未実施）

**非公開の開発用実行環境のみ**で、`dev-only/inquiry/` 全体を公開Webルートの外に配置し、実行前にコードが既存状態と一致することを確認する。

```sh
/usr/bin/php8.3 -l dev-only/inquiry/InquiryValidator.php
/usr/bin/php8.3 -l dev-only/inquiry/InquiryRequestGuard.php
/usr/bin/php8.3 dev-only/inquiry/tests/validator_test.php
/usr/bin/php8.3 dev-only/inquiry/tests/request_guard_test.php
```

各テスト結果の `PASS/FAIL` を記録する。**まだこれらのテストを実行したとは扱わない**。書き込みを伴うDB統合試験は別の事前承認・安全設計を経て実施する。現行の公開サイト、`main`、DNS、実際の問い合わせ受付を変更しない。
