import { useEffect, useRef, useCallback } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import Superscript from '@tiptap/extension-superscript';
import Subscript from '@tiptap/extension-subscript';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import { t } from '../i18n.js';
import './rich-editor.css';

const cx = (...parts) => parts.filter(Boolean).join(' ');

/**
 * Storefront TipTap editor — same toolbar as admin product descriptions.
 * Image upload uses the seller portal endpoint (no admin AI assist).
 */
export default function RichEditor({
    value = '',
    onChange,
    className,
    uploadUrl = '/api/v1/account/seller/editor/upload',
    placeholder = t('storefront.seller.editor_placeholder', 'Write product description, headings, lists, and insert images…'),
    compact = false,
}) {
    const fileRef = useRef(null);
    const uploading = useRef(false);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3, 4, 5, 6] },
            }),
            Underline,
            TextStyle,
            Color,
            Highlight.configure({ multicolor: true }),
            Superscript,
            Subscript,
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
            }),
            Image.configure({ allowBase64: false }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
            Placeholder.configure({ placeholder }),
        ],
        content: value || '',
        editorProps: {
            attributes: { class: 'admin-editor-doc' },
        },
        onUpdate: ({ editor: ed }) => onChange?.(ed.getHTML()),
    });

    useEffect(() => {
        if (!editor || editor.isDestroyed) {
            return;
        }
        const current = editor.getHTML();
        const next = value || '';
        if (next !== current && next !== '<p></p>') {
            editor.commands.setContent(next, false);
        }
    }, [value, editor]);

    const insertImage = useCallback(
        async (file) => {
            if (!file || !editor || uploading.current) {
                return;
            }
            uploading.current = true;
            try {
                const body = new FormData();
                body.append('image', file);
                const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
                    },
                    credentials: 'same-origin',
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok || !json.url) {
                    window.alert(json.message || t('storefront.seller.editor_image_failed', 'Image upload failed.'));
                    return;
                }
                editor.chain().focus().setImage({ src: json.url, alt: file.name }).run();
            } catch {
                window.alert(t('storefront.seller.editor_image_failed', 'Image upload failed.'));
            } finally {
                uploading.current = false;
                if (fileRef.current) {
                    fileRef.current.value = '';
                }
            }
        },
        [editor, uploadUrl],
    );

    if (!editor) {
        return (
            <div className={cx('admin-editor-shell', 'is-loading', compact && 'is-compact', className)}>
                {t('storefront.seller.editor_loading', 'Loading editor…')}
            </div>
        );
    }

    const headingValue = [1, 2, 3, 4, 5, 6].find((level) => editor.isActive('heading', { level })) || 'p';

    return (
        <div className={cx('admin-editor-shell', compact && 'is-compact', className)}>
            <div className="admin-editor-menubar" role="toolbar" aria-label={t('storefront.seller.editor_toolbar', 'Text formatting')}>
                <div className="admin-editor-group">
                    <Btn title={t('storefront.seller.editor_undo', 'Undo')} disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()}>
                        <IconUndo />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_redo', 'Redo')} disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()}>
                        <IconRedo />
                    </Btn>
                </div>

                <select
                    className="admin-editor-select"
                    aria-label={t('storefront.seller.editor_style', 'Text style')}
                    value={headingValue === 'p' ? 'p' : String(headingValue)}
                    onChange={(e) => {
                        const v = e.target.value;
                        if (v === 'p') {
                            editor.chain().focus().setParagraph().run();
                        } else {
                            editor.chain().focus().toggleHeading({ level: Number(v) }).run();
                        }
                    }}
                >
                    <option value="p">{t('storefront.seller.editor_paragraph', 'Paragraph')}</option>
                    <option value="1">{t('storefront.seller.editor_h1', 'Heading 1')}</option>
                    <option value="2">{t('storefront.seller.editor_h2', 'Heading 2')}</option>
                    <option value="3">{t('storefront.seller.editor_h3', 'Heading 3')}</option>
                    <option value="4">{t('storefront.seller.editor_h4', 'Heading 4')}</option>
                    <option value="5">{t('storefront.seller.editor_h5', 'Heading 5')}</option>
                    <option value="6">{t('storefront.seller.editor_h6', 'Heading 6')}</option>
                </select>

                <div className="admin-editor-group">
                    <Btn title={t('storefront.seller.editor_bold', 'Bold')} active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
                        <IconBold />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_italic', 'Italic')} active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
                        <IconItalic />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_underline', 'Underline')} active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}>
                        <IconUnderline />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_strike', 'Strikethrough')} active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
                        <IconStrike />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_code', 'Inline code')} active={editor.isActive('code')} onClick={() => editor.chain().focus().toggleCode().run()}>
                        <IconCode />
                    </Btn>
                    <label className="admin-editor-color" title={t('storefront.seller.editor_color', 'Text color')}>
                        <IconTextColor />
                        <input
                            type="color"
                            value={editor.getAttributes('textStyle').color || '#0f172a'}
                            onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
                            aria-label={t('storefront.seller.editor_color', 'Text color')}
                        />
                    </label>
                    <label className="admin-editor-color" title={t('storefront.seller.editor_highlight', 'Highlight')}>
                        <IconHighlight />
                        <input
                            type="color"
                            value={editor.getAttributes('highlight').color || '#fde68a'}
                            onChange={(e) => editor.chain().focus().toggleHighlight({ color: e.target.value }).run()}
                            aria-label={t('storefront.seller.editor_highlight', 'Highlight')}
                        />
                    </label>
                    <Btn title={t('storefront.seller.editor_clear', 'Clear formatting')} onClick={() => editor.chain().focus().unsetAllMarks().clearNodes().run()}>
                        <IconEraser />
                    </Btn>
                </div>

                <div className="admin-editor-group">
                    <Btn title={t('storefront.seller.editor_align_left', 'Align left')} active={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()}>
                        <IconAlignLeft />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_align_center', 'Align center')} active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()}>
                        <IconAlignCenter />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_align_right', 'Align right')} active={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()}>
                        <IconAlignRight />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_align_justify', 'Justify')} active={editor.isActive({ textAlign: 'justify' })} onClick={() => editor.chain().focus().setTextAlign('justify').run()}>
                        <IconAlignJustify />
                    </Btn>
                </div>

                <div className="admin-editor-group">
                    <Btn title={t('storefront.seller.editor_bullet', 'Bullet list')} active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>
                        <IconBulletList />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_ordered', 'Ordered list')} active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
                        <IconOrderedList />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_quote', 'Blockquote')} active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
                        <IconQuote />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_code_block', 'Code block')} active={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()}>
                        <IconCodeBlock />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_superscript', 'Superscript')} active={editor.isActive('superscript')} onClick={() => editor.chain().focus().toggleSuperscript().run()}>
                        <span className="admin-editor-xy">{t('storefront.seller.editor_x', 'x²')}</span>
                    </Btn>
                    <Btn title={t('storefront.seller.editor_subscript', 'Subscript')} active={editor.isActive('subscript')} onClick={() => editor.chain().focus().toggleSubscript().run()}>
                        <span className="admin-editor-xy">{t('storefront.seller.editor_x2', 'x₂')}</span>
                    </Btn>
                </div>

                <div className="admin-editor-group">
                    <Btn
                        title={t('storefront.seller.editor_link', 'Link')}
                        active={editor.isActive('link')}
                        onClick={() => {
                            const prev = editor.getAttributes('link').href || 'https://';
                            const url = window.prompt(t('storefront.seller.editor_link_prompt', 'Link URL'), prev);
                            if (url === null) {
                                return;
                            }
                            if (!url.trim()) {
                                editor.chain().focus().extendMarkRange('link').unsetLink().run();
                                return;
                            }
                            editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
                        }}
                    >
                        <IconLink />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_image', 'Insert image')} onClick={() => fileRef.current?.click()}>
                        <IconImage />
                    </Btn>
                    <Btn title={t('storefront.seller.editor_hr', 'Horizontal rule')} onClick={() => editor.chain().focus().setHorizontalRule().run()}>
                        <IconHr />
                    </Btn>
                    <Btn
                        title={t('storefront.seller.editor_table', 'Insert table')}
                        onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}
                    >
                        <IconTable />
                    </Btn>
                </div>
            </div>

            <input
                ref={fileRef}
                type="file"
                accept="image/*"
                className="sr-only"
                tabIndex={-1}
                onChange={(e) => insertImage(e.target.files?.[0])}
            />

            <EditorContent editor={editor} className="admin-editor" />
        </div>
    );
}

