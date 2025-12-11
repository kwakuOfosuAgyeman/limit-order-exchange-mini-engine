<?php

namespace App\Http\Middleware;

use App\Enums\SecurityAction;
use App\Jobs\ProcessSecurityAlert;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DetectionResult;
use App\Services\Security\MarketManipulationDetector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttackDetectionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if detection is disabled
        if (! config('attack_detection.enabled')) {
            return $next($request);
        }

        // Only analyze protected endpoints
        if (! $this->isProtectedEndpoint($request)) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        // Create detector fresh each request to pick up config changes
        $detector = new MarketManipulationDetector();

        // Run detection analysis
        $result = $detector->analyze($request, $user);

        // Handle detection result
        if ($result->detected) {
            return $this->handleDetection($request, $result, $user, $next);
        }

        return $next($request);
    }

    /**
     * Check if the endpoint should be monitored.
     */
    private function isProtectedEndpoint(Request $request): bool
    {
        $protectedEndpoints = config('attack_detection.protected_endpoints');
        $method = $request->method();
        $path = '/'.$request->path();

        if (! isset($protectedEndpoints[$method])) {
            return false;
        }

        foreach ($protectedEndpoints[$method] as $pattern) {
            $regex = str_replace(['*', '/'], ['[^/]+', '\/'], $pattern);
            if (preg_match("/^{$regex}$/", $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a positive detection.
     */
    private function handleDetection(
        Request $request,
        DetectionResult $result,
        ?User $user,
        Closure $next
    ): Response {
        // Log all security events to database
        $securityEvents = $this->logSecurityEvents($request, $result, $user);

        // Update user risk score
        if ($user) {
            $this->updateUserRiskScore($user, $result);
        }

        // Dispatch alerts for medium+ severity
        if ($result->shouldAlert() && config('attack_detection.alerts.enabled')) {
            foreach ($securityEvents as $event) {
                ProcessSecurityAlert::dispatch($event)->onQueue('security');
            }
        }

        // Block critical threats
        if ($result->shouldBlock()) {
            Log::warning('Attack blocked', [
                'user_id' => $user?->id,
                'ip' => $request->ip(),
                'threats' => array_map(fn ($t) => $t['type']->value, $result->threats),
            ]);

            return response()->json([
                'message' => 'Request blocked due to suspicious activity',
                'error' => 'security_block',
                'reference' => $securityEvents[0]->uuid ?? Str::uuid(),
            ], 429);
        }

        // Throttle suspicious requests
        if ($result->shouldThrottle() && config('attack_detection.throttling.enabled')) {
            $delay = $result->getThrottleDelay();

            if ($delay > 0) {
                Log::info('Request throttled', [
                    'user_id' => $user?->id,
                    'delay_ms' => $delay,
                    'threats' => array_map(fn ($t) => $t['type']->value, $result->threats),
                ]);

                // Sleep to slow down the attacker
                usleep($delay * 1000);
            }
        }

        // Allow request to proceed (possibly delayed)
        return $next($request);
    }

    /**
     * Log security events to database.
     */
    private function logSecurityEvents(
        Request $request,
        DetectionResult $result,
        ?User $user
    ): array {
        $events = [];

        foreach ($result->threats as $threat) {
            $action = match (true) {
                $result->shouldBlock() => SecurityAction::BLOCKED,
                $result->shouldThrottle() => SecurityAction::THROTTLED,
                default => SecurityAction::LOGGED,
            };

            $event = SecurityEvent::create([
                'event_type' => $threat['type'],
                'severity' => $threat['severity'],
                'user_id' => $user?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'symbol' => $result->context?->symbol,
                'endpoint' => $request->path(),
                'http_method' => $request->method(),
                'detection_metrics' => $threat['metrics'] ?? [],
                'related_orders' => $threat['related_orders'] ?? null,
                'related_users' => $threat['related_users'] ?? null,
                'action_taken' => $action,
                'throttle_delay_ms' => $result->getThrottleDelay(),
                'risk_score' => $threat['type']->riskWeight(),
            ]);

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Update user's cumulative risk score.
     */
    private function updateUserRiskScore(User $user, DetectionResult $result): void
    {
        $config = config('attack_detection.risk_scoring');

        // Add risk score from this detection
        $newScore = min(
            (float) $user->risk_score + $result->riskScore,
            $config['max_score']
        );

        $updateData = [
            'risk_score' => $newScore,
            'risk_score_updated_at' => now(),
            'security_event_count' => $user->security_event_count + count($result->threats),
            'last_security_event_at' => now(),
        ];

        // Auto-flag account if threshold reached
        if ($newScore >= $config['auto_flag_threshold'] && ! $user->security_review_required) {
            $updateData['security_review_required'] = true;
        }

        // Auto-suspend account if critical threshold reached
        if ($newScore >= $config['auto_suspend_threshold'] && ! $user->isSuspended()) {
            $updateData['suspended_at'] = now();
            $updateData['suspension_reason'] = 'Automated suspension due to high security risk score';
        }

        $user->update($updateData);
    }
}
