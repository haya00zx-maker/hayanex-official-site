# 2026年10月20日の補足

最新developは全11ページです。トップ・事業3件・料金・会社事務所・製品関連活動・FAQ・問い合わせ検証・法的下書き2件を対象に検証しています。具体的な結果はTEST-RESULTS.md、公開可否と除外方法はREVIEW.mdを参照してください。

このクラウド環境に外部公開用ポート機能はなく、既存の外部候補URLの開通も未確認です。新しい外部サービスの有効化・契約は行っていません。以下は10月19日時点の確認手順・経緯で、固定コミットとZIPは当時の3ページ版です。最新確認にはdevelop一式を使用してください。

---

# develop のデザイン確認

## 検証済みの状態

制作版コミット `3cd4fd94aef4e7b8c845ebe0fd9d098e63557039` をファイル一式として開発サーバーで配信しています。トップ・privacy.html・legal.html、共通CSS・JavaScript・WebP画像3件について、HTTP 200と正しいContent-Typeを確認しました。ブラウザーで3ページ×8画面幅・メニュー等26項目とaxe24回が合格しています。

このクラウド環境には、利用可能な外部公開URL・ポート公開機能がありません。内部開発サーバーのアドレスは、代表者のPC・スマートフォンからアクセスできる共有URLではありません。

## 外部での確認候補（この環境からの動作は未検証）

HTML単独表示サービスではなく、相対パスのCSS・JavaScript・画像を同じオリジンから適切なContent-Typeで配信する方法が必要です。現在のCSPは同一オリジンの読み込みを許可しています。プレビューサービスが別オリジンのファイルへ書き換える場合は制限対象になります。既存READMEから現在のサービスが `html-preview.github.io` と確認できました。サービス上での通信・コンソールの実測はできていないため、具体的な失敗リクエストと原因の断定は未実施です。

raw.githack はGitHubの静的ファイルを適切なContent-Typeで配信するサービスです（公式ソースREADME: https://github.com/neoascetic/rawgithack ）。本番GitHub Pagesの設定変更や有料サービスの導入は行いません。第三者配信サービスの可用性・表示・キャッシュはまだ確認できていません。

確認対象を固定する候補URL：

- https://raw.githack.com/haya00zx-maker/hayanex-official-site/3cd4fd94aef4e7b8c845ebe0fd9d098e63557039/index.html
- https://raw.githack.com/haya00zx-maker/hayanex-official-site/3cd4fd94aef4e7b8c845ebe0fd9d098e63557039/privacy.html
- https://raw.githack.com/haya00zx-maker/hayanex-official-site/3cd4fd94aef4e7b8c845ebe0fd9d098e63557039/legal.html

最新developを確認する候補URL（キャッシュの影響は未確認）：

https://raw.githack.com/haya00zx-maker/hayanex-official-site/develop/index.html

上記URLへの接続はクラウド側のネットワーク制限で拒否されました。正常表示を確認済みのURLとして扱っていません。検証用に `raw.githack.com` の許可を環境設定の下書きへ追加済みです。環境設定で確認・保存し、クラウド環境をPublishした後、接続と3ページ・全資産・PC/スマートフォン表示を再検証する必要があります。クラウド環境のPublishとWebサイトの本番公開は別です。

## 手元で確認する方法

GitHubのdevelopブランチから「Code → Download ZIP」で一式を取得し、すべて展開してください。HTMLだけをプレビューサイトへ渡さず、assetsフォルダーとの配置を保ちます。

Python 3がある場合、展開したindex.htmlのあるフォルダーで以下を実行すると静的サイト一式を配信できます。

```sh
python3 -m http.server 8000 --bind 127.0.0.1 --directory .
```

ブラウザーのアドレス欄に、HTTP方式・ホスト127.0.0.1・ポート8000・パス/index.htmlを指定します。これはコマンドを実行したPC上での確認方法で、スマートフォンとの共有URLではありません。ブラウザーの開発者ツールで幅375px・1440pxを切り替え、フッターから2つの下書きページへ移動してください。

通常のブラウザーで展開済みindex.htmlを直接開く方法もありますが、この管理環境ではfile方式のURLがブラウザーポリシーで拒否されるため、直接ファイル表示は未検証です。

外部共有が必要な場合は、利用可能な無料の静的サイトホスティング、または上記配信候補へのネットワーク許可を確認してから接続します。mainへのマージ、現行サイト・GitHub Pagesの設定変更は行いません。
