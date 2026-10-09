<?php

use App\Platform\Exceptions\ProblemDetails;

it('derives a short, quotable reference from the correlation id', function () {
    expect(ProblemDetails::reference('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBe('stvw-xyz0')
        ->and(ProblemDetails::reference('req-12345678'))->toBe('1234-5678')
        ->and(ProblemDetails::reference(''))->toBeNull();
});
