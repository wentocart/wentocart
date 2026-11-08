<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\JumpShortcut;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $admin = $request->user('admin');
        $admin->loadMissing(['team', 'profile', 'roles']);

        return Inertia::render('Account/Show', [
            'account' => $this->payload($admin),
        ]);
    }

    public function edit(Request $request): Response
    {
        $admin = $request->user('admin');
        $admin->loadMissing(['team', 'profile', 'roles']);

        return Inertia::render('Account/Edit', [
            'account' => $this->payload($admin),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($admin->id)],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => CustomerPassword::rules(required: false, confirmed: false),
            'job_title' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
            'avatar' => [$admin->avatar_path ? 'nullable' : 'required', 'file', 'image', 'max:5120'],
            'jump_shortcut' => ['nullable', 'string', 'max:32'],
            'jump_shortcut_clear' => ['sometimes', 'boolean'],
        ], CustomerPassword::messages());

        if ($request->boolean('jump_shortcut_clear')) {
            $admin->jump_shortcut = null;
        } elseif (array_key_exists('jump_shortcut', $data)) {
            $raw = trim((string) ($data['jump_shortcut'] ?? ''));
            if ($raw === '') {
                $admin->jump_shortcut = null;
            } else {
                $chord = JumpShortcut::normalize($raw);
                if ($chord === null || ! JumpShortcut::isValid($chord)) {
                    throw ValidationException::withMessages([
                        'jump_shortcut' => 'Choose Ctrl, Alt, Window, or Shift plus a letter or number. That shortcut is reserved.',
                    ]);
                }
                $admin->jump_shortcut = $chord;
            }
        }

        $passwordChanged = false;
        if (! empty($data['password'])) {
            if (! Hash::check((string) ($data['current_password'] ?? ''), $admin->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Current password is incorrect.',
                ]);
            }
            $admin->password = $data['password'];
            $admin->remember_token = Str::random(60);
            $passwordChanged = true;
        }

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);

        $admin->first_name = $first;
        $admin->last_name = $last;
        $admin->name = trim($first.' '.$last);
        $admin->email = $data['email'];
        $admin->job_title = ($data['job_title'] ?? '') ?: null;
        $admin->phone = ($data['phone'] ?? '') ?: null;
        $admin->note = ($data['note'] ?? '') ?: null;

        if ($request->hasFile('avatar')) {
            if ($admin->avatar_path) {
                Storage::disk('public')->delete($admin->avatar_path);
            }
            $admin->avatar_path = $request->file('avatar')->store('admins/'.$admin->id, 'public');
        }

        $admin->save();

        if ($passwordChanged) {
            SessionRevoker::revokeOthers('admin', $admin->id);
        }

        return redirect('/admin/account')->with('success', 'Profile saved.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload($admin): array
    {
        $isSuper = $admin->isSuperAdmin();
        $permissions = $isSuper ? [] : $admin->permissionSlugs();
        $first = trim((string) ($admin->first_name ?: ''));
        $last = trim((string) ($admin->last_name ?: ''));

        if ($first === '' && $last === '' && $admin->name) {
            $parts = preg_split('/\s+/', trim((string) $admin->name), 2) ?: [];
            $first = $parts[0] ?? '';
            $last = $parts[1] ?? '';
        }

        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $admin->email,
            'job_title' => $admin->job_title,
            'phone' => $admin->phone,
            'note' => $admin->note,
            'avatar_url' => $admin->avatarUrl(),
            'has_avatar' => (bool) $admin->avatar_path,
            'jump_shortcut' => JumpShortcut::normalize($admin->jump_shortcut),
            'jump' => JumpShortcut::resolve($admin),
            'jump_shop' => JumpShortcut::shopChord(),
            'jump_default' => JumpShortcut::default(),
            'team' => $admin->team ? [
                'name' => $admin->team->name,
                'description' => $admin->team->description,
            ] : null,
            'profile' => $admin->profile ? [
                'name' => $admin->profile->name,
                'description' => $admin->profile->description,
            ] : null,
            'roles' => $admin->roles
                ->map(fn ($role) => [
                    'name' => $role->name,
                    'slug' => $role->slug,
                ])
                ->values()
                ->all(),
            'permission_count' => $isSuper ? null : count($permissions),
            'is_super' => $isSuper,
            'is_enabled' => (bool) $admin->is_enabled,
            'created_at' => optional($admin->created_at)?->toIso8601String(),
            'updated_at' => optional($admin->updated_at)?->toIso8601String(),
        ];
    }
}
