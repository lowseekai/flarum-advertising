<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class AdvertisingPolicy extends AbstractPolicy
{
    public function submit(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('lowseekai-advertising.submit');
    }

    public function manage(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('lowseekai-advertising.manage');
    }
}
