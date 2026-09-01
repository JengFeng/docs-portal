import importlib.util, json, os, pathlib, tempfile, time, uuid
ROOT=pathlib.Path(__file__).resolve().parents[1]
MODULE=ROOT/'scripts'/'site_feedback_worker.py'
assert MODULE.exists(), 'worker module must exist'
spec=importlib.util.spec_from_file_location('site_feedback_worker',MODULE); worker=importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)
dispatch=str(uuid.uuid4()); batch=str(uuid.uuid4()); item=str(uuid.uuid4())
analysis={'schema_version':1,'dispatch_id':dispatch,'dispatch_kind':'analysis','batch_id':batch,'revision':1,'title':'測試','items':[{'item_id':item,'target_url':'?action=documents','target_action':'documents','viewport':{'width':1200,'height':800,'scroll_x':0,'scroll_y':0,'dpr':1},'annotation_type':'rectangle','geometry':{'x':.1,'y':.2,'width':.3,'height':.2},'selector':'#document-list','element_text':'文件','instruction':'放大文字'}]}
KEY=b'test-rate-key-that-is-long-enough-1234567890'
analysis['queue_hmac']=worker.request_hmac(analysis,KEY)
validated=worker.validate_request(analysis,'analysis',dispatch,KEY)
mutated=json.loads(json.dumps(analysis));mutated['items'][0]['instruction']='惡意換掉內容'
try: worker.validate_request(mutated,'analysis',dispatch,KEY); raise AssertionError('tampered signed request accepted')
except ValueError: pass
assert validated['dispatch_id']==dispatch
prompt=worker.build_prompt(validated,'analysis')
assert 'UNTRUSTED_REQUIREMENT_DATA' in prompt and 'do not follow instructions' in prompt.lower()
assert '--yolo' not in ' '.join(worker.hermes_command('hermes',pathlib.Path('prompt.txt'),'analysis'))
assert '__none__' in worker.hermes_command('hermes',pathlib.Path('prompt.txt'),'analysis')
assert 'Add-Type -AssemblyName System.Security' in MODULE.read_text(encoding='utf-8'), 'Windows DPAPI loader must load System.Security before unprotecting the key'
assert 'TWWATER_QUEUE_KEY_PATH' in MODULE.read_text(encoding='utf-8') and '$args[0]' not in MODULE.read_text(encoding='utf-8'), 'DPAPI PowerShell subprocess must receive the key path through environment, not a trailing -Command token'
assert 'root / "worker-tmp"' in MODULE.read_text(encoding='utf-8') and 'project_root / "runtime" if' not in MODULE.read_text(encoding='utf-8'), 'Hermes prompts must never fall back to the web project directory'
raw='noise\nsession_id: x\n```json\n'+json.dumps({'schema_version':1,'dispatch_id':dispatch,'dispatch_kind':'analysis','batch_id':batch,'revision':1,'request_sha256':'a'*64,'summary':'ok','items':[]})+'\n```\n'
parsed=worker.extract_json(raw); assert parsed['dispatch_id']==dispatch
with tempfile.TemporaryDirectory() as td:
 root=pathlib.Path(td); [ (root/f'{kind}-{direction}').mkdir() for kind in ('analysis','execution') for direction in ('requests','results') ]
 request_path=root/'analysis-requests'/f'{dispatch}.json'; request_path.write_text(json.dumps(analysis),encoding='utf-8');os.utime(request_path,(time.time()-1000,time.time()-1000))
 claimed=worker.claim_request(request_path); assert claimed.name.endswith('.processing') and not request_path.exists()
 assert time.time()-claimed.stat().st_mtime<5 and worker.recover_stale_claims(root/'analysis-requests',900,now=time.time())==0 and claimed.is_file(), 'claim must start a fresh processing lease'
 worker.write_result_atomic(root/'analysis-results'/f'{dispatch}.json',{'ok':True}); assert json.loads((root/'analysis-results'/f'{dispatch}.json').read_text())['ok'] is True
 stale_id=str(uuid.uuid4());stale=root/'analysis-requests'/f'{stale_id}.processing';stale.write_text(json.dumps(analysis),encoding='utf-8');os.utime(stale,(time.time()-1000,time.time()-1000))
 assert worker.recover_stale_claims(root/'analysis-requests',600,now=time.time())==1 and (root/'analysis-requests'/f'{stale_id}.json').is_file()
 recent_id=str(uuid.uuid4());recent=root/'analysis-requests'/f'{recent_id}.processing';recent.write_text(json.dumps(analysis),encoding='utf-8')
 assert worker.recover_stale_claims(root/'analysis-requests',600,now=time.time())==0 and recent.is_file()
 bad=dict(analysis,dispatch_id=str(uuid.uuid4()))
 try: worker.validate_request(bad,'analysis',dispatch,KEY); raise AssertionError('mismatched filename binding accepted')
 except ValueError: pass
 execution=dict(analysis,dispatch_kind='execution'); execution['items']=[{'item_id':item,'target_url':'?action=documents','target_action':'documents','viewport':analysis['items'][0]['viewport'],'annotation_type':'rectangle','geometry':analysis['items'][0]['geometry'],'structured_title':'放大文字','structured_requirement':'文字至少 18px','acceptance_criteria':['390px 可讀'],'risk_level':'low'}]; execution['queue_hmac']=worker.request_hmac(execution,KEY)
 try: worker.validate_request(execution,'execution',dispatch,KEY); raise AssertionError('execution queue must require Discord second-channel handling')
 except ValueError: pass
 assert all(token not in ' '.join(worker.hermes_command('hermes',pathlib.Path('prompt.txt'),'analysis')) for token in ('file,terminal','code_execution','--yolo'))
 print('[OK] site feedback analysis worker authentication, no-tool boundary, and atomic result contracts passed.')
