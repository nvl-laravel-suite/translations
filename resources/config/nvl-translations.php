<?php

declare(strict_types=1);

return ['routes' => ['enabled' => false, 'prefix' => 'nvl/api/v1', 'middleware' => ['api'], 'management_middleware' => ['auth']], 'migrations' => ['enabled' => true], 'authorization' => ['ability' => null], 'discovery' => ['modules' => false, 'vendor' => false], 'backup' => ['enabled' => true], 'lock' => ['store' => null]];
