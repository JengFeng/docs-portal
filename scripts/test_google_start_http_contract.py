import subprocess
import tempfile
import urllib.parse

with tempfile.TemporaryDirectory(prefix='twwater-google-start-') as directory:
    headers = directory + '/headers.txt'
    cookie_jar = directory + '/cookies.txt'
    common = ['curl', '-k', '-sS', '--max-time', '30', '--resolve', 'aiwork.ddns.net:443:127.0.0.1']
    subprocess.run(common + ['-c', cookie_jar, '-o', 'NUL', 'https://aiwork.ddns.net/gary/TWWATER/?action=login'], check=True)
    subprocess.run(common + ['-b', cookie_jar, '-D', headers, '-o', 'NUL', 'https://aiwork.ddns.net/gary/TWWATER/?action=google_login'], check=True)
    lines = open(headers, encoding='utf-8', errors='replace').read().replace('\r\n', '\n').split('\n')
    location = next((line.split(':', 1)[1].strip() for line in lines if line.lower().startswith('location:')), '')
    parsed = urllib.parse.urlparse(location)
    query = urllib.parse.parse_qs(parsed.query)
    status = next((line.split()[1] for line in lines if line.startswith('HTTP/') and len(line.split()) >= 2), 'NONE')
    assert status == '302', 'GOOGLE_START_NOT_REDIRECT'
    assert parsed.netloc == 'accounts.google.com', 'GOOGLE_START_WRONG_HOST'
    assert bool(query.get('state')), 'GOOGLE_START_STATE_MISSING'
    assert bool(query.get('code_challenge')), 'GOOGLE_START_PKCE_MISSING'

print('GOOGLE_START_CONTRACT_OK')
