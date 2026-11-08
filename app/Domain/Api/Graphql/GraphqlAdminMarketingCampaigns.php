<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Database\DbGrammar;
use App\Domain\Marketing\MarketingCampaignSchema;
use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\ShopMailer;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Database\Eloquent\Builder;

/**
 */
final class GraphqlAdminMarketingCampaigns
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('marketing.newsletter') && ! $admin->hasPermission('marketing.discounts'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = NewsletterCampaign::query();

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where('subject', DbGrammar::likeOperator(), '%'.$name.'%');
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if ($status === '1' || $status === 'active') {
                $q->whereIn('status', ['sent', 'active', '1']);
            } elseif ($status === '0' || $status === 'inactive') {
                $q->whereNotIn('status', ['sent', 'active', '1']);
            } else {
                $q->where('status', $status);
            }
        }
        $eventId = (int) ($filters['marketing_event_id'] ?? $filters['marketingEventId'] ?? 0);
        if ($eventId > 0) {
            $q->where('marketing_event_id', $eventId);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'subject', 'status', 'created_at', 'name'], true)) {
            $sort = 'id';
        }
        if ($sort === 'name') {
            $sort = 'subject';
        }
        $q->orderBy($sort, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (NewsletterCampaign $row) => self::map($row, false))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = NewsletterCampaign::query()->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $subject = trim((string) ($input['subject'] ?? $input['name'] ?? ''));
        $body = (string) ($input['html_body'] ?? $input['content'] ?? '');
        $templateId = (int) ($input['marketing_template_id'] ?? $input['marketingTemplateId'] ?? 0);
        if ($templateId > 0) {
            $template = EmailTemplate::query()->find($templateId);
            if (! $template) {
                throw new UserError('Email template not found.');
            }
            if ($body === '') {
                $body = (string) $template->body;
            }
            if ($subject === '') {
                $subject = (string) $template->subject;
            }
        }
        if ($subject === '') {
            throw new UserError('name/subject is required.');
        }
        if ($body === '') {
            throw new UserError('content/html_body is required (or provide marketing_template_id).');
        }

        $rawStatus = $input['status'] ?? 'draft';
        if ($rawStatus === 1 || $rawStatus === '1' || $rawStatus === true) {
            $status = 'active';
        } elseif ($rawStatus === 0 || $rawStatus === '0' || $rawStatus === false) {
            $status = 'inactive';
        } else {
            $status = strtolower(trim((string) $rawStatus)) ?: 'draft';
        }

        $eventId = null;
        $eventRaw = $input['marketing_event_id'] ?? $input['marketingEventId'] ?? null;
        if ($eventRaw !== null && $eventRaw !== '') {
            $event = MarketingEvent::query()->find((int) $eventRaw);
            if (! $event) {
                throw new UserError('Marketing event not found.');
            }
            $eventId = (int) $event->id;
        }

        MarketingCampaignSchema::ensure();
        $groupCode = self::resolveCustomerGroupCode($input);

        $row = NewsletterCampaign::query()->create([
            'subject' => $subject,
            'html_body' => $body,
            'status' => $status,
            'sent_count' => 0,
            'marketing_event_id' => $eventId,
            'customer_group_code' => $groupCode,
        ]);

        $mapped = self::map($row, true);
        $mapped['marketing_template_id'] = $templateId > 0 ? $templateId : null;

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = NewsletterCampaign::query()->find($id);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        if (array_key_exists('subject', $input) || array_key_exists('name', $input)) {
            $subject = trim((string) ($input['subject'] ?? $input['name'] ?? ''));
            if ($subject === '') {
                throw new UserError('subject/name cannot be empty.');
            }
            $row->subject = $subject;
        }
        if (array_key_exists('html_body', $input) || array_key_exists('content', $input)) {
            $row->html_body = (string) ($input['html_body'] ?? $input['content'] ?? $row->html_body);
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $raw = $input['status'];
            if ($raw === 1 || $raw === '1' || $raw === true) {
                $row->status = 'active';
            } elseif ($raw === 0 || $raw === '0' || $raw === false) {
                $row->status = 'inactive';
            } else {
                $row->status = strtolower(trim((string) $raw)) ?: $row->status;
            }
        }
        if (array_key_exists('marketing_event_id', $input) || array_key_exists('marketingEventId', $input)) {
            $eventRaw = $input['marketing_event_id'] ?? $input['marketingEventId'];
            if ($eventRaw === null || $eventRaw === '') {
                $row->marketing_event_id = null;
            } else {
                $event = MarketingEvent::query()->find((int) $eventRaw);
                if (! $event) {
                    throw new UserError('Marketing event not found.');
                }
                $row->marketing_event_id = (int) $event->id;
            }
        }
        if (array_key_exists('customer_group', $input)
            || array_key_exists('customerGroup', $input)
            || array_key_exists('customer_group_code', $input)
            || array_key_exists('customerGroupCode', $input)) {
            MarketingCampaignSchema::ensure();
            $row->customer_group_code = self::resolveCustomerGroupCode($input);
        }
        $row->save();

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = NewsletterCampaign::query()->find($id);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        $snapshot = self::map($row, true);
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one campaign id.');
        }
        $deleted = [];
        foreach ($ids as $id) {
            $row = NewsletterCampaign::query()->find($id);
            if (! $row) {
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return ['ok' => true, 'deleted' => $deleted, 'updated_count' => count($deleted)];
    }

    /**
     * Preview recipient count for the campaign audience (subscribed ± customer group).
     *
     * @return array{campaignId: int, recipientCount: int, customerGroup: ?string, message: string}
     */
    public static function previewAudience(int $id): array
    {
        self::assertManage();
        MarketingCampaignSchema::ensure();
        $row = NewsletterCampaign::query()->find($id);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        $count = self::audienceQuery($row)->count();
        $group = $row->customer_group_code ? (string) $row->customer_group_code : null;

        return [
            'campaignId' => (int) $row->id,
            'recipientCount' => $count,
            'customerGroup' => $group,
            'message' => $group
                ? 'Audience preview: '.$count.' subscribed recipient'.($count === 1 ? '' : 's').' in group '.$group.'.'
                : 'Audience preview: '.$count.' subscribed recipient'.($count === 1 ? '' : 's').' (all groups).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function send(int $id): array
    {
        self::assertManage();
        MarketingCampaignSchema::ensure();
        $row = NewsletterCampaign::query()->find($id);
        if (! $row) {
            throw new UserError('Campaign not found.');
        }
        $status = strtolower((string) ($row->status ?: ''));
        if (in_array($status, ['inactive', '0'], true)) {
            throw new UserError('Cannot send an inactive campaign.');
        }

        $mailer = app(ShopMailer::class);
        $sent = 0;
        self::audienceQuery($row)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($mailer, $row, &$sent) {
                foreach ($rows as $subscriber) {
                    if ($mailer->sendHtml((string) $subscriber->email, (string) $row->subject, (string) $row->html_body, 'newsletter')) {
                        $sent++;
                    }
                }
            });

        $row->update([
            'status' => 'sent',
            'sent_count' => $sent,
            'sent_at' => now(),
        ]);

        return [
            'campaignId' => (int) $row->id,
            'queued' => $sent,
            'sent_count' => $sent,
            'recipientCount' => $sent,
            'customerGroup' => $row->customer_group_code ? (string) $row->customer_group_code : null,
            'message' => 'Campaign sent to '.$sent.' subscriber'.($sent === 1 ? '' : 's').'.',
            'campaign' => self::map($row->fresh() ?? $row, true),
        ];
    }

    /**
     * Subscribed newsletter rows, optionally limited to customers in a group.
     *
     * @return Builder<NewsletterSubscriber>
     */
    protected static function audienceQuery(NewsletterCampaign $campaign): Builder
    {
        $q = NewsletterSubscriber::query()
            ->where(function ($inner) {
                $inner->where('is_subscribed', true)->orWhereNull('is_subscribed');
            });

        $group = trim((string) ($campaign->customer_group_code ?? ''));
        if ($group !== '') {
            $emails = Customer::query()
                ->where('is_enabled', true)
                ->where('customer_group', $group)
                ->pluck('email')
                ->map(fn ($e) => trim((string) $e))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($emails === []) {
                $q->whereRaw('0 = 1');
            } else {
                $q->whereIn('email', $emails);
            }
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected static function resolveCustomerGroupCode(array $input): ?string
    {
        $raw = $input['customer_group_code']
            ?? $input['customerGroupCode']
            ?? $input['customer_group']
            ?? $input['customerGroup']
            ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $code = trim((string) $raw);
        if ($code === '') {
            return null;
        }
        if (! CustomerGroup::query()->where('code', $code)->where('is_enabled', true)->exists()) {
            throw new UserError('Customer group not found.');
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(NewsletterCampaign $row, bool $detail = false): array
    {
        $status = (string) ($row->status ?: 'draft');
        $out = [
            'id' => (int) $row->id,

            'name' => (string) $row->subject,
            'subject' => (string) $row->subject,
            'content' => (string) $row->html_body,
            'html_body' => (string) $row->html_body,
            'status' => $status,
            'statusFlag' => in_array($status, ['sent', 'active', '1'], true) ? 1 : 0,
            'sent_count' => (int) ($row->sent_count ?? 0),
            'sent_at' => optional($row->sent_at)?->toIso8601String(),
            'marketing_template_id' => null,
            'marketing_event_id' => $row->marketing_event_id !== null ? (int) $row->marketing_event_id : null,
            'channel' => null,
            'customerGroup' => $row->customer_group_code ? (string) $row->customer_group_code : null,
            'customer_group' => $row->customer_group_code ? (string) $row->customer_group_code : null,
            'customer_group_code' => $row->customer_group_code ? (string) $row->customer_group_code : null,
            'marketingTemplate' => null,
            'marketingEvent' => null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];

        if ($detail && $row->marketing_event_id) {
            $event = MarketingEvent::query()->find($row->marketing_event_id);
            if ($event) {
                $out['marketingEvent'] = [
                    'id' => (int) $event->id,
                    'name' => (string) $event->name,
                    'date' => optional($event->date)?->format('Y-m-d') ?? (string) $event->date,
                ];
            }
        }

        return $out;
    }
}
