# お問い合わせ管理システム：DB統合テスト計画（実行前レビュー用）

作成日：2026-10-27  
状態：**計画作成済み／書き込みテスト未実施**  
対象：GitHub `develop` の `dev-only/inquiry/InquiryRepository.php` および `docs/inquiry-dev-schema.sql`

## 目的・実施条件

XServer非公開ディレクトリのみでPHP 8.3 CLIを実行し、架空情報の問い合わせ登録、受付番号、冪等性、通知Outbox、ロールバックを検証する。本番用URL、Web公開ルート、`main`、実際の問い合わせ、メール送信を使用しない。

**重要：最初の書き込みテストは現在の `hayanex_inquirydev` ではなく、別途作成した専用の使い捨てDBで実行する。** 現行DBの4テーブルで0件を確認したことは、以後も空である保証にならない。ほかの5テーブルの登録状況も未確認。使い捨てDBは独立したユーザー/権限で接続する。DB作成および書込みテストは別工程とし、実行前に管理者が対象名を確認する。

DB資格情報・実顧客データ・SSH秘密鍵・HMACシークレットはGitHub、ログ、コマンド履歴、スクリーンショットへ載せない。接続設定は実行時に安全に渡し、実行後に破棄する。テストデータは架空の例示のみ。

## 静的レビュー（コード・DDL照合）

- `inquiries`: `receipt_no` UNIQUE、`status` default `new`、`consent_at` 必須、InnoDB、UTF-8 `utf8mb4` を確認。
- `inquiry_sequences`: `received_day` PRIMARY KEY、`last_number` を確認。
- `inquiry_requests`: `request_key` PRIMARY KEY、`payload_hash` と `inquiry_id` を確認。
- `notification_outbox`: `inquiry_id`、`notification_type` enum(`customer_confirmation`,`staff_alert`)、2種別を一意にする複合UNIQUEをスキーマで確認。実DBのユニーク・外部キー制約は別途 `SHOW CREATE TABLE` で照合する。
- PHPの `register()` は同一トランザクションに問い合わせ、リクエストキー、Outbox2件を登録する意図。実際の動作は未確認。
- 注意点：日次受付番号は `%04d` で下限幅4桁、上限4桁の制約ではない。大量受付時の表示仕様・競合を別途検証。
- 注意点：空文字とNULL、DBデフォルト、時刻・タイムゾーンの整合を統合テストで確認。
- `InquiryRateLimiter` はDB書込みを伴う別コンポーネントであり、本テストの対象外。別途テストする。

## テストケース（全て未実施）

| ID | 入力/操作 | 期待結果 |
|---|---|---|
| DB-01 | 同意済み・正常な架空問い合わせを1件登録 | 受付番号1件、`inquiries` 1件、`inquiry_requests` 1件、Outbox 2件、初期状態 `new` |
| DB-02 | DB-01と同じキー・同じ内容を再送 | 同一受付番号、件数増加なし、Outbox重複なし |
| DB-03 | 同じキー・異なる内容を再送 | 拒否され、既存データ変更なし |
| DB-04 | 同意なし／不正なキー・フィールドで登録要求 | 登録拒否、DBに副作用なし |
| DB-05 | 意図的な制約違反で途中処理を失敗させる | 挿入予定の関連データはロールバックされ、部分登録なし |
| DB-06 | 同時に異なるキーで複数要求 | 重複しない受付番号、整合した採番と件数 |
| DB-07 | Outboxの二種類に関する制約を検証 | 1受付につき期待する2種別のみ、重複なし、送信処理は起動しない |

## 実行前ゲート

1. 使い捨てテストDB名・専用認証情報を確認。DB名に `dev` または `test` を含め、既存DBを対象にしない安全装置を設ける。
2. `docs/inquiry-dev-schema.sql` に基づくテーブルを使い捨てDBに作成し、照合する。DBの自動初期化や既存DBのDROP/TRUNCATEはしない。
3. テスト実行器に「明示的なテスト実行フラグ」「DB名チェック」「テスト開始時にレコード0件確認」を実装。ひとつでも満たさなければ書込みをしない。
4. テスト終了後の架空データの後片付け方法を**テストDBだけ**に限定して定義。トランザクション内で `InquiryRepository::register()` が独自commitするため、呼び出し側の単純なrollbackでは元に戻らない点に注意。
5. 登録・検証・後片付けのログに、パスワード・個人情報・秘密鍵を出さない。失敗時も本番や既存開発DBに波及しないことを確認。
6. 書込みテスト実行後に、実際の出力をPASS/FAILで記録する。単体テスト成功をDB統合テスト成功と表現しない。

## 現状

2026-10-27のXServerで、SSH、PHP 8.3、PDO接続、9テーブル存在、6種類のオフライン単体テストPASS、PHP構文検査PASS、読み取り専用DB事前診断PASSまで確認済み。

**DB-01〜DB-07はまだ実行していない。** 管理画面、HTTP送信、実メール通知、添付、監査、MFA連携、本番公開も未承認・未試験。

## 準備済みテストスクリプト（2026-10-27、未実行）

- `dev-only/inquiry/tests/isolated_db_smoke_test.php` を `develop` に追加した。
- 明示的な `HAYANEX_TEST_WRITE_ENABLE=YES_DISPOSABLE_DB_ONLY`、DB名が `_test` で終わること、既存 `hayanex_inquirydev` でないこと、対象4テーブルが空であることを実行前に確認する。
- **このプログラムはINSERTを行う**。隔離専用テストDBが作成されるまで実行しない。実行後は架空の1件と未送信Outbox2件が残り、後片付けは別手順。
- DB-01〜DB-04の基本ケースを部分検証し、DB-05（ロールバック）・DB-06（並列）・DB-07（DB制約の単独試験）は未対応。
- DBパスワード・SSH鍵はソースにも管理台帳にも保存しない。まだXServer側に新しいDBを作成していない。
