import { useEffect, useRef, useState } from 'react';
import Alert from './Alert';
import Button from './Button';
import Loader from './Loader';
import { t } from './i18n';
import './email-canvas.css';

function canvasHtml(html) {
    if (!html) {
        return '';
    }
    const body = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);

    return body ? body[1] : html;
}

function wrapEmail(inner) {
    if (!inner) {
        return '';
    }
    if (/<html/i.test(inner)) {
        return inner;
    }

    return `<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f7f8fa;">${inner}</body></html>`;
}

function parseDesign(design) {
    if (!design) {
        return null;
    }
    if (typeof design === 'object') {
        return design;
    }
    try {
        const data = JSON.parse(design);

        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

function unwrapGrapes(mod) {
    const candidates = [
        mod?.grapesjs,
        mod?.default?.grapesjs,
        mod?.default,
        mod?.default?.default,
        mod,
    ];

    return candidates.find((item) => item && typeof item.init === 'function') || null;
}

function unwrapPlugin(mod) {
    let value = mod;
    for (let i = 0; i < 4; i += 1) {
        if (typeof value === 'function') {
            return value;
        }
        if (value && typeof value === 'object' && typeof value.default === 'function') {
            value = value.default;
            continue;
        }
        if (value && typeof value === 'object' && typeof value['grapesjs-preset-newsletter'] === 'function') {
            return value['grapesjs-preset-newsletter'];
        }
        if (value && typeof value === 'object' && 'default' in value) {
            value = value.default;
            continue;
        }
        break;
    }

    return typeof value === 'function' ? value : null;
}

/**
 * Visual newsletter canvas. Pages import this wrapper only so other admin
 * screens do not load GrapesJS.
 */
export default function EmailCanvas({ html = '', design = null, onReady, hideDeviceToolbar = false }) {
    const rootRef = useRef(null);
    const editorRef = useRef(null);
    const [status, setStatus] = useState('loading');
    const [device, setDevice] = useState('desktop');

    useEffect(() => {
        let cancelled = false;

        (async () => {
            try {
                const grapesjsMod = await import('grapesjs');
                const pluginMod = await import('grapesjs-preset-newsletter');
                await import('grapesjs/dist/css/grapes.min.css');
                const grapesjs = unwrapGrapes(grapesjsMod);
                const plugin = unwrapPlugin(pluginMod);
                if (!grapesjs) {
                    throw new Error('GrapesJS failed to load');
                }
                if (cancelled || !rootRef.current) {
                    return;
                }

                const plugins = plugin
                    ? [
                        (ed) => plugin(ed, {
                            useCustomTheme: false,
                            showBlocksOnLoad: false,
                            textCleanCanvas: t('admin.email.clear_this_email_design', 'Clear this email design?'),
                        }),
                    ]
                    : [];

                const config = {
                    container: rootRef.current,
                    height: '640px',
                    fromElement: false,
                    noticeOnUnload: false,
                    telemetry: false,
                    storageManager: false,
                    selectorManager: { componentFirst: true },
                    deviceManager: {
                        devices: [
                            { id: 'desktop', name: 'Desktop', width: '600px' },
                            { id: 'mobile', name: 'Mobile', width: '320px', widthMedia: '480px' },
                        ],
                    },
                };

                let editor;
                try {
                    editor = grapesjs.init({ ...config, plugins });
                } catch (error) {
                    console.error('Email canvas plugin failed, starting a basic canvas', error);
                    editor = grapesjs.init({ ...config, plugins: [] });
                }

                editor.BlockManager.add('email-title', {
                    label: t('admin.email.block_title', 'Title'),
                    category: t('admin.email.block_content', 'Content'),
                    content: '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-family:Arial,sans-serif;color:#171717;">Heading</h1>',
                });
                editor.BlockManager.add('email-paragraph', {
                    label: t('admin.email.block_paragraph', 'Paragraph'),
                    category: t('admin.email.block_content', 'Content'),
                    content: '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;font-family:Arial,sans-serif;color:#171717;">Write a short paragraph. Use {{customer_name}} for the shopper.</p>',
                });
                editor.BlockManager.add('email-list', {
                    label: t('admin.email.block_list', 'List'),
                    category: t('admin.email.block_content', 'Content'),
                    content: '<ul style="margin:0 0 16px;padding-left:20px;font-family:Arial,sans-serif;color:#171717;"><li>First point</li><li>Second point</li></ul>',
                });
                editor.BlockManager.add('email-spacer', {
                    label: t('admin.email.block_spacer', 'Spacer'),
                    category: t('admin.email.block_content', 'Content'),
                    content: '<div style="height:24px;line-height:24px;font-size:1px;">&nbsp;</div>',
                });
                editor.BlockManager.add('email-html', {
                    label: t('admin.email.block_html', 'HTML'),
                    category: t('admin.email.block_content', 'Content'),
                    content: '<div style="font-family:Arial,sans-serif;font-size:14px;">Custom HTML</div>',
                });

                try {
                    const project = parseDesign(design);
                    if (project && (project.pages || project.assets || project.styles)) {
                        editor.loadProjectData(project);
                    } else if (html) {
                        editor.setComponents(canvasHtml(html));
                    }
                } catch (error) {
                    console.error('Email canvas could not load the saved design', error);
                    if (html) {
                        try {
                            editor.setComponents(canvasHtml(html));
                        } catch {
                            // Keep an empty canvas rather than failing the editor.
                        }
                    }
                }

                editor.setDevice('desktop');
                editorRef.current = editor;
                if (cancelled) {
                    editor.destroy();
                    editorRef.current = null;
                    return;
                }

                const api = {
                    editor,
                    setDevice(next) {
                        editor.setDevice(next === 'mobile' ? 'mobile' : 'desktop');
                    },
                    getValue() {
                        let inner = '';
                        try {
                            inner = editor.runCommand('gjs-get-inlined-html') || '';
                        } catch {
                            const css = editor.getCss() || '';
                            inner = `${css ? `<style>${css}</style>` : ''}${editor.getHtml() || ''}`;
                        }

                        return {
                            html: wrapEmail(inner),
                            design: JSON.stringify(editor.getProjectData()),
                        };
                    },
                    insertToken(key) {
                        const safe = String(key || '').replace(/[^a-z0-9_]/gi, '');
                        if (!safe) {
                            return;
                        }
                        const token = `{{${safe}}}`;
                        const selected = editor.getSelected();
                        const current = selected?.get?.('content');
                        if (selected && typeof current === 'string' && current !== '' && !/<[a-z]/i.test(current)) {
                            selected.set('content', `${current}${current.endsWith(' ') ? '' : ' '}${token}`);
                            return;
                        }
                        editor.addComponents(`<p style="margin:0 0 16px;font-size:15px;line-height:1.6;font-family:Arial,sans-serif;color:#171717;">${token}</p>`);
                    },
                };

                onReady?.(api);
                if (!cancelled) {
                    setStatus('ready');
                }
            } catch (error) {
                console.error('Email canvas failed to start', error);
                if (!cancelled) {
                    setStatus('error');
                }
            }
        })();

        return () => {
            cancelled = true;
            try {
                editorRef.current?.destroy();
            } catch {
                // GrapesJS can throw if the canvas was already removed.
            }
            editorRef.current = null;
        };
        // Load once for this canvas instance. Parent remounts with a key when the template changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const switchDevice = (next) => {
        setDevice(next);
        editorRef.current?.setDevice(next === 'mobile' ? 'mobile' : 'desktop');
    };

    return (
        <div className="email-canvas">
            {hideDeviceToolbar ? null : (
                <div className="email-canvas-toolbar">
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant={device === 'desktop' ? 'sm' : 'secondary'}
                            icon="monitor"
                            onClick={() => switchDevice('desktop')}
                        >
                            {t('admin.email.desktop', 'Desktop')}
                        </Button>
                        <Button
                            type="button"
                            variant={device === 'mobile' ? 'sm' : 'secondary'}
                            icon="smartphone"
                            onClick={() => switchDevice('mobile')}
                        >
                            {t('admin.email.mobile', 'Mobile')}
                        </Button>
                    </div>
                    <p className="text-xs text-neutral-500 m-0">
                        {t('admin.email.drag_blocks_onto_the_canvas', 'Drag blocks onto the canvas. Styles stay inlined for inbox clients.')}
                    </p>
                </div>
            )}
            <div className="email-canvas-stage">
                {status === 'loading' ? (
                    <div className="email-canvas-status">
                        <Loader label={t('admin.email.loading_editor', 'Loading editor')} />
                    </div>
                ) : null}
                {status === 'error' ? (
                    <div className="email-canvas-status p-6">
                        <Alert type="error">
                            {t('admin.email.could_not_start_the_visual_editor', 'Could not start the visual editor. Refresh the page and try again.')}
                        </Alert>
                    </div>
                ) : null}
                <div ref={rootRef} />
            </div>
        </div>
    );
}
