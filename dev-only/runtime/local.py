#!/usr/bin/env python3
"""Local disposable Docker harness. Never connects to XServer or reads production settings."""
import argparse, base64, hashlib, json, os, pathlib, secrets, subprocess, sys, time
ROOT=pathlib.Path(__file__).resolve().parents[2]
RUN=pathlib.Path(os.environ.get('HAYANEX_DEV_RUNTIME','/tmp/hayanex-private-20261109')).resolve()
if not RUN.is_relative_to(pathlib.Path('/tmp')): raise SystemExit('Runtime must be below /tmp')
DB='hayanex-isolated-db-20261109';PHP='hayanex-isolated-php-20261109';IMAGE='hayanex-private-php:20261109'
ENV=os.environ.copy()
for key in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']: ENV.pop(key,None)
ENV['BUILDX_CONFIG']=str(RUN/'buildx')
DK=['docker','--host=unix:///var/run/docker.sock']
def docker(args,**kw): return subprocess.run(DK+args,env=ENV,check=True,**kw)
def write(name,text):
 p=RUN/name;p.write_text(text);p.chmod(0o600)
def envfile(name): return dict(line.split('=',1) for line in (RUN/name).read_text().splitlines() if '=' in line)
def sql(text):
 # Never print SQL: some statements contain generated test passwords.
 r=subprocess.run(DK+['exec','-i','--env-file',str(RUN/'db-admin.env'),DB,'mariadb','--socket=/run/mysqld/mysql.sock','-uroot'],env=ENV,input=text,text=True,capture_output=True)
 if r.returncode: raise RuntimeError('Disposable SQL operation failed (sensitive details suppressed)')
 return r.stdout
def php(file,*args):
 return docker(['run','--rm','--user','1000:1000','--network','container:'+DB,'--env-file',str(RUN/'php.env'),'--mount',f'type=bind,src={ROOT},dst=/source,readonly','--mount',f'type=bind,src={RUN},dst=/runtime',IMAGE,'php','-d','pdo_mysql.default_socket=/runtime/mysql/mysql.sock',file,*args])
def reset(name,extension=False):
 if name not in ['hayanex_test','hayanex_http_test','hayanex_restore_test']:raise ValueError('Disposable DB only')
 password=envfile('php.env')['HAYANEX_TEST_DB_PASS']
 if not password.isalnum():raise ValueError('Generated password required')
 sql(f'DROP DATABASE IF EXISTS `{name}`; CREATE DATABASE `{name}` CHARACTER SET utf8mb4; GRANT ALL ON `{name}`.* TO \'hayanex_test01\'@\'%\'; USE `{name}`;\n'+(ROOT/'docs/inquiry-dev-schema.sql').read_text()+('\n'+(ROOT/'dev-only/runtime/schema-extension.sql').read_text() if extension else ''))
 if extension:
  grants={'inquiry_sequences':'SELECT,INSERT,UPDATE','inquiries':'SELECT,INSERT,UPDATE','inquiry_requests':'SELECT,INSERT','notification_outbox':'SELECT,INSERT,UPDATE','inquiry_rate_limits':'SELECT,INSERT,UPDATE,DELETE','inquiry_staff':'SELECT,INSERT,UPDATE','inquiry_staff_mfa':'SELECT,INSERT,UPDATE','inquiry_assignments':'SELECT,INSERT,UPDATE','inquiry_audit_events':'SELECT,INSERT','staff_login_windows':'SELECT,INSERT,UPDATE,DELETE','dev_notification_deliveries':'SELECT,INSERT','dev_staff_audit':'SELECT,INSERT'}
  sql(f"REVOKE ALL PRIVILEGES ON `{name}`.* FROM 'hayanex_test01'@'%';"+''.join(f"GRANT {rights} ON `{name}`.`{table}` TO 'hayanex_test01'@'%';" for table,rights in grants.items()))
