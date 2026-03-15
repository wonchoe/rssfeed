<?php

namespace App\Domain\Delivery\Services;

use App\Models\TelegramChat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TelegramChatAvatarService
{
    public function __construct(
        private readonly TelegramBotApiClient $telegramBotApiClient,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $photoSizes
     */
    public function cacheFromPhotoSizes(TelegramChat $telegramChat, array $photoSizes): ?string
    {
        if ($photoSizes === []) {
            return null;
        }

        $selectedPhoto = collect($photoSizes)
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['file_id'] ?? null))
            ->sortByDesc(fn (array $item): int => (int) ($item['file_size'] ?? 0))
            ->first();

        if (! is_array($selectedPhoto)) {
            return null;
        }

        $fileId = trim((string) ($selectedPhoto['file_id'] ?? ''));

        if ($fileId === '') {
            return null;
        }

        $fileResponse = $this->telegramBotApiClient->getFile($fileId);
        $result = is_array($fileResponse['result'] ?? null) ? $fileResponse['result'] : null;
        $filePath = trim((string) ($result['file_path'] ?? ''));

        if ($filePath === '') {
            return null;
        }

        $contents = $this->telegramBotApiClient->downloadFile($filePath);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
        $storagePath = 'telegram-chat-avatars/'.$telegramChat->id.'/avatar-'.Str::random(12).'.'.$extension;

        Storage::disk('public')->put($storagePath, $contents);

        $previousPath = trim((string) ($telegramChat->avatar_path ?? ''));

        if ($previousPath !== '' && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $telegramChat->update([
            'avatar_path' => $storagePath,
        ]);

        return $storagePath;
    }
}
