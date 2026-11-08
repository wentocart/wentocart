import { t } from '../../ui';
import { useExtensionBusy } from './extensionBusy';

export function extensionPrimaryAction(row, visit) {
    if (!row.installed) {
        if (row.installable) {
            return {
                label: t('admin.apps.install', 'Install'),
                onClick: () => visit(row.key, 'post', `/admin/extensions/catalog/${row.vendor}/${row.name}`),
            };
        }

        return null;
    }

    if (row.can_upgrade && row.pending_upgrade_version) {
        return {
            label: t('admin.apps.upgrade', 'Upgrade'),
            onClick: () => visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/upgrade`),
        };
    }

    if (!row.enabled) {
        return {
            label: t('admin.apps.enable', 'Enable'),
            onClick: () => visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/enable`, { enabled: true }),
        };
    }

    return {
        label: t('admin.apps.configure', 'Configure'),
        href: row.href,
    };
}

export function extensionMenuItems(row, { visit } = {}) {
    const items = [];

    if (row.installed && row.can_upgrade && row.pending_upgrade_version) {
        items.push({
            key: 'upgrade',
            icon: 'refresh',
            label: t('admin.apps.upgrade_to', 'Upgrade to v:version').replace(':version', row.pending_upgrade_version),
            onClick: () => visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/upgrade`),
        });
    }

    if (row.installed && row.enabled) {
        items.push({
            key: 'disable',
            icon: 'ban',
            label: t('admin.apps.disable', 'Disable'),
            onClick: () => visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/enable`, { enabled: false }),
        });
    } else if (row.installed && !row.enabled) {
        items.push({
            key: 'enable',
            icon: 'check-circle',
            label: t('admin.apps.enable', 'Enable'),
            onClick: () => visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/enable`, { enabled: true }),
        });
    }

    if (row.installed && row.href) {
        items.push({
            key: 'configure',
            icon: 'settings',
            label: t('admin.apps.configure', 'Configure'),
            href: row.href,
        });
    }

    if (row.installed && row.can_reset !== false) {
        items.push({
            key: 'reset',
            icon: 'refresh',
            label: t('admin.apps.reset', 'Reset'),
            danger: true,
            onClick: () => {
                if (window.confirm(t(
                    'admin.apps.reset_confirm',
                    'Reset this extension? All of its settings will be deleted, then it will be reinstalled.',
                ))) {
                    visit(row.key, 'post', `/admin/extensions/${row.vendor}/${row.name}/reset`);
                }
            },
        });
    }

    if (row.installed && row.can_uninstall !== false) {
        items.push({
            key: 'uninstall',
            icon: 'ban',
            label: t('admin.apps.uninstall', 'Uninstall'),
            danger: true,
            onClick: () => {
                if (window.confirm(t('admin.apps.uninstall_confirm', 'Uninstall this extension? It will leave the shop but files stay on disk so you can install again.'))) {
                    visit(row.key, 'delete', `/admin/extensions/${row.vendor}/${row.name}`);
                }
            },
        });
    }

    if (row.can_delete) {
        items.push({
            key: 'delete',
            icon: 'trash',
            label: t('admin.apps.delete', 'Delete'),
            danger: true,
            onClick: () => {
                if (window.confirm(t('admin.apps.delete_confirm', 'Delete this extension from disk? This cannot be undone without uploading or restoring the zip.'))) {
                    visit(row.key, 'delete', `/admin/extensions/${row.vendor}/${row.name}/files`);
                }
            },
        });
    }

    return items;
}

export function extensionStatusBadges(row) {
    const badges = [];

    if (!row.installed) {
        if (row.installable) {
            badges.push({ key: 'available', tone: 'info', label: t('admin.apps.available', 'Available') });
        } else if (row.blocked) {
            badges.push({ key: 'blocked', tone: 'warning', label: t('admin.apps.blocked', 'Blocked') });
        }
    } else {
        badges.push({
            key: 'state',
            tone: row.enabled ? 'success' : 'warning',
            label: row.enabled ? t('admin.apps.enabled', 'Enabled') : t('admin.apps.disabled', 'Disabled'),
        });
        if (row.health_status === 'error') {
            badges.push({ key: 'health', tone: 'danger', label: t('admin.apps.health_error', 'Unhealthy') });
        } else if (row.health_status === 'ok') {
            badges.push({ key: 'healthy', tone: 'success', label: t('admin.apps.health_ok', 'Healthy'), dot: false });
        }
        if (row.compatibility?.ok === false) {
            badges.push({ key: 'compat', tone: 'danger', label: t('admin.apps.incompatible_short', 'Incompatible') });
        }
        if ((row.dependencies?.errors || []).length > 0) {
            badges.push({ key: 'deps', tone: 'warning', label: t('admin.apps.needs_attention', 'Needs attention') });
        }
        if (row.can_upgrade && row.pending_upgrade_version) {
            badges.push({
                key: 'upgrade',
                tone: 'info',
                label: t('admin.apps.upgrade_ready', 'Upgrade ready (v:version)').replace(':version', row.pending_upgrade_version),
            });
        }
    }

    return badges;
}

export function extensionPrimaryStatus(row) {
    return extensionStatusBadges(row)[0] || null;
}

/** Hook helper: primary + menu for a row using shared async visit. */
export function useExtensionRowActions(row) {
    const { visit, isBusy } = useExtensionBusy();
    const busy = isBusy(row.key);
    const primary = extensionPrimaryAction(row, visit);
    const menuItems = extensionMenuItems(row, { visit })
        .filter((item) => item.label !== primary?.label)
        .map((item) => (busy ? { ...item, disabled: true } : item));

    return { visit, busy, primary, menuItems };
}
