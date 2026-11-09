<?php
declare(strict_types=1);
function page(string $title,string $content,bool $login=false): never {
    $style=$login?'login':'dashboard';
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($title).'｜非公開開発｜HAYANEX</title><link rel="stylesheet" href="/assets/'.$style.'.css"></head><body><main class="'.($login?'shell':'layout').'"><header class="brand"><div><div class="logo">HAYANEX</div><div class="sub">スタッフ・受付管理のローカル検証</div></div></header><p class="notice"><strong>隔離された開発環境・本番公開禁止</strong>：架空データのみを使用してください。通知はローカル記録のみで、外部メールを送信しません。</p><h1>'.esc($title).'</h1>'.$content.'<footer class="foot">代表最終承認前・XServer未接続・実受付なし</footer></main></body></html>';exit;
}
function loginForm(string $error=''): never {
    page('スタッフログイン','<section class="panel"><div class="steps"><span class="step current">1. ID・パスワード</span><span class="step">2. MFA認証（対象者）</span></div><p class="intro">仮採用の画面構成を用いた接続検証です。代表者・管理者はMFA完了まで業務画面へ入れません。</p>'.($error?'<p role="alert">'.esc($error).'</p>':'').'<form method="post" action="/staff/login">'.hiddenCsrf().'<label for="login-id">スタッフID</label><input type="text" id="login-id" name="login" maxlength="100" required autocomplete="username"><label for="password">パスワード</label><input id="password" name="password" type="password" maxlength="1024" required autocomplete="current-password"><button type="submit">ログイン</button></form></section>',true);
}
function staffNav(array $s): string {
    $html='<nav class="controls" aria-label="スタッフメニュー"><a href="/staff">受付一覧</a>';
    if(in_array($s['role'],['owner','admin'],true))$html.='<a href="/staff/audit">監査履歴</a>';
    if($s['role']==='owner')$html.='<a href="/staff/accounts">スタッフ管理</a>';
    return $html.'<a href="/contact">架空問い合わせ</a><form action="/staff/logout" method="post">'.hiddenCsrf().'<button type="submit">ログアウト</button></form></nav><p class="muted">サーバーで確認した権限：'.esc(['owner'=>'代表者','admin'=>'管理者','staff'=>'スタッフ'][$s['role']]).'（画面で切り替えることはできません）</p>';
}
function statusLabel(string $s): string {return ['new'=>'新規','reviewing'=>'確認中','in_progress'=>'対応中','completed'=>'完了','on_hold'=>'保留'][$s]??'不明';}
function contactForm(string $error=''): never {
    $fields=['person_name'=>'架空のお名前（必須）','email'=>'架空の返信先（必須・@example.invalid のみ）','organization_name'=>'架空の会社・団体名（該当時）','phone'=>'架空の電話番号（任意）','budget'=>'予算の検証用文字列（任意）','desired_deadline'=>'納期の検証用文字列（任意）'];
    $limits=['person_name'=>50,'email'=>254,'organization_name'=>120,'phone'=>20,'budget'=>60,'desired_deadline'=>60];
    $form='<p>この私用環境のDBに架空データを登録します。公開サイトの非送信試作とは別です。実際の個人情報は入力しないでください。</p>'.($error?'<p role="alert">'.esc($error).'</p>':'').'<form method="post" action="/contact">'.hiddenCsrf().'<input type="hidden" name="request_token" value="'.esc($_SESSION['request_token']??=InquiryRequestGuard::issueToken()).'">';
    foreach(['inquiry_type'=>['お問い合わせ区分',['estimate'=>'見積もり','consult'=>'相談','other'=>'その他']],'client_type'=>['お客様区分',['person'=>'個人','corporate'=>'法人','group'=>'団体']],'service'=>['サービス',['web'=>'Webサイト','app'=>'システム','support'=>'事務代行','other'=>'その他']]] as $key=>[$label,$choices]) {
        $form.='<label for="'.$key.'">'.$label.'（必須）</label><select id="'.$key.'" name="'.$key.'" required>';foreach($choices as $v=>$text)$form.='<option value="'.$v.'">'.$text.'</option>';$form.='</select>';
    }
    foreach($fields as $key=>$label)$form.='<label for="'.$key.'">'.esc($label).'</label><input id="'.$key.'" name="'.$key.'" maxlength="'.$limits[$key].'" type="'.($key==='email'?'email':'text').'" '.(in_array($key,['person_name','email'],true)?'required':'').' autocomplete="off">';
    $form.='<label for="message">架空の相談内容（必須・2,000文字以内）</label><textarea id="message" name="message" maxlength="2000" rows="6" required></textarea><label class="check"><input type="checkbox" name="consent" value="yes" required>架空データだけをローカル検証DBに保存することを確認しました（正式な個人情報方針への同意取得ではありません）</label><button type="submit">開発DBに架空受付を登録する</button></form><p><a href="/staff/login">スタッフログインへ</a></p>';
    page('架空お問い合わせの統合検証',$form,true);
}
