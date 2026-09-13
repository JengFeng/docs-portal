import os
import re
import subprocess
import tempfile
from pathlib import Path

BASE = 'https://aiwork.ddns.net/gary/TWWATER/?action=login'
CURL = ['curl', '-k', '-sS', '--max-time', '30', '--resolve', 'aiwork.ddns.net:443:127.0.0.1']


def run(args: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=True, text=True, capture_output=True)


with tempfile.TemporaryDirectory(prefix='twwater-login-contract-') as directory:
    root = Path(directory)
    jar = root / 'cookies.txt'
    login_page = root / 'login.html'
    response = root / 'post.html'

    run(CURL + ['-c', str(jar), BASE, '-o', str(login_page)])
    page = login_page.read_text(encoding='utf-8', errors='replace')
    match = re.search(r'name="csrf_token" value="([^"]+)"', page)
    assert match, 'LOGIN_CSRF_TOKEN_MISSING'

    run(CURL + [
        '-b', str(jar), '-c', str(jar),
        '--data-urlencode', f'csrf_token={match.group(1)}',
        '--data-urlencode', 'username=login-contract-probe',
        '--data-urlencode', 'password=not-a-real-password',
        BASE, '-o', str(response),
    ])
    posted = response.read_text(encoding='utf-8', errors='replace')
    assert '請重新開啟登入頁後再試一次。' not in posted, 'LOGIN_CSRF_SESSION_MISMATCH'
    assert ('帳號或密碼錯誤' in posted or '登入嘗試過於頻繁' in posted), 'LOGIN_AUTH_HANDLER_NOT_REACHED'

print('LOGIN_SESSION_HTTP_CONTRACT_OK')
