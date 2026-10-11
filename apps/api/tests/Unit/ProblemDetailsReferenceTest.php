<?php

use App\Platform\Exceptions\DomainProblem;
use App\Platform\Exceptions\ProblemDetails;

it('derives a short, quotable reference from the correlation id', function () {
    expect(ProblemDetails::reference('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBe('stvw-xyz0')
        ->and(ProblemDetails::reference('req-12345678'))->toBe('1234-5678')
        ->and(ProblemDetails::reference(''))->toBeNull();
});

it('renders catalogued domain problems with their own status and code', function () {
    $p = ProblemDetails::describe(new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Membership not activated', 'Contact your administrator.'), false);

    expect($p)->toBe(['status' => 409, 'code' => 'INVALID_STATE_TRANSITION', 'title' => 'Membership not activated', 'detail' => 'Contact your administrator.']);
});
