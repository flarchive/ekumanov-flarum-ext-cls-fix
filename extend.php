<?php

namespace Ekumanov\ClsFix;

use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    (new Extend\Formatter)
        ->configure(ConfigureFormatter::class)
        ->render(InjectImageDimensions::class),

    (new Extend\Routes('api'))
        ->post('/cls-fix/dimensions', 'cls-fix.dimensions.store', ReportDimensionsController::class),
];
