<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class QueueMonitorController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny-queue-monitor');

        return view('admin-v2.queue-monitor.index');
    }

    public function jobs(Request $request): JsonResponse
    {
        $query = DB::table('jobs')
            ->select([
                'id',
                'queue',
                'payload',
                'attempts',
                'reserved_at',
                'available_at',
                'created_at',
            ])
            ->orderBy('created_at', 'desc');

        if ($request->filled('queue')) {
            $query->where('queue', $request->input('queue'));
        }

        $jobs = $query->paginate(50);

        $jobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);

            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'job_name' => $payload['displayName'] ?? 'Unknown',
                'attempts' => $job->attempts,
                'status' => $this->getJobStatus($job),
                'created_at' => $job->created_at ? date('Y-m-d H:i:s', $job->created_at) : null,
                'reserved_at' => $job->reserved_at ? date('Y-m-d H:i:s', $job->reserved_at) : null,
                'available_at' => $job->available_at ? date('Y-m-d H:i:s', $job->available_at) : null,
            ];
        });

        return response()->json($jobs);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $query = DB::table('failed_jobs')
            ->select([
                'id',
                'uuid',
                'connection',
                'queue',
                'payload',
                'exception',
                'failed_at',
            ])
            ->orderBy('failed_at', 'desc');

        if ($request->filled('queue')) {
            $query->where('queue', $request->input('queue'));
        }

        $failedJobs = $query->paginate(50);

        $failedJobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);

            return [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'job_name' => $payload['displayName'] ?? 'Unknown',
                'exception' => $this->formatException($job->exception),
                'failed_at' => $job->failed_at,
            ];
        });

        return response()->json($failedJobs);
    }

    public function queues(): JsonResponse
    {
        $activeQueues = DB::table('jobs')
            ->select('queue')
            ->distinct()
            ->pluck('queue');

        $failedQueues = DB::table('failed_jobs')
            ->select('queue')
            ->distinct()
            ->pluck('queue');

        $queues = $activeQueues->merge($failedQueues)->unique()->values();

        return response()->json($queues);
    }

    public function retryFailedJob(string $uuid): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (! $job) {
            return response()->json(['message' => 'Failed job not found'], 404);
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job has been pushed back onto the queue']);
    }

    public function deleteFailedJob(string $uuid): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (! $job) {
            return response()->json(['message' => 'Failed job not found'], 404);
        }

        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(['message' => 'Failed job has been deleted']);
    }

    public function retryAllFailedJobs(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'No failed jobs to retry']);
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json(['message' => "{$count} job(s) have been pushed back onto the queue"]);
    }

    public function flushFailedJobs(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'No failed jobs to delete']);
        }

        Artisan::call('queue:flush');

        return response()->json(['message' => "{$count} failed job(s) have been deleted"]);
    }

    protected function getJobStatus(object $job): string
    {
        if ($job->reserved_at !== null) {
            return 'processing';
        }

        if ($job->available_at > time()) {
            return 'delayed';
        }

        return 'pending';
    }

    protected function formatException(string $exception): array
    {
        $lines = explode("\n", $exception);
        $message = $lines[0];

        // Extract stack trace (first 5 lines)
        $stackTrace = array_slice($lines, 1, 5);

        return [
            'message' => $message,
            'stack_trace' => implode("\n", $stackTrace),
        ];
    }
}