function Btn({ title, active, disabled, onClick, children }) {
    return (
        <button
            type="button"
            className={cx('admin-editor-btn', active && 'is-active')}
            title={title}
            aria-label={title}
            aria-pressed={!!active}
            disabled={disabled}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

function Svg({ children }) {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children}
        </svg>
    );
}

function IconUndo() {
    return (
        <Svg>
            <path d="M3 7v6h6" />
            <path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6.7 2.9L3 13" />
        </Svg>
    );
}
function IconRedo() {
    return (
        <Svg>
            <path d="M21 7v6h-6" />
            <path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6.7 2.9L21 13" />
        </Svg>
    );
}
function IconBold() {
    return (
        <Svg>
            <path d="M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z" />
        </Svg>
    );
}
function IconItalic() {
    return (
        <Svg>
            <line x1="19" y1="4" x2="10" y2="4" />
            <line x1="14" y1="20" x2="5" y2="20" />
            <line x1="15" y1="4" x2="9" y2="20" />
        </Svg>
    );
}
function IconUnderline() {
    return (
        <Svg>
            <path d="M6 4v6a6 6 0 0 0 12 0V4" />
            <line x1="4" y1="20" x2="20" y2="20" />
        </Svg>
    );
}
function IconStrike() {
    return (
        <Svg>
            <path d="M16 4H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6" />
            <line x1="4" y1="12" x2="20" y2="12" />
        </Svg>
    );
}
function IconCode() {
    return (
        <Svg>
            <polyline points="16 18 22 12 16 6" />
            <polyline points="8 6 2 12 8 18" />
        </Svg>
    );
}
function IconTextColor() {
    return (
        <Svg>
            <path d="M4 20h16" />
            <path d="m6 16 6-12 6 12" />
            <path d="M8 12h8" />
        </Svg>
    );
}
function IconHighlight() {
    return (
        <Svg>
            <path d="m9 11-6 6v3h9l3-3" />
            <path d="m15 9 3-3a2.8 2.8 0 0 0-4-4l-3 3" />
            <path d="m7 17 5-5" />
        </Svg>
    );
}
function IconEraser() {
    return (
        <Svg>
            <path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21" />
            <path d="M22 21H7" />
            <path d="m5 11 9 9" />
        </Svg>
    );
}
function IconAlignLeft() {
    return (
        <Svg>
            <line x1="17" y1="10" x2="3" y2="10" />
            <line x1="21" y1="6" x2="3" y2="6" />
            <line x1="21" y1="14" x2="3" y2="14" />
            <line x1="17" y1="18" x2="3" y2="18" />
        </Svg>
    );
}
function IconAlignCenter() {
    return (
        <Svg>
            <line x1="18" y1="10" x2="6" y2="10" />
            <line x1="21" y1="6" x2="3" y2="6" />
            <line x1="21" y1="14" x2="3" y2="14" />
            <line x1="18" y1="18" x2="6" y2="18" />
        </Svg>
    );
}
function IconAlignRight() {
    return (
        <Svg>
            <line x1="21" y1="10" x2="7" y2="10" />
            <line x1="21" y1="6" x2="3" y2="6" />
            <line x1="21" y1="14" x2="3" y2="14" />
            <line x1="21" y1="18" x2="7" y2="18" />
        </Svg>
    );
}
function IconAlignJustify() {
    return (
        <Svg>
            <line x1="21" y1="10" x2="3" y2="10" />
            <line x1="21" y1="6" x2="3" y2="6" />
            <line x1="21" y1="14" x2="3" y2="14" />
            <line x1="21" y1="18" x2="3" y2="18" />
        </Svg>
    );
}
function IconBulletList() {
    return (
        <Svg>
            <line x1="8" y1="6" x2="21" y2="6" />
            <line x1="8" y1="12" x2="21" y2="12" />
            <line x1="8" y1="18" x2="21" y2="18" />
            <line x1="3" y1="6" x2="3.01" y2="6" />
            <line x1="3" y1="12" x2="3.01" y2="12" />
            <line x1="3" y1="18" x2="3.01" y2="18" />
        </Svg>
    );
}
function IconOrderedList() {
    return (
        <Svg>
            <line x1="10" y1="6" x2="21" y2="6" />
            <line x1="10" y1="12" x2="21" y2="12" />
            <line x1="10" y1="18" x2="21" y2="18" />
            <path d="M4 6h1v4" />
            <path d="M4 10h2" />
            <path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />
        </Svg>
    );
}
function IconQuote() {
    return (
        <Svg>
            <path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21z" />
            <path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3z" />
        </Svg>
    );
}
function IconCodeBlock() {
    return (
        <Svg>
            <polyline points="16 18 22 12 16 6" />
            <polyline points="8 6 2 12 8 18" />
            <line x1="12" y1="2" x2="12" y2="22" />
        </Svg>
    );
}
function IconLink() {
    return (
        <Svg>
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
        </Svg>
    );
}
function IconImage() {
    return (
        <Svg>
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="8.5" cy="8.5" r="1.5" />
            <path d="m21 15-5-5L5 21" />
        </Svg>
    );
}
function IconHr() {
    return (
        <Svg>
            <line x1="4" y1="12" x2="20" y2="12" />
        </Svg>
    );
}
function IconTable() {
    return (
        <Svg>
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <path d="M3 9h18M3 15h18M9 3v18M15 3v18" />
        </Svg>
    );
}
