import { ui, cx } from './classes';
import { t } from './i18n';

const KEYS = ['overall', 'seo', 'content', 'indexing', 'store'];

function diskClass(score) {
    if (score >= 90) {
        return ui.scoreDiskGood;
    }
    if (score >= 55) {
        return ui.scoreDiskOk;
    }

    return ui.scoreDiskBad;
}

/**
 * PageSpeed-style score disks. Used on SEO Studio and Store health.
 * scores: { overall, seo, content, indexing, store }
 */
export default function ScoreCards({ scores = {}, labels }) {
    const names = labels || {
        overall: t('admin.seo.score_overall', 'Overall'),
        seo: t('admin.seo.score_seo', 'SEO'),
        content: t('admin.seo.score_content', 'Content'),
        indexing: t('admin.seo.score_indexing', 'Indexing'),
        store: t('admin.seo.score_store', 'Store'),
    };

    return (
        <div className={ui.scoreGrid}>
            {KEYS.map((key) => {
                const score = Number(scores[key] ?? 0);

                return (
                    <div key={key} className={ui.scoreCard}>
                        <div className={cx(ui.scoreDisk, diskClass(score))}>{score}</div>
                        <div className={ui.scoreLabel}>{names[key]}</div>
                    </div>
                );
            })}
        </div>
    );
}
