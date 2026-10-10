<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own API tokens, on the profile page. A token reads
 * the user's figures through the API and can change nothing.
 */
class ApiTokenController extends Controller
{
    /** The one ability a token has. The API asks for it on every route. */
    public const ABILITY = 'read';

    /**
     * Make a token. Its text is flashed to the next page and never shown
     * again: only its hash is stored.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('apiToken', [
            'token_name' => ['required', 'string', 'max:100'],
        ], attributes: ['token_name' => 'name']);

        $token = $request->user()->createToken($validated['token_name'], [self::ABILITY]);

        return redirect()->to(route('profile.edit').'#api-tokens')
            ->with('api_token', $token->plainTextToken)
            ->with('success', "Made the token \"{$validated['token_name']}\". Copy it now: it is not shown again.");
    }

    /**
     * Revoke a token of the user's own. Anything using it stops working at once.
     */
    public function destroy(Request $request, string $token): RedirectResponse
    {
        $token = $request->user()->tokens()->findOrFail($token);
        $token->delete();

        return redirect()->to(route('profile.edit').'#api-tokens')->with('success', "Revoked the token \"{$token->name}\".");
    }
}
