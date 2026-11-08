<?php

namespace App\Http\Controllers\Installer;

use App\Domain\Installer\InstallerService;
use App\Domain\Localization\WorldCountries;
use App\Domain\Security\CustomerPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class InstallerController extends Controller
{
    public function show(InstallerService $installer): View|RedirectResponse
    {
        if ($installer->alreadyInstalled()) {
            return redirect('/installed');
        }

        return view('installer', [
            'shop' => config('wento.name'),
            'version' => config('wento.version'),
            'checks' => $installer->compatibility(),
            'ready' => $installer->ready(),
            'appUrl' => url('/'),
            'countries' => collect(WorldCountries::all())->sortBy('name')->values(),
        ]);
    }

    public function repair(InstallerService $installer): JsonResponse
    {
        if ($installer->alreadyInstalled()) {
            abort(404);
        }

        @set_time_limit(300);

        try {
            $log = $installer->repair();
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => implode("\n", $log),
            'checks' => $installer->compatibility(),
            'ready' => $installer->ready(),
        ]);
    }

    public function testDatabase(Request $request, InstallerService $installer): JsonResponse
    {
        if ($installer->alreadyInstalled()) {
            abort(404);
        }

        $data = $this->databaseRules($request);

        try {
            $installer->testConnection($data);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Database connection works.']);
    }

    public function store(Request $request, InstallerService $installer): JsonResponse|RedirectResponse
    {
        if ($installer->alreadyInstalled()) {
            abort(404);
        }

        $data = $request->validate([
            ...$this->databaseRuleSet(),
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['required', 'email'],
            'store_address' => ['nullable', 'string', 'max:2000'],
            'store_country' => ['nullable', 'string', 'max:2'],
            'store_line1' => ['nullable', 'string', 'max:255'],
            'store_line2' => ['nullable', 'string', 'max:255'],
            'store_city' => ['nullable', 'string', 'max:120'],
            'store_region' => ['nullable', 'string', 'max:120'],
            'store_postal' => ['nullable', 'string', 'max:32'],
            'app_url' => ['nullable', 'string'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email'],
            'admin_password' => CustomerPassword::rules(confirmed: false),
            'language' => ['nullable', 'string', 'max:8'],
            'currency' => ['nullable', 'string', 'max:8'],
            'install_demo_data' => ['nullable', 'boolean'],
        ], [
            'admin_password.required' => 'Enter a password.',
            'admin_password.min' => 'Password must be 8 to 72 characters.',
            'admin_password.max' => 'Password must be 8 to 72 characters.',
            'admin_password.regex' => 'Password needs a capital letter, a number, and no spaces.',
        ]);

        $data['store_address'] = wento_compose_store_address($request->all());
        $data['app_url'] = rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
        $data['detected_url'] = $data['app_url'];

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        try {
            $installer->install($data);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['install' => $e->getMessage()])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => url('/installed')]);
        }

        return redirect('/installed');
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseRules(Request $request): array
    {
        return $request->validate($this->databaseRuleSet());
    }

    /**
     * @return array<string, list<string>>
     */
    protected function databaseRuleSet(): array
    {
        return [
            'db_connection' => ['required', 'in:mysql,sqlite'],
            'db_host' => ['nullable', 'string'],
            'db_port' => ['nullable', 'string'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['nullable', 'string'],
            'db_password' => ['nullable', 'string'],
            'db_prefix' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail) {
                if (! wento_db_prefix_valid((string) $value)) {
                    $fail('Table prefix must start with a letter, then letters, numbers, or underscores (examples: wento, tiger_, tia_2_, Lo_as_as_). Spaces and hyphens are not allowed.');
                }
            }],
        ];
    }
}
