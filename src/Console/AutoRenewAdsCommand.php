<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Console;

use Flarum\Console\AbstractCommand;
use Lowseekai\Advertising\Support\AdRepository;

class AutoRenewAdsCommand extends AbstractCommand
{
    public function __construct(protected AdRepository $ads)
    {
        parent::__construct('advertising:auto-renew');
        $this->setDescription('Automatically renew advertising campaigns before they expire.');
    }

    protected function fire(): int
    {
        $result = $this->ads->autoRenewDueAds();
        $this->info(sprintf(
            'Auto-renewal checked %d campaign(s): %d renewed, %d failed, %d paused.',
            $result['checked'],
            $result['renewed'],
            $result['failed'],
            $result['paused']
        ));

        return 0;
    }
}
