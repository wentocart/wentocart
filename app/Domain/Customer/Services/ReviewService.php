<?php

namespace App\Domain\Customer\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Models\ReviewVote;
use App\Domain\Notify\AdminInbox;
use App\Domain\Order\Models\Order;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\ShopMailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReviewService
{
    /** @var list<string> */
    public const VERIFIED_ORDER_STATUSES = ['processing', 'shipped', 'completed', 'delivered'];

    public function __construct(private readonly ShopMailer $mailer)
    {
    }

    public function customerHasVerifiedPurchase(Customer $customer, int $productId): bool
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::VERIFIED_ORDER_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))
            ->exists();
    }

    /**
     * @return array{
     *   allowed: bool,
     *   reason: ?string,
     *   verified_purchase: bool,
     *   has_review: bool,
     *   review_id: ?int,
     *   review: ?array<string, mixed>
     * }
     */
    public function eligibility(Customer $customer, int $productId): array
    {
        if (! ReviewSettings::enabled()) {
            return [
                'allowed' => false,
                'reason' => 'disabled',
                'verified_purchase' => false,
                'has_review' => false,
                'review_id' => null,
                'review' => null,
            ];
        }

        $existing = Review::query()
            ->where('product_id', $productId)
            ->where('customer_id', $customer->id)
            ->first();

        $reviewPayload = $existing ? array_merge([
            'id' => (int) $existing->id,
            'product_id' => (int) $existing->product_id,
            'rating' => (int) $existing->rating,
            'title' => $existing->title,
            'body' => $existing->body,
        ], $existing->mediaPayload()) : null;

        $verified = $this->customerHasVerifiedPurchase($customer, $productId);
        $who = ReviewSettings::whoCanReview();

        if ($who === 'verified' && ! $verified) {
            return [
                'allowed' => false,
                'reason' => 'verified_only',
                'verified_purchase' => false,
                'has_review' => (bool) $existing,
                'review_id' => $existing?->id,
                'review' => $reviewPayload,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'verified_purchase' => $verified,
            'has_review' => (bool) $existing,
            'review_id' => $existing?->id,
            'review' => $reviewPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>|UploadedFile|null  $photos
     */
    public function submit(
        ?Customer $customer,
        array $input,
        UploadedFile|array|null $photos = null,
        ?UploadedFile $video = null
    ): Review {
        if (! ReviewSettings::enabled()) {
            throw ValidationException::withMessages(['product_id' => 'Reviews are disabled.']);
        }

        $textLimit = ReviewSettings::minLength();
        $media = ReviewSettings::media();
        $photoList = $this->normalizeUploads($photos, $input['photo'] ?? null);
        $guest = $customer === null;

        if ($guest && ReviewSettings::whoCanReview() !== 'everyone') {
            throw ValidationException::withMessages(['email' => 'Sign in to write a review.']);
        }

        $rules = [
            'product_id' => ['required', 'integer', Rule::exists((new Product)->getTable(), 'id')],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => $textLimit > 0 ? ['nullable', 'string', 'max:180'] : ['nullable', 'string', 'max:0'],
            'body' => $textLimit > 0
                ? ['nullable', 'string', 'max:'.$textLimit]
                : ['nullable', 'string', 'max:0'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['string', 'max:500'],
            'remove_video' => ['nullable', 'boolean'],
        ];
        if ($guest) {
            $rules['guest_name'] = ['required', 'string', 'max:120'];
            $rules['guest_email'] = ['required', 'email', 'max:190'];
        }

        $payload = $input;
        if ($photoList !== []) {
            $payload['photos'] = $photoList;
            $rules['photos'] = ['array', 'max:'.Review::MAX_IMAGES];
            $rules['photos.*'] = $this->imageRules();
        }
        if ($video) {
            $payload['video'] = $video;
            $rules['video'] = $this->videoRules($media);
        }

        $data = Validator::make($payload, $rules, [
            'body.max' => $textLimit > 0
                ? 'The review cannot be longer than '.$textLimit.' characters.'
                : 'Written reviews are turned off. Leave a star rating only.',
            'title.max' => $textLimit > 0
                ? 'The title cannot be longer than 180 characters.'
                : 'Written reviews are turned off. Leave a star rating only.',
        ])->validate();

        if ($media === 'none' && ($photoList !== [] || $video)) {
            throw ValidationException::withMessages(['photos' => 'Review media uploads are disabled.']);
        }
        if ($media === 'images' && $video) {
            throw ValidationException::withMessages(['video' => 'Video uploads are not enabled.']);
        }

        $productId = (int) $data['product_id'];
        Product::query()->whereKey($productId)->firstOrFail();

        $guestEmail = $guest ? mb_strtolower(trim((string) $data['guest_email'])) : null;
        if ($guest && Customer::query()->whereRaw('LOWER(email) = ?', [$guestEmail])->exists()) {
            throw ValidationException::withMessages([
                'guest_email' => 'An account already uses this email. Sign in to write the review.',
            ]);
        }

        $eligibility = $customer
            ? $this->eligibility($customer, $productId)
            : [
                'allowed' => true,
                'verified_purchase' => false,
            ];
        if (! $eligibility['allowed']) {
            $message = $eligibility['reason'] === 'verified_only'
                ? 'Only verified buyers can review this product.'
                : 'Reviews are disabled.';
            throw ValidationException::withMessages(['product_id' => $message]);
        }

        $auto = ReviewSettings::moderation() === 'automatic';
        $existing = $customer
            ? Review::query()->where('product_id', $productId)->where('customer_id', $customer->id)->first()
            : Review::query()->where('product_id', $productId)->whereNull('customer_id')->where('guest_email', $guestEmail)->first();

        [$images, $videoPath] = $this->mergeMediaState(
            $existing,
            $photoList,
            $video,
            $data['remove_images'] ?? [],
            filter_var($data['remove_video'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $media
        );

        $wasNew = $existing === null;
        $attrs = [
            'rating' => (int) $data['rating'],
            'status' => $auto ? Review::STATUS_APPROVED : Review::STATUS_PENDING,
            'is_approved' => $auto,
            'rejected_reason' => null,
            'is_verified_purchase' => $eligibility['verified_purchase'],
            'images' => $images,
            'video_path' => $videoPath,
            'photo_path' => $images[0] ?? null,
        ];
        // Only overwrite title/body when the client actually sent them. Partial
        // multipart/query updates must not wipe existing review text.
        if ($textLimit === 0) {
            $attrs['title'] = null;
            $attrs['body'] = null;
        } else {
            if (array_key_exists('title', $input)) {
                $attrs['title'] = $data['title'] ?? null;
            } elseif ($wasNew) {
                $attrs['title'] = null;
            }
            if (array_key_exists('body', $input)) {
                $attrs['body'] = $data['body'] ?? null;
            } elseif ($wasNew) {
                $attrs['body'] = null;
            }
        }
        if ($guest) {
            $attrs['customer_id'] = null;
            $attrs['guest_name'] = trim((string) $data['guest_name']);
            $attrs['guest_email'] = $guestEmail;
        }

        if ($existing) {
            $existing->fill($attrs)->save();
            $review = $existing;
        } else {
            $review = Review::query()->create(array_merge($attrs, [
                'product_id' => $productId,
                'customer_id' => $customer?->id,
            ]));
        }

        if ($wasNew || $review->wasChanged()) {
            $this->notifySubmitted($review, $customer);
        }

        return $review->fresh(['product', 'customer']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $photos
     */
    public function adminUpdateMedia(Review $review, array $input, array $photos = [], ?UploadedFile $video = null): Review
    {
        // Back office can always manage images and video for moderation.
        $media = 'video';

        $payload = $input;
        $rules = [
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['string', 'max:255'],
            'remove_video' => ['nullable', 'boolean'],
        ];
        if ($photos !== []) {
            $payload['photos'] = $photos;
            $rules['photos'] = ['array', 'max:'.Review::MAX_IMAGES];
            $rules['photos.*'] = $this->imageRules();
        }
        if ($video) {
            $payload['video'] = $video;
            $rules['video'] = $this->videoRules($media);
        }
        $data = Validator::make($payload, $rules)->validate();

        [$images, $videoPath] = $this->mergeMediaState(
            $review,
            $photos,
            $video,
            $data['remove_images'] ?? [],
            filter_var($data['remove_video'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $media
        );

        $review->forceFill([
            'images' => $images,
            'video_path' => $videoPath,
            'photo_path' => $images[0] ?? null,
        ])->save();

        return $review->fresh(['product', 'customer']);
    }

    /**
     * @param  list<UploadedFile>|UploadedFile|null  $photos
     * @param  mixed  $legacyPhoto
     * @return list<UploadedFile>
     */
    protected function normalizeUploads(UploadedFile|array|null $photos, mixed $legacyPhoto = null): array
    {
        $out = [];
        if ($photos instanceof UploadedFile) {
            $out[] = $photos;
        } elseif (is_array($photos)) {
            foreach ($photos as $file) {
                if ($file instanceof UploadedFile) {
                    $out[] = $file;
                }
            }
        }
        if ($legacyPhoto instanceof UploadedFile) {
            $out[] = $legacyPhoto;
        }

        return array_values($out);
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @param  list<string>|mixed  $removeImages
     * @return array{0: list<string>, 1: ?string}
     */
    protected function mergeMediaState(
        ?Review $existing,
        array $photos,
        ?UploadedFile $video,
        mixed $removeImages,
        bool $removeVideo,
        string $mediaMode
    ): array {
        $currentImages = $existing ? $existing->imagePaths() : [];
        $currentVideo = $existing?->videoPath();

        $remove = [];
        foreach (is_array($removeImages) ? $removeImages : [] as $raw) {
            $normalized = $this->normalizeStoredPath((string) $raw);
            if ($normalized !== null) {
                $remove[] = $normalized;
            }
        }
        $remove = array_values(array_unique($remove));

        $kept = [];
        foreach ($currentImages as $path) {
            if (in_array($path, $remove, true)) {
                $existing?->deleteStoredFile($path);
                continue;
            }
            $kept[] = $path;
        }

        if ($mediaMode !== 'none') {
            foreach ($photos as $file) {
                if (count($kept) >= Review::MAX_IMAGES) {
                    throw ValidationException::withMessages([
                        'photos' => 'You can upload at most '.Review::MAX_IMAGES.' images.',
                    ]);
                }
                $kept[] = RasterImageUpload::storePublic($file, 'reviews', Review::IMAGE_MAX_KB);
            }
        }

        $videoPath = $currentVideo;
        if ($removeVideo && $videoPath) {
            $existing?->deleteStoredFile($videoPath);
            $videoPath = null;
        }
        if ($video && in_array($mediaMode, ['video'], true)) {
            if ($videoPath) {
                $existing?->deleteStoredFile($videoPath);
            }
            $videoPath = $this->storeVideo($video);
        }

        return [array_values($kept), $videoPath];
    }

    protected function normalizeStoredPath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $marker = strpos($raw, 'reviews/');
        if ($marker !== false) {
            return substr($raw, $marker);
        }
        if (str_starts_with($raw, '/storage/')) {
            $raw = substr($raw, strlen('/storage/'));
        }
        $raw = ltrim($raw, '/');
        if (! str_starts_with($raw, 'reviews/')) {
            return null;
        }

        return $raw;
    }

    /** @return list<string> */
    protected function imageRules(): array
    {
        return RasterImageUpload::rules(Review::IMAGE_MAX_KB);
    }

    /** @return list<string> */
    protected function videoRules(string $media): array
    {
        if ($media !== 'video') {
            return ['prohibited'];
        }

        return ['nullable', 'file', 'max:'.Review::effectiveVideoMaxKb(), 'mimetypes:video/mp4,video/webm,application/octet-stream'];
    }

    protected function storeVideo(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'video' => $this->uploadErrorMessage($file->getError(), 'video'),
            ]);
        }

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)));
        if (! in_array($ext, ['mp4', 'webm'], true)) {
            throw ValidationException::withMessages(['video' => 'Only MP4 and WebM videos are allowed.']);
        }
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: ''));
        $allowedMimes = ['video/mp4', 'video/webm', 'application/octet-stream', 'application/mp4', ''];
        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['video' => 'Video type is not allowed.']);
        }
        $maxKb = Review::effectiveVideoMaxKb();
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'video' => 'Video file is too large. Maximum size is '.round($maxKb / 1024, 1).' MB.',
            ]);
        }

        $path = $file->storeAs(
            'reviews',
            Str::random(40).'.'.$ext,
            'public'
        );
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['video' => 'Could not store the video.']);
        }

        return $path;
    }

    protected function uploadErrorMessage(int $error, string $field): string
    {
        $maxKb = Review::effectiveVideoMaxKb();
        $maxLabel = $maxKb >= 1024 ? round($maxKb / 1024, 1).' MB' : $maxKb.' KB';

        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The '.$field.' is too large. Maximum size is '.$maxLabel.'.',
            UPLOAD_ERR_PARTIAL => 'The '.$field.' upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a '.$field.' file.',
            default => 'The '.$field.' could not be uploaded. Please try a smaller MP4/WebM file.',
        };
    }

    protected function notifySubmitted(Review $review, ?Customer $customer): void
    {
        $locale = app()->getLocale();
        $productName = $review->product?->translation($locale)?->name
            ?: ($review->product?->sku ?: 'Product');
        $name = $customer?->displayName() ?: ($review->guest_name ?: 'Guest');
        $email = $customer?->email ?: (string) $review->guest_email;

        if ($email !== '') {
            $this->mailer->sendEvent(MailEvents::PRODUCT_REVIEW_SUBMITTED, [
                'customer_name' => $name,
                'customer_email' => $email,
                'product_name' => $productName,
                'rating' => (string) $review->rating,
                'review_title' => (string) ($review->title ?: ''),
                'review_status' => (string) $review->status,
            ], $email);
        }

        if ($customer) {
            AdminInbox::reviewSubmitted($review, $customer, $productName);
        }
    }

    /**
     * @return array{helpful_count: int, not_helpful_count: int, my_vote: bool}
     */
    public function vote(Customer $customer, Review $review, bool $helpful): array
    {
        if (! ReviewSettings::enabled()) {
            throw ValidationException::withMessages(['review' => 'Reviews are disabled.']);
        }
        if ($review->status !== Review::STATUS_APPROVED && ! $review->is_approved) {
            throw ValidationException::withMessages(['review' => 'This review cannot be voted on yet.']);
        }
        if ((int) $review->customer_id === (int) $customer->id) {
            throw ValidationException::withMessages(['review' => 'You cannot vote on your own review.']);
        }

        return DB::transaction(function () use ($customer, $review, $helpful) {
            /** @var Review $locked */
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $existing = ReviewVote::query()
                ->where('review_id', $locked->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($existing && (bool) $existing->helpful === $helpful) {
                return [
                    'helpful_count' => max(0, (int) $locked->helpful_count),
                    'not_helpful_count' => max(0, (int) $locked->not_helpful_count),
                    'my_vote' => $helpful,
                    'can_vote' => true,
                ];
            }

            if (! $existing) {
                ReviewVote::query()->create([
                    'review_id' => $locked->id,
                    'customer_id' => $customer->id,
                    'helpful' => $helpful,
                ]);
                if ($helpful) {
                    $locked->helpful_count = (int) $locked->helpful_count + 1;
                } else {
                    $locked->not_helpful_count = (int) $locked->not_helpful_count + 1;
                }
            } else {
                $existing->helpful = $helpful;
                $existing->save();
                if ($helpful) {
                    $locked->helpful_count = (int) $locked->helpful_count + 1;
                    $locked->not_helpful_count = max(0, (int) $locked->not_helpful_count - 1);
                } else {
                    $locked->not_helpful_count = (int) $locked->not_helpful_count + 1;
                    $locked->helpful_count = max(0, (int) $locked->helpful_count - 1);
                }
            }

            $locked->save();

            return [
                'helpful_count' => max(0, (int) $locked->helpful_count),
                'not_helpful_count' => max(0, (int) $locked->not_helpful_count),
                'my_vote' => $helpful,
                'can_vote' => true,
            ];
        });
    }

    public function notifyReply(Review $review): void
    {
        $customer = $review->customer;
        if (! $customer?->email) {
            return;
        }
        $locale = app()->getLocale();
        $productName = $review->product?->translation($locale)?->name
            ?: ($review->product?->sku ?: 'Product');

        $this->mailer->sendEvent(MailEvents::PRODUCT_REVIEW_REPLIED, [
            'customer_name' => $customer->displayName() ?: $customer->email,
            'product_name' => $productName,
            'admin_reply' => (string) ($review->admin_reply ?: ''),
            'rating' => (string) $review->rating,
        ], $customer->email);
    }
}
