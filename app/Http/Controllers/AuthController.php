<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class AuthController extends Controller
{
    // ---- Sign-up with email verification -------------------------------------------------
    private const CODE_MINUTES = 10;   // how long a code works
    private const CODE_TRIES = 5;      // wrong tries before a new code is needed
    private const RESEND_SECONDS = 60; // wait between emails

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($name, 0, 1) . '***@' . $domain;
    }

    // Makes a new code, saves its hash and emails it. False when the email could not be sent.
    private function sendCode(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->email_code_hash = $this->hashCode($code);
        $user->email_code_expires_at = now()->addMinutes(self::CODE_MINUTES);
        $user->email_code_sent_at = now();
        $user->email_code_attempts = 0;
        $user->save();

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Hello {$user->full_name},\n\n"
                . "Your CIMS verification code is: {$code}\n\n"
                . 'It expires in ' . self::CODE_MINUTES . " minutes.\n"
                . "If you did not sign up for CIMS, you can ignore this email.\n",
                fn ($m) => $m->to($user->email)->subject('Your CIMS verification code')
            );
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Sign-up code email failed: ' . $e->getMessage());
            return false;
        }
    }

    public function register(Request $request)
    {
        // A name or email that only has an unfinished (unverified) sign-up is free to use again.
        $taken = fn ($q) => $q->where('status', '!=', 'unverified');

        $validated = $request->validate([
            'full_name' => 'required|string|max:100',
            'username' => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::unique('users', 'username')->where($taken)],
            'email' => ['required', 'email:rfc', 'max:120', \Illuminate\Validation\Rule::unique('users', 'email')->where($taken)],
            'password' => 'required|min:6',
            // What the person says they are; the AMO still decides the real role when approving.
            'employee_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'requested_role' => 'required|in:department_head,admin,instructor,cashier',
            'requested_iddept' => 'nullable|required_if:requested_role,instructor,department_head,dean|integer|exists:dept,iddept',
        ], [
            'email.unique' => 'This email is already used by another account.',
        ]);

        $email = strtolower(trim($validated['email']));

        // Optional restriction set by the AMO: only these email domains may register.
        $domains = \App\Http\Controllers\SettingController::allowedEmailDomains();
        if ($domains) {
            $host = substr(strrchr($email, '@') ?: '', 1);
            $ok = collect($domains)->contains(fn ($d) => $host === $d || str_ends_with($host, '.' . $d));
            if (!$ok) {
                return response()->json([
                    'message' => 'Please use your school email address (' . implode(' or ', array_map(fn ($d) => '@' . $d, $domains)) . ').',
                    'errors' => ['email' => ['Please use your school email address.']],
                ], 422);
            }
        }

        // Clear old unfinished sign-ups (same username/email, or older than a day).
        User::where('status', 'unverified')->where(function ($q) use ($validated, $email) {
            $q->where('username', $validated['username'])
                ->orWhere('email', $email)
                ->orWhere('created_at', '<', now()->subDay());
        })->delete();

        $user = new User([
            'full_name' => $validated['full_name'],
            'username'  => $validated['username'],
            'password'  => Hash::make($validated['password']),
            'role'      => 'instructor',
        ]);

        // Not usable and not visible to the AMO until the email is verified.
        // (status is not mass assignable, so it is set explicitly.)
        $user->status = 'unverified';
        $user->email = $email;
        $user->employee_id = $validated['employee_id'] ?? null;
        $user->requested_role = $validated['requested_role'];
        $user->requested_iddept = in_array($validated['requested_role'], ['instructor', 'department_head'], true)
            ? ($validated['requested_iddept'] ?? null)
            : null;
        $user->save();

        if (!$this->sendCode($user)) {
            $user->delete();
            return response()->json([
                'message' => 'We could not send the verification email right now. Please try again later or contact the AMO.',
            ], 503);
        }

        return response()->json([
            'message' => 'We sent a 6-digit code to your email.',
            'status' => 'unverified',
            'username' => $user->username,
            'email_masked' => $this->maskEmail($email),
            'resend_seconds' => self::RESEND_SECONDS,
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $v = $request->validate([
            'username' => 'required|string',
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('username', $v['username'])->where('status', 'unverified')->first();
        $invalid = response()->json(['message' => 'This code is not valid. Please sign up again.'], 422);
        if (!$user || !$user->email_code_hash) {
            return $invalid;
        }
        if (!$user->email_code_expires_at || now()->greaterThan($user->email_code_expires_at)) {
            return response()->json(['message' => 'This code has expired. Please request a new one.', 'code' => 'code_expired'], 422);
        }
        if ((int) $user->email_code_attempts >= self::CODE_TRIES) {
            return response()->json(['message' => 'Too many wrong tries. Please request a new code.', 'code' => 'code_locked'], 422);
        }

        if (!hash_equals($user->email_code_hash, $this->hashCode($v['code']))) {
            $user->email_code_attempts = (int) $user->email_code_attempts + 1;
            $user->save();
            $left = max(0, self::CODE_TRIES - (int) $user->email_code_attempts);
            return response()->json([
                'message' => $left > 0 ? "That code is not correct. {$left} tries left." : 'Too many wrong tries. Please request a new code.',
                'code' => $left > 0 ? 'code_wrong' : 'code_locked',
            ], 422);
        }

        // Verified: the request now goes to the AMO for approval.
        $user->status = 'pending';
        $user->email_verified_at = now();
        $user->email_code_hash = null;
        $user->email_code_expires_at = null;
        $user->email_code_attempts = 0;
        $user->save();

        return response()->json([
            'message' => 'Your email is verified. Your account is waiting for administrator approval.',
            'status' => 'pending',
        ]);
    }

    public function resendCode(Request $request)
    {
        $v = $request->validate(['username' => 'required|string']);

        $user = User::where('username', $v['username'])->where('status', 'unverified')->first();
        if (!$user) {
            return response()->json(['message' => 'This sign-up was not found. Please sign up again.'], 422);
        }

        if ($user->email_code_sent_at) {
            $wait = self::RESEND_SECONDS - now()->diffInSeconds($user->email_code_sent_at, true);
            if ($wait > 0) {
                return response()->json([
                    'message' => 'Please wait ' . (int) ceil($wait) . ' seconds before asking for a new code.',
                    'retry_after' => (int) ceil($wait),
                ], 429);
            }
        }

        if (!$this->sendCode($user)) {
            return response()->json(['message' => 'We could not send the email right now. Please try again later.'], 503);
        }

        return response()->json([
            'message' => 'A new code was sent.',
            'resend_seconds' => self::RESEND_SECONDS,
        ]);
    }

    // Public list of departments for the sign-up form (names only).
    public function departments()
    {
        return response()->json(
            \App\Models\Department::orderBy('dept_name')->get(['iddept', 'dept_name'])
        );
    }

    public function login(Request $request)
{
    $validated = $request->validate([
    'username' => 'required',
    'password' => 'required',
    ]);

    $user = User::where('username', $validated['username'])->first();

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'message' => 'Invalid username or password.'
        ], 401);
    }

// Checked after the password on purpose, so only someone who knows
// the password can learn that the account is still waiting.
if (($user->status ?? 'active') === 'unverified') {
    return response()->json([
        'message' => 'Your email is not verified yet. Please sign up again to get a new code.',
        'code' => 'email_unverified',
    ], 403);
}

if (($user->status ?? 'active') === 'pending') {
    return response()->json([
        'message' => 'Your account is waiting for administrator approval. Please try again once an admin has approved it.',
        'code' => 'account_pending',
    ], 403);
}

if (($user->status ?? 'active') !== 'active') {
    return response()->json([
        'message' => 'Your account is not active. Please contact the administrator.',
    ], 403);
}

// Only Admin accounts share the two-session limit. Other roles are unaffected.
// Admin tokens expire after 30 minutes without activity; stale tokens are
// removed before checking the limit so abandoned sessions release their slots.
$token = DB::transaction(function () use ($user) {
    $idleCutoff = now()->subMinutes(30);

    if (strtolower((string) $user->role) === 'amo') {
        // Lock the same admin row for every admin login attempt. This serializes
        // concurrent logins so two simultaneous requests cannot both take the last slot.
        $adminIds = User::whereRaw('LOWER(role) = ?', ['amo'])
            ->orderBy('idUsers')
            ->lockForUpdate()
            ->pluck('idUsers');

        $adminTokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $adminIds);

        // A token with no recorded use is measured from creation time.
        $adminTokens->where(function ($query) use ($idleCutoff) {
            $query->where('last_used_at', '>=', $idleCutoff)
                ->orWhere(function ($query) use ($idleCutoff) {
                    $query->whereNull('last_used_at')
                        ->where('created_at', '>=', $idleCutoff);
                });
        });
        $activeAdminTokens = $adminTokens->count();

        // Delete stale Admin tokens to release slots and prevent old credentials
        // from continuing to work after the inactivity timeout.
        PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $adminIds)
            ->where(function ($query) use ($idleCutoff) {
                $query->where('last_used_at', '<', $idleCutoff)
                    ->orWhere(function ($query) use ($idleCutoff) {
                        $query->whereNull('last_used_at')
                            ->where('created_at', '<', $idleCutoff);
                    });
            })->delete();

        if ($activeAdminTokens >= 2) {
            return null;
        }
    }

    return $user->createToken(
        strtolower((string) $user->role) === 'amo' ? 'cims-admin-session' : 'cims'
    )->plainTextToken;
});

