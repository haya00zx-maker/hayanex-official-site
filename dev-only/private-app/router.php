<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/views.php';
try {
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $method=$_SERVER['REQUEST_METHOD'];
    if(in_array($path,['/assets/login.css','/assets/dashboard.css'],true)&&$method==='GET') {
        header('Content-Type: text/css; charset=UTF-8');readfile(__DIR__.'/public/'.basename($path));exit;
    }
    if($path==='/favicon.ico') {http_response_code(204);exit;}
    ini_set('session.save_path',(string)getenv('HAYANEX_DEV_SESSION_DIR'));
    StaffSessionStorage::start(true);
    if($path==='/'&&$method==='GET'){header('Location: /contact',true,303);exit;}
    $routes=['/contact'=>['GET','POST'],'/contact/new'=>['POST'],'/staff/login'=>['GET','POST'],'/staff/mfa'=>['GET','POST'],'/staff'=>['GET'],'/staff/logout'=>['POST'],'/staff/status'=>['POST'],'/staff/assign'=>['POST'],'/staff/audit'=>['GET'],'/staff/accounts'=>['GET'],'/staff/account'=>['POST'],'/staff/notifications'=>['POST']];
    if(preg_match('~^/staff/inquiries/[1-9][0-9]{0,9}$~D',(string)$path))$routes[$path]=['GET'];
    if(!isset($routes[$path]))deny(404);
    if(!in_array($method,$routes[$path],true)){header('Allow: '.implode(', ',$routes[$path]));deny(405);}
    if($method==='POST')postGuard($path==='/contact'?32768:8192);
    if($path==='/staff/logout'){StaffSessionStorage::logout();header('Location: /staff/login',true,303);exit;}
    if($path==='/contact/new'){$_SESSION['request_token']=InquiryRequestGuard::issueToken();header('Location: /contact',true,303);exit;}
    if($path==='/contact') {
        if($method==='GET')contactForm();
        if(!InquiryRequestGuard::verifyToken(submitted('request_token'),$_SESSION['request_token']??''))deny(403);
        $fields=['inquiry_type','client_type','person_name','organization_name','email','phone','service','message','budget','desired_deadline'];
        $data=InquiryValidator::normalize(array_intersect_key($_POST,array_flip($fields)));
        if(!str_ends_with($data['email'],'@example.invalid')||submitted('consent')!=='yes')deny(400);
        $db=devDb();$key=devSecret('HAYANEX_DEV_HMAC_KEY');
        if(!(new InquiryRateLimiter($db))->allow($_SERVER['REMOTE_ADDR'],$key))deny(429);
        $receipt=(new InquiryRepository($db))->register($data,InquiryRequestGuard::deriveRequestKey(submitted('request_token'),$key),true);
        page('開発DBへの登録を確認しました','<section class="panel"><p>架空の開発受付番号：<strong id="receipt">'.esc($receipt).'</strong></p><p>本番の受付は成立していません。通知タスク2件を記録し、外部メールは送っていません。</p><form method="post" action="/contact/new">'.hiddenCsrf().'<button type="submit">別の架空問い合わせを作る</button></form><p><a href="/staff">スタッフ受付管理へ</a></p></section>',true);
    }
    if($path==='/staff/login') {
        if($method==='GET')loginForm();
        if(StaffSessionStorage::current(time())!==null)deny(409);
        $login=submitted('login');$password=submitted('password');
        if(strlen($login)>100||strlen($password)>1024||!mb_check_encoding($login,'UTF-8'))deny(400);
        $db=devDb();$result=(new StaffLoginLimiter($db,devSecret('HAYANEX_DEV_HMAC_KEY')))->attempt($login,$_SERVER['REMOTE_ADDR'],function()use($db,$login,$password){
            $identity=(new StaffCredentialRepository($db))->verify($login,$password);
            return ['ok'=>$identity!==null,'identity'=>$identity];
        });
        if($result['limited']){header('Retry-After: 900');deny(429);}
        if(!$result['ok']){http_response_code(401);loginForm('ログイン情報を確認してください。');}
        $pending=StaffLoginFlow::afterPassword($result['identity'],time());
        if(!$pending)deny(401);
        $pending['password_stamp']=$result['identity']['password_stamp'];
        if(!$pending['mfa_required'])finishLogin($pending,false);
        if(!session_regenerate_id(true))throw new RuntimeException('Rotation failed');
        $_SESSION=['pending'=>$pending,'pending_login'=>$login];csrf();header('Location: /staff/mfa',true,303);exit;
    }
    if($path==='/staff/mfa') {
        $pending=$_SESSION['pending']??null;
        if(!is_array($pending)||!StaffLoginFlow::pendingValid($pending,time())){unset($_SESSION['pending']);deny(401);}
        if($method==='GET')page('MFA認証','<section class="panel"><p>パスワードの確認だけではログインは完了しません。</p><form method="post" action="/staff/mfa">'.hiddenCsrf().'<label for="code">認証アプリの6桁コード</label><input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button type="submit">追加認証を確認する</button></form></section>',true);
        $db=devDb();$code=submitted('code');$result=(new StaffLoginLimiter($db,devSecret('HAYANEX_DEV_HMAC_KEY')))->attempt($_SESSION['pending_login'],$_SERVER['REMOTE_ADDR'],function()use($db,$pending,$code){$stamp=(new StaffMfaVerifier($db,devSecret('HAYANEX_DEV_MFA_KEY')))->verify($pending,$code,time());return ['ok'=>$stamp!==null,'mfa_stamp'=>$stamp];});
        if($result['limited']){header('Retry-After: 900');deny(429);}if(!$result['ok'])deny(401);$pending['mfa_stamp']=$result['mfa_stamp'];finishLogin($pending,true);
    }
    $db=devDb();$s=identity($db);$navigation=staffNav($s);
    if($path==='/staff') {
        $rawPage=$_GET['page']??'1';
        if(!is_string($rawPage)||!ctype_digit($rawPage)||(int)$rawPage<1||(int)$rawPage>100000)deny(400);
        $pageNumber=(int)$rawPage;$offset=($pageNumber-1)*25;
        $rows=(new StaffInquiryList($db))->listFor($s['role'],$s['staff_id'],25,$offset);
        $counts=array_fill_keys(['new','reviewing','in_progress','completed','on_hold'],0);
        // Aggregate within the same role/assignment scope, never a global customer count for staff.
        $sql='SELECT i.status,COUNT(*) AS n FROM inquiries i LEFT JOIN inquiry_assignments a ON a.inquiry_id=i.id'.($s['role']==='staff'?' WHERE a.staff_id=?':'').' GROUP BY i.status';
        $q=$db->prepare($sql);$q->execute($s['role']==='staff'?[$s['staff_id']]:[]);foreach($q->fetchAll() as $r)$counts[$r['status']]=(int)$r['n'];
        $cards='<section class="cards" aria-label="閲覧できる案件の件数">';foreach($counts as $status=>$n)$cards.='<div class="card"><div class="label">'.esc(statusLabel($status)).'</div><div class="value">'.$n.'</div></div>';$cards.='</section>';
        $table='<section class="panel"><h2>受付一覧</h2><p>一般スタッフは割当済みの案件のみです。1ページ25件を表示しています。</p><div class="tablewrap"><table><thead><tr><th scope="col">受付番号</th><th scope="col">区分</th><th scope="col">担当</th><th scope="col">状況</th></tr></thead><tbody>';
        foreach($rows as $r)$table.='<tr><td><a href="/staff/inquiries/'.(int)$r['id'].'">'.esc($r['receipt_no']).'</a></td><td>'.esc(['estimate'=>'見積もり','consult'=>'相談','other'=>'その他'][$r['inquiry_type']]??'不明').'</td><td>'.($r['assigned_staff_id']===null?'未割当':'担当ID '.(int)$r['assigned_staff_id']).'</td><td>'.esc(statusLabel($r['status'])).'</td></tr>';
        if(!$rows)$table.='<tr><td colspan="4">閲覧できる案件はありません。</td></tr>';
        $table.='</tbody></table></div></section>';
        $pagination='<nav class="controls" aria-label="受付一覧のページ"><p>ページ '.$pageNumber.'</p>';
        if($pageNumber>1)$pagination.='<a href="/staff?page='.($pageNumber-1).'">前のページ</a>';
        if($offset+25<array_sum($counts))$pagination.='<a href="/staff?page='.($pageNumber+1).'">次のページ</a>';
        page('お問い合わせ受付管理',$navigation.$cards.$table.$pagination.'</nav>');
    }
    if(preg_match('~^/staff/inquiries/([1-9][0-9]{0,9})$~D',(string)$path,$m)) {
        $q=$db->prepare('SELECT i.*,a.staff_id AS assigned_staff_id FROM inquiries i LEFT JOIN inquiry_assignments a ON a.inquiry_id=i.id WHERE i.id=?'.($s['role']==='staff'?' AND a.staff_id=?':''));$q->execute($s['role']==='staff'?[(int)$m[1],$s['staff_id']]:[(int)$m[1]]);$r=$q->fetch();if(!$r)deny(404);
        permitted($db,$s,'inquiry_view',$r['assigned_staff_id']===null?null:(int)$r['assigned_staff_id']);
        $html=$navigation.'<section class="panel"><h2>'.esc($r['receipt_no']).'</h2><dl>';foreach(['person_name'=>'架空氏名','email'=>'架空返信先','message'=>'架空相談内容','received_at'=>'受付日時（日本時間）'] as $key=>$label)$html.='<dt>'.esc($label).'</dt><dd><pre>'.esc($r[$key]).'</pre></dd>';$html.='</dl>';
        $html.='<form method="post" action="/staff/status">'.hiddenCsrf().'<input type="hidden" name="inquiry_id" value="'.(int)$r['id'].'"><label for="status">状況</label><select id="status" name="status">';
        foreach(['new','reviewing','in_progress','completed','on_hold'] as $status)$html.='<option value="'.$status.'"'.($status===$r['status']?' selected':'').'>'.esc(statusLabel($status)).'</option>';
        $html.='</select><button type="submit">状況を更新する</button></form>';
        if(StaffPermissions::can($s['role'],'assign')) {
            $html.='<form method="post" action="/staff/assign">'.hiddenCsrf().'<input type="hidden" name="inquiry_id" value="'.(int)$r['id'].'"><label for="assignee">担当者</label><select name="staff_id" id="assignee">';
            foreach($db->query('SELECT id,staff_login FROM inquiry_staff WHERE is_active=1 ORDER BY id')->fetchAll() as $a)$html.='<option value="'.(int)$a['id'].'">'.esc($a['staff_login']).'</option>';
            $html.='</select><button type="submit">担当を割り当てる</button></form>';
        }
        page('受付詳細',$html.'</section>');
    }
    if($path==='/staff/status') {
        $id=filter_var(submitted('inquiry_id'),FILTER_VALIDATE_INT);if(!$id||$id<1)deny(400);
        $q=$db->prepare('SELECT staff_id FROM inquiry_assignments WHERE inquiry_id=?');$q->execute([$id]);$assigned=$q->fetchColumn();permitted($db,$s,'inquiry_update',$assigned===false?null:(int)$assigned);
        (new StaffInquiryStatus($db))->change($s['staff_id'],$id,submitted('status'));header('Location: /staff/inquiries/'.$id,true,303);exit;
    }
    if($path==='/staff/assign') {
        permitted($db,$s,'assign');$id=filter_var(submitted('inquiry_id'),FILTER_VALIDATE_INT);$target=filter_var(submitted('staff_id'),FILTER_VALIDATE_INT);if(!$id||!$target||$id<1||$target<1)deny(400);
        $db->beginTransaction();
        try{
            $a=account($db,$s['staff_id'],true);if(!$a||!$a['active']||!StaffPermissions::can($a['role'],'assign'))deny(403);
            $targetAccount=account($db,$target,true);if(!$targetAccount||!$targetAccount['active'])deny(400);
            $q=$db->prepare('SELECT i.id,a.staff_id FROM inquiries i LEFT JOIN inquiry_assignments a ON a.inquiry_id=i.id WHERE i.id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old)deny(404);
            $db->prepare('INSERT INTO inquiry_assignments (inquiry_id,staff_id) VALUES (?,?) ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id),assigned_at=NOW(6)')->execute([$id,$target]);
            if((int)($old['staff_id']??0)!==$target)$db->prepare("INSERT INTO inquiry_audit_events (inquiry_id,actor_staff_id,event_type,old_value,new_value) VALUES (?,?,'assignment_change',?,?)")->execute([$id,$s['staff_id'],(string)($old['staff_id']??'none'),(string)$target]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        header('Location: /staff/inquiries/'.$id,true,303);exit;
    }
    if($path==='/staff/audit') {
        permitted($db,$s,'audit_view');$html=$navigation.'<section class="panel"><h2>案件監査（最新100件）</h2><ul>';
        foreach($db->query('SELECT inquiry_id,event_type,old_value,new_value FROM inquiry_audit_events ORDER BY id DESC LIMIT 100')->fetchAll() as $r)$html.='<li>案件 '.(int)$r['inquiry_id'].'：'.esc(['status_change'=>'状況変更','assignment_change'=>'担当変更'][$r['event_type']]??'操作').' '.esc($r['old_value']).' → '.esc($r['new_value']).'</li>';
        $html.='</ul><h2>開発通知の記録（外部未送信）</h2><ul>';
        foreach($db->query('SELECT status,COUNT(*) AS n FROM notification_outbox GROUP BY status')->fetchAll() as $r)$html.='<li>'.esc(['pending'=>'未処理','processing'=>'処理中','sent'=>'送信済みの記録','failed'=>'失敗','simulated'=>'開発記録済み・外部未送信'][$r['status']]??'不明').'：'.(int)$r['n'].'</li>';
        $html.='</ul><h2>スタッフ変更の監査（最新100件）</h2><ul>';
        foreach($db->query('SELECT actor_id,target_id,old_role,new_role,new_active FROM dev_staff_audit ORDER BY id DESC LIMIT 100')->fetchAll() as $event) {
            $labels=['owner'=>'代表者','admin'=>'管理者','staff'=>'スタッフ'];
            $html.='<li>操作ID '.(int)$event['actor_id'].' → 対象ID '.(int)$event['target_id'].'：'.esc($labels[$event['old_role']]??'不明').' → '.esc($labels[$event['new_role']]??'不明').'、'.((int)$event['new_active']===1?'有効':'無効').'</li>';
        }
        $html.='</ul>';
        if($s['role']==='owner')$html.='<form method="post" action="/staff/notifications">'.hiddenCsrf().'<button type="submit">通知1件をローカルに記録する（外部未送信）</button></form>';
        page('監査・通知状況',$html.'</section>');
    }
    if($path==='/staff/notifications'){permitted($db,$s,'system_config');(new DevNotificationWorker($db))->once();header('Location: /staff/audit',true,303);exit;}
    if($path==='/staff/accounts') {
        permitted($db,$s,'staff_manage');$html=$navigation.'<section class="panel"><h2>架空スタッフの管理</h2><p>自己無効化・自己権限変更は拒否します。新規発行・MFA登録/復旧の本番運用は代表審査事項です。</p>';
        foreach($db->query('SELECT id,staff_login,staff_role,is_active FROM inquiry_staff ORDER BY id')->fetchAll() as $a) {
            $html.='<form method="post" action="/staff/account">'.hiddenCsrf().'<input type="hidden" name="staff_id" value="'.(int)$a['id'].'"><h3>'.esc($a['staff_login']).'</h3><label for="role-'.(int)$a['id'].'">権限</label><select id="role-'.(int)$a['id'].'" name="role">';
            foreach(['owner'=>'代表者','admin'=>'管理者','staff'=>'スタッフ'] as $role=>$label)$html.='<option value="'.$role.'"'.($a['staff_role']===$role?' selected':'').'>'.$label.'</option>';
            $html.='</select><label for="active-'.(int)$a['id'].'">有効状態</label><select id="active-'.(int)$a['id'].'" name="active"><option value="1"'.((int)$a['is_active']===1?' selected':'').'>有効</option><option value="0"'.((int)$a['is_active']===0?' selected':'').'>無効</option></select><button type="submit"'.((int)$a['id']===$s['staff_id']?' disabled':'').'>'.((int)$a['id']===$s['staff_id']?'本人の変更はできません':'変更を保存する').'</button></form>';
        }page('スタッフ管理',$html.'</section>');
    }
    if($path==='/staff/account') {
        permitted($db,$s,'staff_manage');$id=filter_var(submitted('staff_id'),FILTER_VALIDATE_INT);$role=submitted('role');$active=submitted('active');
        if(!$id||$id<1||!in_array($role,['owner','admin','staff'],true)||!in_array($active,['0','1'],true))deny(400);
        if($id===$s['staff_id'])deny(403);
        $db->beginTransaction();try{
            $actor=account($db,$s['staff_id'],true);if(!$actor||!$actor['active']||$actor['role']!=='owner')deny(403);
            $old=account($db,$id,true);if(!$old)deny(404);
            // Promotion cannot create an account with mandatory MFA but no enrolled key.
            if(StaffAuth::requiresMfa($role)){$q=$db->prepare('SELECT staff_id FROM inquiry_staff_mfa WHERE staff_id=?');$q->execute([$id]);if(!$q->fetch())deny(409);}
            $db->prepare('UPDATE inquiry_staff SET staff_role=?,is_active=?,disabled_at=IF(?=0,NOW(6),NULL) WHERE id=?')->execute([$role,(int)$active,(int)$active,$id]);
            $db->prepare('INSERT INTO dev_staff_audit (actor_id,target_id,old_role,new_role,new_active) VALUES (?,?,?,?,?)')->execute([$s['staff_id'],$id,$old['role'],$role,(int)$active]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        header('Location: /staff/accounts',true,303);exit;
    }
    deny(404);
}catch(DevHttpError $e){http_response_code($e->getCode());page('操作を完了できませんでした','<p>認証・権限・リクエストの条件を確認してください。成功した受付や更新として扱わないでください。</p><p><a href="/staff/login">ログイン画面へ</a></p>',true);
}catch(InvalidArgumentException $e){http_response_code(400);page('入力を確認してください','<p>形式や再送条件が一致しません。成功した受付として扱わないでください。</p><a href="/contact">架空お問い合わせへ戻る</a>',true);
}catch(Throwable $e){error_log('private-dev failure: '.get_class($e));http_response_code(503);page('処理を停止しました','<p>認証・保存・通知を完了できません。受付・更新済みとして扱わないでください。</p>',true);}
