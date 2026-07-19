<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GmailOauthToken extends Model
{
    protected $fillable = [
        'mailbox_email',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'access_token_expires_at',
        'scope',
        'connected_by',
        'connected_at',
        'last_fetched_at',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function connectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function storeSecrets(?string $accessToken, ?string $refreshToken, ?\DateTimeInterface $expiresAt = null): void
    {
        if ($accessToken !== null) {
            $this->access_token_encrypted = Crypt::encryptString($accessToken);
        }
        if ($refreshToken !== null) {
            $this->refresh_token_encrypted = Crypt::encryptString($refreshToken);
        }
        if ($expiresAt !== null) {
            $this->access_token_expires_at = $expiresAt;
        }
        $this->save();
    }

    public function plainAccessToken(): ?string
    {
        return $this->decryptNullable($this->access_token_encrypted);
    }

    public function plainRefreshToken(): ?string
    {
        return $this->decryptNullable($this->refresh_token_encrypted);
    }

    public function accessTokenExpired(): bool
    {
        if (! $this->access_token_expires_at) {
            return true;
        }

        return $this->access_token_expires_at->lte(now()->addMinute());
    }

    private function decryptNullable(?string $encrypted): ?string
    {
        if (! $encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
