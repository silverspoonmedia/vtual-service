<?php

namespace Silverspoonmedia\VtualService\Commands;

use Illuminate\Console\Command;

class VtualServiceCommand extends Command
{
    public $signature = 'vtual-service';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
