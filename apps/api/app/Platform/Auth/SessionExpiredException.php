<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use RuntimeException;

/** The server session no longer matches the user's credentials or lifetime (doc 25 §4, R-39): 401 SESSION_EXPIRED. */
final class SessionExpiredException extends RuntimeException {}
