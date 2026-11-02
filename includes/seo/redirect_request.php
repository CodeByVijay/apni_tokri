<?php

declare(strict_types=1);

/** Early public redirect hook (included from bootstrap after session start). */
if (PHP_SAPI !== 'cli') {
    SeoRedirectService::maybeRedirectCurrentRequest();
}
