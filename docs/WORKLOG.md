# 開発作業ログ

## 2026年10月20日：指示書に基づく整備

- 指示：`CODEX-DEVELOPMENT-BRIEF-2026-10-20.md`。目標日は2026年11月15日、日付による自動公開なし。
- 着手：develop `92ba6e3`、ローカル変更なし。mainの読み取り確認は`3716f3a`。既存コミットとPNG原本を保持。
- 調査：既存7ページ、料金の主見出し欠落、詳細ページの共通導線不足、3ページのみだった従来テストを確認。
- 実装：トップの要約化、3事業の詳細と6プラン、会社事務所・製品関連活動・FAQ・非送信入力確認の追加、法的下書き整備。全11ページ。
- 不具合対応：コントラスト、メニューの最小幅、遅延JSのレイアウト移動。未確定の価格・住所・SNS・組織関係は補完せず。
- 入力確認：架空サンプル、必須/メール/文字数、エラー/完了、消去。通信・保存・実受付なし。本番バックエンド設計案は未承認資料として保存。
- コミット`b86c580`：基本ページ・共通導線・送信しない入力確認。
- コミット`a582d19`：全11ページの回帰・性能・メニュー読込・プロジェクトパスのテスト。
- 検証：91項目、axe145回で違反0件、性能22組、プロジェクト配下22組、遅延と失敗2ケース、HTML/JS/差分検証が合格。詳細はTEST-RESULTS.md。
- 資料：REVIEW.mdで公開候補・公開不可機能・承認事項・除外方法を整理。READMEとPREVIEW.mdを現状へ更新。
- 保留：本番受付、実機・Safari/Firefox・支援技術、外部プレビュー、正式SEO情報、営業・法的判断、最終公開承認。代表審査第1〜49号の全文照合も未完了。
- 実施しなかった操作：mainへのマージ、Pages設定、DNS/ドメイン、本番公開、課金、サービス有効化、秘密情報追加、実メール・実決済・本番収集。

現在の段階は代表最終審査待ちです。公開可否の判断はREVIEW.mdに従い、別途の明示的承認が得られるまで公開操作を行いません。

## 2026年11月9日：非公開HTTPS/DB統合と公開審査準備

- 基点：develop `d8a1eff`、main `3716f3a`。main、公開設定、DNS、XServer、本番データは未操作。
- 初回調査とロードマップを報告し、PHP未導入の環境を公式イメージの隔離コンテナで準備。
- `b68badb`：認証、パスワード境界、MFA、原子的な制限、セッション、DB検証の補強。
- `603789b`：ループバックHTTPS・固定の使い捨てDBで、受付・管理UI・通知sink・障害/復旧を結合。
- `41f1a82`：非送信試作フォームと公開範囲検査・最新サイト回帰を補強。
- 既存13を再現後、現在は追加を含む14オフライン、追加DB方針検査、既存DB6試験が合格。
- HTTPS/DB64項目、DB接続拒否と復旧、通知停止、12テーブルの復元チェックサム一致、私用7画面×4幅のHTML/axe/操作/200％が合格。
- サイト11ページの91項目・axe145回、性能22組、配信ガード23ケース、静的検査が合格。
- 最終PHP構文は51ファイル、JS構文/Python AST/静的HTML/差分を確認。アプリ・コード・設定はread-onlyで、セッションだけを書込可能にした実行構成でも統合試験を再実行。
- 検出した失敗と補正：DBソケット・試験前提・初回採番の期待値、正規POSTのOrigin=null、私用画面のコントラスト/拡大、入力の意味、資格情報の結合。検査ハーネスのCookie/HTML応答原文/終了時処理も修正し、検査を無効化して通していない。Dockerの停止直後の自動削除待ち競合は、停止済みコンテナの待機を追加して解消。
- 設定・MFA種・資格情報・秘密鍵・SQLダンプはチェックアウト外。実データや外部メール、実課金は使用しない。
- 本番用接続、実配送、MFA登録/復旧/鍵運用、法的・個人情報、実機、XServerの個別作業・最終公開承認は残課題。詳細はDEVELOPMENT-REPORT-2026-11-09.md。

### 今回の変更ファイル一覧

- `README.md`
- `assets/js/contact-prototype.js`
- `contact.html`
- `dev-only/inquiry/DevNotificationWorker.php`
- `dev-only/inquiry/InquiryValidator.php`
- `dev-only/inquiry/StaffAuth.php`
- `dev-only/inquiry/StaffCredentialRepository.php`
- `dev-only/inquiry/StaffLoginLimiter.php`
- `dev-only/inquiry/StaffMfaVerifier.php`
- `dev-only/inquiry/StaffSessionStorage.php`
- `dev-only/inquiry/tests/isolated_db_parallel_test.php`
- `dev-only/inquiry/tests/local_components_test.php`
- `dev-only/inquiry/tests/run_offline_tests.php`
- `dev-only/inquiry/tests/session_failure_test.php`
- `dev-only/inquiry/tests/staff_auth_test.php`
- `dev-only/inquiry/tests/validator_test.php`
- `dev-only/private-app/bootstrap.php`
- `dev-only/private-app/public/dashboard.css`
- `dev-only/private-app/public/login.css`
- `dev-only/private-app/router.php`
- `dev-only/private-app/tests/browser.cjs`
- `dev-only/private-app/tests/failure.cjs`
- `dev-only/private-app/tests/http.cjs`
- `dev-only/private-app/views.php`
- `dev-only/runtime/.dockerignore`
- `dev-only/runtime/.gitignore`
- `dev-only/runtime/Dockerfile`
- `dev-only/runtime/README.md`
- `dev-only/runtime/local.py`
- `dev-only/runtime/schema-extension.sql`
- `dev-only/runtime/seed-http.php`
- `dev-only/runtime/support.php`
- `dev-only/runtime/tls-gateway.cjs`
- `dev-only/staff-preview/README.md`
- `dev-only/tests/check-release-scope.js`
- `dev-only/tests/test-release-scope.js`
- `docs/DEVELOPMENT-REPORT-2026-11-09.md`
- `docs/LEGAL-REVIEW-DRAFT-2026-11-09.md`
- `docs/OPERATIONS-RECOVERY-DRAFT.md`
- `docs/PRODUCTION-CHANGE-PROPOSAL.md`
- `docs/PROJECT-ROADMAP-2026-11-09.md`
- `docs/REVIEW.md`
- `docs/TEST-RESULTS.md`
- `docs/WORKLOG.md`
- `docs/inquiry-development-checklist.md`
- `tests/verify.cjs`
