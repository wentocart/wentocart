<?php

namespace App\Domain\Customer\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ops\EntityObserver;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'product_id', 'customer_id', 'guest_name', 'guest_email', 'rating', 'title', 'body',
    'is_approved', 'status', 'rejected_reason', 'admin_reply',
    'admin_replied_at', 'is_verified_purchase', 'photo_path',
    'images', 'video_path', 'helpful_count', 'not_helpful_count',
])]
#[ObservedBy([EntityObserver::class])]
class Review extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_REVIEW;
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const MAX_IMAGES = 5;

    public const IMAGE_MAX_KB = 4096;

    /** Soft app limit; effective cap also follows PHP upload_max_filesize / post_max_size. */
    public const VIDEO_MAX_KB = 12288;

    /**
     * Effective video size limit in KB for this PHP process.
     */
    public static function effectiveVideoMaxKb(): int
    {
        $uploadKb = self::iniLimitKb('upload_max_filesize');
        $postKb = self::iniLimitKb('post_max_size');
        // Leave headroom in the POST body for text fields + images.
        $postBudgetKb = $postKb > 1024 ? ($postKb - 1024) : $postKb;
        $phpCap = min($uploadKb > 0 ? $uploadKb : self::VIDEO_MAX_KB, $postBudgetKb > 0 ? $postBudgetKb : self::VIDEO_MAX_KB);

        return max(256, min(self::VIDEO_MAX_KB, $phpCap));
    }

    protected static function iniLimitKb(string $key): int
    {
        $raw = trim((string) ini_get($key));
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;
        return (int) match ($unit) {
            'g' => $value * 1024 * 1024,
            'm' => $value * 1024,
            'k' => $value,
            default => ((float) $raw) / 1024,
        };
    }

    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'is_verified_purchase' => 'boolean',
            'admin_replied_at' => 'datetime',
            'images' => 'array',
            'helpful_count' => 'integer',
            'not_helpful_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Review $review) {
            $status = $review->status ?: ($review->is_approved ? self::STATUS_APPROVED : self::STATUS_PENDING);
            $review->status = $status;
            $review->is_approved = $status === self::STATUS_APPROVED;

            $images = array_values(array_filter(array_map('strval', $review->images ?? [])));
            if ($images === []) {
                $legacy = trim((string) $review->photo_path);
                if ($legacy !== '' && ! self::pathLooksLikeVideo($legacy)) {
                    $images = [$legacy];
                } elseif ($legacy !== '' && self::pathLooksLikeVideo($legacy)) {
                    if (trim((string) $review->video_path) === '') {
                        $review->video_path = $legacy;
                    }
                }
            }
            $review->images = $images === [] ? null : $images;
            $review->photo_path = $images[0] ?? null;
        });

        static::deleting(function (Review $review) {
            $review->purgeStoredMedia();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    /**
     * @return list<string>
     */
    public function imagePaths(): array
    {
        $images = array_values(array_filter(array_map('strval', $this->images ?? [])));
        if ($images !== []) {
            return $images;
        }

        $legacy = trim((string) $this->photo_path);
        if ($legacy === '' || self::pathLooksLikeVideo($legacy)) {
            return [];
        }

        return [$legacy];
    }

    /**
     * @return list<string>
     */
    public function imageUrls(): array
    {
        return array_values(array_map(
            static fn (string $path) => '/storage/'.ltrim($path, '/'),
            $this->imagePaths()
        ));
    }

    public function videoPath(): ?string
    {
        $video = trim((string) $this->video_path);
        if ($video !== '') {
            return $video;
        }

        $legacy = trim((string) $this->photo_path);
        if ($legacy !== '' && self::pathLooksLikeVideo($legacy)) {
            return $legacy;
        }

        return null;
    }

    public function videoUrl(): ?string
    {
        $path = $this->videoPath();

        return $path ? '/storage/'.ltrim($path, '/') : null;
    }

    public function authorName(): string
    {
        $name = trim((string) ($this->customer?->displayName() ?: $this->guest_name));

        return $name !== '' ? $name : 'Customer';
    }

    public function photoUrl(): ?string
    {
        return $this->imageUrls()[0] ?? null;
    }

    /**
     * @return array{
     *   photo_url: ?string,
     *   image_urls: list<string>,
     *   video_url: ?string,
     *   images: list<string>,
     *   video_path: ?string
     * }
     */
    public function mediaPayload(): array
    {
        $paths = $this->imagePaths();

        return [
            'photo_url' => isset($paths[0]) ? '/storage/'.ltrim($paths[0], '/') : null,
            'image_urls' => $this->imageUrls(),
            'video_url' => $this->videoUrl(),
            'images' => $paths,
            'video_path' => $this->videoPath(),
        ];
    }

    /**
     * @return array{helpful_count: int, not_helpful_count: int, my_vote: ?bool}
     */
    public function votePayload(?int $viewerCustomerId = null): array
    {
        $myVote = null;
        if ($viewerCustomerId) {
            $existing = $this->relationLoaded('votes')
                ? $this->votes->first(fn (ReviewVote $vote) => (int) $vote->customer_id === $viewerCustomerId)
                : $this->votes()->where('customer_id', $viewerCustomerId)->first();
            if ($existing) {
                $myVote = (bool) $existing->helpful;
            }
        }

        return [
            'helpful_count' => max(0, (int) $this->helpful_count),
            'not_helpful_count' => max(0, (int) $this->not_helpful_count),
            'my_vote' => $myVote,
            'can_vote' => $viewerCustomerId !== null && $viewerCustomerId > 0 && (int) $this->customer_id !== $viewerCustomerId,
        ];
    }

    public function purgeStoredMedia(): void
    {
        foreach ($this->imagePaths() as $path) {
            $this->deleteStoredFile($path);
        }
        if ($video = $this->videoPath()) {
            $this->deleteStoredFile($video);
        }
    }

    public function deleteStoredFile(?string $path): void
    {
        $path = trim((string) $path);
        if ($path === '') {
            return;
        }
        if (! str_starts_with($path, 'reviews/')) {
            return;
        }
        Storage::disk('public')->delete($path);
    }

    public static function pathLooksLikeVideo(string $path): bool
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'webm', 'ogg', 'mov'], true);
    }

    public function approve(): void
    {
        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'is_approved' => true,
            'rejected_reason' => null,
        ])->save();
    }

    public function reject(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'is_approved' => false,
            'rejected_reason' => $reason,
        ])->save();
    }

    public function pending(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'is_approved' => false,
            'rejected_reason' => null,
        ])->save();
    }

    public function reply(string $body): void
    {
        $this->forceFill([
            'admin_reply' => $body,
            'admin_replied_at' => now(),
        ])->save();
    }
}
