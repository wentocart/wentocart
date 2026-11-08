# Merchant-style zip with vendor/ + public/build so Prepare can be skipped.
# Output (any drive): <repo>\dist\wentocart.zip
# Override: -OutDir D:\releases   or env WENTO_ZIP_OUT=D:\releases\wentocart.zip
# Linux/macOS: ./package/build-install-zip.sh
param(
    [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'

$src = Split-Path $PSScriptRoot -Parent
if (-not (Test-Path (Join-Path $src 'artisan'))) {
    throw "Could not find the shop source next to package/"
}

if ($env:WENTO_ZIP_OUT -and $env:WENTO_ZIP_OUT.Trim() -ne '') {
    $zipOut = $env:WENTO_ZIP_OUT.Trim()
    $outRoot = Split-Path $zipOut -Parent
} elseif ($OutDir -and $OutDir.Trim() -ne '') {
    $outRoot = $OutDir.Trim()
    $zipOut = Join-Path $outRoot 'wentocart.zip'
} else {
    $outRoot = Join-Path $src 'dist'
    $zipOut = Join-Path $outRoot 'wentocart.zip'
}

$staging = Join-Path $env:TEMP 'wentocart-inner'
$wrapper = Join-Path $env:TEMP 'wentocart-wrapper'

Add-Type -AssemblyName System.IO.Compression.FileSystem

function Reset-Dir([string]$path) {
    if (Test-Path $path) {
        Remove-Item $path -Recurse -Force
    }
    New-Item $path -ItemType Directory | Out-Null
}

Push-Location $src
try {
    if (-not (Test-Path (Join-Path $src 'vendor\autoload.php'))) {
        Write-Output 'Running composer install --no-dev…'
        & composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
        if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit $LASTEXITCODE" }
    }
    # Always rebuild frontend assets for the merchant zip. Skipping when
    # public/build/manifest.json already exists shipped stale Host / extension
    # navigation bundles after source-only merges.
    if ($env:WENTO_SKIP_FRONTEND_BUILD -eq '1') {
        Write-Output 'Skipping frontend build (WENTO_SKIP_FRONTEND_BUILD=1).'
    } else {
        $lock = Join-Path $src 'package-lock.json'
        $modules = Join-Path $src 'node_modules'
        $needNpmInstall = -not (Test-Path $modules)
        if ((Test-Path $lock) -and (Test-Path $modules)) {
            $needNpmInstall = (Get-Item $lock).LastWriteTime -gt (Get-Item $modules).LastWriteTime
        }
        if ($needNpmInstall) {
            Write-Output 'Running npm install…'
            if (Test-Path $lock) {
                & npm ci --no-fund --no-audit
            } else {
                & npm install --no-fund --no-audit
            }
            if ($LASTEXITCODE -ne 0) { throw "npm install failed with exit $LASTEXITCODE" }
        } else {
            Write-Output 'Reusing node_modules (package-lock.json unchanged).'
        }
        Write-Output 'Running npm run build…'
        & npm run build
        if ($LASTEXITCODE -ne 0) { throw "npm run build failed with exit $LASTEXITCODE" }
    }
    if (-not (Test-Path (Join-Path $src 'public\build\manifest.json'))) {
        throw 'public/build/manifest.json missing after build'
    }
    & php (Join-Path $src 'package\verify-admin-build.php') (Join-Path $src 'public\build\manifest.json')
    if ($LASTEXITCODE -ne 0) { throw "admin build verification failed with exit $LASTEXITCODE" }
    if (-not (Test-Path (Join-Path $src 'vendor\autoload.php'))) {
        throw 'vendor/autoload.php missing after composer'
    }
}
finally {
    Pop-Location
}

foreach ($p in @($staging, $wrapper)) {
    Reset-Dir $p
}

# Production zip: built assets and a fresh --no-dev vendor. No React source, tests, or dev packages.
$xd = @(
    (Join-Path $src 'node_modules'),
    (Join-Path $src '.git'),
    (Join-Path $src '.cursor'),
    (Join-Path $src '.idea'),
    (Join-Path $src '.vscode'),
    (Join-Path $src '.phpunit.cache'),
    (Join-Path $src 'package'),
    (Join-Path $src 'public\hot'),
    (Join-Path $src 'public\storage'),
    (Join-Path $src 'storage\logs'),
    (Join-Path $src 'storage\pail'),
    (Join-Path $src 'tests'),
    (Join-Path $src 'docs'),
    (Join-Path $src 'agent-skills'),
    (Join-Path $src 'api-collections'),
    (Join-Path $src 'bin'),
    (Join-Path $src 'dist'),
    (Join-Path $src 'docker'),
    (Join-Path $src 'extension-src'),
    (Join-Path $src 'tools'),
    (Join-Path $src 'extensions'),
    (Join-Path $src 'headless'),
    (Join-Path $src 'mobile'),
    (Join-Path $src 'plugin-packs'),
    (Join-Path $src 'plugins'),
    (Join-Path $src 'vendor'),
    (Join-Path $src 'storefront'),
    (Join-Path $src 'resources\js'),
    (Join-Path $src 'resources\css'),
    (Join-Path $src 'database\factories'),
    (Join-Path $src 'database\migrations'),
    (Join-Path $src 'scripts'),
    (Join-Path $src '.github')
) -join ' '
$xf = @(
    '.env', '.env.backup', 'installed.lock', 'installing.lock', 'status.json',
    'INSTALL.bat', 'INSTALL.sh', 'start-installer.bat', 'start-installer.sh',
    'AGENTS.md', 'phpunit.xml', 'docker-compose.yml', '.dockerignore',
    '.editorconfig', '.npmrc', 'package.json', 'package-lock.json', 'vite.config.js', 'README.md', 'serve-shop.sh',
    'sqlite-schema.sql'
) -join ' '

cmd /c "robocopy `"$src`" `"$staging`" /E /NFL /NDL /NJH /NJS /NC /NS /NP /XD $xd /XF $xf" | Out-Null
if ($LASTEXITCODE -ge 8) {
    throw "robocopy failed with exit $LASTEXITCODE"
}

foreach ($junk in @(
    (Join-Path $staging 'storage\app\installed.lock'),
    (Join-Path $staging 'storage\app\installing.lock'),
    (Join-Path $staging 'storage\app\tidy-installer'),
    (Join-Path $staging 'storage\app\install-answers.json'),
    (Join-Path $staging 'storage\framework\setup-status.json'),
    (Join-Path $staging 'storage\framework\install-status.json'),
    (Join-Path $staging 'storage\framework\install-job.json'),
    (Join-Path $staging 'setup\status.json'),
    (Join-Path $staging 'database\schema\sqlite-schema.sql'),
    (Join-Path $staging 'database\migrations'),
    (Join-Path $staging 'extensions'),
    (Join-Path $staging 'headless'),
    (Join-Path $staging 'mobile')
)) {
    if (Test-Path $junk) {
        Remove-Item $junk -Recurse -Force
    }
}
Get-ChildItem (Join-Path $staging 'storage') -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Extension -in '.json', '.key' } |
    Remove-Item -Force

Write-Output 'Installing production PHP packages…'
Push-Location $staging
try {
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    if ($LASTEXITCODE -ne 0) {
        throw 'composer install --no-dev failed in the zip staging folder'
    }
    php (Join-Path $PSScriptRoot 'export-ui-catalog.php') $src (Join-Path $staging 'resources\i18n\ui-source.json')
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not write the translation catalog for the production zip'
    }
}
finally {
    Pop-Location
}

