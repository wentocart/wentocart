<?php

if (! isset($heading, $brand, $steps, $paragraphs, $commands, $actions, $aside, $cssUrl, $logoUrl)) {
    http_response_code(404);
    exit;
}

$stepNumber = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand">
            <img class="brand-logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" width="40" height="40">
            <strong><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <a class="help" href="https://wentocart.com/documentation/" target="_blank" rel="noopener">Need help?</a>
    </header>

    <ol class="stepper" aria-label="Installation steps">
        <?php foreach ($steps as $step): ?>
            <?php
            $state = (string) ($step['state'] ?? '');
            $label = (string) ($step['label'] ?? '');
            $mark = $state === 'done' ? '✓' : (string) $stepNumber;
            ?>
            <li class="step<?= $state === '' ? '' : ' '.$state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
                <span class="dot"><?= htmlspecialchars($mark, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($state === 'current'): ?><span class="state">Current</span><?php endif; ?>
                <?php if ($state === 'done'): ?><span class="state">Done</span><?php endif; ?>
            </li>
            <?php $stepNumber++; ?>
        <?php endforeach; ?>
    </ol>

    <div class="card">
        <aside class="side">
            <h2>Installation</h2>
            <p><?= htmlspecialchars($aside, ENT_QUOTES, 'UTF-8') ?></p>
        </aside>
        <section class="main">
            <h1><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>
            <?php foreach ($paragraphs as $paragraph): ?>
                <p class="lead"><?= htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endforeach; ?>

            <?php if ($commands !== []): ?>
                <p class="cmd-label">Run this command</p>
                <?php foreach ($commands as $command): ?>
                    <code class="cmd"><?= htmlspecialchars((string) $command, ENT_QUOTES, 'UTF-8') ?></code>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($showProgress): ?>
                <div class="bar" aria-hidden="true"><div id="bar"></div></div>
                <p class="lead"><span id="pct">0</span>% — <span id="msg">Starting…</span></p>
                <p class="lead" id="help" hidden></p>
            <?php endif; ?>

            <?php if ($bodyView !== '' && is_file(__DIR__.DIRECTORY_SEPARATOR.$bodyView)): ?>
                <?php include __DIR__.DIRECTORY_SEPARATOR.$bodyView; ?>
            <?php endif; ?>

            <?php if ($actions !== []): ?>
                <div class="footer">
                    <?php foreach ($actions as $action): ?>
                        <?php
                        $class = ! empty($action['primary']) ? 'btn-primary' : 'btn-outline';
                        $disabled = ! empty($action['disabled']);
                        $id = (string) ($action['id'] ?? '');
                        $idAttr = $id !== '' ? ' id="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'"' : '';
                        ?>
                        <?php if (! empty($action['reload'])): ?>
                            <button type="button" class="<?= $class ?>"<?= $idAttr ?><?= $disabled ? ' disabled' : '' ?> onclick="location.reload()"><?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?></button>
                        <?php else: ?>
                            <a class="<?= $class ?><?= $disabled ? ' is-disabled' : '' ?>"<?= $idAttr ?><?= $disabled ? ' aria-disabled="true" tabindex="-1"' : '' ?> href="<?= $disabled ? '#' : htmlspecialchars((string) ($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php if ($scriptUrl !== ''): ?>
    <script src="<?= htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
