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
assert 'HTTP/1.1 308' in headers, 'LOWERCASE_LOGIN_PATH_NOT_CANONICAL_REDIRECTED'
assert 'Location: https://aiwork.ddns.net/gary/TWWATER/?action=login' in headers, 'CANONICAL_LOGIN_LOCATION_MISMATCH'
print('LOGIN_CANONICAL_PATH_CONTRACT_OK')