if (-not (Test-Path (Join-Path $staging 'vendor\autoload.php'))) {
    throw 'staging is missing vendor/autoload.php'
}
if (-not (Test-Path (Join-Path $staging 'public\build\manifest.json'))) {
    throw 'staging is missing public/build/manifest.json'
}
# Official release ships no extensions. Merchants install them later from the shop.
$extensionsHome = Join-Path $staging 'extensions'
if (Test-Path $extensionsHome) {
    Remove-Item $extensionsHome -Recurse -Force
}
New-Item $extensionsHome -ItemType Directory | Out-Null
Set-Content -Path (Join-Path $extensionsHome '.gitignore') -Value "*`n!.gitignore`n!README.md`n" -Encoding ascii
$extensionsReadme = Join-Path $src 'extensions\README.md'
if (Test-Path $extensionsReadme) {
    Copy-Item $extensionsReadme (Join-Path $extensionsHome 'README.md') -Force
}
$shippedApps = Get-ChildItem $extensionsHome -Recurse -Filter app.json -ErrorAction SilentlyContinue
if ($shippedApps) {
    throw 'release zip must not include extension packages'
}

# Robocopy drops storage\logs. A fresh install still needs these writable dirs.
$keepDirs = @(
    'storage\logs',
    'storage\framework',
    'storage\framework\cache',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'bootstrap\cache'
)
foreach ($dir in $keepDirs) {
    $full = Join-Path $staging $dir
    if (-not (Test-Path $full)) {
        New-Item $full -ItemType Directory -Force | Out-Null
    }
    $ignore = Join-Path $full '.gitignore'
    if (-not (Test-Path $ignore)) {
        Set-Content -Path $ignore -Value "*`n!.gitignore`n" -Encoding ascii
    }
}

