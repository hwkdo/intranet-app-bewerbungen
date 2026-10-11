<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

final class PipelineZugang
{
    public static function pruefen(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && ($user->can('manage-app-bewerbungen') || $user->can('manage-app-bewerbungen-definitionen')),
            403,
        );
    }
}
