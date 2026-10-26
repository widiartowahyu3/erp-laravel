<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

final class AuditLogger
{
    /**
     * Record an audit event into audit_logs table via default connection (erp_app).
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(
        string $event,
        string $auditableType,
        int $auditableId,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): bool {
        try {
            $effectiveUserId = $userId ?? Auth::id();
            if ($effectiveUserId !== null) {
                $effectiveUserId = (int) $effectiveUserId;
            }

            $sanitizedOld = $old !== null ? self::sanitizePayload($old) : null;
            $sanitizedNew = $new !== null ? self::sanitizePayload($new) : null;

            $ip = Request::ip();
            $ipAddress = $ip !== null ? substr($ip, 0, 45) : null;

            $ua = Request::userAgent();
            $userAgent = $ua !== null ? substr($ua, 0, 255) : null;

            DB::table('audit_logs')->insert([
                'user_id' => $effectiveUserId,
                'event' => substr($event, 0, 50),
                'auditable_type' => substr($auditableType, 0, 100),
                'auditable_id' => $auditableId,
                'old_values' => $sanitizedOld !== null ? json_encode($sanitizedOld, JSON_THROW_ON_ERROR) : null,
                'new_values' => $sanitizedNew !== null ? json_encode($sanitizedNew, JSON_THROW_ON_ERROR) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to record audit log: '.$e->getMessage(), [
                'event' => $event,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Recursively remove sensitive keys matching /password|token|secret|key/i.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $stringKey = (string) $key;

            if (preg_match('/password|token|secret|key/i', $stringKey) === 1) {
                continue; // Automatically strip sensitive fields
            }

            if (strcasecmp($stringKey, 'email') === 0 && is_string($value)) {
                $sanitized['email_hash'] = hash('sha256', strtolower(trim($value)));

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
