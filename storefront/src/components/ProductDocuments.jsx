import { Icon } from './Icons.jsx';
import { webPath } from '../webPath.js';
import { t } from '../i18n.js';

function DocumentThumb({ file }) {
    const label = String(file.file_type_label || '').trim() || 'FILE';
    const preview = file.is_image && file.preview_url ? webPath(file.preview_url) : null;

    if (preview) {
        return (
            <div className="classic-pdp-document-thumb classic-pdp-document-thumb-image">
                <img
                    src={preview}
                    alt=""
                    loading="lazy"
                    decoding="async"
                />
            </div>
        );
    }

    return (
        <div className="classic-pdp-document-thumb classic-pdp-document-thumb-type" aria-hidden="true">
            <span>{label}</span>
        </div>
    );
}

export default function ProductDocuments({ attachments = [] }) {
    const rows = Array.isArray(attachments) ? attachments.filter((row) => row?.download_url || row?.id) : [];
    if (!rows.length) {
        return null;
    }

    const labels = {
        file: t('storefront.product.document_col_file', 'File'),
        title: t('storefront.product.document_col_title', 'Title'),
        description: t('storefront.product.document_col_description', 'Description'),
        filename: t('storefront.product.document_col_filename', 'File name'),
        size: t('storefront.product.document_col_size', 'Size'),
        download: t('storefront.product.download', 'Download'),
    };

    return (
        <section className="classic-pdp-documents" aria-labelledby="classic-pdp-documents-title">
            <h2 id="classic-pdp-documents-title" className="classic-pdp-block-title">
                {t('storefront.product.documents', 'Product Documents')}
            </h2>
            <div className="classic-pdp-documents-table" role="table" aria-label={t('storefront.product.documents', 'Product Documents')}>
                <div className="classic-pdp-documents-head" role="row">
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-file" role="columnheader">{labels.file}</div>
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-title" role="columnheader">{labels.title}</div>
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-description" role="columnheader">{labels.description}</div>
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-filename" role="columnheader">{labels.filename}</div>
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-size" role="columnheader">{labels.size}</div>
                    <div className="classic-pdp-documents-hcell classic-pdp-documents-hcell-action" role="columnheader">
                        <span className="sr-only">{labels.download}</span>
                    </div>
                </div>
                <ul className="classic-pdp-documents-list">
                    {rows.map((file) => {
                        const title = String(file.name || '').trim()
                            || String(file.original_filename || '').trim()
                            || t('storefront.product.document', 'Document');
                        const description = String(file.description || '').trim();
                        const typeLabel = String(file.file_type_label || '').trim();
                        const originalName = String(file.original_filename || '').trim();
                        const sizeLabel = String(file.file_size_label || '').trim();
                        const downloadHref = file.download_url ? webPath(file.download_url) : null;

                        return (
                            <li key={file.id} className="classic-pdp-document" role="row">
                                <div className="classic-pdp-document-file" role="cell">
                                    <DocumentThumb file={file} />
                                </div>

                                <div className="classic-pdp-document-identity" role="cell">
                                    <strong className="classic-pdp-document-name">{title}</strong>
                                    {typeLabel ? (
                                        <span className="classic-pdp-document-type">{typeLabel}</span>
                                    ) : null}
                                </div>

                                <div className="classic-pdp-document-description-cell" role="cell">
                                    {description || '—'}
                                </div>

                                <div className="classic-pdp-document-filename" role="cell" title={originalName || undefined}>
                                    {originalName || '—'}
                                </div>

                                <div className="classic-pdp-document-size" role="cell">
                                    {sizeLabel || '—'}
                                </div>

                                <div className="classic-pdp-document-action" role="cell">
                                    {downloadHref ? (
                                        <a
                                            className="shop-btn shop-btn-ghost shop-btn--sm classic-pdp-document-download-btn"
                                            href={downloadHref}
                                            download
                                        >
                                            <Icon name="download" className="h-4 w-4" />
                                            {labels.download}
                                        </a>
                                    ) : (
                                        <span className="classic-pdp-document-unavailable">—</span>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}
