<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PermissionCatalogController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->where('slug', '!=', 'super_admin')
            ->with('permissions')
            ->orderBy('name')
            ->get();

        $groups = collect(PageCatalog::navGroups())->map(function (array $group) use ($roles) {
            return [
                'heading' => $group['heading'],
                'heading_key' => $group['heading_key'] ?? PageCatalog::headingKey($group['heading']),
                'pages' => collect($group['pages'])->map(function (array $page) use ($roles) {
                    $usedBy = $roles
                        ->filter(fn (Role $role) => PageCatalog::grants($role->permissions->pluck('slug')->all(), $page['slug']))
                        ->pluck('name')
                        ->values()
                        ->all();

                    return [
                        'slug' => $page['slug'],
                        'label' => $page['label'],
                        'label_key' => $page['label_key'] ?? ('admin.pages.'.$page['slug']),
                        'href' => $page['href'],
                        'roles' => $usedBy,
                    ];
                })->all(),
            ];
        })->all();

        $allPages = collect($groups)->flatMap(fn (array $group) => $group['pages']);
        $unused = $allPages->filter(fn (array $page) => empty($page['roles']))->count();

        return Inertia::render('Staff/Permissions/Index', [
            'groups' => $groups,
            'stats' => [
                'pages' => $allPages->count(),
                'groups' => count($groups),
                'roles' => $roles->count(),
                'unused' => $unused,
            ],
        ]);
    }
}
