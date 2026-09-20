import subprocess

url = 'https://aiwork.ddns.net/gary/twwater/?action=login'
result = subprocess.run(
    [
        'curl', '-k', '-sS', '--max-time', '30', '--resolve', 'aiwork.ddns.net:443:127.0.0.1',
        '-o', 'NUL', '-D', '-', url,
    ],
    check=True,
    text=True,
    capture_output=True,
)
headers = result.stdout.replace('\r\n', '\n')
if 'HTTP/1.1 308' in headers:
    assert 'Location: https://aiwork.ddns.net/gary/TWWATER/?action=login' in headers, 'CANONICAL_LOGIN_LOCATION_MISMATCH'
else:
    assert 'HTTP/1.1 200' in headers, 'LOWERCASE_LOGIN_PATH_NOT_SERVED_OR_REDIRECTED'
    assert 'Content-Security-Policy:' in headers and 'Cache-Control: no-store' in headers, 'CASE_INSENSITIVE_IIS_PATH_LOST_SECURITY_HEADERS'
print('LOGIN_CANONICAL_PATH_CONTRACT_OK')