$innerZip = Join-Path $wrapper 'shop.zip'
if (Test-Path $innerZip) {
    Remove-Item $innerZip -Force
}

function Compress-DirUnixPaths([string]$sourceDir, [string]$zipPath) {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path $zipPath) {
        Remove-Item $zipPath -Force
    }
    $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $root = (Resolve-Path $sourceDir).Path.TrimEnd('\', '/')
        Get-ChildItem -LiteralPath $root -Recurse -Force -File | ForEach-Object {
            $rel = $_.FullName.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
            $level = [System.IO.Compression.CompressionLevel]::Fastest
            if ($rel.EndsWith('.zip', [System.StringComparison]::OrdinalIgnoreCase)) {
                $level = [System.IO.Compression.CompressionLevel]::NoCompression
            }
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $_.FullName,
                $rel,
                $level
            )
        }
    }
    finally {
        $zip.Dispose()
    }
}

# Merchant zip must use RewriteBase / — a folder-specific value from the
# build machine would 404 /admin/login on every other install path.
Write-Output 'Writing path-agnostic Apache .htaccess for the merchant zip…'
Copy-Item (Join-Path $PSScriptRoot 'preinstall.htaccess') (Join-Path $staging '.htaccess') -Force
$installerRules = Join-Path $staging 'app\Domain\Installer'
New-Item $installerRules -ItemType Directory -Force | Out-Null
Copy-Item (Join-Path $PSScriptRoot 'preinstall.htaccess') (Join-Path $installerRules 'preinstall.htaccess') -Force
$publicHt = Join-Path $staging 'public\.htaccess'
if (Test-Path $publicHt) {
    $body = [System.IO.File]::ReadAllText($publicHt)
    $body = [regex]::Replace($body, '(?m)^\s*RewriteBase\s+\S+', 'RewriteBase /')
    [System.IO.File]::WriteAllText($publicHt, $body)
}

Write-Output 'Compressing inner shop.zip (vendor + public/build)…'
Compress-DirUnixPaths $staging $innerZip

$wrappers = @(
    'index.php', 'checks.php', 'install-guide.php', 'system-check.php', 'installer.css', 'unpack.js',
    'preinstall.htaccess', 'logo.jpeg', 'Install.vbs', 'install.sh', 'server.php', '.htaccess'
)
foreach ($name in $wrappers) {
    Copy-Item (Join-Path $PSScriptRoot $name) (Join-Path $wrapper $name) -Force
}
Copy-Item (Join-Path $src 'scripts\install\helper.php') (Join-Path $wrapper 'helper.php') -Force

New-Item $outRoot -ItemType Directory -Force | Out-Null
if (Test-Path $zipOut) {
    Remove-Item $zipOut -Force
}
Write-Output 'Compressing outer wentocart.zip…'
Compress-DirUnixPaths $wrapper $zipOut

Get-Item $zipOut | Select-Object FullName, Length, LastWriteTime
& php -d memory_limit=512M (Join-Path $PSScriptRoot 'zip-tree.php') verify $zipOut
if ($LASTEXITCODE -ne 0) {
    throw 'wentocart.zip failed verification'
}
