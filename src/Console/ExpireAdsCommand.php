<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Console;

use Flarum\Console\AbstractCommand;
use Lowseekai\Advertising\Support\AdRepository;

class ExpireAdsCommand extends AbstractCommand
{
    public function __construct(protected AdRepository $ads)
    {
        parent::__construct('advertising:expire');
        $this->setDescription('Expire advertising campaigns whose end time has passed.');
    }

    protected function fire(): int
    {
        $count = $this->ads->expireDueAds();
        $this->info(sprintf('Expired %d advertising campaign(s).', $count));

        return 0;
    }
}
