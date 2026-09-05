<?php

namespace Tests\Feature\Ai\Support;

/** A second working provider, used to prove the fallback chain advances. */
class BackupTextProvider extends FakeTextProvider
{
    public function code(): string
    {
        return 'fake-backup';
    }
}
