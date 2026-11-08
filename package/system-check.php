<?php

if (! is_array($compat)) {
    return;
}
$rows = is_array($compat['rows'] ?? null) ? $compat['rows'] : [];
?>
<div class="banner <?= $ready ? 'ok' : 'bad' ?>">
    <?php if ($ready): ?>
        This PHP is compatible. Continue to unpack the shop.
    <?php else: ?>
        Some requirements are not met. The shop stays packed until every row passes. Fix the items below, restart this PHP, then reload.
    <?php endif; ?>
</div>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Check</th><th>Found</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?= htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    <?php if (! empty($row['hint'])): ?>
                        <div class="hint"><?= htmlspecialchars((string) $row['hint'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (! empty($row['extensions']) && is_array($row['extensions'])): ?>
                        <div class="ext">
                            <?php foreach ($row['extensions'] as $ext): ?>
                                <span class="<?= ! empty($ext['ok']) ? 'ok' : 'bad' ?>"><?= htmlspecialchars((string) ($ext['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>: <?= ! empty($ext['ok']) ? 'Yes' : 'No' ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="<?= ! empty($row['ok']) ? 'yes' : 'no' ?>"><?= htmlspecialchars((string) ($row['status'] ?? (! empty($row['ok']) ? 'Compatible' : 'Incomplete')), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
