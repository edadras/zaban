<?php

namespace Tests\Feature\Panel;

use Tests\Feature\Classroom\ClassroomTestCase;

/**
 * The web panel, exercised the way a browser exercises it.
 *
 * Same school, same coach, same two learners as the API's tests - so a rule
 * that holds in one and not the other shows up as a failure rather than as a
 * difference nobody notices until a school finds it.
 */
abstract class PanelTestCase extends ClassroomTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The panel's pages are what is under test, not the asset build. Every
        // one of them pulls in a bundle, and requiring `npm run build` before
        // `php artisan test` would make the suite fail for a reason that has
        // nothing to do with the code it is testing.
        $this->withoutVite();
    }
}
