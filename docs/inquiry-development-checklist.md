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
