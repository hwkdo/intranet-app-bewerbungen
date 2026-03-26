<?php

namespace Hwkdo\IntranetAppBewerbungen\Commands;

use Illuminate\Console\Command;

class IntranetAppBewerbungenCommand extends Command
{
    public $signature = 'intranet-app-bewerbungen';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