if ($token === null) {
    return response()->json([
        'message' => 'The maximum of 2 simultaneous Admin sessions has been reached. Please try again after an Admin logs out or their session expires after 30 minutes of inactivity.',
        'code' => 'admin_session_limit',
    ], 429);
}

return response()->json([
    'message' => 'Login successful.',
    'user' => $user,
    'token' => $token,
]);
}

// Any signed-in user changes their own password. Needs the current one,
// so a stolen/left-open session alone can't take the account over.
public function changePassword(Request $request)
{
    $validated = $request->validate([
        'current_password' => 'required|string',
        'password' => 'required|string|min:8|max:100|confirmed',
    ]);

    $user = $request->user();

    if (!Hash::check($validated['current_password'], $user->password)) {
        return response()->json([
            'message' => 'Your current password is not correct.',
            'errors' => ['current_password' => ['Your current password is not correct.']],
        ], 422);
    }

    if (Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'message' => 'Your new password must be different from the current one.',
            'errors' => ['password' => ['Your new password must be different from the current one.']],
        ], 422);
    }

    $user->password = $validated['password']; // hashed by the model cast
    $user->must_change_password = false;
    $user->save();

    // Sign every other device/session out; keep the one used right now.
    $currentId = $request->user()->currentAccessToken()->id ?? null;
    $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

    \App\Models\Log::create([
        'idUsers' => $user->idUsers,
        'activity_description' => 'Changed own password',
        'timestamp' => now()->toDateTimeString(),
    ]);

    return response()->json([
        'message' => 'Your password was changed.',
        'user' => $user->fresh(),
    ]);
}

public function logout(Request $request)
{
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'message' => 'Logged out successfully.'
    ]);
}

}
