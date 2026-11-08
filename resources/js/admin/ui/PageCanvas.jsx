import { useEffect, useRef, useState } from 'react';
import Alert from './Alert';
import Button from './Button';
import Loader from './Loader';
import { t } from './i18n';
import './email-canvas.css';

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

function pageBlocks() {
    return [
        {
            id: 'page-section',
            label: t('admin.cms.block_section', 'Section'),
            category: t('admin.cms.block_layout', 'Layout'),
            content: '<section style="padding:48px 24px;"><h2>Section title</h2><p>Write about this part of the page.</p></section>',
        },
        {
            id: 'page-columns',
            label: t('admin.cms.block_columns', 'Columns'),
            category: t('admin.cms.block_layout', 'Layout'),
            content: '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;"><div><h3>Column</h3><p>Left</p></div><div><h3>Column</h3><p>Right</p></div></div>',
        },
        {
            id: 'page-heading',
            label: t('admin.cms.block_heading', 'Heading'),
            category: t('admin.cms.block_content', 'Content'),
            content: '<h2>Heading</h2>',
        },
        {
            id: 'page-paragraph',
            label: t('admin.cms.block_paragraph', 'Paragraph'),
            category: t('admin.cms.block_content', 'Content'),
            content: '<p>Write a short paragraph for this page.</p>',
        },
        {
            id: 'page-image',
            label: t('admin.cms.block_image', 'Image'),
            category: t('admin.cms.block_content', 'Content'),
            content: { type: 'image' },
        },
        {
            id: 'page-button',
            label: t('admin.cms.block_button', 'Button'),
            category: t('admin.cms.block_content', 'Content'),
            content: '<a href="/" style="display:inline-block;padding:12px 20px;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none;">Shop now</a>',
        },
        {
            id: 'page-video',
            label: t('admin.cms.block_video', 'Video'),
            category: t('admin.cms.block_content', 'Content'),
            content: '<div style="position:relative;padding-bottom:56.25%;height:0;"><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allowfullscreen></iframe></div>',
        },
    ];
}

/**
 * Visual CMS page canvas. Pages import this wrapper only so other admin
 * screens do not load GrapesJS.
 */
export default function PageCanvas({ html = '', onReady }) {
    const rootRef = useRef(null);
    const blocksRef = useRef(null);
    const editorRef = useRef(null);
    const [status, setStatus] = useState('loading');
    const [device, setDevice] = useState('desktop');

    useEffect(() => {
        let cancelled = false;

        (async () => {
            try {
                const grapesjsMod = await import('grapesjs');
                await import('grapesjs/dist/css/grapes.min.css');
                const grapesjs = unwrapGrapes(grapesjsMod);
                if (!grapesjs) {
                    throw new Error('GrapesJS failed to load');
                }
                if (cancelled || !rootRef.current || !blocksRef.current) {
                    return;
                }

                const editor = grapesjs.init({
                    container: rootRef.current,
                    height: '640px',
                    fromElement: false,
                    noticeOnUnload: false,
                    telemetry: false,
                    storageManager: false,
                    panels: { defaults: [] },
                    blockManager: {
                        appendTo: blocksRef.current,
                        blocks: pageBlocks(),
                    },
                    deviceManager: {
                        devices: [
                            { id: 'desktop', name: 'Desktop', width: '' },
                            { id: 'tablet', name: 'Tablet', width: '768px', widthMedia: '992px' },
                            { id: 'mobile', name: 'Mobile', width: '375px', widthMedia: '480px' },
                        ],
                    },
                });

                if (html) {
                    try {
                        editor.setComponents(html);
                    } catch {
                        // Keep an empty canvas rather than failing the editor.
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
                        editor.setDevice(next === 'mobile' ? 'mobile' : (next === 'tablet' ? 'tablet' : 'desktop'));
                    },
                    getValue() {
                        const css = editor.getCss() || '';
                        const markup = editor.getHtml() || '';

                        return {
                            html: `${css ? `<style>${css}</style>` : ''}${markup}`,
                        };
                    },
                };

                onReady?.(api);
                if (!cancelled) {
                    setStatus('ready');
                }
            } catch (error) {
                console.error('Page canvas failed to start', error);
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
        // Load once for this canvas instance. Parent remounts with a key when the page or language changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const switchDevice = (next) => {
        setDevice(next);
        editorRef.current?.setDevice(next);
    };

    return (
        <div className="email-canvas">
            <div className="email-canvas-toolbar">
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant={device === 'desktop' ? 'sm' : 'secondary'} icon="monitor" onClick={() => switchDevice('desktop')}>
                        {t('admin.email.desktop', 'Desktop')}
                    </Button>
                    <Button type="button" variant={device === 'mobile' ? 'sm' : 'secondary'} icon="smartphone" onClick={() => switchDevice('mobile')}>
                        {t('admin.email.mobile', 'Mobile')}
                    </Button>
                </div>
                <p className="text-xs text-neutral-500 m-0">
                    {t('admin.cms.drag_blocks_onto_the_page', 'Drag a block from the left onto the page. Click the text to change it.')}
                </p>
            </div>
            <div className="email-canvas-stage page-canvas-stage">
                <aside className="page-canvas-blocks" aria-label={t('admin.cms.blocks', 'Blocks')}>
                    <p className="page-canvas-blocks-label">{t('admin.cms.blocks', 'Blocks')}</p>
                    <div ref={blocksRef} />
                </aside>
                <div className="page-canvas-frame">
                    {status === 'loading' ? (
                        <div className="email-canvas-status">
                            <Loader label={t('admin.cms.loading_editor', 'Loading editor')} />
                        </div>
                    ) : null}
                    {status === 'error' ? (
                        <div className="email-canvas-status p-6">
                            <Alert type="error">
                                {t('admin.cms.could_not_start_the_visual_editor', 'Could not start the visual editor. Refresh the page and try again.')}
                            </Alert>
                        </div>
                    ) : null}
                    <div ref={rootRef} />
                </div>
            </div>
        </div>
    );
}
