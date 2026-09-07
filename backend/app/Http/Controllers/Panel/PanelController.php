<?php

namespace App\Http\Controllers\Panel;

use App\Models\User;
use App\Services\Classroom\ClassroomException;
use App\Support\PanelAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Shared ground for the web panel.
 *
 * The panel calls the classroom services directly rather than its own copy of
 * the API over HTTP: one set of rules, enforced in one place, whichever door a
 * school walks in through.
 */
abstract class PanelController extends Controller
{
    public function __construct(protected readonly PanelAccess $access) {}

    protected function me(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** A refusal the way a website says it, rather than a JSON envelope. */
    protected function deny(string $message = 'شما به این بخش دسترسی ندارید.'): never
    {
        abort(403, $message);
    }

    protected function allow(bool $condition, string $message = 'شما به این بخش دسترسی ندارید.'): void
    {
        if (! $condition) {
            $this->deny($message);
        }
    }

    /**
     * Run a service call and turn a broken classroom rule into a message on
     * the page the coach is already looking at.
     */
    protected function attempt(callable $work, string $redirect, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (ClassroomException $e) {
            return redirect($redirect)->withInput()->withErrors(['classroom' => $e->getMessage()]);
        }

        return redirect($redirect)->with('status', $success);
    }
}
