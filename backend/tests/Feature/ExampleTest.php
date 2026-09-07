<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The installation's front door.
     *
     * There is no marketing site here: the learner's half of the product is the
     * app, and the only thing served over HTTP to a person is the school's
     * panel. So the root sends a browser to its login page.
     */
    public function test_the_root_sends_a_browser_to_the_panel(): void
    {
        $this->get('/')->assertRedirect(route('panel.login'));
    }
}
