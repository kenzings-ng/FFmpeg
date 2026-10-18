<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handle incoming requests when a user clicks the verification link in an email.
 *
 * We do not use Illuminate\Foundation\Auth\EmailVerificationRequest as in Laravel's
 * default scaffolding (Breeze/Fortify) because that request requires $request->user()
 * to be authenticated via the 'web' session guard — this app has no session login,
 * users authenticate solely via Bearer tokens (Passport). The user clicking the email link
 * might open an unauthenticated browser session, so we look up the user by {id} in the URL
 * and rely on the signature (validated by the 'signed' middleware before reaching here).
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('services.frontend.url'), '/');

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away("{$frontendUrl}/email-verified?status=invalid");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->away("{$frontendUrl}/email-verified?status=ok");
    }
}
