<?php

arch('no debugging statements')->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])->not->toBeUsed();

arch('platform code uses strict types')->expect('App\Platform')->toUseStrictTypes();

arch('platform (cross-cutting) never depends on business modules')->expect('App\Platform')->not->toUse('App\Modules');

arch('controllers are final')->expect('App\Platform\Http\Controllers')->toBeFinal();
