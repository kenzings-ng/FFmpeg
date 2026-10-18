<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

final class UpdateProfile
{
    /**
     * @param  null  $_
     * @param  array{name?: string, password?: string}  $args
     */
    public function __invoke($_, array $args): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // Change handle (record history) and save profile in a single transaction.
        DB::transaction(function () use ($user, $args) {
            if (isset($args['username'])) {
                $user->changeUsername($args['username']);
            }

            // bio: empty string (TrimStrings + ConvertEmptyStringsToNull -> null) deletes the bio.
            if (array_key_exists('bio', $args)) {
                $user->bio = $args['bio'];
            }

            $user->fill(array_filter([
                'name' => $args['name'] ?? null,
                // Model casts 'password' => 'hashed', so it hashes automatically.
                'password' => $args['password'] ?? null,
            ], fn ($value) => $value !== null))->save();

            // When changing password, revoke all other active sessions of the user
            if (isset($args['password'])) {
                $currentToken = $user->token();
                $currentTokenId = $currentToken instanceof Token
                    ? $currentToken->id
                    : ($currentToken?->oauth_access_token_id ?? $currentToken?->id ?? null);

                $otherTokensQuery = $user->tokens()->where('revoked', false);
                if ($currentTokenId) {
                    $otherTokensQuery->whereKeyNot($currentTokenId);
                }

                $otherTokenIds = $otherTokensQuery->pluck('id');
                if ($otherTokenIds->isNotEmpty()) {
                    $user->tokens()->whereIn('id', $otherTokenIds)->update(['revoked' => true]);
                    Passport::refreshToken()->whereIn('access_token_id', $otherTokenIds)->update(['revoked' => true]);
                }
            }
        });

        return $user;
    }
}