def prepare():
 if subprocess.run(DK+['inspect',PHP],env=ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:raise RuntimeError('Stop the owned PHP container before prepare')
 RUN.mkdir(mode=0o700,parents=True,exist_ok=True)
 if not (RUN/'db.env').exists():
  root=secrets.token_hex(24);password=secrets.token_hex(24)
  write('db.env',f'MARIADB_ROOT_PASSWORD={root}\nMARIADB_DATABASE=hayanex_test\nMARIADB_USER=hayanex_test01\nMARIADB_PASSWORD={password}\n')
  write('db-admin.env',f'MYSQL_PWD={root}\n')
  write('php.env',f'HAYANEX_TEST_WRITE_ENABLE=YES_DISPOSABLE_DB_ONLY\nHAYANEX_TEST_DSN=mysql:host=localhost;dbname=hayanex_test;charset=utf8mb4\nHAYANEX_TEST_DB_USER=hayanex_test01\nHAYANEX_TEST_DB_PASS={password}\n')
 exists=subprocess.run(DK+['inspect',DB],env=ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0
 if exists:
  info=json.loads(docker(['inspect',DB],capture_output=True,text=True).stdout)[0]
  if not any(m.get('Destination')=='/run/mysqld' and m.get('Source')==str(RUN/'mysql') for m in info.get('Mounts',[])):raise RuntimeError('Container is not owned by this runtime')
 if not exists:
  (RUN/'mysql').mkdir(mode=0o755,exist_ok=True)
  docker(['run','-d','--name',DB,'--env-file',str(RUN/'db.env'),'-p','127.0.0.1:18880:8080','--mount',f'type=bind,src={RUN / "mysql"},dst=/run/mysqld','mariadb@sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620','--socket=/run/mysqld/mysql.sock'],stdout=subprocess.DEVNULL)
  # Only the socket directory is shared with MariaDB; private keys stay outside it.
  for _ in range(100):
   time.sleep(.2)
   try:sql('SELECT 1;');break
   except RuntimeError:pass
  else:raise RuntimeError('Local DB did not become ready')
 docker(['exec','-u','0',DB,'chmod','755','/run/mysqld'],stdout=subprocess.DEVNULL)
 gateway=secrets.token_hex(32)
 peer=json.loads(docker(['inspect',DB],capture_output=True,text=True).stdout)[0]['NetworkSettings']['Gateway']
 app=envfile('php.env')|{'HAYANEX_DEV_DSN':'mysql:host=localhost;dbname=hayanex_http_test;charset=utf8mb4','HAYANEX_DEV_GATEWAY_PEER':peer,'HAYANEX_DEV_GATEWAY_KEY':gateway,'HAYANEX_DEV_ORIGIN':'https://127.0.0.1:24443','HAYANEX_DEV_SESSION_DIR':'/runtime/sessions','HAYANEX_DEV_HMAC_KEY':base64.b64encode(secrets.token_bytes(32)).decode(),'HAYANEX_DEV_MFA_KEY':base64.b64encode(secrets.token_bytes(32)).decode()}
 write('app.env',''.join(f'{k}={v}\n' for k,v in app.items()));write('gateway.json',json.dumps({'key':gateway}))
 (RUN/'sessions').mkdir(mode=0o700,exist_ok=True)
 if not (RUN/'tls.crt').exists() or subprocess.run(['openssl','x509','-in',str(RUN/'tls.crt'),'-checkend','86400','-noout'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode!=0:
  subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(RUN/'tls.key'),'-out',str(RUN/'tls.crt'),'-days','7','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1,DNS:localhost'],check=True,capture_output=True);(RUN/'tls.key').chmod(0o600)
 reset('hayanex_http_test',True);php('dev-only/runtime/seed-http.php')
 print('READY: disposable runtime; private values remain outside checkout')
def tests():
 php('dev-only/inquiry/tests/run_offline_tests.php')
 php('dev-only/inquiry/tests/local_components_test.php')
 reset('hayanex_test')
 for file in ['isolated_db_smoke_test.php','isolated_db_rollback_test.php','isolated_db_parallel_test.php','isolated_staff_credentials_test.php','isolated_staff_db_test.php','isolated_staff_mfa_db_test.php']:
  php('dev-only/inquiry/tests/'+file)
def serve():
 # Docker --rm can finish just after stop returns. Never compete with an active server.
 for _ in range(100):
  state=subprocess.run(DK+['inspect',PHP],env=ENV,capture_output=True,text=True)
  if state.returncode!=0:break
  info=json.loads(state.stdout)[0]
  if info['State']['Running']:raise RuntimeError('Private PHP server is already running')
  time.sleep(.1)
 else:raise RuntimeError('Stopped PHP container has not been removed')
 docker(['run','--rm','--name',PHP,'--user','1000:1000','--read-only','--tmpfs','/tmp:uid=1000,gid=1000,mode=700','--network','container:'+DB,'--env-file',str(RUN/'app.env'),'-e','PHP_CLI_SERVER_WORKERS=4','--mount',f'type=bind,src={ROOT},dst=/source,readonly','--mount',f'type=bind,src={RUN},dst=/runtime,readonly','--mount',f'type=bind,src={RUN / "sessions"},dst=/runtime/sessions',IMAGE,'php','-d','pdo_mysql.default_socket=/runtime/mysql/mysql.sock','-d','display_errors=0','-S','0.0.0.0:8080','-t','dev-only/private-app/public','dev-only/private-app/router.php'])
def backup_check():
 tables=sql('SHOW TABLES FROM hayanex_http_test;').splitlines()[1:]
 if len(tables)!=12 or any(not name.replace('_','').isalnum() for name in tables):raise RuntimeError('Unexpected disposable schema')
 snapshot={name:sql(f'CHECKSUM TABLE hayanex_http_test.`{name}`;').splitlines()[-1].split()[-1] for name in tables}
 directory=RUN/'backups';directory.mkdir(mode=0o700,exist_ok=True)
 fd=os.open(directory/'synthetic.sql',os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
 with os.fdopen(fd,'w') as target:
  docker(['exec','--env-file',str(RUN/'db-admin.env'),DB,'mariadb-dump','--socket=/run/mysqld/mysql.sock','-uroot','--single-transaction','--skip-lock-tables','--no-tablespaces','hayanex_http_test'],stdout=target)
 reset('hayanex_restore_test')
 sql('USE hayanex_restore_test;\n'+(directory/'synthetic.sql').read_text())
 for name,checksum in snapshot.items():
  restored=sql(f'CHECKSUM TABLE hayanex_restore_test.`{name}`;').splitlines()[-1].split()[-1]
  if restored!=checksum:raise RuntimeError('Restored disposable checksum mismatch')
 print('PASS: isolated backup/restore, 12 table checksums match; no production data or key backup')
def main():
 p=argparse.ArgumentParser();p.add_argument('action',choices=['build','prepare','test-db','serve','reset-http','backup-check','lock-db','unlock-db']);a=p.parse_args()
 if a.action=='build':
  RUN.mkdir(mode=0o700,parents=True,exist_ok=True)
  docker(['build','--secret','id=system_ca,src=/etc/ssl/certs/ca-certificates.crt','-t',IMAGE,str(ROOT/'dev-only/runtime')])
 elif a.action=='prepare':prepare()
 elif a.action=='test-db':tests()
 elif a.action=='backup-check':backup_check()
 elif a.action in ['lock-db','unlock-db']:sql("ALTER USER 'hayanex_test01'@'%' ACCOUNT "+('LOCK' if a.action=='lock-db' else 'UNLOCK'))
 elif a.action=='reset-http':reset('hayanex_http_test',True);php('dev-only/runtime/seed-http.php')
 else:serve()
if __name__=='__main__':main()
