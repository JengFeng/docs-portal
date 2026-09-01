import base64, importlib.util, json, pathlib, subprocess, uuid
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('site_feedback_worker',ROOT/'scripts'/'site_feedback_worker.py');worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(worker)
key=b'interoperability-rate-key-1234567890-ABCDEFGHIJ'
payload={'schema_version':1,'dispatch_id':str(uuid.uuid4()),'dispatch_kind':'execution','batch_id':str(uuid.uuid4()),'revision':7,'title':'跨語言簽章','items':[{'item_id':str(uuid.uuid4()),'structured_requirement':'字體至少 18px','acceptance_criteria':['390px 可讀']} ]}
php_code="require 'app/bootstrap.php';$p=json_decode(base64_decode($argv[1]),true,16,JSON_THROW_ON_ERROR);$k=base64_decode($argv[2],true);echo portal_feedback_canonical_json(portal_feedback_sign_request($p,$k));"
completed=subprocess.run([r'C:\PHP\8.5.9\php.exe','-r',php_code,base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode(),base64.b64encode(key).decode()],cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True)
signed=json.loads(completed.stdout)
assert worker.request_hmac(signed,key)==signed['queue_hmac'],'PHP and Python queue HMAC implementations diverged'
signed['revision']=8
assert worker.request_hmac(signed,key)!=signed['queue_hmac'],'tampering must invalidate cross-language HMAC'
print('[OK] PHP/Python site feedback queue HMAC interoperability and tamper rejection passed.')
