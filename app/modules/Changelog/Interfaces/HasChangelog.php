<?php

namespace App\Modules\Changelog\Interfaces;

interface HasChangelog
{
    public function getChangelogManager(): \App\Modules\Changelog\Managers\ModelChangelogManager;
}
