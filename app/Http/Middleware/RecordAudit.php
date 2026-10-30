<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\AlertService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Audit jurnalı: hər dəyişiklik sorğusu (POST/PUT/DELETE) kim, nə vaxt, hansı obyektdə, nə nəticə ilə — avtomatik yazılır.
 * Dəyişdirilən / ləğv edilən obyektin əvvəlki vəziyyəti və dəyişən sahələr də saxlanılır. Parollar heç vaxt yazılmır.
 */
class RecordAudit
{
    /** Məlumatı dəyişməyən POST sorğuları (hesablama / önizləmə). */
    private const SKIP = ['orders.campaigns'];

    private const SECRET = ['password', 'password_confirmation', 'current_password', '_token', '_method', 'token', 'otp', 'recovery_code'];

    private const BIG = ['cancelled_lines', 'payload', 'remember_token', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'];

    private const MAX_PAYLOAD = 60000;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $action = $request->route()?->getName() ?? ($request->is('login') ? 'auth.login' : $request->path());
        if (in_array($action, self::SKIP, true)) {
            return $next($request);
        }
        $actor = $request->user();
        [$subjectType, $subjectId, $subject] = $this->subject($request);
        $before = $subject ? $this->attributes($subject) : null;

        $response = $next($request);
        // hər yazı əməliyyatından sonra bildirişlər yenidən hesablansın
        AlertService::touch();

        try {
            $this->record($request, $response, $action, $actor, $subjectType, $subjectId, $subject, $before);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function record(Request $request, Response $response, string $action, $actor, ?string $subjectType, ?int $subjectId, ?Model $subject, ?array $before): void
    {
        $session = $request->hasSession() ? $request->session() : null;
        // yalnız bu sorğuda flash edilənlər — əvvəlki sorğunun popupu / xətası bu qeydə düşməsin
        $fresh = fn (string $key) => in_array($key, (array) $session?->get('_flash.new', []), true) ? $session->get($key) : null;
        $popup = $fresh('popup');
        $errors = $fresh('errors');
        $errorText = $fresh('error') ?? ($errors instanceof ViewErrorBag && $errors->any() ? $errors->first() : null);
        $status = $response->getStatusCode();
        $outcome = match (true) {
            in_array($status, [401, 403, 419], true) => 'denied',
            $status >= 400 || $errorText !== null => 'error',
            default => 'ok',
        };
        $user = $request->user() ?? $actor;
        if ($action === 'auth.login' && $user === null) {
            $outcome = 'error';
        }

        $docNos = collect(is_array($popup) ? ($popup['docs'] ?? []) : [])->pluck('no')
            ->push($subject?->getAttribute('doc_no') ?? $subject?->getAttribute('order_no'))
            ->filter()->unique()->values();
        $summary = is_array($popup) ? trim(($popup['title'] ?? '').(($popup['message'] ?? null) ? ': '.$popup['message'] : '')) : null;
        $summary = $errorText ?? ($summary ?: null);

        $payload = ['input' => $this->sanitize($request->except(self::SECRET))];
        if ($before !== null) {
            $payload['before'] = $before;
            $after = $request->isMethod('DELETE') ? null : $subject?->fresh();
            if ($after !== null) {
                $changes = [];
                foreach ($this->attributes($after) as $key => $value) {
                    if ($key !== 'updated_at' && (string) json_encode($before[$key] ?? null) !== (string) json_encode($value)) {
                        $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
                    }
                }
                $payload['changes'] = $changes;
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen((string) $json) > self::MAX_PAYLOAD) {
            $payload['input'] = ['_truncated' => __('Məlumat çox böyükdür — yalnız sahə adları saxlanıldı'), 'keys' => array_keys($request->except(self::SECRET))];
        }

        AuditLog::query()->create([
            'user_id' => $user?->id, 'user_name' => $user?->name ?? ($action === 'auth.login' ? (string) $request->input('email') : null),
            'action' => mb_substr($action, 0, 64), 'method' => $request->method(),
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'doc_nos' => $docNos->isEmpty() ? null : mb_substr('|'.$docNos->implode('|').'|', 0, 255),
            'summary' => $summary !== null ? mb_substr(strip_tags((string) $summary), 0, 2000) : null,
            'payload' => $payload, 'outcome' => $outcome,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Marşrutdakı ilk model (və ya rəqəmli id) — qeydin obyekti.
     *
     * @return array{0: string|null, 1: int|null, 2: Model|null}
     */
    private function subject(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            if ($value instanceof Model) {
                return [class_basename($value), (int) $value->getKey(), $value];
            }
            if (is_numeric($value)) {
                return [$name, (int) $value, null];
            }
        }

        return [null, null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $model): array
    {
        $out = [];
        foreach ($model->getAttributes() as $key => $value) {
            if (! in_array($key, self::BIG, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function sanitize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return __('fayl').': '.$value->getClientOriginalName().' ('.round($value->getSize() / 1024).' KB)';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (! in_array($k, self::SECRET, true)) {
                    $out[$k] = $this->sanitize($v);
                }
            }

            return $out;
        }

        return is_string($value) ? mb_substr($value, 0, 1000) : $value;
    }
}
