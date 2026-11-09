# 隔離ローカル統合環境（本番使用禁止）

XServerへ接続せず、DockerローカルソケットのPHP 8.3.36/MariaDB 11.4.13で再現します。コードはread-only、Web/TLSはループバックのみ。公開サイト・main・既存DBに適用しません。

## 準備

Python 3、Node.js、OpenSSL、管理されたローカルDockerを使用します。環境の`DOCKER_CONFIG`やレジストリ資格情報は変更せず、宛先・TLSの上書き変数を除いてローカルソケットに固定します。イメージは公式配布のdigestを固定しています。ビルドは環境のCA bundleを一時mountし、TLS・APT署名検証を保持します。

リポジトリ直下で：

```sh
python3 dev-only/runtime/local.py build
python3 dev-only/runtime/local.py prepare
python3 dev-only/runtime/local.py test-db
```

`prepare`は今回所有する使い捨ての`hayanex_http_test`を初期化します。`test-db`は専用の`hayanex_test`を作り直し、13オフラインスイート・追加のDB制限試験・既存DB6試験を順番に実行します。既存のXServer開発DB`hayanex_inquirydev`には接続しません。私用PHPコンテナが稼働中は`prepare`を拒否します。

状態は既定で`/tmp/hayanex-private-20261109`。`HAYANEX_DEV_RUNTIME`で別の/tmp配下を指定できますが、固定コンテナ名とポートを使うため同時に複数環境は起動しません。既存コンテナのmount先が異なる場合は再利用を拒否します。

## 起動とHTTP試験

セッションA：

```sh
python3 dev-only/runtime/local.py serve
```

セッションB：

```sh
node dev-only/runtime/tls-gateway.cjs /tmp/hayanex-private-20261109
```

HTTPSはループバック24443、PHPへの内部HTTPはループバック18880にのみ公開します。ブラウザー・HTTPテストはこのローカルTLSを通します。外部共有URLではありません。PHPは固定の検証DB、ゲートの秘密値、期待するpeerを要求し、任意のForwardedヘッダーや直接HTTPでは利用できません。これは本番用TLS設定の代替ではありません。

セッションCで、以下を**直列に**実行してください（架空アカウントやMFAカウンターを変更するため）：

```sh
python3 dev-only/runtime/local.py reset-http
node dev-only/private-app/tests/http.cjs
node dev-only/private-app/tests/failure.cjs
AXE_CORE_PATH=/tmp/hayanex-qa/node_modules/axe-core/axe.min.js node dev-only/private-app/tests/browser.cjs
```

HTTP試験は生成した証明書をCAとして検証します。ブラウザー試験は、そのローカル証明書の公開鍵だけを信頼するpinを使います。全証明書の検証を無効にしません。実在する資格情報や顧客情報は使用しません。

`reset-http`は明示的な試験用リセットです。HTTP試験で役割変更・失効・通知・並列制限等を実際に操作し、ブラウザー試験では架空MFAカウンターをリセットします。これらの試験用操作を本番運用へ持ち込みません。

## 私用画面

同じ開発マシンのHTTPS入口から、`/contact`（架空受付）、`/staff/login`、`/staff/mfa`、`/staff`、案件詳細、監査、スタッフ管理を確認します。ログインID・パスワード・MFA種は生成された`fixtures.json`にのみ保持し、チャットやGitHubへ貼り付けません。本番アカウントの発行機能ではありません。

通知はDBのローカルsinkに記録するだけで`simulated`となります。外部メールや課金サービスは一切有効化しません。ファイル添付、実配送、実アカウント発行/MFA登録・復旧、正式な同意取得は残作業です。

## バックアップ・障害・後片付け

```sh
python3 dev-only/runtime/local.py backup-check
```

上記は架空DBを別の使い捨てDBへ復元し、12テーブルのチェックサムを比較します。ダンプ・鍵・env・セッションはチェックアウト外。`lock-db`/`unlock-db`は同じコンテナの試験ユーザーを接続拒否/解除する障害試験専用操作です。

審査終了後は起動したNode/PHPのセッションを終了し、今回作成したコンテナ名を確認して、PHP、DBの順に停止します。DBを破棄する場合はそのコンテナだけを`docker rm -v`で削除し、チェックアウト外のランタイム状態を適切に削除します。汎用のpruneや他コンテナの停止はしません。実データ・本番鍵・実バックアップをこの環境へ持ち込まないでください。
