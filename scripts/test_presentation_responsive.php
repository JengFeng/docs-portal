<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function responsive_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

responsive_test_assert(
    portal_page_body_class(true) === 'presentation-page',
    'Presentation pages must expose a dedicated responsive body class.'
);
responsive_test_assert(
    portal_page_body_class(false) === '',
    'Non-presentation pages must keep the default layout.'
);

$css = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'presentation.css');
responsive_test_assert(is_string($css), 'Unable to read presentation.css.');
responsive_test_assert(
    str_contains($css, '.presentation-page .page-shell'),
    'Presentation page shell must have a viewport-aware width override.'
);
responsive_test_assert(
    str_contains($css, 'calc(100vw -'),
    'Presentation page shell must scale from the viewport width.'
);
responsive_test_assert(
    str_contains($css, '@media (max-width: 1120px)'),
    'Tablet breakpoint is missing.'
);
responsive_test_assert(
    str_contains($css, '@media (max-width: 720px)'),
    'Mobile breakpoint is missing.'
);
responsive_test_assert(
    str_contains($css, 'grid-template-columns: clamp('),
    'Desktop presentation columns must scale responsively.'
);

fwrite(STDOUT, "[OK] Presentation responsive layout contract passed.\n");
