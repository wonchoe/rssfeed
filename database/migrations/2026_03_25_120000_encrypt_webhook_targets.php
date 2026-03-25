<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->text('target')->change();
        });

        DB::table('webhook_integrations')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    if (! is_string($row->webhook_url) || $row->webhook_url === '') {
                        continue;
                    }

                    DB::table('webhook_integrations')
                        ->where('id', $row->id)
                        ->update([
                            'webhook_url' => $this->encryptValue($row->webhook_url),
                        ]);
                }
            });

        DB::table('subscriptions')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    if (! is_string($row->target) || $row->target === '') {
                        continue;
                    }

                    DB::table('subscriptions')
                        ->where('id', $row->id)
                        ->update([
                            'target' => $this->encryptValue($row->target),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('webhook_integrations')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    if (! is_string($row->webhook_url) || $row->webhook_url === '') {
                        continue;
                    }

                    DB::table('webhook_integrations')
                        ->where('id', $row->id)
                        ->update([
                            'webhook_url' => $this->decryptValue($row->webhook_url),
                        ]);
                }
            });

        DB::table('subscriptions')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    if (! is_string($row->target) || $row->target === '') {
                        continue;
                    }

                    DB::table('subscriptions')
                        ->where('id', $row->id)
                        ->update([
                            'target' => $this->decryptValue($row->target),
                        ]);
                }
            });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('target', 512)->change();
        });
    }

    private function encryptValue(string $value): string
    {
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (DecryptException) {
            return Crypt::encryptString($value);
        }
    }

    private function decryptValue(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
};